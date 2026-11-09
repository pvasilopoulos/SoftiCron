<?php
/** @var array $page @var string $filter @var string $q @var array $counts */
use App\Auth\Auth;
use App\Services\CronHelper;

$tz = Auth::tenant()['timezone'];
$canManage = Auth::can('jobs.manage');
$filters = ['all' => ['Όλα', $counts['all_count']], 'active' => ['Ενεργά', $counts['active']], 'issue' => ['Με πρόβλημα', $counts['issue']], 'paused' => ['Σε παύση', $counts['paused']]];
?>
<div class="page-head" data-autorefresh="60">
  <div>
    <h1>Jobs</h1>
    <p class="sub"><?= (int) $counts['all_count'] ?> jobs · <?= (int) $counts['active'] ?> ενεργά</p>
  </div>
  <?php if ($canManage): ?><a class="btn btn-primary" href="<?= e(url('jobs/new')) ?>"><?= icon('plus', 16) ?>Νέο job</a><?php endif; ?>
</div>

<div class="row">
  <form class="searchbar" action="<?= e(url('jobs')) ?>" role="search">
    <?= icon('search', 16) ?>
    <label class="sr-only" for="jq">Αναζήτηση</label>
    <input id="jq" name="q" value="<?= e($q) ?>" placeholder="Αναζήτηση με όνομα ή URL">
    <input type="hidden" name="filter" value="<?= e($filter) ?>">
  </form>
  <div class="chips" role="group" aria-label="Φίλτρα">
    <?php foreach ($filters as $k => [$label, $n]): ?>
      <a class="chip round" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none" aria-pressed="<?= $filter === $k ? 'true' : 'false' ?>" href="<?= e(url('jobs', ['filter' => $k, 'q' => $q])) ?>"><?= e($label) ?> <span class="xs"><?= (int) $n ?></span></a>
    <?php endforeach; ?>
  </div>
</div>

<section class="card flush">
  <?php if (!$page['rows']): ?>
    <div class="empty"><?= icon('jobs', 40) ?>
      <span><?= $q !== '' || $filter !== 'all' ? 'Κανένα job δεν ταιριάζει με τα φίλτρα.' : 'Δεν έχεις δημιουργήσει jobs ακόμα.' ?></span>
      <?php if ($canManage && $q === '' && $filter === 'all'): ?><a class="btn btn-primary" href="<?= e(url('jobs/new')) ?>">Νέο job</a><?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table rtable">
        <thead><tr><th>Job</th><th>Πρόγραμμα</th><th>Τελευταία εκτέλεση</th><th>Επόμενη</th><th class="num">Ενεργό</th></tr></thead>
        <tbody>
        <?php foreach ($page['rows'] as $j): ?>
          <tr>
            <td class="primary">
              <div class="stack-sm" style="gap:4px;min-width:0">
                <a class="strong" href="<?= e(url('jobs/' . $j['id'] . '/edit')) ?>"><?= e($j['name']) ?></a>
                <div class="row" style="gap:8px;flex-wrap:nowrap;min-width:0"><span class="pill"><?= e($j['method']) ?></span><span class="mono xs muted" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:360px"><?= e($j['url']) ?></span></div>
              </div>
            </td>
            <td data-label="Πρόγραμμα" class="small"><?= e(CronHelper::describe($j['cron_expr'])) ?></td>
            <td data-label="Τελευταία"><div class="row" style="gap:8px;justify-content:flex-end;justify-content:inherit"><?= $j['enabled'] ? status_badge($j['last_status']) : '<span class="badge off"><span class="dot"></span>Σε παύση</span>' ?><span class="xs muted"><?= e(rel_time($j['last_run_at'])) ?></span></div></td>
            <td data-label="Επόμενη" class="small muted"><?= $j['enabled'] && $j['next_run_at'] ? e(fmt_dt($j['next_run_at'], $tz, 'd/m H:i')) : '—' ?></td>
            <td data-label="Ενεργό" class="num">
              <?php if ($canManage): ?>
                <form method="post" action="<?= e(url('jobs/' . $j['id'] . '/toggle')) ?>" class="inline" data-toggle-form>
                  <?= csrf_field() ?>
                  <label class="switch" title="<?= $j['enabled'] ? 'Παύση' : 'Ενεργοποίηση' ?>">
                    <input type="checkbox" <?= $j['enabled'] ? 'checked' : '' ?> aria-label="<?= e(($j['enabled'] ? 'Παύση: ' : 'Ενεργοποίηση: ') . $j['name']) ?>" data-submit-on-change>
                    <span></span>
                  </label>
                </form>
              <?php else: ?>
                <?= $j['enabled'] ? 'Ναι' : 'Όχι' ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= App\Core\View::partial('partials/pagination', ['page' => $page]) ?>
  <?php endif; ?>
</section>
