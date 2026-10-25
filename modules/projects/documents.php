<?php
declare(strict_types=1);

/**
 * Document control: specifications, drawings, permits, method statements and other project documents, each with its
 * revisions. A new revision waits for approval; once approved it becomes the current one and the previous one is
 * marked superseded, so the site never works from an old drawing by mistake.
 */
$pageKey = 'proj.documents';
$a = (string)g('a', 'list');
$id = gint('id');
$doc = $id ? DB::row('SELECT * FROM cs_documents WHERE id = :id', ['id' => $id]) : null;
if ($doc && $doc['project_id']) {
    CsProject::requireAccess((int)$doc['project_id']);
}
$cats = ['specification' => 'Specification', 'drawing' => 'Drawing', 'boq' => 'Bill of quantities', 'method_statement' => 'Method statement', 'permit' => 'Permit',
    'contract' => 'Contract document', 'photo' => 'Photo record', 'as_built' => 'As-built', 'manual' => 'Manual / warranty', 'report' => 'Report', 'other' => 'Other'];
$disciplines = ['general' => 'General', 'architectural' => 'Architectural', 'structural' => 'Structural', 'civil' => 'Civil', 'mechanical' => 'Mechanical', 'plumbing' => 'Plumbing', 'electrical' => 'Electrical'];

handle_post(function () use ($pageKey, $id, $doc, $cats, $disciplines) {
    $act = (string)p('act');
    if ($act === 'save') {
        Access::require($pageKey, $id ? 'edit' : 'create');
        $pid = $doc ? ($doc['project_id'] ? (int)$doc['project_id'] : null) : pint('project_id');
        if ($pid) {
            CsProject::requireAccess($pid);
        }
        $row = ['category' => isset($cats[p('category')]) ? p('category') : 'other', 'discipline' => isset($disciplines[p('discipline')]) ? p('discipline') : 'general',
            'doc_no' => mb_substr(trim((string)p('doc_no')), 0, 60), 'title' => mb_substr(trim((string)p('title')), 0, 200)];
        if ($row['title'] === '') {
            fail('Enter the document title.');
        }
        if ($row['doc_no'] === '') {
            $row['doc_no'] = Seq::next('cs_doc');
        }
        if (DB::value('SELECT id FROM cs_documents WHERE doc_no = :n AND (project_id <=> :p)' . ($id ? ' AND id <> :id' : ''), ['n' => $row['doc_no'], 'p' => $pid] + ($id ? ['id' => $id] : []))) {
            fail('Document no. ' . $row['doc_no'] . ' already exists in this project.');
        }
        if ($doc) {
            DB::update('cs_documents', $row, 'id = :id', ['id' => $id]);
            Audit::log('edit', 'proj', 'document', (int)$id, $row['doc_no'], ['page_key' => $pageKey]);
        } else {
            $id = DB::insert('cs_documents', $row + ['project_id' => $pid, 'contract_id' => $pid ? CsProject::get($pid)['contract_id'] : null, 'status' => 'active', 'created_by' => Auth::id()]);
            Audit::log('create', 'proj', 'document', $id, $row['doc_no'] . ' ' . $row['title'], ['page_key' => $pageKey]);
        }
        if (!empty($_FILES['file']['name'])) {
            $f = CsFiles::store($_FILES['file']['tmp_name'], $_FILES['file']['name'], (int)$_FILES['file']['error'], (int)$_FILES['file']['size']);
            DB::insert('cs_document_revisions', ['document_id' => $id, 'rev_no' => mb_substr(trim((string)p('rev_no')) ?: '0', 0, 10), 'file_path' => $f['path'], 'file_name' => $f['name'],
                'mime' => $f['mime'], 'file_size' => $f['size'], 'notes' => mb_substr(trim((string)p('rev_notes')), 0, 255) ?: null, 'status' => 'for_approval', 'uploaded_by' => Auth::id()]);
        }
        flash('success', 'Document saved.');
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if (!$doc) {
        return;
    }
    if ($act === 'revision') {
        Access::require($pageKey, 'edit');
        if (empty($_FILES['file']['name'])) {
            fail('Choose the file of the new revision.');
        }
        $rev = mb_substr(trim((string)p('rev_no')), 0, 10);
        if ($rev === '' || DB::value('SELECT id FROM cs_document_revisions WHERE document_id = :d AND rev_no = :r', ['d' => $id, 'r' => $rev])) {
            fail('Give a revision number that is not used yet.');
        }
        $f = CsFiles::store($_FILES['file']['tmp_name'], $_FILES['file']['name'], (int)$_FILES['file']['error'], (int)$_FILES['file']['size']);
        DB::insert('cs_document_revisions', ['document_id' => $id, 'rev_no' => $rev, 'file_path' => $f['path'], 'file_name' => $f['name'], 'mime' => $f['mime'], 'file_size' => $f['size'],
            'notes' => mb_substr(trim((string)p('rev_notes')), 0, 255) ?: null, 'status' => 'for_approval', 'uploaded_by' => Auth::id()]);
        Audit::log('revision', 'proj', 'document', (int)$id, $doc['doc_no'] . ' rev. ' . $rev, ['page_key' => $pageKey, 'reason' => (string)p('rev_notes')]);
        flash('success', 'Revision ' . $rev . ' uploaded. It becomes current once approved.');
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if (in_array($act, ['approve_rev', 'reject_rev'], true)) {
        Access::require($pageKey, 'approve');
        DB::tx(function () use ($id, $doc, $act, $pageKey) {
            $r = DB::row("SELECT * FROM cs_document_revisions WHERE id = :r AND document_id = :d AND status = 'for_approval'", ['r' => (int)p('rev_id'), 'd' => $id]);
            if (!$r) {
                fail('Revision not found or already decided.');
            }
            if ((int)$r['uploaded_by'] === (int)Auth::id() && Settings::get('cs_self_approval', '0') !== '1') {
                fail('You uploaded this revision, so someone else approves it (maker-checker).');
            }
            if ($act === 'reject_rev' && trim((string)p('reason')) === '') {
                fail('Give the reason for rejecting the revision.');
            }
            if ($act === 'approve_rev') {
                DB::run("UPDATE cs_document_revisions SET status = 'superseded' WHERE document_id = :d AND status = 'approved'", ['d' => $id]);
                DB::update('cs_document_revisions', ['status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $r['id']]);
                DB::update('cs_documents', ['current_rev' => $r['rev_no']], 'id = :id', ['id' => $id]);
            } else {
                DB::update('cs_document_revisions', ['status' => 'rejected', 'notes' => mb_substr(trim((string)$r['notes'] . ' Rejected: ' . p('reason')), 0, 255)], 'id = :id', ['id' => $r['id']]);
            }
            Audit::log($act === 'approve_rev' ? 'approve' : 'reject', 'proj', 'document', (int)$id, $doc['doc_no'] . ' rev. ' . $r['rev_no'], ['page_key' => $pageKey, 'reason' => (string)p('reason')]);
        });
        flash('success', $act === 'approve_rev' ? 'Revision approved: it is now the current one and the previous one is superseded.' : 'Revision rejected.');
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if ($act === 'void') {
        Access::require($pageKey, 'delete');
        if (trim((string)p('reason')) === '') {
            fail('Give the reason.');
        }
        DB::update('cs_documents', ['status' => $doc['status'] === 'void' ? 'active' : 'void'], 'id = :id', ['id' => $id]);
        Audit::log($doc['status'] === 'void' ? 'restore' : 'void', 'proj', 'document', (int)$id, $doc['doc_no'], ['page_key' => $pageKey, 'reason' => (string)p('reason')]);
        flash('success', $doc['status'] === 'void' ? 'Document restored.' : 'Document marked void.');
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
});

if ($a === 'new' || ($a === 'edit' && $doc)) {
    Access::require($pageKey, $doc ? 'edit' : 'create');
    $pid = $doc ? (int)$doc['project_id'] : (int)(gint('project') ?? CsProject::current() ?? 0);
    $c = $doc ?: ['category' => 'drawing', 'discipline' => 'architectural'];
    layout_start($doc ? 'Edit ' . $doc['doc_no'] : 'New document', ['module' => 'proj']);
    echo '<form method="post" enctype="multipart/form-data" class="card"><div class="card-body"><div class="row g-3">' . csrf_field() . '<input type="hidden" name="act" value="save">'
        . CsUi::field('Project', $doc ? '<div class="pt-1">' . ($pid ? CsUi::projectLink($pid) : 'Company-wide') . '</div>' : CsUi::projectSelect('project_id', $pid ?: '', ['blank' => 'Company-wide (no project)']), 'col-md-6')
        . CsUi::field('Category', CsUi::select('category', $cats, fv('category', $c), null, 'data-no-combo'), 'col-6 col-md-3')
        . CsUi::field('Discipline', CsUi::select('discipline', $disciplines, fv('discipline', $c), null, 'data-no-combo'), 'col-6 col-md-3')
        . CsUi::field('Document no.', CsUi::input('doc_no', fv('doc_no', $c), ['max' => 60, 'placeholder' => 'A-101 (blank: next number)']), 'col-md-3')
        . CsUi::field('Title', CsUi::input('title', fv('title', $c), ['required' => true, 'max' => 200]), 'col-md-9')
        . (!$doc ? CsUi::field('First revision file', '<input class="form-control form-control-sm" type="file" name="file">', 'col-md-6') . CsUi::field('Revision no.', CsUi::input('rev_no', '0', ['max' => 10]), 'col-6 col-md-2')
            . CsUi::field('Revision notes', CsUi::input('rev_notes', '', ['max' => 255]), 'col-6 col-md-4') : '')
        . '</div></div><div class="card-footer">' . save_buttons('Save document', url($pageKey, $doc ? ['a' => 'view', 'id' => $id] : ['project' => $pid]), false) . '</div></form>';
    layout_end();
    return;
}

if ($a === 'view' && $doc) {
    $revs = DB::all('SELECT r.*, u.full_name AS uploader, ap.full_name AS approver FROM cs_document_revisions r LEFT JOIN users u ON u.id = r.uploaded_by LEFT JOIN users ap ON ap.id = r.approved_by
                      WHERE r.document_id = :d ORDER BY r.id DESC', ['d' => $id]);
    $rfis = DB::all('SELECT * FROM cs_rfis WHERE document_id = :d ORDER BY id DESC', ['d' => $id]);
    layout_start($doc['doc_no'] . ' ' . $doc['title'], ['module' => 'proj', 'sub' => ($doc['project_id'] ? CsUi::projectLink((int)$doc['project_id']) . ', ' : '') . h($cats[$doc['category']] ?? '') . ', ' . h($disciplines[$doc['discipline']] ?? '')
        . ($doc['status'] === 'void' ? ' ' . CsUi::state('void') : ''), 'actions' => cs_link($pageKey, ['a' => 'edit', 'id' => $id], 'Edit', 'btn-outline-secondary', 'pencil', 'edit')]);
    echo '<div class="cs-split"><div class="cs-stack">';
    $rows = [];
    foreach ($revs as $r) {
        $btn = '';
        if ($r['status'] === 'for_approval' && Access::can($pageKey, 'approve')) {
            $f = '<input type="hidden" name="rev_id" value="' . (int)$r['id'] . '">';
            $btn = CsUi::confirmButton('ra' . $r['id'], 'Approve', 'Approve revision ' . $r['rev_no'], 'It becomes the current revision; the previous one is marked superseded.', $f . '<input type="hidden" name="act" value="approve_rev">', 'btn-success', 'check2')
                . ' ' . CsUi::confirmButton('rr' . $r['id'], 'Reject', 'Reject revision ' . $r['rev_no'], 'The revision stays on file as rejected.', $f . '<input type="hidden" name="act" value="reject_rev">', 'btn-outline-danger', 'x-lg', true);
        }
        $rows[] = ['_class' => $r['status'] === 'superseded' ? 'text-muted' : '', '<strong>' . h($r['rev_no']) . '</strong>', $r['file_path'] ? '<a href="' . h(url('file.cs', ['rev' => $r['id'], 'inline' => 1])) . '" target="_blank" rel="noopener"><i class="bi bi-file-earmark"></i> ' . h($r['file_name']) . '</a>' : '',
            h((string)$r['notes']), h((string)$r['uploader']) . '<span class="cs-sub">' . h(fdatetime($r['created_at'])) . '</span>',
            CsUi::state($r['status']) . ($r['approver'] ? '<span class="cs-sub">' . h($r['approver']) . ', ' . h(fdatetime($r['approved_at'])) . '</span>' : ''), $btn];
    }
    echo cs_table_card('Revisions (current: ' . ($doc['current_rev'] ?? 'none approved') . ')', ['Rev.' => '', 'File' => '', 'Notes' => '', 'Uploaded' => '', 'Status' => '', '' => ''], $rows, 'No revision uploaded yet.');
    $rows = [];
    foreach ($rfis as $r) {
        $rows[] = ['<a href="' . h(url('proj.rfis', ['a' => 'view', 'id' => $r['id']])) . '">' . h($r['ref_no']) . '</a>', h($r['subject']), CsUi::state($r['status'])];
    }
    if ($rows) {
        echo cs_table_card('RFIs and submittals about this document', ['Ref.' => '', 'Subject' => '', 'Status' => ''], $rows);
    }
    echo '</div><div class="cs-stack">';
    if (Access::can($pageKey, 'edit') && $doc['status'] === 'active') {
        echo '<form method="post" enctype="multipart/form-data" class="card"><div class="card-header">Upload a new revision</div><div class="card-body"><div class="row g-2">' . csrf_field() . '<input type="hidden" name="act" value="revision">'
            . '<div class="col-12"><input class="form-control form-control-sm" type="file" name="file" required></div>'
            . CsUi::field('Revision no.', CsUi::input('rev_no', '', ['max' => 10, 'required' => true, 'placeholder' => 'A, B, 1, 2']), 'col-5') . CsUi::field('What changed', CsUi::input('rev_notes', '', ['max' => 255]), 'col-7')
            . '</div></div><div class="card-footer"><button class="btn btn-outline-primary btn-sm"><i class="bi bi-upload"></i> Upload revision</button></div></form>';
    }
    if (Access::can($pageKey, 'delete')) {
        echo '<div class="card"><div class="card-body">' . CsUi::confirmButton('docVoid', $doc['status'] === 'void' ? 'Restore document' : 'Mark void', $doc['status'] === 'void' ? 'Restore the document' : 'Mark the document void',
            'Void documents stay on file but drop out of the current register.', '<input type="hidden" name="act" value="void">', 'btn-outline-secondary', 'slash-circle', true) . '</div></div>';
    }
    echo cs_history('document', $id) . '</div></div>';
    layout_end();
    return;
}

$pid = CsProject::current();
$cat = (string)g('category');
layout_start('Documents & Specifications', ['actions' => cs_link($pageKey, ['a' => 'new', 'project' => $pid], 'New document', 'btn-primary', 'plus-lg', 'create')]);
echo cs_filter_bar($pageKey, $pid, cs_search_field('Document no., title') . cs_status_field($cats, 'category', 'Category'));
$params = [];
$w = '';
$acc = CsProject::accessibleIds();
if ($acc !== null) {
    [$in, $pp] = DB::in('dacc', $acc ?: [0]);
    $w .= " AND (d.project_id IS NULL OR d.project_id IN $in)";
    $params += $pp;
}
[$lw, $lp] = cs_like((string)g('q'), ['d.doc_no', 'd.title']);
$w .= $lw;
$params += $lp;
if ($pid) {
    $w .= ' AND d.project_id = :pid';
    $params['pid'] = $pid;
}
if (isset($cats[$cat])) {
    $w .= ' AND d.category = :cat';
    $params['cat'] = $cat;
}
$rows = DB::all("SELECT d.*, p.code AS pcode, (SELECT COUNT(*) FROM cs_document_revisions r WHERE r.document_id = d.id AND r.status = 'for_approval') AS waiting
                   FROM cs_documents d LEFT JOIN cs_projects p ON p.id = d.project_id WHERE 1 = 1 $w ORDER BY d.status, p.code, d.category, d.doc_no LIMIT 600", $params);
$out = [];
foreach ($rows as $r) {
    $out[] = ['_class' => $r['status'] === 'void' ? 'text-muted' : '', '<a class="cs-main-link" href="' . h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) . '">' . h($r['doc_no']) . '</a><span class="cs-sub">' . h($r['title']) . '</span>',
        h((string)($r['pcode'] ?? 'Company-wide')), h($cats[$r['category']] ?? ''), h($disciplines[$r['discipline']] ?? ''), $r['current_rev'] !== null ? 'Rev. ' . h($r['current_rev']) : '<span class="text-muted">None approved</span>',
        (int)$r['waiting'] ? '<span class="cs-warn">' . (int)$r['waiting'] . ' waiting</span>' : '', $r['status'] === 'void' ? CsUi::state('void') : ''];
}
echo cs_table_card(count($rows) . ' documents', ['Document' => '', 'Project' => '', 'Category' => '', 'Discipline' => '', 'Current' => '', 'Revisions' => '', '' => ''], $out, 'No documents yet.');
layout_end();
