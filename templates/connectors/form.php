<?php
/** @var array $conn @var array $cfg */
use App\Auth\Auth;
use App\Services\Connectors;

$isNew = $conn['id'] === null;
$type = $conn['type'];
$fields = Connectors::FIELDS[$type];
$action = $isNew ? url('connectors') : url('connectors/' . $conn['id']);
$user = Auth::user();
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="<?= e(url('connectors')) ?>">Connectors</a><span aria-hidden="true">/</span><span><?= e(Connectors::TYPES[$type]) ?></span></div>
    <h1><?= $isNew ? 'Νέος connector · ' . e(Connectors::TYPES[$type]) : e($conn['name']) ?></h1>
  </div>
</div>

<div class="split">
  <form method="post" action="<?= e($action) ?>" class="card stack" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <label class="field">Όνομα
      <input class="input" name="name" value="<?= e(old('name', $conn['name'])) ?>" placeholder="<?= e(Connectors::TYPES[$type]) ?>" maxlength="120">
    </label>
    <div class="form-grid">
      <?php foreach ($fields as $key => [$label, $input, $required, $secret, $help]):
          $val = $secret ? '' : old('cfg', [])[$key] ?? ($cfg[$key] ?? ''); ?>
        <label class="field"><?= e($label) ?><?= $required ? '' : ' <span class="help">Προαιρετικό</span>' ?>
          <?php if (str_starts_with($input, 'select:')):
              $opts = []; foreach (explode(',', substr($input, 7)) as $o) { [$k, $l] = explode('=', $o); $opts[$k] = $l; } ?>
            <select class="select" name="cfg[<?= e($key) ?>]">
              <?php foreach ($opts as $k => $l): ?><option value="<?= e($k) ?>" <?= (string) $val === (string) $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
            </select>
          <?php else: ?>
            <input class="input<?= $secret || $key === 'tenant_id' || $key === 'client_id' ? ' mono' : '' ?>" type="<?= e($input) ?>" name="cfg[<?= e($key) ?>]" value="<?= e($val) ?>"
              <?= $required && !($secret && !$isNew) ? 'required' : '' ?> <?= $secret && !$isNew ? 'placeholder="•••••••• (αμετάβλητο)"' : '' ?> autocomplete="off">
          <?php endif; ?>
          <?php if ($help): ?><span class="help"><?= e($help) ?></span><?php endif; ?>
        </label>
      <?php endforeach; ?>
    </div>
    <label class="check"><input type="checkbox" name="is_default" value="1" <?= $conn['is_default'] ? 'checked' : '' ?>>Προεπιλεγμένος για <?= e(App\Services\Notifier::CHANNELS[App\Services\Connectors::channelOf($type)] ?? $type) ?></label>
    <p class="xs muted"><?= icon('lock', 12) ?> Τα στοιχεία αποθηκεύονται κρυπτογραφημένα και οι κωδικοί δεν εμφανίζονται ξανά.</p>
    <div class="form-actions">
      <a class="btn" href="<?= e(url('connectors')) ?>">Ακύρωση</a>
      <button class="btn btn-primary" type="submit">Αποθήκευση</button>
    </div>
  </form>

  <aside class="stack">
    <?php if (!$isNew): ?>
      <form method="post" action="<?= e(url('connectors/' . $conn['id'] . '/test')) ?>" class="card stack-sm" data-loading>
        <?= csrf_field() ?>
        <h2>Δοκιμαστικό μήνυμα</h2>
        <?php if ($type === 'telegram' || in_array($type, ['smtp', 'm365'], true)): ?>
          <label class="field"><?= $type === 'telegram' ? 'Chat ID' : 'Email παραλήπτη' ?>
            <input class="input" name="target" value="<?= $type === 'telegram' ? e($cfg['default_chat_id'] ?? '') : e($user['email']) ?>" <?= $type === 'telegram' ? '' : 'type="email" required' ?>>
          </label>
        <?php else: ?>
          <p class="small muted">Στέλνει ένα δοκιμαστικό μήνυμα στο webhook που έχεις ορίσει.</p>
        <?php endif; ?>
        <button class="btn" type="submit"><?= icon('send', 16) ?>Αποστολή</button>
        <?php if ($conn['last_test_at']): ?>
          <p class="xs <?= $conn['last_test_ok'] ? '' : '' ?>" style="color:var(--<?= $conn['last_test_ok'] ? 'ok' : 'fail' ?>-fg)">
            Τελευταίο τεστ <?= e(rel_time($conn['last_test_at'])) ?>: <?= $conn['last_test_ok'] ? 'επιτυχία' : e($conn['last_test_error']) ?>
          </p>
        <?php endif; ?>
      </form>
    <?php endif; ?>
    <section class="card stack-sm small">
      <h2>Οδηγίες</h2>
      <?php if ($type === 'smtp'): ?>
        <p>Χρησιμοποίησε τα στοιχεία SMTP του mail server σου (π.χ. του Plesk). Για Gmail/Outlook προτίμησε app password.</p>
      <?php elseif ($type === 'm365'): ?>
        <ol style="margin:0;padding-left:18px;display:flex;flex-direction:column;gap:6px">
          <li>Στο Microsoft Entra ID → App registrations, δημιούργησε νέα εφαρμογή.</li>
          <li>API permissions → Microsoft Graph → Application → <code>Mail.Send</code> → Grant admin consent.</li>
          <li>Certificates &amp; secrets → New client secret.</li>
          <li>Συμπλήρωσε Tenant ID, Client ID, Secret και το mailbox αποστολέα.</li>
        </ol>
      <?php elseif ($type === 'slack'): ?>
        <ol style="margin:0;padding-left:18px;display:flex;flex-direction:column;gap:6px">
          <li>Στο <strong>api.slack.com/apps</strong> δημιούργησε εφαρμογή («From scratch»).</li>
          <li>Incoming Webhooks → On → <strong>Add New Webhook to Workspace</strong> → διάλεξε κανάλι.</li>
          <li>Αντέγραψε το URL (<code>https://hooks.slack.com/services/…</code>) εδώ.</li>
        </ol>
      <?php elseif ($type === 'teams'): ?>
        <ol style="margin:0;padding-left:18px;display:flex;flex-direction:column;gap:6px">
          <li>Στο κανάλι του Teams: <strong>⋯ → Workflows</strong>.</li>
          <li>Επίλεξε το πρότυπο «<strong>Post to a channel when a webhook request is received</strong>».</li>
          <li>Ολοκλήρωσε τον οδηγό και αντέγραψε το URL του webhook εδώ.</li>
        </ol>
      <?php elseif ($type === 'discord'): ?>
        <ol style="margin:0;padding-left:18px;display:flex;flex-direction:column;gap:6px">
          <li>Server Settings → <strong>Integrations → Webhooks → New Webhook</strong>.</li>
          <li>Διάλεξε κανάλι και πάτα <strong>Copy Webhook URL</strong>.</li>
        </ol>
      <?php elseif ($type === 'webhook'): ?>
        <p>Σε κάθε ειδοποίηση στέλνεται <code>POST</code> με JSON:</p>
        <pre class="code" style="font-size:11.5px">{
  "event": "failed",
  "title": "…", "message": "…",
  "job":  { "id": 42, "name": "…", "url": "…" },
  "run":  { "id": 18234, "status": "failed", "http_status": 503, … },
  "tenant": { "id": 1, "name": "…" },
  "sent_at": "2026-11-09T10:00:00+00:00"
}</pre>
        <p>Με secret, ελέγχεις το header <code>X-CronApp-Signature: sha256=…</code> (HMAC-SHA256 του body). Ιδανικό για n8n, Zapier, Make ή δικό σου API.</p>
      <?php else: ?>
        <ol style="margin:0;padding-left:18px;display:flex;flex-direction:column;gap:6px">
          <li>Άνοιξε τον <strong>@BotFather</strong> στο Telegram και στείλε <code>/newbot</code>.</li>
          <li>Αντέγραψε το token εδώ.</li>
          <li>Πρόσθεσε το bot στο group/channel και βρες το chat ID (π.χ. με τον @userinfobot).</li>
        </ol>
      <?php endif; ?>
    </section>
    <?php if (!$isNew): ?>
      <form method="post" action="<?= e(url('connectors/' . $conn['id'] . '/delete')) ?>" data-confirm="Να διαγραφεί ο connector «<?= e($conn['name']) ?>»;">
        <?= csrf_field() ?><button class="btn btn-danger" type="submit"><?= icon('trash', 16) ?>Διαγραφή connector</button>
      </form>
    <?php endif; ?>
  </aside>
</div>
