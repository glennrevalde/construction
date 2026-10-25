<?php
declare(strict_types=1);

/**
 * Writes one importable .sql file with the whole system: the company database (tables, data, audit trail protection)
 * and the platform (master) database with its Super Admin, companies and Company Codes.
 *
 *   php tools/export_sql.php --out=database/gsmart_construction_sample.sql --title="..."
 *   php tools/export_sql.php --no-master         company database only (a backup of one company)
 *   php tools/export_sql.php --no-create-db      leave out CREATE DATABASE / USE (import into databases you made)
 *
 * Import with phpMyAdmin (Import tab) or:  mysql -u root -p < gsmart_construction_sample.sql
 * Importing replaces every table of both databases.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

$opts = getopt('', ['out::', 'no-create-db', 'no-master', 'title::']);
$out = $opts['out'] ?? (APP_ROOT . '/database/gsmart_construction.sql');
$name = $GLOBALS['CONFIG']['db']['name'];
$pdo = DB::pdo();
$withMaster = !isset($opts['no-master']) && Master::enabled();
$masterName = $withMaster ? Master::dbName() : null;

$schema = file_get_contents(APP_ROOT . '/database/schema.sql');
// the combined file controls foreign key checks itself
$schema = preg_replace('/^SET FOREIGN_KEY_CHECKS\s*=\s*[01];\s*$/mi', '', $schema);
$schema = preg_replace('/^SET NAMES utf8mb4;\s*$/mi', '', $schema);
$triggers = file_get_contents(APP_ROOT . '/database/triggers.sql');

$tables = schema_tables(APP_ROOT . '/database/schema.sql');
$counts = [];
foreach ($tables as $t) {
    $counts[$t] = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
}
$title = $opts['title'] ?? 'GSMaRT Construction System - complete database';
$txns = $counts['transactions'] ?? 0;
$projects = $counts['cs_projects'] ?? 0;
$file = basename($out);

$head = "-- =====================================================================\n"
    . "--  $title\n"
    . '--  Generated ' . date('Y-m-d H:i:s P') . "\n"
    . '--  Company database `' . $name . '`: ' . count($tables) . ' tables, ' . array_sum($counts) . " rows ($projects projects, $txns accounting transactions)\n"
    . ($withMaster ? '--  Platform database `' . $masterName . "`: Super Admin, companies and Company Codes\n" : '')
    . "--\n"
    . "--  Import:  phpMyAdmin > Import > choose this file > Go   (no database needs to be selected)\n"
    . "--      or:  mysql -u root -p < $file\n"
    . "--  Works on MySQL 5.5.3 or newer (5.5, 5.6, 5.7, 8.x) and MariaDB 5.5 or newer.\n"
    . "--  Import as root (or a user with CREATE, DROP, INSERT, REFERENCES and TRIGGER privileges).\n"
    . "--  WARNING: importing drops and recreates every table in the databases below.\n"
    . "--\n"
    . "--  Logins: see README.md (superadmin / Super@12345, admin / Admin@12345, other users Demo@12345)\n"
    . "-- =====================================================================\n\n"
    . "SET NAMES utf8mb4;\n"
    . "SET FOREIGN_KEY_CHECKS = 0;\n"
    . "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n"
    . "SET time_zone = '" . date('P') . "';\n\n";

$sql = $head;
if (!isset($opts['no-create-db'])) {
    $sql .= "CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
        . "USE `$name`;\n\n";
}
$sql .= "-- ---------------------------------------------------------------------\n"
    . "--  1. COMPANY DATABASE: TABLES\n"
    . "-- ---------------------------------------------------------------------\n"
    . trim($schema) . "\n\n"
    . "-- ---------------------------------------------------------------------\n"
    . "--  2. COMPANY DATABASE: DATA\n"
    . "-- ---------------------------------------------------------------------\n"
    . export_data($pdo, $tables) . "\n"
    . "-- ---------------------------------------------------------------------\n"
    . "--  3. AUDIT TRAIL PROTECTION (created after the data is loaded)\n"
    . trim($triggers) . "\n\n";

if ($withMaster) {
    $m = Sql::to($masterName)->pdo;
    $mTables = schema_tables(APP_ROOT . '/database/master_schema.sql');
    $mSchema = (string)file_get_contents(APP_ROOT . '/database/master_schema.sql');
    $mSchema = preg_replace('/^SET NAMES utf8mb4;\s*$/mi', '', $mSchema);
    $drops = implode('', array_map(fn($t) => "DROP TABLE IF EXISTS `$t`;\n", array_reverse($mTables)));
    $sql .= "-- ---------------------------------------------------------------------\n"
        . "--  4. PLATFORM (MASTER) DATABASE: companies, Company Codes, Super Admin\n"
        . "-- ---------------------------------------------------------------------\n";
    if (!isset($opts['no-create-db'])) {
        $sql .= "CREATE DATABASE IF NOT EXISTS `$masterName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
            . "USE `$masterName`;\n\n";
    }
    $sql .= $drops . "\n" . trim($mSchema) . "\n" . export_data($m, $mTables) . "\n";
    if (!isset($opts['no-create-db'])) {
        $sql .= "USE `$name`;\n";
    }
}
$sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";

file_put_contents($out, $sql);
out(sprintf('Wrote %s (%s KB, %d tables, %d rows%s).', $out, number_format(strlen($sql) / 1024), count($tables), array_sum($counts), $withMaster ? ', with the platform database' : ''));
