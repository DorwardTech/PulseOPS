<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\CommissionCalculator;

/**
 * Commission generation logic shared by the web controller and cron.
 */
class CommissionService
{
    public function __construct(
        private Database $db,
        private SettingsService $settings
    ) {}

    /**
     * Generate (or regenerate) a draft commission for a customer + period.
     *
     * Commission is calculated PER-MACHINE (each machine has its own rate),
     * then summed. This ensures accuracy when machines have different rates.
     *
     * @return array{id: int, result: array, created: bool}
     */
    public function generateForCustomer(int $customerId, string $periodStart, string $periodEnd, ?int $generatedBy = null): array
    {
        $customer = $this->db->fetch("SELECT * FROM customers WHERE id = ?", [$customerId]);
        if (!$customer) {
            throw new \RuntimeException("Customer {$customerId} not found");
        }

        // 1. Revenue per machine for the period
        $machineRevenue = $this->db->fetchAll(
            "SELECT r.machine_id,
                    COALESCE(SUM(r.cash_amount), 0) AS cash_total,
                    COALESCE(SUM(r.card_amount), 0) AS card_total,
                    COALESCE(SUM(r.prepaid_amount), 0) AS prepaid_total,
                    COALESCE(SUM(r.card_transactions), 0) AS card_txn_count
             FROM revenue r
             JOIN machines m ON r.machine_id = m.id
             WHERE m.customer_id = ?
               AND r.status = 'approved'
               AND r.collection_date BETWEEN ? AND ?
             GROUP BY r.machine_id",
            [$customerId, $periodStart, $periodEnd]
        );

        // 2. Job costs in the period
        $jobCosts = $this->db->fetchAll(
            "SELECT COALESCE(j.parts_cost, 0) AS parts_cost,
                    COALESCE(j.labour_cost, 0) AS labour_cost
             FROM maintenance_jobs j
             JOIN machines m ON j.machine_id = m.id
             WHERE m.customer_id = ?
               AND j.completed_at BETWEEN ? AND ?",
            [$customerId, $periodStart, $periodEnd]
        );

        // 3. Carry forward
        $carryForward = (float) ($customer['carry_forward'] ?? 0);

        // 4. Rates
        $defaultRate = (float) ($customer['commission_rate']
            ?? $this->settings->get('default_commission_rate', 0));
        $processingFee = (float) ($customer['processing_fee']
            ?? $this->settings->get('default_processing_fee', 0));

        // Machine-level rates
        $machineRates = [];
        $machineRows = $this->db->fetchAll(
            "SELECT id, commission_rate FROM machines WHERE customer_id = ?",
            [$customerId]
        );
        foreach ($machineRows as $row) {
            $machineRates[(int) $row['id']] = $row['commission_rate'] !== null
                ? (float) $row['commission_rate']
                : $defaultRate;
        }

        // 5. Calculate commission PER MACHINE, then sum
        $totalCash = 0;
        $totalCard = 0;
        $totalPrepaid = 0;
        $totalCardTransactions = 0;
        $totalGross = 0;
        $totalFees = 0;
        $totalCommission = 0;

        foreach ($machineRevenue as $mr) {
            $machineId = (int) $mr['machine_id'];
            $cash = (float) $mr['cash_total'];
            $card = (float) $mr['card_total'];
            $prepaid = (float) $mr['prepaid_total'];
            $cardTxns = (int) $mr['card_txn_count'];
            $rate = $machineRates[$machineId] ?? $defaultRate;

            $gross = $cash + $card;
            $fees = $cardTxns * $processingFee;
            $net = $gross - $fees;
            $commission = $net * ($rate / 100);

            $totalCash += $cash;
            $totalCard += $card;
            $totalPrepaid += $prepaid;
            $totalCardTransactions += $cardTxns;
            $totalGross += $gross;
            $totalFees += $fees;
            $totalCommission += $commission;
        }

        $totalNetRevenue = $totalGross - $totalFees;

        // Job cost deductions (applied proportionally to commission)
        $totalPartsCost = 0;
        $totalLabourCost = 0;
        foreach ($jobCosts as $job) {
            $totalPartsCost += (float) ($job['parts_cost'] ?? 0);
            $totalLabourCost += (float) ($job['labour_cost'] ?? 0);
        }

        // Deduct parts and labour from commission (not from revenue)
        $partsDeduction = $totalPartsCost;
        $labourDeduction = $totalLabourCost;

        $commissionCalculated = $totalCommission - $partsDeduction - $labourDeduction + $carryForward;
        $commissionAmount = max(0, round($commissionCalculated, 2));
        $carryForwardOut = $commissionCalculated < 0 ? round($commissionCalculated, 2) : 0;

        // Effective blended rate for display purposes only
        $effectiveRate = $totalNetRevenue > 0
            ? round(($totalCommission / $totalNetRevenue) * 100, 2)
            : $defaultRate;

        // 6. Store
        $existing = $this->db->fetch(
            "SELECT id, status FROM commission_payments
             WHERE customer_id = ? AND period_start = ? AND period_end = ?",
            [$customerId, $periodStart, $periodEnd]
        );

        if ($existing && !in_array($existing['status'], ['draft', 'void'], true)) {
            throw new \RuntimeException("Commission already exists with status '{$existing['status']}'");
        }

        $commissionData = [
            'total_cash' => round($totalCash, 2),
            'total_card' => round($totalCard, 2),
            'total_prepaid' => round($totalPrepaid, 2),
            'total_card_transactions' => $totalCardTransactions,
            'commission_rate' => $effectiveRate,
            'processing_fee_rate' => $processingFee,
            'total_parts_cost' => round($totalPartsCost, 2),
            'total_labour_cost' => round($totalLabourCost, 2),
            'carry_forward_in' => round($carryForward, 2),
            'carry_forward_out' => $carryForwardOut,
            'gross_revenue' => round($totalGross, 2),
            'processing_fees' => round($totalFees, 2),
            'net_revenue' => round($totalNetRevenue, 2),
            'parts_deduction' => round($partsDeduction, 2),
            'labour_deduction' => round($labourDeduction, 2),
            'adjustments_total' => 0,
            'commission_calculated' => round($commissionCalculated, 2),
            'commission_amount' => $commissionAmount,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $created = false;
        if ($existing) {
            $commissionId = (int) $existing['id'];
            $commissionData['status'] = 'draft';
            $commissionData['generated_by'] = $generatedBy;
            $this->db->update('commission_payments', $commissionData, 'id = ?', [$commissionId]);
            $this->db->delete('commission_line_items', 'commission_id = ?', [$commissionId]);
        } else {
            $commissionData['customer_id'] = $customerId;
            $commissionData['period_start'] = $periodStart;
            $commissionData['period_end'] = $periodEnd;
            $commissionData['status'] = 'draft';
            $commissionData['generated_by'] = $generatedBy;
            $commissionId = $this->db->insert('commission_payments', $commissionData);
            $created = true;
        }

        // Update carry forward on customer
        $this->db->update('customers', [
            'carry_forward' => $carryForwardOut,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$customerId]);

        return [
            'id' => $commissionId,
            'result' => $commissionData,
            'created' => $created,
        ];
    }
}
