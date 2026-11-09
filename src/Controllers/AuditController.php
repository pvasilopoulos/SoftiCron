<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;

final class AuditController extends Controller
{
    public function index(): Response
    {
        $this->authorize('audit.view');
        $tid = $this->tenantId();
        $where = 'FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.tenant_id = :tid';
        $params = ['tid' => $tid];
        $action = (string) $this->req->query('action', '');
        $user = (int) $this->req->query('user', 0);
        $q = (string) $this->req->query('q', '');
        if ($action !== '') {
            $where .= ' AND a.action LIKE :action';
            $params['action'] = $action . '%';
        }
        if ($user) {
            $where .= ' AND a.user_id = :uid';
            $params['uid'] = $user;
        }
        if ($q !== '') {
            $where .= ' AND (a.summary LIKE :q OR a.entity_id = :qid)';
            $params['q'] = '%' . $q . '%';
            $params['qid'] = $q;
        }
        $page = $this->paginate($where, $params, 'a.*, u.name AS user_name, u.email AS user_email', 'a.id DESC', 40);
        $users = $this->db->all('SELECT u.id, u.name FROM memberships m JOIN users u ON u.id = m.user_id WHERE m.tenant_id = ? ORDER BY u.name', [$tid]);
        return $this->view('audit/index', [
            'title' => 'Audit log', 'nav' => 'audit', 'page' => $page, 'action' => $action, 'user' => $user, 'q' => $q, 'users' => $users,
        ]);
    }
}
