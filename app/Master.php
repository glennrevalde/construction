<?php
declare(strict_types=1);

/**
 * The master (platform) database: companies, the database each company uses, Company Codes, which
 * company every username belongs to, Super Admin accounts and the platform audit log.
 *
 * It is a separate database on the same MySQL server, reached with the credentials in config/config.php.
 * Its name is the first company's database name followed by "_master", unless config.php names another
 * one ($CONFIG['master']['name'] = '...').
 *
 * Multi-company mode is switched on by storage/platform/multi_company.json, written when the current
 * database is registered as Company 1 (System Administration > Multi-Company Setup). Until then the
 * system runs exactly as the single company it always was. Once it is on, the master database has to be
 * reachable: nothing falls back to "single company" quietly.
 */
final class Master
{
    public const FLAG_FILE = '/storage/platform/multi_company.json';

    private static ?Sql $sql = null;
    private static $flag = false;          // false = not read yet
    private static array $companies = [];

    // ------------------------------------------------------------------ where it is

    /** The database in config/config.php: the first company */
    public static function primaryDb(): string
    {
        return (string)$GLOBALS['CONFIG']['db']['name'];
    }

    public static function dbName(): string
    {
        $name = trim((string)($GLOBALS['CONFIG']['master']['name'] ?? ''));
        if ($name === '') {
            $name = (string)(self::flag()['master_db'] ?? '');
        }
        if ($name === '') {
            $name = substr(self::primaryDb(), 0, 57) . '_master';
        }
        if (!DB::validName($name)) {
            throw new RuntimeException('The master database name is not valid.');
        }
        return $name;
    }

    /** The switch file, when it belongs to this installation (same first-company database) */
    public static function flag(): ?array
    {
        if (self::$flag === false) {
            self::$flag = null;
            $file = APP_ROOT . self::FLAG_FILE;
            if (is_file($file)) {
                $data = json_decode((string)file_get_contents($file), true);
                if (is_array($data) && ($data['primary_db'] ?? '') === self::primaryDb() && !empty($data['master_db'])) {
                    self::$flag = $data;
                }
            }
        }
        return self::$flag;
    }

    /** Several companies: the platform is set up */
    public static function enabled(): bool
    {
        return self::flag() !== null;
    }

    public static function writeFlag(string $masterDb): void
    {
        $dir = dirname(APP_ROOT . self::FLAG_FILE);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            fail('The folder storage/platform could not be created. Make the storage folder writable by the web server.');
        }
        self::protectDir($dir);
        $ok = @file_put_contents(APP_ROOT . self::FLAG_FILE, json_encode([
            'primary_db' => self::primaryDb(), 'master_db' => $masterDb, 'enabled_at' => date('c'),
        ], JSON_PRETTY_PRINT));
        if ($ok === false) {
            fail('storage/platform/multi_company.json could not be written. Make the storage folder writable by the web server.');
        }
        self::$flag = false;
    }

    /** A storage folder the web server must never list or serve */
    public static function protectDir(string $dir): void
    {
        if (!file_exists($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
        }
        if (!file_exists($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '');
        }
    }

    // ------------------------------------------------------------------ connection

    public static function sql(): Sql
    {
        if (self::$sql === null) {
            try {
                self::$sql = Sql::to(self::dbName());
            } catch (PDOException $e) {
                // the details go to the server's error log, not to the screen
                error_log('master database ' . self::dbName() . ': ' . $e->getMessage());
                throw new RuntimeException('The platform database cannot be reached, so no company can be opened. '
                    . 'Please try again in a moment; if it keeps happening, the server administrator can find the details in the PHP error log.');
            }
        }
        return self::$sql;
    }

    // ------------------------------------------------------------------ companies

    /** One company with its database and current code, or null */
    public static function company(int $id, bool $fresh = false): ?array
    {
        if ($fresh || !array_key_exists($id, self::$companies)) {
            self::$companies[$id] = self::sql()->row(
                "SELECT c.*, d.db_name, d.is_primary, d.created_by_platform, d.table_count, d.triggers_ok, d.verified_at,
                        (SELECT k.code FROM company_codes k WHERE k.company_id = c.id AND k.status <> 'retired' ORDER BY k.id DESC LIMIT 1) AS code,
                        (SELECT k.status FROM company_codes k WHERE k.company_id = c.id AND k.status <> 'retired' ORDER BY k.id DESC LIMIT 1) AS code_status,
                        (SELECT k.expires_at FROM company_codes k WHERE k.company_id = c.id AND k.status <> 'retired' ORDER BY k.id DESC LIMIT 1) AS code_expires_at
                   FROM companies c LEFT JOIN company_databases d ON d.company_id = c.id
                  WHERE c.id = :id",
                ['id' => $id]
            );
        }
        return self::$companies[$id];
    }

    public static function forget(int $id): void
    {
        unset(self::$companies[$id]);
    }

    public static function companies(): array
    {
        return self::sql()->all(
            "SELECT c.*, d.db_name, d.is_primary, d.triggers_ok,
                    (SELECT k.code FROM company_codes k WHERE k.company_id = c.id AND k.status <> 'retired' ORDER BY k.id DESC LIMIT 1) AS code,
                    (SELECT k.status FROM company_codes k WHERE k.company_id = c.id AND k.status <> 'retired' ORDER BY k.id DESC LIMIT 1) AS code_status,
                    (SELECT m.full_name FROM user_company_memberships m WHERE m.company_id = c.id AND m.company_user_id = c.owner_user_id) AS owner_name,
                    (SELECT m.username FROM user_company_memberships m WHERE m.company_id = c.id AND m.company_user_id = c.owner_user_id) AS owner_username,
                    (SELECT COUNT(*) FROM user_company_memberships m WHERE m.company_id = c.id) AS users_count,
                    (SELECT COUNT(*) FROM user_company_memberships m WHERE m.company_id = c.id AND m.status = 'pending') AS pending_count
               FROM companies c LEFT JOIN company_databases d ON d.company_id = c.id
              ORDER BY c.id"
        );
    }

    /** The primary company (Company 1: the database in config.php) */
    public static function primaryCompanyId(): ?int
    {
        $id = self::sql()->value('SELECT company_id FROM company_databases WHERE is_primary = 1 ORDER BY company_id LIMIT 1');
        return $id === null ? null : (int)$id;
    }

    // ------------------------------------------------------------------ memberships (which company a username is in)

    public static function membershipByUsername(string $username): ?array
    {
        return self::sql()->row('SELECT * FROM user_company_memberships WHERE username = :u', ['u' => $username]);
    }

    public static function membership(int $companyId, int $userId): ?array
    {
        return self::sql()->row('SELECT * FROM user_company_memberships WHERE company_id = :c AND company_user_id = :u',
            ['c' => $companyId, 'u' => $userId]);
    }

    /** Is the username free across every company and the Super Admins? */
    public static function usernameFree(string $username, ?int $companyId = null, ?int $userId = null): bool
    {
        if (self::sql()->value('SELECT id FROM super_admins WHERE username = :u', ['u' => $username])) {
            return false;
        }
        $m = self::membershipByUsername($username);
        return !$m || ($companyId !== null && (int)$m['company_id'] === $companyId && (int)$m['company_user_id'] === (int)$userId);
    }

    // ------------------------------------------------------------------ super admins

    public static function superAdmin(int $id): ?array
    {
        return self::sql()->row('SELECT * FROM super_admins WHERE id = :id', ['id' => $id]);
    }

    public static function superAdminByUsername(string $username): ?array
    {
        return self::sql()->row('SELECT * FROM super_admins WHERE username = :u', ['u' => $username]);
    }

    // ------------------------------------------------------------------ settings

    public const SETTING_DEFAULTS = [
        'db_prefix'               => '',   // empty = <first company database>_company_
        'default_require_approval' => '1',
        'session_timeout_minutes' => '30',
        'max_failed_logins'       => '5',
        'join_attempts_per_15min' => '10',
    ];

    public static function setting(string $key): string
    {
        $v = self::sql()->value('SELECT setting_value FROM platform_settings WHERE setting_key = :k', ['k' => $key]);
        return $v === null ? (self::SETTING_DEFAULTS[$key] ?? '') : (string)$v;
    }

    public static function setSetting(string $key, string $value): void
    {
        self::sql()->run('INSERT INTO platform_settings (setting_key, setting_value) VALUES (:k, :v)
                          ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', ['k' => $key, 'v' => $value]);
    }

    /** New company databases are named <prefix>NNN */
    public static function dbPrefix(): string
    {
        $p = self::setting('db_prefix');
        if ($p === '' || !preg_match('/^[A-Za-z0-9_]{1,50}$/', $p)) {
            $p = substr(self::primaryDb(), 0, 40) . '_company_';
        }
        return $p;
    }

    // ------------------------------------------------------------------ platform audit

    /**
     * One line in the platform audit log. The actor is the Super Admin of this session, else the logged-in
     * company user, else a guest (the join page).
     */
    public static function log(string $action, ?int $companyId, string $details = '', ?array $actor = null): void
    {
        if ($actor === null) {
            if (!empty($_SESSION['platform_sa_id'])) {
                $sa = self::superAdmin((int)$_SESSION['platform_sa_id']);
                $actor = ['super_admin', (int)$_SESSION['platform_sa_id'], $sa['username'] ?? null];
            } elseif (!DB::isBlocked() && ($u = Auth::user())) {
                $actor = [Tenant::isOwner((int)$u['id']) ? 'owner' : 'user', (int)$u['id'], $u['username']];
            } else {
                $actor = [PHP_SAPI === 'cli' ? 'system' : 'guest', null, null];
            }
        }
        try {
            self::sql()->insert('platform_audit', [
                'actor_type' => $actor[0], 'actor_id' => $actor[1], 'actor_name' => $actor[2] !== null ? mb_substr((string)$actor[2], 0, 80) : null,
                'company_id' => $companyId, 'action' => mb_substr($action, 0, 40), 'details' => $details !== '' ? $details : null,
                'ip_address' => client_ip(),
            ]);
        } catch (Throwable $e) {
            error_log('platform audit: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ first-time set-up

    /**
     * Creates the master database (if it is not there) and its tables. Safe to run again.
     * Returns the database name.
     */
    public static function install(): string
    {
        $name = self::dbName();
        $server = Sql::to(null);
        $exists = (bool)$server->value('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :n', ['n' => $name]);
        if (!$exists) {
            try {
                $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (PDOException $e) {
                fail("The master database `$name` could not be created (" . $e->getMessage() . '). '
                    . "Create an empty database named $name in phpMyAdmin or your hosting control panel, "
                    . 'give the database user of config/config.php full rights on it, and try again.');
            }
        }
        self::$sql = null;
        $sql = self::sql();
        foreach (Migrator::split((string)file_get_contents(APP_ROOT . '/database/master_schema.sql')) as $stmt) {
            $sql->exec($stmt);
        }
        // the platform audit log is permanent, like the audit trail of every company (best effort:
        // some hosts do not allow triggers, and the log still works without them)
        try {
            $sql->exec('DROP TRIGGER IF EXISTS trg_platform_audit_no_update');
            $sql->exec("CREATE TRIGGER trg_platform_audit_no_update BEFORE UPDATE ON platform_audit FOR EACH ROW
                        BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Platform audit entries are read-only.'; END");
            $sql->exec('DROP TRIGGER IF EXISTS trg_platform_audit_no_delete');
            $sql->exec("CREATE TRIGGER trg_platform_audit_no_delete BEFORE DELETE ON platform_audit FOR EACH ROW
                        BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Platform audit entries are permanent.'; END");
        } catch (Throwable $e) {
            error_log('platform audit triggers: ' . $e->getMessage());
        }
        return $name;
    }
}
