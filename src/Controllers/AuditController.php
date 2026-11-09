<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Services\Audit;

final class AuditController extends Controller
{
    public const AREAS = [
        'job' => 'Jobs', 'run' => 'Εκτελέσεις', 'connector' => 'Connectors', 'template' => 'Templates',
        'user' => 'Χρήστες', 'invite' => 'Προσκλήσεις', 'role' => 'Ρόλοι', 'settings' => 'Ρυθμίσεις',
        'branding' => 'Branding', 'auth' => 'Συνδέσεις', 'profile' => 'Προφίλ', 'tenant' => 'Tenant',
    ];
    public const PERIODS = ['24h' => '24 ώρες', '7d' => '7 ημέρες', '30d' => '30 ημέρες', '90d' => '90 ημέρες'];

    private function filters(): array
    {
        $tid = $this->tenantId();
        $f = [
            'area' => (string) $this->req->query('area', (string) $this->req->query('action', '')),
            'act' => (string) $this->req->query('act', ''),
            'user' => (int) $this->req->query('user', 0),
            'period' => (string) $this->req->query('period', ''),
            'from' => (string) $this->req->query('from', ''),
            'to' => (string) $this->req->query('to', ''),
            'q' => (string) $this->req->query('q', ''),
            'changes' => (string) $this->req->query('changes', '') === '1' ? '1' : '',
        ];
        $where = 'FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.tenant_id = :tid';
        $p = ['tid' => $tid];
        if (isset(self::AREAS[$f['area']])) {
            $where .= ' AND a.action LIKE :area';
            $p['area'] = $f['area'] . '.%';
        } else {
            $f['area'] = '';
        }
        if (isset(Audit::ACTIONS[$f['act']])) {
            $where .= ' AND a.action = :act';
            $p['act'] = $f['act'];
        } else {
            $f['act'] = '';
        }
        if ($f['user']) {
            $where .= ' AND a.user_id = :uid';
            $p['uid'] = $f['user'];
        }
        if (isset(self::PERIODS[$f['period']])) {
            $days = ['24h' => 1, '7d' => 7, '30d' => 30, '90d' => 90][$f['period']];
            $where .= ' AND a.created_at >= :since';
            $p['since'] = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        } else {
            $f['period'] = '';
        }
        $tz = new \DateTimeZone($this->tenant()['timezone']);
        foreach (['from' => '00:00:00', 'to' => '23:59:59'] as $k => $time) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$k])) {
                $where .= $k === 'from' ? ' AND a.created_at >= :dfrom' : ' AND a.created_at <= :dto';
                $p[$k === 'from' ? 'dfrom' : 'dto'] = (new \DateTimeImmutable($f[$k] . ' ' . $time, $tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            } else {
                $f[$k] = '';
            }
        }
        if ($f['changes']) {
            $where .= ' AND a.changes IS NOT NULL';
        }
        if ($f['q'] !== '') {
            $where .= ' AND (a.summary LIKE :q OR a.entity_id = :qid OR a.ip LIKE :q2 OR a.changes LIKE :q3)';
            $p['q'] = $p['q2'] = $p['q3'] = '%' . $f['q'] . '%';
            $p['qid'] = $f['q'];
        }
        return [$where, $p, $f];
    }

    public function index(): Response
    {
        $this->authorize('audit.view');
        $tid = $this->tenantId();
        [$where, $p, $f] = $this->filters();
        $page = $this->paginate($where, $p, 'a.*, u.name AS user_name, u.email AS user_email', 'a.id DESC', 50);
        $users = $this->db->all(
            'SELECT DISTINCT u.id, u.name FROM audit_logs a JOIN users u ON u.id = a.user_id WHERE a.tenant_id = ? ORDER BY u.name',
            [$tid],
        );
        $areaCounts = [];
        foreach ($this->db->all("SELECT SUBSTRING_INDEX(action, '.', 1) AS area, COUNT(*) AS n FROM audit_logs WHERE tenant_id = ? AND created_at >= ? GROUP BY area", [$tid, gmdate('Y-m-d H:i:s', time() - 7 * 86400)]) as $r) {
            $areaCounts[$r['area']] = (int) $r['n'];
        }
        return $this->view('audit/index', [
            'title' => 'Audit log', 'nav' => 'audit', 'page' => $page, 'f' => $f, 'users' => $users, 'areaCounts' => $areaCounts,
        ]);
    }

    public function export(): Response
    {
        $this->authorize('audit.export');
        [$where, $p] = $this->filters();
        $tz = $this->tenant()['timezone'];
        $rows = $this->db->all("SELECT a.*, u.name AS user_name, u.email AS user_email $where ORDER BY a.id DESC LIMIT 20000", $p);
        $out = array_map(fn ($a) => [
            fmt_dt($a['created_at'], $tz, 'Y-m-d H:i:s'), $a['user_name'] ?? 'Σύστημα', $a['user_email'] ?? '', $a['ip'],
            Audit::ACTIONS[$a['action']] ?? $a['action'], $a['action'], $a['entity_type'], $a['entity_id'], $a['summary'], $a['changes'],
        ], $rows);
        Audit::log('audit.exported', 'audit', null, count($rows) . ' εγγραφές');
        return Response::csv('audit-' . date('Ymd-His') . '.csv', ['Ώρα', 'Χρήστης', 'Email', 'IP', 'Ενέργεια', 'Κωδικός ενέργειας', 'Τύπος', 'ID', 'Στοιχείο', 'Αλλαγές (JSON)'], $out);
    }
}
