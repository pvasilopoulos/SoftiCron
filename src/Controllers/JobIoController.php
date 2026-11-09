<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\Audit;
use App\Services\JobIO;
use App\Services\JobVersions;
use App\Services\Scheduler;

/** JSON import/export of jobs and version history. */
final class JobIoController extends Controller
{
    private const MAX_IMPORT = 500;

    private function find(int $id): array
    {
        $job = $this->db->one('SELECT * FROM jobs WHERE id = ? AND tenant_id = ?', [$id, $this->tenantId()]);
        if (!$job) {
            throw HttpException::notFound('Το job δεν βρέθηκε.');
        }
        return $job;
    }

    // ---- Export -----------------------------------------------------------------

    public function export(): Response
    {
        $this->authorize('jobs.export');
        $tenant = $this->tenant();
        $sql = 'SELECT * FROM jobs WHERE tenant_id = ?';
        $p = [$tenant['id']];
        $ids = array_filter(array_map('intval', explode(',', (string) $this->req->query('ids', ''))));
        if ($ids) {
            $sql .= ' AND id IN (' . implode(',', $ids) . ')';
        }
        $group = (string) $this->req->query('group', '');
        if ($group === '__none') {
            $sql .= ' AND group_name IS NULL';
        } elseif ($group !== '') {
            $sql .= ' AND group_name = ?';
            $p[] = $group;
        }
        $jobs = $this->db->all($sql . ' ORDER BY group_name IS NULL, group_name, name', $p);
        if (!$jobs) {
            $this->flash('error', 'Δεν βρέθηκαν jobs για εξαγωγή.');
            return $this->back('jobs');
        }
        $doc = [
            'format' => JobIO::FORMAT,
            'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'source' => ['app' => \App\Core\App::name(), 'tenant' => $tenant['name']],
            'note' => 'Τα secrets (secret headers, κωδικοί auth) δεν περιλαμβάνονται. Χρησιμοποίησε {{ secrets.ΟΝΟΜΑ }} από τις Μεταβλητές.',
            'jobs' => array_map(fn ($j) => JobIO::toArray($j, false), $jobs),
        ];
        Audit::log('job.exported', 'job', count($jobs) === 1 ? (int) $jobs[0]['id'] : null, count($jobs) . ' jobs');
        $name = count($jobs) === 1 ? preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $jobs[0]['name']) : 'jobs';
        $name = trim((string) $name, '-') ?: 'job';
        return new Response(
            (string) json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            200,
            [
                'Content-Type' => 'application/json; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="softicron-' . $name . '-' . date('Ymd-His') . '.json"',
            ],
        );
    }

    // ---- Import -----------------------------------------------------------------

    public function importForm(): Response
    {
        $this->authorize('jobs.import');
        return $this->view('jobs/import', ['title' => 'Εισαγωγή jobs', 'nav' => 'jobs', 'result' => null]);
    }

    public function import(): Response
    {
        $this->authorize('jobs.import');
        $tenant = $this->tenant();
        $raw = trim((string) $this->req->raw('json'));
        if ($file = $this->req->file('file')) {
            if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024) {
                $this->flash('error', 'Το αρχείο πρέπει να είναι JSON έως 5 MB.');
                return $this->redirect('jobs/import');
            }
            $raw = (string) file_get_contents($file['tmp_name']);
        }
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        $doc = json_decode($raw, true);
        if (!is_array($doc)) {
            $this->flash('error', 'Μη έγκυρο JSON: ' . json_last_error_msg());
            return $this->redirect('jobs/import');
        }
        // Accept a full export, a bare array of jobs, or a single job object.
        $items = isset($doc['jobs']) && is_array($doc['jobs']) ? $doc['jobs'] : (array_is_list($doc) ? $doc : [$doc]);
        $items = array_values(array_filter($items, 'is_array'));
        if (!$items) {
            $this->flash('error', 'Το αρχείο δεν περιέχει jobs.');
            return $this->redirect('jobs/import');
        }
        if (count($items) > self::MAX_IMPORT) {
            $this->flash('error', 'Έως ' . self::MAX_IMPORT . ' jobs ανά εισαγωγή.');
            return $this->redirect('jobs/import');
        }
        $mode = in_array($this->req->input('on_conflict'), ['skip', 'copy', 'update'], true) ? $this->req->input('on_conflict') : 'skip';
        if ($mode === 'update' && !Auth::can('jobs.edit')) {
            throw HttpException::forbidden('Η ενημέρωση υπαρχόντων jobs απαιτεί το δικαίωμα «Επεξεργασία jobs».');
        }
        $paused = (bool) $this->req->input('paused');
        $dry = (bool) $this->req->input('dry_run');
        $groupOverride = trim((string) $this->req->input('group', ''));
        $count = (int) $this->db->value('SELECT COUNT(*) FROM jobs WHERE tenant_id = ?', [$tenant['id']]);
        $result = [];
        $postBackup = $_POST;
        foreach ($items as $i => $item) {
            $name = mb_substr(trim((string) ($item['name'] ?? '')), 0, 150);
            $label = $name !== '' ? $name : 'Job #' . ($i + 1);
            if ($groupOverride !== '') {
                $item['group'] = $groupOverride;
            }
            if ($paused) {
                $item['enabled'] = false;
            }
            $existing = $name !== '' ? $this->db->one('SELECT * FROM jobs WHERE tenant_id = ? AND name = ? ORDER BY id LIMIT 1', [$tenant['id'], $name]) : null;
            if ($existing && $mode === 'skip') {
                $result[] = [$label, 'skipped', 'Υπάρχει ήδη job με αυτό το όνομα.'];
                continue;
            }
            if ($existing && $mode === 'copy') {
                $item['name'] = $label = mb_substr($name . ' (εισαγωγή)', 0, 150);
                $existing = null;
            }
            if ($existing && ($item['type'] ?? $existing['type']) !== $existing['type']) {
                $result[] = [$label, 'error', 'Διαφορετικός τύπος job (http/heartbeat) από το υπάρχον.'];
                continue;
            }
            if (!$existing && $count >= (int) $tenant['max_jobs']) {
                $result[] = [$label, 'error', 'Έφτασες το όριο των ' . $tenant['max_jobs'] . ' jobs.'];
                continue;
            }
            $_POST = JobIO::toInput($item, $existing);
            $jc = new JobController($this->req);
            [$data, $rules, $errors, $targets] = $jc->collect($existing);
            if ($errors) {
                $result[] = [$label, 'error', implode(' ', $errors)];
                continue;
            }
            if ($dry) {
                $result[] = [$label, $existing ? 'updated' : 'created', 'Έλεγχος μόνο, δεν αποθηκεύτηκε.'];
                $count += $existing ? 0 : 1;
                continue;
            }
            if ($existing) {
                $id = (int) $existing['id'];
                JobVersions::snapshot($id, 'Πριν την εισαγωγή');
                $this->db->transaction(function () use ($id, $data, $rules, $targets, $jc): void {
                    $this->db->update('jobs', $data, 'id = :id', ['id' => $id]);
                    $jc->saveRules($id, $rules, $targets);
                });
                Audit::log('job.updated', 'job', $id, $data['name'] . ' (εισαγωγή)', $jc->auditable($existing), $jc->auditable($data));
            } else {
                $id = $this->db->transaction(function () use ($data, $rules, $targets, $tenant, $jc): int {
                    $id = $this->db->insert('jobs', $data + ['tenant_id' => $tenant['id'], 'created_by' => Auth::id(), 'ping_token' => bin2hex(random_bytes(16))]);
                    $jc->saveRules($id, $rules, $targets);
                    return $id;
                });
                $count++;
                Audit::log('job.created', 'job', $id, $data['name'] . ' (εισαγωγή)', null, $jc->auditable($data));
            }
            Scheduler::refreshNextRun($id);
            JobVersions::snapshot($id, 'Εισαγωγή από JSON');
            $result[] = [$label, $existing ? 'updated' : 'created', '', $id];
        }
        $_POST = $postBackup;
        $sum = array_count_values(array_column($result, 1));
        if (!$dry) {
            Audit::log('job.imported', 'job', null, sprintf('%d νέα, %d ενημερώσεις, %d παραλείφθηκαν, %d σφάλματα', $sum['created'] ?? 0, $sum['updated'] ?? 0, $sum['skipped'] ?? 0, $sum['error'] ?? 0));
        }
        return $this->view('jobs/import', ['title' => 'Εισαγωγή jobs', 'nav' => 'jobs', 'result' => $result, 'summary' => $sum, 'dry' => $dry]);
    }

    // ---- Versions ---------------------------------------------------------------

    public function versions(int $id): Response
    {
        $this->authorize('jobs.versions');
        $job = $this->find($id);
        $versions = JobVersions::all($id);
        if (!$versions) {
            // Jobs created before version history existed get their first snapshot now.
            JobVersions::snapshot($id, 'Αρχική κατάσταση');
            $versions = JobVersions::all($id);
        }
        $current = JobIO::toArray($job, false);
        $rows = [];
        foreach ($versions as $i => $v) {
            $data = JobVersions::decode($v)['portable'] ?? [];
            $older = isset($versions[$i + 1]) ? (JobVersions::decode($versions[$i + 1])['portable'] ?? []) : null;
            $rows[] = $v + [
                'changes' => $older !== null ? JobVersions::diff($older, $data) : null,
                'vs_current' => JobVersions::diff($data, $current),
            ];
        }
        return $this->view('jobs/versions', [
            'title' => 'Εκδόσεις · ' . $job['name'], 'nav' => 'jobs', 'job' => $job, 'rows' => $rows,
            'canRestore' => Auth::can('jobs.restore'),
        ]);
    }

    public function restore(int $id, int $vid): Response
    {
        $this->authorize('jobs.restore');
        $job = $this->find($id);
        $version = $this->db->one('SELECT * FROM job_versions WHERE id = ? AND job_id = ?', [$vid, $id]);
        if (!$version) {
            throw HttpException::notFound('Η έκδοση δεν βρέθηκε.');
        }
        JobVersions::snapshot($id, 'Πριν την επαναφορά');
        JobVersions::restore($job, $version);
        JobVersions::snapshot($id, 'Επαναφορά στην έκδοση ' . $version['version_no']);
        $after = $this->find($id);
        $jc = new JobController($this->req);
        Audit::log('job.restored', 'job', $id, $job['name'] . ' → έκδοση ' . $version['version_no'], $jc->auditable($job), $jc->auditable($after));
        $this->flash('success', 'Το job επανήλθε στην έκδοση ' . $version['version_no'] . '.');
        return $this->redirect("jobs/$id/versions");
    }
}
