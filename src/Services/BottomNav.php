<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\Auth;

/** The mobile footer menu: up to 4 configurable items per tenant, then "Μενού". */
final class BottomNav
{
    public const SLOTS = 4;
    public const DEFAULT = ['home', 'jobs', 'runs', 'notifications'];

    /** key => [label, url path, icon, nav key (for active state), permission|null] */
    public const ITEMS = [
        'home' => ['Αρχική', '/', 'home', 'dashboard', null],
        'jobs' => ['Jobs', 'jobs', 'jobs', 'jobs', 'jobs.view'],
        'new_job' => ['Νέο job', 'jobs/new', 'plus', 'jobs_new', 'jobs.create'],
        'runs' => ['Εκτελέσεις', 'runs', 'runs', 'runs', 'runs.view'],
        'calendar' => ['Ημερολόγιο', 'calendar', 'calendar', 'calendar', 'calendar.view'],
        'failed' => ['Αποτυχίες', 'runs?status=failed', 'x', 'runs_failed', 'runs.view'],
        'notifications' => ['Alerts', 'notifications', 'bell', 'notifications', null],
        'templates' => ['Templates', 'templates', 'templates', 'templates', 'templates.view'],
        'connectors' => ['Connectors', 'connectors', 'connectors', 'connectors', 'connectors.view'],
        'users' => ['Χρήστες', 'users', 'users', 'users', 'users.view'],
        'audit' => ['Audit', 'audit', 'audit', 'audit', 'audit.view'],
        'settings' => ['Ρυθμίσεις', 'settings', 'settings', 'settings', \App\Controllers\SettingsController::SETTINGS_PERMS],
        'profile' => ['Προφίλ', 'profile', 'user', 'profile', null],
    ];

    /** Configured keys for a tenant (validated, unique, max 4). */
    public static function configured(int $tenantId): array
    {
        $raw = Settings::get($tenantId, 'ui.bottom_nav');
        $keys = $raw === null ? self::DEFAULT : array_filter(explode(',', $raw));
        return self::clean($keys);
    }

    public static function clean(array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $k = (string) $k;
            if (isset(self::ITEMS[$k]) && !in_array($k, $out, true)) {
                $out[] = $k;
            }
        }
        return array_slice($out, 0, self::SLOTS);
    }

    /** Items the current user may see, in configured order. */
    public static function forCurrentUser(): array
    {
        $tid = Auth::tenantId();
        if (!$tid) {
            return [];
        }
        $items = [];
        foreach (self::configured($tid) as $k) {
            [$label, $path, $icon, $nav, $perm] = self::ITEMS[$k];
            if ($perm === null || Auth::canAny((array) $perm)) {
                $items[] = ['key' => $k, 'label' => $label, 'url' => url($path), 'icon' => $icon, 'nav' => $nav];
            }
        }
        return $items;
    }
}
