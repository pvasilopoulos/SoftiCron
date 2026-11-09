<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Crypto;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\Audit;
use App\Services\Connectors;

final class ConnectorController extends Controller
{
    public function index(): Response
    {
        $this->authorize('connectors.manage');
        $rows = $this->db->all('SELECT id, type, name, is_default, last_test_at, last_test_ok, last_test_error, updated_at FROM connectors WHERE tenant_id = ? ORDER BY type, name', [$this->tenantId()]);
        return $this->view('connectors/index', ['title' => 'Connectors', 'nav' => 'connectors', 'rows' => $rows]);
    }

    public function create(): Response
    {
        $this->authorize('connectors.manage');
        $type = (string) $this->req->query('type', 'smtp');
        if (!isset(Connectors::TYPES[$type])) {
            $type = 'smtp';
        }
        return $this->view('connectors/form', [
            'title' => 'Νέος connector', 'nav' => 'connectors',
            'conn' => ['id' => null, 'type' => $type, 'name' => '', 'is_default' => 0],
            'cfg' => $type === 'smtp' ? ['port' => 587, 'encryption' => 'tls'] : [],
        ]);
    }

    private function find(int $id): array
    {
        $c = Connectors::find($this->tenantId(), $id);
        if (!$c) {
            throw HttpException::notFound('Ο connector δεν βρέθηκε.');
        }
        return $c;
    }

    public function edit(int $id): Response
    {
        $this->authorize('connectors.manage');
        $conn = $this->find($id);
        $cfg = Connectors::config($conn);
        foreach (Connectors::FIELDS[$conn['type']] as $key => $f) {
            if ($f[3]) {
                $cfg[$key] = ''; // never send secrets back to the browser
            }
        }
        return $this->view('connectors/form', ['title' => $conn['name'], 'nav' => 'connectors', 'conn' => $conn, 'cfg' => $cfg]);
    }

    /** @return array{0: array, 1: string[]} */
    private function collect(string $type, array $oldCfg): array
    {
        $cfg = [];
        $errors = [];
        foreach (Connectors::FIELDS[$type] as $key => [$label, $input, $required, $secret]) {
            $v = trim((string) ($_POST['cfg'][$key] ?? ''));
            if ($secret && $v === '' && isset($oldCfg[$key])) {
                $v = (string) $oldCfg[$key];
            }
            if ($required && $v === '') {
                $errors[] = "Το πεδίο «{$label}» είναι υποχρεωτικό.";
            }
            if ($input === 'email' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Το πεδίο «{$label}» πρέπει να είναι email.";
            }
            $cfg[$key] = $input === 'number' ? (int) $v : $v;
        }
        return [$cfg, $errors];
    }

    public function store(): Response
    {
        $this->authorize('connectors.manage');
        $tid = $this->tenantId();
        $type = (string) $this->req->input('type', '');
        if (!isset(Connectors::TYPES[$type])) {
            throw new HttpException(422, 'Άγνωστος τύπος.');
        }
        $name = mb_substr((string) $this->req->input('name', ''), 0, 120) ?: Connectors::TYPES[$type];
        [$cfg, $errors] = $this->collect($type, []);
        if ($errors) {
            $this->keepOld();
            foreach ($errors as $e) {
                $this->flash('error', $e);
            }
            return $this->redirect('connectors/new?type=' . $type);
        }
        $default = $this->req->input('is_default') ? 1 : 0;
        $channel = $type === 'telegram' ? 'telegram' : 'email';
        if (!Connectors::default($tid, $channel)) {
            $default = 1;
        }
        $id = $this->db->insert('connectors', ['tenant_id' => $tid, 'type' => $type, 'name' => $name, 'config_enc' => Crypto::encryptJson($cfg), 'is_default' => $default]);
        if ($default) {
            $this->clearOtherDefaults($id, $type);
        }
        Audit::log('connector.created', 'connector', $id, $name . ' (' . Connectors::TYPES[$type] . ')', null, ['name' => $name] + $cfg);
        $this->flash('success', 'Ο connector αποθηκεύτηκε. Στείλε ένα δοκιμαστικό μήνυμα για να βεβαιωθείς ότι δουλεύει.');
        return $this->redirect("connectors/$id/edit");
    }

    public function update(int $id): Response
    {
        $this->authorize('connectors.manage');
        $conn = $this->find($id);
        $old = Connectors::config($conn);
        [$cfg, $errors] = $this->collect($conn['type'], $old);
        if ($errors) {
            foreach ($errors as $e) {
                $this->flash('error', $e);
            }
            return $this->redirect("connectors/$id/edit");
        }
        $name = mb_substr((string) $this->req->input('name', ''), 0, 120) ?: $conn['name'];
        $default = $this->req->input('is_default') ? 1 : (int) $conn['is_default'];
        $this->db->update('connectors', ['name' => $name, 'config_enc' => Crypto::encryptJson($cfg), 'is_default' => $default], 'id = :id', ['id' => $id]);
        if ($default) {
            $this->clearOtherDefaults($id, $conn['type']);
        }
        Audit::log('connector.updated', 'connector', $id, $name, ['name' => $conn['name']] + $old, ['name' => $name] + $cfg);
        $this->flash('success', 'Οι αλλαγές αποθηκεύτηκαν.');
        return $this->redirect("connectors/$id/edit");
    }

    private function clearOtherDefaults(int $id, string $type): void
    {
        $types = $type === 'telegram' ? "'telegram'" : "'smtp','m365'";
        $this->db->exec("UPDATE connectors SET is_default = 0 WHERE tenant_id = ? AND id <> ? AND type IN ($types)", [$this->tenantId(), $id]);
    }

    public function delete(int $id): Response
    {
        $this->authorize('connectors.manage');
        $conn = $this->find($id);
        $this->db->exec('UPDATE jobs SET email_connector_id = NULL WHERE email_connector_id = ?', [$id]);
        $this->db->exec('UPDATE jobs SET telegram_connector_id = NULL WHERE telegram_connector_id = ?', [$id]);
        $this->db->exec('DELETE FROM connectors WHERE id = ?', [$id]);
        Audit::log('connector.deleted', 'connector', $id, $conn['name']);
        $this->flash('success', 'Ο connector διαγράφηκε.');
        return $this->redirect('connectors');
    }

    public function test(int $id): Response
    {
        $this->authorize('connectors.manage');
        $conn = $this->find($id);
        $target = trim((string) $this->req->input('target', ''));
        try {
            Connectors::test($conn, $this->tenant(), $target);
            $this->db->update('connectors', ['last_test_at' => $this->db->now(), 'last_test_ok' => 1, 'last_test_error' => null], 'id = :id', ['id' => $id]);
            $msg = 'Το δοκιμαστικό μήνυμα στάλθηκε.';
            $ok = true;
        } catch (\Throwable $e) {
            $this->db->update('connectors', ['last_test_at' => $this->db->now(), 'last_test_ok' => 0, 'last_test_error' => mb_substr($e->getMessage(), 0, 500)], 'id = :id', ['id' => $id]);
            $msg = $e->getMessage();
            $ok = false;
        }
        if ($this->req->wantsJson()) {
            return $this->json(['ok' => $ok, 'message' => $msg], $ok ? 200 : 422);
        }
        $this->flash($ok ? 'success' : 'error', $msg);
        return $this->redirect("connectors/$id/edit");
    }
}
