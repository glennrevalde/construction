<?php
declare(strict_types=1);

/**
 * The project workspace: everything about one project, answering at a glance what the company needs to know —
 * contract value, work done, pending deliverables, materials, man-hours, machinery, money committed, spent,
 * billed, certified and collected, what is owed both ways, the shared costs it carries, schedule, budget,
 * the projected final cost and profit, and who approved what.
 */
$pageKey = 'proj.view';
$id = (int)g('id', CsProject::current() ?? 0);
if (!$id) {
    cs_go('proj.center');
}
$p = CsProject::requireAccess($id);
$_SESSION['cs_project'] = $id;
$GLOBALS['CS_PROJECT_ID'] = $id;
$tab = (string)g('tab', 'overview');

handle_post(function () use ($id, $p) {
    if (p('act') === 'status') {
        Access::require('proj.projects', 'edit');
        $st = (string)p('status');
        if ($st === 'closed') {
            fail('A project is closed from Close-out, Punch List & Warranty, where the open items are checked first.');
        }
        CsProject::setStatus($id, $st, (string)p('reason'));
        flash('success', 'Project status changed to ' . CsProject::STATUSES[$st] . '.');
        cs_go('proj.view', ['id' => $id]);
    }
});

$s = CsSummary::project($id);
$cvNote = $s['variations'] ? money($s['contract_original']) . ' original + ' . money($s['variations']) . ' variations' : 'No approved variations';
$actions = cs_link('proj.projects', ['id' => $id], 'Set-up', 'btn-outline-secondary', 'gear', 'edit')
    . ' <div class="dropdown d-inline-block"><button class="btn btn-sm btn-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-plus-lg"></i> Record</button><ul class="dropdown-menu dropdown-menu-end">';
foreach ([
    ['site.daily', 'Daily site report', 'clipboard2-check'], ['labor.timesheets', 'Timesheet', 'clock'], ['proc.requests', 'Material request', 'cart-plus'],
    ['proc.materials', 'Material issue', 'box-arrow-right'], ['equip.logs', 'Equipment usage', 'truck-front'], ['pbill.billings', 'Progress billing', 'receipt'],
    ['proj.change_orders', 'Change order', 'arrow-left-right'], ['site.inspections', 'Inspection', 'search'], ['site.safety', 'Safety record', 'cone-striped'],
] as [$pk, $label, $icon]) {
    if (Access::can($pk, 'create')) {
        $actions .= '<li><a class="dropdown-item" href="' . h(url($pk, ['a' => 'new', 'project' => $id])) . '"><i class="bi bi-' . $icon . '"></i> ' . h($label) . '</a></li>';
    }
}
$actions .= '</ul></div>';

layout_start($p['name'], ['module' => 'proj', 'actions' => $actions,
    'sub' => h(CsUi::sub([$p['code'], $p['customer_name'], $p['contract_no'] ? 'Contract ' . $p['contract_no'] : 'No contract', $p['pm_name'] ? 'PM ' . $p['pm_name'] : '', $p['location']])) . ' ' . CsUi::state($p['status'])]);

$tabs = ['overview' => 'Overview', 'cost' => 'Budget & cost', 'schedule' => 'Schedule & milestones', 'billing' => 'Billing & collections',
    'resources' => 'Materials, labor & equipment', 'quality' => 'Quality & safety', 'records' => 'Documents & records'];
echo CsUi::tabs($tabs, $tab, fn($t) => url('proj.view', ['id' => $id, 'tab' => $t]));

// ===================================================================== overview
if ($tab === 'overview') {
    $plannedTxt = $s['planned_pct'] !== null ? 'Planned ' . CsUi::pct($s['planned_pct']) : 'No baseline schedule';
    echo '<div class="cs-proj-head"><div class="cs-proj-progress">' . CsUi::meter($s['physical_pct'], $s['planned_pct'], null, CsUi::pct($s['physical_pct']) . ' done') . '</div>'
        . '<div class="cs-note text-end">' . h($plannedTxt) . ($s['end_date'] ? ', due ' . h(fdate($s['end_date'])) : '') . ($s['forecast_end'] ? ', forecast ' . h(fdate($s['forecast_end'])) : '') . '</div></div>';
    echo '<div class="cs-kpis">'
        . CsUi::kpi('Approved contract value', CsUi::amt($s['contract_current']), h($cvNote), 'key')
        . CsUi::kpi('Work completed', CsUi::amt($s['work_done_value']), CsUi::pct($s['physical_pct']) . ' physical progress', $s['behind'] ? 'warn' : 'ok')
        . CsUi::kpi('Cost to date', CsUi::amt($s['cost_direct']), 'Committed ' . money($s['committed']['total']))
        . CsUi::kpi('Estimated final cost', CsUi::amt($s['eac']), $s['budget_current'] ? 'Budget ' . money($s['budget_current']) : 'No approved budget', $s['over_budget'] ? 'bad' : '')
        . CsUi::kpi('Projected profit', CsUi::amt($s['projected_profit']), CsUi::pct($s['projected_margin']) . ' margin', $s['projected_profit'] < 0 ? 'bad' : 'ok')
        . CsUi::kpi('Collected', CsUi::amt($s['collected']), 'Receivable ' . money($s['ar_open']) . ($s['ar_overdue'] > 0 ? ', <span class="cs-bad">' . money($s['ar_overdue']) . ' overdue</span>' : ''))
        . '</div>';

    $q = [
        ['Current approved contract value', money($s['contract_current']) . ($s['variations_pending'] ? ' <span class="cs-note">(' . money($s['variations_pending']) . ' in pending variations)</span>' : '')],
        ['Work actually completed', CsUi::pct($s['physical_pct']) . ' = ' . money($s['work_done_value'])],
        ['Project deliverables still pending', $s['deliverables_pending'] . ' deliverable(s), ' . $s['milestones_pending'] . ' of ' . $s['milestones'] . ' milestone(s) open' . ($s['milestones_late'] ? ', <span class="cs-bad">' . $s['milestones_late'] . ' late</span>' : '')],
        ['Materials purchased, consumed, wasted, transferred', money($s['mat_purchased']) . ' bought, ' . money($s['mat_issued'] - $s['mat_returned']) . ' issued net, ' . money($s['mat_consumed']) . ' consumed, ' . money($s['mat_wasted']) . ' wasted, ' . money($s['mat_transferred_out']) . ' out / ' . money($s['mat_transferred_in']) . ' in'],
        ['Workers and man-hours used', $s['labor_workers'] . ' workers, ' . number_format($s['labor_hours'], 1) . ' man-hours, ' . money($s['labor_cost'])],
        ['Machinery used, for how long, at what cost', $s['equip_units'] . ' unit(s), ' . number_format($s['equip_hours'], 1) . ' operating hours (' . number_format($s['equip_idle'], 1) . ' idle), ' . money($s['equip_cost'])],
        ['Committed, spent, billed, certified, collected', money($s['committed']['total']) . ' committed, ' . money($s['cost_direct']) . ' spent, ' . money($s['billed_gross']) . ' billed, ' . money($s['certified']) . ' certified, ' . money($s['collected']) . ' collected'],
        ['Liabilities and receivables outstanding', 'Payables ' . money($s['ap_open']) . ', retention payable ' . money($s['retention_payable']) . '; receivable ' . money($s['ar_open']) . ', retention receivable ' . money($s['retention_receivable'])],
        ['Shared company expense attributed', money($s['shared_allocated']) . ' allocated (' . money($s['overhead']) . ' overhead on the books)'],
        ['Ahead of or behind schedule', $s['planned_pct'] === null ? 'No baseline schedule yet' : ($s['physical_pct'] >= $s['planned_pct'] ? '<span class="cs-good">Ahead or on time</span> (' . CsUi::pct($s['physical_pct'] - $s['planned_pct']) . ')' : '<span class="cs-bad">Behind</span> by ' . CsUi::pct($s['planned_pct'] - $s['physical_pct']))],
        ['Within the approved budget', $s['budget_current'] <= 0 ? 'No approved budget yet' : ($s['over_budget'] ? '<span class="cs-bad">Over by ' . money($s['eac'] - $s['budget_current']) . '</span>' : '<span class="cs-good">Within budget</span>, ' . money($s['budget_current'] - $s['eac']) . ' to spare')],
        ['Projected final construction cost', money($s['eac']) . ' (' . money($s['etc']) . ' still to spend)'],
        ['Profit earned, and expected at completion', money($s['gross_profit_to_date']) . ' earned so far; ' . money($s['projected_profit']) . ' expected'],
        ['Approvals', $s['approvals_pending'] . ' document(s) waiting' . (Access::page('appr.history') ? ' <a href="' . h(url('appr.history', ['project' => $id])) . '">see who approved what</a>' : '')],
        ['Complete financial position', 'Revenue ' . money($s['revenue']) . ', direct cost ' . money($s['cost_direct']) . ', operating result ' . money($s['operating_profit_to_date']) . (Access::page('rpt.cs_income') ? ' <a href="' . h(url('rpt.cs_income', ['project_id' => $id, 'dates' => 'all'])) . '">income statement</a>' : '')],
    ];
    echo '<div class="cs-split is-wide-side"><div class="cs-stack"><div class="card"><div class="card-header">The project in figures</div><div class="cs-questions">';
    foreach ($q as [$qq, $aa]) {
        echo '<div><div class="q">' . h($qq) . '</div><div class="a">' . $aa . '</div></div>';
    }
    echo '</div></div>';
    // cost by type
    $rows = [];
    foreach (CsCost::TYPES as $t => $label) {
        if ($t === 'overhead') {
            continue;
        }
        $rows[] = [h($label), money($s['cost'][$t])];
    }
    $rows[] = ['_class' => 'is-total', 'Direct cost', money($s['cost_direct'])];
    $rows[] = ['Allocated overhead', money($s['overhead'])];
    echo cs_table_card('Cost to date by type', ['Type' => '', 'Amount' => 'num'], $rows);
    echo '</div><div class="cs-stack">';
    // status
    if (Access::can('proj.projects', 'edit') && $p['status'] !== 'closed') {
        echo '<div class="card"><div class="card-header">Status</div><div class="card-body"><form method="post" class="row g-2">' . csrf_field() . '<input type="hidden" name="act" value="status">'
            . '<div class="col-7">' . CsUi::select('status', array_diff_key(CsProject::STATUSES, ['closed' => 1]), $p['status'], null, 'data-no-combo') . '</div>'
            . '<div class="col-5"><button class="btn btn-outline-primary btn-sm w-100">Change</button></div>'
            . '<div class="col-12"><input class="form-control form-control-sm" name="reason" placeholder="Reason (kept in the audit trail)"></div></form>'
            . '<p class="cs-note mt-2 mb-0">Closing is done from <a href="' . h(url('proj.closeout', ['project' => $id])) . '">Close-out</a>, which checks the open items first.</p></div></div>';
    }
    // pending approvals of the project
    $reqs = DB::all("SELECT * FROM cs_approval_requests WHERE project_id = :p AND status = 'pending' ORDER BY id DESC LIMIT 8", ['p' => $id]);
    $rows = [];
    foreach ($reqs as $r) {
        $rows[] = ['<a href="' . h(CsDocs::url($r['doc_type'], (int)$r['doc_id'])) . '">' . h(CsDocs::label($r['doc_type']) . ' ' . $r['doc_ref']) . '</a><span class="cs-sub">Step ' . (int)$r['current_step'] . ' of ' . (int)$r['total_steps'] . '</span>', money($r['amount'])];
    }
    echo cs_table_card('Waiting for approval', ['Document' => '', 'Amount' => 'num'], $rows, 'Nothing is waiting.');
    // alerts of this project
    if (class_exists('CsAlerts')) {
        $al = array_filter(CsAlerts::all(), fn($x) => (int)($x['project_id'] ?? 0) === $id);
        echo '<div class="card"><div class="card-header">Alerts</div>' . ($al ? CsAlerts::render(array_slice($al, 0, 8)) : CsUi::empty('No alert for this project.', '', 'check2-circle')) . '</div>';
    }
    echo '<div class="card"><div class="card-header">Details</div><div class="card-body">' . CsUi::facts([
        'Start' => fdate($p['start_date']), 'Actual start' => fdate($p['actual_start_date']), 'Target completion' => fdate($p['target_end_date']), 'Revised completion' => fdate($p['revised_end_date']),
        'Accounting class' => h((string)$p['class_name']), 'Stockyard' => h((string)DB::value('SELECT name FROM sites WHERE id = :s', ['s' => (int)$p['site_id']])),
        'Site engineer' => h((string)DB::value('SELECT full_name FROM users WHERE id = :u', ['u' => (int)$p['engineer_user_id']])),
        'Quantity surveyor' => h((string)DB::value('SELECT full_name FROM users WHERE id = :u', ['u' => (int)$p['qs_user_id']])),
        'Division' => h((string)$p['division']), 'Floor area' => number_format((float)$p['floor_area'], 2),
    ]) . '</div></div>';
    echo '</div></div>';
}

// ===================================================================== budget & cost
if ($tab === 'cost') {
    $fc = CsCost::forecast($id);
    $b = CsCost::budgets($id);
    echo '<div class="cs-kpis">'
        . CsUi::kpi('Original budget', CsUi::amt($s['budget_original']), $b['baseline'] ? 'Baseline v' . (int)$b['baseline']['version_no'] : 'Not approved yet')
        . CsUi::kpi('Current approved budget', CsUi::amt($s['budget_current']), $b['current'] ? h($b['current']['name']) : 'Not approved yet')
        . CsUi::kpi('Actual cost', CsUi::amt($s['cost_direct']), 'Direct costs on the books')
        . CsUi::kpi('Committed, not yet incurred', CsUi::amt($s['committed']['total']), 'POs ' . money($s['committed']['po']) . ', subcontracts ' . money($s['committed']['subcontract']))
        . CsUi::kpi('Cost to complete', CsUi::amt($s['etc']), '')
        . CsUi::kpi('Estimated final cost', CsUi::amt($s['eac']), 'Variance ' . money($s['budget_current'] - $s['eac']), $s['over_budget'] ? 'bad' : 'ok')
        . '</div>';
    $rows = [];
    foreach ($fc['rows'] as $cc => $r) {
        $code = CsCost::code((int)$cc);
        $rows[] = [$code ? '<strong>' . h($code['code']) . '</strong><span class="cs-sub">' . h($code['name']) . '</span>' : 'No cost code yet',
            money($r['budget']) . (abs($r['budget'] - $r['original']) >= 0.5 ? '<span class="cs-sub">Original ' . money($r['original']) . '</span>' : ''), money($r['actual']), money($r['committed']), money($r['etc']), money($r['eac']),
            '<span class="' . ($r['variance'] < -0.5 ? 'cs-bad' : '') . '">' . money($r['variance']) . '</span>',
            $r['budget'] > 0 ? CsUi::meter($r['actual'] / $r['budget'] * 100) : ''];
    }
    $tot = $fc['totals'];
    echo cs_table_card('Budget versus actual by cost code', ['Cost code' => '', 'Budget' => 'num', 'Actual' => 'num', 'Committed' => 'num', 'To complete' => 'num', 'Final (EAC)' => 'num', 'Variance' => 'num', 'Spent of budget' => ''],
        $rows, 'No budget or cost yet.', ' ' . cs_link('plan.budgets', ['project' => $id], 'Budgets', 'btn-outline-secondary btn-sm ms-auto', 'pie-chart'),
        $rows ? '<tfoot><tr><td>Total</td><td class="num">' . money($tot['budget']) . (abs($tot['budget'] - $tot['original']) >= 0.5 ? '<span class="cs-sub">Original ' . money($tot['original']) . '</span>' : '') . '</td><td class="num">' . money($tot['actual']) . '</td><td class="num">' . money($tot['committed'])
            . '</td><td class="num">' . money($tot['etc']) . '</td><td class="num">' . money($tot['eac']) . '</td><td class="num">' . money($tot['variance']) . '</td><td></td></tr></tfoot>' : '');
    $unassigned = (float)DB::value("SELECT COALESCE(SUM(j.debit - j.credit),0) FROM journal_lines j JOIN accounts a ON a.id = j.account_id
                                     WHERE j.class_id = :c AND a.category = 'Expense' AND j.cost_code_id IS NULL AND COALESCE(a.cs_cost_category, '') <> 'overhead' AND a.account_type = 'Cost of Goods Sold'", ['c' => $p['class_id']]);
    if (abs($unassigned) >= 0.5) {
        echo '<div class="alert alert-warning mt-3">' . money($unassigned) . ' of this project\'s costs has no cost code yet (entered in accounting with the project\'s class). '
            . (Access::page('cost.assign') ? '<a href="' . h(url('cost.assign', ['project' => $id])) . '">Assign cost codes</a> so they count against the right budget line.' : '') . '</div>';
    }
    // open purchase orders
    $pos = DB::all("SELECT t.*, v.name AS vendor_name FROM transactions t LEFT JOIN vendors v ON v.id = t.vendor_id
                     WHERE t.txn_type = 'purchase_order' AND t.is_voided = 0 AND t.status NOT IN ('closed') AND t.class_id = :c ORDER BY t.txn_date DESC LIMIT 20", ['c' => $p['class_id']]);
    $rows = [];
    foreach ($pos as $po) {
        $rows[] = [Txn::link($po), h(fdate($po['txn_date'])), h((string)$po['vendor_name']), money($po['total']), CsUi::state($po['status'])];
    }
    echo '<div class="mt-3">' . cs_table_card('Open purchase orders', ['PO' => '', 'Date' => '', 'Supplier' => '', 'Amount' => 'num', 'Status' => ''], $rows, 'No open purchase orders.') . '</div>';
}

// ===================================================================== schedule
if ($tab === 'schedule') {
    $acts = DB::all('SELECT * FROM cs_activities WHERE project_id = :p ORDER BY sort_order, wbs_code', ['p' => $id]);
    $rows = [];
    foreach ($acts as $x) {
        $late = $x['status'] !== 'completed' && $x['planned_finish'] && $x['planned_finish'] < today();
        $rows[] = [h($x['wbs_code']), h($x['name']) . ((int)$x['is_critical'] ? ' <span class="cs-state is-bad">Critical</span>' : ''), h(fdate($x['planned_start'])), '<span class="' . ($late ? 'cs-bad' : '') . '">' . h(fdate($x['planned_finish'])) . '</span>',
            number_format((float)$x['weight_pct'], 2) . '%', CsUi::meter((float)$x['progress_pct'])];
    }
    echo cs_table_card('Activities', ['WBS' => '', 'Activity' => '', 'Start' => '', 'Finish' => '', 'Weight' => 'num', 'Progress' => ''], $rows, 'No schedule yet.', ' ' . cs_link('plan.schedule', ['project' => $id], 'Gantt chart', 'btn-outline-secondary btn-sm ms-auto', 'bar-chart-steps'));
    $ms = DB::all('SELECT * FROM cs_milestones WHERE project_id = :p ORDER BY sort_order, scheduled_date', ['p' => $id]);
    $rows = [];
    foreach ($ms as $m) {
        $rows[] = ['<a href="' . h(url('plan.milestones', ['a' => 'view', 'id' => $m['id']])) . '">' . h($m['code'] . ' ' . $m['name']) . '</a>', h(fdate($m['scheduled_date'])), money($m['contract_value']),
            CsUi::pct((float)$m['completion_pct']), CsUi::state($m['status']), (int)$m['billing_eligible'] ? '<span class="cs-good">Billable</span>' : '<span class="text-muted">Not yet</span>'];
    }
    echo '<div class="mt-3">' . cs_table_card('Milestones and deliverables', ['Milestone' => '', 'Due' => '', 'Value' => 'num', 'Approved %' => 'num', 'Status' => '', 'Billing' => ''], $rows, 'No milestones yet.') . '</div>';
}

// ===================================================================== billing
if ($tab === 'billing') {
    echo '<div class="cs-kpis">'
        . CsUi::kpi('Billed (gross)', CsUi::amt($s['billed_gross']), $s['contract_current'] > 0 ? CsUi::pct($s['billed_gross'] / $s['contract_current'] * 100) . ' of the contract' : '')
        . CsUi::kpi('Certified by the client', CsUi::amt($s['certified']), '')
        . CsUi::kpi('Revenue earned', CsUi::amt($s['contract_revenue']), 'Recognized on the books')
        . CsUi::kpi('Invoiced', CsUi::amt($s['invoiced']), 'Including VAT')
        . CsUi::kpi('Collected', CsUi::amt($s['collected']), 'Open ' . money($s['ar_open']), $s['ar_overdue'] > 0 ? 'warn' : '')
        . CsUi::kpi('Retention receivable', CsUi::amt($s['retention_receivable']), 'Unrecouped advance ' . money($s['advances_unrecouped']))
        . '</div>';
    $bills = DB::all('SELECT * FROM cs_billings WHERE project_id = :p ORDER BY billing_date DESC, id DESC', ['p' => $id]);
    $rows = [];
    foreach ($bills as $b) {
        $rows[] = ['<a href="' . h(url('pbill.billings', ['a' => 'view', 'id' => $b['id']])) . '">' . h($b['billing_no']) . '</a><span class="cs-sub">' . h(CsUi::label($b['billing_type'])) . '</span>',
            h(fdate($b['billing_date'])), money($b['this_gross']), money($b['retention_amount']), money($b['amount_applied']), $b['certified_amount'] !== null ? money($b['certified_amount']) : '', CsUi::txnLink($b['invoice_txn_id'] ? (int)$b['invoice_txn_id'] : null), CsUi::state($b['status'])];
    }
    echo cs_table_card('Progress billings', ['Billing' => '', 'Date' => '', 'This period' => 'num', 'Retention' => 'num', 'Applied for' => 'num', 'Certified' => 'num', 'Invoice' => '', 'Status' => ''], $rows, 'No billing yet.',
        ' ' . cs_link('pbill.billings', ['a' => 'new', 'project' => $id], 'New billing', 'btn-primary btn-sm ms-auto', 'plus-lg', 'create'));
    $inv = DB::all("SELECT t.* FROM transactions t WHERE t.txn_type IN ('invoice','statement_charge','credit_memo') AND t.is_voided = 0 AND t.class_id = :c ORDER BY t.txn_date DESC LIMIT 30", ['c' => $p['class_id']]);
    $rows = [];
    foreach ($inv as $t) {
        $applied = $t['txn_type'] === 'credit_memo' ? 0 : Balances::applied((int)$t['id']);
        $rows[] = [Txn::link($t), h(fdate($t['txn_date'])), h(fdate($t['due_date'])), money($t['total']), money($applied), money((float)$t['total'] - $applied), CsUi::state($t['status'])];
    }
    echo '<div class="mt-3">' . cs_table_card('Invoices and collections', ['Document' => '', 'Date' => '', 'Due' => '', 'Amount' => 'num', 'Collected' => 'num', 'Open' => 'num', 'Status' => ''], $rows, 'No invoice yet.') . '</div>';
}

// ===================================================================== resources
if ($tab === 'resources') {
    $mats = DB::all("SELECT i.code, i.name, i.unit,
                            SUM(CASE WHEN d.doc_type = 'issue' AND d.project_id = :p THEN l.qty ELSE 0 END) AS issued,
                            SUM(CASE WHEN d.doc_type = 'return' AND d.project_id = :p2 THEN l.qty ELSE 0 END) AS returned,
                            SUM(CASE WHEN d.doc_type = 'consume' AND d.project_id = :p3 THEN l.qty ELSE 0 END) AS consumed,
                            SUM(CASE WHEN d.doc_type = 'consume' AND d.project_id = :p4 THEN l.qty_wasted ELSE 0 END) AS wasted,
                            SUM(CASE WHEN d.doc_type = 'transfer' AND d.project_id = :p5 THEN l.qty ELSE 0 END) AS t_out,
                            SUM(CASE WHEN d.doc_type = 'transfer' AND d.to_project_id = :p6 THEN l.qty ELSE 0 END) AS t_in,
                            SUM(CASE WHEN d.project_id = :p7 AND d.doc_type IN ('issue','consume') THEN l.amount WHEN d.project_id = :p8 AND d.doc_type IN ('return','transfer') THEN -l.amount WHEN d.to_project_id = :p9 THEN l.amount ELSE 0 END) AS cost
                       FROM cs_material_doc_lines l JOIN cs_material_docs d ON d.id = l.doc_id JOIN items i ON i.id = l.item_id
                      WHERE d.status = 'posted' AND (d.project_id = :p10 OR d.to_project_id = :p11) GROUP BY i.id, i.code, i.name, i.unit ORDER BY i.code",
        ['p' => $id, 'p2' => $id, 'p3' => $id, 'p4' => $id, 'p5' => $id, 'p6' => $id, 'p7' => $id, 'p8' => $id, 'p9' => $id, 'p10' => $id, 'p11' => $id]);
    $rows = [];
    foreach ($mats as $m) {
        $rows[] = [h($m['code']) . '<span class="cs-sub">' . h($m['name']) . '</span>', h((string)$m['unit']), qty($m['issued']), qty($m['returned']), qty($m['t_in']), qty($m['t_out']), qty($m['consumed']), qty($m['wasted']), money($m['cost'])];
    }
    echo cs_table_card('Materials', ['Item' => '', 'Unit' => '', 'Issued' => 'num', 'Returned' => 'num', 'Transferred in' => 'num', 'Transferred out' => 'num', 'Consumed' => 'num', 'Wasted' => 'num', 'Cost charged' => 'num'], $rows, 'No material moved to or from this project yet.',
        ' ' . cs_link('proc.stock', ['project' => $id], 'Materials ledger', 'btn-outline-secondary btn-sm ms-auto', 'boxes'));
    $labor = DB::all("SELECT w.worker_no, w.full_name, tr.name AS trade, SUM(l.regular_hours) AS reg, SUM(l.ot_hours) AS ot, COUNT(DISTINCT l.work_date) AS days, SUM(l.labor_cost + l.burden_cost + l.allowance) AS cost
                        FROM cs_timesheet_lines l JOIN cs_timesheets t ON t.id = l.timesheet_id JOIN cs_workers w ON w.id = l.worker_id LEFT JOIN cs_trades tr ON tr.id = w.trade_id
                       WHERE t.project_id = :p AND t.status IN ('approved','posted') GROUP BY w.id, w.worker_no, w.full_name, tr.name ORDER BY cost DESC LIMIT 40", ['p' => $id]);
    $rows = [];
    foreach ($labor as $l) {
        $rows[] = [h($l['worker_no'] . ' ' . $l['full_name']), h((string)$l['trade']), (int)$l['days'], number_format((float)$l['reg'], 1), number_format((float)$l['ot'], 1), money($l['cost'])];
    }
    echo '<div class="mt-3">' . cs_table_card('Workforce (approved timesheets)', ['Worker' => '', 'Trade' => '', 'Days' => 'num', 'Regular hours' => 'num', 'Overtime' => 'num', 'Labor cost' => 'num'], $rows, 'No approved timesheet yet.') . '</div>';
    $eq = DB::all("SELECT e.code, e.name, SUM(l.operating_hours) AS op, SUM(l.idle_hours) AS idle, SUM(l.breakdown_hours) AS bd, SUM(l.fuel_liters) AS fuel, SUM(l.charge_amount) AS cost
                     FROM cs_equipment_logs l JOIN cs_equipment e ON e.id = l.equipment_id WHERE l.project_id = :p AND l.status IN ('approved','posted') GROUP BY e.id, e.code, e.name ORDER BY e.code", ['p' => $id]);
    $rows = [];
    foreach ($eq as $e) {
        $rows[] = [h($e['code'] . ' ' . $e['name']), number_format((float)$e['op'], 1), number_format((float)$e['idle'], 1), number_format((float)$e['bd'], 1), number_format((float)$e['fuel'], 1), money($e['cost'])];
    }
    echo '<div class="mt-3">' . cs_table_card('Equipment', ['Equipment' => '', 'Operating hours' => 'num', 'Idle' => 'num', 'Breakdown' => 'num', 'Fuel (L)' => 'num', 'Charged' => 'num'], $rows, 'No equipment logged on this project yet.') . '</div>';
}

// ===================================================================== quality & safety
if ($tab === 'quality') {
    $rows = [];
    foreach (DB::all('SELECT * FROM cs_inspections WHERE project_id = :p ORDER BY inspection_date DESC LIMIT 20', ['p' => $id]) as $x) {
        $rows[] = ['<a href="' . h(url('site.inspections', ['a' => 'view', 'id' => $x['id']])) . '">' . h($x['insp_no']) . '</a> ' . h($x['title']), h(fdate($x['inspection_date'])), h(CsUi::label($x['kind'])), CsUi::state($x['result'])];
    }
    echo cs_table_card('Inspections', ['Inspection' => '', 'Date' => '', 'Kind' => '', 'Result' => ''], $rows, 'No inspections yet.');
    $rows = [];
    foreach (DB::all('SELECT * FROM cs_ncrs WHERE project_id = :p ORDER BY raised_date DESC LIMIT 20', ['p' => $id]) as $x) {
        $rows[] = ['<a href="' . h(url('site.ncr', ['a' => 'view', 'id' => $x['id']])) . '">' . h($x['ncr_no']) . '</a> ' . h($x['title']), h(fdate($x['raised_date'])), money($x['rework_cost']), CsUi::state($x['status'])];
    }
    echo '<div class="mt-3">' . cs_table_card('Nonconformance reports', ['NCR' => '', 'Raised' => '', 'Rework cost' => 'num', 'Status' => ''], $rows, 'No nonconformance.') . '</div>';
    $rows = [];
    foreach (DB::all('SELECT * FROM cs_safety_records WHERE project_id = :p ORDER BY record_date DESC LIMIT 20', ['p' => $id]) as $x) {
        $rows[] = ['<a href="' . h(url('site.safety', ['a' => 'view', 'id' => $x['id']])) . '">' . h($x['ref_no']) . '</a> ' . h($x['title']), h(fdate($x['record_date'])), h(CsUi::label($x['kind'])), h(CsUi::label((string)$x['severity'])), CsUi::state($x['status'])];
    }
    echo '<div class="mt-3">' . cs_table_card('Safety records', ['Record' => '', 'Date' => '', 'Kind' => '', 'Severity' => '', 'Status' => ''], $rows, 'No safety record.') . '</div>';
}

// ===================================================================== documents & records
if ($tab === 'records') {
    $rows = [];
    foreach (DB::all("SELECT * FROM cs_documents WHERE project_id = :p AND status = 'active' ORDER BY category, doc_no LIMIT 50", ['p' => $id]) as $d) {
        $rows[] = ['<a href="' . h(url('proj.documents', ['a' => 'view', 'id' => $d['id']])) . '">' . h($d['doc_no']) . '</a> ' . h($d['title']), h(CsUi::label($d['category'])), h(CsUi::label((string)$d['discipline'])), h((string)$d['current_rev'])];
    }
    echo cs_table_card('Documents and drawings', ['Document' => '', 'Category' => '', 'Discipline' => '', 'Current revision' => ''], $rows, 'No documents yet.', ' ' . cs_link('proj.documents', ['project' => $id], 'All documents', 'btn-outline-secondary btn-sm ms-auto'));
    $rows = [];
    foreach (DB::all('SELECT * FROM cs_rfis WHERE project_id = :p ORDER BY id DESC LIMIT 20', ['p' => $id]) as $r) {
        $rows[] = ['<a href="' . h(url('proj.rfis', ['a' => 'view', 'id' => $r['id']])) . '">' . h($r['ref_no']) . '</a> ' . h($r['subject']), h(CsUi::label($r['kind'])), h(fdate($r['date_due'])), CsUi::state($r['status'])];
    }
    echo '<div class="mt-3">' . cs_table_card('RFIs, submittals and transmittals', ['Reference' => '', 'Kind' => '', 'Due' => '', 'Status' => ''], $rows, 'None yet.') . '</div>';
    $rows = [];
    foreach (DB::all('SELECT * FROM cs_project_logs WHERE project_id = :p ORDER BY log_date DESC, id DESC LIMIT 25', ['p' => $id]) as $l) {
        $rows[] = [h(fdate($l['log_date'])), h(CsUi::label($l['kind'])), '<a href="' . h(url('proj.logs', ['a' => 'view', 'id' => $l['id']])) . '">' . h($l['title']) . '</a>', CsUi::state($l['status'])];
    }
    echo '<div class="mt-3">' . cs_table_card('Risks, issues, meetings and correspondence', ['Date' => '', 'Kind' => '', 'Title' => '', 'Status' => ''], $rows, 'Nothing logged yet.') . '</div>';
}
layout_end();
