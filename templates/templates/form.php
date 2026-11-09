<?php
/** @var array $tpl */
use App\Services\Notifier;
use App\Services\TemplateRenderer;

$isNew = $tpl['id'] === null;
$action = $isNew ? url('templates') : url('templates/' . $tpl['id']);
$val = fn (string $k) => old($k, $tpl[$k] ?? '');
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="<?= e(url('templates')) ?>">Templates</a><span aria-hidden="true">/</span><span><?= $isNew ? 'Νέο' : e($tpl['name']) ?></span></div>
    <h1><?= $isNew ? 'Νέο template' : e($tpl['name']) ?></h1>
  </div>
</div>
<div class="split">
  <form method="post" action="<?= e($action) ?>" class="card stack" id="tpl-form">
    <?= csrf_field() ?>
    <label class="field">Όνομα
      <input class="input" name="name" value="<?= e($val('name')) ?>" required maxlength="120">
    </label>
    <div class="form-grid">
      <label class="field">Γεγονός
        <select class="select" name="event"><?php foreach (Notifier::EVENTS as $k => $l): ?><option value="<?= $k ?>" <?= $val('event') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
      </label>
      <label class="field">Κανάλι
        <select class="select" name="channel" data-channel><?php foreach (Notifier::TEMPLATE_CHANNELS as $k => $l): ?><option value="<?= $k ?>" <?= $val('channel') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
      </label>
    </div>
    <label class="field" data-subject>Θέμα (email) / Τίτλος (push)
      <input class="input mono" name="subject" value="<?= e($val('subject')) ?>" maxlength="255">
    </label>
    <label class="field">Κείμενο
      <textarea class="textarea mono" name="body" rows="10" required spellcheck="false"><?= e($val('body')) ?></textarea>
      <span class="help hidden" data-sms-count style="color:var(--accent-text)"></span>
      <span class="help">Telegram: χρησιμοποίησε **κείμενο** για έντονα. Υποστηρίζονται <code>{% if %}</code>, <code>{% for %}</code> και φίλτρα όπως <code>|upper</code>, <code>|default('—')</code>.</span>
    </label>
    <label class="check"><input type="checkbox" name="is_default" value="1" <?= $val('is_default') ? 'checked' : '' ?>>Προεπιλογή για αυτό το γεγονός και κανάλι</label>
    <div class="form-actions">
      <?php if (!$isNew): ?><button class="btn btn-danger" type="submit" form="tpl-del"><?= icon('trash', 16) ?>Διαγραφή</button><?php endif; ?>
      <span class="grow"></span>
      <button class="btn" type="button" data-preview><?= icon('play', 16) ?>Προεπισκόπηση</button>
      <button class="btn btn-primary" type="submit">Αποθήκευση</button>
    </div>
  </form>
  <aside class="stack">
    <section class="card stack-sm">
      <h2>Μεταβλητές</h2>
      <div class="stack-sm" style="gap:4px">
        <?php foreach (TemplateRenderer::VARIABLES as $k => $l): ?>
          <button type="button" class="row between var-chip" style="border-radius:8px;justify-content:space-between;width:100%" data-insert="{{ <?= e($k) ?> }}"><span><?= e($k) ?></span><span class="xs muted" style="font-family:var(--font)"><?= e($l) ?></span></button>
        <?php endforeach; ?>
      </div>
    </section>
    <section class="card stack-sm">
      <h2>Προεπισκόπηση</h2>
      <p class="small muted" data-preview-empty>Πάτα «Προεπισκόπηση» για να δεις το μήνυμα με δείγμα δεδομένων.</p>
      <div data-preview-out class="hidden stack-sm"></div>
    </section>
  </aside>
</div>
<?php if (!$isNew): ?>
  <form id="tpl-del" method="post" action="<?= e(url('templates/' . $tpl['id'] . '/delete')) ?>" class="hidden" data-confirm="Να διαγραφεί το template;"><?= csrf_field() ?></form>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const f = document.getElementById('tpl-form');
  const body = f.querySelector('[name=body]');
  const subj = f.querySelector('[name=subject]');
  const ch = f.querySelector('[data-channel]');
  let last = body;
  [body, subj].forEach((el) => el.addEventListener('focus', () => { last = el; }));
  const smsCount = f.querySelector('[data-sms-count]');
  const count = () => {
    const len = body.value.replace(/\{\{[^}]*\}\}/g, 'xxxxxxxxxx').length;
    smsCount.textContent = 'Περίπου ' + len + ' χαρακτήρες · έως 160 = 1 SMS (με μετατροπή ελληνικών) · Viber έως 1000';
  };
  const sync = () => {
    f.querySelector('[data-subject]').classList.toggle('hidden', ch.value === 'telegram' || ch.value === 'sms');
    smsCount.classList.toggle('hidden', ch.value !== 'sms');
    count();
  };
  body.addEventListener('input', count);
  ch.addEventListener('change', sync); sync();
  document.querySelectorAll('[data-insert]').forEach((b) => b.addEventListener('click', () => {
    const t = b.dataset.insert, s = last.selectionStart ?? last.value.length, en = last.selectionEnd ?? s;
    last.value = last.value.slice(0, s) + t + last.value.slice(en); last.focus(); last.setSelectionRange(s + t.length, s + t.length);
  }));
  const out = document.querySelector('[data-preview-out]');
  f.querySelector('[data-preview]').addEventListener('click', async () => {
    try {
      const d = await window.api('/templates/preview', { method: 'POST', json: { subject: subj.value, body: body.value, channel: ch.value, event: f.querySelector('[name=event]').value } });
      document.querySelector('[data-preview-empty]').classList.add('hidden');
      out.classList.remove('hidden');
      if (!d.ok) { out.innerHTML = ''; const p = document.createElement('p'); p.className = 'flash error'; p.textContent = d.error; out.appendChild(p); return; }
      out.innerHTML = '';
      if (ch.value === 'email') {
        const fr = document.createElement('iframe'); fr.setAttribute('sandbox', ''); fr.style.cssText = 'width:100%;height:460px;border:1px solid var(--border);border-radius:10px;background:#fff'; fr.srcdoc = d.html; out.appendChild(fr);
      } else if (ch.value === 'telegram') {
        const box = document.createElement('div'); box.style.cssText = 'padding:12px 14px;border-radius:12px;background:#E7F3FF;color:#0F172A;font-size:14px;line-height:1.5'; box.innerHTML = d.html; out.appendChild(box);
      } else {
        const box = document.createElement('div'); box.className = 'alert-item';
        const t = document.createElement('strong'); t.className = 'small'; t.textContent = d.subject;
        const b2 = document.createElement('div'); b2.className = 'small muted'; b2.textContent = d.body;
        const wrap = document.createElement('div'); wrap.className = 'stack-sm'; wrap.style.gap = '2px'; wrap.append(t, b2); box.appendChild(wrap); out.appendChild(box);
      }
    } catch (e) { window.toast(e.message, 'error'); }
  });
});
</script>
