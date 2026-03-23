<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Authentication Service - Handles admin and portal user authentication
 *
 * Two auth domains:
 * - Admin users: users table joined with roles
 * - Portal users: customer_portal_users table joined with customers
 */
class AuthService
{
    private Database $db;
    private int $maxLoginAttempts;
    private int $lockoutDuration;

    public function __construct(Database $db, array $config = [])
    {
        $this->db = $db;
        $this->maxLoginAttempts = $config['login_attempts'] ?? 5;
        $this->lockoutDuration = $config['lockout_duration'] ?? 900;
    }

    /**
     * Attempt to log in an admin user
     */
    public function attempt(string $email, string $password, bool $remember = false): bool
    {
        $user = $this->db->fetch(
            "SELECT u.*, r.slug AS role_slug, r.permissions
             FROM users u
             JOIN roles r ON u.role_id = r.id
             WHERE u.email = ? AND u.is_active = 1",
            [$email]
        );

        if (!$user) {
            return false;
        }

        // Check if locked out
        if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
            return false;
        }

        if (!password_verify($password, $user['password'])) {
            $this->incrementLoginAttempts($user['id']);
            return false;
        }

        // Successful login
        $this->resetLoginAttempts($user['id']);
        $this->createSession($user);

        if ($remember) {
            $this->createRememberToken($user['id']);
        }

        return true;
    }

    /**
     * Attempt to log in a portal user (customer)
     */
    public function attemptPortal(string $email, string $password): bool
    {
        $user = $this->db->fetch(
            "SELECT cpu.*, c.business_name
             FROM customer_portal_users cpu
             JOIN customers c ON cpu.customer_id = c.id
             WHERE cpu.email = ? AND cpu.is_active = 1 AND c.is_active = 1",
            [$email]
        );

        if (!$user || !password_verify($password, $user['password'])) {
            return false;
        }

        $this->createPortalSession($user);
        return true;
    }

    /**
     * Log out the current user (admin or portal)
     */
    public function logout(): void
    {
        if (isset($_SESSION['portal_user_id'])) {
            $this->db->insert('customer_portal_logs', [
                'portal_user_id' => $_SESSION['portal_user_id'],
                'action' => 'logout',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }

        session_destroy();
    }

    /**
     * Get current admin user data from session
     */
    public function user(): ?array
    {
        if (!$this->isAuthenticated()) {
            return null;
        }

        return $_SESSION['user'] ?? null;
    }

    /**
     * Get current portal user data from session
     */
    public function portalUser(): ?array
    {
        if (!$this->isPortalAuthenticated()) {
            return null;
        }

        return $_SESSION['portal_user'] ?? null;
    }

    /**
     * Check if an admin user is authenticated
     */
    public function isAuthenticated(): bool
    {
        return !empty($_SESSION['user_id']);
    }

    /**
     * Check if a portal user is authenticated
     */
    public function isPortalAuthenticated(): bool
    {
        return !empty($_SESSION['portal_user_id']);
    }

    /**
     * Check if any user (admin or portal) is authenticated
     */
    public function check(): bool
    {
        return $this->isAuthenticated() || $this->isPortalAuthenticated();
    }

    /**
     * Check if admin user has a specific permission
     */
    public function hasPermission(string $permission): bool
    {
        if (!$this->isAuthenticated()) {
            return false;
        }

        $permissions = $_SESSION['user']['permissions'] ?? [];

        // Wildcard = all permissions
        if (in_array('*', $permissions, true)) {
            return true;
        }

        // Exact match
        if (in_array($permission, $permissions, true)) {
            return true;
        }

        // Module wildcard (e.g., 'machines.*' matches 'machines.view')
        $parts = explode('.', $permission);
        $module = $parts[0] ?? '';

        if (in_array("{$module}.*", $permissions, true)) {
            return true;
        }

        return false;
    }

    /**
     * Check if user has any of the given permissions
     */
    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if user has all of the given permissions
     */
    public function hasAllPermissions(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->hasPermission($permission)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check if an account is currently locked
     */
    public function isLocked(string $email): bool
    {
        $user = $this->db->fetch(
            "SELECT locked_until FROM users WHERE email = ?",
            [$email]
        );

        if (!$user || empty($user['locked_until'])) {
            return false;
        }

        return strtotime($user['locked_until']) > time();
    }

    /**
     * Hash a password using Argon2id
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3
        ]);
    }

    /**
     * Generate a secure random password
     */
    public static function generatePassword(int $length = 16): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $password = '';
        $max = strlen($chars) - 1;

        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, $max)];
        }

        return $password;
    }

    // ─── Private helpers ───────────────────────────────────────────────

    private function createSession(array $user): void
    {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user'] = [
            'id' => $user['id'],
            'role_id' => $user['role_id'],
            'role_slug' => $user['role_slug'],
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'permissions' => json_decode($user['permissions'] ?? '[]', true),
        ];
        $_SESSION['logged_in_at'] = time();

        // Update last login
        $this->db->update('users', [
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => $_SERVER['REMOTE_ADDR'] ?? null
        ], 'id = ?', [$user['id']]);

        session_regenerate_id(true);
    }

    private function createPortalSession(array $user): void
    {
        $_SESSION['portal_user_id'] = $user['id'];
        $_SESSION['portal_user'] = [
            'id' => $user['id'],
            'customer_id' => $user['customer_id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'business_name' => $user['business_name'],
        ];
        $_SESSION['logged_in_at'] = time();

        // Update last login
        $this->db->update('customer_portal_users', [
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => $_SERVER['REMOTE_ADDR'] ?? null
        ], 'id = ?', [$user['id']]);

        // Log activity
        $this->db->insert('customer_portal_logs', [
            'portal_user_id' => $user['id'],
            'action' => 'login',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);

        session_regenerate_id(true);
    }

    private function incrementLoginAttempts(int $userId): void
    {
        $this->db->query(
            "UPDATE users SET login_attempts = login_attempts + 1,
             locked_until = IF(login_attempts >= ?, DATE_ADD(NOW(), INTERVAL ? SECOND), locked_until)
             WHERE id = ?",
            [$this->maxLoginAttempts, $this->lockoutDuration, $userId]
        );
    }

    private function resetLoginAttempts(int $userId): void
    {
        $this->db->update('users', [
            'login_attempts' => 0,
            'locked_until' => null
        ], 'id = ?', [$userId]);
    }

    private function createRememberToken(int $userId): void
    {
        $token = bin2hex(random_bytes(32));
        $this->db->update('users', ['remember_token' => $token], 'id = ?', [$userId]);

        setcookie('remember_token', $token, [
            'expires' => time() + (86400 * 30), // 30 days
            'path' => '/',
            'secure' => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
}
