<?php
declare(strict_types=1);

/**
 * Page frame of the Super Admin (platform) pages: the same look as the company pages, with the platform's
 * own menu. No company database is open on these pages.
 */

const PLATFORM_MENU = [
    'Companies' => [
        'companies' => ['Companies', 'buildings'],
        'new'       => ['New company', 'plus-square'],
        'updates'   => ['Database updates', 'database-check'],
    ],
    'Platform' => [
        'admins'    => ['Super Admins', 'shield-lock'],
        'settings'  => ['Platform settings', 'sliders'],
        'audit'     => ['Platform audit log', 'journal-text'],
        'account'   => ['My account', 'person-circle'],
    ],
];

function platform_start(string $title, array $opt = []): void
{
    $sa = Platform::admin();
    $current = (string)($GLOBALS['PLATFORM_PAGE'] ?? '');
    $app = app_name();
    ?>
<!doctype html>
<html lang="en" data-section="platform">
<head>
<?= gs_head($title . ' - Platform - ' . $app) ?>
</head>
<body class="gs-app platform">
<div class="gs-shell" id="gsShell">
  <nav class="gs-rail no-print" aria-label="Sections">
    <a class="gs-rail-brand" href="<?= h(purl()) ?>" title="<?= h($app) ?>"><?= gs_brand_mark() ?></a>
    <div class="gs-rail-list">
      <button type="button" class="gs-rail-btn is-active" data-gs-section="platform" aria-pressed="true"><i class="bi bi-shield-lock"></i><span>Platform</span></button>
    </div>
  </nav>
  <aside class="gs-panel no-print" id="gsPanel" aria-label="Platform pages">
    <div class="gs-panel-head"><span class="gs-panel-company">Platform administration</span>
      <button type="button" class="gs-icon-btn gs-panel-close" data-gs-drawer-close aria-label="Close the menu"><i class="bi bi-x-lg"></i></button></div>
    <div class="gs-panel-section" data-gs-panel="platform">
      <div class="gs-panel-title">Platform</div>
      <?php foreach (PLATFORM_MENU as $group => $items): ?>
      <details class="gs-mod" open><summary><i class="bi bi-<?= $group === 'Companies' ? 'buildings' : 'sliders' ?>"></i><span><?= h($group) ?></span></summary>
        <?php foreach ($items as $k => [$name, $icon]): ?>
          <a class="gs-link<?= $k === $current ? ' is-current' : '' ?>" href="<?= h(purl($k)) ?>"<?= $k === $current ? ' aria-current="page"' : '' ?>><?= h($name) ?></a>
        <?php endforeach; ?>
      </details>
      <?php endforeach; ?>
    </div>
  </aside>
  <div class="gs-scrim" data-gs-drawer-close></div>
  <div class="gs-body">
    <header class="gs-top no-print">
      <button type="button" class="gs-icon-btn gs-panel-toggle" data-gs-panel-toggle aria-label="Show or hide the pages"><i class="bi bi-layout-sidebar"></i></button>
      <a class="gs-top-brand" href="<?= h(purl()) ?>"><?= gs_brand_mark('is-small') ?><span><?= h($app) ?></span></a>
      <span class="gs-company-name d-none d-md-inline">Platform administration</span>
      <div class="gs-top-spacer"></div>
      <?php if ($sa): ?>
      <div class="dropdown">
        <button class="gs-user dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><span class="gs-avatar" aria-hidden="true"><?= h(mb_strtoupper(mb_substr(trim((string)$sa['full_name']), 0, 1))) ?></span><span class="gs-user-name"><?= h($sa['full_name']) ?></span></button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><span class="dropdown-item-text small text-muted">Super Admin</span></li>
          <li><a class="dropdown-item" href="<?= h(purl('account')) ?>"><i class="bi bi-key"></i> Change password</a></li>
          <?php if (Manual::available()): ?>
          <li><a class="dropdown-item" href="<?= h(Manual::url()) ?>" target="_blank" rel="noopener"><i class="bi bi-book"></i> User's Manual</a></li>
          <?php endif; ?>
          <li><hr class="dropdown-divider"></li>
          <li><form method="post" action="<?= h(purl('companies', ['a' => 'logout'])) ?>"><?= csrf_field() ?><button class="dropdown-item"><i class="bi bi-box-arrow-right"></i> Log out</button></form></li>
        </ul>
      </div>
      <?php endif; ?>
    </header>
    <main class="gs-main main-area" id="gsMain" tabindex="-1">
    <?php foreach (take_flash() as [$type, $msg]): ?>
      <div class="alert alert-<?= h($type) ?> alert-dismissible fade show no-print" role="alert"><?= h($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
    <?php endforeach; ?>
    <div class="page-bar">
      <div class="page-bar-title"><h1><?= h($title) ?></h1></div>
      <div class="page-actions no-print"><?= $opt['actions'] ?? '' ?><?= $sa ? Manual::helpButton(Manual::forPlatform($current)) : '' ?></div>
    </div>
    <?= form_error() ?>
<?php
}

function platform_end(): void
{
    ?>
    </main>
  </div>
  <nav class="gs-dock no-print" aria-label="Platform"><button type="button" class="gs-dock-btn" data-gs-section="platform" data-gs-drawer-open><i class="bi bi-shield-lock"></i><span>Platform</span></button></nav>
</div>
<script src="<?= h(vendor_asset('bootstrap.js')) ?>"></script>
<script src="<?= h(asset('js/app.js')) ?>"></script>
<script src="<?= h(asset('js/shell.js')) ?>"></script>
</body>
</html>
<?php
}

/** Company status in words, with a colour cue (plain text, no badge) */
function platform_status(string $status): string
{
    $t = ['active' => 'Active', 'inactive' => 'Inactive', 'provisioning' => 'Being set up', 'failed' => 'Set-up failed'][$status] ?? ucfirst($status);
    return '<span class="co-state co-' . h($status) . '">' . h($t) . '</span>';
}
