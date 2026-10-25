<?php
declare(strict_types=1);

/**
 * Access to another company: one person, one log-in, two (or more) companies, without Super Admin rights.
 *
 * A user belongs to one company (their home company: the master database finds it from the username at log-in).
 * An administrator of another company can give that user access to their company too (User Accounts > Add user from
 * another company). That makes, in the other company's database, a linked account with the same username and name:
 * its own roles, sites and POS terminals, set by that company, and a random password nobody knows (it never logs in by
 * itself). The master database keeps the link (user_company_links).
 *
 * The user logs in at home as always; the company name in the top bar becomes a switch listing their companies.
 * Switching opens the other company with the linked account and keeps the home company in the session
 * (home_tenant_id, home_user_id), so switching back needs no password. On every request Tenant::boot checks again that
 * the link is active, that the home account is still active, and that both companies are open; if not, the user is
 * taken back home. Everything they do in the other company is recorded there under the linked account.
 *
 * The access ends when either company removes it (the linked account is then deactivated, so its name stays on what it
 * entered) or when the home account is deactivated or locked.
 */
final class CompanyAccess
{
    private static ?bool $ready = null;
    private static ?array $switchable = null;

    private const TABLE = "CREATE TABLE IF NOT EXISTS user_company_links (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  home_company_id INT UNSIGNED NOT NULL,
  home_user_id    INT UNSIGNED NOT NULL,
  username        VARCHAR(50)  NOT NULL,
  company_id      INT UNSIGNED NOT NULL,
  company_user_id INT UNSIGNED NOT NULL,
  status          VARCHAR(12)  NOT NULL DEFAULT 'active',
  granted_by      VARCHAR(80)  NULL,
  granted_at      DATETIME     NULL,
  removed_by      VARCHAR(80)  NULL,
  removed_at      DATETIME     NULL,
  removed_reason  VARCHAR(255) NULL,
  last_switch_at  DATETIME     NULL,
  UNIQUE KEY uq_links_home_company (home_company_id, home_user_id, company_id),
  UNIQUE KEY uq_links_company_user (company_id, company_user_id),
  KEY ix_links_company (company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // ------------------------------------------------------------------ the table (master database)

    /** Is the feature usable: several companies, and the link table in the master database? */
    public static function ready(): bool
    {
        if (!Tenant::isMulti()) {
            return false;
        }
        if (self::$ready === null) {
            try {
                self::$ready = (bool)Master::sql()->value("SHOW TABLES LIKE 'user_company_links'");
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    /** Creates the link table the first time a link is made (installations from before this feature) */
    public static function ensure(): void
    {
        if (!Tenant::isMulti()) {
            fail('Access to another company needs several companies on the platform.');
        }
        if (!self::ready()) {
            Master::sql()->exec(self::TABLE);
            self::$ready = true;
        }
    }

    // ------------------------------------------------------------------ this session

    /** The home company and home account of the person logged in: [companyId, userId] */
    public static function home(): array
    {
        return [(int)($_SESSION['home_tenant_id'] ?? $_SESSION['tenant_id'] ?? 0), (int)($_SESSION['home_user_id'] ?? $_SESSION['user_id'] ?? 0)];
    }

    /** Is this session working in another company than the user's own? */
    public static function away(): bool
    {
        return empty($_SESSION['platform_sa_id']) && !empty($_SESSION['home_tenant_id']) && (int)$_SESSION['home_tenant_id'] !== (int)($_SESSION['tenant_id'] ?? 0);
    }

    /** The name of the user's home company (while away) */
    public static function homeName(): string
    {
        [$hc] = self::home();
        return (string)(Master::company($hc)['name'] ?? '');
    }

    /**
     * The companies the person logged in can switch between: [companyId => name], the current one included.
     * Empty when there is nothing to switch to (no link, or a Super Admin session).
     */
    public static function switchable(): array
    {
        if (self::$switchable !== null) {
            return self::$switchable;
        }
        self::$switchable = [];
        if (!self::ready() || Tenant::platformSession() || empty($_SESSION['user_id'])) {
            return self::$switchable;
        }
        [$hc, $hu] = self::home();
        $rows = Master::sql()->all(
            "SELECT l.company_id, c.name FROM user_company_links l JOIN companies c ON c.id = l.company_id
              WHERE l.home_company_id = :hc AND l.home_user_id = :hu AND l.status = 'active' AND c.status = 'active' ORDER BY c.name",
            ['hc' => $hc, 'hu' => $hu]
        );
        if (!$rows) {
            return self::$switchable;
        }
        $home = Master::company($hc);
        self::$switchable[$hc] = (string)($home['name'] ?? 'My company');
        foreach ($rows as $r) {
            self::$switchable[(int)$r['company_id']] = (string)$r['name'];
        }
        return self::$switchable;
    }

    /**
     * Tenant::boot, while away: is the link of this session still good? (active link from the session's home account,
     * the home account still active, the home company still open)
     */
    public static function validAway(int $companyId, int $userId): bool
    {
        if (!self::ready()) {
            return false;
        }
        [$hc, $hu] = self::home();
        $link = Master::sql()->row(
            "SELECT id FROM user_company_links WHERE company_id = :c AND company_user_id = :u AND home_company_id = :hc AND home_user_id = :hu AND status = 'active'",
            ['c' => $companyId, 'u' => $userId, 'hc' => $hc, 'hu' => $hu]
        );
        return $link && self::homeOk($hc, $hu);
    }

    /** The home account is still a member, active, of an active company */
    private static function homeOk(int $hc, int $hu): bool
    {
        $m = Master::membership($hc, $hu);
        $c = Master::company($hc);
        return $m && $m['status'] === 'active' && $c && $c['status'] === 'active';
    }

    /**
     * Back to the home company (the link of this session ended). Without a usable home account the session ends.
     */
    public static function backHome(string $message): never
    {
        [$hc, $hu] = self::home();
        if (!self::homeOk($hc, $hu)) {
            $_SESSION = [];
            session_regenerate_id(true);
            flash('warning', 'Your session has ended. Please log in again.');
            redirect(base_url() . '/login.php');
        }
        self::resetSession($hc, $hu);
        flash('warning', $message);
        redirect(base_url() . '/index.php');
    }

    /** Opens one of the user's companies in this session (the company switch in the top bar) */
    public static function switchTo(int $companyId): string
    {
        [$hc, $hu] = self::home();
        if (Tenant::platformSession() || !$hc || !$hu) {
            fail('This session cannot switch companies.');
        }
        if ($companyId === (int)Tenant::id()) {
            return (string)(Tenant::company()['name'] ?? '');
        }
        if (!self::homeOk($hc, $hu)) {
            fail('Your account is not active any more. Please log in again.');
        }
        $from = (string)(Tenant::company()['name'] ?? '');
        if ($companyId === $hc) {
            self::resetSession($hc, $hu);
            Tenant::use(Master::company($hc, true));
            Audit::log('company_switch', 'security', 'user', $hu, (string)(Auth::user()['username'] ?? ''), ['reason' => 'Back from ' . $from]);
            Master::log('company_switched', $hc, 'Back to the home company from ' . $from, ['user', $hu, (string)(Auth::user()['username'] ?? '')]);
            return (string)Master::company($hc)['name'];
        }
        if (!self::ready()) {
            fail('You have no access to that company.');
        }
        $link = Master::sql()->row("SELECT * FROM user_company_links WHERE home_company_id = :hc AND home_user_id = :hu AND company_id = :c AND status = 'active'",
            ['hc' => $hc, 'hu' => $hu, 'c' => $companyId]);
        $c = Master::company($companyId, true);
        if (!$link || !$c || $c['status'] !== 'active' || (string)$c['db_name'] === '') {
            fail('You have no access to that company, or it is not open at the moment.');
        }
        $db = Sql::to((string)$c['db_name']);
        if (Schema::pendingOn($db)) {
            fail('The database of ' . $c['name'] . ' needs the latest update first. Please ask the platform administrator (Super Admin).');
        }
        $acct = $db->row('SELECT id, status, approval_status FROM users WHERE id = :id', ['id' => (int)$link['company_user_id']]);
        if (!$acct || $acct['status'] !== 'active' || ($acct['approval_status'] ?? 'approved') !== 'approved') {
            fail('Your access to ' . $c['name'] . ' is switched off there. Please ask its administrator.');
        }
        self::resetSession($hc, $hu, $companyId, (int)$acct['id']);
        Tenant::use($c);
        $db->update('users', ['last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => client_ip(), 'failed_attempts' => 0], 'id = :id', ['id' => $acct['id']]);
        Master::sql()->update('user_company_links', ['last_switch_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $link['id']]);
        Audit::log('company_switch', 'security', 'user', (int)$acct['id'], (string)$link['username'], ['reason' => 'Switched from ' . $from]);
        Master::log('company_switched', $companyId, $link['username'] . ' switched here from ' . $from, ['user', $hu, (string)$link['username']]);
        return (string)$c['name'];
    }

    /** A fresh session for one company: only the CSRF token, the messages and the home identity are carried over */
    private static function resetSession(int $hc, int $hu, ?int $companyId = null, ?int $userId = null): void
    {
        $keep = array_intersect_key($_SESSION, ['csrf' => 1, 'flash' => 1]);
        session_regenerate_id(true);
        $_SESSION = $keep + [
            'home_tenant_id' => $hc,
            'home_user_id'   => $hu,
            'tenant_id'      => $companyId ?? $hc,
            'user_id'        => $userId ?? $hu,
            'last_activity'  => time(),
        ];
        self::$switchable = null;
    }

    // ------------------------------------------------------------------ the accounts

    /** Is this user row a linked account (a user of another company working here)? */
    public static function isLinked(array $u): bool
    {
        return ($u['created_via'] ?? '') === 'link';
    }

    /** The link of a linked account of the company open now, with the home company's name */
    public static function linkOf(int $companyUserId, ?int $companyId = null): ?array
    {
        if (!self::ready()) {
            return null;
        }
        return Master::sql()->row(
            'SELECT l.*, c.name AS home_name, c.status AS home_status FROM user_company_links l JOIN companies c ON c.id = l.home_company_id
              WHERE l.company_id = :c AND l.company_user_id = :u',
            ['c' => $companyId ?? (int)Tenant::id(), 'u' => $companyUserId]
        );
    }

    /** linkOf for every linked account of the company open now: [companyUserId => link] */
    public static function linksHere(): array
    {
        if (!self::ready()) {
            return [];
        }
        $out = [];
        foreach (Master::sql()->all('SELECT l.*, c.name AS home_name FROM user_company_links l JOIN companies c ON c.id = l.home_company_id WHERE l.company_id = :c',
            ['c' => (int)Tenant::id()]) as $l) {
            $out[(int)$l['company_user_id']] = $l;
        }
        return $out;
    }

    /** Users of the company open now who may also work in other companies: [userId => [company names]] */
    public static function linksOut(): array
    {
        if (!self::ready()) {
            return [];
        }
        $out = [];
        foreach (Master::sql()->all("SELECT l.home_user_id, c.name FROM user_company_links l JOIN companies c ON c.id = l.company_id
                                      WHERE l.home_company_id = :c AND l.status = 'active' ORDER BY c.name", ['c' => (int)Tenant::id()]) as $r) {
            $out[(int)$r['home_user_id']][] = (string)$r['name'];
        }
        return $out;
    }

    /** The other companies a user of this company can work in (their links, any status), with the company names */
    public static function linksFrom(int $homeUserId): array
    {
        if (!self::ready()) {
            return [];
        }
        return Master::sql()->all(
            'SELECT l.*, c.name AS company_name, c.status AS company_status FROM user_company_links l JOIN companies c ON c.id = l.company_id
              WHERE l.home_company_id = :hc AND l.home_user_id = :hu ORDER BY c.name',
            ['hc' => (int)Tenant::id(), 'hu' => $homeUserId]
        );
    }

    /**
     * Gives a user of another company access to the company open now. Returns the linked account's id.
     * $username is the user's username in their own company.
     */
    public static function grant(string $username, array $roleIds, array $siteIds, array $termIds, string $reason = ''): int
    {
        self::ensure();
        $here = (int)Tenant::id();
        $username = mb_strtolower(trim($username));
        if ($username === '') {
            fail('Enter the username the person logs in with at their own company.');
        }
        $m = Master::membershipByUsername($username);
        $home = $m ? Master::company((int)$m['company_id'], true) : null;
        if (!$m || !$home || $m['status'] !== 'active' || $home['status'] !== 'active') {
            Master::log('link_lookup_failed', $here, 'No active user of another company: ' . mb_substr($username, 0, 50));
            fail('No active user of another company logs in with that username. Check the spelling with the person.');
        }
        if ((int)$m['company_id'] === $here) {
            fail($username . ' is already a user of this company. Change their roles in User Accounts instead.');
        }
        if (!$roleIds) {
            fail('Give at least one role in this company.');
        }
        $existing = Master::sql()->row('SELECT * FROM user_company_links WHERE home_company_id = :hc AND home_user_id = :hu AND company_id = :c',
            ['hc' => (int)$m['company_id'], 'hu' => (int)$m['company_user_id'], 'c' => $here]);
        if ($existing && $existing['status'] === 'active') {
            fail($username . ' already has access to this company.');
        }
        $id = DB::tx(function () use ($m, $username, $roleIds, $siteIds, $termIds, $existing, $home, $reason) {
            $local = DB::row('SELECT * FROM users WHERE username = :u', ['u' => $username]);
            if ($local && (!self::isLinked($local) || !$existing || (int)$existing['company_user_id'] !== (int)$local['id'])) {
                fail('This company already has an account named ' . $username . '. Ask the Super Admin to rename one of them first.');
            }
            $data = ['full_name' => mb_substr((string)($m['full_name'] ?: $username), 0, 120), 'email' => $m['email'] ?: null, 'status' => 'active',
                'approval_status' => 'approved', 'must_change_password' => 0, 'failed_attempts' => 0, 'password_changed_at' => date('Y-m-d H:i:s')];
            if ($local) {
                DB::update('users', $data, 'id = :id', ['id' => $local['id']]);
                $id = (int)$local['id'];
            } else {
                $id = DB::insert('users', $data + [
                    'username' => $username, 'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                    'created_via' => 'link', 'is_platform' => 0, 'created_by' => Auth::id(),
                ]);
            }
            self::setAccess($id, $roleIds, $siteIds, $termIds);
            Audit::log('user_link_added', 'admin', 'user', $id, $username, [
                'reason' => trim('Works here from ' . $home['name'] . ' (same log-in). ' . $reason),
                'changes' => [['field' => 'access', 'old' => $local ? 'removed' : '', 'new' => 'from ' . $home['name']],
                    ['field' => 'roles', 'old' => '', 'new' => implode(', ', DB::column('SELECT name FROM roles WHERE id IN ' . DB::in('x', $roleIds)[0] . ' ORDER BY name', DB::in('x', $roleIds)[1]))]],
            ]);
            return $id;
        });
        $by = (string)(Auth::user()['username'] ?? 'Super Admin');
        if ($existing) {
            Master::sql()->update('user_company_links', ['status' => 'active', 'company_user_id' => $id, 'granted_by' => $by, 'granted_at' => date('Y-m-d H:i:s'),
                'removed_by' => null, 'removed_at' => null, 'removed_reason' => null], 'id = :id', ['id' => $existing['id']]);
        } else {
            Master::sql()->insert('user_company_links', ['home_company_id' => (int)$m['company_id'], 'home_user_id' => (int)$m['company_user_id'],
                'username' => $username, 'company_id' => $here, 'company_user_id' => $id, 'status' => 'active', 'granted_by' => $by, 'granted_at' => date('Y-m-d H:i:s')]);
        }
        Master::log('user_link_added', $here, $username . ' of ' . $home['name'] . ' can now switch to this company' . ($reason !== '' ? ': ' . $reason : ''));
        Master::log('user_link_added', (int)$home['id'], $username . ' can now switch to ' . (Tenant::company()['name'] ?? ''));
        return $id;
    }

    /** Roles, sites and POS terminals of an account of the company open now */
    public static function setAccess(int $userId, array $roleIds, array $siteIds, array $termIds): void
    {
        DB::run('DELETE FROM user_roles WHERE user_id = :u', ['u' => $userId]);
        foreach (array_unique(array_map('intval', $roleIds)) as $r) {
            DB::insert('user_roles', ['user_id' => $userId, 'role_id' => $r]);
        }
        DB::run('DELETE FROM user_sites WHERE user_id = :u', ['u' => $userId]);
        foreach (array_unique(array_map('intval', $siteIds)) as $s) {
            DB::insert('user_sites', ['user_id' => $userId, 'site_id' => $s]);
        }
        DB::run('DELETE FROM user_terminals WHERE user_id = :u', ['u' => $userId]);
        foreach (array_unique(array_map('intval', $termIds)) as $t) {
            DB::insert('user_terminals', ['user_id' => $userId, 'terminal_id' => $t]);
        }
    }

    /**
     * Ends a link. From the company the user works in ($linkId of one of its linked accounts) or from the user's home
     * company ($linkId of one of its users' links). The linked account is deactivated: its name stays on its records.
     */
    public static function remove(int $linkId, string $reason = ''): array
    {
        self::ensure();
        $l = Master::sql()->row('SELECT * FROM user_company_links WHERE id = :id', ['id' => $linkId]);
        $here = (int)Tenant::id();
        if (!$l || ((int)$l['company_id'] !== $here && (int)$l['home_company_id'] !== $here)) {
            fail('That access was not found.');
        }
        if ($l['status'] !== 'active') {
            fail('That access was already removed.');
        }
        $target = Master::company((int)$l['company_id'], true);
        $by = (string)(Auth::user()['username'] ?? 'Super Admin');
        $reason = mb_substr(trim($reason), 0, 255);
        $note = 'Access from ' . (Master::company((int)$l['home_company_id'])['name'] ?? '') . ' removed' . ((int)$l['home_company_id'] === $here ? ' by the home company' : '');
        if ((int)$l['company_id'] === $here) {
            $u = DB::row('SELECT * FROM users WHERE id = :id', ['id' => (int)$l['company_user_id']]);
            if ($u) {
                DB::update('users', ['status' => 'inactive'], 'id = :id', ['id' => $u['id']]);
                Audit::log('user_link_removed', 'admin', 'user', (int)$u['id'], $u['username'], ['reason' => trim($note . '. ' . $reason),
                    'changes' => [['field' => 'status', 'old' => $u['status'], 'new' => 'inactive']]]);
            }
        } elseif ($target && (string)$target['db_name'] !== '') {
            // from the home company: the account in the other company is switched off there, and the change is written in its audit trail
            $db = Sql::to((string)$target['db_name']);
            $u = $db->row('SELECT * FROM users WHERE id = :id', ['id' => (int)$l['company_user_id']]);
            if ($u) {
                $db->update('users', ['status' => 'inactive'], 'id = :id', ['id' => $u['id']]);
                try {
                    $db->insert('audit_trail', ['user_id' => null, 'username' => mb_substr($by . ' (' . (Tenant::company()['name'] ?? '') . ')', 0, 50), 'user_roles' => null,
                        'ip_address' => client_ip(), 'module' => 'admin', 'page_key' => 'admin.users', 'action' => 'user_link_removed',
                        'record_type' => 'user', 'record_id' => (int)$u['id'], 'record_ref' => $u['username'], 'reason' => mb_substr(trim($note . '. ' . $reason), 0, 255),
                        'changes' => json_encode([['field' => 'status', 'old' => $u['status'], 'new' => 'inactive']])]);
                } catch (Throwable $e) {
                    error_log('link removal audit: ' . $e->getMessage());
                }
            }
            Audit::log('user_link_removed', 'admin', 'user', (int)$l['home_user_id'], (string)$l['username'],
                ['reason' => trim('Access to ' . ($target['name'] ?? '') . ' removed. ' . $reason)]);
        }
        Master::sql()->update('user_company_links', ['status' => 'removed', 'removed_by' => $by, 'removed_at' => date('Y-m-d H:i:s'), 'removed_reason' => $reason ?: null],
            'id = :id', ['id' => $linkId]);
        Master::log('user_link_removed', (int)$l['company_id'], $l['username'] . ': access from ' . (Master::company((int)$l['home_company_id'])['name'] ?? '') . ' removed' . ($reason !== '' ? ': ' . $reason : ''));
        if ((int)$l['home_company_id'] !== (int)$l['company_id']) {
            Master::log('user_link_removed', (int)$l['home_company_id'], $l['username'] . ': access to ' . ($target['name'] ?? '') . ' removed');
        }
        return $l;
    }

    /** Gives a removed access back (the company the user works in, from its User Accounts) */
    public static function restore(int $linkId): void
    {
        self::ensure();
        $l = Master::sql()->row('SELECT * FROM user_company_links WHERE id = :id AND company_id = :c', ['id' => $linkId, 'c' => (int)Tenant::id()]);
        if (!$l) {
            fail('That access was not found.');
        }
        if (!self::homeOk((int)$l['home_company_id'], (int)$l['home_user_id'])) {
            fail('The account at the user\'s own company is not active, so access cannot be given back.');
        }
        $roles = array_map('intval', DB::column('SELECT role_id FROM user_roles WHERE user_id = :u', ['u' => $l['company_user_id']]));
        if (!$roles) {
            fail('Give the account a role first.');
        }
        $u = DB::row('SELECT * FROM users WHERE id = :id', ['id' => (int)$l['company_user_id']]);
        DB::update('users', ['status' => 'active', 'failed_attempts' => 0], 'id = :id', ['id' => $l['company_user_id']]);
        Master::sql()->update('user_company_links', ['status' => 'active', 'granted_by' => (string)(Auth::user()['username'] ?? 'Super Admin'),
            'granted_at' => date('Y-m-d H:i:s'), 'removed_by' => null, 'removed_at' => null, 'removed_reason' => null], 'id = :id', ['id' => $linkId]);
        Audit::log('user_link_added', 'admin', 'user', (int)$l['company_user_id'], (string)$l['username'], ['reason' => 'Access given back',
            'changes' => [['field' => 'status', 'old' => $u['status'] ?? '', 'new' => 'active']]]);
        Master::log('user_link_added', (int)$l['company_id'], $l['username'] . ': access given back');
    }

    /**
     * Supervisor approval by username and password, for a linked account: the password is the one of the user's own
     * company (the linked account has none anybody knows).
     */
    public static function verifyHomePassword(array $u, string $password): bool
    {
        $l = self::linkOf((int)$u['id']);
        if (!$l || $l['status'] !== 'active' || !self::homeOk((int)$l['home_company_id'], (int)$l['home_user_id'])) {
            return false;
        }
        $home = Master::company((int)$l['home_company_id']);
        $hu = Sql::to((string)$home['db_name'])->row("SELECT password_hash FROM users WHERE id = :id AND status = 'active'", ['id' => (int)$l['home_user_id']]);
        return $hu && password_verify($password, (string)$hu['password_hash']);
    }
}
