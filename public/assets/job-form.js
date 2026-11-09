/* Job form: headers repeater, body editor, variable insertion and test run. */
(function () {
  'use strict';
  const form = document.getElementById('job-form');
  if (!form) return;
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  // ---- Headers ----
  const list = form.querySelector('[data-headers]');
  const tpl = document.getElementById('header-row-tpl');
  form.querySelector('[data-add-header]')?.addEventListener('click', () => {
    list.appendChild(tpl.content.cloneNode(true));
    list.lastElementChild.querySelector('input').focus();
  });
  list?.addEventListener('click', (e) => {
    const b = e.target.closest('[data-remove-row]');
    if (b) b.closest('.header-row').remove();
  });
  list?.addEventListener('change', (e) => {
    if (!e.target.matches('[data-secret]')) return;
    const row = e.target.closest('.header-row');
    row.querySelector('input[name="h_secret[]"]').value = e.target.checked ? '1' : '0';
    row.querySelector('input[name="h_value[]"]').type = e.target.checked ? 'password' : 'text';
  });

  // ---- Body ----
  const typeInput = form.querySelector('input[name="body_type"]');
  const body = form.querySelector('textarea[name="body"]');
  const method = form.querySelector('[data-method]');
  const CT = { json: 'application/json', form: 'application/x-www-form-urlencoded', text: 'text/plain; charset=utf-8', xml: 'application/xml' };
  function renderBody() {
    const t = typeInput.value;
    form.querySelectorAll('[data-body-type]').forEach((b) => b.setAttribute('aria-pressed', b.dataset.bodyType === t ? 'true' : 'false'));
    form.querySelector('[data-body-none]').classList.toggle('hidden', t !== 'none');
    form.querySelector('[data-body-area]').classList.toggle('hidden', t === 'none');
    form.querySelector('[data-format-json]').classList.toggle('hidden', t !== 'json');
    form.querySelector('[data-form-hint]').classList.toggle('hidden', t !== 'form');
    form.querySelector('[data-body-ct]').textContent = CT[t] ? 'Content-Type: ' + CT[t] + ' (αυτόματα, αν δεν οριστεί header)' : '';
    form.querySelector('[data-body-get-warning]').classList.toggle('hidden', !(t !== 'none' && ['GET', 'HEAD'].includes(method.value)));
    validate();
  }
  function validate() {
    const badge = form.querySelector('[data-body-valid]');
    const t = typeInput.value;
    let ok = true;
    let label = body.value.length + ' χαρακτήρες';
    if (t === 'json' && body.value.trim() !== '') {
      // Template variables are replaced by sample values before checking.
      const sample = body.value.replace(/\{\{[^}]*\}\}/g, '0').replace(/\{%[^%]*%\}/g, '');
      try { JSON.parse(sample); label = '✓ Έγκυρο JSON'; } catch (e) { ok = false; label = 'Μη έγκυρο JSON: ' + e.message; }
    }
    badge.textContent = label;
    badge.className = 'badge ' + (ok ? 'ok' : 'fail');
  }
  form.querySelectorAll('[data-body-type]').forEach((b) => b.addEventListener('click', () => { typeInput.value = b.dataset.bodyType; renderBody(); }));
  method?.addEventListener('change', renderBody);
  body?.addEventListener('input', validate);
  form.querySelector('[data-format-json]')?.addEventListener('click', () => {
    try { body.value = JSON.stringify(JSON.parse(body.value), null, 2); validate(); } catch (e) { window.toast('Το JSON περιέχει μεταβλητές ή σφάλματα και δεν μορφοποιήθηκε.', 'error'); }
  });
  let lastFocus = body;
  form.addEventListener('focusin', (e) => { if (e.target.matches('textarea[name="body"], input[name="url"], input[name="h_value[]"]')) lastFocus = e.target; });
  form.querySelectorAll('[data-insert-var]').forEach((b) => b.addEventListener('click', () => {
    const el = lastFocus || body;
    const txt = '{{ ' + b.dataset.insertVar + ' }}';
    const st = el.selectionStart ?? el.value.length;
    const en = el.selectionEnd ?? el.value.length;
    el.value = el.value.slice(0, st) + txt + el.value.slice(en);
    el.focus();
    el.setSelectionRange(st + txt.length, st + txt.length);
    validate();
  }));
  renderBody();

  // ---- Push recipients ----
  const pt = form.querySelector('[data-push-targets]');
  if (pt) {
    const sync = () => {
      const selected = pt.querySelector('input[name="push_target"]:checked')?.value === 'selected';
      pt.querySelector('[data-push-picker]').classList.toggle('hidden', !selected);
      pt.querySelector('[data-push-all-help]').classList.toggle('hidden', selected);
    };
    pt.addEventListener('change', (e) => {
      if (e.target.matches('[data-push-user]')) {
        // "All devices" of a user: tick and lock that user's individual devices.
        let el = e.target.closest('.stack-sm').querySelectorAll('[data-push-device]');
        el.forEach((d) => { d.checked = e.target.checked || d.checked; d.disabled = e.target.checked; });
      }
      sync();
    });
    sync();
  }

  // ---- Test run ----
  const card = document.getElementById('test-run');
  const result = card?.querySelector('[data-test-result]');
  document.querySelectorAll('[data-test-run]').forEach((btn) => btn.addEventListener('click', async () => {
    if (!card) return;
    card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    card.querySelector('[data-test-idle]').classList.add('hidden');
    result.classList.remove('hidden');
    result.innerHTML = '<div class="row small"><span class="badge run"><span class="dot"></span>Εκτελείται</span>Αποστολή request…</div>';
    document.querySelectorAll('[data-test-run]').forEach((b) => b.classList.add('loading'));
    try {
      const fd = new FormData(form);
      const res = await fetch(window.APP.base.replace(/\/$/, '') + '/jobs/test', { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-Requested-With': 'fetch', 'X-CSRF-Token': window.APP.csrf }, credentials: 'same-origin' });
      const d = await res.json();
      if (d.error && d.status === undefined) {
        result.innerHTML = `<p class="flash error">${esc(d.error)}</p>`;
      } else {
        const badge = d.ok ? `<span class="badge ok">${esc(d.status)} OK</span>` : `<span class="badge fail">${d.status ? esc(d.status) : 'Σφάλμα'}</span>`;
        result.innerHTML = `
          <div class="row">${badge}<span class="small muted">${esc(d.duration)} · ${esc(d.size)}</span></div>
          ${d.ok ? '' : `<p class="small" style="color:var(--fail-fg)">${esc(d.reason || '')}</p>`}
          <p class="xs muted mono" style="word-break:break-all">${esc(d.request.method)} ${esc(d.request.url)}</p>
          ${d.body ? `<pre class="code" style="max-height:320px">${esc(d.body)}</pre>` : '<p class="small muted">Κενό response.</p>'}
          ${d.headers ? `<details><summary class="small">Response headers</summary><pre class="code" style="margin-top:8px">${esc(d.headers)}</pre></details>` : ''}`;
      }
    } catch (e) {
      result.innerHTML = '<p class="flash error">Αποτυχία επικοινωνίας με τον server.</p>';
    } finally {
      document.querySelectorAll('[data-test-run]').forEach((b) => b.classList.remove('loading'));
    }
  }));
})();
