<?php
declare(strict_types=1);

/** RFIs, submittals, shop drawings, material submittals, transmittals and technical approvals, with their answers. */
$pageKey = 'proj.rfis';
$a = (string)g('a', 'list');
$id = gint('id');
$r = $id ? DB::row('SELECT * FROM cs_rfis WHERE id = :id', ['id' => $id]) : null;
if ($r) {
    CsProject::requireAccess((int)$r['project_id']);
}
$kinds = ['rfi' => 'Request for information', 'submittal' => 'Submittal', 'shop_drawing' => 'Shop drawing', 'material_submittal' => 'Material submittal', 'transmittal' => 'Transmittal', 'technical_approval' => 'Technical approval'];
$statuses = ['open' => 'Open', 'answered' => 'Answered', 'approved' => 'Approved', 'approved_as_noted' => 'Approved as noted', 'revise_resubmit' => 'Revise and resubmit', 'rejected' => 'Rejected', 'closed' => 'Closed'];

handle_post(function () use ($pageKey, $id, $r, $kinds, $statuses) {
    if ($r && cs_handle_attach('rfi', (int)$id, (int)$r['project_id'])) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    $act = (string)p('act');
    if ($r && $act === 'respond') {
        Access::require($pageKey, 'edit');
        $st = (string)p('status');
        if (!isset($statuses[$st]) || $st === 'open') {
            fail('Choose the outcome.');
        }
        $row = ['response' => trim((string)p('response')) ?: $r['response'], 'response_date' => pdate('response_date') ?: today(), 'status' => $st];
        DB::update('cs_rfis', $row, 'id = :id', ['id' => $id]);
        Audit::log('respond', 'proj', 'rfi', (int)$id, $r['ref_no'], ['page_key' => $pageKey, 'reason' => $statuses[$st]]);
        if ($r['created_by']) {
            CsNotify::send([(int)$r['created_by']], 'rfi', $r['ref_no'] . ' ' . strtolower($statuses[$st]), mb_substr((string)$row['response'], 0, 300), url($pageKey, ['a' => 'view', 'id' => $id]), (int)$r['project_id']);
        }
        flash('success', 'Response recorded.');
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if ($act === 'save') {
        Access::require($pageKey, $id ? 'edit' : 'create');
        $pid = $r ? (int)$r['project_id'] : (int)p('project_id');
        CsProject::requireAccess($pid);
        $row = ['kind' => isset($kinds[p('kind')]) ? p('kind') : 'rfi', 'subject' => mb_substr(trim((string)p('subject')), 0, 200), 'description' => trim((string)p('description')) ?: null,
            'from_party' => mb_substr(trim((string)p('from_party')), 0, 150) ?: null, 'to_party' => mb_substr(trim((string)p('to_party')), 0, 150) ?: null,
            'date_sent' => pdate('date_sent'), 'date_due' => pdate('date_due'), 'document_id' => pint('document_id')];
        if ($row['subject'] === '') {
            fail('Enter the subject.');
        }
        if ($r) {
            DB::update('cs_rfis', $row, 'id = :id', ['id' => $id]);
            Audit::log('edit', 'proj', 'rfi', (int)$id, $r['ref_no'], ['page_key' => $pageKey]);
        } else {
            $row += ['project_id' => $pid, 'ref_no' => Seq::next('cs_rfi'), 'status' => 'open', 'created_by' => Auth::id()];
            $id = DB::insert('cs_rfis', $row);
            Audit::log('create', 'proj', 'rfi', $id, $row['ref_no'] . ' ' . $row['subject'], ['page_key' => $pageKey]);
        }
        flash('success', 'Saved.');
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
});

if ($a === 'new' || ($a === 'edit' && $r)) {
    Access::require($pageKey, $r ? 'edit' : 'create');
    $pid = $r ? (int)$r['project_id'] : (int)(gint('project') ?? CsProject::current() ?? 0);
    $c = $r ?: ['kind' => (string)g('kind', 'rfi'), 'date_sent' => today(), 'date_due' => date('Y-m-d', strtotime('+7 days')), 'from_party' => Settings::get('company_name')];
    $docs = $pid ? DB::pairs("SELECT id, CONCAT(doc_no, ' ', title) FROM cs_documents WHERE project_id = :p AND status = 'active' ORDER BY doc_no", ['p' => $pid]) : [];
    layout_start($r ? 'Edit ' . $r['ref_no'] : 'New RFI or submittal', ['module' => 'proj']);
    echo '<form method="post" class="card"><div class="card-body"><div class="row g-3">' . csrf_field() . '<input type="hidden" name="act" value="save">'
        . CsUi::field('Project', $r ? '<div class="pt-1">' . CsUi::projectLink($pid) . '</div>' : CsUi::projectSelect('project_id', $pid ?: '', ['required' => true]), 'col-md-6')
        . CsUi::field('Kind', CsUi::select('kind', $kinds, fv('kind', $c), null, 'data-no-combo'), 'col-md-3')
        . CsUi::field('Related document', CsUi::select('document_id', $docs, fv('document_id', $c), 'None'), 'col-md-3')
        . CsUi::field('Subject', CsUi::input('subject', fv('subject', $c), ['required' => true, 'max' => 200]), 'col-12')
        . CsUi::field('Question or what is submitted', CsUi::textarea('description', fv('description', $c), 4), 'col-12')
        . CsUi::field('From', CsUi::input('from_party', fv('from_party', $c), ['max' => 150]), 'col-md-3') . CsUi::field('To', CsUi::input('to_party', fv('to_party', $c), ['max' => 150, 'placeholder' => 'Architect, consultant, client']), 'col-md-3')
        . CsUi::field('Date sent', CsUi::input('date_sent', fv('date_sent', $c), ['type' => 'date']), 'col-6 col-md-3') . CsUi::field('Answer due', CsUi::input('date_due', fv('date_due', $c), ['type' => 'date']), 'col-6 col-md-3')
        . '</div></div><div class="card-footer">' . save_buttons('Save', url($pageKey, $r ? ['a' => 'view', 'id' => $id] : []), false) . '</div></form>';
    layout_end();
    return;
}

if ($a === 'view' && $r) {
    $overdue = $r['status'] === 'open' && $r['date_due'] && $r['date_due'] < today();
    layout_start($r['ref_no'] . ' ' . $r['subject'], ['module' => 'proj', 'sub' => CsUi::projectLink((int)$r['project_id']) . ', ' . h($kinds[$r['kind']] ?? '') . ' ' . CsUi::state($overdue ? 'overdue' : $r['status'], $overdue ? 'Overdue' : null),
        'actions' => cs_link($pageKey, ['a' => 'edit', 'id' => $id], 'Edit', 'btn-outline-secondary', 'pencil', 'edit')]);
    echo '<div class="cs-split"><div class="cs-stack"><div class="card"><div class="card-body">' . CsUi::facts(['From' => h((string)$r['from_party']), 'To' => h((string)$r['to_party']), 'Sent' => h(fdate($r['date_sent'])),
        'Answer due' => '<span class="' . ($overdue ? 'cs-bad' : '') . '">' . h(fdate($r['date_due'])) . '</span>',
        'Document' => $r['document_id'] ? '<a href="' . h(url('proj.documents', ['a' => 'view', 'id' => $r['document_id']])) . '">' . h((string)DB::value("SELECT CONCAT(doc_no, ' ', title) FROM cs_documents WHERE id = :d", ['d' => $r['document_id']])) . '</a>' : '']) . '</div>'
        . ($r['description'] ? '<div class="card-body border-top">' . nl2br(h($r['description'])) . '</div>' : '') . '</div>';
    if ($r['response']) {
        echo '<div class="card"><div class="card-header">Response, ' . h(fdate($r['response_date'])) . '</div><div class="card-body">' . nl2br(h($r['response'])) . '</div></div>';
    }
    echo CsFiles::panel('rfi', $id, Access::can($pageKey, 'edit')) . '</div><div class="cs-stack">';
    if (Access::can($pageKey, 'edit') && $r['status'] !== 'closed') {
        echo '<form method="post" class="card"><div class="card-header">Record the response</div><div class="card-body"><div class="row g-2">' . csrf_field() . '<input type="hidden" name="act" value="respond">'
            . CsUi::field('Outcome', CsUi::select('status', array_diff_key($statuses, ['open' => 1]), $r['status'] === 'open' ? 'answered' : $r['status'], null, 'data-no-combo'), 'col-7')
            . CsUi::field('Date', CsUi::input('response_date', today(), ['type' => 'date']), 'col-5') . CsUi::field('Response', CsUi::textarea('response', (string)$r['response'], 4), 'col-12')
            . '</div></div><div class="card-footer"><button class="btn btn-primary btn-sm">Save response</button></div></form>';
    }
    echo cs_history('rfi', $id) . '</div></div>';
    layout_end();
    return;
}

$pid = CsProject::current();
$kind = (string)g('kind');
$st = (string)g('status');
layout_start('RFIs & Submittals', ['actions' => cs_link($pageKey, ['a' => 'new', 'project' => $pid], 'New', 'btn-primary', 'plus-lg', 'create')]);
echo cs_filter_bar($pageKey, $pid, cs_search_field('Ref., subject') . cs_status_field($kinds, 'kind', 'Kind') . cs_status_field($statuses));
[$f, $params] = CsProject::filter('r.project_id');
[$w, $wp] = cs_like((string)g('q'), ['r.ref_no', 'r.subject']);
$params += $wp;
if ($pid) {
    $w .= ' AND r.project_id = :pid';
    $params['pid'] = $pid;
}
if (isset($kinds[$kind])) {
    $w .= ' AND r.kind = :k';
    $params['k'] = $kind;
}
if (isset($statuses[$st])) {
    $w .= ' AND r.status = :st';
    $params['st'] = $st;
}
$rows = DB::all("SELECT r.*, p.code AS pcode FROM cs_rfis r JOIN cs_projects p ON p.id = r.project_id WHERE 1 = 1 $f $w ORDER BY (r.status = 'open') DESC, r.date_due, r.id DESC LIMIT 500", $params);
$out = [];
foreach ($rows as $x) {
    $overdue = $x['status'] === 'open' && $x['date_due'] && $x['date_due'] < today();
    $out[] = ['<a class="cs-main-link" href="' . h(url($pageKey, ['a' => 'view', 'id' => $x['id']])) . '">' . h($x['ref_no']) . '</a><span class="cs-sub">' . h($x['subject']) . '</span>', h($x['pcode']), h($kinds[$x['kind']] ?? ''),
        h((string)$x['to_party']), h(fdate($x['date_sent'])), '<span class="' . ($overdue ? 'cs-bad' : '') . '">' . h(fdate($x['date_due'])) . '</span>', CsUi::state($overdue ? 'overdue' : $x['status'], $overdue ? 'Overdue' : null)];
}
echo cs_table_card(count($rows) . ' records', ['Ref.' => '', 'Project' => '', 'Kind' => '', 'To' => '', 'Sent' => '', 'Due' => '', 'Status' => ''], $out, 'Nothing recorded.');
layout_end();
