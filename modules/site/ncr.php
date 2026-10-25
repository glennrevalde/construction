<?php
declare(strict_types=1);

/** Non-conformances: what is wrong, why, the corrective and preventive action, the rework cost, verification and closure. */
$pageKey = 'site.ncr';
$a = (string)g('a', 'list');
$id = gint('id');
$n = $id ? DB::row('SELECT n.*, p.code AS pcode, u.full_name AS by_name FROM cs_ncrs n JOIN cs_projects p ON p.id = n.project_id LEFT JOIN users u ON u.id = n.created_by WHERE n.id = :id', ['id' => $id]) : null;
if ($n) {
    CsProject::requireAccess((int)$n['project_id']);
}
$statuses = ['open' => 'Open', 'in_progress' => 'Corrective action in progress', 'verified' => 'Verified', 'closed' => 'Closed'];

handle_post(function () use ($pageKey, $id, $n, $statuses) {
    if ($n && cs_handle_attach('ncr', (int)$id, (int)$n['project_id'])) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    $act = (string)p('act');
    if ($n && $act === 'status') {
        Access::require($pageKey, 'edit');
        $st = (string)p('status');
        if (!isset($statuses[$st])) {
            fail('Choose the status.');
        }
        if (in_array($st, ['verified', 'closed'], true) && trim((string)$n['corrective_action']) === '') {
            fail('Record the corrective action before verifying or closing.');
        }
        if ($st === 'verified' && (int)$n['created_by'] === (int)Auth::id() && !Access::isAdmin()) {
            fail('Someone other than the person who raised it verifies the correction.');
        }
        if ($st === 'closed' && $n['status'] !== 'verified') {
            fail('A non-conformance is closed after its correction is verified.');
        }
        DB::update('cs_ncrs', ['status' => $st, 'closed_date' => $st === 'closed' ? today() : null], 'id = :id', ['id' => $id]);
        Audit::log($st === 'verified' ? 'verify' : 'status', 'site', 'ncr', (int)$id, $n['ncr_no'], ['page_key' => $pageKey, 'reason' => $statuses[$st] . (p('reason') ? ': ' . p('reason') : '')]);
        flash('success', 'Status changed.');
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    Access::require($pageKey, $id ? 'edit' : 'create');
    $pid = $n ? (int)$n['project_id'] : (int)p('project_id');
    CsProject::requireAccess($pid);
    $row = ['title' => mb_substr(trim((string)p('title')), 0, 200), 'description' => trim((string)p('description')) ?: null, 'raised_date' => pdate('raised_date') ?: today(),
        'responsible_party' => mb_substr(trim((string)p('responsible_party')), 0, 150) ?: null, 'root_cause' => trim((string)p('root_cause')) ?: null,
        'corrective_action' => trim((string)p('corrective_action')) ?: null, 'preventive_action' => trim((string)p('preventive_action')) ?: null, 'rework_cost' => to_num(p('rework_cost')),
        'due_date' => pdate('due_date'), 'activity_id' => pint('activity_id'), 'cost_code_id' => pint('cost_code_id'), 'inspection_id' => pint('inspection_id')];
    if ($row['title'] === '') {
        fail('Enter what does not conform.');
    }
    if ($n) {
        DB::update('cs_ncrs', $row + ['status' => $n['status'] === 'open' && $row['corrective_action'] ? 'in_progress' : $n['status']], 'id = :id', ['id' => $id]);
        Audit::log('edit', 'site', 'ncr', (int)$id, $n['ncr_no'], ['page_key' => $pageKey, 'amount' => $row['rework_cost']]);
    } else {
        $id = DB::insert('cs_ncrs', $row + ['ncr_no' => Seq::next('cs_ncr'), 'project_id' => $pid, 'status' => 'open', 'created_by' => Auth::id()]);
        Audit::log('create', 'site', 'ncr', $id, $row['title'], ['page_key' => $pageKey]);
        $p = CsProject::get($pid);
        CsNotify::send(array_filter([(int)$p['pm_user_id'], (int)$p['engineer_user_id']]), 'ncr', 'Non-conformance raised on ' . $p['code'], $row['title'], url($pageKey, ['a' => 'view', 'id' => $id]), $pid);
    }
    flash('success', 'Saved.');
    cs_go($pageKey, ['a' => 'view', 'id' => $id]);
});

if ($a === 'new' || ($a === 'edit' && $n)) {
    Access::require($pageKey, $n ? 'edit' : 'create');
    $insp = !$n && gint('inspection') ? DB::row('SELECT * FROM cs_inspections WHERE id = :i', ['i' => gint('inspection')]) : null;
    $pid = $n ? (int)$n['project_id'] : (int)($insp['project_id'] ?? gint('project') ?? CsProject::current() ?? 0);
    $c = $n ?: ['project_id' => $pid, 'raised_date' => today(), 'due_date' => date('Y-m-d', strtotime('+7 days')), 'inspection_id' => $insp['id'] ?? null, 'activity_id' => $insp['activity_id'] ?? null,
        'title' => $insp ? 'Failed: ' . $insp['title'] : '', 'description' => $insp['findings'] ?? ''];
    $acts = $pid ? DB::pairs("SELECT id, CONCAT(wbs_code, ' ', name) FROM cs_activities WHERE project_id = :p ORDER BY sort_order", ['p' => $pid]) : [];
    layout_start($n ? 'Edit ' . $n['ncr_no'] : 'New non-conformance', ['module' => 'site']);
    echo '<form method="post" class="card"><div class="card-body"><div class="row g-3">' . csrf_field() . '<input type="hidden" name="inspection_id" value="' . h((string)($c['inspection_id'] ?? '')) . '">'
        . CsUi::field('Project', $n || $insp ? '<input type="hidden" name="project_id" value="' . $pid . '"><div class="pt-1">' . CsUi::projectLink($pid) . '</div>' : CsUi::projectSelect('project_id', $pid ?: '', ['required' => true]), 'col-md-5')
        . CsUi::field('Raised on', CsUi::input('raised_date', fv('raised_date', $c), ['type' => 'date']), 'col-6 col-md-3') . CsUi::field('Correct by', CsUi::input('due_date', fv('due_date', $c), ['type' => 'date']), 'col-6 col-md-4')
        . CsUi::field('What does not conform', CsUi::input('title', fv('title', $c), ['required' => true, 'max' => 200]), 'col-12') . CsUi::field('Description', CsUi::textarea('description', fv('description', $c), 3), 'col-12')
        . CsUi::field('Activity', CsUi::select('activity_id', $acts, fv('activity_id', $c), 'None'), 'col-md-4') . CsUi::field('Cost code', CsUi::costCodeSelect('cost_code_id', fv('cost_code_id', $c)), 'col-md-4')
        . CsUi::field('Responsible', CsUi::input('responsible_party', fv('responsible_party', $c), ['max' => 150, 'placeholder' => 'Crew, subcontractor or supplier']), 'col-md-4')
        . CsUi::field('Root cause', CsUi::textarea('root_cause', fv('root_cause', $c), 2), 'col-md-4') . CsUi::field('Corrective action', CsUi::textarea('corrective_action', fv('corrective_action', $c), 2), 'col-md-4')
        . CsUi::field('Preventive action', CsUi::textarea('preventive_action', fv('preventive_action', $c), 2), 'col-md-4') . CsUi::field('Rework cost', CsUi::input('rework_cost', fv('rework_cost', $c, '0'), ['num' => true]), 'col-md-3', 'For the quality cost reports; the cost itself is in the project\'s books.')
        . '</div></div><div class="card-footer">' . save_buttons('Save', url($pageKey, $n ? ['a' => 'view', 'id' => $id] : []), false) . '</div></form>';
    layout_end();
    return;
}

if ($a === 'view' && $n) {
    $late = $n['due_date'] && $n['due_date'] < today() && !in_array($n['status'], ['verified', 'closed'], true);
    layout_start($n['ncr_no'] . ' ' . $n['title'], ['module' => 'site', 'sub' => CsUi::projectLink((int)$n['project_id']) . ', raised ' . h(fdate($n['raised_date']) . ' by ' . $n['by_name']) . ' ' . CsUi::state($late ? 'overdue' : $n['status'], $late ? 'Overdue' : ($statuses[$n['status']] ?? null)),
        'actions' => $n['status'] !== 'closed' ? cs_link($pageKey, ['a' => 'edit', 'id' => $id], 'Edit', 'btn-outline-secondary', 'pencil', 'edit') : '']);
    echo '<div class="cs-split"><div class="cs-stack"><div class="card"><div class="card-body">' . CsUi::facts([
        'Description' => nl2br(h((string)$n['description'])), 'Responsible' => h((string)$n['responsible_party']), 'Correct by' => '<span class="' . ($late ? 'cs-bad' : '') . '">' . h(fdate($n['due_date'])) . '</span>',
        'Root cause' => nl2br(h((string)$n['root_cause'])), 'Corrective action' => nl2br(h((string)$n['corrective_action'])), 'Preventive action' => nl2br(h((string)$n['preventive_action'])),
        'Rework cost' => money($n['rework_cost']), 'Cost code' => h(CsCost::codeLabel($n['cost_code_id'] ? (int)$n['cost_code_id'] : null)),
        'From inspection' => $n['inspection_id'] ? '<a href="' . h(url('site.inspections', ['a' => 'view', 'id' => $n['inspection_id']])) . '">' . h((string)DB::value('SELECT insp_no FROM cs_inspections WHERE id = :i', ['i' => $n['inspection_id']])) . '</a>' : '',
        'Closed' => h(fdate($n['closed_date'])),
    ]) . '</div></div>' . CsFiles::panel('ncr', $id, Access::can($pageKey, 'edit'), 'Photos before and after') . '</div><div class="cs-stack">';
    $next = ['open' => ['in_progress' => 'Corrective action in progress'], 'in_progress' => ['verified' => 'Correction verified'], 'verified' => ['closed' => 'Closed', 'in_progress' => 'Reopen (not acceptable)']][$n['status']] ?? [];
    if ($next && Access::can($pageKey, 'edit')) {
        echo '<form method="post" class="card"><div class="card-header">Next step</div><div class="card-body"><div class="row g-2">' . csrf_field() . '<input type="hidden" name="act" value="status">'
            . '<div class="col-12">' . CsUi::select('status', $next, '', null, 'data-no-combo') . '</div><div class="col-12"><input class="form-control form-control-sm" name="reason" placeholder="Comment"></div>'
            . '<div class="col-12"><button class="btn btn-primary btn-sm">Save</button></div></div></div></form>';
    }
    echo cs_history('ncr', $id) . '</div></div>';
    layout_end();
    return;
}

$pid = CsProject::current();
layout_start('Non-Conformance (NCR)', ['actions' => cs_link($pageKey, ['a' => 'new', 'project' => $pid], 'New NCR', 'btn-primary', 'plus-lg', 'create')]);
echo cs_filter_bar($pageKey, $pid, cs_status_field($statuses));
[$f, $params] = CsProject::filter('n.project_id');
$w = '';
if ($pid) {
    $w .= ' AND n.project_id = :pid';
    $params['pid'] = $pid;
}
if (isset($statuses[(string)g('status')])) {
    $w .= ' AND n.status = :st';
    $params['st'] = (string)g('status');
}
$rows = DB::all("SELECT n.*, p.code FROM cs_ncrs n JOIN cs_projects p ON p.id = n.project_id WHERE 1 = 1 $f $w ORDER BY (n.status = 'closed'), n.due_date, n.id DESC LIMIT 400", $params);
$out = [];
$open = 0;
$cost = 0.0;
foreach ($rows as $r) {
    $late = $r['due_date'] && $r['due_date'] < today() && !in_array($r['status'], ['verified', 'closed'], true);
    $open += $r['status'] !== 'closed' ? 1 : 0;
    $cost += (float)$r['rework_cost'];
    $out[] = ['<a class="cs-main-link" href="' . h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) . '">' . h($r['ncr_no']) . '</a><span class="cs-sub">' . h($r['title']) . '</span>', h($r['code']), h(fdate($r['raised_date'])),
        h((string)$r['responsible_party']), '<span class="' . ($late ? 'cs-bad' : '') . '">' . h(fdate($r['due_date'])) . '</span>', money($r['rework_cost']), CsUi::state($late ? 'overdue' : $r['status'], $late ? 'Overdue' : ($statuses[$r['status']] ?? null))];
}
echo '<div class="cs-kpis">' . CsUi::kpi('Open non-conformances', (string)$open, '', $open ? 'warn' : 'ok') . CsUi::kpi('Rework cost recorded', CsUi::amt($cost), '') . '</div>';
echo cs_table_card(count($rows) . ' non-conformances', ['NCR' => '', 'Project' => '', 'Raised' => '', 'Responsible' => '', 'Correct by' => '', 'Rework cost' => 'num', 'Status' => ''], $out, 'No non-conformance recorded.');
layout_end();
