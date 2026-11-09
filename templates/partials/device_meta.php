<?php
/** @var array $d push_subscriptions row @var string $tz */
use App\Services\Devices;

$healthy = (int) ($d['fail_count'] ?? 0) === 0;
?>
<span class="device-meta">
  <?php if (!empty($d['username'])): ?><span class="tag" title="Χρήστης που καταχώρισε τη συσκευή"><?= icon('user', 11) ?> <?= e($d['username']) ?><?= !empty($d['user_display']) ? ' · ' . e($d['user_display']) : '' ?></span><?php endif; ?>
  <span class="tag" title="<?= e($d['full_user_agent'] ?? '') ?>"><?= e(trim(($d['platform'] ?? '') . ' · ' . ($d['browser'] ?? ''), ' ·') ?: ($d['user_agent'] ?: 'Συσκευή')) ?></span>
  <span class="tag" title="Υπηρεσία push"><?= e(Devices::PROVIDERS[$d['provider'] ?? 'other'] ?? 'Άλλος') ?></span>
  <?php if (!empty($d['is_pwa'])): ?><span class="tag" style="color:var(--accent-text)" title="Εγκατεστημένη στην αρχική οθόνη">PWA</span><?php endif; ?>
  <?php if (!empty($d['language']) || !empty($d['timezone'])): ?><span class="tag" title="Γλώσσα · ζώνη ώρας"><?= e(trim(($d['language'] ?? '') . ' · ' . ($d['timezone'] ?? ''), ' ·')) ?></span><?php endif; ?>
  <?php if (!empty($d['screen'])): ?><span class="tag" title="Ανάλυση οθόνης"><?= e($d['screen']) ?></span><?php endif; ?>
</span>
<span class="xs muted">
  Εγγραφή <?= e(fmt_dt($d['created_at'], $tz, 'd/m/Y')) ?><?= !empty($d['created_ip']) ? ' από ' . e($d['created_ip']) : '' ?>
  · τελευταία χρήση <?= e(!empty($d['last_seen_at']) ? rel_time($d['last_seen_at']) : '—') ?><?= !empty($d['last_ip']) ? ' (' . e($d['last_ip']) . ')' : '' ?>
  · τελευταία αποστολή <?= e($d['last_success_at'] ? rel_time($d['last_success_at']) : 'ποτέ') ?>
</span>
<?php if (!$healthy): ?>
  <span class="xs" style="color:var(--fail-fg)"><?= (int) $d['fail_count'] ?> αποτυχίες στη σειρά · <?= e(rel_time($d['last_error_at'])) ?>: <?= e(mb_strimwidth((string) $d['last_error'], 0, 140, '…')) ?></span>
<?php endif; ?>
