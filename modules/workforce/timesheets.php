<?php
declare(strict_types=1);

/**
 * Daily timesheets of a project: time in and out per worker, the activity and cost code worked on, overtime, absences
 * and holidays. Approval posts the labor cost to the project; overlapping entries and impossible hours are refused.
 */
$pageKey = 'labor.timesheets';
$a = (string)g('a', 'list');
$id = gint('id');
$t = $id ? CsLabor::getTs($id) : null;
if ($t) {
    CsProject::requireAccess((int)$t['project_id']);
}

handle_post(function () use ($pageKey, $id, $t) {
    if ($t && CsApproval::handlePost('timesheet', (int)$id, function () use ($t) {
        if (!in_array($t['status'], ['draft', 'rejected'], true)) {
            fail('Only a draft timesheet is submitted.');
        }
        CsApproval::submit('timesheet', (int)$t['id'], $t['ts_no'] . ' ' . $t['project_code'] . ' ' . fdate($t['work_date']), (int)$t['project_id'], (float)$t['total_cost']);
    })) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    $act = (string)p('act');
    if ($t && $act === 'reopen') {
        Access::require($pageKey, 'edit');
        CsLabor::reopenTs((int)$id, (string)p('reason'));
        flash('success', 'Reopened for correction. Its labor posting was voided; correct it and submit it again.');
        cs_go($pageKey, ['a' => 'edit', 'id' => $id]);
    }
    if ($act === 'save') {
        Access::require($pageKey, $id ? 'edit' : 'create');
        $nid = CsLabor::saveTs($_POST, cs_lines(), $id);
        if (p('then') === 'submit') {
            $n = CsLabor::getTs($nid);
            CsApproval::submit('timesheet', $nid, $n['ts_no'] . ' ' . $n['project_code'] . ' ' . fdate($n['work_date']), (int)$n['project_id'], (float)$n['total_cost']);
            flash('success', 'Timesheet saved and submitted for approval.');
        } else {
            flash('success', 'Timesheet saved.');
        }
        cs_go($pageKey, ['a' => 'view', 'id' => $nid]);
    }
});

if ($a === 'new' || ($a === 'edit' && $t)) {
    Access::require($pageKey, $t ? 'edit' : 'create');
    $pid = $t ? (int)$t['project_id'] : (int)(gint('project') ?? CsProject::current() ?? 0);
    $c = $t ?: ['project_id' => $pid, 'work_date' => gdate('date') ?: today(), 'shift' => 'day'];
    if (is_post()) {
        $lines = cs_lines();
    } elseif ($t) {
        $lines = DB::all('SELECT * FROM cs_timesheet_lines WHERE timesheet_id = :t ORDER BY id', ['t' => $id]);
    } elseif ($pid) {
        // the project's crew, with their usual shift
        $lines = [];
        foreach (DB::all("SELECT * FROM cs_workers WHERE status = 'active' AND assigned_project_id = :p ORDER BY full_name", ['p' => $pid]) as $wk) {
            $lines[] = ['worker_id' => $wk['id'], 'time_in' => $wk['shift_start'], 'time_out' => $wk['shift_end'], 'break_minutes' => $wk['break_minutes']];
        }
    } else {
        $lines = [];
    }
    $workers = DB::pairs("SELECT id, CONCAT(full_name, ' (', worker_no, ')') FROM cs_workers WHERE status = 'active' ORDER BY full_name");
    $acts = $pid ? DB::all("SELECT id, wbs_code, name, cost_code_id FROM cs_activities WHERE project_id = :p AND id NOT IN (SELECT parent_id FROM cs_activities WHERE parent_id IS NOT NULL) ORDER BY sort_order", ['p' => $pid]) : [];
    $ccOpts = CsCost::codeOptions();
    $actSel = function (string $name, $value) use ($acts): string {
        $o = '<select class="form-select form-select-sm" name="' . h($name) . '" data-fill="cc:cost_code_id" data-no-combo><option value="">None</option>';
        foreach ($acts as $x) {
            $o .= '<option value="' . (int)$x['id'] . '" data-cc="' . h((string)$x['cost_code_id']) . '"' . ((string)$value === (string)$x['id'] ? ' selected' : '') . '>' . h($x['wbs_code'] . ' ' . $x['name']) . '</option>';
        }
        return $o . '</select>';
    };
    $row = function (array $l, string $i) use ($workers, $ccOpts, $actSel): string {
        $n = fn($f) => 'lines[' . $i . '][' . $f . ']';
        return '<tr><td style="min-width:200px">' . CsUi::select($n('worker_id'), $workers, $l['worker_id'] ?? '', 'Choose', 'data-combo') . '</td>'
            . '<td style="min-width:170px">' . $actSel($n('activity_id'), $l['activity_id'] ?? '') . '</td>'
            . '<td style="min-width:150px">' . CsUi::select($n('cost_code_id'), $ccOpts, $l['cost_code_id'] ?? '', '', 'data-combo') . '</td>'
            . '<td><input class="form-control form-control-sm" type="time" name="' . $n('time_in') . '" value="' . h(substr((string)($l['time_in'] ?? ''), 0, 5)) . '"></td>'
            . '<td><input class="form-control form-control-sm" type="time" name="' . $n('time_out') . '" value="' . h(substr((string)($l['time_out'] ?? ''), 0, 5)) . '"></td>'
            . '<td style="width:70px"><input class="form-control form-control-sm text-end" type="number" step="5" min="0" name="' . $n('break_minutes') . '" value="' . h((string)($l['break_minutes'] ?? 60)) . '"></td>'
            . '<td style="width:70px"><input class="form-control form-control-sm text-end" name="' . $n('regular_hours') . '" value="' . h(isset($l['regular_hours']) && (float)$l['regular_hours'] && empty($l['time_in']) ? (float)$l['regular_hours'] : '') . '" placeholder="auto"></td>'
            . '<td style="width:70px"><input class="form-control form-control-sm text-end" name="' . $n('ot_hours') . '" value="' . h(isset($l['ot_hours']) && (float)$l['ot_hours'] && empty($l['time_in']) ? (float)$l['ot_hours'] : '') . '" placeholder="auto"></td>'
            . '<td class="text-center"><input class="form-check-input" type="checkbox" name="' . $n('is_absent') . '" value="1"' . (!empty($l['is_absent']) ? ' checked' : '') . ' aria-label="Absent"></td>'
            . '<td class="text-center"><input class="form-check-input" type="checkbox" name="' . $n('is_holiday') . '" value="1"' . (!empty($l['is_holiday']) ? ' checked' : '') . ' aria-label="Holiday"></td>'
            . '<td style="min-width:140px"><input class="form-control form-control-sm" name="' . $n('remarks') . '" value="' . h($l['remarks'] ?? '') . '"></td>'
            . '<td class="grid-acts"><button type="button" class="btn btn-link btn-sm text-danger p-0" data-cs-del aria-label="Remove"><i class="bi bi-x-lg"></i></button></td></tr>';
    };
    layout_start($t ? 'Edit ' . $t['ts_no'] : 'New timesheet', ['module' => 'labor', 'sub' => 'Hours come from the times in and out less the break; overtime is past ' . Settings::get('cs_regular_hours', '8') . ' hours. Leave the times blank to type the hours.']);
    ?>
    <form method="post" data-cs-offline="ts-<?= (int)$pid ?>-<?= (int)($id ?? 0) ?>"<?= $t ? '' : ' data-cs-new' ?>><?= csrf_field() ?><input type="hidden" name="act" value="save">
    <div class="card mb-3"><div class="card-body"><div class="row g-3">
      <?= CsUi::field('Project', $t ? '<input type="hidden" name="project_id" value="' . $pid . '"><div class="pt-1">' . CsUi::projectLink($pid) . '</div>' : CsUi::projectSelect('project_id', $pid ?: '', ['required' => true, 'extra' => 'onchange="location.href=\'' . h(url($pageKey, ['a' => 'new'])) . '&project=\'+this.value"']), 'col-md-5') ?>
      <?= CsUi::field('Work date', CsUi::input('work_date', fv('work_date', $c), ['type' => 'date', 'extra' => 'max="' . today() . '"']), 'col-6 col-md-2') ?>
      <?= CsUi::field('Shift', CsUi::select('shift', ['day' => 'Day', 'night' => 'Night'], fv('shift', $c), null, 'data-no-combo'), 'col-6 col-md-2') ?>
      <?= CsUi::field('Notes', CsUi::input('notes', fv('notes', $c), ['max' => 255]), 'col-md-3') ?>
    </div></div></div>
    <div class="card mb-3"><div class="card-header">Attendance<?= !$t && $pid ? '<span class="cs-note ms-2">The project\'s crew is listed; remove who did not report.</span>' : '' ?></div>
      <div class="table-responsive"><table class="table table-sm cs-lines mb-0" id="tsGrid" data-cs-lines data-min-rows="3"><thead><tr><th>Worker</th><th>Activity</th><th>Cost code</th><th>In</th><th>Out</th><th class="num">Break</th><th class="num">Reg. h</th><th class="num">OT h</th><th>Absent</th><th>Holiday</th><th>Remarks</th><th></th></tr></thead>
      <tbody><?php foreach ($lines as $i => $l) { echo $row($l, 'e' . $i); } ?></tbody></table></div>
      <template data-cs-row="tsGrid"><?= $row(['break_minutes' => 60], '__i__') ?></template>
      <div class="card-body pt-2"><button type="button" class="btn btn-outline-secondary btn-sm" data-cs-add="tsGrid"><i class="bi bi-plus"></i> Add a worker</button></div></div>
    <div class="sticky-actions d-flex flex-wrap gap-2"><button class="btn btn-outline-primary">Save draft</button><button class="btn btn-primary" name="then" value="submit">Save and submit for approval</button>
      <a class="btn btn-outline-secondary" href="<?= h(url($pageKey, $t ? ['a' => 'view', 'id' => $id] : [])) ?>">Cancel</a></div>
    </form>
    <?php
    layout_end();
    return;
}

if ($a === 'view' && $t) {
    $lines = DB::all('SELECT l.*, w.full_name, w.worker_no, w.vendor_id, a.wbs_code FROM cs_timesheet_lines l JOIN cs_workers w ON w.id = l.worker_id LEFT JOIN cs_activities a ON a.id = l.activity_id
                       WHERE l.timesheet_id = :t ORDER BY w.full_name', ['t' => $id]);
    $editable = in_array($t['status'], ['draft', 'rejected'], true) && Access::can($pageKey, 'edit');
    layout_start($t['ts_no'] . ' Timesheet', ['module' => 'labor', 'sub' => CsUi::projectLink((int)$t['project_id']) . ', ' . h(fdate($t['work_date']) . ', ' . $t['shift'] . ' shift') . ' ' . CsUi::state($t['status']),
        'actions' => $editable ? cs_link($pageKey, ['a' => 'edit', 'id' => $id], 'Edit', 'btn-outline-secondary', 'pencil', 'edit') : '']);
    $sum = ['reg' => 0.0, 'ot' => 0.0, 'cost' => 0.0, 'abs' => 0, 'late' => 0];
    $rows = [];
    foreach ($lines as $l) {
        $sum['reg'] += (float)$l['regular_hours'];
        $sum['ot'] += (float)$l['ot_hours'];
        $sum['cost'] += (float)$l['labor_cost'] + (float)$l['burden_cost'] + (float)$l['allowance'];
        $sum['abs'] += (int)$l['is_absent'];
        $sum['late'] += (int)$l['late_minutes'] ? 1 : 0;
        $rows[] = [h($l['full_name']) . '<span class="cs-sub">' . h($l['worker_no']) . ($l['vendor_id'] ? ', agency' : '') . '</span>', h((string)$l['wbs_code']) . '<span class="cs-sub">' . h(CsCost::codeLabel($l['cost_code_id'] ? (int)$l['cost_code_id'] : null)) . '</span>',
            (int)$l['is_absent'] ? '<span class="cs-bad">Absent</span>' : h(substr((string)$l['time_in'], 0, 5) . ($l['time_in'] ? ' to ' : '') . substr((string)$l['time_out'], 0, 5)) . ((int)$l['is_holiday'] ? '<span class="cs-sub">Holiday</span>' : ''),
            number_format((float)$l['regular_hours'], 2), number_format((float)$l['ot_hours'], 2), ((int)$l['late_minutes'] ? '<span class="cs-warn">' . (int)$l['late_minutes'] . ' late</span> ' : '') . ((int)$l['undertime_minutes'] ? '<span class="cs-warn">' . (int)$l['undertime_minutes'] . ' under</span>' : ''),
            money($l['hourly_rate']), money((float)$l['labor_cost'] + (float)$l['allowance']), money($l['burden_cost']), h((string)$l['remarks'] . ($l['correction_note'] ? ' Correction: ' . $l['correction_note'] : ''))];
    }
    echo '<div class="cs-kpis">' . CsUi::kpi('Workers', (string)(count($lines) - $sum['abs']), $sum['abs'] ? $sum['abs'] . ' absent' : 'All present', 'key') . CsUi::kpi('Man-hours', number_format($sum['reg'] + $sum['ot'], 2), number_format($sum['ot'], 2) . ' overtime')
        . CsUi::kpi('Labor cost', CsUi::amt($sum['cost']), 'Wages, allowances and burden') . CsUi::kpi('Late', (string)$sum['late'], 'Workers who came in after their shift start', $sum['late'] ? 'warn' : '') . '</div>';
    echo '<div class="cs-split is-wide-side"><div class="cs-stack">' . cs_table_card('Attendance', ['Worker' => '', 'Activity' => '', 'Time' => '', 'Regular' => 'num', 'Overtime' => 'num', 'Late / under (min)' => '', 'Rate / h' => 'num', 'Pay' => 'num', 'Burden' => 'num', 'Remarks' => ''], $rows)
        . '<div class="card"><div class="card-body">' . CsUi::facts(['Labor posting' => $t['txn_id'] ? CsUi::txnLink((int)$t['txn_id']) : ($t['status'] === 'posted' ? 'Nothing to post (agency workers only)' : 'Posted when approved'),
            'Payroll run' => $t['payroll_run_id'] ? '<a href="' . h(url('labor.payroll', ['a' => 'view', 'id' => $t['payroll_run_id']])) . '">' . h((string)DB::value('SELECT run_no FROM cs_payroll_runs WHERE id = :r', ['r' => $t['payroll_run_id']])) . '</a>' : 'Not paid yet',
            'Notes' => h((string)$t['notes'])]) . '</div></div></div><div class="cs-stack">';
    echo CsApproval::panel('timesheet', $id, Access::can($pageKey, 'edit') && in_array($t['status'], ['draft', 'rejected'], true));
    if (in_array($t['status'], ['approved', 'posted'], true) && Access::can($pageKey, 'edit') && !$t['payroll_run_id']) {
        echo '<div class="card"><div class="card-body">' . CsUi::confirmButton('tsReopen', 'Reopen for correction', 'Reopen ' . $t['ts_no'], 'Its labor posting is voided (kept with your reason) and it goes back to draft for correction and approval again.',
            '<input type="hidden" name="act" value="reopen">', 'btn-outline-secondary', 'arrow-counterclockwise', true) . '</div></div>';
    }
    echo cs_history('timesheet', $id) . '</div></div>';
    layout_end();
    return;
}

$pid = CsProject::current();
$from = gdate('from') ?: date('Y-m-d', strtotime('-14 days'));
$to = gdate('to') ?: today();
$st = (string)g('status');
layout_start('Attendance & Timesheets', ['actions' => cs_link($pageKey, ['a' => 'new', 'project' => $pid], 'New timesheet', 'btn-primary', 'plus-lg', 'create')]);
echo cs_filter_bar($pageKey, $pid, '<div><label class="form-label">From</label><input class="form-control form-control-sm" type="date" name="from" value="' . h($from) . '"></div><div><label class="form-label">To</label><input class="form-control form-control-sm" type="date" name="to" value="' . h($to) . '"></div>'
    . cs_status_field(['draft' => 'Draft', 'for_approval' => 'For approval', 'approved' => 'Approved', 'posted' => 'Posted', 'rejected' => 'Rejected']));
[$f, $params] = CsProject::filter('t.project_id');
$w = ' AND t.work_date BETWEEN :f AND :t';
$params += ['f' => $from, 't' => $to];
if ($pid) {
    $w .= ' AND t.project_id = :pid';
    $params['pid'] = $pid;
}
if ($st !== '') {
    $w .= ' AND t.status = :st';
    $params['st'] = $st;
}
$rows = DB::all("SELECT t.*, p.code, (SELECT COUNT(*) FROM cs_timesheet_lines l WHERE l.timesheet_id = t.id AND l.is_absent = 0) AS present, u.full_name AS by_name
                   FROM cs_timesheets t JOIN cs_projects p ON p.id = t.project_id LEFT JOIN users u ON u.id = t.prepared_by WHERE 1 = 1 $f $w ORDER BY t.work_date DESC, t.id DESC LIMIT 500", $params);
$out = [];
$tot = [0.0, 0.0];
foreach ($rows as $r) {
    $tot[0] += (float)$r['total_hours'];
    $tot[1] += (float)$r['total_cost'];
    $out[] = ['<a class="cs-main-link" href="' . h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) . '">' . h($r['ts_no']) . '</a>', h($r['code']), h(fdate($r['work_date'])), h(ucfirst($r['shift'])), (int)$r['present'],
        number_format((float)$r['total_hours'], 2), money($r['total_cost']), h((string)$r['by_name']), CsUi::state($r['status'])];
}
echo cs_table_card(count($rows) . ' timesheets', ['Timesheet' => '', 'Project' => '', 'Date' => '', 'Shift' => '', 'Present' => 'num', 'Man-hours' => 'num', 'Labor cost' => 'num', 'Prepared by' => '', 'Status' => ''], $out, 'No timesheets in this period.', '',
    $out ? '<tfoot><tr><td colspan="5">Total</td><td class="num">' . number_format($tot[0], 2) . '</td><td class="num">' . money($tot[1]) . '</td><td colspan="2"></td></tr></tfoot>' : '');
layout_end();
