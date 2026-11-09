<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Env;
use App\Core\Response;
use App\Services\Channels\PushSender;
use App\Services\Notifier;

final class NotificationController extends Controller
{
    public function index(): Response
    {
        $tid = $this->tenantId();
        $uid = (int) Auth::id();
        $devices = $this->db->all('SELECT * FROM push_subscriptions WHERE user_id = ? ORDER BY id DESC', [$uid]);
        $prefs = $this->db->value('SELECT events FROM user_notification_prefs WHERE user_id = ? AND tenant_id = ?', [$uid, $tid]);
        $events = $prefs !== null ? array_filter(explode(',', (string) $prefs)) : Notifier::DEFAULT_USER_EVENTS;
        return $this->view('notifications/index', [
            'title' => 'Ειδοποιήσεις', 'nav' => 'notifications',
            'devices' => $devices, 'events' => $events,
            'vapidKey' => (string) Env::get('VAPID_PUBLIC_KEY', ''),
            'pushConfigured' => PushSender::configured(),
        ]);
    }

    /** Detailed log of every notification sent / failed / skipped. */
    public function history(): Response
    {
        $this->authorize('notifications.history');
        $tid = $this->tenantId();
        $f = [
            'channel' => (string) $this->req->query('channel', ''),
            'status' => (string) $this->req->query('status', ''),
            'event' => (string) $this->req->query('event', ''),
            'job' => (int) $this->req->query('job', 0),
            'run' => (int) $this->req->query('run', 0),
            'from' => (string) $this->req->query('from', ''),
            'to' => (string) $this->req->query('to', ''),
            'q' => (string) $this->req->query('q', ''),
        ];
        $where = 'FROM notification_log n LEFT JOIN jobs j ON j.id = n.job_id WHERE n.tenant_id = :tid';
        $p = ['tid' => $tid];
        if (in_array($f['channel'], ['email', 'telegram', 'push'], true)) {
            $where .= ' AND n.channel = :ch';
            $p['ch'] = $f['channel'];
        }
        if (in_array($f['status'], ['sent', 'failed', 'skipped'], true)) {
            $where .= ' AND n.status = :st';
            $p['st'] = $f['status'];
        }
        if (isset(Notifier::EVENTS[$f['event']])) {
            $where .= ' AND n.event = :ev';
            $p['ev'] = $f['event'];
        }
        if ($f['job']) {
            $where .= ' AND n.job_id = :job';
            $p['job'] = $f['job'];
        }
        if ($f['run']) {
            $where .= ' AND n.run_id = :run';
            $p['run'] = $f['run'];
        }
        $tz = new \DateTimeZone($this->tenant()['timezone']);
        foreach (['from' => '00:00:00', 'to' => '23:59:59'] as $k => $time) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$k])) {
                $utc = (new \DateTimeImmutable($f[$k] . ' ' . $time, $tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                $where .= $k === 'from' ? ' AND n.created_at >= :from' : ' AND n.created_at <= :to';
                $p[$k] = $utc;
            } else {
                $f[$k] = '';
            }
        }
        if ($f['q'] !== '') {
            $where .= ' AND (n.target LIKE :q OR n.subject LIKE :q2 OR n.error LIKE :q3)';
            $p['q'] = $p['q2'] = $p['q3'] = '%' . $f['q'] . '%';
        }
        $page = $this->paginate($where, $p, 'n.*, j.name AS job_name', 'n.id DESC', 40);
        $stats = $this->db->all(
            "SELECT channel, SUM(status = 'sent') AS sent, SUM(status = 'failed') AS failed, SUM(status = 'skipped') AS skipped
             FROM notification_log WHERE tenant_id = ? AND created_at >= ? GROUP BY channel",
            [$tid, gmdate('Y-m-d H:i:s', time() - 7 * 86400)],
        );
        $jobs = $this->db->all('SELECT id, name FROM jobs WHERE tenant_id = ? ORDER BY name', [$tid]);
        return $this->view('notifications/history', [
            'title' => 'Ιστορικό ειδοποιήσεων', 'nav' => 'notifications', 'page' => $page, 'f' => $f,
            'stats' => array_column($stats, null, 'channel'), 'jobs' => $jobs,
        ]);
    }

    public function savePrefs(): Response
    {
        $tid = $this->tenantId();
        $events = array_values(array_intersect(array_keys(Notifier::EVENTS), (array) ($_POST['events'] ?? [])));
        $this->db->exec(
            'INSERT INTO user_notification_prefs (user_id, tenant_id, events) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE events = VALUES(events)',
            [Auth::id(), $tid, implode(',', $events)],
        );
        $this->flash('success', 'Οι προτιμήσεις αποθηκεύτηκαν.');
        return $this->redirect('notifications');
    }

    public function subscribe(): Response
    {
        $this->user();
        $endpoint = (string) $this->req->input('endpoint', '');
        $keys = (array) $this->req->input('keys', []);
        if (!preg_match('#^https://#', $endpoint) || empty($keys['p256dh']) || empty($keys['auth'])) {
            return $this->json(['error' => 'Μη έγκυρη συνδρομή.'], 422);
        }
        $ua = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
        \App\Services\Devices::register((int) Auth::id(), $endpoint, $keys, (array) $this->req->input('meta', []), $ua, $this->req->ip());
        return $this->json(['ok' => true]);
    }

    public function unsubscribe(): Response
    {
        $this->user();
        $endpoint = (string) $this->req->input('endpoint', '');
        $this->db->exec('DELETE FROM push_subscriptions WHERE endpoint_hash = ? AND user_id = ?', [hash('sha256', $endpoint), Auth::id()]);
        return $this->json(['ok' => true]);
    }

    private function device(int $id): array
    {
        $d = $this->db->one('SELECT * FROM push_subscriptions WHERE id = ? AND user_id = ?', [$id, Auth::id()]);
        if (!$d) {
            throw \App\Core\HttpException::notFound('Η συσκευή δεν βρέθηκε.');
        }
        return $d;
    }

    public function updateDevice(int $id): Response
    {
        $this->user();
        $this->device($id);
        $label = mb_substr(trim((string) $this->req->input('label', '')), 0, 100);
        $this->db->update('push_subscriptions', [
            'label' => $label !== '' ? $label : null,
            'enabled' => $this->req->input('enabled') ? 1 : 0,
        ], 'id = :id', ['id' => $id]);
        if ($this->req->wantsJson()) {
            return $this->json(['ok' => true]);
        }
        $this->flash('success', 'Η συσκευή ενημερώθηκε.');
        return $this->redirect('notifications');
    }

    public function testDevice(int $id): Response
    {
        $this->user();
        $d = $this->device($id);
        $res = PushSender::sendToSubscriptions([$id], [
            'title' => 'Δοκιμαστική ειδοποίηση',
            'body' => 'Συσκευή: ' . ($d['label'] ?: $d['user_agent'] ?: 'χωρίς όνομα'),
            'url' => abs_url('notifications'),
            'tag' => 'test-' . $id,
            'icon' => abs_url('assets/icons/icon-192.png'),
            'badge' => abs_url('assets/icons/badge-72.png'),
        ], true);
        $ok = $res['sent'] > 0;
        $msg = $ok ? 'Στάλθηκε δοκιμαστική ειδοποίηση στη συσκευή «' . ($d['label'] ?: $d['user_agent'] ?: 'χωρίς όνομα') . '».' : 'Αποτυχία: ' . (implode('; ', array_slice($res['errors'], 0, 2)) ?: 'άγνωστο σφάλμα');
        if ($this->req->wantsJson()) {
            return $this->json(['ok' => $ok, 'message' => $msg], $ok ? 200 : 422);
        }
        $this->flash($ok ? 'success' : 'error', $msg);
        return $this->redirect('notifications');
    }

    public function deleteDevice(int $id): Response
    {
        $this->user();
        $this->db->exec('DELETE FROM push_subscriptions WHERE id = ? AND user_id = ?', [$id, Auth::id()]);
        if ($this->req->wantsJson()) {
            return $this->json(['ok' => true]);
        }
        $this->flash('success', 'Η συσκευή αφαιρέθηκε.');
        return $this->redirect('notifications');
    }

    /** Removes my disabled, failing or long-unused devices in one go. */
    public function cleanupDevices(): Response
    {
        $this->user();
        $what = (string) $this->req->input('what', '');
        $cond = match ($what) {
            'disabled' => 'enabled = 0',
            'failing' => 'fail_count > 0',
            'stale' => "COALESCE(last_seen_at, created_at) < UTC_TIMESTAMP() - INTERVAL 30 DAY",
            default => null,
        };
        if ($cond === null) {
            throw new \App\Core\HttpException(422, 'Άγνωστη επιλογή.');
        }
        $n = $this->db->exec("DELETE FROM push_subscriptions WHERE user_id = ? AND $cond", [Auth::id()]);
        $msg = $n ? 'Αφαιρέθηκαν ' . $n . ($n === 1 ? ' συσκευή.' : ' συσκευές.') : 'Δεν βρέθηκαν συσκευές για αφαίρεση.';
        if ($this->req->wantsJson()) {
            return $this->json(['ok' => true, 'deleted' => $n, 'message' => $msg]);
        }
        $this->flash('success', $msg);
        return $this->redirect('notifications');
    }

    public function test(): Response
    {
        $this->user();
        $res = PushSender::sendToUsers([(int) Auth::id()], [
            'title' => 'Δοκιμαστική ειδοποίηση',
            'body' => 'Οι ειδοποιήσεις push λειτουργούν σε αυτή τη συσκευή.',
            'url' => abs_url('notifications'),
            'tag' => 'test',
            'icon' => abs_url('assets/icons/icon-192.png'),
            'badge' => abs_url('assets/icons/badge-72.png'),
        ]);
        if ($res['sent'] > 0) {
            $msg = 'Στάλθηκε σε ' . $res['sent'] . ($res['sent'] === 1 ? ' συσκευή.' : ' συσκευές.');
            $ok = true;
        } else {
            $msg = $res['errors'] ? 'Αποτυχία: ' . implode('; ', array_slice($res['errors'], 0, 2)) : 'Δεν υπάρχει καταχωρημένη συσκευή. Ενεργοποίησε πρώτα τις ειδοποιήσεις.';
            $ok = false;
        }
        if ($this->req->wantsJson()) {
            return $this->json(['ok' => $ok, 'message' => $msg], $ok ? 200 : 422);
        }
        $this->flash($ok ? 'success' : 'error', $msg);
        return $this->redirect('notifications');
    }

}
