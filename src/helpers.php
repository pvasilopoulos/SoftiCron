<?php

declare(strict_types=1);

use App\Core\App;
use App\Core\Csrf;
use App\Core\Env;

/** HTML-escape a value for output. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Absolute or root-relative URL for an app path. */
function url(string $path = '', array $query = []): string
{
    $base = rtrim((string) Env::get('APP_BASE_PATH', ''), '/');
    $u = $base . '/' . ltrim($path, '/');
    if ($query) {
        $query = array_filter($query, fn ($v) => $v !== null && $v !== '');
        if ($query) {
            $u .= '?' . http_build_query($query);
        }
    }
    return $u;
}

/** Full external URL (for emails, push notifications). */
function abs_url(string $path = ''): string
{
    return rtrim((string) Env::get('APP_URL', ''), '/') . url($path);
}

function asset(string $path): string
{
    $file = App::$root . '/public/assets/' . ltrim($path, '/');
    $v = is_file($file) ? (string) filemtime($file) : '1';
    return url('assets/' . ltrim($path, '/')) . '?v=' . $v;
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function method_field(string $method): string
{
    return '<input type="hidden" name="_method" value="' . e(strtoupper($method)) . '">';
}

/** Format a UTC datetime string in the given timezone. */
function fmt_dt(?string $utc, string $tz = 'Europe/Athens', string $format = 'd/m/Y H:i'): string
{
    if (!$utc) {
        return '—';
    }
    try {
        $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        return $d->setTimezone(new DateTimeZone($tz))->format($format);
    } catch (Throwable) {
        return '—';
    }
}

/** Human relative time in Greek ("πριν 5 λεπτά" / "σε 3 ώρες"). */
function rel_time(?string $utc): string
{
    if (!$utc) {
        return '—';
    }
    $t = strtotime($utc . ' UTC');
    $diff = $t - time();
    $future = $diff > 0;
    $s = abs($diff);
    if ($s < 45) {
        return $future ? 'σε λίγα δευτερόλεπτα' : 'μόλις τώρα';
    }
    $units = [[86400, 'ημέρα', 'ημέρες'], [3600, 'ώρα', 'ώρες'], [60, 'λεπτό', 'λεπτά']];
    foreach ($units as [$sec, $one, $many]) {
        if ($s >= $sec) {
            $n = (int) round($s / $sec);
            $w = $n === 1 ? $one : $many;
            return $future ? "σε $n $w" : "πριν $n $w";
        }
    }
    return '—';
}

function fmt_ms(?int $ms): string
{
    if ($ms === null) {
        return '—';
    }
    if ($ms < 1000) {
        return $ms . ' ms';
    }
    return number_format($ms / 1000, 2, ',', '.') . ' s';
}

function fmt_bytes(?int $b): string
{
    if ($b === null) {
        return '—';
    }
    if ($b < 1024) {
        return $b . ' B';
    }
    if ($b < 1048576) {
        return number_format($b / 1024, 1, ',', '.') . ' KB';
    }
    return number_format($b / 1048576, 1, ',', '.') . ' MB';
}

const RUN_STATUS = [
    'success' => ['Επιτυχία', 'ok'],
    'failed' => ['Αποτυχία', 'fail'],
    'running' => ['Εκτελείται', 'run'],
    'queued' => ['Σε αναμονή', 'queued'],
    'retrying' => ['Νέα προσπάθεια', 'slow'],
    'skipped' => ['Παραλείφθηκε', 'off'],
    'missed' => ['Χαμένη', 'off'],
];

function status_badge(?string $status, bool $slow = false): string
{
    if ($status === null || $status === '') {
        return '<span class="badge off"><span class="dot"></span>Ποτέ</span>';
    }
    [$label, $cls] = RUN_STATUS[$status] ?? [$status, 'off'];
    if ($slow && $status === 'success') {
        [$label, $cls] = ['Αργή', 'slow'];
    }
    return '<span class="badge ' . $cls . '"><span class="dot"></span>' . e($label) . '</span>';
}

function icon(string $name, int $size = 18): string
{
    $paths = [
        'dashboard' => 'M3 3h7v9H3zM14 3h7v5h-7zM14 12h7v9h-7zM3 16h7v5H3z',
        'jobs' => 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 6v6l4 2',
        'runs' => 'M22 12h-4l-3 9L9 3l-3 9H2',
        'templates' => 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h8',
        'connectors' => 'M12 22v-5M9 8V2M15 8V2M18 8v5a6 6 0 0 1-12 0V8z',
        'bell' => 'M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 0 0 3.4 0',
        'users' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
        'roles' => 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10zM9 12l2 2 4-4',
        'audit' => 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z',
        'settings' => 'M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6',
        'home' => 'M3 10.5L12 3l9 7.5V21h-6v-6H9v6H3z',
        'menu' => 'M4 6h16M4 12h16M4 18h16',
        'plus' => 'M12 5v14M5 12h14',
        'search' => 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.3-4.3',
        'play' => 'M6 3l14 9-14 9z',
        'x' => 'M18 6L6 18M6 6l12 12',
        'check' => 'M20 6L9 17l-5-5',
        'chevron-left' => 'M15 18l-6-6 6-6',
        'chevron-right' => 'M9 18l6-6-6-6',
        'chevrons' => 'M7 15l5 5 5-5M7 9l5-5 5 5',
        'lock' => 'M5 11h14v10H5zM8 11V7a4 4 0 0 1 8 0v4',
        'logout' => 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9',
        'building' => 'M3 21h18M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16M9 7h1M14 7h1M9 11h1M14 11h1M9 15h1M14 15h1',
        'sun' => 'M12 17a5 5 0 1 0 0-10 5 5 0 0 0 0 10zM12 1v2M12 21v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M1 12h2M21 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4',
        'moon' => 'M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z',
        'copy' => 'M9 9h11v11H9zM5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1',
        'trash' => 'M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6',
        'edit' => 'M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z',
        'refresh' => 'M21 12a9 9 0 1 1-3-6.7L21 8M21 3v5h-5',
        'send' => 'M22 2L11 13M22 2l-7 20-4-9-9-4z',
        'user' => 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
        'download' => 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3',
        'upload' => 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12',
        'clock' => 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 6v6l4 2',
        'key' => 'M21 2l-2 2m-7.6 7.6a5.5 5.5 0 1 1-7.78 7.78 5.5 5.5 0 0 1 7.78-7.78zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4',
        'shield' => 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z',
        'check-all' => 'M18 6L7 17l-5-5M22 10l-7.5 7.5L13 16',
    ];
    $d = $paths[$name] ?? $paths['dashboard'];
    return '<svg class="ic" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . $d . '"/></svg>';
}

/** Initials for avatars / logo placeholders. */
function initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $out .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $out !== '' ? $out : '?';
}

/** Mix a hex color toward white (w = 0..1). */
function hex_mix(string $hex, float $w): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) {
        $hex = '4F46E5';
    }
    $out = '#';
    foreach (str_split($hex, 2) as $c) {
        $v = hexdec($c);
        $out .= str_pad(dechex((int) round($v + (255 - $v) * $w)), 2, '0', STR_PAD_LEFT);
    }
    return $out;
}

function old(string $key, mixed $default = ''): mixed
{
    $old = $_SESSION['_old'] ?? [];
    return $old[$key] ?? $default;
}
