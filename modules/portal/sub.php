<?php
declare(strict_types=1);

/**
 * The subcontractor portal: the subcontractor's agreements with the progress verified on site, their billings (they
 * submit new ones here, with their invoice attached), retention held, and what was paid.
 */
$pageKey = 'portal.sub';
$u = DB::row('SELECT * FROM users WHERE id = :id', ['id' => Auth::id()]);
$vid = (int)($u['portal_vendor_id'] ?? 0);
if (!$vid && (Access::isAdmin() || Access::can($pageKey, 'view'))) {
    $vid = (int)(gint('vendor') ?? 0);
}
$v = $vid ? DB::row('SELECT * FROM vendors WHERE id = :id', ['id' => $vid]) : null;
$scs = $vid ? DB::all("SELECT s.*, p.code, p.name AS pname FROM cs_subcontracts s JOIN cs_projects p ON p.id = s.project_id WHERE s.vendor_id = :v AND s.status IN ('active','completed','closed') ORDER BY s.status, s.sc_no", ['v' => $vid]) : [];
$scIds = array_map('intval', array_column($scs, 'id'));

handle_post(function () use ($pageKey, $vid, $scIds) {
    if (!$vid) {
        fail('This account is not linked to a subcontractor.');
    }
    $sc = (int)p('subcontract_id');
    if (!in_array($sc, $scIds, true)) {
        fail('Choose one of your subcontracts.');
    }
    $id = CsSub::saveSb($_POST, cs_lines(), null, 'portal');
    $b = CsSub::getSb($id);
    if (!empty($_FILES['files']['name'][0])) {
        CsFiles::attach('files', 'sub_billing', $id, (int)$b['project_id'], 'Invoice and accomplishment from the subcontractor');
    }
    $p = CsProject::get((int)$b['project_id']);
    $to = array_filter([(int)$p['qs_user_id'], (int)$p['pm_user_id']]);
    CsNotify::send($to, 'sub_billing', 'Billing from ' . $b['vendor_name'] . ' (' . $b['sb_no'] . ')', 'Invoice ' . $b['invoice_no'] . ', net ' . money($b['net_amount']) . '. Check it against the accomplishment and submit it for approval.',
        url('subk.billings', ['a' => 'view', 'id' => $id]), (int)$p['id']);
    flash('success', 'Billing ' . $b['sb_no'] . ' received. It is checked against the work verified on site before approval and payment.');
    cs_go($pageKey);
});

layout_start('Subcontractor Portal', ['sub' => $v ? h($v['name']) : 'Choose a subcontractor to preview the portal']);
if (!$vid) {
    if (Access::isAdmin() || Access::can($pageKey, 'view')) {
        echo '<form class="cs-toolbar" method="get"><input type="hidden" name="p" value="' . h($pageKey) . '"><div class="cs-grow" style="max-width:360px"><label class="form-label">Subcontractor</label>'
            . CsUi::select('vendor', DB::pairs('SELECT DISTINCT v.id, v.name FROM vendors v JOIN cs_subcontracts s ON s.vendor_id = v.id ORDER BY v.name'), '', 'Choose', 'data-combo onchange="this.form.submit()"') . '</div></form>';
    }
    echo '<div class="card">' . CsUi::empty('This account is not linked to a subcontractor.', '', 'person-badge') . '</div>';
    layout_end();
    return;
}
$tot = ['value' => 0.0, 'billed' => 0.0, 'held' => 0.0];
foreach ($scs as $s) {
    $pos = CsSub::position((int)$s['id']);
    $tot['value'] += CsSub::value($s);
    $tot['billed'] += $pos['billed'];
    $tot['held'] += $pos['retained'] - $pos['released'];
}
$bills = DB::all("SELECT * FROM transactions WHERE txn_type = 'bill' AND vendor_id = :v AND is_voided = 0 ORDER BY txn_date DESC LIMIT 50", ['v' => $vid]);
$open = array_sum(array_map(fn($b) => Balances::open($b), $bills));
echo '<div class="cs-kpis">' . CsUi::kpi('Contracted', CsUi::amt($tot['value']), count($scs) . ' subcontract(s)', 'key') . CsUi::kpi('Billed', CsUi::amt($tot['billed']), '') . CsUi::kpi('Retention held', CsUi::amt($tot['held']), 'Released after acceptance')
    . CsUi::kpi('Approved, not yet paid', CsUi::amt($open), '') . '</div>';
echo '<div class="cs-split"><div class="cs-stack">';
foreach ($scs as $s) {
    $billed = CsSub::billedQty((int)$s['id']);
    $rows = [];
    foreach (DB::all('SELECT i.*, a.progress_pct FROM cs_subcontract_items i LEFT JOIN cs_activities a ON a.id = i.activity_id WHERE i.subcontract_id = :s ORDER BY i.line_no', ['s' => $s['id']]) as $it) {
        $bq = $billed[(int)$it['id']]['qty'] ?? 0;
        $rows[] = [h($it['description']), qty($it['qty']) . ' ' . h((string)$it['unit']), money($it['unit_rate']), qty($bq), $it['activity_id'] ? CsUi::pct((float)$it['progress_pct']) : ''];
    }
    echo cs_table_card($s['sc_no'] . ' ' . $s['work_package'] . ' (' . $s['code'] . ')', ['Item' => '', 'Contract' => 'num', 'Rate' => 'num', 'Billed' => 'num', 'Verified on site' => 'num'], $rows);
}
$rows = [];
foreach (DB::all('SELECT b.*, s.sc_no FROM cs_sub_billings b JOIN cs_subcontracts s ON s.id = b.subcontract_id WHERE b.vendor_id = :v ORDER BY b.id DESC LIMIT 50', ['v' => $vid]) as $b) {
    $t = $b['bill_txn_id'] ? Txn::load((int)$b['bill_txn_id']) : null;
    $paid = $t ? (float)$t['total'] - Balances::open($t) : 0;
    $rows[] = [h($b['sb_no']) . '<span class="cs-sub">Invoice ' . h($b['invoice_no']) . '</span>', h($b['sc_no']), h(fdate($b['billing_date'])), money($b['gross_amount']), money($b['retention_amount']), money($b['net_amount']),
        $t ? ($paid >= (float)$t['total'] - 0.5 ? '<span class="cs-good">Paid</span>' : money($paid) . ' paid') : '', CsUi::state($b['status'])];
}
echo cs_table_card('Your billings', ['Billing' => '', 'Subcontract' => '', 'Date' => '', 'Gross' => 'num', 'Retention' => 'num', 'Net' => 'num', 'Payment' => '', 'Status' => ''], $rows, 'No billing yet.');
echo '</div><div class="cs-stack">';
$active = array_values(array_filter($scs, fn($s) => $s['status'] === 'active'));
if ($active) {
    $scSel = (int)(gint('sc') ?? $active[0]['id']);
    $scSel = in_array($scSel, array_map('intval', array_column($active, 'id')), true) ? $scSel : (int)$active[0]['id'];
    $billed = CsSub::billedQty($scSel);
    echo '<form method="get" class="mb-2"><input type="hidden" name="p" value="' . h($pageKey) . '">' . (!$u['portal_vendor_id'] ? '<input type="hidden" name="vendor" value="' . $vid . '">' : '')
        . CsUi::select('sc', array_column(array_map(fn($s) => ['id' => $s['id'], 'l' => $s['sc_no'] . ' ' . $s['work_package']], $active), 'l', 'id'), $scSel, null, 'data-no-combo onchange="this.form.submit()"') . '</form>';
    echo '<form method="post" enctype="multipart/form-data" class="card"><div class="card-header">Submit a progress billing</div><div class="card-body"><div class="row g-2">' . csrf_field()
        . '<input type="hidden" name="subcontract_id" value="' . $scSel . '"><input type="hidden" name="kind" value="progress">'
        . CsUi::field('Your invoice no.', CsUi::input('invoice_no', '', ['required' => true, 'max' => 60]), 'col-6') . CsUi::field('Date', CsUi::input('billing_date', today(), ['type' => 'date']), 'col-6')
        . CsUi::field('Period from', CsUi::input('period_from', date('Y-m-01'), ['type' => 'date']), 'col-6') . CsUi::field('Period to', CsUi::input('period_to', today(), ['type' => 'date']), 'col-6') . '</div>';
    $i = 0;
    foreach (DB::all('SELECT * FROM cs_subcontract_items WHERE subcontract_id = :s ORDER BY line_no', ['s' => $scSel]) as $it) {
        $left = (float)$it['qty'] - ($billed[(int)$it['id']]['qty'] ?? 0);
        echo '<div class="cs-check"><div>' . h($it['description']) . '<div class="cs-note">' . qty($left) . ' ' . h((string)$it['unit']) . ' left to bill</div><input type="hidden" name="lines[' . $i . '][sc_item_id]" value="' . (int)$it['id'] . '"></div>'
            . '<div><input class="form-control form-control-sm text-end" name="lines[' . $i . '][this_qty]" placeholder="Qty this billing"></div></div>';
        $i++;
    }
    echo '<label class="form-label mt-2">Invoice and accomplishment report</label><input class="form-control form-control-sm" type="file" name="files[]" multiple accept="image/*,.pdf">'
        . '</div><div class="card-footer"><button class="btn btn-primary btn-sm">Submit billing</button></div></form>';
}
$rows = [];
foreach ($bills as $b) {
    $o = Balances::open($b);
    $rows[] = [h($b['txn_no']) . '<span class="cs-sub">Your ref. ' . h((string)$b['ref_no']) . '</span>', h(fdate($b['due_date'])), money($b['total']), $o > 0.004 ? money($o) : '<span class="cs-good">Paid</span>'];
}
echo cs_table_card('Payables to you', ['Bill' => '', 'Due' => '', 'Amount' => 'num', 'Open' => 'num'], $rows, 'Nothing yet.');
echo '</div></div>';
layout_end();
