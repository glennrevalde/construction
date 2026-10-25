<?php
declare(strict_types=1);

/**
 * Milestones and deliverables: what has to be delivered, the evidence for each deliverable, the review by someone
 * other than the person who submitted it, and the client's certification. The system alone decides when a milestone
 * becomes eligible for billing.
 */
$pageKey = 'plan.milestones';
$a = (string)g('a', 'list');
$id = gint('id');
$m = $id ? DB::row('SELECT * FROM cs_milestones WHERE id = :id', ['id' => $id]) : null;
if ($m) {
    CsProject::requireAccess((int)$m['project_id']);
}

handle_post(function () use ($pageKey, $id, $m) {
    $act = (string)p('act');
    if ($m && $act === 'item_attach') {
        Access::require($pageKey, 'edit');
        $item = (int)p('item_id');
        if (!DB::value('SELECT id FROM cs_milestone_items WHERE id = :i AND milestone_id = :m', ['i' => $item, 'm' => $id])) {
            fail('Deliverable not found.');
        }
        $ids = CsFiles::attach('files', 'milestone_item', $item, (int)$m['project_id'], (string)p('caption'));
        if (!$ids) {
            fail('Choose the file(s) to attach.');
        }
        if (DB::value('SELECT status FROM cs_milestone_items WHERE id = :i', ['i' => $item]) === 'pending') {
            DB::update('cs_milestone_items', ['status' => 'in_progress', 'updated_by' => Auth::id(), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $item]);
            if ($m['status'] === 'pending') {
                DB::update('cs_milestones', ['status' => 'in_progress'], 'id = :id', ['id' => $id]);
            }
        }
        Audit::log('attach', 'plan', 'milestone', (int)$id, $m['code'], ['page_key' => $pageKey, 'reason' => count($ids) . ' evidence file(s)']);
        flash('success', count($ids) . ' file(s) attached as evidence.');
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if ($m && $act === 'item_status') {
        if (!in_array(p('status'), ['accepted', 'rejected', 'completed'], true)) {
            Access::require($pageKey, 'edit');
        }
        CsPlanning::msItemStatus((int)p('item_id'), (string)p('status'), (string)p('remarks'));
        flash('success', 'Deliverable updated.');
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if ($m && in_array($act, ['submit', 'review', 'accept', 'reject', 'certify'], true)) {
        Access::require($pageKey, $act === 'submit' ? 'edit' : 'view');
        CsPlanning::milestoneAction((int)$id, $act, ['reason' => p('reason'), 'completion_pct' => p('completion_pct'), 'date' => p('cert_date'), 'ref' => p('cert_ref')]);
        $now = DB::row('SELECT status, billing_eligible FROM cs_milestones WHERE id = :id', ['id' => $id]);
        flash('success', 'Milestone ' . strtolower(CsUi::label($now['status'])) . '.' . ((int)$now['billing_eligible'] ? ' It is now eligible for billing.' : ''));
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if ($act === 'save') {
        Access::require($pageKey, $id ? 'edit' : 'create');
        $nid = CsPlanning::saveMilestone($_POST, cs_lines('items'), $id);
        flash('success', 'Milestone saved.');
        cs_go($pageKey, ['a' => 'view', 'id' => $nid]);
    }
});

// ---------------------------------------------------------------- form
if ($a === 'new' || ($a === 'edit' && $m)) {
    Access::require($pageKey, $m ? 'edit' : 'create');
    $pid = $m ? (int)$m['project_id'] : (int)(gint('project') ?? CsProject::current() ?? 0);
    $proj = $pid ? CsProject::get($pid) : null;
    $c = $m ?: ['project_id' => $pid, 'measurement_method' => 'physical_pct', 'client_cert_required' => 1, 'responsible_user_id' => $proj['engineer_user_id'] ?? null, 'reviewer_user_id' => $proj['pm_user_id'] ?? null];
    if (is_post()) {
        $items = cs_lines('items');
    } elseif ($m) {
        $items = DB::all('SELECT * FROM cs_milestone_items WHERE milestone_id = :m ORDER BY line_no', ['m' => $id]);
    } else {
        $tpl = (string)DB::value("SELECT items FROM cs_checklist_templates WHERE kind = 'milestone' AND is_active = 1 ORDER BY id LIMIT 1");
        $guess = ['Accomplishment' => 'accomplishment_report', 'photo' => 'progress_photos', 'Inspection' => 'inspection_report', 'Test' => 'test_results', 'certification' => 'engineer_certification', 'acceptance' => 'signed_acceptance'];
        $items = [];
        foreach (array_filter(array_map('trim', explode("\n", $tpl))) as $line) {
            $type = 'other';
            foreach ($guess as $needle => $t) {
                if (stripos($line, $needle) !== false) {
                    $type = $t;
                    break;
                }
            }
            $items[] = ['description' => $line, 'doc_type' => $type, 'is_required' => stripos($line, 'where required') === false ? 1 : 0];
        }
    }
    $acts = $pid ? DB::pairs("SELECT id, CONCAT(wbs_code, ' ', name) FROM cs_activities WHERE project_id = :p ORDER BY sort_order", ['p' => $pid]) : [];
    $users = cs_user_options();
    $itemRow = function (array $it, string $i): string {
        $n = fn($f) => 'items[' . $i . '][' . $f . ']';
        return '<tr><td class="w-desc"><input type="hidden" name="' . $n('id') . '" value="' . h($it['id'] ?? '') . '"><input class="form-control form-control-sm" name="' . $n('description') . '" value="' . h($it['description'] ?? '') . '"></td>'
            . '<td>' . CsUi::select($n('doc_type'), CsPlanning::MS_DOC_TYPES, $it['doc_type'] ?? 'other', null, 'data-no-combo') . '</td>'
            . '<td class="text-center"><input class="form-check-input" type="checkbox" name="' . $n('is_required') . '" value="1"' . (!isset($it['is_required']) || (int)$it['is_required'] ? ' checked' : '') . ' aria-label="Required"></td>'
            . '<td class="grid-acts"><button type="button" class="btn btn-link btn-sm text-danger p-0" data-cs-del aria-label="Remove"><i class="bi bi-x-lg"></i></button></td></tr>';
    };
    layout_start($m ? 'Edit milestone ' . $m['code'] : 'New milestone', ['module' => 'plan', 'sub' => $pid ? CsUi::projectLink($pid) : '']);
    ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="save">
    <div class="card mb-3"><div class="card-body"><div class="row g-3">
      <?= CsUi::field('Project', $m ? '<input type="hidden" name="project_id" value="' . $pid . '"><div class="pt-1">' . CsUi::projectLink($pid) . '</div>' : CsUi::projectSelect('project_id', $pid ?: '', ['required' => true, 'extra' => 'onchange="location.href=\'' . h(url($pageKey, ['a' => 'new'])) . '&project=\'+this.value"']), 'col-md-4') ?>
      <?= CsUi::field('Code', CsUi::input('code', fv('code', $c), ['max' => 30, 'placeholder' => 'Given by the system when blank']), 'col-md-2') ?>
      <?= CsUi::field('Milestone', CsUi::input('name', fv('name', $c), ['required' => true, 'max' => 200]), 'col-md-6') ?>
      <?= CsUi::field('Scheduled completion', CsUi::input('scheduled_date', fv('scheduled_date', $c), ['type' => 'date']), 'col-6 col-md-3') ?>
      <?= CsUi::field('Billable value when certified', CsUi::input('contract_value', fv('contract_value', $c), ['num' => true]), 'col-6 col-md-3') ?>
      <?= CsUi::field('Budget value', CsUi::input('budget_value', fv('budget_value', $c), ['num' => true]), 'col-6 col-md-3') ?>
      <?= CsUi::field('How completion is measured', CsUi::select('measurement_method', CsPlanning::MS_METHODS, fv('measurement_method', $c), null, 'data-no-combo'), 'col-6 col-md-3') ?>
      <?= CsUi::field('Linked activity', CsUi::select('activity_id', $acts, fv('activity_id', $c), 'None'), 'col-md-4') ?>
      <?= CsUi::field('Responsible', CsUi::select('responsible_user_id', $users, fv('responsible_user_id', $c), 'Choose'), 'col-md-4') ?>
      <?= CsUi::field('Reviewer', CsUi::select('reviewer_user_id', $users, fv('reviewer_user_id', $c), 'Anyone with Approve'), 'col-md-4', 'Never the same person as the one responsible.') ?>
      <?= CsUi::field('Scope', CsUi::textarea('scope', fv('scope', $c), 2), 'col-md-6') ?>
      <?= CsUi::field('Required inspections and tests', CsUi::textarea('required_inspections', fv('required_inspections', $c), 2), 'col-md-6') ?>
      <div class="col-12"><?= check_field('client_cert_required', 'The client\'s certification is required before billing', (int)fv('client_cert_required', $c, 1) === 1, '') ?></div>
    </div></div></div>
    <div class="card mb-3"><div class="card-header">Deliverables and evidence required</div>
      <div class="table-responsive"><table class="table table-sm cs-lines mb-0" id="msItems" data-cs-lines data-min-rows="1"><thead><tr><th>Deliverable</th><th>Kind of evidence</th><th>Required</th><th></th></tr></thead><tbody>
      <?php foreach ($items as $i => $it) { echo $itemRow($it, 'e' . $i); } ?></tbody></table></div>
      <template data-cs-row="msItems"><?= $itemRow([], '__i__') ?></template>
      <div class="card-body pt-2"><button type="button" class="btn btn-outline-secondary btn-sm" data-cs-add="msItems"><i class="bi bi-plus"></i> Add a deliverable</button></div></div>
    <div class="card"><div class="card-body"><?= CsUi::field('Notes', CsUi::textarea('notes', fv('notes', $c), 2), 'col-12') ?></div></div>
    <div class="sticky-actions mt-3"><?= save_buttons('Save milestone', url($pageKey, $m ? ['a' => 'view', 'id' => $id] : ['project' => $pid]), false) ?></div>
    </form>
    <?php
    layout_end();
    return;
}

// ---------------------------------------------------------------- one milestone
if ($a === 'view' && $m) {
    $pid = (int)$m['project_id'];
    $items = DB::all('SELECT i.*, u.full_name AS by_name FROM cs_milestone_items i LEFT JOIN users u ON u.id = i.updated_by WHERE i.milestone_id = :m ORDER BY i.line_no', ['m' => $id]);
    $reviewer = CsPlanning::isMsReviewer($m);
    $canEdit = Access::can($pageKey, 'edit');
    $editable = in_array($m['status'], ['pending', 'in_progress', 'rejected'], true);
    $act = $m['activity_id'] ? DB::row('SELECT * FROM cs_activities WHERE id = :a', ['a' => $m['activity_id']]) : null;
    $users = DB::pairs('SELECT id, full_name FROM users');
    $late = $m['scheduled_date'] && $m['scheduled_date'] < today() && !in_array($m['status'], ['accepted', 'completed'], true);
    layout_start($m['code'] . ' ' . $m['name'], ['module' => 'plan', 'sub' => CsUi::projectLink($pid) . ' ' . CsUi::state($m['status']),
        'actions' => ($canEdit && $editable ? cs_link($pageKey, ['a' => 'edit', 'id' => $id], 'Edit', 'btn-outline-secondary', 'pencil', 'edit') : '')
            . ((int)$m['billing_eligible'] ? ' ' . cs_link('pbill.billings', ['a' => 'new', 'project' => $pid, 'type' => 'milestone'], 'Bill this milestone', 'btn-primary', 'receipt', 'create') : '')]);
    echo '<div class="cs-kpis">' . CsUi::kpi('Billable value', CsUi::amt($m['contract_value']), 'Budget ' . money($m['budget_value']), 'key')
        . CsUi::kpi('Approved completion', CsUi::pct((float)$m['completion_pct']), $act ? 'Activity ' . h($act['wbs_code']) . ' is ' . CsUi::pct((float)$act['progress_pct']) . ' done' : h(CsPlanning::MS_METHODS[$m['measurement_method']] ?? ''))
        . CsUi::kpi('Scheduled', h(fdate($m['scheduled_date'])), $late ? '<span class="cs-bad">Late</span>' : ($m['accepted_date'] ? 'Accepted ' . fdate($m['accepted_date']) : ''), $late ? 'bad' : '')
        . CsUi::kpi('Billing', (int)$m['billing_eligible'] ? 'Eligible' : 'Not yet', (int)$m['billing_eligible'] ? 'Certified and accepted' : h(implode('; ', CsPlanning::msStatusReasons($m))), (int)$m['billing_eligible'] ? 'ok' : 'warn') . '</div>';
    echo '<div class="cs-split"><div class="cs-stack">';
    // deliverables
    echo '<div class="card"><div class="card-header">Deliverables</div><div class="card-body">';
    if (!$items) {
        echo '<p class="cs-note mb-0">No deliverables listed. Edit the milestone to add them.</p>';
    }
    foreach ($items as $it) {
        $files = CsFiles::list('milestone_item', (int)$it['id']);
        echo '<div class="cs-check"><div><div class="fw-semibold">' . h($it['description']) . ((int)$it['is_required'] ? '' : ' <span class="cs-note">(optional)</span>') . '</div>'
            . '<div class="cs-note">' . h(CsPlanning::MS_DOC_TYPES[$it['doc_type']] ?? '') . ($it['by_name'] ? ', updated by ' . h($it['by_name']) . ' ' . h(fdatetime($it['updated_at'])) : '') . '</div>'
            . ($it['remarks'] ? '<div class="small ' . ($it['status'] === 'rejected' ? 'cs-bad' : '') . '">' . h($it['remarks']) . '</div>' : '');
        if ($files) {
            echo '<div class="d-flex flex-wrap gap-1 mt-1">';
            foreach ($files as $f) {
                echo CsFiles::isImage($f) ? '<a href="' . h(CsFiles::url((int)$f['id'])) . '" target="_blank" rel="noopener"><img src="' . h(CsFiles::url((int)$f['id'])) . '" alt="' . h($f['caption'] ?: $f['file_name']) . '" style="width:64px;height:48px;object-fit:cover;border-radius:4px"></a>'
                    : '<a class="small" href="' . h(CsFiles::url((int)$f['id'], false)) . '"><i class="bi bi-paperclip"></i>' . h($f['file_name']) . '</a>';
            }
            echo '</div>';
        }
        echo '</div><div>' . CsUi::state($it['status']);
        if ($canEdit || $reviewer) {
            $opts = [];
            if ($canEdit && !in_array($m['status'], ['accepted', 'completed'], true)) {
                $opts += ['in_progress' => 'In progress', 'submitted' => 'Submitted'];
            }
            if ($reviewer && in_array($m['status'], ['submitted', 'under_review', 'in_progress', 'pending'], true)) {
                $opts += ['accepted' => 'Accept', 'rejected' => 'Reject'];
            }
            if ($opts) {
                echo '<details class="mt-1 small"><summary>Update</summary><form method="post" class="mt-1">' . csrf_field() . '<input type="hidden" name="act" value="item_status"><input type="hidden" name="item_id" value="' . (int)$it['id'] . '">'
                    . CsUi::select('status', $opts, '', null, 'data-no-combo') . '<input class="form-control form-control-sm mt-1" name="remarks" placeholder="Remarks (needed to reject)"><button class="btn btn-outline-primary btn-sm mt-1">Save</button></form>'
                    . '<form method="post" enctype="multipart/form-data" class="mt-2">' . csrf_field() . '<input type="hidden" name="act" value="item_attach"><input type="hidden" name="item_id" value="' . (int)$it['id'] . '">'
                    . '<input class="form-control form-control-sm" type="file" name="files[]" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx"><input class="form-control form-control-sm mt-1" name="caption" placeholder="Caption"><button class="btn btn-outline-secondary btn-sm mt-1"><i class="bi bi-upload"></i> Attach evidence</button></form></details>';
            }
        }
        echo '</div></div>';
    }
    echo '</div></div>';
    echo '<div class="card"><div class="card-header">Details</div><div class="card-body">' . CsUi::facts([
        'Measured by' => h(CsPlanning::MS_METHODS[$m['measurement_method']] ?? ''), 'Responsible' => h((string)($users[$m['responsible_user_id']] ?? '')),
        'Reviewer' => h((string)($users[$m['reviewer_user_id']] ?? 'Anyone with Approve')), 'Linked activity' => $act ? h($act['wbs_code'] . ' ' . $act['name']) : '',
        'Client certification' => (int)$m['client_cert_required'] ? ($m['client_certified_date'] ? 'Certified ' . h(fdate($m['client_certified_date'])) . ($m['client_cert_ref'] ? ', ref. ' . h($m['client_cert_ref']) : '') : 'Required, not yet recorded') : 'Not required',
    ]) . '</div>';
    foreach (['scope' => 'Scope', 'required_inspections' => 'Required inspections and tests', 'deliverables' => 'Deliverables', 'notes' => 'Notes'] as $f => $l) {
        if (trim((string)$m[$f]) !== '') {
            echo '<div class="card-body border-top"><div class="cs-note mb-1">' . h($l) . '</div>' . nl2br(h($m[$f])) . '</div>';
        }
    }
    echo '</div></div><div class="cs-stack">';
    // review panel
    echo '<div class="card"><div class="card-header">Review</div><div class="card-body">';
    $btns = '';
    if ($canEdit && in_array($m['status'], ['pending', 'in_progress', 'rejected'], true)) {
        $btns .= CsUi::confirmButton('msSubmit', 'Submit for review', 'Submit ' . $m['code'] . ' for review', 'Every required deliverable must be submitted with its evidence. The reviewer is notified.', '<input type="hidden" name="act" value="submit">', 'btn-primary', 'send');
    }
    if ($reviewer && $m['status'] === 'submitted') {
        $btns .= '<form method="post" class="d-inline">' . csrf_field() . '<button class="btn btn-outline-secondary btn-sm" name="act" value="review"><i class="bi bi-search"></i> Start review</button></form>';
    }
    if ($reviewer && in_array($m['status'], ['submitted', 'under_review'], true)) {
        $btns .= '<button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#msAccept"><i class="bi bi-check2"></i> Accept</button>'
            . '<div class="modal fade" id="msAccept" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" method="post">' . csrf_field() . '<input type="hidden" name="act" value="accept">'
            . '<div class="modal-header"><h5 class="modal-title">Accept ' . h($m['code']) . '</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
            . '<div class="modal-body"><label class="form-label">Approved completion %</label><input class="form-control" name="completion_pct" value="' . h($act ? number_format(min(100, (float)$act['progress_pct']), 2, '.', '') : '100') . '">'
            . '<label class="form-label mt-2">Comment</label><textarea class="form-control" name="reason" rows="2"></textarea></div>'
            . '<div class="modal-footer"><button class="btn btn-success">Accept</button></div></form></div></div>';
    }
    if ($reviewer && in_array($m['status'], ['submitted', 'under_review', 'accepted'], true)) {
        $btns .= CsUi::confirmButton('msReject', 'Reject', 'Reject ' . $m['code'], 'It goes back to the person responsible with your reason.', '<input type="hidden" name="act" value="reject">', 'btn-outline-danger', 'x-lg', true);
    }
    echo $btns ? '<div class="d-flex flex-wrap gap-1">' . $btns . '</div>' : '<p class="cs-note mb-0">' . ($m['status'] === 'completed' ? 'Completed.' : 'Nothing for you to do now.') . '</p>';
    echo '</div></div>';
    if ((int)$m['client_cert_required'] && !$m['client_certified_date'] && (Access::can($pageKey, 'approve') || Access::isAdmin()) && in_array($m['status'], ['submitted', 'under_review', 'accepted', 'completed'], true)) {
        echo '<form method="post" class="card"><div class="card-header">Client certification</div><div class="card-body"><div class="row g-2">' . csrf_field() . '<input type="hidden" name="act" value="certify">'
            . CsUi::field('Date certified', CsUi::input('cert_date', today(), ['type' => 'date']), 'col-6') . CsUi::field('Certificate ref.', CsUi::input('cert_ref', '', ['max' => 60]), 'col-6')
            . ($m['measurement_method'] === 'client_certified' ? CsUi::field('Certified completion %', CsUi::input('completion_pct', number_format((float)$m['completion_pct'], 2, '.', ''), ['num' => true]), 'col-12') : '')
            . '</div></div><div class="card-footer"><button class="btn btn-outline-primary btn-sm">Record certification</button></div></form>';
    }
    echo cs_history('milestone', $id);
    echo '</div></div>';
    layout_end();
    return;
}

// ---------------------------------------------------------------- the list
$pid = CsProject::current();
$st = (string)g('status');
layout_start('Milestones & Deliverables', ['actions' => cs_link($pageKey, ['a' => 'new', 'project' => $pid], 'New milestone', 'btn-primary', 'plus-lg', 'create')]);
$statuses = ['pending' => 'Pending', 'in_progress' => 'In progress', 'submitted' => 'Submitted', 'under_review' => 'Under review', 'accepted' => 'Accepted', 'rejected' => 'Rejected', 'completed' => 'Completed'];
echo cs_filter_bar($pageKey, $pid, cs_status_field($statuses));
[$f, $params] = CsProject::filter('m.project_id');
$w = '';
if ($pid) {
    $w .= ' AND m.project_id = :pid';
    $params['pid'] = $pid;
}
if (isset($statuses[$st])) {
    $w .= ' AND m.status = :st';
    $params['st'] = $st;
}
$rows = DB::all("SELECT m.*, p.code AS pcode, p.name AS pname,
                        (SELECT COUNT(*) FROM cs_milestone_items i WHERE i.milestone_id = m.id AND i.is_required = 1) AS req,
                        (SELECT COUNT(*) FROM cs_milestone_items i WHERE i.milestone_id = m.id AND i.is_required = 1 AND i.status IN ('accepted','completed')) AS req_done
                   FROM cs_milestones m JOIN cs_projects p ON p.id = m.project_id WHERE 1 = 1 $f $w ORDER BY p.code, m.sort_order, m.id LIMIT 500", $params);
$out = [];
$tot = 0.0;
foreach ($rows as $r) {
    $late = $r['scheduled_date'] && $r['scheduled_date'] < today() && !in_array($r['status'], ['accepted', 'completed'], true);
    $tot += (float)$r['contract_value'];
    $out[] = ['<a class="cs-main-link" href="' . h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) . '">' . h($r['code'] . ' ' . $r['name']) . '</a><span class="cs-sub">' . h($r['pcode'] . ' ' . $r['pname']) . '</span>',
        '<span class="' . ($late ? 'cs-bad' : '') . '">' . h(fdate($r['scheduled_date'])) . '</span>', money($r['contract_value']), CsUi::meter((float)$r['completion_pct']),
        (int)$r['req_done'] . ' of ' . (int)$r['req'], CsUi::state($r['status']), (int)$r['billing_eligible'] ? '<span class="cs-good">Eligible</span>' : '<span class="text-muted">Not yet</span>'];
}
echo cs_table_card(count($rows) . ' milestones', ['Milestone' => '', 'Scheduled' => '', 'Value' => 'num', 'Completion' => '', 'Deliverables accepted' => '', 'Status' => '', 'Billing' => ''], $out, 'No milestones yet.', '',
    $out ? '<tfoot><tr><td colspan="2">Total</td><td class="num">' . money($tot) . '</td><td colspan="4"></td></tr></tfoot>' : '');
layout_end();
