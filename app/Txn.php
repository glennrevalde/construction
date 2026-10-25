<?php
declare(strict_types=1);

/** Saving, voiding and deleting transactions (with posting and audit trail). */
final class Txn
{
    /** type => [label, page key, number sequence] */
    public const TYPES = [
        'estimate'             => ['Estimate', 'cust.estimates', 'estimate'],
        'sales_order'          => ['Sales Order', 'cust.sales_orders', 'sales_order'],
        'invoice'              => ['Invoice', 'cust.invoices', 'invoice'],
        'sales_receipt'        => ['Sales Receipt', 'cust.sales_receipts', 'sales_receipt'],
        'statement_charge'     => ['Statement Charge', 'cust.statement_charges', 'statement_charge'],
        'finance_charge'       => ['Finance Charge', 'cust.finance_charges', 'finance_charge'],
        'payment'              => ['Customer Payment', 'cust.payments', 'payment'],
        'credit_memo'          => ['Credit Memo', 'cust.credit_memos', 'credit_memo'],
        'refund'               => ['Customer Refund', 'cust.credit_memos', 'check'],
        'purchase_order'       => ['Purchase Order', 'vend.purchase_orders', 'purchase_order'],
        'bill'                 => ['Bill', 'vend.bills', 'bill'],
        'vendor_credit'        => ['Supplier Debit Memo', 'vend.debit_memos', 'debit_memo'],
        'item_receipt'         => ['Item Receipt', 'vend.receive_items', 'item_receipt'],
        'bill_payment'         => ['Bill Payment', 'vend.pay_bills', 'check'],
        'vendor_advance'       => ['Advance to Supplier', 'vend.advances', 'vendor_advance'],
        'check'                => ['Check', 'bank.write_checks', 'check'],
        'cash_voucher'         => ['Cash Voucher', 'bank.cash_voucher', 'cash_voucher'],
        'deposit'              => ['Deposit', 'bank.deposits', 'deposit'],
        'transfer'             => ['Transfer', 'bank.transfers', 'transfer'],
        'journal'              => ['Journal Entry', 'coa.journal', 'journal'],
        'opening_balance'      => ['Opening Balance', 'coa.journal', 'journal'],
        'inventory_adjustment' => ['Inventory Adjustment', 'inv.adjust', 'adjustment'],
        'inventory_transfer'   => ['Inventory Transfer', 'inv.transfers', 'inv_transfer'],
        'build_assembly'       => ['Build Assembly', 'inv.build', 'build'],
        'item_processing'      => ['Processing Order', 'inv.process', 'processing'],
        'pos_sale'             => ['POS Sale', 'pos.receipts', 'pos'],
        'pos_return'           => ['POS Return', 'pos.receipts', 'pos'],
        'pos_over_short'       => ['Cash Over/Short', 'pos.shifts', 'journal'],
        'depreciation'         => ['Depreciation', 'fa.depreciation', 'journal'],
        'asset_disposal'       => ['Asset Disposal', 'fa.disposal', 'journal'],
        'bank_charge'          => ['Service Charge', 'bank.reconcile', 'journal'],
        'bank_interest'        => ['Interest Earned', 'bank.reconcile', 'journal'],
    ];

    /** Types whose totals are computed from their lines (amount + tax) */
    private const LINE_TOTAL_TYPES = ['estimate', 'sales_order', 'invoice', 'sales_receipt', 'statement_charge', 'finance_charge',
        'credit_memo', 'purchase_order', 'bill', 'vendor_credit', 'item_receipt', 'check', 'pos_sale', 'pos_return'];

    public static function label(string $type): string
    {
        return self::TYPES[$type][0] ?? ucwords(str_replace('_', ' ', $type));
    }

    public static function page(string $type): string
    {
        return self::TYPES[$type][1] ?? 'home.dashboard';
    }

    public static function module(string $type): string
    {
        return explode('.', self::page($type))[0];
    }

    public static function load(int $id): ?array
    {
        return DB::row('SELECT * FROM transactions WHERE id = :id', ['id' => $id]);
    }

    public static function lines(int $id): array
    {
        return DB::all(
            'SELECT l.*, i.code AS item_code, i.name AS item_name, i.item_type, i.unit AS item_unit,
                    a.code AS account_code, a.name AS account_name, c.name AS customer_name,
                    v.name AS vendor_name, o.name AS other_name, cl.name AS class_name
               FROM transaction_lines l
               LEFT JOIN items i ON i.id = l.item_id
               LEFT JOIN accounts a ON a.id = l.account_id
               LEFT JOIN customers c ON c.id = l.customer_id
               LEFT JOIN vendors v ON v.id = l.vendor_id
               LEFT JOIN other_names o ON o.id = l.other_name_id
               LEFT JOIN classes cl ON cl.id = l.class_id
              WHERE l.txn_id = :id ORDER BY l.line_no, l.id',
            ['id' => $id]
        );
    }

    public static function ref(array $t): string
    {
        return self::label($t['txn_type']) . ' ' . ($t['txn_no'] ?: ('#' . $t['id']));
    }

    public static function url(array $t, string $action = 'view'): string
    {
        return url(self::page($t['txn_type']), ['a' => $action, 'id' => $t['id']]);
    }

    /** Drill-down link, shown only when the user may open the page behind it. */
    public static function link($t, ?string $text = null): string
    {
        if (!is_array($t)) {
            $t = self::load((int)$t);
            if (!$t) {
                return h($text ?? '');
            }
        }
        $text ??= $t['txn_no'] ?: ('#' . $t['id']);
        if (!Access::page(self::page($t['txn_type']))) {
            return h($text);
        }
        return '<a href="' . h(self::url($t)) . '">' . h($text) . '</a>';
    }

    // ------------------------------------------------------------------ period and reconciliation protection

    public static function isReconciled(int $id): bool
    {
        return (int)DB::value('SELECT COUNT(*) FROM journal_lines WHERE txn_id = :t AND cleared = 1', ['t' => $id]) > 0;
    }

    public static function assertPeriod(?string $newDate, ?array $old): void
    {
        $closing = Settings::closingDate();
        $allowed = (bool)Access::special('closed_period_changes');
        if ($closing && !$allowed) {
            if ($newDate && $newDate <= $closing) {
                fail('The books are closed through ' . fdate($closing) . '. You do not have permission to record transactions dated on or before the closing date.');
            }
            if ($old && $old['txn_date'] <= $closing) {
                fail('This transaction is dated on or before the closing date (' . fdate($closing) . ') and cannot be changed.');
            }
        }
        if ($old && !$allowed && self::isReconciled((int)$old['id'])) {
            fail('This transaction has been cleared in a bank reconciliation. You do not have permission to change it.');
        }
    }

    // ------------------------------------------------------------------ save

    /**
     * @param array $h     header columns
     * @param array $lines line rows (may include 'id' of an existing line)
     * @param array $opt   reason, approved_by, keep_totals, audit_action, applications
     */
    /** The sales documents that take stock out and post its cost (v29: they may not sell an item with no cost) */
    public const COSTED_SALES = ['invoice', 'sales_receipt', 'statement_charge', 'pos_sale'];

    /**
     * Refuses a sale of an inventory part or assembly that has neither a unit cost (Item List) nor an average unit
     * cost: its cost of goods sold would be zero and its profit overstated. Item groups are sold as their items, so
     * the items of a group are checked too. Switched off with the setting "Block sales of items with no unit cost".
     */
    public static function assertCosted(string $type, array $lines): void
    {
        if (!in_array($type, self::COSTED_SALES, true) || Settings::get('block_zero_cost_sales', '1') !== '1') {
            return;
        }
        $ids = [];
        foreach ($lines as $l) {
            if (!empty($l['item_id']) && (float)($l['qty'] ?? 0) > 0) {
                $ids[(int)$l['item_id']][] = (int)($l['group_item_id'] ?? 0);
            }
        }
        if (!$ids) {
            return;
        }
        [$in, $p] = DB::in('zc', array_keys($ids));
        $bad = DB::all("SELECT id, code, name FROM items WHERE id IN $in AND item_type IN ('inventory','assembly')
                         AND COALESCE(cost, 0) <= 0 AND COALESCE(avg_cost, 0) <= 0 ORDER BY code", $p);
        if (!$bad) {
            return;
        }
        $groups = [];
        foreach ($ids as $gids) {
            foreach ($gids as $g) {
                if ($g) {
                    $groups[$g] = true;
                }
            }
        }
        $gNames = $groups ? DB::pairs('SELECT id, code FROM items WHERE id IN (' . implode(',', array_map('intval', array_keys($groups))) . ')') : [];
        $names = [];
        foreach ($bad as $b) {
            $in = array_values(array_unique(array_filter(array_map(fn($g) => $gNames[$g] ?? null, $ids[(int)$b['id']]))));
            $names[] = $b['code'] . ($b['name'] !== $b['code'] ? ' - ' . $b['name'] : '') . ($in ? ' (in item group ' . implode(', ', $in) . ')' : '');
        }
        fail((count($bad) === 1 ? 'This item has' : 'These items have') . ' no unit cost and no average unit cost, so the sale would record no cost of goods sold: '
            . implode('; ', $names) . '. Enter the unit cost on the item (Inventory > Item List), or receive it at its cost first, then save again.');
    }

    public static function save(string $type, array $h, array $lines, ?int $id = null, array $opt = []): int
    {
        if (Memorized::$capture) {
            Memorized::capture($type, $h, $lines);    // "Memorize" on the form: kept on the list, nothing is recorded
        }
        return DB::tx(function () use ($type, $h, $lines, $id, $opt) {
            $old = $id ? self::load($id) : null;
            if ($id && !$old) {
                fail('Transaction not found.');
            }
            if ($old && (int)$old['is_voided'] === 1) {
                fail('A voided transaction cannot be edited.');
            }
            if (!isset($h['txn_date']) || !valid_date((string)$h['txn_date'])) {
                fail('Enter a valid date.');
            }
            self::assertPeriod($h['txn_date'], $old);
            // an inventory part (or the items of an item group) with no unit cost can not be sold (v29)
            self::assertCosted($type, $lines);
            if (isset($h['site_id']) && $h['site_id'] && !Access::siteAllowed((int)$h['site_id'])) {
                fail('You are not allowed to record transactions for that site.');
            }
            $before = $old ? Audit::txnSnapshot($id) : [];
            $h['txn_type'] = $type;
            // Class: every transaction form has a class drop-down (Classes::field). Pages that do not set
            // the class themselves get the one chosen on the submitted form, so all of them record it.
            if (!array_key_exists('class_id', $h) && is_post() && array_key_exists('class_id', $_POST) && Classes::ready()) {
                $h['class_id'] = Classes::posted();
            }

            if (empty($opt['keep_totals']) && in_array($type, self::LINE_TOTAL_TYPES, true)) {
                $sub = 0.0;
                $tax = 0.0;
                foreach ($lines as $l) {
                    $sub += (float)($l['amount'] ?? 0);
                    $tax += (float)($l['tax_amount'] ?? 0);
                }
                $h['subtotal'] = r2($sub);
                $h['tax_total'] = r2($tax);
                $h['total'] = r2($sub + $tax);
            } elseif (empty($opt['keep_totals']) && in_array($type, Posting::JOURNAL_TYPES, true)) {
                $dr = 0.0;
                foreach ($lines as $l) {
                    $dr += max(0, (float)$l['amount']);
                }
                $h['total'] = r2($dr);
            } elseif (empty($opt['keep_totals']) && $type === 'deposit') {
                $sum = 0.0;
                foreach ($lines as $l) {
                    $sum += (float)$l['amount'];
                }
                $h['subtotal'] = r2($sum);
                $h['total'] = r2($sum - (float)($h['cash_back_amount'] ?? 0));
            }

            if (!$old) {
                if (empty($h['txn_no']) && isset(self::TYPES[$type][2])) {
                    $h['txn_no'] = Seq::next(self::TYPES[$type][2]);
                }
                $h['created_by'] = Auth::id();
                $id = DB::insert('transactions', $h);
            } else {
                $h['updated_by'] = Auth::id();
                DB::update('transactions', $h, 'id = :id', ['id' => $id]);
            }

            // lines: update kept lines, insert new ones, delete removed ones
            $existing = array_map('intval', DB::column('SELECT id FROM transaction_lines WHERE txn_id = :t', ['t' => $id]));
            $oldSources = array_map('intval', DB::column('SELECT source_line_id FROM transaction_lines WHERE txn_id = :t AND source_line_id IS NOT NULL', ['t' => $id]));
            $keep = [];
            $n = 0;
            foreach ($lines as $l) {
                $lineId = isset($l['id']) ? (int)$l['id'] : 0;
                unset($l['id']);
                // a line made from another document's line (PO > receipt > bill, order > invoice) keeps that line's
                // project (class) and cost code when it was not given its own
                if (!empty($l['source_line_id']) && (empty($l['class_id']) || empty($l['cost_code_id']))) {
                    $src = DB::row('SELECT class_id, cost_code_id FROM transaction_lines WHERE id = :s', ['s' => (int)$l['source_line_id']]);
                    if ($src) {
                        $l['class_id'] = ($l['class_id'] ?? null) ?: $src['class_id'];
                        $l['cost_code_id'] = ($l['cost_code_id'] ?? null) ?: $src['cost_code_id'];
                    }
                }
                $l['txn_id'] = $id;
                $l['line_no'] = ++$n;
                if ($lineId && in_array($lineId, $existing, true)) {
                    DB::update('transaction_lines', $l, 'id = :id', ['id' => $lineId]);
                    $keep[] = $lineId;
                } else {
                    $keep[] = DB::insert('transaction_lines', $l);
                }
            }
            foreach (array_diff($existing, $keep) as $gone) {
                $used = (float)DB::value('SELECT COALESCE(SUM(qty),0) FROM transaction_lines WHERE source_line_id = :l', ['l' => $gone]);
                if (abs($used) > 0) {
                    fail('A line that was already used on another document cannot be removed. Close the line instead.');
                }
                DB::run('DELETE FROM transaction_lines WHERE id = :id', ['id' => $gone]);
            }

            if (isset($opt['applications'])) {
                $targets = array_map('intval', DB::column('SELECT target_txn_id FROM payment_applications WHERE payment_txn_id = :p', ['p' => $id]));
                DB::run('DELETE FROM payment_applications WHERE payment_txn_id = :p', ['p' => $id]);
                foreach ($opt['applications'] as $a) {
                    if (abs((float)$a['amount']) + abs((float)($a['discount'] ?? 0)) + abs((float)($a['withholding'] ?? 0)) < 0.005) {
                        continue;
                    }
                    DB::insert('payment_applications', [
                        'payment_txn_id' => $id,
                        'target_txn_id'  => (int)$a['target_txn_id'],
                        'amount'         => r2($a['amount']),
                        'discount'       => r2($a['discount'] ?? 0),
                        'withholding'    => r2($a['withholding'] ?? 0),
                    ]);
                    $targets[] = (int)$a['target_txn_id'];
                }
            }

            // taxes withheld by a payment, by the document they were withheld on (BIR Form 2307 and the BIR Reports)
            if (isset($opt['withholdings']) && Withholding::ready()) {
                Withholding::store($id, $opt['withholdings']);
            }

            Posting::post($id);
            // the item groups sold on the document (Sales by Item Group, POS Sales by Item Group)
            if (in_array($type, ItemGroups::SALES_TYPES, true)) {
                ItemGroups::record($id);
            }
            // a one-time approval code used for this save is tied to the document it approved
            ApprovalCode::attach($id);

            $newSources = array_map('intval', DB::column('SELECT source_line_id FROM transaction_lines WHERE txn_id = :t AND source_line_id IS NOT NULL', ['t' => $id]));
            self::recalcSources(array_merge($oldSources, $newSources));

            if (isset($opt['applications'])) {
                Balances::refreshApplications($id, $targets ?? []);
            }
            Balances::refresh($id);
            if (!empty($h['source_txn_id']) && $type === 'refund') {
                Balances::refresh((int)$h['source_txn_id']);
            }

            $t = self::load($id);
            $after = Audit::txnSnapshot($id);
            Audit::log($opt['audit_action'] ?? ($old ? 'edit' : 'create'), self::module($type), 'transaction', $id, self::ref($t), [
                'page_key'    => self::page($type),
                'before'      => $before,
                'after'       => $after,
                'changes'     => $old ? null : [],
                'snapshot'    => $after,
                'amount'      => $t['total'],
                'reason'      => $opt['reason'] ?? null,
                'approved_by' => $opt['approved_by'] ?? null,
                'site_id'     => $t['site_id'],
                'terminal_id' => $t['terminal_id'],
            ]);
            return $id;
        });
    }

    /** Recompute fulfilled quantities / billed costs on source lines and their documents' status. */
    public static function recalcSources(array $sourceLineIds): void
    {
        $sourceLineIds = array_values(array_unique(array_filter($sourceLineIds)));
        if (!$sourceLineIds) {
            return;
        }
        $txnIds = [];
        foreach ($sourceLineIds as $sid) {
            $src = DB::row('SELECT l.id, l.txn_id, t.txn_type FROM transaction_lines l JOIN transactions t ON t.id = l.txn_id WHERE l.id = :id', ['id' => $sid]);
            if (!$src) {
                continue;
            }
            $agg = DB::row(
                "SELECT COALESCE(SUM(l.qty),0) AS q, COALESCE(SUM(l.amount),0) AS a, MAX(l.txn_id) AS last_txn
                   FROM transaction_lines l JOIN transactions t ON t.id = l.txn_id
                  WHERE l.source_line_id = :id AND t.is_voided = 0 AND t.txn_type NOT IN ('estimate','sales_order','purchase_order')",
                ['id' => $sid]
            );
            if (in_array($src['txn_type'], ['bill', 'check', 'vendor_credit'], true)) {
                DB::update('transaction_lines', ['billed_txn_id' => $agg['last_txn'] ?: null], 'id = :id', ['id' => $sid]);
            } else {
                DB::update('transaction_lines', ['qty_fulfilled' => $agg['q'], 'amount_fulfilled' => r2($agg['a'])], 'id = :id', ['id' => $sid]);
                // an estimate converted to a sales order counts as accepted
                $toSo = (float)DB::value(
                    "SELECT COALESCE(SUM(l.qty),0) FROM transaction_lines l JOIN transactions t ON t.id = l.txn_id
                      WHERE l.source_line_id = :id AND t.is_voided = 0 AND t.txn_type = 'sales_order'",
                    ['id' => $sid]
                );
                if ($src['txn_type'] === 'estimate' && $toSo > 0) {
                    DB::update('transactions', ['status' => 'accepted'], "id = :id AND status = 'pending'", ['id' => $src['txn_id']]);
                }
                if (in_array($src['txn_type'], ['estimate', 'sales_order', 'purchase_order'], true)) {
                    $txnIds[(int)$src['txn_id']] = $src['txn_type'];
                }
            }
        }
        foreach ($txnIds as $tid => $ttype) {
            self::refreshOrderStatus($tid, $ttype);
        }
    }

    public static function refreshOrderStatus(int $tid, string $ttype): void
    {
        $t = self::load($tid);
        if (!$t || (int)$t['is_voided'] === 1) {
            return;
        }
        $lines = DB::all('SELECT l.qty, l.qty_fulfilled, l.amount, l.amount_fulfilled, l.is_closed FROM transaction_lines l WHERE l.txn_id = :t AND l.item_id IS NOT NULL', ['t' => $tid]);
        $any = false;
        $all = true;
        foreach ($lines as $l) {
            if ($ttype === 'estimate') {
                $done = abs((float)$l['amount_fulfilled']) >= abs((float)$l['amount']) - 0.005;
                $any = $any || abs((float)$l['amount_fulfilled']) > 0.004;
            } else {
                $done = (int)$l['is_closed'] === 1 || (float)$l['qty_fulfilled'] >= (float)$l['qty'] - 0.00001;
                $any = $any || (float)$l['qty_fulfilled'] > 0;
            }
            $all = $all && $done;
        }
        if ($ttype === 'estimate') {
            if ($all && $any) {
                $status = 'closed';
            } elseif ($any) {
                $status = 'accepted';
            } else {
                $status = in_array($t['status'], ['accepted', 'pending', 'closed'], true) ? $t['status'] : 'pending';
                if ($status === 'closed' && !self::manuallyClosed($tid)) {
                    $status = 'accepted';
                }
            }
        } else {
            $status = $all && $lines ? 'closed' : ($any ? 'partial' : 'open');
        }
        DB::update('transactions', ['status' => $status], 'id = :id', ['id' => $tid]);
    }

    private static function manuallyClosed(int $tid): bool
    {
        return (int)DB::value("SELECT COUNT(*) FROM audit_trail WHERE record_type = 'transaction' AND record_id = :id AND action = 'close'", ['id' => $tid]) > 0;
    }

    // ------------------------------------------------------------------ void / delete

    /** Reasons a transaction cannot be voided/deleted right now */
    public static function blockers(array $t, bool $deleting): array
    {
        $out = [];
        $id = (int)$t['id'];
        if (in_array($t['txn_type'], ['invoice', 'statement_charge', 'finance_charge', 'bill'], true) && Balances::applied($id) > 0) {
            $out[] = 'Payments or credits have been applied to it. Remove them first (edit the payment or credit).';
        }
        $dep = DB::value(
            'SELECT d.txn_no FROM transaction_lines l JOIN transactions d ON d.id = l.txn_id
              WHERE l.ref_txn_id = :id AND d.is_voided = 0 LIMIT 1',
            ['id' => $id]
        );
        if ($dep) {
            $out[] = "It is included in deposit $dep. Remove it from the deposit first.";
        }
        if ($t['txn_type'] === 'vendor_advance') {
            $app = DB::value("SELECT txn_no FROM transactions WHERE source_txn_id = :id AND txn_type = 'bill_payment' AND is_voided = 0 LIMIT 1", ['id' => $id]);
            if ($app) {
                $out[] = "It has been applied to bills ($app). Void the application first.";
            }
        }
        $refund = DB::value("SELECT txn_no FROM transactions WHERE source_txn_id = :id AND txn_type = 'refund' AND is_voided = 0 LIMIT 1", ['id' => $id]);
        if ($refund && in_array($t['txn_type'], ['credit_memo', 'payment'], true)) {
            $out[] = "It has been refunded ($refund). Void the refund first.";
        }
        $used = (float)DB::value(
            "SELECT COALESCE(SUM(l2.qty),0) FROM transaction_lines l
               JOIN transaction_lines l2 ON l2.source_line_id = l.id
               JOIN transactions t2 ON t2.id = l2.txn_id AND t2.is_voided = 0
              WHERE l.txn_id = :id",
            ['id' => $id]
        );
        if (abs($used) > 0 && in_array($t['txn_type'], ['sales_order', 'purchase_order', 'estimate'], true)) {
            $out[] = 'It has already been converted or received in part. Close it instead.';
        }
        return $out;
    }

    public static function void(int $id, string $reason, ?array $approver = null): void
    {
        DB::tx(function () use ($id, $reason, $approver) {
            $t = self::load($id);
            if (!$t) {
                fail('Transaction not found.');
            }
            if ((int)$t['is_voided'] === 1) {
                fail('This transaction is already void.');
            }
            if (trim($reason) === '') {
                fail('Enter a reason for voiding.');
            }
            self::assertPeriod(null, $t);
            if ($b = self::blockers($t, false)) {
                fail(implode(' ', $b));
            }
            $snapshot = Audit::txnSnapshot($id);
            $targets = array_map('intval', DB::column('SELECT target_txn_id FROM payment_applications WHERE payment_txn_id = :p', ['p' => $id]));
            DB::run('DELETE FROM payment_applications WHERE payment_txn_id = :p', ['p' => $id]);
            DB::update('transactions', [
                'is_voided'   => 1,
                'status'      => 'void',
                'void_reason' => mb_substr($reason, 0, 255),
                'voided_by'   => Auth::id(),
                'voided_at'   => date('Y-m-d H:i:s'),
                'approved_by' => $approver['id'] ?? $t['approved_by'],
            ], 'id = :id', ['id' => $id]);
            Posting::post($id);
            self::recalcSources(array_map('intval', DB::column('SELECT source_line_id FROM transaction_lines WHERE txn_id = :t AND source_line_id IS NOT NULL', ['t' => $id])));
            foreach ($targets as $tg) {
                Balances::refresh($tg);
            }
            if ($t['source_txn_id']) {
                Balances::refresh((int)$t['source_txn_id']);
            }
            DB::run('UPDATE fixed_assets SET acquisition_txn_id = NULL WHERE acquisition_txn_id = :id', ['id' => $id]);
            DB::run('UPDATE pos_shifts SET deposit_txn_id = NULL WHERE deposit_txn_id = :id', ['id' => $id]);
            self::releaseDepreciation($id);
            Audit::log('void', self::module($t['txn_type']), 'transaction', $id, self::ref($t), [
                'page_key' => self::page($t['txn_type']), 'snapshot' => $snapshot, 'amount' => $t['total'],
                'reason' => $reason, 'approved_by' => $approver['username'] ?? null,
                'changes' => [['field' => 'status', 'old' => $t['status'], 'new' => 'void']],
                'site_id' => $t['site_id'], 'terminal_id' => $t['terminal_id'],
            ]);
        });
    }

    public static function delete(int $id, string $reason, ?array $approver = null): void
    {
        DB::tx(function () use ($id, $reason, $approver) {
            $t = self::load($id);
            if (!$t) {
                fail('Transaction not found.');
            }
            if (trim($reason) === '') {
                fail('Enter a reason for deleting.');
            }
            self::assertPeriod(null, $t);
            if ($b = self::blockers($t, true)) {
                fail(implode(' ', $b));
            }
            $snapshot = Audit::txnSnapshot($id);
            $targets = array_map('intval', DB::column('SELECT target_txn_id FROM payment_applications WHERE payment_txn_id = :p', ['p' => $id]));
            $sources = array_map('intval', DB::column('SELECT source_line_id FROM transaction_lines WHERE txn_id = :t AND source_line_id IS NOT NULL', ['t' => $id]));
            Posting::unpost($id);
            DB::run('UPDATE transaction_lines SET billed_txn_id = NULL WHERE billed_txn_id = :id', ['id' => $id]);
            DB::run('UPDATE transaction_lines SET source_line_id = NULL WHERE source_line_id IN (SELECT id FROM (SELECT id FROM transaction_lines WHERE txn_id = :id) x)', ['id' => $id]);
            DB::run('UPDATE transactions SET source_txn_id = NULL WHERE source_txn_id = :id AND txn_type <> \'refund\'', ['id' => $id]);
            DB::run('UPDATE receipt_documents SET linked_txn_id = NULL, status = \'for_review\' WHERE linked_txn_id = :id', ['id' => $id]);
            self::releaseDepreciation($id);
            DB::run('UPDATE pos_shifts SET deposit_txn_id = NULL WHERE deposit_txn_id = :id', ['id' => $id]);
            DB::run('UPDATE fixed_assets SET acquisition_txn_id = NULL WHERE acquisition_txn_id = :id', ['id' => $id]);
            DB::run('DELETE FROM transactions WHERE id = :id', ['id' => $id]);
            self::recalcSources($sources);
            foreach ($targets as $tg) {
                Balances::refresh($tg);
            }
            if ($t['source_txn_id']) {
                Balances::refresh((int)$t['source_txn_id']);
            }
            Audit::log('delete', self::module($t['txn_type']), 'transaction', $id, self::ref($t), [
                'page_key' => self::page($t['txn_type']), 'snapshot' => $snapshot, 'amount' => $t['total'],
                'reason' => $reason, 'approved_by' => $approver['username'] ?? null,
                'site_id' => $t['site_id'], 'terminal_id' => $t['terminal_id'],
            ]);
        });
    }

    /** Undoing a depreciation run or a disposal frees the asset periods / status again. */
    private static function releaseDepreciation(int $txnId): void
    {
        DB::run(
            "UPDATE fixed_assets SET status = 'active'
              WHERE status = 'fully_depreciated' AND id IN (SELECT asset_id FROM (SELECT asset_id FROM asset_depreciation WHERE txn_id = :id) x)",
            ['id' => $txnId]
        );
        DB::run('DELETE FROM asset_depreciation WHERE txn_id = :id', ['id' => $txnId]);
        DB::run(
            "UPDATE fixed_assets SET status = 'active', disposal_date = NULL, disposal_proceeds = NULL, disposal_txn_id = NULL WHERE disposal_txn_id = :id",
            ['id' => $txnId]
        );
    }

    /** Due date from terms */
    public static function dueDate(string $date, ?int $termsId): string
    {
        if (!$termsId) {
            return $date;
        }
        $days = (int)DB::value('SELECT net_days FROM terms WHERE id = :id', ['id' => $termsId]);
        return date('Y-m-d', strtotime("$date +$days days"));
    }
}
