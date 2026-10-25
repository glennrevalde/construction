<?php
declare(strict_types=1);

/**
 * The company switch in the top bar (CompanyAccess): opens another company the user was given access to, or their own
 * company again. Only by POST, from one of the companies listed for this session.
 */
if (!is_post()) {
    redirect(url('home.dashboard'));
}
$to = (int)p('company_id');
if (!isset(CompanyAccess::switchable()[$to])) {
    flash('warning', 'You have no access to that company.');
    redirect(url('home.dashboard'));
}
try {
    $name = CompanyAccess::switchTo($to);
    flash('success', 'You are now working in ' . rtrim($name, '.') . '.');
} catch (UserError $e) {
    flash('danger', $e->getMessage());
}
redirect(url('home.dashboard'));
