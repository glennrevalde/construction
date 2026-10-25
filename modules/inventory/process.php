<?php
declare(strict_types=1);

/**
 * Disassemble / Process Items
 * One or more inputs are converted into several outputs and the cost is allocated among them:
 * disassembly (unbuild) orders, de-kitting / break-bulk, and co-product / by-product processing.
 */
require_once APP_ROOT . '/modules/txn/_common.php';
require_once APP_ROOT . '/modules/txn/_grids.php';

$pageKey = 'inv.process';
$txnType = Processing::TXN_TYPE;
$a = g('a', 'list');

// ---------------------------------------------------------------------- helpers

function proc_load(string $pageKey): array
{
    $t = Txn::load((int)g('id'));
    if (!$t || $t['txn_type'] !== Processing::TXN_TYPE) {
        flash('warning', 'Processing order not found.');
        redirect(url($pageKey));
    }
    if (!Access::siteAllowed($t['site_id'] ? (int)$t['site_id'] : null)) {
        Access::denyPage($pageKey, 'open a processing order of another site on');
    }
    return $t;
}

/** Lines of an order split into [inputs, outputs, costs] */
function proc_split(int $txnId): array
{
    $in = $out = $cs = [];
    foreach (Txn::lines($txnId) as $l) {
        if ($l['line_kind'] === 'input') {
            $in[] = $l;
        } elseif ($l['line_kind'] === 'output') {
            $out[] = $l;
        } elseif ($l['line_kind'] === 'cost') {
            $cs[] = $l;
        }
    }
    return [$in, $out, $cs];
}

/** Supervisor approval when the order is above the user's amount limit */
function proc_approval(string $pageKey, float $pool): ?array
{
    return Approval::check($pageKey, Approval::exceeds(Access::limit('max_amount'), $pool),
        'processing order of ' . money($pool) . ', above your amount limit');
}

/** "Formula" / "Unbuild assembly" choices */
function proc_sources(?int $keepFormulaId = null): array
{
    $out = [];
    foreach (Processing::formulas() as $id => $label) {
        $out['f:' . $id] = 'Formula: ' . $label;
    }
    if ($keepFormulaId && !isset($out['f:' . $keepFormulaId]) && ($f = Processing::formula($keepFormulaId))) {
        $out['f:' . $keepFormulaId] = 'Formula: ' . $f['code'] . ' - ' . $f['name'] . ' (inactive)';
    }
    foreach (Processing::assemblies() as $id => $label) {
        $out['bom:' . $id] = 'Unbuild assembly (bill of materials): ' . $label;
    }
    return $out;
}

function proc_item_opts(array $types, array $keepIds = []): array
{
    [$in, $p] = DB::in('ty', $types);
    $opts = Lookup::itemPairs("is_active = 1 AND item_type IN $in", $p);
    foreach (array_unique(array_filter(array_map('intval', $keepIds))) as $id) {
        if (!isset($opts[$id]) && ($r = DB::row('SELECT code, name FROM items WHERE id = :id', ['id' => $id]))) {
            $opts[$id] = item_label($r['code'], $r['name']) . ' (inactive)';
        }
    }
    return $opts;
}

/** A quantity for an input box: formatted when numeric, as typed otherwise */
/** The unit cost of an input line: its rate, else its cost over its quantity */
function proc_unit_cost(array $l): float
{
    if ((float)$l['rate'] != 0.0) {
        return (float)$l['rate'];
    }
    return abs((float)$l['qty']) > 0.00001 ? round((float)$l['amount'] / (float)$l['qty'], 4) : 0.0;
}

/** Variance quantity: the input quantity less the output quantity (a loss in processing when positive) */
function proc_qty_variance(float $inQty, float $outQty): string
{
    $v = round($inQty - $outQty, 4);
    return abs($v) < 0.00005 ? '0' : qty($v);
}

function proc_num_value($v): string
{
    if ($v === '' || $v === null) {
        return '';
    }
    return is_numeric($v) ? qty($v) : (string)$v;
}

function proc_type_badge(?string $type): string
{
    $color = ['disassembly' => 'info', 'dekit' => 'secondary', 'co_product' => 'primary'][(string)$type] ?? 'light';
    return badge(Processing::typeLabel($type), $color);
}

function proc_role_badge(?string $role): string
{
    $role = $role ?: 'co_product';
    $color = ['co_product' => 'success', 'by_product' => 'warning', 'scrap' => 'secondary'][$role] ?? 'light';
    return badge(Processing::roleLabel($role), $color);
}

// ---------------------------------------------------------------------- complete a pending order

if ($a === 'finalize' && is_post()) {
    Access::require($pageKey, 'edit');
    $t = proc_load($pageKey);
    try {
        if ((int)$t['is_pending'] !== 1 || (int)$t['is_voided'] === 1) {
            fail('Only pending processing orders can be completed.');
        }
        [$in, $out, $cs] = proc_split((int)$t['id']);
        $short = Processing::shortInputs($in, (int)$t['site_id'], null, pdate('txn_date', today()));
        if ($short) {
            fail('Still short of inputs at the processing site: ' . implode(', ', $short) . '.');
        }
        $est = Processing::estimate($in, $cs);
        $approver = proc_approval($pageKey, $est['pool']);
        $keep = ['id', 'line_kind', 'item_id', 'account_id', 'description', 'qty', 'unit', 'site_id', 'amount', 'output_role', 'alloc_basis', 'planned_qty'];
        $lines = array_map(fn($l) => array_intersect_key($l, array_flip($keep)), array_merge($in, $cs, $out));
        Txn::save($txnType, [
            'txn_date' => pdate('txn_date', today()), 'is_pending' => 0, 'status' => 'completed',
            'approved_by' => $approver['id'] ?? $t['approved_by'],
        ], $lines, (int)$t['id'], ['audit_action' => 'mark_final', 'approved_by' => $approver['username'] ?? null]);
        flash('success', 'Processing order completed and posted.');
    } catch (UserError $e) {
        if (DB::inTransaction()) {
            DB::rollBack();
        }
        flash('danger', $e->getMessage());
    }
    redirect(url($pageKey, ['a' => 'view', 'id' => $t['id']]));
}

// ---------------------------------------------------------------------- view / print / void / delete

if ($a === 'view') {
    proc_view(proc_load($pageKey), $pageKey);
    return;
}
if ($a === 'print') {
    $t = proc_load($pageKey);
    Access::require($pageKey, 'print');
    Audit::log('print', 'inv', 'transaction', (int)$t['id'], Txn::ref($t), ['page_key' => $pageKey]);
    proc_print($t);
    return;
}
if (in_array($a, ['void', 'delete'], true) && is_post()) {
    $t = proc_load($pageKey);
    $short = (int)$t['is_voided'] === 0 ? Processing::reversalShortfalls((int)$t['id']) : [];
    if ($short) {
        if (Settings::get('allow_negative_stock') !== '1') {
            flash('danger', 'This order cannot be ' . ($a === 'void' ? 'voided' : 'deleted') . ': its outputs were already used, and stock would go below zero for ' . implode(', ', $short) . '.');
            redirect(Txn::url($t));
        }
        flash('warning', 'Its outputs were already used; stock is now negative for ' . implode(', ', $short) . '. Record the missing receipt or adjustment.');
    }
}
if (txn_common_actions($pageKey, [$txnType])) {
    return;
}
if ($a === 'list') {
    proc_list($pageKey);
    return;
}

// ---------------------------------------------------------------------- new / edit

$editing = $a === 'edit';
$t = $editing ? proc_load($pageKey) : null;
Access::require($pageKey, $editing ? 'edit' : 'create');
if ($t && (int)$t['is_voided'] === 1) {
    flash('warning', 'A voided processing order cannot be edited.');
    redirect(Txn::url($t));
}

$sites = Lookup::sites();
$accounts = Accts::all();
try {
    $absorbedAcct = Accts::get('processing_absorbed');
} catch (UserError $e) {
    $absorbedAcct = null;
}
try {
    $varianceAcct = Accts::get('processing_variance');
} catch (UserError $e) {
    $varianceAcct = null;
}
$showCosts = Access::viewCosts();

// form state
$st = [
    'process_type' => 'disassembly', 'source' => '', 'batches' => '1', 'txn_date' => today(),
    'site_id' => (string)array_key_first($sites), 'site2_id' => '', 'alloc_method' => '', 'account_id' => (string)$varianceAcct,
    'ref_no' => '', 'memo' => '', 'class_id' => '', 'inputs' => [], 'outputs' => [], 'costs' => [],
];
if ($t) {
    [$in, $out, $cs] = proc_split((int)$t['id']);
    $st = array_merge($st, [
        'process_type' => $t['process_type'] ?: 'co_product', 'source' => $t['process_formula_id'] ? 'f:' . $t['process_formula_id'] : '',
        'batches' => $t['process_batches'] !== null ? qty($t['process_batches']) : '1', 'txn_date' => $t['txn_date'],
        'site_id' => (string)$t['site_id'], 'site2_id' => (string)($t['site2_id'] ?? ''), 'alloc_method' => (string)$t['alloc_method'],
        'account_id' => (string)($t['account_id'] ?: $varianceAcct), 'ref_no' => (string)$t['ref_no'], 'memo' => (string)$t['memo'],
        'class_id' => (string)($t['class_id'] ?? ''),
        'inputs' => $in, 'outputs' => $out, 'costs' => $cs,
    ]);
}
if (!is_post() && !$t) {
    // presets from links: ?source=f:3&batches=2, ?item_id=12 (an input item; assemblies load their bill of materials)
    $src = (string)g('source');
    if ($src === '' && ($iid = gint('item_id'))) {
        $it = DB::row('SELECT id, item_type FROM items WHERE id = :id', ['id' => $iid]);
        if ($it && $it['item_type'] === 'assembly' && isset(Processing::assemblies()[$iid])) {
            $src = 'bom:' . $iid;
        } elseif ($it) {
            $st['inputs'] = [['item_id' => $iid, 'qty' => '1', 'planned_qty' => null, 'description' => '']];
            $fid = DB::value("SELECT f.id FROM process_formulas f JOIN process_formula_lines fl ON fl.formula_id = f.id
                               WHERE f.is_active = 1 AND fl.line_type = 'input' AND fl.item_id = :i ORDER BY f.code LIMIT 1", ['i' => $iid]);
            if ($fid) {
                $src = 'f:' . $fid;
            }
        }
    }
    if ($src !== '') {
        $_POST = ['do' => 'load', 'source' => $src, 'batches' => (string)g('batches', '1')];
        $autoLoad = true;
    }
    if ($sid = gint('site_id')) {
        $st['site_id'] = (string)$sid;
    }
}
$posted = is_post() || !empty($autoLoad);
if ($posted) {
    foreach (['process_type', 'source', 'batches', 'txn_date', 'site_id', 'site2_id', 'alloc_method', 'account_id', 'ref_no', 'memo', 'class_id'] as $k) {
        if (array_key_exists($k, $_POST) && is_string($_POST[$k])) {
            $st[$k] = trim($_POST[$k]);
        }
    }
    if (empty($autoLoad)) {
        $st['inputs'] = grid_rows('inputs');
        $st['outputs'] = grid_rows('outputs');
        $st['costs'] = grid_rows('costs');
    }
}

$preview = null;
$runForm = function (bool $auto) use (&$st, &$preview, $t, $sites, $pageKey, $txnType): void {
    $do = (string)($_POST['do'] ?? 'preview');
    $batches = to_num($st['batches'] ?: '1');
    if ($do === 'load') {
        if ($batches <= 0) {
            fail('Enter the number of batches (or units to take apart) greater than zero.');
        }
        $src = $st['source'];
        if (str_starts_with($src, 'f:')) {
            $f = Processing::formula((int)substr($src, 2));
            if (!$f) {
                fail('Choose a formula.');
            }
            [$in, $out, $cs] = Processing::expandFormula((int)$f['id'], $batches);
            $st['process_type'] = $f['process_type'];
            $st['alloc_method'] = $f['alloc_method'];
            if ($f['site_id'] && isset($sites[(int)$f['site_id']])) {
                $st['site_id'] = (string)$f['site_id'];
            }
        } elseif (str_starts_with($src, 'bom:')) {
            [$in, $out, $cs] = Processing::expandBom((int)substr($src, 4), $batches);
            $st['process_type'] = 'disassembly';
            if ($st['alloc_method'] === '' || $auto) {
                $st['alloc_method'] = 'standard_cost';
            }
        } else {
            fail('Choose a formula or an assembly to load, or enter the lines yourself.');
        }
        $st['inputs'] = $in;
        $st['outputs'] = $out;
        $st['costs'] = $cs;
        // show the allocation right away when possible
        try {
            $est = Processing::estimate($in, $cs);
            $keyed = [];
            foreach ($out as $i => $o) {
                $keyed[$i] = ['item_id' => $o['item_id'], 'qty' => $o['qty'], 'role' => $o['output_role'], 'basis' => $o['alloc_basis']];
            }
            $preview = [$est, Processing::allocate($keyed, $est['pool'], $st['alloc_method'] ?: Processing::defaultMethod($st['process_type']))];
        } catch (UserError $e) {
            $preview = null;
        }
        return;
    }

    $method = $st['alloc_method'] ?: Processing::defaultMethod($st['process_type']);
    $st['alloc_method'] = $method;
    [$in, $out, $cs] = Processing::cleanLines($st['inputs'], $st['outputs'], $st['costs'], $method);
    $st['inputs'] = $in;
    $st['outputs'] = $out;
    $st['costs'] = $cs;
    if (!isset(Processing::TYPES[$st['process_type']])) {
        fail('Choose the process type.');
    }
    if (!isset(Processing::METHODS[$method])) {
        fail('Choose a cost allocation method.');
    }
    $siteId = (int)$st['site_id'];
    if (!$siteId || !isset($sites[$siteId])) {
        fail('Choose the site where the inputs are processed.');
    }
    $site2 = (int)$st['site2_id'] ?: $siteId;
    if (!isset($sites[$site2])) {
        fail('You are not allowed to receive outputs at that site.');
    }
    if (!valid_date($st['txn_date'])) {
        fail('Enter a valid date.');
    }
    $formulaId = str_starts_with($st['source'], 'f:') ? (int)substr($st['source'], 2) : null;
    if ($formulaId && !Processing::formula($formulaId)) {
        $formulaId = null;
    }
    $isValue = Processing::isValueMethod($method);
    $varAcct = $isValue ? ((int)$st['account_id'] ?: null) : null;

    [$lines, $est, $alloc] = Processing::orderLines($in, $out, $cs, $method);
    if ($do !== 'save' && $do !== 'pending') {
        $preview = [$est, $alloc];
        return;
    }
    $pending = $do === 'pending';
    $short = Processing::shortInputs($in, $siteId, $t && (int)$t['is_pending'] === 0 ? (int)$t['id'] : null, $st['txn_date']);
    if ($short && !$pending) {
        fail('Not enough stock at the processing site: ' . implode(', ', $short) . '. Save the order as pending, or transfer or receive the items first.');
    }
    $approver = proc_approval($pageKey, $est['pool']);

    $header = [
        'txn_date' => $st['txn_date'], 'site_id' => $siteId, 'site2_id' => $site2 !== $siteId ? $site2 : null,
        'process_type' => $st['process_type'], 'alloc_method' => $method, 'process_formula_id' => $formulaId,
        'process_batches' => $formulaId || str_starts_with($st['source'], 'bom:') ? to_num($st['batches'] ?: '1') : null,
        'account_id' => $varAcct, 'ref_no' => mb_substr($st['ref_no'], 0, 60) ?: null, 'memo' => mb_substr($st['memo'], 0, 255) ?: null,
        'class_id' => Classes::ready() ? Classes::valid($st['class_id']) : null,
        'is_pending' => $pending ? 1 : 0, 'status' => $pending ? 'pending' : 'completed',
        'subtotal' => $est['materials'], 'total' => $est['pool'],
        'approved_by' => $approver['id'] ?? ($t['approved_by'] ?? null),
    ];
    $id = Txn::save($txnType, $header, $lines, $t ? (int)$t['id'] : null, [
        'approved_by' => $approver['username'] ?? null,
        'audit_action' => $t ? 'edit' : 'create',
    ]);
    $saved = Txn::load($id);
    flash('success', $pending
        ? Txn::ref($saved) . ' saved as pending. It does not affect inventory or the books until it is completed.'
        : Txn::ref($saved) . ' posted: inputs issued, outputs received at their allocated cost.');
    redirect_after_save(url($pageKey), url($pageKey, ['a' => 'view', 'id' => $id]));
};
if ($posted) {
    if (!empty($autoLoad)) {
        try {
            $runForm(true);
        } catch (UserError $e) {
            $GLOBALS['FORM_ERROR'] = $e->getMessage();
        }
    } else {
        handle_post(fn() => $runForm(false));
    }
}

if ($t && !$posted && $preview === null) {
    // editing: show the allocation with today's costs
    try {
        [, $e0, $a0] = Processing::orderLines($st['inputs'], $st['outputs'], $st['costs'], $st['alloc_method'] ?: Processing::defaultMethod($st['process_type']));
        $preview = [$e0, $a0];
    } catch (UserError $e) {
        $preview = null;
    }
}

// ---------------------------------------------------------------------- render form

$method = $st['alloc_method'] ?: Processing::defaultMethod($st['process_type']);
$inIds = array_column($st['inputs'], 'item_id');
$outIds = array_column($st['outputs'], 'item_id');
$inOpts = proc_item_opts(Processing::INPUT_TYPES, $inIds);
$outOpts = proc_item_opts(Processing::OUTPUT_TYPES, $outIds);
$siteId = (int)$st['site_id'];
[$est, $alloc] = $preview ?? [null, null];

$inRow = function ($i, array $r) use ($inOpts, $siteId, $showCosts, $est): string {
    $itemId = (int)($r['item_id'] ?? 0);
    $onHand = '';
    if ($itemId && $siteId && ($it = item_cache($itemId)) && Stock::isTracked($it['item_type'])) {
        $have = Stock::onHand($itemId, $siteId);
        $onHand = qty($have) . ($it['unit'] ? ' ' . h($it['unit']) : '');
        if ($have + 0.00001 < to_num($r['qty'] ?? 0)) {
            $onHand = '<span class="text-danger fw-semibold">' . $onHand . '</span>';
        }
    } elseif ($itemId) {
        $onHand = '<span class="text-muted small">not stocked</span>';
    }
    $e = is_int($i) && $est ? ($est['rows'][$i] ?? null) : null;
    $n = 'inputs[' . $i . ']';
    $html = '<tr><td class="col-item"><input type="hidden" name="' . $n . '[id]" value="' . h($r['id'] ?? '') . '">'
        . '<select class="form-select form-select-sm" name="' . $n . '[item_id]">' . options($inOpts, $itemId ?: null, '') . '</select></td>'
        . '<td class="col-desc"><input class="form-control form-control-sm" name="' . $n . '[description]" value="' . h($r['description'] ?? '') . '"></td>'
        . '<td class="col-sm num text-muted small">' . (($r['planned_qty'] ?? null) !== null && ($r['planned_qty'] ?? '') !== '' ? qty($r['planned_qty']) : '')
        . '<input type="hidden" name="' . $n . '[planned_qty]" value="' . h(($r['planned_qty'] ?? null) !== null ? (string)$r['planned_qty'] : '') . '"></td>'
        . '<td class="col-sm"><input class="form-control form-control-sm text-end" name="' . $n . '[qty]" value="' . h(proc_num_value($r['qty'] ?? '')) . '"></td>'
        . '<td class="num small" data-onhand>' . $onHand . '</td>';
    if ($showCosts) {
        // v29: an input with no unit cost can not be posted; it is marked here as soon as the cost is estimated
        $noCost = $e && $itemId && (float)$e['unit'] <= 0;
        $html .= '<td class="num small">' . ($noCost ? '<span class="text-danger fw-semibold" title="No unit cost or average unit cost: the order cannot be posted">no unit cost</span>' : ($e ? money($e['unit'], true) : ''))
            . '</td><td class="num small">' . ($e ? money($e['cost']) : '') . '</td>';
    }
    return $html . grid_row_buttons() . '</tr>';
};
$outRow = function ($i, array $r) use ($outOpts, $showCosts, $alloc): string {
    $n = 'outputs[' . $i . ']';
    $res = is_int($i) && $alloc ? ($alloc['rows'][$i] ?? null) : null;
    $basis = $r['alloc_basis'] ?? '';
    $qtyVal = to_num($r['qty'] ?? 0);
    // the allocated cost and the unit cost can be typed in; each one follows the other
    $allocVal = ($r['alloc_cost'] ?? '') !== '' ? to_num($r['alloc_cost']) : ($res['cost'] ?? (isset($r['amount']) ? (float)$r['amount'] : null));
    $unitVal = ($r['unit_cost'] ?? '') !== '' ? to_num($r['unit_cost'])
        : ($res['unit'] ?? ($allocVal !== null && $qtyVal > 0 ? round($allocVal / $qtyVal, 4) : null));
    $html = '<tr><td class="col-item"><input type="hidden" name="' . $n . '[id]" value="' . h($r['id'] ?? '') . '">'
        . '<select class="form-select form-select-sm" name="' . $n . '[item_id]">' . options($outOpts, ($r['item_id'] ?? '') ?: null, '') . '</select></td>'
        . '<td class="col-desc"><input class="form-control form-control-sm" name="' . $n . '[description]" value="' . h($r['description'] ?? '') . '"></td>'
        . '<td><select class="form-select form-select-sm" name="' . $n . '[output_role]" data-f="output_role" style="min-width:150px">' . options(Processing::ROLES, $r['output_role'] ?? 'co_product') . '</select></td>'
        . '<td class="col-sm num text-muted small">' . (($r['planned_qty'] ?? null) !== null && ($r['planned_qty'] ?? '') !== '' ? qty($r['planned_qty']) : '')
        . '<input type="hidden" name="' . $n . '[planned_qty]" value="' . h(($r['planned_qty'] ?? null) !== null ? (string)$r['planned_qty'] : '') . '"></td>'
        . '<td class="col-sm"><input class="form-control form-control-sm text-end" data-f="qty" name="' . $n . '[qty]" value="' . h(proc_num_value($r['qty'] ?? '')) . '"></td>'
        . '<td class="col-sm"><input class="form-control form-control-sm text-end" name="' . $n . '[alloc_basis]" value="' . h(is_numeric($basis) ? ((float)$basis != 0.0 ? qty($basis) : '') : (string)$basis) . '" placeholder="auto"></td>'
        . '<td class="num small" data-f="share">' . ($res ? number_format($res['pct'], 2) . '%' : '') . '</td>';
    if ($showCosts) {
        $html .= '<td class="col-num"><input class="form-control form-control-sm text-end" data-f="alloc_cost" name="' . $n . '[alloc_cost]"'
            . ' value="' . h($allocVal !== null ? number_format((float)$allocVal, 2, '.', '') : '') . '" placeholder="auto"></td>'
            . '<td class="col-num"><input class="form-control form-control-sm text-end' . ($res && ($r['output_role'] ?? 'co_product') !== 'scrap' && (float)($res['unit'] ?? 0) <= 0 && !empty($r['item_id']) ? ' is-invalid" title="Received at no cost: the order cannot be posted' : '') . '" data-f="unit_cost" name="' . $n . '[unit_cost]"'
            . ' value="' . h($unitVal !== null ? rtrim(rtrim(number_format((float)$unitVal, 4, '.', ''), '0'), '.') : '') . '" placeholder="auto"></td>';
    }
    return $html . grid_row_buttons() . '</tr>';
};
$costRow = function ($i, array $r) use ($accounts, $absorbedAcct): string {
    $n = 'costs[' . $i . ']';
    $amt = $r['amount'] ?? '';
    return '<tr><td class="col-item"><input type="hidden" name="' . $n . '[id]" value="' . h($r['id'] ?? '') . '">'
        . '<select class="form-select form-select-sm" name="' . $n . '[account_id]">' . options($accounts, ($r['account_id'] ?? '') ?: ($i === '__i__' ? $absorbedAcct : null), '') . '</select></td>'
        . '<td class="col-desc"><input class="form-control form-control-sm" name="' . $n . '[description]" value="' . h($r['description'] ?? '') . '" placeholder="e.g. Labor, machine time, overhead"></td>'
        . '<td class="col-num"><input class="form-control form-control-sm text-end" name="' . $n . '[amount]" value="' . h(is_numeric($amt) && (float)$amt != 0.0 ? number_format((float)$amt, 2, '.', '') : (is_numeric($amt) ? '' : $amt)) . '"></td>'
        . grid_row_buttons() . '</tr>';
};

$title = $t ? 'Edit ' . Txn::ref($t) : 'Disassemble / Process Items';
layout_start($title, ['actions' => btn_link(url($pageKey), 'Processing orders') . ' '
    . page_link('inv.formulas', [], 'Processing formulas', 'btn-outline-secondary', 'diagram-3')]);
$typeHelp = array_map(fn($x) => $x[1], Processing::TYPES);
$methodHelp = array_map(fn($x) => $x[1], Processing::METHODS);
?>
<form method="post" id="procForm"><?= csrf_field() ?>
<button type="submit" name="do" value="preview" class="d-none" tabindex="-1" aria-hidden="true"></button>
<?php if ($t && (int)$t['is_pending'] === 1): ?>
  <div class="alert alert-warning py-2">This order is <strong>pending</strong>. <em>Save and post</em> completes it; <em>Save as pending</em> keeps it off the books.</div>
<?php endif; ?>
<div class="card mb-3"><div class="card-body row g-2">
  <div class="col-md-3"><label class="form-label">Process type</label>
    <select class="form-select form-select-sm" name="process_type" id="typeSel"><?= options(array_map(fn($x) => $x[0], Processing::TYPES), $st['process_type']) ?></select>
    <div class="form-text" id="typeHelp"><?= h($typeHelp[$st['process_type']] ?? '') ?></div></div>
  <div class="col-md-5"><label class="form-label">Load lines from</label>
    <select class="form-select form-select-sm" name="source"><?= options(proc_sources(!empty($t['process_formula_id']) ? (int)$t['process_formula_id'] : null), $st['source'], '— none: enter the lines below —') ?></select></div>
  <div class="col-md-2"><label class="form-label">Batches / units</label>
    <input class="form-control form-control-sm text-end" name="batches" value="<?= h($st['batches']) ?>"></div>
  <div class="col-md-2 d-flex align-items-end"><button class="btn btn-sm btn-outline-primary w-100" name="do" value="load"><i class="bi bi-download"></i> Load lines</button></div>

  <?= input_field('txn_date', 'Date', $st['txn_date'], ['type' => 'date', 'class' => 'col-md-2']) ?>
  <?= select_field('site_id', 'Processing site (inputs)', $sites, $st['site_id'], ['class' => 'col-md-3']) ?>
  <?= select_field('site2_id', 'Receive outputs at', $sites, $st['site2_id'], ['class' => 'col-md-3', 'blank' => 'Same site']) ?>
  <?= input_field('ref_no', 'Reference no.', $st['ref_no'], ['class' => 'col-md-2']) ?>
  <div class="col-md-2 small text-muted d-flex align-items-end">No. <?= h($t['txn_no'] ?? Seq::peek('processing') ?: '(automatic)') ?></div>

  <div class="col-md-4"><label class="form-label">Cost allocation method</label>
    <select class="form-select form-select-sm" name="alloc_method" id="methodSel"><?= options(array_map(fn($x) => $x[0], Processing::METHODS), $method) ?></select>
    <div class="form-text" id="methodHelp"><?= h($methodHelp[$method] ?? '') ?></div></div>
  <div class="col-md-4" data-toggle-by="#methodSel" data-show-when="average_cost,manual"><label class="form-label">Variance account (difference)</label>
    <select class="form-select form-select-sm" name="account_id"><?= options($accounts, $st['account_id'], '— default —') ?></select></div>
  <?= input_field('memo', 'Memo', $st['memo'], ['class' => 'col-md-4']) ?>
  <?= Classes::field($st['class_id'], 'col-md-3') ?>
</div></div>

<div class="card mb-3">
  <div class="card-header d-flex justify-content-between"><span class="fw-semibold"><i class="bi bi-box-arrow-in-right"></i> Inputs (consumed)</span><span class="small text-muted">Stocked items are issued at their average cost. Services and non-stock items are charged at their standard cost.</span></div>
  <div class="table-responsive"><table class="table table-sm line-grid mb-1" id="inGrid" data-template="tpl-inGrid" data-total-group="proc-in" data-min-rows="1"
    data-stock-url="<?= h(url('api.lookup', ['what' => 'stock'])) ?>">
    <thead><tr><th>Item</th><th>Description</th><th class="num">Planned</th><th class="num">Qty</th><th class="num">On hand at site</th><?php if ($showCosts): ?><th class="num">Est. unit cost</th><th class="num">Est. cost</th><?php endif; ?><th></th></tr></thead>
    <tbody><?php foreach (array_values($st['inputs']) as $i => $r) { echo $inRow($i, $r); } ?></tbody>
    <tfoot>
      <tr class="fw-semibold grid-totals">
        <td colspan="3" class="text-end">Total inputs</td>
        <td class="num" id="inQtyTotal">0</td>
        <td></td>
        <?php if ($showCosts): ?><td></td><td class="num"><?= $est ? money($est['materials']) : '' ?></td><?php endif; ?>
        <td></td>
      </tr>
    </tfoot>
  </table></div>
  <template id="tpl-inGrid"><?= $inRow('__i__', []) ?></template>
  <div class="card-body py-2"><?= grid_add_buttons('inGrid', 'Add input', 'inputs') ?></div>
</div>

<div class="card mb-3">
  <div class="card-header d-flex justify-content-between"><span class="fw-semibold"><i class="bi bi-cash-coin"></i> Additional processing costs (optional)</span><span class="small text-muted">Labor, machine time or overhead added to the cost. The account is credited (absorbed).</span></div>
  <div class="table-responsive"><table class="table table-sm line-grid mb-1" id="costGrid" data-template="tpl-costGrid" data-total-group="proc-cost" data-min-rows="1">
    <thead><tr><th>Account credited</th><th>Description</th><th class="num">Amount</th><th></th></tr></thead>
    <tbody><?php foreach (array_values($st['costs']) as $i => $r) { echo $costRow($i, $r); } ?></tbody>
  </table></div>
  <template id="tpl-costGrid"><?= $costRow('__i__', []) ?></template>
  <div class="card-body py-2"><?= grid_add_buttons('costGrid', 'Add cost', 'costs') ?></div>
</div>

<div class="card mb-3">
  <div class="card-header d-flex justify-content-between"><span class="fw-semibold"><i class="bi bi-box-arrow-right"></i> Outputs (produced)</span><span class="small text-muted">Outputs must be inventory or assembly items.</span></div>
  <div class="table-responsive"><table class="table table-sm line-grid mb-1" id="outGrid" data-template="tpl-outGrid" data-total-group="proc-out" data-min-rows="2">
    <thead><tr><th>Item</th><th>Description</th><th>Role</th><th class="num">Planned</th><th class="num">Qty</th><th class="num">Basis</th><th class="num">Share of cost</th><?php if ($showCosts): ?><th class="num">Allocated cost</th><th class="num">Unit cost</th><?php endif; ?><th></th></tr></thead>
    <tbody><?php foreach (array_values($st['outputs']) as $i => $r) { echo $outRow($i, $r); } ?></tbody>
    <tfoot>
      <tr class="fw-semibold grid-totals">
        <td colspan="4" class="text-end">Running total</td>
        <td class="num" id="outQtyTotal">0</td>
        <td></td>
        <td class="num" id="outShareTotal"></td>
        <?php if ($showCosts): ?><td class="num" id="outAllocTotal">0.00</td><td></td><?php endif; ?>
        <td></td>
      </tr>
      <tr class="fw-semibold proc-qty-var">
        <td colspan="4" class="text-end">Variance qty (inputs less outputs)</td>
        <td class="num" id="qtyVariance">0</td>
        <td colspan="<?= $showCosts ? 5 : 3 ?>"></td>
      </tr>
      <?php if ($showCosts): ?>
      <tr class="small text-muted" id="outAllocCompare" <?= $alloc ? '' : 'hidden' ?> data-pool="<?= h($alloc ? number_format((float)$alloc['pool'], 2, '.', '') : '') ?>">
        <td colspan="7" class="text-end">Total cost to allocate <strong id="outPool"><?= $alloc ? money($alloc['pool']) : '' ?></strong>, difference from the allocated total</td>
        <td class="num fw-semibold" id="outAllocDiff"></td><td colspan="2"></td>
      </tr>
      <?php endif; ?>
    </tfoot>
  </table></div>
  <template id="tpl-outGrid"><?= $outRow('__i__', []) ?></template>
  <div class="card-body py-2">
    <?= grid_add_buttons('outGrid', 'Add output', 'outputs') ?>
    <div class="small text-muted mt-2"><strong>Basis</strong> depends on the method:
      relative sales value = price per unit (blank uses the item's selling price);
      relative standard cost = cost per unit (blank uses the item's cost);
      weight / measure = weight or volume per unit;
      fixed percentages = % of the cost;
      current average cost = value per unit (blank uses the average cost);
      manual = total cost of the line.
      For <strong>by-products and scrap</strong> the basis is always their value per unit; it is taken out of the cost first (scrap is usually 0).</div>
    <?php if ($showCosts): ?>
    <div class="small text-muted mt-1"><strong>Allocated cost</strong> and <strong>Unit cost</strong> can be typed in: entering one works out the other from the quantity.
      Typing a cost on a co-product switches the method to <em>Manual amounts</em>, so the figures you entered are the ones posted and any difference from the total cost goes to the variance account.
      On a by-product or scrap line the unit cost you type is its value per unit.</div>
    <?php endif; ?>
  </div>
</div>

<?php if ($est && $alloc && $showCosts): ?>
<div class="card mb-3 border-primary"><div class="card-header fw-semibold">Cost allocation preview <span class="small text-muted fw-normal">(estimated with today's average costs; posting uses the average cost at the time of posting)</span></div>
  <div class="card-body"><table class="table table-sm mb-0 w-auto">
    <tr><td>Input materials</td><td class="num"><?= money($est['materials']) ?></td></tr>
    <tr><td>Additional processing costs</td><td class="num"><?= money($est['extra']) ?></td></tr>
    <tr class="fw-semibold"><td>Total cost to allocate</td><td class="num"><?= money($alloc['pool']) ?></td></tr>
    <tr><td>By-products and scrap (fixed values)</td><td class="num"><?= money($alloc['fixed']) ?></td></tr>
    <tr><td>Allocated to outputs</td><td class="num"><?= money($alloc['allocated']) ?></td></tr>
    <?php if (Processing::isValueMethod($method)): ?><tr class="<?= abs($alloc['variance']) >= 0.005 ? 'table-warning' : '' ?>"><td>Variance posted (loss / (gain))</td><td class="num"><?= money($alloc['variance']) ?></td></tr><?php endif; ?>
  </table></div></div>
<?php elseif ($est && $alloc): ?>
  <div class="alert alert-info py-2">Allocation checked: the shares are shown on the output lines.</div>
<?php endif; ?>

<?= Approval::fields() ?>
<div class="sticky-actions d-flex gap-2 mt-3">
  <button class="btn btn-outline-primary" name="do" value="preview"><i class="bi bi-calculator"></i> Preview allocation</button>
  <button class="btn btn-primary" name="do" value="save"><i class="bi bi-check-lg"></i> Save and post</button>
  <?php if (Access::can($pageKey, 'create')): ?><input type="hidden" name="save_new" value=""><button class="btn btn-outline-primary" name="do" value="save" title="Post this one and start another with empty fields"
    onclick="this.form.save_new.value='1'"><i class="bi bi-plus-lg"></i> Save &amp; New</button><?php endif; ?>
  <button class="btn btn-outline-warning" name="do" value="pending"><i class="bi bi-hourglass-split"></i> Save as pending</button>
  <a class="btn btn-outline-secondary" href="<?= h($t ? Txn::url($t) : url($pageKey)) ?>">Cancel</a>
</div>
</form>
<script>
(function () {
  const { num, r2, fmt } = window.AppFmt;
  const typeHelp = <?= json_encode($typeHelp) ?>, methodHelp = <?= json_encode($methodHelp) ?>;
  const ts = document.getElementById('typeSel'), ms = document.getElementById('methodSel');
  ts.addEventListener('change', () => { document.getElementById('typeHelp').textContent = typeHelp[ts.value] || ''; });
  ms.addEventListener('change', () => { document.getElementById('methodHelp').textContent = methodHelp[ms.value] || ''; });

  // ---------------------------------------------------------------- quantity on hand at the processing site
  const inGrid = document.getElementById('inGrid');
  const siteSel = document.querySelector('[name="site_id"]');
  const stockUrl = inGrid ? inGrid.dataset.stockUrl : '';
  const stockCache = {};

  function showOnHand(tr) {
    const cell = tr.querySelector('[data-onhand]');
    const item = tr.querySelector('select[name$="[item_id]"]');
    const qtyF = tr.querySelector('input[name$="[qty]"]');
    if (!cell || !item || !stockUrl) return;
    if (!item.value || !siteSel || !siteSel.value) { cell.textContent = ''; return; }
    const key = item.value + ':' + siteSel.value;
    const paint = (d) => {
      if (!d) { cell.textContent = ''; return; }
      if (!d.tracked) { cell.innerHTML = '<span class="text-muted small">not stocked</span>'; return; }
      const have = num(d.on_hand), want = qtyF ? num(qtyF.value) : 0;
      cell.textContent = fmt(have) + (d.unit ? ' ' + d.unit : '');
      cell.classList.toggle('text-danger', have + 0.00001 < want);
      cell.classList.toggle('fw-semibold', have + 0.00001 < want);
    };
    if (stockCache[key]) { paint(stockCache[key]); return; }
    cell.innerHTML = '<span class="text-muted small">...</span>';
    fetch(stockUrl + '&item=' + encodeURIComponent(item.value) + '&site=' + encodeURIComponent(siteSel.value))
      .then((r) => r.json())
      .then((d) => { stockCache[key] = d; paint(d); })
      .catch(() => { cell.textContent = ''; });
  }

  function refreshInputs() {
    if (!inGrid) return;
    inGrid.querySelectorAll('tbody tr').forEach(showOnHand);
  }
  if (inGrid) {
    inGrid.addEventListener('change', (e) => {
      const tr = e.target.closest('tr');
      if (tr && e.target.name && /\[(item_id|qty)\]$/.test(e.target.name)) showOnHand(tr);
    });
    inGrid.addEventListener('input', (e) => {
      const tr = e.target.closest('tr');
      if (tr && e.target.name && /\[qty\]$/.test(e.target.name)) showOnHand(tr);
    });
    // rows added after the page loaded
    new MutationObserver((ms2) => ms2.forEach((m) => m.addedNodes.forEach((n) => { if (n.nodeType === 1 && n.tagName === 'TR') showOnHand(n); })))
      .observe(inGrid.querySelector('tbody'), { childList: true });
  }
  if (siteSel) siteSel.addEventListener('change', refreshInputs);
  refreshInputs();

  // ---------------------------------------------------------------- input quantity, and the variance qty (inputs less outputs)
  const q4 = (v) => String(Math.round(v * 10000) / 10000);
  function qtySum(grid) {
    let s = 0;
    if (grid) grid.querySelectorAll('tbody input[name$="[qty]"]').forEach((i) => { s += num(i.value); });
    return s;
  }
  function qtyVariance() {
    const inQ = qtySum(inGrid), outQ = qtySum(document.getElementById('outGrid'));
    const a = document.getElementById('inQtyTotal'); if (a) a.textContent = q4(inQ);
    const v = document.getElementById('qtyVariance');
    if (v) {
      const d = Math.round((inQ - outQ) * 10000) / 10000;
      v.textContent = q4(d);
      v.classList.toggle('text-danger', d > 0);
      v.classList.toggle('text-success', d < 0);
    }
  }
  if (inGrid) {
    inGrid.addEventListener('input', qtyVariance);
    inGrid.addEventListener('change', qtyVariance);
    new MutationObserver(qtyVariance).observe(inGrid.querySelector('tbody'), { childList: true });
  }
  qtyVariance();

  // ---------------------------------------------------------------- allocated cost <-> unit cost
  const outGrid = document.getElementById('outGrid');
  function cell(tr, f) { return tr.querySelector('[data-f="' + f + '"]'); }

  function useManual(tr) {
    // a cost typed on a co-product is only posted under "Manual amounts"
    const role = cell(tr, 'output_role');
    if (!ms || !role || role.value !== 'co_product' || ms.value === 'manual') return;
    ms.value = 'manual';
    ms.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function fromAllocated(tr) {
    const q = num(cell(tr, 'qty') ? cell(tr, 'qty').value : 0);
    const a = cell(tr, 'alloc_cost'), u = cell(tr, 'unit_cost');
    if (!a || !u) return;
    if (a.value === '') { u.value = ''; return; }
    u.value = q > 0 ? String(Math.round((num(a.value) / q) * 10000) / 10000) : '';
  }

  function fromUnit(tr) {
    const q = num(cell(tr, 'qty') ? cell(tr, 'qty').value : 0);
    const a = cell(tr, 'alloc_cost'), u = cell(tr, 'unit_cost');
    if (!a || !u) return;
    if (u.value === '') { a.value = ''; return; }
    a.value = r2(num(u.value) * q).toFixed(2);
  }

  function shares() {
    if (!outGrid) return;
    let total = 0, qtyTotal = 0, filled = 0;
    const rows = Array.from(outGrid.querySelectorAll('tbody tr'));
    rows.forEach((tr) => {
      const a = cell(tr, 'alloc_cost'), q = cell(tr, 'qty');
      if (a) { total += num(a.value); if (a.value !== '') filled++; }
      if (q) qtyTotal += num(q.value);
    });
    rows.forEach((tr) => {
      const a = cell(tr, 'alloc_cost'), sh = cell(tr, 'share');
      if (a && sh) sh.textContent = total > 0 && a.value !== '' ? (num(a.value) / total * 100).toFixed(2) + '%' : '';
    });
    totals(r2(total), qtyTotal, filled);
  }

  // running total under the Allocated cost column: follows every change typed in the grid
  function totals(total, qtyTotal, filled) {
    const set = (id, text) => { const el = document.getElementById(id); if (el) el.textContent = text; };
    set('outQtyTotal', String(Math.round(qtyTotal * 10000) / 10000));
    qtyVariance();
    set('outAllocTotal', fmt(total));
    set('outShareTotal', filled && total > 0 ? '100.00%' : '');
    const cmp = document.getElementById('outAllocCompare');
    if (cmp && !cmp.hidden && cmp.dataset.pool !== '') {
      const diff = r2(num(cmp.dataset.pool) - total);
      const cellDiff = document.getElementById('outAllocDiff');
      if (cellDiff) {
        cellDiff.textContent = fmt(diff);
        cellDiff.classList.toggle('text-danger', Math.abs(diff) >= 0.005);
        cellDiff.classList.toggle('text-success', Math.abs(diff) < 0.005);
      }
    }
  }

  if (outGrid) {
    const handler = (e) => {
      const tr = e.target.closest('tr');
      if (!tr) return;
      const f = e.target.dataset.f;
      if (f === 'alloc_cost') { fromAllocated(tr); useManual(tr); shares(); }
      else if (f === 'unit_cost') { fromUnit(tr); useManual(tr); shares(); }
      else if (f === 'qty') { if (cell(tr, 'unit_cost') && cell(tr, 'unit_cost').value !== '') { fromUnit(tr); } else { fromAllocated(tr); } shares(); }
    };
    outGrid.addEventListener('input', handler);
    outGrid.addEventListener('change', handler);
    // removing or adding a line changes the total as well
    new MutationObserver(() => shares()).observe(outGrid.querySelector('tbody'), { childList: true });
    // first paint: totals only, the shares already on the page come from the allocation itself
    (function () {
      let total = 0, qtyTotal = 0, filled = 0;
      outGrid.querySelectorAll('tbody tr').forEach((tr) => {
        const a = cell(tr, 'alloc_cost'), q = cell(tr, 'qty');
        if (a) { total += num(a.value); if (a.value !== '') filled++; }
        if (q) qtyTotal += num(q.value);
      });
      totals(r2(total), qtyTotal, filled);
    })();
  }
})();
</script>
<?php
layout_end();
return;

// ---------------------------------------------------------------------- list

function proc_list(string $pageKey): void
{
    $from = gdate('from', date('Y-m-d', strtotime('-90 days')));
    $to = gdate('to', date('Y-m-d', strtotime('+30 days')));
    $status = g('status');
    $ptype = g('ptype');
    $q = g('q');
    $where = ["t.txn_type = 'item_processing'", 't.txn_date BETWEEN :f AND :t'];
    $params = ['f' => $from, 't' => $to];
    if ($status === 'pending') {
        $where[] = 't.is_pending = 1 AND t.is_voided = 0';
    } elseif ($status !== '') {
        $where[] = 't.status = :st';
        $params['st'] = $status;
    }
    if ($ptype !== '' && isset(Processing::TYPES[$ptype])) {
        $where[] = 't.process_type = :pt';
        $params['pt'] = $ptype;
    }
    if ($q !== '') {
        $where[] = '(t.txn_no LIKE :q OR t.ref_no LIKE :q OR t.memo LIKE :q OR pf.code LIKE :q OR pf.name LIKE :q
                     OR EXISTS (SELECT 1 FROM transaction_lines ql JOIN items qi ON qi.id = ql.item_id WHERE ql.txn_id = t.id AND (qi.code LIKE :q OR qi.name LIKE :q)))';
        $params['q'] = "%$q%";
    }
    [$sf, $sp] = Access::siteFilter('t.site_id');
    $rows = DB::all(
        'SELECT t.*, pf.code AS formula_code, s.name AS site_name, u.username
           FROM transactions t
           LEFT JOIN process_formulas pf ON pf.id = t.process_formula_id
           LEFT JOIN sites s ON s.id = t.site_id
           LEFT JOIN users u ON u.id = t.created_by
          WHERE ' . implode(' AND ', $where) . $sf . '
          ORDER BY t.txn_date DESC, t.id DESC LIMIT 500',
        $params + $sp
    );
    $summ = [];
    if ($rows) {
        [$in, $p] = DB::in('id', array_column($rows, 'id'));
        foreach (DB::all("SELECT l.txn_id, l.line_kind, l.qty, i.code FROM transaction_lines l JOIN items i ON i.id = l.item_id
                           WHERE l.txn_id IN $in AND l.line_kind IN ('input','output') ORDER BY l.line_no", $p) as $l) {
            $summ[(int)$l['txn_id']][$l['line_kind']][] = qty($l['qty']) . ' ' . $l['code'];
            $summ[(int)$l['txn_id']][$l['line_kind'] . '_qty'] = ($summ[(int)$l['txn_id']][$l['line_kind'] . '_qty'] ?? 0) + (float)$l['qty'];
        }
    }
    $showCosts = Access::viewCosts();
    if (g('export') === 'csv' && Access::can($pageKey, 'print')) {
        Audit::log('export', 'inv', 'list', null, 'Processing orders', ['page_key' => $pageKey]);
        $head = ['Date', 'Number', 'Type', 'Formula', 'Site', 'Inputs', 'Outputs', 'Variance qty', 'Status'];
        if ($showCosts) {
            $head[] = 'Total cost';
        }
        csv_download('processing_orders.csv', $head, array_map(function ($r) use ($summ, $showCosts) {
            $row = [$r['txn_date'], $r['txn_no'], Processing::typeLabel($r['process_type']), $r['formula_code'], $r['site_name'],
                implode(', ', $summ[(int)$r['id']]['input'] ?? []), implode(', ', $summ[(int)$r['id']]['output'] ?? []),
                round(($summ[(int)$r['id']]['input_qty'] ?? 0) - ($summ[(int)$r['id']]['output_qty'] ?? 0), 4),
                (int)$r['is_pending'] ? 'pending' : $r['status']];
            if ($showCosts) {
                $row[] = $r['total'];
            }
            return $row;
        }, $rows));
    }
    $actions = page_link($pageKey, ['a' => 'new'], 'New processing order', 'btn-primary', 'plus-lg', 'create') . ' '
        . page_link('inv.formulas', [], 'Formulas', 'btn-outline-secondary', 'diagram-3') . ' '
        . page_link('rpt.processing_register', [], 'Register', 'btn-outline-secondary', 'bar-chart');
    if (Access::can($pageKey, 'print')) {
        $actions .= ' ' . btn_link(current_url(['export' => 'csv']), 'Export', 'btn-outline-secondary', 'download');
    }
    layout_start('Processing Orders', ['actions' => $actions]);
    ?>
    <p class="text-muted small mb-2">Disassembly (unbuild), de-kitting / break-bulk, and co-product / by-product processing. Each order takes items out of stock, puts the resulting items in, and spreads the cost over them.</p>
    <form class="row g-2 mb-3 filters" method="get">
      <input type="hidden" name="p" value="<?= h($pageKey) ?>">
      <div class="col-auto"><label class="form-label">From</label><input class="form-control form-control-sm" type="date" name="from" value="<?= h($from) ?>"></div>
      <div class="col-auto"><label class="form-label">To</label><input class="form-control form-control-sm" type="date" name="to" value="<?= h($to) ?>"></div>
      <div class="col-auto"><label class="form-label">Type</label><select class="form-select form-select-sm" name="ptype"><?= options(array_map(fn($x) => $x[0], Processing::TYPES), $ptype, 'All') ?></select></div>
      <div class="col-auto"><label class="form-label">Status</label><select class="form-select form-select-sm" name="status"><?= options(['completed' => 'Completed', 'pending' => 'Pending', 'void' => 'Void'], $status, 'All') ?></select></div>
      <div class="col-auto"><label class="form-label">Search</label><input class="form-control form-control-sm" name="q" value="<?= h($q) ?>" placeholder="No., item, formula, memo"></div>
      <div class="col-auto d-flex align-items-end"><button class="btn btn-sm btn-secondary">Filter</button></div>
    </form>
    <div class="card"><div class="table-responsive">
    <table class="table table-sm table-hover mb-0">
      <thead><tr><th>Date</th><th>No.</th><th>Type</th><th>Formula</th><th>Site</th><th>Inputs</th><th>Outputs</th><th class="num">Variance qty</th><?php if ($showCosts): ?><th class="num">Total cost</th><?php endif; ?><th>Status</th><th>By</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="11" class="text-muted text-center py-3">No processing orders in this period.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr class="<?= (int)$r['is_voided'] ? 'text-muted text-decoration-line-through' : '' ?>">
          <td><?= h(fdate($r['txn_date'])) ?></td>
          <td><a href="<?= h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) ?>"><?= h($r['txn_no'] ?: '#' . $r['id']) ?></a></td>
          <td><?= proc_type_badge($r['process_type']) ?></td>
          <td class="small"><?= h($r['formula_code']) ?></td>
          <td class="small"><?= h($r['site_name']) ?></td>
          <td class="small"><?= h(mb_strimwidth(implode(', ', $summ[(int)$r['id']]['input'] ?? []), 0, 60, '…')) ?></td>
          <td class="small"><?= h(mb_strimwidth(implode(', ', $summ[(int)$r['id']]['output'] ?? []), 0, 70, '…')) ?></td>
          <td class="num"><?= proc_qty_variance((float)($summ[(int)$r['id']]['input_qty'] ?? 0), (float)($summ[(int)$r['id']]['output_qty'] ?? 0)) ?></td>
          <?php if ($showCosts): ?><td class="num"><?= money($r['total']) ?></td><?php endif; ?>
          <td><?= status_badge((int)$r['is_pending'] && !(int)$r['is_voided'] ? 'pending' : $r['status']) ?></td>
          <td class="small text-muted"><?= h($r['username']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div></div>
    <?php
    layout_end();
}

// ---------------------------------------------------------------------- view

function proc_view(array $t, string $pageKey): void
{
    $id = (int)$t['id'];
    [$in, $out, $cs] = proc_split($id);
    $voided = (int)$t['is_voided'] === 1;
    $pending = (int)$t['is_pending'] === 1;
    $showCosts = Access::viewCosts();
    $formula = $t['process_formula_id'] ? Processing::formula((int)$t['process_formula_id']) : null;

    $actions = [];
    if (!$voided && Access::can($pageKey, 'edit')) {
        $actions[] = btn_link(url($pageKey, ['a' => 'edit', 'id' => $id]), 'Edit', 'btn-primary', 'pencil');
    }
    if (Access::can($pageKey, 'create')) {
        $again = $formula ? ['a' => 'new', 'source' => 'f:' . $formula['id'], 'batches' => qty($t['process_batches'] ?? 1), 'site_id' => $t['site_id']] : null;
        if (!$again && count($in) === 1 && ($in[0]['item_type'] ?? '') === 'assembly') {
            $again = ['a' => 'new', 'source' => 'bom:' . $in[0]['item_id'], 'batches' => qty($in[0]['qty']), 'site_id' => $t['site_id']];
        }
        if ($again) {
            $actions[] = btn_link(url($pageKey, $again), 'Run again', 'btn-outline-success', 'arrow-repeat');
        }
    }
    if (Access::can($pageKey, 'print')) {
        $actions[] = btn_link(Txn::url($t, 'print'), 'Print', 'btn-outline-secondary', 'printer');
    }
    if (Access::can($pageKey, 'delete')) {
        if (!$voided) {
            $actions[] = '<button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#voidModal"><i class="bi bi-slash-circle"></i> Void</button>';
        }
        $actions[] = '<button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal"><i class="bi bi-trash"></i> Delete</button>';
    }
    if (Access::page('admin.txn_history')) {
        $actions[] = btn_link(url('admin.txn_history', ['id' => $id]), 'History', 'btn-outline-info', 'clock-history');
    }
    $actions[] = btn_link(url($pageKey), 'Back to list', 'btn-outline-secondary');

    layout_start(Txn::ref($t), ['actions' => implode(' ', $actions)]);
    if ($voided) {
        echo '<div class="alert alert-dark"><strong>VOID</strong> — ' . h($t['void_reason']) . ' (' . h(fdatetime($t['voided_at'])) . ')</div>';
    }
    if ($pending && !$voided) {
        echo '<div class="alert alert-warning">This processing order is <strong>pending</strong>. It does not affect inventory or the books until it is completed. Amounts below are estimates.</div>';
        if (Access::can($pageKey, 'edit')) {
            echo '<form method="post" class="card card-body border-warning mb-3" style="max-width:640px" action="' . h(url($pageKey, ['a' => 'finalize', 'id' => $id])) . '">'
                . csrf_field() . '<p class="small mb-2">When the inputs are available at the processing site, complete the order here.</p>'
                . '<div class="row g-2 align-items-end"><div class="col-auto"><label class="form-label">Processing date</label><input class="form-control form-control-sm" type="date" name="txn_date" value="' . h(today()) . '"></div>'
                . '<div class="col-auto"><button class="btn btn-warning btn-sm"><i class="bi bi-check2-circle"></i> Complete and post</button></div></div>'
                . Approval::fields() . '</form>';
        }
    }
    $sites = DB::pairs('SELECT id, name FROM sites');
    $info = [
        'Date' => h(fdate($t['txn_date'])),
        'Number' => h($t['txn_no']),
        'Status' => status_badge($pending && !$voided ? 'pending' : $t['status']),
        'Process type' => proc_type_badge($t['process_type']),
        'Allocation method' => h(Processing::methodLabel($t['alloc_method'])),
        'Formula' => $formula ? safe_link('inv.formulas', ['a' => 'edit', 'id' => $formula['id']], $formula['code'] . ' - ' . $formula['name']) . ($t['process_batches'] !== null ? ' × ' . h(qty($t['process_batches'])) : '') : '<span class="text-muted">—</span>',
        'Processing site' => h($sites[$t['site_id']] ?? ''),
        'Outputs received at' => h($sites[$t['site2_id'] ?: $t['site_id']] ?? ''),
    ];
    if ($t['ref_no']) {
        $info['Reference'] = h($t['ref_no']);
    }
    if (!empty($t['class_id']) && Classes::ready()) {
        $info['Class'] = h(Classes::name((int)$t['class_id']));
    }
    if ($t['account_id'] && Processing::isValueMethod($t['alloc_method'])) {
        $info['Variance account'] = h(account_label((int)$t['account_id']));
    }
    if ($t['memo']) {
        $info['Memo'] = h($t['memo']);
    }
    $info['Entered by'] = h(DB::value('SELECT full_name FROM users WHERE id = :id', ['id' => $t['created_by']])) . ', ' . h(fdatetime($t['created_at']));
    if ($t['approved_by']) {
        $info['Approved by'] = h(DB::value('SELECT full_name FROM users WHERE id = :id', ['id' => $t['approved_by']]));
    }
    $materials = array_sum(array_map(fn($l) => (float)$l['amount'], $in));
    $extra = array_sum(array_map(fn($l) => (float)$l['amount'], $cs));
    $pool = $materials + $extra;
    $fixed = array_sum(array_map(fn($l) => ($l['output_role'] ?: 'co_product') !== 'co_product' ? (float)$l['amount'] : 0.0, $out));
    $allocated = array_sum(array_map(fn($l) => (float)$l['amount'], $out));
    $variance = r2($pool - $allocated);
    $inQty = array_sum(array_map(fn($l) => (float)$l['qty'], $in));
    $outQty = array_sum(array_map(fn($l) => (float)$l['qty'], $out));
    $qtyVar = round($inQty - $outQty, 4);
    ?>
    <div class="card mb-3"><div class="card-body"><div class="row g-2">
      <?php foreach ($info as $k => $v): ?><div class="col-sm-6 col-md-3"><div class="small text-muted"><?= h($k) ?></div><div><?= $v ?></div></div><?php endforeach; ?>
    </div></div></div>

    <div class="row g-3">
    <div class="col-xl-9">
    <div class="card mb-3"><div class="card-header fw-semibold"><i class="bi bi-box-arrow-in-right"></i> Inputs (consumed)</div>
      <div class="table-responsive"><table class="table table-sm mb-0">
        <thead><tr><th>Item</th><th>Description</th><th class="num">Planned</th><th class="num">Qty</th><th>Unit</th><?php if ($showCosts): ?><th class="num">Unit cost</th><th class="num">Cost</th><?php endif; ?></tr></thead>
        <?php foreach ($in as $l): ?>
          <tr><td><?= safe_link('inv.center', ['id' => $l['item_id']], $l['item_code']) ?></td><td><?= h($l['description']) ?></td>
            <td class="num text-muted"><?= $l['planned_qty'] !== null ? qty($l['planned_qty']) : '' ?></td><td class="num"><?= qty($l['qty']) ?></td><td class="small"><?= h($l['item_unit']) ?></td>
            <?php if ($showCosts): ?><td class="num"><?= money(proc_unit_cost($l), true) ?></td><td class="num"><?= money($l['amount']) ?></td><?php endif; ?></tr>
        <?php endforeach; ?>
        <tr class="total-row"><td colspan="3">Total inputs</td><td class="num"><?= qty($inQty) ?></td><td></td>
          <?php if ($showCosts): ?><td></td><td class="num"><?= money($materials) ?></td><?php endif; ?></tr>
      </table></div></div>

    <?php if ($cs): ?>
    <div class="card mb-3"><div class="card-header fw-semibold"><i class="bi bi-cash-coin"></i> Additional processing costs</div>
      <table class="table table-sm mb-0"><thead><tr><th>Account credited</th><th>Description</th><?php if ($showCosts): ?><th class="num">Amount</th><?php endif; ?></tr></thead>
        <?php foreach ($cs as $l): ?><tr><td><?= h($l['account_code'] . ' - ' . $l['account_name']) ?></td><td><?= h($l['description']) ?></td><?php if ($showCosts): ?><td class="num"><?= money($l['amount']) ?></td><?php endif; ?></tr><?php endforeach; ?>
      </table></div>
    <?php endif; ?>

    <div class="card mb-3"><div class="card-header fw-semibold"><i class="bi bi-box-arrow-right"></i> Outputs (produced) and cost allocation</div>
      <div class="table-responsive"><table class="table table-sm table-tight mb-0">
        <thead><tr><th>Item</th><th>Description</th><th>Role</th><th class="num">Planned</th><th class="num">Qty</th><th class="num">Yield</th><th class="num">Basis</th><th class="num">Share of cost</th>
          <?php if ($showCosts): ?><th class="num">Allocated cost</th><th class="num">Unit cost</th><?php endif; ?></tr></thead>
        <?php foreach ($out as $l): $yield = $l['planned_qty'] !== null && (float)$l['planned_qty'] > 0 ? (float)$l['qty'] / (float)$l['planned_qty'] * 100 : null; ?>
          <tr><td class="wrap-link"><?= safe_link('inv.center', ['id' => $l['item_id']], $l['item_code']) ?></td><td class="small"><?= h($l['description']) ?></td><td><?= proc_role_badge($l['output_role']) ?></td>
            <td class="num text-muted"><?= $l['planned_qty'] !== null ? qty($l['planned_qty']) : '' ?></td><td class="num"><?= qty($l['qty']) ?> <span class="small text-muted"><?= h($l['item_unit']) ?></span></td>
            <td class="num <?= $yield !== null && abs($yield - 100) >= 0.05 ? ($yield < 100 ? 'text-danger' : 'text-success') : '' ?>"><?= $yield !== null ? number_format($yield, 1) . '%' : '' ?></td>
            <td class="num small"><?= (float)$l['alloc_basis'] != 0.0 ? qty($l['alloc_basis']) : '<span class="text-muted">auto</span>' ?></td>
            <td class="num"><?= number_format((float)$l['alloc_pct'], 2) ?>%</td>
            <?php if ($showCosts): ?><td class="num"><?= money($l['amount']) ?></td><td class="num"><?= money($l['rate'], true) ?></td><?php endif; ?></tr>
        <?php endforeach; ?>
        <tr class="total-row"><td colspan="4">Total outputs</td><td class="num"><?= qty($outQty) ?></td><td></td><td></td>
          <td class="num"><?= number_format(array_sum(array_map(fn($l) => (float)$l['alloc_pct'], $out)), 2) ?>%</td>
          <?php if ($showCosts): ?><td class="num"><?= money($allocated) ?></td><td></td><?php endif; ?></tr>
        <tr class="fw-semibold proc-qty-var"><td colspan="4">Variance qty: inputs (<?= qty($inQty) ?>) less outputs (<?= qty($outQty) ?>)</td>
          <td class="num <?= abs($qtyVar) >= 0.00005 ? ($qtyVar > 0 ? 'text-danger' : 'text-success') : '' ?>"><?= proc_qty_variance($inQty, $outQty) ?></td><td colspan="<?= $showCosts ? 5 : 3 ?>"></td></tr>
        <?php if ($showCosts): ?>
        <tr class="<?= abs($variance) >= 0.005 ? 'table-warning' : '' ?> fw-semibold"><td colspan="8">Variance: inputs<?= $extra ? ' and additional costs' : '' ?> (<?= money($pool) ?>) less outputs (<?= money($allocated) ?>)</td>
          <td class="num"><?= money($variance) ?></td><td></td></tr>
        <?php endif; ?>
      </table></div></div>

    <?php if ($showCosts && !$voided && !$pending): $gl = DB::all('SELECT j.*, a.code, a.name FROM journal_lines j JOIN accounts a ON a.id = j.account_id WHERE j.txn_id = :id ORDER BY j.id', ['id' => $id]); if ($gl): ?>
    <div class="card mb-3"><div class="card-header small fw-semibold">General ledger entries created automatically</div>
      <table class="table table-sm mb-0 small"><thead><tr><th>Account</th><th>Memo</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead>
      <?php $sd = 0; $sc = 0; foreach ($gl as $g): $sd += $g['debit']; $sc += $g['credit']; ?>
        <tr><td><?= h($g['code'] . ' - ' . $g['name']) ?></td><td><?= h($g['memo']) ?></td><td class="num"><?= money($g['debit'], true) ?></td><td class="num"><?= money($g['credit'], true) ?></td></tr>
      <?php endforeach; ?>
      <tr class="fw-semibold"><td colspan="2">Total</td><td class="num"><?= money($sd) ?></td><td class="num"><?= money($sc) ?></td></tr>
      </table></div>
    <?php endif; endif; ?>
    </div>

    <div class="col-xl-3">
      <div class="card mb-3"><div class="card-header small fw-semibold">Quantity summary</div><div class="card-body">
        <table class="table table-sm mb-0 totals-box">
          <tr><td>Input quantity</td><td class="num"><?= qty($inQty) ?></td></tr>
          <tr><td>Output quantity</td><td class="num"><?= qty($outQty) ?></td></tr>
          <tr class="fw-semibold <?= abs($qtyVar) >= 0.00005 ? 'table-warning' : '' ?>"><td>Variance qty (inputs less outputs)</td><td class="num"><?= proc_qty_variance($inQty, $outQty) ?></td></tr>
        </table>
        <?php if (count(array_unique(array_filter(array_map(fn($l) => mb_strtolower(trim((string)$l['item_unit'])), array_merge($in, $out))))) > 1): ?>
        <div class="small text-muted mt-2">The lines use more than one unit of measure; the quantities are added as entered.</div>
        <?php endif; ?>
      </div></div>
      <?php if ($showCosts): ?>
      <div class="card mb-3"><div class="card-header small fw-semibold">Cost summary<?= $pending ? ' (estimate)' : '' ?></div><div class="card-body">
        <table class="table table-sm mb-0 totals-box">
          <tr><td>Input materials</td><td class="num"><?= money($materials) ?></td></tr>
          <tr><td>Additional costs</td><td class="num"><?= money($extra) ?></td></tr>
          <tr class="fw-semibold"><td>Total cost</td><td class="num"><?= money($pool) ?></td></tr>
          <tr><td>By-products &amp; scrap</td><td class="num"><?= money($fixed) ?></td></tr>
          <tr><td>Co-products</td><td class="num"><?= money($allocated - $fixed) ?></td></tr>
          <tr class="<?= abs($variance) >= 0.005 ? 'table-warning' : '' ?>"><td>Variance</td><td class="num"><?= money($variance) ?></td></tr>
        </table>
      </div></div>
      <?php endif; ?>
      <div class="card mb-3"><div class="card-body small text-muted">
        <?= h(Processing::TYPES[$t['process_type']][1] ?? '') ?><br><br>
        <strong><?= h(Processing::methodLabel($t['alloc_method'])) ?>:</strong> <?= h(Processing::METHODS[$t['alloc_method']][1] ?? '') ?>
      </div></div>
    </div>
    </div>
    <?php
    if (Access::can($pageKey, 'delete')) {
        echo reason_modal('voidModal', 'Void processing order', Txn::url($t, 'void'), 'btn-warning',
            '<p class="small text-muted mt-2">Voiding keeps the document but takes the outputs back out of stock and returns the inputs.</p>');
        echo reason_modal('deleteModal', 'Delete processing order', Txn::url($t, 'delete'), 'btn-danger',
            '<p class="small text-muted mt-2">The document is removed; its full content stays in the audit trail.</p>');
    }
    layout_end();
}

// ---------------------------------------------------------------------- print

function proc_print(array $t): void
{
    [$in, $out, $cs] = proc_split((int)$t['id']);
    $s = Settings::all();
    $showCosts = Access::viewCosts();
    $sites = DB::pairs('SELECT id, name FROM sites');
    $formula = $t['process_formula_id'] ? Processing::formula((int)$t['process_formula_id']) : null;
    layout_start(Txn::ref($t), ['bare' => true, 'no_title' => true]);
    ?>
    <div class="no-print mb-2 text-center"><button class="btn btn-primary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
      <a class="btn btn-outline-secondary btn-sm" href="<?= h(Txn::url($t)) ?>">Back</a></div>
    <div class="doc">
      <div class="d-flex justify-content-between">
        <div class="doc-company"><?= Logo::img('doc.item_processing') ?><div><h2 class="mb-0"><?= h($s['company_name']) ?></h2><div class="small"><?= h($s['company_address']) ?></div></div></div>
        <div class="text-end"><h2 class="mb-0">PROCESSING ORDER</h2>
          <div>No. <strong><?= h($t['txn_no']) ?></strong></div><div>Date: <?= h(fdate($t['txn_date'])) ?></div>
          <?php if ((int)$t['is_voided'] === 1): ?><div class="text-danger fw-bold">VOID</div><?php elseif ((int)$t['is_pending'] === 1): ?><div class="text-warning fw-bold">PENDING</div><?php endif; ?>
        </div>
      </div>
      <hr>
      <div class="row small mb-3">
        <div class="col-4">Type: <strong><?= h(Processing::typeLabel($t['process_type'])) ?></strong><br>Method: <?= h(Processing::methodLabel($t['alloc_method'])) ?></div>
        <div class="col-4">Formula: <?= h($formula ? $formula['code'] . ' - ' . $formula['name'] : '—') ?><?= $formula && $t['process_batches'] !== null ? ' × ' . h(qty($t['process_batches'])) : '' ?><br>Reference: <?= h($t['ref_no']) ?></div>
        <div class="col-4">Processing site: <?= h($sites[$t['site_id']] ?? '') ?><br>Outputs to: <?= h($sites[$t['site2_id'] ?: $t['site_id']] ?? '') ?></div>
      </div>
      <h6>Inputs</h6>
      <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Item</th><th>Description</th><th class="num">Qty</th><?php if ($showCosts): ?><th class="num">Unit cost</th><th class="num">Cost</th><?php endif; ?></tr></thead>
        <?php foreach ($in as $l): ?><tr><td><?= h($l['item_code']) ?></td><td><?= h($l['description']) ?></td><td class="num"><?= qty($l['qty']) ?> <?= h($l['item_unit']) ?></td><?php if ($showCosts): ?><td class="num"><?= money(proc_unit_cost($l), true) ?></td><td class="num"><?= money($l['amount']) ?></td><?php endif; ?></tr><?php endforeach; ?>
        <?php foreach ($cs as $l): ?><tr><td colspan="2"><?= h($l['description']) ?> <span class="text-muted">(<?= h($l['account_name']) ?>)</span></td><td></td><?php if ($showCosts): ?><td></td><td class="num"><?= money($l['amount']) ?></td><?php endif; ?></tr><?php endforeach; ?>
        <?php $inQty = array_sum(array_map(fn($l) => (float)$l['qty'], $in)); $inCost = array_sum(array_map(fn($l) => (float)$l['amount'], $in)) + array_sum(array_map(fn($l) => (float)$l['amount'], $cs)); ?>
        <tr class="fw-semibold border-top"><td colspan="2">Total inputs<?= $cs ? ' and additional costs' : '' ?></td><td class="num"><?= qty($inQty) ?></td><?php if ($showCosts): ?><td></td><td class="num"><?= money($inCost) ?></td><?php endif; ?></tr>
      </table></div>
      <h6>Outputs</h6>
      <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Item</th><th>Description</th><th>Role</th><th class="num">Qty</th><th class="num">Share</th><?php if ($showCosts): ?><th class="num">Unit cost</th><th class="num">Cost</th><?php endif; ?></tr></thead>
        <?php foreach ($out as $l): ?><tr><td><?= h($l['item_code']) ?></td><td><?= h($l['description']) ?></td><td><?= h(Processing::roleLabel($l['output_role'])) ?></td>
          <td class="num"><?= qty($l['qty']) ?> <?= h($l['item_unit']) ?></td><td class="num"><?= number_format((float)$l['alloc_pct'], 2) ?>%</td>
          <?php if ($showCosts): ?><td class="num"><?= money($l['rate'], true) ?></td><td class="num"><?= money($l['amount']) ?></td><?php endif; ?></tr><?php endforeach; ?>
        <?php $outQty = array_sum(array_map(fn($l) => (float)$l['qty'], $out)); $outCost = array_sum(array_map(fn($l) => (float)$l['amount'], $out)); ?>
        <tr class="fw-semibold border-top"><td colspan="3">Total outputs</td><td class="num"><?= qty($outQty) ?></td><td class="num"><?= number_format(array_sum(array_map(fn($l) => (float)$l['alloc_pct'], $out)), 2) ?>%</td>
          <?php if ($showCosts): ?><td></td><td class="num"><?= money($outCost) ?></td><?php endif; ?></tr>
      </table></div>
      <table class="table table-sm w-auto ms-auto mb-2 proc-variance">
        <?php if ($showCosts): ?>
        <tr><td>Inputs<?= $cs ? ' and additional costs' : '' ?></td><td class="num"><?= money($inCost) ?></td></tr>
        <tr><td>Outputs</td><td class="num"><?= money($outCost) ?></td></tr>
        <tr class="fw-bold"><td>Variance (inputs less outputs)</td><td class="num"><?= money(r2($inCost - $outCost)) ?></td></tr>
        <?php endif; ?>
        <tr class="fw-bold proc-qty-var"><td>Variance qty (inputs less outputs)</td><td class="num"><?= proc_qty_variance($inQty, $outQty) ?></td></tr></table>
      <?php if ($t['memo']): ?><p class="small">Memo: <?= h($t['memo']) ?></p><?php endif; ?>
      <div class="row mt-5 small">
        <div class="col-4">Prepared by: <?= h(DB::value('SELECT full_name FROM users WHERE id = :id', ['id' => $t['created_by']])) ?></div>
        <div class="col-4 text-center">Processed by: ____________________</div>
        <div class="col-4 text-end">Checked / approved by: ____________________</div>
      </div>
    </div>
    <?php
    layout_end();
}
