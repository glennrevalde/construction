<?php
declare(strict_types=1);

/**
 * Project reports (Project Reports center). Every report shows only the projects the user may see; the project,
 * client, project manager and status drop-downs narrow it further. Figures come from the same ledger as the
 * accounting reports: a project is its class, a work item its cost code.
 */

/** The shared drop-downs of the project reports */
function csr_filters(bool $withStatus = true): array
{
    $f = [
        'project' => ['Project', fn() => CsProject::pickList(true), 'All my projects'],
        'client' => ['Client', fn() => DB::pairs('SELECT DISTINCT c.id, c.name FROM customers c JOIN cs_projects p ON p.customer_id = c.id ORDER BY c.name'), 'All clients'],
        'pm' => ['Project manager', fn() => DB::pairs('SELECT DISTINCT u.id, u.full_name FROM users u JOIN cs_projects p ON p.pm_user_id = u.id ORDER BY u.full_name'), 'Anyone'],
    ];
    if ($withStatus) {
        $f['pstatus'] = ['Status', fn() => ['open' => 'Open projects'] + CsProject::STATUSES, 'Every status'];
    }
    return $f;
}

/** The projects a report covers: [id => project row] */
function csr_projects(array $f): array
{
    $w = '1 = 1';
    $p = [];
    $acc = CsProject::accessibleIds();
    if ($acc !== null) {
        [$in, $pp] = DB::in('rpa', $acc ?: [0]);
        $w .= " AND p.id IN $in";
        $p += $pp;
    }
    if (!empty($f['project'])) {
        $w .= ' AND p.id = :rpid';
        $p['rpid'] = (int)$f['project'];
    }
    if (!empty($f['client'])) {
        $w .= ' AND p.customer_id = :rpc';
        $p['rpc'] = (int)$f['client'];
    }
    if (!empty($f['pm'])) {
        $w .= ' AND p.pm_user_id = :rpm';
        $p['rpm'] = (int)$f['pm'];
    }
    $st = (string)($f['pstatus'] ?? '');
    if ($st === 'open') {
        [$in2, $pp2] = DB::in('rps', CsProject::OPEN);
        $w .= " AND p.status IN $in2";
        $p += $pp2;
    } elseif ($st !== '' && isset(CsProject::STATUSES[$st])) {
        $w .= ' AND p.status = :rpst';
        $p['rpst'] = $st;
    }
    $out = [];
    foreach (DB::all("SELECT p.*, c.name AS customer_name, u.full_name AS pm_name FROM cs_projects p LEFT JOIN customers c ON c.id = p.customer_id LEFT JOIN users u ON u.id = p.pm_user_id WHERE $w ORDER BY p.code", $p) as $r) {
        $out[(int)$r['id']] = $r;
    }
    return $out;
}

function csr_label(array $p): string
{
    return $p['code'] . ' ' . $p['name'];
}

function csr_link(int $pid): ?string
{
    return Report::link('proj.view', ['id' => $pid]);
}

/** Sums the money columns of rows into a totals row */
function csr_totals(array $rows, array $cols, string $label = 'TOTAL', string $first = 'project'): array
{
    $t = [$first => $label];
    foreach ($cols as $c) {
        if (($c['type'] ?? '') === 'money' || ($c['type'] ?? '') === 'qty' || ($c['type'] ?? '') === 'int') {
            $t[$c['key']] = r2(array_sum(array_map(fn($r) => is_numeric($r[$c['key']] ?? null) ? (float)$r[$c['key']] : 0, $rows)));
        }
    }
    return $t;
}

function csr_m(string $key, string $label): array
{
    return ['key' => $key, 'label' => $label, 'type' => 'money'];
}

// =====================================================================================================================
// Project financials
// =====================================================================================================================

Report::define('cs_portfolio', [
    'filters' => ['asof'], 'extra_filters' => csr_filters(),
    'run' => function (array $f): array {
        $rows = [];
        foreach (csr_projects($f) as $pid => $p) {
            $s = CsSummary::project($pid, $f['asof']);
            $rows[] = ['project' => csr_label($p), 'client' => (string)$p['customer_name'], 'pm' => (string)$p['pm_name'], 'status' => CsUi::label($p['status']), 'contract' => $s['contract_current'],
                'physical' => $s['physical_pct'], 'planned' => $s['planned_pct'] ?? '', 'cost' => $s['cost_direct'], 'committed' => $s['committed']['total'], 'eac' => $s['eac'],
                'profit' => $s['projected_profit'], 'margin' => $s['projected_margin'], 'billed' => $s['certified_gross'], 'collected' => $s['collected'], 'ar' => $s['ar_open'],
                '_links' => ['project' => csr_link($pid)], '_class' => $s['projected_profit'] < 0 ? 'text-danger' : ''];
        }
        $cols = [['key' => 'project', 'label' => 'Project'], ['key' => 'client', 'label' => 'Client', 'optional' => true], ['key' => 'pm', 'label' => 'Project manager', 'optional' => true], ['key' => 'status', 'label' => 'Status'],
            csr_m('contract', 'Contract value'), ['key' => 'physical', 'label' => 'Progress', 'type' => 'pct'], ['key' => 'planned', 'label' => 'Planned', 'type' => 'pct', 'optional' => true], csr_m('cost', 'Cost to date'),
            csr_m('committed', 'Committed') + ['optional' => true], csr_m('eac', 'Estimate at completion'), csr_m('profit', 'Projected profit'), ['key' => 'margin', 'label' => 'Margin', 'type' => 'pct'],
            csr_m('billed', 'Billed'), csr_m('collected', 'Collected') + ['optional' => true], csr_m('ar', 'Receivable')];
        return ['columns' => $cols, 'rows' => $rows, 'totals' => csr_totals($rows, $cols),
            'chart' => ['bars_title' => 'Projected profit by project', 'bars' => array_column(array_map(fn($r) => ['k' => explode(' ', $r['project'])[0], 'v' => $r['profit']], $rows), 'v', 'k')]];
    },
]);

Report::define('cs_income', [
    'filters' => ['range'], 'default_dates' => 'year_to_date', 'extra_filters' => csr_filters(),
    'run' => function (array $f): array {
        $ps = csr_projects($f);
        $ids = array_keys($ps);
        $cost = CsCost::costByType($ids, $f['to'], $f['from']);
        $rev = CsCost::revenue($ids, $f['to'], $f['from']);
        $loss = $ids ? CsCost::lossProvided($ids, $f['to'], $f['from']) : [];
        $rows = [];
        foreach ($ps as $pid => $p) {
            $c = $cost[$pid] ?? array_fill_keys(array_keys(CsCost::TYPES), 0.0) + ['direct' => 0.0];
            $r = (float)($rev[$pid] ?? 0);
            $gp = $r - (float)$c['direct'];
            $op = $gp - (float)$c['overhead'] - (float)($loss[$pid] ?? 0);
            $rows[] = ['project' => csr_label($p), 'revenue' => $r, 'material' => $c['material'], 'labor' => $c['labor'], 'equipment' => $c['equipment'], 'subcontract' => $c['subcontract'], 'other' => $c['other'],
                'direct' => $c['direct'], 'gross' => r2($gp), 'gross_pct' => $r != 0.0 ? round($gp / $r * 100, 1) : '', 'overhead' => $c['overhead'], 'loss' => (float)($loss[$pid] ?? 0), 'net' => r2($op),
                '_links' => ['project' => csr_link($pid), 'revenue' => Report::link('rpt.general_ledger', ['class_id' => $p['class_id'], 'from' => $f['from'], 'to' => $f['to'], 'dates' => 'custom'])]];
        }
        $cols = [['key' => 'project', 'label' => 'Project'], csr_m('revenue', 'Revenue'), csr_m('material', 'Materials'), csr_m('labor', 'Labor'), csr_m('equipment', 'Equipment'), csr_m('subcontract', 'Subcontracts'),
            csr_m('other', 'Other direct'), csr_m('direct', 'Total direct cost'), csr_m('gross', 'Gross profit'), ['key' => 'gross_pct', 'label' => 'Gross margin', 'type' => 'pct'], csr_m('overhead', 'Allocated overhead'),
            csr_m('loss', 'Loss provision') + ['optional' => true], csr_m('net', 'Project profit')];
        $t = csr_totals($rows, $cols);
        $t['gross_pct'] = $t['revenue'] != 0.0 ? round($t['gross'] / $t['revenue'] * 100, 1) : '';
        return ['columns' => $cols, 'rows' => $rows, 'totals' => $t, 'notes' => 'Revenue is what the books recognized in the period (percentage of completion or billings, per the company\'s method). Costs are by the cost type of their accounts.'];
    },
]);

Report::define('cs_cost_breakdown', [
    'filters' => ['range'], 'default_dates' => 'year_to_date', 'extra_filters' => csr_filters(),
    'run' => function (array $f): array {
        $rows = [];
        foreach (csr_projects($f) as $pid => $p) {
            $by = CsCost::costByCode($pid, $f['to'], $f['from']);
            if (!$by) {
                continue;
            }
            $rows[] = ['code' => csr_label($p), '_class' => 'group-row', '_links' => ['code' => csr_link($pid)]];
            $sum = array_fill_keys(array_keys(CsCost::TYPES), 0.0);
            uksort($by, fn($a, $b) => strcmp(CsCost::codeLabel($a) ?: 'zzz', CsCost::codeLabel($b) ?: 'zzz'));
            foreach ($by as $cc => $types) {
                $row = ['code' => CsCost::codeLabel($cc ?: null) ?: 'No cost code', '_class' => 'indent-1'];
                foreach (CsCost::TYPES as $t => $_) {
                    $row[$t] = (float)($types[$t] ?? 0);
                    $sum[$t] += $row[$t];
                }
                $row['total'] = r2(array_sum(array_intersect_key($row, CsCost::TYPES)));
                $rows[] = $row;
            }
            $rows[] = ['code' => 'Total ' . $p['code'], '_class' => 'subtotal-row'] + array_map('r2', $sum) + ['total' => r2(array_sum($sum))];
        }
        $cols = [['key' => 'code', 'label' => 'Cost code']];
        foreach (CsCost::TYPES as $t => $l) {
            $cols[] = csr_m($t, $l);
        }
        $cols[] = csr_m('total', 'Total');
        return ['columns' => $cols, 'rows' => $rows, 'grouped' => true, 'notes' => '"No cost code" are project costs typed in accounting without a cost code: give them one in Cost Code Assignment.'];
    },
]);

Report::define('cs_budget_actual', [
    'filters' => ['asof'], 'extra_filters' => csr_filters(),
    'run' => function (array $f): array {
        $rows = [];
        $grand = ['original' => 0.0, 'budget' => 0.0, 'actual' => 0.0, 'committed' => 0.0];
        foreach (csr_projects($f) as $pid => $p) {
            $fc = CsCost::forecast($pid, $f['asof']);
            if (!$fc['rows']) {
                continue;
            }
            $rows[] = ['code' => csr_label($p), '_class' => 'group-row', '_links' => ['code' => csr_link($pid)]];
            foreach ($fc['rows'] as $cc => $r) {
                $left = $r['budget'] - $r['actual'] - $r['committed'];
                $rows[] = ['code' => CsCost::codeLabel($cc ?: null) ?: 'No cost code', 'original' => $r['original'], 'budget' => $r['budget'], 'change' => r2($r['budget'] - $r['original']), 'actual' => $r['actual'],
                    'committed' => $r['committed'], 'remaining' => r2($left), 'used' => $r['budget'] > 0 ? round(($r['actual'] + $r['committed']) / $r['budget'] * 100, 1) : '', '_class' => 'indent-1' . ($left < -0.5 ? ' text-danger' : '')];
            }
            $t = $fc['totals'];
            $rows[] = ['code' => 'Total ' . $p['code'], 'original' => $t['original'], 'budget' => $t['budget'], 'change' => r2($t['budget'] - $t['original']), 'actual' => $t['actual'], 'committed' => $t['committed'],
                'remaining' => r2($t['budget'] - $t['actual'] - $t['committed']), 'used' => $t['budget'] > 0 ? round(($t['actual'] + $t['committed']) / $t['budget'] * 100, 1) : '', '_class' => 'subtotal-row'];
            foreach ($grand as $k => $_) {
                $grand[$k] += $t[$k];
            }
        }
        $cols = [['key' => 'code', 'label' => 'Cost code'], csr_m('original', 'Original budget'), csr_m('budget', 'Current budget'), csr_m('change', 'Revisions'), csr_m('actual', 'Actual'),
            csr_m('committed', 'Committed'), csr_m('remaining', 'Remaining'), ['key' => 'used', 'label' => 'Used', 'type' => 'pct']];
        return ['columns' => $cols, 'rows' => $rows, 'grouped' => true, 'totals' => ['code' => 'TOTAL'] + array_map('r2', $grand) + ['change' => r2($grand['budget'] - $grand['original']), 'remaining' => r2($grand['budget'] - $grand['actual'] - $grand['committed'])],
            'notes' => 'Direct costs against the approved budget in force; the original baseline is kept beside it. Remaining = current budget less actual less committed (open purchase orders and unbilled subcontracts).'];
    },
]);

Report::define('cs_commitments', [
    'filters' => [], 'extra_filters' => csr_filters(),
    'run' => function (array $f): array {
        $ps = csr_projects($f);
        $cls = [];
        foreach ($ps as $pid => $p) {
            $cls[(int)$p['class_id']] = $pid;
        }
        $rows = [];
        if ($cls) {
            [$in, $pp] = DB::in('cmr', array_keys($cls));
            foreach (DB::all("SELECT t.id, t.txn_type, t.txn_no, t.txn_date, t.due_date, v.name AS vendor, COALESCE(l.class_id, t.class_id) AS cid, l.cost_code_id, l.description,
                                     CASE WHEN l.qty > 0 THEN GREATEST(0, l.qty - l.qty_fulfilled) * (l.amount / l.qty) ELSE GREATEST(0, l.amount - l.amount_fulfilled) END AS open_amt, l.amount
                                FROM transaction_lines l JOIN transactions t ON t.id = l.txn_id JOIN vendors v ON v.id = t.vendor_id
                               WHERE t.txn_type = 'purchase_order' AND t.is_voided = 0 AND t.status NOT IN ('closed','void') AND l.is_closed = 0 AND COALESCE(l.class_id, t.class_id) IN $in
                               ORDER BY t.txn_date, t.id", $pp) as $r) {
                if ((float)$r['open_amt'] < 0.005) {
                    continue;
                }
                $p = $ps[$cls[(int)$r['cid']]];
                $rows[] = ['project' => $p['code'], 'kind' => 'Purchase order', 'doc' => $r['txn_no'], 'vendor' => $r['vendor'], 'date' => $r['txn_date'], 'code' => CsCost::codeLabel($r['cost_code_id'] ? (int)$r['cost_code_id'] : null),
                    'description' => $r['description'], 'total' => (float)$r['amount'], 'open' => r2($r['open_amt']), '_links' => ['doc' => Report::txnLink(['id' => $r['id'], 'txn_type' => $r['txn_type']])]];
            }
            [$in2, $pp2] = DB::in('cms', array_keys($ps));
            foreach (DB::all("SELECT s.*, v.name AS vendor FROM cs_subcontracts s JOIN vendors v ON v.id = s.vendor_id WHERE s.status = 'active' AND s.project_id IN $in2 ORDER BY s.sc_no", $pp2) as $s) {
                $pos = CsSub::position((int)$s['id']);
                $open = r2(CsSub::value($s) - $pos['billed']);
                if ($open < 0.005) {
                    continue;
                }
                $rows[] = ['project' => $ps[(int)$s['project_id']]['code'], 'kind' => 'Subcontract', 'doc' => $s['sc_no'], 'vendor' => $s['vendor'], 'date' => $s['start_date'], 'code' => CsCost::codeLabel($s['cost_code_id'] ? (int)$s['cost_code_id'] : null),
                    'description' => $s['work_package'], 'total' => CsSub::value($s), 'open' => $open, '_links' => ['doc' => Report::link('subk.contracts', ['a' => 'view', 'id' => $s['id']])]];
            }
        }
        $cols = [['key' => 'project', 'label' => 'Project'], ['key' => 'kind', 'label' => 'Kind'], ['key' => 'doc', 'label' => 'Document'], ['key' => 'vendor', 'label' => 'Supplier / subcontractor'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'],
            ['key' => 'code', 'label' => 'Cost code'], ['key' => 'description', 'label' => 'Description'], csr_m('total', 'Order value'), csr_m('open', 'Still committed')];
        return ['columns' => $cols, 'rows' => $rows, 'totals' => csr_totals($rows, $cols, 'TOTAL'), 'notes' => 'A commitment is not a cost yet: it becomes one when the goods are received or the work is billed.'];
    },
]);

/** EAC rows of one project by cost code, or one row per project */
function csr_eac(array $f, bool $etcFocus): array
{
    $rows = [];
    $ps = csr_projects($f);
    $single = count($ps) === 1;
    foreach ($ps as $pid => $p) {
        $fc = CsCost::forecast($pid, $f['asof']);
        if ($single) {
            foreach ($fc['rows'] as $cc => $r) {
                $rows[] = ['item' => CsCost::codeLabel($cc ?: null) ?: 'No cost code', 'budget' => $r['budget'], 'actual' => $r['actual'], 'committed' => $r['committed'], 'etc' => $r['etc'], 'eac' => $r['eac'],
                    'variance' => $r['variance'], 'pct' => $r['eac'] > 0 ? round($r['actual'] / $r['eac'] * 100, 1) : '', '_class' => $r['variance'] < -0.5 ? 'text-danger' : ''];
            }
        } else {
            $s = CsSummary::project($pid, $f['asof']);
            $rows[] = ['item' => csr_label($p), 'budget' => $s['budget_current'], 'actual' => $s['cost_direct'], 'committed' => $s['committed']['total'], 'etc' => $s['etc'], 'eac' => $s['eac'],
                'variance' => r2($s['budget_current'] - $s['eac']), 'pct' => $s['eac'] > 0 ? round($s['cost_direct'] / $s['eac'] * 100, 1) : '', '_links' => ['item' => csr_link($pid)],
                '_class' => $s['budget_current'] - $s['eac'] < -0.5 ? 'text-danger' : ''];
        }
    }
    $cols = [['key' => 'item', 'label' => $single ? 'Cost code' : 'Project'], csr_m('budget', 'Current budget'), csr_m('actual', 'Actual to date'), csr_m('committed', 'Committed'),
        csr_m('etc', 'Estimate to complete'), csr_m('eac', 'Estimate at completion'), csr_m('variance', 'Variance (budget less EAC)'), ['key' => 'pct', 'label' => 'Spent of EAC', 'type' => 'pct']];
    if ($etcFocus) {
        $cols = [$cols[0], $cols[2], $cols[3], $cols[4], $cols[1], $cols[5], $cols[7]];
    }
    return ['columns' => $cols, 'rows' => $rows, 'totals' => csr_totals($rows, $cols, 'TOTAL', 'item'),
        'notes' => 'Estimate at completion per cost code = the larger of its budget and what is spent plus committed. Choose one project to see its cost codes.'];
}

Report::define('cs_eac', ['filters' => ['asof'], 'extra_filters' => csr_filters(), 'run' => fn(array $f): array => csr_eac($f, false)]);
Report::define('cs_etc', ['filters' => ['asof'], 'extra_filters' => csr_filters(), 'run' => fn(array $f): array => csr_eac($f, true)]);

Report::define('cs_cash_flow', [
    'filters' => ['range'], 'default_dates' => 'this_year', 'extra_filters' => csr_filters(),
    'run' => function (array $f): array {
        $ps = csr_projects($f);
        $cls = array_map(fn($p) => (int)$p['class_id'], $ps);
        $rows = [];
        if ($cls) {
            [$in, $pp] = DB::in('cfl', array_values($cls));
            $data = DB::all("SELECT DATE_FORMAT(j.txn_date, '%Y-%m') AS m, SUM(j.debit) AS cin, SUM(j.credit) AS cout FROM journal_lines j JOIN accounts a ON a.id = j.account_id
                              JOIN transactions t ON t.id = j.txn_id
                             WHERE a.account_type = 'Bank' AND j.class_id IN $in AND j.txn_date BETWEEN :f AND :t AND t.is_voided = 0 GROUP BY m ORDER BY m", $pp + ['f' => $f['from'], 't' => $f['to']]);
            // payments of bills and invoices carry the class of the documents they settle
            $settled = DB::all("SELECT DATE_FORMAT(p.txn_date, '%Y-%m') AS m, SUM(CASE WHEN p.txn_type = 'payment' THEN pa.amount ELSE 0 END) AS cin,
                                       SUM(CASE WHEN p.txn_type = 'bill_payment' THEN pa.amount ELSE 0 END) AS cout
                                  FROM payment_applications pa JOIN transactions p ON p.id = pa.payment_txn_id JOIN transactions d ON d.id = pa.target_txn_id
                                 WHERE p.is_voided = 0 AND d.class_id IN $in AND (p.class_id IS NULL OR p.class_id NOT IN $in) AND p.txn_date BETWEEN :f AND :t GROUP BY m", $pp + ['f' => $f['from'], 't' => $f['to']]);
            $by = [];
            foreach (array_merge($data, $settled) as $r) {
                $by[$r['m']]['in'] = ($by[$r['m']]['in'] ?? 0) + (float)$r['cin'];
                $by[$r['m']]['out'] = ($by[$r['m']]['out'] ?? 0) + (float)$r['cout'];
            }
            ksort($by);
            $cum = 0.0;
            foreach ($by as $m => $v) {
                $net = $v['in'] - $v['out'];
                $cum += $net;
                $rows[] = ['month' => date('M Y', strtotime($m . '-01')), 'in' => r2($v['in']), 'out' => r2($v['out']), 'net' => r2($net), 'cum' => r2($cum)];
            }
        }
        $cols = [['key' => 'month', 'label' => 'Month'], csr_m('in', 'Cash in (collections)'), csr_m('out', 'Cash out (payments)'), csr_m('net', 'Net'), csr_m('cum', 'Cumulative')];
        $t = csr_totals($rows, $cols, 'TOTAL', 'month');
        $t['cum'] = '';
        return ['columns' => $cols, 'rows' => $rows, 'totals' => $t, 'sortable' => false,
            'chart' => ['bars_title' => 'Net cash by month', 'bars' => array_column(array_map(fn($r) => ['k' => $r['month'], 'v' => $r['net']], $rows), 'v', 'k')],
            'notes' => 'Money received and paid for the projects chosen: customer payments of their invoices, bill payments of their bills, and cash vouchers and checks charged to them.'];
    },
]);

Report::define('cs_forecast', [
    'filters' => ['asof'], 'extra_filters' => csr_filters(),
    'run' => function (array $f): array {
        $rows = [];
        foreach (csr_projects($f) as $pid => $p) {
            $s = CsSummary::project($pid, $f['asof']);
            $rows[] = ['project' => csr_label($p), 'contract' => $s['contract_current'], 'pending' => $s['variations_pending'], 'billed' => $s['certified_gross'], 'to_bill' => r2($s['contract_current'] - $s['certified_gross']),
                'cost' => $s['cost_direct'], 'etc' => $s['etc'], 'eac' => $s['eac'], 'profit' => $s['projected_profit'], 'margin' => $s['projected_margin'], 'end' => $s['end_date'], 'forecast_end' => $s['forecast_end'],
                '_links' => ['project' => csr_link($pid)], '_class' => $s['behind'] || $s['projected_profit'] < 0 ? 'text-danger' : ''];
        }
        $cols = [['key' => 'project', 'label' => 'Project'], csr_m('contract', 'Contract value'), csr_m('pending', 'Changes pending'), csr_m('billed', 'Billed'), csr_m('to_bill', 'Still to bill'), csr_m('cost', 'Cost to date'),
            csr_m('etc', 'Cost to complete'), csr_m('eac', 'Cost at completion'), csr_m('profit', 'Projected profit'), ['key' => 'margin', 'label' => 'Margin', 'type' => 'pct'], ['key' => 'end', 'label' => 'Contract finish', 'type' => 'date'],
            ['key' => 'forecast_end', 'label' => 'Forecast finish', 'type' => 'date']];
        return ['columns' => $cols, 'rows' => $rows, 'totals' => csr_totals($rows, $cols), 'notes' => 'The forecast finish extends the time taken so far at the present rate of physical progress.'];
    },
]);

Report::define('cs_wip', [
    'filters' => ['asof'], 'extra_filters' => csr_filters(),
    'run' => function (array $f): array {
        $rows = [];
        foreach (csr_projects($f) as $pid => $p) {
            $s = CsSummary::project($pid, $f['asof']);
            $pct = $s['eac'] > 0 ? min(100, $s['cost_direct'] / $s['eac'] * 100) : 0;
            $earned = r2($s['contract_current'] * $pct / 100);
            $diff = $earned - $s['certified_gross'];
            $rows[] = ['project' => csr_label($p), 'contract' => $s['contract_current'], 'eac' => $s['eac'], 'profit' => $s['projected_profit'], 'cost' => $s['cost_direct'], 'pct' => round($pct, 1),
                'earned' => $earned, 'billed' => $s['certified_gross'], 'under' => $diff > 0 ? r2($diff) : 0.0, 'over' => $diff < 0 ? r2(-$diff) : 0.0, 'gp' => r2($earned - $s['cost_direct']),
                'recognized' => $s['contract_revenue'], '_links' => ['project' => csr_link($pid)]];
        }
        $cols = [['key' => 'project', 'label' => 'Project'], csr_m('contract', 'Contract'), csr_m('eac', 'Estimated cost'), csr_m('profit', 'Estimated profit'), csr_m('cost', 'Cost to date'),
            ['key' => 'pct', 'label' => 'Complete (cost)', 'type' => 'pct'], csr_m('earned', 'Earned revenue'), csr_m('billed', 'Billed'), csr_m('under', 'Under-billings'), csr_m('over', 'Over-billings'),
            csr_m('gp', 'Gross profit to date'), csr_m('recognized', 'Revenue in the books')];
        return ['columns' => $cols, 'rows' => $rows, 'totals' => csr_totals($rows, $cols), 'notes' => 'Work-in-progress schedule by the cost-to-cost method: earned revenue = contract x cost to date / estimated cost.'];
    },
]);

Report::define('cs_earned_billed', [
    'filters' => ['asof'], 'extra_filters' => csr_filters(),
    'run' => function (array $f): array {
        $rows = [];
        foreach (csr_projects($f) as $pid => $p) {
            $s = CsSummary::project($pid, $f['asof']);
            $rows[] = ['project' => csr_label($p), 'contract' => $s['contract_current'], 'physical' => $s['physical_pct'], 'earned' => $s['work_done_value'], 'billed' => $s['certified_gross'],
                'invoiced' => $s['invoiced'], 'collected' => $s['collected'], 'unbilled' => r2($s['work_done_value'] - $s['certified_gross']), 'uncollected' => $s['ar_open'],
                '_links' => ['project' => csr_link($pid)]];
        }
        $cols = [['key' => 'project', 'label' => 'Project'], csr_m('contract', 'Contract'), ['key' => 'physical', 'label' => 'Work done', 'type' => 'pct'], csr_m('earned', 'Value of work done'), csr_m('billed', 'Billed (gross)'),
            csr_m('invoiced', 'Invoiced (net)'), csr_m('collected', 'Collected'), csr_m('unbilled', 'Done, not billed'), csr_m('uncollected', 'Billed, not collected')];
        return ['columns' => $cols, 'rows' => $rows, 'totals' => csr_totals($rows, $cols)];
    },
]);

Report::define('cs_client_profit', [
    'filters' => ['range'], 'default_dates' => 'all', 'extra_filters' => csr_filters(false),
    'run' => function (array $f): array {
        $ps = csr_projects($f);
        $cost = CsCost::costByType(array_keys($ps), $f['to'], $f['from']);
        $rev = CsCost::revenue(array_keys($ps), $f['to'], $f['from']);
        $by = [];
        foreach ($ps as $pid => $p) {
            $k = (int)$p['customer_id'];
            $by[$k] ??= ['client' => (string)$p['customer_name'], 'projects' => 0, 'contract' => 0.0, 'revenue' => 0.0, 'cost' => 0.0, 'overhead' => 0.0, 'ar' => 0.0];
            $by[$k]['projects']++;
            $by[$k]['contract'] += CsProject::contractValue($pid)['current'];
            $by[$k]['revenue'] += (float)($rev[$pid] ?? 0);
            $by[$k]['cost'] += (float)($cost[$pid]['direct'] ?? 0);
            $by[$k]['overhead'] += (float)($cost[$pid]['overhead'] ?? 0);
            $by[$k]['ar'] += CsSummary::project($pid)['ar_open'];
        }
        $rows = [];
        foreach ($by as $cid => $c) {
            $gp = $c['revenue'] - $c['cost'];
            $rows[] = ['client' => $c['client'], 'projects' => $c['projects'], 'contract' => r2($c['contract']), 'revenue' => r2($c['revenue']), 'cost' => r2($c['cost']), 'gp' => r2($gp),
                'margin' => $c['revenue'] != 0.0 ? round($gp / $c['revenue'] * 100, 1) : '', 'net' => r2($gp - $c['overhead']), 'ar' => r2($c['ar']), '_links' => ['client' => Report::link('proj.clients', ['a' => 'view', 'id' => $cid])]];
        }
        $cols = [['key' => 'client', 'label' => 'Client'], ['key' => 'projects', 'label' => 'Projects', 'type' => 'int'], csr_m('contract', 'Contract value'), csr_m('revenue', 'Revenue'), csr_m('cost', 'Direct cost'),
            csr_m('gp', 'Gross profit'), ['key' => 'margin', 'label' => 'Margin', 'type' => 'pct'], csr_m('net', 'After overhead'), csr_m('ar', 'Receivable')];
        return ['columns' => $cols, 'rows' => $rows, 'totals' => csr_totals($rows, $cols, 'TOTAL', 'client')];
    },
]);

// =====================================================================================================================
// Billing and collections
// =====================================================================================================================

Report::define('cs_billing', [
    'filters' => ['range'], 'default_dates' => 'this_year', 'extra_filters' => csr_filters(false) + ['bstatus' => ['Billing status', fn() => CsBilling::STATUSES, 'Every status']],
    'run' => function (array $f): array {
        $ps = csr_projects($f);
        $rows = [];
        if ($ps) {
            [$in, $pp] = DB::in('rbl', array_keys($ps));
            $w = '';
            if (!empty($f['bstatus'])) {
                $w = ' AND b.status = :bst';
                $pp['bst'] = $f['bstatus'];
            }
            foreach (DB::all("SELECT b.*, c.name AS customer FROM cs_billings b JOIN customers c ON c.id = b.customer_id WHERE b.project_id IN $in AND b.billing_date BETWEEN :f AND :t $w ORDER BY b.billing_date, b.id",
                $pp + ['f' => $f['from'], 't' => $f['to']]) as $b) {
                $rows[] = ['billing' => $b['billing_no'], 'project' => $ps[(int)$b['project_id']]['code'], 'client' => $b['customer'], 'type' => CsBilling::shortType($b['billing_type']), 'date' => $b['billing_date'],
                    'gross' => (float)$b['this_gross'] + (float)$b['materials_stored'], 'retention' => (float)$b['retention_amount'], 'recoup' => (float)$b['advance_recoupment'], 'other' => (float)$b['other_deductions'],
                    'vat' => (float)$b['vat_amount'], 'due' => (float)($b['certified_amount'] ?? $b['amount_applied']), 'status' => CsBilling::STATUSES[$b['status']] ?? $b['status'],
                    '_links' => ['billing' => Report::link('pbill.billings', ['a' => 'view', 'id' => $b['id']])]];
            }
        }
        $cols = [['key' => 'billing', 'label' => 'Billing'], ['key' => 'project', 'label' => 'Project'], ['key' => 'client', 'label' => 'Client'], ['key' => 'type', 'label' => 'Kind'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'],
            csr_m('gross', 'Gross'), csr_m('retention', 'Retention'), csr_m('recoup', 'Recoupment'), csr_m('other', 'Other deductions'), csr_m('vat', 'VAT'), csr_m('due', 'Amount due'), ['key' => 'status', 'label' => 'Status']];
        return ['columns' => $cols, 'rows' => $rows, 'totals' => csr_totals($rows, $cols, 'TOTAL', 'billing')];
    },
]);

Report::define('cs_collections', [
    'filters' => ['range'], 'default_dates' => 'this_year', 'extra_filters' => csr_filters(false),
    'run' => function (array $f): array {
        $ps = csr_projects($f);
        $cls = [];
        foreach ($ps as $pid => $p) {
            $cls[(int)$p['class_id']] = $p;
        }
        $rows = [];
        if ($cls) {
            [$in, $pp] = DB::in('rco', array_keys($cls));
            foreach (DB::all("SELECT p.id, p.txn_type, p.txn_no, p.txn_date, p.ref_no, c.name AS customer, d.id AS did, d.txn_type AS dtype, d.txn_no AS dno, d.class_id, pa.amount, pa.withholding, pa.discount
                                FROM payment_applications pa JOIN transactions p ON p.id = pa.payment_txn_id JOIN transactions d ON d.id = pa.target_txn_id JOIN customers c ON c.id = d.customer_id
                               WHERE p.txn_type IN ('payment','credit_memo') AND p.is_voided = 0 AND d.class_id IN $in AND p.txn_date BETWEEN :f AND :t ORDER BY p.txn_date, p.id", $pp + ['f' => $f['from'], 't' => $f['to']]) as $r) {
                $rows[] = ['date' => $r['txn_date'], 'receipt' => Txn::label($r['txn_type']) . ' ' . $r['txn_no'], 'client' => $r['customer'], 'project' => $cls[(int)$r['class_id']]['code'], 'invoice' => $r['dno'],
                    'amount' => (float)$r['amount'], 'withholding' => (float)$r['withholding'], 'discount' => (float)$r['discount'], 'settled' => r2((float)$r['amount'] + (float)$r['withholding'] + (float)$r['discount']),
                    '_links' => ['receipt' => Report::txnLink(['id' => $r['id'], 'txn_type' => $r['txn_type']]), 'invoice' => Report::txnLink(['id' => $r['did'], 'txn_type' => $r['dtype']])]];
            }
        }
        $cols = [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'receipt', 'label' => 'Receipt'], ['key' => 'client', 'label' => 'Client'], ['key' => 'project', 'label' => 'Project'], ['key' => 'invoice', 'label' => 'Invoice'],
            csr_m('amount', 'Cash received'), csr_m('withholding', 'Tax withheld'), csr_m('discount', 'Discount'), csr_m('settled', 'Invoice settled')];
        return ['columns' => $cols, 'rows' => $rows, 'totals' => csr_totals($rows, $cols, 'TOTAL', 'date')];
    },
]);

Report::define('cs_ar_aging', [
    'filters' => ['asof'], 'extra_filters' => csr_filters(false),
    'run' => function (array $f): array {
        $ps = csr_projects($f);
        $cls = [];
        foreach ($ps as $pid => $p) {
            $cls[(int)$p['class_id']] = $p;
        }
        $rows = [];
        if ($cls) {
            [$in, $pp] = DB::in('rag', array_keys($cls));
            foreach (DB::all("SELECT t.*, c.name AS customer FROM transactions t JOIN customers c ON c.id = t.customer_id WHERE t.txn_type IN ('invoice','statement_charge') AND t.is_voided = 0 AND t.is_pending = 0
                               AND t.class_id IN $in AND t.txn_date <= :d ORDER BY t.due_date", $pp + ['d' => $f['asof']]) as $t) {
                $open = r2((float)$t['total'] - Balances::applied((int)$t['id'], $f['asof']));
                if ($open < 0.005) {
                    continue;
                }
                $days = $t['due_date'] ? (int)floor((strtotime($f['asof']) - strtotime($t['due_date'])) / 86400) : 0;
                $b = $days <= 0 ? 'cur' : ($days <= 30 ? 'd30' : ($days <= 60 ? 'd60' : ($days <= 90 ? 'd90' : 'd91')));
                $rows[] = ['invoice' => $t['txn_no'], 'client' => $t['customer'], 'project' => $cls[(int)$t['class_id']]['code'], 'date' => $t['txn_date'], 'due' => $t['due_date'], 'days' => max(0, $days),
                    'cur' => $b === 'cur' ? $open : 0.0, 'd30' => $b === 'd30' ? $open : 0.0, 'd60' => $b === 'd60' ? $open : 0.0, 'd90' => $b === 'd90' ? $open : 0.0, 'd91' => $b === 'd91' ? $open : 0.0, 'open' => $open,
                    '_links' => ['invoice' => Report::txnLink($t)]];
            }
        }
        $cols = [['key' => 'invoice', 'label' => 'Invoice'], ['key' => 'client', 'label' => 'Client'], ['key' => 'project', 'label' => 'Project'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'due', 'label' => 'Due', 'type' => 'date'],
            ['key' => 'days', 'label' => 'Days overdue', 'type' => 'int'], csr_m('cur', 'Current'), csr_m('d30', '1-30'), csr_m('d60', '31-60'), csr_m('d90', '61-90'), csr_m('d91', 'Over 90'), csr_m('open', 'Open balance')];
        $t = csr_totals($rows, $cols, 'TOTAL', 'invoice');
        $t['days'] = '';
        return ['columns' => $cols, 'rows' => $rows, 'totals' => $t, 'notes' => 'Retention is not in these figures until it is billed for release.'];
    },
]);

Report::define('cs_retention', [
    'filters' => ['asof'], 'extra_filters' => csr_filters(),
    'run' => function (array $f): array {
        $rows = [];
        foreach (csr_projects($f) as $pid => $p) {
            $rec = (float)(CsCost::balance('cs_retention_receivable', [$pid], $f['asof'])[$pid] ?? 0);
            $pay = -(float)(CsCost::balance('cs_retention_payable', [$pid], $f['asof'])[$pid] ?? 0);
            $adv = -(float)(CsCost::balance('cs_client_advances', [$pid], $f['asof'])[$pid] ?? 0);
            if (abs($rec) < 0.005 && abs($pay) < 0.005 && abs($adv) < 0.005) {
                continue;
            }
            $rows[] = ['project' => csr_label($p), 'status' => CsUi::label($p['status']), 'receivable' => r2($rec), 'payable' => r2($pay), 'net' => r2($rec - $pay), 'advance' => r2($adv), 'warranty' => $p['warranty_end_date'],
                '_links' => ['project' => csr_link($pid)]];
        }
        $cols = [['key' => 'project', 'label' => 'Project'], ['key' => 'status', 'label' => 'Status'], csr_m('receivable', 'Retention receivable'), csr_m('payable', 'Retention payable'), csr_m('net', 'Net'),
            csr_m('advance', 'Client advance not recouped'), ['key' => 'warranty', 'label' => 'Warranty ends', 'type' => 'date']];
        return ['columns' => $cols, 'rows' => $rows, 'totals' => csr_totals($rows, $cols)];
    },
]);

// =====================================================================================================================
// Costs and resources
// =====================================================================================================================

Report::define('cs_ap', [
    'filters' => ['asof'], 'extra_filters' => csr_filters(false),
    'run' => function (array $f): array {
        $ps = csr_projects($f);
        $cls = [];
        foreach ($ps as $pid => $p) {
            $cls[(int)$p['class_id']] = $p;
        }
        $rows = [];
        if ($cls) {
            [$in, $pp] = DB::in('rap', array_keys($cls));
            foreach (DB::all("SELECT t.*, v.name AS vendor, v.vendor_kind FROM transactions t JOIN vendors v ON v.id = t.vendor_id WHERE t.txn_type = 'bill' AND t.is_voided = 0 AND t.class_id IN $in AND t.txn_date <= :d ORDER BY t.due_date",
                $pp + ['d' => $f['asof']]) as $t) {
                $open = r2((float)$t['total'] - Balances::applied((int)$t['id'], $f['asof']));
                if ($open < 0.005) {
                    continue;
                }
                $days = $t['due_date'] ? (int)floor((strtotime($f['asof']) - strtotime($t['due_date'])) / 86400) : 0;
                $rows[] = ['bill' => $t['txn_no'], 'vendor' => $t['vendor'], 'kind' => CsUi::label($t['vendor_kind']), 'project' => $cls[(int)$t['class_id']]['code'], 'ref' => (string)$t['ref_no'], 'date' => $t['txn_date'],
                    'due' => $t['due_date'], 'days' => max(0, $days), 'total' => (float)$t['total'], 'open' => $open, '_links' => ['bill' => Report::txnLink($t)], '_class' => $days > 0 ? 'text-danger' : ''];
            }
        }
        $cols = [['key' => 'bill', 'label' => 'Bill'], ['key' => 'vendor', 'label' => 'Supplier / subcontractor'], ['key' => 'kind', 'label' => 'Kind'], ['key' => 'project', 'label' => 'Project'], ['key' => 'ref', 'label' => 'Their invoice'],
            ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'due', 'label' => 'Due', 'type' => 'date'], ['key' => 'days', 'label' => 'Days overdue', 'type' => 'int'], csr_m('total', 'Amount'), csr_m('open', 'Open')];
        $t = csr_totals($rows, $cols, 'TOTAL', 'bill');
        $t['days'] = '';
        return ['columns' => $cols, 'rows' => $rows, 'totals' => $t];
    },
]);

Report::define('cs_materials', [
    'filters' => ['range'], 'default_dates' => 'all', 'extra_filters' => csr_filters(false),
    'run' => function (array $f): array {
        $rows = [];
        foreach (csr_projects($f) as $pid => $p) {
            $data = DB::all("SELECT l.item_id, i.code, i.name, i.unit, SUM(CASE WHEN d.doc_type = 'issue' AND d.project_id = :p1 THEN l.qty ELSE 0 END) AS issued,
                                    SUM(CASE WHEN d.doc_type = 'return' AND d.project_id = :p2 THEN l.qty ELSE 0 END) AS returned,
                                    SUM(CASE WHEN d.doc_type = 'consume' AND d.project_id = :p3 THEN l.qty ELSE 0 END) AS consumed,
                                    SUM(CASE WHEN d.doc_type = 'consume' AND d.project_id = :p4 THEN l.qty_wasted ELSE 0 END) AS wasted,
                                    SUM(CASE WHEN d.doc_type = 'transfer' AND d.project_id = :p5 THEN l.qty ELSE 0 END) AS t_out,
                                    SUM(CASE WHEN d.doc_type = 'transfer' AND d.to_project_id = :p6 THEN l.qty ELSE 0 END) AS t_in,
                                    SUM(CASE WHEN d.project_id = :p7 AND d.doc_type IN ('issue','consume') THEN l.amount WHEN d.project_id = :p8 AND d.doc_type = 'return' THEN -l.amount ELSE 0 END) AS cost
                               FROM cs_material_doc_lines l JOIN cs_material_docs d ON d.id = l.doc_id JOIN items i ON i.id = l.item_id
                              WHERE d.status = 'posted' AND (d.project_id = :p9 OR d.to_project_id = :p10) AND d.doc_date BETWEEN :f AND :t GROUP BY l.item_id, i.code, i.name, i.unit ORDER BY i.name",
                ['p1' => $pid, 'p2' => $pid, 'p3' => $pid, 'p4' => $pid, 'p5' => $pid, 'p6' => $pid, 'p7' => $pid, 'p8' => $pid, 'p9' => $pid, 'p10' => $pid, 'f' => $f['from'], 't' => $f['to']]);
            if (!$data) {
                continue;
            }
            $rows[] = ['material' => csr_label($p), '_class' => 'group-row', '_links' => ['material' => csr_link($pid)]];
            foreach ($data as $d) {
                $rows[] = ['material' => $d['code'] . ' ' . $d['name'], 'unit' => $d['unit'], 'issued' => (float)$d['issued'], 'returned' => (float)$d['returned'], 'consumed' => (float)$d['consumed'], 'wasted' => (float)$d['wasted'],
                    'waste_pct' => (float)$d['consumed'] > 0 ? round((float)$d['wasted'] / (float)$d['consumed'] * 100, 1) : '', 'transfers' => r2((float)$d['t_in'] - (float)$d['t_out']), 'cost' => r2($d['cost']), '_class' => 'indent-1'];
            }
        }
        $cols = [['key' => 'material', 'label' => 'Material'], ['key' => 'unit', 'label' => 'Unit'], ['key' => 'issued', 'label' => 'Issued', 'type' => 'qty'], ['key' => 'returned', 'label' => 'Returned', 'type' => 'qty'],
            ['key' => 'consumed', 'label' => 'Used (reported)', 'type' => 'qty'], ['key' => 'wasted', 'label' => 'Wasted', 'type' => 'qty'], ['key' => 'waste_pct', 'label' => 'Waste', 'type' => 'pct'],
            ['key' => 'transfers', 'label' => 'Transfers in less out', 'type' => 'qty'], ['key' => 'cost', 'label' => 'Cost charged', 'type' => 'money', 'sensitive' => 'cost']];
        return ['columns' => $cols, 'rows' => $rows, 'grouped' => true];
    },
]);

Report::define('cs_labor', [
    'filters' => ['range'], 'default_dates' => 'this_month', 'extra_filters' => csr_filters(false),
    'run' => function (array $f): array {
        $ps = csr_projects($f);
        $rows = [];
        if ($ps) {
            [$in, $pp] = DB::in('rlb', array_keys($ps));
            foreach (DB::all("SELECT t.project_id, w.id, w.full_name, w.worker_no, tr.name AS trade, w.classification, COUNT(DISTINCT CASE WHEN l.regular_hours + l.ot_hours > 0 THEN l.work_date END) AS days,
                                     SUM(l.regular_hours) AS reg, SUM(l.ot_hours) AS ot, SUM(l.is_absent) AS absent, SUM(l.late_minutes) AS late, SUM(l.labor_cost + l.allowance) AS pay, SUM(l.burden_cost) AS burden
                                FROM cs_timesheet_lines l JOIN cs_timesheets t ON t.id = l.timesheet_id JOIN cs_workers w ON w.id = l.worker_id LEFT JOIN cs_trades tr ON tr.id = w.trade_id
                               WHERE t.project_id IN $in AND t.status IN ('approved','posted') AND t.work_date BETWEEN :f AND :t
                               GROUP BY t.project_id, w.id, w.full_name, w.worker_no, tr.name, w.classification ORDER BY t.project_id, w.full_name", $pp + ['f' => $f['from'], 't' => $f['to']]) as $r) {
                $rows[] = ['project' => $ps[(int)$r['project_id']]['code'], 'worker' => $r['full_name'] . ' (' . $r['worker_no'] . ')', 'trade' => (string)$r['trade'], 'class' => CsLabor::CLASSES[$r['classification']] ?? '',
                    'days' => (float)$r['days'], 'reg' => (float)$r['reg'], 'ot' => (float)$r['ot'], 'absent' => (int)$r['absent'], 'late' => (int)$r['late'], 'pay' => r2($r['pay']), 'burden' => r2($r['burden']),
                    'total' => r2((float)$r['pay'] + (float)$r['burden']), '_links' => ['worker' => Report::link('labor.workers', ['a' => 'view', 'id' => $r['id']])]];
            }
        }
        $cols = [['key' => 'project', 'label' => 'Project'], ['key' => 'worker', 'label' => 'Worker'], ['key' => 'trade', 'label' => 'Trade'], ['key' => 'class', 'label' => 'Classification', 'optional' => true], ['key' => 'days', 'label' => 'Days', 'type' => 'qty'],
            ['key' => 'reg', 'label' => 'Regular hours', 'type' => 'qty'], ['key' => 'ot', 'label' => 'Overtime hours', 'type' => 'qty'], ['key' => 'absent', 'label' => 'Absences', 'type' => 'int'], ['key' => 'late', 'label' => 'Minutes late', 'type' => 'int', 'optional' => true],
            ['key' => 'pay', 'label' => 'Pay', 'type' => 'money', 'sensitive' => 'cost'], ['key' => 'burden', 'label' => 'Burden', 'type' => 'money', 'sensitive' => 'cost'], ['key' => 'total', 'label' => 'Labor cost', 'type' => 'money', 'sensitive' => 'cost']];
        return ['columns' => $cols, 'rows' => $rows, 'totals' => csr_totals($rows, $cols)];
    },
]);

Report::define('cs_productivity', [
    'filters' => ['asof'], 'extra_filters' => csr_filters(false),
    'run' => function (array $f): array {
        $rows = [];
        foreach (csr_projects($f) as $pid => $p) {
            $hours = DB::pairs("SELECT l.activity_id, SUM(l.regular_hours + l.ot_hours) FROM cs_timesheet_lines l JOIN cs_timesheets t ON t.id = l.timesheet_id
                                 WHERE t.project_id = :p AND t.status IN ('approved','posted') AND l.activity_id IS NOT NULL AND t.work_date <= :d GROUP BY l.activity_id", ['p' => $pid, 'd' => $f['asof']]);
            $acts = DB::all('SELECT * FROM cs_activities WHERE project_id = :p AND is_milestone = 0 AND id NOT IN (SELECT parent_id FROM cs_activities WHERE parent_id IS NOT NULL) ORDER BY sort_order', ['p' => $pid]);
            if (!$acts) {
                continue;
            }
            $rows[] = ['activity' => csr_label($p), '_class' => 'group-row', '_links' => ['activity' => csr_link($pid)]];
            foreach ($acts as $a) {
                $actual = (float)($hours[$a['id']] ?? 0);
                $earnedH = (float)$a['budget_hours'] * (float)$a['progress_pct'] / 100;
                $rows[] = ['activity' => $a['wbs_code'] . ' ' . $a['name'], 'unit' => (string)$a['unit'], 'planned' => (float)$a['planned_qty'], 'done' => (float)$a['qty_done'], 'progress' => (float)$a['progress_pct'],
                    'budget_h' => (float)$a['budget_hours'], 'earned_h' => r2($earnedH), 'actual_h' => r2($actual), 'rate' => $actual > 0 && (float)$a['qty_done'] > 0 ? round((float)$a['qty_done'] / $actual, 3) : '',
                    'pf' => $actual > 0 ? round($earnedH / $actual, 2) : '', '_class' => 'indent-1' . ($actual > 0 && $earnedH / $actual < 0.85 ? ' text-danger' : '')];
            }
        }
        $cols = [['key' => 'activity', 'label' => 'Activity'], ['key' => 'unit', 'label' => 'Unit'], ['key' => 'planned', 'label' => 'Planned qty', 'type' => 'qty'], ['key' => 'done', 'label' => 'Done', 'type' => 'qty'],
            ['key' => 'progress', 'label' => 'Progress', 'type' => 'pct'], ['key' => 'budget_h', 'label' => 'Budget man-hours', 'type' => 'qty'], ['key' => 'earned_h', 'label' => 'Earned man-hours', 'type' => 'qty'],
            ['key' => 'actual_h', 'label' => 'Actual man-hours', 'type' => 'qty'], ['key' => 'rate', 'label' => 'Output per man-hour', 'type' => 'qty'], ['key' => 'pf', 'label' => 'Performance factor', 'type' => 'qty']];
        return ['columns' => $cols, 'rows' => $rows, 'grouped' => true, 'notes' => 'Performance factor = earned man-hours / actual man-hours; below 1.00 the crew is slower than planned.'];
    },
]);

Report::define('cs_equipment', [
    'filters' => ['range'], 'default_dates' => 'this_month', 'extra_filters' => csr_filters(false),
    'run' => function (array $f): array {
        $ps = csr_projects($f);
        $rows = [];
        if ($ps) {
            [$in, $pp] = DB::in('req', array_keys($ps));
            foreach (DB::all("SELECT l.project_id, e.id, e.code, e.name, e.ownership, COUNT(*) AS days, SUM(l.operating_hours) AS op, SUM(l.idle_hours) AS idle, SUM(l.breakdown_hours) AS bd, SUM(l.fuel_liters) AS fuel,
                                     SUM(l.fuel_cost) AS fuel_cost, SUM(l.charge_amount) AS charge
                                FROM cs_equipment_logs l JOIN cs_equipment e ON e.id = l.equipment_id WHERE l.project_id IN $in AND l.status IN ('approved','posted') AND l.log_date BETWEEN :f AND :t
                               GROUP BY l.project_id, e.id, e.code, e.name, e.ownership ORDER BY e.code", $pp + ['f' => $f['from'], 't' => $f['to']]) as $r) {
                $tot = (float)$r['op'] + (float)$r['idle'] + (float)$r['bd'];
                $rows[] = ['unit' => $r['code'] . ' ' . $r['name'], 'project' => $ps[(int)$r['project_id']]['code'], 'ownership' => ucfirst($r['ownership']), 'days' => (int)$r['days'], 'op' => (float)$r['op'], 'idle' => (float)$r['idle'],
                    'bd' => (float)$r['bd'], 'util' => $tot > 0 ? round((float)$r['op'] / $tot * 100, 1) : '', 'fuel' => (float)$r['fuel'], 'lph' => (float)$r['op'] > 0 ? round((float)$r['fuel'] / (float)$r['op'], 2) : '',
                    'charge' => r2($r['charge']), '_links' => ['unit' => Report::link('equip.register', ['a' => 'view', 'id' => $r['id']])]];
            }
        }
        $cols = [['key' => 'unit', 'label' => 'Unit'], ['key' => 'project', 'label' => 'Project'], ['key' => 'ownership', 'label' => 'Ownership'], ['key' => 'days', 'label' => 'Days', 'type' => 'int'],
            ['key' => 'op', 'label' => 'Operating h', 'type' => 'qty'], ['key' => 'idle', 'label' => 'Idle h', 'type' => 'qty'], ['key' => 'bd', 'label' => 'Breakdown h', 'type' => 'qty'], ['key' => 'util', 'label' => 'Utilization', 'type' => 'pct'],
            ['key' => 'fuel', 'label' => 'Fuel (L)', 'type' => 'qty'], ['key' => 'lph', 'label' => 'Liters per hour', 'type' => 'qty'], csr_m('charge', 'Charged to the project')];
        $t = csr_totals($rows, $cols, 'TOTAL', 'unit');
        $t['util'] = '';
        $t['lph'] = '';
        return ['columns' => $cols, 'rows' => $rows, 'totals' => $t];
    },
]);

Report::define('cs_subcon', [
    'filters' => [], 'extra_filters' => csr_filters(false) + ['vendor_f' => ['Subcontractor', fn() => DB::pairs("SELECT DISTINCT v.id, v.name FROM vendors v JOIN cs_subcontracts s ON s.vendor_id = v.id ORDER BY v.name"), 'All']],
    'run' => function (array $f): array {
        $ps = csr_projects($f);
        $rows = [];
        if ($ps) {
            [$in, $pp] = DB::in('rsc', array_keys($ps));
            $w = '';
            if (!empty($f['vendor_f'])) {
                $w = ' AND s.vendor_id = :vf';
                $pp['vf'] = (int)$f['vendor_f'];
            }
            foreach (DB::all("SELECT s.*, v.name AS vendor FROM cs_subcontracts s JOIN vendors v ON v.id = s.vendor_id WHERE s.project_id IN $in AND s.status NOT IN ('draft','rejected') $w ORDER BY v.name, s.sc_no", $pp) as $s) {
                $pos = CsSub::position((int)$s['id']);
                $val = CsSub::value($s);
                $paid = (float)DB::value("SELECT COALESCE(SUM(t.total),0) FROM cs_sub_billings b JOIN transactions t ON t.id = b.bill_txn_id WHERE b.subcontract_id = :s AND t.is_voided = 0", ['s' => $s['id']]);
                $open = (float)array_sum(array_map(fn($t) => Balances::open($t), DB::all('SELECT t.* FROM cs_sub_billings b JOIN transactions t ON t.id = b.bill_txn_id WHERE b.subcontract_id = :s AND t.is_voided = 0', ['s' => $s['id']])));
                $rows[] = ['sc' => $s['sc_no'] . ' ' . $s['work_package'], 'vendor' => $s['vendor'], 'project' => $ps[(int)$s['project_id']]['code'], 'value' => $val, 'billed' => $pos['billed'],
                    'pct' => $val > 0 ? round($pos['billed'] / $val * 100, 1) : '', 'to_bill' => r2($val - $pos['billed']), 'retained' => r2($pos['retained'] - $pos['released']), 'paid' => r2($paid - $open), 'open' => r2($open),
                    'status' => CsUi::label($s['status']), '_links' => ['sc' => Report::link('subk.contracts', ['a' => 'view', 'id' => $s['id']])]];
            }
        }
        $cols = [['key' => 'sc', 'label' => 'Subcontract'], ['key' => 'vendor', 'label' => 'Subcontractor'], ['key' => 'project', 'label' => 'Project'], csr_m('value', 'Value'), csr_m('billed', 'Billed'), ['key' => 'pct', 'label' => 'Billed %', 'type' => 'pct'],
            csr_m('to_bill', 'Still to bill'), csr_m('retained', 'Retention held'), csr_m('paid', 'Paid'), csr_m('open', 'Unpaid'), ['key' => 'status', 'label' => 'Status']];
        $t = csr_totals($rows, $cols, 'TOTAL', 'sc');
        $t['pct'] = '';
        return ['columns' => $cols, 'rows' => $rows, 'totals' => $t];
    },
]);

Report::define('cs_allocations', [
    'filters' => ['range'], 'default_dates' => 'this_year', 'extra_filters' => csr_filters(false),
    'run' => function (array $f): array {
        $ps = csr_projects($f);
        $rows = [];
        if ($ps) {
            [$in, $pp] = DB::in('ral', array_keys($ps));
            foreach (DB::all("SELECT a.alloc_no, a.id, a.alloc_date, a.description, a.method, ac.code, ac.name AS acct, l.project_id, l.pct, l.amount FROM cs_allocation_lines l JOIN cs_allocations a ON a.id = l.allocation_id
                               JOIN accounts ac ON ac.id = a.source_account_id WHERE a.status = 'posted' AND l.project_id IN $in AND a.alloc_date BETWEEN :f AND :t ORDER BY a.alloc_date, a.id", $pp + ['f' => $f['from'], 't' => $f['to']]) as $r) {
                $rows[] = ['alloc' => $r['alloc_no'], 'date' => $r['alloc_date'], 'project' => $ps[(int)$r['project_id']]['code'], 'account' => $r['code'] . ' ' . $r['acct'], 'description' => $r['description'],
                    'basis' => CsAlloc::METHODS[$r['method']] ?? $r['method'], 'pct' => (float)$r['pct'], 'amount' => (float)$r['amount'], '_links' => ['alloc' => Report::link('cost.allocations', ['a' => 'view', 'id' => $r['id']])]];
            }
        }
        $cols = [['key' => 'alloc', 'label' => 'Allocation'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'project', 'label' => 'Project'], ['key' => 'account', 'label' => 'Account'],
            ['key' => 'description', 'label' => 'Description'], ['key' => 'basis', 'label' => 'Basis'], ['key' => 'pct', 'label' => 'Share', 'type' => 'pct'], csr_m('amount', 'Amount')];
        return ['columns' => $cols, 'rows' => $rows, 'totals' => csr_totals($rows, $cols, 'TOTAL', 'alloc'), 'notes' => 'Posted allocations only; reversed ones are left out.'];
    },
]);

Report::define('cs_supplier_spend', [
    'filters' => ['range'], 'default_dates' => 'this_year', 'extra_filters' => csr_filters(false),
    'run' => function (array $f): array {
        $ps = csr_projects($f);
        $cls = [];
        foreach ($ps as $pid => $p) {
            $cls[(int)$p['class_id']] = $p;
        }
        $rows = [];
        if ($cls) {
            [$in, $pp] = DB::in('rss', array_keys($cls));
            foreach (DB::all("SELECT v.id, v.name, v.vendor_kind, COALESCE(l.class_id, t.class_id) AS cid, COUNT(DISTINCT t.id) AS docs,
                                     SUM(CASE WHEN t.txn_type = 'vendor_credit' THEN -l.amount ELSE l.amount END) AS amt
                                FROM transaction_lines l JOIN transactions t ON t.id = l.txn_id JOIN vendors v ON v.id = t.vendor_id
                               WHERE t.txn_type IN ('bill','check','vendor_credit') AND t.is_voided = 0 AND COALESCE(l.class_id, t.class_id) IN $in AND t.txn_date BETWEEN :f AND :t
                                 AND (l.source_line_id IS NULL OR t.txn_type <> 'bill' OR NOT EXISTS (SELECT 1 FROM transaction_lines s JOIN transactions st ON st.id = s.txn_id WHERE s.id = l.source_line_id AND st.txn_type = 'item_receipt'))
                               GROUP BY v.id, v.name, v.vendor_kind, cid ORDER BY amt DESC", $pp + ['f' => $f['from'], 't' => $f['to']]) as $r) {
                $rows[] = ['vendor' => $r['name'], 'kind' => CsUi::label($r['vendor_kind']), 'project' => $cls[(int)$r['cid']]['code'], 'docs' => (int)$r['docs'], 'amount' => r2($r['amt']),
                    '_links' => ['vendor' => Report::link('vend.center', ['id' => $r['id']])]];
            }
        }
        $cols = [['key' => 'vendor', 'label' => 'Supplier / subcontractor'], ['key' => 'kind', 'label' => 'Kind'], ['key' => 'project', 'label' => 'Project'], ['key' => 'docs', 'label' => 'Documents', 'type' => 'int'], csr_m('amount', 'Spend (net of VAT)')];
        return ['columns' => $cols, 'rows' => $rows, 'totals' => csr_totals($rows, $cols, 'TOTAL', 'vendor')];
    },
]);

// =====================================================================================================================
// Contracts and progress
// =====================================================================================================================

Report::define('cs_change_orders', [
    'filters' => ['range'], 'default_dates' => 'all', 'extra_filters' => csr_filters(false) + ['costatus' => ['Status', fn() => ['proposed' => 'Proposed', 'for_approval' => 'For approval', 'internally_approved' => 'Internally approved', 'client_approved' => 'Client-approved', 'disputed' => 'Disputed', 'rejected' => 'Rejected', 'withdrawn' => 'Withdrawn'], 'Every status']],
    'run' => function (array $f): array {
        $ps = csr_projects($f);
        $rows = [];
        if ($ps) {
            [$in, $pp] = DB::in('rcx', array_keys($ps));
            $w = '';
            if (!empty($f['costatus'])) {
                $w = ' AND o.status = :cst';
                $pp['cst'] = $f['costatus'];
            }
            foreach (DB::all("SELECT o.* FROM cs_change_orders o WHERE o.project_id IN $in AND DATE(o.created_at) BETWEEN :f AND :t $w ORDER BY o.id", $pp + ['f' => $f['from'], 't' => $f['to']]) as $o) {
                $rows[] = ['co' => $o['co_no'], 'project' => $ps[(int)$o['project_id']]['code'], 'title' => $o['title'], 'type' => CsContracts::CO_TYPES[$o['co_type']] ?? '', 'by' => CsUi::label($o['requested_by_party']),
                    'price' => (float)$o['price_impact'], 'cost' => (float)$o['cost_impact'], 'margin' => r2((float)$o['price_impact'] - (float)$o['cost_impact']), 'days' => (int)$o['schedule_impact_days'],
                    'status' => CsUi::label($o['status']), 'client_date' => $o['client_approved_date'], '_links' => ['co' => Report::link('proj.change_orders', ['a' => 'view', 'id' => $o['id']])]];
            }
        }
        $cols = [['key' => 'co', 'label' => 'Change order'], ['key' => 'project', 'label' => 'Project'], ['key' => 'title', 'label' => 'Title'], ['key' => 'type', 'label' => 'Type'], ['key' => 'by', 'label' => 'Requested by', 'optional' => true],
            csr_m('price', 'Price'), csr_m('cost', 'Cost'), csr_m('margin', 'Margin'), ['key' => 'days', 'label' => 'Days', 'type' => 'int'], ['key' => 'status', 'label' => 'Status'], ['key' => 'client_date', 'label' => 'Client approval', 'type' => 'date', 'optional' => true]];
        return ['columns' => $cols, 'rows' => $rows, 'totals' => csr_totals($rows, $cols, 'TOTAL', 'co')];
    },
]);

Report::define('cs_contract_summary', [
    'filters' => [], 'extra_filters' => ['client' => ['Client', fn() => DB::pairs('SELECT DISTINCT c.id, c.name FROM customers c JOIN cs_contracts k ON k.customer_id = c.id ORDER BY c.name'), 'All clients'],
        'kstatus' => ['Status', fn() => CsContracts::STATUSES, 'Every status']],
    'run' => function (array $f): array {
        $w = '1 = 1';
        $pp = [];
        if (!empty($f['client'])) {
            $w .= ' AND k.customer_id = :c';
            $pp['c'] = (int)$f['client'];
        }
        if (!empty($f['kstatus'])) {
            $w .= ' AND k.status = :s';
            $pp['s'] = $f['kstatus'];
        }
        $acc = CsProject::accessibleIds();
        $rows = [];
        foreach (DB::all("SELECT k.*, c.name AS customer FROM cs_contracts k JOIN customers c ON c.id = k.customer_id WHERE $w ORDER BY k.contract_no", $pp) as $k) {
            $pids = array_map('intval', DB::column('SELECT id FROM cs_projects WHERE contract_id = :k', ['k' => $k['id']]));
            if ($acc !== null && !array_intersect($pids, $acc) && !Access::isAdmin()) {
                continue;
            }
            $billed = 0.0;
            $coll = 0.0;
            $ret = 0.0;
            foreach ($pids as $pid) {
                $s = CsSummary::project($pid);
                $billed += $s['certified_gross'];
                $coll += $s['collected'];
                $ret += $s['retention_receivable'];
            }
            $v = CsContracts::value($k);
            $rows[] = ['contract' => $k['contract_no'] . ' ' . $k['title'], 'client' => $k['customer'], 'type' => CsContracts::TYPES[$k['contract_type']] ?? '', 'original' => $v['original'], 'variations' => $v['variations'], 'revised' => $v['revised'],
                'projects' => count($pids), 'billed' => r2($billed), 'collected' => r2($coll), 'retention' => r2($ret), 'finish' => $k['revised_completion_date'] ?: $k['original_completion_date'], 'status' => CsContracts::STATUSES[$k['status']] ?? $k['status'],
                '_links' => ['contract' => Report::link('proj.contracts', ['a' => 'view', 'id' => $k['id']])]];
        }
        $cols = [['key' => 'contract', 'label' => 'Contract'], ['key' => 'client', 'label' => 'Client'], ['key' => 'type', 'label' => 'Type', 'optional' => true], csr_m('original', 'Original'), csr_m('variations', 'Variations'), csr_m('revised', 'Revised'),
            ['key' => 'projects', 'label' => 'Projects', 'type' => 'int', 'optional' => true], csr_m('billed', 'Billed'), csr_m('collected', 'Collected'), csr_m('retention', 'Retention held'), ['key' => 'finish', 'label' => 'Completion', 'type' => 'date'], ['key' => 'status', 'label' => 'Status']];
        return ['columns' => $cols, 'rows' => $rows, 'totals' => csr_totals($rows, $cols, 'TOTAL', 'contract')];
    },
]);

Report::define('cs_evm', [
    'filters' => ['asof'], 'extra_filters' => csr_filters(),
    'run' => function (array $f): array {
        $rows = [];
        foreach (csr_projects($f) as $pid => $p) {
            $e = CsPlanning::evm($pid, $f['asof']);
            $rows[] = ['project' => csr_label($p), 'bac' => $e['bac'], 'planned' => $e['planned_pct'] ?? '', 'physical' => $e['physical_pct'], 'pv' => r2($e['pv']), 'ev' => r2($e['ev']), 'ac' => r2($e['ac']),
                'cv' => r2($e['cv']), 'sv' => r2($e['sv']), 'cpi' => $e['cpi'] !== null ? round($e['cpi'], 2) : '', 'spi' => $e['spi'] !== null ? round($e['spi'], 2) : '', 'eac' => r2($e['eac']), 'vac' => r2($e['vac']),
                '_links' => ['project' => Report::link('plan.schedule', ['project' => $pid, 'tab' => 'evm'])], '_class' => ($e['cpi'] !== null && $e['cpi'] < 0.95) || ($e['spi'] !== null && $e['spi'] < 0.9) ? 'text-danger' : ''];
        }
        $cols = [['key' => 'project', 'label' => 'Project'], csr_m('bac', 'Budget (BAC)'), ['key' => 'planned', 'label' => 'Planned', 'type' => 'pct', 'optional' => true], ['key' => 'physical', 'label' => 'Done', 'type' => 'pct', 'optional' => true], csr_m('pv', 'Planned value'),
            csr_m('ev', 'Earned value'), csr_m('ac', 'Actual cost'), csr_m('cv', 'Cost variance'), csr_m('sv', 'Schedule variance'), ['key' => 'cpi', 'label' => 'CPI', 'type' => 'qty'], ['key' => 'spi', 'label' => 'SPI', 'type' => 'qty'],
            csr_m('eac', 'EAC (BAC/CPI)'), csr_m('vac', 'Variance at completion')];
        $t = csr_totals($rows, $cols);
        $t['cpi'] = $t['ac'] > 0 ? round($t['ev'] / $t['ac'], 2) : '';
        $t['spi'] = $t['pv'] > 0 ? round($t['ev'] / $t['pv'], 2) : '';
        return ['columns' => $cols, 'rows' => $rows, 'totals' => $t, 'notes' => 'CPI below 1.00 means more is spent than the work earned; SPI below 1.00 means the work is behind the baseline.'];
    },
]);

Report::define('cs_deliverables', [
    'filters' => [], 'extra_filters' => csr_filters(false) + ['mstatus' => ['Status', fn() => ['open' => 'Not yet accepted', 'pending' => 'Pending', 'in_progress' => 'In progress', 'submitted' => 'Submitted', 'under_review' => 'Under review', 'accepted' => 'Accepted', 'rejected' => 'Rejected', 'completed' => 'Completed'], 'Every status']],
    'run' => function (array $f): array {
        $ps = csr_projects($f);
        $rows = [];
        if ($ps) {
            [$in, $pp] = DB::in('rdl', array_keys($ps));
            $w = '';
            if (($f['mstatus'] ?? '') === 'open') {
                $w = " AND m.status NOT IN ('accepted','completed')";
            } elseif (!empty($f['mstatus'])) {
                $w = ' AND m.status = :ms';
                $pp['ms'] = $f['mstatus'];
            }
            foreach (DB::all("SELECT m.*, (SELECT COUNT(*) FROM cs_milestone_items i WHERE i.milestone_id = m.id AND i.is_required = 1) AS req,
                                     (SELECT COUNT(*) FROM cs_milestone_items i WHERE i.milestone_id = m.id AND i.is_required = 1 AND i.status IN ('accepted','completed')) AS done,
                                     (SELECT b.billing_no FROM cs_billing_lines bl JOIN cs_billings b ON b.id = bl.billing_id WHERE bl.milestone_id = m.id AND b.status NOT IN ('draft','rejected','cancelled','returned') LIMIT 1) AS billed_on
                                FROM cs_milestones m WHERE m.project_id IN $in $w ORDER BY m.project_id, m.sort_order", $pp) as $m) {
                $late = $m['scheduled_date'] && $m['scheduled_date'] < today() && !in_array($m['status'], ['accepted', 'completed'], true);
                $rows[] = ['milestone' => $m['code'] . ' ' . $m['name'], 'project' => $ps[(int)$m['project_id']]['code'], 'scheduled' => $m['scheduled_date'], 'value' => (float)$m['contract_value'],
                    'completion' => (float)$m['completion_pct'], 'deliverables' => (int)$m['done'] . ' of ' . (int)$m['req'], 'status' => CsUi::label($m['status']) . ($late ? ' (late)' : ''),
                    'certified' => $m['client_certified_date'], 'eligible' => (int)$m['billing_eligible'] ? 'Yes' : 'No', 'billed' => (string)$m['billed_on'],
                    '_links' => ['milestone' => Report::link('plan.milestones', ['a' => 'view', 'id' => $m['id']])], '_class' => $late ? 'text-danger' : ''];
            }
        }
        $cols = [['key' => 'milestone', 'label' => 'Milestone'], ['key' => 'project', 'label' => 'Project'], ['key' => 'scheduled', 'label' => 'Scheduled', 'type' => 'date'], csr_m('value', 'Billable value'),
            ['key' => 'completion', 'label' => 'Completion', 'type' => 'pct'], ['key' => 'deliverables', 'label' => 'Required deliverables accepted'], ['key' => 'status', 'label' => 'Status'],
            ['key' => 'certified', 'label' => 'Client certified', 'type' => 'date'], ['key' => 'eligible', 'label' => 'Billable'], ['key' => 'billed', 'label' => 'Billed on']];
        return ['columns' => $cols, 'rows' => $rows, 'totals' => csr_totals($rows, $cols, 'TOTAL', 'milestone')];
    },
]);

Report::define('cs_qhse', [
    'filters' => ['range'], 'default_dates' => 'this_year', 'extra_filters' => csr_filters(),
    'run' => function (array $f): array {
        $rows = [];
        foreach (csr_projects($f) as $pid => $p) {
            $prm = ['p' => $pid, 'f' => $f['from'], 't' => $f['to']];
            $i = DB::row("SELECT COUNT(*) AS n, SUM(result = 'passed') AS ok, SUM(result = 'failed') AS bad FROM cs_inspections WHERE project_id = :p AND inspection_date BETWEEN :f AND :t", $prm);
            $n = DB::row("SELECT COUNT(*) AS n, SUM(status <> 'closed') AS open, SUM(rework_cost) AS cost FROM cs_ncrs WHERE project_id = :p AND raised_date BETWEEN :f AND :t", $prm);
            $s = DB::row("SELECT SUM(kind = 'incident') AS inc, SUM(kind = 'near_miss') AS nm, SUM(kind = 'toolbox') AS tb, SUM(kind = 'training') AS tr, SUM(lost_time_hours) AS lt FROM cs_safety_records WHERE project_id = :p AND record_date BETWEEN :f AND :t", $prm);
            $h = (float)DB::value("SELECT COALESCE(SUM(l.regular_hours + l.ot_hours),0) FROM cs_timesheet_lines l JOIN cs_timesheets t ON t.id = l.timesheet_id WHERE t.project_id = :p AND t.status <> 'rejected' AND t.work_date BETWEEN :f AND :t", $prm);
            $punch = (int)DB::value("SELECT COUNT(*) FROM cs_punch_items WHERE project_id = :p AND status NOT IN ('verified','closed')", ['p' => $pid]);
            $rows[] = ['project' => csr_label($p), 'insp' => (int)$i['n'], 'pass' => (int)$i['n'] ? round((int)$i['ok'] / (int)$i['n'] * 100, 1) : '', 'failed' => (int)$i['bad'], 'ncr' => (int)$n['n'], 'ncr_open' => (int)$n['open'],
                'rework' => r2($n['cost']), 'incidents' => (int)$s['inc'], 'near' => (int)$s['nm'], 'toolbox' => (int)$s['tb'], 'training' => (int)$s['tr'], 'lost' => (float)$s['lt'], 'hours' => $h,
                'rate' => $h > 0 ? round((int)$s['inc'] / $h * 200000, 2) : '', 'punch' => $punch, '_links' => ['project' => csr_link($pid)], '_class' => (int)$s['inc'] ? 'text-danger' : ''];
        }
        $cols = [['key' => 'project', 'label' => 'Project'], ['key' => 'insp', 'label' => 'Inspections', 'type' => 'int'], ['key' => 'pass', 'label' => 'Pass rate', 'type' => 'pct'], ['key' => 'failed', 'label' => 'Failed', 'type' => 'int'],
            ['key' => 'ncr', 'label' => 'NCRs', 'type' => 'int'], ['key' => 'ncr_open', 'label' => 'NCRs open', 'type' => 'int'], csr_m('rework', 'Rework cost'), ['key' => 'incidents', 'label' => 'Incidents', 'type' => 'int'],
            ['key' => 'near', 'label' => 'Near misses', 'type' => 'int', 'optional' => true], ['key' => 'toolbox', 'label' => 'Toolbox meetings', 'type' => 'int', 'optional' => true], ['key' => 'training', 'label' => 'Trainings', 'type' => 'int', 'optional' => true],
            ['key' => 'lost', 'label' => 'Lost-time hours', 'type' => 'qty'], ['key' => 'hours', 'label' => 'Man-hours', 'type' => 'qty', 'optional' => true], ['key' => 'rate', 'label' => 'Incident rate (per 200,000 h)', 'type' => 'qty'],
            ['key' => 'punch', 'label' => 'Punch items open', 'type' => 'int']];
        $t = csr_totals($rows, $cols);
        $t['pass'] = '';
        $t['rate'] = $t['hours'] > 0 ? round($t['incidents'] / $t['hours'] * 200000, 2) : '';
        return ['columns' => $cols, 'rows' => $rows, 'totals' => $t];
    },
]);
