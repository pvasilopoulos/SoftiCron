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
        $devices = $this->db->all('SELECT id, user_agent, created_at, last_success_at FROM push_subscriptions WHERE user_id = ? ORDER BY id DESC', [$uid]);
        $prefs = $this->db->value('SELECT events FROM user_notification_prefs WHERE user_id = ? AND tenant_id = ?', [$uid, $tid]);
        $events = $prefs !== null ? array_filter(explode(',', (string) $prefs)) : Notifier::DEFAULT_USER_EVENTS;
        return $this->view('notifications/index', [
            'title' => 'Ειδοποιήσεις', 'nav' => 'notifications',
            'devices' => $devices, 'events' => $events,
            'vapidKey' => (string) Env::get('VAPID_PUBLIC_KEY', ''),
            'pushConfigured' => PushSender::configured(),
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
        $this->db->exec(
            'INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth, user_agent) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth), user_agent = VALUES(user_agent)',
            [Auth::id(), $endpoint, hash('sha256', $endpoint), mb_substr((string) $keys['p256dh'], 0, 255), mb_substr((string) $keys['auth'], 0, 255), self::deviceName($this->req->userAgent())],
        );
        return $this->json(['ok' => true]);
    }

    public function unsubscribe(): Response
    {
        $this->user();
        $endpoint = (string) $this->req->input('endpoint', '');
        $this->db->exec('DELETE FROM push_subscriptions WHERE endpoint_hash = ? AND user_id = ?', [hash('sha256', $endpoint), Auth::id()]);
        return $this->json(['ok' => true]);
    }

    public function deleteDevice(int $id): Response
    {
        $this->user();
        $this->db->exec('DELETE FROM push_subscriptions WHERE id = ? AND user_id = ?', [$id, Auth::id()]);
        $this->flash('success', 'Η συσκευή αφαιρέθηκε.');
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

    private static function deviceName(string $ua): string
    {
        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') => 'Mac',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Συσκευή',
        };
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Firefox') => 'Firefox',
            str_contains($ua, 'Chrome') => 'Chrome',
            str_contains($ua, 'Safari') => 'Safari',
            default => 'Browser',
        };
        return "$os · $browser";
    }
}
