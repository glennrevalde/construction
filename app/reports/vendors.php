<?php
declare(strict_types=1);

/** Vendors and Payables reports. */

Report::define('ap_aging_summary', [
    'filters' => ['asof', 'vendor'],
    'run' => function (array $f): array {
        $docs = Aging::payables($f['asof'], $f['vendor_id']);
        $sum = Aging::summarize($docs, 'vendor_id', 'vendor');
        $tot = ['name' => 'TOTAL', 'total' => 0.0] + array_fill_keys(array_keys(Aging::BUCKETS), 0.0);
        foreach ($sum as &$s) {
            $s['_links'] = ['name' => Report::link('rpt.ap_aging_detail', ['asof' => $f['asof'], 'vendor_id' => $s['id']])];
            foreach (array_keys(Aging::BUCKETS) as $k) {
                $tot[$k] += $s[$k];
            }
            $tot['total'] += $s['total'];
        }
        unset($s);
        [$in, $p] = DB::in('a', Accts::apIds() ?: [0]);
        $ap = $f['vendor_id'] ? Balances::vendorBalance((int)$f['vendor_id'], $f['asof'])
            : r2(DB::value("SELECT COALESCE(SUM(credit - debit),0) FROM journal_lines WHERE account_id IN $in AND txn_date <= :d", $p + ['d' => $f['asof']]));
        return [
            'columns' => aging_cols('Vendor'),
            'rows' => $sum,
            'totals' => $tot,
            'notes' => 'Accounts Payable per ledger: <strong>' . money($ap) . '</strong>' . (abs($ap - $tot['total']) < 0.01 ? ' — agrees with the aging total.' : ' — <span class="text-danger">differs from the aging total.</span>')
                . ' Item receipts (goods received, bill not yet entered) are included.',
        ];
    },
]);

Report::define('ap_aging_detail', [
    'filters' => ['asof', 'vendor'],
    'sortable' => false,
    'run' => function (array $f): array {
        $docs = Aging::payables($f['asof'], $f['vendor_id']);
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
                $d['txn_id'] = $d['id'];
                $rows[] = ['type' => Txn::label($d['txn_type']), 'date' => $d['txn_date'], 'num' => $d['ref_no'] ?: $d['txn_no'], 'name' => $d['vendor'], 'terms' => $d['terms'],
                    'due' => $d['due_date'], 'days' => $d['days'] ?: '', 'open' => $d['open'], '_links' => ['num' => Report::txnLink($d)]];
                $sub += $d['open'];
            }
            $rows[] = ['type' => 'Total ' . ($bk === 'current' ? 'Current' : $bl), 'open' => r2($sub), '_class' => 'subtotal-row'];
            $grand += $sub;
        }
        return [
            'grouped' => true,
            'columns' => [['key' => 'type', 'label' => 'Type'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'num', 'label' => 'Num / vendor ref.'], ['key' => 'name', 'label' => 'Vendor'],
                ['key' => 'terms', 'label' => 'Terms'], ['key' => 'due', 'label' => 'Due date', 'type' => 'date'], ['key' => 'days', 'label' => 'Days past due', 'type' => 'int'], ['key' => 'open', 'label' => 'Open balance', 'type' => 'money']],
            'rows' => $rows,
            'totals' => ['type' => 'TOTAL', 'open' => r2($grand)],
        ];
    },
]);

Report::define('vendor_balance_summary', [
    'filters' => ['asof', 'vendor'],
    'run' => function (array $f): array {
        [$in, $p] = DB::in('a', Accts::apIds() ?: [0]);
        $rows = [];
        $tot = 0.0;
        $vw = '';
        if ($f['vendor_id']) {
            $vw = ' AND v.id = :v';
            $p['v'] = $f['vendor_id'];
        }
        foreach (DB::all("SELECT v.id, v.name, SUM(j.credit - j.debit) AS bal FROM journal_lines j JOIN vendors v ON v.id = j.vendor_id
                          WHERE j.account_id IN $in AND j.txn_date <= :d $vw GROUP BY v.id, v.name HAVING ABS(SUM(j.credit - j.debit)) >= 0.005 ORDER BY v.name", $p + ['d' => $f['asof']]) as $r) {
            $rows[] = ['name' => $r['name'], 'balance' => r2($r['bal']), '_links' => ['name' => Report::link('rpt.vendor_balance_detail', ['vendor_id' => $r['id'], 'asof' => $f['asof']])]];
            $tot += $r['bal'];
        }
        return ['columns' => [['key' => 'name', 'label' => 'Vendor'], ['key' => 'balance', 'label' => 'Balance', 'type' => 'money']], 'rows' => $rows, 'totals' => ['name' => 'TOTAL', 'balance' => r2($tot)]];
    },
]);

Report::define('vendor_balance_detail', [
    'filters' => ['asof', 'vendor'],
    'sortable' => false,
    'run' => function (array $f): array {
        [$in, $p] = DB::in('a', Accts::apIds() ?: [0]);
        $sql = "SELECT v.name, t.id AS txn_id, t.txn_type, t.txn_no, t.ref_no, t.txn_date, t.memo, SUM(j.credit - j.debit) AS amt
                  FROM journal_lines j JOIN transactions t ON t.id = j.txn_id JOIN vendors v ON v.id = j.vendor_id
                 WHERE j.account_id IN $in AND j.txn_date <= :d";
        $p['d'] = $f['asof'];
        if ($f['vendor_id']) {
            $sql .= ' AND v.id = :v';
            $p['v'] = $f['vendor_id'];
        }
        $rows = [];
        $cur = null;
        $run = 0.0;
        $grand = 0.0;
        foreach (DB::all($sql . ' GROUP BY v.name, t.id, t.txn_type, t.txn_no, t.ref_no, t.txn_date, t.memo ORDER BY v.name, t.txn_date, t.id', $p) as $d) {
            if ($d['name'] !== $cur) {
                if ($cur !== null) {
                    $rows[] = ['type' => 'Total ' . $cur, 'balance' => r2($run), '_class' => 'subtotal-row'];
                }
                $rows[] = ['type' => $d['name'], '_class' => 'group-row'];
                $cur = $d['name'];
                $run = 0.0;
            }
            $run += $d['amt'];
            $grand += $d['amt'];
            $rows[] = ['type' => Txn::label($d['txn_type']), 'date' => $d['txn_date'], 'num' => $d['ref_no'] ?: $d['txn_no'], 'memo' => $d['memo'], 'amount' => r2($d['amt']), 'balance' => r2($run), '_links' => ['num' => Report::txnLink($d)]];
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

Report::define('unpaid_bills', [
    'filters' => ['asof', 'vendor'],
    'sortable' => false,
    'run' => function (array $f): array {
        $docs = array_filter(Aging::payables($f['asof'], $f['vendor_id']), fn($d) => $d['txn_type'] !== 'item_receipt');
        $rows = [];
        $cur = null;
        $sub = 0.0;
        $grand = 0.0;
        foreach ($docs as $d) {
            if ($d['vendor'] !== $cur) {
                if ($cur !== null) {
                    $rows[] = ['type' => 'Total ' . $cur, 'open' => r2($sub), '_class' => 'subtotal-row'];
                }
                $rows[] = ['type' => $d['vendor'], '_class' => 'group-row'];
                $cur = $d['vendor'];
                $sub = 0.0;
            }
            $d['txn_id'] = $d['id'];
            $rows[] = ['type' => ($d['open'] < 0 ? 'Unapplied ' : '') . Txn::label($d['txn_type']), 'date' => $d['txn_date'], 'num' => $d['ref_no'] ?: $d['txn_no'], 'due' => $d['due_date'],
                'days' => $d['days'] ?: '', 'open' => $d['open'], '_links' => ['num' => Report::txnLink($d)]];
            $sub += $d['open'];
            $grand += $d['open'];
        }
        if ($cur !== null) {
            $rows[] = ['type' => 'Total ' . $cur, 'open' => r2($sub), '_class' => 'subtotal-row'];
        }
        return [
            'grouped' => true,
            'columns' => [['key' => 'type', 'label' => 'Type'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'num', 'label' => 'Num / vendor ref.'],
                ['key' => 'due', 'label' => 'Due date', 'type' => 'date'], ['key' => 'days', 'label' => 'Days past due', 'type' => 'int'], ['key' => 'open', 'label' => 'Open balance', 'type' => 'money']],
            'rows' => $rows, 'totals' => ['type' => 'TOTAL', 'open' => r2($grand)],
            'footer' => Access::can('vend.pay_bills', 'create') ? '<a class="btn btn-sm btn-success no-print" href="' . h(url('vend.pay_bills')) . '">Go to Pay Bills</a>' : '',
        ];
    },
]);

Report::define('txn_list_vendor', [
    'filters' => ['range', 'vendor', 'class', 'txn_type'],
    'sortable' => false,
    'run' => function (array $f): array {
        $sql = "SELECT t.*, t.id AS txn_id, v.name AS vendor, a.name AS account_name,
                       (SELECT MAX(j.cleared) FROM journal_lines j JOIN accounts ba ON ba.id = j.account_id AND ba.account_type = 'Bank' WHERE j.txn_id = t.id) AS cleared
                  FROM transactions t JOIN vendors v ON v.id = t.vendor_id LEFT JOIN accounts a ON a.id = t.account_id
                 WHERE t.txn_date BETWEEN :f AND :t";
        $p = ['f' => $f['from'], 't' => $f['to']];
        if ($f['vendor_id']) {
            $sql .= ' AND v.id = :v';
            $p['v'] = $f['vendor_id'];
        }
        if ($f['txn_type'] !== '') {
            $sql .= ' AND t.txn_type = :tt';
            $p['tt'] = $f['txn_type'];
        }
        if (!empty($f['class_id']) && Classes::ready()) {
            [$csql, $cp] = Classes::sqlFilter((int)$f['class_id'], 't', 'tlv');
            $sql .= $csql;
            $p += $cp;
        }
        $rows = [];
        $cur = null;
        foreach (DB::all($sql . ' ORDER BY v.name, t.txn_date, t.id', $p) as $d) {
            if ($d['vendor'] !== $cur) {
                $rows[] = ['type' => $d['vendor'], '_class' => 'group-row'];
                $cur = $d['vendor'];
            }
            $sign = in_array($d['txn_type'], ['bill_payment', 'check', 'vendor_credit', 'vendor_advance'], true) ? -1 : 1;
            $rows[] = ['type' => Txn::label($d['txn_type']) . ((int)$d['is_voided'] ? ' (void)' : ''), 'date' => $d['txn_date'], 'num' => $d['txn_no'], 'ref' => $d['ref_no'],
                'memo' => $d['memo'], 'account' => $d['account_name'], 'cleared' => $d['cleared'] ? '✓' : '', 'amount' => $sign * (float)$d['total'], '_links' => ['num' => Report::txnLink($d)]];
        }
        return [
            'grouped' => true,
            'columns' => [['key' => 'type', 'label' => 'Type'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'num', 'label' => 'Num'], ['key' => 'ref', 'label' => 'Vendor ref.'],
                ['key' => 'memo', 'label' => 'Memo'], ['key' => 'account', 'label' => 'Account'], ['key' => 'cleared', 'label' => 'Clr'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money']],
            'rows' => $rows,
            'notes' => 'Purchase orders are non-posting and are listed for reference.',
        ];
    },
]);

Report::define('vendor_contacts', [
    'filters' => ['search'],
    'run' => function (array $f): array {
        [$in, $p] = DB::in('a', Accts::apIds() ?: [0]);
        $sql = "SELECT v.*, (SELECT COALESCE(SUM(j.credit - j.debit),0) FROM journal_lines j WHERE j.vendor_id = v.id AND j.account_id IN $in) AS bal FROM vendors v WHERE v.is_active = 1";
        if ($f['q'] !== '') {
            $sql .= ' AND (v.name LIKE :q OR v.contact_person LIKE :q)';
            $p['q'] = '%' . $f['q'] . '%';
        }
        $rows = array_map(fn($v) => ['name' => $v['name'], 'contact' => $v['contact_person'], 'phone' => $v['phone'], 'fax' => $v['fax'], 'email' => $v['email'],
            'address' => $v['address'], 'account_no' => $v['account_no'], 'balance' => r2($v['bal']), 'tin' => $v['tin'],
            // the taxes usually withheld from the supplier (Withholding Tax Table)
            'wht' => implode(', ', array_filter(array_map(fn($i) => Withholding::get($i) ? Withholding::label(Withholding::get($i)) : null, Withholding::defaults($v['wht_ids'] ?? '')))),
            '_links' => ['name' => Report::link('vend.center', ['id' => $v['id']])]], DB::all($sql . ' ORDER BY v.name', $p));
        return [
            'columns' => [['key' => 'name', 'label' => 'Vendor'], ['key' => 'contact', 'label' => 'Contact'], ['key' => 'phone', 'label' => 'Phone'], ['key' => 'fax', 'label' => 'Fax'],
                ['key' => 'email', 'label' => 'Email'], ['key' => 'address', 'label' => 'Billing address'], ['key' => 'account_no', 'label' => 'Account no.'],
                ['key' => 'tin', 'label' => 'TIN', 'optional' => true], ['key' => 'wht', 'label' => 'Withholding taxes', 'optional' => true], ['key' => 'balance', 'label' => 'Balance owed', 'type' => 'money']],
            'rows' => $rows,
        ];
    },
]);
