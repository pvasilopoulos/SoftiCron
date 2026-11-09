<?php
/** @var array $tenant @var array $settings @var array $options @var array $jobs @var array $notices @var ?array $edit @var ?array $preview @var string $tab */
use App\Services\StatusPage;

$tz = $tenant['timezone'] ?: 'Europe/Athens';
$slug = $settings['status.slug'] ?? $tenant['slug'];
$on = ($settings['status.enabled'] ?? '') === '1';
$pub = abs_url('status/' . $slug);
$shown = count(array_filter($jobs, fn ($j) => (int) $j['show_on_status'] === 1));
$local = fn (?string $utc) => $utc ? (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz))->format('Y-m-d\TH:i') : '';
$overallTxt = ['up' => ['ok', 'Όλα λειτουργούν'], 'degraded' => ['slow', 'Μεμονωμένα προβλήματα'], 'partial' => ['fail', 'Μερική διακοπή'], 'major' => ['fail', 'Σοβαρή διακοπή'], 'unknown' => ['off', 'Χωρίς δεδομένα'], 'incident' => ['slow', 'Συμβάν υπό διερεύνηση'], 'maintenance' => ['run', 'Συντήρηση']];
$now = time();
$noticeState = function (array $n) use ($now): array {
    $s = strtotime($n['starts_at'] . ' UTC');
    $e = $n['ends_at'] ? strtotime($n['ends_at'] . ' UTC') : null;
    return $s > $now ? ['run', 'Προγραμματισμένη'] : ($e !== null && $e <= $now ? ['off', 'Έληξε'] : ['ok', 'Ενεργή']);
};
$tabs = ['components' => ['Υπηρεσίες', $shown], 'notices' => ['Ανακοινώσεις', count(array_filter($notices, fn ($n) => $noticeState($n)[1] !== 'Έληξε'))], 'appearance' => ['Ρυθμίσεις & εμφάνιση', null], 'embed' => ['Ενσωμάτωση', null]];
?>
<div class="page-head">
  <div>
    <h1>Σελίδα κατάστασης</h1>
    <p class="sub">Δημόσια σελίδα για τους πελάτες σου με την κατάσταση των υπηρεσιών, το uptime και τις ανακοινώσεις</p>
  </div>
  <div class="row">
    <?php if ($on): ?>
      <a class="btn" href="<?= e($pub) ?>" target="_blank" rel="noopener"><?= icon('send', 16) ?>Άνοιγμα σελίδας</a>
    <?php endif; ?>
  </div>
</div>

<section class="card status-hero">
  <div class="row" style="gap:14px;flex-wrap:nowrap;min-width:0">
    <span class="badge <?= $on ? 'ok' : 'off' ?>" style="flex:none"><?= $on ? 'Δημοσιευμένη' : 'Ανενεργή' ?></span>
    <div class="stack-sm" style="gap:2px;min-width:0">
      <span class="mono small" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($pub) ?></span>
      <span class="xs muted"><?= $shown ?> υπηρεσίες<?php if ($preview): [$oc, $ot] = $overallTxt[$preview['overall']]; ?> · τώρα: <span class="badge <?= $oc ?>" style="font-size:11px"><?= e($ot) ?></span><?php endif; ?></span>
    </div>
  </div>
  <div class="row" style="gap:6px">
    <button class="btn btn-sm" type="button" data-copy="<?= e($pub) ?>"><?= icon('copy', 14) ?>Αντιγραφή συνδέσμου</button>
    <?php if (!$on): ?><a class="btn btn-sm btn-primary" href="<?= e(url('status-page', ['tab' => 'appearance'])) ?>">Ενεργοποίηση</a><?php endif; ?>
  </div>
</section>

<nav class="tabs" aria-label="Ενότητες σελίδας κατάστασης">
  <?php foreach ($tabs as $k => [$l, $n]): ?>
    <a class="<?= $tab === $k ? 'on' : '' ?>" href="<?= e(url('status-page', ['tab' => $k])) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= e($l) ?><?= $n !== null ? ' <span class="muted">' . $n . '</span>' : '' ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'components'): ?>
  <form method="post" action="<?= e(url('status-page/components')) ?>" class="card flush" data-sp-components>
    <?= csrf_field() ?>
    <div class="row between" style="padding:14px 18px;gap:10px">
      <div class="feed-search" style="margin:0;flex:1;max-width:340px"><?= icon('search', 14) ?><input type="search" data-sp-q placeholder="Αναζήτηση job ή ομάδας…" aria-label="Αναζήτηση"></div>
      <div class="row" style="gap:6px">
        <label class="check small"><input type="checkbox" data-sp-only>Μόνο όσα εμφανίζονται</label>
        <button class="btn btn-sm" type="button" data-sp-all="1">Όλα</button>
        <button class="btn btn-sm" type="button" data-sp-all="0">Κανένα</button>
      </div>
    </div>
    <?php if (!$jobs): ?>
      <div class="empty"><?= icon('jobs', 36) ?><span>Δεν υπάρχουν jobs ακόμα.</span></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table rtable">
          <thead><tr><th style="width:44px"><span class="sr-only">Εμφάνιση</span></th><th>Job</th><th>Όνομα στη σελίδα</th><th>Ομάδα (ενότητα)</th><th>Τώρα</th></tr></thead>
          <tbody>
            <?php foreach ($jobs as $j): ?>
              <tr data-sp-row data-name="<?= e(mb_strtolower($j['name'] . ' ' . $j['group_name'] . ' ' . $j['status_name'])) ?>">
                <td><input type="checkbox" name="show[]" value="<?= (int) $j['id'] ?>" <?= $j['show_on_status'] ? 'checked' : '' ?> aria-label="<?= e('Εμφάνιση: ' . $j['name']) ?>" style="width:18px;height:18px;accent-color:var(--accent)"></td>
                <td class="primary"><a class="small" style="font-weight:600;color:var(--text)" href="<?= e(url('jobs/' . $j['id'] . '/edit')) ?>"><?= e($j['name']) ?></a><?= $j['type'] === 'heartbeat' ? ' <span class="tag xs">heartbeat</span>' : '' ?></td>
                <td data-label="Όνομα στη σελίδα"><input class="input" name="status_name[<?= (int) $j['id'] ?>]" value="<?= e($j['status_name'] ?? '') ?>" placeholder="<?= e($j['name']) ?>" maxlength="100" style="min-height:34px"></td>
                <td data-label="Ομάδα" class="small muted"><?= e($j['group_name'] ?? '—') ?></td>
                <td data-label="Τώρα"><?= !$j['enabled'] ? '<span class="badge off">Παύση</span>' : status_badge($j['last_status']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <div class="form-actions" style="padding:14px 18px;border-top:1px solid var(--border)">
      <span class="xs muted grow">Το «Όνομα στη σελίδα» είναι αυτό που βλέπουν οι πελάτες (π.χ. «Ηλεκτρονικό κατάστημα»). Η ομάδα του job γίνεται ενότητα στη σελίδα.</span>
      <button class="btn btn-primary" type="submit">Αποθήκευση</button>
    </div>
  </form>

<?php elseif ($tab === 'notices'): ?>
  <div class="split">
    <section class="card flush">
      <?php if (!$notices): ?>
        <div class="empty"><?= icon('bell', 36) ?><span>Δεν υπάρχουν ανακοινώσεις. Ενημέρωσε τους πελάτες σου για προγραμματισμένες συντηρήσεις ή για ένα συμβάν σε εξέλιξη.</span></div>
      <?php else: ?>
        <?php foreach ($notices as $n): [$sc, $sl] = $noticeState($n); [$kl, $kc] = StatusPage::NOTICE_KINDS[$n['kind']]; ?>
          <article class="sp-notice" style="border-left-color:<?= $kc ?>">
            <div class="row between" style="gap:8px">
              <div class="row" style="gap:6px">
                <span class="tag" style="color:<?= $kc ?>;font-weight:600"><?= e($kl) ?></span>
                <span class="badge <?= $sc ?>"><?= e($sl) ?></span>
                <?php if ($n['is_pinned']): ?><span class="tag">📌 καρφιτσωμένη</span><?php endif; ?>
              </div>
              <div class="row-actions">
                <a class="btn btn-sm btn-icon" href="<?= e(url('status-page', ['tab' => 'notices', 'notice' => $n['id']])) ?>" title="Επεξεργασία" aria-label="Επεξεργασία"><?= icon('edit', 14) ?></a>
                <?php if ($sl !== 'Έληξε'): ?>
                  <form method="post" action="<?= e(url('status-page/notices/' . $n['id'] . '/resolve')) ?>" class="inline"><?= csrf_field() ?><button class="btn btn-sm" type="submit" title="<?= $n['kind'] === 'incident' ? 'Σήμανση ως επιλυμένο' : 'Λήξη τώρα' ?>"><?= icon('check', 14) ?><?= $n['kind'] === 'incident' ? 'Επιλύθηκε' : 'Λήξη' ?></button></form>
                <?php endif; ?>
                <form method="post" action="<?= e(url('status-page/notices/' . $n['id'] . '/delete')) ?>" class="inline" data-confirm="Να διαγραφεί η ανακοίνωση;"><?= csrf_field() ?><button class="btn btn-sm btn-icon btn-danger" type="submit" aria-label="Διαγραφή"><?= icon('trash', 14) ?></button></form>
              </div>
            </div>
            <strong><?= e($n['title']) ?></strong>
            <?php if ($n['body']): ?><p class="small" style="white-space:pre-line;color:var(--text-2)"><?= e($n['body']) ?></p><?php endif; ?>
            <span class="xs muted"><?= e(fmt_dt($n['starts_at'], $tz, 'd/m/Y H:i')) ?><?= $n['ends_at'] ? ' → ' . e(fmt_dt($n['ends_at'], $tz, 'd/m/Y H:i')) : ' · χωρίς λήξη' ?></span>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>
    <aside class="stack">
      <form method="post" action="<?= e($edit ? url('status-page/notices/' . $edit['id']) : url('status-page/notices')) ?>" class="card stack">
        <?= csrf_field() ?>
        <h2><?= $edit ? 'Επεξεργασία ανακοίνωσης' : 'Νέα ανακοίνωση' ?></h2>
        <div class="seg" role="radiogroup" aria-label="Τύπος" style="flex-wrap:wrap">
          <?php foreach (StatusPage::NOTICE_KINDS as $k => [$l]): ?>
            <label><input type="radio" name="kind" value="<?= $k ?>" class="sr-only" <?= ($edit['kind'] ?? 'maintenance') === $k ? 'checked' : '' ?>><span class="seg-opt"><?= e($l) ?></span></label>
          <?php endforeach; ?>
        </div>
        <label class="field">Τίτλος<input class="input" name="title" value="<?= e($edit['title'] ?? '') ?>" maxlength="200" required placeholder="π.χ. Αναβάθμιση servers"></label>
        <label class="field">Μήνυμα <span class="help">Προαιρετικό</span><textarea class="textarea" name="body" rows="4" style="min-height:90px" placeholder="Τι επηρεάζεται και τι να περιμένουν οι πελάτες"><?= e($edit['body'] ?? '') ?></textarea></label>
        <div class="form-grid">
          <label class="field">Έναρξη<input class="input" type="datetime-local" name="starts_at" value="<?= e($local($edit['starts_at'] ?? null) ?: (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('Y-m-d\TH:i')) ?>"></label>
          <label class="field">Λήξη <span class="help">Κενό = μέχρι να τη λήξεις</span><input class="input" type="datetime-local" name="ends_at" value="<?= e($local($edit['ends_at'] ?? null)) ?>"></label>
        </div>
        <label class="check"><input type="checkbox" name="is_pinned" value="1" <?= !empty($edit['is_pinned']) ? 'checked' : '' ?>>Καρφίτσωμα στην κορυφή της σελίδας</label>
        <p class="xs muted">Ώρες σε ζώνη <?= e($tz) ?>. Οι προγραμματισμένες συντηρήσεις εμφανίζονται ως «Επερχόμενες» μέχρι να ξεκινήσουν.</p>
        <div class="form-actions">
          <?php if ($edit): ?><a class="btn" href="<?= e(url('status-page', ['tab' => 'notices'])) ?>">Ακύρωση</a><?php endif; ?>
          <button class="btn btn-primary" type="submit"><?= $edit ? 'Αποθήκευση' : 'Δημοσίευση' ?></button>
        </div>
      </form>
    </aside>
  </div>

<?php elseif ($tab === 'appearance'): ?>
  <form method="post" action="<?= e(url('status-page/settings')) ?>" class="split">
    <?= csrf_field() ?>
    <section class="card stack">
      <h2>Βασικά</h2>
      <label class="check"><input type="checkbox" name="enabled" value="1" <?= $on ? 'checked' : '' ?>><strong>Η σελίδα είναι δημόσια</strong></label>
      <label class="field">Διεύθυνση
        <div class="row" style="gap:4px;flex-wrap:nowrap"><span class="small muted nowrap"><?= e(abs_url('status/')) ?></span><input class="input mono" name="slug" value="<?= e($slug) ?>" pattern="[a-z0-9\-]+" maxlength="60"></div>
      </label>
      <label class="field">Τίτλος<input class="input" name="title" value="<?= e($settings['status.title'] ?? '') ?>" placeholder="<?= e($tenant['name']) ?>" maxlength="120"></label>
      <label class="field">Περιγραφή <span class="help">Προαιρετική</span><input class="input" name="description" value="<?= e($settings['status.description'] ?? '') ?>" maxlength="255" placeholder="π.χ. Κατάσταση των online υπηρεσιών μας"></label>
      <p class="xs muted">Το λογότυπο και το χρώμα έρχονται από το branding του οργανισμού (Ρυθμίσεις → Branding).</p>
    </section>
    <aside class="stack">
      <section class="card stack-sm">
        <h2>Τι εμφανίζεται</h2>
        <?php foreach (['show_uptime' => 'Ποσοστό διαθεσιμότητας (uptime)', 'show_response' => 'Χρόνος απόκρισης (24 ώρες)', 'show_history' => 'Ιστορικό συμβάντων 30 ημερών', 'show_notices' => 'Ανακοινώσεις & συντηρήσεις', 'collapse_groups' => 'Οι ομάδες ξεκινούν κλειστές', 'hide_powered' => 'Απόκρυψη «Powered by»', 'noindex' => 'Να μην εμφανίζεται στις μηχανές αναζήτησης'] as $k => $l): ?>
          <label class="check"><input type="checkbox" name="<?= $k ?>" value="1" <?= $options[$k] === '1' ? 'checked' : '' ?>><?= e($l) ?></label>
        <?php endforeach; ?>
        <div class="form-grid">
          <label class="field">Προεπιλεγμένη περίοδος
            <select class="select" name="default_days"><?php foreach (['7' => '7 ημέρες', '30' => '30 ημέρες', '90' => '90 ημέρες'] as $v => $l): ?><option value="<?= $v ?>" <?= $options['default_days'] === (string) $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
          </label>
          <label class="field">Θέμα
            <select class="select" name="theme"><?php foreach (['auto' => 'Αυτόματο', 'light' => 'Φωτεινό', 'dark' => 'Σκοτεινό'] as $v => $l): ?><option value="<?= $v ?>" <?= $options['theme'] === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
          </label>
        </div>
      </section>
      <div class="form-actions"><button class="btn btn-primary" type="submit">Αποθήκευση</button></div>
    </aside>
  </form>

<?php else: ?>
  <?php if (!$on): ?><p class="notice">Η σελίδα δεν είναι δημόσια ακόμα. Ενεργοποίησέ τη στις Ρυθμίσεις & εμφάνιση για να δουλέψουν οι παρακάτω κώδικες.</p><?php endif; ?>
  <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(320px,1fr))">
    <section class="card stack-sm">
      <h2>Badge</h2>
      <p class="small muted">Εικονίδιο κατάστασης για το site σου, README ή email υπογραφή. Ενημερώνεται αυτόματα.</p>
      <?php if ($on): ?><img src="<?= e(url('status/' . $slug . '.svg')) ?>" alt="Badge κατάστασης" style="align-self:flex-start;height:20px"><?php endif; ?>
      <pre class="code" style="font-size:12px">&lt;a href="<?= e($pub) ?>"&gt;&lt;img src="<?= e($pub) ?>.svg" alt="Κατάσταση"&gt;&lt;/a&gt;</pre>
      <pre class="code" style="font-size:12px">[![Κατάσταση](<?= e($pub) ?>.svg)](<?= e($pub) ?>)</pre>
      <p class="xs muted">Άλλο κείμενο: <code>.svg?label=Υπηρεσίες</code></p>
    </section>
    <section class="card stack-sm">
      <h2>Widget (iframe)</h2>
      <p class="small muted">Συμπαγής κάρτα κατάστασης για να μπει μέσα στο site σου.</p>
      <pre class="code" style="font-size:12px">&lt;iframe src="<?= e($pub) ?>/embed" width="100%" height="220" style="border:0" title="Κατάσταση υπηρεσιών"&gt;&lt;/iframe&gt;</pre>
      <?php if ($on): ?><iframe src="<?= e(url('status/' . $slug . '/embed')) ?>" width="100%" height="220" style="border:1px solid var(--border);border-radius:12px" title="Προεπισκόπηση widget"></iframe><?php endif; ?>
    </section>
    <section class="card stack-sm">
      <h2>RSS συμβάντων</h2>
      <p class="small muted">Οι πελάτες σου μπορούν να γραφτούν σε RSS reader, Slack ή Teams (RSS app) και να λαμβάνουν ανακοινώσεις και διακοπές.</p>
      <pre class="code" style="font-size:12px"><?= e($pub) ?>.rss</pre>
    </section>
    <section class="card stack-sm">
      <h2>JSON API</h2>
      <p class="small muted">Για monitoring εργαλεία ή δικές σου εφαρμογές: συνολική κατάσταση, υπηρεσίες με uptime 7/30/90 ημερών, ανακοινώσεις και συμβάντα.</p>
      <pre class="code" style="font-size:12px">curl <?= e($pub) ?>.json</pre>
    </section>
  </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const f = document.querySelector('[data-sp-components]');
  if (!f) return;
  const rows = [...f.querySelectorAll('[data-sp-row]')];
  const q = f.querySelector('[data-sp-q]');
  const only = f.querySelector('[data-sp-only]');
  const apply = () => {
    const t = q.value.trim().toLowerCase();
    rows.forEach((r) => { r.hidden = (t && !r.dataset.name.includes(t)) || (only.checked && !r.querySelector('input[type=checkbox]').checked); });
  };
  q.addEventListener('input', apply);
  only.addEventListener('change', apply);
  f.querySelectorAll('[data-sp-all]').forEach((b) => b.addEventListener('click', () => {
    rows.filter((r) => !r.hidden).forEach((r) => { r.querySelector('input[type=checkbox]').checked = b.dataset.spAll === '1'; });
  }));
});
</script>
