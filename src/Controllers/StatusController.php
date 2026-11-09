<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Response;
use App\Core\View;
use App\Services\Settings;

/** Public status page per tenant: /status/{slug} (HTML) and /status/{slug}.json */
final class StatusController extends Controller
{
    private const DAYS = 90;

    private function tenantBySlug(string $slug): array
    {
        $tid = $this->db->value("SELECT tenant_id FROM settings WHERE k = 'status.slug' AND v = ? LIMIT 1", [$slug]);
        $tenant = $tid ? $this->db->one("SELECT * FROM tenants WHERE id = ? AND status = 'active'", [$tid]) : null;
        if (!$tenant || Settings::get((int) $tenant['id'], 'status.enabled') !== '1') {
            throw HttpException::notFound('Η σελίδα κατάστασης δεν βρέθηκε.');
        }
        return $tenant;
    }

    /** Builds the public data (never includes URLs, errors or internal details). */
    private function data(array $tenant): array
    {
        $tid = (int) $tenant['id'];
        $tz = new \DateTimeZone($tenant['timezone'] ?: 'Europe/Athens');
        $offset = $tz->getOffset(new \DateTimeImmutable('now', $tz));
        $jobs = $this->db->all(
            'SELECT id, COALESCE(status_name, name) AS name, group_name, enabled, last_status, failing_since, last_run_at
             FROM jobs WHERE tenant_id = ? AND show_on_status = 1 ORDER BY group_name IS NULL, group_name, name',
            [$tid],
        );
        $ids = array_column($jobs, 'id');
        $daily = [];
        if ($ids) {
            $in = implode(',', array_map('intval', $ids));
            $since = gmdate('Y-m-d H:i:s', time() - self::DAYS * 86400);
            foreach ($this->db->all(
                "SELECT job_id, DATE(COALESCE(started_at, scheduled_at) + INTERVAL $offset SECOND) AS d,
                        SUM(status = 'success') AS ok, SUM(status = 'failed') AS failed
                 FROM job_runs WHERE job_id IN ($in) AND status IN ('success','failed') AND COALESCE(started_at, scheduled_at) >= ?
                 GROUP BY job_id, d",
                [$since],
            ) as $r) {
                $daily[(int) $r['job_id']][$r['d']] = [(int) $r['ok'], (int) $r['failed']];
            }
        }
        $today = new \DateTimeImmutable('now', $tz);
        $groups = [];
        $down = 0;
        $degraded = 0;
        foreach ($jobs as $j) {
            $days = [];
            $okSum = $failSum = 0;
            for ($i = self::DAYS - 1; $i >= 0; $i--) {
                $d = $today->modify("-$i days")->format('Y-m-d');
                [$ok, $failed] = $daily[(int) $j['id']][$d] ?? [0, 0];
                $okSum += $ok;
                $failSum += $failed;
                $ratio = $ok + $failed ? $ok / ($ok + $failed) : null;
                $days[] = ['date' => $d, 'ratio' => $ratio, 'failed' => $failed];
            }
            $state = !$j['enabled'] ? 'paused' : ($j['last_status'] === 'failed' ? 'down' : ($j['last_status'] === null ? 'unknown' : 'up'));
            if ($state === 'down') {
                $down++;
            }
            if ($state === 'up' && ($days[self::DAYS - 1]['failed'] ?? 0) > 0) {
                $state = 'degraded';
                $degraded++;
            }
            $groups[$j['group_name'] ?? ''][] = [
                'name' => $j['name'],
                'state' => $state,
                'since' => $j['failing_since'],
                'uptime' => $okSum + $failSum ? 100 * $okSum / ($okSum + $failSum) : null,
                'days' => $days,
            ];
        }
        $total = count($jobs);
        $overall = $total === 0 ? 'unknown' : ($down === 0 ? ($degraded ? 'degraded' : 'up') : ($down >= max(1, (int) ceil($total / 2)) ? 'major' : 'partial'));
        // Incidents: currently failing components.
        $incidents = [];
        foreach ($jobs as $j) {
            if ($j['enabled'] && $j['last_status'] === 'failed') {
                $incidents[] = ['name' => $j['name'], 'since' => $j['failing_since'] ?? $j['last_run_at']];
            }
        }
        return ['tenant' => $tenant, 'groups' => $groups, 'overall' => $overall, 'incidents' => $incidents, 'total' => $total,
            'title' => Settings::get($tid, 'status.title') ?: $tenant['name'], 'description' => Settings::get($tid, 'status.description', ''), 'tz' => $tz->getName()];
    }

    public function show(string $slug): Response
    {
        $tenant = $this->tenantBySlug($slug);
        $d = $this->data($tenant);
        $r = Response::html(View::render('status/page', $d + ['slug' => $slug], null));
        $r->headers['Cache-Control'] = 'public, max-age=60';
        return $r;
    }

    public function feed(string $slug): Response
    {
        $tenant = $this->tenantBySlug($slug);
        $d = $this->data($tenant);
        $components = [];
        foreach ($d['groups'] as $g => $items) {
            foreach ($items as $c) {
                $components[] = ['group' => $g ?: null, 'name' => $c['name'], 'status' => $c['state'], 'uptime_90d' => $c['uptime'] !== null ? round($c['uptime'], 3) : null];
            }
        }
        $r = Response::json(['title' => $d['title'], 'status' => $d['overall'], 'components' => $components, 'updated_at' => gmdate(DATE_ATOM)]);
        $r->headers['Cache-Control'] = 'public, max-age=60';
        $r->headers['Access-Control-Allow-Origin'] = '*';
        return $r;
    }
}
