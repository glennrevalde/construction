<?php
declare(strict_types=1);

/**
 * Shared cost allocation. A cost recorded without a project (office rent, a supervisor working for several sites, a
 * fuel tanker serving all projects) is spread over projects by a basis: equally, fixed percentages, man-hours,
 * equipment hours, materials cost, direct cost, contract value, revenue, floor area or custom units.
 *
 * Posting is a reclassification journal on the same account: Cr the account without a project (or the class it was
 * under), Dr the account for each project's class (with a cost code when given). The source transaction is untouched,
 * so the original record and the allocation stay side by side. A posted allocation is never edited: it is reversed
 * (its journal voided, with the reason) or reallocated (a new allocation that, once posted, reverses the old one).
 */
final class CsAlloc
{
    public const METHODS = ['equal' => 'Equally', 'fixed_pct' => 'Fixed percentages (rule)', 'man_hours' => 'Man-hours', 'equipment_hours' => 'Equipment hours',
        'material_cost' => 'Materials cost', 'direct_cost' => 'Direct cost', 'contract_value' => 'Contract value', 'revenue' => 'Revenue', 'floor_area' => 'Floor area', 'custom' => 'Custom units (rule)'];

    public static function get(int $id): ?array
    {
        return DB::row('SELECT a.*, ac.code AS acct_code, ac.name AS acct_name FROM cs_allocations a JOIN accounts ac ON ac.id = a.source_account_id WHERE a.id = :id', ['id' => $id]);
    }

    /** The basis of each project for a method over a period: [projectId => value] */
    public static function basis(string $method, array $projectIds, ?string $from, ?string $to, ?int $ruleId = null): array
    {
        $out = array_fill_keys(array_map('intval', $projectIds), 0.0);
        if (!$out) {
            return [];
        }
        [$in, $pp] = DB::in('ab', array_keys($out));
        $from = $from ?: '1900-01-01';
        $to = $to ?: today();
        switch ($method) {
            case 'equal':
                return array_fill_keys(array_keys($out), 1.0);
            case 'fixed_pct':
            case 'custom':
                foreach ($ruleId ? DB::all("SELECT project_id, share_value FROM cs_alloc_rule_shares WHERE rule_id = :r AND project_id IN $in", ['r' => $ruleId] + $pp) : [] as $r) {
                    $out[(int)$r['project_id']] = (float)$r['share_value'];
                }
                return $out;
            case 'man_hours':
                $rows = DB::pairs("SELECT t.project_id, SUM(l.regular_hours + l.ot_hours) FROM cs_timesheet_lines l JOIN cs_timesheets t ON t.id = l.timesheet_id
                                    WHERE t.project_id IN $in AND t.status IN ('approved','posted') AND t.work_date BETWEEN :f AND :t GROUP BY t.project_id", $pp + ['f' => $from, 't' => $to]);
                break;
            case 'equipment_hours':
                $rows = DB::pairs("SELECT project_id, SUM(operating_hours) FROM cs_equipment_logs WHERE project_id IN $in AND status IN ('approved','posted') AND log_date BETWEEN :f AND :t GROUP BY project_id", $pp + ['f' => $from, 't' => $to]);
                break;
            case 'material_cost':
            case 'direct_cost':
                $rows = [];
                foreach (CsCost::costByType(array_keys($out), $to, $from) as $pid => $c) {
                    $rows[$pid] = $method === 'material_cost' ? $c['material'] : $c['direct'];
                }
                break;
            case 'contract_value':
                $rows = [];
                foreach (array_keys($out) as $pid) {
                    $rows[$pid] = CsProject::contractValue($pid)['current'];
                }
                break;
            case 'revenue':
                $rows = CsCost::revenue(array_keys($out), $to, $from);
                break;
            case 'floor_area':
                $rows = DB::pairs("SELECT id, floor_area FROM cs_projects WHERE id IN $in", $pp);
                break;
            default:
                fail('Unknown allocation method.');
        }
        foreach ($rows as $pid => $v) {
            if (isset($out[(int)$pid])) {
                $out[(int)$pid] = max(0, (float)$v);
            }
        }
        return $out;
    }

    /** Spreads an amount by a basis; the rounding goes to the largest share */
    public static function spread(float $amount, array $basis, string $method): array
    {
        $sum = array_sum($basis);
        if ($sum <= 0) {
            fail('The basis is zero for every project chosen (' . (self::METHODS[$method] ?? $method) . '). Choose another method or other projects.');
        }
        if ($method === 'fixed_pct' && abs($sum - 100) > 0.01) {
            fail('The rule\'s percentages add up to ' . number_format($sum, 2) . '%, not 100%.');
        }
        $lines = [];
        $done = 0.0;
        foreach ($basis as $pid => $v) {
            $amt = r2($amount * $v / $sum);
            $lines[$pid] = ['project_id' => $pid, 'basis_value' => $v, 'pct' => round($v / $sum * 100, 4), 'amount' => $amt];
            $done += $amt;
        }
        $diff = r2($amount - $done);
        if (abs($diff) >= 0.005) {
            $big = array_key_first(array_filter($lines, fn($l) => $l['amount'] == max(array_column($lines, 'amount'))));
            $lines[$big]['amount'] = r2($lines[$big]['amount'] + $diff);
        }
        return array_values(array_filter($lines, fn($l) => abs($l['amount']) >= 0.005));
    }

    /** What is left to allocate of a source transaction's line on an account (the part without a project, or under its class) */
    public static function remaining(int $txnId, int $accountId, ?int $classId, ?int $exceptAlloc = null): float
    {
        $amt = (float)DB::value('SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE txn_id = :t AND account_id = :a AND (class_id <=> :c)', ['t' => $txnId, 'a' => $accountId, 'c' => $classId]);
        $used = (float)DB::value("SELECT COALESCE(SUM(amount),0) FROM cs_allocations WHERE source_txn_id = :t AND source_account_id = :a AND (source_class_id <=> :c) AND status IN ('draft','for_approval','posted')"
            . ($exceptAlloc ? ' AND id <> :x' : ''), ['t' => $txnId, 'a' => $accountId, 'c' => $classId] + ($exceptAlloc ? ['x' => $exceptAlloc] : []));
        return r2($amt - $used);
    }

    public static function save(array $d, array $projectIds, ?int $id = null): int
    {
        return DB::tx(function () use ($d, $projectIds, $id) {
            $old = $id ? self::get($id) : null;
            if ($old && !in_array($old['status'], ['draft', 'rejected'], true)) {
                fail('A posted allocation is never changed: reverse or reallocate it.');
            }
            $acct = (int)($d['source_account_id'] ?? 0);
            $a = DB::row('SELECT * FROM accounts WHERE id = :a', ['a' => $acct]);
            if (!$a || $a['category'] !== 'Expense') {
                fail('Choose the expense account to allocate.');
            }
            $amount = r2(to_num($d['amount'] ?? 0));
            if ($amount <= 0) {
                fail('Enter the amount to allocate.');
            }
            $src = (int)($d['source_txn_id'] ?? 0) ?: null;
            $srcClass = (int)($d['source_class_id'] ?? 0) ?: null;
            if ($src) {
                $left = self::remaining($src, $acct, $srcClass, $id);
                if ($amount > $left + 0.005) {
                    fail('Only ' . money(max(0, $left)) . ' of that transaction on ' . $a['code'] . ' ' . $a['name'] . ' is left to allocate. The same cost is never allocated twice.');
                }
            }
            $method = isset(self::METHODS[$d['method'] ?? '']) ? $d['method'] : 'equal';
            $ruleId = (int)($d['rule_id'] ?? 0) ?: null;
            if (in_array($method, ['fixed_pct', 'custom'], true)) {
                if (!$ruleId) {
                    fail('Choose the allocation rule with the shares.');
                }
                $projectIds = array_map('intval', DB::column('SELECT project_id FROM cs_alloc_rule_shares WHERE rule_id = :r', ['r' => $ruleId]));
            }
            $projectIds = array_values(array_unique(array_filter(array_map('intval', $projectIds))));
            if (count($projectIds) < 1) {
                fail('Choose the projects that share the cost.');
            }
            foreach ($projectIds as $pid) {
                if (!CsProject::get($pid)) {
                    fail('A project chosen no longer exists.');
                }
            }
            $from = valid_date((string)($d['period_from'] ?? '')) ? $d['period_from'] : null;
            $to = valid_date((string)($d['period_to'] ?? '')) ? $d['period_to'] : null;
            $lines = self::spread($amount, self::basis($method, $projectIds, $from, $to, $ruleId), $method);
            $row = ['alloc_date' => valid_date((string)($d['alloc_date'] ?? '')) ? $d['alloc_date'] : today(), 'period_from' => $from, 'period_to' => $to, 'source_txn_id' => $src, 'source_account_id' => $acct,
                'source_class_id' => $srcClass, 'description' => mb_substr(trim((string)($d['description'] ?? '')) ?: 'Shared ' . $a['name'], 0, 255), 'amount' => $amount, 'method' => $method,
                'rule_id' => $ruleId, 'cost_code_id' => (int)($d['cost_code_id'] ?? 0) ?: null];
            if ($old) {
                DB::update('cs_allocations', $row + ['status' => 'draft'], 'id = :id', ['id' => $id]);
                DB::run('DELETE FROM cs_allocation_lines WHERE allocation_id = :a', ['a' => $id]);
            } else {
                $id = DB::insert('cs_allocations', $row + ['alloc_no' => Seq::next('cs_allocation'), 'status' => 'draft', 'replaces_id' => (int)($d['replaces_id'] ?? 0) ?: null, 'created_by' => Auth::id()]);
            }
            foreach ($lines as $l) {
                DB::insert('cs_allocation_lines', $l + ['allocation_id' => $id]);
            }
            Audit::log($old ? 'edit' : 'create', 'cost', 'allocation', $id, $row['description'], ['page_key' => 'cost.allocations', 'amount' => $amount, 'reason' => self::METHODS[$method] . ', ' . count($lines) . ' project(s)']);
            return $id;
        });
    }

    public static function post(int $id): int
    {
        $al = self::get($id);
        if (!$al || $al['txn_id']) {
            return (int)($al['txn_id'] ?? 0);
        }
        if ($al['source_txn_id'] && !$al['replaces_id'] && self::remaining((int)$al['source_txn_id'], (int)$al['source_account_id'], $al['source_class_id'] ? (int)$al['source_class_id'] : null, $id) < (float)$al['amount'] - 0.005) {
            fail('The source cost was allocated in the meantime; nothing is left for this allocation.');
        }
        $jl = [['account_id' => (int)$al['source_account_id'], 'amount' => -(float)$al['amount'], 'class_id' => $al['source_class_id'] ? (int)$al['source_class_id'] : null, 'description' => 'Allocated: ' . $al['description']]];
        foreach (DB::all('SELECT l.*, p.class_id, p.code FROM cs_allocation_lines l JOIN cs_projects p ON p.id = l.project_id WHERE l.allocation_id = :a', ['a' => $id]) as $l) {
            $jl[] = ['account_id' => (int)$al['source_account_id'], 'amount' => (float)$l['amount'], 'class_id' => (int)$l['class_id'], 'cost_code_id' => $al['cost_code_id'],
                'description' => $al['description'] . ' (' . number_format((float)$l['pct'], 2) . '%)'];
        }
        $txn = CsPost::journal($al['alloc_date'], 'Cost allocation ' . $al['alloc_no'] . ': ' . $al['description'] . ' (' . (self::METHODS[$al['method']] ?? $al['method']) . ')', $jl, null, $al['alloc_no']);
        DB::update('cs_allocations', ['status' => 'posted', 'txn_id' => $txn, 'approved_by' => Auth::id()], 'id = :id', ['id' => $id]);
        Audit::log('post', 'cost', 'allocation', $id, $al['alloc_no'], ['page_key' => 'cost.allocations', 'amount' => $al['amount']]);
        if ($al['replaces_id']) {
            $old = self::get((int)$al['replaces_id']);
            if ($old && $old['status'] === 'posted') {
                self::reverse((int)$old['id'], 'Reallocated by ' . $al['alloc_no']);
            }
        }
        return $txn;
    }

    public static function reverse(int $id, string $reason): void
    {
        DB::tx(function () use ($id, $reason) {
            $al = self::get($id);
            if (!$al || $al['status'] !== 'posted') {
                fail('Only a posted allocation is reversed.');
            }
            if (trim($reason) === '') {
                fail('Give the reason for the reversal.');
            }
            CsPost::void((int)$al['txn_id'], 'Allocation ' . $al['alloc_no'] . ' reversed: ' . $reason);
            DB::update('cs_allocations', ['status' => 'reversed', 'reversal_reason' => mb_substr($reason, 0, 255)], 'id = :id', ['id' => $id]);
            Audit::log('reverse', 'cost', 'allocation', $id, $al['alloc_no'], ['page_key' => 'cost.allocations', 'amount' => $al['amount'], 'reason' => $reason]);
        });
    }

    public static function workflow(string $type, string $event, int $id, array $req): void
    {
        if ($type !== 'allocation') {
            return;
        }
        $status = ['submitted' => 'for_approval', 'rejected' => 'rejected', 'returned' => 'draft', 'cancelled' => 'draft'][$event] ?? null;
        if ($status) {
            DB::update('cs_allocations', ['status' => $status], 'id = :id', ['id' => $id]);
        }
        if ($event === 'approved') {
            self::post($id);
        }
    }

    /** Expenses recorded without a project, not yet fully allocated: per transaction and account */
    public static function waiting(?string $from = null, ?string $to = null, int $limit = 200): array
    {
        $rows = DB::all("SELECT j.txn_id, j.account_id, t.txn_type, t.txn_no, t.txn_date, t.memo, ac.code, ac.name, SUM(j.debit - j.credit) AS amt
                           FROM journal_lines j JOIN accounts ac ON ac.id = j.account_id JOIN transactions t ON t.id = j.txn_id
                          WHERE j.class_id IS NULL AND ac.category = 'Expense' AND t.is_voided = 0 AND j.txn_date BETWEEN :f AND :t
                            AND ac.id NOT IN (:x1, :x2)
                          GROUP BY j.txn_id, j.account_id, t.txn_type, t.txn_no, t.txn_date, t.memo, ac.code, ac.name HAVING amt > 0.004 ORDER BY t.txn_date DESC LIMIT " . (int)$limit,
            ['f' => $from ?: date('Y-m-d', strtotime('-6 months')), 't' => $to ?: today(), 'x1' => (int)(DB::value("SELECT account_id FROM default_accounts WHERE setting_key = 'cs_equipment_recovery'") ?: 0),
                'x2' => CsCost::lossAccount()]);
        $out = [];
        foreach ($rows as $r) {
            $left = self::remaining((int)$r['txn_id'], (int)$r['account_id'], null);
            if ($left > 0.004) {
                $r['left'] = $left;
                $out[] = $r;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ cost code assignment

    /** Project costs posted without a cost code */
    public static function uncoded(?array $projectIds, int $limit = 300): array
    {
        $cls = CsCost::classes($projectIds);
        if (!$cls) {
            return [];
        }
        [$in, $pp] = DB::in('unc', array_keys($cls));
        return DB::all("SELECT j.*, ac.code, ac.name AS acct_name, t.txn_type, t.txn_no FROM journal_lines j JOIN accounts ac ON ac.id = j.account_id JOIN transactions t ON t.id = j.txn_id
                         WHERE j.class_id IN $in AND j.cost_code_id IS NULL AND ac.category = 'Expense' AND t.is_voided = 0 AND j.debit - j.credit <> 0
                         ORDER BY j.txn_date DESC, j.id DESC LIMIT " . (int)$limit, $pp);
    }

    /**
     * Gives a ledger line its cost code, and the matching line of its transaction too (so a later edit of the
     * transaction keeps it).
     */
    public static function assign(int $journalLineId, int $costCodeId): void
    {
        $j = DB::row('SELECT j.*, t.class_id AS h_class FROM journal_lines j JOIN transactions t ON t.id = j.txn_id WHERE j.id = :id', ['id' => $journalLineId]);
        if (!$j || !CsCost::code($costCodeId)) {
            fail('Ledger line or cost code not found.');
        }
        DB::update('journal_lines', ['cost_code_id' => $costCodeId], 'id = :id', ['id' => $journalLineId]);
        $amt = r2((float)$j['debit'] - (float)$j['credit']);
        $cands = DB::all('SELECT l.id, l.amount FROM transaction_lines l LEFT JOIN items i ON i.id = l.item_id
                           WHERE l.txn_id = :t AND l.cost_code_id IS NULL AND (l.account_id = :a OR i.expense_account_id = :a2 OR i.cogs_account_id = :a3)
                             AND COALESCE(l.class_id, :hc) <=> :c', ['t' => $j['txn_id'], 'a' => $j['account_id'], 'a2' => $j['account_id'], 'a3' => $j['account_id'], 'hc' => $j['h_class'], 'c' => $j['class_id']]);
        $exact = array_values(array_filter($cands, fn($c) => abs(abs((float)$c['amount']) - abs($amt)) < 0.01));
        foreach ($exact ? [$exact[0]] : $cands as $c) {
            DB::update('transaction_lines', ['cost_code_id' => $costCodeId], 'id = :id', ['id' => $c['id']]);
        }
        Audit::log('assign_cost_code', 'cost', 'journal_line', $journalLineId, CsCost::codeLabel($costCodeId), ['page_key' => 'cost.assign', 'amount' => $amt]);
    }
}
