<?php
declare(strict_types=1);

/** HTML for the editable line grids used by transaction forms (see assets/js/app.js). */

/** The buttons under a line grid: one line, five or ten at a time */
function grid_add_buttons(string $gridId, string $label = 'Add line', string $plural = 'lines'): string
{
    $b = fn(int $n, string $text, string $cls) => '<button type="button" class="btn btn-sm ' . $cls . '" data-add-row="' . h($gridId) . '" data-add-count="' . $n . '"'
        . ($n > 1 ? ' title="Add ' . $n . ' empty ' . h($plural) . ' at once"' : '') . '><i class="bi bi-plus"></i> ' . h($text) . '</button>';
    return '<div class="grid-add">' . $b(1, $label, 'btn-outline-secondary') . $b(5, 'Add 5 ' . $plural, 'btn-outline-secondary') . $b(10, 'Add 10 ' . $plural, 'btn-outline-secondary') . '</div>';
}

/** The buttons at the end of a line: a new line above it, and taking it out */
function grid_row_buttons(bool $removable = true, string $removeHtml = ''): string
{
    return '<td class="grid-acts"><button type="button" class="btn btn-sm btn-link p-0 grid-ins" data-insert-row title="Insert a line above this one" aria-label="Insert a line above"><i class="bi bi-plus-square"></i></button>'
        . ($removeHtml !== '' ? $removeHtml : ($removable ? '<button type="button" class="btn btn-sm btn-link text-danger p-0" data-remove-row title="Remove" aria-label="Remove the line"><i class="bi bi-x-lg"></i></button>' : '')) . '</td>';
}

/**
 * The hidden fields that keep a line in its item group: on a group shown as one line, which group of the document it
 * is and the ids of its saved lines; on a line of a group shown as the items in it, its group, price and role.
 */
function grid_group_fields(array $l, callable $n): string
{
    if (empty($l['group_ref'])) {
        return '';
    }
    $f = fn(string $k, $v) => '<input type="hidden" name="' . $n($k) . '" value="' . h((string)$v) . '">';
    if (!empty($l['_group']) || isset($l['group_line_ids'])) {
        return $f('group_ref', $l['group_ref']) . $f('group_line_ids', $l['group_line_ids'] ?? '') . $f('group_src_ids', $l['group_src_ids'] ?? '');
    }
    return $f('group_ref', $l['group_ref']) . $f('group_item_id', $l['group_item_id'] ?? '') . $f('group_qty', $l['group_qty'] ?? '')
        . $f('group_rate', $l['group_rate'] ?? '') . $f('group_role', $l['group_role'] ?? '');
}

function grid_item_options(array $items, $selected): string
{
    $out = '<option value=""></option>';
    foreach ($items as $it) {
        $sel = (string)$it['id'] === (string)$selected ? ' selected' : '';
        $out .= '<option value="' . $it['id'] . '"' . $sel . '>' . h(item_label($it['code'], $it['name'])) . '</option>';
    }
    return $out;
}

/**
 * Item grid.
 * $opt: id, mode (sales|purchase), name (post key), show_disc, show_uom, show_site, show_job,
 *       show_fulfilled (label), show_closed, total_group
 */
function item_grid(array $lines, array $opt): string
{
    $id = $opt['id'] ?? 'itemsGrid';
    $mode = $opt['mode'] ?? 'sales';
    $name = $opt['name'] ?? 'lines';
    $items = $opt['items'] ?? Lookup::itemsForGrid($mode);
    // keep inactive items that are already on the document
    foreach ($lines as $l) {
        if (!empty($l['item_id']) && !isset($items[(int)$l['item_id']])) {
            $it = item_cache((int)$l['item_id']);
            if ($it) {
                $items[(int)$it['id']] = ['id' => (int)$it['id'], 'code' => $it['code'], 'name' => $it['name'], 'type' => $it['item_type'],
                    'desc' => $it['description'], 'unit' => $it['unit'], 'price' => (float)$it['sales_price'], 'cost' => (float)$it['cost'], 'pct' => (float)$it['discount_pct'], 'tax' => $it['tax_code_id']];
            }
        }
    }
    $taxes = Lookup::taxRates();
    $taxOpts = Lookup::taxCodes();
    $sites = $opt['show_site'] ? Lookup::sites() : [];
    $jobs = !empty($opt['show_job']) ? Lookup::customers() : [];
    $group = $opt['total_group'] ?? 'main';
    $priceUrl = $mode === 'sales' ? url('api.lookup', ['what' => 'price']) : '';

    // units of measure: only worth a column when an item on this form has more than one unit
    $units = ($opt['show_uom'] ?? true) ? Uom::unitsByItem(array_keys($items)) : [];
    $units = array_filter($units, fn($u) => count($u) > 1);
    $showUom = (bool)$units;

    $row = function (array $l, string $i) use ($name, $items, $taxOpts, $sites, $jobs, $opt, $mode, $units, $showUom) {
        $n = fn($f) => h($name . '[' . $i . '][' . $f . ']');
        $isSub = !empty($l['item_id']) && ($items[(int)$l['item_id']]['type'] ?? '') === 'subtotal';
        $html = '<tr>';
        $html .= '<td class="col-item"><input type="hidden" name="' . $n('id') . '" value="' . h($l['id'] ?? '') . '">'
            . '<input type="hidden" name="' . $n('source_line_id') . '" value="' . h($l['source_line_id'] ?? '') . '">'
            . '<input type="hidden" name="' . $n('account_id') . '" value="' . h($l['account_id'] ?? '') . '">'
            . grid_group_fields($l, $n)
            . '<select class="form-select form-select-sm" data-f="item_id" name="' . $n('item_id') . '">' . grid_item_options($items, $l['item_id'] ?? '') . '</select></td>';
        $html .= '<td class="col-desc"><input class="form-control form-control-sm" data-f="description" name="' . $n('description') . '" value="' . h($l['description'] ?? '') . '">'
            . ($isSub ? '<span class="small text-muted">Subtotal: <span data-subtotal></span></span>' : '<span data-subtotal class="d-none"></span>') . '</td>';
        // quantity and price are entered in the line's own unit; qty / rate are stored in base units
        $entQty = array_key_exists('uom_qty', $l) && (float)$l['uom_qty'] != 0.0 ? (float)$l['uom_qty'] : ($l['qty'] ?? '');
        $entRate = array_key_exists('uom_rate', $l) && (float)$l['uom_rate'] != 0.0 ? (float)$l['uom_rate'] : ($l['rate'] ?? '');
        $html .= '<td class="col-sm"><input class="form-control form-control-sm text-end" data-f="qty" name="' . $n('qty') . '" value="' . h($entQty !== '' && $entQty !== null ? qty($entQty) : '') . '"></td>';
        if ($showUom) {
            $rowUnits = $units[(int)($l['item_id'] ?? 0)] ?? [];
            $opts = '<option value=""></option>';
            foreach ($rowUnits as $u) {
                $opts .= '<option value="' . $u['id'] . '" data-factor="' . h((string)$u['factor']) . '"' . ((string)$u['id'] === (string)($l['uom_id'] ?? '') ? ' selected' : '') . '>' . h($u['code']) . '</option>';
            }
            $html .= '<td class="col-sm"><select class="form-select form-select-sm" data-f="uom_id" name="' . $n('uom_id') . '">' . $opts . '</select></td>';
        }
        $html .= '<td class="col-num"><input class="form-control form-control-sm text-end" data-f="rate" name="' . $n('rate') . '" value="' . h($entRate !== '' && $entRate !== null && (float)$entRate != 0.0 ? rtrim(rtrim(number_format((float)$entRate, 4, '.', ''), '0'), '.') : '') . '"></td>';
        if (!empty($opt['show_disc'])) {
            $kind = ($l['discount_kind'] ?? 'pct') === 'amt' ? 'amt' : 'pct';
            $dval = $kind === 'amt' ? (float)($l['discount_amount'] ?? 0) : (float)($l['discount_pct'] ?? 0);
            $html .= '<td class="col-disc"><div class="input-group input-group-sm flex-nowrap">'
                . '<input class="form-control form-control-sm text-end" data-f="discount_val" name="' . $n('discount_val') . '" value="' . h($dval > 0 ? rtrim(rtrim(number_format($dval, 2, '.', ''), '0'), '.') : '') . '">'
                . '<select class="form-select form-select-sm flex-grow-0 w-auto" data-f="discount_kind" name="' . $n('discount_kind') . '" title="Percent of the line, or an amount off">'
                . '<option value="pct"' . ($kind === 'pct' ? ' selected' : '') . '>%</option>'
                . '<option value="amt"' . ($kind === 'amt' ? ' selected' : '') . '>Amt</option>'
                . '</select></div></td>';
        }
        $html .= '<td class="col-num"><input class="form-control form-control-sm text-end" data-f="amount" name="' . $n('amount') . '" value="' . h(isset($l['amount']) ? number_format((float)$l['amount'], 2, '.', '') : '') . '"></td>';
        $html .= '<td class="col-num"><select class="form-select form-select-sm" data-f="tax_code_id" name="' . $n('tax_code_id') . '">' . options($taxOpts, $l['tax_code_id'] ?? '', '') . '</select>'
            . '<input type="hidden" data-f="tax_amount" value="' . h(number_format((float)($l['tax_amount'] ?? 0), 2, '.', '')) . '"></td>';
        if (!empty($opt['show_site'])) {
            $html .= '<td class="col-num"><select class="form-select form-select-sm" name="' . $n('site_id') . '">' . options($sites, $l['site_id'] ?? '', 'Default') . '</select></td>';
        }
        if (!empty($opt['show_job'])) {
            $html .= '<td class="col-item"><select class="form-select form-select-sm" name="' . $n('customer_id') . '">' . options($jobs, $l['customer_id'] ?? '', '') . '</select></td>'
                . '<td class="text-center"><input type="checkbox" class="form-check-input" name="' . $n('is_billable') . '" value="1"' . checked(!empty($l['is_billable'])) . '></td>';
        }
        if (!empty($opt['show_fulfilled'])) {
            $html .= '<td class="num small">' . (isset($l['qty_fulfilled']) ? qty($l['qty_fulfilled']) : '') . '</td>';
        }
        if (!empty($opt['show_closed'])) {
            $html .= '<td class="text-center"><input type="checkbox" class="form-check-input" name="' . $n('is_closed') . '" value="1"' . checked(!empty($l['is_closed'])) . '></td>';
        }
        $locked = !empty($l['qty_fulfilled']) && (float)$l['qty_fulfilled'] > 0;
        $html .= grid_row_buttons(!$locked);
        return $html . '</tr>';
    };

    $head = '<th>Item</th><th>Description</th><th class="num">Qty</th>' . ($showUom ? '<th>U/M</th>' : '')
        . '<th class="num">' . ($mode === 'purchase' ? 'Cost' : 'Rate') . '</th>'
        . (!empty($opt['show_disc']) ? '<th class="num">Discount</th>' : '') . '<th class="num">Amount</th><th>Tax</th>'
        . (!empty($opt['show_site']) ? '<th>Site</th>' : '')
        . (!empty($opt['show_job']) ? '<th>Customer:Job</th><th>Billable</th>' : '')
        . (!empty($opt['show_fulfilled']) ? '<th class="num">' . h($opt['show_fulfilled']) . '</th>' : '')
        . (!empty($opt['show_closed']) ? '<th>Closed</th>' : '') . '<th></th>';

    $body = '';
    $i = 0;
    foreach ($lines as $l) {
        $body .= $row($l, (string)$i++);
    }
    $tpl = $row([], '__i__');

    return '<div class="table-responsive"><table class="table table-sm line-grid mb-1" id="' . h($id) . '" data-template="tpl-' . h($id) . '" data-mode="' . h($mode) . '"'
        . ' data-total-group="' . h($group) . '" data-min-rows="' . (int)($opt['min_rows'] ?? 3) . '"'
        . ' data-items="' . h(json_encode($items)) . '" data-taxes="' . h(json_encode($taxes)) . '"'
        . ($showUom ? ' data-units="' . h(json_encode($units)) . '"' : '')
        . ($priceUrl ? ' data-price-url="' . h($priceUrl) . '"' : '') . '>'
        . '<thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div>'
        . '<template id="tpl-' . h($id) . '">' . $tpl . '</template>'
        . grid_add_buttons($id);
}

/** Expense-account grid (bills, checks, vendor credits) */
function expense_grid(array $lines, array $opt = []): string
{
    $id = $opt['id'] ?? 'expGrid';
    $name = $opt['name'] ?? 'expenses';
    $accounts = Accts::all();
    $taxOpts = Lookup::taxCodes();
    $jobs = Lookup::customers();
    $group = $opt['total_group'] ?? 'main';
    $row = function (array $l, string $i) use ($name, $accounts, $taxOpts, $jobs) {
        $n = fn($f) => h($name . '[' . $i . '][' . $f . ']');
        return '<tr>'
            . '<td class="col-item"><input type="hidden" name="' . $n('id') . '" value="' . h($l['id'] ?? '') . '">'
            . '<select class="form-select form-select-sm" name="' . $n('account_id') . '">' . options($accounts, $l['account_id'] ?? '', '') . '</select></td>'
            . '<td class="col-num"><input class="form-control form-control-sm text-end" data-f="amount" name="' . $n('amount') . '" value="' . h(isset($l['amount']) ? number_format((float)$l['amount'], 2, '.', '') : '') . '"></td>'
            . '<td class="col-num"><select class="form-select form-select-sm" data-f="tax_code_id" name="' . $n('tax_code_id') . '">' . options($taxOpts, $l['tax_code_id'] ?? '', '') . '</select>'
            . '<input type="hidden" data-f="tax_amount" value="' . h(number_format((float)($l['tax_amount'] ?? 0), 2, '.', '')) . '"></td>'
            . '<td class="col-desc"><input class="form-control form-control-sm" name="' . $n('description') . '" value="' . h($l['description'] ?? '') . '"></td>'
            . '<td class="col-item"><select class="form-select form-select-sm" name="' . $n('customer_id') . '">' . options($jobs, $l['customer_id'] ?? '', '') . '</select></td>'
            . '<td class="text-center"><input type="checkbox" class="form-check-input" name="' . $n('is_billable') . '" value="1"' . checked(!empty($l['is_billable'])) . '></td>'
            . grid_row_buttons(true, !empty($l['billed_txn_id']) ? '<span class="small text-success ms-1">billed</span>' : '')
            . '</tr>';
    };
    $body = '';
    $i = 0;
    foreach ($lines as $l) {
        $body .= $row($l, (string)$i++);
    }
    return '<div class="table-responsive"><table class="table table-sm line-grid mb-1" id="' . h($id) . '" data-template="tpl-' . h($id) . '" data-mode="expense"'
        . ' data-total-group="' . h($group) . '" data-min-rows="' . (int)($opt['min_rows'] ?? 2) . '" data-taxes="' . h(json_encode(Lookup::taxRates())) . '">'
        . '<thead><tr><th>Account</th><th class="num">Amount</th><th>Tax</th><th>Memo</th><th>Customer:Job</th><th>Billable</th><th></th></tr></thead>'
        . '<tbody>' . $body . '</tbody></table></div>'
        . '<template id="tpl-' . h($id) . '">' . $row([], '__i__') . '</template>'
        . grid_add_buttons($id);
}

/**
 * Journal grid: account, debit, credit, memo, name and class.
 * Used by journal entries and cash vouchers.
 * $opt: id, name (post key), min_rows, name_label ("Name" / "SL"), total_group, foot_extra (html for the totals row)
 */
/**
 * Labels for the searchable account boxes: "1010 - Cash in Bank" becomes "Cash in Bank (1010)", in name order,
 * so an account is looked up by its description and the code follows at the end.
 */
function combo_account_options(array $pairs): array
{
    $out = [];
    foreach ($pairs as $id => $label) {
        $out[$id] = preg_match('/^(\S+) - (.+)$/u', (string)$label, $m) ? $m[2] . ' (' . $m[1] . ')' : (string)$label;
    }
    uasort($out, fn($a, $b) => strcasecmp($a, $b));
    return $out;
}

/** Labels for the searchable name boxes: "Customer: ABC Trading" becomes "ABC Trading (Customer)", in name order */
function combo_name_options(array $pairs): array
{
    $out = [];
    foreach ($pairs as $code => $label) {
        $out[$code] = preg_match('/^(Employee|Customer|Supplier|Vendor|Other): (.+)$/u', (string)$label, $m) ? $m[2] . ' (' . ($m[1] === 'Other' ? 'Other name' : $m[1]) . ')' : (string)$label;
    }
    uasort($out, fn($a, $b) => strcasecmp($a, $b));
    return $out;
}

function journal_grid(array $lines, array $opt = []): string
{
    $id = $opt['id'] ?? 'jeGrid';
    $name = $opt['name'] ?? 'lines';
    $group = $opt['total_group'] ?? 'je';
    $nameLabel = $opt['name_label'] ?? 'Name';
    $accounts = combo_account_options(Accts::all());
    $showClass = Classes::ready();
    $keep = [];
    foreach ($lines as $l) {
        $keep[] = $l['name_code'] ?? Names::code($l);
    }
    $names = Names::options();
    foreach (array_filter($keep) as $code) {
        if (!isset($names[$code])) {
            $names += Names::options($code);
        }
    }
    $names = combo_name_options($names);
    $row = function (array $l, string $i) use ($name, $accounts, $names, $showClass) {
        $n = fn($f) => h($name . '[' . $i . '][' . $f . ']');
        $amt = (float)($l['amount'] ?? 0);
        $sel = $l['name_code'] ?? Names::code($l);
        return '<tr>'
            . '<td class="col-item"><input type="hidden" name="' . $n('id') . '" value="' . h($l['id'] ?? '') . '"><select class="form-select form-select-sm" data-combo data-combo-placeholder="Type the account name" name="' . $n('account_id') . '">' . options($accounts, $l['account_id'] ?? '', '') . '</select></td>'
            . '<td class="col-num"><input class="form-control form-control-sm text-end" data-f="debit" name="' . $n('debit') . '" value="' . ($amt > 0 ? number_format($amt, 2, '.', '') : '') . '"></td>'
            . '<td class="col-num"><input class="form-control form-control-sm text-end" data-f="credit" name="' . $n('credit') . '" value="' . ($amt < 0 ? number_format(-$amt, 2, '.', '') : '') . '"></td>'
            . '<td class="col-desc"><input class="form-control form-control-sm" name="' . $n('description') . '" value="' . h($l['description'] ?? '') . '"></td>'
            . '<td class="col-item"><select class="form-select form-select-sm" data-combo data-combo-placeholder="Type a name" name="' . $n('name') . '">' . options($names, $sel, '') . '</select></td>'
            . ($showClass ? '<td class="col-class">' . Classes::lineSelect($name . '[' . $i . '][class_id]', $l['class_id'] ?? '') . '</td>' : '')
            . grid_row_buttons() . '</tr>';
    };
    $body = '';
    $i = 0;
    foreach ($lines as $l) {
        $body .= $row($l, (string)$i++);
    }
    $foot = $opt['foot_extra'] ?? ('Difference: <span data-total="' . h($group) . '-diff">0.00</span>');
    return '<div class="table-responsive"><table class="table table-sm line-grid mb-1" id="' . h($id) . '" data-template="tpl-' . h($id) . '" data-mode="journal" data-total-group="' . h($group) . '" data-min-rows="' . (int)($opt['min_rows'] ?? 4) . '">'
        . '<thead><tr><th>Account</th><th class="num">Debit</th><th class="num">Credit</th><th>Memo</th><th>' . h($nameLabel) . '</th>' . ($showClass ? '<th>Class</th>' : '') . '<th></th></tr></thead>'
        . '<tbody>' . $body . '</tbody>'
        . '<tfoot><tr class="fw-semibold grid-totals"><td class="text-end">Totals</td><td class="num" data-total="' . h($group) . '-debit">0.00</td><td class="num" data-total="' . h($group) . '-credit">0.00</td><td colspan="' . ($showClass ? 4 : 3) . '">' . $foot . '</td></tr></tfoot></table></div>'
        . '<template id="tpl-' . h($id) . '">' . $row([], '__i__') . '</template>'
        . grid_add_buttons($id);
}

/** Rows posted from a journal grid, shaped for showing the grid again after an error */
function journal_rows_for_redisplay(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => $r['id'] ?? '', 'account_id' => $r['account_id'] ?? '',
            'amount' => to_num($r['debit'] ?? 0) - to_num($r['credit'] ?? 0),
            'description' => $r['description'] ?? '', 'name_code' => (string)($r['name'] ?? ''), 'class_id' => $r['class_id'] ?? '',
        ];
    }
    return $out;
}

function parse_journal_lines(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $acct = !empty($r['account_id']) ? (int)$r['account_id'] : null;
        $dr = r2(to_num($r['debit'] ?? 0));
        $cr = r2(to_num($r['credit'] ?? 0));
        if (!$acct && $dr == 0.0 && $cr == 0.0) {
            continue;
        }
        if (!$acct) {
            fail('Choose an account for every journal line.');
        }
        if ($dr != 0.0 && $cr != 0.0) {
            fail('A journal line cannot have both a debit and a credit.');
        }
        $who = Names::parse((string)($r['name'] ?? ''));
        $line = [
            'line_kind'     => 'journal',
            'account_id'    => $acct,
            'amount'        => $dr != 0.0 ? $dr : -$cr,
            'description'   => mb_substr(trim((string)($r['description'] ?? '')), 0, 255) ?: null,
            'customer_id'   => $who['customer_id'],
            'vendor_id'     => $who['vendor_id'],
            'other_name_id' => $who['other_name_id'],
            'class_id'      => Classes::ready() ? Classes::valid($r['class_id'] ?? null) : null,
        ];
        if (!Classes::ready()) {
            unset($line['class_id'], $line['other_name_id']);
        }
        if (!empty($r['id']) && ctype_digit((string)$r['id'])) {
            $line['id'] = (int)$r['id'];
        }
        $out[] = $line;
    }
    return $out;
}

function totals_box(string $group = 'main', string $extra = ''): string
{
    return '<table class="table table-sm totals-box ms-auto mb-0">'
        . '<tr><td>Subtotal</td><td class="num" data-total="' . h($group) . '-subtotal">0.00</td></tr>'
        . '<tr><td>Tax</td><td class="num" data-total="' . h($group) . '-tax">0.00</td></tr>'
        . $extra
        . '<tr class="fw-bold fs-6"><td>Total</td><td class="num" data-total="' . h($group) . '-total">0.00</td></tr></table>';
}
