<?php
/** @var array $checks @var array $queue */
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
