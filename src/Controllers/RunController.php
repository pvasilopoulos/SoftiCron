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
    public function index(): Response
    {
        $this->authorize('runs.view');
        $tid = $this->tenantId();
        $status = (string) $this->req->query('status', '');
        $jobId = (int) $this->req->query('job', 0);
        $where = 'FROM job_runs r JOIN jobs j ON j.id = r.job_id WHERE r.tenant_id = :tid';
        $params = ['tid' => $tid];
        if (isset(RUN_STATUS[$status])) {
            $where .= ' AND r.status = :status';
            $params['status'] = $status;
        }
        if ($jobId) {
            $where .= ' AND r.job_id = :job';
            $params['job'] = $jobId;
        }
        $page = $this->paginate(
            $where,
            $params,
            'r.id, r.status, r.http_status, r.duration_ms, r.is_slow, r.attempts, r.trigger_type, r.scheduled_at, r.started_at, r.created_at, r.error, j.id AS job_id, j.name AS job_name, j.method',
            'r.id DESC',
            40,
        );
        $jobs = $this->db->all('SELECT id, name FROM jobs WHERE tenant_id = ? ORDER BY name', [$tid]);
        return $this->view('runs/index', [
            'title' => 'Εκτελέσεις', 'nav' => 'runs', 'page' => $page, 'status' => $status, 'jobId' => $jobId, 'jobs' => $jobs,
        ]);
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
        return $this->redirect("runs/$newId");
    }
}
