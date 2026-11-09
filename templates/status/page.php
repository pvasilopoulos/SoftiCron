<?php
/** @var array $tenant @var array $groups @var string $overall @var array $incidents @var int $total @var string $title @var string $description @var string $tz @var string $slug */
use App\Core\App;

$accent = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $tenant['brand_color']) ? $tenant['brand_color'] : '#4F46E5';
$logo = $tenant['logo_path'] ? url('branding/' . $tenant['id'] . '/logo') . '?v=' . rawurlencode((string) $tenant['logo_path']) : null;
$banner = [
    'up' => ['Όλα τα συστήματα λειτουργούν κανονικά', '#16A34A', '✓'],
    'degraded' => ['Λειτουργούν, με μεμονωμένα προβλήματα σήμερα', '#D97706', '!'],
    'partial' => ['Μερική διακοπή λειτουργίας', '#EA580C', '!'],
    'major' => ['Σοβαρή διακοπή λειτουργίας', '#DC2626', '✕'],
    'unknown' => ['Δεν υπάρχουν ακόμα δεδομένα', '#64748B', '…'],
][$overall];
$stateLabel = ['up' => ['Λειτουργεί', '#16A34A'], 'degraded' => ['Με προβλήματα σήμερα', '#D97706'], 'down' => ['Εκτός λειτουργίας', '#DC2626'], 'paused' => ['Σε συντήρηση', '#64748B'], 'unknown' => ['Χωρίς δεδομένα', '#94A3B8']];
$cell = function (?float $r): string {
    if ($r === null) { return '#E2E8F0'; }
    if ($r >= 0.999) { return '#22C55E'; }
    if ($r >= 0.95) { return '#84CC16'; }
    if ($r >= 0.8) { return '#F59E0B'; }
    return '#EF4444';
};
$fmt = fn (?string $utc) => $utc ? (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz))->format('d/m/Y H:i') : '';
?>
<!doctype html>
<html lang="el">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="60">
<title><?= e($title) ?> · Κατάσταση υπηρεσιών</title>
<meta name="description" content="<?= e($banner[0]) ?>">
<link rel="icon" href="<?= e($logo ?? asset('icons/icon.svg')) ?>">
<style>
  :root { --accent: <?= e($accent) ?>; --text: #0F172A; --muted: #5B6577; --border: #E5E7EB; --bg: #F6F7F9; --card: #fff; }
  @media (prefers-color-scheme: dark) { :root { --text: #E5E7EB; --muted: #94A3B8; --border: #232B42; --bg: #0B1020; --card: #121A2E; } }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: Inter, "Segoe UI", system-ui, sans-serif; background: var(--bg); color: var(--text); line-height: 1.5; }
  .wrap { max-width: 880px; margin: 0 auto; padding: 32px 16px 48px; display: flex; flex-direction: column; gap: 20px; }
  header { display: flex; align-items: center; gap: 14px; }
  .logo { width: 44px; height: 44px; border-radius: 12px; background: var(--accent); color: #fff; display: grid; place-items: center; font-weight: 700; overflow: hidden; flex-shrink: 0; }
  .logo img { width: 100%; height: 100%; object-fit: contain; background: #fff; }
  h1 { margin: 0; font-size: 22px; }
  .muted { color: var(--muted); font-size: 14px; }
  .banner { display: flex; align-items: center; gap: 14px; padding: 18px 20px; border-radius: 14px; color: #fff; font-weight: 600; font-size: 17px; }
  .banner .ic { width: 32px; height: 32px; border-radius: 50%; background: rgba(255,255,255,.25); display: grid; place-items: center; }
  .card { background: var(--card); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
  .group-title { padding: 12px 18px; font-weight: 700; font-size: 13px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); border-bottom: 1px solid var(--border); }
  .comp { padding: 14px 18px; border-bottom: 1px solid var(--border); display: flex; flex-direction: column; gap: 8px; }
  .comp:last-child { border-bottom: 0; }
  .comp-head { display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
  .state { font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; }
  .state i { width: 9px; height: 9px; border-radius: 50%; display: inline-block; }
  .bars { display: flex; gap: 2px; height: 30px; }
  .bars span { flex: 1 1 0; border-radius: 2px; min-width: 2px; }
  .axis { display: flex; justify-content: space-between; font-size: 12px; color: var(--muted); }
  .inc { padding: 12px 18px; border-bottom: 1px solid var(--border); font-size: 14px; }
  footer { text-align: center; font-size: 12px; color: var(--muted); }
  footer a { color: var(--muted); }
  @media (max-width: 600px) { .bars span:nth-child(-n+60) { display: none; } .axis .d90 { display: none; } .axis .d30 { display: inline; } }
  .axis .d30 { display: none; }
</style>
</head>
<body>
<div class="wrap">
  <header>
    <div class="logo"><?php if ($logo): ?><img src="<?= e($logo) ?>" alt=""><?php else: ?><?= e(initials($tenant['name'])) ?><?php endif; ?></div>
    <div>
      <h1><?= e($title) ?></h1>
      <div class="muted"><?= $description ? e($description) : 'Κατάσταση υπηρεσιών' ?></div>
    </div>
  </header>

  <div class="banner" style="background:<?= $banner[1] ?>" role="status"><span class="ic" aria-hidden="true"><?= $banner[2] ?></span><?= e($banner[0]) ?></div>

  <?php if ($incidents): ?>
    <section class="card">
      <div class="group-title">Τρέχοντα προβλήματα</div>
      <?php foreach ($incidents as $i): ?>
        <div class="inc"><strong><?= e($i['name']) ?></strong> — εκτός λειτουργίας<?= $i['since'] ? ' από ' . e($fmt($i['since'])) : '' ?>. Η ομάδα μας έχει ενημερωθεί.</div>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>

  <?php if (!$groups): ?>
    <section class="card"><div class="inc muted">Δεν έχουν δημοσιευτεί υπηρεσίες ακόμα.</div></section>
  <?php endif; ?>

  <?php foreach ($groups as $g => $items): ?>
    <section class="card">
      <?php if ($g !== '' || count($groups) > 1): ?><div class="group-title"><?= e($g !== '' ? $g : 'Λοιπές υπηρεσίες') ?></div><?php endif; ?>
      <?php foreach ($items as $c): [$sl, $sc] = $stateLabel[$c['state']]; ?>
        <div class="comp">
          <div class="comp-head">
            <strong><?= e($c['name']) ?></strong>
            <span class="state" style="color:<?= $sc ?>"><i style="background:<?= $sc ?>"></i><?= e($sl) ?></span>
          </div>
          <div class="bars" role="img" aria-label="<?= e('Διαθεσιμότητα 90 ημερών: ' . ($c['uptime'] !== null ? number_format($c['uptime'], 2, ',', '.') . '%' : 'χωρίς δεδομένα')) ?>">
            <?php foreach ($c['days'] as $d): ?>
              <span style="background:<?= $cell($d['ratio']) ?>" title="<?= e(date('d/m/Y', strtotime($d['date'])) . ' · ' . ($d['ratio'] === null ? 'χωρίς δεδομένα' : number_format(100 * $d['ratio'], 1, ',', '.') . '% επιτυχία' . ($d['failed'] ? ', ' . $d['failed'] . ' αποτυχίες' : ''))) ?>"></span>
            <?php endforeach; ?>
          </div>
          <div class="axis"><span class="d90">90 ημέρες πριν</span><span class="d30">30 ημέρες πριν</span><span><?= $c['uptime'] !== null ? e(number_format($c['uptime'], 2, ',', '.')) . '% διαθεσιμότητα' : '' ?></span><span>Σήμερα</span></div>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endforeach; ?>

  <footer>Ενημερώνεται αυτόματα κάθε λεπτό · <?= e((new DateTimeImmutable('now', new DateTimeZone($tz)))->format('d/m/Y H:i')) ?> · <a href="<?= e(url('status/' . $slug . '.json')) ?>">JSON</a> · Powered by <?= e(App::name()) ?></footer>
</div>
</body>
</html>
