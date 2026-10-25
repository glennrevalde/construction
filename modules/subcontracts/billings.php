<?php
declare(strict_types=1);

/**
 * Subcontractor progress billings: the quantities accomplished per item (never past the contract, never ahead of the
 * verified progress), retention, recoupment of the advance, back charges and penalties; approval makes the supplier bill.
 */
$pageKey = 'subk.billings';
$a = (string)g('a', 'list');
$id = gint('id');
$b = $id ? CsSub::getSb($id) : null;
if ($b) {
    CsProject::requireAccess((int)$b['project_id']);
}

handle_post(function () use ($pageKey, $id, $b) {
    if ($b && cs_handle_attach('sub_billing', (int)$id, (int)$b['project_id'])) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if ($b && CsApproval::handlePost('sub_billing', (int)$id, function () use ($b) {
        if (!in_array($b['status'], ['draft', 'rejected'], true)) {
            fail('Only a draft billing is submitted.');
        }
        CsApproval::submit('sub_billing', (int)$b['id'], $b['sb_no'] . ' ' . $b['vendor_name'] . ' inv. ' . $b['invoice_no'], (int)$b['project_id'], (float)$b['net_amount']);
    })) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    $act = (string)p('act');
    if ($b && $act === 'cancel') {
        Access::require($pageKey, 'edit');
        if (!in_array($b['status'], ['draft', 'rejected'], true) || trim((string)p('reason')) === '') {
            fail('Only a draft billing is cancelled, with the reason.');
        }
        DB::update('cs_sub_billings', ['status' => 'cancelled', 'notes' => mb_substr(trim((string)$b['notes'] . ' Cancelled: ' . p('reason')), 0, 255)], 'id = :id', ['id' => $id]);
        Audit::log('cancel', 'subk', 'sub_billing', (int)$id, $b['sb_no'], ['page_key' => $pageKey, 'reason' => (string)p('reason')]);
        flash('success', 'Billing cancelled.');
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if ($act === 'save') {
        Access::require($pageKey, $id ? 'edit' : 'create');
        $sc = CsSub::get((int)($b['subcontract_id'] ?? p('subcontract_id')));
        if ($sc) {
            CsProject::requireAccess((int)$sc['project_id']);
        }
        $nid = CsSub::saveSb($_POST, cs_lines(), $id);
        flash('success', 'Billing saved. Check the figures, then submit it for approval.');
        cs_go($pageKey, ['a' => 'view', 'id' => $nid]);
    }
});

if ($a === 'new' || ($a === 'edit' && $b)) {
    Access::require($pageKey, $b ? 'edit' : 'create');
    $scId = $b ? (int)$b['subcontract_id'] : (int)gint('sc');
    $sc = $scId ? CsSub::get($scId) : null;
    if ($sc) {
        CsProject::requireAccess((int)$sc['project_id']);
    }
    $kind = $b['kind'] ?? (in_array(g('kind'), ['progress', 'final', 'retention_release'], true) ? (string)g('kind') : 'progress');
    layout_start($b ? 'Edit ' . $b['sb_no'] : 'New subcontractor billing', ['module' => 'subk', 'sub' => $sc ? h($sc['sc_no'] . ' ' . $sc['work_package'] . ', ' . $sc['vendor_name']) . ', ' . CsUi::projectLink((int)$sc['project_id']) : '']);
    if (!$sc) {
        [$f, $params] = CsProject::filter('s.project_id');
        $opts = DB::pairs("SELECT s.id, CONCAT(s.sc_no, ' ', v.name, ' (', p.code, ')') FROM cs_subcontracts s JOIN vendors v ON v.id = s.vendor_id JOIN cs_projects p ON p.id = s.project_id WHERE s.status IN ('active','completed') $f ORDER BY s.sc_no", $params);
        echo '<form class="card" method="get"><div class="card-body"><input type="hidden" name="p" value="' . h($pageKey) . '"><input type="hidden" name="a" value="new">'
            . CsUi::field('Subcontract', CsUi::select('sc', $opts, '', 'Choose', 'data-combo required'), '') . '</div><div class="card-footer"><button class="btn btn-primary btn-sm">Continue</button></div></form>';
        layout_end();
        return;
    }
    $c = $b ?: ['billing_date' => today(), 'period_to' => today(), 'period_from' => date('Y-m-d', strtotime('-1 month +1 day'))];
    $billed = CsSub::billedQty($scId, $id);
    $mine = $b ? DB::pairs('SELECT sc_item_id, this_qty FROM cs_sub_billing_lines WHERE sb_id = :b', ['b' => $id]) : [];
    $pos = CsSub::position($scId);
    echo '<form method="post">' . csrf_field() . '<input type="hidden" name="act" value="save"><input type="hidden" name="subcontract_id" value="' . $scId . '"><input type="hidden" name="kind" value="' . h($kind) . '">'
        . '<div class="card mb-3"><div class="card-body"><div class="row g-3">'
        . CsUi::field('Kind', '<div class="pt-1">' . h(['progress' => 'Progress billing', 'final' => 'Final billing', 'retention_release' => 'Release of retention'][$kind]) . '</div>', 'col-md-3')
        . CsUi::field('Subcontractor\'s invoice no.', CsUi::input('invoice_no', fv('invoice_no', $c), ['required' => true, 'max' => 60]), 'col-md-3')
        . CsUi::field('Date', CsUi::input('billing_date', fv('billing_date', $c), ['type' => 'date']), 'col-6 col-md-2')
        . ($kind !== 'retention_release' ? CsUi::field('Period from', CsUi::input('period_from', fv('period_from', $c), ['type' => 'date']), 'col-6 col-md-2') . CsUi::field('Period to', CsUi::input('period_to', fv('period_to', $c), ['type' => 'date']), 'col-6 col-md-2') : '')
        . '</div></div></div>';
    if ($kind === 'retention_release') {
        $held = $pos['retained'] - $pos['released'];
        echo '<div class="card mb-3"><div class="card-body">' . CsUi::field('Amount to release (held: ' . money($held) . ')', CsUi::input('release_amount', number_format($held, 2, '.', ''), ['num' => true]), 'col-md-4') . '</div></div>';
    } else {
        echo '<div class="card mb-3"><div class="card-header">Accomplishment this billing</div><div class="table-responsive"><table class="table table-sm cs-lines mb-0" data-cs-lines data-min-rows="0" id="sbGrid"><thead><tr><th>Item</th><th class="num">Contract</th><th class="num">Billed before</th><th class="num">Verified progress</th><th class="num">This billing</th><th class="num">Rate</th><th class="num">Amount</th></tr></thead><tbody>';
        $i = 0;
        foreach (DB::all('SELECT i.*, a.progress_pct, a.wbs_code FROM cs_subcontract_items i LEFT JOIN cs_activities a ON a.id = i.activity_id WHERE i.subcontract_id = :s ORDER BY i.line_no', ['s' => $scId]) as $it) {
            $prev = $billed[(int)$it['id']]['qty'] ?? 0.0;
            $thisQ = is_post() ? '' : (isset($mine[$it['id']]) ? qty($mine[$it['id']]) : '');
            echo '<tr><td>' . h($it['description']) . '<input type="hidden" name="lines[' . $i . '][sc_item_id]" value="' . (int)$it['id'] . '"></td><td class="num">' . qty($it['qty']) . ' ' . h((string)$it['unit']) . '</td>'
                . '<td class="num">' . qty($prev) . '</td><td class="num">' . ($it['activity_id'] ? h($it['wbs_code']) . ' ' . CsUi::pct((float)$it['progress_pct']) . '<span class="cs-sub">up to ' . qty((float)$it['qty'] * (float)$it['progress_pct'] / 100) . '</span>' : '<span class="text-muted">Not linked</span>') . '</td>'
                . '<td class="w-sm"><input class="form-control form-control-sm text-end" name="lines[' . $i . '][this_qty]" value="' . h($thisQ) . '" data-q placeholder="0"></td>'
                . '<td class="num"><span data-r-fixed>' . money($it['unit_rate']) . '</span><input type="hidden" value="' . h((string)(float)$it['unit_rate']) . '" data-r></td><td class="num" data-a>0.00</td></tr>';
            $i++;
        }
        echo '</tbody><tfoot><tr><td colspan="6" class="text-end">Gross this billing</td><td class="num" data-cs-sum="amount">0.00</td></tr></tfoot></table></div></div>'
            . '<div class="card mb-3"><div class="card-body"><div class="row g-3">' . CsUi::field('Back charges', CsUi::input('back_charges', fv('back_charges', $c, '0'), ['num' => true]), 'col-6 col-md-3', 'Materials or services we supplied for them')
            . CsUi::field('Penalties', CsUi::input('penalties', fv('penalties', $c, '0'), ['num' => true]), 'col-6 col-md-3') . '<div class="col-md-6 cs-note pt-4">Retention (' . CsUi::pct((float)$sc['retention_pct'], 2) . '), the advance recoupment and VAT are worked out when you save.</div>'
            . '</div></div></div>';
    }
    echo '<div class="card mb-3"><div class="card-body">' . CsUi::field('Notes', CsUi::input('notes', fv('notes', $c), ['max' => 255]), 'col-12') . '</div></div>'
        . '<div class="sticky-actions">' . save_buttons('Save billing', url($pageKey, $b ? ['a' => 'view', 'id' => $id] : []), false) . '</div></form>';
    layout_end();
    return;
}

if ($a === 'view' && $b) {
    $lines = DB::all('SELECT l.*, i.description, i.unit, i.qty AS contract_qty, i.unit_rate FROM cs_sub_billing_lines l JOIN cs_subcontract_items i ON i.id = l.sc_item_id WHERE l.sb_id = :b ORDER BY i.line_no', ['b' => $id]);
    layout_start($b['sb_no'] . ' ' . $b['vendor_name'], ['module' => 'subk', 'sub' => 'Invoice ' . h($b['invoice_no']) . ', <a href="' . h(url('subk.contracts', ['a' => 'view', 'id' => $b['subcontract_id']])) . '">' . h($b['sc_no']) . '</a>, ' . CsUi::projectLink((int)$b['project_id'])
        . ($b['submitted_via'] === 'portal' ? ', sent through the portal' : '') . ' ' . CsUi::state($b['status']),
        'actions' => in_array($b['status'], ['draft', 'rejected'], true) ? cs_link($pageKey, ['a' => 'edit', 'id' => $id], 'Edit', 'btn-outline-secondary', 'pencil', 'edit') : '']);
    $rows = [];
    foreach ($lines as $l) {
        $rows[] = [h($l['description']), qty($l['contract_qty']) . ' ' . h((string)$l['unit']), qty($l['prev_qty']), qty($l['this_qty']), qty((float)$l['prev_qty'] + (float)$l['this_qty']), money($l['unit_rate']), money($l['this_amount'])];
    }
    echo '<div class="cs-split"><div class="cs-stack">' . ($rows ? cs_table_card('Accomplishment', ['Item' => '', 'Contract' => 'num', 'Before' => 'num', 'This billing' => 'num', 'To date' => 'num', 'Rate' => 'num', 'Amount' => 'num'], $rows) : '');
    echo '<div class="card"><div class="card-header">Statement</div><div class="card-body">' . CsUi::facts([
        'Kind' => h(CsUi::label($b['kind'])), 'Period' => $b['period_from'] ? h(fdate($b['period_from']) . ' to ' . fdate($b['period_to'])) : '', 'Gross' => money($b['gross_amount']),
        'Billed before / to date' => money($b['prev_cumulative']) . ' / ' . money($b['cumulative_amount']), 'VAT' => money($b['vat_amount']), 'Less retention' => '(' . money($b['retention_amount']) . ')',
        'Less advance recoupment' => '(' . money($b['advance_recoup']) . ')', 'Less back charges' => '(' . money($b['back_charges']) . ')', 'Less penalties' => '(' . money($b['penalties']) . ')',
        'Net payable' => '<strong>' . money($b['net_amount']) . '</strong>', 'Supplier bill' => $b['bill_txn_id'] ? CsUi::txnLink((int)$b['bill_txn_id']) : 'Made when approved', 'Notes' => h((string)$b['notes']),
    ]) . '</div></div>' . CsFiles::panel('sub_billing', $id, Access::can($pageKey, 'edit'), 'Invoice and accomplishment report') . '</div><div class="cs-stack">';
    echo CsApproval::panel('sub_billing', $id, Access::can($pageKey, 'edit') && in_array($b['status'], ['draft', 'rejected'], true), 'Certify and submit for approval');
    if (in_array($b['status'], ['draft', 'rejected'], true) && Access::can($pageKey, 'edit')) {
        echo '<div class="card"><div class="card-body">' . CsUi::confirmButton('sbCancel', 'Cancel billing', 'Cancel ' . $b['sb_no'], 'The billing is kept as cancelled.', '<input type="hidden" name="act" value="cancel">', 'btn-outline-secondary', 'x-lg', true) . '</div></div>';
    }
    echo cs_history('sub_billing', $id) . '</div></div>';
    layout_end();
    return;
}

$pid = CsProject::current();
layout_start('Subcontractor Billings', ['actions' => cs_link($pageKey, ['a' => 'new'], 'New billing', 'btn-primary', 'plus-lg', 'create')]);
echo cs_filter_bar($pageKey, $pid, cs_search_field('Billing no., invoice no., subcontractor') . cs_status_field(['draft' => 'Draft', 'for_approval' => 'For approval', 'posted' => 'Posted', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled']));
[$f, $params] = CsProject::filter('b.project_id');
[$w, $wp] = cs_like((string)g('q'), ['b.sb_no', 'b.invoice_no', 'v.name']);
$params += $wp;
if ($pid) {
    $w .= ' AND b.project_id = :pid';
    $params['pid'] = $pid;
}
if ((string)g('status') !== '') {
    $w .= ' AND b.status = :st';
    $params['st'] = (string)g('status');
}
$rows = DB::all("SELECT b.*, v.name AS vendor_name, s.sc_no, p.code FROM cs_sub_billings b JOIN vendors v ON v.id = b.vendor_id JOIN cs_subcontracts s ON s.id = b.subcontract_id JOIN cs_projects p ON p.id = b.project_id WHERE 1 = 1 $f $w ORDER BY b.id DESC LIMIT 400", $params);
$out = [];
foreach ($rows as $r) {
    $out[] = ['<a class="cs-main-link" href="' . h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) . '">' . h($r['sb_no']) . '</a><span class="cs-sub">Invoice ' . h($r['invoice_no']) . '</span>', h($r['vendor_name']), h($r['sc_no'] . ', ' . $r['code']),
        h(CsUi::label($r['kind'])), h(fdate($r['billing_date'])), money($r['gross_amount']), money($r['retention_amount']), money($r['net_amount']), CsUi::state($r['status']) . ($r['submitted_via'] === 'portal' ? '<span class="cs-sub">Portal</span>' : '')];
}
echo cs_table_card(count($rows) . ' billings', ['Billing' => '', 'Subcontractor' => '', 'Subcontract' => '', 'Kind' => '', 'Date' => '', 'Gross' => 'num', 'Retention' => 'num', 'Net' => 'num', 'Status' => ''], $out, 'No billings yet.');
layout_end();
