<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\Database;
use App\Services\AuthService;

class DashboardController
{
    public function __construct(
        private Twig $twig,
        private Database $db,
        private AuthService $auth
    ) {}

    /**
     * Dashboard with summary stats, recent revenue, and chart data.
     */
    public function index(Request $request, Response $response): Response
    {
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        // Current month boundaries
        $monthStart = date('Y-m-01');
        $monthEnd = date('Y-m-t');

        // Last month boundaries
        $lastMonthStart = date('Y-m-01', strtotime('first day of last month'));
        $lastMonthEnd = date('Y-m-t', strtotime('last day of last month'));

        // Total active machines
        $totalMachines = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM machines WHERE status = 'active'"
        );

        // Total active customers
        $totalCustomers = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM customers WHERE is_active = 1"
        );

        // This month revenue (cash + card only, prepaid tracked separately)
        $thisMonthRevenue = (float) $this->db->fetchColumn(
            "SELECT COALESCE(SUM(cash_amount + card_amount), 0)
             FROM revenue
             WHERE collection_date BETWEEN ? AND ?",
            [$monthStart, $monthEnd]
        );

        // Last month revenue for comparison
        $lastMonthRevenue = (float) $this->db->fetchColumn(
            "SELECT COALESCE(SUM(cash_amount + card_amount), 0)
             FROM revenue
             WHERE collection_date BETWEEN ? AND ?",
            [$lastMonthStart, $lastMonthEnd]
        );

        // Open jobs count
        $openJobs = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM maintenance_jobs j
             JOIN job_statuses js ON j.status_id = js.id
             WHERE js.slug IN ('open', 'in_progress')"
        );

        // Recent revenue entries (last 5)
        $recentRevenue = $this->db->fetchAll(
            "SELECT r.*, m.name AS machine_name, m.machine_code,
                    c.name AS customer_name
             FROM revenue r
             LEFT JOIN machines m ON r.machine_id = m.id
             LEFT JOIN customers c ON m.customer_id = c.id
             ORDER BY r.collection_date DESC, r.created_at DESC
             LIMIT 5"
        );

        // Revenue change percentage
        $revenueChange = $lastMonthRevenue > 0
            ? round((($thisMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100, 1)
            : 0;

        // Top machines by revenue (last 30 days)
        $thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
        $topMachines = $this->db->fetchAll(
            "SELECT m.id, m.name, m.machine_code, c.name AS customer_name,
                    COALESCE(SUM(r.cash_amount + r.card_amount), 0) AS total_revenue
             FROM revenue r
             JOIN machines m ON r.machine_id = m.id
             LEFT JOIN customers c ON m.customer_id = c.id
             WHERE r.collection_date >= ?
             GROUP BY m.id, m.name, m.machine_code, c.name
             ORDER BY total_revenue DESC
             LIMIT 5",
            [$thirtyDaysAgo]
        );

        // Recent jobs
        $recentJobs = $this->db->fetchAll(
            "SELECT j.*, m.name AS machine_name, js.name AS status_name, js.color AS status_color
             FROM maintenance_jobs j
             LEFT JOIN machines m ON j.machine_id = m.id
             LEFT JOIN job_statuses js ON j.status_id = js.id
             ORDER BY j.created_at DESC
             LIMIT 5"
        );

        // Revenue chart data - last 6 months grouped by month (cash vs card)
        $sixMonthsAgo = date('Y-m-01', strtotime('-5 months'));
        $chartRows = $this->db->fetchAll(
            "SELECT DATE_FORMAT(collection_date, '%Y-%m') AS month,
                    COALESCE(SUM(cash_amount), 0) AS cash,
                    COALESCE(SUM(card_amount), 0) AS card
             FROM revenue
             WHERE collection_date >= ?
             GROUP BY DATE_FORMAT(collection_date, '%Y-%m')
             ORDER BY month ASC",
            [$sixMonthsAgo]
        );

        $revenueChart = ['labels' => [], 'cash' => [], 'card' => []];
        foreach ($chartRows as $row) {
            $revenueChart['labels'][] = date('M Y', strtotime($row['month'] . '-01'));
            $revenueChart['cash'][] = (float) $row['cash'];
            $revenueChart['card'][] = (float) $row['card'];
        }

        return $this->twig->render($response, 'admin/dashboard/index.twig', [
            'active_page' => 'dashboard',
            'auth_user' => $this->auth->user(),
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'stats' => [
                'total_machines' => $totalMachines,
                'total_customers' => $totalCustomers,
                'month_revenue' => $thisMonthRevenue,
                'revenue_change' => $revenueChange,
                'open_jobs' => $openJobs,
            ],
            'top_machines' => $topMachines,
            'recent_revenue' => $recentRevenue,
            'recent_jobs' => $recentJobs,
            'revenue_chart' => $revenueChart,
        ]);
    }
}
