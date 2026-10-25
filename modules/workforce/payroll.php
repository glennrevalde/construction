<?php
declare(strict_types=1);

/**
 * Payroll runs: the approved timesheets of a pay period, cash advances deducted, other deductions, the net pay; once
 * approved, it clears the accrued payroll of each project and leaves the net in Wages Payable to be paid from accounting.
 */
$pageKey = 'labor.payroll';
$a = (string)g('a', 'list');
$id = gint('id');
$r = $id ? DB::row('SELECT * FROM cs_payroll_runs WHERE id = :id', ['id' => $id]) : null;

handle_post(function () use ($pageKey, $id, $r) {
    if ($r && CsApproval::handlePost('payroll', (int)$id, function () use ($r) {
        if (!in_array($r['status'], ['draft', 'rejected'], true)) {
            fail('Only a draft payroll run is submitted.');
        }
        CsApproval::submit('payroll', (int)$r['id'], $r['run_no'] . ' ' . fdate($r['period_from']) . ' to ' . fdate($r['period_to']), null, (float)$r['total_gross']);
    })) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    $act = (string)p('act');
    if ($act === 'build') {
        Access::require($pageKey, 'create');
        $nid = CsLabor::buildPayroll((string)p('period_from'), (string)p('period_to'), (string)p('pay_date'), (string)p('notes'));
        flash('success', 'Payroll run prepared. Check the deductions, then submit it for approval.');
        cs_go($pageKey, ['a' => 'view', 'id' => $nid]);
    }
    if ($r && $act === 'deductions') {
        Access::require($pageKey, 'edit');
        CsLabor::setDeductions((int)$id, (array)($_POST['other'] ?? []));
        flash('success', 'Deductions saved.');
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if ($r && $act === 'remove') {
        Access::require($pageKey, 'delete');
        CsLabor::deleteDraftRun((int)$id, (string)p('reason'));
        flash('success', 'Payroll run removed; its timesheets are free for another run.');
        cs_go($pageKey);
    }
});

if ($a === 'view' && $r) {
    $lines = DB::all('SELECT l.*, w.full_name, w.worker_no, t.name AS trade FROM cs_payroll_lines l JOIN cs_workers w ON w.id = l.worker_id LEFT JOIN cs_trades t ON t.id = w.trade_id WHERE l.run_id = :r ORDER BY w.full_name', ['r' => $id]);
    $draft = in_array($r['status'], ['draft', 'rejected'], true) && Access::can($pageKey, 'edit');
    $byProject = DB::all('SELECT p.code, p.name, COUNT(DISTINCT t.id) AS sheets, SUM(l.labor_cost + l.allowance) AS pay, SUM(l.regular_hours + l.ot_hours) AS hours
                            FROM cs_timesheets t JOIN cs_projects p ON p.id = t.project_id JOIN cs_timesheet_lines l ON l.timesheet_id = t.id WHERE t.payroll_run_id = :r GROUP BY p.code, p.name ORDER BY p.code', ['r' => $id]);
    if (g('print') === '1') {
        layout_start('Payslips ' . $r['run_no'], ['module' => 'labor', 'no_title' => true]);
        echo '<div class="no-print mb-3"><button class="btn btn-primary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button> <a class="btn btn-outline-secondary btn-sm" href="' . h(url($pageKey, ['a' => 'view', 'id' => $id])) . '">Back</a></div>';
        foreach ($lines as $l) {
            echo '<div class="cs-doc mb-3" style="break-inside:avoid"><div class="cs-doc-head"><div><h2>Payslip</h2><div>' . h(Settings::get('company_name')) . '</div></div><div class="text-end">' . h($r['run_no']) . '<br>'
                . h(fdate($r['period_from']) . ' to ' . fdate($r['period_to'])) . '</div></div>' . CsUi::facts(['Worker' => h($l['full_name'] . ' (' . $l['worker_no'] . ')'), 'Trade' => h((string)$l['trade']),
                    'Days worked' => number_format((float)$l['days_worked'], 2), 'Hours' => number_format((float)$l['regular_hours'], 2) . ' regular, ' . number_format((float)$l['ot_hours'], 2) . ' overtime',
                    'Basic and overtime pay' => money($l['gross_pay']), 'Allowances' => money($l['allowances']), 'Cash advance deducted' => money($l['advance_deduction']), 'Other deductions' => money($l['other_deductions']),
                    'Net pay' => '<strong>' . money($l['net_pay']) . '</strong>']) . '<div class="cs-sign"><div>Received by</div><div>Date</div></div></div>';
        }
        layout_end();
        return;
    }
    layout_start($r['run_no'] . ' Payroll', ['module' => 'labor', 'sub' => h(fdate($r['period_from']) . ' to ' . fdate($r['period_to']) . ($r['pay_date'] ? ', paid ' . fdate($r['pay_date']) : '')) . ' ' . CsUi::state($r['status']),
        'actions' => cs_link($pageKey, ['a' => 'view', 'id' => $id, 'print' => 1], 'Payslips', 'btn-outline-secondary', 'printer')]);
    echo '<div class="cs-kpis">' . CsUi::kpi('Gross pay', CsUi::amt($r['total_gross']), count($lines) . ' workers', 'key') . CsUi::kpi('Deductions', CsUi::amt($r['total_deductions']), 'Advances and others')
        . CsUi::kpi('Net pay', CsUi::amt($r['total_net']), 'To be paid from Wages Payable') . CsUi::kpi('Employer burden', CsUi::amt($r['total_burden']), 'Already charged to the projects') . '</div>';
    echo '<div class="cs-split is-wide-side"><div class="cs-stack"><form method="post">' . csrf_field() . '<input type="hidden" name="act" value="deductions">';
    $rows = [];
    foreach ($lines as $l) {
        $rows[] = [h($l['full_name']) . '<span class="cs-sub">' . h($l['worker_no'] . ($l['trade'] ? ', ' . $l['trade'] : '')) . '</span>', number_format((float)$l['days_worked'], 2),
            number_format((float)$l['regular_hours'], 2) . '<span class="cs-sub">' . number_format((float)$l['ot_hours'], 2) . ' OT</span>', money($l['gross_pay']), money($l['allowances']), money($l['advance_deduction']),
            $draft ? '<input class="form-control form-control-sm text-end" style="width:110px;margin-left:auto" name="other[' . (int)$l['id'] . ']" value="' . h(number_format((float)$l['other_deductions'], 2, '.', '')) . '">' : money($l['other_deductions']),
            '<strong>' . money($l['net_pay']) . '</strong>'];
    }
    echo cs_table_card('Workers', ['Worker' => '', 'Days' => 'num', 'Hours' => 'num', 'Basic and OT' => 'num', 'Allowances' => 'num', 'Advances' => 'num', 'Other deductions' => 'num', 'Net pay' => 'num'], $rows, 'No worker.',
        $draft ? '<button class="btn btn-outline-primary btn-sm ms-auto">Save deductions</button>' : '');
    echo '</form>';
    $rows = [];
    foreach ($byProject as $b) {
        $rows[] = [h($b['code'] . ' ' . $b['name']), (int)$b['sheets'], number_format((float)$b['hours'], 2), money($b['pay'])];
    }
    echo cs_table_card('By project', ['Project' => '', 'Timesheets' => 'num', 'Man-hours' => 'num', 'Pay' => 'num'], $rows);
    echo '</div><div class="cs-stack">' . CsApproval::panel('payroll', $id, Access::can($pageKey, 'edit') && in_array($r['status'], ['draft', 'rejected'], true), 'Submit payroll for approval');
    if ($r['status'] === 'posted') {
        echo '<div class="card"><div class="card-body">' . CsUi::facts(['Posting' => CsUi::txnLink((int)$r['txn_id']), 'Posted' => h(fdatetime($r['posted_at']))])
            . '<p class="cs-note mt-2 mb-2">Pay the net from accounting: a check or cash voucher charged to Wages Payable, one per worker or one for the payroll fund.</p>'
            . page_link('bank.cash_voucher', [], 'Cash voucher', 'btn-outline-secondary', 'cash-coin', 'create') . ' ' . page_link('bank.write_checks', [], 'Write checks', 'btn-outline-secondary', 'pencil-square', 'create') . '</div></div>';
    }
    if ($draft && Access::can($pageKey, 'delete')) {
        echo '<div class="card"><div class="card-body">' . CsUi::confirmButton('prRemove', 'Remove this run', 'Remove ' . $r['run_no'], 'Its timesheets become free for another run.', '<input type="hidden" name="act" value="remove">', 'btn-outline-danger', 'trash', true) . '</div></div>';
    }
    echo cs_history('payroll', $id) . '</div></div>';
    layout_end();
    return;
}

layout_start('Payroll Integration', ['sub' => 'Approved timesheets accrue the labor cost on each project; a payroll run pays it.']);
if (Access::can($pageKey, 'create')) {
    $last = DB::value("SELECT MAX(period_to) FROM cs_payroll_runs WHERE status <> 'void'");
    $from = $last ? date('Y-m-d', strtotime($last . ' +1 day')) : date('Y-m-01');
    $to = date('Y-m-d', strtotime($from . ' +14 days'));
    if ($to > today()) {
        $to = today();
    }
    $waiting = DB::row("SELECT COUNT(*) AS n, MIN(work_date) AS f, MAX(work_date) AS t FROM cs_timesheets WHERE status = 'posted' AND payroll_run_id IS NULL");
    echo '<form method="post" class="card mb-3"><div class="card-header">New payroll run' . ((int)$waiting['n'] ? '<span class="cs-note ms-2">' . (int)$waiting['n'] . ' approved timesheet(s) from ' . fdate($waiting['f']) . ' to ' . fdate($waiting['t']) . ' are waiting</span>' : '') . '</div><div class="card-body"><div class="row g-2 align-items-end">' . csrf_field() . '<input type="hidden" name="act" value="build">'
        . CsUi::field('Period from', CsUi::input('period_from', $from, ['type' => 'date', 'required' => true]), 'col-6 col-md-2') . CsUi::field('Period to', CsUi::input('period_to', $to, ['type' => 'date', 'required' => true]), 'col-6 col-md-2')
        . CsUi::field('Pay date', CsUi::input('pay_date', $to, ['type' => 'date']), 'col-6 col-md-2') . CsUi::field('Notes', CsUi::input('notes', '', ['max' => 255]), 'col-6 col-md-4')
        . '<div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Prepare run</button></div></div></div></form>';
}
$rows = [];
foreach (DB::all("SELECT r.*, (SELECT COUNT(*) FROM cs_payroll_lines l WHERE l.run_id = r.id) AS workers FROM cs_payroll_runs r WHERE r.status <> 'void' ORDER BY r.period_from DESC LIMIT 200") as $x) {
    $rows[] = ['<a class="cs-main-link" href="' . h(url($pageKey, ['a' => 'view', 'id' => $x['id']])) . '">' . h($x['run_no']) . '</a>', h(fdate($x['period_from']) . ' to ' . fdate($x['period_to'])), h(fdate($x['pay_date'])),
        (int)$x['workers'], money($x['total_gross']), money($x['total_deductions']), money($x['total_net']), CsUi::state($x['status'])];
}
echo cs_table_card('Payroll runs', ['Run' => '', 'Period' => '', 'Pay date' => '', 'Workers' => 'num', 'Gross' => 'num', 'Deductions' => 'num', 'Net' => 'num', 'Status' => ''], $rows, 'No payroll run yet.');
$acc = (float)DB::value('SELECT COALESCE(SUM(credit - debit),0) FROM journal_lines WHERE account_id = :a', ['a' => Accts::get('cs_accrued_payroll')]);
$wp = (float)DB::value('SELECT COALESCE(SUM(credit - debit),0) FROM journal_lines WHERE account_id = :a', ['a' => Accts::get('cs_wages_payable')]);
echo '<div class="cs-kpis mt-3">' . CsUi::kpi('Accrued payroll (not yet in a run)', CsUi::amt($acc), 'From approved timesheets') . CsUi::kpi('Wages payable (not yet paid)', CsUi::amt($wp), 'From posted runs', $wp > 0 ? 'warn' : '') . '</div>';
layout_end();
