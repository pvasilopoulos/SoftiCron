<?php
/** @var array $run @var array $attempts @var array $notifications @var ?string $triggeredBy @var string $body @var bool $canRun @var bool $live */
use App\Services\Notifier;

$tz = $run['job_tz'];
[$label, $cls] = RUN_STATUS[$run['status']] ?? [$run['status'], 'off'];
if ($run['status'] === 'success' && $run['is_slow']) {
    $label = 'Επιτυχία (αργή)';
}
$heroCls = $run['status'] === 'success' ? 'ok' : ($run['status'] === 'failed' ? 'fail' : 'other');
$heroIcon = $run['status'] === 'success' ? 'check' : ($run['status'] === 'failed' ? 'x' : 'jobs');
$triggers = ['schedule' => 'Προγραμματισμένη', 'manual' => 'Χειροκίνητη' . ($triggeredBy ? ' · ' . $triggeredBy : ''), 'missed' => 'Χαμένη εκτέλεση'];
?>
<div class="page-head"<?= $live ? ' data-autorefresh="3"' : '' ?>>
  <div>
    <div class="breadcrumb"><a href="<?= e(url('runs')) ?>">Εκτελέσεις</a><span aria-hidden="true">/</span><span>#<?= (int) $run['id'] ?></span></div>
    <h1>Εκτέλεση #<?= (int) $run['id'] ?></h1>
    <p class="sub"><a href="<?= e(url('jobs/' . $run['job_id'] . '/edit')) ?>"><?= e($run['job_name']) ?></a></p>
  </div>
  <div class="row">
    <a class="btn" href="<?= e(url('jobs/' . $run['job_id'] . '/edit')) ?>">Άνοιγμα job</a>
    <?php if ($canRun && !$live): ?>
      <form method="post" action="<?= e(url('runs/' . $run['id'] . '/retry')) ?>" class="inline" data-loading>
        <?= csrf_field() ?><button class="btn btn-primary" type="submit"><?= icon('refresh', 16) ?>Επανάληψη τώρα</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="hero <?= $heroCls ?>">
  <span class="icon"><?= icon($heroIcon, 22) ?></span>
  <div class="stack-sm" style="gap:2px">
    <span class="big"><?= e($label) ?></span>
    <span class="small"><?= e(fmt_dt($run['started_at'] ?? $run['scheduled_at'], $tz, 'd/m/Y H:i:s')) ?><?= $run['finished_at'] ? ' → ' . e(fmt_dt($run['finished_at'], $tz, 'H:i:s')) : '' ?></span>
    <?php if ($run['error']): ?><span class="small"><?= e($run['error']) ?></span><?php endif; ?>
  </div>
</div>

<div class="kv">
  <div><span class="k">HTTP status</span><span class="v mono"><?= $run['http_status'] ? (int) $run['http_status'] : '—' ?></span></div>
  <div><span class="k">Διάρκεια (τελευταία)</span><span class="v"><?= e(fmt_ms($run['duration_ms'] !== null ? (int) $run['duration_ms'] : null)) ?></span></div>
  <div><span class="k">Προσπάθειες</span><span class="v"><?= (int) $run['attempts'] ?> / <?= 1 + (int) $run['max_retries'] ?></span></div>
  <div><span class="k">Αφορμή</span><span class="v small"><?= e($triggers[$run['trigger_type']] ?? $run['trigger_type']) ?></span></div>
  <div><span class="k">Προγραμματισμένη για</span><span class="v small"><?= e(fmt_dt($run['scheduled_at'], $tz, 'd/m/Y H:i')) ?></span></div>
  <div><span class="k">Μέγεθος response</span><span class="v small"><?= e(fmt_bytes($run['response_size'] !== null ? (int) $run['response_size'] : null)) ?></span></div>
</div>

<div class="split">
  <div class="stack" style="min-width:0">
    <section class="card stack-sm">
      <h2>Request</h2>
      <p class="mono small" style="word-break:break-all"><span class="pill"><?= e($run['request_method'] ?? '') ?></span> <?= e($run['request_url'] ?? '') ?></p>
    </section>
    <section class="card stack-sm">
      <div class="row between"><h2>Response</h2><?php if ($body !== ''): ?><button class="btn btn-sm" type="button" data-copy="<?= e($body) ?>"><?= icon('copy', 14) ?>Αντιγραφή</button><?php endif; ?></div>
      <?php if ($run['response_headers']): ?>
        <details><summary class="small">Headers</summary><pre class="code" style="margin-top:8px"><?= e($run['response_headers']) ?></pre></details>
      <?php endif; ?>
      <?php if ($body !== ''): ?>
        <pre class="code"><?= e($body) ?></pre>
        <?php if ((int) $run['response_size'] > strlen((string) $run['response_body'])): ?><p class="xs muted">Εμφανίζονται τα πρώτα 64 KB.</p><?php endif; ?>
      <?php else: ?>
        <p class="small muted"><?= $live ? 'Αναμονή αποτελέσματος…' : 'Κενό response.' ?></p>
      <?php endif; ?>
    </section>
  </div>
  <aside class="stack" style="min-width:0">
    <section class="card stack-sm">
      <h2>Προσπάθειες</h2>
      <?php if (!$attempts): ?><p class="small muted"><?= $live ? 'Σε εξέλιξη…' : 'Καμία προσπάθεια.' ?></p><?php endif; ?>
      <div class="timeline">
        <?php foreach ($attempts as $a): $ok = $a['error'] === null; ?>
          <div class="step">
            <span class="n" style="background:var(--<?= $ok ? 'ok' : 'fail' ?>-bg);color:var(--<?= $ok ? 'ok' : 'fail' ?>-fg)"><?= (int) $a['attempt_no'] ?></span>
            <div class="grow stack-sm" style="gap:1px">
              <div class="row between small"><strong><?= e(fmt_dt($a['started_at'], $tz, 'H:i:s')) ?></strong><span class="mono"><?= $a['http_status'] ? (int) $a['http_status'] : '—' ?></span></div>
              <span class="xs muted"><?= e(fmt_ms($a['duration_ms'] !== null ? (int) $a['duration_ms'] : null)) ?><?= $a['error'] ? ' · ' . e($a['error']) : '' ?></span>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if ($run['status'] === 'retrying' && $run['next_attempt_at']): ?>
          <p class="small muted">Επόμενη προσπάθεια <?= e(rel_time($run['next_attempt_at'])) ?>.</p>
        <?php endif; ?>
      </div>
    </section>
    <section class="card stack-sm">
      <h2>Ειδοποιήσεις που στάλθηκαν</h2>
      <?php if (!$notifications): ?><p class="small muted">Καμία.</p><?php endif; ?>
      <?php foreach ($notifications as $n): ?>
        <div class="list-item small">
          <span class="badge <?= $n['status'] === 'sent' ? 'ok' : 'fail' ?>"><?= $n['status'] === 'sent' ? '✓' : '✗' ?> <?= e(Notifier::CHANNELS[$n['channel']] ?? $n['channel']) ?></span>
          <span class="grow"><?= e(Notifier::EVENTS[$n['event']] ?? $n['event']) ?> · <?= e($n['target']) ?><?php if ($n['error']): ?><br><span class="xs" style="color:var(--fail-fg)"><?= e($n['error']) ?></span><?php endif; ?></span>
        </div>
      <?php endforeach; ?>
    </section>
  </aside>
</div>
