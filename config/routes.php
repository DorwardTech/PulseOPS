<?php

declare(strict_types=1);

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\MachinesController;
use App\Controllers\CustomersController;
use App\Controllers\JobsController;
use App\Controllers\RevenueController;
use App\Controllers\CommissionsController;
use App\Controllers\NayaxController;
use App\Controllers\AnalyticsController;
use App\Controllers\SettingsController;
use App\Controllers\LogViewerController;
use App\Controllers\PortalController;
use App\Middleware\AuthMiddleware;
use App\Middleware\PortalAuthMiddleware;
use App\Middleware\CsrfMiddleware;

return function (App $app) {
    // ── Health check (no Twig/DB dependency) ────────────
    $app->get('/healthz', function ($request, $response) {
        $response->getBody()->write(json_encode(['status' => 'ok', 'time' => date('c')]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // ── Auth (public) ─────────────────────────────────────
    $app->get('/login', [AuthController::class, 'showLogin']);
    $app->post('/login', [AuthController::class, 'login']);
    $app->get('/logout', [AuthController::class, 'logout']);

    // ── Admin routes (authenticated) ──────────────────────
    $app->group('', function (RouteCollectorProxy $group) {

        // Dashboard
        $group->get('/', [DashboardController::class, 'index']);
        $group->get('/dashboard', [DashboardController::class, 'index']);

        // Machines
        $group->get('/machines', [MachinesController::class, 'index']);
        $group->get('/machines/create', [MachinesController::class, 'create']);
        $group->post('/machines', [MachinesController::class, 'store']);
        $group->get('/machines/{id:[0-9]+}', [MachinesController::class, 'show']);
        $group->get('/machines/{id:[0-9]+}/edit', [MachinesController::class, 'edit']);
        $group->post('/machines/{id:[0-9]+}', [MachinesController::class, 'update']);
        $group->post('/machines/{id:[0-9]+}/delete', [MachinesController::class, 'delete']);
        $group->delete('/machines/{id:[0-9]+}', [MachinesController::class, 'delete']);
        $group->get('/machines/import', [MachinesController::class, 'showImport']);
        $group->post('/machines/import', [MachinesController::class, 'import']);
        $group->post('/machines/{id:[0-9]+}/photos', [MachinesController::class, 'uploadPhoto']);
        $group->post('/machines/{id:[0-9]+}/photos/{photoId:[0-9]+}/delete', [MachinesController::class, 'deletePhoto']);
        $group->delete('/machines/{id:[0-9]+}/photos/{photoId:[0-9]+}', [MachinesController::class, 'deletePhoto']);

        // Customers
        $group->get('/customers', [CustomersController::class, 'index']);
        $group->get('/customers/create', [CustomersController::class, 'create']);
        $group->post('/customers', [CustomersController::class, 'store']);
        $group->get('/customers/{id:[0-9]+}', [CustomersController::class, 'show']);
        $group->get('/customers/{id:[0-9]+}/edit', [CustomersController::class, 'edit']);
        $group->post('/customers/{id:[0-9]+}', [CustomersController::class, 'update']);
        $group->get('/customers/import', [CustomersController::class, 'showImport']);
        $group->post('/customers/import', [CustomersController::class, 'import']);
        $group->post('/customers/{id:[0-9]+}/delete', [CustomersController::class, 'delete']);
        $group->delete('/customers/{id:[0-9]+}', [CustomersController::class, 'delete']);
        $group->get('/customers/{id:[0-9]+}/portal', [CustomersController::class, 'portalUsers']);
        $group->post('/customers/{id:[0-9]+}/portal', [CustomersController::class, 'createPortalUser']);
        $group->post('/customers/{id:[0-9]+}/portal/{userId:[0-9]+}/toggle', [CustomersController::class, 'togglePortalUser']);

        // Jobs
        $group->get('/jobs', [JobsController::class, 'index']);
        $group->get('/jobs/create', [JobsController::class, 'create']);
        $group->post('/jobs', [JobsController::class, 'store']);
        $group->get('/jobs/{id:[0-9]+}', [JobsController::class, 'show']);
        $group->get('/jobs/{id:[0-9]+}/edit', [JobsController::class, 'edit']);
        $group->post('/jobs/{id:[0-9]+}', [JobsController::class, 'update']);
        $group->post('/jobs/{id:[0-9]+}/delete', [JobsController::class, 'delete']);
        $group->delete('/jobs/{id:[0-9]+}', [JobsController::class, 'delete']);
        $group->post('/jobs/{id:[0-9]+}/notes', [JobsController::class, 'addNote']);
        $group->post('/jobs/{id:[0-9]+}/photos', [JobsController::class, 'uploadPhoto']);
        $group->post('/jobs/{id:[0-9]+}/photos/{photoId:[0-9]+}/delete', [JobsController::class, 'deletePhoto']);
        $group->delete('/jobs/{id:[0-9]+}/photos/{photoId:[0-9]+}', [JobsController::class, 'deletePhoto']);
        $group->post('/jobs/{id:[0-9]+}/parts', [JobsController::class, 'addPart']);
        $group->post('/jobs/{id:[0-9]+}/parts/{partId:[0-9]+}/delete', [JobsController::class, 'deletePart']);
        $group->delete('/jobs/{id:[0-9]+}/parts/{partId:[0-9]+}', [JobsController::class, 'deletePart']);
        $group->post('/jobs/{id:[0-9]+}/status', [JobsController::class, 'updateStatus']);

        // Revenue
        $group->get('/revenue', [RevenueController::class, 'index']);
        $group->get('/revenue/create', [RevenueController::class, 'create']);
        $group->post('/revenue', [RevenueController::class, 'store']);
        $group->get('/revenue/{id:[0-9]+}', [RevenueController::class, 'show']);
        $group->get('/revenue/{id:[0-9]+}/edit', [RevenueController::class, 'edit']);
        $group->post('/revenue/{id:[0-9]+}', [RevenueController::class, 'update']);
        $group->post('/revenue/{id:[0-9]+}/delete', [RevenueController::class, 'delete']);
        $group->delete('/revenue/{id:[0-9]+}', [RevenueController::class, 'delete']);
        $group->post('/revenue/{id:[0-9]+}/approve', [RevenueController::class, 'approve']);
        $group->get('/revenue/by-machine', [RevenueController::class, 'byMachine']);
        $group->get('/revenue/import', [RevenueController::class, 'showImport']);
        $group->post('/revenue/import', [RevenueController::class, 'import']);

        // Commissions
        $group->get('/commissions', [CommissionsController::class, 'index']);
        $group->get('/commissions/generate', [CommissionsController::class, 'showGenerate']);
        $group->post('/commissions/generate', [CommissionsController::class, 'processGenerate']);
        $group->get('/commissions/{id:[0-9]+}', [CommissionsController::class, 'show']);
        $group->post('/commissions/{id:[0-9]+}/approve', [CommissionsController::class, 'approve']);
        $group->post('/commissions/{id:[0-9]+}/pay', [CommissionsController::class, 'markPaid']);
        $group->post('/commissions/{id:[0-9]+}/void', [CommissionsController::class, 'void']);
        $group->post('/commissions/{id:[0-9]+}/line-items', [CommissionsController::class, 'addLineItem']);
        $group->post('/commissions/{id:[0-9]+}/line-items/{itemId:[0-9]+}/delete', [CommissionsController::class, 'deleteLineItem']);
        $group->delete('/commissions/{id:[0-9]+}/line-items/{itemId:[0-9]+}', [CommissionsController::class, 'deleteLineItem']);
        $group->post('/commissions/{id:[0-9]+}/recalculate', [CommissionsController::class, 'recalculate']);

        // Nayax
        $group->get('/nayax', [NayaxController::class, 'index']);
        $group->get('/nayax/devices', [NayaxController::class, 'devices']);
        $group->post('/nayax/devices/sync', [NayaxController::class, 'syncDevices']);
        $group->post('/nayax/sync-devices', [NayaxController::class, 'syncDevices']);
        $group->post('/nayax/devices/bulk-link', [NayaxController::class, 'bulkLinkDevices']);
        $group->post('/nayax/devices/{id:[0-9]+}/link', [NayaxController::class, 'linkDevice']);
        $group->post('/nayax/devices/{id:[0-9]+}/unlink', [NayaxController::class, 'unlinkDevice']);
        $group->get('/nayax/transactions', [NayaxController::class, 'transactions']);
        $group->get('/nayax/import', [NayaxController::class, 'showImport']);
        $group->post('/nayax/import', [NayaxController::class, 'processImport']);
        $group->post('/nayax/import-transactions', [NayaxController::class, 'processImport']);
        $group->post('/nayax/reaggregate', [NayaxController::class, 'reaggregate']);
        $group->get('/nayax/diagnostics', [NayaxController::class, 'diagnostics']);

        // Analytics
        $group->get('/analytics', [AnalyticsController::class, 'index']);
        $group->get('/analytics/revenue', [AnalyticsController::class, 'revenue']);
        $group->get('/analytics/machines', [AnalyticsController::class, 'machines']);
        $group->get('/analytics/customers', [AnalyticsController::class, 'customers']);
        $group->get('/analytics/export', [AnalyticsController::class, 'export']);

        // Settings
        $group->get('/settings', [SettingsController::class, 'index']);
        $group->post('/settings/general', [SettingsController::class, 'updateGeneral']);
        $group->post('/settings/commission', [SettingsController::class, 'updateCommission']);
        $group->post('/settings/nayax', [SettingsController::class, 'updateNayax']);
        $group->post('/settings/email', [SettingsController::class, 'updateEmail']);
        $group->get('/settings/users', [SettingsController::class, 'users']);
        $group->get('/settings/users/create', [SettingsController::class, 'createUser']);
        $group->post('/settings/users', [SettingsController::class, 'storeUser']);
        $group->get('/settings/users/{id:[0-9]+}/edit', [SettingsController::class, 'editUser']);
        $group->post('/settings/users/{id:[0-9]+}', [SettingsController::class, 'updateUser']);
        $group->post('/settings/users/{id:[0-9]+}/delete', [SettingsController::class, 'deleteUser']);
        $group->delete('/settings/users/{id:[0-9]+}', [SettingsController::class, 'deleteUser']);
        $group->get('/settings/roles', [SettingsController::class, 'roles']);
        $group->post('/settings/roles', [SettingsController::class, 'storeRole']);
        $group->post('/settings/roles/{id:[0-9]+}', [SettingsController::class, 'updateRole']);
        $group->post('/settings/roles/{id:[0-9]+}/delete', [SettingsController::class, 'deleteRole']);
        $group->delete('/settings/roles/{id:[0-9]+}', [SettingsController::class, 'deleteRole']);
        $group->get('/settings/job-statuses', [SettingsController::class, 'jobStatuses']);
        $group->post('/settings/job-statuses', [SettingsController::class, 'storeJobStatus']);
        $group->post('/settings/job-statuses/{id:[0-9]+}', [SettingsController::class, 'updateJobStatus']);
        $group->post('/settings/job-statuses/{id:[0-9]+}/delete', [SettingsController::class, 'deleteJobStatus']);
        $group->delete('/settings/job-statuses/{id:[0-9]+}', [SettingsController::class, 'deleteJobStatus']);
        $group->get('/settings/machine-types', [SettingsController::class, 'machineTypes']);
        $group->post('/settings/machine-types', [SettingsController::class, 'storeMachineType']);
        $group->post('/settings/machine-types/{id:[0-9]+}', [SettingsController::class, 'updateMachineType']);
        $group->post('/settings/machine-types/{id:[0-9]+}/delete', [SettingsController::class, 'deleteMachineType']);
        $group->delete('/settings/machine-types/{id:[0-9]+}', [SettingsController::class, 'deleteMachineType']);
        $group->get('/settings/profile', [SettingsController::class, 'profile']);
        $group->post('/settings/profile', [SettingsController::class, 'updateProfile']);
        $group->post('/settings/profile/password', [SettingsController::class, 'updatePassword']);

        // Purge Data (admin only — permission checked in controller)
        $group->get('/settings/purge-data', [SettingsController::class, 'purgeData']);
        $group->post('/settings/purge-data', [SettingsController::class, 'executePurge']);

        // Logs (admin only — permission checked in controller)
        $group->get('/logs', [LogViewerController::class, 'index']);
        $group->post('/logs/php/clear', [LogViewerController::class, 'clearPhpLog']);
        $group->post('/logs/api/clear', [LogViewerController::class, 'clearApiLogs']);

    })->add(CsrfMiddleware::class)->add(AuthMiddleware::class);

    // ── API endpoints (key-authenticated, no session) ─────
    $app->get('/api/nayax/cron', [NayaxController::class, 'cronImport']);

    // ── Customer Portal (portal auth) ─────────────────────
    $app->get('/portal/login', [PortalController::class, 'showLogin']);
    $app->post('/portal/login', [PortalController::class, 'login']);
    $app->get('/portal/logout', [PortalController::class, 'logout']);

    $app->group('/portal', function (RouteCollectorProxy $group) {
        $group->get('/dashboard', [PortalController::class, 'dashboard']);
        $group->get('/machines', [PortalController::class, 'machines']);
        $group->get('/machines/{id:[0-9]+}', [PortalController::class, 'machineDetail']);
        $group->get('/machines/{id:[0-9]+}/report-issue', [PortalController::class, 'showReportIssue']);
        $group->post('/machines/{id:[0-9]+}/report-issue', [PortalController::class, 'reportIssue']);
        $group->get('/revenue', [PortalController::class, 'revenue']);
        $group->get('/commissions', [PortalController::class, 'commissions']);
        $group->get('/commissions/{id:[0-9]+}', [PortalController::class, 'commissionDetail']);
        $group->get('/jobs', [PortalController::class, 'jobs']);
        $group->get('/jobs/{id:[0-9]+}', [PortalController::class, 'jobDetail']);
        $group->post('/jobs/{id:[0-9]+}/notes', [PortalController::class, 'addJobNote']);
        $group->get('/settings', [PortalController::class, 'settings']);
        $group->post('/settings/profile', [PortalController::class, 'updateProfile']);
        $group->post('/settings/password', [PortalController::class, 'updatePassword']);
        $group->post('/settings/bank', [PortalController::class, 'updateBank']);
    })->add(CsrfMiddleware::class)->add(PortalAuthMiddleware::class);
};
