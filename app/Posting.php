<?php
declare(strict_types=1);

/**
 * Posting engine: turns a saved transaction into general ledger lines and
 * inventory movements. Re-posting first removes what the transaction posted before,
 * so edits, voids and deletions always leave the ledger consistent.
 */
final class Posting
{
    /** Transaction types that never post to the ledger */
    public const NON_POSTING = ['estimate', 'sales_order', 'purchase_order'];

    /** Types posted directly from their lines (+ debit / - credit) */
    public const JOURNAL_TYPES = ['journal', 'depreciation', 'asset_disposal', 'bank_charge', 'bank_interest', 'pos_over_short', 'opening_balance'];

    /** Cash voucher: lines like a journal entry, balanced by one credit to the cash account in the header */
    public const CASH_VOUCHER = 'cash_voucher';

    /**
     * Saving a document that takes an item's quantity at a site below zero (lower than it was before the save)
     * is refused when "Allow selling or moving stock below zero" is off, and saved with a warning when it is on
     * (a form carrying negative_stock_fields() is asked about it first, and saved only after Yes).
     * Switched off while Utilities re-posts every transaction.
     */
    public static bool $stockCheck = true;

    private array $jl = [];
    private array $t;

    public static function post(int $txnId): void
    {
        DB::tx(function () use ($txnId) {
            $t = DB::row('SELECT * FROM transactions WHERE id = :id FOR UPDATE', ['id' => $txnId]);
            if (!$t) {
                fail('Transaction not found.');
            }
            $cleared = self::clearedLines($txnId);
            $watch = self::$stockCheck ? self::stockBefore($txnId) : null;
            Stock::reverseTxn($txnId);
            DB::run('DELETE FROM journal_lines WHERE txn_id = :t', ['t' => $txnId]);

            $posts = !in_array($t['txn_type'], self::NON_POSTING, true)
                && (int)$t['is_voided'] === 0
                && (int)$t['is_pending'] === 0
                && $t['status'] !== 'suspended';

            if ($posts) {
                $engine = new self($t);
                Stock::$settlements = [];
                $engine->build();
                $engine->settled();
                $engine->write();
                if ($watch) {
                    self::stockAfter($watch);
                }
            }
            self::restoreCleared($t, $cleared);
        });
    }

    /** Quantities by site of the items a transaction moves (its lines and what it posted before), before it is posted again */
    private static function stockBefore(int $txnId): array
    {
        $items = array_values(array_unique(array_map('intval', array_merge(
            DB::column("SELECT DISTINCT l.item_id FROM transaction_lines l JOIN items i ON i.id = l.item_id
                         WHERE l.txn_id = :t AND i.item_type IN ('inventory', 'assembly')", ['t' => $txnId]),
            DB::column('SELECT DISTINCT item_id FROM inventory_movements WHERE txn_id = :t', ['t' => $txnId])
        ))));
        return $items ? ['items' => $items, 'qty' => self::siteQuantities($items)] : [];
    }

    private static function siteQuantities(array $items): array
    {
        [$in, $p] = DB::in('i', $items);
        $out = [];
        foreach (DB::all("SELECT item_id, site_id, qty_on_hand FROM item_site_stock WHERE item_id IN $in", $p) as $r) {
            $out[$r['item_id'] . ':' . $r['site_id']] = (float)$r['qty_on_hand'];
        }
        return $out;
    }

    /** Refuses the save, or warns, when it took a quantity below zero */
    private static function stockAfter(array $watch): void
    {
        $short = [];
        foreach (self::siteQuantities($watch['items']) as $k => $after) {
            $before = $watch['qty'][$k] ?? 0.0;
            if ($after < -0.00001 && $after < $before - 0.00001) {
                [$itemId, $siteId] = array_map('intval', explode(':', $k));
                $code = (string)DB::value('SELECT code FROM items WHERE id = :i', ['i' => $itemId]);
                $site = (string)DB::value('SELECT name FROM sites WHERE id = :s', ['s' => $siteId]);
                $short[] = [$code, $site, $before, $after];
            }
        }
        if (!$short) {
            return;
        }
        if (Settings::get('allow_negative_stock') !== '1') {
            fail('Not enough stock: ' . implode('; ', array_map(fn($s) => $s[0] . ' at ' . $s[1] . ' has ' . qty(max(0, $s[2])) . ' on hand, and this would leave ' . qty($s[3]), $short))
                . '. Selling or moving stock below zero is switched off (System Administration > Company, Security & Closing Date).');
        }
        if (negative_stock_ask()) {
            // the form asks first; nothing is saved until the user answers Yes
            throw new StockQuestion('Saving the transaction will lead the inventory to a negative balance: '
                . implode('; ', array_map(fn($s) => $s[0] . ' at ' . $s[1] . ' has ' . qty($s[2]) . ' on hand and would be left with ' . qty($s[3]), $short)) . '.', $short);
        }
        if (PHP_SAPI !== 'cli') {
            // shown only if the save goes through
            $msg = 'Stock is now below zero: ' . implode('; ', array_map(fn($s) => $s[0] . ' at ' . $s[1] . ' (' . qty($s[3]) . ')', $short)) . '.';
            DB::afterCommit(fn() => flash('warning', $msg));
        }
    }

    /** Remove everything a transaction posted (used when deleting). */
    public static function unpost(int $txnId): void
    {
        DB::tx(function () use ($txnId) {
            $t = DB::row('SELECT * FROM transactions WHERE id = :id', ['id' => $txnId]);
            $cleared = self::clearedLines($txnId);
            Stock::reverseTxn($txnId);
            DB::run('DELETE FROM journal_lines WHERE txn_id = :t', ['t' => $txnId]);
            if ($t) {
                self::restoreCleared($t, $cleared, 'deleted');
            }
        });
    }

    private function __construct(array $t)
    {
        $this->t = $t;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The taxes withheld on a payment, each to the account of its type ($sign 1 = debit, for a customer's payment;
     * -1 = credit, for a bill payment); anything not broken down by type goes to $fallback.
     */
    private function withheld(float $total, int $sign, int $fallback): void
    {
        $left = $total;
        if (Withholding::ready()) {
            foreach (DB::all('SELECT account_id, SUM(amount) AS amt FROM payment_withholdings WHERE payment_txn_id = :p GROUP BY account_id', ['p' => $this->t['id']]) as $w) {
                $this->add((int)$w['account_id'], $sign * (float)$w['amt'], []);
                $left -= (float)$w['amt'];
            }
        }
        if (abs($left) >= 0.005) {
            $this->add($fallback, $sign * r2($left), []);
        }
    }

    private function dr(int $account, float $amt, array $meta = []): void
    {
        $this->add($account, $amt, $meta);
    }

    private function cr(int $account, float $amt, array $meta = []): void
    {
        $this->add($account, -$amt, $meta);
    }

    private function add(int $account, float $signed, array $meta): void
    {
        $signed = r2($signed);
        if (abs($signed) < 0.005) {
            return;
        }
        $this->jl[] = array_merge([
            'account_id'  => $account,
            'debit'       => $signed > 0 ? $signed : 0,
            'credit'      => $signed < 0 ? -$signed : 0,
            'customer_id' => $this->t['customer_id'],
            'vendor_id'   => $this->t['vendor_id'],
            'item_id'     => null,
            'site_id'     => $this->t['site_id'],
            'class_id'    => $this->t['class_id'] ?? null,
            'other_name_id' => null,
            'cost_code_id' => null,
            'memo'        => $this->t['memo'],
        ], $meta);
    }

    private function lines(): array
    {
        return DB::all(
            'SELECT l.*, i.item_type, i.cost AS item_cost, i.income_account_id, i.cogs_account_id, i.asset_account_id, i.expense_account_id
               FROM transaction_lines l
               LEFT JOIN items i ON i.id = l.item_id
              WHERE l.txn_id = :t ORDER BY l.line_no, l.id',
            ['t' => $this->t['id']]
        );
    }

    private function taxAccount(?int $taxCodeId, string $side): int
    {
        if ($taxCodeId) {
            $col = $side === 'sales' ? 'sales_account_id' : 'purchase_account_id';
            $id = DB::value("SELECT $col FROM tax_codes WHERE id = :id", ['id' => $taxCodeId]);
            if ($id) {
                return (int)$id;
            }
        }
        return Accts::get($side === 'sales' ? 'output_tax' : 'input_tax');
    }

    private function site(array $l): int
    {
        $s = $l['site_id'] ?: $this->t['site_id'];
        if (!$s) {
            $s = DB::value('SELECT id FROM sites WHERE is_active = 1 ORDER BY id LIMIT 1');
        }
        if (!$s) {
            fail('Set up at least one inventory site before recording inventory transactions.');
        }
        return (int)$s;
    }

    private function assetAccount(array $l): int
    {
        return (int)($l['asset_account_id'] ?: Accts::get('inventory_asset'));
    }

    private function purchaseAccount(array $l): int
    {
        if ($l['line_kind'] === 'expense' || !$l['item_id']) {
            if (!$l['account_id']) {
                fail('Every expense line needs an account.');
            }
            return (int)$l['account_id'];
        }
        if (Stock::isTracked($l['item_type'])) {
            return $this->assetAccount($l);
        }
        return (int)($l['expense_account_id'] ?: ($l['cogs_account_id'] ?: Accts::get('default_expense')));
    }

    private function setLineCost(int $lineId, float $cost): void
    {
        DB::run('UPDATE transaction_lines SET cost_amount = :c WHERE id = :id', ['c' => r2($cost), 'id' => $lineId]);
    }

    private function lineMeta(array $l): array
    {
        return [
            'item_id'     => $l['item_id'],
            'site_id'     => $l['site_id'] ?: $this->t['site_id'],
            'customer_id' => $l['customer_id'] ?: $this->t['customer_id'],
            // a check made out to an employee or other name carries that name onto its lines,
            // so the subsidiary ledger of the account can list it
            'other_name_id' => ($l['other_name_id'] ?? null) ?: ($this->t['other_name_id'] ?? null),
            'class_id'    => ($l['class_id'] ?? null) ?: ($this->t['class_id'] ?? null),
            // construction: the cost code of the line goes to the ledger with the project (class)
            'cost_code_id' => ($l['cost_code_id'] ?? null) ?: null,
            'memo'        => $l['description'] ?: $this->t['memo'],
        ];
    }

    // ------------------------------------------------------------------ rules

    private function build(): void
    {
        $type = $this->t['txn_type'];
        switch ($type) {
            case 'invoice':
            case 'statement_charge':
            case 'finance_charge':
                $this->sales(fn(float $total) => $this->dr((int)($this->t['account_id'] ?: Accts::get('ar')), $total));
                break;
            case 'sales_receipt':
                $this->sales(fn(float $total) => $this->dr((int)($this->t['account_id'] ?: Accts::get('undeposited_funds')), $total));
                break;
            case 'pos_sale':
                $this->sales(fn(float $total) => $this->tenders($total, 1));
                break;
            case 'credit_memo':
                $this->returns(fn(float $total) => $this->cr((int)($this->t['account_id'] ?: Accts::get('ar')), $total));
                break;
            case 'pos_return':
                $this->returns(fn(float $total) => $this->tenders($total, -1));
                break;
            case 'refund':
                $this->dr(Accts::get('ar'), (float)$this->t['total']);
                $this->cr((int)$this->t['account_id'], (float)$this->t['total']);
                break;
            case 'payment':
                $t = $this->t;
                $this->dr((int)($t['account_id'] ?: Accts::get('undeposited_funds')), (float)$t['total']);
                if ((float)$t['discount_amount'] != 0.0) {
                    $this->dr(Accts::get('sales_discounts'), (float)$t['discount_amount']);
                }
                if ((float)$t['withholding_amount'] != 0.0) {
                    // each tax the customer withheld, to its own account (Creditable Withholding Tax, Creditable VAT Withheld)
                    $this->withheld((float)$t['withholding_amount'], 1, Accts::get('cwt'));
                }
                $this->cr((int)($t['account2_id'] ?: Accts::get('ar')), (float)$t['total'] + (float)$t['discount_amount'] + (float)$t['withholding_amount']);
                break;
            case 'bill':
            case 'item_receipt':
                $this->purchases(1, fn(float $total) => $this->cr((int)($this->t['account_id'] ?: Accts::get('ap')), $total));
                break;
            case 'vendor_credit':
                $this->purchases(-1, fn(float $total) => $this->dr((int)($this->t['account_id'] ?: Accts::get('ap')), $total));
                break;
            case 'check':
                if (!$this->t['account_id']) {
                    fail('Choose the bank account the check is drawn on.');
                }
                $this->purchases(1, fn(float $total) => $this->cr((int)$this->t['account_id'], $total));
                break;
            case 'bill_payment':
                // Accounts Payable is cleared by what was paid, the discount taken and the taxes withheld; the taxes
                // withheld are owed to the BIR until remitted (Withholding Tax Payable, VAT Withheld Payable).
                // A payment made out of an advance to the supplier credits the advances account instead of a bank.
                $t = $this->t;
                $wht = (float)$t['withholding_amount'];
                $this->dr((int)($t['account2_id'] ?: Accts::get('ap')), (float)$t['total'] + (float)$t['discount_amount'] + $wht);
                $this->cr((int)$t['account_id'], (float)$t['total']);
                if ((float)$t['discount_amount'] != 0.0) {
                    $this->cr(Accts::get('purchase_discounts'), (float)$t['discount_amount']);
                }
                if ($wht != 0.0) {
                    $this->withheld($wht, -1, Accts::get('wht_payable'));
                }
                break;
            case 'vendor_advance':
                // money paid to a supplier before the goods or services come: an asset until it is applied to bills
                $this->dr((int)($this->t['account2_id'] ?: Accts::get('vendor_advances')), (float)$this->t['total']);
                $this->cr((int)$this->t['account_id'], (float)$this->t['total']);
                break;
            case 'deposit':
                $this->deposit();
                break;
            case 'transfer':
                $this->dr((int)$this->t['account2_id'], (float)$this->t['total'], ['customer_id' => null, 'vendor_id' => null]);
                $this->cr((int)$this->t['account_id'], (float)$this->t['total'], ['customer_id' => null, 'vendor_id' => null]);
                break;
            case 'inventory_adjustment':
                $this->adjustment();
                break;
            case 'inventory_transfer':
                $this->transferStock();
                break;
            case 'build_assembly':
                $this->build_assembly();
                break;
            case Processing::TXN_TYPE:
                $this->processing();
                break;
            case self::CASH_VOUCHER:
                $this->cashVoucher();
                break;
            default:
                if (in_array($type, self::JOURNAL_TYPES, true)) {
                    $this->journal();
                } else {
                    fail("Unknown transaction type: $type");
                }
        }
    }

    /** Invoices, sales receipts, statement/finance charges and POS sales */
    private function sales(callable $header): void
    {
        $total = 0.0;
        foreach ($this->lines() as $l) {
            if ($l['item_type'] === 'subtotal') {
                continue;
            }
            $meta = $this->lineMeta($l);
            $amount = (float)$l['amount'];
            if ($l['item_type'] === 'payment') {
                // payment item: money received on the document itself
                $this->dr(Accts::get('undeposited_funds'), -$amount, $meta);
                $total += $amount;
                continue;
            }
            $income = (int)($l['account_id'] ?: ($l['income_account_id'] ?: Accts::get(
                $this->t['txn_type'] === 'finance_charge' ? 'finance_charge_income' : 'sales_income'
            )));
            $this->cr($income, $amount, $meta);
            $total += $amount;
            if ((float)$l['tax_amount'] != 0.0) {
                $this->cr($this->taxAccount($l['tax_code_id'] ? (int)$l['tax_code_id'] : null, 'sales'), (float)$l['tax_amount'], $meta);
                $total += (float)$l['tax_amount'];
            }
            if (Stock::isTracked($l['item_type']) && abs((float)$l['qty']) > 0) {
                $value = Stock::move((int)$this->t['id'], (int)$l['id'], $this->t['txn_date'], (int)$l['item_id'], $this->site($l), -(float)$l['qty']);
                $cost = -$value;
                $this->setLineCost((int)$l['id'], $cost);
                $this->dr((int)($l['cogs_account_id'] ?: Accts::get('cogs')), $cost, $meta);
                $this->cr($this->assetAccount($l), $cost, $meta);
            }
        }
        $header(r2($total));
    }

    /** Credit memos and POS returns */
    private function returns(callable $header): void
    {
        $total = 0.0;
        foreach ($this->lines() as $l) {
            if ($l['item_type'] === 'subtotal') {
                continue;
            }
            $meta = $this->lineMeta($l);
            $amount = (float)$l['amount'];
            $acct = (int)($l['account_id'] ?: Accts::get('sales_returns'));
            $this->dr($acct, $amount, $meta);
            $total += $amount;
            if ((float)$l['tax_amount'] != 0.0) {
                $this->dr($this->taxAccount($l['tax_code_id'] ? (int)$l['tax_code_id'] : null, 'sales'), (float)$l['tax_amount'], $meta);
                $total += (float)$l['tax_amount'];
            }
            if ((int)$this->t['restock'] === 1 && Stock::isTracked($l['item_type']) && abs((float)$l['qty']) > 0) {
                $site = (int)($this->t['site2_id'] ?: $this->site($l));
                $value = Stock::move((int)$this->t['id'], (int)$l['id'], $this->t['txn_date'], (int)$l['item_id'], $site, (float)$l['qty']);
                $this->setLineCost((int)$l['id'], $value);
                $this->dr($this->assetAccount($l), $value, $meta + ['site_id' => $site]);
                $this->cr((int)($l['cogs_account_id'] ?: Accts::get('cogs')), $value, $meta + ['site_id' => $site]);
            }
        }
        $header(r2($total));
    }

    /** POS tenders: $sign 1 = money in (sale), -1 = money out (refund) */
    private function tenders(float $total, int $sign): void
    {
        $tenders = DB::all(
            'SELECT pt.amount, pm.method_type, pm.clearing_account_id FROM pos_tenders pt
               JOIN payment_methods pm ON pm.id = pt.payment_method_id WHERE pt.txn_id = :t',
            ['t' => $this->t['id']]
        );
        $cashAcct = (int)(DB::value('SELECT cash_account_id FROM pos_terminals WHERE id = :id', ['id' => $this->t['terminal_id']]) ?: Accts::get('cash_on_hand'));
        $sum = 0.0;
        foreach ($tenders as $tn) {
            if ($tn['method_type'] === 'cash') {
                $acct = $cashAcct;
            } else {
                $acct = (int)($tn['clearing_account_id'] ?: Accts::get($tn['method_type'] === 'ewallet' ? 'ewallet_clearing' : 'card_clearing'));
            }
            $this->add($acct, $sign * (float)$tn['amount'], ['customer_id' => null]);
            $sum += (float)$tn['amount'];
        }
        if (abs(r2($sum) - $total) >= 0.01) {
            fail('Tenders (' . money($sum) . ') do not equal the sale total (' . money($total) . ').');
        }
    }

    /** Bills, vendor credits, item receipts and checks. $sign 1 = debit lines, -1 = credit lines */
    private function purchases(int $sign, callable $header): void
    {
        $total = 0.0;
        foreach ($this->lines() as $l) {
            if ($l['item_type'] === 'subtotal') {
                continue;
            }
            $meta = $this->lineMeta($l);
            $amount = (float)$l['amount'];
            $acct = $this->purchaseAccount($l);
            if ($l['line_kind'] !== 'expense' && Stock::isTracked($l['item_type']) && abs((float)$l['qty']) > 0) {
                $unit = $amount / (float)$l['qty'];
                $value = Stock::move((int)$this->t['id'], (int)$l['id'], $this->t['txn_date'], (int)$l['item_id'], $this->site($l), $sign * (float)$l['qty'], $unit, 0.0, $amount);
                $amountPosted = abs($value);
                $this->add($acct, $sign * $amountPosted, $meta);
                $total += $amountPosted;
            } else {
                $this->add($acct, $sign * $amount, $meta);
                $total += $amount;
            }
            if ((float)$l['tax_amount'] != 0.0) {
                $this->add($this->taxAccount($l['tax_code_id'] ? (int)$l['tax_code_id'] : null, 'purchase'), $sign * (float)$l['tax_amount'], $meta);
                $total += (float)$l['tax_amount'];
            }
        }
        $header(r2($total));
    }

    private function deposit(): void
    {
        $t = $this->t;
        $sum = 0.0;
        foreach ($this->lines() as $l) {
            if (!$l['account_id']) {
                fail('Every deposit line needs a "from" account.');
            }
            $meta = ['customer_id' => $l['customer_id'], 'vendor_id' => $l['vendor_id'], 'memo' => $l['description']];
            $this->cr((int)$l['account_id'], (float)$l['amount'], $meta);
            $sum += (float)$l['amount'];
        }
        $cashBack = (float)$t['cash_back_amount'];
        if ($cashBack > 0) {
            if (!$t['account2_id']) {
                fail('Choose the account for the cash taken back.');
            }
            $this->dr((int)$t['account2_id'], $cashBack, ['customer_id' => null]);
        }
        $this->dr((int)$t['account_id'], r2($sum - $cashBack), ['customer_id' => null]);
    }

    private function journal(): void
    {
        foreach ($this->lines() as $l) {
            if (!$l['account_id']) {
                fail('Every journal line needs an account.');
            }
            $this->add((int)$l['account_id'], (float)$l['amount'], [
                'customer_id' => $l['customer_id'],
                'vendor_id'   => $l['vendor_id'],
                'other_name_id' => $l['other_name_id'] ?? null,
                'item_id'     => $l['item_id'],
                'site_id'     => $l['site_id'],
                'class_id'    => ($l['class_id'] ?? null) ?: ($this->t['class_id'] ?? null),
                'cost_code_id' => ($l['cost_code_id'] ?? null) ?: null,
                'memo'        => $l['description'] ?: $this->t['memo'],
            ]);
        }
    }

    /**
     * Cash voucher (petty cash, revolving fund and other cash payments):
     *  the account lines as entered (+ debit / - credit), and one credit to the cash account
     *  for the cash amount. The page makes sure debits less credits equal the cash amount.
     */
    private function cashVoucher(): void
    {
        $t = $this->t;
        if (!$t['account_id']) {
            fail('Choose the cash account the voucher is paid from.');
        }
        $this->journal();
        $this->cr((int)$t['account_id'], (float)$t['total'], [
            'other_name_id' => $t['other_name_id'] ?? null,
            'memo'          => $t['memo'] ?: ('Cash voucher ' . ($t['txn_no'] ?? '')),
        ]);
    }

    private function adjustment(): void
    {
        $adjAcct = (int)($this->t['account_id'] ?: Accts::get('inventory_adjustment'));
        foreach ($this->lines() as $l) {
            if (!Stock::isTracked($l['item_type'])) {
                continue;
            }
            $site = $this->site($l);
            $value = 0.0;
            $q = (float)$l['qty'];
            if (abs($q) > 0) {
                $unit = ($q > 0 && (float)$l['rate'] > 0) ? (float)$l['rate'] : null;
                $value += Stock::move((int)$this->t['id'], (int)$l['id'], $this->t['txn_date'], (int)$l['item_id'], $site, $q, $unit);
            }
            if (abs((float)$l['value_change']) >= 0.005) {
                $value += Stock::move((int)$this->t['id'], (int)$l['id'], $this->t['txn_date'], (int)$l['item_id'], $site, 0, null, (float)$l['value_change']);
            }
            $this->setLineCost((int)$l['id'], $value);
            // the class of the line (Per Item Adjustment has one on every line), else the adjustment's own;
            // the Customer:Job of the adjustment, when one was chosen, for job costing
            $meta = ['item_id' => $l['item_id'], 'site_id' => $site, 'memo' => $l['description'] ?: $this->t['memo'],
                'customer_id' => ($l['customer_id'] ?? null) ?: ($this->t['customer_id'] ?? null),
                'class_id' => ($l['class_id'] ?? null) ?: ($this->t['class_id'] ?? null)];
            $this->add($this->assetAccount($l), $value, $meta);
            // construction: a material issued to a project carries its cost code on the cost side
            $this->add($adjAcct, -$value, $meta + ['cost_code_id' => ($l['cost_code_id'] ?? null) ?: null]);
        }
    }

    private function transferStock(): void
    {
        $from = (int)$this->t['site_id'];
        $to = (int)$this->t['site2_id'];
        if (!$from || !$to || $from === $to) {
            fail('Choose two different sites for the transfer.');
        }
        // v31: stock moved between sites is not a new receipt: an item below zero at all sites keeps its shortfall
        Stock::$settleShortfall = false;
        try {
            foreach ($this->lines() as $l) {
                if (!Stock::isTracked($l['item_type']) || abs((float)$l['qty']) <= 0) {
                    continue;
                }
                $q = abs((float)$l['qty']);
                $value = Stock::move((int)$this->t['id'], (int)$l['id'], $this->t['txn_date'], (int)$l['item_id'], $from, -$q);
                Stock::move((int)$this->t['id'], (int)$l['id'], $this->t['txn_date'], (int)$l['item_id'], $to, $q, null, 0.0, -$value);
                $this->setLineCost((int)$l['id'], -$value);
            }
        } finally {
            Stock::$settleShortfall = true;
        }
        // same asset account on both sides: total inventory value does not change
    }

    /**
     * v31: a receipt into stock that had been sold or used below zero settles that shortfall at the cost received
     * (Stock::move). The difference between what the shortfall was costed at and what it really cost goes to cost of
     * goods sold: Dr Cost of goods sold / Cr Inventory when it cost more (the other way round when less), so the
     * average of what is left is the cost received and is not blown up by a few units carrying the whole difference.
     */
    private function settled(): void
    {
        foreach (Stock::$settlements as $s) {
            $acc = DB::row('SELECT cogs_account_id, asset_account_id FROM items WHERE id = :i', ['i' => $s['item_id']]) ?: [];
            $cogs = (int)(($acc['cogs_account_id'] ?? null) ?: Accts::get('cogs'));
            $asset = (int)(($acc['asset_account_id'] ?? null) ?: Accts::get('inventory_asset'));
            $meta = ['item_id' => $s['item_id'], 'site_id' => $s['site_id'], 'customer_id' => null,
                'memo' => 'Stock sold below zero, settled at the cost received ' . ($this->t['txn_no'] ?? '')];
            $this->add($cogs, (float)$s['amount'], $meta);
            $this->add($asset, -(float)$s['amount'], $meta);
        }
        Stock::$settlements = [];
    }

    private function build_assembly(): void
    {
        $lines = $this->lines();
        $assembly = null;
        $site = (int)$this->t['site_id'];
        $totalCost = 0.0;
        foreach ($lines as $l) {
            if ($l['line_kind'] === 'item') {
                $assembly = $l;
                continue;
            }
            // component line: stocked parts are issued at average cost;
            // labor / overhead charges are absorbed at their standard cost
            if (Stock::isTracked($l['item_type'])) {
                $value = Stock::move((int)$this->t['id'], (int)$l['id'], $this->t['txn_date'], (int)$l['item_id'], $site, -(float)$l['qty']);
                $cost = -$value;
                $acct = $this->assetAccount($l);
            } else {
                $cost = r2((float)$l['qty'] * (float)$l['item_cost']);
                $acct = (int)($l['expense_account_id'] ?: ($l['cogs_account_id'] ?: Accts::get('default_expense')));
            }
            $this->setLineCost((int)$l['id'], $cost);
            $this->cr($acct, $cost, ['item_id' => $l['item_id'], 'customer_id' => null]);
            $totalCost += $cost;
        }
        if (!$assembly) {
            fail('The build has no assembly item.');
        }
        $q = (float)$assembly['qty'];
        if ($q <= 0) {
            fail('Quantity to build must be greater than zero.');
        }
        Stock::move((int)$this->t['id'], (int)$assembly['id'], $this->t['txn_date'], (int)$assembly['item_id'], $site, $q, null, 0.0, $totalCost);
        $this->setLineCost((int)$assembly['id'], $totalCost);
        $this->dr($this->assetAccount($assembly), $totalCost, ['item_id' => $assembly['item_id'], 'customer_id' => null]);
    }

    /**
     * Disassembly / de-kitting / co-product processing.
     *  Cr inputs (average cost) and additional cost accounts  = total cost
     *  Dr outputs (allocated cost)                             + variance (valuation methods)
     */
    private function processing(): void
    {
        $t = $this->t;
        $lines = $this->lines();
        $inSite = $this->site(['site_id' => null]);
        $outSite = (int)($t['site2_id'] ?: $inSite);
        $materials = 0.0;
        $extra = 0.0;
        $outputs = [];
        foreach ($lines as $l) {
            $meta = ['item_id' => $l['item_id'], 'customer_id' => null, 'memo' => $l['description'] ?: $t['memo']];
            if ($l['line_kind'] === 'input') {
                $q = (float)$l['qty'];
                if ($q <= 0 || !$l['item_id']) {
                    fail('Every input needs an item and a quantity greater than zero.');
                }
                if (Stock::isTracked($l['item_type'])) {
                    $site = (int)($l['site_id'] ?: $inSite);
                    $cost = -Stock::move((int)$t['id'], (int)$l['id'], $t['txn_date'], (int)$l['item_id'], $site, -$q);
                    $acct = $this->assetAccount($l);
                    $meta['site_id'] = $site;
                } else {
                    // labor / supplies as items: absorbed at their standard cost
                    $cost = r2($q * (float)$l['item_cost']);
                    $acct = (int)($l['expense_account_id'] ?: ($l['cogs_account_id'] ?: Accts::get('default_expense')));
                }
                $this->setLineCost((int)$l['id'], $cost);
                DB::run('UPDATE transaction_lines SET rate = :r, amount = :a WHERE id = :id', ['r' => round($cost / $q, 4), 'a' => r2($cost), 'id' => $l['id']]);
                $this->cr($acct, $cost, $meta);
                $materials += $cost;
            } elseif ($l['line_kind'] === 'cost') {
                $amt = r2((float)$l['amount']);
                if ($amt <= 0 || !$l['account_id']) {
                    fail('Every additional cost needs an account and an amount greater than zero.');
                }
                $this->setLineCost((int)$l['id'], $amt);
                $this->cr((int)$l['account_id'], $amt, ['customer_id' => null, 'memo' => $l['description'] ?: $t['memo']]);
                $extra += $amt;
            } elseif ($l['line_kind'] === 'output') {
                if (!$l['item_id'] || !Stock::isTracked($l['item_type'])) {
                    fail('Outputs must be inventory or assembly items.');
                }
                if ((float)$l['qty'] <= 0) {
                    fail('Every output needs a quantity greater than zero.');
                }
                $outputs[(int)$l['id']] = ['line' => $l, 'item_id' => (int)$l['item_id'], 'qty' => (float)$l['qty'],
                    'role' => $l['output_role'] ?: 'co_product', 'basis' => (float)$l['alloc_basis']];
            }
        }
        if (!$outputs) {
            fail('The processing order has no output items.');
        }
        if (!array_filter($lines, fn($l) => $l['line_kind'] === 'input')) {
            fail('The processing order has no input items.');
        }
        $pool = r2($materials + $extra);
        $alloc = Processing::allocate($outputs, $pool, (string)($t['alloc_method'] ?: Processing::defaultMethod((string)$t['process_type'])));
        // v29: an input with no unit cost, or a co-product / by-product received at no cost, is not posted
        if (Settings::get('block_zero_cost_processing', '1') === '1') {
            $noCost = [];
            $codes = DB::pairs('SELECT l.id, i.code FROM transaction_lines l JOIN items i ON i.id = l.item_id WHERE l.txn_id = :t', ['t' => $t['id']]);
            foreach ($lines as $l) {
                if ($l['line_kind'] === 'input' && (float)DB::value('SELECT cost_amount FROM transaction_lines WHERE id = :id', ['id' => $l['id']]) <= 0.0) {
                    $noCost['in'][] = $codes[$l['id']] ?? ($l['description'] ?: 'an input');
                }
            }
            foreach ($outputs as $lineId => $o) {
                if ($o['role'] !== 'scrap' && (float)($alloc['rows'][$lineId]['unit'] ?? 0) <= 0.0) {
                    $noCost['out'][] = $codes[$lineId] ?? ($o['line']['description'] ?: 'an output');
                }
            }
            if ($noCost) {
                fail('The processing order cannot be posted with no unit cost: '
                    . (!empty($noCost['in']) ? 'the input' . (count($noCost['in']) > 1 ? 's ' : ' ') . implode(', ', $noCost['in']) . ' ha' . (count($noCost['in']) > 1 ? 've' : 's') . ' no unit cost or average unit cost' : '')
                    . (!empty($noCost['in']) && !empty($noCost['out']) ? ', and ' : '')
                    . (!empty($noCost['out']) ? 'the output' . (count($noCost['out']) > 1 ? 's ' : ' ') . implode(', ', $noCost['out']) . ' would be received at no cost' : '')
                    . '. Enter the unit cost of the inputs (Inventory > Item List) or receive them at their cost first, and give every co-product and by-product a share of the cost; or save the order as pending.');
            }
        }
        foreach ($outputs as $lineId => $o) {
            $l = $o['line'];
            $r = $alloc['rows'][$lineId];
            $site = (int)($l['site_id'] ?: $outSite);
            Stock::move((int)$t['id'], $lineId, $t['txn_date'], $o['item_id'], $site, $o['qty'], null, 0.0, $r['cost']);
            DB::run(
                'UPDATE transaction_lines SET cost_amount = :c, amount = :c2, rate = :u, alloc_pct = :p WHERE id = :id',
                ['c' => $r['cost'], 'c2' => $r['cost'], 'u' => $r['unit'], 'p' => $r['pct'], 'id' => $lineId]
            );
            $this->dr($this->assetAccount($l), $r['cost'], ['item_id' => $o['item_id'], 'site_id' => $site, 'customer_id' => null, 'memo' => $l['description'] ?: $t['memo']]);
        }
        if (abs($alloc['variance']) >= 0.005) {
            $varAcct = (int)($t['account_id'] ?: Accts::get('processing_variance'));
            $this->dr($varAcct, $alloc['variance'], ['customer_id' => null, 'memo' => 'Processing variance ' . ($t['txn_no'] ?? '')]);
        }
        DB::run(
            'UPDATE transactions SET subtotal = :m, discount_amount = 0, total = :p WHERE id = :id',
            ['m' => r2($materials), 'p' => $pool, 'id' => $t['id']]
        );
    }

    // ------------------------------------------------------------------ write

    private function write(): void
    {
        $dr = 0.0;
        $cr = 0.0;
        foreach ($this->jl as $j) {
            $dr += $j['debit'];
            $cr += $j['credit'];
        }
        if (abs(r2($dr) - r2($cr)) >= 0.01) {
            fail(sprintf('The entry does not balance (debits %s, credits %s).', money($dr), money($cr)));
        }
        foreach ($this->jl as $j) {
            $j['txn_id'] = $this->t['id'];
            $j['txn_date'] = $this->t['txn_date'];
            if ($j['memo'] !== null) {
                $j['memo'] = mb_substr((string)$j['memo'], 0, 255);
            }
            DB::insert('journal_lines', $j);
        }
    }

    // ------------------------------------------------------------------ reconciliation protection

    private static function clearedLines(int $txnId): array
    {
        return DB::all(
            'SELECT account_id, SUM(debit - credit) AS amt, MAX(reconciliation_id) AS rec_id, MAX(cleared) AS cleared
               FROM journal_lines WHERE txn_id = :t AND cleared = 1 GROUP BY account_id',
            ['t' => $txnId]
        );
    }

    /** Keep the cleared mark if the amount did not change; otherwise log a discrepancy. */
    private static function restoreCleared(array $t, array $cleared, string $forced = ''): void
    {
        foreach ($cleared as $c) {
            $newAmt = (float)DB::value(
                'SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE txn_id = :t AND account_id = :a',
                ['t' => $t['id'], 'a' => $c['account_id']]
            );
            if ($forced === '' && abs($newAmt - (float)$c['amt']) < 0.005) {
                DB::run(
                    'UPDATE journal_lines SET cleared = 1, reconciliation_id = :r WHERE txn_id = :t AND account_id = :a',
                    ['r' => $c['rec_id'], 't' => $t['id'], 'a' => $c['account_id']]
                );
                continue;
            }
            $type = $forced ?: ((int)$t['is_voided'] === 1 ? 'voided' : 'amount changed');
            DB::insert('reconciliation_discrepancies', [
                'reconciliation_id' => $c['rec_id'],
                'account_id'        => $c['account_id'],
                'txn_id'            => $t['id'],
                'txn_ref'           => Txn::label($t['txn_type']) . ' ' . ($t['txn_no'] ?? ''),
                'change_type'       => $type,
                'cleared_amount'    => r2($c['amt']),
                'new_amount'        => $forced ? 0 : r2($newAmt),
                'changed_by'        => Auth::id(),
            ]);
        }
    }
}
