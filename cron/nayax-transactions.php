<?php

declare(strict_types=1);

/**
 * Cron: Nayax Transaction Import
 * Schedule: Hourly
 * Imports recent transactions from Nayax API
 */

$container = require __DIR__ . '/bootstrap.php';

use App\Services\Database;
use App\Services\NayaxService;
use App\Services\SettingsService;

$db = $container->get(Database::class);
$nayax = $container->get(NayaxService::class);
$settings = $container->get(SettingsService::class);

echo "[" . date('Y-m-d H:i:s') . "] Nayax Transaction Import started\n";

if ($settings->get('nayax_enabled') !== 'true') {
    echo "Nayax integration is disabled. Skipping.\n";
    exit(0);
}

$cashCountingEnabled = $settings->get('nayax_cash_counting_enabled') === 'true';

try {
    // Import last 24 hours of transactions
    $dateFrom = date('Y-m-d', strtotime('-1 day'));
    $dateTo = date('Y-m-d');

    $transactions = $nayax->getTransactions($dateFrom, $dateTo);

    if (empty($transactions)) {
        echo "No transactions found.\n";
        exit(0);
    }

    $imported = 0;
    $skipped = 0;
    $errors = 0;

    foreach ($transactions as $txn) {
        $txnId = $txn['TransactionId'] ?? $txn['transaction_id'] ?? null;
        if (!$txnId) {
            $errors++;
            continue;
        }

        // Check if already imported
        $existing = $db->fetch(
            "SELECT id FROM nayax_transactions WHERE transaction_id = ?",
            [(string)$txnId]
        );

        if ($existing) {
            $skipped++;
            continue;
        }

        $paymentType = strtolower($txn['PaymentType'] ?? $txn['payment_type'] ?? 'card');

        // Determine payment category
        if (in_array($paymentType, ['cash', 'coin'])) {
            // Check if cash counting is enabled for this device's machine
            $deviceId = $txn['DeviceId'] ?? $txn['device_id'] ?? null;
            if ($deviceId && !$cashCountingEnabled) {
                // Check per-machine setting
                $machine = $db->fetch(
                    "SELECT m.nayax_cash_counting FROM machines m
                     JOIN nayax_devices nd ON nd.machine_id = m.id
                     WHERE nd.device_id = ?",
                    [(string)$deviceId]
                );
                if (!$machine || !$machine['nayax_cash_counting']) {
                    $skipped++;
                    continue; // Skip cash transactions when counting disabled
                }
            }
        }

        $db->insert('nayax_transactions', [
            'transaction_id' => (string)$txnId,
            'device_id' => (string)($txn['DeviceId'] ?? $txn['device_id'] ?? ''),
            'transaction_date' => $txn['TransactionDate'] ?? $txn['transaction_date'] ?? date('Y-m-d H:i:s'),
            'amount' => (float)($txn['Amount'] ?? $txn['amount'] ?? 0),
            'payment_type' => $paymentType,
            'status' => $txn['Status'] ?? $txn['status'] ?? 'completed',
            'raw_data' => json_encode($txn),
        ]);

        $imported++;
    }

    // Log import
    $db->insert('nayax_imports', [
        'import_type' => 'cron',
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'transactions_imported' => $imported,
        'transactions_skipped' => $skipped,
        'transactions_error' => $errors,
        'records_imported' => $imported,
        'records_skipped' => $skipped,
        'records_failed' => $errors,
        'status' => $errors > 0 ? 'partial' : 'success',
    ]);

    echo "Import complete. Imported: {$imported}, Skipped: {$skipped}, Errors: {$errors}\n";

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Nayax Transaction Import completed\n";
