<?php
declare(strict_types=1);

/** Login, lockout, password policy and session timeout. */
final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = $_SESSION['user_id'] ?? null;
            if ($id && !DB::isBlocked()) {
                self::$user = DB::row('SELECT * FROM users WHERE id = :id', ['id' => $id]);
                if (!self::$user || self::$user['status'] !== 'active' || (self::$user['approval_status'] ?? 'approved') !== 'approved') {
                    self::$user = null;
                    unset($_SESSION['user_id']);
                }
            }
        }
        return self::$user;
    }

    /** Forget the loaded user (another company's database was switched on) */
    public static function flush(): void
    {
        self::$loaded = false;
        self::$user = null;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int)$u['id'] : null;
    }

    /** For CLI tools and tests */
    public static function actAs(int $userId): void
    {
        $_SESSION['user_id'] = $userId;
        self::$loaded = false;
        self::$user = null;
        Access::reset();
    }

    public static function attempt(string $username, string $password): array
    {
        $u = DB::row('SELECT * FROM users WHERE username = :u', ['u' => $username]);
        $max = max(1, Settings::int('max_failed_logins'));

        if (!$u || (int)($u['is_platform'] ?? 0) === 1 || ($u['created_via'] ?? '') === 'link') {
            Audit::log('login_failed', 'security', 'user', null, $username, ['user_id' => null, 'username' => $username, 'roles' => '', 'reason' => 'Unknown username']);
            return [false, 'Invalid username or password.'];
        }
        // an employee who joined with the Company Code: only told where the registration stands once the password is right
        $approval = (string)($u['approval_status'] ?? 'approved');
        if ($approval !== 'approved') {
            if (!password_verify($password, $u['password_hash'])) {
                Audit::log('login_failed', 'security', 'user', (int)$u['id'], $username, ['user_id' => (int)$u['id'], 'username' => $username, 'roles' => '', 'reason' => 'Wrong password (registration ' . $approval . ')']);
                return [false, 'Invalid username or password.'];
            }
            Audit::log('login_failed', 'security', 'user', (int)$u['id'], $username, ['user_id' => (int)$u['id'], 'username' => $username, 'roles' => '', 'reason' => 'Registration ' . $approval]);
            return [false, $approval === 'pending'
                ? 'Your registration is waiting for approval by your Company Owner. You can log in once it is approved.'
                : 'Your registration was not approved. Please contact your Company Owner.'];
        }
        if ($u['status'] === 'inactive') {
            Audit::log('login_failed', 'security', 'user', (int)$u['id'], $username, ['user_id' => (int)$u['id'], 'username' => $username, 'roles' => '', 'reason' => 'Account inactive']);
            return [false, 'This account is inactive. Contact your administrator.'];
        }
        if ($u['status'] === 'locked') {
            Audit::log('login_failed', 'security', 'user', (int)$u['id'], $username, ['user_id' => (int)$u['id'], 'username' => $username, 'roles' => '', 'reason' => 'Account locked']);
            return [false, 'This account is locked. Only an administrator can unlock it.'];
        }
        if (!password_verify($password, $u['password_hash'])) {
            $fails = (int)$u['failed_attempts'] + 1;
            $lock = $fails >= $max;
            DB::update('users', ['failed_attempts' => $fails, 'status' => $lock ? 'locked' : 'active'], 'id = :id', ['id' => $u['id']]);
            Audit::log('login_failed', 'security', 'user', (int)$u['id'], $username, [
                'user_id' => (int)$u['id'], 'username' => $username, 'roles' => '',
                'reason' => "Wrong password (attempt $fails of $max)",
            ]);
            if ($lock) {
                Audit::log('account_locked', 'security', 'user', (int)$u['id'], $username, [
                    'user_id' => (int)$u['id'], 'username' => $username, 'roles' => '',
                    'reason' => "Locked after $fails failed login attempts",
                ]);
                Membership::refresh((int)$u['id']);
                return [false, 'Too many failed attempts. The account is now locked.'];
            }
            return [false, 'Invalid username or password. ' . ($max - $fails) . ' attempt(s) left before the account is locked.'];
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$u['id'];
        if (Tenant::isMulti()) {
            // the company comes from the master database (the caller opened it), never from the request
            $_SESSION['tenant_id'] = (int)Tenant::id();
            unset($_SESSION['platform_sa_id'], $_SESSION['platform_user_id']);
            // the user's own company: other companies that gave them access are opened from it (CompanyAccess)
            $_SESSION['home_tenant_id'] = (int)Tenant::id();
            $_SESSION['home_user_id'] = (int)$u['id'];
        }
        $_SESSION['last_activity'] = time();
        DB::update('users', [
            'failed_attempts' => 0,
            'last_login_at'   => date('Y-m-d H:i:s'),
            'last_login_ip'   => client_ip(),
        ], 'id = :id', ['id' => $u['id']]);
        self::$loaded = false;
        Access::reset();
        Audit::log('login', 'security', 'user', (int)$u['id'], $username);
        return [true, ''];
    }

    public static function logout(string $reason = ''): void
    {
        if (self::user()) {
            Audit::log($reason === 'timeout' ? 'session_timeout' : 'logout', 'security', 'user', self::id(), self::user()['username']);
        }
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        self::$user = null;
        self::$loaded = true;
    }

    /** Enforces login, session timeout, forced password change and password expiry. */
    public static function requireLogin(string $route): void
    {
        $timeout = max(1, Settings::int('session_timeout_minutes')) * 60;
        if (self::user() && isset($_SESSION['last_activity']) && time() - (int)$_SESSION['last_activity'] > $timeout) {
            self::logout('timeout');
            session_start();
            flash('warning', 'You were logged out after a period of inactivity.');
            redirect(base_url() . '/login.php');
        }
        if (!self::user()) {
            redirect(base_url() . '/login.php');
        }
        $_SESSION['last_activity'] = time();

        // a user of another company working here: the password and its rules are those of their own company
        if ($route !== 'auth.change_password' && $route !== 'auth.logout' && (self::user()['created_via'] ?? '') !== 'link') {
            $u = self::user();
            $expiry = Settings::int('pw_expiry_days');
            $expired = $expiry > 0 && $u['password_changed_at']
                && strtotime($u['password_changed_at']) < strtotime("-$expiry days");
            if ((int)$u['must_change_password'] === 1 || $expired) {
                if ($expired && (int)$u['must_change_password'] !== 1) {
                    flash('warning', 'Your password has expired. Please choose a new one.');
                }
                redirect(url('auth.change_password'));
            }
        }
    }

    /**
     * Returns a list of policy violations (empty = OK). The rules are this company's; the platform passes
     * another company's settings ($settings) when it sets a password there.
     */
    public static function passwordProblems(string $pw, ?string $username = null, ?array $settings = null): array
    {
        $s = ($settings ?? []) + Settings::DEFAULTS;
        if ($settings === null) {
            $s = Settings::all();
        }
        $errors = [];
        $min = (int)$s['pw_min_length'];
        if (mb_strlen($pw) < $min) {
            $errors[] = "at least $min characters";
        }
        if ($s['pw_require_upper'] === '1' && (!preg_match('/[A-Z]/', $pw) || !preg_match('/[a-z]/', $pw))) {
            $errors[] = 'upper- and lower-case letters';
        }
        if ($s['pw_require_number'] === '1' && !preg_match('/\d/', $pw)) {
            $errors[] = 'a number';
        }
        if ($s['pw_require_symbol'] === '1' && !preg_match('/[^A-Za-z0-9]/', $pw)) {
            $errors[] = 'a symbol';
        }
        if ($username && stripos($pw, $username) !== false) {
            $errors[] = 'not contain the username';
        }
        return $errors;
    }

    public static function hash(string $pw): string
    {
        return password_hash($pw, PASSWORD_DEFAULT);
    }

    /** Verify another user's credentials (supervisor approval). */
    public static function verifyOther(string $username, string $password): ?array
    {
        $u = DB::row("SELECT * FROM users WHERE username = :u AND status = 'active' AND approval_status = 'approved' AND is_platform = 0", ['u' => $username]);
        if (!$u) {
            return null;
        }
        // a user of another company working here approves with the password of their own company
        $ok = ($u['created_via'] ?? '') === 'link' ? CompanyAccess::verifyHomePassword($u, $password) : password_verify($password, $u['password_hash']);
        if (!$ok) {
            return null;
        }
        return $u;
    }
}
