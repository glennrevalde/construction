<?php
declare(strict_types=1);

/**
 * Close-out and warranty: the readiness check, the close-out checklist, the punch list and defects, the warranty
 * period, and the financial closure (approved like any other document; closing with open items needs a documented
 * exception).
 */
$pageKey = 'proj.closeout';
$pid = CsProject::current();
$p = $pid ? CsProject::requireAccess($pid) : null;

handle_post(function () use ($pageKey, $pid, $p) {
    if (!$p) {
        fail('Choose a project first.');
    }
    if (cs_handle_attach('closeout', (int)$pid, (int)$pid)) {
        cs_go($pageKey, ['project' => $pid]);
    }
    if (CsApproval::handlePost('closeout', (int)$pid, function () use ($pid, $p) {
        Access::require('proj.closeout', 'edit');
        if (!in_array($p['status'], ['completed', 'under_warranty', 'substantially_completed'], true)) {
            fail('Mark the project completed before asking for financial closure.');
        }
        $reason = trim((string)p('override'));
        $issues = CsCloseout::issues((int)$pid);
        if ($issues && $reason === '') {
            fail('There are open items. Clear them, or write the documented exception for closing with them.');
        }
        DB::update('cs_projects', ['closure_override' => $reason !== '' ? mb_substr($reason, 0, 255) : null], 'id = :id', ['id' => $pid]);
        CsProject::flush();
        $s = CsSummary::project((int)$pid);
        CsApproval::submit('closeout', (int)$pid, $p['code'] . ' ' . $p['name'], (int)$pid, (float)$s['contract_current'], $reason !== '' ? 'Exception: ' . $reason : 'No open items');
    })) {
        cs_go($pageKey, ['project' => $pid]);
    }
    $act = (string)p('act');
    Access::require($pageKey, 'edit');
    if ($act === 'checklist') {
        foreach ((array)($_POST['items'] ?? []) as $iid => $it) {
            $old = DB::row('SELECT * FROM cs_closeout_items WHERE id = :i AND project_id = :p', ['i' => (int)$iid, 'p' => $pid]);
            if (!$old) {
                continue;
            }
            $st = in_array($it['status'] ?? '', ['pending', 'in_progress', 'completed', 'waived'], true) ? $it['status'] : $old['status'];
            $rem = mb_substr(trim((string)($it['remarks'] ?? '')), 0, 255) ?: null;
            if ($st === 'waived' && !$rem) {
                fail('Give the reason for waiving "' . $old['item'] . '".');
            }
            if ($st !== $old['status'] || $rem !== $old['remarks']) {
                DB::update('cs_closeout_items', ['status' => $st, 'remarks' => $rem, 'completed_date' => in_array($st, ['completed', 'waived'], true) ? ($old['completed_date'] ?: today()) : null, 'updated_by' => Auth::id()],
                    'id = :id', ['id' => $iid]);
                Audit::log('checklist', 'proj', 'closeout', (int)$pid, $old['item'], ['page_key' => $pageKey, 'reason' => CsUi::label($st) . ($rem ? ': ' . $rem : '')]);
            }
        }
        if (trim((string)p('new_item')) !== '') {
            DB::insert('cs_closeout_items', ['project_id' => $pid, 'item' => mb_substr(trim((string)p('new_item')), 0, 200), 'status' => 'pending',
                'line_no' => 1 + (int)DB::value('SELECT COALESCE(MAX(line_no),0) FROM cs_closeout_items WHERE project_id = :p', ['p' => $pid])]);
        }
        flash('success', 'Checklist saved.');
    } elseif ($act === 'punch') {
        CsCloseout::savePunch((int)$pid, $_POST);
        flash('success', 'Item added.');
    } elseif ($act === 'punch_status') {
        if (!DB::value('SELECT id FROM cs_punch_items WHERE id = :i AND project_id = :p', ['i' => (int)p('item_id'), 'p' => $pid])) {
            fail('Item not found.');
        }
        CsCloseout::punchStatus((int)p('item_id'), (string)p('status'), (string)p('remarks'));
        flash('success', 'Item updated.');
    } elseif ($act === 'complete') {
        CsProject::setStatus((int)$pid, 'completed', (string)p('reason'));
        flash('success', 'Project marked completed. Start the warranty period when the client accepts the works.');
    } elseif ($act === 'warranty') {
        $end = CsCloseout::startWarranty((int)$pid, pdate('from'));
        flash('success', 'Warranty period runs until ' . fdate($end) . '.');
    }
    cs_go($pageKey, ['project' => $pid]);
});

layout_start('Close-out & Warranty', ['sub' => $p ? CsUi::projectLink((int)$pid) . ' ' . CsUi::state($p['status']) : 'Choose a project']);
echo cs_filter_bar($pageKey, $pid, '', false);
if (!$p) {
    echo '<div class="card">' . CsUi::empty('Choose a project to work on its close-out.', '', 'check2-square') . '</div>';
    layout_end();
    return;
}
$issues = CsCloseout::issues((int)$pid);
$items = DB::all('SELECT c.*, u.full_name AS by_name FROM cs_closeout_items c LEFT JOIN users u ON u.id = c.updated_by WHERE c.project_id = :p ORDER BY c.line_no, c.id', ['p' => $pid]);
$punch = DB::all('SELECT * FROM cs_punch_items WHERE project_id = :p ORDER BY (status IN (\'verified\',\'closed\')), kind, id DESC', ['p' => $pid]);
$done = count(array_filter($items, fn($i) => in_array($i['status'], ['completed', 'waived'], true)));
$openPunch = count(array_filter($punch, fn($i) => $i['kind'] === 'punch' && !in_array($i['status'], ['verified', 'closed'], true)));
$openDef = count(array_filter($punch, fn($i) => $i['kind'] === 'defect' && !in_array($i['status'], ['verified', 'closed'], true)));
$canEdit = Access::can($pageKey, 'edit');
echo '<div class="cs-kpis">' . CsUi::kpi('Close-out checklist', $done . ' of ' . count($items), count($items) ? CsUi::pct($done / max(1, count($items)) * 100, 0) . ' done' : 'No checklist', $done === count($items) && $items ? 'ok' : 'key')
    . CsUi::kpi('Punch-list items open', (string)$openPunch, '', $openPunch ? 'warn' : 'ok')
    . CsUi::kpi('Defects open', (string)$openDef, 'Reported during the warranty period', $openDef ? 'warn' : 'ok')
    . CsUi::kpi('Warranty ends', $p['warranty_end_date'] ? h(fdate($p['warranty_end_date'])) : 'Not started', $p['warranty_end_date'] && $p['warranty_end_date'] < today() ? 'Ended' : '', $p['warranty_end_date'] && $p['warranty_end_date'] >= today() && $p['warranty_end_date'] <= date('Y-m-d', strtotime('+60 days')) ? 'warn' : '')
    . CsUi::kpi('Open items for closure', (string)count($issues), $issues ? 'See the readiness list' : 'Ready for financial closure', $issues ? 'warn' : 'ok') . '</div>';

echo '<div class="cs-split"><div class="cs-stack">';
// checklist
echo '<form method="post" class="card">' . csrf_field() . '<input type="hidden" name="act" value="checklist"><div class="card-header">Close-out checklist</div><div class="card-body">';
if (!$items) {
    echo '<p class="cs-note">No checklist items.</p>';
}
foreach ($items as $it) {
    echo '<div class="cs-check"><div><div class="' . (in_array($it['status'], ['completed', 'waived'], true) ? 'text-muted' : 'fw-semibold') . '">' . h($it['item']) . '</div>'
        . ($it['completed_date'] ? '<div class="cs-note">' . h(CsUi::label($it['status'])) . ' ' . h(fdate($it['completed_date'])) . ($it['by_name'] ? ' by ' . h($it['by_name']) : '') . '</div>' : '')
        . ($canEdit ? '<input class="form-control form-control-sm mt-1" name="items[' . (int)$it['id'] . '][remarks]" value="' . h((string)$it['remarks']) . '" placeholder="Remarks">' : ($it['remarks'] ? '<div class="small">' . h($it['remarks']) . '</div>' : '')) . '</div>'
        . '<div>' . ($canEdit ? CsUi::select('items[' . (int)$it['id'] . '][status]', ['pending' => 'Pending', 'in_progress' => 'In progress', 'completed' => 'Completed', 'waived' => 'Waived (give reason)'], $it['status'], null, 'data-no-combo') : CsUi::state($it['status'])) . '</div></div>';
}
echo '</div>' . ($canEdit ? '<div class="card-footer d-flex flex-wrap gap-2"><input class="form-control form-control-sm" style="max-width:320px" name="new_item" placeholder="Add an item"><button class="btn btn-primary btn-sm ms-auto">Save checklist</button></div>' : '') . '</form>';

// punch list and defects
$rows = [];
foreach ($punch as $it) {
    $late = $it['due_date'] && $it['due_date'] < today() && !in_array($it['status'], ['verified', 'closed'], true);
    $upd = '';
    if ($canEdit && $it['status'] !== 'closed') {
        $upd = '<details class="small"><summary>Update</summary><form method="post" class="mt-1">' . csrf_field() . '<input type="hidden" name="act" value="punch_status"><input type="hidden" name="item_id" value="' . (int)$it['id'] . '">'
            . CsUi::select('status', CsCloseout::PUNCH_STATUSES, $it['status'], null, 'data-no-combo') . '<input class="form-control form-control-sm mt-1" name="remarks" placeholder="Remarks"><button class="btn btn-outline-primary btn-sm mt-1">Save</button></form></details>';
    }
    $rows[] = ['_class' => in_array($it['status'], ['verified', 'closed'], true) ? 'text-muted' : '', h($it['ref_no']) . '<span class="cs-sub">' . h(CsUi::label($it['kind'])) . '</span>',
        h($it['description']) . '<span class="cs-sub">' . h(CsUi::sub([$it['location'], $it['responsible_party'], (int)$it['warranty_covered'] ? 'Under warranty' : ''])) . '</span>' . ($it['remarks'] ? '<span class="cs-sub">' . h($it['remarks']) . '</span>' : ''),
        '<span class="' . ($it['severity'] === 'critical' || $it['severity'] === 'high' ? 'cs-bad' : '') . '">' . h(CsUi::label($it['severity'])) . '</span>', '<span class="' . ($late ? 'cs-bad' : '') . '">' . h(fdate($it['due_date'])) . '</span>',
        (float)$it['cost'] ? money($it['cost']) : '', CsUi::state($it['status']) . $upd];
}
echo cs_table_card('Punch list and defects', ['Ref.' => '', 'Item' => '', 'Severity' => '', 'Due' => '', 'Cost' => 'num', 'Status' => ''], $rows, 'No punch-list items or defects.');
if ($canEdit) {
    echo '<details class="card"><summary class="card-header">Add a punch-list item or defect</summary><form method="post" class="card-body"><div class="row g-2">' . csrf_field() . '<input type="hidden" name="act" value="punch">'
        . CsUi::field('Kind', CsUi::select('kind', ['punch' => 'Punch-list item', 'defect' => 'Defect (warranty period)'], 'punch', null, 'data-no-combo'), 'col-md-4')
        . CsUi::field('Location', CsUi::input('location', '', ['max' => 150, 'placeholder' => '3F Unit 305, toilet']), 'col-md-4') . CsUi::field('Severity', CsUi::select('severity', ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'], 'medium', null, 'data-no-combo'), 'col-md-4')
        . CsUi::field('Description', CsUi::input('description', '', ['required' => true, 'max' => 255]), 'col-12')
        . CsUi::field('Responsible', CsUi::input('responsible_party', '', ['max' => 150, 'placeholder' => 'Crew or subcontractor']), 'col-md-4') . CsUi::field('Due', CsUi::input('due_date', date('Y-m-d', strtotime('+7 days')), ['type' => 'date']), 'col-md-4')
        . CsUi::field('Estimated cost', CsUi::input('cost', '', ['num' => true]), 'col-md-4')
        . '<div class="col-12">' . check_field('warranty_covered', 'Covered by the warranty', false, '') . '</div>'
        . '<div class="col-12"><button class="btn btn-primary btn-sm">Add</button></div></div></form></details>';
}
echo '</div><div class="cs-stack">';
// readiness
echo '<div class="card"><div class="card-header">Readiness for closure</div><div class="card-body">';
if (!$issues) {
    echo '<p class="mb-0 cs-good"><i class="bi bi-check2-circle"></i> Nothing is left open.</p>';
} else {
    echo '<ul class="list-unstyled mb-0">';
    foreach ($issues as [$label, $fig, $page, $params]) {
        echo '<li class="d-flex justify-content-between gap-2 py-1 border-bottom"><span>' . (Access::page($page) ? '<a href="' . h(url($page, $params)) . '">' . h($label) . '</a>' : h($label)) . '</span><span class="cs-warn text-nowrap">' . h($fig) . '</span></li>';
    }
    echo '</ul>';
}
echo '</div></div>';
// completion and warranty
if ($canEdit && in_array($p['status'], ['active', 'substantially_completed', 'on_hold'], true)) {
    echo '<div class="card"><div class="card-body">' . CsUi::confirmButton('pComplete', 'Mark project completed', 'Mark ' . $p['code'] . ' completed', 'The works are finished. Billing, collection and close-out continue.', '<input type="hidden" name="act" value="complete">', 'btn-outline-primary', 'flag') . '</div></div>';
}
if ($canEdit && in_array($p['status'], ['completed', 'substantially_completed'], true)) {
    echo '<form method="post" class="card"><div class="card-header">Warranty and defect liability</div><div class="card-body">' . csrf_field() . '<input type="hidden" name="act" value="warranty">'
        . CsUi::field('Starts on (client acceptance)', CsUi::input('from', $p['actual_end_date'] ?: today(), ['type' => 'date']), '') . '<p class="cs-note mt-2 mb-0">The length comes from the contract\'s warranty and defect-liability months.</p></div>'
        . '<div class="card-footer"><button class="btn btn-outline-primary btn-sm">Start the warranty period</button></div></form>';
}
// financial closure
if ($p['status'] !== 'closed') {
    $req = CsApproval::requestFor('closeout', (int)$pid);
    $canSubmit = $canEdit && in_array($p['status'], ['completed', 'under_warranty', 'substantially_completed'], true) && (!$req || in_array($req['status'], ['returned', 'cancelled', 'rejected'], true));
    if ($canSubmit) {
        echo '<form method="post" class="card"><div class="card-header">Financial closure</div><div class="card-body">' . csrf_field() . '<input type="hidden" name="appr_act" value="submit">'
            . ($issues ? '<label class="form-label">Documented exception for closing with open items</label><textarea class="form-control form-control-sm" name="override" rows="2" required></textarea><div class="form-text">Kept on the project and in the audit trail. Only an authorized user can approve it.</div>'
                : '<p class="cs-note mb-0">Final cost, revenue and profit are frozen once the closure is approved.</p>')
            . '</div><div class="card-footer"><button class="btn btn-primary btn-sm"><i class="bi bi-send"></i> Ask for financial closure</button></div></form>';
        if ($req) {
            echo CsApproval::panel('closeout', (int)$pid);
        }
    } else {
        echo CsApproval::panel('closeout', (int)$pid);
    }
} else {
    echo '<div class="card"><div class="card-header">Financial closure</div><div class="card-body">Closed ' . h(fdatetime($p['closed_at'])) . ($p['closure_override'] ? '<p class="cs-note mt-2 mb-0">Exception: ' . h($p['closure_override']) . '</p>' : '') . '</div></div>';
}
echo CsFiles::panel('closeout', (int)$pid, $canEdit, 'Turnover documents (as-builts, manuals, certificates)');
echo '</div></div>';
layout_end();
