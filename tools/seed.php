<?php
declare(strict_types=1);

/**
 * Set-up data of a new GSMaRT Construction System company (the clean database):
 * settings, modules and pages, the construction chart of accounts and its default accounts, tax codes and the
 * withholding tax table, terms and payment methods, units of measure, the main warehouse, the 26 standard roles
 * with their page access, one user account per role, cost codes, trades, checklist templates, approval routes,
 * allocation rules and document numbering.
 *
 * Sample clients, suppliers, subcontractors, materials and everything that happens on the projects are added by
 * tools/demo_data.php (the sample database).
 */

/** The user accounts shipped with the system, lowest level first: [username, full name, role, password, position] */
function seed_user_list(): array
{
    return [
        ['subcon', 'Allan Torres', 'Subcontractor Portal User', 'Demo@12345', 'Subcontractor representative'],
        ['client', 'Atty. Ramon Velasco', 'External Client / Client Representative', 'Demo@12345', 'Client representative'],
        ['timekeeper', 'Pedro Castillo', 'Timekeeper', 'Demo@12345', 'Timekeeper'],
        ['foreman', 'Nestor Ramos', 'Site Supervisor / Foreman', 'Demo@12345', 'Foreman'],
        ['warehouse', 'Dan Villanueva', 'Warehouse Officer', 'Demo@12345', 'Warehouse officer'],
        ['cashier', 'Ella Ramos', 'Collection Officer / Cashier', 'Demo@12345', 'Collection officer'],
        ['safety', 'Jose Mercado', 'Safety Officer', 'Demo@12345', 'Safety officer'],
        ['qa', 'Engr. Paolo Garcia', 'Quality Inspector', 'Demo@12345', 'QA/QC inspector'],
        ['billing', 'Maria Santos', 'Billing Officer', 'Demo@12345', 'Billing officer'],
        ['acctstaff', 'Ben Garcia', 'Accounting Staff', 'Demo@12345', 'Accounting staff'],
        ['procurement', 'Carla Lim', 'Procurement Officer', 'Demo@12345', 'Procurement officer'],
        ['hr', 'Angela Torres', 'Human Resources Officer', 'Demo@12345', 'HR officer'],
        ['equipmgr', 'Rogelio Navarro', 'Equipment Manager', 'Demo@12345', 'Equipment manager'],
        ['siteeng', 'Engr. Mark Dizon', 'Site Engineer', 'Demo@12345', 'Site engineer'],
        ['qs', 'Joy Fernandez', 'Quantity Surveyor', 'Demo@12345', 'Quantity surveyor'],
        ['auditor', 'Grace Uy', 'Internal Auditor', 'Demo@12345', 'Internal auditor'],
        ['purchasing', 'Victor Chua', 'Purchasing Manager', 'Demo@12345', 'Purchasing manager'],
        ['controller', 'Ana Cruz, CPA', 'Chief Accountant / Controller', 'Demo@12345', 'Chief accountant'],
        ['pm', 'Engr. Carlo Reyes', 'Project Manager', 'Demo@12345', 'Project manager'],
        ['pm2', 'Engr. Liza Bautista', 'Project Manager', 'Demo@12345', 'Project manager'],
        ['finmgr', 'Michelle Tan', 'Finance Manager', 'Demo@12345', 'Finance manager'],
        ['projdir', 'Engr. Ramon Aquino', 'Project Director', 'Demo@12345', 'Project director'],
        ['opsmgr', 'Teresa Mendoza', 'Operations Manager', 'Demo@12345', 'Operations manager'],
        ['gm', 'Ricardo Villanueva', 'General Manager', 'Demo@12345', 'General manager'],
        ['owner', 'Eduardo Santos', 'Business Owner / President', 'Demo@12345', 'President'],
        ['admin', 'System Administrator', 'Company Administrator', 'Admin@12345', 'System administrator'],
    ];
}

function seed_master_data(): void
{
    $reg = require APP_ROOT . '/app/registry.php';

    // ---------------------------------------------------------------- settings
    $settings = Settings::DEFAULTS;
    $settings['company_name'] = 'Sample Builders & Development Corp.';
    $settings['company_address'] = '18/F Summit Tower, 2350 Chino Roces Ave., Makati City, Metro Manila';
    $settings['company_tin'] = '009-876-543-000';
    $settings['company_phone'] = '(02) 8812-4400';
    $settings['company_email'] = 'accounting@samplebuilders.test';
    $settings['allow_negative_stock'] = '0';
    $settings['finance_charge_rate'] = '0';
    $settings['pos_receipt_footer'] = "Thank you!\nThis serves as your official receipt.";
    foreach ($settings as $k => $v) {
        DB::insert('settings', ['setting_key' => $k, 'setting_value' => $v]);
    }

    // ---------------------------------------------------------------- modules & pages
    $moduleIds = [];
    foreach ($reg['modules'] as $code => [$name, $icon, $sort]) {
        $moduleIds[$code] = DB::insert('modules', ['code' => $code, 'name' => $name, 'icon' => $icon, 'sort_order' => $sort]);
    }
    $pageIds = [];
    $sort = 0;
    foreach ($reg['pages'] as $key => [$module, $name, $type, $group, $file, $params, $menu]) {
        $pageIds[$key] = DB::insert('pages', [
            'page_key' => $key, 'module_id' => $moduleIds[$module], 'name' => $name, 'page_type' => $type,
            'menu_group' => $group, 'sort_order' => ++$sort * 10, 'show_in_menu' => $menu,
        ]);
    }

    // ---------------------------------------------------------------- chart of accounts
    $typeInfo = [
        'Bank' => ['Asset', 'Debit'], 'Accounts Receivable' => ['Asset', 'Debit'], 'Other Current Asset' => ['Asset', 'Debit'],
        'Fixed Asset' => ['Asset', 'Debit'], 'Other Asset' => ['Asset', 'Debit'], 'Accounts Payable' => ['Liability', 'Credit'],
        'Credit Card' => ['Liability', 'Credit'], 'Other Current Liability' => ['Liability', 'Credit'], 'Long Term Liability' => ['Liability', 'Credit'],
        'Equity' => ['Equity', 'Credit'], 'Income' => ['Income', 'Credit'], 'Other Income' => ['Income', 'Credit'],
        'Cost of Goods Sold' => ['Expense', 'Debit'], 'Expense' => ['Expense', 'Debit'], 'Other Expense' => ['Expense', 'Debit'],
    ];
    // code, name, type, normal balance override, parent code, construction cost category
    $coa = [
        ['1000', 'Cash', 'Bank', null, null, null],
        ['1001', 'Cash on Hand - Yard Counter', 'Bank', null, '1000', null],
        ['1005', 'Petty Cash Fund - Head Office', 'Bank', null, '1000', null],
        ['1006', 'Site Revolving Fund', 'Bank', null, '1000', null],
        ['1010', 'Cash in Bank - BDO Current', 'Bank', null, '1000', null],
        ['1020', 'Cash in Bank - BPI Savings', 'Bank', null, '1000', null],
        ['1030', 'Cash in Bank - Landbank (Government Collections)', 'Bank', null, '1000', null],
        ['1050', 'Undeposited Funds', 'Other Current Asset', null, null, null],
        ['1060', 'Card Clearing', 'Other Current Asset', null, null, null],
        ['1065', 'E-wallet Clearing', 'Other Current Asset', null, null, null],
        ['1070', 'P.O. Card Clearing', 'Other Current Asset', null, null, null],
        ['1100', 'Accounts Receivable - Contracts', 'Accounts Receivable', null, null, null],
        ['1110', 'Retention Receivable', 'Other Current Asset', null, null, null],
        ['1120', 'Contract Assets - Revenue Earned Not Billed', 'Other Current Asset', null, null, null],
        ['1150', 'Allowance for Doubtful Accounts', 'Other Current Asset', 'Credit', null, null],
        ['1170', 'Advances to Employees', 'Other Current Asset', null, null, null],
        ['1200', 'Construction Materials Inventory', 'Other Current Asset', null, null, null],
        ['1300', 'Input Tax', 'Other Current Asset', null, null, null],
        ['1310', 'Creditable Withholding Tax', 'Other Current Asset', null, null, null],
        ['1315', 'Creditable VAT Withheld', 'Other Current Asset', null, null, null],
        ['1400', 'Prepaid Expenses', 'Other Current Asset', null, null, null],
        ['1405', 'Prepaid Insurance and Bond Premiums', 'Other Current Asset', null, null, null],
        ['1410', 'Advances to Suppliers and Subcontractors', 'Other Current Asset', null, null, null],
        ['1500', 'Land', 'Fixed Asset', null, null, null],
        ['1510', 'Office Building', 'Fixed Asset', null, null, null],
        ['1515', 'Accumulated Depreciation - Building', 'Fixed Asset', 'Credit', null, null],
        ['1520', 'Construction Equipment', 'Fixed Asset', null, null, null],
        ['1525', 'Accumulated Depreciation - Construction Equipment', 'Fixed Asset', 'Credit', null, null],
        ['1530', 'Transportation Equipment', 'Fixed Asset', null, null, null],
        ['1535', 'Accumulated Depreciation - Transportation', 'Fixed Asset', 'Credit', null, null],
        ['1540', 'Furniture and Fixtures', 'Fixed Asset', null, null, null],
        ['1545', 'Accumulated Depreciation - Furniture', 'Fixed Asset', 'Credit', null, null],
        ['1550', 'Computer Equipment', 'Fixed Asset', null, null, null],
        ['1555', 'Accumulated Depreciation - Computer', 'Fixed Asset', 'Credit', null, null],
        ['1560', 'Tools and Scaffolding', 'Fixed Asset', null, null, null],
        ['1565', 'Accumulated Depreciation - Tools and Scaffolding', 'Fixed Asset', 'Credit', null, null],
        ['1600', 'Security and Rental Deposits', 'Other Asset', null, null, null],
        ['1610', 'Bid and Performance Security Deposits', 'Other Asset', null, null, null],
        ['2000', 'Accounts Payable', 'Accounts Payable', null, null, null],
        ['2050', 'Company Credit Card', 'Credit Card', null, null, null],
        ['2100', 'Output Tax', 'Other Current Liability', null, null, null],
        ['2110', 'Withholding Tax Payable - Expanded', 'Other Current Liability', null, null, null],
        ['2112', 'Withholding Tax Payable - Compensation', 'Other Current Liability', null, null, null],
        ['2115', 'VAT Withheld Payable', 'Other Current Liability', null, null, null],
        ['2120', 'Gift Vouchers Outstanding', 'Other Current Liability', null, null, null],
        ['2130', 'Customer Deposits', 'Other Current Liability', null, null, null],
        ['2150', 'SSS, PhilHealth and Pag-IBIG Payable', 'Other Current Liability', null, null, null],
        ['2160', 'Accrued Payroll', 'Other Current Liability', null, null, null],
        ['2165', 'Salaries and Wages Payable', 'Other Current Liability', null, null, null],
        ['2200', 'Accrued Expenses', 'Other Current Liability', null, null, null],
        ['2400', 'Progress Billings on Contracts', 'Other Current Liability', null, null, null],
        ['2420', 'Advances from Clients (Mobilization)', 'Other Current Liability', null, null, null],
        ['2430', 'Retention Payable - Subcontractors', 'Other Current Liability', null, null, null],
        ['2440', 'Provision for Losses on Contracts', 'Other Current Liability', null, null, null],
        ['2500', 'Loans Payable - Equipment Financing', 'Long Term Liability', null, null, null],
        ['3000', 'Capital Stock', 'Equity', null, null, null],
        ['3100', 'Dividends Declared', 'Equity', 'Debit', null, null],
        ['3200', 'Retained Earnings', 'Equity', null, null, null],
        ['3900', 'Opening Balance Equity', 'Equity', null, null, null],
        ['4000', 'Contract Revenue', 'Income', null, null, null],
        ['4010', 'Service Income', 'Income', null, null, null],
        ['4020', 'Sales - Construction Materials', 'Income', null, null, null],
        ['4030', 'Equipment Rental Income', 'Income', null, null, null],
        ['4050', 'Sales Returns and Allowances', 'Income', 'Debit', null, null],
        ['4060', 'Sales Discounts', 'Income', 'Debit', null, null],
        ['4070', 'Item Group Price Variance', 'Income', null, null, null],
        ['4110', 'Reimbursable Expense Income', 'Income', null, null, null],
        ['4100', 'Finance Charge Income', 'Other Income', null, null, null],
        ['4200', 'Interest Income', 'Other Income', null, null, null],
        ['4300', 'Gain (Loss) on Disposal of Assets', 'Other Income', null, null, null],
        ['4400', 'Other Income', 'Other Income', null, null, null],
        ['5000', 'Cost of Materials Sold', 'Cost of Goods Sold', null, null, null],
        ['5050', 'Purchase Discounts', 'Cost of Goods Sold', 'Credit', null, null],
        ['5100', 'Inventory Adjustments and Shrinkage', 'Cost of Goods Sold', null, null, null],
        ['5200', 'Direct Labor Absorbed', 'Cost of Goods Sold', 'Credit', null, null],
        ['5300', 'Cost of Construction', 'Cost of Goods Sold', null, null, null],
        ['5310', 'Direct Materials', 'Cost of Goods Sold', null, '5300', 'material'],
        ['5320', 'Direct Labor', 'Cost of Goods Sold', null, '5300', 'labor'],
        ['5325', 'Labor Burden and Benefits', 'Cost of Goods Sold', null, '5300', 'labor'],
        ['5330', 'Equipment Costs Charged', 'Cost of Goods Sold', null, '5300', 'equipment'],
        ['5335', 'Equipment Rental - Projects', 'Cost of Goods Sold', null, '5300', 'equipment'],
        ['5340', 'Subcontract Costs', 'Cost of Goods Sold', null, '5300', 'subcontract'],
        ['5350', 'Hauling and Delivery - Projects', 'Cost of Goods Sold', null, '5300', 'other'],
        ['5355', 'Site Preparation and Temporary Facilities', 'Cost of Goods Sold', null, '5300', 'other'],
        ['5360', 'Permits, Licenses and Fees - Projects', 'Cost of Goods Sold', null, '5300', 'other'],
        ['5365', 'Site Utilities', 'Cost of Goods Sold', null, '5300', 'other'],
        ['5370', 'Site Supervision and Indirect Labor', 'Cost of Goods Sold', null, '5300', 'labor'],
        ['5375', 'Insurance and Bonds - Projects', 'Cost of Goods Sold', null, '5300', 'other'],
        ['5380', 'Testing and Inspection', 'Cost of Goods Sold', null, '5300', 'other'],
        ['5385', 'Rework and Warranty Costs', 'Cost of Goods Sold', null, '5300', 'other'],
        ['5390', 'Other Direct Project Costs', 'Cost of Goods Sold', null, '5300', 'other'],
        ['5395', 'Provision for Contract Losses', 'Cost of Goods Sold', null, '5300', 'other'],
        ['6000', 'Salaries and Wages - Office', 'Expense', null, null, 'overhead'],
        ['6005', 'Employee Benefits and Contributions', 'Expense', null, null, 'overhead'],
        ['6010', 'Rent - Office and Warehouse', 'Expense', null, null, 'overhead'],
        ['6020', 'Utilities Expense', 'Expense', null, null, 'overhead'],
        ['6030', 'Repairs and Maintenance', 'Expense', null, null, 'overhead'],
        ['6040', 'Office Supplies Expense', 'Expense', null, null, 'overhead'],
        ['6050', 'Fuel and Oil - Service Vehicles', 'Expense', null, null, 'overhead'],
        ['6060', 'Meals and Representation', 'Expense', null, null, 'overhead'],
        ['6070', 'Professional Fees', 'Expense', null, null, 'overhead'],
        ['6080', 'Bank and Merchant Charges', 'Expense', null, null, 'overhead'],
        ['6090', 'Depreciation Expense', 'Expense', null, null, 'overhead'],
        ['6100', 'Freight and Delivery', 'Expense', null, null, 'overhead'],
        ['6110', 'Purchases - Non-inventory', 'Expense', null, null, 'overhead'],
        ['6120', 'Insurance Expense', 'Expense', null, null, 'overhead'],
        ['6130', 'Taxes and Licenses', 'Expense', null, null, 'overhead'],
        ['6140', 'Equipment Fuel and Lubricants', 'Expense', null, null, 'overhead'],
        ['6145', 'Equipment Repairs and Parts', 'Expense', null, null, 'overhead'],
        ['6155', 'Equipment Cost Recovery (charged to projects)', 'Expense', 'Credit', null, 'overhead'],
        ['6160', 'Travel and Transportation', 'Expense', null, null, 'overhead'],
        ['6170', 'Communication Expense', 'Expense', null, null, 'overhead'],
        ['6180', 'Shared Warehouse and Yard Costs', 'Expense', null, null, 'overhead'],
        ['6200', 'Cash Over and Short', 'Expense', null, null, null],
        ['6900', 'Miscellaneous Expense', 'Expense', null, null, 'overhead'],
        ['7000', 'Interest Expense', 'Other Expense', null, null, null],
    ];
    $acct = [];
    foreach ($coa as [$code, $name, $type, $nb, $parent, $costCat]) {
        [$cat, $normal] = $typeInfo[$type];
        $acct[$code] = DB::insert('accounts', [
            'code' => $code, 'name' => $name, 'account_type' => $type, 'category' => $cat,
            'normal_balance' => $nb ?? $normal, 'parent_id' => $parent ? $acct[$parent] : null,
            'is_system' => in_array($code, ['1050', '1100', '1110', '1120', '1200', '2000', '2100', '1300', '2400', '2420', '2430', '2440', '3200', '3900', '4000'], true) ? 1 : 0,
            'bank_account_no' => ['1010' => '0012-3456-7890', '1020' => '1234-5678-90', '1030' => '1832-0045-12'][$code] ?? null,
            'cs_cost_category' => $costCat,
        ]);
    }

    $defaults = [
        'ar' => '1100', 'undeposited_funds' => '1050', 'sales_income' => '4020', 'sales_returns' => '4050',
        'sales_discounts' => '4060', 'finance_charge_income' => '4100', 'reimbursable_income' => '4110', 'cwt' => '1310',
        'output_tax' => '2100', 'ap' => '2000', 'input_tax' => '1300', 'purchase_discounts' => '5050',
        'default_expense' => '6110', 'inventory_asset' => '1200', 'cogs' => '5000', 'inventory_adjustment' => '5100',
        'cash_on_hand' => '1001', 'cash_over_short' => '6200', 'card_clearing' => '1060', 'ewallet_clearing' => '1065',
        'merchant_fees' => '6080', 'interest_income' => '4200', 'retained_earnings' => '3200', 'opening_equity' => '3900',
        'gain_loss_disposal' => '4300', 'processing_variance' => '5100', 'processing_absorbed' => '5200',
        'cvat_withheld' => '1315', 'wht_payable' => '2110', 'vat_withheld_payable' => '2115', 'vendor_advances' => '1410',
        'group_price_variance' => '4070',
        'cs_contract_revenue' => '4000', 'cs_progress_billings' => '2400', 'cs_contract_asset' => '1120', 'cs_retention_receivable' => '1110',
        'cs_client_advances' => '2420', 'cs_retention_payable' => '2430', 'cs_materials_cost' => '5310', 'cs_labor_cost' => '5320',
        'cs_labor_burden' => '5325', 'cs_indirect_labor' => '5370', 'cs_equipment_cost' => '5330', 'cs_equipment_recovery' => '6155',
        'cs_subcontract_cost' => '5340', 'cs_other_direct' => '5390', 'cs_loss_provision' => '5395', 'cs_loss_liability' => '2440', 'cs_accrued_payroll' => '2160',
        'cs_wages_payable' => '2165', 'cs_statutory_payable' => '2150', 'cs_employee_advances' => '1170',
    ];
    foreach (Accts::KEYS as $key => [$label, $module]) {
        DB::insert('default_accounts', ['setting_key' => $key, 'label' => $label, 'module' => $module, 'account_id' => $acct[$defaults[$key]]]);
    }
    Accts::reset();

    // ---------------------------------------------------------------- lists
    $terms = [];
    foreach ([['Due on receipt', 0, 0, 0], ['Net 15', 15, 0, 0], ['Net 30', 30, 0, 0], ['2% 10 Net 30', 30, 2, 10], ['Net 45', 45, 0, 0], ['Net 60', 60, 0, 0]] as [$n, $d, $p, $dd]) {
        $terms[$n] = DB::insert('terms', ['name' => $n, 'net_days' => $d, 'discount_pct' => $p, 'discount_days' => $dd]);
    }
    $tax = [];
    $tax['VAT12'] = DB::insert('tax_codes', ['code' => 'VAT12', 'name' => 'VAT 12%', 'rate' => 12, 'sales_account_id' => $acct['2100'], 'purchase_account_id' => $acct['1300']]);
    $tax['ZR'] = DB::insert('tax_codes', ['code' => 'ZR', 'name' => 'Zero-rated', 'rate' => 0, 'sales_account_id' => $acct['2100'], 'purchase_account_id' => $acct['1300']]);
    $tax['EX'] = DB::insert('tax_codes', ['code' => 'EX', 'name' => 'VAT-exempt', 'rate' => 0, 'sales_account_id' => $acct['2100'], 'purchase_account_id' => $acct['1300']]);
    $tax['NT'] = DB::insert('tax_codes', ['code' => 'NT', 'name' => 'Non-taxable', 'rate' => 0, 'sales_account_id' => $acct['2100'], 'purchase_account_id' => $acct['1300']]);

    // the Withholding Tax Table (BIR Reports). Rates are a list the company keeps: none of them is assumed by the program.
    foreach ([
        ['WT-GOODS', 'WC158', 'WT on Goods', 'income', 1, 'Goods bought from regular suppliers by top withholding agents (RR 11-2018)', 10],
        ['WT-SERVICES', 'WC160', 'WT on Services', 'income', 2, 'Services bought from regular suppliers by top withholding agents (RR 11-2018)', 20],
        ['WT-CONTRACTOR', 'WC120', 'WT on Contractors', 'income', 2, 'Payments to general engineering, building and specialty contractors', 25],
        ['WT-VAT', 'WV010', 'WT on VAT (Government)', 'vat', 5, 'Creditable VAT withheld by government agencies on VATable purchases (5% of the VAT-exclusive payment)', 30],
        ['WT-RENT', 'WC100', 'WT on Rentals', 'income', 5, 'Rental of real and personal property, including construction equipment', 40],
    ] as [$code, $atc, $name, $kind, $rate, $desc, $sortNo]) {
        DB::insert('withholding_taxes', ['code' => $code, 'atc' => $atc, 'name' => $name, 'tax_kind' => $kind, 'rate' => $rate,
            'base_type' => 'net_of_vat', 'applies_to' => 'both', 'description' => $desc, 'sort_order' => $sortNo]);
    }
    Withholding::reset();

    foreach ([
        ['Cash', 'cash', null, 1], ['Check', 'check', null, 0], ['Credit/Debit Card', 'card', '1060', 1], ['GCash', 'ewallet', '1065', 1],
        ['Maya', 'ewallet', '1065', 1], ['Bank Transfer', 'bank_transfer', null, 0], ['Gift Voucher', 'voucher', '2120', 0],
        ['P.O. Card', 'card', '1070', 0], ['Bank', 'bank_transfer', '1010', 1],
    ] as [$n, $t, $a, $pos]) {
        DB::insert('payment_methods', ['name' => $n, 'method_type' => $t, 'clearing_account_id' => $a ? $acct[$a] : null, 'show_in_pos' => $pos]);
    }
    foreach ([['Contractor Price', -5], ['Developer Price', -8], ['Walk-in Plus', 5], ['Price Level 4', 0], ['Price Level 5', 0], ['Price Level 6', 0], ['Price Level 7', 0], ['Price Level 8', 0], ['Price Level 9', 0], ['Price Level 10', 0]] as [$n, $p]) {
        DB::insert('price_levels', ['name' => $n, 'adjust_pct' => $p]);
    }
    foreach (['Government', 'Private Developer', 'Corporate', 'Institutional', 'Individual'] as $n) {
        DB::insert('customer_types', ['name' => $n]);
    }
    DB::insert('billing_groups', ['name' => 'Equipment Rental Clients']);

    // ---------------------------------------------------------------- units of measure (construction)
    foreach ([
        ['Piece', 'count', 'pc', [['pc', 'Piece', 1, 'S'], ['bdl', 'Bundle of 10', 10, ''], ['box', 'Box of 100', 100, 'P']]],
        ['Bag (cement)', 'count', 'bag', [['bag', 'Bag (40 kg)', 1, 'SP'], ['pallet', 'Pallet of 40 bags', 40, '']]],
        ['Cubic meter', 'volume', 'cu.m', [['cu.m', 'Cubic meter', 1, 'SP'], ['truck', 'Truckload of 8 cu.m', 8, '']]],
        ['Length (m)', 'length', 'm', [['m', 'Meter', 1, 'SP'], ['lm', 'Linear meter', 1, ''], ['roll', 'Roll of 150 m', 150, '']]],
        ['Steel bar (6 m)', 'count', 'len', [['len', 'Length (6 m)', 1, 'SP'], ['bdl10', 'Bundle of 10 lengths', 10, '']]],
        ['Weight (kg)', 'weight', 'kg', [['kg', 'Kilogram', 1, 'SP'], ['ton', 'Metric ton', 1000, '']]],
        ['Volume (L)', 'volume', 'L', [['L', 'Liter', 1, 'SP'], ['gal', 'Gallon (4 L)', 4, ''], ['drum', 'Drum of 200 L', 200, '']]],
        ['Sheet', 'count', 'sht', [['sht', 'Sheet', 1, 'SP']]],
        ['Time (hour)', 'time', 'hr', [['hr', 'Hour', 1, 'SP'], ['day', 'Day (8 hours)', 8, '']]],
    ] as [$sName, $kind, $baseUnit, $units]) {
        $sid = DB::insert('uom_sets', ['name' => $sName, 'uom_kind' => $kind, 'base_unit' => $baseUnit, 'is_active' => 1]);
        $sortU = 0;
        foreach ($units as [$code, $uName, $factor, $flags]) {
            DB::insert('uom_units', ['set_id' => $sid, 'code' => $code, 'name' => $uName, 'factor' => $factor,
                'is_base' => $factor == 1 && $sortU === 0 ? 1 : 0, 'is_sales_default' => str_contains($flags, 'S') ? 1 : 0,
                'is_purchase_default' => str_contains($flags, 'P') ? 1 : 0, 'sort_order' => $sortU++]);
        }
    }
    foreach (['Cement and Aggregates', 'Steel and Rebar', 'Lumber and Formworks', 'Masonry', 'Electrical', 'Plumbing', 'Paints and Finishes', 'Roofing', 'Fuel and Lubricants', 'Safety and PPE', 'Services', 'Charges'] as $n) {
        DB::insert('item_categories', ['name' => $n]);
    }

    // ---------------------------------------------------------------- the main warehouse and the yard counter
    $wh = DB::insert('sites', ['code' => 'WH', 'name' => 'Main Warehouse (Taguig Yard)', 'site_type' => 'warehouse', 'address' => 'Lot 4, C-5 Road, Taguig City']);
    $yard = DB::insert('pos_terminals', ['code' => 'POS-01', 'name' => 'Yard Sales Counter', 'site_id' => $wh, 'cash_account_id' => $acct['1001'], 'receipt_prefix' => 'YD-']);

    // ---------------------------------------------------------------- roles
    $roleIds = seed_roles($reg, $pageIds);

    // ---------------------------------------------------------------- users: one per role
    $n = 0;
    foreach (seed_user_list() as [$u, $full, $role, $pw, $position]) {
        $uid = DB::insert('users', [
            'username' => $u, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'full_name' => $full,
            'email' => $u . '@samplebuilders.test', 'employee_no' => sprintf('EMP-%03d', ++$n), 'status' => 'active',
            'must_change_password' => 0, 'password_changed_at' => date('Y-m-d H:i:s'),
            'all_projects' => in_array($role, ['Company Administrator', 'Business Owner / President', 'General Manager', 'Operations Manager', 'Project Director',
                'Chief Accountant / Controller', 'Finance Manager', 'Internal Auditor', 'Purchasing Manager', 'Procurement Officer', 'Warehouse Officer',
                'Human Resources Officer', 'Equipment Manager', 'Accounting Staff', 'Billing Officer', 'Collection Officer / Cashier', 'Quantity Surveyor', 'Timekeeper', 'Safety Officer', 'Quality Inspector'], true) ? 1 : 0,
        ]);
        DB::insert('user_roles', ['user_id' => $uid, 'role_id' => $roleIds[$role]]);
        if ($role === 'Collection Officer / Cashier') {
            DB::insert('user_terminals', ['user_id' => $uid, 'terminal_id' => $yard]);
        }
    }

    // ---------------------------------------------------------------- fixed asset categories
    foreach ([
        ['Office Building', '1510', '1515', 'straight_line', 25],
        ['Heavy Construction Equipment', '1520', '1525', 'straight_line', 8],
        ['Light Construction Equipment', '1520', '1525', 'straight_line', 5],
        ['Construction Equipment (usage-based)', '1520', '1525', 'units_of_production', 8],
        ['Transportation Equipment', '1530', '1535', 'declining_balance', 5],
        ['Furniture and Fixtures', '1540', '1545', 'straight_line', 10],
        ['Computer Equipment', '1550', '1555', 'declining_balance', 3],
        ['Tools and Scaffolding', '1560', '1565', 'straight_line', 3],
    ] as [$nm, $a, $ad, $m, $life]) {
        DB::insert('asset_categories', ['name' => $nm, 'asset_account_id' => $acct[$a], 'accum_dep_account_id' => $acct[$ad],
            'dep_expense_account_id' => $acct['6090'], 'default_method' => $m, 'default_life_years' => $life]);
    }

    // ---------------------------------------------------------------- construction set-up lists
    seed_construction_setup($acct, $roleIds);

    // ---------------------------------------------------------------- sequences
    foreach ([
        ['estimate', 'EST-', 1, 5], ['sales_order', 'SO-', 1, 5], ['invoice', 'INV-', 1, 5], ['sales_receipt', 'SR-', 1, 5],
        ['statement_charge', 'SC-', 1, 5], ['finance_charge', 'FC-', 1, 5], ['payment', 'OR-', 1, 6], ['credit_memo', 'CM-', 1, 5],
        ['purchase_order', 'PO-', 1, 5], ['bill', 'BILL-', 1, 5], ['item_receipt', 'RR-', 1, 5], ['check', '', 1001, 4],
        ['deposit', 'DEP-', 1, 5], ['transfer', 'TRF-', 1, 5], ['journal', 'JE-', 1, 5], ['adjustment', 'ADJ-', 1, 5],
        ['inv_transfer', 'IT-', 1, 5], ['build', 'BLD-', 1, 5], ['asset', 'FA-', 1, 5], ['pos', 'POS-', 1, 6],
        ['processing', 'PRC-', 1, 5], ['cash_voucher', 'CAV-', 1, 5],
        ['maintenance', 'MNT-', 1, 5], ['debit_memo', 'DM-', 1, 5], ['vendor_advance', 'ADV-', 1, 5], ['advance_apply', 'AAP-', 1, 5],
        // construction documents
        ['cs_project', 'PRJ-', 1, 4], ['cs_contract', 'CON-', 1, 4], ['cs_opportunity', 'OPP-', 1, 4], ['cs_estimate', 'CE-', 1, 4],
        ['cs_change_order', 'VO-', 1, 4], ['cs_mr', 'MR-', 1, 5], ['cs_canvass', 'CNV-', 1, 4], ['cs_matdoc', 'MIS-', 1, 5],
        ['cs_timesheet', 'TS-', 1, 5], ['cs_payroll', 'PAY-', 1, 4], ['cs_advance', 'CA-', 1, 4], ['cs_dispatch', 'DSP-', 1, 4],
        ['cs_equip_log', 'EQL-', 1, 5], ['cs_daily', 'DSR-', 1, 5], ['cs_subcontract', 'SUB-', 1, 4], ['cs_sub_billing', 'SPB-', 1, 4],
        ['cs_billing', 'PB-', 1, 4], ['cs_revenue', 'RR-', 1, 4], ['cs_allocation', 'ALC-', 1, 4], ['cs_inspection', 'INSP-', 1, 4],
        ['cs_ncr', 'NCR-', 1, 4], ['cs_safety', 'SAF-', 1, 4], ['cs_rfi', 'RFI-', 1, 4], ['cs_eot', 'EOT-', 1, 3], ['cs_punch', 'PL-', 1, 4],
        ['cs_log', 'LOG-', 1, 4], ['cs_doc', 'DOC-', 1, 4], ['cs_worker', 'W-', 1, 4], ['cs_equipment', 'EQ-', 1, 3],
    ] as [$k, $pfx, $next, $pad]) {
        DB::insert('sequences', ['seq_key' => $k, 'prefix' => $pfx, 'next_no' => $next, 'pad' => $pad]);
    }
}

/**
 * The 26 standard roles of a construction company and what each may open and do.
 * Letters: C create, E edit, D delete/void, P open and print/export, A approve.
 */
function seed_roles(array $reg, array $pageIds): array
{
    $pageDefs = [];
    foreach ($reg['pages'] as $k => $d) {
        $pageDefs[$k] = ['module' => $d[0], 'type' => $d[2], 'group' => $d[3]];
    }
    $adminSetup = ['admin.users', 'admin.roles', 'admin.page_access', 'admin.effective_access', 'admin.settings', 'admin.cs_settings',
        'admin.utilities', 'admin.import', 'admin.backup', 'admin.multi_company', 'company.profile', 'company.users', 'company.join_requests'];
    $select = function (string $pattern) use ($pageDefs): array {
        // "module:*", "module:report", "module:!report", "group:Sales Reports", exact key
        if (isset($pageDefs[$pattern])) {
            return [$pattern];
        }
        [$a, $b] = explode(':', $pattern, 2) + [1 => '*'];
        $out = [];
        foreach ($pageDefs as $k => $d) {
            if ($a === 'group') {
                if ($d['group'] === $b) {
                    $out[] = $k;
                }
                continue;
            }
            if ($d['module'] !== $a) {
                continue;
            }
            if ($b === '*' || ($b === 'report' && $d['type'] === 'report') || ($b === '!report' && $d['type'] !== 'report')) {
                $out[] = $k;
            }
        }
        return $out;
    };
    $dash = ['home.dashboard' => 'P', 'home.alerts' => 'P', 'home.reports' => 'P', 'home.memorized' => 'P', 'home.search' => 'P', 'home.find' => 'P', 'appr.inbox' => 'P', 'appr.history' => 'P'];
    $projView = ['proj.center' => 'P', 'proj.view' => 'P'];
    $acctReports = ['coa:report' => 'P', 'cust:report' => 'P', 'vend:report' => 'P', 'inv:report' => 'P', 'fa:report' => 'P', 'bank:report' => 'P', 'bir:report' => 'P'];
    $fieldDocs = ['proj.documents' => 'P', 'proj.rfis' => 'P', 'proj.logs' => 'P', 'plan.schedule' => 'P', 'plan.milestones' => 'P'];

    $roleDefs = [
        'Company Administrator' => [
            'desc' => 'Everything in every module, including user accounts, roles, page access, construction and company settings, and the closing date.',
            'admin' => 1, 'pages' => [], 'special' => [],
        ],
        'Business Owner / President' => [
            'desc' => 'Sees every project, report and transaction and gives the final executive approvals. (The Company Owner account has full authority in any case.)',
            'pages' => ['*' => 'PA'] + $dash + ['home.exec_dashboard' => 'P', 'home.fin_dashboard' => 'P', 'home.pm_dashboard' => 'P'],
            'deny' => $adminSetup,
            'special' => ['view_costs' => '1', 'view_bank_balances' => '1', 'max_amount' => '', 'design_reports' => '1'],
        ],
        'General Manager' => [
            'desc' => 'Runs the company day to day: every project, contract and report, and the authorized-approver step of contracts, billings, change orders and large purchases.',
            'pages' => ['*' => 'PA', 'proj:!report' => 'CEPA', 'plan:!report' => 'CEPA', 'appr.routes' => 'P'] + $dash + ['home.exec_dashboard' => 'P', 'home.fin_dashboard' => 'P', 'home.pm_dashboard' => 'P'],
            'deny' => $adminSetup,
            'special' => ['view_costs' => '1', 'view_bank_balances' => '1', 'max_amount' => '', 'design_reports' => '1'],
        ],
        'Operations Manager' => [
            'desc' => 'All field operations across projects: site reports, workforce, equipment, procurement and subcontracts, with approval rights on them.',
            'pages' => $dash + $projView + ['home.exec_dashboard' => 'P', 'home.pm_dashboard' => 'P',
                'proj:!report' => 'CEPA', 'plan:!report' => 'CEPA', 'site:*' => 'CEPA', 'labor:*' => 'CEPA', 'equip:*' => 'CEPA',
                'proc:*' => 'CEPA', 'subk:*' => 'CEPA', 'pbill.billings' => 'P', 'pbill.collections' => 'P', 'pbill.retention' => 'P', 'cost:*' => 'P',
                'creports:*' => 'P', 'fa.maintenance' => 'P', 'fa.pm' => 'P', 'vend.purchase_orders' => 'P', 'inv.center' => 'P'],
            'special' => ['view_costs' => '1', 'max_amount' => '1000000'],
        ],
        'Project Director' => [
            'desc' => 'Directs a portfolio of projects: contracts, budgets, schedules and change orders, and the director-level approvals.',
            'pages' => $dash + $projView + ['home.pm_dashboard' => 'P', 'home.exec_dashboard' => 'P',
                'proj:!report' => 'CEDPA', 'plan:!report' => 'CEDPA', 'site:*' => 'PA', 'labor:*' => 'PA', 'equip:*' => 'PA', 'proc:*' => 'PA',
                'subk:*' => 'CEPA', 'pbill:*' => 'PA', 'cost:*' => 'P', 'creports:*' => 'P'],
            'special' => ['view_costs' => '1', 'max_amount' => '2000000'],
        ],
        'Project Manager' => [
            'desc' => 'Runs the projects assigned to them: budgets, schedule, materials, workforce, equipment, subcontracts and progress billings, and approves their project\'s documents.',
            'pages' => $dash + $projView + ['home.pm_dashboard' => 'P',
                'proj.contracts' => 'P', 'proj.clients' => 'P', 'proj.change_orders' => 'CEPA', 'proj.documents' => 'CEPA', 'proj.rfis' => 'CEPA', 'proj.logs' => 'CEP', 'proj.closeout' => 'CEPA', 'proj.projects' => 'EP',
                'plan:!report' => 'CEPA', 'plan.cost_codes' => 'P', 'site:*' => 'CEPA', 'labor.workers' => 'P', 'labor.timesheets' => 'CEPA', 'labor.advances' => 'CEPA',
                'equip.register' => 'P', 'equip.dispatch' => 'CEPA', 'equip.logs' => 'CEPA', 'proc.requests' => 'CEPA', 'proc.canvass' => 'P', 'proc.receiving' => 'CEP',
                'proc.materials' => 'CEPA', 'proc.stock' => 'P', 'subk.register' => 'P', 'subk.contracts' => 'CEP', 'subk.billings' => 'CEPA',
                'pbill.billings' => 'CEPA', 'pbill.collections' => 'P', 'pbill.retention' => 'P', 'cost.assign' => 'P', 'creports:*' => 'P', 'vend.purchase_orders' => 'P'],
            'special' => ['view_costs' => '1', 'max_amount' => '500000'],
        ],
        'Site Engineer' => [
            'desc' => 'Daily site reports and accomplishments, inspections, RFIs, material requests and issues, timesheets and equipment logs of their projects; prepares the progress billing.',
            'pages' => $dash + $projView + $fieldDocs + ['home.pm_dashboard' => 'P',
                'site.daily' => 'CEP', 'site.inspections' => 'CEP', 'site.ncr' => 'CEP', 'site.safety' => 'CEP', 'proj.documents' => 'CEP', 'proj.rfis' => 'CEP', 'proj.logs' => 'CEP',
                'plan.schedule' => 'EP', 'plan.milestones' => 'EP', 'plan.boq' => 'P', 'proc.requests' => 'CEP', 'proc.materials' => 'CEP', 'proc.stock' => 'P', 'proc.receiving' => 'CEP',
                'labor.timesheets' => 'CEP', 'labor.workers' => 'P', 'equip.logs' => 'CEP', 'equip.dispatch' => 'P', 'pbill.billings' => 'CEPA', 'proj.closeout' => 'EP',
                'rpt.cs_materials' => 'P', 'rpt.cs_labor' => 'P', 'rpt.cs_productivity' => 'P', 'rpt.cs_deliverables' => 'P', 'rpt.cs_evm' => 'P'],
            'special' => ['view_costs' => '0', 'max_amount' => '50000'],
        ],
        'Quantity Surveyor' => [
            'desc' => 'Estimates, BOQ and schedule of values, budgets, change orders, and the measurement of progress and subcontractor billings.',
            'pages' => $dash + $projView + ['plan.estimates' => 'CEDPA', 'plan.boq' => 'CEDP', 'plan.budgets' => 'CEP', 'plan.cost_codes' => 'CEP', 'plan.opportunities' => 'CEP', 'plan.milestones' => 'EP',
                'proj.change_orders' => 'CEPA', 'proj.contracts' => 'P', 'pbill.billings' => 'CEPA', 'pbill.retention' => 'P', 'subk.contracts' => 'CEP', 'subk.billings' => 'CEPA',
                'creports:*' => 'P', 'plan.schedule' => 'P'],
            'special' => ['view_costs' => '1', 'max_amount' => '100000'],
        ],
        'Site Supervisor / Foreman' => [
            'desc' => 'Crew attendance, daily site reports, material requests and equipment logs on site.',
            'pages' => $dash + $projView + ['site.daily' => 'CEP', 'site.safety' => 'CEP', 'labor.timesheets' => 'CEP', 'labor.workers' => 'P', 'equip.logs' => 'CEP',
                'proc.requests' => 'CEP', 'proc.materials' => 'CP', 'plan.schedule' => 'P', 'proj.documents' => 'P'],
            'special' => ['view_costs' => '0', 'max_amount' => '0'],
        ],
        'Human Resources Officer' => [
            'desc' => 'Worker records, trades and rates, cash advances, and the man-hour and labor cost reports.',
            'pages' => $dash + ['labor.workers' => 'CEDP', 'labor.trades' => 'CEDP', 'labor.advances' => 'CEP', 'labor.timesheets' => 'P', 'labor.payroll' => 'P', 'coa.names' => 'CEP',
                'rpt.cs_labor' => 'P', 'rpt.cs_productivity' => 'P', 'site.safety' => 'P'],
            'special' => ['view_costs' => '1', 'max_amount' => '0'],
        ],
        'Timekeeper' => [
            'desc' => 'Records daily time in and time out of the workers.',
            'pages' => ['home.dashboard' => 'P', 'home.alerts' => 'P', 'labor.timesheets' => 'CEP', 'labor.workers' => 'P', 'proj.center' => 'P', 'rpt.cs_labor' => 'P'],
            'special' => ['view_costs' => '0', 'max_amount' => '0'],
        ],
        'Equipment Manager' => [
            'desc' => 'The equipment register, dispatch and booking, usage logs and charges, preventive maintenance and repairs.',
            'pages' => $dash + $projView + ['equip:*' => 'CEDPA', 'fa.maintenance' => 'CEP', 'fa.pm' => 'CEP', 'fa.register' => 'P', 'fa:report' => 'P', 'rpt.cs_equipment' => 'P'],
            'special' => ['view_costs' => '1', 'max_amount' => '100000'],
        ],
        'Warehouse Officer' => [
            'desc' => 'Receiving and inspection of deliveries, stock, issues, returns and transfers of materials to and between projects.',
            'pages' => $dash + ['proj.center' => 'P', 'proc.materials' => 'CEP', 'proc.stock' => 'P', 'proc.receiving' => 'CEP', 'proc.requests' => 'P', 'vend.receive_items' => 'CEP',
                'vend.purchase_orders' => 'P', 'inv.center' => 'P', 'inv.items' => 'CEP', 'inv.categories' => 'CEP', 'inv.sites' => 'CEP', 'inv.transfers' => 'CEP', 'inv.adjust' => 'CEP',
                'inv.physical_count' => 'P', 'inv.uom' => 'P', 'inv:report' => 'P', 'rpt.cs_materials' => 'P'],
            'special' => ['view_costs' => '0', 'max_amount' => '20000'],
        ],
        'Procurement Officer' => [
            'desc' => 'Canvass and supplier quotations, purchase orders, supplier and subcontractor records.',
            'pages' => $dash + ['proj.center' => 'P', 'proc.requests' => 'P', 'proc.canvass' => 'CEP', 'proc.receiving' => 'P', 'proc.stock' => 'P', 'vend.purchase_orders' => 'CEP',
                'vend.center' => 'CEP', 'subk.register' => 'CEP', 'inv.items' => 'CEP', 'inv.center' => 'P', 'rpt.cs_commitments' => 'P', 'rpt.cs_supplier_spend' => 'P', 'vend:report' => 'P'],
            'special' => ['view_costs' => '1', 'max_amount' => '200000'],
        ],
        'Purchasing Manager' => [
            'desc' => 'Approves material requests and purchases, awards canvasses, and manages suppliers and subcontract agreements.',
            'pages' => $dash + $projView + ['proc:*' => 'CEPA', 'vend.purchase_orders' => 'CEDPA', 'vend.center' => 'CEP', 'vend:report' => 'P', 'subk:*' => 'CEPA',
                'inv.center' => 'P', 'inv.items' => 'CEP', 'rpt.cs_commitments' => 'P', 'rpt.cs_supplier_spend' => 'P', 'rpt.cs_materials' => 'P', 'rpt.cs_subcon' => 'P'],
            'special' => ['view_costs' => '1', 'max_amount' => '1000000'],
        ],
        'Accounting Staff' => [
            'desc' => 'Day-to-day bookkeeping: bills, checks, receipts, deposits, journal entries, cost code assignment and payroll postings.',
            'pages' => $dash + ['proj.center' => 'P', 'coa.journal' => 'CEP', 'coa.accounts' => 'P', 'coa.names' => 'CEP', 'coa.classes' => 'P', 'cust:!report' => 'CEP', 'vend:!report' => 'CEP',
                'bank:!report' => 'CEP', 'inv.center' => 'P', 'cost.assign' => 'CEP', 'labor.payroll' => 'CEP', 'labor.advances' => 'CEP', 'pbill.collections' => 'P', 'pbill.retention' => 'P',
                'subk.billings' => 'P', 'bir.form_2307' => 'P'] + $acctReports,
            'special' => ['view_costs' => '1', 'view_bank_balances' => '0', 'max_amount' => '100000'],
        ],
        'Chief Accountant / Controller' => [
            'desc' => 'The books: chart of accounts, period closing, revenue recognition, cost allocation, payroll posting, BIR reports and every financial and project report.',
            'pages' => $dash + $projView + ['home.fin_dashboard' => 'P', 'coa:*' => 'CEDPA', 'cust:*' => 'CEDPA', 'vend:*' => 'CEDPA', 'bank:*' => 'CEDPA', 'fa:*' => 'CEDPA', 'bir:*' => 'CEDPA',
                'inv:report' => 'P', 'inv.center' => 'P', 'inv.items' => 'P', 'pbill:*' => 'CEPA', 'cost:*' => 'CEDPA', 'labor.payroll' => 'CEDPA', 'labor.advances' => 'PA',
                'subk.billings' => 'PA', 'creports:*' => 'P', 'proj.contracts' => 'P', 'plan.budgets' => 'P', 'admin.audit_trail' => 'P', 'admin.txn_history' => 'P', 'admin.utilities' => 'P'],
            'special' => ['view_costs' => '1', 'view_bank_balances' => '1', 'coa_changes' => '1', 'closed_period_changes' => '1', 'max_amount' => '', 'design_reports' => '1'],
        ],
        'Finance Manager' => [
            'desc' => 'Cash, collections, payments and project finances: approves billings, payments, budgets and allocations, and watches receivables, payables and cash flow.',
            'pages' => $dash + $projView + ['home.fin_dashboard' => 'P', 'home.exec_dashboard' => 'P', 'coa:*' => 'PA', 'cust:*' => 'CEPA', 'vend:*' => 'CEPA', 'bank:*' => 'CEDPA',
                'bir:*' => 'P', 'fa:report' => 'P', 'inv:report' => 'P', 'pbill:*' => 'CEPA', 'cost:*' => 'PA', 'labor.payroll' => 'PA', 'labor.advances' => 'PA', 'subk:*' => 'PA',
                'plan.budgets' => 'PA', 'proj.contracts' => 'P', 'proj.change_orders' => 'PA', 'proc.requests' => 'PA', 'proc.canvass' => 'PA', 'creports:*' => 'P', 'proj.closeout' => 'PA'],
            'special' => ['view_costs' => '1', 'view_bank_balances' => '1', 'max_amount' => '5000000', 'design_reports' => '1'],
        ],
        'Billing Officer' => [
            'desc' => 'Progress billing applications, invoices and statements of account, retention and billing reports.',
            'pages' => $dash + $projView + ['pbill.billings' => 'CEP', 'pbill.retention' => 'P', 'pbill.collections' => 'P', 'cust.invoices' => 'CEP', 'cust.statements' => 'CP', 'cust.center' => 'P',
                'cust.credit_memos' => 'CEP', 'cust:report' => 'P', 'rpt.cs_billing' => 'P', 'rpt.cs_collections' => 'P', 'rpt.cs_ar_aging' => 'P', 'rpt.cs_retention' => 'P',
                'rpt.cs_earned_billed' => 'P', 'plan.boq' => 'P', 'plan.milestones' => 'P'],
            'special' => ['view_costs' => '0', 'max_amount' => '0'],
        ],
        'Collection Officer / Cashier' => [
            'desc' => 'Receives client payments and issues official receipts, makes deposits, and works the yard sales counter.',
            'pages' => $dash + ['cust.payments' => 'CEP', 'cust.center' => 'P', 'bank.deposits' => 'CEP', 'pbill.collections' => 'P', 'pos.register' => 'CP', 'pos.shifts' => 'CP', 'pos.receipts' => 'CP',
                'rpt.cs_collections' => 'P', 'rpt.cs_ar_aging' => 'P', 'rpt.ar_aging_summary' => 'P', 'rpt.ar_aging_detail' => 'P', 'rpt.collections' => 'P', 'rpt.open_invoices' => 'P'],
            'special' => ['view_costs' => '0', 'max_discount_pct' => '5', 'max_price_override_pct' => '0', 'max_amount' => '0'],
        ],
        'Safety Officer' => [
            'desc' => 'Safety records: incidents, near misses, toolbox meetings, training, PPE, permits to work and hazard assessments; equipment safety inspections.',
            'pages' => $dash + $projView + ['site.safety' => 'CEDPA', 'site.inspections' => 'CEP', 'site.daily' => 'P', 'rpt.cs_qhse' => 'P', 'equip.register' => 'P'],
            'special' => ['view_costs' => '0', 'max_amount' => '0'],
        ],
        'Quality Inspector' => [
            'desc' => 'Inspection and test plans, quality checklists, material tests, nonconformance reports and corrective actions.',
            'pages' => $dash + $projView + ['site.inspections' => 'CEDPA', 'site.ncr' => 'CEDPA', 'site.daily' => 'P', 'plan.milestones' => 'EP', 'proj.closeout' => 'EP', 'proj.documents' => 'P', 'rpt.cs_qhse' => 'P'],
            'special' => ['view_costs' => '0', 'max_amount' => '0'],
        ],
        'Internal Auditor' => [
            'desc' => 'View-only access to every record, report and the audit trail. Cannot create, change or approve anything.',
            'pages' => ['*' => 'P'],
            'deny' => array_merge($adminSetup, ['pos.register', 'pos.shifts', 'portal.client', 'portal.sub']),
            'special' => ['view_costs' => '1', 'view_bank_balances' => '1'],
        ],
        'External Client / Client Representative' => [
            'desc' => 'Portal: the progress, milestones, billings, statements and documents of the client\'s own projects.',
            'pages' => ['portal.client' => 'P'],
            'special' => ['view_costs' => '0', 'max_amount' => '0'],
        ],
        'Subcontractor Portal User' => [
            'desc' => 'Portal: the subcontractor\'s own agreements, progress claims, retention and payments.',
            'pages' => ['portal.sub' => 'CP'],
            'special' => ['view_costs' => '0', 'max_amount' => '0'],
        ],
    ];

    $roleIds = [];
    foreach ($roleDefs as $name => $def) {
        $rid = DB::insert('roles', ['name' => $name, 'description' => $def['desc'], 'is_admin' => $def['admin'] ?? 0, 'is_system' => 1, 'all_sites' => 1]);
        $roleIds[$name] = $rid;
        $grant = [];
        foreach ($def['pages'] as $pattern => $letters) {
            $keys = $pattern === '*' ? array_keys($pageDefs) : $select($pattern);
            foreach ($keys as $k) {
                $grant[$k] = ($grant[$k] ?? '') . $letters;
            }
        }
        foreach ($def['deny'] ?? [] as $k) {
            unset($grant[$k]);
        }
        // nobody but a portal user opens a portal page
        if (!in_array($name, ['External Client / Client Representative', 'Subcontractor Portal User'], true)) {
            unset($grant['portal.client'], $grant['portal.sub']);
        }
        foreach ($grant as $k => $letters) {
            if (!isset($pageIds[$k])) {
                continue;
            }
            DB::insert('role_page_access', [
                'role_id' => $rid, 'page_id' => $pageIds[$k], 'allowed' => 1,
                'can_create' => (int)str_contains($letters, 'C'), 'can_edit' => (int)str_contains($letters, 'E'),
                'can_delete' => (int)str_contains($letters, 'D'), 'can_print' => (int)str_contains($letters, 'P'),
                'can_approve' => (int)str_contains($letters, 'A'),
            ]);
        }
        foreach ($def['special'] as $k => $v) {
            DB::insert('role_special_permissions', ['role_id' => $rid, 'perm_key' => $k, 'perm_value' => $v]);
        }
    }
    return $roleIds;
}

/** Cost codes, trades, checklist templates, approval routes and allocation rules */
function seed_construction_setup(array $acct, array $roleIds): void
{
    // ---- cost codes: work items (the WBS of a building or civil works job). Category = the usual cost type.
    $cc = [];
    foreach ([
        ['01', 'General Requirements', 'other', null, null],
        ['01-100', 'Mobilization and Demobilization', 'other', '01', '5390'],
        ['01-200', 'Temporary Facilities', 'other', '01', '5355'],
        ['01-300', 'Site Supervision and Administration', 'labor', '01', '5370'],
        ['01-400', 'Permits and Clearances', 'other', '01', '5360'],
        ['01-500', 'Insurance and Bonds', 'other', '01', '5375'],
        ['01-600', 'Safety and Health Program', 'other', '01', '5390'],
        ['01-700', 'Testing and Quality Control', 'other', '01', '5380'],
        ['01-800', 'Shared Overhead (allocated)', 'overhead', '01', null],
        ['02', 'Site Works', 'equipment', null, null],
        ['02-100', 'Clearing and Grubbing', 'equipment', '02', '5330'],
        ['02-200', 'Excavation and Earthworks', 'equipment', '02', '5330'],
        ['02-300', 'Backfill and Compaction', 'equipment', '02', '5330'],
        ['03', 'Concrete Works', 'material', null, null],
        ['03-100', 'Formworks and Scaffolding', 'material', '03', '5310'],
        ['03-200', 'Reinforcing Steel', 'material', '03', '5310'],
        ['03-300', 'Structural Concrete', 'material', '03', '5310'],
        ['04', 'Masonry Works', 'material', null, null],
        ['04-100', 'CHB Walls and Plastering', 'material', '04', '5310'],
        ['05', 'Metal and Roofing Works', 'material', null, null],
        ['05-100', 'Structural Steel', 'material', '05', '5310'],
        ['05-200', 'Roof Framing and Roofing', 'material', '05', '5310'],
        ['06', 'Architectural Finishes', 'material', null, null],
        ['06-100', 'Doors and Windows', 'material', '06', '5310'],
        ['06-200', 'Ceiling Works', 'material', '06', '5310'],
        ['06-300', 'Painting Works', 'subcontract', '06', '5340'],
        ['06-400', 'Tiles and Flooring', 'material', '06', '5310'],
        ['07', 'Plumbing and Sanitary Works', 'subcontract', null, null],
        ['07-100', 'Plumbing and Fixtures', 'subcontract', '07', '5340'],
        ['08', 'Electrical Works', 'subcontract', null, null],
        ['08-100', 'Electrical Roughing-in and Wiring', 'subcontract', '08', '5340'],
        ['09', 'Mechanical Works', 'subcontract', null, null],
        ['09-100', 'Air-conditioning and Ventilation', 'subcontract', '09', '5340'],
        ['10', 'Road and Drainage Works', 'material', null, null],
        ['10-100', 'Aggregate Base Course', 'material', '10', '5310'],
        ['10-200', 'Portland Cement Concrete Pavement', 'material', '10', '5310'],
        ['10-300', 'Drainage and Culverts', 'material', '10', '5310'],
    ] as $i => [$code, $name, $cat, $parent, $glCode]) {
        $cc[$code] = DB::insert('cs_cost_codes', ['code' => $code, 'name' => $name, 'category' => $cat, 'parent_id' => $parent ? $cc[$parent] : null,
            'account_id' => $glCode ? $acct[$glCode] : null, 'sort_order' => ($i + 1) * 10]);
    }

    // ---- trades and their usual daily rates (Metro Manila, 2026)
    foreach ([
        ['Laborer / Helper', 'General labor and helpers', 695], ['Carpenter', 'Formworks, framing and finishing carpentry', 850], ['Mason', 'Concrete, CHB and plastering', 850],
        ['Steelman', 'Rebar cutting, bending and tying', 880], ['Welder', 'Structural and pipe welding', 950], ['Electrician', 'Electrical roughing-in and wiring', 950],
        ['Plumber', 'Plumbing and sanitary works', 900], ['Painter', 'Surface preparation and painting', 820], ['Tile Setter', 'Tiles and stone finishes', 880],
        ['Heavy Equipment Operator', 'Excavator, loader, roller and crane operators', 1100], ['Driver', 'Dump truck and service vehicle drivers', 850],
        ['Foreman', 'Crew leader on site', 1200], ['Surveyor', 'Line and grade, quantity measurement', 1100], ['Timekeeper', 'Attendance and time records', 750],
    ] as [$n, $d, $r]) {
        DB::insert('cs_trades', ['name' => $n, 'description' => $d, 'daily_rate' => $r]);
    }

    // ---- checklist templates
    foreach ([
        ['milestone', 'Milestone completion evidence', "Accomplishment report signed by the site engineer\nProgress photographs\nInspection report\nTest results (where required)\nEngineer certification\nClient acceptance or certificate of completion"],
        ['closeout', 'Project close-out', "Final accomplishment verified\nPunch list cleared\nCertificate of completion issued\nAs-built drawings turned over\nEquipment manuals and warranties turned over\nTesting and commissioning records\nClient acceptance signed\nOccupancy or completion permit\nFinal billing issued\nFinal collection received\nRetention release billed\nSubcontractors closed out (final billing, retention, clearances)\nWarranty obligations registered\nFinal project cost and profit reviewed"],
        ['inspection', 'Pre-pour concrete inspection', "Formworks aligned and braced\nRebar size, spacing and cover as per plan\nEmbedded items and sleeves in place\nConcrete mix design approved\nSlump test before pouring\nCylinder samples taken"],
        ['inspection', 'Electrical roughing-in inspection', "Conduit sizes as per plan\nBoxes and fittings secured\nGrounding provisions\nNo damaged conduits\nPull wires in place"],
        ['tender', 'Tender document checklist', "Eligibility documents (PhilGEPS, PCAB licence)\nBid security\nTechnical proposal\nFinancial proposal and BOQ\nOmnibus sworn statement\nSchedule of work\nKey personnel and equipment list"],
    ] as [$kind, $name, $items]) {
        DB::insert('cs_checklist_templates', ['kind' => $kind, 'name' => $name, 'items' => $items]);
    }

    // ---- approval routes: doc type, name, min, max, steps [label, role name or project role]
    $routes = [
        ['contract', 'Contracts', 0, null, [['Project Director review', 'Project Director'], ['General Manager approval', 'General Manager']]],
        ['estimate', 'Estimates', 0, null, [['Project Director approval', 'Project Director']]],
        ['budget', 'Project budgets and revisions', 0, null, [['Project Manager', '@pm'], ['Project Director', 'Project Director'], ['Finance Manager', 'Finance Manager']]],
        ['change_order', 'Change orders (internal approval)', 0, 1000000, [['Project Manager', '@pm'], ['Project Director', 'Project Director']]],
        ['change_order', 'Change orders of P1M and above', 1000000, null, [['Project Manager', '@pm'], ['Project Director', 'Project Director'], ['General Manager', 'General Manager']]],
        ['material_request', 'Material requests below P100,000', 0, 100000, [['Project Manager', '@pm']]],
        ['material_request', 'Material requests of P100,000 and above', 100000, null, [['Project Manager', '@pm'], ['Purchasing Manager', 'Purchasing Manager']]],
        ['purchase', 'Canvass award and purchase order below P500,000', 0, 500000, [['Purchasing Manager', 'Purchasing Manager']]],
        ['purchase', 'Canvass award and purchase order of P500,000 and above', 500000, null, [['Purchasing Manager', 'Purchasing Manager'], ['Finance Manager', 'Finance Manager'], ['General Manager', 'General Manager']]],
        ['material_transfer', 'Material transfers between projects', 0, null, [['Project Manager (giving project)', '@pm'], ['Operations Manager', 'Operations Manager']]],
        ['timesheet', 'Daily timesheets', 0, null, [['Project Manager or site engineer', '@pm']]],
        ['equipment_log', 'Equipment usage charges', 0, null, [['Project Manager', '@pm']]],
        ['daily_report', 'Daily site reports', 0, null, [['Project Manager', '@pm']]],
        ['cash_advance', 'Cash advances', 0, null, [['Project Manager', '@pm'], ['Finance Manager', 'Finance Manager']]],
        ['subcontract', 'Subcontract agreements', 0, null, [['Project Director', 'Project Director'], ['General Manager', 'General Manager']]],
        ['sub_billing', 'Subcontractor billings below P500,000', 0, 500000, [['Quantity Surveyor', 'Quantity Surveyor'], ['Project Manager', '@pm'], ['Finance Manager', 'Finance Manager']]],
        ['sub_billing', 'Subcontractor billings of P500,000 and above', 500000, null, [['Quantity Surveyor', 'Quantity Surveyor'], ['Project Manager', '@pm'], ['Finance Manager', 'Finance Manager'], ['General Manager', 'General Manager']]],
        ['billing', 'Progress billing to clients', 0, null, [['Project Manager', '@pm'], ['Quantity Surveyor / billing engineer', 'Quantity Surveyor'], ['Accounting review', 'Chief Accountant / Controller'], ['Finance Manager', 'Finance Manager'], ['Authorized approver', 'General Manager']]],
        ['allocation', 'Shared cost allocations and reallocations', 0, null, [['Chief Accountant', 'Chief Accountant / Controller'], ['Finance Manager', 'Finance Manager']]],
        ['revenue', 'Revenue recognition', 0, null, [['Chief Accountant', 'Chief Accountant / Controller']]],
        ['payroll', 'Payroll runs', 0, null, [['Chief Accountant', 'Chief Accountant / Controller'], ['Finance Manager', 'Finance Manager']]],
        ['closeout', 'Financial closure with open items', 0, null, [['Finance Manager', 'Finance Manager'], ['General Manager', 'General Manager']]],
    ];
    $sortR = 0;
    foreach ($routes as [$type, $name, $min, $max, $steps]) {
        $rid = DB::insert('cs_approval_routes', ['doc_type' => $type, 'name' => $name, 'min_amount' => $min, 'max_amount' => $max, 'sort_order' => ($sortR += 10)]);
        foreach ($steps as $i => [$label, $who]) {
            DB::insert('cs_approval_steps', [
                'route_id' => $rid, 'step_no' => $i + 1, 'label' => $label,
                'role_id' => str_starts_with($who, '@') ? null : $roleIds[$who],
                'project_role' => str_starts_with($who, '@') ? substr($who, 1) : null,
                'due_hours' => 48,
            ]);
        }
    }

    // ---- allocation rules
    foreach ([
        ['Equal share among active projects', 'equal', 'Splits a shared cost evenly among the projects chosen on the allocation'],
        ['By man-hours worked', 'man_hours', 'Approved timesheet hours of each project in the period'],
        ['By equipment hours', 'equipment_hours', 'Operating hours logged on each project in the period'],
        ['By direct cost', 'direct_cost', 'Direct costs posted to each project in the period'],
        ['By contract value', 'contract_value', 'Current contract value of each project'],
        ['By revenue recognized', 'revenue', 'Revenue recognized for each project in the period'],
        ['By floor area', 'floor_area', 'Floor area of each project'],
        ['By material consumption', 'material_cost', 'Materials charged to each project in the period'],
    ] as [$n, $m, $d]) {
        DB::insert('cs_alloc_rules', ['name' => $n, 'method' => $m, 'description' => $d]);
    }
}
