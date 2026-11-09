<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Db;

/**
 * The worker. bin/cron.php calls tick() every minute (Plesk Scheduled Task):
 *  1. schedules due jobs (one row per slot in job_runs, unique => no duplicates)
 *  2. executes queued runs / due retries in parallel, until the time budget ends
 *  3. housekeeping once a day
 */
final class Scheduler
{
    private Db $db;
    private array $stats = ['scheduled' => 0, 'executed' => 0, 'missed' => 0, 'skipped' => 0];
    private const LATE_SECONDS = 180;

    public function __construct()
    {
        $this->db = App::db();
    }

    public function tick(int $budgetSeconds = 50): array
    {
        $deadline = microtime(true) + $budgetSeconds;
        $got = (int) $this->db->value("SELECT GET_LOCK('cronapp_scheduler', 0)");
        if ($got !== 1) {
            return ['locked' => true];
        }
        try {
            Settings::set(0, 'scheduler.last_tick', $this->db->now());
            $this->recoverStuck();
            $this->scheduleDue();
            $this->process($deadline);
            $this->maintenance();
        } finally {
            $this->db->value("SELECT RELEASE_LOCK('cronapp_scheduler')");
        }
        return $this->stats;
    }

    /** Runs left in "running" by a crashed worker are failed after their timeout. */
    private function recoverStuck(): void
    {
        $rows = $this->db->all(
            "SELECT r.id FROM job_runs r JOIN jobs j ON j.id = r.job_id
             WHERE r.status = 'running' AND r.started_at < (UTC_TIMESTAMP() - INTERVAL (j.timeout_sec + 300) SECOND)",
        );
        foreach ($rows as $r) {
            $this->db->update('job_runs', [
                'status' => 'failed', 'finished_at' => $this->db->now(), 'error' => 'Η εκτέλεση διακόπηκε απρόσμενα (worker restart).',
            ], 'id = :id', ['id' => $r['id']]);
        }
    }

    public function scheduleDue(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $jobs = $this->db->all(
            "SELECT j.*, t.timezone AS tenant_tz FROM jobs j JOIN tenants t ON t.id = j.tenant_id
             WHERE j.enabled = 1 AND t.status = 'active' AND (j.next_run_at IS NULL OR j.next_run_at <= ?)",
            [$now->format('Y-m-d H:i:s')],
        );
        foreach ($jobs as $job) {
            $tz = $job['timezone'] ?: $job['tenant_tz'];
            try {
                if ($job['next_run_at'] !== null) {
                    $due = new \DateTimeImmutable($job['next_run_at'], new \DateTimeZone('UTC'));
                    $late = $now->getTimestamp() - $due->getTimestamp();
                    if ($late > self::LATE_SECONDS) {
                        $this->recordMissed($job, $due);
                    }
                    $this->enqueue($job, $due->format('Y-m-d H:i:s'), 'schedule', null);
                }
                $next = CronHelper::nextRuns($job['cron_expr'], $tz, 1, $job['active_from'], $job['active_until'], $now)[0] ?? null;
                $this->db->update('jobs', ['next_run_at' => $next?->format('Y-m-d H:i:s')], 'id = :id', ['id' => $job['id']]);
            } catch (\Throwable $e) {
                App::log('schedule error job ' . $job['id'] . ': ' . $e->getMessage());
                $this->db->update('jobs', ['next_run_at' => null, 'enabled' => 0], 'id = :id', ['id' => $job['id']]);
            }
        }
    }

    private function recordMissed(array $job, \DateTimeImmutable $due): void
    {
        $id = $this->db->insert('job_runs', [
            'tenant_id' => $job['tenant_id'], 'job_id' => $job['id'], 'trigger_type' => 'missed', 'status' => 'missed',
            'scheduled_at' => $due->format('Y-m-d H:i:s'), 'finished_at' => $this->db->now(),
            'error' => 'Η εκτέλεση δεν έγινε εγκαίρως (ο scheduler δεν έτρεχε). Έγινε εκτέλεση αναπλήρωσης.',
        ], true);
        if ($id) {
            $this->stats['missed']++;
            $run = $this->db->one('SELECT * FROM job_runs WHERE id = ?', [$id]);
            $tenant = $this->db->one('SELECT * FROM tenants WHERE id = ?', [$job['tenant_id']]);
            Notifier::dispatch('missed', $job, $run, $tenant);
        }
    }

    /** Inserts a run for a slot, honouring the overlap policy. Returns run id or 0. */
    public function enqueue(array $job, string $scheduledAt, string $trigger, ?int $userId): int
    {
        $busy = (bool) $this->db->value(
            "SELECT 1 FROM job_runs WHERE job_id = ? AND status IN ('running','retrying') LIMIT 1",
            [$job['id']],
        );
        $status = 'queued';
        $error = null;
        if ($busy && $job['overlap_policy'] === 'skip' && $trigger === 'schedule') {
            $status = 'skipped';
            $error = 'Παραλείφθηκε: η προηγούμενη εκτέλεση έτρεχε ακόμα.';
        }
        $id = $this->db->insert('job_runs', [
            'tenant_id' => $job['tenant_id'], 'job_id' => $job['id'], 'trigger_type' => $trigger, 'status' => $status,
            'scheduled_at' => $scheduledAt, 'error' => $error, 'triggered_by' => $userId,
            'request_method' => $job['method'], 'request_url' => mb_substr((string) $job['url'], 0, 2048),
            'finished_at' => $status === 'skipped' ? $this->db->now() : null,
        ], true);
        if ($id) {
            $this->stats[$status === 'skipped' ? 'skipped' : 'scheduled']++;
        }
        return $id;
    }

    /** Executes ready runs until the deadline. */
    public function process(float $deadline): void
    {
        while (microtime(true) < $deadline - 2) {
            $rows = $this->db->all(
                "SELECT r.id, r.job_id, j.overlap_policy FROM job_runs r JOIN jobs j ON j.id = r.job_id
                 WHERE r.status = 'queued' OR (r.status = 'retrying' AND r.next_attempt_at <= UTC_TIMESTAMP())
                 ORDER BY r.id LIMIT 30",
            );
            $claimed = [];
            $jobsInBatch = [];
            foreach ($rows as $r) {
                if ($r['overlap_policy'] !== 'allow') {
                    if (isset($jobsInBatch[$r['job_id']])) {
                        continue;
                    }
                    $running = $this->db->value("SELECT 1 FROM job_runs WHERE job_id = ? AND status = 'running' AND id <> ? LIMIT 1", [$r['job_id'], $r['id']]);
                    if ($running) {
                        continue;
                    }
                }
                if ($this->claim((int) $r['id'])) {
                    $claimed[] = (int) $r['id'];
                    $jobsInBatch[$r['job_id']] = true;
                }
            }
            if ($claimed) {
                $this->executeClaimed($claimed);
                continue;
            }
            // Nothing ready now: wait for a retry that becomes due within the budget.
            $nextRetry = $this->db->value("SELECT MIN(next_attempt_at) FROM job_runs WHERE status = 'retrying'");
            if ($nextRetry === null) {
                break;
            }
            $wait = strtotime($nextRetry . ' UTC') - time();
            if ($wait > 0 && microtime(true) + $wait < $deadline - 2) {
                sleep(min($wait, 10));
                continue;
            }
            break;
        }
    }

    public function claim(int $runId): bool
    {
        return $this->db->exec(
            "UPDATE job_runs SET status = 'running', started_at = COALESCE(started_at, UTC_TIMESTAMP())
             WHERE id = ? AND status IN ('queued','retrying')",
            [$runId],
        ) === 1;
    }

    /** @param int[] $runIds runs already set to "running" */
    public function executeClaimed(array $runIds): void
    {
        $requests = [];
        $meta = [];
        foreach ($runIds as $id) {
            $run = $this->db->one('SELECT * FROM job_runs WHERE id = ?', [$id]);
            $job = $run ? $this->db->one('SELECT * FROM jobs WHERE id = ?', [$run['job_id']]) : null;
            $tenant = $job ? $this->db->one('SELECT * FROM tenants WHERE id = ?', [$job['tenant_id']]) : null;
            if (!$run || !$job || !$tenant) {
                continue;
            }
            $prevAt = $this->db->value(
                "SELECT MAX(started_at) FROM job_runs WHERE job_id = ? AND status = 'success' AND id <> ?",
                [$job['id'], $id],
            );
            $ctx = TemplateRenderer::requestContext($job, $tenant, $run, $prevAt);
            $req = HttpExecutor::buildRequest($job, $ctx);
            $requests[$id] = $req;
            $meta[$id] = [$run, $job, $tenant, $req];
        }
        if (!$requests) {
            return;
        }
        $attemptStart = $this->db->now();
        $results = HttpExecutor::executeMany($requests);
        foreach ($results as $id => $res) {
            [$run, $job, $tenant, $req] = $meta[$id];
            $this->stats['executed']++;
            $attempt = (int) $run['attempts'] + 1;
            [$ok, $reason] = HttpExecutor::evaluate($job, $res);
            $this->db->insert('job_run_attempts', [
                'run_id' => $id, 'attempt_no' => $attempt, 'started_at' => $attemptStart,
                'duration_ms' => $res['duration_ms'], 'http_status' => $res['http_status'], 'error' => $ok ? null : mb_substr((string) $reason, 0, 1000),
            ]);
            $common = [
                'attempts' => $attempt,
                'http_status' => $res['http_status'],
                'duration_ms' => $res['duration_ms'],
                'request_method' => $req['method'],
                'request_url' => mb_substr($req['url'], 0, 2048),
                'response_headers' => $res['headers'] !== '' ? $res['headers'] : null,
                'response_body' => $res['body'] !== '' ? $res['body'] : null,
                'response_size' => $res['size'],
            ];
            $maxAttempts = 1 + (int) $job['max_retries'];
            if (!$ok && $attempt < $maxAttempts) {
                $delay = $job['retry_strategy'] === 'fixed'
                    ? (int) $job['retry_delay_sec']
                    : (int) $job['retry_delay_sec'] * (3 ** ($attempt - 1));
                $this->db->update('job_runs', $common + [
                    'status' => 'retrying',
                    'error' => mb_substr((string) $reason, 0, 1000),
                    'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + max(1, $delay)),
                ], 'id = :id', ['id' => $id]);
                continue;
            }
            $slow = $ok && $job['slow_threshold_ms'] && $res['duration_ms'] > (int) $job['slow_threshold_ms'];
            $final = $ok ? 'success' : 'failed';
            $this->db->update('job_runs', $common + [
                'status' => $final,
                'is_slow' => $slow ? 1 : 0,
                'error' => $ok ? null : mb_substr((string) $reason, 0, 1000),
                'finished_at' => $this->db->now(),
                'next_attempt_at' => null,
            ], 'id = :id', ['id' => $id]);
            $this->db->update('jobs', [
                'last_run_at' => $run['started_at'] ?? $attemptStart,
                'last_status' => $final,
                'last_duration_ms' => $res['duration_ms'],
            ], 'id = :id', ['id' => $job['id']]);

            $fresh = $this->db->one('SELECT * FROM job_runs WHERE id = ?', [$id]);
            $prev = $job['last_status'];
            try {
                if ($ok) {
                    if ($prev === 'failed') {
                        Notifier::dispatch('recovered', $job, $fresh, $tenant);
                    }
                    if ($slow) {
                        Notifier::dispatch('slow', $job, $fresh, $tenant);
                    }
                    Notifier::dispatch('succeeded', $job, $fresh, $tenant);
                } else {
                    Notifier::dispatch('failed', $job, $fresh, $tenant);
                }
            } catch (\Throwable $e) {
                App::log('notify error run ' . $id . ': ' . $e->getMessage());
            }
        }
    }

    /** Daily cleanup according to each tenant's retention. */
    private function maintenance(): void
    {
        $today = gmdate('Y-m-d');
        if (Settings::get(0, 'maintenance.last') === $today) {
            return;
        }
        Settings::set(0, 'maintenance.last', $today);
        foreach ($this->db->all('SELECT id, log_retention_days FROM tenants') as $t) {
            $cut = gmdate('Y-m-d H:i:s', time() - max(1, (int) $t['log_retention_days']) * 86400);
            $this->db->exec("DELETE FROM job_runs WHERE tenant_id = ? AND created_at < ? AND status NOT IN ('queued','running','retrying')", [$t['id'], $cut]);
            $this->db->exec('DELETE FROM notification_log WHERE tenant_id = ? AND created_at < ?', [$t['id'], $cut]);
        }
        $this->db->exec('DELETE FROM login_attempts WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
        $this->db->exec('DELETE FROM invitations WHERE accepted_at IS NULL AND expires_at < ?', [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
        $this->db->exec('DELETE FROM audit_logs WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 365 * 86400)]);
    }

    /** Recomputes next_run_at after a job is created/edited/enabled. */
    public static function refreshNextRun(int $jobId): void
    {
        $db = App::db();
        $job = $db->one('SELECT j.*, t.timezone AS tenant_tz FROM jobs j JOIN tenants t ON t.id = j.tenant_id WHERE j.id = ?', [$jobId]);
        if (!$job) {
            return;
        }
        $next = null;
        if ($job['enabled']) {
            try {
                $next = CronHelper::nextRuns($job['cron_expr'], $job['timezone'] ?: $job['tenant_tz'], 1, $job['active_from'], $job['active_until'])[0] ?? null;
            } catch (\Throwable) {
                $next = null;
            }
        }
        $db->update('jobs', ['next_run_at' => $next?->format('Y-m-d H:i:s')], 'id = :id', ['id' => $jobId]);
    }
}
