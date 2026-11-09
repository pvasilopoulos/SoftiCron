<?php
/** @var array $job @var array $headers @var array $notify @var array $recent @var array $emailConnectors @var array $telegramConnectors @var int $minInterval @var bool $canManage @var bool $canRun @var array $variables */
use App\Auth\Auth;
use App\Services\CronHelper;
use App\Services\Notifier;

$tenant = Auth::tenant();
$isNew = $job['id'] === null;
$hasOld = isset($_SESSION['_old']['name']);
$v = fn (string $k) => $hasOld ? old($k, '') : ($job[$k] ?? '');
if ($hasOld) {
    $headers = [];
    foreach ((array) old('h_name', []) as $i => $n) {
        $headers[] = ['name' => $n, 'value' => old('h_value', [])[$i] ?? '', 'secret' => (old('h_secret', [])[$i] ?? '0') === '1'];
    }
    $notify = [];
    foreach ((array) old('notify', []) as $ev => $chs) {
        foreach ((array) $chs as $ch => $on) {
            $notify[$ev][$ch] = (bool) $on;
        }
    }
}
$action = $isNew ? url('jobs') : url('jobs/' . $job['id']);
$tz = $tenant['timezone'];
$bodyType = (string) ($v('body_type') ?: 'none');
$scheduleUi = (string) ($v('schedule_ui') ?? '');
$pushTarget = (string) ($v('push_target') ?: 'all');
if ($hasOld) {
    $pushTargets = ['users' => array_map('intval', (array) old('push_users', [])), 'devices' => array_map('intval', (array) old('push_devices', []))];
}
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="<?= e(url('jobs')) ?>">Jobs</a><span aria-hidden="true">/</span><span><?= $isNew ? 'Νέο job' : e($job['name']) ?></span></div>
    <h1><?= $isNew ? 'Νέο job' : e($job['name']) ?></h1>
  </div>
  <div class="row">
    <?php if ($canRun): ?>
      <button class="btn" type="button" data-test-run><?= icon('play', 16) ?>Test run</button>
    <?php endif; ?>
    <?php if (!$isNew && $canRun): ?>
      <form method="post" action="<?= e(url('jobs/' . $job['id'] . '/run')) ?>" class="inline" data-loading>
        <?= csrf_field() ?><button class="btn" type="submit"><?= icon('send', 16) ?>Εκτέλεση τώρα</button>
      </form>
    <?php endif; ?>
    <?php if ($canManage): ?>
      <button class="btn btn-primary" type="submit" form="job-form"><?= icon('check', 16) ?>Αποθήκευση</button>
    <?php endif; ?>
  </div>
</div>

<?php if (!$isNew): ?>
  <nav class="tabs" aria-label="Ενότητες job">
    <a class="on" href="<?= e(url('jobs/' . $job['id'] . '/edit')) ?>" aria-current="page">Ρυθμίσεις</a>
    <a href="<?= e(url('runs', ['job' => $job['id']])) ?>">Ιστορικό εκτελέσεων</a>
    <?php if (Auth::can('audit.view')): ?><a href="<?= e(url('audit', ['q' => $job['id']])) ?>">Αλλαγές (audit)</a><?php endif; ?>
  </nav>
<?php endif; ?>

<form id="job-form" method="post" action="<?= e($action) ?>" class="split" autocomplete="off" data-job-id="<?= (int) ($job['id'] ?? 0) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="job_id" value="<?= (int) ($job['id'] ?? 0) ?>">
  <fieldset class="stack" style="border:0;padding:0;margin:0;min-width:0" <?= $canManage ? '' : 'disabled' ?>>

    <section class="card stack">
      <div class="row between"><h2>Γενικά</h2>
        <label class="check"><span class="switch"><input type="checkbox" name="enabled" value="1" <?= $v('enabled') ? 'checked' : '' ?>><span></span></span>Ενεργό</label>
      </div>
      <label class="field">Όνομα
        <input class="input" name="name" value="<?= e($v('name')) ?>" maxlength="150" required placeholder="π.χ. Συγχρονισμός παραγγελιών">
      </label>
      <label class="field">Περιγραφή <span class="help">Προαιρετική</span>
        <textarea class="textarea" name="description" rows="2" style="min-height:64px"><?= e($v('description')) ?></textarea>
      </label>
    </section>

    <section class="card stack">
      <h2>Request</h2>
      <div class="row" style="align-items:flex-end;flex-wrap:wrap">
        <label class="field" style="flex:0 0 120px">Μέθοδος
          <select class="select mono" name="method" data-method>
            <?php foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'] as $m): ?>
              <option <?= $v('method') === $m ? 'selected' : '' ?>><?= $m ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field" style="flex:1 1 300px">URL
          <input class="input mono" name="url" value="<?= e($v('url')) ?>" required placeholder="https://api.example.gr/webhook" inputmode="url">
        </label>
      </div>

      <div class="stack-sm">
        <span class="small" style="font-weight:500;color:var(--text-2)">Headers</span>
        <div class="stack-sm" data-headers>
          <?php foreach ($headers as $h): ?>
            <div class="header-row">
              <input class="input mono" name="h_name[]" value="<?= e($h['name']) ?>" placeholder="Όνομα" aria-label="Όνομα header">
              <input class="input mono" name="h_value[]" value="<?= e($h['value']) ?>" placeholder="<?= $h['secret'] ? '•••••••• (αμετάβλητο)' : 'Τιμή' ?>" aria-label="Τιμή header" <?= $h['secret'] ? 'type="password"' : '' ?>>
              <label class="check xs secret-label" title="Κρυπτογραφημένο, δεν εμφανίζεται ξανά"><input type="hidden" name="h_secret[]" value="<?= $h['secret'] ? '1' : '0' ?>"><input type="checkbox" data-secret <?= $h['secret'] ? 'checked' : '' ?>><?= icon('lock', 14) ?>Μυστικό</label>
              <button class="btn btn-ghost btn-icon btn-sm" type="button" data-remove-row aria-label="Αφαίρεση header"><?= icon('x', 16) ?></button>
            </div>
          <?php endforeach; ?>
        </div>
        <template id="header-row-tpl">
          <div class="header-row">
            <input class="input mono" name="h_name[]" placeholder="Όνομα" aria-label="Όνομα header">
            <input class="input mono" name="h_value[]" placeholder="Τιμή" aria-label="Τιμή header">
            <label class="check xs secret-label"><input type="hidden" name="h_secret[]" value="0"><input type="checkbox" data-secret><?= icon('lock', 14) ?>Μυστικό</label>
            <button class="btn btn-ghost btn-icon btn-sm" type="button" data-remove-row aria-label="Αφαίρεση header"><?= icon('x', 16) ?></button>
          </div>
        </template>
        <div class="row">
          <button class="btn btn-ghost btn-sm" type="button" data-add-header><?= icon('plus', 14) ?>Προσθήκη header</button>
          <span class="xs muted">Τα headers αποθηκεύονται κρυπτογραφημένα. Τα «μυστικά» δεν εμφανίζονται ξανά.</span>
        </div>
      </div>

      <div class="stack-sm" data-body-editor>
        <div class="row between">
          <span class="small" style="font-weight:500;color:var(--text-2)">Request body</span>
          <div class="seg" role="group" aria-label="Τύπος body">
            <?php foreach (['none' => 'Κανένα', 'json' => 'JSON', 'form' => 'Form', 'text' => 'Κείμενο', 'xml' => 'XML'] as $k => $l): ?>
              <button type="button" data-body-type="<?= $k ?>" aria-pressed="<?= $bodyType === $k ? 'true' : 'false' ?>"><?= $l ?></button>
            <?php endforeach; ?>
          </div>
        </div>
        <input type="hidden" name="body_type" value="<?= e($bodyType) ?>">
        <p class="notice hidden" data-body-get-warning>Τα GET/HEAD requests συνήθως δεν έχουν body. Το HEAD δεν στέλνει ποτέ body.</p>
        <p class="empty hidden" data-body-none style="padding:14px;border:1px dashed var(--border-strong);border-radius:10px">Το request στέλνεται χωρίς body.</p>
        <div class="stack-sm" data-body-area>
          <label class="sr-only" for="job-body">Περιεχόμενο body</label>
          <textarea id="job-body" class="textarea code mono" name="body" spellcheck="false" rows="8"><?= e($v('body')) ?></textarea>
          <div class="row">
            <span class="badge ok" data-body-valid>✓</span>
            <button class="btn btn-sm" type="button" data-format-json>Μορφοποίηση JSON</button>
            <span class="grow"></span>
            <span class="xs muted" data-body-ct></span>
          </div>
          <p class="xs muted hidden" data-form-hint>Για Form γράψε ένα πεδίο ανά γραμμή: <code>key=value</code></p>
        </div>
        <div class="row" style="gap:6px">
          <span class="xs muted">Μεταβλητές:</span>
          <?php foreach ($variables as $var): ?>
            <button class="var-chip" type="button" data-insert-var="<?= e($var) ?>"><?= e($var) ?></button>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <section class="card">
      <div id="cron-builder"
           data-expr="<?= e($v('cron_expr')) ?>"
           data-ui="<?= e($scheduleUi) ?>"
           data-tz="<?= e($v('timezone') ?: $tz) ?>"
           data-from="<?= e($v('active_from')) ?>"
           data-until="<?= e($v('active_until')) ?>"
           data-min-interval="<?= (int) $minInterval ?>"
           data-preview-url="<?= e(url('api/cron/preview')) ?>"
           data-timezones="<?= e(json_encode(array_values(array_unique(array_merge([$tz, 'Europe/Athens', 'UTC'], CronHelper::timezones()))))) ?>">
        <input type="hidden" name="cron_expr" value="<?= e($v('cron_expr')) ?>">
        <input type="hidden" name="schedule_ui" value="<?= e($scheduleUi) ?>">
        <p class="muted small">Φόρτωση cron builder…</p>
      </div>
    </section>

    <section class="card stack">
      <h2>Εκτέλεση & αξιοπιστία</h2>
      <div class="form-grid">
        <label class="field">Timeout (δευτ.)
          <input class="input" type="number" min="1" max="300" name="timeout_sec" value="<?= e($v('timeout_sec')) ?>">
        </label>
        <label class="field">Επαναλήψεις σε αποτυχία
          <input class="input" type="number" min="0" max="10" name="max_retries" value="<?= e($v('max_retries')) ?>">
        </label>
        <label class="field">Αναμονή μεταξύ προσπαθειών
          <select class="select" name="retry_strategy">
            <option value="exponential" <?= $v('retry_strategy') === 'exponential' ? 'selected' : '' ?>>Εκθετική (×3 κάθε φορά)</option>
            <option value="fixed" <?= $v('retry_strategy') === 'fixed' ? 'selected' : '' ?>>Σταθερή</option>
          </select>
        </label>
        <label class="field">Αρχική αναμονή (δευτ.)
          <input class="input" type="number" min="1" max="3600" name="retry_delay_sec" value="<?= e($v('retry_delay_sec')) ?>">
        </label>
        <label class="field">Αν τρέχει ήδη
          <select class="select" name="overlap_policy">
            <option value="skip" <?= $v('overlap_policy') === 'skip' ? 'selected' : '' ?>>Παράλειψη</option>
            <option value="queue" <?= $v('overlap_policy') === 'queue' ? 'selected' : '' ?>>Αναμονή στη σειρά</option>
            <option value="allow" <?= $v('overlap_policy') === 'allow' ? 'selected' : '' ?>>Παράλληλη εκτέλεση</option>
          </select>
        </label>
        <label class="field">Όριο «αργής» απόκρισης (ms)
          <input class="input" type="number" min="0" name="slow_threshold_ms" value="<?= e($v('slow_threshold_ms')) ?>" placeholder="Χωρίς όριο">
        </label>
      </div>
      <div class="form-grid">
        <label class="field">Επιτυχία όταν το HTTP status είναι
          <input class="input mono" name="success_codes" value="<?= e($v('success_codes')) ?>" placeholder="200-299">
          <span class="help">π.χ. 200-299,304 ή 2xx</span>
        </label>
        <label class="field">…και το response περιέχει <span class="help">Προαιρετικό</span>
          <input class="input mono" name="success_contains" value="<?= e($v('success_contains')) ?>" placeholder='"status":"ok"'>
        </label>
      </div>
    </section>
  </fieldset>

  <aside class="stack" style="min-width:0">
    <?php if ($canRun): ?>
      <section class="card stack-sm" id="test-run">
        <div class="row between"><h2>Test run</h2><button class="btn btn-ghost btn-sm" type="button" data-test-run>Εκτέλεση</button></div>
        <p class="small muted" data-test-idle>Στέλνει το request τώρα, με τις τρέχουσες ρυθμίσεις της φόρμας, χωρίς να αποθηκευτεί το job ή να σταλούν ειδοποιήσεις.</p>
        <div data-test-result class="stack-sm hidden"></div>
      </section>
    <?php endif; ?>

    <fieldset class="card stack" style="margin:0;min-width:0" <?= $canManage ? '' : 'disabled' ?>>
      <h2>Ειδοποιήσεις</h2>
      <div class="table-wrap">
        <table class="table" style="font-size:13px">
          <thead><tr><th style="padding-left:0">Γεγονός</th><?php foreach (Notifier::CHANNELS as $ch => $l): ?><th style="text-align:center"><?= e($l) ?></th><?php endforeach; ?></tr></thead>
          <tbody>
            <?php foreach (Notifier::EVENTS as $ev => $label): ?>
              <tr>
                <td style="padding-left:0"><?= e($label) ?></td>
                <?php foreach (Notifier::CHANNELS as $ch => $l): ?>
                  <td style="text-align:center"><input type="checkbox" name="notify[<?= $ev ?>][<?= $ch ?>]" value="1" <?= !empty($notify[$ev][$ch]) ? 'checked' : '' ?> aria-label="<?= e($label . ' · ' . $l) ?>" style="width:18px;height:18px;accent-color:var(--accent)"></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="stack-sm" data-push-targets>
        <span class="small" style="font-weight:500;color:var(--text-2)">Push προς</span>
        <div class="seg" role="radiogroup" aria-label="Παραλήπτες push">
          <label style="display:contents"><input type="radio" class="sr-only" name="push_target" value="all" <?= $pushTarget === 'all' ? 'checked' : '' ?>><span class="seg-opt">Όλους</span></label>
          <label style="display:contents"><input type="radio" class="sr-only" name="push_target" value="selected" <?= $pushTarget === 'selected' ? 'checked' : '' ?>><span class="seg-opt">Επιλεγμένους</span></label>
        </div>
        <p class="xs muted" data-push-all-help>Όλα τα μέλη που έχουν ενεργοποιήσει push, σύμφωνα με τις προτιμήσεις του καθενός.</p>
        <div class="stack-sm" data-push-picker style="max-height:280px;overflow:auto;border:1px solid var(--border);border-radius:10px;padding:10px 12px">
          <?php if (!$pushMembers): ?><p class="xs muted">Δεν υπάρχουν μέλη.</p><?php endif; ?>
          <?php foreach ($pushMembers as $m): $userOn = in_array((int) $m['id'], $pushTargets['users'], true); ?>
            <div class="stack-sm" style="gap:4px">
              <label class="check small"><input type="checkbox" name="push_users[]" value="<?= (int) $m['id'] ?>" <?= $userOn ? 'checked' : '' ?> data-push-user>
                <strong><?= e($m['name']) ?></strong> <span class="xs muted">όλες οι συσκευές (<?= count($m['devices']) ?>)</span></label>
              <?php foreach ($m['devices'] as $d): ?>
                <label class="check xs" style="padding-left:28px"><input type="checkbox" name="push_devices[]" value="<?= (int) $d['id'] ?>" <?= $userOn || in_array((int) $d['id'], $pushTargets['devices'], true) ? 'checked' : '' ?> <?= $userOn ? 'disabled' : '' ?> data-push-device>
                  <?= e($d['label'] ?: $d['user_agent'] ?: 'Συσκευή') ?><?= $d['enabled'] ? '' : ' <span class="badge off">ανενεργή</span>' ?></label>
              <?php endforeach; ?>
              <?php if (!$m['devices']): ?><span class="xs muted" style="padding-left:28px">Χωρίς συσκευές με ενεργό push</span><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <label class="field">Παραλήπτες email <span class="help">Κενό = οι προεπιλεγμένοι από τις Ρυθμίσεις</span>
        <input class="input" name="notify_emails" value="<?= e($v('notify_emails')) ?>" placeholder="ops@example.gr, it@example.gr">
      </label>
      <label class="field">Email connector
        <select class="select" name="email_connector_id">
          <option value="">Προεπιλογή</option>
          <?php foreach ($emailConnectors as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $v('email_connector_id') === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <div class="form-grid">
        <label class="field">Telegram chat ID <span class="help">Κενό = του connector</span>
          <input class="input mono" name="notify_telegram_chat" value="<?= e($v('notify_telegram_chat')) ?>">
        </label>
        <label class="field">Telegram connector
          <select class="select" name="telegram_connector_id">
            <option value="">Προεπιλογή</option>
            <?php foreach ($telegramConnectors as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $v('telegram_connector_id') === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
          </select>
        </label>
      </div>
      <?php if (!$emailConnectors && !$telegramConnectors && Auth::can('connectors.manage')): ?>
        <p class="notice">Δεν έχεις ρυθμίσει connectors. <a href="<?= e(url('connectors/new')) ?>">Πρόσθεσε έναν</a> για email ή Telegram.</p>
      <?php endif; ?>
      <p class="xs muted">Τα μηνύματα χρησιμοποιούν τα <a href="<?= e(url('templates')) ?>">templates</a> του οργανισμού. Το Push πηγαίνει στις συσκευές των χρηστών που το έχουν ενεργοποιήσει.</p>
    </fieldset>

    <?php if (!$isNew): ?>
      <section class="card">
        <div class="card-head" style="margin-bottom:6px"><h2>Τελευταίες εκτελέσεις</h2><a class="small" href="<?= e(url('runs', ['job' => $job['id']])) ?>">Όλες</a></div>
        <?php if (!$recent): ?><p class="small muted">Δεν έχει εκτελεστεί ακόμα. Επόμενη: <?= e(fmt_dt($job['next_run_at'], $job['timezone'])) ?></p><?php endif; ?>
        <?php foreach ($recent as $r): ?>
          <a class="list-item" href="<?= e(url('runs/' . $r['id'])) ?>" style="text-decoration:none;color:inherit">
            <?= status_badge($r['status'], (bool) $r['is_slow']) ?>
            <span class="small grow"><?= e(fmt_dt($r['created_at'], $job['timezone'], 'd/m H:i:s')) ?><?= $r['trigger_type'] === 'manual' ? ' · χειροκίνητη' : '' ?></span>
            <span class="xs muted mono"><?= $r['http_status'] ? (int) $r['http_status'] : '' ?> <?= e(fmt_ms($r['duration_ms'] !== null ? (int) $r['duration_ms'] : null)) ?></span>
          </a>
        <?php endforeach; ?>
      </section>

      <?php if ($canManage): ?>
        <section class="card stack-sm">
          <h2>Ενέργειες</h2>
          <div class="row">
            <button class="btn btn-sm" type="submit" form="dup-form"><?= icon('copy', 14) ?>Αντίγραφο</button>
            <button class="btn btn-sm btn-danger" type="submit" form="del-form"><?= icon('trash', 14) ?>Διαγραφή</button>
          </div>
        </section>
      <?php endif; ?>
    <?php endif; ?>
  </aside>
</form>
<?php if (!$isNew && $canManage): ?>
  <form id="dup-form" method="post" action="<?= e(url('jobs/' . $job['id'] . '/duplicate')) ?>" class="hidden"><?= csrf_field() ?></form>
  <form id="del-form" method="post" action="<?= e(url('jobs/' . $job['id'] . '/delete')) ?>" class="hidden" data-confirm="Να διαγραφεί οριστικά το job «<?= e($job['name']) ?>» μαζί με το ιστορικό του;"><?= csrf_field() ?></form>
<?php endif; ?>
<script src="<?= e(asset('cron-builder.js')) ?>" defer></script>
<script src="<?= e(asset('job-form.js')) ?>" defer></script>
