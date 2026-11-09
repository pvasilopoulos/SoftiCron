<?php
/** @var array $tenant @var array $settings */
use App\Auth\Auth;
use App\Services\CronHelper;

$logoUrl = $tenant['logo_path'] ? url('branding/' . $tenant['id'] . '/logo') . '?v=' . rawurlencode((string) $tenant['logo_path']) : null;
$cGen = Auth::can('settings.manage');
$cNot = Auth::can('settings.notifications');
$cRep = Auth::can('settings.reports');
$cBrand = Auth::can('settings.branding');
$cStatus = Auth::can('settings.status_page');
$cMenu = Auth::can('settings.menu');
?>
<div class="page-head"><div><h1>Ρυθμίσεις</h1><p class="sub">Ρυθμίσεις του οργανισμού <?= e($tenant['name']) ?></p></div></div>

<div class="split">
  <?php if ($cGen || $cNot || $cRep): ?>
  <form method="post" action="<?= e(url('settings')) ?>" class="card stack">
    <?= csrf_field() ?>
    <?php if ($cGen): ?>
    <h2>Γενικά</h2>
    <div class="form-grid">
      <label class="field">Όνομα οργανισμού<input class="input" name="name" value="<?= e($tenant['name']) ?>" required maxlength="150"></label>
      <label class="field">Ζώνη ώρας
        <select class="select" name="timezone"><?php foreach (CronHelper::timezones() as $t): ?><option <?= $t === $tenant['timezone'] ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
        <span class="help">Προεπιλογή για νέα jobs και για την εμφάνιση ωρών</span>
      </label>
    </div>
    <?php endif; ?>
    <?php if ($cNot): ?>
    <h2 style="margin-top:8px">Ειδοποιήσεις</h2>
    <label class="field">Προεπιλεγμένοι παραλήπτες email
      <input class="input" name="notify_emails" value="<?= e($settings['notify.emails'] ?? '') ?>" placeholder="ops@example.gr, it@example.gr">
      <span class="help">Χρησιμοποιούνται όταν ένα job δεν έχει δικούς του παραλήπτες</span>
    </label>
    <div class="form-grid">
      <label class="field">Προεπιλεγμένο Telegram chat ID<input class="input mono" name="notify_telegram_chat" value="<?= e($settings['notify.telegram_chat'] ?? '') ?>"></label>
      <label class="field">Επανάληψη ειδοποίησης αποτυχίας (λεπτά)
        <input class="input" type="number" min="0" max="1440" name="failure_repeat_minutes" value="<?= e($settings['notify.failure_repeat_minutes'] ?? '60') ?>">
        <span class="help">Αν ένα job συνεχίζει να αποτυγχάνει, ειδοποίηση το πολύ μία φορά σε αυτό το διάστημα. 0 = κάθε φορά.</span>
      </label>
    </div>
    <div class="form-grid">
      <label class="field">Προεπιλεγμένα κινητά για SMS / Viber
        <input class="input mono" name="notify_phones" value="<?= e($settings['notify.phones'] ?? '') ?>" placeholder="6912345678, +306987654321" inputmode="tel">
        <span class="help">Χρησιμοποιούνται όταν ένα job δεν έχει δικά του κινητά</span>
      </label>
      <label class="field">Μέγιστα SMS / Viber ανά ημέρα
        <input class="input" type="number" min="0" max="100000" name="sms_daily_limit" value="<?= e($settings['notify.sms_daily_limit'] ?? (string) App\Services\Notifier::SMS_DAILY_LIMIT_DEFAULT) ?>">
        <span class="help">Προστασία του υπολοίπου σου στη Routee. 0 = χωρίς όριο. Σήμερα: <?= App\Services\Notifier::smsSentToday((int) $tenant['id']) ?></span>
      </label>
    </div>
    <div class="stack-sm" style="padding:12px;border:1px solid var(--border);border-radius:12px">
      <label class="check"><input type="checkbox" name="quiet_enabled" value="1" <?= ($settings['notify.quiet_enabled'] ?? '') === '1' ? 'checked' : '' ?>>Ώρες ησυχίας</label>
      <div class="row small" style="gap:8px">από <input class="input" type="time" name="quiet_from" value="<?= e($settings['notify.quiet_from'] ?? '23:00') ?>" style="width:auto;min-height:36px"> έως <input class="input" type="time" name="quiet_to" value="<?= e($settings['notify.quiet_to'] ?? '07:00') ?>" style="width:auto;min-height:36px"></div>
      <span class="xs muted">Μέσα σε αυτές τις ώρες στέλνονται μόνο οι αποτυχίες και οι κλιμακώσεις. Ανακάμψεις, αργές αποκρίσεις και επιτυχίες καταγράφονται ως «παραλείφθηκε».</span>
    </div>
    <?php endif; ?>
    <?php if ($cRep): ?>
    <h2 style="margin-top:8px">Εβδομαδιαία αναφορά</h2>
    <label class="check"><input type="checkbox" name="weekly_enabled" value="1" <?= ($settings['reports.weekly_enabled'] ?? '') === '1' ? 'checked' : '' ?>>Αποστολή αναφοράς διαθεσιμότητας κάθε Δευτέρα 08:00</label>
    <label class="field">Παραλήπτες αναφοράς <span class="help">Κενό = οι προεπιλεγμένοι παραλήπτες email</span>
      <input class="input" name="weekly_emails" value="<?= e($settings['reports.weekly_emails'] ?? '') ?>" placeholder="manager@example.gr">
    </label>
    <?php endif; ?>
    <h2 style="margin-top:8px">Όρια πλάνου</h2>
    <div class="kv">
      <div><span class="k">Μέγιστα jobs</span><span class="v"><?= (int) $tenant['max_jobs'] ?></span></div>
      <div><span class="k">Ελάχιστο διάστημα</span><span class="v"><?= (int) $tenant['min_interval_minutes'] ?> λεπτά</span></div>
      <div><span class="k">Διατήρηση logs</span><span class="v"><?= (int) $tenant['log_retention_days'] ?> ημέρες</span></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Αποθήκευση</button></div>
  </form>
  <?php endif; ?>

  <?php if ($cBrand): ?>
  <form method="post" action="<?= e(url('settings/branding')) ?>" enctype="multipart/form-data" class="card stack">
    <?= csrf_field() ?>
    <h2>Branding</h2>
    <p class="small muted">Το λογότυπο και το χρώμα εμφανίζονται στην εφαρμογή και στα emails για όλους τους χρήστες του οργανισμού.</p>
    <div class="row">
      <div class="logo" style="width:64px;height:64px;border-radius:16px;font-size:20px;background:<?= e($tenant['brand_color']) ?>"><?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt="Λογότυπο"><?php else: ?><?= e(initials($tenant['name'])) ?><?php endif; ?></div>
      <label class="field grow">Λογότυπο <span class="help">PNG, JPG ή WebP, έως 1 MB, ιδανικά τετράγωνο</span>
        <input class="input" type="file" name="logo" accept="image/png,image/jpeg,image/webp" style="padding:8px">
      </label>
    </div>
    <?php if ($logoUrl): ?><label class="check small"><input type="checkbox" name="remove_logo" value="1">Αφαίρεση λογοτύπου</label><?php endif; ?>
    <label class="field">Βασικό χρώμα
      <div class="row">
        <input type="color" name="brand_color" value="<?= e($tenant['brand_color']) ?>" style="width:56px;height:42px;border:1px solid var(--border-strong);border-radius:10px;padding:2px;background:var(--surface)" data-color>
        <input class="input mono" value="<?= e($tenant['brand_color']) ?>" style="width:120px" data-color-text aria-label="Χρώμα hex" pattern="#[0-9A-Fa-f]{6}">
        <div class="chips">
          <?php foreach (['#4F46E5', '#0369A1', '#047857', '#BE123C', '#B45309', '#7C3AED', '#0F172A'] as $c): ?>
            <button type="button" class="chip" style="min-width:34px;min-height:34px;background:<?= $c ?>;border-color:<?= $c ?>" data-swatch="<?= $c ?>" aria-label="Χρώμα <?= $c ?>"></button>
          <?php endforeach; ?>
        </div>
      </div>
    </label>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Αποθήκευση branding</button></div>
  </form>
  <?php endif; ?>
</div>
<?php if ($cStatus):
$stSlug = $settings['status.slug'] ?? $tenant['slug'];
$stOn = ($settings['status.enabled'] ?? '') === '1';
?>
<section id="status-page" class="card row between" style="gap:12px;flex-wrap:wrap">
  <div class="stack-sm" style="gap:2px">
    <h2>Δημόσια σελίδα κατάστασης <span class="badge <?= $stOn ? 'ok' : 'off' ?>" style="vertical-align:middle"><?= $stOn ? 'Δημοσιευμένη' : 'Ανενεργή' ?></span></h2>
    <span class="small muted">Υπηρεσίες, ανακοινώσεις, εμφάνιση, badge, widget και RSS · <span class="mono"><?= e(abs_url('status/' . $stSlug)) ?></span></span>
  </div>
  <a class="btn btn-primary" href="<?= e(url('status-page')) ?>">Διαχείριση σελίδας κατάστασης</a>
</section>
<?php endif; ?>

<?php if ($cMenu):
$footer = App\Services\BottomNav::configured((int) $tenant['id']);
$items = App\Services\BottomNav::ITEMS;
?>
<form id="footer-menu" method="post" action="<?= e(url('settings/menu')) ?>" class="card stack">
  <?= csrf_field() ?>
  <div>
    <h2>Μενού κινητού (footer)</h2>
    <p class="small muted" style="margin-top:4px">Τα κουμπιά της κάτω μπάρας στο κινητό, για όλους τους χρήστες του οργανισμού. Το «Μενού» μένει πάντα τελευταίο και ανοίγει όλες τις σελίδες. Όποιος δεν έχει δικαίωμα για μια σελίδα δεν βλέπει το κουμπί της.</p>
  </div>
  <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr))">
    <?php for ($i = 0; $i < App\Services\BottomNav::SLOTS; $i++): ?>
      <label class="field">Θέση <?= $i + 1 ?>
        <select class="select" name="slots[]" data-footer-slot>
          <option value="">— Κενή —</option>
          <?php foreach ($items as $k => [$label]): ?>
            <option value="<?= e($k) ?>" data-icon="<?= e($items[$k][2]) ?>" <?= ($footer[$i] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    <?php endfor; ?>
  </div>
  <div class="stack-sm">
    <span class="small" style="font-weight:500;color:var(--text-2)">Προεπισκόπηση</span>
    <div data-footer-preview style="display:flex;justify-content:space-around;max-width:420px;padding:8px 6px;border:1px solid var(--border);border-radius:14px;background:var(--surface)"></div>
  </div>
  <div class="form-actions">
    <button class="btn" type="button" data-footer-reset>Προεπιλογή</button>
    <button class="btn btn-primary" type="submit">Αποθήκευση μενού</button>
  </div>
  <template data-footer-icons>
    <?php foreach (array_unique(array_merge(array_column($items, 2), ['menu'])) as $ic): ?><span data-ic="<?= e($ic) ?>"><?= icon($ic, 22) ?></span><?php endforeach; ?>
  </template>
</form>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const fm = document.getElementById('footer-menu');
  if (fm) {
  const slots = [...fm.querySelectorAll('[data-footer-slot]')];
  const icons = fm.querySelector('[data-footer-icons]').content;
  const iconHtml = (n) => icons.querySelector('[data-ic="' + n + '"]')?.innerHTML || '';
  const preview = () => {
    // Prevent duplicates: an item chosen in one slot is disabled in the others.
    const chosen = slots.map((s) => s.value).filter(Boolean);
    slots.forEach((s) => [...s.options].forEach((o) => { o.disabled = o.value !== '' && o.value !== s.value && chosen.includes(o.value); }));
    const parts = slots.filter((s) => s.value).map((s) => { const o = s.selectedOptions[0]; return [o.textContent, o.dataset.icon]; });
    parts.push(['Μενού', 'menu']);
    fm.querySelector('[data-footer-preview]').innerHTML = parts.map(([l, ic], i) =>
      '<span style="display:flex;flex-direction:column;align-items:center;gap:3px;font-size:11px;font-weight:600;color:' + (i === 0 ? 'var(--accent-text)' : 'var(--muted)') + '">' + iconHtml(ic) + l.replace(/[<>&]/g, '') + '</span>').join('');
  };
  slots.forEach((s) => s.addEventListener('change', preview));
  fm.querySelector('[data-footer-reset]').addEventListener('click', () => {
    ['home', 'jobs', 'runs', 'notifications'].forEach((v, i) => { slots[i].value = v; });
    preview();
  });
  preview();
  }
  const c = document.querySelector('[data-color]'), t = document.querySelector('[data-color-text]');
  if (!c || !t) return;
  const set = (v) => { c.value = v; t.value = v.toUpperCase(); document.documentElement.style.setProperty('--accent', v); };
  c.addEventListener('input', () => set(c.value));
  t.addEventListener('input', () => { if (/^#[0-9a-f]{6}$/i.test(t.value)) set(t.value); });
  document.querySelectorAll('[data-swatch]').forEach((b) => b.addEventListener('click', () => set(b.dataset.swatch)));
});
</script>
