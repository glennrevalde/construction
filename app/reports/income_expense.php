<?php
declare(strict_types=1);

/**
 * Income by Customer (what each customer's purchases earned: sales less the cost of the items sold) and
 * Expenses by Vendor (what was spent with each supplier, from the expense accounts of the ledger).
 */

// ---------------------------------------------------------------------- income by customer

/**
 * The sales lines of the period with their cost and income, for the income reports.
 * Sales are net of VAT and discounts; credit memos and POS returns count against the customer.
 */
function income_lines(array $f): array
{
    $lines = Report::salesLines($f['from'], $f['to'], $f);
    foreach ($lines as &$l) {
        $l['s_income'] = $l['s_amount'] - $l['s_cost'];
    }
    unset($l);
    return $lines;
}

function income_margin(float $sales, float $income): string|float
{
    return abs($sales) > 0.004 ? round($income / $sales * 100, 1) : '';
}

Report::define('income_customer_summary', [
    'filters' => ['range', 'customer_type', 'rep', 'class', 'site', 'item', 'category', 'item_type', 'pref_vendor'],
    // the customers sorted on a figure, increasing or decreasing (v29)
    'sort_by' => ['qty' => 'Qty sold', 'sales' => 'Sales', 'cost' => 'Cost of sales', 'income' => 'Income / (loss)', 'margin' => 'Margin %'],
    'default_dates' => 'this_month',
    'run' => function (array $f): array {
        $by = [];
        foreach (income_lines(array_merge($f, ['customer_id' => null])) as $l) {
            $k = $l['customer_key'];
            $b = &$by[$k];
            $b['name'] = $l['customer_label'];
            $b['txns'][$l['txn_id']] = true;
            $b['qty'] = ($b['qty'] ?? 0) + $l['s_qty'];
            $b['sales'] = ($b['sales'] ?? 0) + $l['s_amount'];
            $b['cost'] = ($b['cost'] ?? 0) + $l['s_cost'];
            unset($b);
        }
        $rows = [];
        $tot = ['name' => 'TOTAL', 'count' => 0, 'qty' => 0.0, 'sales' => 0.0, 'cost' => 0.0, 'income' => 0.0];
        foreach ($by as $k => $b) {
            $income = r2($b['sales'] - $b['cost']);
            $rows[] = ['name' => $b['name'], 'count' => count($b['txns']), 'qty' => $b['qty'], 'sales' => r2($b['sales']), 'cost' => r2($b['cost']),
                'income' => $income, 'margin' => income_margin($b['sales'], $income),
                '_links' => ['name' => $k ? Report::link('rpt.income_customer_detail', ['customer_id' => $k, 'dates' => 'custom', 'from' => $f['from'], 'to' => $f['to'],
                    'rep_id' => $f['rep_id'], 'class_id' => $f['class_id'], 'site_id' => $f['site_id']]) : null],
                '_ref' => $k ? ['customer' => $k] : [], '_class' => $income < -0.004 ? 'income-loss' : ''];
            $tot['count'] += count($b['txns']);
            $tot['qty'] += $b['qty'];
            $tot['sales'] += $b['sales'];
            $tot['cost'] += $b['cost'];
        }
        usort($rows, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        $tot['income'] = r2($tot['sales'] - $tot['cost']);
        $tot['margin'] = income_margin($tot['sales'], $tot['income']);
        foreach (['sales', 'cost'] as $x) {
            $tot[$x] = r2($tot[$x]);
        }
        return [
            'columns' => [['key' => 'name', 'label' => 'Customer'], ['key' => 'count', 'label' => 'Transactions', 'type' => 'int'], ['key' => 'qty', 'label' => 'Qty sold', 'type' => 'qty'],
                ['key' => 'sales', 'label' => 'Sales', 'type' => 'money'], ['key' => 'cost', 'label' => 'Cost of sales', 'type' => 'money', 'sensitive' => 'cost'],
                ['key' => 'income', 'label' => 'Income / (loss)', 'type' => 'money', 'sensitive' => 'cost'], ['key' => 'margin', 'label' => 'Margin %', 'type' => 'pct', 'sensitive' => 'cost']],
            'rows' => $rows, 'totals' => $tot,
            'notes' => 'Income is the sales of the items sold to each customer, net of VAT and discounts, less what those items cost (their average cost when sold). '
                . 'Credit memos and POS returns count against the customer. A negative figure is a loss. Click a customer for the detail.'
                . (Access::viewCosts() ? '' : ' Cost and income are shown only to users who may view costs.'),
        ];
    },
]);

Report::define('income_customer_detail', [
    'filters' => ['range', 'customer', 'customer_type', 'rep', 'class', 'site', 'item', 'category', 'item_type', 'pref_vendor'],
    'default_dates' => 'this_month',
    'sortable' => false,
    'run' => function (array $f): array {
        $lines = income_lines($f);
        usort($lines, fn($a, $b) => [mb_strtolower($a['customer_label']), $a['txn_date'], $a['txn_id']] <=> [mb_strtolower($b['customer_label']), $b['txn_date'], $b['txn_id']]);
        $rows = [];
        $tot = ['type' => 'TOTAL', 'qty' => 0.0, 'sales' => 0.0, 'cost' => 0.0];
        $cur = null;
        $sub = null;
        $close = function () use (&$rows, &$sub, &$cur) {
            if ($cur === null) {
                return;
            }
            $income = r2($sub['sales'] - $sub['cost']);
            $rows[] = ['type' => 'Total ' . $cur, 'qty' => $sub['qty'], 'sales' => r2($sub['sales']), 'cost' => r2($sub['cost']), 'income' => $income,
                'margin' => income_margin($sub['sales'], $income), '_class' => 'subtotal-row'];
        };
        foreach ($lines as $l) {
            if ($l['customer_label'] !== $cur) {
                $close();
                $cur = $l['customer_label'];
                $sub = ['qty' => 0.0, 'sales' => 0.0, 'cost' => 0.0];
                $rows[] = ['type' => $cur, '_class' => 'group-row', '_ref' => $l['customer_key'] ? ['customer' => $l['customer_key']] : []];
            }
            $income = r2($l['s_income']);
            $rows[] = [
                'type' => Txn::label($l['txn_type']), 'date' => $l['txn_date'], 'num' => $l['receipt_no'] ?: $l['txn_no'], 'item' => $l['item_label'],
                'qty' => $l['s_qty'], 'price' => (float)$l['rate'], 'sales' => r2($l['s_amount']), 'cost' => r2($l['s_cost']), 'income' => $income,
                'margin' => income_margin($l['s_amount'], $income), '_class' => 'indent-1' . ($income < -0.004 ? ' income-loss' : ''),
                '_links' => ['num' => Report::txnLink($l), 'item' => $l['item_id'] ? Report::link('inv.center', ['id' => $l['item_id']]) : null],
                '_ref' => array_filter(['txn' => (int)$l['txn_id'], 'customer' => (int)$l['customer_key'], 'item' => (int)$l['item_id']]),
            ];
            foreach (['qty' => 's_qty', 'sales' => 's_amount', 'cost' => 's_cost'] as $k => $src) {
                $sub[$k] += $l[$src];
                $tot[$k] += $l[$src];
            }
        }
        $close();
        $tot['income'] = r2($tot['sales'] - $tot['cost']);
        $tot['margin'] = income_margin($tot['sales'], $tot['income']);
        $tot['sales'] = r2($tot['sales']);
        $tot['cost'] = r2($tot['cost']);
        return [
            'grouped' => true,
            'columns' => [['key' => 'type', 'label' => 'Type'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'num', 'label' => 'Num'],
                ['key' => 'item', 'label' => 'Item'], ['key' => 'qty', 'label' => 'Qty', 'type' => 'qty'], ['key' => 'price', 'label' => 'Sales price', 'type' => 'money'],
                ['key' => 'sales', 'label' => 'Sales', 'type' => 'money'], ['key' => 'cost', 'label' => 'Cost of sales', 'type' => 'money', 'sensitive' => 'cost'],
                ['key' => 'income', 'label' => 'Income / (loss)', 'type' => 'money', 'sensitive' => 'cost'], ['key' => 'margin', 'label' => 'Margin %', 'type' => 'pct', 'sensitive' => 'cost']],
            'rows' => $rows, 'totals' => $tot,
            'notes' => 'Every item sold to each customer in the period with what it earned: the sale (net of VAT and discounts) less the cost of the item when it was sold. '
                . 'Returns and credit memos are shown as negatives. A negative income is a loss on that line.'
                . (Access::viewCosts() ? '' : ' Cost and income are shown only to users who may view costs.'),
        ];
    },
]);

// ---------------------------------------------------------------------- expenses by vendor

/**
 * The ledger lines of the period that went to an expense account (expenses, cost of goods sold, other expenses)
 * and name a supplier, on the line or on its transaction. Supplier debit memos reduce them.
 */
function expense_lines(array $f): array
{
    $sql = "SELECT jl.id, jl.txn_id, jl.txn_date, jl.account_id, jl.debit - jl.credit AS amount, jl.memo AS line_memo, jl.class_id,
                   COALESCE(jl.vendor_id, t.vendor_id) AS vendor_id, v.name AS vendor, t.txn_type, t.txn_no, t.ref_no, t.memo,
                   a.code AS acct_code, a.name AS acct_name
              FROM journal_lines jl
              JOIN transactions t ON t.id = jl.txn_id
              JOIN accounts a ON a.id = jl.account_id AND a.category = 'Expense'
              JOIN vendors v ON v.id = COALESCE(jl.vendor_id, t.vendor_id)
             WHERE jl.txn_date BETWEEN :f AND :t";
    $p = ['f' => $f['from'], 't' => $f['to']];
    if (!empty($f['vendor_id'])) {
        $sql .= ' AND COALESCE(jl.vendor_id, t.vendor_id) = :v';
        $p['v'] = $f['vendor_id'];
    }
    if (!empty($f['account_id'])) {
        $sql .= ' AND jl.account_id = :a';
        $p['a'] = $f['account_id'];
    }
    if (!empty($f['class_id']) && Classes::ready()) {
        [$csql, $cp] = Classes::sqlFilter((int)$f['class_id'], 'jl', 'eclass');
        $sql .= $csql;
        $p += $cp;
    }
    return DB::all($sql . ' ORDER BY v.name, jl.txn_date, jl.txn_id, jl.id', $p);
}

/** The expense accounts, for the Account filter */
function expense_accounts(): array
{
    return DB::pairs("SELECT id, CONCAT(code, ' - ', name) FROM accounts WHERE category = 'Expense' AND is_active = 1 ORDER BY code");
}

Report::define('expenses_vendor_summary', [
    'filters' => ['range', 'columns', 'vendor', 'class'],
    'extra_filters' => ['expense_account' => ['Expense account', fn() => expense_accounts()]],
    'default_dates' => 'this_month',
    'run' => function (array $f): array {
        $mode = $f['columns'] ?: 'total';
        $f['account_id'] = (int)($f['expense_account'] ?? 0) ?: null;
        $lines = expense_lines($f);
        // the columns start with the first month that has an expense ("All dates" begins in 2000)
        $first = $lines ? min(array_column($lines, 'txn_date')) : $f['from'];
        $last = $lines ? max(array_column($lines, 'txn_date')) : $f['to'];
        $periods = Report::periods(max($f['from'], $first), min($f['to'], $last), $mode);
        $by = [];
        foreach ($lines as $l) {
            $k = (int)$l['vendor_id'];
            $b = &$by[$k];
            $b['name'] = $l['vendor'];
            $b['txns'][$l['txn_id']] = true;
            $b['total'] = ($b['total'] ?? 0) + (float)$l['amount'];
            if ($periods) {
                $pk = Report::periodKey($l['txn_date'], $mode);
                $b['p_' . $pk] = ($b['p_' . $pk] ?? 0) + (float)$l['amount'];
            }
            unset($b);
        }
        $grand = array_sum(array_column($by, 'total'));
        $cols = [['key' => 'name', 'label' => 'Vendor'], ['key' => 'count', 'label' => 'Transactions', 'type' => 'int']];
        foreach ($periods as $k => $label) {
            $cols[] = ['key' => 'p_' . $k, 'label' => $label, 'type' => 'money'];
        }
        $cols[] = ['key' => 'total', 'label' => 'Total expenses', 'type' => 'money'];
        $cols[] = ['key' => 'share', 'label' => '% of total', 'type' => 'pct'];
        $rows = [];
        $tot = ['name' => 'TOTAL', 'count' => 0, 'total' => r2($grand), 'share' => $grand ? 100 : ''];
        foreach ($by as $k => $b) {
            $r = ['name' => $b['name'], 'count' => count($b['txns']), 'total' => r2($b['total']), 'share' => abs($grand) > 0.004 ? round($b['total'] / $grand * 100, 1) : '',
                '_links' => ['name' => Report::link('rpt.expenses_vendor_detail', ['vendor_id' => $k, 'dates' => 'custom', 'from' => $f['from'], 'to' => $f['to'], 'class_id' => $f['class_id']])],
                '_ref' => ['vendor' => $k]];
            foreach (array_keys($periods) as $pk) {
                $r['p_' . $pk] = r2($b['p_' . $pk] ?? 0);
                $tot['p_' . $pk] = r2(($tot['p_' . $pk] ?? 0) + $r['p_' . $pk]);
            }
            $tot['count'] += $r['count'];
            $rows[] = $r;
        }
        usort($rows, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return [
            'columns' => $cols, 'rows' => $rows, 'totals' => $tot,
            'notes' => 'What was charged to the expense accounts (expenses, cost of goods sold and other expenses) by bills, checks, cash vouchers, '
                . 'card charges and journal entries that name the supplier, less supplier debit memos. Purchases of inventory items go to the inventory '
                . 'account until they are sold, so they are not expenses here. Click a vendor for the detail.',
        ];
    },
]);

Report::define('expenses_vendor_detail', [
    'filters' => ['range', 'vendor', 'class'],
    'extra_filters' => ['expense_account' => ['Expense account', fn() => expense_accounts()]],
    'default_dates' => 'this_month',
    'sortable' => false,
    'run' => function (array $f): array {
        $f['account_id'] = (int)($f['expense_account'] ?? 0) ?: null;
        $rows = [];
        $grand = 0.0;
        $cur = null;
        $sub = 0.0;
        foreach (expense_lines($f) as $l) {
            if ($l['vendor_id'] !== $cur) {
                if ($cur !== null) {
                    $rows[] = ['type' => 'Total ' . $curName, 'amount' => r2($sub), '_class' => 'subtotal-row'];
                }
                $cur = $l['vendor_id'];
                $curName = $l['vendor'];
                $sub = 0.0;
                $rows[] = ['type' => $l['vendor'], '_class' => 'group-row', '_ref' => ['vendor' => (int)$l['vendor_id']]];
            }
            $amt = (float)$l['amount'];
            $sub += $amt;
            $grand += $amt;
            $rows[] = [
                'type' => Txn::label($l['txn_type']), 'date' => $l['txn_date'], 'num' => $l['ref_no'] ?: $l['txn_no'],
                'memo' => $l['line_memo'] ?: $l['memo'], 'account' => $l['acct_code'] . ' - ' . $l['acct_name'],
                'class' => $l['class_id'] ? Classes::name((int)$l['class_id']) : '', 'amount' => r2($amt), 'balance' => r2($sub),
                '_class' => 'indent-1',
                '_links' => ['num' => Report::txnLink(['txn_type' => $l['txn_type'], 'txn_id' => $l['txn_id']]),
                    'account' => Report::link('rpt.general_ledger', ['account_id' => $l['account_id'], 'dates' => 'custom', 'from' => $f['from'], 'to' => $f['to']])],
                '_ref' => ['txn' => (int)$l['txn_id'], 'vendor' => (int)$l['vendor_id'], 'account' => (int)$l['account_id']],
            ];
        }
        if ($cur !== null) {
            $rows[] = ['type' => 'Total ' . $curName, 'amount' => r2($sub), '_class' => 'subtotal-row'];
        }
        $cols = [['key' => 'type', 'label' => 'Type'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'num', 'label' => 'Num'],
            ['key' => 'memo', 'label' => 'Memo'], ['key' => 'account', 'label' => 'Account']];
        if (Classes::ready()) {
            $cols[] = ['key' => 'class', 'label' => 'Class'];
        }
        $cols[] = ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'];
        $cols[] = ['key' => 'balance', 'label' => 'Balance', 'type' => 'money'];
        return [
            'grouped' => true, 'columns' => $cols, 'rows' => $rows, 'totals' => ['type' => 'TOTAL', 'amount' => r2($grand)],
            'notes' => 'Each expense charged to a supplier in the period, with the account it went to; the balance runs within each supplier. '
                . 'Supplier debit memos are negatives. Purchases of inventory items are not expenses until they are sold, so they are not listed.',
        ];
    },
]);
