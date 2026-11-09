<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\Session;
use App\Services\Audit;

final class AuthController extends Controller
{
    public function loginForm(): Response
    {
        if (Auth::check()) {
            return $this->redirect('/');
        }
        return $this->view('auth/login', ['title' => 'Σύνδεση'], 'auth_layout');
    }

    public function login(): Response
    {
        $email = (string) $this->req->input('email', '');
        $error = Auth::attempt($email, (string) ($_POST['password'] ?? ''), $this->req->ip());
        if ($error !== null) {
            Audit::log('auth.login_failed', 'user', null, mb_substr($email, 0, 190), tenantId: null);
            $this->keepOld();
            $this->flash('error', $error);
            return $this->redirect('login');
        }
        Audit::log('auth.login', 'user', Auth::id(), null, tenantId: Auth::tenantId());
        $to = (string) Session::get('_intended', '/');
        Session::forget('_intended');
        if (!str_starts_with($to, '/') || str_starts_with($to, '//')) {
            $to = '/';
        }
        return $this->redirect(url($to));
    }

    public function logout(): Response
    {
        if (Auth::check()) {
            Audit::log('auth.logout', 'user', Auth::id());
        }
        Auth::logout();
        return $this->redirect('login');
    }

    public function switchTenant(): Response
    {
        $this->user();
        $id = (int) $this->req->input('tenant_id', 0);
        if (!Auth::switchTenant($id)) {
            throw HttpException::forbidden();
        }
        return $this->redirect('/');
    }

    private function findInvite(string $token): array
    {
        $inv = $this->db->one(
            'SELECT i.*, t.name AS tenant_name, t.brand_color, t.status AS tenant_status, r.name AS role_name
             FROM invitations i JOIN tenants t ON t.id = i.tenant_id JOIN roles r ON r.id = i.role_id
             WHERE i.token_hash = ?',
            [hash('sha256', $token)],
        );
        if (!$inv || $inv['accepted_at'] !== null || strtotime($inv['expires_at'] . ' UTC') < time() || $inv['tenant_status'] !== 'active') {
            throw new HttpException(410, 'Η πρόσκληση δεν είναι έγκυρη ή έχει λήξει. Ζήτησε νέα πρόσκληση από τον διαχειριστή.');
        }
        return $inv;
    }

    public function inviteForm(string $token): Response
    {
        $inv = $this->findInvite($token);
        $existing = $this->db->one('SELECT id, name FROM users WHERE email = ?', [$inv['email']]);
        return $this->view('auth/invite', ['title' => 'Πρόσκληση', 'inv' => $inv, 'token' => $token, 'existing' => $existing], 'auth_layout');
    }

    public function acceptInvite(string $token): Response
    {
        $inv = $this->findInvite($token);
        $existing = $this->db->one('SELECT * FROM users WHERE email = ?', [$inv['email']]);
        $password = (string) ($_POST['password'] ?? '');

        if ($existing) {
            if (!password_verify($password, $existing['password_hash'])) {
                $this->flash('error', 'Λάθος κωδικός για τον υπάρχοντα λογαριασμό.');
                return $this->redirect('invite/' . $token);
            }
            $userId = (int) $existing['id'];
        } else {
            $name = trim((string) $this->req->input('name', ''));
            if ($name === '' || mb_strlen($password) < 10 || $password !== ($_POST['password_confirm'] ?? '')) {
                $this->keepOld();
                $this->flash('error', 'Συμπλήρωσε όνομα και κωδικό τουλάχιστον 10 χαρακτήρων (ίδιο και στις δύο φορές).');
                return $this->redirect('invite/' . $token);
            }
            $userId = $this->db->insert('users', [
                'email' => $inv['email'], 'name' => $name, 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
        }
        $this->db->transaction(function () use ($inv, $userId): void {
            $this->db->exec(
                'INSERT INTO memberships (tenant_id, user_id, role_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE role_id = VALUES(role_id)',
                [$inv['tenant_id'], $userId, $inv['role_id']],
            );
            $this->db->update('invitations', ['accepted_at' => $this->db->now()], 'id = :id', ['id' => $inv['id']]);
            $this->db->update('users', ['last_tenant_id' => $inv['tenant_id']], 'id = :id', ['id' => $userId]);
        });
        Auth::loginAs($userId);
        Auth::switchTenant((int) $inv['tenant_id']);
        Audit::log('user.joined', 'user', $userId, $inv['email'] . ' · ' . $inv['role_name'], tenantId: (int) $inv['tenant_id']);
        $this->flash('success', 'Καλώς ήρθες στον ' . $inv['tenant_name'] . '!');
        return $this->redirect('/');
    }
}
