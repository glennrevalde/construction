<?php
declare(strict_types=1);

/**
 * Comparative financial statements (v29), Financial Set-up & Reports > Reports:
 *   cmp_is_month    Comparative Income Statement by Month          one column per month of the date range
 *   cmp_is_quarter  Comparative Income Statement by Quarter        one column per quarter
 *   cmp_is_year     Comparative Income Statement by Year           one column per fiscal year
 *   cmp_is_custom   Comparative Income Statement by Custom Range   up to four date ranges of your own, side by side
 *   cmp_bs_month    Comparative Balance Sheet by Month             the balances at the end of each month
 *   cmp_bs_quarter  Comparative Balance Sheet by Quarter           at the end of each quarter
 *   cmp_bs_year     Comparative Balance Sheet by Year              at the end of each fiscal year
 *   cmp_bs_custom   Comparative Balance Sheet by Custom Date       as of up to four dates of your own
 * The figures are those of the Income Statement and the Balance Sheet (fin_income_rows / fin_balance_sheet_rows),
 * so each column agrees with the statement run for that period or date. The last two columns compare the last
 * period with the one before it (the first range or date with the second on the custom reports): the change in
 * amount and in percent. Every account figure opens the General Ledger of that account for its own column.
 */

/**
 * The periods of a date range: [key => [label, start, end]], each cut at the From and To dates.
 * Months and quarters are calendar ones (as on the Income Statement's Columns box); years are fiscal years.
 */
function cmp_periods(string $from, string $to, string $mode): array
{
    $out = [];
    if ($from > $to) {
        return $out;
    }
    $fs = (int)Settings::get('fiscal_year_start_month', '1') ?: 1;
    if ($mode === 'year') {
        $cur = Report::fiscalYearStart($from);
    } elseif ($mode === 'quarter') {
        $m = (int)substr($from, 5, 2);
        $cur = sprintf('%s-%02d-01', substr($from, 0, 4), intdiv($m - 1, 3) * 3 + 1);
    } else {
        $cur = substr($from, 0, 7) . '-01';
    }
    $n = 0;
    while ($cur <= $to && $n++ < 60) {
        $step = ['month' => '+1 month', 'quarter' => '+3 months', 'year' => '+1 year'][$mode];
        $next = date('Y-m-d', strtotime($cur . ' ' . $step));
        $end = date('Y-m-d', strtotime($next . ' -1 day'));
        $y = (int)substr($cur, 0, 4);
        if ($mode === 'month') {
            $key = substr($cur, 0, 7);
            $label = date('M Y', strtotime($cur));
        } elseif ($mode === 'quarter') {
            $q = intdiv((int)substr($cur, 5, 2) - 1, 3) + 1;
            $key = $y . '-Q' . $q;
            $label = 'Q' . $q . ' ' . $y;
        } else {
            $key = 'FY' . $y;
            $label = $fs === 1 ? (string)$y : 'FY ' . $y . '-' . substr((string)($y + 1), 2);
        }
        $out[$key] = [$label, max($cur, $from), min($end, $to)];
        $cur = $next;
    }
    return $out;
}

/** The custom ranges or dates typed on the report, in the order typed: [key => [label, start, end]] */
function cmp_custom(array $f, bool $ranges): array
{
    $out = [];
    for ($i = 1; $i <= 4; $i++) {
        if ($ranges) {
            $a = (string)($f['r' . $i . '_from'] ?? '');
            $b = (string)($f['r' . $i . '_to'] ?? '');
            if ($a === '' || $b === '' || $a > $b) {
                continue;
            }
            $out['r' . $i] = ['Range ' . $i . ': ' . fdate($a) . ' - ' . fdate($b), $a, $b];
        } else {
            $d = (string)($f['d' . $i] ?? '');
            if ($d === '') {
                continue;
            }
            $out['d' . $i] = ['Date ' . $i . ': ' . fdate($d), $d, $d];
        }
    }
    return $out;
}

/** The custom ranges / dates that were typed the wrong way round or left half empty, for the note */
function cmp_custom_skipped(array $f, bool $ranges): array
{
    $skip = [];
    for ($i = 1; $i <= 4; $i++) {
        if ($ranges) {
            $a = (string)($f['r' . $i . '_from'] ?? '');
            $b = (string)($f['r' . $i . '_to'] ?? '');
            if (($a === '') !== ($b === '') || ($a !== '' && $b !== '' && $a > $b)) {
                $skip[] = 'Range ' . $i;
            }
        }
    }
    return $skip;
}

/** The change columns: the last column less the one before it (or the first less the second) */
function cmp_add_change(array &$rows, array &$cols, string $a, string $b, string $label): void
{
    $cols[] = ['key' => 'chg', 'label' => 'Change ' . $label, 'type' => 'money'];
    $cols[] = ['key' => 'chg_pct', 'label' => '% change', 'type' => 'pct'];
    foreach ($rows as &$r) {
        if (!array_key_exists($a, $r) || !array_key_exists($b, $r) || !is_numeric($r[$a]) || !is_numeric($r[$b])) {
            continue;
        }
        $r['chg'] = r2((float)$r[$a] - (float)$r[$b]);
        $r['chg_pct'] = abs((float)$r[$b]) > 0.004 ? round(((float)$r[$a] - (float)$r[$b]) / abs((float)$r[$b]) * 100, 1) : '';
    }
    unset($r);
}

/** Which two columns the change compares, and how the heading says it */
function cmp_change_pair(array $periods, bool $custom): ?array
{
    $keys = array_keys($periods);
    if (count($keys) < 2) {
        return null;
    }
    if ($custom) {
        return [$keys[0], $keys[1], str_starts_with($keys[0], 'r') ? '(range ' . substr($keys[0], 1) . ' less range ' . substr($keys[1], 1) . ')'
            : '(date ' . substr($keys[0], 1) . ' less date ' . substr($keys[1], 1) . ')'];
    }
    $n = count($keys);
    return [$keys[$n - 1], $keys[$n - 2], '(' . $periods[$keys[$n - 1]][0] . ' vs ' . $periods[$keys[$n - 2]][0] . ')'];
}

/** The basis line of a comparative report */
function cmp_basis(array $periods, ?int $cls, bool $balance): string
{
    $parts = [];
    if ($periods) {
        $first = reset($periods);
        $last = end($periods);
        $parts[] = $balance ? 'As of ' . implode(', ', array_map(fn($p) => fdate($p[2]), $periods))
            : fdate($first[1]) . ' through ' . fdate($last[2]);
    }
    if ($cls) {
        $parts[] = 'Class ' . Classes::name($cls);
    }
    return implode(', ', $parts);
}

/** Comparative income statement over any set of periods */
function cmp_income(array $f, array $periods, bool $custom): array
{
    $cls = $f['class_id'] ?: null;
    if (!$periods) {
        return ['columns' => [['key' => 'name', 'label' => '']], 'rows' => [],
            'notes' => $custom ? 'Type at least one range: its From and To dates.' : 'The From date is after the To date.'];
    }
    [$csql, $cp] = Classes::sqlFilter($cls, 'j');
    $by = [];
    foreach ($periods as $k => [$label, $a, $b]) {
        foreach (DB::all("SELECT j.account_id, SUM(j.credit - j.debit) AS net FROM journal_lines j JOIN accounts a ON a.id = j.account_id
                           WHERE a.category IN ('Income','Expense') AND j.txn_date BETWEEN :f AND :t $csql GROUP BY j.account_id",
            ['f' => $a, 't' => $b] + $cp) as $d) {
            $aid = (int)$d['account_id'];
            $by[$aid][$k] = ($by[$aid][$k] ?? 0) + (float)$d['net'];
            $by[$aid]['__total'] = ($by[$aid]['__total'] ?? 0) + (float)$d['net'];
        }
    }
    // the drill-down of each account is set below, column by column
    $rows = fin_income_rows($by, array_keys($periods), fn($acct) => 'acct:' . (int)$acct['id']);
    // each account figure opens the ledger of its own period
    foreach ($rows as &$r) {
        $u = $r['_links']['name'] ?? null;
        if (!is_string($u) || !str_starts_with($u, 'acct:')) {
            continue;
        }
        $aid = (int)substr($u, 5);
        $r['_links'] = ['name' => Report::link('rpt.general_ledger', ['account_id' => $aid, 'dates' => 'custom', 'from' => reset($periods)[1], 'to' => end($periods)[2], 'class_id' => $cls])];
        foreach ($periods as $k => [$label, $a, $b]) {
            $r['_links']['p_' . $k] = Report::link('rpt.general_ledger', ['account_id' => $aid, 'dates' => 'custom', 'from' => $a, 'to' => $b, 'class_id' => $cls]);
        }
    }
    unset($r);
    $cols = [['key' => 'name', 'label' => '']];
    foreach ($periods as $k => [$label]) {
        $cols[] = ['key' => 'p_' . $k, 'label' => $label, 'type' => 'money'];
    }
    if (!$custom) {
        $cols[] = ['key' => 'total', 'label' => 'Total', 'type' => 'money'];
    } else {
        foreach ($rows as &$r) {
            unset($r['total']);
        }
        unset($r);
    }
    if ($pair = cmp_change_pair($periods, $custom)) {
        cmp_add_change($rows, $cols, 'p_' . $pair[0], 'p_' . $pair[1], $pair[2]);
    }
    $note = fin_class_note($cls);
    if (!$custom) {
        $note .= 'One column per ' . ($f['_mode'] === 'year' ? 'fiscal year' : $f['_mode']) . ' of the date range; the first and last are cut at the From and To dates. ';
    } else {
        $skip = cmp_custom_skipped($f, true);
        $note .= 'One column per range, in the order typed; the ranges may overlap, so there is no total column. '
            . ($skip ? 'Left out (a date missing, or From after To): ' . implode(', ', $skip) . '. ' : '');
    }
    $note .= count($periods) > 1 ? 'The change compares ' . ($custom ? 'the first column with the second' : 'the last column with the one before it') . '. ' : '';
    $note .= 'Click an amount for its entries in the General Ledger.';
    return ['grouped' => true, 'columns' => $cols, 'rows' => $rows, 'notes' => $note];
}

/** Comparative balance sheet at the end of each period (or as of each date) */
function cmp_balance(array $f, array $periods, bool $custom): array
{
    $cls = $f['class_id'] ?: null;
    if (!$periods) {
        return ['columns' => [['key' => 'name', 'label' => '']], 'rows' => [],
            'notes' => $custom ? 'Type at least one date.' : 'The From date is after the To date.'];
    }
    $keys = array_keys($periods);
    $bal = [];
    $prior = [];
    $ytd = [];
    foreach ($periods as $k => [$label, $a, $asof]) {
        $fy = Report::fiscalYearStart($asof);
        $bal[$k] = fin_balances(null, $asof, $cls);
        $prior[$k] = fin_net_income(null, date('Y-m-d', strtotime("$fy -1 day")), $cls);
        $ytd[$k] = fin_net_income($fy, $asof, $cls);
    }
    $lastAsof = end($periods)[2];
    [$rows, $totals] = fin_balance_sheet_rows($keys, $bal, $prior, $ytd, $lastAsof, Report::fiscalYearStart($lastAsof), $cls ? ['class_id' => $cls] : []);
    foreach ($rows as &$r) {
        // the columns are p_<key>, as on the comparative income statement
        foreach ($keys as $k) {
            if (array_key_exists($k, $r)) {
                $r['p_' . $k] = $r[$k];
                unset($r[$k]);
            }
        }
        $u = $r['_links']['name'] ?? null;
        if (is_string($u) && str_contains($u, 'rpt.general_ledger')) {
            foreach ($periods as $k => [$label, $a, $asof]) {
                $r['_links']['p_' . $k] = str_replace('to=' . $lastAsof, 'to=' . $asof, $u);
            }
        } elseif (is_string($u)) {
            // the net income of the fiscal year to date: the income statement of each column's own year
            foreach ($periods as $k => [$label, $a, $asof]) {
                $r['_links']['p_' . $k] = Report::link('rpt.income_statement', ['dates' => 'custom', 'from' => Report::fiscalYearStart($asof), 'to' => $asof, 'class_id' => $cls]);
            }
        }
    }
    unset($r);
    $cols = [['key' => 'name', 'label' => '']];
    foreach ($periods as $k => [$label, $a, $asof]) {
        $cols[] = ['key' => 'p_' . $k, 'label' => $custom ? $label : $label . ' (' . fdate($asof) . ')', 'type' => 'money'];
    }
    if ($pair = cmp_change_pair($periods, $custom)) {
        cmp_add_change($rows, $cols, 'p_' . $pair[0], 'p_' . $pair[1], $pair[2]);
    }
    $off = [];
    foreach ($periods as $k => [$label]) {
        if (abs(($totals['ASSETS'][$k] ?? 0) - ($totals['LE'][$k] ?? 0)) >= 0.01) {
            $off[] = $label;
        }
    }
    $note = fin_class_note($cls)
        . ($custom ? 'One column per date, in the order typed. ' : 'The balances at the end of each ' . ($f['_mode'] === 'year' ? 'fiscal year' : $f['_mode']) . ' of the date range; the last one is as of the To date. ')
        . (count($periods) > 1 ? 'The change compares ' . ($custom ? 'the first column with the second' : 'the last column with the one before it') . '. ' : '')
        . ($off ? '<strong class="text-danger">Assets do not equal liabilities and equity in ' . h(implode(', ', $off)) . '.</strong>'
            : ($cls ? '' : 'Assets = Liabilities + Equity in every column.'));
    return ['grouped' => true, 'columns' => $cols, 'rows' => $rows, 'notes' => $note];
}

/** The year reports open on the last three fiscal years, up to today */
function cmp_three_years(array $f): array
{
    if (!array_key_exists('from', $_GET) && (string)g('dates', 'custom') === 'custom') {
        $f['from'] = date('Y-m-d', strtotime(Report::fiscalYearStart(today()) . ' -2 years'));
        $f['to'] = today();
    }
    return $f;
}

foreach (['month' => 'year_to_date', 'quarter' => 'year_to_date', 'year' => 'custom'] as $cmpMode => $cmpDates) {
    Report::define('cmp_is_' . $cmpMode, [
        'filters' => ['range', 'class'],
        'default_dates' => $cmpDates,
        'sortable' => false,
        // the column headings wrap, so many periods fit across the page
        'layout_defaults' => ['col_wrap' => '1'],
        'filter_hook' => function (array $f) use ($cmpMode): array {
            $f['_mode'] = $cmpMode;
            return $cmpMode === 'year' ? cmp_three_years($f) : $f;
        },
        'run' => fn(array $f) => cmp_income($f, cmp_periods($f['from'], $f['to'], $f['_mode']), false),
    ]);
    Report::define('cmp_bs_' . $cmpMode, [
        'filters' => ['range', 'class'],
        'default_dates' => $cmpDates,
        'sortable' => false,
        // the column headings wrap, so many periods fit across the page
        'layout_defaults' => ['col_wrap' => '1'],
        'filter_hook' => function (array $f) use ($cmpMode): array {
            $f['_mode'] = $cmpMode;
            return $cmpMode === 'year' ? cmp_three_years($f) : $f;
        },
        'basis' => fn(array $f) => cmp_basis(cmp_periods($f['from'], $f['to'], $f['_mode']), $f['class_id'] ?: null, true),
        'run' => fn(array $f) => cmp_balance($f, cmp_periods($f['from'], $f['to'], $f['_mode']), false),
    ]);
}

Report::define('cmp_is_custom', [
    'filters' => ['class'],
    'sortable' => false,
    'layout_defaults' => ['col_wrap' => '1'],
    // this month beside the same month a year earlier, until other ranges are typed
    'date_inputs' => [
        'r1_from' => ['Range 1 from', fn() => date('Y-m-01')], 'r1_to' => ['Range 1 to', fn() => date('Y-m-t')],
        'r2_from' => ['Range 2 from', fn() => date('Y-m-01', strtotime(date('Y-m-01') . ' -1 year'))], 'r2_to' => ['Range 2 to', fn() => date('Y-m-t', strtotime(date('Y-m-01') . ' -1 year'))],
        'r3_from' => ['Range 3 from', null], 'r3_to' => ['Range 3 to', null],
        'r4_from' => ['Range 4 from', null], 'r4_to' => ['Range 4 to', null],
    ],
    'basis' => fn(array $f) => implode(', ', array_filter([implode('; ', array_map(fn($p) => $p[0], cmp_custom($f, true))), ($f['class_id'] ?? null) ? 'Class ' . Classes::name((int)$f['class_id']) : ''])),
    'run' => fn(array $f) => cmp_income($f, cmp_custom($f, true), true),
]);

Report::define('cmp_bs_custom', [
    'filters' => ['class'],
    'sortable' => false,
    'layout_defaults' => ['col_wrap' => '1'],
    // today beside the end of the last fiscal year, until other dates are typed
    'date_inputs' => [
        'd1' => ['Date 1', fn() => today()],
        'd2' => ['Date 2', fn() => date('Y-m-d', strtotime(Report::fiscalYearStart(today()) . ' -1 day'))],
        'd3' => ['Date 3', null],
        'd4' => ['Date 4', null],
    ],
    'basis' => fn(array $f) => cmp_basis(cmp_custom($f, false), $f['class_id'] ?: null, true),
    'run' => fn(array $f) => cmp_balance($f, cmp_custom($f, false), true),
]);
