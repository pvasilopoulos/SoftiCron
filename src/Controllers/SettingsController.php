<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\Audit;
use App\Services\CronHelper;
use App\Services\Settings;
use App\Services\Tenants;

final class SettingsController extends Controller
{
    public function index(): Response
    {
        $this->authorize('settings.manage');
        $tenant = $this->tenant();
        return $this->view('settings/index', [
            'title' => 'Ρυθμίσεις', 'nav' => 'settings', 'tenant' => $tenant,
            'settings' => Settings::all((int) $tenant['id']),
        ]);
    }

    public function update(): Response
    {
        $this->authorize('settings.manage');
        $tenant = $this->tenant();
        $tid = (int) $tenant['id'];
        $before = ['name' => $tenant['name'], 'timezone' => $tenant['timezone']] + Settings::all($tid);
        $tz = (string) $this->req->input('timezone', $tenant['timezone']);
        if (!in_array($tz, CronHelper::timezones(), true)) {
            $this->flash('error', 'Άγνωστη ζώνη ώρας.');
            return $this->redirect('settings');
        }
        $name = mb_substr((string) $this->req->input('name', ''), 0, 150) ?: $tenant['name'];
        $emails = trim((string) $this->req->input('notify_emails', ''));
        foreach (array_filter(preg_split('/[,;\s]+/', $emails) ?: []) as $em) {
            if (!filter_var($em, FILTER_VALIDATE_EMAIL)) {
                $this->flash('error', "Μη έγκυρο email: $em");
                return $this->redirect('settings');
            }
        }
        $phones = [];
        foreach (preg_split('/[,;\n]+/', (string) $this->req->input('notify_phones', '')) ?: [] as $ph) {
            if (trim($ph) === '') {
                continue;
            }
            $n = \App\Services\Channels\RouteeSender::normalizePhone($ph);
            if ($n === null) {
                $this->flash('error', 'Μη έγκυρο κινητό: ' . trim($ph));
                return $this->redirect('settings');
            }
            $phones[] = $n;
        }
        Settings::set($tid, 'notify.phones', implode(', ', array_unique($phones)));
        Settings::set($tid, 'notify.sms_daily_limit', (string) max(0, min(100000, (int) $this->req->input('sms_daily_limit', \App\Services\Notifier::SMS_DAILY_LIMIT_DEFAULT))));
        $this->db->update('tenants', ['name' => $name, 'timezone' => $tz], 'id = :id', ['id' => $tid]);
        Settings::set($tid, 'notify.emails', $emails);
        Settings::set($tid, 'notify.telegram_chat', mb_substr((string) $this->req->input('notify_telegram_chat', ''), 0, 100));
        Settings::set($tid, 'notify.failure_repeat_minutes', (string) max(0, min(1440, (int) $this->req->input('failure_repeat_minutes', 60))));
        Audit::log('settings.updated', 'tenant', $tid, null, $before, ['name' => $name, 'timezone' => $tz] + Settings::all($tid));
        $this->flash('success', 'Οι ρυθμίσεις αποθηκεύτηκαν.');
        return $this->redirect('settings');
    }

    public function branding(): Response
    {
        $this->authorize('settings.manage');
        $tenant = $this->tenant();
        $tid = (int) $tenant['id'];
        $color = (string) $this->req->input('brand_color', $tenant['brand_color']);
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            $this->flash('error', 'Μη έγκυρο χρώμα.');
            return $this->redirect('settings');
        }
        $data = ['brand_color' => strtoupper($color)];
        if ($this->req->input('remove_logo')) {
            $data['logo_path'] = null;
        }
        $file = $this->req->file('logo');
        if ($file) {
            if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 1024 * 1024) {
                $this->flash('error', 'Το λογότυπο πρέπει να είναι έως 1 MB.');
                return $this->redirect('settings');
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime] ?? null;
            if (!$ext || !@getimagesize($file['tmp_name'])) {
                $this->flash('error', 'Επιτρέπονται μόνο εικόνες PNG, JPG ή WebP.');
                return $this->redirect('settings');
            }
            $name = 'logo-' . $tid . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
            @mkdir(App::storage('uploads'), 0775, true);
            if (!move_uploaded_file($file['tmp_name'], App::storage('uploads/' . $name))) {
                $this->flash('error', 'Η αποθήκευση του αρχείου απέτυχε.');
                return $this->redirect('settings');
            }
            if ($old = Tenants::logoFile($tenant)) {
                @unlink($old);
            }
            $data['logo_path'] = $name;
        }
        $this->db->update('tenants', $data, 'id = :id', ['id' => $tid]);
        Audit::log('branding.updated', 'tenant', $tid, null, ['brand_color' => $tenant['brand_color'], 'logo' => $tenant['logo_path']], ['brand_color' => $data['brand_color'], 'logo' => $data['logo_path'] ?? $tenant['logo_path']]);
        $this->flash('success', 'Το branding ενημερώθηκε.');
        return $this->redirect('settings');
    }

    /** Mobile footer menu items (tenant-wide). */
    public function menu(): Response
    {
        $this->authorize('settings.manage');
        $tid = $this->tenantId();
        $before = \App\Services\BottomNav::configured($tid);
        $keys = \App\Services\BottomNav::clean((array) ($_POST['slots'] ?? []));
        if (!$keys) {
            $this->flash('error', 'Διάλεξε τουλάχιστον ένα κουμπί για το μενού.');
            return $this->redirect('settings#footer-menu');
        }
        Settings::set($tid, 'ui.bottom_nav', implode(',', $keys));
        Audit::log('settings.updated', 'tenant', $tid, 'Μενού κινητού', ['footer' => $before], ['footer' => $keys]);
        $this->flash('success', 'Το μενού κινητού αποθηκεύτηκε.');
        return $this->redirect('settings#footer-menu');
    }

    /** Public: logos are shown in emails and on the login screen of invitations. */
    public function logo(int $id): Response
    {
        $tenant = $this->db->one('SELECT id, logo_path FROM tenants WHERE id = ?', [$id]);
        $path = $tenant ? Tenants::logoFile($tenant) : null;
        if (!$path) {
            throw HttpException::notFound();
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        return Response::file($path, $mime);
    }
}
