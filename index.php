<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/views/layout.php';

verify_csrf();

$route = (string)($_GET['p'] ?? 'home.dashboard');
if (!preg_match('/^[a-z_]+\.[a-z0-9_]+$/', $route)) {
    $route = 'home.dashboard';
}

// several companies and none open in this session: the Super Admin goes to the platform pages, anyone else logs in
if (Tenant::isMulti() && !Tenant::id()) {
    redirect(Platform::admin() ? purl() : base_url() . '/login.php');
}

Auth::requireLogin($route);
// users without the Home page (the client and subcontractor portals) start on their own page
if ($route === 'home.dashboard' && !Access::page('home.dashboard')) {
    $me = Auth::user() ?? [];
    foreach ([!empty($me['portal_customer_id']) ? 'portal.client' : '', !empty($me['portal_vendor_id']) ? 'portal.sub' : '', 'appr.inbox', 'proj.center'] as $alt) {
        if ($alt !== '' && Access::page($alt)) {
            redirect(url($alt));
        }
    }
}
$GLOBALS['CURRENT_PAGE'] = $route;

// The program files are newer than the database: stop with clear instructions instead of failing half-way through a save.
if ($route !== 'auth.logout' && ($pendingSql = Schema::pending())) {
    http_response_code(503);
    layout_start('Database update needed', ['bare' => true]);
    ?>
    <div class="card mx-auto mt-4" style="max-width:720px"><div class="card-body">
      <p>The program files were updated, and the database needs the matching update before work can continue. No data is lost, and nothing has been changed yet.</p>
      <?php if (Tenant::platformSession()): ?>
      <p>Bring this company's database up to date from the platform: <strong>Companies &gt; Update databases</strong>, then open the company again.</p>
      <a class="btn btn-primary btn-sm" href="<?= h(purl('companies', ['a' => 'leave'])) ?>"><i class="bi bi-arrow-left"></i> Back to the platform</a>
      <?php elseif (!Tenant::isPrimary()): ?>
      <p>Please ask the platform administrator (Super Admin) to update this company's database, then reload this page.</p>
      <?php elseif (Access::isAdmin()): ?>
      <ol class="mb-3">
        <li>Back up the database first (phpMyAdmin or Navicat, Export / Dump SQL File).</li>
        <li>Open the <strong><?= h((string)($GLOBALS['CONFIG']['db']['name'] ?? '')) ?></strong> database and run <?= count($pendingSql) > 1 ? 'these SQL files from the program folder, in this order' : 'this SQL file from the program folder' ?>
          (phpMyAdmin: Import. Navicat: Execute SQL File):
          <ul class="mt-1"><?php foreach ($pendingSql as $sqlFile): ?><li><code><?= h($sqlFile) ?></code></li><?php endforeach; ?></ul></li>
        <li>Reload this page.</li>
      </ol>
      <p class="small text-muted mb-3">The files are written for MySQL 5.5 and newer, keep all existing records, and can safely be run again.</p>
      <?php else: ?>
      <p>Please ask your system administrator to run the database update, then reload this page.</p>
      <?php endif; ?>
      <a class="btn btn-primary btn-sm" href="<?= h(current_url()) ?>"><i class="bi bi-arrow-clockwise"></i> Reload</a>
      <a class="btn btn-outline-secondary btn-sm" href="<?= h(url('auth.logout')) ?>">Log out</a>
    </div></div>
    <?php
    layout_end();
    exit;
}

// Memorized transactions set to "enter automatically" are recorded on their due date, the first time
// anyone opens the system that day. Doing it here means no page can render before they are in the books.
$memKey = 'mem_auto_day' . (Tenant::id() ? ':' . Tenant::id() : '');
if ($route !== 'auth.logout' && ($_SESSION[$memKey] ?? '') !== today()) {
    $_SESSION[$memKey] = today();
    foreach (Memorized::runAutomatic() as $entered) {
        flash('info', 'Memorized transaction "' . $entered['name'] . '" was entered automatically for ' . fdate($entered['date']) . '.');
    }
}

// routes every logged-in user can open
if ($open = Access::openRoute($route)) {
    require __DIR__ . '/modules/' . $open;
    exit;
}

$def = Access::pageDef($route);
if (!$def) {
    http_response_code(404);
    layout_start('Page not found');
    echo '<p>The page you asked for does not exist.</p>';
    layout_end();
    exit;
}

// page access: denied pages are recorded in the audit trail
Access::requirePage($route);

$PAGE = $def;
$PARAMS = $def['params'];
require __DIR__ . '/modules/' . $def['file'];
