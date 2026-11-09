<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\ApiTokens;
use App\Services\Audit;
use App\Services\JobIO;
use App\Services\JobVersions;
use App\Services\Reports;
use App\Services\Scheduler;

/** REST API v1 — stateless, authenticated with "Authorization: Bearer sct_…". */
final class ApiController extends Controller
{
    private array $token = [];

    public function __construct(Request $req)
    {
        parent::__construct($req);
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        $token = preg_match('/^Bearer\s+(\S+)$/i', $header, $m) ? $m[1] : trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
        if ($token === '') {
            throw new HttpException(401, 'Λείπει το header Authorization: Bearer <token> (ή X-Api-Key).');
        }
        $resolved = ApiTokens::resolve($token);
        if (!$resolved) {
            throw new HttpException(401, 'Μη έγκυρο, ληγμένο ή ανακληθέν token.');
        }
        [$this->token, $user, $tenant, $perms] = $resolved;
        if (ApiTokens::rateLimited((int) $this->token['id'])) {
            throw new HttpException(429, 'Πάρα πολλά αιτήματα. Όριο: 120 ανά λεπτό ανά token.');
        }
        Auth::actAs($user, $tenant, $perms);
        ApiTokens::touch((int) $this->token['id'], $req->ip());
    }

    private function need(string $permission): void
    {
        if (!Auth::can($permission)) {
            throw new HttpException(403, "Το token δεν έχει δικαίωμα για αυτή την ενέργεια ($permission).");
        }
    }

    private function find(int $id): array
    {
        $job = $this->db->one('SELECT * FROM jobs WHERE id = ? AND tenant_id = ?', [$id, Auth::tenantId()]);
        if (!$job) {
            throw HttpException::notFound('Το job δεν βρέθηκε.');
        }
        return $job;
    }

    private function audit(string $action, int $id, string $name, ?array $before = null, ?array $after = null): void
    {
        Audit::log($action, 'job', $id, $name . ' · API (' . $this->token['name'] . ')', $before, $after);
    }

    private function limit(): array
    {
        $per = max(1, min(200, (int) $this->req->query('per_page', 50)));
        $page = max(1, (int) $this->req->query('page', 1));
        return [$per, $page, ($page - 1) * $per];
    }

    private function listing(array $items, int $total, int $per, int $page): Response
    {
        return $this->json(['data' => $items, 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $per, 'pages' => (int) max(1, ceil($total / $per))]]);
    }

    public function me(): Response
    {
        $u = Auth::user();
        $t = Auth::tenant();
        return $this->json(['data' => [
            'user' => ['id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email']],
            'tenant' => ['id' => (int) $t['id'], 'name' => $t['name'], 'timezone' => $t['timezone']],
            'token' => ['name' => $this->token['name'], 'prefix' => $this->token['prefix'], 'scopes' => explode(',', (string) $this->token['scopes']), 'expires_at' => JobIO::iso($this->token['expires_at'])],
        ]]);
    }

    // ---- Jobs -----------------------------------------------------------------

    public function jobs(): Response
    {
        $this->need('jobs.view');
        [$per, $page, $off] = $this->limit();
        $where = 'FROM jobs WHERE tenant_id = ?';
        $p = [Auth::tenantId()];
        if (($g = (string) $this->req->query('group', '')) !== '') {
            $where .= ' AND group_name = ?';
            $p[] = $g;
        }
        if (($q = (string) $this->req->query('q', '')) !== '') {
            $where .= ' AND (name LIKE ? OR url LIKE ?)';
            array_push($p, "%$q%", "%$q%");
        }
        $where .= match ((string) $this->req->query('status', '')) {
            'active' => ' AND enabled = 1',
            'paused' => ' AND enabled = 0',
            'failing' => " AND enabled = 1 AND last_status = 'failed'",
            default => '',
        };
        $total = (int) $this->db->value("SELECT COUNT(*) $where", $p);
        $rows = $this->db->all("SELECT * $where ORDER BY group_name IS NULL, group_name, name LIMIT $per OFFSET $off", $p);
        return $this->listing(array_map(fn ($j) => JobIO::toArray($j), $rows), $total, $per, $page);
    }

    public function job(int $id): Response
    {
        $this->need('jobs.view');
        return $this->json(['data' => JobIO::toArray($this->find($id))]);
    }

    public function createJob(): Response
    {
        $this->need('jobs.create');
        $tenant = Auth::tenant();
        $body = $this->req->json();
        $_POST = JobIO::toInput($body, null);
        $jc = new JobController($this->req);
        [$data, $rules, $errors, $targets] = $jc->collect(null);
        $count = (int) $this->db->value('SELECT COUNT(*) FROM jobs WHERE tenant_id = ?', [$tenant['id']]);
        if ($count >= (int) $tenant['max_jobs']) {
            $errors[] = 'Έφτασες το όριο των ' . $tenant['max_jobs'] . ' jobs του πλάνου σου.';
        }
        if ($errors) {
            return $this->json(['error' => 'Αποτυχία επικύρωσης.', 'errors' => $errors], 422);
        }
        $id = $this->db->transaction(function () use ($data, $rules, $targets, $tenant, $jc): int {
            $id = $this->db->insert('jobs', $data + ['tenant_id' => $tenant['id'], 'created_by' => Auth::id(), 'ping_token' => bin2hex(random_bytes(16))]);
            $jc->saveRules($id, $rules, $targets);
            return $id;
        });
        Scheduler::refreshNextRun($id);
        JobVersions::snapshot($id, 'Δημιουργία μέσω API');
        $this->audit('job.created', $id, $data['name'], null, $jc->auditable($data));
        return $this->json(['data' => JobIO::toArray($this->find($id))], 201);
    }

    public function updateJob(int $id): Response
    {
        $this->need('jobs.edit');
        $job = $this->find($id);
        $_POST = JobIO::toInput($this->req->json(), $job);
        $jc = new JobController($this->req);
        [$data, $rules, $errors, $targets] = $jc->collect($job);
        if ($errors) {
            return $this->json(['error' => 'Αποτυχία επικύρωσης.', 'errors' => $errors], 422);
        }
        JobVersions::snapshot($id, 'Κατάσταση πριν την αλλαγή');
        $this->db->transaction(function () use ($id, $data, $rules, $targets, $jc): void {
            $this->db->update('jobs', $data, 'id = :id', ['id' => $id]);
            $jc->saveRules($id, $rules, $targets);
        });
        Scheduler::refreshNextRun($id);
        JobVersions::snapshot($id, 'Αλλαγή μέσω API');
        $this->audit('job.updated', $id, $data['name'], $jc->auditable($job), $jc->auditable($data));
        return $this->json(['data' => JobIO::toArray($this->find($id))]);
    }

    public function deleteJob(int $id): Response
    {
        $this->need('jobs.delete');
        $job = $this->find($id);
        $this->db->exec('DELETE FROM jobs WHERE id = ?', [$id]);
        $this->audit('job.deleted', $id, $job['name']);
        return new Response('', 204);
    }

    public function pause(int $id): Response
    {
        return $this->setEnabled($id, 0);
    }

    public function resume(int $id): Response
    {
        return $this->setEnabled($id, 1);
    }

    private function setEnabled(int $id, int $enabled): Response
    {
        $this->need('jobs.toggle');
        $job = $this->find($id);
        if ((int) $job['enabled'] !== $enabled) {
            $this->db->update('jobs', ['enabled' => $enabled], 'id = :id', ['id' => $id]);
            Scheduler::refreshNextRun($id);
            $this->audit($enabled ? 'job.enabled' : 'job.disabled', $id, $job['name']);
        }
        return $this->json(['data' => JobIO::toArray($this->find($id))]);
    }

    /** POST /jobs/{id}/run — runs now and waits for the result (pass ?wait=0 to only queue it). */
    public function run(int $id): Response
    {
        $this->need('jobs.run');
        $job = $this->find($id);
        if ($job['type'] === 'heartbeat') {
            return $this->json(['error' => 'Τα heartbeat jobs δεν εκτελούνται· περιμένουν ping.'], 409);
        }
        $scheduler = new Scheduler();
        $runId = $scheduler->enqueue($job, gmdate('Y-m-d H:i:s'), 'api', Auth::id());
        if (!$runId) {
            return $this->json(['error' => 'Υπάρχει ήδη εκτέλεση μέσω API αυτό το δευτερόλεπτο. Δοκίμασε ξανά.'], 409);
        }
        $this->audit('job.run_now', $id, $job['name']);
        $wait = !in_array((string) $this->req->query('wait', '1'), ['0', 'false', 'no'], true);
        if ($wait) {
            ignore_user_abort(true);
            set_time_limit((int) $job['timeout_sec'] + 30);
            $busy = $job['overlap_policy'] !== 'allow' && $this->db->value("SELECT 1 FROM job_runs WHERE job_id = ? AND status = 'running' AND id <> ?", [$id, $runId]);
            if (!$busy && $scheduler->claim($runId)) {
                $scheduler->executeClaimed([$runId]);
            }
        }
        $run = $this->db->one('SELECT * FROM job_runs WHERE id = ?', [$runId]);
        return $this->json(['data' => $this->runArray($run, $job['name'])], $wait && $run['status'] !== 'queued' ? 200 : 202);
    }

    // ---- Runs -----------------------------------------------------------------

    private function runArray(array $r, ?string $jobName = null, bool $full = false): array
    {
        $out = [
            'id' => (int) $r['id'],
            'job_id' => (int) $r['job_id'],
            'job_name' => $jobName ?? ($r['job_name'] ?? null),
            'status' => $r['status'],
            'trigger' => $r['trigger_type'],
            'attempts' => (int) $r['attempts'],
            'http_status' => $r['http_status'] !== null ? (int) $r['http_status'] : null,
            'duration_ms' => $r['duration_ms'] !== null ? (int) $r['duration_ms'] : null,
            'error' => $r['error'],
            'scheduled_at' => JobIO::iso($r['scheduled_at']),
            'started_at' => JobIO::iso($r['started_at']),
            'finished_at' => JobIO::iso($r['finished_at']),
        ];
        if ($full) {
            $out['request'] = ['method' => $r['request_method'], 'url' => $r['request_url']];
            $out['response_body'] = isset($r['response_body']) ? mb_substr((string) $r['response_body'], 0, 20000) : null;
        }
        return $out;
    }

    public function runs(): Response
    {
        $this->need('runs.view');
        [$per, $page, $off] = $this->limit();
        $where = 'FROM job_runs r JOIN jobs j ON j.id = r.job_id WHERE r.tenant_id = ?';
        $p = [Auth::tenantId()];
        if ($jid = (int) $this->req->query('job_id', 0)) {
            $where .= ' AND r.job_id = ?';
            $p[] = $jid;
        }
        if (($st = (string) $this->req->query('status', '')) !== '') {
            $where .= ' AND r.status = ?';
            $p[] = $st;
        }
        foreach (['since' => '>=', 'until' => '<='] as $k => $op) {
            if (($v = (string) $this->req->query($k, '')) !== '' && ($ts = strtotime($v)) !== false) {
                $where .= " AND r.scheduled_at $op ?";
                $p[] = gmdate('Y-m-d H:i:s', $ts);
            }
        }
        $total = (int) $this->db->value("SELECT COUNT(*) $where", $p);
        $rows = $this->db->all("SELECT r.*, j.name AS job_name $where ORDER BY r.id DESC LIMIT $per OFFSET $off", $p);
        return $this->listing(array_map(fn ($r) => $this->runArray($r), $rows), $total, $per, $page);
    }

    public function runShow(int $id): Response
    {
        $this->need('runs.view');
        $r = $this->db->one('SELECT r.*, j.name AS job_name FROM job_runs r JOIN jobs j ON j.id = r.job_id WHERE r.id = ? AND r.tenant_id = ?', [$id, Auth::tenantId()]);
        if (!$r) {
            throw HttpException::notFound('Η εκτέλεση δεν βρέθηκε.');
        }
        return $this->json(['data' => $this->runArray($r, null, true)]);
    }

    // ---- Reports --------------------------------------------------------------

    public function report(): Response
    {
        $this->need('reports.view');
        $days = max(1, min(365, (int) $this->req->query('days', 30)));
        $to = time();
        $rep = Reports::compute((int) Auth::tenantId(), $to - $days * 86400, $to, (string) $this->req->query('group', ''));
        $jobs = array_map(fn ($j) => [
            'id' => (int) $j['id'], 'name' => $j['name'], 'group' => $j['group'] ?? null,
            'runs' => (int) $j['runs'], 'success_rate' => $j['success_rate'], 'uptime' => $j['uptime'],
            'downtime_min' => $j['downtime_min'], 'avg_ms' => $j['avg_ms'], 'p95_ms' => $j['p95_ms'],
            'sla_target' => $j['sla'] !== null ? (float) $j['sla'] : null, 'sla_met' => $j['sla_met'],
        ], $rep['jobs']);
        return $this->json(['data' => ['days' => $days, 'from' => JobIO::iso($rep['from']), 'to' => JobIO::iso($rep['to']), 'totals' => $rep['totals'], 'jobs' => $jobs]]);
    }
}
