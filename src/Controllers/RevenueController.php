<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\Database;
use App\Services\AuthService;

class RevenueController
{
    public function __construct(
        private Twig $twig,
        private Database $db,
        private AuthService $auth
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
            'active_page' => 'revenue',
            'auth_user' => $this->auth->user(),
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_success' => $flash_success,
            'flash_error' => $flash_error,
        ], $extra);
    }

    /**
     * List revenue entries with filters and pagination.
     */
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = max(1, (int) ($params['per_page'] ?? 25));
        $offset = ($page - 1) * $perPage;

        $dateFrom = $params['date_from'] ?? null;
        $dateTo = $params['date_to'] ?? null;
        $machineId = $params['machine_id'] ?? null;
        $customerId = $params['customer_id'] ?? null;
        $source = $params['source'] ?? null;
        $status = $params['status'] ?? null;

        $where = ['1=1'];
        $bindings = [];

        if ($dateFrom) {
            $where[] = 'r.collection_date >= ?';
            $bindings[] = $dateFrom;
        }
        if ($dateTo) {
            $where[] = 'r.collection_date <= ?';
            $bindings[] = $dateTo;
        }
        if ($machineId) {
            $where[] = 'r.machine_id = ?';
            $bindings[] = (int) $machineId;
        }
        if ($customerId) {
            $where[] = 'm.customer_id = ?';
            $bindings[] = (int) $customerId;
        }
        if ($source) {
            $where[] = 'r.source = ?';
            $bindings[] = $source;
        }
        if ($status) {
            $where[] = 'r.status = ?';
            $bindings[] = $status;
        }

        $whereClause = implode(' AND ', $where);

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*)
             FROM revenue r
             LEFT JOIN machines m ON r.machine_id = m.id
             WHERE {$whereClause}",
            $bindings
        );

        $entries = $this->db->fetchAll(
            "SELECT r.*, m.name AS machine_name, m.machine_code,
                    c.name AS customer_name
             FROM revenue r
             LEFT JOIN machines m ON r.machine_id = m.id
             LEFT JOIN customers c ON m.customer_id = c.id
             WHERE {$whereClause}
             ORDER BY r.collection_date DESC, r.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $bindings
        );

        $machines = $this->db->fetchAll(
            "SELECT id, name, machine_code FROM machines WHERE status = 'active' ORDER BY name"
        );
        $customers = $this->db->fetchAll(
            "SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name"
        );

        return $this->twig->render($response, 'admin/revenue/index.twig', $this->viewData([
            'entries' => $entries,
            'machines' => $machines,
            'customers' => $customers,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'machine_id' => $machineId,
                'customer_id' => $customerId,
                'source' => $source,
                'status' => $status,
            ],
        ]));
    }

    /**
     * Show create revenue entry form.
     */
    public function create(Request $request, Response $response): Response
    {
        $machines = $this->db->fetchAll(
            "SELECT m.id, m.name, m.machine_code, m.status, c.name AS customer_name
             FROM machines m
             LEFT JOIN customers c ON m.customer_id = c.id
             ORDER BY m.name"
        );

        return $this->twig->render($response, 'admin/revenue/create.twig', $this->viewData([
            'machines' => $machines,
        ]));
    }

    /**
     * Store a new revenue entry.
     */
    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $authUser = $this->auth->user();

        $revenueData = [
            'machine_id' => (int) ($data['machine_id'] ?? 0),
            'collection_date' => $data['collection_date'] ?? date('Y-m-d'),
            'cash_amount' => (float) ($data['cash_amount'] ?? 0),
            'card_amount' => (float) ($data['card_amount'] ?? 0),
            'prepaid_amount' => (float) ($data['prepaid_amount'] ?? 0),
            'card_transactions' => (int) ($data['card_transactions'] ?? 0),
            'prepaid_transactions' => (int) ($data['prepaid_transactions'] ?? 0),
            'cash_source' => trim((string) ($data['cash_source'] ?? '')),
            'source' => trim((string) ($data['source'] ?? 'manual')),
            'notes' => trim((string) ($data['notes'] ?? '')),
            'status' => $data['status'] ?? 'pending',
            'collected_by' => $authUser['id'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($revenueData['machine_id'] === 0) {
            $_SESSION['flash_error'] = 'Please select a machine.';
            return $response->withHeader('Location', '/revenue/create')->withStatus(302);
        }

        $id = $this->db->insert('revenue', $revenueData);

        $_SESSION['flash_success'] = 'Revenue entry created successfully.';
        return $response->withHeader('Location', "/revenue/{$id}")->withStatus(302);
    }

    /**
     * Show revenue entry detail.
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        $entry = $this->db->fetch(
            "SELECT r.*, m.name AS machine_name, m.machine_code,
                    c.name AS customer_name,
                    u.full_name AS created_by_name,
                    ua.full_name AS approved_by_name
             FROM revenue r
             LEFT JOIN machines m ON r.machine_id = m.id
             LEFT JOIN customers c ON m.customer_id = c.id
             LEFT JOIN users u ON r.collected_by = u.id
             LEFT JOIN users ua ON r.approved_by = ua.id
             WHERE r.id = ?",
            [$id]
        );

        if (!$entry) {
            $_SESSION['flash_error'] = 'Revenue entry not found.';
            return $response->withHeader('Location', '/revenue')->withStatus(302);
        }

        return $this->twig->render($response, 'admin/revenue/show.twig', $this->viewData([
            'entry' => $entry,
        ]));
    }

    /**
     * Show edit revenue entry form.
     */
    public function edit(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        $entry = $this->db->fetch("SELECT * FROM revenue WHERE id = ?", [$id]);
        if (!$entry) {
            $_SESSION['flash_error'] = 'Revenue entry not found.';
            return $response->withHeader('Location', '/revenue')->withStatus(302);
        }

        $machines = $this->db->fetchAll(
            "SELECT m.id, m.name, m.machine_code, c.name AS customer_name
             FROM machines m
             LEFT JOIN customers c ON m.customer_id = c.id
             WHERE m.status = 'active' OR m.id = ?
             ORDER BY m.name",
            [$entry['machine_id']]
        );

        return $this->twig->render($response, 'admin/revenue/edit.twig', $this->viewData([
            'entry' => $entry,
            'machines' => $machines,
        ]));
    }

    /**
     * Update a revenue entry.
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $data = $request->getParsedBody();

        $existing = $this->db->fetch("SELECT id FROM revenue WHERE id = ?", [$id]);
        if (!$existing) {
            $_SESSION['flash_error'] = 'Revenue entry not found.';
            return $response->withHeader('Location', '/revenue')->withStatus(302);
        }

        $revenueData = [
            'machine_id' => (int) ($data['machine_id'] ?? 0),
            'collection_date' => $data['collection_date'] ?? date('Y-m-d'),
            'cash_amount' => (float) ($data['cash_amount'] ?? 0),
            'card_amount' => (float) ($data['card_amount'] ?? 0),
            'prepaid_amount' => (float) ($data['prepaid_amount'] ?? 0),
            'card_transactions' => (int) ($data['card_transactions'] ?? 0),
            'prepaid_transactions' => (int) ($data['prepaid_transactions'] ?? 0),
            'cash_source' => trim((string) ($data['cash_source'] ?? '')),
            'source' => trim((string) ($data['source'] ?? 'manual')),
            'notes' => trim((string) ($data['notes'] ?? '')),
            'status' => $data['status'] ?? 'pending',
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $this->db->update('revenue', $revenueData, 'id = ?', [$id]);

        $_SESSION['flash_success'] = 'Revenue entry updated successfully.';
        return $response->withHeader('Location', "/revenue/{$id}")->withStatus(302);
    }

    /**
     * Delete a revenue entry.
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        $entry = $this->db->fetch("SELECT id FROM revenue WHERE id = ?", [$id]);
        if (!$entry) {
            $_SESSION['flash_error'] = 'Revenue entry not found.';
            return $response->withHeader('Location', '/revenue')->withStatus(302);
        }

        $this->db->delete('revenue', 'id = ?', [$id]);

        $_SESSION['flash_success'] = 'Revenue entry deleted successfully.';
        return $response->withHeader('Location', '/revenue')->withStatus(302);
    }

    /**
     * Approve a revenue entry.
     */
    public function approve(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $authUser = $this->auth->user();

        $entry = $this->db->fetch("SELECT id, status FROM revenue WHERE id = ?", [$id]);
        if (!$entry) {
            $_SESSION['flash_error'] = 'Revenue entry not found.';
            return $response->withHeader('Location', '/revenue')->withStatus(302);
        }

        $this->db->update('revenue', [
            'status' => 'approved',
            'approved_by' => $authUser['id'] ?? null,
            'approved_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);

        $_SESSION['flash_success'] = 'Revenue entry approved.';
        return $response->withHeader('Location', "/revenue/{$id}")->withStatus(302);
    }

    /**
     * Revenue grouped by machine.
     */
    public function byMachine(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $dateFrom = $params['date_from'] ?? date('Y-m-01');
        $dateTo = $params['date_to'] ?? date('Y-m-d');

        $data = $this->db->fetchAll(
            "SELECT m.id, m.name, m.machine_code, c.name AS customer_name,
                    COUNT(r.id) AS entry_count,
                    COALESCE(SUM(r.cash_amount), 0) AS total_cash,
                    COALESCE(SUM(r.card_amount), 0) AS total_card,
                    COALESCE(SUM(r.prepaid_amount), 0) AS total_prepaid,
                    COALESCE(SUM(r.cash_amount + r.card_amount), 0) AS total_revenue
             FROM machines m
             LEFT JOIN revenue r ON r.machine_id = m.id
                 AND r.collection_date BETWEEN ? AND ?
             LEFT JOIN customers c ON m.customer_id = c.id
             WHERE m.status = 'active'
             GROUP BY m.id, m.name, m.machine_code, c.name
             ORDER BY total_revenue DESC",
            [$dateFrom, $dateTo]
        );

        return $this->twig->render($response, 'admin/revenue/by_machine.twig', $this->viewData([
            'machine_revenue' => $data,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]));
    }

    /**
     * Show CSV import form.
     */
    public function showImport(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'admin/revenue/import.twig', $this->viewData());
    }

    /**
     * Process CSV import of revenue entries.
     */
    public function import(Request $request, Response $response): Response
    {
        $uploadedFiles = $request->getUploadedFiles();
        $csvFile = $uploadedFiles['csv_file'] ?? null;

        if (!$csvFile || $csvFile->getError() !== UPLOAD_ERR_OK) {
            $_SESSION['flash_error'] = 'Please upload a valid CSV file.';
            return $response->withHeader('Location', '/revenue/import')->withStatus(302);
        }

        $stream = $csvFile->getStream();
        $content = (string) $stream;
        $lines = array_filter(explode("\n", $content));

        if (count($lines) < 2) {
            $_SESSION['flash_error'] = 'CSV file is empty or has no data rows.';
            return $response->withHeader('Location', '/revenue/import')->withStatus(302);
        }

        $headers = str_getcsv(array_shift($lines));
        $headers = array_map('trim', $headers);
        $headers = array_map('strtolower', $headers);

        $authUser = $this->auth->user();
        $imported = 0;
        $errors = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $row = str_getcsv($line);
            $record = array_combine($headers, $row);

            if ($record === false) {
                $errors++;
                continue;
            }

            try {
                $this->db->insert('revenue', [
                    'machine_id' => (int) ($record['machine_id'] ?? 0),
                    'collection_date' => $record['collection_date'] ?? date('Y-m-d'),
                    'cash_amount' => (float) ($record['cash_amount'] ?? 0),
                    'card_amount' => (float) ($record['card_amount'] ?? 0),
                    'prepaid_amount' => (float) ($record['prepaid_amount'] ?? 0),
                    'card_transactions' => (int) ($record['card_transactions'] ?? 0),
                    'prepaid_transactions' => (int) ($record['prepaid_transactions'] ?? 0),
                    'cash_source' => trim($record['cash_source'] ?? ''),
                    'source' => 'csv_import',
                    'status' => 'pending',
                    'collected_by' => $authUser['id'] ?? null,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $imported++;
            } catch (\Exception $e) {
                $errors++;
            }
        }

        $_SESSION['flash_success'] = "Import complete: {$imported} entries imported, {$errors} errors.";
        return $response->withHeader('Location', '/revenue')->withStatus(302);
    }
}
