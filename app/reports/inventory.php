<?php
declare(strict_types=1);

/** Inventory reports. */

/** The active stock items, one or all, narrowed by the report's item filters (category, item type, preferred supplier) */
function inv_items(?int $itemId = null, array $f = []): array
{
    $sql = "SELECT i.*, v.name AS vendor FROM items i LEFT JOIN vendors v ON v.id = i.preferred_vendor_id WHERE i.item_type IN ('inventory','assembly') AND i.is_active = 1";
    $p = [];
    if ($itemId) {
        $sql .= ' AND i.id = :i';
        $p['i'] = $itemId;
    }
    $sql .= Report::itemCond($f, 'i.id', $p);
    return DB::all($sql . ' ORDER BY i.code', $p);
}

/** The item filters every inventory report offers */
const INV_ITEM_FILTERS = ['category', 'item_type', 'pref_vendor'];

function inv_sites(?int $siteId = null): array
{
    $rows = DB::pairs('SELECT id, name FROM sites WHERE is_active = 1 ORDER BY id');
    $allowed = Access::siteIds();
    return array_filter($rows, fn($k) => (!$siteId || (int)$k === $siteId) && ($allowed === null || in_array((int)$k, $allowed, true)), ARRAY_FILTER_USE_KEY);
}

/** item_id => [qty, value] as of a date (from inventory movements) */
function inv_position(string $asof, ?int $siteId = null): array
{
    $sql = 'SELECT item_id, SUM(qty) AS qty, SUM(total_cost) AS value FROM inventory_movements WHERE txn_date <= :d';
    $p = ['d' => $asof];
    if ($siteId) {
        $sql .= ' AND site_id = :s';
        $p['s'] = $siteId;
    }
    $out = [];
    foreach (DB::all($sql . ' GROUP BY item_id', $p) as $r) {
        $out[(int)$r['item_id']] = ['qty' => (float)$r['qty'], 'value' => (float)$r['value']];
    }
    return $out;
}

Report::define('qoh_by_site', [
    'filters' => array_merge(['asof', 'site', 'item'], INV_ITEM_FILTERS),
    // leave out the items with nothing on hand at the sites shown (v29)
    'checks' => ['exclude_zero' => 'Exclude zero qty'],
    'run' => function (array $f): array {
        // a site chosen: only its column (and its total)
        $sites = inv_sites($f['site_id'] ?: null);
        $cols = [['key' => 'item', 'label' => 'Item'], ['key' => 'desc', 'label' => 'Description']];
        foreach ($sites as $sid => $sn) {
            $cols[] = ['key' => 's' . $sid, 'label' => $sn, 'type' => 'qty'];
        }
        $cols[] = ['key' => 'total', 'label' => 'Total', 'type' => 'qty'];
        $stock = [];
        if ($f['asof'] >= today()) {
            // today (or later): the quantities on hand now
            foreach (DB::all('SELECT item_id, site_id, qty_on_hand FROM item_site_stock') as $s) {
                $stock[(int)$s['item_id']][(int)$s['site_id']] = (float)$s['qty_on_hand'];
            }
        } else {
            // an earlier date: every movement up to and including that day, site by site
            foreach (DB::all('SELECT item_id, site_id, SUM(qty) AS q FROM inventory_movements WHERE txn_date <= :d GROUP BY item_id, site_id', ['d' => $f['asof']]) as $s) {
                $stock[(int)$s['item_id']][(int)$s['site_id']] = (float)$s['q'];
            }
        }
        $rows = [];
        $tot = ['item' => 'TOTAL', 'total' => 0.0];
        foreach (inv_items($f['item_id'], $f) as $i) {
            $r = ['item' => $i['code'], 'desc' => $i['name'], 'total' => 0.0, '_links' => ['item' => Report::link('inv.center', ['id' => $i['id']])], '_ref' => ['item' => (int)$i['id']]];
            foreach ($sites as $sid => $_) {
                $q = $stock[(int)$i['id']][$sid] ?? 0.0;
                $r['s' . $sid] = $q;
                $r['total'] += $q;
                $tot['s' . $sid] = ($tot['s' . $sid] ?? 0) + $q;
            }
            if (($f['exclude_zero'] ?? '') === '1') {
                $any = false;
                foreach ($sites as $sid => $_) {
                    $any = $any || abs((float)$r['s' . $sid]) >= 0.00001;
                }
                if (!$any) {
                    // nothing on hand anywhere shown: it adds nothing to the totals either
                    continue;
                }
            }
            $tot['total'] += $r['total'];
            $rows[] = $r;
        }
        return ['columns' => $cols, 'rows' => $rows, 'totals' => $tot,
            'notes' => ($f['exclude_zero'] ?? '') === '1' ? 'Items with no quantity on hand at the sites shown are left out.' : ''];
    },
]);

Report::define('inv_valuation_summary', [
    'filters' => array_merge(['asof', 'site', 'item'], INV_ITEM_FILTERS),
    'filter_labels' => ['site' => 'Inventory site'],
    'run' => function (array $f): array {
        $siteId = $f['site_id'] ? (int)$f['site_id'] : null;
        if ($siteId && !isset(inv_sites($siteId)[$siteId])) {
            $siteId = null;      // a site the user may not see is ignored
        }
        $all = inv_position($f['asof']);
        // one site: its quantity, valued at the item's weighted average cost of all sites (costs are averaged
        // across the sites, as on Inventory Valuation Summary by Site)
        $pos = $all;
        if ($siteId) {
            $pos = [];
            foreach (inv_position($f['asof'], $siteId) as $itemId => $p) {
                $tot = $all[$itemId] ?? ['qty' => 0.0, 'value' => 0.0];
                $unit = abs($tot['qty']) > 0.00001 ? $tot['value'] / $tot['qty'] : null;
                $pos[$itemId] = ['qty' => $p['qty'], 'value' => $unit !== null ? r2($p['qty'] * $unit) : $p['value'], 'unit' => $unit];
            }
        }
        $rows = [];
        $totVal = 0.0;
        $totRetail = 0.0;
        $items = inv_items($f['item_id'], $f);
        foreach ($items as $i) {
            $p = $pos[(int)$i['id']] ?? ['qty' => 0.0, 'value' => 0.0];
            $totVal += $p['value'];
            $totRetail += $p['qty'] * (float)$i['sales_price'];
        }
        foreach ($items as $i) {
            $p = $pos[(int)$i['id']] ?? ['qty' => 0.0, 'value' => 0.0];
            if (abs($p['qty']) < 0.00001 && abs($p['value']) < 0.005) {
                continue;
            }
            $retail = $p['qty'] * (float)$i['sales_price'];
            $avg = isset($p['unit']) && $p['unit'] !== null ? round($p['unit'], 4) : (abs($p['qty']) > 0.00001 ? round($p['value'] / $p['qty'], 4) : '');
            $rows[] = [
                'item' => $i['code'], 'desc' => $i['name'], 'qty' => $p['qty'],
                'avg' => $avg, 'value' => r2($p['value']),
                'pct' => $totVal ? round($p['value'] / $totVal * 100, 1) : 0, 'price' => (float)$i['sales_price'], 'retail' => r2($retail),
                'pct_retail' => $totRetail ? round($retail / $totRetail * 100, 1) : 0,
                '_links' => ['item' => Report::link('rpt.inv_valuation_detail', ['item_id' => $i['id'], 'from' => '2000-01-01', 'to' => $f['asof'], 'dates' => 'custom', 'site_id' => $siteId])],
            ];
        }
        $notes = '';
        if ($siteId) {
            $notes = 'Site: <strong>' . h(inv_sites($siteId)[$siteId]) . '</strong>. The quantities are this site\'s; each is valued at the item\'s weighted average cost of all sites (costs are averaged across the sites).';
        } elseif (Access::viewCosts()) {
            $assetIds = array_unique(array_filter(array_map('intval', array_merge(DB::column("SELECT DISTINCT asset_account_id FROM items WHERE asset_account_id IS NOT NULL"), [Accts::get('inventory_asset')]))));
            [$in, $pp] = DB::in('ia', $assetIds ?: [0]);
            $gl = r2(DB::value("SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE account_id IN $in AND txn_date <= :d", $pp + ['d' => $f['asof']]));
            $notes = 'Inventory asset account(s) per ledger: <strong>' . money($gl) . '</strong>' . (abs($gl - $totVal) < 0.01 ? ' — agrees with the total asset value.' : ' — <span class="text-danger">differs (check journal entries posted directly to the inventory account).</span>');
        }
        return [
            'columns' => [['key' => 'item', 'label' => 'Item'], ['key' => 'desc', 'label' => 'Description'], ['key' => 'qty', 'label' => 'On hand', 'type' => 'qty'],
                ['key' => 'avg', 'label' => 'Avg cost', 'type' => 'money', 'sensitive' => 'cost'], ['key' => 'value', 'label' => 'Asset value', 'type' => 'money', 'sensitive' => 'cost'],
                ['key' => 'pct', 'label' => '% of tot asset', 'type' => 'pct', 'sensitive' => 'cost'], ['key' => 'price', 'label' => 'Sales price', 'type' => 'money'],
                ['key' => 'retail', 'label' => 'Retail value', 'type' => 'money'], ['key' => 'pct_retail', 'label' => '% of tot retail', 'type' => 'pct']],
            'rows' => $rows,
            'totals' => ['item' => 'TOTAL', 'value' => r2($totVal), 'pct' => 100, 'retail' => r2($totRetail), 'pct_retail' => 100],
            'notes' => $notes,
        ];
    },
]);

Report::define('inv_valuation_site', [
    'filters' => array_merge(['asof', 'site', 'item'], INV_ITEM_FILTERS),
    'sortable' => false,
    'run' => function (array $f): array {
        $all = inv_position($f['asof']);
        $rows = [];
        $grand = 0.0;
        foreach (inv_sites($f['site_id']) as $sid => $sn) {
            $pos = inv_position($f['asof'], (int)$sid);
            $rows[] = ['item' => $sn, '_class' => 'group-row'];
            $sub = 0.0;
            foreach (inv_items($f['item_id'], $f) as $i) {
                $p = $pos[(int)$i['id']] ?? null;
                if (!$p || abs($p['qty']) < 0.00001) {
                    continue;
                }
                $tot = $all[(int)$i['id']] ?? ['qty' => 0.0, 'value' => 0.0];
                $unit = abs($tot['qty']) > 0.00001 ? $tot['value'] / $tot['qty'] : (float)$i['avg_cost'];
                $val = r2($p['qty'] * $unit);
                $rows[] = ['item' => $i['code'], 'desc' => $i['name'], 'qty' => $p['qty'], 'avg' => round($unit, 4), 'value' => $val, 'retail' => r2($p['qty'] * (float)$i['sales_price']), '_class' => 'indent-1', '_ref' => ['item' => (int)$i['id']]];
                $sub += $val;
            }
            $rows[] = ['item' => 'Total ' . $sn, 'value' => r2($sub), '_class' => 'subtotal-row'];
            $grand += $sub;
        }
        return [
            'grouped' => true,
            'columns' => [['key' => 'item', 'label' => 'Site / item'], ['key' => 'desc', 'label' => 'Description'], ['key' => 'qty', 'label' => 'On hand', 'type' => 'qty'],
                ['key' => 'avg', 'label' => 'Avg cost', 'type' => 'money', 'sensitive' => 'cost'], ['key' => 'value', 'label' => 'Asset value', 'type' => 'money', 'sensitive' => 'cost'],
                ['key' => 'retail', 'label' => 'Retail value', 'type' => 'money']],
            'rows' => $rows, 'totals' => ['item' => 'TOTAL', 'value' => r2($grand)],
            'notes' => 'Site values use each item\'s weighted average cost (costs are averaged across all sites).',
        ];
    },
]);

Report::define('inv_valuation_detail', [
    'filters' => array_merge(['range', 'item', 'site'], INV_ITEM_FILTERS),
    'default_dates' => 'this_month',
    'sortable' => false,
    'run' => function (array $f): array {
        $rows = [];
        foreach (inv_items($f['item_id'], $f) as $i) {
            $siteSql = $f['site_id'] ? ' AND site_id = :s' : '';
            $p = ['i' => $i['id'], 'f' => $f['from']];
            if ($f['site_id']) {
                $p['s'] = $f['site_id'];
            }
            $open = DB::row("SELECT COALESCE(SUM(qty),0) AS q, COALESCE(SUM(total_cost),0) AS v FROM inventory_movements WHERE item_id = :i AND txn_date < :f $siteSql", $p);
            $p2 = ['i' => $i['id'], 'f' => $f['from'], 't' => $f['to']] + ($f['site_id'] ? ['s' => $f['site_id']] : []);
            $moves = DB::all("SELECT m.*, t.txn_type, t.txn_no, t.id AS txn_id, COALESCE(c.name, v.name, t.payee_name) AS party, s.code AS site
                                FROM inventory_movements m JOIN transactions t ON t.id = m.txn_id JOIN sites s ON s.id = m.site_id
                                LEFT JOIN customers c ON c.id = t.customer_id LEFT JOIN vendors v ON v.id = t.vendor_id
                               WHERE m.item_id = :i AND m.txn_date BETWEEN :f AND :t " . str_replace('site_id', 'm.site_id', $siteSql) . ' ORDER BY m.txn_date, m.id', $p2);
            if (!$moves && abs((float)$open['q']) < 0.00001) {
                continue;
            }
            // v30: on one date, the stock received before the stock used or sold (as the Inventory Center and the rebuild)
            $rk = Stock::dayRanks(array_column($moves, 'txn_id'));
            usort($moves, fn($a, $b) => [$a['txn_date'], $rk[(int)$a['txn_id']] ?? 1, (int)$a['txn_id'], (int)$a['id']] <=> [$b['txn_date'], $rk[(int)$b['txn_id']] ?? 1, (int)$b['txn_id'], (int)$b['id']]);
            $q = (float)$open['q'];
            $v = (float)$open['v'];
            $rows[] = ['type' => $i['code'] . ' - ' . $i['name'], '_class' => 'group-row'];
            $rows[] = ['type' => 'Opening', 'onhand' => $q, 'avg' => abs($q) > 0.00001 ? round($v / $q, 4) : '', 'assetval' => r2($v)];
            foreach ($moves as $m) {
                $q += (float)$m['qty'];
                $v += (float)$m['total_cost'];
                $rows[] = [
                    'type' => Txn::label($m['txn_type']), 'date' => $m['txn_date'], 'num' => $m['txn_no'], 'name' => $m['party'], 'site' => $m['site'],
                    'qty' => (float)$m['qty'], 'cost' => r2($m['total_cost']), 'onhand' => $q, 'avg' => abs($q) > 0.00001 ? round($v / $q, 4) : '', 'assetval' => r2($v),
                    '_links' => ['num' => Report::txnLink($m)],
                ];
            }
            $rows[] = ['type' => 'Total ' . $i['code'], 'onhand' => $q, 'assetval' => r2($v), '_class' => 'subtotal-row'];
        }
        return [
            'grouped' => true,
            'columns' => [['key' => 'type', 'label' => 'Type'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'num', 'label' => 'Num'], ['key' => 'name', 'label' => 'Name'],
                ['key' => 'site', 'label' => 'Site'], ['key' => 'qty', 'label' => 'Qty', 'type' => 'qty'], ['key' => 'cost', 'label' => 'Cost', 'type' => 'money', 'sensitive' => 'cost'],
                ['key' => 'onhand', 'label' => 'On hand', 'type' => 'qty'], ['key' => 'avg', 'label' => 'Avg cost', 'type' => 'money', 'sensitive' => 'cost'],
                ['key' => 'assetval', 'label' => 'Asset value', 'type' => 'money', 'sensitive' => 'cost']],
            'rows' => $rows,
        ];
    },
]);

// ---------------------------------------------------------------------- v31: what moved the average cost

/**
 * Average Cost Changes (v31): every movement that changed an item's weighted average cost (all sites), in the order the
 * costs were worked out, with the average before and after, the change and what caused it. Made to find the sudden
 * jumps: a processing order or bill received while the item was below zero, a disassembly whose outputs carry a share of
 * the cost far from their average, a back-dated entry, a value adjustment.
 */
Report::define('avg_cost_changes', [
    'filters' => array_merge(['range', 'item'], INV_ITEM_FILTERS),
    'default_dates' => 'this_month',
    'sortable' => false,
    'extra_filters' => [
        'min_pct' => ['Change of at least', fn() => ['1' => '1%', '5' => '5%', '10' => '10%', '25' => '25%', '50' => '50%', '100' => '100%'], 'Any change'],
    ],
    'checks' => ['proc_only' => 'Processing, disassembly and builds only'],
    'run' => function (array $f): array {
        $min = ($f['min_pct'] ?? '') !== '' ? (float)$f['min_pct'] : 0.0;
        $procTypes = [Processing::TXN_TYPE, 'build_assembly'];
        $rows = [];
        $count = 0;
        $byCause = [];
        foreach (inv_items($f['item_id'], $f) as $i) {
            $moves = DB::all(
                'SELECT m.*, t.txn_type, t.txn_no, s.code AS site FROM inventory_movements m JOIN transactions t ON t.id = m.txn_id JOIN sites s ON s.id = m.site_id
                  WHERE m.item_id = :i AND m.txn_date <= :t ORDER BY m.id',
                ['i' => $i['id'], 't' => $f['to']]
            );
            $q = 0.0;
            $prevAvg = null;
            $latest = '';
            $out = [];
            foreach ($moves as $m) {
                $mq = (float)$m['qty'];
                $before = $q;
                $q += $mq;
                $after = (float)$m['avg_after'];
                $was = $prevAvg;
                $prevAvg = $after;
                $backdated = $latest !== '' && $m['txn_date'] < $latest;
                $latest = max($latest, (string)$m['txn_date']);
                if ($m['txn_date'] < $f['from'] || $was === null) {
                    continue;
                }
                $change = round($after - $was, 4);
                if (abs($change) < 0.00005) {
                    continue;
                }
                $pct = $was > 0.00005 ? round($change / $was * 100, 2) : null;
                if ($min > 0 && $pct !== null && abs($pct) < $min) {
                    continue;
                }
                $isProc = in_array($m['txn_type'], $procTypes, true);
                if (($f['proc_only'] ?? '') === '1' && !$isProc) {
                    continue;
                }
                if (abs($mq) < 0.00005) {
                    $cause = $m['txn_type'] === 'inventory_adjustment' ? 'Value adjustment' : 'Stock sold below zero, settled at the cost received';
                } elseif ($mq > 0) {
                    if ($before < -0.00005) {
                        $cause = 'Received while ' . qty(-$before) . ' short (below zero); the shortfall was settled at this cost';
                    } elseif ($m['txn_type'] === Processing::TXN_TYPE) {
                        $cause = 'Processing output at its share of the cost';
                    } elseif ($m['txn_type'] === 'build_assembly') {
                        $cause = 'Built at the cost of its components';
                    } else {
                        $cause = 'Received at a cost different from the average';
                    }
                } else {
                    $cause = $q < 0.00005 ? 'The last of the stock went out with the value left' : 'Taken out at a cost different from the average';
                }
                if ($backdated) {
                    $cause = 'Entered after later-dated movements; ' . lcfirst($cause);
                }
                $byCause[$isProc ? 'proc' : 'other'] = ($byCause[$isProc ? 'proc' : 'other'] ?? 0) + 1;
                $out[] = [
                    'type' => Txn::label($m['txn_type']), 'date' => $m['txn_date'], 'num' => $m['txn_no'], 'site' => $m['site'],
                    'qty' => $mq, 'unit' => abs($mq) >= 0.00005 ? (float)$m['unit_cost'] : '', 'before_qty' => round($before, 4),
                    'avg_before' => $was, 'avg_after' => $after, 'change' => $change, 'pct' => $pct ?? '', 'cause' => $cause,
                    '_links' => ['num' => Report::txnLink(['txn_id' => $m['txn_id'], 'txn_type' => $m['txn_type']])],
                    '_class' => $pct !== null && abs($pct) >= 25 ? 'avg-jump' : '',
                ];
            }
            if ($out) {
                $rows[] = ['type' => $i['code'] . ' - ' . $i['name'], '_class' => 'group-row'];
                array_push($rows, ...$out);
                $rows[] = ['type' => 'Average now ' . $i['code'], 'avg_after' => (float)$i['avg_cost'], '_class' => 'subtotal-row'];
                $count += count($out);
            }
        }
        return [
            'grouped' => true,
            'columns' => [['key' => 'type', 'label' => 'Type'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'num', 'label' => 'Num'],
                ['key' => 'site', 'label' => 'Site'], ['key' => 'qty', 'label' => 'Qty', 'type' => 'qty'],
                ['key' => 'unit', 'label' => 'Unit cost', 'type' => 'money', 'sensitive' => 'cost'],
                ['key' => 'before_qty', 'label' => 'On hand before', 'type' => 'qty'],
                ['key' => 'avg_before', 'label' => 'Avg before', 'type' => 'money', 'sensitive' => 'cost'],
                ['key' => 'avg_after', 'label' => 'Avg after', 'type' => 'money', 'sensitive' => 'cost'],
                ['key' => 'change', 'label' => 'Change', 'type' => 'money', 'sensitive' => 'cost'],
                ['key' => 'pct', 'label' => 'Change %', 'type' => 'pct', 'sensitive' => 'cost'],
                ['key' => 'cause', 'label' => 'What moved it']],
            'rows' => $rows,
            'notes' => $count . ' change' . ($count === 1 ? '' : 's') . ($byCause ? ' (' . (int)($byCause['proc'] ?? 0) . ' by processing orders, disassemblies and builds)' : '') . '. '
                . 'The average cost of an item is its remaining value divided by its remaining quantity (all sites), worked out in the order the transactions were entered. '
                . 'It moves when stock comes in at another cost: a bill, an item receipt, the outputs of a processing order or disassembly (each output takes its share of the cost of the inputs), a build. '
                . 'Changes of 25% or more are shown in red. A back-dated entry is costed at the average of the day it was entered, not of its date: '
                . 'run Utilities: Verify &amp; Rebuild Data (Rebuild data) to work every cost out again in date order.',
        ];
    },
]);

/** Stock status figures for one item (optionally one site) over a sales window */
function stock_status_row(array $i, ?int $siteId, string $from, string $to): array
{
    $onHand = Stock::onHand((int)$i['id'], $siteId);
    $so = Stock::onSalesOrder((int)$i['id'], $siteId);
    $po = Stock::onPurchaseOrder((int)$i['id'], $siteId);
    $next = DB::value(
        "SELECT MIN(t.due_date) FROM transaction_lines l JOIN transactions t ON t.id = l.txn_id
          WHERE l.item_id = :i AND t.txn_type = 'purchase_order' AND t.status IN ('open','partial') AND t.is_voided = 0 AND l.is_closed = 0 AND l.qty > l.qty_fulfilled"
        . ($siteId ? ' AND COALESCE(l.site_id, t.site_id) = :s' : ''),
        ['i' => $i['id']] + ($siteId ? ['s' => $siteId] : [])
    );
    $sold = -(float)DB::value(
        "SELECT COALESCE(SUM(m.qty),0) FROM inventory_movements m JOIN transactions t ON t.id = m.txn_id
          WHERE m.item_id = :i AND t.txn_type IN ('invoice','sales_receipt','pos_sale','credit_memo','pos_return') AND m.txn_date BETWEEN :f AND :t" . ($siteId ? ' AND m.site_id = :s' : ''),
        ['i' => $i['id'], 'f' => $from, 't' => $to] + ($siteId ? ['s' => $siteId] : [])
    );
    $weeks = max(1, (strtotime($to) - strtotime($from)) / 604800);
    $avail = $onHand - $so;
    $point = (float)($i['item_type'] === 'assembly' ? max((float)$i['reorder_point'], (float)$i['build_point']) : $i['reorder_point']);
    $order = $point > 0 && ($avail + $po) <= $point;
    return [
        'item' => $i['code'], 'desc' => $i['name'], 'vendor' => $i['vendor'], 'reorder' => $point ?: '', 'onhand' => $onHand, 'onso' => $so, 'avail' => $avail,
        'order' => $order ? ($i['item_type'] === 'assembly' ? 'Build' : 'Reorder') : '', 'suggest' => $order ? max(0, (float)$i['max_stock'] - $avail - $po) ?: '' : '',
        'onpo' => $po, 'next' => $next, 'perweek' => round($sold / $weeks, 2),
        '_links' => ['item' => Report::link('inv.center', ['id' => $i['id']])],
        '_class' => $order ? 'table-warning' : '',
    ];
}

function stock_status_cols(): array
{
    return [['key' => 'item', 'label' => 'Item'], ['key' => 'desc', 'label' => 'Description'], ['key' => 'vendor', 'label' => 'Pref. vendor'], ['key' => 'reorder', 'label' => 'Reorder pt', 'type' => 'qty'],
        ['key' => 'onhand', 'label' => 'On hand', 'type' => 'qty'], ['key' => 'onso', 'label' => 'On sales order', 'type' => 'qty'], ['key' => 'avail', 'label' => 'Available', 'type' => 'qty'],
        ['key' => 'order', 'label' => 'Order / build'], ['key' => 'suggest', 'label' => 'Suggested qty', 'type' => 'qty'], ['key' => 'onpo', 'label' => 'On PO', 'type' => 'qty'],
        ['key' => 'next', 'label' => 'Next delivery', 'type' => 'date'], ['key' => 'perweek', 'label' => 'Sales / week', 'type' => 'qty']];
}

Report::define('stock_status_item', [
    'filters' => array_merge(['range', 'site', 'item'], INV_ITEM_FILTERS),
    'default_dates' => 'this_quarter',
    'run' => function (array $f): array {
        $rows = array_map(fn($i) => stock_status_row($i, $f['site_id'] ?: null, $f['from'], $f['to']), inv_items($f['item_id'], $f));
        $footer = Access::can('vend.purchase_orders', 'create') ? '<a class="btn btn-sm btn-success no-print" href="' . h(url('vend.purchase_orders', ['a' => 'new'])) . '">Create purchase order</a>' : '';
        return ['columns' => stock_status_cols(), 'rows' => $rows, 'footer' => $footer,
            'notes' => 'Highlighted items are at or below their reorder (build) point after counting what is already on order. Sales per week uses the selected date range.'];
    },
]);

Report::define('stock_status_vendor', [
    'filters' => ['range', 'vendor', 'site', 'category', 'item_type'],
    'default_dates' => 'this_quarter',
    'sortable' => false,
    'run' => function (array $f): array {
        $items = inv_items(null, $f);
        usort($items, fn($a, $b) => [(string)$a['vendor'], $a['code']] <=> [(string)$b['vendor'], $b['code']]);
        $rows = [];
        $cur = false;
        foreach ($items as $i) {
            if ($f['vendor_id'] && (int)$i['preferred_vendor_id'] !== $f['vendor_id']) {
                continue;
            }
            if ($i['vendor'] !== $cur) {
                $po = $i['preferred_vendor_id'] && Access::can('vend.purchase_orders', 'create')
                    ? ' <a class="no-print small" href="' . h(url('vend.purchase_orders', ['a' => 'new', 'vendor_id' => $i['preferred_vendor_id']])) . '">create PO</a>' : '';
                $rows[] = ['item' => $i['vendor'] ?: '(no preferred vendor)', '_class' => 'group-row', '_html' => ['item' => '<strong>' . h($i['vendor'] ?: '(no preferred vendor)') . '</strong>' . $po]];
                $cur = $i['vendor'];
            }
            $rows[] = stock_status_row($i, $f['site_id'] ?: null, $f['from'], $f['to']);
        }
        return ['grouped' => true, 'columns' => stock_status_cols(), 'rows' => $rows];
    },
]);

Report::define('stock_status_site', [
    'filters' => array_merge(['range', 'site', 'item'], INV_ITEM_FILTERS),
    'default_dates' => 'this_quarter',
    'sortable' => false,
    'run' => function (array $f): array {
        $rows = [];
        foreach (inv_items($f['item_id'], $f) as $i) {
            $rows[] = ['item' => $i['code'] . ' - ' . $i['name'], '_class' => 'group-row'];
            foreach (inv_sites($f['site_id']) as $sid => $sn) {
                $r = stock_status_row($i, (int)$sid, $f['from'], $f['to']);
                if (abs($r['onhand']) < 0.00001 && abs($r['onso']) < 0.00001 && abs($r['onpo']) < 0.00001) {
                    continue;
                }
                $r['item'] = $sn;
                $r['desc'] = '';
                $r['order'] = $r['avail'] < 0 ? 'Short' : '';
                $r['reorder'] = '';
                $r['suggest'] = '';
                $r['_class'] = $r['avail'] < 0 ? 'table-warning indent-1' : 'indent-1';
                $rows[] = $r;
            }
        }
        $cols = stock_status_cols();
        $cols[0]['label'] = 'Item / site';
        return ['grouped' => true, 'columns' => $cols, 'rows' => $rows,
            'notes' => 'Sites where committed orders exceed stock are highlighted — cover them by transferring from another site or ordering more.'];
    },
]);

Report::define('physical_worksheet', [
    'filters' => array_merge(['asof', 'site'], INV_ITEM_FILTERS, ['blind']),
    'run' => function (array $f): array {
        $blind = $f['blind'] === '1';
        $asof = $f['asof'];
        $siteId = $f['site_id'] ? (int)$f['site_id'] : null;
        // the system quantity: on hand now for today (or later), or every movement up to and including an earlier day
        $past = $asof < today();
        $pos = $past ? inv_position($asof, $siteId) : [];
        $rows = [];
        foreach (inv_items(null, $f) as $i) {
            $id = (int)$i['id'];
            $sys = $past ? (float)($pos[$id]['qty'] ?? 0) : Stock::onHand($id, $siteId);
            $rows[] = ['item' => $i['code'], 'desc' => $i['name'], 'vendor' => $i['vendor'], 'unit' => $i['unit'],
                'onhand' => $blind ? '' : $sys, 'count' => '', '_html' => ['count' => '<span style="display:inline-block;width:90px;border-bottom:1px solid #333">&nbsp;</span>'],
                '_ref' => ['item' => $id]];
        }
        return [
            'columns' => [['key' => 'item', 'label' => 'Item'], ['key' => 'desc', 'label' => 'Description'], ['key' => 'vendor', 'label' => 'Pref. vendor'], ['key' => 'unit', 'label' => 'Unit'],
                ['key' => 'onhand', 'label' => 'On hand (system)', 'type' => 'qty'], ['key' => 'count', 'label' => 'Physical count']],
            'rows' => $rows,
            'notes' => ($siteId ? 'Site: ' . h(DB::value('SELECT name FROM sites WHERE id = :id', ['id' => $siteId])) . '. ' : 'All sites. ')
                . ($blind ? 'Blind count: system quantities are hidden.' : 'System quantities as of ' . h(fdate($asof)) . ($past ? ' (every movement up to the end of that day).' : '.'))
                . ' Counted by: ____________ Date: ____________',
            'footer' => Access::can('inv.physical_count', 'create') ? '<a class="btn btn-sm btn-primary no-print" href="' . h(url('inv.physical_count', ['site_id' => $siteId])) . '">Enter counts</a>' : '',
        ];
    },
]);

function pending_build_rows(?int $siteId, bool $bySite, array $f = []): array
{
    $sql = "SELECT t.*, t.id AS txn_id, s.name AS site, l.qty, i.code, i.name AS item_name FROM transactions t
              JOIN transaction_lines l ON l.txn_id = t.id AND l.line_kind = 'item' JOIN items i ON i.id = l.item_id
              LEFT JOIN sites s ON s.id = t.site_id
             WHERE t.txn_type = 'build_assembly' AND t.is_pending = 1 AND t.is_voided = 0";
    $p = [];
    if ($siteId) {
        $sql .= ' AND t.site_id = :s';
        $p['s'] = $siteId;
    }
    if (!empty($f['item_id'])) {
        $sql .= ' AND l.item_id = :pi';
        $p['pi'] = (int)$f['item_id'];
    }
    $sql .= Report::itemCond($f, 'l.item_id', $p);
    $data = DB::all($sql . ($bySite ? ' ORDER BY s.name, t.txn_date' : ' ORDER BY t.txn_date'), $p);
    $rows = [];
    $cur = null;
    foreach ($data as $d) {
        if ($bySite && $d['site'] !== $cur) {
            $rows[] = ['date' => $d['site'], '_class' => 'group-row'];
            $cur = $d['site'];
        }
        $short = [];
        foreach (DB::all("SELECT l.qty, i.code, i.item_type, l.item_id FROM transaction_lines l JOIN items i ON i.id = l.item_id WHERE l.txn_id = :t AND l.line_kind = 'component'", ['t' => $d['id']]) as $c) {
            if (in_array($c['item_type'], ['inventory', 'assembly'], true)) {
                $have = Stock::onHand((int)$c['item_id'], (int)$d['site_id']);
                if ($have < (float)$c['qty']) {
                    $short[] = $c['code'] . ' (short ' . qty((float)$c['qty'] - $have) . ')';
                }
            }
        }
        $rows[] = ['date' => $d['txn_date'], 'num' => $d['txn_no'], 'item' => $d['code'] . ' - ' . $d['item_name'], 'site' => $d['site'], 'qty' => (float)$d['qty'],
            'short' => $short ? implode(', ', $short) : 'Ready to build', 'memo' => $d['memo'], '_links' => ['num' => Report::txnLink($d)]];
    }
    return $rows;
}

$pbCols = [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'num', 'label' => 'Build ref.'], ['key' => 'item', 'label' => 'Assembly'], ['key' => 'site', 'label' => 'Site'],
    ['key' => 'qty', 'label' => 'Qty to build', 'type' => 'qty'], ['key' => 'short', 'label' => 'Missing components'], ['key' => 'memo', 'label' => 'Memo']];
Report::define('pending_builds', [
    'filters' => ['site', 'item', 'category'],
    'run' => fn(array $f) => ['columns' => $pbCols, 'rows' => pending_build_rows($f['site_id'], false, $f), 'notes' => 'Builds saved as pending because components were short.'],
]);
Report::define('pending_builds_site', [
    'filters' => ['site', 'item', 'category'],
    'sortable' => false,
    'run' => fn(array $f) => ['grouped' => true, 'columns' => $pbCols, 'rows' => pending_build_rows($f['site_id'], true, $f)],
]);

Report::define('negative_inventory', [
    'filters' => array_merge(['asof', 'site', 'item'], INV_ITEM_FILTERS),
    'run' => function (array $f): array {
        $asof = $f['asof'];
        $past = $asof < today();
        $p = [];
        if ($past) {
            // an earlier date: the quantity left by every movement up to and including that day, item by item and site by site
            $sql = 'SELECT x.item_id, x.site_id, x.qty_on_hand, i.code, i.name, s.name AS site
                      FROM (SELECT item_id, site_id, SUM(qty) AS qty_on_hand FROM inventory_movements WHERE txn_date <= :d GROUP BY item_id, site_id) x
                      JOIN items i ON i.id = x.item_id JOIN sites s ON s.id = x.site_id WHERE x.qty_on_hand < -0.00001';
            $p['d'] = $asof;
        } else {
            $sql = 'SELECT x.*, i.code, i.name, s.name AS site FROM item_site_stock x JOIN items i ON i.id = x.item_id JOIN sites s ON s.id = x.site_id WHERE x.qty_on_hand < 0';
        }
        if ($f['site_id']) {
            $sql .= ' AND x.site_id = :s';
            $p['s'] = $f['site_id'];
        }
        if ($f['item_id']) {
            $sql .= ' AND x.item_id = :i';
            $p['i'] = $f['item_id'];
        }
        $sql .= Report::itemCond($f, 'x.item_id', $p);
        $rows = [];
        foreach (DB::all($sql . ' ORDER BY i.code, s.name', $p) as $r) {
            $last = DB::row("SELECT t.id AS txn_id, t.txn_type, t.txn_no, m.txn_date FROM inventory_movements m JOIN transactions t ON t.id = m.txn_id
                              WHERE m.item_id = :i AND m.site_id = :s AND m.qty < 0 AND m.txn_date <= :d ORDER BY m.txn_date DESC, m.id DESC LIMIT 1",
                ['i' => $r['item_id'], 's' => $r['site_id'], 'd' => $past ? $asof : '9999-12-31']);
            $rows[] = ['item' => $r['code'], 'desc' => $r['name'], 'site' => $r['site'], 'qty' => (float)$r['qty_on_hand'],
                'last' => $last ? Txn::label($last['txn_type']) . ' ' . $last['txn_no'] . ' (' . fdate($last['txn_date']) . ')' : '',
                '_links' => ['last' => $last ? Report::txnLink($last) : null, 'item' => Report::link('inv.center', ['id' => $r['item_id']])]];
        }
        return [
            'columns' => [['key' => 'item', 'label' => 'Item'], ['key' => 'desc', 'label' => 'Description'], ['key' => 'site', 'label' => 'Site'], ['key' => 'qty', 'label' => 'On hand', 'type' => 'qty'],
                ['key' => 'last', 'label' => 'Last issue']],
            'rows' => $rows,
            'notes' => 'Quantities as of ' . h(fdate($asof)) . '. Negative quantities distort average cost and cost of goods sold. Record the missing item receipt, transfer or adjustment for each item.',
        ];
    },
]);

// ---------------------------------------------------------------------- items that have not moved

Report::define('inv_no_movement', [
    'filters' => ['asof', 'site', 'item', 'category', 'item_type', 'pref_vendor'],
    'filter_labels' => ['site' => 'Inventory site'],
    'extra_filters' => [
        'min_days' => ['Not sold for at least', fn() => ['30' => '30 days', '60' => '60 days', '90' => '90 days', '180' => '180 days', '365' => '1 year'], 'Any number of days'],
        'stock' => ['Items', fn() => ['in_stock' => 'Only items in stock', 'all' => 'All items, in stock or not'], null],
    ],
    'run' => function (array $f): array {
        $asof = $f['asof'];
        $siteId = $f['site_id'] ? (int)$f['site_id'] : null;
        if ($siteId && !isset(inv_sites($siteId)[$siteId])) {
            $siteId = null;
        }
        $minDays = (int)($f['min_days'] ?? 0);
        $inStockOnly = ($f['stock'] ?? '') !== 'all';
        // per item: the last sale to a customer, the last movement of any kind, and the quantity, all up to the date
        $sql = "SELECT m.item_id,
                       MAX(CASE WHEN t.txn_type IN ('invoice','sales_receipt','pos_sale') AND m.qty < 0 THEN m.txn_date END) AS last_sale,
                       MAX(m.txn_date) AS last_move, MIN(m.txn_date) AS first_move, SUM(m.qty) AS qty
                  FROM inventory_movements m JOIN transactions t ON t.id = m.txn_id
                 WHERE m.txn_date <= :d";
        $p = ['d' => $asof];
        if ($siteId) {
            $sql .= ' AND m.site_id = :s';
            $p['s'] = $siteId;
        }
        $mv = [];
        foreach (DB::all($sql . ' GROUP BY m.item_id', $p) as $r) {
            $mv[(int)$r['item_id']] = $r;
        }
        $all = inv_position($asof);
        $cats = Lookup::categories();
        $days = fn(?string $d): ?int => $d ? (int)round((strtotime($asof) - strtotime($d)) / 86400) : null;
        $rows = [];
        $tot = ['item' => 'TOTAL', 'qty' => 0.0, 'value' => 0.0];
        foreach (inv_items($f['item_id'], $f) as $i) {
            $m = $mv[(int)$i['id']] ?? null;
            $qty = $m ? (float)$m['qty'] : 0.0;
            if ($inStockOnly && $qty <= 0.00001) {
                continue;
            }
            $notSold = $m && $m['last_sale'] ? $days($m['last_sale']) : null;
            // never sold: counted from the day the item first came in (or from when it was added)
            $since = $m && $m['last_sale'] ? $m['last_sale'] : ($m['first_move'] ?? substr((string)$i['created_at'], 0, 10));
            $count = $days($since);
            if ($count !== null && $count < 0) {
                $count = 0;
            }
            if ($minDays && ($count === null || $count < $minDays)) {
                continue;
            }
            $tq = $all[(int)$i['id']] ?? ['qty' => 0.0, 'value' => 0.0];
            $unit = abs($tq['qty']) > 0.00001 ? $tq['value'] / $tq['qty'] : (float)$i['avg_cost'];
            $value = r2($qty * $unit);
            $band = $count === null ? '' : ($count >= 365 ? 'over a year' : ($count >= 180 ? "180\u{2011}364 days" : ($count >= 90 ? "90\u{2011}179 days" : ($count >= 30 ? "30\u{2011}89 days" : 'under 30 days'))));
            $rows[] = [
                'item' => $i['code'], 'desc' => $i['name'], 'category' => $cats[$i['category_id']] ?? '',
                'qty' => $qty, 'value' => $value,
                'last_sale' => $m['last_sale'] ?? null ?: 'Never sold', 'days' => $count, 'band' => $band,
                'last_move' => $m['last_move'] ?? '', 'days_move' => $m && $m['last_move'] ? max(0, (int)$days($m['last_move'])) : null,
                '_links' => ['item' => Report::link('inv.center', ['id' => $i['id'], 'site_id' => $siteId])],
                '_ref' => ['item' => (int)$i['id']],
                '_class' => $count !== null && $count >= 90 ? 'aged-stock' : '',
            ];
            $tot['qty'] += $qty;
            $tot['value'] += $value;
        }
        // the longest without a sale first
        usort($rows, fn($a, $b) => [-(int)($a['days'] ?? -1), $a['item']] <=> [-(int)($b['days'] ?? -1), $b['item']]);
        $tot['value'] = r2($tot['value']);
        return [
            'columns' => [['key' => 'item', 'label' => 'Item'], ['key' => 'desc', 'label' => 'Description'], ['key' => 'category', 'label' => 'Category'],
                ['key' => 'qty', 'label' => 'On hand', 'type' => 'qty'], ['key' => 'value', 'label' => 'Value', 'type' => 'money', 'sensitive' => 'cost'],
                ['key' => 'last_sale', 'label' => 'Last sold', 'type' => 'date'], ['key' => 'days', 'label' => 'Days not sold', 'type' => 'int'],
                ['key' => 'band', 'label' => 'Age', 'optional' => true], ['key' => 'last_move', 'label' => 'Last movement (any)', 'type' => 'date'],
                ['key' => 'days_move', 'label' => 'Days since any movement', 'type' => 'int', 'optional' => true]],
            'rows' => $rows, 'totals' => $tot,
            'notes' => 'Days not sold counts from the last sale to a customer (invoice, sales receipt or POS sale) up to ' . fdate($asof)
                . '; an item never sold counts from the day it first came in. "Last movement" is any change of quantity: receipts, transfers, adjustments, builds and sales.'
                . ($siteId ? ' Only the sales and movements at ' . h(inv_sites($siteId)[$siteId]) . ' are counted.' : ''),
        ];
    },
]);

// ---------------------------------------------------------------------- the customized inventory report

/**
 * Customized Inventory Report: one line per inventory part or assembly, with the columns the user picks
 * (the Columns box of the report, open to everybody who may open it): the item's own fields, its stock and
 * value as of the end of the period, its prices and the profit per unit, and what was sold and bought in the
 * period, among them the gross profit amount and gross profit percentage per item. Columns that show costs
 * are only offered to the users who may see costs. 'optional' columns print when they are picked.
 */
const INV_CUSTOM_COLUMNS = [
    // key => [heading, type, group, standard (printed when nothing is picked), cost (needs the view costs right)]
    'item'         => ['Item', 'text', 'Item', true, false],
    'desc'         => ['Description', 'text', 'Item', true, false],
    'sdesc'        => ['Sales description', 'text', 'Item', false, false],
    'pdesc'        => ['Purchase description', 'text', 'Item', false, false],
    'barcode'      => ['Barcode', 'text', 'Item', false, false],
    'category'     => ['Category', 'text', 'Item', true, false],
    'type'         => ['Item type', 'text', 'Item', false, false],
    'unit'         => ['Unit', 'text', 'Item', true, false],
    'vendor'       => ['Preferred supplier', 'text', 'Item', false, false],
    'tax'          => ['Tax code', 'text', 'Item', false, false],
    'status'       => ['Status', 'text', 'Item', false, false],
    'reorder'      => ['Reorder point', 'qty', 'Item', false, false],
    'max'          => ['Maximum stock', 'qty', 'Item', false, false],
    'onhand'       => ['On hand', 'qty', 'Stock and value at the end of the period', true, false],
    'to_order'     => ['Below reorder point by', 'qty', 'Stock and value at the end of the period', false, false],
    'avg'          => ['Average cost', 'money', 'Stock and value at the end of the period', true, true],
    'value'        => ['Asset value', 'money', 'Stock and value at the end of the period', true, true],
    'pct_value'    => ['% of total asset value', 'pct', 'Stock and value at the end of the period', false, true],
    'price'        => ['Sales price', 'money', 'Stock and value at the end of the period', true, false],
    'retail'       => ['Retail value', 'money', 'Stock and value at the end of the period', false, false],
    'pct_retail'   => ['% of total retail value', 'pct', 'Stock and value at the end of the period', false, false],
    'pot_gp'       => ['Gross profit in stock (retail less asset value)', 'money', 'Stock and value at the end of the period', false, true],
    'cost'         => ['Purchase cost', 'money', 'Profit per unit', false, true],
    'gp_unit'      => ['Gross profit per unit', 'money', 'Profit per unit', true, true],
    'gp_unit_pct'  => ['Gross profit % per unit', 'pct', 'Profit per unit', true, true],
    'markup'       => ['Markup % on cost', 'pct', 'Profit per unit', false, true],
    'sold_qty'     => ['Qty sold', 'qty', 'Sales in the period', true, false],
    'sales'        => ['Sales amount', 'money', 'Sales in the period', true, false],
    'avg_price'    => ['Average selling price', 'money', 'Sales in the period', false, false],
    'cogs'         => ['Cost of sales', 'money', 'Sales in the period', false, true],
    'gp'           => ['Gross profit', 'money', 'Sales in the period', true, true],
    'gp_pct'       => ['Gross profit %', 'pct', 'Sales in the period', true, true],
    'pct_sales'    => ['% of total sales', 'pct', 'Sales in the period', false, false],
    'last_sale'    => ['Last sold', 'date', 'Sales in the period', false, false],
    'bought_qty'   => ['Qty bought', 'qty', 'Purchases in the period', false, false],
    'bought'       => ['Purchase amount', 'money', 'Purchases in the period', false, true],
    'last_buy'     => ['Last bought', 'date', 'Purchases in the period', false, false],
];

Report::define('inv_custom', [
    'filters' => array_merge(['range', 'site', 'item'], INV_ITEM_FILTERS),
    'filter_labels' => ['site' => 'Inventory site'],
    'default_dates' => 'this_month',
    'column_picker' => true,
    'extra_filters' => [
        'show_items' => ['Items', fn() => ['active' => 'Active items', 'stock' => 'Active items in stock', 'sold' => 'Items sold in the period', 'all' => 'Active and inactive items'], null],
    ],
    'run' => function (array $f): array {
        $from = $f['from'];
        $to = $f['to'];
        $siteId = $f['site_id'] ? (int)$f['site_id'] : null;
        if ($siteId && !isset(inv_sites($siteId)[$siteId])) {
            $siteId = null;
        }
        $show = in_array($f['show_items'] ?? '', ['stock', 'sold', 'all'], true) ? $f['show_items'] : 'active';

        // the items
        $sql = "SELECT i.*, v.name AS vendor, c.name AS category, tc.code AS tax_code FROM items i LEFT JOIN vendors v ON v.id = i.preferred_vendor_id
                  LEFT JOIN item_categories c ON c.id = i.category_id LEFT JOIN tax_codes tc ON tc.id = i.tax_code_id
                 WHERE i.item_type IN ('inventory','assembly')" . ($show === 'all' ? '' : ' AND i.is_active = 1');
        $p = [];
        if ($f['item_id']) {
            $sql .= ' AND i.id = :i';
            $p['i'] = $f['item_id'];
        }
        $sql .= Report::itemCond($f, 'i.id', $p);
        $items = DB::all($sql . ' ORDER BY i.code', $p);

        // stock and value at the end of the period (one site: its quantity at the average cost of all sites)
        $all = inv_position($to);
        $pos = $all;
        if ($siteId) {
            $pos = [];
            foreach (inv_position($to, $siteId) as $itemId => $q) {
                $tot = $all[$itemId] ?? ['qty' => 0.0, 'value' => 0.0];
                $unit = abs($tot['qty']) > 0.00001 ? $tot['value'] / $tot['qty'] : null;
                $pos[$itemId] = ['qty' => $q['qty'], 'value' => $unit !== null ? r2($q['qty'] * $unit) : $q['value']];
            }
        }
        // sales in the period
        $sold = [];
        foreach (Report::salesLines($from, $to, ['site_id' => $siteId] + $f) as $l) {
            $iid = (int)$l['item_id'];
            if (!$iid) {
                continue;
            }
            $sold[$iid] ??= ['qty' => 0.0, 'amount' => 0.0, 'cost' => 0.0, 'last' => ''];
            $sold[$iid]['qty'] += $l['s_qty'];
            $sold[$iid]['amount'] += $l['s_amount'];
            $sold[$iid]['cost'] += $l['s_cost'];
            if ((int)$l['sgn'] > 0 && $l['txn_date'] > $sold[$iid]['last']) {
                $sold[$iid]['last'] = $l['txn_date'];
            }
        }
        // purchases in the period: what came into stock from suppliers (a bill made from an item receipt is counted once)
        $bsql = "SELECT m.item_id, SUM(m.qty) AS qty, SUM(m.total_cost) AS amt, MAX(CASE WHEN m.qty > 0 THEN m.txn_date END) AS last_buy
                   FROM inventory_movements m JOIN transactions t ON t.id = m.txn_id
                  WHERE t.txn_type IN ('item_receipt','bill','check','cash_voucher','vendor_credit') AND t.is_voided = 0 AND m.txn_date BETWEEN :f AND :t";
        $bp = ['f' => $from, 't' => $to];
        if ($siteId) {
            $bsql .= ' AND m.site_id = :s';
            $bp['s'] = $siteId;
        }
        $bought = [];
        foreach (DB::all($bsql . ' GROUP BY m.item_id', $bp) as $b) {
            $bought[(int)$b['item_id']] = $b;
        }

        $totVal = 0.0;
        $totRetail = 0.0;
        $totSales = 0.0;
        $keep = [];
        foreach ($items as $i) {
            $id = (int)$i['id'];
            $q = $pos[$id] ?? ['qty' => 0.0, 'value' => 0.0];
            if ($show === 'stock' && abs($q['qty']) < 0.00001) {
                continue;
            }
            if ($show === 'sold' && empty($sold[$id])) {
                continue;
            }
            $keep[] = $i;
            $totVal += $q['value'];
            $totRetail += $q['qty'] * (float)$i['sales_price'];
            $totSales += $sold[$id]['amount'] ?? 0.0;
        }
        $types = ['inventory' => 'Inventory part', 'assembly' => 'Inventory assembly'];
        $rows = [];
        $tot = ['item' => 'TOTAL'];
        foreach ($keep as $i) {
            $id = (int)$i['id'];
            $q = $pos[$id] ?? ['qty' => 0.0, 'value' => 0.0];
            $s = $sold[$id] ?? ['qty' => 0.0, 'amount' => 0.0, 'cost' => 0.0, 'last' => ''];
            $b = $bought[$id] ?? null;
            $qty = (float)$q['qty'];
            $value = r2((float)$q['value']);
            $avg = abs($qty) > 0.00001 ? round($value / $qty, 4) : round((float)$i['avg_cost'], 4);
            $price = (float)$i['sales_price'];
            $retail = r2($qty * $price);
            $gpUnit = round($price - $avg, 4);
            $gp = r2($s['amount'] - $s['cost']);
            $reorder = (float)$i['reorder_point'];
            $rows[] = [
                'item' => $i['code'], 'desc' => $i['name'], 'sdesc' => trim((string)$i['description']), 'pdesc' => trim((string)$i['purchase_description']),
                'barcode' => $i['barcode'], 'category' => $i['category'], 'type' => $types[$i['item_type']] ?? $i['item_type'], 'unit' => $i['unit'],
                'vendor' => $i['vendor'], 'tax' => $i['tax_code'], 'status' => (int)$i['is_active'] ? 'Active' : 'Inactive',
                'reorder' => $reorder, 'max' => (float)$i['max_stock'],
                'onhand' => $qty, 'to_order' => $reorder > 0 && $qty < $reorder ? $reorder - $qty : '',
                'avg' => $avg, 'value' => $value, 'pct_value' => $totVal ? round($value / $totVal * 100, 1) : 0,
                'price' => $price, 'retail' => $retail, 'pct_retail' => $totRetail ? round($retail / $totRetail * 100, 1) : 0,
                'pot_gp' => r2($retail - $value),
                'cost' => (float)$i['cost'], 'gp_unit' => $gpUnit,
                'gp_unit_pct' => $price > 0 ? round($gpUnit / $price * 100, 1) : '', 'markup' => $avg > 0 ? round($gpUnit / $avg * 100, 1) : '',
                'sold_qty' => $s['qty'], 'sales' => r2($s['amount']), 'avg_price' => abs($s['qty']) > 0.00001 ? round($s['amount'] / $s['qty'], 4) : '',
                'cogs' => r2($s['cost']), 'gp' => $gp, 'gp_pct' => abs($s['amount']) > 0.004 ? round($gp / $s['amount'] * 100, 1) : '',
                'pct_sales' => $totSales ? round($s['amount'] / $totSales * 100, 1) : 0, 'last_sale' => $s['last'],
                'bought_qty' => $b ? (float)$b['qty'] : 0.0, 'bought' => $b ? r2((float)$b['amt']) : 0.0, 'last_buy' => $b['last_buy'] ?? '',
                '_links' => ['item' => Report::link('inv.center', ['id' => $id]),
                    'sales' => Report::link('rpt.sales_item_detail', ['item_id' => $id, 'dates' => 'custom', 'from' => $from, 'to' => $to]),
                    'value' => Report::link('rpt.inv_valuation_detail', ['item_id' => $id, 'from' => '2000-01-01', 'to' => $to, 'dates' => 'custom', 'site_id' => $siteId])],
                '_ref' => ['item' => $id],
            ];
            foreach (['onhand' => $qty, 'value' => $value, 'retail' => $retail, 'pot_gp' => r2($retail - $value), 'sold_qty' => $s['qty'], 'sales' => $s['amount'],
                      'cogs' => $s['cost'], 'gp' => $gp, 'bought_qty' => $b ? (float)$b['qty'] : 0.0, 'bought' => $b ? (float)$b['amt'] : 0.0] as $k => $v) {
                $tot[$k] = ($tot[$k] ?? 0.0) + (float)$v;
            }
        }
        foreach (['value', 'retail', 'pot_gp', 'sales', 'cogs', 'gp', 'bought'] as $k) {
            $tot[$k] = r2($tot[$k] ?? 0.0);
        }
        $tot['gp_pct'] = abs($tot['sales'] ?? 0) > 0.004 ? round($tot['gp'] / $tot['sales'] * 100, 1) : '';
        $tot['pct_value'] = $rows ? 100 : '';
        $tot['pct_retail'] = $rows ? 100 : '';
        $tot['pct_sales'] = $rows && $totSales ? 100 : '';

        $cols = [];
        foreach (INV_CUSTOM_COLUMNS as $k => [$label, $type, $group, $std, $cost]) {
            $cols[] = ['key' => $k, 'label' => $label, 'type' => $type, 'group' => $group, 'optional' => !$std] + ($cost ? ['sensitive' => 'cost'] : []);
        }
        return [
            'columns' => $cols,
            'rows' => $rows,
            'totals' => $rows ? $tot : null,
            'notes' => 'Stock and value at the end of ' . h(fdate($to)) . ($siteId ? ' at ' . h(inv_sites($siteId)[$siteId]) : ', all sites')
                . '; sales and purchases from ' . h(fdate($from)) . ' to ' . h(fdate($to)) . '. Gross profit = sales amount less the cost of sales; per unit, the sales price less the average cost.'
                . ' Pick the columns to print in the <strong>Columns</strong> box above.',
        ];
    },
]);
