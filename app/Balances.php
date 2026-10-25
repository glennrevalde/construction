<?php
declare(strict_types=1);

/** Open balances, applied payments and credits, document status. */
final class Balances
{
    public const RECEIVABLE_DOCS = ['invoice', 'statement_charge', 'finance_charge'];
    public const PAYABLE_DOCS = ['bill'];
    public const CUSTOMER_CREDITS = ['payment', 'credit_memo'];
    public const VENDOR_CREDITS = ['vendor_credit'];

    /** Amount already applied to an invoice / bill (optionally as of a date, by payment date). */
    public static function applied(int $targetId, ?string $asOf = null): float
    {
        // application date = the later of the payment/credit date and the document date
        $sql = 'SELECT COALESCE(SUM(pa.amount + pa.discount + pa.withholding),0)
                  FROM payment_applications pa
                  JOIN transactions p ON p.id = pa.payment_txn_id
                  JOIN transactions tg ON tg.id = pa.target_txn_id
                 WHERE pa.target_txn_id = :t AND p.is_voided = 0';
        $params = ['t' => $targetId];
        if ($asOf) {
            $sql .= ' AND GREATEST(p.txn_date, tg.txn_date) <= :d';
            $params['d'] = $asOf;
        }
        return r2(DB::value($sql, $params));
    }

    public static function open(array $t, ?string $asOf = null): float
    {
        if ((int)$t['is_voided'] === 1 || (int)$t['is_pending'] === 1) {
            return 0.0;
        }
        return r2((float)$t['total'] - self::applied((int)$t['id'], $asOf));
    }

    /** Unused part of a payment / credit memo / vendor credit (positive number). */
    public static function creditRemaining(array $t, ?string $asOf = null): float
    {
        if ((int)$t['is_voided'] === 1) {
            return 0.0;
        }
        $base = (float)$t['total'];
        if ($t['txn_type'] === 'payment') {
            $base += (float)$t['discount_amount'] + (float)$t['withholding_amount'];
        }
        $sql = 'SELECT COALESCE(SUM(pa.amount + pa.discount + pa.withholding),0) FROM payment_applications pa
                  JOIN transactions tg ON tg.id = pa.target_txn_id
                 WHERE pa.payment_txn_id = :id';
        $p = ['id' => $t['id']];
        if ($asOf) {
            $sql .= ' AND GREATEST(tg.txn_date, :pd) <= :d';
            $p['pd'] = $t['txn_date'];
            $p['d'] = $asOf;
        }
        $used = (float)DB::value($sql, $p);
        $refSql = "SELECT COALESCE(SUM(total),0) FROM transactions WHERE txn_type = 'refund' AND source_txn_id = :id AND is_voided = 0";
        $rp = ['id' => $t['id']];
        if ($asOf) {
            $refSql .= ' AND txn_date <= :d';
            $rp['d'] = $asOf;
        }
        $refunded = (float)DB::value($refSql, $rp);
        return r2($base - $used - $refunded);
    }

    /** Updates status of an invoice / bill / payment / credit after applications change. */
    public static function refresh(int $txnId): void
    {
        $t = DB::row('SELECT * FROM transactions WHERE id = :id', ['id' => $txnId]);
        if (!$t) {
            return;
        }
        if ((int)$t['is_voided'] === 1) {
            DB::update('transactions', ['status' => 'void'], 'id = :id', ['id' => $txnId]);
            return;
        }
        $type = $t['txn_type'];
        if (in_array($type, array_merge(self::RECEIVABLE_DOCS, self::PAYABLE_DOCS), true)) {
            if ((int)$t['is_pending'] === 1) {
                $status = 'pending';
            } else {
                $open = self::open($t);
                $status = $open <= 0.004 ? 'paid' : (self::applied($txnId) > 0 ? 'partial' : 'open');
            }
            DB::update('transactions', ['status' => $status], 'id = :id', ['id' => $txnId]);
        } elseif (in_array($type, array_merge(self::CUSTOMER_CREDITS, self::VENDOR_CREDITS), true)) {
            $rem = self::creditRemaining($t);
            DB::update('transactions', ['status' => $rem <= 0.004 ? 'closed' : 'open'], 'id = :id', ['id' => $txnId]);
        } elseif ($type === 'vendor_advance') {
            DB::update('transactions', ['status' => Advances::remaining($t) <= 0.004 ? 'closed' : 'open'], 'id = :id', ['id' => $txnId]);
        }
    }

    /** Refresh every document touched by a payment / credit. */
    public static function refreshApplications(int $paymentId, array $extraTargets = []): void
    {
        $targets = array_merge($extraTargets, array_map('intval', DB::column(
            'SELECT target_txn_id FROM payment_applications WHERE payment_txn_id = :p',
            ['p' => $paymentId]
        )));
        foreach (array_unique($targets) as $tid) {
            self::refresh((int)$tid);
        }
        self::refresh($paymentId);
    }

    public static function customerBalance(int $customerId, bool $withJobs = true, ?string $asOf = null): float
    {
        $ids = [$customerId];
        if ($withJobs) {
            $ids = array_merge($ids, array_map('intval', DB::column('SELECT id FROM customers WHERE parent_id = :c', ['c' => $customerId])));
        }
        [$in, $p] = DB::in('c', $ids);
        [$ain, $ap] = DB::in('a', Accts::arIds() ?: [0]);
        $sql = "SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE customer_id IN $in AND account_id IN $ain";
        if ($asOf) {
            $sql .= ' AND txn_date <= :d';
            $p['d'] = $asOf;
        }
        return r2(DB::value($sql, $p + $ap));
    }

    public static function vendorBalance(int $vendorId, ?string $asOf = null): float
    {
        [$ain, $ap] = DB::in('a', Accts::apIds() ?: [0]);
        $sql = "SELECT COALESCE(SUM(credit - debit),0) FROM journal_lines WHERE vendor_id = :v AND account_id IN $ain";
        $p = ['v' => $vendorId] + $ap;
        if ($asOf) {
            $sql .= ' AND txn_date <= :d';
            $p['d'] = $asOf;
        }
        return r2(DB::value($sql, $p));
    }

    /** Open receivable documents for a customer (and jobs) */
    public static function openReceivables(int $customerId, bool $withJobs = true): array
    {
        $ids = [$customerId];
        if ($withJobs) {
            $ids = array_merge($ids, array_map('intval', DB::column('SELECT id FROM customers WHERE parent_id = :c', ['c' => $customerId])));
        }
        [$in, $p] = DB::in('c', $ids);
        $rows = DB::all(
            "SELECT t.*, c.name AS customer_name, tm.discount_pct, tm.discount_days
               FROM transactions t
               JOIN customers c ON c.id = t.customer_id
               LEFT JOIN terms tm ON tm.id = t.terms_id
              WHERE t.customer_id IN $in AND t.txn_type IN ('invoice','statement_charge','finance_charge')
                AND t.is_voided = 0 AND t.is_pending = 0 AND t.status IN ('open','partial')
              ORDER BY t.due_date, t.txn_date, t.id",
            $p
        );
        foreach ($rows as &$r) {
            $r['open_balance'] = self::open($r);
        }
        return array_values(array_filter($rows, fn($r) => $r['open_balance'] > 0.004));
    }

    public static function openPayables(?int $vendorId = null, ?string $dueBy = null): array
    {
        $sql = "SELECT t.*, v.name AS vendor_name, tm.discount_pct, tm.discount_days
                  FROM transactions t
                  JOIN vendors v ON v.id = t.vendor_id
                  LEFT JOIN terms tm ON tm.id = t.terms_id
                 WHERE t.txn_type = 'bill' AND t.is_voided = 0 AND t.status IN ('open','partial')";
        $p = [];
        if ($vendorId) {
            $sql .= ' AND t.vendor_id = :v';
            $p['v'] = $vendorId;
        }
        if ($dueBy) {
            $sql .= ' AND COALESCE(t.due_date, t.txn_date) <= :d';
            $p['d'] = $dueBy;
        }
        $sql .= ' ORDER BY COALESCE(t.due_date, t.txn_date), v.name, t.id';
        $rows = DB::all($sql, $p);
        foreach ($rows as &$r) {
            $r['open_balance'] = self::open($r);
        }
        return array_values(array_filter($rows, fn($r) => $r['open_balance'] > 0.004));
    }

    /** Available customer credits (unapplied payments and credit memos) */
    public static function customerCredits(int $customerId): array
    {
        $rows = DB::all(
            "SELECT * FROM transactions WHERE customer_id = :c AND txn_type IN ('payment','credit_memo')
                AND is_voided = 0 AND status = 'open' ORDER BY txn_date, id",
            ['c' => $customerId]
        );
        foreach ($rows as &$r) {
            $r['remaining'] = self::creditRemaining($r);
        }
        return array_values(array_filter($rows, fn($r) => $r['remaining'] > 0.004));
    }

    public static function vendorCredits(int $vendorId): array
    {
        $rows = DB::all(
            "SELECT * FROM transactions WHERE vendor_id = :v AND txn_type = 'vendor_credit' AND is_voided = 0 AND status = 'open' ORDER BY txn_date, id",
            ['v' => $vendorId]
        );
        foreach ($rows as &$r) {
            $r['remaining'] = self::creditRemaining($r);
        }
        return array_values(array_filter($rows, fn($r) => $r['remaining'] > 0.004));
    }

    /** Early-payment discount available for a document on a payment date */
    public static function earlyDiscount(array $doc, string $payDate): float
    {
        $pct = (float)($doc['discount_pct'] ?? 0);
        $days = (int)($doc['discount_days'] ?? 0);
        if ($pct <= 0 || $days <= 0) {
            return 0.0;
        }
        $limit = strtotime($doc['txn_date'] . " +$days days");
        if (strtotime($payDate) > $limit) {
            return 0.0;
        }
        return r2((float)$doc['total'] * $pct / 100);
    }
}
