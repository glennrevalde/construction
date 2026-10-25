<?php
declare(strict_types=1);

/**
 * Advance payments to suppliers (Vendors > Advances to Suppliers).
 *
 * An advance is money paid to a supplier before the goods or services it pays for arrive: it debits Advances to
 * Suppliers (an asset) and credits the bank. When the supplier's bill comes, the advance is applied to it in
 * Pay Bills: the application is a bill payment made out of the advances account (debit Accounts Payable, credit
 * Advances to Suppliers), dated when it is applied and linked to the advance (source_txn_id). What is left of an
 * advance is its amount less the applications that are not void.
 */
final class Advances
{
    /** What has been applied to bills out of an advance */
    public static function applied(int $advanceId, ?string $asOf = null): float
    {
        $sql = "SELECT COALESCE(SUM(total),0) FROM transactions WHERE source_txn_id = :a AND txn_type = 'bill_payment' AND is_voided = 0";
        $p = ['a' => $advanceId];
        if ($asOf) {
            $sql .= ' AND txn_date <= :d';
            $p['d'] = $asOf;
        }
        return r2(DB::value($sql, $p));
    }

    public static function remaining(array $adv, ?string $asOf = null): float
    {
        if ((int)$adv['is_voided'] === 1 || ($asOf && $adv['txn_date'] > $asOf)) {
            return 0.0;
        }
        return r2((float)$adv['total'] - self::applied((int)$adv['id'], $asOf));
    }

    /** A supplier's advances with something left, oldest first (each with 'remaining') */
    public static function open(int $vendorId): array
    {
        $out = [];
        foreach (DB::all("SELECT * FROM transactions WHERE txn_type = 'vendor_advance' AND vendor_id = :v AND is_voided = 0 ORDER BY txn_date, id", ['v' => $vendorId]) as $a) {
            $a['remaining'] = self::remaining($a);
            if ($a['remaining'] > 0.004) {
                $out[] = $a;
            }
        }
        return $out;
    }

    /** What is left of every supplier's advances: vendor id => amount */
    public static function byVendor(): array
    {
        $rows = DB::all("SELECT a.vendor_id, a.total - COALESCE((SELECT SUM(b.total) FROM transactions b WHERE b.source_txn_id = a.id AND b.txn_type = 'bill_payment' AND b.is_voided = 0), 0) AS rem
                           FROM transactions a WHERE a.txn_type = 'vendor_advance' AND a.is_voided = 0");
        $out = [];
        foreach ($rows as $r) {
            if ((float)$r['rem'] > 0.004) {
                $out[(int)$r['vendor_id']] = r2(($out[(int)$r['vendor_id']] ?? 0) + (float)$r['rem']);
            }
        }
        return $out;
    }

    /** The bill payments that applied an advance, with the bills each paid */
    public static function applications(int $advanceId): array
    {
        $out = [];
        foreach (DB::all("SELECT * FROM transactions WHERE source_txn_id = :a AND txn_type = 'bill_payment' ORDER BY txn_date, id", ['a' => $advanceId]) as $p) {
            $p['bills'] = DB::all('SELECT t.*, pa.amount, pa.discount, pa.withholding FROM payment_applications pa JOIN transactions t ON t.id = pa.target_txn_id WHERE pa.payment_txn_id = :p', ['p' => $p['id']]);
            $out[] = $p;
        }
        return $out;
    }

    /** The advance a bill payment applied, or null for an ordinary payment */
    public static function sourceOf(array $payment): ?array
    {
        if ($payment['txn_type'] !== 'bill_payment' || empty($payment['source_txn_id'])) {
            return null;
        }
        $a = Txn::load((int)$payment['source_txn_id']);
        return $a && $a['txn_type'] === 'vendor_advance' ? $a : null;
    }

    /**
     * Applies a supplier's advances to bills, oldest advance first. $useByBill: bill id => [amount, discount,
     * withholding, withholding rows]; the discount and the taxes withheld of a bill go with the first application
     * on it. One bill payment is made per advance used. Returns the ids of the applications made.
     */
    public static function apply(int $vendorId, array $useByBill, string $date, array $billRefs, ?array $approver, string $pageKey): array
    {
        $advances = self::open($vendorId);
        $need = r2(array_sum(array_map(fn($u) => $u['amount'], $useByBill)));
        $have = r2(array_sum(array_column($advances, 'remaining')));
        if ($need > $have + 0.004) {
            fail('Only ' . money($have) . ' of advances is left for ' . DB::value('SELECT name FROM vendors WHERE id = :id', ['id' => $vendorId]) . '.');
        }
        $plan = [];      // advance index => bill id => [amount, discount, withholding, rows]
        $ai = 0;
        foreach ($useByBill as $bid => $u) {
            $left = $u['amount'];
            $first = true;
            while ($left > 0.004 && isset($advances[$ai])) {
                if ($advances[$ai]['remaining'] <= 0.004) {
                    $ai++;
                    continue;
                }
                $take = r2(min($left, $advances[$ai]['remaining']));
                $plan[$ai][$bid] = [
                    'amount' => $take,
                    'discount' => $first ? $u['discount'] : 0.0,
                    'withholding' => $first ? $u['withholding'] : 0.0,
                    'rows' => $first ? $u['rows'] : [],
                ];
                $first = false;
                $advances[$ai]['remaining'] = r2($advances[$ai]['remaining'] - $take);
                $left = r2($left - $take);
            }
        }
        $made = [];
        foreach ($plan as $ai => $bills) {
            $adv = $advances[$ai];
            $apps = [];
            $wrows = [];
            foreach ($bills as $bid => $x) {
                $apps[] = ['target_txn_id' => $bid, 'amount' => $x['amount'], 'discount' => $x['discount'], 'withholding' => $x['withholding']];
                if ($x['rows']) {
                    $wrows[$bid] = $x['rows'];
                }
            }
            $id = Txn::save('bill_payment', [
                'txn_date' => max($date, (string)$adv['txn_date']),
                'txn_no' => Seq::next('advance_apply'),
                'vendor_id' => $vendorId,
                'account_id' => (int)($adv['account2_id'] ?: Accts::get('vendor_advances')),
                'account2_id' => Accts::get('ap'),
                'source_txn_id' => (int)$adv['id'],
                'total' => r2(array_sum(array_column($apps, 'amount'))),
                'discount_amount' => r2(array_sum(array_column($apps, 'discount'))),
                'withholding_amount' => r2(array_sum(array_column($apps, 'withholding'))),
                'memo' => 'Advance ' . $adv['txn_no'] . ' applied to ' . implode(', ', array_map(fn($b) => $billRefs[$b] ?? ('#' . $b), array_keys($bills))),
                'approved_by' => $approver['id'] ?? null,
            ], [], null, ['keep_totals' => true, 'applications' => $apps, 'withholdings' => $wrows, 'approved_by' => $approver['username'] ?? null,
                'audit_action' => 'apply_advance']);
            Balances::refresh((int)$adv['id']);
            $made[] = $id;
        }
        return $made;
    }
}
