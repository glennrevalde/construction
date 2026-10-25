<?php
declare(strict_types=1);

/** The workforce: workers, foremen and site staff with their trade, rates, assignment and safety documents. */
$pageKey = 'labor.workers';
$a = (string)g('a', 'list');
$id = gint('id');
$w = $id ? DB::row('SELECT w.*, t.name AS trade_name, v.name AS vendor_name, s.full_name AS supervisor_name FROM cs_workers w LEFT JOIN cs_trades t ON t.id = w.trade_id
                     LEFT JOIN vendors v ON v.id = w.vendor_id LEFT JOIN cs_workers s ON s.id = w.supervisor_id WHERE w.id = :id', ['id' => $id]) : null;

handle_post(function () use ($pageKey, $id, $w) {
    if ($w && cs_handle_attach('worker', (int)$id, $w['assigned_project_id'] ? (int)$w['assigned_project_id'] : null)) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    Access::require($pageKey, $id ? 'edit' : 'create');
    $nid = CsLabor::saveWorker($_POST, $id);
    flash('success', 'Worker saved.');
    cs_go($pageKey, ['a' => 'view', 'id' => $nid]);
});

if ($a === 'new' || ($a === 'edit' && $w)) {
    Access::require($pageKey, $w ? 'edit' : 'create');
    $c = $w ?: ['classification' => 'direct', 'rate_basis' => 'daily', 'ot_multiplier' => '1.25', 'holiday_multiplier' => '2.00', 'burden_pct' => '12', 'break_minutes' => 60,
        'shift_start' => '07:00', 'shift_end' => '16:00', 'status' => 'active', 'assigned_project_id' => CsProject::current(), 'date_hired' => today()];
    $trades = DB::all('SELECT id, name, daily_rate FROM cs_trades WHERE is_active = 1 ORDER BY name');
    $tradeSel = '<select class="form-select form-select-sm" name="trade_id" data-fill="rate:daily_rate" data-no-combo><option value="">None</option>';
    foreach ($trades as $t) {
        $tradeSel .= '<option value="' . (int)$t['id'] . '" data-rate="' . h(number_format((float)$t['daily_rate'], 2, '.', '')) . '"' . ((string)fv('trade_id', $c) === (string)$t['id'] ? ' selected' : '') . '>' . h($t['name']) . ' (' . money($t['daily_rate']) . ' a day)</option>';
    }
    $tradeSel .= '</select>';
    $vendors = DB::pairs("SELECT id, name FROM vendors WHERE is_active = 1 AND vendor_kind IN ('subcontractor','both','service') ORDER BY name");
    $foremen = DB::pairs("SELECT id, full_name FROM cs_workers WHERE status = 'active'" . ($id ? ' AND id <> ' . (int)$id : '') . ' ORDER BY full_name');
    layout_start($w ? 'Edit ' . $w['full_name'] : 'New worker', ['module' => 'labor', 'sub' => 'The worker is also listed under Employees & Other Names in accounting, for the payroll and advances ledgers.']);
    echo '<form method="post"><div class="card mb-3"><div class="card-body"><div class="row g-3">' . csrf_field()
        . CsUi::field('Full name', CsUi::input('full_name', fv('full_name', $c), ['required' => true, 'max' => 120]), 'col-md-5')
        . CsUi::field('Worker no.', $w ? '<input class="form-control form-control-sm" value="' . h($w['worker_no']) . '" readonly>' : CsUi::input('worker_no', '', ['max' => 30, 'placeholder' => 'Next: ' . Seq::peek('cs_worker')]), 'col-6 col-md-2')
        . CsUi::field('Classification', CsUi::select('classification', CsLabor::CLASSES, fv('classification', $c), null, 'data-no-combo'), 'col-6 col-md-3')
        . CsUi::field('Status', CsUi::select('status', ['active' => 'Active', 'inactive' => 'Inactive', 'terminated' => 'Terminated'], fv('status', $c), null, 'data-no-combo'), 'col-6 col-md-2')
        . CsUi::field('Trade', $tradeSel, 'col-md-4') . CsUi::field('Skills', CsUi::input('skills', fv('skills', $c), ['max' => 255]), 'col-md-4')
        . CsUi::field('Certifications and licences', CsUi::input('certifications', fv('certifications', $c), ['max' => 255, 'placeholder' => 'TESDA NC II, heavy equipment licence']), 'col-md-4')
        . CsUi::field('Assigned project', CsUi::projectSelect('assigned_project_id', fv('assigned_project_id', $c), ['blank' => 'None (pool)']), 'col-md-4')
        . CsUi::field('Foreman / supervisor', CsUi::select('supervisor_id', $foremen, fv('supervisor_id', $c), 'None'), 'col-md-4')
        . CsUi::field('Agency or labor-only subcontractor', CsUi::select('vendor_id', $vendors, fv('vendor_id', $c), 'None (our employee)'), 'col-md-4', 'Agency workers are timed here; their cost comes from the agency\'s bill.')
        . '</div></div></div><div class="card mb-3"><div class="card-header">Pay and schedule</div><div class="card-body"><div class="row g-3">'
        . CsUi::field('Paid by', CsUi::select('rate_basis', ['daily' => 'Daily rate', 'hourly' => 'Hourly rate'], fv('rate_basis', $c), null, 'data-no-combo'), 'col-6 col-md-2')
        . CsUi::field('Daily rate', CsUi::input('daily_rate', fv('daily_rate', $c), ['num' => true]), 'col-6 col-md-2') . CsUi::field('Hourly rate', CsUi::input('hourly_rate', fv('hourly_rate', $c), ['num' => true]), 'col-6 col-md-2')
        . CsUi::field('Overtime multiplier', CsUi::input('ot_multiplier', fv('ot_multiplier', $c), ['num' => true]), 'col-6 col-md-2') . CsUi::field('Holiday multiplier', CsUi::input('holiday_multiplier', fv('holiday_multiplier', $c), ['num' => true]), 'col-6 col-md-2')
        . CsUi::field('Burden %', CsUi::input('burden_pct', fv('burden_pct', $c), ['num' => true]), 'col-6 col-md-2', 'Employer SSS, PhilHealth, Pag-IBIG, 13th month')
        . CsUi::field('Daily allowance', CsUi::input('allowance_daily', fv('allowance_daily', $c), ['num' => true]), 'col-6 col-md-2')
        . CsUi::field('Shift start', CsUi::input('shift_start', substr((string)fv('shift_start', $c), 0, 5), ['type' => 'time']), 'col-6 col-md-2') . CsUi::field('Shift end', CsUi::input('shift_end', substr((string)fv('shift_end', $c), 0, 5), ['type' => 'time']), 'col-6 col-md-2')
        . CsUi::field('Break (minutes)', CsUi::input('break_minutes', fv('break_minutes', $c), ['type' => 'number', 'step' => '5']), 'col-6 col-md-2') . CsUi::field('Date hired', CsUi::input('date_hired', fv('date_hired', $c), ['type' => 'date']), 'col-6 col-md-2')
        . '</div></div></div><div class="card mb-3"><div class="card-body"><div class="row g-3">'
        . CsUi::field('Phone', CsUi::input('phone', fv('phone', $c), ['max' => 40]), 'col-md-3') . CsUi::field('Address', CsUi::input('address', fv('address', $c), ['max' => 255]), 'col-md-9')
        . CsUi::field('Safety documents', CsUi::input('safety_docs', fv('safety_docs', $c), ['max' => 255, 'placeholder' => 'Safety orientation, medical certificate, PPE issued']), 'col-md-6')
        . CsUi::field('Notes', CsUi::input('notes', fv('notes', $c), ['max' => 255]), 'col-md-6')
        . '</div></div></div><div class="sticky-actions">' . save_buttons('Save worker', url($pageKey, $w ? ['a' => 'view', 'id' => $id] : []), false) . '</div></form>';
    layout_end();
    return;
}

if ($a === 'view' && $w) {
    $since = date('Y-m-d', strtotime('-30 days'));
    $att = DB::row("SELECT COUNT(DISTINCT CASE WHEN l.regular_hours + l.ot_hours > 0 THEN l.work_date END) AS days, SUM(l.regular_hours) AS reg, SUM(l.ot_hours) AS ot, SUM(l.is_absent) AS absent,
                           SUM(l.late_minutes) AS late, SUM(l.labor_cost + l.allowance + l.burden_cost) AS cost
                      FROM cs_timesheet_lines l JOIN cs_timesheets t ON t.id = l.timesheet_id WHERE l.worker_id = :w AND l.work_date >= :s AND t.status <> 'rejected'", ['w' => $id, 's' => $since]);
    $owed = (float)DB::value("SELECT COALESCE(SUM(amount - deducted),0) FROM cs_cash_advances WHERE worker_id = :w AND status = 'released'", ['w' => $id]);
    layout_start($w['full_name'], ['module' => 'labor', 'sub' => h(CsUi::sub([$w['worker_no'], $w['trade_name'], CsLabor::CLASSES[$w['classification']] ?? ''])) . ' ' . CsUi::state($w['status']),
        'actions' => cs_link($pageKey, ['a' => 'edit', 'id' => $id], 'Edit', 'btn-outline-secondary', 'pencil', 'edit') . ' ' . cs_link('labor.advances', ['a' => 'new', 'worker' => $id], 'Cash advance', 'btn-outline-secondary', 'cash', 'create')]);
    echo '<div class="cs-kpis">' . CsUi::kpi('Days worked, last 30 days', (string)(int)$att['days'], number_format((float)$att['reg'], 1) . ' regular h, ' . number_format((float)$att['ot'], 1) . ' overtime h', 'key')
        . CsUi::kpi('Absences', (string)(int)$att['absent'], (int)$att['late'] ? (int)$att['late'] . ' minutes late in total' : 'No lateness')
        . CsUi::kpi('Labor cost, last 30 days', CsUi::amt($att['cost']), 'Wages, allowances and burden')
        . CsUi::kpi('Rate', money(CsLabor::hourly($w)) . ' / h', $w['rate_basis'] === 'daily' ? money($w['daily_rate']) . ' a day' : 'Hourly')
        . CsUi::kpi('Advances not yet deducted', CsUi::amt($owed), '', $owed > 0 ? 'warn' : '') . '</div>';
    echo '<div class="cs-split"><div class="cs-stack">';
    $rows = [];
    foreach (DB::all("SELECT l.*, t.ts_no, t.id AS tid, t.status, p.code FROM cs_timesheet_lines l JOIN cs_timesheets t ON t.id = l.timesheet_id JOIN cs_projects p ON p.id = t.project_id
                       WHERE l.worker_id = :w ORDER BY l.work_date DESC, l.id DESC LIMIT 40", ['w' => $id]) as $l) {
        $rows[] = [h(fdate($l['work_date'])), '<a href="' . h(url('labor.timesheets', ['a' => 'view', 'id' => $l['tid']])) . '">' . h($l['ts_no']) . '</a>', h($l['code']),
            (int)$l['is_absent'] ? '<span class="cs-bad">Absent</span>' : h(substr((string)$l['time_in'], 0, 5) . ' to ' . substr((string)$l['time_out'], 0, 5)),
            number_format((float)$l['regular_hours'], 2), number_format((float)$l['ot_hours'], 2), (int)$l['late_minutes'] ? '<span class="cs-warn">' . (int)$l['late_minutes'] . ' min</span>' : '', money((float)$l['labor_cost'] + (float)$l['allowance']), CsUi::state($l['status'])];
    }
    echo cs_table_card('Attendance', ['Date' => '', 'Timesheet' => '', 'Project' => '', 'Time' => '', 'Regular' => 'num', 'Overtime' => 'num', 'Late' => '', 'Pay' => 'num', 'Status' => ''], $rows, 'No attendance yet.');
    echo '</div><div class="cs-stack"><div class="card"><div class="card-body">' . CsUi::facts([
        'Assigned project' => $w['assigned_project_id'] ? CsUi::projectLink((int)$w['assigned_project_id']) : 'Pool', 'Foreman' => h((string)$w['supervisor_name']), 'Agency' => h((string)$w['vendor_name']),
        'Shift' => h(substr((string)$w['shift_start'], 0, 5) . ' to ' . substr((string)$w['shift_end'], 0, 5)) . ', break ' . (int)$w['break_minutes'] . ' min',
        'Overtime / holiday' => h('x' . (float)$w['ot_multiplier'] . ' / x' . (float)$w['holiday_multiplier']), 'Burden' => CsUi::pct((float)$w['burden_pct'], 2), 'Allowance' => money($w['allowance_daily']) . ' a day',
        'Skills' => h((string)$w['skills']), 'Certifications' => h((string)$w['certifications']), 'Safety documents' => h((string)$w['safety_docs']), 'Phone' => h((string)$w['phone']), 'Hired' => h(fdate($w['date_hired'])),
    ]) . '</div></div>' . CsFiles::panel('worker', $id, Access::can($pageKey, 'edit'), 'IDs, certificates and medical') . '</div></div>';
    layout_end();
    return;
}

$pid = CsProject::current();
$cls = (string)g('class');
$st = (string)g('status', 'active');
layout_start('Workers', ['actions' => cs_link($pageKey, ['a' => 'new'], 'New worker', 'btn-primary', 'plus-lg', 'create')]);
echo cs_filter_bar($pageKey, $pid, cs_search_field('Name, worker no., skill') . cs_status_field(CsLabor::CLASSES, 'class', 'Classification') . cs_status_field(['active' => 'Active', 'inactive' => 'Inactive', 'terminated' => 'Terminated']));
[$w, $params] = cs_like((string)g('q'), ['w.full_name', 'w.worker_no', 'w.skills']);
if ($pid) {
    $w .= ' AND w.assigned_project_id = :pid';
    $params['pid'] = $pid;
}
if (isset(CsLabor::CLASSES[$cls])) {
    $w .= ' AND w.classification = :c';
    $params['c'] = $cls;
}
if ($st !== '') {
    $w .= ' AND w.status = :st';
    $params['st'] = $st;
}
$rows = DB::all("SELECT w.*, t.name AS trade_name, p.code AS pcode, v.name AS vendor_name FROM cs_workers w LEFT JOIN cs_trades t ON t.id = w.trade_id LEFT JOIN cs_projects p ON p.id = w.assigned_project_id
                  LEFT JOIN vendors v ON v.id = w.vendor_id WHERE 1 = 1 $w ORDER BY w.full_name LIMIT 1000", $params);
$out = [];
foreach ($rows as $r) {
    $out[] = ['<a class="cs-main-link" href="' . h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) . '">' . h($r['full_name']) . '</a><span class="cs-sub">' . h($r['worker_no']) . '</span>', h((string)$r['trade_name']),
        h(CsLabor::CLASSES[$r['classification']] ?? '') . ($r['vendor_name'] ? '<span class="cs-sub">' . h($r['vendor_name']) . '</span>' : ''), h((string)$r['pcode']),
        $r['rate_basis'] === 'daily' ? money($r['daily_rate']) . ' / day' : money($r['hourly_rate']) . ' / h', CsUi::state($r['status'])];
}
echo cs_table_card(count($rows) . ' workers', ['Worker' => '', 'Trade' => '', 'Classification' => '', 'Project' => '', 'Rate' => 'num', 'Status' => ''], $out, 'No workers.');
layout_end();
