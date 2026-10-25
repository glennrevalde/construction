<?php
declare(strict_types=1);

/**
 * Progress billings (applications for payment): built on the schedule of values, milestones, quantities or time and
 * materials; approved inside, sent to the client, certified, then invoiced in accounting.
 */
$pageKey = 'pbill.billings';
$a = (string)g('a', 'list');
$id = gint('id');
$b = $id ? CsBilling::get($id) : null;
if ($b) {
    CsProject::requireAccess((int)$b['project_id']);
}

handle_post(function () use ($pageKey, $id, $b) {
    if ($b && cs_handle_attach('billing', (int)$id, (int)$b['project_id'])) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if ($b && CsApproval::handlePost('billing', (int)$id, function () use ($b) {
        if (!in_array($b['status'], ['draft', 'returned', 'rejected'], true)) {
            fail('Only a draft billing is submitted.');
        }
        CsApproval::submit('billing', (int)$b['id'], $b['billing_no'] . ' ' . $b['project_code'], (int)$b['project_id'], (float)$b['amount_applied'], $b['overbill_override'] ? 'Overbilling adjustment: ' . $b['overbill_override'] : '',
            $b['overbill_override'] ? 'high' : null);
    })) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    $act = (string)p('act');
    if ($b && $act === 'sent') {
        Access::require($pageKey, 'edit');
        CsBilling::markSubmitted((int)$id, pdate('sent_date') ?: today());
        flash('success', 'Marked as sent to the client.');
    } elseif ($b && $act === 'certify') {
        Access::require($pageKey, 'approve');
        $gross = trim((string)p('certified_gross')) !== '' ? to_num(p('certified_gross')) : null;
        $inv = CsBilling::certify((int)$id, (string)p('cert_date'), trim((string)p('cert_ref')), $gross, p('make_invoice', '1') === '1');
        flash('success', 'Certification recorded.' . ($inv ? ' Invoice ' . Txn::load($inv)['txn_no'] . ' was made in accounting.' : ''));
    } elseif ($b && $act === 'invoice') {
        Access::require($pageKey, 'approve');
        $inv = CsBilling::invoice((int)$id);
        flash('success', 'Invoice ' . Txn::load($inv)['txn_no'] . ' made in accounting.');
    } elseif ($b && in_array($act, ['returned', 'rejected', 'cancelled'], true)) {
        Access::require($pageKey, 'edit');
        CsBilling::setStatus((int)$id, $act, (string)p('reason'));
        flash('success', 'Status changed.');
    } elseif ($act === 'save') {
        Access::require($pageKey, $id ? 'edit' : 'create');
        $nid = CsBilling::save($_POST, cs_lines(), $id);
        flash('success', 'Billing saved. Check it, then submit it for approval.');
        cs_go($pageKey, ['a' => 'view', 'id' => $nid]);
    }
    cs_go($pageKey, ['a' => 'view', 'id' => $id]);
});

// ---------------------------------------------------------------- form
if ($a === 'new' || ($a === 'edit' && $b)) {
    Access::require($pageKey, $b ? 'edit' : 'create');
    $pid = $b ? (int)$b['project_id'] : (int)(gint('project') ?? CsProject::current() ?? 0);
    $type = $b['billing_type'] ?? (string)g('type', '');
    if (!$pid || !isset(CsBilling::TYPES[$type])) {
        layout_start('New billing', ['module' => 'pbill']);
        echo '<form class="card" method="get"><div class="card-body"><div class="row g-3"><input type="hidden" name="p" value="' . h($pageKey) . '"><input type="hidden" name="a" value="new">'
            . CsUi::field('Project', CsUi::projectSelect('project', $pid ?: '', ['required' => true]), 'col-md-6')
            . CsUi::field('Kind of billing', CsUi::select('type', CsBilling::TYPES, $type ?: 'progress', null, 'data-no-combo'), 'col-md-6')
            . '</div></div><div class="card-footer"><button class="btn btn-primary btn-sm">Continue</button></div></form>';
        layout_end();
        return;
    }
    CsProject::requireAccess($pid);
    $t = CsBilling::terms($pid);
    $prev = CsBilling::previous($pid, $id);
    $c = $b ?: ['billing_date' => today(), 'period_to' => today(), 'period_from' => date('Y-m-d', strtotime(($prev['gross'] > 0 ? (string)DB::value("SELECT MAX(period_to) FROM cs_billings WHERE project_id = :p AND status NOT IN ('draft','cancelled','rejected')", ['p' => $pid]) : date('Y-m-01')) . ' +1 day'))];
    $mine = $b ? DB::all('SELECT * FROM cs_billing_lines WHERE billing_id = :b', ['b' => $id]) : [];
    layout_start(($b ? 'Edit ' . $b['billing_no'] : 'New ' . strtolower(CsBilling::TYPES[$type])), ['module' => 'pbill',
        'sub' => CsUi::projectLink($pid) . ', ' . h(CsUi::sub(['contract ' . money($t['revised']), 'billed before ' . money($prev['gross']), 'retention ' . CsUi::pct($t['retention_pct'], 2), 'VAT ' . CsUi::pct($t['vat_rate'], 0)]))]);
    echo '<form method="post" id="billForm">' . csrf_field() . '<input type="hidden" name="act" value="save"><input type="hidden" name="project_id" value="' . $pid . '"><input type="hidden" name="billing_type" value="' . h($type) . '">'
        . '<div class="card mb-3"><div class="card-body"><div class="row g-3">'
        . CsUi::field('Billing date', CsUi::input('billing_date', fv('billing_date', $c), ['type' => 'date']), 'col-6 col-md-2')
        . (in_array($type, CsBilling::WORK_TYPES, true) ? CsUi::field('Period from', CsUi::input('period_from', fv('period_from', $c), ['type' => 'date']), 'col-6 col-md-2') . CsUi::field('Period to', CsUi::input('period_to', fv('period_to', $c), ['type' => 'date']), 'col-6 col-md-2') : '')
        . '</div></div></div>';
    if (in_array($type, CsBilling::SOV_TYPES, true)) {
        $billed = CsPlanning::billedByBoq($pid, $id);
        $mineBy = array_column($mine, null, 'boq_item_id');
        $items = DB::all('SELECT b.*, a.progress_pct AS act_pct, a.wbs_code FROM cs_boq_items b LEFT JOIN cs_activities a ON a.id = b.activity_id WHERE b.project_id = :p' . ($type === 'change_order' ? ' AND b.is_variation = 1' : '') . ' ORDER BY b.is_variation, b.sort_order, b.id', ['p' => $pid]);
        if (!$items) {
            echo '<div class="card">' . CsUi::empty('The project has no schedule of values yet.', cs_link('plan.boq', ['project' => $pid], 'Set up the BOQ', 'btn-primary', 'list-ol', 'edit'), 'list-ol') . '</div></form>';
            layout_end();
            return;
        }
        echo '<div class="card mb-3"><div class="card-header">Schedule of values' . ($type === 'final' ? '<span class="cs-note ms-2">A final billing takes every line to 100%.</span>' : '') . '</div><div class="table-responsive"><table class="table table-sm cs-lines mb-0" id="sovGrid"><thead><tr>'
            . '<th>Item</th><th class="num">Scheduled value</th><th class="num">Before</th><th class="num">This qty</th><th class="num">This %</th><th class="num">This amount</th><th class="num">To date</th><th class="num">Balance</th></tr></thead><tbody>';
        foreach ($items as $i => $it) {
            $pa = (float)($billed[(int)$it['id']]['amount'] ?? 0);
            $sv = (float)$it['amount'];
            $m = $mineBy[$it['id']] ?? null;
            $hasQty = (float)$it['qty'] > 0 && $it['unit'] && strtolower((string)$it['unit']) !== 'lot';
            echo '<tr data-sv="' . h((string)$sv) . '" data-prev="' . h((string)$pa) . '" data-rate="' . h((string)(float)$it['unit_rate']) . '"' . ((int)$it['is_variation'] ? ' class="table-light"' : '') . '><td>' . h($it['item_no'] . ' ' . $it['description'])
                . ((int)$it['is_variation'] ? '<span class="cs-sub">Variation</span>' : '') . ($it['activity_id'] ? '<span class="cs-sub">Verified: ' . h($it['wbs_code']) . ' ' . CsUi::pct((float)$it['act_pct']) . '</span>' : '')
                . '<input type="hidden" name="lines[' . $i . '][boq_item_id]" value="' . (int)$it['id'] . '"></td><td class="num">' . money($sv) . ($hasQty ? '<span class="cs-sub">' . qty($it['qty']) . ' ' . h((string)$it['unit']) . ' @ ' . money($it['unit_rate']) . '</span>' : '') . '</td>'
                . '<td class="num">' . money($pa) . '<span class="cs-sub">' . ($sv != 0.0 ? CsUi::pct($pa / $sv * 100) : '') . '</span></td>'
                . '<td class="w-sm">' . ($hasQty ? '<input class="form-control form-control-sm text-end" name="lines[' . $i . '][this_qty]" value="' . h($m && (float)$m['this_qty'] && !is_post() ? qty($m['this_qty']) : '') . '" data-bq>' : '') . '</td>'
                . '<td class="w-sm"><input class="form-control form-control-sm text-end" name="lines[' . $i . '][this_pct]" value="' . h($m && !$hasQty && !is_post() ? number_format((float)$m['this_pct'], 2, '.', '') : '') . '" data-bp></td>'
                . '<td class="num" data-ba data-sum="amount">0.00</td><td class="num" data-bt></td><td class="num" data-bb></td></tr>';
        }
        echo '</tbody><tfoot><tr><td colspan="5" class="text-end">Gross accomplishment this billing</td><td class="num" id="sovTotal">0.00</td><td colspan="2"></td></tr></tfoot></table></div></div>';
    } elseif ($type === 'milestone') {
        $sel = array_column($mine, 'milestone_id');
        $ms = DB::all("SELECT m.* FROM cs_milestones m WHERE m.project_id = :p AND NOT EXISTS (SELECT 1 FROM cs_billing_lines l JOIN cs_billings b ON b.id = l.billing_id WHERE l.milestone_id = m.id AND b.status NOT IN ('draft','rejected','cancelled','returned')" . ($id ? ' AND b.id <> ' . (int)$id : '') . ") ORDER BY m.sort_order", ['p' => $pid]);
        echo '<div class="card mb-3"><div class="card-header">Milestones not yet billed</div><div class="card-body">';
        $i = 0;
        foreach ($ms as $m) {
            $why = CsPlanning::msStatusReasons($m);
            echo '<div class="cs-check"><label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="lines[' . $i . '][milestone_id]" value="' . (int)$m['id'] . '"' . (in_array($m['id'], $sel) ? ' checked' : '') . ((int)$m['billing_eligible'] ? '' : ' disabled') . '> '
                . '<span class="form-check-label">' . h($m['code'] . ' ' . $m['name']) . '</span></label><div class="text-end">' . money($m['contract_value']) . '<div class="cs-note">' . ((int)$m['billing_eligible'] ? '<span class="cs-good">Eligible</span>' : 'Not eligible: ' . h(implode('; ', $why))) . '</div></div></div>';
            $i++;
        }
        echo ($ms ? '' : '<p class="cs-note mb-0">Every milestone is billed, or there are none.</p>') . '</div></div>';
    } elseif (in_array($type, ['tm', 'recurring'], true)) {
        $row = fn(array $l, string $i) => '<tr><td class="w-desc"><input class="form-control form-control-sm" name="lines[' . $i . '][description]" value="' . h($l['description'] ?? '') . '"></td>'
            . '<td class="w-sm"><input class="form-control form-control-sm" name="lines[' . $i . '][unit]" value="' . h($l['unit'] ?? '') . '"></td>'
            . '<td class="w-sm"><input class="form-control form-control-sm text-end" name="lines[' . $i . '][this_qty]" value="' . h(isset($l['this_qty']) ? qty($l['this_qty']) : '') . '" data-q></td>'
            . '<td class="w-num"><input class="form-control form-control-sm text-end" name="lines[' . $i . '][unit_rate]" value="' . h(isset($l['unit_rate']) ? number_format((float)$l['unit_rate'], 2, '.', '') : '') . '" data-r></td>'
            . '<td class="w-num num" data-a>0.00</td><td class="grid-acts"><button type="button" class="btn btn-link btn-sm text-danger p-0" data-cs-del aria-label="Remove"><i class="bi bi-x-lg"></i></button></td></tr>';
        echo '<div class="card mb-3"><div class="card-header">' . ($type === 'tm' ? 'Labor, materials and equipment at the agreed rates' : 'Items billed') . '</div><div class="table-responsive"><table class="table table-sm cs-lines mb-0" id="tmGrid" data-cs-lines data-min-rows="3"><thead><tr><th>Description</th><th>Unit</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Amount</th><th></th></tr></thead><tbody>';
        foreach ($mine as $i => $l) {
            echo $row($l, 'e' . $i);
        }
        echo '</tbody><tfoot><tr><td colspan="4" class="text-end">Gross</td><td class="num" data-cs-sum="amount">0.00</td><td></td></tr></tfoot></table></div><template data-cs-row="tmGrid">' . $row([], '__i__') . '</template>'
            . '<div class="card-body pt-2"><button type="button" class="btn btn-outline-secondary btn-sm" data-cs-add="tmGrid"><i class="bi bi-plus"></i> Add a line</button></div></div>';
    } elseif ($type === 'advance') {
        echo '<div class="card mb-3"><div class="card-body">' . CsUi::field('Advance billed now (contract advance ' . money($t['advance']) . ', billed before ' . money($prev['advance']) . ')',
            CsUi::input('advance_amount', number_format(max(0, $t['advance'] - $prev['advance']), 2, '.', ''), ['num' => true]), 'col-md-4') . '</div></div>';
    } else {
        $held = $prev['retention'] - $prev['released'];
        echo '<div class="card mb-3"><div class="card-body">' . CsUi::field('Retention to release (held: ' . money($held) . ')', CsUi::input('release_amount', number_format(max(0, $held), 2, '.', ''), ['num' => true]), 'col-md-4')
            . '<p class="cs-note mt-2 mb-0">Release conditions: ' . h((string)($t['contract']['retention_release_terms'] ?? 'see the contract')) . '</p></div></div>';
    }
    if (in_array($type, CsBilling::WORK_TYPES, true)) {
        echo '<div class="card mb-3"><div class="card-body"><div class="row g-3">'
            . CsUi::field('Materials delivered, not yet installed', CsUi::input('materials_stored', fv('materials_stored', $c, '0'), ['num' => true]), 'col-6 col-md-3', 'Only when the contract allows billing them.')
            . CsUi::field('Other deductions', CsUi::input('other_deductions', fv('other_deductions', $c, '0'), ['num' => true]), 'col-6 col-md-3')
            . CsUi::field('What the deductions are', CsUi::input('deductions_note', fv('deductions_note', $c), ['max' => 255, 'placeholder' => 'Liquidated damages, back charges']), 'col-md-6')
            . ((Access::can($pageKey, 'approve') || Access::isAdmin()) ? CsUi::field('Authorized adjustment for billing past a line (only if needed)', CsUi::input('overbill_override', fv('overbill_override', $c), ['max' => 255, 'placeholder' => 'Client instruction / variation reference']), 'col-12') : '')
            . '</div></div></div>';
    }
    echo '<div class="card mb-3"><div class="card-body">' . CsUi::field('Notes', CsUi::textarea('notes', fv('notes', $c), 2), 'col-12') . '</div></div>'
        . '<div class="sticky-actions">' . save_buttons('Save billing', url($pageKey, $b ? ['a' => 'view', 'id' => $id] : ['project' => $pid]), false) . '</div></form>';
    ?>
    <script>
    (function () {
      var grid = document.getElementById('sovGrid');
      if (!grid) return;
      var num = function (v) { var n = parseFloat(String(v || '').replace(/[, ]/g, '')); return isNaN(n) ? 0 : n; };
      var fmt = function (n) { return (Math.round(n * 100) / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
      var recalc = function () {
        var total = 0;
        grid.querySelectorAll('tbody tr').forEach(function (tr) {
          var sv = num(tr.dataset.sv), prev = num(tr.dataset.prev), rate = num(tr.dataset.rate);
          var q = tr.querySelector('[data-bq]'), p = tr.querySelector('[data-bp]');
          var amt = 0;
          if (q && q.value !== '') amt = Math.round(num(q.value) * rate * 100) / 100;
          else if (p && p.value !== '') amt = Math.round(sv * num(p.value)) / 100;
          total += amt;
          var todate = prev + amt;
          tr.querySelector('[data-ba]').textContent = fmt(amt);
          tr.querySelector('[data-bt]').innerHTML = fmt(todate) + '<span class="cs-sub">' + (sv ? (todate / sv * 100).toFixed(1) + '%' : '') + '</span>';
          var bb = tr.querySelector('[data-bb]');
          bb.textContent = fmt(sv - todate);
          bb.className = 'num' + (sv - todate < -0.004 ? ' cs-bad' : '');
        });
        document.getElementById('sovTotal').textContent = fmt(total);
      };
      grid.addEventListener('input', function (e) {
        var tr = e.target.closest('tr');
        if (e.target.matches('[data-bq]')) { var p = tr.querySelector('[data-bp]'); if (p) p.value = ''; }
        if (e.target.matches('[data-bp]')) { var q = tr.querySelector('[data-bq]'); if (q) q.value = ''; }
        recalc();
      });
      recalc();
    })();
    </script>
    <?php
    layout_end();
    return;
}

// ---------------------------------------------------------------- one billing
if ($a === 'view' && $b) {
    $lines = DB::all('SELECT * FROM cs_billing_lines WHERE billing_id = :b ORDER BY id', ['b' => $id]);
    $t = CsBilling::terms((int)$b['project_id']);
    $inv = $b['invoice_txn_id'] ? Txn::load((int)$b['invoice_txn_id']) : null;
    $paid = $inv ? Balances::applied((int)$inv['id']) : 0.0;
    $print = g('print') === '1';
    $statement = function () use ($b, $lines, $t, $inv): string {
        $k = $t['contract'];
        $o = '<div class="cs-doc"><div class="cs-doc-head"><div><h2>' . h(in_array($b['billing_type'], ['advance', 'retention_release'], true) ? CsBilling::TYPES[$b['billing_type']] : 'Progress Billing No. ' . $b['billing_no']) . '</h2>'
            . '<div>' . h(Settings::get('company_name')) . '</div><div class="cs-note">' . h((string)Settings::get('company_address')) . '</div></div><div class="text-end"><div><strong>' . h($b['billing_no']) . '</strong></div><div>' . h(fdate($b['billing_date'])) . '</div>'
            . ($b['period_to'] ? '<div class="cs-note">Period ' . h(fdate($b['period_from']) . ' to ' . fdate($b['period_to'])) . '</div>' : '') . '</div></div>'
            . CsUi::facts(['Client' => h($b['customer_name']), 'Project' => h($b['project_code'] . ' ' . $b['project_name']), 'Contract' => h(($k['contract_no'] ?? '') . ' ' . ($k['title'] ?? '')) . ($k['client_ref_no'] ? ', client ref. ' . h($k['client_ref_no']) : '')]);
        if ($lines) {
            $o .= '<table class="table table-sm mt-3"><thead><tr><th>Item</th><th class="num">Scheduled value</th><th class="num">Previous</th><th class="num">This period</th><th class="num">To date</th><th class="num">%</th></tr></thead><tbody>';
            foreach ($lines as $l) {
                $to = (float)$l['prev_amount'] + (float)$l['this_amount'];
                $o .= '<tr><td>' . h($l['description']) . '</td><td class="num">' . money($l['scheduled_value']) . '</td><td class="num">' . money($l['prev_amount']) . '</td><td class="num">' . money($l['this_amount']) . '</td>'
                    . '<td class="num">' . money($to) . '</td><td class="num">' . ((float)$l['scheduled_value'] != 0.0 ? number_format($to / (float)$l['scheduled_value'] * 100, 2) : '') . '</td></tr>';
            }
            $o .= '</tbody></table>';
        }
        $rows = ['Original contract' => money($b['original_contract']), 'Approved change orders' => money((float)$b['revised_contract'] - (float)$b['original_contract']), 'Revised contract' => money($b['revised_contract'])];
        if (in_array($b['billing_type'], CsBilling::WORK_TYPES, true)) {
            $rows += ['Previously billed' => money($b['prev_gross']), 'This billing, accomplishment' => money($b['this_gross'])] + ((float)$b['materials_stored'] ? ['Materials on site' => money($b['materials_stored'])] : [])
                + ['Billed to date' => money($b['cumulative_gross']) . ($b['revised_contract'] > 0 ? ' (' . number_format((float)$b['cumulative_gross'] / (float)$b['revised_contract'] * 100, 2) . '%)' : '')];
        } else {
            $rows += ['Amount' => money($b['this_gross'])];
        }
        $rows += ['Add VAT' => money($b['vat_amount']), 'Less retention' . ((float)$b['retention_pct'] ? ' (' . (float)$b['retention_pct'] . '%)' : '') => '(' . money($b['retention_amount']) . ')',
            'Less recoupment of the advance' => '(' . money($b['advance_recoupment']) . ')', 'Less ' . ($b['deductions_note'] ?: 'other deductions') => '(' . money($b['other_deductions']) . ')',
            'Amount due' => '<strong>' . money($b['certified_amount'] ?? $b['amount_applied']) . '</strong>', 'Expected withholding by the client' => money($b['wht_expected'])];
        $o .= '<div class="row justify-content-end"><div class="col-md-7">' . CsUi::facts($rows) . '</div></div>'
            . ($b['certified_date'] ? '<p class="cs-note">Certified by the client on ' . h(fdate($b['certified_date'])) . ($b['certificate_ref'] ? ', certificate ' . h($b['certificate_ref']) : '') . ($inv ? '; invoice ' . h($inv['txn_no']) : '') . '.</p>' : '')
            . '<div class="cs-sign"><div>Prepared by</div><div>Checked by (QS)</div><div>Approved by</div><div>Received / certified by the client</div></div></div>';
        return $o;
    };
    if ($print) {
        layout_start($b['billing_no'], ['module' => 'pbill', 'no_title' => true]);
        echo '<div class="no-print mb-3"><button class="btn btn-primary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button> <a class="btn btn-outline-secondary btn-sm" href="' . h(url($pageKey, ['a' => 'view', 'id' => $id])) . '">Back</a></div>' . $statement();
        layout_end();
        return;
    }
    $editable = in_array($b['status'], ['draft', 'returned', 'rejected'], true) && Access::can($pageKey, 'edit');
    layout_start($b['billing_no'] . ' ' . CsBilling::shortType($b['billing_type']), ['module' => 'pbill', 'sub' => CsUi::projectLink((int)$b['project_id']) . ', ' . h($b['customer_name']) . ' ' . CsUi::state($b['status'], CsBilling::STATUSES[$b['status']] ?? null),
        'actions' => ($editable ? cs_link($pageKey, ['a' => 'edit', 'id' => $id], 'Edit', 'btn-outline-secondary', 'pencil', 'edit') . ' ' : '') . cs_link($pageKey, ['a' => 'view', 'id' => $id, 'print' => 1], 'Print', 'btn-outline-secondary', 'printer')]);
    echo '<div class="cs-kpis">' . CsUi::kpi('Accomplishment billed', CsUi::amt((float)$b['this_gross'] + (float)$b['materials_stored']), 'To date ' . money($b['cumulative_gross']), 'key')
        . CsUi::kpi('Amount due', CsUi::amt($b['certified_amount'] ?? $b['amount_applied']), 'After VAT, retention and recoupment') . CsUi::kpi('Retention', CsUi::amt($b['retention_amount']), CsUi::pct((float)$b['retention_pct'], 2))
        . ($inv ? CsUi::kpi('Collected', CsUi::amt($paid), 'Open ' . money((float)$inv['total'] - $paid), (float)$inv['total'] - $paid > 0.5 && $inv['due_date'] < today() ? 'bad' : ((float)$inv['total'] - $paid < 0.5 ? 'ok' : '')) : '') . '</div>';
    echo '<div class="cs-split is-wide-side"><div class="cs-stack">' . $statement() . CsFiles::panel('billing', $id, Access::can($pageKey, 'edit'), 'Accomplishment report, photos, certificate') . '</div><div class="cs-stack">';
    echo CsApproval::panel('billing', $id, Access::can($pageKey, 'edit') && in_array($b['status'], ['draft', 'returned', 'rejected'], true), 'Submit billing for approval');
    if ($b['status'] === 'approved' && Access::can($pageKey, 'edit')) {
        echo '<form method="post" class="card"><div class="card-header">Send to the client</div><div class="card-body">' . csrf_field() . '<input type="hidden" name="act" value="sent">' . CsUi::field('Date sent', CsUi::input('sent_date', today(), ['type' => 'date']), '')
            . '</div><div class="card-footer"><button class="btn btn-primary btn-sm">Mark as sent</button></div></form>';
    }
    if (in_array($b['status'], ['approved', 'submitted'], true) && Access::can($pageKey, 'approve')) {
        echo '<form method="post" class="card"><div class="card-header">Client certification</div><div class="card-body"><div class="row g-2">' . csrf_field() . '<input type="hidden" name="act" value="certify">'
            . CsUi::field('Certificate date', CsUi::input('cert_date', today(), ['type' => 'date', 'required' => true]), 'col-6') . CsUi::field('Certificate ref.', CsUi::input('cert_ref', '', ['max' => 60]), 'col-6')
            . (in_array($b['billing_type'], CsBilling::WORK_TYPES, true) ? CsUi::field('Accomplishment certified (gross)', CsUi::input('certified_gross', number_format((float)$b['this_gross'], 2, '.', ''), ['num' => true]), 'col-12', 'Lower it when the client certifies less; the lines, retention and VAT follow.') : '')
            . '<div class="col-12">' . check_field('make_invoice', 'Make the invoice in accounting now', true, '') . '</div>'
            . '</div></div><div class="card-footer"><button class="btn btn-primary btn-sm" data-confirm="Record the client\'s certification?">Record certification</button></div></form>';
    }
    if ($b['status'] === 'certified' && Access::can($pageKey, 'approve')) {
        echo '<form method="post" class="card"><div class="card-body">' . csrf_field() . '<button class="btn btn-primary btn-sm" name="act" value="invoice"><i class="bi bi-receipt"></i> Make the invoice</button></div></form>';
    }
    if ($inv) {
        echo '<div class="card"><div class="card-header">Invoice</div><div class="card-body">' . CsUi::facts(['Invoice' => CsUi::txnLink((int)$inv['id']), 'Due' => h(fdate($inv['due_date'])), 'Total' => money($inv['total']), 'Collected' => money($paid)])
            . ((float)$inv['total'] - $paid > 0.5 ? '<div class="mt-2">' . page_link('cust.payments', ['customer_id' => $b['customer_id']], 'Receive payment', 'btn-outline-primary', 'cash-coin', 'create') . '</div>' : '') . '</div></div>';
    }
    $moves = ['approved' => ['returned' => 'Returned by the client', 'rejected' => 'Rejected by the client', 'cancelled' => 'Cancel'], 'submitted' => ['returned' => 'Returned by the client', 'rejected' => 'Rejected by the client'],
        'draft' => ['cancelled' => 'Cancel'], 'returned' => ['cancelled' => 'Cancel'], 'rejected' => ['cancelled' => 'Cancel']][$b['status']] ?? [];
    if ($moves && Access::can($pageKey, 'edit')) {
        echo '<form method="post" class="card"><div class="card-header">Other outcome</div><div class="card-body"><div class="row g-2">' . csrf_field()
            . '<div class="col-12">' . CsUi::select('act', $moves, '', null, 'data-no-combo') . '</div><div class="col-12"><input class="form-control form-control-sm" name="reason" placeholder="Reason" required></div>'
            . '<div class="col-12"><button class="btn btn-outline-secondary btn-sm">Save</button></div></div></div></form>';
    }
    echo cs_history('billing', $id) . '</div></div>';
    layout_end();
    return;
}

// ---------------------------------------------------------------- list
$pid = CsProject::current();
$st = (string)g('status');
layout_start('Progress Billing', ['actions' => cs_link($pageKey, ['a' => 'new', 'project' => $pid], 'New billing', 'btn-primary', 'plus-lg', 'create')]);
echo cs_filter_bar($pageKey, $pid, cs_search_field('Billing no., certificate') . cs_status_field(CsBilling::STATUSES));
[$f, $params] = CsProject::filter('b.project_id');
[$w, $wp] = cs_like((string)g('q'), ['b.billing_no', 'b.certificate_ref']);
$params += $wp;
if ($pid) {
    $w .= ' AND b.project_id = :pid';
    $params['pid'] = $pid;
}
if (isset(CsBilling::STATUSES[$st])) {
    $w .= ' AND b.status = :st';
    $params['st'] = $st;
}
$rows = DB::all("SELECT b.*, p.code, c.name AS customer_name FROM cs_billings b JOIN cs_projects p ON p.id = b.project_id JOIN customers c ON c.id = b.customer_id WHERE 1 = 1 $f $w ORDER BY b.billing_date DESC, b.id DESC LIMIT 400", $params);
if ($pid) {
    $s = CsSummary::project($pid);
    echo '<div class="cs-kpis">' . CsUi::kpi('Revised contract', CsUi::amt($s['contract_current']), '', 'key') . CsUi::kpi('Billed to date', CsUi::amt($s['certified_gross']), $s['contract_current'] > 0 ? CsUi::pct($s['certified_gross'] / $s['contract_current'] * 100) . ' (physical ' . CsUi::pct($s['physical_pct']) . ')' : '')
        . CsUi::kpi('Collected', CsUi::amt($s['collected']), 'Open ' . money($s['ar_open'])) . CsUi::kpi('Retention receivable', CsUi::amt($s['retention_receivable']), '') . CsUi::kpi('Advance not recouped', CsUi::amt($s['advances_unrecouped']), '') . '</div>';
}
$out = [];
foreach ($rows as $r) {
    $out[] = ['<a class="cs-main-link" href="' . h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) . '">' . h($r['billing_no']) . '</a><span class="cs-sub">' . h(CsBilling::shortType($r['billing_type'])) . '</span>', h($r['code']), h($r['customer_name']),
        h(fdate($r['billing_date'])), money((float)$r['this_gross'] + (float)$r['materials_stored']), money($r['retention_amount']), money($r['certified_amount'] ?? $r['amount_applied']), $r['invoice_txn_id'] ? CsUi::txnLink((int)$r['invoice_txn_id']) : '',
        CsUi::state($r['status'], CsBilling::STATUSES[$r['status']] ?? null)];
}
echo cs_table_card(count($rows) . ' billings', ['Billing' => '', 'Project' => '', 'Client' => '', 'Date' => '', 'Gross' => 'num', 'Retention' => 'num', 'Amount due' => 'num', 'Invoice' => '', 'Status' => ''], $out, 'No billings yet.');
layout_end();
