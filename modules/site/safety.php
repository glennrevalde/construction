<?php
declare(strict_types=1);

/** Safety records: incidents, near misses, toolbox meetings, trainings, PPE issuance, permits to work, hazards and equipment checks. */
$pageKey = 'site.safety';
$a = (string)g('a', 'list');
$id = gint('id');
$s = $id ? DB::row('SELECT s.*, p.code AS pcode, u.full_name AS by_name FROM cs_safety_records s JOIN cs_projects p ON p.id = s.project_id LEFT JOIN users u ON u.id = s.created_by WHERE s.id = :id', ['id' => $id]) : null;
if ($s) {
    CsProject::requireAccess((int)$s['project_id']);
}
$kinds = ['incident' => 'Incident', 'near_miss' => 'Near miss', 'toolbox' => 'Toolbox meeting', 'training' => 'Safety training', 'ppe' => 'PPE issuance', 'permit' => 'Permit to work', 'hazard' => 'Hazard observation', 'equipment_check' => 'Equipment safety check'];
$sev = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'];

handle_post(function () use ($pageKey, $id, $s, $kinds, $sev) {
    if ($s && cs_handle_attach('safety', (int)$id, (int)$s['project_id'])) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    Access::require($pageKey, $id ? 'edit' : 'create');
    $pid = $s ? (int)$s['project_id'] : (int)p('project_id');
    CsProject::requireAccess($pid);
    $kind = $s ? $s['kind'] : (isset($kinds[p('kind')]) ? (string)p('kind') : 'toolbox');
    $row = ['record_date' => pdate('record_date') ?: today(), 'title' => mb_substr(trim((string)p('title')), 0, 200), 'description' => trim((string)p('description')) ?: null,
        'persons' => mb_substr(trim((string)p('persons')), 0, 255) ?: null, 'severity' => isset($sev[p('severity')]) ? p('severity') : null, 'attendees' => max(0, (int)p('attendees')),
        'lost_time_hours' => max(0, to_num(p('lost_time_hours'))), 'corrective_action' => trim((string)p('corrective_action')) ?: null, 'valid_until' => pdate('valid_until'),
        'status' => in_array(p('status'), ['open', 'closed'], true) ? p('status') : 'open'];
    if ($row['title'] === '') {
        fail('Enter the title.');
    }
    if (in_array($kind, ['incident', 'near_miss'], true) && !$row['severity']) {
        fail('Give the severity of the ' . strtolower($kinds[$kind]) . '.');
    }
    if ($row['status'] === 'closed' && in_array($kind, ['incident', 'near_miss', 'hazard'], true) && !$row['corrective_action']) {
        fail('Record the corrective action before closing it.');
    }
    if ($s) {
        DB::update('cs_safety_records', $row, 'id = :id', ['id' => $id]);
        Audit::log('edit', 'site', 'safety', (int)$id, $s['ref_no'], ['page_key' => $pageKey]);
    } else {
        $id = DB::insert('cs_safety_records', $row + ['ref_no' => Seq::next('cs_safety'), 'project_id' => $pid, 'kind' => $kind, 'created_by' => Auth::id()]);
        Audit::log('create', 'site', 'safety', $id, $kinds[$kind] . ': ' . $row['title'], ['page_key' => $pageKey]);
        if ($kind === 'incident') {
            $p = CsProject::get($pid);
            $to = array_filter([(int)$p['pm_user_id'], (int)$p['engineer_user_id']]);
            foreach (DB::column("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.name IN ('Safety Officer','Project Director') AND u.status = 'active'") as $u) {
                $to[] = (int)$u;
            }
            CsNotify::send(array_values(array_unique($to)), 'incident', 'Incident on ' . $p['code'] . ' (' . ($sev[$row['severity']] ?? '') . ')', $row['title'], url($pageKey, ['a' => 'view', 'id' => $id]), $pid);
        }
    }
    flash('success', 'Saved.');
    cs_go($pageKey, ['a' => 'view', 'id' => $id]);
});

if ($a === 'new' || ($a === 'edit' && $s)) {
    Access::require($pageKey, $s ? 'edit' : 'create');
    $kind = $s ? $s['kind'] : (isset($kinds[(string)g('kind')]) ? (string)g('kind') : 'toolbox');
    $pid = $s ? (int)$s['project_id'] : (int)(gint('project') ?? CsProject::current() ?? 0);
    $c = $s ?: ['record_date' => today(), 'status' => in_array($kind, ['toolbox', 'training', 'ppe', 'equipment_check'], true) ? 'closed' : 'open', 'severity' => in_array($kind, ['incident', 'near_miss'], true) ? 'low' : null];
    $isEvent = in_array($kind, ['incident', 'near_miss', 'hazard'], true);
    layout_start(($s ? 'Edit ' . $s['ref_no'] : 'New ' . strtolower($kinds[$kind])), ['module' => 'site']);
    if (!$s) {
        echo '<div class="d-flex flex-wrap gap-1 mb-3">';
        foreach ($kinds as $k => $label) {
            echo '<a class="btn btn-sm ' . ($k === $kind ? 'btn-primary' : 'btn-outline-secondary') . '" href="' . h(url($pageKey, ['a' => 'new', 'kind' => $k, 'project' => $pid])) . '">' . h($label) . '</a>';
        }
        echo '</div>';
    }
    echo '<form method="post" class="card"><div class="card-body"><div class="row g-3">' . csrf_field() . '<input type="hidden" name="kind" value="' . h($kind) . '">'
        . CsUi::field('Project', $s ? '<div class="pt-1">' . CsUi::projectLink($pid) . '</div>' : CsUi::projectSelect('project_id', $pid ?: '', ['required' => true]), 'col-md-5')
        . CsUi::field('Date', CsUi::input('record_date', fv('record_date', $c), ['type' => 'date']), 'col-6 col-md-3')
        . CsUi::field('Status', CsUi::select('status', ['open' => 'Open', 'closed' => 'Closed'], fv('status', $c), null, 'data-no-combo'), 'col-6 col-md-2')
        . ($isEvent ? CsUi::field('Severity', CsUi::select('severity', $sev, fv('severity', $c), 'Choose', 'data-no-combo'), 'col-md-2') : '')
        . CsUi::field(['toolbox' => 'Topic', 'training' => 'Training', 'ppe' => 'PPE issued', 'permit' => 'Permit (hot work, height, confined space)'][$kind] ?? 'What happened', CsUi::input('title', fv('title', $c), ['required' => true, 'max' => 200]), 'col-12')
        . CsUi::field('Details', CsUi::textarea('description', fv('description', $c), 3), 'col-12')
        . CsUi::field(in_array($kind, ['toolbox', 'training'], true) ? 'Conducted by' : 'Persons involved', CsUi::input('persons', fv('persons', $c), ['max' => 255]), 'col-md-6')
        . (in_array($kind, ['toolbox', 'training', 'ppe'], true) ? CsUi::field('Attendees / recipients', CsUi::input('attendees', fv('attendees', $c, '0'), ['type' => 'number', 'step' => '1']), 'col-6 col-md-3') : '')
        . ($kind === 'incident' ? CsUi::field('Lost time (hours)', CsUi::input('lost_time_hours', fv('lost_time_hours', $c, '0'), ['num' => true]), 'col-6 col-md-3') : '')
        . (in_array($kind, ['permit', 'training'], true) ? CsUi::field('Valid until', CsUi::input('valid_until', fv('valid_until', $c), ['type' => 'date']), 'col-6 col-md-3') : '')
        . ($isEvent || $kind === 'equipment_check' ? CsUi::field('Corrective action', CsUi::textarea('corrective_action', fv('corrective_action', $c), 2), 'col-12') : '')
        . '</div></div><div class="card-footer">' . save_buttons('Save', url($pageKey, $s ? ['a' => 'view', 'id' => $id] : []), false) . '</div></form>';
    layout_end();
    return;
}

if ($a === 'view' && $s) {
    layout_start($s['ref_no'] . ' ' . $s['title'], ['module' => 'site', 'sub' => CsUi::projectLink((int)$s['project_id']) . ', ' . h($kinds[$s['kind']] . ', ' . fdate($s['record_date'])) . ' ' . CsUi::state($s['status'])
        . ($s['severity'] ? ' <span class="cs-state is-' . (in_array($s['severity'], ['high', 'critical'], true) ? 'bad' : 'warn') . '">' . h($sev[$s['severity']]) . '</span>' : ''),
        'actions' => cs_link($pageKey, ['a' => 'edit', 'id' => $id], 'Edit', 'btn-outline-secondary', 'pencil', 'edit')]);
    echo '<div class="cs-split"><div class="card"><div class="card-body">' . CsUi::facts(['Details' => nl2br(h((string)$s['description'])), 'Persons' => h((string)$s['persons']),
        'Attendees' => (int)$s['attendees'] ? (string)(int)$s['attendees'] : null, 'Lost time' => (float)$s['lost_time_hours'] ? number_format((float)$s['lost_time_hours'], 1) . ' hours' : null,
        'Valid until' => $s['valid_until'] ? h(fdate($s['valid_until'])) : null, 'Corrective action' => nl2br(h((string)$s['corrective_action'])), 'Recorded by' => h((string)$s['by_name'])]) . '</div></div>'
        . '<div class="cs-stack">' . CsFiles::panel('safety', $id, Access::can($pageKey, 'edit'), 'Photos and attendance sheets') . cs_history('safety', $id) . '</div></div>';
    layout_end();
    return;
}

$pid = CsProject::current();
$from = gdate('from') ?: date('Y-m-01', strtotime('-2 months'));
layout_start('Safety Monitoring', ['actions' => cs_link($pageKey, ['a' => 'new', 'project' => $pid], 'New record', 'btn-primary', 'plus-lg', 'create')]);
echo cs_filter_bar($pageKey, $pid, '<div><label class="form-label">Since</label><input class="form-control form-control-sm" type="date" name="from" value="' . h($from) . '"></div>' . cs_status_field($kinds, 'kind', 'Kind'));
[$f, $params] = CsProject::filter('s.project_id');
$w = ' AND s.record_date >= :from';
$params['from'] = $from;
if ($pid) {
    $w .= ' AND s.project_id = :pid';
    $params['pid'] = $pid;
}
if (isset($kinds[(string)g('kind')])) {
    $w .= ' AND s.kind = :k';
    $params['k'] = (string)g('kind');
}
$rows = DB::all("SELECT s.*, p.code FROM cs_safety_records s JOIN cs_projects p ON p.id = s.project_id WHERE 1 = 1 $f $w ORDER BY s.record_date DESC, s.id DESC LIMIT 500", $params);
$k = array_fill_keys(array_keys($kinds), 0);
$lost = 0.0;
$out = [];
foreach ($rows as $r) {
    $k[$r['kind']]++;
    $lost += (float)$r['lost_time_hours'];
    $out[] = ['<a class="cs-main-link" href="' . h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) . '">' . h($r['ref_no']) . '</a><span class="cs-sub">' . h($r['title']) . '</span>', h($r['code']), h($kinds[$r['kind']]), h(fdate($r['record_date'])),
        $r['severity'] ? '<span class="' . (in_array($r['severity'], ['high', 'critical'], true) ? 'cs-bad' : 'cs-warn') . '">' . h($sev[$r['severity']]) . '</span>' : '', (int)$r['attendees'] ?: '', CsUi::state($r['status'])];
}
$hours = (float)DB::value("SELECT COALESCE(SUM(l.regular_hours + l.ot_hours),0) FROM cs_timesheet_lines l JOIN cs_timesheets t ON t.id = l.timesheet_id WHERE t.work_date >= :f AND t.status <> 'rejected'" . ($pid ? ' AND t.project_id = ' . (int)$pid : ''), ['f' => $from]);
echo '<div class="cs-kpis">' . CsUi::kpi('Incidents', (string)$k['incident'], $lost ? number_format($lost, 1) . ' lost-time hours' : 'No lost time', $k['incident'] ? 'bad' : 'ok') . CsUi::kpi('Near misses', (string)$k['near_miss'], 'Reported is good')
    . CsUi::kpi('Toolbox meetings', (string)$k['toolbox'], $k['training'] . ' trainings') . CsUi::kpi('Man-hours worked', number_format($hours, 0), $k['incident'] && $hours ? 'Incident rate ' . number_format($k['incident'] / $hours * 200000, 2) . ' per 200,000 h' : '') . '</div>';
echo cs_table_card(count($rows) . ' records', ['Record' => '', 'Project' => '', 'Kind' => '', 'Date' => '', 'Severity' => '', 'Attendees' => 'num', 'Status' => ''], $out, 'No safety records in this period.');
layout_end();
