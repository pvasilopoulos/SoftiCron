<div class="card">
  <form method="post" action="<?= e(url('login')) ?>" class="stack" data-once data-loading>
    <?= csrf_field() ?>
    <div>
      <h1 style="font-size:22px">Σύνδεση</h1>
      <p class="muted small" style="margin-top:4px">Μπες με το email και τον κωδικό σου.</p>
    </div>
    <label class="field">Email
      <input class="input" type="email" name="email" value="<?= e(old('email')) ?>" autocomplete="username" required autofocus>
    </label>
    <label class="field">Κωδικός
      <input class="input" type="password" name="password" autocomplete="current-password" required>
    </label>
    <button class="btn btn-primary" type="submit">Σύνδεση</button>
  </form>
</div>
<p class="muted small" style="text-align:center">Δεν έχεις λογαριασμό; Ζήτησε πρόσκληση από τον διαχειριστή του οργανισμού σου.</p>
