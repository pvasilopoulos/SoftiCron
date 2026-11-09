<?php
/** @var array $grouped @var array $f @var int $total @var int $count */
use App\Services\Notifier;

$chIcon = ['email' => 'send', 'telegram' => 'send', 'push' => 'bell', 'chat' => 'connectors'];
$eventColor = ['failed' => '#DC2626', 'recovered' => '#16A34A', 'missed' => '#64748B', 'slow' => '#D97706', 'succeeded' => '#16A34A'];
$base = array_filter($f, fn ($v) => $v !== '');
$link = fn (array $c) => url('templates', array_filter(array_merge($base, $c), fn ($v) => $v !== ''));
$chips = [];
if ($f['event'] !== '') { $chips[] = [Notifier::EVENTS[$f['event']], $link(['event' => ''])]; }
if ($f['channel'] !== '') { $chips[] = [Notifier::TEMPLATE_CHANNELS[$f['channel']], $link(['channel' => ''])]; }
if ($f['q'] !== '') { $chips[] = ['«' . $f['q'] . '»', $link(['q' => ''])]; }
?>
<div class="page-head">
  <div><h1>Templates μηνυμάτων</h1><p class="sub">Το κείμενο κάθε ειδοποίησης, ανά γεγονός και κανάλι · <?= (int) $total ?> templates</p></div>
  <a class="btn btn-primary" href="<?= e(url('templates/new', ['event' => $f['event'], 'channel' => $f['channel']])) ?>"><?= icon('plus', 16) ?>Νέο template</a>
</div>

<form class="card filters" method="get" action="<?= e(url('templates')) ?>">
  <div class="filters-main">
    <label class="searchbar"><?= icon('search', 16) ?><span class="sr-only">Αναζήτηση</span>
      <input name="q" value="<?= e($f['q']) ?>" placeholder="Αναζήτηση σε όνομα, θέμα ή κείμενο">
    </label>
    <label class="sr-only" for="tf-ev">Γεγονός</label>
    <select id="tf-ev" class="select" name="event" style="width:auto" data-autosubmit>
      <option value="">Όλα τα γεγονότα</option>
      <?php foreach (Notifier::EVENTS as $k => $l): ?><option value="<?= $k ?>" <?= $f['event'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-primary" type="submit">Εφαρμογή</button>
  </div>
  <div class="chips" role="group" aria-label="Κανάλι">
    <a class="chip round" style="display:inline-flex;align-items:center;text-decoration:none" aria-pressed="<?= $f['channel'] === '' ? 'true' : 'false' ?>" href="<?= e($link(['channel' => ''])) ?>">Όλα τα κανάλια</a>
    <?php foreach (Notifier::TEMPLATE_CHANNELS as $k => $l): ?>
      <a class="chip round" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none" aria-pressed="<?= $f['channel'] === $k ? 'true' : 'false' ?>" href="<?= e($link(['channel' => $k])) ?>"><?= icon($chIcon[$k], 14) ?><?= e($k === 'chat' ? 'Chat & Webhook' : $l) ?></a>
    <?php endforeach; ?>
    <input type="hidden" name="channel" value="<?= e($f['channel']) ?>">
  </div>
  <?= App\Core\View::partial('partials/active_filters', ['chips' => $chips, 'clear' => url('templates')]) ?>
</form>

<?php if (!$grouped): ?>
  <div class="card empty"><?= icon('templates', 40) ?><span>Κανένα template με αυτά τα φίλτρα.</span><a class="btn" href="<?= e(url('templates')) ?>">Καθαρισμός φίλτρων</a></div>
<?php endif; ?>

<?php foreach ($grouped as $event => $items): ?>
  <section class="card tpl-event">
    <header>
      <span style="width:10px;height:10px;border-radius:50%;background:<?= $eventColor[$event] ?? 'var(--accent)' ?>"></span>
      <h2><?= e(Notifier::EVENTS[$event] ?? $event) ?></h2>
      <span class="xs muted"><?= count($items) ?> templates</span>
      <span class="grow"></span>
      <a class="btn btn-sm btn-ghost" href="<?= e(url('templates/new', ['event' => $event])) ?>"><?= icon('plus', 14) ?>Νέο</a>
    </header>
    <?php foreach ($items as $t): ?>
      <div class="tpl-row">
        <div class="row" style="gap:8px;flex-wrap:nowrap">
          <span class="tag" style="display:inline-flex;align-items:center;gap:6px"><?= icon($chIcon[$t['channel']] ?? 'bell', 12) ?><?= e($t['channel'] === 'chat' ? 'Chat' : (Notifier::TEMPLATE_CHANNELS[$t['channel']] ?? $t['channel'])) ?></span>
        </div>
        <div class="stack-sm" style="gap:2px;min-width:0">
          <div class="row" style="gap:8px">
            <a class="strong small" style="color:var(--text);font-weight:600" href="<?= e(url('templates/' . $t['id'] . '/edit')) ?>"><?= e($t['name']) ?></a>
            <?php if ($t['is_default']): ?><span class="badge ok">Προεπιλογή</span><?php endif; ?>
            <?php if ((int) $t['used_by']): ?><span class="xs muted"><?= (int) $t['used_by'] ?> jobs</span><?php endif; ?>
          </div>
          <?php if ($t['subject']): ?><span class="mono xs" style="color:var(--text-2);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($t['subject']) ?></span><?php endif; ?>
          <span class="tpl-preview"><?= e(preg_replace('/\s+/u', ' ', (string) $t['body'])) ?></span>
        </div>
        <div class="row-actions">
          <form method="post" action="<?= e(url('templates/' . $t['id'] . '/test')) ?>" class="inline" data-loading><?= csrf_field() ?><button class="btn btn-sm" type="submit" title="Δοκιμαστική αποστολή σε εσένα, με δείγμα δεδομένων"><?= icon('send', 14) ?>Δοκιμή</button></form>
          <a class="btn btn-sm btn-icon" href="<?= e(url('templates/' . $t['id'] . '/edit')) ?>" title="Επεξεργασία" aria-label="<?= e('Επεξεργασία: ' . $t['name']) ?>"><?= icon('edit', 14) ?></a>
          <details class="notif-details">
            <summary class="btn btn-sm btn-icon" title="Περισσότερα" aria-label="Περισσότερες ενέργειες">⋯</summary>
            <div class="notif-panel stack-sm" style="width:240px">
              <?php if (!$t['is_default']): ?>
                <form method="post" action="<?= e(url('templates/' . $t['id'] . '/default')) ?>"><?= csrf_field() ?><button class="btn btn-sm" type="submit" style="width:100%;justify-content:flex-start"><?= icon('check', 14) ?>Ορισμός ως προεπιλογή</button></form>
              <?php endif; ?>
              <form method="post" action="<?= e(url('templates/' . $t['id'] . '/duplicate')) ?>"><?= csrf_field() ?><button class="btn btn-sm" type="submit" style="width:100%;justify-content:flex-start"><?= icon('copy', 14) ?>Αντίγραφο</button></form>
              <form method="post" action="<?= e(url('templates/' . $t['id'] . '/reset')) ?>" data-confirm="Να επανέλθει το αρχικό κείμενο; Οι αλλαγές σου σε αυτό το template θα χαθούν."><?= csrf_field() ?><button class="btn btn-sm" type="submit" style="width:100%;justify-content:flex-start"><?= icon('refresh', 14) ?>Επαναφορά αρχικού κειμένου</button></form>
              <form method="post" action="<?= e(url('templates/' . $t['id'] . '/delete')) ?>" data-confirm="<?= e('Να διαγραφεί το template «' . $t['name'] . '»;') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-danger" type="submit" style="width:100%;justify-content:flex-start"><?= icon('trash', 14) ?>Διαγραφή</button></form>
            </div>
          </details>
        </div>
      </div>
    <?php endforeach; ?>
  </section>
<?php endforeach; ?>
