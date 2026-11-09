<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\Audit;
use App\Services\Scheduler;

final class RunController extends Controller
{
    public const TRIGGERS = ['schedule' => 'Πρόγραμμα', 'manual' => 'Χειροκίνητη', 'missed' => 'Χαμένη'];
    public const HTTP_CLASSES = ['2xx' => '2xx Επιτυχία', '3xx' => '3xx Ανακατεύθυνση', '4xx' => '4xx Σφάλμα client', '5xx' => '5xx Σφάλμα server', 'none' => 'Χωρίς απάντηση'];
    public const PERIODS = ['1h' => 'Τελευταία ώρα', '24h' => '24 ώρες', '7d' => '7 ημέρες', '30d' => '30 ημέρες'];

    /** Reads the filters from the query string and builds the WHERE clause. */
    private function filters(): array
    {
        $tid = $this->tenantId();
        $f = [
            'status' => (string) $this->req->query('status', ''),
            'job' => (int) $this->req->query('job', 0),
            'group' => (string) $this->req->query('group', ''),
            'trigger' => (string) $this->req->query('trigger', ''),
            'http' => (string) $this->req->query('http', ''),
            'period' => (string) $this->req->query('period', ''),
            'from' => (string) $this->req->query('from', ''),
            'to' => (string) $this->req->query('to', ''),
            'slow' => (string) $this->req->query('slow', '') === '1' ? '1' : '',
            'q' => (string) $this->req->query('q', ''),
        ];
        $where = 'FROM job_runs r JOIN jobs j ON j.id = r.job_id WHERE r.tenant_id = :tid';
        $p = ['tid' => $tid];
        if (isset(RUN_STATUS[$f['status']])) {
            $where .= ' AND r.status = :status';
            $p['status'] = $f['status'];
        } else {
            $f['status'] = '';
        }
        if ($f['job']) {
            $where .= ' AND r.job_id = :job';
            $p['job'] = $f['job'];
        }
        if ($f['group'] !== '') {
            $where .= $f['group'] === '__none' ? ' AND j.group_name IS NULL' : ' AND j.group_name = :grp';
            if ($f['group'] !== '__none') {
                $p['grp'] = $f['group'];
            }
        }
        if (isset(self::TRIGGERS[$f['trigger']])) {
            $where .= ' AND r.trigger_type = :trg';
            $p['trg'] = $f['trigger'];
        } else {
            $f['trigger'] = '';
        }
        if (isset(self::HTTP_CLASSES[$f['http']])) {
            $where .= $f['http'] === 'none' ? ' AND r.http_status IS NULL' : ' AND r.http_status BETWEEN :hlo AND :hhi';
            if ($f['http'] !== 'none') {
                $p['hlo'] = (int) $f['http'][0] * 100;
                $p['hhi'] = (int) $f['http'][0] * 100 + 99;
            }
        } else {
            $f['http'] = '';
        }
        if (isset(self::PERIODS[$f['period']])) {
            $secs = ['1h' => 3600, '24h' => 86400, '7d' => 604800, '30d' => 2592000][$f['period']];
            $where .= ' AND r.created_at >= :since';
            $p['since'] = gmdate('Y-m-d H:i:s', time() - $secs);
        } else {
            $f['period'] = '';
        }
        $tz = new \DateTimeZone($this->tenant()['timezone']);
        foreach (['from' => '00:00:00', 'to' => '23:59:59'] as $k => $time) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$k])) {
                $where .= $k === 'from' ? ' AND r.created_at >= :dfrom' : ' AND r.created_at <= :dto';
                $p[$k === 'from' ? 'dfrom' : 'dto'] = (new \DateTimeImmutable($f[$k] . ' ' . $time, $tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            } else {
                $f[$k] = '';
            }
        }
        if ($f['slow']) {
            $where .= ' AND r.is_slow = 1';
        }
        if ($f['q'] !== '') {
            $where .= ' AND (r.error LIKE :q OR r.request_url LIKE :q2 OR j.name LIKE :q3 OR r.id = :qid)';
            $p['q'] = $p['q2'] = $p['q3'] = '%' . $f['q'] . '%';
            $p['qid'] = ctype_digit(ltrim($f['q'], '#')) ? (int) ltrim($f['q'], '#') : 0;
        }
        return [$where, $p, $f];
    }

    public function index(): Response
    {
        $this->authorize('runs.view');
        $tid = $this->tenantId();
        [$where, $p, $f] = $this->filters();
        $page = $this->paginate(
            $where,
            $p,
            'r.id, r.status, r.http_status, r.duration_ms, r.is_slow, r.attempts, r.trigger_type, r.scheduled_at, r.started_at, r.created_at, r.error, r.request_url, j.id AS job_id, j.name AS job_name, j.method, j.group_name, j.max_retries',
            'r.id DESC',
            40,
        );
        // Counts per status for the same filters (except status) → quick status chips.
        $fNoStatus = $f;
        $whereNoStatus = preg_replace('/ AND r\.status = :status/', '', $where);
        $pNoStatus = $p;
        unset($pNoStatus['status']);
        $counts = array_column($this->db->all("SELECT r.status, COUNT(*) AS n $whereNoStatus GROUP BY r.status", $pNoStatus), 'n', 'status');
        $maxDur = (int) $this->db->value("SELECT MAX(r.duration_ms) $whereNoStatus", $pNoStatus);
        $jobs = $this->db->all('SELECT id, name FROM jobs WHERE tenant_id = ? ORDER BY name', [$tid]);
        $groups = $this->db->column('SELECT DISTINCT group_name FROM jobs WHERE tenant_id = ? AND group_name IS NOT NULL ORDER BY group_name', [$tid]);
        return $this->view('runs/index', [
            'title' => 'Εκτελέσεις', 'nav' => 'runs', 'page' => $page, 'f' => $f, 'jobs' => $jobs, 'groups' => $groups,
            'counts' => $counts, 'maxDur' => max(1, $maxDur), 'canRun' => Auth::can('jobs.run'),
        ]);
    }

    /** CSV export with the same filters as the list (max 10.000 rows). */
    public function export(): Response
    {
        $this->authorize('runs.view');
        [$where, $p] = $this->filters();
        $tz = $this->tenant()['timezone'];
        $rows = $this->db->all(
            "SELECT r.id, j.name AS job_name, j.group_name, r.status, r.http_status, r.duration_ms, r.attempts, r.trigger_type, r.scheduled_at, r.started_at, r.finished_at, r.request_method, r.request_url, r.error
             $where ORDER BY r.id DESC LIMIT 10000",
            $p,
        );
        $out = array_map(fn ($r) => [
            $r['id'], $r['job_name'], $r['group_name'], RUN_STATUS[$r['status']][0] ?? $r['status'], $r['http_status'], $r['duration_ms'], $r['attempts'],
            self::TRIGGERS[$r['trigger_type']] ?? $r['trigger_type'], fmt_dt($r['scheduled_at'], $tz, 'Y-m-d H:i:s'),
            $r['started_at'] ? fmt_dt($r['started_at'], $tz, 'Y-m-d H:i:s') : '', $r['finished_at'] ? fmt_dt($r['finished_at'], $tz, 'Y-m-d H:i:s') : '',
            $r['request_method'], $r['request_url'], $r['error'],
        ], $rows);
        return Response::csv('executions-' . date('Ymd-His') . '.csv', ['#', 'Job', 'Ομάδα', 'Κατάσταση', 'HTTP', 'Διάρκεια (ms)', 'Προσπάθειες', 'Αφορμή', 'Προγραμματισμένη', 'Έναρξη', 'Λήξη', 'Μέθοδος', 'URL', 'Σφάλμα'], $out);
    }

    private function find(int $id): array
    {
        $run = $this->db->one(
            'SELECT r.*, j.name AS job_name, j.timezone AS job_tz, j.max_retries FROM job_runs r JOIN jobs j ON j.id = r.job_id WHERE r.id = ? AND r.tenant_id = ?',
            [$id, $this->tenantId()],
        );
        if (!$run) {
            throw HttpException::notFound('Η εκτέλεση δεν βρέθηκε.');
        }
        return $run;
    }

    public function show(int $id): Response
    {
        $this->authorize('runs.view');
        $run = $this->find($id);
        $attempts = $this->db->all('SELECT * FROM job_run_attempts WHERE run_id = ? ORDER BY attempt_no', [$id]);
        $notifications = $this->db->all('SELECT * FROM notification_log WHERE run_id = ? ORDER BY id', [$id]);
        $user = $run['triggered_by'] ? $this->db->one('SELECT name FROM users WHERE id = ?', [$run['triggered_by']]) : null;
        $body = (string) ($run['response_body'] ?? '');
        $decoded = json_decode($body, true);
        if ($decoded !== null && is_array($decoded)) {
            $body = (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $prev = $this->db->value('SELECT MAX(id) FROM job_runs WHERE job_id = ? AND id < ?', [$run['job_id'], $id]);
        $next = $this->db->value('SELECT MIN(id) FROM job_runs WHERE job_id = ? AND id > ?', [$run['job_id'], $id]);
        return $this->view('runs/show', [
            'title' => 'Εκτέλεση #' . $id,
            'nav' => 'runs',
            'run' => $run,
            'attempts' => $attempts,
            'notifications' => $notifications,
            'triggeredBy' => $user['name'] ?? null,
            'body' => $body,
            'canRun' => Auth::can('jobs.run'),
            'live' => in_array($run['status'], ['queued', 'running', 'retrying'], true),
            'prevId' => $prev ? (int) $prev : null,
            'nextId' => $next ? (int) $next : null,
        ]);
    }

    public function retry(int $id): Response
    {
        $this->authorize('jobs.run');
        $run = $this->find($id);
        $job = $this->db->one('SELECT * FROM jobs WHERE id = ?', [$run['job_id']]);
        $scheduler = new Scheduler();
        $newId = $scheduler->enqueue($job, gmdate('Y-m-d H:i:s'), 'manual', Auth::id());
        if (!$newId) {
            $this->flash('error', 'Υπάρχει ήδη χειροκίνητη εκτέλεση αυτή τη στιγμή.');
            return $this->redirect("runs/$id");
        }
        Audit::log('run.retried', 'run', $id, $job['name'] . ' → #' . $newId);
        ignore_user_abort(true);
        set_time_limit((int) $job['timeout_sec'] + 30);
        if ($scheduler->claim($newId)) {
            $scheduler->executeClaimed([$newId]);
        }
        if ($this->req->input('from') === 'list') {
            $st = $this->db->value('SELECT status FROM job_runs WHERE id = ?', [$newId]);
            $this->flash($st === 'failed' ? 'error' : 'success', '«' . $job['name'] . '»: νέα εκτέλεση #' . $newId . ' · ' . (RUN_STATUS[$st][0] ?? $st));
            return $this->back('runs');
        }
        return $this->redirect("runs/$newId");
    }
}
