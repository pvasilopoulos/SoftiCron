<?php
/** @var array $page @var string $action @var int $user @var string $q @var array $users */
use App\Auth\Auth;
use App\Services\Audit;

$tz = Auth::tenant()['timezone'];
$groups = ['job' => 'Jobs', 'run' => 'Εκτελέσεις', 'connector' => 'Connectors', 'template' => 'Templates', 'user' => 'Χρήστες', 'role' => 'Ρόλοι', 'settings' => 'Ρυθμίσεις', 'branding' => 'Branding', 'auth' => 'Συνδέσεις', 'profile' => 'Προφίλ'];
?>
<div class="page-head"><div><h1>Audit log</h1><p class="sub">Ποιος έκανε τι και πότε. Οι κωδικοί και τα μυστικά δεν καταγράφονται ποτέ.</p></div></div>
<form class="row" method="get" action="<?= e(url('audit')) ?>">
  <label class="field" style="flex:1 1 180px">Περιοχή
    <select class="select" name="action" data-autosubmit><option value="">Όλες</option><?php foreach ($groups as $k => $l): ?><option value="<?= $k ?>" <?= $action === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  </label>
  <label class="field" style="flex:1 1 180px">Χρήστης
    <select class="select" name="user" data-autosubmit><option value="">Όλοι</option><?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $user === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select>
  </label>
  <label class="field" style="flex:2 1 220px">Αναζήτηση<input class="input" name="q" value="<?= e($q) ?>" placeholder="Όνομα ή ID"></label>
  <button class="btn" type="submit" style="align-self:flex-end">Φίλτρο</button>
</form>
<section class="card flush">
  <?php if (!$page['rows']): ?>
    <div class="empty"><?= icon('audit', 40) ?><span>Καμία καταγραφή.</span></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table rtable">
        <thead><tr><th>Ώρα</th><th>Χρήστης</th><th>Ενέργεια</th><th>Στοιχείο</th><th>Αλλαγές</th></tr></thead>
        <tbody>
          <?php foreach ($page['rows'] as $a): $changes = $a['changes'] ? json_decode($a['changes'], true) : null; ?>
            <tr>
              <td data-label="Ώρα" class="small nowrap"><?= e(fmt_dt($a['created_at'], $tz, 'd/m/Y H:i:s')) ?></td>
              <td data-label="Χρήστης" class="small"><?= e($a['user_name'] ?? 'Σύστημα') ?><div class="xs muted"><?= e($a['ip']) ?></div></td>
              <td class="primary"><strong class="small"><?= e(Audit::ACTIONS[$a['action']] ?? $a['action']) ?></strong></td>
              <td data-label="Στοιχείο" class="small"><?= e($a['summary'] ?? '') ?><?= $a['entity_id'] ? ' <span class="xs muted mono">#' . e($a['entity_id']) . '</span>' : '' ?></td>
              <td data-label="Αλλαγές" class="small">
                <?php if ($changes): ?>
                  <details><summary><?= count($changes) ?> πεδία</summary>
                    <div class="stack-sm" style="margin-top:6px;gap:4px">
                      <?php foreach ($changes as $field => [$old, $new]): ?>
                        <div class="xs"><span class="mono" style="font-weight:600"><?= e($field) ?></span>: <span style="color:var(--fail-fg);text-decoration:line-through"><?= e(is_scalar($old) || $old === null ? (string) ($old ?? '∅') : json_encode($old, JSON_UNESCAPED_UNICODE)) ?></span> → <span style="color:var(--ok-fg)"><?= e(is_scalar($new) || $new === null ? (string) ($new ?? '∅') : json_encode($new, JSON_UNESCAPED_UNICODE)) ?></span></div>
                      <?php endforeach; ?>
                    </div>
                  </details>
                <?php else: ?><span class="muted">—</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= App\Core\View::partial('partials/pagination', ['page' => $page]) ?>
  <?php endif; ?>
</section>
