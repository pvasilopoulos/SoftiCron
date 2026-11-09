<?php
/** @var array $rows */
$link = $_SESSION['_invite_link'] ?? null;
unset($_SESSION['_invite_link']);
?>
<div class="page-head">
  <div><h1>Tenants</h1><p class="sub">Οργανισμοί της πλατφόρμας</p></div>
  <div class="row">
    <a class="btn" href="<?= e(url('admin/system')) ?>"><?= icon('refresh', 16) ?>Κατάσταση συστήματος</a>
    <a class="btn btn-primary" href="<?= e(url('admin/tenants/new')) ?>"><?= icon('plus', 16) ?>Νέος tenant</a>
  </div>
</div>
<?php if ($link): ?>
  <div class="flash info" style="flex-wrap:wrap"><span>Σύνδεσμος πρόσκλησης ιδιοκτήτη:</span><code style="word-break:break-all"><?= e($link) ?></code><button class="btn btn-sm" type="button" data-copy="<?= e($link) ?>">Αντιγραφή</button></div>
<?php endif; ?>
<section class="card flush">
  <?php if (!$rows): ?>
    <div class="empty"><?= icon('building', 40) ?><span>Δεν υπάρχουν tenants ακόμα.</span><a class="btn btn-primary" href="<?= e(url('admin/tenants/new')) ?>">Δημιούργησε τον πρώτο</a></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table rtable">
        <thead><tr><th>Οργανισμός</th><th>Κατάσταση</th><th>Χρήστες</th><th>Jobs</th><th>Εκτελέσεις 24ω</th><th class="num"></th></tr></thead>
        <tbody>
          <?php foreach ($rows as $t): ?>
            <tr>
              <td class="primary"><div class="row" style="flex-wrap:nowrap"><span class="logo" style="width:32px;height:32px;font-size:12px;background:<?= e($t['brand_color']) ?>"><?= e(initials($t['name'])) ?></span><div class="stack-sm" style="gap:0"><a class="strong" href="<?= e(url('admin/tenants/' . $t['id'] . '/edit')) ?>"><?= e($t['name']) ?></a><span class="xs muted mono"><?= e($t['slug']) ?></span></div></div></td>
              <td data-label="Κατάσταση"><?= $t['status'] === 'active' ? '<span class="badge ok">Ενεργός</span>' : '<span class="badge fail">Σε αναστολή</span>' ?></td>
              <td data-label="Χρήστες"><?= (int) $t['users'] ?></td>
              <td data-label="Jobs"><?= (int) $t['jobs'] ?> / <?= (int) $t['max_jobs'] ?></td>
              <td data-label="Εκτελέσεις 24ω"><?= (int) $t['runs_24h'] ?></td>
              <td class="num">
                <form method="post" action="<?= e(url('admin/tenants/' . $t['id'] . '/enter')) ?>" class="inline"><?= csrf_field() ?><button class="btn btn-sm" type="submit">Είσοδος</button></form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
