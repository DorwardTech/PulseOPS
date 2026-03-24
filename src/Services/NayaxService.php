<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Nayax Lynx API Service
 *
 * Handles Nayax API integrations for transaction imports,
 * device management, and remote operations.
 *
 * API Documentation: Nayax Operational Lynx API v1
 * Authentication: Direct token from Nayax User Tokens page
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
     * Test API connection
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
            $response = $this->request('GET', '/v1/machines', ['Take' => 5]);

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
     * Get devices list from Nayax API
     */
    public function getDevices(): array
    {
        $machines = $this->getMachines(1000, 0);

        return array_map(function ($m) {
            return [
                'device_id' => $m['MachineID'] ?? '',
                'name' => $m['MachineName'] ?? '',
                'serial' => $m['DeviceSerialNumber'] ?? '',
                'status' => ($m['MachineStatusBit'] ?? 0) == 1 ? 'online' : 'offline',
                'last_transaction' => null
            ];
        }, $machines);
    }

    /**
     * Get transactions for a date range
     */
    public function getTransactions(string $dateFrom, string $dateTo, array $deviceIds = []): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $allTransactions = [];

        try {
            $params = [
                'FromDate' => $dateFrom,
                'ToDate' => $dateTo,
                'Take' => 1000,
                'Skip' => 0
            ];

            if (!empty($this->operatorId)) {
                $params['OperatorId'] = $this->operatorId;
            }

            $response = $this->request('GET', '/v1/transactions', $params);

            if ($response['status'] === 200) {
                $rawTransactions = $response['data'] ?? [];

                foreach ($rawTransactions as $txn) {
                    $deviceId = (string) ($txn['MachineID'] ?? $txn['machineID'] ?? $txn['DeviceId'] ?? '');

                    if (!empty($deviceIds) && !in_array($deviceId, $deviceIds)) {
                        continue;
                    }

                    $allTransactions[] = [
                        'transaction_id' => $txn['TransactionID'] ?? $txn['transactionID'] ?? $txn['Id'] ?? uniqid('txn_'),
                        'device_id' => $deviceId,
                        'date' => $txn['TransactionDate'] ?? $txn['transactionDate'] ?? $txn['Date'] ?? date('Y-m-d H:i:s'),
                        'amount' => (float) ($txn['Amount'] ?? $txn['amount'] ?? $txn['TransactionAmount'] ?? 0),
                        'payment_type' => $txn['PaymentType'] ?? $txn['paymentType'] ?? $txn['Type'] ?? 'card',
                        'status' => $txn['Status'] ?? $txn['status'] ?? $txn['TransactionStatus'] ?? 'completed',
                        'card_type' => $txn['CardType'] ?? $txn['cardType'] ?? null,
                        'product_name' => $txn['ProductName'] ?? $txn['productName'] ?? null,
                        'raw' => $txn
                    ];
                }
            }
        } catch (\Exception $e) {
            error_log("Nayax getTransactions error: " . $e->getMessage());
        }

        return $allTransactions;
    }

    /**
     * Get last sales for a specific machine
     */
    public function getLastSales(int $machineId, ?string $from = null, ?string $to = null): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        try {
            if ($from && $to) {
                $params = [
                    'fromDate' => $from . 'T00:00:00',
                    'toDate' => $to . 'T23:59:59'
                ];
                $response = $this->request('GET', "/v1/machines/{$machineId}/sales", $params);
            } else {
                $response = $this->request('GET', "/v1/machines/{$machineId}/lastSales");
            }

            if ($response['status'] === 200) {
                return $response['data'] ?? [];
            }
        } catch (\Exception $e) {
            error_log("Nayax getLastSales error: " . $e->getMessage());
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
     * Get machines from Nayax API
     */
    private function getMachines(int $take = 100, int $skip = 0): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        try {
            $params = [
                'Take' => $take,
                'Skip' => $skip
            ];

            if (!empty($this->operatorId)) {
                $params['OperatorId'] = $this->operatorId;
            }

            $response = $this->request('GET', '/v1/machines', $params);

            if ($response['status'] === 200) {
                return $response['data'] ?? [];
            }
        } catch (\Exception $e) {
            error_log("Nayax getMachines error: " . $e->getMessage());
        }

        return [];
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
                        'data' => $response['data'] ?? null,
                        'error' => $response['error'] ?? null
                    ]),
                    $response['status'] ?? 0
                ]
            );
        } catch (\Exception $e) {
            error_log("Nayax API Log: {$method} {$endpoint} - Status: " . ($response['status'] ?? 'N/A'));
        }
    }
}
