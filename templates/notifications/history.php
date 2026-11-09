<?php
/** @var array $page @var array $f @var array $stats @var array $jobs */
use App\Auth\Auth;
use App\Services\Notifier;

$tz = Auth::tenant()['timezone'];
$statusMap = ['sent' => ['Στάλθηκε', 'ok'], 'failed' => ['Απέτυχε', 'fail'], 'skipped' => ['Παραλείφθηκε', 'off']];
$channelIcon = ['email' => 'send', 'telegram' => 'send', 'push' => 'bell'];
$filtered = array_filter($f, fn ($v) => $v !== '' && $v !== 0);
?>
<div class="page-head" data-autorefresh="60">
  <div><h1>Ειδοποιήσεις</h1><p class="sub">Αναλυτικό ιστορικό κάθε email, Telegram και push</p></div>
</div>
<nav class="tabs" aria-label="Ειδοποιήσεις">
  <a href="<?= e(url('notifications')) ?>">Συσκευές & προτιμήσεις</a>
  <a class="on" href="<?= e(url('notifications/history')) ?>" aria-current="page">Ιστορικό</a>
</nav>

<div class="kpis">
  <?php foreach (Notifier::CHANNELS as $ch => $label): $s = $stats[$ch] ?? ['sent' => 0, 'failed' => 0, 'skipped' => 0]; ?>
    <a class="card kpi" href="<?= e(url('notifications/history', ['channel' => $ch])) ?>" style="text-decoration:none;color:inherit">
      <span class="label"><?= e($label) ?> · 7 ημέρες</span>
      <span class="value"><?= (int) $s['sent'] ?></span>
      <span class="delta"><?= (int) $s['failed'] ? '<span style="color:var(--fail-fg)">' . (int) $s['failed'] . ' αποτυχίες</span>' : 'Καμία αποτυχία' ?><?= (int) $s['skipped'] ? ' · ' . (int) $s['skipped'] . ' παραλείψεις' : '' ?></span>
    </a>
  <?php endforeach; ?>
  <a class="card kpi" href="<?= e(url('notifications/history', ['status' => 'failed'])) ?>" style="text-decoration:none;color:inherit">
    <span class="label">Αποτυχίες · 7 ημέρες</span>
    <span class="value" style="color:<?= array_sum(array_column($stats, 'failed')) ? 'var(--fail-fg)' : 'inherit' ?>"><?= (int) array_sum(array_column($stats, 'failed')) ?></span>
    <span class="delta">Πάτα για να τις δεις</span>
  </a>
</div>

<form method="get" action="<?= e(url('notifications/history')) ?>" class="card stack-sm">
  <div class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">
    <label class="field">Κανάλι
      <select class="select" name="channel"><option value="">Όλα</option><?php foreach (Notifier::CHANNELS as $k => $l): ?><option value="<?= $k ?>" <?= $f['channel'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    </label>
    <label class="field">Κατάσταση
      <select class="select" name="status"><option value="">Όλες</option><?php foreach ($statusMap as $k => [$l]): ?><option value="<?= $k ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    </label>
    <label class="field">Γεγονός
      <select class="select" name="event"><option value="">Όλα</option><?php foreach (Notifier::EVENTS as $k => $l): ?><option value="<?= $k ?>" <?= $f['event'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    </label>
    <label class="field">Job
      <select class="select" name="job"><option value="">Όλα</option><?php foreach ($jobs as $j): ?><option value="<?= (int) $j['id'] ?>" <?= $f['job'] === (int) $j['id'] ? 'selected' : '' ?>><?= e($j['name']) ?></option><?php endforeach; ?></select>
    </label>
    <label class="field">Από<input class="input" type="date" name="from" value="<?= e($f['from']) ?>"></label>
    <label class="field">Έως<input class="input" type="date" name="to" value="<?= e($f['to']) ?>"></label>
    <label class="field">Αναζήτηση<input class="input" name="q" value="<?= e($f['q']) ?>" placeholder="Παραλήπτης, θέμα, σφάλμα"></label>
  </div>
  <?php if ($f['run']): ?><input type="hidden" name="run" value="<?= (int) $f['run'] ?>"><?php endif; ?>
  <div class="form-actions">
    <?php if ($filtered): ?><a class="btn" href="<?= e(url('notifications/history')) ?>">Καθαρισμός φίλτρων</a><?php endif; ?>
    <button class="btn btn-primary" type="submit"><?= icon('search', 16) ?>Φίλτρο</button>
  </div>
</form>

<section class="card flush">
  <?php if (!$page['rows']): ?>
    <div class="empty"><?= icon('bell', 40) ?><span><?= $filtered ? 'Καμία ειδοποίηση με αυτά τα φίλτρα.' : 'Δεν έχουν σταλεί ειδοποιήσεις ακόμα.' ?></span></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table rtable">
        <thead><tr><th>Ώρα</th><th>Job · γεγονός</th><th>Κανάλι</th><th>Παραλήπτης</th><th>Κατάσταση</th><th class="num">Λεπτομέρειες</th></tr></thead>
        <tbody>
          <?php foreach ($page['rows'] as $n): [$sl, $sc] = $statusMap[$n['status']] ?? [$n['status'], 'off']; ?>
            <tr>
              <td data-label="Ώρα" class="small nowrap"><?= e(fmt_dt($n['created_at'], $tz, 'd/m/Y H:i:s')) ?></td>
              <td class="primary">
                <div class="stack-sm" style="gap:2px">
                  <?php if ($n['job_id'] && $n['job_name']): ?><a class="strong small" href="<?= e(url('jobs/' . $n['job_id'] . '/edit')) ?>"><?= e($n['job_name']) ?></a><?php else: ?><span class="small muted">Διαγραμμένο job</span><?php endif; ?>
                  <span class="xs muted"><?= e(Notifier::eventLabel($n['event'])) ?><?php if ($n['run_id']): ?> · <a href="<?= e(url('runs/' . $n['run_id'])) ?>">εκτέλεση #<?= (int) $n['run_id'] ?></a><?php endif; ?></span>
                </div>
              </td>
              <td data-label="Κανάλι"><span class="tag" style="display:inline-flex;align-items:center;gap:6px"><?= icon($channelIcon[$n['channel']] ?? 'bell', 12) ?><?= e(Notifier::CHANNELS[$n['channel']] ?? $n['channel']) ?></span></td>
              <td data-label="Παραλήπτης" class="small" style="max-width:280px;word-break:break-word"><?= e($n['target']) ?></td>
              <td data-label="Κατάσταση"><span class="badge <?= $sc ?>"><span class="dot"></span><?= e($sl) ?></span></td>
              <td class="num">
                <?php if ($n['subject'] || $n['body'] || $n['error']): ?>
                  <details class="notif-details">
                    <summary class="btn btn-sm">Προβολή</summary>
                    <div class="stack-sm notif-panel">
                      <?php if ($n['error']): ?><p class="flash <?= $n['status'] === 'skipped' ? 'info' : 'error' ?>" style="margin:0"><?= e($n['error']) ?></p><?php endif; ?>
                      <?php if ($n['subject']): ?><div><span class="xs muted">Θέμα / τίτλος</span><div class="small" style="font-weight:600"><?= e($n['subject']) ?></div></div><?php endif; ?>
                      <?php if ($n['body']): ?><div><span class="xs muted">Μήνυμα</span><pre class="code" style="max-height:260px"><?= e($n['body']) ?></pre></div><?php endif; ?>
                    </div>
                  </details>
                <?php else: ?><span class="muted xs">—</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= App\Core\View::partial('partials/pagination', ['page' => $page]) ?>
  <?php endif; ?>
</section>
