<?php
/** @var array $devices @var array $events @var string $vapidKey @var bool $pushConfigured */
use App\Auth\Auth;
use App\Services\Notifier;

$tz = Auth::tenant()['timezone'];
?>
<div class="page-head">
  <div><h1>Ειδοποιήσεις</h1><p class="sub">Push ειδοποιήσεις στο κινητό και στον υπολογιστή σου, ακόμα και με την εφαρμογή κλειστή</p></div>
</div>
<?php if (Auth::can('runs.view')): ?>
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

    <section class="card">
      <h2 style="margin-bottom:8px">Οι συσκευές μου</h2>
      <?php if (!$devices): ?><p class="small muted">Δεν έχεις ενεργοποιήσει ειδοποιήσεις σε καμία συσκευή.</p><?php endif; ?>
      <?php foreach ($devices as $d): ?>
        <div class="list-item" style="flex-wrap:wrap" data-device-endpoint="<?= e(hash('sha256', $d['endpoint'])) ?>">
          <span class="avatar"><?= icon(str_contains((string) $d['user_agent'], 'iPhone') || str_contains((string) $d['user_agent'], 'Android') ? 'bell' : 'dashboard', 16) ?></span>
          <form method="post" action="<?= e(url('notifications/devices/' . $d['id'])) ?>" class="grow row" style="gap:8px;min-width:220px">
            <?= csrf_field() ?>
            <div class="stack-sm grow" style="gap:2px;min-width:160px">
              <label class="sr-only" for="dev-<?= (int) $d['id'] ?>">Όνομα συσκευής</label>
              <input id="dev-<?= (int) $d['id'] ?>" class="input" name="label" value="<?= e($d['label'] ?? '') ?>" placeholder="<?= e($d['user_agent'] ?: 'Συσκευή') ?>" maxlength="100" style="min-height:36px">
              <span class="xs muted"><?= e($d['user_agent'] ?: 'Συσκευή') ?> · από <?= e(fmt_dt($d['created_at'], $tz, 'd/m/Y')) ?> · τελευταία αποστολή <?= e($d['last_success_at'] ? rel_time($d['last_success_at']) : 'ποτέ') ?><span class="this-device hidden"> · <strong>αυτή η συσκευή</strong></span></span>
            </div>
            <label class="check xs" title="Λήψη ειδοποιήσεων"><span class="switch"><input type="checkbox" name="enabled" value="1" <?= $d['enabled'] ? 'checked' : '' ?>><span></span></span>Ενεργή</label>
            <button class="btn btn-sm" type="submit">Αποθήκευση</button>
          </form>
          <form method="post" action="<?= e(url('notifications/devices/' . $d['id'] . '/test')) ?>" class="inline"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit" title="Δοκιμή σε αυτή τη συσκευή"><?= icon('send', 14) ?>Δοκιμή</button></form>
          <form method="post" action="<?= e(url('notifications/devices/' . $d['id'] . '/delete')) ?>" data-confirm="Να αφαιρεθεί η συσκευή;"><?= csrf_field() ?><button class="btn btn-ghost btn-icon btn-sm" type="submit" aria-label="Αφαίρεση συσκευής"><?= icon('trash', 16) ?></button></form>
        </div>
      <?php endforeach; ?>
      <p class="xs muted" style="margin-top:8px">Δώσε όνομα σε κάθε συσκευή (π.χ. «Κινητό», «Laptop γραφείου»), για να την αναγνωρίζουν οι διαχειριστές όταν επιλέγουν παραλήπτες σε ένα job. Μια ανενεργή συσκευή δεν λαμβάνει καμία ειδοποίηση.</p>
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
    try { await window.api('/notifications/subscribe', { method: 'POST', json: sub.toJSON() }); } catch (e) {}
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
      await window.api('/notifications/subscribe', { method: 'POST', json: sub.toJSON() });
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
