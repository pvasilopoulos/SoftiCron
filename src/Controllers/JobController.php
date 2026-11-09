<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Crypto;
use App\Core\HttpException;
use App\Core\Response;
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
            $where .= ' AND (j.name LIKE :q OR j.url LIKE :q2)';
            $params['q'] = '%' . $q . '%';
            $params['q2'] = '%' . $q . '%';
        }
        $where .= match ($filter) {
            'active' => ' AND j.enabled = 1',
            'issue' => " AND j.enabled = 1 AND j.last_status = 'failed'",
            'paused' => ' AND j.enabled = 0',
            default => '',
        };
        $page = $this->paginate($where, $params, 'j.*', 'j.name ASC', 30);
        $counts = $this->db->one(
            "SELECT COUNT(*) AS all_count, SUM(enabled = 1) AS active, SUM(enabled = 1 AND last_status = 'failed') AS issue, SUM(enabled = 0) AS paused FROM jobs WHERE tenant_id = ?",
            [$tid],
        );
        return $this->view('jobs/index', [
            'title' => 'Jobs', 'nav' => 'jobs', 'page' => $page, 'filter' => $filter, 'q' => $q, 'counts' => $counts,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('jobs.manage');
        $tenant = $this->tenant();
        $job = [
            'id' => null, 'name' => '', 'description' => '', 'enabled' => 1, 'method' => 'GET', 'url' => 'https://',
            'body_type' => 'none', 'body' => '', 'cron_expr' => '*/5 * * * *', 'schedule_ui' => null,
            'timezone' => $tenant['timezone'], 'timeout_sec' => 30, 'max_retries' => 2, 'retry_strategy' => 'exponential',
            'retry_delay_sec' => 10, 'overlap_policy' => 'skip', 'success_codes' => '200-299', 'success_contains' => '',
            'slow_threshold_ms' => '', 'active_from' => '', 'active_until' => '', 'notify_emails' => '', 'notify_telegram_chat' => '',
            'email_connector_id' => null, 'telegram_connector_id' => null,
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
        return $this->form($job, $headers, $notify, $recent);
    }

    private function form(array $job, array $headers, array $notify, array $recent = []): Response
    {
        $tid = $this->tenantId();
        $tenant = $this->tenant();
        return $this->view('jobs/form', [
            'title' => $job['id'] ? $job['name'] : 'Νέο job',
            'nav' => 'jobs',
            'job' => $job,
            'headers' => $headers,
            'notify' => $notify,
            'recent' => $recent,
            'emailConnectors' => Connectors::listFor($tid, 'email'),
            'telegramConnectors' => Connectors::listFor($tid, 'telegram'),
            'minInterval' => (int) $tenant['min_interval_minutes'],
            'canManage' => Auth::can('jobs.manage'),
            'canRun' => Auth::can('jobs.run'),
            'variables' => ['run.scheduled_at', 'run.previous_at', 'run.timestamp', 'run.id', 'job.name', 'job.id', 'tenant.slug', 'now'],
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
    private function collect(?array $existing): array
    {
        $tenant = $this->tenant();
        $r = $this->req;
        $errors = [];
        $name = mb_substr((string) $r->input('name', ''), 0, 150);
        if ($name === '') {
            $errors[] = 'Το όνομα είναι υποχρεωτικό.';
        }
        $method = strtoupper((string) $r->input('method', 'GET'));
        if (!in_array($method, self::METHODS, true)) {
            $method = 'GET';
        }
        $url = (string) $r->input('url', '');
        if (!preg_match('#^https?://[^\s]+$#i', $url)) {
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
        $data = [
            'name' => $name,
            'description' => mb_substr((string) $r->input('description', ''), 0, 2000) ?: null,
            'enabled' => $r->input('enabled') ? 1 : 0,
            'method' => $method,
            'url' => mb_substr($url, 0, 2048),
            'headers_enc' => $headers ? Crypto::encryptJson($headers) : null,
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
            'slow_threshold_ms' => (int) $r->input('slow_threshold_ms', 0) > 0 ? (int) $r->input('slow_threshold_ms') : null,
            'active_from' => $from,
            'active_until' => $until,
            'notify_emails' => $emails !== '' ? mb_substr($emails, 0, 500) : null,
            'notify_telegram_chat' => mb_substr((string) $r->input('notify_telegram_chat', ''), 0, 100) ?: null,
            'email_connector_id' => $connector('email_connector_id', 'email'),
            'telegram_connector_id' => $connector('telegram_connector_id', 'telegram'),
        ];
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
        return [$data, $rules, $errors];
    }

    private function saveRules(int $jobId, array $rules): void
    {
        $this->db->exec('DELETE FROM job_notifications WHERE job_id = ?', [$jobId]);
        foreach ($rules as [$event, $ch]) {
            $this->db->insert('job_notifications', ['job_id' => $jobId, 'event' => $event, 'channel' => $ch]);
        }
    }

    public function store(): Response
    {
        $this->authorize('jobs.manage');
        $tenant = $this->tenant();
        [$data, $rules, $errors] = $this->collect(null);
        $count = (int) $this->db->value('SELECT COUNT(*) FROM jobs WHERE tenant_id = ?', [$tenant['id']]);
        if ($count >= (int) $tenant['max_jobs']) {
            $errors[] = 'Έφτασες το όριο των ' . $tenant['max_jobs'] . ' jobs του πλάνου σου.';
        }
        if ($errors) {
            return $this->invalid($errors);
        }
        $id = $this->db->transaction(function () use ($data, $rules, $tenant): int {
            $id = $this->db->insert('jobs', $data + ['tenant_id' => $tenant['id'], 'created_by' => Auth::id()]);
            $this->saveRules($id, $rules);
            return $id;
        });
        Scheduler::refreshNextRun($id);
        Audit::log('job.created', 'job', $id, $data['name'], null, $this->auditable($data));
        $this->flash('success', 'Το job δημιουργήθηκε.');
        return $this->redirect("jobs/$id/edit");
    }

    public function update(int $id): Response
    {
        $this->authorize('jobs.manage');
        $job = $this->find($id);
        [$data, $rules, $errors] = $this->collect($job);
        if ($errors) {
            return $this->invalid($errors);
        }
        $this->db->transaction(function () use ($id, $data, $rules): void {
            $this->db->update('jobs', $data, 'id = :id', ['id' => $id]);
            $this->saveRules($id, $rules);
        });
        Scheduler::refreshNextRun($id);
        Audit::log('job.updated', 'job', $id, $data['name'], $this->auditable($job), $this->auditable($data));
        $this->flash('success', 'Οι αλλαγές αποθηκεύτηκαν.');
        return $this->redirect("jobs/$id/edit");
    }

    private function auditable(array $job): array
    {
        $out = array_intersect_key($job, array_flip(['name', 'enabled', 'method', 'url', 'body_type', 'cron_expr', 'timezone', 'timeout_sec', 'max_retries', 'overlap_policy', 'success_codes', 'active_from', 'active_until', 'notify_emails']));
        $out['headers'] = array_map(fn ($h) => $h['name'] . ': ' . (!empty($h['secret']) ? '••••' : $h['value']), HttpExecutor::headers($job));
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
        $this->authorize('jobs.manage');
        $job = $this->find($id);
        $this->db->exec('DELETE FROM jobs WHERE id = ?', [$id]);
        Audit::log('job.deleted', 'job', $id, $job['name'], $this->auditable($job), null);
        $this->flash('success', 'Το job διαγράφηκε.');
        return $this->redirect('jobs');
    }

    public function toggle(int $id): Response
    {
        $this->authorize('jobs.manage');
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
        $this->authorize('jobs.manage');
        $job = $this->find($id);
        $copy = $job;
        unset($copy['id'], $copy['created_at'], $copy['updated_at']);
        $copy['name'] = mb_substr($job['name'] . ' (αντίγραφο)', 0, 150);
        $copy['enabled'] = 0;
        $copy['next_run_at'] = $copy['last_run_at'] = $copy['last_status'] = $copy['last_duration_ms'] = null;
        $copy['created_by'] = Auth::id();
        $newId = $this->db->insert('jobs', $copy);
        $this->db->exec('INSERT INTO job_notifications (job_id, event, channel, template_id) SELECT ?, event, channel, template_id FROM job_notifications WHERE job_id = ?', [$newId, $id]);
        Audit::log('job.created', 'job', $newId, $copy['name'] . ' (αντιγραφή από #' . $id . ')');
        $this->flash('success', 'Δημιουργήθηκε αντίγραφο σε παύση.');
        return $this->redirect("jobs/$newId/edit");
    }

    public function runNow(int $id): Response
    {
        $this->authorize('jobs.run');
        $job = $this->find($id);
        $scheduler = new Scheduler();
        $runId = $scheduler->enqueue($job, gmdate('Y-m-d H:i:s'), 'manual', Auth::id());
        if (!$runId) {
            $this->flash('error', 'Υπάρχει ήδη χειροκίνητη εκτέλεση αυτή τη στιγμή.');
            return $this->back('jobs');
        }
        Audit::log('job.run_now', 'job', $id, $job['name']);
        ignore_user_abort(true);
        set_time_limit((int) $job['timeout_sec'] + 30);
        $overlapBusy = $job['overlap_policy'] !== 'allow' && $this->db->value("SELECT 1 FROM job_runs WHERE job_id = ? AND status = 'running' AND id <> ?", [$id, $runId]);
        if (!$overlapBusy && $scheduler->claim($runId)) {
            $scheduler->executeClaimed([$runId]);
        }
        $run = $this->db->one('SELECT status FROM job_runs WHERE id = ?', [$runId]);
        $label = RUN_STATUS[$run['status'] ?? 'queued'][0] ?? '';
        $this->flash($run['status'] === 'failed' ? 'error' : 'success', 'Η εκτέλεση ολοκληρώθηκε: ' . $label . '.');
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
        $ctx = TemplateRenderer::requestContext($job, $tenant, null, $existing['last_run_at'] ?? null);
        $req = HttpExecutor::buildRequest($job, $ctx);
        $res = HttpExecutor::execute($req);
        [$ok, $reason] = HttpExecutor::evaluate($job, $res);
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
