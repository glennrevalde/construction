<?php
declare(strict_types=1);

/** The approval history of every construction document: who did what, when, at which step, with which comment. */
$pageKey = 'appr.history';
$from = gdate('from') ?: date('Y-m-d', strtotime('-30 days'));
$to = gdate('to') ?: today();
$type = (string)g('type');
$act = (string)g('action');
$pid = CsProject::current();
$types = [];
foreach (CsDocs::TYPES as $k => $t) {
    $types[$k] = $t[0];
}
$actions = ['submit' => 'Submitted', 'approve' => 'Approved', 'reject' => 'Rejected', 'return' => 'Returned', 'delegate' => 'Delegated', 'escalate' => 'Escalated', 'cancel' => 'Withdrawn'];
$w = ' AND a.created_at >= :f AND a.created_at < :t';
$params = ['f' => $from . ' 00:00:00', 't' => date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'];
$acc = CsProject::accessibleIds();
if ($acc !== null) {
    [$in, $pp] = DB::in('ahacc', $acc ?: [0]);
    $w .= " AND (r.project_id IS NULL OR r.project_id IN $in)";
    $params += $pp;
}
if ($pid) {
    $w .= ' AND r.project_id = :pid';
    $params['pid'] = $pid;
}
if (isset($types[$type])) {
    $w .= ' AND r.doc_type = :ty';
    $params['ty'] = $type;
}
if (isset($actions[$act])) {
    $w .= ' AND a.action = :ac';
    $params['ac'] = $act;
}
$rows = DB::all("SELECT a.*, r.doc_type, r.doc_id, r.doc_ref, r.project_id, r.status AS req_status FROM cs_approval_actions a JOIN cs_approval_requests r ON r.id = a.request_id WHERE 1 = 1 $w ORDER BY a.id DESC LIMIT 1000", $params);
$out = [];
foreach ($rows as $r) {
    $out[] = [h(fdatetime($r['created_at'])), '<a href="' . h(CsDocs::url($r['doc_type'], (int)$r['doc_id'])) . '">' . h(CsDocs::label($r['doc_type']) . ' ' . $r['doc_ref']) . '</a>', $r['project_id'] ? CsUi::projectLink((int)$r['project_id']) : '',
        CsUi::state(['approve' => 'approved', 'reject' => 'rejected', 'return' => 'returned', 'submit' => 'submitted', 'cancel' => 'cancelled'][$r['action']] ?? 'pending', $actions[$r['action']] ?? $r['action']),
        h((string)$r['step_label']), h((string)$r['user_name']), $r['amount'] !== null ? money($r['amount']) : '', h((string)$r['comment'])];
}
if (g('export') === 'csv') {
    csv_download('approval_history.csv', ['When', 'Document', 'Project', 'Action', 'Step', 'By', 'Amount', 'Comment'], array_map(fn($r) => [$r['created_at'], CsDocs::label($r['doc_type']) . ' ' . $r['doc_ref'], (string)$r['project_id'],
        $actions[$r['action']] ?? $r['action'], $r['step_label'], $r['user_name'], $r['amount'], $r['comment']], $rows));
}
layout_start('Approval History');
echo cs_filter_bar($pageKey, $pid, '<div><label class="form-label">From</label><input class="form-control form-control-sm" type="date" name="from" value="' . h($from) . '"></div><div><label class="form-label">To</label><input class="form-control form-control-sm" type="date" name="to" value="' . h($to) . '"></div>'
    . cs_status_field($types, 'type', 'Document') . cs_status_field($actions, 'action', 'Action'));
echo cs_table_card(count($rows) . ' actions', ['When' => '', 'Document' => '', 'Project' => '', 'Action' => '', 'Step' => '', 'By' => '', 'Amount' => 'num', 'Comment' => ''], $out, 'No approval activity in this period.',
    '<a class="btn btn-outline-secondary btn-sm ms-auto" href="' . h(current_url(['export' => 'csv'])) . '"><i class="bi bi-download"></i> CSV</a>');
layout_end();
