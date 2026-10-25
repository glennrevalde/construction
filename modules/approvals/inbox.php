<?php
declare(strict_types=1);

/** My approvals: what waits for me now, what I submitted that is still waiting, and what I was asked to look at. */
$pageKey = 'appr.inbox';
$me = (int)Auth::id();

handle_post(function () use ($pageKey) {
    $req = DB::row('SELECT * FROM cs_approval_requests WHERE id = :id', ['id' => (int)p('appr_req')]);
    if (!$req) {
        fail('Approval request not found.');
    }
    if ($req['project_id']) {
        CsProject::requireAccess((int)$req['project_id']);
    }
    CsApproval::decide((int)$req['id'], (string)p('appr_act'), (string)p('reason'), pint('delegate_to'));
    flash('success', ['approve' => 'Approved.', 'reject' => 'Rejected.', 'return' => 'Returned for correction.'][(string)p('appr_act')] ?? 'Done.');
    cs_go($pageKey);
});

$mine = CsApproval::inbox($me);
$type = (string)g('type');
if ($type !== '') {
    $mine = array_values(array_filter($mine, fn($r) => $r['doc_type'] === $type));
}
layout_start('My Approvals', ['sub' => count($mine) ? count($mine) . ' document(s) wait for you' : 'Nothing waits for you']);
$types = [];
foreach (CsDocs::TYPES as $k => $t) {
    $types[$k] = $t[0];
}
echo '<form class="cs-toolbar" method="get"><input type="hidden" name="p" value="' . h($pageKey) . '">' . cs_status_field($types, 'type', 'Kind of document') . '</form>';
$names = DB::pairs('SELECT id, full_name FROM users');
$now = date('Y-m-d H:i:s');
if (!$mine) {
    echo '<div class="card">' . CsUi::empty('Nothing is waiting for your approval.', '', 'check2-all') . '</div>';
} else {
    echo '<div class="card"><div class="table-responsive"><table class="table table-sm cs-table mb-0"><thead><tr><th>Document</th><th>Project</th><th class="num">Amount</th><th>Step</th><th>From</th><th>Waiting since</th><th></th></tr></thead><tbody>';
    foreach ($mine as $r) {
        $s = CsApproval::currentStep($r);
        $over = $r['due_at'] && $r['due_at'] < $now;
        $f = '<input type="hidden" name="appr_req" value="' . (int)$r['id'] . '">';
        echo '<tr><td><a class="cs-main-link" href="' . h(CsDocs::url($r['doc_type'], (int)$r['doc_id'])) . '">' . h(CsDocs::label($r['doc_type']) . ' ' . $r['doc_ref']) . '</a>' . ((int)$r['escalated'] ? '<span class="cs-sub cs-bad">Escalated</span>' : '') . '</td>'
            . '<td>' . ($r['project_id'] ? CsUi::projectLink((int)$r['project_id']) : '') . '</td><td class="num">' . money($r['amount']) . '</td>'
            . '<td>' . h($s['label']) . '<span class="cs-sub">Step ' . (int)$r['current_step'] . ' of ' . (int)$r['total_steps'] . '</span></td><td>' . h((string)($names[$r['requested_by']] ?? '')) . '</td>'
            . '<td class="' . ($over ? 'cs-bad' : '') . '">' . h(fdatetime($r['step_since'])) . ($over ? '<span class="cs-sub">Overdue</span>' : '') . '</td>'
            . '<td class="text-nowrap">' . CsUi::confirmButton('qa' . $r['id'], 'Approve', 'Approve ' . CsDocs::label($r['doc_type']) . ' ' . $r['doc_ref'], 'Open the document first if you have not reviewed it. ' . ((int)$r['current_step'] === (int)$r['total_steps'] ? 'This is the last step.' : 'It goes on to the next approver.'),
                $f . '<input type="hidden" name="appr_act" value="approve">', 'btn-success', 'check2')
            . ' ' . CsUi::confirmButton('qr' . $r['id'], 'Return', 'Return for correction', 'It goes back to the person who submitted it with your reason.', $f . '<input type="hidden" name="appr_act" value="return">', 'btn-outline-secondary', 'arrow-return-left', true) . '</td></tr>';
    }
    echo '</tbody></table></div></div>';
}
// what I submitted
$sent = DB::all("SELECT * FROM cs_approval_requests WHERE requested_by = :u AND status = 'pending' ORDER BY id DESC LIMIT 50", ['u' => $me]);
$rows = [];
foreach ($sent as $r) {
    $s = CsApproval::currentStep($r);
    $rows[] = ['<a href="' . h(CsDocs::url($r['doc_type'], (int)$r['doc_id'])) . '">' . h(CsDocs::label($r['doc_type']) . ' ' . $r['doc_ref']) . '</a>', money($r['amount']), h($s['label']) . '<span class="cs-sub">Step ' . (int)$r['current_step'] . ' of ' . (int)$r['total_steps'] . '</span>',
        h(CsApproval::stepWho($s, $r)), h(fdatetime($r['step_since']))];
}
echo '<div class="mb-3"></div>' . cs_table_card('I submitted, still waiting', ['Document' => '', 'Amount' => 'num', 'At step' => '', 'Waiting for' => '', 'Since' => ''], $rows, 'Nothing you submitted is waiting.');
// returned or rejected recently
$back = DB::all("SELECT r.*, a.comment, a.user_name FROM cs_approval_requests r JOIN cs_approval_actions a ON a.request_id = r.id AND a.action IN ('return','reject')
                  WHERE r.requested_by = :u AND r.status IN ('returned','rejected') AND r.decided_at >= :d ORDER BY r.decided_at DESC LIMIT 30", ['u' => $me, 'd' => date('Y-m-d', strtotime('-30 days'))]);
$rows = [];
foreach ($back as $r) {
    $rows[] = ['<a href="' . h(CsDocs::url($r['doc_type'], (int)$r['doc_id'])) . '">' . h(CsDocs::label($r['doc_type']) . ' ' . $r['doc_ref']) . '</a>', CsUi::state($r['status']), h((string)$r['user_name']), h((string)$r['comment']), h(fdatetime($r['decided_at']))];
}
if ($rows) {
    echo '<div class="mb-3"></div>' . cs_table_card('Returned or rejected in the last 30 days', ['Document' => '', 'Outcome' => '', 'By' => '', 'Reason' => '', 'When' => ''], $rows);
}
layout_end();
