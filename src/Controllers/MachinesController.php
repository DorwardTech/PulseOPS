<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\Database;
use App\Services\AuthService;
use App\Services\AuditService;

class MachinesController
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
            'active_page' => 'machines',
            'auth_user' => $this->auth->user(),
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_success' => $flash_success,
            'flash_error' => $flash_error,
        ], $extra);
    }

    /**
     * List machines with filters and pagination.
     */
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = max(1, (int) ($params['per_page'] ?? 25));
        $offset = ($page - 1) * $perPage;

        $status = $params['status'] ?? null;
        $customerId = $params['customer_id'] ?? null;
        $typeId = $params['machine_type_id'] ?? null;
        $search = $params['search'] ?? null;

        $where = ['1=1'];
        $bindings = [];

        if ($status) {
            $where[] = 'm.status = ?';
            $bindings[] = $status;
        }
        if ($customerId) {
            $where[] = 'm.customer_id = ?';
            $bindings[] = (int) $customerId;
        }
        if ($typeId) {
            $where[] = 'm.machine_type_id = ?';
            $bindings[] = (int) $typeId;
        }
        if ($search) {
            $where[] = '(m.name LIKE ? OR m.machine_code LIKE ? OR m.serial_number LIKE ?)';
            $term = "%{$search}%";
            $bindings[] = $term;
            $bindings[] = $term;
            $bindings[] = $term;
        }

        $whereClause = implode(' AND ', $where);

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM machines m WHERE {$whereClause}",
            $bindings
        );

        $machines = $this->db->fetchAll(
            "SELECT m.*, c.name AS customer_name, mt.name AS type_name,
                    (SELECT mp.file_path FROM machine_photos mp WHERE mp.machine_id = m.id ORDER BY mp.is_primary DESC, mp.created_at ASC LIMIT 1) AS photo_url
             FROM machines m
             LEFT JOIN customers c ON m.customer_id = c.id
             LEFT JOIN machine_types mt ON m.machine_type_id = mt.id
             WHERE {$whereClause}
             ORDER BY m.machine_code ASC
             LIMIT {$perPage} OFFSET {$offset}",
            $bindings
        );

        $customers = $this->db->fetchAll(
            "SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name"
        );
        $machineTypes = $this->db->fetchAll(
            "SELECT id, name FROM machine_types ORDER BY name"
        );

        $view = $params['view'] ?? 'grid';

        return $this->twig->render($response, 'admin/machines/index.twig', $this->viewData([
            'machines' => $machines,
            'customers' => $customers,
            'machine_types' => $machineTypes,
            'view' => $view,
            'pagination' => [
                'total' => $total,
                'current_page' => $page,
                'per_page' => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
            ],
            'filters' => [
                'status' => $status,
                'customer_id' => $customerId,
                'machine_type_id' => $typeId,
                'search' => $search,
                'view' => $view,
            ],
        ]));
    }

    /**
     * Show create machine form.
     */
    public function create(Request $request, Response $response): Response
    {
        $customers = $this->db->fetchAll(
            "SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name"
        );
        $machineTypes = $this->db->fetchAll(
            "SELECT id, name FROM machine_types ORDER BY name"
        );

        return $this->twig->render($response, 'admin/machines/create.twig', $this->viewData([
            'customers' => $customers,
            'machine_types' => $machineTypes,
        ]));
    }

    /**
     * Store a new machine.
     */
    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        $machineData = [
            'machine_code' => trim((string) ($data['machine_code'] ?? '')),
            'name' => trim((string) ($data['name'] ?? '')),
            'customer_id' => !empty($data['customer_id']) ? (int) $data['customer_id'] : null,
            'machine_type_id' => !empty($data['machine_type_id']) ? (int) $data['machine_type_id'] : null,
            'description' => trim((string) ($data['description'] ?? '')),
            'location_details' => trim((string) ($data['location_details'] ?? '')),
            'status' => $data['status'] ?? 'active',
            'serial_number' => trim((string) ($data['serial_number'] ?? '')),
            'manufacturer' => trim((string) ($data['manufacturer'] ?? '')),
            'model' => trim((string) ($data['model'] ?? '')),
            'nayax_cash_counting' => !empty($data['nayax_cash_counting']) ? 1 : 0,
            'commission_rate' => isset($data['commission_rate']) && $data['commission_rate'] !== ''
                ? (float) $data['commission_rate'] : null,
            'notes' => trim((string) ($data['notes'] ?? '')),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($machineData['machine_code'] === '' || $machineData['name'] === '') {
            $_SESSION['flash_error'] = 'Machine code and name are required.';
            return $response->withHeader('Location', '/machines/create')->withStatus(302);
        }

        // Auto-populate commission_rate from customer default if not explicitly set
        if ($machineData['commission_rate'] === null && $machineData['customer_id'] !== null) {
            $custRate = $this->db->fetchColumn(
                "SELECT commission_rate FROM customers WHERE id = ?",
                [$machineData['customer_id']]
            );
            if ($custRate !== null && $custRate !== false) {
                $machineData['commission_rate'] = (float) $custRate;
            }
        }

        $id = $this->db->insert('machines', $machineData);

        $this->audit->log('created', 'machine', (int) $id, null, $machineData);

        $_SESSION['flash_success'] = 'Machine created successfully.';
        return $response->withHeader('Location', "/machines/{$id}")->withStatus(302);
    }

    /**
     * Show machine detail with revenue history, photos, maintenance jobs.
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        $machine = $this->db->fetch(
            "SELECT m.*, c.name AS customer_name, c.email AS customer_email, c.phone AS customer_phone, mt.name AS type_name
             FROM machines m
             LEFT JOIN customers c ON m.customer_id = c.id
             LEFT JOIN machine_types mt ON m.machine_type_id = mt.id
             WHERE m.id = ?",
            [$id]
        );

        if (!$machine) {
            $_SESSION['flash_error'] = 'Machine not found.';
            return $response->withHeader('Location', '/machines')->withStatus(302);
        }

        $revenueHistory = $this->db->fetchAll(
            "SELECT * FROM revenue WHERE machine_id = ? ORDER BY collection_date DESC LIMIT 50",
            [$id]
        );

        $photos = $this->db->fetchAll(
            "SELECT * FROM machine_photos WHERE machine_id = ? ORDER BY created_at DESC",
            [$id]
        );

        $jobs = $this->db->fetchAll(
            "SELECT j.*, js.name AS status_name, js.slug AS status_slug, js.color AS status_color
             FROM maintenance_jobs j
             LEFT JOIN job_statuses js ON j.status_id = js.id
             WHERE j.machine_id = ? ORDER BY j.created_at DESC LIMIT 20",
            [$id]
        );

        $activityLogs = $this->audit->getLogsForEntity('machine', $id);
        foreach ($activityLogs as &$log) {
            $log['changes'] = $log['old_values'] ? json_decode($log['old_values'], true) : null;
            $log['new_data'] = $log['new_values'] ? json_decode($log['new_values'], true) : null;
        }
        unset($log);

        // Nayax device linked to this machine
        $nayaxDevice = null;
        $nayaxTransactions = [];
        try {
            $nayaxDevice = $this->db->fetch(
                "SELECT * FROM nayax_devices WHERE machine_id = ? LIMIT 1",
                [$id]
            );

            if ($nayaxDevice) {
                $nayaxTransactions = $this->db->fetchAll(
                    "SELECT * FROM nayax_transactions
                     WHERE device_id = ?
                     ORDER BY transaction_date DESC LIMIT 25",
                    [$nayaxDevice['device_id']]
                );
            }
        } catch (\Exception $e) {
            // Nayax tables may not exist yet
        }

        return $this->twig->render($response, 'admin/machines/show.twig', $this->viewData([
            'machine' => $machine,
            'revenue_entries' => $revenueHistory,
            'photos' => $photos,
            'jobs' => $jobs,
            'activity_logs' => $activityLogs,
            'nayax_device' => $nayaxDevice,
            'nayax_transactions' => $nayaxTransactions,
        ]));
    }

    /**
     * Show edit machine form.
     */
    public function edit(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        $machine = $this->db->fetch("SELECT * FROM machines WHERE id = ?", [$id]);
        if (!$machine) {
            $_SESSION['flash_error'] = 'Machine not found.';
            return $response->withHeader('Location', '/machines')->withStatus(302);
        }

        $customers = $this->db->fetchAll(
            "SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name"
        );
        $machineTypes = $this->db->fetchAll(
            "SELECT id, name FROM machine_types ORDER BY name"
        );

        return $this->twig->render($response, 'admin/machines/edit.twig', $this->viewData([
            'machine' => $machine,
            'customers' => $customers,
            'machine_types' => $machineTypes,
        ]));
    }

    /**
     * Update an existing machine.
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $data = $request->getParsedBody();

        $oldMachine = $this->db->fetch("SELECT * FROM machines WHERE id = ?", [$id]);

        $machineData = [
            'machine_code' => trim((string) ($data['machine_code'] ?? '')),
            'name' => trim((string) ($data['name'] ?? '')),
            'customer_id' => !empty($data['customer_id']) ? (int) $data['customer_id'] : null,
            'machine_type_id' => !empty($data['machine_type_id']) ? (int) $data['machine_type_id'] : null,
            'description' => trim((string) ($data['description'] ?? '')),
            'location_details' => trim((string) ($data['location_details'] ?? '')),
            'status' => $data['status'] ?? 'active',
            'serial_number' => trim((string) ($data['serial_number'] ?? '')),
            'manufacturer' => trim((string) ($data['manufacturer'] ?? '')),
            'model' => trim((string) ($data['model'] ?? '')),
            'nayax_cash_counting' => !empty($data['nayax_cash_counting']) ? 1 : 0,
            'commission_rate' => isset($data['commission_rate']) && $data['commission_rate'] !== ''
                ? (float) $data['commission_rate'] : null,
            'notes' => trim((string) ($data['notes'] ?? '')),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($machineData['machine_code'] === '' || $machineData['name'] === '') {
            $_SESSION['flash_error'] = 'Machine code and name are required.';
            return $response->withHeader('Location', "/machines/{$id}/edit")->withStatus(302);
        }

        // Auto-populate commission_rate from customer default if not explicitly set
        // and customer has changed
        $customerChanged = $oldMachine && (int) ($oldMachine['customer_id'] ?? 0) !== ($machineData['customer_id'] ?? 0);
        if ($machineData['commission_rate'] === null && $machineData['customer_id'] !== null && $customerChanged) {
            $custRate = $this->db->fetchColumn(
                "SELECT commission_rate FROM customers WHERE id = ?",
                [$machineData['customer_id']]
            );
            if ($custRate !== null && $custRate !== false) {
                $machineData['commission_rate'] = (float) $custRate;
            }
        }

        $this->db->update('machines', $machineData, 'id = ?', [$id]);

        $changes = $this->audit->diff($oldMachine ?? [], $machineData);
        if (!empty($changes)) {
            $this->audit->log('updated', 'machine', $id, $changes);
        }

        $_SESSION['flash_success'] = 'Machine updated successfully.';
        return $response->withHeader('Location', "/machines/{$id}")->withStatus(302);
    }

    /**
     * Delete a machine (POST for safety).
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        $machine = $this->db->fetch("SELECT * FROM machines WHERE id = ?", [$id]);
        if (!$machine) {
            $_SESSION['flash_error'] = 'Machine not found.';
            return $response->withHeader('Location', '/machines')->withStatus(302);
        }

        $this->db->delete('machines', 'id = ?', [$id]);
        $this->audit->log('deleted', 'machine', $id, $machine);

        $_SESSION['flash_success'] = 'Machine deleted successfully.';
        return $response->withHeader('Location', '/machines')->withStatus(302);
    }

    /**
     * Show CSV import form.
     */
    public function showImport(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'admin/machines/import.twig', $this->viewData());
    }

    /**
     * Process CSV import of machines.
     */
    public function import(Request $request, Response $response): Response
    {
        $uploadedFiles = $request->getUploadedFiles();
        $csvFile = $uploadedFiles['csv_file'] ?? null;

        if (!$csvFile || $csvFile->getError() !== UPLOAD_ERR_OK) {
            $_SESSION['flash_error'] = 'Please upload a valid CSV file.';
            return $response->withHeader('Location', '/machines/import')->withStatus(302);
        }

        $stream = $csvFile->getStream();
        $content = (string) $stream;
        $lines = array_filter(explode("\n", $content));

        if (count($lines) < 2) {
            $_SESSION['flash_error'] = 'CSV file is empty or has no data rows.';
            return $response->withHeader('Location', '/machines/import')->withStatus(302);
        }

        $headers = str_getcsv(array_shift($lines));
        $headers = array_map('trim', $headers);
        $headers = array_map('strtolower', $headers);

        // Build lookup maps for customer and machine type names
        $customerMap = [];
        $customers = $this->db->fetchAll("SELECT id, name FROM customers");
        foreach ($customers as $c) {
            $customerMap[strtolower(trim($c['name']))] = (int) $c['id'];
        }

        $typeMap = [];
        $types = $this->db->fetchAll("SELECT id, name FROM machine_types");
        foreach ($types as $t) {
            $typeMap[strtolower(trim($t['name']))] = (int) $t['id'];
        }

        $validStatuses = ['active', 'maintenance', 'inactive', 'in_storage'];

        $imported = 0;
        $errors = 0;
        $skipped = [];

        foreach ($lines as $lineNum => $line) {
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

            // Resolve customer by name or ID
            $customerId = null;
            $customerVal = trim($record['customer'] ?? $record['customer_name'] ?? $record['customer_id'] ?? '');
            if ($customerVal !== '') {
                if (is_numeric($customerVal)) {
                    $customerId = (int) $customerVal;
                } else {
                    $customerId = $customerMap[strtolower($customerVal)] ?? null;
                    if ($customerId === null) {
                        $skipped[] = "Row " . ($lineNum + 2) . ": Customer '{$customerVal}' not found";
                        $errors++;
                        continue;
                    }
                }
            }

            // Resolve machine type by name or ID
            $typeId = null;
            $typeVal = trim($record['machine_type'] ?? $record['type'] ?? $record['machine_type_id'] ?? '');
            if ($typeVal !== '') {
                if (is_numeric($typeVal)) {
                    $typeId = (int) $typeVal;
                } else {
                    $typeId = $typeMap[strtolower($typeVal)] ?? null;
                }
            }

            // Resolve status - accept friendly names
            $statusVal = strtolower(trim($record['status'] ?? 'active'));
            $statusVal = str_replace(' ', '_', $statusVal);
            if (!in_array($statusVal, $validStatuses)) {
                $statusVal = 'active';
            }

            try {
                $id = $this->db->insert('machines', [
                    'machine_code' => trim($record['machine_code'] ?? ''),
                    'name' => trim($record['name'] ?? ''),
                    'customer_id' => $customerId,
                    'machine_type_id' => $typeId,
                    'description' => trim($record['description'] ?? ''),
                    'location_details' => trim($record['location_details'] ?? $record['location'] ?? ''),
                    'status' => $statusVal,
                    'serial_number' => trim($record['serial_number'] ?? ''),
                    'manufacturer' => trim($record['manufacturer'] ?? ''),
                    'model' => trim($record['model'] ?? ''),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $this->audit->log('created', 'machine', (int) $id, null, ['source' => 'csv_import']);
                $imported++;
            } catch (\Exception $e) {
                $errors++;
            }
        }

        $msg = "Import complete: {$imported} machines imported, {$errors} errors.";
        if (!empty($skipped)) {
            $msg .= ' Skipped: ' . implode('; ', array_slice($skipped, 0, 5));
        }
        $_SESSION['flash_success'] = $msg;
        return $response->withHeader('Location', '/machines')->withStatus(302);
    }

    /**
     * Upload a photo for a machine.
     */
    public function uploadPhoto(Request $request, Response $response, array $args): Response
    {
        $machineId = (int) $args['id'];

        $machine = $this->db->fetch("SELECT id FROM machines WHERE id = ?", [$machineId]);
        if (!$machine) {
            $_SESSION['flash_error'] = 'Machine not found.';
            return $response->withHeader('Location', '/machines')->withStatus(302);
        }

        $data = $request->getParsedBody();
        $uploadedFiles = $request->getUploadedFiles();
        $photo = $uploadedFiles['photo'] ?? null;

        if (!$photo || $photo->getError() !== UPLOAD_ERR_OK) {
            $_SESSION['flash_error'] = 'Please upload a valid image file.';
            return $response->withHeader('Location', "/machines/{$machineId}")->withStatus(302);
        }

        $extension = pathinfo($photo->getClientFilename(), PATHINFO_EXTENSION);
        $filename = sprintf('machine_%d_%s.%s', $machineId, bin2hex(random_bytes(8)), $extension);
        $uploadDir = __DIR__ . '/../../public/uploads/machines';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $photo->moveTo("{$uploadDir}/{$filename}");

        $this->db->insert('machine_photos', [
            'machine_id' => $machineId,
            'filename' => $filename,
            'original_name' => $photo->getClientFilename(),
            'file_path' => "/uploads/machines/{$filename}",
            'mime_type' => $photo->getClientMediaType(),
            'file_size' => $photo->getSize(),
            'description' => trim((string) ($data['caption'] ?? '')),
            'uploaded_by' => $this->auth->user()['id'] ?? null,
        ]);

        $_SESSION['flash_success'] = 'Photo uploaded successfully.';
        return $response->withHeader('Location', "/machines/{$machineId}")->withStatus(302);
    }

    /**
     * Delete a machine photo.
     */
    public function deletePhoto(Request $request, Response $response, array $args): Response
    {
        $machineId = (int) $args['id'];
        $photoId = (int) $args['photo_id'];

        $photo = $this->db->fetch(
            "SELECT * FROM machine_photos WHERE id = ? AND machine_id = ?",
            [$photoId, $machineId]
        );

        if (!$photo) {
            $_SESSION['flash_error'] = 'Photo not found.';
            return $response->withHeader('Location', "/machines/{$machineId}")->withStatus(302);
        }

        // Remove file from disk
        $filePath = __DIR__ . '/../../public' . $photo['file_path'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        $this->db->delete('machine_photos', 'id = ?', [$photoId]);

        $_SESSION['flash_success'] = 'Photo deleted successfully.';
        return $response->withHeader('Location', "/machines/{$machineId}")->withStatus(302);
    }
}
