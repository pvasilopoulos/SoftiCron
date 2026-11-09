<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\InAppFeed;

/** JSON endpoints behind the bell popup (per user, per tenant). */
final class FeedController extends Controller
{
    private function scope(): array
    {
        return [(int) Auth::id(), $this->tenantId()];
    }

    public function index(): Response
    {
        [$uid, $tid] = $this->scope();
        $tz = $this->tenant()['timezone'];
        $limit = max(5, min(50, (int) $this->req->query('limit', 20)));
        $where = 'n.user_id = ? AND n.tenant_id = ?';
        $p = [$uid, $tid];
        $filter = (string) $this->req->query('filter', 'all');
        if ($filter === 'unread') {
            $where .= ' AND n.read_at IS NULL';
        } elseif (in_array($filter, ['error', 'warning', 'success'], true)) {
            $where .= ' AND n.level = ?';
            $p[] = $filter;
        }
        if ($before = (int) $this->req->query('before', 0)) {
            $where .= ' AND n.id < ?';
            $p[] = $before;
        }
        if (($q = trim((string) $this->req->query('q', ''))) !== '') {
            $where .= ' AND (n.title LIKE ? OR n.body LIKE ?)';
            array_push($p, "%$q%", "%$q%");
        }
        $rows = $this->db->all(
            "SELECT n.id, n.event, n.level, n.title, n.body, n.url, n.read_at, n.created_at, n.job_id, j.group_name
             FROM user_notifications n LEFT JOIN jobs j ON j.id = n.job_id
             WHERE $where ORDER BY n.id DESC LIMIT " . ($limit + 1),
            $p,
        );
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $today = (new \DateTimeImmutable('now', new \DateTimeZone($tz)))->format('Y-m-d');
        $yesterday = (new \DateTimeImmutable('yesterday', new \DateTimeZone($tz)))->format('Y-m-d');
        $items = array_map(function ($r) use ($tz, $today, $yesterday) {
            $local = (new \DateTimeImmutable($r['created_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($tz));
            $day = $local->format('Y-m-d');
            return [
                'id' => (int) $r['id'],
                'event' => $r['event'],
                'level' => $r['level'],
                'title' => $r['title'],
                'body' => $r['body'],
                'group' => $r['group_name'],
                'url' => $r['url'] ? url($r['url']) : null,
                'read' => $r['read_at'] !== null,
                'ago' => rel_time($r['created_at']),
                'time' => $local->format('H:i'),
                'day' => $day === $today ? 'Σήμερα' : ($day === $yesterday ? 'Χθες' : $local->format('d/m/Y')),
            ];
        }, $rows);
        return $this->json(['items' => $items, 'more' => $more, 'unread' => InAppFeed::unread($uid, $tid)]);
    }

    /** Cheap polling endpoint for the badge. */
    public function count(): Response
    {
        [$uid, $tid] = $this->scope();
        $latest = (int) $this->db->value('SELECT MAX(id) FROM user_notifications WHERE user_id = ? AND tenant_id = ?', [$uid, $tid]);
        return $this->json(['unread' => InAppFeed::unread($uid, $tid), 'latest' => $latest, 'tenants' => (object) InAppFeed::unreadByTenant($uid), 'tenant' => $tid]);
    }

    public function read(int $id): Response
    {
        return $this->setRead($id, true);
    }

    public function unread(int $id): Response
    {
        return $this->setRead($id, false);
    }

    private function setRead(int $id, bool $read): Response
    {
        [$uid, $tid] = $this->scope();
        $n = $this->db->exec(
            'UPDATE user_notifications SET read_at = ' . ($read ? 'COALESCE(read_at, UTC_TIMESTAMP())' : 'NULL') . ' WHERE id = ? AND user_id = ? AND tenant_id = ?',
            [$id, $uid, $tid],
        );
        if (!$n && !$this->db->value('SELECT 1 FROM user_notifications WHERE id = ? AND user_id = ?', [$id, $uid])) {
            throw HttpException::notFound();
        }
        return $this->json(['ok' => true, 'unread' => InAppFeed::unread($uid, $tid)]);
    }

    public function readAll(): Response
    {
        [$uid, $tid] = $this->scope();
        $this->db->exec('UPDATE user_notifications SET read_at = UTC_TIMESTAMP() WHERE user_id = ? AND tenant_id = ? AND read_at IS NULL', [$uid, $tid]);
        return $this->json(['ok' => true, 'unread' => 0]);
    }

    public function delete(int $id): Response
    {
        [$uid, $tid] = $this->scope();
        $this->db->exec('DELETE FROM user_notifications WHERE id = ? AND user_id = ? AND tenant_id = ?', [$id, $uid, $tid]);
        return $this->json(['ok' => true, 'unread' => InAppFeed::unread($uid, $tid)]);
    }

    /** Removes the notifications already read. */
    public function clearRead(): Response
    {
        [$uid, $tid] = $this->scope();
        $n = $this->db->exec('DELETE FROM user_notifications WHERE user_id = ? AND tenant_id = ? AND read_at IS NOT NULL', [$uid, $tid]);
        return $this->json(['ok' => true, 'deleted' => $n, 'unread' => InAppFeed::unread($uid, $tid)]);
    }
}
