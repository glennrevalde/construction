<?php
declare(strict_types=1);

/** Estimates: line-item costing in the 13 estimate categories, contingency and markup, approval, then the budget. */
$pageKey = 'plan.estimates';
$a = (string)g('a', 'list');
$id = gint('id');
$e = $id ? DB::row('SELECT e.*, p.code AS project_code, p.name AS project_name, o.opp_no, o.name AS opp_name FROM cs_estimates e LEFT JOIN cs_projects p ON p.id = e.project_id LEFT JOIN cs_opportunities o ON o.id = e.opportunity_id WHERE e.id = :id', ['id' => $id]) : null;
if ($e && $e['project_id']) {
    CsProject::requireAccess((int)$e['project_id']);
}

handle_post(function () use ($pageKey, $id, $e) {
    if ($e && CsApproval::handlePost('estimate', (int)$id, function () use ($e) {
        if (!in_array($e['status'], ['draft', 'rejected'], true)) {
            fail('Only a draft estimate is submitted.');
        }
        CsApproval::submit('estimate', (int)$e['id'], $e['estimate_no'], $e['project_id'] ? (int)$e['project_id'] : null, (float)$e['total_price']);
    })) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if (p('act') === 'budget' && $e) {
        Access::require('plan.budgets', 'create');
        $bid = CsPlanning::budgetFromEstimate((int)$id);
        flash('success', 'A draft budget was made from the estimate. Check it and submit it for approval.');
        cs_go('plan.budgets', ['a' => 'view', 'id' => $bid]);
    }
    if (p('act') === 'copy' && $e) {
        Access::require($pageKey, 'create');
        $lines = DB::all('SELECT category, cost_code_id, item_id, boq_ref, description, qty, unit, unit_cost FROM cs_estimate_lines WHERE estimate_id = :e ORDER BY line_no', ['e' => $id]);
        $nid = CsPlanning::saveEstimate(['project_id' => $e['project_id'], 'opportunity_id' => $e['opportunity_id'], 'title' => $e['title'], 'markup_pct' => $e['markup_pct'],
            'contingency_pct' => $e['contingency_pct'], 'notes' => 'Revised from ' . $e['estimate_no'] . ' v' . $e['version_no']], $lines);
        if ($e['status'] === 'approved') {
            DB::update('cs_estimates', ['status' => 'superseded'], 'id = :id', ['id' => $id]);
        }
        flash('success', 'A new version was made as a draft.');
        cs_go($pageKey, ['a' => 'edit', 'id' => $nid]);
    }
    if (p('act') === 'save') {
        Access::require($pageKey, $id ? 'edit' : 'create');
        $nid = CsPlanning::saveEstimate($_POST, cs_lines(), $id);
        flash('success', 'Estimate saved.');
        cs_go($pageKey, ['a' => 'view', 'id' => $nid]);
    }
});

$catOpts = array_map(fn($c) => $c[0], CsPlanning::EST_CATS);
$items = DB::all("SELECT id, code, name, unit, cost FROM items WHERE is_active = 1 AND item_type IN ('inventory','non_inventory','service','other_charge') ORDER BY code");
$itemOpts = '<option value=""></option>';
foreach ($items as $it) {
    $itemOpts .= '<option value="' . (int)$it['id'] . '" data-unit="' . h((string)$it['unit']) . '" data-cost="' . h((string)(float)$it['cost']) . '" data-desc="' . h($it['name']) . '">' . h($it['code'] . ' ' . $it['name']) . '</option>';
}

if ($a === 'new' || ($a === 'edit' && $e)) {
    Access::require($pageKey, $e ? 'edit' : 'create');
    $c = $e ?: ['project_id' => CsProject::current(), 'opportunity_id' => gint('opportunity_id'), 'markup_pct' => 12, 'contingency_pct' => 5];
    $lines = is_post() ? cs_lines() : ($e ? DB::all('SELECT * FROM cs_estimate_lines WHERE estimate_id = :e ORDER BY line_no', ['e' => $id]) : []);
    $ccOpts = CsCost::codeOptions();
    $row = function (array $l, string $i) use ($catOpts, $ccOpts, $itemOpts): string {
        $n = fn($f) => 'lines[' . $i . '][' . $f . ']';
        $io = $itemOpts;
        if (!empty($l['item_id'])) {
            $io = str_replace('value="' . (int)$l['item_id'] . '"', 'value="' . (int)$l['item_id'] . '" selected', $io);
        }
        return '<tr><td>' . CsUi::select($n('category'), $catOpts, $l['category'] ?? 'materials', null, 'data-no-combo') . '</td>'
            . '<td>' . CsUi::select($n('cost_code_id'), $ccOpts, $l['cost_code_id'] ?? '', '', 'data-combo') . '</td>'
            . '<td><select class="form-select form-select-sm" name="' . $n('item_id') . '" data-combo data-fill="unit:unit,cost:unit_cost,desc:description">' . $io . '</select></td>'
            . '<td class="w-desc"><input class="form-control form-control-sm" name="' . $n('description') . '" value="' . h($l['description'] ?? '') . '"></td>'
            . '<td class="w-sm"><input class="form-control form-control-sm text-end" name="' . $n('qty') . '" value="' . h(isset($l['qty']) && $l['qty'] !== '' ? qty($l['qty']) : '') . '" data-q></td>'
            . '<td class="w-sm"><input class="form-control form-control-sm" name="' . $n('unit') . '" value="' . h($l['unit'] ?? '') . '"></td>'
            . '<td class="w-num"><input class="form-control form-control-sm text-end" name="' . $n('unit_cost') . '" value="' . h(isset($l['unit_cost']) && $l['unit_cost'] !== '' ? (float)$l['unit_cost'] : '') . '" data-r></td>'
            . '<td class="w-num num" data-a>' . money($l['amount'] ?? 0) . '</td>'
            . '<td class="grid-acts"><button type="button" class="btn btn-link btn-sm text-danger p-0" data-cs-del aria-label="Remove"><i class="bi bi-x-lg"></i></button></td></tr>';
    };
    layout_start($e ? 'Edit estimate ' . $e['estimate_no'] : 'New estimate', ['module' => 'plan', 'sub' => 'Quantity times unit cost per line. Contingency and markup are added to the total.']);
    ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="save">
    <div class="card mb-3"><div class="card-body"><div class="row g-3">
      <?= CsUi::field('Title', CsUi::input('title', fv('title', $c), ['required' => true, 'max' => 200]), 'col-md-5') ?>
      <?= CsUi::field('Project', CsUi::projectSelect('project_id', fv('project_id', $c), ['blank' => 'None (a lead or tender)']), 'col-md-4') ?>
      <?= CsUi::field('Lead / tender', CsUi::select('opportunity_id', DB::pairs("SELECT id, CONCAT(opp_no, ' ', name) FROM cs_opportunities ORDER BY id DESC"), fv('opportunity_id', $c), 'None', 'data-combo'), 'col-md-3') ?>
      <?= CsUi::field('Contingency %', CsUi::input('contingency_pct', fv('contingency_pct', $c), ['num' => true, 'extra' => 'id="eCont"']), 'col-6 col-md-2') ?>
      <?= CsUi::field('Markup %', CsUi::input('markup_pct', fv('markup_pct', $c), ['num' => true, 'extra' => 'id="eMark"']), 'col-6 col-md-2') ?>
      <?= CsUi::field('Notes', CsUi::input('notes', fv('notes', $c), ['max' => 2000]), 'col-md-8') ?>
    </div></div></div>
    <div class="card mb-3"><div class="table-responsive"><table class="table table-sm cs-lines mb-0" id="estGrid" data-cs-lines data-min-rows="5">
      <thead><tr><th>Category</th><th>Cost code</th><th>Material or service</th><th>Description</th><th class="num">Qty</th><th>Unit</th><th class="num">Unit cost</th><th class="num">Amount</th><th></th></tr></thead>
      <tbody><?php foreach ($lines as $i => $l) { echo $row($l, 'e' . $i); } ?></tbody>
      <tfoot>
        <tr><td colspan="7" class="text-end">Direct cost</td><td class="num" data-cs-sum="amount">0.00</td><td></td></tr>
        <tr><td colspan="7" class="text-end">Contingency</td><td class="num" id="eContAmt">0.00</td><td></td></tr>
        <tr><td colspan="7" class="text-end">Markup</td><td class="num" id="eMarkAmt">0.00</td><td></td></tr>
        <tr><td colspan="7" class="text-end fw-bold">Proposed price</td><td class="num fw-bold" id="ePrice">0.00</td><td></td></tr>
      </tfoot></table></div>
      <template data-cs-row="estGrid"><?= $row([], '__i__') ?></template>
      <div class="card-body pt-2"><button type="button" class="btn btn-outline-secondary btn-sm" data-cs-add="estGrid"><i class="bi bi-plus"></i> Add a line</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-cs-add="estGrid" data-count="5">Add 5 lines</button></div>
    </div>
    <div class="sticky-actions"><?= save_buttons('Save estimate', url($pageKey, $e ? ['a' => 'view', 'id' => $id] : []), false) ?></div>
    </form>
    <script>
    (function () {
      var g = document.getElementById('estGrid');
      function upd(t) {
        var sub = t ? (t.amount || 0) : 0;
        var c = Math.round(sub * csNum(document.getElementById('eCont').value)) / 100;
        var m = Math.round((sub + c) * csNum(document.getElementById('eMark').value)) / 100;
        document.getElementById('eContAmt').textContent = csFmt(c);
        document.getElementById('eMarkAmt').textContent = csFmt(m);
        document.getElementById('ePrice').textContent = csFmt(sub + c + m);
      }
      var last = null;
      g.addEventListener('cs:totals', function (e) { last = e.detail; upd(last); });
      ['eCont', 'eMark'].forEach(function (i) { document.getElementById(i).addEventListener('input', function () { upd(last); }); });
      if (g.csRecalc) g.csRecalc();
    })();
    </script>
    <?php
    layout_end();
    return;
}

if ($a === 'view' && $e) {
    $lines = DB::all('SELECT * FROM cs_estimate_lines WHERE estimate_id = :e ORDER BY line_no', ['e' => $id]);
    $byCat = [];
    foreach ($lines as $l) {
        $byCat[$l['category']] = ($byCat[$l['category']] ?? 0) + (float)$l['amount'];
    }
    $actions = (in_array($e['status'], ['draft', 'rejected'], true) ? cs_link($pageKey, ['a' => 'edit', 'id' => $id], 'Edit', 'btn-outline-secondary', 'pencil', 'edit') : '')
        . (Access::can($pageKey, 'create') ? ' <form method="post" class="d-inline">' . csrf_field() . '<input type="hidden" name="act" value="copy"><button class="btn btn-outline-secondary btn-sm"><i class="bi bi-files"></i> New version</button></form>' : '')
        . ($e['status'] === 'approved' && $e['project_id'] && Access::can('plan.budgets', 'create') ? ' <form method="post" class="d-inline">' . csrf_field() . '<input type="hidden" name="act" value="budget"><button class="btn btn-primary btn-sm"><i class="bi bi-pie-chart"></i> Make the budget</button></form>' : '');
    layout_start($e['estimate_no'] . ' v' . (int)$e['version_no'] . ' ' . $e['title'], ['module' => 'plan', 'actions' => $actions,
        'sub' => ($e['project_id'] ? CsUi::projectLink((int)$e['project_id']) : h($e['opp_no'] . ' ' . $e['opp_name'])) . ' ' . CsUi::state($e['status'])]);
    echo '<div class="cs-kpis">' . CsUi::kpi('Direct cost', CsUi::amt($e['total_cost']), count($lines) . ' lines') . CsUi::kpi('Contingency', CsUi::amt($e['contingency_amt']), CsUi::pct((float)$e['contingency_pct']))
        . CsUi::kpi('Markup', CsUi::amt((float)$e['total_price'] - (float)$e['total_cost'] - (float)$e['contingency_amt']), CsUi::pct((float)$e['markup_pct']))
        . CsUi::kpi('Proposed price', CsUi::amt($e['total_price']), 'Net of VAT', 'key') . '</div>';
    echo '<div class="cs-split"><div class="cs-stack">';
    $rows = [];
    foreach ($lines as $l) {
        $rows[] = [h(CsPlanning::EST_CATS[$l['category']][0] ?? $l['category']), h(CsCost::codeLabel($l['cost_code_id'] ? (int)$l['cost_code_id'] : null)), h($l['description']), qty($l['qty']), h((string)$l['unit']), money($l['unit_cost']), money($l['amount'])];
    }
    echo cs_table_card('Lines', ['Category' => '', 'Cost code' => '', 'Description' => '', 'Qty' => 'num', 'Unit' => '', 'Unit cost' => 'num', 'Amount' => 'num'], $rows, 'No lines.', '',
        '<tfoot><tr><td colspan="6">Direct cost</td><td class="num">' . money($e['total_cost']) . '</td></tr></tfoot>');
    echo '</div><div class="cs-stack">' . CsApproval::panel('estimate', $id, Access::can($pageKey, 'edit') && in_array($e['status'], ['draft', 'rejected'], true));
    $rows = [];
    foreach (CsPlanning::EST_CATS as $k => [$label]) {
        if (!empty($byCat[$k])) {
            $rows[] = [h($label), money($byCat[$k]), (float)$e['total_cost'] > 0 ? CsUi::pct($byCat[$k] / (float)$e['total_cost'] * 100) : ''];
        }
    }
    echo cs_table_card('By category', ['Category' => '', 'Amount' => 'num', 'Share' => 'num'], $rows) . '</div></div>';
    layout_end();
    return;
}

$pid = CsProject::current();
[$f, $params] = CsProject::filter('e.project_id');
$w = $e ? '' : '';
if ($pid) {
    $w .= ' AND e.project_id = :pid';
    $params['pid'] = $pid;
}
[$lw, $lp] = cs_like((string)g('q'), ['e.estimate_no', 'e.title']);
$rows = DB::all("SELECT e.*, p.code AS project_code, o.opp_no FROM cs_estimates e LEFT JOIN cs_projects p ON p.id = e.project_id LEFT JOIN cs_opportunities o ON o.id = e.opportunity_id
                  WHERE (e.project_id IS NULL OR 1 = 1 $f) $w $lw ORDER BY e.id DESC LIMIT 300", $params + $lp);
layout_start('Estimates', ['actions' => cs_link($pageKey, ['a' => 'new'], 'New estimate', 'btn-primary', 'plus-lg', 'create')]);
echo cs_filter_bar($pageKey, $pid, cs_search_field('Number, title'));
$out = [];
foreach ($rows as $r) {
    $out[] = ['<a class="cs-main-link" href="' . h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) . '">' . h($r['estimate_no']) . ' v' . (int)$r['version_no'] . '</a><span class="cs-sub">' . h($r['title']) . '</span>',
        h($r['project_code'] ?: (string)$r['opp_no']), money($r['total_cost']), money($r['total_price']), CsUi::state($r['status'])];
}
echo cs_table_card(count($rows) . ' estimates', ['Estimate' => '', 'Project / tender' => '', 'Cost' => 'num', 'Price' => 'num', 'Status' => ''], $out, 'No estimates yet.');
layout_end();
