<?php
declare(strict_types=1);

/**
 * Withholding taxes (BIR Reports > Withholding Tax Table).
 *
 * The table lists the taxes a payment can have withheld: a code, the BIR ATC, a rate, the kind of tax (income tax
 * withheld under the expanded withholding tax rules, or business tax / VAT withheld) and the base it is worked out on
 * (the amount net of VAT, as the BIR asks for the 1% / 2% / 5% rates, or the gross amount). Each type posts to its
 * own account: what is withheld from a supplier is a liability until it is remitted (Withholding Tax Payable,
 * VAT Withheld Payable), what a customer withholds is a tax credit (Creditable Withholding Tax, Creditable VAT Withheld).
 *
 * A payment keeps its taxes withheld in payment_withholdings: one row per document paid and tax type, with the base,
 * the rate and the amount. payment_applications.withholding and transactions.withholding_amount hold the totals.
 * The rows are what BIR Form 2307 and the BIR Reports are built from.
 */
final class Withholding
{
    public const KINDS = ['income' => 'Income tax withheld (expanded)', 'vat' => 'Business tax withheld (VAT)'];
    public const BASES = ['net_of_vat' => 'Amount net of VAT', 'gross' => 'Gross amount (VAT included)'];
    public const SIDES = ['purchases' => 'Payments to suppliers', 'sales' => 'Payments from customers', 'both' => 'Both'];

    private static ?array $cache = null;
    private static ?bool $ready = null;

    /** The tables exist (a database upgraded with the 2026-10-15 migration, or a new one) */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                DB::value('SELECT COUNT(*) FROM withholding_taxes WHERE 1 = 0');
                DB::value('SELECT COUNT(*) FROM payment_withholdings WHERE 1 = 0');
                self::$ready = true;
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    public static function reset(): void
    {
        self::$cache = null;
    }

    /** Every type, by id, in the order of the table */
    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            if (self::ready()) {
                foreach (DB::all('SELECT * FROM withholding_taxes ORDER BY sort_order, rate, code') as $r) {
                    self::$cache[(int)$r['id']] = $r;
                }
            }
        }
        return self::$cache;
    }

    public static function get(int $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    /** The active types a payment to suppliers ('purchases') or from customers ('sales') can use */
    public static function forSide(string $side): array
    {
        return array_filter(self::all(), fn($t) => (int)$t['is_active'] === 1 && in_array($t['applies_to'], [$side, 'both'], true));
    }

    /** The company withholds taxes from what it pays its suppliers (BIR Reports > Withholding Tax Table) */
    public static function isAgent(): bool
    {
        return Settings::get('withholding_agent', '1') === '1';
    }

    public static function label(array $t): string
    {
        return $t['name'] . ' ' . rtrim(rtrim(number_format((float)$t['rate'], 4, '.', ''), '0'), '.') . '%';
    }

    public static function rateText($rate): string
    {
        return rtrim(rtrim(number_format((float)$rate, 4, '.', ''), '0'), '.') . '%';
    }

    /** The account a type posts to: what is withheld from suppliers (payable) or by customers (receivable) */
    public static function account(array $t, string $side): int
    {
        if ($side === 'purchases') {
            return (int)($t['payable_account_id'] ?: Accts::get($t['tax_kind'] === 'vat' ? 'vat_withheld_payable' : 'wht_payable'));
        }
        return (int)($t['receivable_account_id'] ?: Accts::get($t['tax_kind'] === 'vat' ? 'cvat_withheld' : 'cwt'));
    }

    /**
     * The part of a bill or invoice that is not VAT: subtotal / total. The tax base of what is still open on it is
     * the open amount times this (P112,000 owed with 12% VAT in it is a base of P100,000).
     */
    public static function netRatio(array $doc): float
    {
        $total = (float)$doc['total'];
        $tax = (float)($doc['tax_total'] ?? 0);
        if ($total <= 0.004 || abs($tax) < 0.005) {
            return 1.0;
        }
        return max(0.0, min(1.0, ($total - $tax) / $total));
    }

    /** Default types of a supplier or customer ("1,3") as ids */
    public static function defaults(?string $ids): array
    {
        return array_values(array_filter(array_map('intval', explode(',', (string)$ids))));
    }

    /**
     * The taxes typed on one line of a payment form: $r['wht'][type id] = ['on' => 1, 'amount' => ...] and
     * $r['wht_base'] = the base net of VAT. Returns [rows, total]. Each row: wht_id, tax_kind, atc, rate, base, amount, account_id.
     */
    public static function parse(array $r, string $side, float $ratio, string $ref): array
    {
        $rows = [];
        $total = 0.0;
        $types = self::forSide($side);
        $net = r2(to_num($r['wht_base'] ?? 0));
        foreach ((array)($r['wht'] ?? []) as $wid => $w) {
            if (empty($w['on'])) {
                continue;
            }
            $t = $types[(int)$wid] ?? null;
            if (!$t) {
                fail('A withholding tax ticked on ' . $ref . ' is not on the Withholding Tax Table (or is inactive).');
            }
            $amt = r2(to_num($w['amount'] ?? 0));
            if ($amt < 0) {
                fail('The withholding tax on ' . $ref . ' cannot be negative.');
            }
            if ($amt < 0.005) {
                continue;
            }
            if ($net < 0) {
                fail('The tax base of ' . $ref . ' cannot be negative.');
            }
            $base = $t['base_type'] === 'gross' && $ratio > 0 ? r2($net / $ratio) : $net;
            $rows[] = [
                'wht_id' => (int)$t['id'], 'tax_kind' => $t['tax_kind'], 'atc' => $t['atc'], 'rate' => (float)$t['rate'],
                'base' => $base, 'amount' => $amt, 'account_id' => self::account($t, $side),
            ];
            $total += $amt;
        }
        return [$rows, r2($total)];
    }

    /** Keeps the taxes withheld by a payment (called by Txn::save before the payment is posted) */
    public static function store(int $paymentId, array $byTarget): void
    {
        DB::run('DELETE FROM payment_withholdings WHERE payment_txn_id = :p', ['p' => $paymentId]);
        foreach ($byTarget as $targetId => $rows) {
            foreach ($rows as $w) {
                DB::insert('payment_withholdings', [
                    'payment_txn_id' => $paymentId, 'target_txn_id' => $targetId ?: null, 'wht_id' => $w['wht_id'],
                    'tax_kind' => $w['tax_kind'], 'atc' => $w['atc'], 'rate' => $w['rate'], 'base' => r2($w['base']),
                    'amount' => r2($w['amount']), 'account_id' => $w['account_id'],
                ]);
            }
        }
    }

    /** The taxes a payment withheld, with the document each was withheld on */
    public static function forPayment(int $paymentId): array
    {
        if (!self::ready()) {
            return [];
        }
        return DB::all('SELECT w.*, x.code, x.name, d.txn_no AS doc_no, d.ref_no AS doc_ref, d.txn_type AS doc_type, d.id AS doc_id
                          FROM payment_withholdings w JOIN withholding_taxes x ON x.id = w.wht_id
                          LEFT JOIN transactions d ON d.id = w.target_txn_id
                         WHERE w.payment_txn_id = :p ORDER BY w.id', ['p' => $paymentId]);
    }

    /** The rows of a payment by the document they were withheld on, and type: [doc id][type id] => row */
    public static function byTarget(int $paymentId): array
    {
        $out = [];
        foreach (self::forPayment($paymentId) as $w) {
            $out[(int)$w['target_txn_id']][(int)$w['wht_id']] = $w;
        }
        return $out;
    }

    /**
     * The Withholding cell of a payment line: a button showing the tax withheld, opening a list of the types of the
     * Withholding Tax Table to tick (one or more). A ticked type works out its amount from the base (net of VAT, or
     * gross), and the amount can be typed over. $name is the line's field prefix (pay[12]); $saved the rows kept
     * before ([type id] => row); $defaults the supplier's or customer's usual types, ticked when the line is chosen.
     */
    public static function picker(string $name, array $types, float $ratio, array $saved, array $posted, array $defaults, string $ref, bool $disabled = false): string
    {
        $isPost = $posted !== [];
        $base = $isPost ? (string)($posted['wht_base'] ?? '') : '';
        if (!$isPost && $saved) {
            $first = reset($saved);
            $t = $types[(int)$first['wht_id']] ?? self::get((int)$first['wht_id']);
            $base = number_format(($t && $t['base_type'] === 'gross') ? (float)$first['base'] * $ratio : (float)$first['base'], 2, '.', '');
        }
        // a type no longer active stays on a payment that used it
        foreach ($saved as $wid => $w) {
            if (!isset($types[(int)$wid]) && ($t = self::get((int)$wid))) {
                $types[(int)$wid] = $t;
            }
        }
        $sum = 0.0;
        $rows = '';
        foreach ($types as $wid => $t) {
            $on = $isPost ? !empty($posted['wht'][$wid]['on']) : isset($saved[$wid]);
            $amt = $isPost ? (string)($posted['wht'][$wid]['amount'] ?? '') : (isset($saved[$wid]) ? number_format((float)$saved[$wid]['amount'], 2, '.', '') : '');
            if ($on) {
                $sum += to_num($amt);
            }
            $f = h($name . '[wht][' . $wid . ']');
            $rows .= '<div class="wht-row' . ($on ? ' is-on' : '') . '">'
                . '<label class="wht-type"><input class="form-check-input wht-on" type="checkbox" name="' . $f . '[on]" value="1" data-id="' . (int)$wid . '" data-rate="' . h((string)(float)$t['rate']) . '" data-basis="' . h($t['base_type']) . '"' . ($on ? ' checked' : '') . '>'
                . '<span class="wht-type-name">' . h($t['name']) . '<small>' . h(self::rateText($t['rate'])) . ($t['atc'] ? ', ATC ' . h($t['atc']) : '') . ($t['base_type'] === 'gross' ? ', on the gross amount' : '') . '</small></span></label>'
                . '<input class="form-control form-control-sm text-end wht-amt" name="' . $f . '[amount]" value="' . h($amt) . '" inputmode="decimal" autocomplete="off" aria-label="' . h($t['name']) . ' withheld on ' . h($ref) . '"' . ($on ? '' : ' disabled') . ($on && ($isPost || $saved) ? ' data-typed="1"' : '') . '>'
                . '</div>';
        }
        $short = [];
        foreach ($types as $wid => $t) {
            if ($isPost ? !empty($posted['wht'][$wid]['on']) : isset($saved[$wid])) {
                $short[] = self::rateText($t['rate']);
            }
        }
        $id = 'wht' . substr(md5($name), 0, 10);
        return '<div class="wht-pick" data-wht data-ratio="' . h(number_format($ratio, 12, '.', '')) . '" data-default="' . h(implode(',', $defaults)) . '"' . ($isPost || $saved ? ' data-touched="1"' : '') . '>'
            . '<button type="button" class="form-control form-control-sm wht-btn" id="' . $id . '" aria-expanded="false" aria-haspopup="true" title="Choose the withholding taxes to deduct"' . ($disabled ? ' disabled' : '') . '>'
            . '<span class="wht-sum">' . ($sum > 0.004 ? number_format($sum, 2) : '') . '</span><span class="wht-rates">' . h(implode(' + ', $short)) . '</span><i class="bi bi-chevron-down"></i></button>'
            . '<div class="dropdown-menu dropdown-menu-end wht-menu" aria-labelledby="' . $id . '">'
            . '<div class="wht-menu-head">Withholding taxes on ' . h($ref) . '</div>'
            . '<label class="wht-base"><span>Tax base, net of VAT</span><input class="form-control form-control-sm text-end wht-base-in" name="' . h($name . '[wht_base]') . '" value="' . h($base) . '" inputmode="decimal" autocomplete="off"' . ($isPost || $saved ? ' data-typed="1"' : '') . '></label>'
            . ($types ? $rows : '<div class="small text-muted px-1 py-2">No withholding taxes on the Withholding Tax Table yet.</div>')
            . '<div class="wht-menu-foot"><span>Total withheld <strong class="wht-sum2">' . number_format($sum, 2) . '</strong></span>'
            . '<span class="d-flex gap-2"><button type="button" class="btn btn-link btn-sm p-0 wht-clear">Clear</button><button type="button" class="btn btn-primary btn-sm wht-done">Done</button></span></div>'
            . '<div class="wht-menu-note">Ticking a tax works out its amount from the base; type over it to adjust.</div>'
            . '</div>'
            . '<input type="hidden" class="wht-total" data-k="withholding" name="' . h($name . '[withholding]') . '" value="' . ($sum > 0.004 ? number_format($sum, 2, '.', '') : '') . '">'
            . '</div>';
    }

    // ------------------------------------------------------------------ data for BIR Form 2307 and the BIR Reports

    /**
     * Taxes withheld, one row per payment, document and type, with the payment, the payee and the document:
     * $side 'purchases' = withheld by the company from its suppliers (bill payments), 'sales' = withheld by customers.
     */
    public static function rows(string $side, string $from, string $to, array $opt = []): array
    {
        if (!self::ready()) {
            return [];
        }
        $isP = $side === 'purchases';
        $sql = 'SELECT w.*, x.code, x.name AS wht_name, x.tax_kind AS kind, p.txn_date, p.txn_no AS pay_no, p.id AS pay_id, p.txn_type AS pay_type, p.ref_no AS pay_ref,
                       d.id AS doc_id, d.txn_no AS doc_no, d.ref_no AS doc_ref, d.txn_type AS doc_type, d.txn_date AS doc_date, d.total AS doc_total, d.tax_total AS doc_tax, '
            . ($isP ? 'v.id AS party_id, v.name AS party, v.tin, v.address, NULL AS party_type' : 'c.id AS party_id, c.name AS party, c.tin, c.billing_address AS address, ct.name AS party_type')
            . ' FROM payment_withholdings w
                JOIN withholding_taxes x ON x.id = w.wht_id
                JOIN transactions p ON p.id = w.payment_txn_id AND p.is_voided = 0
                LEFT JOIN transactions d ON d.id = w.target_txn_id '
            . ($isP ? 'JOIN vendors v ON v.id = p.vendor_id ' : 'JOIN customers c ON c.id = p.customer_id LEFT JOIN customer_types ct ON ct.id = c.customer_type_id ')
            . 'WHERE p.txn_type = :pt AND p.txn_date BETWEEN :f AND :t';
        $p = ['pt' => $isP ? 'bill_payment' : 'payment', 'f' => $from, 't' => $to];
        if (!empty($opt['party_id'])) {
            $sql .= $isP ? ' AND v.id = :party' : ' AND c.id = :party';
            $p['party'] = (int)$opt['party_id'];
        }
        if (!empty($opt['kind'])) {
            $sql .= ' AND w.tax_kind = :kind';
            $p['kind'] = $opt['kind'];
        }
        if (!empty($opt['wht_id'])) {
            $sql .= ' AND w.wht_id = :wid';
            $p['wid'] = (int)$opt['wht_id'];
        }
        $sql .= ' ORDER BY ' . ($opt['order'] ?? 'party, p.txn_date, p.id, w.id');
        return DB::all($sql, $p);
    }

    /** Quarter of a date: [year, quarter 1-4, first day, last day] */
    public static function quarter(string $date): array
    {
        $y = (int)substr($date, 0, 4);
        $q = intdiv((int)substr($date, 5, 2) - 1, 3) + 1;
        return [$y, $q, sprintf('%04d-%02d-01', $y, $q * 3 - 2), date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $y, $q * 3)))];
    }

    /** Formats a TIN as 000-000-000-00000 when it holds the digits of one */
    public static function tin(?string $tin): string
    {
        $d = preg_replace('/\D/', '', (string)$tin);
        if (strlen($d) < 9) {
            return trim((string)$tin);
        }
        $d = str_pad($d, 14, '0');
        return substr($d, 0, 3) . '-' . substr($d, 3, 3) . '-' . substr($d, 6, 3) . '-' . substr($d, 9, 5);
    }
}
