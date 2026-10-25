<?php
declare(strict_types=1);

/**
 * The BOQ of a project, which is also its schedule of values: the contract price broken into billable lines.
 * Variations from client-approved change orders join it by themselves. A line can not go below what has been billed.
 */
$pageKey = 'plan.boq';
$pid = CsProject::current();
$p = $pid ? CsProject::requireAccess($pid) : null;

handle_post(function () use ($pageKey, $pid, $p) {
    if (!$p) {
        fail('Choose a project first.');
    }
    Access::require($pageKey, 'edit');
    if (p('act') === 'from_estimate') {
        $e = DB::row("SELECT * FROM cs_estimates WHERE project_id = :p AND status = 'approved' ORDER BY version_no DESC LIMIT 1", ['p' => $pid]);
        if (!$e) {
            fail('The project has no approved estimate to start from.');
        }
        if ((int)DB::value('SELECT COUNT(*) FROM cs_boq_items WHERE project_id = :p', ['p' => $pid]) > 0) {
            fail('The BOQ already has lines. Edit them instead.');
        }
        $factor = (1 + (float)$e['contingency_pct'] / 100) * (1 + (float)$e['markup_pct'] / 100);
        $lines = [];
        foreach (DB::all("SELECT * FROM cs_estimate_lines WHERE estimate_id = :e AND category NOT IN ('corporate_overhead') ORDER BY line_no", ['e' => $e['id']]) as $i => $l) {
            $lines[] = ['item_no' => $l['boq_ref'] ?: (string)($i + 1), 'description' => $l['description'], 'unit' => $l['unit'], 'qty' => $l['qty'],
                'unit_rate' => round((float)$l['unit_cost'] * $factor, 2), 'cost_code_id' => $l['cost_code_id']];
        }
        CsPlanning::saveBoq((int)$pid, $lines);
        flash('success', 'BOQ filled from estimate ' . $e['estimate_no'] . ' (unit costs plus contingency and markup). Adjust the rates to the signed contract.');
    } else {
        CsPlanning::saveBoq((int)$pid, cs_lines());
        flash('success', 'BOQ saved.');
    }
    cs_go($pageKey, ['project' => $pid]);
});

layout_start('BOQ & Schedule of Values', ['sub' => $p ? CsUi::projectLink((int)$pid) : 'Choose a project']);
echo cs_filter_bar($pageKey, $pid, '', false);
if (!$p) {
    echo '<div class="card">' . CsUi::empty('Choose a project to see its BOQ.', '', 'list-ol') . '</div>';
    layout_end();
    return;
}
$items = DB::all('SELECT * FROM cs_boq_items WHERE project_id = :p ORDER BY is_variation, sort_order, id', ['p' => $pid]);
$billed = CsPlanning::billedByBoq((int)$pid);
$base = array_filter($items, fn($i) => !(int)$i['is_variation']);
$vars = array_filter($items, fn($i) => (int)$i['is_variation']);
$cv = CsProject::contractValue((int)$pid);
$boqTotal = array_sum(array_map(fn($i) => (float)$i['amount'], $base));
$varTotal = array_sum(array_map(fn($i) => (float)$i['amount'], $vars));
$billedTotal = array_sum(array_map(fn($b) => $b['amount'], $billed));
echo '<div class="cs-kpis">' . CsUi::kpi('BOQ total', CsUi::amt($boqTotal), abs($boqTotal - $cv['original']) > 0.5 ? '<span class="cs-warn">Contract value is ' . money($cv['original']) . '</span>' : 'Agrees with the contract value', abs($boqTotal - $cv['original']) > 0.5 ? 'warn' : 'ok')
    . CsUi::kpi('Approved variations', CsUi::amt($varTotal), count($vars) . ' line(s)') . CsUi::kpi('Schedule of values', CsUi::amt($boqTotal + $varTotal), '', 'key')
    . CsUi::kpi('Billed against it', CsUi::amt($billedTotal), $boqTotal + $varTotal > 0 ? CsUi::pct($billedTotal / ($boqTotal + $varTotal) * 100) : '') . '</div>';
$canEdit = Access::can($pageKey, 'edit');
$ccOpts = CsCost::codeOptions();
$row = function (array $l, string $i) use ($ccOpts, $billed, $canEdit): string {
    $n = fn($f) => 'lines[' . $i . '][' . $f . ']';
    $b = $billed[(int)($l['id'] ?? 0)]['amount'] ?? 0.0;
    $dis = $canEdit ? '' : ' disabled';
    return '<tr><td class="w-sm"><input type="hidden" name="' . $n('id') . '" value="' . h($l['id'] ?? '') . '"><input class="form-control form-control-sm" name="' . $n('item_no') . '" value="' . h($l['item_no'] ?? '') . '"' . $dis . '></td>'
        . '<td class="w-desc"><input class="form-control form-control-sm" name="' . $n('description') . '" value="' . h($l['description'] ?? '') . '"' . $dis . '></td>'
        . '<td class="w-sm"><input class="form-control form-control-sm" name="' . $n('unit') . '" value="' . h($l['unit'] ?? '') . '"' . $dis . '></td>'
        . '<td class="w-sm"><input class="form-control form-control-sm text-end" name="' . $n('qty') . '" value="' . h(isset($l['qty']) ? qty($l['qty']) : '') . '" data-q' . $dis . '></td>'
        . '<td class="w-num"><input class="form-control form-control-sm text-end" name="' . $n('unit_rate') . '" value="' . h(isset($l['unit_rate']) ? (float)$l['unit_rate'] : '') . '" data-r' . $dis . '></td>'
        . '<td class="w-num num" data-a>' . money($l['amount'] ?? 0) . '</td>'
        . '<td>' . CsUi::select($n('cost_code_id'), $ccOpts, $l['cost_code_id'] ?? '', '', 'data-combo' . $dis) . '</td>'
        . '<td class="num">' . ($b ? money($b) : '') . '</td>'
        . '<td class="grid-acts">' . ($canEdit && !$b ? '<button type="button" class="btn btn-link btn-sm text-danger p-0" data-cs-del aria-label="Remove"><i class="bi bi-x-lg"></i></button>' : '') . '</td></tr>';
};
echo '<form method="post">' . csrf_field() . '<div class="card mb-3"><div class="card-header">Contract BOQ' . ($canEdit && !$items ? '<button class="btn btn-outline-primary btn-sm ms-auto" name="act" value="from_estimate">Fill from the approved estimate</button>' : '') . '</div>'
    . '<div class="table-responsive"><table class="table table-sm cs-lines mb-0" id="boqGrid" data-cs-lines data-min-rows="' . ($canEdit ? 3 : 0) . '"><thead><tr><th>Item</th><th>Description</th><th>Unit</th><th class="num">Qty</th><th class="num">Unit rate</th><th class="num">Amount</th><th>Cost code</th><th class="num">Billed</th><th></th></tr></thead><tbody>';
$i = 0;
foreach ($base as $l) {
    echo $row($l, 'e' . $i++);
}
echo '</tbody><tfoot><tr><td colspan="5" class="text-end">BOQ total</td><td class="num" data-cs-sum="amount">0.00</td><td colspan="3"></td></tr></tfoot></table></div>'
    . '<template data-cs-row="boqGrid">' . $row([], '__i__') . '</template>'
    . ($canEdit ? '<div class="card-body pt-2 d-flex gap-2"><button type="button" class="btn btn-outline-secondary btn-sm" data-cs-add="boqGrid"><i class="bi bi-plus"></i> Add a line</button><button class="btn btn-primary btn-sm ms-auto" name="act" value="save">Save BOQ</button></div>' : '')
    . '</div></form>';
$rows = [];
foreach ($vars as $l) {
    $rows[] = [h($l['item_no']), h($l['description']), h((string)$l['unit']), qty($l['qty']), money($l['unit_rate']), money($l['amount']), money($billed[(int)$l['id']]['amount'] ?? 0),
        $l['change_order_id'] ? '<a href="' . h(url('proj.change_orders', ['a' => 'view', 'id' => $l['change_order_id']])) . '">Change order</a>' : ''];
}
echo cs_table_card('Approved variations', ['Item' => '', 'Description' => '', 'Unit' => '', 'Qty' => 'num', 'Rate' => 'num', 'Amount' => 'num', 'Billed' => 'num', 'From' => ''], $rows, 'No client-approved variation yet.');
layout_end();
