<?php
declare(strict_types=1);

/** Subcontract agreements: the work package and its items, retention, advance and recoupment, approval, and progress. */
$pageKey = 'subk.contracts';
$a = (string)g('a', 'list');
$id = gint('id');
$s = $id ? CsSub::get($id) : null;
if ($s) {
    CsProject::requireAccess((int)$s['project_id']);
}

handle_post(function () use ($pageKey, $id, $s) {
    if ($s && cs_handle_attach('subcontract', (int)$id, (int)$s['project_id'])) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if ($s && CsApproval::handlePost('subcontract', (int)$id, fn() => CsSub::submit((int)$id))) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    $act = (string)p('act');
    if ($s && $act === 'status') {
        Access::require($pageKey, 'edit');
        CsSub::setStatus((int)$id, (string)p('status'), (string)p('reason'));
        flash('success', 'Status changed.');
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if ($act === 'save') {
        Access::require($pageKey, $id ? 'edit' : 'create');
        $nid = CsSub::save($_POST, cs_lines(), $id);
        flash('success', 'Subcontract saved. Submit it for approval when complete.');
        cs_go($pageKey, ['a' => 'view', 'id' => $nid]);
    }
});

if ($a === 'new' || ($a === 'edit' && $s)) {
    Access::require($pageKey, $s ? 'edit' : 'create');
    $pid = $s ? (int)$s['project_id'] : (int)(gint('project') ?? CsProject::current() ?? 0);
    $c = $s ?: ['project_id' => $pid, 'vendor_id' => gint('vendor'), 'retention_pct' => Settings::get('cs_retention_default', '10'), 'recoupment_pct' => 0, 'start_date' => today()];
    $items = is_post() ? cs_lines() : ($s ? DB::all('SELECT * FROM cs_subcontract_items WHERE subcontract_id = :s ORDER BY line_no', ['s' => $id]) : []);
    $vendors = DB::pairs("SELECT id, name FROM vendors WHERE is_active = 1 AND vendor_kind IN ('subcontractor','both','service') ORDER BY name");
    $acts = $pid ? DB::pairs("SELECT id, CONCAT(wbs_code, ' ', name) FROM cs_activities WHERE project_id = :p ORDER BY sort_order", ['p' => $pid]) : [];
    $ccOpts = CsCost::codeOptions();
    $row = function (array $l, string $i) use ($acts, $ccOpts): string {
        $n = fn($f) => 'lines[' . $i . '][' . $f . ']';
        return '<tr><td class="w-desc"><input type="hidden" name="' . $n('id') . '" value="' . h($l['id'] ?? '') . '"><input class="form-control form-control-sm" name="' . $n('description') . '" value="' . h($l['description'] ?? '') . '"></td>'
            . '<td class="w-sm"><input class="form-control form-control-sm" name="' . $n('unit') . '" value="' . h($l['unit'] ?? '') . '"></td>'
            . '<td class="w-sm"><input class="form-control form-control-sm text-end" name="' . $n('qty') . '" value="' . h(isset($l['qty']) && $l['qty'] !== '' ? qty($l['qty']) : '') . '" data-q></td>'
            . '<td class="w-num"><input class="form-control form-control-sm text-end" name="' . $n('unit_rate') . '" value="' . h(isset($l['unit_rate']) && $l['unit_rate'] !== '' ? number_format((float)$l['unit_rate'], 2, '.', '') : '') . '" data-r></td>'
            . '<td class="w-num num" data-a>0.00</td>'
            . '<td style="min-width:160px">' . CsUi::select($n('cost_code_id'), $ccOpts, $l['cost_code_id'] ?? '', '', 'data-combo') . '</td>'
            . '<td style="min-width:160px">' . CsUi::select($n('activity_id'), $acts, $l['activity_id'] ?? '', '', 'data-combo') . '</td>'
            . '<td class="grid-acts"><button type="button" class="btn btn-link btn-sm text-danger p-0" data-cs-del aria-label="Remove"><i class="bi bi-x-lg"></i></button></td></tr>';
    };
    layout_start($s ? 'Edit ' . $s['sc_no'] : 'New subcontract', ['module' => 'subk', 'sub' => 'Linking an item to an activity lets billings be checked against the verified accomplishment.']);
    ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="save">
    <div class="card mb-3"><div class="card-body"><div class="row g-3">
      <?= CsUi::field('Project', $s ? '<input type="hidden" name="project_id" value="' . $pid . '"><div class="pt-1">' . CsUi::projectLink($pid) . '</div>' : CsUi::projectSelect('project_id', $pid ?: '', ['required' => true, 'extra' => 'onchange="location.href=\'' . h(url($pageKey, ['a' => 'new'])) . '&project=\'+this.value"']), 'col-md-5') ?>
      <?= CsUi::field('Subcontractor', CsUi::select('vendor_id', $vendors, fv('vendor_id', $c), 'Choose', 'data-combo required'), 'col-md-4') ?>
      <?= CsUi::field('Main cost code', CsUi::costCodeSelect('cost_code_id', fv('cost_code_id', $c)), 'col-md-3') ?>
      <?= CsUi::field('Work package', CsUi::input('work_package', fv('work_package', $c), ['required' => true, 'max' => 200, 'placeholder' => 'Electrical works, Building A']), 'col-md-6') ?>
      <?= CsUi::field('Start', CsUi::input('start_date', fv('start_date', $c), ['type' => 'date']), 'col-6 col-md-3') ?>
      <?= CsUi::field('Finish', CsUi::input('end_date', fv('end_date', $c), ['type' => 'date']), 'col-6 col-md-3') ?>
      <?= CsUi::field('Retention %', CsUi::input('retention_pct', fv('retention_pct', $c), ['num' => true]), 'col-6 col-md-2') ?>
      <?= CsUi::field('Advance amount', CsUi::input('advance_amount', fv('advance_amount', $c, '0'), ['num' => true]), 'col-6 col-md-2') ?>
      <?= CsUi::field('Recoupment % per billing', CsUi::input('recoupment_pct', fv('recoupment_pct', $c), ['num' => true]), 'col-6 col-md-2') ?>
      <?= CsUi::field('VAT', CsUi::select('tax_code_id', Lookup::taxCodes(), fv('tax_code_id', $c), 'The subcontractor\'s'), 'col-6 col-md-2') ?>
      <?= CsUi::field('Scope', CsUi::textarea('scope', fv('scope', $c), 2), 'col-md-6') ?>
      <?= CsUi::field('Terms and conditions', CsUi::textarea('terms', fv('terms', $c), 2), 'col-md-6') ?>
    </div></div></div>
    <div class="card mb-3"><div class="card-header">Items (net of VAT)</div>
      <div class="table-responsive"><table class="table table-sm cs-lines mb-0" id="scGrid" data-cs-lines data-min-rows="3"><thead><tr><th>Description</th><th>Unit</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Amount</th><th>Cost code</th><th>Activity</th><th></th></tr></thead>
      <tbody><?php foreach ($items as $i => $l) { echo $row($l, 'e' . $i); } ?></tbody>
      <tfoot><tr><td colspan="4" class="text-end">Subcontract amount</td><td class="num" data-cs-sum="amount">0.00</td><td colspan="3"></td></tr></tfoot></table></div>
      <template data-cs-row="scGrid"><?= $row([], '__i__') ?></template>
      <div class="card-body pt-2"><button type="button" class="btn btn-outline-secondary btn-sm" data-cs-add="scGrid"><i class="bi bi-plus"></i> Add an item</button></div></div>
    <div class="sticky-actions"><?= save_buttons('Save subcontract', url($pageKey, $s ? ['a' => 'view', 'id' => $id] : []), false) ?></div>
    </form>
    <?php
    layout_end();
    return;
}

if ($a === 'view' && $s) {
    $pos = CsSub::position((int)$id);
    $val = CsSub::value($s);
    $items = DB::all('SELECT i.*, a.wbs_code, a.progress_pct FROM cs_subcontract_items i LEFT JOIN cs_activities a ON a.id = i.activity_id WHERE i.subcontract_id = :s ORDER BY i.line_no', ['s' => $id]);
    $billed = CsSub::billedQty((int)$id);
    $actions = (in_array($s['status'], ['draft', 'rejected'], true) ? cs_link($pageKey, ['a' => 'edit', 'id' => $id], 'Edit', 'btn-outline-secondary', 'pencil', 'edit') : '')
        . ($s['status'] === 'active' ? ' ' . cs_link('subk.billings', ['a' => 'new', 'sc' => $id], 'New billing', 'btn-primary', 'receipt', 'create') : '')
        . ($s['status'] === 'completed' && $pos['retained'] - $pos['released'] > 0.5 ? ' ' . cs_link('subk.billings', ['a' => 'new', 'sc' => $id, 'kind' => 'retention_release'], 'Release retention', 'btn-primary', 'unlock', 'create') : '');
    layout_start($s['sc_no'] . ' ' . $s['work_package'], ['module' => 'subk', 'actions' => $actions,
        'sub' => '<a href="' . h(url('subk.register', ['a' => 'view', 'id' => $s['vendor_id']])) . '">' . h($s['vendor_name']) . '</a>, ' . CsUi::projectLink((int)$s['project_id']) . ' ' . CsUi::state($s['status'])]);
    echo '<div class="cs-kpis">' . CsUi::kpi('Subcontract value', CsUi::amt($val), (float)$s['approved_variations'] ? 'Including ' . money($s['approved_variations']) . ' variations' : '', 'key')
        . CsUi::kpi('Billed', CsUi::amt($pos['billed']), $val > 0 ? CsUi::pct($pos['billed'] / $val * 100) . ' of the value' : '') . CsUi::kpi('Still to bill', CsUi::amt($val - $pos['billed']), 'A commitment of the project')
        . CsUi::kpi('Retention held', CsUi::amt($pos['retained'] - $pos['released']), CsUi::pct((float)$s['retention_pct'], 2) . ' of each billing')
        . CsUi::kpi('Advance', CsUi::amt($s['advance_amount']), money($pos['recouped']) . ' recouped') . '</div>';
    $rows = [];
    foreach ($items as $it) {
        $b = $billed[(int)$it['id']]['qty'] ?? 0;
        $pct = (float)$it['qty'] > 0 ? $b / (float)$it['qty'] * 100 : 0;
        $rows[] = [h($it['description']) . '<span class="cs-sub">' . h(CsCost::codeLabel($it['cost_code_id'] ? (int)$it['cost_code_id'] : null)) . '</span>', qty($it['qty']) . ' ' . h((string)$it['unit']), money($it['unit_rate']), money($it['amount']),
            CsUi::meter($pct, $it['activity_id'] ? (float)$it['progress_pct'] : null), $it['activity_id'] ? h($it['wbs_code']) . ' ' . CsUi::pct((float)$it['progress_pct']) : ''];
    }
    echo '<div class="cs-split"><div class="cs-stack">' . cs_table_card('Items', ['Item' => '', 'Qty' => 'num', 'Rate' => 'num', 'Amount' => 'num', 'Billed (tick: verified progress)' => '', 'Activity' => ''], $rows);
    $rows = [];
    foreach (DB::all('SELECT * FROM cs_sub_billings WHERE subcontract_id = :s ORDER BY id DESC', ['s' => $id]) as $b) {
        $rows[] = ['<a href="' . h(url('subk.billings', ['a' => 'view', 'id' => $b['id']])) . '">' . h($b['sb_no']) . '</a><span class="cs-sub">Invoice ' . h($b['invoice_no']) . '</span>', h(CsUi::label($b['kind'])), h(fdate($b['billing_date'])),
            money($b['gross_amount']), money($b['retention_amount']), money($b['net_amount']), $b['bill_txn_id'] ? CsUi::txnLink((int)$b['bill_txn_id']) : '', CsUi::state($b['status'])];
    }
    echo cs_table_card('Billings', ['Billing' => '', 'Kind' => '', 'Date' => '', 'Gross' => 'num', 'Retention' => 'num', 'Net' => 'num', 'Bill' => '', 'Status' => ''], $rows, 'No billing yet.');
    echo '<div class="card"><div class="card-body">' . CsUi::facts(['Dates' => h(fdate($s['start_date']) . ' to ' . fdate($s['end_date'])), 'Scope' => nl2br(h((string)$s['scope'])), 'Terms' => nl2br(h((string)$s['terms']))]) . '</div></div>';
    echo '</div><div class="cs-stack">' . CsApproval::panel('subcontract', $id, Access::can($pageKey, 'edit') && in_array($s['status'], ['draft', 'rejected'], true), 'Submit subcontract for approval');
    $moves = ['active' => ['completed' => 'Completed', 'terminated' => 'Terminated'], 'completed' => ['closed' => 'Closed', 'active' => 'Back to active']][$s['status']] ?? [];
    if ($moves && Access::can($pageKey, 'edit')) {
        echo '<form method="post" class="card"><div class="card-header">Status</div><div class="card-body"><div class="row g-2">' . csrf_field() . '<input type="hidden" name="act" value="status">'
            . '<div class="col-12">' . CsUi::select('status', $moves, '', null, 'data-no-combo') . '</div><div class="col-12"><input class="form-control form-control-sm" name="reason" placeholder="Reason"></div>'
            . '<div class="col-12"><button class="btn btn-outline-primary btn-sm">Change status</button></div></div></div></form>';
    }
    echo CsFiles::panel('subcontract', $id, Access::can($pageKey, 'edit'), 'Signed agreement, bonds, insurance') . cs_history('subcontract', $id) . '</div></div>';
    layout_end();
    return;
}

$pid = CsProject::current();
layout_start('Subcontract Agreements', ['actions' => cs_link($pageKey, ['a' => 'new', 'project' => $pid], 'New subcontract', 'btn-primary', 'plus-lg', 'create')]);
echo cs_filter_bar($pageKey, $pid, cs_search_field('SC no., work package, subcontractor') . cs_status_field(CsSub::STATUSES));
[$f, $params] = CsProject::filter('s.project_id');
[$w, $wp] = cs_like((string)g('q'), ['s.sc_no', 's.work_package', 'v.name']);
$params += $wp;
if ($pid) {
    $w .= ' AND s.project_id = :pid';
    $params['pid'] = $pid;
}
if (isset(CsSub::STATUSES[(string)g('status')])) {
    $w .= ' AND s.status = :st';
    $params['st'] = (string)g('status');
}
$rows = DB::all("SELECT s.*, v.name AS vendor_name, p.code FROM cs_subcontracts s JOIN vendors v ON v.id = s.vendor_id JOIN cs_projects p ON p.id = s.project_id WHERE 1 = 1 $f $w ORDER BY s.id DESC LIMIT 400", $params);
$out = [];
$t = [0.0, 0.0];
foreach ($rows as $r) {
    $pos = CsSub::position((int)$r['id']);
    $val = CsSub::value($r);
    $t[0] += $val;
    $t[1] += $pos['billed'];
    $out[] = ['<a class="cs-main-link" href="' . h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) . '">' . h($r['sc_no']) . '</a><span class="cs-sub">' . h($r['work_package']) . '</span>', h($r['vendor_name']), h($r['code']),
        money($val), money($pos['billed']), $val > 0 ? CsUi::meter($pos['billed'] / $val * 100) : '', CsUi::state($r['status'])];
}
echo cs_table_card(count($rows) . ' subcontracts', ['Subcontract' => '', 'Subcontractor' => '', 'Project' => '', 'Value' => 'num', 'Billed' => 'num', 'Progress' => '', 'Status' => ''], $out, 'No subcontracts yet.', '',
    $out ? '<tfoot><tr><td colspan="3">Total</td><td class="num">' . money($t[0]) . '</td><td class="num">' . money($t[1]) . '</td><td colspan="2"></td></tr></tfoot>' : '');
layout_end();
