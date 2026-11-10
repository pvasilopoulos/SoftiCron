<?php
/**
 * @var array $page @var string $filter @var string $q @var array $counts @var string $group @var array $groups @var array $groupStats
 * @var string $type @var string $method @var string $sort @var int $per @var array $recent @var array $stats @var array $kpi
 */
use App\Auth\Auth;
use App\Services\CronHelper;

$tz = Auth::tenant()['timezone'];
$canCreate = Auth::can('jobs.create');
$canEdit = Auth::can('jobs.edit');
$canToggle = Auth::can('jobs.toggle');
$canDelete = Auth::can('jobs.delete');
$canRun = Auth::can('jobs.run');
$canExport = Auth::can('jobs.export');
$canBulk = $canToggle || $canRun || $canEdit || $canDelete || $canExport;

// Current query, used to build links that change one parameter.
$cur = ['q' => $q, 'filter' => $filter === 'all' ? '' : $filter, 'group' => $group, 'type' => $type, 'method' => $method,
    'sort' => $sort === 'group' ? '' : $sort, 'per' => $per === 50 ? '' : (string) $per];
$link = fn (array $over = []) => url('jobs', array_filter(array_merge($cur, $over), fn ($v) => $v !== '' && $v !== null));
$filters = [
    'all' => ['Όλα', $counts['all_count'], null],
    'active' => ['Ενεργά', $counts['active'], '#16A34A'],
    'issue' => ['Με πρόβλημα', $counts['issue'], '#DC2626'],
    'paused' => ['Σε παύση', $counts['paused'], '#94A3B8'],
    'never' => ['Χωρίς εκτέλεση', $counts['never'], '#CBD5E1'],
];
if ((int) $counts['slow'] > 0 || $filter === 'slow') {
    $filters['slow'] = ['Αργά', $counts['slow'], '#D97706'];
}
$sorts = ['group' => 'Ομάδα', 'status' => 'Κατάσταση (προβλήματα πρώτα)', 'name' => 'Όνομα', 'last_run' => 'Τελευταία εκτέλεση', 'next_run' => 'Επόμενη εκτέλεση', 'duration' => 'Διάρκεια', 'created' => 'Νεότερα'];
$advanced = $group !== '' || $type !== '' || $method !== '' || $sort !== 'group' || $per !== 50;
$anyFilter = $q !== '' || $filter !== 'all' || $advanced;
$total = (int) $counts['all_count'];
$rate = (int) $kpi['runs'] > 0 ? 100 * (int) $kpi['ok'] / (int) $kpi['runs'] : null;
$showGroups = $groups && $sort === 'group';
$fmtPct = fn (?float $p) => $p === null ? '—' : ($p >= 99.95 || $p == 0 ? number_format($p, 0) : number_format($p, 1, ',', '.')) . '%';
?>
<div class="page-head" data-autorefresh="60">
  <div>
    <h1>Jobs</h1>
    <p class="sub"><?= $total ?> jobs · <?= (int) $counts['active'] ?> ενεργά<?php if ((int) $counts['issue'] > 0): ?> · <a href="<?= e(url('jobs', ['filter' => 'issue'])) ?>" style="color:var(--fail-fg)"><?= (int) $counts['issue'] ?> με πρόβλημα</a><?php endif; ?></p>
  </div>
  <div class="row">
    <?php if (Auth::can('jobs.import')): ?><a class="btn" href="<?= e(url('jobs/import')) ?>"><?= icon('upload', 16) ?><span>Εισαγωγή</span></a><?php endif; ?>
    <?php if ($canExport && $total > 0): ?><a class="btn" href="<?= e(url('jobs/export', array_filter(['group' => $group]))) ?>" title="<?= $group !== '' ? 'Εξαγωγή των jobs της ομάδας' : 'Εξαγωγή όλων των jobs' ?>"><?= icon('download', 16) ?><span>Εξαγωγή</span></a><?php endif; ?>
    <?php if ($canCreate): ?><a class="btn btn-primary" href="<?= e(url('jobs/new')) ?>"><?= icon('plus', 16) ?>Νέο job</a><?php endif; ?>
  </div>
</div>

<?php if ($total > 0): ?>
<div class="jobs-kpis">
  <a class="jk" href="<?= e(url('runs', ['period' => '24h'])) ?>">
    <span class="jk-label">Εκτελέσεις 24ω</span>
    <span class="jk-value"><?= number_format((int) $kpi['runs'], 0, ',', '.') ?></span>
    <span class="jk-sub"><?= $kpi['running'] ? '<span class="badge run"><span class="dot"></span>' . (int) $kpi['running'] . ' σε εξέλιξη</span>' : 'μέση διάρκεια ' . e(fmt_ms($kpi['avg_ms'] !== null ? (int) round((float) $kpi['avg_ms']) : null)) ?></span>
  </a>
  <a class="jk" href="<?= e(url('runs', ['period' => '24h', 'status' => 'success'])) ?>">
    <span class="jk-label">Ποσοστό επιτυχίας 24ω</span>
    <span class="jk-value" style="color:<?= $rate === null ? 'inherit' : ($rate >= 99 ? 'var(--ok-fg)' : ($rate >= 90 ? 'var(--warn-fg)' : 'var(--fail-fg)')) ?>"><?= $fmtPct($rate) ?></span>
    <span class="jk-bar"><i style="width:<?= $rate === null ? 0 : max(2, round($rate)) ?>%"></i></span>
  </a>
  <a class="jk" href="<?= e(url('runs', ['period' => '24h', 'status' => 'failed'])) ?>">
    <span class="jk-label">Αποτυχίες 24ω</span>
    <span class="jk-value"<?= (int) $kpi['failed'] > 0 ? ' style="color:var(--fail-fg)"' : '' ?>><?= (int) $kpi['failed'] ?></span>
    <span class="jk-sub"><?= (int) $counts['issue'] > 0 ? (int) $counts['issue'] . ' jobs αποτυγχάνουν τώρα' : 'κανένα job δεν αποτυγχάνει' ?></span>
  </a>
  <?php $nx = $kpi['next']; ?>
  <a class="jk" href="<?= e($nx ? url('jobs/' . $nx['id'] . '/edit') : url('jobs', ['sort' => 'next_run'])) ?>">
    <span class="jk-label">Επόμενη εκτέλεση</span>
    <span class="jk-value jk-small"><?= $nx ? (strtotime($nx['next_run_at'] . ' UTC') < time() - 120 ? '<span style="color:var(--warn-fg)">Καθυστερεί</span>' : e(rel_time($nx['next_run_at']))) : '—' ?></span>
    <span class="jk-sub" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= $nx ? e($nx['name']) . ' · ' . e(fmt_dt($nx['next_run_at'], $tz, 'd/m H:i')) : 'κανένα ενεργό job' ?></span>
  </a>
</div>
<?php endif; ?>

<form id="jobs-filter" class="jobs-toolbar" method="get" action="<?= e(url('jobs')) ?>" role="search">
  <div class="row" style="gap:10px">
    <label class="searchbar" style="max-width:none">
      <?= icon('search', 16) ?>
      <span class="sr-only">Αναζήτηση</span>
      <input id="jq" name="q" value="<?= e($q) ?>" placeholder="Αναζήτηση με όνομα, URL, ομάδα ή περιγραφή" autocomplete="off"<?= $q !== '' ? ' data-refocus' : '' ?>>
      <?php if ($q !== ''): ?><a class="jq-clear" href="<?= e($link(['q' => ''])) ?>" aria-label="Καθαρισμός αναζήτησης"><?= icon('x', 14) ?></a><?php else: ?><kbd class="kbd" title="Πάτα / για αναζήτηση">/</kbd><?php endif; ?>
    </label>
    <button type="button" class="btn<?= $advanced ? ' on' : '' ?>" data-toggle-adv aria-expanded="<?= $advanced ? 'true' : 'false' ?>" aria-controls="jobs-adv">
      <svg class="ic" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 5h18M6 12h12M10 19h4"/></svg>
      <span>Φίλτρα</span><?php $nAdv = count(array_filter([$group, $type, $method, $sort !== 'group' ? $sort : '', $per !== 50 ? 'x' : ''])); if ($nAdv): ?><span class="jk-count"><?= $nAdv ?></span><?php endif; ?>
    </button>
  </div>

  <div class="chips" role="group" aria-label="Κατάσταση">
    <?php foreach ($filters as $k => [$label, $n, $dot]): ?>
      <a class="chip round jchip" aria-pressed="<?= $filter === $k ? 'true' : 'false' ?>" href="<?= e($link(['filter' => $k === 'all' ? '' : $k])) ?>">
        <?php if ($dot): ?><i class="jdot" style="background:<?= $dot ?>"></i><?php endif; ?><?= e($label) ?> <span class="xs"><?= (int) $n ?></span>
      </a>
    <?php endforeach; ?>
  </div>

  <div id="jobs-adv" class="jobs-adv"<?= $advanced ? '' : ' hidden' ?>>
    <?php if ($filter !== 'all'): ?><input type="hidden" name="filter" value="<?= e($filter) ?>"><?php endif; ?>
    <label class="field-inline"><span>Ομάδα</span>
      <select class="select" name="group" data-autosubmit>
        <option value="">Όλες</option>
        <?php foreach ($groups as $g): ?><option value="<?= e($g['name']) ?>" <?= $group === $g['name'] ? 'selected' : '' ?>><?= e($g['name']) ?> (<?= (int) $g['n'] ?>)</option><?php endforeach; ?>
        <?php if (isset($groupStats[''])): ?><option value="__none" <?= $group === '__none' ? 'selected' : '' ?>>Χωρίς ομάδα (<?= (int) $groupStats['']['n'] ?>)</option><?php endif; ?>
      </select>
    </label>
    <label class="field-inline"><span>Τύπος</span>
      <select class="select" name="type" data-autosubmit>
        <option value="">Όλοι</option>
        <option value="http" <?= $type === 'http' ? 'selected' : '' ?>>HTTP (<?= $total - (int) $counts['heartbeat'] ?>)</option>
        <option value="heartbeat" <?= $type === 'heartbeat' ? 'selected' : '' ?>>Heartbeat (<?= (int) $counts['heartbeat'] ?>)</option>
      </select>
    </label>
    <label class="field-inline"><span>Μέθοδος</span>
      <select class="select" name="method" data-autosubmit>
        <option value="">Όλες</option>
        <?php foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'] as $m): ?><option <?= $method === $m ? 'selected' : '' ?>><?= $m ?></option><?php endforeach; ?>
      </select>
    </label>
    <label class="field-inline"><span>Ταξινόμηση</span>
      <select class="select" name="sort" data-autosubmit>
        <?php foreach ($sorts as $k => $label): ?><option value="<?= $k ?>" <?= $sort === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label class="field-inline"><span>Ανά σελίδα</span>
      <select class="select" name="per" data-autosubmit>
        <?php foreach ([25, 50, 100] as $p): ?><option <?= $per === $p ? 'selected' : '' ?>><?= $p ?></option><?php endforeach; ?>
      </select>
    </label>
    <?php if ($anyFilter): ?><a class="btn btn-sm" href="<?= e(url('jobs')) ?>" style="align-self:flex-end"><?= icon('x', 14) ?>Καθαρισμός όλων</a><?php endif; ?>
  </div>
</form>

<?php if ($anyFilter && $page['rows']): ?>
  <p class="xs muted" style="margin:-4px 0 0">Εμφανίζονται <?= (int) $page['total'] ?> από <?= $total ?> jobs<?= $q !== '' ? ' για «' . e($q) . '»' : '' ?> · <a href="<?= e(url('jobs')) ?>">καθαρισμός φίλτρων</a></p>
<?php endif; ?>

<section class="card flush">
  <?php if (!$page['rows']): ?>
    <div class="empty"><?= icon('jobs', 40) ?>
      <?php if ($anyFilter): ?>
        <span>Κανένα job δεν ταιριάζει με τα φίλτρα.</span>
        <a class="btn" href="<?= e(url('jobs')) ?>"><?= icon('x', 14) ?>Καθαρισμός φίλτρων</a>
      <?php else: ?>
        <strong style="color:var(--text)">Δεν έχεις δημιουργήσει jobs ακόμα</strong>
        <span>Ένα job καλεί ένα URL με πρόγραμμα (cron) ή περιμένει ping από δικό σου script (heartbeat).</span>
        <div class="row" style="justify-content:center">
          <?php if ($canCreate): ?><a class="btn btn-primary" href="<?= e(url('jobs/new')) ?>"><?= icon('plus', 16) ?>Νέο job</a><?php endif; ?>
          <?php if (Auth::can('jobs.import')): ?><a class="btn" href="<?= e(url('jobs/import')) ?>"><?= icon('upload', 16) ?>Εισαγωγή από αρχείο</a><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table rtable jobs-table">
        <thead><tr>
          <th><?php if ($canBulk): ?><label class="jcheck" title="Επιλογή όλων"><input type="checkbox" data-sel-all aria-label="Επιλογή όλων των jobs της σελίδας"></label><?php endif; ?>Job</th>
          <th>Πρόγραμμα</th><th>Τελευταία</th><th>Ιστορικό</th><th>Επόμενη</th><th class="num">Ενεργό</th><th class="num"><span class="sr-only">Ενέργειες</span></th>
        </tr></thead>
        <tbody>
        <?php
        $currentGroup = false;
        foreach ($page['rows'] as $j):
            $jid = (int) $j['id'];
            $g = (string) ($j['group_name'] ?? '');
            if ($showGroups && $g !== $currentGroup):
                $currentGroup = $g;
                $st = $groupStats[$g] ?? ['n' => 0, 'active' => 0, 'failing' => 0];
        ?>
          <tr class="group-row" data-group-row="<?= e($g) ?>">
            <td colspan="7">
              <div class="row" style="gap:10px">
                <?php if ($canBulk): ?><label class="jcheck" title="Επιλογή ομάδας"><input type="checkbox" data-sel-group="<?= e($g) ?>" aria-label="<?= e('Επιλογή ομάδας ' . ($g === '' ? 'Χωρίς ομάδα' : $g)) ?>"></label><?php endif; ?>
                <button type="button" class="jgroup-toggle" data-collapse-group="<?= e($g) ?>" aria-expanded="true" title="Σύμπτυξη / ανάπτυξη">
                  <svg class="ic" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                  <strong><?= $g === '' ? 'Χωρίς ομάδα' : e($g) ?></strong>
                </button>
                <span class="xs muted"><?= (int) $st['n'] ?> jobs · <?= (int) $st['active'] ?> ενεργά</span>
                <?php if ((int) $st['failing'] > 0): ?><span class="badge fail"><?= (int) $st['failing'] ?> με πρόβλημα</span><?php endif; ?>
                <span class="grow"></span>
                <?php if ($group === ''): ?><a class="xs" href="<?= e($link(['group' => $g === '' ? '__none' : $g])) ?>">Μόνο αυτή</a><?php endif; ?>
                <?php if ($canCreate && $g !== ''): ?><a class="xs" href="<?= e(url('jobs/new', ['group' => $g])) ?>">+ Νέο job εδώ</a><?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endif; ?>
        <?php
            $runs = $recent[$jid] ?? [];
            $s7 = $stats[$jid] ?? null;
            $done7 = $s7 ? (int) $s7['ok'] + (int) $s7['failed'] : 0;
            $pct7 = $done7 ? 100 * (int) $s7['ok'] / $done7 : null;
            $maxMs = max(1, ...array_map(fn ($r) => (int) $r['duration_ms'], $runs ?: [['duration_ms' => 1]]));
            $last = $runs ? end($runs) : null;
            $failing = $j['enabled'] && $j['last_status'] === 'failed';
        ?>
          <tr class="jrow<?= $failing ? ' is-failing' : '' ?><?= $j['enabled'] ? '' : ' is-paused' ?>" data-job="<?= $jid ?>" data-group="<?= e($g) ?>" data-name="<?= e($j['name']) ?>" data-type="<?= e($j['type']) ?>">
            <td class="primary">
              <div class="jcell">
                <?php if ($canBulk): ?><label class="jcheck"><input type="checkbox" data-sel value="<?= $jid ?>" aria-label="<?= e('Επιλογή: ' . $j['name']) ?>"></label><?php endif; ?>
                <div class="stack-sm" style="gap:4px;min-width:0">
                  <a class="strong jname" href="<?= e(url('jobs/' . $jid . '/edit')) ?>"><?= e($j['name']) ?></a>
                  <?php if ($j['type'] === 'heartbeat'): ?>
                    <div class="row" style="gap:8px;flex-wrap:nowrap;min-width:0"><span class="pill" style="background:var(--accent-soft);color:var(--accent-text)">HEARTBEAT</span><span class="xs muted"><?= $j['last_ping_at'] ? 'τελευταίο ping ' . e(rel_time($j['last_ping_at'])) : 'δεν έχει έρθει ping' ?></span></div>
                  <?php else: ?>
                    <div class="row" style="gap:8px;flex-wrap:nowrap;min-width:0"><span class="pill m-<?= e(strtolower($j['method'])) ?>"><?= e($j['method']) ?></span><span class="mono xs muted jurl" title="<?= e($j['url']) ?>"><?= e(preg_replace('#^https?://#', '', $j['url'])) ?></span></div>
                  <?php endif; ?>
                  <?php if (!empty($j['description'])): ?><span class="xs muted jdesc" title="<?= e($j['description']) ?>"><?= e(mb_strimwidth($j['description'], 0, 90, '…')) ?></span><?php endif; ?>
                  <?php if (!$showGroups && $g !== ''): ?><a class="jtag" href="<?= e($link(['group' => $g])) ?>"><?= e($g) ?></a><?php endif; ?>
                </div>
              </div>
            </td>
            <td data-label="Πρόγραμμα" class="small">
              <div class="stack-sm" style="gap:2px">
                <span><?= e(CronHelper::describe($j['cron_expr'])) ?></span>
                <code class="xs muted" title="Ζώνη ώρας: <?= e($j['timezone']) ?>"><?= e($j['cron_expr']) ?></code>
              </div>
            </td>
            <td data-label="Τελευταία">
              <div class="stack-sm jlast" style="gap:3px">
                <?php if (!$j['enabled']): ?>
                  <span class="badge off"><span class="dot"></span>Σε παύση</span>
                <?php elseif ($last && $j['last_status']): ?>
                  <a href="<?= e(url('runs/' . $last['id'])) ?>" class="jbadge-link" title="Άνοιγμα εκτέλεσης #<?= (int) $last['id'] ?>"><?= status_badge($j['last_status'], (bool) $last['is_slow']) ?></a>
                <?php else: ?>
                  <?= status_badge($j['last_status']) ?>
                <?php endif; ?>
                <span class="xs muted" title="<?= e(fmt_dt($j['last_run_at'], $tz, 'd/m/Y H:i:s')) ?>"><?= e(rel_time($j['last_run_at'])) ?><?= $j['last_duration_ms'] !== null ? ' · ' . e(fmt_ms((int) $j['last_duration_ms'])) : '' ?></span>
              </div>
            </td>
            <td data-label="Ιστορικό">
              <?php if ($runs): ?>
                <div class="jhist">
                  <div class="spark" role="img" aria-label="<?= e('Τελευταίες ' . count($runs) . ' εκτελέσεις') ?>">
                    <?php for ($i = count($runs); $i < 20; $i++): ?><i class="sp-empty"></i><?php endfor; ?>
                    <?php foreach ($runs as $r):
                        $cls = match (true) {
                            $r['status'] === 'failed' => 'f', in_array($r['status'], ['running', 'retrying'], true) => 'r',
                            in_array($r['status'], ['missed', 'skipped'], true) => 'm', (bool) $r['is_slow'] => 's', default => 'o',
                        };
                        $h = $cls === 'o' || $cls === 's' ? max(30, (int) round(100 * (int) $r['duration_ms'] / $maxMs)) : 100;
                        $tip = fmt_dt($r['t'], $tz, 'd/m H:i') . ' · ' . (RUN_STATUS[$r['status']][0] ?? $r['status'])
                            . ($r['http_status'] ? ' · HTTP ' . $r['http_status'] : '') . ($r['duration_ms'] !== null ? ' · ' . fmt_ms((int) $r['duration_ms']) : '');
                    ?><a class="sp-<?= $cls ?>" style="height:<?= $h ?>%" href="<?= e(url('runs/' . $r['id'])) ?>" title="<?= e($tip) ?>" aria-label="<?= e($tip) ?>"></a><?php endforeach; ?>
                  </div>
                  <span class="xs muted"><?= $pct7 !== null ? '<b class="jpct ' . ($pct7 >= 99 ? 'ok' : ($pct7 >= 90 ? 'warn' : 'bad')) . '">' . e($fmtPct($pct7)) . '</b> 7ημ.' : '—' ?><?= $s7 && $s7['avg_ms'] !== null ? ' · ⌀ ' . e(fmt_ms((int) round((float) $s7['avg_ms']))) : '' ?></span>
                </div>
              <?php else: ?>
                <span class="xs muted">Καμία εκτέλεση</span>
              <?php endif; ?>
            </td>
            <td data-label="Επόμενη" class="small">
              <?php if ($j['enabled'] && $j['next_run_at']): ?>
                <div class="stack-sm" style="gap:2px"><?php if (strtotime($j['next_run_at'] . ' UTC') < time() - 120): ?><span style="color:var(--warn-fg);font-weight:600" title="Η εκτέλεση έπρεπε να έχει γίνει· ελέγξτε ότι τρέχει ο scheduler (cron).">Καθυστερεί</span><?php else: ?><span><?= e(rel_time($j['next_run_at'])) ?></span><?php endif; ?><span class="xs muted"><?= e(fmt_dt($j['next_run_at'], $tz, 'd/m H:i')) ?></span></div>
              <?php else: ?><span class="muted">—</span><?php endif; ?>
            </td>
            <td data-label="Ενεργό" class="num">
              <?php if ($canToggle): ?>
                <form method="post" action="<?= e(url('jobs/' . $jid . '/toggle')) ?>" class="inline" data-toggle-form>
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
                <?php if ($canRun && $j['type'] !== 'heartbeat'): ?>
                  <form method="post" action="<?= e(url('jobs/' . $jid . '/run')) ?>" class="inline" data-loading>
                    <?= csrf_field() ?>
                    <input type="hidden" name="from" value="list">
                    <button class="btn btn-sm" type="submit" title="Εκτέλεση τώρα" aria-label="<?= e('Εκτέλεση τώρα: ' . $j['name']) ?>"><?= icon('play', 14) ?><span>Εκτέλεση</span></button>
                  </form>
                <?php endif; ?>
                <a class="btn btn-sm btn-icon" href="<?= e(url('jobs/' . $jid . '/edit')) ?>" title="<?= $canEdit ? 'Επεξεργασία' : 'Προβολή' ?>" aria-label="<?= e(($canEdit ? 'Επεξεργασία: ' : 'Προβολή: ') . $j['name']) ?>" style="width:34px"><?= icon('edit', 14) ?></a>
                <details class="feed-more jmenu">
                  <summary class="btn btn-sm btn-icon" style="width:34px" aria-label="<?= e('Περισσότερα: ' . $j['name']) ?>" title="Περισσότερα">⋯</summary>
                  <div class="feed-menu">
                    <a href="<?= e(url('runs', ['job' => $jid])) ?>"><?= icon('runs', 14) ?>Ιστορικό εκτελέσεων</a>
                    <a href="<?= e(url('jobs/' . $jid . '/versions')) ?>"><?= icon('clock', 14) ?>Εκδόσεις</a>
                    <?php if ($canCreate): ?>
                      <form method="post" action="<?= e(url('jobs/' . $jid . '/duplicate')) ?>" class="inline"><?= csrf_field() ?><button type="submit" style="width:100%"><?= icon('copy', 14) ?>Δημιουργία αντιγράφου</button></form>
                    <?php endif; ?>
                    <?php if ($j['type'] !== 'heartbeat'): ?><button type="button" data-copy="<?= e($j['url']) ?>"><?= icon('copy', 14) ?>Αντιγραφή URL</button><?php endif; ?>
                    <?php if ($canExport): ?><a href="<?= e(url('jobs/export', ['ids' => $jid])) ?>"><?= icon('download', 14) ?>Εξαγωγή (JSON)</a><?php endif; ?>
                    <?php if ($canDelete): ?>
                      <form method="post" action="<?= e(url('jobs/' . $jid . '/delete')) ?>" class="inline" data-confirm="<?= e('Να διαγραφεί οριστικά το job «' . $j['name'] . '» μαζί με το ιστορικό εκτελέσεών του;') ?>">
                        <?= csrf_field() ?>
                        <button type="submit" style="width:100%;color:var(--fail-fg)"><?= icon('trash', 14) ?>Διαγραφή</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </details>
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

<?php if ($canBulk && $page['rows']): ?>
<div class="bulk-bar" id="bulk-bar" hidden role="region" aria-label="Μαζικές ενέργειες">
  <span class="bulk-count"><b data-bulk-n>0</b> επιλεγμένα</span>
  <span class="bulk-sep"></span>
  <?php if ($canRun): ?><button type="button" class="btn btn-sm" data-bulk="run" title="Εκτέλεση τώρα"><?= icon('play', 14) ?><span>Εκτέλεση</span></button><?php endif; ?>
  <?php if ($canToggle): ?>
    <button type="button" class="btn btn-sm" data-bulk="enable" title="Ενεργοποίηση"><?= icon('check', 14) ?><span>Ενεργοποίηση</span></button>
    <button type="button" class="btn btn-sm" data-bulk="pause" title="Παύση"><svg class="ic" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M9 5v14M15 5v14"/></svg><span>Παύση</span></button>
  <?php endif; ?>
  <?php if ($canEdit): ?>
    <details class="feed-more bulk-group">
      <summary class="btn btn-sm" title="Μετακίνηση σε ομάδα"><?= icon('templates', 14) ?><span>Ομάδα</span></summary>
      <div class="feed-menu" style="bottom:calc(100% + 6px);top:auto;min-width:260px;gap:8px;padding:12px">
        <label class="xs muted" for="bulk-group-in">Μετακίνηση στην ομάδα</label>
        <input id="bulk-group-in" class="input" list="bulk-groups" placeholder="Όνομα ομάδας (κενό = χωρίς ομάδα)" maxlength="100">
        <datalist id="bulk-groups"><?php foreach ($groups as $g): ?><option value="<?= e($g['name']) ?>"><?php endforeach; ?></datalist>
        <button type="button" class="btn btn-primary btn-sm" data-bulk="group" style="justify-content:center">Εφαρμογή</button>
      </div>
    </details>
  <?php endif; ?>
  <?php if ($canExport): ?><button type="button" class="btn btn-sm" data-bulk-export title="Εξαγωγή επιλεγμένων (JSON)"><?= icon('download', 14) ?><span>Εξαγωγή</span></button><?php endif; ?>
  <?php if ($canDelete): ?><button type="button" class="btn btn-sm btn-danger" data-bulk="delete" title="Διαγραφή"><?= icon('trash', 14) ?><span>Διαγραφή</span></button><?php endif; ?>
  <button type="button" class="btn btn-sm btn-icon" data-bulk-clear aria-label="Καθαρισμός επιλογής" title="Καθαρισμός επιλογής (Esc)" style="width:32px"><?= icon('x', 14) ?></button>
</div>
<?php endif; ?>

<script>
(function () {
  'use strict';
  const base = (window.APP && window.APP.base || '/').replace(/\/$/, '');
  const form = document.getElementById('jobs-filter');
  const q = document.getElementById('jq');

  // Message saved before a reload (bulk actions).
  try { const m = sessionStorage.getItem('jobsToast'); if (m) { sessionStorage.removeItem('jobsToast'); const d = JSON.parse(m); setTimeout(() => window.toast(d.message, d.error ? 'error' : ''), 50); } } catch (e) { /* ignore */ }

  // Live search (debounced) + keep the caret at the end after reload.
  if (q) {
    let t;
    q.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => form.submit(), 450); });
    if (q.hasAttribute('data-refocus')) { q.focus(); const v = q.value; q.value = ''; q.value = v; }
  }
  document.addEventListener('keydown', (e) => {
    const tag = (e.target.tagName || '').toLowerCase();
    if (e.key === '/' && !['input', 'textarea', 'select'].includes(tag) && !e.target.isContentEditable) { e.preventDefault(); q && q.focus(); }
    if (e.key === 'Escape' && tag !== 'input') clearSel();
  });

  // Advanced filters panel
  const advBtn = document.querySelector('[data-toggle-adv]');
  const adv = document.getElementById('jobs-adv');
  advBtn && advBtn.addEventListener('click', () => {
    const open = adv.hidden;
    adv.hidden = !open;
    advBtn.setAttribute('aria-expanded', String(open));
    try { localStorage.setItem('jobsAdv', open ? '1' : '0'); } catch (e) { /* ignore */ }
  });
  try { if (adv && adv.hidden && localStorage.getItem('jobsAdv') === '1') { adv.hidden = false; advBtn.setAttribute('aria-expanded', 'true'); } } catch (e) { /* ignore */ }

  // Only one "⋯" menu open at a time; close on outside click.
  document.addEventListener('click', (e) => {
    document.querySelectorAll('details.feed-more[open]').forEach((d) => { if (!d.contains(e.target)) d.removeAttribute('open'); });
  });

  // Collapsible groups (remembered per tenant page)
  let collapsed = [];
  try { collapsed = JSON.parse(localStorage.getItem('jobsCollapsed') || '[]'); } catch (e) { /* ignore */ }
  const applyCollapse = (g, on) => {
    document.querySelectorAll('tr.jrow').forEach((tr) => { if (tr.dataset.group === g) tr.hidden = on; });
    const b = document.querySelector('[data-collapse-group="' + CSS.escape(g) + '"]');
    if (b) { b.setAttribute('aria-expanded', String(!on)); b.closest('tr').classList.toggle('collapsed', on); }
  };
  collapsed.forEach((g) => applyCollapse(g, true));
  document.querySelectorAll('[data-collapse-group]').forEach((b) => b.addEventListener('click', () => {
    const g = b.dataset.collapseGroup;
    const on = b.getAttribute('aria-expanded') === 'true';
    applyCollapse(g, on);
    collapsed = collapsed.filter((x) => x !== g);
    if (on) collapsed.push(g);
    try { localStorage.setItem('jobsCollapsed', JSON.stringify(collapsed)); } catch (e) { /* ignore */ }
  }));

  // Selection + bulk bar
  const bar = document.getElementById('bulk-bar');
  if (!bar) return;
  const boxes = () => Array.from(document.querySelectorAll('input[data-sel]'));
  const selected = () => boxes().filter((b) => b.checked);
  const all = document.querySelector('[data-sel-all]');
  const sync = () => {
    const sel = selected();
    bar.hidden = sel.length === 0;
    bar.querySelector('[data-bulk-n]').textContent = sel.length;
    document.documentElement.toggleAttribute('data-hold-refresh', sel.length > 0);
    boxes().forEach((b) => b.closest('tr').classList.toggle('selected', b.checked));
    if (all) { all.checked = sel.length > 0 && sel.length === boxes().length; all.indeterminate = sel.length > 0 && !all.checked; }
    document.querySelectorAll('[data-sel-group]').forEach((gb) => {
      const inG = boxes().filter((b) => b.closest('tr').dataset.group === gb.dataset.selGroup);
      const n = inG.filter((b) => b.checked).length;
      gb.checked = n > 0 && n === inG.length; gb.indeterminate = n > 0 && n < inG.length;
    });
  };
  function clearSel() { boxes().forEach((b) => { b.checked = false; }); sync(); }
  let lastIdx = null;
  boxes().forEach((b, i) => b.addEventListener('click', (e) => {
    // Shift+click selects a range.
    if (e.shiftKey && lastIdx !== null) { const [a, z] = [Math.min(lastIdx, i), Math.max(lastIdx, i)]; boxes().slice(a, z + 1).forEach((x) => { x.checked = b.checked; }); }
    lastIdx = i; sync();
  }));
  all && all.addEventListener('change', () => { boxes().forEach((b) => { if (!b.closest('tr').hidden) b.checked = all.checked; }); sync(); });
  document.querySelectorAll('[data-sel-group]').forEach((gb) => gb.addEventListener('change', () => {
    boxes().forEach((b) => { if (b.closest('tr').dataset.group === gb.dataset.selGroup) b.checked = gb.checked; }); sync();
  }));
  bar.querySelector('[data-bulk-clear]').addEventListener('click', clearSel);

  const exp = bar.querySelector('[data-bulk-export]');
  exp && exp.addEventListener('click', () => { location.href = base + '/jobs/export?ids=' + selected().map((b) => b.value).join(','); });

  const labels = { run: 'Να εκτελεστούν τώρα', enable: 'Να ενεργοποιηθούν', pause: 'Να τεθούν σε παύση', delete: 'Να διαγραφούν ΟΡΙΣΤΙΚΑ (μαζί με το ιστορικό τους)', group: 'Να μετακινηθούν' };
  bar.querySelectorAll('[data-bulk]').forEach((btn) => btn.addEventListener('click', async () => {
    const action = btn.dataset.bulk;
    const sel = selected();
    if (!sel.length) return;
    const names = sel.slice(0, 5).map((b) => '• ' + b.closest('tr').dataset.name).join('\n') + (sel.length > 5 ? '\n… και ' + (sel.length - 5) + ' ακόμα' : '');
    const payload = { action, ids: sel.map((b) => b.value) };
    if (action === 'group') payload.group = document.getElementById('bulk-group-in').value.trim();
    if (['run', 'delete'].includes(action) || sel.length > 1) {
      const what = action === 'group' ? labels.group + (payload.group ? ' στην ομάδα «' + payload.group + '»' : ' εκτός ομάδας') : labels[action];
      if (!confirm(what + ' ' + sel.length + ' jobs;\n\n' + names)) return;
    }
    bar.querySelectorAll('button').forEach((b) => { b.disabled = true; });
    btn.classList.add('loading');
    try {
      const d = await window.api('/jobs/bulk', { method: 'POST', json: payload });
      try { sessionStorage.setItem('jobsToast', JSON.stringify(d)); } catch (e) { /* ignore */ }
      location.reload();
    } catch (e) {
      window.toast(e.message || 'Αποτυχία.', 'error');
      bar.querySelectorAll('button').forEach((b) => { b.disabled = false; });
      btn.classList.remove('loading');
    }
  }));
  sync();
})();
</script>
