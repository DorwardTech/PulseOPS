<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\Database;
use App\Services\AuthService;

class AnalyticsController
{
    public function __construct(
        private Twig $twig,
        private Database $db,
        private AuthService $auth
    ) {}

    /**
     * Analytics overview with revenue trends, top machines, top customers.
     */
    public function index(Request $request, Response $response, array $args = []): Response
    {
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        // Revenue trends - last 12 months
        $twelveMonthsAgo = date('Y-m-01', strtotime('-11 months'));
        $revenueTrends = $this->db->fetchAll(
            "SELECT DATE_FORMAT(collection_date, '%Y-%m') AS month,
                    COALESCE(SUM(cash_amount + card_amount), 0) AS total_revenue,
                    COALESCE(SUM(cash_amount), 0) AS cash_total,
                    COALESCE(SUM(card_amount), 0) AS card_total,
                    COUNT(*) AS collection_count
             FROM revenue
             WHERE collection_date >= ?
             GROUP BY DATE_FORMAT(collection_date, '%Y-%m')
             ORDER BY month ASC",
            [$twelveMonthsAgo]
        );

        // Top 10 machines by revenue (last 12 months)
        $topMachines = $this->db->fetchAll(
            "SELECT m.id, m.name, m.machine_code,
                    COALESCE(SUM(r.cash_amount + r.card_amount), 0) AS total_revenue,
                    COUNT(r.id) AS collection_count
             FROM machines m
             LEFT JOIN revenue r ON m.id = r.machine_id AND r.collection_date >= ?
             GROUP BY m.id, m.name, m.machine_code
             HAVING total_revenue > 0
             ORDER BY total_revenue DESC
             LIMIT 10",
            [$twelveMonthsAgo]
        );

        // Top 10 customers by revenue (last 12 months)
        $topCustomers = $this->db->fetchAll(
            "SELECT c.id, c.name, c.business_name,
                    COALESCE(SUM(r.cash_amount + r.card_amount), 0) AS total_revenue,
                    COUNT(DISTINCT m.id) AS machine_count
             FROM customers c
             JOIN machines m ON c.id = m.customer_id
             LEFT JOIN revenue r ON m.id = r.machine_id AND r.collection_date >= ?
             GROUP BY c.id, c.name, c.business_name
             HAVING total_revenue > 0
             ORDER BY total_revenue DESC
             LIMIT 10",
            [$twelveMonthsAgo]
        );

        // Summary totals
        $thisMonthStart = date('Y-m-01');
        $thisMonthEnd = date('Y-m-t');
        $lastMonthStart = date('Y-m-01', strtotime('first day of last month'));
        $lastMonthEnd = date('Y-m-t', strtotime('last day of last month'));

        $thisMonthRevenue = (float) $this->db->fetchColumn(
            "SELECT COALESCE(SUM(cash_amount + card_amount), 0) FROM revenue
             WHERE collection_date BETWEEN ? AND ?",
            [$thisMonthStart, $thisMonthEnd]
        );

        $lastMonthRevenue = (float) $this->db->fetchColumn(
            "SELECT COALESCE(SUM(cash_amount + card_amount), 0) FROM revenue
             WHERE collection_date BETWEEN ? AND ?",
            [$lastMonthStart, $lastMonthEnd]
        );

        $yearToDateRevenue = (float) $this->db->fetchColumn(
            "SELECT COALESCE(SUM(cash_amount + card_amount), 0) FROM revenue
             WHERE collection_date >= ?",
            [date('Y-01-01')]
        );

        return $this->twig->render($response, 'admin/analytics/index.twig', [
            'active_page' => 'analytics',
            'auth_user' => $this->auth->user(),
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'revenue_trends' => $revenueTrends,
            'top_machines' => $topMachines,
            'top_customers' => $topCustomers,
            'this_month_revenue' => $thisMonthRevenue,
            'last_month_revenue' => $lastMonthRevenue,
            'year_to_date_revenue' => $yearToDateRevenue,
        ]);
    }

    /**
     * Detailed revenue analytics with grouping and date range filter.
     */
    public function revenue(Request $request, Response $response, array $args = []): Response
    {
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $params = $request->getQueryParams();
        $dateFrom = $params['date_from'] ?? date('Y-m-01', strtotime('-11 months'));
        $dateTo = $params['date_to'] ?? date('Y-m-t');
        $groupBy = $params['group_by'] ?? 'month';

        $where = 'r.collection_date BETWEEN ? AND ?';
        $bindings = [$dateFrom, $dateTo];

        // Revenue grouped by month
        $byMonth = $this->db->fetchAll(
            "SELECT DATE_FORMAT(r.collection_date, '%Y-%m') AS period,
                    COALESCE(SUM(r.cash_amount + r.card_amount), 0) AS total_revenue,
                    COALESCE(SUM(r.cash_amount), 0) AS cash_total,
                    COALESCE(SUM(r.card_amount), 0) AS card_total,
                    COUNT(*) AS collection_count
             FROM revenue r
             WHERE {$where}
             GROUP BY DATE_FORMAT(r.collection_date, '%Y-%m')
             ORDER BY period ASC",
            $bindings
        );

        // Revenue grouped by machine
        $byMachine = $this->db->fetchAll(
            "SELECT m.id, m.name, m.machine_code,
                    COALESCE(SUM(r.cash_amount + r.card_amount), 0) AS total_revenue,
                    COALESCE(SUM(r.cash_amount), 0) AS cash_total,
                    COALESCE(SUM(r.card_amount), 0) AS card_total,
                    COUNT(r.id) AS collection_count
             FROM revenue r
             LEFT JOIN machines m ON r.machine_id = m.id
             WHERE {$where}
             GROUP BY m.id, m.name, m.machine_code
             ORDER BY total_revenue DESC",
            $bindings
        );

        // Revenue grouped by customer
        $byCustomer = $this->db->fetchAll(
            "SELECT c.id, c.name, c.business_name,
                    COALESCE(SUM(r.cash_amount + r.card_amount), 0) AS total_revenue,
                    COALESCE(SUM(r.cash_amount), 0) AS cash_total,
                    COALESCE(SUM(r.card_amount), 0) AS card_total,
                    COUNT(r.id) AS collection_count
             FROM revenue r
             LEFT JOIN machines m ON r.machine_id = m.id
             LEFT JOIN customers c ON m.customer_id = c.id
             WHERE {$where}
             GROUP BY c.id, c.name, c.business_name
             ORDER BY total_revenue DESC",
            $bindings
        );

        // Overall totals for the period
        $totals = $this->db->fetch(
            "SELECT COALESCE(SUM(cash_amount + card_amount), 0) AS total_revenue,
                    COALESCE(SUM(cash_amount), 0) AS cash_total,
                    COALESCE(SUM(card_amount), 0) AS card_total,
                    COUNT(*) AS collection_count
             FROM revenue r
             WHERE {$where}",
            $bindings
        );

        return $this->twig->render($response, 'admin/analytics/revenue.twig', [
            'active_page' => 'analytics',
            'auth_user' => $this->auth->user(),
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'by_month' => $byMonth,
            'by_machine' => $byMachine,
            'by_customer' => $byCustomer,
            'totals' => $totals,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'group_by' => $groupBy,
            ],
        ]);
    }

    /**
     * Machine performance analytics.
     */
    public function machines(Request $request, Response $response, array $args = []): Response
    {
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $params = $request->getQueryParams();
        $dateFrom = $params['date_from'] ?? date('Y-m-01', strtotime('-11 months'));
        $dateTo = $params['date_to'] ?? date('Y-m-t');

        // Revenue per machine
        $machineRevenue = $this->db->fetchAll(
            "SELECT m.id, m.name, m.machine_code, m.status, m.location_details,
                    c.name AS customer_name,
                    COALESCE(SUM(r.cash_amount + r.card_amount), 0) AS total_revenue,
                    COALESCE(SUM(r.cash_amount), 0) AS cash_total,
                    COALESCE(SUM(r.card_amount), 0) AS card_total,
                    COUNT(r.id) AS collection_count,
                    MAX(r.collection_date) AS last_collection
             FROM machines m
             LEFT JOIN customers c ON m.customer_id = c.id
             LEFT JOIN revenue r ON m.id = r.machine_id
                 AND r.collection_date BETWEEN ? AND ?
             GROUP BY m.id, m.name, m.machine_code, m.status, m.location_details, c.name
             ORDER BY total_revenue DESC",
            [$dateFrom, $dateTo]
        );

        // Status distribution
        $statusDistribution = $this->db->fetchAll(
            "SELECT status, COUNT(*) AS count
             FROM machines
             GROUP BY status
             ORDER BY count DESC"
        );

        // Collection frequency (average days between collections per machine)
        $collectionFrequency = $this->db->fetchAll(
            "SELECT m.id, m.name, m.machine_code,
                    COUNT(r.id) AS total_collections,
                    MIN(r.collection_date) AS first_collection,
                    MAX(r.collection_date) AS last_collection,
                    DATEDIFF(MAX(r.collection_date), MIN(r.collection_date)) /
                        GREATEST(COUNT(r.id) - 1, 1) AS avg_days_between
             FROM machines m
             JOIN revenue r ON m.id = r.machine_id
                 AND r.collection_date BETWEEN ? AND ?
             GROUP BY m.id, m.name, m.machine_code
             HAVING total_collections > 1
             ORDER BY avg_days_between ASC
             LIMIT 20",
            [$dateFrom, $dateTo]
        );

        return $this->twig->render($response, 'admin/analytics/machines.twig', [
            'active_page' => 'analytics',
            'auth_user' => $this->auth->user(),
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'machine_revenue' => $machineRevenue,
            'status_distribution' => $statusDistribution,
            'collection_frequency' => $collectionFrequency,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
        ]);
    }

    /**
     * Customer analytics.
     */
    public function customers(Request $request, Response $response, array $args = []): Response
    {
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $params = $request->getQueryParams();
        $dateFrom = $params['date_from'] ?? date('Y-m-01', strtotime('-11 months'));
        $dateTo = $params['date_to'] ?? date('Y-m-t');

        // Revenue by customer
        $customerRevenue = $this->db->fetchAll(
            "SELECT c.id, c.name, c.business_name, c.commission_rate,
                    COUNT(DISTINCT m.id) AS machine_count,
                    COALESCE(SUM(r.cash_amount + r.card_amount), 0) AS total_revenue,
                    COALESCE(SUM(r.cash_amount), 0) AS cash_total,
                    COALESCE(SUM(r.card_amount), 0) AS card_total,
                    COUNT(r.id) AS collection_count
             FROM customers c
             LEFT JOIN machines m ON c.id = m.customer_id
             LEFT JOIN revenue r ON m.id = r.machine_id
                 AND r.collection_date BETWEEN ? AND ?
             WHERE c.is_active = 1
             GROUP BY c.id, c.name, c.business_name, c.commission_rate
             ORDER BY total_revenue DESC",
            [$dateFrom, $dateTo]
        );

        // Commission totals by customer
        $commissionTotals = $this->db->fetchAll(
            "SELECT c.id, c.name, c.business_name,
                    COALESCE(SUM(cp.commission_amount), 0) AS total_commission,
                    COUNT(cp.id) AS payment_count
             FROM customers c
             LEFT JOIN commission_payments cp ON c.id = cp.customer_id
                 AND cp.period_start >= ? AND cp.period_end <= ?
             WHERE c.is_active = 1
             GROUP BY c.id, c.name, c.business_name
             HAVING total_commission > 0
             ORDER BY total_commission DESC",
            [$dateFrom, $dateTo]
        );

        // Machine counts per customer
        $machineCounts = $this->db->fetchAll(
            "SELECT c.id, c.name, c.business_name,
                    SUM(CASE WHEN m.status = 'active' THEN 1 ELSE 0 END) AS active_machines,
                    SUM(CASE WHEN m.status = 'inactive' THEN 1 ELSE 0 END) AS inactive_machines,
                    COUNT(m.id) AS total_machines
             FROM customers c
             LEFT JOIN machines m ON c.id = m.customer_id
             WHERE c.is_active = 1
             GROUP BY c.id, c.name, c.business_name
             ORDER BY total_machines DESC"
        );

        return $this->twig->render($response, 'admin/analytics/customers.twig', [
            'active_page' => 'analytics',
            'auth_user' => $this->auth->user(),
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'customer_revenue' => $customerRevenue,
            'commission_totals' => $commissionTotals,
            'machine_counts' => $machineCounts,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
        ]);
    }

    /**
     * Export analytics data as CSV.
     */
    public function export(Request $request, Response $response, array $args = []): Response
    {
        $params = $request->getQueryParams();
        $type = $params['type'] ?? 'revenue';
        $dateFrom = $params['date_from'] ?? date('Y-m-01', strtotime('-11 months'));
        $dateTo = $params['date_to'] ?? date('Y-m-t');

        $filename = "analytics_{$type}_" . date('Y-m-d') . '.csv';
        $rows = [];
        $headers = [];

        switch ($type) {
            case 'revenue':
                $headers = ['Month', 'Total Revenue', 'Cash', 'Card', 'Collections'];
                $rows = $this->db->fetchAll(
                    "SELECT DATE_FORMAT(collection_date, '%Y-%m') AS month,
                            COALESCE(SUM(cash_amount + card_amount), 0) AS total_revenue,
                            COALESCE(SUM(cash_amount), 0) AS cash_total,
                            COALESCE(SUM(card_amount), 0) AS card_total,
                            COUNT(*) AS collection_count
                     FROM revenue
                     WHERE collection_date BETWEEN ? AND ?
                     GROUP BY DATE_FORMAT(collection_date, '%Y-%m')
                     ORDER BY month ASC",
                    [$dateFrom, $dateTo]
                );
                break;

            case 'machines':
                $headers = ['Machine', 'Code', 'Status', 'Customer', 'Total Revenue', 'Cash', 'Card', 'Collections'];
                $rows = $this->db->fetchAll(
                    "SELECT m.name, m.machine_code, m.status,
                            c.name AS customer_name,
                            COALESCE(SUM(r.cash_amount + r.card_amount), 0) AS total_revenue,
                            COALESCE(SUM(r.cash_amount), 0) AS cash_total,
                            COALESCE(SUM(r.card_amount), 0) AS card_total,
                            COUNT(r.id) AS collection_count
                     FROM machines m
                     LEFT JOIN customers c ON m.customer_id = c.id
                     LEFT JOIN revenue r ON m.id = r.machine_id
                         AND r.collection_date BETWEEN ? AND ?
                     GROUP BY m.id, m.name, m.machine_code, m.status, c.name
                     ORDER BY total_revenue DESC",
                    [$dateFrom, $dateTo]
                );
                break;

            case 'customers':
                $headers = ['Customer', 'Business Name', 'Machines', 'Total Revenue', 'Cash', 'Card', 'Collections'];
                $rows = $this->db->fetchAll(
                    "SELECT c.name, c.business_name,
                            COUNT(DISTINCT m.id) AS machine_count,
                            COALESCE(SUM(r.cash_amount + r.card_amount), 0) AS total_revenue,
                            COALESCE(SUM(r.cash_amount), 0) AS cash_total,
                            COALESCE(SUM(r.card_amount), 0) AS card_total,
                            COUNT(r.id) AS collection_count
                     FROM customers c
                     LEFT JOIN machines m ON c.id = m.customer_id
                     LEFT JOIN revenue r ON m.id = r.machine_id
                         AND r.collection_date BETWEEN ? AND ?
                     WHERE c.is_active = 1
                     GROUP BY c.id, c.name, c.business_name
                     ORDER BY total_revenue DESC",
                    [$dateFrom, $dateTo]
                );
                break;
        }

        // Build CSV content
        $output = fopen('php://temp', 'r+');
        fputcsv($output, $headers);
        foreach ($rows as $row) {
            fputcsv($output, array_values($row));
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        $response->getBody()->write($csv);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withStatus(200);
    }
}
