<?php
declare(strict_types=1);

/**
 * User roles, page access and permissions.
 *
 *  - Page access (allowed) decides whether a user can open a page at all.
 *  - Role permissions (create / edit / delete / print / approve) decide what the user can do there.
 *  - A user-level override always takes precedence over the user's roles.
 *  - The Administrator role (is_admin) can open everything.
 */
final class Access
{
    public const ACTIONS = ['create' => 'Create', 'edit' => 'Edit', 'delete' => 'Delete/Void', 'print' => 'Print/Export', 'approve' => 'Approve'];

    public const SPECIAL = [
        'view_costs'            => ['View costs and margins', 'bool'],
        'view_bank_balances'    => ['View bank balances outside Banking', 'bool'],
        'max_discount_pct'      => ['Max discount % without approval', 'number'],
        'max_price_override_pct'=> ['Max price change % without approval', 'number'],
        'max_amount'            => ['Max check / bill payment / adjustment amount without approval', 'number'],
        'credit_limit_override' => ['Can exceed customer credit limits', 'bool'],
        'coa_changes'           => ['Can change the Chart of Accounts and default accounts', 'bool'],
        'closed_period_changes' => ['Can change transactions on/before the closing date or already reconciled', 'bool'],
        'design_reports'        => ['Can design report layouts (fonts, headings and footers, columns and rows) and save or share them', 'bool'],
    ];

    /** Role combinations that should be kept apart: [pageA, pageB, description] */
    public const SOD_RULES = [
        ['vend.center', 'vend.pay_bills', 'creating vendors and paying bills'],
        ['cust.payments', 'bank.reconcile', 'receiving customer payments and reconciling the bank account'],
        ['inv.adjust', 'inv.physical_count', 'adjusting inventory and counting it'],
        ['vend.bills', 'bank.write_checks', 'entering bills and writing checks'],
        ['pos.register', 'bank.deposits', 'selling at the POS and depositing the cash'],
    ];

    private static array $cache = [];

    public static function reset(): void
    {
        self::$cache = [];
    }

    private static function registry(): array
    {
        static $r = null;
        return $r ??= require APP_ROOT . '/app/registry.php';
    }

    public static function pageDef(string $key): ?array
    {
        $d = self::registry()['pages'][$key] ?? null;
        if (!$d) {
            return null;
        }
        [$module, $name, $type, $group, $file, $params, $menu] = $d;
        return compact('key', 'module', 'name', 'type', 'group', 'file', 'params', 'menu');
    }

    public static function allPageDefs(): array
    {
        $out = [];
        foreach (self::registry()['pages'] as $k => $_) {
            $out[$k] = self::pageDef($k);
        }
        return $out;
    }

    public static function modules(): array
    {
        return self::registry()['modules'];
    }

    /** The name of a module's card on the Report Center: its own (registry 'report_center_names') or the module's */
    public static function reportCenterName(string $code): string
    {
        return (string)(self::registry()['report_center_names'][$code] ?? (self::modules()[$code][0] ?? $code));
    }

    /** The sections of the side rail: code => [name, icon, [module codes]] */
    public static function registry_sections(): array
    {
        return self::registry()['sections'] ?? [];
    }

    public static function menuAliases(): array
    {
        return self::registry()['menu_aliases'];
    }

    public static function openRoute(string $key): ?string
    {
        return self::registry()['open_routes'][$key] ?? null;
    }

    // --------------------------------------------------------------- roles

    public static function roles(?int $userId = null): array
    {
        $userId ??= Auth::id();
        if (!$userId) {
            return [];
        }
        return self::$cache["roles:$userId"] ??= DB::all(
            'SELECT r.* FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = :u ORDER BY r.name',
            ['u' => $userId]
        );
    }

    public static function roleNames(?int $userId = null): array
    {
        return array_column(self::roles($userId), 'name');
    }

    public static function isAdmin(?int $userId = null): bool
    {
        // the Company Owner and a Super Admin working in the company have full authority in it
        if (Tenant::hasAuthority($userId ?? Auth::id())) {
            return true;
        }
        foreach (self::roles($userId) as $r) {
            if ((int)$r['is_admin'] === 1) {
                return true;
            }
        }
        return false;
    }

    // --------------------------------------------------------------- effective access

    /**
     * page_key => ['allowed'=>bool, 'create'=>..., 'edit'=>..., 'delete'=>..., 'print'=>..., 'approve'=>..., 'source'=>string]
     */
    public static function effective(?int $userId = null): array
    {
        $userId ??= Auth::id();
        if (!$userId) {
            return [];
        }
        if (isset(self::$cache["eff:$userId"])) {
            return self::$cache["eff:$userId"];
        }
        $pages = DB::pairs('SELECT page_key, id FROM pages');
        $out = [];
        $admin = self::isAdmin($userId);
        $authority = Tenant::hasAuthority($userId);
        $source = $authority ? (Tenant::isOwner($userId) ? 'Company Owner' : 'Super Admin') : 'Administrator role';
        foreach (self::allPageDefs() as $key => $def) {
            $out[$key] = ['allowed' => $admin, 'create' => $admin, 'edit' => $admin, 'delete' => $admin,
                'print' => $admin, 'approve' => $admin, 'source' => $admin ? $source : '', 'override' => false];
        }
        if ($authority) {
            // no role or user override can lock the Company Owner (or the Super Admin) out of their own company
            return self::$cache["eff:$userId"] = $out;
        }
        if (!$admin) {
            $rows = DB::all(
                'SELECT p.page_key, r.name AS role_name, rpa.*
                   FROM role_page_access rpa
                   JOIN user_roles ur ON ur.role_id = rpa.role_id AND ur.user_id = :u
                   JOIN roles r ON r.id = rpa.role_id
                   JOIN pages p ON p.id = rpa.page_id
                  WHERE rpa.allowed = 1',
                ['u' => $userId]
            );
            foreach ($rows as $r) {
                $k = $r['page_key'];
                if (!isset($out[$k])) {
                    continue;
                }
                $out[$k]['allowed'] = true;
                foreach (array_keys(self::ACTIONS) as $a) {
                    $out[$k][$a] = $out[$k][$a] || (int)$r['can_' . $a] === 1;
                }
                $out[$k]['source'] = trim($out[$k]['source'] . ', ' . $r['role_name'], ', ');
            }
        }
        // user overrides take precedence (also over the Administrator role)
        $ov = DB::all(
            'SELECT p.page_key, o.* FROM user_page_overrides o JOIN pages p ON p.id = o.page_id WHERE o.user_id = :u',
            ['u' => $userId]
        );
        foreach ($ov as $o) {
            $k = $o['page_key'];
            if (!isset($out[$k])) {
                continue;
            }
            $allowed = (int)$o['allowed'] === 1;
            $out[$k] = ['allowed' => $allowed, 'override' => true,
                'source' => 'User override (' . ($allowed ? 'allowed' : 'denied') . ')'];
            foreach (array_keys(self::ACTIONS) as $a) {
                $out[$k][$a] = $allowed && (int)$o['can_' . $a] === 1;
            }
        }
        // pages that are not in the database yet are only visible to administrators
        foreach ($out as $k => $v) {
            if (!isset($pages[$k]) && !$admin) {
                $out[$k]['allowed'] = false;
            }
        }
        // admin setup pages can never be locked away from every administrator
        return self::$cache["eff:$userId"] = $out;
    }

    public static function page(string $key, ?int $userId = null): bool
    {
        return (bool)(self::effective($userId)[$key]['allowed'] ?? false);
    }

    public static function can(string $key, string $action, ?int $userId = null): bool
    {
        if ($action === 'view') {
            return self::page($key, $userId);
        }
        $e = self::effective($userId)[$key] ?? null;
        return $e && $e['allowed'] && !empty($e[$action]);
    }

    /** Stops the request with an access-denied page, and records the attempt. */
    public static function denyPage(string $key, string $action = 'open'): never
    {
        $def = self::pageDef($key);
        Audit::log('access_denied', 'security', 'page', null, ($def['name'] ?? $key) . " ($action)", ['page_key' => $key]);
        http_response_code(403);
        $GLOBALS['DENIED_PAGE'] = $def['name'] ?? $key;
        $GLOBALS['DENIED_ACTION'] = $action;
        require APP_ROOT . '/modules/auth/denied.php';
        exit;
    }

    public static function requirePage(string $key): void
    {
        if (!self::page($key)) {
            self::denyPage($key);
        }
    }

    public static function require(string $key, string $action): void
    {
        if (!self::can($key, $action)) {
            self::denyPage($key, $action);
        }
    }

    // --------------------------------------------------------------- special permissions

    public static function special(string $perm, ?int $userId = null)
    {
        $userId ??= Auth::id();
        $type = self::SPECIAL[$perm][1] ?? 'bool';
        if (self::isAdmin($userId)) {
            return $type === 'bool' ? true : null; // null = unlimited
        }
        $vals = DB::column(
            'SELECT rsp.perm_value FROM role_special_permissions rsp
               JOIN user_roles ur ON ur.role_id = rsp.role_id AND ur.user_id = :u
              WHERE rsp.perm_key = :k',
            ['u' => $userId, 'k' => $perm]
        );
        if ($type === 'bool') {
            foreach ($vals as $v) {
                if ($v === '1') {
                    return true;
                }
            }
            return false;
        }
        $max = 0.0;
        foreach ($vals as $v) {
            if ($v === '' || $v === null) {
                return null; // unlimited
            }
            $max = max($max, (float)$v);
        }
        return $vals ? $max : 0.0;
    }

    public static function viewCosts(): bool
    {
        return (bool)self::special('view_costs');
    }

    /** Returns null when unlimited, otherwise the limit. */
    public static function limit(string $perm): ?float
    {
        $v = self::special($perm);
        return $v === null ? null : (float)$v;
    }

    // --------------------------------------------------------------- sites

    /** Allowed site ids, or null for all sites. */
    public static function siteIds(?int $userId = null): ?array
    {
        $userId ??= Auth::id();
        if (!$userId || self::isAdmin($userId)) {
            return null;
        }
        return self::$cache["sites:$userId"] ??= (function () use ($userId) {
            $userSites = array_map('intval', DB::column('SELECT site_id FROM user_sites WHERE user_id = :u', ['u' => $userId]));
            $roles = self::roles($userId);
            $allRoles = false;
            $roleSites = [];
            foreach ($roles as $r) {
                if ((int)$r['all_sites'] === 1) {
                    $allRoles = true;
                } else {
                    $roleSites = array_merge($roleSites, array_map('intval', DB::column('SELECT site_id FROM role_sites WHERE role_id = :r', ['r' => $r['id']])));
                }
            }
            $set = null;
            if ($userSites) {
                $set = $userSites;
            }
            if (!$allRoles && $roles) {
                $set = $set === null ? array_values(array_unique($roleSites)) : array_values(array_intersect($set, $roleSites));
            }
            return $set;
        })();
    }

    public static function siteAllowed(?int $siteId): bool
    {
        if ($siteId === null) {
            return true;
        }
        $ids = self::siteIds();
        return $ids === null || in_array($siteId, $ids, true);
    }

    /** SQL fragment restricting a site column, e.g. " AND t.site_id IN (...)" */
    public static function siteFilter(string $column, string $prefix = 'st'): array
    {
        $ids = self::siteIds();
        if ($ids === null) {
            return ['', []];
        }
        [$in, $params] = DB::in($prefix, $ids ?: [0]);
        return [" AND ($column IS NULL OR $column IN $in)", $params];
    }

    // --------------------------------------------------------------- segregation of duties

    /** Returns descriptions of SoD conflicts for a set of allowed page keys with create/edit rights. */
    public static function sodConflicts(array $pageRights): array
    {
        $out = [];
        foreach (self::SOD_RULES as [$a, $b, $desc]) {
            $ha = !empty($pageRights[$a]['allowed']) && (!empty($pageRights[$a]['create']) || !empty($pageRights[$a]['edit']));
            $hb = !empty($pageRights[$b]['allowed']) && (!empty($pageRights[$b]['create']) || !empty($pageRights[$b]['edit']));
            if ($ha && $hb) {
                $out[] = $desc;
            }
        }
        return $out;
    }
}
