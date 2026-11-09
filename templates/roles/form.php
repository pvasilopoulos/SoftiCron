<?php
/** @var array $role @var array $granted */
use App\Auth\Permissions;

$isNew = $role['id'] === null;
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="<?= e(url('roles')) ?>">Ρόλοι</a><span aria-hidden="true">/</span><span><?= $isNew ? 'Νέος' : e($role['name']) ?></span></div>
    <h1><?= $isNew ? 'Νέος ρόλος' : e($role['name']) ?></h1>
  </div>
</div>
<form method="post" action="<?= e($isNew ? url('roles') : url('roles/' . $role['id'])) ?>" class="card stack" style="max-width:760px">
  <?= csrf_field() ?>
  <div class="form-grid">
    <label class="field">Όνομα<input class="input" name="name" value="<?= e($role['name']) ?>" required maxlength="80"></label>
    <label class="field">Περιγραφή<input class="input" name="description" value="<?= e($role['description']) ?>" maxlength="255"></label>
  </div>
  <?php foreach (Permissions::grouped() as $group => $items): ?>
    <fieldset class="stack-sm" style="border:1px solid var(--border);border-radius:12px;padding:14px">
      <legend class="small" style="font-weight:600;padding:0 6px"><?= e($group) ?></legend>
      <?php foreach ($items as $code => $label): ?>
        <label class="check"><input type="checkbox" name="perms[]" value="<?= e($code) ?>" <?= in_array($code, $granted, true) ? 'checked' : '' ?>><?= e($label) ?> <span class="xs muted mono"><?= e($code) ?></span></label>
      <?php endforeach; ?>
    </fieldset>
  <?php endforeach; ?>
  <?php if (!$isNew && $role['tenant_id'] === null): ?>
    <p class="notice">Ενσωματωμένος ρόλος: με την αποθήκευση δημιουργείται προσαρμοσμένη έκδοση μόνο για τον οργανισμό σου.</p>
  <?php endif; ?>
  <div class="form-actions">
    <?php if (!$isNew && $role['tenant_id'] !== null): ?>
      <button class="btn btn-danger" type="submit" form="role-del"><?= $role['based_on'] ? 'Επαναφορά προεπιλογών' : 'Διαγραφή' ?></button><span class="grow"></span>
    <?php endif; ?>
    <a class="btn" href="<?= e(url('roles')) ?>">Ακύρωση</a>
    <button class="btn btn-primary" type="submit">Αποθήκευση</button>
  </div>
</form>
<?php if (!$isNew && $role['tenant_id'] !== null): ?>
  <form id="role-del" method="post" action="<?= e(url('roles/' . $role['id'] . '/delete')) ?>" class="hidden" data-confirm="<?= $role['based_on'] ? 'Να επανέλθει ο ρόλος στα προεπιλεγμένα δικαιώματα;' : 'Να διαγραφεί ο ρόλος;' ?>"><?= csrf_field() ?></form>
<?php endif; ?>
