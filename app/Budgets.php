<?php
declare(strict_types=1);

/**
 * Budgets and forecasts.
 *
 *  - A budget holds twelve monthly amounts per account for one fiscal year, for the income statement
 *    (kind 'pl') or the balance sheet (kind 'bs'), optionally for one class.
 *  - A forecast (kind 'forecast') holds the same twelve months per customer and income account:
 *    for one customer, or for all customers in one sheet.
 *
 * The reports put the actual figures from the ledger beside them and show the difference.
 */
final class Budgets
{
    public const KINDS = [
        'pl'       => 'Income statement (profit and loss)',
        'bs'       => 'Balance sheet',
        'forecast' => 'Sales forecast by customer',
    ];

    public const PL_TYPES = ['Income', 'Other Income', 'Cost of Goods Sold', 'Expense', 'Other Expense'];
    public const BS_TYPES = ['Bank', 'Accounts Receivable', 'Other Current Asset', 'Fixed Asset', 'Other Asset',
        'Accounts Payable', 'Credit Card', 'Other Current Liability', 'Long Term Liability', 'Equity'];

    public static function load(int $id): ?array
    {
        return DB::row('SELECT * FROM budgets WHERE id = :id', ['id' => $id]);
    }

    public static function all(string $kind): array
    {
        return DB::all('SELECT b.*, c.name AS class_name, cu.name AS customer_name
                          FROM budgets b LEFT JOIN classes c ON c.id = b.class_id LEFT JOIN customers cu ON cu.id = b.customer_id
                         WHERE b.kind = :k ORDER BY b.fiscal_year DESC, b.name', ['k' => $kind]);
    }

    /** name => id, newest year first */
    public static function options(string $kind): array
    {
        $out = [];
        foreach (self::all($kind) as $b) {
            $out[(int)$b['id']] = $b['name'] . ' (' . self::yearLabel($b) . ')' . ($b['class_name'] ? ', ' . $b['class_name'] : '');
        }
        return $out;
    }

    public static function yearLabel(array $b): string
    {
        $start = (int)$b['start_month'];
        return $start === 1 ? (string)(int)$b['fiscal_year']
            : date('M Y', mktime(0, 0, 0, $start, 1, (int)$b['fiscal_year'])) . ' - ' . date('M Y', mktime(0, 0, 0, $start + 11, 1, (int)$b['fiscal_year']));
    }

    /** The twelve periods of a budget: [['key' => 1, 'label' => 'Jul 2026', 'from' => .., 'to' => ..], ...] */
    public static function periods(array $b): array
    {
        $out = [];
        for ($i = 1; $i <= 12; $i++) {
            $ts = mktime(0, 0, 0, (int)$b['start_month'] + $i - 1, 1, (int)$b['fiscal_year']);
            $out[] = ['key' => $i, 'label' => date('M Y', $ts), 'short' => date('M', $ts),
                'from' => date('Y-m-01', $ts), 'to' => date('Y-m-t', $ts)];
        }
        return $out;
    }

    /** Saved amounts: [account_id][customer_id|0][month] = amount */
    public static function lines(int $budgetId): array
    {
        $out = [];
        foreach (DB::all('SELECT * FROM budget_lines WHERE budget_id = :b', ['b' => $budgetId]) as $l) {
            $out[(int)$l['account_id']][(int)($l['customer_id'] ?? 0)][(int)$l['period_month']] = (float)$l['amount'];
        }
        return $out;
    }

    /** Accounts a budget of this kind can hold */
    public static function accounts(string $kind): array
    {
        $types = $kind === 'bs' ? self::BS_TYPES : self::PL_TYPES;
        if ($kind === 'forecast') {
            $types = ['Income', 'Other Income'];
        }
        [$in, $params] = DB::in('t', $types);
        return DB::all("SELECT id, code, name, account_type FROM accounts WHERE is_active = 1 AND account_type IN $in ORDER BY code", $params);
    }

    /**
     * Actual figures from the ledger for one budget, the same shape as lines():
     * [account_id][customer_id|0][month] = amount, income and equity shown as positive.
     */
    public static function actual(array $b): array
    {
        $periods = self::periods($b);
        $from = $periods[0]['from'];
        $to = $periods[11]['to'];
        $byCustomer = $b['kind'] === 'forecast';
        $balanceSheet = $b['kind'] === 'bs';
        $params = ['f' => $from, 't' => $to];
        $where = 'j.txn_date BETWEEN :f AND :t';
        if (!empty($b['class_id'])) {
            $where .= ' AND j.class_id = :cl';
            $params['cl'] = (int)$b['class_id'];
        }
        if ($byCustomer && !empty($b['customer_id'])) {
            $where .= ' AND j.customer_id = :cu';
            $params['cu'] = (int)$b['customer_id'];
        }
        $sql = "SELECT j.account_id, a.account_type, " . ($byCustomer ? 'COALESCE(j.customer_id, 0) AS cust,' : '0 AS cust,') . "
                       YEAR(j.txn_date) AS y, MONTH(j.txn_date) AS m, SUM(j.debit - j.credit) AS amt
                  FROM journal_lines j JOIN accounts a ON a.id = j.account_id
                 WHERE $where
                 GROUP BY j.account_id, a.account_type, cust, y, m";
        $index = [];
        foreach ($periods as $p) {
            $index[substr($p['from'], 0, 7)] = $p['key'];
        }
        $out = [];
        foreach (DB::all($sql, $params) as $r) {
            $key = sprintf('%04d-%02d', (int)$r['y'], (int)$r['m']);
            if (!isset($index[$key])) {
                continue;
            }
            $amt = (float)$r['amt'];
            // income, liabilities and equity are kept the way they are read: income positive, liabilities positive
            if (in_array($r['account_type'], ['Income', 'Other Income', 'Accounts Payable', 'Credit Card', 'Other Current Liability', 'Long Term Liability', 'Equity'], true)) {
                $amt = -$amt;
            }
            $out[(int)$r['account_id']][(int)$r['cust']][$index[$key]] = round(($out[(int)$r['account_id']][(int)$r['cust']][$index[$key]] ?? 0) + $amt, 2);
        }
        if ($balanceSheet) {
            // a balance sheet account is a balance, not a movement: add the opening balance and carry each month forward
            $opening = [];
            $op = ['d' => date('Y-m-d', strtotime($from . ' -1 day'))];
            $opWhere = 'j.txn_date <= :d';
            if (!empty($b['class_id'])) {
                $opWhere .= ' AND j.class_id = :cl';
                $op['cl'] = (int)$b['class_id'];
            }
            foreach (DB::all("SELECT j.account_id, a.account_type, SUM(j.debit - j.credit) AS amt FROM journal_lines j JOIN accounts a ON a.id = j.account_id
                               WHERE $opWhere GROUP BY j.account_id, a.account_type", $op) as $r) {
                $amt = (float)$r['amt'];
                if (in_array($r['account_type'], ['Accounts Payable', 'Credit Card', 'Other Current Liability', 'Long Term Liability', 'Equity'], true)) {
                    $amt = -$amt;
                }
                $opening[(int)$r['account_id']] = $amt;
            }
            foreach (array_unique(array_merge(array_keys($out), array_keys($opening))) as $acc) {
                $run = $opening[$acc] ?? 0.0;
                for ($m = 1; $m <= 12; $m++) {
                    $run = round($run + ($out[$acc][0][$m] ?? 0), 2);
                    $out[$acc][0][$m] = $run;
                }
            }
        }
        return $out;
    }

    /** Saves the grid that was posted: $amounts[account_id][customer_id][month] */
    public static function saveLines(int $budgetId, array $amounts): int
    {
        return DB::tx(function () use ($budgetId, $amounts) {
            DB::run('DELETE FROM budget_lines WHERE budget_id = :b', ['b' => $budgetId]);
            $n = 0;
            foreach ($amounts as $accountId => $byCustomer) {
                foreach ((array)$byCustomer as $customerId => $months) {
                    foreach ((array)$months as $month => $value) {
                        $amt = r2(to_num($value));
                        if ($amt == 0.0 || (int)$month < 1 || (int)$month > 12) {
                            continue;
                        }
                        DB::insert('budget_lines', [
                            'budget_id' => $budgetId, 'account_id' => (int)$accountId,
                            'customer_id' => (int)$customerId ?: null, 'period_month' => (int)$month, 'amount' => $amt,
                        ]);
                        $n++;
                    }
                }
            }
            return $n;
        });
    }

    /** Copies last year's actual figures into a budget, so it can be adjusted instead of typed from nothing */
    public static function fillFromActual(array $b, float $pct = 0.0): array
    {
        $prev = $b;
        $prev['fiscal_year'] = (int)$b['fiscal_year'] - 1;
        $actual = self::actual($prev);
        $out = [];
        foreach ($actual as $acc => $byCust) {
            foreach ($byCust as $cust => $months) {
                foreach ($months as $m => $amt) {
                    $out[$acc][$cust][$m] = r2($amt * (1 + $pct / 100));
                }
            }
        }
        return $out;
    }
}
