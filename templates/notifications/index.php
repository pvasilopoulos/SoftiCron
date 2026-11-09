<?php
/** @var array $devices @var array $events @var string $vapidKey @var bool $pushConfigured */
use App\Auth\Auth;
use App\Services\Notifier;

$tz = Auth::tenant()['timezone'];
?>
<div class="page-head">
  <div><h1>Ειδοποιήσεις</h1><p class="sub">Push ειδοποιήσεις στο κινητό και στον υπολογιστή σου, ακόμα και με την εφαρμογή κλειστή</p></div>
</div>
<?php if (Auth::can('notifications.history')): ?>
  <nav class="tabs" aria-label="Ειδοποιήσεις">
    <a class="on" href="<?= e(url('notifications')) ?>" aria-current="page">Συσκευές & προτιμήσεις</a>
    <a href="<?= e(url('notifications/history')) ?>">Ιστορικό</a>
  </nav>
<?php endif; ?>

<div class="split">
  <div class="stack" style="min-width:0">
    <section class="card stack" id="push-card" data-vapid="<?= e($vapidKey) ?>">
      <div class="row between"><h2>Αυτή η συσκευή</h2><span class="badge off" data-push-state>Έλεγχος…</span></div>
      <?php if (!$pushConfigured): ?>
        <p class="notice">Ο διαχειριστής της πλατφόρμας δεν έχει ρυθμίσει ακόμα τα κλειδιά VAPID για Web Push.</p>
      <?php endif; ?>
      <p class="small muted" data-push-help>Πάτα «Ενεργοποίηση» και επίτρεψε τις ειδοποιήσεις όταν σε ρωτήσει ο browser.</p>
      <div class="notice hidden" data-ios-help>
        <strong>iPhone / iPad:</strong> Οι ειδοποιήσεις λειτουργούν μόνο όταν η εφαρμογή είναι εγκατεστημένη στην αρχική οθόνη (iOS 16.4+).
        Στο Safari πάτα <strong>Κοινοποίηση → Προσθήκη στην αρχική οθόνη</strong>, άνοιξε την εφαρμογή από το εικονίδιο και έλα ξανά εδώ.
      </div>
      <div class="row">
        <button class="btn btn-primary" type="button" data-push-enable <?= $pushConfigured ? '' : 'disabled' ?>><?= icon('bell', 16) ?>Ενεργοποίηση</button>
        <button class="btn hidden" type="button" data-push-disable>Απενεργοποίηση</button>
        <form method="post" action="<?= e(url('notifications/test')) ?>" class="inline"><?= csrf_field() ?><button class="btn" type="submit" <?= $devices ? '' : 'disabled' ?>><?= icon('send', 16) ?>Δοκιμαστική ειδοποίηση</button></form>
      </div>
    </section>

    <?php
    $staleCut = time() - 30 * 86400;
    $devState = function (array $d) use ($staleCut): string {
        if (!$d['enabled']) {
            return 'disabled';
        }
        if ((int) ($d['fail_count'] ?? 0) > 0) {
            return 'failing';
        }
        return strtotime(($d['last_seen_at'] ?: $d['created_at']) . ' UTC') < $staleCut ? 'stale' : 'active';
    };
    $stateMeta = ['active' => ['ok', 'Ενεργή'], 'failing' => ['fail', 'Σφάλματα'], 'stale' => ['slow', 'Αδρανής'], 'disabled' => ['off', 'Ανενεργή']];
    $devCounts = ['all' => count($devices), 'active' => 0, 'failing' => 0, 'stale' => 0, 'disabled' => 0];
    foreach ($devices as $d) {
        $devCounts[$devState($d)]++;
    }
    $isMobile = fn (array $d) => (bool) preg_match('/iPhone|iPad|Android/', ($d['platform'] ?? '') . ' ' . ($d['user_agent'] ?? ''));
    ?>
    <section class="card stack" id="my-devices">
      <div class="row between" style="align-items:flex-start">
        <div>
          <h2>Οι συσκευές μου</h2>
          <p class="small muted" style="margin-top:4px">
            <?php if ($devices): ?><?= $devCounts['active'] ?> από <?= count($devices) ?> λαμβάνουν κανονικά ειδοποιήσεις<?php if ($devCounts['failing']): ?> · <span style="color:var(--fail-fg)"><?= $devCounts['failing'] ?> με σφάλματα</span><?php endif; ?>
            <?php else: ?>Οι συσκευές όπου έχεις ενεργοποιήσει τις ειδοποιήσεις push<?php endif; ?>
          </p>
        </div>
        <?php if ($devices): ?>
          <div class="row" style="gap:6px">
            <button class="btn btn-sm" type="button" data-dev-test-all <?= $devCounts['all'] - $devCounts['disabled'] ? '' : 'disabled' ?>><?= icon('send', 14) ?>Δοκιμή σε όλες</button>
            <details class="feed-more">
              <summary class="btn btn-sm btn-icon" aria-label="Καθαρισμός συσκευών" title="Καθαρισμός">⋯</summary>
              <div class="feed-menu">
                <button type="button" data-dev-cleanup="disabled"<?= $devCounts['disabled'] ? '' : ' disabled' ?>><?= icon('trash', 14) ?>Αφαίρεση ανενεργών (<?= $devCounts['disabled'] ?>)</button>
                <button type="button" data-dev-cleanup="failing"<?= $devCounts['failing'] ? '' : ' disabled' ?>><?= icon('trash', 14) ?>Αφαίρεση με σφάλματα (<?= $devCounts['failing'] ?>)</button>
                <button type="button" data-dev-cleanup="stale"<?= $devCounts['stale'] ? '' : ' disabled' ?>><?= icon('trash', 14) ?>Αφαίρεση αδρανών 30+ ημερών (<?= $devCounts['stale'] ?>)</button>
              </div>
            </details>
          </div>
        <?php endif; ?>
      </div>

      <?php if (!$devices): ?>
        <div class="dev-empty">
          <div class="dev-empty-ic"><?= icon('bell', 28) ?></div>
          <div class="stack-sm" style="gap:4px;text-align:center;max-width:440px">
            <strong>Δεν έχεις ενεργοποιήσει ειδοποιήσεις σε καμία συσκευή</strong>
            <span class="small muted">Ενεργοποίησέ τες εδώ ή στο κινητό σου, για να μαθαίνεις αμέσως όταν ένα job αποτυγχάνει, ακόμα και με την εφαρμογή κλειστή.</span>
          </div>
          <button class="btn btn-primary" type="button" data-dev-enable-here <?= $pushConfigured ? '' : 'disabled' ?>><?= icon('bell', 16) ?>Ενεργοποίηση σε αυτή τη συσκευή</button>
          <div class="dev-steps">
            <div><span class="dev-step-n">1</span><span><strong>Android ή υπολογιστής:</strong> άνοιξε αυτή τη σελίδα στη συσκευή και πάτα «Ενεργοποίηση».</span></div>
            <div><span class="dev-step-n">2</span><span><strong>iPhone ή iPad:</strong> στο Safari πάτα Κοινοποίηση → «Προσθήκη στην αρχική οθόνη», άνοιξε την εφαρμογή από το εικονίδιο και ενεργοποίησε από εδώ.</span></div>
            <div><span class="dev-step-n">3</span><span class="grow stack-sm" style="gap:4px"><strong>Σύνδεσμος για το κινητό:</strong>
              <span class="row" style="gap:6px;flex-wrap:nowrap"><input class="input mono" value="<?= e(abs_url('notifications')) ?>" readonly onclick="this.select()" aria-label="Σύνδεσμος για το κινητό" style="min-height:32px;font-size:12px"><button class="btn btn-sm" type="button" data-copy="<?= e(abs_url('notifications')) ?>"><?= icon('copy', 14) ?>Αντιγραφή</button></span></span></div>
          </div>
        </div>
      <?php else: ?>
        <div class="dev-toolbar">
          <div class="feed-tabs" role="tablist" aria-label="Φίλτρο συσκευών" style="padding:0">
            <?php foreach (['all' => 'Όλες', 'active' => 'Ενεργές', 'failing' => 'Με σφάλματα', 'stale' => 'Αδρανείς', 'disabled' => 'Ανενεργές'] as $k => $l): if ($k !== 'all' && !$devCounts[$k]) { continue; } ?>
              <button type="button" role="tab" aria-selected="<?= $k === 'all' ? 'true' : 'false' ?>" data-dev-filter="<?= $k ?>"><?= e($l) ?> <span class="dev-n"><?= $devCounts[$k] ?></span></button>
            <?php endforeach; ?>
          </div>
          <div class="row" style="gap:6px;flex-wrap:nowrap">
            <?php if (count($devices) > 3): ?><div class="feed-search" style="margin:0;flex:1"><?= icon('search', 14) ?><input type="search" data-dev-q placeholder="Αναζήτηση…" aria-label="Αναζήτηση συσκευών"></div><?php endif; ?>
            <label class="sr-only" for="dev-sort">Ταξινόμηση</label>
            <select id="dev-sort" class="select" data-dev-sort style="width:auto;min-height:34px;font-size:13px">
              <option value="seen">Τελευταία χρήση</option>
              <option value="name">Όνομα</option>
              <option value="created">Ημερομηνία εγγραφής</option>
            </select>
          </div>
        </div>

        <div class="dev-grid" data-dev-grid>
          <?php foreach ($devices as $d): $st = $devState($d); [$cls, $lbl] = $stateMeta[$st]; ?>
            <article class="dev-card st-<?= $st ?>" data-dev-id="<?= (int) $d['id'] ?>" data-state="<?= $st ?>"
              data-device-endpoint="<?= e(hash('sha256', $d['endpoint'])) ?>"
              data-name="<?= e(mb_strtolower(($d['label'] ?? '') . ' ' . $d['user_agent'] . ' ' . ($d['platform'] ?? '') . ' ' . ($d['browser'] ?? ''))) ?>"
              data-seen="<?= strtotime(($d['last_seen_at'] ?: $d['created_at']) . ' UTC') ?>" data-created="<?= strtotime($d['created_at'] . ' UTC') ?>">
              <div class="dev-head">
                <span class="dev-ic" aria-hidden="true">
                  <?php if ($isMobile($d)): ?><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="2" width="12" height="20" rx="2.5"/><path d="M11 18h2"/></svg>
                  <?php else: ?><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/></svg><?php endif; ?>
                </span>
                <div class="grow" style="min-width:0">
                  <label class="sr-only" for="dev-<?= (int) $d['id'] ?>">Όνομα συσκευής</label>
                  <input id="dev-<?= (int) $d['id'] ?>" class="dev-name" data-dev-label value="<?= e($d['label'] ?? '') ?>" placeholder="<?= e($d['user_agent'] ?: 'Συσκευή') ?> · δώσε όνομα" maxlength="100" title="Κλικ για μετονομασία">
                  <div class="row" style="gap:6px;margin-top:2px">
                    <span class="badge <?= $cls ?>" data-dev-badge><?= e($lbl) ?></span>
                    <span class="badge run this-device hidden">Αυτή η συσκευή</span>
                    <span class="xs" data-dev-saved hidden style="color:var(--ok-fg)">✓ αποθηκεύτηκε</span>
                  </div>
                </div>
                <label class="switch" title="Λήψη ειδοποιήσεων σε αυτή τη συσκευή"><input type="checkbox" data-dev-enabled <?= $d['enabled'] ? 'checked' : '' ?> aria-label="Λήψη ειδοποιήσεων"><span></span></label>
              </div>
              <div class="stack-sm" style="gap:6px"><?= App\Core\View::partial('partials/device_meta', ['d' => $d, 'tz' => $tz]) ?></div>
              <div class="dev-actions">
                <button class="btn btn-sm" type="button" data-dev-test <?= $d['enabled'] ? '' : 'disabled' ?>><?= icon('send', 14) ?>Δοκιμή</button>
                <span class="grow"></span>
                <button class="btn btn-sm btn-ghost" type="button" data-dev-delete style="color:var(--fail-fg)"><?= icon('trash', 14) ?>Αφαίρεση</button>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
        <p class="small muted hidden" data-dev-none style="text-align:center;padding:12px">Καμία συσκευή με αυτό το φίλτρο.</p>
        <p class="xs muted">Δώσε όνομα σε κάθε συσκευή (π.χ. «Κινητό», «Laptop γραφείου») για να την αναγνωρίζουν οι διαχειριστές όταν επιλέγουν παραλήπτες σε ένα job. Οι αλλαγές αποθηκεύονται αυτόματα.</p>
      <?php endif; ?>
    </section>
  </div>

  <aside>
    <form method="post" action="<?= e(url('notifications/prefs')) ?>" class="card stack">
      <?= csrf_field() ?>
      <div><h2>Τι θέλω να λαμβάνω</h2><p class="small muted" style="margin-top:4px">Ισχύει για τα push σου σε αυτόν τον οργανισμό, για όσα jobs έχουν ενεργό το κανάλι Push.</p></div>
      <?php foreach (Notifier::EVENTS as $k => $l): ?>
        <label class="check"><input type="checkbox" name="events[]" value="<?= $k ?>" <?= in_array($k, $events, true) ? 'checked' : '' ?>><?= e($l) ?></label>
      <?php endforeach; ?>
      <button class="btn btn-primary" type="submit">Αποθήκευση</button>
    </form>
  </aside>
</div>

<script>
document.addEventListener('DOMContentLoaded', async () => {
  const card = document.getElementById('push-card');
  const state = card.querySelector('[data-push-state]');
  const en = card.querySelector('[data-push-enable]');
  const dis = card.querySelector('[data-push-disable]');
  const isIos = /iPhone|iPad|iPod/.test(navigator.userAgent);
  const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone;
  const set = (txt, cls) => { state.textContent = txt; state.className = 'badge ' + cls; };
  const b64 = (s) => { const p = '='.repeat((4 - s.length % 4) % 4); const raw = atob((s + p).replace(/-/g, '+').replace(/_/g, '/')); return Uint8Array.from([...raw].map((c) => c.charCodeAt(0))); };
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
    set('Δεν υποστηρίζεται', 'off'); en.disabled = true;
    if (isIos && !standalone) card.querySelector('[data-ios-help]').classList.remove('hidden');
    return;
  }
  if (isIos && !standalone) card.querySelector('[data-ios-help]').classList.remove('hidden');
  const reg = await navigator.serviceWorker.register(window.APP.base.replace(/\/$/, '') + '/sw.js', { scope: window.APP.base });
  await navigator.serviceWorker.ready;
  const refresh = async () => {
    const sub = await reg.pushManager.getSubscription();
    if (Notification.permission === 'denied') { set('Μπλοκαρισμένες', 'fail'); en.disabled = true; card.querySelector('[data-push-help]').textContent = 'Οι ειδοποιήσεις έχουν μπλοκαριστεί στις ρυθμίσεις του browser για αυτόν τον ιστότοπο.'; }
    else if (sub) { set('Ενεργές', 'ok'); en.classList.add('hidden'); dis.classList.remove('hidden'); }
    else { set('Ανενεργές', 'off'); en.classList.remove('hidden'); dis.classList.add('hidden'); }
    return sub;
  };
  let sub = await refresh();
  if (sub) {
    try { await window.api('/notifications/subscribe', { method: 'POST', json: Object.assign(sub.toJSON(), { meta: window.deviceMeta() }) }); } catch (e) {}
    // Mark "this device" in the list.
    try {
      const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(sub.endpoint));
      const hex = [...new Uint8Array(buf)].map((b) => b.toString(16).padStart(2, '0')).join('');
      document.querySelector('[data-device-endpoint="' + hex + '"] .this-device')?.classList.remove('hidden');
    } catch (e) {}
  }
  en.addEventListener('click', async () => {
    try {
      const perm = await Notification.requestPermission();
      if (perm !== 'granted') { await refresh(); return; }
      sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64(card.dataset.vapid) });
      await window.api('/notifications/subscribe', { method: 'POST', json: Object.assign(sub.toJSON(), { meta: window.deviceMeta() }) });
      window.toast('Οι ειδοποιήσεις ενεργοποιήθηκαν σε αυτή τη συσκευή.');
      setTimeout(() => location.reload(), 800);
    } catch (e) { window.toast('Αποτυχία: ' + e.message, 'error'); }
  });
  dis.addEventListener('click', async () => {
    const s = await reg.pushManager.getSubscription();
    if (s) { await window.api('/notifications/unsubscribe', { method: 'POST', json: { endpoint: s.endpoint } }).catch(() => {}); await s.unsubscribe(); }
    window.toast('Οι ειδοποιήσεις απενεργοποιήθηκαν.');
    setTimeout(() => location.reload(), 800);
  });
});
</script>
<script>
/* "Οι συσκευές μου": filters, sorting, autosave, test, remove, cleanup. */
document.addEventListener('DOMContentLoaded', () => {
  const box = document.getElementById('my-devices');
  if (!box) return;
  const enableHere = box.querySelector('[data-dev-enable-here]');
  if (enableHere) enableHere.addEventListener('click', () => document.querySelector('[data-push-enable]')?.click());
  const grid = box.querySelector('[data-dev-grid]');
  if (!grid) return;
  const cards = () => [...grid.querySelectorAll('.dev-card')];
  const q = box.querySelector('[data-dev-q]');
  const sort = box.querySelector('[data-dev-sort]');
  let filter = 'all';
  const apply = () => {
    const term = (q?.value || '').trim().toLowerCase();
    let shown = 0;
    cards().forEach((c) => {
      const ok = (filter === 'all' || c.dataset.state === filter) && (!term || c.dataset.name.includes(term));
      c.hidden = !ok;
      shown += ok ? 1 : 0;
    });
    box.querySelector('[data-dev-none]').classList.toggle('hidden', shown > 0);
    const key = sort.value;
    cards().sort((a, b) => key === 'name'
      ? (a.querySelector('[data-dev-label]').value || a.dataset.name).localeCompare(b.querySelector('[data-dev-label]').value || b.dataset.name, 'el')
      : (+b.dataset[key === 'created' ? 'created' : 'seen']) - (+a.dataset[key === 'created' ? 'created' : 'seen'])).forEach((c) => grid.appendChild(c));
    try { localStorage.setItem('devSort', key); } catch (e) { /* ignore */ }
  };
  try { const s = localStorage.getItem('devSort'); if (s) sort.value = s; } catch (e) { /* ignore */ }
  box.querySelectorAll('[data-dev-filter]').forEach((b) => b.addEventListener('click', () => {
    filter = b.dataset.devFilter;
    box.querySelectorAll('[data-dev-filter]').forEach((o) => o.setAttribute('aria-selected', o === b ? 'true' : 'false'));
    apply();
  }));
  q?.addEventListener('input', apply);
  sort.addEventListener('change', apply);
  apply();

  // Autosave name and on/off switch.
  const save = async (card) => {
    const label = card.querySelector('[data-dev-label]');
    const on = card.querySelector('[data-dev-enabled]').checked;
    const fd = new FormData();
    fd.append('label', label.value.trim());
    if (on) fd.append('enabled', '1');
    try {
      await window.api('/notifications/devices/' + card.dataset.devId, { method: 'POST', body: fd });
      const s = card.querySelector('[data-dev-saved]');
      s.hidden = false; clearTimeout(s._t); s._t = setTimeout(() => { s.hidden = true; }, 1800);
      const badge = card.querySelector('[data-dev-badge]');
      if (!on) { card.dataset.state = 'disabled'; badge.className = 'badge off'; badge.textContent = 'Ανενεργή'; }
      else if (card.dataset.state === 'disabled') { card.dataset.state = 'active'; badge.className = 'badge ok'; badge.textContent = 'Ενεργή'; }
      card.className = 'dev-card st-' + card.dataset.state;
      card.querySelector('[data-dev-test]').disabled = !on;
      card.dataset.name = (label.value + ' ' + card.dataset.name).toLowerCase();
    } catch (e) { window.toast('Δεν αποθηκεύτηκε: ' + e.message, 'error'); }
  };
  cards().forEach((card) => {
    const label = card.querySelector('[data-dev-label]');
    let last = label.value;
    const commit = () => { if (label.value.trim() !== last.trim()) { last = label.value; save(card); } };
    label.addEventListener('blur', commit);
    label.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); label.blur(); }
      if (e.key === 'Escape') { label.value = last; label.blur(); }
    });
    card.querySelector('[data-dev-enabled]').addEventListener('change', () => save(card));
    card.querySelector('[data-dev-test]').addEventListener('click', async (e) => {
      const btn = e.currentTarget; btn.disabled = true;
      try { const d = await window.api('/notifications/devices/' + card.dataset.devId + '/test', { method: 'POST' }); window.toast(d.message); }
      catch (err) { window.toast(err.message, 'error'); }
      btn.disabled = false;
    });
    card.querySelector('[data-dev-delete]').addEventListener('click', async () => {
      const name = label.value || label.placeholder.replace(' · δώσε όνομα', '');
      if (!confirm('Να αφαιρεθεί η συσκευή «' + name + '»; Θα σταματήσει να λαμβάνει ειδοποιήσεις μέχρι να τις ενεργοποιήσεις ξανά σε αυτήν.')) return;
      try {
        await window.api('/notifications/devices/' + card.dataset.devId + '/delete', { method: 'POST' });
        card.style.opacity = '.3';
        window.toast('Η συσκευή αφαιρέθηκε.');
        setTimeout(() => location.reload(), 600);
      } catch (err) { window.toast(err.message, 'error'); }
    });
  });

  box.querySelector('[data-dev-test-all]')?.addEventListener('click', async (e) => {
    const btn = e.currentTarget; btn.disabled = true;
    try { const d = await window.api('/notifications/test', { method: 'POST' }); window.toast(d.message); }
    catch (err) { window.toast(err.message, 'error'); }
    btn.disabled = false;
  });
  box.querySelectorAll('[data-dev-cleanup]').forEach((b) => b.addEventListener('click', async () => {
    if (!confirm(b.textContent.trim() + ';')) return;
    try {
      const d = await window.api('/notifications/devices/cleanup', { method: 'POST', json: { what: b.dataset.devCleanup } });
      window.toast(d.message);
      setTimeout(() => location.reload(), 600);
    } catch (err) { window.toast(err.message, 'error'); }
  }));
});
</script>
