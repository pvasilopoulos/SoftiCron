<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Permissions;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\Audit;
use App\Services\Roles;

final class RoleController extends Controller
{
    public function index(): Response
    {
        $this->authorize('roles.manage');
        $tid = $this->tenantId();
        $roles = Roles::visible($tid);
        foreach ($roles as &$r) {
            $r['members'] = (int) $this->db->value('SELECT COUNT(*) FROM memberships WHERE role_id = ? AND tenant_id = ?', [$r['id'], $tid]);
        }
        unset($r);
        $perms = [];
        foreach ($this->db->all('SELECT role_id, permission FROM role_permissions') as $p) {
            $perms[$p['role_id']][] = $p['permission'];
        }
        return $this->view('roles/index', ['title' => 'Ρόλοι & δικαιώματα', 'nav' => 'roles', 'roles' => $roles, 'perms' => $perms]);
    }

    /** Saves the whole permission matrix at once. */
    public function matrix(): Response
    {
        $this->authorize('roles.manage');
        $tid = $this->tenantId();
        $posted = (array) ($_POST['perms'] ?? []);
        $changed = 0;
        foreach (Roles::visible($tid) as $role) {
            $rid = (int) $role['id'];
            if ($rid === Roles::OWNER) {
                continue;
            }
            $new = Permissions::valid((array) ($posted[$rid] ?? []));
            $old = $this->db->column('SELECT permission FROM role_permissions WHERE role_id = ?', [$rid]);
            sort($new);
            sort($old);
            if ($new === $old) {
                continue;
            }
            $editId = Roles::editable($tid, $rid);
            if (!$editId) {
                continue;
            }
            $this->db->transaction(function () use ($editId, $new): void {
                $this->db->exec('DELETE FROM role_permissions WHERE role_id = ?', [$editId]);
                foreach ($new as $p) {
                    $this->db->insert('role_permissions', ['role_id' => $editId, 'permission' => $p]);
                }
            });
            Audit::log('role.updated', 'role', $editId, $role['name'], ['permissions' => $old], ['permissions' => $new]);
            $changed++;
        }
        $this->flash('success', $changed ? 'Τα δικαιώματα αποθηκεύτηκαν.' : 'Δεν υπήρχαν αλλαγές.');
        return $this->redirect('roles');
    }

    public function create(): Response
    {
        $this->authorize('roles.manage');
        return $this->view('roles/form', ['title' => 'Νέος ρόλος', 'nav' => 'roles', 'role' => ['id' => null, 'name' => '', 'description' => '', 'tenant_id' => null, 'based_on' => null], 'granted' => ['jobs.view', 'runs.view'], 'members' => 0]);
    }

    private function findVisible(int $id): array
    {
        $tid = $this->tenantId();
        if (!Roles::isVisible($tid, $id)) {
            throw HttpException::notFound('Ο ρόλος δεν βρέθηκε.');
        }
        return $this->db->one('SELECT * FROM roles WHERE id = ?', [$id]);
    }

    public function edit(int $id): Response
    {
        $this->authorize('roles.manage');
        if ($id === Roles::OWNER) {
            $this->flash('info', 'Ο ρόλος Owner έχει πάντα όλα τα δικαιώματα και δεν αλλάζει.');
            return $this->redirect('roles');
        }
        $role = $this->findVisible($id);
        $granted = $this->db->column('SELECT permission FROM role_permissions WHERE role_id = ?', [$id]);
        $members = (int) $this->db->value('SELECT COUNT(*) FROM memberships WHERE role_id = ? AND tenant_id = ?', [$id, $this->tenantId()]);
        return $this->view('roles/form', ['title' => $role['name'], 'nav' => 'roles', 'role' => $role, 'granted' => $granted, 'members' => $members]);
    }

    private function save(?int $id): Response
    {
        $tid = $this->tenantId();
        $name = mb_substr((string) $this->req->input('name', ''), 0, 80);
        $desc = mb_substr((string) $this->req->input('description', ''), 0, 255);
        $perms = Permissions::valid((array) ($_POST['perms'] ?? []));
        if ($name === '') {
            $this->flash('error', 'Το όνομα είναι υποχρεωτικό.');
            return $this->back('roles');
        }
        $before = null;
        if ($id) {
            $role = $this->findVisible($id);
            $before = ['name' => $role['name'], 'description' => $role['description'], 'permissions' => $this->db->column('SELECT permission FROM role_permissions WHERE role_id = ?', [$id])];
            $id = Roles::editable($tid, $id);
            if (!$id) {
                throw HttpException::forbidden('Αυτός ο ρόλος δεν αλλάζει.');
            }
        }
        $id = $this->db->transaction(function () use ($id, $name, $desc, $perms, $tid): int {
            if ($id) {
                $this->db->update('roles', ['name' => $name, 'description' => $desc], 'id = :id', ['id' => $id]);
                $this->db->exec('DELETE FROM role_permissions WHERE role_id = ?', [$id]);
            } else {
                $id = $this->db->insert('roles', ['tenant_id' => $tid, 'name' => $name, 'description' => $desc]);
            }
            foreach ($perms as $p) {
                $this->db->insert('role_permissions', ['role_id' => $id, 'permission' => $p]);
            }
            return $id;
        });
        Audit::log($before ? 'role.updated' : 'role.created', 'role', $id, $name, $before, ['name' => $name, 'description' => $desc, 'permissions' => $perms]);
        $this->flash('success', 'Ο ρόλος αποθηκεύτηκε.');
        return $this->redirect('roles');
    }

    public function store(): Response
    {
        $this->authorize('roles.manage');
        return $this->save(null);
    }

    public function update(int $id): Response
    {
        $this->authorize('roles.manage');
        return $this->save($id);
    }

    public function delete(int $id): Response
    {
        $this->authorize('roles.manage');
        $tid = $this->tenantId();
        $role = $this->db->one('SELECT * FROM roles WHERE id = ? AND tenant_id = ?', [$id, $tid]);
        if (!$role) {
            throw HttpException::notFound('Οι ενσωματωμένοι ρόλοι δεν διαγράφονται.');
        }
        if ($role['based_on']) {
            // Reset a customised built-in role back to the default.
            $this->db->transaction(function () use ($role, $tid): void {
                $this->db->exec('UPDATE memberships SET role_id = ? WHERE tenant_id = ? AND role_id = ?', [$role['based_on'], $tid, $role['id']]);
                $this->db->exec('UPDATE invitations SET role_id = ? WHERE tenant_id = ? AND role_id = ?', [$role['based_on'], $tid, $role['id']]);
                $this->db->exec('DELETE FROM roles WHERE id = ?', [$role['id']]);
            });
            Audit::log('role.updated', 'role', $id, $role['name'] . ' → επαναφορά προεπιλογών');
            $this->flash('success', 'Ο ρόλος επανήλθε στις προεπιλογές.');
            return $this->redirect('roles');
        }
        if ($this->db->value('SELECT 1 FROM memberships WHERE role_id = ? LIMIT 1', [$id])) {
            $this->flash('error', 'Ο ρόλος χρησιμοποιείται από χρήστες. Άλλαξε πρώτα τον ρόλο τους.');
            return $this->redirect('roles');
        }
        $this->db->exec('DELETE FROM roles WHERE id = ?', [$id]);
        Audit::log('role.deleted', 'role', $id, $role['name']);
        $this->flash('success', 'Ο ρόλος διαγράφηκε.');
        return $this->redirect('roles');
    }
}
