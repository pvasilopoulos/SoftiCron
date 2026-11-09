<?php
/** @var string $title */
use App\Auth\Auth;
use App\Core\App;
use App\Core\Csrf;

$tenant = Auth::tenant();
$user = Auth::user();
$accent = $tenant['brand_color'] ?? '#4F46E5';
if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $accent)) {
    $accent = '#4F46E5';
}
[$r, $g, $b] = sscanf($accent, '#%02x%02x%02x');
$lum = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
$accentText = $accent;
if ($lum > 0.55) {
    // Very light brand colour: darken for text contrast on white.
    $accentText = sprintf('#%02x%02x%02x', (int) ($r * .45), (int) ($g * .45), (int) ($b * .45));
}
$theme = $user['theme'] ?? 'system';
?>
<!doctype html>
<html lang="el"<?= $theme !== 'system' ? ' data-theme="' . e($theme) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
<meta name="theme-color" content="<?= e($accent) ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= e(App::name()) ?>">
<title><?= e(($title ?? '') !== '' ? $title . ' · ' : '') . e(App::name()) ?></title>
<link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
<link rel="icon" href="<?= e(asset('icons/icon.svg')) ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= e(asset('icons/icon-192.png')) ?>">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
<style>:root{--accent:<?= e($accent) ?>;--accent-soft:<?= e($accent) ?>1f;--accent-text:<?= e($accentText) ?>;--accent-light:<?= e(hex_mix($accent, .55)) ?>}</style>
<script>window.APP = { base: <?= json_encode(url('')) ?>, csrf: <?= json_encode(Csrf::token()) ?> };</script>
<script src="<?= e(asset('app.js')) ?>" defer></script>
</head>
