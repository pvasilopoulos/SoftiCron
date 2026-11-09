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
  <div class="row between">
    <span class="small muted"><span data-perm-count><?= count($granted) ?></span> / <?= count(Permissions::ALL) ?> δικαιώματα</span>
    <div class="row" style="gap:6px">
      <button class="btn btn-sm" type="button" data-perm-all="1">Όλα</button>
      <button class="btn btn-sm" type="button" data-perm-all="0">Κανένα</button>
    </div>
  </div>
  <?php foreach (Permissions::grouped() as $group => $items): ?>
    <fieldset class="stack-sm" style="border:1px solid var(--border);border-radius:12px;padding:14px" data-perm-group>
      <legend class="small" style="font-weight:600;padding:0 6px"><?= e($group) ?></legend>
      <div class="row between" style="margin-top:-4px">
        <span class="xs muted"><?= e(Permissions::GROUPS[$group] ?? '') ?></span>
        <label class="check xs"><input type="checkbox" data-perm-group-toggle>Όλα της ομάδας</label>
      </div>
      <?php foreach ($items as $code => [$label, $desc]): ?>
        <label class="check" style="align-items:flex-start">
          <input type="checkbox" name="perms[]" value="<?= e($code) ?>" <?= in_array($code, $granted, true) ? 'checked' : '' ?> data-perm="<?= e($code) ?>" data-requires="<?= e(implode(',', Permissions::REQUIRES[$code] ?? [])) ?>" style="margin-top:3px">
          <span class="stack-sm" style="gap:1px"><span><?= e($label) ?> <span class="xs muted mono"><?= e($code) ?></span></span><span class="xs muted"><?= e($desc) ?><?php if (!empty(Permissions::REQUIRES[$code])): ?> · περιλαμβάνει: <?= e(implode(', ', array_map([Permissions::class, 'label'], Permissions::REQUIRES[$code]))) ?><?php endif; ?></span></span>
        </label>
      <?php endforeach; ?>
    </fieldset>
  <?php endforeach; ?>
  <script>
  document.addEventListener('DOMContentLoaded', () => {
    const boxes = [...document.querySelectorAll('[data-perm]')];
    const byCode = Object.fromEntries(boxes.map((b) => [b.dataset.perm, b]));
    const sync = () => {
      document.querySelector('[data-perm-count]').textContent = boxes.filter((b) => b.checked).length;
      document.querySelectorAll('[data-perm-group]').forEach((g) => {
        const own = [...g.querySelectorAll('[data-perm]')];
        const t = g.querySelector('[data-perm-group-toggle]');
        t.checked = own.every((b) => b.checked);
        t.indeterminate = !t.checked && own.some((b) => b.checked);
      });
    };
    // Checking a permission also checks what it depends on; unchecking removes what depends on it.
    const check = (b) => { b.checked = true; (b.dataset.requires || '').split(',').filter(Boolean).forEach((c) => byCode[c] && !byCode[c].checked && check(byCode[c])); };
    const uncheck = (b) => { b.checked = false; boxes.filter((o) => o.checked && (o.dataset.requires || '').split(',').includes(b.dataset.perm)).forEach(uncheck); };
    boxes.forEach((b) => b.addEventListener('change', () => { b.checked ? check(b) : uncheck(b); sync(); }));
    document.querySelectorAll('[data-perm-group-toggle]').forEach((t) => t.addEventListener('change', () => {
      t.closest('[data-perm-group]').querySelectorAll('[data-perm]').forEach((b) => (t.checked ? check(b) : uncheck(b)));
      sync();
    }));
    document.querySelectorAll('[data-perm-all]').forEach((btn) => btn.addEventListener('click', () => {
      boxes.forEach((b) => (btn.dataset.permAll === '1' ? check(b) : uncheck(b)));
      sync();
    }));
    sync();
  });
  </script>
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
