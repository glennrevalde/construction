<?php
declare(strict_types=1);

/** Customer and Receivables reports, and Sales reports. */

/** Open receivables, narrowed by the customer type when one is chosen */
function ar_docs(array $f, bool $withCustomer = true): array
{
    return array_values(array_filter(Aging::receivables($f['asof'], $withCustomer ? $f['customer_id'] : null), fn($d) => Report::customerOk($f, $d['customer_id'])));
}

/** The filters on the customer a receivables report is about */
const AR_FILTERS = ['customer_type'];
/** The filters on what was sold: the item and its category, type and preferred supplier */
const SALES_ITEM_FILTERS = ['item', 'category', 'item_type', 'pref_vendor'];

function cust_label(array $r): string
{
    return ($r['parent'] ?? null) ? $r['parent'] . ':' . $r['customer'] : (string)$r['customer'];
}

function aging_cols(string $first): array
{
    $cols = [['key' => 'name', 'label' => $first]];
    foreach (Aging::BUCKETS as $k => $l) {
        $cols[] = ['key' => $k, 'label' => $l, 'type' => 'money'];
    }
    $cols[] = ['key' => 'total', 'label' => 'Total', 'type' => 'money'];
    return $cols;
}

Report::define('ar_aging_summary', [
    'filters' => ['asof', 'customer', 'customer_type'],
    'run' => function (array $f): array {
        $docs = ar_docs($f);
        foreach ($docs as &$d) {
            $d['label'] = cust_label($d);
        }
        unset($d);
        $sum = Aging::summarize($docs, 'customer_id', 'label');
        $tot = ['name' => 'TOTAL', 'total' => 0.0] + array_fill_keys(array_keys(Aging::BUCKETS), 0.0);
        $rows = [];
        foreach ($sum as $s) {
            $s['_links'] = ['name' => Report::link('rpt.ar_aging_detail', ['asof' => $f['asof'], 'customer_id' => $s['id']])];
            foreach (array_keys(Aging::BUCKETS) as $k) {
                $tot[$k] += $s[$k];
            }
            $tot['total'] += $s['total'];
            $rows[] = $s;
        }
        if (Report::narrowed($f)) {
            // one customer type: the ledger total is of every customer, so there is nothing to compare it with
            return ['columns' => aging_cols('Customer'), 'rows' => $rows, 'totals' => $tot];
        }
        $ar = $f['customer_id'] ? Balances::customerBalance((int)$f['customer_id'], true, $f['asof']) : (function () use ($f) {
            [$in, $p] = DB::in('a', Accts::arIds() ?: [0]);
            return r2(DB::value("SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE account_id IN $in AND txn_date <= :d", $p + ['d' => $f['asof']]));
        })();
        return [
            'columns' => aging_cols('Customer'),
            'rows' => $rows,
            'totals' => $tot,
            'notes' => 'Accounts Receivable per ledger: <strong>' . money($ar) . '</strong>' . (abs($ar - $tot['total']) < 0.01 ? ' — agrees with the aging total.' : ' — <span class="text-danger">differs from the aging total (check journal entries posted directly to A/R).</span>'),
        ];
    },
]);

Report::define('ar_aging_detail', [
    'filters' => ['asof', 'customer', 'customer_type'],
    'sortable' => false,
    'run' => function (array $f): array {
        $docs = ar_docs($f);
        $rows = [];
        $grand = 0.0;
        foreach (Aging::BUCKETS as $bk => $bl) {
            $in = array_filter($docs, fn($d) => $d['bucket'] === $bk);
            if (!$in) {
                continue;
            }
            $rows[] = ['type' => $bk === 'current' ? 'Current' : $bl . ' days past due', '_class' => 'group-row'];
            $sub = 0.0;
            foreach ($in as $d) {
                $rows[] = ['type' => Txn::label($d['txn_type']), 'date' => $d['txn_date'], 'num' => $d['txn_no'], 'name' => cust_label($d), 'terms' => $d['terms'],
                    'due' => $d['due_date'], 'days' => $d['days'] ?: '', 'open' => $d['open'], '_links' => ['num' => Report::txnLink($d)]];
                $sub += $d['open'];
            }
            $rows[] = ['type' => 'Total ' . ($bk === 'current' ? 'Current' : $bl), 'open' => r2($sub), '_class' => 'subtotal-row'];
            $grand += $sub;
        }
        return [
            'grouped' => true,
            'columns' => [['key' => 'type', 'label' => 'Type'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'num', 'label' => 'Num'], ['key' => 'name', 'label' => 'Customer'],
                ['key' => 'terms', 'label' => 'Terms'], ['key' => 'due', 'label' => 'Due date', 'type' => 'date'], ['key' => 'days', 'label' => 'Days past due', 'type' => 'int'], ['key' => 'open', 'label' => 'Open balance', 'type' => 'money']],
            'rows' => $rows,
            'totals' => ['type' => 'TOTAL', 'open' => r2($grand)],
        ];
    },
]);

Report::define('customer_balance_summary', [
    'filters' => ['asof', 'customer', 'customer_type'],
    'run' => function (array $f): array {
        [$in, $p] = DB::in('a', Accts::arIds() ?: [0]);
        $rows = [];
        $tot = 0.0;
        $cw = '';
        if ($f['customer_id']) {
            $cw = ' AND (c.id = :c OR c.parent_id = :c)';
            $p['c'] = $f['customer_id'];
        }
        $cw .= Report::customerCond($f, 'c.id', $p);
        foreach (DB::all("SELECT c.id, c.name, pc.name AS parent, SUM(j.debit - j.credit) AS bal FROM journal_lines j JOIN customers c ON c.id = j.customer_id
                          LEFT JOIN customers pc ON pc.id = c.parent_id WHERE j.account_id IN $in AND j.txn_date <= :d $cw
                          GROUP BY c.id, c.name, pc.name HAVING ABS(SUM(j.debit - j.credit)) >= 0.005 ORDER BY COALESCE(pc.name, c.name), c.name", $p + ['d' => $f['asof']]) as $r) {
            $rows[] = ['name' => ($r['parent'] ? $r['parent'] . ':' : '') . $r['name'], 'balance' => r2($r['bal']),
                '_links' => ['name' => Report::link('rpt.customer_balance_detail', ['customer_id' => $r['id'], 'asof' => $f['asof']])]];
            $tot += $r['bal'];
        }
        return ['columns' => [['key' => 'name', 'label' => 'Customer:Job'], ['key' => 'balance', 'label' => 'Balance', 'type' => 'money']], 'rows' => $rows, 'totals' => ['name' => 'TOTAL', 'balance' => r2($tot)]];
    },
]);

Report::define('customer_balance_detail', [
    'filters' => ['asof', 'customer', 'customer_type'],
    'sortable' => false,
    'run' => function (array $f): array {
        [$in, $p] = DB::in('a', Accts::arIds() ?: [0]);
        $sql = "SELECT j.customer_id, c.name, pc.name AS parent, t.id AS txn_id, t.txn_type, t.txn_no, t.txn_date, t.memo, SUM(j.debit - j.credit) AS amt
                  FROM journal_lines j JOIN transactions t ON t.id = j.txn_id JOIN customers c ON c.id = j.customer_id LEFT JOIN customers pc ON pc.id = c.parent_id
                 WHERE j.account_id IN $in AND j.txn_date <= :d";
        $p['d'] = $f['asof'];
        if ($f['customer_id']) {
            $sql .= ' AND (c.id = :c OR c.parent_id = :c)';
            $p['c'] = $f['customer_id'];
        }
        $sql .= Report::customerCond($f, 'c.id', $p);
        $data = DB::all($sql . ' GROUP BY j.customer_id, c.name, pc.name, t.id, t.txn_type, t.txn_no, t.txn_date, t.memo ORDER BY COALESCE(pc.name, c.name), c.name, t.txn_date, t.id', $p);
        $rows = [];
        $cur = null;
        $run = 0.0;
        $grand = 0.0;
        foreach ($data as $d) {
            $label = ($d['parent'] ? $d['parent'] . ':' : '') . $d['name'];
            if ($label !== $cur) {
                if ($cur !== null) {
                    $rows[] = ['type' => 'Total ' . $cur, 'balance' => r2($run), '_class' => 'subtotal-row'];
                }
                $rows[] = ['type' => $label, '_class' => 'group-row'];
                $cur = $label;
                $run = 0.0;
            }
            $run += $d['amt'];
            $grand += $d['amt'];
            $rows[] = ['type' => Txn::label($d['txn_type']), 'date' => $d['txn_date'], 'num' => $d['txn_no'], 'memo' => $d['memo'], 'amount' => r2($d['amt']), 'balance' => r2($run), '_links' => ['num' => Report::txnLink($d)]];
        }
        if ($cur !== null) {
            $rows[] = ['type' => 'Total ' . $cur, 'balance' => r2($run), '_class' => 'subtotal-row'];
        }
        return [
            'grouped' => true,
            'columns' => [['key' => 'type', 'label' => 'Type'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'num', 'label' => 'Num'], ['key' => 'memo', 'label' => 'Memo'],
                ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'], ['key' => 'balance', 'label' => 'Balance', 'type' => 'money']],
            'rows' => $rows, 'totals' => ['type' => 'TOTAL', 'balance' => r2($grand)],
        ];
    },
]);

Report::define('open_invoices', [
    'filters' => ['asof', 'customer', 'customer_type'],
    'sortable' => false,
    'run' => function (array $f): array {
        $docs = ar_docs($f);
        $rows = [];
        $cur = null;
        $sub = 0.0;
        $grand = 0.0;
        foreach ($docs as $d) {
            $label = cust_label($d);
            if ($label !== $cur) {
                if ($cur !== null) {
                    $rows[] = ['type' => 'Total ' . $cur, 'open' => r2($sub), '_class' => 'subtotal-row'];
                }
                $rows[] = ['type' => $label, '_class' => 'group-row'];
                $cur = $label;
                $sub = 0.0;
            }
            $rows[] = ['type' => ($d['open'] < 0 ? 'Unapplied ' : '') . Txn::label($d['txn_type']), 'date' => $d['txn_date'], 'num' => $d['txn_no'], 'terms' => $d['terms'],
                'due' => $d['due_date'], 'days' => $d['days'] ?: '', 'open' => $d['open'], '_links' => ['num' => Report::txnLink($d)]];
            $sub += $d['open'];
            $grand += $d['open'];
        }
        if ($cur !== null) {
            $rows[] = ['type' => 'Total ' . $cur, 'open' => r2($sub), '_class' => 'subtotal-row'];
        }
        return [
            'grouped' => true,
            'columns' => [['key' => 'type', 'label' => 'Type'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'num', 'label' => 'Num'], ['key' => 'terms', 'label' => 'Terms'],
                ['key' => 'due', 'label' => 'Due date', 'type' => 'date'], ['key' => 'days', 'label' => 'Days past due', 'type' => 'int'], ['key' => 'open', 'label' => 'Open balance', 'type' => 'money']],
            'rows' => $rows, 'totals' => ['type' => 'TOTAL', 'open' => r2($grand)],
            'notes' => 'Unapplied payments and credits (negative amounts) can be applied to these invoices in Receive Payments.',
        ];
    },
]);

Report::define('collections', [
    'filters' => ['asof', 'customer', 'customer_type'],
    'sortable' => false,
    'run' => function (array $f): array {
        $docs = array_filter(ar_docs($f), fn($d) => $d['open'] > 0 && $d['days'] > 0);
        $rows = [];
        $cur = null;
        $sub = 0.0;
        $grand = 0.0;
        foreach ($docs as $d) {
            $label = cust_label($d);
            if ($label !== $cur) {
                if ($cur !== null) {
                    $rows[] = ['type' => 'Total ' . $cur, 'open' => r2($sub), '_class' => 'subtotal-row'];
                }
                $top = DB::row('SELECT contact_person, phone, mobile, email FROM customers WHERE id = :id', ['id' => $d['top_id']]);
                $rows[] = ['type' => $label, 'contact' => $top['contact_person'], 'phone' => trim($top['phone'] . ' ' . $top['mobile']), 'num' => $top['email'], '_class' => 'group-row'];
                $cur = $label;
                $sub = 0.0;
            }
            $rows[] = ['type' => Txn::label($d['txn_type']), 'date' => $d['txn_date'], 'num' => $d['txn_no'], 'due' => $d['due_date'], 'days' => $d['days'], 'open' => $d['open'], '_links' => ['num' => Report::txnLink($d)]];
            $sub += $d['open'];
            $grand += $d['open'];
        }
        if ($cur !== null) {
            $rows[] = ['type' => 'Total ' . $cur, 'open' => r2($sub), '_class' => 'subtotal-row'];
        }
        return [
            'grouped' => true,
            'columns' => [['key' => 'type', 'label' => 'Type'], ['key' => 'contact', 'label' => 'Contact'], ['key' => 'phone', 'label' => 'Phone'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'],
                ['key' => 'num', 'label' => 'Num / email'], ['key' => 'due', 'label' => 'Due date', 'type' => 'date'], ['key' => 'days', 'label' => 'Days overdue', 'type' => 'int'], ['key' => 'open', 'label' => 'Amount due', 'type' => 'money']],
            'rows' => $rows, 'totals' => ['type' => 'TOTAL', 'open' => r2($grand)],
        ];
    },
]);

/** Paid invoices with their final payment date (for Average Days to Pay) */
function paid_invoices(string $from, string $to, ?int $customerId, array $f = []): array
{
    $sql = "SELECT * FROM (
              SELECT t.id AS txn_id, t.txn_type, t.txn_no, t.txn_date, t.total, t.customer_id, c.name AS customer, pc.name AS parent,
                     (SELECT MAX(GREATEST(p.txn_date, t.txn_date)) FROM payment_applications pa JOIN transactions p ON p.id = pa.payment_txn_id
                       WHERE pa.target_txn_id = t.id AND p.is_voided = 0) AS paid_date
                FROM transactions t JOIN customers c ON c.id = t.customer_id LEFT JOIN customers pc ON pc.id = c.parent_id
               WHERE t.txn_type = 'invoice' AND t.status = 'paid' AND t.is_voided = 0" . ($customerId ? ' AND (c.id = :c OR c.parent_id = :c)' : '') . "
            ) x WHERE x.paid_date BETWEEN :f AND :t ORDER BY COALESCE(x.parent, x.customer), x.customer, x.txn_date";
    $p = ['f' => $from, 't' => $to];
    if ($customerId) {
        $p['c'] = $customerId;
    }
    $rows = array_values(array_filter(DB::all($sql, $p), fn($r) => Report::customerOk($f, $r['customer_id'])));
    foreach ($rows as &$r) {
        $r['days'] = max(0, (int)round((strtotime($r['paid_date']) - strtotime($r['txn_date'])) / 86400));
    }
    return $rows;
}

Report::define('avg_days_summary', [
    'filters' => ['range', 'customer', 'customer_type'],
    'default_dates' => 'this_year',
    'run' => function (array $f): array {
        $rows = [];
        $by = [];
        $all = [];
        foreach (paid_invoices($f['from'], $f['to'], $f['customer_id'], $f) as $r) {
            $k = cust_label($r);
            $by[$k]['days'][] = $r['days'];
            $by[$k]['id'] = $r['customer_id'];
            $all[] = $r['days'];
        }
        foreach ($by as $name => $b) {
            $rows[] = ['name' => $name, 'count' => count($b['days']), 'avg' => round(array_sum($b['days']) / count($b['days']), 1),
                '_links' => ['name' => Report::link('rpt.avg_days_detail', ['customer_id' => $b['id'], 'from' => $f['from'], 'to' => $f['to'], 'dates' => 'custom'])]];
        }
        return [
            'columns' => [['key' => 'name', 'label' => 'Customer'], ['key' => 'count', 'label' => 'Invoices paid', 'type' => 'int'], ['key' => 'avg', 'label' => 'Average days to pay', 'type' => 'qty']],
            'rows' => $rows,
            'totals' => ['name' => 'OVERALL AVERAGE', 'count' => count($all), 'avg' => $all ? round(array_sum($all) / count($all), 1) : ''],
            'notes' => 'Days from the invoice date to the date the invoice was fully paid, for invoices paid during the period.',
        ];
    },
]);

Report::define('avg_days_detail', [
    'filters' => ['range', 'customer', 'customer_type'],
    'default_dates' => 'this_year',
    'sortable' => false,
    'run' => function (array $f): array {
        $rows = [];
        $cur = null;
        $list = [];
        $all = [];
        $flush = function () use (&$rows, &$list, &$cur) {
            if ($cur !== null && $list) {
                $rows[] = ['name' => 'Average for ' . $cur, 'days' => round(array_sum($list) / count($list), 1), '_class' => 'subtotal-row'];
            }
            $list = [];
        };
        foreach (paid_invoices($f['from'], $f['to'], $f['customer_id'], $f) as $r) {
            $k = cust_label($r);
            if ($k !== $cur) {
                $flush();
                $rows[] = ['name' => $k, '_class' => 'group-row'];
                $cur = $k;
            }
            $rows[] = ['name' => '', 'num' => $r['txn_no'], 'date' => $r['txn_date'], 'amount' => (float)$r['total'], 'paid' => $r['paid_date'], 'days' => $r['days'], '_links' => ['num' => Report::txnLink($r)]];
            $list[] = $r['days'];
            $all[] = $r['days'];
        }
        $flush();
        return [
            'grouped' => true,
            'columns' => [['key' => 'name', 'label' => 'Customer'], ['key' => 'num', 'label' => 'Invoice'], ['key' => 'date', 'label' => 'Invoice date', 'type' => 'date'],
                ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'], ['key' => 'paid', 'label' => 'Paid in full', 'type' => 'date'], ['key' => 'days', 'label' => 'Days to pay', 'type' => 'qty']],
            'rows' => $rows,
            'totals' => ['name' => 'OVERALL AVERAGE', 'days' => $all ? round(array_sum($all) / count($all), 1) : ''],
        ];
    },
]);

Report::define('ar_graph', [
    'filters' => ['asof', 'customer_type'],
    'run' => function (array $f): array {
        $docs = ar_docs($f, false);
        $bars = array_fill_keys(array_values(Aging::BUCKETS), 0.0);
        $links = [];
        $byCust = [];
        $custId = [];
        foreach ($docs as $d) {
            $bars[Aging::BUCKETS[$d['bucket']]] += $d['open'];
            $name = $d['parent'] ?: $d['customer'];
            $byCust[$name] = ($byCust[$name] ?? 0) + $d['open'];
            $custId[$name] = (int)($d['parent_id'] ?: $d['customer_id']);
        }
        foreach (array_keys(Aging::BUCKETS) as $k) {
            $links[Aging::BUCKETS[$k]] = Report::link('rpt.ar_aging_detail', ['asof' => $f['asof']]);
        }
        arsort($byCust);
        $pie = array_slice($byCust, 0, 5, true);
        $others = array_sum(array_slice($byCust, 5));
        if (abs($others) > 0.004) {
            $pie['All others'] = $others;
        }
        $pie = array_filter($pie, fn($v) => $v > 0);
        $rows = [];
        $total = array_sum($byCust) ?: 1;
        foreach ($byCust as $n => $v) {
            $rows[] = ['name' => $n, 'amount' => r2($v), 'share' => round($v / $total * 100, 1), '_ref' => ['customer' => $custId[$n] ?? 0]];
        }
        return [
            'chart' => ['bars' => $bars, 'bar_links' => $links, 'pie' => $pie, 'bars_title' => 'Receivables by aging period', 'pie_title' => 'Share of total owed by customer'],
            'columns' => [['key' => 'name', 'label' => 'Customer'], ['key' => 'amount', 'label' => 'Owed', 'type' => 'money'], ['key' => 'share', 'label' => '% of total', 'type' => 'pct']],
            'rows' => $rows,
            'totals' => ['name' => 'TOTAL', 'amount' => r2(array_sum($byCust))],
            'notes' => 'A few customers owing a large share of the total indicates concentration risk.',
        ];
    },
]);

Report::define('unbilled_costs', [
    'filters' => ['customer', 'customer_type', 'vendor'],
    'sortable' => false,
    'run' => function (array $f): array {
        $sql = "SELECT l.*, t.txn_type, t.txn_no, t.txn_date, t.id AS txn_id, v.name AS vendor, c.name AS customer, pc.name AS parent, a.name AS account_name, i.code AS item_code
                  FROM transaction_lines l JOIN transactions t ON t.id = l.txn_id JOIN customers c ON c.id = l.customer_id LEFT JOIN customers pc ON pc.id = c.parent_id
                  LEFT JOIN vendors v ON v.id = t.vendor_id LEFT JOIN accounts a ON a.id = l.account_id LEFT JOIN items i ON i.id = l.item_id
                 WHERE l.is_billable = 1 AND l.billed_txn_id IS NULL AND t.is_voided = 0 AND t.txn_type IN ('bill','check')";
        $p = [];
        if ($f['customer_id']) {
            $sql .= ' AND (c.id = :c OR c.parent_id = :c)';
            $p['c'] = $f['customer_id'];
        }
        if ($f['vendor_id']) {
            $sql .= ' AND t.vendor_id = :v';
            $p['v'] = $f['vendor_id'];
        }
        $sql .= Report::customerCond($f, 'c.id', $p);
        $rows = [];
        $cur = null;
        $sub = 0.0;
        $grand = 0.0;
        foreach (DB::all($sql . ' ORDER BY COALESCE(pc.name, c.name), c.name, t.txn_date', $p) as $d) {
            $label = cust_label($d);
            if ($label !== $cur) {
                if ($cur !== null) {
                    $rows[] = ['type' => 'Total ' . $cur, 'amount' => r2($sub), '_class' => 'subtotal-row'];
                }
                $rows[] = ['type' => $label, '_class' => 'group-row'];
                $cur = $label;
                $sub = 0.0;
            }
            $rows[] = ['type' => Txn::label($d['txn_type']), 'date' => $d['txn_date'], 'num' => $d['txn_no'], 'vendor' => $d['vendor'],
                'source' => $d['item_code'] ?: $d['account_name'], 'memo' => $d['description'], 'amount' => (float)$d['amount'], '_links' => ['num' => Report::txnLink($d)]];
            $sub += $d['amount'];
            $grand += $d['amount'];
        }
        if ($cur !== null) {
            $rows[] = ['type' => 'Total ' . $cur, 'amount' => r2($sub), '_class' => 'subtotal-row'];
        }
        return [
            'grouped' => true,
            'columns' => [['key' => 'type', 'label' => 'Type'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'num', 'label' => 'Num'], ['key' => 'vendor', 'label' => 'Vendor'],
                ['key' => 'source', 'label' => 'Account / item'], ['key' => 'memo', 'label' => 'Memo'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money']],
            'rows' => $rows, 'totals' => ['type' => 'TOTAL', 'amount' => r2($grand)],
            'notes' => 'Costs recorded as billable to a customer job (Enter Bills / Write Checks / card charges) that have not been added to an invoice yet.',
        ];
    },
]);

Report::define('txn_list_customer', [
    'filters' => ['range', 'customer', 'customer_type', 'class', 'txn_type', 'nonposting'],
    'sortable' => false,
    'run' => function (array $f): array {
        $types = ['invoice', 'sales_receipt', 'payment', 'credit_memo', 'statement_charge', 'finance_charge', 'refund', 'pos_sale', 'pos_return'];
        if ($f['include_nonposting'] === '1') {
            $types = array_merge($types, ['estimate', 'sales_order']);
        }
        [$in, $p] = DB::in('ty', $types);
        $sql = "SELECT t.*, t.id AS txn_id, c.name AS customer, pc.name AS parent, a.name AS account_name,
                       (SELECT MAX(j.cleared) FROM journal_lines j JOIN accounts ba ON ba.id = j.account_id AND ba.account_type = 'Bank' WHERE j.txn_id = t.id) AS cleared
                  FROM transactions t JOIN customers c ON c.id = t.customer_id LEFT JOIN customers pc ON pc.id = c.parent_id LEFT JOIN accounts a ON a.id = t.account_id
                 WHERE t.txn_type IN $in AND t.txn_date BETWEEN :f AND :t AND t.status <> 'suspended'";
        $p += ['f' => $f['from'], 't' => $f['to']];
        if ($f['customer_id']) {
            $sql .= ' AND (c.id = :c OR c.parent_id = :c)';
            $p['c'] = $f['customer_id'];
        }
        if ($f['txn_type'] !== '') {
            $sql .= ' AND t.txn_type = :tt';
            $p['tt'] = $f['txn_type'];
        }
        if (!empty($f['class_id']) && Classes::ready()) {
            [$csql, $cp] = Classes::sqlFilter((int)$f['class_id'], 't', 'tlc');
            $sql .= $csql;
            $p += $cp;
        }
        $sql .= Report::customerCond($f, 'c.id', $p);
        $rows = [];
        $cur = null;
        foreach (DB::all($sql . ' ORDER BY COALESCE(pc.name, c.name), c.name, t.txn_date, t.id', $p) as $d) {
            $label = cust_label($d);
            if ($label !== $cur) {
                $rows[] = ['type' => $label, '_class' => 'group-row'];
                $cur = $label;
            }
            $sign = in_array($d['txn_type'], ['payment', 'credit_memo', 'pos_return'], true) ? -1 : 1;
            $rows[] = ['type' => Txn::label($d['txn_type']) . ((int)$d['is_voided'] ? ' (void)' : ((int)$d['is_pending'] ? ' (pending)' : '')), 'date' => $d['txn_date'], 'num' => $d['txn_no'],
                'memo' => $d['memo'], 'account' => $d['account_name'], 'cleared' => $d['cleared'] ? '✓' : '', 'amount' => $sign * (float)$d['total'], '_links' => ['num' => Report::txnLink($d)]];
        }
        return [
            'grouped' => true,
            'columns' => [['key' => 'type', 'label' => 'Type'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'num', 'label' => 'Num'], ['key' => 'memo', 'label' => 'Memo'],
                ['key' => 'account', 'label' => 'Account'], ['key' => 'cleared', 'label' => 'Clr'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money']],
            'rows' => $rows,
        ];
    },
]);

Report::define('item_price_list', [
    'filters' => ['category', 'item_type', 'pref_vendor'],
    'run' => function (array $f): array {
        $levels = DB::all('SELECT * FROM price_levels WHERE is_active = 1 ORDER BY name');
        $cols = [['key' => 'code', 'label' => 'Item'], ['key' => 'name', 'label' => 'Description'], ['key' => 'type', 'label' => 'Type'], ['key' => 'unit', 'label' => 'Unit'], ['key' => 'price', 'label' => 'Price', 'type' => 'money']];
        foreach ($levels as $pl) {
            $cols[] = ['key' => 'pl' . $pl['id'], 'label' => $pl['name'], 'type' => 'money'];
        }
        $sql = "SELECT * FROM items WHERE is_active = 1 AND item_type IN ('inventory','assembly','non_inventory','service','other_charge')";
        $p = [];
        $sql .= Report::itemCond($f, 'id', $p);
        $custom = [];
        foreach (DB::all('SELECT * FROM price_level_items') as $c) {
            $custom[$c['price_level_id']][$c['item_id']] = (float)$c['custom_price'];
        }
        $rows = [];
        foreach (DB::all($sql . ' ORDER BY code', $p) as $i) {
            $r = ['code' => $i['code'], 'name' => $i['description'] ?: $i['name'], 'type' => str_replace('_', ' ', $i['item_type']), 'unit' => $i['unit'], 'price' => (float)$i['sales_price'], '_ref' => ['item' => (int)$i['id']]];
            foreach ($levels as $pl) {
                $r['pl' . $pl['id']] = $custom[$pl['id']][$i['id']] ?? round((float)$i['sales_price'] * (1 + $pl['adjust_pct'] / 100), 2);
            }
            $rows[] = $r;
        }
        return ['columns' => $cols, 'rows' => $rows, 'notes' => 'Prices exclude VAT. Price levels apply to the customers assigned to them.'];
    },
]);

// ---------------------------------------------------------------------- sales reports

function sales_summary(array $f, string $keyField, string $labelField, string $firstLabel, ?callable $link = null): array
{
    $mode = $f['columns'] ?: 'total';
    $periods = Report::periods($f['from'], $f['to'], $mode);
    $lines = Report::salesLines($f['from'], $f['to'], $f);
    $by = [];
    foreach ($lines as $l) {
        $k = $l[$keyField] ?? 0;
        $by[$k]['label'] = $l[$labelField] ?? '(none)';
        $by[$k]['total'] = ($by[$k]['total'] ?? 0) + $l['s_amount'];
        if ($periods) {
            $pk = Report::periodKey($l['txn_date'], $mode);
            $by[$k]['p_' . $pk] = ($by[$k]['p_' . $pk] ?? 0) + $l['s_amount'];
        }
    }
    $cols = [['key' => 'name', 'label' => $firstLabel]];
    foreach ($periods as $k => $label) {
        $cols[] = ['key' => 'p_' . $k, 'label' => $label, 'type' => 'money'];
    }
    $cols[] = ['key' => 'total', 'label' => 'Total', 'type' => 'money'];
    $rows = [];
    $tot = ['name' => 'TOTAL', 'total' => 0.0];
    foreach ($by as $k => $b) {
        $r = ['name' => $b['label'], 'total' => r2($b['total'])];
        foreach (array_keys($periods) as $pk) {
            $r['p_' . $pk] = r2($b['p_' . $pk] ?? 0);
            $tot['p_' . $pk] = ($tot['p_' . $pk] ?? 0) + $r['p_' . $pk];
        }
        if ($link) {
            $r['_links'] = ['name' => $link($k)];
        }
        $tot['total'] += $b['total'];
        $rows[] = $r;
    }
    usort($rows, fn($a, $b) => strcasecmp((string)$a['name'], (string)$b['name']));
    return ['columns' => $cols, 'rows' => $rows, 'totals' => $tot, 'notes' => 'Net of VAT, discounts, credit memos and POS returns.'];
}

function sales_detail(array $f, string $groupField, string $groupLabel): array
{
    $lines = Report::salesLines($f['from'], $f['to'], $f);
    usort($lines, fn($a, $b) => [(string)($a[$groupLabel] ?? ''), $a['txn_date'], $a['txn_id']] <=> [(string)($b[$groupLabel] ?? ''), $b['txn_date'], $b['txn_id']]);
    $rows = [];
    $cur = null;
    $sub = 0.0;
    $subQ = 0.0;
    $grand = 0.0;
    $names = [];
    foreach ($lines as $l) {
        $g = (string)($l[$groupLabel] ?? '(none)');
        if ($g !== $cur) {
            if ($cur !== null) {
                $rows[] = ['type' => 'Total ' . $cur, 'qty' => $subQ, 'amount' => r2($sub), '_class' => 'subtotal-row'];
            }
            $rows[] = ['type' => $g, '_class' => 'group-row'];
            $cur = $g;
            $sub = 0.0;
            $subQ = 0.0;
        }
        if (!isset($names[$l['customer_id']])) {
            $names[$l['customer_id']] = $l['customer_id'] ? (($l['parent_id'] ? $l['parent_name'] . ':' : '') . $l['customer_name']) : 'Walk-in';
        }
        $rows[] = [
            'type' => Txn::label($l['txn_type']), 'date' => $l['txn_date'], 'num' => $l['receipt_no'] ?: $l['txn_no'], 'name' => $names[$l['customer_id']],
            'item' => $l['item_code'] ?: $l['description'], 'qty' => $l['s_qty'], 'price' => (float)$l['rate'], 'amount' => r2($l['s_amount']),
            '_links' => ['num' => Report::txnLink($l)],
        ];
        $sub += $l['s_amount'];
        $subQ += $l['s_qty'];
        $grand += $l['s_amount'];
    }
    if ($cur !== null) {
        $rows[] = ['type' => 'Total ' . $cur, 'qty' => $subQ, 'amount' => r2($sub), '_class' => 'subtotal-row'];
    }
    return [
        'grouped' => true,
        'columns' => [['key' => 'type', 'label' => 'Type'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'num', 'label' => 'Num'], ['key' => 'name', 'label' => 'Customer'],
            ['key' => 'item', 'label' => 'Item'], ['key' => 'qty', 'label' => 'Qty', 'type' => 'qty'], ['key' => 'price', 'label' => 'Sales price', 'type' => 'money'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money']],
        'rows' => $rows, 'totals' => ['type' => 'TOTAL', 'amount' => r2($grand)],
    ];
}

function rep_name_map(): array
{
    static $m = null;
    return $m ??= DB::pairs('SELECT id, name FROM sales_reps');
}

Report::define('sales_customer_summary', [
    'filters' => array_merge(['range', 'columns', 'customer_type', 'rep', 'site', 'class'], SALES_ITEM_FILTERS),
    'run' => function (array $f): array {
        $f['_'] = null;
        $data = sales_summary(array_merge($f, ['customer_id' => null]), 'customer_key', 'customer_label', 'Customer:Job',
            fn($k) => $k ? Report::link('rpt.sales_customer_detail', ['customer_id' => $k, 'from' => $f['from'], 'to' => $f['to'], 'dates' => 'custom']) : null);
        return $data;
    },
    'prepare' => true,
]);

Report::define('sales_customer_detail', [
    'filters' => array_merge(['range', 'customer', 'customer_type', 'rep', 'site', 'class'], SALES_ITEM_FILTERS),
    'sortable' => false,
    'run' => fn(array $f) => sales_detail($f, 'customer_key', 'customer_label'),
]);

Report::define('sales_item_summary', [
    'filters' => ['range', 'customer', 'customer_type', 'rep', 'site', 'class', 'category', 'item_type', 'pref_vendor'],
    // the items of each item type sorted on a figure, increasing or decreasing (v29)
    'sort_by' => ['qty' => 'Qty', 'amount' => 'Amount', 'pct' => '% of sales', 'cogs' => 'COGS', 'margin' => 'Gross margin', 'margin_pct' => 'Margin %'],
    'run' => function (array $f): array {
        $lines = Report::salesLines($f['from'], $f['to'], $f);
        $by = [];
        $total = 0.0;
        foreach ($lines as $l) {
            $k = $l['item_id'] ?: 0;
            $by[$k]['type'] = $l['item_type'] ?: 'other';
            $by[$k]['name'] = $l['item_code'] ? $l['item_code'] . ' - ' . $l['item_name'] : ($l['description'] ?: 'Other');
            $by[$k]['qty'] = ($by[$k]['qty'] ?? 0) + $l['s_qty'];
            $by[$k]['amount'] = ($by[$k]['amount'] ?? 0) + $l['s_amount'];
            $by[$k]['cost'] = ($by[$k]['cost'] ?? 0) + $l['s_cost'];
            $total += $l['s_amount'];
        }
        $typeOrder = ['inventory' => 'Inventory', 'assembly' => 'Assembly', 'non_inventory' => 'Non-inventory', 'service' => 'Service', 'other_charge' => 'Other charges', 'discount' => 'Discounts', 'other' => 'Other'];
        $rows = [];
        $tot = ['qty' => 0.0, 'amount' => 0.0, 'cost' => 0.0];
        foreach ($typeOrder as $tk => $tl) {
            $items = array_filter($by, fn($b) => $b['type'] === $tk);
            if (!$items) {
                continue;
            }
            uasort($items, fn($a, $b) => strcmp($a['name'], $b['name']));
            $rows[] = ['name' => $tl, '_class' => 'group-row'];
            $st = ['qty' => 0.0, 'amount' => 0.0, 'cost' => 0.0];
            foreach ($items as $k => $b) {
                $tracked = in_array($tk, ['inventory', 'assembly'], true);
                $rows[] = [
                    'name' => $b['name'], 'qty' => $b['qty'], 'amount' => r2($b['amount']), 'pct' => $total ? round($b['amount'] / $total * 100, 1) : 0,
                    'avg_price' => abs($b['qty']) > 0 ? round($b['amount'] / $b['qty'], 2) : '',
                    'cogs' => $tracked ? r2($b['cost']) : '', 'avg_cost' => $tracked && abs($b['qty']) > 0 ? round($b['cost'] / $b['qty'], 2) : '',
                    'margin' => $tracked ? r2($b['amount'] - $b['cost']) : '', 'margin_pct' => $tracked && abs($b['amount']) > 0 ? round(($b['amount'] - $b['cost']) / $b['amount'] * 100, 1) : '',
                    '_links' => ['name' => $k ? Report::link('rpt.sales_item_detail', ['item_id' => $k, 'from' => $f['from'], 'to' => $f['to'], 'dates' => 'custom']) : null],
                    '_class' => 'indent-1',
                ];
                foreach (['qty', 'amount'] as $x) {
                    $st[$x] += $b[$x];
                }
                if ($tracked) {
                    $st['cost'] += $b['cost'];
                }
            }
            $rows[] = ['name' => 'Total ' . $tl, 'qty' => $st['qty'], 'amount' => r2($st['amount']), 'pct' => $total ? round($st['amount'] / $total * 100, 1) : 0,
                'cogs' => r2($st['cost']) ?: '', 'margin' => in_array($tk, ['inventory', 'assembly'], true) ? r2($st['amount'] - $st['cost']) : '', '_class' => 'subtotal-row'];
            foreach ($st as $x => $v) {
                $tot[$x] += $v;
            }
        }
        return [
            'grouped' => true,
            'columns' => [['key' => 'name', 'label' => 'Item'], ['key' => 'qty', 'label' => 'Qty', 'type' => 'qty'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'],
                ['key' => 'pct', 'label' => '% of sales', 'type' => 'pct'], ['key' => 'avg_price', 'label' => 'Avg price', 'type' => 'money'],
                ['key' => 'cogs', 'label' => 'COGS', 'type' => 'money', 'sensitive' => 'cost'], ['key' => 'avg_cost', 'label' => 'Avg cost', 'type' => 'money', 'sensitive' => 'cost'],
                ['key' => 'margin', 'label' => 'Gross margin', 'type' => 'money', 'sensitive' => 'cost'], ['key' => 'margin_pct', 'label' => 'Margin %', 'type' => 'pct', 'sensitive' => 'cost']],
            'rows' => $rows,
            'totals' => ['name' => 'TOTAL', 'amount' => r2($tot['amount']), 'pct' => 100, 'cogs' => r2($tot['cost']), 'margin' => r2($tot['amount'] - $tot['cost']),
                'margin_pct' => $tot['amount'] ? round(($tot['amount'] - $tot['cost']) / $tot['amount'] * 100, 1) : ''],
        ];
    },
]);

Report::define('sales_item_detail', [
    'filters' => array_merge(['range', 'customer', 'customer_type', 'rep', 'site', 'class'], SALES_ITEM_FILTERS),
    'sortable' => false,
    'run' => fn(array $f) => sales_detail($f, 'item_id', 'item_label'),
]);

Report::define('sales_rep_summary', [
    'filters' => array_merge(['range', 'columns', 'customer', 'customer_type', 'site', 'class'], SALES_ITEM_FILTERS),
    'run' => function (array $f): array {
        $data = sales_summary($f, 'sales_rep_id', 'rep_label', 'Sales rep',
            fn($k) => Report::link('rpt.sales_rep_detail', ['rep_id' => $k, 'from' => $f['from'], 'to' => $f['to'], 'dates' => 'custom']));
        $comm = DB::pairs('SELECT name, commission_pct FROM sales_reps');
        $data['columns'][] = ['key' => 'commission', 'label' => 'Commission', 'type' => 'money'];
        foreach ($data['rows'] as &$r) {
            $r['commission'] = isset($comm[$r['name']]) ? r2($r['total'] * $comm[$r['name']] / 100) : '';
        }
        $data['notes'] = 'Based on the sales rep on each sales transaction. Commission uses the rate on the sales rep list.';
        return $data;
    },
]);

Report::define('sales_rep_detail', [
    'filters' => array_merge(['range', 'rep', 'customer', 'customer_type', 'site', 'class'], SALES_ITEM_FILTERS),
    'sortable' => false,
    'run' => fn(array $f) => sales_detail($f, 'sales_rep_id', 'rep_label'),
]);

Report::define('inventory_sales_site', [
    'filters' => array_merge(['range', 'site', 'customer', 'customer_type', 'class'], SALES_ITEM_FILTERS),
    'sortable' => false,
    // the items of each site sorted on a figure, increasing or decreasing (v29)
    'sort_by' => ['qty' => 'Qty sold', 'amount' => 'Sales', 'cost' => 'Cost of sales'],
    'run' => function (array $f): array {
        $lines = array_filter(Report::salesLines($f['from'], $f['to'], $f), fn($l) => in_array($l['item_type'], ['inventory', 'assembly'], true));
        $sites = DB::pairs('SELECT id, name FROM sites');
        $by = [];
        foreach ($lines as $l) {
            $s = $sites[$l['site_id']] ?? '(no site)';
            $k = $l['item_code'] . ' - ' . $l['item_name'];
            $by[$s][$k]['qty'] = ($by[$s][$k]['qty'] ?? 0) + $l['s_qty'];
            $by[$s][$k]['amount'] = ($by[$s][$k]['amount'] ?? 0) + $l['s_amount'];
            $by[$s][$k]['cost'] = ($by[$s][$k]['cost'] ?? 0) + $l['s_cost'];
            $by[$s][$k]['item_id'] = (int)$l['item_id'];
        }
        ksort($by);
        $rows = [];
        $tq = 0.0;
        $ta = 0.0;
        $tc = 0.0;
        foreach ($by as $site => $items) {
            ksort($items);
            $rows[] = ['name' => $site, '_class' => 'group-row'];
            $sq = 0.0;
            $sa = 0.0;
            $sc = 0.0;
            foreach ($items as $name => $v) {
                $rows[] = ['name' => $name, 'qty' => $v['qty'], 'amount' => r2($v['amount']), 'cost' => r2($v['cost']), '_class' => 'indent-1', '_ref' => ['item' => $v['item_id']]];
                $sq += $v['qty'];
                $sa += $v['amount'];
                $sc += $v['cost'];
            }
            $rows[] = ['name' => 'Total ' . $site, 'qty' => $sq, 'amount' => r2($sa), 'cost' => r2($sc), '_class' => 'subtotal-row'];
            $tq += $sq;
            $ta += $sa;
            $tc += $sc;
        }
        return [
            'grouped' => true,
            'columns' => [['key' => 'name', 'label' => 'Site / item'], ['key' => 'qty', 'label' => 'Qty sold', 'type' => 'qty'], ['key' => 'amount', 'label' => 'Sales', 'type' => 'money'],
                ['key' => 'cost', 'label' => 'Cost of sales', 'type' => 'money', 'sensitive' => 'cost']],
            'rows' => $rows, 'totals' => ['name' => 'TOTAL', 'qty' => $tq, 'amount' => r2($ta), 'cost' => r2($tc)],
            'notes' => 'Each POS terminal sells from its store\'s site, so counter and back-office sales are compared by location.',
        ];
    },
]);

Report::define('pending_sales', [
    'filters' => ['customer', 'customer_type'],
    'run' => function (array $f): array {
        $sql = "SELECT t.*, t.id AS txn_id, c.name AS customer, pc.name AS parent FROM transactions t JOIN customers c ON c.id = t.customer_id LEFT JOIN customers pc ON pc.id = c.parent_id
                 WHERE t.is_pending = 1 AND t.is_voided = 0 AND t.txn_type IN ('invoice','sales_receipt')";
        $p = [];
        if ($f['customer_id']) {
            $sql .= ' AND (c.id = :c OR c.parent_id = :c)';
            $p['c'] = $f['customer_id'];
        }
        $sql .= Report::customerCond($f, 'c.id', $p);
        $rows = [];
        $tot = 0.0;
        foreach (DB::all($sql . ' ORDER BY t.txn_date', $p) as $d) {
            $rows[] = ['type' => Txn::label($d['txn_type']), 'date' => $d['txn_date'], 'num' => $d['txn_no'], 'name' => cust_label($d), 'memo' => $d['memo'], 'amount' => (float)$d['total'], '_links' => ['num' => Report::txnLink($d)]];
            $tot += $d['total'];
        }
        return [
            'columns' => [['key' => 'type', 'label' => 'Type'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'num', 'label' => 'Num'], ['key' => 'name', 'label' => 'Customer'],
                ['key' => 'memo', 'label' => 'Memo'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money']],
            'rows' => $rows, 'totals' => ['type' => 'TOTAL', 'amount' => r2($tot)],
            'notes' => 'Pending sales do not affect the books or inventory until they are marked final.',
        ];
    },
]);

function open_so_lines(?int $customerId, ?int $itemId, array $f = []): array
{
    $sql = "SELECT l.*, t.id AS txn_id, t.txn_type, t.txn_no, t.txn_date, t.ship_date, c.name AS customer, pc.name AS parent, i.code AS item_code, i.name AS item_name,
                   GREATEST(l.qty - l.qty_fulfilled, 0) AS backordered
              FROM transaction_lines l JOIN transactions t ON t.id = l.txn_id JOIN customers c ON c.id = t.customer_id
              LEFT JOIN customers pc ON pc.id = c.parent_id LEFT JOIN items i ON i.id = l.item_id
             WHERE t.txn_type = 'sales_order' AND t.is_voided = 0 AND t.status IN ('open','partial') AND l.is_closed = 0 AND l.item_id IS NOT NULL AND l.qty > l.qty_fulfilled";
    $p = [];
    if ($customerId) {
        $sql .= ' AND (c.id = :c OR c.parent_id = :c)';
        $p['c'] = $customerId;
    }
    if ($itemId) {
        $sql .= ' AND l.item_id = :i';
        $p['i'] = $itemId;
    }
    if (!empty($f['site_id'])) {
        $sql .= ' AND COALESCE(l.site_id, t.site_id) = :s';
        $p['s'] = (int)$f['site_id'];
    }
    $sql .= Report::itemCond($f, 'l.item_id', $p) . Report::customerCond($f, 'c.id', $p);
    return DB::all($sql . ' ORDER BY t.txn_date, t.id, l.line_no', $p);
}

Report::define('open_so_customer', [
    'filters' => array_merge(['customer', 'customer_type', 'site'], SALES_ITEM_FILTERS),
    'sortable' => false,
    'run' => function (array $f): array {
        $data = open_so_lines($f['customer_id'], $f['item_id'], $f);
        usort($data, fn($a, $b) => [cust_label($a), $a['txn_date']] <=> [cust_label($b), $b['txn_date']]);
        $rows = [];
        $cur = null;
        $grand = 0.0;
        foreach ($data as $d) {
            $label = cust_label($d);
            if ($label !== $cur) {
                $rows[] = ['date' => $label, '_class' => 'group-row'];
                $cur = $label;
            }
            $open = r2((float)$d['backordered'] * (float)$d['rate'] * (1 - (float)$d['discount_pct'] / 100));
            $rows[] = ['date' => $d['txn_date'], 'num' => $d['txn_no'], 'ship' => $d['ship_date'], 'item' => $d['item_code'] . ' - ' . $d['item_name'],
                'ordered' => (float)$d['qty'], 'invoiced' => (float)$d['qty_fulfilled'], 'backordered' => (float)$d['backordered'], 'open' => $open, '_links' => ['num' => Report::txnLink($d)]];
            $grand += $open;
        }
        return [
            'grouped' => true,
            'columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'num', 'label' => 'Num'], ['key' => 'ship', 'label' => 'Ship date', 'type' => 'date'], ['key' => 'item', 'label' => 'Item'],
                ['key' => 'ordered', 'label' => 'Ordered', 'type' => 'qty'], ['key' => 'invoiced', 'label' => 'Invoiced', 'type' => 'qty'], ['key' => 'backordered', 'label' => 'Backordered', 'type' => 'qty'],
                ['key' => 'open', 'label' => 'Open amount', 'type' => 'money']],
            'rows' => $rows, 'totals' => ['date' => 'TOTAL', 'open' => r2($grand)],
        ];
    },
]);

Report::define('open_so_item', [
    'filters' => array_merge(['customer', 'customer_type', 'site'], SALES_ITEM_FILTERS),
    'sortable' => false,
    'run' => function (array $f): array {
        $data = open_so_lines($f['customer_id'], $f['item_id'], $f);
        usort($data, fn($a, $b) => [$a['item_code'], $a['txn_date']] <=> [$b['item_code'], $b['txn_date']]);
        $rows = [];
        $cur = null;
        $q = 0.0;
        foreach ($data as $d) {
            $label = $d['item_code'] . ' - ' . $d['item_name'];
            if ($label !== $cur) {
                if ($cur !== null) {
                    $rows[] = ['date' => 'Total ' . $cur, 'backordered' => $q, 'onhand' => Stock::onHand((int)$prevItem), 'onpo' => Stock::onPurchaseOrder((int)$prevItem), '_class' => 'subtotal-row'];
                }
                $rows[] = ['date' => $label, '_class' => 'group-row'];
                $cur = $label;
                $q = 0.0;
            }
            $prevItem = $d['item_id'];
            $rows[] = ['date' => $d['txn_date'], 'num' => $d['txn_no'], 'name' => cust_label($d), 'ship' => $d['ship_date'], 'backordered' => (float)$d['backordered'], '_links' => ['num' => Report::txnLink($d)]];
            $q += (float)$d['backordered'];
        }
        if ($cur !== null) {
            $rows[] = ['date' => 'Total ' . $cur, 'backordered' => $q, 'onhand' => Stock::onHand((int)$prevItem), 'onpo' => Stock::onPurchaseOrder((int)$prevItem), '_class' => 'subtotal-row'];
        }
        return [
            'grouped' => true,
            'columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'num', 'label' => 'Num'], ['key' => 'name', 'label' => 'Customer'], ['key' => 'ship', 'label' => 'Ship date', 'type' => 'date'],
                ['key' => 'backordered', 'label' => 'Qty committed', 'type' => 'qty'], ['key' => 'onhand', 'label' => 'On hand', 'type' => 'qty'], ['key' => 'onpo', 'label' => 'On PO', 'type' => 'qty']],
            'rows' => $rows,
            'notes' => 'How much of each item is needed to fill existing orders — often the trigger for a purchase order or an assembly build.',
        ];
    },
]);
