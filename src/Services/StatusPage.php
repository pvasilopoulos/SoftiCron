<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;

/** Data of the public status page (never exposes URLs, errors or other internals). */
final class StatusPage
{
    public const DAYS = 90;

    /** option => default */
    public const OPTIONS = [
        'show_uptime' => '1', 'show_response' => '1', 'show_history' => '1', 'show_notices' => '1',
        'default_days' => '90', 'theme' => 'auto', 'hide_powered' => '0', 'noindex' => '0', 'collapse_groups' => '0',
    ];

    public const NOTICE_KINDS = [
        'info' => ['Ανακοίνωση', '#2563EB'], 'maintenance' => ['Προγραμματισμένη συντήρηση', '#7C3AED'],
        'incident' => ['Συμβάν σε εξέλιξη', '#DC2626'], 'resolved' => ['Επιλύθηκε', '#16A34A'],
    ];

    public static function options(int $tid): array
    {
        $o = [];
        foreach (self::OPTIONS as $k => $def) {
            $o[$k] = Settings::get($tid, 'status.' . $k, $def) ?? $def;
        }
        return $o;
    }

    /** Tenant by public slug, or null when missing/disabled. */
    public static function tenantBySlug(string $slug): ?array
    {
        $db = App::db();
        $tid = $db->value("SELECT tenant_id FROM settings WHERE k = 'status.slug' AND v = ? LIMIT 1", [$slug]);
        $tenant = $tid ? $db->one("SELECT * FROM tenants WHERE id = ? AND status = 'active'", [$tid]) : null;
        return $tenant && Settings::get((int) $tenant['id'], 'status.enabled') === '1' ? $tenant : null;
    }

    public static function data(array $tenant): array
    {
        $db = App::db();
        $tid = (int) $tenant['id'];
        $tz = new \DateTimeZone($tenant['timezone'] ?: 'Europe/Athens');
        $offset = $tz->getOffset(new \DateTimeImmutable('now', $tz));
        $jobs = $db->all(
            'SELECT id, COALESCE(status_name, name) AS name, group_name, enabled, last_status, failing_since, last_run_at, notify_after_failures, description
             FROM jobs WHERE tenant_id = ? AND show_on_status = 1 ORDER BY group_name IS NULL, group_name, COALESCE(status_name, name)',
            [$tid],
        );
        $ids = array_map('intval', array_column($jobs, 'id'));
        $daily = $resp = [];
        if ($ids) {
            $in = implode(',', $ids);
            $since = gmdate('Y-m-d H:i:s', time() - self::DAYS * 86400);
            foreach ($db->all(
                "SELECT job_id, DATE(COALESCE(started_at, scheduled_at) + INTERVAL $offset SECOND) AS d,
                        SUM(status = 'success') AS ok, SUM(status = 'failed') AS failed, AVG(CASE WHEN status = 'success' THEN duration_ms END) AS avg_ms
                 FROM job_runs WHERE job_id IN ($in) AND status IN ('success','failed') AND COALESCE(started_at, scheduled_at) >= ?
                 GROUP BY job_id, d",
                [$since],
            ) as $r) {
                $daily[(int) $r['job_id']][$r['d']] = [(int) $r['ok'], (int) $r['failed'], $r['avg_ms'] !== null ? (int) round((float) $r['avg_ms']) : null];
            }
            foreach ($db->all(
                "SELECT job_id, AVG(duration_ms) AS a FROM job_runs WHERE job_id IN ($in) AND status = 'success' AND started_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY GROUP BY job_id",
            ) as $r) {
                $resp[(int) $r['job_id']] = (int) round((float) $r['a']);
            }
        }
        $today = new \DateTimeImmutable('now', $tz);
        $groups = [];
        $counts = ['up' => 0, 'degraded' => 0, 'down' => 0, 'paused' => 0, 'unknown' => 0];
        foreach ($jobs as $j) {
            $days = [];
            for ($i = self::DAYS - 1; $i >= 0; $i--) {
                $d = $today->modify("-$i days")->format('Y-m-d');
                [$ok, $failed, $avg] = $daily[(int) $j['id']][$d] ?? [0, 0, null];
                $days[] = [$d, $ok, $failed, $avg];
            }
            $state = !$j['enabled'] ? 'paused' : ($j['last_status'] === 'failed' ? 'down' : ($j['last_status'] === null ? 'unknown' : 'up'));
            if ($state === 'up' && $days[self::DAYS - 1][2] > 0) {
                $state = 'degraded';
            }
            $counts[$state]++;
            $groups[$j['group_name'] ?? ''][] = [
                'id' => (int) $j['id'],
                'name' => $j['name'],
                'state' => $state,
                'since' => $j['failing_since'],
                'response_ms' => $resp[(int) $j['id']] ?? null,
                'days' => $days,
            ];
        }
        $total = count($jobs);
        $down = $counts['down'];
        $overall = $total === 0 ? 'unknown'
            : ($down === 0 ? ($counts['degraded'] ? 'degraded' : 'up') : ($down >= max(1, (int) ceil($total / 2)) ? 'major' : 'partial'));
        $notices = self::notices($tid);
        if (in_array($overall, ['up', 'degraded', 'unknown'], true)) {
            $kinds = array_column($notices['active'], 'kind');
            if (in_array('incident', $kinds, true)) {
                $overall = 'incident';
            } elseif (in_array('maintenance', $kinds, true)) {
                $overall = 'maintenance';
            }
        }
        return [
            'tenant' => $tenant, 'groups' => $groups, 'overall' => $overall, 'counts' => $counts, 'total' => $total,
            'history' => self::history($jobs), 'notices' => $notices,
            'title' => Settings::get($tid, 'status.title') ?: $tenant['name'],
            'description' => Settings::get($tid, 'status.description', '') ?? '',
            'tz' => $tz->getName(), 'options' => self::options($tid),
        ];
    }

    /** Incidents of the last 30 days, derived from failure streaks (newest first). */
    public static function history(array $jobs, int $days = 30): array
    {
        $db = App::db();
        $since = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $out = [];
        foreach ($jobs as $j) {
            $threshold = max(1, (int) ($j['notify_after_failures'] ?? 1));
            $runs = $db->all(
                "SELECT status, COALESCE(started_at, scheduled_at) AS t FROM job_runs
                 WHERE job_id = ? AND status IN ('success','failed') AND COALESCE(started_at, scheduled_at) >= ? ORDER BY t, id",
                [$j['id'], $since],
            );
            $start = null;
            $n = 0;
            $flush = function (?string $end) use (&$out, &$start, &$n, $j, $threshold): void {
                if ($start !== null && $n >= $threshold) {
                    $dur = (int) round(((($end ? strtotime($end . ' UTC') : time())) - strtotime($start . ' UTC')) / 60);
                    $out[] = ['name' => $j['name'], 'start' => $start, 'end' => $end, 'minutes' => max(1, $dur), 'failures' => $n, 'ongoing' => $end === null];
                }
                $start = null;
                $n = 0;
            };
            foreach ($runs as $r) {
                if ($r['status'] === 'failed') {
                    $start ??= $r['t'];
                    $n++;
                } else {
                    $flush($r['t']);
                }
            }
            $flush(null);
        }
        usort($out, fn ($a, $b) => strcmp($b['start'], $a['start']));
        return array_slice($out, 0, 50);
    }

    /** Active, upcoming and recently ended announcements. */
    public static function notices(int $tid): array
    {
        $rows = App::db()->all(
            "SELECT * FROM status_notices WHERE tenant_id = ?
               AND (ends_at IS NULL OR ends_at > UTC_TIMESTAMP() - INTERVAL 14 DAY)
             ORDER BY is_pinned DESC, starts_at DESC LIMIT 30",
            [$tid],
        );
        $now = time();
        $out = ['active' => [], 'upcoming' => [], 'past' => []];
        foreach ($rows as $n) {
            $s = strtotime($n['starts_at'] . ' UTC');
            $e = $n['ends_at'] ? strtotime($n['ends_at'] . ' UTC') : null;
            $bucket = $s > $now ? 'upcoming' : ($e !== null && $e <= $now ? 'past' : 'active');
            $out[$bucket][] = $n;
        }
        usort($out['upcoming'], fn ($a, $b) => strcmp($a['starts_at'], $b['starts_at']));
        return $out;
    }

    public static function uptime(array $days, int $last): ?float
    {
        $ok = $fail = 0;
        foreach (array_slice($days, -$last) as [, $o, $f]) {
            $ok += $o;
            $fail += $f;
        }
        return $ok + $fail ? 100 * $ok / ($ok + $fail) : null;
    }

    /** Shields-style SVG badge ("status | operational"). */
    public static function badge(string $label, string $overall): string
    {
        [$text, $color] = [
            'up' => ['λειτουργεί', '#16A34A'], 'degraded' => ['μερικά προβλήματα', '#D97706'], 'partial' => ['μερική διακοπή', '#EA580C'],
            'major' => ['διακοπή', '#DC2626'], 'unknown' => ['άγνωστο', '#64748B'],
            'incident' => ['υπό διερεύνηση', '#D97706'], 'maintenance' => ['συντήρηση', '#7C3AED'],
        ][$overall] ?? ['άγνωστο', '#64748B'];
        $w = fn (string $s) => (int) ceil(mb_strlen($s) * 6.6) + 14;
        $lw = $w($label);
        $rw = $w($text);
        $t = $lw + $rw;
        $esc = fn (string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $t . '" height="20" role="img" aria-label="' . $esc($label . ': ' . $text) . '">'
            . '<linearGradient id="g" x2="0" y2="100%"><stop offset="0" stop-color="#fff" stop-opacity=".12"/><stop offset="1" stop-opacity=".12"/></linearGradient>'
            . '<clipPath id="r"><rect width="' . $t . '" height="20" rx="4"/></clipPath><g clip-path="url(#r)">'
            . '<rect width="' . $lw . '" height="20" fill="#475569"/><rect x="' . $lw . '" width="' . $rw . '" height="20" fill="' . $color . '"/>'
            . '<rect width="' . $t . '" height="20" fill="url(#g)"/></g>'
            . '<g fill="#fff" font-family="Verdana,DejaVu Sans,sans-serif" font-size="11" text-anchor="middle">'
            . '<text x="' . ($lw / 2) . '" y="14">' . $esc($label) . '</text><text x="' . ($lw + $rw / 2) . '" y="14">' . $esc($text) . '</text></g></svg>';
    }
}
