<?php
declare(strict_types=1);

/**
 * Deliveries for the projects: purchase orders waiting to be received (received in accounting's Receive Items, so stock
 * and payables are recorded once), and the quality check of what came in. Rejected quantities go back to the supplier
 * on a supplier debit memo.
 */
$pageKey = 'proc.receiving';
$pid = CsProject::current();

handle_post(function () use ($pageKey, $pid) {
    Access::require($pageKey, 'edit');
    $line = (int)p('line_id');
    $tid = (int)DB::value('SELECT txn_id FROM transaction_lines WHERE id = :l', ['l' => $line]);
    $t = $tid ? Txn::load($tid) : null;
    $cls = $t ? (int)(DB::value('SELECT COALESCE(class_id, :c) FROM transaction_lines WHERE id = :l', ['l' => $line, 'c' => $t['class_id']]) ?? 0) : 0;
    $proj = $cls ? CsProject::byClass($cls) : null;
    if ($proj) {
        CsProject::requireAccess((int)$proj['id']);
    }
    CsMaterials::inspect($line, to_num(p('accepted')), to_num(p('rejected')), (string)p('reason'));
    flash('success', to_num(p('rejected')) > 0 ? 'Inspection recorded. The rejected quantity went back to the supplier on a supplier debit memo.' : 'Inspection recorded.');
    cs_go($pageKey, ['project' => $pid]);
});

layout_start('Receiving & Inspection', ['actions' => page_link('vend.receive_items', [], 'Receive items', 'btn-primary', 'box-arrow-in-down', 'create')]);
echo cs_filter_bar($pageKey, $pid);
$cls = CsCost::classes($pid ? [$pid] : CsProject::accessibleIds());
if (!$cls) {
    echo '<div class="card">' . CsUi::empty('No project to show.') . '</div>';
    layout_end();
    return;
}
[$in, $pp] = DB::in('rcv', array_keys($cls));
// purchase orders still open
$pos = DB::all("SELECT t.*, v.name AS vendor_name,
                       (SELECT SUM(CASE WHEN l.is_closed = 1 THEN 0 ELSE GREATEST(0, l.qty - l.qty_fulfilled) * l.rate END) FROM transaction_lines l WHERE l.txn_id = t.id) AS open_amt
                  FROM transactions t JOIN vendors v ON v.id = t.vendor_id
                 WHERE t.txn_type = 'purchase_order' AND t.is_voided = 0 AND t.status IN ('open','partial')
                   AND (t.class_id IN $in OR EXISTS (SELECT 1 FROM transaction_lines l WHERE l.txn_id = t.id AND l.class_id IN $in))
                 ORDER BY t.due_date, t.id LIMIT 200", $pp);
$rows = [];
foreach ($pos as $t) {
    $proj = CsProject::byClass((int)$t['class_id']);
    $late = $t['due_date'] && $t['due_date'] < today();
    $rows[] = [Txn::link($t), h($t['vendor_name']), $proj ? h($proj['code']) : '', h(fdate($t['txn_date'])), '<span class="' . ($late ? 'cs-bad' : '') . '">' . h(fdate($t['due_date'])) . '</span>',
        money($t['open_amt']), CsUi::state($t['status']), page_link('vend.receive_items', ['po' => $t['id']], 'Receive', 'btn-outline-primary', 'box-arrow-in-down', 'create')];
}
echo cs_table_card('Purchase orders to receive', ['Order' => '', 'Supplier' => '', 'Project' => '', 'Date' => '', 'Expected' => '', 'Not received' => 'num', 'Status' => '', '' => ''], $rows, 'Nothing is waiting to be received.');
echo '<div class="mb-3"></div>';
// deliveries received in the last 60 days, with their inspection
$lines = DB::all("SELECT l.*, t.txn_no, t.txn_type, t.txn_date, t.id AS tid, v.name AS vendor_name, COALESCE(l.class_id, t.class_id) AS cls,
                         (SELECT COALESCE(SUM(c.qty_accepted),0) FROM cs_receiving_checks c WHERE c.txn_line_id = l.id) AS acc,
                         (SELECT COALESCE(SUM(c.qty_rejected),0) FROM cs_receiving_checks c WHERE c.txn_line_id = l.id) AS rej
                    FROM transaction_lines l JOIN transactions t ON t.id = l.txn_id JOIN vendors v ON v.id = t.vendor_id
                   WHERE t.txn_type IN ('item_receipt','bill') AND t.is_voided = 0 AND l.item_id IS NOT NULL AND l.qty > 0
                     AND COALESCE(l.class_id, t.class_id) IN $in AND t.txn_date >= :since
                   ORDER BY t.txn_date DESC, t.id DESC, l.line_no LIMIT 300", $pp + ['since' => date('Y-m-d', strtotime('-60 days'))]);
$canEdit = Access::can($pageKey, 'edit');
$rows = [];
foreach ($lines as $l) {
    $left = (float)$l['qty'] - (float)$l['acc'] - (float)$l['rej'];
    $proj = CsProject::byClass((int)$l['cls']);
    $form = '';
    if ($canEdit && $left > 0.0001) {
        $form = '<details class="small"><summary>Inspect</summary><form method="post" class="mt-1">' . csrf_field() . '<input type="hidden" name="line_id" value="' . (int)$l['id'] . '">'
            . '<div class="input-group input-group-sm mb-1"><span class="input-group-text">Accepted</span><input class="form-control text-end" name="accepted" value="' . h(qty($left)) . '"></div>'
            . '<div class="input-group input-group-sm mb-1"><span class="input-group-text">Rejected</span><input class="form-control text-end" name="rejected" value="0"></div>'
            . '<input class="form-control form-control-sm mb-1" name="reason" placeholder="Reason for rejecting"><button class="btn btn-outline-primary btn-sm">Record</button></form></details>';
    }
    $rows[] = [Txn::link(['id' => $l['tid'], 'txn_type' => $l['txn_type'], 'txn_no' => $l['txn_no']]) . '<span class="cs-sub">' . h(fdate($l['txn_date'])) . '</span>', h($l['vendor_name']), $proj ? h($proj['code']) : '',
        h($l['description']), qty($l['qty']) . ' ' . h((string)$l['unit']), qty($l['acc']), (float)$l['rej'] > 0 ? '<span class="cs-bad">' . qty($l['rej']) . '</span>' : '0',
        $left > 0.0001 ? '<span class="cs-warn">' . qty($left) . ' to check</span>' . $form : '<span class="cs-good">Checked</span>'];
}
echo cs_table_card('Deliveries of the last 60 days', ['Delivery' => '', 'Supplier' => '', 'Project' => '', 'Material' => '', 'Received' => 'num', 'Accepted' => 'num', 'Rejected' => 'num', 'Inspection' => ''], $rows, 'No deliveries in the last 60 days.');
// recent rejections
$rej = DB::all("SELECT c.*, i.name AS item_name, t.txn_no, d.txn_no AS dm_no FROM cs_receiving_checks c JOIN items i ON i.id = c.item_id JOIN transactions t ON t.id = c.txn_id
                  LEFT JOIN transactions d ON d.id = c.debit_memo_txn_id WHERE c.qty_rejected > 0 " . ($pid ? 'AND c.project_id = :p' : '') . ' ORDER BY c.id DESC LIMIT 50', $pid ? ['p' => $pid] : []);
if ($rej) {
    $rows = [];
    foreach ($rej as $r) {
        $rows[] = [h(fdate($r['check_date'])), h($r['txn_no']), h($r['item_name']), qty($r['qty_rejected']), h((string)$r['reject_reason']), $r['debit_memo_txn_id'] ? CsUi::txnLink((int)$r['debit_memo_txn_id']) : ''];
    }
    echo '<div class="mb-3"></div>' . cs_table_card('Rejected on delivery', ['Date' => '', 'Delivery' => '', 'Material' => '', 'Qty' => 'num', 'Reason' => '', 'Debit memo' => ''], $rows);
}
layout_end();
