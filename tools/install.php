<?php
declare(strict_types=1);

/**
 * Command-line installer of GSMaRT Construction System.
 *
 *   php tools/install.php            create the company database, its tables, triggers and set-up data, and the
 *                                    platform (master) database with the Super Admin; Company 1 = this database
 *   php tools/install.php --demo     ... plus the sample clients, projects and transactions
 *   php tools/install.php --drop     drop and recreate both databases first (DESTROYS DATA)
 *   php tools/install.php --company="Name"   the company's name (clean install without the sample company's details)
 *
 * Database settings come from config/config.php (or DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS).
 * The easier way for most people: import database/gsmart_construction_clean.sql (or _sample.sql) in phpMyAdmin.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';
require __DIR__ . '/seed.php';

$opts = getopt('', ['demo', 'drop', 'superadmin::', 'superpass::', 'company::']);
$name = $GLOBALS['CONFIG']['db']['name'];
$masterName = substr($name, 0, 57) . '_master';

$server = server_pdo();
if (isset($opts['drop'])) {
    out("Dropping databases `$name` and `$masterName` ...");
    $server->exec("DROP DATABASE IF EXISTS `$name`");
    $server->exec("DROP DATABASE IF EXISTS `$masterName`");
    foreach (glob(APP_ROOT . '/storage/companies/*') ?: [] as $dir) {
        // company folders of earlier installs are left alone: only the switch file is reset
    }
    @unlink(APP_ROOT . Master::FLAG_FILE);
}
$server->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$pdo = DB::pdo();
$existing = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'")->fetchColumn();
if ($existing && !isset($opts['drop'])) {
    out('The database already has tables. Use --drop to reinstall (this deletes all data).');
    exit(1);
}

out('Creating tables ...');
run_sql_file($pdo, APP_ROOT . '/database/schema.sql');
out('Creating audit trail protection triggers ...');
run_sql_file($pdo, APP_ROOT . '/database/triggers.sql');

out('Loading set-up data (chart of accounts, roles, users, cost codes, approval routes) ...');
DB::tx(function () {
    seed_master_data();
});

$admin = (int)DB::value("SELECT id FROM users WHERE username = 'admin'");
Auth::actAs($admin);

if (isset($opts['demo'])) {
    require __DIR__ . '/demo_data.php';
    out('Recording sample clients, projects and transactions ...');
    load_demo_data();
}

// ---- a clean install can carry the company's own name (the rest is filled in on Company Profile)
if (!empty($opts['company'])) {
    foreach (['company_name' => (string)$opts['company'], 'company_address' => '', 'company_tin' => '', 'company_phone' => '', 'company_email' => ''] as $k => $v) {
        Settings::set($k, $v);
    }
}

// ---- the platform: this database becomes Company 1, with its Company Owner and a Super Admin
out('Setting up the platform (several companies) ...');
$_SESSION = [];
$owner = (int)DB::value("SELECT id FROM users WHERE username = 'owner'");
$s = Settings::all();
$r = Provision::setupPrimary(
    ['username' => (string)($opts['superadmin'] ?? 'superadmin'), 'full_name' => 'Platform Super Administrator',
     'email' => 'superadmin@samplebuilders.test', 'password' => (string)($opts['superpass'] ?? 'Super@12345')],
    ['name' => $s['company_name'], 'address' => $s['company_address'], 'contact_person' => 'Eduardo Santos', 'phone' => $s['company_phone'],
     'email' => $s['company_email'], 'tin' => $s['company_tin']],
    $owner,
    true
);
out('Company 1 registered with Company Code ' . CompanyCode::display((string)$r['code']) . ' (' . $r['users'] . ' users listed).');
out('Done. Log in as admin / Admin@12345, owner / Demo@12345, or the Super Admin superadmin / Super@12345.');
