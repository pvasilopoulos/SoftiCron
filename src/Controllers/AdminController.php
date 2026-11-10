<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\Audit;
use App\Services\Channels\EmailSender;
use App\Services\Channels\PushSender;
use App\Services\CronHelper;
use App\Services\Settings;
use App\Services\Tenants;

/** Platform super-admin: tenants and system health. */
final class AdminController extends Controller
{
    public function tenants(): Response
    {
        $this->superAdmin();
        $rows = $this->db->all(
            'SELECT t.*,
                (SELECT COUNT(*) FROM memberships m WHERE m.tenant_id = t.id) AS users,
                (SELECT COUNT(*) FROM jobs j WHERE j.tenant_id = t.id) AS jobs,
                (SELECT COUNT(*) FROM job_runs r WHERE r.tenant_id = t.id AND r.created_at > UTC_TIMESTAMP() - INTERVAL 1 DAY) AS runs_24h
             FROM tenants t ORDER BY t.name',
        );
        return $this->view('admin/tenants', ['title' => 'Tenants', 'nav' => 'admin', 'rows' => $rows], Auth::tenant() ? 'layout' : 'auth_layout');
    }

    public function createTenant(): Response
    {
        $this->superAdmin();
        return $this->view('admin/tenant_form', [
            'title' => 'Νέος tenant', 'nav' => 'admin',
            't' => ['id' => null, 'name' => '', 'slug' => '', 'status' => 'active', 'timezone' => 'Europe/Athens', 'brand_color' => '#4F46E5', 'max_jobs' => 50, 'min_interval_minutes' => 1, 'log_retention_days' => 30],
        ], Auth::tenant() ? 'layout' : 'auth_layout');
    }

    public function editTenant(int $id): Response
    {
        $this->superAdmin();
        $t = $this->db->one('SELECT * FROM tenants WHERE id = ?', [$id]) ?? throw HttpException::notFound();
        return $this->view('admin/tenant_form', ['title' => $t['name'], 'nav' => 'admin', 't' => $t], Auth::tenant() ? 'layout' : 'auth_layout');
    }

    /** @return array{0: array, 1: string[]} */
    private function collect(?int $id): array
    {
        $errors = [];
        $name = mb_substr((string) $this->req->input('name', ''), 0, 150);
        $slug = Tenants::slugify((string) $this->req->input('slug', '') ?: $name);
        if ($name === '') {
            $errors[] = 'Το όνομα είναι υποχρεωτικό.';
        }
        if ($this->db->value('SELECT 1 FROM tenants WHERE slug = ? AND id <> ?', [$slug, $id ?? 0])) {
            $errors[] = 'Το slug χρησιμοποιείται ήδη.';
        }
        $tz = (string) $this->req->input('timezone', 'Europe/Athens');
        if (!in_array($tz, CronHelper::timezones(), true)) {
            $errors[] = 'Άγνωστη ζώνη ώρας.';
        }
        $color = (string) $this->req->input('brand_color', '#4F46E5');
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            $color = '#4F46E5';
        }
        return [[
            'name' => $name, 'slug' => $slug, 'timezone' => $tz, 'brand_color' => strtoupper($color),
            'status' => $this->req->input('status') === 'suspended' ? 'suspended' : 'active',
            'max_jobs' => max(1, min(100000, (int) $this->req->input('max_jobs', 50))),
            'min_interval_minutes' => max(1, min(1440, (int) $this->req->input('min_interval_minutes', 1))),
            'log_retention_days' => max(1, min(3650, (int) $this->req->input('log_retention_days', 30))),
        ], $errors];
    }

    public function storeTenant(): Response
    {
        $this->superAdmin();
        [$data, $errors] = $this->collect(null);
        $ownerEmail = strtolower(trim((string) $this->req->input('owner_email', '')));
        if ($ownerEmail !== '' && !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Μη έγκυρο email ιδιοκτήτη.';
        }
        if ($errors) {
            $this->keepOld();
            foreach ($errors as $e) {
                $this->flash('error', $e);
            }
            return $this->redirect('admin/tenants/new');
        }
        $id = Tenants::create($data);
        if ($data['status'] === 'suspended') {
            $this->db->update('tenants', ['status' => 'suspended'], 'id = :id', ['id' => $id]);
        }
        Audit::log('tenant.created', 'tenant', $id, $data['name'], null, $data, $id);
        $msg = 'Ο tenant δημιουργήθηκε.';
        if ($ownerEmail !== '') {
            $user = $this->db->one('SELECT id FROM users WHERE email = ?', [$ownerEmail]);
            if ($user) {
                $this->db->insert('memberships', ['tenant_id' => $id, 'user_id' => $user['id'], 'role_id' => 1]);
                $msg .= ' Ο χρήστης προστέθηκε ως Owner.';
            } else {
                $token = bin2hex(random_bytes(24));
                $this->db->insert('invitations', [
                    'tenant_id' => $id, 'email' => $ownerEmail, 'role_id' => 1, 'token_hash' => hash('sha256', $token),
                    'invited_by' => Auth::id(), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 7 * 86400),
                ]);
                $link = abs_url('invite/' . $token);
                $_SESSION['_invite_link'] = $link;
                if (EmailSender::platformConfigured()) {
                    try {
                        $tenant = $this->db->one('SELECT * FROM tenants WHERE id = ?', [$id]);
                        $text = "Δημιουργήθηκε ο οργανισμός «{$data['name']}» και είσαι ο ιδιοκτήτης του.";
                        EmailSender::sendPlatform([$ownerEmail], 'Καλώς ήρθες στο ' . \App\Core\App::name(), \App\Services\Notifier::emailHtml($tenant, 'Καλώς ήρθες', $text, $link, null), $text . "\n\n" . $link);
                        $msg .= " Στάλθηκε πρόσκληση στο $ownerEmail.";
                    } catch (\Throwable $e) {
                        $msg .= ' Το email δεν στάλθηκε (' . $e->getMessage() . ').';
                    }
                }
                $msg .= ' Σύνδεσμος πρόσκλησης: ' . $link;
            }
        }
        $this->flash('success', $msg);
        return $this->redirect('admin/tenants');
    }

    public function updateTenant(int $id): Response
    {
        $this->superAdmin();
        $before = $this->db->one('SELECT * FROM tenants WHERE id = ?', [$id]) ?? throw HttpException::notFound();
        [$data, $errors] = $this->collect($id);
        if ($errors) {
            foreach ($errors as $e) {
                $this->flash('error', $e);
            }
            return $this->redirect("admin/tenants/$id/edit");
        }
        $this->db->update('tenants', $data, 'id = :id', ['id' => $id]);
        Audit::log('tenant.updated', 'tenant', $id, $data['name'], $before, $data, $id);
        $this->flash('success', 'Ο tenant ενημερώθηκε.');
        return $this->redirect('admin/tenants');
    }

    public function enterTenant(int $id): Response
    {
        $this->superAdmin();
        if (!Auth::switchTenant($id)) {
            throw HttpException::notFound();
        }
        return $this->redirect('/');
    }

    public function system(): Response
    {
        $this->superAdmin();
        $lastTick = Settings::get(0, 'scheduler.last_tick');
        $checks = [
            ['PHP έκδοση', PHP_VERSION, version_compare(PHP_VERSION, '8.2', '>=')],
            ['Scheduler (bin/cron.php)', $lastTick ? 'Τελευταία εκτέλεση ' . fmt_dt($lastTick, 'Europe/Athens', 'd/m H:i:s') : 'Δεν έχει τρέξει ποτέ', $lastTick && strtotime($lastTick . ' UTC') > time() - 180],
            ['APP_KEY', Env::get('APP_KEY') ? 'Ορισμένο' : 'Λείπει', (bool) Env::get('APP_KEY')],
            ['APP_URL', (string) Env::get('APP_URL', '—'), (bool) Env::get('APP_URL')],
            (function (): array {
                $err = PushSender::selfTest();
                return ['Web Push (VAPID)', $err ?? 'Ρυθμισμένο, τα κλειδιά ταιριάζουν', $err === null];
            })(),
            ['Platform mailer', EmailSender::platformConfigured() ? (string) Env::get('MAIL_HOST') : 'Δεν έχει ρυθμιστεί (προαιρετικό)', true],
            ['Εσωτερικές διευθύνσεις', Env::bool('ALLOW_PRIVATE_TARGETS') ? 'Επιτρέπονται (μόνο για dev!)' : 'Μπλοκάρονται', !Env::bool('ALLOW_PRIVATE_TARGETS')],
            ['Επεκτάσεις PHP', implode(', ', array_filter(['curl', 'sodium', 'pdo_mysql', 'mbstring', 'openssl', 'gmp'], fn ($e) => !extension_loaded($e))) ?: 'Όλες φορτωμένες', !array_filter(['curl', 'sodium', 'pdo_mysql', 'mbstring', 'openssl'], fn ($e) => !extension_loaded($e))],
            ['storage/ εγγράψιμο', is_writable(\App\Core\App::storage()) ? 'Ναι' : 'Όχι', is_writable(\App\Core\App::storage())],
        ];
        $queue = $this->db->one("SELECT SUM(status='queued') AS queued, SUM(status='running') AS running, SUM(status='retrying') AS retrying FROM job_runs WHERE status IN ('queued','running','retrying')");
        $watch = \App\Services\Watchdog::status() + [
            'ping_url' => (string) Settings::get(0, 'watchdog.ping_url', ''),
            'ping_last' => (string) Settings::get(0, 'watchdog.ping_last', ''),
            'alert_at' => (string) Settings::get(0, 'watchdog.alert_at', ''),
        ];
        return $this->view('admin/system', ['title' => 'Κατάσταση συστήματος', 'nav' => 'system', 'checks' => $checks, 'queue' => $queue, 'watch' => $watch], Auth::tenant() ? 'layout' : 'auth_layout');
    }

    public function watchdogSave(): Response
    {
        $this->superAdmin();
        $url = trim((string) $this->req->input('ping_url', ''));
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            $this->flash('error', 'Το URL πρέπει να ξεκινά με https:// (ή http://).');
            return $this->redirect('admin/system');
        }
        $before = (string) Settings::get(0, 'watchdog.ping_url', '');
        Settings::set(0, 'watchdog.ping_url', mb_substr($url, 0, 500));
        if ($url !== $before) {
            Settings::set(0, 'watchdog.ping_last', '');
        }
        Audit::log('system.watchdog', 'system', null, $url !== '' ? 'Ping URL: ' . parse_url($url, PHP_URL_HOST) : 'Ping URL αφαιρέθηκε', tenantId: null);
        $this->flash('success', $url !== '' ? 'Αποθηκεύτηκε. Ο scheduler θα το καλεί σε κάθε εκτέλεση (κάθε λεπτό).' : 'Το ping URL αφαιρέθηκε.');
        return $this->redirect('admin/system');
    }

    public function watchdogTest(): Response
    {
        $this->superAdmin();
        \App\Services\Watchdog::pingOut();
        \App\Services\Watchdog::alertAdmins('🔔 Δοκιμή επιτήρησης scheduler', 'Αυτή είναι δοκιμαστική ειδοποίηση. Έτσι θα ειδοποιηθείς αν σταματήσει το bin/cron.php.', 'info');
        $last = (string) Settings::get(0, 'watchdog.ping_last', '');
        $this->flash('success', 'Στάλθηκε δοκιμαστική ειδοποίηση στους super admins (push, email, εφαρμογή).' . ($last !== '' ? ' Ping: ' . explode('|', $last, 2)[1] : ''));
        return $this->redirect('admin/system');
    }
}
