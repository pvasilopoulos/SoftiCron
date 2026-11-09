<?php
/** @var array $page @var array $f @var array $stats @var array $members @var bool $canManage @var int $staleDays */
use App\Auth\Auth;
use App\Services\Devices;

$tz = Auth::tenant()['timezone'];
$base = array_filter($f, fn ($v) => $v !== '' && $v !== 0);
$link = fn (array $c) => url('devices', array_filter(array_merge($base, $c), fn ($v) => $v !== '' && $v !== 0));
$statuses = [
    '' => ['Όλες', (int) $stats['total']],
    'active' => ['Ενεργές', (int) $stats['enabled'] - (int) $stats['failing']],
    'failing' => ['Με σφάλματα', (int) $stats['failing']],
    'disabled' => ['Απενεργοποιημένες', (int) $stats['total'] - (int) $stats['enabled']],
    'stale' => ['Αδρανείς ' . $staleDays . '+ ημέρες', (int) $stats['stale']],
    'pwa' => ['Εγκατεστημένες (PWA)', (int) $stats['pwa']],
];
$chips = [];
if ($f['user']) {
    foreach ($members as $m) {
        if ((int) $m['id'] === $f['user']) {
            $chips[] = ['Χρήστης: ' . $m['name'], $link(['user' => 0])];
        }
    }
}
if ($f['provider'] !== '') {
    $chips[] = ['Υπηρεσία: ' . (Devices::PROVIDERS[$f['provider']] ?? $f['provider']), $link(['provider' => ''])];
}
if ($f['q'] !== '') {
    $chips[] = ['«' . $f['q'] . '»', $link(['q' => ''])];
}
$withoutDevices = array_filter($members, fn ($m) => !(int) $m['devices']);
?>
<div class="page-head">
  <div><h1>Συσκευές χρηστών</h1><p class="sub"><?= (int) $stats['total'] ?> συσκευές push από <?= (int) $stats['users'] ?> από τα <?= count($members) ?> μέλη</p></div>
  <?php if (Auth::can('users.view')): ?><a class="btn" href="<?= e(url('users')) ?>"><?= icon('users', 16) ?>Χρήστες</a><?php endif; ?>
</div>

<div class="stat-chips" role="group" aria-label="Κατάσταση συσκευών">
  <?php foreach ($statuses as $k => [$l, $n]): ?>
    <a class="stat-chip<?= $f['status'] === $k ? ' on' : '' ?>" href="<?= e($link(['status' => $k])) ?>"><?= e($l) ?> <span class="n"><?= $n ?></span></a>
  <?php endforeach; ?>
</div>

<form class="card filters" method="get" action="<?= e(url('devices')) ?>">
  <?php if ($f['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($f['status']) ?>"><?php endif; ?>
  <div class="filters-main">
    <label class="searchbar"><?= icon('search', 16) ?><span class="sr-only">Αναζήτηση</span>
      <input name="q" value="<?= e($f['q']) ?>" placeholder="Χρήστης, email, όνομα συσκευής, πλατφόρμα ή IP">
    </label>
    <label class="sr-only" for="dv-user">Χρήστης</label>
    <select id="dv-user" class="select" name="user" style="width:auto;min-width:160px">
      <option value="">Όλοι οι χρήστες</option>
      <?php foreach ($members as $m): ?><option value="<?= (int) $m['id'] ?>" <?= $f['user'] === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?> (<?= (int) $m['devices'] ?>)</option><?php endforeach; ?>
    </select>
    <label class="sr-only" for="dv-prov">Υπηρεσία push</label>
    <select id="dv-prov" class="select" name="provider" style="width:auto">
      <option value="">Όλες οι υπηρεσίες</option>
      <?php foreach (Devices::PROVIDERS as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['provider'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-primary" type="submit">Φιλτράρισμα</button>
  </div>
</form>
<?= App\Core\View::partial('partials/active_filters', ['chips' => $chips, 'clear' => url('devices')]) ?>

<section class="card flush">
  <?php if (!$page['rows']): ?>
    <div class="empty"><?= icon('bell', 40) ?><span><?= $base ? 'Καμία συσκευή με αυτά τα φίλτρα.' : 'Κανένα μέλος δεν έχει ενεργοποιήσει ειδοποιήσεις push ακόμα.' ?></span></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table rtable">
        <thead><tr><th>Χρήστης & συσκευή</th><th>Στοιχεία</th><th>Κατάσταση</th><th class="num"><span class="sr-only">Ενέργειες</span></th></tr></thead>
        <tbody>
          <?php foreach ($page['rows'] as $d):
              $state = !$d['enabled'] ? ['off', 'Απενεργοποιημένη'] : ((int) $d['fail_count'] > 0 ? ['fail', 'Σφάλματα'] : ['ok', 'Ενεργή']); ?>
            <tr>
              <td class="primary">
                <div class="row" style="flex-wrap:nowrap;align-items:flex-start">
                  <span class="avatar"><?= e(initials($d['user_name'])) ?></span>
                  <div class="stack-sm" style="gap:1px;min-width:0">
                    <a class="small" style="font-weight:600;color:var(--text)" href="<?= e($link(['user' => (int) $d['user_id']])) ?>"><?= e($d['user_name']) ?></a>
                    <span class="xs muted"><?= e($d['user_email']) ?></span>
                    <span class="small"><?= e($d['label'] ?: ($d['user_agent'] ?: 'Συσκευή χωρίς όνομα')) ?></span>
                  </div>
                </div>
              </td>
              <td data-label="Στοιχεία"><div class="stack-sm" style="gap:4px"><?= App\Core\View::partial('partials/device_meta', ['d' => $d, 'tz' => $tz]) ?></div></td>
              <td data-label="Κατάσταση"><span class="badge <?= $state[0] ?>"><?= e($state[1]) ?></span></td>
              <td class="num">
                <?php if ($canManage): ?>
                  <div class="row-actions">
                    <form method="post" action="<?= e(url('devices/' . $d['id'] . '/test')) ?>" class="inline" data-loading><?= csrf_field() ?><button class="btn btn-sm" type="submit" title="Στέλνει δοκιμαστική ειδοποίηση στη συσκευή"<?= $d['enabled'] ? '' : ' disabled' ?>><?= icon('send', 14) ?>Δοκιμή</button></form>
                    <form method="post" action="<?= e(url('devices/' . $d['id'] . '/toggle')) ?>" class="inline"><?= csrf_field() ?><button class="btn btn-sm btn-icon" type="submit" title="<?= $d['enabled'] ? 'Απενεργοποίηση' : 'Ενεργοποίηση' ?>" aria-label="<?= e(($d['enabled'] ? 'Απενεργοποίηση: ' : 'Ενεργοποίηση: ') . ($d['label'] ?: $d['user_agent'])) ?>"><?= icon($d['enabled'] ? 'x' : 'check', 14) ?></button></form>
                    <form method="post" action="<?= e(url('devices/' . $d['id'] . '/delete')) ?>" class="inline" data-confirm="<?= e('Να αφαιρεθεί η συσκευή «' . ($d['label'] ?: $d['user_agent']) . '» του/της ' . $d['user_name'] . '; Θα σταματήσει να λαμβάνει ειδοποιήσεις μέχρι να τις ξαναενεργοποιήσει.') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-icon btn-danger" type="submit" title="Αφαίρεση" aria-label="Αφαίρεση συσκευής"><?= icon('trash', 14) ?></button></form>
                  </div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= App\Core\View::partial('partials/pagination', ['page' => $page]) ?>
  <?php endif; ?>
</section>

<?php if ($withoutDevices && !$base): ?>
  <section class="card stack-sm">
    <h2>Μέλη χωρίς συσκευή push</h2>
    <p class="small muted">Δεν θα λάβουν ειδοποιήσεις push μέχρι να τις ενεργοποιήσουν από το μενού Ειδοποιήσεις σε κινητό ή υπολογιστή.</p>
    <div class="chips"><?php foreach ($withoutDevices as $m): ?><span class="tag"><?= e($m['name']) ?></span><?php endforeach; ?></div>
  </section>
<?php endif; ?>
