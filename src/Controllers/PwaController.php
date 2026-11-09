<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\App;
use App\Core\Response;

final class PwaController extends Controller
{
    public function manifest(): Response
    {
        $tenant = Auth::tenant();
        $color = $tenant['brand_color'] ?? '#4F46E5';
        $data = [
            'name' => App::name(),
            'short_name' => App::name(),
            'start_url' => url('/'),
            'scope' => url('/'),
            'display' => 'standalone',
            'background_color' => '#0B1020',
            'theme_color' => $color,
            'lang' => 'el',
            'icons' => [
                ['src' => url('assets/icons/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => url('assets/icons/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => url('assets/icons/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ];
        $r = Response::json($data);
        $r->headers['Content-Type'] = 'application/manifest+json';
        return $r;
    }

    /** Served from the root so its scope covers the whole app. */
    public function serviceWorker(): Response
    {
        $js = (string) file_get_contents(App::$root . '/public/assets/sw.js');
        return new Response($js, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'Service-Worker-Allowed' => '/',
        ]);
    }
}
