<?php
/** @var array $rows */
use App\Services\Notifier;
?>
<div class="page-head">
  <div><h1>Templates μηνυμάτων</h1><p class="sub">Το κείμενο των ειδοποιήσεων ανά γεγονός και κανάλι. Το «Προεπιλογή» χρησιμοποιείται αυτόματα.</p></div>
  <a class="btn btn-primary" href="<?= e(url('templates/new')) ?>"><?= icon('plus', 16) ?>Νέο template</a>
</div>
<section class="card flush">
  <?php if (!$rows): ?>
    <div class="empty"><?= icon('templates', 40) ?><span>Δεν υπάρχουν templates. Θα χρησιμοποιούνται τα ενσωματωμένα.</span></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table rtable">
        <thead><tr><th>Όνομα</th><th>Γεγονός</th><th>Κανάλι</th><th>Θέμα / τίτλος</th><th class="num"></th></tr></thead>
        <tbody>
          <?php foreach ($rows as $t): ?>
            <tr>
              <td class="primary"><a class="strong" href="<?= e(url('templates/' . $t['id'] . '/edit')) ?>"><?= e($t['name']) ?></a> <?= $t['is_default'] ? '<span class="tag">Προεπιλογή</span>' : '' ?></td>
              <td data-label="Γεγονός"><?= e(Notifier::EVENTS[$t['event']] ?? $t['event']) ?></td>
              <td data-label="Κανάλι"><?= e(Notifier::CHANNELS[$t['channel']] ?? $t['channel']) ?></td>
              <td data-label="Θέμα" class="mono xs muted"><?= e(mb_strimwidth((string) ($t['subject'] ?? '—'), 0, 60, '…')) ?></td>
              <td class="num"><a class="btn btn-sm" href="<?= e(url('templates/' . $t['id'] . '/edit')) ?>"><?= icon('edit', 14) ?>Επεξεργασία</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
