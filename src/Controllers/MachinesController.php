<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\Database;
use App\Services\AuthService;

class MachinesController
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
        $typeId = $params['type'] ?? null;
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
            "SELECT m.*, c.name AS customer_name, mt.name AS type_name
             FROM machines m
             LEFT JOIN customers c ON m.customer_id = c.id
             LEFT JOIN machine_types mt ON m.machine_type_id = mt.id
             WHERE {$whereClause}
             ORDER BY m.name ASC
             LIMIT {$perPage} OFFSET {$offset}",
            $bindings
        );

        $customers = $this->db->fetchAll(
            "SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name"
        );
        $machineTypes = $this->db->fetchAll(
            "SELECT id, name FROM machine_types ORDER BY name"
        );

        return $this->twig->render($response, 'admin/machines/index.twig', $this->viewData([
            'machines' => $machines,
            'customers' => $customers,
            'machine_types' => $machineTypes,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
            'filters' => [
                'status' => $status,
                'customer_id' => $customerId,
                'type' => $typeId,
                'search' => $search,
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

        $id = $this->db->insert('machines', $machineData);

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

        return $this->twig->render($response, 'admin/machines/show.twig', $this->viewData([
            'machine' => $machine,
            'revenue_history' => $revenueHistory,
            'photos' => $photos,
            'jobs' => $jobs,
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

        $this->db->update('machines', $machineData, 'id = ?', [$id]);

        $_SESSION['flash_success'] = 'Machine updated successfully.';
        return $response->withHeader('Location', "/machines/{$id}")->withStatus(302);
    }

    /**
     * Delete a machine (POST for safety).
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        $machine = $this->db->fetch("SELECT id FROM machines WHERE id = ?", [$id]);
        if (!$machine) {
            $_SESSION['flash_error'] = 'Machine not found.';
            return $response->withHeader('Location', '/machines')->withStatus(302);
        }

        $this->db->delete('machines', 'id = ?', [$id]);

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
                $this->db->insert('machines', [
                    'machine_code' => trim($record['machine_code'] ?? ''),
                    'name' => trim($record['name'] ?? ''),
                    'customer_id' => !empty($record['customer_id']) ? (int) $record['customer_id'] : null,
                    'machine_type_id' => !empty($record['machine_type_id']) ? (int) $record['machine_type_id'] : null,
                    'description' => trim($record['description'] ?? ''),
                    'location_details' => trim($record['location_details'] ?? ''),
                    'status' => $record['status'] ?? 'active',
                    'serial_number' => trim($record['serial_number'] ?? ''),
                    'manufacturer' => trim($record['manufacturer'] ?? ''),
                    'model' => trim($record['model'] ?? ''),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $imported++;
            } catch (\Exception $e) {
                $errors++;
            }
        }

        $_SESSION['flash_success'] = "Import complete: {$imported} machines imported, {$errors} errors.";
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
            'original_filename' => $photo->getClientFilename(),
            'file_path' => "/uploads/machines/{$filename}",
            'created_at' => date('Y-m-d H:i:s'),
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
