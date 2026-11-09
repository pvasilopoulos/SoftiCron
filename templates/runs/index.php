<?php
/** @var array $page @var string $status @var int $jobId @var array $jobs */
use App\Auth\Auth;

$tz = Auth::tenant()['timezone'];
$triggers = ['schedule' => 'Πρόγραμμα', 'manual' => 'Χειροκίνητη', 'missed' => 'Χαμένη'];
?>
<div class="page-head" data-autorefresh="20">
  <div>
    <h1>Εκτελέσεις</h1>
    <p class="sub">Execution logs με request, response και προσπάθειες</p>
  </div>
</div>

<form class="row" method="get" action="<?= e(url('runs')) ?>">
  <label class="field" style="flex:1 1 220px">Job
    <select class="select" name="job" data-autosubmit>
      <option value="">Όλα τα jobs</option>
      <?php foreach ($jobs as $j): ?><option value="<?= (int) $j['id'] ?>" <?= $jobId === (int) $j['id'] ? 'selected' : '' ?>><?= e($j['name']) ?></option><?php endforeach; ?>
    </select>
  </label>
  <label class="field" style="flex:1 1 180px">Κατάσταση
    <select class="select" name="status" data-autosubmit>
      <option value="">Όλες</option>
      <?php foreach (RUN_STATUS as $k => [$l]): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
    </select>
  </label>
  <noscript><button class="btn" type="submit">Φίλτρο</button></noscript>
</form>

<section class="card flush">
  <?php if (!$page['rows']): ?>
    <div class="empty"><?= icon('runs', 40) ?><span>Δεν βρέθηκαν εκτελέσεις.</span></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table rtable">
        <thead><tr><th>#</th><th>Job</th><th>Κατάσταση</th><th>HTTP</th><th>Διάρκεια</th><th>Προσπάθειες</th><th>Αφορμή</th><th class="num">Ώρα</th></tr></thead>
        <tbody>
          <?php foreach ($page['rows'] as $r): ?>
            <tr>
              <td data-label="#" class="mono small"><a href="<?= e(url('runs/' . $r['id'])) ?>">#<?= (int) $r['id'] ?></a></td>
              <td class="primary"><div class="row" style="gap:8px;flex-wrap:nowrap"><span class="pill"><?= e($r['method']) ?></span><a class="strong" href="<?= e(url('runs/' . $r['id'])) ?>"><?= e($r['job_name']) ?></a></div>
                <?php if ($r['error'] && $r['status'] !== 'success'): ?><div class="xs muted" style="margin-top:2px;max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($r['error']) ?></div><?php endif; ?></td>
              <td data-label="Κατάσταση"><?= status_badge($r['status'], (bool) $r['is_slow']) ?></td>
              <td data-label="HTTP" class="mono small"><?= $r['http_status'] ? (int) $r['http_status'] : '—' ?></td>
              <td data-label="Διάρκεια"><?= e(fmt_ms($r['duration_ms'] !== null ? (int) $r['duration_ms'] : null)) ?></td>
              <td data-label="Προσπάθειες"><?= (int) $r['attempts'] ?></td>
              <td data-label="Αφορμή" class="small"><?= e($triggers[$r['trigger_type']] ?? $r['trigger_type']) ?></td>
              <td data-label="Ώρα" class="num muted small"><?= e(fmt_dt($r['started_at'] ?? $r['scheduled_at'], $tz, 'd/m/Y H:i:s')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= App\Core\View::partial('partials/pagination', ['page' => $page]) ?>
  <?php endif; ?>
</section>
