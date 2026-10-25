<?php
declare(strict_types=1);

/**
 * Pre-construction and planning: estimates, the BOQ / schedule of values, budgets (an approved baseline kept for
 * good, and approved revisions), the schedule with its critical path, milestones and deliverables, and extensions
 * of time.
 */
final class CsPlanning
{
    /** Estimate categories => the cost type they become in a budget */
    public const EST_CATS = [
        'materials' => ['Construction materials', 'material'], 'labor' => ['Direct labor', 'labor'], 'equipment' => ['Construction equipment', 'equipment'],
        'subcontract' => ['Subcontractor services', 'subcontract'], 'hauling' => ['Transportation and hauling', 'other'], 'site_prep' => ['Site preparation', 'other'],
        'utilities' => ['Utilities', 'other'], 'permits' => ['Permits and fees', 'other'], 'temporary' => ['Temporary facilities', 'other'],
        'site_admin' => ['Site administration', 'labor'], 'overhead' => ['Project overhead', 'other'], 'corporate_overhead' => ['Shared corporate overhead', 'overhead'],
        'contingency' => ['Contingencies and other costs', 'other'],
    ];

    // ------------------------------------------------------------------ estimates

    public static function saveEstimate(array $d, array $lines, ?int $id = null): int
    {
        return DB::tx(function () use ($d, $lines, $id) {
            $old = $id ? DB::row('SELECT * FROM cs_estimates WHERE id = :id', ['id' => $id]) : null;
            if ($old && !in_array($old['status'], ['draft', 'rejected'], true)) {
                fail('Only a draft estimate can be changed. Copy it to a new version to revise an approved one.');
            }
            $clean = [];
            $cost = 0.0;
            foreach ($lines as $l) {
                if (trim((string)($l['description'] ?? '')) === '' && empty($l['item_id'])) {
                    continue;
                }
                $qty = to_num($l['qty'] ?? 0);
                $uc = to_num($l['unit_cost'] ?? 0);
                $amt = r2($qty * $uc);
                $desc = trim((string)($l['description'] ?? '')) ?: (string)DB::value('SELECT name FROM items WHERE id = :i', ['i' => (int)$l['item_id']]);
                $clean[] = ['category' => isset(self::EST_CATS[$l['category'] ?? '']) ? $l['category'] : 'materials', 'cost_code_id' => (int)($l['cost_code_id'] ?? 0) ?: null,
                    'item_id' => (int)($l['item_id'] ?? 0) ?: null, 'boq_ref' => mb_substr(trim((string)($l['boq_ref'] ?? '')), 0, 30) ?: null, 'description' => mb_substr($desc, 0, 255),
                    'qty' => $qty, 'unit' => mb_substr(trim((string)($l['unit'] ?? '')), 0, 20) ?: null, 'unit_cost' => $uc, 'amount' => $amt];
                $cost += $amt;
            }
            if (!$clean) {
                fail('Add at least one estimate line.');
            }
            $cont = to_num($d['contingency_pct'] ?? 0);
            $mark = to_num($d['markup_pct'] ?? 0);
            $contAmt = r2($cost * $cont / 100);
            $row = ['project_id' => (int)($d['project_id'] ?? 0) ?: null, 'opportunity_id' => (int)($d['opportunity_id'] ?? 0) ?: null,
                'title' => trim((string)($d['title'] ?? '')), 'markup_pct' => $mark, 'contingency_pct' => $cont, 'total_cost' => r2($cost), 'contingency_amt' => $contAmt,
                'total_price' => r2(($cost + $contAmt) * (1 + $mark / 100)), 'notes' => trim((string)($d['notes'] ?? '')) ?: null];
            if ($row['title'] === '') {
                fail('Enter the estimate title.');
            }
            if (!$row['project_id'] && !$row['opportunity_id']) {
                fail('An estimate belongs to a project or to a lead / tender.');
            }
            if ($old) {
                $row['updated_at'] = date('Y-m-d H:i:s');
                DB::update('cs_estimates', $row, 'id = :id', ['id' => $id]);
                DB::run('DELETE FROM cs_estimate_lines WHERE estimate_id = :e', ['e' => $id]);
            } else {
                $row['estimate_no'] = Seq::next('cs_estimate');
                $row['version_no'] = 1 + (int)DB::value('SELECT COALESCE(MAX(version_no),0) FROM cs_estimates WHERE (project_id <=> :p) AND (opportunity_id <=> :o)', ['p' => $row['project_id'], 'o' => $row['opportunity_id']]);
                $row['status'] = 'draft';
                $row['created_by'] = Auth::id();
                $id = DB::insert('cs_estimates', $row);
            }
            foreach ($clean as $i => $l) {
                DB::insert('cs_estimate_lines', $l + ['estimate_id' => $id, 'line_no' => $i + 1]);
            }
            Audit::log($old ? 'edit' : 'create', 'plan', 'estimate', $id, $row['title'], ['page_key' => 'plan.estimates', 'amount' => $row['total_price']]);
            return $id;
        });
    }

    /** A new draft budget from an approved estimate: its lines summed by cost code and cost type */
    public static function budgetFromEstimate(int $estimateId): int
    {
        $e = DB::row('SELECT * FROM cs_estimates WHERE id = :id', ['id' => $estimateId]);
        if (!$e || $e['status'] !== 'approved') {
            fail('Only an approved estimate becomes a budget.');
        }
        if (!$e['project_id']) {
            fail('The estimate has no project yet. Turn the lead into a project first.');
        }
        $lines = [];
        $fallback = (int)DB::value("SELECT id FROM cs_cost_codes WHERE code = '01-100'") ?: (int)DB::value('SELECT id FROM cs_cost_codes WHERE parent_id IS NOT NULL ORDER BY sort_order LIMIT 1');
        foreach (DB::all('SELECT * FROM cs_estimate_lines WHERE estimate_id = :e ORDER BY line_no', ['e' => $estimateId]) as $l) {
            $lines[] = ['cost_code_id' => $l['cost_code_id'] ?: $fallback, 'category' => self::EST_CATS[$l['category']][1] ?? 'other', 'description' => $l['description'],
                'qty' => $l['qty'], 'unit' => $l['unit'], 'unit_cost' => $l['unit_cost'], 'amount' => $l['amount']];
        }
        if ((float)$e['contingency_amt'] > 0) {
            $lines[] = ['cost_code_id' => $fallback, 'category' => 'other', 'description' => 'Contingency ' . (float)$e['contingency_pct'] . '%', 'qty' => 1, 'unit' => 'lot',
                'unit_cost' => $e['contingency_amt'], 'amount' => $e['contingency_amt']];
        }
        return self::saveBudget(['project_id' => $e['project_id'], 'name' => 'Budget from estimate ' . $e['estimate_no'], 'source_estimate_id' => $estimateId,
            'reason' => 'Generated from the approved estimate ' . $e['estimate_no'] . ' v' . $e['version_no']], $lines);
    }

    // ------------------------------------------------------------------ budgets

    public static function saveBudget(array $d, array $lines, ?int $id = null): int
    {
        return DB::tx(function () use ($d, $lines, $id) {
            $old = $id ? DB::row('SELECT * FROM cs_budgets WHERE id = :id', ['id' => $id]) : null;
            if ($old && !in_array($old['status'], ['draft', 'rejected'], true)) {
                fail('An approved budget is never changed: make a revision instead. The original baseline is kept for comparison.');
            }
            $projectId = (int)($d['project_id'] ?? ($old['project_id'] ?? 0));
            if (!CsProject::get($projectId)) {
                fail('Choose the project.');
            }
            $clean = [];
            $tot = 0.0;
            foreach ($lines as $l) {
                if (empty($l['cost_code_id']) && trim((string)($l['description'] ?? '')) === '') {
                    continue;
                }
                if (empty($l['cost_code_id'])) {
                    fail('Every budget line needs a cost code (' . ($l['description'] ?? '') . ').');
                }
                $qty = to_num($l['qty'] ?? 0);
                $uc = to_num($l['unit_cost'] ?? 0);
                $amt = isset($l['amount']) && $l['amount'] !== '' && $qty == 0.0 ? r2(to_num($l['amount'])) : r2($qty * $uc);
                if ($qty == 0.0 && $amt != 0.0) {
                    $qty = 1;
                    $uc = $amt;
                }
                $clean[] = ['cost_code_id' => (int)$l['cost_code_id'], 'category' => isset(CsCost::TYPES[$l['category'] ?? '']) ? $l['category'] : 'material',
                    'description' => mb_substr(trim((string)($l['description'] ?? '')), 0, 255) ?: null, 'qty' => $qty, 'unit' => mb_substr(trim((string)($l['unit'] ?? '')), 0, 20) ?: null,
                    'unit_cost' => $uc, 'amount' => $amt];
                $tot += $amt;
            }
            if (!$clean) {
                fail('Add at least one budget line.');
            }
            $hasBase = (int)DB::value("SELECT COUNT(*) FROM cs_budgets WHERE project_id = :p AND is_baseline = 1 AND status IN ('approved','superseded')", ['p' => $projectId]) > 0;
            $row = ['name' => trim((string)($d['name'] ?? '')) ?: 'Budget', 'effective_date' => valid_date((string)($d['effective_date'] ?? '')) ? $d['effective_date'] : today(),
                'reason' => trim((string)($d['reason'] ?? '')) ?: null, 'total_amount' => r2($tot)];
            if ($hasBase && $row['reason'] === null) {
                fail('A budget revision needs its reason (what changed and why).');
            }
            if ($old) {
                DB::update('cs_budgets', $row, 'id = :id', ['id' => $id]);
                DB::run('DELETE FROM cs_budget_lines WHERE budget_id = :b', ['b' => $id]);
            } else {
                $row += ['project_id' => $projectId, 'version_no' => 1 + (int)DB::value('SELECT COALESCE(MAX(version_no),0) FROM cs_budgets WHERE project_id = :p', ['p' => $projectId]),
                    'kind' => $hasBase ? 'revision' : 'original', 'status' => 'draft', 'source_estimate_id' => $d['source_estimate_id'] ?? null,
                    'change_order_id' => $d['change_order_id'] ?? null, 'created_by' => Auth::id()];
                $id = DB::insert('cs_budgets', $row);
            }
            foreach ($clean as $l) {
                DB::insert('cs_budget_lines', $l + ['budget_id' => $id]);
            }
            Audit::log($old ? 'edit' : 'create', 'plan', 'budget', $id, $row['name'], ['page_key' => 'plan.budgets', 'amount' => $tot, 'reason' => $row['reason']]);
            return $id;
        });
    }

    /** A draft revision: the budget in force plus the cost lines of a client-approved change order */
    public static function reviseFromChangeOrder(array $co): ?int
    {
        $b = CsCost::budgets((int)$co['project_id']);
        if (!$b['current']) {
            return null;
        }
        $lines = DB::all('SELECT cost_code_id, category, description, qty, unit, unit_cost, amount FROM cs_budget_lines WHERE budget_id = :b', ['b' => $b['current']['id']]);
        $added = false;
        foreach (DB::all('SELECT * FROM cs_co_lines WHERE co_id = :c', ['c' => $co['id']]) as $l) {
            if (abs((float)$l['cost_amount']) < 0.005) {
                continue;
            }
            $cc = $l['cost_code_id'] ?: (int)DB::value('SELECT id FROM cs_cost_codes WHERE parent_id IS NOT NULL ORDER BY sort_order LIMIT 1');
            $lines[] = ['cost_code_id' => $cc, 'category' => CsCost::code((int)$cc)['category'] ?? 'other', 'description' => mb_substr($co['co_no'] . ': ' . $l['description'], 0, 255),
                'qty' => 1, 'unit' => 'lot', 'unit_cost' => $l['cost_amount'], 'amount' => $l['cost_amount']];
            $added = true;
        }
        if (!$added) {
            $cc = (int)DB::value("SELECT id FROM cs_cost_codes WHERE code = '01-100'") ?: (int)DB::value('SELECT id FROM cs_cost_codes WHERE parent_id IS NOT NULL ORDER BY sort_order LIMIT 1');
            $lines[] = ['cost_code_id' => $cc, 'category' => 'other', 'description' => mb_substr($co['co_no'] . ': ' . $co['title'], 0, 255), 'qty' => 1, 'unit' => 'lot',
                'unit_cost' => $co['cost_impact'], 'amount' => $co['cost_impact']];
        }
        return self::saveBudget(['project_id' => $co['project_id'], 'name' => 'Revision for ' . $co['co_no'], 'change_order_id' => $co['id'],
            'reason' => 'Cost impact of the client-approved change order ' . $co['co_no'] . ' (' . $co['title'] . ')'], $lines);
    }

    /** Approval events of estimates and budgets */
    public static function workflow(string $type, string $event, int $id, array $req): void
    {
        $table = $type === 'estimate' ? 'cs_estimates' : 'cs_budgets';
        $status = ['submitted' => 'for_approval', 'approved' => 'approved', 'rejected' => 'rejected', 'returned' => 'draft', 'cancelled' => 'draft'][$event] ?? null;
        if (!$status) {
            return;
        }
        $row = ['status' => $status];
        if ($event === 'approved') {
            $row += ['approved_by' => Auth::id(), 'approved_at' => date('Y-m-d H:i:s')];
        }
        if ($type === 'budget' && $event === 'approved') {
            $b = DB::row('SELECT * FROM cs_budgets WHERE id = :id', ['id' => $id]);
            $hasBase = (int)DB::value("SELECT COUNT(*) FROM cs_budgets WHERE project_id = :p AND is_baseline = 1 AND status IN ('approved','superseded') AND id <> :id", ['p' => $b['project_id'], 'id' => $id]) > 0;
            if (!$hasBase) {
                $row['is_baseline'] = 1;
            }
            DB::run("UPDATE cs_budgets SET status = 'superseded' WHERE project_id = :p AND status = 'approved' AND id <> :id", ['p' => $b['project_id'], 'id' => $id]);
        }
        DB::update($table, $row, 'id = :id', ['id' => $id]);
    }

    // ------------------------------------------------------------------ the BOQ / schedule of values

    /** The amount already billed on each BOQ line (billings that were approved, submitted, certified or invoiced) */
    public static function billedByBoq(int $projectId, ?int $exceptBillingId = null): array
    {
        $p = ['p' => $projectId];
        $w = '';
        if ($exceptBillingId) {
            $w = ' AND b.id <> :x';
            $p['x'] = $exceptBillingId;
        }
        $out = [];
        foreach (DB::all("SELECT l.boq_item_id, SUM(l.this_amount) AS amt, SUM(l.this_qty) AS q FROM cs_billing_lines l JOIN cs_billings b ON b.id = l.billing_id
                           WHERE b.project_id = :p AND l.boq_item_id IS NOT NULL AND b.status NOT IN ('draft','rejected','cancelled','returned') $w GROUP BY l.boq_item_id", $p) as $r) {
            $out[(int)$r['boq_item_id']] = ['amount' => (float)$r['amt'], 'qty' => (float)$r['q']];
        }
        return $out;
    }

    public static function saveBoq(int $projectId, array $lines): void
    {
        DB::tx(function () use ($projectId, $lines) {
            $p = CsProject::get($projectId);
            if (!$p) {
                fail('Project not found.');
            }
            $billed = self::billedByBoq($projectId);
            $existing = array_map('intval', DB::column('SELECT id FROM cs_boq_items WHERE project_id = :p AND is_variation = 0', ['p' => $projectId]));
            $keep = [];
            $sort = 0;
            foreach ($lines as $l) {
                if (trim((string)($l['description'] ?? '')) === '') {
                    continue;
                }
                $qty = to_num($l['qty'] ?? 0);
                $rate = to_num($l['unit_rate'] ?? 0);
                $row = ['item_no' => mb_substr(trim((string)($l['item_no'] ?? '')), 0, 30) ?: (string)($sort / 10 + 1), 'description' => mb_substr(trim((string)$l['description']), 0, 255),
                    'unit' => mb_substr(trim((string)($l['unit'] ?? '')), 0, 20) ?: null, 'qty' => $qty, 'unit_rate' => $rate, 'amount' => r2($qty * $rate),
                    'cost_code_id' => (int)($l['cost_code_id'] ?? 0) ?: null, 'sort_order' => $sort += 10];
                $lid = (int)($l['id'] ?? 0);
                if ($lid && in_array($lid, $existing, true)) {
                    if (isset($billed[$lid]) && $row['amount'] + 0.005 < $billed[$lid]['amount']) {
                        fail('BOQ item ' . $row['item_no'] . ' has already been billed ' . money($billed[$lid]['amount']) . ': its value cannot go below that.');
                    }
                    DB::update('cs_boq_items', $row, 'id = :id', ['id' => $lid]);
                    $keep[] = $lid;
                } else {
                    $keep[] = DB::insert('cs_boq_items', $row + ['project_id' => $projectId, 'contract_id' => $p['contract_id']]);
                }
            }
            foreach (array_diff($existing, $keep) as $gone) {
                if (isset($billed[$gone]) || DB::value('SELECT id FROM cs_billing_lines WHERE boq_item_id = :b LIMIT 1', ['b' => $gone])) {
                    fail('A BOQ item that is on a billing cannot be removed.');
                }
                DB::run('DELETE FROM cs_boq_items WHERE id = :id', ['id' => $gone]);
            }
            $total = (float)DB::value('SELECT COALESCE(SUM(amount),0) FROM cs_boq_items WHERE project_id = :p AND is_variation = 0', ['p' => $projectId]);
            Audit::log('edit', 'plan', 'boq', $projectId, $p['code'] . ' BOQ', ['page_key' => 'plan.boq', 'amount' => $total, 'reason' => count($keep) . ' BOQ line(s)']);
        });
    }

    // ------------------------------------------------------------------ the schedule

    /**
     * Saves the activity list of a project from the schedule grid. The parent of an activity comes from its WBS code
     * (1.2.3 sits under 1.2); predecessors are written as WBS codes with an optional type and lag ("1.1", "1.2SS",
     * "2.1FS+3", "2.4FF-2"). Loops in the logic are refused. Activities already used by site reports, timesheets,
     * equipment logs, milestones or the BOQ are not removed.
     */
    public static function saveActivities(int $projectId, array $lines): int
    {
        return DB::tx(function () use ($projectId, $lines) {
            $p = CsProject::get($projectId);
            if (!$p) {
                fail('Project not found.');
            }
            $existing = array_map('intval', DB::column('SELECT id FROM cs_activities WHERE project_id = :p', ['p' => $projectId]));
            $rows = [];
            $byWbs = [];
            foreach ($lines as $l) {
                $wbs = mb_substr(trim((string)($l['wbs_code'] ?? '')), 0, 30);
                $name = mb_substr(trim((string)($l['name'] ?? '')), 0, 200);
                if ($wbs === '' && $name === '') {
                    continue;
                }
                if ($wbs === '' || $name === '') {
                    fail('Every activity needs its WBS code and its name' . ($name !== '' ? ' (' . $name . ')' : '') . '.');
                }
                if (isset($byWbs[$wbs])) {
                    fail('WBS code ' . $wbs . ' is used twice.');
                }
                $start = valid_date((string)($l['planned_start'] ?? '')) ? $l['planned_start'] : null;
                $finish = valid_date((string)($l['planned_finish'] ?? '')) ? $l['planned_finish'] : null;
                $dur = (int)($l['duration'] ?? 0);
                $ms = !empty($l['is_milestone']) ? 1 : 0;
                if ($start && !$finish && ($dur > 0 || $ms)) {
                    $finish = $ms ? $start : self::dayDate(self::dayIndex($start) + $dur - 1);
                }
                if ($start && $finish && $finish < $start) {
                    fail('Activity ' . $wbs . ' finishes before it starts.');
                }
                $byWbs[$wbs] = count($rows);
                $rows[] = ['id' => (int)($l['id'] ?? 0), 'wbs_code' => $wbs, 'name' => $name, 'cost_code_id' => (int)($l['cost_code_id'] ?? 0) ?: null,
                    'planned_start' => $start, 'planned_finish' => $finish, 'weight_pct' => max(0, to_num($l['weight_pct'] ?? 0)), 'planned_qty' => max(0, to_num($l['planned_qty'] ?? 0)),
                    'unit' => mb_substr(trim((string)($l['unit'] ?? '')), 0, 20) ?: null, 'budget_hours' => max(0, to_num($l['budget_hours'] ?? 0)), 'is_milestone' => $ms,
                    'resource_notes' => mb_substr(trim((string)($l['resource_notes'] ?? '')), 0, 255) ?: null, 'preds' => trim((string)($l['predecessors'] ?? ''))];
            }
            // parents from the WBS codes; a parent is a summary row and carries no weight of its own
            $parentWbs = [];
            foreach ($rows as $i => $r) {
                $parts = explode('.', $r['wbs_code']);
                $pw = null;
                while (count($parts) > 1) {
                    array_pop($parts);
                    if (isset($byWbs[implode('.', $parts)])) {
                        $pw = implode('.', $parts);
                        break;
                    }
                }
                $parentWbs[$i] = $pw;
            }
            $isParent = array_flip(array_filter($parentWbs));
            // upsert
            $ids = [];
            $sort = 0;
            foreach ($rows as $i => $r) {
                $data = array_diff_key($r, ['id' => 1, 'preds' => 1]) + ['sort_order' => $sort += 10];
                if (isset($isParent[$r['wbs_code']])) {
                    $data['weight_pct'] = 0;
                }
                if ($r['id'] && in_array($r['id'], $existing, true)) {
                    DB::update('cs_activities', $data, 'id = :id', ['id' => $r['id']]);
                    $ids[$i] = $r['id'];
                } else {
                    $ids[$i] = DB::insert('cs_activities', $data + ['project_id' => $projectId]);
                }
            }
            foreach ($rows as $i => $r) {
                DB::update('cs_activities', ['parent_id' => $parentWbs[$i] !== null ? $ids[$byWbs[$parentWbs[$i]]] : null], 'id = :id', ['id' => $ids[$i]]);
            }
            // removed activities
            foreach (array_diff($existing, $ids) as $gone) {
                foreach (['cs_daily_report_lines' => 'a site report', 'cs_timesheet_lines' => 'a timesheet', 'cs_equipment_logs' => 'an equipment log',
                    'cs_milestones' => 'a milestone', 'cs_boq_items' => 'the BOQ', 'cs_material_requests' => 'a material request', 'cs_subcontract_items' => 'a subcontract'] as $t => $what) {
                    if (DB::value("SELECT id FROM $t WHERE activity_id = :a LIMIT 1", ['a' => $gone])) {
                        fail('Activity ' . DB::value('SELECT wbs_code FROM cs_activities WHERE id = :a', ['a' => $gone]) . ' is used by ' . $what . ' and cannot be removed. Mark it on hold instead.');
                    }
                }
                DB::run('DELETE FROM cs_activities WHERE id = :id', ['id' => $gone]);
            }
            // the logic
            if ($ids) {
                [$in, $pp] = DB::in('dep', array_values($ids));
                DB::run("DELETE FROM cs_activity_deps WHERE activity_id IN $in", $pp);
            }
            $graph = [];
            foreach ($rows as $i => $r) {
                if ($r['preds'] === '') {
                    continue;
                }
                foreach (preg_split('/[,;]+/', $r['preds']) as $tok) {
                    $tok = trim($tok);
                    if ($tok === '') {
                        continue;
                    }
                    if (!preg_match('/^(.+?)\s*(FS|SS|FF|SF)?\s*(?:([+-])\s*(\d+)\s*d?)?$/i', $tok, $m)) {
                        fail('Activity ' . $r['wbs_code'] . ': "' . $tok . '" is not a predecessor (write it like 1.2 or 1.2SS+3).');
                    }
                    $pw = trim($m[1]);
                    if (!isset($byWbs[$pw])) {
                        fail('Activity ' . $r['wbs_code'] . ': predecessor ' . $pw . ' is not in the schedule.');
                    }
                    if ($pw === $r['wbs_code']) {
                        fail('Activity ' . $r['wbs_code'] . ' cannot be its own predecessor.');
                    }
                    $lag = isset($m[4]) && $m[4] !== '' ? (int)$m[4] * (($m[3] ?? '+') === '-' ? -1 : 1) : 0;
                    DB::run('INSERT INTO cs_activity_deps (activity_id, predecessor_id, dep_type, lag_days) VALUES (:a, :p, :t, :l) ON DUPLICATE KEY UPDATE dep_type = VALUES(dep_type), lag_days = VALUES(lag_days)',
                        ['a' => $ids[$i], 'p' => $ids[$byWbs[$pw]], 't' => strtoupper($m[2] ?? '') ?: 'FS', 'l' => $lag]);
                    $graph[$ids[$i]][] = $ids[$byWbs[$pw]];
                }
            }
            // refuse loops (depth-first search)
            $state = [];
            $visit = function (int $n) use (&$visit, &$state, $graph): bool {
                if (($state[$n] ?? 0) === 1) {
                    return false;
                }
                if (($state[$n] ?? 0) === 2) {
                    return true;
                }
                $state[$n] = 1;
                foreach ($graph[$n] ?? [] as $m) {
                    if (!$visit($m)) {
                        return false;
                    }
                }
                $state[$n] = 2;
                return true;
            };
            foreach (array_keys($graph) as $n) {
                if (!$visit($n)) {
                    fail('The predecessors make a loop (an activity ends up waiting for itself). Check activity ' . DB::value('SELECT wbs_code FROM cs_activities WHERE id = :a', ['a' => $n]) . '.');
                }
            }
            Audit::log('edit', 'plan', 'schedule', $projectId, $p['code'] . ' schedule', ['page_key' => 'plan.schedule', 'reason' => count($rows) . ' activities']);
            return count($rows);
        });
    }

    /** Progress entered on the schedule: per activity quantity done or percent, actual dates and status */
    public static function saveProgress(int $projectId, array $lines): void
    {
        DB::tx(function () use ($projectId, $lines) {
            foreach ($lines as $l) {
                $a = DB::row('SELECT * FROM cs_activities WHERE id = :id AND project_id = :p', ['id' => (int)($l['id'] ?? 0), 'p' => $projectId]);
                if (!$a) {
                    continue;
                }
                $row = [];
                if ((float)$a['planned_qty'] > 0 && isset($l['qty_done']) && $l['qty_done'] !== '') {
                    $row['qty_done'] = max(0, to_num($l['qty_done']));
                    $row['progress_pct'] = min(100, round($row['qty_done'] / (float)$a['planned_qty'] * 100, 2));
                } elseif (isset($l['progress_pct']) && $l['progress_pct'] !== '') {
                    $row['progress_pct'] = max(0, min(100, to_num($l['progress_pct'])));
                }
                $pct = $row['progress_pct'] ?? (float)$a['progress_pct'];
                $row['actual_start'] = valid_date((string)($l['actual_start'] ?? '')) ? $l['actual_start'] : ($pct > 0 ? ($a['actual_start'] ?: today()) : null);
                $row['actual_finish'] = valid_date((string)($l['actual_finish'] ?? '')) ? $l['actual_finish'] : ($pct >= 100 ? ($a['actual_finish'] ?: today()) : null);
                if ($row['actual_finish'] && $row['actual_start'] && $row['actual_finish'] < $row['actual_start']) {
                    fail('Activity ' . $a['wbs_code'] . ' finishes before it starts.');
                }
                $hold = ($l['status'] ?? '') === 'on_hold';
                $row['status'] = $hold ? 'on_hold' : ($pct >= 100 ? 'completed' : ($pct > 0 ? 'in_progress' : 'not_started'));
                $changed = false;
                foreach ($row as $k => $v) {
                    if ((string)$a[$k] !== (string)$v && !(is_numeric($a[$k]) && is_numeric($v) && abs((float)$a[$k] - (float)$v) < 0.0001)) {
                        $changed = true;
                    }
                }
                if ($changed) {
                    DB::update('cs_activities', $row, 'id = :id', ['id' => $a['id']]);
                }
            }
            // summary rows follow their children
            foreach (DB::all('SELECT DISTINCT parent_id FROM cs_activities WHERE project_id = :p AND parent_id IS NOT NULL', ['p' => $projectId]) as $r) {
                $agg = DB::row('SELECT SUM(weight_pct * progress_pct) / NULLIF(SUM(weight_pct),0) AS pr, AVG(progress_pct) AS av, MIN(actual_start) AS s FROM cs_activities WHERE parent_id = :p', ['p' => $r['parent_id']]);
                $pr = round((float)($agg['pr'] ?? $agg['av']), 2);
                DB::update('cs_activities', ['progress_pct' => $pr, 'actual_start' => $agg['s'], 'status' => $pr >= 100 ? 'completed' : ($pr > 0 ? 'in_progress' : 'not_started')], 'id = :id', ['id' => $r['parent_id']]);
            }
            $p = CsProject::get($projectId);
            Audit::log('progress', 'plan', 'schedule', $projectId, $p['code'] . ' progress update', ['page_key' => 'plan.schedule', 'reason' => 'Physical progress now ' . CsProject::physicalPct($projectId) . '%']);
        });
    }

    /** Earned value of a project as of a date: BAC, PV, EV, AC, the variances and indexes, and the forecasts */
    public static function evm(int $projectId, ?string $asOf = null): array
    {
        $bud = CsCost::budgets($projectId);
        $bac = (float)($bud['current']['total_amount'] ?? 0);
        $planned = CsProject::plannedPct($projectId, $asOf);
        $phys = CsProject::physicalPct($projectId);
        $ac = (float)(CsCost::costByType([$projectId], $asOf)[$projectId]['total'] ?? 0);
        $pv = $planned !== null ? $bac * $planned / 100 : 0.0;
        $ev = $bac * $phys / 100;
        $cpi = $ac > 0 ? $ev / $ac : null;
        $spi = $pv > 0 ? $ev / $pv : null;
        $eac = $cpi ? $bac / $cpi : $bac;
        return ['bac' => $bac, 'planned_pct' => $planned, 'physical_pct' => $phys, 'pv' => $pv, 'ev' => $ev, 'ac' => $ac, 'cv' => $ev - $ac, 'sv' => $ev - $pv,
            'cpi' => $cpi, 'spi' => $spi, 'eac' => $eac, 'etc' => max(0, $eac - $ac), 'vac' => $bac - $eac,
            'tcpi' => ($bac - $ac) != 0.0 ? ($bac - $ev) / ($bac - $ac) : null];
    }

    /**
     * The critical path: a forward and backward pass over the activities' planned dates and dependencies
     * (finish-to-start, start-to-start, finish-to-finish, start-to-finish, with lags in days). The planned dates are
     * pushed later when a predecessor requires it; total float and the critical flag are stored.
     */
    public static function computeCpm(int $projectId): array
    {
        $acts = [];
        foreach (DB::all('SELECT * FROM cs_activities WHERE project_id = :p ORDER BY sort_order, id', ['p' => $projectId]) as $a) {
            if ((int)DB::value('SELECT COUNT(*) FROM cs_activities WHERE parent_id = :id', ['id' => $a['id']]) > 0) {
                continue; // summary rows take their dates from their children
            }
            if (!$a['planned_start']) {
                continue;
            }
            $dur = $a['planned_finish'] ? max(0, self::dayIndex($a['planned_finish']) - self::dayIndex($a['planned_start']) + ((int)$a['is_milestone'] ? 0 : 1)) : 1;
            $acts[(int)$a['id']] = ['es' => self::dayIndex($a['planned_start']), 'dur' => $dur, 'preds' => []];
        }
        if (!$acts) {
            return ['critical' => 0, 'finish' => null];
        }
        [$in, $pp] = DB::in('cpm', array_keys($acts));
        foreach (DB::all("SELECT * FROM cs_activity_deps WHERE activity_id IN $in", $pp) as $d) {
            if (isset($acts[(int)$d['predecessor_id']])) {
                $acts[(int)$d['activity_id']]['preds'][] = $d;
            }
        }
        // forward pass (repeat until stable; the graph is small)
        for ($pass = 0; $pass < count($acts) + 2; $pass++) {
            $changed = false;
            foreach ($acts as $aid => &$a) {
                foreach ($a['preds'] as $d) {
                    $p = $acts[(int)$d['predecessor_id']];
                    $lag = (int)$d['lag_days'];
                    $need = match ($d['dep_type']) {
                        'SS' => $p['es'] + $lag,
                        'FF' => $p['es'] + $p['dur'] + $lag - $a['dur'],
                        'SF' => $p['es'] + $lag - $a['dur'],
                        default => $p['es'] + $p['dur'] + $lag,
                    };
                    if ($need > $a['es']) {
                        $a['es'] = $need;
                        $changed = true;
                    }
                }
            }
            unset($a);
            if (!$changed) {
                break;
            }
        }
        $finish = max(array_map(fn($a) => $a['es'] + $a['dur'], $acts));
        // backward pass
        foreach ($acts as &$a) {
            $a['lf'] = $finish;
        }
        unset($a);
        for ($pass = 0; $pass < count($acts) + 2; $pass++) {
            $changed = false;
            foreach ($acts as $aid => $a) {
                foreach ($a['preds'] as $d) {
                    $pid = (int)$d['predecessor_id'];
                    $p = $acts[$pid];
                    $lag = (int)$d['lag_days'];
                    $ls = $a['lf'] - $a['dur'];
                    $limit = match ($d['dep_type']) {
                        'SS' => $ls - $lag + $p['dur'],
                        'FF' => $a['lf'] - $lag,
                        'SF' => $a['lf'] - $lag + $p['dur'],
                        default => $ls - $lag,
                    };
                    if ($limit < $acts[$pid]['lf']) {
                        $acts[$pid]['lf'] = $limit;
                        $changed = true;
                    }
                }
            }
            if (!$changed) {
                break;
            }
        }
        $critical = 0;
        foreach ($acts as $aid => $a) {
            $float = $a['lf'] - ($a['es'] + $a['dur']);
            $crit = $float <= 0 ? 1 : 0;
            $critical += $crit;
            $start = self::dayDate($a['es']);
            $end = self::dayDate($a['es'] + max(1, $a['dur']) - 1);
            DB::update('cs_activities', ['planned_start' => $start, 'planned_finish' => $a['dur'] === 0 ? $start : $end, 'total_float' => $float, 'is_critical' => $crit], 'id = :id', ['id' => $aid]);
        }
        // summary rows span their children
        foreach (DB::all('SELECT DISTINCT parent_id FROM cs_activities WHERE project_id = :p AND parent_id IS NOT NULL', ['p' => $projectId]) as $r) {
            $agg = DB::row('SELECT MIN(planned_start) AS s, MAX(planned_finish) AS f, SUM(weight_pct * progress_pct) / NULLIF(SUM(weight_pct),0) AS pr FROM cs_activities WHERE parent_id = :p', ['p' => $r['parent_id']]);
            DB::update('cs_activities', ['planned_start' => $agg['s'], 'planned_finish' => $agg['f'], 'progress_pct' => round((float)$agg['pr'], 2)], 'id = :id', ['id' => $r['parent_id']]);
        }
        return ['critical' => $critical, 'finish' => self::dayDate($finish - 1)];
    }

    /** Whole days since 1970-01-01 of a date (time-zone safe), and back */
    public static function dayIndex(string $date): int
    {
        return intdiv((int)strtotime($date . ' 00:00:00 UTC'), 86400);
    }

    public static function dayDate(int $index): string
    {
        return gmdate('Y-m-d', $index * 86400);
    }

    /** Keeps today's planned dates as the baseline the progress is measured against */
    public static function setBaseline(int $projectId): void
    {
        DB::run('UPDATE cs_activities SET baseline_start = planned_start, baseline_finish = planned_finish WHERE project_id = :p', ['p' => $projectId]);
        Audit::log('baseline', 'plan', 'schedule', $projectId, 'Schedule baseline set', ['page_key' => 'plan.schedule']);
    }

    /** Progress of an activity from its quantities (when it has a planned quantity), and its status */
    public static function addProgress(int $activityId, float $qty, ?string $date = null): void
    {
        $a = DB::row('SELECT * FROM cs_activities WHERE id = :id', ['id' => $activityId]);
        if (!$a) {
            return;
        }
        $done = (float)$a['qty_done'] + $qty;
        $row = ['qty_done' => $done];
        if ((float)$a['planned_qty'] > 0) {
            $row['progress_pct'] = min(100, round($done / (float)$a['planned_qty'] * 100, 2));
        }
        $pct = $row['progress_pct'] ?? (float)$a['progress_pct'];
        if ($pct > 0 && !$a['actual_start']) {
            $row['actual_start'] = $date ?? today();
        }
        $row['status'] = $pct >= 100 ? 'completed' : ($pct > 0 ? 'in_progress' : $a['status']);
        if ($pct >= 100 && !$a['actual_finish']) {
            $row['actual_finish'] = $date ?? today();
        }
        DB::update('cs_activities', $row, 'id = :id', ['id' => $activityId]);
    }

    // ------------------------------------------------------------------ milestones

    /**
     * Billing eligibility is decided here, never by hand: a milestone becomes billable only when it is accepted,
     * every required deliverable is accepted or completed, its approved completion is 100% (or it is measured by a
     * percentage the client certified), and the client's certification is recorded when the contract requires it.
     */
    public static function refreshEligibility(int $milestoneId): bool
    {
        $m = DB::row('SELECT * FROM cs_milestones WHERE id = :id', ['id' => $milestoneId]);
        if (!$m) {
            return false;
        }
        $openReq = (int)DB::value("SELECT COUNT(*) FROM cs_milestone_items WHERE milestone_id = :m AND is_required = 1 AND status NOT IN ('accepted','completed')", ['m' => $milestoneId]);
        $ok = in_array($m['status'], ['accepted', 'completed'], true) && $openReq === 0 && ((float)$m['completion_pct'] >= 100 || $m['measurement_method'] === 'client_certified')
            && (!(int)$m['client_cert_required'] || $m['client_certified_date']);
        DB::update('cs_milestones', ['billing_eligible' => $ok ? 1 : 0], 'id = :id', ['id' => $milestoneId]);
        return $ok;
    }

    public const MS_METHODS = ['physical_pct' => 'Physical percentage', 'quantity' => 'Quantity accomplished', 'completion' => 'Completion of the deliverable',
        'cost_to_cost' => 'Cost-to-cost', 'client_certified' => 'Client-certified percentage'];
    public const MS_DOC_TYPES = ['inspection_report' => 'Inspection report', 'accomplishment_report' => 'Accomplishment report', 'progress_photos' => 'Progress photos',
        'test_results' => 'Test results', 'engineer_certification' => 'Engineer certification', 'signed_acceptance' => 'Signed acceptance', 'as_built' => 'As-built drawings',
        'delivery_ack' => 'Delivery acknowledgement', 'punch_clearance' => 'Punch-list clearance', 'other' => 'Other'];

    public static function saveMilestone(array $d, array $items, ?int $id = null): int
    {
        return DB::tx(function () use ($d, $items, $id) {
            $old = $id ? DB::row('SELECT * FROM cs_milestones WHERE id = :id', ['id' => $id]) : null;
            if ($old && !in_array($old['status'], ['pending', 'in_progress', 'rejected'], true)) {
                fail('A milestone that is submitted, under review or accepted is not changed.');
            }
            $pid = (int)($old['project_id'] ?? ($d['project_id'] ?? 0));
            if (!CsProject::get($pid)) {
                fail('Choose the project.');
            }
            $row = ['code' => mb_substr(trim((string)($d['code'] ?? '')), 0, 30), 'name' => mb_substr(trim((string)($d['name'] ?? '')), 0, 200),
                'scope' => trim((string)($d['scope'] ?? '')) ?: null, 'scheduled_date' => valid_date((string)($d['scheduled_date'] ?? '')) ? $d['scheduled_date'] : null,
                'deliverables' => trim((string)($d['deliverables'] ?? '')) ?: null, 'budget_value' => to_num($d['budget_value'] ?? 0), 'contract_value' => to_num($d['contract_value'] ?? 0),
                'measurement_method' => isset(self::MS_METHODS[$d['measurement_method'] ?? '']) ? $d['measurement_method'] : 'physical_pct',
                'required_inspections' => trim((string)($d['required_inspections'] ?? '')) ?: null, 'responsible_user_id' => (int)($d['responsible_user_id'] ?? 0) ?: null,
                'reviewer_user_id' => (int)($d['reviewer_user_id'] ?? 0) ?: null, 'client_cert_required' => !empty($d['client_cert_required']) ? 1 : 0,
                'activity_id' => (int)($d['activity_id'] ?? 0) ?: null, 'notes' => trim((string)($d['notes'] ?? '')) ?: null];
            if ($row['name'] === '') {
                fail('Enter the milestone name.');
            }
            if ($row['code'] === '') {
                $row['code'] = 'MS-' . str_pad((string)(1 + (int)DB::value('SELECT COUNT(*) FROM cs_milestones WHERE project_id = :p', ['p' => $pid])), 2, '0', STR_PAD_LEFT);
            }
            if ($row['responsible_user_id'] && $row['responsible_user_id'] === $row['reviewer_user_id']) {
                fail('The reviewer has to be someone other than the person responsible.');
            }
            if ($old) {
                DB::update('cs_milestones', $row + ['status' => $old['status'] === 'rejected' ? 'in_progress' : $old['status']], 'id = :id', ['id' => $id]);
            } else {
                $row += ['project_id' => $pid, 'status' => 'pending', 'sort_order' => 10 + (int)DB::value('SELECT COALESCE(MAX(sort_order),0) FROM cs_milestones WHERE project_id = :p', ['p' => $pid])];
                $id = DB::insert('cs_milestones', $row);
            }
            $keep = [];
            $n = 0;
            foreach ($items as $it) {
                $desc = mb_substr(trim((string)($it['description'] ?? '')), 0, 255);
                if ($desc === '') {
                    continue;
                }
                $data = ['description' => $desc, 'doc_type' => isset(self::MS_DOC_TYPES[$it['doc_type'] ?? '']) ? $it['doc_type'] : 'other', 'is_required' => !empty($it['is_required']) ? 1 : 0, 'line_no' => ++$n];
                $iid = (int)($it['id'] ?? 0);
                if ($iid && DB::value('SELECT id FROM cs_milestone_items WHERE id = :i AND milestone_id = :m', ['i' => $iid, 'm' => $id])) {
                    DB::update('cs_milestone_items', $data, 'id = :id', ['id' => $iid]);
                    $keep[] = $iid;
                } else {
                    $keep[] = DB::insert('cs_milestone_items', $data + ['milestone_id' => $id]);
                }
            }
            $gone = DB::column('SELECT id FROM cs_milestone_items WHERE milestone_id = :m', ['m' => $id]);
            foreach (array_diff(array_map('intval', $gone), $keep) as $g) {
                DB::run('DELETE FROM cs_milestone_items WHERE id = :id', ['id' => $g]);
            }
            self::refreshEligibility($id);
            Audit::log($old ? 'edit' : 'create', 'plan', 'milestone', $id, $row['code'] . ' ' . $row['name'], ['page_key' => 'plan.milestones', 'amount' => $row['contract_value']]);
            return $id;
        });
    }

    /** Whether the user reviews milestones (named reviewer, Approve on the page, or an administrator) */
    public static function isMsReviewer(array $m, ?int $userId = null): bool
    {
        $userId ??= (int)Auth::id();
        return (int)$m['reviewer_user_id'] === $userId || Access::can('plan.milestones', 'approve', $userId) || Access::isAdmin($userId);
    }

    /**
     * The review steps of a milestone: submit (by the person responsible), review, accept (with the approved completion) or reject
     * (with the reason), and the client's certification. The submitter never reviews their own submission.
     */
    public static function milestoneAction(int $id, string $action, array $d = []): void
    {
        DB::tx(function () use ($id, $action, $d) {
            $m = DB::row('SELECT * FROM cs_milestones WHERE id = :id FOR UPDATE', ['id' => $id]);
            if (!$m) {
                fail('Milestone not found.');
            }
            $me = (int)Auth::id();
            $reason = trim((string)($d['reason'] ?? ''));
            $lastSubmitter = (int)DB::value("SELECT user_id FROM audit_trail WHERE record_type = 'milestone' AND record_id = :i AND action = 'submit' ORDER BY id DESC LIMIT 1", ['i' => $id]);
            $notifyTo = [];
            switch ($action) {
                case 'submit':
                    if (!in_array($m['status'], ['pending', 'in_progress', 'rejected'], true)) {
                        fail('This milestone is already submitted.');
                    }
                    $open = (int)DB::value("SELECT COUNT(*) FROM cs_milestone_items WHERE milestone_id = :m AND is_required = 1 AND status NOT IN ('submitted','under_review','accepted','completed')", ['m' => $id]);
                    if ($open) {
                        fail($open . ' required deliverable(s) are not submitted yet. Attach the evidence and mark each one submitted first.');
                    }
                    DB::update('cs_milestones', ['status' => 'submitted'], 'id = :id', ['id' => $id]);
                    DB::run("UPDATE cs_milestone_items SET status = 'submitted', updated_by = :u, updated_at = NOW() WHERE milestone_id = :m AND status IN ('pending','in_progress')", ['m' => $id, 'u' => $me]);
                    $notifyTo = $m['reviewer_user_id'] ? [(int)$m['reviewer_user_id']] : [];
                    break;
                case 'review':
                    if ($m['status'] !== 'submitted') {
                        fail('Only a submitted milestone goes under review.');
                    }
                    self::assertMsChecker($m, $lastSubmitter, $me);
                    DB::update('cs_milestones', ['status' => 'under_review'], 'id = :id', ['id' => $id]);
                    break;
                case 'accept':
                    if (!in_array($m['status'], ['submitted', 'under_review'], true)) {
                        fail('Only a submitted milestone is accepted.');
                    }
                    self::assertMsChecker($m, $lastSubmitter, $me);
                    $pct = isset($d['completion_pct']) && $d['completion_pct'] !== '' ? max(0, min(100, to_num($d['completion_pct']))) : 100.0;
                    DB::run("UPDATE cs_milestone_items SET status = 'accepted', updated_by = :u, updated_at = NOW() WHERE milestone_id = :m AND status IN ('submitted','under_review')", ['m' => $id, 'u' => $me]);
                    $openReq = (int)DB::value("SELECT COUNT(*) FROM cs_milestone_items WHERE milestone_id = :m AND is_required = 1 AND status NOT IN ('accepted','completed')", ['m' => $id]);
                    if ($openReq) {
                        fail('Required deliverables were rejected or are missing; the milestone cannot be accepted.');
                    }
                    $done = $m['client_certified_date'] || !(int)$m['client_cert_required'];
                    DB::update('cs_milestones', ['status' => $done && $pct >= 100 ? 'completed' : 'accepted', 'completion_pct' => $pct, 'accepted_date' => today()], 'id = :id', ['id' => $id]);
                    $notifyTo = $lastSubmitter ? [$lastSubmitter] : [];
                    break;
                case 'reject':
                    if (!in_array($m['status'], ['submitted', 'under_review', 'accepted'], true)) {
                        fail('Only a submitted or accepted milestone is rejected.');
                    }
                    if ($reason === '') {
                        fail('Enter the reason for rejecting the milestone.');
                    }
                    self::assertMsChecker($m, $lastSubmitter, $me);
                    DB::update('cs_milestones', ['status' => 'rejected', 'billing_eligible' => 0], 'id = :id', ['id' => $id]);
                    $notifyTo = array_filter([$lastSubmitter, (int)$m['responsible_user_id']]);
                    break;
                case 'certify':
                    if (!Access::can('plan.milestones', 'approve') && !Access::isAdmin()) {
                        fail('Recording the client\'s certification needs the Approve permission on milestones.');
                    }
                    if (!in_array($m['status'], ['accepted', 'completed', 'submitted', 'under_review'], true)) {
                        fail('Record the client\'s certification once the milestone is submitted or accepted.');
                    }
                    $date = valid_date((string)($d['date'] ?? '')) ? $d['date'] : today();
                    $row = ['client_certified_date' => $date, 'client_cert_ref' => mb_substr(trim((string)($d['ref'] ?? '')), 0, 60) ?: null];
                    if ($m['measurement_method'] === 'client_certified' && isset($d['completion_pct']) && $d['completion_pct'] !== '') {
                        $row['completion_pct'] = max(0, min(100, to_num($d['completion_pct'])));
                    }
                    if ($m['status'] === 'accepted' && (float)($row['completion_pct'] ?? $m['completion_pct']) >= 100) {
                        $row['status'] = 'completed';
                    }
                    DB::update('cs_milestones', $row, 'id = :id', ['id' => $id]);
                    $reason = 'Client certificate ' . ($row['client_cert_ref'] ?? '') . ' dated ' . fdate($date);
                    break;
                default:
                    fail('Unknown action.');
            }
            $eligible = self::refreshEligibility($id);
            Audit::log($action, 'plan', 'milestone', $id, $m['code'] . ' ' . $m['name'], ['page_key' => 'plan.milestones', 'amount' => $m['contract_value'],
                'reason' => trim($reason . ($eligible ? ' Now eligible for billing.' : ''))]);
            if ($notifyTo) {
                CsNotify::send(array_values(array_unique($notifyTo)), 'milestone', 'Milestone ' . $m['code'] . ' ' . CsUi::label($action === 'review' ? 'under_review' : ($action === 'submit' ? 'submitted' : $action . 'ed')),
                    $reason, url('plan.milestones', ['a' => 'view', 'id' => $id]), (int)$m['project_id']);
            }
        });
    }

    private static function assertMsChecker(array $m, int $submitter, int $me): void
    {
        if (!self::isMsReviewer($m, $me)) {
            fail('Only the milestone\'s reviewer or someone with Approve on milestones reviews it.');
        }
        if ($submitter === $me && Settings::get('cs_self_approval', '0') !== '1') {
            fail('You submitted this milestone, so someone else reviews it (maker-checker).');
        }
    }

    /** Status of one deliverable: the person responsible marks progress and submits; the reviewer accepts or rejects */
    public static function msItemStatus(int $itemId, string $status, string $remarks): void
    {
        DB::tx(function () use ($itemId, $status, $remarks) {
            $it = DB::row('SELECT i.*, m.status AS ms_status, m.reviewer_user_id, m.project_id, m.code FROM cs_milestone_items i JOIN cs_milestones m ON m.id = i.milestone_id WHERE i.id = :id', ['id' => $itemId]);
            if (!$it) {
                fail('Deliverable not found.');
            }
            $reviewing = in_array($status, ['accepted', 'rejected', 'completed'], true);
            if ($reviewing && !self::isMsReviewer($it)) {
                fail('Only the reviewer accepts or rejects a deliverable.');
            }
            if (!$reviewing && !in_array($status, ['pending', 'in_progress', 'submitted'], true)) {
                fail('Unknown status.');
            }
            if ($status === 'rejected' && trim($remarks) === '') {
                fail('Give the reason the deliverable is rejected.');
            }
            if (in_array($it['ms_status'], ['accepted', 'completed'], true) && !$reviewing) {
                fail('The milestone is already accepted.');
            }
            DB::update('cs_milestone_items', ['status' => $status, 'remarks' => trim($remarks) ?: $it['remarks'], 'updated_by' => Auth::id(), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $itemId]);
            if ($it['ms_status'] === 'pending' && in_array($status, ['in_progress', 'submitted'], true)) {
                DB::update('cs_milestones', ['status' => 'in_progress'], 'id = :id', ['id' => $it['milestone_id']]);
            }
            self::refreshEligibility((int)$it['milestone_id']);
            Audit::log('deliverable', 'plan', 'milestone', (int)$it['milestone_id'], $it['code'] . ': ' . $it['description'], ['page_key' => 'plan.milestones', 'reason' => CsUi::label($status) . ($remarks !== '' ? ': ' . $remarks : '')]);
        });
    }

    public static function msStatusReasons(array $m): array
    {
        $why = [];
        if (!in_array($m['status'], ['accepted', 'completed'], true)) {
            $why[] = 'it is not accepted yet (now ' . CsUi::label($m['status']) . ')';
        }
        $openReq = (int)DB::value("SELECT COUNT(*) FROM cs_milestone_items WHERE milestone_id = :m AND is_required = 1 AND status NOT IN ('accepted','completed')", ['m' => $m['id']]);
        if ($openReq) {
            $why[] = $openReq . ' required deliverable(s) still open';
        }
        if ((float)$m['completion_pct'] < 100 && $m['measurement_method'] !== 'client_certified') {
            $why[] = 'the approved completion is ' . CsUi::pct((float)$m['completion_pct']);
        }
        if ((int)$m['client_cert_required'] && !$m['client_certified_date']) {
            $why[] = 'the client\'s certification is not recorded';
        }
        return $why;
    }

    // ------------------------------------------------------------------ extensions of time

    public static function eotDecision(int $id, string $status, int $days, string $date): void
    {
        DB::tx(function () use ($id, $status, $days, $date) {
            $e = DB::row('SELECT * FROM cs_eot_requests WHERE id = :id', ['id' => $id]);
            if (!$e || !in_array($e['status'], ['submitted', 'draft'], true)) {
                fail('Only a submitted request is decided.');
            }
            if (!in_array($status, ['granted', 'partially_granted', 'rejected'], true)) {
                fail('Choose the decision.');
            }
            $days = $status === 'rejected' ? 0 : max(0, $days);
            DB::update('cs_eot_requests', ['status' => $status, 'days_granted' => $days, 'decision_date' => valid_date($date) ? $date : today()], 'id = :id', ['id' => $id]);
            if ($days > 0) {
                $p = CsProject::get((int)$e['project_id']);
                $base = $p['revised_end_date'] ?: $p['target_end_date'];
                if ($base) {
                    DB::update('cs_projects', ['revised_end_date' => date('Y-m-d', strtotime($base . ' +' . $days . ' days'))], 'id = :id', ['id' => $e['project_id']]);
                }
                CsProject::flush();
            }
            Audit::log('decide', 'plan', 'eot', $id, $e['ref_no'], ['page_key' => 'plan.eot', 'reason' => CsUi::label($status) . ': ' . $days . ' day(s)']);
        });
    }
}
