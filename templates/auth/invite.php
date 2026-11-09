<?php /** @var array $inv @var string $token @var ?array $existing */ ?>
<div class="card">
  <form method="post" action="<?= e(url('invite/' . $token)) ?>" class="stack">
    <?= csrf_field() ?>
    <div>
      <h1 style="font-size:22px">Πρόσκληση στον <?= e($inv['tenant_name']) ?></h1>
      <p class="muted small" style="margin-top:4px">Ρόλος: <strong><?= e($inv['role_name']) ?></strong> · <?= e($inv['email']) ?></p>
    </div>
    <?php if ($existing): ?>
      <p class="small">Έχεις ήδη λογαριασμό. Βάλε τον κωδικό σου για να αποδεχτείς την πρόσκληση.</p>
      <label class="field">Κωδικός
        <input class="input" type="password" name="password" autocomplete="current-password" required>
      </label>
    <?php else: ?>
      <label class="field">Ονοματεπώνυμο
        <input class="input" name="name" value="<?= e(old('name')) ?>" autocomplete="name" required>
      </label>
      <label class="field">Κωδικός <span class="help">Τουλάχιστον 10 χαρακτήρες</span>
        <input class="input" type="password" name="password" minlength="10" autocomplete="new-password" required>
      </label>
      <label class="field">Επιβεβαίωση κωδικού
        <input class="input" type="password" name="password_confirm" minlength="10" autocomplete="new-password" required>
      </label>
    <?php endif; ?>
    <button class="btn btn-primary" type="submit">Αποδοχή πρόσκλησης</button>
  </form>
</div>
