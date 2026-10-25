<?php
declare(strict_types=1);

/**
 * Project costs and the figures behind every project report, all read from the general ledger by the project's
 * class: cost by type (from the expense account's construction cost category) and by cost code (on the ledger line),
 * revenue, billings, retention; plus the budget versions, commitments (open purchase orders and subcontracts),
 * cost to complete and estimate at completion.
 */
final class CsCost
{
    public const TYPES = ['material' => 'Materials', 'labor' => 'Labor', 'equipment' => 'Equipment', 'subcontract' => 'Subcontracts', 'other' => 'Other direct costs', 'overhead' => 'Allocated overhead'];
    public const DIRECT = ['material', 'labor', 'equipment', 'subcontract', 'other'];

    private static ?array $codes = null;
    private static ?int $lossAcct = null;

    public static function flush(): void
    {
        self::$codes = null;
        self::$lossAcct = null;
    }

    // ------------------------------------------------------------------ cost codes

    public static function codes(): array
    {
        return self::$codes ??= DB::all('SELECT c.*, (SELECT COUNT(*) FROM cs_cost_codes k WHERE k.parent_id = c.id) AS children FROM cs_cost_codes c ORDER BY c.sort_order, c.code');
    }

    /** id => "03-200 Reinforcing Steel" for the drop-downs (headings of a group are left out: costs go to the work items) */
    public static function codeOptions(bool $withHeads = false): array
    {
        $out = [];
        foreach (self::codes() as $c) {
            if ((int)$c['is_active'] !== 1 || (!$withHeads && (int)$c['children'] > 0)) {
                continue;
            }
            $out[(int)$c['id']] = $c['code'] . ' ' . $c['name'];
        }
        return $out;
    }

    public static function code(?int $id): ?array
    {
        if (!$id) {
            return null;
        }
        foreach (self::codes() as $c) {
            if ((int)$c['id'] === $id) {
                return $c;
            }
        }
        return null;
    }

    public static function codeLabel(?int $id): string
    {
        $c = self::code($id);
        return $c ? $c['code'] . ' ' . $c['name'] : '';
    }

    /** The cost type of a ledger account (material, labor, ...), or null when it is not a cost */
    public static function typeSql(string $a = 'a'): string
    {
        return "CASE WHEN $a.category <> 'Expense' THEN NULL WHEN $a.cs_cost_category IS NOT NULL AND $a.cs_cost_category <> '' THEN $a.cs_cost_category
                     WHEN $a.account_type = 'Cost of Goods Sold' THEN 'other' ELSE 'overhead' END";
    }

    // ------------------------------------------------------------------ ledger figures by project

    /** [classId => projectId] for a set of projects (null = every project) */
    public static function classes(?array $projectIds = null): array
    {
        if ($projectIds === null) {
            return array_map('intval', DB::pairs('SELECT class_id, id FROM cs_projects'));
        }
        if (!$projectIds) {
            return [];
        }
        [$in, $p] = DB::in('pcl', $projectIds);
        return array_map('intval', DB::pairs("SELECT class_id, id FROM cs_projects WHERE id IN $in", $p));
    }

    /**
     * Costs by project and type: [projectId => [type => amount, 'total' => direct total, 'overhead' => ...]].
     * Dates: up to $to (and from $from when given).
     */
    public static function costByType(?array $projectIds = null, ?string $to = null, ?string $from = null): array
    {
        $cls = self::classes($projectIds);
        if (!$cls) {
            return [];
        }
        [$in, $p] = DB::in('cbt', array_keys($cls));
        $w = '';
        if ($to) {
            $w .= ' AND j.txn_date <= :to';
            $p['to'] = $to;
        }
        if ($from) {
            $w .= ' AND j.txn_date >= :from';
            $p['from'] = $from;
        }
        $p['lpx'] = self::lossAccount();
        $rows = DB::all('SELECT j.class_id, ' . self::typeSql() . " AS t, SUM(j.debit - j.credit) AS amt
                           FROM journal_lines j JOIN accounts a ON a.id = j.account_id
                          WHERE j.class_id IN $in AND a.category = 'Expense' AND a.id <> :lpx $w GROUP BY j.class_id, t", $p);
        $out = [];
        foreach ($cls as $cid => $pid) {
            $out[$pid] = array_fill_keys(array_keys(self::TYPES), 0.0) + ['direct' => 0.0, 'total' => 0.0];
        }
        foreach ($rows as $r) {
            $pid = $cls[(int)$r['class_id']];
            $t = (string)$r['t'];
            if (!isset(self::TYPES[$t])) {
                $t = 'overhead';
            }
            $out[$pid][$t] += (float)$r['amt'];
            if (in_array($t, self::DIRECT, true)) {
                $out[$pid]['direct'] += (float)$r['amt'];
            }
            $out[$pid]['total'] += (float)$r['amt'];
        }
        foreach ($out as &$o) {
            $o = array_map(fn($v) => r2($v), $o);
        }
        return $out;
    }

    /** Cost by cost code and type for one project: [costCodeId|0 => [type => amount]] */
    public static function costByCode(int $projectId, ?string $to = null, ?string $from = null): array
    {
        $p = ['c' => CsProject::classId($projectId), 'lpx' => self::lossAccount()];
        $w = '';
        if ($to) {
            $w .= ' AND j.txn_date <= :to';
            $p['to'] = $to;
        }
        if ($from) {
            $w .= ' AND j.txn_date >= :from';
            $p['from'] = $from;
        }
        $out = [];
        foreach (DB::all('SELECT COALESCE(j.cost_code_id, 0) AS cc, ' . self::typeSql() . " AS t, SUM(j.debit - j.credit) AS amt
                            FROM journal_lines j JOIN accounts a ON a.id = j.account_id
                           WHERE j.class_id = :c AND a.category = 'Expense' AND a.id <> :lpx $w GROUP BY cc, t", $p) as $r) {
            $t = isset(self::TYPES[(string)$r['t']]) ? (string)$r['t'] : 'overhead';
            $out[(int)$r['cc']][$t] = r2(($out[(int)$r['cc']][$t] ?? 0) + (float)$r['amt']);
        }
        return $out;
    }

    /** The provision for contract losses is kept out of the cost of the work (it is an estimate, not a cost incurred) */
    public static function lossAccount(): int
    {
        return self::$lossAcct ??= (int)(DB::value("SELECT account_id FROM default_accounts WHERE setting_key = 'cs_loss_provision'") ?: 0);
    }

    /** The loss provided for so far, by project */
    public static function lossProvided(?array $projectIds = null, ?string $to = null, ?string $from = null): array
    {
        return self::sumByAccounts($projectIds, 'a.id = ' . self::lossAccount(), 1, $to, $from);
    }

    /** Revenue recognized (income accounts) by project */
    public static function revenue(?array $projectIds = null, ?string $to = null, ?string $from = null): array
    {
        return self::sumByAccounts($projectIds, "a.category = 'Income'", -1, $to, $from);
    }

    /** Contract revenue only (the cs_contract_revenue account) */
    public static function contractRevenue(?array $projectIds = null, ?string $to = null): array
    {
        return self::sumByAccounts($projectIds, 'a.id = ' . (int)Accts::get('cs_contract_revenue'), -1, $to);
    }

    /** Balance of one default account by project (debit positive unless $sign = -1) */
    public static function balance(string $acctKey, ?array $projectIds = null, ?string $to = null, int $sign = 1): array
    {
        return self::sumByAccounts($projectIds, 'a.id = ' . (int)Accts::get($acctKey), $sign, $to);
    }

    private static function sumByAccounts(?array $projectIds, string $cond, int $sign, ?string $to = null, ?string $from = null): array
    {
        $cls = self::classes($projectIds);
        if (!$cls) {
            return [];
        }
        [$in, $p] = DB::in('sba', array_keys($cls));
        $w = '';
        if ($to) {
            $w .= ' AND j.txn_date <= :to';
            $p['to'] = $to;
        }
        if ($from) {
            $w .= ' AND j.txn_date >= :from';
            $p['from'] = $from;
        }
        $out = array_fill_keys(array_values($cls), 0.0);
        foreach (DB::all("SELECT j.class_id, SUM(j.debit - j.credit) AS amt FROM journal_lines j JOIN accounts a ON a.id = j.account_id
                           WHERE j.class_id IN $in AND $cond $w GROUP BY j.class_id", $p) as $r) {
            $out[$cls[(int)$r['class_id']]] = r2($sign * (float)$r['amt']);
        }
        return $out;
    }

    // ------------------------------------------------------------------ budgets

    /** The approved budget currently in force (latest approved version) and the original baseline */
    public static function budgets(int $projectId): array
    {
        $current = DB::row("SELECT * FROM cs_budgets WHERE project_id = :p AND status = 'approved' ORDER BY version_no DESC, id DESC LIMIT 1", ['p' => $projectId]);
        $base = DB::row("SELECT * FROM cs_budgets WHERE project_id = :p AND is_baseline = 1 AND status IN ('approved','superseded') ORDER BY id LIMIT 1", ['p' => $projectId]);
        return ['current' => $current, 'baseline' => $base];
    }

    /** Budget lines summed by cost code and type: [ccId => [type => amount]] */
    public static function budgetByCode(?int $budgetId): array
    {
        if (!$budgetId) {
            return [];
        }
        $out = [];
        foreach (DB::all('SELECT cost_code_id, category, SUM(amount) AS amt FROM cs_budget_lines WHERE budget_id = :b GROUP BY cost_code_id, category', ['b' => $budgetId]) as $r) {
            $out[(int)$r['cost_code_id']][(string)$r['category']] = r2($r['amt']);
        }
        return $out;
    }

    public static function budgetTotals(array $projectIds): array
    {
        $out = [];
        foreach ($projectIds as $pid) {
            $b = self::budgets((int)$pid);
            $out[(int)$pid] = ['original' => r2($b['baseline']['total_amount'] ?? 0), 'current' => r2($b['current']['total_amount'] ?? 0)];
        }
        return $out;
    }

    // ------------------------------------------------------------------ commitments

    /**
     * What is committed and not yet incurred: the open part of purchase orders charged to the project, and the part
     * of subcontracts not yet billed. A purchase order is never a cost by itself; only the receipt or bill is.
     * Returns [projectId => ['po' => amount, 'subcontract' => amount, 'total' => amount]].
     */
    public static function commitments(?array $projectIds = null): array
    {
        $cls = self::classes($projectIds);
        $out = [];
        foreach ($cls as $pid) {
            $out[$pid] = ['po' => 0.0, 'subcontract' => 0.0, 'total' => 0.0];
        }
        if (!$cls) {
            return $out;
        }
        [$in, $p] = DB::in('cmt', array_keys($cls));
        foreach (DB::all("SELECT COALESCE(l.class_id, t.class_id) AS cid,
                                 SUM(CASE WHEN l.is_closed = 1 THEN 0
                                          WHEN l.qty > 0 THEN GREATEST(0, l.qty - l.qty_fulfilled) * (l.amount / l.qty)
                                          ELSE GREATEST(0, l.amount - l.amount_fulfilled) END) AS open_amt
                            FROM transaction_lines l JOIN transactions t ON t.id = l.txn_id
                           WHERE t.txn_type = 'purchase_order' AND t.is_voided = 0 AND t.status NOT IN ('closed','void')
                             AND COALESCE(l.class_id, t.class_id) IN $in
                           GROUP BY cid", $p) as $r) {
            $pid = $cls[(int)$r['cid']] ?? null;
            if ($pid) {
                $out[$pid]['po'] = r2($r['open_amt']);
            }
        }
        [$in2, $p2] = DB::in('cms', array_values($cls));
        foreach (DB::all("SELECT s.project_id, SUM(GREATEST(0, s.contract_amount + s.approved_variations
                                 - COALESCE((SELECT SUM(b.gross_amount) FROM cs_sub_billings b WHERE b.subcontract_id = s.id AND b.status = 'posted' AND b.kind <> 'retention_release'), 0))) AS open_amt
                            FROM cs_subcontracts s WHERE s.status IN ('active') AND s.project_id IN $in2 GROUP BY s.project_id", $p2) as $r) {
            $out[(int)$r['project_id']]['subcontract'] = r2($r['open_amt']);
        }
        foreach ($out as &$o) {
            $o['total'] = r2($o['po'] + $o['subcontract']);
        }
        return $out;
    }

    // ------------------------------------------------------------------ forecast

    /**
     * Estimate at completion of one project, by cost code: for each code the larger of its current budget and what is
     * already spent plus committed; codes with costs but no budget count at spent plus committed.
     * Returns rows [cc => [budget, original, actual, committed, etc, eac, variance]] and totals.
     */
    public static function forecast(int $projectId, ?string $asOf = null): array
    {
        $b = self::budgets($projectId);
        $bud = self::budgetByCode($b['current']['id'] ?? null);
        $orig = self::budgetByCode($b['baseline']['id'] ?? null);
        $act = self::costByCode($projectId, $asOf);
        $comm = self::commitmentsByCode($projectId);
        $ids = array_unique(array_merge(array_keys($bud), array_keys($act), array_keys($comm), array_keys($orig)));
        // a finished project costs what it cost: budget left unspent is not part of its estimate at completion
        $done = in_array(CsProject::get($projectId)['status'] ?? '', ['completed', 'under_warranty', 'closed'], true);
        $rows = [];
        $tot = ['original' => 0.0, 'budget' => 0.0, 'actual' => 0.0, 'committed' => 0.0, 'etc' => 0.0, 'eac' => 0.0, 'variance' => 0.0, 'overhead' => 0.0];
        foreach ($ids as $cc) {
            $budget = array_sum(array_intersect_key($bud[$cc] ?? [], array_flip(self::DIRECT)));
            $original = array_sum(array_intersect_key($orig[$cc] ?? [], array_flip(self::DIRECT)));
            $actual = array_sum(array_intersect_key($act[$cc] ?? [], array_flip(self::DIRECT)));
            $overhead = (float)($act[$cc]['overhead'] ?? 0);
            $committed = (float)($comm[$cc] ?? 0);
            $eac = $done ? $actual + $committed : max($budget, $actual + $committed);
            $etc = max(0, $eac - $actual);
            $rows[$cc] = ['cost_code_id' => $cc, 'original' => r2($original), 'budget' => r2($budget), 'actual' => r2($actual), 'committed' => r2($committed),
                'remaining_commit' => r2($committed), 'etc' => r2($etc), 'eac' => r2($eac), 'variance' => r2($budget - $eac), 'overhead' => r2($overhead)];
            foreach ($tot as $k => $_) {
                $tot[$k] += $rows[$cc][$k] ?? 0;
            }
        }
        uksort($rows, fn($a, $b) => strcmp(self::codeLabel($a) ?: 'zzz', self::codeLabel($b) ?: 'zzz'));
        return ['rows' => $rows, 'totals' => array_map('r2', $tot)];
    }

    /** Open purchase orders and unbilled subcontracts of a project by cost code */
    public static function commitmentsByCode(int $projectId): array
    {
        $cid = CsProject::classId($projectId);
        $out = [];
        foreach (DB::all("SELECT COALESCE(l.cost_code_id, 0) AS cc,
                                 SUM(CASE WHEN l.is_closed = 1 THEN 0 WHEN l.qty > 0 THEN GREATEST(0, l.qty - l.qty_fulfilled) * (l.amount / l.qty)
                                          ELSE GREATEST(0, l.amount - l.amount_fulfilled) END) AS amt
                            FROM transaction_lines l JOIN transactions t ON t.id = l.txn_id
                           WHERE t.txn_type = 'purchase_order' AND t.is_voided = 0 AND t.status NOT IN ('closed','void') AND COALESCE(l.class_id, t.class_id) = :c
                           GROUP BY cc", ['c' => $cid]) as $r) {
            $out[(int)$r['cc']] = ($out[(int)$r['cc']] ?? 0) + (float)$r['amt'];
        }
        foreach (DB::all("SELECT COALESCE(s.cost_code_id, 0) AS cc, GREATEST(0, s.contract_amount + s.approved_variations
                                 - COALESCE((SELECT SUM(b.gross_amount) FROM cs_sub_billings b WHERE b.subcontract_id = s.id AND b.status = 'posted' AND b.kind <> 'retention_release'), 0)) AS amt
                            FROM cs_subcontracts s WHERE s.status = 'active' AND s.project_id = :p", ['p' => $projectId]) as $r) {
            $out[(int)$r['cc']] = ($out[(int)$r['cc']] ?? 0) + (float)$r['amt'];
        }
        return array_map('r2', $out);
    }

    /** How much of a cost code's current budget is still free (budget less actual less committed), or null without a budget */
    public static function remainingBudget(int $projectId, ?int $costCodeId): ?float
    {
        if (!$costCodeId) {
            return null;
        }
        $b = self::budgets($projectId);
        if (!$b['current']) {
            return null;
        }
        $bud = array_sum(self::budgetByCode((int)$b['current']['id'])[$costCodeId] ?? []);
        $act = array_sum(array_intersect_key(self::costByCode($projectId)[$costCodeId] ?? [], array_flip(self::DIRECT)));
        $com = (float)(self::commitmentsByCode($projectId)[$costCodeId] ?? 0);
        return r2($bud - $act - $com);
    }

    /**
     * Budget control: a request or purchase that would take a cost code past its remaining budget is refused
     * (setting "block") or warned about ("warn"). Returns the warning text, or '' when within budget.
     */
    public static function checkBudget(int $projectId, ?int $costCodeId, float $amount, string $what): string
    {
        $left = self::remainingBudget($projectId, $costCodeId);
        if ($left === null || $amount <= $left + 0.005) {
            return '';
        }
        $msg = $what . ' of ' . money($amount) . ' goes past the remaining budget of ' . self::codeLabel($costCodeId) . ' (' . money(max(0, $left)) . ' left'
            . ($left < 0 ? ', already over by ' . money(-$left) : '') . ').';
        if (Settings::get('cs_budget_control', 'warn') === 'block') {
            fail($msg . ' Raise a budget revision first (Planning & Estimating > Project Budgets).');
        }
        return $msg;
    }
}
