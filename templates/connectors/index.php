<?php
/** @var array $rows @var string $type @var string $q @var array $counts @var array $usedChannels */
use App\Auth\Auth;
use App\Services\Connectors;
use App\Services\Notifier;

$tz = Auth::tenant()['timezone'];
$logo = function (string $t, int $size = 40): string {
    [, , $color, $txt] = Connectors::CATALOG[$t] ?? [null, null, '#64748B', '?'];
    return '<span class="conn-logo" style="width:' . $size . 'px;height:' . $size . 'px;background:' . e($color) . '">' . e($txt) . '</span>';
};
$total = array_sum($counts);
?>
<div class="page-head">
  <div><h1>Connectors</h1><p class="sub">Τα κανάλια μέσω των οποίων φεύγουν οι ειδοποιήσεις των jobs · <?= (int) $total ?> ρυθμισμένοι</p></div>
  <a class="btn" href="<?= e(url('notifications/history')) ?>"><?= icon('bell', 16) ?>Ιστορικό αποστολών</a>
</div>

<?php if (\App\Auth\Auth::can('connectors.manage')): ?>
<section class="stack-sm">
  <div class="row between"><h2>Διαθέσιμα add-ons</h2><span class="xs muted">Πρόσθεσε όσα κανάλια χρειάζεσαι · κάθε τύπος μπορεί να έχει πολλούς connectors</span></div>
  <div class="addon-grid">
    <?php foreach (Connectors::CATALOG as $t => [$channel, $desc]): $n = (int) ($counts[$t] ?? 0); ?>
      <a class="addon-card" href="<?= e(url('connectors/new', ['type' => $t])) ?>">
        <div class="row" style="gap:12px;flex-wrap:nowrap">
          <?= $logo($t) ?>
          <div class="stack-sm grow" style="gap:0;min-width:0">
            <strong class="small"><?= e(Connectors::TYPES[$t]) ?></strong>
            <span class="xs <?= $n ? '' : 'muted' ?>" style="<?= $n ? 'color:var(--ok-fg);font-weight:600' : '' ?>"><?= $n ? '✓ ' . $n . ' ρυθμισμένος' . ($n > 1 ? 'οι' : '') : 'Δεν έχει ρυθμιστεί' ?></span>
          </div>
          <span class="addon-plus" aria-hidden="true"><?= icon('plus', 16) ?></span>
        </div>
        <p class="xs muted" style="margin-top:8px"><?= e($desc) ?></p>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="stack-sm">
  <div class="row between">
    <h2>Οι connectors μου</h2>
    <?php if ($total): ?>
      <form method="get" action="<?= e(url('connectors')) ?>" class="row" style="gap:8px">
        <label class="searchbar" style="min-height:38px;max-width:240px"><?= icon('search', 14) ?><span class="sr-only">Αναζήτηση</span><input name="q" value="<?= e($q) ?>" placeholder="Αναζήτηση"></label>
        <input type="hidden" name="type" value="<?= e($type) ?>">
      </form>
    <?php endif; ?>
  </div>
  <?php if ($total): ?>
    <div class="chips" role="group" aria-label="Φίλτρο τύπου">
      <a class="chip round" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none" aria-pressed="<?= $type === '' ? 'true' : 'false' ?>" href="<?= e(url('connectors', ['q' => $q])) ?>">Όλοι <span class="xs"><?= (int) $total ?></span></a>
      <?php foreach ($counts as $t => $n): ?>
        <a class="chip round" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none" aria-pressed="<?= $type === $t ? 'true' : 'false' ?>" href="<?= e(url('connectors', ['type' => $t, 'q' => $q])) ?>"><?= e(Connectors::TYPES[$t] ?? $t) ?> <span class="xs"><?= (int) $n ?></span></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!$rows): ?>
    <div class="card empty"><?= icon('connectors', 40) ?>
      <span><?= $total ? 'Κανένας connector με αυτά τα φίλτρα.' : 'Δεν έχεις ρυθμίσει connectors ακόμα. Διάλεξε ένα add-on παραπάνω για να ξεκινήσεις.' ?></span>
    </div>
  <?php else: ?>
    <div class="conn-grid">
      <?php foreach ($rows as $c):
          $channel = Connectors::channelOf($c['type']);
          $jobsUsing = (int) ($usedChannels[$channel] ?? 0);
          $statusCls = $c['last_test_at'] === null ? 'off' : ($c['last_test_ok'] ? 'ok' : 'fail');
          $statusTxt = $c['last_test_at'] === null ? 'Δεν έχει δοκιμαστεί' : ($c['last_test_ok'] ? 'Λειτουργεί' : 'Σφάλμα στο τελευταίο τεστ');
      ?>
        <article class="card conn-card">
          <div class="row" style="gap:12px;flex-wrap:nowrap;align-items:flex-start">
            <?= $logo($c['type'], 44) ?>
            <div class="stack-sm grow" style="gap:2px;min-width:0">
              <a class="strong" href="<?= e(url('connectors/' . $c['id'] . '/edit')) ?>" style="color:var(--text);font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($c['name']) ?></a>
              <span class="xs muted"><?= e(Connectors::TYPES[$c['type']] ?? $c['type']) ?></span>
            </div>
            <?php if ($c['is_default']): ?><span class="tag" style="background:var(--accent-soft);color:var(--accent-text)">Προεπιλογή</span><?php endif; ?>
          </div>
          <div class="conn-meta">
            <div><span class="badge <?= $statusCls ?>"><span class="dot"></span><?= e($statusTxt) ?></span>
              <?php if ($c['last_test_at']): ?><span class="xs muted"> · <?= e(rel_time($c['last_test_at'])) ?></span><?php endif; ?></div>
            <?php if (!$c['last_test_ok'] && $c['last_test_error']): ?><p class="xs" style="color:var(--fail-fg);margin-top:4px"><?= e(mb_strimwidth($c['last_test_error'], 0, 140, '…')) ?></p><?php endif; ?>
            <p class="xs muted" style="margin-top:6px">
              <?= $jobsUsing ? $jobsUsing . ' jobs στέλνουν μέσω ' . e(Notifier::CHANNELS[$channel] ?? $channel) : 'Κανένα job δεν χρησιμοποιεί ακόμα αυτό το κανάλι' ?>
              <?php if ((int) $c['sent_7d']): ?> · <?= (int) $c['sent_7d'] ?> αποστολές 7 ημερών<?php endif; ?>
            </p>
          </div>
          <div class="row" style="gap:6px;margin-top:auto">
            <?php if (\App\Auth\Auth::can('connectors.test')): ?>
            <form method="post" action="<?= e(url('connectors/' . $c['id'] . '/test')) ?>" class="inline" data-loading>
              <?= csrf_field() ?><input type="hidden" name="from" value="list">
              <button class="btn btn-sm" type="submit" title="<?= in_array($c['type'], ['smtp', 'm365'], true) ? 'Στέλνει δοκιμαστικό email στο δικό σου email' : 'Στέλνει δοκιμαστικό μήνυμα' ?>"><?= icon('send', 14) ?>Δοκιμή</button>
            </form>
            <?php endif; ?>
            <?php if (\App\Auth\Auth::can('connectors.manage')): ?>
            <a class="btn btn-sm" href="<?= e(url('connectors/' . $c['id'] . '/edit')) ?>"><?= icon('edit', 14) ?>Ρυθμίσεις</a>
            <?php endif; ?>
            <span class="grow"></span>
            <?php if (\App\Auth\Auth::can('connectors.manage')): ?>
            <?php if (!$c['is_default']): ?>
              <form method="post" action="<?= e(url('connectors/' . $c['id'] . '/default')) ?>" class="inline"><?= csrf_field() ?><button class="btn btn-sm btn-icon" type="submit" title="Ορισμός ως προεπιλογή" aria-label="<?= e('Ορισμός ως προεπιλογή: ' . $c['name']) ?>"><?= icon('check', 14) ?></button></form>
            <?php endif; ?>
            <form method="post" action="<?= e(url('connectors/' . $c['id'] . '/duplicate')) ?>" class="inline"><?= csrf_field() ?><button class="btn btn-sm btn-icon" type="submit" title="Αντίγραφο" aria-label="<?= e('Αντίγραφο: ' . $c['name']) ?>"><?= icon('copy', 14) ?></button></form>
            <form method="post" action="<?= e(url('connectors/' . $c['id'] . '/delete')) ?>" class="inline" data-confirm="<?= e('Να διαγραφεί ο connector «' . $c['name'] . '»;') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-icon btn-danger" type="submit" title="Διαγραφή" aria-label="<?= e('Διαγραφή: ' . $c['name']) ?>"><?= icon('trash', 14) ?></button></form>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
