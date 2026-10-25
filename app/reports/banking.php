<?php
declare(strict_types=1);

/** Banking and fixed asset reports. */

function recon_pick(array $f): ?array
{
    $id = $f['rec_id'] ?: (int)DB::value("SELECT id FROM bank_reconciliations WHERE status = 'completed'" . ($f['account_id'] ? ' AND account_id = :a' : '') . ' ORDER BY statement_date DESC, id DESC LIMIT 1',
        $f['account_id'] ? ['a' => $f['account_id']] : []);
    return $id ? DB::row('SELECT r.*, a.code, a.name AS account_name, u.username FROM bank_reconciliations r JOIN accounts a ON a.id = r.account_id LEFT JOIN users u ON u.id = r.completed_by WHERE r.id = :id', ['id' => $id]) : null;
}

Report::define('recon_summary', [
    'filters' => ['rec'],
    'sortable' => false,
    'run' => function (array $f): array {
        $r = recon_pick($f);
        if (!$r) {
            return ['columns' => [['key' => 'name', 'label' => '']], 'rows' => [], 'notes' => 'No reconciliation yet.'];
        }
        $items = DB::all('SELECT * FROM reconciliation_items WHERE reconciliation_id = :r', ['r' => $r['id']]);
        $cp = array_filter($items, fn($i) => $i['cleared'] && $i['amount'] < 0);
        $cd = array_filter($items, fn($i) => $i['cleared'] && $i['amount'] > 0);
        $up = array_filter($items, fn($i) => !$i['cleared'] && $i['amount'] < 0);
        $ud = array_filter($items, fn($i) => !$i['cleared'] && $i['amount'] > 0);
        $sum = fn($a) => r2(array_sum(array_column($a, 'amount')));
        $register = r2(DB::value('SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE account_id = :a AND txn_date <= :d', ['a' => $r['account_id'], 'd' => $r['statement_date']]));
        $rows = [
            ['name' => 'Beginning balance', 'count' => '', 'amount' => (float)$r['beginning_balance'], '_class' => 'fw-semibold'],
            ['name' => 'Cleared transactions', '_class' => 'group-row'],
            ['name' => 'Checks and payments', 'count' => count($cp), 'amount' => $sum($cp), '_class' => 'indent-1'],
            ['name' => 'Deposits and credits', 'count' => count($cd), 'amount' => $sum($cd), '_class' => 'indent-1'],
            ['name' => 'Total cleared transactions', 'count' => count($cp) + count($cd), 'amount' => r2($sum($cp) + $sum($cd)), '_class' => 'subtotal-row'],
            ['name' => 'Cleared balance', 'amount' => (float)$r['cleared_balance'], '_class' => 'total-row'],
            ['name' => 'Uncleared transactions (as of reconciliation)', '_class' => 'group-row'],
            ['name' => 'Outstanding checks and payments', 'count' => count($up), 'amount' => $sum($up), '_class' => 'indent-1'],
            ['name' => 'Deposits in transit', 'count' => count($ud), 'amount' => $sum($ud), '_class' => 'indent-1'],
            ['name' => 'Register balance as of ' . fdate($r['statement_date']), 'amount' => $register, '_class' => 'total-row'],
            ['name' => 'Statement ending balance', 'amount' => (float)$r['ending_balance'], '_class' => 'total-row'],
        ];
        return [
            'grouped' => true,
            'columns' => [['key' => 'name', 'label' => $r['code'] . ' - ' . $r['account_name'] . ', statement ' . fdate($r['statement_date'])], ['key' => 'count', 'label' => 'Items', 'type' => 'int'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money']],
            'rows' => $rows,
            'notes' => 'Status: ' . h($r['status']) . ($r['completed_at'] ? ', completed ' . h(fdatetime($r['completed_at'])) . ' by ' . h($r['username']) : '')
                . ' | ' . (Access::page('rpt.recon_detail') ? '<a href="' . h(url('rpt.recon_detail', ['rec_id' => $r['id']])) . '">Detail</a>' : ''),
        ];
    },
]);

Report::define('recon_detail', [
    'filters' => ['rec'],
    'sortable' => false,
    'run' => function (array $f): array {
        $r = recon_pick($f);
        if (!$r) {
            return ['columns' => [['key' => 'type', 'label' => '']], 'rows' => [], 'notes' => 'No reconciliation yet.'];
        }
        $items = DB::all('SELECT ri.*, t.id AS txn_id, t.txn_type, t.txn_no, t.txn_date, COALESCE(c.name, v.name, o.name, t.payee_name) AS party
                            FROM reconciliation_items ri LEFT JOIN transactions t ON t.id = ri.txn_id LEFT JOIN customers c ON c.id = t.customer_id LEFT JOIN vendors v ON v.id = t.vendor_id
                            LEFT JOIN other_names o ON o.id = t.other_name_id
                           WHERE ri.reconciliation_id = :r ORDER BY ri.cleared DESC, t.txn_date', ['r' => $r['id']]);
        $rows = [];
        foreach ([[1, -1, 'Cleared checks and payments'], [1, 1, 'Cleared deposits and credits'], [0, -1, 'Uncleared checks and payments'], [0, 1, 'Uncleared deposits and credits']] as [$cl, $sg, $label]) {
            $set = array_filter($items, fn($i) => (int)$i['cleared'] === $cl && ($sg < 0 ? $i['amount'] < 0 : $i['amount'] > 0));
            $rows[] = ['type' => $label, '_class' => 'group-row'];
            $sub = 0.0;
            foreach ($set as $i) {
                $rows[] = ['type' => $i['txn_type'] ? Txn::label($i['txn_type']) : '(deleted)', 'date' => $i['txn_date'], 'num' => $i['txn_no'], 'name' => $i['party'], 'amount' => (float)$i['amount'], '_links' => ['num' => $i['txn_type'] ? Report::txnLink($i) : null]];
                $sub += $i['amount'];
            }
            $rows[] = ['type' => 'Total', 'amount' => r2($sub), '_class' => 'subtotal-row'];
        }
        return ['grouped' => true,
            'columns' => [['key' => 'type', 'label' => 'Type'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'num', 'label' => 'Num'], ['key' => 'name', 'label' => 'Name'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money']],
            'rows' => $rows, 'notes' => h($r['code'] . ' - ' . $r['account_name']) . ', statement date ' . h(fdate($r['statement_date'])) . ', ending balance ' . money($r['ending_balance'])];
    },
]);

Report::define('recon_discrepancy', [
    'filters' => ['bank'],
    'run' => function (array $f): array {
        $sql = 'SELECT d.*, a.code, u.username, r.statement_date FROM reconciliation_discrepancies d JOIN accounts a ON a.id = d.account_id
                  LEFT JOIN users u ON u.id = d.changed_by LEFT JOIN bank_reconciliations r ON r.id = d.reconciliation_id';
        $p = [];
        if ($f['account_id']) {
            $sql .= ' WHERE d.account_id = :a';
            $p['a'] = $f['account_id'];
        }
        $rows = [];
        foreach (DB::all($sql . ' ORDER BY d.changed_at DESC', $p) as $d) {
            $t = Txn::load((int)$d['txn_id']);
            $rows[] = ['when' => $d['changed_at'], 'account' => $d['code'], 'statement' => $d['statement_date'], 'txn' => $d['txn_ref'], 'change' => $d['change_type'],
                'cleared' => (float)$d['cleared_amount'], 'new' => (float)$d['new_amount'], 'diff' => r2($d['new_amount'] - $d['cleared_amount']), 'user' => $d['username'],
                '_links' => ['txn' => $t ? Report::txnLink(['txn_type' => $t['txn_type'], 'txn_id' => $t['id']]) : null]];
        }
        return ['columns' => [['key' => 'when', 'label' => 'Changed', 'type' => 'datetime'], ['key' => 'account', 'label' => 'Account'], ['key' => 'statement', 'label' => 'Reconciled statement', 'type' => 'date'],
            ['key' => 'txn', 'label' => 'Transaction'], ['key' => 'change', 'label' => 'Change'], ['key' => 'cleared', 'label' => 'Cleared amount', 'type' => 'money'],
            ['key' => 'new', 'label' => 'New amount', 'type' => 'money'], ['key' => 'diff', 'label' => 'Effect', 'type' => 'money'], ['key' => 'user', 'label' => 'Changed by']],
            'rows' => $rows, 'notes' => 'Cleared transactions that were changed, voided or deleted after they were reconciled.'];
    },
]);

Report::define('check_register', [
    'filters' => ['range', 'bank', 'vendor', 'class'],
    'default_dates' => 'this_month',
    'run' => function (array $f): array {
        $sql = "SELECT t.*, t.id AS txn_id, a.code, COALESCE(v.name, c.name, t.payee_name) AS payee FROM transactions t JOIN accounts a ON a.id = t.account_id
                  LEFT JOIN vendors v ON v.id = t.vendor_id LEFT JOIN customers c ON c.id = t.customer_id
                 WHERE t.txn_type IN ('check','bill_payment','refund','vendor_advance') AND a.account_type IN ('Bank','Credit Card') AND t.txn_date BETWEEN :f AND :t";
        $p = ['f' => $f['from'], 't' => $f['to']];
        if ($f['account_id']) {
            $sql .= ' AND t.account_id = :a';
            $p['a'] = $f['account_id'];
        }
        if ($f['vendor_id']) {
            $sql .= ' AND t.vendor_id = :v';
            $p['v'] = $f['vendor_id'];
        }
        if (!empty($f['class_id']) && Classes::ready()) {
            [$csql, $cp] = Classes::sqlFilter((int)$f['class_id'], 't', 'crc');
            $sql .= $csql;
            $p += $cp;
        }
        $data = DB::all($sql . ' ORDER BY t.account_id, t.txn_no', $p);
        $seen = [];
        $prev = null;
        $rows = [];
        foreach ($data as $d) {
            $flag = [];
            $no = $d['txn_no'];
            if (isset($seen[$d['account_id']][$no])) {
                $flag[] = 'duplicate number';
            }
            if ($prev !== null && ctype_digit((string)$no) && ctype_digit((string)$prev) && (int)$no - (int)$prev > 1) {
                $flag[] = 'missing ' . ((int)$prev + 1) . ((int)$no - (int)$prev > 2 ? '–' . ((int)$no - 1) : '');
            }
            $seen[$d['account_id']][$no] = true;
            $prev = $no;
            $rows[] = ['num' => $no, 'date' => $d['txn_date'], 'account' => $d['code'], 'type' => Txn::label($d['txn_type']), 'payee' => $d['payee'], 'memo' => $d['memo'],
                'amount' => (float)$d['total'], 'status' => (int)$d['is_voided'] ? 'VOID' : ((int)$d['to_be_printed'] ? 'to print' : ((int)$d['is_printed'] ? 'printed' : '')),
                'flag' => implode(', ', $flag), '_links' => ['num' => Report::txnLink($d)], '_class' => $flag ? 'table-warning' : ''];
        }
        return ['columns' => [['key' => 'num', 'label' => 'Check no.'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'account', 'label' => 'Account'], ['key' => 'type', 'label' => 'Type'],
            ['key' => 'payee', 'label' => 'Payee'], ['key' => 'memo', 'label' => 'Memo'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'], ['key' => 'status', 'label' => 'Status'], ['key' => 'flag', 'label' => 'Exceptions']],
            'rows' => $rows, 'sortable' => false, 'notes' => 'Missing and duplicate check numbers are highlighted.'];
    },
]);

// ---------------------------------------------------------------------- fixed assets

/** Location drop-down of the fixed asset reports, and the SQL that goes with the chosen value */
function fa_location_filter(): array
{
    return ['fa_location' => ['Location', fn() => DB::pairs('SELECT id, name FROM asset_locations ORDER BY name') + ['none' => '(No location)']]];
}

function fa_location_sql(array $f, array &$params, string $alias = 'fa'): string
{
    $v = (string)($f['fa_location'] ?? '');
    if ($v === 'none') {
        return " AND $alias.location_id IS NULL";
    }
    if ((int)$v > 0) {
        $params['faloc'] = (int)$v;
        return " AND $alias.location_id = :faloc";
    }
    return '';
}

Report::define('fa_register', [
    'filters' => ['asof', 'fa_category'],
    'extra_filters' => fa_location_filter(),
    'run' => function (array $f): array {
        $sql = 'SELECT fa.*, ac.name AS category FROM fixed_assets fa JOIN asset_categories ac ON ac.id = fa.category_id WHERE fa.acquisition_date <= :d AND (fa.disposal_date IS NULL OR fa.disposal_date > :d)';
        $p = ['d' => $f['asof']];
        if ($f['category_id']) {
            $sql .= ' AND fa.category_id = :c';
            $p['c'] = $f['category_id'];
        }
        $sql .= fa_location_sql($f, $p);
        $rows = [];
        $tc = 0.0;
        $ta = 0.0;
        foreach (DB::all($sql . ' ORDER BY ac.name, fa.asset_no', $p) as $a) {
            $acc = Depreciation::accumulated($a, Depreciation::idx($f['asof']));
            $rows[] = ['no' => $a['asset_no'], 'desc' => $a['description'], 'category' => $a['category'], 'location' => $a['location'], 'acquired' => $a['acquisition_date'],
                'method' => method_label($a['method']), 'life' => (float)$a['useful_life_years'], 'cost' => (float)$a['cost'], 'salvage' => (float)$a['salvage_value'],
                'accum' => $acc, 'nbv' => r2((float)$a['cost'] - $acc), '_links' => ['no' => Report::link('fa.register', ['a' => 'view', 'id' => $a['id']])]];
            $tc += (float)$a['cost'];
            $ta += $acc;
        }
        return ['columns' => [['key' => 'no', 'label' => 'Asset no.'], ['key' => 'desc', 'label' => 'Description'], ['key' => 'category', 'label' => 'Category'], ['key' => 'location', 'label' => 'Location'],
            ['key' => 'acquired', 'label' => 'Acquired', 'type' => 'date'], ['key' => 'method', 'label' => 'Method'], ['key' => 'life', 'label' => 'Life (yrs)', 'type' => 'qty'],
            ['key' => 'cost', 'label' => 'Cost', 'type' => 'money'], ['key' => 'salvage', 'label' => 'Salvage', 'type' => 'money'], ['key' => 'accum', 'label' => 'Accum. dep.', 'type' => 'money'], ['key' => 'nbv', 'label' => 'Net book value', 'type' => 'money']],
            'rows' => $rows, 'totals' => ['no' => 'TOTAL', 'cost' => r2($tc), 'accum' => r2($ta), 'nbv' => r2($tc - $ta)]];
    },
]);

Report::define('fa_schedule', [
    'filters' => ['asset', 'schedule_inputs'],
    'sortable' => false,
    'run' => function (array $f): array {
        if ($f['asset_id']) {
            $a = Depreciation::asset((int)$f['asset_id']);
            $cost = (float)$a['cost'];
            $salvage = (float)$a['salvage_value'];
            $life = max(1, (int)round((float)$a['useful_life_years']));
            $title = $a['asset_no'] . ' - ' . $a['description'];
            $units = (float)$a['total_units'];
        } else {
            $cost = to_num($f['cost'] ?: '500000');
            $salvage = to_num($f['salvage'] ?: '50000');
            $life = max(1, (int)($f['life'] ?: 5));
            $title = 'Example asset';
            $units = 0.0;
        }
        $sl = Depreciation::annualSchedule($cost, $salvage, $life, 'straight_line');
        $db = Depreciation::annualSchedule($cost, $salvage, $life, 'declining_balance', 2.0);
        $syd = Depreciation::annualSchedule($cost, $salvage, $life, 'syd');
        $rows = [];
        $acc = ['sl' => 0.0, 'db' => 0.0, 'syd' => 0.0];
        for ($y = 0; $y < $life; $y++) {
            $acc['sl'] += $sl[$y];
            $acc['db'] += $db[$y];
            $acc['syd'] += $syd[$y];
            $rows[] = ['year' => $y + 1, 'sl' => $sl[$y], 'db' => $db[$y], 'syd' => $syd[$y], 'nbv_sl' => r2($cost - $acc['sl']), 'nbv_db' => r2($cost - $acc['db']), 'nbv_syd' => r2($cost - $acc['syd'])];
        }
        $notes = h($title) . ': cost ' . money($cost) . ', salvage ' . money($salvage) . ', life ' . $life . ' years, depreciable cost ' . money($cost - $salvage)
            . '. Double-declining rate ' . pct(200 / $life) . ' (switches to straight-line when that is higher; never below salvage).'
            . ($units > 0 ? ' Units of production: ' . money(($cost - $salvage) / $units) . ' per unit of ' . qty($units) . '.' : '')
            . ' All methods depreciate the same total; only the timing differs.';
        return ['columns' => [['key' => 'year', 'label' => 'Year'], ['key' => 'sl', 'label' => 'Straight-line', 'type' => 'money'], ['key' => 'db', 'label' => 'Double-declining balance', 'type' => 'money'],
            ['key' => 'syd', 'label' => "Sum-of-the-years'-digits", 'type' => 'money'], ['key' => 'nbv_sl', 'label' => 'NBV (SL)', 'type' => 'money'], ['key' => 'nbv_db', 'label' => 'NBV (DDB)', 'type' => 'money'],
            ['key' => 'nbv_syd', 'label' => 'NBV (SYD)', 'type' => 'money']],
            'rows' => $rows, 'totals' => ['year' => 'Total', 'sl' => r2(array_sum($sl)), 'db' => r2(array_sum($db)), 'syd' => r2(array_sum($syd))], 'notes' => $notes];
    },
]);

Report::define('fa_by_category', [
    'filters' => ['range', 'fa_category'],
    'extra_filters' => fa_location_filter(),
    'default_dates' => 'this_year',
    'sortable' => false,
    'run' => function (array $f): array {
        $sql = 'SELECT ac.name AS category, fa.id, fa.asset_no, fa.description, fa.method, SUM(ad.amount) AS amt, SUM(ad.units_used) AS units
                  FROM asset_depreciation ad JOIN transactions t ON t.id = ad.txn_id AND t.is_voided = 0
                  JOIN fixed_assets fa ON fa.id = ad.asset_id JOIN asset_categories ac ON ac.id = fa.category_id
                 WHERE t.txn_date BETWEEN :f AND :t';
        $p = ['f' => $f['from'], 't' => $f['to']];
        if ($f['category_id']) {
            $sql .= ' AND fa.category_id = :c';
            $p['c'] = $f['category_id'];
        }
        $sql .= fa_location_sql($f, $p);
        $rows = [];
        $cur = null;
        $sub = 0.0;
        $grand = 0.0;
        foreach (DB::all($sql . ' GROUP BY ac.name, fa.id, fa.asset_no, fa.description, fa.method ORDER BY ac.name, fa.asset_no', $p) as $d) {
            if ($d['category'] !== $cur) {
                if ($cur !== null) {
                    $rows[] = ['asset' => 'Total ' . $cur, 'amount' => r2($sub), '_class' => 'subtotal-row'];
                }
                $rows[] = ['asset' => $d['category'], '_class' => 'group-row'];
                $cur = $d['category'];
                $sub = 0.0;
            }
            $rows[] = ['asset' => $d['asset_no'] . ' - ' . $d['description'], 'method' => method_label($d['method']), 'units' => $d['units'] !== null ? (float)$d['units'] : '', 'amount' => r2($d['amt']), '_class' => 'indent-1',
                '_links' => ['asset' => Report::link('fa.register', ['a' => 'view', 'id' => $d['id']])]];
            $sub += $d['amt'];
            $grand += $d['amt'];
        }
        if ($cur !== null) {
            $rows[] = ['asset' => 'Total ' . $cur, 'amount' => r2($sub), '_class' => 'subtotal-row'];
        }
        return ['grouped' => true, 'columns' => [['key' => 'asset', 'label' => 'Category / asset'], ['key' => 'method', 'label' => 'Method'], ['key' => 'units', 'label' => 'Units used', 'type' => 'qty'], ['key' => 'amount', 'label' => 'Depreciation', 'type' => 'money']],
            'rows' => $rows, 'totals' => ['asset' => 'TOTAL', 'amount' => r2($grand)]];
    },
]);

Report::define('fa_additions', [
    'filters' => ['range', 'fa_category'],
    'extra_filters' => fa_location_filter(),
    'default_dates' => 'this_year',
    'sortable' => false,
    'run' => function (array $f): array {
        $lp = [];
        $locSql = fa_location_sql($f, $lp);
        if (!empty($f['category_id'])) {
            $locSql .= ' AND fa.category_id = :fac';
            $lp['fac'] = (int)$f['category_id'];
        }
        $rows = [['asset' => 'Additions', '_class' => 'group-row']];
        $add = 0.0;
        foreach (DB::all('SELECT fa.*, ac.name AS category FROM fixed_assets fa JOIN asset_categories ac ON ac.id = fa.category_id WHERE fa.acquisition_date BETWEEN :f AND :t' . $locSql . ' ORDER BY fa.acquisition_date', ['f' => $f['from'], 't' => $f['to']] + $lp) as $a) {
            $rows[] = ['asset' => $a['asset_no'] . ' - ' . $a['description'], 'category' => $a['category'], 'date' => $a['acquisition_date'], 'cost' => (float)$a['cost'], '_class' => 'indent-1',
                '_links' => ['asset' => Report::link('fa.register', ['a' => 'view', 'id' => $a['id']])]];
            $add += (float)$a['cost'];
        }
        $rows[] = ['asset' => 'Total additions', 'cost' => r2($add), '_class' => 'subtotal-row'];
        $rows[] = ['asset' => 'Disposals', '_class' => 'group-row'];
        $disp = 0.0;
        $gl = 0.0;
        foreach (DB::all("SELECT fa.*, ac.name AS category FROM fixed_assets fa JOIN asset_categories ac ON ac.id = fa.category_id WHERE fa.status = 'disposed' AND fa.disposal_date BETWEEN :f AND :t$locSql ORDER BY fa.disposal_date", ['f' => $f['from'], 't' => $f['to']] + $lp) as $a) {
            $acc = Depreciation::accumulated($a);
            $gain = r2((float)$a['disposal_proceeds'] - ((float)$a['cost'] - $acc));
            $rows[] = ['asset' => $a['asset_no'] . ' - ' . $a['description'], 'category' => $a['category'], 'date' => $a['disposal_date'], 'cost' => (float)$a['cost'], 'accum' => $acc,
                'proceeds' => (float)$a['disposal_proceeds'], 'gain' => $gain, '_class' => 'indent-1', '_links' => ['asset' => Report::link('fa.register', ['a' => 'view', 'id' => $a['id']])]];
            $disp += (float)$a['cost'];
            $gl += $gain;
        }
        $rows[] = ['asset' => 'Total disposals', 'cost' => r2($disp), 'gain' => r2($gl), '_class' => 'subtotal-row'];
        return ['grouped' => true, 'columns' => [['key' => 'asset', 'label' => 'Asset'], ['key' => 'category', 'label' => 'Category'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'],
            ['key' => 'cost', 'label' => 'Cost', 'type' => 'money'], ['key' => 'accum', 'label' => 'Accum. dep.', 'type' => 'money'], ['key' => 'proceeds', 'label' => 'Proceeds', 'type' => 'money'],
            ['key' => 'gain', 'label' => 'Gain / (loss)', 'type' => 'money']], 'rows' => $rows];
    },
]);
