<?php
declare(strict_types=1);

/**
 * Memorized transactions (Chart of Accounts > Memorized Transactions).
 *
 * A memorized transaction is a saved copy of a document: its header and its lines, without the
 * numbers that belong to one occasion (the document number, the dates, the payments applied).
 * It can be entered again at any time from the list, and on a schedule:
 *   - Automatically: the entry is recorded on its due date, the next time anyone opens the system.
 *   - Remind me: the Home page and the list show it as due; a user enters it with one click.
 *   - Don't remind: it only sits on the list, ready when it is needed.
 */
final class Memorized
{
    public const HOW = [
        'auto'   => 'Enter it automatically',
        'remind' => 'Remind me when it is due',
        'none'   => "Don't remind me (I enter it from the list)",
    ];

    /** How it is entered, in a few words for the list */
    public static function howShort(array $m): string
    {
        if ($m['how'] === 'none') {
            return self::formOnly((string)$m['txn_type']) ? 'Loaded when needed' : 'When needed';
        }
        return ['auto' => 'Automatically', 'remind' => 'Reminder'][$m['how']] ?? '';
    }

    public const FREQ = [
        'daily'     => 'Day(s)',
        'weekly'    => 'Week(s)',
        'monthly'   => 'Month(s)',
        'quarterly' => 'Quarter(s)',
        'yearly'    => 'Year(s)',
    ];

    /** Transaction types that can be memorized: everything with lines that a form can re-enter */
    public const TYPES = ['invoice', 'sales_receipt', 'estimate', 'sales_order', 'statement_charge', 'credit_memo',
        'bill', 'vendor_credit', 'purchase_order', 'check', 'journal', 'cash_voucher', 'item_receipt'];

    /**
     * Pro-formas that are only ever loaded into their form, changed there and saved from there: an inventory
     * adjustment (the quantities depend on the day's count) and a batch of invoices (one per customer).
     * type => the page whose form they are loaded into
     */
    public const FORM_ONLY = ['inventory_adjustment' => 'inv.adjust', 'batch_invoice' => 'cust.batch_invoices'];

    /** Set while a form is saved with "Memorize": Txn::save keeps a copy instead of recording the transaction */
    public static bool $capture = false;

    /** Header fields worth keeping: dates, numbers and running balances are left out on purpose */
    private const KEEP_HEADER = ['customer_id', 'vendor_id', 'other_name_id', 'account_id', 'account2_id', 'class_id', 'site_id', 'site2_id',
        'terms_id', 'sales_rep_id', 'payment_method_id', 'payee_name', 'memo', 'customer_message', 'billing_address', 'shipping_address',
        'ref_no', 'subtotal', 'tax_total', 'discount_amount', 'withholding_amount', 'total', 'restock'];

    private const KEEP_LINE = ['line_kind', 'item_id', 'account_id', 'description', 'qty', 'unit', 'rate', 'uom_id', 'uom_factor', 'uom_qty', 'uom_rate',
        'discount_kind', 'discount_pct', 'discount_amount', 'amount', 'tax_code_id', 'tax_amount', 'site_id', 'customer_id', 'vendor_id',
        'other_name_id', 'class_id', 'is_billable', 'output_role', 'alloc_basis', 'planned_qty',
        'group_item_id', 'group_ref', 'group_qty', 'group_rate', 'group_role'];

    public static function load(int $id): ?array
    {
        return DB::row('SELECT * FROM memorized_txns WHERE id = :id', ['id' => $id]);
    }

    public static function formOnly(string $type): bool
    {
        return isset(self::FORM_ONLY[$type]);
    }

    public static function typeLabel(string $type): string
    {
        return ['batch_invoice' => 'Batch of invoices'][$type] ?? Txn::label($type);
    }

    /** The page whose form a memorized transaction of this type is loaded into */
    public static function formPage(string $type): ?string
    {
        if (isset(self::FORM_ONLY[$type])) {
            return self::FORM_ONLY[$type];
        }
        return isset(Txn::TYPES[$type]) ? Txn::page($type) : null;
    }

    /** The address that opens the form with a memorized transaction in it */
    public static function loadUrl(array $m, ?string $pageKey = null): ?string
    {
        $page = $pageKey ?: self::formPage((string)$m['txn_type']);
        if (!$page) {
            return null;
        }
        $params = ['mem' => (int)$m['id']];
        if (in_array($page, ['vend.receive_items', 'vend.receive_bill'], true)) {
            $params['vendor_id'] = self::payload($m)['header']['vendor_id'] ?? null;
        } elseif (!in_array($page, ['inv.adjust', 'bank.cash_voucher', 'cust.batch_invoices'], true)) {
            $params = ['a' => 'new'] + $params;
        }
        return url($page, $params);
    }

    /** The active memorized transactions of some types, for the "Load Memorized Transaction" list of a form */
    public static function ofTypes(array $types): array
    {
        if (!$types) {
            return [];
        }
        try {
            [$in, $p] = DB::in('mt', $types);
            return DB::all("SELECT * FROM memorized_txns WHERE is_active = 1 AND txn_type IN $in ORDER BY group_name IS NULL, group_name, name", $p);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** A memorized transaction to load into a form that takes these types, or null */
    public static function forForm(int $id, array $types): ?array
    {
        $m = $id > 0 ? self::load($id) : null;
        if (!$m || !in_array($m['txn_type'], $types, true)) {
            return null;
        }
        return ['m' => $m] + self::payload($m);
    }

    /** Keeps only the header fields and line fields worth remembering */
    public static function clean(array $h, array $lines): array
    {
        $header = [];
        foreach (self::KEEP_HEADER as $k) {
            if (array_key_exists($k, $h) && $h[$k] !== null && $h[$k] !== '') {
                $header[$k] = $h[$k];
            }
        }
        $out = [];
        foreach ($lines as $l) {
            $row = [];
            foreach (self::KEEP_LINE as $k) {
                if (array_key_exists($k, $l) && $l[$k] !== null && $l[$k] !== '') {
                    $row[$k] = $l[$k];
                }
            }
            if ($row) {
                $out[] = $row;
            }
        }
        return ['header' => $header, 'lines' => $out];
    }

    /**
     * Puts what a form holds on the Memorized Transactions list, from the "Memorize" box of the form
     * (mem_name, mem_group, mem_how, mem_every_n, mem_frequency, mem_next_date, mem_days, mem_notes). Returns the name.
     */
    public static function saveFromForm(string $type, array $payload, string $entity, float $amount): string
    {
        if (!Access::can('coa.memorized_txns', 'create')) {
            fail('You are not allowed to memorize transactions.');
        }
        $name = mb_substr(trim((string)p('mem_name')), 0, 120);
        if ($name === '') {
            fail('Enter a name for the memorized transaction.');
        }
        $how = isset(self::HOW[p('mem_how')]) ? (string)p('mem_how') : 'none';
        if (self::formOnly($type) && $how === 'auto') {
            $how = 'remind';      // it is loaded into the form and saved from there, never entered on its own
        }
        $next = pdate('mem_next_date');
        if ($how !== 'none' && !$next) {
            fail('Enter the date it is next due, or choose "Don\'t remind me".');
        }
        $id = DB::insert('memorized_txns', [
            'name' => $name, 'group_name' => mb_substr(trim((string)p('mem_group')), 0, 60) ?: null,
            'txn_type' => $type, 'entity_label' => mb_substr($entity, 0, 160) ?: null,
            'amount' => r2($amount), 'payload' => json_encode($payload),
            'how' => $how, 'frequency' => isset(self::FREQ[p('mem_frequency')]) ? (string)p('mem_frequency') : 'monthly',
            'every_n' => max(1, (int)p('mem_every_n', '1')), 'next_date' => $how === 'none' ? null : $next,
            'days_in_advance' => max(0, min(90, (int)p('mem_days', '5'))),
            'notes' => mb_substr(trim((string)p('mem_notes')), 0, 255) ?: null,
            'created_by' => Auth::id(),
        ]);
        Audit::log('memorize', 'coa', 'memorized_txn', $id, $name, ['reason' => 'Memorized from the ' . self::typeLabel($type) . ' form', 'amount' => r2($amount), 'page_key' => 'coa.memorized_txns']);
        return $name;
    }

    /** Txn::save while memorizing: the transaction is kept on the list instead of being recorded */
    public static function capture(string $type, array $h, array $lines): never
    {
        self::$capture = false;
        if (!in_array($type, self::TYPES, true)) {
            fail('This transaction cannot be memorized.');
        }
        $amount = 0.0;
        foreach ($lines as $l) {
            $amount += (float)($l['amount'] ?? 0) + (float)($l['tax_amount'] ?? 0);
        }
        if (in_array($type, ['journal', 'cash_voucher'], true)) {
            $amount = (float)($h['total'] ?? 0) ?: array_sum(array_map(fn($l) => max(0.0, (float)($l['amount'] ?? 0)), $lines));
        }
        if (!array_key_exists('class_id', $h) && array_key_exists('class_id', $_POST) && Classes::ready()) {
            $h['class_id'] = Classes::posted();
        }
        $payload = self::clean($h, $lines);
        $payload['header']['total'] = r2($amount);
        $name = self::saveFromForm($type, $payload, self::entityLabel($h), $amount);
        throw new FormNotice('Memorized as "' . $name . '". It is on the Memorized Transactions list; load it into this form with "Load Memorized Transaction". This ' . strtolower(Txn::label($type)) . ' itself was not saved.');
    }

    public static function payload(array $m): array
    {
        $p = json_decode((string)$m['payload'], true);
        return is_array($p) ? $p + ['header' => [], 'lines' => []] : ['header' => [], 'lines' => []];
    }

    /** Builds the saved copy from a transaction that is already recorded */
    public static function fromTxn(array $t): array
    {
        $header = [];
        foreach (self::KEEP_HEADER as $k) {
            if (array_key_exists($k, $t) && $t[$k] !== null && $t[$k] !== '') {
                $header[$k] = $t[$k];
            }
        }
        $lines = [];
        foreach (Txn::lines((int)$t['id']) as $l) {
            $row = [];
            foreach (self::KEEP_LINE as $k) {
                if (array_key_exists($k, $l) && $l[$k] !== null && $l[$k] !== '') {
                    $row[$k] = $l[$k];
                }
            }
            if ($row) {
                $lines[] = $row;
            }
        }
        return ['header' => $header, 'lines' => $lines];
    }

    /** Who the transaction is with, for the list */
    public static function entityLabel(array $t): string
    {
        foreach ([['customer_id', 'customers'], ['vendor_id', 'vendors'], ['other_name_id', 'other_names']] as [$field, $table]) {
            if (!empty($t[$field])) {
                return (string)DB::value("SELECT name FROM $table WHERE id = :id", ['id' => $t[$field]]);
            }
        }
        return (string)($t['payee_name'] ?? '');
    }

    /** The date after $from for a repeat of every $n $freq */
    public static function nextDate(string $from, string $freq, int $n): ?string
    {
        $n = max(1, $n);
        switch ($freq) {
            case 'daily':
                return date('Y-m-d', strtotime($from . ' +' . $n . ' days'));
            case 'weekly':
                return date('Y-m-d', strtotime($from . ' +' . $n . ' weeks'));
            case 'monthly':
            case 'quarterly':
            case 'yearly':
                $months = $freq === 'monthly' ? $n : ($freq === 'quarterly' ? $n * 3 : $n * 12);
                $d = new DateTime($from);
                $day = (int)$d->format('j');
                $d->modify('first day of this month')->modify("+$months months");
                $d->setDate((int)$d->format('Y'), (int)$d->format('n'), min($day, (int)$d->format('t')));
                return $d->format('Y-m-d');
            default:
                return null;
        }
    }

    public static function frequencyLabel(array $m): string
    {
        $n = max(1, (int)$m['every_n']);
        $unit = self::FREQ[$m['frequency']] ?? '';
        $unit = $n === 1 ? rtrim(str_replace('(s)', '', $unit), ' ') : str_replace('(s)', 's', $unit);
        return $m['how'] === 'none' && !$m['next_date'] ? 'When needed' : 'Every ' . ($n === 1 ? '' : $n . ' ') . strtolower($unit);
    }

    /** Memorized transactions that are due (or nearly due) and are set to remind */
    public static function due(?string $today = null): array
    {
        $today ??= today();
        try {
            return DB::all(
                "SELECT * FROM memorized_txns
                  WHERE is_active = 1 AND how = 'remind' AND next_date IS NOT NULL
                    AND next_date <= DATE_ADD(:d, INTERVAL days_in_advance DAY)
                    AND (end_date IS NULL OR next_date <= end_date)
                    AND (remaining IS NULL OR remaining > 0)
                  ORDER BY next_date, name",
                ['d' => $today]
            );
        } catch (Throwable $e) {
            return [];   // database not upgraded yet
        }
    }

    public static function remindsUser(): bool
    {
        return Auth::id() !== null && Access::page('coa.memorized_txns');
    }

    /**
     * Records one entry from a memorized transaction. Returns the new transaction id.
     * $date: the date of the entry (its due date when it runs on a schedule).
     */
    public static function enter(array $m, ?string $date = null, bool $advance = true): int
    {
        if (self::formOnly((string)$m['txn_type'])) {
            fail('"' . $m['name'] . '" is loaded into its form, changed there and saved from there. Use "Open on the form".');
        }
        $date ??= $m['next_date'] ?: today();
        $p = self::payload($m);
        $type = (string)$m['txn_type'];
        $h = $p['header'];
        $h['txn_date'] = $date;
        if (in_array($type, ['invoice', 'bill', 'statement_charge', 'vendor_credit'], true)) {
            $h['due_date'] = Txn::dueDate($date, !empty($h['terms_id']) ? (int)$h['terms_id'] : null);
        }
        if ($type === 'cash_voucher') {
            $h['status'] = 'open';
        }
        $opt = ['reason' => 'Entered from the memorized transaction ' . $m['name']];
        if (in_array($type, ['cash_voucher', 'transfer'], true)) {
            $opt['keep_totals'] = true;
        }
        $lines = $p['lines'];
        // the class of the header is already in the payload; do not take the one from a form that is not there
        if (!array_key_exists('class_id', $h)) {
            $h['class_id'] = null;
        }
        $id = DB::tx(function () use ($type, $h, $lines, $opt, $m, $date, $advance) {
            $newId = Txn::save($type, $h, $lines, null, $opt);
            $upd = ['last_entered' => $date, 'entered_count' => (int)$m['entered_count'] + 1];
            if ($m['remaining'] !== null) {
                $upd['remaining'] = max(0, (int)$m['remaining'] - 1);
            }
            if ($advance && $m['next_date']) {
                $next = self::nextDate($m['next_date'], (string)$m['frequency'], (int)$m['every_n']);
                $stop = ($m['end_date'] && $next && $next > $m['end_date']) || (isset($upd['remaining']) && $upd['remaining'] <= 0);
                $upd['next_date'] = $stop ? $m['next_date'] : $next;
                if ($stop) {
                    $upd['is_active'] = 0;
                }
            }
            DB::update('memorized_txns', $upd, 'id = :id', ['id' => $m['id']]);
            Audit::log('memorized_enter', 'coa', 'memorized_txn', (int)$m['id'], $m['name'], [
                'reason' => Txn::label($type) . ' entered for ' . fdate($date), 'amount' => (float)$m['amount'], 'page_key' => 'coa.memorized_txns',
            ]);
            return $newId;
        });
        return $id;
    }

    /**
     * Enters everything that is due and set to "Enter it automatically".
     * Runs once per user session and per day, from the layout, so nothing is missed and nothing is doubled.
     */
    public static function runAutomatic(): array
    {
        $done = [];
        try {
            $rows = DB::all(
                "SELECT * FROM memorized_txns
                  WHERE is_active = 1 AND how = 'auto' AND next_date IS NOT NULL AND next_date <= :d
                    AND (end_date IS NULL OR next_date <= end_date) AND (remaining IS NULL OR remaining > 0)
                  ORDER BY next_date, id",
                ['d' => today()]
            );
        } catch (Throwable $e) {
            return [];
        }
        foreach ($rows as $m) {
            // a long-overdue entry catches up one occasion at a time, keeping every date it should have had
            $guard = 0;
            while ($m['next_date'] && $m['next_date'] <= today() && $guard++ < 60) {
                if ($m['remaining'] !== null && (int)$m['remaining'] <= 0) {
                    break;
                }
                if ($m['end_date'] && $m['next_date'] > $m['end_date']) {
                    break;
                }
                try {
                    $id = self::enter($m, $m['next_date']);
                    $done[] = ['name' => $m['name'], 'txn_id' => $id, 'date' => $m['next_date']];
                } catch (Throwable $e) {
                    // a closed period, a missing account: stop this one and leave it for a person to look at
                    DB::update('memorized_txns', ['how' => 'remind'], 'id = :id', ['id' => $m['id']]);
                    Audit::log('memorized_failed', 'coa', 'memorized_txn', (int)$m['id'], (string)$m['name'], [
                        'reason' => 'Automatic entry stopped: ' . $e->getMessage() . '. Set back to "Remind me".', 'page_key' => 'coa.memorized_txns',
                    ]);
                    break;
                }
                $m = self::load((int)$m['id']);
                if (!$m || (int)$m['is_active'] !== 1) {
                    break;
                }
            }
        }
        return $done;
    }
}
