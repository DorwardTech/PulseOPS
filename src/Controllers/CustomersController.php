<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\Database;
use App\Services\AuthService;
use App\Services\AuditService;

class CustomersController
{
    public function __construct(
        private Twig $twig,
        private Database $db,
        private AuthService $auth,
        private AuditService $audit
    ) {}

    /**
     * Helper: gather common view data (auth, flash, csrf).
     */
    private function viewData(array $extra = []): array
    {
        $flash_success = $_SESSION['flash_success'] ?? null;
        $flash_error = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        return array_merge([
            'active_page' => 'customers',
            'auth_user' => $this->auth->user(),
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_success' => $flash_success,
            'flash_error' => $flash_error,
        ], $extra);
    }

    /**
     * List customers with search and pagination.
     */
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = max(1, (int) ($params['per_page'] ?? 25));
        $offset = ($page - 1) * $perPage;
        $search = $params['search'] ?? null;

        $where = ['1=1'];
        $bindings = [];

        if ($search) {
            $where[] = '(c.name LIKE ? OR c.contact_name LIKE ? OR c.email LIKE ? OR c.abn LIKE ?)';
            $term = "%{$search}%";
            $bindings[] = $term;
            $bindings[] = $term;
            $bindings[] = $term;
            $bindings[] = $term;
        }

        $whereClause = implode(' AND ', $where);

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM customers c WHERE {$whereClause}",
            $bindings
        );

        $customers = $this->db->fetchAll(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM machines m WHERE m.customer_id = c.id AND m.status = 'active') AS active_machines
             FROM customers c
             WHERE {$whereClause}
             ORDER BY c.name ASC
             LIMIT {$perPage} OFFSET {$offset}",
            $bindings
        );

        return $this->twig->render($response, 'admin/customers/index.twig', $this->viewData([
            'customers' => $customers,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
            'filters' => ['search' => $search],
        ]));
    }

    /**
     * Show create customer form.
     */
    public function create(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'admin/customers/create.twig', $this->viewData());
    }

    /**
     * Store a new customer.
     */
    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        $customerData = [
            'name' => trim((string) ($data['name'] ?? '')),
            'contact_name' => trim((string) ($data['contact_name'] ?? '')),
            'email' => trim((string) ($data['email'] ?? '')),
            'phone' => trim((string) ($data['phone'] ?? '')),
            'mobile' => trim((string) ($data['mobile'] ?? '')),
            'address_line1' => trim((string) ($data['address_line1'] ?? '')),
            'address_line2' => trim((string) ($data['address_line2'] ?? '')),
            'city' => trim((string) ($data['city'] ?? '')),
            'state' => trim((string) ($data['state'] ?? '')),
            'postcode' => trim((string) ($data['postcode'] ?? '')),
            'country' => trim((string) ($data['country'] ?? 'Australia')),
            'abn' => trim((string) ($data['abn'] ?? '')),
            'commission_rate' => isset($data['commission_rate']) && $data['commission_rate'] !== ''
                ? (float) $data['commission_rate'] : null,
            'processing_fee' => isset($data['processing_fee']) && $data['processing_fee'] !== ''
                ? (float) $data['processing_fee'] : null,
            'payment_terms' => trim((string) ($data['payment_terms'] ?? '')),
            'payment_method' => trim((string) ($data['payment_method'] ?? '')),
            'bank_name' => trim((string) ($data['bank_name'] ?? '')),
            'bank_bsb' => trim((string) ($data['bank_bsb'] ?? '')),
            'bank_account_number' => trim((string) ($data['bank_account_number'] ?? '')),
            'bank_account_name' => trim((string) ($data['bank_account_name'] ?? '')),
            'notes' => trim((string) ($data['notes'] ?? '')),
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($customerData['name'] === '') {
            $_SESSION['flash_error'] = 'Customer name is required.';
            return $response->withHeader('Location', '/customers/create')->withStatus(302);
        }

        $id = $this->db->insert('customers', $customerData);
        $this->audit->log('created', 'customer', (int) $id, null, $customerData);

        $_SESSION['flash_success'] = 'Customer created successfully.';
        return $response->withHeader('Location', "/customers/{$id}")->withStatus(302);
    }

    /**
     * Show customer detail with machines, revenue summary, commission history.
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        $customer = $this->db->fetch("SELECT * FROM customers WHERE id = ?", [$id]);
        if (!$customer) {
            $_SESSION['flash_error'] = 'Customer not found.';
            return $response->withHeader('Location', '/customers')->withStatus(302);
        }

        $machines = $this->db->fetchAll(
            "SELECT m.*, mt.name AS type_name
             FROM machines m
             LEFT JOIN machine_types mt ON m.machine_type_id = mt.id
             WHERE m.customer_id = ?
             ORDER BY m.name ASC",
            [$id]
        );

        // Build machine ID list for efficient revenue lookup
        $machineIds = array_column($machines, 'id');
        $revenueEntries = [];
        if (!empty($machineIds)) {
            $placeholders = implode(',', array_fill(0, count($machineIds), '?'));
            $revenueEntries = $this->db->fetchAll(
                "SELECT r.*, m.name AS machine_name
                 FROM revenue r
                 JOIN machines m ON r.machine_id = m.id
                 WHERE r.machine_id IN ({$placeholders})
                 ORDER BY r.collection_date DESC
                 LIMIT 20",
                $machineIds
            );
        }

        $commissions = $this->db->fetchAll(
            "SELECT * FROM commission_payments
             WHERE customer_id = ?
             ORDER BY period_end DESC
             LIMIT 20",
            [$id]
        );

        $portalUsers = $this->db->fetchAll(
            "SELECT * FROM customer_portal_users WHERE customer_id = ? ORDER BY name",
            [$id]
        );

        return $this->twig->render($response, 'admin/customers/show.twig', $this->viewData([
            'customer' => $customer,
            'machines' => $machines,
            'revenue_entries' => $revenueEntries,
            'commissions' => $commissions,
            'portal_users' => $portalUsers,
        ]));
    }

    /**
     * Show edit customer form.
     */
    public function edit(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        $customer = $this->db->fetch("SELECT * FROM customers WHERE id = ?", [$id]);
        if (!$customer) {
            $_SESSION['flash_error'] = 'Customer not found.';
            return $response->withHeader('Location', '/customers')->withStatus(302);
        }

        return $this->twig->render($response, 'admin/customers/edit.twig', $this->viewData([
            'customer' => $customer,
        ]));
    }

    /**
     * Update an existing customer. Log rate changes to commission_rate_history.
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $data = $request->getParsedBody();

        $existing = $this->db->fetch("SELECT * FROM customers WHERE id = ?", [$id]);
        if (!$existing) {
            $_SESSION['flash_error'] = 'Customer not found.';
            return $response->withHeader('Location', '/customers')->withStatus(302);
        }

        $customerData = [
            'name' => trim((string) ($data['name'] ?? '')),
            'contact_name' => trim((string) ($data['contact_name'] ?? '')),
            'email' => trim((string) ($data['email'] ?? '')),
            'phone' => trim((string) ($data['phone'] ?? '')),
            'mobile' => trim((string) ($data['mobile'] ?? '')),
            'address_line1' => trim((string) ($data['address_line1'] ?? '')),
            'address_line2' => trim((string) ($data['address_line2'] ?? '')),
            'city' => trim((string) ($data['city'] ?? '')),
            'state' => trim((string) ($data['state'] ?? '')),
            'postcode' => trim((string) ($data['postcode'] ?? '')),
            'country' => trim((string) ($data['country'] ?? 'Australia')),
            'abn' => trim((string) ($data['abn'] ?? '')),
            'commission_rate' => isset($data['commission_rate']) && $data['commission_rate'] !== ''
                ? (float) $data['commission_rate'] : null,
            'processing_fee' => isset($data['processing_fee']) && $data['processing_fee'] !== ''
                ? (float) $data['processing_fee'] : null,
            'payment_terms' => trim((string) ($data['payment_terms'] ?? '')),
            'payment_method' => trim((string) ($data['payment_method'] ?? '')),
            'bank_name' => trim((string) ($data['bank_name'] ?? '')),
            'bank_bsb' => trim((string) ($data['bank_bsb'] ?? '')),
            'bank_account_number' => trim((string) ($data['bank_account_number'] ?? '')),
            'bank_account_name' => trim((string) ($data['bank_account_name'] ?? '')),
            'notes' => trim((string) ($data['notes'] ?? '')),
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($customerData['name'] === '') {
            $_SESSION['flash_error'] = 'Customer name is required.';
            return $response->withHeader('Location', "/customers/{$id}/edit")->withStatus(302);
        }

        // Log commission_rate change
        $oldRate = $existing['commission_rate'] !== null ? (float) $existing['commission_rate'] : null;
        $newRate = $customerData['commission_rate'];
        if ($oldRate !== $newRate) {
            $authUser = $this->auth->user();
            $this->db->insert('commission_rate_history', [
                'customer_id' => $id,
                'field_changed' => 'commission_rate',
                'old_value' => $oldRate !== null ? (string) $oldRate : null,
                'new_value' => $newRate !== null ? (string) $newRate : null,
                'effective_from' => date('Y-m-d'),
                'changed_by' => $authUser['id'] ?? null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        // Log processing_fee change
        $oldFee = $existing['processing_fee'] !== null ? (float) $existing['processing_fee'] : null;
        $newFee = $customerData['processing_fee'];
        if ($oldFee !== $newFee) {
            $authUser = $this->auth->user();
            $this->db->insert('commission_rate_history', [
                'customer_id' => $id,
                'field_changed' => 'processing_fee',
                'old_value' => $oldFee !== null ? (string) $oldFee : null,
                'new_value' => $newFee !== null ? (string) $newFee : null,
                'effective_from' => date('Y-m-d'),
                'changed_by' => $authUser['id'] ?? null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $this->db->update('customers', $customerData, 'id = ?', [$id]);

        $changes = $this->audit->diff($existing, $customerData);
        if (!empty($changes)) {
            $this->audit->log('updated', 'customer', $id, $changes);
        }

        $_SESSION['flash_success'] = 'Customer updated successfully.';
        return $response->withHeader('Location', "/customers/{$id}")->withStatus(302);
    }

    /**
     * Delete a customer.
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        $customer = $this->db->fetch("SELECT * FROM customers WHERE id = ?", [$id]);
        if (!$customer) {
            $_SESSION['flash_error'] = 'Customer not found.';
            return $response->withHeader('Location', '/customers')->withStatus(302);
        }

        $this->db->delete('customers', 'id = ?', [$id]);
        $this->audit->log('deleted', 'customer', $id, $customer);

        $_SESSION['flash_success'] = 'Customer deleted successfully.';
        return $response->withHeader('Location', '/customers')->withStatus(302);
    }

    /**
     * Show CSV import form.
     */
    public function showImport(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'admin/customers/import.twig', $this->viewData());
    }

    /**
     * Process CSV import of customers.
     */
    public function import(Request $request, Response $response): Response
    {
        $uploadedFiles = $request->getUploadedFiles();
        $csvFile = $uploadedFiles['csv_file'] ?? null;

        if (!$csvFile || $csvFile->getError() !== UPLOAD_ERR_OK) {
            $_SESSION['flash_error'] = 'Please upload a valid CSV file.';
            return $response->withHeader('Location', '/customers/import')->withStatus(302);
        }

        $stream = $csvFile->getStream();
        $content = (string) $stream;
        $lines = array_filter(explode("\n", $content));

        if (count($lines) < 2) {
            $_SESSION['flash_error'] = 'CSV file is empty or has no data rows.';
            return $response->withHeader('Location', '/customers/import')->withStatus(302);
        }

        $headers = str_getcsv(array_shift($lines));
        $headers = array_map('trim', $headers);
        $headers = array_map('strtolower', $headers);

        $imported = 0;
        $errors = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            $row = str_getcsv($line);
            $record = array_combine($headers, $row);
            if ($record === false) { $errors++; continue; }

            try {
                $id = $this->db->insert('customers', [
                    'name' => trim($record['name'] ?? ''),
                    'contact_name' => trim($record['contact_name'] ?? ''),
                    'email' => trim($record['email'] ?? ''),
                    'phone' => trim($record['phone'] ?? ''),
                    'mobile' => trim($record['mobile'] ?? ''),
                    'address_line1' => trim($record['address_line1'] ?? $record['address'] ?? ''),
                    'city' => trim($record['city'] ?? $record['suburb'] ?? ''),
                    'state' => trim($record['state'] ?? ''),
                    'postcode' => trim($record['postcode'] ?? ''),
                    'country' => trim($record['country'] ?? 'Australia'),
                    'abn' => trim($record['abn'] ?? ''),
                    'commission_rate' => !empty($record['commission_rate']) ? (float) $record['commission_rate'] : null,
                    'is_active' => 1,
                ]);
                $this->audit->log('created', 'customer', (int) $id, null, ['source' => 'csv_import']);
                $imported++;
            } catch (\Exception $e) {
                $errors++;
            }
        }

        $_SESSION['flash_success'] = "Import complete: {$imported} customers imported, {$errors} errors.";
        return $response->withHeader('Location', '/customers')->withStatus(302);
    }

    /**
     * List/manage portal users for a customer.
     */
    public function portalUsers(Request $request, Response $response, array $args): Response
    {
        $customerId = (int) $args['id'];

        $customer = $this->db->fetch("SELECT * FROM customers WHERE id = ?", [$customerId]);
        if (!$customer) {
            $_SESSION['flash_error'] = 'Customer not found.';
            return $response->withHeader('Location', '/customers')->withStatus(302);
        }

        $portalUsers = $this->db->fetchAll(
            "SELECT * FROM customer_portal_users WHERE customer_id = ? ORDER BY name",
            [$customerId]
        );

        return $this->twig->render($response, 'admin/customers/portal_users.twig', $this->viewData([
            'customer' => $customer,
            'portal_users' => $portalUsers,
        ]));
    }

    /**
     * Create a portal user for a customer.
     */
    public function createPortalUser(Request $request, Response $response, array $args): Response
    {
        $customerId = (int) $args['id'];
        $data = $request->getParsedBody();

        $customer = $this->db->fetch("SELECT id FROM customers WHERE id = ?", [$customerId]);
        if (!$customer) {
            $_SESSION['flash_error'] = 'Customer not found.';
            return $response->withHeader('Location', '/customers')->withStatus(302);
        }

        $name = trim((string) ($data['name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($name === '' || $email === '' || $password === '') {
            $_SESSION['flash_error'] = 'Name, email, and password are required.';
            return $response->withHeader('Location', "/customers/{$customerId}/portal-users")->withStatus(302);
        }

        // Check for duplicate email
        $exists = $this->db->exists('customer_portal_users', 'email = ?', [$email]);
        if ($exists) {
            $_SESSION['flash_error'] = 'A portal user with this email already exists.';
            return $response->withHeader('Location', "/customers/{$customerId}/portal-users")->withStatus(302);
        }

        $this->db->insert('customer_portal_users', [
            'customer_id' => $customerId,
            'name' => $name,
            'email' => $email,
            'password' => AuthService::hashPassword($password),
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $_SESSION['flash_success'] = 'Portal user created successfully.';
        return $response->withHeader('Location', "/customers/{$customerId}/portal-users")->withStatus(302);
    }

    /**
     * Toggle (enable/disable) a portal user.
     */
    public function togglePortalUser(Request $request, Response $response, array $args): Response
    {
        $customerId = (int) $args['id'];
        $userId = (int) $args['user_id'];

        $portalUser = $this->db->fetch(
            "SELECT * FROM customer_portal_users WHERE id = ? AND customer_id = ?",
            [$userId, $customerId]
        );

        if (!$portalUser) {
            $_SESSION['flash_error'] = 'Portal user not found.';
            return $response->withHeader('Location', "/customers/{$customerId}/portal-users")->withStatus(302);
        }

        $newStatus = $portalUser['is_active'] ? 0 : 1;
        $this->db->update('customer_portal_users', [
            'is_active' => $newStatus,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$userId]);

        $statusLabel = $newStatus ? 'enabled' : 'disabled';
        $_SESSION['flash_success'] = "Portal user {$statusLabel} successfully.";
        return $response->withHeader('Location', "/customers/{$customerId}/portal-users")->withStatus(302);
    }
}
