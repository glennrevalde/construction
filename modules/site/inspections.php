<?php
declare(strict_types=1);

/** Quality inspections and tests: inspection and test plans, checklists, material tests, pre-pour and final inspections. */
$pageKey = 'site.inspections';
$a = (string)g('a', 'list');
$id = gint('id');
$x = $id ? DB::row('SELECT i.*, p.code AS pcode FROM cs_inspections i JOIN cs_projects p ON p.id = i.project_id WHERE i.id = :id', ['id' => $id]) : null;
if ($x) {
    CsProject::requireAccess((int)$x['project_id']);
}
$kinds = ['itp' => 'Inspection and test plan', 'checklist' => 'Quality checklist', 'material_test' => 'Material test', 'equipment_safety' => 'Equipment safety check', 'pre_pour' => 'Pre-pour inspection', 'final' => 'Final inspection'];
$results = ['pending' => 'Pending', 'passed' => 'Passed', 'conditional' => 'Passed with conditions', 'failed' => 'Failed'];

handle_post(function () use ($pageKey, $id, $x, $kinds, $results) {
    if ($x && cs_handle_attach('inspection', (int)$id, (int)$x['project_id'])) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    Access::require($pageKey, $id ? 'edit' : 'create');
    $pid = $x ? (int)$x['project_id'] : (int)p('project_id');
    CsProject::requireAccess($pid);
    $check = [];
    foreach ((array)($_POST['items'] ?? []) as $it) {
        $t = trim((string)($it['item'] ?? ''));
        if ($t !== '') {
            $check[] = str_replace('|', '/', $t) . '|' . (in_array($it['r'] ?? '', ['pass', 'fail', 'na'], true) ? $it['r'] : '');
        }
    }
    $row = ['kind' => isset($kinds[p('kind')]) ? p('kind') : 'checklist', 'title' => mb_substr(trim((string)p('title')), 0, 200), 'inspection_date' => pdate('inspection_date') ?: today(),
        'location' => mb_substr(trim((string)p('location')), 0, 150) ?: null, 'inspector' => mb_substr(trim((string)p('inspector')), 0, 120) ?: null, 'activity_id' => pint('activity_id'),
        'checklist' => $check ? implode("\n", $check) : null, 'findings' => trim((string)p('findings')) ?: null, 'result' => isset($results[p('result')]) ? p('result') : 'pending'];
    if ($row['title'] === '') {
        fail('Enter what was inspected.');
    }
    if (in_array('fail', array_map(fn($c) => explode('|', $c)[1] ?? '', $check), true) && $row['result'] === 'passed') {
        fail('A checklist item failed: the inspection cannot be marked passed. Use "Passed with conditions" or "Failed".');
    }
    if ($x) {
        DB::update('cs_inspections', $row, 'id = :id', ['id' => $id]);
        Audit::log('edit', 'site', 'inspection', (int)$id, $x['insp_no'], ['page_key' => $pageKey, 'reason' => $results[$row['result']]]);
    } else {
        $id = DB::insert('cs_inspections', $row + ['insp_no' => Seq::next('cs_inspection'), 'project_id' => $pid, 'created_by' => Auth::id()]);
        Audit::log('create', 'site', 'inspection', $id, $row['title'], ['page_key' => $pageKey, 'reason' => $results[$row['result']]]);
    }
    flash('success', 'Inspection saved.' . ($row['result'] === 'failed' ? ' Raise a non-conformance for what failed.' : ''));
    cs_go($pageKey, ['a' => 'view', 'id' => $id]);
});

if ($a === 'new' || ($a === 'edit' && $x)) {
    Access::require($pageKey, $x ? 'edit' : 'create');
    $pid = $x ? (int)$x['project_id'] : (int)(gint('project') ?? CsProject::current() ?? 0);
    $tplId = gint('tpl');
    $c = $x ?: ['project_id' => $pid, 'inspection_date' => today(), 'kind' => 'checklist', 'result' => 'pending', 'inspector' => Auth::user()['full_name'] ?? ''];
    $items = [];
    if ($x && $x['checklist']) {
        foreach (explode("\n", $x['checklist']) as $ln) {
            [$t, $res] = array_pad(explode('|', $ln, 2), 2, '');
            $items[] = ['item' => $t, 'r' => $res];
        }
    } elseif ($tplId) {
        $tpl = DB::row("SELECT * FROM cs_checklist_templates WHERE id = :t AND kind = 'inspection'", ['t' => $tplId]);
        foreach (array_filter(array_map('trim', explode("\n", (string)($tpl['items'] ?? '')))) as $t) {
            $items[] = ['item' => $t, 'r' => ''];
        }
        $c['title'] = $tpl['name'] ?? '';
        $c['kind'] = stripos((string)($tpl['name'] ?? ''), 'pour') !== false ? 'pre_pour' : 'checklist';
    }
    $tpls = DB::pairs("SELECT id, name FROM cs_checklist_templates WHERE kind = 'inspection' AND is_active = 1 ORDER BY name");
    $acts = $pid ? DB::pairs("SELECT id, CONCAT(wbs_code, ' ', name) FROM cs_activities WHERE project_id = :p ORDER BY sort_order", ['p' => $pid]) : [];
    $itemRow = fn(array $it, string $i) => '<tr><td class="w-desc"><input class="form-control form-control-sm" name="items[' . $i . '][item]" value="' . h($it['item'] ?? '') . '"></td><td style="width:150px">'
        . CsUi::select('items[' . $i . '][r]', ['pass' => 'Pass', 'fail' => 'Fail', 'na' => 'Not applicable'], $it['r'] ?? '', 'Not checked', 'data-no-combo') . '</td>'
        . '<td class="grid-acts"><button type="button" class="btn btn-link btn-sm text-danger p-0" data-cs-del aria-label="Remove"><i class="bi bi-x-lg"></i></button></td></tr>';
    layout_start($x ? 'Edit ' . $x['insp_no'] : 'New inspection', ['module' => 'site']);
    if (!$x && $tpls) {
        echo '<form class="cs-toolbar" method="get"><input type="hidden" name="p" value="' . h($pageKey) . '"><input type="hidden" name="a" value="new"><input type="hidden" name="project" value="' . (int)$pid . '">'
            . '<div><label class="form-label">Start from a checklist</label>' . CsUi::select('tpl', $tpls, $tplId ?? '', 'Blank', 'data-no-combo onchange="this.form.submit()"') . '</div></form>';
    }
    echo '<form method="post"><div class="card mb-3"><div class="card-body"><div class="row g-3">' . csrf_field()
        . CsUi::field('Project', $x ? '<div class="pt-1">' . CsUi::projectLink($pid) . '</div>' : CsUi::projectSelect('project_id', $pid ?: '', ['required' => true]), 'col-md-5')
        . CsUi::field('Kind', CsUi::select('kind', $kinds, fv('kind', $c), null, 'data-no-combo'), 'col-md-4') . CsUi::field('Date', CsUi::input('inspection_date', fv('inspection_date', $c), ['type' => 'date']), 'col-md-3')
        . CsUi::field('What was inspected', CsUi::input('title', fv('title', $c), ['required' => true, 'max' => 200]), 'col-md-6') . CsUi::field('Location', CsUi::input('location', fv('location', $c), ['max' => 150, 'placeholder' => '2F slab, Grid B-C/2-3']), 'col-md-6')
        . CsUi::field('Inspector', CsUi::input('inspector', fv('inspector', $c), ['max' => 120]), 'col-md-4') . CsUi::field('Activity', CsUi::select('activity_id', $acts, fv('activity_id', $c), 'None'), 'col-md-4')
        . CsUi::field('Result', CsUi::select('result', $results, fv('result', $c), null, 'data-no-combo'), 'col-md-4')
        . '</div></div></div><div class="card mb-3"><div class="card-header">Checklist</div><div class="table-responsive"><table class="table table-sm cs-lines mb-0" id="inspItems" data-cs-lines data-min-rows="3"><thead><tr><th>Item</th><th>Result</th><th></th></tr></thead><tbody>';
    foreach ($items as $i => $it) {
        echo $itemRow($it, 'e' . $i);
    }
    echo '</tbody></table></div><template data-cs-row="inspItems">' . $itemRow([], '__i__') . '</template><div class="card-body pt-2"><button type="button" class="btn btn-outline-secondary btn-sm" data-cs-add="inspItems"><i class="bi bi-plus"></i> Add an item</button></div></div>'
        . '<div class="card mb-3"><div class="card-body">' . CsUi::field('Findings', CsUi::textarea('findings', fv('findings', $c), 3), 'col-12') . '</div></div>'
        . '<div class="sticky-actions">' . save_buttons('Save inspection', url($pageKey, $x ? ['a' => 'view', 'id' => $id] : []), false) . '</div></form>';
    layout_end();
    return;
}

if ($a === 'view' && $x) {
    layout_start($x['insp_no'] . ' ' . $x['title'], ['module' => 'site', 'sub' => CsUi::projectLink((int)$x['project_id']) . ', ' . h($kinds[$x['kind']] ?? '') . ', ' . h(fdate($x['inspection_date'])) . ' ' . CsUi::state($x['result'], $results[$x['result']] ?? null),
        'actions' => cs_link($pageKey, ['a' => 'edit', 'id' => $id], 'Edit', 'btn-outline-secondary', 'pencil', 'edit') . ' ' . (in_array($x['result'], ['failed', 'conditional'], true) ? cs_link('site.ncr', ['a' => 'new', 'inspection' => $id], 'Raise non-conformance', 'btn-primary', 'exclamation-triangle', 'create') : '')]);
    echo '<div class="cs-split"><div class="cs-stack"><div class="card"><div class="card-header">Checklist</div><div class="card-body">';
    foreach (array_filter(explode("\n", (string)$x['checklist'])) as $ln) {
        [$t, $res] = array_pad(explode('|', $ln, 2), 2, '');
        echo '<div class="cs-check"><div>' . h($t) . '</div><div>' . CsUi::state($res === 'pass' ? 'passed' : ($res === 'fail' ? 'failed' : 'pending'), ['pass' => 'Pass', 'fail' => 'Fail', 'na' => 'Not applicable'][$res] ?? 'Not checked') . '</div></div>';
    }
    echo ($x['checklist'] ? '' : '<p class="cs-note mb-0">No checklist.</p>') . '</div></div>';
    echo '<div class="card"><div class="card-body">' . CsUi::facts(['Location' => h((string)$x['location']), 'Inspector' => h((string)$x['inspector']), 'Findings' => nl2br(h((string)$x['findings']))]) . '</div></div></div><div class="cs-stack">';
    $ncrs = DB::all('SELECT * FROM cs_ncrs WHERE inspection_id = :i', ['i' => $id]);
    if ($ncrs) {
        $rows = [];
        foreach ($ncrs as $n) {
            $rows[] = ['<a href="' . h(url('site.ncr', ['a' => 'view', 'id' => $n['id']])) . '">' . h($n['ncr_no']) . '</a>', h($n['title']), CsUi::state($n['status'])];
        }
        echo cs_table_card('Non-conformances raised', ['NCR' => '', 'Title' => '', 'Status' => ''], $rows);
    }
    echo CsFiles::panel('inspection', $id, Access::can($pageKey, 'edit'), 'Test results and photos') . '</div></div>';
    layout_end();
    return;
}

$pid = CsProject::current();
layout_start('Quality Inspections', ['actions' => cs_link($pageKey, ['a' => 'new', 'project' => $pid], 'New inspection', 'btn-primary', 'plus-lg', 'create')]);
echo cs_filter_bar($pageKey, $pid, cs_status_field($kinds, 'kind', 'Kind') . cs_status_field($results, 'result', 'Result'));
[$f, $params] = CsProject::filter('i.project_id');
$w = '';
if ($pid) {
    $w .= ' AND i.project_id = :pid';
    $params['pid'] = $pid;
}
if (isset($kinds[(string)g('kind')])) {
    $w .= ' AND i.kind = :k';
    $params['k'] = (string)g('kind');
}
if (isset($results[(string)g('result')])) {
    $w .= ' AND i.result = :r';
    $params['r'] = (string)g('result');
}
$rows = DB::all("SELECT i.*, p.code FROM cs_inspections i JOIN cs_projects p ON p.id = i.project_id WHERE 1 = 1 $f $w ORDER BY i.inspection_date DESC, i.id DESC LIMIT 400", $params);
$out = [];
$cnt = ['passed' => 0, 'failed' => 0, 'all' => count($rows)];
foreach ($rows as $r) {
    if (isset($cnt[$r['result']])) {
        $cnt[$r['result']]++;
    }
    $out[] = ['<a class="cs-main-link" href="' . h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) . '">' . h($r['insp_no']) . '</a><span class="cs-sub">' . h($r['title']) . '</span>', h($r['code']), h($kinds[$r['kind']] ?? ''),
        h(fdate($r['inspection_date'])), h((string)$r['location']), h((string)$r['inspector']), CsUi::state($r['result'], $results[$r['result']] ?? null)];
}
echo '<div class="cs-kpis">' . CsUi::kpi('Inspections', (string)$cnt['all'], '', 'key') . CsUi::kpi('First-time pass rate', $cnt['all'] ? CsUi::pct($cnt['passed'] / $cnt['all'] * 100) : 'None', '', '') . CsUi::kpi('Failed', (string)$cnt['failed'], '', $cnt['failed'] ? 'warn' : 'ok') . '</div>';
echo cs_table_card(count($rows) . ' inspections', ['Inspection' => '', 'Project' => '', 'Kind' => '', 'Date' => '', 'Location' => '', 'Inspector' => '', 'Result' => ''], $out, 'No inspections yet.');
layout_end();
