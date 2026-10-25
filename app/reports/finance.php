<?php
declare(strict_types=1);

/** Financial statements, general ledger and journal. */

/** account_id => net (debit - credit) for a date window, optionally for one class */
function fin_balances(?string $from, string $to, ?int $classId = null): array
{
    $sql = 'SELECT account_id, SUM(debit - credit) AS net FROM journal_lines WHERE txn_date <= :t';
    $p = ['t' => $to];
    if ($from) {
        $sql .= ' AND txn_date >= :f';
        $p['f'] = $from;
    }
    [$csql, $cp] = Classes::sqlFilter($classId, '');
    return array_map('floatval', DB::pairs($sql . $csql . ' GROUP BY account_id', $p + $cp));
}

/** Note shown on a report that is filtered by class */
function fin_class_note(?int $classId): string
{
    return $classId ? 'Class: <strong>' . h(Classes::name($classId)) . '</strong> only. ' : '';
}

/**
 * Column buckets of the "by Class" statements: every class with activity, then Unclassified.
 * Returns [bucket key => label]; bucket key is "c<id>" or "c0" for entries without a class.
 */
function fin_class_buckets(array $usedKeys): array
{
    $out = [];
    foreach (Classes::all() as $id => $c) {
        if (isset($usedKeys['c' . $id]) || (int)$c['is_active'] === 1) {
            $out['c' . $id] = $c['name'];
        }
    }
    if (isset($usedKeys['c0']) || !$out) {
        $out['c0'] = 'Unclassified';
    }
    return $out;
}

function fin_accounts(): array
{
    static $a = null;
    return $a ??= DB::all('SELECT * FROM accounts ORDER BY code');
}

/** Balance-sheet style net income before a date (all prior P&L activity) */
function fin_net_income(?string $from, string $to, ?int $classId = null): float
{
    $sql = "SELECT COALESCE(SUM(j.credit - j.debit),0) FROM journal_lines j JOIN accounts a ON a.id = j.account_id
             WHERE a.category IN ('Income','Expense') AND j.txn_date <= :t";
    $p = ['t' => $to];
    if ($from) {
        $sql .= ' AND j.txn_date >= :f';
        $p['f'] = $from;
    }
    [$csql, $cp] = Classes::sqlFilter($classId, 'j');
    return r2(DB::value($sql . $csql, $p + $cp));
}

/** Net income (credit - debit) per class bucket for a date window: ['c3' => 1200.00, 'c0' => 50.00] */
function fin_net_income_by_class(?string $from, string $to): array
{
    $sql = "SELECT COALESCE(j.class_id, 0) AS cls, SUM(j.credit - j.debit) AS net FROM journal_lines j JOIN accounts a ON a.id = j.account_id
             WHERE a.category IN ('Income','Expense') AND j.txn_date <= :t";
    $p = ['t' => $to];
    if ($from) {
        $sql .= ' AND j.txn_date >= :f';
        $p['f'] = $from;
    }
    $out = [];
    foreach (DB::all($sql . ' GROUP BY COALESCE(j.class_id, 0)', $p) as $r) {
        $out['c' . (int)$r['cls']] = (float)$r['net'];
    }
    return $out;
}

Report::define('trial_balance', [
    'filters' => ['asof', 'class'],
    'sortable' => false,
    'run' => function (array $f): array {
        $asof = $f['asof'];
        $cls = $f['class_id'] ?: null;
        $fy = Report::fiscalYearStart($asof);
        $bsBal = fin_balances(null, $asof, $cls);
        $plBal = fin_balances($fy, $asof, $cls);
        $priorNi = fin_net_income(null, date('Y-m-d', strtotime("$fy -1 day")), $cls);
        $rows = [];
        $td = 0.0;
        $tc = 0.0;
        $re = Accts::get('retained_earnings');
        foreach (fin_accounts() as $a) {
            $isPl = in_array($a['category'], ['Income', 'Expense'], true);
            $net = $isPl ? ($plBal[$a['id']] ?? 0.0) : ($bsBal[$a['id']] ?? 0.0);
            if ((int)$a['id'] === $re) {
                $net -= $priorNi;
            }
            if (abs($net) < 0.005) {
                continue;
            }
            $rows[] = [
                'account' => $a['code'] . ' - ' . $a['name'], 'type' => $a['account_type'],
                'debit' => $net > 0 ? r2($net) : '', 'credit' => $net < 0 ? r2(-$net) : '',
                '_links' => ['account' => Report::link('rpt.general_ledger', ['account_id' => $a['id'], 'from' => $isPl ? $fy : '2000-01-01', 'to' => $asof, 'dates' => 'custom', 'class_id' => $cls])],
            ];
            $td += max(0, $net);
            $tc += max(0, -$net);
        }
        return [
            'columns' => [['key' => 'account', 'label' => 'Account'], ['key' => 'type', 'label' => 'Type'], ['key' => 'debit', 'label' => 'Debit', 'type' => 'money'], ['key' => 'credit', 'label' => 'Credit', 'type' => 'money']],
            'rows' => $rows,
            'totals' => ['account' => 'TOTAL', 'debit' => r2($td), 'credit' => r2($tc)],
            'notes' => fin_class_note($cls) . 'Income and expense accounts show fiscal year-to-date activity from ' . fdate($fy) . '; earlier years\' net income is included in Retained Earnings.'
                . (abs(r2($td) - r2($tc)) >= 0.01 ? ' <strong class="text-danger">Out of balance!</strong>' : ''),
        ];
    },
]);

/**
 * Balance sheet rows for one or more amount columns.
 *  $cols  : column keys (['amount'] for the plain statement; class buckets plus 'total' for the by-class one)
 *  $bal   : column key => [account id => net debit balance]
 *  $prior : column key => net income of earlier fiscal years (shown inside Retained Earnings)
 *  $ytd   : column key => net income of the fiscal year to date
 * Returns [rows, section totals per column].
 */
function fin_balance_sheet_rows(array $cols, array $bal, array $prior, array $ytd, string $asof, string $fy, array $linkExtra = []): array
{
    $re = Accts::get('retained_earnings');
    $sections = [
        'ASSETS' => ['Current Assets' => ['Bank', 'Accounts Receivable', 'Other Current Asset'], 'Fixed Assets' => ['Fixed Asset'], 'Other Assets' => ['Other Asset']],
        'LIABILITIES' => ['Current Liabilities' => ['Accounts Payable', 'Credit Card', 'Other Current Liability'], 'Long-term Liabilities' => ['Long Term Liability']],
        'EQUITY' => ['Equity' => ['Equity']],
    ];
    $zero = array_fill_keys($cols, 0.0);
    $withVals = function (array $row, array $vals) use ($cols): array {
        foreach ($cols as $k) {
            $row[$k] = r2($vals[$k] ?? 0);
        }
        return $row;
    };
    $rows = [];
    $totals = [];
    foreach ($sections as $sec => $groups) {
        $rows[] = ['name' => $sec, '_class' => 'group-row'];
        $secTotal = $zero;
        foreach ($groups as $gname => $types) {
            $gTotal = $zero;
            $gRows = [];
            foreach (fin_accounts() as $a) {
                if (!in_array($a['account_type'], $types, true)) {
                    continue;
                }
                $vals = [];
                $any = false;
                foreach ($cols as $k) {
                    $net = $bal[$k][$a['id']] ?? 0.0;
                    if ($sec !== 'ASSETS') {
                        $net = -$net;
                    }
                    if ((int)$a['id'] === $re) {
                        $net += $prior[$k] ?? 0.0;
                    }
                    $vals[$k] = $net;
                    $any = $any || abs($net) >= 0.005;
                }
                if (!$any) {
                    continue;
                }
                $gRows[] = $withVals(['name' => $a['code'] . ' - ' . $a['name'], '_class' => 'indent-2',
                    '_links' => ['name' => Report::link('rpt.general_ledger', ['account_id' => $a['id'], 'from' => '2000-01-01', 'to' => $asof, 'dates' => 'custom'] + $linkExtra)]], $vals);
                foreach ($cols as $k) {
                    $gTotal[$k] += $vals[$k];
                }
            }
            if ($sec === 'EQUITY') {
                $gRows[] = $withVals(['name' => 'Net Income (fiscal year to date)', '_class' => 'indent-2',
                    '_links' => ['name' => Report::link('rpt.income_statement', ['from' => $fy, 'to' => $asof, 'dates' => 'custom'] + $linkExtra)]], $ytd);
                foreach ($cols as $k) {
                    $gTotal[$k] += $ytd[$k] ?? 0.0;
                }
            }
            if (!$gRows) {
                continue;
            }
            $rows[] = ['name' => $gname, '_class' => 'indent-1 fw-semibold'];
            $rows = array_merge($rows, $gRows);
            $rows[] = $withVals(['name' => 'Total ' . $gname, '_class' => 'subtotal-row indent-1'], $gTotal);
            foreach ($cols as $k) {
                $secTotal[$k] += $gTotal[$k];
            }
        }
        $rows[] = $withVals(['name' => 'TOTAL ' . $sec, '_class' => 'total-row'], $secTotal);
        $totals[$sec] = $secTotal;
    }
    $le = [];
    foreach ($cols as $k) {
        $le[$k] = ($totals['LIABILITIES'][$k] ?? 0) + ($totals['EQUITY'][$k] ?? 0);
    }
    $rows[] = $withVals(['name' => 'TOTAL LIABILITIES AND EQUITY', '_class' => 'total-row'], $le);
    $totals['LE'] = $le;
    return [$rows, $totals];
}

Report::define('balance_sheet', [
    'filters' => ['asof', 'class'],
    'sortable' => false,
    'run' => function (array $f): array {
        $asof = $f['asof'];
        $cls = $f['class_id'] ?: null;
        $fy = Report::fiscalYearStart($asof);
        [$rows, $totals] = fin_balance_sheet_rows(
            ['amount'],
            ['amount' => fin_balances(null, $asof, $cls)],
            ['amount' => fin_net_income(null, date('Y-m-d', strtotime("$fy -1 day")), $cls)],
            ['amount' => fin_net_income($fy, $asof, $cls)],
            $asof, $fy, $cls ? ['class_id' => $cls] : []
        );
        $balanced = abs(($totals['ASSETS']['amount'] ?? 0) - ($totals['LE']['amount'] ?? 0)) < 0.01;
        return [
            'grouped' => true,
            'columns' => [['key' => 'name', 'label' => ''], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money']],
            'rows' => $rows,
            'notes' => fin_class_note($cls) . ($balanced ? 'Assets = Liabilities + Equity.'
                : ($cls ? 'Assets and liabilities plus equity differ for this class: some entries carry a different class on each side (journal entry or cash voucher lines with their own class).'
                    : '<strong class="text-danger">Assets do not equal liabilities and equity.</strong>')),
        ];
    },
]);

Report::define('balance_sheet_class', [
    'filters' => ['asof'],
    'sortable' => false,
    'run' => function (array $f): array {
        $asof = $f['asof'];
        $fy = Report::fiscalYearStart($asof);
        $bal = [];
        $used = [];
        foreach (DB::all('SELECT account_id, COALESCE(class_id, 0) AS cls, SUM(debit - credit) AS net FROM journal_lines WHERE txn_date <= :t GROUP BY account_id, COALESCE(class_id, 0)', ['t' => $asof]) as $r) {
            $k = 'c' . (int)$r['cls'];
            $bal[$k][(int)$r['account_id']] = (float)$r['net'];
            $bal['total'][(int)$r['account_id']] = ($bal['total'][(int)$r['account_id']] ?? 0) + (float)$r['net'];
            if (abs((float)$r['net']) >= 0.005) {
                $used[$k] = true;
            }
        }
        $prior = fin_net_income_by_class(null, date('Y-m-d', strtotime("$fy -1 day")));
        $ytd = fin_net_income_by_class($fy, $asof);
        $prior['total'] = array_sum($prior);
        $ytd['total'] = array_sum($ytd);
        $buckets = fin_class_buckets($used + array_fill_keys(array_keys(array_filter($ytd + $prior, fn($v) => abs($v) >= 0.005)), true));
        unset($buckets['total']);
        $cols = array_merge(array_keys($buckets), ['total']);
        [$rows, $totals] = fin_balance_sheet_rows($cols, $bal, $prior, $ytd, $asof, $fy);
        $columns = [['key' => 'name', 'label' => '']];
        foreach ($buckets as $k => $label) {
            $columns[] = ['key' => $k, 'label' => $label, 'type' => 'money'];
        }
        $columns[] = ['key' => 'total', 'label' => 'Total', 'type' => 'money'];
        // per-class drill-down on the account rows
        foreach ($rows as &$r) {
            if (!empty($r['_links']['name'])) {
                foreach ($buckets as $k => $_) {
                    $cid = (int)substr($k, 1) ?: Classes::UNCLASSIFIED;
                    $r['_links'][$k] = $r['_links']['name'] . '&class_id=' . $cid;
                }
            }
        }
        unset($r);
        $off = [];
        foreach ($buckets as $k => $label) {
            if (abs(($totals['ASSETS'][$k] ?? 0) - ($totals['LE'][$k] ?? 0)) >= 0.01) {
                $off[] = $label;
            }
        }
        $note = abs(($totals['ASSETS']['total'] ?? 0) - ($totals['LE']['total'] ?? 0)) >= 0.01
            ? '<strong class="text-danger">Assets do not equal liabilities and equity.</strong>'
            : 'Assets = Liabilities + Equity in total.';
        if ($off) {
            $note .= ' The columns ' . h(implode(', ', $off)) . ' do not balance on their own: some entries carry a different class on each side (journal entry or cash voucher lines with their own class, or payments applied across classes).';
        }
        if (!Classes::inUse()) {
            $note .= ' No classes are set up yet, so everything is unclassified. Add classes under Financial Set-up &amp; Reports, Class List.';
        }
        return ['grouped' => true, 'columns' => $columns, 'rows' => $rows, 'notes' => $note];
    },
]);

/**
 * Income statement rows for any set of amount columns.
 *  $by   : account id => [column key => net (credit - debit)], with '__total' for the total column
 *  $keys : column keys between the name and the total
 *  $link : fn(account row) => url of the drill-down, or null
 */
function fin_income_rows(array $by, array $keys, callable $link): array
{
    $sections = [
        'Income' => [['Income'], 1],
        'Cost of Goods Sold' => [['Cost of Goods Sold'], -1],
        'Expenses' => [['Expense'], -1],
        'Other Income' => [['Other Income'], 1],
        'Other Expenses' => [['Other Expense'], -1],
    ];
    $rows = [];
    $sum = [];
    $makeRow = function (string $name, array $vals, string $class, ?string $url = null) use ($keys) {
        $r = ['name' => $name, '_class' => $class, 'total' => r2($vals['__total'] ?? 0)];
        foreach ($keys as $k) {
            $r['p_' . $k] = r2($vals[$k] ?? 0);
        }
        if ($url) {
            $r['_links'] = ['name' => $url];
        }
        return $r;
    };
    foreach ($sections as $sec => [$types, $sign]) {
        $secVals = [];
        $secRows = [];
        foreach (fin_accounts() as $a) {
            if (!in_array($a['account_type'], $types, true) || empty($by[$a['id']])) {
                continue;
            }
            $vals = array_map(fn($v) => $sign * $v, $by[$a['id']]);
            if (abs($vals['__total']) < 0.005 && count(array_filter($vals, fn($v) => abs($v) >= 0.005)) === 0) {
                continue;
            }
            $secRows[] = $makeRow($a['code'] . ' - ' . $a['name'], $vals, 'indent-1', $link($a));
            foreach ($vals as $k => $v) {
                $secVals[$k] = ($secVals[$k] ?? 0) + $v;
            }
        }
        $sum[$sec] = $secVals;
        if ($secRows) {
            $rows[] = ['name' => $sec, '_class' => 'group-row'];
            $rows = array_merge($rows, $secRows);
            $rows[] = $makeRow('Total ' . $sec, $secVals, 'subtotal-row');
        }
        if ($sec === 'Cost of Goods Sold') {
            $gp = [];
            foreach (array_merge(array_keys($sum['Income'] ?? []), array_keys($secVals)) as $k) {
                $gp[$k] = ($sum['Income'][$k] ?? 0) - ($secVals[$k] ?? 0);
            }
            $rows[] = $makeRow('GROSS PROFIT', $gp, 'total-row');
            $sum['Gross'] = $gp;
        }
        if ($sec === 'Expenses') {
            $op = [];
            foreach (array_merge(array_keys($sum['Gross'] ?? []), array_keys($secVals)) as $k) {
                $op[$k] = ($sum['Gross'][$k] ?? 0) - ($secVals[$k] ?? 0);
            }
            $rows[] = $makeRow('NET OPERATING INCOME', $op, 'total-row');
            $sum['Operating'] = $op;
        }
    }
    $ni = [];
    foreach (array_unique(array_merge(array_keys($sum['Operating'] ?? []), array_keys($sum['Other Income'] ?? []), array_keys($sum['Other Expenses'] ?? []))) as $k) {
        $ni[$k] = ($sum['Operating'][$k] ?? 0) + ($sum['Other Income'][$k] ?? 0) - ($sum['Other Expenses'][$k] ?? 0);
    }
    $rows[] = $makeRow('NET INCOME', $ni, 'total-row');
    return $rows;
}

Report::define('income_statement', [
    'filters' => ['range', 'columns', 'class'],
    'default_dates' => 'year_to_date',
    'sortable' => false,
    'run' => function (array $f): array {
        $mode = $f['columns'] ?: 'total';
        $cls = $f['class_id'] ?: null;
        $periods = Report::periods($f['from'], $f['to'], $mode);
        [$csql, $cp] = Classes::sqlFilter($cls, 'j');
        $data = DB::all(
            "SELECT j.account_id, j.txn_date, SUM(j.credit - j.debit) AS net FROM journal_lines j JOIN accounts a ON a.id = j.account_id
              WHERE a.category IN ('Income','Expense') AND j.txn_date BETWEEN :f AND :t $csql GROUP BY j.account_id, j.txn_date",
            ['f' => $f['from'], 't' => $f['to']] + $cp
        );
        $by = [];
        foreach ($data as $d) {
            $pk = $periods ? Report::periodKey($d['txn_date'], $mode) : 'total';
            $by[$d['account_id']][$pk] = ($by[$d['account_id']][$pk] ?? 0) + (float)$d['net'];
            $by[$d['account_id']]['__total'] = ($by[$d['account_id']]['__total'] ?? 0) + (float)$d['net'];
        }
        $cols = [['key' => 'name', 'label' => '']];
        foreach ($periods as $k => $label) {
            $cols[] = ['key' => 'p_' . $k, 'label' => $label, 'type' => 'money'];
        }
        $cols[] = ['key' => 'total', 'label' => 'Total', 'type' => 'money'];
        $rows = fin_income_rows($by, array_keys($periods), fn($a) => Report::link('rpt.general_ledger',
            ['account_id' => $a['id'], 'from' => $f['from'], 'to' => $f['to'], 'dates' => 'custom', 'class_id' => $cls]));
        return ['grouped' => true, 'columns' => $cols, 'rows' => $rows, 'notes' => fin_class_note($cls)];
    },
]);

Report::define('income_statement_class', [
    'filters' => ['range'],
    'default_dates' => 'year_to_date',
    'sortable' => false,
    'run' => function (array $f): array {
        $data = DB::all(
            "SELECT j.account_id, COALESCE(j.class_id, 0) AS cls, SUM(j.credit - j.debit) AS net FROM journal_lines j JOIN accounts a ON a.id = j.account_id
              WHERE a.category IN ('Income','Expense') AND j.txn_date BETWEEN :f AND :t GROUP BY j.account_id, COALESCE(j.class_id, 0)",
            ['f' => $f['from'], 't' => $f['to']]
        );
        $by = [];
        $used = [];
        foreach ($data as $d) {
            $k = 'c' . (int)$d['cls'];
            $by[$d['account_id']][$k] = ($by[$d['account_id']][$k] ?? 0) + (float)$d['net'];
            $by[$d['account_id']]['__total'] = ($by[$d['account_id']]['__total'] ?? 0) + (float)$d['net'];
            if (abs((float)$d['net']) >= 0.005) {
                $used[$k] = true;
            }
        }
        $buckets = fin_class_buckets($used);
        $cols = [['key' => 'name', 'label' => '']];
        foreach ($buckets as $k => $label) {
            $cols[] = ['key' => 'p_' . $k, 'label' => $label, 'type' => 'money'];
        }
        $cols[] = ['key' => 'total', 'label' => 'Total', 'type' => 'money'];
        $rows = fin_income_rows($by, array_keys($buckets), fn($a) => Report::link('rpt.general_ledger',
            ['account_id' => $a['id'], 'from' => $f['from'], 'to' => $f['to'], 'dates' => 'custom']));
        // each class column drills down to the ledger of that class
        foreach ($rows as &$r) {
            if (!empty($r['_links']['name'])) {
                foreach ($buckets as $k => $_) {
                    $cid = (int)substr($k, 1) ?: Classes::UNCLASSIFIED;
                    $r['_links']['p_' . $k] = $r['_links']['name'] . '&class_id=' . $cid;
                }
            }
        }
        unset($r);
        $note = Classes::inUse() ? 'One column per class; entries without a class are under Unclassified.'
            : 'No classes are set up yet, so everything is unclassified. Add classes under Financial Set-up &amp; Reports, Class List.';
        return ['grouped' => true, 'columns' => $cols, 'rows' => $rows, 'notes' => $note];
    },
]);

Report::define('cash_flow', [
    'filters' => ['range', 'class'],
    'default_dates' => 'year_to_date',
    'sortable' => false,
    'run' => function (array $f): array {
        $from = $f['from'];
        $to = $f['to'];
        $cls = $f['class_id'] ?: null;
        [$csql, $cp] = Classes::sqlFilter($cls, '');
        $change = fin_balances($from, $to, $cls);
        $ni = fin_net_income($from, $to, $cls);
        $rows = [['name' => 'OPERATING ACTIVITIES', '_class' => 'group-row'], ['name' => 'Net Income', 'amount' => $ni, '_class' => 'indent-1']];
        $groups = [
            'op' => ['Accounts Receivable', 'Other Current Asset', 'Accounts Payable', 'Credit Card', 'Other Current Liability'],
            'inv' => ['Fixed Asset', 'Other Asset'],
            'fin' => ['Long Term Liability', 'Equity'],
        ];
        $tot = ['op' => $ni, 'inv' => 0.0, 'fin' => 0.0];
        $lines = ['op' => [], 'inv' => [], 'fin' => []];
        foreach (fin_accounts() as $a) {
            foreach ($groups as $g => $types) {
                if (!in_array($a['account_type'], $types, true)) {
                    continue;
                }
                $v = -($change[$a['id']] ?? 0.0);
                if (abs($v) < 0.005) {
                    continue;
                }
                $label = $a['code'] . ' - ' . $a['name'];
                if ($a['account_type'] === 'Fixed Asset' && $a['normal_balance'] === 'Credit') {
                    // depreciation is a non-cash adjustment to operating activities
                    $lines['op'][] = ['name' => $label . ' (depreciation)', 'amount' => r2($v), '_class' => 'indent-1'];
                    $tot['op'] += $v;
                    continue;
                }
                $lines[$g][] = ['name' => $label, 'amount' => r2($v), '_class' => 'indent-1'];
                $tot[$g] += $v;
            }
        }
        $rows = array_merge($rows, $lines['op']);
        $rows[] = ['name' => 'Net cash provided by operating activities', 'amount' => r2($tot['op']), '_class' => 'subtotal-row'];
        $rows[] = ['name' => 'INVESTING ACTIVITIES', '_class' => 'group-row'];
        $rows = array_merge($rows, $lines['inv']);
        $rows[] = ['name' => 'Net cash provided by investing activities', 'amount' => r2($tot['inv']), '_class' => 'subtotal-row'];
        $rows[] = ['name' => 'FINANCING ACTIVITIES', '_class' => 'group-row'];
        $rows = array_merge($rows, $lines['fin']);
        $rows[] = ['name' => 'Net cash provided by financing activities', 'amount' => r2($tot['fin']), '_class' => 'subtotal-row'];
        $net = r2($tot['op'] + $tot['inv'] + $tot['fin']);
        [$in, $p] = DB::in('b', array_map('intval', DB::column("SELECT id FROM accounts WHERE account_type = 'Bank'")) ?: [0]);
        $begin = (float)DB::value("SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE account_id IN $in AND txn_date < :f $csql", $p + ['f' => $from] + $cp);
        $end = (float)DB::value("SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE account_id IN $in AND txn_date <= :t $csql", $p + ['t' => $to] + $cp);
        $rows[] = ['name' => 'NET CASH INCREASE (DECREASE) FOR THE PERIOD', 'amount' => $net, '_class' => 'total-row'];
        $rows[] = ['name' => 'Cash at beginning of period', 'amount' => r2($begin)];
        $rows[] = ['name' => 'CASH AT END OF PERIOD', 'amount' => r2($begin + $net), '_class' => 'total-row'];
        return [
            'grouped' => true,
            'columns' => [['key' => 'name', 'label' => ''], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money']],
            'rows' => $rows,
            'notes' => fin_class_note($cls) . 'Indirect method. Cash = bank and cash accounts.' . (abs(r2($begin + $net) - r2($end)) >= 0.01 ? ' <strong class="text-danger">Does not tie to the bank balances (' . money($end) . ').</strong>' : ''),
        ];
    },
]);

Report::define('general_ledger', [
    'filters' => ['range', 'account', 'class', 'search'],
    'sortable' => false,
    'run' => function (array $f): array {
        $accts = $f['account_id'] ? array_filter(fin_accounts(), fn($a) => (int)$a['id'] === $f['account_id']) : fin_accounts();
        $cls = $f['class_id'] ?: null;
        [$csql, $cp] = Classes::sqlFilter($cls, '');
        [$cjsql, $cjp] = Classes::sqlFilter($cls, 'j', 'fclassj');
        $rows = [];
        foreach ($accts as $a) {
            $opening = (float)DB::value("SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE account_id = :a AND txn_date < :f $csql", ['a' => $a['id'], 'f' => $f['from']] + $cp);
            if (in_array($a['category'], ['Income', 'Expense'], true)) {
                $fy = Report::fiscalYearStart($f['from']);
                $opening = (float)DB::value("SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE account_id = :a AND txn_date < :f AND txn_date >= :fy $csql", ['a' => $a['id'], 'f' => $f['from'], 'fy' => $fy] + $cp);
            }
            $sql = "SELECT j.*, t.txn_type, t.txn_no, COALESCE(c.name, v.name, o.name, t.payee_name) AS party, cl.name AS class_name FROM journal_lines j JOIN transactions t ON t.id = j.txn_id
                     LEFT JOIN customers c ON c.id = j.customer_id LEFT JOIN vendors v ON v.id = j.vendor_id
                     LEFT JOIN other_names o ON o.id = j.other_name_id LEFT JOIN classes cl ON cl.id = j.class_id
                    WHERE j.account_id = :a AND j.txn_date BETWEEN :f AND :t $cjsql";
            $p = ['a' => $a['id'], 'f' => $f['from'], 't' => $f['to']] + $cjp;
            if ($f['q'] !== '') {
                $sql .= ' AND (j.memo LIKE :q OR t.txn_no LIKE :q OR c.name LIKE :q OR v.name LIKE :q OR o.name LIKE :q)';
                $p['q'] = '%' . $f['q'] . '%';
            }
            $lines = DB::all($sql . ' ORDER BY j.txn_date, j.id', $p);
            if (!$lines && abs($opening) < 0.005) {
                continue;
            }
            $rows[] = ['date' => $a['code'] . ' - ' . $a['name'], '_class' => 'group-row'];
            $rows[] = ['date' => '', 'memo' => 'Beginning balance', 'balance' => r2($opening)];
            $run = $opening;
            $sd = 0.0;
            $sc = 0.0;
            foreach ($lines as $l) {
                $run += $l['debit'] - $l['credit'];
                $sd += $l['debit'];
                $sc += $l['credit'];
                $rows[] = [
                    'date' => $l['txn_date'], 'type' => Txn::label($l['txn_type']), 'num' => $l['txn_no'], 'name' => $l['party'], 'memo' => $l['memo'],
                    'class' => $l['class_name'],
                    'debit' => (float)$l['debit'] ?: '', 'credit' => (float)$l['credit'] ?: '', 'balance' => r2($run),
                    '_links' => ['num' => Report::txnLink($l)],
                ];
            }
            $rows[] = ['date' => 'Total ' . $a['code'], 'debit' => r2($sd), 'credit' => r2($sc), 'balance' => r2($run), '_class' => 'subtotal-row'];
        }
        return [
            'grouped' => true,
            'columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'type', 'label' => 'Type'], ['key' => 'num', 'label' => 'Num'],
                ['key' => 'name', 'label' => 'Name'], ['key' => 'memo', 'label' => 'Memo'], ['key' => 'class', 'label' => 'Class'],
                ['key' => 'debit', 'label' => 'Debit', 'type' => 'money'],
                ['key' => 'credit', 'label' => 'Credit', 'type' => 'money'], ['key' => 'balance', 'label' => 'Balance (debit +)', 'type' => 'money']],
            'notes' => fin_class_note($cls),
            'rows' => array_map(function ($r) {
                if (($r['_class'] ?? '') !== '' && !valid_date((string)($r['date'] ?? ''))) {
                    $r['_html']['date'] = '<strong>' . h($r['date']) . '</strong>';
                }
                return $r;
            }, $rows),
        ];
    },
]);

Report::define('journal', [
    'filters' => ['range', 'txn_type', 'class'],
    'sortable' => false,
    'run' => function (array $f): array {
        $cls = $f['class_id'] ?: null;
        [$csql, $cp] = Classes::sqlFilter($cls, 'j');
        $sql = "SELECT t.* FROM transactions t WHERE t.txn_date BETWEEN :f AND :t AND EXISTS (SELECT 1 FROM journal_lines j WHERE j.txn_id = t.id $csql)";
        $p = ['f' => $f['from'], 't' => $f['to']] + $cp;
        if ($f['txn_type'] !== '') {
            $sql .= ' AND t.txn_type = :ty';
            $p['ty'] = $f['txn_type'];
        }
        $txns = DB::all($sql . ' ORDER BY t.txn_date, t.id LIMIT 2000', $p);
        $rows = [];
        $td = 0.0;
        $tc = 0.0;
        foreach ($txns as $t) {
            $first = true;
            foreach (DB::all("SELECT j.*, a.code, a.name, cl.name AS class_name FROM journal_lines j JOIN accounts a ON a.id = j.account_id LEFT JOIN classes cl ON cl.id = j.class_id WHERE j.txn_id = :t $csql ORDER BY j.id", ['t' => $t['id']] + $cp) as $j) {
                $rows[] = [
                    'date' => $first ? $t['txn_date'] : '', 'type' => $first ? Txn::label($t['txn_type']) : '', 'num' => $first ? $t['txn_no'] : '',
                    'account' => $j['code'] . ' - ' . $j['name'], 'memo' => $j['memo'], 'class' => $j['class_name'],
                    'debit' => (float)$j['debit'] ?: '', 'credit' => (float)$j['credit'] ?: '',
                    '_links' => ['num' => $first ? Report::txnLink(['txn_type' => $t['txn_type'], 'txn_id' => $t['id']]) : null],
                    '_class' => $first ? 'border-top' : '',
                ];
                $td += $j['debit'];
                $tc += $j['credit'];
                $first = false;
            }
        }
        return [
            'grouped' => true,
            'columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'type', 'label' => 'Type'], ['key' => 'num', 'label' => 'Num'],
                ['key' => 'account', 'label' => 'Account'], ['key' => 'memo', 'label' => 'Memo'], ['key' => 'class', 'label' => 'Class'],
                ['key' => 'debit', 'label' => 'Debit', 'type' => 'money'], ['key' => 'credit', 'label' => 'Credit', 'type' => 'money']],
            'rows' => $rows,
            'notes' => fin_class_note($cls),
            'totals' => ['date' => 'TOTAL', 'debit' => r2($td), 'credit' => r2($tc)],
        ];
    },
]);
