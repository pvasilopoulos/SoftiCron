<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Services\CronHelper;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Runs calendar: month grid with past results and planned runs, and a day view with an hourly
 * heat map and the run times per job — to spot clusters (e.g. 30 jobs at 03:00).
 */
final class CalendarController extends Controller
{
    /** Max planned occurrences computed per job and range; beyond it the rate is extrapolated. */
    private const CAP = 1500;

    public function index(): Response
    {
        $this->authorize('runs.view');
        $tenant = $this->tenant();
        $tid = (int) $tenant['id'];
        $tz = new DateTimeZone($tenant['timezone'] ?: 'Europe/Athens');
        $now = new DateTimeImmutable('now', $tz);

        $month = (string) $this->req->query('month', $now->format('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = $now->format('Y-m');
        }
        $first = new DateTimeImmutable($month . '-01 00:00:00', $tz);
        $next = $first->modify('first day of next month');
        $day = (string) $this->req->query('day', '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !str_starts_with($day, $month)) {
            $day = $now->format('Y-m') === $month ? $now->format('Y-m-d') : $first->format('Y-m-d');
        }
        $group = (string) $this->req->query('group', '');
        $jobFilter = (int) $this->req->query('job', 0);

        $sql = 'SELECT id, name, group_name, type, enabled, cron_expr, timezone, active_from, active_until FROM jobs WHERE tenant_id = ?';
        $p = [$tid];
        if ($group === '__none') {
            $sql .= ' AND group_name IS NULL';
        } elseif ($group !== '') {
            $sql .= ' AND group_name = ?';
            $p[] = $group;
        }
        if ($jobFilter) {
            $sql .= ' AND id = ?';
            $p[] = $jobFilter;
        }
        $jobs = $this->db->all($sql . ' ORDER BY name', $p);
        $jobIds = array_map('intval', array_column($jobs, 'id'));
        $byId = array_column($jobs, null, 'id');

        // ---- Month: past results per local day ----
        $offset = $tz->getOffset($first);
        $past = [];
        if ($jobIds) {
            $in = implode(',', $jobIds);
            foreach ($this->db->all(
                "SELECT DATE(COALESCE(started_at, scheduled_at) + INTERVAL $offset SECOND) AS d,
                        SUM(status = 'success') AS ok, SUM(status = 'failed') AS failed, SUM(status IN ('missed','skipped')) AS other
                 FROM job_runs WHERE job_id IN ($in) AND COALESCE(started_at, scheduled_at) >= ? AND COALESCE(started_at, scheduled_at) < ?
                 GROUP BY d",
                [$this->utc($first), $this->utc($next)],
            ) as $r) {
                $past[$r['d']] = ['ok' => (int) $r['ok'], 'failed' => (int) $r['failed'], 'other' => (int) $r['other']];
            }
        }
        // ---- Month: planned runs per local day (from now on) ----
        $planned = [];
        $dense = [];
        $fromPlan = max($now, $first);
        if ($fromPlan < $next) {
            foreach ($jobs as $j) {
                if (!$j['enabled']) {
                    continue;
                }
                [$times, $capped] = $this->occurrences($j, $fromPlan, $next);
                foreach ($times as $t) {
                    $d = $t->setTimezone($tz)->format('Y-m-d');
                    $planned[$d] = ($planned[$d] ?? 0) + 1;
                }
                if ($capped && $times) {
                    // Extrapolate the rest of the month at the same rate.
                    $dense[(int) $j['id']] = true;
                    $span = max(60, end($times)->getTimestamp() - $fromPlan->getTimestamp());
                    $rate = count($times) / $span;
                    $cursor = end($times)->setTimezone($tz);
                    while ($cursor < $next) {
                        $dayEnd = min($cursor->modify('tomorrow'), $next);
                        $d = $cursor->format('Y-m-d');
                        $planned[$d] = ($planned[$d] ?? 0) + (int) round($rate * ($dayEnd->getTimestamp() - $cursor->getTimestamp()));
                        $cursor = $dayEnd;
                    }
                }
            }
        }

        // ---- Selected day: hourly load + run times per job ----
        $dStart = new DateTimeImmutable($day . ' 00:00:00', $tz);
        $dEnd = $dStart->modify('+1 day');
        $hours = array_fill(0, 24, ['done' => 0, 'failed' => 0, 'planned' => 0]);
        $perJob = [];
        if ($jobIds) {
            $in = implode(',', $jobIds);
            foreach ($this->db->all(
                "SELECT id, job_id, status, is_slow, http_status, duration_ms, COALESCE(started_at, scheduled_at) AS t FROM job_runs
                 WHERE job_id IN ($in) AND COALESCE(started_at, scheduled_at) >= ? AND COALESCE(started_at, scheduled_at) < ?
                 ORDER BY t LIMIT 5000",
                [$this->utc($dStart), $this->utc($dEnd)],
            ) as $r) {
                $local = (new DateTimeImmutable($r['t'], new DateTimeZone('UTC')))->setTimezone($tz);
                $h = (int) $local->format('G');
                $hours[$h]['done']++;
                if ($r['status'] === 'failed') {
                    $hours[$h]['failed']++;
                }
                $perJob[(int) $r['job_id']]['runs'][] = ['id' => (int) $r['id'], 'time' => $local->format('H:i'), 'status' => $r['status'], 'slow' => (bool) $r['is_slow'], 'http' => $r['http_status'], 'ms' => $r['duration_ms']];
            }
        }
        $planFrom = max($now, $dStart);
        if ($planFrom < $dEnd) {
            foreach ($jobs as $j) {
                if (!$j['enabled']) {
                    continue;
                }
                [$times] = $this->occurrences($j, $planFrom, $dEnd);
                foreach ($times as $t) {
                    $local = $t->setTimezone($tz);
                    $hours[(int) $local->format('G')]['planned']++;
                    $perJob[(int) $j['id']]['planned'][] = $local->format('H:i');
                }
            }
        }
        uksort($perJob, fn ($a, $b) => strcmp((string) ($byId[$a]['name'] ?? ''), (string) ($byId[$b]['name'] ?? '')));
        $peak = max(array_map(fn ($h) => $h['done'] + $h['planned'], $hours)) ?: 0;

        $groups = $this->db->column('SELECT DISTINCT group_name FROM jobs WHERE tenant_id = ? AND group_name IS NOT NULL ORDER BY group_name', [$tid]);
        $allJobs = $this->db->all('SELECT id, name FROM jobs WHERE tenant_id = ? ORDER BY name', [$tid]);

        return $this->view('calendar/index', [
            'title' => 'Ημερολόγιο', 'nav' => 'calendar', 'tz' => $tz->getName(), 'now' => $now,
            'first' => $first, 'next' => $next, 'month' => $month, 'day' => $day,
            'past' => $past, 'planned' => $planned, 'dense' => $dense,
            'hours' => $hours, 'peak' => $peak, 'perJob' => $perJob, 'jobs' => $byId,
            'group' => $group, 'jobFilter' => $jobFilter, 'groups' => $groups, 'allJobs' => $allJobs,
        ]);
    }

    /**
     * Planned run times of a job in [from, to), in UTC.
     * @return array{0: DateTimeImmutable[], 1: bool} [times, capped]
     */
    private function occurrences(array $job, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if (!CronHelper::isValid((string) $job['cron_expr'])) {
            return [[], false];
        }
        $out = [];
        $cursor = $from->setTimezone(new DateTimeZone('UTC'))->modify('-1 second');
        $tz = $job['timezone'] ?: 'Europe/Athens';
        while (count($out) < self::CAP) {
            $batch = CronHelper::nextRuns((string) $job['cron_expr'], $tz, 200, $job['active_from'] ?: null, $job['active_until'] ?: null, $cursor);
            if (!$batch) {
                break;
            }
            foreach ($batch as $t) {
                if ($t >= $to) {
                    return [$out, false];
                }
                $out[] = $t;
                if (count($out) >= self::CAP) {
                    return [$out, true];
                }
            }
            $cursor = end($batch);
        }
        return [$out, false];
    }

    private function utc(DateTimeImmutable $d): string
    {
        return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
