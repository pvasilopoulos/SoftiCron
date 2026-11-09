<?php
/** @var array $page @var array $f @var array $users @var array $areaCounts */
use App\Auth\Auth;
use App\Controllers\AuditController;
use App\Services\Audit;

$tz = Auth::tenant()['timezone'];
$base = array_filter($f, fn ($v) => $v !== '' && $v !== 0);
$link = fn (array $c) => url('audit', array_filter(array_merge($base, $c), fn ($v) => $v !== '' && $v !== 0 && $v !== null));
$userName = '';
foreach ($users as $u) {
    if ((int) $u['id'] === $f['user']) {
        $userName = $u['name'];
    }
}
$chips = [];
foreach ([
    'area' => AuditController::AREAS[$f['area']] ?? '', 'act' => Audit::ACTIONS[$f['act']] ?? '', 'user' => $userName,
    'period' => AuditController::PERIODS[$f['period']] ?? '', 'from' => $f['from'] ? 'Από ' . date('d/m/Y', strtotime($f['from'])) : '',
    'to' => $f['to'] ? 'Έως ' . date('d/m/Y', strtotime($f['to'])) : '', 'changes' => $f['changes'] ? 'Μόνο με αλλαγές' : '',
    'q' => $f['q'] !== '' ? '«' . $f['q'] . '»' : '',
] as $k => $l) {
    if ($l !== '') {
        $chips[] = [$l, $link([$k => ''])];
    }
}
$verb = function (string $action): array {
    $v = substr($action, strrpos($action, '.') + 1);
    return match (true) {
        in_array($v, ['created', 'invited', 'joined', 'enabled'], true) => ['#16A34A', 'plus'],
        in_array($v, ['deleted', 'removed', 'revoked', 'disabled', 'login_failed'], true) => ['#DC2626', 'trash'],
        in_array($v, ['login', 'logout'], true) => ['#64748B', 'user'],
        in_array($v, ['run_now', 'retried'], true) => ['#7C3AED', 'play'],
        default => ['var(--accent)', 'edit'],
    };
};
$entityUrl = function (array $a): ?string {
    $id = $a['entity_id'];
    if (!$id || !ctype_digit((string) $id)) {
        return null;
    }
    return match ($a['entity_type']) {
        'job' => url('jobs/' . $id . '/edit'), 'run' => url('runs/' . $id), 'connector' => url('connectors/' . $id . '/edit'),
        'template' => url('templates/' . $id . '/edit'), 'user' => url('users/' . $id . '/edit'), 'role' => url('roles/' . $id . '/edit'),
        default => null,
    };
};
$fmtVal = fn ($v) => $v === null ? '∅' : (is_scalar($v) ? (is_bool($v) ? ($v ? 'ναι' : 'όχι') : (string) $v) : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$days = ['Κυριακή', 'Δευτέρα', 'Τρίτη', 'Τετάρτη', 'Πέμπτη', 'Παρασκευή', 'Σάββατο'];
?>
<div class="page-head">
  <div><h1>Audit log</h1><p class="sub">Ποιος έκανε τι και πότε · οι κωδικοί και τα μυστικά δεν καταγράφονται ποτέ · <?= (int) $page['total'] ?> εγγραφές</p></div>
  <a class="btn" href="<?= e(url('audit/export', $base)) ?>"><?= icon('copy', 16) ?>Εξαγωγή CSV</a>
</div>

<?php if ($areaCounts): ?>
  <div class="stat-chips" role="group" aria-label="Περιοχές (7 ημέρες)">
    <a class="stat-chip<?= $f['area'] === '' ? ' on' : '' ?>" href="<?= e($link(['area' => ''])) ?>">Όλα <span class="n"><?= array_sum($areaCounts) ?></span></a>
    <?php foreach (AuditController::AREAS as $k => $l): if (empty($areaCounts[$k])) { continue; } ?>
      <a class="stat-chip<?= $f['area'] === $k ? ' on' : '' ?>" href="<?= e($link(['area' => $k, 'act' => ''])) ?>"><?= e($l) ?> <span class="n"><?= (int) $areaCounts[$k] ?></span></a>
    <?php endforeach; ?>
    <span class="xs muted" style="align-self:center">τελευταίες 7 ημέρες</span>
  </div>
<?php endif; ?>

<form class="card filters" method="get" action="<?= e(url('audit')) ?>">
  <div class="filters-main">
    <label class="searchbar"><?= icon('search', 16) ?><span class="sr-only">Αναζήτηση</span>
      <input name="q" value="<?= e($f['q']) ?>" placeholder="Αναζήτηση: στοιχείο, ID, IP ή τιμή αλλαγής">
    </label>
    <label class="sr-only" for="af-user">Χρήστης</label>
    <select id="af-user" class="select" name="user" style="width:auto;min-width:160px">
      <option value="">Όλοι οι χρήστες</option>
      <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $f['user'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
    </select>
    <label class="sr-only" for="af-period">Περίοδος</label>
    <select id="af-period" class="select" name="period" style="width:auto">
      <option value="">Όλη η περίοδος</option>
      <?php foreach (AuditController::PERIODS as $k => $l): ?><option value="<?= $k ?>" <?= $f['period'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-primary" type="submit">Εφαρμογή</button>
  </div>
  <details <?= $f['act'] || $f['from'] || $f['to'] || $f['changes'] ? 'open' : '' ?>>
    <summary class="small" style="cursor:pointer;color:var(--accent-text);font-weight:600">Περισσότερα φίλτρα</summary>
    <div class="filters-more" style="margin-top:10px">
      <label class="field">Περιοχή
        <select class="select" name="area"><option value="">Όλες</option><?php foreach (AuditController::AREAS as $k => $l): ?><option value="<?= $k ?>" <?= $f['area'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
      </label>
      <label class="field">Ενέργεια
        <select class="select" name="act"><option value="">Όλες</option><?php foreach (Audit::ACTIONS as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['act'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
      </label>
      <label class="field">Από<input class="input" type="date" name="from" value="<?= e($f['from']) ?>"></label>
      <label class="field">Έως<input class="input" type="date" name="to" value="<?= e($f['to']) ?>"></label>
      <label class="check small" style="align-self:end;min-height:40px"><input type="checkbox" name="changes" value="1" <?= $f['changes'] ? 'checked' : '' ?>>Μόνο με αλλαγές πεδίων</label>
    </div>
  </details>
  <?= App\Core\View::partial('partials/active_filters', ['chips' => $chips, 'clear' => url('audit')]) ?>
</form>

<?php if (!$page['rows']): ?>
  <div class="card empty"><?= icon('audit', 40) ?><span><?= $base ? 'Καμία καταγραφή με αυτά τα φίλτρα.' : 'Καμία καταγραφή ακόμα.' ?></span></div>
<?php else: ?>
  <section class="card flush">
    <?php $lastDay = null; foreach ($page['rows'] as $a):
        $local = (new DateTimeImmutable($a['created_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz));
        $day = $local->format('Y-m-d');
        [$color, $ic] = $verb($a['action']);
        $changes = $a['changes'] ? json_decode($a['changes'], true) : null;
        $eurl = $entityUrl($a);
        if ($day !== $lastDay): $lastDay = $day; ?>
          <div class="audit-day"><?= e($days[(int) $local->format('w')] . ' ' . $local->format('d/m/Y')) ?></div>
        <?php endif; ?>
      <div class="audit-item">
        <span class="audit-ic" style="color:<?= $color ?>;background:color-mix(in srgb, <?= $color ?> 12%, transparent)"><?= icon($ic, 15) ?></span>
        <div class="stack-sm grow" style="gap:3px;min-width:0">
          <div class="row" style="gap:6px">
            <strong class="small"><?= e($a['user_name'] ?? 'Σύστημα') ?></strong>
            <span class="small"><?= e(mb_strtolower(Audit::ACTIONS[$a['action']] ?? $a['action'])) ?></span>
            <?php if ($a['summary']): ?>
              <?php if ($eurl): ?><a class="small" style="font-weight:600" href="<?= e($eurl) ?>"><?= e($a['summary']) ?></a><?php else: ?><span class="small" style="font-weight:600"><?= e($a['summary']) ?></span><?php endif; ?>
            <?php endif; ?>
          </div>
          <span class="xs muted"><?= e($local->format('H:i:s')) ?> · <?= e(rel_time($a['created_at'])) ?> · IP <?= e($a['ip']) ?><?= $a['entity_id'] ? ' · #' . e($a['entity_id']) : '' ?></span>
          <?php if ($changes): ?>
            <details class="audit-changes">
              <summary class="xs" style="cursor:pointer;color:var(--accent-text);font-weight:600"><?= count($changes) ?> <?= count($changes) === 1 ? 'αλλαγή' : 'αλλαγές' ?></summary>
              <table class="table" style="font-size:12.5px;margin-top:6px">
                <thead><tr><th style="padding-left:0">Πεδίο</th><th>Πριν</th><th>Μετά</th></tr></thead>
                <tbody>
                  <?php foreach ($changes as $field => [$old, $new]): ?>
                    <tr>
                      <td class="mono" style="padding-left:0;font-weight:600"><?= e($field) ?></td>
                      <td style="color:var(--fail-fg);word-break:break-word"><?= e(mb_strimwidth($fmtVal($old), 0, 300, '…')) ?></td>
                      <td style="color:var(--ok-fg);word-break:break-word"><?= e(mb_strimwidth($fmtVal($new), 0, 300, '…')) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </details>
          <?php endif; ?>
        </div>
        <?php if ($a['user_id']): ?><a class="btn btn-sm btn-icon" href="<?= e($link(['user' => (int) $a['user_id']])) ?>" title="Μόνο αυτός ο χρήστης" aria-label="Φίλτρο: μόνο αυτός ο χρήστης"><?= icon('user', 14) ?></a><?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?= App\Core\View::partial('partials/pagination', ['page' => $page]) ?>
  </section>
<?php endif; ?>
