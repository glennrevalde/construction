<?php
declare(strict_types=1);

/** Module default accounts (the mapping set up before any transaction is recorded). */
final class Accts
{
    private static ?array $map = null;

    public const KEYS = [
        // key => [label, module]
        'ar'                  => ['Accounts Receivable', 'cust'],
        'undeposited_funds'   => ['Undeposited Funds', 'cust'],
        'sales_income'        => ['Default Sales Income', 'cust'],
        'sales_returns'       => ['Sales Returns and Allowances', 'cust'],
        'sales_discounts'     => ['Sales Discounts', 'cust'],
        'finance_charge_income' => ['Finance Charge Income', 'cust'],
        'reimbursable_income' => ['Reimbursable Expense Income', 'cust'],
        'cwt'                 => ['Creditable Withholding Tax', 'cust'],
        'cvat_withheld'       => ['Creditable VAT Withheld', 'cust'],
        'output_tax'          => ['Output Tax', 'cust'],
        'ap'                  => ['Accounts Payable', 'vend'],
        'input_tax'           => ['Input Tax', 'vend'],
        'purchase_discounts'  => ['Purchase Discounts', 'vend'],
        'wht_payable'         => ['Withholding Tax Payable (Expanded)', 'vend'],
        'vat_withheld_payable'=> ['VAT Withheld Payable', 'vend'],
        'vendor_advances'     => ['Advances to Suppliers', 'vend'],
        'default_expense'     => ['Default Purchases / Expense', 'vend'],
        'inventory_asset'     => ['Inventory Asset', 'inv'],
        'cogs'                => ['Cost of Goods Sold', 'inv'],
        'inventory_adjustment'=> ['Inventory Adjustment (Shrinkage)', 'inv'],
        'processing_variance' => ['Processing / Disassembly Variance', 'inv'],
        'group_price_variance'=> ['Item Group Price Variance', 'inv'],
        'processing_absorbed' => ['Processing Labor & Overhead Absorbed', 'inv'],
        'cash_on_hand'        => ['Cash on Hand (Register)', 'pos'],
        'cash_over_short'     => ['Cash Over and Short', 'pos'],
        'card_clearing'       => ['Card Clearing', 'pos'],
        'ewallet_clearing'    => ['E-wallet Clearing', 'pos'],
        'merchant_fees'       => ['Bank / Merchant Charges', 'bank'],
        'interest_income'     => ['Interest Income', 'bank'],
        'retained_earnings'   => ['Retained Earnings', 'coa'],
        'opening_equity'      => ['Opening Balance Equity', 'coa'],
        'gain_loss_disposal'  => ['Gain / Loss on Disposal of Assets', 'fa'],
        // construction (GSMaRT Construction System): progress billing, revenue, retention and project costs
        'cs_contract_revenue'   => ['Contract Revenue (earned)', 'proj'],
        'cs_progress_billings'  => ['Progress Billings on Contracts (contract liability)', 'proj'],
        'cs_contract_asset'     => ['Contract Assets - Revenue Earned Not Billed', 'proj'],
        'cs_retention_receivable' => ['Retention Receivable', 'proj'],
        'cs_client_advances'    => ['Advances from Clients (mobilization)', 'proj'],
        'cs_retention_payable'  => ['Retention Payable (subcontractors)', 'proj'],
        'cs_materials_cost'     => ['Direct Materials Cost', 'proj'],
        'cs_labor_cost'         => ['Direct Labor Cost', 'proj'],
        'cs_labor_burden'       => ['Labor Burden and Benefits', 'proj'],
        'cs_indirect_labor'     => ['Indirect Labor / Site Supervision', 'proj'],
        'cs_equipment_cost'     => ['Equipment Cost Charged to Projects', 'proj'],
        'cs_equipment_recovery' => ['Equipment Cost Recovery (contra)', 'proj'],
        'cs_subcontract_cost'   => ['Subcontract Cost', 'proj'],
        'cs_other_direct'       => ['Other Direct Project Costs', 'proj'],
        'cs_loss_provision'     => ['Provision for Contract Losses', 'proj'],
        'cs_loss_liability'     => ['Provision for Losses on Contracts (liability)', 'proj'],
        'cs_accrued_payroll'    => ['Accrued Payroll (approved timesheets)', 'proj'],
        'cs_wages_payable'      => ['Salaries and Wages Payable (net pay)', 'proj'],
        'cs_statutory_payable'  => ['SSS, PhilHealth and Pag-IBIG Payable', 'proj'],
        'cs_employee_advances'  => ['Advances to Employees (cash advances)', 'proj'],
    ];

    public static function get(string $key): int
    {
        if (self::$map === null) {
            self::$map = DB::pairs('SELECT setting_key, account_id FROM default_accounts');
        }
        $id = self::$map[$key] ?? null;
        if (!$id) {
            fail('The default account "' . (self::KEYS[$key][0] ?? $key) . '" is not set. Set it under Financial Set-up & Reports > Module Default Accounts.');
        }
        return (int)$id;
    }

    public static function reset(): void
    {
        self::$map = null;
    }

    public static function ofType(array $types): array
    {
        [$in, $p] = DB::in('ty', $types);
        return DB::pairs("SELECT id, CONCAT(code, ' - ', name) FROM accounts WHERE is_active = 1 AND account_type IN $in ORDER BY code", $p);
    }

    public static function all(bool $activeOnly = true): array
    {
        return DB::pairs("SELECT id, CONCAT(code, ' - ', name) FROM accounts" . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY code');
    }

    public static function banks(): array
    {
        return self::ofType(['Bank']);
    }

    /** Accounts that can receive deposits (bank, undeposited funds, cash) */
    public static function depositTo(): array
    {
        return self::ofType(['Bank', 'Other Current Asset']);
    }

    public static function arIds(): array
    {
        return array_map('intval', DB::column("SELECT id FROM accounts WHERE account_type = 'Accounts Receivable'"));
    }

    public static function apIds(): array
    {
        return array_map('intval', DB::column("SELECT id FROM accounts WHERE account_type = 'Accounts Payable'"));
    }

    // ------------------------------------------------------------------ subsidiary ledgers

    /**
     * Accounts marked "With SL": they keep a subsidiary ledger, so every entry to them has to
     * name a customer, supplier, employee or other name. id => "code - name".
     */
    public static function withSl(): array
    {
        static $c = null;
        if ($c === null) {
            try {
                $c = DB::pairs("SELECT id, CONCAT(code, ' - ', name) FROM accounts WHERE with_sl = 1 ORDER BY code");
            } catch (Throwable $e) {
                $c = [];      // the column is added by the 2026_09_24 migration
            }
        }
        return $c;
    }

    public static function isWithSl(?int $accountId): bool
    {
        return $accountId !== null && isset(self::withSl()[$accountId]);
    }

    /**
     * Stops a journal entry, cash voucher or check whose line posts to a "With SL" account without a
     * name on it. A line carries its own name; when it has none the payee of the whole transaction
     * counts, which is how a check made out to one supplier works.
     *
     * @param array $lines  the parsed lines
     * @param array $header the transaction header (its customer_id / vendor_id / other_name_id)
     */
    public static function requireSl(array $lines, array $header = []): void
    {
        $flagged = self::withSl();
        if (!$flagged) {
            return;
        }
        $onHeader = !empty($header['customer_id']) || !empty($header['vendor_id']) || !empty($header['other_name_id']);
        $missing = [];
        foreach ($lines as $l) {
            $acct = !empty($l['account_id']) ? (int)$l['account_id'] : null;
            if ($acct === null || !isset($flagged[$acct])) {
                continue;
            }
            $onLine = !empty($l['customer_id']) || !empty($l['vendor_id']) || !empty($l['other_name_id']);
            if (!$onLine && !$onHeader) {
                $missing[$acct] = $flagged[$acct];
            }
        }
        if ($missing) {
            fail(count($missing) === 1
                ? 'The account ' . reset($missing) . ' is marked "With SL", so the line posting to it needs a name: choose a customer, supplier, employee or other name.'
                : 'These accounts are marked "With SL", so the lines posting to them need a name (customer, supplier, employee or other name): ' . implode('; ', $missing) . '.');
        }
    }

    public static function balance(int $accountId, ?string $asOf = null): float
    {
        $sql = 'SELECT COALESCE(SUM(jl.debit - jl.credit),0) FROM journal_lines jl WHERE jl.account_id = :a';
        $p = ['a' => $accountId];
        if ($asOf) {
            $sql .= ' AND jl.txn_date <= :d';
            $p['d'] = $asOf;
        }
        $v = (float)DB::value($sql, $p);
        $nb = DB::value('SELECT normal_balance FROM accounts WHERE id = :a', ['a' => $accountId]);
        return r2($nb === 'Credit' ? -$v : $v);
    }
}
