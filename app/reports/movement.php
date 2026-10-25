<?php
declare(strict_types=1);

/**
 * Inventory movement reports (v25):
 *   Inventory Movement Summary Report   (Inventory Reports)  beginning, in, out, ending per item
 *   Inventory Movement Detailed Report  (Inventory Reports)  the in and out split by kind of transaction
 *   Sales Report Through Inventory Movement (Sales Reports)  quantity sold = beginning + in - out - ending
 *   Inventory Movement Transactions     (hidden)             the source transactions behind every figure
 *
 * Every figure comes from the stock movements (inventory_movements) of transactions that are not voided, so the
 * beginning balance (everything before the From date) plus the movements of the period always gives the ending
 * balance (everything up to the To date). The site, class and item filters narrow the beginning and the period alike.
 */

/** The kinds of movement: key => [label, 'in' | 'out', what it covers] */
const INV_MOVE_KINDS = [
    'purchases'  => ['Purchases', 'in', 'Write Checks and Cash Vouchers with inventory (positive or negative), Receive Items, Receive Items and Enter Bills'],
    'trf_in'     => ['Transfer inventory', 'in', 'Inventory transfers into the site'],
    'adj_in'     => ['Adjust quantity', 'in', 'Inventory adjustments that add quantity (physical counts and opening quantities included)'],
    'build_in'   => ['Build assemblies', 'in', 'Build Assemblies: the assembled items'],
    'proc_in'    => ['Disassemble / process items', 'in', 'Disassemble / Process Items: the outputs'],
    'sales'      => ['Sales', 'out', 'Create Invoices, Create Batch Invoices, Enter Sales Receipts, POS sales and statement charges, less credit memos and POS returns that restock'],
    'trf_out'    => ['Transfer inventory', 'out', 'Inventory transfers out of the site'],
    'adj_out'    => ['Adjust quantity', 'out', 'Inventory adjustments that take quantity off'],
    'build_out'  => ['Build assemblies', 'out', 'Build Assemblies: the component items used'],
    'proc_out'   => ['Disassemble / process items', 'out', 'Disassemble / Process Items: the inputs consumed'],
    'debit_memo' => ['Supplier debit memo', 'out', 'Supplier debit memos (vendor credits) that return items'],
];

/** The movement groups a drill-down can ask for, besides one kind */
const INV_MOVE_GROUPS = [
    'beg'     => 'Beginning balance (before the From date)',
    'end'     => 'Ending balance (up to the To date)',
    'in'      => 'All IN',
    'out'     => 'All OUT',
    'in_ns'   => 'Delivery (IN, without sales documents)',
    'out_ns'  => 'Withdrawals (OUT, without sales documents)',
];

/** The SQL that sorts a movement (m) of a transaction (t) into its kind */
function inv_move_kind_sql(): string
{
    $proc = "'" . preg_replace('/\W/', '', Processing::TXN_TYPE) . "'";
    return "CASE WHEN t.txn_type IN ('bill','item_receipt','check','cash_voucher') THEN 'purchases'
                 WHEN t.txn_type IN ('invoice','statement_charge','sales_receipt','pos_sale','credit_memo','pos_return') THEN 'sales'
                 WHEN t.txn_type = 'vendor_credit' THEN 'debit_memo'
                 WHEN t.txn_type = 'inventory_transfer' THEN IF(m.qty < 0, 'trf_out', 'trf_in')
                 WHEN t.txn_type = 'build_assembly' THEN IF(m.qty < 0, 'build_out', 'build_in')
                 WHEN t.txn_type = $proc THEN IF(m.qty < 0, 'proc_out', 'proc_in')
                 ELSE IF(m.qty < 0, 'adj_out', 'adj_in') END";
}

/** The filters every movement query shares: site, class, item, category, the sites the user may see, the cashier */
function inv_move_where(array $f, array &$p): string
{
    $w = ' AND t.is_voided = 0 AND m.qty <> 0';
    if (!empty($f['site_id'])) {
        $w .= ' AND m.site_id = :mvs';
        $p['mvs'] = (int)$f['site_id'];
    } elseif (!empty($f['site_ids'])) {
        // a cashier who worked at several outlets in the period: those sites
        [$sin, $sp0] = DB::in('mvss', array_map('intval', (array)$f['site_ids']));
        $w .= " AND m.site_id IN $sin";
        $p += $sp0;
    }
    if (!empty($f['class_id']) && Classes::ready()) {
        if ((int)$f['class_id'] === Classes::UNCLASSIFIED) {
            $w .= ' AND COALESCE(l.class_id, t.class_id) IS NULL';
        } else {
            $w .= ' AND COALESCE(l.class_id, t.class_id) = :mvc';
            $p['mvc'] = (int)$f['class_id'];
        }
    }
    if (!empty($f['item_id'])) {
        $w .= ' AND m.item_id = :mvi';
        $p['mvi'] = (int)$f['item_id'];
    }
    $w .= Report::itemCond($f, 'm.item_id', $p, 'mvf');
    [$sf, $sp] = Access::siteFilter('m.site_id', 'mvst');
    $p += $sp;
    return $w . $sf;
}

const INV_MOVE_FROM = ' FROM inventory_movements m JOIN transactions t ON t.id = m.txn_id LEFT JOIN transaction_lines l ON l.id = m.txn_line_id ';

/**
 * When a movement happened, to the second: its date, at the time of day its transaction was recorded. A POS sale is
 * recorded as it is rung up, so it falls in the shift that made it; a document dated another day counts on its own
 * date, at the time of day it was entered.
 */
const INV_MOVE_TS = 'TIMESTAMP(m.txn_date, TIME(t.created_at))';

/**
 * The period condition of a movement query: 'before' the From (the beginning balance), 'within' the range, or
 * 'through' the To (the ending balance). Whole days unless the report was given a time.
 */
function inv_move_when(array $f, string $which, array &$p): string
{
    if (empty($f['timed'])) {
        switch ($which) {
            case 'before':
                $p['mvwf'] = $f['from'];
                return ' AND m.txn_date < :mvwf';
            case 'through':
                $p['mvwt'] = $f['to'];
                return ' AND m.txn_date <= :mvwt';
            default:
                $p['mvwf'] = $f['from'];
                $p['mvwt'] = $f['to'];
                return ' AND m.txn_date BETWEEN :mvwf AND :mvwt';
        }
    }
    $ts = INV_MOVE_TS;
    switch ($which) {
        case 'before':
            $p['mvwf'] = $f['from'];
            $p['mvwfd'] = $f['from_dt'];
            return " AND m.txn_date <= :mvwf AND $ts < :mvwfd";
        case 'through':
            $p['mvwt'] = $f['to'];
            $p['mvwtd'] = $f['to_dt'];
            return " AND m.txn_date <= :mvwt AND $ts <= :mvwtd";
        default:
            $p['mvwf'] = $f['from'];
            $p['mvwt'] = $f['to'];
            $p['mvwfd'] = $f['from_dt'];
            $p['mvwtd'] = $f['to_dt'];
            return " AND m.txn_date BETWEEN :mvwf AND :mvwt AND $ts BETWEEN :mvwfd AND :mvwtd";
    }
}

/**
 * item_id => ['beg' => qty, 'end' => qty, kind => qty (signed as in stock), ...] for the period, plus the
 * quantity and amount sold per cashier when one is asked for.
 */
function inv_move_data(array $f, ?int $cashier = null): array
{
    $out = [];
    $p = [];
    $where = inv_move_where($f, $p);
    $pb = $p;
    $before = inv_move_when($f, 'before', $pb);
    foreach (DB::all('SELECT m.item_id, SUM(m.qty) AS q' . INV_MOVE_FROM . 'WHERE 1 = 1' . $before . $where . ' GROUP BY m.item_id', $pb) as $r) {
        $out[(int)$r['item_id']]['beg'] = (float)$r['q'];
    }
    $kind = inv_move_kind_sql();
    // the period, by kind; the sales documents also give the amount sold (credit memos and returns take it back)
    $sql = "SELECT m.item_id, $kind AS kind, SUM(m.qty) AS q,
                   SUM(CASE WHEN t.txn_type IN ('credit_memo','pos_return') THEN -1 ELSE 1 END * COALESCE(l.amount, 0)) AS amt,
                   SUM(CASE WHEN t.created_by = :mvu THEN m.qty ELSE 0 END) AS q_user,
                   SUM(CASE WHEN t.created_by = :mvu2 THEN CASE WHEN t.txn_type IN ('credit_memo','pos_return') THEN -1 ELSE 1 END * COALESCE(l.amount, 0) ELSE 0 END) AS amt_user"
        . INV_MOVE_FROM . 'WHERE 1 = 1' . inv_move_when($f, 'within', $p) . $where . ' GROUP BY m.item_id, kind';
    foreach (DB::all($sql, $p + ['mvu' => (int)$cashier, 'mvu2' => (int)$cashier]) as $r) {
        $id = (int)$r['item_id'];
        $out[$id][$r['kind']] = ($out[$id][$r['kind']] ?? 0.0) + (float)$r['q'];
        if ($r['kind'] === 'sales') {
            $out[$id]['sales_amt'] = (float)$r['amt'];
            $out[$id]['sales_q_user'] = (float)$r['q_user'];
            $out[$id]['sales_amt_user'] = (float)$r['amt_user'];
        }
    }
    foreach ($out as $id => &$d) {
        $d['beg'] ??= 0.0;
        $d['in'] = 0.0;
        $d['out'] = 0.0;
        foreach (INV_MOVE_KINDS as $k => [, $dir]) {
            $d[$k] ??= 0.0;
            $d[$dir] += $d[$k];
        }
        // OUT reads as a positive quantity taken off (sales net of returns, issues, transfers out)
        $d['out'] = -$d['out'];
        $d['end'] = $d['beg'] + $d['in'] - $d['out'];
    }
    unset($d);
    return $out;
}

/** The items of the movement data, in code order (inactive items that moved are shown too) */
function inv_move_items(array $data): array
{
    if (!$data) {
        return [];
    }
    [$in, $p] = DB::in('mvit', array_keys($data));
    return DB::all("SELECT id, code, name, unit, is_active FROM items WHERE id IN $in ORDER BY code", $p);
}

/** The drill-down to the source transactions of one figure (none for a zero: there is nothing behind it) */
function inv_move_link(array $f, ?int $itemId, string $move, ?int $cashier = null, ?float $value = null): ?string
{
    if ($value !== null && abs($value) < 0.00001) {
        return null;
    }
    $from = $f['from'];
    $to = $f['to'];
    $timed = !empty($f['timed']);
    $fromTime = $timed ? substr((string)$f['from_dt'], 11) : null;
    $toTime = $timed ? substr((string)$f['to_dt'], 11) : null;
    if ($move === 'beg' || $move === 'end') {
        static $first = null;
        $first ??= (string)(DB::value('SELECT MIN(txn_date) FROM inventory_movements') ?: $from);
        if ($move === 'beg') {
            if ($timed && $fromTime !== '00:00:00') {
                // up to the second before the From time
                $to = $from;
                $toTime = date('H:i:s', strtotime($f['from_dt']) - 1);
            } else {
                $to = date('Y-m-d', strtotime($from . ' -1 day'));
                $toTime = $timed ? '23:59:59' : null;
            }
        }
        $from = min($first, $from);
        $fromTime = $timed ? '00:00:00' : null;
        if ($to < $from) {
            $from = $to;
        }
    }
    return Report::link('rpt.inv_movement_txns', array_filter([
        'dates' => 'custom', 'from' => $from, 'from_time' => $fromTime, 'to' => $to, 'to_time' => $toTime, 'move' => $move, 'item_id' => $itemId,
        'site_id' => $f['site_id'] ?? null, 'sites' => empty($f['site_id']) && !empty($f['site_ids']) ? implode(',', (array)$f['site_ids']) : null,
        'class_id' => $f['class_id'] ?? null, 'category_id' => $f['category_id'] ?? null,
        'cashier_id' => $cashier,
    ], fn($v) => $v !== null && $v !== '' && $v !== 0));
}

function inv_move_notes(array $f): string
{
    if (!empty($f['timed'])) {
        $n = 'Beginning balance: the quantity on hand just before ' . Report::fdt($f['from_dt']) . '. Ending balance: the quantity on hand at ' . Report::fdt($f['to_dt']) . '. '
           . 'IN and OUT are the transactions between those times (each counts at its date and the time of day it was recorded; a POS sale at the moment it was rung up); '
           . 'voided transactions are left out. Click any figure to see its source transactions.';
    } else {
        $n = 'Beginning balance: the quantity on hand at the end of the day before the From date. Ending balance: the quantity on hand at the end of the To date. '
           . 'IN and OUT are the transactions dated within the range; voided transactions are left out. Click any figure to see its source transactions.';
    }
    if (!empty($f['_shift_label'])) {
        $n .= ' Shift: ' . h($f['_shift_label']) . '.';
    } elseif (!empty($f['_cashier_site'])) {
        $n .= ' Cashier: the stock of the outlet the cashier worked at (' . h($f['_cashier_site']) . ').';
    }
    if (!empty($f['site_id'])) {
        $n .= ' One site: transfers into it count as IN and transfers out of it as OUT.';
    } else {
        $n .= ' All sites: a transfer is IN at the site it went to and OUT at the site it left, so it does not change the total.';
    }
    if (!empty($f['class_id'])) {
        $n .= ' Class: the movements of transactions (or lines) of that class, the beginning balance included.';
    }
    return $n;
}

/** The users who entered sales documents or worked a POS shift (the Cashier filter) */
function inv_move_cashiers(): array
{
    static $list = null;
    return $list ??= DB::pairs("SELECT u.id, COALESCE(NULLIF(u.full_name, ''), u.username) FROM users u
                       WHERE EXISTS (SELECT 1 FROM transactions t WHERE t.created_by = u.id
                                        AND t.txn_type IN ('invoice','sales_receipt','pos_sale','statement_charge','credit_memo','pos_return'))
                          OR EXISTS (SELECT 1 FROM pos_shifts s WHERE s.cashier_id = u.id)
                       ORDER BY 2");
}

/** One POS shift with its terminal, site and cashier */
function inv_move_shift(int $id): ?array
{
    return DB::row("SELECT s.*, t.name AS terminal, t.site_id, si.name AS site, COALESCE(NULLIF(u.full_name, ''), u.username) AS cashier
                      FROM pos_shifts s JOIN pos_terminals t ON t.id = s.terminal_id JOIN sites si ON si.id = t.site_id JOIN users u ON u.id = s.cashier_id
                     WHERE s.id = :id", ['id' => $id]) ?: null;
}

function inv_move_shift_label(array $s): string
{
    return $s['cashier'] . ', ' . $s['terminal'] . ' (' . $s['site'] . '), ' . fdatetime($s['opened_at']) . ' to '
        . ($s['closed_at'] ? fdatetime($s['closed_at']) : 'still open');
}

/**
 * The POS shifts the Shift filter offers, newest first: those of the cashier chosen (or of the user alone, when
 * $only is given), at the sites the user may see.
 */
function inv_move_shifts(?int $only = null): array
{
    $cashier = $only ?: (int)g('cashier_id');
    $sql = "SELECT s.*, t.name AS terminal, t.site_id, si.name AS site, COALESCE(NULLIF(u.full_name, ''), u.username) AS cashier
              FROM pos_shifts s JOIN pos_terminals t ON t.id = s.terminal_id JOIN sites si ON si.id = t.site_id JOIN users u ON u.id = s.cashier_id WHERE 1 = 1";
    $p = [];
    if ($cashier) {
        $sql .= ' AND s.cashier_id = :c';
        $p['c'] = $cashier;
    }
    [$sf, $sp] = Access::siteFilter('t.site_id', 'shst');
    $out = [];
    foreach (DB::all($sql . $sf . ' ORDER BY s.opened_at DESC LIMIT 300', $p + $sp) as $s) {
        $out[(int)$s['id']] = inv_move_shift_label($s);
    }
    return $out;
}

/**
 * The Cashier and Shift filters of the movement reports. A shift sets the dates and times to the moment it was
 * opened and closed (now, when it is still open), the cashier, and the site of its terminal. A cashier without a
 * shift sets the site to the outlet(s) the cashier worked at in the period (or the cashier's default POS site).
 * $only: the one cashier this user may report on (a cashier printing their own ending inventory).
 */
function inv_move_cashier_hook(array $f, ?int $only = null): array
{
    if ($only) {
        $f['cashier_id'] = (string)$only;
    }
    $sid = (int)($f['shift_id'] ?? 0);
    $shift = $sid ? inv_move_shift($sid) : null;
    if ($shift && $only && (int)$shift['cashier_id'] !== $only) {
        $shift = null;
    }
    if ($shift && Access::siteIds() !== null && !in_array((int)$shift['site_id'], Access::siteIds(), true)) {
        $shift = null;
    }
    if (!$shift) {
        $f['shift_id'] = '';
    } else {
        // the shift's own times, once: dates typed afterwards (with the shift still chosen) are kept
        if (g('shift_applied') !== (string)$sid) {
            $close = $shift['closed_at'] ?: date('Y-m-d H:i:s');
            $f['from'] = substr((string)$shift['opened_at'], 0, 10);
            $f['from_time'] = substr((string)$shift['opened_at'], 11, 8);
            $f['to'] = substr((string)$close, 0, 10);
            $f['to_time'] = substr((string)$close, 11, 8);
            $_GET['dates'] = 'custom';   // the Dates box then reads Custom, not the preset the shift replaced
        }
        $f['cashier_id'] = (string)$shift['cashier_id'];
        if (empty($f['site_id'])) {
            $f['site_id'] = (int)$shift['site_id'];
        }
        $f['_hidden']['shift_applied'] = (string)$sid;
        $f['_shift_label'] = inv_move_shift_label($shift);
    }
    // sites passed on by a drill-down
    if (empty($f['site_id']) && is_string(g('sites')) && g('sites') !== '') {
        $f['site_ids'] = array_values(array_filter(array_map('intval', explode(',', (string)g('sites')))));
    }
    $cashier = (int)($f['cashier_id'] ?? 0);
    if ($cashier && empty($f['site_id']) && empty($f['site_ids'])) {
        $sites = array_map('intval', DB::column(
            "SELECT DISTINCT t.site_id FROM pos_shifts s JOIN pos_terminals t ON t.id = s.terminal_id
              WHERE s.cashier_id = :c AND DATE(s.opened_at) <= :t AND (s.closed_at IS NULL OR DATE(s.closed_at) >= :f)",
            ['c' => $cashier, 'f' => $f['from'], 't' => $f['to']]
        ));
        if (!$sites) {
            $def = (int)DB::value('SELECT pos_default_site_id FROM users WHERE id = :u', ['u' => $cashier]);
            $sites = $def ? [$def] : [];
        }
        $allowed = Access::siteIds();
        if ($allowed !== null) {
            $sites = array_values(array_intersect($sites, $allowed));
        }
        if (count($sites) === 1) {
            $f['site_id'] = $sites[0];
        } elseif ($sites) {
            $f['site_ids'] = $sites;
        }
        if ($sites) {
            $f['_cashier_site'] = implode(', ', DB::column('SELECT name FROM sites WHERE id IN (' . implode(',', array_map('intval', $sites)) . ') ORDER BY name'));
        }
    }
    return $f;
}

/** The Cashier and Shift drop-downs the movement reports share */
function inv_move_cashier_filters(): array
{
    return [
        'cashier_id' => ['Cashier', fn() => inv_move_cashiers()],
        'shift_id' => ['Shift', fn() => inv_move_shifts(), 'Choose a shift (sets the times)', true, true],
    ];
}

Report::define('inv_movement_summary', [
    'filters' => ['range', 'site', 'class', 'item', 'category'],
    'default_dates' => 'this_month',
    'times' => true,
    'extra_filters' => inv_move_cashier_filters(),
    'filter_hook' => fn(array $f): array => inv_move_cashier_hook($f),
    'run' => function (array $f): array {
        $data = inv_move_data($f);
        $rows = [];
        $tot = ['item' => 'TOTAL', 'beg' => 0.0, 'in' => 0.0, 'out' => 0.0, 'end' => 0.0];
        foreach (inv_move_items($data) as $i) {
            $d = $data[(int)$i['id']];
            if (abs($d['beg']) < 0.00001 && abs($d['in']) < 0.00001 && abs($d['out']) < 0.00001 && abs($d['end']) < 0.00001) {
                continue;
            }
            $id = (int)$i['id'];
            $rows[] = [
                'item' => $i['code'], 'desc' => $i['name'], 'unit' => $i['unit'],
                'beg' => $d['beg'], 'in' => $d['in'], 'out' => $d['out'], 'end' => $d['end'],
                '_links' => ['item' => Report::link('inv.center', ['id' => $id]), 'beg' => inv_move_link($f, $id, 'beg', null, $d['beg']), 'in' => inv_move_link($f, $id, 'in', null, $d['in']),
                    'out' => inv_move_link($f, $id, 'out', null, $d['out']), 'end' => inv_move_link($f, $id, 'end', null, $d['end'])],
                '_ref' => ['item' => $id],
            ];
            foreach (['beg', 'in', 'out', 'end'] as $k) {
                $tot[$k] += $d[$k];
            }
        }
        $tot['_links'] = ['beg' => inv_move_link($f, null, 'beg', null, $tot['beg']), 'in' => inv_move_link($f, null, 'in', null, $tot['in']),
            'out' => inv_move_link($f, null, 'out', null, $tot['out']), 'end' => inv_move_link($f, null, 'end', null, $tot['end'])];
        return [
            'columns' => [['key' => 'item', 'label' => 'Item code'], ['key' => 'desc', 'label' => 'Item description'], ['key' => 'unit', 'label' => 'Unit', 'optional' => true],
                ['key' => 'beg', 'label' => 'Beg. balance', 'type' => 'qty'], ['key' => 'in', 'label' => 'In', 'type' => 'qty'],
                ['key' => 'out', 'label' => 'Out', 'type' => 'qty'], ['key' => 'end', 'label' => 'Ending balance', 'type' => 'qty']],
            'rows' => $rows,
            'totals' => $tot,
            'notes' => inv_move_notes($f) . ' Ending balance = Beg. balance + In - Out.',
            'footer' => '<div class="small text-muted"><strong>In:</strong> ' . h(implode('; ', array_map(fn($k) => INV_MOVE_KINDS[$k][2], ['purchases', 'trf_in', 'adj_in', 'build_in', 'proc_in'])))
                . '.<br><strong>Out:</strong> ' . h(implode('; ', array_map(fn($k) => INV_MOVE_KINDS[$k][2], ['sales', 'trf_out', 'adj_out', 'build_out', 'proc_out', 'debit_memo']))) . '.</div>',
        ];
    },
]);

Report::define('inv_movement_detail', [
    'filters' => ['range', 'site', 'class', 'item', 'category'],
    'default_dates' => 'this_month',
    'times' => true,
    'extra_filters' => inv_move_cashier_filters(),
    'filter_hook' => fn(array $f): array => inv_move_cashier_hook($f),
    'run' => function (array $f): array {
        $data = inv_move_data($f);
        $cols = [['key' => 'item', 'label' => 'Item code'], ['key' => 'desc', 'label' => 'Item description'], ['key' => 'unit', 'label' => 'Unit', 'optional' => true],
            ['key' => 'beg', 'label' => 'Beg. balance', 'type' => 'qty']];
        foreach (INV_MOVE_KINDS as $k => [$label, $dir]) {
            $cols[] = ['key' => $k, 'label' => $label, 'type' => 'qty', 'band' => strtoupper($dir), 'group' => $dir === 'in' ? 'In' : 'Out'];
        }
        $cols[] = ['key' => 'end', 'label' => 'Ending balance', 'type' => 'qty'];
        $rows = [];
        $tot = ['item' => 'TOTAL', 'beg' => 0.0, 'end' => 0.0] + array_fill_keys(array_keys(INV_MOVE_KINDS), 0.0);
        $tot['_links'] = ['beg' => inv_move_link($f, null, 'beg'), 'end' => inv_move_link($f, null, 'end')];
        foreach (array_keys(INV_MOVE_KINDS) as $k) {
            $tot['_links'][$k] = inv_move_link($f, null, $k);
        }
        foreach (inv_move_items($data) as $i) {
            $id = (int)$i['id'];
            $d = $data[$id];
            if (abs($d['beg']) < 0.00001 && abs($d['in']) < 0.00001 && abs($d['out']) < 0.00001 && abs($d['end']) < 0.00001) {
                continue;
            }
            $r = ['item' => $i['code'], 'desc' => $i['name'], 'unit' => $i['unit'], 'beg' => $d['beg'], 'end' => $d['end'],
                '_links' => ['item' => Report::link('inv.center', ['id' => $id]), 'beg' => inv_move_link($f, $id, 'beg', null, $d['beg']), 'end' => inv_move_link($f, $id, 'end', null, $d['end'])],
                '_ref' => ['item' => $id]];
            foreach (INV_MOVE_KINDS as $k => $_) {
                // as in stock: IN positive, OUT negative (a purchase returned on a check reads negative under Purchases)
                $r[$k] = abs($d[$k]) < 0.00001 ? '' : $d[$k];
                if (abs($d[$k]) >= 0.00001) {
                    $r['_links'][$k] = inv_move_link($f, $id, $k);
                }
                $tot[$k] += $d[$k];
            }
            $tot['beg'] += $d['beg'];
            $tot['end'] += $d['end'];
            $rows[] = $r;
        }
        $legend = [];
        foreach (INV_MOVE_KINDS as [$label, $dir, $what]) {
            $legend[] = '<strong>' . strtoupper($dir) . ' ' . h($label) . ':</strong> ' . h($what);
        }
        return [
            'columns' => $cols,
            'rows' => $rows,
            'totals' => $tot,
            'notes' => inv_move_notes($f) . ' The OUT columns are negative; Ending balance = the sum of Beg. balance and every IN and OUT column.',
            'footer' => '<div class="small text-muted">' . implode('<br>', $legend) . '</div>',
        ];
    },
]);

Report::define('sales_inv_movement', [
    'filters' => ['range', 'site', 'class', 'item', 'category'],
    'default_dates' => 'this_month',
    'times' => true,
    'extra_filters' => inv_move_cashier_filters(),
    'checks' => ['exclude_zero' => 'Exclude zero sales'],
    'filter_hook' => fn(array $f): array => inv_move_cashier_hook($f),
    'run' => function (array $f): array {
        $cashier = (int)($f['cashier_id'] ?? 0) ?: null;
        if ($cashier && !isset(inv_move_cashiers()[$cashier])) {
            $cashier = null;
        }
        $data = inv_move_data($f, $cashier);
        $inNs = ['purchases', 'trf_in', 'adj_in', 'build_in', 'proc_in'];
        $outNs = ['trf_out', 'adj_out', 'build_out', 'proc_out', 'debit_memo'];
        $rows = [];
        $tot = ['desc' => 'TOTAL', 'beg' => 0.0, 'in' => 0.0, 'out' => 0.0, 'end' => 0.0, 'sold' => 0.0, 'others' => 0.0, 'sales' => 0.0];
        foreach (inv_move_items($data) as $i) {
            $id = (int)$i['id'];
            $d = $data[$id];
            $in = array_sum(array_map(fn($k) => $d[$k], $inNs));
            $out = -array_sum(array_map(fn($k) => $d[$k], $outNs));
            // what the stock says was sold: beginning + delivery - withdrawals - ending
            $sold = $d['beg'] + $in - $out - $d['end'];
            $amt = (float)($d['sales_amt'] ?? 0.0);
            $others = 0.0;
            if ($cashier) {
                $mine = -(float)($d['sales_q_user'] ?? 0.0);
                $others = $sold - $mine;
                $sold = $mine;
                $amt = (float)($d['sales_amt_user'] ?? 0.0);
            }
            if (abs($d['beg']) < 0.00001 && abs($in) < 0.00001 && abs($out) < 0.00001 && abs($d['end']) < 0.00001 && abs($sold) < 0.00001 && abs($others) < 0.00001) {
                continue;
            }
            // "Exclude zero sales": only the items sold in the period (by the cashier, when one is chosen)
            if (($f['exclude_zero'] ?? '') === '1' && abs($sold) < 0.00001) {
                continue;
            }
            $avg = abs($sold) > 0.00001 ? $amt / $sold : 0.0;
            $total = r2($sold * $avg);
            $rows[] = [
                'item' => $i['code'], 'desc' => $i['name'], 'unit' => $i['unit'],
                'beg' => $d['beg'], 'in' => $in, 'out' => $out, 'end' => $d['end'], 'sold' => $sold, 'others' => $others,
                'avg' => abs($sold) > 0.00001 ? round($avg, 4) : '', 'sales' => $total,
                '_links' => ['beg' => inv_move_link($f, $id, 'beg', null, $d['beg']), 'in' => inv_move_link($f, $id, 'in_ns', null, $in), 'out' => inv_move_link($f, $id, 'out_ns', null, $out),
                    'end' => inv_move_link($f, $id, 'end', null, $d['end']), 'sold' => inv_move_link($f, $id, 'sales', $cashier, $sold),
                    'sales' => abs($total) < 0.005 ? null : inv_move_link($f, $id, 'sales', $cashier, $sold)],
                '_ref' => ['item' => $id],
            ];
            foreach (['beg' => $d['beg'], 'in' => $in, 'out' => $out, 'end' => $d['end'], 'sold' => $sold, 'others' => $others, 'sales' => $total] as $k => $v) {
                $tot[$k] += $v;
            }
        }
        // the total line's average price is the total sales over the quantity sold
        $tot['avg'] = abs($tot['sold']) > 0.00001 ? round($tot['sales'] / $tot['sold'], 4) : '';
        $tot['sales'] = r2($tot['sales']);
        $tot['_links'] = ['beg' => inv_move_link($f, null, 'beg', null, $tot['beg']), 'in' => inv_move_link($f, null, 'in_ns', null, $tot['in']),
            'out' => inv_move_link($f, null, 'out_ns', null, $tot['out']), 'end' => inv_move_link($f, null, 'end', null, $tot['end']),
            'sold' => inv_move_link($f, null, 'sales', $cashier, $tot['sold'])];
        $cols = [['key' => 'item', 'label' => 'Item code', 'optional' => true], ['key' => 'desc', 'label' => 'Item description'], ['key' => 'unit', 'label' => 'Unit', 'optional' => true],
            ['key' => 'beg', 'label' => 'Beg. inventory', 'type' => 'qty'], ['key' => 'in', 'label' => 'Delivery (in)', 'type' => 'qty'],
            ['key' => 'out', 'label' => 'Withdrawals (out)', 'type' => 'qty'], ['key' => 'end', 'label' => 'Ending inventory', 'type' => 'qty'],
            ['key' => 'sold', 'label' => 'Sales (qty)', 'type' => 'qty']];
        if ($cashier) {
            $cols[] = ['key' => 'others', 'label' => 'Sold by other cashiers', 'type' => 'qty'];
        }
        $cols[] = ['key' => 'avg', 'label' => 'Ave. price', 'type' => 'money'];
        $cols[] = ['key' => 'sales', 'label' => 'Total sales', 'type' => 'money'];
        $notes = 'Delivery (in) and Withdrawals (out) leave out invoices, batch invoices, sales receipts and POS sales; Sales (qty) = Beg. inventory + Delivery - Withdrawals - Ending inventory, '
            . 'which is the quantity the sales documents took out of stock (net of credit memos and POS returns that restock). Ave. price = the amount of those invoices, batch invoices, '
            . 'sales receipts and POS sales over the quantity sold, so Total sales (Sales qty x Ave. price) equals their amount. Ending inventory is the quantity on hand at the end of the '
            . (!empty($f['timed']) ? 'To date and time. ' : 'To date. ')
            . (!empty($f['timed']) ? 'Transactions between ' . Report::fdt($f['from_dt']) . ' and ' . Report::fdt($f['to_dt']) . ' (each at its date and the time of day it was recorded; a POS sale at the moment it was rung up)'
                : 'Transactions dated within the range') . '; click a figure to see its source transactions.';
        if (!empty($f['_shift_label'])) {
            $notes .= ' Shift: ' . h($f['_shift_label']) . '.';
        } elseif (!empty($f['_cashier_site'])) {
            $notes .= ' The stock is that of the outlet the cashier worked at (' . h($f['_cashier_site']) . ').';
        }
        if ($cashier) {
            $notes .= ' Cashier: Sales (qty), Ave. price and Total sales are those of the sales documents this cashier entered; "Sold by other cashiers" is the rest of the quantity sold.';
        }
        return [
            'columns' => $cols,
            'rows' => $rows,
            'totals' => $tot,
            'notes' => $notes,
            'footer' => '<div class="small text-muted"><strong>Delivery (in):</strong> ' . h(implode('; ', array_map(fn($k) => INV_MOVE_KINDS[$k][2], $inNs)))
                . '.<br><strong>Withdrawals (out):</strong> ' . h(implode('; ', array_map(fn($k) => INV_MOVE_KINDS[$k][2], $outNs))) . '.</div>',
        ];
    },
]);

Report::define('inv_movement_txns', [
    'filters' => ['range', 'site', 'class', 'item', 'category'],
    'default_dates' => 'this_month',
    'sortable' => false,
    'times' => true,
    'filter_hook' => function (array $f): array {
        // the sites a drill-down passed on (a cashier who worked at several outlets)
        if (empty($f['site_id']) && is_string(g('sites')) && g('sites') !== '') {
            $f['site_ids'] = array_values(array_filter(array_map('intval', explode(',', (string)g('sites')))));
            $f['_hidden']['sites'] = implode(',', $f['site_ids']);
        }
        return $f;
    },
    'extra_filters' => [
        'move' => ['Movement', function () {
            $o = INV_MOVE_GROUPS;
            foreach (INV_MOVE_KINDS as $k => [$label, $dir]) {
                $o[$k] = strtoupper($dir) . ': ' . $label;
            }
            return $o;
        }],
        'cashier_id' => ['Cashier', fn() => inv_move_cashiers()],
    ],
    'run' => function (array $f): array {
        $move = (string)($f['move'] ?? '');
        $kinds = match ($move) {
            'in' => array_keys(array_filter(INV_MOVE_KINDS, fn($k) => $k[1] === 'in')),
            'out' => array_keys(array_filter(INV_MOVE_KINDS, fn($k) => $k[1] === 'out')),
            'in_ns' => ['purchases', 'trf_in', 'adj_in', 'build_in', 'proc_in'],
            'out_ns' => ['trf_out', 'adj_out', 'build_out', 'proc_out', 'debit_memo'],
            default => isset(INV_MOVE_KINDS[$move]) ? [$move] : [],
        };
        $p = [];
        $where = inv_move_when($f, 'within', $p) . inv_move_where($f, $p);
        $kind = inv_move_kind_sql();
        $sql = "SELECT m.item_id, m.txn_date, TIME(t.created_at) AS txn_time, m.qty, m.total_cost, t.id AS txn_id, t.txn_type, t.txn_no, t.receipt_no, $kind AS kind,
                       COALESCE(c.name, v.name, t.payee_name) AS party, s.code AS site, cl.name AS class, COALESCE(NULLIF(u.full_name, ''), u.username) AS user,
                       i.code, i.name AS item_name
                  FROM inventory_movements m JOIN transactions t ON t.id = m.txn_id LEFT JOIN transaction_lines l ON l.id = m.txn_line_id
                  JOIN items i ON i.id = m.item_id JOIN sites s ON s.id = m.site_id
                  LEFT JOIN customers c ON c.id = t.customer_id LEFT JOIN vendors v ON v.id = t.vendor_id
                  LEFT JOIN classes cl ON cl.id = COALESCE(l.class_id, t.class_id) LEFT JOIN users u ON u.id = t.created_by
                 WHERE 1 = 1" . $where;
        if ($kinds) {
            [$in, $kp] = DB::in('mvk', $kinds);
            $sql .= " AND $kind IN $in";
            $p += $kp;
        }
        $cashier = (int)($f['cashier_id'] ?? 0);
        if ($cashier) {
            $sql .= ' AND t.created_by = :mvcu';
            $p['mvcu'] = $cashier;
        }
        $moves = DB::all($sql . ' ORDER BY i.code, m.txn_date, TIME(t.created_at), m.id', $p);
        $rows = [];
        $cur = null;
        $sub = 0.0;
        $subCost = 0.0;
        $grand = 0.0;
        $grandCost = 0.0;
        $close = function () use (&$rows, &$cur, &$sub, &$subCost) {
            if ($cur !== null) {
                $rows[] = ['type' => 'Total ' . $cur, 'qty' => $sub, 'cost' => r2($subCost), '_class' => 'subtotal-row'];
            }
        };
        foreach ($moves as $m) {
            if ($cur !== $m['code']) {
                $close();
                $cur = $m['code'];
                $sub = 0.0;
                $subCost = 0.0;
                $rows[] = ['type' => $m['code'] . ' - ' . $m['item_name'], '_class' => 'group-row'];
            }
            [$label, $dir] = INV_MOVE_KINDS[$m['kind']];
            $rows[] = [
                'type' => Txn::label($m['txn_type']), 'date' => $m['txn_date'], 'time' => date('h:i A', strtotime('2000-01-01 ' . $m['txn_time'])),
                'num' => $m['txn_no'] ?: $m['receipt_no'], 'name' => $m['party'],
                'site' => $m['site'], 'class' => $m['class'], 'user' => $m['user'], 'move' => strtoupper($dir) . ': ' . $label,
                'qty' => (float)$m['qty'], 'cost' => r2((float)$m['total_cost']),
                '_links' => ['num' => Report::txnLink($m), 'type' => Report::txnLink($m)],
                '_class' => 'indent-1',
            ];
            $sub += (float)$m['qty'];
            $subCost += (float)$m['total_cost'];
            $grand += (float)$m['qty'];
            $grandCost += (float)$m['total_cost'];
        }
        $close();
        $what = INV_MOVE_GROUPS[$move] ?? (isset(INV_MOVE_KINDS[$move]) ? strtoupper(INV_MOVE_KINDS[$move][1]) . ': ' . INV_MOVE_KINDS[$move][0] . ' (' . INV_MOVE_KINDS[$move][2] . ')' : 'Every movement');
        return [
            'grouped' => true,
            'columns' => [['key' => 'type', 'label' => 'Type'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'],
                ['key' => 'time', 'label' => 'Time recorded', 'optional' => empty($f['timed'])], ['key' => 'num', 'label' => 'Num'],
                ['key' => 'name', 'label' => 'Name'], ['key' => 'site', 'label' => 'Site'], ['key' => 'class', 'label' => 'Class', 'optional' => true],
                ['key' => 'user', 'label' => 'Entered by', 'optional' => true], ['key' => 'move', 'label' => 'Movement'],
                ['key' => 'qty', 'label' => 'Qty', 'type' => 'qty'], ['key' => 'cost', 'label' => 'Cost', 'type' => 'money', 'sensitive' => 'cost', 'optional' => true]],
            'rows' => $rows,
            'totals' => ['type' => 'TOTAL', 'qty' => $grand, 'cost' => r2($grandCost)],
            'notes' => 'Showing: <strong>' . h($what) . '</strong>. Quantities as they moved the stock (in positive, out negative); voided transactions are left out. Click a number to open the transaction.',
        ];
    },
]);

// ------------------------------------------------------------------ Ending Inventory - POS Cashier (Point of Sale > Reports)

/** The two kinds of the report: A. item code and balance, B. item description and balance */
const POS_ENDINV_TYPES = ['code' => 'A. Item code & balance', 'desc' => 'B. Item description & balance'];

/** The cashier a user may report on alone (a cashier without the POS reports sees only their own shifts), or null */
function pos_endinv_only(): ?int
{
    return Access::page('rpt.pos_sales_cashier') ? null : (int)Auth::id();
}

Report::define('pos_ending_inventory', [
    'filters' => ['range', 'item', 'site', 'class', 'category'],
    'default_dates' => 'today',
    'times' => true,
    'sortable' => false,
    'extra_filters' => [
        'cashier_id' => ['Cashier', function () {
            $only = pos_endinv_only();
            $all = inv_move_cashiers();
            return $only ? array_intersect_key($all, [$only => 1]) + [$only => (string)(Auth::user()['full_name'] ?? 'Me')] : $all;
        }],
        'shift_id' => ['Shift', fn() => inv_move_shifts(pos_endinv_only()), 'Choose a shift (sets the times)', true, true],
        'type' => ['Report type', fn() => POS_ENDINV_TYPES, null, true],
    ],
    'checks' => ['exclude_zero' => 'Exclude inventory with zero balance'],
    'filter_hook' => function (array $f): array {
        $only = pos_endinv_only();
        // a cashier opening the report: their latest shift, from the moment it was opened to its close (or now)
        if ($only && (string)($f['shift_id'] ?? '') === '' && !isset($_GET['from'])) {
            $last = (int)DB::value('SELECT id FROM pos_shifts WHERE cashier_id = :c ORDER BY opened_at DESC LIMIT 1', ['c' => $only]);
            if ($last) {
                $f['shift_id'] = (string)$last;
            }
        }
        if (!isset(POS_ENDINV_TYPES[(string)($f['type'] ?? '')])) {
            $f['type'] = 'code';
        }
        return inv_move_cashier_hook($f, $only);
    },
    'basis' => function (array $f): string {
        $parts = ['Quantity on hand by cashier, by ' . ($f['type'] === 'desc' ? 'item description' : 'item code')];
        $parts[] = 'Cut-off: ' . Report::fdt($f['to_dt']);
        $parts[] = 'Site: ' . pos_endinv_site($f);
        if (!empty($f['cashier_id'])) {
            $parts[] = 'Cashier: ' . (inv_move_cashiers()[(int)$f['cashier_id']] ?? '');
        }
        if (!empty($f['class_id'])) {
            $parts[] = 'Class ' . Classes::name($f['class_id']);
        }
        if (($f['exclude_zero'] ?? '') === '1') {
            $parts[] = 'Zero balances left out';
        }
        return implode('. ', $parts);
    },
    'layout_defaults' => ['prepared_by' => 1, 'sign_labels' => "Inventory Turnovered by:\nInventory Received by:"],
    'pos_print' => 'pos_endinv_slip',
    'run' => function (array $f): array {
        $byDesc = $f['type'] === 'desc';
        $data = inv_move_data($f);
        // every stocked item (the zeros too, unless they are left out), and any other item that moved
        $items = [];
        foreach (inv_items($f['item_id'] ? (int)$f['item_id'] : null, $f) as $i) {
            $items[(int)$i['id']] = $i;
        }
        foreach (inv_move_items($data) as $i) {
            $items[(int)$i['id']] ??= $i;
        }
        uasort($items, fn($a, $b) => $byDesc ? strcasecmp((string)$a['name'], (string)$b['name']) : strcasecmp((string)$a['code'], (string)$b['code']));
        $rows = [];
        $tot = ['item' => 'TOTAL', 'total' => 0.0, 'beg' => 0.0, 'in' => 0.0, 'sold' => 0.0, 'out' => 0.0];
        foreach ($items as $id => $i) {
            $d = $data[$id] ?? ['beg' => 0.0, 'in' => 0.0, 'out' => 0.0, 'end' => 0.0, 'sales' => 0.0];
            $end = abs($d['end']) < 0.00001 ? 0.0 : $d['end'];
            if (($f['exclude_zero'] ?? '') === '1' && $end === 0.0) {
                continue;
            }
            $sold = -(float)($d['sales'] ?? 0.0);
            $r = [
                'item' => $byDesc ? $i['name'] : $i['code'], 'other' => $byDesc ? $i['code'] : $i['name'], 'unit' => $i['unit'] ?? '',
                'total' => $end, 'beg' => $d['beg'], 'in' => $d['in'], 'sold' => $sold, 'out' => $d['out'] - $sold,
                '_links' => ['total' => inv_move_link($f, $id, 'end', null, $end), 'beg' => inv_move_link($f, $id, 'beg', null, $d['beg']),
                    'in' => inv_move_link($f, $id, 'in', null, $d['in']), 'sold' => inv_move_link($f, $id, 'sales', null, $sold)],
                '_ref' => ['item' => $id],
            ];
            foreach (['total', 'beg', 'in', 'sold', 'out'] as $k) {
                $tot[$k] += (float)$r[$k];
            }
            $rows[] = $r;
        }
        return [
            'columns' => [['key' => 'item', 'label' => $byDesc ? 'Description' : 'Item'], ['key' => 'other', 'label' => $byDesc ? 'Item code' : 'Description', 'optional' => true],
                ['key' => 'unit', 'label' => 'Unit', 'optional' => true],
                ['key' => 'beg', 'label' => 'Beg.', 'type' => 'qty', 'optional' => true], ['key' => 'in', 'label' => 'In', 'type' => 'qty', 'optional' => true],
                ['key' => 'sold', 'label' => 'Sold', 'type' => 'qty', 'optional' => true], ['key' => 'out', 'label' => 'Other out', 'type' => 'qty', 'optional' => true],
                ['key' => 'total', 'label' => 'Total', 'type' => 'qty']],
            'rows' => $rows,
            'totals' => $tot,
            'notes' => 'The quantity on hand at the cut-off (' . h(Report::fdt($f['to_dt'])) . ') at ' . h(pos_endinv_site($f))
                . (!empty($f['_shift_label']) ? ', for the shift of ' . h($f['_shift_label']) : '')
                . '. Every sale, return, delivery, transfer and adjustment up to that moment counts; a POS sale at the moment it was rung up. '
                . '<span class="no-print">Choose <strong>Report type</strong> A (item code) or B (item description), then <strong>Print to POS printer</strong> for the slip. '
                . 'Beg., In, Sold and Other out (from the From date and time) can be added with Modify Report.</span>',
        ];
    },
]);

/** The site(s) the report is about, in words */
function pos_endinv_site(array $f): string
{
    if (!empty($f['site_id'])) {
        return (string)DB::value('SELECT name FROM sites WHERE id = :id', ['id' => (int)$f['site_id']]);
    }
    if (!empty($f['site_ids'])) {
        return implode(', ', DB::column('SELECT name FROM sites WHERE id IN (' . implode(',', array_map('intval', $f['site_ids'])) . ') ORDER BY name'));
    }
    return 'All sites';
}

/** The slip for the roll printer: the same figures, narrow, with the signature lines and the foot text of the layout */
function pos_endinv_slip(array $f, array $rows, array $visible, array $data, array $opt, string $title, bool $btOn): void
{
    $s = Settings::all();
    $byDesc = $f['type'] === 'desc';
    $width = Receipt::width();
    $mm = $width <= 32 ? 58 : 80;
    $cashier = !empty($f['cashier_id']) ? (inv_move_cashiers()[(int)$f['cashier_id']] ?? '') : '';
    $head = ['QUANTITY ON HAND BY CASHIER', $byDesc ? 'By Item Description' : 'By Item Code'];
    $info = ['Cut-off: To: ' . Report::fdt($f['to_dt']), 'Site: ' . pos_endinv_site($f)];
    if ($cashier !== '') {
        $info[] = 'Cashier: ' . $cashier;
    }
    $labels = ReportLayout::lines($opt['sign_labels'] ?? '');
    $names = ReportLayout::lines($opt['sign_names'] ?? '');
    $signs = [];
    if (!empty($opt['prepared_by'])) {
        foreach (array_slice($labels, 0, 4) as $i => $l) {
            $signs[] = [$l, $names[$i] ?? ''];
        }
    }
    $foot = ReportLayout::lines($opt['footer_text'] ?? '');
    $list = [];
    $total = 0.0;
    foreach ($rows as $r) {
        if (($r['_class'] ?? '') !== '') {
            continue;
        }
        $list[] = [(string)$r['item'], qty($r['total'])];
        $total += (float)$r['total'];
    }
    $col = $byDesc ? 'Description' : 'Item';
    $ops = $btOn ? Receipt::reportOps($head, $info, [$col, 'Total'], $list, ['TOTAL', qty($total)], $signs, $foot) : [];
    ?>
    <style>@media print { @page { size: <?= $mm ?>mm auto; margin: 2mm; } .pos-slip { width: <?= $mm - 6 ?>mm; max-width: none; } }</style>
    <div class="no-print pos-slip-bar">
      <div class="small text-muted">Report type to print</div>
      <div class="btn-group btn-group-sm pos-slip-types" role="group" aria-label="Report type">
        <?php foreach (POS_ENDINV_TYPES as $k => $lab): ?>
        <a class="btn <?= $f['type'] === $k ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= h(current_url(['type' => $k])) ?>"><?= h($lab) ?></a>
        <?php endforeach; ?>
      </div>
      <div class="d-flex flex-wrap gap-2 justify-content-center">
        <button class="btn btn-primary btn-sm" onclick="window.print()" autofocus><i class="bi bi-printer"></i> Print</button>
        <?php if ($btOn): ?><button class="btn btn-outline-primary btn-sm" type="button" data-bt-print data-bt-ops="#btReport"><i class="bi bi-bluetooth"></i> Bluetooth printer</button><?php endif; ?>
        <a class="btn btn-outline-secondary btn-sm" href="<?= h(current_url(['print' => null])) ?>">Back</a>
      </div>
    </div>
    <?php if ($btOn): ?>
    <div class="bt-note mx-auto" data-bt-status hidden></div>
    <script type="application/json" id="btPrinterOptions"><?= json_encode(Receipt::btOptions(), JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <script type="application/json" id="btReport"><?= json_encode($ops, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <?php endif; ?>
    <div class="receipt pos-slip pos-slip-<?= $mm ?>">
      <div class="text-center"><strong><?= h($s['company_name']) ?></strong><br>
        <span class="fw-bold"><?= h($head[0]) ?></span><br><?= h($head[1]) ?></div>
      <hr class="my-1">
      <div><?= implode('<br>', array_map('h', $info)) ?></div>
      <hr class="my-1">
      <table class="w-100 pos-slip-table">
        <thead><tr><th><?= h($col) ?></th><th class="text-end">Total</th></tr></thead>
        <tbody>
        <?php foreach ($list as [$name, $q]): ?><tr><td><?= h($name) ?></td><td class="text-end"><?= h($q) ?></td></tr><?php endforeach; ?>
        <?php if (!$list): ?><tr><td colspan="2" class="text-center">No items</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr><td>TOTAL</td><td class="text-end"><?= h(qty($total)) ?></td></tr></tfoot>
      </table>
      <?php foreach ($signs as [$cap, $nm]): ?>
      <div class="pos-slip-sign"><div><?= h($cap) ?></div><div class="pos-slip-line"></div><?php if ($nm !== ''): ?><div class="text-center"><?= h($nm) ?></div><?php endif; ?></div>
      <?php endforeach; ?>
      <?php if ($foot): ?><hr class="my-1"><div class="text-center"><?= implode('<br>', array_map('h', $foot)) ?></div><?php endif; ?>
      <div class="text-center pos-slip-printed">Printed <?= h(date('m/d/Y h:i A')) ?> by <?= h((string)(Auth::user()['full_name'] ?? '')) ?></div>
    </div>
    <?php
}
