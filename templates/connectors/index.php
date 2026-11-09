<?php
/** @var array $rows */
use App\Auth\Auth;
use App\Services\Connectors;

$tz = Auth::tenant()['timezone'];
?>
<div class="page-head">
  <div><h1>Connectors</h1><p class="sub">Κανάλια αποστολής ειδοποιήσεων: Email (SMTP, Microsoft 365) και Telegram</p></div>
  <div class="row">
    <?php foreach (Connectors::TYPES as $k => $l): ?>
      <a class="btn<?= $k === 'smtp' ? ' btn-primary' : '' ?>" href="<?= e(url('connectors/new', ['type' => $k])) ?>"><?= icon('plus', 16) ?><?= e($l) ?></a>
    <?php endforeach; ?>
  </div>
</div>
<section class="card flush">
  <?php if (!$rows): ?>
    <div class="empty"><?= icon('connectors', 40) ?><span>Δεν υπάρχουν connectors. Πρόσθεσε έναν για να στέλνεις ειδοποιήσεις μέσω email ή Telegram.</span></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table rtable">
        <thead><tr><th>Όνομα</th><th>Τύπος</th><th>Τελευταίο τεστ</th><th class="num"></th></tr></thead>
        <tbody>
          <?php foreach ($rows as $c): ?>
            <tr>
              <td class="primary"><a class="strong" href="<?= e(url('connectors/' . $c['id'] . '/edit')) ?>"><?= e($c['name']) ?></a> <?= $c['is_default'] ? '<span class="tag">Προεπιλογή</span>' : '' ?></td>
              <td data-label="Τύπος"><?= e(Connectors::TYPES[$c['type']]) ?></td>
              <td data-label="Τελευταίο τεστ">
                <?php if ($c['last_test_at'] === null): ?><span class="muted small">Ποτέ</span>
                <?php elseif ($c['last_test_ok']): ?><span class="badge ok">✓ <?= e(fmt_dt($c['last_test_at'], $tz, 'd/m H:i')) ?></span>
                <?php else: ?><span class="badge fail" title="<?= e($c['last_test_error']) ?>">✗ <?= e(fmt_dt($c['last_test_at'], $tz, 'd/m H:i')) ?></span><?php endif; ?>
              </td>
              <td class="num"><a class="btn btn-sm" href="<?= e(url('connectors/' . $c['id'] . '/edit')) ?>"><?= icon('edit', 14) ?>Επεξεργασία</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
