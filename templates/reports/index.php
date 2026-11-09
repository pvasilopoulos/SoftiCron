<?php
/** @var array $r @var array $f @var array $groups @var int $fromTs @var int $toTs @var bool $canEmail */
use App\Auth\Auth;
use App\Controllers\ReportController;
use App\Services\Reports;

$tz = new DateTimeZone(Auth::tenant()['timezone']);
$fmtD = fn (int $ts) => (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('d/m/Y');
$t = $r['totals'];
$q = array_filter($f, fn ($v) => $v !== '');
$maxDay = max(1, ...array_map(fn ($d) => $d['ok'] + $d['failed'], $r['daily'] ?: [['ok' => 0, 'failed' => 0]]));
$uptimeColor = fn (?float $u, ?float $sla = null) => $u === null ? 'var(--muted)' : (($sla !== null && $u < $sla) || $u < 95 ? 'var(--fail-fg)' : ($u < 99 ? 'var(--warn-fg)' : 'var(--ok-fg)'));
?>
<div class="page-head">
  <div>
    <h1>Αναφορές & SLA</h1>
    <p class="sub"><?= e($fmtD($fromTs)) ?> – <?= e($fmtD($toTs)) ?><?= $f['group'] !== '' ? ' · ομάδα ' . e($f['group'] === '__none' ? 'Χωρίς ομάδα' : $f['group']) : '' ?></p>
  </div>
  <div class="row no-print">
    <?php if (!empty($canExport)): ?>
    <a class="btn" href="<?= e(url('reports/export', $q)) ?>"><?= icon('copy', 16) ?>CSV</a>
    <?php endif; ?>
    <button class="btn" type="button" onclick="window.print()"><?= icon('templates', 16) ?>Εκτύπωση / PDF</button>
    <?php if ($canEmail): ?>
      <form method="post" action="<?= e(url('reports/email', $q)) ?>" class="inline" data-loading><?= csrf_field() ?><button class="btn" type="submit"><?= icon('send', 16) ?>Αποστολή με email</button></form>
    <?php endif; ?>
  </div>
</div>

<form class="card filters no-print" method="get" action="<?= e(url('reports')) ?>">
  <div class="filters-main">
    <div class="seg" role="group" aria-label="Περίοδος">
      <?php foreach (ReportController::PERIODS as $k => $l): if ($k === 'custom') { continue; } ?>
        <a class="<?= $f['period'] === $k ? 'on' : '' ?>" href="<?= e(url('reports', ['period' => $k, 'group' => $f['group']])) ?>"><?= e($l) ?></a>
      <?php endforeach; ?>
    </div>
    <label class="row small" style="gap:6px">Από <input class="input" type="date" name="from" value="<?= e($f['period'] === 'custom' ? $f['from'] : '') ?>" style="width:auto;min-height:38px"></label>
    <label class="row small" style="gap:6px">Έως <input class="input" type="date" name="to" value="<?= e($f['period'] === 'custom' ? $f['to'] : '') ?>" style="width:auto;min-height:38px"></label>
    <input type="hidden" name="period" value="custom">
    <?php if ($groups): ?>
      <label class="sr-only" for="rep-group">Ομάδα</label>
      <select id="rep-group" class="select" name="group" style="width:auto"><option value="">Όλες οι ομάδες</option><?php foreach ($groups as $g): ?><option value="<?= e($g) ?>" <?= $f['group'] === $g ? 'selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?><option value="__none" <?= $f['group'] === '__none' ? 'selected' : '' ?>>Χωρίς ομάδα</option></select>
    <?php endif; ?>
    <button class="btn btn-primary" type="submit">Εφαρμογή</button>
  </div>
</form>

<div class="kpis">
  <div class="card kpi"><span class="label">Διαθεσιμότητα (uptime)</span><span class="value" style="color:<?= $uptimeColor($t['uptime']) ?>"><?= e(Reports::pct($t['uptime'], 3)) ?></span><span class="delta">Downtime <?= (int) $t['downtime_min'] ?> λεπτά</span></div>
  <div class="card kpi"><span class="label">Ποσοστό επιτυχίας</span><span class="value"><?= e(Reports::pct($t['success_rate'])) ?></span><span class="delta"><?= (int) $t['runs'] ?> εκτελέσεις · <?= (int) $t['failed'] ?> αποτυχίες</span></div>
  <div class="card kpi"><span class="label">SLA εντός στόχου</span><span class="value" style="color:<?= $t['sla_total'] && $t['sla_met'] < $t['sla_total'] ? 'var(--fail-fg)' : 'inherit' ?>"><?= $t['sla_total'] ? (int) $t['sla_met'] . ' / ' . (int) $t['sla_total'] : '—' ?></span><span class="delta"><?= $t['sla_total'] ? 'jobs με στόχο SLA' : 'Όρισε στόχο SLA στα jobs' ?></span></div>
  <div class="card kpi"><span class="label">Jobs στην αναφορά</span><span class="value"><?= count($r['jobs']) ?></span><span class="delta"><?= count(array_filter($r['jobs'], fn ($j) => $j['failed'] > 0)) ?> με αποτυχίες</span></div>
</div>

<section class="card">
  <div class="card-head"><h2>Εκτελέσεις ανά ημέρα</h2><div class="legend"><span><i style="background:var(--accent)"></i>Επιτυχίες</span><span><i style="background:#DC2626"></i>Αποτυχίες</span></div></div>
  <div class="chart" style="height:140px">
    <?php foreach ($r['daily'] as $d): ?>
      <div class="col" title="<?= e($d['label'] . ' · ' . $d['ok'] . ' επιτυχίες, ' . $d['failed'] . ' αποτυχίες') ?>">
        <?php if ($d['failed']): ?><div class="fail" style="height:<?= max(3, (int) round(120 * $d['failed'] / $maxDay)) ?>px"></div><?php endif; ?>
        <div class="ok" style="height:<?= (int) round(120 * $d['ok'] / $maxDay) ?>px"></div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if ($r['daily']): ?><div class="chart-axis"><span><?= e($r['daily'][0]['label']) ?></span><span><?= e($r['daily'][(int) floor(count($r['daily']) / 2)]['label']) ?></span><span><?= e(end($r['daily'])['label']) ?></span></div><?php endif; ?>
</section>

<section class="card flush">
  <?php if (!$r['jobs']): ?>
    <div class="empty"><?= icon('runs', 40) ?><span>Δεν υπάρχουν jobs για αυτή την επιλογή.</span></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table rtable">
        <thead><tr><th>Job</th><th>Uptime</th><th>SLA</th><th>Επιτυχία</th><th>Εκτελέσεις</th><th>Downtime</th><th>Μέση / p95</th></tr></thead>
        <tbody>
          <?php $lastGroup = false; foreach ($r['jobs'] as $j):
              if ($j['group'] !== $lastGroup && ($groups || $j['group'] !== null)): $lastGroup = $j['group']; ?>
                <tr class="group-row"><td colspan="7"><strong><?= e($j['group'] ?? 'Χωρίς ομάδα') ?></strong></td></tr>
              <?php endif; ?>
            <tr>
              <td class="primary"><a class="strong" href="<?= e(url('jobs/' . $j['id'] . '/edit')) ?>"><?= e($j['name']) ?></a><?php if ($j['type'] === 'heartbeat'): ?> <span class="pill">HB</span><?php endif; ?><?php if (!$j['enabled']): ?> <span class="badge off">Σε παύση</span><?php endif; ?></td>
              <td data-label="Uptime">
                <strong style="color:<?= $uptimeColor($j['uptime'], $j['sla']) ?>"><?= e(Reports::pct($j['uptime'], 3)) ?></strong>
                <?php if ($j['uptime'] !== null): ?><div class="dur-bar" aria-hidden="true" style="width:90px"><span style="width:<?= max(2, (int) round($j['uptime'])) ?>%;background:<?= $uptimeColor($j['uptime'], $j['sla']) ?>"></span></div><?php endif; ?>
              </td>
              <td data-label="SLA">
                <?php if ($j['sla'] === null): ?><span class="muted xs">—</span>
                <?php elseif ($j['sla_met'] === null): ?><span class="xs muted">στόχος <?= e(Reports::pct($j['sla'])) ?></span>
                <?php else: ?><span class="badge <?= $j['sla_met'] ? 'ok' : 'fail' ?>"><?= $j['sla_met'] ? '✓ Εντός' : '✗ Εκτός' ?> <?= e(Reports::pct($j['sla'])) ?></span><?php endif; ?>
              </td>
              <td data-label="Επιτυχία" class="small"><?= e(Reports::pct($j['success_rate'], 1)) ?></td>
              <td data-label="Εκτελέσεις" class="small"><a href="<?= e(url('runs', ['job' => $j['id']])) ?>"><?= (int) $j['runs'] ?></a><?= $j['failed'] ? ' · <a href="' . e(url('runs', ['job' => $j['id'], 'status' => 'failed'])) . '" style="color:var(--fail-fg)">' . (int) $j['failed'] . ' αποτυχίες</a>' : '' ?></td>
              <td data-label="Downtime" class="small"><?= $j['downtime_min'] ? (int) $j['downtime_min'] . ' λεπτά' : '—' ?></td>
              <td data-label="Μέση / p95" class="small nowrap"><?= e(fmt_ms($j['avg_ms'])) ?> / <?= e(fmt_ms($j['p95_ms'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<p class="xs muted">Uptime: ποσοστό του χρόνου που το job δεν βρισκόταν σε αποτυχία (από την πρώτη αποτυχία μέχρι την επόμενη επιτυχία). Ο στόχος SLA ορίζεται σε κάθε job.</p>
