<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Settings Service - Key/value settings with in-memory caching
 *
 * Settings table expected schema:
 *   id, key (unique), value, type, category, created_at, updated_at
 */
class SettingsService
{
    private Database $db;
    private ?array $cache = null;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Get a setting value by key
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->loadCache();

        if (!array_key_exists($key, $this->cache)) {
            return $default;
        }

        return $this->cache[$key];
    }

    /**
     * Set (update or insert) a setting value
     */
    public function set(string $key, mixed $value, ?string $type = null): void
    {
        $stringValue = is_array($value) || is_object($value) ? json_encode($value) : (string) $value;

        $existing = $this->db->fetch(
            "SELECT id FROM settings WHERE setting_key = ?",
            [$key]
        );

        if ($existing) {
            $data = [
                'setting_value' => $stringValue,
            ];
            if ($type !== null) {
                $data['setting_type'] = $type;
            }
            $this->db->update('settings', $data, 'id = ?', [$existing['id']]);
        } else {
            $data = [
                'setting_key' => $key,
                'setting_value' => $stringValue,
                'setting_type' => $type ?? 'string',
            ];
            $this->db->insert('settings', $data);
        }

        // Update the in-memory cache
        $this->cache[$key] = $this->castValue($stringValue, $type ?? 'string');
    }

    /**
     * Get all settings in a given category
     */
    public function getByCategory(string $category): array
    {
        $rows = $this->db->fetchAll(
            "SELECT setting_key, setting_value, setting_type FROM settings WHERE category = ?",
            [$category]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['setting_key']] = $this->castValue($row['setting_value'], $row['setting_type'] ?? 'string');
        }

        return $result;
    }

    /**
     * Get all settings as key => value
     */
    public function all(): array
    {
        $this->loadCache();
        return $this->cache;
    }

    /**
     * Clear the in-memory cache (forces reload on next access)
     */
    public function clearCache(): void
    {
        $this->cache = null;
    }

    // ─── Private helpers ───────────────────────────────────────────────

    /**
     * Load all settings into memory on first access
     */
    private function loadCache(): void
    {
        if ($this->cache !== null) {
            return;
        }

        $rows = $this->db->fetchAll("SELECT setting_key, setting_value, setting_type FROM settings");

        $this->cache = [];
        foreach ($rows as $row) {
            $this->cache[$row['setting_key']] = $this->castValue($row['setting_value'], $row['setting_type'] ?? 'string');
        }
    }

    /**
     * Cast a stored value to its declared type
     */
    private function castValue(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'bool', 'boolean' => in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true),
            'json', 'array' => json_decode($value, true),
            default => $value,
        };
    }
}
