<?php
declare(strict_types=1);

/**
 * Daily site reports: weather, manpower, equipment, materials, the work done on each activity, delays, safety,
 * inspections, instructions and photos. Approval adds the work done to the schedule's progress.
 */
$pageKey = 'site.daily';
$a = (string)g('a', 'list');
$id = gint('id');
$r = $id ? CsSite::get($id) : null;
if ($r) {
    CsProject::requireAccess((int)$r['project_id']);
}

handle_post(function () use ($pageKey, $id, $r) {
    if ($r && cs_handle_attach('daily_report', (int)$id, (int)$r['project_id'])) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if ($r && CsApproval::handlePost('daily_report', (int)$id, function () use ($r) {
        if (!in_array($r['status'], ['draft', 'returned'], true)) {
            fail('Only a draft report is submitted.');
        }
        CsApproval::submit('daily_report', (int)$r['id'], $r['report_no'] . ' ' . $r['project_code'] . ' ' . fdate($r['report_date']), (int)$r['project_id'], 0);
    })) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if (p('act') === 'save') {
        Access::require($pageKey, $id ? 'edit' : 'create');
        $nid = CsSite::save($_POST, cs_lines(), $id);
        $n = CsSite::get($nid);
        if (!empty($_FILES['photos']['name'][0])) {
            CsFiles::attach('photos', 'daily_report', $nid, (int)$n['project_id'], 'Site photo ' . fdate($n['report_date']));
        }
        if (p('then') === 'submit') {
            CsApproval::submit('daily_report', $nid, $n['report_no'] . ' ' . $n['project_code'] . ' ' . fdate($n['report_date']), (int)$n['project_id'], 0);
            flash('success', 'Report saved and submitted.');
        } else {
            flash('success', 'Report saved.');
        }
        cs_go($pageKey, ['a' => 'view', 'id' => $nid]);
    }
});

if ($a === 'new' || ($a === 'edit' && $r)) {
    Access::require($pageKey, $r ? 'edit' : 'create');
    $pid = $r ? (int)$r['project_id'] : (int)(gint('project') ?? CsProject::current() ?? 0);
    $date = $r ? $r['report_date'] : (gdate('date') ?: today());
    if (!$r && $pid && ($ex = DB::value('SELECT id FROM cs_daily_reports WHERE project_id = :p AND report_date = :d', ['p' => $pid, 'd' => $date]))) {
        cs_go($pageKey, ['a' => 'view', 'id' => $ex]);
    }
    $facts = $pid ? CsSite::dayFacts($pid, $date) : ['workers' => 0, 'hours' => 0, 'equipment' => '', 'materials' => ''];
    $c = $r ?: ['project_id' => $pid, 'report_date' => $date, 'weather_am' => 'sunny', 'weather_pm' => 'sunny', 'workers_count' => $facts['workers'], 'man_hours' => $facts['hours'] ?: '',
        'equipment_used' => $facts['equipment'], 'materials_used' => $facts['materials']];
    $lines = is_post() ? cs_lines() : ($r ? DB::all('SELECT * FROM cs_daily_report_lines WHERE report_id = :r ORDER BY id', ['r' => $id]) : []);
    $acts = $pid ? DB::all("SELECT a.id, a.wbs_code, a.name, a.unit, a.planned_qty, a.qty_done, a.progress_pct, a.cost_code_id FROM cs_activities a
                             WHERE a.project_id = :p AND a.id NOT IN (SELECT parent_id FROM cs_activities WHERE parent_id IS NOT NULL) AND a.is_milestone = 0 ORDER BY a.sort_order", ['p' => $pid]) : [];
    $ccOpts = CsCost::codeOptions();
    $actSel = function (string $name, $value) use ($acts): string {
        $o = '<select class="form-select form-select-sm" name="' . h($name) . '" data-fill="unit:unit,cc:cost_code_id" data-no-combo><option value="">Choose the activity</option>';
        foreach ($acts as $x) {
            $left = (float)$x['planned_qty'] > 0 ? qty((float)$x['planned_qty'] - (float)$x['qty_done']) . ' ' . $x['unit'] . ' left' : number_format(100 - (float)$x['progress_pct'], 1) . '% left';
            $o .= '<option value="' . (int)$x['id'] . '" data-unit="' . h((float)$x['planned_qty'] > 0 ? (string)$x['unit'] : '%') . '" data-cc="' . h((string)$x['cost_code_id']) . '"' . ((string)$value === (string)$x['id'] ? ' selected' : '') . '>'
                . h($x['wbs_code'] . ' ' . $x['name'] . ' (' . $left . ')') . '</option>';
        }
        return $o . '</select>';
    };
    $row = function (array $l, string $i) use ($actSel, $ccOpts): string {
        $n = fn($f) => 'lines[' . $i . '][' . $f . ']';
        return '<tr><td style="min-width:240px">' . $actSel($n('activity_id'), $l['activity_id'] ?? '') . '</td>'
            . '<td class="w-desc"><input class="form-control form-control-sm" name="' . $n('description') . '" value="' . h($l['description'] ?? '') . '" placeholder="Work done"></td>'
            . '<td class="w-sm"><input class="form-control form-control-sm text-end" name="' . $n('qty_done') . '" value="' . h(isset($l['qty_done']) && (float)$l['qty_done'] ? qty($l['qty_done']) : '') . '"></td>'
            . '<td class="w-sm"><input class="form-control form-control-sm" name="' . $n('unit') . '" value="' . h($l['unit'] ?? '') . '"></td>'
            . '<td style="width:70px"><input class="form-control form-control-sm text-end" type="number" min="0" name="' . $n('workers') . '" value="' . h((string)($l['workers'] ?? '')) . '"></td>'
            . '<td style="width:80px"><input class="form-control form-control-sm text-end" name="' . $n('man_hours') . '" value="' . h(isset($l['man_hours']) && (float)$l['man_hours'] ? (float)$l['man_hours'] : '') . '"></td>'
            . '<td style="min-width:150px">' . CsUi::select($n('cost_code_id'), $ccOpts, $l['cost_code_id'] ?? '', '', 'data-combo') . '</td>'
            . '<td class="grid-acts"><button type="button" class="btn btn-link btn-sm text-danger p-0" data-cs-del aria-label="Remove"><i class="bi bi-x-lg"></i></button></td></tr>';
    };
    layout_start($r ? 'Edit ' . $r['report_no'] : 'Daily site report', ['module' => 'site', 'sub' => 'Works offline: what you type stays on this device until it is sent.']);
    ?>
    <form method="post" enctype="multipart/form-data" data-cs-offline="dsr-<?= (int)$pid ?>-<?= h($date) ?>"<?= $r ? '' : ' data-cs-new' ?>><?= csrf_field() ?><input type="hidden" name="act" value="save">
    <div class="card mb-3"><div class="card-body"><div class="row g-3">
      <?= CsUi::field('Project', $r ? '<input type="hidden" name="project_id" value="' . $pid . '"><div class="pt-1">' . CsUi::projectLink($pid) . '</div>' : CsUi::projectSelect('project_id', $pid ?: '', ['required' => true, 'extra' => 'onchange="location.href=\'' . h(url($pageKey, ['a' => 'new'])) . '&project=\'+this.value"']), 'col-md-5') ?>
      <?= CsUi::field('Date', CsUi::input('report_date', fv('report_date', $c), ['type' => 'date', 'extra' => 'max="' . today() . '"']), 'col-6 col-md-2') ?>
      <?= CsUi::field('Weather, morning', CsUi::select('weather_am', CsSite::WEATHER, fv('weather_am', $c), 'Not noted', 'data-no-combo'), 'col-6 col-md-2') ?>
      <?= CsUi::field('Weather, afternoon', CsUi::select('weather_pm', CsSite::WEATHER, fv('weather_pm', $c), 'Not noted', 'data-no-combo'), 'col-6 col-md-3') ?>
      <?= CsUi::field('Site conditions', CsUi::input('site_conditions', fv('site_conditions', $c), ['max' => 255, 'placeholder' => 'Muddy access road, dry, flooded excavation']), 'col-md-6') ?>
      <?= CsUi::field('Workers on site', CsUi::input('workers_count', fv('workers_count', $c), ['type' => 'number', 'step' => '1']), 'col-6 col-md-2', $facts['workers'] ? 'Timesheets: ' . $facts['workers'] : '') ?>
      <?= CsUi::field('Man-hours', CsUi::input('man_hours', fv('man_hours', $c), ['num' => true]), 'col-6 col-md-2', $facts['hours'] ? 'Timesheets: ' . number_format($facts['hours'], 1) : '') ?>
      <?= CsUi::field('Delay hours', CsUi::input('delay_hours', fv('delay_hours', $c, '0'), ['num' => true]), 'col-6 col-md-2') ?>
    </div></div></div>
    <div class="card mb-3"><div class="card-header">Work done today</div>
      <div class="card-body pb-0"><p class="cs-note mb-2">For activities measured in percent, enter the percent done today as the quantity.</p></div>
      <div class="table-responsive"><table class="table table-sm cs-lines mb-0" id="dsrGrid" data-cs-lines data-min-rows="3"><thead><tr><th>Activity</th><th>Description</th><th class="num">Qty done</th><th>Unit</th><th class="num">Workers</th><th class="num">Man-hours</th><th>Cost code</th><th></th></tr></thead>
      <tbody><?php foreach ($lines as $i => $l) { echo $row($l, 'e' . $i); } ?></tbody></table></div>
      <template data-cs-row="dsrGrid"><?= $row([], '__i__') ?></template>
      <div class="card-body pt-2"><button type="button" class="btn btn-outline-secondary btn-sm" data-cs-add="dsrGrid"><i class="bi bi-plus"></i> Add work done</button></div></div>
    <div class="card mb-3"><div class="card-body"><div class="row g-3">
      <?= CsUi::field('Equipment used', CsUi::input('equipment_used', fv('equipment_used', $c), ['max' => 255]), 'col-md-6') ?>
      <?= CsUi::field('Materials used and delivered', CsUi::textarea('materials_used', fv('materials_used', $c), 2), 'col-md-6') ?>
      <?= CsUi::field('Delays and their causes', CsUi::textarea('delays', fv('delays', $c), 2), 'col-md-6') ?>
      <?= CsUi::field('Safety observations and incidents', CsUi::textarea('safety_observations', fv('safety_observations', $c), 2), 'col-md-6') ?>
      <?= CsUi::field('Inspections and tests', CsUi::textarea('inspections', fv('inspections', $c), 2), 'col-md-6') ?>
      <?= CsUi::field('Instructions received', CsUi::textarea('instructions', fv('instructions', $c), 2), 'col-md-6') ?>
      <?= CsUi::field('Issues and requests', CsUi::textarea('issues', fv('issues', $c), 2), 'col-md-6') ?>
      <?= CsUi::field('Remarks', CsUi::textarea('remarks', fv('remarks', $c), 2), 'col-md-6') ?>
      <?= CsUi::field('Photos', '<input class="form-control form-control-sm" type="file" name="photos[]" multiple accept="image/*">', 'col-12', 'On a phone this opens the camera or the gallery.') ?>
    </div></div></div>
    <div class="sticky-actions d-flex flex-wrap gap-2"><button class="btn btn-outline-primary">Save draft</button><button class="btn btn-primary" name="then" value="submit">Save and submit</button>
      <a class="btn btn-outline-secondary" href="<?= h(url($pageKey, $r ? ['a' => 'view', 'id' => $id] : [])) ?>">Cancel</a></div>
    </form>
    <?php
    layout_end();
    return;
}

if ($a === 'view' && $r) {
    $lines = DB::all('SELECT l.*, a.wbs_code, a.progress_pct FROM cs_daily_report_lines l LEFT JOIN cs_activities a ON a.id = l.activity_id WHERE l.report_id = :r ORDER BY l.id', ['r' => $id]);
    $facts = CsSite::dayFacts((int)$r['project_id'], $r['report_date']);
    $editable = in_array($r['status'], ['draft', 'returned'], true) && Access::can($pageKey, 'edit');
    layout_start($r['report_no'] . ' Daily site report', ['module' => 'site', 'sub' => CsUi::projectLink((int)$r['project_id']) . ', ' . h(date('l, M j, Y', strtotime($r['report_date']))) . ' ' . CsUi::state($r['status']),
        'actions' => ($editable ? cs_link($pageKey, ['a' => 'edit', 'id' => $id], 'Edit', 'btn-outline-secondary', 'pencil', 'edit') . ' ' : '') . '<button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>']);
    echo '<div class="cs-kpis">' . CsUi::kpi('Workers', (string)(int)$r['workers_count'], $facts['workers'] !== (int)$r['workers_count'] && $facts['workers'] ? '<span class="cs-warn">Timesheets show ' . $facts['workers'] . '</span>' : 'Matches the timesheets', 'key')
        . CsUi::kpi('Man-hours', number_format((float)$r['man_hours'], 1), $facts['hours'] ? 'Timesheets: ' . number_format($facts['hours'], 1) : '')
        . CsUi::kpi('Weather', h(CsSite::WEATHER[$r['weather_am'] ?: (string)$r['weather_pm']] ?? '') ?: 'Not noted',
            h(CsUi::sub([$r['weather_pm'] && $r['weather_pm'] !== $r['weather_am'] ? 'Afternoon: ' . (CsSite::WEATHER[$r['weather_pm']] ?? '') : '', (string)$r['site_conditions']])))
        . CsUi::kpi('Delay', number_format((float)$r['delay_hours'], 1) . ' h', '', (float)$r['delay_hours'] > 0 ? 'warn' : '') . '</div>';
    $rows = [];
    foreach ($lines as $l) {
        $rows[] = [h((string)$l['wbs_code']), h($l['description']), qty($l['qty_done']) . ' ' . h((string)$l['unit']), (int)$l['workers'], number_format((float)$l['man_hours'], 1),
            $l['activity_id'] ? CsUi::meter((float)$l['progress_pct']) : ''];
    }
    echo '<div class="cs-split"><div class="cs-stack">' . cs_table_card('Work done', ['WBS' => '', 'Description' => '', 'Done' => 'num', 'Workers' => 'num', 'Man-hours' => 'num', 'Activity progress now' => ''], $rows, 'No accomplishment recorded.');
    $notes = [];
    foreach (['equipment_used' => 'Equipment', 'materials_used' => 'Materials', 'delays' => 'Delays', 'safety_observations' => 'Safety', 'inspections' => 'Inspections and tests', 'instructions' => 'Instructions', 'issues' => 'Issues', 'remarks' => 'Remarks'] as $f => $label) {
        if (trim((string)$r[$f]) !== '') {
            $notes[$label] = nl2br(h($r[$f]));
        }
    }
    echo '<div class="card"><div class="card-body">' . ($notes ? CsUi::facts($notes) : '<p class="cs-note mb-0">No other notes.</p>') . '</div></div>';
    echo CsFiles::panel('daily_report', $id, Access::can($pageKey, 'edit') || Access::can($pageKey, 'create'), 'Site photos') . '</div><div class="cs-stack">';
    echo CsApproval::panel('daily_report', $id, Access::can($pageKey, 'edit') && in_array($r['status'], ['draft', 'returned'], true), 'Submit report');
    echo '<div class="card"><div class="card-body">' . CsUi::facts(['Prepared by' => h((string)$r['by_name']), 'Submitted' => h(fdatetime($r['submitted_at'])), 'Approved' => h(fdatetime($r['approved_at']))]) . '</div></div>';
    echo '</div></div>';
    layout_end();
    return;
}

// the list, with the days without a report
$pid = CsProject::current();
$from = gdate('from') ?: date('Y-m-d', strtotime('-14 days'));
$to = gdate('to') ?: today();
layout_start('Daily Site Reports', ['actions' => cs_link($pageKey, ['a' => 'new', 'project' => $pid], 'Today\'s report', 'btn-primary', 'plus-lg', 'create')]);
echo cs_filter_bar($pageKey, $pid, '<div><label class="form-label">From</label><input class="form-control form-control-sm" type="date" name="from" value="' . h($from) . '"></div><div><label class="form-label">To</label><input class="form-control form-control-sm" type="date" name="to" value="' . h($to) . '"></div>'
    . cs_status_field(['draft' => 'Draft', 'for_approval' => 'For approval', 'approved' => 'Approved', 'returned' => 'Returned']));
[$f, $params] = CsProject::filter('r.project_id');
$w = ' AND r.report_date BETWEEN :f AND :t';
$params += ['f' => $from, 't' => $to];
if ($pid) {
    $w .= ' AND r.project_id = :pid';
    $params['pid'] = $pid;
}
if ((string)g('status') !== '') {
    $w .= ' AND r.status = :st';
    $params['st'] = (string)g('status');
}
$rows = DB::all("SELECT r.*, p.code, u.full_name AS by_name, (SELECT COUNT(*) FROM cs_attachments x WHERE x.entity_type = 'daily_report' AND x.entity_id = r.id) AS photos
                   FROM cs_daily_reports r JOIN cs_projects p ON p.id = r.project_id LEFT JOIN users u ON u.id = r.created_by WHERE 1 = 1 $f $w ORDER BY r.report_date DESC, p.code LIMIT 500", $params);
if ($pid) {
    $have = array_flip(array_column($rows, 'report_date'));
    $miss = [];
    $p = CsProject::get($pid);
    if (in_array($p['status'], ['active', 'mobilization'], true)) {
        for ($d = max($from, (string)($p['actual_start_date'] ?: $p['start_date'])); $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            if (!isset($have[$d]) && date('N', strtotime($d)) < 7) {
                $miss[] = $d;
            }
        }
    }
    if ($miss) {
        echo '<div class="alert alert-warning">No report for ' . count($miss) . ' working day(s): ' . h(implode(', ', array_map(fn($d) => date('M j', strtotime($d)), array_slice($miss, -10)))) . (count($miss) > 10 ? ' and earlier' : '') . '.</div>';
    }
}
$out = [];
foreach ($rows as $x) {
    $out[] = ['<a class="cs-main-link" href="' . h(url($pageKey, ['a' => 'view', 'id' => $x['id']])) . '">' . h($x['report_no']) . '</a>', h(date('D, M j', strtotime($x['report_date']))), h($x['code']),
        h(CsSite::WEATHER[$x['weather_am']] ?? ''), (int)$x['workers_count'], number_format((float)$x['man_hours'], 1), (float)$x['delay_hours'] ? '<span class="cs-warn">' . number_format((float)$x['delay_hours'], 1) . ' h</span>' : '',
        (int)$x['photos'] ? (int)$x['photos'] . ' <i class="bi bi-image"></i>' : '', h((string)$x['by_name']), CsUi::state($x['status'])];
}
echo cs_table_card(count($rows) . ' reports', ['Report' => '', 'Date' => '', 'Project' => '', 'Weather' => '', 'Workers' => 'num', 'Man-hours' => 'num', 'Delay' => '', 'Photos' => '', 'By' => '', 'Status' => ''], $out, 'No reports in this period.');
layout_end();
