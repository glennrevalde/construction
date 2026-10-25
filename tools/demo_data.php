<?php
declare(strict_types=1);

/**
 * Sample data of GSMaRT Construction System: a general contractor with four projects at different stages, recorded
 * through the same classes the screens use, so the ledger, stock, approvals and audit trail agree with each other.
 *
 *   PRJ-0001  St. Benedict School Gymnasium        finished last month, under warranty, retention release waiting
 *   PRJ-0002  Northgate Residences Tower 2          main job, about a third done: every module in daily use
 *   PRJ-0003  C-5 Service Road Widening (DPWH)      government unit-price contract, about two thirds done
 *   PRJ-0004  Pacific Agri-Foods Warehouse          just won: estimate approved, contract waiting for approval
 *
 * Dates count back from the day the data is generated, so the aging, schedules and dashboards look current.
 */
require_once APP_ROOT . '/views/layout.php';
require_once APP_ROOT . '/modules/txn/_common.php';

function demo_id(string $table, string $col, string $val): int
{
    $id = DB::value("SELECT id FROM `$table` WHERE `$col` = :v", ['v' => $val]);
    if (!$id) {
        throw new RuntimeException("Demo data: $table.$col = $val not found");
    }
    return (int)$id;
}

function dm_d(int $daysAgo): string
{
    return date('Y-m-d', strtotime(date('Y-m-d') . ' ' . ($daysAgo >= 0 ? '-' : '+') . abs($daysAgo) . ' days'));
}

function dm_user(string $username): int
{
    static $cache = [];
    return $cache[$username] ??= demo_id('users', 'username', $username);
}

function dm_as(string $username): void
{
    Auth::actAs(dm_user($username));
    CsProject::flush();
}

/** Moves every pending step of a document's approval along, each by someone allowed to act on it */
function dm_approve(string $type, int $docId, ?string $when = null, int $steps = 99, string $comment = ''): void
{
    $prev = (int)Auth::id();
    $n = 0;
    $reqId = null;
    while ($n < $steps && ($req = CsApproval::pendingFor($type, $docId))) {
        $reqId = (int)$req['id'];
        $who = null;
        foreach (CsApproval::stepUsers($req) as $u) {
            if (CsApproval::canAct($req, (int)$u)) {
                $who = (int)$u;
                break;
            }
        }
        if (!$who) {
            foreach (['admin', 'owner', 'gm'] as $un) {
                if (CsApproval::canAct($req, dm_user($un))) {
                    $who = dm_user($un);
                    break;
                }
            }
        }
        if (!$who) {
            throw new RuntimeException("Demo data: nobody can approve $type #$docId step " . $req['current_step']);
        }
        Auth::actAs($who);
        CsProject::flush();
        CsApproval::decide($reqId, 'approve', $comment);
        $n++;
    }
    if ($when && $reqId === null) {
        $reqId = (int)(CsApproval::requestFor($type, $docId)['id'] ?? 0) ?: null;
    }
    if ($when && $reqId) {
        dm_backdate($reqId, $when);
    }
    Auth::actAs($prev);
    CsProject::flush();
}

/** Dates the approval of a document on the day it really happened (the screens show when each step was taken) */
function dm_backdate(int $reqId, string $date): void
{
    $base = strtotime($date . ' 08:30:00');
    DB::run('UPDATE cs_approval_requests SET requested_at = :a, step_since = :b, due_at = :c WHERE id = :id',
        ['a' => date('Y-m-d H:i:s', $base), 'b' => date('Y-m-d H:i:s', $base + 3600), 'c' => date('Y-m-d H:i:s', $base + 48 * 3600), 'id' => $reqId]);
    DB::run("UPDATE cs_approval_requests SET decided_at = :d WHERE id = :id AND status <> 'pending'", ['d' => date('Y-m-d H:i:s', $base + 6 * 3600), 'id' => $reqId]);
    // the document's own dates of submission and approval
    static $cols = [];
    $req = DB::row('SELECT doc_type, doc_id FROM cs_approval_requests WHERE id = :id', ['id' => $reqId]);
    $table = ['contract' => 'cs_contracts', 'estimate' => 'cs_estimates', 'budget' => 'cs_budgets', 'change_order' => 'cs_change_orders', 'material_request' => 'cs_material_requests',
        'purchase' => 'cs_canvass', 'material_transfer' => 'cs_material_docs', 'timesheet' => 'cs_timesheets', 'payroll' => 'cs_payroll_runs', 'cash_advance' => 'cs_cash_advances',
        'equipment_log' => 'cs_equipment_logs', 'daily_report' => 'cs_daily_reports', 'subcontract' => 'cs_subcontracts', 'sub_billing' => 'cs_sub_billings', 'billing' => 'cs_billings',
        'revenue' => 'cs_revenue_recognitions', 'allocation' => 'cs_allocations'][$req['doc_type']] ?? null;
    if ($table) {
        $cols[$table] ??= array_column(DB::all("SHOW COLUMNS FROM `$table`"), 'Field');
        foreach (['submitted_at' => 900, 'approved_at' => 6 * 3600, 'internal_approved_at' => 6 * 3600, 'posted_at' => 6 * 3600 + 60] as $c => $off) {
            if (in_array($c, $cols[$table], true)) {
                DB::run("UPDATE `$table` SET `$c` = :t WHERE id = :id AND `$c` IS NOT NULL", ['t' => date('Y-m-d H:i:s', $base + $off), 'id' => $req['doc_id']]);
            }
        }
    }
    $i = 0;
    foreach (DB::column('SELECT id FROM cs_approval_actions WHERE request_id = :r ORDER BY id', ['r' => $reqId]) as $aid) {
        DB::run('UPDATE cs_approval_actions SET created_at = :t WHERE id = :id', ['t' => date('Y-m-d H:i:s', $base + ($i++) * 5400), 'id' => $aid]);
    }
}

/** Monday to Saturday between two dates (Sundays and a few holidays off) */
function dm_workdays(string $from, string $to): array
{
    $out = [];
    $holidays = ['12-25', '12-30', '01-01', '04-09', '05-01', '06-12', '08-21', '08-25', '11-01', '11-30'];
    for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
        if (date('N', $t) === '7' || in_array(date('m-d', $t), $holidays, true)) {
            continue;
        }
        $out[] = date('Y-m-d', $t);
    }
    return $out;
}

/** A one-page PDF with a title and a few lines of text (sample drawings and documents) */
function dm_pdf(string $title, array $lines): string
{
    $esc = fn(string $s) => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    $content = "BT /F1 18 Tf 60 760 Td (" . $esc($title) . ") Tj ET\n";
    $y = 720;
    foreach ($lines as $l) {
        $content .= "BT /F1 11 Tf 60 $y Td (" . $esc($l) . ") Tj ET\n";
        $y -= 18;
    }
    $content .= "0.6 w 50 60 495 730 re S\n";
    $objs = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream"];
    $pdf = "%PDF-1.4\n";
    $offs = [];
    foreach ($objs as $i => $o) {
        $offs[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n" . $o . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
    foreach ($offs as $o) {
        $pdf .= sprintf("%010d 00000 n \n", $o);
    }
    return $pdf . "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
}

/** Saves sample file content in the attachments folder the way an upload is stored: [path, size] */
function dm_store_file(string $content, string $ext): array
{
    $dir = CsFiles::dir();
    $sub = date('Y/m');
    if (!is_dir($dir . '/' . $sub)) {
        @mkdir($dir . '/' . $sub, 0775, true);
    }
    $name = $sub . '/' . bin2hex(random_bytes(10)) . '.' . $ext;
    file_put_contents($dir . '/' . $name, $content);
    return [$name, strlen($content)];
}

/** Shared state of the generator */
function &dm(): array
{
    static $s = [];
    return $s;
}

function load_demo_data(): void
{
    mt_srand(20261023);
    $_SESSION = $_SESSION ?? [];
    dm_as('admin');
    dm_masters();
    dm_projects();
    dm_timeline();
    dm_finish();
}

// =====================================================================================================================
// Lists: banks, clients, suppliers, subcontractors, materials, workers, equipment
// =====================================================================================================================

function dm_masters(): void
{
    $S = &dm();
    $acct = fn(string $c) => demo_id('accounts', 'code', $c);
    $S['acct'] = $acct;
    $vat = demo_id('tax_codes', 'code', 'VAT12');
    $S['vat'] = $vat;
    $wt = fn(string $c) => demo_id('withholding_taxes', 'code', $c);
    $terms = fn(string $n) => demo_id('terms', 'name', $n);
    $S['pm'] = fn(string $n) => demo_id('payment_methods', 'name', $n);
    $open = dm_d(400);
    $S['open'] = $open;

    // ---- opening balances: cash, the equipment fleet and its financing, capital
    Txn::save('opening_balance', ['txn_date' => $open, 'memo' => 'Opening balances', 'txn_no' => 'OB-0001'], [
        ['line_kind' => 'journal', 'account_id' => $acct('1010'), 'amount' => 60000000, 'description' => 'BDO current account'],
        ['line_kind' => 'journal', 'account_id' => $acct('1020'), 'amount' => 9200000, 'description' => 'BPI savings account'],
        ['line_kind' => 'journal', 'account_id' => $acct('1030'), 'amount' => 1500000, 'description' => 'Landbank (government collections)'],
        ['line_kind' => 'journal', 'account_id' => $acct('1005'), 'amount' => 50000, 'description' => 'Petty cash fund, head office'],
        ['line_kind' => 'journal', 'account_id' => $acct('1006'), 'amount' => 300000, 'description' => 'Site revolving fund'],
        ['line_kind' => 'journal', 'account_id' => $acct('1520'), 'amount' => 21850000, 'description' => 'Construction equipment at cost'],
        ['line_kind' => 'journal', 'account_id' => $acct('1525'), 'amount' => -5310000, 'description' => 'Accumulated depreciation, construction equipment'],
        ['line_kind' => 'journal', 'account_id' => $acct('1530'), 'amount' => 1650000, 'description' => 'Service vehicles at cost'],
        ['line_kind' => 'journal', 'account_id' => $acct('1535'), 'amount' => -495000, 'description' => 'Accumulated depreciation, service vehicles'],
        ['line_kind' => 'journal', 'account_id' => $acct('1610'), 'amount' => 850000, 'description' => 'Bid and performance security deposits'],
        ['line_kind' => 'journal', 'account_id' => $acct('2500'), 'amount' => -7200000, 'description' => 'Equipment financing loan'],
        ['line_kind' => 'journal', 'account_id' => $acct('3000'), 'amount' => -50000000, 'description' => 'Capital stock'],
        ['line_kind' => 'journal', 'account_id' => $acct('3200'), 'amount' => -32395000, 'description' => 'Retained earnings'],
    ]);

    // ---- clients
    $cust = function (array $c) use ($terms) {
        $id = DB::insert('customers', $c + ['is_active' => 1]);
        Audit::log('create', 'cust', 'customer', $id, $c['name'], ['page_key' => 'cust.center']);
        return $id;
    };
    $ct = fn(string $n) => demo_id('customer_types', 'name', $n);
    $S['cust'] = [
        'school' => $cust(['name' => 'St. Benedict School Foundation, Inc.', 'contact_person' => 'Sr. Teresita Lagman, OSB', 'phone' => '(02) 8721-3340', 'email' => 'finance@stbenedict.test',
            'billing_address' => '120 Benedictine St., San Juan City, Metro Manila', 'tin' => '000-456-781-000', 'terms_id' => $terms('Net 30'), 'tax_code_id' => $vat, 'customer_type_id' => $ct('Institutional'),
            'client_class' => 'institutional', 'wht_ids' => (string)$wt('WT-CONTRACTOR')]),
        'northgate' => $cust(['name' => 'Northgate Land Development Corp.', 'company_name' => 'Northgate Land Development Corp.', 'contact_person' => 'Atty. Ramon Velasco', 'phone' => '(02) 8890-1155',
            'email' => 'projects@northgateland.test', 'billing_address' => '25/F Northgate Center, Ayala Ave., Makati City', 'tin' => '008-221-934-000', 'terms_id' => $terms('Net 30'),
            'tax_code_id' => $vat, 'customer_type_id' => $ct('Private Developer'), 'client_class' => 'private', 'wht_ids' => (string)$wt('WT-CONTRACTOR'), 'credit_limit' => 60000000]),
        'dpwh' => $cust(['name' => 'DPWH Metro Manila 1st District Engineering Office', 'contact_person' => 'Engr. Rosalinda Pineda, District Engineer', 'phone' => '(02) 8304-3000',
            'email' => 'mm1deo@dpwh.test', 'billing_address' => 'Port Area, Manila', 'tin' => '000-910-128-000', 'terms_id' => $terms('Net 45'), 'tax_code_id' => $vat,
            'customer_type_id' => $ct('Government'), 'client_class' => 'government', 'wht_ids' => $wt('WT-CONTRACTOR') . ',' . $wt('WT-VAT')]),
        'pacific' => $cust(['name' => 'Pacific Agri-Foods Corporation', 'contact_person' => 'Mr. Daniel Co, VP Operations', 'phone' => '(049) 545-7781', 'email' => 'dco@pacificagri.test',
            'billing_address' => 'Lot 7 Light Industry and Science Park, Calamba, Laguna', 'tin' => '007-345-112-000', 'terms_id' => $terms('Net 30'), 'tax_code_id' => $vat,
            'customer_type_id' => $ct('Corporate'), 'client_class' => 'corporate', 'wht_ids' => (string)$wt('WT-CONTRACTOR')]),
        'harbor' => $cust(['name' => 'Harbor Point Properties, Inc.', 'contact_person' => 'Ms. Celine Ong', 'phone' => '(02) 8556-2010', 'email' => 'celine.ong@harborpoint.test',
            'billing_address' => 'Pasay City', 'terms_id' => $terms('Net 30'), 'tax_code_id' => $vat, 'customer_type_id' => $ct('Private Developer'), 'client_class' => 'private']),
    ];

    // ---- suppliers, subcontractors, rental companies, service providers
    $vend = function (array $v) {
        $id = DB::insert('vendors', $v + ['is_active' => 1]);
        Audit::log('create', 'vend', 'vendor', $id, $v['name'], ['page_key' => 'vend.center']);
        return $id;
    };
    $V = [];
    foreach ([
        ['metrocement', 'Metro Cement & Aggregates Trading', 'supplier', 'Cement, sand, gravel and aggregates', 'Net 30', '5310', null, null, 4],
        ['pacsteel', 'Pacific Steel & Rebar Supply Corp.', 'supplier', 'Deformed bars, tie wire, structural steel', 'Net 30', '5310', null, null, 5],
        ['northline', 'Northline Hardware & Construction Supply', 'supplier', 'Plywood, lumber, CHB, hardware', 'Net 15', '5310', null, null, 4],
        ['primemix', 'Prime Ready-Mix Concrete, Inc.', 'supplier', 'Ready-mix concrete and pumping', 'Net 30', '5310', null, null, 5],
        ['bulacanagg', 'Bulacan Aggregates Corp.', 'supplier', 'Aggregate base course, borrow and embankment materials', 'Net 30', '5310', null, null, 3],
        ['rapidfuel', 'Rapid Fuel Services', 'supplier', 'Diesel and lubricants delivered on site', 'Net 15', '6140', null, null, 4],
        ['allied', 'Allied Electrical Contractors', 'subcontractor', 'Electrical works', 'Net 30', '5340', 'PCAB 41822 (Electrical, AAA)', 380, 5],
        ['aquaflow', 'AquaFlow Plumbing Services', 'subcontractor', 'Plumbing and sanitary works', 'Net 30', '5340', 'PCAB 38817 (Plumbing, A)', 220, 4],
        ['colorcoat', 'ColorCoat Painting Works', 'subcontractor', 'Painting and coating works', 'Net 30', '5340', 'PCAB 40210 (Painting, B)', 150, 3],
        ['bayanihan', 'Bayanihan Manpower Services Cooperative', 'labor_contractor', 'Labor-only manpower (DOLE D.O. 174 registered)', 'Net 15', '5320', 'DOLE NCR-174-2025-0091', 300, 4],
        ['heavyline', 'Heavyline Equipment Rentals, Inc.', 'equipment_lessor', 'Cranes, rollers and heavy equipment for rent', 'Net 30', '5335', null, null, 4],
        ['qualitest', 'QualiTest Materials Laboratory', 'service_provider', 'Concrete, soil and steel testing', 'Net 30', '5380', null, null, 5],
        ['insurepro', 'InsurePro Surety & Insurance Co.', 'service_provider', 'Contractor\'s all-risk insurance and bonds', 'Due on receipt', '5375', null, null, 4],
        ['yardlease', 'C-5 Yard Leasing Corp.', 'service_provider', 'Lease of the Taguig equipment yard and warehouse', 'Net 15', '6180', null, null, 4],
        ['officelease', 'Summit Tower Management', 'service_provider', 'Head office space', 'Net 15', '6010', null, null, 4],
        ['metropower', 'Metro Power Distribution Co.', 'service_provider', 'Electricity', 'Net 15', '6020', null, null, 3],
        ['tollway', 'Southlink Logistics', 'supplier', 'Hauling and trucking', 'Net 15', '5350', null, null, 4],
    ] as [$k, $name, $kind, $cat, $tn, $acc, $accr, $expDays, $rate]) {
        $V[$k] = $vend(['name' => $name, 'company_name' => $name, 'vendor_kind' => ['labor_contractor' => 'subcontractor', 'equipment_lessor' => 'service', 'service_provider' => 'service'][$kind] ?? $kind, 'service_categories' => $cat, 'terms_id' => $terms($tn), 'tax_code_id' => $vat,
            'default_expense_account_id' => $acct($acc), 'accreditation_no' => $accr, 'accreditation_expiry' => $expDays !== null ? dm_d(-$expDays) : null, 'rating' => $rate,
            'address' => 'Metro Manila', 'contact_person' => 'Accounts Department', 'phone' => '(02) 8' . (7000 + count($V) * 37) . '-' . (1000 + count($V) * 13),
            'tin' => sprintf('%03d-%03d-%03d-000', 100 + count($V) * 7, 200 + count($V) * 11, 300 + count($V) * 13),
            'wht_ids' => (string)$wt(match ($kind) { 'subcontractor', 'labor_contractor' => 'WT-CONTRACTOR', 'equipment_lessor' => 'WT-RENT', 'service_provider' => 'WT-SERVICES', default => 'WT-GOODS' }),
            'qualifications' => $kind === 'subcontractor' ? 'Track record on mid-rise residential and institutional buildings' : null]);
    }
    $S['vend'] = $V;

    // ---- portal accounts: the client's representative and the electrical subcontractor
    DB::run('UPDATE users SET portal_customer_id = :c WHERE username = :u', ['c' => $S['cust']['northgate'], 'u' => 'client']);
    DB::run('UPDATE users SET portal_vendor_id = :v WHERE username = :u', ['v' => $V['allied'], 'u' => 'subcon']);

    // ---- materials (inventory items) and the warehouse
    $cat = fn(string $n) => demo_id('item_categories', 'name', $n);
    $items = [];
    foreach ([
        ['CEM-40', 'Portland cement Type 1, 40 kg', 'bag', 265, 'Cement and Aggregates', 'metrocement'],
        ['SAND-W', 'Washed sand', 'cu.m', 1350, 'Cement and Aggregates', 'metrocement'],
        ['GRVL-34', 'Gravel 3/4"', 'cu.m', 1550, 'Cement and Aggregates', 'metrocement'],
        ['ABC-G1', 'Aggregate base course, Grading A', 'cu.m', 1080, 'Cement and Aggregates', 'bulacanagg'],
        ['RB-10', 'Deformed bar 10 mm x 6 m, Grade 60', 'len', 205, 'Steel and Rebar', 'pacsteel'],
        ['RB-12', 'Deformed bar 12 mm x 6 m, Grade 60', 'len', 292, 'Steel and Rebar', 'pacsteel'],
        ['RB-16', 'Deformed bar 16 mm x 6 m, Grade 60', 'len', 520, 'Steel and Rebar', 'pacsteel'],
        ['RB-20', 'Deformed bar 20 mm x 6 m, Grade 60', 'len', 812, 'Steel and Rebar', 'pacsteel'],
        ['TW-16', 'Tie wire #16', 'kg', 98, 'Steel and Rebar', 'pacsteel'],
        ['PLY-12', 'Marine plywood 1/2" x 4 x 8', 'sht', 980, 'Lumber and Formworks', 'northline'],
        ['CL-2X3', 'Coco lumber 2" x 3" x 10\'', 'pc', 145, 'Lumber and Formworks', 'northline'],
        ['CHB-4', 'Concrete hollow block 4"', 'pc', 16.5, 'Masonry', 'northline'],
        ['CHB-6', 'Concrete hollow block 6"', 'pc', 22, 'Masonry', 'northline'],
        ['DSL', 'Diesel fuel', 'L', 62, 'Fuel and Lubricants', 'rapidfuel'],
        ['PPE-HH', 'Safety helmet with chin strap', 'pc', 285, 'Safety and PPE', 'northline'],
    ] as [$code, $name, $unit, $cost, $c, $pv]) {
        $items[$code] = DB::insert('items', ['item_type' => 'inventory', 'code' => $code, 'name' => $name, 'description' => $name, 'purchase_description' => $name,
            'category_id' => $cat($c), 'unit' => $unit, 'cost' => $cost, 'avg_cost' => 0, 'sales_price' => r2($cost * 1.18), 'income_account_id' => $acct('4020'),
            'cogs_account_id' => $acct('5000'), 'asset_account_id' => $acct('1200'), 'tax_code_id' => $vat, 'preferred_vendor_id' => $V[$pv],
            'reorder_point' => $unit === 'bag' ? 300 : 0]);
        Audit::log('create', 'inv', 'item', $items[$code], $code . ' ' . $name, ['page_key' => 'inv.items']);
    }
    $S['items'] = $items;
    $S['wh'] = demo_id('sites', 'code', 'WH');

    // ---- the crews (each worker is also an employee name in accounting)
    $trade = fn(string $n) => demo_id('cs_trades', 'name', $n);
    $W = [];
    foreach ([
        // key, name, trade, class, daily rate, burden %, allowance, crew
        ['rodel', 'Rodel Santos', 'Foreman', 'staff', 1250, 18, 150, 'tower'],
        ['jun', 'Jun Dela Cruz', 'Carpenter', 'direct', 860, 15, 80, 'tower'],
        ['arnel', 'Arnel Bautista', 'Carpenter', 'direct', 850, 15, 80, 'tower'],
        ['mario', 'Mario Reyes', 'Mason', 'direct', 860, 15, 80, 'tower'],
        ['ricky', 'Ricky Gonzales', 'Mason', 'direct', 850, 15, 80, 'tower'],
        ['ben', 'Ben Aquino', 'Steelman', 'direct', 890, 15, 80, 'tower'],
        ['jomar', 'Jomar Villanueva', 'Steelman', 'direct', 880, 15, 80, 'tower'],
        ['tony', 'Tony Mendoza', 'Laborer / Helper', 'direct', 695, 15, 60, 'tower'],
        ['ramil', 'Ramil Castro', 'Laborer / Helper', 'direct', 695, 15, 60, 'tower'],
        ['edwin', 'Edwin Flores', 'Heavy Equipment Operator', 'direct', 1120, 15, 100, 'tower'],
        ['dante', 'Dante Ramos', 'Foreman', 'staff', 1200, 18, 150, 'road'],
        ['noel', 'Noel Garcia', 'Heavy Equipment Operator', 'direct', 1100, 15, 100, 'road'],
        ['joel', 'Joel Pascual', 'Driver', 'direct', 860, 15, 80, 'road'],
        ['rey', 'Rey Navarro', 'Laborer / Helper', 'direct', 695, 15, 60, 'road'],
        ['allan', 'Allan Santiago', 'Laborer / Helper', 'direct', 695, 15, 60, 'road'],
        ['victor', 'Victor Lim', 'Mason', 'direct', 850, 15, 80, 'road'],
        ['leo', 'Leo Manalo', 'Laborer / Helper', 'labor_only', 720, 0, 0, 'tower'],
        ['carlo', 'Carlo Dizon', 'Carpenter', 'labor_only', 880, 0, 0, 'tower'],
    ] as $i => [$k, $name, $tr, $cl, $rate, $burden, $allow, $crew]) {
        $W[$k] = CsLabor::saveWorker(['full_name' => $name, 'trade_id' => $trade($tr), 'classification' => $cl, 'rate_basis' => 'daily', 'daily_rate' => $rate, 'burden_pct' => $burden,
            'allowance_daily' => $allow, 'shift_start' => '07:00', 'shift_end' => '16:00', 'break_minutes' => 60, 'ot_multiplier' => 1.25, 'holiday_multiplier' => 2,
            'vendor_id' => $cl === 'labor_only' ? $V['bayanihan'] : null, 'date_hired' => dm_d(900 - $i * 23), 'phone' => '0917 ' . (5120000 + $i * 3317),
            'certifications' => in_array($tr, ['Heavy Equipment Operator', 'Driver'], true) ? 'TESDA NC II' : ($tr === 'Foreman' ? 'COSH (40 hours)' : 'BOSH orientation'),
            'safety_docs' => 'Medical certificate, BOSH orientation'], null);
        $S['crew'][$crew][] = $k;
    }
    $S['workers'] = $W;

    // ---- the fleet: owned units (fixed assets), and leased and rented ones
    $faCat = fn(string $n) => (int)DB::row('SELECT id FROM asset_categories WHERE name = :n', ['n' => $n])['id'];
    $loc = DB::insert('asset_locations', ['name' => 'Taguig Equipment Yard']);
    $E = [];
    foreach ([
        // key, code, name, type, make, ownership, method, rate, idle rate, cost, meter, meter unit, fixed asset category, life, accumulated
        ['exc', 'EQ-001', 'Hydraulic excavator 20 t', 'excavator', 'Komatsu PC200-8', 'owned', 'standard', 2400, 600, 7800000, 6820, 'hours', 'Heavy Construction Equipment', 8, 1950000],
        ['bhl', 'EQ-002', 'Backhoe loader', 'backhoe', 'JCB 3CX', 'owned', 'standard', 1650, 400, 4600000, 4110, 'hours', 'Heavy Construction Equipment', 8, 1150000],
        ['dt1', 'EQ-003', 'Dump truck 10-wheeler', 'truck', 'Isuzu Giga 6WG1', 'owned', 'standard', 1250, 300, 4950000, 88400, 'km', 'Heavy Construction Equipment', 8, 1240000],
        ['mix', 'EQ-004', 'Concrete mixer, one-bagger', 'mixer', 'Hi-Power 1-bagger', 'owned', 'standard', 180, 40, 260000, 1880, 'hours', 'Light Construction Equipment', 5, 104000],
        ['gen', 'EQ-005', 'Generator set 60 kVA', 'generator', 'Denyo DCA-60', 'owned', 'standard', 650, 150, 1450000, 9120, 'hours', 'Light Construction Equipment', 5, 580000],
        ['wld', 'EQ-006', 'Welding machine 400 A', 'welder', 'Lincoln SAE-400', 'owned', 'standard', 220, 40, 280000, 3300, 'hours', 'Light Construction Equipment', 5, 112000],
        ['pick', 'EQ-007', 'Service pickup', 'vehicle', 'Toyota Hilux 2.4', 'owned', 'standard', 450, 0, 1650000, 61200, 'km', 'Transportation Equipment', 5, 495000],
        ['rol', 'EQ-008', 'Vibratory roller 10 t', 'roller', 'Sakai SV512', 'rented', 'rental', 0, 0, 0, 2210, 'hours', null, 0, 0],
        ['crane', 'EQ-009', 'Tower crane TC5610', 'crane', 'Zoomlion TC5610', 'leased', 'rental', 0, 0, 0, 0, 'hours', null, 0, 0],
        ['pump', 'EQ-010', 'Concrete pump, trailer', 'pump', 'Putzmeister BSA 1409', 'owned', 'standard', 1450, 300, 2510000, 2700, 'hours', 'Heavy Construction Equipment', 8, 0],
    ] as [$k, $code, $name, $type, $make, $own, $meth, $rate, $idle, $cost, $meter, $mu, $fac, $life, $acc]) {
        $fa = null;
        if ($fac) {
            $fa = DB::insert('fixed_assets', ['asset_no' => Seq::next('asset'), 'description' => $name . ' (' . $code . ')', 'category_id' => $faCat($fac), 'location' => 'Taguig Equipment Yard',
                'location_id' => $loc, 'serial_no' => strtoupper(substr(md5($code), 0, 10)), 'acquisition_date' => dm_d(400 + (int)($acc / max(1, $cost) * $life * 365)),
                'cost' => $cost, 'salvage_value' => r2($cost * 0.1), 'useful_life_years' => $life, 'method' => 'straight_line', 'opening_accum_dep' => $acc, 'opening_through' => dm_d(400),
                'current_meter' => $meter, 'meter_unit' => $mu]);
        }
        $E[$k] = CsEquip::save(['code' => $code, 'name' => $name, 'equipment_type' => $type, 'make_model' => $make, 'plate_serial' => strtoupper(substr(sha1($code), 0, 8)), 'ownership' => $own,
            'fixed_asset_id' => $fa, 'vendor_id' => $own === 'owned' ? null : $S['vend']['heavyline'], 'costing_method' => $meth, 'hourly_rate' => $rate, 'idle_rate' => $idle,
            'rental_basis' => $own === 'owned' ? null : 'monthly', 'rental_rate' => $own === 'rented' ? 185000 : ($own === 'leased' ? 420000 : 0), 'acquisition_cost' => $cost,
            'meter_unit' => $mu, 'current_meter' => $meter, 'fuel_type' => in_array($type, ['scaffold', 'crane'], true) ? 'Electric' : 'Diesel',
            'insurance_expiry' => dm_d(-200), 'inspection_due' => dm_d(-45), 'registration_expiry' => in_array($type, ['truck', 'vehicle'], true) ? dm_d(-120) : null,
            'next_service_meter' => $mu === 'hours' ? $meter + 250 : $meter + 5000]);
    }
    DB::update('cs_equipment', ['operator_worker_id' => $W['edwin']], 'id = :id', ['id' => $E['exc']]);
    DB::update('cs_equipment', ['operator_worker_id' => $W['noel']], 'id = :id', ['id' => $E['bhl']]);
    DB::update('cs_equipment', ['operator_worker_id' => $W['joel']], 'id = :id', ['id' => $E['dt1']]);
    $S['equip'] = $E;
}

// =====================================================================================================================
// The four projects: contract, project, estimate, budget, schedule of values, schedule, milestones, subcontracts
// =====================================================================================================================

/**
 * Plans of the sample projects. Offsets are days counted from the project start; 'start' is how many days ago it
 * started. Estimate lines: [cost code, category, description, qty, unit, unit cost]. Activities: [wbs, name, cost code,
 * start, finish, weight, planned qty, unit, budget man-hours, predecessors, milestone].
 */
function dm_plan(): array
{
    return [
        'gym' => [
            'name' => 'St. Benedict School Gymnasium', 'customer' => 'school', 'pm' => 'pm2', 'engineer' => 'siteeng', 'qs' => 'qs', 'division' => 'Buildings',
            'location' => '120 Benedictine St., San Juan City', 'floor_area' => 2850, 'start' => 380, 'days' => 320, 'signed' => 392, 'contract' => 44200000,
            'type' => 'lump_sum', 'advance' => 10, 'recoup' => 10, 'retention' => 10, 'ld' => 42800, 'client_ref' => 'SBSF-2025-GYM-01', 'lag' => 15,
            'scope' => 'Design-bid-build general construction of a 2,850 sq.m covered gymnasium with bleachers, locker rooms and long-span steel roof.',
            'estimate' => [
                ['01-100', 'site_prep', 'Mobilization and demobilization', 1, 'lot', 600000], ['01-200', 'temporary', 'Temporary facilities and utilities', 1, 'lot', 750000],
                ['01-300', 'site_admin', 'Site supervision and project team', 12, 'mo', 120000], ['01-400', 'permits', 'Building and occupancy permits', 1, 'lot', 380000],
                ['01-500', 'overhead', 'Insurance and bonds', 1, 'lot', 420000], ['02-200', 'equipment', 'Excavation and backfill', 2400, 'cu.m', 270.83],
                ['03-100', 'materials', 'Formworks materials', 1, 'lot', 1900000], ['03-100', 'labor', 'Formworks carpentry', 1, 'lot', 980000],
                ['03-200', 'materials', 'Reinforcing steel', 95, 'ton', 48421.05], ['03-200', 'labor', 'Rebar installation', 95, 'ton', 5473.68],
                ['03-300', 'materials', 'Ready-mix concrete', 1160, 'cu.m', 5344.83], ['03-300', 'labor', 'Concrete placing', 1160, 'cu.m', 362.07],
                ['04-100', 'materials', 'CHB walls and plastering materials', 3600, 'sq.m', 513.89], ['04-100', 'labor', 'Masonry labor', 3600, 'sq.m', 211.11],
                ['05-100', 'materials', 'Long-span steel roof trusses', 86, 'ton', 50000], ['05-100', 'labor', 'Truss fabrication and erection', 86, 'ton', 7558.14],
                ['05-200', 'materials', 'Pre-painted roofing and accessories', 3100, 'sq.m', 629.03], ['06-100', 'materials', 'Doors and windows', 1, 'lot', 1600000],
                ['06-300', 'subcontract', 'Painting works (subcontract)', 1, 'lot', 1350000], ['06-400', 'materials', 'Sports flooring and tiles', 1900, 'sq.m', 1105.26],
                ['07-100', 'subcontract', 'Plumbing and sanitary (subcontract)', 1, 'lot', 1150000], ['08-100', 'subcontract', 'Electrical works (subcontract)', 1, 'lot', 1480000],
            ],
            'boq' => [
                ['A.1', 'General requirements', 'lot', 1, ['01-100', '01-200', '01-300', '01-400', '01-500']], ['B.1', 'Excavation and backfill', 'cu.m', 2400, ['02-200']],
                ['C.1', 'Formworks', 'lot', 1, ['03-100']], ['C.2', 'Reinforcing steel', 'ton', 95, ['03-200']], ['C.3', 'Structural concrete', 'cu.m', 1160, ['03-300']],
                ['D.1', 'CHB walls and plastering', 'sq.m', 3600, ['04-100']], ['E.1', 'Steel roof trusses', 'ton', 86, ['05-100']], ['E.2', 'Roofing', 'sq.m', 3100, ['05-200']],
                ['F.1', 'Doors and windows', 'lot', 1, ['06-100']], ['F.2', 'Painting', 'lot', 1, ['06-300']], ['F.3', 'Sports flooring and tiles', 'sq.m', 1900, ['06-400']],
                ['G.1', 'Plumbing and sanitary', 'lot', 1, ['07-100']], ['G.2', 'Electrical works', 'lot', 1, ['08-100']],
            ],
            'acts' => [
                ['1', 'General requirements', null, 0, 320, 0], ['1.1', 'Mobilization and temporary facilities', '01-200', 0, 15, 2, 0, '', 400, ''],
                ['1.2', 'Testing, commissioning and turnover', '01-700', 300, 320, 2, 0, '', 300, '7.2'],
                ['2', 'Earthworks', null, 10, 35, 0], ['2.1', 'Excavation and backfill', '02-200', 10, 35, 3, 2400, 'cu.m', 900, '1.1SS+10'],
                ['3', 'Concrete works', null, 25, 200, 0], ['3.1', 'Footings, columns and beams', '03-300', 30, 120, 14, 620, 'cu.m', 4200, '2.1SS+20'],
                ['3.2', 'Formworks', '03-100', 30, 200, 6, 0, '', 3800, '3.1SS'], ['3.3', 'Reinforcing steel', '03-200', 25, 190, 10, 95, 'ton', 3400, '2.1SS+15'],
                ['3.4', 'Slab on grade and bleachers', '03-300', 120, 200, 10, 540, 'cu.m', 3100, '3.1'],
                ['4', 'Masonry', null, 150, 240, 0], ['4.1', 'CHB walls and plastering', '04-100', 150, 240, 8, 3600, 'sq.m', 4100, '3.1+30'],
                ['5', 'Roof', null, 160, 260, 0], ['5.1', 'Long-span steel roof trusses', '05-100', 160, 230, 12, 86, 'ton', 2900, '3.1+40'],
                ['5.2', 'Roofing sheets and accessories', '05-200', 220, 260, 6, 3100, 'sq.m', 1200, '5.1FF+30'],
                ['6', 'Finishes', null, 240, 315, 0], ['6.1', 'Doors and windows', '06-100', 240, 290, 5, 0, '', 800, '4.1FF+50'],
                ['6.2', 'Painting', '06-300', 250, 310, 5, 0, '', 0, '4.1'], ['6.3', 'Sports flooring and tiles', '06-400', 260, 315, 6, 1900, 'sq.m', 1300, '3.4'],
                ['7', 'Mechanical, electrical and plumbing', null, 90, 305, 0], ['7.1', 'Plumbing and sanitary', '07-100', 100, 300, 4, 0, '', 0, '3.1SS+70'],
                ['7.2', 'Electrical works', '08-100', 90, 305, 5, 0, '', 0, '3.1SS+60'],
                ['8', 'Substantial completion', null, 318, 318, 0, 0, '', 0, '1.2FF-2', true],
            ],
        ],
        'tower' => [
            'name' => 'Northgate Residences Tower 2', 'customer' => 'northgate', 'pm' => 'pm', 'engineer' => 'siteeng', 'qs' => 'qs', 'division' => 'Buildings',
            'location' => 'Northgate Cyberzone, Filinvest City, Alabang, Muntinlupa', 'floor_area' => 14200, 'start' => 185, 'days' => 545, 'signed' => 200, 'contract' => 128500000,
            'type' => 'lump_sum', 'advance' => 15, 'recoup' => 15, 'retention' => 10, 'ld' => 128500, 'client_ref' => 'NLDC-NR2-GC-001', 'lag' => 8,
            'scope' => 'General construction of a 14-storey residential tower with one basement level: structure, masonry, roofing, architectural finishes and MEPF through nominated subcontractors.',
            'estimate' => [
                ['01-100', 'site_prep', 'Mobilization and demobilization', 1, 'lot', 1450000], ['01-200', 'temporary', 'Site office, bunkhouse, fencing and temporary utilities', 1, 'lot', 1820000],
                ['01-300', 'site_admin', 'Project team and site supervision', 18, 'mo', 235000], ['01-400', 'permits', 'Building, fencing and excavation permits', 1, 'lot', 880000],
                ['01-500', 'overhead', 'Contractor\'s all-risk insurance and bonds', 1, 'lot', 1050000], ['01-600', 'overhead', 'Safety program, PPE and signages', 18, 'mo', 45000],
                ['01-700', 'overhead', 'Material testing and inspection', 1, 'lot', 620000],
                ['02-200', 'equipment', 'Excavation, shoring and hauling', 4800, 'cu.m', 520], ['02-200', 'labor', 'Excavation crew', 4800, 'cu.m', 95],
                ['02-300', 'equipment', 'Backfill and compaction', 1900, 'cu.m', 380], ['02-300', 'materials', 'Selected backfill', 1900, 'cu.m', 250],
                ['03-100', 'materials', 'Plywood, lumber and formwork accessories', 1, 'lot', 4100000], ['03-100', 'labor', 'Formworks carpentry', 1, 'lot', 2350000],
                ['03-200', 'materials', 'Reinforcing steel bars, Grade 60', 260, 'ton', 52000], ['03-200', 'labor', 'Rebar cutting, bending and installation', 260, 'ton', 4800],
                ['03-300', 'materials', 'Ready-mix concrete 4,000 psi, pumped', 3700, 'cu.m', 5200], ['03-300', 'labor', 'Concrete placing and curing', 3700, 'cu.m', 260],
                ['03-300', 'equipment', 'Concrete pump and vibrators', 3700, 'cu.m', 180],
                ['04-100', 'materials', 'CHB, cement and sand for walls and plastering', 9800, 'sq.m', 520], ['04-100', 'labor', 'Masonry labor', 9800, 'sq.m', 210],
                ['05-200', 'materials', 'Roof framing and pre-painted roofing', 1, 'lot', 3150000], ['06-100', 'materials', 'Aluminum windows and doors', 1, 'lot', 5900000],
                ['06-200', 'materials', 'Ceiling framing and boards', 1, 'lot', 3450000], ['06-300', 'subcontract', 'Painting works (subcontract)', 1, 'lot', 3780000],
                ['06-400', 'materials', 'Floor and wall tiles', 1, 'lot', 5480000], ['07-100', 'subcontract', 'Plumbing and sanitary works (subcontract)', 1, 'lot', 5620000],
                ['08-100', 'subcontract', 'Electrical works (subcontract)', 1, 'lot', 7380000], ['09-100', 'subcontract', 'Air-conditioning and ventilation (subcontract)', 1, 'lot', 4760000],
            ],
            'boq' => [
                ['A.1', 'General requirements', 'lot', 1, ['01-100', '01-200', '01-300', '01-400', '01-500', '01-600', '01-700']],
                ['B.1', 'Excavation and shoring', 'cu.m', 4800, ['02-200']], ['B.2', 'Backfill and compaction', 'cu.m', 1900, ['02-300']],
                ['C.1', 'Formworks', 'lot', 1, ['03-100']], ['C.2', 'Reinforcing steel', 'ton', 260, ['03-200']], ['C.3', 'Structural concrete', 'cu.m', 3700, ['03-300']],
                ['D.1', 'CHB walls and plastering', 'sq.m', 9800, ['04-100']], ['E.1', 'Roof framing and roofing', 'lot', 1, ['05-200']],
                ['F.1', 'Doors and windows', 'lot', 1, ['06-100']], ['F.2', 'Ceiling works', 'lot', 1, ['06-200']], ['F.3', 'Painting', 'lot', 1, ['06-300']],
                ['F.4', 'Floor and wall tiles', 'lot', 1, ['06-400']], ['G.1', 'Plumbing and sanitary', 'lot', 1, ['07-100']], ['G.2', 'Electrical works', 'lot', 1, ['08-100']],
                ['G.3', 'Air-conditioning and ventilation', 'lot', 1, ['09-100']],
            ],
            'acts' => [
                ['1', 'General requirements', null, 0, 545, 0], ['1.1', 'Mobilization and temporary facilities', '01-200', 0, 20, 2, 0, '', 600, ''],
                ['1.2', 'Permits and clearances', '01-400', 0, 15, 1, 0, '', 120, ''], ['1.3', 'Testing, commissioning and turnover', '01-700', 505, 545, 2, 0, '', 400, '8.1SS-10'],
                ['2', 'Substructure', null, 12, 120, 0], ['2.1', 'Excavation and shoring', '02-200', 12, 55, 4, 4800, 'cu.m', 1500, '1.1SS+12'],
                ['2.2', 'Foundation rebar', '03-200', 40, 95, 6, 95, 'ton', 3400, '2.1SS+28'], ['2.3', 'Foundation concrete', '03-300', 55, 105, 6, 1250, 'cu.m', 2800, '2.2SS+15'],
                ['2.4', 'Backfill and compaction', '02-300', 95, 120, 2, 1900, 'cu.m', 700, '2.3FF-10'],
                ['3', 'Superstructure', null, 95, 260, 0], ['3.1', 'Columns, beams and shear walls', '03-300', 100, 240, 12, 980, 'cu.m', 5200, '2.3FF-5'],
                ['3.2', 'Suspended slabs, 2F to roof deck', '03-300', 120, 260, 12, 1470, 'cu.m', 5600, '3.1SS+20'],
                ['3.3', 'Formworks, superstructure', '03-100', 100, 260, 6, 0, '', 6800, '3.1SS'], ['3.4', 'Reinforcing steel, superstructure', '03-200', 95, 250, 8, 165, 'ton', 5900, '2.2'],
                ['4', 'Masonry', null, 200, 340, 0], ['4.1', 'CHB walls and plastering', '04-100', 200, 340, 8, 9800, 'sq.m', 9800, '3.1SS+100'],
                ['5', 'Mechanical, electrical and plumbing', null, 95, 500, 0], ['5.1', 'Electrical roughing-in and wiring', '08-100', 95, 420, 6, 0, '', 0, '3.1SS-5'],
                ['5.2', 'Plumbing and sanitary', '07-100', 145, 440, 5, 0, '', 0, '3.1SS+45'], ['5.3', 'Air-conditioning and ventilation', '09-100', 380, 500, 4, 0, '', 0, '4.1+40'],
                ['6', 'Roofing', null, 260, 320, 0], ['6.1', 'Roof framing and roofing', '05-200', 260, 320, 3, 0, '', 1600, '3.2'],
                ['7', 'Architectural finishes', null, 330, 520, 0], ['7.1', 'Ceiling works', '06-200', 330, 430, 4, 0, '', 2400, '4.1SS+130'],
                ['7.2', 'Floor and wall tiles', '06-400', 340, 460, 5, 0, '', 3600, '4.1SS+140'], ['7.3', 'Doors and windows', '06-100', 360, 470, 5, 0, '', 1800, '4.1SS+160'],
                ['7.4', 'Painting', '06-300', 400, 520, 4, 0, '', 0, '7.1SS+70'],
                ['8', 'Completion', null, 515, 540, 0], ['8.1', 'Punch list and clean-up', '01-100', 515, 540, 1, 0, '', 900, '7.4FF-5'],
                ['9', 'Topping-off', null, 260, 260, 0, 0, '', 0, '3.2', true], ['10', 'Turnover to the client', null, 545, 545, 0, 0, '', 0, '1.3', true],
            ],
        ],
        'road' => [
            'name' => 'C-5 Service Road Widening, Taguig Section', 'customer' => 'dpwh', 'pm' => 'pm', 'engineer' => 'siteeng', 'qs' => 'qs', 'division' => 'Civil works',
            'location' => 'C-5 Service Road, Brgy. Ususan to Brgy. Napindan, Taguig City', 'floor_area' => 0, 'start' => 140, 'days' => 180, 'signed' => 152, 'contract' => 44600000,
            'type' => 'unit_price', 'advance' => 15, 'recoup' => 15, 'retention' => 10, 'ld' => 44600, 'client_ref' => 'DPWH-MM1-26-C5-014', 'lag' => 0,
            'scope' => 'Widening of 1.3 km of the C-5 service road to four lanes: removal of old pavement, earthworks, aggregate base course, 280 mm PCCP, and 910 mm RCPC drainage.',
            'estimate' => [
                ['01-100', 'site_prep', 'Mobilization and demobilization', 1, 'lot', 650000], ['01-300', 'site_admin', 'Project engineer, foreman and field office', 7, 'mo', 145000],
                ['01-600', 'overhead', 'Construction safety, traffic management and signages', 1, 'lot', 520000], ['01-700', 'overhead', 'Material testing (DPWH quality assurance)', 1, 'lot', 380000],
                ['02-100', 'equipment', 'Removal of existing pavement and structures', 1, 'lot', 820000],
                ['02-200', 'equipment', 'Roadway excavation and hauling', 6500, 'cu.m', 310], ['02-200', 'labor', 'Excavation crew', 6500, 'cu.m', 45],
                ['02-300', 'equipment', 'Embankment placing and compaction', 4200, 'cu.m', 480], ['02-300', 'materials', 'Borrow materials', 4200, 'cu.m', 350],
                ['10-100', 'materials', 'Aggregate base course', 2900, 'cu.m', 1150], ['10-100', 'equipment', 'Spreading and compaction', 2900, 'cu.m', 160],
                ['10-200', 'materials', 'PCCP materials (cement, aggregates, dowels)', 10400, 'sq.m', 1480], ['10-200', 'labor', 'Paving crew', 10400, 'sq.m', 150],
                ['10-200', 'equipment', 'Batching and paving equipment', 10400, 'sq.m', 120],
                ['10-300', 'materials', 'RCPC 910 mm pipes and bedding', 860, 'l.m', 5800], ['10-300', 'labor', 'Pipe laying crew', 860, 'l.m', 450],
            ],
            'boq' => [
                ['B.5', 'Project billboard, mobilization and demobilization', 'lot', 1, ['01-100', '01-300', '01-700']], ['B.7', 'Construction safety and health', 'lot', 1, ['01-600']],
                ['101(3)', 'Removal of existing PCCP and structures', 'lot', 1, ['02-100']], ['102(2)', 'Roadway excavation, unsuitable materials', 'cu.m', 6500, ['02-200']],
                ['104(1)a', 'Embankment from borrow', 'cu.m', 4200, ['02-300']], ['201(1)', 'Aggregate base course', 'cu.m', 2900, ['10-100']],
                ['311(1)e2', 'PCCP, 280 mm thick, 14 days', 'sq.m', 10400, ['10-200']], ['500(1)a10', 'RCPC 910 mm, Class II', 'l.m', 860, ['10-300']],
            ],
            'acts' => [
                ['1', 'General', null, 0, 180, 0], ['1.1', 'Mobilization', '01-100', 0, 10, 2, 0, '', 300, ''],
                ['1.2', 'Traffic management and safety', '01-600', 0, 180, 2, 0, '', 0, ''], ['1.3', 'Testing and final inspection', '01-700', 165, 180, 3, 0, '', 200, '3.2FF+5'],
                ['2', 'Earthworks', null, 5, 85, 0], ['2.1', 'Removal of existing pavement and structures', '02-100', 5, 25, 4, 0, '', 700, '1.1SS+5'],
                ['2.2', 'Roadway excavation', '02-200', 15, 60, 10, 6500, 'cu.m', 1800, '2.1SS+10'], ['2.3', 'Embankment', '02-300', 40, 85, 9, 4200, 'cu.m', 1300, '2.2SS+25'],
                ['3', 'Pavement and drainage', null, 30, 170, 0], ['3.1', 'Aggregate base course', '10-100', 70, 120, 12, 2900, 'cu.m', 1500, '2.3SS+30'],
                ['3.2', 'Concrete pavement, 280 mm', '10-200', 95, 170, 44, 10400, 'sq.m', 6200, '3.1SS+25'], ['3.3', 'RCPC drainage, 910 mm', '10-300', 30, 110, 12, 860, 'l.m', 2600, '2.2SS+15'],
                ['4', 'Substantial completion', null, 175, 175, 0, 0, '', 0, '1.3FF-5', true],
            ],
        ],
        'whse' => [
            'name' => 'Pacific Agri-Foods Warehouse Expansion', 'customer' => 'pacific', 'pm' => 'pm2', 'engineer' => 'siteeng', 'qs' => 'qs', 'division' => 'Industrial',
            'location' => 'Light Industry and Science Park, Calamba, Laguna', 'floor_area' => 4200, 'start' => -25, 'days' => 240, 'signed' => 12, 'contract' => 31800000,
            'type' => 'lump_sum', 'advance' => 15, 'recoup' => 15, 'retention' => 10, 'ld' => 31800, 'client_ref' => 'PAFC-WH3-2026', 'lag' => 0,
            'scope' => 'Pre-engineered steel warehouse of 4,200 sq.m with loading docks, slab on grade, and electrical and fire protection works.',
            'estimate' => [
                ['01-100', 'site_prep', 'Mobilization', 1, 'lot', 450000], ['01-300', 'site_admin', 'Site supervision', 8, 'mo', 110000],
                ['02-200', 'equipment', 'Site grading and excavation', 3500, 'cu.m', 290], ['03-300', 'materials', 'Slab on grade and footings concrete', 1450, 'cu.m', 5300],
                ['03-200', 'materials', 'Reinforcing steel', 72, 'ton', 52000], ['05-100', 'subcontract', 'Pre-engineered steel building (supply and erect)', 1, 'lot', 9800000],
                ['04-100', 'materials', 'Firewalls and plastering', 1600, 'sq.m', 540], ['08-100', 'subcontract', 'Electrical and lighting (subcontract)', 1, 'lot', 2150000],
                ['07-100', 'subcontract', 'Fire protection and plumbing (subcontract)', 1, 'lot', 1650000],
            ],
            'boq' => [], 'acts' => [],
        ],
    ];
}

function dm_cc(string $code): int
{
    static $c = [];
    return $c[$code] ??= demo_id('cs_cost_codes', 'code', $code);
}

function dm_projects(): void
{
    $S = &dm();
    $plans = dm_plan();
    $S['plan'] = $plans;
    $vat = $S['vat'];
    $wt = fn(string $c) => demo_id('withholding_taxes', 'code', $c);
    foreach (['gym', 'tower', 'road', 'whse'] as $k) {
        $pl = $plans[$k];
        $start = dm_d($pl['start']);
        $end = date('Y-m-d', strtotime($start . ' +' . $pl['days'] . ' days'));
        $signed = dm_d($pl['signed']);
        $custId = $S['cust'][$pl['customer']];

        // ---- the contract, approved by the project director and the general manager
        dm_as('opsmgr');
        $kid = CsContracts::save([
            'customer_id' => $custId, 'title' => $pl['name'], 'client_ref_no' => $pl['client_ref'], 'scope' => $pl['scope'], 'contract_type' => $pl['type'],
            'original_amount' => $pl['contract'], 'tax_code_id' => $vat, 'wht_ids' => $pl['customer'] === 'dpwh' ? [$wt('WT-CONTRACTOR'), $wt('WT-VAT')] : [$wt('WT-CONTRACTOR')],
            'terms_id' => demo_id('terms', 'name', $pl['customer'] === 'dpwh' ? 'Net 45' : 'Net 30'),
            'payment_terms' => 'Monthly progress billing on the accomplishment certified by the client\'s engineer; payment within ' . ($pl['customer'] === 'dpwh' ? '45' : '30') . ' days of certification.',
            'advance_pct' => $pl['advance'], 'recoupment_pct' => $pl['recoup'], 'retention_pct' => $pl['retention'], 'retention_cap_pct' => '',
            'retention_release_terms' => 'Released after the one-year defects liability period, against a warranty bond for the same amount.',
            'ld_per_day' => $pl['ld'], 'ld_cap_pct' => 10, 'penalty_terms' => 'Liquidated damages of 1/10 of 1% of the contract price per calendar day of delay, up to 10%.',
            'warranty_months' => 12, 'dlp_months' => 12, 'signing_date' => $signed, 'effective_date' => $signed, 'ntp_date' => dm_d($pl['start'] + 5),
            'commencement_date' => $start, 'original_completion_date' => $end,
        ], [
            ['kind' => 'performance_bond', 'reference_no' => 'PB-' . substr($pl['client_ref'], -6), 'issuer' => 'InsurePro Surety & Insurance Co.', 'amount' => r2($pl['contract'] * 0.1),
                'issue_date' => $signed, 'expiry_date' => date('Y-m-d', strtotime($end . ' +365 days')), 'status' => 'active'],
            ['kind' => 'advance_bond', 'reference_no' => 'AB-' . substr($pl['client_ref'], -6), 'issuer' => 'InsurePro Surety & Insurance Co.', 'amount' => r2($pl['contract'] * $pl['advance'] / 100),
                'issue_date' => $signed, 'expiry_date' => $end, 'status' => 'active'],
        ]);
        $kno = (string)DB::value('SELECT contract_no FROM cs_contracts WHERE id = :k', ['k' => $kid]);
        CsApproval::submit('contract', $kid, $kno, null, (float)$pl['contract']);
        if ($k === 'whse') {
            dm_approve('contract', $kid, dm_d(2), 1, 'Scope and price checked against the tender.');
        } else {
            dm_approve('contract', $kid, $signed);
        }
        $S['contract'][$k] = $kid;

        // ---- the project, with its accounting class and stockyard
        $pid = CsProject::create(['name' => $pl['name'], 'description' => $pl['scope'], 'location' => $pl['location'], 'contract_id' => $kid, 'customer_id' => $custId,
            'pm_user_id' => dm_user($pl['pm']), 'engineer_user_id' => dm_user($pl['engineer']), 'qs_user_id' => dm_user($pl['qs']), 'division' => $pl['division'],
            'status' => $k === 'whse' ? 'planning' : 'mobilization', 'contract_value' => $pl['contract'], 'start_date' => $start, 'target_end_date' => $end,
            'floor_area' => $pl['floor_area'], 'progress_source' => 'schedule'], true);
        foreach (['foreman' => 'Site supervisor / foreman', 'safety' => 'Safety officer', 'qa' => 'QA/QC inspector', 'client' => 'Client representative'] as $u => $role) {
            if ($u === 'client' && $k !== 'tower') {
                continue;
            }
            if (in_array($k, ['tower', 'road', 'gym'], true) || $u !== 'foreman') {
                DB::run('INSERT IGNORE INTO cs_project_members (project_id, user_id, role_label) VALUES (:p, :u, :r)', ['p' => $pid, 'u' => dm_user($u), 'r' => $role]);
            }
        }
        $S['proj'][$k] = $pid;
        $S['start'][$k] = $start;
        $S['end'][$k] = $end;

        // ---- estimate (QS), approved by the project director, then the budget
        dm_as('qs');
        $lines = [];
        foreach ($pl['estimate'] as [$cc, $cat, $desc, $q, $unit, $uc]) {
            $lines[] = ['category' => $cat, 'cost_code_id' => dm_cc($cc), 'description' => $desc, 'qty' => $q, 'unit' => $unit, 'unit_cost' => $uc];
        }
        $cont = $k === 'road' ? 2 : 3;
        $sumCost = array_sum(array_map(fn($l) => r2($l['qty'] * $l['unit_cost']), $lines));
        $markup = round(($pl['contract'] / ($sumCost * (1 + $cont / 100)) - 1) * 100, 2);
        $eid = CsPlanning::saveEstimate(['project_id' => $pid, 'title' => 'Cost estimate, ' . $pl['name'], 'markup_pct' => $markup, 'contingency_pct' => $cont,
            'notes' => 'Prices as of ' . fdate(dm_d($pl['signed'] + 20)) . '; materials delivered to site.'], $lines);
        $e = DB::row('SELECT * FROM cs_estimates WHERE id = :id', ['id' => $eid]);
        CsApproval::submit('estimate', $eid, $e['estimate_no'], $pid, (float)$e['total_price']);
        dm_approve('estimate', $eid, dm_d($pl['signed'] + 15));
        dm_as('qs');
        $bid = CsPlanning::budgetFromEstimate($eid);
        $S['estimate'][$k] = $eid;
        if ($k !== 'whse') {
            $b = DB::row('SELECT * FROM cs_budgets WHERE id = :id', ['id' => $bid]);
            CsApproval::submit('budget', $bid, CsProject::get($pid)['code'] . ' v' . $b['version_no'], $pid, (float)$b['total_amount']);
            dm_approve('budget', $bid, dm_d($pl['start'] + 3));
        }

        // ---- schedule of values (BOQ), priced so it adds up to the contract
        if ($pl['boq']) {
            $costBy = [];
            foreach ($lines as $l) {
                $code = DB::value('SELECT code FROM cs_cost_codes WHERE id = :c', ['c' => $l['cost_code_id']]);
                $costBy[$code] = ($costBy[$code] ?? 0) + r2($l['qty'] * $l['unit_cost']);
            }
            $f = $pl['contract'] / array_sum($costBy);
            $boq = [];
            $sum = 0.0;
            foreach ($pl['boq'] as [$no, $desc, $unit, $qty, $codes]) {
                $price = array_sum(array_map(fn($c) => $costBy[$c] ?? 0, $codes)) * $f;
                $rate = round($price / $qty, 2);
                $boq[] = ['item_no' => $no, 'description' => $desc, 'unit' => $unit, 'qty' => $qty, 'unit_rate' => $rate, 'cost_code_id' => dm_cc($codes[count($codes) - 1])];
                $sum += r2($qty * $rate);
            }
            $boq[0]['cost_code_id'] = dm_cc($pl['boq'][0][4][0]);
            $boq[0]['unit_rate'] = r2($boq[0]['unit_rate'] + ($pl['contract'] - $sum) / $boq[0]['qty']);
            CsPlanning::saveBoq($pid, $boq);
        }

        // ---- schedule with its logic, baselined
        if ($pl['acts']) {
            dm_as($pl['pm']);
            $acts = [];
            foreach ($pl['acts'] as $a) {
                [$wbs, $name, $cc, $s0, $f0, $w] = $a;
                $acts[] = ['wbs_code' => $wbs, 'name' => $name, 'cost_code_id' => $cc ? dm_cc($cc) : null, 'planned_start' => date('Y-m-d', strtotime($start . " +$s0 days")),
                    'planned_finish' => date('Y-m-d', strtotime($start . " +$f0 days")), 'weight_pct' => $w, 'planned_qty' => $a[6] ?? 0, 'unit' => $a[7] ?? '', 'budget_hours' => $a[8] ?? 0,
                    'predecessors' => $a[9] ?? '', 'is_milestone' => !empty($a[10])];
            }
            CsPlanning::saveActivities($pid, $acts);
            CsPlanning::computeCpm($pid);
            CsPlanning::setBaseline($pid);
            foreach (DB::all('SELECT wbs_code, planned_start, planned_finish FROM cs_activities WHERE project_id = :p', ['p' => $pid]) as $r) {
                $S['dates'][$k][$r['wbs_code']] = [$r['planned_start'], $r['planned_finish']];
            }
            DB::run("UPDATE cs_activities SET status = 'not_started' WHERE project_id = :p", ['p' => $pid]);
            $S['acts'][$k] = DB::pairs('SELECT wbs_code, id FROM cs_activities WHERE project_id = :p', ['p' => $pid]);
            // a BOQ line carried by one activity of the schedule is linked to it (billing is checked against its progress)
            foreach ($pl['boq'] as [$no, , , , $codes]) {
                $leaf = count($codes) === 1 ? DB::column('SELECT id FROM cs_activities WHERE project_id = :p AND cost_code_id = :c AND is_milestone = 0', ['p' => $pid, 'c' => dm_cc($codes[0])]) : [];
                if (count($leaf) === 1) {
                    DB::run('UPDATE cs_boq_items SET activity_id = :a WHERE project_id = :p AND item_no = :n', ['a' => $leaf[0], 'p' => $pid, 'n' => $no]);
                }
            }
        }
    }

    // ---- milestones with their deliverables (the evidence each needs before it is accepted)
    $evidence = [['Accomplishment report signed by the site engineer', 'accomplishment_report', 1], ['Progress photographs', 'progress_photos', 1],
        ['Inspection report', 'inspection_report', 1], ['Test results', 'test_results', 1], ['Engineer certification', 'engineer_certification', 1]];
    $items = fn(array $rows) => array_map(fn($r) => ['description' => $r[0], 'doc_type' => $r[1], 'is_required' => $r[2]], $rows);
    dm_as('pm');
    foreach ([
        ['tower', 'MS-01', 'Substructure complete', 120, 'Excavation, foundation and basement slab completed and backfilled', '2.4'],
        ['tower', 'MS-02', 'Topping-off', 260, 'Roof deck slab poured', '9'],
        ['tower', 'MS-03', 'Turnover to the client', 545, 'Building completed, tested and turned over', '10'],
        ['road', 'MS-01', 'Drainage works complete', 110, 'RCPC 910 mm lines laid, backfilled and tested', '3.3'],
        ['road', 'MS-02', 'Substantial completion', 175, 'Pavement, drainage and appurtenances completed', '4'],
        ['gym', 'MS-01', 'Structural completion', 230, 'Columns, beams, slab on grade and roof trusses completed', '5.1'],
        ['gym', 'MS-02', 'Substantial completion', 318, 'Building completed and ready for use', '8'],
        ['gym', 'MS-03', 'Final acceptance', 335, 'Punch list cleared and final acceptance signed by the owner', '8'],
    ] as [$k, $code, $name, $off, $scope, $act]) {
        dm_as($S['plan'][$k]['pm']);
        $S['ms'][$k][$code] = CsPlanning::saveMilestone(['project_id' => $S['proj'][$k], 'code' => $code, 'name' => $name, 'scope' => $scope,
            'scheduled_date' => date('Y-m-d', strtotime($S['start'][$k] . " +$off days")), 'measurement_method' => 'completion', 'responsible_user_id' => dm_user('siteeng'),
            'reviewer_user_id' => dm_user($S['plan'][$k]['pm']), 'client_cert_required' => 1, 'activity_id' => $S['acts'][$k][$act] ?? null,
            'required_inspections' => 'Joint inspection with the client\'s engineer'], $items($code === 'MS-03' && $k === 'gym'
                ? [['Punch-list clearance', 'punch_clearance', 1], ['As-built drawings', 'as_built', 1], ['Signed acceptance', 'signed_acceptance', 1]] : $evidence));
    }

    // ---- opportunities and tenders
    dm_as('opsmgr');
    foreach ([
        ['Pacific Agri-Foods Warehouse Expansion', 'pacific', null, 'Invitation to bid', 'Calamba, Laguna', 31800000, 26900000, 100, 40, 'won', 'bid', $S['proj']['whse']],
        ['Harbor Point Mid-rise Office Fit-out', 'harbor', null, 'Referral', 'Pasay City', 18500000, 15600000, 55, -12, 'bidding', 'bid', null],
        ['Barangay Napindan Health Center', null, 'City Government of Taguig', 'PhilGEPS', 'Taguig City', 9800000, 8600000, 0, 60, 'lost', 'bid', null],
        ['Laguna Cold Storage Facility', 'pacific', null, 'Client referral', 'Sta. Rosa, Laguna', 64000000, 55000000, 25, -45, 'lead', 'pending', null],
        ['San Juan Parish Hall Retrofit', null, 'Parish of St. John the Baptist', 'Walk-in', 'San Juan City', 4200000, 3700000, 0, 20, 'no_bid', 'no_bid', null],
    ] as $i => [$name, $ck, $prospect, $src, $loc, $val, $cost, $prob, $due, $st, $dec, $proj]) {
        DB::insert('cs_opportunities', ['opp_no' => Seq::next('cs_opportunity'), 'name' => $name, 'customer_id' => $ck ? $S['cust'][$ck] : null, 'prospect_name' => $prospect,
            'source' => $src, 'location' => $loc, 'est_value' => $val, 'est_cost' => $cost, 'probability_pct' => $prob, 'bid_due_date' => dm_d($due), 'status' => $st, 'bid_decision' => $dec,
            'decision_notes' => $st === 'no_bid' ? 'Too small for our overhead; outside our current workload.' : ($st === 'lost' ? 'Lost to the lowest calculated bid by 4.2%.' : null),
            'bid_security_amount' => $dec === 'bid' ? r2($val * 0.02) : 0, 'bid_security_ref' => $dec === 'bid' ? 'BS-' . (2201 + $i) : null,
            'bid_security_expiry' => $dec === 'bid' ? dm_d($due - 120) : null,
            'checklist' => "[x] Eligibility documents (PhilGEPS, PCAB licence)\n[x] Bid security\n" . ($st === 'lead' ? '[ ]' : '[x]') . " Technical proposal\n" . ($st === 'lead' ? '[ ]' : '[x]') . " Financial proposal and BOQ",
            'owner_user_id' => dm_user($i % 2 ? 'pm2' : 'opsmgr'), 'project_id' => $proj, 'created_by' => dm_user('opsmgr')]);
    }

    // ---- subcontracts (approved when the work package starts)
    $sc = function (string $k, string $vk, string $pkg, string $cc, string $act, int $startOff, int $endOff, array $items, float $advPct) use (&$S) {
        dm_as('qs');
        $pid = $S['proj'][$k];
        $amount = array_sum(array_column($items, 1));
        $id = CsSub::save(['project_id' => $pid, 'vendor_id' => $S['vend'][$vk], 'work_package' => $pkg, 'scope' => 'Supply of labor, materials, tools and supervision for ' . strtolower($pkg) . ' as per plans and specifications.',
            'cost_code_id' => dm_cc($cc), 'retention_pct' => 10, 'advance_amount' => r2($amount * $advPct / 100), 'recoupment_pct' => $advPct, 'tax_code_id' => $S['vat'],
            'start_date' => date('Y-m-d', strtotime($S['start'][$k] . " +$startOff days")), 'end_date' => date('Y-m-d', strtotime($S['start'][$k] . " +$endOff days")),
            'terms' => 'Monthly progress billing on accomplishment verified by our site engineer; 10% retention released after acceptance.'],
            array_map(fn($it) => ['description' => $it[0], 'qty' => $it[2] ?? 1, 'unit' => $it[3] ?? 'lot', 'unit_rate' => ($it[2] ?? 1) != 1 ? r2($it[1] / $it[2]) : $it[1],
                'cost_code_id' => dm_cc($cc), 'activity_id' => $S['acts'][$k][$act]], $items));
        return $id;
    };
    $S['sc']['gym_elec'] = $sc('gym', 'allied', 'Electrical works, gymnasium', '08-100', '7.2', 90, 305, [['Electrical works complete, as per plans', 1590000]], 0);
    $S['sc']['gym_plumb'] = $sc('gym', 'aquaflow', 'Plumbing and sanitary works, gymnasium', '07-100', '7.1', 100, 300, [['Plumbing and sanitary works complete', 1230000]], 0);
    $S['sc']['gym_paint'] = $sc('gym', 'colorcoat', 'Painting works, gymnasium', '06-300', '6.2', 250, 310, [['Painting works, interior and exterior', 1420000]], 0);
    $S['sc']['tower_elec'] = $sc('tower', 'allied', 'Electrical works, Tower 2', '08-100', '5.1', 95, 420, [
        ['Roughing-in: conduits, boxes and fittings', 3250000], ['Wires and cables', 2180000], ['Panel boards and switchgear', 1420000], ['Lighting fixtures and wiring devices', 1100000]], 10);
    $S['sc']['tower_plumb'] = $sc('tower', 'aquaflow', 'Plumbing and sanitary works, Tower 2', '07-100', '5.2', 145, 440, [
        ['Water supply lines', 2150000], ['Sanitary and storm drainage lines', 2600000], ['Plumbing fixtures', 1300000]], 10);
}


// =====================================================================================================================
// The year, day by day
// =====================================================================================================================

/** Weather of a day in Metro Manila: wet season from June to October */
function dm_weather(string $day): string
{
    $S = &dm();
    if (!isset($S['weather'][$day])) {
        $m = (int)date('n', strtotime($day));
        $r = mt_rand(0, 99);
        $wet = $m >= 6 && $m <= 10;
        $S['weather'][$day] = $wet ? ($r < 9 ? 'heavy_rain' : ($r < 24 ? 'light_rain' : ($r < 55 ? 'cloudy' : 'sunny'))) : ($r < 3 ? 'light_rain' : ($r < 30 ? 'cloudy' : ($r < 85 ? 'sunny' : 'hot')));
    }
    return $S['weather'][$day];
}

/** What each activity of a project accomplishes on each workday: [wbs => [date => qty or percent points]] */
function dm_work_plan(string $k): array
{
    $S = &dm();
    $pl = $S['plan'][$k];
    $out = [];
    $start = $S['start'][$k];
    foreach ($pl['acts'] as $a) {
        [$wbs, , $cc, $s0, $f0, $w] = $a;
        if (!$cc || !empty($a[10])) {
            continue;
        }
        $total = (float)(($a[6] ?? 0) ?: 100);
        [$ps, $pf] = $S['dates'][$k][$wbs];
        $ws = date('Y-m-d', strtotime($ps . ' +' . $pl['lag'] . ' days'));
        $we = date('Y-m-d', strtotime($pf . ' +' . $pl['lag'] . ' days'));
        $n = max(1, count(dm_workdays($ws, $we)));
        $base = $total / $n;
        $left = $total;
        foreach (dm_workdays($ws, date('Y-m-d', strtotime($we . ' +120 days'))) as $day) {
            if ($left <= 0.0001) {
                break;
            }
            $wx = dm_weather($day);
            $f = (0.85 + mt_rand(0, 30) / 100) * ($wx === 'heavy_rain' ? 0.35 : ($wx === 'light_rain' ? 0.8 : 1.0));
            $q = min($left, $base * $f);
            $q = $total >= 100 && ($a[6] ?? 0) > 0 ? round($q, 1) : round($q, 2);
            if ($left - $q < $base * 0.15) {
                $q = $left;
            }
            if ($q > 0) {
                $out[$wbs][$day] = $q;
                $left -= $q;
            }
        }
    }
    return $out;
}

function dm_timeline(): void
{
    $S = &dm();
    $today = date('Y-m-d');
    foreach (['tower', 'road'] as $k) {
        $S['work'][$k] = dm_work_plan($k);
    }
    $S['meter'] = DB::pairs('SELECT id, current_meter FROM cs_equipment');
    // dispatch plan: unit => [[project, from, to or null (still out)]]
    $ts = $S['start']['tower'];
    $rs = $S['start']['road'];
    $plus = fn(string $d, int $n) => date('Y-m-d', strtotime($d . " +$n days"));
    $S['dispatch_plan'] = [
        'exc' => [['tower', $plus($ts, 15), $plus($ts, 70)], ['road', $plus($rs, 30), $plus($rs, 80)]],
        'bhl' => [['road', $rs, null]], 'dt1' => [['road', $plus($rs, 3), null]], 'rol' => [['road', $plus($rs, 38), null]],
        'mix' => [['tower', $plus($ts, 40), null]], 'gen' => [['tower', $ts, null]], 'pump' => [['tower', $plus($ts, 60), null]],
        'crane' => [['tower', $plus($ts, 100), null]], 'wld' => [['tower', $plus($ts, 95), null]],
    ];
    $first = strtotime(dm_d(398));
    for ($t = $first; $t <= strtotime($today); $t += 86400) {
        $day = date('Y-m-d', $t);
        dm_day($day);
    }
}

function dm_day(string $day): void
{
    $S = &dm();
    $dow = (int)date('N', strtotime($day));
    $dom = (int)date('j', strtotime($day));
    $isMonthEnd = $day === date('Y-m-t', strtotime($day));
    $work = in_array($day, dm_workdays($day, $day), true);

    // ---- project starts and status changes
    foreach (['gym', 'tower', 'road'] as $k) {
        if ($day === $S['start'][$k]) {
            dm_as($S['plan'][$k]['pm']);
            CsProject::update($S['proj'][$k], ['status' => 'active', 'actual_start_date' => $day]);
            dm_project_start_costs($k, $day);
        }
    }
    dm_events($day);
    dm_dispatches($day);
    // ---- the site, every working day
    if ($work) {
        foreach (['tower', 'road'] as $k) {
            if ($day >= $S['start'][$k]) {
                dm_site_day($k, $day);
            }
        }
    }
    // ---- materials: requests at the start of the month, deliveries, issues on Saturdays
    if ($work) {
        dm_materials($day);
    }
    if ($dow === 6) {
        foreach (['tower', 'road'] as $k) {
            if ($day >= $S['start'][$k]) {
                dm_weekly_site_costs($k, $day);
            }
        }
        if ($day >= $S['start']['gym'] && $day <= dm_d(40)) {
            dm_gym_progress($day);
        }
    }
    // ---- monthly costs of the head office, the yard and the sites
    if ($dom === 1) {
        dm_monthly_bills($day);
    }
    if ($dom === 20) {
        dm_sub_billings($day);
    }
    if ($dom === 25) {
        dm_progress_billings($day);
    }
    if ($dom === 16 || $dom === 1) {
        dm_payroll($day);
    }
    if ($dow === 5) {
        dm_pay_bills($day);
    }
    dm_collections($day);
    if ($isMonthEnd) {
        dm_month_end($day);
    }
}

// ---------------------------------------------------------------------------------------------------- accounting helpers

/** A supplier bill typed in accounting. Lines: [account code, amount, description, cost code or null, project key or null, vat (bool)] */
function dm_bill(string $vk, string $date, string $ref, array $lines, string $memo = ''): int
{
    $S = &dm();
    $vid = $S['vend'][$vk];
    $terms = DB::value('SELECT terms_id FROM vendors WHERE id = :v', ['v' => $vid]);
    $raw = [];
    $classes = [];
    foreach ($lines as $l) {
        [$ac, $amt, $desc] = $l;
        $cc = $l[3] ?? null;
        $pk = $l[4] ?? null;
        $vat = $l[5] ?? true;
        $cls = $pk ? (int)CsProject::get($S['proj'][$pk])['class_id'] : null;
        $classes[] = $cls;
        $raw[] = ['line_kind' => 'expense', 'account_id' => ($S['acct'])($ac), 'amount' => r2($amt), 'description' => $desc, 'tax_code_id' => $vat ? $S['vat'] : null,
            'tax_amount' => $vat ? r2($amt * 0.12) : 0, 'class_id' => $cls, 'cost_code_id' => $cc ? dm_cc($cc) : null];
    }
    $one = count(array_unique($classes)) === 1 ? $classes[0] : null;
    dm_as('acctstaff');
    return Txn::save('bill', ['txn_date' => $date, 'vendor_id' => $vid, 'ref_no' => $ref, 'terms_id' => $terms ?: null, 'due_date' => Txn::dueDate($date, $terms ? (int)$terms : null),
        'account_id' => Accts::get('ap'), 'class_id' => $one, 'memo' => $memo ?: null], $raw);
}

/** A check (or cash voucher when $voucher) paid out of a cash account. Lines as dm_bill, without VAT unless asked. */
function dm_check(string $date, string $bank, array $lines, string $memo, ?string $vk = null, ?string $payee = null, bool $voucher = false): int
{
    $S = &dm();
    $raw = [];
    $classes = [];
    $tot = 0.0;
    foreach ($lines as $l) {
        [$ac, $amt, $desc] = $l;
        $cc = $l[3] ?? null;
        $pk = $l[4] ?? null;
        $vat = $l[5] ?? false;
        $cls = $pk ? (int)CsProject::get($S['proj'][$pk])['class_id'] : null;
        $classes[] = $cls;
        $tax = $vat ? r2($amt * 0.12) : 0;
        $tot += $amt + $tax;
        $raw[] = $voucher
            ? ['line_kind' => 'journal', 'account_id' => ($S['acct'])($ac), 'amount' => r2($amt + $tax), 'description' => $desc, 'class_id' => $cls, 'cost_code_id' => $cc ? dm_cc($cc) : null]
            : ['line_kind' => 'expense', 'account_id' => ($S['acct'])($ac), 'amount' => r2($amt), 'description' => $desc, 'tax_code_id' => $vat ? $S['vat'] : null, 'tax_amount' => $tax,
                'class_id' => $cls, 'cost_code_id' => $cc ? dm_cc($cc) : null];
    }
    $one = count(array_unique($classes)) === 1 ? $classes[0] : null;
    dm_as('acctstaff');
    $h = ['txn_date' => $date, 'account_id' => ($S['acct'])($bank), 'vendor_id' => $vk ? $S['vend'][$vk] : null, 'payee_name' => $vk ? null : $payee, 'memo' => $memo, 'class_id' => $one];
    if ($voucher) {
        return Txn::save('cash_voucher', $h + ['subtotal' => r2($tot), 'total' => r2($tot), 'status' => 'open'], $raw, null, ['keep_totals' => true]);
    }
    return Txn::save('check', $h, $raw);
}

/** Withholding rows of a payment: [code => base] */
function dm_wht(array $bases, string $side): array
{
    $out = [];
    foreach ($bases as $code => $base) {
        $w = DB::row('SELECT * FROM withholding_taxes WHERE code = :c', ['c' => $code]);
        $out[] = ['wht_id' => (int)$w['id'], 'tax_kind' => $w['tax_kind'], 'atc' => $w['atc'], 'rate' => (float)$w['rate'], 'base' => r2($base),
            'amount' => r2($base * (float)$w['rate'] / 100), 'account_id' => Withholding::account($w, $side)];
    }
    return $out;
}

// ---------------------------------------------------------------------------------------------------- start of a project

function dm_project_start_costs(string $k, string $day): void
{
    $S = &dm();
    $big = ['gym' => 0.45, 'tower' => 1.0, 'road' => 0.42][$k];
    $plus = fn(int $n) => date('Y-m-d', strtotime($day . " +$n days"));
    dm_check($plus(-3), '1010', [['5360', r2(880000 * $big), 'Building and excavation permits, fees and clearances', '01-400', $k]], 'Permits ' . CsProject::get($S['proj'][$k])['code'], null, 'City Engineering Office');
    dm_bill('insurepro', $plus(-2), 'IPS-' . strtoupper($k) . '-' . date('ym', strtotime($day)), [['5375', r2(1050000 * $big), 'Contractor\'s all-risk insurance, performance and advance payment bonds', '01-500', $k]],
        'Insurance and bonds');
    dm_bill('tollway', $plus(1), 'SL-' . (4100 + count($S['proj'])), [['5350', r2(620000 * $big), 'Mobilization: hauling of equipment, tools and site office container', '01-100', $k]], 'Mobilization');
    dm_bill('northline', $plus(4), 'NH-' . (77000 + mt_rand(100, 999)), [['5355', r2(1250000 * $big), 'Temporary facilities: fencing, site office, bunkhouse, toilets', '01-200', $k]], 'Temporary facilities');
}

// ---------------------------------------------------------------------------------------------------- equipment dispatches

function dm_dispatches(string $day): void
{
    $S = &dm();
    foreach ($S['dispatch_plan'] as $unit => $legs) {
        foreach ($legs as $i => [$k, $from, $to]) {
            $eid = $S['equip'][$unit];
            if ($day === $from) {
                dm_as('equipmgr');
                $did = CsEquip::saveDispatch(['equipment_id' => $eid, 'project_id' => $S['proj'][$k], 'date_from' => $from, 'date_to' => $to ?: $S['end'][$k],
                    'purpose' => ucfirst(CsEquip::TYPES[DB::value('SELECT equipment_type FROM cs_equipment WHERE id = :e', ['e' => $eid])] ?? 'Equipment') . ' for ' . CsProject::get($S['proj'][$k])['name']]);
                CsEquip::dispatchStatus($did, 'dispatched', ['meter' => $S['meter'][$eid]]);
                $S['disp'][$unit] = ['id' => $did, 'k' => $k];
            }
            if ($to && $day === $to && ($S['disp'][$unit]['k'] ?? '') === $k) {
                dm_as('equipmgr');
                CsEquip::dispatchStatus($S['disp'][$unit]['id'], 'returned', ['meter' => $S['meter'][$eid], 'date' => $day]);
                unset($S['disp'][$unit]);
            }
        }
    }
}

// ---------------------------------------------------------------------------------------------------- a working day on site

function dm_site_day(string $k, string $day): void
{
    $S = &dm();
    $pid = $S['proj'][$k];
    $acts = $S['acts'][$k];
    $today = [];
    foreach ($S['work'][$k] as $wbs => $days) {
        if (isset($days[$day])) {
            $today[$wbs] = $days[$day];
        }
    }
    $wx = dm_weather($day);
    $ccOf = [];
    foreach ($S['plan'][$k]['acts'] as $a) {
        $ccOf[$a[0]] = $a[2];
    }
    $pour = (bool)array_filter(array_keys($today), fn($w) => in_array($ccOf[$w], ['03-300', '10-200'], true));
    $prefer = ['Carpenter' => ['03-100', '06-200', '06-100'], 'Mason' => ['04-100', '03-300', '10-200', '10-300', '06-400'], 'Steelman' => ['03-200'],
        'Heavy Equipment Operator' => ['02-200', '02-300', '10-100', '02-100'], 'Driver' => ['02-200', '02-300', '10-100', '02-100'], 'Laborer / Helper' => []];

    // ---- attendance (timekeeper), approved by the project manager
    $lines = [];
    $byAct = [];
    $n = 0;
    foreach ($S['crew'][$k] as $wk) {
        $wid = $S['workers'][$wk];
        if (in_array($wk, ['leo', 'carlo'], true) && $day < date('Y-m-d', strtotime($S['start'][$k] . ' +60 days'))) {
            continue;
        }
        $trade = (string)DB::value('SELECT t.name FROM cs_workers w LEFT JOIN cs_trades t ON t.id = w.trade_id WHERE w.id = :w', ['w' => $wid]);
        $r = mt_rand(0, 99);
        if ($r < 2) {
            $lines[] = ['worker_id' => $wid, 'is_absent' => 1, 'remarks' => 'Absent (sick leave)'];
            continue;
        }
        $act = null;
        if ($trade !== 'Foreman') {
            foreach (array_keys($today) as $w) {
                if (in_array($ccOf[$w], $prefer[$trade] ?? [], true)) {
                    $act = $w;
                    break;
                }
            }
            if (!$act && $today) {
                $keys = array_keys($today);
                $act = $keys[$n % count($keys)];
            }
        }
        $n++;
        $in = $r < 6 ? '07:20' : '07:00';
        $out = $pour && in_array($trade, ['Mason', 'Laborer / Helper', 'Carpenter'], true) ? '18:00' : ($wx === 'heavy_rain' && $r > 70 ? '14:00' : '16:00');
        $lines[] = ['worker_id' => $wid, 'time_in' => $in, 'time_out' => $out, 'activity_id' => $act ? $acts[$act] : null,
            'cost_code_id' => $act ? dm_cc($ccOf[$act]) : dm_cc('01-300'), 'remarks' => $in === '07:20' ? 'Late: traffic' : ($out === '18:00' ? 'Overtime: concrete pour' : null)];
        if ($act) {
            $byAct[$act] = ($byAct[$act] ?? 0) + 1;
        }
    }
    dm_as('timekeeper');
    $tsId = CsLabor::saveTs(['project_id' => $pid, 'work_date' => $day, 'shift' => 'day', 'notes' => $wx === 'heavy_rain' ? 'Work stopped for two hours due to heavy rain' : null], $lines);
    $t = CsLabor::getTs($tsId);
    $last = $day === date('Y-m-d');
    CsApproval::submit('timesheet', $tsId, $t['ts_no'] . ' ' . $t['project_code'] . ' ' . fdate($day), $pid, (float)$t['total_cost']);
    if (!$last) {
        dm_approve('timesheet', $tsId, $day);
    }

    // ---- equipment usage (equipment manager), approved by the project manager
    $usedBy = ['exc' => ['02-200', '02-300'], 'bhl' => ['02-100', '02-200', '02-300', '10-300'], 'dt1' => ['02-100', '02-200', '02-300', '10-100'], 'rol' => ['02-300', '10-100'],
        'mix' => ['03-300', '04-100'], 'pump' => ['03-300'], 'gen' => ['*']];
    $fuelPerH = ['exc' => 16, 'bhl' => 8, 'dt1' => 7, 'rol' => 9, 'mix' => 1.5, 'pump' => 11, 'gen' => 9];
    $eqUsed = [];
    foreach ($S['disp'] ?? [] as $unit => $x) {
        if ($x['k'] !== $k || !isset($usedBy[$unit])) {
            continue;
        }
        $eid = $S['equip'][$unit];
        $act = null;
        foreach (array_keys($today) as $w) {
            if ($usedBy[$unit] === ['*'] || in_array($ccOf[$w], $usedBy[$unit], true)) {
                $act = $w;
                break;
            }
        }
        if (!$act && $unit !== 'gen') {
            continue;
        }
        $op = $wx === 'heavy_rain' ? 3.5 : 6 + mt_rand(0, 20) / 10;
        $idle = mt_rand(0, 15) / 10;
        $bd = mt_rand(0, 99) < 2 ? 2.0 : 0.0;
        $isKm = DB::value('SELECT meter_unit FROM cs_equipment WHERE id = :e', ['e' => $eid]) === 'km';
        $ms = (float)$S['meter'][$eid];
        $me = $ms + ($isKm ? round($op * 14, 0) : $op + $idle);
        $S['meter'][$eid] = $me;
        $fuel = round($op * $fuelPerH[$unit], 1);
        dm_as('equipmgr');
        $lid = CsEquip::saveLog(['equipment_id' => $eid, 'project_id' => $pid, 'activity_id' => $act ? $acts[$act] : null, 'cost_code_id' => dm_cc($act ? $ccOf[$act] : '01-200'),
            'log_date' => $day, 'operating_hours' => $op, 'idle_hours' => $idle, 'breakdown_hours' => $bd, 'meter_start' => $ms, 'meter_end' => $me, 'fuel_liters' => $fuel,
            'fuel_cost' => r2($fuel * 62), 'operator_worker_id' => DB::value('SELECT operator_worker_id FROM cs_equipment WHERE id = :e', ['e' => $eid]),
            'remarks' => $bd > 0 ? 'Hydraulic hose replaced on site' : null]);
        $l = CsEquip::getLog($lid);
        CsApproval::submit('equipment_log', $lid, $l['log_no'] . ' ' . $l['code'] . ' ' . fdate($day), $pid, (float)$l['charge_amount']);
        if (!$last) {
            dm_approve('equipment_log', $lid, $day);
        }
        $S['fuel'][substr($day, 0, 7)] = ($S['fuel'][substr($day, 0, 7)] ?? 0) + $fuel;
        $eqUsed[] = $l['code'] . ' ' . $l['equipment_name'];
    }

    // ---- daily site report (foreman), approved by the project manager; its accomplishment updates the schedule
    $dl = [];
    foreach ($today as $w => $q) {
        $wkrs = max(1, $byAct[$w] ?? 0);
        $dl[] = ['activity_id' => $acts[$w], 'qty_done' => $q, 'workers' => $wkrs, 'man_hours' => $wkrs * 8,
            'remarks' => in_array($ccOf[$w], ['08-100', '07-100', '09-100'], true) ? 'By the subcontractor; verified by the site engineer' : null];
    }
    if (!$dl) {
        $dl[] = ['description' => 'Housekeeping, site maintenance and safety inspection', 'workers' => count($lines), 'man_hours' => 8 * count($lines)];
    }
    dm_as('foreman');
    $rid = CsSite::save(['project_id' => $pid, 'report_date' => $day, 'weather_am' => $wx === 'heavy_rain' ? 'light_rain' : $wx, 'weather_pm' => $wx,
        'site_conditions' => in_array($wx, ['heavy_rain', 'light_rain'], true) ? 'Wet, muddy access road' : 'Dry, accessible', 'workers_count' => count(array_filter($lines, fn($x) => empty($x['is_absent']))), 'man_hours' => $t['total_hours'],
        'equipment_used' => implode(', ', $eqUsed) ?: null, 'materials_used' => $pour ? 'Ready-mix concrete delivered and poured' : null,
        'delays' => $wx === 'heavy_rain' ? 'Heavy rain in the afternoon; concreting and excavation stopped' : null, 'delay_hours' => $wx === 'heavy_rain' ? 3 : 0,
        'safety_observations' => mt_rand(0, 9) === 0 ? 'Two workers reminded to wear their safety harness at the slab edge' : 'No incident. Toolbox talk held before work.',
        'instructions' => mt_rand(0, 12) === 0 ? 'Client\'s engineer asked to add cylinder samples for the next pour' : null], $dl);
    $r = CsSite::get($rid);
    CsApproval::submit('daily_report', $rid, $r['report_no'] . ' ' . CsProject::get($pid)['code'] . ' ' . fdate($day), $pid, 0);
    if ($day < dm_d(1)) {
        dm_approve('daily_report', $rid, $day);
    }
    $S['done'][$k][$day] = $today;
}

// ---------------------------------------------------------------------------------------------------- materials

/** Stock materials used per unit of an activity's accomplishment */
function dm_ratios(): array
{
    $rebar = [['RB-16', 42], ['RB-20', 20], ['RB-12', 37], ['RB-10', 27], ['TW-16', 15]];
    return [
        'tower' => ['2.2' => $rebar, '3.4' => $rebar, '3.3' => [['PLY-12', 22], ['CL-2X3', 95]], '4.1' => [['CHB-6', 6.5], ['CHB-4', 6.5], ['CEM-40', 0.5], ['SAND-W', 0.04]],
            '2.3' => [['CEM-40', 0.12]], '3.1' => [['CEM-40', 0.12]], '3.2' => [['CEM-40', 0.12]], '1.1' => [['PPE-HH', 0.6]]],
        'road' => ['3.1' => [['ABC-G1', 1.08]], '3.3' => [['SAND-W', 0.25], ['CEM-40', 0.6]], '3.2' => [['RB-16', 0.12]], '1.1' => [['PPE-HH', 0.4]]],
    ];
}

/** Material requests at the start of each month, canvass, award, purchase order; deliveries billed when they come */
function dm_materials(string $day): void
{
    $S = &dm();
    $firstWorkday = count(dm_workdays(date('Y-m-01', strtotime($day)), $day)) === 1;
    foreach (['tower', 'road'] as $k) {
        if ($day < $S['start'][$k] || ($day !== $S['start'][$k] && !$firstWorkday) || $day > dm_d(3)) {
            continue;
        }
        dm_material_requests($k, $day);
    }
    foreach ($S['deliver'][$day] ?? [] as [$po, $k]) {
        dm_receive($po, $k, $day);
    }
}

function dm_material_requests(string $k, string $day): void
{
    $S = &dm();
    $pid = $S['proj'][$k];
    $p = CsProject::get($pid);
    $site = (int)$p['site_id'];
    $horizon = date('Y-m-d', strtotime($day . ' +40 days'));
    $ccOf = [];
    foreach ($S['plan'][$k]['acts'] as $a) {
        $ccOf[$a[0]] = $a[2];
    }
    $need = [];
    foreach (dm_ratios()[$k] as $wbs => $mix) {
        $q = 0.0;
        foreach ($S['work'][$k][$wbs] ?? [] as $d => $x) {
            if ($d >= $day && $d <= $horizon) {
                $q += $x;
            }
        }
        if ($q <= 0) {
            continue;
        }
        foreach ($mix as [$code, $per]) {
            $need[$code][$ccOf[$wbs]] = ($need[$code][$ccOf[$wbs]] ?? 0) + $q * $per;
        }
    }
    $groups = [];
    foreach ($need as $code => $byCc) {
        $iid = $S['items'][$code];
        $item = DB::row('SELECT * FROM items WHERE id = :i', ['i' => $iid]);
        $onOrder = (float)DB::value("SELECT COALESCE(SUM(l.qty - l.qty_fulfilled),0) FROM transaction_lines l JOIN transactions t ON t.id = l.txn_id
                                      WHERE t.txn_type = 'purchase_order' AND t.is_voided = 0 AND l.item_id = :i AND l.site_id = :s AND l.is_closed = 0", ['i' => $iid, 's' => $site]);
        $have = Stock::onHand($iid, $site) + $onOrder;
        $total = array_sum($byCc) * 1.15;
        $buy = $total - $have;
        if ($buy <= 0.01) {
            continue;
        }
        foreach ($byCc as $cc => $q) {
            $qty = in_array($item['unit'], ['cu.m'], true) ? ceil($buy * $q / array_sum($byCc) * 2) / 2 : ceil($buy * $q / array_sum($byCc));
            if ($qty > 0) {
                $groups[(int)$item['preferred_vendor_id']][] = ['item_id' => $iid, 'qty' => $qty, 'unit' => $item['unit'], 'est_unit_cost' => $item['cost'], 'cost_code_id' => dm_cc($cc),
                    'description' => $item['name']];
            }
        }
    }
    $rivals = [$S['vend']['metrocement'] => ['bulacanagg', 'northline'], $S['vend']['pacsteel'] => ['northline', 'metrocement'], $S['vend']['northline'] => ['metrocement', 'pacsteel'],
        $S['vend']['bulacanagg'] => ['metrocement'], $S['vend']['rapidfuel'] => ['northline']];
    foreach ($groups as $vendorId => $lines) {
        dm_as('siteeng');
        $mr = CsMaterials::saveMr(['project_id' => $pid, 'request_date' => $day, 'needed_date' => date('Y-m-d', strtotime($day . ' +5 days')), 'deliver_to' => 'site',
            'purpose' => 'Materials for the next weeks (' . DB::value('SELECT name FROM vendors WHERE id = :v', ['v' => $vendorId]) . ' items)'], $lines);
        CsMaterials::submitMr($mr);
        dm_approve('material_request', $mr, $day);
        dm_as('procurement');
        $cid = CsMaterials::startCanvass($mr);
        $mrLines = DB::all('SELECT * FROM cs_mr_lines WHERE mr_id = :m ORDER BY line_no', ['m' => $mr]);
        $quoters = array_merge([$vendorId], array_map(fn($x) => $S['vend'][$x], $rivals[$vendorId] ?? []));
        foreach ($quoters as $qi => $v) {
            $prices = [];
            foreach ($mrLines as $l) {
                $cost = (float)DB::value('SELECT cost FROM items WHERE id = :i', ['i' => $l['item_id']]);
                $prices[$l['id']] = round($cost * ($qi === 0 ? 0.97 + mt_rand(0, 4) / 100 : 1.03 + mt_rand(0, 7) / 100), 2);
            }
            CsMaterials::saveQuote($cid, ['vendor_id' => $v, 'quote_ref' => 'Q-' . (5000 + $cid * 3 + $qi), 'quote_date' => $day, 'delivery_days' => 2 + $qi, 'payment_terms' => '30 days',
                'valid_until' => date('Y-m-d', strtotime($day . ' +15 days'))], $prices);
        }
        $cmp = CsMaterials::comparison($cid);
        CsMaterials::award($cid, (int)$cmp['lowest'], count($quoters) < 2 ? 'Sole supplier of this material near the site' : '');
        dm_approve('purchase', $cid, $day);
        $po = (int)DB::value('SELECT po_txn_id FROM cs_canvass WHERE id = :c', ['c' => $cid]);
        DB::run('UPDATE transactions SET txn_date = :d, due_date = :dd WHERE id = :id', ['d' => $day, 'dd' => date('Y-m-d', strtotime($day . ' +3 days')), 'id' => $po]);
        DB::run('UPDATE cs_canvass SET canvass_date = :d WHERE id = :id', ['d' => $day, 'id' => $cid]);
        $wd = dm_workdays(date('Y-m-d', strtotime($day . ' +2 days')), date('Y-m-d', strtotime($day . ' +10 days')));
        if ($vendorId === $S['vend']['pacsteel'] && $day >= dm_d(25)) {
            continue; // the steel supplier is late: this order is still open
        }
        if ($wd[0] <= date('Y-m-d')) {
            $S['deliver'][$wd[0]][] = [$po, $k];
        }
    }
}

/** A delivery: the supplier's invoice billed against the purchase order lines (stock comes into the stockyard) */
function dm_receive(int $po, string $k, string $day): void
{
    $S = &dm();
    $t = Txn::load($po);
    $rows = [];
    foreach (Txn::lines($po) as $l) {
        $rows[] = ['line_kind' => 'item', 'item_id' => $l['item_id'], 'description' => $l['description'], 'qty' => $l['qty'], 'rate' => $l['rate'], 'amount' => r2((float)$l['qty'] * (float)$l['rate']),
            'tax_code_id' => $l['tax_code_id'], 'tax_amount' => r2((float)$l['qty'] * (float)$l['rate'] * 0.12), 'site_id' => $l['site_id'], 'source_line_id' => $l['id'], 'unit' => $l['unit']];
    }
    $terms = DB::value('SELECT terms_id FROM vendors WHERE id = :v', ['v' => $t['vendor_id']]);
    dm_as('warehouse');
    Txn::save('bill', ['txn_date' => $day, 'vendor_id' => (int)$t['vendor_id'], 'site_id' => $t['site_id'], 'class_id' => $t['class_id'], 'ref_no' => 'SI-' . (30000 + $po * 7),
        'terms_id' => $terms ?: null, 'due_date' => Txn::dueDate($day, $terms ? (int)$terms : null), 'account_id' => Accts::get('ap'),
        'memo' => 'Delivered to the stockyard of ' . CsProject::get($S['proj'][$k])['code'] . ' against ' . Txn::ref($t)], $rows);
    // delivered in full: procurement closes the request
    $mr = (int)DB::value('SELECT mr_id FROM cs_canvass WHERE po_txn_id = :p', ['p' => $po]);
    if ($mr) {
        dm_as('procurement');
        DB::update('cs_material_requests', ['status' => 'closed'], 'id = :id', ['id' => $mr]);
        Audit::log('close', 'proc', 'material_request', $mr, (string)DB::value('SELECT mr_no FROM cs_material_requests WHERE id = :m', ['m' => $mr]),
            ['page_key' => 'proc.requests', 'reason' => 'Delivered in full on ' . fdate($day)]);
    }
}

/** Saturday: the week's materials issued to the work, ready-mix and other site deliveries billed */
function dm_weekly_site_costs(string $k, string $day): void
{
    $S = &dm();
    $pid = $S['proj'][$k];
    $p = CsProject::get($pid);
    $site = (int)$p['site_id'];
    $from = date('Y-m-d', strtotime($day . ' -5 days'));
    $ccOf = [];
    foreach ($S['plan'][$k]['acts'] as $a) {
        $ccOf[$a[0]] = $a[2];
    }
    $week = [];
    foreach ($S['done'][$k] ?? [] as $d => $acts) {
        if ($d >= $from && $d <= $day) {
            foreach ($acts as $w => $q) {
                $week[$w] = ($week[$w] ?? 0) + $q;
            }
        }
    }
    if (!$week) {
        return;
    }
    // stock materials issued to the activities
    $lines = [];
    $use = [];
    foreach (dm_ratios()[$k] as $wbs => $mix) {
        if (empty($week[$wbs])) {
            continue;
        }
        foreach ($mix as [$code, $per]) {
            $iid = $S['items'][$code];
            $q = round($week[$wbs] * $per, 2);
            $avail = Stock::onHand($iid, $site) - ($use[$iid] ?? 0);
            $q = min($q, floor($avail * 100) / 100);
            if ($q <= 0.01) {
                continue;
            }
            $use[$iid] = ($use[$iid] ?? 0) + $q;
            $lines[] = ['item_id' => $iid, 'qty' => $q, 'cost_code_id' => dm_cc($ccOf[$wbs]), 'activity_id' => $S['acts'][$k][$wbs]];
        }
    }
    if ($lines) {
        dm_as('warehouse');
        $doc = CsMaterials::saveDoc(['doc_type' => 'issue', 'project_id' => $pid, 'site_id' => $site, 'doc_date' => $day, 'notes' => 'Issued for the work of the week ' . fdate($from) . ' to ' . fdate($day)], $lines);
        CsMaterials::postDoc($doc);
    }
    // deliveries billed by the week: ready-mix concrete, fill and borrow, pipes, hauling
    $direct = [
        'tower' => ['2.3' => ['primemix', 1, 5200, 'Ready-mix concrete 4,000 psi, pumped (cu.m)', '5310'], '3.1' => ['primemix', 1, 5200, 'Ready-mix concrete 4,000 psi, pumped (cu.m)', '5310'],
            '3.2' => ['primemix', 1, 5200, 'Ready-mix concrete 4,000 psi, pumped (cu.m)', '5310'], '2.4' => ['bulacanagg', 1, 255, 'Selected backfill delivered (cu.m)', '5310'],
            '2.1' => ['tollway', 1, 120, 'Hauling and disposal of excavated soil (cu.m)', '5350']],
        'road' => ['3.2' => ['primemix', 0.29, 5150, 'PCCP mix, 650 psi flexural (cu.m)', '5310'], '2.3' => ['bulacanagg', 1, 352, 'Borrow materials for embankment (cu.m)', '5310'],
            '3.3' => ['metrocement', 1, 4950, 'RCPC 910 mm Class II with collars (l.m)', '5310'], '2.1' => ['tollway', 0.01 * 450000, 1, 'Hauling and disposal of removed pavement', '5350'],
            '2.2' => ['tollway', 1, 85, 'Hauling of unsuitable materials (cu.m)', '5350']],
    ][$k];
    $byVendor = [];
    foreach ($direct as $wbs => [$vk, $per, $price, $desc, $acc]) {
        if (empty($week[$wbs])) {
            continue;
        }
        $qty = round($week[$wbs] * $per, 2);
        $byVendor[$vk][] = [$acc, r2($qty * $price), $desc . ': ' . qty($qty) . ' for ' . $wbs, $ccOf[$wbs], $k];
    }
    foreach ($byVendor as $vk => $bl) {
        dm_bill($vk, $day, strtoupper(substr($vk, 0, 3)) . '-' . date('ymd', strtotime($day)) . '-' . $pid, $bl, 'Deliveries of the week, ' . $p['code']);
    }
}

// ---------------------------------------------------------------------------------------------------- the gymnasium (progress kept on the schedule)

/** Planned fraction of an activity done by a date, the work running $lag days behind the baseline */
function dm_gym_frac(array $a, string $date): float
{
    $S = &dm();
    $lag = $S['plan']['gym']['lag'];
    [$ps, $pf] = $S['dates']['gym'][$a[0]];
    $s = strtotime($ps . ' +' . $lag . ' days');
    $f = strtotime($pf . ' +' . $lag . ' days');
    $t = strtotime($date);
    return $t <= $s ? 0.0 : ($t >= $f ? 1.0 : ($t - $s) / max(1, $f - $s));
}

function dm_gym_progress(string $day): void
{
    $S = &dm();
    $lines = [];
    foreach ($S['plan']['gym']['acts'] as $a) {
        if (!$a[2]) {
            continue;
        }
        $f = dm_gym_frac($a, $day);
        $id = $S['acts']['gym'][$a[0]];
        [$ps, $pf] = $S['dates']['gym'][$a[0]];
        $line = ['id' => $id, 'actual_start' => $f > 0 ? date('Y-m-d', strtotime($ps . ' +' . $S['plan']['gym']['lag'] . ' days')) : '',
            'actual_finish' => $f >= 1 ? date('Y-m-d', strtotime($pf . ' +' . $S['plan']['gym']['lag'] . ' days')) : ''];
        if (($a[6] ?? 0) > 0) {
            $line['qty_done'] = round($a[6] * $f, 2);
        } else {
            $line['progress_pct'] = round($f * 100, 2);
        }
        $lines[] = $line;
    }
    dm_as('siteeng');
    CsPlanning::saveProgress($S['proj']['gym'], $lines);
}

// ---------------------------------------------------------------------------------------------------- the first of the month

function dm_monthly_bills(string $day): void
{
    $S = &dm();
    $ps = date('Y-m-01', strtotime($day . ' -1 day'));
    $pe = date('Y-m-t', strtotime($ps));
    $ym = date('M Y', strtotime($ps));
    $tag = date('ym', strtotime($day));
    // ---- head office and the yard (overhead of the company)
    dm_check($day, '1010', [['6010', 180000, 'Office rent, ' . date('F Y', strtotime($day)), null, null, true]], 'Office rent', 'officelease');
    dm_check($day, '1010', [['6000', 780000, 'Head office salaries, ' . $ym], ['6005', 98000, 'Employer contributions, head office, ' . $ym]], 'Head office payroll ' . $ym, null, 'Head office payroll account');
    dm_bill('metropower', $day, 'MP-HO-' . $tag, [['6020', 38000 + mt_rand(0, 14000), 'Electricity, head office, ' . $ym]], 'Electricity');
    $S['yard'][date('Y-m', strtotime($day))] = dm_bill('yardlease', $day, 'YL-' . $tag, [['6180', 250000, 'Lease of the Taguig equipment yard and central warehouse, ' . date('F Y', strtotime($day))]], 'Yard and warehouse lease');
    $fuel = (float)($S['fuel'][date('Y-m', strtotime($ps))] ?? 0);
    if ($fuel > 0) {
        dm_bill('rapidfuel', $day, 'RF-' . $tag, [['6140', r2($fuel * 62), 'Diesel delivered to the sites, ' . qty($fuel) . ' L, ' . $ym]], 'Fuel for the fleet');
    }
    // ---- the sites: rentals, utilities, testing, safety and the site team, last month
    foreach (['tower', 'road'] as $k) {
        if ($S['start'][$k] > $pe) {
            continue;
        }
        $from = max($ps, $S['start'][$k]);
        $f = (strtotime($pe) - strtotime($from) + 86400) / (strtotime($pe) - strtotime($ps) + 86400);
        $big = $k === 'tower' ? 1.0 : 0.62;
        dm_bill('metropower', $day, 'MP-' . strtoupper($k) . '-' . $tag, [['5365', r2((52000 + mt_rand(0, 18000)) * $big * $f), 'Site electricity and water, ' . $ym, '01-200', $k]], 'Site utilities');
        dm_bill('qualitest', $day, 'QT-' . strtoupper($k) . '-' . $tag, [['5380', r2((28000 + mt_rand(0, 12000)) * $big * $f), 'Concrete cylinder, soil and steel tests, ' . $ym, '01-700', $k]], 'Material testing');
        dm_check($day, '1006', [['5390', r2((36000 + mt_rand(0, 9000)) * $big * $f), 'PPE, signages and first-aid supplies, ' . $ym, '01-600', $k]], 'Safety supplies ' . $ym, null, 'Various suppliers (site revolving fund)', true);
        dm_check($day, '1010', [['5370', r2(($k === 'tower' ? 235000 : 145000) * $f), 'Project engineers and site staff salaries, ' . $ym, '01-300', $k]], 'Site staff payroll ' . $ym, null, 'Site staff payroll account');
    }
    $plan = $S['dispatch_plan'];
    if ($plan['rol'][0][1] <= $pe && $S['start']['road'] <= $pe) {
        dm_bill('heavyline', $day, 'HL-ROL-' . $tag, [['5335', 185000, 'Rental of vibratory roller 10 t (EQ-008), ' . $ym, '10-100', 'road']], 'Equipment rental');
    }
    if ($plan['crane'][0][1] <= $pe) {
        dm_bill('heavyline', $day, 'HL-TC-' . $tag, [['5335', 420000, 'Lease of tower crane TC5610 (EQ-009), ' . $ym, '03-300', 'tower']], 'Equipment lease');
    }
    // ---- labor-only workers on the tower, billed by their cooperative
    $agency = DB::all("SELECT l.cost_code_id, SUM(l.labor_cost) AS amt, SUM(l.regular_hours + l.ot_hours) AS h FROM cs_timesheet_lines l JOIN cs_timesheets t ON t.id = l.timesheet_id
                        JOIN cs_workers w ON w.id = l.worker_id WHERE w.vendor_id IS NOT NULL AND t.work_date BETWEEN :f AND :t AND t.status <> 'rejected' GROUP BY l.cost_code_id",
        ['f' => $ps, 't' => $pe]);
    if ($agency) {
        $bl = [];
        foreach ($agency as $a) {
            $bl[] = ['5320', r2((float)$a['amt'] * 1.12), 'Labor-only manpower, ' . qty($a['h']) . ' man-hours, ' . $ym, DB::value('SELECT code FROM cs_cost_codes WHERE id = :c', ['c' => $a['cost_code_id']]), 'tower'];
        }
        dm_bill('bayanihan', $day, 'BMS-' . $tag, $bl, 'Manpower billing ' . $ym);
    }
    // ---- the gymnasium: its costs were typed in accounting straight to the project and its cost codes
    if ($S['start']['gym'] <= $pe && $ps <= dm_d(40)) {
        dm_gym_month($ps, $pe, $day);
    }
    // ---- top up the site revolving fund
    $bal = (float)DB::value('SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE account_id = :a', ['a' => ($S['acct'])('1006')]);
    if ($bal < 300000 - 1) {
        dm_as('acctstaff');
        Txn::save('transfer', ['txn_date' => $day, 'account_id' => ($S['acct'])('1010'), 'account2_id' => ($S['acct'])('1006'), 'total' => r2(300000 - $bal),
            'memo' => 'Replenishment of the site revolving fund', 'status' => 'open'], [], null, ['keep_totals' => true]);
    }
}

function dm_gym_month(string $ps, string $pe, string $day): void
{
    $S = &dm();
    $pl = $S['plan']['gym'];
    $frac = function (string $cc, string $date) use ($pl) {
        $w = 0.0;
        $s = 0.0;
        foreach ($pl['acts'] as $a) {
            if ($a[2] === $cc) {
                $w += max(1, $a[5]);
                $s += max(1, $a[5]) * dm_gym_frac($a, $date);
            }
        }
        return $w > 0 ? $s / $w : null;
    };
    $supplier = ['03-100' => 'northline', '03-200' => 'pacsteel', '03-300' => 'primemix', '04-100' => 'metrocement', '05-100' => 'pacsteel', '05-200' => 'northline',
        '06-100' => 'northline', '06-400' => 'northline', '02-200' => 'heavyline'];
    $bills = [];
    foreach ($pl['estimate'] as [$cc, $cat, $desc, $q, $unit, $uc]) {
        $amt = $q * $uc;
        if ($cc === '01-300') {
            $f = 1 / 12.5;
        } else {
            $fe = $frac($cc, $pe);
            if ($fe === null) {
                continue;
            }
            $f = $fe - $frac($cc, date('Y-m-d', strtotime($ps . ' -1 day')));
        }
        if ($f <= 0.0001) {
            continue;
        }
        $m = ['materials' => 1.04, 'labor' => 1.08, 'equipment' => 0.96, 'site_admin' => 1.0][$cat] ?? 0;
        if (!$m) {
            continue;
        }
        $line = [['materials' => '5310', 'labor' => '5320', 'equipment' => '5335', 'site_admin' => '5370'][$cat], r2($amt * $f * $m), $desc . ', ' . date('M Y', strtotime($ps)), $cc, 'gym'];
        if ($cat === 'site_admin') {
            dm_check($day, '1010', [$line], 'Gymnasium site staff ' . date('M Y', strtotime($ps)), null, 'Site staff payroll account');
            continue;
        }
        $bills[$cat === 'labor' ? 'bayanihan' : $supplier[$cc]][] = $line;
    }
    foreach ($bills as $vk => $bl) {
        dm_bill($vk, $day, strtoupper(substr($vk, 0, 3)) . '-GYM-' . date('ym', strtotime($day)), $bl, 'St. Benedict Gymnasium, ' . date('F Y', strtotime($ps)));
    }
}

// ---------------------------------------------------------------------------------------------------- subcontractor billings (the 20th)

function dm_sub_billings(string $day): void
{
    $S = &dm();
    foreach ($S['sc'] as $key => $scId) {
        $s = CsSub::get($scId);
        if (!$s || $s['status'] !== 'active' || $s['start_date'] > $day) {
            continue;
        }
        $billed = CsSub::billedQty($scId);
        $lines = [];
        $full = true;
        foreach (DB::all('SELECT i.*, a.progress_pct FROM cs_subcontract_items i LEFT JOIN cs_activities a ON a.id = i.activity_id WHERE i.subcontract_id = :s', ['s' => $scId]) as $it) {
            $target = round((float)$it['qty'] * min(100, (float)$it['progress_pct']) / 100, 4);
            $thisQ = round($target - ($billed[(int)$it['id']]['qty'] ?? 0), 4);
            if ($target < (float)$it['qty'] - 0.0001) {
                $full = false;
            }
            if ($thisQ > 0.0004) {
                $lines[] = ['sc_item_id' => $it['id'], 'this_qty' => $thisQ];
            }
        }
        if (!$lines) {
            continue;
        }
        $n = 1 + (int)DB::value('SELECT COUNT(*) FROM cs_sub_billings WHERE subcontract_id = :s', ['s' => $scId]);
        $inv = strtoupper(substr((string)$s['vendor_name'], 0, 3)) . '-' . date('Y', strtotime($day)) . '-' . str_pad((string)($scId * 10 + $n), 4, '0', STR_PAD_LEFT);
        $d = ['subcontract_id' => $scId, 'kind' => $full ? 'final' : 'progress', 'invoice_no' => $inv, 'billing_date' => $day, 'period_from' => date('Y-m-d', strtotime($day . ' -30 days')),
            'period_to' => $day, 'notes' => 'Progress billing no. ' . $n];
        if ($key === 'tower_elec' && $day >= dm_d(6)) {
            // the latest billing came in through the subcontractor portal and waits for the QS
            dm_as('subcon');
            CsSub::saveSb($d, $lines, null, 'portal');
            continue;
        }
        dm_as('siteeng');
        $id = CsSub::saveSb($d, $lines);
        $b = CsSub::getSb($id);
        CsApproval::submit('sub_billing', $id, $b['sb_no'] . ' ' . $b['vendor_name'] . ' inv. ' . $inv, (int)$b['project_id'], (float)$b['net_amount']);
        dm_approve('sub_billing', $id, $day);
        if ($full) {
            dm_as('qs');
            CsSub::setStatus($scId, 'completed', 'Work completed and accepted on site');
        }
    }
}

// ---------------------------------------------------------------------------------------------------- progress billings to the clients (the 25th)

function dm_bill_client(string $k, string $type, string $day, array $lines, array $extra = []): int
{
    $S = &dm();
    $pid = $S['proj'][$k];
    dm_as('billing');
    $id = CsBilling::save(['project_id' => $pid, 'billing_type' => $type, 'billing_date' => $day, 'period_from' => date('Y-m-d', strtotime($day . ' -30 days')), 'period_to' => $day] + $extra, $lines);
    $b = CsBilling::get($id);
    CsApproval::submit('billing', $id, $b['billing_no'] . ' ' . $b['project_code'], $pid, (float)$b['amount_applied']);
    return $id;
}

function dm_progress_billings(string $day): void
{
    $S = &dm();
    foreach (['gym', 'tower', 'road'] as $k) {
        $pid = $S['proj'][$k];
        if ($day < date('Y-m-d', strtotime($S['start'][$k] . ' +25 days')) || !empty($S['final'][$k])) {
            continue;
        }
        $phys = CsProject::physicalPct($pid);
        $billed = CsPlanning::billedByBoq($pid);
        $lines = [];
        foreach (DB::all('SELECT b.*, a.progress_pct, a.qty_done, a.planned_qty FROM cs_boq_items b LEFT JOIN cs_activities a ON a.id = b.activity_id WHERE b.project_id = :p ORDER BY b.sort_order', ['p' => $pid]) as $it) {
            if ((int)$it['is_variation']) {
                $since = (string)DB::value('SELECT client_approved_date FROM cs_change_orders WHERE id = :c', ['c' => $it['change_order_id']]);
                $pct = min(100, max(0, (strtotime($day) - strtotime($since)) / 86400 / 75 * 100));
            } else {
                // the accomplishment of the line: every activity of its cost codes, weighted; general requirements follow the whole job
                $codes = [];
                foreach ($S['plan'][$k]['boq'] as $bq) {
                    if ($bq[0] === $it['item_no']) {
                        $codes = $bq[4];
                    }
                }
                $pct = $phys;
                if ($codes && !str_starts_with($codes[0], '01-')) {
                    [$in, $pp] = DB::in('bqc', array_map('dm_cc', $codes));
                    $agg = DB::row("SELECT SUM(GREATEST(weight_pct, 0.1) * progress_pct) / SUM(GREATEST(weight_pct, 0.1)) AS p FROM cs_activities WHERE project_id = :p AND is_milestone = 0 AND cost_code_id IN $in", ['p' => $pid] + $pp);
                    $pct = (float)($agg['p'] ?? 0);
                }
            }
            $prevPct = (float)$it['amount'] != 0.0 ? ($billed[(int)$it['id']]['amount'] ?? 0) / (float)$it['amount'] * 100 : 0;
            $thisQ = round($pct - $prevPct, 4);
            if ($thisQ < 0.05) {
                continue;
            }
            if ($k === 'road' && (float)$it['qty'] > 1) {
                $q = round((float)$it['qty'] * $pct / 100 - ($billed[(int)$it['id']]['qty'] ?? 0), 2);
                if ($q > 0) {
                    $lines[] = ['boq_item_id' => $it['id'], 'this_qty' => $q];
                }
            } else {
                $lines[] = ['boq_item_id' => $it['id'], 'this_pct' => $thisQ];
            }
        }
        if (!$lines) {
            continue;
        }
        $id = dm_bill_client($k, $k === 'road' ? 'quantity' : 'progress', $day, $lines, ['notes' => 'Accomplishment as of ' . fdate($day) . ', ' . number_format($phys, 2) . '% physical progress.']);
        dm_approve('billing', $id, $day);
        dm_as('billing');
        CsBilling::markSubmitted($id, date('Y-m-d', strtotime($day . ' +1 day')));
        $cert = date('Y-m-d', strtotime($day . ' +' . ['gym' => 6, 'tower' => 7, 'road' => 30][$k] . ' days'));
        $S['certify'][$cert][] = [$id, $k];
    }
}

/** The client's certificate: the billing is invoiced, and paid around its due date */
function dm_certify(int $id, string $k, string $day): void
{
    $S = &dm();
    $b = CsBilling::get($id);
    $cut = null;
    if ($k === 'tower' && $b['billing_type'] === 'progress' && (int)DB::value("SELECT COUNT(*) FROM cs_billings WHERE project_id = :p AND billing_type = 'progress' AND id <= :id", ['p' => $b['project_id'], 'id' => $id]) === 3) {
        $cut = r2((float)$b['this_gross'] * 0.96);
    }
    dm_as('billing');
    $txn = CsBilling::certify($id, $day, ($k === 'road' ? 'DPWH-CERT-' : 'PC-') . date('ymd', strtotime($day)) . '-' . $id, $cut, true);
    $inv = Txn::load($txn);
    $pay = date('Y-m-d', strtotime(($inv['due_date'] ?: $day) . ' ' . (mt_rand(-4, 6) >= 0 ? '+' : '-') . abs(mt_rand(-4, 6)) . ' days'));
    if ($k === 'tower' && $day >= dm_d(66) && $day <= dm_d(45)) {
        return; // this one is overdue: the client is disputing a deduction
    }
    if ($pay <= date('Y-m-d')) {
        $S['collect'][$pay][] = [$txn, $k];
    }
}

function dm_collections(string $day): void
{
    $S = &dm();
    foreach ($S['collect'][$day] ?? [] as [$txn, $k]) {
        $inv = Txn::load($txn);
        $open = Balances::open($inv);
        if ($open < 1) {
            continue;
        }
        $base = r2($open * Withholding::netRatio($inv));
        $rows = dm_wht($k === 'road' ? ['WT-CONTRACTOR' => $base, 'WT-VAT' => $base] : ['WT-CONTRACTOR' => $base], 'sales');
        $wht = r2(array_sum(array_column($rows, 'amount')));
        dm_as('cashier');
        Txn::save('payment', ['txn_date' => $day, 'customer_id' => (int)$inv['customer_id'], 'account_id' => ($S['acct'])($k === 'road' ? '1030' : '1010'), 'account2_id' => Accts::get('ar'),
            'payment_method_id' => ($S['pm'])($k === 'road' ? 'Check' : 'Bank Transfer'), 'ref_no' => ($k === 'road' ? 'LBP-' : 'IB-') . (880000 + $txn), 'total' => r2($open - $wht),
            'withholding_amount' => $wht, 'memo' => 'Collection of ' . $inv['txn_no'] . ($k === 'road' ? ' (2% income tax and 5% VAT withheld)' : ' (2% withheld)')], [], null,
            ['keep_totals' => true, 'applications' => [['target_txn_id' => $txn, 'amount' => r2($open - $wht), 'withholding' => $wht]], 'withholdings' => [$txn => $rows]]);
    }
}

// ---------------------------------------------------------------------------------------------------- paying suppliers (Fridays)

function dm_pay_bills(string $day): void
{
    $S = &dm();
    $bills = DB::all("SELECT t.*, v.wht_ids, v.name AS vname FROM transactions t JOIN vendors v ON v.id = t.vendor_id
                       WHERE t.txn_type = 'bill' AND t.is_voided = 0 AND t.status IN ('open','partial') AND t.due_date <= :d AND t.txn_date <= :d7 ORDER BY t.due_date, t.id",
        ['d' => $day, 'd7' => date('Y-m-d', strtotime($day . ' -7 days'))]);
    foreach ($bills as $b) {
        if ((int)$b['vendor_id'] === $S['vend']['northline'] && $b['txn_date'] >= dm_d(60)) {
            continue; // held: short delivery being settled with the supplier
        }
        $open = Balances::open($b);
        if ($open < 1) {
            continue;
        }
        $w = DB::row('SELECT code FROM withholding_taxes WHERE id = :w', ['w' => (int)explode(',', (string)$b['wht_ids'])[0]]);
        $rows = $w ? dm_wht([$w['code'] => r2($open * Withholding::netRatio($b))], 'purchases') : [];
        $wht = r2(array_sum(array_column($rows, 'amount')));
        dm_as('acctstaff');
        Txn::save('bill_payment', ['txn_date' => $day, 'vendor_id' => (int)$b['vendor_id'], 'account_id' => ($S['acct'])('1010'), 'account2_id' => Accts::get('ap'),
            'payment_method_id' => ($S['pm'])('Check'), 'total' => r2($open - $wht), 'withholding_amount' => $wht, 'memo' => 'Payment of ' . ($b['ref_no'] ?: $b['txn_no'])], [], null,
            ['keep_totals' => true, 'applications' => [['target_txn_id' => (int)$b['id'], 'amount' => r2($open - $wht), 'withholding' => $wht]], 'withholdings' => $rows ? [(int)$b['id'] => $rows] : []]);
    }
}

// ---------------------------------------------------------------------------------------------------- payroll (16th and 1st)

function dm_payroll(string $day): void
{
    $S = &dm();
    if ((int)date('j', strtotime($day)) === 16) {
        [$from, $to] = [date('Y-m-01', strtotime($day)), date('Y-m-15', strtotime($day))];
    } else {
        $from = date('Y-m-16', strtotime($day . ' -1 day'));
        $to = date('Y-m-t', strtotime($day . ' -1 day'));
    }
    if ($to < $S['start']['tower'] && $to < $S['start']['road']) {
        return;
    }
    dm_as('hr');
    try {
        $run = CsLabor::buildPayroll($from, $to, $day, 'Semi-monthly payroll of the site crews');
    } catch (UserError $e) {
        return;
    }
    $ded = [];
    foreach (DB::all('SELECT * FROM cs_payroll_lines WHERE run_id = :r', ['r' => $run]) as $l) {
        $ded[$l['id']] = min(r2(((float)$l['gross_pay'] + (float)$l['allowances']) * 0.045), max(0, (float)$l['gross_pay'] + (float)$l['allowances'] - (float)$l['advance_deduction']));
    }
    CsLabor::setDeductions($run, $ded);
    $r = DB::row('SELECT * FROM cs_payroll_runs WHERE id = :id', ['id' => $run]);
    CsApproval::submit('payroll', $run, $r['run_no'] . ' ' . fdate($from) . ' to ' . fdate($to), null, (float)$r['total_gross']);
    if ($day >= dm_d(9)) {
        dm_approve('payroll', $run, $day, 1, 'Checked against the approved timesheets.');
        return;
    }
    dm_approve('payroll', $run, $day);
    $r = DB::row('SELECT * FROM cs_payroll_runs WHERE id = :id', ['id' => $run]);
    dm_check($day, '1010', [['2165', (float)$r['total_net'], 'Net pay, payroll ' . $r['run_no']]], 'Payroll ' . $r['run_no'] . ' (ATM payroll credit)', null, 'Site crews payroll (ATM)');
}

// ---------------------------------------------------------------------------------------------------- month end

function dm_month_end(string $day): void
{
    $S = &dm();
    if ($day < $S['open']) {
        return;
    }
    // ---- depreciation of the fleet and the vehicles
    dm_as('acctstaff');
    $y = (int)date('Y', strtotime($day));
    $m = (int)date('n', strtotime($day));
    if ($day > date('Y-m-t', strtotime($S['open']))) {
        Depreciation::run($y, $m, null, 'Monthly depreciation');
    }
    // ---- revenue recognized on the percentage of completion
    foreach (['gym', 'tower', 'road'] as $k) {
        if ($S['start'][$k] > $day) {
            continue;
        }
        dm_as('acctstaff');
        try {
            $rid = CsBilling::prepareRecognition($S['proj'][$k], $day);
        } catch (UserError $e) {
            continue;
        }
        $rr = DB::row('SELECT r.*, p.code FROM cs_revenue_recognitions r JOIN cs_projects p ON p.id = r.project_id WHERE r.id = :id', ['id' => $rid]);
        CsApproval::submit('revenue', $rid, $rr['rr_no'] . ' ' . $rr['code'] . ' to ' . fdate($day), $S['proj'][$k], abs((float)$rr['this_period']));
        dm_approve('revenue', $rid, $day);
    }
    // ---- the yard and warehouse shared by the projects, allocated on their direct costs of the month
    $yard = $S['yard'][date('Y-m', strtotime($day))] ?? null;
    $active = [];
    foreach (['gym', 'tower', 'road'] as $k) {
        if ($S['start'][$k] <= $day && ($k !== 'gym' || $day <= dm_d(45))) {
            $active[] = $S['proj'][$k];
        }
    }
    if ($yard && $active) {
        dm_as('acctstaff');
        $aid = CsAlloc::save(['source_txn_id' => $yard, 'source_account_id' => ($S['acct'])('6180'), 'amount' => 250000, 'method' => 'direct_cost', 'alloc_date' => $day,
            'period_from' => date('Y-m-01', strtotime($day)), 'period_to' => $day, 'cost_code_id' => dm_cc('01-800'),
            'description' => 'Yard and central warehouse, ' . date('F Y', strtotime($day))], $active);
        $al = CsAlloc::get($aid);
        CsApproval::submit('allocation', $aid, $al['alloc_no'] . ' ' . $al['description'], null, (float)$al['amount']);
        dm_approve('allocation', $aid, $day, $day >= dm_d(30) ? 1 : 99);
    }
    // ---- government contributions withheld and the employer's share, remitted
    $stat = (float)DB::value('SELECT COALESCE(SUM(credit - debit),0) FROM journal_lines WHERE account_id = :a', ['a' => ($S['acct'])('2150')]);
    if ($stat > 1) {
        dm_check($day, '1010', [['2150', r2($stat), 'SSS, PhilHealth and Pag-IBIG contributions, ' . date('F Y', strtotime($day))]], 'Contributions ' . date('M Y', strtotime($day)), null, 'SSS / PhilHealth / Pag-IBIG');
    }
}

// ---------------------------------------------------------------------------------------------------- dated events

function dm_ms_flow(string $k, string $code, string $day, bool $certify = true): void
{
    $S = &dm();
    $mid = $S['ms'][$k][$code];
    dm_as('siteeng');
    foreach (DB::column('SELECT id FROM cs_milestone_items WHERE milestone_id = :m', ['m' => $mid]) as $iid) {
        CsPlanning::msItemStatus((int)$iid, 'submitted', 'Filed in the document register');
    }
    CsPlanning::milestoneAction($mid, 'submit');
    dm_as($S['plan'][$k]['pm']);
    CsPlanning::milestoneAction($mid, 'review');
    CsPlanning::milestoneAction($mid, 'accept', ['completion_pct' => 100]);
    if ($certify) {
        dm_as('projdir');
        CsPlanning::milestoneAction($mid, 'certify', ['date' => date('Y-m-d', strtotime($day . ' +4 days')), 'ref' => strtoupper($k) . '-' . $code . '-CERT']);
    }
    DB::run('UPDATE cs_milestones SET accepted_date = :d WHERE id = :id', ['d' => $day, 'id' => $mid]);
}

function dm_events(string $day): void
{
    $S = &dm();
    $plus = fn(string $d, int $n) => date('Y-m-d', strtotime($d . ($n >= 0 ? ' +' : ' ') . $n . ' days'));

    // ---- subcontracts approved as their work starts; the advance paid
    foreach ($S['sc'] as $key => $scId) {
        $s = CsSub::get($scId);
        if ($s['status'] === 'draft' && $s['start_date'] <= $day) {
            dm_as('qs');
            CsSub::submit($scId);
            dm_approve('subcontract', $scId, $day);
            if ((float)$s['advance_amount'] > 0) {
                dm_as('acctstaff');
                Txn::save('vendor_advance', ['txn_date' => $day, 'vendor_id' => (int)$s['vendor_id'], 'account_id' => ($S['acct'])('1010'), 'account2_id' => Accts::get('vendor_advances'),
                    'payment_method_id' => ($S['pm'])('Check'), 'ref_no' => 'CHK-' . (4500 + $scId), 'total' => (float)$s['advance_amount'], 'subtotal' => (float)$s['advance_amount'], 'status' => 'open',
                    'class_id' => CsProject::classId((int)$s['project_id']), 'memo' => 'Advance on ' . $s['sc_no'] . ' ' . $s['work_package']], [], null, ['keep_totals' => true]);
            }
        }
    }

    // ---- advance (mobilization) billings five days after the start; the client's certificates
    foreach (['gym', 'tower', 'road'] as $k) {
        if ($day === $plus($S['start'][$k], 5)) {
            $id = dm_bill_client($k, 'advance', $day, [], ['notes' => 'Mobilization advance against the advance payment bond']);
            dm_approve('billing', $id, $day);
            dm_as('billing');
            CsBilling::markSubmitted($id, $day);
            $S['certify'][$plus($day, 4)][] = [$id, $k];
        }
    }
    foreach ($S['certify'][$day] ?? [] as [$id, $k]) {
        dm_certify($id, $k, $day);
    }

    // ---- cash advances of workers, deducted on the next payroll
    foreach ([[150, 'ben', 'tower', 3000, 'Hospital bill of a family member'], [96, 'rey', 'road', 2500, 'School enrollment of a child'],
        [41, 'mario', 'tower', 3000, 'House repair after the typhoon'], [11, 'allan', 'road', 2000, 'Transportation and meals'], [2, 'tony', 'tower', 1500, 'Medicine']] as [$ago, $wk, $k, $amt, $why]) {
        if ($day !== dm_d($ago)) {
            continue;
        }
        dm_as('timekeeper');
        $ca = CsLabor::requestAdvance(['worker_id' => $S['workers'][$wk], 'project_id' => $S['proj'][$k], 'advance_date' => $day, 'amount' => $amt, 'purpose' => $why]);
        if ($ago > 3) {
            dm_approve('cash_advance', $ca, $day);
            dm_as('cashier');
            CsLabor::releaseAdvance($ca, ($S['acct'])('1006'), $day);
        }
    }

    // ---- change orders
    if ($day === dm_d(75)) {
        dm_as('siteeng');
        $S['co']['t1'] = CsContracts::saveCo(['project_id' => $S['proj']['tower'], 'co_type' => 'additional_work', 'title' => 'Additional basement ramp and entrance canopy',
            'reason' => 'Client instruction no. 7: second basement access for the residents\' parking', 'requested_by_party' => 'client', 'schedule_impact_days' => 20,
            'labor_impact' => 'Formworks and rebar crew for four more weeks', 'equipment_impact' => 'Concrete pump, six more pour days', 'billing_implications' => 'Billed on its own lines of the schedule of values once the client approves it',
            'description' => 'Ramp of 210 cu.m of concrete with 18 t of rebar, and a steel canopy at the lobby entrance, as per sketch SK-07.'], [
            ['description' => 'Ramp structural concrete', 'unit' => 'cu.m', 'qty' => 210, 'unit_rate' => 7800, 'cost_amount' => 1224000, 'cost_code_id' => dm_cc('03-300')],
            ['description' => 'Ramp reinforcing steel', 'unit' => 'ton', 'qty' => 18, 'unit_rate' => 64000, 'cost_amount' => 936000, 'cost_code_id' => dm_cc('03-200')],
            ['description' => 'Entrance canopy, steel framing and roofing', 'unit' => 'lot', 'qty' => 1, 'unit_rate' => 660000, 'cost_amount' => 480000, 'cost_code_id' => dm_cc('05-200')]]);
    }
    if ($day === dm_d(72)) {
        dm_as('siteeng');
        CsApproval::submit('change_order', $S['co']['t1'], CsContracts::getCo($S['co']['t1'])['co_no'], $S['proj']['tower'], 3450000);
        dm_approve('change_order', $S['co']['t1'], $plus($day, 2));
    }
    if ($day === dm_d(62)) {
        dm_as('pm');
        CsContracts::clientApprove($S['co']['t1'], $day, 'NLDC-VO-001');
        $bid = (int)DB::value('SELECT budget_id FROM cs_change_orders WHERE id = :c', ['c' => $S['co']['t1']]);
        dm_as('qs');
        $b = DB::row('SELECT * FROM cs_budgets WHERE id = :id', ['id' => $bid]);
        CsApproval::submit('budget', $bid, CsProject::get($S['proj']['tower'])['code'] . ' v' . $b['version_no'], $S['proj']['tower'], (float)$b['total_amount']);
        dm_approve('budget', $bid, $plus($day, 2));
    }
    if ($day === dm_d(58)) {
        dm_as('siteeng');
        $S['co']['r1'] = CsContracts::saveCo(['project_id' => $S['proj']['road'], 'co_type' => 'additional_work', 'title' => 'Additional RCPC drainage line, Ususan section',
            'reason' => 'Flooding at Sta. 0+420 reported by the barangay; DPWH instructed an extra cross drain', 'requested_by_party' => 'client', 'schedule_impact_days' => 15,
            'billing_implications' => 'Variation order under the unit prices of the contract'], [
            ['description' => 'RCPC 910 mm Class II, additional line', 'unit' => 'l.m', 'qty' => 120, 'unit_rate' => 13500, 'cost_amount' => 1180000, 'cost_code_id' => dm_cc('10-300')],
            ['description' => 'Inlets and junction boxes (6 sets)', 'unit' => 'lot', 'qty' => 1, 'unit_rate' => 530000, 'cost_amount' => 420000, 'cost_code_id' => dm_cc('10-300')]]);
        CsApproval::submit('change_order', $S['co']['r1'], CsContracts::getCo($S['co']['r1'])['co_no'], $S['proj']['road'], 2150000);
        dm_approve('change_order', $S['co']['r1'], $plus($day, 3));
    }
    if ($day === dm_d(45)) {
        dm_as('pm');
        CsContracts::clientApprove($S['co']['r1'], $day, 'DPWH-MM1-VO-01');
        $bid = (int)DB::value('SELECT budget_id FROM cs_change_orders WHERE id = :c', ['c' => $S['co']['r1']]);
        dm_as('qs');
        $b = DB::row('SELECT * FROM cs_budgets WHERE id = :id', ['id' => $bid]);
        CsApproval::submit('budget', $bid, CsProject::get($S['proj']['road'])['code'] . ' v' . $b['version_no'], $S['proj']['road'], (float)$b['total_amount']);
        dm_approve('budget', $bid, $plus($day, 1));
    }
    if ($day === dm_d(6)) {
        dm_as('siteeng');
        $S['co']['t2'] = CsContracts::saveCo(['project_id' => $S['proj']['tower'], 'co_type' => 'client_request', 'title' => 'Granite floor finish at the lobby and elevator halls',
            'reason' => 'Client upgrade from homogeneous tiles to granite (letter of 2 October)', 'requested_by_party' => 'client', 'schedule_impact_days' => 0,
            'billing_implications' => 'Additional lines of the schedule of values'], [
            ['description' => 'Granite slabs 20 mm, supply and install', 'unit' => 'sq.m', 'qty' => 320, 'unit_rate' => 4750, 'cost_amount' => 1152000, 'cost_code_id' => dm_cc('06-400')],
            ['description' => 'Less: homogeneous tiles in the original scope', 'unit' => 'sq.m', 'qty' => -320, 'unit_rate' => 750, 'cost_amount' => -192000, 'cost_code_id' => dm_cc('06-400')]]);
        CsApproval::submit('change_order', $S['co']['t2'], CsContracts::getCo($S['co']['t2'])['co_no'], $S['proj']['tower'], 1280000);
    }

    // ---- milestones as their work is finished
    foreach ([['tower', 'MS-01', '2.4'], ['road', 'MS-01', '3.3']] as [$k, $code, $wbs]) {
        $m = DB::row('SELECT status FROM cs_milestones WHERE id = :m', ['m' => $S['ms'][$k][$code]]);
        if ($m['status'] === 'pending' && (float)DB::value('SELECT progress_pct FROM cs_activities WHERE id = :a', ['a' => $S['acts'][$k][$wbs]]) >= 100) {
            dm_ms_flow($k, $code, $day);
        }
    }
    $gs = $S['start']['gym'];
    $lag = $S['plan']['gym']['lag'];
    $done = $S['gymdone'] ??= $plus((string)max(array_map(fn($x) => $x[1], array_intersect_key($S['dates']['gym'], array_flip(array_map(fn($a) => $a[0], array_filter($S['plan']['gym']['acts'], fn($a) => $a[2])))))), $lag);
    if ($day === $plus($gs, 232 + $lag)) {
        dm_ms_flow('gym', 'MS-01', $day);
    }

    // ---- the gymnasium is finished
    if ($day === $done) {
        dm_gym_progress($day);
        dm_as('pm2');
        CsProject::update($S['proj']['gym'], ['status' => 'substantially_completed', 'actual_end_date' => $day]);
        dm_ms_flow('gym', 'MS-02', $day);
        $S['final']['gym'] = true;
        foreach ([['Gym floor', 'Scratches on the sports flooring near the north entrance', 'ColorCoat Painting Works', 'low', 'closed'],
            ['Locker room B', 'Leaking faucet and loose shower head', 'AquaFlow Plumbing Services', 'medium', 'closed'],
            ['Bleachers', 'Missing end caps on the handrails', 'Our carpentry crew', 'low', 'closed'], ['Main hall', 'Two lighting fixtures not working', 'Allied Electrical Contractors', 'medium', 'closed'],
            ['Roof', 'Ponding near gutter line C', 'Our roofing crew', 'high', 'verified'], ['Stage', 'Paint touch-up on the proscenium', 'ColorCoat Painting Works', 'low', 'rectified'],
            ['Exterior', 'Hairline cracks on the parapet plaster', 'Our masonry crew', 'medium', 'in_progress'], ['Storage room', 'Door lock to be replaced', 'Our carpentry crew', 'low', 'open']] as $i => [$loc, $desc, $resp, $sev, $st]) {
            dm_as('qa');
            $pi = CsCloseout::savePunch($S['proj']['gym'], ['kind' => 'punch', 'location' => $loc, 'description' => $desc, 'responsible_party' => $resp, 'reported_date' => $day,
                'due_date' => $plus($day, 21), 'severity' => $sev, 'warranty_covered' => 0]);
            foreach (['closed' => ['rectified', 'verified', 'closed'], 'verified' => ['rectified', 'verified'], 'rectified' => ['rectified'], 'in_progress' => ['in_progress'], 'open' => []][$st] as $step) {
                CsCloseout::punchStatus($pi, $step, $step === 'verified' ? 'Checked with the owner\'s representative' : '');
            }
        }
    }
    if ($day === $plus($done, 2)) {
        $id = dm_bill_client('gym', 'final', $day, [], ['notes' => 'Final billing: all items of the schedule of values completed.']);
        dm_approve('billing', $id, $day);
        dm_as('billing');
        CsBilling::markSubmitted($id, $day);
        $S['certify'][$plus($day, 8)][] = [$id, 'gym'];
    }
    if ($day === $plus($done, 15)) {
        dm_as('pm2');
        CsProject::update($S['proj']['gym'], ['status' => 'completed']);
        CsCloseout::startWarranty($S['proj']['gym'], $done);
        $i = 0;
        foreach (DB::all('SELECT * FROM cs_closeout_items WHERE project_id = :p ORDER BY line_no', ['p' => $S['proj']['gym']]) as $ci) {
            $i++;
            $st = $i <= 9 ? 'completed' : ($i <= 11 ? 'in_progress' : 'pending');
            DB::update('cs_closeout_items', ['status' => $st, 'completed_date' => $st === 'completed' ? $plus($done, $i) : null, 'updated_by' => dm_user('pm2'),
                'remarks' => $st === 'in_progress' ? 'Waiting for the owner\'s signature' : null], 'id = :id', ['id' => $ci['id']]);
        }
        dm_as('pm2');
        CsPlanning::msItemStatus((int)DB::value("SELECT id FROM cs_milestone_items WHERE milestone_id = :m AND doc_type = 'as_built'", ['m' => $S['ms']['gym']['MS-03']]), 'submitted', 'Turned over on USB and in print');
        CsPlanning::msItemStatus((int)DB::value("SELECT id FROM cs_milestone_items WHERE milestone_id = :m AND doc_type = 'punch_clearance'", ['m' => $S['ms']['gym']['MS-03']]), 'in_progress', 'Two punch items left');
    }
    if ($day === dm_d(19)) {
        dm_as('acctstaff');
        try {
            CsBilling::settleContractAccounts($S['proj']['gym'], $day);
        } catch (UserError $e) {
        }
    }
    if ($day === dm_d(8)) {
        // retention of the gymnasium's electrical subcontractor released after acceptance
        $s = CsSub::get($S['sc']['gym_elec']);
        if ($s['status'] === 'completed') {
            dm_as('siteeng');
            $id = CsSub::saveSb(['subcontract_id' => $s['id'], 'kind' => 'retention_release', 'invoice_no' => 'ALL-RET-' . $s['id'], 'billing_date' => $day, 'notes' => 'Release of retention after acceptance'], []);
            $b = CsSub::getSb($id);
            CsApproval::submit('sub_billing', $id, $b['sb_no'] . ' ' . $b['vendor_name'] . ' inv. ' . $b['invoice_no'], (int)$b['project_id'], (float)$b['net_amount']);
            dm_approve('sub_billing', $id, $day);
        }
    }
    if ($day === dm_d(3)) {
        // the school agreed to release the retention against a warranty bond: the billing waits for approval
        dm_bill_client('gym', 'retention_release', $day, [], ['notes' => 'Release of the 10% retention against warranty bond WB-GYM-01 of InsurePro']);
    }

    // ---- materials moved between projects, returned, and a rejected delivery
    if ($day === dm_d(30)) {
        dm_as('warehouse');
        $doc = CsMaterials::saveDoc(['doc_type' => 'transfer', 'project_id' => $S['proj']['tower'], 'to_project_id' => $S['proj']['road'], 'doc_date' => $day,
            'notes' => 'Cement lent to the road project for the drainage collars'], [['item_id' => $S['items']['CEM-40'], 'qty' => 60, 'cost_code_id' => dm_cc('10-300')]]);
        CsApproval::submit('material_transfer', $doc, (string)DB::value('SELECT doc_no FROM cs_material_docs WHERE id = :d', ['d' => $doc]), $S['proj']['tower'],
            (float)DB::value('SELECT total_cost FROM cs_material_docs WHERE id = :d', ['d' => $doc]));
        dm_approve('material_transfer', $doc, $day);
    }
    if ($day === dm_d(12)) {
        dm_as('warehouse');
        $site = (int)CsProject::get($S['proj']['tower'])['site_id'];
        $doc = CsMaterials::saveDoc(['doc_type' => 'return', 'project_id' => $S['proj']['tower'], 'site_id' => $site, 'doc_date' => $day, 'notes' => 'Unused plywood returned after the 8th-floor pour'],
            [['item_id' => $S['items']['PLY-12'], 'qty' => 15, 'cost_code_id' => dm_cc('03-100')]]);
        CsMaterials::postDoc($doc);
    }
    if ($day === dm_d(20)) {
        $line = DB::row("SELECT l.id, l.qty FROM transaction_lines l JOIN transactions t ON t.id = l.txn_id WHERE t.txn_type = 'bill' AND l.item_id = :i AND t.txn_date <= :d
                          ORDER BY t.txn_date DESC, l.id DESC LIMIT 1", ['i' => $S['items']['CEM-40'], 'd' => $day]);
        if ($line) {
            dm_as('qa');
            CsMaterials::inspect((int)$line['id'], (float)$line['qty'] - 20, 20, 'Hardened bags (wet during delivery)');
        }
    }
    // ---- an extension of time on the road
    if ($day === dm_d(35)) {
        $eot = DB::insert('cs_eot_requests', ['project_id' => $S['proj']['road'], 'ref_no' => Seq::next('cs_eot'), 'cause' => 'weather', 'days_requested' => 12, 'submitted_date' => $day,
            'reason' => 'Eleven rain days in August and September stopped paving (PAGASA rainfall records attached).', 'status' => 'submitted', 'created_by' => dm_user('pm')]);
        $S['eot'] = $eot;
    }
    if ($day === dm_d(26)) {
        dm_as('projdir');
        CsPlanning::eotDecision($S['eot'], 'partially_granted', 10, $day);
    }
}

// =====================================================================================================================
// Quality, safety, documents, registers, and the state of today
// =====================================================================================================================

function dm_finish(): void
{
    $S = &dm();
    $tw = $S['proj']['tower'];
    $rd = $S['proj']['road'];
    $gm = $S['proj']['gym'];

    // ---- inspections and non-conformances
    foreach ([
        [$tw, 'pre_pour', 'Pre-pour inspection, footing F-12 to F-20', 132, 'Basement, grid A-C', 'Engr. Paolo Garcia', 'passed', 'Rebar spacing and cover as per plan; slump 4 in.'],
        [$tw, 'material_test', 'Concrete cylinder test, 28 days (batch 14)', 95, 'QualiTest laboratory', 'QualiTest Materials Laboratory', 'passed', 'Average 4,280 psi against 4,000 psi'],
        [$tw, 'pre_pour', 'Pre-pour inspection, 5th floor slab', 38, '5F, grid 1-6', 'Engr. Paolo Garcia', 'conditional', 'Add chairs at bay 4; corrected before pouring'],
        [$tw, 'pre_pour', 'Pre-pour inspection, 7th floor columns', 9, '7F', 'Engr. Paolo Garcia', 'failed', 'Column C-7 ties spaced 200 mm instead of 100 mm in the confinement zone (see NCR)'],
        [$tw, 'equipment_safety', 'Tower crane load test and inspection', 82, 'Tower crane TC5610', 'Jose Mercado', 'passed', 'Load test at 110% passed; certificate issued'],
        [$rd, 'material_test', 'Field density test, embankment layer 3', 70, 'Sta. 0+300 to 0+600', 'QualiTest Materials Laboratory', 'passed', '96% of maximum dry density'],
        [$rd, 'material_test', 'Flexural strength of PCCP beams', 14, 'Sta. 0+800', 'DPWH Materials Engineer', 'passed', '660 psi at 14 days'],
        [$rd, 'checklist', 'Joint inspection of RCPC line', 33, 'Sta. 0+000 to 0+860', 'Engr. Paolo Garcia', 'passed', 'Joints sealed, invert levels within tolerance'],
        [$gm, 'final', 'Final inspection with the owner', 44, 'Whole building', 'Sr. Teresita Lagman, OSB', 'conditional', 'Eight punch items recorded'],
    ] as [$pid, $kind, $title, $ago, $loc, $who, $res, $find]) {
        $ins[$title] = DB::insert('cs_inspections', ['insp_no' => Seq::next('cs_inspection'), 'project_id' => $pid, 'kind' => $kind, 'title' => $title, 'inspection_date' => dm_d($ago),
            'location' => $loc, 'inspector' => $who, 'findings' => $find, 'result' => $res, 'checklist' => "[x] Drawings and approved shop drawings on hand\n[x] Measurements checked\n" . ($res === 'failed' ? '[ ]' : '[x]') . ' Within tolerance',
            'created_by' => dm_user('qa')]);
    }
    foreach ([
        [$tw, 'Column C-7 ties spaced wider than the plan', 'Ties at 200 mm in the confinement zone of column C-7, 7th floor; the plan requires 100 mm.', 9, 'Our rebar crew',
            'Foreman skipped the detail on the drawing revision', 'Ties added before the pour; inspection repeated', 'Toolbox talk on revised drawings; QA checks the latest revision', 18500, 'in_progress', '03-200', 'Pre-pour inspection, 7th floor columns'],
        [$tw, 'Honeycombing on basement wall W-3', 'Honeycombs on 2.4 sq.m of the basement retaining wall after form removal.', 61, 'Prime Ready-Mix Concrete, Inc.', 'Poor vibration at the wall base',
            'Chipped and repaired with non-shrink grout', 'Vibrator operator assigned for every pour', 32000, 'closed', '03-300', null],
        [$rd, 'PCCP slab cracked at Sta. 0+640', 'Transverse crack on one 4.5 m slab, 6 days after paving.', 22, 'Our paving crew', 'Late saw-cutting of the contraction joint',
            'Slab removed and replaced', 'Saw-cutting within 12 hours of paving', 68000, 'verified', '10-200', null],
    ] as [$pid, $title, $desc, $ago, $party, $root, $ca, $pa, $cost, $st, $cc, $insp]) {
        DB::insert('cs_ncrs', ['ncr_no' => Seq::next('cs_ncr'), 'project_id' => $pid, 'cost_code_id' => dm_cc($cc), 'inspection_id' => $insp ? $ins[$insp] : null, 'title' => $title, 'description' => $desc,
            'raised_date' => dm_d($ago), 'responsible_party' => $party, 'root_cause' => $root, 'corrective_action' => $ca, 'preventive_action' => $pa, 'rework_cost' => $cost,
            'due_date' => dm_d($ago - 7), 'closed_date' => $st === 'closed' ? dm_d($ago - 9) : null, 'status' => $st, 'created_by' => dm_user('qa')]);
    }

    // ---- safety: toolbox meetings every Monday, trainings, a near miss and an incident
    foreach (['tower' => $tw, 'road' => $rd] as $k => $pid) {
        foreach (dm_workdays(max($S['start'][$k], dm_d(70)), dm_d(0)) as $d) {
            if (date('N', strtotime($d)) === '1') {
                DB::insert('cs_safety_records', ['ref_no' => Seq::next('cs_safety'), 'project_id' => $pid, 'kind' => 'toolbox', 'record_date' => $d, 'title' => 'Toolbox meeting: ' .
                    ['working at heights', 'housekeeping', 'heat stress', 'lifting operations', 'electrical safety', 'traffic management'][(int)date('W', strtotime($d)) % 6],
                    'attendees' => $k === 'tower' ? 14 : 9, 'status' => 'closed', 'created_by' => dm_user('safety')]);
            }
        }
    }
    foreach ([
        [$tw, 'training', 'Basic occupational safety and health (BOSH) refresher', 52, null, 'low', 18, 0, 'closed'],
        [$tw, 'near_miss', 'Plywood sheet fell from the 6th floor in strong wind', 27, 'No one hurt; area below was barricaded', 'high', null, 0, 'closed'],
        [$tw, 'incident', 'Laborer cut his hand on rebar ends', 16, 'Tony Mendoza: first aid and four stitches at the clinic', 'medium', null, 4, 'closed'],
        [$tw, 'ppe', 'PPE issuance: helmets, gloves and harnesses for the new crew', 60, null, 'low', 12, 0, 'closed'],
        [$tw, 'permit', 'Hot work permit, welding at the 7th floor', 4, null, 'medium', null, 0, 'open'],
        [$rd, 'hazard', 'Open trench without barricade at Sta. 0+420', 50, 'Barricades and blinkers installed the same day', 'high', null, 0, 'closed'],
        [$rd, 'training', 'Traffic management for flagmen', 120, null, 'low', 8, 0, 'closed'],
    ] as [$pid, $kind, $title, $ago, $persons, $sev, $att, $lt, $st]) {
        DB::insert('cs_safety_records', ['ref_no' => Seq::next('cs_safety'), 'project_id' => $pid, 'kind' => $kind, 'record_date' => dm_d($ago), 'title' => $title, 'persons' => $persons, 'severity' => $sev,
            'attendees' => $att ?? 0, 'lost_time_hours' => $lt, 'corrective_action' => $kind === 'incident' ? 'Rebar caps installed on all exposed bars; gloves required at the rebar yard' : null,
            'valid_until' => $kind === 'permit' ? dm_d(-1) : null, 'status' => $st, 'created_by' => dm_user('safety')]);
    }

    // ---- requests for information and submittals
    foreach ([
        [$tw, 'rfi', 'Clarify the slab thickness at the 3rd floor transfer beam', 120, 'Structural drawing S-302 shows 150 mm, the schedule shows 200 mm.', 'answered', 'Use 200 mm; drawing revised (S-302 rev. B).'],
        [$tw, 'shop_drawing', 'Shop drawings: aluminum windows, typical floor', 40, 'For approval of the architect.', 'approved_as_noted', 'Approved; increase the sill height of W-4 to 1.0 m.'],
        [$tw, 'material_submittal', 'Material submittal: homogeneous tiles 600 x 600', 20, 'Three samples for the client\'s choice.', 'open', null],
        [$tw, 'rfi', 'Location of the fire pump room sleeves', 3, 'Mechanical and structural drawings do not agree on the sleeve location at grid D.', 'open', null],
        [$rd, 'rfi', 'Existing water line found at Sta. 0+380', 75, 'Maynilad line 300 mm crossing the excavation, 1.2 m deep.', 'closed', 'Relocated by Maynilad on 12 August; resume excavation.'],
        [$rd, 'technical_approval', 'Concrete mix design for PCCP', 55, 'Design mix for 650 psi flexural strength.', 'approved', 'Approved by the DPWH Materials Engineer.'],
    ] as [$pid, $kind, $subj, $ago, $desc, $st, $resp]) {
        DB::insert('cs_rfis', ['project_id' => $pid, 'kind' => $kind, 'ref_no' => Seq::next('cs_rfi'), 'subject' => $subj, 'description' => $desc, 'from_party' => 'Sample Builders & Development Corp.',
            'to_party' => $pid === $rd ? 'DPWH Project Engineer' : 'Northgate Land design team', 'date_sent' => dm_d($ago), 'date_due' => dm_d($ago - 7), 'response' => $resp,
            'response_date' => $resp ? dm_d($ago - 5) : null, 'status' => $st, 'created_by' => dm_user('siteeng')]);
    }

    // ---- the document register, with sample files and their revisions
    foreach ([
        [$tw, 'drawing', 'structural', 'S-302', 'Structural framing plan, 3rd floor', ['A', 'B'], 'approved'],
        [$tw, 'drawing', 'architectural', 'A-201', 'Floor plan, typical floor (4F to 12F)', ['A'], 'approved'],
        [$tw, 'specification', 'general', 'SPEC-01', 'Technical specifications, general construction', ['0'], 'approved'],
        [$tw, 'method_statement', 'structural', 'MS-CONC-01', 'Method statement: concreting of suspended slabs', ['A'], 'approved'],
        [$tw, 'permit', 'general', 'BP-2026-0418', 'Building permit', ['0'], 'approved'],
        [$rd, 'drawing', 'civil', 'C-101', 'Plan and profile, Sta. 0+000 to 0+650', ['A', 'B'], 'approved'],
        [$rd, 'drawing', 'civil', 'C-201', 'Drainage layout and details', ['A'], 'approved'],
        [$gm, 'drawing', 'architectural', 'AB-01', 'As-built drawings, gymnasium (set)', ['0'], 'for_review'],
    ] as [$pid, $cat, $disc, $no, $title, $revs, $st]) {
        $did = DB::insert('cs_documents', ['project_id' => $pid, 'contract_id' => CsProject::get($pid)['contract_id'], 'category' => $cat, 'discipline' => $disc, 'doc_no' => $no, 'title' => $title,
            'current_rev' => end($revs), 'status' => $st, 'created_by' => dm_user('siteeng')]);
        foreach ($revs as $i => $rev) {
            [$path, $size] = dm_store_file(dm_pdf($no . ' rev. ' . $rev . '  ' . $title, [CsProject::get($pid)['code'] . ' ' . CsProject::get($pid)['name'], 'Sample document generated for the demonstration data.',
                'Revision ' . $rev . ($i ? ': updated after the RFI response' : ': issued for construction'), 'Prepared by Sample Builders & Development Corp.']), 'pdf');
            DB::insert('cs_document_revisions', ['document_id' => $did, 'rev_no' => $rev, 'file_path' => $path, 'file_name' => $no . '_rev' . $rev . '.pdf', 'mime' => 'application/pdf', 'file_size' => $size,
                'notes' => $i ? 'Revised per RFI response' : 'Issued for construction', 'status' => $i < count($revs) - 1 ? 'superseded' : ($st === 'for_review' ? 'for_review' : 'approved'),
                'approved_by' => $st === 'approved' ? dm_user('pm') : null, 'approved_at' => $st === 'approved' ? dm_d(30 - $i * 10) . ' 10:00:00' : null, 'uploaded_by' => dm_user('siteeng')]);
        }
    }

    // ---- risks, issues, decisions, instructions and minutes
    foreach ([
        [$tw, 'risk', 'Steel price increase in the 4th quarter', 40, 'Rebar suppliers announced a 6% increase from November.', 'Pacific Steel', 3, 4, 780000, 0, 'Buy the rebar for 8F to 12F before November', 'open'],
        [$tw, 'risk', 'Typhoon season delays to the slab pours', 90, 'Rain days could push the topping-off.', null, 4, 3, 0, 15, 'Night pours when the forecast is clear; covers ready', 'open'],
        [$tw, 'issue', 'Late payment of progress billing no. 2', 12, 'Billing certified but unpaid past its due date; the client disputes the deduction for the ramp formworks.', 'Northgate Land', null, null, 0, 0,
            'Meeting with the client\'s finance team on Friday', 'open'],
        [$tw, 'decision', 'Use of a second concrete pump for the 9th-floor slab', 7, 'Approved by the project director to keep the pour within one day.', null, null, null, 85000, 0, null, 'closed'],
        [$tw, 'instruction', 'Client instruction no. 7: second basement access', 78, 'Basis of change order VO-0001.', 'Northgate Land', null, null, 0, 0, null, 'closed'],
        [$tw, 'meeting', 'Weekly coordination meeting no. 22', 2, 'Attendees: client, architect, Allied Electrical, AquaFlow. Look-ahead for the next two weeks agreed.', null, null, null, 0, 0, null, 'closed'],
        [$rd, 'issue', 'Traffic re-routing permit from the city', 100, 'MMDA permit needed for the night paving.', 'MMDA', null, null, 0, 0, 'Permit obtained', 'closed'],
        [$rd, 'risk', 'Flooding at the low section', 60, 'Low section at Sta. 0+420 floods during heavy rain.', null, 3, 3, 0, 5, 'Additional cross drain (VO-0002)', 'closed'],
    ] as [$pid, $kind, $title, $ago, $det, $party, $prob, $imp, $cost, $days, $resp, $st]) {
        DB::insert('cs_project_logs', ['project_id' => $pid, 'kind' => $kind, 'ref_no' => Seq::next('cs_log'), 'log_date' => dm_d($ago), 'title' => $title, 'details' => $det, 'party' => $party,
            'probability' => $prob, 'impact' => $imp, 'cost_impact' => $cost, 'days_impact' => $days, 'response' => $resp, 'due_date' => $st === 'open' ? dm_d(-14) : null, 'status' => $st,
            'created_by' => dm_user('pm')]);
    }

    // ---- a material request sent today, waiting for the project manager
    dm_as('siteeng');
    $mr = CsMaterials::saveMr(['project_id' => $tw, 'request_date' => dm_d(0), 'needed_date' => dm_d(-6), 'deliver_to' => 'site', 'purpose' => 'Safety helmets for the new finishing crew and tie wire for the 9th floor'], [
        ['item_id' => $S['items']['PPE-HH'], 'qty' => 25, 'unit' => 'pc', 'est_unit_cost' => 285, 'cost_code_id' => dm_cc('01-600'), 'description' => 'Safety helmet with chin strap'],
        ['item_id' => $S['items']['TW-16'], 'qty' => 400, 'unit' => 'kg', 'est_unit_cost' => 98, 'cost_code_id' => dm_cc('03-200'), 'description' => 'Tie wire #16']]);
    CsMaterials::submitMr($mr);

    // ---- the warehouse project: planning has started
    dm_as('pm2');
    DB::run('INSERT IGNORE INTO cs_project_members (project_id, user_id, role_label) VALUES (:p, :u, :r)', ['p' => $S['proj']['whse'], 'u' => dm_user('foreman'), 'r' => 'Site supervisor / foreman']);

    // ---- what changed after the year: a subcontractor's accreditation lapsed; the books are closed through the opening balances
    DB::run('UPDATE vendors SET accreditation_expiry = :d WHERE id = :v', ['d' => dm_d(12), 'v' => $S['vend']['colorcoat']]);
    dm_as('admin');
    Settings::set('closing_date', $S['open']);
    Audit::log('closing_date_change', 'admin', 'settings', null, 'Closing date', ['changes' => [['field' => 'closing_date', 'old' => '', 'new' => $S['open']]], 'reason' => 'Opening balances finalized']);

    // ---- notifications: what happened before this week has been read
    DB::run('UPDATE cs_notifications SET is_read = 1');
    DB::run("UPDATE cs_notifications n JOIN cs_approval_requests r ON n.link LIKE CONCAT('%id=', r.doc_id) AND r.status = 'pending' SET n.is_read = 0 WHERE n.kind = 'approval'");
    $_SESSION = [];
}
