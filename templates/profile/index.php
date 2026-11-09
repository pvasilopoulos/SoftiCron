<?php
/** @var array $u @var array $memberships */
?>
<div class="page-head"><div><h1>Το προφίλ μου</h1><p class="sub"><?= e($u['email']) ?></p></div></div>
<div class="grid cols-2">
  <form method="post" action="<?= e(url('profile')) ?>" class="card stack">
    <?= csrf_field() ?>
    <h2>Στοιχεία</h2>
    <label class="field">Ονοματεπώνυμο<input class="input" name="name" value="<?= e($u['name']) ?>" required maxlength="150"></label>
    <label class="field">Θέμα εμφάνισης
      <select class="select" name="theme">
        <?php foreach (['system' => 'Όπως το σύστημα', 'light' => 'Φωτεινό', 'dark' => 'Σκοτεινό'] as $k => $l): ?><option value="<?= $k ?>" <?= $u['theme'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
      </select>
    </label>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Αποθήκευση</button></div>
  </form>
  <form method="post" action="<?= e(url('profile/password')) ?>" class="card stack">
    <?= csrf_field() ?>
    <h2>Αλλαγή κωδικού</h2>
    <label class="field">Τρέχων κωδικός<input class="input" type="password" name="current_password" required autocomplete="current-password"></label>
    <label class="field">Νέος κωδικός <span class="help">Τουλάχιστον 10 χαρακτήρες</span><input class="input" type="password" name="new_password" minlength="10" required autocomplete="new-password"></label>
    <label class="field">Επιβεβαίωση<input class="input" type="password" name="new_password_confirm" minlength="10" required autocomplete="new-password"></label>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Αλλαγή κωδικού</button></div>
  </form>
</div>
<?php if ($memberships): ?>
  <section class="card">
    <h2 style="margin-bottom:6px">Οι οργανισμοί μου</h2>
    <?php foreach ($memberships as $m): ?>
      <div class="list-item"><span class="logo" style="width:32px;height:32px;font-size:12px;background:<?= e($m['brand_color']) ?>"><?= e(initials($m['name'])) ?></span><span class="grow small"><strong><?= e($m['name']) ?></strong> · <?= e($m['role']) ?></span></div>
    <?php endforeach; ?>
  </section>
<?php endif; ?>
