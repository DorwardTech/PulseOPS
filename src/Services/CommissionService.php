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
     * @return array{id: int, result: array, created: bool}
     */
    public function generateForCustomer(int $customerId, string $periodStart, string $periodEnd, ?int $generatedBy = null): array
    {
        $customer = $this->db->fetch("SELECT * FROM customers WHERE id = ?", [$customerId]);
        if (!$customer) {
            throw new \RuntimeException("Customer {$customerId} not found");
        }

        // 1. Approved revenue for the period
        $revenueEntries = $this->db->fetchAll(
            "SELECT r.*
             FROM revenue r
             JOIN machines m ON r.machine_id = m.id
             WHERE m.customer_id = ?
               AND r.status = 'approved'
               AND r.collection_date BETWEEN ? AND ?",
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
        $processingFee = $customer['processing_fee']
            ?? (float) $this->settings->get('default_processing_fee', 0);

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

        // 5. Aggregate with per-machine weighted rates
        $totalCash = 0;
        $totalCard = 0;
        $totalPrepaid = 0;
        $totalCardTransactions = 0;
        $weightedNumerator = 0;
        $totalGross = 0;

        foreach ($revenueEntries as $entry) {
            $cash = (float) ($entry['cash_amount'] ?? 0);
            $card = (float) ($entry['card_amount'] ?? 0);
            $totalCash += $cash;
            $totalCard += $card;
            $totalPrepaid += (float) ($entry['prepaid_amount'] ?? 0);
            $totalCardTransactions += (int) ($entry['card_transactions'] ?? 0);

            $machineId = (int) ($entry['machine_id'] ?? 0);
            $rate = $machineRates[$machineId] ?? $defaultRate;
            $gross = $cash + $card;
            $weightedNumerator += $gross * $rate;
            $totalGross += $gross;
        }

        $effectiveRate = $totalGross > 0
            ? $weightedNumerator / $totalGross
            : $defaultRate;

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
            'commission_rate' => round($effectiveRate, 2),
            'processing_fee' => $processingFee,
            'parts_cost' => $totalPartsCost,
            'labour_cost' => $totalLabourCost,
            'carry_forward_in' => $carryForward,
        ]);

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
            'total_cash' => $totalCash,
            'total_card' => $totalCard,
            'total_prepaid' => $totalPrepaid,
            'total_card_transactions' => $totalCardTransactions,
            'commission_rate' => $result['commission_rate'],
            'processing_fee_rate' => $result['processing_fee_rate'],
            'total_parts_cost' => $totalPartsCost,
            'total_labour_cost' => $totalLabourCost,
            'carry_forward_in' => $result['carry_forward_in'],
            'carry_forward_out' => $result['carry_forward_out'],
            'gross_revenue' => $result['gross_revenue'],
            'processing_fees' => $result['transaction_fees'],
            'net_revenue' => $result['net_revenue'],
            'parts_deduction' => $result['parts_deduction'],
            'labour_deduction' => $result['labour_deduction'],
            'adjustments_total' => $result['adjustments_total'],
            'commission_calculated' => $result['commission_calculated'],
            'commission_amount' => $result['commission_amount'],
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
        if (isset($result['carry_forward_out'])) {
            $this->db->update('customers', [
                'carry_forward' => $result['carry_forward_out'],
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$customerId]);
        }

        return [
            'id' => $commissionId,
            'result' => $result,
            'created' => $created,
        ];
    }
}
