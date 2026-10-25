<?php
declare(strict_types=1);

/** The project registers: risks, issues, decisions, site instructions, correspondence, meetings, delays and the diary. */
$pageKey = 'proj.logs';
$kinds = ['risk' => 'Risks', 'issue' => 'Issues', 'decision' => 'Decisions', 'instruction' => 'Site instructions', 'correspondence' => 'Correspondence', 'meeting' => 'Meeting minutes', 'delay' => 'Delays', 'diary' => 'Diary'];
$kind = (string)g('kind', 'risk');
if (!isset($kinds[$kind])) {
    $kind = 'risk';
}
$id = gint('id');
$row = $id ? DB::row('SELECT * FROM cs_project_logs WHERE id = :id', ['id' => $id]) : null;
if ($row) {
    CsProject::requireAccess((int)$row['project_id']);
    $kind = $row['kind'];
}
$pid = $row ? (int)$row['project_id'] : CsProject::current();

handle_post(function () use ($pageKey, $id, $row, $kinds) {
    if ($row && cs_handle_attach('project_log', (int)$id, (int)$row['project_id'])) {
        cs_go($pageKey, ['id' => $id]);
    }
    Access::require($pageKey, $id ? 'edit' : 'create');
    $pid = $row ? (int)$row['project_id'] : (int)p('project_id');
    CsProject::requireAccess($pid);
    $k = $row ? $row['kind'] : (string)p('kind');
    if (!isset($kinds[$k])) {
        fail('Unknown register.');
    }
    $d = ['log_date' => pdate('log_date') ?: today(), 'title' => mb_substr(trim((string)p('title')), 0, 200), 'details' => trim((string)p('details')) ?: null,
        'party' => mb_substr(trim((string)p('party')), 0, 150) ?: null, 'probability' => $k === 'risk' ? max(1, min(5, (int)p('probability', 3))) : null,
        'impact' => $k === 'risk' ? max(1, min(5, (int)p('impact', 3))) : null, 'cost_impact' => to_num(p('cost_impact', 0)), 'days_impact' => (int)p('days_impact', 0),
        'response' => trim((string)p('response')) ?: null, 'due_date' => pdate('due_date'), 'status' => in_array(p('status'), ['open', 'monitoring', 'closed'], true) ? p('status') : 'open'];
    if ($d['title'] === '') {
        fail('Enter the title.');
    }
    if ($row) {
        DB::update('cs_project_logs', $d, 'id = :id', ['id' => $id]);
        Audit::log('edit', 'proj', 'project_log', (int)$id, $row['ref_no'] . ' ' . $d['title'], ['page_key' => $pageKey, 'before' => $row, 'after' => $d]);
    } else {
        $id = DB::insert('cs_project_logs', $d + ['project_id' => $pid, 'kind' => $k, 'ref_no' => Seq::next('cs_log'), 'created_by' => Auth::id()]);
        Audit::log('create', 'proj', 'project_log', $id, $d['title'], ['page_key' => $pageKey, 'reason' => $kinds[$k]]);
    }
    flash('success', 'Saved.');
    cs_go($pageKey, ['project' => $pid, 'kind' => $k]);
});

layout_start('Logs, Risks & Issues', ['sub' => $pid ? CsUi::projectLink($pid) : 'Choose a project']);
echo cs_filter_bar($pageKey, $pid, '<input type="hidden" name="kind" value="' . h($kind) . '">', false);
if (!$pid) {
    echo '<div class="card">' . CsUi::empty('Choose a project to see its registers.', '', 'journal-text') . '</div>';
    layout_end();
    return;
}
CsProject::requireAccess($pid);
$counts = DB::pairs("SELECT kind, COUNT(*) FROM cs_project_logs WHERE project_id = :p AND status <> 'closed' GROUP BY kind", ['p' => $pid]);
$tabs = [];
foreach ($kinds as $k => $l) {
    $tabs[$k] = h($l) . (isset($counts[$k]) ? ' <span class="cs-note">' . (int)$counts[$k] . '</span>' : '');
}
echo CsUi::tabs($tabs, $kind, fn($k) => url($pageKey, ['project' => $pid, 'kind' => $k]));
$list = DB::all('SELECT l.*, u.full_name AS by_name FROM cs_project_logs l LEFT JOIN users u ON u.id = l.created_by WHERE l.project_id = :p AND l.kind = :k ORDER BY (l.status = \'closed\'), l.log_date DESC, l.id DESC', ['p' => $pid, 'k' => $kind]);
echo '<div class="cs-split"><div>';
$out = [];
foreach ($list as $l) {
    $score = $l['probability'] && $l['impact'] ? (int)$l['probability'] * (int)$l['impact'] : null;
    $out[] = ['_class' => $l['status'] === 'closed' ? 'text-muted' : '',
        '<a class="cs-main-link" href="' . h(url($pageKey, ['id' => $l['id']])) . '">' . h($l['title']) . '</a><span class="cs-sub">' . h($l['ref_no'] . ', ' . fdate($l['log_date']) . ($l['party'] ? ', ' . $l['party'] : '')) . '</span>'
        . ($l['details'] ? '<span class="cs-sub">' . h(mb_strimwidth($l['details'], 0, 140, '...')) . '</span>' : ''),
        $kind === 'risk' ? '<span class="' . ($score >= 15 ? 'cs-bad' : ($score >= 8 ? 'cs-warn' : '')) . '">' . ($score ?? '') . '</span>' : ((float)$l['cost_impact'] ? money($l['cost_impact']) : ''),
        (int)$l['days_impact'] ? (int)$l['days_impact'] . ' d' : '', h(fdate($l['due_date'])), CsUi::state($l['status'])];
}
echo cs_table_card($kinds[$kind], ['Entry' => '', $kind === 'risk' ? 'Score' : 'Cost impact' => 'num', 'Days' => 'num', 'Due' => '', 'Status' => ''], $out, 'Nothing recorded yet.');
if ($kind === 'risk') {
    echo '<p class="cs-note mt-2">Score is probability times impact, each from 1 to 5: 15 and above is high, 8 to 14 is medium.</p>';
}
echo '</div><div class="cs-stack">';
if (Access::can($pageKey, $row ? 'edit' : 'create')) {
    $c = $row ?: ['log_date' => today(), 'status' => 'open', 'probability' => 3, 'impact' => 3];
    echo '<form method="post" class="card"><div class="card-header">' . ($row ? 'Edit ' . h($row['ref_no']) : 'New entry in ' . h(strtolower($kinds[$kind]))) . '</div><div class="card-body"><div class="row g-2">' . csrf_field()
        . '<input type="hidden" name="project_id" value="' . (int)$pid . '"><input type="hidden" name="kind" value="' . h($kind) . '">'
        . CsUi::field('Date', CsUi::input('log_date', fv('log_date', $c), ['type' => 'date']), 'col-6') . CsUi::field('Status', CsUi::select('status', ['open' => 'Open', 'monitoring' => 'Monitoring', 'closed' => 'Closed'], fv('status', $c), null, 'data-no-combo'), 'col-6')
        . CsUi::field('Title', CsUi::input('title', fv('title', $c), ['required' => true, 'max' => 200]), 'col-12')
        . CsUi::field('Details', CsUi::textarea('details', fv('details', $c), 3), 'col-12')
        . CsUi::field(['meeting' => 'Attendees', 'correspondence' => 'From / to', 'instruction' => 'Issued by'][$kind] ?? 'Owner', CsUi::input('party', fv('party', $c), ['max' => 150]), 'col-12')
        . ($kind === 'risk' ? CsUi::field('Probability (1-5)', CsUi::input('probability', fv('probability', $c), ['type' => 'number', 'step' => '1']), 'col-6') . CsUi::field('Impact (1-5)', CsUi::input('impact', fv('impact', $c), ['type' => 'number', 'step' => '1']), 'col-6') : '')
        . CsUi::field('Cost impact', CsUi::input('cost_impact', fv('cost_impact', $c, '0'), ['num' => true]), 'col-6') . CsUi::field('Days impact', CsUi::input('days_impact', fv('days_impact', $c, '0'), ['type' => 'number', 'step' => '1']), 'col-6')
        . CsUi::field(['risk' => 'Mitigation', 'issue' => 'Resolution', 'meeting' => 'Action items'][$kind] ?? 'Response / action', CsUi::textarea('response', fv('response', $c), 2), 'col-12')
        . CsUi::field('Due', CsUi::input('due_date', fv('due_date', $c), ['type' => 'date']), 'col-6')
        . '</div></div><div class="card-footer">' . save_buttons('Save', $row ? url($pageKey, ['project' => $pid, 'kind' => $kind]) : null, false) . '</div></form>';
}
if ($row) {
    echo CsFiles::panel('project_log', (int)$id, Access::can($pageKey, 'edit')) . cs_history('project_log', (int)$id);
}
echo '</div></div>';
layout_end();
