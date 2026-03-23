<?php
declare(strict_types=1);

namespace App\Helpers;

class CommissionCalculator
{
    /**
     * Calculate commission using V3 formula
     *
     * NEW Formula:
     * Gross Revenue = Cash + Card (Prepaid EXCLUDED)
     * Transaction Fees = Card Transactions x Processing Fee
     * Parts Deduction = Parts Cost x Commission Rate %
     * Labour Deduction = Labour Cost x Commission Rate %
     * Net Revenue = Gross - Transaction Fees - Parts Deduction - Labour Deduction
     * Commission = (Net Revenue x Commission Rate %) + Adjustments + Carry Forward
     * If negative, commission = $0, remainder carries forward
     */
    public static function calculate(array $data): array
    {
        $cash = (float)($data['cash'] ?? 0);
        $card = (float)($data['card'] ?? 0);
        $prepaid = (float)($data['prepaid'] ?? 0);
        $cardTransactions = (int)($data['card_transactions'] ?? 0);
        $commissionRate = (float)($data['commission_rate'] ?? 0);
        $processingFee = (float)($data['processing_fee'] ?? 0.30);
        $partsCost = (float)($data['parts_cost'] ?? 0);
        $labourCost = (float)($data['labour_cost'] ?? 0);
        $adjustmentsTotal = (float)($data['adjustments_total'] ?? 0);
        $carryForwardIn = (float)($data['carry_forward_in'] ?? 0);

        $commissionRateDecimal = $commissionRate / 100;

        // Gross Revenue = Cash + Card ONLY (Prepaid excluded)
        $grossRevenue = $cash + $card;

        // Transaction Fees
        $transactionFees = $cardTransactions * $processingFee;

        // Parts & Labour deductions at commission rate
        $partsDeduction = $partsCost * $commissionRateDecimal;
        $labourDeduction = $labourCost * $commissionRateDecimal;

        // Net Revenue
        $netRevenue = $grossRevenue - $transactionFees - $partsDeduction - $labourDeduction;

        // Commission
        $commissionCalculated = ($netRevenue * $commissionRateDecimal) + $adjustmentsTotal + $carryForwardIn;
        $commissionAmount = max(0, $commissionCalculated);
        $carryForwardOut = $commissionCalculated < 0 ? $commissionCalculated : 0;

        return [
            'gross_revenue' => round($grossRevenue, 2),
            'transaction_fees' => round($transactionFees, 2),
            'parts_deduction' => round($partsDeduction, 2),
            'labour_deduction' => round($labourDeduction, 2),
            'net_revenue' => round($netRevenue, 2),
            'adjustments_total' => round($adjustmentsTotal, 2),
            'carry_forward_in' => round($carryForwardIn, 2),
            'commission_calculated' => round($commissionCalculated, 2),
            'commission_amount' => round($commissionAmount, 2),
            'carry_forward_out' => round($carryForwardOut, 2),
            'prepaid_usage' => round($prepaid, 2),
            'commission_rate' => $commissionRate,
            'processing_fee_rate' => $processingFee,
        ];
    }

    public static function calculateRevenue(float $cash, float $card, float $prepaid, int $cardTransactions, float $processingFee = 0.30): array
    {
        $gross = $cash + $card; // Prepaid excluded
        $fees = $cardTransactions * $processingFee;
        $net = $gross - $fees;
        return [
            'gross' => round($gross, 2),
            'fees' => round($fees, 2),
            'net' => round($net, 2),
            'prepaid' => round($prepaid, 2),
        ];
    }

    public static function calculateLabourCost(int $minutes, float $hourlyRate): float
    {
        return round(($minutes / 60) * $hourlyRate, 2);
    }

    public static function formatLabourTime(int $minutes): string
    {
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;
        if ($hours > 0 && $mins > 0) return "{$hours}h {$mins}m";
        if ($hours > 0) return "{$hours}h";
        return "{$mins}m";
    }

    public static function roundMinutes(int $minutes, int $increment = 15): int
    {
        return (int)(ceil($minutes / $increment) * $increment);
    }
}
