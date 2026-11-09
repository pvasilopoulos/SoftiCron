<?php /** @var int $status @var string $message */ ?>
<div class="card empty">
  <div style="font-size:48px;font-weight:700;color:var(--accent-text)"><?= (int) $status ?></div>
  <p><?= e($message ?: 'Σφάλμα') ?></p>
  <div class="row" style="justify-content:center">
    <a class="btn" href="javascript:history.back()">Πίσω</a>
    <a class="btn btn-primary" href="<?= e(url('/')) ?>">Αρχική</a>
  </div>
</div>
