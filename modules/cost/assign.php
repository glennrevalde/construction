<?php
declare(strict_types=1);

/**
 * Cost code assignment: costs charged to a project in accounting (bills, checks, cash vouchers, journals) without a
 * cost code get one here, so the budget-versus-actual by cost code is complete.
 */
$pageKey = 'cost.assign';
$pid = CsProject::current();

handle_post(function () use ($pageKey, $pid) {
    Access::require($pageKey, 'edit');
    $n = 0;
    DB::tx(function () use (&$n) {
        foreach ((array)($_POST['cc'] ?? []) as $jl => $cc) {
            if ((int)$cc > 0) {
                $j = DB::row('SELECT class_id FROM journal_lines WHERE id = :j', ['j' => (int)$jl]);
                $p = $j ? CsProject::byClass((int)$j['class_id']) : null;
                if (!$p) {
                    continue;
                }
                CsProject::requireAccess((int)$p['id']);
                CsAlloc::assign((int)$jl, (int)$cc);
                $n++;
            }
        }
    });
    flash('success', $n . ' cost line(s) given a cost code.');
    cs_go($pageKey, ['project' => $pid]);
});

layout_start('Cost Code Assignment', ['sub' => 'Project costs recorded in accounting without a cost code.']);
echo cs_filter_bar($pageKey, $pid);
$lines = CsAlloc::uncoded($pid ? [$pid] : CsProject::accessibleIds());
if (!$lines) {
    echo '<div class="card">' . CsUi::empty('Every project cost has a cost code.', '', 'check2-circle') . '</div>';
    layout_end();
    return;
}
$ccOpts = CsCost::codeOptions();
$canEdit = Access::can($pageKey, 'edit');
echo '<form method="post">' . csrf_field();
$rows = [];
$tot = 0.0;
foreach ($lines as $l) {
    $amt = (float)$l['debit'] - (float)$l['credit'];
    $tot += $amt;
    $p = CsProject::byClass((int)$l['class_id']);
    $rows[] = [Txn::link(['id' => $l['txn_id'], 'txn_type' => $l['txn_type'], 'txn_no' => $l['txn_no']], Txn::label($l['txn_type']) . ' ' . $l['txn_no']) . '<span class="cs-sub">' . h((string)$l['memo']) . '</span>', h(fdate($l['txn_date'])),
        $p ? h($p['code']) : '', h($l['code'] . ' ' . $l['acct_name']), money($amt), $canEdit ? CsUi::select('cc[' . (int)$l['id'] . ']', $ccOpts, '', 'Choose', 'data-combo') : ''];
}
echo cs_table_card(count($lines) . ' cost lines without a cost code (' . money($tot) . ')', ['Source' => '', 'Date' => '', 'Project' => '', 'Account' => '', 'Amount' => 'num', 'Cost code' => ''], $rows, '',
    $canEdit ? '<button class="btn btn-primary btn-sm ms-auto">Save cost codes</button>' : '');
echo '</form>';
layout_end();
