<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Crypto;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\Session;
use App\Services\Audit;
use App\Services\Connectors;
use App\Services\CronHelper;
use App\Services\HttpExecutor;
use App\Services\Notifier;
use App\Services\Scheduler;
use App\Services\SsrfGuard;
use App\Services\TemplateRenderer;

final class JobController extends Controller
{
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'];

    public function index(): Response
    {
        $this->authorize('jobs.view');
        $tid = $this->tenantId();
        $filter = (string) $this->req->query('filter', 'all');
        $q = (string) $this->req->query('q', '');
        $where = 'FROM jobs j WHERE j.tenant_id = :tid';
        $params = ['tid' => $tid];
        if ($q !== '') {
            $where .= ' AND (j.name LIKE :q OR j.url LIKE :q2 OR j.group_name LIKE :q3 OR j.description LIKE :q4 OR j.cron_expr LIKE :q5)';
            $params['q'] = '%' . $q . '%';
            $params['q2'] = '%' . $q . '%';
            $params['q3'] = '%' . $q . '%';
            $params['q4'] = $params['q5'] = '%' . $q . '%';
        }
        $group = (string) $this->req->query('group', '');
        if ($group === '__none') {
            $where .= ' AND j.group_name IS NULL';
        } elseif ($group !== '') {
            $where .= ' AND j.group_name = :grp';
            $params['grp'] = $group;
        }
        $where .= match ($filter) {
            'active' => ' AND j.enabled = 1',
            'issue' => " AND j.enabled = 1 AND j.last_status = 'failed'",
            'paused' => ' AND j.enabled = 0',
            'never' => ' AND j.last_status IS NULL',
            'slow' => ' AND j.enabled = 1 AND j.slow_threshold_ms IS NOT NULL AND j.last_duration_ms > j.slow_threshold_ms',
            default => '',
        };
        $type = (string) $this->req->query('type', '');
        if (in_array($type, ['http', 'heartbeat'], true)) {
            $where .= ' AND j.type = :type';
            $params['type'] = $type;
        }
        $method = strtoupper((string) $this->req->query('method', ''));
        if (in_array($method, self::METHODS, true)) {
            $where .= " AND j.method = :method AND j.type <> 'heartbeat'";
            $params['method'] = $method;
        }
        $sorts = [
            'group' => 'j.group_name IS NULL, j.group_name ASC, j.name ASC', // grouped first, ungrouped last
            'name' => 'j.name ASC',
            'status' => "CASE WHEN j.enabled = 1 AND j.last_status = 'failed' THEN 0 WHEN j.enabled = 0 THEN 2 ELSE 1 END, j.name ASC",
            'last_run' => 'j.last_run_at IS NULL, j.last_run_at DESC',
            'next_run' => 'j.enabled DESC, j.next_run_at IS NULL, j.next_run_at ASC',
            'duration' => 'j.last_duration_ms IS NULL, j.last_duration_ms DESC',
            'created' => 'j.id DESC',
        ];
        $sort = (string) $this->req->query('sort', 'group');
        if (!isset($sorts[$sort])) {
            $sort = 'group';
        }
        $per = (int) $this->req->query('per', 50);
        $per = in_array($per, [25, 50, 100], true) ? $per : 50;
        $page = $this->paginate($where, $params, 'j.*', $sorts[$sort] . ', j.id ASC', $per);
        $counts = $this->db->one(
            "SELECT COUNT(*) AS all_count, SUM(enabled = 1) AS active, SUM(enabled = 1 AND last_status = 'failed') AS issue, SUM(enabled = 0) AS paused,
                    SUM(last_status IS NULL) AS never, SUM(enabled = 1 AND slow_threshold_ms IS NOT NULL AND last_duration_ms > slow_threshold_ms) AS slow,
                    SUM(type = 'heartbeat') AS heartbeat
             FROM jobs WHERE tenant_id = ?",
            [$tid],
        );

        // Recent run history + 7-day stats of the jobs on this page.
        $recent = $stats = [];
        $ids = array_map('intval', array_column($page['rows'], 'id'));
        if ($ids) {
            $in = implode(',', $ids);
            foreach ($this->db->all(
                "SELECT job_id, id, status, duration_ms, is_slow, http_status, t FROM (
                   SELECT job_id, id, status, duration_ms, is_slow, http_status, COALESCE(started_at, scheduled_at) AS t,
                          ROW_NUMBER() OVER (PARTITION BY job_id ORDER BY id DESC) AS rn
                   FROM job_runs WHERE job_id IN ($in) AND status IN ('success','failed','missed','skipped','running','retrying')
                     AND created_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY
                 ) x WHERE rn <= 20 ORDER BY job_id, id",
            ) as $r) {
                $recent[(int) $r['job_id']][] = $r;
            }
            foreach ($this->db->all(
                "SELECT job_id, SUM(status = 'success') AS ok, SUM(status = 'failed') AS failed,
                        AVG(CASE WHEN status = 'success' THEN duration_ms END) AS avg_ms
                 FROM job_runs WHERE job_id IN ($in) AND status IN ('success','failed') AND created_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY
                 GROUP BY job_id",
            ) as $r) {
                $stats[(int) $r['job_id']] = $r;
            }
        }
        $kpi = $this->db->one(
            "SELECT COUNT(*) AS runs, SUM(status = 'success') AS ok, SUM(status = 'failed') AS failed,
                    AVG(CASE WHEN status = 'success' THEN duration_ms END) AS avg_ms
             FROM job_runs WHERE tenant_id = ? AND status IN ('success','failed') AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY",
            [$tid],
        );
        $kpi['running'] = (int) $this->db->value("SELECT COUNT(*) FROM job_runs WHERE tenant_id = ? AND status IN ('running','queued','retrying')", [$tid]);
        $kpi['next'] = $this->db->one(
            'SELECT id, name, next_run_at FROM jobs WHERE tenant_id = ? AND enabled = 1 AND next_run_at IS NOT NULL ORDER BY next_run_at LIMIT 1',
            [$tid],
        );
        $groupStats = [];
        foreach ($this->db->all(
            "SELECT COALESCE(group_name, '') AS g, COUNT(*) AS n, SUM(enabled = 1) AS active, SUM(enabled = 1 AND last_status = 'failed') AS failing
             FROM jobs WHERE tenant_id = ? GROUP BY COALESCE(group_name, '')",
            [$tid],
        ) as $row) {
            $groupStats[$row['g']] = $row;
        }
        return $this->view('jobs/index', [
            'title' => 'Jobs', 'nav' => 'jobs', 'page' => $page, 'filter' => $filter, 'q' => $q, 'counts' => $counts,
            'group' => $group, 'groups' => $this->groups(), 'groupStats' => $groupStats,
            'type' => $type, 'method' => $method, 'sort' => $sort, 'per' => $per, 'recent' => $recent, 'stats' => $stats, 'kpi' => $kpi,
        ]);
    }

    /** Bulk actions from the jobs list: enable, pause, run, group, delete. */
    public function bulk(): Response
    {
        $action = (string) $this->req->input('action', '');
        $perm = ['enable' => 'jobs.toggle', 'pause' => 'jobs.toggle', 'run' => 'jobs.run', 'group' => 'jobs.edit', 'delete' => 'jobs.delete'][$action] ?? null;
        if ($perm === null) {
            throw new HttpException(422, 'Άγνωστη ενέργεια.');
        }
        $this->authorize($perm);
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $this->req->input('ids', [])))));
        $jobs = $ids ? $this->db->all(
            'SELECT * FROM jobs WHERE tenant_id = ? AND id IN (' . implode(',', $ids) . ')',
            [$this->tenantId()],
        ) : [];
        if (!$jobs) {
            return $this->bulkDone('Δεν επιλέχθηκαν jobs.', true);
        }
        $n = count($jobs);
        switch ($action) {
            case 'enable':
            case 'pause':
                $on = $action === 'enable' ? 1 : 0;
                foreach ($jobs as $j) {
                    if ((int) $j['enabled'] !== $on) {
                        $this->db->update('jobs', ['enabled' => $on], 'id = :id', ['id' => $j['id']]);
                        Scheduler::refreshNextRun((int) $j['id']);
                        Audit::log($on ? 'job.enabled' : 'job.disabled', 'job', (int) $j['id'], $j['name']);
                    }
                }
                return $this->bulkDone($on ? "Ενεργοποιήθηκαν $n jobs." : "Τέθηκαν σε παύση $n jobs.");
            case 'group':
                $g = trim((string) $this->req->input('group', ''));
                $g = $g === '' ? null : mb_substr($g, 0, 100);
                foreach ($jobs as $j) {
                    if ($j['group_name'] !== $g) {
                        $this->db->update('jobs', ['group_name' => $g], 'id = :id', ['id' => $j['id']]);
                        \App\Services\JobVersions::snapshot((int) $j['id'], $g === null ? 'Αφαίρεση από ομάδα' : 'Μετακίνηση στην ομάδα «' . $g . '»');
                        Audit::log('job.updated', 'job', (int) $j['id'], $j['name'], ['group_name' => $j['group_name']], ['group_name' => $g]);
                    }
                }
                return $this->bulkDone($g === null ? "Αφαιρέθηκαν $n jobs από την ομάδα τους." : "Μετακινήθηκαν $n jobs στην ομάδα «{$g}».");
            case 'delete':
                foreach ($jobs as $j) {
                    $this->db->exec('DELETE FROM jobs WHERE id = ?', [$j['id']]);
                    Audit::log('job.deleted', 'job', (int) $j['id'], $j['name'], $this->auditable($j), null);
                }
                return $this->bulkDone("Διαγράφηκαν $n jobs.");
            default: // run
                $scheduler = new Scheduler();
                $runIds = [];
                $skipped = 0;
                foreach ($jobs as $j) {
                    if ($j['type'] === 'heartbeat') {
                        $skipped++;
                        continue;
                    }
                    $runId = $scheduler->enqueue($j, gmdate('Y-m-d H:i:s'), 'manual', Auth::id());
                    if ($runId) {
                        $runIds[] = $runId;
                        Audit::log('job.run_now', 'job', (int) $j['id'], $j['name']);
                    } else {
                        $skipped++;
                    }
                }
                // Run up to 20 immediately (in parallel); the rest wait for the scheduler.
                ignore_user_abort(true);
                set_time_limit(180);
                Session::release();
                $claimed = array_values(array_filter(array_slice($runIds, 0, 20), fn ($id) => $scheduler->claim($id)));
                if ($claimed) {
                    $scheduler->executeClaimed($claimed);
                }
                $res = $runIds ? $this->db->one(
                    "SELECT SUM(status = 'success') AS ok, SUM(status IN ('failed','retrying')) AS failed FROM job_runs WHERE id IN (" . implode(',', $runIds) . ')',
                ) : ['ok' => 0, 'failed' => 0];
                $msg = 'Εκτελέστηκαν ' . count($runIds) . ' jobs: ' . (int) $res['ok'] . ' επιτυχίες, ' . (int) $res['failed'] . ' αποτυχίες'
                    . (count($runIds) > count($claimed) ? ', ' . (count($runIds) - count($claimed)) . ' σε αναμονή' : '')
                    . ($skipped ? " ($skipped παραλείφθηκαν)" : '') . '.';
                return $this->bulkDone($msg, (int) $res['failed'] > 0);
        }
    }

    private function bulkDone(string $msg, bool $error = false): Response
    {
        if ($this->req->wantsJson()) {
            return $this->json(['message' => $msg, 'error' => $error]);
        }
        $this->flash($error ? 'error' : 'success', $msg);
        return $this->back('jobs');
    }

    public function create(): Response
    {
        $this->authorize('jobs.create');
        $tenant = $this->tenant();
        $job = [
            'id' => null, 'type' => $this->req->query('type') === 'heartbeat' ? 'heartbeat' : 'http', 'auth_type' => 'none', 'auth_enc' => null,
            'grace_minutes' => 5, 'notify_after_failures' => 1, 'escalate_after_minutes' => '', 'escalate_channels' => '', 'sla_target' => '',
            'show_on_status' => 0, 'status_name' => '', 'ping_token' => null,
            'name' => '', 'group_name' => (string) $this->req->query('group', ''), 'description' => '', 'enabled' => 1, 'method' => 'GET', 'url' => 'https://',
            'body_type' => 'none', 'body' => '', 'cron_expr' => '*/5 * * * *', 'schedule_ui' => null,
            'timezone' => $tenant['timezone'], 'timeout_sec' => 30, 'max_retries' => 2, 'retry_strategy' => 'exponential',
            'retry_delay_sec' => 10, 'overlap_policy' => 'skip', 'success_codes' => '200-299', 'success_contains' => '',
            'slow_threshold_ms' => '', 'active_from' => '', 'active_until' => '', 'notify_emails' => '', 'notify_telegram_chat' => '', 'notify_phones' => '',
            'email_connector_id' => null, 'telegram_connector_id' => null, 'push_target' => 'all',
        ];
        $notify = ['failed' => ['push' => true, 'email' => true], 'recovered' => ['push' => true], 'missed' => ['email' => true]];
        return $this->form($job, [['name' => 'Accept', 'value' => 'application/json', 'secret' => false]], $notify);
    }

    public function edit(int $id): Response
    {
        $this->authorize('jobs.view');
        $job = $this->find($id);
        $notify = [];
        foreach ($this->db->all('SELECT event, channel FROM job_notifications WHERE job_id = ?', [$id]) as $n) {
            $notify[$n['event']][$n['channel']] = true;
        }
        $headers = array_map(fn ($h) => ['name' => $h['name'] ?? '', 'value' => !empty($h['secret']) ? '' : ($h['value'] ?? ''), 'secret' => !empty($h['secret'])], HttpExecutor::headers($job));
        $recent = $this->db->all('SELECT id, status, http_status, duration_ms, is_slow, created_at, trigger_type FROM job_runs WHERE job_id = ? ORDER BY id DESC LIMIT 10', [$id]);
        $targets = ['users' => [], 'devices' => []];
        foreach ($this->db->all('SELECT user_id, subscription_id FROM job_push_targets WHERE job_id = ?', [$id]) as $t) {
            if ($t['subscription_id']) {
                $targets['devices'][] = (int) $t['subscription_id'];
            } elseif ($t['user_id']) {
                $targets['users'][] = (int) $t['user_id'];
            }
        }
        return $this->form($job, $headers, $notify, $recent, $targets);
    }

    /** Existing group names of the tenant with job counts. */
    private function groups(): array
    {
        return $this->db->all(
            'SELECT group_name AS name, COUNT(*) AS n FROM jobs WHERE tenant_id = ? AND group_name IS NOT NULL GROUP BY group_name ORDER BY group_name',
            [$this->tenantId()],
        );
    }

    /** Members of the tenant with their push devices, for the recipient picker. */
    private function pushMembers(): array
    {
        $members = $this->db->all(
            "SELECT u.id, u.name, u.email FROM memberships m JOIN users u ON u.id = m.user_id
             WHERE m.tenant_id = ? AND u.status = 'active' ORDER BY u.name",
            [$this->tenantId()],
        );
        foreach ($members as &$m) {
            $m['devices'] = $this->db->all('SELECT id, label, user_agent, enabled FROM push_subscriptions WHERE user_id = ? ORDER BY id', [$m['id']]);
        }
        unset($m);
        return $members;
    }

    private function form(array $job, array $headers, array $notify, array $recent = [], array $targets = ['users' => [], 'devices' => []]): Response
    {
        $tid = $this->tenantId();
        $tenant = $this->tenant();
        return $this->view('jobs/form', [
            'groups' => $this->groups(),
            'pushMembers' => $this->pushMembers(),
            'pushTargets' => $targets,
            'title' => $job['id'] ? $job['name'] : 'Νέο job',
            'nav' => 'jobs',
            'job' => $job,
            'headers' => $headers,
            'notify' => $notify,
            'recent' => $recent,
            'emailConnectors' => Connectors::listFor($tid, 'email'),
            'telegramConnectors' => Connectors::listFor($tid, 'telegram'),
            'minInterval' => (int) $tenant['min_interval_minutes'],
            'canManage' => Auth::can($job['id'] ? 'jobs.edit' : 'jobs.create'),
            'canCreate' => Auth::can('jobs.create'),
            'canDelete' => Auth::can('jobs.delete'),
            'canExport' => Auth::can('jobs.export'),
            'canVersions' => Auth::can('jobs.versions'),
            'canRun' => Auth::can('jobs.run'),
            'variables' => array_merge(
                ['run.scheduled_at', 'run.previous_at', 'run.timestamp', 'run.id', 'job.name', 'job.id', 'tenant.slug', 'now'],
                array_map(fn ($v) => ($v['is_secret'] ? 'secrets.' : 'vars.') . $v['name'], \App\Services\Variables::all($tid)),
            ),
            'authCfg' => \App\Services\JobAuth::config($job),
        ]);
    }

    private function find(int $id): array
    {
        $job = $this->db->one('SELECT * FROM jobs WHERE id = ? AND tenant_id = ?', [$id, $this->tenantId()]);
        if (!$job) {
            throw HttpException::notFound('Το job δεν βρέθηκε.');
        }
        return $job;
    }

    /** @return array{0: array, 1: array, 2: string[]} [data, notify rules, errors] */
    public function collect(?array $existing): array
    {
        $tenant = $this->tenant();
        $r = $this->req;
        $errors = [];
        $name = mb_substr((string) $r->input('name', ''), 0, 150);
        if ($name === '') {
            $errors[] = 'Το όνομα είναι υποχρεωτικό.';
        }
        // Job type is chosen on creation and never changes afterwards.
        $type = $existing ? (string) $existing['type'] : ($r->input('type') === 'heartbeat' ? 'heartbeat' : 'http');
        $method = strtoupper((string) $r->input('method', 'GET'));
        if (!in_array($method, self::METHODS, true)) {
            $method = 'GET';
        }
        $url = (string) $r->input('url', '');
        if ($type === 'heartbeat') {
            $url = '';
        } elseif (!preg_match('#^https?://[^\s]+$#i', $url)) {
            $errors[] = 'Το URL πρέπει να ξεκινά με http:// ή https://.';
        } elseif (!str_contains((string) parse_url($url, PHP_URL_HOST), '{')) {
            try {
                SsrfGuard::check($url);
            } catch (\InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }
        $tz = (string) $r->input('timezone', $tenant['timezone']);
        if (!in_array($tz, CronHelper::timezones(), true)) {
            $errors[] = 'Άγνωστη ζώνη ώρας.';
            $tz = $tenant['timezone'];
        }
        $expr = CronHelper::normalize((string) $r->input('cron_expr', ''));
        if (!CronHelper::isValid($expr)) {
            $errors[] = 'Μη έγκυρη έκφραση cron.';
        } else {
            $gap = CronHelper::minGapMinutes($expr, $tz);
            if ($gap !== null && $gap < (int) $tenant['min_interval_minutes']) {
                $errors[] = 'Το πλάνο σου επιτρέπει εκτέλεση το πολύ κάθε ' . $tenant['min_interval_minutes'] . ' λεπτά.';
            }
        }
        $bodyType = (string) $r->input('body_type', 'none');
        if (!in_array($bodyType, ['none', 'json', 'form', 'text', 'xml'], true)) {
            $bodyType = 'none';
        }
        $body = $r->raw('body');
        if ($bodyType === 'json' && trim($body) !== '' && !str_contains($body, '{%')) {
            json_decode(TemplateRenderer::renderSafe($body, TemplateRenderer::sampleContext()));
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errors[] = 'Το body δεν είναι έγκυρο JSON: ' . json_last_error_msg();
            }
        }
        foreach ([$url, $body] as $src) {
            if (str_contains($src, '{{') || str_contains($src, '{%')) {
                if ($err = TemplateRenderer::validate($src)) {
                    $errors[] = 'Σφάλμα στις μεταβλητές: ' . $err;
                }
            }
        }
        // Headers (secret values are kept when left empty)
        $oldHeaders = $existing ? HttpExecutor::headers($existing) : [];
        $oldByName = [];
        foreach ($oldHeaders as $h) {
            $oldByName[strtolower((string) $h['name'])] = $h;
        }
        $headers = [];
        $hn = (array) ($_POST['h_name'] ?? []);
        $hv = (array) ($_POST['h_value'] ?? []);
        $hs = (array) ($_POST['h_secret'] ?? []);
        foreach ($hn as $i => $hName) {
            $hName = trim((string) $hName);
            if ($hName === '') {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9\-_]+$/', $hName)) {
                $errors[] = "Μη έγκυρο όνομα header: $hName";
                continue;
            }
            $secret = ($hs[$i] ?? '0') === '1';
            $value = str_replace(["\r", "\n"], '', (string) ($hv[$i] ?? ''));
            if ($secret && $value === '' && isset($oldByName[strtolower($hName)])) {
                $value = (string) $oldByName[strtolower($hName)]['value'];
            }
            $headers[] = ['name' => $hName, 'value' => $value, 'secret' => $secret];
        }
        $codes = preg_replace('/\s+/', '', (string) $r->input('success_codes', '200-299')) ?: '200-299';
        if (!preg_match('/^(\d{3}(-\d{3})?|\dxx)(,(\d{3}(-\d{3})?|\dxx))*$/i', $codes)) {
            $errors[] = 'Τα HTTP status επιτυχίας πρέπει να είναι π.χ. 200-299,304 ή 2xx.';
        }
        if (Auth::can('jobs.assertions')) {
            [$assertions, $assertErrors] = \App\Services\Assertions::parse($r->input('assert', []));
            array_push($errors, ...$assertErrors);
        } else {
            // No right to change them: keep what the job already has.
            $assertions = \App\Services\Assertions::decode($existing['assertions'] ?? null);
        }
        $date = function (string $key) use (&$errors): ?string {
            $v = (string) $this->req->input($key, '');
            if ($v === '') {
                return null;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                $errors[] = 'Μη έγκυρη ημερομηνία.';
                return null;
            }
            return $v;
        };
        $from = $date('active_from');
        $until = $date('active_until');
        if ($from && $until && $until < $from) {
            $errors[] = 'Η ημερομηνία λήξης είναι πριν από την ημερομηνία έναρξης.';
        }
        $emails = trim((string) $r->input('notify_emails', ''));
        foreach (array_filter(preg_split('/[,;\s]+/', $emails) ?: []) as $em) {
            if (!filter_var($em, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Μη έγκυρο email: $em";
            }
        }
        $connector = function (string $key, string $channel): ?int {
            $id = (int) $this->req->input($key, 0);
            return $id && Connectors::find($this->tenantId(), $id) ? $id : null;
        };
        $scheduleUi = (string) $r->input('schedule_ui', '');
        if ($scheduleUi !== '' && json_decode($scheduleUi) === null) {
            $scheduleUi = '';
        }
        $phones = [];
        foreach (preg_split('/[,;\n]+/', (string) $r->input('notify_phones', '')) ?: [] as $ph) {
            if (trim($ph) === '') {
                continue;
            }
            $n = \App\Services\Channels\RouteeSender::normalizePhone($ph);
            if ($n === null) {
                $errors[] = 'Μη έγκυρο κινητό: ' . trim($ph) . ' (π.χ. 69xxxxxxxx ή +3069xxxxxxxx)';
            } elseif (!in_array($n, $phones, true)) {
                $phones[] = $n;
            }
        }
        // Request authentication (secret fields left empty keep their stored value).
        $authType = (string) $r->input('auth_type', 'none');
        if (!isset(\App\Services\JobAuth::TYPES[$authType])) {
            $authType = 'none';
        }
        $authOld = $existing ? \App\Services\JobAuth::config($existing) : [];
        $authCfg = [];
        foreach (\App\Services\JobAuth::FIELDS[$authType] ?? [] as $key => [$label, $input, $secret]) {
            $val = trim((string) ($_POST['auth'][$key] ?? ''));
            if ($secret && $val === '' && isset($authOld[$key]) && ($existing['auth_type'] ?? '') === $authType) {
                $val = (string) $authOld[$key];
            }
            $authCfg[$key] = $val;
        }
        if ($authType === 'oauth2') {
            if (!preg_match('#^https://#i', $authCfg['token_url'] ?? '')) {
                $errors[] = 'OAuth2: το Token URL πρέπει να ξεκινά με https://';
            }
            if (($authCfg['client_id'] ?? '') === '' || ($authCfg['client_secret'] ?? '') === '') {
                $errors[] = 'OAuth2: συμπλήρωσε Client ID και Client secret.';
            }
        }
        $group = trim((string) preg_replace('/\s+/u', ' ', (string) $r->input('group_name', '')));
        $data = [
            'name' => $name,
            'group_name' => $group !== '' ? mb_substr($group, 0, 100) : null,
            'description' => mb_substr((string) $r->input('description', ''), 0, 2000) ?: null,
            'enabled' => $r->input('enabled') ? 1 : 0,
            'method' => $method,
            'url' => mb_substr($url, 0, 2048),
            'headers_enc' => $headers ? Crypto::encryptJson($headers) : null,
            'auth_type' => $authType,
            'auth_enc' => $authType !== 'none' ? Crypto::encryptJson($authCfg) : null,
            'body_type' => $bodyType,
            'body' => $bodyType === 'none' ? null : $body,
            'cron_expr' => $expr,
            'schedule_ui' => $scheduleUi !== '' ? mb_substr($scheduleUi, 0, 4000) : null,
            'timezone' => $tz,
            'timeout_sec' => max(1, min(300, (int) $r->input('timeout_sec', 30))),
            'max_retries' => max(0, min(10, (int) $r->input('max_retries', 0))),
            'retry_strategy' => $r->input('retry_strategy') === 'fixed' ? 'fixed' : 'exponential',
            'retry_delay_sec' => max(1, min(3600, (int) $r->input('retry_delay_sec', 10))),
            'overlap_policy' => in_array($r->input('overlap_policy'), ['skip', 'queue', 'allow'], true) ? $r->input('overlap_policy') : 'skip',
            'success_codes' => $codes,
            'success_contains' => mb_substr((string) $r->input('success_contains', ''), 0, 255) ?: null,
            'assertions' => $assertions ? json_encode($assertions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'slow_threshold_ms' => (int) $r->input('slow_threshold_ms', 0) > 0 ? (int) $r->input('slow_threshold_ms') : null,
            'active_from' => $from,
            'active_until' => $until,
            'notify_emails' => $emails !== '' ? mb_substr($emails, 0, 500) : null,
            'notify_telegram_chat' => mb_substr((string) $r->input('notify_telegram_chat', ''), 0, 100) ?: null,
            'notify_phones' => $phones ? mb_substr(implode(', ', $phones), 0, 500) : null,
            'email_connector_id' => $connector('email_connector_id', 'email'),
            'telegram_connector_id' => $connector('telegram_connector_id', 'telegram'),
            'type' => $type,
            'grace_minutes' => max(1, min(1440, (int) $r->input('grace_minutes', 5))),
            'push_target' => $r->input('push_target') === 'selected' ? 'selected' : 'all',
            'notify_after_failures' => max(1, min(50, (int) $r->input('notify_after_failures', 1))),
            'escalate_after_minutes' => (int) $r->input('escalate_after_minutes', 0) > 0 ? min(10080, (int) $r->input('escalate_after_minutes')) : null,
            'escalate_channels' => implode(',', array_intersect(array_keys(Notifier::CHANNELS), (array) ($_POST['escalate_channels'] ?? []))) ?: null,
            'sla_target' => is_numeric($r->input('sla_target')) && (float) $r->input('sla_target') > 0 ? min(100, max(1, round((float) $r->input('sla_target'), 2))) : null,
            'show_on_status' => $r->input('show_on_status') ? 1 : 0,
            'status_name' => mb_substr((string) $r->input('status_name', ''), 0, 100) ?: null,
        ];
        // Push recipients: only members of this tenant and their own devices are accepted.
        $memberIds = array_map('intval', $this->db->column('SELECT user_id FROM memberships WHERE tenant_id = ?', [$tenant['id']]));
        $targets = ['users' => [], 'devices' => []];
        foreach ((array) ($_POST['push_users'] ?? []) as $uid) {
            if (in_array((int) $uid, $memberIds, true)) {
                $targets['users'][] = (int) $uid;
            }
        }
        $devIds = array_map('intval', (array) ($_POST['push_devices'] ?? []));
        if ($devIds && $memberIds) {
            $in = implode(',', array_fill(0, count($devIds), '?'));
            $inM = implode(',', array_fill(0, count($memberIds), '?'));
            foreach ($this->db->all("SELECT id, user_id FROM push_subscriptions WHERE id IN ($in) AND user_id IN ($inM)", array_merge($devIds, $memberIds)) as $d) {
                if (!in_array((int) $d['user_id'], $targets['users'], true)) {
                    $targets['devices'][] = (int) $d['id'];
                }
            }
        }
        if ($data['push_target'] === 'selected' && !$targets['users'] && !$targets['devices']) {
            $hasPushRule = false;
            foreach ((array) ($_POST['notify'] ?? []) as $chs) {
                $hasPushRule = $hasPushRule || !empty($chs['push']);
            }
            if ($hasPushRule) {
                $errors[] = 'Διάλεξε τουλάχιστον έναν χρήστη ή μία συσκευή για τα push, ή επίλεξε «Όλοι».';
            }
        }
        $rules = [];
        foreach ((array) ($_POST['notify'] ?? []) as $event => $channels) {
            if (!isset(Notifier::EVENTS[$event]) || !is_array($channels)) {
                continue;
            }
            foreach ($channels as $ch => $on) {
                if (isset(Notifier::CHANNELS[$ch]) && $on) {
                    $rules[] = [$event, $ch];
                }
            }
        }
        return [$data, $rules, $errors, $targets];
    }

    public function saveRules(int $jobId, array $rules, array $targets): void
    {
        $this->db->exec('DELETE FROM job_notifications WHERE job_id = ?', [$jobId]);
        foreach ($rules as [$event, $ch]) {
            $this->db->insert('job_notifications', ['job_id' => $jobId, 'event' => $event, 'channel' => $ch]);
        }
        $this->db->exec('DELETE FROM job_push_targets WHERE job_id = ?', [$jobId]);
        foreach ($targets['users'] as $uid) {
            $this->db->insert('job_push_targets', ['job_id' => $jobId, 'user_id' => $uid]);
        }
        foreach ($targets['devices'] as $sid) {
            $this->db->insert('job_push_targets', ['job_id' => $jobId, 'subscription_id' => $sid]);
        }
    }

    public function store(): Response
    {
        $this->authorize('jobs.create');
        $tenant = $this->tenant();
        [$data, $rules, $errors, $targets] = $this->collect(null);
        $count = (int) $this->db->value('SELECT COUNT(*) FROM jobs WHERE tenant_id = ?', [$tenant['id']]);
        if ($count >= (int) $tenant['max_jobs']) {
            $errors[] = 'Έφτασες το όριο των ' . $tenant['max_jobs'] . ' jobs του πλάνου σου.';
        }
        if ($errors) {
            return $this->invalid($errors);
        }
        $id = $this->db->transaction(function () use ($data, $rules, $targets, $tenant): int {
            $id = $this->db->insert('jobs', $data + ['tenant_id' => $tenant['id'], 'created_by' => Auth::id(), 'ping_token' => bin2hex(random_bytes(16))]);
            $this->saveRules($id, $rules, $targets);
            return $id;
        });
        Scheduler::refreshNextRun($id);
        \App\Services\JobVersions::snapshot($id, 'Δημιουργία');
        Audit::log('job.created', 'job', $id, $data['name'], null, $this->auditable($data));
        $this->flash('success', 'Το job δημιουργήθηκε.');
        return $this->redirect("jobs/$id/edit");
    }

    public function update(int $id): Response
    {
        $this->authorize('jobs.edit');
        $job = $this->find($id);
        [$data, $rules, $errors, $targets] = $this->collect($job);
        if ($errors) {
            return $this->invalid($errors);
        }
        // Keep the state before the change (no-op when it is already the latest version).
        \App\Services\JobVersions::snapshot($id, 'Κατάσταση πριν την αλλαγή');
        $this->db->transaction(function () use ($id, $data, $rules, $targets): void {
            $this->db->update('jobs', $data, 'id = :id', ['id' => $id]);
            $this->saveRules($id, $rules, $targets);
        });
        Scheduler::refreshNextRun($id);
        \App\Services\JobVersions::snapshot($id, mb_substr(trim((string) $this->req->input('version_comment', '')), 0, 255) ?: null);
        Audit::log('job.updated', 'job', $id, $data['name'], $this->auditable($job), $this->auditable($data));
        $this->flash('success', 'Οι αλλαγές αποθηκεύτηκαν.');
        return $this->redirect("jobs/$id/edit");
    }

    public function auditable(array $job): array
    {
        $out = array_intersect_key($job, array_flip(['name', 'group_name', 'notify_phones', 'enabled', 'method', 'url', 'body_type', 'cron_expr', 'timezone', 'timeout_sec', 'max_retries', 'overlap_policy', 'success_codes', 'active_from', 'active_until', 'notify_emails']));
        $out['headers'] = array_map(fn ($h) => $h['name'] . ': ' . (!empty($h['secret']) ? '••••' : $h['value']), HttpExecutor::headers($job));
        $out['auth_type'] = $job['auth_type'] ?? 'none';
        return $out;
    }

    private function invalid(array $errors): Response
    {
        if ($this->req->wantsJson()) {
            return $this->json(['errors' => $errors], 422);
        }
        $this->keepOld();
        foreach ($errors as $e) {
            $this->flash('error', $e);
        }
        return $this->back('jobs');
    }

    public function delete(int $id): Response
    {
        $this->authorize('jobs.delete');
        $job = $this->find($id);
        $this->db->exec('DELETE FROM jobs WHERE id = ?', [$id]);
        Audit::log('job.deleted', 'job', $id, $job['name'], $this->auditable($job), null);
        $this->flash('success', 'Το job διαγράφηκε.');
        return $this->redirect('jobs');
    }

    public function toggle(int $id): Response
    {
        $this->authorize('jobs.toggle');
        $job = $this->find($id);
        $enabled = $job['enabled'] ? 0 : 1;
        $this->db->update('jobs', ['enabled' => $enabled], 'id = :id', ['id' => $id]);
        Scheduler::refreshNextRun($id);
        Audit::log($enabled ? 'job.enabled' : 'job.disabled', 'job', $id, $job['name']);
        if ($this->req->wantsJson()) {
            return $this->json(['enabled' => (bool) $enabled]);
        }
        $this->flash('success', $enabled ? 'Το job ενεργοποιήθηκε.' : 'Το job τέθηκε σε παύση.');
        return $this->back('jobs');
    }

    public function duplicate(int $id): Response
    {
        $this->authorize('jobs.create');
        $job = $this->find($id);
        $copy = $job;
        unset($copy['id'], $copy['created_at'], $copy['updated_at']);
        $copy['name'] = mb_substr($job['name'] . ' (αντίγραφο)', 0, 150);
        $copy['enabled'] = 0;
        $copy['next_run_at'] = $copy['last_run_at'] = $copy['last_status'] = $copy['last_duration_ms'] = null;
        $copy['last_ping_at'] = $copy['failing_since'] = $copy['escalated_at'] = null;
        $copy['ping_token'] = bin2hex(random_bytes(16)); // a copy never shares the ping URL
        $copy['created_by'] = Auth::id();
        $newId = $this->db->insert('jobs', $copy);
        $this->db->exec('INSERT INTO job_notifications (job_id, event, channel, template_id) SELECT ?, event, channel, template_id FROM job_notifications WHERE job_id = ?', [$newId, $id]);
        $this->db->exec('INSERT INTO job_push_targets (job_id, user_id, subscription_id) SELECT ?, user_id, subscription_id FROM job_push_targets WHERE job_id = ?', [$newId, $id]);
        \App\Services\JobVersions::snapshot($newId, 'Αντίγραφο του #' . $id);
        Audit::log('job.created', 'job', $newId, $copy['name'] . ' (αντιγραφή από #' . $id . ')');
        $this->flash('success', 'Δημιουργήθηκε αντίγραφο σε παύση.');
        return $this->redirect("jobs/$newId/edit");
    }

    public function runNow(int $id): Response
    {
        $this->authorize('jobs.run');
        $job = $this->find($id);
        if ($job['type'] === 'heartbeat') {
            $this->flash('error', 'Τα heartbeat jobs δεν εκτελούνται από εδώ· περιμένουν ping από το δικό σου script.');
            return $this->back('jobs');
        }
        $scheduler = new Scheduler();
        $runId = $scheduler->enqueue($job, gmdate('Y-m-d H:i:s'), 'manual', Auth::id());
        if (!$runId) {
            $this->flash('error', 'Υπάρχει ήδη χειροκίνητη εκτέλεση αυτή τη στιγμή.');
            return $this->back('jobs');
        }
        Audit::log('job.run_now', 'job', $id, $job['name']);
        ignore_user_abort(true);
        set_time_limit((int) $job['timeout_sec'] + 30);
        Session::release(); // the call may take long: do not block the user's other requests
        $overlapBusy = $job['overlap_policy'] !== 'allow' && $this->db->value("SELECT 1 FROM job_runs WHERE job_id = ? AND status = 'running' AND id <> ?", [$id, $runId]);
        if (!$overlapBusy && $scheduler->claim($runId)) {
            $scheduler->executeClaimed([$runId]);
        }
        $run = $this->db->one('SELECT status, http_status, duration_ms, error FROM job_runs WHERE id = ?', [$runId]);
        $label = RUN_STATUS[$run['status'] ?? 'queued'][0] ?? '';
        $failed = in_array($run['status'], ['failed', 'retrying'], true);
        if ($this->req->input('from') === 'list') {
            $details = $run['http_status'] ? ' · HTTP ' . $run['http_status'] . ' · ' . fmt_ms($run['duration_ms'] !== null ? (int) $run['duration_ms'] : null) : '';
            $msg = '«' . $job['name'] . '»: ' . $label . $details . ($failed && $run['error'] ? ' — ' . $run['error'] : '') . ' (εκτέλεση #' . $runId . ')';
            $this->flash($failed ? 'error' : 'success', $msg);
            return $this->redirect('jobs' . (($q = parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_QUERY)) ? '?' . $q : ''));
        }
        $this->flash($failed ? 'error' : 'success', 'Η εκτέλεση ολοκληρώθηκε: ' . $label . '.');
        return $this->redirect("runs/$runId");
    }

    /** Executes the request described by the (unsaved) form and returns the result. */
    public function test(): Response
    {
        $this->authorize('jobs.run');
        $tenant = $this->tenant();
        $existingId = (int) $this->req->input('job_id', 0);
        $existing = $existingId ? $this->db->one('SELECT * FROM jobs WHERE id = ? AND tenant_id = ?', [$existingId, $tenant['id']]) : null;
        [$data, , $errors] = $this->collect($existing);
        $errors = array_values(array_filter($errors, fn ($e) => !str_contains($e, 'cron') && !str_contains($e, 'όνομα') && !str_contains($e, 'πλάνο')));
        if ($errors) {
            return $this->json(['ok' => false, 'error' => implode(' ', $errors)], 422);
        }
        $job = $data + ['id' => $existingId, 'tenant_id' => $tenant['id']];
        set_time_limit((int) $job['timeout_sec'] + 15);
        Session::release();
        $ctx = TemplateRenderer::requestContext($job, $tenant, null, $existing['last_run_at'] ?? null);
        try {
            $req = HttpExecutor::buildRequest($job, $ctx);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        $res = HttpExecutor::execute($req);
        [$ok, $reason, $checks] = HttpExecutor::evaluateFull($job, $res);
        $pretty = $res['body'];
        $decoded = json_decode($res['body'], true);
        if ($decoded !== null && is_array($decoded)) {
            $pretty = (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $this->json([
            'ok' => $ok,
            'reason' => $reason,
            'status' => $res['http_status'],
            'duration' => fmt_ms($res['duration_ms']),
            'size' => fmt_bytes($res['size']),
            'request' => ['method' => $req['method'], 'url' => $req['url'], 'body' => $req['body']],
            'headers' => $res['headers'],
            'body' => mb_substr($pretty, 0, 20000),
            'checks' => $checks,
        ]);
    }

    public function cronPreview(): Response
    {
        $tenant = $this->tenant();
        $tz = (string) $this->req->query('tz', $tenant['timezone']);
        $count = max(1, min(20, (int) $this->req->query('count', 5)));
        return $this->json(CronHelper::preview(
            (string) $this->req->query('expr', ''),
            in_array($tz, CronHelper::timezones(), true) ? $tz : $tenant['timezone'],
            $count,
            (string) $this->req->query('from', '') ?: null,
            (string) $this->req->query('until', '') ?: null,
            (int) $tenant['min_interval_minutes'],
        ));
    }
}
