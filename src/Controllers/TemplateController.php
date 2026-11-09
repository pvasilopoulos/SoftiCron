<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Response;
use App\Services\Audit;
use App\Services\Channels\TelegramSender;
use App\Services\Notifier;
use App\Services\TemplateRenderer;

final class TemplateController extends Controller
{
    public function index(): Response
    {
        $this->authorize('templates.manage');
        Notifier::seedTemplates($this->tenantId()); // adds templates for newly introduced channels
        $f = [
            'event' => (string) $this->req->query('event', ''),
            'channel' => (string) $this->req->query('channel', ''),
            'q' => (string) $this->req->query('q', ''),
        ];
        $sql = 'SELECT t.*, (SELECT COUNT(*) FROM job_notifications n WHERE n.template_id = t.id) AS used_by FROM message_templates t WHERE t.tenant_id = :tid';
        $p = ['tid' => $this->tenantId()];
        if (isset(Notifier::EVENTS[$f['event']])) {
            $sql .= ' AND t.event = :ev';
            $p['ev'] = $f['event'];
        } else {
            $f['event'] = '';
        }
        if (isset(Notifier::TEMPLATE_CHANNELS[$f['channel']])) {
            $sql .= ' AND t.channel = :ch';
            $p['ch'] = $f['channel'];
        } else {
            $f['channel'] = '';
        }
        if ($f['q'] !== '') {
            $sql .= ' AND (t.name LIKE :q OR t.subject LIKE :q2 OR t.body LIKE :q3)';
            $p['q'] = $p['q2'] = $p['q3'] = '%' . $f['q'] . '%';
        }
        $order = "FIELD(t.event, '" . implode("','", array_keys(Notifier::EVENTS)) . "'), FIELD(t.channel, '" . implode("','", array_keys(Notifier::TEMPLATE_CHANNELS)) . "'), t.is_default DESC, t.name";
        $rows = $this->db->all("$sql ORDER BY $order", $p);
        $grouped = [];
        foreach ($rows as $r) {
            $grouped[$r['event']][] = $r;
        }
        $total = (int) $this->db->value('SELECT COUNT(*) FROM message_templates WHERE tenant_id = ?', [$this->tenantId()]);
        return $this->view('templates/index', ['title' => 'Templates μηνυμάτων', 'nav' => 'templates', 'grouped' => $grouped, 'f' => $f, 'total' => $total, 'count' => count($rows)]);
    }

    public function duplicate(int $id): Response
    {
        $this->authorize('templates.manage');
        $tpl = $this->find($id);
        $newId = $this->db->insert('message_templates', [
            'tenant_id' => $this->tenantId(), 'name' => mb_substr($tpl['name'] . ' (αντίγραφο)', 0, 120), 'event' => $tpl['event'],
            'channel' => $tpl['channel'], 'subject' => $tpl['subject'], 'body' => $tpl['body'], 'is_default' => 0,
        ]);
        Audit::log('template.created', 'template', $newId, $tpl['name'] . ' (αντίγραφο)');
        $this->flash('success', 'Δημιουργήθηκε αντίγραφο.');
        return $this->redirect("templates/$newId/edit");
    }

    public function makeDefault(int $id): Response
    {
        $this->authorize('templates.manage');
        $tpl = $this->find($id);
        $this->db->exec('UPDATE message_templates SET is_default = (id = ?) WHERE tenant_id = ? AND event = ? AND channel = ?', [$id, $this->tenantId(), $tpl['event'], $tpl['channel']]);
        Audit::log('template.updated', 'template', $id, $tpl['name'] . ' → προεπιλογή');
        $this->flash('success', '«' . $tpl['name'] . '» είναι πλέον η προεπιλογή για ' . (Notifier::EVENTS[$tpl['event']] ?? '') . ' · ' . (Notifier::TEMPLATE_CHANNELS[$tpl['channel']] ?? '') . '.');
        return $this->back('templates');
    }

    /** Restores the built-in subject/body for this event + channel. */
    public function reset(int $id): Response
    {
        $this->authorize('templates.manage');
        $tpl = $this->find($id);
        [$subject, $body] = Notifier::DEFAULTS[$tpl['event']][$tpl['channel']] ?? [null, null];
        if ($body === null) {
            $this->flash('error', 'Δεν υπάρχει ενσωματωμένο κείμενο για αυτό το κανάλι.');
            return $this->back('templates');
        }
        $this->db->update('message_templates', ['subject' => $subject !== '' ? $subject : null, 'body' => $body], 'id = :id', ['id' => $id]);
        Audit::log('template.updated', 'template', $id, $tpl['name'] . ' → επαναφορά αρχικού κειμένου', ['subject' => $tpl['subject'], 'body' => $tpl['body']], ['subject' => $subject, 'body' => $body]);
        $this->flash('success', 'Το template επανήλθε στο αρχικό κείμενο.');
        return $this->back('templates');
    }

    /** Sends the template, rendered with sample data, to the current user. */
    public function test(int $id): Response
    {
        $this->authorize('templates.manage');
        $tpl = $this->find($id);
        $tenant = $this->tenant();
        try {
            $target = Notifier::sendTest($tpl, $tenant, \App\Auth\Auth::user());
            $ok = true;
            $msg = 'Η δοκιμαστική αποστολή έγινε: ' . $target;
        } catch (\Throwable $e) {
            $ok = false;
            $msg = 'Αποτυχία δοκιμαστικής αποστολής: ' . $e->getMessage();
        }
        if ($this->req->wantsJson()) {
            return $this->json(['ok' => $ok, 'message' => $msg], $ok ? 200 : 422);
        }
        $this->flash($ok ? 'success' : 'error', $msg);
        return $this->back('templates');
    }

    public function create(): Response
    {
        $this->authorize('templates.manage');
        $event = (string) $this->req->query('event', 'failed');
        $channel = (string) $this->req->query('channel', 'email');
        if (!isset(Notifier::EVENTS[$event])) {
            $event = 'failed';
        }
        if (!isset(Notifier::TEMPLATE_CHANNELS[$channel])) {
            $channel = 'email';
        }
        [$subject, $body] = Notifier::DEFAULTS[$event][$channel];
        return $this->view('templates/form', [
            'title' => 'Νέο template', 'nav' => 'templates',
            'tpl' => ['id' => null, 'name' => '', 'event' => $event, 'channel' => $channel, 'subject' => $subject, 'body' => $body, 'is_default' => 0],
        ]);
    }

    private function find(int $id): array
    {
        $t = $this->db->one('SELECT * FROM message_templates WHERE id = ? AND tenant_id = ?', [$id, $this->tenantId()]);
        if (!$t) {
            throw HttpException::notFound('Το template δεν βρέθηκε.');
        }
        return $t;
    }

    public function edit(int $id): Response
    {
        $this->authorize('templates.manage');
        $tpl = $this->find($id);
        return $this->view('templates/form', ['title' => $tpl['name'], 'nav' => 'templates', 'tpl' => $tpl]);
    }

    /** @return array{0: array, 1: string[]} */
    private function collect(): array
    {
        $errors = [];
        $event = (string) $this->req->input('event', '');
        $channel = (string) $this->req->input('channel', '');
        if (!isset(Notifier::EVENTS[$event])) {
            $errors[] = 'Άγνωστο γεγονός.';
        }
        if (!isset(Notifier::TEMPLATE_CHANNELS[$channel])) {
            $errors[] = 'Άγνωστο κανάλι.';
        }
        $name = mb_substr((string) $this->req->input('name', ''), 0, 120);
        if ($name === '') {
            $errors[] = 'Το όνομα είναι υποχρεωτικό.';
        }
        $subject = mb_substr($this->req->raw('subject'), 0, 255);
        $body = $this->req->raw('body');
        if (trim($body) === '') {
            $errors[] = 'Το κείμενο είναι υποχρεωτικό.';
        }
        foreach ([$subject, $body] as $src) {
            if ($err = TemplateRenderer::validate($src)) {
                $errors[] = 'Σφάλμα στο template: ' . $err;
            }
        }
        return [[
            'name' => $name, 'event' => $event, 'channel' => $channel,
            'subject' => in_array($channel, ['telegram', 'sms'], true) ? null : ($subject !== '' ? $subject : null),
            'body' => $body, 'is_default' => $this->req->input('is_default') ? 1 : 0,
        ], $errors];
    }

    private function clearDefaults(array $data, int $id): void
    {
        if ($data['is_default']) {
            $this->db->exec('UPDATE message_templates SET is_default = 0 WHERE tenant_id = ? AND event = ? AND channel = ? AND id <> ?', [$this->tenantId(), $data['event'], $data['channel'], $id]);
        }
    }

    public function store(): Response
    {
        $this->authorize('templates.manage');
        [$data, $errors] = $this->collect();
        if ($errors) {
            $this->keepOld();
            foreach ($errors as $e) {
                $this->flash('error', $e);
            }
            return $this->back('templates/new');
        }
        $id = $this->db->insert('message_templates', $data + ['tenant_id' => $this->tenantId()]);
        $this->clearDefaults($data, $id);
        Audit::log('template.created', 'template', $id, $data['name']);
        $this->flash('success', 'Το template αποθηκεύτηκε.');
        return $this->redirect("templates/$id/edit");
    }

    public function update(int $id): Response
    {
        $this->authorize('templates.manage');
        $tpl = $this->find($id);
        [$data, $errors] = $this->collect();
        if ($errors) {
            foreach ($errors as $e) {
                $this->flash('error', $e);
            }
            return $this->redirect("templates/$id/edit");
        }
        $this->db->update('message_templates', $data, 'id = :id', ['id' => $id]);
        $this->clearDefaults($data, $id);
        Audit::log('template.updated', 'template', $id, $data['name'], array_intersect_key($tpl, $data), $data);
        $this->flash('success', 'Οι αλλαγές αποθηκεύτηκαν.');
        return $this->redirect("templates/$id/edit");
    }

    public function delete(int $id): Response
    {
        $this->authorize('templates.manage');
        $tpl = $this->find($id);
        $this->db->exec('UPDATE job_notifications SET template_id = NULL WHERE template_id = ?', [$id]);
        $this->db->exec('DELETE FROM message_templates WHERE id = ?', [$id]);
        Audit::log('template.deleted', 'template', $id, $tpl['name']);
        $this->flash('success', 'Το template διαγράφηκε.');
        return $this->redirect('templates');
    }

    public function preview(): Response
    {
        $this->authorize('templates.manage');
        $ctx = TemplateRenderer::sampleContext();
        $ctx['tenant']['name'] = $this->tenant()['name'];
        $channel = (string) $this->req->input('channel', 'email');
        try {
            $subject = TemplateRenderer::render($this->req->raw('subject'), $ctx);
            $body = TemplateRenderer::render($this->req->raw('body'), $ctx);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()]);
        }
        $html = match ($channel) {
            'email' => Notifier::emailHtml($this->tenant(), $subject, $body, $ctx['run']['link'], (string) $this->req->input('event', 'failed')),
            'telegram' => nl2br(TelegramSender::format($body)),
            default => '',
        };
        return $this->json(['ok' => true, 'subject' => $subject, 'body' => $body, 'html' => $html]);
    }
}
