<?php
declare(strict_types=1);

/**
 * Projects: the register, who may see which project, and the link between a project and its accounting class.
 *
 * Every project owns exactly one class (cs_projects.class_id). Whatever is recorded in accounting with that
 * class (a bill, a check, a journal entry, an invoice) belongs to the project, so the project's figures always
 * come from the general ledger itself and reconcile with the company's books.
 */
final class CsProject
{
    public const STATUSES = [
        'planning' => 'Planning', 'mobilization' => 'Mobilization', 'active' => 'Active', 'on_hold' => 'On hold',
        'substantially_completed' => 'Substantially completed', 'completed' => 'Completed', 'under_warranty' => 'Under warranty', 'closed' => 'Closed',
    ];
    /** statuses in which work and costs are still expected */
    public const OPEN = ['planning', 'mobilization', 'active', 'on_hold', 'substantially_completed'];

    private static array $cache = [];
    private static ?array $ids = null;
    private static ?int $idsUser = null;

    public static function flush(): void
    {
        self::$cache = [];
        self::$ids = null;
    }

    public static function get(int $id): ?array
    {
        if (!array_key_exists($id, self::$cache)) {
            self::$cache[$id] = DB::row(
                'SELECT p.*, c.name AS customer_name, k.contract_no, k.title AS contract_title, u.full_name AS pm_name, cl.name AS class_name
                   FROM cs_projects p
                   LEFT JOIN customers c ON c.id = p.customer_id
                   LEFT JOIN cs_contracts k ON k.id = p.contract_id
                   LEFT JOIN users u ON u.id = p.pm_user_id
                   LEFT JOIN classes cl ON cl.id = p.class_id
                  WHERE p.id = :id',
                ['id' => $id]
            );
        }
        return self::$cache[$id];
    }

    public static function byClass(?int $classId): ?array
    {
        if (!$classId) {
            return null;
        }
        $id = DB::value('SELECT id FROM cs_projects WHERE class_id = :c', ['c' => $classId]);
        return $id ? self::get((int)$id) : null;
    }

    public static function classId(int $id): int
    {
        return (int)(self::get($id)['class_id'] ?? 0);
    }

    // ------------------------------------------------------------------ who sees which project

    /** Project ids this user may see, or null for every project */
    public static function accessibleIds(?int $userId = null): ?array
    {
        $userId ??= Auth::id();
        if (!$userId) {
            return [];
        }
        $mine = $userId === Auth::id();
        if ($mine && self::$ids !== null && self::$idsUser === $userId) {
            return self::$ids === ['*'] ? null : self::$ids;
        }
        $u = DB::row('SELECT all_projects, portal_customer_id, portal_vendor_id FROM users WHERE id = :id', ['id' => $userId]) ?: [];
        $portal = !empty($u['portal_customer_id']) || !empty($u['portal_vendor_id']);
        if (!$portal && (Access::isAdmin($userId) || (int)($u['all_projects'] ?? 0) === 1 || Settings::get('cs_project_access', 'members') === 'all')) {
            $ids = null;
        } else {
            $ids = array_map('intval', DB::column(
                'SELECT id FROM cs_projects WHERE pm_user_id = :u OR engineer_user_id = :u2 OR qs_user_id = :u3
                 UNION SELECT project_id FROM cs_project_members WHERE user_id = :u4',
                ['u' => $userId, 'u2' => $userId, 'u3' => $userId, 'u4' => $userId]
            ));
            if (!empty($u['portal_customer_id'])) {
                $ids = array_merge($ids, array_map('intval', DB::column('SELECT id FROM cs_projects WHERE customer_id = :c', ['c' => $u['portal_customer_id']])));
            }
            if (!empty($u['portal_vendor_id'])) {
                $ids = array_merge($ids, array_map('intval', DB::column('SELECT DISTINCT project_id FROM cs_subcontracts WHERE vendor_id = :v', ['v' => $u['portal_vendor_id']])));
            }
            $ids = array_values(array_unique($ids));
        }
        if ($mine) {
            self::$ids = $ids === null ? ['*'] : $ids;
            self::$idsUser = $userId;
        }
        return $ids;
    }

    public static function canSee(int $id): bool
    {
        $ids = self::accessibleIds();
        return $ids === null || in_array($id, $ids, true);
    }

    /** Stops with "not found" when the project does not exist or the user may not see it */
    public static function requireAccess(int $id): array
    {
        $p = self::get($id);
        if (!$p || !self::canSee($id)) {
            http_response_code(404);
            layout_start('Project not found');
            echo CsUi::empty('This project does not exist, or it is not one of your projects.', btn_link(url('proj.center'), 'Go to the Project Center', 'btn-primary'), 'buildings');
            layout_end();
            exit;
        }
        return $p;
    }

    /** SQL restricting a project id column to the user's projects: [" AND col IN (...)", params] */
    public static function filter(string $column, string $tag = 'pacc'): array
    {
        $ids = self::accessibleIds();
        if ($ids === null) {
            return ['', []];
        }
        [$in, $p] = DB::in($tag, $ids ?: [0]);
        return [" AND $column IN $in", $p];
    }

    /** id => "CODE Name" of the projects the user may see (open ones, or every one) */
    public static function pickList(bool $withClosed = false): array
    {
        [$f, $p] = self::filter('p.id');
        $rows = DB::all("SELECT p.id, p.code, p.name, p.status FROM cs_projects p WHERE 1 = 1 $f"
            . ($withClosed ? '' : " AND p.status <> 'closed'") . ' ORDER BY p.code', $p);
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['id']] = $r['code'] . ' ' . $r['name'];
        }
        return $out;
    }

    /** The project chosen on a list page (?project=), remembered for the session */
    public static function current(): ?int
    {
        if (isset($_GET['project'])) {
            $v = (int)$_GET['project'];
            if ($v > 0 && self::canSee($v)) {
                $_SESSION['cs_project'] = $v;
                return $v;
            }
            unset($_SESSION['cs_project']);
            return null;
        }
        $v = (int)($_SESSION['cs_project'] ?? 0);
        return $v > 0 && self::canSee($v) && self::get($v) ? $v : null;
    }

    // ------------------------------------------------------------------ contract value

    /** original (the project's share of its contract), approved variations, current approved value, pending variations */
    public static function contractValue(int $id): array
    {
        $p = self::get($id);
        $orig = (float)($p['contract_value'] ?? 0);
        $approved = (float)DB::value("SELECT COALESCE(SUM(price_impact),0) FROM cs_change_orders WHERE project_id = :p AND status = 'client_approved'", ['p' => $id]);
        $pending = (float)DB::value("SELECT COALESCE(SUM(price_impact),0) FROM cs_change_orders WHERE project_id = :p AND status IN ('proposed','for_approval','internally_approved','disputed')", ['p' => $id]);
        return ['original' => r2($orig), 'variations' => r2($approved), 'current' => r2($orig + $approved), 'pending' => r2($pending)];
    }

    // ------------------------------------------------------------------ set-up

    /**
     * Creates a project, its accounting class and (optionally) its stockyard. $d: code (blank = next number), name,
     * customer_id, contract_id, location, pm_user_id, ... ; $stockyard = also make an inventory site for the project.
     */
    public static function create(array $d, bool $stockyard = true): int
    {
        return DB::tx(function () use ($d, $stockyard) {
            $code = trim((string)($d['code'] ?? '')) ?: Seq::next('cs_project');
            if (DB::value('SELECT id FROM cs_projects WHERE code = :c', ['c' => $code])) {
                fail("Project code $code is already used.");
            }
            $name = trim((string)($d['name'] ?? ''));
            if ($name === '') {
                fail('Enter the project name.');
            }
            $className = mb_substr($code . ' ' . $name, 0, 80);
            if (DB::value('SELECT id FROM classes WHERE name = :n', ['n' => $className])) {
                $className = mb_substr($code . ' ' . $name, 0, 74) . ' (' . substr(uniqid(), -4) . ')';
            }
            $classId = DB::insert('classes', ['name' => $className, 'description' => 'Project ' . $code . ' (created with the project)',
                'sort_order' => 100 + (int)DB::value('SELECT COUNT(*) FROM cs_projects'), 'created_by' => Auth::id()]);
            Classes::reset();
            $siteId = null;
            if ($stockyard) {
                $siteCode = substr(preg_replace('/[^A-Z0-9-]/', '', strtoupper($code)), 0, 18) ?: 'PRJ' . $classId;
                if (DB::value('SELECT id FROM sites WHERE code = :c', ['c' => $siteCode])) {
                    $siteCode = substr($siteCode, 0, 14) . '-' . $classId;
                }
                $siteId = DB::insert('sites', ['code' => $siteCode, 'name' => mb_substr('Stockyard ' . $name, 0, 80), 'site_type' => 'project',
                    'address' => mb_substr((string)($d['location'] ?? ''), 0, 255) ?: null]);
            }
            $row = self::clean($d) + ['code' => $code, 'class_id' => $classId, 'site_id' => $siteId, 'created_by' => Auth::id()];
            $id = DB::insert('cs_projects', $row);
            foreach (array_filter([(int)($row['pm_user_id'] ?? 0) => 'Project Manager', (int)($row['engineer_user_id'] ?? 0) => 'Site Engineer', (int)($row['qs_user_id'] ?? 0) => 'Quantity Surveyor']) as $uid => $lbl) {
                if ($uid) {
                    DB::run('INSERT IGNORE INTO cs_project_members (project_id, user_id, role_label) VALUES (:p, :u, :r)', ['p' => $id, 'u' => $uid, 'r' => $lbl]);
                }
            }
            // the standard close-out checklist is ready from the first day
            $tpl = (string)DB::value("SELECT items FROM cs_checklist_templates WHERE kind = 'closeout' AND is_active = 1 ORDER BY id LIMIT 1");
            $n = 0;
            foreach (preg_split('/\R/', $tpl) as $item) {
                if (trim($item) !== '') {
                    DB::insert('cs_closeout_items', ['project_id' => $id, 'line_no' => ++$n, 'item' => mb_substr(trim($item), 0, 200)]);
                }
            }
            self::flush();
            Audit::log('create', 'proj', 'project', $id, $code . ' ' . $name, ['page_key' => 'proj.projects', 'amount' => $row['contract_value'] ?? 0,
                'reason' => 'Accounting class "' . $className . '" created for the project' . ($siteId ? ', with its stockyard' : '')]);
            return $id;
        });
    }

    public static function update(int $id, array $d): void
    {
        DB::tx(function () use ($id, $d) {
            $old = DB::row('SELECT * FROM cs_projects WHERE id = :id', ['id' => $id]);
            if (!$old) {
                fail('Project not found.');
            }
            $row = self::clean($d);
            $row['updated_at'] = date('Y-m-d H:i:s');
            DB::update('cs_projects', $row, 'id = :id', ['id' => $id]);
            if (isset($row['name']) && $row['name'] !== $old['name']) {
                $cn = mb_substr($old['code'] . ' ' . $row['name'], 0, 80);
                if (!DB::value('SELECT id FROM classes WHERE name = :n AND id <> :c', ['n' => $cn, 'c' => $old['class_id']])) {
                    DB::update('classes', ['name' => $cn], 'id = :id', ['id' => $old['class_id']]);
                    Classes::reset();
                }
            }
            foreach (['pm_user_id' => 'Project Manager', 'engineer_user_id' => 'Site Engineer', 'qs_user_id' => 'Quantity Surveyor'] as $col => $lbl) {
                if (!empty($row[$col])) {
                    DB::run('INSERT IGNORE INTO cs_project_members (project_id, user_id, role_label) VALUES (:p, :u, :r)', ['p' => $id, 'u' => $row[$col], 'r' => $lbl]);
                }
            }
            self::flush();
            $new = DB::row('SELECT * FROM cs_projects WHERE id = :id', ['id' => $id]);
            Audit::log('edit', 'proj', 'project', $id, $old['code'] . ' ' . $old['name'], ['page_key' => 'proj.projects', 'before' => $old, 'after' => $new]);
        });
    }

    private static function clean(array $d): array
    {
        $cols = ['name', 'description', 'location', 'contract_id', 'customer_id', 'pm_user_id', 'engineer_user_id', 'qs_user_id', 'division', 'status',
            'contract_value', 'start_date', 'target_end_date', 'revised_end_date', 'actual_start_date', 'actual_end_date', 'completion_pct', 'progress_source',
            'floor_area', 'warranty_end_date', 'notes'];
        $out = [];
        foreach ($cols as $c) {
            if (!array_key_exists($c, $d)) {
                continue;
            }
            $v = $d[$c];
            if (in_array($c, ['contract_id', 'customer_id', 'pm_user_id', 'engineer_user_id', 'qs_user_id'], true)) {
                $v = $v ? (int)$v : null;
            } elseif (in_array($c, ['start_date', 'target_end_date', 'revised_end_date', 'actual_start_date', 'actual_end_date', 'warranty_end_date'], true)) {
                $v = valid_date((string)$v) ? $v : null;
            } elseif (in_array($c, ['contract_value', 'completion_pct', 'floor_area'], true)) {
                $v = r2(to_num($v));
            } else {
                $v = is_string($v) ? trim($v) : $v;
                $v = $v === '' && !in_array($c, ['name', 'status', 'progress_source'], true) ? null : $v;
            }
            $out[$c] = $v;
        }
        if (isset($out['status']) && !isset(self::STATUSES[$out['status']])) {
            $out['status'] = 'planning';
        }
        if (isset($out['completion_pct'])) {
            $out['completion_pct'] = max(0, min(100, (float)$out['completion_pct']));
        }
        return $out;
    }

    /** Changes the status, with the checks that keep a project from being closed with loose ends */
    public static function setStatus(int $id, string $status, string $reason = ''): void
    {
        $p = self::get($id);
        if (!$p || !isset(self::STATUSES[$status])) {
            fail('Unknown project or status.');
        }
        if ($status === 'closed') {
            CsCloseout::assertClosable($id, $reason);
        }
        $row = ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')];
        if ($status === 'active' && !$p['actual_start_date']) {
            $row['actual_start_date'] = today();
        }
        if (in_array($status, ['completed', 'substantially_completed'], true) && !$p['actual_end_date']) {
            $row['actual_end_date'] = today();
        }
        if ($status === 'closed') {
            $row['closed_by'] = Auth::id();
            $row['closed_at'] = date('Y-m-d H:i:s');
            if ($reason !== '') {
                $row['closure_override'] = mb_substr($reason, 0, 255);
            }
        }
        DB::update('cs_projects', $row, 'id = :id', ['id' => $id]);
        self::flush();
        Audit::log('status', 'proj', 'project', $id, $p['code'] . ' ' . $p['name'], ['page_key' => 'proj.view', 'reason' => $reason ?: null,
            'changes' => [['field' => 'status', 'old' => $p['status'], 'new' => $status]]]);
    }

    /** Physical progress: the weighted progress of the schedule, or the figure kept on the project */
    public static function physicalPct(int $id): float
    {
        $p = self::get($id);
        if (($p['progress_source'] ?? 'schedule') === 'schedule') {
            $row = DB::row('SELECT SUM(weight_pct) AS w, SUM(weight_pct * progress_pct) AS wp FROM cs_activities WHERE project_id = :p AND is_milestone = 0', ['p' => $id]);
            if ($row && (float)$row['w'] > 0) {
                return round((float)$row['wp'] / (float)$row['w'], 2);
            }
        }
        return (float)($p['completion_pct'] ?? 0);
    }

    /** Where the schedule's baseline says progress should be today (weighted, straight-line within each activity) */
    public static function plannedPct(int $id, ?string $asOf = null): ?float
    {
        $asOf ??= today();
        $acts = DB::all('SELECT weight_pct, COALESCE(baseline_start, planned_start) AS s, COALESCE(baseline_finish, planned_finish) AS f
                           FROM cs_activities WHERE project_id = :p AND is_milestone = 0', ['p' => $id]);
        $w = 0.0;
        $sum = 0.0;
        $t = strtotime($asOf);
        foreach ($acts as $a) {
            if (!$a['s'] || !$a['f']) {
                continue;
            }
            $w += (float)$a['weight_pct'];
            $s = strtotime($a['s']);
            $f = strtotime($a['f']) + 86400;
            $frac = $t <= $s ? 0 : ($t >= $f ? 1 : ($t - $s) / max(1, $f - $s));
            $sum += (float)$a['weight_pct'] * $frac;
        }
        return $w > 0 ? round($sum / $w * 100, 2) : null;
    }
}
