<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\Audit;
use App\Services\Channels\PushSender;

/** Admin view of the push devices of every member of the tenant. */
final class DeviceController extends Controller
{
    private const STALE_DAYS = 30;

    public function index(): Response
    {
        $this->authorize('devices.view');
        $tid = $this->tenantId();
        $f = [
            'user' => (int) $this->req->query('user', 0),
            'provider' => (string) $this->req->query('provider', ''),
            'status' => (string) $this->req->query('status', ''),
            'q' => trim((string) $this->req->query('q', '')),
        ];
        $where = 'FROM push_subscriptions s JOIN users u ON u.id = s.user_id JOIN memberships m ON m.user_id = s.user_id AND m.tenant_id = :tid WHERE 1 = 1';
        $p = ['tid' => $tid];
        if ($f['user']) {
            $where .= ' AND s.user_id = :uid';
            $p['uid'] = $f['user'];
        }
        if (isset(\App\Services\Devices::PROVIDERS[$f['provider']])) {
            $where .= ' AND s.provider = :prov';
            $p['prov'] = $f['provider'];
        }
        $stale = gmdate('Y-m-d H:i:s', time() - self::STALE_DAYS * 86400);
        $where .= match ($f['status']) {
            'active' => ' AND s.enabled = 1 AND s.fail_count = 0',
            'disabled' => ' AND s.enabled = 0',
            'failing' => ' AND s.fail_count > 0',
            'stale' => " AND COALESCE(s.last_seen_at, s.created_at) < '$stale'",
            'pwa' => ' AND s.is_pwa = 1',
            default => '',
        };
        if ($f['q'] !== '') {
            $where .= ' AND (u.name LIKE :q1 OR u.email LIKE :q2 OR s.label LIKE :q3 OR s.platform LIKE :q4 OR s.browser LIKE :q5 OR s.last_ip LIKE :q6)';
            foreach (range(1, 6) as $i) {
                $p["q$i"] = '%' . $f['q'] . '%';
            }
        }
        $page = $this->paginate($where, $p, 's.*, u.name AS user_name, u.email AS user_email', 'u.name, s.id DESC', 50);
        $stats = $this->db->one(
            "SELECT COUNT(*) AS total, COUNT(DISTINCT s.user_id) AS users, SUM(s.enabled = 1) AS enabled, SUM(s.fail_count > 0) AS failing,
                    SUM(s.is_pwa = 1) AS pwa, SUM(COALESCE(s.last_seen_at, s.created_at) < ?) AS stale
             FROM push_subscriptions s JOIN memberships m ON m.user_id = s.user_id AND m.tenant_id = ?",
            [$stale, $tid],
        );
        $members = $this->db->all(
            'SELECT u.id, u.name, (SELECT COUNT(*) FROM push_subscriptions s WHERE s.user_id = u.id) AS devices
             FROM memberships m JOIN users u ON u.id = m.user_id WHERE m.tenant_id = ? ORDER BY u.name',
            [$tid],
        );
        return $this->view('devices/index', [
            'title' => 'Συσκευές χρηστών', 'nav' => 'devices', 'page' => $page, 'f' => $f, 'stats' => $stats,
            'members' => $members, 'canManage' => Auth::can('devices.manage'), 'staleDays' => self::STALE_DAYS,
        ]);
    }

    /** A device that belongs to a member of the current tenant. */
    private function device(int $id): array
    {
        $d = $this->db->one(
            'SELECT s.*, u.name AS user_name FROM push_subscriptions s JOIN users u ON u.id = s.user_id
             JOIN memberships m ON m.user_id = s.user_id AND m.tenant_id = ? WHERE s.id = ?',
            [$this->tenantId(), $id],
        );
        if (!$d) {
            throw HttpException::notFound('Η συσκευή δεν βρέθηκε.');
        }
        return $d;
    }

    private function label(array $d): string
    {
        return ($d['label'] ?: $d['user_agent'] ?: 'Συσκευή') . ' (' . $d['user_name'] . ')';
    }

    public function toggle(int $id): Response
    {
        $this->authorize('devices.manage');
        $d = $this->device($id);
        $enabled = $d['enabled'] ? 0 : 1;
        $this->db->update('push_subscriptions', ['enabled' => $enabled], 'id = :id', ['id' => $id]);
        Audit::log($enabled ? 'device.enabled' : 'device.disabled', 'user', (int) $d['user_id'], $this->label($d));
        $this->flash('success', $enabled ? 'Η συσκευή ενεργοποιήθηκε.' : 'Η συσκευή απενεργοποιήθηκε.');
        return $this->back('devices');
    }

    public function test(int $id): Response
    {
        $this->authorize('devices.manage');
        $d = $this->device($id);
        $res = PushSender::sendToSubscriptions([$id], [
            'title' => 'Δοκιμαστική ειδοποίηση',
            'body' => 'Στάλθηκε από τον διαχειριστή ' . (Auth::user()['name'] ?? '') . ' για έλεγχο της συσκευής.',
            'url' => abs_url('notifications'),
            'tag' => 'admin-test-' . $id,
            'icon' => abs_url('assets/icons/icon-192.png'),
            'badge' => abs_url('assets/icons/badge-72.png'),
        ], true);
        $ok = $res['sent'] > 0;
        Audit::log('device.tested', 'user', (int) $d['user_id'], $this->label($d) . ($ok ? '' : ' · αποτυχία'));
        $this->flash($ok ? 'success' : 'error', $ok ? 'Η δοκιμή στάλθηκε στη συσκευή ' . $this->label($d) . '.' : 'Αποτυχία: ' . (implode('; ', array_slice($res['errors'], 0, 2)) ?: 'άγνωστο σφάλμα'));
        return $this->back('devices');
    }

    public function delete(int $id): Response
    {
        $this->authorize('devices.manage');
        $d = $this->device($id);
        $this->db->exec('DELETE FROM push_subscriptions WHERE id = ?', [$id]);
        Audit::log('device.removed', 'user', (int) $d['user_id'], $this->label($d));
        $this->flash('success', 'Η συσκευή αφαιρέθηκε. Ο χρήστης μπορεί να την ξαναενεργοποιήσει από τις Ειδοποιήσεις του.');
        return $this->back('devices');
    }
}
