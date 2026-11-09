<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Response;
use App\Core\View;
use App\Services\StatusPage;

/** Public status page per tenant: page, JSON, SVG badge, RSS and embeddable widget. */
final class StatusController extends Controller
{
    private function load(string $slug): array
    {
        $tenant = StatusPage::tenantBySlug($slug);
        if (!$tenant) {
            throw HttpException::notFound('Η σελίδα κατάστασης δεν βρέθηκε.');
        }
        return StatusPage::data($tenant);
    }

    private function cached(Response $r, int $seconds = 60): Response
    {
        $r->headers['Cache-Control'] = 'public, max-age=' . $seconds;
        $r->headers['X-Content-Type-Options'] = 'nosniff';
        return $r;
    }

    public function show(string $slug): Response
    {
        $d = $this->load($slug);
        $r = Response::html(View::render('status/page', $d + ['slug' => $slug], null));
        if ($d['options']['noindex'] === '1') {
            $r->headers['X-Robots-Tag'] = 'noindex';
        }
        return $this->cached($r);
    }

    /** Compact version for an <iframe> on another site. */
    public function embed(string $slug): Response
    {
        $d = $this->load($slug);
        $r = Response::html(View::render('status/embed', $d + ['slug' => $slug], null));
        $r->headers['Content-Security-Policy'] = 'frame-ancestors *';
        return $this->cached($r);
    }

    public function feed(string $slug): Response
    {
        $d = $this->load($slug);
        $components = [];
        foreach ($d['groups'] as $g => $items) {
            foreach ($items as $c) {
                $u7 = StatusPage::uptime($c['days'], 7);
                $u30 = StatusPage::uptime($c['days'], 30);
                $u90 = StatusPage::uptime($c['days'], 90);
                $components[] = [
                    'group' => $g ?: null, 'name' => $c['name'], 'status' => $c['state'],
                    'uptime_7d' => $u7 !== null ? round($u7, 3) : null,
                    'uptime_30d' => $u30 !== null ? round($u30, 3) : null,
                    'uptime_90d' => $u90 !== null ? round($u90, 3) : null,
                    'response_ms_24h' => $d['options']['show_response'] === '1' ? $c['response_ms'] : null,
                ];
            }
        }
        $iso = fn (?string $utc) => $utc ? gmdate('Y-m-d\TH:i:s\Z', strtotime($utc . ' UTC')) : null;
        $notices = [];
        foreach (['active', 'upcoming'] as $b) {
            foreach ($d['notices'][$b] as $n) {
                $notices[] = ['type' => $n['kind'], 'title' => $n['title'], 'body' => $n['body'], 'starts_at' => $iso($n['starts_at']), 'ends_at' => $iso($n['ends_at']), 'state' => $b];
            }
        }
        $incidents = array_map(fn ($i) => [
            'component' => $i['name'], 'started_at' => $iso($i['start']), 'resolved_at' => $iso($i['end']), 'duration_min' => $i['minutes'],
        ], array_slice($d['history'], 0, 20));
        $r = Response::json([
            'title' => $d['title'], 'status' => $d['overall'], 'components' => $components,
            'notices' => $notices, 'incidents' => $incidents, 'updated_at' => gmdate(DATE_ATOM),
        ]);
        $r->headers['Access-Control-Allow-Origin'] = '*';
        return $this->cached($r);
    }

    public function badge(string $slug): Response
    {
        $tenant = StatusPage::tenantBySlug($slug);
        $overall = $tenant ? StatusPage::data($tenant)['overall'] : 'unknown';
        $label = mb_substr((string) ($this->req->query('label', '') ?: 'status'), 0, 30);
        $r = new Response(StatusPage::badge($label, $overall), 200, ['Content-Type' => 'image/svg+xml; charset=utf-8']);
        $r->headers['Access-Control-Allow-Origin'] = '*';
        return $this->cached($r, 120);
    }

    /** RSS 2.0 with announcements and incidents. */
    public function rss(string $slug): Response
    {
        $d = $this->load($slug);
        $x = fn (string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $link = abs_url('status/' . $slug);
        $items = [];
        foreach (array_merge($d['notices']['active'], $d['notices']['upcoming'], $d['notices']['past']) as $n) {
            $items[] = [strtotime($n['starts_at'] . ' UTC'), (StatusPage::NOTICE_KINDS[$n['kind']][0] ?? '') . ': ' . $n['title'], (string) $n['body'], 'notice-' . $n['id']];
        }
        foreach ($d['history'] as $i) {
            $items[] = [
                strtotime($i['start'] . ' UTC'),
                ($i['ongoing'] ? 'Διακοπή σε εξέλιξη: ' : 'Διακοπή: ') . $i['name'],
                $i['ongoing'] ? 'Η υπηρεσία είναι εκτός λειτουργίας. Η ομάδα έχει ενημερωθεί.' : 'Διάρκεια ' . $i['minutes'] . ' λεπτά. Επιλύθηκε.',
                'incident-' . md5($i['name'] . $i['start']),
            ];
        }
        usort($items, fn ($a, $b) => $b[0] <=> $a[0]);
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<rss version="2.0"><channel>'
            . '<title>' . $x($d['title'] . ' · Κατάσταση υπηρεσιών') . '</title><link>' . $x($link) . '</link>'
            . '<description>' . $x($d['description'] ?: 'Ανακοινώσεις και συμβάντα') . '</description><language>el</language>';
        foreach (array_slice($items, 0, 50) as [$ts, $title, $body, $guid]) {
            $xml .= '<item><title>' . $x($title) . '</title><link>' . $x($link) . '</link><guid isPermaLink="false">' . $x($guid) . '</guid>'
                . '<pubDate>' . gmdate(DATE_RSS, $ts) . '</pubDate><description>' . $x($body) . '</description></item>';
        }
        $xml .= '</channel></rss>';
        return $this->cached(new Response($xml, 200, ['Content-Type' => 'application/rss+xml; charset=utf-8']), 300);
    }
}
