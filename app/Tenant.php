<?php
declare(strict_types=1);

/**
 * Which company this request works for, and so which company database every module reads and writes.
 *
 * The company is never taken from the address, a form or a cookie. It is set on the server when the user
 * logs in (the master database says which company the username belongs to) and kept in the session; on
 * every request it is checked again against the master database:
 *   - the company still exists and is active (a Super Admin may still open an inactive one),
 *   - the logged-in user is still a member of that company (their user id is mapped to it), or works there
 *     from their own company through access that company gave them (CompanyAccess),
 *   - the Super Admin of a platform session is still active.
 * Then the company's database is switched on in DB, in this one place. With several companies and none
 * chosen, DB is blocked: nothing can read any company's data.
 *
 * With only one company (multi-company not set up yet) nothing changes: the database of config.php is used.
 */
final class Tenant
{
    private static bool $multi = false;
    private static ?array $company = null;
    private static array $ownerCache = [];

    // ------------------------------------------------------------------ start of every request

    /** Web requests: resolve the company of this session */
    public static function boot(): void
    {
        if (!Master::enabled()) {
            self::$multi = false;
            return;
        }
        self::$multi = true;
        $companyId = (int)($_SESSION['tenant_id'] ?? 0);
        $saId = (int)($_SESSION['platform_sa_id'] ?? 0);

        if ($saId) {
            $sa = Master::superAdmin($saId);
            if (!$sa || $sa['status'] !== 'active') {
                self::expel('Your Super Admin session has ended. Please log in again.');
            }
            if ($companyId) {
                $_SESSION['platform_last'] = time();   // working inside a company keeps the platform session alive
            }
        }
        if (!$companyId) {
            DB::block('No company is open in this session.');
            return;
        }
        $c = Master::company($companyId);
        if (!$c || (string)$c['db_name'] === '') {
            self::expel('Your company could not be found. Please log in again.');
        }
        if ($c['status'] !== 'active' && !($saId && $c['status'] === 'inactive')) {
            if (!$saId && CompanyAccess::away()) {
                // the other company a user was working in was switched off: back to their own company
                CompanyAccess::backHome($c['name'] . ' is not open at the moment. You are back in your own company.');
            }
            self::expel('Your company\'s access is currently suspended. Please contact your Company Owner.');
        }
        self::use($c);

        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId) {
            if ($saId) {
                // a Super Admin works in a company through its platform account, and only through that
                if ((int)($_SESSION['platform_user_id'] ?? 0) !== $userId) {
                    self::expel('Please open the company again from the platform.');
                }
            } elseif (CompanyAccess::away()) {
                // a user of another company working here through access given by this company
                if (!CompanyAccess::validAway((int)$c['id'], $userId)) {
                    CompanyAccess::backHome('Your access to ' . $c['name'] . ' has ended. You are back in your own company.');
                }
            } elseif (!Master::membership((int)$c['id'], $userId)) {
                self::expel('Your account is no longer linked to this company. Please log in again.');
            }
        }
    }

    /**
     * Command-line tools: the first company, or the one given with --company=N.
     * Returns the company (null with a single company).
     */
    public static function bootCli(?int $companyId = null): ?array
    {
        if (!Master::enabled()) {
            self::$multi = false;
            return null;
        }
        self::$multi = true;
        $companyId ??= Master::primaryCompanyId();
        $c = $companyId ? Master::company($companyId) : null;
        if (!$c || (string)$c['db_name'] === '') {
            throw new RuntimeException("Company $companyId was not found.");
        }
        self::use($c);
        return $c;
    }

    /** Switches every module to this company's database (the only place this is done) */
    public static function use(array $company): void
    {
        DB::useDatabase((string)$company['db_name']);
        self::$company = $company;
        self::$ownerCache = [];
        Settings::flush();
        Access::reset();
        Auth::flush();
        Schema::flush();
        Logo::flush();
        CsProject::flush();
        CsCost::flush();
    }

    /** Ends the session with a message and goes back to the log-in page */
    private static function expel(string $message): never
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        flash('warning', $message);
        if (PHP_SAPI === 'cli') {
            throw new RuntimeException($message);
        }
        redirect(base_url() . '/login.php');
    }

    // ------------------------------------------------------------------ the session's company

    public static function isMulti(): bool
    {
        return self::$multi;
    }

    public static function company(): ?array
    {
        return self::$company;
    }

    public static function id(): ?int
    {
        return self::$company ? (int)self::$company['id'] : null;
    }

    /** The first company (the database of config.php), or the only company */
    public static function isPrimary(): bool
    {
        return !self::$multi || (self::$company && (int)self::$company['is_primary'] === 1);
    }

    /** A Super Admin is working inside this company through the platform */
    public static function platformSession(): bool
    {
        return self::$multi && !empty($_SESSION['platform_sa_id']) && !empty($_SESSION['platform_user_id']);
    }

    /** The user is this company's Company Owner */
    public static function isOwner(?int $userId = null): bool
    {
        if (!self::$multi || !self::$company) {
            return false;
        }
        $userId ??= Auth::id();
        return $userId !== null && (int)(self::$company['owner_user_id'] ?? 0) === $userId;
    }

    /** The account a Super Admin works through inside a company (never listed with the company's users) */
    public static function isPlatformUser(?int $userId = null): bool
    {
        if (!self::$multi) {
            return false;
        }
        $userId ??= Auth::id();
        if ($userId === null) {
            return false;
        }
        return self::$ownerCache['p' . $userId] ??= (int)DB::value('SELECT is_platform FROM users WHERE id = :id', ['id' => $userId]) === 1;
    }

    /** Owner and Super Admin: full authority inside the company, whatever roles or overrides say */
    public static function hasAuthority(?int $userId = null): bool
    {
        return self::isOwner($userId) || self::isPlatformUser($userId);
    }

    /** The company's display name (master record, else the company settings) */
    public static function name(): string
    {
        return (string)(self::$company['name'] ?? Settings::get('company_name'));
    }

    // ------------------------------------------------------------------ files

    /**
     * The folder for one kind of file of this company. The first company keeps the folders it always had
     * (storage/company, storage/backups, ...); every other company has its own under storage/companies/cNNN.
     */
    public static function storage(string $sub): string
    {
        $sub = trim(preg_replace('/[^a-z0-9_\/-]/i', '', $sub), '/');
        if (self::isPrimary()) {
            if ($sub === 'receipts') {
                $dir = (string)($GLOBALS['CONFIG']['app']['upload_dir'] ?? (APP_ROOT . '/storage/receipts'));
            } else {
                $dir = APP_ROOT . '/storage/' . $sub;
            }
        } else {
            $dir = APP_ROOT . '/storage/companies/' . self::storageKey((int)self::$company['id']) . '/' . $sub;
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public static function storageKey(int $companyId): string
    {
        return 'c' . str_pad((string)$companyId, 3, '0', STR_PAD_LEFT);
    }

    /** How the company is named in backup file names (the first company keeps its database name) */
    public static function fileStem(): string
    {
        return self::isPrimary() ? DB::databaseName() : 'company_' . str_pad((string)self::id(), 3, '0', STR_PAD_LEFT);
    }
}
