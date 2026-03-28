<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\Database;
use App\Services\AuthService;

class PortalController
{
    public function __construct(
        private Twig $twig,
        private Database $db,
        private AuthService $auth
    ) {}

    /**
     * Show portal login form.
     */
    public function showLogin(Request $request, Response $response, array $args = []): Response
    {
        if ($this->auth->isPortalAuthenticated()) {
            return $response->withHeader('Location', '/portal/dashboard')->withStatus(302);
        }

        $flashError = $_SESSION['flash_error'] ?? null;
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        unset($_SESSION['flash_error'], $_SESSION['flash_success']);

        return $this->twig->render($response, 'portal/auth/login.twig', [
            'flash_error' => $flashError,
            'flash_success' => $flashSuccess,
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
        ]);
    }

    /**
     * Process portal login.
     */
    public function login(Request $request, Response $response, array $args = []): Response
    {
        $data = $request->getParsedBody();
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($email === '' || $password === '') {
            $_SESSION['flash_error'] = 'Please enter your email and password.';
            return $response->withHeader('Location', '/portal/login')->withStatus(302);
        }

        if ($this->auth->attemptPortal($email, $password)) {
            $_SESSION['flash_success'] = 'Welcome back!';
            return $response->withHeader('Location', '/portal/dashboard')->withStatus(302);
        }

        $_SESSION['flash_error'] = 'Invalid email or password.';
        return $response->withHeader('Location', '/portal/login')->withStatus(302);
    }

    /**
     * Portal logout.
     */
    public function logout(Request $request, Response $response, array $args = []): Response
    {
        $this->auth->logout();
        return $response->withHeader('Location', '/portal/login')->withStatus(302);
    }

    /**
     * Portal dashboard.
     */
    public function dashboard(Request $request, Response $response, array $args = []): Response
    {
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $portalUser = $this->auth->portalUser();
        $customerId = $portalUser['customer_id'];

        // Customer's machines
        $machines = $this->db->fetchAll(
            "SELECT * FROM machines WHERE customer_id = ? ORDER BY name ASC",
            [$customerId]
        );

        $machineIds = array_column($machines, 'id');

        // Recent revenue
        $recentRevenue = [];
        if (!empty($machineIds)) {
            $placeholders = implode(',', array_fill(0, count($machineIds), '?'));
            $recentRevenue = $this->db->fetchAll(
                "SELECT r.*, m.name AS machine_name, m.machine_code
                 FROM revenue r
                 LEFT JOIN machines m ON r.machine_id = m.id
                 WHERE r.machine_id IN ({$placeholders})
                 ORDER BY r.collection_date DESC
                 LIMIT 10",
                $machineIds
            );
        }

        // Commission summary
        $thisMonthStart = date('Y-m-01');
        $thisMonthEnd = date('Y-m-t');

        $totalCommissions = (float) $this->db->fetchColumn(
            "SELECT COALESCE(SUM(commission_amount), 0) FROM commission_payments
             WHERE customer_id = ?",
            [$customerId]
        );

        $pendingCommissions = (float) $this->db->fetchColumn(
            "SELECT COALESCE(SUM(commission_amount), 0) FROM commission_payments
             WHERE customer_id = ? AND status = 'pending'",
            [$customerId]
        );

        // This month revenue across customer's machines
        $thisMonthRevenue = 0.0;
        if (!empty($machineIds)) {
            $placeholders = implode(',', array_fill(0, count($machineIds), '?'));
            $thisMonthRevenue = (float) $this->db->fetchColumn(
                "SELECT COALESCE(SUM(cash_amount + card_amount), 0)
                 FROM revenue
                 WHERE machine_id IN ({$placeholders})
                   AND collection_date BETWEEN ? AND ?",
                array_merge($machineIds, [$thisMonthStart, $thisMonthEnd])
            );
        }

        // Latest commission
        $latestCommission = (float) $this->db->fetchColumn(
            "SELECT COALESCE(commission_amount, 0) FROM commission_payments
             WHERE customer_id = ? ORDER BY period_end DESC LIMIT 1",
            [$customerId]
        );

        // Open jobs for this customer's machines
        $openJobs = 0;
        $recentJobs = [];
        if (!empty($machineIds)) {
            $placeholders = implode(',', array_fill(0, count($machineIds), '?'));
            $openJobs = (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM maintenance_jobs j
                 LEFT JOIN job_statuses js ON j.status_id = js.id
                 WHERE j.machine_id IN ({$placeholders})
                   AND j.is_customer_visible = 1
                   AND (js.slug NOT IN ('completed', 'closed', 'cancelled') OR js.slug IS NULL)",
                $machineIds
            );
            $recentJobs = $this->db->fetchAll(
                "SELECT j.*, m.name AS machine_name,
                        js.name AS status_name, js.color AS status_color
                 FROM maintenance_jobs j
                 LEFT JOIN machines m ON j.machine_id = m.id
                 LEFT JOIN job_statuses js ON j.status_id = js.id
                 WHERE j.machine_id IN ({$placeholders})
                   AND j.is_customer_visible = 1
                 ORDER BY j.created_at DESC LIMIT 5",
                $machineIds
            );
        }

        // Customer info
        $customer = $this->db->fetch(
            "SELECT * FROM customers WHERE id = ?",
            [$customerId]
        );

        return $this->twig->render($response, 'portal/dashboard.twig', [
            'active_page' => 'dashboard',
            'portal_user' => $portalUser,
            'customer' => $customer,
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'machines' => $machines,
            'recent_revenue' => $recentRevenue,
            'recent_jobs' => $recentJobs,
            'stats' => [
                'total_machines' => count($machines),
                'month_revenue' => $thisMonthRevenue,
                'latest_commission' => $latestCommission,
                'open_jobs' => $openJobs,
            ],
        ]);
    }

    /**
     * List machines for this customer.
     */
    public function machines(Request $request, Response $response, array $args = []): Response
    {
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $portalUser = $this->auth->portalUser();
        $customerId = $portalUser['customer_id'];

        $machines = $this->db->fetchAll(
            "SELECT m.*, mt.name AS type_name
             FROM machines m
             LEFT JOIN machine_types mt ON m.machine_type_id = mt.id
             WHERE m.customer_id = ?
             ORDER BY m.name ASC",
            [$customerId]
        );

        return $this->twig->render($response, 'portal/machines/index.twig', [
            'active_page' => 'machines',
            'portal_user' => $portalUser,
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'machines' => $machines,
        ]);
    }

    /**
     * Machine detail (verify machine belongs to customer).
     */
    public function machineDetail(Request $request, Response $response, array $args = []): Response
    {
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $portalUser = $this->auth->portalUser();
        $customerId = $portalUser['customer_id'];
        $machineId = (int) $args['id'];

        $machine = $this->db->fetch(
            "SELECT m.*, mt.name AS type_name
             FROM machines m
             LEFT JOIN machine_types mt ON m.machine_type_id = mt.id
             WHERE m.id = ? AND m.customer_id = ?",
            [$machineId, $customerId]
        );

        if (!$machine) {
            $_SESSION['flash_error'] = 'Machine not found.';
            return $response->withHeader('Location', '/portal/machines')->withStatus(302);
        }

        // Recent revenue for this machine
        $recentRevenue = $this->db->fetchAll(
            "SELECT * FROM revenue WHERE machine_id = ?
             ORDER BY collection_date DESC LIMIT 20",
            [$machineId]
        );

        return $this->twig->render($response, 'portal/machines/show.twig', [
            'active_page' => 'machines',
            'portal_user' => $portalUser,
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'machine' => $machine,
            'recent_revenue' => $recentRevenue,
        ]);
    }

    /**
     * Show report issue form.
     */
    public function showReportIssue(Request $request, Response $response, array $args = []): Response
    {
        $portalUser = $this->auth->portalUser();
        $customerId = $portalUser['customer_id'];
        $machineId = (int) $args['id'];

        $machine = $this->db->fetch(
            "SELECT * FROM machines WHERE id = ? AND customer_id = ?",
            [$machineId, $customerId]
        );

        if (!$machine) {
            $_SESSION['flash_error'] = 'Machine not found.';
            return $response->withHeader('Location', '/portal/machines')->withStatus(302);
        }

        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_error']);

        return $this->twig->render($response, 'portal/machines/report-issue.twig', [
            'active_page' => 'machines',
            'portal_user' => $portalUser,
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_error' => $flashError,
            'machine' => $machine,
        ]);
    }

    /**
     * Create a maintenance job from the portal (report an issue).
     */
    public function reportIssue(Request $request, Response $response, array $args = []): Response
    {
        $portalUser = $this->auth->portalUser();
        $customerId = $portalUser['customer_id'];
        $data = $request->getParsedBody();

        $machineId = (int) ($args['id'] ?? $data['machine_id'] ?? 0);

        // Verify machine belongs to customer
        $machine = $this->db->fetch(
            "SELECT id FROM machines WHERE id = ? AND customer_id = ?",
            [$machineId, $customerId]
        );

        if (!$machine) {
            $_SESSION['flash_error'] = 'Invalid machine selected.';
            return $response->withHeader('Location', '/portal/machines')->withStatus(302);
        }

        // Get default open status
        $defaultStatus = $this->db->fetch(
            "SELECT id FROM job_statuses WHERE is_default = 1 LIMIT 1"
        );
        $statusId = $defaultStatus ? (int) $defaultStatus['id'] : null;

        $jobId = $this->db->insert('maintenance_jobs', [
            'machine_id' => $machineId,
            'status_id' => $statusId,
            'job_type' => 'repair',
            'priority' => $data['priority'] ?? 'medium',
            'title' => trim($data['title'] ?? 'Issue reported by customer'),
            'description' => trim($data['description'] ?? ''),
            'reported_by_customer' => $portalUser['id'],
            'is_customer_visible' => 1,
            'labour_minutes' => 0,
            'labour_rate' => 0,
            'labour_cost' => 0,
            'parts_cost' => 0,
            'total_cost' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // Auto-generate job_number
        $jobNumber = 'JOB-' . str_pad((string) $jobId, 6, '0', STR_PAD_LEFT);
        $this->db->update('maintenance_jobs', ['job_number' => $jobNumber], 'id = ?', [$jobId]);

        $_SESSION['flash_success'] = 'Issue reported successfully. Job ' . $jobNumber . ' has been created.';
        return $response->withHeader('Location', '/portal/machines/' . $machineId)->withStatus(302);
    }

    /**
     * Revenue for customer's machines.
     */
    public function revenue(Request $request, Response $response, array $args = []): Response
    {
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $portalUser = $this->auth->portalUser();
        $customerId = $portalUser['customer_id'];

        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = 25;
        $offset = ($page - 1) * $perPage;
        $dateFrom = $params['date_from'] ?? '';
        $dateTo = $params['date_to'] ?? '';

        $machineIds = array_column(
            $this->db->fetchAll("SELECT id FROM machines WHERE customer_id = ?", [$customerId]),
            'id'
        );

        $revenue = [];
        $totalCount = 0;
        $totalPages = 1;

        if (!empty($machineIds)) {
            $placeholders = implode(',', array_fill(0, count($machineIds), '?'));
            $where = "r.machine_id IN ({$placeholders})";
            $bindings = $machineIds;

            if ($dateFrom !== '') {
                $where .= ' AND r.collection_date >= ?';
                $bindings[] = $dateFrom;
            }
            if ($dateTo !== '') {
                $where .= ' AND r.collection_date <= ?';
                $bindings[] = $dateTo;
            }

            $totalCount = (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM revenue r WHERE {$where}",
                $bindings
            );

            $totalPages = max(1, (int) ceil($totalCount / $perPage));

            $revenue = $this->db->fetchAll(
                "SELECT r.*, m.name AS machine_name, m.machine_code
                 FROM revenue r
                 LEFT JOIN machines m ON r.machine_id = m.id
                 WHERE {$where}
                 ORDER BY r.collection_date DESC
                 LIMIT {$perPage} OFFSET {$offset}",
                $bindings
            );
        }

        return $this->twig->render($response, 'portal/revenue/index.twig', [
            'active_page' => 'revenue',
            'portal_user' => $portalUser,
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'revenue' => $revenue,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
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
     * Commission payments for this customer.
     */
    public function commissions(Request $request, Response $response, array $args = []): Response
    {
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $portalUser = $this->auth->portalUser();
        $customerId = $portalUser['customer_id'];

        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $totalCount = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM commission_payments WHERE customer_id = ?",
            [$customerId]
        );

        $totalPages = max(1, (int) ceil($totalCount / $perPage));

        $commissions = $this->db->fetchAll(
            "SELECT * FROM commission_payments
             WHERE customer_id = ?
             ORDER BY period_end DESC
             LIMIT {$perPage} OFFSET {$offset}",
            [$customerId]
        );

        return $this->twig->render($response, 'portal/commissions/index.twig', [
            'active_page' => 'commissions',
            'portal_user' => $portalUser,
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'commissions' => $commissions,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'total_count' => $totalCount,
                'per_page' => $perPage,
            ],
        ]);
    }

    /**
     * Commission detail view.
     */
    public function commissionDetail(Request $request, Response $response, array $args = []): Response
    {
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $portalUser = $this->auth->portalUser();
        $customerId = $portalUser['customer_id'];
        $commissionId = (int) $args['id'];

        $commission = $this->db->fetch(
            "SELECT * FROM commission_payments WHERE id = ? AND customer_id = ?",
            [$commissionId, $customerId]
        );

        if (!$commission) {
            $_SESSION['flash_error'] = 'Commission payment not found.';
            return $response->withHeader('Location', '/portal/commissions')->withStatus(302);
        }

        // Per-machine breakdown
        $machineBreakdown = $this->db->fetchAll(
            "SELECT m.id, m.name, m.machine_code, m.commission_rate,
                    COALESCE(SUM(r.cash_amount), 0) AS cash_total,
                    COALESCE(SUM(r.card_amount), 0) AS card_total,
                    COALESCE(SUM(r.prepaid_amount), 0) AS prepaid_total,
                    COALESCE(SUM(r.cash_amount + r.card_amount), 0) AS gross_revenue,
                    SUM(r.card_transactions) AS card_transactions
             FROM machines m
             JOIN revenue r ON m.id = r.machine_id
             WHERE m.customer_id = ?
               AND r.status = 'approved'
               AND r.collection_date BETWEEN ? AND ?
             GROUP BY m.id, m.name, m.machine_code, m.commission_rate
             ORDER BY gross_revenue DESC",
            [$customerId, $commission['period_start'], $commission['period_end']]
        );

        $defaultRate = (float) ($commission['commission_rate'] ?? 0);
        $processingFeeRate = (float) ($commission['processing_fee_rate'] ?? 0);
        foreach ($machineBreakdown as &$machine) {
            $rate = $machine['commission_rate'] !== null ? (float) $machine['commission_rate'] : $defaultRate;
            $gross = (float) $machine['gross_revenue'];
            $fees = (int) $machine['card_transactions'] * $processingFeeRate;
            $net = $gross - $fees;
            $machine['effective_rate'] = $rate;
            $machine['processing_fees'] = round($fees, 2);
            $machine['net_revenue'] = round($net, 2);
            $machine['commission'] = round($net * $rate / 100, 2);
        }
        unset($machine);

        return $this->twig->render($response, 'portal/commissions/show.twig', [
            'active_page' => 'commissions',
            'portal_user' => $portalUser,
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'commission' => $commission,
            'machine_breakdown' => $machineBreakdown,
        ]);
    }

    /**
     * Jobs on customer's machines (where is_customer_visible=1).
     */
    public function jobs(Request $request, Response $response, array $args = []): Response
    {
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $portalUser = $this->auth->portalUser();
        $customerId = $portalUser['customer_id'];

        $machineIds = array_column(
            $this->db->fetchAll("SELECT id FROM machines WHERE customer_id = ?", [$customerId]),
            'id'
        );

        $jobs = [];
        if (!empty($machineIds)) {
            $placeholders = implode(',', array_fill(0, count($machineIds), '?'));
            $jobs = $this->db->fetchAll(
                "SELECT j.*, js.name AS status_name, js.color AS status_color,
                        m.name AS machine_name, m.machine_code
                 FROM maintenance_jobs j
                 LEFT JOIN job_statuses js ON j.status_id = js.id
                 LEFT JOIN machines m ON j.machine_id = m.id
                 WHERE j.machine_id IN ({$placeholders})
                   AND j.is_customer_visible = 1
                 ORDER BY j.created_at DESC",
                $machineIds
            );
        }

        // Get machines list for the report issue form
        $machines = $this->db->fetchAll(
            "SELECT id, name, machine_code FROM machines WHERE customer_id = ? ORDER BY name ASC",
            [$customerId]
        );

        return $this->twig->render($response, 'portal/jobs/index.twig', [
            'active_page' => 'jobs',
            'portal_user' => $portalUser,
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'jobs' => $jobs,
            'machines' => $machines,
        ]);
    }

    /**
     * Job detail (verify belongs to customer's machines and is visible).
     */
    public function jobDetail(Request $request, Response $response, array $args = []): Response
    {
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $portalUser = $this->auth->portalUser();
        $customerId = $portalUser['customer_id'];
        $jobId = (int) $args['id'];

        $job = $this->db->fetch(
            "SELECT j.*, js.name AS status_name, js.color AS status_color,
                    m.name AS machine_name, m.machine_code, m.location_details
             FROM maintenance_jobs j
             LEFT JOIN job_statuses js ON j.status_id = js.id
             LEFT JOIN machines m ON j.machine_id = m.id
             WHERE j.id = ? AND m.customer_id = ? AND j.is_customer_visible = 1",
            [$jobId, $customerId]
        );

        if (!$job) {
            $_SESSION['flash_error'] = 'Job not found.';
            return $response->withHeader('Location', '/portal/jobs')->withStatus(302);
        }

        // Job photos (visible to customer)
        $photos = $this->db->fetchAll(
            "SELECT * FROM job_photos WHERE job_id = ? ORDER BY created_at DESC",
            [$jobId]
        );

        // Job notes (visible to customer)
        $notes = $this->db->fetchAll(
            "SELECT jn.*, COALESCE(u.full_name, cpu.name, 'System') AS author_name
             FROM job_notes jn
             LEFT JOIN users u ON jn.user_id = u.id
             LEFT JOIN customer_portal_users cpu ON jn.portal_user_id = cpu.id
             WHERE jn.job_id = ? AND jn.is_internal = 0
             ORDER BY jn.created_at ASC",
            [$jobId]
        );

        return $this->twig->render($response, 'portal/jobs/show.twig', [
            'active_page' => 'jobs',
            'portal_user' => $portalUser,
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'job' => $job,
            'photos' => $photos,
            'notes' => $notes,
        ]);
    }

    /**
     * Add a note to a job (portal user).
     */
    public function addJobNote(Request $request, Response $response, array $args = []): Response
    {
        $portalUser = $this->auth->portalUser();
        $customerId = $portalUser['customer_id'];
        $jobId = (int) $args['id'];
        $data = $request->getParsedBody();

        // Verify job belongs to customer's machine
        $job = $this->db->fetch(
            "SELECT j.id FROM maintenance_jobs j
             JOIN machines m ON j.machine_id = m.id
             WHERE j.id = ? AND m.customer_id = ? AND j.is_customer_visible = 1",
            [$jobId, $customerId]
        );

        if (!$job) {
            $_SESSION['flash_error'] = 'Job not found.';
            return $response->withHeader('Location', '/portal/jobs')->withStatus(302);
        }

        $note = trim((string) ($data['note'] ?? ''));
        if ($note === '') {
            $_SESSION['flash_error'] = 'Note cannot be empty.';
            return $response->withHeader('Location', "/portal/jobs/{$jobId}")->withStatus(302);
        }

        $this->db->insert('job_notes', [
            'job_id' => $jobId,
            'portal_user_id' => $portalUser['id'],
            'note' => $note,
            'is_internal' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $_SESSION['flash_success'] = 'Note added.';
        return $response->withHeader('Location', "/portal/jobs/{$jobId}")->withStatus(302);
    }

    /**
     * Portal user settings.
     */
    public function settings(Request $request, Response $response, array $args = []): Response
    {
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $portalUser = $this->auth->portalUser();

        $user = $this->db->fetch(
            "SELECT cpu.*, c.business_name, c.bank_name, c.bank_account_name,
                    c.bank_bsb, c.bank_account_number
             FROM customer_portal_users cpu
             JOIN customers c ON cpu.customer_id = c.id
             WHERE cpu.id = ?",
            [$portalUser['id']]
        );

        return $this->twig->render($response, 'portal/settings.twig', [
            'active_page' => 'settings',
            'portal_user' => $portalUser,
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'profile' => $user,
        ]);
    }

    /**
     * Update portal user profile.
     */
    public function updateProfile(Request $request, Response $response, array $args = []): Response
    {
        $portalUser = $this->auth->portalUser();
        $data = $request->getParsedBody();

        $this->db->update('customer_portal_users', [
            'name' => trim($data['name'] ?? ''),
            'email' => trim($data['email'] ?? ''),
            'phone' => trim($data['phone'] ?? ''),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$portalUser['id']]);

        // Update session data
        $_SESSION['portal_user']['name'] = trim($data['name'] ?? '');
        $_SESSION['portal_user']['email'] = trim($data['email'] ?? '');

        $_SESSION['flash_success'] = 'Profile updated successfully.';
        return $response->withHeader('Location', '/portal/settings')->withStatus(302);
    }

    /**
     * Change portal password.
     */
    public function updatePassword(Request $request, Response $response, array $args = []): Response
    {
        $portalUser = $this->auth->portalUser();
        $data = $request->getParsedBody();

        $currentPassword = $data['current_password'] ?? '';
        $newPassword = $data['new_password'] ?? '';
        $confirmPassword = $data['confirm_password'] ?? '';

        if ($currentPassword === '' || $newPassword === '') {
            $_SESSION['flash_error'] = 'All password fields are required.';
            return $response->withHeader('Location', '/portal/settings')->withStatus(302);
        }

        if ($newPassword !== $confirmPassword) {
            $_SESSION['flash_error'] = 'New password and confirmation do not match.';
            return $response->withHeader('Location', '/portal/settings')->withStatus(302);
        }

        // Verify current password
        $user = $this->db->fetch(
            "SELECT password FROM customer_portal_users WHERE id = ?",
            [$portalUser['id']]
        );

        if (!$user || !password_verify($currentPassword, $user['password'])) {
            $_SESSION['flash_error'] = 'Current password is incorrect.';
            return $response->withHeader('Location', '/portal/settings')->withStatus(302);
        }

        $this->db->update('customer_portal_users', [
            'password' => AuthService::hashPassword($newPassword),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$portalUser['id']]);

        $_SESSION['flash_success'] = 'Password changed successfully.';
        return $response->withHeader('Location', '/portal/settings')->withStatus(302);
    }

    /**
     * Update customer bank details.
     */
    public function updateBank(Request $request, Response $response, array $args = []): Response
    {
        $portalUser = $this->auth->portalUser();
        $customerId = $portalUser['customer_id'];
        $data = $request->getParsedBody();

        $this->db->update('customers', [
            'bank_name' => trim($data['bank_name'] ?? ''),
            'bank_account_name' => trim($data['bank_account_name'] ?? ''),
            'bank_bsb' => trim($data['bank_bsb'] ?? ''),
            'bank_account_number' => trim($data['bank_account_number'] ?? ''),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$customerId]);

        $_SESSION['flash_success'] = 'Bank details updated successfully.';
        return $response->withHeader('Location', '/portal/settings')->withStatus(302);
    }
}
