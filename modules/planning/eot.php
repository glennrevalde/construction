<?php
declare(strict_types=1);

/** Extensions of time: the request with its cause and days, the decision, and the revised completion date it gives. */
$pageKey = 'plan.eot';
$a = (string)g('a', 'list');
$id = gint('id');
$e = $id ? DB::row('SELECT * FROM cs_eot_requests WHERE id = :id', ['id' => $id]) : null;
if ($e) {
    CsProject::requireAccess((int)$e['project_id']);
}
$causes = ['weather' => 'Unfavourable weather', 'client_delay' => 'Client delay (late approvals, access, payments)', 'variation' => 'Variation / change order',
    'force_majeure' => 'Force majeure', 'permits' => 'Permits and government approvals', 'supply' => 'Supply shortage', 'other' => 'Other'];

handle_post(function () use ($pageKey, $id, $e, $causes) {
    $act = (string)p('act');
    if ($e && cs_handle_attach('eot', (int)$id, (int)$e['project_id'])) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if ($e && $act === 'submit') {
        Access::require($pageKey, 'edit');
        if ($e['status'] !== 'draft') {
            fail('Only a draft request is submitted.');
        }
        DB::update('cs_eot_requests', ['status' => 'submitted', 'submitted_date' => $e['submitted_date'] ?: today()], 'id = :id', ['id' => $id]);
        Audit::log('submit', 'plan', 'eot', (int)$id, $e['ref_no'], ['page_key' => $pageKey, 'reason' => $e['days_requested'] . ' day(s) requested']);
        flash('success', 'Request submitted. Record the decision once the client or consultant answers.');
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if ($e && $act === 'decide') {
        Access::require($pageKey, 'approve');
        CsPlanning::eotDecision((int)$id, (string)p('status'), (int)p('days_granted'), (string)p('decision_date'));
        flash('success', 'Decision recorded.' . ((int)p('days_granted') > 0 && p('status') !== 'rejected' ? ' The project\'s revised completion date moved accordingly.' : ''));
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if ($act === 'save') {
        Access::require($pageKey, $id ? 'edit' : 'create');
        if ($e && $e['status'] !== 'draft') {
            fail('A submitted request is not changed.');
        }
        $pid = $e ? (int)$e['project_id'] : (int)p('project_id');
        CsProject::requireAccess($pid);
        $row = ['reason' => trim((string)p('reason')), 'cause' => isset($causes[p('cause')]) ? p('cause') : 'other', 'days_requested' => max(0, (int)p('days_requested')),
            'submitted_date' => pdate('submitted_date'), 'change_order_id' => pint('change_order_id')];
        if ($row['reason'] === '' || $row['days_requested'] < 1) {
            fail('Give the reason and the number of days requested.');
        }
        if ($e) {
            DB::update('cs_eot_requests', $row, 'id = :id', ['id' => $id]);
            Audit::log('edit', 'plan', 'eot', (int)$id, $e['ref_no'], ['page_key' => $pageKey]);
        } else {
            $row += ['project_id' => $pid, 'ref_no' => Seq::next('cs_eot'), 'status' => 'draft', 'created_by' => Auth::id()];
            $id = DB::insert('cs_eot_requests', $row);
            Audit::log('create', 'plan', 'eot', $id, $row['ref_no'], ['page_key' => $pageKey, 'reason' => $row['days_requested'] . ' day(s)']);
        }
        flash('success', 'Request saved.');
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
});

if ($a === 'new' || ($a === 'edit' && $e)) {
    Access::require($pageKey, $e ? 'edit' : 'create');
    $pid = $e ? (int)$e['project_id'] : (int)(gint('project') ?? CsProject::current() ?? 0);
    $c = $e ?: ['project_id' => $pid, 'cause' => 'weather', 'submitted_date' => today()];
    $cos = $pid ? DB::pairs("SELECT id, CONCAT(co_no, ' ', title) FROM cs_change_orders WHERE project_id = :p ORDER BY id DESC", ['p' => $pid]) : [];
    layout_start($e ? 'Edit ' . $e['ref_no'] : 'New extension of time', ['module' => 'plan']);
    echo '<form method="post" class="card"><div class="card-body"><div class="row g-3">' . csrf_field() . '<input type="hidden" name="act" value="save">'
        . CsUi::field('Project', $e ? '<div class="pt-1">' . CsUi::projectLink($pid) . '</div>' : CsUi::projectSelect('project_id', $pid ?: '', ['required' => true]), 'col-md-6')
        . CsUi::field('Cause', CsUi::select('cause', $causes, fv('cause', $c), null, 'data-no-combo'), 'col-md-3')
        . CsUi::field('Days requested', CsUi::input('days_requested', fv('days_requested', $c), ['type' => 'number', 'step' => '1', 'required' => true]), 'col-6 col-md-3')
        . CsUi::field('Date sent', CsUi::input('submitted_date', fv('submitted_date', $c), ['type' => 'date']), 'col-6 col-md-3')
        . CsUi::field('Related change order', CsUi::select('change_order_id', $cos, fv('change_order_id', $c), 'None'), 'col-md-9')
        . CsUi::field('Grounds for the extension', CsUi::textarea('reason', fv('reason', $c), 5, 'required'), 'col-12', 'The events, their dates and how they delayed the critical path.')
        . '</div></div><div class="card-footer">' . save_buttons('Save request', url($pageKey, $e ? ['a' => 'view', 'id' => $id] : []), false) . '</div></form>';
    layout_end();
    return;
}

if ($a === 'view' && $e) {
    $p = CsProject::get((int)$e['project_id']);
    layout_start($e['ref_no'] . ' Extension of time', ['module' => 'plan', 'sub' => CsUi::projectLink((int)$e['project_id']) . ' ' . CsUi::state($e['status']),
        'actions' => $e['status'] === 'draft' ? cs_link($pageKey, ['a' => 'edit', 'id' => $id], 'Edit', 'btn-outline-secondary', 'pencil', 'edit') : '']);
    echo '<div class="cs-kpis">' . CsUi::kpi('Days requested', (string)(int)$e['days_requested'], h($causes[$e['cause']] ?? (string)$e['cause']), 'key')
        . CsUi::kpi('Days granted', in_array($e['status'], ['granted', 'partially_granted', 'rejected'], true) ? (string)(int)$e['days_granted'] : 'Pending', $e['decision_date'] ? 'Decided ' . fdate($e['decision_date']) : '')
        . CsUi::kpi('Contract completion', h(fdate($p['target_end_date'])), 'Revised: ' . h(fdate($p['revised_end_date'] ?: $p['target_end_date']))) . '</div>';
    echo '<div class="cs-split"><div class="cs-stack"><div class="card"><div class="card-header">Grounds</div><div class="card-body">' . nl2br(h($e['reason'])) . '</div>'
        . '<div class="card-body border-top">' . CsUi::facts(['Sent' => h(fdate($e['submitted_date'])), 'Change order' => $e['change_order_id'] ? '<a href="' . h(url('proj.change_orders', ['a' => 'view', 'id' => $e['change_order_id']])) . '">' . h((string)DB::value('SELECT co_no FROM cs_change_orders WHERE id = :i', ['i' => $e['change_order_id']])) . '</a>' : '']) . '</div></div>'
        . CsFiles::panel('eot', $id, Access::can($pageKey, 'edit'), 'Supporting documents') . '</div><div class="cs-stack">';
    if ($e['status'] === 'draft' && Access::can($pageKey, 'edit')) {
        echo '<div class="card"><div class="card-body">' . CsUi::confirmButton('eotSub', 'Mark as submitted', 'Submit ' . $e['ref_no'], 'Record that the request went to the client or consultant.', '<input type="hidden" name="act" value="submit">', 'btn-primary', 'send') . '</div></div>';
    }
    if ($e['status'] === 'submitted' && Access::can($pageKey, 'approve')) {
        echo '<form method="post" class="card"><div class="card-header">Decision</div><div class="card-body"><div class="row g-2">' . csrf_field() . '<input type="hidden" name="act" value="decide">'
            . CsUi::field('Decision', CsUi::select('status', ['granted' => 'Granted', 'partially_granted' => 'Partially granted', 'rejected' => 'Rejected'], 'granted', null, 'data-no-combo'), 'col-12')
            . CsUi::field('Days granted', CsUi::input('days_granted', (int)$e['days_requested'], ['type' => 'number', 'step' => '1']), 'col-6')
            . CsUi::field('Decision date', CsUi::input('decision_date', today(), ['type' => 'date']), 'col-6')
            . '</div></div><div class="card-footer"><button class="btn btn-primary btn-sm" data-confirm="Record this decision? Granted days move the project\'s revised completion date.">Record decision</button></div></form>';
    }
    echo cs_history('eot', $id) . '</div></div>';
    layout_end();
    return;
}

$pid = CsProject::current();
layout_start('Extensions of Time', ['actions' => cs_link($pageKey, ['a' => 'new', 'project' => $pid], 'New request', 'btn-primary', 'plus-lg', 'create')]);
echo cs_filter_bar($pageKey, $pid);
[$f, $params] = CsProject::filter('e.project_id');
$w = '';
if ($pid) {
    $w = ' AND e.project_id = :pid';
    $params['pid'] = $pid;
}
$rows = DB::all("SELECT e.*, p.code, p.name AS pname FROM cs_eot_requests e JOIN cs_projects p ON p.id = e.project_id WHERE 1 = 1 $f $w ORDER BY e.id DESC LIMIT 300", $params);
$out = [];
foreach ($rows as $r) {
    $out[] = ['<a class="cs-main-link" href="' . h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) . '">' . h($r['ref_no']) . '</a>', h($r['code'] . ' ' . $r['pname']), h($causes[$r['cause']] ?? (string)$r['cause']),
        (int)$r['days_requested'], in_array($r['status'], ['granted', 'partially_granted', 'rejected'], true) ? (int)$r['days_granted'] : '', h(fdate($r['submitted_date'])), CsUi::state($r['status'])];
}
echo cs_table_card(count($rows) . ' requests', ['Ref.' => '', 'Project' => '', 'Cause' => '', 'Requested' => 'num', 'Granted' => 'num', 'Sent' => '', 'Status' => ''], $out, 'No extension of time requested.');
layout_end();
