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
use App\Services\Channels\EmailSender;
use App\Services\Settings;

/** Super admin: backups and Excel report of every tenant, automatic platform backups, restore. */
final class PlatformBackupController extends Controller
{
    private const MAX_UPLOAD = 100 * 1024 * 1024;

    public function index(): Response
    {
        $this->superAdmin();
        return $this->render(null);
    }

    private function render(?array $restore): Response
    {
        $tenants = $this->db->all(
            "SELECT t.id, t.name, t.slug, t.status,
                    (SELECT COUNT(*) FROM jobs j WHERE j.tenant_id = t.id) AS jobs,
                    (SELECT COUNT(*) FROM connectors c WHERE c.tenant_id = t.id) AS connectors
             FROM tenants t ORDER BY t.name",
        );
        return $this->view('admin/backup', [
            'title' => 'Backup πλατφόρμας', 'nav' => 'platform-backup',
            'settings' => Settings::all(0), 'hasPassword' => (bool) Settings::get(0, 'backup.password_enc'),
            'files' => Backup::files(0), 'tenants' => $tenants, 'restore' => $restore,
            'mailer' => EmailSender::platformConfigured(),
        ], Auth::tenant() ? 'layout' : 'auth_layout');
    }

    public function download(): Response
    {
        $this->superAdmin();
        $password = $this->req->raw('password');
        if ($password !== $this->req->raw('password_confirm')) {
            $this->flash('error', 'Οι δύο κωδικοί δεν ταιριάζουν.');
            return $this->redirect('admin/backup');
        }
        Session::release();
        @set_time_limit(300);
        $platform = Backup::platform();
        try {
            $file = Backup::encrypt(Backup::platformPayload(), $password, $platform);
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect('admin/backup');
        }
        Audit::log('backup.downloaded', 'platform', null, 'Λήψη backup όλων των οργανισμών', null, null, null);
        return $this->file(Backup::filename($platform), $file, 'application/json');
    }

    public function report(): Response
    {
        $this->superAdmin();
        Session::release();
        @set_time_limit(300);
        [$name, $data, $mime] = Backup::platformReport();
        Audit::log('job.exported', 'platform', null, 'Αναλυτική αναφορά Excel όλων των οργανισμών', null, null, null);
        return $this->file($name, $data, $mime);
    }

    public function saveSettings(): Response
    {
        $this->superAdmin();
        $auto = (string) $this->req->input('auto', 'off');
        $auto = isset(Backup::SCHEDULES[$auto]) ? $auto : 'off';
        $emails = [];
        foreach (preg_split('/[,;\s]+/', (string) $this->req->input('emails', '')) ?: [] as $e) {
            if ($e === '') {
                continue;
            }
            if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $this->flash('error', 'Μη έγκυρο email: ' . $e);
                return $this->redirect('admin/backup');
            }
            $emails[] = $e;
        }
        $password = $this->req->raw('password');
        if ($password !== '') {
            if (mb_strlen($password) < Backup::MIN_PASSWORD) {
                $this->flash('error', 'Ο κωδικός πρέπει να έχει τουλάχιστον ' . Backup::MIN_PASSWORD . ' χαρακτήρες.');
                return $this->redirect('admin/backup');
            }
            if ($password !== $this->req->raw('password_confirm')) {
                $this->flash('error', 'Οι δύο κωδικοί δεν ταιριάζουν.');
                return $this->redirect('admin/backup');
            }
            Settings::set(0, 'backup.password_enc', Crypto::encrypt($password));
        }
        if ($auto !== 'off' && !Settings::get(0, 'backup.password_enc')) {
            $this->flash('error', 'Όρισε κωδικό για να ενεργοποιηθεί το αυτόματο backup.');
            return $this->redirect('admin/backup');
        }
        Settings::set(0, 'backup.auto', $auto);
        Settings::set(0, 'backup.hour', (string) max(0, min(23, (int) $this->req->input('hour', 2))));
        Settings::set(0, 'backup.weekday', (string) max(1, min(7, (int) $this->req->input('weekday', 1))));
        Settings::set(0, 'backup.keep', (string) max(1, min(90, (int) $this->req->input('keep', 14))));
        Settings::set(0, 'backup.emails', $emails ? implode(', ', $emails) : null);
        Settings::set(0, 'backup.attach_report', $this->req->input('attach_report') ? '1' : '0');
        Audit::log('backup.settings', 'platform', null, 'Αυτόματο backup πλατφόρμας: ' . Backup::SCHEDULES[$auto] . ($password !== '' ? ' · νέος κωδικός' : ''), null, null, null);
        $this->flash('success', 'Οι ρυθμίσεις του backup πλατφόρμας αποθηκεύτηκαν.');
        return $this->redirect('admin/backup');
    }

    public function runNow(): Response
    {
        $this->superAdmin();
        Session::release();
        @set_time_limit(300);
        try {
            $name = Backup::store(Backup::platform());
            Audit::log('backup.created', 'platform', null, 'Χειροκίνητο backup πλατφόρμας: ' . $name, null, null, null);
            $this->flash('success', 'Δημιουργήθηκε το backup ' . $name . (trim((string) Settings::get(0, 'backup.emails')) !== '' ? ' και στάλθηκε με email.' : '.'));
        } catch (\Throwable $e) {
            Settings::set(0, 'backup.last_error', mb_substr(date('d/m/Y H:i') . ' · ' . $e->getMessage(), 0, 500));
            $this->flash('error', $e->getMessage());
        }
        return $this->redirect('admin/backup');
    }

    public function fileDownload(string $name): Response
    {
        $this->superAdmin();
        $path = Backup::path(0, $name) ?? throw HttpException::notFound('Το backup δεν βρέθηκε.');
        Audit::log('backup.downloaded', 'platform', null, 'Λήψη backup πλατφόρμας ' . $name, null, null, null);
        return $this->file($name, (string) file_get_contents($path), 'application/json');
    }

    public function fileDelete(string $name): Response
    {
        $this->superAdmin();
        $path = Backup::path(0, $name) ?? throw HttpException::notFound('Το backup δεν βρέθηκε.');
        @unlink($path);
        Audit::log('backup.deleted', 'platform', null, 'Διαγραφή backup πλατφόρμας ' . $name, null, null, null);
        $this->flash('success', 'Το backup διαγράφηκε.');
        return $this->redirect('admin/backup');
    }

    public function restore(): Response
    {
        $this->superAdmin();
        $stored = (string) $this->req->input('stored', '');
        if ($stored !== '') {
            $path = Backup::path(0, $stored) ?? throw HttpException::notFound('Το backup δεν βρέθηκε.');
            $raw = (string) file_get_contents($path);
            $source = $stored;
        } else {
            $f = $this->req->file('file');
            if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > self::MAX_UPLOAD) {
                $this->flash('error', 'Διάλεξε αρχείο backup πλατφόρμας (έως 100 MB).');
                return $this->redirect('admin/backup#restore');
            }
            $raw = (string) file_get_contents($f['tmp_name']);
            $source = (string) $f['name'];
        }
        $password = $this->req->raw('password');
        if ($password === '' && $stored !== '') {
            $password = (string) Backup::password(0);
        }
        Session::release();
        @set_time_limit(600);
        try {
            [$meta, $payload] = Backup::decrypt($raw, $password);
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect('admin/backup#restore');
        }
        if (($meta['format'] ?? '') !== Backup::PLATFORM_FORMAT) {
            $this->flash('error', 'Αυτό είναι backup ενός μόνο οργανισμού: κάνε επαναφορά από τη σελίδα Backup & επαναφορά μέσα στον οργανισμό.');
            return $this->redirect('admin/backup#restore');
        }
        $sections = array_values(array_intersect(array_keys(Backup::SECTIONS), (array) ($_POST['sections'] ?? [])));
        if (!$sections) {
            $this->flash('error', 'Διάλεξε τι θα επαναφερθεί.');
            return $this->redirect('admin/backup#restore');
        }
        $only = (string) $this->req->input('tenant', '');
        $mode = $this->req->input('mode') === 'update' ? 'update' : 'skip';
        $paused = (bool) $this->req->input('paused');
        $dry = (bool) $this->req->input('dry_run');
        $create = (bool) $this->req->input('create_missing');
        $io = new JobIoController($this->req);
        $log = Backup::restorePlatform($payload, $only, $create, $sections, $mode, $paused, $dry,
            fn (array $tenant, array $items, string $m, bool $p, bool $d, callable $after) => $io->importItems($tenant, $items, $m, $p, $d, '', 'επαναφορά', $after));
        $sum = array_count_values(array_column($log, 3));
        if (!$dry) {
            Audit::log('backup.restored', 'platform', null, sprintf('Επαναφορά πλατφόρμας από %s%s: %d νέα, %d ενημερώσεις, %d παραλείφθηκαν, %d σφάλματα', $source, $only !== '' ? ' (' . $only . ')' : '', $sum['created'] ?? 0, $sum['updated'] ?? 0, $sum['skipped'] ?? 0, $sum['error'] ?? 0), null, null, null);
        }
        return $this->render([
            'log' => $log, 'sum' => $sum, 'dry' => $dry, 'source' => $source, 'mode' => $mode,
            'created_at' => $meta['created_at'] ?? null, 'contents' => $meta['contents'] ?? [],
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
