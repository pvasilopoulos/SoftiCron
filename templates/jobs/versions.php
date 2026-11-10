<?php
/** @var array $job @var array $rows @var bool $canRestore */
use App\Auth\Auth;

$tz = Auth::tenant()['timezone'];
$fieldLabels = [
    'name' => 'Όνομα', 'group' => 'Ομάδα', 'description' => 'Περιγραφή', 'enabled' => 'Ενεργό', 'method' => 'Μέθοδος', 'url' => 'URL',
    'headers' => 'Headers', 'auth' => 'Αυθεντικοποίηση', 'body_type' => 'Τύπος body', 'body' => 'Body', 'cron' => 'Πρόγραμμα (cron)',
    'timezone' => 'Ζώνη ώρας', 'timeout_sec' => 'Timeout', 'max_retries' => 'Επαναλήψεις', 'retry_strategy' => 'Στρατηγική retry',
    'retry_delay_sec' => 'Καθυστέρηση retry', 'overlap_policy' => 'Επικάλυψη', 'success_codes' => 'HTTP επιτυχίας',
    'success_contains' => 'Η απάντηση περιέχει', 'assertions' => 'Έλεγχοι απάντησης', 'slow_threshold_ms' => 'Όριο αργής απόκρισης', 'active_from' => 'Ενεργό από',
    'active_until' => 'Ενεργό έως', 'notify' => 'Ειδοποιήσεις', 'notify_emails' => 'Emails', 'notify_phones' => 'Κινητά',
    'notify_telegram_chat' => 'Telegram chat', 'grace_minutes' => 'Περιθώριο heartbeat', 'notify_after_failures' => 'Ειδοποίηση μετά από',
    'escalate_after_minutes' => 'Κλιμάκωση μετά από', 'escalate_channels' => 'Κανάλια κλιμάκωσης', 'sla_target' => 'Στόχος SLA',
    'show_on_status' => 'Στη σελίδα κατάστασης', 'status_name' => 'Όνομα στη σελίδα κατάστασης', 'push_target' => 'Παραλήπτες push', 'type' => 'Τύπος',
];
$short = fn (string $v) => mb_strlen($v) > 140 ? mb_substr($v, 0, 140) . '…' : $v;
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="<?= e(url('jobs')) ?>">Jobs</a><span aria-hidden="true">/</span><a href="<?= e(url('jobs/' . $job['id'] . '/edit')) ?>"><?= e($job['name']) ?></a><span aria-hidden="true">/</span><span>Εκδόσεις</span></div>
    <h1>Ιστορικό εκδόσεων</h1>
    <p class="sub">Κάθε αποθήκευση κρατά μια έκδοση (έως τις τελευταίες <?= App\Services\JobVersions::KEEP ?>). Η επαναφορά κρατά το ping URL και το ιστορικό εκτελέσεων.</p>
  </div>
  <?php if (Auth::can('jobs.export')): ?><a class="btn" href="<?= e(url('jobs/export', ['ids' => $job['id']])) ?>"><?= icon('download', 16) ?>Εξαγωγή JSON</a><?php endif; ?>
</div>

<nav class="tabs" aria-label="Ενότητες job">
  <a href="<?= e(url('jobs/' . $job['id'] . '/edit')) ?>">Ρυθμίσεις</a>
  <a href="<?= e(url('runs', ['job' => $job['id']])) ?>">Ιστορικό εκτελέσεων</a>
  <a class="on" href="<?= e(url('jobs/' . $job['id'] . '/versions')) ?>" aria-current="page">Εκδόσεις</a>
  <?php if (Auth::can('audit.view')): ?><a href="<?= e(url('audit', ['q' => $job['id']])) ?>">Αλλαγές (audit)</a><?php endif; ?>
</nav>

<div class="stack">
  <?php foreach ($rows as $i => $v): $isCurrent = $i === 0 && !$v['vs_current']; ?>
    <section class="card stack-sm">
      <div class="row" style="gap:10px">
        <span class="badge <?= $isCurrent ? 'ok' : 'off' ?>" style="font-family:var(--mono)">v<?= (int) $v['version_no'] ?></span>
        <div class="stack-sm grow" style="gap:0;min-width:0">
          <strong class="small"><?= e($v['comment'] ?: 'Αλλαγή ρυθμίσεων') ?><?= $isCurrent ? ' · τρέχουσα' : '' ?></strong>
          <span class="xs muted"><?= e(fmt_dt($v['created_at'], $tz, 'd/m/Y H:i:s')) ?> · <?= e($v['user_name'] ?? 'σύστημα / API') ?> · <?= e(rel_time($v['created_at'])) ?></span>
        </div>
        <?php if ($canRestore && !$isCurrent && $v['vs_current']): ?>
          <form method="post" action="<?= e(url('jobs/' . $job['id'] . '/versions/' . $v['id'] . '/restore')) ?>" class="inline" data-confirm="<?= e('Να επανέλθει το job στην έκδοση v' . $v['version_no'] . '; Η τρέχουσα κατάσταση θα κρατηθεί ως νέα έκδοση, ώστε να μπορείς να γυρίσεις πίσω.') ?>">
            <?= csrf_field() ?><button class="btn btn-sm" type="submit"><?= icon('refresh', 14) ?>Επαναφορά</button>
          </form>
        <?php endif; ?>
      </div>
      <?php if ($v['changes'] === null): ?>
        <p class="xs muted">Πρώτη καταγεγραμμένη έκδοση.</p>
      <?php elseif (!$v['changes']): ?>
        <p class="xs muted">Χωρίς αλλαγές στις ρυθμίσεις σε σχέση με την προηγούμενη έκδοση (π.χ. αλλαγή secret ή παραληπτών push).</p>
      <?php else: ?>
        <details <?= $i < 3 ? 'open' : '' ?>>
          <summary class="small" style="cursor:pointer;font-weight:500"><?= count($v['changes']) ?> αλλαγές σε σχέση με v<?= (int) $v['version_no'] - 1 ?></summary>
          <div class="table-wrap" style="margin-top:8px">
            <table class="table rtable">
              <thead><tr><th>Πεδίο</th><th>Πριν</th><th>Μετά</th></tr></thead>
              <tbody>
                <?php foreach ($v['changes'] as $field => [$old, $new]): ?>
                  <tr>
                    <td class="primary small" style="font-weight:500"><?= e($fieldLabels[$field] ?? $field) ?></td>
                    <td data-label="Πριν" class="mono xs" style="color:var(--fail-fg);word-break:break-all;max-width:320px"><?= e($short($old)) ?></td>
                    <td data-label="Μετά" class="mono xs" style="color:var(--ok-fg);word-break:break-all;max-width:320px"><?= e($short($new)) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </details>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
</div>
