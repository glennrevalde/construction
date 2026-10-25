<?php
declare(strict_types=1);

/**
 * Revenue recognition by percentage of completion (cost-to-cost or physical progress) or at completion, with the full
 * provision for an expected loss; the over/under-billing (WIP) schedule; and the settlement of the contract accounts
 * at completion.
 */
$pageKey = 'pbill.revenue';
$a = (string)g('a', 'list');
$id = gint('id');
$rr = $id ? DB::row('SELECT r.*, p.code, p.name AS pname FROM cs_revenue_recognitions r JOIN cs_projects p ON p.id = r.project_id WHERE r.id = :id', ['id' => $id]) : null;
if ($rr) {
    CsProject::requireAccess((int)$rr['project_id']);
}
$method = Settings::get('cs_revenue_method', 'poc_cost');

handle_post(function () use ($pageKey, $id, $rr) {
    if ($rr && CsApproval::handlePost('revenue', (int)$id, function () use ($rr) {
        if (!in_array($rr['status'], ['draft', 'rejected'], true)) {
            fail('Only a draft recognition is submitted.');
        }
        CsApproval::submit('revenue', (int)$rr['id'], $rr['rr_no'] . ' ' . $rr['code'] . ' to ' . fdate($rr['period_end']), (int)$rr['project_id'], abs((float)$rr['this_period']));
    })) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    $act = (string)p('act');
    if ($act === 'prepare') {
        Access::require($pageKey, 'create');
        $nid = CsBilling::prepareRecognition((int)p('project_id'), (string)p('period_end'));
        flash('success', 'Revenue recognition prepared. Submit it for approval; it posts once approved.');
        cs_go($pageKey, ['a' => 'view', 'id' => $nid]);
    }
    if ($rr && $act === 'reverse') {
        Access::require($pageKey, 'delete');
        CsBilling::reverseRecognition((int)$id, (string)p('reason'));
        flash('success', 'Reversed: its journal entry was voided with your reason.');
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if ($rr && $act === 'remove') {
        Access::require($pageKey, 'edit');
        if (!in_array($rr['status'], ['draft', 'rejected'], true) || CsApproval::pendingFor('revenue', (int)$id)) {
            fail('Only a draft that is not waiting for approval is removed.');
        }
        DB::update('cs_revenue_recognitions', ['status' => 'reversed'], 'id = :id', ['id' => $id]);
        Audit::log('delete', 'pbill', 'revenue', (int)$id, $rr['rr_no'], ['page_key' => $pageKey, 'reason' => 'Draft removed']);
        flash('success', 'Draft removed.');
        cs_go($pageKey);
    }
    if ($act === 'settle') {
        Access::require($pageKey, 'approve');
        $txn = CsBilling::settleContractAccounts((int)p('project_id'), pdate('date') ?: today());
        flash('success', $txn ? 'Progress billings closed against the contract assets.' : 'Nothing to settle.');
        cs_go($pageKey);
    }
});

if ($a === 'view' && $rr) {
    layout_start($rr['rr_no'] . ' Revenue recognition', ['module' => 'pbill', 'sub' => CsUi::projectLink((int)$rr['project_id']) . ', to ' . h(fdate($rr['period_end'])) . ', ' . h(CsUi::label($rr['method'])) . ' ' . CsUi::state($rr['status'])]);
    echo '<div class="cs-kpis">' . CsUi::kpi('Percent complete', CsUi::pct((float)$rr['pct_complete'], 2), $rr['method'] === 'poc_cost' ? money($rr['cost_to_date']) . ' of ' . money($rr['est_total_cost']) . ' estimated cost' : '', 'key')
        . CsUi::kpi('Revenue earned to date', CsUi::amt($rr['earned_to_date']), 'Of a contract value of ' . money($rr['contract_value']))
        . CsUi::kpi('Recognized before', CsUi::amt($rr['prev_recognized']), '') . CsUi::kpi('This period', CsUi::amt($rr['this_period']), (float)$rr['this_period'] < 0 ? 'A reduction' : '', (float)$rr['this_period'] < 0 ? 'warn' : 'ok')
        . ((float)$rr['loss_provision'] ? CsUi::kpi('Loss provision change', CsUi::amt($rr['loss_provision']), 'Expected loss on the contract', 'bad') : '') . '</div>';
    echo '<div class="cs-split"><div class="card"><div class="card-header">The entry</div><div class="card-body"><table class="table table-sm mb-0"><thead><tr><th>Account</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead><tbody>';
    $e = (float)$rr['this_period'];
    $lp = (float)$rr['loss_provision'];
    foreach (array_filter([[Accts::KEYS['cs_contract_asset'][0], $e], [Accts::KEYS['cs_contract_revenue'][0], -$e], [Accts::KEYS['cs_loss_provision'][0], $lp], [Accts::KEYS['cs_loss_liability'][0], -$lp]], fn($x) => abs($x[1]) >= 0.005) as [$acc, $amt]) {
        echo '<tr><td>' . h($acc) . '</td><td class="num">' . ($amt > 0 ? money($amt) : '') . '</td><td class="num">' . ($amt < 0 ? money(-$amt) : '') . '</td></tr>';
    }
    echo '</tbody></table>' . ($rr['txn_id'] ? '<div class="mt-2">Posted as ' . CsUi::txnLink((int)$rr['txn_id']) . '</div>' : '') . '</div></div><div class="cs-stack">';
    echo CsApproval::panel('revenue', $id, Access::can($pageKey, 'edit') && in_array($rr['status'], ['draft', 'rejected'], true), 'Submit for approval');
    if ($rr['status'] === 'posted' && Access::can($pageKey, 'delete')) {
        echo '<div class="card"><div class="card-body">' . CsUi::confirmButton('rrRev', 'Reverse', 'Reverse ' . $rr['rr_no'], 'The journal entry is voided (kept with your reason). Only the latest recognition of a project is reversed.', '<input type="hidden" name="act" value="reverse">', 'btn-outline-danger', 'arrow-counterclockwise', true) . '</div></div>';
    }
    if (in_array($rr['status'], ['draft', 'rejected'], true) && Access::can($pageKey, 'edit') && !CsApproval::pendingFor('revenue', $id)) {
        echo '<form method="post" class="card"><div class="card-body">' . csrf_field() . '<button class="btn btn-outline-secondary btn-sm" name="act" value="remove" data-confirm="Remove this draft?">Remove the draft</button></div></form>';
    }
    echo cs_history('revenue', $id) . '</div></div>';
    layout_end();
    return;
}

$pid = CsProject::current();
layout_start('Revenue Recognition', ['sub' => 'Method: ' . h(CsUi::label($method)) . '. Change it in Construction settings.']);
if ($method === 'billing') {
    echo '<div class="alert alert-info">Revenue follows the certified billings: each invoice is credited to Contract Revenue directly, so there is nothing to recognize here. The schedule below still shows each project\'s position.</div>';
}
echo cs_filter_bar($pageKey, $pid);
if ($method !== 'billing' && Access::can($pageKey, 'create')) {
    $preview = '';
    $end = gdate('end') ?: date('Y-m-t', strtotime('last day of previous month'));
    if ($pid) {
        $r = CsBilling::computeRevenue($pid, $end);
        $preview = '<div class="cs-kpis mt-3">' . CsUi::kpi('Percent complete', CsUi::pct($r['pct'], 2), $method === 'poc_cost' ? money($r['cost_to_date']) . ' of ' . money($r['est_total_cost']) : '', 'key')
            . CsUi::kpi('Earned to date', CsUi::amt($r['earned']), 'Contract ' . money($r['contract_value'])) . CsUi::kpi('Recognized so far', CsUi::amt($r['prev']), '')
            . CsUi::kpi('To recognize now', CsUi::amt($r['this']), '', $r['this'] < 0 ? 'warn' : 'ok') . ($r['loss_needed'] > 0 || $r['loss_provided'] > 0 ? CsUi::kpi('Loss provision', CsUi::amt($r['loss_needed']), 'Change ' . money($r['loss_delta']), 'bad') : '') . '</div>';
    }
    echo '<form method="post" class="card mb-3"><div class="card-header">Prepare a recognition</div><div class="card-body"><div class="row g-2 align-items-end">' . csrf_field() . '<input type="hidden" name="act" value="prepare">'
        . CsUi::field('Project', CsUi::projectSelect('project_id', $pid ?: '', ['required' => true]), 'col-md-5') . CsUi::field('Period end', CsUi::input('period_end', $end, ['type' => 'date']), 'col-md-3')
        . '<div class="col-md-4"><button class="btn btn-primary btn-sm">Prepare</button></div></div>' . $preview . '</div></form>';
}
// the WIP schedule
$ids = CsProject::accessibleIds();
[$f, $params] = CsProject::filter('p.id');
$projects = DB::all("SELECT p.* FROM cs_projects p WHERE p.status <> 'planning' $f" . ($pid ? ' AND p.id = ' . (int)$pid : '') . ' ORDER BY p.code', $params);
$rows = [];
$tot = ['cv' => 0.0, 'earned' => 0.0, 'billed' => 0.0, 'under' => 0.0, 'over' => 0.0];
foreach ($projects as $p) {
    $w = CsBilling::wip((int)$p['id']);
    $rev = (float)(CsCost::contractRevenue([(int)$p['id']])[(int)$p['id']] ?? 0);
    $billedAll = (float)DB::value("SELECT COALESCE(SUM(this_gross + materials_stored),0) FROM cs_billings WHERE project_id = :p AND status = 'invoiced' AND billing_type IN ('" . implode("','", CsBilling::WORK_TYPES) . "')", ['p' => $p['id']]);
    $cv = CsProject::contractValue((int)$p['id'])['current'];
    $tot['cv'] += $cv;
    $tot['earned'] += $rev;
    $tot['billed'] += $billedAll;
    $diff = $rev - $billedAll;
    $tot[$diff >= 0 ? 'under' : 'over'] += abs($diff);
    $last = DB::value("SELECT MAX(period_end) FROM cs_revenue_recognitions WHERE project_id = :p AND status = 'posted'", ['p' => $p['id']]);
    $settle = in_array($p['status'], ['completed', 'under_warranty', 'closed'], true) && min($w['earned'], $w['billed']) > 0.5 && Access::can($pageKey, 'approve')
        ? '<form method="post">' . csrf_field() . '<input type="hidden" name="act" value="settle"><input type="hidden" name="project_id" value="' . (int)$p['id'] . '"><button class="btn btn-link btn-sm p-0" data-confirm="Close the progress billings against the contract assets?">Settle accounts</button></form>' : '';
    $rows[] = [CsUi::projectLink((int)$p['id']) . '<span class="cs-sub">' . h(CsUi::label($p['status'])) . '</span>', money($cv), money($rev), money($billedAll),
        $diff >= 0 ? money($diff) : '', $diff < 0 ? '<span class="cs-warn">' . money(-$diff) . '</span>' : '', h(fdate($last)), $settle];
}
echo cs_table_card('Over- and under-billings (work in progress)', ['Project' => '', 'Contract' => 'num', 'Revenue earned' => 'num', 'Billed' => 'num', 'Under-billed (asset)' => 'num', 'Over-billed (liability)' => 'num', 'Recognized to' => '', '' => ''], $rows, 'No projects.', '',
    $rows ? '<tfoot><tr><td>Total</td><td class="num">' . money($tot['cv']) . '</td><td class="num">' . money($tot['earned']) . '</td><td class="num">' . money($tot['billed']) . '</td><td class="num">' . money($tot['under']) . '</td><td class="num">' . money($tot['over']) . '</td><td colspan="2"></td></tr></tfoot>' : '');
$hist = DB::all("SELECT r.*, p.code FROM cs_revenue_recognitions r JOIN cs_projects p ON p.id = r.project_id WHERE 1 = 1 " . CsProject::filter('r.project_id', 'rh')[0] . ($pid ? ' AND r.project_id = ' . (int)$pid : '') . ' ORDER BY r.period_end DESC, r.id DESC LIMIT 200', CsProject::filter('r.project_id', 'rh')[1]);
$rows = [];
foreach ($hist as $r) {
    $rows[] = ['<a class="cs-main-link" href="' . h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) . '">' . h($r['rr_no']) . '</a>', h($r['code']), h(fdate($r['period_end'])), CsUi::pct((float)$r['pct_complete'], 2),
        money($r['earned_to_date']), money($r['this_period']), (float)$r['loss_provision'] ? '<span class="cs-bad">' . money($r['loss_provision']) . '</span>' : '', CsUi::state($r['status'])];
}
echo '<div class="mb-3"></div>' . cs_table_card('Recognitions', ['No.' => '', 'Project' => '', 'Period end' => '', 'Complete' => 'num', 'Earned to date' => 'num', 'This period' => 'num', 'Loss provision' => 'num', 'Status' => ''], $rows, 'No recognition yet.');
layout_end();
