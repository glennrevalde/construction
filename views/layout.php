<?php
declare(strict_types=1);

/** Runs a POST handler; user-facing errors are shown on the re-rendered form. */
function handle_post(callable $fn): void
{
    if (!is_post()) {
        return;
    }
    // "Memorize" on a form: the transaction is put on the Memorized Transactions list instead of being saved
    Memorized::$capture = p('memorize') === '1';
    try {
        $fn();
    } catch (UserError $e) {
        if (DB::inTransaction()) {
            DB::rollBack();
        }
        Approval::release();
        $GLOBALS['FORM_ERROR'] = $e->getMessage();
        $GLOBALS['FORM_STOCK_QUESTION'] = $e instanceof StockQuestion ? $e->short : null;
        $GLOBALS['FORM_NOTICE'] = $e instanceof FormNotice;
        if ($e instanceof StockQuestion) {
            Approval::keepForAnswer();
        }
    } finally {
        Memorized::$capture = false;
    }
}

function form_error(): string
{
    $e = $GLOBALS['FORM_ERROR'] ?? null;
    if (!$e) {
        return '';
    }
    $short = $GLOBALS['FORM_STOCK_QUESTION'] ?? null;
    if ($short) {
        // the question asked by app.js (Yes saves, No goes back to the form); the same words stay on the page
        $rows = array_map(fn($s) => ['code' => $s[0], 'site' => $s[1], 'before' => qty($s[2]), 'after' => qty($s[3])], $short);
        $li = implode('', array_map(fn($r) => '<li>' . h($r['code']) . ' at ' . h($r['site']) . ': ' . h($r['before']) . ' on hand, would be left with <span class="neg">' . h($r['after']) . '</span></li>', $rows));
        return '<div class="alert alert-warning d-flex gap-2 neg-stock-alert" data-negative-stock="' . h(json_encode($rows, JSON_UNESCAPED_UNICODE)) . '">'
            . '<i class="bi bi-exclamation-triangle-fill"></i><div><strong>Saving the transaction will lead the inventory to a negative balance.</strong> Nothing has been saved yet.'
            . '<ul class="mb-1 mt-1 small">' . $li . '</ul><span class="small">Save again and answer Yes to continue, or change the quantities.</span></div></div>';
    }
    if (!empty($GLOBALS['FORM_NOTICE'])) {
        return '<div class="alert alert-success d-flex gap-2 form-notice"><i class="bi bi-bookmark-check-fill"></i><div>' . h($e) . '</div></div>';
    }
    return '<div class="alert alert-danger d-flex gap-2"><i class="bi bi-exclamation-triangle-fill"></i><div>' . h($e) . '</div></div>';
}

/** Value for a form field: posted value first, then the record's value. */
function fv(string $key, $record = null, $default = '')
{
    if (is_post() && array_key_exists($key, $_POST)) {
        return is_string($_POST[$key]) ? $_POST[$key] : $_POST[$key];
    }
    if (is_array($record) && array_key_exists($key, $record)) {
        return $record[$key] ?? $default;
    }
    return $default;
}

// =====================================================================
//  The shell (GSMaRT Construction System): a rail of sections on the left,
//  the pages of the open section beside it, and a slim top bar.
//  On a phone the rail becomes a bar at the bottom and the pages a drawer.
// =====================================================================

/** The <head> files every page of the system loads (also the log-in, join and platform pages) */
function gs_head(string $title): string
{
    return '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
        . '<meta name="theme-color" content="#14263a">'
        . '<title>' . h($title) . '</title>'
        . '<link rel="stylesheet" href="' . h(asset('css/fonts.css')) . '">'
        . '<link rel="stylesheet" href="' . h(vendor_asset('bootstrap.css')) . '">'
        . '<link rel="stylesheet" href="' . h(vendor_asset('icons.css')) . '">'
        . '<link rel="stylesheet" href="' . h(asset('css/app.css')) . '">'
        . '<link rel="stylesheet" href="' . h(asset('css/theme.css')) . '">'
        . '<script src="' . h(asset('js/fmt.js')) . '"></script>';
}

/** The brand mark: two letters on a plate with a hi-vis edge */
function gs_brand_mark(string $class = ''): string
{
    return '<span class="gs-mark ' . h($class) . '" aria-hidden="true"><span>GS</span></span>';
}

/** Which rail section a module belongs to */
function gs_section_of(string $module): string
{
    foreach ((array)(Access::registry_sections()) as $code => [, , $mods]) {
        if (in_array($module, $mods, true)) {
            return $code;
        }
    }
    return 'home';
}

/**
 * The navigation the user may see: section => [name, icon, modules => [code => [name, icon, groups => [group => [pages]]]]].
 * A page appears when the user may open it; a module with nothing to open is left out, and so is a section.
 */
function build_menu(): array
{
    $defs = Access::allPageDefs();
    $eff = Access::effective();
    $mods = Access::modules();
    $aliases = Access::menuAliases();
    $out = [];
    foreach (Access::registry_sections() as $sec => [$sname, $sicon, $codes]) {
        $modules = [];
        foreach ($codes as $code) {
            if (!isset($mods[$code])) {
                continue;
            }
            [$name, $icon] = $mods[$code];
            $groups = [];
            foreach ($defs as $k => $d) {
                if ($d['module'] !== $code || !$d['menu'] || empty($eff[$k]['allowed'])) {
                    continue;
                }
                if ($k === 'admin.multi_company' && Tenant::isMulti()) {
                    continue;
                }
                if ($k === 'company.join_requests' && Schema::ready() && ($waiting = (int)DB::value("SELECT COUNT(*) FROM users WHERE approval_status = 'pending' AND is_platform = 0"))) {
                    $d['name'] .= ' (' . $waiting . ')';
                }
                $group = $d['type'] === 'report' ? 'Reports' : (string)$d['group'];
                $groups[$group][] = ['key' => $k, 'name' => $d['name'], 'sub' => $d['group']];
            }
            // pages of the accounting modules that this module works with (purchase orders under Procurement ...)
            foreach ($aliases[$code] ?? [] as $k) {
                if (!empty($eff[$k]['allowed']) && isset($defs[$k])) {
                    $groups['From accounting'][] = ['key' => $k, 'name' => $defs[$k]['name'], 'sub' => 'From accounting', 'alias' => true];
                }
            }
            if ($groups) {
                // the reports last, the set-up pages just before them
                uksort($groups, function ($a, $b) {
                    $rank = fn($g) => $g === 'Reports' ? 3 : ($g === 'Setup' ? 2 : ($g === 'From accounting' ? 1 : 0));
                    return $rank($a) <=> $rank($b);
                });
                $modules[$code] = ['name' => $name, 'icon' => $icon, 'groups' => $groups];
            }
        }
        if ($modules) {
            $out[$sec] = ['name' => $sname, 'icon' => $sicon, 'modules' => $modules];
        }
    }
    return $out;
}

/** How many approvals wait for this user, and how many alerts are open (never stops a page from showing) */
function gs_counts(): array
{
    static $c = null;
    if ($c !== null) {
        return $c;
    }
    $c = ['approvals' => 0, 'alerts' => 0];
    try {
        if (Auth::user() && Schema::ready()) {
            if (class_exists('CsApproval') && Access::page('appr.inbox')) {
                $c['approvals'] = CsApproval::countFor((int)Auth::id());
            }
            if (class_exists('CsAlerts') && Access::page('home.alerts')) {
                $c['alerts'] = CsAlerts::count();
            }
        }
    } catch (Throwable $e) {
        error_log('gs_counts: ' . $e->getMessage());
    }
    return $c;
}

function layout_start(string $title, array $opt = []): void
{
    $GLOBALS['LAYOUT_TITLE'] = $title;
    $GLOBALS['LAYOUT_BARE'] = !empty($opt['bare']);
    // extra scripts this page needs, loaded after app.js by layout_end(): ['js' => ['js/btprint.js']]
    $GLOBALS['LAYOUT_JS'] = array_values(array_filter((array)($opt['js'] ?? [])));
    $user = Auth::user();
    $current = $GLOBALS['CURRENT_PAGE'] ?? '';
    $currentModule = Access::pageDef($current)['module'] ?? '';
    if (isset($opt['module'])) {
        $currentModule = $opt['module'];
    }
    $company = Settings::get('company_name');
    $app = app_name();
    $menu = $user && empty($opt['bare']) ? build_menu() : [];
    $section = gs_section_of($currentModule ?: 'home');
    if (!isset($menu[$section]) && $menu) {
        $section = array_key_first($menu);
    }
    $counts = $user && empty($opt['bare']) ? gs_counts() : ['approvals' => 0, 'alerts' => 0];
    $bodyClass = trim((!empty($opt['bare']) ? 'bare ' : 'gs-app ') . (string)($opt['body_class'] ?? ''));
    ?>
<!doctype html>
<html lang="en" data-section="<?= h($section) ?>">
<head>
<?= gs_head($title . ' - ' . $app) ?>
<script>try { if (localStorage.getItem('gs.panel') === 'off') document.documentElement.classList.add('gs-panel-off'); } catch (e) {}</script>
<script src="<?= h(asset('js/wht.js')) ?>"></script>
</head>
<body class="<?= h($bodyClass) ?>">
<?php if (empty($opt['bare'])): ?>
<a class="gs-skip" href="#gsMain">Skip to the page</a>
<div class="gs-shell" id="gsShell">
  <?php if ($user): ?>
  <nav class="gs-rail no-print" aria-label="Sections">
    <a class="gs-rail-brand" href="<?= h(url('home.dashboard')) ?>" title="<?= h($app) ?>"><?= gs_brand_mark() ?></a>
    <div class="gs-rail-list">
    <?php foreach ($menu as $sec => $m): ?>
      <button type="button" class="gs-rail-btn<?= $sec === $section ? ' is-active' : '' ?>" data-gs-section="<?= h($sec) ?>" aria-controls="gsPanel" aria-pressed="<?= $sec === $section ? 'true' : 'false' ?>">
        <i class="bi bi-<?= h($m['icon']) ?>"></i><span><?= h($m['name']) ?></span>
        <?php if ($sec === 'approve' && $counts['approvals']): ?><b class="gs-count"><?= $counts['approvals'] > 99 ? '99+' : (int)$counts['approvals'] ?></b><?php endif; ?>
      </button>
    <?php endforeach; ?>
    </div>
  </nav>
  <aside class="gs-panel no-print" id="gsPanel" aria-label="Pages">
    <div class="gs-panel-head">
      <span class="gs-panel-company" title="<?= h($app) ?>"><?= h($app) ?></span>
      <button type="button" class="gs-icon-btn gs-panel-close" data-gs-drawer-close aria-label="Close the menu"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="gs-panel-filter"><i class="bi bi-search"></i><input type="search" placeholder="Find a page" aria-label="Find a page" data-gs-page-filter autocomplete="off"></div>
    <?php foreach ($menu as $sec => $m): ?>
    <div class="gs-panel-section" data-gs-panel="<?= h($sec) ?>"<?= $sec === $section ? '' : ' hidden' ?>>
      <div class="gs-panel-title"><?= h($m['name']) ?></div>
      <?php foreach ($m['modules'] as $code => $mod): $single = count($m['modules']) === 1; $openMod = $code === $currentModule || $single; ?>
      <details class="gs-mod<?= $single ? ' is-single' : '' ?>"<?= $openMod || $sec !== 'acct' ? ' open' : '' ?>>
        <summary<?= $single ? ' hidden' : '' ?>><i class="bi bi-<?= h($mod['icon']) ?>"></i><span><?= h($mod['name']) ?></span></summary>
        <?php foreach ($mod['groups'] as $gname => $items): ?>
          <?php if ($gname === 'Reports'): ?>
          <details class="gs-reports"<?= ($openMod && str_starts_with($current, 'rpt.')) ? ' open' : '' ?>>
            <summary>Reports <small><?= count($items) ?></small></summary>
            <?php $lastSub = null; foreach ($items as $it): ?>
              <?php if ($it['sub'] !== 'Reports' && $it['sub'] !== $lastSub): $lastSub = $it['sub']; ?><div class="gs-sub"><?= h($it['sub']) ?></div><?php endif; ?>
              <a class="gs-link<?= $it['key'] === $current ? ' is-current' : '' ?>" href="<?= h(url($it['key'])) ?>"<?= $it['key'] === $current ? ' aria-current="page"' : '' ?>><?= h($it['name']) ?></a>
            <?php endforeach; ?>
          </details>
          <?php else: ?>
            <?php if (count($mod['groups']) > 1 && !in_array($gname, ['Home', $mod['name']], true)): ?><div class="gs-group"><?= h($gname) ?></div><?php endif; ?>
            <?php foreach ($items as $it): ?>
              <a class="gs-link<?= $it['key'] === $current ? ' is-current' : '' ?><?= !empty($it['alias']) ? ' is-alias' : '' ?>" href="<?= h(url($it['key'])) ?>"<?= $it['key'] === $current ? ' aria-current="page"' : '' ?>><?= h($it['name']) ?></a>
            <?php endforeach; ?>
          <?php endif; ?>
        <?php endforeach; ?>
      </details>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </aside>
  <div class="gs-scrim" data-gs-drawer-close></div>
  <?php endif; ?>
  <div class="gs-body">
    <header class="gs-top no-print">
      <?php if ($user): ?>
      <button type="button" class="gs-icon-btn gs-panel-toggle" data-gs-panel-toggle aria-label="Show or hide the pages" aria-controls="gsPanel"><i class="bi bi-layout-sidebar"></i></button>
      <a class="gs-top-brand" href="<?= h(url('home.dashboard')) ?>"><?= gs_brand_mark('is-small') ?><span><?= h($app) ?></span></a>
      <?php endif; ?>
      <?php $switchTo = $user ? CompanyAccess::switchable() : []; ?>
      <?php if (count($switchTo) > 1): ?>
      <div class="dropdown gs-company">
        <button class="gs-company-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Switch company"><i class="bi bi-buildings"></i><span><?= h($company) ?></span></button>
        <div class="dropdown-menu">
          <div class="dropdown-header">Your companies</div>
          <?php [$homeCo] = CompanyAccess::home(); ?>
          <?php foreach ($switchTo as $cid => $cname): $isNow = (int)$cid === (int)Tenant::id(); ?>
          <form method="post" action="<?= h(url('auth.switch_company')) ?>"><?= csrf_field() ?><input type="hidden" name="company_id" value="<?= (int)$cid ?>">
            <button class="dropdown-item" <?= $isNow ? 'disabled aria-current="true"' : '' ?>><i class="bi <?= $isNow ? 'bi-check2' : 'bi-building' ?>"></i> <?= h($cname) ?> <small class="text-muted d-block"><?= (int)$cid === (int)$homeCo ? 'Your own company' : 'Access given to you' ?></small></button></form>
          <?php endforeach; ?>
        </div>
      </div>
      <?php elseif ($user): ?>
      <span class="gs-company-name d-none d-xl-inline" title="Company"><?= h($company) ?></span>
      <?php endif; ?>
      <?php if ($user && Access::page('proj.view') && class_exists('CsProject')): $plist = CsProject::pickList(); ?>
        <?php if ($plist): ?>
        <form class="gs-project-jump d-none d-md-flex" method="get" action="<?= h(base_url() . '/index.php') ?>">
          <input type="hidden" name="p" value="proj.view">
          <select name="id" data-combo aria-label="Open a project" onchange="if(this.value)this.form.submit()"><?= options($plist, (string)($GLOBALS['CS_PROJECT_ID'] ?? ''), 'Open a project') ?></select>
        </form>
        <?php endif; ?>
      <?php endif; ?>
      <div class="gs-top-spacer"></div>
      <?php if ($user): ?>
        <?php if (Access::page('home.search')): ?>
        <form class="gs-search d-none d-lg-flex" method="get" action="<?= h(base_url() . '/index.php') ?>" role="search">
          <input type="hidden" name="p" value="home.search">
          <i class="bi bi-search"></i>
          <input type="search" name="q" placeholder="Search records" aria-label="Search clients, suppliers, items and documents">
        </form>
        <?php endif; ?>
        <?php if (Access::page('home.find')): ?>
        <a class="gs-icon-btn" href="<?= h(url('home.find')) ?>" title="Find transactions"><i class="bi bi-funnel"></i></a>
        <?php endif; ?>
        <?php if (Access::page('appr.inbox')): ?>
        <a class="gs-icon-btn<?= $counts['approvals'] ? ' has-count' : '' ?>" href="<?= h(url('appr.inbox')) ?>" title="<?= $counts['approvals'] ? $counts['approvals'] . ' waiting for your approval' : 'Nothing waits for your approval' ?>"><i class="bi bi-check2-square"></i><?php if ($counts['approvals']): ?><b class="gs-count"><?= $counts['approvals'] > 99 ? '99+' : (int)$counts['approvals'] ?></b><?php endif; ?></a>
        <?php endif; ?>
        <?php
          // preventive maintenance reminders: they stay until the tasks are acknowledged as complete
          $pmDue = Schema::ready() && AssetMaint::remindsUser() ? AssetMaint::reminders() : null;
          $GLOBALS['PM_REMINDERS'] = $pmDue;
          $alertN = (int)$counts['alerts'];
          if (Access::page('home.alerts') || $pmDue !== null):
            $bellUrl = Access::page('home.alerts') ? url('home.alerts') : url('fa.pm', ['tab' => 'due']);
            $bellN = $alertN + ($pmDue ? count($pmDue) : 0); ?>
          <a class="gs-icon-btn<?= $bellN ? ' has-count' : '' ?>" href="<?= h($bellUrl) ?>" title="<?= $bellN ? $bellN . ' alert(s) and reminder(s)' : 'No alerts' ?>"><i class="bi bi-bell<?= $bellN ? '-fill' : '' ?>"></i><?php if ($bellN): ?><b class="gs-count"><?= $bellN > 99 ? '99+' : $bellN ?></b><?php endif; ?></a>
        <?php endif; ?>
        <div class="dropdown">
          <button class="gs-user dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><span class="gs-avatar" aria-hidden="true"><?= h(mb_strtoupper(mb_substr(trim((string)$user['full_name']), 0, 1))) ?></span><span class="gs-user-name"><?= h($user['full_name']) ?></span></button>
          <ul class="dropdown-menu dropdown-menu-end">
            <?php if (Tenant::platformSession()): ?>
            <li><span class="dropdown-item-text small text-muted">Super Admin, managing this company</span></li>
            <li><a class="dropdown-item" href="<?= h(purl('companies', ['a' => 'leave'])) ?>"><i class="bi bi-box-arrow-left"></i> Back to the platform</a></li>
            <?php else: ?>
            <li><span class="dropdown-item-text small text-muted"><?= h(Tenant::isOwner() ? 'Company Owner' . (Access::roleNames() ? ', ' . implode(', ', Access::roleNames()) : '') : (implode(', ', Access::roleNames()) ?: 'No role')) ?></span></li>
            <?php if (CompanyAccess::away()): ?>
            <li><span class="dropdown-item-text small text-muted">Your own company: <?= h(CompanyAccess::homeName()) ?>. Change your password there.</span></li>
            <?php else: ?>
            <li><a class="dropdown-item" href="<?= h(url('auth.change_password')) ?>"><i class="bi bi-key"></i> Change password</a></li>
            <?php endif; ?>
            <?php endif; ?>
            <?php if (Manual::available()): ?>
            <li><a class="dropdown-item" href="<?= h(Manual::url()) ?>" target="_blank" rel="noopener"><i class="bi bi-book"></i> User's Manual</a></li>
            <?php endif; ?>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="<?= h(url('auth.logout')) ?>"><i class="bi bi-box-arrow-right"></i> Log out</a></li>
          </ul>
        </div>
      <?php endif; ?>
    </header>
    <?php if ($user && CompanyAccess::away()): ?>
    <div class="gs-strip is-company no-print"><i class="bi bi-buildings"></i><span>You are working in <strong><?= h($company) ?></strong> with access given to you. Your own company is <?= h(CompanyAccess::homeName()) ?>.</span>
      <form method="post" action="<?= h(url('auth.switch_company')) ?>"><?= csrf_field() ?><input type="hidden" name="company_id" value="<?= (int)CompanyAccess::home()[0] ?>"><button class="btn btn-link btn-sm p-0">Back to <?= h(CompanyAccess::homeName()) ?></button></form></div>
    <?php endif; ?>
    <?php if (Tenant::platformSession()): ?>
    <div class="gs-strip is-platform no-print"><i class="bi bi-shield-lock"></i><span>Super Admin: you are working inside <strong><?= h(Tenant::name()) ?></strong><?= (Tenant::company()['status'] ?? '') === 'inactive' ? ' (inactive: its users cannot log in)' : '' ?>. Everything you do here is recorded in its audit trail.</span><a href="<?= h(purl('companies', ['a' => 'leave'])) ?>">Back to the platform</a></div>
    <?php endif; ?>
    <main class="gs-main main-area" id="gsMain" tabindex="-1">
<?php else: ?>
  <main class="gs-main is-bare p-3">
<?php endif; ?>
    <?php foreach (take_flash() as [$type, $msg]): ?>
      <div class="alert alert-<?= h($type) ?> alert-dismissible fade show no-print" role="alert"><?= h($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
    <?php endforeach; ?>
    <?php if (empty($opt['no_title'])): ?>
    <div class="page-bar">
      <div class="page-bar-title">
        <h1><?= h($title) ?></h1>
        <?php if (!empty($opt['sub'])): ?><div class="page-sub"><?= $opt['sub'] ?></div><?php endif; ?>
      </div>
      <div class="page-actions no-print"><?= $opt['actions'] ?? '' ?><?= $user ? Manual::helpButton(Manual::forPage($current)) : '' ?></div>
    </div>
    <?php endif; ?>
    <?= form_error() ?>
<?php
}

function layout_end(): void
{
    $bare = !empty($GLOBALS['LAYOUT_BARE']);
    ?>
  </main>
<?php if (!$bare): ?>
  </div>
<?php if (Auth::user()): $menu = build_menu(); ?>
  <nav class="gs-dock no-print" aria-label="Quick sections">
    <?php foreach (['home', 'projects', 'field', 'approve'] as $sec): if (!isset($menu[$sec])) { continue; } $c = $sec === 'approve' ? gs_counts()['approvals'] : 0; ?>
      <button type="button" class="gs-dock-btn" data-gs-section="<?= h($sec) ?>" data-gs-drawer-open><i class="bi bi-<?= h($menu[$sec]['icon']) ?>"></i><span><?= h($menu[$sec]['name']) ?></span><?php if ($c): ?><b class="gs-count"><?= (int)$c ?></b><?php endif; ?></button>
    <?php endforeach; ?>
    <button type="button" class="gs-dock-btn" data-gs-drawer-open data-gs-all><i class="bi bi-grid-3x3-gap"></i><span>All</span></button>
  </nav>
<?php endif; ?>
</div>
<?php endif; ?>
<?php
    // Pop-up with the maintenance tasks that are due. Shown again whenever the list of due tasks changes or a new day
    // starts, and after every log-in; the bell keeps the count in between. Only completing a task removes it.
    $pmDue = !$bare && !is_post() ? ($GLOBALS['PM_REMINDERS'] ?? null) : null;
    $pmSig = $pmDue ? today() . ':' . implode(',', array_map(fn($r) => $r['id'], $pmDue)) : '';
    if ($pmDue && ($GLOBALS['CURRENT_PAGE'] ?? '') !== 'fa.pm' && ($_SESSION['pm_popup'] ?? '') !== $pmSig):
        $_SESSION['pm_popup'] = $pmSig; ?>
<div class="modal fade" id="pmReminderModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
  <div class="modal-header"><span class="class-confirm-ico me-2"><i class="bi bi-bell"></i></span><h5 class="modal-title">Maintenance due: <?= count($pmDue) ?> task<?= count($pmDue) === 1 ? '' : 's' ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div class="reminder-list">
    <?php foreach (array_slice($pmDue, 0, 40) as $r): ?>
      <div class="reminder-item"><div><strong><?= h($r['task_name']) ?></strong><div class="small text-muted"><?= h($r['asset_no'] . ' - ' . $r['asset_description']) ?><?= $r['asset_location'] ? ', ' . h($r['asset_location']) : '' ?></div></div>
        <div class="text-end text-nowrap"><span class="pm-state <?= h($r['state']) ?>"><?= h($r['state_text']) ?></span><div class="small text-muted"><?= h(fdate($r['next_due_date'])) ?></div></div></div>
    <?php endforeach; ?>
  </div><p class="small text-muted mt-2 mb-0">These reminders stay until each task is acknowledged as complete in Preventive Maintenance.</p></div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Later</button><a class="btn btn-primary" href="<?= h(url('fa.pm', ['tab' => 'due'])) ?>"><i class="bi bi-calendar-check"></i> Open Preventive Maintenance</a></div>
</div></div></div>
<?php endif; ?>
<script src="<?= h(vendor_asset('bootstrap.js')) ?>"></script>
<script src="<?= h(asset('js/app.js')) ?>"></script>
<script src="<?= h(asset('js/shell.js')) ?>"></script>
<script src="<?= h(asset('js/cs.js')) ?>"></script>
<?php foreach ((array)($GLOBALS['LAYOUT_JS'] ?? []) as $js): ?>
<script src="<?= h(asset($js)) ?>"></script>
<?php endforeach; ?>
<script>(function () { var m = document.getElementById('pmReminderModal'); if (m && window.bootstrap) new bootstrap.Modal(m).show(); })();</script>
</body>
</html>
<?php
}

/** Bootstrap button link, shown only if allowed */
function btn_link(string $href, string $label, string $class = 'btn-outline-secondary', string $icon = ''): string
{
    return '<a class="btn btn-sm ' . h($class) . '" href="' . h($href) . '">' . ($icon ? '<i class="bi bi-' . h($icon) . '"></i> ' : '') . h($label) . '</a>';
}

function page_link(string $pageKey, array $params, string $label, string $class = 'btn-outline-secondary', string $icon = '', string $action = 'view'): string
{
    if (!Access::can($pageKey, $action)) {
        return '';
    }
    return btn_link(url($pageKey, $params), $label, $class, $icon);
}

/** A link that is plain text when the user may not open the target page */
function safe_link(string $pageKey, array $params, string $text): string
{
    if (!Access::page($pageKey)) {
        return h($text);
    }
    return '<a href="' . h(url($pageKey, $params)) . '">' . h($text) . '</a>';
}

function select_field(string $name, string $label, array $opts, $value, array $attr = []): string
{
    $blank = $attr['blank'] ?? null;
    $cls = $attr['class'] ?? 'col-md-4';
    $req = !empty($attr['required']) ? ' required' : '';
    $extra = $attr['extra'] ?? '';
    return '<div class="' . h($cls) . '"><label class="form-label">' . h($label) . '</label>'
        . '<select class="form-select form-select-sm" name="' . h($name) . '"' . $req . ' ' . $extra . '>' . options($opts, $value, $blank) . '</select></div>';
}

function input_field(string $name, string $label, $value, array $attr = []): string
{
    $type = $attr['type'] ?? 'text';
    $cls = $attr['class'] ?? 'col-md-4';
    $req = !empty($attr['required']) ? ' required' : '';
    $extra = $attr['extra'] ?? '';
    $step = $type === 'number' ? ' step="' . h($attr['step'] ?? '0.01') . '"' : '';
    return '<div class="' . h($cls) . '"><label class="form-label">' . h($label) . '</label>'
        . '<input class="form-control form-control-sm" type="' . h($type) . '" name="' . h($name) . '" value="' . h($value) . '"' . $req . $step . ' ' . $extra . '></div>';
}

function check_field(string $name, string $label, $checked, string $cls = 'col-md-4'): string
{
    return '<div class="' . h($cls) . ' d-flex align-items-end"><div class="form-check">'
        . '<input type="hidden" name="' . h($name) . '" value="0">'
        . '<input class="form-check-input" type="checkbox" id="f_' . h($name) . '" name="' . h($name) . '" value="1"' . checked($checked) . '>'
        . '<label class="form-check-label" for="f_' . h($name) . '">' . h($label) . '</label></div></div>';
}

/**
 * A drop-down list of check boxes, for picking any number of choices at once (sent as name[]; nothing ticked means all).
 *   'all'    - the text shown when nothing is ticked ('All categories')
 *   'noun'   - what the choices are, for the summary ('categories')
 *   (an empty name: the ticks only steer the page's own script and are not sent with the form)
 *   'submit' - a GET filter: the form is sent when the list is closed after a change (and by its Show button)
 */
function multi_check_field(string $name, string $label, array $opts, array $values, array $attr = []): string
{
    $cls = $attr['class'] ?? 'col-md-4';
    $all = (string)($attr['all'] ?? 'All');
    $noun = (string)($attr['noun'] ?? 'selected');
    $id = (string)($attr['id'] ?? ('mc_' . preg_replace('/\W+/', '', $name)));
    $vals = array_map('strval', $values);
    // the choices may come in groups: [group heading => [value => label]]
    $flat = [];
    foreach ($opts as $k => $v) {
        if (is_array($v)) {
            $flat += $v;
        } else {
            $flat[$k] = $v;
        }
    }
    $picked = array_values(array_filter($flat, fn($k) => in_array((string)$k, $vals, true), ARRAY_FILTER_USE_KEY));
    $summary = multi_check_summary($picked, $all, $noun);
    $bare = !empty($attr['nolabel']);   // no label above it (it sits in a toolbar); the button carries the label instead
    $html = '<div class="' . h($cls) . '">' . ($bare ? '' : '<label class="form-label" id="' . h($id) . 'L">' . h($label) . '</label>')
        . '<div class="dropdown multi-check" id="' . h($id) . '" data-multi-check data-all="' . h($all) . '" data-noun="' . h($noun) . '"' . (!empty($attr['submit']) ? ' data-multi-submit' : '') . '>'
        . '<button type="button" class="form-select form-select-sm multi-check-btn" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" '
        . ($bare ? 'title="' . h($label) . '" aria-label="' . h($label) . '"' : 'aria-labelledby="' . h($id) . 'L ' . h($id) . 'S"') . '>'
        . '<span class="multi-check-text" id="' . h($id) . 'S">' . h($summary) . '</span></button>'
        . '<div class="dropdown-menu multi-check-menu">'
        . '<div class="multi-check-tools">'
        . (count($flat) > 8 ? '<input type="search" class="form-control form-control-sm" placeholder="Type to find" autocomplete="off" aria-label="Find in ' . h($label) . '" data-mc-find>' : '')
        . '<div class="multi-check-links"><button type="button" class="btn btn-link btn-sm p-0" data-mc-all>Tick all</button><button type="button" class="btn btn-link btn-sm p-0" data-mc-none>Clear</button></div></div>'
        . '<div class="multi-check-list">';
    $item = fn($k, $v) => '<label class="multi-check-item"><input class="form-check-input" type="checkbox"' . ($name !== '' ? ' name="' . h($name) . '[]"' : '') . ' value="' . h((string)$k) . '"'
        . (in_array((string)$k, $vals, true) ? ' checked' : '') . '><span>' . h((string)$v) . '</span></label>';
    foreach ($opts as $k => $v) {
        if (is_array($v)) {
            $html .= '<div class="multi-check-group" data-mc-group>' . h((string)$k) . '</div>';
            foreach ($v as $kk => $vv) {
                $html .= $item($kk, $vv);
            }
        } else {
            $html .= $item($k, $v);
        }
    }
    if (!$opts) {
        $html .= '<div class="small text-muted px-3 py-2">Nothing to choose from yet.</div>';
    }
    $html .= '</div>';
    if (!empty($attr['submit'])) {
        $html .= '<div class="multi-check-foot"><span class="small text-muted">Nothing ticked: ' . h(lcfirst($all)) . '</span><button type="submit" class="btn btn-primary btn-sm">Show</button></div>';
    }
    return $html . '</div></div></div>';
}

/** "Hardware", "Hardware, Paint", "Hardware, Paint and 3 more categories" */
function multi_check_summary(array $names, string $all, string $noun): string
{
    $n = count($names);
    if ($n === 0) {
        return $all;
    }
    if ($n <= 2) {
        return implode(', ', $names);
    }
    return $names[0] . ', ' . $names[1] . ' and ' . ($n - 2) . ' more ' . $noun;
}

/** The ids ticked in a multi_check_field of a GET form (an old single "<name>" id is still understood) */
function gints(string $name, string $single = ''): array
{
    $v = $_GET[$name] ?? [];
    $ids = is_array($v) ? $v : explode(',', (string)$v);
    if ($single !== '' && ($_GET[$single] ?? '') !== '') {
        $ids[] = $_GET[$single];
    }
    $out = [];
    foreach ($ids as $id) {
        if (is_scalar($id) && (int)$id > 0) {
            $out[(int)$id] = (int)$id;
        }
    }
    return array_values($out);
}

/**
 * Save / Save & New / Cancel buttons for a form.
 * "Save & New" posts save_new=1; redirect_after_save() then re-opens an empty form,
 * so the next record can be entered without going back to the list.
 */
function save_buttons(string $label = 'Save', ?string $cancelUrl = null, bool $showNew = true, string $extra = ''): string
{
    $html = '<button class="btn btn-primary btn-sm" name="save" value="1"><i class="bi bi-check-lg"></i> ' . h($label) . '</button>';
    if ($showNew) {
        $html .= ' <button class="btn btn-outline-primary btn-sm" name="save_new" value="1" title="Save this one and start another with empty fields">'
            . '<i class="bi bi-plus-lg"></i> ' . h($label) . ' &amp; New</button>';
    }
    if ($cancelUrl !== null) {
        $html .= ' <a class="btn btn-outline-secondary btn-sm" href="' . h($cancelUrl) . '">Cancel</a>';
    }
    return '<div class="d-flex gap-1 flex-wrap align-items-center">' . $html . ($extra ? ' ' . $extra : '') . '</div>';
}

/** True when the form was submitted with "Save & New" */
function wants_new(): bool
{
    return is_post() && (string)p('save_new') === '1';
}

/** Goes to a blank form after "Save & New", otherwise to the saved record */
function redirect_after_save(string $newUrl, string $savedUrl): void
{
    redirect(wants_new() ? $newUrl : $savedUrl);
}

function reason_modal(string $id, string $title, string $action, string $buttonClass = 'btn-danger', string $extraHtml = ''): string
{
    return '<div class="modal fade" id="' . h($id) . '" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post" action="' . h($action) . '">'
        . csrf_field()
        . '<div class="modal-header"><h5 class="modal-title">' . h($title) . '</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>'
        . '<div class="modal-body"><label class="form-label">Reason (required, recorded in the audit trail)</label>'
        . '<textarea class="form-control" name="reason" required rows="2"></textarea>' . $extraHtml . Approval::fields() . '</div>'
        . '<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>'
        . '<button class="btn ' . h($buttonClass) . '">' . h($title) . '</button></div></form></div></div>';
}

// the helpers of the construction pages
require_once APP_ROOT . '/modules/cs/_common.php';
