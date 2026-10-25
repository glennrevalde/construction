<?php
declare(strict_types=1);

/**
 * Super Admin sessions: logging in, the platform pages' session checks, and opening a company.
 *
 * A Super Admin belongs to no company. To manage one from the inside, the platform opens that company's
 * database and works through an account of its own there (users.is_platform = 1, username "@platform-N"):
 * it has full authority in that company, every change it makes is in the company's audit trail under that
 * name, it can never be logged into directly (it has no usable password and no master record), and it is
 * left out of the company's user lists.
 */
final class Platform
{
    private static ?array $admin = null;

    // ------------------------------------------------------------------ log in / out

    /** @return array{0: bool, 1: string} */
    public static function attempt(array $sa, string $password): array
    {
        $m = Master::sql();
        $max = max(1, (int)Master::setting('max_failed_logins'));
        if ($sa['status'] === 'inactive') {
            Master::log('login_failed', null, 'Super Admin account inactive', ['super_admin', (int)$sa['id'], $sa['username']]);
            return [false, 'This account is inactive.'];
        }
        if ($sa['status'] === 'locked') {
            Master::log('login_failed', null, 'Super Admin account locked', ['super_admin', (int)$sa['id'], $sa['username']]);
            return [false, 'This account is locked. Another Super Admin can unlock it (or run tools/platform.php).'];
        }
        if (!password_verify($password, $sa['password_hash'])) {
            $fails = (int)$sa['failed_attempts'] + 1;
            $lock = $fails >= $max;
            $m->update('super_admins', ['failed_attempts' => $fails, 'status' => $lock ? 'locked' : 'active'], 'id = :id', ['id' => $sa['id']]);
            Master::log($lock ? 'account_locked' : 'login_failed', null, "Wrong password (attempt $fails of $max)", ['super_admin', (int)$sa['id'], $sa['username']]);
            return [false, $lock ? 'Too many failed attempts. The account is now locked.'
                : 'Invalid username or password. ' . ($max - $fails) . ' attempt(s) left before the account is locked.'];
        }
        session_regenerate_id(true);
        $_SESSION = ['platform_sa_id' => (int)$sa['id'], 'platform_last' => time(), 'csrf' => $_SESSION['csrf'] ?? bin2hex(random_bytes(16))];
        $m->update('super_admins', ['failed_attempts' => 0, 'last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => client_ip()], 'id = :id', ['id' => $sa['id']]);
        Master::log('login', null, 'Super Admin logged in', ['super_admin', (int)$sa['id'], $sa['username']]);
        return [true, ''];
    }

    public static function admin(): ?array
    {
        if (self::$admin === null && !empty($_SESSION['platform_sa_id']) && Master::enabled()) {
            $sa = Master::superAdmin((int)$_SESSION['platform_sa_id']);
            self::$admin = ($sa && $sa['status'] === 'active') ? $sa : null;
        }
        return self::$admin;
    }

    /** Platform pages: a logged-in, active Super Admin whose session has not timed out */
    public static function requireLogin(bool $allowPasswordChange = false): array
    {
        $sa = self::admin();
        if (!$sa) {
            redirect(base_url() . '/login.php');
        }
        $timeout = max(5, (int)Master::setting('session_timeout_minutes')) * 60;
        if (time() - (int)($_SESSION['platform_last'] ?? 0) > $timeout) {
            Master::log('session_timeout', null, 'Super Admin session timed out');
            $_SESSION = [];
            session_regenerate_id(true);
            flash('warning', 'You were logged out after a period of inactivity.');
            redirect(base_url() . '/login.php');
        }
        $_SESSION['platform_last'] = time();
        if (!$allowPasswordChange && (int)$sa['must_change_password'] === 1) {
            redirect(purl('account'));
        }
        return $sa;
    }

    public static function logout(): void
    {
        if ($sa = self::admin()) {
            Master::log('logout', null, 'Super Admin logged out', ['super_admin', (int)$sa['id'], $sa['username']]);
        }
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    // ------------------------------------------------------------------ opening a company

    /** Opens a company (active or inactive) as the Super Admin of this session */
    public static function enterCompany(int $companyId): void
    {
        $sa = self::admin();
        $c = Master::company($companyId, true);
        if (!$sa || !$c || !in_array($c['status'], ['active', 'inactive'], true) || (string)$c['db_name'] === '') {
            fail('That company cannot be opened.');
        }
        $db = Sql::to((string)$c['db_name']);
        $pending = Schema::pendingOn($db);
        if ($pending) {
            fail('The database of ' . $c['name'] . ' needs the latest update first. Use "Update databases" on the Companies page.');
        }
        $userId = self::platformUser($db, $sa);
        session_regenerate_id(true);
        $_SESSION['tenant_id'] = (int)$c['id'];
        $_SESSION['user_id'] = $userId;
        $_SESSION['platform_user_id'] = $userId;
        $_SESSION['last_activity'] = time();
        Master::log('company_opened', (int)$c['id'], 'Super Admin opened the company to manage it');
    }

    /** Back from a company to the platform pages */
    public static function leaveCompany(): void
    {
        if (!empty($_SESSION['tenant_id'])) {
            Master::log('company_closed', (int)$_SESSION['tenant_id'], 'Super Admin went back to the platform');
        }
        unset($_SESSION['tenant_id'], $_SESSION['user_id'], $_SESSION['platform_user_id'], $_SESSION['last_activity']);
        session_regenerate_id(true);
    }

    /**
     * The Super Admin's own account inside one company database, made the first time it is needed.
     * Its password is random and never shown: nobody can log in with it.
     */
    public static function platformUser(Sql $db, array $sa): int
    {
        $username = '@platform-' . (int)$sa['id'];
        $name = mb_substr('Super Admin: ' . $sa['full_name'], 0, 120);
        $id = $db->value('SELECT id FROM users WHERE username = :u', ['u' => $username]);
        if ($id) {
            // its (unknown, random) password never expires in the company's password rules either
            $db->update('users', ['full_name' => $name, 'status' => 'active', 'approval_status' => 'approved', 'is_platform' => 1,
                'must_change_password' => 0, 'failed_attempts' => 0, 'password_changed_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            return (int)$id;
        }
        return $db->insert('users', [
            'username' => $username, 'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
            'full_name' => $name, 'email' => $sa['email'], 'status' => 'active', 'approval_status' => 'approved',
            'created_via' => 'platform', 'is_platform' => 1, 'must_change_password' => 0, 'password_changed_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
