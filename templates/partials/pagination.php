<?php
/** @var array $page */
if (($page['pages'] ?? 1) <= 1) {
    return;
}
$q = $_GET;
$link = function (int $p) use ($q): string {
    $q['page'] = $p;
    return '?' . http_build_query($q);
};
$cur = (int) $page['page'];
$last = (int) $page['pages'];
?>
<nav class="pagination" aria-label="Σελίδες">
  <?php if ($cur > 1): ?><a href="<?= e($link($cur - 1)) ?>" aria-label="Προηγούμενη"><?= icon('chevron-left', 16) ?></a><?php endif; ?>
  <?php for ($p = max(1, $cur - 2); $p <= min($last, $cur + 2); $p++): ?>
    <?php if ($p === $cur): ?><span class="cur" aria-current="page"><?= $p ?></span><?php else: ?><a href="<?= e($link($p)) ?>"><?= $p ?></a><?php endif; ?>
  <?php endfor; ?>
  <?php if ($cur < $last): ?><a href="<?= e($link($cur + 1)) ?>" aria-label="Επόμενη"><?= icon('chevron-right', 16) ?></a><?php endif; ?>
  <span class="muted small" style="border:0"><?= (int) $page['total'] ?> συνολικά</span>
</nav>
