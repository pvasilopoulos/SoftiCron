<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\Incidents;

/** "Το είδα": acknowledging a failing job, from inside the app or from a link in a notification. */
final class IncidentController extends Controller
{
    /** Job of a signed ack link; the token is only valid for the current incident. */
    private function fromLink(int $id, string $token): ?array
    {
        $job = $this->db->one('SELECT * FROM jobs WHERE id = ?', [$id]);
        if (!$job) {
            throw HttpException::notFound('Ο σύνδεσμος δεν είναι έγκυρος.');
        }
        if (!$job['failing_since']) {
            return $job; // already recovered: the page says so
        }
        if (!hash_equals(Incidents::token($id, (string) $job['failing_since']), $token)) {
            // Token of an older incident of the same job.
            $job['failing_since'] = null;
        }
        return $job;
    }

    public function linkForm(int $id, string $token): Response
    {
        $job = $this->fromLink($id, $token);
        $tenant = $this->db->one('SELECT name, timezone FROM tenants WHERE id = ?', [$job['tenant_id']]);
        $lastRun = $job['failing_since'] ? $this->db->one("SELECT id, error, http_status, COALESCE(started_at, scheduled_at) AS t FROM job_runs WHERE job_id = ? AND status = 'failed' ORDER BY id DESC LIMIT 1", [$id]) : null;
        $fails = $job['failing_since'] ? (int) $this->db->value("SELECT COUNT(*) FROM job_runs WHERE job_id = ? AND status = 'failed' AND COALESCE(started_at, scheduled_at) >= ?", [$id, $job['failing_since']]) : 0;
        $user = Auth::check() ? Auth::user() : null;
        return $this->view('incidents/ack', [
            'title' => 'Ανάληψη συμβάντος', 'job' => $job, 'tenant' => $tenant, 'token' => $token, 'lastRun' => $lastRun, 'fails' => $fails,
            'user' => $user, 'canOpen' => $user && Auth::tenantId() === (int) $job['tenant_id'],
        ], 'auth_layout');
    }

    public function linkAck(int $id, string $token): Response
    {
        $job = $this->fromLink($id, $token);
        if (!$job['failing_since']) {
            $this->flash('error', 'Το συμβάν έχει ήδη λήξει.');
            return $this->redirect("ack/$id/$token");
        }
        $user = Auth::check() ? Auth::user() : null;
        $name = $user ? (string) $user['name'] : trim((string) $this->req->input('name', ''));
        if ($name === '') {
            $this->keepOld();
            $this->flash('error', 'Γράψε το όνομά σου, για να ξέρει η ομάδα ποιος ανέλαβε.');
            return $this->redirect("ack/$id/$token");
        }
        $done = Incidents::ack($job, $user ? (int) $user['id'] : null, $name, (string) $this->req->input('note', ''), 'link');
        $this->flash($done ? 'success' : 'error', $done ? 'Ευχαριστούμε! Η ομάδα ενημερώθηκε ότι το ανέλαβες.' : 'Το συμβάν το έχει ήδη αναλάβει ο/η ' . ($job['acked_name'] ?: 'κάποιος') . '.');
        return $this->redirect("ack/$id/$token");
    }

    public function ack(int $id): Response
    {
        $this->authorize('incidents.ack');
        $job = $this->job($id);
        $done = Incidents::ack($job, Auth::id(), (string) Auth::user()['name'], (string) $this->req->input('note', ''));
        $msg = $done ? 'Ανέλαβες το συμβάν. Οι επαναλαμβανόμενες ειδοποιήσεις και η κλιμάκωση σταμάτησαν.' : ($job['failing_since'] ? 'Το συμβάν το έχει ήδη αναλάβει ο/η ' . $job['acked_name'] . '.' : 'Το job δεν αποτυγχάνει αυτή τη στιγμή.');
        if ($this->req->wantsJson()) {
            return $this->json(['ok' => $done, 'message' => $msg]);
        }
        $this->flash($done ? 'success' : 'error', $msg);
        return $this->back('jobs');
    }

    public function unack(int $id): Response
    {
        $this->authorize('incidents.ack');
        $job = $this->job($id);
        if ((int) ($job['acked_by'] ?? 0) !== Auth::id() && !Auth::can('incidents.unack')) {
            throw new HttpException(403, 'Μπορείς να αναιρέσεις μόνο τη δική σου ανάληψη.');
        }
        Incidents::unack($job, (string) Auth::user()['name']);
        $this->flash('success', 'Η ανάληψη αναιρέθηκε· οι ειδοποιήσεις συνεχίζουν κανονικά.');
        return $this->back('jobs');
    }

    private function job(int $id): array
    {
        $job = $this->db->one('SELECT * FROM jobs WHERE id = ? AND tenant_id = ?', [$id, $this->tenantId()]);
        if (!$job) {
            throw HttpException::notFound('Το job δεν βρέθηκε.');
        }
        return $job;
    }
}
