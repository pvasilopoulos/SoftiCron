<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\Audit;
use App\Services\Settings;
use App\Services\StatusPage;
use App\Services\Tenants;

/** Administration of the public status page: settings, components, announcements, embedding. */
final class StatusAdminController extends Controller
{
    public function index(): Response
    {
        $this->authorize('settings.status_page');
        $tenant = $this->tenant();
        $tid = (int) $tenant['id'];
        $jobs = $this->db->all(
            'SELECT id, name, group_name, status_name, show_on_status, enabled, last_status, type FROM jobs WHERE tenant_id = ? ORDER BY group_name IS NULL, group_name, name',
            [$tid],
        );
        $notices = $this->db->all('SELECT * FROM status_notices WHERE tenant_id = ? ORDER BY (ends_at IS NULL OR ends_at > UTC_TIMESTAMP()) DESC, starts_at DESC LIMIT 100', [$tid]);
        $edit = null;
        if ($eid = (int) $this->req->query('notice', 0)) {
            foreach ($notices as $n) {
                if ((int) $n['id'] === $eid) {
                    $edit = $n;
                }
            }
        }
        $preview = Settings::get($tid, 'status.enabled') === '1' ? StatusPage::data($tenant) : null;
        return $this->view('status/admin', [
            'title' => 'Σελίδα κατάστασης', 'nav' => 'status-page', 'tenant' => $tenant,
            'settings' => Settings::all($tid), 'options' => StatusPage::options($tid),
            'jobs' => $jobs, 'notices' => $notices, 'edit' => $edit, 'preview' => $preview,
            'tab' => in_array($this->req->query('tab'), ['components', 'notices', 'appearance', 'embed'], true) ? $this->req->query('tab') : 'components',
        ]);
    }

    public function settings(): Response
    {
        $this->authorize('settings.status_page');
        $tenant = $this->tenant();
        $tid = (int) $tenant['id'];
        $slug = Tenants::slugify((string) $this->req->input('slug', '') ?: $tenant['slug']);
        if ($this->db->value("SELECT 1 FROM settings WHERE k = 'status.slug' AND v = ? AND tenant_id <> ?", [$slug, $tid])) {
            $this->flash('error', "Η διεύθυνση /status/$slug χρησιμοποιείται ήδη. Διάλεξε άλλη.");
            return $this->redirect('status-page?tab=appearance');
        }
        $before = ['enabled' => Settings::get($tid, 'status.enabled'), 'slug' => Settings::get($tid, 'status.slug')] + StatusPage::options($tid);
        Settings::set($tid, 'status.enabled', $this->req->input('enabled') ? '1' : '0');
        Settings::set($tid, 'status.slug', $slug);
        Settings::set($tid, 'status.title', mb_substr((string) $this->req->input('title', ''), 0, 120));
        Settings::set($tid, 'status.description', mb_substr((string) $this->req->input('description', ''), 0, 255));
        foreach (StatusPage::OPTIONS as $k => $def) {
            $v = match ($k) {
                'default_days' => in_array((string) $this->req->input($k), ['7', '30', '90'], true) ? (string) $this->req->input($k) : '90',
                'theme' => in_array((string) $this->req->input($k), ['auto', 'light', 'dark'], true) ? (string) $this->req->input($k) : 'auto',
                default => $this->req->input($k) ? '1' : '0',
            };
            Settings::set($tid, 'status.' . $k, $v);
        }
        Audit::log('settings.updated', 'tenant', $tid, 'Σελίδα κατάστασης', $before, ['enabled' => Settings::get($tid, 'status.enabled'), 'slug' => $slug] + StatusPage::options($tid));
        $this->flash('success', 'Οι ρυθμίσεις της σελίδας κατάστασης αποθηκεύτηκαν.');
        return $this->redirect('status-page?tab=appearance');
    }

    /** Which jobs are shown and under which public name. */
    public function components(): Response
    {
        $this->authorize('settings.status_page');
        $tid = $this->tenantId();
        $show = array_map('intval', (array) ($_POST['show'] ?? []));
        $names = (array) ($_POST['status_name'] ?? []);
        $changed = 0;
        foreach ($this->db->all('SELECT id, show_on_status, status_name FROM jobs WHERE tenant_id = ?', [$tid]) as $j) {
            $on = in_array((int) $j['id'], $show, true) ? 1 : 0;
            $name = array_key_exists((string) $j['id'], $names) || array_key_exists((int) $j['id'], $names)
                ? (mb_substr(trim((string) ($names[$j['id']] ?? '')), 0, 100) ?: null)
                : $j['status_name'];
            if ($on !== (int) $j['show_on_status'] || $name !== $j['status_name']) {
                $this->db->update('jobs', ['show_on_status' => $on, 'status_name' => $name], 'id = :id', ['id' => $j['id']]);
                $changed++;
            }
        }
        Audit::log('settings.updated', 'tenant', $tid, 'Υπηρεσίες σελίδας κατάστασης: ' . count($show) . ' εμφανίζονται');
        $this->flash('success', $changed ? 'Οι υπηρεσίες ενημερώθηκαν (' . $changed . ' αλλαγές).' : 'Καμία αλλαγή.');
        return $this->redirect('status-page?tab=components');
    }

    // ---- Announcements --------------------------------------------------------

    private function notice(int $id): array
    {
        $n = $this->db->one('SELECT * FROM status_notices WHERE id = ? AND tenant_id = ?', [$id, $this->tenantId()]);
        if (!$n) {
            throw HttpException::notFound('Η ανακοίνωση δεν βρέθηκε.');
        }
        return $n;
    }

    /** "2026-11-10T08:00" in tenant time → UTC "Y-m-d H:i:s". */
    private function toUtc(string $local): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $local)) {
            return null;
        }
        $tz = new \DateTimeZone($this->tenant()['timezone'] ?: 'Europe/Athens');
        return (new \DateTimeImmutable($local, $tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function saveNotice(?array $existing): Response
    {
        $title = mb_substr(trim((string) $this->req->input('title', '')), 0, 200);
        $kind = (string) $this->req->input('kind', 'info');
        if (!isset(StatusPage::NOTICE_KINDS[$kind])) {
            $kind = 'info';
        }
        $starts = $this->toUtc((string) $this->req->input('starts_at', '')) ?? gmdate('Y-m-d H:i:s');
        $ends = $this->toUtc((string) $this->req->input('ends_at', ''));
        if ($title === '') {
            $this->flash('error', 'Ο τίτλος είναι υποχρεωτικός.');
            return $this->redirect('status-page?tab=notices' . ($existing ? '&notice=' . $existing['id'] : ''));
        }
        if ($ends !== null && $ends <= $starts) {
            $this->flash('error', 'Η λήξη πρέπει να είναι μετά την έναρξη.');
            return $this->redirect('status-page?tab=notices' . ($existing ? '&notice=' . $existing['id'] : ''));
        }
        $data = [
            'kind' => $kind, 'title' => $title, 'body' => mb_substr(trim($this->req->raw('body')), 0, 5000) ?: null,
            'starts_at' => $starts, 'ends_at' => $ends, 'is_pinned' => $this->req->input('is_pinned') ? 1 : 0,
        ];
        if ($existing) {
            $this->db->update('status_notices', $data, 'id = :id', ['id' => $existing['id']]);
            Audit::log('status.notice_updated', 'status_notice', (int) $existing['id'], $title, $existing, $data);
            $this->flash('success', 'Η ανακοίνωση ενημερώθηκε.');
        } else {
            $id = $this->db->insert('status_notices', $data + ['tenant_id' => $this->tenantId(), 'created_by' => Auth::id()]);
            Audit::log('status.notice_created', 'status_notice', $id, $title, null, $data);
            $this->flash('success', 'Η ανακοίνωση δημοσιεύτηκε.');
        }
        return $this->redirect('status-page?tab=notices');
    }

    public function storeNotice(): Response
    {
        $this->authorize('settings.status_page');
        return $this->saveNotice(null);
    }

    public function updateNotice(int $id): Response
    {
        $this->authorize('settings.status_page');
        return $this->saveNotice($this->notice($id));
    }

    /** Ends an announcement now (an incident becomes "resolved"). */
    public function resolveNotice(int $id): Response
    {
        $this->authorize('settings.status_page');
        $n = $this->notice($id);
        $this->db->update('status_notices', [
            'ends_at' => gmdate('Y-m-d H:i:s'), 'kind' => $n['kind'] === 'incident' ? 'resolved' : $n['kind'], 'is_pinned' => 0,
        ], 'id = :id', ['id' => $id]);
        Audit::log('status.notice_resolved', 'status_notice', $id, $n['title']);
        $this->flash('success', $n['kind'] === 'incident' ? 'Το συμβάν σημειώθηκε ως επιλυμένο.' : 'Η ανακοίνωση έληξε.');
        return $this->redirect('status-page?tab=notices');
    }

    public function deleteNotice(int $id): Response
    {
        $this->authorize('settings.status_page');
        $n = $this->notice($id);
        $this->db->exec('DELETE FROM status_notices WHERE id = ?', [$id]);
        Audit::log('status.notice_deleted', 'status_notice', $id, $n['title']);
        $this->flash('success', 'Η ανακοίνωση διαγράφηκε.');
        return $this->redirect('status-page?tab=notices');
    }
}
