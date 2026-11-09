<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;

/**
 * In-app notification center (bell). Independent of the job's delivery channels:
 * every member who can see jobs gets the events they follow (Ειδοποιήσεις → Προτιμήσεις).
 */
final class InAppFeed
{
    public const LEVELS = [
        'failed' => 'error', 'escalated' => 'error', 'missed' => 'warning', 'slow' => 'warning',
        'recovered' => 'success', 'succeeded' => 'success',
    ];

    public const KEEP_DAYS = 90;

    public static function record(string $event, array $job, ?array $run, array $tenant, ?string $extra = null): void
    {
        try {
            $db = App::db();
            // One failure entry per incident: skip repeated failures until the job recovers.
            if ($event === 'failed') {
                $last = $db->value("SELECT event FROM user_notifications WHERE job_id = ? AND event IN ('failed','recovered') ORDER BY id DESC LIMIT 1", [$job['id']]);
                if ($last === 'failed') {
                    return;
                }
            }
            $users = self::recipients((int) $tenant['id'], $event === 'escalated' ? 'failed' : $event);
            if (!$users) {
                return;
            }
            [$title, $body] = self::text($event, $job, $run, $extra);
            $url = $run && !empty($run['id']) ? 'runs/' . $run['id'] : 'jobs/' . $job['id'] . '/edit';
            foreach ($users as $uid) {
                $db->insert('user_notifications', [
                    'tenant_id' => $tenant['id'], 'user_id' => $uid, 'job_id' => $job['id'], 'run_id' => $run['id'] ?? null,
                    'event' => $event, 'level' => self::LEVELS[$event] ?? 'info',
                    'title' => mb_substr($title, 0, 255), 'body' => $body !== null ? mb_substr($body, 0, 1000) : null, 'url' => $url,
                ]);
            }
        } catch (\Throwable $e) {
            App::log('in-app feed: ' . $e->getMessage());
        }
    }

    /** Active members who can view jobs and follow this event. */
    public static function recipients(int $tenantId, string $event): array
    {
        $follow = Notifier::pushRecipients($tenantId, $event);
        if (!$follow) {
            return [];
        }
        $in = implode(',', array_fill(0, count($follow), '?'));
        return array_map('intval', App::db()->column(
            "SELECT DISTINCT m.user_id FROM memberships m
             JOIN users u ON u.id = m.user_id
             LEFT JOIN role_permissions rp ON rp.role_id = m.role_id AND rp.permission = 'jobs.view'
             WHERE m.tenant_id = ? AND m.user_id IN ($in) AND (rp.permission IS NOT NULL OR u.is_super_admin = 1)",
            array_merge([$tenantId], $follow),
        ));
    }

    /** @return array{0: string, 1: ?string} */
    private static function text(string $event, array $job, ?array $run, ?string $extra): array
    {
        $name = (string) $job['name'];
        $http = $run && !empty($run['http_status']) ? 'HTTP ' . $run['http_status'] : null;
        $dur = $run && isset($run['duration_ms']) && $run['duration_ms'] !== null ? fmt_ms((int) $run['duration_ms']) : null;
        $err = $run['error'] ?? null;
        $title = match ($event) {
            'failed' => "Αποτυχία: $name",
            'escalated' => "Κλιμάκωση: $name",
            'recovered' => "Ανάκαμψη: $name",
            'missed' => "Χαμένη εκτέλεση: $name",
            'slow' => "Αργή απόκριση: $name",
            'succeeded' => "Επιτυχία: $name",
            default => Notifier::eventLabel($event) . ": $name",
        };
        $parts = array_filter([$extra, $http, $dur, $event === 'failed' || $event === 'escalated' || $event === 'missed' ? $err : null]);
        return [$title, $parts ? implode(' · ', $parts) : null];
    }

    public static function unread(int $userId, int $tenantId): int
    {
        return (int) App::db()->value('SELECT COUNT(*) FROM user_notifications WHERE user_id = ? AND tenant_id = ? AND read_at IS NULL', [$userId, $tenantId]);
    }

    /** @return array<int, int> tenant_id => unread count (only tenants with unread items) */
    public static function unreadByTenant(int $userId): array
    {
        $out = [];
        foreach (App::db()->all('SELECT tenant_id, COUNT(*) AS n FROM user_notifications WHERE user_id = ? AND read_at IS NULL GROUP BY tenant_id', [$userId]) as $r) {
            $out[(int) $r['tenant_id']] = (int) $r['n'];
        }
        return $out;
    }

    public static function cleanup(): void
    {
        App::db()->exec('DELETE FROM user_notifications WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - self::KEEP_DAYS * 86400)]);
    }
}
