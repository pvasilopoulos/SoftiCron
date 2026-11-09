<?php
/** @var string $base */
$endpoints = [
    ['GET', '/me', 'read', 'Στοιχεία token, χρήστη και οργανισμού.', null],
    ['GET', '/jobs', 'read', 'Λίστα jobs. Φίλτρα: <code>status</code> (active, paused, failing), <code>group</code>, <code>q</code>, <code>page</code>, <code>per_page</code> (έως 200).', null],
    ['GET', '/jobs/{id}', 'read', 'Ένα job με όλες τις ρυθμίσεις του (χωρίς secrets).', null],
    ['POST', '/jobs', 'write', 'Δημιουργία job. Απαιτούνται <code>name</code>, <code>url</code>, <code>cron</code>.', '{
  "name": "Συγχρονισμός παραγγελιών",
  "group": "ERP",
  "method": "POST",
  "url": "{{ vars.ERP_URL }}/sync",
  "cron": "*/15 * * * *",
  "timezone": "Europe/Athens",
  "headers": [{"name": "X-Key", "value": "{{ secrets.ERP_KEY }}", "secret": true}],
  "body_type": "json",
  "body": "{\"since\": \"{{ run.previous_at }}\"}",
  "max_retries": 2,
  "notify": {"failed": ["email", "push"], "recovered": ["email"]},
  "enabled": true
}'],
    ['PATCH', '/jobs/{id}', 'write', 'Αλλαγή job. Στέλνεις μόνο τα πεδία που αλλάζουν. Secret headers ή auth πεδία με τιμή <code>null</code> κρατούν την αποθηκευμένη τιμή.', '{"cron": "0 * * * *", "max_retries": 3}'],
    ['DELETE', '/jobs/{id}', 'write', 'Οριστική διαγραφή job και του ιστορικού του. Απαντά 204.', null],
    ['POST', '/jobs/{id}/pause', 'write', 'Παύση job.', null],
    ['POST', '/jobs/{id}/resume', 'write', 'Ενεργοποίηση job.', null],
    ['POST', '/jobs/{id}/run', 'run', 'Εκτέλεση τώρα και αναμονή αποτελέσματος. Με <code>?wait=0</code> μπαίνει μόνο στην ουρά (202).', null],
    ['GET', '/runs', 'read', 'Εκτελέσεις, νεότερες πρώτα. Φίλτρα: <code>job_id</code>, <code>status</code>, <code>since</code>, <code>until</code> (ISO 8601), <code>page</code>.', null],
    ['GET', '/runs/{id}', 'read', 'Μία εκτέλεση με την απάντηση του server.', null],
    ['GET', '/reports/summary', 'read', 'Uptime, SLA και χρόνοι απόκρισης. Φίλτρα: <code>days</code> (1–365, προεπιλογή 30), <code>group</code>.', null],
];
$colors = ['GET' => 'ok', 'POST' => 'run', 'PATCH' => 'slow', 'DELETE' => 'fail'];
?>
<div class="page-head">
  <div><h1>Τεκμηρίωση API</h1><p class="sub">REST API v1 · JSON · UTF-8</p></div>
  <?php if (App\Auth\Auth::can('api.manage')): ?><a class="btn btn-primary" href="<?= e(url('api-tokens')) ?>"><?= icon('key', 16) ?>API tokens</a><?php endif; ?>
</div>

<div class="split">
  <div class="stack">
    <section class="card stack-sm">
      <h2>Βασικά</h2>
      <div class="kv">
        <div><span class="k">Base URL</span><span class="v mono small"><?= e($base) ?></span></div>
        <div><span class="k">Αυθεντικοποίηση</span><span class="v small">Bearer token</span></div>
        <div><span class="k">Όριο</span><span class="v small">120 / λεπτό / token</span></div>
      </div>
      <pre class="code">curl -H "Authorization: Bearer sct_…" <?= e($base) ?>/jobs</pre>
      <p class="small">Αν ο proxy σου αφαιρεί το <code>Authorization</code>, στείλε το token στο header <code>X-Api-Key</code>. Στα αιτήματα με body βάλε <code>Content-Type: application/json</code>.</p>
    </section>

    <?php foreach ($endpoints as [$m, $path, $scope, $desc, $example]): ?>
      <section class="card stack-sm">
        <div class="row" style="gap:10px">
          <span class="badge <?= $colors[$m] ?>" style="font-family:var(--mono);min-width:58px;justify-content:center"><?= $m ?></span>
          <code class="small" style="font-weight:600"><?= e($path) ?></code>
          <span class="grow"></span>
          <span class="tag">scope: <?= e($scope) ?></span>
        </div>
        <p class="small"><?= $desc ?></p>
        <?php if ($example): ?><pre class="code"><?= e($example) ?></pre><?php endif; ?>
      </section>
    <?php endforeach; ?>
  </div>

  <aside class="stack">
    <section class="card stack-sm small">
      <h2>Απαντήσεις</h2>
      <p>Επιτυχία: <code>{"data": …}</code>. Οι λίστες έχουν και <code>meta</code> με <code>total</code>, <code>page</code>, <code>pages</code>.</p>
      <p>Σφάλμα: <code>{"error": "…", "status": 4xx}</code>. Στην επικύρωση (422) υπάρχει και πίνακας <code>errors</code>.</p>
      <div class="stack-sm" style="gap:4px">
        <?php foreach (['200' => 'OK', '201' => 'Δημιουργήθηκε', '202' => 'Στην ουρά', '204' => 'Διαγράφηκε', '401' => 'Λάθος ή ληγμένο token', '403' => 'Λείπει δικαίωμα / scope', '404' => 'Δεν βρέθηκε', '409' => 'Σύγκρουση (π.χ. heartbeat job)', '422' => 'Λάθος δεδομένα', '429' => 'Υπέρβαση ορίου'] as $c => $l): ?>
          <div class="row between"><code><?= $c ?></code><span class="muted"><?= e($l) ?></span></div>
        <?php endforeach; ?>
      </div>
    </section>
    <section class="card stack-sm small">
      <h2>Πεδία job</h2>
      <p class="xs muted">Ίδια με τη μορφή εξαγωγής JSON:</p>
      <p><code>name</code> <code>group</code> <code>description</code> <code>type</code> (http, heartbeat) <code>method</code> <code>url</code> <code>headers</code> <code>auth</code> <code>body_type</code> <code>body</code> <code>cron</code> <code>timezone</code> <code>timeout_sec</code> <code>max_retries</code> <code>retry_strategy</code> <code>retry_delay_sec</code> <code>overlap_policy</code> <code>success_codes</code> <code>success_contains</code> <code>slow_threshold_ms</code> <code>notify</code> <code>notify_emails</code> <code>notify_phones</code> <code>notify_after_failures</code> <code>escalate_after_minutes</code> <code>escalate_channels</code> <code>sla_target</code> <code>show_on_status</code> <code>enabled</code></p>
    </section>
    <section class="card stack-sm small">
      <h2>Ασφάλεια</h2>
      <p>Τα secrets (secret headers, κωδικοί auth) δεν επιστρέφονται ποτέ. Χρησιμοποίησε <code>{{ secrets.ΟΝΟΜΑ }}</code> από τις Μεταβλητές.</p>
    </section>
  </aside>
</div>
