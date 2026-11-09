<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\App;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\Audit;
use App\Services\Channels\EmailSender;
use App\Services\Connectors;
use App\Services\Notifier;

final class UserController extends Controller
{
    public function index(): Response
    {
        $this->authorize('users.manage');
        $tid = $this->tenantId();
        $members = $this->db->all(
            'SELECT u.id, u.name, u.email, u.last_login_at, u.status, m.role_id, r.name AS role_name, m.created_at AS joined_at
             FROM memberships m JOIN users u ON u.id = m.user_id JOIN roles r ON r.id = m.role_id
             WHERE m.tenant_id = ? ORDER BY u.name',
            [$tid],
        );
        $invites = $this->db->all(
            'SELECT i.*, r.name AS role_name FROM invitations i JOIN roles r ON r.id = i.role_id
             WHERE i.tenant_id = ? AND i.accepted_at IS NULL AND i.expires_at > UTC_TIMESTAMP() ORDER BY i.id DESC',
            [$tid],
        );
        $inviteLink = $_SESSION['_invite_link'] ?? null;
        $newPassword = $_SESSION['_new_password'] ?? null;
        unset($_SESSION['_invite_link'], $_SESSION['_new_password']);
        return $this->view('users/index', [
            'title' => 'Χρήστες', 'nav' => 'users', 'members' => $members, 'invites' => $invites,
            'roles' => $this->roles(), 'inviteLink' => $inviteLink, 'newPassword' => $newPassword,
        ]);
    }

    private function roles(): array
    {
        return \App\Services\Roles::visible($this->tenantId());
    }

    private function validRole(int $roleId): bool
    {
        if ($roleId === 1 && !$this->isOwner()) {
            return false; // only owners can create owners
        }
        return \App\Services\Roles::isVisible($this->tenantId(), $roleId);
    }

    private function isOwner(): bool
    {
        return Auth::isSuperAdmin() || (int) $this->db->value('SELECT role_id FROM memberships WHERE tenant_id = ? AND user_id = ?', [$this->tenantId(), Auth::id()]) === 1;
    }

    public function invite(): Response
    {
        $this->authorize('users.manage');
        unset($_SESSION['_invite_link']);
        $tenant = $this->tenant();
        $email = strtolower(trim((string) $this->req->input('email', '')));
        $roleId = (int) $this->req->input('role_id', 0);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash('error', 'Μη έγκυρο email.');
            return $this->redirect('users');
        }
        if (!$this->validRole($roleId)) {
            $this->flash('error', 'Μη έγκυρος ρόλος.');
            return $this->redirect('users');
        }
        $already = $this->db->value('SELECT 1 FROM memberships m JOIN users u ON u.id = m.user_id WHERE m.tenant_id = ? AND u.email = ?', [$tenant['id'], $email]);
        if ($already) {
            $this->flash('error', 'Ο χρήστης είναι ήδη μέλος.');
            return $this->redirect('users');
        }
        $token = bin2hex(random_bytes(24));
        $id = $this->db->insert('invitations', [
            'tenant_id' => $tenant['id'], 'email' => $email, 'role_id' => $roleId, 'token_hash' => hash('sha256', $token),
            'invited_by' => Auth::id(), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 7 * 86400),
        ]);
        $link = abs_url('invite/' . $token);
        $role = $this->db->value('SELECT name FROM roles WHERE id = ?', [$roleId]);
        Audit::log('user.invited', 'invitation', $id, "$email · $role");

        $subject = 'Πρόσκληση στο ' . App::name() . ' · ' . $tenant['name'];
        $text = Auth::user()['name'] . " σε προσκάλεσε στον οργανισμό «{$tenant['name']}» με ρόλο $role.\n\nΗ πρόσκληση ισχύει 7 ημέρες.";
        $html = Notifier::emailHtml($tenant, $subject, $text, $link, null);
        $sent = false;
        try {
            $conn = Connectors::default((int) $tenant['id'], 'email');
            if ($conn) {
                EmailSender::send($conn['type'], Connectors::config($conn), [$email], $subject, $html, $text . "\n\n" . $link);
                $sent = true;
            } elseif (EmailSender::platformConfigured()) {
                EmailSender::sendPlatform([$email], $subject, $html, $text . "\n\n" . $link);
                $sent = true;
            }
        } catch (\Throwable $e) {
            $this->flash('error', 'Το email δεν στάλθηκε: ' . $e->getMessage());
        }
        $_SESSION['_invite_link'] = $link;
        $this->flash('success', $sent ? "Η πρόσκληση στάλθηκε στο $email." : 'Η πρόσκληση δημιουργήθηκε. Αντέγραψε τον σύνδεσμο και στείλ’ τον στον χρήστη.');
        return $this->redirect('users');
    }

    public function changeRole(int $userId): Response
    {
        $this->authorize('users.manage');
        $tid = $this->tenantId();
        $roleId = (int) $this->req->input('role_id', 0);
        $member = $this->db->one('SELECT m.*, u.name FROM memberships m JOIN users u ON u.id = m.user_id WHERE m.tenant_id = ? AND m.user_id = ?', [$tid, $userId]);
        if (!$member || !$this->validRole($roleId)) {
            throw HttpException::notFound();
        }
        if ((int) $member['role_id'] === 1 && !$this->isOwner()) {
            throw HttpException::forbidden('Μόνο ένας Owner μπορεί να αλλάξει τον ρόλο άλλου Owner.');
        }
        if ((int) $member['role_id'] === 1 && $roleId !== 1 && $this->ownerCount() <= 1) {
            $this->flash('error', 'Πρέπει να υπάρχει τουλάχιστον ένας Owner.');
            return $this->redirect('users');
        }
        $this->db->update('memberships', ['role_id' => $roleId], 'id = :id', ['id' => $member['id']]);
        $old = $this->db->value('SELECT name FROM roles WHERE id = ?', [$member['role_id']]);
        $new = $this->db->value('SELECT name FROM roles WHERE id = ?', [$roleId]);
        Audit::log('user.role_changed', 'user', $userId, $member['name'], ['role' => $old], ['role' => $new]);
        $this->flash('success', 'Ο ρόλος άλλαξε.');
        return $this->redirect('users');
    }

    private function ownerCount(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM memberships WHERE tenant_id = ? AND role_id = 1', [$this->tenantId()]);
    }

    public function remove(int $userId): Response
    {
        $this->authorize('users.manage');
        $tid = $this->tenantId();
        $member = $this->db->one('SELECT m.*, u.name, u.email FROM memberships m JOIN users u ON u.id = m.user_id WHERE m.tenant_id = ? AND m.user_id = ?', [$tid, $userId]);
        if (!$member) {
            throw HttpException::notFound();
        }
        if ((int) $member['role_id'] === 1 && ($this->ownerCount() <= 1 || !$this->isOwner())) {
            $this->flash('error', 'Δεν μπορείς να αφαιρέσεις αυτόν τον Owner.');
            return $this->redirect('users');
        }
        if ($userId === Auth::id()) {
            $this->flash('error', 'Δεν μπορείς να διαγράψεις τον εαυτό σου.');
            return $this->redirect('users');
        }
        $this->db->exec('DELETE FROM memberships WHERE id = ?', [$member['id']]);
        // Account with no other organisation left: delete it completely.
        $orphan = !$this->db->value('SELECT 1 FROM memberships WHERE user_id = ? LIMIT 1', [$userId])
            && !$this->db->value('SELECT is_super_admin FROM users WHERE id = ?', [$userId]);
        if ($orphan) {
            $this->db->exec('DELETE FROM users WHERE id = ?', [$userId]);
        }
        Audit::log($orphan ? 'user.deleted' : 'user.removed', 'user', $userId, $member['name'] . ' <' . $member['email'] . '>');
        $this->flash('success', $orphan ? 'Ο χρήστης διαγράφηκε.' : 'Ο χρήστης αφαιρέθηκε από τον οργανισμό (παραμένει μέλος άλλων οργανισμών).');
        return $this->redirect('users');
    }

    /** Name/email/password/status may be changed only for accounts that belong to this tenant alone. */
    private function managedHere(int $userId): bool
    {
        if (Auth::isSuperAdmin()) {
            return true;
        }
        $isSuper = (bool) $this->db->value('SELECT is_super_admin FROM users WHERE id = ?', [$userId]);
        $others = (int) $this->db->value('SELECT COUNT(*) FROM memberships WHERE user_id = ? AND tenant_id <> ?', [$userId, $this->tenantId()]);
        return !$isSuper && $others === 0;
    }

    private function member(int $userId): array
    {
        $m = $this->db->one(
            'SELECT u.*, m.role_id, m.id AS membership_id FROM memberships m JOIN users u ON u.id = m.user_id WHERE m.tenant_id = ? AND m.user_id = ?',
            [$this->tenantId(), $userId],
        );
        if (!$m) {
            throw HttpException::notFound('Ο χρήστης δεν βρέθηκε.');
        }
        return $m;
    }

    public function create(): Response
    {
        $this->authorize('users.manage');
        return $this->view('users/form', [
            'title' => 'Νέος χρήστης', 'nav' => 'users', 'roles' => $this->roles(),
            'u' => ['id' => null, 'name' => '', 'email' => '', 'role_id' => 3, 'status' => 'active'], 'managed' => true,
        ]);
    }

    private static function randomPassword(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(12)), '+/', 'Kx'), '=');
    }

    public function store(): Response
    {
        $this->authorize('users.manage');
        $tid = $this->tenantId();
        $name = mb_substr((string) $this->req->input('name', ''), 0, 150);
        $email = strtolower(trim((string) $this->req->input('email', '')));
        $roleId = (int) $this->req->input('role_id', 0);
        $password = (string) ($_POST['password'] ?? '');
        $errors = [];
        if ($name === '') {
            $errors[] = 'Το όνομα είναι υποχρεωτικό.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Μη έγκυρο email.';
        }
        if (!$this->validRole($roleId)) {
            $errors[] = 'Μη έγκυρος ρόλος.';
        }
        if ($password !== '' && mb_strlen($password) < 10) {
            $errors[] = 'Ο κωδικός πρέπει να έχει τουλάχιστον 10 χαρακτήρες.';
        }
        $existing = $email ? $this->db->one('SELECT id FROM users WHERE email = ?', [$email]) : null;
        if ($existing && $this->db->value('SELECT 1 FROM memberships WHERE tenant_id = ? AND user_id = ?', [$tid, $existing['id']])) {
            $errors[] = 'Ο χρήστης είναι ήδη μέλος του οργανισμού.';
        }
        if ($errors) {
            $this->keepOld();
            foreach ($errors as $e) {
                $this->flash('error', $e);
            }
            return $this->redirect('users/new');
        }
        $generated = null;
        if ($existing) {
            $userId = (int) $existing['id'];
            $msg = 'Ο λογαριασμός υπήρχε ήδη και προστέθηκε στον οργανισμό με τον υπάρχοντα κωδικό του.';
        } else {
            if ($password === '') {
                $password = $generated = self::randomPassword();
            }
            $userId = $this->db->insert('users', ['email' => $email, 'name' => $name, 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
            $msg = 'Ο χρήστης δημιουργήθηκε.';
        }
        $this->db->insert('memberships', ['tenant_id' => $tid, 'user_id' => $userId, 'role_id' => $roleId]);
        Audit::log('user.created', 'user', $userId, "$name <$email>", null, ['name' => $name, 'email' => $email, 'role_id' => $roleId]);
        if ($generated) {
            $_SESSION['_new_password'] = ['email' => $email, 'password' => $generated];
        }
        $this->flash('success', $msg);
        return $this->redirect('users');
    }

    public function edit(int $userId): Response
    {
        $this->authorize('users.manage');
        $u = $this->member($userId);
        return $this->view('users/form', [
            'title' => $u['name'], 'nav' => 'users', 'roles' => $this->roles(), 'u' => $u, 'managed' => $this->managedHere($userId),
        ]);
    }

    public function update(int $userId): Response
    {
        $this->authorize('users.manage');
        $u = $this->member($userId);
        $managed = $this->managedHere($userId);
        $roleId = (int) $this->req->input('role_id', $u['role_id']);
        $before = ['name' => $u['name'], 'email' => $u['email'], 'status' => $u['status'], 'role_id' => (int) $u['role_id']];
        $after = $before;
        $errors = [];

        if ($roleId !== (int) $u['role_id']) {
            if (!$this->validRole($roleId) || ((int) $u['role_id'] === 1 && !$this->isOwner())) {
                $errors[] = 'Δεν μπορείς να ορίσεις αυτόν τον ρόλο.';
            } elseif ((int) $u['role_id'] === 1 && $this->ownerCount() <= 1) {
                $errors[] = 'Πρέπει να υπάρχει τουλάχιστον ένας Owner.';
            } else {
                $after['role_id'] = $roleId;
            }
        }
        $newPassword = '';
        if ($managed) {
            $name = mb_substr((string) $this->req->input('name', ''), 0, 150);
            $email = strtolower(trim((string) $this->req->input('email', '')));
            $status = $this->req->input('status') === 'disabled' ? 'disabled' : 'active';
            $newPassword = (string) ($_POST['password'] ?? '');
            if ($name === '') {
                $errors[] = 'Το όνομα είναι υποχρεωτικό.';
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Μη έγκυρο email.';
            } elseif ($this->db->value('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $userId])) {
                $errors[] = 'Το email χρησιμοποιείται από άλλον λογαριασμό.';
            }
            if ($status === 'disabled' && $userId === Auth::id()) {
                $errors[] = 'Δεν μπορείς να απενεργοποιήσεις τον εαυτό σου.';
            }
            if ($newPassword !== '' && mb_strlen($newPassword) < 10) {
                $errors[] = 'Ο νέος κωδικός πρέπει να έχει τουλάχιστον 10 χαρακτήρες.';
            }
            $after['name'] = $name;
            $after['email'] = $email;
            $after['status'] = $status;
        }
        if ($errors) {
            foreach ($errors as $e) {
                $this->flash('error', $e);
            }
            return $this->redirect("users/$userId/edit");
        }
        $this->db->transaction(function () use ($u, $after, $managed, $newPassword, $userId): void {
            $this->db->update('memberships', ['role_id' => $after['role_id']], 'id = :id', ['id' => $u['membership_id']]);
            if ($managed) {
                $data = ['name' => $after['name'], 'email' => $after['email'], 'status' => $after['status']];
                if ($newPassword !== '') {
                    $data['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
                }
                $this->db->update('users', $data, 'id = :id', ['id' => $userId]);
            }
        });
        Audit::log('user.updated', 'user', $userId, $after['name'], $before, $after + ($newPassword !== '' ? ['password' => 'changed'] : []));
        $this->flash('success', 'Ο χρήστης ενημερώθηκε.' . ($newPassword !== '' ? ' Ο κωδικός άλλαξε.' : ''));
        return $this->redirect('users');
    }

    public function revokeInvite(int $id): Response
    {
        $this->authorize('users.manage');
        $inv = $this->db->one('SELECT * FROM invitations WHERE id = ? AND tenant_id = ?', [$id, $this->tenantId()]);
        if (!$inv) {
            throw HttpException::notFound();
        }
        $this->db->exec('DELETE FROM invitations WHERE id = ?', [$id]);
        Audit::log('invite.revoked', 'invitation', $id, $inv['email']);
        $this->flash('success', 'Η πρόσκληση ακυρώθηκε.');
        return $this->redirect('users');
    }
}
