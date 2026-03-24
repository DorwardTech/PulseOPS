<?php
declare(strict_types=1);

namespace App\Services;

class AuditService
{
    private Database $db;
    private AuthService $auth;

    public function __construct(Database $db, AuthService $auth)
    {
        $this->db = $db;
        $this->auth = $auth;
    }

    /**
     * Log an action to the activity_logs table.
     */
    public function log(
        string $action,
        string $entityType,
        int $entityId,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $portalUserId = null
    ): void {
        $user = $this->auth->user();

        try {
            $this->db->insert('activity_logs', [
                'user_id' => $user['id'] ?? null,
                'portal_user_id' => $portalUserId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'old_values' => $oldValues !== null ? json_encode($oldValues) : null,
                'new_values' => $newValues !== null ? json_encode($newValues) : null,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null,
            ]);
        } catch (\Exception $e) {
            error_log("AuditService error: " . $e->getMessage());
        }
    }

    /**
     * Compare old and new data, returning only changed fields.
     */
    public function diff(array $old, array $new): array
    {
        $changed = [];
        foreach ($new as $key => $value) {
            if (!array_key_exists($key, $old) || (string) ($old[$key] ?? '') !== (string) ($value ?? '')) {
                $changed[$key] = [
                    'from' => $old[$key] ?? null,
                    'to' => $value,
                ];
            }
        }
        return $changed;
    }

    /**
     * Get activity logs for a specific entity.
     */
    public function getLogsForEntity(string $entityType, int $entityId, int $limit = 50): array
    {
        try {
            return $this->db->fetchAll(
                "SELECT al.*, u.full_name AS user_name
                 FROM activity_logs al
                 LEFT JOIN users u ON al.user_id = u.id
                 WHERE al.entity_type = ? AND al.entity_id = ?
                 ORDER BY al.created_at DESC
                 LIMIT {$limit}",
                [$entityType, $entityId]
            );
        } catch (\Exception $e) {
            error_log("AuditService getLogsForEntity error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all activity logs with pagination.
     */
    public function getRecentLogs(int $limit = 100): array
    {
        try {
            return $this->db->fetchAll(
                "SELECT al.*, u.full_name AS user_name
                 FROM activity_logs al
                 LEFT JOIN users u ON al.user_id = u.id
                 ORDER BY al.created_at DESC
                 LIMIT {$limit}"
            );
        } catch (\Exception $e) {
            error_log("AuditService getRecentLogs error: " . $e->getMessage());
            return [];
        }
    }
}
