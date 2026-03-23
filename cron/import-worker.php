<?php

declare(strict_types=1);

/**
 * Cron: Import Worker
 * Schedule: Every minute
 * Processes queued import jobs
 */

$container = require __DIR__ . '/bootstrap.php';

use App\Services\Database;

$db = $container->get(Database::class);

echo "[" . date('Y-m-d H:i:s') . "] Import Worker started\n";

try {
    // Get next queued job
    $job = $db->fetch(
        "SELECT * FROM import_jobs WHERE status = 'queued' ORDER BY created_at ASC LIMIT 1"
    );

    if (!$job) {
        echo "No queued jobs found.\n";
        exit(0);
    }

    // Mark as processing
    $db->update('import_jobs', [
        'status' => 'processing',
        'started_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$job['id']]);

    $params = json_decode($job['parameters'] ?? '{}', true);

    echo "Processing job #{$job['id']} (type: {$job['job_type']})\n";

    // Process based on job type
    switch ($job['job_type']) {
        case 'nayax_transactions':
            // Delegate to nayax-transactions.php logic
            echo "Nayax transaction import - use dedicated cron instead\n";
            break;

        case 'csv_revenue':
            // Process CSV revenue import
            $file = $params['file_path'] ?? null;
            if ($file && file_exists($file)) {
                $handle = fopen($file, 'r');
                $header = fgetcsv($handle);
                $imported = 0;
                $total = 0;

                while (($row = fgetcsv($handle)) !== false) {
                    $total++;
                    $data = array_combine($header, $row);

                    try {
                        $db->insert('revenue', [
                            'machine_id' => (int)($data['machine_id'] ?? 0),
                            'collection_date' => $data['collection_date'] ?? date('Y-m-d'),
                            'cash_amount' => (float)($data['cash_amount'] ?? 0),
                            'card_amount' => (float)($data['card_amount'] ?? 0),
                            'prepaid_amount' => (float)($data['prepaid_amount'] ?? 0),
                            'card_transactions' => (int)($data['card_transactions'] ?? 0),
                            'source' => 'import',
                            'status' => 'draft',
                        ]);
                        $imported++;
                    } catch (\Exception $e) {
                        // Skip failed rows
                    }

                    // Update progress
                    $db->update('import_jobs', [
                        'progress' => $imported,
                        'total' => $total,
                        'progress_message' => "Imported {$imported} of {$total} rows",
                    ], 'id = ?', [$job['id']]);
                }

                fclose($handle);
                unlink($file); // Clean up
            }
            break;

        default:
            echo "Unknown job type: {$job['job_type']}\n";
    }

    // Mark as completed
    $db->update('import_jobs', [
        'status' => 'completed',
        'completed_at' => date('Y-m-d H:i:s'),
        'progress_message' => 'Import completed',
    ], 'id = ?', [$job['id']]);

    echo "Job #{$job['id']} completed\n";

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";

    if (isset($job)) {
        $db->update('import_jobs', [
            'status' => 'failed',
            'error_message' => $e->getMessage(),
            'completed_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$job['id']]);
    }

    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Import Worker completed\n";
