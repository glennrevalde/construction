<?php
declare(strict_types=1);

/** Material requests from the site: approval (with the budget check), then canvass and purchase, or issue from stock. */
$pageKey = 'proc.requests';
$a = (string)g('a', 'list');
$id = gint('id');
$m = $id ? CsMaterials::getMr($id) : null;
if ($m) {
    CsProject::requireAccess((int)$m['project_id']);
}

handle_post(function () use ($pageKey, $id, $m) {
    if ($m && cs_handle_attach('material_request', (int)$id, (int)$m['project_id'])) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if ($m && CsApproval::handlePost('material_request', (int)$id, fn() => CsMaterials::submitMr((int)$id))) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    $act = (string)p('act');
    if ($m && $act === 'canvass') {
        Access::require('proc.canvass', 'create');
        $cid = CsMaterials::startCanvass((int)$id);
        flash('success', 'Canvass started. Enter the suppliers\' quotations.');
        cs_go('proc.canvass', ['a' => 'view', 'id' => $cid]);
    }
    if ($m && in_array($act, ['close', 'cancel'], true)) {
        Access::require($pageKey, 'edit');
        if (trim((string)p('reason')) === '') {
            fail('Give the reason.');
        }
        if ($act === 'cancel' && (float)DB::value('SELECT COALESCE(SUM(qty_ordered + qty_issued),0) FROM cs_mr_lines WHERE mr_id = :m', ['m' => $id]) > 0) {
            fail('Part of this request was already ordered or issued: close it instead.');
        }
        if (CsApproval::pendingFor('material_request', (int)$id)) {
            fail('Withdraw it from approval first.');
        }
        DB::update('cs_material_requests', ['status' => $act === 'close' ? 'closed' : 'cancelled'], 'id = :id', ['id' => $id]);
        Audit::log($act, 'proc', 'material_request', (int)$id, $m['mr_no'], ['page_key' => $pageKey, 'reason' => (string)p('reason')]);
        flash('success', $act === 'close' ? 'Request closed.' : 'Request cancelled.');
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if ($act === 'save') {
        Access::require($pageKey, $id ? 'edit' : 'create');
        if (!$m) {
            CsProject::requireAccess((int)p('project_id'));
        }
        $nid = CsMaterials::saveMr($_POST, cs_lines(), $id);
        flash('success', 'Request saved. Submit it for approval when it is complete.');
        cs_go($pageKey, ['a' => 'view', 'id' => $nid]);
    }
});

if ($a === 'new' || ($a === 'edit' && $m)) {
    Access::require($pageKey, $m ? 'edit' : 'create');
    $pid = $m ? (int)$m['project_id'] : (int)(gint('project') ?? CsProject::current() ?? 0);
    $c = $m ?: ['project_id' => $pid, 'request_date' => today(), 'needed_date' => date('Y-m-d', strtotime('+5 days')), 'deliver_to' => 'site'];
    $lines = is_post() ? cs_lines() : ($m ? DB::all('SELECT * FROM cs_mr_lines WHERE mr_id = :m ORDER BY line_no', ['m' => $id]) : []);
    $acts = $pid ? DB::pairs("SELECT id, CONCAT(wbs_code, ' ', name) FROM cs_activities WHERE project_id = :p ORDER BY sort_order", ['p' => $pid]) : [];
    $ccOpts = CsCost::codeOptions();
    $row = function (array $l, string $i) use ($ccOpts): string {
        $n = fn($f) => 'lines[' . $i . '][' . $f . ']';
        return '<tr><td style="min-width:220px">' . CsMaterials::itemSelect($n('item_id'), $l['item_id'] ?? '', 'data-fill="unit:unit,cost:est_unit_cost,desc:description"') . '</td>'
            . '<td class="w-desc"><input class="form-control form-control-sm" name="' . $n('description') . '" value="' . h($l['description'] ?? '') . '" placeholder="Or describe a material not on the list"></td>'
            . '<td class="w-sm"><input class="form-control form-control-sm text-end" name="' . $n('qty') . '" value="' . h(isset($l['qty']) && $l['qty'] !== '' ? qty($l['qty']) : '') . '" data-q></td>'
            . '<td class="w-sm"><input class="form-control form-control-sm" name="' . $n('unit') . '" value="' . h($l['unit'] ?? '') . '"></td>'
            . '<td class="w-num"><input class="form-control form-control-sm text-end" name="' . $n('est_unit_cost') . '" value="' . h(isset($l['est_unit_cost']) && $l['est_unit_cost'] !== '' ? number_format((float)$l['est_unit_cost'], 2, '.', '') : '') . '" data-r></td>'
            . '<td class="w-num num" data-a>0.00</td>'
            . '<td style="min-width:170px">' . CsUi::select($n('cost_code_id'), $ccOpts, $l['cost_code_id'] ?? '', '', 'data-combo') . '</td>'
            . '<td class="grid-acts"><button type="button" class="btn btn-link btn-sm text-danger p-0" data-cs-del aria-label="Remove"><i class="bi bi-x-lg"></i></button></td></tr>';
    };
    layout_start($m ? 'Edit ' . $m['mr_no'] : 'New material request', ['module' => 'proc']);
    ?>
    <form method="post" data-cs-offline="mr-<?= (int)($id ?? 0) ?>"<?= $m ? '' : ' data-cs-new' ?>><?= csrf_field() ?><input type="hidden" name="act" value="save">
    <div class="card mb-3"><div class="card-body"><div class="row g-3">
      <?= CsUi::field('Project', $m ? '<input type="hidden" name="project_id" value="' . $pid . '"><div class="pt-1">' . CsUi::projectLink($pid) . '</div>' : CsUi::projectSelect('project_id', $pid ?: '', ['required' => true]), 'col-md-5') ?>
      <?= CsUi::field('Date', CsUi::input('request_date', fv('request_date', $c), ['type' => 'date']), 'col-6 col-md-2') ?>
      <?= CsUi::field('Needed on site by', CsUi::input('needed_date', fv('needed_date', $c), ['type' => 'date']), 'col-6 col-md-2') ?>
      <?= CsUi::field('Deliver to', CsUi::select('deliver_to', ['site' => 'Project site (stockyard)', 'warehouse' => 'Main warehouse'], fv('deliver_to', $c), null, 'data-no-combo'), 'col-md-3') ?>
      <?= CsUi::field('For activity', CsUi::select('activity_id', $acts, fv('activity_id', $c), 'Not tied to one activity'), 'col-md-5') ?>
      <?= CsUi::field('Purpose', CsUi::input('purpose', fv('purpose', $c), ['max' => 255, 'placeholder' => '2F slab pour, column rebars']), 'col-md-7') ?>
    </div></div></div>
    <div class="card mb-3"><div class="card-header">Materials</div>
      <div class="table-responsive"><table class="table table-sm cs-lines mb-0" id="mrGrid" data-cs-lines data-min-rows="3"><thead><tr><th>Material</th><th>Description</th><th class="num">Qty</th><th>Unit</th><th class="num">Est. unit cost</th><th class="num">Est. amount</th><th>Cost code</th><th></th></tr></thead>
      <tbody><?php foreach ($lines as $i => $l) { echo $row($l, 'e' . $i); } ?></tbody>
      <tfoot><tr><td colspan="5" class="text-end">Estimated total</td><td class="num" data-cs-sum="amount">0.00</td><td colspan="2"></td></tr></tfoot></table></div>
      <template data-cs-row="mrGrid"><?= $row([], '__i__') ?></template>
      <div class="card-body pt-2"><button type="button" class="btn btn-outline-secondary btn-sm" data-cs-add="mrGrid"><i class="bi bi-plus"></i> Add a material</button></div></div>
    <div class="sticky-actions"><?= save_buttons('Save request', url($pageKey, $m ? ['a' => 'view', 'id' => $id] : ['project' => $pid]), false) ?></div>
    </form>
    <?php
    layout_end();
    return;
}

if ($a === 'view' && $m) {
    $lines = DB::all('SELECT * FROM cs_mr_lines WHERE mr_id = :m ORDER BY line_no', ['m' => $id]);
    $canv = DB::all('SELECT c.*, v.name AS vendor_name FROM cs_canvass c LEFT JOIN vendors v ON v.id = c.awarded_vendor_id WHERE c.mr_id = :m ORDER BY c.id DESC', ['m' => $id]);
    $docs = DB::all("SELECT * FROM cs_material_docs WHERE mr_id = :m ORDER BY id DESC", ['m' => $id]);
    $open = array_sum(array_map(fn($l) => max(0, (float)$l['qty_approved'] - (float)$l['qty_ordered'] - (float)$l['qty_issued']), $lines));
    $edit = in_array($m['status'], ['draft', 'rejected'], true) && Access::can($pageKey, 'edit');
    $actions = ($edit ? cs_link($pageKey, ['a' => 'edit', 'id' => $id], 'Edit', 'btn-outline-secondary', 'pencil', 'edit') : '');
    if (in_array($m['status'], ['approved', 'partially_ordered'], true) && $open > 0) {
        if (Access::can('proc.canvass', 'create') && !DB::value("SELECT id FROM cs_canvass WHERE mr_id = :m AND status IN ('open','for_approval')", ['m' => $id])) {
            $actions .= ' <form method="post" class="d-inline">' . csrf_field() . '<button class="btn btn-primary btn-sm" name="act" value="canvass"><i class="bi bi-shop"></i> Canvass suppliers</button></form>';
        }
        $actions .= ' ' . cs_link('proc.materials', ['a' => 'new', 'type' => 'issue', 'mr' => $id], 'Issue from stock', 'btn-outline-primary', 'box-arrow-right', 'create');
    }
    layout_start($m['mr_no'] . ' Material request', ['module' => 'proc', 'actions' => $actions,
        'sub' => CsUi::projectLink((int)$m['project_id']) . ', ' . h(CsUi::sub(['by ' . $m['requester'], fdate($m['request_date']), $m['needed_date'] ? 'needed by ' . fdate($m['needed_date']) : ''])) . ' ' . CsUi::state($m['status'])]);
    $rows = [];
    $tot = 0.0;
    $byCode = [];
    foreach ($lines as $l) {
        $amt = (float)$l['qty'] * (float)$l['est_unit_cost'];
        $tot += $amt;
        $byCode[(int)$l['cost_code_id']] = ($byCode[(int)$l['cost_code_id']] ?? 0) + $amt;
        $rows[] = [h($l['description']), qty($l['qty']) . ' ' . h((string)$l['unit']), money($l['est_unit_cost']), money($amt), h(CsCost::codeLabel($l['cost_code_id'] ? (int)$l['cost_code_id'] : null)),
            qty($l['qty_approved']), qty($l['qty_ordered']), qty($l['qty_issued'])];
    }
    echo '<div class="cs-split"><div class="cs-stack">';
    echo cs_table_card('Materials requested', ['Material' => '', 'Qty' => 'num', 'Est. unit cost' => 'num', 'Est. amount' => 'num', 'Cost code' => '', 'Approved' => 'num', 'Ordered' => 'num', 'Issued' => 'num'], $rows, '', '',
        '<tfoot><tr><td colspan="3">Estimated total</td><td class="num">' . money($tot) . '</td><td colspan="4"></td></tr></tfoot>');
    // budget position of the cost codes on the request
    $brow = [];
    foreach ($byCode as $cc => $amt) {
        $left = CsCost::remainingBudget((int)$m['project_id'], $cc ?: null);
        $brow[] = [h(CsCost::codeLabel($cc ?: null) ?: 'No cost code'), money($amt), $left === null ? '<span class="text-muted">No budget</span>' : '<span class="' . ($left < $amt ? 'cs-bad' : 'cs-good') . '">' . money($left) . '</span>'];
    }
    echo cs_table_card('Budget check', ['Cost code' => '', 'This request' => 'num', 'Budget still free' => 'num'], $brow, '', '', '');
    $rows = [];
    foreach ($canv as $c) {
        $rows[] = ['<a href="' . h(url('proc.canvass', ['a' => 'view', 'id' => $c['id']])) . '">' . h($c['canvass_no']) . '</a>', h(fdate($c['canvass_date'])), h((string)$c['vendor_name']), $c['po_txn_id'] ? CsUi::txnLink((int)$c['po_txn_id']) : '', CsUi::state($c['status'])];
    }
    foreach ($docs as $d) {
        $rows[] = ['<a href="' . h(url('proc.materials', ['a' => 'view', 'id' => $d['id']])) . '">' . h($d['doc_no']) . '</a>', h(fdate($d['doc_date'])), h(CsMaterials::DOC_TYPES[$d['doc_type']]), money($d['total_cost']), CsUi::state($d['status'])];
    }
    echo cs_table_card('Canvass, orders and issues', ['Document' => '', 'Date' => '', 'Supplier / kind' => '', 'Order / cost' => '', 'Status' => ''], $rows, 'Nothing yet.');
    echo '</div><div class="cs-stack">';
    echo CsApproval::panel('material_request', $id, Access::can($pageKey, 'edit') && in_array($m['status'], ['draft', 'rejected'], true));
    if (Access::can($pageKey, 'edit') && !in_array($m['status'], ['closed', 'cancelled', 'for_approval'], true)) {
        echo '<div class="card"><div class="card-body d-flex flex-wrap gap-1">'
            . (in_array($m['status'], ['draft', 'rejected', 'approved'], true) ? CsUi::confirmButton('mrCancel', 'Cancel request', 'Cancel ' . $m['mr_no'], 'Nothing on it was ordered or issued.', '<input type="hidden" name="act" value="cancel">', 'btn-outline-secondary', 'x-lg', true) : '')
            . (in_array($m['status'], ['approved', 'partially_ordered', 'ordered', 'issued'], true) ? CsUi::confirmButton('mrClose', 'Close request', 'Close ' . $m['mr_no'], 'What was not ordered or issued is no longer needed.', '<input type="hidden" name="act" value="close">', 'btn-outline-secondary', 'check2-square', true) : '')
            . '</div></div>';
    }
    echo CsFiles::panel('material_request', $id, Access::can($pageKey, 'edit')) . '</div></div>';
    layout_end();
    return;
}

$pid = CsProject::current();
$st = (string)g('status');
layout_start('Material Requests', ['actions' => cs_link($pageKey, ['a' => 'new', 'project' => $pid], 'New request', 'btn-primary', 'plus-lg', 'create')]);
echo cs_filter_bar($pageKey, $pid, cs_search_field('MR no., purpose') . cs_status_field(CsMaterials::MR_STATUSES));
[$f, $params] = CsProject::filter('m.project_id');
[$w, $wp] = cs_like((string)g('q'), ['m.mr_no', 'm.purpose']);
$params += $wp;
if ($pid) {
    $w .= ' AND m.project_id = :pid';
    $params['pid'] = $pid;
}
if (isset(CsMaterials::MR_STATUSES[$st])) {
    $w .= ' AND m.status = :st';
    $params['st'] = $st;
}
$rows = DB::all("SELECT m.*, p.code AS pcode, u.full_name AS requester, (SELECT SUM(qty * est_unit_cost) FROM cs_mr_lines l WHERE l.mr_id = m.id) AS est
                   FROM cs_material_requests m JOIN cs_projects p ON p.id = m.project_id LEFT JOIN users u ON u.id = m.requested_by WHERE 1 = 1 $f $w ORDER BY m.id DESC LIMIT 400", $params);
$out = [];
foreach ($rows as $r) {
    $late = $r['needed_date'] && $r['needed_date'] < today() && in_array($r['status'], ['draft', 'for_approval', 'approved', 'partially_ordered'], true);
    $out[] = ['<a class="cs-main-link" href="' . h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) . '">' . h($r['mr_no']) . '</a><span class="cs-sub">' . h((string)$r['purpose']) . '</span>', h($r['pcode']),
        h((string)$r['requester']), h(fdate($r['request_date'])), '<span class="' . ($late ? 'cs-bad' : '') . '">' . h(fdate($r['needed_date'])) . '</span>', money($r['est']), CsUi::state($r['status'])];
}
echo cs_table_card(count($rows) . ' requests', ['Request' => '', 'Project' => '', 'By' => '', 'Date' => '', 'Needed' => '', 'Estimate' => 'num', 'Status' => ''], $out, 'No material requests.');
layout_end();
