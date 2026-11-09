<?php
/** @var ?array $result @var array $summary @var bool $dry */
$labels = ['created' => ['ok', 'Νέο'], 'updated' => ['run', 'Ενημερώθηκε'], 'skipped' => ['off', 'Παραλείφθηκε'], 'error' => ['fail', 'Σφάλμα']];
$groups = App\Core\App::db()->column('SELECT DISTINCT group_name FROM jobs WHERE tenant_id = ? AND group_name IS NOT NULL ORDER BY group_name', [App\Auth\Auth::tenantId()]);
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="<?= e(url('jobs')) ?>">Jobs</a><span aria-hidden="true">/</span><span>Εισαγωγή</span></div>
    <h1>Εισαγωγή jobs από JSON</h1>
    <p class="sub">Από αρχείο εξαγωγής του SoftiCron (άλλος οργανισμός ή άλλη εγκατάσταση) ή από JSON που έφτιαξες εσύ.</p>
  </div>
</div>

<?php if ($result !== null): ?>
  <section class="card stack-sm">
    <div class="row between">
      <h2><?= !empty($dry) ? 'Αποτέλεσμα ελέγχου (δεν αποθηκεύτηκε τίποτα)' : 'Αποτέλεσμα εισαγωγής' ?></h2>
      <div class="chips">
        <?php foreach ($labels as $k => [$cls, $l]): if (!empty($summary[$k])): ?><span class="badge <?= $cls ?>"><?= e($l) ?>: <?= (int) $summary[$k] ?></span><?php endif; endforeach; ?>
      </div>
    </div>
    <div class="table-wrap">
      <table class="table rtable">
        <thead><tr><th>Job</th><th>Αποτέλεσμα</th><th>Λεπτομέρειες</th></tr></thead>
        <tbody>
          <?php foreach ($result as $r): [$cls, $l] = $labels[$r[1]]; ?>
            <tr>
              <td class="primary"><?php if (!empty($r[3])): ?><a href="<?= e(url('jobs/' . $r[3] . '/edit')) ?>"><?= e($r[0]) ?></a><?php else: ?><?= e($r[0]) ?><?php endif; ?></td>
              <td data-label="Αποτέλεσμα"><span class="badge <?= $cls ?>"><?= e($l) ?></span></td>
              <td data-label="Λεπτομέρειες" class="small muted"><?= e($r[2] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="form-actions"><a class="btn" href="<?= e(url('jobs/import')) ?>">Νέα εισαγωγή</a><a class="btn btn-primary" href="<?= e(url('jobs')) ?>">Στα jobs</a></div>
  </section>
<?php endif; ?>

<div class="split">
  <form method="post" action="<?= e(url('jobs/import')) ?>" enctype="multipart/form-data" class="card stack">
    <?= csrf_field() ?>
    <label class="field">Αρχείο JSON <span class="help">Έως 5 MB · έως 500 jobs</span>
      <input class="input" type="file" name="file" accept="application/json,.json" style="padding:8px">
    </label>
    <label class="field">ή επικόλλησε JSON
      <textarea class="textarea mono" name="json" rows="8" spellcheck="false" placeholder='{"jobs": [{"name": "…", "url": "https://…", "cron": "*/5 * * * *"}]}'></textarea>
    </label>
    <div class="form-grid">
      <label class="field">Αν υπάρχει job με το ίδιο όνομα
        <select class="select" name="on_conflict">
          <option value="skip">Παράλειψη</option>
          <option value="copy">Δημιουργία αντιγράφου</option>
          <?php if (App\Auth\Auth::can('jobs.edit')): ?><option value="update">Ενημέρωση του υπάρχοντος</option><?php endif; ?>
        </select>
      </label>
      <label class="field">Ομάδα <span class="help">Κενό = όπως στο αρχείο</span>
        <input class="input" name="group" list="import-groups" maxlength="100">
        <datalist id="import-groups"><?php foreach ($groups as $g): ?><option value="<?= e($g) ?>"><?php endforeach; ?></datalist>
      </label>
    </div>
    <label class="check"><input type="checkbox" name="paused" value="1" checked>Εισαγωγή σε παύση <span class="xs muted">(συνιστάται: έλεγξε URL και secrets πριν τα ενεργοποιήσεις)</span></label>
    <label class="check"><input type="checkbox" name="dry_run" value="1">Μόνο έλεγχος, χωρίς αποθήκευση</label>
    <div class="form-actions"><a class="btn" href="<?= e(url('jobs')) ?>">Ακύρωση</a><button class="btn btn-primary" type="submit"><?= icon('upload', 16) ?>Εισαγωγή</button></div>
  </form>
  <aside class="stack">
    <section class="card stack-sm small">
      <h2>Τι μεταφέρεται</h2>
      <p>Αίτημα, πρόγραμμα, retries, έλεγχος επιτυχίας, ειδοποιήσεις, κλιμάκωση, SLA και heartbeat.</p>
      <p><strong>Δεν μεταφέρονται</strong> secrets (secret headers, κωδικοί auth), συγκεκριμένοι παραλήπτες push και connectors. Για τα secrets χρησιμοποίησε <code>{{ secrets.ΟΝΟΜΑ }}</code> από τις <a href="<?= e(url('variables')) ?>">Μεταβλητές</a>.</p>
      <p class="xs muted">Τα heartbeat jobs παίρνουν νέο ping URL.</p>
    </section>
    <section class="card stack-sm small">
      <h2>Ελάχιστο παράδειγμα</h2>
      <pre class="code" style="font-size:11.5px">{
  "jobs": [
    {
      "name": "Health check",
      "url": "https://example.gr/health",
      "cron": "*/5 * * * *",
      "notify": {"failed": ["email"]}
    }
  ]
}</pre>
    </section>
  </aside>
</div>
