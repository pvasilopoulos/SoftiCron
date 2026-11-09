<?php

declare(strict_types=1);

use App\Controllers as C;
use FastRoute\RouteCollector;

return static function (RouteCollector $r): void {
    // Auth
    $r->get('/login', [C\AuthController::class, 'loginForm']);
    $r->post('/login', [C\AuthController::class, 'login']);
    $r->post('/logout', [C\AuthController::class, 'logout']);
    $r->get('/invite/{token}', [C\AuthController::class, 'inviteForm']);
    $r->post('/invite/{token}', [C\AuthController::class, 'acceptInvite']);
    $r->post('/tenant/switch', [C\AuthController::class, 'switchTenant']);

    // Dashboard
    $r->get('/', [C\DashboardController::class, 'index']);
    $r->get('/no-tenant', [C\DashboardController::class, 'noTenant']);

    // Jobs
    $r->get('/jobs', [C\JobController::class, 'index']);
    $r->get('/jobs/new', [C\JobController::class, 'create']);
    $r->post('/jobs', [C\JobController::class, 'store']);
    $r->post('/jobs/test', [C\JobController::class, 'test']);
    $r->get('/jobs/{id:\d+}/edit', [C\JobController::class, 'edit']);
    $r->post('/jobs/{id:\d+}', [C\JobController::class, 'update']);
    $r->post('/jobs/{id:\d+}/delete', [C\JobController::class, 'delete']);
    $r->post('/jobs/{id:\d+}/toggle', [C\JobController::class, 'toggle']);
    $r->post('/jobs/{id:\d+}/run', [C\JobController::class, 'runNow']);
    $r->post('/jobs/{id:\d+}/duplicate', [C\JobController::class, 'duplicate']);
    $r->get('/api/cron/preview', [C\JobController::class, 'cronPreview']);

    // Runs
    $r->get('/runs', [C\RunController::class, 'index']);
    $r->get('/runs/{id:\d+}', [C\RunController::class, 'show']);
    $r->post('/runs/{id:\d+}/retry', [C\RunController::class, 'retry']);

    // Connectors
    $r->get('/connectors', [C\ConnectorController::class, 'index']);
    $r->get('/connectors/new', [C\ConnectorController::class, 'create']);
    $r->post('/connectors', [C\ConnectorController::class, 'store']);
    $r->get('/connectors/{id:\d+}/edit', [C\ConnectorController::class, 'edit']);
    $r->post('/connectors/{id:\d+}', [C\ConnectorController::class, 'update']);
    $r->post('/connectors/{id:\d+}/delete', [C\ConnectorController::class, 'delete']);
    $r->post('/connectors/{id:\d+}/test', [C\ConnectorController::class, 'test']);

    // Message templates
    $r->get('/templates', [C\TemplateController::class, 'index']);
    $r->get('/templates/new', [C\TemplateController::class, 'create']);
    $r->post('/templates', [C\TemplateController::class, 'store']);
    $r->post('/templates/preview', [C\TemplateController::class, 'preview']);
    $r->get('/templates/{id:\d+}/edit', [C\TemplateController::class, 'edit']);
    $r->post('/templates/{id:\d+}', [C\TemplateController::class, 'update']);
    $r->post('/templates/{id:\d+}/delete', [C\TemplateController::class, 'delete']);

    // Notifications (devices / preferences)
    $r->get('/notifications', [C\NotificationController::class, 'index']);
    $r->post('/notifications/prefs', [C\NotificationController::class, 'savePrefs']);
    $r->post('/notifications/subscribe', [C\NotificationController::class, 'subscribe']);
    $r->post('/notifications/unsubscribe', [C\NotificationController::class, 'unsubscribe']);
    $r->post('/notifications/devices/{id:\d+}/delete', [C\NotificationController::class, 'deleteDevice']);
    $r->post('/notifications/test', [C\NotificationController::class, 'test']);

    // Users & roles
    $r->get('/users', [C\UserController::class, 'index']);
    $r->post('/users/invite', [C\UserController::class, 'invite']);
    $r->get('/users/new', [C\UserController::class, 'create']);
    $r->post('/users', [C\UserController::class, 'store']);
    $r->get('/users/{id:\d+}/edit', [C\UserController::class, 'edit']);
    $r->post('/users/{id:\d+}', [C\UserController::class, 'update']);
    $r->post('/users/{id:\d+}/role', [C\UserController::class, 'changeRole']);
    $r->post('/users/{id:\d+}/remove', [C\UserController::class, 'remove']);
    $r->post('/invitations/{id:\d+}/revoke', [C\UserController::class, 'revokeInvite']);
    $r->get('/roles', [C\RoleController::class, 'index']);
    $r->get('/roles/new', [C\RoleController::class, 'create']);
    $r->post('/roles', [C\RoleController::class, 'store']);
    $r->post('/roles/matrix', [C\RoleController::class, 'matrix']);
    $r->get('/roles/{id:\d+}/edit', [C\RoleController::class, 'edit']);
    $r->post('/roles/{id:\d+}', [C\RoleController::class, 'update']);
    $r->post('/roles/{id:\d+}/delete', [C\RoleController::class, 'delete']);

    // Audit, settings, profile
    $r->get('/audit', [C\AuditController::class, 'index']);
    $r->get('/settings', [C\SettingsController::class, 'index']);
    $r->post('/settings', [C\SettingsController::class, 'update']);
    $r->post('/settings/branding', [C\SettingsController::class, 'branding']);
    $r->get('/branding/{id:\d+}/logo', [C\SettingsController::class, 'logo']);
    $r->get('/profile', [C\ProfileController::class, 'index']);
    $r->post('/profile', [C\ProfileController::class, 'update']);
    $r->post('/profile/password', [C\ProfileController::class, 'password']);

    // Platform admin
    $r->get('/admin/tenants', [C\AdminController::class, 'tenants']);
    $r->get('/admin/tenants/new', [C\AdminController::class, 'createTenant']);
    $r->post('/admin/tenants', [C\AdminController::class, 'storeTenant']);
    $r->get('/admin/tenants/{id:\d+}/edit', [C\AdminController::class, 'editTenant']);
    $r->post('/admin/tenants/{id:\d+}', [C\AdminController::class, 'updateTenant']);
    $r->post('/admin/tenants/{id:\d+}/enter', [C\AdminController::class, 'enterTenant']);
    $r->get('/admin/system', [C\AdminController::class, 'system']);

    // PWA
    $r->get('/manifest.webmanifest', [C\PwaController::class, 'manifest']);
    $r->get('/sw.js', [C\PwaController::class, 'serviceWorker']);
};
