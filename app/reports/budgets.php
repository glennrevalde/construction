<?php
declare(strict_types=1);

/**
 * Reports added for the budgets, the forecasts and the date-range statements:
 *   trial_balance_range   trial balance of the movement inside a date range
 *   trial_balance_totals  the same range, showing the total debits and the total credits of every account
 *   balance_sheet_range   balance sheet built from the transactions inside a date range only
 *   budget_income         income statement beside its budget, with the difference
 *   budget_balance        balance sheet beside its budget, with the difference
 *   forecast_vs_actual    sales forecast per customer beside what was invoiced
 */

/** The budgets or forecasts of one kind, for the report's own drop-down */
function budget_rpt_options(string $kind): array
{
    return Budgets::options($kind);
}

/** The budget chosen on a report, or the newest one of that kind */
function budget_rpt_pick(array $f, string $kind): ?array
{
    $id = (int)($f['budget'] ?? 0);
    if ($id && ($b = Budgets::load($id)) && $b['kind'] === $kind) {
        return $b;
    }
    $all = Budgets::all($kind);
    return $all ? Budgets::load((int)$all[0]['id']) : null;
}

/** Difference and % columns, the way a budget report shows them */
function budget_rpt_diff(float $actual, float $budget): array
{
    $diff = r2($actual - $budget);
    return [$diff, abs($budget) > 0.004 ? round($actual / $budget * 100, 1) : null];
}

// ---------------------------------------------------------------------------- trial balance by date range

Report::define('trial_balance_range', [
    'filters' => ['range', 'class'],
    'default_dates' => 'this_month',
    'sortable' => false,
    'run' => function (array $f): array {
        $cls = $f['class_id'] ?: null;
        [$csql, $cp] = Classes::sqlFilter($cls, 'j');
        $rows = [];
        $td = 0.0;
        $tc = 0.0;
        $moves = DB::all(
            "SELECT j.account_id, SUM(j.debit) AS dr, SUM(j.credit) AS cr FROM journal_lines j
              WHERE j.txn_date BETWEEN :f AND :t" . $csql . ' GROUP BY j.account_id',
            ['f' => $f['from'], 't' => $f['to']] + $cp
        );
        $by = [];
        foreach ($moves as $m) {
            $by[(int)$m['account_id']] = ['dr' => (float)$m['dr'], 'cr' => (float)$m['cr']];
        }
        foreach (fin_accounts() as $a) {
            $m = $by[(int)$a['id']] ?? null;
            if (!$m) {
                continue;
            }
            $net = r2($m['dr'] - $m['cr']);
            if (abs($net) < 0.005) {
                continue;
            }
            $rows[] = [
                'account' => $a['code'] . ' - ' . $a['name'], 'type' => $a['account_type'],
                'debit' => $net > 0 ? $net : '', 'credit' => $net < 0 ? r2(-$net) : '',
                '_links' => ['account' => Report::link('rpt.general_ledger', ['account_id' => $a['id'], 'from' => $f['from'], 'to' => $f['to'], 'dates' => 'custom', 'class_id' => $cls])],
            ];
            $td += max(0, $net);
            $tc += max(0, -$net);
        }
        return [
            'notes' => fin_class_note($cls) . 'Only the transactions dated in this range are included, so the figures are the movement of the period, not the balance carried from before it.',
            'columns' => [['key' => 'account', 'label' => 'Account'], ['key' => 'type', 'label' => 'Type'],
                ['key' => 'debit', 'label' => 'Debit', 'type' => 'money'], ['key' => 'credit', 'label' => 'Credit', 'type' => 'money']],
            'rows' => $rows, 'totals' => ['account' => 'TOTAL', 'debit' => r2($td), 'credit' => r2($tc)],
        ];
    },
]);

// ---------------------------------------------------------------------------- trial balance of totals

Report::define('trial_balance_totals', [
    'filters' => ['range', 'class'],
    'default_dates' => 'this_month',
    'sortable' => false,
    'run' => function (array $f): array {
        $cls = $f['class_id'] ?: null;
        [$csql, $cp] = Classes::sqlFilter($cls, 'j');
        $rows = [];
        $td = 0.0;
        $tc = 0.0;
        $by = [];
        foreach (DB::all("SELECT j.account_id, SUM(j.debit) AS dr, SUM(j.credit) AS cr FROM journal_lines j
                           WHERE j.txn_date BETWEEN :f AND :t" . $csql . ' GROUP BY j.account_id',
                 ['f' => $f['from'], 't' => $f['to']] + $cp) as $m) {
            $by[(int)$m['account_id']] = ['dr' => (float)$m['dr'], 'cr' => (float)$m['cr']];
        }
        foreach (fin_accounts() as $a) {
            $m = $by[(int)$a['id']] ?? null;
            if (!$m || (abs($m['dr']) < 0.005 && abs($m['cr']) < 0.005)) {
                continue;
            }
            $rows[] = [
                'account' => $a['code'] . ' - ' . $a['name'], 'type' => $a['account_type'],
                'debit' => r2($m['dr']), 'credit' => r2($m['cr']), 'net' => r2($m['dr'] - $m['cr']),
                '_links' => ['account' => Report::link('rpt.general_ledger', ['account_id' => $a['id'], 'from' => $f['from'], 'to' => $f['to'], 'dates' => 'custom', 'class_id' => $cls])],
            ];
            $td += $m['dr'];
            $tc += $m['cr'];
        }
        return [
            'notes' => fin_class_note($cls) . 'The total debits and the total credits posted to each account inside the range, not the balance: an account with 1,000,000.00 of debits and 665,000.00 of credits shows both figures.',
            'columns' => [['key' => 'account', 'label' => 'Account'], ['key' => 'type', 'label' => 'Type'],
                ['key' => 'debit', 'label' => 'Total debit', 'type' => 'money'], ['key' => 'credit', 'label' => 'Total credit', 'type' => 'money'],
                ['key' => 'net', 'label' => 'Net movement', 'type' => 'money']],
            'rows' => $rows, 'totals' => ['account' => 'TOTAL', 'debit' => r2($td), 'credit' => r2($tc), 'net' => r2($td - $tc)],
        ];
    },
]);

// ---------------------------------------------------------------------------- balance sheet by date range

Report::define('balance_sheet_range', [
    'filters' => ['range', 'class'],
    'default_dates' => 'this_month',
    'sortable' => false,
    'run' => function (array $f): array {
        $cls = $f['class_id'] ?: null;
        [$csql, $cp] = Classes::sqlFilter($cls, 'j');
        $moves = array_map('floatval', DB::pairs(
            "SELECT j.account_id, SUM(j.debit - j.credit) FROM journal_lines j
              WHERE j.txn_date BETWEEN :f AND :t" . $csql . ' GROUP BY j.account_id',
            ['f' => $f['from'], 't' => $f['to']] + $cp
        ));
        // the profit made inside the range is the movement of the equity, exactly as it is on a full balance sheet
        $ni = 0.0;
        foreach (fin_accounts() as $a) {
            if (in_array($a['category'], ['Income', 'Expense'], true)) {
                $ni -= $moves[(int)$a['id']] ?? 0.0;
            }
        }
        $sections = [
            'Assets' => ['Bank', 'Accounts Receivable', 'Other Current Asset', 'Fixed Asset', 'Other Asset'],
            'Liabilities' => ['Accounts Payable', 'Credit Card', 'Other Current Liability', 'Long Term Liability'],
            'Equity' => ['Equity'],
        ];
        $rows = [];
        $totals = [];
        foreach ($sections as $section => $types) {
            $rows[] = ['account' => strtoupper($section), '_class' => 'group-row'];
            $sum = 0.0;
            foreach (fin_accounts() as $a) {
                if (!in_array($a['account_type'], $types, true)) {
                    continue;
                }
                $net = $moves[(int)$a['id']] ?? 0.0;
                if (abs($net) < 0.005) {
                    continue;
                }
                $shown = $section === 'Assets' ? $net : -$net;
                $sum += $shown;
                $rows[] = ['account' => $a['code'] . ' - ' . $a['name'], 'change' => r2($shown), '_class' => 'indent-1',
                    '_links' => ['account' => Report::link('rpt.general_ledger', ['account_id' => $a['id'], 'from' => $f['from'], 'to' => $f['to'], 'dates' => 'custom', 'class_id' => $cls])]];
            }
            if ($section === 'Equity' && abs($ni) >= 0.005) {
                $rows[] = ['account' => 'Net income of the period', 'change' => r2($ni), '_class' => 'indent-1'];
                $sum += $ni;
            }
            $totals[$section] = r2($sum);
            $rows[] = ['account' => 'Total ' . strtolower($section), 'change' => r2($sum), '_class' => 'subtotal-row'];
        }
        $rows[] = ['account' => 'LIABILITIES AND EQUITY', 'change' => r2($totals['Liabilities'] + $totals['Equity']), '_class' => 'total-row'];
        return [
            'grouped' => true,
            'notes' => fin_class_note($cls) . 'Only the transactions dated in this range are included: every figure is the change during the period, not the balance at the end of it. '
                . 'Assets are shown as debit-positive, liabilities and equity as credit-positive, so the two sides agree.',
            'columns' => [['key' => 'account', 'label' => 'Account'], ['key' => 'change', 'label' => 'Change in the period', 'type' => 'money']],
            'rows' => $rows,
        ];
    },
]);

// ---------------------------------------------------------------------------- income statement vs budget

Report::define('budget_income', [
    'filters' => ['class'],
    'extra_filters' => [
        'budget' => ['Budget', fn() => budget_rpt_options('pl'), null],
        'period' => ['Show', fn() => ['ytd' => 'Year to date', 'year' => 'The whole fiscal year', 'month' => 'One month at a time'], null],
        'month'  => ['Month', fn() => [1 => 'Month 1', 2 => 'Month 2', 3 => 'Month 3', 4 => 'Month 4', 5 => 'Month 5', 6 => 'Month 6',
            7 => 'Month 7', 8 => 'Month 8', 9 => 'Month 9', 10 => 'Month 10', 11 => 'Month 11', 12 => 'Month 12'], null],
    ],
    'sortable' => false,
    'run' => function (array $f): array {
        $b = budget_rpt_pick($f, 'pl');
        if (!$b) {
            return ['columns' => [['key' => 'account', 'label' => 'Account']], 'rows' => [],
                'notes' => 'No budget has been set up yet. Financial Set-up &amp; Reports &gt; Set Up Budgets makes one.'];
        }
        return budget_rpt_build($b, $f, [
            'Income' => ['Income'], 'Cost of Goods Sold' => ['Cost of Goods Sold'],
            'Expenses' => ['Expense'], 'Other income' => ['Other Income'], 'Other expenses' => ['Other Expense'],
        ], true);
    },
]);

// ---------------------------------------------------------------------------- balance sheet vs budget

Report::define('budget_balance', [
    'filters' => ['class'],
    'extra_filters' => [
        'budget' => ['Budget', fn() => budget_rpt_options('bs'), null],
        'period' => ['Show', fn() => ['ytd' => 'As at the latest month', 'year' => 'The whole fiscal year', 'month' => 'One month at a time'], null],
        'month'  => ['Month', fn() => array_combine(range(1, 12), array_map(fn($i) => 'Month ' . $i, range(1, 12))), null],
    ],
    'sortable' => false,
    'run' => function (array $f): array {
        $b = budget_rpt_pick($f, 'bs');
        if (!$b) {
            return ['columns' => [['key' => 'account', 'label' => 'Account']], 'rows' => [],
                'notes' => 'No balance sheet budget has been set up yet. Financial Set-up &amp; Reports &gt; Set Up Budgets makes one.'];
        }
        return budget_rpt_build($b, $f, [
            'Assets' => ['Bank', 'Accounts Receivable', 'Other Current Asset', 'Fixed Asset', 'Other Asset'],
            'Liabilities' => ['Accounts Payable', 'Credit Card', 'Other Current Liability', 'Long Term Liability'],
            'Equity' => ['Equity'],
        ], false);
    },
]);

/**
 * Builds a budget report: one row per account with the actual figure, the budget and the difference.
 * $sections: heading => account types. $isPl: totals are summed as income less cost (a balance sheet just totals).
 */
function budget_rpt_build(array $b, array $f, array $sections, bool $isPl): array
{
    $periods = Budgets::periods($b);
    $mode = (string)($f['period'] ?? '') ?: 'ytd';
    $month = max(1, min(12, (int)($f['month'] ?? 0) ?: 0));
    $budget = Budgets::lines((int)$b['id']);
    $actual = Budgets::actual($b);
    $today = today();
    // year to date: up to the month the fiscal year has reached today
    $upto = 12;
    if ($mode === 'ytd') {
        $upto = 0;
        foreach ($periods as $p) {
            if ($p['from'] <= $today) {
                $upto = max($upto, (int)$p['key']);
            }
        }
        $upto = $upto ?: 12;
    }
    $months = $mode === 'month' && $month ? [$month] : range(1, $mode === 'ytd' ? $upto : 12);
    $sum = function (array $src, int $accId) use ($months, $b): float {
        $t = 0.0;
        foreach ($months as $m) {
            if ($b['kind'] === 'bs') {
                $t = (float)($src[$accId][0][$m] ?? $t);   // a balance: the figure of the last month shown
            } else {
                $t += (float)($src[$accId][0][$m] ?? 0);
            }
        }
        return r2($t);
    };
    $rows = [];
    $tot = ['actual' => 0.0, 'budget' => 0.0];
    $sectionTotals = [];
    foreach ($sections as $heading => $types) {
        $accounts = array_values(array_filter(fin_accounts(), fn($a) => in_array($a['account_type'], $types, true)));
        $secA = 0.0;
        $secB = 0.0;
        $body = [];
        foreach ($accounts as $a) {
            $av = $sum($actual, (int)$a['id']);
            $bv = $sum($budget, (int)$a['id']);
            if (abs($av) < 0.005 && abs($bv) < 0.005) {
                continue;
            }
            [$diff, $pct] = budget_rpt_diff($av, $bv);
            $body[] = ['account' => $a['code'] . ' - ' . $a['name'], 'actual' => $av, 'budget' => $bv, 'diff' => $diff, 'pct' => $pct, '_class' => 'indent-1',
                '_links' => ['account' => Report::link('rpt.general_ledger', ['account_id' => $a['id'], 'from' => $periods[$months[0] - 1]['from'], 'to' => $periods[end($months) - 1]['to'], 'dates' => 'custom', 'class_id' => $b['class_id']])]];
            $secA += $av;
            $secB += $bv;
        }
        if (!$body) {
            continue;
        }
        $rows[] = ['account' => strtoupper($heading), '_class' => 'group-row'];
        $rows = array_merge($rows, $body);
        [$sdiff, $spct] = budget_rpt_diff(r2($secA), r2($secB));
        $rows[] = ['account' => 'Total ' . strtolower($heading), 'actual' => r2($secA), 'budget' => r2($secB), 'diff' => $sdiff, 'pct' => $spct, '_class' => 'subtotal-row'];
        $sectionTotals[$heading] = [r2($secA), r2($secB)];
        $tot['actual'] += $secA;
        $tot['budget'] += $secB;
    }
    if ($isPl) {
        // income and other income add to net income; cost of sales, expenses and other expenses (all read as positive figures) reduce it
        $income = ($sectionTotals['Income'][0] ?? 0) + ($sectionTotals['Other income'][0] ?? 0);
        $ib = ($sectionTotals['Income'][1] ?? 0) + ($sectionTotals['Other income'][1] ?? 0);
        $cost = ($sectionTotals['Cost of Goods Sold'][0] ?? 0) + ($sectionTotals['Expenses'][0] ?? 0) + ($sectionTotals['Other expenses'][0] ?? 0);
        $cb = ($sectionTotals['Cost of Goods Sold'][1] ?? 0) + ($sectionTotals['Expenses'][1] ?? 0) + ($sectionTotals['Other expenses'][1] ?? 0);
        [$d, $p] = budget_rpt_diff(r2($income - $cost), r2($ib - $cb));
        $rows[] = ['account' => 'NET INCOME', 'actual' => r2($income - $cost), 'budget' => r2($ib - $cb), 'diff' => $d, 'pct' => $p, '_class' => 'total-row'];
    }
    $range = $periods[$months[0] - 1]['label'] . ' - ' . $periods[end($months) - 1]['label'];
    return [
        'grouped' => true,
        'notes' => 'Budget: <strong>' . h($b['name']) . '</strong> (' . h(Budgets::yearLabel($b)) . '), ' . h($range)
            . ($b['class_id'] ? ', class ' . h(Classes::name($b['class_id'])) : '')
            . '. "Difference" is the actual figure less the budget; "% of budget" is what was reached.',
        'columns' => [['key' => 'account', 'label' => 'Account'], ['key' => 'actual', 'label' => 'Actual', 'type' => 'money'],
            ['key' => 'budget', 'label' => 'Budget', 'type' => 'money'], ['key' => 'diff', 'label' => 'Difference', 'type' => 'money'],
            ['key' => 'pct', 'label' => '% of budget', 'type' => 'qty']],
        'rows' => $rows,
    ];
}

// ---------------------------------------------------------------------------- forecast vs actual

Report::define('forecast_vs_actual', [
    'filters' => ['customer'],
    'extra_filters' => [
        'budget' => ['Forecast', fn() => budget_rpt_options('forecast'), null],
        'period' => ['Show', fn() => ['ytd' => 'Year to date', 'year' => 'The whole fiscal year', 'month' => 'One month at a time'], null],
        'month'  => ['Month', fn() => array_combine(range(1, 12), array_map(fn($i) => 'Month ' . $i, range(1, 12))), null],
    ],
    'sortable' => false,
    'run' => function (array $f): array {
        $b = budget_rpt_pick($f, 'forecast');
        if (!$b) {
            return ['columns' => [['key' => 'customer', 'label' => 'Customer']], 'rows' => [],
                'notes' => 'No forecast has been set up yet. Customers &gt; Set Up Forecast makes one.'];
        }
        $periods = Budgets::periods($b);
        $mode = (string)($f['period'] ?? '') ?: 'ytd';
        $month = max(1, min(12, (int)($f['month'] ?? 0) ?: 0));
        $today = today();
        $upto = 12;
        if ($mode === 'ytd') {
            $upto = 0;
            foreach ($periods as $p) {
                if ($p['from'] <= $today) {
                    $upto = max($upto, (int)$p['key']);
                }
            }
            $upto = $upto ?: 12;
        }
        $months = $mode === 'month' && $month ? [$month] : range(1, $mode === 'ytd' ? $upto : 12);
        $forecast = Budgets::lines((int)$b['id']);
        // the actual figure is the customer's sales: only the income accounts a forecast is made of (Income and
        // Other Income, net of returns and discounts booked to them), never the receivable, tax, cost or cash lines
        $incomeAccounts = array_flip(array_map('intval', DB::column("SELECT id FROM accounts WHERE account_type IN ('Income', 'Other Income')")));
        $actual = array_intersect_key(Budgets::actual($b), $incomeAccounts);
        $names = Lookup::customers();
        $pick = (int)($f['customer_id'] ?? 0);
        $byCustomer = [];
        foreach ([$forecast, $actual] as $which => $src) {
            foreach ($src as $acc => $byCust) {
                foreach ($byCust as $cust => $byMonth) {
                    foreach ($byMonth as $m => $amt) {
                        if (!in_array((int)$m, $months, true)) {
                            continue;
                        }
                        $byCustomer[(int)$cust][$which === 0 ? 'budget' : 'actual'] = r2(($byCustomer[(int)$cust][$which === 0 ? 'budget' : 'actual'] ?? 0) + (float)$amt);
                    }
                }
            }
        }
        $rows = [];
        $tf = 0.0;
        $ta = 0.0;
        ksort($byCustomer);
        foreach ($byCustomer as $cust => $v) {
            if ($pick && $cust !== $pick) {
                continue;
            }
            if ($b['customer_id'] && (int)$b['customer_id'] !== $cust && $cust !== 0) {
                continue;
            }
            $fc = (float)($v['budget'] ?? 0);
            $ac = (float)($v['actual'] ?? 0);
            if (abs($fc) < 0.005 && abs($ac) < 0.005) {
                continue;
            }
            [$diff, $pct] = budget_rpt_diff($ac, $fc);
            $rows[] = ['customer' => $cust ? ($names[$cust] ?? ('Customer #' . $cust)) : 'Sales without a customer (counter sales)',
                'forecast' => $fc, 'actual' => $ac, 'diff' => $diff, 'pct' => $pct,
                '_links' => $cust ? ['customer' => Report::link('rpt.sales_customer_detail', ['customer_id' => $cust, 'dates' => 'custom',
                    'from' => $periods[$months[0] - 1]['from'], 'to' => $periods[end($months) - 1]['to']])] : []];
            $tf += $fc;
            $ta += $ac;
        }
        $range = $periods[$months[0] - 1]['label'] . ' - ' . $periods[end($months) - 1]['label'];
        return [
            'notes' => 'Forecast: <strong>' . h($b['name']) . '</strong> (' . h(Budgets::yearLabel($b)) . '), ' . h($range)
                . ($b['customer_id'] ? ', ' . h((string)($names[(int)$b['customer_id']] ?? '')) : ', all customers')
                . '. The actual figures are the income posted for each customer in the same months.',
            'columns' => [['key' => 'customer', 'label' => 'Customer'], ['key' => 'forecast', 'label' => 'Forecast', 'type' => 'money'],
                ['key' => 'actual', 'label' => 'Actual', 'type' => 'money'], ['key' => 'diff', 'label' => 'Difference', 'type' => 'money'],
                ['key' => 'pct', 'label' => '% of forecast', 'type' => 'qty']],
            'rows' => $rows,
            'totals' => ['customer' => 'TOTAL', 'forecast' => r2($tf), 'actual' => r2($ta), 'diff' => r2($ta - $tf),
                'pct' => abs($tf) > 0.004 ? round($ta / $tf * 100, 1) : null],
        ];
    },
]);
