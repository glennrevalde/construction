<?php
declare(strict_types=1);

/** Open receivables / payables as of a date, and aging buckets. */
final class Aging
{
    public const BUCKETS = ['current' => 'Current', 'd1_30' => '1 - 30', 'd31_60' => '31 - 60', 'd61_90' => '61 - 90', 'd90' => '> 90'];

    public static function bucket(?string $dueDate, string $asOf): string
    {
        $due = $dueDate ?: $asOf;
        $days = (int)floor((strtotime($asOf) - strtotime($due)) / 86400);
        if ($days <= 0) {
            return 'current';
        }
        if ($days <= 30) {
            return 'd1_30';
        }
        if ($days <= 60) {
            return 'd31_60';
        }
        if ($days <= 90) {
            return 'd61_90';
        }
        return 'd90';
    }

    public static function daysPastDue(?string $dueDate, string $asOf): int
    {
        return max(0, (int)floor((strtotime($asOf) - strtotime($dueDate ?: $asOf)) / 86400));
    }

    /**
     * Open A/R documents (positive) and unapplied credits (negative) as of a date.
     * Rows: id, txn_type, txn_no, txn_date, due_date, customer_id, customer, parent, terms, total, open
     */
    public static function receivables(string $asOf, ?int $customerId = null, bool $withJobs = true): array
    {
        $sql = "SELECT t.*, c.name AS customer, c.parent_id, p.name AS parent, COALESCE(p.id, c.id) AS top_id,
                       tm.name AS terms, c.phone, c.contact_person
                  FROM transactions t
                  JOIN customers c ON c.id = t.customer_id
                  LEFT JOIN customers p ON p.id = c.parent_id
                  LEFT JOIN terms tm ON tm.id = t.terms_id
                 WHERE t.is_voided = 0 AND t.is_pending = 0 AND t.txn_date <= :d
                   AND t.txn_type IN ('invoice','statement_charge','finance_charge','payment','credit_memo')";
        $params = ['d' => $asOf];
        if ($customerId) {
            $sql .= $withJobs ? ' AND (c.id = :c OR c.parent_id = :c)' : ' AND c.id = :c';
            $params['c'] = $customerId;
        }
        $sql .= ' ORDER BY COALESCE(p.name, c.name), c.name, t.txn_date, t.id';
        $out = [];
        foreach (DB::all($sql, $params) as $t) {
            if (in_array($t['txn_type'], Balances::RECEIVABLE_DOCS, true)) {
                $open = Balances::open($t, $asOf);
            } else {
                $open = -Balances::creditRemaining($t, $asOf);
            }
            if (abs($open) < 0.005) {
                continue;
            }
            $t['open'] = $open;
            $t['bucket'] = $open < 0 ? 'current' : self::bucket($t['due_date'], $asOf);
            $t['days'] = $open < 0 ? 0 : self::daysPastDue($t['due_date'], $asOf);
            $out[] = $t;
        }
        return $out;
    }

    /** Open A/P: bills, open item receipts (positive) and vendor credits (negative) */
    public static function payables(string $asOf, ?int $vendorId = null): array
    {
        $sql = "SELECT t.*, v.name AS vendor, tm.name AS terms
                  FROM transactions t
                  JOIN vendors v ON v.id = t.vendor_id
                  LEFT JOIN terms tm ON tm.id = t.terms_id
                 WHERE t.is_voided = 0 AND t.txn_date <= :d
                   AND t.txn_type IN ('bill','item_receipt','vendor_credit')";
        $params = ['d' => $asOf];
        if ($vendorId) {
            $sql .= ' AND t.vendor_id = :v';
            $params['v'] = $vendorId;
        }
        $sql .= ' ORDER BY v.name, t.txn_date, t.id';
        $out = [];
        foreach (DB::all($sql, $params) as $t) {
            if ($t['txn_type'] === 'vendor_credit') {
                $open = -Balances::creditRemaining($t, $asOf);
            } elseif ($t['txn_type'] === 'item_receipt') {
                $open = (float)$t['total'];
            } else {
                $open = Balances::open($t, $asOf);
            }
            if (abs($open) < 0.005) {
                continue;
            }
            $t['open'] = $open;
            $t['bucket'] = $open < 0 ? 'current' : self::bucket($t['due_date'], $asOf);
            $t['days'] = $open < 0 ? 0 : self::daysPastDue($t['due_date'], $asOf);
            $out[] = $t;
        }
        return $out;
    }

    /** Sum rows into buckets keyed by a column */
    public static function summarize(array $rows, string $key, string $label): array
    {
        $out = [];
        foreach ($rows as $r) {
            $k = $r[$key];
            if (!isset($out[$k])) {
                $out[$k] = ['id' => $k, 'name' => $r[$label], 'total' => 0.0] + array_fill_keys(array_keys(self::BUCKETS), 0.0);
            }
            $out[$k][$r['bucket']] += $r['open'];
            $out[$k]['total'] += $r['open'];
        }
        return array_values($out);
    }
}
