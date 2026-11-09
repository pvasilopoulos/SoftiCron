<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Services\Scheduler;

/**
 * Public heartbeat endpoints (no login, no CSRF):
 *   /ping/{token}         success
 *   /ping/{token}/start   job started (measures duration)
 *   /ping/{token}/fail    job failed
 * The request body (or ?msg=) is stored as the run output (first 10 KB).
 */
final class PingController extends Controller
{
    public function success(string $token): Response
    {
        return $this->handle($token, 'success');
    }

    public function start(string $token): Response
    {
        return $this->handle($token, 'start');
    }

    public function fail(string $token): Response
    {
        return $this->handle($token, 'fail');
    }

    private function handle(string $token, string $kind): Response
    {
        $text = fn (string $s, int $code = 200) => new Response($s . "\n", $code, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return $text('not found', 404);
        }
        $job = $this->db->one(
            "SELECT j.* FROM jobs j JOIN tenants t ON t.id = j.tenant_id WHERE j.ping_token = ? AND j.type = 'heartbeat' AND t.status = 'active'",
            [$token],
        );
        if (!$job) {
            return $text('not found', 404);
        }
        if (!$job['enabled']) {
            return $text('paused');
        }
        $tenant = $this->db->one('SELECT * FROM tenants WHERE id = ?', [$job['tenant_id']]);
        $now = $this->db->now();
        $output = mb_substr((string) file_get_contents('php://input', false, null, 0, 10240), 0, 10240);
        if ($output === '' && isset($_GET['msg'])) {
            $output = mb_substr((string) $_GET['msg'], 0, 2000);
        }
        $output = mb_check_encoding($output, 'UTF-8') ? $output : mb_convert_encoding($output, 'UTF-8', 'ISO-8859-1');
        $ip = $this->req->ip();

        if ($kind === 'start') {
            $this->db->insert('job_runs', [
                'tenant_id' => $job['tenant_id'], 'job_id' => $job['id'], 'trigger_type' => 'ping', 'status' => 'running',
                'scheduled_at' => $now, 'started_at' => $now, 'request_method' => 'PING', 'request_url' => 'start από ' . $ip,
            ], true);
            $this->db->update('jobs', ['last_ping_at' => $now], 'id = :id', ['id' => $job['id']]);
            return $text('OK');
        }

        $ok = $kind === 'success';
        // Close an open "start" ping from the last 24 h, otherwise create a new run.
        $open = $this->db->one(
            "SELECT * FROM job_runs WHERE job_id = ? AND trigger_type = 'ping' AND status = 'running' AND started_at > UTC_TIMESTAMP() - INTERVAL 1 DAY ORDER BY id DESC LIMIT 1",
            [$job['id']],
        );
        $data = [
            'status' => $ok ? 'success' : 'failed',
            'attempts' => 1,
            'finished_at' => $now,
            'error' => $ok ? null : 'Το script ανέφερε αποτυχία' . ($output !== '' ? ': ' . mb_substr(trim($output), 0, 300) : '.'),
            'response_body' => $output !== '' ? $output : null,
            'response_size' => strlen($output),
            'request_method' => 'PING',
            'request_url' => ($ok ? 'success' : 'fail') . ' από ' . $ip,
        ];
        if ($open) {
            $data['duration_ms'] = max(0, (strtotime($now . ' UTC') - strtotime($open['started_at'] . ' UTC')) * 1000);
            $this->db->update('job_runs', $data, 'id = :id', ['id' => $open['id']]);
            $runId = (int) $open['id'];
        } else {
            $runId = $this->db->insert('job_runs', $data + [
                'tenant_id' => $job['tenant_id'], 'job_id' => $job['id'], 'trigger_type' => 'ping',
                'scheduled_at' => $now, 'started_at' => $now, 'duration_ms' => null,
            ], true);
            if (!$runId) {
                return $text('OK'); // duplicate ping in the same second
            }
        }
        $this->db->update('jobs', [
            'last_ping_at' => $now, 'last_run_at' => $now, 'last_status' => $data['status'],
            'last_duration_ms' => $data['duration_ms'] ?? null,
        ], 'id = :id', ['id' => $job['id']]);
        $run = $this->db->one('SELECT * FROM job_runs WHERE id = ?', [$runId]);
        $slow = $ok && $job['slow_threshold_ms'] && isset($data['duration_ms']) && $data['duration_ms'] > (int) $job['slow_threshold_ms'];
        if ($slow) {
            $this->db->update('job_runs', ['is_slow' => 1], 'id = :id', ['id' => $runId]);
        }
        Scheduler::afterOutcome($job, $run, $tenant, $ok, (bool) $slow);
        return $text('OK');
    }
}
