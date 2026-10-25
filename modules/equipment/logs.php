<?php
declare(strict_types=1);

/** Daily equipment usage: operating, idle and breakdown hours, meter, fuel, operator; approval charges the project. */
$pageKey = 'equip.logs';
$a = (string)g('a', 'list');
$id = gint('id');
$l = $id ? CsEquip::getLog($id) : null;
if ($l) {
    CsProject::requireAccess((int)$l['project_id']);
}

handle_post(function () use ($pageKey, $id, $l) {
    if ($l && CsApproval::handlePost('equipment_log', (int)$id, function () use ($l) {
        if (!in_array($l['status'], ['draft', 'rejected'], true)) {
            fail('Only a draft usage log is submitted.');
        }
        CsApproval::submit('equipment_log', (int)$l['id'], $l['log_no'] . ' ' . $l['code'] . ' ' . fdate($l['log_date']), (int)$l['project_id'], (float)$l['charge_amount']);
    })) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    $act = (string)p('act');
    if ($l && $act === 'reopen') {
        Access::require($pageKey, 'edit');
        CsEquip::reopenLog((int)$id, (string)p('reason'));
        flash('success', 'Reopened for correction; its charge was voided.');
        cs_go($pageKey, ['a' => 'edit', 'id' => $id]);
    }
    if ($act === 'save') {
        Access::require($pageKey, $id ? 'edit' : 'create');
        $nid = CsEquip::saveLog($_POST, $id);
        if (p('then') === 'submit') {
            $n = CsEquip::getLog($nid);
            CsApproval::submit('equipment_log', $nid, $n['log_no'] . ' ' . $n['code'] . ' ' . fdate($n['log_date']), (int)$n['project_id'], (float)$n['charge_amount']);
            flash('success', 'Saved and submitted for approval.');
        } else {
            flash('success', 'Usage log saved.');
        }
        cs_go($pageKey, ['a' => 'view', 'id' => $nid]);
    }
});

if ($a === 'new' || ($a === 'edit' && $l)) {
    Access::require($pageKey, $l ? 'edit' : 'create');
    $pid = $l ? (int)$l['project_id'] : (int)(gint('project') ?? CsProject::current() ?? 0);
    $c = $l ?: ['project_id' => $pid, 'log_date' => today(), 'equipment_id' => gint('equipment')];
    $eqs = DB::all("SELECT e.id, e.code, e.name, e.current_meter, e.operator_worker_id, e.current_project_id FROM cs_equipment e WHERE e.status <> 'disposed' ORDER BY (e.current_project_id = " . (int)$pid . ") DESC, e.code");
    $eqSel = '<select class="form-select form-select-sm" name="equipment_id" data-fill="meter:meter_start,op:operator_worker_id" data-combo required><option value="">Choose</option>';
    foreach ($eqs as $e) {
        $eqSel .= '<option value="' . (int)$e['id'] . '" data-meter="' . h(number_format((float)$e['current_meter'], 2, '.', '')) . '" data-op="' . h((string)$e['operator_worker_id']) . '"' . ((string)fv('equipment_id', $c) === (string)$e['id'] ? ' selected' : '') . '>'
            . h($e['code'] . ' ' . $e['name'] . ((int)$e['current_project_id'] === $pid && $pid ? ' (at this project)' : '')) . '</option>';
    }
    $eqSel .= '</select>';
    $acts = $pid ? DB::pairs("SELECT id, CONCAT(wbs_code, ' ', name) FROM cs_activities WHERE project_id = :p ORDER BY sort_order", ['p' => $pid]) : [];
    $ops = DB::pairs("SELECT id, full_name FROM cs_workers WHERE status = 'active' ORDER BY full_name");
    layout_start($l ? 'Edit ' . $l['log_no'] : 'New usage log', ['module' => 'equip']);
    echo '<form method="post" data-cs-offline="eql-' . (int)($id ?? 0) . '"' . ($l ? '' : ' data-cs-new') . '><div class="card mb-3"><div class="card-body"><div class="row g-3">' . csrf_field() . '<input type="hidden" name="act" value="save">'
        . CsUi::field('Project', $l ? '<input type="hidden" name="project_id" value="' . $pid . '"><div class="pt-1">' . CsUi::projectLink($pid) . '</div>' : CsUi::projectSelect('project_id', $pid ?: '', ['required' => true, 'extra' => 'onchange="location.href=\'' . h(url($pageKey, ['a' => 'new'])) . '&project=\'+this.value"']), 'col-md-5')
        . CsUi::field('Equipment', $eqSel, 'col-md-5') . CsUi::field('Date', CsUi::input('log_date', fv('log_date', $c), ['type' => 'date', 'extra' => 'max="' . today() . '"']), 'col-md-2')
        . CsUi::field('Operating hours', CsUi::input('operating_hours', fv('operating_hours', $c), ['num' => true, 'required' => true]), 'col-4 col-md-2') . CsUi::field('Idle hours', CsUi::input('idle_hours', fv('idle_hours', $c, '0'), ['num' => true]), 'col-4 col-md-2')
        . CsUi::field('Breakdown hours', CsUi::input('breakdown_hours', fv('breakdown_hours', $c, '0'), ['num' => true]), 'col-4 col-md-2')
        . CsUi::field('Meter start', CsUi::input('meter_start', fv('meter_start', $c), ['num' => true]), 'col-6 col-md-3') . CsUi::field('Meter end', CsUi::input('meter_end', fv('meter_end', $c), ['num' => true]), 'col-6 col-md-3')
        . CsUi::field('Fuel (liters)', CsUi::input('fuel_liters', fv('fuel_liters', $c, '0'), ['num' => true]), 'col-6 col-md-2') . CsUi::field('Fuel cost', CsUi::input('fuel_cost', fv('fuel_cost', $c, '0'), ['num' => true]), 'col-6 col-md-2')
        . CsUi::field('Operator', CsUi::select('operator_worker_id', $ops, fv('operator_worker_id', $c), 'Choose'), 'col-md-4')
        . CsUi::field('Activity', CsUi::select('activity_id', $acts, fv('activity_id', $c), 'None'), 'col-md-4') . CsUi::field('Cost code', CsUi::costCodeSelect('cost_code_id', fv('cost_code_id', $c)), 'col-md-4')
        . CsUi::field('Remarks', CsUi::input('remarks', fv('remarks', $c), ['max' => 255, 'placeholder' => 'Work done, breakdown cause']), 'col-12')
        . '</div></div></div><div class="sticky-actions d-flex flex-wrap gap-2"><button class="btn btn-outline-primary">Save draft</button><button class="btn btn-primary" name="then" value="submit">Save and submit for approval</button>'
        . '<a class="btn btn-outline-secondary" href="' . h(url($pageKey, $l ? ['a' => 'view', 'id' => $id] : [])) . '">Cancel</a></div></form>';
    layout_end();
    return;
}

if ($a === 'view' && $l) {
    $op = DB::value('SELECT full_name FROM cs_workers WHERE id = :w', ['w' => (int)$l['operator_worker_id']]);
    layout_start($l['log_no'] . ' ' . $l['code'], ['module' => 'equip', 'sub' => h($l['equipment_name']) . ', ' . CsUi::projectLink((int)$l['project_id']) . ', ' . h(fdate($l['log_date'])) . ' ' . CsUi::state($l['status']),
        'actions' => in_array($l['status'], ['draft', 'rejected'], true) ? cs_link($pageKey, ['a' => 'edit', 'id' => $id], 'Edit', 'btn-outline-secondary', 'pencil', 'edit') : '']);
    echo '<div class="cs-kpis">' . CsUi::kpi('Operating', number_format((float)$l['operating_hours'], 2) . ' h', number_format((float)$l['idle_hours'], 2) . ' idle, ' . number_format((float)$l['breakdown_hours'], 2) . ' breakdown', 'key')
        . CsUi::kpi('Charge to the project', CsUi::amt($l['charge_amount']), $l['costing_method'] === 'rental' ? 'Rented: the supplier\'s bill carries the cost' : money($l['hourly_rate']) . ' per hour')
        . CsUi::kpi('Fuel', number_format((float)$l['fuel_liters'], 2) . ' L', money($l['fuel_cost'])) . '</div>';
    echo '<div class="cs-split"><div class="card"><div class="card-body">' . CsUi::facts(['Meter' => $l['meter_start'] !== null ? number_format((float)$l['meter_start'], 1) . ' to ' . number_format((float)$l['meter_end'], 1) . ' ' . h($l['meter_unit']) : '',
        'Operator' => h((string)$op), 'Activity' => h((string)DB::value("SELECT CONCAT(wbs_code, ' ', name) FROM cs_activities WHERE id = :a", ['a' => (int)$l['activity_id']])),
        'Cost code' => h(CsCost::codeLabel($l['cost_code_id'] ? (int)$l['cost_code_id'] : null)), 'Posting' => $l['txn_id'] ? CsUi::txnLink((int)$l['txn_id']) : 'When approved', 'Remarks' => h((string)$l['remarks'])]) . '</div></div><div class="cs-stack">';
    echo CsApproval::panel('equipment_log', $id, Access::can($pageKey, 'edit') && in_array($l['status'], ['draft', 'rejected'], true));
    if (in_array($l['status'], ['approved', 'posted'], true) && Access::can($pageKey, 'edit')) {
        echo '<div class="card"><div class="card-body">' . CsUi::confirmButton('elReopen', 'Reopen for correction', 'Reopen ' . $l['log_no'], 'The charge to the project is voided (kept with your reason) and the log goes back to draft.', '<input type="hidden" name="act" value="reopen">', 'btn-outline-secondary', 'arrow-counterclockwise', true) . '</div></div>';
    }
    echo cs_history('equipment_log', $id) . '</div></div>';
    layout_end();
    return;
}

$pid = CsProject::current();
$from = gdate('from') ?: date('Y-m-d', strtotime('-30 days'));
$to = gdate('to') ?: today();
layout_start('Equipment Usage Logs', ['actions' => cs_link($pageKey, ['a' => 'new', 'project' => $pid], 'New usage log', 'btn-primary', 'plus-lg', 'create')]);
echo cs_filter_bar($pageKey, $pid, '<div><label class="form-label">From</label><input class="form-control form-control-sm" type="date" name="from" value="' . h($from) . '"></div><div><label class="form-label">To</label><input class="form-control form-control-sm" type="date" name="to" value="' . h($to) . '"></div>'
    . cs_status_field(['draft' => 'Draft', 'for_approval' => 'For approval', 'posted' => 'Posted', 'rejected' => 'Rejected']));
[$f, $params] = CsProject::filter('l.project_id');
$w = ' AND l.log_date BETWEEN :f AND :t';
$params += ['f' => $from, 't' => $to];
if ($pid) {
    $w .= ' AND l.project_id = :pid';
    $params['pid'] = $pid;
}
if ((string)g('status') !== '') {
    $w .= ' AND l.status = :st';
    $params['st'] = (string)g('status');
}
$rows = DB::all("SELECT l.*, e.code, e.name, p.code AS pcode FROM cs_equipment_logs l JOIN cs_equipment e ON e.id = l.equipment_id JOIN cs_projects p ON p.id = l.project_id WHERE 1 = 1 $f $w ORDER BY l.log_date DESC, l.id DESC LIMIT 500", $params);
$out = [];
$t = [0.0, 0.0, 0.0, 0.0];
foreach ($rows as $r) {
    $t[0] += (float)$r['operating_hours'];
    $t[1] += (float)$r['idle_hours'];
    $t[2] += (float)$r['fuel_liters'];
    $t[3] += (float)$r['charge_amount'];
    $out[] = ['<a class="cs-main-link" href="' . h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) . '">' . h($r['log_no']) . '</a>', h(fdate($r['log_date'])), h($r['code'] . ' ' . $r['name']), h($r['pcode']),
        number_format((float)$r['operating_hours'], 1), number_format((float)$r['idle_hours'], 1), (float)$r['breakdown_hours'] ? '<span class="cs-bad">' . number_format((float)$r['breakdown_hours'], 1) . '</span>' : '0.0',
        number_format((float)$r['fuel_liters'], 1), money($r['charge_amount']), CsUi::state($r['status'])];
}
echo cs_table_card(count($rows) . ' logs', ['Log' => '', 'Date' => '', 'Unit' => '', 'Project' => '', 'Operating' => 'num', 'Idle' => 'num', 'Breakdown' => 'num', 'Fuel (L)' => 'num', 'Charge' => 'num', 'Status' => ''], $out, 'No usage in this period.', '',
    $out ? '<tfoot><tr><td colspan="4">Total</td><td class="num">' . number_format($t[0], 1) . '</td><td class="num">' . number_format($t[1], 1) . '</td><td></td><td class="num">' . number_format($t[2], 1) . '</td><td class="num">' . money($t[3]) . '</td><td></td></tr></tfoot>' : '');
layout_end();
