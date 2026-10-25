<?php
declare(strict_types=1);

/** The equipment and vehicle register: ownership, rates, meters, documents, where each unit is, and its use and cost. */
$pageKey = 'equip.register';
$a = (string)g('a', 'list');
$id = gint('id');
$e = $id ? CsEquip::get($id) : null;

handle_post(function () use ($pageKey, $id, $e) {
    if ($e && cs_handle_attach('equipment', (int)$id, null)) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    Access::require($pageKey, $id ? 'edit' : 'create');
    $nid = CsEquip::save($_POST, $id);
    flash('success', 'Equipment saved.');
    cs_go($pageKey, ['a' => 'view', 'id' => $nid]);
});

if ($a === 'new' || ($a === 'edit' && $e)) {
    Access::require($pageKey, $e ? 'edit' : 'create');
    $c = $e ?: ['ownership' => 'owned', 'costing_method' => 'standard', 'meter_unit' => 'hours', 'equipment_type' => 'excavator'];
    $vendors = DB::pairs("SELECT id, name FROM vendors WHERE is_active = 1 ORDER BY name");
    $assets = DB::pairs("SELECT id, CONCAT(asset_no, ' ', description) FROM fixed_assets WHERE status <> 'disposed' ORDER BY asset_no");
    $ops = DB::pairs("SELECT id, full_name FROM cs_workers WHERE status = 'active' ORDER BY full_name");
    layout_start($e ? 'Edit ' . $e['code'] : 'New equipment', ['module' => 'equip']);
    echo '<form method="post"><div class="card mb-3"><div class="card-body"><div class="row g-3">' . csrf_field()
        . CsUi::field('Code', $e ? '<input class="form-control form-control-sm" value="' . h($e['code']) . '" readonly>' : CsUi::input('code', '', ['max' => 30, 'placeholder' => 'Next: ' . Seq::peek('cs_equipment')]), 'col-6 col-md-2')
        . CsUi::field('Name', CsUi::input('name', fv('name', $c), ['required' => true, 'max' => 150, 'placeholder' => 'Komatsu PC200 excavator']), 'col-md-5')
        . CsUi::field('Type', CsUi::select('equipment_type', CsEquip::TYPES, fv('equipment_type', $c), null, 'data-no-combo'), 'col-6 col-md-2')
        . ($e ? CsUi::field('Status', CsUi::select('status', CsEquip::STATUSES, fv('status', $c), null, 'data-no-combo'), 'col-6 col-md-3') : '')
        . CsUi::field('Make and model', CsUi::input('make_model', fv('make_model', $c), ['max' => 120]), 'col-md-4') . CsUi::field('Plate / serial no.', CsUi::input('plate_serial', fv('plate_serial', $c), ['max' => 60]), 'col-md-4')
        . CsUi::field('Usual operator', CsUi::select('operator_worker_id', $ops, fv('operator_worker_id', $c), 'None'), 'col-md-4')
        . '</div></div></div><div class="card mb-3"><div class="card-header">Ownership and cost</div><div class="card-body"><div class="row g-3">'
        . CsUi::field('Ownership', CsUi::select('ownership', ['owned' => 'Owned', 'rented' => 'Rented', 'leased' => 'Leased'], fv('ownership', $c), null, 'data-no-combo'), 'col-6 col-md-2')
        . CsUi::field('Fixed asset', CsUi::select('fixed_asset_id', $assets, fv('fixed_asset_id', $c), 'Not linked'), 'col-md-4', 'Owned units: depreciation runs in Fixed Assets.')
        . CsUi::field('Rental / leasing company', CsUi::select('vendor_id', $vendors, fv('vendor_id', $c), 'None'), 'col-md-3')
        . CsUi::field('Acquisition cost', CsUi::input('acquisition_cost', fv('acquisition_cost', $c), ['num' => true]), 'col-6 col-md-3')
        . CsUi::field('Costing to projects', CsUi::select('costing_method', ['standard' => 'Charge-out rate per hour', 'actual' => 'Charge-out rate plus fuel', 'rental' => 'Through the rental bills'], fv('costing_method', $c), null, 'data-no-combo'), 'col-md-3')
        . CsUi::field('Rate per operating hour', CsUi::input('hourly_rate', fv('hourly_rate', $c), ['num' => true]), 'col-6 col-md-2') . CsUi::field('Rate per idle hour', CsUi::input('idle_rate', fv('idle_rate', $c), ['num' => true]), 'col-6 col-md-2')
        . CsUi::field('Rental basis', CsUi::select('rental_basis', ['hourly' => 'Hourly', 'daily' => 'Daily', 'monthly' => 'Monthly'], fv('rental_basis', $c), 'Not rented'), 'col-6 col-md-2')
        . CsUi::field('Rental rate', CsUi::input('rental_rate', fv('rental_rate', $c), ['num' => true]), 'col-6 col-md-3')
        . '</div></div></div><div class="card mb-3"><div class="card-header">Meter, service and documents</div><div class="card-body"><div class="row g-3">'
        . CsUi::field('Meter', CsUi::select('meter_unit', ['hours' => 'Hour meter', 'km' => 'Odometer (km)'], fv('meter_unit', $c), null, 'data-no-combo'), 'col-6 col-md-2')
        . CsUi::field('Current reading', CsUi::input('current_meter', fv('current_meter', $c), ['num' => true]), 'col-6 col-md-2') . CsUi::field('Next service at', CsUi::input('next_service_meter', fv('next_service_meter', $c), ['num' => true]), 'col-6 col-md-2')
        . CsUi::field('Fuel', CsUi::input('fuel_type', fv('fuel_type', $c), ['max' => 20, 'placeholder' => 'Diesel']), 'col-6 col-md-2')
        . CsUi::field('Insurance expires', CsUi::input('insurance_expiry', fv('insurance_expiry', $c), ['type' => 'date']), 'col-6 col-md-2') . CsUi::field('Inspection due', CsUi::input('inspection_due', fv('inspection_due', $c), ['type' => 'date']), 'col-6 col-md-2')
        . CsUi::field('Registration expires', CsUi::input('registration_expiry', fv('registration_expiry', $c), ['type' => 'date']), 'col-6 col-md-2') . CsUi::field('Notes', CsUi::input('notes', fv('notes', $c), ['max' => 255]), 'col-md-10')
        . '</div></div></div><div class="sticky-actions">' . save_buttons('Save equipment', url($pageKey, $e ? ['a' => 'view', 'id' => $id] : []), false) . '</div></form>';
    layout_end();
    return;
}

if ($a === 'view' && $e) {
    $use = DB::row("SELECT SUM(operating_hours) AS op, SUM(idle_hours) AS idle, SUM(breakdown_hours) AS bd, SUM(fuel_liters) AS fuel, SUM(charge_amount) AS charge FROM cs_equipment_logs WHERE equipment_id = :e AND status <> 'rejected' AND log_date >= :s",
        ['e' => $id, 's' => date('Y-m-d', strtotime('-90 days'))]);
    $util = ((float)$use['op'] + (float)$use['idle']) > 0 ? (float)$use['op'] / ((float)$use['op'] + (float)$use['idle'] + (float)$use['bd']) * 100 : 0;
    $soon = date('Y-m-d', strtotime('+30 days'));
    $docWarn = array_filter(['Insurance' => $e['insurance_expiry'], 'Inspection' => $e['inspection_due'], 'Registration' => $e['registration_expiry']], fn($d) => $d && $d <= $soon);
    layout_start($e['code'] . ' ' . $e['name'], ['module' => 'equip', 'sub' => h(CsUi::sub([CsEquip::TYPES[$e['equipment_type']] ?? '', $e['make_model'], $e['plate_serial'], ucfirst($e['ownership'])])) . ' ' . CsUi::state($e['status'])
        . ($e['current_project_id'] ? ' at ' . CsUi::projectLink((int)$e['current_project_id']) : ''),
        'actions' => cs_link($pageKey, ['a' => 'edit', 'id' => $id], 'Edit', 'btn-outline-secondary', 'pencil', 'edit') . ' ' . cs_link('equip.dispatch', ['a' => 'new', 'equipment' => $id], 'Dispatch', 'btn-primary', 'truck', 'create')
            . ' ' . page_link('fa.maintenance', [], 'Maintenance', 'btn-outline-secondary', 'wrench', 'view')]);
    echo '<div class="cs-kpis">' . CsUi::kpi('Operating hours, 90 days', number_format((float)$use['op'], 1), number_format((float)$use['idle'], 1) . ' idle, ' . number_format((float)$use['bd'], 1) . ' breakdown', 'key')
        . CsUi::kpi('Utilization', CsUi::pct($util), 'Operating over logged hours', $util < 60 && $use['op'] ? 'warn' : '') . CsUi::kpi('Charged to projects, 90 days', CsUi::amt($use['charge']), number_format((float)$use['fuel'], 1) . ' liters of fuel')
        . CsUi::kpi('Meter', number_format((float)$e['current_meter'], 1) . ' ' . h($e['meter_unit']), $e['next_service_meter'] !== null ? 'Service at ' . number_format((float)$e['next_service_meter'], 1) : '', $e['next_service_meter'] !== null && (float)$e['current_meter'] >= (float)$e['next_service_meter'] ? 'bad' : '')
        . ($docWarn ? CsUi::kpi('Documents', count($docWarn) . ' expiring', h(implode(', ', array_map(fn($k, $d) => $k . ' ' . fdate($d), array_keys($docWarn), $docWarn))), 'bad') : '') . '</div>';
    echo '<div class="cs-split"><div class="cs-stack">';
    $rows = [];
    foreach (DB::all("SELECT d.*, p.code FROM cs_equipment_dispatch d JOIN cs_projects p ON p.id = d.project_id WHERE d.equipment_id = :e ORDER BY d.date_from DESC LIMIT 20", ['e' => $id]) as $d) {
        $rows[] = ['<a href="' . h(url('equip.dispatch', ['a' => 'view', 'id' => $d['id']])) . '">' . h($d['dispatch_no']) . '</a>', h($d['code']), h(fdate($d['date_from']) . ' to ' . fdate($d['date_to'])), h((string)$d['purpose']), CsUi::state($d['status'])];
    }
    echo cs_table_card('Dispatches', ['Dispatch' => '', 'Project' => '', 'Dates' => '', 'Purpose' => '', 'Status' => ''], $rows, 'Never dispatched.');
    $rows = [];
    foreach (DB::all("SELECT l.*, p.code FROM cs_equipment_logs l JOIN cs_projects p ON p.id = l.project_id WHERE l.equipment_id = :e ORDER BY l.log_date DESC LIMIT 30", ['e' => $id]) as $l) {
        $rows[] = ['<a href="' . h(url('equip.logs', ['a' => 'view', 'id' => $l['id']])) . '">' . h($l['log_no']) . '</a>', h(fdate($l['log_date'])), h($l['code']), number_format((float)$l['operating_hours'], 1),
            number_format((float)$l['idle_hours'], 1), number_format((float)$l['fuel_liters'], 1), money($l['charge_amount']), CsUi::state($l['status'])];
    }
    echo cs_table_card('Usage', ['Log' => '', 'Date' => '', 'Project' => '', 'Operating' => 'num', 'Idle' => 'num', 'Fuel (L)' => 'num', 'Charge' => 'num', 'Status' => ''], $rows, 'No usage logged.');
    echo '</div><div class="cs-stack"><div class="card"><div class="card-body">' . CsUi::facts([
        'Costing' => h(['standard' => 'Charge-out rate', 'actual' => 'Charge-out rate plus fuel', 'rental' => 'Through the rental bills'][$e['costing_method']] ?? ''),
        'Rates' => money($e['hourly_rate']) . ' per operating hour, ' . money($e['idle_rate']) . ' per idle hour', 'Rental' => $e['vendor_name'] ? h($e['vendor_name']) . ', ' . money($e['rental_rate']) . ' ' . h((string)$e['rental_basis']) : '',
        'Fixed asset' => $e['fixed_asset_id'] ? '<a href="' . h(url('fa.register', ['a' => 'view', 'id' => $e['fixed_asset_id']])) . '">' . h((string)DB::value('SELECT asset_no FROM fixed_assets WHERE id = :f', ['f' => $e['fixed_asset_id']])) . '</a>' : '',
        'Operator' => h((string)$e['operator_name']), 'Insurance' => h(fdate($e['insurance_expiry'])), 'Inspection' => h(fdate($e['inspection_due'])), 'Registration' => h(fdate($e['registration_expiry'])), 'Notes' => h((string)$e['notes']),
    ]) . '</div></div>' . CsFiles::panel('equipment', $id, Access::can($pageKey, 'edit'), 'OR/CR, insurance and inspection certificates') . '</div></div>';
    layout_end();
    return;
}

$st = (string)g('status');
$type = (string)g('type');
layout_start('Equipment Register', ['actions' => cs_link($pageKey, ['a' => 'new'], 'New equipment', 'btn-primary', 'plus-lg', 'create')]);
echo '<form class="cs-toolbar" method="get"><input type="hidden" name="p" value="' . h($pageKey) . '">' . cs_search_field('Code, name, plate') . cs_status_field(CsEquip::TYPES, 'type', 'Type') . cs_status_field(CsEquip::STATUSES)
    . '<div><button class="btn btn-outline-secondary btn-sm">Show</button></div></form>';
[$w, $params] = cs_like((string)g('q'), ['e.code', 'e.name', 'e.plate_serial']);
if (isset(CsEquip::STATUSES[$st])) {
    $w .= ' AND e.status = :st';
    $params['st'] = $st;
}
if (isset(CsEquip::TYPES[$type])) {
    $w .= ' AND e.equipment_type = :t';
    $params['t'] = $type;
}
$rows = DB::all("SELECT e.*, p.code AS pcode, v.name AS vendor_name FROM cs_equipment e LEFT JOIN cs_projects p ON p.id = e.current_project_id LEFT JOIN vendors v ON v.id = e.vendor_id WHERE 1 = 1 $w ORDER BY e.code LIMIT 500", $params);
$soon = date('Y-m-d', strtotime('+30 days'));
$out = [];
$counts = array_fill_keys(array_keys(CsEquip::STATUSES), 0);
foreach ($rows as $r) {
    $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
    $flags = [];
    foreach (['insurance_expiry' => 'Insurance', 'inspection_due' => 'Inspection', 'registration_expiry' => 'Registration'] as $f => $l) {
        if ($r[$f] && $r[$f] <= $soon) {
            $flags[] = $l . ' ' . fdate($r[$f]);
        }
    }
    if ($r['next_service_meter'] !== null && (float)$r['current_meter'] >= (float)$r['next_service_meter']) {
        $flags[] = 'Service due';
    }
    $out[] = ['<a class="cs-main-link" href="' . h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) . '">' . h($r['code']) . '</a><span class="cs-sub">' . h($r['name']) . '</span>', h(CsEquip::TYPES[$r['equipment_type']] ?? ''),
        h(ucfirst($r['ownership'])) . ($r['vendor_name'] ? '<span class="cs-sub">' . h($r['vendor_name']) . '</span>' : ''), h((string)$r['pcode']), money($r['hourly_rate']),
        number_format((float)$r['current_meter'], 1), $flags ? '<span class="cs-bad">' . h(implode(', ', $flags)) . '</span>' : '', CsUi::state($r['status'])];
}
echo '<div class="cs-kpis">' . CsUi::kpi('Units', (string)count($rows), '', 'key') . CsUi::kpi('Deployed', (string)$counts['deployed'], '') . CsUi::kpi('Available', (string)$counts['available'], '', 'ok')
    . CsUi::kpi('Under repair', (string)$counts['under_repair'], '', $counts['under_repair'] ? 'warn' : '') . '</div>';
echo cs_table_card(count($rows) . ' units', ['Unit' => '', 'Type' => '', 'Ownership' => '', 'At project' => '', 'Rate / h' => 'num', 'Meter' => 'num', 'Attention' => '', 'Status' => ''], $out, 'No equipment yet.');
layout_end();
