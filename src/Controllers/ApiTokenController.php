<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\Session;
use App\Services\ApiTokens;
use App\Services\Audit;

final class ApiTokenController extends Controller
{
    public function index(): Response
    {
        $this->authorize('api.manage');
        $tid = $this->tenantId();
        $tokens = $this->db->all(
            'SELECT t.*, u.name AS user_name FROM api_tokens t LEFT JOIN users u ON u.id = t.user_id
             WHERE t.tenant_id = ? ORDER BY t.revoked_at IS NOT NULL, t.id DESC',
            [$tid],
        );
        $newToken = Session::get('_new_api_token');
        Session::set('_new_api_token', null);
        return $this->view('api/tokens', [
            'title' => 'API tokens', 'nav' => 'api', 'tokens' => $tokens, 'newToken' => is_string($newToken) ? $newToken : null,
        ]);
    }

    public function store(): Response
    {
        $this->authorize('api.manage');
        $tid = $this->tenantId();
        $name = trim((string) $this->req->input('name', ''));
        $scopes = ApiTokens::cleanScopes((array) ($_POST['scopes'] ?? []));
        if ($name === '') {
            $this->flash('error', 'Δώσε ένα όνομα στο token (π.χ. «ERP integration»).');
            return $this->redirect('api-tokens');
        }
        if (!$scopes) {
            $this->flash('error', 'Διάλεξε τουλάχιστον ένα δικαίωμα (scope).');
            return $this->redirect('api-tokens');
        }
        $days = (int) $this->req->input('expires_days', 0);
        $expires = $days > 0 ? gmdate('Y-m-d H:i:s', time() + min($days, 3650) * 86400) : null;
        [$plain, $id] = ApiTokens::create($tid, (int) Auth::id(), $name, $scopes, $expires);
        Audit::log('api.token_created', 'api_token', $id, $name, null, ['scopes' => implode(',', $scopes), 'expires_at' => $expires ?? 'ποτέ']);
        Session::set('_new_api_token', $plain);
        $this->flash('success', 'Το token δημιουργήθηκε. Αντέγραψέ το τώρα, δεν θα εμφανιστεί ξανά.');
        return $this->redirect('api-tokens');
    }

    public function revoke(int $id): Response
    {
        $this->authorize('api.manage');
        $tok = $this->db->one('SELECT * FROM api_tokens WHERE id = ? AND tenant_id = ?', [$id, $this->tenantId()]);
        if (!$tok) {
            throw HttpException::notFound();
        }
        if (!$tok['revoked_at']) {
            $this->db->update('api_tokens', ['revoked_at' => $this->db->now()], 'id = :id', ['id' => $id]);
            Audit::log('api.token_revoked', 'api_token', $id, $tok['name'] . ' (' . $tok['prefix'] . '…)');
        }
        $this->flash('success', 'Το token ανακλήθηκε. Όποια εφαρμογή το χρησιμοποιεί θα παίρνει 401.');
        return $this->redirect('api-tokens');
    }

    public function docs(): Response
    {
        $this->tenant();
        if (!Auth::canAny(['api.manage', 'jobs.view'])) {
            throw HttpException::forbidden();
        }
        return $this->view('api/docs', ['title' => 'Τεκμηρίωση API', 'nav' => 'api', 'base' => abs_url('api/v1')]);
    }
}
