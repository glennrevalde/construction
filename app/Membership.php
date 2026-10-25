<?php
declare(strict_types=1);

/**
 * The master database's record of which company every username belongs to
 * (user_company_memberships). The log-in page reads it to know which company database to open.
 *
 * The account itself (password, roles, lock-out, status) lives in the company database, and the master
 * record is written from it: after a user is created or changed, after a registration, and in full
 * (sync) after a restore. Usernames are unique across all companies and the Super Admins.
 */
final class Membership
{
    /** What the master database shows for a user row: pending / rejected / active / inactive / locked */
    public static function statusOf(array $u): string
    {
        $a = (string)($u['approval_status'] ?? 'approved');
        if ($a === 'pending' || $a === 'rejected') {
            return $a;
        }
        return (string)$u['status'];
    }

    /** Writes the master record of one user of the company open in this request */
    public static function refresh(int $userId): void
    {
        if (!Tenant::isMulti() || !Tenant::id()) {
            return;
        }
        $u = DB::row('SELECT * FROM users WHERE id = :id', ['id' => $userId]);
        $companyId = (int)Tenant::id();
        if (!$u) {
            Master::sql()->run('DELETE FROM user_company_memberships WHERE company_id = :c AND company_user_id = :u', ['c' => $companyId, 'u' => $userId]);
            return;
        }
        self::write($companyId, $u);
    }

    /**
     * Writes one user's master record. A username that belongs to another company is never taken over:
     * the user is left without a record (they cannot log in) and false is returned.
     */
    public static function write(int $companyId, array $u): bool
    {
        // the Super Admin's account, and users of another company working here (they log in at their own company)
        if ((int)($u['is_platform'] ?? 0) === 1 || ($u['created_via'] ?? '') === 'link') {
            return true;
        }
        $m = Master::sql();
        $holder = $m->row('SELECT company_id FROM user_company_memberships WHERE username = :n', ['n' => $u['username']]);
        if (($holder && (int)$holder['company_id'] !== $companyId)
            || $m->value('SELECT id FROM super_admins WHERE username = :n', ['n' => $u['username']])) {
            $m->run('DELETE FROM user_company_memberships WHERE company_id = :c AND company_user_id = :u', ['c' => $companyId, 'u' => (int)$u['id']]);
            return false;
        }
        $m->run('DELETE FROM user_company_memberships WHERE company_id = :c AND (company_user_id = :u OR username = :n)',
            ['c' => $companyId, 'u' => (int)$u['id'], 'n' => $u['username']]);
        $m->insert('user_company_memberships', [
            'company_id' => $companyId, 'company_user_id' => (int)$u['id'], 'username' => $u['username'],
            'full_name' => $u['full_name'] ?? null, 'email' => $u['email'] ?? null, 'status' => self::statusOf($u),
            'created_via' => (string)($u['created_via'] ?? 'admin'), 'joined_at' => $u['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return true;
    }

    /**
     * Rebuilds every master record of one company from its users table (after a restore, or on request).
     * @return array{users: int, conflicts: string[]}  conflicts = usernames already used by another company
     */
    public static function sync(int $companyId, Sql $companyDb): array
    {
        $users = $companyDb->all("SELECT * FROM users WHERE is_platform = 0 AND created_via <> 'link' ORDER BY id");
        $conflicts = [];
        Master::sql()->tx(function (Sql $m) use ($companyId, $users, &$conflicts) {
            $m->run('DELETE FROM user_company_memberships WHERE company_id = :c', ['c' => $companyId]);
            foreach ($users as $u) {
                if (!self::write($companyId, $u)) {
                    $conflicts[] = $u['username'];
                }
            }
        });
        return ['users' => count($users) - count($conflicts), 'conflicts' => $conflicts];
    }

    /** Is the username free for this user (across every company and the Super Admins)? */
    public static function usernameFree(string $username, ?int $userId = null): bool
    {
        if (!Tenant::isMulti()) {
            return true;
        }
        return Master::usernameFree($username, Tenant::id(), $userId);
    }
}
