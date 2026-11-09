<?php
/** @var array $u @var array $roles @var bool $managed */
use App\Auth\Auth;

$isNew = $u['id'] === null;
$v = fn (string $k) => old($k, $u[$k] ?? '');
$isSelf = !$isNew && (int) $u['id'] === Auth::id();
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="<?= e(url('users')) ?>">Χρήστες</a><span aria-hidden="true">/</span><span><?= $isNew ? 'Νέος' : e($u['name']) ?></span></div>
    <h1><?= $isNew ? 'Νέος χρήστης' : e($u['name']) ?></h1>
  </div>
</div>
<div class="split">
  <form method="post" action="<?= e($isNew ? url('users') : url('users/' . $u['id'])) ?>" class="card stack" autocomplete="off">
    <?= csrf_field() ?>
    <?php if (!$managed): ?>
      <p class="notice">Ο λογαριασμός ανήκει και σε άλλους οργανισμούς. Μπορείς να αλλάξεις μόνο τον ρόλο του εδώ. Τα υπόλοιπα στοιχεία τα αλλάζει ο ίδιος από το προφίλ του.</p>
    <?php endif; ?>
    <div class="form-grid">
      <label class="field">Ονοματεπώνυμο
        <input class="input" name="name" value="<?= e($v('name')) ?>" required maxlength="150" <?= $managed ? '' : 'disabled' ?>>
      </label>
      <label class="field">Email
        <input class="input" type="email" name="email" value="<?= e($v('email')) ?>" required <?= $managed ? '' : 'disabled' ?>>
      </label>
      <label class="field">Ρόλος
        <select class="select" name="role_id">
          <?php foreach ($roles as $r): ?><option value="<?= (int) $r['id'] ?>" <?= (int) $v('role_id') === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <?php if (!$isNew && $managed): ?>
        <label class="field">Κατάσταση
          <select class="select" name="status" <?= $isSelf ? 'disabled' : '' ?>>
            <option value="active" <?= $v('status') === 'active' ? 'selected' : '' ?>>Ενεργός</option>
            <option value="disabled" <?= $v('status') === 'disabled' ? 'selected' : '' ?>>Απενεργοποιημένος (δεν μπορεί να συνδεθεί)</option>
          </select>
          <?php if ($isSelf): ?><input type="hidden" name="status" value="active"><?php endif; ?>
        </label>
      <?php endif; ?>
    </div>
    <?php if ($managed): ?>
      <label class="field"><?= $isNew ? 'Κωδικός' : 'Νέος κωδικός' ?>
        <span class="help"><?= $isNew ? 'Άφησέ το κενό για να δημιουργηθεί τυχαίος κωδικός (θα σου εμφανιστεί μία φορά). Τουλάχιστον 10 χαρακτήρες.' : 'Άφησέ το κενό για να μην αλλάξει.' ?></span>
        <input class="input" type="password" name="password" minlength="10" autocomplete="new-password">
      </label>
    <?php endif; ?>
    <div class="form-actions">
      <?php if (!$isNew && !$isSelf && \App\Auth\Auth::can('users.delete')): ?><button class="btn btn-danger" type="submit" form="user-del"><?= icon('trash', 16) ?>Διαγραφή</button><span class="grow"></span><?php endif; ?>
      <a class="btn" href="<?= e(url('users')) ?>">Ακύρωση</a>
      <button class="btn btn-primary" type="submit">Αποθήκευση</button>
    </div>
  </form>
  <aside class="card stack-sm small">
    <h2>Δημιουργία ή πρόσκληση;</h2>
    <p>Με τη <strong>δημιουργία</strong> ορίζεις εσύ τον κωδικό και τον δίνεις στον χρήστη.</p>
    <p>Με την <strong>πρόσκληση</strong> ο χρήστης λαμβάνει σύνδεσμο και ορίζει μόνος του τον κωδικό του. Είναι ο πιο ασφαλής τρόπος.</p>
    <?php if (!$isNew && $u['last_login_at']): ?><p class="muted">Τελευταία σύνδεση <?= e(rel_time($u['last_login_at'])) ?>.</p><?php endif; ?>
  </aside>
</div>
<?php if (!$isNew && !$isSelf): ?>
  <form id="user-del" method="post" action="<?= e(url('users/' . $u['id'] . '/remove')) ?>" class="hidden" data-confirm="Να διαγραφεί ο/η <?= e($u['name']) ?>; Αν ανήκει και σε άλλους οργανισμούς, θα αφαιρεθεί μόνο από αυτόν."><?= csrf_field() ?></form>
<?php endif; ?>
