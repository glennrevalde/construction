<?php
declare(strict_types=1);

/**
 * Canvass and award: the suppliers' quotations side by side, the lowest price per line, the award (with the reason when
 * it is not the lowest), its approval, and the purchase order it becomes.
 */
$pageKey = 'proc.canvass';
$a = (string)g('a', 'list');
$id = gint('id');
$c = $id ? CsMaterials::getCanvass($id) : null;
if ($c) {
    CsProject::requireAccess((int)$c['project_id']);
}

handle_post(function () use ($pageKey, $id, $c) {
    if (!$c) {
        return;
    }
    if (cs_handle_attach('canvass', (int)$id, (int)$c['project_id'])) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if (CsApproval::handlePost('purchase', (int)$id, function () {
        fail('Choose the supplier to award first.');
    })) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    $act = (string)p('act');
    if ($act === 'quote') {
        Access::require($pageKey, 'edit');
        CsMaterials::saveQuote((int)$id, $_POST, (array)($_POST['price'] ?? []), pint('quote_id'));
        flash('success', 'Quotation saved.');
    } elseif ($act === 'del_quote') {
        Access::require($pageKey, 'edit');
        if ($c['status'] !== 'open') {
            fail('The canvass is no longer open.');
        }
        DB::run('DELETE FROM cs_canvass_quotes WHERE id = :q AND canvass_id = :c', ['q' => (int)p('quote_id'), 'c' => $id]);
        Audit::log('delete', 'proc', 'canvass', (int)$id, $c['canvass_no'], ['page_key' => $pageKey, 'reason' => 'Quotation removed']);
        flash('success', 'Quotation removed.');
    } elseif ($act === 'award') {
        Access::require($pageKey, 'edit');
        CsMaterials::award((int)$id, (int)p('quote_id'), (string)p('reason'));
        flash('success', 'Award sent for approval. The purchase order is made once it is approved.');
    } elseif ($act === 'cancel') {
        Access::require($pageKey, 'edit');
        if ($c['status'] !== 'open' || trim((string)p('reason')) === '') {
            fail('Only an open canvass is cancelled, with the reason.');
        }
        DB::update('cs_canvass', ['status' => 'cancelled', 'award_reason' => mb_substr('Cancelled: ' . p('reason'), 0, 255)], 'id = :id', ['id' => $id]);
        Audit::log('cancel', 'proc', 'canvass', (int)$id, $c['canvass_no'], ['page_key' => $pageKey, 'reason' => (string)p('reason')]);
        flash('success', 'Canvass cancelled.');
    }
    cs_go($pageKey, ['a' => 'view', 'id' => $id]);
});

if ($a === 'view' && $c) {
    $cmp = CsMaterials::comparison((int)$id);
    $canEdit = Access::can($pageKey, 'edit') && $c['status'] === 'open';
    $editQ = gint('quote') ? DB::row('SELECT * FROM cs_canvass_quotes WHERE id = :q AND canvass_id = :c', ['q' => gint('quote'), 'c' => $id]) : null;
    layout_start($c['canvass_no'] . ' Canvass', ['module' => 'proc', 'sub' => CsUi::projectLink((int)$c['project_id']) . ', for <a href="' . h(url('proc.requests', ['a' => 'view', 'id' => $c['mr_id']])) . '">' . h($c['mr_no']) . '</a> ' . CsUi::state($c['status'])]);
    echo '<div class="cs-kpis">' . CsUi::kpi('Quotations', (string)count($cmp['quotes']), count($cmp['quotes']) < 3 ? 'Three are usual' : '', count($cmp['quotes']) < 2 ? 'warn' : '')
        . CsUi::kpi('Lowest complete quotation', $cmp['lowest'] ? CsUi::amt($cmp['totals'][$cmp['lowest']]['total']) : 'None yet', $cmp['lowest'] ? h(array_values(array_filter($cmp['quotes'], fn($q) => (int)$q['id'] === $cmp['lowest']))[0]['vendor_name']) : '', 'key')
        . ($c['awarded_name'] ? CsUi::kpi('Awarded', ($aw = array_values(array_filter($cmp['quotes'], fn($q) => (int)$q['is_awarded']))) ? CsUi::amt($cmp['totals'][(int)$aw[0]['id']]['total'] ?? 0) : '',
            h(CsUi::sub([$c['awarded_name'], (string)$c['award_reason']])), 'ok') : '')
        . ($c['po_txn_id'] && ($po = Txn::load((int)$c['po_txn_id'])) ? CsUi::kpi('Purchase order', Txn::link($po, (string)$po['txn_no']),
            $po['status'] === 'closed' ? 'Received in full' : 'Receive it in Receive Items') : '') . '</div>';
    // comparison
    echo '<div class="card mb-3"><div class="card-header">Comparison (net of VAT)</div><div class="table-responsive"><table class="table table-sm cs-table mb-0"><thead><tr><th>Material</th><th class="num">Qty</th>';
    foreach ($cmp['quotes'] as $q) {
        echo '<th class="num">' . h($q['vendor_name']) . ((int)$q['is_awarded'] ? ' <span class="cs-state is-ok">Awarded</span>' : '') . '<span class="cs-sub">' . h(CsUi::sub([$q['quote_ref'], $q['delivery_days'] !== null ? $q['delivery_days'] . ' days' : '', $q['payment_terms']])) . '</span>'
            . ($canEdit ? '<span class="cs-sub"><a href="' . h(url($pageKey, ['a' => 'view', 'id' => $id, 'quote' => $q['id']])) . '#quoteForm">Edit</a></span>' : '') . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($cmp['lines'] as $l) {
        echo '<tr><td>' . h($l['description']) . '<span class="cs-sub">' . h(CsCost::codeLabel($l['cost_code_id'] ? (int)$l['cost_code_id'] : null)) . '</span></td><td class="num">' . qty($l['to_buy']) . ' ' . h((string)$l['unit']) . '</td>';
        foreach ($cmp['quotes'] as $q) {
            $pr = $cmp['prices'][(int)$q['id']][(int)$l['id']] ?? null;
            $isBest = $pr !== null && $cmp['best'][(int)$l['id']] !== null && abs($pr - $cmp['best'][(int)$l['id']]) < 0.0001;
            echo '<td class="num' . ($isBest ? ' cs-good fw-semibold' : '') . '">' . ($pr === null ? '<span class="text-muted">No quote</span>' : money($pr) . '<span class="cs-sub">' . money((float)$l['to_buy'] * $pr) . '</span>') . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody><tfoot><tr><td colspan="2">Total</td>';
    foreach ($cmp['quotes'] as $q) {
        $t = $cmp['totals'][(int)$q['id']];
        echo '<td class="num' . ((int)$q['id'] === $cmp['lowest'] ? ' cs-good' : '') . '">' . money($t['total']) . ($t['complete'] ? '' : '<span class="cs-sub cs-warn">Incomplete</span>') . '</td>';
    }
    echo '</tr></tfoot></table></div>' . (!$cmp['quotes'] ? CsUi::empty('No quotation yet. Enter each supplier\'s prices below.', '', 'shop') : '') . '</div>';
    echo '<div class="cs-split"><div class="cs-stack">';
    if ($canEdit) {
        $qv = $editQ ?: ['quote_date' => today()];
        $pv = $editQ ? $cmp['prices'][(int)$editQ['id']] ?? [] : [];
        $vendors = DB::pairs("SELECT id, name FROM vendors WHERE is_active = 1 AND vendor_kind IN ('supplier','both') ORDER BY name");
        echo '<form method="post" class="card" id="quoteForm">' . csrf_field() . '<input type="hidden" name="act" value="quote">' . ($editQ ? '<input type="hidden" name="quote_id" value="' . (int)$editQ['id'] . '">' : '')
            . '<div class="card-header">' . ($editQ ? 'Edit the quotation' : 'Add a quotation') . '</div><div class="card-body"><div class="row g-2">'
            . CsUi::field('Supplier', CsUi::select('vendor_id', $vendors, $qv['vendor_id'] ?? '', 'Choose', 'data-combo required'), 'col-md-6')
            . CsUi::field('Quotation ref.', CsUi::input('quote_ref', $qv['quote_ref'] ?? '', ['max' => 60]), 'col-6 col-md-3') . CsUi::field('Date', CsUi::input('quote_date', $qv['quote_date'] ?? today(), ['type' => 'date']), 'col-6 col-md-3')
            . CsUi::field('Delivery (days)', CsUi::input('delivery_days', $qv['delivery_days'] ?? '', ['type' => 'number', 'step' => '1']), 'col-4') . CsUi::field('Payment terms', CsUi::input('payment_terms', $qv['payment_terms'] ?? '', ['max' => 60]), 'col-4')
            . CsUi::field('Valid until', CsUi::input('valid_until', $qv['valid_until'] ?? '', ['type' => 'date']), 'col-4')
            . '</div></div><div class="table-responsive"><table class="table table-sm cs-lines mb-0"><thead><tr><th>Material</th><th class="num">Qty</th><th class="num">Unit price</th></tr></thead><tbody>';
        foreach ($cmp['lines'] as $l) {
            echo '<tr><td>' . h($l['description']) . '</td><td class="num">' . qty($l['to_buy']) . ' ' . h((string)$l['unit']) . '</td><td class="w-num"><input class="form-control form-control-sm text-end" name="price[' . (int)$l['id'] . ']" value="' . h(isset($pv[(int)$l['id']]) ? number_format($pv[(int)$l['id']], 2, '.', '') : '') . '" placeholder="Not quoted"></td></tr>';
        }
        echo '</tbody></table></div><div class="card-body pt-2">' . CsUi::input('remarks', $qv['remarks'] ?? '', ['max' => 255, 'placeholder' => 'Remarks (brand, specs, inclusions)']) . '</div>'
            . '<div class="card-footer d-flex gap-2"><button class="btn btn-primary btn-sm">Save quotation</button>' . ($editQ ? '<a class="btn btn-outline-secondary btn-sm" href="' . h(url($pageKey, ['a' => 'view', 'id' => $id])) . '">Cancel</a>'
            . '<button class="btn btn-outline-danger btn-sm ms-auto" name="act" value="del_quote" data-confirm="Remove this quotation?">Remove</button>' : '') . '</div></form>';
    }
    echo CsFiles::panel('canvass', $id, Access::can($pageKey, 'edit'), 'Quotation documents') . '</div><div class="cs-stack">';
    if ($canEdit && $cmp['quotes']) {
        echo '<form method="post" class="card">' . csrf_field() . '<input type="hidden" name="act" value="award"><div class="card-header">Award</div><div class="card-body">';
        foreach ($cmp['quotes'] as $q) {
            $t = $cmp['totals'][(int)$q['id']];
            echo '<label class="form-check mb-1"><input class="form-check-input" type="radio" name="quote_id" value="' . (int)$q['id'] . '"' . ((int)$q['id'] === $cmp['lowest'] ? ' checked' : '') . '> <span class="form-check-label">'
                . h($q['vendor_name']) . ', ' . money($t['total']) . ($t['complete'] ? '' : ' (incomplete)') . ((int)$q['id'] === $cmp['lowest'] ? ' <span class="cs-good">lowest</span>' : '') . '</span></label>';
        }
        echo '<label class="form-label mt-2">Reason (needed when not the lowest, or with a single quotation)</label><textarea class="form-control form-control-sm" name="reason" rows="2"></textarea></div>'
            . '<div class="card-footer"><button class="btn btn-primary btn-sm"><i class="bi bi-send"></i> Award and send for approval</button></div></form>';
    }
    if (CsApproval::requestFor('purchase', (int)$id)) {
        echo CsApproval::panel('purchase', (int)$id);
    }
    if ($canEdit) {
        echo '<div class="card"><div class="card-body">' . CsUi::confirmButton('cvCancel', 'Cancel canvass', 'Cancel ' . $c['canvass_no'], 'The request stays approved and can be canvassed again.', '<input type="hidden" name="act" value="cancel">', 'btn-outline-secondary', 'x-lg', true) . '</div></div>';
    }
    echo '</div></div>';
    layout_end();
    return;
}

$pid = CsProject::current();
$st = (string)g('status');
$statuses = ['open' => 'Open', 'for_approval' => 'For approval', 'awarded' => 'Awarded', 'cancelled' => 'Cancelled'];
layout_start('Canvass & Purchase Orders', ['sub' => 'Start a canvass from an approved material request.']);
echo cs_filter_bar($pageKey, $pid, cs_status_field($statuses));
[$f, $params] = CsProject::filter('c.project_id');
$w = '';
if ($pid) {
    $w .= ' AND c.project_id = :pid';
    $params['pid'] = $pid;
}
if (isset($statuses[$st])) {
    $w .= ' AND c.status = :st';
    $params['st'] = $st;
}
$rows = DB::all("SELECT c.*, m.mr_no, p.code AS pcode, v.name AS vendor_name, (SELECT COUNT(*) FROM cs_canvass_quotes q WHERE q.canvass_id = c.id) AS quotes
                   FROM cs_canvass c JOIN cs_material_requests m ON m.id = c.mr_id JOIN cs_projects p ON p.id = c.project_id LEFT JOIN vendors v ON v.id = c.awarded_vendor_id
                  WHERE 1 = 1 $f $w ORDER BY c.id DESC LIMIT 400", $params);
$out = [];
foreach ($rows as $r) {
    $out[] = ['<a class="cs-main-link" href="' . h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) . '">' . h($r['canvass_no']) . '</a>', h($r['mr_no']), h($r['pcode']), h(fdate($r['canvass_date'])), (int)$r['quotes'],
        h((string)$r['vendor_name']), $r['po_txn_id'] ? CsUi::txnLink((int)$r['po_txn_id']) : '', CsUi::state($r['status'])];
}
$ready = DB::all("SELECT m.*, p.code AS pcode FROM cs_material_requests m JOIN cs_projects p ON p.id = m.project_id
                   WHERE m.status IN ('approved','partially_ordered') AND NOT EXISTS (SELECT 1 FROM cs_canvass c WHERE c.mr_id = m.id AND c.status IN ('open','for_approval')) " . CsProject::filter('m.project_id', 'rd')[0] . ' ORDER BY m.needed_date LIMIT 50',
    CsProject::filter('m.project_id', 'rd')[1]);
if ($ready) {
    $rr = [];
    foreach ($ready as $m) {
        $rr[] = ['<a href="' . h(url('proc.requests', ['a' => 'view', 'id' => $m['id']])) . '">' . h($m['mr_no']) . '</a>', h($m['pcode']), h((string)$m['purpose']), h(fdate($m['needed_date']))];
    }
    echo cs_table_card('Approved requests waiting to be bought', ['Request' => '', 'Project' => '', 'Purpose' => '', 'Needed' => ''], $rr);
    echo '<div class="mb-3"></div>';
}
echo cs_table_card(count($rows) . ' canvasses', ['Canvass' => '', 'Request' => '', 'Project' => '', 'Date' => '', 'Quotes' => 'num', 'Awarded to' => '', 'Purchase order' => '', 'Status' => ''], $out, 'No canvass yet.');
layout_end();
