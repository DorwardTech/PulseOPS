<?php

declare(strict_types=1);

/**
 * Cron: Nayax Device Sync
 * Schedule: Hourly
 * Syncs machine/device data from Nayax API via GET /v1/machines
 */

$container = require __DIR__ . '/bootstrap.php';

use App\Services\Database;
use App\Services\NayaxService;
use App\Services\SettingsService;

$db = $container->get(Database::class);
$nayax = $container->get(NayaxService::class);
$settings = $container->get(SettingsService::class);

echo "[" . date('Y-m-d H:i:s') . "] Nayax Device Sync started\n";

if ($settings->get('nayax_enabled') !== 'true') {
    echo "Nayax integration is disabled. Skipping.\n";
    exit(0);
}

try {
    $devices = $nayax->getDevices();

    if (empty($devices)) {
        echo "No devices returned from API.\n";
        exit(0);
    }

    $synced = 0;
    $created = 0;

    foreach ($devices as $device) {
        $deviceId = $device['device_id'] ?? '';
        if ($deviceId === '') {
            continue;
        }

        $existing = $db->fetch(
            "SELECT id FROM nayax_devices WHERE device_id = ?",
            [$deviceId]
        );

        $data = [
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
            $db->update('nayax_devices', $data, 'id = ?', [$existing['id']]);
            $synced++;
        } else {
            $data['device_id'] = $deviceId;
            $db->insert('nayax_devices', $data);
            $created++;
        }
    }

    $settings->set('nayax_last_sync', date('Y-m-d H:i:s'));
    echo "Sync complete. Created: {$created}, Updated: {$synced}\n";

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    try {
        $db->insert('nayax_api_logs', [
            'method' => 'GET',
            'endpoint' => '/v1/machines',
            'status_code' => 0,
            'response_data' => json_encode(['error' => $e->getMessage()]),
        ]);
    } catch (\Exception $logEx) {
        // Ignore logging failures
    }
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Nayax Device Sync completed\n";
