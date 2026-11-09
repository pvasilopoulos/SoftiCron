<?php
/** @var array $page @var array $f @var array $jobs @var array $groups @var array $counts @var int $maxDur @var bool $canRun */
use App\Auth\Auth;
use App\Controllers\RunController;

$tz = Auth::tenant()['timezone'];
$base = array_filter($f, fn ($v) => $v !== '' && $v !== 0);
$link = function (array $changes) use ($base): string {
    $q = array_filter(array_merge($base, $changes), fn ($v) => $v !== '' && $v !== null && $v !== 0);
    return url('runs', $q);
};
$jobName = '';
foreach ($jobs as $j) {
    if ((int) $j['id'] === $f['job']) {
        $jobName = $j['name'];
    }
}
$chips = [];
$labels = [
    'status' => RUN_STATUS[$f['status']][0] ?? '',
    'job' => $jobName,
    'group' => $f['group'] === '__none' ? 'Χωρίς ομάδα' : $f['group'],
    'trigger' => RunController::TRIGGERS[$f['trigger']] ?? '',
    'http' => RunController::HTTP_CLASSES[$f['http']] ?? '',
    'period' => RunController::PERIODS[$f['period']] ?? '',
    'from' => $f['from'] !== '' ? 'Από ' . date('d/m/Y', strtotime($f['from'])) : '',
    'to' => $f['to'] !== '' ? 'Έως ' . date('d/m/Y', strtotime($f['to'])) : '',
    'slow' => $f['slow'] ? 'Μόνο αργές' : '',
    'q' => $f['q'] !== '' ? '«' . $f['q'] . '»' : '',
];
foreach ($labels as $k => $l) {
    if ($l !== '' && ($base[$k] ?? '') !== '') {
        $chips[] = [$l, $link([$k => ''])];
    }
}
$total = array_sum($counts);
$statusChips = [
    '' => ['Όλες', $total, 'var(--muted)'],
    'success' => ['Επιτυχίες', $counts['success'] ?? 0, '#16A34A'],
    'failed' => ['Αποτυχίες', $counts['failed'] ?? 0, '#DC2626'],
    'retrying' => ['Σε επανάληψη', $counts['retrying'] ?? 0, '#D97706'],
    'running' => ['Εκτελούνται', ($counts['running'] ?? 0) + ($counts['queued'] ?? 0), 'var(--accent)'],
    'skipped' => ['Παραλείψεις', ($counts['skipped'] ?? 0), '#64748B'],
    'missed' => ['Χαμένες', ($counts['missed'] ?? 0), '#64748B'],
];
$moreOpen = $f['trigger'] !== '' || $f['http'] !== '' || $f['from'] !== '' || $f['to'] !== '' || $f['slow'] || $f['group'] !== '';
?>
<div class="page-head" data-autorefresh="20">
  <div>
    <h1>Εκτελέσεις</h1>
    <p class="sub">Execution logs με request, response και προσπάθειες · <?= (int) $page['total'] ?> αποτελέσματα</p>
  </div>
  <div class="row">
    <a class="btn" href="<?= e(url('runs/export', $base)) ?>"><?= icon('copy', 16) ?>Εξαγωγή CSV</a>
  </div>
</div>

<div class="stat-chips" role="group" aria-label="Κατάσταση">
  <?php foreach ($statusChips as $k => [$l, $n, $c]): if ($k !== '' && $k !== $f['status'] && !$n) { continue; } ?>
    <a class="stat-chip<?= $f['status'] === $k ? ' on' : '' ?>" href="<?= e($link(['status' => $k])) ?>" aria-pressed="<?= $f['status'] === $k ? 'true' : 'false' ?>">
      <span class="dot" style="background:<?= $c ?>"></span><?= e($l) ?> <span class="n"><?= (int) $n ?></span>
    </a>
  <?php endforeach; ?>
</div>

<form class="card filters" method="get" action="<?= e(url('runs')) ?>">
  <div class="filters-main">
    <label class="searchbar"><?= icon('search', 16) ?><span class="sr-only">Αναζήτηση</span>
      <input name="q" value="<?= e($f['q']) ?>" placeholder="Αναζήτηση: #id, job, URL ή μήνυμα σφάλματος">
    </label>
    <label class="sr-only" for="rf-job">Job</label>
    <select id="rf-job" class="select" name="job" style="width:auto;min-width:180px">
      <option value="">Όλα τα jobs</option>
      <?php foreach ($jobs as $j): ?><option value="<?= (int) $j['id'] ?>" <?= $f['job'] === (int) $j['id'] ? 'selected' : '' ?>><?= e($j['name']) ?></option><?php endforeach; ?>
    </select>
    <label class="sr-only" for="rf-period">Περίοδος</label>
    <select id="rf-period" class="select" name="period" style="width:auto">
      <option value="">Όλη η περίοδος</option>
      <?php foreach (RunController::PERIODS as $k => $l): ?><option value="<?= $k ?>" <?= $f['period'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
    </select>
    <?php if ($f['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($f['status']) ?>"><?php endif; ?>
    <button class="btn btn-primary" type="submit">Εφαρμογή</button>
  </div>
  <details <?= $moreOpen ? 'open' : '' ?>>
    <summary class="small" style="cursor:pointer;color:var(--accent-text);font-weight:600">Περισσότερα φίλτρα</summary>
    <div class="filters-more" style="margin-top:10px">
      <?php if ($groups): ?>
        <label class="field">Ομάδα
          <select class="select" name="group"><option value="">Όλες</option><?php foreach ($groups as $g): ?><option value="<?= e($g) ?>" <?= $f['group'] === $g ? 'selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?><option value="__none" <?= $f['group'] === '__none' ? 'selected' : '' ?>>Χωρίς ομάδα</option></select>
        </label>
      <?php endif; ?>
      <label class="field">Αφορμή
        <select class="select" name="trigger"><option value="">Όλες</option><?php foreach (RunController::TRIGGERS as $k => $l): ?><option value="<?= $k ?>" <?= $f['trigger'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
      </label>
      <label class="field">HTTP status
        <select class="select" name="http"><option value="">Όλα</option><?php foreach (RunController::HTTP_CLASSES as $k => $l): ?><option value="<?= $k ?>" <?= $f['http'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
      </label>
      <label class="field">Από<input class="input" type="date" name="from" value="<?= e($f['from']) ?>"></label>
      <label class="field">Έως<input class="input" type="date" name="to" value="<?= e($f['to']) ?>"></label>
      <label class="check small" style="align-self:end;min-height:40px"><input type="checkbox" name="slow" value="1" <?= $f['slow'] ? 'checked' : '' ?>>Μόνο αργές</label>
    </div>
  </details>
  <?= App\Core\View::partial('partials/active_filters', ['chips' => $chips, 'clear' => url('runs')]) ?>
</form>

<section class="card flush">
  <?php if (!$page['rows']): ?>
    <div class="empty"><?= icon('runs', 40) ?><span><?= $base ? 'Δεν βρέθηκαν εκτελέσεις με αυτά τα φίλτρα.' : 'Δεν υπάρχουν εκτελέσεις ακόμα.' ?></span><?php if ($base): ?><a class="btn" href="<?= e(url('runs')) ?>">Καθαρισμός φίλτρων</a><?php endif; ?></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table rtable">
        <thead><tr><th>Εκτέλεση</th><th>Αποτέλεσμα</th><th>Διάρκεια</th><th>Πότε</th><th class="num"><span class="sr-only">Ενέργειες</span></th></tr></thead>
        <tbody>
          <?php foreach ($page['rows'] as $r): $pct = $r['duration_ms'] !== null ? max(3, (int) round(100 * (int) $r['duration_ms'] / $maxDur)) : 0; ?>
            <tr>
              <td class="primary">
                <div class="stack-sm" style="gap:3px;min-width:0">
                  <div class="row" style="gap:8px;flex-wrap:nowrap;min-width:0">
                    <a class="mono xs" href="<?= e(url('runs/' . $r['id'])) ?>">#<?= (int) $r['id'] ?></a>
                    <span class="pill"><?= e($r['method']) ?></span>
                    <a class="strong" href="<?= e(url('runs/' . $r['id'])) ?>" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($r['job_name']) ?></a>
                  </div>
                  <?php if ($r['group_name']): ?><span class="xs muted"><?= icon('templates', 11) ?> <?= e($r['group_name']) ?></span><?php endif; ?>
                  <?php if ($r['error'] && $r['status'] !== 'success'): ?><span class="xs" style="color:var(--fail-fg);max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($r['error']) ?>"><?= e($r['error']) ?></span><?php endif; ?>
                </div>
              </td>
              <td data-label="Αποτέλεσμα">
                <div class="row" style="gap:6px;flex-wrap:nowrap">
                  <?= status_badge($r['status'], (bool) $r['is_slow']) ?>
                  <?php if ($r['http_status']): ?><span class="mono xs" style="color:<?= (int) $r['http_status'] >= 400 ? 'var(--fail-fg)' : 'var(--muted)' ?>"><?= (int) $r['http_status'] ?></span><?php endif; ?>
                  <?php if ((int) $r['attempts'] > 1): ?><span class="xs muted" title="Προσπάθειες"><?= (int) $r['attempts'] ?>/<?= 1 + (int) $r['max_retries'] ?></span><?php endif; ?>
                </div>
              </td>
              <td data-label="Διάρκεια">
                <span class="small"><?= e(fmt_ms($r['duration_ms'] !== null ? (int) $r['duration_ms'] : null)) ?></span>
                <?php if ($pct): ?><div class="dur-bar" aria-hidden="true"><span style="width:<?= $pct ?>%;<?= $r['is_slow'] ? 'background:#D97706' : '' ?>"></span></div><?php endif; ?>
              </td>
              <td data-label="Πότε" class="small nowrap">
                <span title="<?= e(fmt_dt($r['started_at'] ?? $r['scheduled_at'], $tz, 'd/m/Y H:i:s')) ?>"><?= e(fmt_dt($r['started_at'] ?? $r['scheduled_at'], $tz, 'd/m H:i')) ?></span>
                <div class="xs muted"><?= e(RunController::TRIGGERS[$r['trigger_type']] ?? $r['trigger_type']) ?></div>
              </td>
              <td class="num" data-label="Ενέργειες">
                <div class="row-actions">
                  <a class="btn btn-sm btn-icon" href="<?= e(url('runs/' . $r['id'])) ?>" title="Προβολή" aria-label="<?= e('Προβολή εκτέλεσης #' . $r['id']) ?>"><?= icon('search', 14) ?></a>
                  <?php if ($canRun && !in_array($r['status'], ['queued', 'running', 'retrying'], true)): ?>
                    <form method="post" action="<?= e(url('runs/' . $r['id'] . '/retry')) ?>" class="inline" data-loading>
                      <?= csrf_field() ?><input type="hidden" name="from" value="list">
                      <button class="btn btn-sm btn-icon" type="submit" title="Επανάληψη τώρα" aria-label="<?= e('Επανάληψη εκτέλεσης #' . $r['id']) ?>"><?= icon('refresh', 14) ?></button>
                    </form>
                  <?php endif; ?>
                  <a class="btn btn-sm btn-icon" href="<?= e(url('jobs/' . $r['job_id'] . '/edit')) ?>" title="Άνοιγμα job" aria-label="<?= e('Άνοιγμα job ' . $r['job_name']) ?>"><?= icon('jobs', 14) ?></a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= App\Core\View::partial('partials/pagination', ['page' => $page]) ?>
  <?php endif; ?>
</section>
