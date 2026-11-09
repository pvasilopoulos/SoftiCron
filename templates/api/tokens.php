<?php
/** @var array $tokens @var ?string $newToken */
use App\Services\ApiTokens;

$tz = App\Auth\Auth::tenant()['timezone'];
?>
<div class="page-head">
  <div><h1>API tokens</h1><p class="sub">Πρόσβαση στο REST API για άλλες εφαρμογές (ERP, scripts, CI/CD). Κάθε token δρα με τα δικαιώματα του χρήστη που το δημιούργησε, περιορισμένα στα scopes του.</p></div>
  <a class="btn" href="<?= e(url('api-docs')) ?>"><?= icon('templates', 16) ?>Τεκμηρίωση API</a>
</div>

<?php if ($newToken): ?>
  <section class="card stack-sm" style="border-color:var(--ok-fg)">
    <h2><?= icon('key', 16) ?> Το νέο σου token</h2>
    <p class="small">Αντέγραψέ το και φύλαξέ το σε ασφαλές σημείο. <strong>Δεν θα εμφανιστεί ξανά.</strong></p>
    <div class="row" style="flex-wrap:nowrap">
      <input class="input mono grow" value="<?= e($newToken) ?>" readonly onclick="this.select()" aria-label="Νέο API token">
      <button class="btn btn-primary" type="button" data-copy="<?= e($newToken) ?>"><?= icon('copy', 16) ?>Αντιγραφή</button>
    </div>
    <pre class="code">curl -H "Authorization: Bearer <?= e($newToken) ?>" <?= e(abs_url('api/v1/jobs')) ?></pre>
  </section>
<?php endif; ?>

<div class="split">
  <section class="card flush">
    <?php if (!$tokens): ?>
      <div class="empty"><?= icon('key', 40) ?><span>Δεν υπάρχουν API tokens ακόμα.</span></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table rtable">
          <thead><tr><th>Token</th><th>Scopes</th><th>Τελευταία χρήση</th><th>Λήξη</th><th class="num"><span class="sr-only">Ενέργειες</span></th></tr></thead>
          <tbody>
            <?php foreach ($tokens as $t):
                $expired = $t['expires_at'] && strtotime($t['expires_at'] . ' UTC') < time();
                $state = $t['revoked_at'] ? ['off', 'Ανακλήθηκε'] : ($expired ? ['fail', 'Έληξε'] : ['ok', 'Ενεργό']); ?>
              <tr style="<?= $t['revoked_at'] || $expired ? 'opacity:.6' : '' ?>">
                <td class="primary">
                  <div class="stack-sm" style="gap:2px">
                    <div class="row" style="gap:8px"><strong class="small"><?= e($t['name']) ?></strong><span class="badge <?= $state[0] ?>"><?= e($state[1]) ?></span></div>
                    <span class="xs muted"><code><?= e($t['prefix']) ?>…</code> · <?= e($t['user_name'] ?? 'διαγραμμένος χρήστης') ?> · <?= e(fmt_dt($t['created_at'], $tz, 'd/m/Y')) ?></span>
                  </div>
                </td>
                <td data-label="Scopes"><div class="chips"><?php foreach (explode(',', (string) $t['scopes']) as $s): ?><span class="tag"><?= e(ApiTokens::SCOPES[$s][0] ?? $s) ?></span><?php endforeach; ?></div></td>
                <td data-label="Τελευταία χρήση" class="small muted"><?= $t['last_used_at'] ? e(rel_time($t['last_used_at'])) . '<div class="xs mono">' . e($t['last_used_ip']) . '</div>' : 'Ποτέ' ?></td>
                <td data-label="Λήξη" class="small muted"><?= $t['expires_at'] ? e(fmt_dt($t['expires_at'], $tz, 'd/m/Y')) : 'Ποτέ' ?></td>
                <td class="num">
                  <?php if (!$t['revoked_at']): ?>
                    <form method="post" action="<?= e(url('api-tokens/' . $t['id'] . '/revoke')) ?>" class="inline" data-confirm="<?= e('Να ανακληθεί το token «' . $t['name'] . '»; Οι εφαρμογές που το χρησιμοποιούν θα σταματήσουν να λειτουργούν.') ?>">
                      <?= csrf_field() ?><button class="btn btn-sm btn-danger" type="submit"><?= icon('x', 14) ?>Ανάκληση</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <aside class="stack">
    <form method="post" action="<?= e(url('api-tokens')) ?>" class="card stack" autocomplete="off">
      <?= csrf_field() ?>
      <h2>Νέο token</h2>
      <label class="field">Όνομα <span class="help">Για να θυμάσαι πού χρησιμοποιείται</span><input class="input" name="name" required maxlength="100" placeholder="π.χ. ERP integration"></label>
      <fieldset class="stack-sm" style="border:0;padding:0;margin:0">
        <legend class="small" style="font-weight:500;color:var(--text-2);margin-bottom:6px">Scopes</legend>
        <?php foreach (ApiTokens::SCOPES as $k => [$label, $desc]): ?>
          <label class="check" style="align-items:flex-start"><input type="checkbox" name="scopes[]" value="<?= e($k) ?>" <?= $k === 'read' ? 'checked' : '' ?> style="margin-top:3px"><span class="stack-sm" style="gap:0"><span><?= e($label) ?> <code class="xs"><?= e($k) ?></code></span><span class="xs muted"><?= e($desc) ?></span></span></label>
        <?php endforeach; ?>
      </fieldset>
      <label class="field">Λήξη
        <select class="select" name="expires_days">
          <option value="30">30 ημέρες</option><option value="90" selected>90 ημέρες</option><option value="365">1 έτος</option><option value="0">Χωρίς λήξη</option>
        </select>
      </label>
      <button class="btn btn-primary" type="submit"><?= icon('key', 16) ?>Δημιουργία token</button>
    </form>
    <section class="card stack-sm small">
      <h2>Ασφάλεια</h2>
      <p>Αποθηκεύεται μόνο το hash του token. Αν χαθεί, ανακάλεσέ το και φτιάξε νέο.</p>
      <p class="xs muted">Όριο: 120 αιτήματα ανά λεπτό ανά token. Όλες οι αλλαγές μέσω API καταγράφονται στο audit log με το όνομα του token.</p>
    </section>
  </aside>
</div>
