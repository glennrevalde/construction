<?php
declare(strict_types=1);

/**
 * Find: looks up transactions from every module by any combination of what is known about them.
 *
 * It works the way the Find window of QuickBooks does. The Simple tab asks for a transaction type
 * and the handful of things that fit it (the name, the dates, the number, the amount). The Advanced
 * tab lists every filter there is, alphabetically; each one picked is added to the Current Choices,
 * and all of them have to hold for a transaction to be found. A filter that takes a list (accounts,
 * names, items, types ...) finds a transaction that matches any of the ones ticked.
 *
 * The choices travel in the address as f[filter][...], so a search can be bookmarked, opened as a
 * report (rpt.find), printed, exported and memorized like anything else.
 *
 * Only the transactions a user is allowed to open are ever found: the type's own page has to be open
 * to them, and the transaction has to be at one of their sites.
 */
final class Find
{
    /** Rows shown on the Find page at a time */
    public const LIMIT = 200;
    /** Rows on the printable report */
    public const REPORT_LIMIT = 5000;

    /** Types that never post to the books */
    public const NON_POSTING = ['estimate', 'sales_order', 'purchase_order'];
    /** Types that carry a due date and an open balance */
    public const DUE_TYPES = ['invoice', 'statement_charge', 'finance_charge', 'bill'];

    // ------------------------------------------------------------------ the filters

    /**
     * Every filter: key => [label, kind, what it finds].
     * kinds: pick   tick any number of things (none ticked = all of them)
     *        choice one of a short list (nothing chosen = either)
     *        range  a date range
     *        number a figure compared: =, at most, at least, between
     *        text   words: contains, is exactly, starts with (Number also: from ... to)
     * Listed alphabetically by label, the way they appear on the Advanced tab.
     */
    public static function catalogue(): array
    {
        static $cat = null;
        if ($cat !== null) {
            return $cat;
        }
        $cat = [
            'account'          => ['Account', 'pick', 'Transactions that post to, or are entered against, any of these accounts.'],
            'aging'            => ['Aging (days past due)', 'number', 'Open invoices, statement charges, finance charges and bills this many days past their due date.'],
            'amount'           => ['Amount', 'number', 'The total of the transaction (a journal entry, adjustment or transfer counts its debits).'],
            'approved_by'      => ['Approved by', 'pick', 'Transactions a supervisor approved: discounts, price changes, amounts over a limit.'],
            'billing'          => ['Billing status', 'choice', 'Costs marked billable to a customer, and whether they were billed yet.'],
            'cleared'          => ['Cleared (bank)', 'choice', 'Whether the bank or credit-card lines of the transaction were cleared in a reconciliation.'],
            'customer_type'    => ['Customer type', 'pick', 'Transactions of customers of these types.'],
            'date'             => ['Date', 'range', 'The date of the transaction.'],
            'detail'           => ['Detail level', 'choice', 'One line for each transaction, or every line of each transaction.'],
            'due_date'         => ['Due date', 'range', 'When an invoice, charge or bill falls due.'],
            'entered'          => ['Entered / modified', 'range', 'When the transaction was first entered, or last changed.'],
            'entered_by'       => ['Entered by', 'pick', 'The user who entered the transaction (at the POS, the cashier).'],
            'asset'            => ['Fixed asset', 'pick', 'Depreciation, disposals and maintenance of these assets, and the purchases that brought them in.'],
            'batch'            => ['Import batch', 'text', 'Transactions brought in by Import from CSV, by their batch reference.'],
            'item'             => ['Item', 'pick', 'Transactions with a line for any of these items (inactive items included).'],
            'memo'             => ['Memo', 'text', 'Words in the memo of the transaction or in the description of any of its lines.'],
            'module'           => ['Module', 'pick', 'Transactions entered in these parts of the system.'],
            'name'             => ['Name', 'pick', 'The customer, supplier, employee or other name on the transaction or on any of its lines.'],
            'name_account'     => ['Name account no. / notes', 'text', 'The supplier account number, or words in the notes, of the name on the transaction.'],
            'name_address'     => ['Name address', 'text', 'The street, city or province of the name on the transaction (billing and shipping addresses too).'],
            'name_contact'     => ['Name contact person / company', 'text', 'The contact person or company name of the customer or supplier on the transaction.'],
            'name_email'       => ['Name e-mail', 'text', 'The e-mail address of the name on the transaction.'],
            'name_phone'       => ['Name phone / mobile / fax', 'text', 'Any phone, mobile or fax number of the name on the transaction.'],
            'name_tin'         => ['Name TIN', 'text', 'The tax identification number of the name on the transaction.'],
            'number'           => ['Number', 'text', 'The document number (or the POS receipt number).'],
            'paid'             => ['Paid status', 'choice', 'Invoices, charges and bills still open, or paid in full.'],
            'payment_method'   => ['Payment method', 'pick', 'Payments, receipts and POS sales paid by these methods.'],
            'posting'          => ['Posting status', 'choice', 'Transactions that post to the books, or those that do not (estimates, orders, suspended sales).'],
            'preferred_vendor' => ['Preferred supplier', 'pick', 'Transactions with items whose preferred supplier is one of these.'],
            'printed'          => ['Printed status', 'choice', 'Documents printed, marked to be printed, or not printed.'],
            'qty'              => ['Quantity', 'number', 'Transactions with a line of this quantity.'],
            'received'         => ['Received (purchase orders)', 'choice', 'Purchase orders received in full, or still waiting for goods.'],
            'ref'              => ['Reference / P.O. no.', 'text', 'The reference: the customer\'s P.O. number, the supplier\'s invoice number, the check reference.'],
            'rep'              => ['Sales rep', 'pick', 'Sales documents of these sales reps.'],
            'ship_date'        => ['Ship date', 'range', 'The ship date of an invoice or order.'],
            'site'             => ['Site', 'pick', 'Transactions at these sites (a transfer counts at both ends).'],
            'status'           => ['Status', 'pick', 'The status the document stands at: open, partly paid, paid, accepted, closed and so on.'],
            'tax_code'         => ['Tax code', 'pick', 'Transactions with a line under any of these tax codes.'],
            'terminal'         => ['POS terminal', 'pick', 'Sales, returns and payments taken at these counters.'],
            'terms'            => ['Terms', 'pick', 'Invoices, bills and orders on these terms.'],
            'type'             => ['Transaction type', 'pick', 'Invoices, bills, checks, journal entries ... any number of types at once.'],
            'voided'           => ['Voided', 'choice', 'Leave voided transactions out, or find only those.'],
        ];
        if (Classes::ready()) {
            $cat['class'] = ['Class', 'pick', 'Transactions, or lines, carrying these classes. "No class" finds the unclassified.'];
        }
        uasort($cat, fn($a, $b) => strcasecmp($a[0], $b[0]));
        return $cat;
    }

    /** The two-choice and three-choice filters: key => [value => label] */
    public const CHOICES = [
        'billing'  => ['unbilled' => 'Billable, not billed yet', 'billed' => 'Billable, already billed', 'none' => 'Not billable'],
        'cleared'  => ['cleared' => 'Cleared', 'uncleared' => 'Not cleared yet'],
        'detail'   => ['summary' => 'Summary: one line for each transaction', 'lines' => 'Every line of each transaction'],
        'paid'     => ['open' => 'Open (not paid in full)', 'paid' => 'Paid in full'],
        'posting'  => ['posting' => 'Posting', 'nonposting' => 'Non-posting (estimates, orders, suspended sales)'],
        'printed'  => ['printed' => 'Printed', 'to_print' => 'Marked to be printed', 'not' => 'Not printed'],
        'received' => ['open' => 'Not received in full', 'full' => 'Received in full'],
        'voided'   => ['no' => 'Leave voided out', 'yes' => 'Voided only'],
    ];

    public const NUMBER_OPS = ['eq' => '=', 'le' => 'at most', 'ge' => 'at least', 'between' => 'between'];
    public const TEXT_MODES = ['contains' => 'contains', 'is' => 'is exactly', 'starts' => 'starts with'];
    public const RANGE_PRESETS = [
        'today' => 'Today', 'this_week' => 'This week', 'this_month' => 'This month', 'last_month' => 'Last month',
        'this_quarter' => 'This quarter', 'this_year' => 'This fiscal year', 'year_to_date' => 'Fiscal year to date',
        'last_year' => 'Last fiscal year', 'custom' => 'Custom',
    ];

    /** What the picker of a filter offers: value => label */
    public static function options(string $key): array
    {
        static $cache = [];
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $out = match ($key) {
            'account' => [
                'type:Bank' => 'All bank accounts', 'type:Accounts Receivable' => 'All accounts receivable',
                'type:Accounts Payable' => 'All accounts payable', 'type:Credit Card' => 'All credit-card accounts',
                'type:Income' => 'All income accounts', 'type:Cost of Goods Sold' => 'All cost of sales accounts',
                'type:Expense' => 'All expense accounts', 'type:Fixed Asset' => 'All fixed asset accounts',
            ] + self::accountOptions(),
            'name' => ['all:c' => 'All customers', 'all:v' => 'All suppliers', 'all:e' => 'All employees', 'all:o' => 'All other names']
                + self::nameOptions(),
            'type' => self::typeOptions(),
            'module' => self::moduleOptions(),
            'item' => self::itemOptions(),
            'class' => ['none' => 'No class (unclassified)'] + Classes::options(),
            'site' => DB::pairs('SELECT id, name FROM sites ORDER BY name'),
            'customer_type' => Lookup::customerTypes(),
            'terms' => DB::pairs('SELECT id, name FROM terms ORDER BY net_days, name'),
            'rep' => DB::pairs('SELECT id, name FROM sales_reps ORDER BY name'),
            'payment_method' => DB::pairs('SELECT id, name FROM payment_methods ORDER BY name'),
            'tax_code' => DB::pairs("SELECT id, CONCAT(code, ' - ', name) FROM tax_codes ORDER BY code"),
            'terminal' => DB::pairs("SELECT id, CONCAT(code, ' - ', name) FROM pos_terminals ORDER BY code"),
            'entered_by', 'approved_by' => DB::pairs("SELECT id, CONCAT(full_name, ' (', username, ')') FROM users ORDER BY full_name"),
            'preferred_vendor' => DB::pairs('SELECT id, name FROM vendors ORDER BY name'),
            'asset' => DB::pairs("SELECT id, CONCAT(description, ' (', asset_no, ')') FROM fixed_assets ORDER BY description"),
            'status' => [
                'open' => 'Open', 'partial' => 'Partly paid', 'paid' => 'Paid', 'pending' => 'Pending',
                'accepted' => 'Accepted', 'closed' => 'Closed', 'completed' => 'Completed', 'suspended' => 'Suspended',
            ],
            default => [],
        };
        return $cache[$key] = array_map('strval', $out);
    }

    private static function accountOptions(): array
    {
        $out = [];
        foreach (DB::all('SELECT id, code, name, is_active FROM accounts ORDER BY code') as $a) {
            $out[(string)$a['id']] = $a['code'] . ' - ' . $a['name'] . ((int)$a['is_active'] ? '' : ' (inactive)');
        }
        return $out;
    }

    private static function nameOptions(): array
    {
        $out = [];
        foreach (DB::all("SELECT c.id, CASE WHEN p.id IS NULL THEN c.name ELSE CONCAT(p.name, ':', c.name) END AS n, c.is_active
                            FROM customers c LEFT JOIN customers p ON p.id = c.parent_id
                           ORDER BY COALESCE(p.name, c.name), c.parent_id IS NOT NULL, c.name") as $r) {
            $out['c' . $r['id']] = 'Customer: ' . $r['n'] . ((int)$r['is_active'] ? '' : ' (inactive)');
        }
        foreach (DB::all('SELECT id, name, is_active FROM vendors ORDER BY name') as $r) {
            $out['v' . $r['id']] = 'Supplier: ' . $r['name'] . ((int)$r['is_active'] ? '' : ' (inactive)');
        }
        try {
            foreach (DB::all('SELECT id, name, name_type, is_active FROM other_names ORDER BY name') as $r) {
                $out['o' . $r['id']] = ($r['name_type'] === 'employee' ? 'Employee: ' : 'Other: ') . $r['name']
                    . ((int)$r['is_active'] ? '' : ' (inactive)');
            }
        } catch (Throwable $e) {
            // Employees & Other Names comes with the class migration
        }
        return $out;
    }

    private static function itemOptions(): array
    {
        $out = [];
        foreach (DB::all('SELECT id, code, name, is_active FROM items ORDER BY code') as $r) {
            $out[(string)$r['id']] = item_label($r['code'], $r['name'], 'home.find') . ((int)$r['is_active'] ? '' : ' (inactive)');
        }
        return Lookup::sortLabels($out);
    }

    /** The transaction types this user may open, by label */
    public static function typeOptions(): array
    {
        $out = [];
        foreach (self::allowedTypes() as $t) {
            $out[$t] = Txn::label($t);
        }
        asort($out);
        return $out;
    }

    /** The modules that have transaction types this user may open */
    private static function moduleOptions(): array
    {
        $names = DB::pairs('SELECT code, name FROM modules ORDER BY sort_order');
        $out = [];
        foreach (self::allowedTypes() as $t) {
            $m = Txn::module($t);
            if (isset($names[$m])) {
                $out[$m] = $names[$m];
            }
        }
        return array_intersect_key($names, $out);
    }

    /** Transaction types whose own page this user may open: nothing else is ever found */
    public static function allowedTypes(): array
    {
        static $types = null;
        if ($types === null) {
            $types = [];
            foreach (Txn::TYPES as $t => $def) {
                if (Access::page($def[1])) {
                    $types[] = $t;
                }
            }
        }
        return $types;
    }

    // ------------------------------------------------------------------ reading the choices

    /**
     * The filters that are set, cleaned up: key => the values that matter for that kind.
     * Anything unknown, empty or invalid is left out, so an address typed by hand cannot break the search.
     */
    public static function parse(array $f): array
    {
        $cat = self::catalogue();
        $out = [];
        foreach ($cat as $key => [$label, $kind]) {
            $v = $f[$key] ?? null;
            if (!is_array($v)) {
                continue;
            }
            switch ($kind) {
                case 'pick':
                    $vals = array_values(array_unique(array_filter(array_map('strval', (array)($v['v'] ?? [])), fn($x) => $x !== '')));
                    $allowed = self::options($key);
                    $vals = array_values(array_filter($vals, fn($x) => isset($allowed[$x])));
                    if ($vals) {
                        $out[$key] = ['v' => $vals];
                    }
                    break;
                case 'choice':
                    $c = (string)($v['v'] ?? '');
                    // one line for each transaction is what Find does anyway, so it is not a choice to list
                    if (isset(self::CHOICES[$key][$c]) && !($key === 'detail' && $c === 'summary')) {
                        $out[$key] = ['v' => $c];
                    }
                    break;
                case 'range':
                    $preset = (string)($v['preset'] ?? '');
                    $from = (string)($v['from'] ?? '');
                    $to = (string)($v['to'] ?? '');
                    if ($preset !== '' && $preset !== 'custom' && isset(self::RANGE_PRESETS[$preset])) {
                        [$from, $to] = Report::preset($preset);
                    } else {
                        $preset = '';
                    }
                    $from = valid_date($from) ? $from : '';
                    $to = valid_date($to) ? $to : '';
                    if ($from !== '' || $to !== '') {
                        $out[$key] = ['from' => $from, 'to' => $to, 'preset' => $preset];
                        if ($key === 'entered') {
                            $out[$key]['which'] = ($v['which'] ?? '') === 'modified' ? 'modified' : 'entered';
                        }
                    }
                    break;
                case 'number':
                    $op = (string)($v['op'] ?? '');
                    if (!isset(self::NUMBER_OPS[$op])) {
                        break;
                    }
                    $a = trim((string)($v['a'] ?? ''));
                    $b = trim((string)($v['b'] ?? ''));
                    if ($a === '' || !is_numeric(str_replace([',', ' '], '', $a))) {
                        break;
                    }
                    if ($op === 'between' && ($b === '' || !is_numeric(str_replace([',', ' '], '', $b)))) {
                        break;
                    }
                    $out[$key] = ['op' => $op, 'a' => to_num($a), 'b' => $op === 'between' ? to_num($b) : null];
                    if ($op === 'between' && $out[$key]['b'] < $out[$key]['a']) {
                        [$out[$key]['a'], $out[$key]['b']] = [$out[$key]['b'], $out[$key]['a']];
                    }
                    break;
                case 'text':
                    $q = mb_substr(trim((string)($v['q'] ?? '')), 0, 120);
                    $mode = (string)($v['mode'] ?? 'contains');
                    $modes = self::TEXT_MODES + ($key === 'number' ? ['range' => 'from ... to'] : []);
                    if (!isset($modes[$mode])) {
                        $mode = 'contains';
                    }
                    $q2 = mb_substr(trim((string)($v['q2'] ?? '')), 0, 120);
                    if ($q !== '' || ($mode === 'range' && $q2 !== '')) {
                        $out[$key] = ['q' => $q, 'mode' => $mode] + ($mode === 'range' ? ['q2' => $q2] : []);
                    }
                    break;
            }
        }
        return $out;
    }

    /** The Current Choices, in words: [key => [label, what it is set to]] */
    public static function describe(array $c): array
    {
        $cat = self::catalogue();
        $out = [];
        foreach ($c as $key => $v) {
            if (!isset($cat[$key])) {
                continue;
            }
            [$label, $kind] = $cat[$key];
            $out[$key] = [$label, match ($kind) {
                'pick' => self::pickWords($key, $v['v']),
                'choice' => self::CHOICES[$key][$v['v']],
                'range' => self::rangeWords($v),
                'number' => self::numberWords($key, $v),
                'text' => self::textWords($v),
                default => '',
            }];
        }
        return $out;
    }

    private static function pickWords(string $key, array $vals): string
    {
        $opts = self::options($key);
        $names = array_map(fn($x) => $opts[$x] ?? $x, $vals);
        if (count($names) <= 3) {
            return implode(', ', $names);
        }
        return implode(', ', array_slice($names, 0, 2)) . ' and ' . (count($names) - 2) . ' more';
    }

    private static function rangeWords(array $v): string
    {
        $what = isset($v['which']) && $v['which'] === 'modified' ? 'Modified ' : (isset($v['which']) ? 'Entered ' : '');
        if (($v['preset'] ?? '') !== '') {
            return $what . self::RANGE_PRESETS[$v['preset']] . ' (' . fdate($v['from']) . ' to ' . fdate($v['to']) . ')';
        }
        if ($v['from'] !== '' && $v['to'] !== '') {
            return $what . fdate($v['from']) . ' to ' . fdate($v['to']);
        }
        return $what . ($v['from'] !== '' ? 'From ' . fdate($v['from']) : 'Up to ' . fdate($v['to']));
    }

    private static function numberWords(string $key, array $v): string
    {
        $f = fn($n) => $key === 'aging' || $key === 'qty' ? qty($n) : money($n);
        $unit = $key === 'aging' ? ' days' : '';
        return match ($v['op']) {
            'eq' => '= ' . $f($v['a']) . $unit,
            'le' => 'At most ' . $f($v['a']) . $unit,
            'ge' => 'At least ' . $f($v['a']) . $unit,
            'between' => $f($v['a']) . ' to ' . $f($v['b']) . $unit,
            default => '',
        };
    }

    private static function textWords(array $v): string
    {
        if ($v['mode'] === 'range') {
            return ($v['q'] !== '' ? 'From ' . $v['q'] : '') . ($v['q'] !== '' && $v['q2'] !== '' ? ' ' : '')
                . ($v['q2'] !== '' ? ($v['q'] !== '' ? 'to ' : 'Up to ') . $v['q2'] : '');
        }
        return ucfirst(self::TEXT_MODES[$v['mode']]) . ' "' . $v['q'] . '"';
    }

    /** The choices as they go into an address again (for Report, Export, the Simple/Advanced switch) */
    public static function toQuery(array $c): array
    {
        $out = [];
        foreach ($c as $key => $v) {
            if (isset($v['v']) && is_array($v['v'])) {
                $out[$key] = ['v' => $v['v']];
            } else {
                $out[$key] = array_filter($v, fn($x) => $x !== null && $x !== '');
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ the search itself

    /**
     * Runs the search.
     * @return array ['rows' => [...], 'count' => all that match, 'total' => their amount, 'more' => bool]
     *   each row: txn_id, txn_type, type_label, num, txn_date, name, account, memo, amount, is_voided, status, url
     */
    public static function run(array $c, int $limit = self::LIMIT, int $offset = 0, string $sort = 'date', string $dir = 'desc'): array
    {
        $lines = ($c['detail']['v'] ?? 'summary') === 'lines';
        [$where, $p] = self::where($c, $lines);
        $amount = $lines ? self::lineAmountSql() : self::amountSql();

        $from = 'FROM transactions t'
            . ($lines ? ' LEFT JOIN transaction_lines l ON l.txn_id = t.id' : '')
            . ' LEFT JOIN customers c ON c.id = t.customer_id LEFT JOIN customers pc ON pc.id = c.parent_id'
            . ' LEFT JOIN vendors v ON v.id = t.vendor_id'
            . (self::hasOtherNames() ? ' LEFT JOIN other_names o ON o.id = t.other_name_id' : '')
            . ($lines
                ? ' LEFT JOIN accounts a ON a.id = COALESCE(l.account_id, t.account_id) LEFT JOIN items i ON i.id = l.item_id'
                : ' LEFT JOIN accounts a ON a.id = t.account_id');

        $sum = DB::row("SELECT COUNT(*) AS n, COALESCE(SUM($amount), 0) AS total $from WHERE $where", $p);

        $order = [
            'date' => 't.txn_date', 'type' => 't.txn_type', 'num' => 't.txn_no',
            'name' => 'name', 'account' => 'account', 'memo' => 'memo', 'amount' => 'amount',
        ][$sort] ?? 't.txn_date';
        $dirSql = $dir === 'asc' ? 'ASC' : 'DESC';
        $name = "COALESCE(CASE WHEN pc.id IS NULL THEN c.name ELSE CONCAT(pc.name, ':', c.name) END, v.name"
            . (self::hasOtherNames() ? ', o.name' : '') . ', t.payee_name)';
        $account = $lines
            ? "COALESCE(CONCAT(a.code, ' - ', a.name), CONCAT(i.code, ' - ', i.name))"
            : "CONCAT(a.code, ' - ', a.name)";
        $memo = $lines ? 'COALESCE(NULLIF(l.description, \'\'), t.memo)' : 't.memo';

        $rows = DB::all(
            "SELECT t.id AS txn_id, t.txn_type, t.txn_no, t.receipt_no, t.txn_date, t.is_voided, t.status,
                    $name AS name, $account AS account, $memo AS memo, $amount AS amount
               $from
              WHERE $where
              ORDER BY $order $dirSql, t.id $dirSql" . ($lines ? ', l.line_no, l.id' : '') . '
              LIMIT ' . (int)max(1, $limit + 1) . ' OFFSET ' . (int)max(0, $offset),
            $p
        );
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        foreach ($rows as &$r) {
            $r['type_label'] = Txn::label($r['txn_type']);
            $r['num'] = (string)($r['txn_no'] ?: ($r['receipt_no'] ?: '#' . $r['txn_id']));
            $r['url'] = Txn::url(['txn_type' => $r['txn_type'], 'id' => $r['txn_id']]);
            $r['amount'] = (float)$r['amount'];
        }
        unset($r);
        return ['rows' => $rows, 'count' => (int)$sum['n'], 'total' => r2((float)$sum['total']), 'more' => $more];
    }

    /** The figure a transaction is found by and shown with: its total, or the debits of an entry that has none */
    private static function amountSql(): string
    {
        return "CASE WHEN t.total <> 0 THEN t.total
                     ELSE (SELECT COALESCE(SUM(jd.debit), 0) FROM journal_lines jd WHERE jd.txn_id = t.id) END";
    }

    private static function lineAmountSql(): string
    {
        return 'CASE WHEN l.id IS NULL THEN ' . self::amountSql() . ' ELSE l.amount END';
    }

    private static function hasOtherNames(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                DB::value('SELECT COUNT(other_name_id) FROM transactions WHERE 1 = 0');
                $ok = true;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    /**
     * The WHERE clause for a set of choices. Every filter adds one condition, and all of them must hold.
     * A filter about the lines of a transaction (item, account, class ...) looks at the transaction's lines:
     * one of them has to match. With every line shown (Detail level), it picks the matching lines themselves.
     */
    public static function where(array $c, bool $lines = false): array
    {
        $w = [];
        $p = [];
        $n = 0;
        $bind = function ($v) use (&$p, &$n): string {
            $k = 'fp' . (++$n);
            $p[$k] = $v;
            return ':' . $k;
        };
        $in = function (array $vals) use (&$p, &$n): string {
            $ph = [];
            foreach ($vals as $v) {
                $k = 'fp' . (++$n);
                $p[$k] = $v;
                $ph[] = ':' . $k;
            }
            return '(' . ($ph ? implode(',', $ph) : 'NULL') . ')';
        };
        $like = function (array $v) use ($bind): array {
            $q = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v['q']);
            return match ($v['mode']) {
                'is' => ['= ' . $bind($v['q'])],
                'starts' => ['LIKE ' . $bind($q . '%')],
                default => ['LIKE ' . $bind('%' . $q . '%')],
            };
        };
        $cmp = function (string $expr, array $v) use ($bind): string {
            return match ($v['op']) {
                'eq' => "ABS($expr - " . $bind($v['a']) . ') < 0.005',
                'le' => "$expr <= " . $bind($v['a']),
                'ge' => "$expr >= " . $bind($v['a']),
                'between' => "$expr BETWEEN " . $bind($v['a']) . ' AND ' . $bind($v['b']),
                default => '1 = 1',
            };
        };
        // a condition about the lines of the transaction
        $line = function (string $cond) use ($lines): string {
            return $lines
                ? '(' . str_replace('lx.', 'l.', $cond) . ')'
                : "EXISTS (SELECT 1 FROM transaction_lines lx WHERE lx.txn_id = t.id AND $cond)";
        };

        // ------------------------------------------------ what this user may see at all
        $types = self::allowedTypes();
        $w[] = 't.txn_type IN ' . $in($types ?: ['-none-']);
        [$site, $sp] = Access::siteFilter('t.site_id', 'fsite');
        if ($site !== '') {
            $w[] = preg_replace('/^\s*AND\s+/', '', $site);
            $p += $sp;
        }

        foreach ($c as $key => $v) {
            switch ($key) {
                // ---------------------------------------------- dates
                case 'date':
                case 'due_date':
                case 'ship_date':
                case 'entered':
                    $col = match ($key) {
                        'date' => 't.txn_date', 'due_date' => 't.due_date', 'ship_date' => 't.ship_date',
                        default => ($v['which'] ?? 'entered') === 'modified' ? 'DATE(COALESCE(t.updated_at, t.created_at))' : 'DATE(t.created_at)',
                    };
                    if ($v['from'] !== '') {
                        $w[] = "$col >= " . $bind($v['from']);
                    }
                    if ($v['to'] !== '') {
                        $w[] = "$col <= " . $bind($v['to']);
                    }
                    break;

                // ---------------------------------------------- figures
                case 'amount':
                    $w[] = $cmp('ABS(' . ($lines ? self::lineAmountSql() : self::amountSql()) . ')', $v);
                    break;
                case 'aging':
                    $w[] = 't.txn_type IN ' . $in(self::DUE_TYPES) . " AND t.status IN ('open', 'partial') AND t.is_voided = 0"
                        . ' AND t.due_date IS NOT NULL AND ' . $cmp('DATEDIFF(CURDATE(), t.due_date)', $v);
                    break;
                case 'qty':
                    $w[] = $line($cmp('ABS(lx.qty)', $v));
                    break;

                // ---------------------------------------------- words
                case 'number':
                    if ($v['mode'] === 'range') {
                        if ($v['q'] !== '') {
                            $w[] = 'COALESCE(t.txn_no, t.receipt_no) >= ' . $bind($v['q']);
                        }
                        if (($v['q2'] ?? '') !== '') {
                            $w[] = 'COALESCE(t.txn_no, t.receipt_no) <= ' . $bind($v['q2']);
                        }
                    } else {
                        [$op] = $like($v);
                        [$op2] = $like($v);
                        $w[] = "(t.txn_no $op OR t.receipt_no $op2)";
                    }
                    break;
                case 'ref':
                    [$op] = $like($v);
                    $w[] = "t.ref_no $op";
                    break;
                case 'batch':
                    [$op] = $like($v);
                    $w[] = "t.batch_ref $op";
                    break;
                case 'memo':
                    [$op1] = $like($v);
                    [$op2] = $like($v);
                    [$op3] = $like($v);
                    $w[] = "(t.memo $op1 OR t.customer_message $op3 OR " . ($lines ? "l.description $op2" : "EXISTS (SELECT 1 FROM transaction_lines lm WHERE lm.txn_id = t.id AND lm.description $op2)") . ')';
                    break;

                // ---------------------------------------------- what is known about the name on the transaction
                case 'name_address':
                case 'name_email':
                case 'name_phone':
                case 'name_tin':
                case 'name_account':
                case 'name_contact':
                    $fields = [
                        'name_address' => [['c.billing_address', 'c.shipping_address'], ['v.address'], ['o.address'], ['t.billing_address', 't.shipping_address']],
                        'name_email'   => [['c.email'], ['v.email'], [], []],
                        'name_phone'   => [['c.phone', 'c.mobile'], ['v.phone', 'v.fax'], ['o.phone'], []],
                        'name_tin'     => [['c.tin'], ['v.tin'], ['o.tin'], []],
                        'name_account' => [['c.notes'], ['v.account_no', 'v.notes'], ['o.notes', 'o.employee_no'], []],
                        'name_contact' => [['c.contact_person', 'c.company_name'], ['v.contact_person', 'v.company_name'], [], []],
                    ][$key];
                    $or = [];
                    foreach ([0 => 'c', 1 => 'v', 2 => 'o'] as $i => $alias) {
                        if ($alias === 'o' && !self::hasOtherNames()) {
                            continue;
                        }
                        $table = ['c' => 'customers', 'v' => 'vendors', 'o' => 'other_names'][$alias];
                        $link = ['c' => 't.customer_id', 'v' => 't.vendor_id', 'o' => 't.other_name_id'][$alias];
                        $parts = [];
                        foreach ($fields[$i] as $col) {
                            [$op] = $like($v);
                            $parts[] = "$col $op";
                        }
                        if ($parts) {
                            $or[] = "EXISTS (SELECT 1 FROM $table $alias WHERE $alias.id = $link AND (" . implode(' OR ', $parts) . '))';
                        }
                    }
                    foreach ($fields[3] as $col) {
                        [$op] = $like($v);
                        $or[] = "$col $op";
                    }
                    $w[] = '(' . implode(' OR ', $or) . ')';
                    break;

                // ---------------------------------------------- lists
                case 'type':
                    $w[] = 't.txn_type IN ' . $in($v['v']);
                    break;
                case 'module':
                    $mt = array_values(array_filter(self::allowedTypes(), fn($t) => in_array(Txn::module($t), $v['v'], true)));
                    $w[] = 't.txn_type IN ' . $in($mt ?: ['-none-']);
                    break;
                case 'status':
                    $w[] = 't.status IN ' . $in($v['v']);
                    break;
                case 'terms':
                    $w[] = 't.terms_id IN ' . $in($v['v']);
                    break;
                case 'rep':
                    $w[] = 't.sales_rep_id IN ' . $in($v['v']);
                    break;
                case 'terminal':
                    $w[] = 't.terminal_id IN ' . $in($v['v']);
                    break;
                case 'entered_by':
                    $w[] = 't.created_by IN ' . $in($v['v']);
                    break;
                case 'approved_by':
                    $w[] = 't.approved_by IN ' . $in($v['v']);
                    break;
                case 'payment_method':
                    $w[] = '(t.payment_method_id IN ' . $in($v['v'])
                        . ' OR EXISTS (SELECT 1 FROM pos_tenders pt WHERE pt.txn_id = t.id AND pt.payment_method_id IN ' . $in($v['v']) . '))';
                    break;
                case 'customer_type':
                    $w[] = 'EXISTS (SELECT 1 FROM customers ct WHERE ct.id = t.customer_id AND ct.customer_type_id IN ' . $in($v['v']) . ')';
                    break;
                case 'site':
                    $sites = $in($v['v']);
                    $sites2 = $in($v['v']);
                    $w[] = '(t.site_id IN ' . $sites . ' OR t.site2_id IN ' . $sites2 . ' OR ' . $line('lx.site_id IN ' . $in($v['v'])) . ')';
                    break;
                case 'item':
                    $w[] = $line('lx.item_id IN ' . $in($v['v']));
                    break;
                case 'tax_code':
                    $w[] = $line('lx.tax_code_id IN ' . $in($v['v']));
                    break;
                case 'preferred_vendor':
                    $w[] = $line('lx.item_id IN (SELECT pi.id FROM items pi WHERE pi.preferred_vendor_id IN ' . $in($v['v']) . ')');
                    break;
                case 'class':
                    $ids = array_values(array_filter($v['v'], fn($x) => $x !== 'none'));
                    $or = [];
                    if ($ids) {
                        $or[] = 't.class_id IN ' . $in($ids);
                        $or[] = $line('lx.class_id IN ' . $in($ids));
                        $or[] = 'EXISTS (SELECT 1 FROM journal_lines jc WHERE jc.txn_id = t.id AND jc.class_id IN ' . $in($ids) . ')';
                    }
                    if (in_array('none', $v['v'], true)) {
                        $or[] = '(t.class_id IS NULL AND NOT EXISTS (SELECT 1 FROM transaction_lines lc WHERE lc.txn_id = t.id AND lc.class_id IS NOT NULL))';
                    }
                    $w[] = '(' . implode(' OR ', $or) . ')';
                    break;
                case 'account':
                    $ids = [];
                    $kinds = [];
                    foreach ($v['v'] as $x) {
                        if (str_starts_with($x, 'type:')) {
                            $kinds[] = substr($x, 5);
                        } else {
                            $ids[] = (int)$x;
                        }
                    }
                    // the accounts ticked, plus every account of the kinds ticked ("All bank accounts")
                    $accs = function () use ($ids, $kinds, $in): string {
                        $set = [];
                        if ($ids) {
                            $set[] = 'SELECT ' . implode(' AS id UNION SELECT ', array_map(fn($i) => (string)(int)$i, $ids)) . ' AS id';
                        }
                        if ($kinds) {
                            $set[] = 'SELECT ak.id FROM accounts ak WHERE ak.account_type IN ' . $in($kinds);
                        }
                        return implode(' UNION ', $set);
                    };
                    $w[] = '(t.account_id IN (' . $accs() . ') OR t.account2_id IN (' . $accs() . ')'
                        . ' OR EXISTS (SELECT 1 FROM journal_lines ja WHERE ja.txn_id = t.id AND ja.account_id IN (' . $accs() . '))'
                        . ' OR ' . $line('lx.account_id IN (' . $accs() . ')') . ')';
                    break;
                case 'name':
                    $or = [];
                    foreach ($v['v'] as $x) {
                        if ($x === 'all:c') {
                            $or[] = 't.customer_id IS NOT NULL';
                        } elseif ($x === 'all:v') {
                            $or[] = 't.vendor_id IS NOT NULL';
                        } elseif (($x === 'all:e' || $x === 'all:o') && self::hasOtherNames()) {
                            $kind = $x === 'all:e' ? "= 'employee'" : "<> 'employee'";
                            $or[] = "(EXISTS (SELECT 1 FROM other_names ox WHERE ox.id = t.other_name_id AND ox.name_type $kind)"
                                . " OR EXISTS (SELECT 1 FROM journal_lines jo JOIN other_names oy ON oy.id = jo.other_name_id WHERE jo.txn_id = t.id AND oy.name_type $kind))";
                        } else {
                            $id = (int)substr($x, 1);
                            $col = ['c' => 'customer_id', 'v' => 'vendor_id', 'o' => 'other_name_id'][$x[0]] ?? null;
                            if (!$col || ($col === 'other_name_id' && !self::hasOtherNames())) {
                                continue;
                            }
                            if ($col === 'customer_id') {
                                // a customer finds its jobs too
                                $a = $bind($id);
                                $b = $bind($id);
                                $or[] = "(t.customer_id = $a OR t.customer_id IN (SELECT cj.id FROM customers cj WHERE cj.parent_id = $b))";
                            } else {
                                $or[] = "t.$col = " . $bind($id);
                            }
                            $or[] = "EXISTS (SELECT 1 FROM journal_lines jn WHERE jn.txn_id = t.id AND jn.$col = " . $bind($id) . ')';
                        }
                    }
                    $w[] = $or ? '(' . implode(' OR ', $or) . ')' : '1 = 0';
                    break;
                case 'asset':
                    $a1 = $in($v['v']);
                    $a2 = $in($v['v']);
                    $a3 = $in($v['v']);
                    $w[] = '(EXISTS (SELECT 1 FROM asset_depreciation ad WHERE ad.txn_id = t.id AND ad.asset_id IN ' . $a1 . ')'
                        . ' OR EXISTS (SELECT 1 FROM asset_maintenance am WHERE am.txn_id = t.id AND am.asset_id IN ' . $a2 . ')'
                        . ' OR EXISTS (SELECT 1 FROM fixed_assets fa WHERE fa.id IN ' . $a3 . ' AND (fa.acquisition_txn_id = t.id OR fa.disposal_txn_id = t.id)))';
                    break;

                // ---------------------------------------------- the short choices
                case 'voided':
                    $w[] = 't.is_voided = ' . ($v['v'] === 'yes' ? '1' : '0');
                    break;
                case 'posting':
                    $np = 't.txn_type IN ' . $in(self::NON_POSTING) . " OR t.status = 'suspended'";
                    $w[] = $v['v'] === 'nonposting' ? "($np)" : "NOT ($np)";
                    break;
                case 'paid':
                    $w[] = 't.txn_type IN ' . $in(self::DUE_TYPES)
                        . ($v['v'] === 'paid' ? " AND t.status IN ('paid', 'closed')" : " AND t.status IN ('open', 'partial') AND t.is_voided = 0");
                    break;
                case 'received':
                    $w[] = "t.txn_type = 'purchase_order' AND t.status " . ($v['v'] === 'full' ? "= 'closed'" : "<> 'closed'");
                    break;
                case 'printed':
                    $w[] = match ($v['v']) {
                        'printed' => 't.is_printed = 1',
                        'to_print' => 't.to_be_printed = 1 AND t.is_printed = 0',
                        default => 't.is_printed = 0 AND t.to_be_printed = 0',
                    };
                    break;
                case 'billing':
                    $w[] = match ($v['v']) {
                        'unbilled' => $line('lx.is_billable = 1 AND lx.billed_txn_id IS NULL'),
                        'billed' => $line('lx.is_billable = 1 AND lx.billed_txn_id IS NOT NULL'),
                        default => 'NOT EXISTS (SELECT 1 FROM transaction_lines lb WHERE lb.txn_id = t.id AND lb.is_billable = 1)',
                    };
                    break;
                case 'cleared':
                    $w[] = 'EXISTS (SELECT 1 FROM journal_lines jb JOIN accounts ab ON ab.id = jb.account_id'
                        . " WHERE jb.txn_id = t.id AND ab.account_type IN ('Bank', 'Credit Card') AND jb.cleared = "
                        . ($v['v'] === 'cleared' ? '1' : '0') . ')';
                    break;
            }
        }
        return [implode(' AND ', array_map(fn($x) => "($x)", $w)), $p];
    }

    // ------------------------------------------------------------------ the Simple tab

    /**
     * What the Simple tab offers for each transaction type: the words the name and the number go by.
     * type => [name label, number label]
     */
    public static function simpleLabels(string $type): array
    {
        return match ($type) {
            'invoice' => ['Customer:Job', 'Invoice #'],
            'estimate' => ['Customer:Job', 'Estimate #'],
            'sales_order' => ['Customer:Job', 'Sales order #'],
            'sales_receipt' => ['Customer:Job', 'Sales receipt #'],
            'credit_memo' => ['Customer:Job', 'Credit memo #'],
            'payment' => ['Customer:Job', 'Payment #'],
            'statement_charge', 'finance_charge' => ['Customer:Job', 'Number'],
            'refund' => ['Customer:Job', 'Check #'],
            'bill' => ['Supplier', 'Bill #'],
            'vendor_credit' => ['Supplier', 'Debit memo #'],
            'purchase_order' => ['Supplier', 'P.O. #'],
            'item_receipt' => ['Supplier', 'Receipt #'],
            'bill_payment' => ['Supplier', 'Check #'],
            'vendor_advance' => ['Supplier', 'Advance #'],
            'check' => ['Payee', 'Check #'],
            'cash_voucher' => ['Paid to', 'Voucher #'],
            'pos_sale', 'pos_return' => ['Customer', 'Receipt #'],
            'journal', 'opening_balance' => ['Name', 'Entry #'],
            default => ['Name', 'Number'],
        };
    }
}
