<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\Database;
use App\Services\AuthService;

class LogViewerController
{
    private const LOG_PATHS = [
        '/var/log/php/error.log',
        '/var/log/php-fpm/error.log',
        '/var/log/apache2/error.log',
        '/var/log/nginx/error.log',
        '/tmp/php-errors.log',
    ];

    private const MAX_LINES = 500;
    private const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB

    public function __construct(
        private Twig $twig,
        private Database $db,
        private AuthService $auth
    ) {}

    /**
     * Show PHP error logs and Nayax API logs.
     */
    public function index(Request $request, Response $response): Response
    {
        if (!$this->auth->hasPermission('*')) {
            $_SESSION['flash_error'] = 'You do not have permission to view logs.';
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $params = $request->getQueryParams();
        $tab = $params['tab'] ?? 'php';
        $lines = min((int) ($params['lines'] ?? 200), self::MAX_LINES);
        $search = $params['search'] ?? '';

        $phpLog = $this->readPhpErrorLog($lines, $search);
        $apiLogs = $this->getApiLogs($params);
        $appLogs = $this->getAppLogs($lines, $search);

        return $this->twig->render($response, 'admin/logs/index.twig', [
            'active_page' => 'logs',
            'auth_user' => $this->auth->user(),
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'tab' => $tab,
            'php_log' => $phpLog,
            'api_logs' => $apiLogs['logs'],
            'api_pagination' => $apiLogs['pagination'],
            'app_logs' => $appLogs,
            'lines' => $lines,
            'search' => $search,
            'log_path' => $phpLog['path'] ?? 'not found',
            'filters' => [
                'status' => $params['status'] ?? '',
                'method' => $params['method'] ?? '',
                'endpoint' => $params['endpoint'] ?? '',
            ],
        ]);
    }

    /**
     * Clear PHP error log (POST).
     */
    public function clearPhpLog(Request $request, Response $response): Response
    {
        if (!$this->auth->hasPermission('*')) {
            $_SESSION['flash_error'] = 'You do not have permission to clear logs.';
            return $response->withHeader('Location', '/logs')->withStatus(302);
        }

        $logPath = $this->findLogFile();
        if ($logPath && is_writable($logPath)) {
            file_put_contents($logPath, '');
            $_SESSION['flash_success'] = 'PHP error log cleared.';
        } else {
            $_SESSION['flash_error'] = 'Could not clear log file (not found or not writable).';
        }

        return $response->withHeader('Location', '/logs?tab=php')->withStatus(302);
    }

    /**
     * Clear old API logs (POST).
     */
    public function clearApiLogs(Request $request, Response $response): Response
    {
        if (!$this->auth->hasPermission('*')) {
            $_SESSION['flash_error'] = 'You do not have permission to clear logs.';
            return $response->withHeader('Location', '/logs')->withStatus(302);
        }

        $data = $request->getParsedBody();
        $days = max(1, (int) ($data['days'] ?? 30));

        $deleted = $this->db->execute(
            "DELETE FROM nayax_api_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );

        $_SESSION['flash_success'] = "Cleared API logs older than {$days} days.";
        return $response->withHeader('Location', '/logs?tab=api')->withStatus(302);
    }

    // ─── Private helpers ───────────────────────────────────────────────

    /**
     * Find the active PHP error log file.
     */
    private function findLogFile(): ?string
    {
        // Check php.ini setting first
        $iniPath = ini_get('error_log');
        if ($iniPath && $iniPath !== '/proc/self/fd/2' && is_readable($iniPath)) {
            return $iniPath;
        }

        // Check common log locations
        foreach (self::LOG_PATHS as $path) {
            if (is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Read PHP error log, returning the last N lines.
     */
    private function readPhpErrorLog(int $lines, string $search): array
    {
        $result = [
            'path' => null,
            'entries' => [],
            'total_size' => 0,
            'source' => 'file',
        ];

        $logPath = $this->findLogFile();

        // If no file log, try reading from Docker stderr via /proc
        if (!$logPath) {
            // Try to read syslog entries or recent error_log() output from DB
            $result['source'] = 'unavailable';
            $result['message'] = 'PHP error log writes to Docker stderr (/proc/self/fd/2). '
                . 'File-based logging is not configured. '
                . 'View container logs with: docker logs <container>';
            return $result;
        }

        $result['path'] = $logPath;
        $result['total_size'] = filesize($logPath);

        // Don't try to read files larger than max
        if ($result['total_size'] > self::MAX_FILE_SIZE) {
            // Read just the tail
            $content = $this->tailFile($logPath, $lines * 5); // overshoot for filtering
        } else {
            $content = file_get_contents($logPath);
        }

        if ($content === false) {
            return $result;
        }

        // Parse into entries (PHP errors often span multiple lines)
        $rawLines = explode("\n", trim($content));
        $entries = [];
        $current = null;

        foreach ($rawLines as $line) {
            // PHP error lines typically start with [date] or a timestamp
            if (preg_match('/^\[(\d{2}-\w{3}-\d{4}\s[\d:]+\s\w+)\]/', $line, $m)
                || preg_match('/^\[(\d{4}-\d{2}-\d{2}[\sT][\d:]+)/', $line, $m)) {
                if ($current !== null) {
                    $entries[] = $current;
                }
                $current = [
                    'timestamp' => $m[1] ?? '',
                    'message' => $line,
                    'level' => $this->detectLevel($line),
                ];
            } elseif ($current !== null) {
                // Stack trace continuation
                $current['message'] .= "\n" . $line;
            } else {
                $entries[] = [
                    'timestamp' => '',
                    'message' => $line,
                    'level' => $this->detectLevel($line),
                ];
            }
        }
        if ($current !== null) {
            $entries[] = $current;
        }

        // Apply search filter
        if ($search !== '') {
            $searchLower = strtolower($search);
            $entries = array_filter($entries, function ($e) use ($searchLower) {
                return str_contains(strtolower($e['message']), $searchLower);
            });
        }

        // Take last N entries (most recent)
        $entries = array_slice($entries, -$lines);

        // Reverse so newest is first
        $result['entries'] = array_reverse($entries);

        return $result;
    }

    /**
     * Read last N bytes of a file efficiently.
     */
    private function tailFile(string $path, int $lines): string
    {
        $fp = fopen($path, 'r');
        if (!$fp) {
            return '';
        }

        // Seek from end
        $chunkSize = 8192;
        $buffer = '';
        $lineCount = 0;

        fseek($fp, 0, SEEK_END);
        $pos = ftell($fp);

        while ($pos > 0 && $lineCount < $lines) {
            $readSize = min($chunkSize, $pos);
            $pos -= $readSize;
            fseek($fp, $pos);
            $chunk = fread($fp, $readSize);
            $buffer = $chunk . $buffer;
            $lineCount = substr_count($buffer, "\n");
        }

        fclose($fp);
        return $buffer;
    }

    /**
     * Detect log level from a line.
     */
    private function detectLevel(string $line): string
    {
        $lower = strtolower($line);
        if (str_contains($lower, 'fatal') || str_contains($lower, 'emergency')) {
            return 'fatal';
        }
        if (str_contains($lower, 'error') || str_contains($lower, 'exception')) {
            return 'error';
        }
        if (str_contains($lower, 'warning') || str_contains($lower, 'warn')) {
            return 'warning';
        }
        if (str_contains($lower, 'notice') || str_contains($lower, 'info')) {
            return 'notice';
        }
        if (str_contains($lower, 'deprecated')) {
            return 'deprecated';
        }
        return 'info';
    }

    /**
     * Get Nayax API logs from database.
     */
    private function getApiLogs(array $params): array
    {
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        $where = ['1=1'];
        $bindings = [];

        if (!empty($params['status']) && is_numeric($params['status'])) {
            $where[] = 'status_code = ?';
            $bindings[] = (int) $params['status'];
        }
        if (!empty($params['method'])) {
            $where[] = 'method = ?';
            $bindings[] = strtoupper($params['method']);
        }
        if (!empty($params['endpoint'])) {
            $where[] = 'endpoint LIKE ?';
            $bindings[] = '%' . $params['endpoint'] . '%';
        }

        $whereClause = implode(' AND ', $where);

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM nayax_api_logs WHERE {$whereClause}",
            $bindings
        );

        $logs = $this->db->fetchAll(
            "SELECT * FROM nayax_api_logs WHERE {$whereClause} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}",
            $bindings
        );

        return [
            'logs' => $logs,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => max(1, (int) ceil($total / $perPage)),
                'total_count' => $total,
                'per_page' => $perPage,
            ],
        ];
    }

    /**
     * Get application-level log entries (error_log calls captured in DB if available).
     */
    private function getAppLogs(int $lines, string $search): array
    {
        // Check if an app_logs table exists
        try {
            $logs = $this->db->fetchAll(
                "SELECT * FROM app_logs ORDER BY created_at DESC LIMIT ?",
                [$lines]
            );
            return $logs;
        } catch (\Exception $e) {
            // Table doesn't exist — that's fine
            return [];
        }
    }
}
