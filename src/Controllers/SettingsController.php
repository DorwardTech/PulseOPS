<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\Database;
use App\Services\AuthService;
use App\Services\SettingsService;

class SettingsController
{
    public function __construct(
        private Twig $twig,
        private Database $db,
        private AuthService $auth,
        private SettingsService $settings
    ) {}

    /**
     * Show settings page with all categories in tabs.
     */
    public function index(Request $request, Response $response, array $args = []): Response
    {
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $params = $request->getQueryParams();
        $activeTab = $params['tab'] ?? 'general';

        $settings = array_merge(
            $this->settings->getByCategory('general'),
            $this->settings->getByCategory('commission'),
            $this->settings->getByCategory('nayax'),
            $this->settings->getByCategory('email'),
            $this->settings->getByCategory('revenue')
        );

        // Data for Users tab
        $users = $this->db->fetchAll(
            "SELECT u.*, r.name AS role_name
             FROM users u
             LEFT JOIN roles r ON u.role_id = r.id
             ORDER BY u.full_name ASC"
        );

        // Data for Roles tab
        $roles = $this->db->fetchAll(
            "SELECT r.*, COUNT(u.id) AS users_count
             FROM roles r
             LEFT JOIN users u ON r.id = u.role_id
             GROUP BY r.id
             ORDER BY r.name ASC"
        );

        // Data for Job Statuses tab
        $jobStatuses = $this->db->fetchAll(
            "SELECT js.*, COUNT(j.id) AS job_count
             FROM job_statuses js
             LEFT JOIN maintenance_jobs j ON js.id = j.status_id
             GROUP BY js.id
             ORDER BY js.sort_order ASC, js.name ASC"
        );

        // Data for Machine Types tab
        $machineTypes = $this->db->fetchAll(
            "SELECT mt.*, COUNT(m.id) AS machines_count
             FROM machine_types mt
             LEFT JOIN machines m ON mt.id = m.machine_type_id
             GROUP BY mt.id
             ORDER BY mt.name ASC"
        );

        return $this->twig->render($response, 'admin/settings/index.twig', [
            'active_page' => 'settings',
            'auth_user' => $this->auth->user(),
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'active_tab' => $activeTab,
            'settings' => $settings,
            'users' => $users,
            'roles' => $roles,
            'job_statuses' => $jobStatuses,
            'machine_types' => $machineTypes,
        ]);
    }

    /**
     * Update general settings.
     */
    public function updateGeneral(Request $request, Response $response, array $args = []): Response
    {
        $data = $request->getParsedBody();

        $fields = ['company_name', 'company_email', 'company_phone', 'company_address', 'timezone'];
        foreach ($fields as $field) {
            if (isset($data[$field])) {
                $this->settings->set($field, trim($data[$field]));
            }
        }

        // Map currency form field to currency_code DB key
        if (isset($data['currency'])) {
            $this->settings->set('currency_code', trim($data['currency']));
        }

        $_SESSION['flash_success'] = 'General settings updated successfully.';
        return $response->withHeader('Location', '/settings?tab=general')->withStatus(302);
    }

    /**
     * Update commission settings.
     */
    public function updateCommission(Request $request, Response $response, array $args = []): Response
    {
        $data = $request->getParsedBody();

        $fields = [
            'default_commission_rate' => 'float',
            'default_processing_fee' => 'float',
            'labour_hourly_rate' => 'float',
            'labour_increment_minutes' => 'integer',
        ];

        foreach ($fields as $field => $type) {
            if (isset($data[$field])) {
                $this->settings->set($field, trim($data[$field]), $type);
            }
        }

        $_SESSION['flash_success'] = 'Commission settings updated successfully.';
        return $response->withHeader('Location', '/settings?tab=commission')->withStatus(302);
    }

    /**
     * Update Nayax settings.
     */
    public function updateNayax(Request $request, Response $response, array $args = []): Response
    {
        $data = $request->getParsedBody();

        $this->settings->set('nayax_enabled', !empty($data['nayax_enabled']) ? '1' : '0', 'boolean');

        $stringFields = ['nayax_api_token', 'nayax_operator_id', 'nayax_environment'];
        foreach ($stringFields as $field) {
            if (isset($data[$field])) {
                $this->settings->set($field, trim($data[$field]));
            }
        }

        $this->settings->set('nayax_cash_counting_enabled', !empty($data['nayax_cash_counting_enabled']) ? '1' : '0', 'boolean');

        // Auto-import settings
        $this->settings->set('nayax_auto_import', !empty($data['nayax_auto_import']) ? '1' : '0', 'boolean');
        if (isset($data['nayax_import_interval'])) {
            $this->settings->set('nayax_import_interval', trim($data['nayax_import_interval']));
        }
        if (isset($data['nayax_import_days'])) {
            $this->settings->set('nayax_import_days', trim($data['nayax_import_days']));
        }
        if (isset($data['nayax_cron_key'])) {
            $key = trim($data['nayax_cron_key']);
            if ($key === '') {
                $key = bin2hex(random_bytes(16));
            }
            $this->settings->set('nayax_cron_key', $key);
        }

        $this->settings->clearCache();

        $_SESSION['flash_success'] = 'Nayax settings updated successfully.';
        return $response->withHeader('Location', '/settings?tab=nayax')->withStatus(302);
    }

    /**
     * Update email/SMTP settings.
     */
    public function updateEmail(Request $request, Response $response, array $args = []): Response
    {
        $data = $request->getParsedBody();

        $fields = ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'smtp_from_name'];
        foreach ($fields as $field) {
            if (isset($data[$field])) {
                $this->settings->set($field, trim($data[$field]));
            }
        }

        // Map form field name to DB key
        if (isset($data['smtp_from_address'])) {
            $this->settings->set('smtp_from_email', trim($data['smtp_from_address']));
        }

        $_SESSION['flash_success'] = 'Email settings updated successfully.';
        return $response->withHeader('Location', '/settings?tab=email')->withStatus(302);
    }

    // ─── User Management ──────────────────────────────────────────────

    /**
     * List admin users.
     */
    public function users(Request $request, Response $response, array $args = []): Response
    {
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $users = $this->db->fetchAll(
            "SELECT u.*, r.name AS role_name
             FROM users u
             LEFT JOIN roles r ON u.role_id = r.id
             ORDER BY u.full_name ASC"
        );

        return $this->twig->render($response, 'admin/settings/users.twig', [
            'active_page' => 'settings',
            'auth_user' => $this->auth->user(),
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'users' => $users,
        ]);
    }

    /**
     * Show create user form.
     */
    public function createUser(Request $request, Response $response, array $args = []): Response
    {
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_error']);

        $roles = $this->db->fetchAll("SELECT * FROM roles ORDER BY name ASC");

        return $this->twig->render($response, 'admin/settings/users/create.twig', [
            'active_page' => 'settings',
            'auth_user' => $this->auth->user(),
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_error' => $flashError,
            'roles' => $roles,
        ]);
    }

    /**
     * Store a new user.
     */
    public function storeUser(Request $request, Response $response, array $args = []): Response
    {
        $data = $request->getParsedBody();

        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';
        $fullName = trim($data['full_name'] ?? '');

        if ($email === '' || $password === '' || $fullName === '') {
            $_SESSION['flash_error'] = 'Full name, email, and password are required.';
            return $response->withHeader('Location', '/settings/users/create')->withStatus(302);
        }

        // Check for duplicate email
        $existing = $this->db->fetch("SELECT id FROM users WHERE email = ?", [$email]);
        if ($existing) {
            $_SESSION['flash_error'] = 'A user with that email already exists.';
            return $response->withHeader('Location', '/settings/users/create')->withStatus(302);
        }

        $this->db->insert('users', [
            'full_name' => $fullName,
            'email' => $email,
            'password' => AuthService::hashPassword($password),
            'phone' => trim($data['phone'] ?? ''),
            'role_id' => !empty($data['role_id']) ? (int) $data['role_id'] : null,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $_SESSION['flash_success'] = 'User created successfully.';
        return $response->withHeader('Location', '/settings/users')->withStatus(302);
    }

    /**
     * Show edit user form.
     */
    public function editUser(Request $request, Response $response, array $args = []): Response
    {
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_error']);

        $userId = (int) $args['id'];
        $user = $this->db->fetch("SELECT * FROM users WHERE id = ?", [$userId]);

        if (!$user) {
            $_SESSION['flash_error'] = 'User not found.';
            return $response->withHeader('Location', '/settings/users')->withStatus(302);
        }

        $roles = $this->db->fetchAll("SELECT * FROM roles ORDER BY name ASC");

        return $this->twig->render($response, 'admin/settings/users/edit.twig', [
            'active_page' => 'settings',
            'auth_user' => $this->auth->user(),
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_error' => $flashError,
            'edit_user' => $user,
            'roles' => $roles,
        ]);
    }

    /**
     * Update a user.
     */
    public function updateUser(Request $request, Response $response, array $args = []): Response
    {
        $userId = (int) $args['id'];
        $data = $request->getParsedBody();

        $user = $this->db->fetch("SELECT * FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            $_SESSION['flash_error'] = 'User not found.';
            return $response->withHeader('Location', '/settings/users')->withStatus(302);
        }

        $email = trim($data['email'] ?? '');

        // Check for duplicate email (exclude current user)
        $existing = $this->db->fetch(
            "SELECT id FROM users WHERE email = ? AND id != ?",
            [$email, $userId]
        );
        if ($existing) {
            $_SESSION['flash_error'] = 'A user with that email already exists.';
            return $response->withHeader('Location', '/settings/users/' . $userId . '/edit')->withStatus(302);
        }

        $updateData = [
            'full_name' => trim($data['full_name'] ?? ''),
            'email' => $email,
            'phone' => trim($data['phone'] ?? ''),
            'role_id' => !empty($data['role_id']) ? (int) $data['role_id'] : null,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        // Only update password if provided
        $password = $data['password'] ?? '';
        if ($password !== '') {
            $updateData['password'] = AuthService::hashPassword($password);
        }

        $this->db->update('users', $updateData, 'id = ?', [$userId]);

        $_SESSION['flash_success'] = 'User updated successfully.';
        return $response->withHeader('Location', '/settings/users')->withStatus(302);
    }

    /**
     * Delete a user.
     */
    public function deleteUser(Request $request, Response $response, array $args = []): Response
    {
        $userId = (int) $args['id'];

        // Prevent self-deletion
        $currentUser = $this->auth->user();
        if ($currentUser && (int) $currentUser['id'] === $userId) {
            $_SESSION['flash_error'] = 'You cannot delete your own account.';
            return $response->withHeader('Location', '/settings/users')->withStatus(302);
        }

        $this->db->delete('users', 'id = ?', [$userId]);

        $_SESSION['flash_success'] = 'User deleted successfully.';
        return $response->withHeader('Location', '/settings/users')->withStatus(302);
    }

    // ─── Roles Management ─────────────────────────────────────────────

    /**
     * List/manage roles.
     */
    public function roles(Request $request, Response $response, array $args = []): Response
    {
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $roles = $this->db->fetchAll(
            "SELECT r.*, COUNT(u.id) AS user_count
             FROM roles r
             LEFT JOIN users u ON r.id = u.role_id
             GROUP BY r.id
             ORDER BY r.name ASC"
        );

        // Decode permissions JSON for display
        foreach ($roles as &$role) {
            $role['permissions_list'] = json_decode($role['permissions'] ?? '[]', true) ?: [];
        }
        unset($role);

        return $this->twig->render($response, 'admin/settings/roles.twig', [
            'active_page' => 'settings',
            'auth_user' => $this->auth->user(),
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'roles' => $roles,
        ]);
    }

    /**
     * Create a role with permissions JSON.
     */
    public function storeRole(Request $request, Response $response, array $args = []): Response
    {
        $data = $request->getParsedBody();

        $name = trim($data['name'] ?? '');
        $slug = trim($data['slug'] ?? '');
        $permissions = $data['permissions'] ?? [];

        if ($name === '' || $slug === '') {
            $_SESSION['flash_error'] = 'Role name and slug are required.';
            return $response->withHeader('Location', '/settings/roles')->withStatus(302);
        }

        if (is_string($permissions)) {
            $permissions = json_decode($permissions, true) ?: [];
        }

        $this->db->insert('roles', [
            'name' => $name,
            'slug' => $slug,
            'permissions' => json_encode(array_values($permissions)),
            'is_system' => 0,
        ]);

        $_SESSION['flash_success'] = 'Role created successfully.';
        return $response->withHeader('Location', '/settings/roles')->withStatus(302);
    }

    /**
     * Update a role.
     */
    public function updateRole(Request $request, Response $response, array $args = []): Response
    {
        $roleId = (int) $args['id'];
        $data = $request->getParsedBody();

        $role = $this->db->fetch("SELECT * FROM roles WHERE id = ?", [$roleId]);
        if (!$role) {
            $_SESSION['flash_error'] = 'Role not found.';
            return $response->withHeader('Location', '/settings/roles')->withStatus(302);
        }

        $permissions = $data['permissions'] ?? [];
        if (is_string($permissions)) {
            $permissions = json_decode($permissions, true) ?: [];
        }

        $this->db->update('roles', [
            'name' => trim($data['name'] ?? $role['name']),
            'slug' => trim($data['slug'] ?? $role['slug']),
            'permissions' => json_encode(array_values($permissions)),
        ], 'id = ?', [$roleId]);

        $_SESSION['flash_success'] = 'Role updated successfully.';
        return $response->withHeader('Location', '/settings/roles')->withStatus(302);
    }

    /**
     * Delete a role (if not a system role).
     */
    public function deleteRole(Request $request, Response $response, array $args = []): Response
    {
        $roleId = (int) $args['id'];

        $role = $this->db->fetch("SELECT * FROM roles WHERE id = ?", [$roleId]);
        if (!$role) {
            $_SESSION['flash_error'] = 'Role not found.';
            return $response->withHeader('Location', '/settings/roles')->withStatus(302);
        }

        if (!empty($role['is_system'])) {
            $_SESSION['flash_error'] = 'System roles cannot be deleted.';
            return $response->withHeader('Location', '/settings/roles')->withStatus(302);
        }

        // Check if any users are assigned to this role
        $userCount = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM users WHERE role_id = ?",
            [$roleId]
        );

        if ($userCount > 0) {
            $_SESSION['flash_error'] = "Cannot delete role: {$userCount} user(s) are still assigned to it.";
            return $response->withHeader('Location', '/settings/roles')->withStatus(302);
        }

        $this->db->delete('roles', 'id = ?', [$roleId]);

        $_SESSION['flash_success'] = 'Role deleted successfully.';
        return $response->withHeader('Location', '/settings/roles')->withStatus(302);
    }

    // ─── Job Statuses Management ──────────────────────────────────────

    /**
     * List/manage job statuses.
     */
    public function jobStatuses(Request $request, Response $response, array $args = []): Response
    {
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $statuses = $this->db->fetchAll(
            "SELECT js.*, COUNT(j.id) AS job_count
             FROM job_statuses js
             LEFT JOIN maintenance_jobs j ON js.id = j.status_id
             GROUP BY js.id
             ORDER BY js.sort_order ASC, js.name ASC"
        );

        return $this->twig->render($response, 'admin/settings/job-statuses.twig', [
            'active_page' => 'settings',
            'auth_user' => $this->auth->user(),
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'statuses' => $statuses,
        ]);
    }

    /**
     * Create a job status.
     */
    public function storeJobStatus(Request $request, Response $response, array $args = []): Response
    {
        $data = $request->getParsedBody();

        $this->db->insert('job_statuses', [
            'name' => trim($data['name'] ?? ''),
            'slug' => trim($data['slug'] ?? ''),
            'color' => trim($data['color'] ?? '#6b7280'),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_default' => !empty($data['is_default']) ? 1 : 0,
        ]);

        $_SESSION['flash_success'] = 'Job status created successfully.';
        return $response->withHeader('Location', '/settings/job-statuses')->withStatus(302);
    }

    /**
     * Update a job status.
     */
    public function updateJobStatus(Request $request, Response $response, array $args = []): Response
    {
        $statusId = (int) $args['id'];
        $data = $request->getParsedBody();

        $this->db->update('job_statuses', [
            'name' => trim($data['name'] ?? ''),
            'slug' => trim($data['slug'] ?? ''),
            'color' => trim($data['color'] ?? '#6b7280'),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_default' => !empty($data['is_default']) ? 1 : 0,
        ], 'id = ?', [$statusId]);

        $_SESSION['flash_success'] = 'Job status updated successfully.';
        return $response->withHeader('Location', '/settings/job-statuses')->withStatus(302);
    }

    /**
     * Delete a job status.
     */
    public function deleteJobStatus(Request $request, Response $response, array $args = []): Response
    {
        $statusId = (int) $args['id'];

        $jobCount = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM maintenance_jobs WHERE status_id = ?",
            [$statusId]
        );

        if ($jobCount > 0) {
            $_SESSION['flash_error'] = "Cannot delete status: {$jobCount} job(s) are using it.";
            return $response->withHeader('Location', '/settings/job-statuses')->withStatus(302);
        }

        $this->db->delete('job_statuses', 'id = ?', [$statusId]);

        $_SESSION['flash_success'] = 'Job status deleted successfully.';
        return $response->withHeader('Location', '/settings/job-statuses')->withStatus(302);
    }

    // ─── Machine Types Management ─────────────────────────────────────

    /**
     * List/manage machine types.
     */
    public function machineTypes(Request $request, Response $response, array $args = []): Response
    {
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $types = $this->db->fetchAll(
            "SELECT mt.*, COUNT(m.id) AS machine_count
             FROM machine_types mt
             LEFT JOIN machines m ON mt.id = m.machine_type_id
             GROUP BY mt.id
             ORDER BY mt.name ASC"
        );

        return $this->twig->render($response, 'admin/settings/machine-types.twig', [
            'active_page' => 'settings',
            'auth_user' => $this->auth->user(),
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'types' => $types,
        ]);
    }

    /**
     * Create a machine type.
     */
    public function storeMachineType(Request $request, Response $response, array $args = []): Response
    {
        $data = $request->getParsedBody();

        $this->db->insert('machine_types', [
            'name' => trim($data['name'] ?? ''),
            'description' => trim($data['description'] ?? ''),
        ]);

        $_SESSION['flash_success'] = 'Machine type created successfully.';
        return $response->withHeader('Location', '/settings/machine-types')->withStatus(302);
    }

    /**
     * Update a machine type.
     */
    public function updateMachineType(Request $request, Response $response, array $args = []): Response
    {
        $typeId = (int) $args['id'];
        $data = $request->getParsedBody();

        $this->db->update('machine_types', [
            'name' => trim($data['name'] ?? ''),
            'description' => trim($data['description'] ?? ''),
        ], 'id = ?', [$typeId]);

        $_SESSION['flash_success'] = 'Machine type updated successfully.';
        return $response->withHeader('Location', '/settings/machine-types')->withStatus(302);
    }

    /**
     * Delete a machine type.
     */
    public function deleteMachineType(Request $request, Response $response, array $args = []): Response
    {
        $typeId = (int) $args['id'];

        $machineCount = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM machines WHERE machine_type_id = ?",
            [$typeId]
        );

        if ($machineCount > 0) {
            $_SESSION['flash_error'] = "Cannot delete type: {$machineCount} machine(s) are using it.";
            return $response->withHeader('Location', '/settings/machine-types')->withStatus(302);
        }

        $this->db->delete('machine_types', 'id = ?', [$typeId]);

        $_SESSION['flash_success'] = 'Machine type deleted successfully.';
        return $response->withHeader('Location', '/settings/machine-types')->withStatus(302);
    }

    // ─── Profile Management ───────────────────────────────────────────

    /**
     * Show current user profile.
     */
    public function profile(Request $request, Response $response, array $args = []): Response
    {
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $currentUser = $this->auth->user();
        $user = $this->db->fetch("SELECT * FROM users WHERE id = ?", [$currentUser['id']]);

        return $this->twig->render($response, 'admin/settings/profile.twig', [
            'active_page' => 'settings',
            'auth_user' => $currentUser,
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'profile' => $user,
        ]);
    }

    /**
     * Update profile (full_name, email, phone).
     */
    public function updateProfile(Request $request, Response $response, array $args = []): Response
    {
        $currentUser = $this->auth->user();
        $data = $request->getParsedBody();

        $email = trim($data['email'] ?? '');

        // Check for duplicate email (exclude current user)
        $existing = $this->db->fetch(
            "SELECT id FROM users WHERE email = ? AND id != ?",
            [$email, $currentUser['id']]
        );
        if ($existing) {
            $_SESSION['flash_error'] = 'That email is already in use by another account.';
            return $response->withHeader('Location', '/settings/profile')->withStatus(302);
        }

        $this->db->update('users', [
            'full_name' => trim($data['full_name'] ?? ''),
            'email' => $email,
            'phone' => trim($data['phone'] ?? ''),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$currentUser['id']]);

        // Update session data
        $_SESSION['user']['full_name'] = trim($data['full_name'] ?? '');
        $_SESSION['user']['email'] = $email;
        $_SESSION['user']['phone'] = trim($data['phone'] ?? '');

        $_SESSION['flash_success'] = 'Profile updated successfully.';
        return $response->withHeader('Location', '/settings/profile')->withStatus(302);
    }

    /**
     * Change password (verify current, set new with Argon2id).
     */
    public function updatePassword(Request $request, Response $response, array $args = []): Response
    {
        $currentUser = $this->auth->user();
        $data = $request->getParsedBody();

        $currentPassword = $data['current_password'] ?? '';
        $newPassword = $data['new_password'] ?? '';
        $confirmPassword = $data['confirm_password'] ?? '';

        if ($newPassword === '' || $currentPassword === '') {
            $_SESSION['flash_error'] = 'All password fields are required.';
            return $response->withHeader('Location', '/settings/profile')->withStatus(302);
        }

        if ($newPassword !== $confirmPassword) {
            $_SESSION['flash_error'] = 'New password and confirmation do not match.';
            return $response->withHeader('Location', '/settings/profile')->withStatus(302);
        }

        // Verify current password
        $user = $this->db->fetch("SELECT password FROM users WHERE id = ?", [$currentUser['id']]);
        if (!$user || !password_verify($currentPassword, $user['password'])) {
            $_SESSION['flash_error'] = 'Current password is incorrect.';
            return $response->withHeader('Location', '/settings/profile')->withStatus(302);
        }

        $this->db->update('users', [
            'password' => AuthService::hashPassword($newPassword),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$currentUser['id']]);

        $_SESSION['flash_success'] = 'Password changed successfully.';
        return $response->withHeader('Location', '/settings/profile')->withStatus(302);
    }

    /**
     * Show purge data page.
     */
    public function purgeData(Request $request, Response $response): Response
    {
        if (!$this->auth->hasPermission('*')) {
            $_SESSION['flash_error'] = 'Access denied.';
            return $response->withHeader('Location', '/settings')->withStatus(302);
        }

        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $counts = [
            'revenue' => (int) $this->db->fetchColumn("SELECT COUNT(*) FROM revenue"),
            'commission_payments' => (int) $this->db->fetchColumn("SELECT COUNT(*) FROM commission_payments"),
            'commission_line_items' => (int) $this->db->fetchColumn("SELECT COUNT(*) FROM commission_line_items"),
            'nayax_transactions' => (int) $this->db->fetchColumn("SELECT COUNT(*) FROM nayax_transactions"),
        ];

        return $this->twig->render($response, 'admin/settings/purge-data.twig', [
            'active_page' => 'purge-data',
            'auth_user' => $this->auth->user(),
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'counts' => $counts,
        ]);
    }

    /**
     * Execute data purge.
     */
    public function executePurge(Request $request, Response $response): Response
    {
        if (!$this->auth->hasPermission('*')) {
            $_SESSION['flash_error'] = 'Access denied.';
            return $response->withHeader('Location', '/settings')->withStatus(302);
        }

        $data = $request->getParsedBody();
        $targets = $data['targets'] ?? [];
        $confirm = $data['confirm'] ?? '';

        if ($confirm !== 'PURGE') {
            $_SESSION['flash_error'] = 'You must type PURGE to confirm.';
            return $response->withHeader('Location', '/settings/purge-data')->withStatus(302);
        }

        if (empty($targets)) {
            $_SESSION['flash_error'] = 'No data selected to purge.';
            return $response->withHeader('Location', '/settings/purge-data')->withStatus(302);
        }

        $purged = [];

        if (in_array('nayax_transactions', $targets)) {
            $count = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM nayax_transactions");
            $this->db->execute("DELETE FROM nayax_transactions");
            $purged[] = "{$count} Nayax transactions";
        }

        if (in_array('commission_line_items', $targets)) {
            $count = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM commission_line_items");
            $this->db->execute("DELETE FROM commission_line_items");
            $purged[] = "{$count} commission line items";
        }

        if (in_array('commission_payments', $targets)) {
            // Delete line items first to avoid orphans
            $liCount = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM commission_line_items");
            if ($liCount > 0) {
                $this->db->execute("DELETE FROM commission_line_items");
                $purged[] = "{$liCount} commission line items";
            }
            $count = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM commission_payments");
            $this->db->execute("DELETE FROM commission_payments");
            $purged[] = "{$count} commission payments";
        }

        if (in_array('revenue', $targets)) {
            $count = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM revenue");
            $this->db->execute("DELETE FROM revenue");
            $purged[] = "{$count} revenue records";
        }

        $authUser = $this->auth->user();
        try {
            $this->db->insert('activity_logs', [
                'action' => 'purge_data',
                'entity_type' => 'system',
                'entity_id' => 0,
                'user_id' => $authUser['id'] ?? null,
                'new_values' => json_encode(['purged' => $purged, 'targets' => $targets]),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (\Exception $e) {
            error_log("Purge audit log error: " . $e->getMessage());
        }

        $_SESSION['flash_success'] = 'Purged: ' . implode(', ', $purged) . '.';
        return $response->withHeader('Location', '/settings/purge-data')->withStatus(302);
    }
}
