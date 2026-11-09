<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Crypto;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\Audit;
use App\Services\Variables;

final class VariableController extends Controller
{
    public function index(): Response
    {
        $this->authorize('variables.view');
        $tid = $this->tenantId();
        $rows = Variables::all($tid);
        // Where is each variable used? (jobs referencing it in url/body/headers/auth are encrypted, so search url/body only)
        foreach ($rows as &$r) {
            $needle = '%' . ($r['is_secret'] ? 'secrets.' : 'vars.') . $r['name'] . '%';
            $r['used_by'] = (int) $this->db->value('SELECT COUNT(*) FROM jobs WHERE tenant_id = ? AND (url LIKE ? OR body LIKE ?)', [$tid, $needle, $needle]);
        }
        unset($r);
        $edit = null;
        if ($id = (int) $this->req->query('edit', 0)) {
            foreach ($rows as $r) {
                if ((int) $r['id'] === $id) {
                    $edit = $r;
                }
            }
        }
        return $this->view('variables/index', ['title' => 'Μεταβλητές & secrets', 'nav' => 'variables', 'rows' => $rows, 'edit' => $edit]);
    }

    public function store(): Response
    {
        $this->authorize('variables.manage');
        return $this->save(null);
    }

    public function update(int $id): Response
    {
        $this->authorize('variables.manage');
        return $this->save($id);
    }

    private function save(?int $id): Response
    {
        $tid = $this->tenantId();
        $existing = $id ? $this->db->one('SELECT * FROM tenant_variables WHERE id = ? AND tenant_id = ?', [$id, $tid]) : null;
        if ($id && !$existing) {
            throw HttpException::notFound();
        }
        $name = trim((string) $this->req->input('name', ''));
        $secret = $this->req->input('is_secret') ? 1 : 0;
        $value = $this->req->raw('value');
        $desc = mb_substr((string) $this->req->input('description', ''), 0, 255);
        if (!Variables::validName($name)) {
            $this->flash('error', 'Το όνομα πρέπει να ξεκινά με γράμμα και να έχει μόνο λατινικά, αριθμούς και _ (π.χ. ERP_TOKEN).');
            return $this->redirect('variables' . ($id ? '?edit=' . $id : ''));
        }
        if ($this->db->value('SELECT 1 FROM tenant_variables WHERE tenant_id = ? AND name = ? AND id <> ?', [$tid, $name, $id ?? 0])) {
            $this->flash('error', "Υπάρχει ήδη μεταβλητή με όνομα $name.");
            return $this->redirect('variables' . ($id ? '?edit=' . $id : ''));
        }
        if ($existing && $value === '' && $existing['is_secret']) {
            $valueEnc = $existing['value_enc']; // secret left blank: keep it
        } else {
            $valueEnc = Crypto::encrypt($value);
        }
        $data = ['name' => $name, 'value_enc' => $valueEnc, 'is_secret' => $secret, 'description' => $desc ?: null];
        if ($existing) {
            $this->db->update('tenant_variables', $data, 'id = :id', ['id' => $id]);
            Audit::log('variable.updated', 'variable', $id, $name, ['name' => $existing['name'], 'is_secret' => (int) $existing['is_secret']], ['name' => $name, 'is_secret' => $secret]);
        } else {
            $id = $this->db->insert('tenant_variables', $data + ['tenant_id' => $tid]);
            Audit::log('variable.created', 'variable', $id, $name . ($secret ? ' (secret)' : ''));
        }
        Variables::forget($tid);
        $this->flash('success', 'Η μεταβλητή αποθηκεύτηκε. Χρησιμοποίησέ τη ως {{ ' . ($secret ? 'secrets.' : 'vars.') . $name . ' }}');
        return $this->redirect('variables');
    }

    public function delete(int $id): Response
    {
        $this->authorize('variables.manage');
        $tid = $this->tenantId();
        $row = $this->db->one('SELECT * FROM tenant_variables WHERE id = ? AND tenant_id = ?', [$id, $tid]) ?? throw HttpException::notFound();
        $this->db->exec('DELETE FROM tenant_variables WHERE id = ?', [$id]);
        Variables::forget($tid);
        Audit::log('variable.deleted', 'variable', $id, $row['name']);
        $this->flash('success', 'Η μεταβλητή διαγράφηκε.');
        return $this->redirect('variables');
    }
}
