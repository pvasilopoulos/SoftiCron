/* CronApp cron builder: visual schedule editor that produces a 5-field cron expression.
   The server (/api/cron/preview, dragonmantank/cron-expression) is the source of truth for
   validation, description and next runs, so what you see is exactly what the scheduler does. */
(function () {
  'use strict';
  const root = document.getElementById('cron-builder');
  if (!root) return;

  const $ = (sel, el) => (el || root).querySelector(sel);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const two = (n) => String(n).padStart(2, '0');
  const toggleIn = (arr, v) => (arr.includes(v) ? arr.filter((x) => x !== v) : arr.concat([v]));
  const clamp = (v, a, b) => (isNaN(v) ? a : Math.min(b, Math.max(a, v)));
  const DS = ['Κυρ', 'Δευ', 'Τρι', 'Τετ', 'Πεμ', 'Παρ', 'Σαβ'];
  const DF = ['Κυριακή', 'Δευτέρα', 'Τρίτη', 'Τετάρτη', 'Πέμπτη', 'Παρασκευή', 'Σάββατο'];
  const MS = ['Ιαν', 'Φεβ', 'Μαρ', 'Απρ', 'Μαΐ', 'Ιουν', 'Ιουλ', 'Αυγ', 'Σεπ', 'Οκτ', 'Νοε', 'Δεκ'];
  const MODES = [['minutes', 'Ανά λεπτά'], ['hourly', 'Ωριαία'], ['daily', 'Ημερήσια'], ['weekly', 'Εβδομαδιαία'], ['monthly', 'Μηνιαία'], ['yearly', 'Ανά μήνες'], ['advanced', 'Προχωρημένο']];
  const PRESETS = [
    ['Κάθε λεπτό', { mode: 'minutes', every: 1, win: false }],
    ['Κάθε 5 λεπτά', { mode: 'minutes', every: 5, win: false }],
    ['Κάθε 15΄ σε ωράριο γραφείου', { mode: 'minutes', every: 15, win: true, winFrom: 9, winTo: 18, winWork: true }],
    ['Κάθε ώρα', { mode: 'hourly', hEvery: 1, hAt: 0, win: false }],
    ['Κάθε εργάσιμη 09:00', { mode: 'daily', dayset: 'work', hours: [9], minute: 0 }],
    ['Κάθε Δευτέρα 08:30', { mode: 'weekly', days: [1], hours: [8], minute: 30 }],
    ['1η του μήνα 00:00', { mode: 'monthly', mKind: 'dates', dates: [1], hours: [0], minute: 0 }],
    ['Τέλος μήνα 23:00', { mode: 'monthly', mKind: 'dates', dates: ['L'], hours: [23], minute: 0 }],
    ['1η Δευτέρα του μήνα 09:00', { mode: 'monthly', mKind: 'nth', nth: '1', wd: 1, hours: [9], minute: 0 }],
    ['Κάθε τρίμηνο', { mode: 'yearly', months: [1, 4, 7, 10], yDate: '1', hours: [0], minute: 0 }],
  ];

  const inExpr = root.querySelector('input[name="cron_expr"]');
  const inUi = root.querySelector('input[name="schedule_ui"]');
  const initialExpr = (root.dataset.expr || '').trim() || '*/5 * * * *';
  const minInterval = parseInt(root.dataset.minInterval || '1', 10);

  let s = {
    mode: 'advanced', every: 5, hEvery: 1, hAt: 0, win: false, winFrom: 9, winTo: 18, winWork: true,
    hours: [9], minute: 0, dayset: 'all', days: [1, 2, 3, 4, 5], mKind: 'dates', dates: [1], nth: '1', wd: 1,
    months: [1, 4, 7, 10], yDate: '1', raw: initialExpr, count: 5,
  };
  try {
    const ui = root.dataset.ui ? JSON.parse(root.dataset.ui) : null;
    if (ui && typeof ui === 'object') s = Object.assign(s, ui);
  } catch (e) { /* ignore */ }
  if (!root.dataset.ui) {
    const m = /^\*\/(\d+) \* \* \* \*$/.exec(initialExpr);
    if (m) { s.mode = 'minutes'; s.every = parseInt(m[1], 10); }
    else if (initialExpr === '* * * * *') { s.mode = 'minutes'; s.every = 1; }
  }
  // If the stored UI no longer produces the saved expression, show it as advanced.
  if (buildExpr().expr !== initialExpr && s.mode !== 'advanced') { s.mode = 'advanced'; }
  s.raw = initialExpr;

  function compress(arr) {
    const a = Array.from(new Set(arr)).filter((x) => typeof x === 'number').sort((x, y) => x - y);
    const out = [];
    let i = 0;
    while (i < a.length) {
      let j = i;
      while (j + 1 < a.length && a[j + 1] === a[j] + 1) j++;
      if (j - i >= 2) out.push(a[i] + '-' + a[j]);
      else for (let k = i; k <= j; k++) out.push(String(a[k]));
      i = j + 1;
    }
    return out.join(',');
  }

  function buildExpr() {
    const dowWin = s.win && s.winWork ? '1-5' : '*';
    const hWin = s.winFrom + '-' + (s.winTo - 1);
    if ((s.mode === 'minutes' || s.mode === 'hourly') && s.win && s.winTo <= s.winFrom) return { expr: '', err: 'Η ώρα λήξης του ωραρίου πρέπει να είναι μετά την ώρα έναρξης.' };
    if (s.mode === 'minutes') return { expr: (s.every === 1 ? '*' : '*/' + s.every) + ' ' + (s.win ? hWin : '*') + ' * * ' + dowWin };
    if (s.mode === 'hourly') {
      const h = s.win ? hWin + (s.hEvery > 1 ? '/' + s.hEvery : '') : (s.hEvery > 1 ? '*/' + s.hEvery : '*');
      return { expr: s.hAt + ' ' + h + ' * * ' + dowWin };
    }
    if (s.mode === 'advanced') return { expr: String(s.raw || '').trim().replace(/\s+/g, ' ') };
    if (!s.hours.length) return { expr: '', err: 'Διάλεξε τουλάχιστον μία ώρα.' };
    const H = compress(s.hours);
    const Mi = s.minute;
    if (s.mode === 'daily') return { expr: Mi + ' ' + H + ' * * ' + ({ all: '*', work: '1-5', weekend: '0,6' })[s.dayset] };
    if (s.mode === 'weekly') {
      if (!s.days.length) return { expr: '', err: 'Διάλεξε τουλάχιστον μία ημέρα.' };
      return { expr: Mi + ' ' + H + ' * * ' + compress(s.days) };
    }
    if (s.mode === 'monthly') {
      if (s.mKind === 'nth') return { expr: Mi + ' ' + H + ' * * ' + s.wd + (s.nth === 'L' ? 'L' : '#' + s.nth) };
      if (!s.dates.length) return { expr: '', err: 'Διάλεξε τουλάχιστον μία ημερομηνία.' };
      const segs = [compress(s.dates.filter((d) => d !== 'L'))].filter(Boolean);
      if (s.dates.includes('L')) segs.push('L');
      return { expr: Mi + ' ' + H + ' ' + segs.join(',') + ' * *' };
    }
    if (s.mode === 'yearly') {
      if (!s.months.length) return { expr: '', err: 'Διάλεξε τουλάχιστον έναν μήνα.' };
      return { expr: Mi + ' ' + H + ' ' + s.yDate + ' ' + compress(s.months) + ' *' };
    }
    return { expr: '' };
  }

  const chip = (on, label, act, val, extra) => `<button type="button" class="chip${extra || ''}" aria-pressed="${on ? 'true' : 'false'}" data-act="${act}" data-val="${esc(val)}">${esc(label)}</button>`;
  const opt = (v, l, cur) => `<option value="${esc(v)}"${String(cur) === String(v) ? ' selected' : ''}>${esc(l)}</option>`;
  const hourOpts = (cur) => Array.from({ length: 25 }, (_, h) => opt(h, two(h) + ':00', cur)).join('');

  // ---- static skeleton ----
  let tzList = [];
  try { tzList = JSON.parse(root.dataset.timezones || '[]'); } catch (e) { tzList = ['Europe/Athens', 'UTC']; }
  const tz0 = root.dataset.tz || 'Europe/Athens';
  root.insertAdjacentHTML('beforeend', `
    <div class="stack" data-cb>
      <div class="row between">
        <h2>Πρόγραμμα εκτέλεσης</h2>
        <label class="row small" style="gap:8px">Ζώνη ώρας
          <select class="select" name="timezone" data-tz style="min-height:36px;width:auto;max-width:220px">${tzList.map((t) => opt(t, t, tz0)).join('')}</select>
        </label>
      </div>
      <div class="stack-sm"><span class="small" style="font-weight:500;color:var(--text-2)">Γρήγορες επιλογές</span>
        <div class="chips">${PRESETS.map((p, i) => `<button type="button" class="var-chip" style="font-family:inherit;font-size:13px" data-act="preset" data-val="${i}">${esc(p[0])}</button>`).join('')}</div>
      </div>
      <div class="seg" role="tablist" aria-label="Τύπος προγράμματος" data-modes></div>
      <div class="cb-panel" data-panel></div>
      <div class="form-grid">
        <label class="field">Ενεργό από <span class="help">Προαιρετικό</span><input class="input" type="date" name="active_from" value="${esc(root.dataset.from || '')}" data-range></label>
        <label class="field">Ενεργό έως <span class="help">Προαιρετικό</span><input class="input" type="date" name="active_until" value="${esc(root.dataset.until || '')}" data-range></label>
      </div>
      <div class="cb-result" aria-live="polite">
        <div class="row">
          <span class="cb-expr" data-expr>—</span>
          <button type="button" class="btn btn-sm" data-act="copy">${'Αντιγραφή'}</button>
          <button type="button" class="btn btn-sm" data-act="edit-raw" data-edit-raw>Επεξεργασία ως έκφραση</button>
        </div>
        <span style="font-weight:500" data-desc></span>
        <span class="cb-err hidden" data-err></span>
        <div class="cb-fields" data-fields></div>
      </div>
      <div class="stack-sm" data-warns></div>
      <div>
        <div class="row between" style="margin-bottom:4px"><h3>Επόμενες εκτελέσεις</h3><button type="button" class="btn btn-ghost btn-sm" data-act="count">Δείξε 10</button></div>
        <div data-next><p class="small muted">…</p></div>
      </div>
    </div>`);

  function renderModes() {
    $('[data-modes]').innerHTML = MODES.map(([id, l]) => `<button type="button" role="tab" aria-selected="${s.mode === id}" data-act="mode" data-val="${id}">${l}</button>`).join('');
  }

  function renderPanel() {
    const p = [];
    if (s.mode === 'minutes') {
      p.push(`<div class="stack-sm"><span class="small" style="font-weight:500">Εκτέλεση κάθε</span><div class="chips" style="align-items:center">
        ${[1, 2, 3, 5, 10, 15, 20, 30].map((n) => chip(s.every === n, n === 1 ? '1 λεπτό' : n + ' λεπτά', 'every', n)).join('')}
        <label class="row small" style="gap:6px">ή <input class="input" type="number" min="1" max="59" value="${s.every}" data-f="every" style="width:76px;min-height:38px"> λεπτά</label></div></div>`);
    }
    if (s.mode === 'hourly') {
      p.push(`<div class="stack-sm"><span class="small" style="font-weight:500">Εκτέλεση κάθε</span><div class="chips" style="align-items:center">
        ${[1, 2, 3, 4, 6, 8, 12].map((n) => chip(s.hEvery === n, n === 1 ? '1 ώρα' : n + ' ώρες', 'hEvery', n)).join('')}
        <label class="row small" style="gap:6px">στο λεπτό <input class="input" type="number" min="0" max="59" value="${s.hAt}" data-f="hAt" style="width:76px;min-height:38px"></label></div></div>`);
    }
    if (s.mode === 'minutes' || s.mode === 'hourly') {
      p.push(`<div class="stack-sm cb-sep"><label class="check"><input type="checkbox" data-f="win" ${s.win ? 'checked' : ''}>Μόνο μέσα σε ωράριο</label>
        ${s.win ? `<div class="row" style="padding-left:28px">
          <label class="row small" style="gap:6px">από <select class="select" data-f="winFrom" style="width:auto;min-height:38px">${hourOpts(s.winFrom)}</select></label>
          <label class="row small" style="gap:6px">έως <select class="select" data-f="winTo" style="width:auto;min-height:38px">${hourOpts(s.winTo)}</select></label>
          <label class="check small"><input type="checkbox" data-f="winWork" ${s.winWork ? 'checked' : ''}>μόνο εργάσιμες (Δευ–Παρ)</label></div>` : ''}</div>`);
    }
    if (s.mode === 'daily') {
      p.push(`<div class="stack-sm"><span class="small" style="font-weight:500">Ημέρες</span><div class="chips">
        ${[['all', 'Κάθε ημέρα'], ['work', 'Εργάσιμες (Δευ–Παρ)'], ['weekend', 'Σαββατοκύριακα']].map(([k, l]) => chip(s.dayset === k, l, 'dayset', k)).join('')}</div></div>`);
    }
    if (s.mode === 'weekly') {
      p.push(`<div class="stack-sm"><span class="small" style="font-weight:500">Ημέρες εβδομάδας</span><div class="chips">
        ${[1, 2, 3, 4, 5, 6, 0].map((d) => chip(s.days.includes(d), DS[d], 'day', d)).join('')}</div></div>`);
    }
    if (s.mode === 'monthly') {
      p.push(`<div class="chips">${chip(s.mKind === 'dates', 'Συγκεκριμένες ημερομηνίες', 'mKind', 'dates')}${chip(s.mKind === 'nth', 'Ημέρα της εβδομάδας', 'mKind', 'nth')}</div>`);
      if (s.mKind === 'dates') {
        const ds = Array.from({ length: 31 }, (_, i) => i + 1).map((d) => chip(s.dates.includes(d), d, 'date', d, '" style="padding:0')).join('');
        p.push(`<div class="chip-grid" style="max-width:440px">${ds}${chip(s.dates.includes('L'), 'Τελευταία', 'date', 'L', '" style="grid-column:span 3')}</div>`);
      } else {
        p.push(`<div class="row small">Την
          <select class="select" data-f="nth" style="width:auto;min-height:40px">${[['1', '1η'], ['2', '2η'], ['3', '3η'], ['4', '4η'], ['L', 'Τελευταία']].map(([v, l]) => opt(v, l, s.nth)).join('')}</select>
          <select class="select" data-f="wd" style="width:auto;min-height:40px">${[1, 2, 3, 4, 5, 6, 0].map((d) => opt(d, DF[d], s.wd)).join('')}</select> κάθε μήνα</div>`);
      }
    }
    if (s.mode === 'yearly') {
      p.push(`<div class="stack-sm"><span class="small" style="font-weight:500">Μήνες</span><div class="chip-grid" style="grid-template-columns:repeat(auto-fill,minmax(64px,1fr));max-width:480px">
        ${MS.map((m, i) => chip(s.months.includes(i + 1), m, 'month', i + 1)).join('')}</div>
        <label class="row small">Ημέρα του μήνα <select class="select" data-f="yDate" style="width:auto;min-height:40px">
        ${Array.from({ length: 31 }, (_, i) => opt(i + 1, i + 1, s.yDate)).join('')}${opt('L', 'Τελευταία ημέρα', s.yDate)}</select></label></div>`);
    }
    if (['daily', 'weekly', 'monthly', 'yearly'].includes(s.mode)) {
      p.push(`<div class="stack-sm cb-sep"><div class="row between"><span class="small" style="font-weight:500">Ώρες (μία ή περισσότερες)</span>
        <label class="row small" style="gap:6px">στο λεπτό <input class="input" type="number" min="0" max="59" value="${s.minute}" data-f="minute" style="width:76px;min-height:38px"></label></div>
        <div class="chip-grid">${Array.from({ length: 24 }, (_, h) => chip(s.hours.includes(h), two(h), 'hour', h, '" style="padding:0')).join('')}</div></div>`);
    }
    if (s.mode === 'advanced') {
      p.push(`<label class="field">Έκφραση cron (5 πεδία: λεπτό ώρα ημέρα μήνας ημέρα-εβδομάδας)
        <input class="input mono" data-f="raw" value="${esc(s.raw)}" spellcheck="false" style="font-size:16px;letter-spacing:.04em"></label>
        <div class="syntax-help">${[['*', 'κάθε τιμή'], [',', 'λίστα: 1,15'], ['-', 'εύρος: 9-17'], ['/', 'βήμα: */10'], ['L', 'τελευταία ημέρα μήνα'], ['5L', 'τελευταία Παρασκευή'], ['1#2', '2η Δευτέρα']].map(([k, v]) => `<span><b>${esc(k)}</b>${esc(v)}</span>`).join('')}</div>`);
    }
    $('[data-panel]').innerHTML = p.join('');
  }

  let timer = null;
  let seq = 0;
  function update() {
    const { expr, err } = buildExpr();
    inExpr.value = err ? '' : expr;
    const ui = Object.assign({}, s);
    delete ui.raw; delete ui.count;
    inUi.value = s.mode === 'advanced' ? JSON.stringify({ mode: 'advanced' }) : JSON.stringify(ui);
    $('[data-expr]').textContent = err ? '—' : (expr || '—');
    $('[data-edit-raw]').classList.toggle('hidden', s.mode === 'advanced');
    if (err) { showResult({ valid: false, error: err, fields: [], next: [], warnings: [] }); return; }
    clearTimeout(timer);
    timer = setTimeout(() => preview(expr), 220);
  }

  async function preview(expr) {
    const my = ++seq;
    const q = new URLSearchParams({ expr, tz: $('[data-tz]').value, count: s.count, from: root.querySelector('[name="active_from"]').value, until: root.querySelector('[name="active_until"]').value });
    try {
      const res = await fetch(root.dataset.previewUrl + '?' + q.toString(), { headers: { Accept: 'application/json', 'X-Requested-With': 'fetch' }, credentials: 'same-origin' });
      const data = await res.json();
      if (my === seq) showResult(data);
    } catch (e) {
      if (my === seq) showResult({ valid: false, error: 'Δεν ήταν δυνατός ο έλεγχος της έκφρασης.', fields: [], next: [], warnings: [] });
    }
  }

  function showResult(d) {
    const errEl = $('[data-err]');
    errEl.textContent = d.error || '';
    errEl.classList.toggle('hidden', !d.error);
    $('[data-desc]').textContent = d.description && d.valid !== false ? d.description : (d.description && !d.error ? d.description : '');
    if (d.error && d.description) $('[data-desc]').textContent = d.description;
    $('[data-fields]').innerHTML = (d.fields || []).map((f) => `<div class="cb-field"><span class="l">${esc(f.label)}</span><span class="r">${esc(f.raw)}</span><span class="t">${esc(f.text)}</span></div>`).join('');
    $('[data-warns]').innerHTML = (d.warnings || []).map((w) => `<p class="notice">${esc(w)}</p>`).join('');
    const next = d.next || [];
    $('[data-next]').innerHTML = next.length
      ? next.map((n) => `<div class="list-item" style="font-size:14px"><span class="grow" style="font-weight:500">${esc(n.date)}</span><span class="small muted">${esc(n.rel)}</span><span class="mono" style="width:56px;text-align:right">${esc(n.time)}</span></div>`).join('')
      : `<p class="small muted">${d.error ? 'Διόρθωσε την έκφραση για να δεις τις επόμενες εκτελέσεις.' : 'Καμία εκτέλεση στο επιλεγμένο διάστημα.'}</p>`;
    root.dataset.valid = d.valid === false ? '0' : '1';
  }

  function full() { renderModes(); renderPanel(); update(); }

  root.addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-act]');
    if (!b || !root.contains(b)) return;
    const act = b.dataset.act;
    const raw = b.dataset.val;
    const num = parseInt(raw, 10);
    switch (act) {
      case 'mode':
        if (raw === 'advanced') { const r = buildExpr(); if (!r.err && r.expr) s.raw = r.expr; }
        s.mode = raw; break;
      case 'preset': Object.assign(s, PRESETS[num][1]); break;
      case 'every': s.every = num; break;
      case 'hEvery': s.hEvery = num; break;
      case 'dayset': s.dayset = raw; break;
      case 'day': s.days = toggleIn(s.days, num); break;
      case 'mKind': s.mKind = raw; break;
      case 'date': s.dates = toggleIn(s.dates, raw === 'L' ? 'L' : num); break;
      case 'month': s.months = toggleIn(s.months, num); break;
      case 'hour': s.hours = toggleIn(s.hours, num); break;
      case 'edit-raw': { const r = buildExpr(); s.raw = r.expr || s.raw; s.mode = 'advanced'; break; }
      case 'count': s.count = s.count === 5 ? 10 : 5; b.textContent = s.count === 5 ? 'Δείξε 10' : 'Δείξε 5'; update(); return;
      case 'copy':
        if (navigator.clipboard) navigator.clipboard.writeText(inExpr.value).then(() => window.toast && window.toast('Η έκφραση αντιγράφηκε.'));
        return;
      default: return;
    }
    full();
  });

  const onField = (ev) => {
    const el = ev.target;
    if (el.matches('[data-tz], [data-range]')) { update(); return; }
    const f = el.dataset.f;
    if (!f) return;
    if (el.type === 'checkbox') { s[f] = el.checked; full(); return; }
    if (f === 'raw') { s.raw = el.value; update(); return; }
    if (['every', 'hAt', 'minute'].includes(f)) {
      const v = parseInt(el.value, 10);
      s[f] = f === 'every' ? clamp(v, 1, 59) : clamp(v, 0, 59);
      if (ev.type === 'change') renderPanel();
      update(); return;
    }
    if (['winFrom', 'winTo', 'wd'].includes(f)) s[f] = parseInt(el.value, 10);
    else s[f] = el.value;
    update();
  };
  root.addEventListener('input', onField);
  root.addEventListener('change', (ev) => { if (ev.target.type === 'checkbox' || ev.target.tagName === 'SELECT' || ev.target.type === 'number' || ev.target.type === 'date') onField(ev); });

  const form = root.closest('form');
  if (form) {
    form.addEventListener('submit', (ev) => {
      if (!inExpr.value || root.dataset.valid === '0') {
        ev.preventDefault();
        window.toast && window.toast('Διόρθωσε το πρόγραμμα εκτέλεσης πριν την αποθήκευση.', 'error');
        root.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    });
  }

  root.querySelector('p.muted')?.remove();
  full();
})();
