<?php

declare(strict_types=1);

/**
 * Cron: Nayax Revenue Aggregation
 * Schedule: Daily or configurable interval
 * Aggregates Nayax transactions into revenue records
 */

$container = require __DIR__ . '/bootstrap.php';

use App\Services\Database;
use App\Services\SettingsService;

$db = $container->get(Database::class);
$settings = $container->get(SettingsService::class);

echo "[" . date('Y-m-d H:i:s') . "] Nayax Revenue Aggregation started\n";

if ($settings->get('nayax_enabled') !== 'true') {
    echo "Nayax integration is disabled. Skipping.\n";
    exit(0);
}

try {
    // Get un-aggregated transactions grouped by device and date
    $groups = $db->fetchAll(
        "SELECT
            nt.device_id,
            nd.machine_id,
            DATE(nt.transaction_date) as txn_date,
            SUM(CASE WHEN nt.payment_type IN ('cash', 'coin', 'coins') THEN nt.amount ELSE 0 END) as cash_total,
            SUM(CASE WHEN nt.payment_type IN ('card', 'creditcard', 'credit card', 'credit', 'debit', 'debitcard', 'visa', 'mastercard', 'cashless') THEN nt.amount ELSE 0 END) as card_total,
            SUM(CASE WHEN nt.payment_type IN ('prepaid', 'mifh', 'mifare', 'qr', 'qrcode', 'qr code', 'app') THEN nt.amount ELSE 0 END) as prepaid_total,
            COUNT(CASE WHEN nt.payment_type IN ('card', 'creditcard', 'credit card', 'credit', 'debit', 'debitcard', 'visa', 'mastercard', 'cashless') THEN 1 END) as card_txn_count,
            COUNT(CASE WHEN nt.payment_type IN ('prepaid', 'mifh', 'mifare', 'qr', 'qrcode', 'qr code', 'app') THEN 1 END) as prepaid_txn_count,
            GROUP_CONCAT(nt.id) as transaction_ids
         FROM nayax_transactions nt
         JOIN nayax_devices nd ON nd.device_id = nt.device_id
         WHERE nt.is_aggregated = 0
           AND nt.status = 'completed'
           AND nd.machine_id IS NOT NULL
         GROUP BY nt.device_id, nd.machine_id, DATE(nt.transaction_date)"
    );

    if (empty($groups)) {
        echo "No un-aggregated transactions found.\n";
        exit(0);
    }

    $created = 0;
    $updated = 0;

    foreach ($groups as $group) {
        if (!$group['machine_id']) continue;

        $txnDate = $group['txn_date'];

        // Check if revenue record already exists for this machine/date
        $existing = $db->fetch(
            "SELECT id, cash_amount, card_amount, prepaid_amount, card_transactions, prepaid_transactions
             FROM revenue
             WHERE machine_id = ? AND collection_date = ? AND source = 'nayax'",
            [$group['machine_id'], $txnDate]
        );

        $revenueData = [
            'cash_amount' => (float)$group['cash_total'],
            'card_amount' => (float)$group['card_total'],
            'prepaid_amount' => (float)$group['prepaid_total'],
            'card_transactions' => (int)$group['card_txn_count'],
            'prepaid_transactions' => (int)$group['prepaid_txn_count'],
            'cash_source' => 'nayax',
            'source' => 'nayax',
            'status' => 'approved',
            'nayax_transaction_ids' => json_encode(explode(',', $group['transaction_ids'])),
        ];

        if ($existing) {
            // Update existing record (add to existing amounts)
            $db->update('revenue', [
                'cash_amount' => (float)$existing['cash_amount'] + $revenueData['cash_amount'],
                'card_amount' => (float)$existing['card_amount'] + $revenueData['card_amount'],
                'prepaid_amount' => (float)$existing['prepaid_amount'] + $revenueData['prepaid_amount'],
                'card_transactions' => (int)$existing['card_transactions'] + $revenueData['card_transactions'],
                'prepaid_transactions' => (int)$existing['prepaid_transactions'] + $revenueData['prepaid_transactions'],
            ], 'id = ?', [$existing['id']]);
            $updated++;
        } else {
            $revenueData['machine_id'] = $group['machine_id'];
            $revenueData['collection_date'] = $txnDate;
            $db->insert('revenue', $revenueData);
            $created++;
        }

        // Mark transactions as aggregated
        $txnIds = explode(',', $group['transaction_ids']);
        $placeholders = implode(',', array_fill(0, count($txnIds), '?'));
        $db->execute(
            "UPDATE nayax_transactions SET is_aggregated = 1, aggregated_at = NOW() WHERE id IN ({$placeholders})",
            $txnIds
        );
    }

    echo "Aggregation complete. Created: {$created}, Updated: {$updated}\n";

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Nayax Revenue Aggregation completed\n";
