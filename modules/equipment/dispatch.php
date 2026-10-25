<?php
declare(strict_types=1);

/** Equipment dispatch and booking: one unit on one project at a time, out and back with its meter readings. */
$pageKey = 'equip.dispatch';
$a = (string)g('a', 'list');
$id = gint('id');
$x = $id ? DB::row('SELECT d.*, e.code, e.name AS equipment_name, e.meter_unit, p.code AS pcode, w.full_name AS operator_name FROM cs_equipment_dispatch d JOIN cs_equipment e ON e.id = d.equipment_id
                     JOIN cs_projects p ON p.id = d.project_id LEFT JOIN cs_workers w ON w.id = d.operator_worker_id WHERE d.id = :id', ['id' => $id]) : null;
if ($x) {
    CsProject::requireAccess((int)$x['project_id']);
}

handle_post(function () use ($pageKey, $id, $x) {
    $act = (string)p('act');
    if ($x && in_array($act, ['dispatched', 'returned', 'cancelled'], true)) {
        Access::require($pageKey, 'edit');
        CsEquip::dispatchStatus((int)$id, $act, ['meter' => p('meter'), 'date' => p('date'), 'reason' => p('reason')]);
        flash('success', ['dispatched' => 'The unit is out at the project.', 'returned' => 'The unit is back and available.', 'cancelled' => 'Booking cancelled.'][$act]);
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    Access::require($pageKey, $id ? 'edit' : 'create');
    CsProject::requireAccess((int)p('project_id'));
    $nid = CsEquip::saveDispatch($_POST, $id);
    flash('success', 'Booking saved.');
    cs_go($pageKey, ['a' => 'view', 'id' => $nid]);
});

if ($a === 'new' || ($a === 'edit' && $x)) {
    Access::require($pageKey, $x ? 'edit' : 'create');
    $c = $x ?: ['equipment_id' => gint('equipment'), 'project_id' => CsProject::current(), 'date_from' => today(), 'date_to' => date('Y-m-d', strtotime('+7 days'))];
    $eq = DB::pairs("SELECT id, CONCAT(code, ' ', name, ' (', status, ')') FROM cs_equipment WHERE status NOT IN ('disposed') ORDER BY code");
    $ops = DB::pairs("SELECT id, full_name FROM cs_workers WHERE status = 'active' ORDER BY full_name");
    layout_start($x ? 'Edit ' . $x['dispatch_no'] : 'Book equipment for a project', ['module' => 'equip']);
    echo '<form method="post" class="card"><div class="card-body"><div class="row g-3">' . csrf_field()
        . CsUi::field('Equipment', CsUi::select('equipment_id', $eq, fv('equipment_id', $c), 'Choose', 'data-combo required'), 'col-md-6')
        . CsUi::field('Project', CsUi::projectSelect('project_id', fv('project_id', $c), ['required' => true]), 'col-md-6')
        . CsUi::field('From', CsUi::input('date_from', fv('date_from', $c), ['type' => 'date', 'required' => true]), 'col-6 col-md-3') . CsUi::field('To', CsUi::input('date_to', fv('date_to', $c), ['type' => 'date', 'required' => true]), 'col-6 col-md-3')
        . CsUi::field('Operator', CsUi::select('operator_worker_id', $ops, fv('operator_worker_id', $c), 'The unit\'s usual operator'), 'col-md-6')
        . CsUi::field('Purpose', CsUi::input('purpose', fv('purpose', $c), ['max' => 255, 'placeholder' => 'Excavation of footings, Grid A to D']), 'col-12')
        . '</div></div><div class="card-footer">' . save_buttons('Save booking', url($pageKey, $x ? ['a' => 'view', 'id' => $id] : []), false) . '</div></form>';
    layout_end();
    return;
}

if ($a === 'view' && $x) {
    layout_start($x['dispatch_no'] . ' ' . $x['code'] . ' to ' . $x['pcode'], ['module' => 'equip', 'sub' => h($x['equipment_name']) . ', ' . h(fdate($x['date_from']) . ' to ' . fdate($x['date_to'])) . ' ' . CsUi::state($x['status']),
        'actions' => $x['status'] === 'scheduled' ? cs_link($pageKey, ['a' => 'edit', 'id' => $id], 'Edit', 'btn-outline-secondary', 'pencil', 'edit') : '']);
    $hours = DB::row("SELECT SUM(operating_hours) AS op, SUM(idle_hours) AS idle, SUM(charge_amount) AS ch, COUNT(*) AS n FROM cs_equipment_logs WHERE equipment_id = :e AND project_id = :p AND log_date BETWEEN :f AND :t AND status <> 'rejected'",
        ['e' => $x['equipment_id'], 'p' => $x['project_id'], 'f' => $x['date_from'], 't' => $x['returned_date'] ?: $x['date_to']]);
    echo '<div class="cs-kpis">' . CsUi::kpi('Days logged', (string)(int)$hours['n'], number_format((float)$hours['op'], 1) . ' operating h, ' . number_format((float)$hours['idle'], 1) . ' idle h', 'key')
        . CsUi::kpi('Charged to the project', CsUi::amt($hours['ch']), '') . CsUi::kpi('Meter', $x['meter_out'] !== null ? number_format((float)$x['meter_out'], 1) . ' out' : 'Not out yet', $x['meter_in'] !== null ? number_format((float)$x['meter_in'], 1) . ' back' : '') . '</div>';
    echo '<div class="cs-split"><div class="card"><div class="card-body">' . CsUi::facts(['Project' => CsUi::projectLink((int)$x['project_id']), 'Operator' => h((string)$x['operator_name']), 'Purpose' => h((string)$x['purpose']), 'Returned' => h(fdate($x['returned_date']))]) . '</div></div><div class="cs-stack">';
    if (Access::can($pageKey, 'edit') && in_array($x['status'], ['scheduled', 'dispatched'], true)) {
        $next = $x['status'] === 'scheduled' ? 'dispatched' : 'returned';
        echo '<form method="post" class="card"><div class="card-header">' . ($next === 'dispatched' ? 'Send out' : 'Return') . '</div><div class="card-body"><div class="row g-2">' . csrf_field() . '<input type="hidden" name="act" value="' . $next . '">'
            . CsUi::field('Meter reading (' . h($x['meter_unit']) . ')', CsUi::input('meter', '', ['num' => true]), 'col-6') . ($next === 'returned' ? CsUi::field('Date', CsUi::input('date', today(), ['type' => 'date']), 'col-6') : '')
            . '</div></div><div class="card-footer d-flex gap-2"><button class="btn btn-primary btn-sm">' . ($next === 'dispatched' ? 'Mark as out at the project' : 'Mark as returned') . '</button></div></form>';
        if ($x['status'] === 'scheduled') {
            echo '<div class="card"><div class="card-body">' . CsUi::confirmButton('dCancel', 'Cancel booking', 'Cancel ' . $x['dispatch_no'], 'The unit is free again for those dates.', '<input type="hidden" name="act" value="cancelled">', 'btn-outline-secondary', 'x-lg', true) . '</div></div>';
        }
    }
    echo cs_history('dispatch', $id) . '</div></div>';
    layout_end();
    return;
}

// list and a two-week board
$pid = CsProject::current();
$from = gdate('from') ?: date('Y-m-d', strtotime('monday this week'));
$days = 14;
layout_start('Dispatch & Booking', ['actions' => cs_link($pageKey, ['a' => 'new'], 'Book equipment', 'btn-primary', 'plus-lg', 'create')]);
echo cs_filter_bar($pageKey, $pid, '<div><label class="form-label">Board from</label><input class="form-control form-control-sm" type="date" name="from" value="' . h($from) . '"></div>');
$to = date('Y-m-d', strtotime($from . ' +' . ($days - 1) . ' days'));
$eqs = DB::all("SELECT id, code, name, status FROM cs_equipment WHERE status <> 'disposed' ORDER BY code");
$disp = DB::all("SELECT d.*, p.code AS pcode FROM cs_equipment_dispatch d JOIN cs_projects p ON p.id = d.project_id WHERE d.status IN ('scheduled','dispatched','returned') AND d.date_from <= :t AND COALESCE(d.returned_date, d.date_to) >= :f" . ($pid ? ' AND d.project_id = ' . (int)$pid : ''), ['f' => $from, 't' => $to]);
$by = [];
foreach ($disp as $d) {
    $by[(int)$d['equipment_id']][] = $d;
}
echo '<div class="card mb-3"><div class="card-header">Booking board, ' . h(fdate($from) . ' to ' . fdate($to)) . '</div><div class="table-responsive"><table class="table table-sm cs-table mb-0" style="font-size:.78rem"><thead><tr><th>Unit</th>';
for ($i = 0; $i < $days; $i++) {
    $d = date('Y-m-d', strtotime($from . ' +' . $i . ' days'));
    echo '<th class="text-center' . ($d === today() ? ' table-warning' : '') . '">' . h(date('D', strtotime($d))) . '<br>' . h(date('j', strtotime($d))) . '</th>';
}
echo '</tr></thead><tbody>';
foreach ($eqs as $e) {
    if ($pid && !isset($by[(int)$e['id']])) {
        continue;
    }
    echo '<tr><td class="text-nowrap"><a href="' . h(url('equip.register', ['a' => 'view', 'id' => $e['id']])) . '">' . h($e['code']) . '</a> <span class="text-muted">' . h(mb_strimwidth($e['name'], 0, 26, '...')) . '</span></td>';
    for ($i = 0; $i < $days; $i++) {
        $d = date('Y-m-d', strtotime($from . ' +' . $i . ' days'));
        $hit = null;
        foreach ($by[(int)$e['id']] ?? [] as $x2) {
            if ($x2['date_from'] <= $d && ($x2['returned_date'] ?: $x2['date_to']) >= $d) {
                $hit = $x2;
            }
        }
        echo $hit ? '<td class="text-center" style="background:' . ($hit['status'] === 'scheduled' ? '#e7eef7' : '#d3e1f0') . '"><a href="' . h(url($pageKey, ['a' => 'view', 'id' => $hit['id']])) . '" title="' . h($hit['dispatch_no'] . ' ' . CsUi::label($hit['status'])) . '">' . h($hit['pcode']) . '</a></td>'
            : '<td class="text-center text-muted">' . ($e['status'] === 'under_repair' ? 'R' : '') . '</td>';
    }
    echo '</tr>';
}
echo '</tbody></table></div><div class="card-body pt-2 cs-note">Light blue: booked; darker: out at the project; R: under repair.</div></div>';
[$f, $params] = CsProject::filter('d.project_id');
$w = $pid ? ' AND d.project_id = ' . (int)$pid : '';
$rows = [];
foreach (DB::all("SELECT d.*, e.code, e.name, p.code AS pcode FROM cs_equipment_dispatch d JOIN cs_equipment e ON e.id = d.equipment_id JOIN cs_projects p ON p.id = d.project_id WHERE 1 = 1 $f $w ORDER BY d.id DESC LIMIT 200", $params) as $d) {
    $rows[] = ['<a class="cs-main-link" href="' . h(url($pageKey, ['a' => 'view', 'id' => $d['id']])) . '">' . h($d['dispatch_no']) . '</a>', h($d['code'] . ' ' . $d['name']), h($d['pcode']), h(fdate($d['date_from']) . ' to ' . fdate($d['date_to'])), h((string)$d['purpose']), CsUi::state($d['status'])];
}
echo cs_table_card('Bookings', ['Dispatch' => '', 'Unit' => '', 'Project' => '', 'Dates' => '', 'Purpose' => '', 'Status' => ''], $rows, 'No bookings.');
layout_end();
