<?php

declare(strict_types=1);

/**
 * Cron: Nayax Device Sync
 * Schedule: Hourly
 * Syncs device data from Nayax API
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
        $deviceId = $device['DeviceId'] ?? $device['device_id'] ?? null;
        if (!$deviceId) continue;

        $existing = $db->fetch(
            "SELECT id FROM nayax_devices WHERE device_id = ?",
            [(string)$deviceId]
        );

        $data = [
            'device_id' => (string)$deviceId,
            'device_name' => $device['DeviceName'] ?? $device['device_name'] ?? null,
            'device_serial' => $device['SerialNumber'] ?? $device['serial_number'] ?? null,
            'device_status' => $device['Status'] ?? $device['status'] ?? 'unknown',
            'device_model' => $device['Model'] ?? $device['model'] ?? null,
            'firmware_version' => $device['FirmwareVersion'] ?? $device['firmware_version'] ?? null,
            'last_communication' => $device['LastCommunication'] ?? $device['last_communication'] ?? null,
            'last_sync_at' => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            $db->update('nayax_devices', $data, 'id = ?', [$existing['id']]);
            $synced++;
        } else {
            $db->insert('nayax_devices', $data);
            $created++;
        }
    }

    echo "Sync complete. Created: {$created}, Updated: {$synced}\n";

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    $db->insert('nayax_api_logs', [
        'method' => 'GET',
        'endpoint' => '/devices',
        'status_code' => 0,
        'response_data' => json_encode(['error' => $e->getMessage()]),
    ]);
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Nayax Device Sync completed\n";
