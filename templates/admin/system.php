<?php
/** @var array $checks @var array $queue @var array $watch */
?>
<div class="page-head"><div><h1>Κατάσταση συστήματος</h1><p class="sub">Έλεγχοι εγκατάστασης και ουρά εκτελέσεων</p></div><a class="btn" href="<?= e(url('admin/tenants')) ?>">Tenants</a></div>
<section class="card flush">
  <table class="table rtable">
    <tbody>
      <?php foreach ($checks as [$label, $value, $ok]): ?>
        <tr>
          <td class="primary"><strong class="small"><?= e($label) ?></strong></td>
          <td data-label="Τιμή" class="small"><?= e($value) ?></td>
          <td class="num"><?= $ok ? '<span class="badge ok">OK</span>' : '<span class="badge fail">Προσοχή</span>' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<section class="card stack">
  <div class="row between">
    <div class="stack-sm" style="gap:2px"><h2>Επιτήρηση scheduler</h2><span class="small muted">Για να μάθεις αμέσως αν σταματήσει το <code>bin/cron.php</code>.</span></div>
    <?php if ($watch["ok"]): ?><span class="badge ok"><span class="dot"></span>Λειτουργεί · πριν <?= (int) $watch["age"] ?>″</span><?php elseif ($watch["last_tick"]): ?><span class="badge fail"><span class="dot"></span>Σταματημένος <?= e(rel_time($watch["last_tick"])) ?></span><?php else: ?><span class="badge off"><span class="dot"></span>Δεν έχει τρέξει ποτέ</span><?php endif; ?>
  </div>
  <div class="watch-grid">
    <div class="watch-step">
      <span class="ws-n">1</span>
      <div class="stack-sm">
        <strong class="small">Εσωτερική ειδοποίηση</strong>
        <span class="small muted">Αν περάσουν <?= (int) (App\Services\Watchdog::STALE_SECONDS / 60) ?> λεπτά χωρίς εκτέλεση, οι super admins παίρνουν push, email και ειδοποίηση στην εφαρμογή, και ξανά όταν επανέλθει. Ενεργοποιείται όταν κάποιος ανοίξει την εφαρμογή ή όταν ένα monitor καλέσει το <code>/health</code>.</span>
        <?php if ($watch["alert_at"]): ?><span class="xs" style="color:var(--fail-fg)">Στάλθηκε ειδοποίηση διακοπής <?= e(rel_time($watch["alert_at"])) ?>.</span><?php endif; ?>
      </div>
    </div>
    <div class="watch-step">
      <span class="ws-n">2</span>
      <div class="stack-sm" style="min-width:0">
        <strong class="small">Εξωτερικό monitor στο /health</strong>
        <span class="small muted">Βάλε σε UptimeRobot, Better Stack κ.λπ. έναν έλεγχο HTTP σε αυτό το URL. Απαντά <code>200</code> όταν όλα δουλεύουν και <code>503</code> όταν ο scheduler ή η βάση έχουν πρόβλημα, ακόμα κι αν κανείς δεν ανοίγει την εφαρμογή.</span>
        <pre class="code"><?= e(abs_url("health")) ?></pre>
      </div>
    </div>
    <div class="watch-step">
      <span class="ws-n">3</span>
      <form method="post" action="<?= e(url("admin/system/watchdog")) ?>" class="stack-sm" style="min-width:0">
        <?= csrf_field() ?>
        <strong class="small">Dead man’s switch (ping σε κάθε εκτέλεση)</strong>
        <span class="small muted">Ο scheduler καλεί αυτό το URL κάθε λεπτό. Αν σταματήσει να το καλεί, σε ειδοποιεί η εξωτερική υπηρεσία, π.χ. healthchecks.io, Cronitor ή ένα heartbeat job σε άλλο SoftiCron.</span>
        <div class="row" style="flex-wrap:nowrap">
          <input class="input mono" name="ping_url" value="<?= e($watch["ping_url"]) ?>" placeholder="https://hc-ping.com/…" style="flex:1;min-width:0">
          <button class="btn" type="submit">Αποθήκευση</button>
        </div>
        <?php if ($watch["ping_last"] !== ""): [$pt, $pr] = array_pad(explode("|", $watch["ping_last"], 2), 2, ""); ?><span class="xs muted">Τελευταίο ping <?= e(rel_time($pt)) ?>: <?= ctype_digit($pr) ? "HTTP " . e($pr) : "<span style=\"color:var(--fail-fg)\">" . e($pr) . "</span>" ?></span><?php endif; ?>
      </form>
    </div>
  </div>
  <form method="post" action="<?= e(url("admin/system/watchdog/test")) ?>" class="inline" data-loading><?= csrf_field() ?><button class="btn btn-sm" type="submit"><?= icon("send", 14) ?>Δοκιμαστική ειδοποίηση</button></form>
</section>
<div class="kv">
  <div><span class="k">Σε αναμονή</span><span class="v"><?= (int) ($queue['queued'] ?? 0) ?></span></div>
  <div><span class="k">Εκτελούνται</span><span class="v"><?= (int) ($queue['running'] ?? 0) ?></span></div>
  <div><span class="k">Νέα προσπάθεια</span><span class="v"><?= (int) ($queue['retrying'] ?? 0) ?></span></div>
</div>
<section class="card stack-sm small">
  <h2>Scheduled Task στο Plesk</h2>
  <p>Websites &amp; Domains → Scheduled Tasks → Add Task → «Run a command», κάθε λεπτό (<code>* * * * *</code>):</p>
  <pre class="code">/opt/plesk/php/8.3/bin/php <?= e(App\Core\App::$root) ?>/bin/cron.php</pre>
</section>
