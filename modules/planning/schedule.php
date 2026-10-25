<?php
declare(strict_types=1);

/**
 * The project schedule: the work breakdown with dates, weights and logic, its critical path, a Gantt chart against the
 * baseline, the progress update, a three-week look-ahead and earned value.
 */
$pageKey = 'plan.schedule';
$pid = CsProject::current();
$p = $pid ? CsProject::requireAccess($pid) : null;
$tab = (string)g('tab', 'gantt');
if (!in_array($tab, ['gantt', 'edit', 'progress', 'lookahead', 'evm'], true)) {
    $tab = 'gantt';
}

handle_post(function () use ($pageKey, $pid, $p) {
    if (!$p) {
        fail('Choose a project first.');
    }
    Access::require($pageKey, 'edit');
    $act = (string)p('act');
    if ($act === 'save') {
        $n = CsPlanning::saveActivities((int)$pid, cs_lines());
        $cpm = CsPlanning::computeCpm((int)$pid);
        flash('success', $n . ' activities saved. ' . ($cpm['finish'] ? 'Critical path worked out: ' . $cpm['critical'] . ' critical activit' . ($cpm['critical'] === 1 ? 'y' : 'ies') . ', finish ' . fdate($cpm['finish']) . '.' : ''));
        cs_go($pageKey, ['project' => $pid, 'tab' => 'edit']);
    }
    if ($act === 'cpm') {
        $cpm = CsPlanning::computeCpm((int)$pid);
        flash('success', $cpm['finish'] ? 'Critical path worked out: finish ' . fdate($cpm['finish']) . ', ' . $cpm['critical'] . ' critical activities.' : 'Give the activities planned dates first.');
        cs_go($pageKey, ['project' => $pid]);
    }
    if ($act === 'baseline') {
        if (trim((string)p('reason')) === '' && DB::value('SELECT id FROM cs_activities WHERE project_id = :p AND baseline_start IS NOT NULL LIMIT 1', ['p' => $pid])) {
            fail('The schedule already has a baseline: give the reason for re-baselining.');
        }
        CsPlanning::setBaseline((int)$pid);
        if (trim((string)p('reason')) !== '') {
            Audit::log('baseline', 'plan', 'schedule', (int)$pid, $p['code'] . ' re-baselined', ['page_key' => $pageKey, 'reason' => (string)p('reason')]);
        }
        flash('success', 'Baseline set from the planned dates. Progress is now measured against it.');
        cs_go($pageKey, ['project' => $pid]);
    }
    if ($act === 'progress') {
        CsPlanning::saveProgress((int)$pid, cs_lines());
        flash('success', 'Progress saved. Physical progress of the project: ' . CsUi::pct(CsProject::physicalPct((int)$pid)) . '.');
        cs_go($pageKey, ['project' => $pid, 'tab' => 'progress']);
    }
});

layout_start('Schedule & Gantt', ['sub' => $p ? CsUi::projectLink((int)$pid) . ', ' . h(CsUi::sub(['Start ' . fdate($p['start_date']), 'target finish ' . fdate($p['revised_end_date'] ?: $p['target_end_date'])])) : 'Choose a project',
    'actions' => $p && Access::can($pageKey, 'edit') ? '<form method="post" class="d-inline">' . csrf_field() . '<button class="btn btn-outline-secondary btn-sm" name="act" value="cpm"><i class="bi bi-diagram-3"></i> Work out the critical path</button></form> '
        . CsUi::confirmButton('baseModal', 'Set baseline', 'Set the schedule baseline', 'Today\'s planned dates become the baseline that progress and delays are measured against.', '<input type="hidden" name="act" value="baseline">', 'btn-outline-primary', 'flag', (bool)DB::value('SELECT id FROM cs_activities WHERE project_id = :p AND baseline_start IS NOT NULL LIMIT 1', ['p' => $pid])) : '']);
echo cs_filter_bar($pageKey, $pid, '<input type="hidden" name="tab" value="' . h($tab) . '">', false);
if (!$p) {
    echo '<div class="card">' . CsUi::empty('Choose a project to see its schedule.', '', 'calendar3-range') . '</div>';
    layout_end();
    return;
}
$acts = DB::all('SELECT a.* FROM cs_activities a WHERE a.project_id = :p ORDER BY a.sort_order, a.id', ['p' => $pid]);
$hasKids = array_flip(array_filter(array_map(fn($a) => (int)$a['parent_id'], $acts)));
$depsBy = [];
$wbsById = array_column($acts, 'wbs_code', 'id');
foreach ($acts ? DB::all('SELECT d.* FROM cs_activity_deps d JOIN cs_activities a ON a.id = d.activity_id WHERE a.project_id = :p ORDER BY d.id', ['p' => $pid]) : [] as $d) {
    $depsBy[(int)$d['activity_id']][] = ($wbsById[$d['predecessor_id']] ?? '?') . ($d['dep_type'] !== 'FS' ? $d['dep_type'] : '') . ((int)$d['lag_days'] ? ($d['lag_days'] > 0 ? '+' : '') . (int)$d['lag_days'] : '');
}
$phys = CsProject::physicalPct((int)$pid);
$plan = CsProject::plannedPct((int)$pid);
$finish = $acts ? max(array_filter(array_column($acts, 'planned_finish')) ?: [null]) : null;
$target = $p['revised_end_date'] ?: $p['target_end_date'];
$critical = count(array_filter($acts, fn($a) => (int)$a['is_critical'] && !isset($hasKids[(int)$a['id']])));
$late = array_filter($acts, fn($a) => !isset($hasKids[(int)$a['id']]) && $a['planned_finish'] && $a['planned_finish'] < today() && (float)$a['progress_pct'] < 100);
echo '<div class="cs-kpis">' . CsUi::kpi('Physical progress', CsUi::pct($phys), $plan !== null ? 'Planned by today: ' . CsUi::pct($plan) : 'No baseline yet', $plan !== null && $phys + 5 < $plan ? 'bad' : ($plan !== null && $phys < $plan ? 'warn' : 'key'))
    . CsUi::kpi('Scheduled finish', $finish ? h(fdate($finish)) : 'Not planned', $target ? 'Contract finish ' . fdate($target) : '', $finish && $target && $finish > $target ? 'bad' : '')
    . CsUi::kpi('Critical activities', (string)$critical, 'Zero float: any delay moves the finish')
    . CsUi::kpi('Activities past their finish', (string)count($late), count($late) ? 'Not yet 100% done' : 'None', count($late) ? 'warn' : 'ok') . '</div>';
echo CsUi::tabs(['gantt' => 'Gantt chart', 'edit' => 'Activities and logic', 'progress' => 'Update progress', 'lookahead' => 'Three-week look-ahead', 'evm' => 'Earned value'], $tab,
    fn($k) => url($pageKey, ['project' => $pid, 'tab' => $k]));

$canEdit = Access::can($pageKey, 'edit');

// ---------------------------------------------------------------- Gantt
if ($tab === 'gantt') {
    if (!$acts) {
        echo '<div class="card">' . CsUi::empty('No activities yet. Build the work breakdown on the "Activities and logic" tab.', $canEdit ? cs_link($pageKey, ['project' => $pid, 'tab' => 'edit'], 'Add activities', 'btn-primary', 'plus-lg', 'edit') : '', 'bar-chart-steps') . '</div>';
        layout_end();
        return;
    }
    $dates = [];
    foreach ($acts as $a) {
        foreach (['planned_start', 'planned_finish', 'baseline_start', 'baseline_finish', 'actual_start', 'actual_finish'] as $f) {
            if ($a[$f]) {
                $dates[] = $a[$f];
            }
        }
    }
    foreach ([$p['start_date'], $target] as $d) {
        if ($d) {
            $dates[] = $d;
        }
    }
    if (!$dates) {
        echo '<div class="card">' . CsUi::empty('The activities have no dates yet.', '', 'calendar') . '</div>';
        layout_end();
        return;
    }
    $d0 = CsPlanning::dayIndex(min($dates)) - 3;
    $d1 = CsPlanning::dayIndex(max($dates)) + 4;
    $span = $d1 - $d0;
    $zoom = (string)g('zoom', $span <= 100 ? 'day' : ($span <= 420 ? 'week' : 'month'));
    $px = ['day' => 18, 'week' => 6, 'month' => 2.2][$zoom] ?? 6;
    $w = (int)ceil($span * $px);
    $x = fn(string $d) => round((CsPlanning::dayIndex($d) - $d0) * $px, 1);
    // month header and grid lines
    $head = '';
    $grid = '';
    $m = gmdate('Y-m-01', $d0 * 86400);
    while (CsPlanning::dayIndex($m) < $d1) {
        $next = gmdate('Y-m-01', strtotime($m . ' +1 month UTC'));
        $l = max(0, $x($m));
        $r = min($w, $x($next));
        if ($r > $l) {
            $head .= '<span class="g-month" style="position:absolute;top:0;bottom:0;left:' . $l . 'px;width:' . ($r - $l) . 'px;display:flex;align-items:center">' . h(date($r - $l > 60 ? 'M Y' : 'M', strtotime($m))) . '</span>';
            $grid .= '<span class="g-grid" style="left:' . $l . 'px"></span>';
        }
        $m = $next;
    }
    $todayX = CsPlanning::dayIndex(today()) >= $d0 && CsPlanning::dayIndex(today()) <= $d1 ? '<span class="g-today" style="left:' . $x(today()) . 'px" title="Today"></span>' : '';
    echo '<div class="d-flex flex-wrap gap-2 align-items-center mb-2"><span class="cs-note">Scale</span>';
    foreach (['day' => 'Days', 'week' => 'Weeks', 'month' => 'Months'] as $k => $label) {
        echo '<a class="btn btn-sm ' . ($zoom === $k ? 'btn-primary' : 'btn-outline-secondary') . '" href="' . h(url($pageKey, ['project' => $pid, 'zoom' => $k])) . '">' . $label . '</a>';
    }
    echo '<button type="button" class="btn btn-sm btn-outline-secondary ms-auto" onclick="window.print()"><i class="bi bi-printer"></i> Print</button></div>';
    echo '<div class="cs-gantt"><table><thead><tr><th class="g-name">Activity</th><th class="g-time" style="min-width:' . $w . 'px;width:' . $w . 'px">' . $head . '</th></tr></thead><tbody>';
    foreach ($acts as $a) {
        $isSum = isset($hasKids[(int)$a['id']]);
        $isLate = !$isSum && $a['planned_finish'] && $a['planned_finish'] < today() && (float)$a['progress_pct'] < 100;
        $depth = substr_count($a['wbs_code'], '.');
        $bar = '';
        if ($a['planned_start']) {
            $s = $a['planned_start'];
            $f = $a['planned_finish'] ?: $s;
            $tip = $a['wbs_code'] . ' ' . $a['name'] . ': ' . fdate($s) . ' to ' . fdate($f) . ', ' . number_format((float)$a['progress_pct'], 1) . '% done'
                . ($a['total_float'] !== null ? ', float ' . (int)$a['total_float'] . ' d' : '') . ($a['baseline_start'] ? ', baseline ' . fdate($a['baseline_start']) . ' to ' . fdate($a['baseline_finish']) : '');
            if ((int)$a['is_milestone']) {
                $bar .= '<span class="g-ms" style="left:' . $x($s) . 'px" title="' . h($tip) . '"></span>';
            } else {
                $left = $x($s);
                $width = max(3, $x($f) + $px - $left);
                $bar .= '<span class="g-bar' . ((int)$a['is_critical'] && !$isSum ? ' is-critical' : '') . '" style="left:' . $left . 'px;width:' . $width . 'px' . ($isSum ? ';height:8px;top:13px;background:#14263a' : '') . '" title="' . h($tip) . '">'
                    . '<span class="g-done" style="width:' . min(100, (float)$a['progress_pct']) . '%"></span></span>';
            }
        }
        if ($a['baseline_start'] && $a['baseline_finish'] && !(int)$a['is_milestone']) {
            $bar .= '<span class="g-base" style="left:' . $x($a['baseline_start']) . 'px;width:' . max(2, $x($a['baseline_finish']) + $px - $x($a['baseline_start'])) . 'px"></span>';
        }
        echo '<tr class="' . trim(($isSum ? 'is-summary ' : '') . ($isLate ? 'is-late' : '')) . '"><td class="g-name" title="' . h($a['wbs_code'] . ' ' . $a['name']) . '"><span style="padding-left:' . ($depth * 14) . 'px">'
            . '<span class="text-muted me-1">' . h($a['wbs_code']) . '</span>' . h($a['name']) . '</span></td><td class="g-time">' . $grid . $todayX . $bar . '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<div class="cs-legend"><span>Planned</span><span class="l-crit">Critical path</span><span class="l-done">Done</span><span class="l-base">Baseline</span><span class="l-ms">Milestone</span><span class="l-today">Today</span></div>';
    layout_end();
    return;
}

// ---------------------------------------------------------------- edit activities
if ($tab === 'edit') {
    if (!$canEdit) {
        echo '<div class="alert alert-info">You can view the schedule; changing it needs the Edit permission.</div>';
    }
    $ccOpts = CsCost::codeOptions();
    $dis = $canEdit ? '' : ' disabled';
    $row = function (array $a, string $i) use ($ccOpts, $depsBy, $dis): string {
        $n = fn($f) => 'lines[' . $i . '][' . $f . ']';
        $dur = $a && $a['planned_start'] && $a['planned_finish'] ? CsPlanning::dayIndex($a['planned_finish']) - CsPlanning::dayIndex($a['planned_start']) + 1 : '';
        $num = fn($v) => $v === null || $v === '' || (float)$v == 0.0 ? '' : rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.');
        return '<tr><td class="w-sm"><input type="hidden" name="' . $n('id') . '" value="' . h($a['id'] ?? '') . '"><input class="form-control form-control-sm" name="' . $n('wbs_code') . '" value="' . h($a['wbs_code'] ?? '') . '" placeholder="1.1"' . $dis . '></td>'
            . '<td class="w-desc"><input class="form-control form-control-sm" name="' . $n('name') . '" value="' . h($a['name'] ?? '') . '"' . $dis . '></td>'
            . '<td style="min-width:170px">' . CsUi::select($n('cost_code_id'), $ccOpts, $a['cost_code_id'] ?? '', '', 'data-combo' . $dis) . '</td>'
            . '<td><input class="form-control form-control-sm" type="date" name="' . $n('planned_start') . '" value="' . h($a['planned_start'] ?? '') . '" data-ds' . $dis . '></td>'
            . '<td><input class="form-control form-control-sm" type="date" name="' . $n('planned_finish') . '" value="' . h($a['planned_finish'] ?? '') . '" data-df' . $dis . '></td>'
            . '<td style="width:70px"><input class="form-control form-control-sm text-end" type="number" min="0" step="1" name="' . $n('duration') . '" value="' . h((string)$dur) . '" data-dd' . $dis . '></td>'
            . '<td style="width:80px"><input class="form-control form-control-sm text-end" name="' . $n('weight_pct') . '" value="' . h($num($a['weight_pct'] ?? '')) . '" data-sum="weight"' . $dis . '></td>'
            . '<td style="width:90px"><input class="form-control form-control-sm text-end" name="' . $n('planned_qty') . '" value="' . h($num($a['planned_qty'] ?? '')) . '"' . $dis . '></td>'
            . '<td style="width:70px"><input class="form-control form-control-sm" name="' . $n('unit') . '" value="' . h($a['unit'] ?? '') . '"' . $dis . '></td>'
            . '<td style="width:90px"><input class="form-control form-control-sm text-end" name="' . $n('budget_hours') . '" value="' . h($num($a['budget_hours'] ?? '')) . '"' . $dis . '></td>'
            . '<td style="min-width:120px"><input class="form-control form-control-sm" name="' . $n('predecessors') . '" value="' . h(implode(', ', $depsBy[(int)($a['id'] ?? 0)] ?? [])) . '" placeholder="1.1, 1.2SS+2"' . $dis . '></td>'
            . '<td class="text-center"><input class="form-check-input" type="checkbox" name="' . $n('is_milestone') . '" value="1"' . (!empty($a['is_milestone']) ? ' checked' : '') . $dis . ' aria-label="Milestone"></td>'
            . '<td class="grid-acts">' . ($dis ? '' : '<button type="button" class="btn btn-link btn-sm text-danger p-0" data-cs-del aria-label="Remove"><i class="bi bi-x-lg"></i></button>') . '</td></tr>';
    };
    echo '<form method="post">' . csrf_field() . '<input type="hidden" name="act" value="save"><div class="card mb-3"><div class="card-header">Work breakdown</div>'
        . '<div class="card-body pb-0"><p class="cs-note mb-2">The WBS code sets the outline: 2.1 and 2.2 sit under 2, which becomes a summary row. Weights are each activity\'s share of the whole job (they should add up to 100). '
        . 'Predecessors use WBS codes with an optional type and lag in days: 1.2 (finish-to-start), 1.2SS, 1.2FF, 1.2FS+3. Saving works out the critical path; dates move later when the logic needs it.</p></div>'
        . '<div class="table-responsive"><table class="table table-sm cs-lines mb-0" id="actGrid" data-cs-lines data-min-rows="' . ($canEdit ? 3 : 0) . '"><thead><tr><th>WBS</th><th>Activity</th><th>Cost code</th><th>Start</th><th>Finish</th><th class="num">Days</th><th class="num">Weight %</th><th class="num">Qty</th><th>Unit</th><th class="num">Man-hours</th><th>Predecessors</th><th>Milestone</th><th></th></tr></thead><tbody>';
    foreach ($acts as $i => $a) {
        echo $row($a, 'e' . $i);
    }
    echo '</tbody><tfoot><tr><td colspan="6" class="text-end">Total weight</td><td class="num" data-cs-sum="weight">0.00</td><td colspan="6"></td></tr></tfoot></table></div>'
        . '<template data-cs-row="actGrid">' . $row([], '__i__') . '</template>'
        . ($canEdit ? '<div class="card-body pt-2 d-flex flex-wrap gap-2"><button type="button" class="btn btn-outline-secondary btn-sm" data-cs-add="actGrid"><i class="bi bi-plus"></i> Add an activity</button>'
            . '<button type="button" class="btn btn-outline-secondary btn-sm" data-cs-add="actGrid" data-count="5">Add 5</button><button class="btn btn-primary btn-sm ms-auto">Save and work out the critical path</button></div>' : '')
        . '</div></form>';
    layout_end();
    return;
}

// ---------------------------------------------------------------- progress
if ($tab === 'progress') {
    $leaf = array_filter($acts, fn($a) => !isset($hasKids[(int)$a['id']]));
    if (!$leaf) {
        echo '<div class="card">' . CsUi::empty('No activities to update yet.', '', 'graph-up') . '</div>';
        layout_end();
        return;
    }
    echo '<form method="post">' . csrf_field() . '<input type="hidden" name="act" value="progress"><div class="card"><div class="card-header">Progress as of today</div>'
        . '<div class="card-body pb-0"><p class="cs-note mb-2">Approved daily site reports add their quantities here by themselves. Activities with a planned quantity take their percent from the quantity done; the others take the percent you enter.</p></div>'
        . '<div class="table-responsive"><table class="table table-sm cs-lines mb-0"><thead><tr><th>Activity</th><th>Planned</th><th class="num">Weight</th><th class="num">Qty done</th><th class="num">Percent</th><th>Actual start</th><th>Actual finish</th><th>On hold</th></tr></thead><tbody>';
    $i = 0;
    foreach ($leaf as $a) {
        $n = fn($f) => 'lines[' . $i . '][' . $f . ']';
        $dis = $canEdit ? '' : ' disabled';
        $hasQty = (float)$a['planned_qty'] > 0;
        echo '<tr' . ((float)$a['progress_pct'] < 100 && $a['planned_finish'] && $a['planned_finish'] < today() ? ' class="table-warning"' : '') . '><td><input type="hidden" name="' . $n('id') . '" value="' . (int)$a['id'] . '"><span class="text-muted">' . h($a['wbs_code']) . '</span> ' . h($a['name'])
            . ((int)$a['is_critical'] ? ' <span class="cs-state is-bad">Critical</span>' : '') . '</td>'
            . '<td class="text-nowrap small">' . h(fdate($a['planned_start'])) . ' to ' . h(fdate($a['planned_finish'])) . '</td><td class="num">' . number_format((float)$a['weight_pct'], 2) . '</td>'
            . '<td style="width:150px">' . ($hasQty ? '<div class="input-group input-group-sm"><input class="form-control text-end" name="' . $n('qty_done') . '" value="' . h(qty($a['qty_done'])) . '"' . $dis . '><span class="input-group-text">of ' . h(qty($a['planned_qty']) . ' ' . $a['unit']) . '</span></div>' : '<span class="text-muted small">No quantity</span>') . '</td>'
            . '<td style="width:100px"><input class="form-control form-control-sm text-end" name="' . $n('progress_pct') . '" value="' . h(number_format((float)$a['progress_pct'], 2, '.', '')) . '"' . ($hasQty ? ' readonly tabindex="-1"' : '') . $dis . '></td>'
            . '<td><input class="form-control form-control-sm" type="date" name="' . $n('actual_start') . '" value="' . h($a['actual_start'] ?? '') . '"' . $dis . '></td>'
            . '<td><input class="form-control form-control-sm" type="date" name="' . $n('actual_finish') . '" value="' . h($a['actual_finish'] ?? '') . '"' . $dis . '></td>'
            . '<td class="text-center"><input class="form-check-input" type="checkbox" name="' . $n('status') . '" value="on_hold"' . ($a['status'] === 'on_hold' ? ' checked' : '') . $dis . ' aria-label="On hold"></td></tr>';
        $i++;
    }
    echo '</tbody></table></div>' . ($canEdit ? '<div class="card-body pt-2 text-end"><button class="btn btn-primary btn-sm">Save progress</button></div>' : '') . '</div></form>';
    layout_end();
    return;
}

// ---------------------------------------------------------------- look-ahead
if ($tab === 'lookahead') {
    $from = today();
    $to = date('Y-m-d', strtotime('+21 days'));
    $rows = [];
    foreach ($acts as $a) {
        if (isset($hasKids[(int)$a['id']]) || (float)$a['progress_pct'] >= 100 || !$a['planned_start']) {
            continue;
        }
        if ($a['planned_start'] <= $to && ($a['planned_finish'] ?: $a['planned_start']) >= date('Y-m-d', strtotime('-60 days'))) {
            $startsLate = $a['planned_start'] < $from && (float)$a['progress_pct'] <= 0;
            $rows[] = ['_class' => (int)$a['is_critical'] ? 'table-danger' : '', h($a['wbs_code']), h($a['name']) . ($a['resource_notes'] ? '<span class="cs-sub">' . h($a['resource_notes']) . '</span>' : ''),
                '<span class="' . ($startsLate ? 'cs-bad' : '') . '">' . h(fdate($a['planned_start'])) . '</span>', h(fdate($a['planned_finish'])), CsUi::meter((float)$a['progress_pct']),
                $a['total_float'] !== null ? (int)$a['total_float'] . ' d' : '', h(implode(', ', $depsBy[(int)$a['id']] ?? [])), CsUi::state($startsLate ? 'late' : $a['status'], $startsLate ? 'Should have started' : null)];
        }
    }
    echo cs_table_card('Work to do from ' . fdate($from) . ' to ' . fdate($to), ['WBS' => '', 'Activity' => '', 'Start' => '', 'Finish' => '', 'Progress' => '', 'Float' => 'num', 'Waits for' => '', 'Status' => ''], $rows,
        'Nothing is planned in the next three weeks.', '<button type="button" class="btn btn-outline-secondary btn-sm ms-auto no-print" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>');
    echo '<p class="cs-note mt-2">Rows in red are on the critical path. Use this list for the weekly site coordination meeting: materials, crews and equipment for these activities should be requested now.</p>';
    layout_end();
    return;
}

// ---------------------------------------------------------------- earned value
$e = CsPlanning::evm((int)$pid);
$f2 = fn($v) => $v === null ? 'Not available' : number_format($v, 2);
echo '<div class="cs-kpis">'
    . CsUi::kpi('Budget at completion (BAC)', CsUi::amt($e['bac']), 'The approved budget in force', 'key')
    . CsUi::kpi('Planned value (PV)', CsUi::amt($e['pv']), $e['planned_pct'] !== null ? CsUi::pct($e['planned_pct']) . ' planned by today' : 'No baseline')
    . CsUi::kpi('Earned value (EV)', CsUi::amt($e['ev']), CsUi::pct($e['physical_pct']) . ' physically done')
    . CsUi::kpi('Actual cost (AC)', CsUi::amt($e['ac']), 'From the books, to date') . '</div>';
echo '<div class="cs-kpis">'
    . CsUi::kpi('Cost performance (CPI)', $f2($e['cpi']), $e['cpi'] === null ? '' : ($e['cpi'] < 1 ? 'Spending more than the work earned' : 'Within the value earned'), $e['cpi'] !== null && $e['cpi'] < 0.95 ? 'bad' : ($e['cpi'] !== null && $e['cpi'] < 1 ? 'warn' : 'ok'))
    . CsUi::kpi('Schedule performance (SPI)', $f2($e['spi']), $e['spi'] === null ? '' : ($e['spi'] < 1 ? 'Behind the baseline' : 'On or ahead of the baseline'), $e['spi'] !== null && $e['spi'] < 0.9 ? 'bad' : ($e['spi'] !== null && $e['spi'] < 1 ? 'warn' : 'ok'))
    . CsUi::kpi('Estimate at completion (BAC / CPI)', CsUi::amt($e['eac']), 'Variance at completion ' . money($e['vac']), $e['vac'] < 0 ? 'bad' : '')
    . CsUi::kpi('To-complete index (TCPI)', $f2($e['tcpi']), 'Efficiency needed on the rest to finish on budget') . '</div>';
echo '<div class="card"><div class="card-body">' . CsUi::facts([
    'Cost variance (EV less AC)' => '<span class="' . ($e['cv'] < 0 ? 'cs-bad' : 'cs-good') . '">' . money($e['cv']) . '</span>',
    'Schedule variance (EV less PV)' => '<span class="' . ($e['sv'] < 0 ? 'cs-bad' : 'cs-good') . '">' . money($e['sv']) . '</span>',
    'Estimate to complete' => money($e['etc']),
]) . '<p class="cs-note mt-3 mb-0">Earned value uses the approved budget in force as BAC, the weighted physical progress of the schedule as percent complete, the baseline dates for planned value and the costs posted to the project\'s class for actual cost.</p></div></div>';
layout_end();
