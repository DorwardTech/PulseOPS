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
            'settings' => [
                'nayax_auto_import' => $this->settings->get('nayax_auto_import'),
                'nayax_import_interval' => $this->settings->get('nayax_import_interval', '60'),
                'nayax_import_days' => $this->settings->get('nayax_import_days', '7'),
            ],
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
            $duplicates = 0;
            $filtered = 0;

            foreach ($transactions as $txn) {
                $txnId = $txn['transaction_id'] ?? '';
                if ($txnId === '') {
                    continue;
                }

                // Skip declined/failed transactions and $0 amounts
                $amount = (float) ($txn['amount'] ?? 0);
                $status = $txn['status'] ?? 'completed';
                if ($amount <= 0 || in_array($status, ['declined', 'failed', 'rejected', 'cancelled', 'error'])) {
                    $filtered++;
                    continue;
                }

                // Check if transaction already exists
                $exists = $this->db->exists(
                    'nayax_transactions',
                    'transaction_id = ?',
                    [$txnId]
                );

                if ($exists) {
                    $duplicates++;
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

            $skipped = $duplicates + $filtered;

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

            $_SESSION['flash_success'] = "Import complete. {$imported} imported, {$duplicates} duplicates, {$filtered} filtered ($0/failed). {$aggregated} revenue records updated.";
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
        $cashCountingEnabled = (bool) $this->settings->get('nayax_cash_counting_enabled', false);

        $groups = $this->db->fetchAll(
            "SELECT
                nt.device_id,
                nd.machine_id,
                DATE(nt.transaction_date) as txn_date,
                SUM(CASE WHEN nt.payment_type IN ('cash', 'coin') THEN nt.amount ELSE 0 END) as cash_total,
                SUM(CASE WHEN nt.payment_type IN ('card', 'qr', 'app', 'mifh') THEN nt.amount ELSE 0 END) as card_total,
                SUM(CASE WHEN nt.payment_type IN ('prepaid') OR nt.payment_type LIKE '%prepaid%' OR nt.payment_type LIKE '%monyx%' THEN nt.amount ELSE 0 END) as prepaid_total,
                COUNT(CASE WHEN nt.payment_type IN ('card', 'qr', 'app', 'mifh') THEN 1 END) as card_txn_count,
                COUNT(CASE WHEN nt.payment_type IN ('prepaid') OR nt.payment_type LIKE '%prepaid%' OR nt.payment_type LIKE '%monyx%' THEN 1 END) as prepaid_txn_count,
                GROUP_CONCAT(nt.id) as transaction_ids
             FROM nayax_transactions nt
             JOIN nayax_devices nd ON nd.device_id = nt.device_id
             WHERE nt.is_aggregated = 0
               AND nt.status = 'completed'
               AND nd.machine_id IS NOT NULL
             GROUP BY nt.device_id, nd.machine_id, DATE(nt.transaction_date)"
        );

        // When cash counting is disabled, zero out Nayax cash totals
        // (cash is counted manually, not from Nayax)
        if (!$cashCountingEnabled) {
            foreach ($groups as &$g) {
                $g['cash_total'] = 0;
            }
            unset($g);
        }

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
     * Reconciliation: compare Nayax transactions vs aggregated revenue per machine.
     * Shows gaps, misclassified transactions, and unlinked devices.
     */
    public function reconcile(Request $request, Response $response, array $args = []): Response
    {
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $params = $request->getQueryParams();
        $dateFrom = $params['date_from'] ?? date('Y-m-01');
        $dateTo = $params['date_to'] ?? date('Y-m-d');

        // 1. Nayax transaction totals per device (from raw imported transactions)
        $nayaxTotals = $this->db->fetchAll(
            "SELECT nt.device_id,
                    nd.device_name,
                    nd.machine_id,
                    m.machine_code,
                    m.name AS machine_name,
                    COUNT(*) AS txn_count,
                    SUM(CASE WHEN nt.payment_type IN ('card', 'qr', 'app', 'mifh') THEN nt.amount ELSE 0 END) AS nayax_card,
                    SUM(CASE WHEN nt.payment_type IN ('cash', 'coin') THEN nt.amount ELSE 0 END) AS nayax_cash,
                    SUM(CASE WHEN nt.payment_type IN ('prepaid') OR nt.payment_type LIKE '%prepaid%' OR nt.payment_type LIKE '%monyx%' THEN nt.amount ELSE 0 END) AS nayax_prepaid,
                    SUM(nt.amount) AS nayax_total,
                    SUM(CASE WHEN nt.is_aggregated = 0 THEN 1 ELSE 0 END) AS unaggregated_count,
                    SUM(CASE WHEN nt.is_aggregated = 0 THEN nt.amount ELSE 0 END) AS unaggregated_amount
             FROM nayax_transactions nt
             LEFT JOIN nayax_devices nd ON nt.device_id = nd.device_id
             LEFT JOIN machines m ON nd.machine_id = m.id
             WHERE DATE(nt.transaction_date) BETWEEN ? AND ?
               AND nt.status = 'completed'
             GROUP BY nt.device_id, nd.device_name, nd.machine_id, m.machine_code, m.name
             ORDER BY nayax_total DESC",
            [$dateFrom, $dateTo]
        );

        // 2. Revenue totals per machine (aggregated records, nayax source only)
        $revenueTotals = $this->db->fetchAll(
            "SELECT r.machine_id,
                    m.machine_code,
                    m.name AS machine_name,
                    SUM(r.card_amount) AS revenue_card,
                    SUM(r.cash_amount) AS revenue_cash,
                    SUM(r.prepaid_amount) AS revenue_prepaid,
                    SUM(r.card_amount + r.cash_amount) AS revenue_gross
             FROM revenue r
             JOIN machines m ON r.machine_id = m.id
             WHERE r.source = 'nayax'
               AND r.collection_date BETWEEN ? AND ?
             GROUP BY r.machine_id, m.machine_code, m.name
             ORDER BY revenue_gross DESC",
            [$dateFrom, $dateTo]
        );

        // Index revenue by machine_id for easy lookup
        $revenueByMachine = [];
        foreach ($revenueTotals as $r) {
            $revenueByMachine[(int) $r['machine_id']] = $r;
        }

        // 3. Build reconciliation rows
        $reconciliation = [];
        $totalNayaxCard = 0;
        $totalNayaxCash = 0;
        $totalNayaxPrepaid = 0;
        $totalRevenueCard = 0;
        $totalRevenueCash = 0;
        $totalRevenuePrepaid = 0;

        foreach ($nayaxTotals as $nt) {
            $machineId = $nt['machine_id'] ? (int) $nt['machine_id'] : null;
            $rev = $machineId ? ($revenueByMachine[$machineId] ?? null) : null;

            $nCard = round((float) $nt['nayax_card'], 2);
            $nCash = round((float) $nt['nayax_cash'], 2);
            $nPrepaid = round((float) $nt['nayax_prepaid'], 2);
            $rCard = $rev ? round((float) $rev['revenue_card'], 2) : 0;
            $rCash = $rev ? round((float) $rev['revenue_cash'], 2) : 0;
            $rPrepaid = $rev ? round((float) $rev['revenue_prepaid'], 2) : 0;

            $cardDiff = round($nCard - $rCard, 2);
            $cashDiff = round($nCash - $rCash, 2);
            $prepaidDiff = round($nPrepaid - $rPrepaid, 2);

            $totalNayaxCard += $nCard;
            $totalNayaxCash += $nCash;
            $totalNayaxPrepaid += $nPrepaid;
            $totalRevenueCard += $rCard;
            $totalRevenueCash += $rCash;
            $totalRevenuePrepaid += $rPrepaid;

            $status = 'ok';
            if (!$machineId) {
                $status = 'unlinked';
            } elseif (abs($cardDiff) > 0.01 || abs($prepaidDiff) > 0.01) {
                $status = 'mismatch';
            } elseif ((int) $nt['unaggregated_count'] > 0) {
                $status = 'pending';
            }

            $reconciliation[] = [
                'device_id' => $nt['device_id'],
                'device_name' => $nt['device_name'],
                'machine_id' => $machineId,
                'machine_code' => $nt['machine_code'],
                'machine_name' => $nt['machine_name'],
                'txn_count' => (int) $nt['txn_count'],
                'nayax_card' => $nCard,
                'nayax_cash' => $nCash,
                'nayax_prepaid' => $nPrepaid,
                'revenue_card' => $rCard,
                'revenue_cash' => $rCash,
                'revenue_prepaid' => $rPrepaid,
                'card_diff' => $cardDiff,
                'cash_diff' => $cashDiff,
                'prepaid_diff' => $prepaidDiff,
                'unaggregated' => (int) $nt['unaggregated_count'],
                'unaggregated_amount' => round((float) $nt['unaggregated_amount'], 2),
                'status' => $status,
            ];
        }

        // 4. Unlinked devices
        $unlinkedDevices = $this->db->fetchAll(
            "SELECT nd.device_id, nd.device_name, nd.device_serial,
                    COUNT(nt.id) AS txn_count,
                    COALESCE(SUM(nt.amount), 0) AS total_amount
             FROM nayax_devices nd
             LEFT JOIN nayax_transactions nt ON nd.device_id = nt.device_id
                 AND DATE(nt.transaction_date) BETWEEN ? AND ?
             WHERE nd.machine_id IS NULL
             GROUP BY nd.device_id, nd.device_name, nd.device_serial
             ORDER BY total_amount DESC",
            [$dateFrom, $dateTo]
        );

        return $this->twig->render($response, 'admin/nayax/reconcile.twig', [
            'active_page' => 'nayax',
            'auth_user' => $this->auth->user(),
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'reconciliation' => $reconciliation,
            'unlinked_devices' => $unlinkedDevices,
            'totals' => [
                'nayax_card' => round($totalNayaxCard, 2),
                'nayax_cash' => round($totalNayaxCash, 2),
                'nayax_prepaid' => round($totalNayaxPrepaid, 2),
                'revenue_card' => round($totalRevenueCard, 2),
                'revenue_cash' => round($totalRevenueCash, 2),
                'revenue_prepaid' => round($totalRevenuePrepaid, 2),
            ],
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
        ]);
    }

    /**
     * Diagnostic: show distinct PaymentMethod and RecognitionMethod values from raw_data.
     */
    public function diagnostics(Request $request, Response $response, array $args = []): Response
    {
        $recognition = $this->db->fetchAll(
            "SELECT LOWER(JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.RecognitionMethod'))) AS val, COUNT(*) AS cnt
             FROM nayax_transactions WHERE raw_data IS NOT NULL
             GROUP BY val ORDER BY cnt DESC"
        );

        $payment = $this->db->fetchAll(
            "SELECT LOWER(JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.PaymentMethod'))) AS val, COUNT(*) AS cnt
             FROM nayax_transactions WHERE raw_data IS NOT NULL
             GROUP BY val ORDER BY cnt DESC"
        );

        $currentTypes = $this->db->fetchAll(
            "SELECT payment_type, COUNT(*) AS cnt, SUM(amount) AS total
             FROM nayax_transactions GROUP BY payment_type ORDER BY cnt DESC"
        );

        $revenueCheck = $this->db->fetchAll(
            "SELECT source, COUNT(*) AS cnt, SUM(prepaid_amount) AS prepaid_total, SUM(card_amount) AS card_total, SUM(cash_amount) AS cash_total
             FROM revenue GROUP BY source"
        );

        $payload = [
            'recognition_methods' => $recognition,
            'payment_methods' => $payment,
            'current_payment_types' => $currentTypes,
            'revenue_by_source' => $revenueCheck,
        ];

        $response->getBody()->write(json_encode($payload, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Re-derive payment_type from raw_data JSON for all nayax transactions.
     * Uses bulk SQL with JSON_EXTRACT to avoid loading all rows into PHP.
     */
    private function reclassifyPaymentTypes(): int
    {
        // Use MySQL JSON_EXTRACT to reclassify in bulk
        // RecognitionMethod takes priority for prepaid detection
        // Reclassify using PaymentMethod (RecognitionMethod mirrors it in this API)
        // QR, app, mifh all count as card revenue (commissioned)
        // Only 'prepaid credit' and 'monyx' are true prepaid (excluded)
        $sql = "UPDATE nayax_transactions SET payment_type = CASE
            WHEN LOWER(JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.PaymentMethod'))) IN ('cash') THEN 'cash'
            WHEN LOWER(JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.PaymentMethod'))) IN ('coin', 'coins') THEN 'coin'
            WHEN LOWER(JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.PaymentMethod'))) LIKE '%prepaid%' THEN 'prepaid'
            WHEN LOWER(JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.PaymentMethod'))) LIKE '%monyx%' THEN 'prepaid'
            WHEN LOWER(JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.RecognitionMethod'))) LIKE '%prepaid%' THEN 'prepaid'
            WHEN LOWER(JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.RecognitionMethod'))) LIKE '%monyx%' THEN 'prepaid'
            ELSE 'card'
        END
        WHERE raw_data IS NOT NULL";

        return $this->db->execute($sql);
    }

    /**
     * Cron endpoint for automatic Nayax import.
     * Called via: GET /api/nayax/cron?key=<cron_key>
     */
    public function cronImport(Request $request, Response $response, array $args = []): Response
    {
        $params = $request->getQueryParams();
        $key = $params['key'] ?? '';

        $cronKey = $this->settings->get('nayax_cron_key', '');
        if ($cronKey === '' || !hash_equals($cronKey, $key)) {
            $response->getBody()->write(json_encode(['error' => 'Unauthorized']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        if (!$this->settings->get('nayax_auto_import')) {
            $response->getBody()->write(json_encode(['status' => 'disabled', 'message' => 'Auto-import is disabled']));
            return $response->withHeader('Content-Type', 'application/json');
        }

        // Check interval - only run if enough time has passed since last cron import
        $interval = (int) ($this->settings->get('nayax_import_interval') ?: 60);
        $lastCron = $this->db->fetch(
            "SELECT import_date FROM nayax_imports WHERE import_type = 'cron' ORDER BY id DESC LIMIT 1"
        );
        if ($lastCron) {
            $lastTime = strtotime($lastCron['import_date']);
            if ((time() - $lastTime) < ($interval * 60)) {
                $response->getBody()->write(json_encode([
                    'status' => 'skipped',
                    'message' => 'Not yet due',
                    'next_in' => ($interval * 60) - (time() - $lastTime) . 's',
                ]));
                return $response->withHeader('Content-Type', 'application/json');
            }
        }

        $days = (int) ($this->settings->get('nayax_import_days') ?: 7);
        $dateTo = date('Y-m-d');
        $dateFrom = date('Y-m-d', strtotime("-{$days} days"));

        try {
            $transactions = $this->nayax->getTransactions($dateFrom, $dateTo);

            $imported = 0;
            $skipped = 0;

            foreach ($transactions as $txn) {
                $txnId = $txn['transaction_id'] ?? '';
                if ($txnId === '') {
                    continue;
                }

                $amount = (float) ($txn['amount'] ?? 0);
                $status = $txn['status'] ?? 'completed';
                if ($amount <= 0 || in_array($status, ['declined', 'failed', 'rejected', 'cancelled', 'error'])) {
                    $skipped++;
                    continue;
                }

                $exists = $this->db->exists('nayax_transactions', 'transaction_id = ?', [$txnId]);
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

            $this->db->insert('nayax_imports', [
                'import_type'            => 'cron',
                'date_from'              => $dateFrom,
                'date_to'                => $dateTo,
                'transactions_imported'  => $imported,
                'transactions_skipped'   => $skipped,
                'records_imported'       => $imported,
                'records_skipped'        => $skipped,
                'status'                 => 'success',
                'imported_by'            => null,
            ]);

            $aggregated = $this->aggregateToRevenue();

            $result = [
                'status' => 'success',
                'imported' => $imported,
                'skipped' => $skipped,
                'aggregated' => $aggregated,
                'period' => "{$dateFrom} to {$dateTo}",
            ];
        } catch (\Exception $e) {
            $this->db->insert('nayax_imports', [
                'import_type'  => 'cron',
                'date_from'    => $dateFrom,
                'date_to'      => $dateTo,
                'status'       => 'failed',
                'error_message' => $e->getMessage(),
                'imported_by'  => null,
            ]);

            $result = ['status' => 'error', 'message' => $e->getMessage()];
        }

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
