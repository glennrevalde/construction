<?php
declare(strict_types=1);

/**
 * The Super Admin (platform) pages: every company, their databases, codes and owners.
 * Only a logged-in Super Admin gets here; company users never do. No company database is open on these
 * pages (DB is blocked); a company is read through its own connection, or opened with "Open company".
 */

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/views/layout.php';
require __DIR__ . '/views/platform_layout.php';

if (!Master::enabled()) {
    redirect(base_url() . '/login.php');
}
verify_csrf();

$route = (string)g('p', 'companies');
$pages = ['companies', 'company', 'new', 'updates', 'admins', 'settings', 'audit', 'account'];
if (!in_array($route, $pages, true)) {
    $route = 'companies';
}

// logging out, and coming back from a company, are the two things allowed before the usual checks
if (g('a') === 'logout' && is_post()) {
    Platform::logout();
    flash('success', 'You have been logged out.');
    redirect(base_url() . '/login.php');
}
$sa = Platform::requireLogin($route === 'account');
if (g('a') === 'leave') {
    $back = gint('id');
    Platform::leaveCompany();
    redirect($back ? purl('company', ['id' => $back]) : purl('companies'));
}
// a platform page never works on a company database: that only happens inside "Open company"
if (Tenant::id()) {
    Platform::leaveCompany();
    redirect(purl($route));
}

$GLOBALS['PLATFORM_PAGE'] = $route;
require __DIR__ . '/modules/platform/' . $route . '.php';
