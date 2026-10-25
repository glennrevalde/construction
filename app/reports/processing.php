<?php
declare(strict_types=1);

/** Processing (disassembly, de-kitting, co-product / by-product) reports. */

function proc_rpt_orders(array $f, bool $completedOnly): array
{
    $sql = "SELECT t.*, pf.code AS formula_code, pf.name AS formula_name, s.name AS site_name
              FROM transactions t
              LEFT JOIN process_formulas pf ON pf.id = t.process_formula_id
              LEFT JOIN sites s ON s.id = t.site_id
             WHERE t.txn_type = 'item_processing' AND t.txn_date BETWEEN :f AND :t";
    $p = ['f' => $f['from'], 't' => $f['to']];
    if ($completedOnly) {
        $sql .= ' AND t.is_voided = 0 AND t.is_pending = 0';
    }
    if (!empty($f['site_id'])) {
        $sql .= ' AND (t.site_id = :s OR t.site2_id = :s)';
        $p['s'] = $f['site_id'];
    }
    if (($f['ptype'] ?? '') !== '') {
        $sql .= ' AND t.process_type = :pt';
        $p['pt'] = $f['ptype'];
    }
    if (!empty($f['formula'])) {
        $sql .= ' AND t.process_formula_id = :pf';
        $p['pf'] = (int)$f['formula'];
    }
    if (($f['pstatus'] ?? '') === 'completed') {
        $sql .= ' AND t.is_voided = 0 AND t.is_pending = 0';
    } elseif (($f['pstatus'] ?? '') === 'pending') {
        $sql .= ' AND t.is_voided = 0 AND t.is_pending = 1';
    } elseif (($f['pstatus'] ?? '') === 'void') {
        $sql .= ' AND t.is_voided = 1';
    }
    [$sf, $sp] = Access::siteFilter('t.site_id', 'rs');
    return DB::all($sql . $sf . ' ORDER BY t.txn_date, t.id', $p + $sp);
}

function proc_rpt_type_opts(): array
{
    return array_map(fn($x) => $x[0], Processing::TYPES);
}

function proc_rpt_formula_opts(): array
{
    return Processing::formulas(false);
}

Report::define('processing_register', [
    'filters' => ['range', 'site'],
    'default_dates' => 'this_month',
    'extra_filters' => [
        'ptype' => ['Process type', fn() => proc_rpt_type_opts()],
        'formula' => ['Formula', fn() => proc_rpt_formula_opts()],
        'pstatus' => ['Status', fn() => ['completed' => 'Completed', 'pending' => 'Pending', 'void' => 'Void']],
    ],
    'run' => function (array $f): array {
        $orders = proc_rpt_orders($f, false);
        $agg = [];
        if ($orders) {
            [$in, $p] = DB::in('id', array_column($orders, 'id'));
            foreach (DB::all("SELECT l.txn_id, l.line_kind, l.output_role, l.qty, l.amount, i.code
                                FROM transaction_lines l LEFT JOIN items i ON i.id = l.item_id
                               WHERE l.txn_id IN $in ORDER BY l.line_no", $p) as $l) {
                $a = &$agg[(int)$l['txn_id']];
                $amt = (float)$l['amount'];
                if ($l['line_kind'] === 'input') {
                    $a['in'][] = qty($l['qty']) . ' ' . $l['code'];
                    $a['in_qty'] = ($a['in_qty'] ?? 0) + (float)$l['qty'];
                    $a['materials'] = ($a['materials'] ?? 0) + $amt;
                } elseif ($l['line_kind'] === 'cost') {
                    $a['extra'] = ($a['extra'] ?? 0) + $amt;
                } elseif ($l['line_kind'] === 'output') {
                    $a['out'][] = qty($l['qty']) . ' ' . $l['code'];
                    $a['out_qty'] = ($a['out_qty'] ?? 0) + (float)$l['qty'];
                    if (($l['output_role'] ?: 'co_product') === 'co_product') {
                        $a['co'] = ($a['co'] ?? 0) + $amt;
                    } else {
                        $a['by'] = ($a['by'] ?? 0) + $amt;
                    }
                }
                unset($a);
            }
        }
        $rows = [];
        $tot = ['date' => 'TOTAL (completed orders)', 'in_qty' => 0.0, 'out_qty' => 0.0, 'qty_var' => 0.0, 'materials' => 0.0, 'extra' => 0.0, 'total' => 0.0, 'by' => 0.0, 'co' => 0.0, 'variance' => 0.0];
        foreach ($orders as $o) {
            $a = $agg[(int)$o['id']] ?? [];
            $materials = r2($a['materials'] ?? 0);
            $extra = r2($a['extra'] ?? 0);
            $pool = r2($materials + $extra);
            $by = r2($a['by'] ?? 0);
            $co = r2($a['co'] ?? 0);
            $variance = r2($pool - $by - $co);
            $status = (int)$o['is_voided'] ? 'void' : ((int)$o['is_pending'] ? 'pending' : 'completed');
            $row = [
                'date' => $o['txn_date'], 'num' => $o['txn_no'], 'type' => Processing::typeLabel($o['process_type']),
                'formula' => $o['formula_code'] ? $o['formula_code'] . ($o['process_batches'] !== null ? ' × ' . qty($o['process_batches']) : '') : '',
                'site' => $o['site_name'], 'inputs' => implode(', ', $a['in'] ?? []), 'outputs' => implode(', ', $a['out'] ?? []),
                'in_qty' => round($a['in_qty'] ?? 0, 4), 'out_qty' => round($a['out_qty'] ?? 0, 4), 'qty_var' => round(($a['in_qty'] ?? 0) - ($a['out_qty'] ?? 0), 4),
                'materials' => $materials, 'extra' => $extra, 'total' => $pool, 'by' => $by, 'co' => $co, 'variance' => $variance,
                'method' => Processing::methodLabel($o['alloc_method']), 'status' => $status,
                '_links' => ['num' => Report::txnLink(['txn_type' => $o['txn_type'], 'txn_id' => $o['id']])],
                '_class' => $status === 'completed' ? '' : 'text-muted',
            ];
            if ($status === 'completed') {
                foreach (['in_qty', 'out_qty', 'qty_var', 'materials', 'extra', 'total', 'by', 'co', 'variance'] as $k) {
                    $tot[$k] += $row[$k];
                }
            }
            $rows[] = $row;
        }
        return [
            'columns' => [
                ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'num', 'label' => 'Num'], ['key' => 'type', 'label' => 'Type'],
                ['key' => 'formula', 'label' => 'Formula'], ['key' => 'site', 'label' => 'Site'], ['key' => 'inputs', 'label' => 'Inputs'], ['key' => 'outputs', 'label' => 'Outputs'],
                ['key' => 'in_qty', 'label' => 'Input qty', 'type' => 'qty', 'optional' => true], ['key' => 'out_qty', 'label' => 'Output qty', 'type' => 'qty', 'optional' => true],
                ['key' => 'qty_var', 'label' => 'Variance qty', 'type' => 'qty'],
                ['key' => 'materials', 'label' => 'Input materials', 'type' => 'money', 'sensitive' => 'cost'],
                ['key' => 'extra', 'label' => 'Added costs', 'type' => 'money', 'sensitive' => 'cost'],
                ['key' => 'total', 'label' => 'Total cost', 'type' => 'money', 'sensitive' => 'cost'],
                ['key' => 'by', 'label' => 'By-products & scrap', 'type' => 'money', 'sensitive' => 'cost'],
                ['key' => 'co', 'label' => 'Co-products', 'type' => 'money', 'sensitive' => 'cost'],
                ['key' => 'variance', 'label' => 'Variance', 'type' => 'money', 'sensitive' => 'cost'],
                ['key' => 'method', 'label' => 'Allocation'], ['key' => 'status', 'label' => 'Status'],
            ],
            'rows' => $rows,
            'totals' => $tot,
            'notes' => 'Disassembly, de-kitting and co-product orders. Pending orders show estimated amounts and are left out of the totals; voided orders are listed for reference. Variance qty is the input quantity less the output quantity.',
        ];
    },
]);

Report::define('coproduct_costs', [
    'filters' => ['range', 'item', 'site', 'category', 'item_type'],
    'default_dates' => 'this_year',
    'sortable' => false,
    'extra_filters' => [
        'ptype' => ['Process type', fn() => proc_rpt_type_opts()],
        'role' => ['Output role', fn() => Processing::ROLES],
    ],
    'run' => function (array $f): array {
        $orders = proc_rpt_orders($f, true);
        $rows = [];
        $chart = [];
        $tot = ['item' => 'TOTAL', 'qty' => '', 'cost' => 0.0];
        if ($orders) {
            $byId = [];
            foreach ($orders as $o) {
                $byId[(int)$o['id']] = $o;
            }
            [$in, $p] = DB::in('id', array_keys($byId));
            $sql = "SELECT l.*, i.code, i.name, i.unit AS item_unit FROM transaction_lines l JOIN items i ON i.id = l.item_id
                     WHERE l.txn_id IN $in AND l.line_kind = 'output'";
            if (!empty($f['item_id'])) {
                $sql .= ' AND l.item_id = :iid';
                $p['iid'] = $f['item_id'];
            }
            $sql .= Report::itemCond($f, 'l.item_id', $p);
            if (($f['role'] ?? '') !== '') {
                $sql .= " AND COALESCE(l.output_role, 'co_product') = :role";
                $p['role'] = $f['role'];
            }
            $lines = DB::all($sql . ' ORDER BY i.code, l.txn_id', $p);
            $group = null;
            $sub = null;
            $flush = function () use (&$rows, &$sub, &$group) {
                if ($group !== null) {
                    $rows[] = ['item' => 'Total ' . $group, 'qty' => $sub['qty'], 'cost' => r2($sub['cost']),
                        'unit' => $sub['qty'] > 0 ? round($sub['cost'] / $sub['qty'], 4) : '', '_class' => 'subtotal-row'];
                }
            };
            foreach ($lines as $l) {
                if ($group !== $l['code']) {
                    $flush();
                    $group = $l['code'];
                    $sub = ['qty' => 0.0, 'cost' => 0.0];
                    $rows[] = ['item' => $l['code'] . ' - ' . $l['name'], '_class' => 'group-row'];
                }
                $o = $byId[(int)$l['txn_id']];
                $cost = (float)$l['amount'];
                $sub['qty'] += (float)$l['qty'];
                $sub['cost'] += $cost;
                $tot['cost'] += $cost;
                $chart[$l['code']] = ($chart[$l['code']] ?? 0) + $cost;
                $rows[] = [
                    'item' => '', 'date' => $o['txn_date'], 'num' => $o['txn_no'], 'type' => Processing::typeLabel($o['process_type']),
                    'role' => Processing::roleLabel($l['output_role']), 'qty' => (float)$l['qty'], 'uom' => $l['item_unit'],
                    'basis' => (float)$l['alloc_basis'] != 0.0 ? qty($l['alloc_basis']) : 'auto', 'method' => Processing::methodLabel($o['alloc_method']),
                    'pct' => (float)$l['alloc_pct'], 'cost' => r2($cost), 'unit' => (float)$l['rate'],
                    '_links' => ['num' => Report::txnLink(['txn_type' => $o['txn_type'], 'txn_id' => $o['id']])],
                ];
            }
            $flush();
        }
        arsort($chart);
        if (count($chart) > 6) {
            $top = array_slice($chart, 0, 5, true);
            $top['Other'] = array_sum(array_slice($chart, 5));
            $chart = $top;
        }
        return [
            'grouped' => true,
            'columns' => [
                ['key' => 'item', 'label' => 'Output item'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'num', 'label' => 'Num'],
                ['key' => 'type', 'label' => 'Type'], ['key' => 'role', 'label' => 'Role'], ['key' => 'qty', 'label' => 'Qty', 'type' => 'qty'], ['key' => 'uom', 'label' => 'U/M'],
                ['key' => 'method', 'label' => 'Method'], ['key' => 'basis', 'label' => 'Basis'], ['key' => 'pct', 'label' => 'Share of order cost', 'type' => 'pct'],
                ['key' => 'cost', 'label' => 'Allocated cost', 'type' => 'money', 'sensitive' => 'cost'],
                ['key' => 'unit', 'label' => 'Unit cost', 'type' => 'money', 'sensitive' => 'cost'],
            ],
            'rows' => $rows,
            'totals' => $tot,
            'chart' => Access::viewCosts() && $chart ? ['pie' => array_map('r2', $chart), 'pie_title' => 'Allocated cost by output item'] : null,
            'notes' => 'How the cost of each completed processing order was shared among its outputs. By-products and scrap carry their fixed value; co-products share the rest by the order\'s method.',
        ];
    },
]);

Report::define('process_yield', [
    'filters' => ['range', 'item', 'site', 'category', 'item_type'],
    'default_dates' => 'this_year',
    'sortable' => false,
    'extra_filters' => [
        'formula' => ['Formula', fn() => proc_rpt_formula_opts()],
        'layout' => ['Show', fn() => ['summary' => 'Summary by formula and item', 'detail' => 'Every order'], null],
    ],
    'run' => function (array $f): array {
        $orders = proc_rpt_orders($f, true);
        $detail = ($f['layout'] ?? '') === 'detail';
        $rows = [];
        if ($orders) {
            $byId = [];
            foreach ($orders as $o) {
                $byId[(int)$o['id']] = $o;
            }
            [$in, $p] = DB::in('id', array_keys($byId));
            $sql = "SELECT l.*, i.code, i.name FROM transaction_lines l JOIN items i ON i.id = l.item_id
                     WHERE l.txn_id IN $in AND l.line_kind IN ('input','output') AND l.planned_qty IS NOT NULL";
            if (!empty($f['item_id'])) {
                $sql .= ' AND l.item_id = :iid';
                $p['iid'] = $f['item_id'];
            }
            $sql .= Report::itemCond($f, 'l.item_id', $p);
            $lines = DB::all($sql . ' ORDER BY l.txn_id, l.line_no', $p);
            $groups = [];
            foreach ($lines as $l) {
                $o = $byId[(int)$l['txn_id']];
                $gkey = $o['formula_code'] ? $o['formula_code'] . ' - ' . $o['formula_name'] : 'Assembly bills of materials / other';
                $ikey = $l['line_kind'] . '|' . $l['code'];
                $g = &$groups[$gkey][$ikey];
                $g['label'] = $l['code'] . ' - ' . $l['name'];
                $g['item_id'] = (int)$l['item_id'];
                $g['kind'] = $l['line_kind'] === 'input' ? 'Input' : Processing::roleLabel($l['output_role']);
                $g['orders'][(int)$l['txn_id']] = true;
                $g['planned'] = ($g['planned'] ?? 0) + (float)$l['planned_qty'];
                $g['actual'] = ($g['actual'] ?? 0) + (float)$l['qty'];
                $g['lines'][] = [$o, $l];
                unset($g);
            }
            ksort($groups);
            foreach ($groups as $gkey => $items) {
                $rows[] = ['item' => $gkey, '_class' => 'group-row'];
                // inputs first, then outputs
                uksort($items, fn($x, $y) => strcmp($x, $y));
                foreach ($items as $it) {
                    $planned = $it['planned'];
                    $actual = $it['actual'];
                    $rows[] = [
                        'item' => $it['label'], 'kind' => $it['kind'], 'orders' => count($it['orders']), 'planned' => $planned, 'actual' => $actual,
                        'diff' => round($actual - $planned, 4), 'yield' => $planned > 0 ? round($actual / $planned * 100, 2) : '',
                        '_class' => $detail ? 'subtotal-row' : ($planned > 0 && abs($actual / $planned * 100 - 100) >= 2 ? 'table-warning' : ''),
                        '_ref' => ['item' => $it['item_id']],
                    ];
                    if ($detail) {
                        foreach ($it['lines'] as [$o, $l]) {
                            $pl = (float)$l['planned_qty'];
                            $rows[] = [
                                'item' => '', 'date' => $o['txn_date'], 'num' => $o['txn_no'], 'planned' => $pl, 'actual' => (float)$l['qty'],
                                'diff' => round((float)$l['qty'] - $pl, 4), 'yield' => $pl > 0 ? round((float)$l['qty'] / $pl * 100, 2) : '',
                                '_class' => 'indent-1', '_links' => ['num' => Report::txnLink(['txn_type' => $o['txn_type'], 'txn_id' => $o['id']])],
                            ];
                        }
                    }
                }
            }
        }
        return [
            'grouped' => true,
            'columns' => [
                ['key' => 'item', 'label' => 'Item'], ['key' => 'kind', 'label' => 'Line'], ['key' => 'orders', 'label' => 'Orders', 'type' => 'int'],
                ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'num', 'label' => 'Num'],
                ['key' => 'planned', 'label' => 'Planned qty', 'type' => 'qty'], ['key' => 'actual', 'label' => 'Actual qty', 'type' => 'qty'],
                ['key' => 'diff', 'label' => 'Difference', 'type' => 'qty'], ['key' => 'yield', 'label' => 'Actual vs planned', 'type' => 'pct'],
            ],
            'rows' => $rows,
            'notes' => 'Planned quantities come from the formula (or bill of materials) times the batches run; actual quantities are what was recorded on the completed order. Rows more than 2% off plan are highlighted.',
        ];
    },
]);
