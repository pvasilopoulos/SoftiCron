<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Response;
use App\Services\Audit;

final class ProfileController extends Controller
{
    public function index(): Response
    {
        $user = $this->user();
        return $this->view('profile/index', ['title' => 'Το προφίλ μου', 'nav' => 'profile', 'u' => $user, 'memberships' => Auth::tenants()], Auth::tenant() ? 'layout' : 'auth_layout');
    }

    public function update(): Response
    {
        $user = $this->user();
        $name = mb_substr((string) $this->req->input('name', ''), 0, 150);
        $theme = (string) $this->req->input('theme', 'system');
        if ($name === '' || !in_array($theme, ['system', 'light', 'dark'], true)) {
            $this->flash('error', 'Συμπλήρωσε όνομα.');
            return $this->redirect('profile');
        }
        $this->db->update('users', ['name' => $name, 'theme' => $theme], 'id = :id', ['id' => $user['id']]);
        Audit::log('profile.updated', 'user', $user['id'], null, ['name' => $user['name'], 'theme' => $user['theme']], ['name' => $name, 'theme' => $theme]);
        if ($this->req->wantsJson()) {
            return $this->json(['ok' => true]);
        }
        $this->flash('success', 'Το προφίλ ενημερώθηκε.');
        return $this->redirect('profile');
    }

    public function password(): Response
    {
        $user = $this->user();
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        if (!password_verify($current, $user['password_hash'])) {
            $this->flash('error', 'Ο τρέχων κωδικός είναι λάθος.');
            return $this->redirect('profile');
        }
        if (mb_strlen($new) < 10 || $new !== ($_POST['new_password_confirm'] ?? '')) {
            $this->flash('error', 'Ο νέος κωδικός πρέπει να έχει τουλάχιστον 10 χαρακτήρες και να ταιριάζει με την επιβεβαίωση.');
            return $this->redirect('profile');
        }
        $this->db->update('users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], 'id = :id', ['id' => $user['id']]);
        session_regenerate_id(true);
        Audit::log('profile.password', 'user', $user['id']);
        $this->flash('success', 'Ο κωδικός άλλαξε.');
        return $this->redirect('profile');
    }
}
