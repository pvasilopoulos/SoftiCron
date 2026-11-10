<?php
/**
 * @var string $tz @var DateTimeImmutable $now @var DateTimeImmutable $first @var DateTimeImmutable $next
 * @var string $month @var string $day @var array $past @var array $planned @var array $dense
 * @var array $hours @var int $peak @var array $perJob @var array $jobs
 * @var string $group @var int $jobFilter @var array $groups @var array $allJobs
 */
$MONTHS = ['Ιανουάριος', 'Φεβρουάριος', 'Μάρτιος', 'Απρίλιος', 'Μάιος', 'Ιούνιος', 'Ιούλιος', 'Αύγουστος', 'Σεπτέμβριος', 'Οκτώβριος', 'Νοέμβριος', 'Δεκέμβριος'];
$MONTHS_GEN = ['Ιανουαρίου', 'Φεβρουαρίου', 'Μαρτίου', 'Απριλίου', 'Μαΐου', 'Ιουνίου', 'Ιουλίου', 'Αυγούστου', 'Σεπτεμβρίου', 'Οκτωβρίου', 'Νοεμβρίου', 'Δεκεμβρίου'];
$DOW = ['Δευ', 'Τρι', 'Τετ', 'Πεμ', 'Παρ', 'Σαβ', 'Κυρ'];
$DOW_FULL = ['Δευτέρα', 'Τρίτη', 'Τετάρτη', 'Πέμπτη', 'Παρασκευή', 'Σάββατο', 'Κυριακή'];
$q = array_filter(['group' => $group, 'job' => $jobFilter ?: '']);
$link = fn (array $over) => url('calendar', array_filter(array_merge(['month' => $month] + $q, $over), fn ($v) => $v !== '' && $v !== null));
$prev = $first->modify('-1 month')->format('Y-m');
$nextM = $next->format('Y-m');
$today = $now->format('Y-m-d');
// Day cells, Monday first.
$lead = (int) $first->format('N') - 1;
$daysIn = (int) $first->format('t');
$maxTotal = 1;
for ($i = 1; $i <= $daysIn; $i++) {
    $d = $first->format('Y-m-') . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
    $p = $past[$d] ?? ['ok' => 0, 'failed' => 0, 'other' => 0];
    $maxTotal = max($maxTotal, $p['ok'] + $p['failed'] + $p['other'] + ($planned[$d] ?? 0));
}
$selD = new DateTimeImmutable($day . ' 00:00:00', new DateTimeZone($tz));
$selTotals = ['done' => array_sum(array_column($hours, 'done')), 'failed' => array_sum(array_column($hours, 'failed')), 'planned' => array_sum(array_column($hours, 'planned'))];
$peakHour = null;
foreach ($hours as $h => $v) {
    if ($peak > 0 && $v['done'] + $v['planned'] === $peak) {
        $peakHour = $h;
        break;
    }
}
$nf = fn (int $n) => number_format($n, 0, ',', '.');
?>
<div class="page-head">
  <div>
    <h1>Ημερολόγιο</h1>
    <p class="sub">Εκτελέσεις που έγιναν και προγραμματισμένες, ανά ημέρα και ώρα · <?= e($tz) ?></p>
  </div>
  <form class="row cal-filters" method="get" action="<?= e(url('calendar')) ?>">
    <input type="hidden" name="month" value="<?= e($month) ?>">
    <?php if ($groups): ?>
      <label class="sr-only" for="cal-group">Ομάδα</label>
      <select id="cal-group" class="select" name="group" data-autosubmit>
        <option value="">Όλες οι ομάδες</option>
        <?php foreach ($groups as $g): ?><option value="<?= e($g) ?>" <?= $group === $g ? 'selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?>
        <option value="__none" <?= $group === '__none' ? 'selected' : '' ?>>Χωρίς ομάδα</option>
      </select>
    <?php endif; ?>
    <label class="sr-only" for="cal-job">Job</label>
    <select id="cal-job" class="select" name="job" data-autosubmit>
      <option value="">Όλα τα jobs</option>
      <?php foreach ($allJobs as $j): ?><option value="<?= (int) $j['id'] ?>" <?= $jobFilter === (int) $j['id'] ? 'selected' : '' ?>><?= e($j['name']) ?></option><?php endforeach; ?>
    </select>
  </form>
</div>

<div class="cal-layout">
  <section class="card cal-month">
    <div class="cal-nav">
      <a class="btn btn-sm btn-icon" href="<?= e($link(['month' => $prev, 'day' => ''])) ?>" aria-label="Προηγούμενος μήνας"><?= icon('chevron-left', 16) ?></a>
      <h2><?= e($MONTHS[(int) $first->format('n') - 1] . ' ' . $first->format('Y')) ?></h2>
      <a class="btn btn-sm btn-icon" href="<?= e($link(['month' => $nextM, 'day' => ''])) ?>" aria-label="Επόμενος μήνας"><?= icon('chevron-right', 16) ?></a>
      <span class="grow"></span>
      <?php if ($month !== $now->format('Y-m') || $day !== $today): ?><a class="btn btn-sm" href="<?= e(url('calendar', $q)) ?>">Σήμερα</a><?php endif; ?>
    </div>
    <div class="cal-grid" role="grid" aria-label="Ημέρες μήνα">
      <?php foreach ($DOW as $i => $d): ?><div class="cal-dow<?= $i >= 5 ? ' we' : '' ?>" role="columnheader"><?= $d ?></div><?php endforeach; ?>
      <?php for ($i = 0; $i < $lead; $i++): ?><div class="cal-day blank" aria-hidden="true"></div><?php endfor; ?>
      <?php for ($i = 1; $i <= $daysIn; $i++):
          $d = $first->format('Y-m-') . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
          $p = $past[$d] ?? ['ok' => 0, 'failed' => 0, 'other' => 0];
          $pl = (int) ($planned[$d] ?? 0);
          $done = $p['ok'] + $p['failed'] + $p['other'];
          $heat = ($done + $pl) / $maxTotal;
          $cls = ['cal-day'];
          if ($d === $today) { $cls[] = 'today'; }
          if ($d === $day) { $cls[] = 'sel'; }
          if ($d < $today) { $cls[] = 'past'; }
          if ($p['failed'] > 0) { $cls[] = 'has-fail'; }
          $dow = ((int) (new DateTimeImmutable($d))->format('N')) >= 6;
          if ($dow) { $cls[] = 'we'; }
          $label = $i . ' ' . $MONTHS_GEN[(int) $first->format('n') - 1] . ': ' . ($done ? $done . ' εκτελέσεις' . ($p['failed'] ? ', ' . $p['failed'] . ' αποτυχίες' : '') : '') . ($pl ? ($done ? ', ' : '') . $pl . ' προγραμματισμένες' : '') . (!$done && !$pl ? 'καμία εκτέλεση' : '');
      ?>
        <a class="<?= implode(' ', $cls) ?>" href="<?= e($link(['day' => $d])) ?>" style="--heat:<?= round($heat, 3) ?>" aria-label="<?= e($label) ?>" title="<?= e($label) ?>"<?= $d === $day ? ' aria-current="date"' : '' ?>>
          <span class="cd-n"><?= $i ?></span>
          <span class="cd-stats">
            <?php if ($p['ok']): ?><span class="cd-ok"><?= $nf($p['ok']) ?></span><?php endif; ?>
            <?php if ($p['failed']): ?><span class="cd-fail"><?= $nf($p['failed']) ?></span><?php endif; ?>
            <?php if ($pl): ?><span class="cd-plan"><?= $nf($pl) ?></span><?php endif; ?>
          </span>
        </a>
      <?php endfor; ?>
    </div>
    <div class="cal-legend xs muted">
      <span><i class="lg-ok"></i>Επιτυχίες</span><span><i class="lg-fail"></i>Αποτυχίες</span><span><i class="lg-plan"></i>Προγραμματισμένες</span><span><i class="lg-heat"></i>Πιο σκούρο = περισσότερες</span>
    </div>
  </section>

  <section class="card cal-dayview stack">
    <div class="row between">
      <div class="stack-sm" style="gap:2px">
        <h2><?= e($DOW_FULL[(int) $selD->format('N') - 1] . ' ' . $selD->format('j') . ' ' . $MONTHS_GEN[(int) $selD->format('n') - 1]) ?><?= $day === $today ? ' · σήμερα' : '' ?></h2>
        <span class="small muted">
          <?= $selTotals['done'] ? $nf($selTotals['done']) . ' εκτελέσεις' . ($selTotals['failed'] ? ' · <span style="color:var(--fail-fg)">' . $nf($selTotals['failed']) . ' αποτυχίες</span>' : '') : '' ?>
          <?= $selTotals['planned'] ? ($selTotals['done'] ? ' · ' : '') . $nf($selTotals['planned']) . ' προγραμματισμένες' : '' ?>
          <?= !$selTotals['done'] && !$selTotals['planned'] ? 'Καμία εκτέλεση' : '' ?>
        </span>
      </div>
      <?php if ($peakHour !== null && $peak > 1): ?><span class="badge <?= $peak >= 20 ? 'slow' : 'off' ?>" title="Η ώρα με τις περισσότερες εκτελέσεις">Αιχμή <?= sprintf('%02d:00', $peakHour) ?> · <?= $nf($peak) ?></span><?php endif; ?>
    </div>

    <div class="cal-hours" role="img" aria-label="Εκτελέσεις ανά ώρα">
      <?php foreach ($hours as $h => $v): $tot = $v['done'] + $v['planned']; ?>
        <div class="ch" title="<?= e(sprintf('%02d:00–%02d:59', $h, $h) . ': ' . ($v['done'] ? $v['done'] . ' εκτελέσεις' . ($v['failed'] ? ' (' . $v['failed'] . ' αποτυχίες)' : '') : '') . ($v['planned'] ? ($v['done'] ? ', ' : '') . $v['planned'] . ' προγραμματισμένες' : '') . (!$tot ? 'καμία' : '')) ?>">
          <div class="ch-bar">
            <?php if ($v['planned']): ?><i class="chb-plan" style="height:<?= $peak ? round(100 * $v['planned'] / $peak, 1) : 0 ?>%"></i><?php endif; ?>
            <?php if ($v['done'] - $v['failed'] > 0): ?><i class="chb-ok" style="height:<?= $peak ? round(100 * ($v['done'] - $v['failed']) / $peak, 1) : 0 ?>%"></i><?php endif; ?>
            <?php if ($v['failed']): ?><i class="chb-fail" style="height:<?= $peak ? max(4, round(100 * $v['failed'] / $peak, 1)) : 0 ?>%"></i><?php endif; ?>
          </div>
          <span class="ch-h"><?= $h % 3 === 0 ? sprintf('%02d', $h) : '' ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if (!$perJob): ?>
      <div class="empty" style="padding:24px"><?= icon('clock', 32) ?><span>Καμία εκτέλεση αυτή την ημέρα.</span></div>
    <?php else: ?>
      <div class="cal-jobs">
        <?php foreach ($perJob as $jid => $pj): $job = $jobs[$jid] ?? null; if (!$job) { continue; }
            $runs = $pj['runs'] ?? [];
            $plan = $pj['planned'] ?? [];
            $n = count($runs) + count($plan);
            $fails = count(array_filter($runs, fn ($r) => $r['status'] === 'failed'));
        ?>
          <div class="cal-job">
            <div class="row between" style="gap:8px">
              <a class="strong small" href="<?= e(url('jobs/' . $jid . '/edit')) ?>"><?= e($job['name']) ?></a>
              <span class="xs muted"><?= $nf($n) ?> <?= $n === 1 ? 'εκτέλεση' : 'εκτελέσεις' ?><?= $fails ? ' · <span style="color:var(--fail-fg)">' . $fails . ' αποτυχ.</span>' : '' ?><?= isset($dense[$jid]) ? ' · συχνό' : '' ?></span>
            </div>
            <?php if ($n > 48): ?>
              <?php $all = array_merge(array_column($runs, 'time'), $plan); ?>
              <p class="xs muted">Από <?= e(min($all)) ?> έως <?= e(max($all)) ?> · <?= e(App\Services\CronHelper::describe((string) $job['cron_expr'])) ?>
                · <a href="<?= e(url('runs', ['job' => $jid])) ?>">όλες οι εκτελέσεις</a></p>
              <?php if ($fails): ?>
                <div class="cal-times"><?php foreach (array_filter($runs, fn ($r) => $r['status'] === 'failed') as $r): ?><a class="ct fail" href="<?= e(url('runs/' . $r['id'])) ?>"><?= e($r['time']) ?></a><?php endforeach; ?></div>
              <?php endif; ?>
            <?php else: ?>
              <div class="cal-times">
                <?php foreach ($runs as $r):
                    $c = match (true) { $r['status'] === 'failed' => 'fail', $r['slow'] => 'slow', in_array($r['status'], ['missed', 'skipped'], true) => 'off', in_array($r['status'], ['running', 'queued', 'retrying'], true) => 'run', default => 'ok' };
                    $t = $r['time'] . ' · ' . (RUN_STATUS[$r['status']][0] ?? $r['status']) . ($r['http'] ? ' · HTTP ' . $r['http'] : '') . ($r['ms'] !== null ? ' · ' . fmt_ms((int) $r['ms']) : '');
                ?><a class="ct <?= $c ?>" href="<?= e(url('runs/' . $r['id'])) ?>" title="<?= e($t) ?>"><?= e($r['time']) ?></a><?php endforeach; ?>
                <?php foreach ($plan as $t): ?><span class="ct plan" title="Προγραμματισμένη <?= e($t) ?>"><?= e($t) ?></span><?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>
