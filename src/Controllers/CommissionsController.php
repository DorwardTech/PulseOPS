<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\Database;
use App\Services\AuthService;
use App\Services\SettingsService;
use App\Services\CommissionService;
use App\Helpers\CommissionCalculator;

class CommissionsController
{
    public function __construct(
        private Twig $twig,
        private Database $db,
        private AuthService $auth,
        private SettingsService $settings,
        private CommissionService $commissionService
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
            'active_page' => 'commissions',
            'auth_user' => $this->auth->user(),
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_success' => $flash_success,
            'flash_error' => $flash_error,
        ], $extra);
    }

    /**
     * List commission payments with filters and pagination.
     */
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = max(1, (int) ($params['per_page'] ?? 25));
        $offset = ($page - 1) * $perPage;

        $customerId = $params['customer_id'] ?? null;
        $status = $params['status'] ?? null;
        $periodStart = $params['period_start'] ?? null;
        $periodEnd = $params['period_end'] ?? null;

        $where = ['1=1'];
        $bindings = [];

        if ($customerId) {
            $where[] = 'cp.customer_id = ?';
            $bindings[] = (int) $customerId;
        }
        if ($status) {
            $where[] = 'cp.status = ?';
            $bindings[] = $status;
        }
        if ($periodStart) {
            $where[] = 'cp.period_start >= ?';
            $bindings[] = $periodStart;
        }
        if ($periodEnd) {
            $where[] = 'cp.period_end <= ?';
            $bindings[] = $periodEnd;
        }

        $whereClause = implode(' AND ', $where);

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM commission_payments cp WHERE {$whereClause}",
            $bindings
        );

        $commissions = $this->db->fetchAll(
            "SELECT cp.*, c.name AS customer_name
             FROM commission_payments cp
             LEFT JOIN customers c ON cp.customer_id = c.id
             WHERE {$whereClause}
             ORDER BY cp.period_end DESC, cp.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $bindings
        );

        $customers = $this->db->fetchAll(
            "SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name"
        );

        return $this->twig->render($response, 'admin/commissions/index.twig', $this->viewData([
            'commissions' => $commissions,
            'customers' => $customers,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
            'filters' => [
                'customer_id' => $customerId,
                'status' => $status,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ],
        ]));
    }

    /**
     * Show commission generation form.
     */
    public function showGenerate(Request $request, Response $response): Response
    {
        $customers = $this->db->fetchAll(
            "SELECT id, name, commission_rate, processing_fee, carry_forward
             FROM customers
             WHERE is_active = 1
             ORDER BY name"
        );

        return $this->twig->render($response, 'admin/commissions/generate.twig', $this->viewData([
            'customers' => $customers,
            'default_commission_rate' => $this->settings->get('default_commission_rate', 0),
            'default_processing_fee' => $this->settings->get('default_processing_fee', 0),
        ]));
    }

    /**
     * Generate commission for a customer/period using the new formula.
     *
     * Steps:
     * 1. Get all approved revenue for customer's machines in period
     * 2. Get all job parts/labour costs in period
     * 3. Get carry_forward from customer
     * 4. Calculate using CommissionCalculator::calculate()
     * 5. Store in commission_payments
     * 6. Update customer carry_forward if needed
     */
    public function processGenerate(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $customerId = (int) ($data['customer_id'] ?? 0);
        $periodStart = $data['period_start'] ?? '';
        $periodEnd = $data['period_end'] ?? '';

        if ($customerId === 0 || $periodStart === '' || $periodEnd === '') {
            $_SESSION['flash_error'] = 'Customer and period dates are required.';
            return $response->withHeader('Location', '/commissions/generate')->withStatus(302);
        }

        try {
            $authUser = $this->auth->user();
            $gen = $this->commissionService->generateForCustomer(
                $customerId,
                $periodStart,
                $periodEnd,
                $authUser['id'] ?? null
            );

            $_SESSION['flash_success'] = 'Commission generated successfully.';
            return $response->withHeader('Location', "/commissions/{$gen['id']}")->withStatus(302);
        } catch (\RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $response->withHeader('Location', '/commissions/generate')->withStatus(302);
        }
    }

    /**
     * Show commission detail with full breakdown and line items.
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        $commission = $this->db->fetch(
            "SELECT cp.*, c.name AS customer_name,
                    u.full_name AS generated_by_name,
                    ua.full_name AS approved_by_name
             FROM commission_payments cp
             LEFT JOIN customers c ON cp.customer_id = c.id
             LEFT JOIN users u ON cp.generated_by = u.id
             LEFT JOIN users ua ON cp.approved_by = ua.id
             WHERE cp.id = ?",
            [$id]
        );

        if (!$commission) {
            $_SESSION['flash_error'] = 'Commission not found.';
            return $response->withHeader('Location', '/commissions')->withStatus(302);
        }

        $lineItems = $this->db->fetchAll(
            "SELECT cli.*
             FROM commission_line_items cli
             WHERE cli.commission_id = ?
             ORDER BY cli.type, cli.created_at",
            [$id]
        );

        // Per-machine breakdown: revenue, rate, and commission for each machine
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
            [$commission['customer_id'], $commission['period_start'], $commission['period_end']]
        );

        // Calculate per-machine commission amounts for display
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

        return $this->twig->render($response, 'admin/commissions/show.twig', $this->viewData([
            'commission' => $commission,
            'line_items' => $lineItems,
            'machine_breakdown' => $machineBreakdown,
        ]));
    }

    /**
     * Approve a commission.
     */
    public function approve(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $authUser = $this->auth->user();

        $commission = $this->db->fetch("SELECT id, status FROM commission_payments WHERE id = ?", [$id]);
        if (!$commission) {
            $_SESSION['flash_error'] = 'Commission not found.';
            return $response->withHeader('Location', '/commissions')->withStatus(302);
        }

        $this->db->update('commission_payments', [
            'status' => 'approved',
            'approved_by' => $authUser['id'] ?? null,
            'approved_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);

        $_SESSION['flash_success'] = 'Commission approved.';
        return $response->withHeader('Location', "/commissions/{$id}")->withStatus(302);
    }

    /**
     * Mark a commission as paid.
     */
    public function markPaid(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $data = $request->getParsedBody();

        $commission = $this->db->fetch("SELECT id, status FROM commission_payments WHERE id = ?", [$id]);
        if (!$commission) {
            $_SESSION['flash_error'] = 'Commission not found.';
            return $response->withHeader('Location', '/commissions')->withStatus(302);
        }

        $this->db->update('commission_payments', [
            'status' => 'paid',
            'payment_method' => trim((string) ($data['payment_method'] ?? '')),
            'payment_reference' => trim((string) ($data['payment_reference'] ?? '')),
            'paid_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);

        $_SESSION['flash_success'] = 'Commission marked as paid.';
        return $response->withHeader('Location', "/commissions/{$id}")->withStatus(302);
    }

    /**
     * Void a commission.
     */
    public function void(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        $commission = $this->db->fetch(
            "SELECT id, customer_id, carry_forward_in, carry_forward_out FROM commission_payments WHERE id = ?",
            [$id]
        );
        if (!$commission) {
            $_SESSION['flash_error'] = 'Commission not found.';
            return $response->withHeader('Location', '/commissions')->withStatus(302);
        }

        $this->db->update('commission_payments', [
            'status' => 'void',
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);

        // Revert carry_forward on the customer back to the value before this commission
        $this->db->update('customers', [
            'carry_forward' => $commission['carry_forward_in'],
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$commission['customer_id']]);

        $_SESSION['flash_success'] = 'Commission voided.';
        return $response->withHeader('Location', "/commissions/{$id}")->withStatus(302);
    }

    /**
     * Add a manual adjustment line item to a commission.
     */
    public function addLineItem(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $data = $request->getParsedBody();

        $commission = $this->db->fetch("SELECT id FROM commission_payments WHERE id = ?", [$id]);
        if (!$commission) {
            $_SESSION['flash_error'] = 'Commission not found.';
            return $response->withHeader('Location', '/commissions')->withStatus(302);
        }

        $description = trim((string) ($data['description'] ?? ''));
        $amount = (float) ($data['amount'] ?? 0);
        $type = $data['type'] ?? 'adjustment';

        if ($description === '') {
            $_SESSION['flash_error'] = 'Line item description is required.';
            return $response->withHeader('Location', "/commissions/{$id}")->withStatus(302);
        }

        $this->db->insert('commission_line_items', [
            'commission_id' => $id,
            'description' => $description,
            'amount' => $amount,
            'type' => $type,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Recalculate net amount
        $this->recalculateNetAmount($id);

        $_SESSION['flash_success'] = 'Line item added.';
        return $response->withHeader('Location', "/commissions/{$id}")->withStatus(302);
    }

    /**
     * Delete a line item from a commission.
     */
    public function deleteLineItem(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $lineItemId = (int) $args['itemId'];

        $lineItem = $this->db->fetch(
            "SELECT id FROM commission_line_items WHERE id = ? AND commission_id = ?",
            [$lineItemId, $id]
        );

        if (!$lineItem) {
            $_SESSION['flash_error'] = 'Line item not found.';
            return $response->withHeader('Location', "/commissions/{$id}")->withStatus(302);
        }

        $this->db->delete('commission_line_items', 'id = ?', [$lineItemId]);

        // Recalculate net amount
        $this->recalculateNetAmount($id);

        $_SESSION['flash_success'] = 'Line item deleted.';
        return $response->withHeader('Location', "/commissions/{$id}")->withStatus(302);
    }

    /**
     * Recalculate commission amounts.
     */
    public function recalculate(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        $commission = $this->db->fetch("SELECT * FROM commission_payments WHERE id = ?", [$id]);
        if (!$commission) {
            $_SESSION['flash_error'] = 'Commission not found.';
            return $response->withHeader('Location', '/commissions')->withStatus(302);
        }

        $customerId = (int) $commission['customer_id'];
        $periodStart = $commission['period_start'];
        $periodEnd = $commission['period_end'];

        $customer = $this->db->fetch("SELECT * FROM customers WHERE id = ?", [$customerId]);

        // Re-fetch revenue
        $revenueEntries = $this->db->fetchAll(
            "SELECT r.*
             FROM revenue r
             JOIN machines m ON r.machine_id = m.id
             WHERE m.customer_id = ?
               AND r.status = 'approved'
               AND r.collection_date BETWEEN ? AND ?",
            [$customerId, $periodStart, $periodEnd]
        );

        // Re-fetch job costs
        $jobCosts = $this->db->fetchAll(
            "SELECT j.id AS job_id,
                    COALESCE(j.parts_cost, 0) AS parts_cost,
                    COALESCE(j.labour_cost, 0) AS labour_cost
             FROM maintenance_jobs j
             JOIN machines m ON j.machine_id = m.id
             WHERE m.customer_id = ?
               AND j.completed_at BETWEEN ? AND ?",
            [$customerId, $periodStart, $periodEnd]
        );

        $carryForward = (float) ($commission['carry_forward_in'] ?? 0);

        $commissionRate = $customer['commission_rate']
            ?? (float) $this->settings->get('default_commission_rate', 0);
        $processingFee = $customer['processing_fee']
            ?? (float) $this->settings->get('default_processing_fee', 0);

        $machineOverrides = $this->db->fetchAll(
            "SELECT id, commission_rate FROM machines
             WHERE customer_id = ? AND commission_rate IS NOT NULL",
            [$customerId]
        );

        // Aggregate revenue entries into totals
        $totalCash = 0;
        $totalCard = 0;
        $totalPrepaid = 0;
        $totalCardTransactions = 0;
        foreach ($revenueEntries as $entry) {
            $totalCash += (float) ($entry['cash_amount'] ?? 0);
            $totalCard += (float) ($entry['card_amount'] ?? 0);
            $totalPrepaid += (float) ($entry['prepaid_amount'] ?? 0);
            $totalCardTransactions += (int) ($entry['card_transactions'] ?? 0);
        }

        // Aggregate job costs
        $totalPartsCost = 0;
        $totalLabourCost = 0;
        foreach ($jobCosts as $job) {
            $totalPartsCost += (float) ($job['parts_cost'] ?? 0);
            $totalLabourCost += (float) ($job['labour_cost'] ?? 0);
        }

        $result = CommissionCalculator::calculate([
            'cash' => $totalCash,
            'card' => $totalCard,
            'prepaid' => $totalPrepaid,
            'card_transactions' => $totalCardTransactions,
            'commission_rate' => $commissionRate,
            'processing_fee' => $processingFee,
            'parts_cost' => $totalPartsCost,
            'labour_cost' => $totalLabourCost,
            'carry_forward_in' => $carryForward,
        ]);

        $this->db->update('commission_payments', [
            'total_cash' => $totalCash,
            'total_card' => $totalCard,
            'total_prepaid' => $totalPrepaid,
            'total_card_transactions' => $totalCardTransactions,
            'commission_rate' => $commissionRate,
            'processing_fee_rate' => $processingFee,
            'total_parts_cost' => $totalPartsCost,
            'total_labour_cost' => $totalLabourCost,
            'gross_revenue' => $result['gross_revenue'],
            'processing_fees' => $result['transaction_fees'],
            'net_revenue' => $result['net_revenue'],
            'parts_deduction' => $result['parts_deduction'],
            'labour_deduction' => $result['labour_deduction'],
            'adjustments_total' => $result['adjustments_total'],
            'commission_calculated' => $result['commission_calculated'],
            'commission_amount' => $result['commission_amount'],
            'carry_forward_out' => $result['carry_forward_out'] ?? 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);

        // Update customer carry_forward
        if (isset($result['carry_forward_out'])) {
            $this->db->update('customers', [
                'carry_forward' => $result['carry_forward_out'],
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$customerId]);
        }

        $_SESSION['flash_success'] = 'Commission recalculated.';
        return $response->withHeader('Location', "/commissions/{$id}")->withStatus(302);
    }

    /**
     * Internal helper to recalculate commission after line item changes.
     */
    private function recalculateNetAmount(int $commissionId): void
    {
        $commission = $this->db->fetch("SELECT * FROM commission_payments WHERE id = ?", [$commissionId]);
        if (!$commission) {
            return;
        }

        $adjustments = (float) $this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0)
             FROM commission_line_items
             WHERE commission_id = ?",
            [$commissionId]
        );

        $netRevenue = (float) ($commission['net_revenue'] ?? 0);
        $commissionRate = (float) ($commission['commission_rate'] ?? 0);
        $carryForwardIn = (float) ($commission['carry_forward_in'] ?? 0);

        $commissionCalculated = ($netRevenue * $commissionRate / 100) + $adjustments + $carryForwardIn;
        $commissionAmount = max(0, $commissionCalculated);
        $carryForwardOut = $commissionCalculated < 0 ? $commissionCalculated : 0;

        $this->db->update('commission_payments', [
            'adjustments_total' => $adjustments,
            'commission_calculated' => round($commissionCalculated, 2),
            'commission_amount' => round($commissionAmount, 2),
            'carry_forward_out' => round($carryForwardOut, 2),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$commissionId]);
    }
}
