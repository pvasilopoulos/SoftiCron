<?php
/** @var array $tenant @var array $groups @var string $overall @var array $counts @var array $history @var array $notices @var int $total @var string $title @var string $description @var string $tz @var string $slug @var array $options */
use App\Core\App;
use App\Services\StatusPage;

$accent = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $tenant['brand_color']) ? $tenant['brand_color'] : '#4F46E5';
$logo = $tenant['logo_path'] ? url('branding/' . $tenant['id'] . '/logo') . '?v=' . rawurlencode((string) $tenant['logo_path']) : null;
$banner = [
    'up' => ['Όλα τα συστήματα λειτουργούν κανονικά', '#16A34A'],
    'degraded' => ['Λειτουργούν, με μεμονωμένα προβλήματα σήμερα', '#D97706'],
    'partial' => ['Μερική διακοπή λειτουργίας', '#EA580C'],
    'major' => ['Σοβαρή διακοπή λειτουργίας', '#DC2626'],
    'unknown' => ['Δεν υπάρχουν ακόμα δεδομένα', '#64748B'],
    'incident' => ['Διερευνούμε ένα συμβάν', '#D97706'],
    'maintenance' => ['Συντήρηση σε εξέλιξη', '#7C3AED'],
][$overall];
$stateLabel = ['up' => ['Λειτουργεί', '#16A34A'], 'degraded' => ['Προβλήματα σήμερα', '#D97706'], 'down' => ['Εκτός λειτουργίας', '#DC2626'], 'paused' => ['Σε συντήρηση', '#64748B'], 'unknown' => ['Χωρίς δεδομένα', '#94A3B8']];
$local = fn (?string $utc, string $f = 'd/m/Y H:i') => $utc ? (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz))->format($f) : '';
$dur = function (int $min): string {
    if ($min < 60) {
        return $min . ' λεπτ.';
    }
    if ($min < 1440) {
        return intdiv($min, 60) . ' ώρ.' . ($min % 60 ? ' ' . $min % 60 . ' λεπτ.' : '');
    }
    return intdiv($min, 1440) . ' ημ.' . (intdiv($min % 1440, 60) ? ' ' . intdiv($min % 1440, 60) . ' ώρ.' : '');
};
$o = $options;
$days = (int) $o['default_days'];
$theme = $o['theme'];
// Compact per-day data for the client (period switch, tooltips).
$js = [];
foreach ($groups as $items) {
    foreach ($items as $c) {
        $js[$c['id']] = $c['days'];
    }
}
$activeNotices = $o['show_notices'] === '1' ? $notices['active'] : [];
$upcoming = $o['show_notices'] === '1' ? $notices['upcoming'] : [];
$pastNotices = $o['show_notices'] === '1' ? $notices['past'] : [];
$ongoing = array_values(array_filter($history, fn ($i) => $i['ongoing']));
$groupNames = array_keys($groups);
$issues = $counts['down'] + $counts['degraded'];
?>
<!doctype html>
<html lang="el"<?= $theme !== 'auto' ? ' data-theme="' . e($theme) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?> · Κατάσταση υπηρεσιών</title>
<meta name="description" content="<?= e($banner[0]) ?>">
<?php if ($o['noindex'] === '1'): ?><meta name="robots" content="noindex"><?php endif; ?>
<meta property="og:title" content="<?= e($title . ' · Κατάσταση υπηρεσιών') ?>">
<meta property="og:description" content="<?= e($banner[0]) ?>">
<meta name="theme-color" content="<?= e($banner[1]) ?>">
<link rel="icon" href="<?= e($logo ?? asset('icons/icon.svg')) ?>">
<link rel="alternate" type="application/rss+xml" title="<?= e($title) ?> · συμβάντα" href="<?= e(url('status/' . $slug . '.rss')) ?>">
<style>
  :root { --accent: <?= e($accent) ?>; --text: #0F172A; --muted: #5B6577; --border: #E5E7EB; --bg: #F6F7F9; --card: #fff; --soft: #F1F5F9; --none: #E2E8F0; color-scheme: light; }
  <?php if ($theme === 'auto'): ?>@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) { --text: #E5E7EB; --muted: #94A3B8; --border: #232B42; --bg: #0B1020; --card: #121A2E; --soft: #1A2338; --none: #2A3450; color-scheme: dark; } }<?php endif; ?>
  :root[data-theme="dark"] { --text: #E5E7EB; --muted: #94A3B8; --border: #232B42; --bg: #0B1020; --card: #121A2E; --soft: #1A2338; --none: #2A3450; color-scheme: dark; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: Inter, "Segoe UI", system-ui, -apple-system, sans-serif; background: var(--bg); color: var(--text); line-height: 1.5; -webkit-font-smoothing: antialiased; }
  a { color: var(--accent); }
  .wrap { max-width: 920px; margin: 0 auto; padding: 28px 16px 48px; display: flex; flex-direction: column; gap: 18px; }
  header { display: flex; align-items: center; gap: 14px; }
  .logo { width: 48px; height: 48px; border-radius: 13px; background: var(--accent); color: #fff; display: grid; place-items: center; font-weight: 700; overflow: hidden; flex-shrink: 0; }
  .logo img { width: 100%; height: 100%; object-fit: contain; background: #fff; }
  h1 { margin: 0; font-size: 22px; letter-spacing: -.01em; }
  h2 { margin: 0; font-size: 15px; }
  .muted { color: var(--muted); font-size: 14px; }
  .xs { font-size: 12px; }
  .grow { flex: 1; min-width: 0; }
  .hdr-actions { display: flex; gap: 6px; margin-left: auto; }
  .btn { display: inline-flex; align-items: center; gap: 6px; height: 34px; padding: 0 12px; border: 1px solid var(--border); border-radius: 9px; background: var(--card); color: var(--text); font: inherit; font-size: 13px; font-weight: 500; cursor: pointer; text-decoration: none; }
  .btn:hover { border-color: var(--accent); }
  .banner { display: flex; align-items: center; gap: 14px; padding: 18px 20px; border-radius: 16px; color: #fff; box-shadow: 0 8px 24px -12px rgba(0,0,0,.35); }
  .banner .ic { width: 36px; height: 36px; border-radius: 50%; background: rgba(255,255,255,.22); display: grid; place-items: center; flex-shrink: 0; }
  .banner strong { font-size: 17px; display: block; }
  .banner .sub { font-size: 13px; opacity: .9; }
  .summary { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
  .sum { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 12px 14px; }
  .sum b { font-size: 20px; display: block; }
  .card { background: var(--card); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
  .notice { padding: 14px 18px; border-left: 4px solid; border-bottom: 1px solid var(--border); display: flex; flex-direction: column; gap: 4px; }
  .notice:last-child { border-bottom: 0; }
  .kind { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
  .toolbar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between; }
  .chips { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
  .chip { padding: 6px 12px; border: 1px solid var(--border); border-radius: 999px; background: var(--card); color: var(--muted); font: inherit; font-size: 13px; font-weight: 500; cursor: pointer; }
  .chip[aria-pressed="true"] { background: var(--accent); border-color: var(--accent); color: #fff; }
  .search { display: flex; align-items: center; gap: 6px; height: 34px; padding: 0 10px; border: 1px solid var(--border); border-radius: 9px; background: var(--card); color: var(--muted); }
  .search input { border: 0; background: none; color: var(--text); font: inherit; font-size: 13px; outline: none; width: 160px; }
  .seg { display: inline-flex; padding: 3px; gap: 2px; background: var(--soft); border-radius: 10px; }
  .seg button { border: 0; background: none; padding: 5px 11px; border-radius: 8px; font: inherit; font-size: 13px; font-weight: 600; color: var(--muted); cursor: pointer; }
  .seg button[aria-pressed="true"] { background: var(--card); color: var(--text); box-shadow: 0 1px 2px rgba(0,0,0,.08); }
  details.group > summary { list-style: none; cursor: pointer; padding: 12px 18px; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid var(--border); }
  details.group > summary::-webkit-details-marker { display: none; }
  details.group > summary .chev { transition: transform .15s; color: var(--muted); flex: none; }
  details.group[open] > summary .chev { transform: rotate(90deg); }
  details.group:not([open]) > summary { border-bottom: 0; }
  details.group[hidden] { display: none; }
  .gname { font-weight: 700; font-size: 13px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }
  .comp { padding: 14px 18px; border-bottom: 1px solid var(--border); display: flex; flex-direction: column; gap: 8px; }
  .comp:last-child { border-bottom: 0; }
  .comp[hidden] { display: none; }
  .comp-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; }
  .comp-name { font-weight: 600; }
  .meta { display: flex; gap: 12px; align-items: center; font-size: 13px; color: var(--muted); flex-wrap: wrap; }
  .state { font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; }
  .state i { width: 9px; height: 9px; border-radius: 50%; display: inline-block; }
  .state.pulse i { animation: pulse 1.6s infinite; }
  @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(220, 38, 38, .6); } 70%, 100% { box-shadow: 0 0 0 7px rgba(220, 38, 38, 0); } }
  .bars { display: flex; gap: 2px; height: 32px; }
  .bars span { flex: 1 1 0; border-radius: 3px; min-width: 2px; background: var(--none); cursor: pointer; transition: transform .1s; }
  .bars span:hover, .bars span:focus { transform: scaleY(1.12); outline: none; }
  .axis { display: flex; justify-content: space-between; font-size: 12px; color: var(--muted); }
  .tip { position: fixed; z-index: 10; pointer-events: none; background: #0F172A; color: #fff; border-radius: 10px; padding: 8px 10px; font-size: 12px; line-height: 1.45; box-shadow: 0 10px 30px rgba(0,0,0,.3); max-width: 230px; }
  .tip[hidden] { display: none; }
  .tip b { display: block; font-size: 12.5px; }
  .hist { padding: 12px 18px; border-bottom: 1px solid var(--border); display: grid; grid-template-columns: 14px 1fr auto; gap: 10px; align-items: start; font-size: 14px; }
  .hist:last-child { border-bottom: 0; }
  .hist .dot { width: 10px; height: 10px; border-radius: 50%; margin-top: 6px; }
  .section-title { display: flex; justify-content: space-between; align-items: center; padding: 12px 18px; border-bottom: 1px solid var(--border); }
  .empty { padding: 22px 18px; color: var(--muted); font-size: 14px; text-align: center; }
  .legend { display: flex; flex-wrap: wrap; gap: 12px; font-size: 12px; color: var(--muted); }
  .legend i { width: 10px; height: 10px; border-radius: 3px; display: inline-block; margin-right: 4px; vertical-align: -1px; }
  footer { text-align: center; font-size: 12px; color: var(--muted); display: flex; flex-direction: column; gap: 6px; }
  footer a { color: var(--muted); }
  .sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
  @media (max-width: 640px) {
    .summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .search input { width: 110px; }
    .hdr-actions .label { display: none; }
    .bars { height: 28px; gap: 1px; }
  }
  @media (prefers-reduced-motion: reduce) { * { animation: none !important; transition: none !important; } }
</style>
</head>
<body>
<div class="wrap">
  <header>
    <div class="logo"><?php if ($logo): ?><img src="<?= e($logo) ?>" alt=""><?php else: ?><?= e(initials($tenant['name'])) ?><?php endif; ?></div>
    <div class="grow">
      <h1><?= e($title) ?></h1>
      <div class="muted"><?= $description ? e($description) : 'Κατάσταση υπηρεσιών' ?></div>
    </div>
    <div class="hdr-actions">
      <a class="btn" href="<?= e(url('status/' . $slug . '.rss')) ?>" title="Εγγραφή μέσω RSS"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M4 11a9 9 0 0 1 9 9M4 4a16 16 0 0 1 16 16"/><circle cx="5" cy="19" r="1"/></svg><span class="label">Εγγραφή</span></a>
      <button class="btn" type="button" data-theme-btn title="Φωτεινό / σκοτεινό θέμα" aria-label="Αλλαγή θέματος">◐</button>
    </div>
  </header>

  <div class="banner" style="background:<?= $banner[1] ?>" role="status">
    <span class="ic" aria-hidden="true"><?= $overall === 'up'
        ? '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>'
        : '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round"><path d="M12 7v6M12 17h.01"/></svg>' ?></span>
    <div class="grow">
      <strong><?= e($banner[0]) ?></strong>
      <span class="sub">Ενημέρωση <?= e($local(gmdate('Y-m-d H:i:s'), 'H:i')) ?> · ανανέωση σε <span data-countdown>60</span>″</span>
    </div>
  </div>

  <?php if ($total): ?>
    <div class="summary" aria-label="Σύνοψη">
      <div class="sum"><b style="color:#16A34A"><?= $counts['up'] ?></b><span class="muted xs">Λειτουργούν</span></div>
      <div class="sum"><b style="color:#D97706"><?= $counts['degraded'] ?></b><span class="muted xs">Με προβλήματα σήμερα</span></div>
      <div class="sum"><b style="color:#DC2626"><?= $counts['down'] ?></b><span class="muted xs">Εκτός λειτουργίας</span></div>
      <div class="sum"><b style="color:#64748B"><?= $counts['paused'] ?></b><span class="muted xs">Σε συντήρηση</span></div>
    </div>
  <?php endif; ?>

  <?php if ($activeNotices || $ongoing): ?>
    <section class="card" aria-label="Τρέχοντα">
      <?php foreach ($activeNotices as $n): [$kl, $kc] = StatusPage::NOTICE_KINDS[$n['kind']]; ?>
        <div class="notice" style="border-left-color:<?= $kc ?>">
          <span class="kind" style="color:<?= $kc ?>"><?= e($kl) ?><?= $n['is_pinned'] ? ' · 📌' : '' ?></span>
          <strong><?= e($n['title']) ?></strong>
          <?php if ($n['body']): ?><span style="white-space:pre-line;font-size:14px"><?= e($n['body']) ?></span><?php endif; ?>
          <span class="muted xs">Από <?= e($local($n['starts_at'])) ?><?= $n['ends_at'] ? ' έως ' . e($local($n['ends_at'])) : '' ?></span>
        </div>
      <?php endforeach; ?>
      <?php foreach ($ongoing as $i): ?>
        <div class="notice" style="border-left-color:#DC2626">
          <span class="kind" style="color:#DC2626">Διακοπή σε εξέλιξη</span>
          <strong><?= e($i['name']) ?></strong>
          <span style="font-size:14px">Η υπηρεσία δεν λειτουργεί από <?= e($local($i['start'])) ?> (<?= e($dur($i['minutes'])) ?>). Η ομάδα μας έχει ενημερωθεί και εργάζεται για την αποκατάσταση.</span>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>

  <?php if ($upcoming): ?>
    <section class="card" aria-label="Επερχόμενες συντηρήσεις">
      <div class="section-title"><h2>Επερχόμενες συντηρήσεις & ανακοινώσεις</h2></div>
      <?php foreach ($upcoming as $n): [$kl, $kc] = StatusPage::NOTICE_KINDS[$n['kind']]; ?>
        <div class="notice" style="border-left-color:<?= $kc ?>">
          <span class="kind" style="color:<?= $kc ?>"><?= e($kl) ?></span>
          <strong><?= e($n['title']) ?></strong>
          <?php if ($n['body']): ?><span style="white-space:pre-line;font-size:14px"><?= e($n['body']) ?></span><?php endif; ?>
          <span class="muted xs"><?= e($local($n['starts_at'])) ?><?= $n['ends_at'] ? ' → ' . e($local($n['ends_at'])) : '' ?> · <?= e($tz) ?></span>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>

  <?php if (!$groups): ?>
    <section class="card"><div class="empty">Δεν έχουν δημοσιευτεί υπηρεσίες ακόμα.</div></section>
  <?php else: ?>
    <div class="toolbar">
      <div class="chips" role="group" aria-label="Φίλτρο υπηρεσιών">
        <button class="chip" type="button" aria-pressed="true" data-filter="all">Όλες <?= $total ?></button>
        <?php if ($issues): ?><button class="chip" type="button" aria-pressed="false" data-filter="issues">Με προβλήματα <?= $issues ?></button><?php endif; ?>
        <?php if (count($groupNames) > 1): foreach ($groupNames as $g): ?>
          <button class="chip" type="button" aria-pressed="false" data-filter="g:<?= e((string) $g) ?>"><?= e($g !== '' ? (string) $g : 'Λοιπές') ?></button>
        <?php endforeach; endif; ?>
      </div>
      <div class="chips">
        <?php if ($total > 4): ?><label class="search"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg><span class="sr">Αναζήτηση</span><input type="search" data-q placeholder="Αναζήτηση υπηρεσίας"></label><?php endif; ?>
        <div class="seg" role="group" aria-label="Περίοδος">
          <?php foreach ([7, 30, 90] as $p): ?><button type="button" data-days="<?= $p ?>" aria-pressed="<?= $p === $days ? 'true' : 'false' ?>"><?= $p ?> ημ.</button><?php endforeach; ?>
        </div>
      </div>
    </div>

    <?php foreach ($groups as $g => $items):
        $gIssues = count(array_filter($items, fn ($c) => in_array($c['state'], ['down', 'degraded'], true)));
        $open = $o['collapse_groups'] !== '1' || $gIssues > 0; ?>
      <details class="card group" data-group="<?= e((string) $g) ?>" <?= $open ? 'open' : '' ?>>
        <summary>
          <svg class="chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
          <span class="gname grow"><?= e($g !== '' ? (string) $g : (count($groups) > 1 ? 'Λοιπές υπηρεσίες' : 'Υπηρεσίες')) ?></span>
          <span class="state" style="color:<?= $gIssues ? '#D97706' : '#16A34A' ?>"><i style="background:currentColor"></i><?= $gIssues ? $gIssues . ' με προβλήματα' : 'Όλες λειτουργούν' ?></span>
        </summary>
        <?php foreach ($items as $c): [$sl, $sc] = $stateLabel[$c['state']]; ?>
          <div class="comp" data-comp="<?= (int) $c['id'] ?>" data-state="<?= e($c['state']) ?>" data-name="<?= e(mb_strtolower($c['name'])) ?>">
            <div class="comp-head">
              <span class="comp-name"><?= e($c['name']) ?></span>
              <span class="meta">
                <?php if ($o['show_response'] === '1' && $c['response_ms'] !== null): ?><span title="Μέσος χρόνος απόκρισης 24 ωρών">⏱ <?= e(fmt_ms($c['response_ms'])) ?></span><?php endif; ?>
                <?php if ($o['show_uptime'] === '1'): ?><span data-uptime title="Διαθεσιμότητα περιόδου">—</span><?php endif; ?>
                <span class="state<?= $c['state'] === 'down' ? ' pulse' : '' ?>" style="color:<?= $sc ?>"><i style="background:<?= $sc ?>"></i><?= e($sl) ?></span>
              </span>
            </div>
            <div class="bars" data-bars role="img" aria-label="Διαθεσιμότητα ανά ημέρα"></div>
            <div class="axis"><span data-from><?= $days ?> ημέρες πριν</span><span>Σήμερα</span></div>
          </div>
        <?php endforeach; ?>
      </details>
    <?php endforeach; ?>
    <p class="muted xs" data-none hidden style="text-align:center">Καμία υπηρεσία με αυτά τα φίλτρα.</p>
    <div class="legend" aria-label="Υπόμνημα">
      <span><i style="background:#22C55E"></i>Χωρίς προβλήματα</span><span><i style="background:#84CC16"></i>Λιγότερο από 5% αποτυχίες</span>
      <span><i style="background:#F59E0B"></i>Λιγότερο από 20%</span><span><i style="background:#EF4444"></i>Σοβαρή διακοπή</span><span><i style="background:var(--none)"></i>Χωρίς δεδομένα</span>
    </div>
  <?php endif; ?>

  <?php
  $timeline = [];
  if ($o['show_history'] === '1') {
      foreach ($history as $i) {
          if (!$i['ongoing']) {
              $timeline[] = [$i['start'], 'incident', $i];
          }
      }
  }
  foreach ($pastNotices as $n) {
      $timeline[] = [$n['starts_at'], 'notice', $n];
  }
  usort($timeline, fn ($a, $b) => strcmp($b[0], $a[0]));
  ?>
  <?php if ($o['show_history'] === '1' || $pastNotices): ?>
    <section class="card" aria-label="Ιστορικό">
      <div class="section-title"><h2>Ιστορικό συμβάντων</h2><span class="muted xs">τελευταίες 30 ημέρες</span></div>
      <?php if (!$timeline): ?>
        <div class="empty">✓ Κανένα συμβάν τις τελευταίες 30 ημέρες.</div>
      <?php endif; ?>
      <?php foreach (array_slice($timeline, 0, 25) as [$t, $type, $x]): ?>
        <?php if ($type === 'incident'): ?>
          <div class="hist"><span class="dot" style="background:#EF4444"></span>
            <div><strong><?= e($x['name']) ?></strong> · διακοπή <?= e($dur($x['minutes'])) ?><div class="muted xs"><?= e($local($x['start'])) ?> → <?= e($local($x['end'])) ?> · επιλύθηκε</div></div>
            <span class="muted xs"><?= e($local($x['start'], 'd/m')) ?></span></div>
        <?php else: [$kl, $kc] = StatusPage::NOTICE_KINDS[$x['kind']]; ?>
          <div class="hist"><span class="dot" style="background:<?= $kc ?>"></span>
            <div><strong><?= e($x['title']) ?></strong> · <?= e(mb_strtolower($kl)) ?><?php if ($x['body']): ?><div class="muted xs" style="white-space:pre-line"><?= e($x['body']) ?></div><?php endif; ?></div>
            <span class="muted xs"><?= e($local($x['starts_at'], 'd/m')) ?></span></div>
        <?php endif; ?>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>

  <footer>
    <span>Ώρες σε ζώνη <?= e($tz) ?> · <a href="<?= e(url('status/' . $slug . '.rss')) ?>">RSS</a> · <a href="<?= e(url('status/' . $slug . '.json')) ?>">JSON</a></span>
    <?php if ($o['hide_powered'] !== '1'): ?><span>Powered by <?= e(App::name()) ?></span><?php endif; ?>
  </footer>
</div>
<div class="tip" data-tip hidden role="tooltip"></div>
<script type="application/json" id="status-data"><?= json_encode($js, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script>
(function () {
  const data = JSON.parse(document.getElementById('status-data').textContent);
  const root = document.documentElement;
  const months = ['Ιαν', 'Φεβ', 'Μαρ', 'Απρ', 'Μαΐ', 'Ιουν', 'Ιουλ', 'Αυγ', 'Σεπ', 'Οκτ', 'Νοε', 'Δεκ'];
  const fmtDate = (d) => { const p = d.split('-'); return +p[2] + ' ' + months[+p[1] - 1] + ' ' + p[0]; };
  const pct = (v) => (v >= 99.995 ? '100' : v.toFixed(2).replace('.', ',')) + '%';
  const color = (ok, fail) => {
    if (!ok && !fail) return 'var(--none)';
    const r = ok / (ok + fail);
    return r >= 0.999 ? '#22C55E' : r >= 0.95 ? '#84CC16' : r >= 0.8 ? '#F59E0B' : '#EF4444';
  };
  let days = +(document.querySelector('[data-days][aria-pressed="true"]') || { dataset: { days: 90 } }).dataset.days;
  try { const saved = +localStorage.getItem('statusDays'); if ([7, 30, 90].includes(saved)) days = saved; } catch (e) { /* ignore */ }

  function render() {
    document.querySelectorAll('[data-comp]').forEach((c) => {
      const rows = (data[c.dataset.comp] || []).slice(-days);
      let ok = 0, fail = 0;
      const bars = c.querySelector('[data-bars]');
      bars.innerHTML = rows.map((r, i) => { ok += r[1]; fail += r[2]; return '<span tabindex="0" data-i="' + i + '" style="background:' + color(r[1], r[2]) + '"></span>'; }).join('');
      c._rows = rows;
      const up = ok + fail ? pct(100 * ok / (ok + fail)) : null;
      const u = c.querySelector('[data-uptime]');
      if (u) u.textContent = up ? up + ' uptime' : 'χωρίς δεδομένα';
      c.querySelector('[data-from]').textContent = days + ' ημέρες πριν';
      bars.setAttribute('aria-label', 'Διαθεσιμότητα ' + days + ' ημερών: ' + (up || 'χωρίς δεδομένα'));
    });
    document.querySelectorAll('[data-days]').forEach((b) => b.setAttribute('aria-pressed', +b.dataset.days === days ? 'true' : 'false'));
  }
  document.querySelectorAll('[data-days]').forEach((b) => b.addEventListener('click', () => {
    days = +b.dataset.days;
    try { localStorage.setItem('statusDays', String(days)); } catch (e) { /* ignore */ }
    render();
  }));

  // Tooltip per day: hover, keyboard focus or tap.
  const tip = document.querySelector('[data-tip]');
  const showTip = (el) => {
    const c = el.closest('[data-comp]');
    const r = c._rows[+el.dataset.i];
    const total = r[1] + r[2];
    tip.innerHTML = '<b>' + fmtDate(r[0]) + '</b>' + (total
      ? pct(100 * r[1] / total) + ' επιτυχία · ' + total + ' έλεγχοι'
        + (r[2] ? '<br><span style="color:#FCA5A5">' + r[2] + ' αποτυχίες</span>' : '<br><span style="color:#86EFAC">Χωρίς προβλήματα</span>')
        + (r[3] ? '<br>Μέση απόκριση ' + (r[3] >= 1000 ? (r[3] / 1000).toFixed(2).replace('.', ',') + ' s' : r[3] + ' ms') : '')
      : 'Χωρίς δεδομένα');
    tip.hidden = false;
    const b = el.getBoundingClientRect();
    const w = tip.offsetWidth;
    tip.style.left = Math.max(8, Math.min(window.innerWidth - w - 8, b.left + b.width / 2 - w / 2)) + 'px';
    tip.style.top = (b.top - tip.offsetHeight - 8 < 8 ? b.bottom + 8 : b.top - tip.offsetHeight - 8) + 'px';
  };
  const barAt = (t) => t.closest && t.closest('[data-bars] span');
  document.addEventListener('mouseover', (e) => { const s = barAt(e.target); if (s) showTip(s); });
  document.addEventListener('focusin', (e) => { const s = barAt(e.target); if (s) showTip(s); });
  document.addEventListener('click', (e) => { const s = barAt(e.target); if (s) showTip(s); else tip.hidden = true; });
  document.addEventListener('mouseout', (e) => { if (barAt(e.target)) tip.hidden = true; });
  window.addEventListener('scroll', () => { tip.hidden = true; }, { passive: true });

  // Filters: status/group chips and search.
  let filter = 'all';
  const q = document.querySelector('[data-q]');
  function applyFilter() {
    const t = (q ? q.value : '').trim().toLowerCase();
    let shown = 0;
    document.querySelectorAll('details.group').forEach((g) => {
      let any = 0;
      g.querySelectorAll('[data-comp]').forEach((c) => {
        const byFilter = filter === 'all'
          || (filter === 'issues' && (c.dataset.state === 'down' || c.dataset.state === 'degraded'))
          || (filter.indexOf('g:') === 0 && g.dataset.group === filter.slice(2));
        const ok = byFilter && (!t || c.dataset.name.indexOf(t) !== -1);
        c.hidden = !ok;
        any += ok ? 1 : 0;
      });
      g.hidden = !any;
      if (any && (t || filter !== 'all')) g.open = true;
      shown += any;
    });
    const none = document.querySelector('[data-none]');
    if (none) none.hidden = shown > 0;
  }
  document.querySelectorAll('[data-filter]').forEach((b) => b.addEventListener('click', () => {
    filter = b.dataset.filter;
    document.querySelectorAll('[data-filter]').forEach((x) => x.setAttribute('aria-pressed', x === b ? 'true' : 'false'));
    applyFilter();
  }));
  if (q) q.addEventListener('input', applyFilter);

  // Theme toggle, remembered per visitor.
  try { const th = localStorage.getItem('statusTheme'); if (th) root.dataset.theme = th; } catch (e) { /* ignore */ }
  document.querySelector('[data-theme-btn]').addEventListener('click', () => {
    const dark = root.dataset.theme ? root.dataset.theme === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
    root.dataset.theme = dark ? 'light' : 'dark';
    try { localStorage.setItem('statusTheme', root.dataset.theme); } catch (e) { /* ignore */ }
  });

  // Auto refresh with countdown, paused while the tab is hidden or a filter is in use.
  let left = 60;
  const cd = document.querySelector('[data-countdown]');
  setInterval(() => {
    if (document.hidden) return;
    left -= 1;
    if (cd) cd.textContent = String(Math.max(0, left));
    if (left <= 0) location.reload();
  }, 1000);

  render();
  applyFilter();
})();
</script>
</body>
</html>
