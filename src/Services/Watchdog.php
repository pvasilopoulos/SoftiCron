<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Services\Channels\EmailSender;
use App\Services\Channels\Http;
use App\Services\Channels\PushSender;

/**
 * Watches the scheduler itself:
 *  - /health tells external monitors (UptimeRobot, healthchecks.io, Better Stack…) whether bin/cron.php runs;
 *  - an optional "dead man's switch" URL is pinged on every tick, so a monitor alerts when the pings stop;
 *  - when a web request notices that the scheduler stopped, super admins get push + email + in-app alert,
 *    and a recovery message once it runs again.
 */
final class Watchdog
{
    /** The scheduler is considered stopped after this many seconds without a tick. */
    public const STALE_SECONDS = 300;

    /** @return array{last_tick: ?string, age: ?int, ok: bool} */
    public static function status(): array
    {
        $last = Settings::get(0, 'scheduler.last_tick');
        $age = $last ? max(0, time() - strtotime($last . ' UTC')) : null;
        return ['last_tick' => $last, 'age' => $age, 'ok' => $age !== null && $age <= self::STALE_SECONDS];
    }

    /** Called on web requests (cheap: one settings read unless the scheduler is stale). */
    public static function checkFromWeb(): void
    {
        try {
            $st = self::status();
            if ($st['ok'] || $st['last_tick'] === null) {
                return; // healthy, or a fresh install that never ran (shown on the dashboard instead)
            }
            if (Settings::get(0, 'watchdog.alert_at')) {
                return; // already alerted for this outage
            }
            Settings::set(0, 'watchdog.alert_at', gmdate('Y-m-d H:i:s'));
            $mins = (int) round($st['age'] / 60);
            self::alertAdmins(
                '⚠️ Ο scheduler σταμάτησε',
                "Το bin/cron.php δεν έχει τρέξει εδώ και $mins λεπτά. Τα jobs δεν εκτελούνται και δεν φεύγουν ειδοποιήσεις.\nΈλεγξε το Scheduled Task στο Plesk (κάθε λεπτό).",
                'error',
            );
            App::log("watchdog: scheduler stale for {$st['age']}s, admins alerted");
        } catch (\Throwable $e) {
            App::log('watchdog check: ' . $e->getMessage());
        }
    }

    /** Called at the start of every scheduler tick. */
    public static function onTick(): void
    {
        $since = Settings::get(0, 'watchdog.alert_at');
        if ($since) {
            Settings::set(0, 'watchdog.alert_at', '');
            $mins = (int) round((time() - strtotime($since . ' UTC')) / 60);
            self::alertAdmins('✅ Ο scheduler λειτουργεί ξανά', "Το bin/cron.php τρέχει ξανά κανονικά (η ειδοποίηση είχε σταλεί πριν από $mins λεπτά). Οι εκτελέσεις που χάθηκαν καταγράφονται ως «Χαμένες».", 'success');
        }
    }

    /** Dead man's switch: GET the configured URL after each tick. */
    public static function pingOut(): void
    {
        $url = trim((string) Settings::get(0, 'watchdog.ping_url', ''));
        if ($url === '') {
            return;
        }
        try {
            [$code] = Http::request('GET', $url, ['User-Agent: ' . App::name() . '-Watchdog/1.0'], null, 8);
            Settings::set(0, 'watchdog.ping_last', gmdate('Y-m-d H:i:s') . '|' . $code);
        } catch (\Throwable $e) {
            Settings::set(0, 'watchdog.ping_last', gmdate('Y-m-d H:i:s') . '|' . mb_substr($e->getMessage(), 0, 150));
        }
    }

    /** Push + email + in-app notification to every super admin. */
    public static function alertAdmins(string $title, string $body, string $level): void
    {
        $db = App::db();
        $admins = $db->all("SELECT id, email FROM users WHERE is_super_admin = 1 AND status = 'active'");
        if (!$admins) {
            return;
        }
        $ids = array_map(fn ($a) => (int) $a['id'], $admins);
        try {
            PushSender::sendToUsers($ids, ['title' => $title, 'body' => $body, 'url' => abs_url('admin/system'), 'tag' => 'watchdog', 'icon' => abs_url('assets/icons/icon-192.png')]);
        } catch (\Throwable $e) {
            App::log('watchdog push: ' . $e->getMessage());
        }
        if (EmailSender::platformConfigured()) {
            try {
                $tenant = ['id' => 0, 'name' => App::name(), 'brand_color' => '#4F46E5', 'logo_path' => null];
                $html = Notifier::emailHtml($tenant, $title, $body, abs_url('admin/system'), $level === 'error' ? 'failed' : 'recovered');
                EmailSender::sendPlatform(array_column($admins, 'email'), $title, $html, $body);
            } catch (\Throwable $e) {
                App::log('watchdog email: ' . $e->getMessage());
            }
        }
        // In-app feed: one entry per admin, in a tenant they belong to (or the first tenant).
        foreach ($ids as $uid) {
            $tid = $db->value('SELECT tenant_id FROM memberships WHERE user_id = ? ORDER BY tenant_id LIMIT 1', [$uid]) ?: $db->value('SELECT id FROM tenants ORDER BY id LIMIT 1');
            if ($tid) {
                $db->insert('user_notifications', [
                    'tenant_id' => $tid, 'user_id' => $uid, 'event' => 'watchdog', 'level' => $level,
                    'title' => mb_substr($title, 0, 255), 'body' => mb_substr($body, 0, 1000), 'url' => 'admin/system',
                ]);
            }
        }
    }
}
