<?php
/**
 * @var array $settings @var bool $hasPassword @var array $files @var array $tenants @var ?array $restore @var bool $mailer
 */
use App\Services\Backup;

$tz = new DateTimeZone('Europe/Athens');
$fmtTs = fn (int $ts) => (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('d/m/Y H:i');
$size = fn (int $b) => $b >= 1048576 ? number_format($b / 1048576, 1, ',', '.') . ' MB' : max(1, (int) round($b / 1024)) . ' KB';
$auto = $settings['backup.auto'] ?? 'off';
$labels = ['created' => ['ok', 'Νέο'], 'updated' => ['run', 'Ενημερώθηκε'], 'skipped' => ['off', 'Παραλείφθηκε'], 'error' => ['fail', 'Σφάλμα']];
$sectionLabel = Backup::SECTIONS + ['tenant' => 'Οργανισμός'];
$totJobs = array_sum(array_column($tenants, 'jobs'));
?>
<div class="page-head">
  <div>
    <h1>Backup πλατφόρμας</h1>
    <p class="sub">Όλοι οι οργανισμοί σε ένα αρχείο · <?= count($tenants) ?> οργανισμοί, <?= (int) $totJobs ?> jobs</p>
  </div>
</div>

<?php if ($restore !== null): ?>
  <section class="card stack-sm" id="restore-result">
    <div class="row between">
      <h2><?= $restore['dry'] ? 'Αποτέλεσμα ελέγχου (δεν αποθηκεύτηκε τίποτα)' : 'Αποτέλεσμα επαναφοράς' ?></h2>
      <div class="chips">
        <?php foreach ($labels as $k => [$cls, $l]): if (!empty($restore['sum'][$k])): ?><span class="badge <?= $cls ?>"><?= e($l) ?>: <?= (int) $restore['sum'][$k] ?></span><?php endif; endforeach; ?>
      </div>
    </div>
    <p class="small muted">
      Αρχείο <span class="mono"><?= e($restore['source']) ?></span>
      <?php if ($restore['created_at']): ?>· backup της <?= e($fmtTs((int) strtotime($restore['created_at']))) ?><?php endif; ?>
      <?php if (!empty($restore['contents']['tenants'])): ?>· <?= (int) $restore['contents']['tenants'] ?> οργανισμοί στο αρχείο<?php endif; ?>
      · <?= $restore['mode'] === 'update' ? 'αντικατάσταση υπαρχόντων' : 'μόνο όσα λείπουν' ?>
    </p>
    <div class="table-wrap">
      <table class="table rtable">
        <thead><tr><th>Οργανισμός</th><th>Ενότητα</th><th>Στοιχείο</th><th>Αποτέλεσμα</th><th>Λεπτομέρειες</th></tr></thead>
        <tbody>
          <?php foreach ($restore['log'] as [$ten, $sec, $label, $st, $msg]): [$cls, $l] = $labels[$st] ?? ['off', $st]; ?>
            <tr>
              <td data-label="Οργανισμός"><strong><?= e($ten) ?></strong></td>
              <td data-label="Ενότητα" class="small"><?= e($sectionLabel[$sec] ?? $sec) ?></td>
              <td data-label="Στοιχείο"><?= e($label) ?></td>
              <td data-label="Αποτέλεσμα"><span class="badge <?= $cls ?>"><?= e($l) ?></span></td>
              <td data-label="Λεπτομέρειες" class="small muted"><?= e($msg ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($restore['dry']): ?><p class="small muted">Αν όλα φαίνονται σωστά, ξανακάνε την επαναφορά χωρίς το «Μόνο έλεγχος».</p><?php endif; ?>
  </section>
<?php endif; ?>

<div class="backup-grid">
  <form method="post" action="<?= e(url('admin/backup/download')) ?>" class="card stack">
    <?= csrf_field() ?>
    <div class="bk-head"><span class="bk-ic"><?= icon('lock', 20) ?></span><div><h2>Πλήρες backup όλων</h2><p class="small muted">Ένα κρυπτογραφημένο αρχείο για επαναφορά, με κωδικό που διαλέγεις.</p></div></div>
    <ul class="bk-list small">
      <li><strong><?= count($tenants) ?></strong> οργανισμοί με τα στοιχεία τους (όνομα, slug, όρια πλάνου, ζώνη ώρας)</li>
      <li>Για κάθε οργανισμό: jobs με τα secrets, connectors, μεταβλητές, templates, ρυθμίσεις</li>
      <li>Δεν περιλαμβάνει χρήστες, ιστορικό εκτελέσεων και audit log</li>
    </ul>
    <div class="form-grid">
      <label class="field">Κωδικός backup<input class="input" type="password" name="password" minlength="<?= Backup::MIN_PASSWORD ?>" required autocomplete="new-password"></label>
      <label class="field">Επιβεβαίωση<input class="input" type="password" name="password_confirm" minlength="<?= Backup::MIN_PASSWORD ?>" required autocomplete="new-password"></label>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('download', 16) ?>Λήψη backup</button></div>
  </form>

  <form method="get" action="<?= e(url('admin/backup/report')) ?>" class="card stack">
    <div class="bk-head"><span class="bk-ic bk-ic-xls"><?= icon('templates', 20) ?></span><div><h2>Αναλυτική αναφορά Excel όλων</h2><p class="small muted">Ένα αρχείο με στήλη «Οργανισμός» σε κάθε φύλλο.</p></div></div>
    <ul class="bk-list small">
      <li><strong>Οργανισμοί:</strong> σύνοψη ανά οργανισμό (jobs, ενεργά, αποτυχίες, εκτελέσεις 30 ημερών)</li>
      <li><strong>Jobs, Ειδοποιήσεις, Έλεγχοι, Headers, Επόμενες εκτελέσεις</strong> για όλους</li>
      <li>Οι τιμές των secrets εμφανίζονται ως ••••••</li>
    </ul>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('download', 16) ?>Λήψη Excel</button></div>
  </form>
</div>

<section class="card stack" id="auto">
  <div class="bk-head"><span class="bk-ic"><?= icon('refresh', 20) ?></span><div><h2>Αυτόματο backup πλατφόρμας</h2><p class="small muted">Αρχεία στο <span class="mono">storage/backups/platform</span>, ανεξάρτητα από τα backup κάθε οργανισμού.</p></div></div>
  <?php if (!empty($settings['backup.last_error'])): ?>
    <div class="alert-item" style="border-color:var(--fail-fg)"><?= icon('x', 18) ?><div class="small"><strong>Το τελευταίο backup απέτυχε</strong><br><?= e($settings['backup.last_error']) ?></div></div>
  <?php endif; ?>
  <form method="post" action="<?= e(url('admin/backup/settings')) ?>" class="stack">
    <?= csrf_field() ?>
    <div class="form-grid bk-sched">
      <label class="field">Συχνότητα
        <select class="select" name="auto" data-bk-auto><?php foreach (Backup::SCHEDULES as $k => $l): ?><option value="<?= $k ?>" <?= $auto === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
      </label>
      <label class="field" data-bk-weekly <?= $auto === 'weekly' ? '' : 'hidden' ?>>Ημέρα
        <select class="select" name="weekday"><?php foreach (Backup::WEEKDAYS as $k => $l): ?><option value="<?= $k ?>" <?= (int) ($settings['backup.weekday'] ?? 1) === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
      </label>
      <label class="field">Ώρα
        <select class="select" name="hour"><?php for ($h = 0; $h < 24; $h++): ?><option value="<?= $h ?>" <?= (int) ($settings['backup.hour'] ?? 2) === $h ? 'selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option><?php endfor; ?></select>
        <span class="help">Ώρα Europe/Athens</span>
      </label>
      <label class="field">Διατήρηση
        <input class="input" type="number" name="keep" min="1" max="90" value="<?= e($settings['backup.keep'] ?? '14') ?>">
        <span class="help">Τελευταία backup στον server</span>
      </label>
    </div>
    <label class="field">Αποστολή με email σε
      <input class="input" name="emails" value="<?= e($settings['backup.emails'] ?? '') ?>" placeholder="admin@softify.gr">
      <span class="help"><?= $mailer ? 'Κενό = μόνο στον server. Στέλνεται από τον platform mailer (MAIL_* στο .env).' : 'Ο platform mailer δεν έχει ρυθμιστεί (MAIL_HOST / MAIL_FROM στο .env): τα backup μένουν μόνο στον server.' ?></span>
    </label>
    <label class="check"><input type="checkbox" name="attach_report" value="1" <?= ($settings['backup.attach_report'] ?? '') === '1' ? 'checked' : '' ?>>Να επισυνάπτεται και η αναφορά Excel όλων</label>
    <div class="form-grid">
      <label class="field">Κωδικός αποθηκευμένων backup
        <input class="input" type="password" name="password" minlength="<?= Backup::MIN_PASSWORD ?>" autocomplete="new-password" placeholder="<?= $hasPassword ? '•••••••• (έχει οριστεί, κενό = χωρίς αλλαγή)' : '' ?>">
      </label>
      <label class="field">Επιβεβαίωση<input class="input" type="password" name="password_confirm" minlength="<?= Backup::MIN_PASSWORD ?>" autocomplete="new-password"></label>
    </div>
    <p class="xs muted">Κράτα τον κωδικό και κάπου εκτός εφαρμογής: θα τον χρειαστείς για επαναφορά σε νέο server.</p>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Αποθήκευση</button></div>
  </form>

  <div class="row between" style="margin-top:6px">
    <h3 style="margin:0">Αποθηκευμένα backup</h3>
    <?php if ($hasPassword): ?>
      <form method="post" action="<?= e(url('admin/backup/run')) ?>" class="inline" data-loading><?= csrf_field() ?><button class="btn btn-sm" type="submit"><?= icon('play', 14) ?>Backup τώρα</button></form>
    <?php endif; ?>
  </div>
  <?php if ($files): ?>
    <div class="table-wrap">
      <table class="table rtable">
        <thead><tr><th>Αρχείο</th><th>Ημερομηνία</th><th>Μέγεθος</th><th class="right">Ενέργειες</th></tr></thead>
        <tbody>
          <?php foreach ($files as $f): ?>
            <tr>
              <td data-label="Αρχείο" class="mono small" style="word-break:break-all"><?= e($f['name']) ?></td>
              <td data-label="Ημερομηνία"><?= e($fmtTs($f['time'])) ?></td>
              <td data-label="Μέγεθος"><?= e($size($f['size'])) ?></td>
              <td data-label="Ενέργειες" class="right">
                <div class="row" style="justify-content:flex-end;gap:6px">
                  <a class="btn btn-sm" href="<?= e(url('admin/backup/files/' . $f['name'])) ?>"><?= icon('download', 14) ?>Λήψη</a>
                  <a class="btn btn-sm" href="#restore" data-bk-restore="<?= e($f['name']) ?>">Επαναφορά</a>
                  <form method="post" action="<?= e(url('admin/backup/files/' . $f['name'] . '/delete')) ?>" class="inline" onsubmit="return confirm('Διαγραφή του backup;')"><?= csrf_field() ?><button class="btn btn-sm btn-ghost btn-icon" type="submit" aria-label="Διαγραφή" title="Διαγραφή"><?= icon('trash', 14) ?></button></form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p class="empty small"><?= $hasPassword ? 'Δεν υπάρχουν ακόμα αποθηκευμένα backup.' : 'Όρισε κωδικό και συχνότητα για να ξεκινήσουν τα αυτόματα backup.' ?></p>
  <?php endif; ?>
</section>

<form method="post" action="<?= e(url('admin/backup/restore')) ?>" enctype="multipart/form-data" class="card stack" id="restore" data-loading>
  <?= csrf_field() ?>
  <div class="bk-head"><span class="bk-ic bk-ic-warn"><?= icon('upload', 20) ?></span><div><h2>Επαναφορά από backup πλατφόρμας</h2><p class="small muted">Οι οργανισμοί αντιστοιχίζονται με το slug τους. Τα backup ενός οργανισμού επαναφέρονται από τη δική του σελίδα Backup & επαναφορά.</p></div></div>
  <input type="hidden" name="stored" value="" data-bk-stored>
  <div class="bk-stored-note small" data-bk-stored-note hidden>Επαναφορά από το αποθηκευμένο <strong class="mono" data-bk-stored-name></strong> · <a href="#" data-bk-stored-clear>άλλο αρχείο</a></div>
  <div class="form-grid" data-bk-upload>
    <label class="field">Αρχείο backup πλατφόρμας<input class="input" type="file" name="file" accept=".json,application/json" data-bk-file required></label>
    <label class="field">Κωδικός backup<input class="input" type="password" name="password" autocomplete="off" data-bk-pass required></label>
  </div>
  <div class="form-grid">
    <label class="field">Οργανισμοί
      <select class="select" name="tenant">
        <option value="">Όλοι οι οργανισμοί του backup</option>
        <?php foreach ($tenants as $t): ?><option value="<?= e($t['slug']) ?>"><?= e($t['name']) ?> (<?= e($t['slug']) ?>)</option><?php endforeach; ?>
      </select>
    </label>
    <label class="field">Όταν υπάρχει ήδη στοιχείο με το ίδιο όνομα
      <select class="select" name="mode"><option value="skip">Να μείνει ως έχει (μόνο όσα λείπουν)</option><option value="update">Να αντικατασταθεί από το backup</option></select>
    </label>
  </div>
  <fieldset class="stack-sm" style="border:0;padding:0;margin:0">
    <legend class="small" style="font-weight:600;margin-bottom:6px">Τι θα επαναφερθεί</legend>
    <div class="chips">
      <?php foreach (Backup::SECTIONS as $k => $l): ?>
        <label class="check"><input type="checkbox" name="sections[]" value="<?= $k ?>" <?= $k !== 'settings' ? 'checked' : '' ?>><?= e($l) ?></label>
      <?php endforeach; ?>
    </div>
  </fieldset>
  <div class="chips">
    <label class="check"><input type="checkbox" name="create_missing" value="1" checked>Δημιουργία οργανισμών που δεν υπάρχουν</label>
    <label class="check"><input type="checkbox" name="paused" value="1">Τα jobs να μπουν σε παύση</label>
    <label class="check"><input type="checkbox" name="dry_run" value="1" checked>Μόνο έλεγχος (χωρίς αλλαγές)</label>
  </div>
  <p class="xs muted">Οι νέοι οργανισμοί δημιουργούνται χωρίς μέλη: πρόσθεσε τον ιδιοκτήτη από τους Χρήστες ή μπες στον οργανισμό από τους Tenants. Πριν από κάθε αλλαγή υπάρχοντος job κρατιέται έκδοση.</p>
  <div class="form-actions"><button class="btn btn-primary" type="submit">Επαναφορά</button></div>
</form>

<script>
(() => {
  const auto = document.querySelector('[data-bk-auto]');
  const weekly = document.querySelector('[data-bk-weekly]');
  auto && auto.addEventListener('change', () => { weekly.hidden = auto.value !== 'weekly'; });
  const form = document.getElementById('restore');
  const stored = form.querySelector('[data-bk-stored]');
  const note = form.querySelector('[data-bk-stored-note]');
  const upload = form.querySelector('[data-bk-upload]');
  const file = form.querySelector('[data-bk-file]');
  const pass = form.querySelector('[data-bk-pass]');
  const useStored = (name) => {
    stored.value = name || '';
    note.hidden = !name;
    upload.hidden = !!name;
    file.required = pass.required = !name;
    if (name) { form.querySelector('[data-bk-stored-name]').textContent = name; }
  };
  document.querySelectorAll('[data-bk-restore]').forEach((a) => a.addEventListener('click', () => useStored(a.dataset.bkRestore)));
  form.querySelector('[data-bk-stored-clear]').addEventListener('click', (e) => { e.preventDefault(); useStored(''); });
})();
</script>
