<?php
declare(strict_types=1);

/**
 * The bridge from construction documents to the books. Every amount the construction side posts goes through the
 * accounting system's own transaction engine (Txn::save -> Posting), so it gets the same numbering, period lock,
 * audit trail and ledger lines as an entry typed in accounting. The project rides on the class, the work item on
 * the cost code of each line.
 */
final class CsPost
{
    /**
     * A journal entry. $lines: [account_id, amount (+ debit / - credit), class_id, cost_code_id, description,
     * other_name_id, customer_id, vendor_id]. Returns the transaction id.
     */
    public static function journal(string $date, string $memo, array $lines, ?int $classId = null, ?string $ref = null, ?int $replaceTxnId = null): int
    {
        $rows = [];
        $sum = 0.0;
        foreach ($lines as $l) {
            $amt = r2($l['amount'] ?? 0);
            if (abs($amt) < 0.005) {
                continue;
            }
            $sum += $amt;
            $rows[] = [
                'line_kind' => 'journal', 'account_id' => (int)$l['account_id'], 'amount' => $amt,
                'description' => mb_substr((string)($l['description'] ?? $memo), 0, 255),
                // a line that names its class keeps it, even "no class" (a contra entry of the company itself)
                'class_id' => array_key_exists('class_id', $l) ? (((int)$l['class_id']) ?: null) : $classId, 'cost_code_id' => ($l['cost_code_id'] ?? null) ?: null,
                'other_name_id' => ($l['other_name_id'] ?? null) ?: null, 'customer_id' => ($l['customer_id'] ?? null) ?: null,
                'vendor_id' => ($l['vendor_id'] ?? null) ?: null,
            ];
        }
        if (!$rows) {
            fail('There is nothing to post: every amount is zero.');
        }
        if (abs(r2($sum)) >= 0.01) {
            fail('The entry does not balance (difference ' . money($sum) . ').');
        }
        // lines fall back on the class of the entry, so the entry carries one only when every line has it
        $headClass = $classId && !array_filter($rows, fn($r) => (int)$r['class_id'] !== $classId) ? $classId : null;
        return Txn::save('journal', ['txn_date' => $date, 'memo' => mb_substr($memo, 0, 255), 'class_id' => $headClass, 'ref_no' => $ref ? mb_substr($ref, 0, 60) : null],
            $rows, $replaceTxnId, ['audit_action' => $replaceTxnId ? 'edit' : 'create', 'reason' => 'Posted from the construction module' . ($ref ? ' (' . $ref . ')' : '')]);
    }

    /** Voids an accounting entry made by a construction document (keeps it, with zero amounts, and the reason) */
    public static function void(?int $txnId, string $reason): void
    {
        if (!$txnId) {
            return;
        }
        $t = Txn::load($txnId);
        if ($t && (int)$t['is_voided'] === 0) {
            Txn::void($txnId, $reason);
        }
    }

    public static function account(string $key): int
    {
        return Accts::get($key);
    }

    /** The GL account a cost of a given type is charged to, when the cost code does not name one */
    public static function costAccount(string $type, ?int $costCodeId = null): int
    {
        $cc = CsCost::code($costCodeId);
        if ($cc && $cc['account_id']) {
            $acc = DB::row('SELECT cs_cost_category FROM accounts WHERE id = :id', ['id' => $cc['account_id']]);
            if ($acc && ($acc['cs_cost_category'] === $type || $type === 'any')) {
                return (int)$cc['account_id'];
            }
        }
        return Accts::get([
            'material' => 'cs_materials_cost', 'labor' => 'cs_labor_cost', 'equipment' => 'cs_equipment_cost',
            'subcontract' => 'cs_subcontract_cost', 'other' => 'cs_other_direct', 'any' => 'cs_other_direct',
        ][$type] ?? 'cs_other_direct');
    }
}
