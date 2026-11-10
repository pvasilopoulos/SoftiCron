<?php
/** @var array $job @var ?array $tenant @var string $token @var ?array $lastRun @var int $fails @var ?array $user @var bool $canOpen */
$tz = $tenant['timezone'] ?? 'Europe/Athens';
$failing = (bool) $job['failing_since'];
$acked = $failing && $job['acked_at'];
?>
<div class="card stack ack-card">
  <div class="stack-sm" style="gap:6px">
    <span class="xs muted"><?= e($tenant['name'] ?? '') ?></span>
    <h1 style="font-size:21px;line-height:1.3"><?= e($job['name']) ?></h1>
  </div>

  <?php if (!$failing): ?>
    <div class="hero ok">
      <div class="icon"><?= icon('check', 24) ?></div>
      <div class="stack-sm" style="gap:2px"><span class="big">Το συμβάν έχει λήξει</span><span class="small">Το job λειτουργεί ξανά κανονικά, δεν χρειάζεται κάτι άλλο.</span></div>
    </div>
  <?php elseif ($acked): ?>
    <div class="hero other">
      <div class="icon" style="background:var(--accent)"><span style="font-size:22px">✋</span></div>
      <div class="stack-sm" style="gap:2px">
        <span class="big"><?= e($job['acked_name']) ?> το ανέλαβε</span>
        <span class="small"><?= e(rel_time($job['acked_at'])) ?><?= $job['ack_note'] ? ' · «' . e($job['ack_note']) . '»' : '' ?></span>
      </div>
    </div>
    <p class="small muted">Οι επαναλαμβανόμενες ειδοποιήσεις και η κλιμάκωση έχουν σταματήσει. Θα έρθει ειδοποίηση μόλις επανέλθει.</p>
  <?php else: ?>
    <div class="hero fail">
      <div class="icon"><?= icon('x', 24) ?></div>
      <div class="stack-sm" style="gap:2px">
        <span class="big">Αποτυγχάνει <?= e(rel_time($job['failing_since'])) ?></span>
        <span class="small"><?= $fails ?> αποτυχημένες εκτελέσεις<?= $lastRun && $lastRun['http_status'] ? ' · HTTP ' . (int) $lastRun['http_status'] : '' ?></span>
      </div>
    </div>
    <?php if ($lastRun && $lastRun['error']): ?><p class="small" style="color:var(--fail-fg);word-break:break-word"><?= e($lastRun['error']) ?></p><?php endif; ?>

    <form method="post" action="<?= e(url('ack/' . $job['id'] . '/' . $token)) ?>" class="stack" data-once data-loading>
      <?= csrf_field() ?>
      <?php if ($user): ?>
        <p class="small">Θα καταγραφεί ότι το ανέλαβες ως <strong><?= e($user['name']) ?></strong>.</p>
      <?php else: ?>
        <label class="field">Το όνομά σου
          <input class="input" name="name" value="<?= e(old('name')) ?>" maxlength="120" required autocomplete="name" placeholder="π.χ. Γιώργος">
        </label>
      <?php endif; ?>
      <label class="field">Σημείωση <span class="help">Προαιρετική, π.χ. «το κοιτάω, ο server κάνει restart»</span>
        <input class="input" name="note" value="<?= e(old('note')) ?>" maxlength="500">
      </label>
      <button class="btn btn-primary" type="submit" style="min-height:48px;font-size:15px">✋ Το είδα, το αναλαμβάνω</button>
      <p class="xs muted">Σταματούν οι επαναλαμβανόμενες ειδοποιήσεις αποτυχίας και η κλιμάκωση, και ενημερώνονται τα κανάλια chat της ομάδας.</p>
    </form>
  <?php endif; ?>

  <?php if ($canOpen): ?>
    <div class="row">
      <a class="btn" href="<?= e(url('jobs/' . $job['id'] . '/edit')) ?>">Άνοιγμα job</a>
      <?php if ($lastRun): ?><a class="btn" href="<?= e(url('runs/' . $lastRun['id'])) ?>">Τελευταία αποτυχία</a><?php endif; ?>
    </div>
  <?php endif; ?>
</div>
