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
     * Bulk generate commissions for ALL active customers for a period.
     */
    public function processGenerateAll(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $periodStart = $data['period_start'] ?? '';
        $periodEnd = $data['period_end'] ?? '';

        if ($periodStart === '' || $periodEnd === '') {
            $_SESSION['flash_error'] = 'Period dates are required.';
            return $response->withHeader('Location', '/commissions/generate')->withStatus(302);
        }

        $customers = $this->db->fetchAll(
            "SELECT DISTINCT c.id, c.name
             FROM customers c
             JOIN machines m ON c.id = m.customer_id
             WHERE c.is_active = 1
             ORDER BY c.name"
        );

        $authUser = $this->auth->user();
        $generated = 0;
        $skipped = 0;
        $errors = [];

        foreach ($customers as $customer) {
            try {
                $this->commissionService->generateForCustomer(
                    (int) $customer['id'],
                    $periodStart,
                    $periodEnd,
                    $authUser['id'] ?? null
                );
                $generated++;
            } catch (\RuntimeException $e) {
                if (str_contains($e->getMessage(), 'already exists')) {
                    $skipped++;
                } else {
                    $errors[] = "{$customer['name']}: {$e->getMessage()}";
                }
            } catch (\Exception $e) {
                $errors[] = "{$customer['name']}: {$e->getMessage()}";
            }
        }

        $msg = "{$generated} commissions generated, {$skipped} skipped (already approved/paid).";
        if (!empty($errors)) {
            $msg .= ' Errors: ' . implode('; ', $errors);
        }
        $_SESSION['flash_success'] = $msg;
        return $response->withHeader('Location', '/commissions')->withStatus(302);
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
     * Export commission as PDF.
     */
    public function exportPdf(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        $commission = $this->db->fetch(
            "SELECT cp.*, c.name AS customer_name
             FROM commission_payments cp
             LEFT JOIN customers c ON cp.customer_id = c.id
             WHERE cp.id = ?",
            [$id]
        );

        if (!$commission) {
            $_SESSION['flash_error'] = 'Commission not found.';
            return $response->withHeader('Location', '/commissions')->withStatus(302);
        }

        $lineItems = $this->db->fetchAll(
            "SELECT cli.* FROM commission_line_items cli WHERE cli.commission_id = ? ORDER BY cli.type, cli.created_at",
            [$id]
        );

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
            [$commission['customer_id'], $commission['period_start'], $commission['period_end']]
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

        $companyName = $this->settings->get('company_name', '') ?: ($_ENV['APP_NAME'] ?? 'PulseOPS');
        $periodFormatted = date('d/m/Y', strtotime($commission['period_start']))
            . ' - ' . date('d/m/Y', strtotime($commission['period_end']));

        // Render HTML via Twig
        $html = $this->twig->fetch('pdf/commission.twig', [
            'commission' => $commission,
            'line_items' => $lineItems,
            'machine_breakdown' => $machineBreakdown,
            'company_name' => $companyName,
            'period_formatted' => $periodFormatted,
            'generated_at' => date('d/m/Y g:i A'),
        ]);

        // Generate PDF
        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = 'commission_' . ($commission['customer_name'] ?? 'unknown') . '_' . $commission['period_start'] . '.pdf';
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

        $pdfContent = $dompdf->output();
        $response->getBody()->write($pdfContent);

        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', "attachment; filename=\"{$filename}\"")
            ->withHeader('Content-Length', (string) strlen($pdfContent))
            ->withStatus(200);
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
     * Permanently delete a commission and its line items.
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        $commission = $this->db->fetch(
            "SELECT id, customer_id, carry_forward_in FROM commission_payments WHERE id = ?",
            [$id]
        );
        if (!$commission) {
            $_SESSION['flash_error'] = 'Commission not found.';
            return $response->withHeader('Location', '/commissions')->withStatus(302);
        }

        // Revert carry forward
        $this->db->update('customers', [
            'carry_forward' => $commission['carry_forward_in'],
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$commission['customer_id']]);

        // Delete line items then commission
        $this->db->delete('commission_line_items', 'commission_id = ?', [$id]);
        $this->db->delete('commission_payments', 'id = ?', [$id]);

        $_SESSION['flash_success'] = 'Commission deleted.';
        return $response->withHeader('Location', '/commissions')->withStatus(302);
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

        try {
            $authUser = $this->auth->user();
            $this->commissionService->generateForCustomer(
                (int) $commission['customer_id'],
                $commission['period_start'],
                $commission['period_end'],
                $authUser['id'] ?? null
            );

            $_SESSION['flash_success'] = 'Commission recalculated.';
        } catch (\RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $response->withHeader('Location', "/commissions/{$id}")->withStatus(302);
    }

    /**
     * Export approved/paid commissions as Xero-compatible CSV (bills).
     */
    public function exportXero(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $status = $params['status'] ?? 'approved';
        $periodStart = $params['period_start'] ?? null;
        $periodEnd = $params['period_end'] ?? null;

        $where = ["cp.status IN ('approved', 'paid')"];
        $bindings = [];

        if ($periodStart) {
            $where[] = 'cp.period_start >= ?';
            $bindings[] = $periodStart;
        }
        if ($periodEnd) {
            $where[] = 'cp.period_end <= ?';
            $bindings[] = $periodEnd;
        }

        $whereClause = implode(' AND ', $where);

        $commissions = $this->db->fetchAll(
            "SELECT cp.*, c.name AS customer_name
             FROM commission_payments cp
             LEFT JOIN customers c ON cp.customer_id = c.id
             WHERE {$whereClause}
             ORDER BY cp.period_end DESC",
            $bindings
        );

        if (empty($commissions)) {
            $_SESSION['flash_error'] = 'No approved/paid commissions found to export.';
            return $response->withHeader('Location', '/commissions')->withStatus(302);
        }

        $accountCode = $this->settings->get('xero_account_code', '');
        $taxType = $this->settings->get('xero_tax_type', 'BAS Excluded');
        $dueDays = (int) ($this->settings->get('xero_due_days', 14) ?: 14);

        $output = fopen('php://temp', 'r+');

        // Xero bill import headers
        fputcsv($output, [
            'ContactName',
            'InvoiceNumber',
            'InvoiceDate',
            'DueDate',
            'Description',
            'Quantity',
            'UnitAmount',
            'AccountCode',
            'TaxType',
            'Currency',
        ], ',', '"', '\\');

        foreach ($commissions as $cp) {
            // Skip $0 commissions
            if ((float) $cp['commission_amount'] <= 0) {
                continue;
            }

            $invoiceDate = $cp['period_end'];
            $dueDate = date('d/m/Y', strtotime($invoiceDate . " +{$dueDays} days"));
            $invoiceDateFormatted = date('d/m/Y', strtotime($invoiceDate));
            $periodLabel = $cp['period_label']
                ?? date('F Y', strtotime($cp['period_start']));
            $invoiceNumber = 'COMM-' . $cp['id'];

            fputcsv($output, [
                $cp['customer_name'] ?? 'Unknown',
                $invoiceNumber,
                $invoiceDateFormatted,
                $dueDate,
                "Commission - {$periodLabel}",
                1,
                number_format((float) $cp['commission_amount'], 2, '.', ''),
                $accountCode,
                $taxType,
                'AUD',
            ], ',', '"', '\\');
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        $filename = 'xero_commissions_' . date('Y-m-d') . '.csv';
        $response->getBody()->write($csv);

        return $response
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Disposition', "attachment; filename=\"{$filename}\"")
            ->withHeader('Content-Transfer-Encoding', 'binary')
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->withStatus(200);
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
