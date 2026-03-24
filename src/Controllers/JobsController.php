<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\Database;
use App\Services\AuthService;

class JobsController
{
    public function __construct(
        private Twig $twig,
        private Database $db,
        private AuthService $auth
    ) {}

    /**
     * List jobs with filters and pagination.
     */
    public function index(Request $request, Response $response, array $args = []): Response
    {
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $status = $params['status'] ?? '';
        $machineId = $params['machine_id'] ?? '';
        $priority = $params['priority'] ?? '';
        $assignedTo = $params['assigned_to'] ?? '';
        $search = trim($params['search'] ?? '');

        $where = ['1=1'];
        $bindings = [];

        if ($status !== '') {
            $where[] = 'j.status_id = ?';
            $bindings[] = (int) $status;
        }
        if ($machineId !== '') {
            $where[] = 'j.machine_id = ?';
            $bindings[] = (int) $machineId;
        }
        if ($priority !== '') {
            $where[] = 'j.priority = ?';
            $bindings[] = $priority;
        }
        if ($assignedTo !== '') {
            $where[] = 'j.assigned_to = ?';
            $bindings[] = (int) $assignedTo;
        }
        if ($search !== '') {
            $where[] = '(j.title LIKE ? OR j.job_number LIKE ? OR j.description LIKE ?)';
            $searchTerm = "%{$search}%";
            $bindings[] = $searchTerm;
            $bindings[] = $searchTerm;
            $bindings[] = $searchTerm;
        }

        $whereClause = implode(' AND ', $where);

        $totalCount = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM maintenance_jobs j WHERE {$whereClause}",
            $bindings
        );

        $totalPages = max(1, (int) ceil($totalCount / $perPage));

        $jobs = $this->db->fetchAll(
            "SELECT j.*, js.name AS status_name, js.color AS status_color,
                    m.name AS machine_name, m.machine_code,
                    u.full_name AS assigned_user_name
             FROM maintenance_jobs j
             LEFT JOIN job_statuses js ON j.status_id = js.id
             LEFT JOIN machines m ON j.machine_id = m.id
             LEFT JOIN users u ON j.assigned_to = u.id
             WHERE {$whereClause}
             ORDER BY j.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $bindings
        );

        $statuses = $this->db->fetchAll("SELECT * FROM job_statuses ORDER BY sort_order ASC, name ASC");
        $machines = $this->db->fetchAll("SELECT id, name, machine_code FROM machines ORDER BY name ASC");
        $users = $this->db->fetchAll("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name ASC");

        return $this->twig->render($response, 'admin/jobs/index.twig', [
            'active_page' => 'jobs',
            'auth_user' => $this->auth->user(),
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'jobs' => $jobs,
            'statuses' => $statuses,
            'machines' => $machines,
            'users' => $users,
            'filters' => [
                'status' => $status,
                'machine_id' => $machineId,
                'priority' => $priority,
                'assigned_to' => $assignedTo,
                'search' => $search,
            ],
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'total_count' => $totalCount,
                'per_page' => $perPage,
            ],
        ]);
    }

    /**
     * Show create job form.
     */
    public function create(Request $request, Response $response, array $args = []): Response
    {
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_error']);

        $machines = $this->db->fetchAll("SELECT id, name, machine_code FROM machines ORDER BY name ASC");
        $statuses = $this->db->fetchAll("SELECT * FROM job_statuses ORDER BY sort_order ASC, name ASC");
        $users = $this->db->fetchAll("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name ASC");

        return $this->twig->render($response, 'admin/jobs/create.twig', [
            'active_page' => 'jobs',
            'auth_user' => $this->auth->user(),
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_error' => $flashError,
            'machines' => $machines,
            'statuses' => $statuses,
            'users' => $users,
        ]);
    }

    /**
     * Store a new job.
     */
    public function store(Request $request, Response $response, array $args = []): Response
    {
        $data = $request->getParsedBody();

        $labourMinutes = (int) ($data['labour_minutes'] ?? 0);
        $labourRate = (float) ($data['labour_rate'] ?? 0);
        $labourCost = round(($labourMinutes / 60) * $labourRate, 2);

        $jobId = $this->db->insert('maintenance_jobs', [
            'machine_id' => !empty($data['machine_id']) ? (int) $data['machine_id'] : null,
            'status_id' => !empty($data['status_id']) ? (int) $data['status_id'] : null,
            'job_type' => $data['job_type'] ?? 'maintenance',
            'priority' => $data['priority'] ?? 'medium',
            'title' => trim($data['title'] ?? ''),
            'description' => trim($data['description'] ?? ''),
            'assigned_to' => !empty($data['assigned_to']) ? (int) $data['assigned_to'] : null,
            'scheduled_date' => !empty($data['scheduled_date']) ? $data['scheduled_date'] : null,
            'scheduled_time' => !empty($data['scheduled_time']) ? $data['scheduled_time'] : null,
            'labour_minutes' => $labourMinutes,
            'labour_rate' => $labourRate,
            'labour_cost' => $labourCost,
            'parts_cost' => 0,
            'total_cost' => $labourCost,
            'is_customer_visible' => !empty($data['is_customer_visible']) ? 1 : 0,
            'created_by' => $this->auth->user()['id'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // Auto-generate job_number
        $jobNumber = 'JOB-' . str_pad((string) $jobId, 6, '0', STR_PAD_LEFT);
        $this->db->update('maintenance_jobs', ['job_number' => $jobNumber], 'id = ?', [$jobId]);

        $_SESSION['flash_success'] = 'Job created successfully.';
        return $response->withHeader('Location', '/jobs/' . $jobId)->withStatus(302);
    }

    /**
     * Show job detail with notes, photos, parts.
     */
    public function show(Request $request, Response $response, array $args = []): Response
    {
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $jobId = (int) $args['id'];

        $job = $this->db->fetch(
            "SELECT j.*, js.name AS status_name, js.color AS status_color,
                    m.name AS machine_name, m.machine_code, m.location_details,
                    c.name AS customer_name, c.business_name,
                    u.full_name AS assigned_user_name
             FROM maintenance_jobs j
             LEFT JOIN job_statuses js ON j.status_id = js.id
             LEFT JOIN machines m ON j.machine_id = m.id
             LEFT JOIN customers c ON m.customer_id = c.id
             LEFT JOIN users u ON j.assigned_to = u.id
             WHERE j.id = ?",
            [$jobId]
        );

        if (!$job) {
            $_SESSION['flash_error'] = 'Job not found.';
            return $response->withHeader('Location', '/jobs')->withStatus(302);
        }

        $notes = $this->db->fetchAll(
            "SELECT jn.*, u.full_name AS author_name
             FROM job_notes jn
             LEFT JOIN users u ON jn.created_by = u.id
             WHERE jn.job_id = ?
             ORDER BY jn.created_at DESC",
            [$jobId]
        );

        $photos = $this->db->fetchAll(
            "SELECT * FROM job_photos WHERE job_id = ? ORDER BY created_at DESC",
            [$jobId]
        );

        $parts = $this->db->fetchAll(
            "SELECT * FROM job_parts WHERE job_id = ? ORDER BY created_at DESC",
            [$jobId]
        );

        $statuses = $this->db->fetchAll("SELECT * FROM job_statuses ORDER BY sort_order ASC, name ASC");

        return $this->twig->render($response, 'admin/jobs/show.twig', [
            'active_page' => 'jobs',
            'auth_user' => $this->auth->user(),
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'job' => $job,
            'notes' => $notes,
            'photos' => $photos,
            'parts' => $parts,
            'statuses' => $statuses,
        ]);
    }

    /**
     * Show edit job form.
     */
    public function edit(Request $request, Response $response, array $args = []): Response
    {
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_error']);

        $jobId = (int) $args['id'];

        $job = $this->db->fetch("SELECT * FROM maintenance_jobs WHERE id = ?", [$jobId]);

        if (!$job) {
            $_SESSION['flash_error'] = 'Job not found.';
            return $response->withHeader('Location', '/jobs')->withStatus(302);
        }

        $machines = $this->db->fetchAll("SELECT id, name, machine_code FROM machines ORDER BY name ASC");
        $statuses = $this->db->fetchAll("SELECT * FROM job_statuses ORDER BY sort_order ASC, name ASC");
        $users = $this->db->fetchAll("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name ASC");

        return $this->twig->render($response, 'admin/jobs/edit.twig', [
            'active_page' => 'jobs',
            'auth_user' => $this->auth->user(),
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_error' => $flashError,
            'job' => $job,
            'machines' => $machines,
            'statuses' => $statuses,
            'users' => $users,
        ]);
    }

    /**
     * Update a job.
     */
    public function update(Request $request, Response $response, array $args = []): Response
    {
        $jobId = (int) $args['id'];
        $data = $request->getParsedBody();

        $job = $this->db->fetch("SELECT * FROM maintenance_jobs WHERE id = ?", [$jobId]);
        if (!$job) {
            $_SESSION['flash_error'] = 'Job not found.';
            return $response->withHeader('Location', '/jobs')->withStatus(302);
        }

        $labourMinutes = (int) ($data['labour_minutes'] ?? 0);
        $labourRate = (float) ($data['labour_rate'] ?? 0);
        $labourCost = round(($labourMinutes / 60) * $labourRate, 2);

        $partsCost = (float) $this->db->fetchColumn(
            "SELECT COALESCE(SUM(quantity * unit_cost), 0) FROM job_parts WHERE job_id = ?",
            [$jobId]
        );

        $totalCost = round($labourCost + $partsCost, 2);

        $this->db->update('maintenance_jobs', [
            'machine_id' => !empty($data['machine_id']) ? (int) $data['machine_id'] : null,
            'status_id' => !empty($data['status_id']) ? (int) $data['status_id'] : null,
            'job_type' => $data['job_type'] ?? 'maintenance',
            'priority' => $data['priority'] ?? 'medium',
            'title' => trim($data['title'] ?? ''),
            'description' => trim($data['description'] ?? ''),
            'assigned_to' => !empty($data['assigned_to']) ? (int) $data['assigned_to'] : null,
            'scheduled_date' => !empty($data['scheduled_date']) ? $data['scheduled_date'] : null,
            'scheduled_time' => !empty($data['scheduled_time']) ? $data['scheduled_time'] : null,
            'labour_minutes' => $labourMinutes,
            'labour_rate' => $labourRate,
            'labour_cost' => $labourCost,
            'parts_cost' => $partsCost,
            'total_cost' => $totalCost,
            'is_customer_visible' => !empty($data['is_customer_visible']) ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$jobId]);

        $_SESSION['flash_success'] = 'Job updated successfully.';
        return $response->withHeader('Location', '/jobs/' . $jobId)->withStatus(302);
    }

    /**
     * Delete a job.
     */
    public function delete(Request $request, Response $response, array $args = []): Response
    {
        $jobId = (int) $args['id'];

        $job = $this->db->fetch("SELECT * FROM maintenance_jobs WHERE id = ?", [$jobId]);
        if (!$job) {
            $_SESSION['flash_error'] = 'Job not found.';
            return $response->withHeader('Location', '/jobs')->withStatus(302);
        }

        // Delete related records
        $this->db->delete('job_notes', 'job_id = ?', [$jobId]);
        $this->db->delete('job_parts', 'job_id = ?', [$jobId]);

        // Delete photo files and records
        $photos = $this->db->fetchAll("SELECT * FROM job_photos WHERE job_id = ?", [$jobId]);
        foreach ($photos as $photo) {
            $filePath = __DIR__ . '/../../public/uploads/jobs/' . $photo['filename'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
        $this->db->delete('job_photos', 'job_id = ?', [$jobId]);

        $this->db->delete('maintenance_jobs', 'id = ?', [$jobId]);

        $_SESSION['flash_success'] = 'Job deleted successfully.';
        return $response->withHeader('Location', '/jobs')->withStatus(302);
    }

    /**
     * Add a note to a job.
     */
    public function addNote(Request $request, Response $response, array $args = []): Response
    {
        $jobId = (int) $args['id'];
        $data = $request->getParsedBody();

        $note = trim($data['note'] ?? '');
        if ($note === '') {
            $_SESSION['flash_error'] = 'Note content is required.';
            return $response->withHeader('Location', '/jobs/' . $jobId)->withStatus(302);
        }

        $this->db->insert('job_notes', [
            'job_id' => $jobId,
            'note' => $note,
            'created_by' => $this->auth->user()['id'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $_SESSION['flash_success'] = 'Note added successfully.';
        return $response->withHeader('Location', '/jobs/' . $jobId)->withStatus(302);
    }

    /**
     * Upload a photo to a job.
     */
    public function uploadPhoto(Request $request, Response $response, array $args = []): Response
    {
        $jobId = (int) $args['id'];
        $uploadedFiles = $request->getUploadedFiles();
        $photo = $uploadedFiles['photo'] ?? null;

        if (!$photo || $photo->getError() !== UPLOAD_ERR_OK) {
            $_SESSION['flash_error'] = 'Please select a valid photo to upload.';
            return $response->withHeader('Location', '/jobs/' . $jobId)->withStatus(302);
        }

        $uploadDir = __DIR__ . '/../../public/uploads/jobs';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $extension = pathinfo($photo->getClientFilename(), PATHINFO_EXTENSION);
        $filename = 'job_' . $jobId . '_' . bin2hex(random_bytes(8)) . '.' . $extension;

        $photo->moveTo($uploadDir . '/' . $filename);

        $data = $request->getParsedBody();

        $this->db->insert('job_photos', [
            'job_id' => $jobId,
            'filename' => $filename,
            'original_name' => $photo->getClientFilename(),
            'caption' => trim($data['caption'] ?? ''),
            'uploaded_by' => $this->auth->user()['id'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $_SESSION['flash_success'] = 'Photo uploaded successfully.';
        return $response->withHeader('Location', '/jobs/' . $jobId)->withStatus(302);
    }

    /**
     * Delete a photo from a job.
     */
    public function deletePhoto(Request $request, Response $response, array $args = []): Response
    {
        $jobId = (int) $args['id'];
        $photoId = (int) $args['photoId'];

        $photo = $this->db->fetch(
            "SELECT * FROM job_photos WHERE id = ? AND job_id = ?",
            [$photoId, $jobId]
        );

        if ($photo) {
            $filePath = __DIR__ . '/../../public/uploads/jobs/' . $photo['filename'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            $this->db->delete('job_photos', 'id = ?', [$photoId]);
            $_SESSION['flash_success'] = 'Photo deleted successfully.';
        } else {
            $_SESSION['flash_error'] = 'Photo not found.';
        }

        return $response->withHeader('Location', '/jobs/' . $jobId)->withStatus(302);
    }

    /**
     * Add a part to a job.
     */
    public function addPart(Request $request, Response $response, array $args = []): Response
    {
        $jobId = (int) $args['id'];
        $data = $request->getParsedBody();

        $quantity = (int) ($data['quantity'] ?? 1);
        $unitCost = (float) ($data['unit_cost'] ?? 0);
        $totalCostPart = round($quantity * $unitCost, 2);

        $this->db->insert('job_parts', [
            'job_id' => $jobId,
            'part_name' => trim($data['part_name'] ?? ''),
            'part_number' => trim($data['part_number'] ?? ''),
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCostPart,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Recalculate job parts_cost and total_cost
        $this->recalculateJobCosts($jobId);

        $_SESSION['flash_success'] = 'Part added successfully.';
        return $response->withHeader('Location', '/jobs/' . $jobId)->withStatus(302);
    }

    /**
     * Delete a part from a job.
     */
    public function deletePart(Request $request, Response $response, array $args = []): Response
    {
        $jobId = (int) $args['id'];
        $partId = (int) $args['partId'];

        $this->db->delete('job_parts', 'id = ? AND job_id = ?', [$partId, $jobId]);

        // Recalculate job parts_cost and total_cost
        $this->recalculateJobCosts($jobId);

        $_SESSION['flash_success'] = 'Part deleted successfully.';
        return $response->withHeader('Location', '/jobs/' . $jobId)->withStatus(302);
    }

    /**
     * Update job status.
     */
    public function updateStatus(Request $request, Response $response, array $args = []): Response
    {
        $jobId = (int) $args['id'];
        $data = $request->getParsedBody();

        $statusId = (int) ($data['status_id'] ?? 0);
        if ($statusId <= 0) {
            $_SESSION['flash_error'] = 'Invalid status.';
            return $response->withHeader('Location', '/jobs/' . $jobId)->withStatus(302);
        }

        $this->db->update('maintenance_jobs', [
            'status_id' => $statusId,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$jobId]);

        $_SESSION['flash_success'] = 'Job status updated.';
        return $response->withHeader('Location', '/jobs/' . $jobId)->withStatus(302);
    }

    // ─── Private helpers ───────────────────────────────────────────────

    /**
     * Recalculate parts_cost and total_cost for a job.
     */
    private function recalculateJobCosts(int $jobId): void
    {
        $partsCost = (float) $this->db->fetchColumn(
            "SELECT COALESCE(SUM(quantity * unit_cost), 0) FROM job_parts WHERE job_id = ?",
            [$jobId]
        );

        $job = $this->db->fetch("SELECT labour_cost FROM maintenance_jobs WHERE id = ?", [$jobId]);
        $labourCost = (float) ($job['labour_cost'] ?? 0);
        $totalCost = round($labourCost + $partsCost, 2);

        $this->db->update('maintenance_jobs', [
            'parts_cost' => $partsCost,
            'total_cost' => $totalCost,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$jobId]);
    }
}
