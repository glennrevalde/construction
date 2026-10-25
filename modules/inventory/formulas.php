<?php
declare(strict_types=1);

/**
 * Processing Formulas (multi-output bills of materials)
 * Reusable recipes for disassembly, de-kitting / break-bulk and co-product / by-product processing.
 * Quantities and costs are per batch; a processing order multiplies them by the number of batches.
 */
require_once APP_ROOT . '/modules/txn/_common.php';
require_once APP_ROOT . '/modules/txn/_grids.php';

$pageKey = 'inv.formulas';
$a = g('a', 'list');

/** Readable copy of a formula for the audit trail */
function pf_snapshot(int $id): array
{
    $f = Processing::formula($id);
    if (!$f) {
        return [];
    }
    $snap = array_intersect_key($f, array_flip(['code', 'name', 'process_type', 'alloc_method', 'site_id', 'description', 'is_active']));
    $snap['lines'] = array_map(fn($l) => [
        'line_type' => $l['line_type'], 'item' => $l['item_code'], 'account' => $l['account_code'], 'description' => $l['description'],
        'qty' => $l['qty'], 'output_role' => $l['output_role'], 'alloc_basis' => $l['alloc_basis'], 'amount' => $l['amount'],
    ], Processing::formulaLines($id));
    return $snap;
}

function pf_load(string $pageKey): array
{
    $f = Processing::formula((int)g('id'));
    if (!$f) {
        flash('warning', 'Formula not found.');
        redirect(url($pageKey));
    }
    return $f;
}

// ---------------------------------------------------------------------- activate / deactivate / delete

if (in_array($a, ['activate', 'deactivate'], true) && is_post()) {
    Access::require($pageKey, 'edit');
    $f = pf_load($pageKey);
    $active = $a === 'activate' ? 1 : 0;
    DB::update('process_formulas', ['is_active' => $active], 'id = :id', ['id' => $f['id']]);
    Audit::log($a, 'inv', 'process_formula', (int)$f['id'], $f['code'] . ' ' . $f['name'], [
        'page_key' => $pageKey, 'changes' => [['field' => 'is_active', 'old' => $f['is_active'], 'new' => $active]],
    ]);
    flash('success', 'Formula ' . $f['code'] . ($active ? ' activated.' : ' deactivated. It stays on past orders but cannot be chosen for new ones.'));
    redirect(url($pageKey, ['a' => 'edit', 'id' => $f['id']]));
}
if ($a === 'delete' && is_post()) {
    Access::require($pageKey, 'delete');
    $f = pf_load($pageKey);
    $used = (int)DB::value('SELECT COUNT(*) FROM transactions WHERE process_formula_id = :id', ['id' => $f['id']]);
    if ($used > 0) {
        flash('danger', "Formula {$f['code']} is used on $used processing order(s). Deactivate it instead.");
        redirect(url($pageKey, ['a' => 'edit', 'id' => $f['id']]));
    }
    if (trim((string)p('reason')) === '') {
        flash('danger', 'Enter a reason for deleting.');
        redirect(url($pageKey, ['a' => 'edit', 'id' => $f['id']]));
    }
    $snap = pf_snapshot((int)$f['id']);
    DB::tx(function () use ($f) {
        DB::run('DELETE FROM process_formula_lines WHERE formula_id = :id', ['id' => $f['id']]);
        DB::run('DELETE FROM process_formulas WHERE id = :id', ['id' => $f['id']]);
    });
    Audit::log('delete', 'inv', 'process_formula', (int)$f['id'], $f['code'] . ' ' . $f['name'], ['page_key' => $pageKey, 'snapshot' => $snap, 'reason' => (string)p('reason')]);
    flash('success', 'Formula ' . $f['code'] . ' deleted. A full copy is kept in the audit trail.');
    redirect(url($pageKey));
}

// ---------------------------------------------------------------------- list

if ($a === 'list') {
    $show = g('show', 'active');
    $rows = DB::all(
        'SELECT f.*, s.name AS site_name, (SELECT COUNT(*) FROM transactions t WHERE t.process_formula_id = f.id AND t.is_voided = 0) AS used
           FROM process_formulas f LEFT JOIN sites s ON s.id = f.site_id'
        . ($show === 'all' ? '' : ($show === 'inactive' ? ' WHERE f.is_active = 0' : ' WHERE f.is_active = 1')) . ' ORDER BY f.code'
    );
    $lines = [];
    foreach (DB::all("SELECT fl.formula_id, fl.line_type, fl.qty, fl.output_role, i.code FROM process_formula_lines fl JOIN items i ON i.id = fl.item_id ORDER BY fl.line_no") as $l) {
        $lines[(int)$l['formula_id']][$l['line_type']][] = qty($l['qty']) . ' ' . $l['code'] . ($l['line_type'] === 'output' && $l['output_role'] !== 'co_product' ? ' (' . ($l['output_role'] === 'scrap' ? 'scrap' : 'by-product') . ')' : '');
    }
    $actions = page_link($pageKey, ['a' => 'new'], 'New formula', 'btn-primary', 'plus-lg', 'create');
    $asm = Processing::assemblies();
    layout_start('Processing Formulas', ['actions' => $actions . ' ' . page_link('inv.process', [], 'Processing orders', 'btn-outline-secondary', 'gear')]);
    ?>
    <p class="text-muted small">A formula is a multi-output bill of materials: what goes in, what comes out, and how the cost is shared. Quantities are per batch.
      Assembly items can also be taken apart straight from their bill of materials, without a formula.</p>
    <div class="d-flex gap-2 mb-3 flex-wrap">
      <form method="get" class="d-flex gap-2"><input type="hidden" name="p" value="<?= h($pageKey) ?>">
        <select class="form-select form-select-sm" name="show" data-autosubmit><?= options(['active' => 'Active formulas', 'inactive' => 'Inactive formulas', 'all' => 'All formulas'], $show) ?></select></form>
      <?php if ($asm && Access::can($pageKey, 'create')): ?>
      <form method="get" class="d-flex gap-2 ms-auto"><input type="hidden" name="p" value="<?= h($pageKey) ?>"><input type="hidden" name="a" value="new">
        <select class="form-select form-select-sm" name="bom"><?= options($asm) ?></select>
        <button class="btn btn-sm btn-outline-primary text-nowrap">New from assembly BOM</button></form>
      <?php endif; ?>
    </div>
    <div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
      <thead><tr><th>Code</th><th>Name</th><th>Type</th><th>Allocation</th><th>Inputs (per batch)</th><th>Outputs (per batch)</th><th class="num">Orders</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-3">No formulas yet.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><a href="<?= h(url($pageKey, ['a' => 'edit', 'id' => $r['id']])) ?>"><?= h($r['code']) ?></a></td>
          <td><?= h($r['name']) ?></td>
          <td class="small"><?= h(Processing::typeLabel($r['process_type'])) ?></td>
          <td class="small"><?= h(Processing::methodLabel($r['alloc_method'])) ?></td>
          <td class="small"><?= h(implode(', ', $lines[(int)$r['id']]['input'] ?? [])) ?></td>
          <td class="small"><?= h(implode(', ', $lines[(int)$r['id']]['output'] ?? [])) ?></td>
          <td class="num"><?= (int)$r['used'] ?: '' ?></td>
          <td><?= status_badge((int)$r['is_active'] ? 'active' : 'inactive') ?></td>
          <td class="text-end"><?= (int)$r['is_active'] ? page_link('inv.process', ['a' => 'new', 'source' => 'f:' . $r['id']], 'Run', 'btn-outline-success', 'play', 'create') : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div></div>
    <?php
    layout_end();
    return;
}

// ---------------------------------------------------------------------- new / edit

$f = $a === 'edit' ? pf_load($pageKey) : null;
$canEdit = $f ? Access::can($pageKey, 'edit') : Access::can($pageKey, 'create');
if (!$f && !$canEdit) {
    Access::require($pageKey, 'create');
}
$sites = Lookup::sites(false);
$accounts = Accts::all();
try {
    $absorbedAcct = Accts::get('processing_absorbed');
} catch (UserError $e) {
    $absorbedAcct = null;
}
$showCosts = Access::viewCosts();

$st = ['code' => '', 'name' => '', 'process_type' => 'co_product', 'alloc_method' => 'sales_value', 'site_id' => '', 'description' => '', 'is_active' => '1',
    'inputs' => [], 'outputs' => [], 'costs' => []];
if ($f) {
    $st = array_merge($st, array_map(fn($v) => (string)$v, array_intersect_key($f, $st)));
    foreach (Processing::formulaLines((int)$f['id']) as $l) {
        if ($l['line_type'] === 'input') {
            $st['inputs'][] = ['item_id' => $l['item_id'], 'qty' => $l['qty'], 'description' => $l['description']];
        } elseif ($l['line_type'] === 'output') {
            $st['outputs'][] = ['item_id' => $l['item_id'], 'qty' => $l['qty'], 'output_role' => $l['output_role'], 'alloc_basis' => $l['alloc_basis'], 'description' => $l['description']];
        } else {
            $st['costs'][] = ['account_id' => $l['account_id'], 'amount' => $l['amount'], 'description' => $l['description']];
        }
    }
} elseif (!is_post() && ($bom = gint('bom'))) {
    try {
        [$in, $out] = Processing::expandBom($bom, 1);
        $asm = DB::row('SELECT code, name FROM items WHERE id = :id', ['id' => $bom]);
        $st = array_merge($st, [
            'code' => mb_substr('UNB-' . $asm['code'], 0, 30), 'name' => mb_substr('Unbuild ' . $asm['name'], 0, 120),
            'process_type' => 'disassembly', 'alloc_method' => 'standard_cost',
            'description' => 'Take ' . $asm['code'] . ' apart into its stocked components.',
            'inputs' => $in, 'outputs' => $out,
        ]);
    } catch (UserError $e) {
        $GLOBALS['FORM_ERROR'] = $e->getMessage();
    }
}
if (is_post()) {
    foreach (['code', 'name', 'process_type', 'alloc_method', 'site_id', 'description'] as $k) {
        $st[$k] = trim((string)p($k));
    }
    $st['is_active'] = p('is_active') === '1' ? '1' : '0';
    $st['inputs'] = grid_rows('inputs');
    $st['outputs'] = grid_rows('outputs');
    $st['costs'] = grid_rows('costs');
}

$preview = null;
handle_post(function () use (&$st, &$preview, $f, $pageKey, $canEdit) {
    if (!$canEdit) {
        Access::denyPage($pageKey, $f ? 'edit' : 'create');
    }
    [$in, $out, $cs] = Processing::cleanLines($st['inputs'], $st['outputs'], $st['costs']);
    $st['inputs'] = $in;
    $st['outputs'] = $out;
    $st['costs'] = $cs;
    $code = mb_strtoupper($st['code']);
    if ($code === '' || mb_strlen($code) > 30) {
        fail('Enter a code of up to 30 characters.');
    }
    if ($st['name'] === '') {
        fail('Enter the formula name.');
    }
    if (!isset(Processing::TYPES[$st['process_type']])) {
        fail('Choose the process type.');
    }
    if (!isset(Processing::METHODS[$st['alloc_method']])) {
        fail('Choose the cost allocation method.');
    }
    $dup = DB::value('SELECT id FROM process_formulas WHERE code = :c AND id <> :id', ['c' => $code, 'id' => $f['id'] ?? 0]);
    if ($dup) {
        fail("Code $code is already used by another formula.");
    }
    // check the allocation rules with today's costs (percentages, weights, co-products ...)
    $est = Processing::estimate($in, $cs);
    $keyed = [];
    foreach ($out as $i => $o) {
        $keyed[$i] = ['item_id' => $o['item_id'], 'qty' => $o['qty'], 'role' => $o['output_role'], 'basis' => $o['alloc_basis']];
    }
    $alloc = Processing::allocate($keyed, $est['pool'], $st['alloc_method']);
    if (p('do') === 'preview') {
        $preview = [$est, $alloc];
        return;
    }
    $data = [
        'code' => $code, 'name' => mb_substr($st['name'], 0, 120), 'process_type' => $st['process_type'], 'alloc_method' => $st['alloc_method'],
        'site_id' => (int)$st['site_id'] ?: null, 'description' => mb_substr($st['description'], 0, 255) ?: null, 'is_active' => (int)$st['is_active'],
    ];
    $before = $f ? pf_snapshot((int)$f['id']) : [];
    $id = DB::tx(function () use ($f, $data, $in, $out, $cs) {
        if ($f) {
            $id = (int)$f['id'];
            DB::update('process_formulas', $data, 'id = :id', ['id' => $id]);
            DB::run('DELETE FROM process_formula_lines WHERE formula_id = :id', ['id' => $id]);
        } else {
            $id = DB::insert('process_formulas', $data + ['created_by' => Auth::id()]);
        }
        $n = 0;
        foreach ($in as $l) {
            DB::insert('process_formula_lines', ['formula_id' => $id, 'line_no' => ++$n, 'line_type' => 'input', 'item_id' => $l['item_id'],
                'qty' => $l['qty'], 'description' => $l['description']]);
        }
        foreach ($cs as $l) {
            DB::insert('process_formula_lines', ['formula_id' => $id, 'line_no' => ++$n, 'line_type' => 'cost', 'account_id' => $l['account_id'],
                'amount' => $l['amount'], 'description' => $l['description']]);
        }
        foreach ($out as $l) {
            DB::insert('process_formula_lines', ['formula_id' => $id, 'line_no' => ++$n, 'line_type' => 'output', 'item_id' => $l['item_id'],
                'qty' => $l['qty'], 'output_role' => $l['output_role'], 'alloc_basis' => $l['alloc_basis'], 'description' => $l['description']]);
        }
        return $id;
    });
    $after = pf_snapshot($id);
    Audit::log($f ? 'edit' : 'create', 'inv', 'process_formula', $id, $code . ' ' . $data['name'], [
        'page_key' => $pageKey, 'before' => $before, 'after' => $after, 'snapshot' => $after,
        'changes' => $f ? null : [],
    ]);
    flash('success', 'Formula ' . $code . ' saved.');
    redirect_after_save(url($pageKey, ['a' => 'new']), url($pageKey, ['a' => 'edit', 'id' => $id]));
});

// ---------------------------------------------------------------------- render

[$est, $alloc] = $preview ?? [null, null];
$inOpts = proc_formula_items(Processing::INPUT_TYPES, array_column($st['inputs'], 'item_id'));
$outOpts = proc_formula_items(Processing::OUTPUT_TYPES, array_column($st['outputs'], 'item_id'));

function proc_formula_items(array $types, array $keep): array
{
    [$in, $p] = DB::in('ty', $types);
    $opts = Lookup::itemPairs("is_active = 1 AND item_type IN $in", $p);
    foreach (array_unique(array_filter(array_map('intval', $keep))) as $id) {
        if (!isset($opts[$id]) && ($r = DB::row('SELECT code, name FROM items WHERE id = :id', ['id' => $id]))) {
            $opts[$id] = item_label($r['code'], $r['name']) . ' (inactive)';
        }
    }
    return $opts;
}

function pf_num($v, bool $money = false): string
{
    if ($v === '' || $v === null) {
        return '';
    }
    if (!is_numeric($v)) {
        return (string)$v;
    }
    if ((float)$v == 0.0) {
        return '';
    }
    return $money ? number_format((float)$v, 2, '.', '') : qty($v);
}

$remove = grid_row_buttons();
$inRow = fn($i, array $r) => '<tr><td class="col-item"><select class="form-select form-select-sm" name="inputs[' . $i . '][item_id]">' . options($inOpts, ($r['item_id'] ?? '') ?: null, '') . '</select></td>'
    . '<td class="col-desc"><input class="form-control form-control-sm" name="inputs[' . $i . '][description]" value="' . h($r['description'] ?? '') . '"></td>'
    . '<td class="col-sm"><input class="form-control form-control-sm text-end" name="inputs[' . $i . '][qty]" value="' . h(pf_num($r['qty'] ?? '')) . '"></td>'
    . ($showCosts ? '<td class="num small">' . (is_int($i) && $est ? money($est['rows'][$i]['cost'] ?? 0) : '') . '</td>' : '') . $remove . '</tr>';
$costRow = fn($i, array $r) => '<tr><td class="col-item"><select class="form-select form-select-sm" name="costs[' . $i . '][account_id]">' . options($accounts, ($r['account_id'] ?? '') ?: ($i === '__i__' ? $absorbedAcct : null), '') . '</select></td>'
    . '<td class="col-desc"><input class="form-control form-control-sm" name="costs[' . $i . '][description]" value="' . h($r['description'] ?? '') . '" placeholder="e.g. Labor per batch"></td>'
    . '<td class="col-num"><input class="form-control form-control-sm text-end" name="costs[' . $i . '][amount]" value="' . h(pf_num($r['amount'] ?? '', true)) . '"></td>' . $remove . '</tr>';
$outRow = fn($i, array $r) => '<tr><td class="col-item"><select class="form-select form-select-sm" name="outputs[' . $i . '][item_id]">' . options($outOpts, ($r['item_id'] ?? '') ?: null, '') . '</select></td>'
    . '<td class="col-desc"><input class="form-control form-control-sm" name="outputs[' . $i . '][description]" value="' . h($r['description'] ?? '') . '"></td>'
    . '<td><select class="form-select form-select-sm" name="outputs[' . $i . '][output_role]" style="min-width:150px">' . options(Processing::ROLES, $r['output_role'] ?? 'co_product') . '</select></td>'
    . '<td class="col-sm"><input class="form-control form-control-sm text-end" name="outputs[' . $i . '][qty]" value="' . h(pf_num($r['qty'] ?? '')) . '"></td>'
    . '<td class="col-sm"><input class="form-control form-control-sm text-end" name="outputs[' . $i . '][alloc_basis]" value="' . h(pf_num($r['alloc_basis'] ?? '')) . '" placeholder="auto"></td>'
    . '<td class="num small">' . (is_int($i) && $alloc ? number_format($alloc['rows'][$i]['pct'] ?? 0, 2) . '%' : '') . '</td>'
    . ($showCosts ? '<td class="num small">' . (is_int($i) && $alloc ? money($alloc['rows'][$i]['cost'] ?? 0) : '') . '</td><td class="num small">' . (is_int($i) && $alloc ? money($alloc['rows'][$i]['unit'] ?? 0, true) : '') . '</td>' : '')
    . $remove . '</tr>';

$used = $f ? (int)DB::value('SELECT COUNT(*) FROM transactions WHERE process_formula_id = :id', ['id' => $f['id']]) : 0;
$actions = [btn_link(url($pageKey), 'Formula list')];
if ($f && (int)$f['is_active'] === 1) {
    $actions[] = page_link('inv.process', ['a' => 'new', 'source' => 'f:' . $f['id']], 'Run this formula', 'btn-success', 'play', 'create');
}
if ($f && Access::can($pageKey, 'edit')) {
    $actions[] = '<form method="post" class="d-inline" action="' . h(url($pageKey, ['a' => (int)$f['is_active'] ? 'deactivate' : 'activate', 'id' => $f['id']])) . '">' . csrf_field()
        . '<button class="btn btn-sm btn-outline-secondary">' . ((int)$f['is_active'] ? 'Deactivate' : 'Activate') . '</button></form>';
}
if ($f && Access::can($pageKey, 'delete') && $used === 0) {
    $actions[] = '<button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal"><i class="bi bi-trash"></i> Delete</button>';
}
if ($f && $used) {
    $actions[] = page_link('rpt.process_yield', ['formula' => $f['id'], 'dates' => 'all'], 'Yield analysis', 'btn-outline-secondary', 'bar-chart');
}
layout_start($f ? 'Formula ' . $f['code'] : 'New Processing Formula', ['actions' => implode(' ', array_filter($actions))]);
$methodHelp = array_map(fn($x) => $x[1], Processing::METHODS);
?>
<?php if ($f && $used): ?><div class="alert alert-info py-2 small">Used on <?= $used ?> processing order(s). Changes apply to new orders only; posted orders keep their own lines.</div><?php endif; ?>
<form method="post"><?= csrf_field() ?>
<button type="submit" name="do" value="preview" class="d-none" tabindex="-1" aria-hidden="true"></button>
<fieldset <?= $canEdit ? '' : 'disabled' ?>>
<div class="card mb-3"><div class="card-body row g-2">
  <?= input_field('code', 'Code', $st['code'], ['class' => 'col-md-2', 'required' => true, 'extra' => 'maxlength="30"']) ?>
  <?= input_field('name', 'Name', $st['name'], ['class' => 'col-md-4', 'required' => true, 'extra' => 'maxlength="120"']) ?>
  <?= select_field('process_type', 'Process type', array_map(fn($x) => $x[0], Processing::TYPES), $st['process_type'], ['class' => 'col-md-3']) ?>
  <?= select_field('site_id', 'Usual processing site', $sites, $st['site_id'], ['class' => 'col-md-3', 'blank' => 'Any']) ?>
  <div class="col-md-5"><label class="form-label">Cost allocation method</label>
    <select class="form-select form-select-sm" name="alloc_method" id="pfMethod"><?= options(array_map(fn($x) => $x[0], Processing::METHODS), $st['alloc_method']) ?></select>
    <div class="form-text" id="pfMethodHelp"><?= h($methodHelp[$st['alloc_method']] ?? '') ?></div></div>
  <?= input_field('description', 'Description / instructions', $st['description'], ['class' => 'col-md-5']) ?>
  <div class="col-md-2 d-flex align-items-end"><label><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" <?= checked($st['is_active'] === '1') ?>> Active</label></div>
</div></div>

<div class="card mb-3"><div class="card-header fw-semibold">Inputs per batch</div>
  <div class="table-responsive"><table class="table table-sm line-grid mb-1" id="pfIn" data-template="tpl-pfIn" data-total-group="pf-in" data-min-rows="1">
    <thead><tr><th>Item</th><th>Description</th><th class="num">Qty</th><?= $showCosts ? '<th class="num">Est. cost</th>' : '' ?><th></th></tr></thead>
    <tbody><?php foreach (array_values($st['inputs']) as $i => $r) { echo $inRow($i, $r); } ?></tbody></table></div>
  <template id="tpl-pfIn"><?= $inRow('__i__', []) ?></template>
  <div class="card-body py-2"><?= grid_add_buttons('pfIn', 'Add input', 'inputs') ?></div>
</div>
<div class="card mb-3"><div class="card-header fw-semibold">Additional costs per batch (optional)</div>
  <div class="table-responsive"><table class="table table-sm line-grid mb-1" id="pfCost" data-template="tpl-pfCost" data-total-group="pf-cost" data-min-rows="1">
    <thead><tr><th>Account credited</th><th>Description</th><th class="num">Amount</th><th></th></tr></thead>
    <tbody><?php foreach (array_values($st['costs']) as $i => $r) { echo $costRow($i, $r); } ?></tbody></table></div>
  <template id="tpl-pfCost"><?= $costRow('__i__', []) ?></template>
  <div class="card-body py-2"><?= grid_add_buttons('pfCost', 'Add cost', 'costs') ?></div>
</div>
<div class="card mb-3"><div class="card-header fw-semibold">Outputs per batch</div>
  <div class="table-responsive"><table class="table table-sm line-grid mb-1" id="pfOut" data-template="tpl-pfOut" data-total-group="pf-out" data-min-rows="2">
    <thead><tr><th>Item</th><th>Description</th><th>Role</th><th class="num">Qty</th><th class="num">Basis</th><th class="num">Share</th><?= $showCosts ? '<th class="num">Est. cost</th><th class="num">Unit cost</th>' : '' ?><th></th></tr></thead>
    <tbody><?php foreach (array_values($st['outputs']) as $i => $r) { echo $outRow($i, $r); } ?></tbody></table></div>
  <template id="tpl-pfOut"><?= $outRow('__i__', []) ?></template>
  <div class="card-body py-2"><?= grid_add_buttons('pfOut', 'Add output', 'outputs') ?>
    <div class="small text-muted mt-2"><strong>Basis:</strong> price per unit (relative sales value), cost per unit (relative standard cost), weight per unit (weight / measure),
      % of cost (fixed percentages), value per unit (current average cost) or total cost per batch (manual). By-products and scrap: value per unit, deducted from the cost first.
      Leave it blank to use the item's own price or cost.</div></div>
</div>
<?php if ($est && $alloc && $showCosts): ?>
<div class="alert alert-primary py-2">Per batch at today's costs: total cost <strong><?= money($alloc['pool']) ?></strong>, by-products and scrap <?= money($alloc['fixed']) ?>, allocated <?= money($alloc['allocated']) ?><?= Processing::isValueMethod($st['alloc_method']) ? ', variance ' . money($alloc['variance']) : '' ?>.</div>
<?php endif; ?>
<?php if ($canEdit): ?>
<div class="d-flex gap-2">
  <button class="btn btn-outline-primary" name="do" value="preview"><i class="bi bi-calculator"></i> Check allocation</button>
  <button class="btn btn-primary" name="do" value="save"><i class="bi bi-check-lg"></i> Save formula</button>
  <?php if (Access::can($pageKey, 'create')): ?><button class="btn btn-outline-primary" name="save_new" value="1" title="Save this one and start another with empty fields"><i class="bi bi-plus-lg"></i> Save &amp; New</button><?php endif; ?>
  <a class="btn btn-outline-secondary" href="<?= h(url($pageKey)) ?>">Cancel</a>
</div>
<?php endif; ?>
</fieldset>
</form>
<script>
document.getElementById('pfMethod')?.addEventListener('change', function () {
  document.getElementById('pfMethodHelp').textContent = (<?= json_encode($methodHelp) ?>)[this.value] || '';
});
</script>
<?php
if ($f && Access::can($pageKey, 'delete') && $used === 0) {
    echo reason_modal('deleteModal', 'Delete formula', url($pageKey, ['a' => 'delete', 'id' => $f['id']]), 'btn-danger');
}
layout_end();
