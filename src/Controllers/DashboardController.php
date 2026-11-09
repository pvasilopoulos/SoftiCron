<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Response;
use App\Services\Settings;

final class DashboardController extends Controller
{
    public function index(): Response
    {
        $tenant = $this->tenant();
        $tid = (int) $tenant['id'];
        $range = in_array($this->req->query('range'), ['24h', '7d', '30d'], true) ? $this->req->query('range') : '24h';
        $hours = ['24h' => 24, '7d' => 168, '30d' => 720][$range];
        $since = gmdate('Y-m-d H:i:s', time() - $hours * 3600);
        $prevSince = gmdate('Y-m-d H:i:s', time() - 2 * $hours * 3600);

        $jobCounts = $this->db->one('SELECT COUNT(*) AS total, SUM(enabled) AS active FROM jobs WHERE tenant_id = ?', [$tid]);
        $stat = fn (string $from, string $to) => $this->db->one(
            "SELECT COUNT(*) AS total, SUM(status = 'success') AS ok, SUM(status = 'failed') AS failed, AVG(CASE WHEN status IN ('success','failed') THEN duration_ms END) AS avg_ms
             FROM job_runs WHERE tenant_id = ? AND created_at >= ? AND created_at < ? AND status IN ('success','failed')",
            [$tid, $from, $to],
        );
        $cur = $stat($since, gmdate('Y-m-d H:i:s', time() + 60));
        $prev = $stat($prevSince, $since);
        $rate = fn ($s) => (int) $s['total'] > 0 ? 100 * (int) $s['ok'] / (int) $s['total'] : null;

        // Chart: buckets (hour for 24h, day otherwise)
        $bucketFmt = $range === '24h' ? '%Y-%m-%d %H:00' : '%Y-%m-%d';
        $rows = $this->db->all(
            "SELECT DATE_FORMAT(created_at, '$bucketFmt') AS b, SUM(status = 'success') AS ok, SUM(status = 'failed') AS failed
             FROM job_runs WHERE tenant_id = ? AND created_at >= ? AND status IN ('success','failed') GROUP BY b",
            [$tid, $since],
        );
        $byBucket = array_column($rows, null, 'b');
        $bars = [];
        $n = $range === '24h' ? 24 : ($range === '7d' ? 7 : 30);
        $step = $range === '24h' ? 3600 : 86400;
        $tz = new \DateTimeZone($tenant['timezone']);
        for ($i = $n - 1; $i >= 0; $i--) {
            $t = time() - $i * $step;
            $key = $range === '24h' ? gmdate('Y-m-d H:00', $t) : gmdate('Y-m-d', $t);
            $local = (new \DateTimeImmutable('@' . $t))->setTimezone($tz);
            $bars[] = [
                'label' => $range === '24h' ? $local->format('H:00') : $local->format('d/m'),
                'ok' => (int) ($byBucket[$key]['ok'] ?? 0),
                'failed' => (int) ($byBucket[$key]['failed'] ?? 0),
            ];
        }
        $max = max(1, ...array_map(fn ($b) => $b['ok'] + $b['failed'], $bars));

        $attention = $this->db->all(
            "SELECT j.id, j.name, j.last_status, j.last_run_at, r.id AS run_id, r.http_status, r.error, r.attempts, r.is_slow, r.status AS run_status, r.duration_ms
             FROM jobs j JOIN job_runs r ON r.id = (SELECT MAX(id) FROM job_runs WHERE job_id = j.id AND status IN ('success','failed','missed'))
             WHERE j.tenant_id = ? AND j.enabled = 1 AND (r.status IN ('failed','missed') OR r.is_slow = 1)
             ORDER BY r.id DESC LIMIT 6",
            [$tid],
        );
        $recent = $this->db->all(
            'SELECT r.id, r.status, r.http_status, r.duration_ms, r.is_slow, r.created_at, r.started_at, r.trigger_type, j.name AS job_name, j.method, j.id AS job_id
             FROM job_runs r JOIN jobs j ON j.id = r.job_id WHERE r.tenant_id = ? ORDER BY r.id DESC LIMIT 8',
            [$tid],
        );
        $upcoming = $this->db->all(
            'SELECT id, name, cron_expr, next_run_at FROM jobs WHERE tenant_id = ? AND enabled = 1 AND next_run_at IS NOT NULL ORDER BY next_run_at LIMIT 6',
            [$tid],
        );
        $lastTick = Settings::get(0, 'scheduler.last_tick');
        $schedulerOk = $lastTick && strtotime($lastTick . ' UTC') > time() - 180;

        return $this->view('dashboard', [
            'title' => 'Επισκόπηση',
            'nav' => 'dashboard',
            'range' => $range,
            'jobCounts' => $jobCounts,
            'cur' => $cur,
            'prev' => $prev,
            'rate' => $rate($cur),
            'prevRate' => $rate($prev),
            'bars' => $bars,
            'max' => $max,
            'attention' => $attention,
            'recent' => $recent,
            'upcoming' => $upcoming,
            'schedulerOk' => $schedulerOk,
            'lastTick' => $lastTick,
        ]);
    }

    public function noTenant(): Response
    {
        $this->user();
        if (Auth::tenant()) {
            return $this->redirect('/');
        }
        return $this->view('errors/no_tenant', ['title' => 'Χωρίς οργανισμό'], 'auth_layout');
    }
}
