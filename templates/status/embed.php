<?php
/** @var array $tenant @var array $groups @var string $overall @var array $counts @var int $total @var string $title @var string $slug @var array $options */
use App\Services\StatusPage;

$banner = [
    'up' => ['Όλα λειτουργούν κανονικά', '#16A34A'], 'degraded' => ['Μεμονωμένα προβλήματα σήμερα', '#D97706'],
    'partial' => ['Μερική διακοπή', '#EA580C'], 'major' => ['Σοβαρή διακοπή', '#DC2626'], 'unknown' => ['Χωρίς δεδομένα', '#64748B'],
    'incident' => ['Διερευνάται συμβάν', '#D97706'], 'maintenance' => ['Συντήρηση σε εξέλιξη', '#7C3AED'],
][$overall];
$stateColor = ['up' => '#16A34A', 'degraded' => '#D97706', 'down' => '#DC2626', 'paused' => '#64748B', 'unknown' => '#94A3B8'];
$items = [];
foreach ($groups as $g) {
    foreach ($g as $c) {
        $items[] = $c;
    }
}
// Problems first, then alphabetical.
usort($items, fn ($a, $b) => [(int) !in_array($a['state'], ['down', 'degraded'], true), $a['name']] <=> [(int) !in_array($b['state'], ['down', 'degraded'], true), $b['name']]);
$theme = $options['theme'];
?>
<!doctype html>
<html lang="el"<?= $theme !== 'auto' ? ' data-theme="' . e($theme) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="120">
<meta name="robots" content="noindex">
<title><?= e($title) ?> · Κατάσταση</title>
<style>
  :root { --text: #0F172A; --muted: #5B6577; --border: #E5E7EB; --card: #fff; }
  <?php if ($theme === 'auto'): ?>@media (prefers-color-scheme: dark) { :root { --text: #E5E7EB; --muted: #94A3B8; --border: #232B42; --card: #121A2E; } }<?php endif; ?>
  :root[data-theme="dark"] { --text: #E5E7EB; --muted: #94A3B8; --border: #232B42; --card: #121A2E; }
  body { margin: 0; font-family: Inter, "Segoe UI", system-ui, sans-serif; background: transparent; color: var(--text); font-size: 13px; }
  .w { background: var(--card); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
  .top { display: flex; align-items: center; gap: 10px; padding: 12px 14px; color: #fff; }
  .top b { flex: 1; font-size: 14px; }
  .top a { color: #fff; opacity: .9; font-size: 12px; text-decoration: none; }
  .row { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 8px 14px; border-top: 1px solid var(--border); }
  .row span:first-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .dot { width: 9px; height: 9px; border-radius: 50%; flex: none; }
  .more { padding: 8px 14px; border-top: 1px solid var(--border); color: var(--muted); font-size: 12px; }
</style>
</head>
<body>
<div class="w">
  <div class="top" style="background:<?= $banner[1] ?>"><b><?= e($title) ?> · <?= e($banner[0]) ?></b><a href="<?= e(abs_url('status/' . $slug)) ?>" target="_blank" rel="noopener">Λεπτομέρειες →</a></div>
  <?php foreach (array_slice($items, 0, 4) as $c): $u = StatusPage::uptime($c['days'], 30); ?>
    <div class="row"><span><?= e($c['name']) ?></span><span style="display:flex;align-items:center;gap:8px;color:var(--muted)"><?= $options['show_uptime'] === '1' && $u !== null ? e(number_format($u, 2, ',', '.')) . '%' : '' ?><i class="dot" style="background:<?= $stateColor[$c['state']] ?>"></i></span></div>
  <?php endforeach; ?>
  <?php if (count($items) > 4): ?><div class="more">+<?= count($items) - 4 ?> ακόμα υπηρεσίες</div><?php endif; ?>
  <?php if (!$items): ?><div class="more">Δεν έχουν δημοσιευτεί υπηρεσίες.</div><?php endif; ?>
</div>
</body>
</html>
