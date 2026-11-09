<?php
/** @var array $roles @var array $perms */
use App\Auth\Permissions;
use App\Services\Roles;
?>
<div class="page-head">
  <div><h1>Ρόλοι & δικαιώματα</h1><p class="sub">Τσέκαρε ή ξετσέκαρε δικαιώματα και πάτα «Αποθήκευση». Ο Owner έχει πάντα όλα τα δικαιώματα.</p></div>
  <div class="row">
    <a class="btn" href="<?= e(url('roles/new')) ?>"><?= icon('plus', 16) ?>Νέος ρόλος</a>
    <button class="btn btn-primary" type="submit" form="matrix-form"><?= icon('check', 16) ?>Αποθήκευση</button>
  </div>
</div>
<form id="matrix-form" method="post" action="<?= e(url('roles/matrix')) ?>" class="card flush">
  <?= csrf_field() ?>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Δικαίωμα</th>
          <?php foreach ($roles as $r): $locked = (int) $r['id'] === Roles::OWNER; ?>
            <th style="text-align:center;vertical-align:top">
              <?php if ($locked): ?>
                <span class="row" style="justify-content:center;gap:4px"><?= e($r['name']) ?><?= icon('lock', 12) ?></span>
              <?php else: ?>
                <a href="<?= e(url('roles/' . $r['id'] . '/edit')) ?>" class="row" style="justify-content:center;gap:4px" title="Επεξεργασία ονόματος και περιγραφής"><?= e($r['name']) ?><?= icon('edit', 12) ?></a>
              <?php endif; ?>
              <div class="xs muted" style="text-transform:none;letter-spacing:0;font-weight:400"><?= (int) $r['members'] ?> μέλη<?= $r['based_on'] ? ' · προσαρμοσμένος' : (!$r['tenant_id'] ? '' : ' · δικός σου') ?></div>
            </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach (Permissions::ALL as $code => [$group, $label]): ?>
          <tr>
            <td><div class="small" style="font-weight:500"><?= e($label) ?></div><div class="xs muted mono"><?= e($code) ?></div></td>
            <?php foreach ($roles as $r):
                $has = in_array($code, $perms[$r['id']] ?? [], true);
                $locked = (int) $r['id'] === Roles::OWNER; ?>
              <td style="text-align:center">
                <input type="checkbox" name="perms[<?= (int) $r['id'] ?>][]" value="<?= e($code) ?>" <?= $has || $locked ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?>
                  aria-label="<?= e($r['name'] . ' · ' . $label) ?>" style="width:20px;height:20px;accent-color:var(--accent);cursor:<?= $locked ? 'not-allowed' : 'pointer' ?>">
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="form-actions" style="padding:14px 20px;border-top:1px solid var(--border)">
    <span class="xs muted grow">Οι αλλαγές στους ενσωματωμένους ρόλους ισχύουν μόνο για τον δικό σου οργανισμό.</span>
    <button class="btn btn-primary" type="submit">Αποθήκευση αλλαγών</button>
  </div>
</form>
