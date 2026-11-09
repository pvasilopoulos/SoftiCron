<?php
/** @var string $content */
use App\Core\Session;

echo App\Core\View::partial('partials/head', ['title' => $title ?? '']);
?>
<body>
<div class="auth-wrap">
  <div class="auth-card<?= !empty($wide) ? ' wide' : '' ?>">
    <div class="auth-logo">
      <img src="<?= e(asset('icons/icon.svg')) ?>" width="40" height="40" alt="">
      <span><?= e(App\Core\App::name()) ?></span>
    </div>
    <div class="stack">
      <?php foreach (Session::takeFlash() as $f): ?>
        <div class="flash <?= e($f['type']) ?>" role="<?= $f['type'] === 'error' ? 'alert' : 'status' ?>"><span><?= e($f['message']) ?></span></div>
      <?php endforeach; ?>
      <?= $content ?>
    </div>
  </div>
</div>
<div class="toast-wrap" id="toasts" aria-live="polite"></div>
</body>
</html>
