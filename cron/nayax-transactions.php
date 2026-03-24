<?php

declare(strict_types=1);

/**
 * Cron: Nayax Transaction Import
 * Schedule: Hourly
 * Imports recent transactions via GET /v1/machines/{id}/lastSales
 * for each known machine/device.
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
        $txnId = $txn['transaction_id'] ?? '';
        if ($txnId === '') {
            $errors++;
            continue;
        }

        // Check if already imported
        $existing = $db->fetch(
            "SELECT id FROM nayax_transactions WHERE transaction_id = ?",
            [$txnId]
        );

        if ($existing) {
            $skipped++;
            continue;
        }

        $paymentType = strtolower($txn['payment_type'] ?? 'card');

        // Determine payment category — skip cash if disabled
        if (in_array($paymentType, ['cash', 'coin'])) {
            $deviceId = $txn['device_id'] ?? '';
            if ($deviceId !== '' && !$cashCountingEnabled) {
                $machine = $db->fetch(
                    "SELECT m.nayax_cash_counting FROM machines m
                     JOIN nayax_devices nd ON nd.machine_id = m.id
                     WHERE nd.device_id = ?",
                    [$deviceId]
                );
                if (!$machine || !$machine['nayax_cash_counting']) {
                    $skipped++;
                    continue;
                }
            }
        }

        $db->insert('nayax_transactions', [
            'transaction_id'   => $txnId,
            'device_id'        => (string) ($txn['device_id'] ?? ''),
            'transaction_date' => $txn['date'] ?? date('Y-m-d H:i:s'),
            'amount'           => (float) ($txn['amount'] ?? 0),
            'payment_type'     => $paymentType,
            'status'           => $txn['status'] ?? 'completed',
            'raw_data'         => json_encode($txn['raw'] ?? []),
        ]);

        $imported++;
    }

    // Log import
    $db->insert('nayax_imports', [
        'import_type'            => 'cron',
        'date_from'              => $dateFrom,
        'date_to'                => $dateTo,
        'transactions_imported'  => $imported,
        'transactions_skipped'   => $skipped,
        'transactions_error'     => $errors,
        'records_imported'       => $imported,
        'records_skipped'        => $skipped,
        'records_failed'         => $errors,
        'status'                 => $errors > 0 ? 'partial' : 'success',
    ]);

    echo "Import complete. Imported: {$imported}, Skipped: {$skipped}, Errors: {$errors}\n";

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Nayax Transaction Import completed\n";
