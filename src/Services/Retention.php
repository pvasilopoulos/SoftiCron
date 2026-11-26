<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;

/**
 * How long each organization keeps its history, and the daily cleanup that applies it.
 *
 * - runs: job runs (status, times, errors); per-job override in jobs.retention_days
 * - bodies: stored response bodies/headers of the runs (cleared earlier to save space)
 * - notifications: notification history
 * - audit: audit log
 *
 * runs and notifications are capped by the plan's maximum (tenants.log_retention_days, set by
 * the super admin). Defaults keep the behaviour from before these settings existed.
 */
final class Retention
{
    public const AUDIT_DEFAULT = 365;
    public const AUDIT_MIN = 30;
    public const AUDIT_MAX = 730;
    /** Platform-level audit entries (no tenant). */
    public const PLATFORM_AUDIT_DAYS = 365;
    private const BATCH = 5000;
    private const DONE = "status NOT IN ('queued','running','retrying')";

    /** @return array{max:int, runs:int, bodies:int, notifications:int, audit:int} */
    public static function policy(array $tenant): array
    {
        $tid = (int) $tenant['id'];
        $max = max(1, (int) $tenant['log_retention_days']);
        $get = fn (string $k, int $def) => ($v = Settings::get($tid, 'retention.' . $k)) !== null && $v !== '' ? (int) $v : $def;
        $runs = max(1, min($max, $get('runs', $max)));
        return [
            'max' => $max,
            'runs' => $runs,
            'bodies' => max(1, min($runs, $get('bodies', $runs))),
            'notifications' => max(1, min($max, $get('notifications', $max))),
            'audit' => max(self::AUDIT_MIN, min(self::AUDIT_MAX, $get('audit', self::AUDIT_DEFAULT))),
        ];
    }

    /** Validated policy from user input (out-of-range values are clamped, as the form says). */
    public static function fromInput(array $tenant, array $in): array
    {
        $max = max(1, (int) $tenant['log_retention_days']);
        $int = fn (string $k, int $def) => isset($in[$k]) && is_numeric($in[$k]) ? (int) $in[$k] : $def;
        $cur = self::policy($tenant);
        $runs = max(1, min($max, $int('runs', $cur['runs'])));
        return [
            'max' => $max,
            'runs' => $runs,
            'bodies' => max(1, min($runs, $int('bodies', $cur['bodies']))),
            'notifications' => max(1, min($max, $int('notifications', $cur['notifications']))),
            'audit' => max(self::AUDIT_MIN, min(self::AUDIT_MAX, $int('audit', $cur['audit']))),
        ];
    }

    public static function save(int $tenantId, array $policy): void
    {
        foreach (['runs', 'bodies', 'notifications', 'audit'] as $k) {
            Settings::set($tenantId, 'retention.' . $k, (string) $policy[$k]);
        }
    }

    /** Effective days of a job's runs: its own override (within the plan) or the organization's. */
    public static function jobDays(array $job, array $policy): int
    {
        return $job['retention_days'] !== null && (int) $job['retention_days'] > 0 ? max(1, min($policy['max'], (int) $job['retention_days'])) : $policy['runs'];
    }

    private static function cut(int $days): string
    {
        return gmdate('Y-m-d H:i:s', time() - $days * 86400);
    }

    /** Jobs with their own retention: [job_id => days]. */
    private static function overrides(int $tenantId, array $policy): array
    {
        $out = [];
        foreach (App::db()->all('SELECT id, retention_days FROM jobs WHERE tenant_id = ? AND retention_days IS NOT NULL', [$tenantId]) as $j) {
            $out[(int) $j['id']] = self::jobDays($j, $policy);
        }
        return $out;
    }

    /**
     * What the next cleanup would remove with this policy (index-only counts).
     * bodies = runs that are kept but whose stored response would be cleared (upper bound).
     */
    public static function preview(array $tenant, array $policy): array
    {
        $db = App::db();
        $tid = (int) $tenant['id'];
        $over = self::overrides($tid, $policy);
        $notIn = $over ? ' AND job_id NOT IN (' . implode(',', array_keys($over)) . ')' : '';
        $runs = (int) $db->value('SELECT COUNT(*) FROM job_runs WHERE tenant_id = ? AND created_at < ? AND ' . self::DONE . $notIn, [$tid, self::cut($policy['runs'])]);
        foreach ($over as $jid => $days) {
            $runs += (int) $db->value('SELECT COUNT(*) FROM job_runs WHERE job_id = ? AND created_at < ? AND ' . self::DONE, [$jid, self::cut($days)]);
        }
        // Responses not cleared yet: newer than the previous cleanup's marker and than the runs
        // that will be deleted anyway, older than the new limit.
        $from = max(self::cut(max([$policy['runs'], ...array_values($over)])), Settings::get($tid, 'retention.bodies_cleared') ?: '1970-01-01 00:00:00');
        $bodies = (int) $db->value('SELECT COUNT(*) FROM job_runs WHERE tenant_id = ? AND created_at >= ? AND created_at < ?', [$tid, $from, self::cut($policy['bodies'])]);
        return [
            'runs' => $runs,
            'bodies' => $bodies,
            'notifications' => (int) $db->value('SELECT COUNT(*) FROM notification_log WHERE tenant_id = ? AND created_at < ?', [$tid, self::cut($policy['notifications'])]),
            'audit' => (int) $db->value('SELECT COUNT(*) FROM audit_logs WHERE tenant_id = ? AND created_at < ?', [$tid, self::cut($policy['audit'])]),
        ];
    }

    /** Rows and approximate size per tenant; the table sizes come from the database's statistics. */
    public static function usage(?int $tenantId = null): array
    {
        $db = App::db();
        $sizes = [];
        foreach ($db->all("SELECT table_name AS t, data_length + index_length AS bytes, table_rows AS rows_est FROM information_schema.tables
                           WHERE table_schema = DATABASE() AND table_name IN ('job_runs','notification_log','audit_logs')") as $r) {
            $sizes[$r['t']] = ['bytes' => (int) $r['bytes'], 'rows' => max(1, (int) $r['rows_est'])];
        }
        $count = function (string $table, string $where, array $p) use ($db): array {
            return array_column($db->all("SELECT tenant_id, COUNT(*) AS n FROM $table WHERE $where GROUP BY tenant_id", $p), 'n', 'tenant_id');
        };
        $w = $tenantId ? 'tenant_id = ?' : 'tenant_id IS NOT NULL';
        $p = $tenantId ? [$tenantId] : [];
        $runs = $count('job_runs', $w, $p);
        $notif = $count('notification_log', $w, $p);
        $audit = $count('audit_logs', $w, $p);
        $totals = [
            'job_runs' => max(1, (int) $db->value('SELECT COUNT(*) FROM job_runs')),
            'notification_log' => max(1, (int) $db->value('SELECT COUNT(*) FROM notification_log')),
            'audit_logs' => max(1, (int) $db->value('SELECT COUNT(*) FROM audit_logs')),
        ];
        $share = fn (string $t, int $n) => isset($sizes[$t]) ? (int) round($sizes[$t]['bytes'] * $n / $totals[$t]) : 0;
        $out = [];
        foreach (array_unique(array_merge(array_keys($runs), array_keys($notif), array_keys($audit), $tenantId ? [$tenantId] : [])) as $tid) {
            $r = (int) ($runs[$tid] ?? 0);
            $n = (int) ($notif[$tid] ?? 0);
            $a = (int) ($audit[$tid] ?? 0);
            $out[(int) $tid] = [
                'runs' => $r, 'notifications' => $n, 'audit' => $a,
                'bytes' => $share('job_runs', $r) + $share('notification_log', $n) + $share('audit_logs', $a),
                'oldest' => $db->value('SELECT MIN(created_at) FROM job_runs WHERE tenant_id = ?', [$tid]),
            ];
        }
        return $out;
    }

    public static function mb(int $bytes): string
    {
        return $bytes >= 1048576 * 1024 ? number_format($bytes / 1073741824, 2, ',', '.') . ' GB'
            : ($bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.') . ' MB' : max(0, (int) round($bytes / 1024)) . ' KB');
    }

    /**
     * Applies every tenant's policy in small batches, so the tables are never locked for long.
     * Returns true when everything is clean, false when the time budget ran out (the next
     * scheduler tick continues where this one stopped).
     */
    public static function run(float $deadline): bool
    {
        $db = App::db();
        $batch = function (string $sql, array $p) use ($db, $deadline): bool {
            do {
                if (microtime(true) > $deadline) {
                    return false;
                }
                $n = $db->exec($sql . ' LIMIT ' . self::BATCH, $p);
                if ($n >= self::BATCH) {
                    usleep(20000); // let other queries through between batches
                }
            } while ($n >= self::BATCH);
            return true;
        };
        foreach ($db->all('SELECT * FROM tenants ORDER BY id') as $tenant) {
            $tid = (int) $tenant['id'];
            $pol = self::policy($tenant);
            $over = self::overrides($tid, $pol);
            $notIn = $over ? ' AND job_id NOT IN (' . implode(',', array_keys($over)) . ')' : '';
            if (!$batch('DELETE FROM job_runs WHERE tenant_id = ? AND created_at < ? AND ' . self::DONE . $notIn, [$tid, self::cut($pol['runs'])])) {
                return false;
            }
            foreach ($over as $jid => $days) {
                if (!$batch('DELETE FROM job_runs WHERE job_id = ? AND created_at < ? AND ' . self::DONE, [$jid, self::cut($days)])) {
                    return false;
                }
            }
            // Stored responses: only the window not cleared yet (a marker remembers how far it got).
            $bodyCut = self::cut($pol['bodies']);
            $from = Settings::get($tid, 'retention.bodies_cleared') ?: '1970-01-01 00:00:00';
            if ($from < $bodyCut) {
                if (!$batch('UPDATE job_runs SET response_body = NULL, response_headers = NULL WHERE tenant_id = ? AND created_at >= ? AND created_at < ? AND response_body IS NOT NULL', [$tid, $from, $bodyCut])) {
                    return false;
                }
                Settings::set($tid, 'retention.bodies_cleared', $bodyCut);
            }
            if (!$batch('DELETE FROM notification_log WHERE tenant_id = ? AND created_at < ?', [$tid, self::cut($pol['notifications'])])) {
                return false;
            }
            if (!$batch('DELETE FROM audit_logs WHERE tenant_id = ? AND created_at < ?', [$tid, self::cut($pol['audit'])])) {
                return false;
            }
        }
        return $batch('DELETE FROM audit_logs WHERE tenant_id IS NULL AND created_at < ?', [self::cut(self::PLATFORM_AUDIT_DAYS)]);
    }
}
