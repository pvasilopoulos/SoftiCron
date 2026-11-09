<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\Auth;
use App\Core\App;

/**
 * Job version history. Each snapshot stores the full DB row (secrets stay encrypted),
 * the notification rules and push recipients, so a restore is exact, plus the
 * portable form used to show human-readable diffs.
 */
final class JobVersions
{
    /** Runtime state that is not part of a job's configuration. */
    private const STATE = ['id', 'tenant_id', 'created_at', 'updated_at', 'created_by', 'next_run_at', 'last_run_at', 'last_status',
        'last_duration_ms', 'last_ping_at', 'failing_since', 'escalated_at', 'ping_token'];

    public const KEEP = 50;

    public static function snapshot(int $jobId, ?string $comment = null): ?int
    {
        $db = App::db();
        $job = $db->one('SELECT * FROM jobs WHERE id = ?', [$jobId]);
        if (!$job) {
            return null;
        }
        $row = array_diff_key($job, array_flip(self::STATE));
        $data = [
            'row' => $row,
            'notify' => $db->all('SELECT event, channel, template_id FROM job_notifications WHERE job_id = ?', [$jobId]),
            'targets' => $db->all('SELECT user_id, subscription_id FROM job_push_targets WHERE job_id = ?', [$jobId]),
            'portable' => JobIO::toArray($job, false),
        ];
        $json = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $last = $db->one('SELECT version_no, data FROM job_versions WHERE job_id = ? ORDER BY version_no DESC LIMIT 1', [$jobId]);
        if ($last && self::sameConfig((string) $last['data'], $data)) {
            return null; // nothing changed
        }
        $no = (int) ($last['version_no'] ?? 0) + 1;
        $id = $db->insert('job_versions', [
            'job_id' => $jobId, 'tenant_id' => $job['tenant_id'], 'version_no' => $no,
            'data' => $json, 'comment' => $comment !== null ? mb_substr($comment, 0, 255) : null, 'created_by' => Auth::id(),
        ]);
        $db->exec('DELETE FROM job_versions WHERE job_id = ? AND version_no <= ?', [$jobId, $no - self::KEEP]);
        return $id;
    }

    private static function sameConfig(string $lastJson, array $data): bool
    {
        $prev = json_decode($lastJson, true);
        if (!is_array($prev)) {
            return false;
        }
        $norm = static function (array $d): string {
            $r = $d['row'];
            unset($r['headers_enc'], $r['auth_enc']); // ciphertext changes on every save
            $p = (array) ($d['portable'] ?? []);
            $p['notify'] = (array) ($p['notify'] ?? []); // {} vs [] after a JSON round trip
            return json_encode([$r, $p, $d['notify'], $d['targets']]) ?: '';
        };
        return $norm($prev) === $norm($data);
    }

    public static function all(int $jobId): array
    {
        return App::db()->all(
            'SELECT v.id, v.version_no, v.comment, v.created_at, v.data, u.name AS user_name
             FROM job_versions v LEFT JOIN users u ON u.id = v.created_by WHERE v.job_id = ? ORDER BY v.version_no DESC',
            [$jobId],
        );
    }

    public static function decode(array $version): array
    {
        $d = json_decode((string) $version['data'], true);
        return is_array($d) ? $d : [];
    }

    /** Field-level differences between two portable job arrays: [field => [old, new]]. */
    public static function diff(array $old, array $new): array
    {
        // Normalise objects to arrays so {} and [] compare equal.
        $old = json_decode((string) json_encode($old), true) ?: [];
        $new = json_decode((string) json_encode($new), true) ?: [];
        $out = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $k) {
            $a = $old[$k] ?? null;
            $b = $new[$k] ?? null;
            if (json_encode($a) !== json_encode($b)) {
                $out[$k] = [self::show($a), self::show($b)];
            }
        }
        return $out;
    }

    private static function show(mixed $v): string
    {
        if ($v === null || $v === '' || $v === []) {
            return '—';
        }
        if (is_bool($v)) {
            return $v ? 'Ναι' : 'Όχι';
        }
        if (is_array($v)) {
            return (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return (string) $v;
    }

    /** Restores a version onto the job (keeps the job id, ping URL and run history). */
    public static function restore(array $job, array $version): void
    {
        $db = App::db();
        $d = self::decode($version);
        $row = array_diff_key((array) ($d['row'] ?? []), array_flip(self::STATE));
        $cols = array_flip($db->column('SHOW COLUMNS FROM jobs'));
        $row = array_intersect_key($row, $cols);
        $row['type'] = $job['type'];
        // Connectors deleted since then fall back to the tenant default.
        foreach (['email_connector_id', 'telegram_connector_id'] as $c) {
            if (!empty($row[$c]) && !$db->value('SELECT 1 FROM connectors WHERE id = ? AND tenant_id = ?', [$row[$c], $job['tenant_id']])) {
                $row[$c] = null;
            }
        }
        $db->transaction(function () use ($db, $job, $row, $d): void {
            $db->update('jobs', $row, 'id = :id', ['id' => $job['id']]);
            $db->exec('DELETE FROM job_notifications WHERE job_id = ?', [$job['id']]);
            foreach ((array) ($d['notify'] ?? []) as $n) {
                $db->insert('job_notifications', ['job_id' => $job['id'], 'event' => $n['event'], 'channel' => $n['channel'], 'template_id' => $n['template_id'] ?? null]);
            }
            $db->exec('DELETE FROM job_push_targets WHERE job_id = ?', [$job['id']]);
            $members = array_map('intval', $db->column('SELECT user_id FROM memberships WHERE tenant_id = ?', [$job['tenant_id']]));
            foreach ((array) ($d['targets'] ?? []) as $t) {
                if ($t['user_id'] !== null && in_array((int) $t['user_id'], $members, true)) {
                    $db->insert('job_push_targets', ['job_id' => $job['id'], 'user_id' => $t['user_id']]);
                } elseif ($t['subscription_id'] !== null && $db->value('SELECT 1 FROM push_subscriptions WHERE id = ?', [$t['subscription_id']])) {
                    $db->insert('job_push_targets', ['job_id' => $job['id'], 'subscription_id' => $t['subscription_id']]);
                }
            }
        });
        Scheduler::refreshNextRun((int) $job['id']);
    }
}
