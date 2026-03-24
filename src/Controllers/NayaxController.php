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
            "SELECT id, name, machine_code FROM machines ORDER BY name ASC"
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
     */
    public function syncDevices(Request $request, Response $response, array $args = []): Response
    {
        try {
            $apiDevices = $this->nayax->getDevices();

            $synced = 0;
            foreach ($apiDevices as $device) {
                $existing = $this->db->fetch(
                    "SELECT id FROM nayax_devices WHERE device_id = ?",
                    [$device['device_id']]
                );

                $deviceData = [
                    'device_name' => $device['name'],
                    'device_serial' => $device['serial'],
                    'device_model' => $device['model'] ?? null,
                    'device_status' => $device['status'],
                    'nayax_device_id' => $device['device_id'],
                    'last_communication' => $device['last_communication'] ?? null,
                    'last_sync_at' => date('Y-m-d H:i:s'),
                ];

                if ($existing) {
                    $this->db->update('nayax_devices', $deviceData, 'id = ?', [$existing['id']]);
                } else {
                    $deviceData['device_id'] = $device['device_id'];
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
            'nayax_device_name' => ['from' => null, 'to' => $device['actor_name'] ?? $device['device_id'] ?? ''],
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
                'nayax_device_name' => ['from' => $device['actor_name'] ?? '', 'to' => null],
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

        return $this->twig->render($response, 'admin/nayax/transactions.twig', [
            'active_page' => 'nayax',
            'auth_user' => $this->auth->user(),
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'transactions' => $transactions,
            'devices' => $devices,
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
                // Check if transaction already exists
                $exists = $this->db->exists(
                    'nayax_transactions',
                    'transaction_id = ?',
                    [$txn['transaction_id']]
                );

                if ($exists) {
                    $skipped++;
                    continue;
                }

                $this->db->insert('nayax_transactions', [
                    'transaction_id' => $txn['transaction_id'],
                    'device_id' => $txn['device_id'],
                    'transaction_date' => $txn['date'],
                    'amount' => $txn['amount'],
                    'payment_type' => $txn['payment_type'],
                    'status' => $txn['status'],
                    'card_type' => $txn['card_type'],
                    'product_name' => $txn['product_name'],
                    'raw_data' => json_encode($txn['raw']),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $imported++;
            }

            // Record the import
            $this->db->insert('nayax_imports', [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'transactions_imported' => $imported,
                'transactions_skipped' => $skipped,
                'imported_by' => $this->auth->user()['id'] ?? null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $_SESSION['flash_success'] = "Import complete. {$imported} transactions imported, {$skipped} skipped (duplicates).";
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = 'Import failed: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/nayax/import')->withStatus(302);
    }
}
