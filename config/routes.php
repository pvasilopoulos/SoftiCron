<?php

declare(strict_types=1);

use App\Controllers as C;
use FastRoute\RouteCollector;

return static function (RouteCollector $r): void {
    // Auth
    $r->get('/login', [C\AuthController::class, 'loginForm']);
    $r->post('/login', [C\AuthController::class, 'login']);
    $r->get('/csrf-token', [C\AuthController::class, 'csrfToken']);
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
    $r->post('/jobs/bulk', [C\JobController::class, 'bulk']);
    $r->get('/jobs/{id:\d+}/edit', [C\JobController::class, 'edit']);
    $r->post('/jobs/{id:\d+}', [C\JobController::class, 'update']);
    $r->post('/jobs/{id:\d+}/delete', [C\JobController::class, 'delete']);
    $r->post('/jobs/{id:\d+}/toggle', [C\JobController::class, 'toggle']);
    $r->post('/jobs/{id:\d+}/run', [C\JobController::class, 'runNow']);
    $r->post('/jobs/{id:\d+}/duplicate', [C\JobController::class, 'duplicate']);
    $r->get('/api/cron/preview', [C\JobController::class, 'cronPreview']);

    // Incidents: "Το είδα"
    $r->post('/jobs/{id:\d+}/ack', [C\IncidentController::class, 'ack']);
    $r->post('/jobs/{id:\d+}/unack', [C\IncidentController::class, 'unack']);
    $r->get('/ack/{id:\d+}/{token:[a-f0-9]{32}}', [C\IncidentController::class, 'linkForm']);
    $r->post('/ack/{id:\d+}/{token:[a-f0-9]{32}}', [C\IncidentController::class, 'linkAck']);

    // Runs
    $r->get('/calendar', [C\CalendarController::class, 'index']);
    $r->get('/runs', [C\RunController::class, 'index']);
    $r->get('/runs/export', [C\RunController::class, 'export']);
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
    $r->post('/connectors/{id:\d+}/default', [C\ConnectorController::class, 'makeDefault']);
    $r->post('/connectors/{id:\d+}/duplicate', [C\ConnectorController::class, 'duplicate']);

    // Message templates
    $r->get('/templates', [C\TemplateController::class, 'index']);
    $r->get('/templates/new', [C\TemplateController::class, 'create']);
    $r->post('/templates', [C\TemplateController::class, 'store']);
    $r->post('/templates/preview', [C\TemplateController::class, 'preview']);
    $r->get('/templates/{id:\d+}/edit', [C\TemplateController::class, 'edit']);
    $r->post('/templates/{id:\d+}', [C\TemplateController::class, 'update']);
    $r->post('/templates/{id:\d+}/delete', [C\TemplateController::class, 'delete']);
    $r->post('/templates/{id:\d+}/duplicate', [C\TemplateController::class, 'duplicate']);
    $r->post('/templates/{id:\d+}/default', [C\TemplateController::class, 'makeDefault']);
    $r->post('/templates/{id:\d+}/reset', [C\TemplateController::class, 'reset']);
    $r->post('/templates/{id:\d+}/test', [C\TemplateController::class, 'test']);

    // Notifications (devices / preferences)
    $r->get('/notifications', [C\NotificationController::class, 'index']);
    $r->get('/notifications/history', [C\NotificationController::class, 'history']);
    $r->post('/notifications/prefs', [C\NotificationController::class, 'savePrefs']);
    $r->post('/notifications/subscribe', [C\NotificationController::class, 'subscribe']);
    $r->post('/notifications/unsubscribe', [C\NotificationController::class, 'unsubscribe']);
    $r->post('/notifications/devices/cleanup', [C\NotificationController::class, 'cleanupDevices']);
    $r->post('/notifications/devices/{id:\d+}/delete', [C\NotificationController::class, 'deleteDevice']);
    $r->post('/notifications/devices/{id:\d+}', [C\NotificationController::class, 'updateDevice']);
    $r->post('/notifications/devices/{id:\d+}/test', [C\NotificationController::class, 'testDevice']);
    $r->post('/notifications/test', [C\NotificationController::class, 'test']);
    // In-app notification center (bell)
    $r->get('/feed', [C\FeedController::class, 'index']);
    $r->get('/feed/count', [C\FeedController::class, 'count']);
    $r->post('/feed/read-all', [C\FeedController::class, 'readAll']);
    $r->post('/feed/clear-read', [C\FeedController::class, 'clearRead']);
    $r->post('/feed/{id:\d+}/read', [C\FeedController::class, 'read']);
    $r->post('/feed/{id:\d+}/unread', [C\FeedController::class, 'unread']);
    $r->post('/feed/{id:\d+}/delete', [C\FeedController::class, 'delete']);

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
    $r->get('/devices', [C\DeviceController::class, 'index']);
    $r->post('/devices/{id:\d+}/toggle', [C\DeviceController::class, 'toggle']);
    $r->post('/devices/{id:\d+}/test', [C\DeviceController::class, 'test']);
    $r->post('/devices/{id:\d+}/delete', [C\DeviceController::class, 'delete']);
    $r->get('/roles', [C\RoleController::class, 'index']);
    $r->get('/roles/new', [C\RoleController::class, 'create']);
    $r->post('/roles', [C\RoleController::class, 'store']);
    $r->post('/roles/matrix', [C\RoleController::class, 'matrix']);
    $r->get('/roles/{id:\d+}/edit', [C\RoleController::class, 'edit']);
    $r->post('/roles/{id:\d+}', [C\RoleController::class, 'update']);
    $r->post('/roles/{id:\d+}/delete', [C\RoleController::class, 'delete']);

    // Reports & SLA
    $r->get('/reports', [C\ReportController::class, 'index']);
    $r->get('/reports/export', [C\ReportController::class, 'export']);
    $r->post('/reports/email', [C\ReportController::class, 'email']);

    // Variables & secrets
    $r->get('/variables', [C\VariableController::class, 'index']);
    $r->post('/variables', [C\VariableController::class, 'store']);
    $r->post('/variables/{id:\d+}', [C\VariableController::class, 'update']);
    $r->post('/variables/{id:\d+}/delete', [C\VariableController::class, 'delete']);

    // Audit, settings, profile
    $r->get('/audit', [C\AuditController::class, 'index']);
    $r->get('/audit/export', [C\AuditController::class, 'export']);
    $r->get('/settings', [C\SettingsController::class, 'index']);
    $r->post('/settings', [C\SettingsController::class, 'update']);
    $r->post('/settings/branding', [C\SettingsController::class, 'branding']);
    $r->post('/settings/menu', [C\SettingsController::class, 'menu']);
    $r->post('/settings/status', [C\SettingsController::class, 'status']);
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
    $r->post('/admin/system/watchdog', [C\AdminController::class, 'watchdogSave']);
    $r->post('/admin/system/watchdog/test', [C\AdminController::class, 'watchdogTest']);
    $r->get('/health', [C\HealthController::class, 'health']);

    // Public status page
    $r->get('/status/{slug:[a-z0-9\-]+}.json', [C\StatusController::class, 'feed']);
    $r->get('/status/{slug:[a-z0-9\-]+}.svg', [C\StatusController::class, 'badge']);
    $r->get('/status/{slug:[a-z0-9\-]+}.rss', [C\StatusController::class, 'rss']);
    $r->get('/status/{slug:[a-z0-9\-]+}/embed', [C\StatusController::class, 'embed']);
    $r->get('/status/{slug:[a-z0-9\-]+}', [C\StatusController::class, 'show']);

    // Status page administration
    $r->get('/status-page', [C\StatusAdminController::class, 'index']);
    $r->post('/status-page/settings', [C\StatusAdminController::class, 'settings']);
    $r->post('/status-page/components', [C\StatusAdminController::class, 'components']);
    $r->post('/status-page/notices', [C\StatusAdminController::class, 'storeNotice']);
    $r->post('/status-page/notices/{id:\d+}', [C\StatusAdminController::class, 'updateNotice']);
    $r->post('/status-page/notices/{id:\d+}/resolve', [C\StatusAdminController::class, 'resolveNotice']);
    $r->post('/status-page/notices/{id:\d+}/delete', [C\StatusAdminController::class, 'deleteNotice']);

    // Heartbeat pings (public, GET or POST)
    $r->addRoute(['GET', 'POST'], '/ping/{token:[a-f0-9]{32}}', [C\PingController::class, 'success']);
    $r->addRoute(['GET', 'POST'], '/ping/{token:[a-f0-9]{32}}/start', [C\PingController::class, 'start']);
    $r->addRoute(['GET', 'POST'], '/ping/{token:[a-f0-9]{32}}/fail', [C\PingController::class, 'fail']);

    // REST API v1 (stateless, Bearer token)
    $r->addGroup('/api/v1', function (RouteCollector $r): void {
        $r->get('/me', [C\ApiController::class, 'me']);
        $r->get('/jobs', [C\ApiController::class, 'jobs']);
        $r->post('/jobs', [C\ApiController::class, 'createJob']);
        $r->get('/jobs/{id:\d+}', [C\ApiController::class, 'job']);
        $r->addRoute(['PUT', 'PATCH'], '/jobs/{id:\d+}', [C\ApiController::class, 'updateJob']);
        $r->delete('/jobs/{id:\d+}', [C\ApiController::class, 'deleteJob']);
        $r->post('/jobs/{id:\d+}/run', [C\ApiController::class, 'run']);
        $r->post('/jobs/{id:\d+}/pause', [C\ApiController::class, 'pause']);
        $r->post('/jobs/{id:\d+}/resume', [C\ApiController::class, 'resume']);
        $r->get('/runs', [C\ApiController::class, 'runs']);
        $r->get('/runs/{id:\d+}', [C\ApiController::class, 'runShow']);
        $r->get('/reports/summary', [C\ApiController::class, 'report']);
    });

    // API tokens & docs (UI)
    $r->get('/api-tokens', [C\ApiTokenController::class, 'index']);
    $r->post('/api-tokens', [C\ApiTokenController::class, 'store']);
    $r->post('/api-tokens/{id:\d+}/revoke', [C\ApiTokenController::class, 'revoke']);
    $r->get('/api-docs', [C\ApiTokenController::class, 'docs']);

    // Job import/export & versions
    $r->get('/jobs/export', [C\JobIoController::class, 'export']);
    $r->get('/jobs/import', [C\JobIoController::class, 'importForm']);
    $r->post('/jobs/import', [C\JobIoController::class, 'import']);
    $r->get('/jobs/{id:\d+}/versions', [C\JobIoController::class, 'versions']);
    $r->post('/jobs/{id:\d+}/versions/{vid:\d+}/restore', [C\JobIoController::class, 'restore']);

    // PWA
    $r->get('/manifest.webmanifest', [C\PwaController::class, 'manifest']);
    $r->get('/sw.js', [C\PwaController::class, 'serviceWorker']);
};
