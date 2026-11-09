<?php
/** @var array $jobCounts @var array $cur @var array $prev @var ?float $rate @var ?float $prevRate @var array $bars @var int $max @var array $attention @var array $recent @var array $upcoming @var string $range */
use App\Auth\Auth;
use App\Services\CronHelper;

$tenant = Auth::tenant();
$tz = $tenant['timezone'];
$days = ['Κυριακή', 'Δευτέρα', 'Τρίτη', 'Τετάρτη', 'Πέμπτη', 'Παρασκευή', 'Σάββατο'];
$months = ['Ιανουαρίου', 'Φεβρουαρίου', 'Μαρτίου', 'Απριλίου', 'Μαΐου', 'Ιουνίου', 'Ιουλίου', 'Αυγούστου', 'Σεπτεμβρίου', 'Οκτωβρίου', 'Νοεμβρίου', 'Δεκεμβρίου'];
$now = new DateTimeImmutable('now', new DateTimeZone($tz));
$rangeLabel = ['24h' => '24 ώρες', '7d' => '7 ημέρες', '30d' => '30 ημέρες'][$range];
$delta = function (?float $a, ?float $b, string $unit, bool $higherIsGood): array {
    if ($a === null || $b === null) {
        return ['—', ''];
    }
    $d = $a - $b;
    if (abs($d) < 0.05) {
        return ['Σταθερό', ''];
    }
    $good = $higherIsGood ? $d > 0 : $d < 0;
    return [($d > 0 ? '▲ ' : '▼ ') . number_format(abs($d), $unit === '%' ? 1 : 0, ',', '.') . $unit . ' από την προηγούμενη περίοδο', $good ? 'up' : 'down'];
};
[$rateTxt, $rateCls] = $delta($rate, $prevRate, '%', true);
[$failTxt, $failCls] = $delta((float) $cur['failed'], (float) $prev['failed'], '', false);
[$avgTxt, $avgCls] = $delta($cur['avg_ms'] !== null ? (float) $cur['avg_ms'] : null, $prev['avg_ms'] !== null ? (float) $prev['avg_ms'] : null, ' ms', false);
?>
<div class="page-head" data-autorefresh="30">
  <div>
    <h1>Επισκόπηση</h1>
    <p class="sub"><?= e($days[(int) $now->format('w')] . ' ' . $now->format('j') . ' ' . $months[(int) $now->format('n') - 1] . ' · ' . $now->format('H:i') . ' · ' . $tz) ?></p>
  </div>
  <div class="seg" role="group" aria-label="Περίοδος">
    <?php foreach (['24h' => '24 ώρες', '7d' => '7 ημέρες', '30d' => '30 ημέρες'] as $k => $l): ?>
      <a href="<?= e(url('/', ['range' => $k])) ?>" class="<?= $range === $k ? 'on' : '' ?>"><?= e($l) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<?php if (!$schedulerOk): ?>
  <div class="notice"><strong>Ο scheduler δεν τρέχει.</strong> <?= $lastTick ? 'Τελευταία εκτέλεση ' . e(rel_time($lastTick)) . '.' : 'Δεν έχει τρέξει ποτέ.' ?> Τα jobs δεν θα εκτελούνται μέχρι να ρυθμιστεί το Scheduled Task <code>php bin/cron.php</code> κάθε λεπτό.</div>
<?php endif; ?>

<div class="kpis">
  <div class="card kpi"><span class="label">Ενεργά jobs</span><span class="value"><?= (int) $jobCounts['active'] ?></span><span class="delta"><?= (int) $jobCounts['total'] ?> σύνολο · <?= (int) $jobCounts['total'] - (int) $jobCounts['active'] ?> σε παύση</span></div>
  <div class="card kpi"><span class="label">Ποσοστό επιτυχίας · <?= e($rangeLabel) ?></span><span class="value"><?= $rate === null ? '—' : e(number_format($rate, 1, ',', '.')) . '%' ?></span><span class="delta <?= $rateCls ?>"><?= e($rateTxt) ?></span></div>
  <div class="card kpi"><span class="label">Αποτυχίες</span><span class="value"><?= (int) $cur['failed'] ?></span><span class="delta <?= $failCls ?>"><?= e($failTxt) ?></span></div>
  <div class="card kpi"><span class="label">Μέση απόκριση</span><span class="value"><?= e(fmt_ms($cur['avg_ms'] !== null ? (int) round((float) $cur['avg_ms']) : null)) ?></span><span class="delta <?= $avgCls ?>"><?= e($avgTxt) ?></span></div>
</div>

<div class="split-even">
  <section class="card">
    <div class="card-head">
      <h2>Εκτελέσεις ανά <?= $range === '24h' ? 'ώρα' : 'ημέρα' ?></h2>
      <div class="legend"><span><i style="background:var(--accent)"></i>Επιτυχίες</span><span><i style="background:#DC2626"></i>Αποτυχίες</span></div>
    </div>
    <div class="chart" role="img" aria-label="Γράφημα εκτελέσεων: <?= (int) $cur['total'] ?> συνολικά, <?= (int) $cur['failed'] ?> αποτυχίες">
      <?php foreach ($bars as $b): ?>
        <div class="col" title="<?= e($b['label'] . ' · ' . $b['ok'] . ' επιτυχίες, ' . $b['failed'] . ' αποτυχίες') ?>">
          <?php if ($b['failed']): ?><div class="fail" style="height:<?= max(4, (int) round(140 * $b['failed'] / $max)) ?>px"></div><?php endif; ?>
          <div class="ok" style="height:<?= (int) round(140 * $b['ok'] / $max) ?>px"></div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="chart-axis"><span><?= e($bars[0]['label']) ?></span><span><?= e($bars[(int) floor(count($bars) / 2)]['label']) ?></span><span>Τώρα</span></div>
  </section>

  <section class="card">
    <div class="card-head"><h2>Χρειάζονται προσοχή</h2><?php if ($attention): ?><span class="badge fail"><?= count($attention) ?></span><?php endif; ?></div>
    <?php if (!$attention): ?>
      <div class="empty" style="padding:20px"><?= icon('check', 32) ?><span>Όλα λειτουργούν κανονικά.</span></div>
    <?php else: ?>
      <div class="stack-sm">
        <?php foreach ($attention as $a):
            $color = $a['run_status'] === 'failed' ? '#DC2626' : ($a['run_status'] === 'missed' ? '#64748B' : '#D97706');
            $text = $a['run_status'] === 'failed'
                ? 'Απέτυχε' . ($a['attempts'] > 1 ? ' ' . (int) $a['attempts'] . ' προσπάθειες' : '') . ($a['http_status'] ? ' · HTTP ' . (int) $a['http_status'] : '') . ' · ' . fmt_dt($a['last_run_at'], $tz, 'H:i')
                : ($a['run_status'] === 'missed' ? 'Χαμένη εκτέλεση' : 'Αργή απόκριση ' . fmt_ms((int) $a['duration_ms']));
        ?>
          <div class="alert-item">
            <span class="bullet" style="background:<?= $color ?>"></span>
            <div class="stack-sm" style="gap:2px;min-width:0">
              <strong class="small"><?= e($a['name']) ?></strong>
              <span class="small muted"><?= e($text) ?></span>
              <a class="small" href="<?= e(url('runs/' . $a['run_id'])) ?>" style="font-weight:600">Προβολή εκτέλεσης</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>

<div class="split-even">
  <section class="card flush">
    <div class="card-head"><h2>Πρόσφατες εκτελέσεις</h2><?php if (Auth::can('runs.view')): ?><a class="small" href="<?= e(url('runs')) ?>" style="font-weight:600">Όλες οι εκτελέσεις</a><?php endif; ?></div>
    <?php if (!$recent): ?>
      <div class="empty"><?= icon('runs', 32) ?><span>Δεν υπάρχουν εκτελέσεις ακόμα.</span><?php if (Auth::can('jobs.create')): ?><a class="btn btn-primary" href="<?= e(url('jobs/new')) ?>">Δημιούργησε το πρώτο job</a><?php endif; ?></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table rtable">
          <thead><tr><th>Job</th><th>Κατάσταση</th><th>HTTP</th><th>Διάρκεια</th><th class="num">Έναρξη</th></tr></thead>
          <tbody>
            <?php foreach ($recent as $r): ?>
              <tr>
                <td class="primary"><div class="row" style="gap:8px;flex-wrap:nowrap"><span class="pill"><?= e($r['method']) ?></span><a class="strong" href="<?= e(url('runs/' . $r['id'])) ?>"><?= e($r['job_name']) ?></a></div></td>
                <td data-label="Κατάσταση"><?= status_badge($r['status'], (bool) $r['is_slow']) ?></td>
                <td data-label="HTTP" class="mono small"><?= $r['http_status'] ? (int) $r['http_status'] : '—' ?></td>
                <td data-label="Διάρκεια"><?= e(fmt_ms($r['duration_ms'] !== null ? (int) $r['duration_ms'] : null)) ?></td>
                <td data-label="Έναρξη" class="num muted"><?= e(fmt_dt($r['started_at'] ?? $r['created_at'], $tz, 'd/m H:i')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2 style="margin-bottom:8px">Επόμενες εκτελέσεις</h2>
    <?php if (!$upcoming): ?>
      <p class="muted small">Δεν υπάρχουν προγραμματισμένες εκτελέσεις.</p>
    <?php endif; ?>
    <?php foreach ($upcoming as $u): ?>
      <div class="list-item">
        <span class="time-box"><?= e(fmt_dt($u['next_run_at'], $tz, 'H:i')) ?></span>
        <div class="stack-sm" style="gap:1px;min-width:0">
          <a class="strong small" style="color:var(--text);font-weight:600" href="<?= e(url('jobs/' . $u['id'] . '/edit')) ?>"><?= e($u['name']) ?></a>
          <span class="xs muted"><?= e(rel_time($u['next_run_at'])) ?> · <?= e(CronHelper::describe($u['cron_expr'])) ?></span>
        </div>
      </div>
    <?php endforeach; ?>
  </section>
</div>
