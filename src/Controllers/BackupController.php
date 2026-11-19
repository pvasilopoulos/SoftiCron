<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Crypto;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\Session;
use App\Services\Audit;
use App\Services\Backup;
use App\Services\Settings;

/** Backup & restore page: encrypted full backups, detailed Excel report, automatic backups. */
final class BackupController extends Controller
{
    public const PERMS = ['backup.manage', 'backup.restore', 'jobs.export'];
    private const MAX_UPLOAD = 20 * 1024 * 1024;

    public function index(): Response
    {
        $this->authorizeAny(self::PERMS);
        return $this->render(null);
    }

    private function render(?array $restore): Response
    {
        $tid = $this->tenantId();
        $s = Settings::all($tid);
        $groups = $this->db->column('SELECT DISTINCT group_name FROM jobs WHERE tenant_id = ? AND group_name IS NOT NULL ORDER BY group_name', [$tid]);
        $counts = [
            'jobs' => (int) $this->db->value('SELECT COUNT(*) FROM jobs WHERE tenant_id = ?', [$tid]),
            'connectors' => (int) $this->db->value('SELECT COUNT(*) FROM connectors WHERE tenant_id = ?', [$tid]),
            'variables' => (int) $this->db->value('SELECT COUNT(*) FROM tenant_variables WHERE tenant_id = ?', [$tid]),
            'templates' => (int) $this->db->value('SELECT COUNT(*) FROM message_templates WHERE tenant_id = ?', [$tid]),
        ];
        return $this->view('backup/index', [
            'title' => 'Backup & επαναφορά', 'nav' => 'backup',
            'canManage' => Auth::can('backup.manage'), 'canRestore' => Auth::can('backup.restore'), 'canReport' => Auth::can('jobs.export'),
            'settings' => $s, 'hasPassword' => !empty($s['backup.password_enc']), 'files' => Auth::can('backup.manage') ? Backup::files($tid) : [],
            'groups' => $groups, 'counts' => $counts, 'restore' => $restore,
            'hasEmailConnector' => (bool) \App\Services\Connectors::default($tid, 'email'),
        ]);
    }

    /** Encrypted full backup, downloaded right away. */
    public function download(): Response
    {
        $this->authorize('backup.manage');
        $tenant = $this->tenant();
        $password = $this->req->raw('password');
        if ($password !== $this->req->raw('password_confirm')) {
            $this->flash('error', 'Οι δύο κωδικοί δεν ταιριάζουν.');
            return $this->redirect('backup');
        }
        try {
            $file = Backup::encrypt(Backup::payload($tenant), $password, $tenant);
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect('backup');
        }
        Audit::log('backup.downloaded', 'tenant', $tenant['id'], 'Λήψη πλήρους backup');
        return $this->file(Backup::filename($tenant), $file, 'application/json');
    }

    /** Detailed, human-readable Excel report of the jobs (no secrets). */
    public function report(): Response
    {
        $this->authorize('jobs.export');
        $tenant = $this->tenant();
        [$name, $data, $mime] = Backup::report($tenant, (string) $this->req->query('group', ''));
        Audit::log('job.exported', 'job', null, 'Αναλυτική αναφορά Excel');
        return $this->file($name, $data, $mime);
    }

    public function saveSettings(): Response
    {
        $this->authorize('backup.manage');
        $tid = $this->tenantId();
        $auto = (string) $this->req->input('auto', 'off');
        $auto = isset(Backup::SCHEDULES[$auto]) ? $auto : 'off';
        $emails = [];
        foreach (preg_split('/[,;\s]+/', (string) $this->req->input('emails', '')) ?: [] as $e) {
            if ($e === '') {
                continue;
            }
            if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $this->flash('error', 'Μη έγκυρο email: ' . $e);
                return $this->redirect('backup');
            }
            $emails[] = $e;
        }
        $password = $this->req->raw('password');
        if ($password !== '') {
            if (mb_strlen($password) < Backup::MIN_PASSWORD) {
                $this->flash('error', 'Ο κωδικός πρέπει να έχει τουλάχιστον ' . Backup::MIN_PASSWORD . ' χαρακτήρες.');
                return $this->redirect('backup');
            }
            if ($password !== $this->req->raw('password_confirm')) {
                $this->flash('error', 'Οι δύο κωδικοί δεν ταιριάζουν.');
                return $this->redirect('backup');
            }
            Settings::set($tid, 'backup.password_enc', Crypto::encrypt($password));
        }
        if ($auto !== 'off' && !Settings::get($tid, 'backup.password_enc')) {
            $this->flash('error', 'Όρισε κωδικό για να ενεργοποιηθεί το αυτόματο backup.');
            return $this->redirect('backup');
        }
        Settings::set($tid, 'backup.auto', $auto);
        Settings::set($tid, 'backup.hour', (string) max(0, min(23, (int) $this->req->input('hour', 3))));
        Settings::set($tid, 'backup.weekday', (string) max(1, min(7, (int) $this->req->input('weekday', 1))));
        Settings::set($tid, 'backup.keep', (string) max(1, min(90, (int) $this->req->input('keep', 14))));
        Settings::set($tid, 'backup.emails', $emails ? implode(', ', $emails) : null);
        Settings::set($tid, 'backup.attach_report', $this->req->input('attach_report') ? '1' : '0');
        Audit::log('backup.settings', 'tenant', $tid, 'Αυτόματο backup: ' . Backup::SCHEDULES[$auto] . ($password !== '' ? ' · νέος κωδικός' : ''));
        $this->flash('success', 'Οι ρυθμίσεις του backup αποθηκεύτηκαν.');
        return $this->redirect('backup');
    }

    /** Creates a stored backup now (and emails it, when recipients are set). */
    public function runNow(): Response
    {
        $this->authorize('backup.manage');
        $tenant = $this->tenant();
        Session::release();
        try {
            $name = Backup::store($tenant);
            Audit::log('backup.created', 'tenant', $tenant['id'], 'Χειροκίνητο backup: ' . $name);
            $this->flash('success', 'Δημιουργήθηκε το backup ' . $name . (trim((string) Settings::get((int) $tenant['id'], 'backup.emails')) !== '' ? ' και στάλθηκε με email.' : '.'));
        } catch (\Throwable $e) {
            Settings::set((int) $tenant['id'], 'backup.last_error', mb_substr(date('d/m/Y H:i') . ' · ' . $e->getMessage(), 0, 500));
            $this->flash('error', $e->getMessage());
        }
        return $this->redirect('backup');
    }

    public function fileDownload(string $name): Response
    {
        $this->authorize('backup.manage');
        $path = Backup::path($this->tenantId(), $name) ?? throw HttpException::notFound('Το backup δεν βρέθηκε.');
        Audit::log('backup.downloaded', 'tenant', $this->tenantId(), 'Λήψη αποθηκευμένου backup ' . $name);
        return $this->file($name, (string) file_get_contents($path), 'application/json');
    }

    public function fileDelete(string $name): Response
    {
        $this->authorize('backup.manage');
        $path = Backup::path($this->tenantId(), $name) ?? throw HttpException::notFound('Το backup δεν βρέθηκε.');
        @unlink($path);
        Audit::log('backup.deleted', 'tenant', $this->tenantId(), 'Διαγραφή backup ' . $name);
        $this->flash('success', 'Το backup διαγράφηκε.');
        return $this->redirect('backup');
    }

    public function restore(): Response
    {
        $this->authorize('backup.restore');
        $tenant = $this->tenant();
        $tid = (int) $tenant['id'];
        $stored = (string) $this->req->input('stored', '');
        if ($stored !== '') {
            $this->authorize('backup.manage');
            $path = Backup::path($tid, $stored) ?? throw HttpException::notFound('Το backup δεν βρέθηκε.');
            $raw = (string) file_get_contents($path);
            $source = $stored;
        } else {
            $f = $this->req->file('file');
            if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > self::MAX_UPLOAD) {
                $this->flash('error', 'Διάλεξε αρχείο backup (έως 20 MB).');
                return $this->redirect('backup#restore');
            }
            $raw = (string) file_get_contents($f['tmp_name']);
            $source = (string) $f['name'];
        }
        $password = $this->req->raw('password');
        if ($password === '' && $stored !== '') {
            $password = (string) Backup::password($tid);
        }
        try {
            [$meta, $payload] = Backup::decrypt($raw, $password);
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect('backup#restore');
        }
        if (($meta['format'] ?? '') === Backup::PLATFORM_FORMAT) {
            $this->flash('error', 'Αυτό είναι backup όλης της πλατφόρμας: η επαναφορά του γίνεται από τον super admin, στο Backup πλατφόρμας.');
            return $this->redirect('backup#restore');
        }
        $sections = array_values(array_intersect(array_keys(Backup::SECTIONS), (array) ($_POST['sections'] ?? [])));
        if (!$sections) {
            $this->flash('error', 'Διάλεξε τι θα επαναφερθεί.');
            return $this->redirect('backup#restore');
        }
        $mode = $this->req->input('mode') === 'update' ? 'update' : 'skip';
        $paused = (bool) $this->req->input('paused');
        $dry = (bool) $this->req->input('dry_run');
        Session::release();
        @set_time_limit(300);
        $io = new JobIoController($this->req);
        $log = Backup::restore($tenant, $payload, $sections, $mode, $paused, $dry, fn (array $items, string $m, bool $p, bool $d, callable $after) => $io->importItems($tenant, $items, $m, $p, $d, '', 'επαναφορά', $after));
        $sum = array_count_values(array_column($log, 2));
        if (!$dry) {
            Audit::log('backup.restored', 'tenant', $tid, sprintf('Επαναφορά από %s: %d νέα, %d ενημερώσεις, %d παραλείφθηκαν, %d σφάλματα', $source, $sum['created'] ?? 0, $sum['updated'] ?? 0, $sum['skipped'] ?? 0, $sum['error'] ?? 0));
        }
        return $this->render([
            'log' => $log, 'sum' => $sum, 'dry' => $dry, 'source' => $source, 'mode' => $mode,
            'created_at' => $meta['created_at'] ?? null, 'tenant' => $meta['tenant'] ?? null, 'sections' => $sections,
        ]);
    }

    private function file(string $name, string $data, string $mime): Response
    {
        return new Response($data, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Content-Length' => (string) strlen($data),
            'Cache-Control' => 'no-store',
        ]);
    }
}
