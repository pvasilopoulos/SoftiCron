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
    ['templates', 'templates', 'Templates μηνυμάτων', 'templates', 'templates.manage'],
    ['connectors', 'connectors', 'Connectors', 'connectors', 'connectors.manage'],
    ['notifications', 'notifications', 'Ειδοποιήσεις', 'bell', null],
];
$admin = [
    ['users', 'users', 'Χρήστες', 'users', 'users.manage'],
    ['roles', 'roles', 'Ρόλοι', 'roles', 'roles.manage'],
    ['audit', 'audit', 'Audit log', 'audit', 'audit.view'],
    ['settings', 'settings', 'Ρυθμίσεις', 'settings', 'settings.manage'],
];
$tenants = Auth::tenants();
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
      <form method="post" action="<?= e(url('tenant/switch')) ?>" class="tenant-switch">
        <?= csrf_field() ?>
        <label class="sr-only" for="tenant-switch">Αλλαγή οργανισμού</label>
        <select id="tenant-switch" name="tenant_id" class="select" data-autosubmit>
          <?php
          $list = Auth::isSuperAdmin() ? App\Core\App::db()->all('SELECT id, name FROM tenants ORDER BY name') : $tenants;
          foreach ($list as $t): ?>
            <option value="<?= (int) $t['id'] ?>" <?= $tenant && (int) $t['id'] === (int) $tenant['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    <?php endif; ?>
    <nav class="nav">
      <?php foreach ($items as $it) { if ($it[4] === null || Auth::can($it[4])) { echo $link($it); } } ?>
      <?php $adminVisible = array_filter($admin, fn ($it) => Auth::can($it[4])); if ($adminVisible): ?>
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
      <a class="btn btn-icon" href="<?= e(url('notifications')) ?>" aria-label="Ειδοποιήσεις" title="Ειδοποιήσεις"><?= icon('bell') ?></a>
      <?php if (Auth::can('jobs.manage')): ?>
        <a class="btn btn-primary" href="<?= e(url('jobs/new')) ?>"><?= icon('plus', 16) ?>Νέο job</a>
      <?php endif; ?>
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
  <a href="<?= e(url('/')) ?>" class="<?= $nav === 'dashboard' ? 'active' : '' ?>"><?= icon('home', 22) ?>Αρχική</a>
  <?php if (Auth::can('jobs.view')): ?><a href="<?= e(url('jobs')) ?>" class="<?= $nav === 'jobs' ? 'active' : '' ?>"><?= icon('jobs', 22) ?>Jobs</a><?php endif; ?>
  <?php if (Auth::can('runs.view')): ?><a href="<?= e(url('runs')) ?>" class="<?= $nav === 'runs' ? 'active' : '' ?>"><?= icon('runs', 22) ?>Εκτελέσεις</a><?php endif; ?>
  <a href="<?= e(url('notifications')) ?>" class="<?= $nav === 'notifications' ? 'active' : '' ?>"><?= icon('bell', 22) ?>Alerts</a>
  <button type="button" data-drawer-open><?= icon('menu', 22) ?>Μενού</button>
</nav>
<div class="toast-wrap" id="toasts" aria-live="polite"></div>
</body>
</html>
