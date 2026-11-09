<?php
/** @var array $members @var array $invites @var array $roles @var ?string $inviteLink */
use App\Auth\Auth;

$tz = Auth::tenant()['timezone'];
?>
<div class="page-head">
  <div><h1>Χρήστες</h1><p class="sub"><?= count($members) ?> μέλη στον <?= e(Auth::tenant()['name']) ?></p></div>
  <div class="row">
    <?php if (Auth::can('roles.manage')): ?><a class="btn" href="<?= e(url('roles')) ?>"><?= icon('roles', 16) ?>Ρόλοι & δικαιώματα</a><?php endif; ?>
    <a class="btn btn-primary" href="<?= e(url('users/new')) ?>"><?= icon('plus', 16) ?>Νέος χρήστης</a>
  </div>
</div>

<?php if (!empty($newPassword)): ?>
  <div class="flash info" style="flex-wrap:wrap">
    <span>Κωδικός για <strong><?= e($newPassword['email']) ?></strong> (εμφανίζεται μόνο τώρα):</span>
    <code><?= e($newPassword['password']) ?></code>
    <button class="btn btn-sm" type="button" data-copy="<?= e($newPassword['password']) ?>"><?= icon('copy', 14) ?>Αντιγραφή</button>
  </div>
<?php endif; ?>

<?php if ($inviteLink): ?>
  <div class="flash info" style="flex-wrap:wrap">
    <span>Σύνδεσμος πρόσκλησης (ισχύει 7 ημέρες):</span>
    <code style="word-break:break-all"><?= e($inviteLink) ?></code>
    <button class="btn btn-sm" type="button" data-copy="<?= e($inviteLink) ?>"><?= icon('copy', 14) ?>Αντιγραφή</button>
  </div>
<?php endif; ?>

<div class="split">
  <section class="card flush">
    <div class="table-wrap">
      <table class="table rtable">
        <thead><tr><th>Χρήστης</th><th>Ρόλος</th><th>Τελευταία σύνδεση</th><th class="num"></th></tr></thead>
        <tbody>
          <?php foreach ($members as $m): ?>
            <tr>
              <td class="primary"><div class="row" style="flex-wrap:nowrap"><span class="avatar"><?= e(initials($m['name'])) ?></span><div class="stack-sm" style="gap:0;min-width:0"><a class="small" style="font-weight:600;color:var(--text)" href="<?= e(url('users/' . $m['id'] . '/edit')) ?>"><?= e($m['name']) ?><?= (int) $m['id'] === Auth::id() ? ' (εσύ)' : '' ?></a><?= $m['status'] === 'disabled' ? ' <span class="badge off">Ανενεργός</span>' : '' ?><span class="xs muted"><?= e($m['email']) ?></span></div></div></td>
              <td data-label="Ρόλος">
                <form method="post" action="<?= e(url('users/' . $m['id'] . '/role')) ?>" class="inline">
                  <?= csrf_field() ?>
                  <label class="sr-only" for="role-<?= (int) $m['id'] ?>">Ρόλος</label>
                  <select id="role-<?= (int) $m['id'] ?>" class="select" name="role_id" data-autosubmit style="min-height:36px;width:auto">
                    <?php foreach ($roles as $r): ?><option value="<?= (int) $r['id'] ?>" <?= (int) $r['id'] === (int) $m['role_id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?>
                  </select>
                </form>
              </td>
              <td data-label="Τελευταία σύνδεση" class="small muted"><?= e($m['last_login_at'] ? rel_time($m['last_login_at']) : 'Ποτέ') ?></td>
              <td class="num nowrap">
                <a class="btn btn-sm" href="<?= e(url('users/' . $m['id'] . '/edit')) ?>"><?= icon('edit', 14) ?>Επεξεργασία</a>
                <?php if ((int) $m['id'] !== Auth::id()): ?>
                  <form method="post" action="<?= e(url('users/' . $m['id'] . '/remove')) ?>" class="inline" data-confirm="Να διαγραφεί ο/η <?= e($m['name']) ?>; Αν ανήκει και σε άλλους οργανισμούς, θα αφαιρεθεί μόνο από αυτόν.">
                    <?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit" style="color:var(--fail-fg)" aria-label="Διαγραφή <?= e($m['name']) ?>"><?= icon('trash', 14) ?></button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <aside class="stack">
    <form method="post" action="<?= e(url('users/invite')) ?>" class="card stack">
      <?= csrf_field() ?>
      <h2>Πρόσκληση χρήστη</h2>
      <label class="field">Email<input class="input" type="email" name="email" required></label>
      <label class="field">Ρόλος
        <select class="select" name="role_id"><?php foreach ($roles as $r): ?><option value="<?= (int) $r['id'] ?>" <?= $r['name'] === 'Operator' ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select>
      </label>
      <button class="btn btn-primary" type="submit"><?= icon('send', 16) ?>Αποστολή πρόσκλησης</button>
      <p class="xs muted">Στέλνεται email μέσω του προεπιλεγμένου email connector. Αν δεν υπάρχει, θα πάρεις σύνδεσμο για να τον στείλεις εσύ.</p>
    </form>
    <?php if ($invites): ?>
      <section class="card">
        <h2 style="margin-bottom:6px">Εκκρεμείς προσκλήσεις</h2>
        <?php foreach ($invites as $i): ?>
          <div class="list-item">
            <div class="grow stack-sm" style="gap:0"><strong class="small"><?= e($i['email']) ?></strong><span class="xs muted"><?= e($i['role_name']) ?> · λήγει <?= e(rel_time($i['expires_at'])) ?></span></div>
            <form method="post" action="<?= e(url('invitations/' . $i['id'] . '/revoke')) ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Ακύρωση</button></form>
          </div>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>
  </aside>
</div>
