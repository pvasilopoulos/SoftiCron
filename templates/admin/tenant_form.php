<?php
/** @var array $t */
use App\Services\CronHelper;

$isNew = $t['id'] === null;
$v = fn (string $k) => old($k, $t[$k] ?? '');
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="<?= e(url('admin/tenants')) ?>">Tenants</a><span aria-hidden="true">/</span><span><?= $isNew ? 'Νέος' : e($t['name']) ?></span></div>
    <h1><?= $isNew ? 'Νέος tenant' : e($t['name']) ?></h1>
  </div>
</div>
<form method="post" action="<?= e($isNew ? url('admin/tenants') : url('admin/tenants/' . $t['id'])) ?>" class="card stack" style="max-width:820px">
  <?= csrf_field() ?>
  <div class="form-grid">
    <label class="field">Όνομα<input class="input" name="name" value="<?= e($v('name')) ?>" required maxlength="150"></label>
    <label class="field">Slug <span class="help">Κενό = από το όνομα</span><input class="input mono" name="slug" value="<?= e($v('slug')) ?>" maxlength="80"></label>
    <label class="field">Ζώνη ώρας
      <select class="select" name="timezone"><?php foreach (CronHelper::timezones() as $z): ?><option <?= $z === $v('timezone') ? 'selected' : '' ?>><?= e($z) ?></option><?php endforeach; ?></select>
    </label>
    <label class="field">Κατάσταση
      <select class="select" name="status"><option value="active" <?= $v('status') === 'active' ? 'selected' : '' ?>>Ενεργός</option><option value="suspended" <?= $v('status') === 'suspended' ? 'selected' : '' ?>>Σε αναστολή</option></select>
    </label>
    <label class="field">Χρώμα brand<input class="input" type="color" name="brand_color" value="<?= e($v('brand_color')) ?>" style="padding:2px"></label>
  </div>
  <h2>Όρια πλάνου</h2>
  <div class="form-grid">
    <label class="field">Μέγιστα jobs<input class="input" type="number" min="1" name="max_jobs" value="<?= e($v('max_jobs')) ?>"></label>
    <label class="field">Ελάχιστο διάστημα (λεπτά)<input class="input" type="number" min="1" name="min_interval_minutes" value="<?= e($v('min_interval_minutes')) ?>"></label>
    <label class="field">Διατήρηση logs (ημέρες)<input class="input" type="number" min="1" name="log_retention_days" value="<?= e($v('log_retention_days')) ?>"></label>
  </div>
  <?php if ($isNew): ?>
    <label class="field">Email ιδιοκτήτη (Owner) <span class="help">Υπάρχων χρήστης προστίθεται αμέσως· νέος λαμβάνει πρόσκληση</span>
      <input class="input" type="email" name="owner_email" value="<?= e(old('owner_email')) ?>">
    </label>
  <?php endif; ?>
  <div class="form-actions"><a class="btn" href="<?= e(url('admin/tenants')) ?>">Ακύρωση</a><button class="btn btn-primary" type="submit">Αποθήκευση</button></div>
</form>
