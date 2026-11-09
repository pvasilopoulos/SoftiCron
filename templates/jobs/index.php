<?php
/** @var array $page @var string $filter @var string $q @var array $counts @var string $group @var array $groups @var array $groupStats */
use App\Auth\Auth;
use App\Services\CronHelper;

$tz = Auth::tenant()['timezone'];
$canManage = Auth::can('jobs.manage');
$canRun = Auth::can('jobs.run');
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
    <input id="jq" name="q" value="<?= e($q) ?>" placeholder="Αναζήτηση με όνομα, URL ή ομάδα">
    <input type="hidden" name="filter" value="<?= e($filter) ?>">
    <input type="hidden" name="group" value="<?= e($group) ?>">
  </form>
  <?php if ($groups): ?>
    <form method="get" action="<?= e(url('jobs')) ?>" class="inline">
      <input type="hidden" name="filter" value="<?= e($filter) ?>">
      <input type="hidden" name="q" value="<?= e($q) ?>">
      <label class="sr-only" for="jgroup">Ομάδα</label>
      <select id="jgroup" class="select" name="group" data-autosubmit style="width:auto;min-height:44px;border-radius:11px">
        <option value="">Όλες οι ομάδες</option>
        <?php foreach ($groups as $g): ?><option value="<?= e($g['name']) ?>" <?= $group === $g['name'] ? 'selected' : '' ?>><?= e($g['name']) ?> (<?= (int) $g['n'] ?>)</option><?php endforeach; ?>
        <?php if (isset($groupStats[''])): ?><option value="__none" <?= $group === '__none' ? 'selected' : '' ?>>Χωρίς ομάδα (<?= (int) $groupStats['']['n'] ?>)</option><?php endif; ?>
      </select>
    </form>
  <?php endif; ?>
  <div class="chips" role="group" aria-label="Φίλτρα">
    <?php foreach ($filters as $k => [$label, $n]): ?>
      <a class="chip round" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none" aria-pressed="<?= $filter === $k ? 'true' : 'false' ?>" href="<?= e(url('jobs', ['filter' => $k, 'q' => $q, 'group' => $group])) ?>"><?= e($label) ?> <span class="xs"><?= (int) $n ?></span></a>
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
        <thead><tr><th>Job</th><th>Πρόγραμμα</th><th>Τελευταία εκτέλεση</th><th>Επόμενη</th><th class="num">Ενεργό</th><th class="num"><span class="sr-only">Ενέργειες</span></th></tr></thead>
        <tbody>
        <?php
        $showGroups = (bool) $groups;
        $currentGroup = false;
        foreach ($page['rows'] as $j):
            $g = (string) ($j['group_name'] ?? '');
            if ($showGroups && $g !== $currentGroup):
                $currentGroup = $g;
                $st = $groupStats[$g] ?? ['n' => 0, 'active' => 0, 'failing' => 0];
        ?>
          <tr class="group-row">
            <td colspan="6">
              <div class="row" style="gap:10px">
                <?= icon($g === '' ? 'jobs' : 'templates', 16) ?>
                <strong><?= $g === '' ? 'Χωρίς ομάδα' : e($g) ?></strong>
                <span class="xs muted"><?= (int) $st['n'] ?> jobs · <?= (int) $st['active'] ?> ενεργά</span>
                <?php if ((int) $st['failing'] > 0): ?><span class="badge fail"><?= (int) $st['failing'] ?> με πρόβλημα</span><?php endif; ?>
                <span class="grow"></span>
                <?php if ($group === ''): ?><a class="xs" href="<?= e(url('jobs', ['group' => $g === '' ? '__none' : $g, 'filter' => $filter])) ?>">Μόνο αυτή</a><?php endif; ?>
                <?php if ($canManage && $g !== ''): ?><a class="xs" href="<?= e(url('jobs/new', ['group' => $g])) ?>">+ Νέο job εδώ</a><?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endif; ?>
          <tr>
            <td class="primary">
              <div class="stack-sm" style="gap:4px;min-width:0">
                <a class="strong" href="<?= e(url('jobs/' . $j['id'] . '/edit')) ?>"><?= e($j['name']) ?></a>
                <div class="row" style="gap:8px;flex-wrap:nowrap;min-width:0"><span class="pill"><?= e($j['method']) ?></span><span class="mono xs muted" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:260px"><?= e($j['url']) ?></span></div>
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
            <td class="num" data-label="Ενέργειες">
              <div class="row" style="gap:6px;flex-wrap:nowrap;justify-content:flex-end">
                <?php if ($canRun): ?>
                  <form method="post" action="<?= e(url('jobs/' . $j['id'] . '/run')) ?>" class="inline" data-loading>
                    <?= csrf_field() ?>
                    <input type="hidden" name="from" value="list">
                    <button class="btn btn-sm" type="submit" title="Εκτέλεση τώρα" aria-label="<?= e('Εκτέλεση τώρα: ' . $j['name']) ?>"><?= icon('play', 14) ?><span>Εκτέλεση</span></button>
                  </form>
                <?php endif; ?>
                <a class="btn btn-sm btn-icon" href="<?= e(url('jobs/' . $j['id'] . '/edit')) ?>" title="<?= $canManage ? 'Επεξεργασία' : 'Προβολή' ?>" aria-label="<?= e(($canManage ? 'Επεξεργασία: ' : 'Προβολή: ') . $j['name']) ?>" style="width:34px"><?= icon('edit', 14) ?></a>
                <?php if ($canManage): ?>
                  <form method="post" action="<?= e(url('jobs/' . $j['id'] . '/delete')) ?>" class="inline" data-confirm="<?= e('Να διαγραφεί οριστικά το job «' . $j['name'] . '» μαζί με το ιστορικό εκτελέσεών του;') ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn-sm btn-icon btn-danger" type="submit" title="Διαγραφή" aria-label="<?= e('Διαγραφή: ' . $j['name']) ?>" style="width:34px"><?= icon('trash', 14) ?></button>
                  </form>
                <?php endif; ?>
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
