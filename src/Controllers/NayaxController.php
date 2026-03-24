<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\Database;
use App\Services\AuthService;
use App\Services\NayaxService;
use App\Services\SettingsService;
use App\Services\AuditService;

class NayaxController
{
    public function __construct(
        private Twig $twig,
        private Database $db,
        private AuthService $auth,
        private NayaxService $nayax,
        private SettingsService $settings,
        private AuditService $audit
    ) {}

    /**
     * Nayax overview dashboard.
     */
    public function index(Request $request, Response $response, array $args = []): Response
    {
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $linkedDevices = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM nayax_devices WHERE machine_id IS NOT NULL"
        );

        $totalDevices = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM nayax_devices"
        );

        $recentTransactions = $this->db->fetchAll(
            "SELECT nt.*, nd.device_name AS device_name, m.name AS machine_name
             FROM nayax_transactions nt
             LEFT JOIN nayax_devices nd ON nt.device_id = nd.device_id
             LEFT JOIN machines m ON nd.machine_id = m.id
             ORDER BY nt.transaction_date DESC
             LIMIT 10"
        );

        $lastSync = $this->settings->get('nayax_last_sync', null);

        $config = $this->nayax->getConfiguration();

        return $this->twig->render($response, 'admin/nayax/index.twig', [
            'active_page' => 'nayax',
            'auth_user' => $this->auth->user(),
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'linked_devices' => $linkedDevices,
            'total_devices' => $totalDevices,
            'recent_transactions' => $recentTransactions,
            'last_sync' => $lastSync,
            'config' => $config,
        ]);
    }

    /**
     * List all Nayax devices with link status.
     */
    public function devices(Request $request, Response $response, array $args = []): Response
    {
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $devices = $this->db->fetchAll(
            "SELECT nd.*, m.name AS machine_name, m.machine_code
             FROM nayax_devices nd
             LEFT JOIN machines m ON nd.machine_id = m.id
             ORDER BY nd.device_name ASC"
        );

        $machines = $this->db->fetchAll(
            "SELECT m.id, m.name, m.machine_code FROM machines m
             LEFT JOIN nayax_devices nd ON nd.machine_id = m.id
             WHERE nd.id IS NULL
             ORDER BY m.name ASC"
        );

        return $this->twig->render($response, 'admin/nayax/devices.twig', [
            'active_page' => 'nayax',
            'auth_user' => $this->auth->user(),
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'devices' => $devices,
            'machines' => $machines,
        ]);
    }

    /**
     * Sync devices from Nayax API.
     * Uses GET /v1/machines which returns MachineInfo objects.
     */
    public function syncDevices(Request $request, Response $response, array $args = []): Response
    {
        try {
            $apiDevices = $this->nayax->getDevices();

            $synced = 0;
            foreach ($apiDevices as $device) {
                $deviceId = $device['device_id'] ?? '';
                if ($deviceId === '') {
                    continue;
                }

                $existing = $this->db->fetch(
                    "SELECT id FROM nayax_devices WHERE device_id = ?",
                    [$deviceId]
                );

                $deviceData = [
                    'device_name'        => $device['name'] ?? null,
                    'device_serial'      => $device['serial'] ?? null,
                    'device_model'       => $device['model'] ?? null,
                    'device_status'      => $device['status'] ?? 'unknown',
                    'nayax_device_id'    => $deviceId,
                    'vpos_id'            => $device['vpos_id'] ?? null,
                    'firmware_version'   => $device['firmware_version'] ?? null,
                    'latitude'           => $device['latitude'] ?? null,
                    'longitude'          => $device['longitude'] ?? null,
                    'last_communication' => $device['last_communication'] ?? null,
                    'last_sync_at'       => date('Y-m-d H:i:s'),
                ];

                if ($existing) {
                    $this->db->update('nayax_devices', $deviceData, 'id = ?', [$existing['id']]);
                } else {
                    $deviceData['device_id'] = $deviceId;
                    $this->db->insert('nayax_devices', $deviceData);
                }
                $synced++;
            }

            $this->settings->set('nayax_last_sync', date('Y-m-d H:i:s'));

            $_SESSION['flash_success'] = "Successfully synced {$synced} devices from Nayax.";
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = 'Failed to sync devices: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/nayax/devices')->withStatus(302);
    }

    /**
     * Bulk link multiple Nayax devices to machines.
     */
    public function bulkLinkDevices(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $links = $data['links'] ?? [];

        if (empty($links)) {
            $_SESSION['flash_error'] = 'No devices selected for linking.';
            return $response->withHeader('Location', '/nayax/devices')->withStatus(302);
        }

        $linked = 0;
        foreach ($links as $link) {
            $deviceId = (int) ($link['device_id'] ?? 0);
            $machineId = (int) ($link['machine_id'] ?? 0);

            if ($deviceId <= 0 || $machineId <= 0) {
                continue;
            }

            $device = $this->db->fetch("SELECT * FROM nayax_devices WHERE id = ?", [$deviceId]);
            if (!$device || $device['machine_id']) {
                continue;
            }

            $this->db->update('nayax_devices', [
                'machine_id' => $machineId,
            ], 'id = ?', [$deviceId]);

            if (!empty($device['device_id'])) {
                $this->db->update('machines', [
                    'nayax_device_id' => $device['device_id'],
                ], 'id = ?', [$machineId]);
            }

            $this->audit->log('nayax_device_linked', 'machine', $machineId, [
                'nayax_device_id' => ['from' => null, 'to' => $device['device_id'] ?? $deviceId],
                'nayax_device_name' => ['from' => null, 'to' => $device['device_name'] ?? $device['device_id'] ?? ''],
            ]);

            $linked++;
        }

        if ($linked > 0) {
            $_SESSION['flash_success'] = "{$linked} device(s) linked successfully.";
        } else {
            $_SESSION['flash_error'] = 'No devices were linked. Make sure you selected a machine for each device.';
        }

        return $response->withHeader('Location', '/nayax/devices')->withStatus(302);
    }

    /**
     * Link a Nayax device to a machine.
     */
    public function linkDevice(Request $request, Response $response, array $args = []): Response
    {
        $deviceId = (int) $args['id'];
        $data = $request->getParsedBody();
        $machineId = (int) ($data['machine_id'] ?? 0);

        if ($deviceId <= 0 || $machineId <= 0) {
            $_SESSION['flash_error'] = 'Please select both a device and a machine.';
            return $response->withHeader('Location', '/nayax/devices')->withStatus(302);
        }

        $device = $this->db->fetch("SELECT * FROM nayax_devices WHERE id = ?", [$deviceId]);
        $machine = $this->db->fetch("SELECT id, name FROM machines WHERE id = ?", [$machineId]);

        $this->db->update('nayax_devices', [
            'machine_id' => $machineId,
        ], 'id = ?', [$deviceId]);

        // Also store the nayax device ID on the machine for reference
        if ($device && !empty($device['device_id'])) {
            $this->db->update('machines', [
                'nayax_device_id' => $device['device_id'],
            ], 'id = ?', [$machineId]);
        }

        // Log on the machine so it shows in machine history
        $this->audit->log('nayax_device_linked', 'machine', $machineId, [
            'nayax_device_id' => ['from' => null, 'to' => $device['device_id'] ?? $deviceId],
            'nayax_device_name' => ['from' => null, 'to' => $device['device_name'] ?? $device['device_id'] ?? ''],
        ]);

        $_SESSION['flash_success'] = 'Device linked to machine successfully.';
        return $response->withHeader('Location', '/nayax/devices')->withStatus(302);
    }

    /**
     * Unlink a Nayax device from a machine.
     */
    public function unlinkDevice(Request $request, Response $response, array $args = []): Response
    {
        $deviceId = (int) $args['id'];

        $device = $this->db->fetch("SELECT * FROM nayax_devices WHERE id = ?", [$deviceId]);
        $oldMachineId = $device['machine_id'] ?? null;

        $this->db->update('nayax_devices', [
            'machine_id' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$deviceId]);

        // Clear nayax_device_id on the machine
        if ($oldMachineId) {
            $this->db->update('machines', ['nayax_device_id' => null], 'id = ?', [$oldMachineId]);

            $this->audit->log('nayax_device_unlinked', 'machine', (int) $oldMachineId, [
                'nayax_device_id' => ['from' => $device['device_id'] ?? $deviceId, 'to' => null],
                'nayax_device_name' => ['from' => $device['device_name'] ?? '', 'to' => null],
            ]);
        }

        $_SESSION['flash_success'] = 'Device unlinked from machine.';
        return $response->withHeader('Location', '/nayax/devices')->withStatus(302);
    }

    /**
     * List recent transactions with filters.
     */
    public function transactions(Request $request, Response $response, array $args = []): Response
    {
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = 25;
        $offset = ($page - 1) * $perPage;

        $dateFrom = $params['date_from'] ?? '';
        $dateTo = $params['date_to'] ?? '';
        $deviceId = $params['device_id'] ?? '';
        $paymentType = $params['payment_type'] ?? '';

        $where = ['1=1'];
        $bindings = [];

        if ($dateFrom !== '') {
            $where[] = 'nt.transaction_date >= ?';
            $bindings[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== '') {
            $where[] = 'nt.transaction_date <= ?';
            $bindings[] = $dateTo . ' 23:59:59';
        }
        if ($deviceId !== '') {
            $where[] = 'nt.device_id = ?';
            $bindings[] = $deviceId;
        }
        if ($paymentType !== '') {
            $where[] = 'nt.payment_type = ?';
            $bindings[] = $paymentType;
        }

        $whereClause = implode(' AND ', $where);

        $totalCount = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM nayax_transactions nt WHERE {$whereClause}",
            $bindings
        );

        $totalPages = max(1, (int) ceil($totalCount / $perPage));

        $transactions = $this->db->fetchAll(
            "SELECT nt.*, nd.device_name AS device_name, m.name AS machine_name
             FROM nayax_transactions nt
             LEFT JOIN nayax_devices nd ON nt.device_id = nd.device_id
             LEFT JOIN machines m ON nd.machine_id = m.id
             WHERE {$whereClause}
             ORDER BY nt.transaction_date DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $bindings
        );

        $devices = $this->db->fetchAll(
            "SELECT device_id, device_name FROM nayax_devices ORDER BY device_name ASC"
        );

        $paymentTypes = $this->db->fetchAll(
            "SELECT DISTINCT payment_type FROM nayax_transactions WHERE payment_type IS NOT NULL AND payment_type != '' ORDER BY payment_type"
        );

        return $this->twig->render($response, 'admin/nayax/transactions.twig', [
            'active_page' => 'nayax',
            'auth_user' => $this->auth->user(),
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'transactions' => $transactions,
            'devices' => $devices,
            'payment_types' => $paymentTypes,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'device_id' => $deviceId,
                'payment_type' => $paymentType,
            ],
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'total_count' => $totalCount,
                'per_page' => $perPage,
            ],
        ]);
    }

    /**
     * Show import form.
     */
    public function showImport(Request $request, Response $response, array $args = []): Response
    {
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $recentImports = $this->db->fetchAll(
            "SELECT * FROM nayax_imports ORDER BY import_date DESC LIMIT 10"
        );

        return $this->twig->render($response, 'admin/nayax/import.twig', [
            'active_page' => 'nayax',
            'auth_user' => $this->auth->user(),
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'recent_imports' => $recentImports,
        ]);
    }

    /**
     * Import transactions from Nayax API for a date range.
     * Calls GET /v1/machines/{id}/lastSales for each known machine.
     */
    public function processImport(Request $request, Response $response, array $args = []): Response
    {
        $data = $request->getParsedBody();
        $dateFrom = $data['date_from'] ?? '';
        $dateTo = $data['date_to'] ?? '';

        if ($dateFrom === '' || $dateTo === '') {
            $_SESSION['flash_error'] = 'Please select both a start and end date.';
            return $response->withHeader('Location', '/nayax/import')->withStatus(302);
        }

        try {
            $transactions = $this->nayax->getTransactions($dateFrom, $dateTo);

            $imported = 0;
            $skipped = 0;

            foreach ($transactions as $txn) {
                $txnId = $txn['transaction_id'] ?? '';
                if ($txnId === '') {
                    continue;
                }

                // Skip declined/failed transactions and $0 amounts
                $amount = (float) ($txn['amount'] ?? 0);
                $status = $txn['status'] ?? 'completed';
                if ($amount <= 0 || in_array($status, ['declined', 'failed', 'rejected', 'cancelled', 'error'])) {
                    $skipped++;
                    continue;
                }

                // Check if transaction already exists
                $exists = $this->db->exists(
                    'nayax_transactions',
                    'transaction_id = ?',
                    [$txnId]
                );

                if ($exists) {
                    $skipped++;
                    continue;
                }

                $this->db->insert('nayax_transactions', [
                    'transaction_id'   => $txnId,
                    'device_id'        => $txn['device_id'] ?? '',
                    'transaction_date' => $txn['date'],
                    'amount'           => $txn['amount'],
                    'payment_type'     => $txn['payment_type'] ?? 'card',
                    'status'           => $txn['status'] ?? 'completed',
                    'raw_data'         => json_encode($txn['raw'] ?? []),
                ]);
                $imported++;
            }

            // Record the import
            $this->db->insert('nayax_imports', [
                'import_type'            => 'manual',
                'date_from'              => $dateFrom,
                'date_to'                => $dateTo,
                'transactions_imported'  => $imported,
                'transactions_skipped'   => $skipped,
                'records_imported'       => $imported,
                'records_skipped'        => $skipped,
                'status'                 => 'success',
                'imported_by'            => $this->auth->user()['id'] ?? null,
            ]);

            // Aggregate imported transactions into revenue
            $aggregated = $this->aggregateToRevenue();

            $_SESSION['flash_success'] = "Import complete. {$imported} transactions imported, {$skipped} skipped (duplicates). {$aggregated} revenue records updated.";
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = 'Import failed: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/nayax/import')->withStatus(302);
    }

    /**
     * Aggregate un-aggregated nayax_transactions into the revenue table.
     * Returns the number of revenue records created or updated.
     */
    private function aggregateToRevenue(): int
    {
        $groups = $this->db->fetchAll(
            "SELECT
                nt.device_id,
                nd.machine_id,
                DATE(nt.transaction_date) as txn_date,
                SUM(CASE WHEN nt.payment_type IN ('cash', 'coin') THEN nt.amount ELSE 0 END) as cash_total,
                SUM(CASE WHEN nt.payment_type IN ('card') THEN nt.amount ELSE 0 END) as card_total,
                SUM(CASE WHEN nt.payment_type IN ('prepaid', 'mifh', 'qr', 'app') THEN nt.amount ELSE 0 END) as prepaid_total,
                COUNT(CASE WHEN nt.payment_type IN ('card') THEN 1 END) as card_txn_count,
                COUNT(CASE WHEN nt.payment_type IN ('prepaid', 'mifh', 'qr', 'app') THEN 1 END) as prepaid_txn_count,
                GROUP_CONCAT(nt.id) as transaction_ids
             FROM nayax_transactions nt
             JOIN nayax_devices nd ON nd.device_id = nt.device_id
             WHERE nt.is_aggregated = 0
               AND nt.status = 'completed'
               AND nd.machine_id IS NOT NULL
             GROUP BY nt.device_id, nd.machine_id, DATE(nt.transaction_date)"
        );

        $count = 0;

        foreach ($groups as $group) {
            if (!$group['machine_id']) {
                continue;
            }

            $existing = $this->db->fetch(
                "SELECT id, cash_amount, card_amount, prepaid_amount, card_transactions, prepaid_transactions
                 FROM revenue
                 WHERE machine_id = ? AND collection_date = ? AND source = 'nayax'",
                [$group['machine_id'], $group['txn_date']]
            );

            if ($existing) {
                $this->db->update('revenue', [
                    'cash_amount' => (float)$existing['cash_amount'] + (float)$group['cash_total'],
                    'card_amount' => (float)$existing['card_amount'] + (float)$group['card_total'],
                    'prepaid_amount' => (float)$existing['prepaid_amount'] + (float)$group['prepaid_total'],
                    'card_transactions' => (int)$existing['card_transactions'] + (int)$group['card_txn_count'],
                    'prepaid_transactions' => (int)$existing['prepaid_transactions'] + (int)$group['prepaid_txn_count'],
                    'updated_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$existing['id']]);
            } else {
                $this->db->insert('revenue', [
                    'machine_id' => $group['machine_id'],
                    'collection_date' => $group['txn_date'],
                    'cash_amount' => (float)$group['cash_total'],
                    'card_amount' => (float)$group['card_total'],
                    'prepaid_amount' => (float)$group['prepaid_total'],
                    'card_transactions' => (int)$group['card_txn_count'],
                    'prepaid_transactions' => (int)$group['prepaid_txn_count'],
                    'cash_source' => 'nayax',
                    'source' => 'nayax',
                    'status' => 'approved',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }

            $count++;

            // Mark transactions as aggregated
            $txnIds = explode(',', $group['transaction_ids']);
            $placeholders = implode(',', array_fill(0, count($txnIds), '?'));
            $this->db->execute(
                "UPDATE nayax_transactions SET is_aggregated = 1, aggregated_at = NOW() WHERE id IN ({$placeholders})",
                $txnIds
            );
        }

        return $count;
    }

    /**
     * Re-aggregate all nayax transactions into the revenue table.
     * Deletes existing nayax revenue records and rebuilds from scratch.
     */
    public function reaggregate(Request $request, Response $response, array $args = []): Response
    {
        try {
            // Re-derive payment_type from raw_data for all transactions
            $reclassified = $this->reclassifyPaymentTypes();

            // Delete all nayax-sourced revenue records
            $this->db->execute("DELETE FROM revenue WHERE source = 'nayax'");

            // Reset all nayax transactions to un-aggregated
            $this->db->execute("UPDATE nayax_transactions SET is_aggregated = 0, aggregated_at = NULL");

            // Re-run aggregation
            $count = $this->aggregateToRevenue();

            $_SESSION['flash_success'] = "Re-aggregation complete. {$reclassified} transactions reclassified, {$count} revenue records rebuilt.";
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = 'Re-aggregation failed: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/nayax/import')->withStatus(302);
    }

    /**
     * Re-derive payment_type from raw_data JSON for all nayax transactions.
     * Uses the same resolvePaymentType logic from NayaxService.
     */
    private function reclassifyPaymentTypes(): int
    {
        $rows = $this->db->fetchAll(
            "SELECT id, raw_data FROM nayax_transactions WHERE raw_data IS NOT NULL"
        );

        $prepaidRecognition = [
            'mifare' => 'mifh', 'mifh' => 'mifh',
            'qr' => 'qr', 'qr code' => 'qr', 'qrcode' => 'qr',
            'app' => 'app', 'prepaid' => 'prepaid', 'nfc' => 'prepaid',
        ];

        $paymentMap = [
            'creditcard' => 'card', 'credit card' => 'card', 'credit' => 'card',
            'debit' => 'card', 'debitcard' => 'card', 'visa' => 'card',
            'mastercard' => 'card', 'card' => 'card', 'cashless' => 'card',
            'cash' => 'cash', 'coin' => 'coin', 'coins' => 'coin',
            'prepaid' => 'prepaid', 'qr' => 'qr', 'qrcode' => 'qr',
            'qr code' => 'qr', 'app' => 'app', 'mifh' => 'mifh', 'mifare' => 'mifh',
        ];

        $updated = 0;

        foreach ($rows as $row) {
            $sale = json_decode($row['raw_data'], true);
            if (!is_array($sale)) {
                continue;
            }

            $recognition = strtolower(trim($sale['RecognitionMethod'] ?? ''));
            $payment = strtolower(trim($sale['PaymentMethod'] ?? ''));

            if (isset($prepaidRecognition[$recognition])) {
                $type = $prepaidRecognition[$recognition];
            } elseif ($payment !== '' && isset($paymentMap[$payment])) {
                $type = $paymentMap[$payment];
            } elseif ($payment !== '') {
                $type = $payment;
            } else {
                $type = 'card';
            }

            $this->db->execute(
                "UPDATE nayax_transactions SET payment_type = ? WHERE id = ?",
                [$type, $row['id']]
            );
            $updated++;
        }

        return $updated;
    }
}
