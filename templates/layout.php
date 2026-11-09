<?php
/** @var string $content */
use App\Auth\Auth;
use App\Core\Session;

$tenant = Auth::tenant();
$user = Auth::user();
$nav = $nav ?? '';
$failing = $tenant ? (int) App\Core\App::db()->value("SELECT COUNT(*) FROM jobs WHERE tenant_id = ? AND enabled = 1 AND last_status = 'failed'", [$tenant['id']]) : 0;
$items = [
    ['dashboard', '/', 'Επισκόπηση', 'dashboard', null],
    ['jobs', 'jobs', 'Jobs', 'jobs', 'jobs.view'],
    ['runs', 'runs', 'Εκτελέσεις', 'runs', 'runs.view'],
    ['reports', 'reports', 'Αναφορές & SLA', 'dashboard', 'reports.view'],
    ['templates', 'templates', 'Templates μηνυμάτων', 'templates', 'templates.view'],
    ['connectors', 'connectors', 'Connectors', 'connectors', 'connectors.view'],
    ['notifications', 'notifications', 'Ειδοποιήσεις', 'bell', null],
];
$admin = [
    ['variables', 'variables', 'Μεταβλητές & secrets', 'lock', 'variables.view'],
    ['api', 'api-tokens', 'API', 'key', 'api.manage'],
    ['users', 'users', 'Χρήστες', 'users', 'users.view'],
    ['roles', 'roles', 'Ρόλοι', 'roles', 'roles.manage'],
    ['audit', 'audit', 'Audit log', 'audit', 'audit.view'],
    ['settings', 'settings', 'Ρυθμίσεις', 'settings', \App\Controllers\SettingsController::SETTINGS_PERMS],
];
$tenants = Auth::tenants();
$feedUnread = $tenant ? App\Services\InAppFeed::unread((int) Auth::id(), (int) $tenant['id']) : 0;
$logoUrl = $tenant && $tenant['logo_path'] ? url('branding/' . $tenant['id'] . '/logo') . '?v=' . rawurlencode((string) $tenant['logo_path']) : null;
$link = function (array $it) use ($nav, $failing): string {
    [$key, $href, $label, $ic] = $it;
    $cls = $nav === $key ? ' class="active" aria-current="page"' : '';
    $count = $key === 'jobs' && $failing ? '<span class="count">' . $failing . '</span>' : '';
    return '<a href="' . e(url($href)) . '"' . $cls . ' title="' . e($label) . '">' . icon($ic) . '<span>' . e($label) . '</span>' . $count . '</a>';
};
echo App\Core\View::partial('partials/head', ['title' => $title ?? '']);
?>
<body>
<div class="app">
  <aside class="sidebar" id="sidebar" aria-label="Κύρια πλοήγηση">
    <div class="brand">
      <div class="logo"><?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt=""><?php else: ?><?= e(initials($tenant['name'] ?? 'C A')) ?><?php endif; ?></div>
      <div class="brand-text">
        <strong><?= e($tenant['name'] ?? App\Core\App::name()) ?></strong>
        <span><?= e(Auth::roleName() ?? '') ?></span>
      </div>
    </div>
    <?php if (count($tenants) > 1 || Auth::isSuperAdmin()): ?>
      <?php
      $list = Auth::isSuperAdmin()
          ? App\Core\App::db()->all("SELECT t.id, t.name, t.brand_color, t.logo_path, COALESCE(r.name, 'Super admin') AS role FROM tenants t LEFT JOIN memberships m ON m.tenant_id = t.id AND m.user_id = ? LEFT JOIN roles r ON r.id = m.role_id ORDER BY t.name", [Auth::id()])
          : $tenants;
      $tenantUnread = App\Services\InAppFeed::unreadByTenant((int) Auth::id());
      $otherUnread = array_sum(array_diff_key($tenantUnread, [(int) ($tenant['id'] ?? 0) => 0]));
      $cur = $tenant ?: ($list[0] ?? null);
      $tAvatar = function (array $t, int $size = 28): string {
          $logo = $t['logo_path'] ? url('branding/' . $t['id'] . '/logo') . '?v=' . rawurlencode((string) $t['logo_path']) : null;
          return '<span class="ts-avatar" style="width:' . $size . 'px;height:' . $size . 'px;background:' . e($t['brand_color'] ?: '#4F46E5') . '">'
              . ($logo ? '<img src="' . e($logo) . '" alt="">' : e(initials((string) $t['name']))) . '</span>';
      };
      ?>
      <form method="post" action="<?= e(url('tenant/switch')) ?>" class="tenant-switch">
        <?= csrf_field() ?>
        <details class="ts" data-tenant-switch>
          <summary class="ts-current" aria-label="Αλλαγή οργανισμού<?= $otherUnread ? ' (' . $otherUnread . ' μη αναγνωσμένες ειδοποιήσεις σε άλλους οργανισμούς)' : '' ?>">
            <?php if ($cur): ?><?= $tAvatar($cur, 24) ?><span class="ts-name"><?= e($cur['name']) ?></span><?php endif; ?>
            <span class="ts-badge ts-others" data-ts-others title="Μη αναγνωσμένες σε άλλους οργανισμούς"<?= $otherUnread ? '' : ' hidden' ?>><?= $otherUnread > 99 ? '99+' : $otherUnread ?></span>
            <svg class="ts-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
          </summary>
          <div class="ts-menu" role="listbox" aria-label="Οργανισμοί">
            <?php if (count($list) > 6): ?><div class="ts-search"><input type="search" placeholder="Αναζήτηση οργανισμού…" aria-label="Αναζήτηση οργανισμού" data-ts-search></div><?php endif; ?>
            <div class="ts-list">
              <?php foreach ($list as $t): $n = (int) ($tenantUnread[(int) $t['id']] ?? 0); $isCur = $tenant && (int) $t['id'] === (int) $tenant['id']; ?>
                <button type="submit" name="tenant_id" value="<?= (int) $t['id'] ?>" class="ts-opt<?= $isCur ? ' on' : '' ?>" role="option" aria-selected="<?= $isCur ? 'true' : 'false' ?>" data-ts-name="<?= e(mb_strtolower((string) $t['name'])) ?>" aria-label="<?= e($t['name'] . ($n ? ' · ' . $n . ' μη αναγνωσμένες' : '')) ?>">
                  <?= $tAvatar($t) ?>
                  <span class="ts-text"><span class="ts-tname"><?= e($t['name']) ?></span><span class="ts-role"><?= e($t['role'] ?? '') ?></span></span>
                  <span class="ts-badge" data-ts-count="<?= (int) $t['id'] ?>"<?= $n ? '' : ' hidden' ?>><?= $n > 99 ? '99+' : $n ?></span>
                  <?php if ($isCur): ?><svg class="ts-check" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg><?php endif; ?>
                </button>
              <?php endforeach; ?>
            </div>
          </div>
        </details>
      </form>
    <?php endif; ?>
    <nav class="nav">
      <?php foreach ($items as $it) { if ($it[4] === null || Auth::canAny((array) $it[4])) { echo $link($it); } } ?>
      <?php $adminVisible = array_filter($admin, fn ($it) => Auth::canAny((array) $it[4])); if ($adminVisible): ?>
        <div class="nav-label">Διαχείριση</div>
        <?php foreach ($adminVisible as $it) { echo $link($it); } ?>
      <?php endif; ?>
      <?php if (Auth::isSuperAdmin()): ?>
        <div class="nav-label">Πλατφόρμα</div>
        <?= $link(['admin', 'admin/tenants', 'Tenants', 'building']) ?>
        <?= $link(['system', 'admin/system', 'Κατάσταση συστήματος', 'refresh']) ?>
      <?php endif; ?>
    </nav>
    <div class="side-user">
      <a class="avatar" href="<?= e(url('profile')) ?>" title="Το προφίλ μου"><?= e(initials($user['name'] ?? '')) ?></a>
      <div class="who">
        <strong><?= e($user['name'] ?? '') ?></strong>
        <span><?= e($user['email'] ?? '') ?></span>
      </div>
      <form method="post" action="<?= e(url('logout')) ?>">
        <?= csrf_field() ?>
        <button class="btn btn-ghost btn-icon" type="submit" aria-label="Αποσύνδεση" title="Αποσύνδεση"><?= icon('logout') ?></button>
      </form>
    </div>
  </aside>
  <div class="drawer-backdrop" data-drawer-close></div>

  <div class="main">
    <header class="mobile-header">
      <div class="logo" style="width:32px;height:32px;border-radius:9px;font-size:12px"><?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt=""><?php else: ?><?= e(initials($tenant['name'] ?? 'C A')) ?><?php endif; ?></div>
      <span class="title"><?= e($title ?? '') ?></span>
      <?php if ($tenant): ?><button class="btn btn-icon btn-sm feed-bell" type="button" data-feed-toggle aria-label="Ειδοποιήσεις<?= $feedUnread ? ' (' . $feedUnread . ' νέες)' : '' ?>" aria-haspopup="dialog" aria-expanded="false" title="Ειδοποιήσεις"><?= icon('bell') ?><span class="feed-badge" data-feed-badge<?= $feedUnread ? '' : ' hidden' ?>><?= $feedUnread > 99 ? '99+' : $feedUnread ?></span></button><?php endif; ?>
      <button class="btn btn-icon btn-sm" type="button" data-theme-toggle aria-label="Αλλαγή θέματος"><?= icon('moon') ?></button>
    </header>
    <div class="topbar">
      <form class="searchbar" action="<?= e(url('jobs')) ?>" role="search">
        <?= icon('search', 16) ?>
        <label class="sr-only" for="global-search">Αναζήτηση jobs</label>
        <input id="global-search" name="q" placeholder="Αναζήτηση jobs…" value="<?= e($_GET['q'] ?? '') ?>">
        <kbd class="xs muted">Ctrl K</kbd>
      </form>
      <div class="grow"></div>
      <button class="btn btn-icon" type="button" data-theme-toggle aria-label="Αλλαγή θέματος" title="Φωτεινό / σκοτεινό θέμα"><?= icon('moon') ?></button>
      <?php if ($tenant): ?><button class="btn btn-icon feed-bell" type="button" data-feed-toggle aria-label="Ειδοποιήσεις<?= $feedUnread ? ' (' . $feedUnread . ' νέες)' : '' ?>" aria-haspopup="dialog" aria-expanded="false" title="Ειδοποιήσεις"><?= icon('bell') ?><span class="feed-badge" data-feed-badge<?= $feedUnread ? '' : ' hidden' ?>><?= $feedUnread > 99 ? '99+' : $feedUnread ?></span></button><?php else: ?><a class="btn btn-icon" href="<?= e(url('notifications')) ?>" aria-label="Ειδοποιήσεις" title="Ειδοποιήσεις"><?= icon('bell') ?></a><?php endif; ?>
    </div>
    <main class="content" id="content">
      <?php foreach (Session::takeFlash() as $f): ?>
        <div class="flash <?= e($f['type']) ?>" role="<?= $f['type'] === 'error' ? 'alert' : 'status' ?>"><?= icon($f['type'] === 'error' ? 'x' : 'check', 18) ?><span><?= e($f['message']) ?></span></div>
      <?php endforeach; ?>
      <?= $content ?>
    </main>
  </div>
</div>

<nav class="bottom-nav" aria-label="Γρήγορη πλοήγηση">
  <?php foreach (App\Services\BottomNav::forCurrentUser() as $bn): ?>
    <a href="<?= e($bn['url']) ?>" class="<?= $nav === $bn['nav'] ? 'active' : '' ?>"<?= $nav === $bn['nav'] ? ' aria-current="page"' : '' ?>><?= icon($bn['icon'], 22) ?><?= e($bn['label']) ?></a>
  <?php endforeach; ?>
  <button type="button" data-drawer-open><?= icon('menu', 22) ?>Μενού</button>
</nav>
<?php if ($tenant): ?>
<div class="feed-backdrop" data-feed-close hidden></div>
<section id="feed-panel" class="feed-panel" role="dialog" aria-modal="false" aria-labelledby="feed-title" hidden>
  <header class="feed-head">
    <div class="row" style="gap:8px;min-width:0">
      <h2 id="feed-title" tabindex="-1">Ειδοποιήσεις</h2>
      <span class="badge run" data-feed-count hidden></span>
    </div>
    <div class="row" style="gap:4px;flex-wrap:nowrap">
      <button class="btn btn-sm btn-ghost" type="button" data-feed-readall title="Σήμανση όλων ως αναγνωσμένες"><?= icon('check-all', 16) ?><span class="feed-hide-xs">Όλες αναγνωσμένες</span></button>
      <details class="feed-more">
        <summary class="btn btn-sm btn-icon btn-ghost" aria-label="Περισσότερες ενέργειες">⋯</summary>
        <div class="feed-menu">
          <button type="button" data-feed-clear><?= icon('trash', 14) ?>Διαγραφή αναγνωσμένων</button>
          <a href="<?= e(url('notifications')) ?>"><?= icon('settings', 14) ?>Προτιμήσεις & συσκευές</a>
          <?php if (Auth::can('notifications.history')): ?><a href="<?= e(url('notifications/history')) ?>"><?= icon('audit', 14) ?>Ιστορικό αποστολών</a><?php endif; ?>
        </div>
      </details>
      <button class="btn btn-sm btn-icon btn-ghost" type="button" data-feed-close aria-label="Κλείσιμο"><?= icon('x', 16) ?></button>
    </div>
  </header>
  <div class="feed-tabs" role="tablist" aria-label="Φίλτρο ειδοποιήσεων">
    <button type="button" role="tab" aria-selected="true" data-feed-filter="all">Όλες</button>
    <button type="button" role="tab" aria-selected="false" data-feed-filter="unread">Μη αναγνωσμένες</button>
    <button type="button" role="tab" aria-selected="false" data-feed-filter="error">Σφάλματα</button>
    <button type="button" role="tab" aria-selected="false" data-feed-filter="warning">Προειδοποιήσεις</button>
    <button type="button" role="tab" aria-selected="false" data-feed-filter="success">Ανακάμψεις</button>
  </div>
  <div class="feed-search"><?= icon('search', 14) ?><input type="search" data-feed-q placeholder="Αναζήτηση…" aria-label="Αναζήτηση ειδοποιήσεων"></div>
  <div class="feed-list" data-feed-list aria-live="polite"></div>
  <footer class="feed-foot">
    <span class="xs muted">Κρατιούνται <?= App\Services\InAppFeed::KEEP_DAYS ?> ημέρες</span>
    <a class="small" href="<?= e(url('notifications')) ?>">Τι ειδοποιήσεις λαμβάνω;</a>
  </footer>
</section>
<script src="<?= e(asset('feed.js')) ?>" defer></script>
<?php endif; ?>
<div class="toast-wrap" id="toasts" aria-live="polite"></div>
</body>
</html>
