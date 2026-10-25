<?php
declare(strict_types=1);

/** Point of Sale operational reports. */

function pos_tender_type(string $t): string
{
    return ['cash' => 'Cash', 'check' => 'Check', 'card' => 'Card', 'ewallet' => 'E-wallet', 'bank_transfer' => 'Bank transfer', 'voucher' => 'Gift voucher', 'other' => 'Other'][$t] ?? $t;
}

/** What the POS reports ask the sales lines for: the counter, site, cashier and the item filters */
function pos_line_opt(array $f): array
{
    return ['pos_only' => true, 'terminal_id' => $f['terminal_id'], 'site_id' => $f['site_id'], 'created_by' => $f['user_id'] ?? null,
        'item_id' => $f['item_id'] ?? null, 'category_id' => $f['category_id'] ?? null, 'item_type' => $f['item_type'] ?? '', 'pref_vendor_id' => $f['pref_vendor_id'] ?? null];
}

function pos_group(array $f, callable $keyFn, string $label, ?callable $refFn = null): array
{
    $lines = Report::salesLines($f['from'], $f['to'], pos_line_opt($f));
    $by = [];
    foreach ($lines as $l) {
        $k = $keyFn($l);
        $by[$k]['qty'] = ($by[$k]['qty'] ?? 0) + $l['s_qty'];
        $by[$k]['amount'] = ($by[$k]['amount'] ?? 0) + $l['s_amount'];
        $by[$k]['disc'] = ($by[$k]['disc'] ?? 0) + ($l['sgn'] > 0 ? (float)$l['discount_amount'] : 0);
        $by[$k]['cost'] = ($by[$k]['cost'] ?? 0) + $l['s_cost'];
        $by[$k]['txns'][$l['txn_id']] = true;
        if ($refFn) {
            $by[$k]['_ref'] = $refFn($l);
        }
    }
    ksort($by);
    $rows = [];
    $tot = ['name' => 'TOTAL', 'qty' => 0.0, 'amount' => 0.0, 'disc' => 0.0, 'cost' => 0.0];
    foreach ($by as $k => $b) {
        $rows[] = ['name' => $k, 'count' => count($b['txns']), 'qty' => $b['qty'], 'disc' => r2($b['disc']), 'amount' => r2($b['amount']), 'cost' => r2($b['cost']), 'margin' => r2($b['amount'] - $b['cost']),
            '_ref' => $b['_ref'] ?? []];
        foreach (['qty', 'amount', 'disc', 'cost'] as $x) {
            $tot[$x] += $b[$x];
        }
    }
    $tot['margin'] = r2($tot['amount'] - $tot['cost']);
    return [
        'columns' => [['key' => 'name', 'label' => $label], ['key' => 'count', 'label' => 'Receipts', 'type' => 'int'], ['key' => 'qty', 'label' => 'Qty', 'type' => 'qty'],
            ['key' => 'disc', 'label' => 'Discounts', 'type' => 'money'], ['key' => 'amount', 'label' => 'Net sales', 'type' => 'money'],
            ['key' => 'cost', 'label' => 'Cost', 'type' => 'money', 'sensitive' => 'cost'], ['key' => 'margin', 'label' => 'Margin', 'type' => 'money', 'sensitive' => 'cost']],
        'rows' => $rows, 'totals' => $tot, 'notes' => 'Counter sales less returns, net of VAT.',
    ];
}

Report::define('pos_sales_item', [
    'filters' => ['range', 'terminal', 'site', 'cashier', 'item', 'category', 'item_type', 'pref_vendor'],
    'default_dates' => 'today',
    'run' => fn(array $f) => pos_group($f, fn($l) => $l['item_code'] ? $l['item_code'] . ' - ' . $l['item_name'] : $l['item_label'], 'Item', fn($l) => ['item' => (int)$l['item_id']]),
]);

Report::define('pos_sales_category', [
    'filters' => ['range', 'terminal', 'site', 'cashier', 'category', 'item_type', 'pref_vendor'],
    'default_dates' => 'today',
    'run' => function (array $f): array {
        $cats = DB::pairs('SELECT id, name FROM item_categories');
        return pos_group($f, fn($l) => $cats[$l['category_id']] ?? '(uncategorized)', 'Category');
    },
]);

Report::define('pos_sales_cashier', [
    'filters' => ['range', 'terminal', 'site', 'cashier'],
    'default_dates' => 'today',
    'sortable' => false,
    'run' => function (array $f): array {
        // one group per cashier: the sales figures on the cashier's line, then how the money came in,
        // one line per payment method (the two earlier reports, by cashier and by payment method, in one)
        $users = DB::pairs('SELECT id, full_name FROM users');
        $lines = Report::salesLines($f['from'], $f['to'], pos_line_opt($f));
        $by = [];
        foreach ($lines as $l) {
            $k = (int)$l['created_by'];
            $by[$k]['qty'] = ($by[$k]['qty'] ?? 0) + $l['s_qty'];
            $by[$k]['amount'] = ($by[$k]['amount'] ?? 0) + $l['s_amount'];
            $by[$k]['disc'] = ($by[$k]['disc'] ?? 0) + ($l['sgn'] > 0 ? (float)$l['discount_amount'] : 0);
            $by[$k]['cost'] = ($by[$k]['cost'] ?? 0) + $l['s_cost'];
            $by[$k]['txns'][$l['txn_id']] = true;
        }
        $p = ['f' => $f['from'], 't' => $f['to']];
        $and = '';
        if ($f['terminal_id']) {
            $and .= ' AND t.terminal_id = :term';
            $p['term'] = $f['terminal_id'];
        }
        if ($f['site_id']) {
            $and .= ' AND t.site_id = :site';
            $p['site'] = $f['site_id'];
        }
        if ($f['user_id']) {
            $and .= ' AND t.created_by = :cashier';
            $p['cashier'] = $f['user_id'];
        }
        $voids = DB::pairs("SELECT t.created_by, COUNT(*) FROM transactions t WHERE t.txn_type = 'pos_sale' AND t.is_voided = 1 AND t.txn_date BETWEEN :f AND :t $and GROUP BY t.created_by", $p);
        $tenders = DB::all("SELECT t.created_by, pm.name, pm.method_type, SUM(CASE WHEN t.txn_type = 'pos_return' THEN -pt.amount ELSE pt.amount END) AS amt, COUNT(DISTINCT t.id) AS cnt
                              FROM pos_tenders pt JOIN transactions t ON t.id = pt.txn_id JOIN payment_methods pm ON pm.id = pt.payment_method_id
                             WHERE t.is_voided = 0 AND t.status <> 'suspended' AND t.txn_date BETWEEN :f AND :t $and
                             GROUP BY t.created_by, pm.name, pm.method_type ORDER BY pm.name", $p);
        $charges = DB::all("SELECT t.created_by, COUNT(*) AS c, COALESCE(SUM(t.total),0) AS s FROM transactions t
                             WHERE t.txn_type = 'invoice' AND t.terminal_id IS NOT NULL AND t.is_voided = 0 AND t.txn_date BETWEEN :f AND :t $and GROUP BY t.created_by", $p);
        $pay = [];
        foreach ($tenders as $r) {
            $pay[(int)$r['created_by']][] = ['name' => $r['name'], 'type' => pos_tender_type((string)$r['method_type']), 'count' => (int)$r['cnt'], 'tendered' => r2($r['amt'])];
            $by[(int)$r['created_by']] ??= ['qty' => 0, 'amount' => 0, 'disc' => 0, 'cost' => 0, 'txns' => []];
        }
        foreach ($charges as $r) {
            $pay[(int)$r['created_by']][] = ['name' => 'Charged to customer accounts', 'type' => 'On account', 'count' => (int)$r['c'], 'tendered' => r2($r['s'])];
            $by[(int)$r['created_by']] ??= ['qty' => 0, 'amount' => 0, 'disc' => 0, 'cost' => 0, 'txns' => []];
        }
        uksort($by, fn($a, $b) => strcmp($users[$a] ?? '?', $users[$b] ?? '?'));
        $rows = [];
        $tot = ['name' => 'TOTAL', 'count' => 0, 'qty' => 0.0, 'disc' => 0.0, 'amount' => 0.0, 'cost' => 0.0, 'margin' => 0.0, 'voids' => 0, 'tendered' => 0.0];
        $byMethod = [];
        foreach ($by as $uid => $b) {
            $tendered = array_sum(array_column($pay[$uid] ?? [], 'tendered'));
            $rows[] = ['name' => $users[$uid] ?? 'Cashier #' . $uid, 'count' => count($b['txns']), 'qty' => $b['qty'], 'disc' => r2($b['disc']), 'amount' => r2($b['amount']),
                'cost' => r2($b['cost']), 'margin' => r2($b['amount'] - $b['cost']), 'voids' => (int)($voids[$uid] ?? 0), 'tendered' => r2($tendered), '_class' => 'group-row'];
            foreach ($pay[$uid] ?? [] as $m) {
                $rows[] = ['name' => $m['name'], 'type' => $m['type'], 'count' => $m['count'], 'tendered' => $m['tendered'], '_class' => 'indent-1'];
                $byMethod[$m['name']]['count'] = ($byMethod[$m['name']]['count'] ?? 0) + $m['count'];
                $byMethod[$m['name']]['tendered'] = ($byMethod[$m['name']]['tendered'] ?? 0) + $m['tendered'];
                $byMethod[$m['name']]['type'] = $m['type'];
            }
            $tot['count'] += count($b['txns']);
            $tot['qty'] += $b['qty'];
            $tot['disc'] += $b['disc'];
            $tot['amount'] += $b['amount'];
            $tot['cost'] += $b['cost'];
            $tot['voids'] += (int)($voids[$uid] ?? 0);
            $tot['tendered'] += $tendered;
        }
        if (count($by) > 1 && $byMethod) {
            // every cashier together, by payment method
            $rows[] = ['name' => 'All cashiers, by payment method', '_class' => 'group-row'];
            ksort($byMethod);
            foreach ($byMethod as $name => $m) {
                $rows[] = ['name' => $name, 'type' => $m['type'], 'count' => $m['count'], 'tendered' => r2($m['tendered']), '_class' => 'indent-1'];
            }
        }
        $tot['margin'] = r2($tot['amount'] - $tot['cost']);
        foreach (['disc', 'amount', 'cost', 'tendered'] as $x) {
            $tot[$x] = r2($tot[$x]);
        }
        return [
            'grouped' => true,
            'columns' => [
                ['key' => 'name', 'label' => 'Cashier / payment method'], ['key' => 'type', 'label' => 'Type'],
                ['key' => 'count', 'label' => 'Receipts', 'type' => 'int'], ['key' => 'qty', 'label' => 'Qty', 'type' => 'qty'],
                ['key' => 'disc', 'label' => 'Discounts', 'type' => 'money'], ['key' => 'amount', 'label' => 'Net sales (ex VAT)', 'type' => 'money'],
                ['key' => 'cost', 'label' => 'Cost', 'type' => 'money', 'sensitive' => 'cost'], ['key' => 'margin', 'label' => 'Margin', 'type' => 'money', 'sensitive' => 'cost'],
                ['key' => 'voids', 'label' => 'Voids', 'type' => 'int'], ['key' => 'tendered', 'label' => 'Tendered (incl. VAT)', 'type' => 'money'],
            ],
            'rows' => $rows, 'totals' => $tot,
            'notes' => 'Each cashier\'s line is their counter sales less returns, net of VAT, with the number of receipts and voids. The lines under it show how the money came in, by payment method, VAT included; "Charged to customer accounts" is the sales put on account at the counter.',
        ];
    },
]);

Report::define('pos_sales_payment', [
    'filters' => ['range', 'terminal', 'cashier'],
    'default_dates' => 'today',
    'run' => function (array $f): array {
        $sql = "SELECT pm.name, pm.method_type, SUM(CASE WHEN t.txn_type = 'pos_return' THEN -pt.amount ELSE pt.amount END) AS amt, COUNT(DISTINCT t.id) AS cnt
                  FROM pos_tenders pt JOIN transactions t ON t.id = pt.txn_id JOIN payment_methods pm ON pm.id = pt.payment_method_id
                 WHERE t.is_voided = 0 AND t.status <> 'suspended' AND t.txn_date BETWEEN :f AND :t";
        $p = ['f' => $f['from'], 't' => $f['to']];
        if ($f['terminal_id']) {
            $sql .= ' AND t.terminal_id = :term';
            $p['term'] = $f['terminal_id'];
        }
        if ($f['user_id']) {
            $sql .= ' AND t.created_by = :cashier';
            $p['cashier'] = $f['user_id'];
        }
        $rows = [];
        $tot = 0.0;
        foreach (DB::all($sql . ' GROUP BY pm.name, pm.method_type ORDER BY pm.name', $p) as $r) {
            $rows[] = ['name' => $r['name'], 'type' => $r['method_type'], 'count' => (int)$r['cnt'], 'amount' => r2($r['amt'])];
            $tot += $r['amt'];
        }
        $charge = DB::row("SELECT COUNT(*) AS c, COALESCE(SUM(total),0) AS s FROM transactions WHERE txn_type = 'invoice' AND terminal_id IS NOT NULL AND is_voided = 0 AND txn_date BETWEEN :f AND :t"
            . ($f['terminal_id'] ? ' AND terminal_id = :term' : '')
            . ($f['user_id'] ? ' AND created_by = :cashier' : ''), $p);
        if ((int)$charge['c']) {
            $rows[] = ['name' => 'Charged to customer accounts', 'type' => 'account', 'count' => (int)$charge['c'], 'amount' => r2($charge['s'])];
            $tot += $charge['s'];
        }
        return ['columns' => [['key' => 'name', 'label' => 'Payment method'], ['key' => 'type', 'label' => 'Type'], ['key' => 'count', 'label' => 'Transactions', 'type' => 'int'], ['key' => 'amount', 'label' => 'Amount (incl. VAT)', 'type' => 'money']],
            'rows' => $rows, 'totals' => ['name' => 'TOTAL', 'amount' => r2($tot)]];
    },
]);

Report::define('pos_sales_hour', [
    'filters' => ['range', 'terminal', 'site', 'cashier'],
    'default_dates' => 'this_month',
    'sortable' => false,
    'run' => function (array $f): array {
        $sql = "SELECT HOUR(t.created_at) AS hr, COUNT(*) AS cnt, SUM(CASE WHEN t.txn_type = 'pos_return' THEN -t.subtotal ELSE t.subtotal END) AS net
                  FROM transactions t WHERE t.txn_type IN ('pos_sale','pos_return') AND t.is_voided = 0 AND t.status <> 'suspended' AND t.txn_date BETWEEN :f AND :t";
        $p = ['f' => $f['from'], 't' => $f['to']];
        if ($f['terminal_id']) {
            $sql .= ' AND t.terminal_id = :term';
            $p['term'] = $f['terminal_id'];
        }
        if ($f['user_id']) {
            $sql .= ' AND t.created_by = :cashier';
            $p['cashier'] = $f['user_id'];
        }
        if ($f['site_id']) {
            $sql .= ' AND t.site_id = :s';
            $p['s'] = $f['site_id'];
        }
        $data = DB::all($sql . ' GROUP BY HOUR(t.created_at) ORDER BY hr', $p);
        $bars = [];
        $rows = [];
        $tot = 0.0;
        foreach ($data as $d) {
            $label = sprintf('%02d:00–%02d:59', $d['hr'], $d['hr']);
            $bars[$label] = r2($d['net']);
            $rows[] = ['hour' => $label, 'count' => (int)$d['cnt'], 'amount' => r2($d['net']), 'avg' => $d['cnt'] ? r2($d['net'] / $d['cnt']) : 0];
            $tot += $d['net'];
        }
        return ['chart' => ['bars' => $bars, 'bars_title' => 'Net sales by hour'],
            'columns' => [['key' => 'hour', 'label' => 'Hour'], ['key' => 'count', 'label' => 'Transactions', 'type' => 'int'], ['key' => 'amount', 'label' => 'Net sales', 'type' => 'money'], ['key' => 'avg', 'label' => 'Average sale', 'type' => 'money']],
            'rows' => $rows, 'totals' => ['hour' => 'TOTAL', 'amount' => r2($tot)]];
    },
]);

Report::define('pos_daily_summary', [
    'filters' => ['range', 'terminal', 'cashier', 'class'],
    'default_dates' => 'this_month',
    'sortable' => false,
    'run' => function (array $f): array {
        $sql = "SELECT t.txn_date, pt.name AS terminal, t.txn_type, t.is_voided, COUNT(*) AS cnt, SUM(t.subtotal) AS sub, SUM(t.tax_total) AS tax, SUM(t.total) AS total
                  FROM transactions t JOIN pos_terminals pt ON pt.id = t.terminal_id
                 WHERE t.txn_type IN ('pos_sale','pos_return') AND t.status <> 'suspended' AND t.txn_date BETWEEN :f AND :t";
        $p = ['f' => $f['from'], 't' => $f['to']];
        if ($f['terminal_id']) {
            $sql .= ' AND t.terminal_id = :term';
            $p['term'] = $f['terminal_id'];
        }
        if ($f['user_id']) {
            $sql .= ' AND t.created_by = :cashier';
            $p['cashier'] = $f['user_id'];
        }
        // the class of the sale (the terminal's or the one the cashier chose); "Unclassified" = sales without one
        [$csql, $cp] = Classes::sqlFilter($f['class_id'] ? (int)$f['class_id'] : null, 't', 'dclass');
        $sql .= $csql;
        $p += $cp;
        $by = [];
        foreach (DB::all($sql . ' GROUP BY t.txn_date, pt.name, t.txn_type, t.is_voided ORDER BY t.txn_date, pt.name', $p) as $r) {
            $k = $r['txn_date'] . '|' . $r['terminal'];
            $by[$k] = ($by[$k] ?? ['date' => $r['txn_date'], 'terminal' => $r['terminal'], 'count' => 0, 'net' => 0.0, 'returns' => 0.0, 'voids' => 0.0, 'tax' => 0.0, 'total' => 0.0]);
            if ((int)$r['is_voided']) {
                $by[$k]['voids'] += $r['total'];
            } elseif ($r['txn_type'] === 'pos_return') {
                $by[$k]['returns'] += $r['total'];
                $by[$k]['net'] -= $r['sub'];
                $by[$k]['tax'] -= $r['tax'];
                $by[$k]['total'] -= $r['total'];
            } else {
                $by[$k]['count'] += $r['cnt'];
                $by[$k]['net'] += $r['sub'];
                $by[$k]['tax'] += $r['tax'];
                $by[$k]['total'] += $r['total'];
            }
        }
        $rows = array_values($by);
        $tot = ['date' => 'TOTAL'];
        foreach (['count', 'net', 'returns', 'voids', 'tax', 'total'] as $x) {
            $tot[$x] = array_sum(array_column($rows, $x));
        }
        return ['columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'terminal', 'label' => 'Terminal'], ['key' => 'count', 'label' => 'Sales', 'type' => 'int'],
            ['key' => 'returns', 'label' => 'Returns', 'type' => 'money'], ['key' => 'voids', 'label' => 'Voids', 'type' => 'money'], ['key' => 'net', 'label' => 'Net sales', 'type' => 'money'],
            ['key' => 'tax', 'label' => 'VAT', 'type' => 'money'], ['key' => 'total', 'label' => 'Total', 'type' => 'money']], 'rows' => $rows, 'totals' => $tot];
    },
]);

Report::define('pos_readings', [
    'filters' => ['range', 'terminal'],
    'default_dates' => 'this_month',
    'run' => function (array $f): array {
        $sql = 'SELECT r.*, t.name AS terminal, u.username FROM pos_readings r JOIN pos_terminals t ON t.id = r.terminal_id JOIN users u ON u.id = r.created_by WHERE r.business_date BETWEEN :f AND :t';
        $p = ['f' => $f['from'], 't' => $f['to']];
        if ($f['terminal_id']) {
            $sql .= ' AND r.terminal_id = :term';
            $p['term'] = $f['terminal_id'];
        }
        $rows = array_map(fn($r) => [
            'date' => $r['business_date'], 'type' => $r['reading_type'] . ($r['z_no'] ? ' #' . $r['z_no'] : ''), 'terminal' => $r['terminal'], 'receipts' => trim($r['first_receipt'] . ' – ' . $r['last_receipt'], ' –'),
            'gross' => (float)$r['gross_sales'], 'disc' => (float)$r['discounts'], 'returns' => (float)$r['returns'], 'voids' => (float)$r['voids'], 'net' => (float)$r['net_sales'], 'tax' => (float)$r['tax'],
            'expected' => (float)$r['expected_cash'], 'counted' => $r['counted_cash'] !== null ? (float)$r['counted_cash'] : '', 'overshort' => $r['over_short'] !== null ? (float)$r['over_short'] : '', 'by' => $r['username'],
            '_links' => ['type' => Report::link('pos.shifts', ['a' => 'reading', 'id' => $r['id']])],
        ], DB::all($sql . ' ORDER BY r.business_date, r.id', $p));
        return ['columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'type', 'label' => 'Reading'], ['key' => 'terminal', 'label' => 'Terminal'], ['key' => 'receipts', 'label' => 'Receipts'],
            ['key' => 'gross', 'label' => 'Gross', 'type' => 'money'], ['key' => 'disc', 'label' => 'Discounts', 'type' => 'money'], ['key' => 'returns', 'label' => 'Returns', 'type' => 'money'],
            ['key' => 'voids', 'label' => 'Voids', 'type' => 'money'], ['key' => 'net', 'label' => 'Net', 'type' => 'money'], ['key' => 'tax', 'label' => 'VAT', 'type' => 'money'],
            ['key' => 'expected', 'label' => 'Expected cash', 'type' => 'money'], ['key' => 'counted', 'label' => 'Counted', 'type' => 'money'], ['key' => 'overshort', 'label' => 'Over/(short)', 'type' => 'money'],
            ['key' => 'by', 'label' => 'By']], 'rows' => $rows];
    },
]);

Report::define('pos_discount_log', [
    'filters' => ['range', 'terminal', 'user'],
    'default_dates' => 'this_month',
    'run' => function (array $f): array {
        $sql = "SELECT t.id AS txn_id, t.txn_type, t.txn_no, t.receipt_no, t.created_at, t.approved_by, pt.name AS terminal, u.full_name, i.code, l.qty, l.rate, l.discount_pct, l.discount_amount
                  FROM transaction_lines l JOIN transactions t ON t.id = l.txn_id JOIN users u ON u.id = t.created_by
                  LEFT JOIN pos_terminals pt ON pt.id = t.terminal_id LEFT JOIN items i ON i.id = l.item_id
                 WHERE t.terminal_id IS NOT NULL AND t.txn_type IN ('pos_sale','invoice') AND t.status <> 'suspended' AND l.discount_amount > 0 AND t.txn_date BETWEEN :f AND :t";
        $p = ['f' => $f['from'], 't' => $f['to']];
        if ($f['terminal_id']) {
            $sql .= ' AND t.terminal_id = :term';
            $p['term'] = $f['terminal_id'];
        }
        if ($f['user_id']) {
            $sql .= ' AND t.created_by = :u';
            $p['u'] = $f['user_id'];
        }
        $users = DB::pairs('SELECT id, username FROM users');
        $rows = [];
        $tot = 0.0;
        foreach (DB::all($sql . ' ORDER BY t.created_at', $p) as $r) {
            $rows[] = ['time' => $r['created_at'], 'receipt' => $r['receipt_no'] ?: $r['txn_no'], 'terminal' => $r['terminal'], 'cashier' => $r['full_name'], 'item' => $r['code'],
                'qty' => (float)$r['qty'], 'price' => (float)$r['rate'], 'pct' => (float)$r['discount_pct'], 'amount' => (float)$r['discount_amount'],
                'approved' => $r['approved_by'] ? ($users[$r['approved_by']] ?? '') : '', '_links' => ['receipt' => Report::txnLink($r)]];
            $tot += $r['discount_amount'];
        }
        return ['columns' => [['key' => 'time', 'label' => 'Time', 'type' => 'datetime'], ['key' => 'receipt', 'label' => 'Receipt'], ['key' => 'terminal', 'label' => 'Terminal'],
            ['key' => 'cashier', 'label' => 'Cashier'], ['key' => 'item', 'label' => 'Item'], ['key' => 'qty', 'label' => 'Qty', 'type' => 'qty'], ['key' => 'price', 'label' => 'Price', 'type' => 'money'],
            ['key' => 'pct', 'label' => 'Disc %', 'type' => 'pct'], ['key' => 'amount', 'label' => 'Discount', 'type' => 'money'], ['key' => 'approved', 'label' => 'Approved by']],
            'rows' => $rows, 'totals' => ['time' => 'TOTAL', 'amount' => r2($tot)]];
    },
]);

Report::define('pos_void_log', [
    'filters' => ['range', 'terminal', 'user'],
    'default_dates' => 'this_month',
    'run' => function (array $f): array {
        $sql = "SELECT a.*, t.name AS terminal FROM audit_trail a LEFT JOIN pos_terminals t ON t.id = a.terminal_id
                 WHERE a.module = 'pos' AND a.action IN ('void','delete','refund') AND a.created_at BETWEEN :f AND :t";
        $p = ['f' => $f['from'] . ' 00:00:00', 't' => $f['to'] . ' 23:59:59'];
        if ($f['terminal_id']) {
            $sql .= ' AND a.terminal_id = :term';
            $p['term'] = $f['terminal_id'];
        }
        if ($f['user_id']) {
            $sql .= ' AND a.user_id = :u';
            $p['u'] = $f['user_id'];
        }
        $rows = [];
        $tot = 0.0;
        foreach (DB::all($sql . ' ORDER BY a.id', $p) as $a) {
            $t = $a['record_id'] ? Txn::load((int)$a['record_id']) : null;
            $rows[] = ['time' => $a['created_at'], 'action' => ucfirst($a['action']), 'record' => $a['record_ref'], 'terminal' => $a['terminal'], 'user' => $a['username'],
                'amount' => (float)$a['amount'], 'reason' => $a['reason'], 'approved' => $a['approved_by'],
                '_links' => ['record' => $t ? Report::txnLink(['txn_type' => $t['txn_type'], 'txn_id' => $t['id']]) : null]];
            $tot += (float)$a['amount'];
        }
        return ['columns' => [['key' => 'time', 'label' => 'Time', 'type' => 'datetime'], ['key' => 'action', 'label' => 'Action'], ['key' => 'record', 'label' => 'Receipt'],
            ['key' => 'terminal', 'label' => 'Terminal'], ['key' => 'user', 'label' => 'Cashier'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'],
            ['key' => 'reason', 'label' => 'Reason'], ['key' => 'approved', 'label' => 'Approved by']],
            'rows' => $rows, 'totals' => ['time' => 'TOTAL', 'amount' => r2($tot)],
            'notes' => 'Taken from the audit trail. Every void and refund requires a supervisor.'];
    },
]);

/**
 * POS Sales by Transaction No. (detail) — every receipt with the items sold on it.
 * One heading row per transaction number, then one row per item, then the receipt totals.
 */
Report::define('pos_sales_txn', [
    'filters' => ['range', 'terminal', 'site', 'cashier'],
    'default_dates' => 'today',
    'sortable' => false,
    'run' => function (array $f): array {
        $lines = Report::salesLines($f['from'], $f['to'], ['pos_only' => true, 'terminal_id' => $f['terminal_id'],
            'site_id' => $f['site_id'], 'created_by' => $f['user_id'] ?? null]);
        $byTxn = [];
        foreach ($lines as $l) {
            $byTxn[(int)$l['txn_id']][] = $l;
        }
        $rows = [];
        $tot = ['ref' => 'TOTAL', 'qty' => 0.0, 'disc' => 0.0, 'amount' => 0.0, 'tax' => 0.0, 'total' => 0.0, 'cost' => 0.0, 'margin' => 0.0];
        if ($byTxn) {
            [$in, $p] = DB::in('t', array_keys($byTxn));
            $heads = DB::all("SELECT t.id, t.txn_type, t.txn_no, t.receipt_no, t.txn_date, t.created_at, t.subtotal, t.tax_total, t.total, t.is_voided,
                                     COALESCE(NULLIF(u.full_name, ''), u.username) AS cashier, pt.name AS terminal, c.name AS customer,
                                     (SELECT GROUP_CONCAT(pm.name ORDER BY pm.name SEPARATOR ', ') FROM pos_tenders pv JOIN payment_methods pm ON pm.id = pv.payment_method_id WHERE pv.txn_id = t.id) AS tender
                                FROM transactions t
                                JOIN users u ON u.id = t.created_by
                                LEFT JOIN pos_terminals pt ON pt.id = t.terminal_id
                                LEFT JOIN customers c ON c.id = t.customer_id
                               WHERE t.id IN $in ORDER BY t.txn_date, t.created_at, t.id", $p);
            foreach ($heads as $h) {
                $ls = $byTxn[(int)$h['id']] ?? [];
                $sgn = $h['txn_type'] === 'pos_return' ? -1 : 1;
                $hDisc = 0.0;
                $hAmt = 0.0;
                $hCost = 0.0;
                foreach ($ls as $l) {
                    $hDisc += $sgn * (float)$l['discount_amount'];
                    $hAmt += (float)$l['s_amount'];
                    $hCost += (float)$l['s_cost'];
                }
                $rows[] = [
                    'ref' => ($h['receipt_no'] ?: $h['txn_no']) . ($h['txn_type'] === 'pos_return' ? ' (return)' : '') . ((int)$h['is_voided'] ? ' — VOID' : ''),
                    'time' => $h['created_at'], 'cashier' => $h['cashier'], 'terminal' => $h['terminal'],
                    'customer' => $h['customer'] ?: 'Walk-in', 'payment' => $h['tender'] ?: 'Charge account',
                    'qty' => '', 'unit' => '', 'price' => '', 'disc' => r2($hDisc), 'amount' => r2($hAmt),
                    'tax' => r2($sgn * (float)$h['tax_total']), 'total' => r2($sgn * (float)$h['total']),
                    'cost' => r2($hCost), 'margin' => r2($hAmt - $hCost),
                    '_class' => 'group-row', '_links' => ['ref' => Report::txnLink(['txn_type' => $h['txn_type'], 'txn_id' => $h['id']])],
                ];
                foreach ($ls as $l) {
                    $rows[] = [
                        'ref' => trim(($l['item_code'] ?? '') . ' - ' . ($l['item_name'] ?: $l['description']), ' -'),
                        'time' => '', 'cashier' => '', 'terminal' => '', 'customer' => '', 'payment' => '',
                        'qty' => abs((float)($l['uom_qty'] ?? 0)) > 0.000001 ? $sgn * (float)$l['uom_qty'] : (float)$l['s_qty'],
                        'unit' => $l['unit'] ?? '',
                        'price' => abs((float)($l['uom_rate'] ?? 0)) > 0.000001 ? (float)$l['uom_rate'] : (float)$l['rate'],
                        'disc' => r2($sgn * (float)$l['discount_amount']), 'amount' => r2((float)$l['s_amount']),
                        'tax' => '', 'total' => '', 'cost' => r2((float)$l['s_cost']), 'margin' => r2((float)$l['s_amount'] - (float)$l['s_cost']),
                        '_class' => 'indent-1',
                    ];
                    $tot['qty'] += (float)$l['s_qty'];   // totals stay in base units
                }
                $tot['disc'] += $hDisc;
                $tot['amount'] += $hAmt;
                $tot['tax'] += $sgn * (float)$h['tax_total'];
                $tot['total'] += $sgn * (float)$h['total'];
                $tot['cost'] += $hCost;
            }
        }
        $tot['margin'] = r2($tot['amount'] - $tot['cost']);
        foreach (['disc', 'amount', 'tax', 'total', 'cost'] as $k) {
            $tot[$k] = r2($tot[$k]);
        }
        return [
            'columns' => [
                ['key' => 'ref', 'label' => 'Transaction no. / item'],
                ['key' => 'time', 'label' => 'Time', 'type' => 'datetime'],
                ['key' => 'cashier', 'label' => 'Cashier'],
                ['key' => 'terminal', 'label' => 'Terminal'],
                ['key' => 'customer', 'label' => 'Customer'],
                ['key' => 'payment', 'label' => 'Payment'],
                ['key' => 'qty', 'label' => 'Qty', 'type' => 'qty'],
                ['key' => 'unit', 'label' => 'U/M'],
                ['key' => 'price', 'label' => 'Price', 'type' => 'money'],
                ['key' => 'disc', 'label' => 'Discount', 'type' => 'money'],
                ['key' => 'amount', 'label' => 'Net sales', 'type' => 'money'],
                ['key' => 'tax', 'label' => 'VAT', 'type' => 'money'],
                ['key' => 'total', 'label' => 'Receipt total', 'type' => 'money'],
                ['key' => 'cost', 'label' => 'Cost', 'type' => 'money', 'sensitive' => 'cost'],
                ['key' => 'margin', 'label' => 'Margin', 'type' => 'money', 'sensitive' => 'cost'],
            ],
            'rows' => $rows,
            'totals' => $tot,
            'notes' => 'One block per transaction number: the receipt line, then every item sold on it. Returns are shown as negatives.',
        ];
    },
]);
