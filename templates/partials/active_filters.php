<?php
/**
 * Shows the active filters as removable chips.
 * @var array $chips list of [label, url-without-this-filter]
 * @var string $clear url that removes all filters
 */
if (!$chips) {
    return;
}
?>
<div class="filter-chips" aria-label="Ενεργά φίλτρα">
  <span class="xs muted">Φίλτρα:</span>
  <?php foreach ($chips as [$label, $href]): ?>
    <a class="fchip" href="<?= e($href) ?>" title="Αφαίρεση φίλτρου"><?= e($label) ?><span class="x" aria-hidden="true">×</span><span class="sr-only">αφαίρεση</span></a>
  <?php endforeach; ?>
  <a class="xs" href="<?= e($clear) ?>" style="margin-left:4px">Καθαρισμός όλων</a>
</div>
