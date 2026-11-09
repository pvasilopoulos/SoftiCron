<?php
/** @var array $rows @var ?array $edit */
$isEdit = $edit !== null;
?>
<div class="page-head">
  <div><h1>Μεταβλητές & secrets</h1><p class="sub">Τιμές που χρησιμοποιούνται σε URL, headers, body και Auth των jobs. Αλλάζεις την τιμή μία φορά και ενημερώνονται όλα τα jobs.</p></div>
</div>

<div class="split">
  <section class="card flush">
    <?php if (!$rows): ?>
      <div class="empty"><?= icon('lock', 40) ?><span>Δεν υπάρχουν μεταβλητές. Πρόσθεσε π.χ. <code>ERP_BASE_URL</code> ή το secret <code>ERP_TOKEN</code>.</span></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table rtable">
          <thead><tr><th>Μεταβλητή</th><th>Τιμή</th><th>Χρήση</th><th class="num"><span class="sr-only">Ενέργειες</span></th></tr></thead>
          <tbody>
            <?php foreach ($rows as $r): $tag = ($r['is_secret'] ? 'secrets.' : 'vars.') . $r['name']; ?>
              <tr>
                <td class="primary">
                  <div class="stack-sm" style="gap:3px">
                    <div class="row" style="gap:8px"><code class="small" style="font-weight:600"><?= e($r['name']) ?></code><?= $r['is_secret'] ? '<span class="badge off">' . icon('lock', 11) . ' secret</span>' : '' ?></div>
                    <button type="button" class="var-chip" style="align-self:flex-start" data-copy="{{ <?= e($tag) ?> }}" title="Αντιγραφή">{{ <?= e($tag) ?> }}</button>
                    <?php if ($r['description']): ?><span class="xs muted"><?= e($r['description']) ?></span><?php endif; ?>
                  </div>
                </td>
                <td data-label="Τιμή" class="mono small" style="max-width:260px;word-break:break-all"><?= $r['is_secret'] ? '<span class="muted">••••••••</span>' : e(mb_strimwidth($r['value'], 0, 120, '…')) ?></td>
                <td data-label="Χρήση" class="small muted"><?= (int) $r['used_by'] ? (int) $r['used_by'] . ' jobs' : '—' ?></td>
                <td class="num">
                  <div class="row-actions">
                    <?php if (\App\Auth\Auth::can('variables.manage')): ?>
                    <a class="btn btn-sm btn-icon" href="<?= e(url('variables', ['edit' => $r['id']])) ?>" title="Επεξεργασία" aria-label="<?= e('Επεξεργασία ' . $r['name']) ?>"><?= icon('edit', 14) ?></a>
                    <form method="post" action="<?= e(url('variables/' . $r['id'] . '/delete')) ?>" class="inline" data-confirm="<?= e('Να διαγραφεί η μεταβλητή ' . $r['name'] . '; Τα jobs που τη χρησιμοποιούν θα στέλνουν κενή τιμή.') ?>">
                      <?= csrf_field() ?><button class="btn btn-sm btn-icon btn-danger" type="submit" title="Διαγραφή" aria-label="<?= e('Διαγραφή ' . $r['name']) ?>"><?= icon('trash', 14) ?></button>
                    </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <aside class="stack">
    <?php if (\App\Auth\Auth::can('variables.manage')): ?>
    <form method="post" action="<?= e($isEdit ? url('variables/' . $edit['id']) : url('variables')) ?>" class="card stack" autocomplete="off">
      <?= csrf_field() ?>
      <h2><?= $isEdit ? 'Επεξεργασία ' . e($edit['name']) : 'Νέα μεταβλητή' ?></h2>
      <label class="field">Όνομα <span class="help">Λατινικά, αριθμοί και _ (π.χ. ERP_TOKEN)</span>
        <input class="input mono" name="name" value="<?= e($edit['name'] ?? '') ?>" required pattern="[A-Za-z][A-Za-z0-9_]{0,63}" maxlength="64">
      </label>
      <label class="field">Τιμή
        <textarea class="textarea mono" name="value" rows="3" style="min-height:70px" <?= $isEdit && $edit['is_secret'] ? 'placeholder="•••••••• (αμετάβλητο αν μείνει κενό)"' : '' ?>><?= $isEdit && !$edit['is_secret'] ? e($edit['value']) : '' ?></textarea>
      </label>
      <label class="check"><input type="checkbox" name="is_secret" value="1" <?= ($edit['is_secret'] ?? 0) ? 'checked' : '' ?>><?= icon('lock', 14) ?> Secret <span class="xs muted">(δεν εμφανίζεται ξανά, κρύβεται στα logs)</span></label>
      <label class="field">Περιγραφή <span class="help">Προαιρετική</span><input class="input" name="description" value="<?= e($edit['description'] ?? '') ?>" maxlength="255"></label>
      <div class="form-actions">
        <?php if ($isEdit): ?><a class="btn" href="<?= e(url('variables')) ?>">Ακύρωση</a><?php endif; ?>
        <button class="btn btn-primary" type="submit"><?= $isEdit ? 'Αποθήκευση' : 'Προσθήκη' ?></button>
      </div>
    </form>
    <?php endif; ?>
    <section class="card stack-sm small">
      <h2>Πώς χρησιμοποιούνται</h2>
      <p>Σε URL, headers, body ή στα πεδία Auth ενός job:</p>
      <pre class="code" style="font-size:12px">{{ vars.ERP_BASE_URL }}/api/orders
Authorization: Bearer {{ secrets.ERP_TOKEN }}</pre>
      <p class="xs muted">Όλες οι τιμές αποθηκεύονται κρυπτογραφημένες. Τα secrets αντικαθίστανται με •••• στα logs εκτελέσεων.</p>
    </section>
  </aside>
</div>
