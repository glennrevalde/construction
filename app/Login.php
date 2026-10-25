<?php
declare(strict_types=1);

/**
 * The log-in page's decision: who is this, and where do they belong?
 *
 * With one company it is the log-in it always was. With several:
 *   1. a Super Admin username (master database) goes to the platform pages;
 *   2. any other username is looked up in the master database, which says which company it belongs to;
 *   3. that company has to be active; its database is opened (Tenant::use) and the password, lock-out,
 *      approval and password rules are checked there, in the company's own users table;
 *   4. on success the session is tied to that company (Auth::attempt stores the company Tenant opened): from then on every request opens
 *      that company's database and no other.
 * The company is never chosen by the person logging in, and a failed attempt says nothing about which
 * companies or databases exist.
 */
final class Login
{
    /** @return array{0: bool, 1: string, 2: string} ok, message, where to go */
    public static function attempt(string $username, string $password): array
    {
        $username = trim($username);
        if (!Tenant::isMulti()) {
            [$ok, $err] = Auth::attempt($username, $password);
            return [$ok, $err, url('home.dashboard')];
        }
        if ($username === '' || $password === '') {
            return [false, 'Enter your username and password.', ''];
        }
        if (self::tooManyFromHere()) {
            return [false, 'Too many failed log-in attempts from this computer. Please wait 15 minutes and try again.', ''];
        }

        // 1. a Super Admin
        if ($sa = Master::superAdminByUsername($username)) {
            [$ok, $err] = Platform::attempt($sa, $password);
            return [$ok, $err, base_url() . '/platform.php'];
        }

        // 2. which company the username belongs to
        $m = Master::membershipByUsername($username);
        $c = $m ? Master::company((int)$m['company_id'], true) : null;
        if (!$m || !$c || (string)$c['db_name'] === '' || !in_array($c['status'], ['active', 'inactive'], true)) {
            Master::log('login_failed', $m ? (int)$m['company_id'] : null, 'Unknown username: ' . mb_substr($username, 0, 50), ['guest', null, null]);
            return [false, 'Invalid username or password.', ''];
        }

        // 3. the company's own database decides the rest
        if ($c['status'] !== 'active') {
            $u = Sql::to((string)$c['db_name'])->row('SELECT password_hash FROM users WHERE id = :id', ['id' => (int)$m['company_user_id']]);
            if (!$u || !password_verify($password, (string)$u['password_hash'])) {
                return [false, 'Invalid username or password.', ''];
            }
            Master::log('login_refused', (int)$c['id'], 'Company is inactive: ' . $username, ['user', (int)$m['company_user_id'], $username]);
            return [false, 'Your company\'s access is currently suspended. Please contact your Company Owner.', ''];
        }
        Tenant::use($c);
        [$ok, $err] = Auth::attempt($username, $password);
        if (!$ok) {
            return [false, $err, ''];
        }
        // 4. the session now belongs to this company (Auth::attempt set tenant_id from Tenant, not from the request)
        if ((int)Auth::id() !== (int)$m['company_user_id']) {
            // the master record points at another account than the one that just logged in: refuse, and fix the record
            Membership::refresh((int)Auth::id());
            Auth::logout();
            return [false, 'Your account needs to be checked by your Company Owner before you can log in.', ''];
        }
        return [true, '', url('home.dashboard')];
    }

    /** More than 30 failed attempts with unknown usernames from one address in 15 minutes */
    private static function tooManyFromHere(): bool
    {
        $n = (int)Master::sql()->value(
            "SELECT COUNT(*) FROM platform_audit WHERE action = 'login_failed' AND ip_address = :ip AND created_at > NOW() - INTERVAL 15 MINUTE",
            ['ip' => client_ip()]
        );
        return $n >= 30;
    }
}
