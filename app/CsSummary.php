<?php
declare(strict_types=1);

/**
 * The figures of a project (or of every project) in one place: contract value, progress, budget, cost, commitments,
 * forecast, billing, collections, receivables, retention and profit. The dashboards, the project workspace and the
 * project reports all read them from here, so they always agree.
 */
final class CsSummary
{
    private static array $memo = [];

    public static function flush(): void
    {
        self::$memo = [];
    }

    /** Figures for many projects at once: [projectId => figures] */
    public static function portfolio(?array $ids = null, ?string $asOf = null): array
    {
        if ($ids === null) {
            [$f, $p] = CsProject::filter('id');
            $ids = array_map('intval', DB::column("SELECT id FROM cs_projects WHERE 1 = 1 $f ORDER BY code", $p));
        }
        $out = [];
        foreach ($ids as $pid) {
            $out[(int)$pid] = self::project((int)$pid, $asOf);
        }
        return $out;
    }

    public static function project(int $id, ?string $asOf = null): array
    {
        $key = $id . ':' . ($asOf ?? '');
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }
        $p = CsProject::get($id);
        if (!$p) {
            return [];
        }
        $asOf ??= today();
        $cid = (int)$p['class_id'];
        $cv = CsProject::contractValue($id);
        $physical = CsProject::physicalPct($id);
        $planned = CsProject::plannedPct($id, $asOf);
        $budgets = CsCost::budgets($id);
        $cost = CsCost::costByType([$id], $asOf)[$id] ?? array_fill_keys(array_keys(CsCost::TYPES), 0.0) + ['direct' => 0.0, 'total' => 0.0];
        $commit = CsCost::commitments([$id])[$id] ?? ['po' => 0, 'subcontract' => 0, 'total' => 0];
        $fc = CsCost::forecast($id, $asOf);
        $budgetCurrent = (float)($budgets['current']['total_amount'] ?? 0);
        $budgetOriginal = (float)($budgets['baseline']['total_amount'] ?? 0);
        // with no budget lines the forecast falls back on spent + committed
        $eac = $fc['rows'] ? (float)$fc['totals']['eac'] : r2($cost['direct'] + $commit['total']);
        $eac = max($eac, (float)$cost['direct'] + (float)$commit['total']);
        if ($eac <= 0) {
            // not started and no approved budget yet: the budget being prepared, or else the approved estimate, is the best guess
            $eac = (float)(DB::value("SELECT total_amount FROM cs_budgets WHERE project_id = :p AND status IN ('draft','for_approval','returned') ORDER BY id DESC LIMIT 1", ['p' => $id])
                ?: DB::value("SELECT total_cost + contingency_amt FROM cs_estimates WHERE project_id = :p AND status = 'approved' ORDER BY version_no DESC LIMIT 1", ['p' => $id]) ?: 0);
        }
        $etc = r2(max(0, $eac - (float)$cost['direct']));
        $revenue = (float)(CsCost::revenue([$id], $asOf)[$id] ?? 0);
        $contractRevenue = (float)(CsCost::contractRevenue([$id], $asOf)[$id] ?? 0);

        // billing
        $bill = DB::row("SELECT COALESCE(SUM(CASE WHEN status IN ('invoiced') AND billing_type NOT IN ('retention_release','advance') THEN this_gross + materials_stored ELSE 0 END),0) AS billed,
                                COALESCE(SUM(CASE WHEN status IN ('certified','invoiced') AND billing_type NOT IN ('retention_release','advance') THEN this_gross + materials_stored ELSE 0 END),0) AS certified_gross,
                                COALESCE(SUM(CASE WHEN status IN ('certified','invoiced') THEN certified_amount ELSE 0 END),0) AS certified,
                                COALESCE(SUM(CASE WHEN status IN ('approved','submitted','certified','invoiced') THEN amount_applied ELSE 0 END),0) AS applied,
                                COALESCE(SUM(CASE WHEN status = 'invoiced' THEN retention_amount ELSE 0 END),0) AS retained,
                                COALESCE(SUM(CASE WHEN status IN ('draft','for_approval','approved','submitted','returned') THEN 1 ELSE 0 END),0) AS open_bills
                           FROM cs_billings WHERE project_id = :p AND billing_date <= :d", ['p' => $id, 'd' => $asOf]) ?: [];
        // receivables of the project: every invoice with its class, from the construction module or typed in accounting
        $inv = DB::all("SELECT t.* FROM transactions t WHERE t.txn_type IN ('invoice','statement_charge') AND t.is_voided = 0 AND t.is_pending = 0
                          AND t.class_id = :c AND t.txn_date <= :d", ['c' => $cid, 'd' => $asOf]);
        $invoiced = 0.0;
        $collected = 0.0;
        $overdue = 0.0;
        foreach ($inv as $t) {
            $invoiced += (float)$t['total'];
            $applied = Balances::applied((int)$t['id'], $asOf);
            $collected += $applied;
            $open = (float)$t['total'] - $applied;
            if ($open > 0.004 && $t['due_date'] && $t['due_date'] < $asOf) {
                $overdue += $open;
            }
        }
        $cashIn = (float)DB::value("SELECT COALESCE(SUM(j.debit - j.credit),0) FROM journal_lines j JOIN accounts a ON a.id = j.account_id
                                     WHERE j.class_id = :c AND a.account_type = 'Bank' AND j.txn_date <= :d AND j.debit > 0", ['c' => $cid, 'd' => $asOf]);
        $retentionRec = (float)(CsCost::balance('cs_retention_receivable', [$id], $asOf)[$id] ?? 0);
        $advances = -(float)(CsCost::balance('cs_client_advances', [$id], $asOf)[$id] ?? 0);
        $retentionPay = -(float)(CsCost::balance('cs_retention_payable', [$id], $asOf)[$id] ?? 0);
        $apOpen = 0.0;
        foreach (DB::all("SELECT * FROM transactions WHERE txn_type = 'bill' AND is_voided = 0 AND class_id = :c AND status IN ('open','partial')", ['c' => $cid]) as $b) {
            $apOpen += Balances::open($b);
        }
        $shared = (float)DB::value("SELECT COALESCE(SUM(l.amount),0) FROM cs_allocation_lines l JOIN cs_allocations a ON a.id = l.allocation_id
                                     WHERE l.project_id = :p AND a.status = 'posted' AND a.alloc_date <= :d", ['p' => $id, 'd' => $asOf]);
        // resources
        $labor = DB::row("SELECT COALESCE(SUM(l.regular_hours + l.ot_hours),0) AS hours, COUNT(DISTINCT l.worker_id) AS workers, COALESCE(SUM(l.labor_cost + l.burden_cost + l.allowance),0) AS cost
                            FROM cs_timesheet_lines l JOIN cs_timesheets t ON t.id = l.timesheet_id
                           WHERE t.project_id = :p AND t.status IN ('approved','posted') AND t.work_date <= :d", ['p' => $id, 'd' => $asOf]) ?: [];
        $equip = DB::row("SELECT COALESCE(SUM(operating_hours),0) AS hours, COALESCE(SUM(idle_hours),0) AS idle, COALESCE(SUM(charge_amount),0) AS cost, COUNT(DISTINCT equipment_id) AS units
                            FROM cs_equipment_logs WHERE project_id = :p AND status IN ('approved','posted') AND log_date <= :d", ['p' => $id, 'd' => $asOf]) ?: [];
        $mat = DB::row("SELECT COALESCE(SUM(CASE WHEN d.doc_type = 'issue' THEN l.amount ELSE 0 END),0) AS issued,
                               COALESCE(SUM(CASE WHEN d.doc_type = 'return' THEN l.amount ELSE 0 END),0) AS returned,
                               COALESCE(SUM(CASE WHEN d.doc_type = 'consume' THEN l.amount ELSE 0 END),0) AS consumed,
                               COALESCE(SUM(CASE WHEN d.doc_type = 'consume' THEN l.qty_wasted * l.unit_cost ELSE 0 END),0) AS wasted,
                               COALESCE(SUM(CASE WHEN d.doc_type = 'transfer' THEN l.amount ELSE 0 END),0) AS transferred_out
                          FROM cs_material_doc_lines l JOIN cs_material_docs d ON d.id = l.doc_id
                         WHERE d.project_id = :p AND d.status = 'posted' AND d.doc_date <= :d", ['p' => $id, 'd' => $asOf]) ?: [];
        $matIn = (float)DB::value("SELECT COALESCE(SUM(l.amount),0) FROM cs_material_doc_lines l JOIN cs_material_docs d ON d.id = l.doc_id
                                    WHERE d.to_project_id = :p AND d.doc_type = 'transfer' AND d.status = 'posted' AND d.doc_date <= :d", ['p' => $id, 'd' => $asOf]);
        $purchased = (float)DB::value("SELECT COALESCE(SUM(l.amount),0) FROM transaction_lines l JOIN transactions t ON t.id = l.txn_id
                                        WHERE t.txn_type IN ('bill','item_receipt','check') AND t.is_voided = 0 AND COALESCE(l.class_id, t.class_id) = :c AND l.item_id IS NOT NULL AND t.txn_date <= :d", ['c' => $cid, 'd' => $asOf]);
        $ms = DB::row("SELECT COUNT(*) AS n, SUM(CASE WHEN status NOT IN ('accepted','completed') THEN 1 ELSE 0 END) AS pending,
                              SUM(CASE WHEN status NOT IN ('accepted','completed') AND scheduled_date < :d THEN 1 ELSE 0 END) AS late
                         FROM cs_milestones WHERE project_id = :p", ['p' => $id, 'd' => $asOf]) ?: [];
        $pendingDeliv = (int)DB::value("SELECT COUNT(*) FROM cs_milestone_items i JOIN cs_milestones m ON m.id = i.milestone_id
                                          WHERE m.project_id = :p AND i.is_required = 1 AND i.status NOT IN ('accepted','completed')", ['p' => $id]);
        $pendingAppr = (int)DB::value("SELECT COUNT(*) FROM cs_approval_requests WHERE project_id = :p AND status = 'pending'", ['p' => $id]);

        // schedule
        $end = $p['revised_end_date'] ?: $p['target_end_date'];
        $forecastEnd = null;
        if ($p['start_date'] && $physical > 0 && $physical < 100) {
            $startT = strtotime((string)($p['actual_start_date'] ?: $p['start_date']));
            $elapsed = max(1, (strtotime($asOf) - $startT) / 86400);
            $forecastEnd = date('Y-m-d', (int)($startT + $elapsed / ($physical / 100) * 86400));
        } elseif ($physical >= 100) {
            $forecastEnd = $p['actual_end_date'] ?: $asOf;
        }
        $behind = ($planned !== null && $physical + 5 < $planned) || ($forecastEnd && $end && $forecastEnd > $end && !in_array($p['status'], ['completed', 'closed', 'under_warranty'], true));
        $earnedValue = r2($physical / 100 * $cv['current']);
        $projectedProfit = r2($cv['current'] - $eac - (float)$cost['overhead']);
        $grossToDate = r2($contractRevenue - (float)$cost['direct']);
        $overBudget = $budgetCurrent > 0 && $eac > $budgetCurrent + 0.5;

        return self::$memo[$key] = [
            'project' => $p, 'as_of' => $asOf,
            'contract_original' => $cv['original'], 'variations' => $cv['variations'], 'contract_current' => $cv['current'], 'variations_pending' => $cv['pending'],
            'physical_pct' => $physical, 'planned_pct' => $planned, 'work_done_value' => $earnedValue,
            'budget_original' => r2($budgetOriginal), 'budget_current' => r2($budgetCurrent),
            'cost' => $cost, 'cost_direct' => (float)$cost['direct'], 'overhead' => (float)$cost['overhead'],
            'committed' => $commit, 'etc' => $etc, 'eac' => r2($eac), 'projected_profit' => $projectedProfit,
            'projected_margin' => $cv['current'] > 0 ? round($projectedProfit / $cv['current'] * 100, 2) : 0.0,
            'revenue' => r2($revenue), 'contract_revenue' => r2($contractRevenue), 'gross_profit_to_date' => $grossToDate,
            'operating_profit_to_date' => r2($revenue - (float)$cost['total']),
            'billed_gross' => r2($bill['billed'] ?? 0), 'certified_gross' => r2($bill['certified_gross'] ?? 0), 'certified' => r2($bill['certified'] ?? 0),
            'applied' => r2($bill['applied'] ?? 0), 'open_billings' => (int)($bill['open_bills'] ?? 0),
            'invoiced' => r2($invoiced), 'collected' => r2($collected), 'ar_open' => r2($invoiced - $collected), 'ar_overdue' => r2($overdue), 'cash_in' => r2($cashIn),
            'retention_receivable' => r2($retentionRec), 'advances_unrecouped' => r2($advances), 'retention_payable' => r2($retentionPay), 'ap_open' => r2($apOpen),
            'shared_allocated' => r2($shared),
            'labor_hours' => r2($labor['hours'] ?? 0), 'labor_workers' => (int)($labor['workers'] ?? 0), 'labor_cost' => r2($labor['cost'] ?? 0),
            'equip_hours' => r2($equip['hours'] ?? 0), 'equip_idle' => r2($equip['idle'] ?? 0), 'equip_cost' => r2($equip['cost'] ?? 0), 'equip_units' => (int)($equip['units'] ?? 0),
            'mat_purchased' => r2($purchased), 'mat_issued' => r2($mat['issued'] ?? 0), 'mat_returned' => r2($mat['returned'] ?? 0), 'mat_consumed' => r2($mat['consumed'] ?? 0),
            'mat_wasted' => r2($mat['wasted'] ?? 0), 'mat_transferred_out' => r2($mat['transferred_out'] ?? 0), 'mat_transferred_in' => r2($matIn),
            'milestones' => (int)($ms['n'] ?? 0), 'milestones_pending' => (int)($ms['pending'] ?? 0), 'milestones_late' => (int)($ms['late'] ?? 0),
            'deliverables_pending' => $pendingDeliv, 'approvals_pending' => $pendingAppr,
            'end_date' => $end, 'forecast_end' => $forecastEnd, 'behind' => $behind, 'over_budget' => $overBudget,
            'cpi' => (float)$cost['direct'] > 0 && $budgetCurrent > 0 ? round(($physical / 100 * $budgetCurrent) / (float)$cost['direct'], 2) : null,
        ];
    }
}
