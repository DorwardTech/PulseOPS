<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Nayax Lynx Operational API Service
 *
 * Endpoints used (from swagger.json):
 *   GET /v1/machines          — list machines (our "devices")
 *   GET /v1/machines/{id}     — single machine info
 *   GET /v1/machines/{id}/lastSales — transactions for a machine
 *   GET /v1/machines/{id}/status    — machine status (cash box, counters)
 *   GET /v1/devices           — physical device hardware info
 *
 * Authentication: Bearer token from Nayax User Tokens page
 */
class NayaxService
{
    private Database $db;
    private SettingsService $settings;
    private string $apiUrl;
    private string $token;
    private string $operatorId;
    private array $apiLog = [];

    public const URL_PRODUCTION = 'https://lynx.nayax.com/Operational';
    public const URL_QA = 'https://qa-lynx.nayax.com/Operational';

    public function __construct(array $config, Database $db, SettingsService $settings)
    {
        $this->db = $db;
        $this->settings = $settings;

        // Token: config (env) first, then settings table
        $rawToken = $config['nayax_api_token']
            ?? $this->settings->get('nayax_api_token', '');
        $this->token = preg_replace('/\s+/', '', (string) $rawToken);

        $this->operatorId = $config['nayax_operator_id']
            ?? $this->settings->get('nayax_operator_id', '');

        // Environment: qa or production
        $environment = $config['nayax_environment']
            ?? $this->settings->get('nayax_environment', 'production');

        $this->apiUrl = ($environment === 'qa') ? self::URL_QA : self::URL_PRODUCTION;
    }

    /**
     * Check if API is configured with a token
     */
    public function isConfigured(): bool
    {
        return !empty($this->token);
    }

    /**
     * Check if cash counting toggle is enabled in settings
     */
    public function isCashCountingEnabled(): bool
    {
        return (bool) $this->settings->get('nayax_cash_counting', false);
    }

    /**
     * Get current configuration for display
     */
    public function getConfiguration(): array
    {
        return [
            'api_url' => $this->apiUrl,
            'has_token' => !empty($this->token),
            'token_length' => strlen($this->token),
            'token_preview' => !empty($this->token) ? substr($this->token, 0, 20) . '...' : '',
            'operator_id' => $this->operatorId,
            'environment' => $this->settings->get('nayax_environment', 'production'),
            'cash_counting' => $this->isCashCountingEnabled(),
            'is_configured' => $this->isConfigured(),
        ];
    }

    /**
     * Test API connection by fetching a small set of machines.
     */
    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Nayax API token not configured. Please paste your token from Nayax User Tokens page.'
            ];
        }

        try {
            $response = $this->request('GET', '/v1/machines', ['ResultsLimit' => 5]);

            if ($response['status'] === 200) {
                $machines = $response['data'] ?? [];
                return [
                    'success' => true,
                    'message' => 'Connection successful! Found ' . count($machines) . ' machines.',
                    'machine_count' => count($machines),
                    'sample_data' => array_slice($machines, 0, 2)
                ];
            }

            if ($response['status'] === 401) {
                return [
                    'success' => false,
                    'message' => 'Authentication failed (401). Please check your API token.',
                    'status_code' => 401,
                ];
            }

            return [
                'success' => false,
                'message' => 'API request failed (HTTP ' . $response['status'] . ')',
                'status_code' => $response['status'],
                'details' => $response['data']
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get machines from Nayax API (these are the "devices" in our system).
     * GET /v1/machines — returns array of MachineInfo objects.
     *
     * @return array Normalised device records ready for DB upsert
     */
    public function getDevices(): array
    {
        $machines = $this->fetchAllMachines();

        return array_map(function (array $m): array {
            $statusBit = (int) ($m['MachineStatusBit'] ?? 0);

            return [
                'device_id'          => (string) ($m['MachineID'] ?? ''),
                'name'               => $m['MachineName'] ?? '',
                'serial'             => $m['DeviceSerialNumber'] ?? $m['SerialNumber'] ?? '',
                'model'              => $m['MachineModelID'] ?? null,
                'status'             => $statusBit === 1 ? 'online' : 'offline',
                'vpos_id'            => isset($m['VPOSID']) ? (string) $m['VPOSID'] : null,
                'device_hardware_id' => isset($m['DeviceID']) ? (string) $m['DeviceID'] : null,
                'firmware_version'   => $m['VPOSSerialNumber'] ?? null,
                'latitude'           => $m['GeoLatitude'] ?? null,
                'longitude'          => $m['GeoLongitude'] ?? null,
                'last_communication' => $m['LastUpdated'] ?? null,
                'raw'                => $m,
            ];
        }, $machines);
    }

    /**
     * Get transactions (lastSales) for all linked machines in a date range.
     *
     * The Lynx API has NO global /v1/transactions endpoint.
     * We call GET /v1/machines/{MachineID}/lastSales per machine,
     * using curl_multi to fetch in parallel batches.
     */
    public function getTransactions(string $dateFrom, string $dateTo, array $machineIds = []): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        // If no specific machines requested, get all known machines from our DB
        if (empty($machineIds)) {
            $rows = $this->db->fetchAll(
                "SELECT device_id FROM nayax_devices WHERE device_id IS NOT NULL AND device_id != ''"
            );
            $machineIds = array_column($rows, 'device_id');
        }

        if (empty($machineIds)) {
            error_log("Nayax getTransactions: no machines to query");
            return [];
        }

        // Fetch lastSales for all machines in parallel
        $rawResults = $this->fetchLastSalesMulti($machineIds);

        $allTransactions = [];

        foreach ($rawResults as $machineId => $sales) {
            foreach ($sales as $sale) {
                $txnDate = $sale['AuthorizationDateTimeGMT']
                    ?? $sale['MachineAuthorizationTime']
                    ?? $sale['SettlementDateTimeGMT']
                    ?? null;

                // Filter by date range
                if ($txnDate) {
                    $txnDay = substr($txnDate, 0, 10);
                    if ($txnDay < $dateFrom || $txnDay > $dateTo) {
                        continue;
                    }
                }

                $allTransactions[] = [
                    'transaction_id' => (string) ($sale['TransactionID'] ?? uniqid('txn_')),
                    'device_id'      => (string) ($sale['MachineID'] ?? $machineId),
                    'date'           => $txnDate ?? date('Y-m-d H:i:s'),
                    'amount'         => (float) ($sale['SettlementValue'] ?? $sale['AuthorizationValue'] ?? 0),
                    'payment_type'   => self::resolvePaymentType($sale),
                    'status'         => 'completed',
                    'machine_name'   => $sale['MachineName'] ?? null,
                    'product_name'   => $sale['ProductName'] ?? null,
                    'card_brand'     => $sale['CardBrand'] ?? null,
                    'currency'       => $sale['CurrencyCode'] ?? null,
                    'raw'            => $sale,
                ];
            }
        }

        return $allTransactions;
    }

    /**
     * Get last sales for a specific machine.
     * GET /v1/machines/{MachineID}/lastSales
     *
     * @return array Raw MachineLastSales objects from API
     */
    public function getLastSales(int $machineId, ?string $from = null, ?string $to = null): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        try {
            $response = $this->request('GET', "/v1/machines/{$machineId}/lastSales");

            if ($response['status'] === 200) {
                return $response['data'] ?? [];
            }
        } catch (\Exception $e) {
            error_log("Nayax getLastSales error for machine {$machineId}: " . $e->getMessage());
        }

        return [];
    }

    /**
     * Get machine status (cash box level, counters, etc).
     * GET /v1/machines/{MachineID}/status
     */
    public function getMachineStatus(int $machineId): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        try {
            $response = $this->request('GET', "/v1/machines/{$machineId}/status");

            if ($response['status'] === 200) {
                return $response['data'] ?? null;
            }
        } catch (\Exception $e) {
            error_log("Nayax getMachineStatus error for machine {$machineId}: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Get physical device hardware info.
     * GET /v1/devices — returns array of DeviceExtra objects.
     */
    public function getHardwareDevices(int $pageSize = 1000): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        try {
            $params = [
                'pageNumber' => 1,
                'pageSize' => $pageSize,
            ];

            if (!empty($this->operatorId)) {
                $params['ActorId'] = $this->operatorId;
            }

            $response = $this->request('GET', '/v1/devices', $params);

            if ($response['status'] === 200) {
                return $response['data'] ?? [];
            }
        } catch (\Exception $e) {
            error_log("Nayax getHardwareDevices error: " . $e->getMessage());
        }

        return [];
    }

    /**
     * Get API log for debugging
     */
    public function getApiLog(): array
    {
        return $this->apiLog;
    }

    // ─── Private helpers ───────────────────────────────────────────────

    /**
     * Fetch lastSales for multiple machines in parallel using curl_multi.
     * Processes in batches of 10 to avoid overwhelming the API.
     *
     * @return array<string, array> Map of machineId => sales array
     */
    private function fetchLastSalesMulti(array $machineIds): array
    {
        $results = [];
        $batchSize = 10;
        $batches = array_chunk($machineIds, $batchSize);

        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
        ];

        foreach ($batches as $batch) {
            $mh = curl_multi_init();
            $handles = [];

            foreach ($batch as $machineId) {
                $url = rtrim($this->apiUrl, '/') . "/v1/machines/{$machineId}/lastSales";
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[(string) $machineId] = $ch;
            }

            // Execute all requests in parallel
            do {
                $status = curl_multi_exec($mh, $active);
                if ($active) {
                    curl_multi_select($mh, 1.0);
                }
            } while ($active && $status === CURLM_OK);

            // Collect results
            foreach ($handles as $machineId => $ch) {
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $body = curl_multi_getcontent($ch);
                $error = curl_error($ch);

                $this->logApiCall('GET', "/v1/machines/{$machineId}/lastSales", [], [
                    'status' => $httpCode,
                    'data' => null,
                    'error' => $error ?: null,
                ]);

                if ($httpCode === 200 && $body) {
                    $data = json_decode($body, true);
                    if (is_array($data)) {
                        $results[$machineId] = $data;
                    }
                } elseif ($error) {
                    error_log("Nayax lastSales error for machine {$machineId}: {$error}");
                }

                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }

            curl_multi_close($mh);
        }

        return $results;
    }

    /**
     * Fetch all machines with pagination.
     * GET /v1/machines uses ResultsLimit / ResultsOffset.
     */
    private function fetchAllMachines(): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $all = [];
        $limit = 100;
        $offset = 0;
        $maxPages = 20; // Safety cap: 2000 machines max
        $page = 0;

        try {
            while ($page < $maxPages) {
                $params = [
                    'ResultsLimit' => $limit,
                    'ResultsOffset' => $offset,
                ];

                if (!empty($this->operatorId)) {
                    $params['ActorID'] = $this->operatorId;
                }

                $response = $this->request('GET', '/v1/machines', $params);

                if ($response['status'] !== 200) {
                    break;
                }

                $machines = $response['data'] ?? [];
                if (empty($machines)) {
                    break;
                }

                foreach ($machines as $m) {
                    $all[] = $m;
                }

                // If we got fewer than the limit, we've reached the end
                if (count($machines) < $limit) {
                    break;
                }

                $offset += $limit;
                $page++;
            }
        } catch (\Exception $e) {
            error_log("Nayax fetchAllMachines error: " . $e->getMessage());
        }

        return $all;
    }

    /**
     * Make an API request via cURL
     */
    private function request(string $method, string $endpoint, array $params = []): array
    {
        $url = rtrim($this->apiUrl, '/') . $endpoint;

        if ($method === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if (!empty($params)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if (!empty($params)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($ch);
        $errno = curl_errno($ch);

        curl_close($ch);

        $decodedData = [];
        if ($response !== false && !empty($response)) {
            $decodedData = json_decode($response, true) ?? [];
        }

        $result = [
            'status' => $httpCode,
            'data' => $decodedData,
            'raw_response' => $response !== false ? substr($response, 0, 2000) : '(curl failed)',
            'effective_url' => $effectiveUrl,
            'error' => $error ?: null
        ];

        $this->logApiCall($method, $endpoint, $params, $result);

        if ($error) {
            throw new \RuntimeException("cURL Error [{$errno}]: {$error}");
        }

        return $result;
    }

    /**
     * Log an API call to the database and in-memory log
     */
    private function logApiCall(string $method, string $endpoint, array $requestData, array $response): void
    {
        $this->apiLog[] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'method' => $method,
            'endpoint' => $endpoint,
            'params' => $requestData,
            'status' => $response['status'] ?? null,
        ];

        try {
            $this->db->query(
                "INSERT INTO nayax_api_logs (method, endpoint, request_data, response_data, status_code, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())",
                [
                    $method,
                    $endpoint,
                    json_encode($requestData),
                    json_encode([
                        'status' => $response['status'] ?? null,
                        'data_keys' => is_array($response['data'] ?? null) ? array_keys($response['data']) : [],
                        'error' => $response['error'] ?? null
                    ]),
                    $response['status'] ?? 0
                ]
            );
        } catch (\Exception $e) {
            error_log("Nayax API Log: {$method} {$endpoint} - Status: " . ($response['status'] ?? 'N/A'));
        }
    }

    /**
     * Resolve the payment type from a Nayax sale object using both
     * PaymentMethod and RecognitionMethod fields.
     *
     * RecognitionMethod indicates how the user was identified (MiFare, QR, App, etc.)
     * and is the primary signal for prepaid-type transactions, since PaymentMethod
     * may be null or generic "Credit Card" even for prepaid/QR/app payments.
     */
    private static function resolvePaymentType(array $sale): string
    {
        $payment = strtolower(trim($sale['PaymentMethod'] ?? ''));
        $recognition = strtolower(trim($sale['RecognitionMethod'] ?? ''));

        // Check both fields for prepaid keywords (substring match)
        foreach ([$payment, $recognition] as $val) {
            if (str_contains($val, 'prepaid') || str_contains($val, 'monyx')) {
                return 'prepaid';
            }
        }

        // Exact match map for PaymentMethod
        $paymentMap = [
            'creditcard'  => 'card',
            'credit card' => 'card',
            'credit'      => 'card',
            'debit'       => 'card',
            'debitcard'   => 'card',
            'visa'        => 'card',
            'mastercard'  => 'card',
            'card'        => 'card',
            'cashless'    => 'card',
            'cash'        => 'cash',
            'coin'        => 'coin',
            'coins'       => 'coin',
            'qr'          => 'qr',
            'qrcode'      => 'qr',
            'qr code'     => 'qr',
            'app'         => 'app',
            'mifh'        => 'mifh',
            'mifare'      => 'mifh',
        ];

        if ($payment !== '' && isset($paymentMap[$payment])) {
            return $paymentMap[$payment];
        }

        // Check recognition for other known types
        if (isset($paymentMap[$recognition])) {
            return $paymentMap[$recognition];
        }

        // If PaymentMethod is set but unknown, preserve it
        if ($payment !== '') {
            return $payment;
        }

        return 'card';
    }
}
