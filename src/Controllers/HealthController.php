<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Services\Watchdog;

/**
 * GET /health — for external uptime monitors. 200 when the database answers and the
 * scheduler ticked in the last 5 minutes, 503 otherwise. Exposes no tenant data.
 */
final class HealthController extends Controller
{
    public function health(): Response
    {
        $db = true;
        try {
            $this->db->value('SELECT 1');
        } catch (\Throwable) {
            $db = false;
        }
        $st = $db ? Watchdog::status() : ['last_tick' => null, 'age' => null, 'ok' => false];
        if ($db) {
            Watchdog::checkFromWeb(); // a monitor hitting /health also triggers the admins' alert
        }
        $ok = $db && $st['ok'];
        $r = Response::json([
            'status' => $ok ? 'ok' : 'fail',
            'database' => $db ? 'ok' : 'fail',
            'scheduler' => [
                'status' => $st['ok'] ? 'ok' : ($st['last_tick'] ? 'stale' : 'never_ran'),
                'last_tick' => $st['last_tick'] ? gmdate('Y-m-d\TH:i:s\Z', strtotime($st['last_tick'] . ' UTC')) : null,
                'seconds_since_tick' => $st['age'],
                'max_seconds' => Watchdog::STALE_SECONDS,
            ],
            'time' => gmdate('Y-m-d\TH:i:s\Z'),
        ], $ok ? 200 : 503);
        $r->headers['Cache-Control'] = 'no-store';
        return $r;
    }
}
