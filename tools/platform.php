<?php
declare(strict_types=1);

/**
 * Platform (several companies) from the command line: for the server administrator.
 *
 *   php tools/platform.php list                               the companies, their databases and status
 *   php tools/platform.php reset-superadmin <username> <pw>   new password for a Super Admin, unlocked and active
 *   php tools/platform.php sync <company id>                  rebuild the platform's list of that company's usernames
 *   php tools/platform.php update-all                         run the pending upgrade scripts on every company database
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

if (!Master::enabled()) {
    out('Multi-company is not set up on this installation (System Administration > Multi-Company Setup).');
    exit(1);
}
$cmd = $argv[1] ?? '';
$m = Master::sql();

switch ($cmd) {
    case 'list':
        foreach (Master::companies() as $c) {
            out(sprintf('%3d  %-12s  %-32s  %-28s  %s', $c['id'], $c['status'], mb_substr($c['name'], 0, 32), $c['db_name'] ?: '-', CompanyCode::display($c['code'])));
        }
        break;

    case 'reset-superadmin':
        [$user, $pw] = [$argv[2] ?? '', $argv[3] ?? ''];
        $sa = Master::superAdminByUsername($user);
        if (!$sa) {
            out("No Super Admin named $user.");
            exit(1);
        }
        $rules = ['pw_min_length' => '10', 'pw_require_upper' => '1', 'pw_require_number' => '1', 'pw_require_symbol' => '1'];
        if ($problems = Auth::passwordProblems($pw, $user, $rules)) {
            out('The password must have ' . implode(', ', $problems) . '.');
            exit(1);
        }
        $m->update('super_admins', ['password_hash' => Auth::hash($pw), 'status' => 'active', 'failed_attempts' => 0,
            'must_change_password' => 1, 'password_changed_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $sa['id']]);
        Master::log('super_admin_password_reset', null, "$user reset from the command line", ['system', null, 'cli']);
        out("$user can log in with the new password and must change it at once.");
        break;

    case 'sync':
        $c = Master::company((int)($argv[2] ?? 0), true);
        if (!$c || !$c['db_name']) {
            out('Company not found.');
            exit(1);
        }
        $r = Membership::sync((int)$c['id'], Sql::to((string)$c['db_name']));
        out($r['users'] . ' user(s) listed.' . ($r['conflicts'] ? ' Username clashes with other companies: ' . implode(', ', $r['conflicts']) : ''));
        break;

    case 'update-all':
        foreach (Master::companies() as $c) {
            if (!in_array($c['status'], ['active', 'inactive'], true) || !$c['db_name']) {
                continue;
            }
            $r = Migrator::runPending(Sql::to((string)$c['db_name']));
            out(sprintf('%-32s ran %d, %s', mb_substr($c['name'], 0, 32), count($r['ran']), $r['errors'] ? implode('; ', $r['errors']) : 'up to date'));
            if ($r['ran']) {
                Master::log('database_updated', (int)$c['id'], 'Ran ' . implode(', ', array_map('basename', $r['ran'])), ['system', null, 'cli']);
            }
        }
        break;

    default:
        out("Usage:\n  php tools/platform.php list\n  php tools/platform.php reset-superadmin <username> <password>\n  php tools/platform.php sync <company id>\n  php tools/platform.php update-all");
        exit(1);
}
