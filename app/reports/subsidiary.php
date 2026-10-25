<?php
declare(strict_types=1);

/**
 * Subsidiary ledgers: the names that transacted an account.
 *
 *   Subsidiary Ledger     one name, every account it transacted (or one account), with every entry
 *                         and a running balance.
 *   Schedule of Accounts  one account, every name that transacted it, with the amount each one
 *                         stands at.
 *
 * An account keeps a subsidiary ledger when it is marked "With SL" in the Chart of Accounts. Both
 * reports also work on an account that is not marked, so a listing can be seen before the flag is set.
 */

/** The names that can be looked up, as the one short code Names uses (c12, v5, o7) */
function sl_name_options(): array
{
    return Names::options();
}

/** Accounts a subsidiary ledger can be asked for: the ones marked "With SL" first, then the rest */
function sl_account_options(): array
{
    $flagged = Accts::withSl();
    $out = [];
    foreach ($flagged as $id => $label) {
        $out[$id] = $label . '  (With SL)';
    }
    foreach (Accts::all(false) as $id => $label) {
        if (!isset($out[$id])) {
            $out[$id] = $label;
        }
    }
    return $out;
}

/** "From and to" (a date range) or "everything up to the To date" */
function sl_period_options(): array
{
    return [
        'range' => 'From and to: the entries between the two dates',
        'asof'  => 'To: every entry up to the To date',
    ];
}

/** The dates in force, and the line that says so on the report */
function sl_period(array $f): array
{
    $asOf = ($f['sl_period'] ?? 'range') === 'asof';
    $from = $asOf ? '1900-01-01' : (string)$f['from'];
    $to = (string)$f['to'];
    return [$from, $to, $asOf
        ? 'Every entry up to ' . fdate($to)
        : fdate($from) . ' through ' . fdate($to)];
}

/** The three id columns of one name code, ready for a WHERE clause */
function sl_name_where(string $code, string $alias = 'j'): array
{
    $who = Names::parse($code);
    if ($who['customer_id']) {
        return [" AND $alias.customer_id = :slname", ['slname' => $who['customer_id']]];
    }
    if ($who['vendor_id']) {
        return [" AND $alias.vendor_id = :slname", ['slname' => $who['vendor_id']]];
    }
    if ($who['other_name_id']) {
        return [" AND $alias.other_name_id = :slname", ['slname' => $who['other_name_id']]];
    }
    return ['', []];
}

/** The accounts marked "With SL", oldest code first: id => ['code' => ..., 'name' => ...] */
function sl_flagged_accounts(): array
{
    $out = [];
    try {
        foreach (DB::all('SELECT id, code, name FROM accounts WHERE with_sl = 1 ORDER BY code') as $r) {
            $out[(int)$r['id']] = ['code' => (string)$r['code'], 'name' => (string)$r['name']];
        }
    } catch (Throwable $e) {
        // the column is only there once the subsidiary ledger migration has been run
    }
    return $out;
}

/** The name on a journal line, as its code and its label */
function sl_line_name(array $l): array
{
    if (!empty($l['customer_id'])) {
        return ['c' . (int)$l['customer_id'], (string)($l['customer_name'] ?? ''), 'Customer'];
    }
    if (!empty($l['vendor_id'])) {
        return ['v' . (int)$l['vendor_id'], (string)($l['vendor_name'] ?? ''), 'Supplier'];
    }
    if (!empty($l['other_name_id'])) {
        return ['o' . (int)$l['other_name_id'], (string)($l['other_name'] ?? ''), (string)($l['other_type'] ?? '') === 'employee' ? 'Employee' : 'Other name'];
    }
    return ['', '', ''];
}

// ---------------------------------------------------------------------------- Subsidiary Ledger

Report::define('subsidiary_ledger', [
    'filters' => ['range', 'account', 'class'],
    'extra_filters' => [
        'sl_name'   => ['Subsidiary ledger (name)', fn() => sl_name_options(), '— every name —'],
        'sl_period' => ['Which entries', fn() => sl_period_options(), null],
    ],
    'sortable' => false,
    'default_dates' => 'this_year',
    'run' => function (array $f): array {
        [$from, $to, $basis] = sl_period($f);
        $cls = $f['class_id'] ?: null;
        [$cjsql, $cjp] = Classes::sqlFilter($cls, 'j', 'fclassj');

        $where = 'j.txn_date BETWEEN :f AND :t' . $cjsql;
        $p = ['f' => $from, 't' => $to] + $cjp;
        // only entries that carry a name belong in a subsidiary ledger
        $where .= ' AND (j.customer_id IS NOT NULL OR j.vendor_id IS NOT NULL OR j.other_name_id IS NOT NULL)';
        if (!empty($f['sl_name'])) {
            [$nsql, $np] = sl_name_where((string)$f['sl_name']);
            $where .= $nsql;
            $p += $np;
        }
        if ($f['account_id']) {
            $where .= ' AND j.account_id = :acc';
            $p['acc'] = $f['account_id'];
        } else {
            // with no account chosen, the accounts that keep a subsidiary ledger are the ones listed
            $flagged = array_keys(Accts::withSl());
            if ($flagged) {
                [$in, $ip] = DB::in('sla', $flagged);
                $where .= " AND j.account_id IN $in";
                $p += $ip;
            }
        }

        $lines = DB::all(
            "SELECT j.*, t.txn_type, t.txn_no, t.ref_no, a.code AS account_code, a.name AS account_name, a.normal_balance,
                    c.name AS customer_name, pc.name AS parent_name, v.name AS vendor_name, o.name AS other_name, o.name_type AS other_type,
                    cl.name AS class_name
               FROM journal_lines j
               JOIN transactions t ON t.id = j.txn_id
               JOIN accounts a ON a.id = j.account_id
               LEFT JOIN customers c ON c.id = j.customer_id
               LEFT JOIN customers pc ON pc.id = c.parent_id
               LEFT JOIN vendors v ON v.id = j.vendor_id
               LEFT JOIN other_names o ON o.id = j.other_name_id
               LEFT JOIN classes cl ON cl.id = j.class_id
              WHERE $where
              ORDER BY j.txn_date, j.id",
            $p
        );

        // group by name, then by account, keeping a running balance down each account
        $byName = [];
        foreach ($lines as $l) {
            [$code, $label, $kind] = sl_line_name($l);
            if ($code === '') {
                continue;
            }
            if ($code[0] === 'c' && !empty($l['parent_name'])) {
                $label = $l['parent_name'] . ':' . $label;
            }
            $byName[$code]['label'] = $label;
            $byName[$code]['kind'] = $kind;
            $byName[$code]['accounts'][(int)$l['account_id']]['account'] = $l['account_code'] . '  ' . $l['account_name'];
            $byName[$code]['accounts'][(int)$l['account_id']]['lines'][] = $l;
        }

        // One name chosen and no account: the whole of that name's subsidiary ledger is wanted, so every
        // account marked "With SL" is listed for it, including the ones it has not transacted in the period.
        // That way the listing shows what the name stands at on each of them rather than nothing at all.
        $wholeLedger = !empty($f['sl_name']) && !$f['account_id'];
        if ($wholeLedger) {
            $code = (string)$f['sl_name'];
            if (!isset($byName[$code])) {
                $label = Names::label(Names::parse($code));
                $byName[$code] = ['label' => $label !== '' ? $label : $code, 'kind' => '', 'accounts' => []];
            }
            foreach (sl_flagged_accounts() as $accId => $acc) {
                if (!isset($byName[$code]['accounts'][$accId])) {
                    $byName[$code]['accounts'][$accId] = ['account' => $acc['code'] . '  ' . $acc['name'], 'lines' => []];
                }
            }
        }
        uasort($byName, fn($a, $b) => strcasecmp($a['label'], $b['label']));

        $rows = [];
        $grandDr = 0.0;
        $grandCr = 0.0;
        foreach ($byName as $code => $n) {
            $rows[] = ['date' => $n['label'], '_class' => 'group-row'];
            uasort($n['accounts'], fn($a, $b) => strcmp($a['account'], $b['account']));
            foreach ($n['accounts'] as $accId => $acc) {
                $rows[] = ['account' => $acc['account'], '_class' => 'subtotal-row'];
                $run = 0.0;
                $sd = 0.0;
                $sc = 0.0;
                foreach ($acc['lines'] as $l) {
                    $run += (float)$l['debit'] - (float)$l['credit'];
                    $sd += (float)$l['debit'];
                    $sc += (float)$l['credit'];
                    $rows[] = [
                        'date' => $l['txn_date'],
                        'ref' => $l['txn_no'] ?: $l['ref_no'],
                        'account' => $l['account_code'],
                        'description' => $l['memo'] ?: Txn::label($l['txn_type']),
                        'class' => $l['class_name'],
                        'debit' => (float)$l['debit'] ?: '',
                        'credit' => (float)$l['credit'] ?: '',
                        'balance' => r2($run),
                        '_class' => 'indent-1',
                        '_links' => ['ref' => Report::txnLink($l)],
                    ];
                }
                $rows[] = ['description' => 'Total ' . $acc['account'], 'debit' => r2($sd), 'credit' => r2($sc),
                    'balance' => r2($run), '_class' => 'subtotal-row'];
                $grandDr += $sd;
                $grandCr += $sc;
            }
        }

        $notes = 'A subsidiary ledger lists the entries of one name. ' . h($basis) . '.';
        if ($cls) {
            $notes .= ' Class: ' . h(Classes::name($cls)) . '.';
        }
        if (!$f['account_id']) {
            if (!Accts::withSl()) {
                $notes .= ' No account is marked "With SL" yet, so every account a name transacted is listed.';
            } elseif ($wholeLedger) {
                $notes .= ' Every account marked "With SL" is listed for this name, including the ones it has no entries on'
                    . ' in this period; choose an account above to narrow it to one.';
            } else {
                $notes .= ' Every account marked "With SL" is listed; choose an account above to narrow it to one.';
            }
        }
        if (!$rows) {
            $notes .= ' Nothing was posted with a name in this period. Tick "With SL" on the accounts you want a listing of,'
                . ' and every journal entry, cash voucher or check to them will carry a name from then on.';
        }

        return [
            'grouped' => true,
            'columns' => [
                ['key' => 'date', 'label' => 'Date', 'type' => 'date'],
                ['key' => 'ref', 'label' => 'Ref ID'],
                ['key' => 'account', 'label' => 'Account'],
                ['key' => 'description', 'label' => 'Description'],
                ['key' => 'class', 'label' => 'Class'],
                ['key' => 'debit', 'label' => 'Debit', 'type' => 'money'],
                ['key' => 'credit', 'label' => 'Credit', 'type' => 'money'],
                ['key' => 'balance', 'label' => 'Balance', 'type' => 'money'],
            ],
            'notes' => $notes,
            'totals' => $rows ? ['description' => 'Total', 'debit' => r2($grandDr), 'credit' => r2($grandCr)] : [],
            'rows' => array_map(function ($r) {
                if (($r['_class'] ?? '') === 'group-row') {
                    $r['_html']['date'] = '<strong>' . h((string)$r['date']) . '</strong>';
                }
                if (($r['_class'] ?? '') === 'subtotal-row' && isset($r['account']) && !isset($r['debit'])) {
                    $r['_html']['account'] = '<strong>' . h((string)$r['account']) . '</strong>';
                }
                return $r;
            }, $rows),
        ];
    },
]);

// ---------------------------------------------------------------------------- Schedule of Accounts

Report::define('schedule_of_accounts', [
    'filters' => ['range', 'account', 'class'],
    'extra_filters' => [
        'sl_period' => ['Which entries', fn() => sl_period_options(), null],
    ],
    'sortable' => false,
    'default_dates' => 'this_year',
    'run' => function (array $f): array {
        [$from, $to, $basis] = sl_period($f);
        $cls = $f['class_id'] ?: null;
        [$cjsql, $cjp] = Classes::sqlFilter($cls, 'j', 'fclassj');

        $where = 'j.txn_date BETWEEN :f AND :t' . $cjsql
            . ' AND (j.customer_id IS NOT NULL OR j.vendor_id IS NOT NULL OR j.other_name_id IS NOT NULL)';
        $p = ['f' => $from, 't' => $to] + $cjp;
        if ($f['account_id']) {
            $where .= ' AND j.account_id = :acc';
            $p['acc'] = $f['account_id'];
        } else {
            // with no account chosen, every account that keeps a subsidiary ledger is listed
            $flagged = array_keys(Accts::withSl());
            if ($flagged) {
                [$in, $ip] = DB::in('sla', $flagged);
                $where .= " AND j.account_id IN $in";
                $p += $ip;
            }
        }

        $rows = DB::all(
            "SELECT j.account_id, a.code AS account_code, a.name AS account_name, a.normal_balance, a.with_sl,
                    j.customer_id, j.vendor_id, j.other_name_id,
                    c.name AS customer_name, pc.name AS parent_name, v.name AS vendor_name,
                    o.name AS other_name, o.name_type AS other_type,
                    ROUND(SUM(j.debit - j.credit), 2) AS net
               FROM journal_lines j
               JOIN accounts a ON a.id = j.account_id
               LEFT JOIN customers c ON c.id = j.customer_id
               LEFT JOIN customers pc ON pc.id = c.parent_id
               LEFT JOIN vendors v ON v.id = j.vendor_id
               LEFT JOIN other_names o ON o.id = j.other_name_id
              WHERE $where
              GROUP BY j.account_id, a.code, a.name, a.normal_balance, a.with_sl,
                       j.customer_id, j.vendor_id, j.other_name_id,
                       c.name, pc.name, v.name, o.name, o.name_type
              ORDER BY a.code",
            $p
        );

        $byAccount = [];
        foreach ($rows as $r) {
            [$code, $label, $kind] = sl_line_name($r);
            if ($code === '') {
                continue;
            }
            if ($code[0] === 'c' && !empty($r['parent_name'])) {
                $label = $r['parent_name'] . ':' . $label;
            }
            $amount = r2($r['normal_balance'] === 'Credit' ? -(float)$r['net'] : (float)$r['net']);
            $byAccount[(int)$r['account_id']]['title'] = $r['account_code'] . '  ' . $r['account_name'];
            $byAccount[(int)$r['account_id']]['code'] = $r['account_code'];
            $byAccount[(int)$r['account_id']]['rows'][] = ['code' => $code, 'name' => $label, 'kind' => $kind, 'amount' => $amount];
        }

        $out = [];
        $grand = 0.0;
        foreach ($byAccount as $accId => $acc) {
            usort($acc['rows'], fn($a, $b) => strcasecmp($a['name'], $b['name']));
            $sum = 0.0;
            $lines = [];
            foreach ($acc['rows'] as $r) {
                if (abs($r['amount']) < 0.005) {
                    continue;   // a name that nets to nothing is not part of the schedule
                }
                $sum += $r['amount'];
                $lines[] = [
                    'sl_code' => $r['code'], 'name' => $r['name'], 'kind' => $r['kind'], 'amount' => $r['amount'],
                    '_links' => ['name' => Report::link('rpt.subsidiary_ledger', [
                        'sl_name' => $r['code'], 'account_id' => $accId, 'from' => $f['from'], 'to' => $f['to'],
                        'dates' => 'custom', 'sl_period' => $f['sl_period'] ?? 'range', 'class_id' => $cls,
                    ])],
                ];
            }
            if (!$lines) {
                continue;       // every name on this account nets to nothing, so it has no schedule
            }
            $out[] = ['sl_code' => $acc['title'], '_class' => 'group-row'];
            $out = array_merge($out, $lines);
            $out[] = ['name' => 'Total ' . $acc['title'], 'amount' => r2($sum), '_class' => 'subtotal-row'];
            $grand += $sum;
        }

        $notes = 'The names that transacted each account, and what each one stands at. ' . h($basis) . '.';
        $notes .= $cls ? ' Class: ' . h(Classes::name($cls)) . '.'
            : ' Every class, and entries with no class, are included.';
        if (!$f['account_id'] && !Accts::withSl()) {
            $notes .= ' No account is marked "With SL" yet, so choose an account above, or tick "With SL" on the accounts you want a schedule of.';
        }

        return [
            'grouped' => true,
            'columns' => [
                ['key' => 'sl_code', 'label' => 'SL Code'],
                ['key' => 'name', 'label' => 'Subsidiary Ledger Name'],
                ['key' => 'kind', 'label' => 'Kind'],
                ['key' => 'amount', 'label' => 'Current Amount', 'type' => 'money'],
            ],
            'notes' => $notes,
            'totals' => $out ? ['name' => 'Total', 'amount' => r2($grand)] : [],
            'rows' => array_map(function ($r) {
                if (($r['_class'] ?? '') === 'group-row') {
                    $r['_html']['sl_code'] = '<strong>' . h((string)$r['sl_code']) . '</strong>';
                }
                return $r;
            }, $out),
        ];
    },
]);
