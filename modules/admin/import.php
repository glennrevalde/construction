<?php
declare(strict_types=1);

/**
 * System Administration > Import from CSV
 * Loads customers, suppliers, fixed assets and whole transactions from spreadsheet files.
 * The columns of every type, its blank template and the checks are in app/Importer.php.
 *
 * Nothing is written until the whole file has been read and checked, and the writing itself runs in one
 * transaction: a file with a bad line leaves the books exactly as they were.
 */
$pageKey = 'admin.import';
$types = Importer::types();
$type = (string)(g('type') ?: p('type'));
if (!isset($types[$type])) {
    $type = '';
}

// ------------------------------------------------------------------ blank template
if (g('a') === 'template' && $type) {
    Access::require($pageKey, 'print');
    [$head, $rows] = Importer::template($type);
    csv_download('import_' . $type . '_template.csv', $head, $rows);
}

// ------------------------------------------------------------------ the import itself
$result = null;
handle_post(function () use (&$result, $type, $types, $pageKey) {
    Access::require($pageKey, 'create');
    if (!$type) {
        fail('Choose what to import.');
    }
    // the file that was just checked, imported without choosing it again
    if (p('mode') === 'import_checked') {
        $token = preg_replace('/[^a-f0-9]/', '', (string)p('token'));
        $kept = $_SESSION['import_checked'][$token] ?? null;
        if (!$kept || $kept['type'] !== $type || !is_file($kept['path'])) {
            fail('The checked file is no longer here. Choose the file and check it again.');
        }
        $rows = Importer::read($kept['path'], $type);
        $result = import_run($type, $rows, false, (string)$kept['name'], $pageKey);
        if (!$result['errors']) {
            @unlink($kept['path']);
            unset($_SESSION['import_checked'][$token]);
            flash('success', $result['ok'] . ' ' . strtolower($types[$type]['label']) . ' imported.');
        }
        return;
    }
    $up = $_FILES['file'] ?? null;
    if (!$up || ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        fail('Choose the CSV file to import.');
    }
    if ($up['error'] !== UPLOAD_ERR_OK) {
        fail('The file could not be uploaded' . (in_array($up['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? ': it is too large.' : '.'));
    }
    if (!preg_match('/\.(csv|txt)$/i', (string)$up['name'])) {
        fail('Choose a CSV file (.csv). In Excel: File > Save As > CSV (Comma delimited).');
    }
    $rows = Importer::read($up['tmp_name'], $type);
    $dry = p('mode') !== 'import';
    $result = import_run($type, $rows, $dry, (string)$up['name'], $pageKey);
    if ($dry && !$result['errors']) {
        // keep the checked file, so it can be imported from the result without being chosen again
        $result['token'] = import_keep_checked($up['tmp_name'], $type, (string)$up['name']);
    }
    if (!$dry && $result['ok'] > 0) {
        flash('success', $result['ok'] . ' ' . strtolower($types[$type]['label']) . ' imported.');
    }
});

/**
 * Keeps a file that passed the check in the company's own storage folder for a day, and remembers it in
 * the session: "Import these records" then imports exactly the file that was checked.
 */
function import_keep_checked(string $tmp, string $type, string $name): ?string
{
    $dir = Tenant::storage('imports');
    Master::protectDir($dir);
    foreach (glob($dir . '/checked_*.csv') ?: [] as $old) {
        if (filemtime($old) < time() - 86400) {
            @unlink($old);
        }
    }
    $token = bin2hex(random_bytes(12));
    $path = $dir . '/checked_' . $token . '.csv';
    if (!@move_uploaded_file($tmp, $path) && !@copy($tmp, $path)) {
        return null;
    }
    $kept = array_slice($_SESSION['import_checked'] ?? [], -4, null, true);
    $kept[$token] = ['type' => $type, 'name' => mb_substr($name, 0, 190), 'path' => $path, 'at' => time()];
    $_SESSION['import_checked'] = $kept;
    return $token;
}

// ------------------------------------------------------------------ one import type
/**
 * Reads the rows of one type and (unless $dry) writes them. Returns what happened.
 * Every type checks first and writes afterwards, so a file is either fully imported or not at all.
 */
function import_run(string $type, array $rows, bool $dry, string $fileName, string $pageKey): array
{
    $def = Importer::type($type);
    $errors = [];
    $created = [];
    $preview = [];
    $count = 0;

    $val = function (array $row, string $col) use ($def, &$errors) {
        return Importer::value($row, $col, $def['columns'][$col], $errors);
    };

    if (in_array($type, ['accounts', 'customers', 'vendors', 'fixed_assets', 'items'], true)) {
        $records = [];
        // the columns the file has (an existing item only gets those changed)
        $present = array_values(array_diff(array_keys($rows[0] ?? []), ['_line']));
        foreach ($rows as $row) {
            $rec = [];
            foreach (array_keys($def['columns']) as $col) {
                $rec[$col] = $val($row, $col);
            }
            $rec['_line'] = $row['_line'];
            if (in_array($type, ['items', 'customers', 'vendors'], true)) {
                $rec['_raw'] = $row;
                $rec['_present'] = $present;
            }
            $records[] = $rec;
        }
        $count = count($records);
        // items are checked in full even when a value was already wrong, so one check lists every problem of the file
        if (!$errors || $type === 'items') {
            import_check_records($type, $records, $errors);
        }
        if ($type === 'items') {
            // in the order of the file's lines
            $lineOf = fn(string $e): int => preg_match('/^Line (\d+)/', $e, $m) ? (int)$m[1] : 0;
            usort($errors, fn($x, $y) => $lineOf($x) <=> $lineOf($y));
        }
        $preview = $records;
        if (!$errors && !$dry) {
            DB::tx(function () use ($type, $records, &$created, $pageKey) {
                if ($type === 'items') {
                    $created = import_write_items($records, $pageKey);
                    return;
                }
                foreach ($records as $r) {
                    $created[] = import_write_record($type, $r, $pageKey);
                }
            });
        }
    } else {
        // transactions: lines are grouped into documents by their document number
        $keyCol = $type === 'pos_sales' ? 'receipt_no' : 'doc_no';
        $docs = Importer::group($rows, $keyCol);
        foreach ($docs as $no => $lines) {
            if ((string)$no === '') {
                $errors[] = 'Line ' . $lines[0]['_line'] . ': the document number is needed, so the lines can be grouped into documents.';
                continue;
            }
            $read = [];
            foreach ($lines as $row) {
                $rec = ['_line' => $row['_line']];
                foreach (array_keys($def['columns']) as $col) {
                    $rec[$col] = $val($row, $col);
                }
                $read[] = $rec;
            }
            $preview[] = ['no' => $no, 'date' => $read[0]['date'] ?? '', 'lines' => count($read),
                'who' => $read[0]['customer'] ?? $read[0]['vendor'] ?? $read[0]['payee'] ?? $read[0]['paid_to'] ?? null,
                'total' => import_doc_total($type, $read)];
            $docs[$no] = $read;
        }
        $count = count($docs);
        if (!$errors) {
            foreach ($docs as $no => $read) {
                import_check_doc($type, (string)$no, $read, $errors);
            }
        }
        if (!$errors && !$dry) {
            DB::tx(function () use ($type, $docs, &$created, $pageKey) {
                foreach ($docs as $no => $read) {
                    $created[] = import_write_doc($type, (string)$no, $read, $pageKey);
                }
            });
        }
    }

    $ok = $errors ? 0 : ($dry ? 0 : $count);
    if (!$dry) {
        Importer::logBatch($type, $fileName, $count, $ok, $errors ? $count : 0, $created, $errors);
        if (!$errors) {
            Audit::log('import', 'admin', 'import', null, $def['label'], ['reason' => $ok . ' record(s) from ' . $fileName, 'page_key' => $pageKey]);
        }
    }
    return ['type' => $type, 'dry' => $dry, 'count' => $count, 'ok' => $ok, 'errors' => $errors, 'preview' => $preview, 'created' => $created, 'file' => $fileName];
}

/**
 * Checks a whole file of records before anything is written, so "Check the file" reports what is
 * wrong with it. What only the writing can catch is caught there as well.
 */
function import_check_records(string $type, array &$records, array &$errors): void
{
    if ($type === 'items') {
        import_check_items($records, $errors);
        return;
    }
    if ($type !== 'accounts') {
        return;
    }
    $catOf = [
        'Bank' => 'Asset', 'Accounts Receivable' => 'Asset', 'Other Current Asset' => 'Asset', 'Fixed Asset' => 'Asset', 'Other Asset' => 'Asset',
        'Accounts Payable' => 'Liability', 'Credit Card' => 'Liability', 'Other Current Liability' => 'Liability', 'Long Term Liability' => 'Liability',
        'Equity' => 'Equity', 'Income' => 'Income', 'Other Income' => 'Income',
        'Cost of Goods Sold' => 'Expense', 'Expense' => 'Expense', 'Other Expense' => 'Expense',
    ];
    // the categories of the codes this file will have written by the time each line is reached
    $known = DB::pairs('SELECT code, category FROM accounts');
    $seen = [];
    foreach ($records as $r) {
        $code = trim((string)$r['code']);
        $category = $catOf[(string)$r['account_type']] ?? null;
        if ($code === '') {
            continue;
        }
        if (isset($seen[$code])) {
            $errors[] = 'Line ' . $r['_line'] . ': the code ' . $code . ' is in the file twice.';
            continue;
        }
        $seen[$code] = true;
        $parent = trim((string)$r['parent_code']);
        if ($parent !== '') {
            if ($parent === $code) {
                $errors[] = 'Line ' . $r['_line'] . ': the account ' . $code . ' cannot be a sub-account of itself.';
            } elseif (!isset($known[$parent])) {
                $errors[] = 'Line ' . $r['_line'] . ': the parent account ' . $parent . ' is not there yet. Put the parent earlier in the file, or add it first.';
            } elseif ($category !== null && $known[$parent] !== $category) {
                $errors[] = 'Line ' . $r['_line'] . ': the account ' . $code . ' cannot sit under ' . $parent
                    . '; a sub-account belongs to a parent of the same category (' . $known[$parent] . ').';
            }
        }
        if ($category !== null) {
            $known[$code] = $category;
        }
    }
}

function import_doc_total(string $type, array $read): float
{
    $t = 0.0;
    foreach ($read as $r) {
        if (isset($r['debit']) || isset($r['credit'])) {
            $t += (float)($r['debit'] ?? 0);
        } elseif (isset($r['amount'])) {
            $t += (float)$r['amount'];
        } else {
            $qty = (float)($r['qty'] ?? 0);
            $rate = (float)($r['rate'] ?? $r['price'] ?? 0);
            $t += r2($qty * $rate * (1 - (float)($r['discount_pct'] ?? 0) / 100));
        }
    }
    return r2($t);
}

/** Checks that make sense only for a whole document */
function import_check_doc(string $type, string $no, array $read, array &$errors): void
{
    if ($type === 'journals') {
        $dr = 0.0;
        $cr = 0.0;
        foreach ($read as $r) {
            $dr += (float)$r['debit'];
            $cr += (float)$r['credit'];
        }
        if (abs($dr - $cr) > 0.004) {
            $errors[] = 'Document ' . $no . ': the debits (' . money($dr) . ') and the credits (' . money($cr) . ') are not equal.';
        }
        if ($dr <= 0) {
            $errors[] = 'Document ' . $no . ': the entry has no amounts.';
        }
    }
    if ($type === 'cash_vouchers') {
        $net = 0.0;
        foreach ($read as $r) {
            $net += (float)$r['debit'] - (float)$r['credit'];
        }
        if ($net <= 0) {
            $errors[] = 'Document ' . $no . ': the debits less the credits must be more than nothing; that is the cash paid out.';
        }
    }
    $dates = array_unique(array_filter(array_column($read, 'date')));
    if (count($dates) > 1) {
        $errors[] = 'Document ' . $no . ': its lines carry different dates (' . implode(', ', $dates) . ').';
    }
    if ($dup = import_already_there($type, $no, $read)) {
        $errors[] = 'Document ' . $no . ' is already in the books as ' . $dup . '. Take it out of the file, or change its document number.';
    }
}

/**
 * The same file imported twice would post everything twice, so a document number that is already
 * in the books stops the import. Returns the transaction number that holds it, or null.
 */
function import_already_there(string $type, string $no, array $read): ?string
{
    $map = ['invoices' => 'invoice', 'sales_receipts' => 'sales_receipt', 'sales_orders' => 'sales_order',
        'purchase_orders' => 'purchase_order', 'receive_bills' => 'bill', 'checks' => 'check',
        'cash_vouchers' => 'cash_voucher', 'journals' => 'journal', 'pos_sales' => 'pos_sale'];
    $txnType = $map[$type] ?? '';
    if (!$txnType) {
        return null;
    }
    if ($type === 'pos_sales') {
        $col = 'receipt_no';
        $value = $no;
    } elseif ($type === 'checks') {
        $col = 'txn_no';
        $value = trim((string)($read[0]['check_no'] ?? ''));
    } elseif ($type === 'receive_bills') {
        $col = 'ref_no';
        $value = trim((string)($read[0]['ref_no'] ?? '')) ?: $no;
    } else {
        $col = 'ref_no';
        $value = trim((string)($read[0]['ref_no'] ?? '')) ?: $no;
    }
    if ($value === '') {
        return null;
    }
    return DB::value("SELECT txn_no FROM transactions WHERE txn_type = :t AND $col = :v AND is_voided = 0 LIMIT 1",
        ['t' => $txnType, 'v' => $value]) ?: null;
}

/** Writes one customer, supplier or fixed asset */
/**
 * An existing customer or supplier is updated only with the cells of its line that have something in them:
 * a column the file does not have, or a blank cell, keeps what is saved (and an inactive record stays inactive).
 */
function import_filled_only(array $r, array $map, array $d): array
{
    $out = [];
    foreach ($map as $col => $field) {
        if (trim((string)($r['_raw'][$col] ?? '')) !== '') {
            $out[$field] = $d[$field];
        }
    }
    return $out;
}

function import_write_record(string $type, array $r, string $pageKey): array
{
    if ($type === 'accounts') {
        $catOf = [
            'Bank' => 'Asset', 'Accounts Receivable' => 'Asset', 'Other Current Asset' => 'Asset', 'Fixed Asset' => 'Asset', 'Other Asset' => 'Asset',
            'Accounts Payable' => 'Liability', 'Credit Card' => 'Liability', 'Other Current Liability' => 'Liability', 'Long Term Liability' => 'Liability',
            'Equity' => 'Equity', 'Income' => 'Income', 'Other Income' => 'Income',
            'Cost of Goods Sold' => 'Expense', 'Expense' => 'Expense', 'Other Expense' => 'Expense',
        ];
        $code = trim((string)$r['code']);
        $type2 = (string)$r['account_type'];
        $category = $catOf[$type2] ?? null;
        if (!$category) {
            fail('"' . $type2 . '" is not an account type (line ' . $r['_line'] . ').');
        }
        $parentId = null;
        $parentCode = trim((string)$r['parent_code']);
        if ($parentCode !== '') {
            $parent = DB::row('SELECT * FROM accounts WHERE code = :c', ['c' => $parentCode]);
            if (!$parent) {
                fail('The parent account ' . $parentCode . ' is not there yet (line ' . $r['_line'] . '). Put the parent earlier in the file, or add it first.');
            }
            if ($parent['category'] !== $category) {
                fail('The account ' . $code . ' cannot sit under ' . $parentCode . ': a sub-account belongs to a parent of the same category (line ' . $r['_line'] . ').');
            }
            $parentId = (int)$parent['id'];
        }
        $normal = (string)$r['normal_balance'];
        $d = [
            'code' => mb_substr($code, 0, 20),
            'name' => mb_substr(trim((string)$r['name']), 0, 120),
            'account_type' => $type2,
            'category' => $category,
            'normal_balance' => in_array($normal, ['Debit', 'Credit'], true) ? $normal : (in_array($category, ['Asset', 'Expense'], true) ? 'Debit' : 'Credit'),
            'parent_id' => $parentId,
            'description' => trim((string)$r['description']) ?: null,
            'bank_account_no' => trim((string)$r['bank_account_no']) ?: null,
            'with_sl' => (int)$r['with_sl'],
            'is_active' => trim((string)($r['is_active'] ?? '')) === '' ? 1 : (int)$r['is_active'],
        ];
        $existing = DB::row('SELECT * FROM accounts WHERE code = :c', ['c' => $d['code']]);
        if ($existing) {
            if ((int)$existing['is_system'] === 1 && $existing['category'] !== $d['category']) {
                fail('The account ' . $d['code'] . ' is one the modules post to; it cannot be moved to another category (line ' . $r['_line'] . ').');
            }
            if ($existing['category'] !== $d['category']
                && (int)DB::value('SELECT COUNT(*) FROM journal_lines WHERE account_id = :a', ['a' => $existing['id']]) > 0) {
                fail('The account ' . $d['code'] . ' has transactions; it cannot be moved to another category (line ' . $r['_line'] . ').');
            }
            DB::update('accounts', $d, 'id = :id', ['id' => $existing['id']]);
            Accts::reset();
            Audit::log('import_update', 'coa', 'account', (int)$existing['id'], $d['code'] . ' ' . $d['name'], ['page_key' => $pageKey]);
            return ['type' => 'account', 'id' => (int)$existing['id'], 'name' => $d['code'] . ' ' . $d['name'], 'updated' => true];
        }
        $id = DB::insert('accounts', $d);
        Accts::reset();
        Audit::log('import_create', 'coa', 'account', $id, $d['code'] . ' ' . $d['name'], ['snapshot' => $d, 'page_key' => $pageKey]);
        return ['type' => 'account', 'id' => $id, 'name' => $d['code'] . ' ' . $d['name']];
    }

    if ($type === 'customers') {
        $name = (string)$r['name'];
        $parentId = null;
        if (str_contains($name, ':')) {
            [$parent, $job] = array_map('trim', explode(':', $name, 2));
            $parentId = Importer::ref('customers', $parent);
            if (!$parentId) {
                fail('The customer "' . $parent . '" of the job "' . $job . '" is not on the list yet (line ' . $r['_line'] . ').');
            }
            $name = $job;
        }
        $existing = DB::value('SELECT id FROM customers WHERE name = :n AND parent_id ' . ($parentId ? '= :p' : 'IS NULL'),
            ['n' => $name] + ($parentId ? ['p' => $parentId] : []));
        // column of the file => field of the customer record
        $map = ['company_name' => 'company_name', 'contact_person' => 'contact_person', 'phone' => 'phone', 'mobile' => 'mobile', 'email' => 'email',
            'billing_address' => 'billing_address', 'shipping_address' => 'shipping_address', 'tin' => 'tin', 'terms' => 'terms_id',
            'credit_limit' => 'credit_limit', 'price_level' => 'price_level_id', 'tax_code' => 'tax_code_id', 'customer_type' => 'customer_type_id',
            'sales_rep' => 'sales_rep_id', 'allow_pos_charge' => 'allow_pos_charge', 'notes' => 'notes'];
        $d = [
            'parent_id' => $parentId, 'name' => $name, 'company_name' => $r['company_name'], 'contact_person' => $r['contact_person'],
            'phone' => $r['phone'], 'mobile' => $r['mobile'], 'email' => $r['email'], 'billing_address' => $r['billing_address'],
            'shipping_address' => $r['shipping_address'], 'tin' => $r['tin'], 'terms_id' => $r['terms'], 'credit_limit' => (float)$r['credit_limit'],
            'price_level_id' => $r['price_level'], 'tax_code_id' => $r['tax_code'], 'customer_type_id' => $r['customer_type'],
            'sales_rep_id' => $r['sales_rep'], 'allow_pos_charge' => (int)$r['allow_pos_charge'], 'notes' => $r['notes'], 'is_active' => 1,
        ];
        if ($existing) {
            $changes = import_filled_only($r, $map, $d);
            $before = DB::row('SELECT * FROM customers WHERE id = :id', ['id' => $existing]);
            if ($changes) {
                DB::update('customers', $changes, 'id = :id', ['id' => $existing]);
            }
            Audit::log('import_update', 'cust', 'customer', (int)$existing, $name, ['page_key' => $pageKey,
                'before' => array_intersect_key((array)$before, $changes), 'after' => $changes]);
            return ['type' => 'customer', 'id' => (int)$existing, 'name' => $name, 'updated' => true];
        }
        $id = DB::insert('customers', $d);
        Audit::log('import_create', 'cust', 'customer', $id, $name, ['snapshot' => $d, 'page_key' => $pageKey]);
        return ['type' => 'customer', 'id' => $id, 'name' => $name];
    }

    if ($type === 'vendors') {
        $name = (string)$r['name'];
        $existing = DB::value('SELECT id FROM vendors WHERE name = :n', ['n' => $name]);
        $map = ['company_name' => 'company_name', 'contact_person' => 'contact_person', 'phone' => 'phone', 'fax' => 'fax', 'email' => 'email',
            'address' => 'address', 'tin' => 'tin', 'terms' => 'terms_id', 'credit_limit' => 'credit_limit',
            'default_expense_account' => 'default_expense_account_id', 'account_no' => 'account_no', 'notes' => 'notes'];
        $d = [
            'name' => $name, 'company_name' => $r['company_name'], 'contact_person' => $r['contact_person'], 'phone' => $r['phone'],
            'fax' => $r['fax'], 'email' => $r['email'], 'address' => $r['address'], 'tin' => $r['tin'], 'terms_id' => $r['terms'],
            'credit_limit' => (float)$r['credit_limit'], 'default_expense_account_id' => $r['default_expense_account'],
            'account_no' => $r['account_no'], 'notes' => $r['notes'], 'is_active' => 1,
        ];
        if ($existing) {
            $changes = import_filled_only($r, $map, $d);
            $before = DB::row('SELECT * FROM vendors WHERE id = :id', ['id' => $existing]);
            if ($changes) {
                DB::update('vendors', $changes, 'id = :id', ['id' => $existing]);
            }
            Audit::log('import_update', 'vend', 'vendor', (int)$existing, $name, ['page_key' => $pageKey,
                'before' => array_intersect_key((array)$before, $changes), 'after' => $changes]);
            return ['type' => 'vendor', 'id' => (int)$existing, 'name' => $name, 'updated' => true];
        }
        $id = DB::insert('vendors', $d);
        Audit::log('import_create', 'vend', 'vendor', $id, $name, ['snapshot' => $d, 'page_key' => $pageKey]);
        return ['type' => 'vendor', 'id' => $id, 'name' => $name];
    }

    // fixed asset
    $locId = null;
    $loc = trim((string)$r['location']);
    if ($loc !== '') {
        $locId = (int)(DB::value('SELECT id FROM asset_locations WHERE name = :n', ['n' => $loc]) ?: DB::insert('asset_locations', ['name' => $loc]));
    }
    $no = trim((string)$r['asset_no']) ?: Seq::next('asset');
    if (DB::value('SELECT id FROM fixed_assets WHERE asset_no = :n', ['n' => $no])) {
        fail('The asset number ' . $no . ' is already used (line ' . $r['_line'] . ').');
    }
    $d = [
        'asset_no' => $no, 'description' => $r['description'], 'category_id' => $r['category'], 'location' => $loc ?: null, 'location_id' => $locId,
        'serial_no' => $r['serial_no'], 'vendor_id' => $r['vendor'], 'class_id' => $r['class'], 'acquisition_date' => $r['acquisition_date'],
        'cost' => (float)$r['cost'], 'salvage_value' => (float)$r['salvage_value'], 'useful_life_years' => (float)$r['useful_life_years'],
        'method' => $r['method'] ?: 'straight_line', 'opening_accum_dep' => (float)$r['opening_accum_dep'], 'opening_through' => $r['opening_through'],
        'notes' => $r['notes'],
    ];
    if ($d['useful_life_years'] <= 0) {
        fail('The useful life must be more than zero (line ' . $r['_line'] . ').');
    }
    if (!empty($r['post_opening'])) {
        // the asset is not on the books yet: write the same opening-balance entry the New Asset page writes
        $cat = DB::row('SELECT * FROM asset_categories WHERE id = :id', ['id' => $d['category_id']]);
        $cost = (float)$d['cost'];
        $opening = (float)$d['opening_accum_dep'];
        if ($opening > 0 && !$d['opening_through']) {
            fail('Fill "Depreciated through" for ' . $d['description'] . ', which carries accumulated depreciation (line ' . $r['_line'] . ').');
        }
        $lines = [
            ['line_kind' => 'journal', 'account_id' => (int)$cat['asset_account_id'], 'amount' => $cost, 'description' => 'Opening balance - ' . $d['description']],
            ['line_kind' => 'journal', 'account_id' => Accts::get('opening_equity'), 'amount' => -r2($cost - $opening), 'description' => 'Opening balance'],
        ];
        if ($opening > 0) {
            $lines[] = ['line_kind' => 'journal', 'account_id' => (int)$cat['accum_dep_account_id'], 'amount' => -$opening, 'description' => 'Opening accumulated depreciation'];
        }
        $d['acquisition_txn_id'] = Txn::save('opening_balance',
            ['txn_date' => $d['acquisition_date'], 'memo' => 'Opening balance of asset ' . $no, 'class_id' => $d['class_id']],
            $lines, null, ['reason' => 'Imported from CSV']);
    }
    $id = DB::insert('fixed_assets', $d);
    Audit::log('import_create', 'fa', 'asset', $id, $no . ' ' . $r['description'], ['snapshot' => $d, 'amount' => (float)$r['cost'], 'page_key' => $pageKey]);
    return ['type' => 'asset', 'id' => $id, 'name' => $no . ' ' . $r['description']];
}

/** Writes one whole document (all the lines that carry the same document number) */
function import_write_doc(string $type, string $no, array $read, string $pageKey): array
{
    $first = $read[0];
    $date = (string)$first['date'];
    $opt = ['reason' => 'Imported from CSV, document ' . $no];

    if (in_array($type, ['invoices', 'sales_receipts', 'sales_orders'], true)) {
        $txnType = ['invoices' => 'invoice', 'sales_receipts' => 'sales_receipt', 'sales_orders' => 'sales_order'][$type];
        $lines = [];
        foreach ($read as $r) {
            $lines[] = import_item_line($r, 'sales');
        }
        $h = [
            'txn_date' => $date, 'customer_id' => $first['customer'], 'ref_no' => $no, 'memo' => $first['memo'],
            'site_id' => $first['site'], 'class_id' => $first['class'], 'terms_id' => $first['terms'],
        ];
        if ($txnType === 'invoice') {
            $h['account_id'] = Accts::get('ar');
            $h['due_date'] = Txn::dueDate($date, $first['terms'] ? (int)$first['terms'] : null);
        } elseif ($txnType === 'sales_receipt') {
            $h['account_id'] = $first['deposit_to'] ?: Accts::get('undeposited_funds');
        } else {
            $h['status'] = 'open';
        }
        $id = Txn::save($txnType, $h, $lines, null, $opt);
        return ['type' => $txnType, 'id' => $id, 'name' => $no];
    }

    if (in_array($type, ['purchase_orders', 'receive_bills'], true)) {
        $txnType = $type === 'purchase_orders' ? 'purchase_order' : 'bill';
        $lines = [];
        foreach ($read as $r) {
            $lines[] = import_item_line($r, 'purchase');
        }
        $h = ['txn_date' => $date, 'vendor_id' => $first['vendor'], 'ref_no' => $first['ref_no'] ?: $no, 'memo' => $first['memo'],
            'site_id' => $first['site'], 'class_id' => $first['class']];
        if ($txnType === 'bill') {
            $terms = DB::value('SELECT terms_id FROM vendors WHERE id = :v', ['v' => $first['vendor']]);
            $h['account_id'] = Accts::get('ap');
            $h['terms_id'] = $terms ?: null;
            $h['due_date'] = Txn::dueDate($date, $terms ? (int)$terms : null);
        } else {
            $h['status'] = 'open';
            $h['due_date'] = null;
        }
        $id = Txn::save($txnType, $h, $lines, null, $opt);
        return ['type' => $txnType, 'id' => $id, 'name' => $no];
    }

    if ($type === 'checks') {
        $lines = [];
        foreach ($read as $r) {
            $lines[] = ['line_kind' => 'expense', 'account_id' => $r['account'], 'description' => $r['description'],
                'amount' => (float)$r['amount'], 'tax_code_id' => $r['tax_code'],
                'tax_amount' => $r['tax_code'] ? r2((float)$r['amount'] * tax_rate((int)$r['tax_code']) / 100) : 0,
                'class_id' => $r['class']];
        }
        $payee = trim((string)$first['payee']);
        $vendorId = Importer::ref('vendors', $payee);
        $h = ['txn_date' => $date, 'account_id' => $first['bank_account'], 'vendor_id' => $vendorId,
            'payee_name' => $vendorId ? null : $payee, 'txn_no' => trim((string)$first['check_no']) ?: null,
            'memo' => $first['memo'], 'class_id' => $first['class']];
        $id = Txn::save('check', $h, $lines, null, $opt);
        return ['type' => 'check', 'id' => $id, 'name' => $no];
    }

    if ($type === 'cash_vouchers') {
        $lines = [];
        $net = 0.0;
        foreach ($read as $r) {
            $amt = r2((float)$r['debit'] - (float)$r['credit']);
            $net += $amt;
            $lines[] = ['line_kind' => 'journal', 'account_id' => $r['account'], 'description' => $r['memo'], 'amount' => $amt, 'class_id' => $r['class']];
        }
        $paidTo = trim((string)$first['paid_to']);
        $h = ['txn_date' => $date, 'account_id' => $first['cash_account'], 'ref_no' => $first['ref_no'] ?: $no,
            'memo' => $first['particulars'], 'class_id' => $first['class'], 'subtotal' => r2($net), 'total' => r2($net), 'status' => 'open',
            'customer_id' => Importer::ref('customers', $paidTo), 'vendor_id' => Importer::ref('vendors', $paidTo),
            'other_name_id' => Importer::ref('other_names', $paidTo)];
        if (!$h['customer_id'] && !$h['vendor_id'] && !$h['other_name_id']) {
            $h['payee_name'] = $paidTo;
        }
        $id = Txn::save('cash_voucher', $h, $lines, null, $opt + ['keep_totals' => true]);
        return ['type' => 'cash_voucher', 'id' => $id, 'name' => $no];
    }

    if ($type === 'journals') {
        $lines = [];
        foreach ($read as $r) {
            $name = trim((string)$r['name']);
            $lines[] = ['line_kind' => 'journal', 'account_id' => $r['account'], 'description' => $r['description'],
                'amount' => r2((float)$r['debit'] - (float)$r['credit']), 'class_id' => $r['class'],
                'customer_id' => Importer::ref('customers', $name), 'vendor_id' => Importer::ref('vendors', $name),
                'other_name_id' => Importer::ref('other_names', $name)];
        }
        $h = ['txn_date' => $date, 'ref_no' => $first['ref_no'] ?: $no, 'memo' => $first['memo'], 'class_id' => $first['class']];
        $id = Txn::save('journal', $h, $lines, null, $opt);
        return ['type' => 'journal', 'id' => $id, 'name' => $no];
    }

    // POS sale
    $terminalId = (int)$first['terminal'];
    $terminal = DB::row('SELECT * FROM pos_terminals WHERE id = :id', ['id' => $terminalId]);
    $shift = DB::row("SELECT * FROM pos_shifts WHERE terminal_id = :t AND closed_at IS NULL ORDER BY id DESC LIMIT 1", ['t' => $terminalId]);
    if (!$shift) {
        $shiftId = DB::insert('pos_shifts', ['terminal_id' => $terminalId, 'user_id' => Auth::id(), 'business_date' => $date,
            'opening_float' => 0, 'opened_at' => $date . ' 08:00:00']);
        $shift = DB::row('SELECT * FROM pos_shifts WHERE id = :id', ['id' => $shiftId]);
    }
    $lines = [];
    $total = 0.0;
    foreach ($read as $r) {
        $amount = r2((float)$r['qty'] * (float)$r['price'] * (1 - (float)$r['discount_pct'] / 100));
        $tax = $r['tax_code'] ? r2($amount * tax_rate((int)$r['tax_code']) / 100) : 0.0;
        $lines[] = ['line_kind' => 'item', 'item_id' => $r['item'], 'description' => $r['description'], 'qty' => (float)$r['qty'],
            'rate' => (float)$r['price'], 'discount_pct' => (float)$r['discount_pct'], 'amount' => $amount,
            'tax_code_id' => $r['tax_code'], 'tax_amount' => $tax, 'site_id' => (int)$terminal['site_id'], 'class_id' => $r['class']];
        $total += $amount + $tax;
    }
    $total = r2($total);
    $h = ['txn_date' => $date, 'site_id' => (int)$terminal['site_id'], 'terminal_id' => $terminalId, 'pos_shift_id' => (int)$shift['id'],
        'customer_id' => $first['customer'], 'class_id' => $first['class'] ?: ($terminal['class_id'] ?? null),
        'receipt_no' => $no, 'status' => 'suspended', 'memo' => 'Imported POS sale'];
    $id = DB::tx(function () use ($h, $lines, $first, $total, $opt) {
        $rid = Txn::save('pos_sale', $h, $lines, null, $opt + ['audit_action' => 'import']);
        $methodId = $first['payment_method'] ?: (int)DB::value("SELECT id FROM payment_methods WHERE method_type = 'cash' ORDER BY id LIMIT 1");
        DB::insert('pos_tenders', ['txn_id' => $rid, 'payment_method_id' => $methodId, 'amount' => $total, 'reference' => 'Imported']);
        $saved = Txn::load($rid);
        return Txn::save('pos_sale', ['txn_date' => $saved['txn_date'], 'status' => 'completed', 'amount_tendered' => $total, 'change_given' => 0],
            array_map(fn($l) => array_intersect_key($l, array_flip(['id', 'line_kind', 'item_id', 'description', 'qty', 'rate', 'discount_pct', 'amount', 'tax_code_id', 'tax_amount', 'site_id', 'class_id'])), Txn::lines($rid)),
            $rid, $opt);
    });
    return ['type' => 'pos_sale', 'id' => $id, 'name' => $no];
}

/** One item line of an imported sales or purchase document */
function import_item_line(array $r, string $mode): array
{
    $qty = (float)$r['qty'];
    $rate = (float)$r['rate'];
    $disc = (float)($r['discount_pct'] ?? 0);
    $amount = r2($qty * $rate * (1 - $disc / 100));
    return [
        'line_kind' => 'item', 'item_id' => $r['item'], 'description' => $r['description'], 'qty' => $qty, 'rate' => $rate,
        'discount_pct' => $disc, 'amount' => $amount, 'tax_code_id' => $r['tax_code'],
        'tax_amount' => $r['tax_code'] ? r2($amount * tax_rate((int)$r['tax_code']) / 100) : 0,
        'site_id' => $r['site'], 'class_id' => $r['class'],
    ];
}

// ------------------------------------------------------------------ inventory items

/** "BOLT x 4; NUT x 4" (also BOLT*4, BOLT=4, BOLT:4, one per line): [[code, qty], ...]; a bad piece is reported */
function import_parse_components(string $raw, int $line, array &$errors): array
{
    $out = [];
    foreach (preg_split('/[;\r\n|]+/', $raw) ?: [] as $piece) {
        $piece = trim($piece);
        if ($piece === '') {
            continue;
        }
        if (preg_match('/^(.+?)\s*(?:\s[xX×]\s*|\*|=|:)\s*([0-9][0-9.,]*)$/u', $piece, $m)) {
            $qty = to_num($m[2]);
            $code = trim($m[1]);
        } else {
            $qty = 1.0;
            $code = $piece;
        }
        if ($qty <= 0) {
            $errors[] = 'Line ' . $line . ': the component "' . $piece . '" needs a quantity more than zero.';
            continue;
        }
        $out[] = [$code, $qty];
    }
    return $out;
}

/** An item by code, display name or barcode, read fresh (not from a cache: items are being added) */
function import_find_item(string $value): ?array
{
    foreach (['code', 'name', 'barcode'] as $c) {
        $r = DB::row("SELECT id, code, item_type FROM items WHERE $c = :v ORDER BY id LIMIT 1", ['v' => $value]);
        if ($r) {
            return $r;
        }
    }
    return null;
}

/**
 * Checks every item line before anything is written, and notes on each line what the writing will do
 * (_type, _existing, _category_new, _uom, _components, _opening, _prices).
 */
function import_check_items(array &$records, array &$errors): void
{
    $viewCosts = Access::viewCosts();
    $closing = Settings::closingDate();
    $closedOk = (bool)Access::special('closed_period_changes');
    $sites = DB::pairs('SELECT id, CONCAT(code, \' - \', name) FROM sites WHERE is_active = 1 ORDER BY id');
    $levels = DB::pairs('SELECT id, name FROM price_levels WHERE is_active = 1 ORDER BY id');
    $inFile = [];        // lower-case code => [line, type] of the lines already read
    $barcodes = [];
    $componentTypes = ['inventory', 'assembly', 'service', 'non_inventory', 'other_charge'];
    foreach ($records as &$r) {
        $line = (int)$r['_line'];
        $raw = $r['_raw'];
        $present = $r['_present'];
        $has = fn(string $c): bool => in_array($c, $present, true);
        $blank = fn(string $c): bool => trim((string)($raw[$c] ?? '')) === '';
        $code = trim((string)$r['code']);
        if ($code === '') {
            continue;                                    // "needed" was already reported
        }
        $key = mb_strtolower($code);
        if (isset($inFile[$key])) {
            $errors[] = 'Line ' . $line . ': the code ' . $code . ' is already on line ' . $inFile[$key][0] . ' of the file.';
            continue;
        }
        foreach (['code' => 40, 'name' => 120, 'barcode' => 60, 'description' => 255, 'purchase_description' => 255, 'category' => 60, 'unit' => 20] as $c => $max) {
            if (mb_strlen((string)($r[$c] ?? '')) > $max) {
                $errors[] = 'Line ' . $line . ': "' . $c . '" is longer than ' . $max . ' characters.';
            }
        }
        $ex = DB::row('SELECT * FROM items WHERE code = :c', ['c' => $code]);
        $r['_existing'] = $ex ? (int)$ex['id'] : null;
        if (!$ex && trim((string)($r['name'] ?? '')) === '') {
            $errors[] = 'Line ' . $line . ': the new item ' . $code . ' needs its display name ("name").';
        }

        // type
        $type = Importer::itemType((string)($r['item_type'] ?? ''));
        if ($type === null) {
            $errors[] = 'Line ' . $line . ': "' . $r['item_type'] . '" is not an item type. Use one of: ' . implode(', ', Importer::ITEM_TYPES) . '.';
            continue;
        }
        if ($type === '') {
            $type = $ex ? $ex['item_type'] : 'inventory';
        }
        if ($ex && $type !== $ex['item_type']) {
            $errors[] = 'Line ' . $line . ': ' . $code . ' is already on the Item List as ' . (Importer::ITEM_TYPES[$ex['item_type']] ?? $ex['item_type'])
                . '; the type of an existing item cannot be changed by an import.';
            continue;
        }
        // an inventory item that still has a balance can not be made inactive by an import either
        if ($ex && (int)$ex['is_active'] === 1 && $has('is_active') && !$blank('is_active') && empty($r['is_active']) && ($why = Stock::inactiveRefusal($ex))) {
            $errors[] = 'Line ' . $line . ': ' . $why;
        }
        $r['_type'] = $type;
        $tracked = in_array($type, ['inventory', 'assembly'], true);
        $inFile[$key] = [$line, $type];

        // barcode
        $bc = trim((string)($r['barcode'] ?? ''));
        if ($bc !== '') {
            if (isset($barcodes[$bc])) {
                $errors[] = 'Line ' . $line . ': the barcode ' . $bc . ' is also on line ' . $barcodes[$bc] . '.';
            }
            $barcodes[$bc] = $line;
            $other = DB::value('SELECT code FROM items WHERE barcode = :b AND id <> :id', ['b' => $bc, 'id' => $ex['id'] ?? 0]);
            if ($other) {
                $errors[] = 'Line ' . $line . ': the barcode ' . $bc . ' already belongs to the item ' . $other . '.';
            }
        }

        // category: added when new
        $cat = trim((string)($r['category'] ?? ''));
        $r['_category_id'] = $cat !== '' ? Importer::ref('item_categories', $cat) : null;
        $r['_category_new'] = $cat !== '' && !$r['_category_id'];

        // units of measure
        $r['_uom'] = ['sales' => null, 'purchase' => null];
        if (!empty($r['uom_set'])) {
            $units = Uom::units((int)$r['uom_set']);
            foreach (['sales' => 'sales_unit', 'purchase' => 'purchase_unit'] as $k => $col) {
                $want = mb_strtolower(trim((string)($r[$col] ?? '')));
                if ($want === '') {
                    continue;
                }
                foreach ($units as $u) {
                    if (mb_strtolower($u['code']) === $want || mb_strtolower($u['name']) === $want) {
                        $r['_uom'][$k] = (int)$u['id'];
                    }
                }
                if (!$r['_uom'][$k]) {
                    $errors[] = 'Line ' . $line . ': "' . $r[$col] . '" is not a unit of the U/M set "' . $raw['uom_set'] . '".';
                }
            }
            if ($ex && (int)$ex['uom_set_id'] !== (int)$r['uom_set']
                && DB::value('SELECT 1 FROM transaction_lines l JOIN uom_units u ON u.id = l.uom_id WHERE l.item_id = :i AND u.set_id <> :s LIMIT 1', ['i' => $ex['id'], 's' => (int)$r['uom_set']])) {
                $errors[] = 'Line ' . $line . ': ' . $code . ' was already entered in units of its present U/M set, so its set cannot be changed.';
            }
        } elseif (!$blank('sales_unit') || !$blank('purchase_unit')) {
            $errors[] = 'Line ' . $line . ': a sales or purchase unit needs the U/M set it belongs to (column "uom_set").';
        }

        // amounts
        foreach (['cost', 'sales_price', 'reorder_point', 'max_stock', 'build_point', 'opening_qty', 'opening_cost', 'discount_pct'] as $c) {
            if ((float)($r[$c] ?? 0) < 0) {
                $errors[] = 'Line ' . $line . ': "' . $c . '" cannot be below zero.';
            }
        }
        if ((float)($r['discount_pct'] ?? 0) > 100) {
            $errors[] = 'Line ' . $line . ': the discount % cannot be more than 100.';
        }
        if (!$viewCosts && (!$blank('cost') || !$blank('opening_cost') || (float)($r['opening_qty'] ?? 0) > 0)) {
            $errors[] = 'Line ' . $line . ': your role cannot see item costs, so costs and opening quantities cannot be imported. Leave those columns empty or ask an administrator.';
        }
        $r['_prices'] = [];
        foreach ($levels as $lid => $lname) {
            $c = 'price_level_' . $lid;
            if ($has($c)) {
                $r['_prices'][$lid] = $blank($c) ? null : (float)$r[$c];
                if (!$blank($c) && (float)$r[$c] < 0) {
                    $errors[] = 'Line ' . $line . ': the price for "' . $lname . '" cannot be below zero.';
                }
            }
        }

        // accounts that do not fit the type
        foreach (['cogs_account', 'asset_account'] as $c) {
            if (!empty($r[$c]) && !$tracked) {
                $errors[] = 'Line ' . $line . ': "' . $c . '" is only for inventory parts and assemblies.';
            }
        }

        // components
        $r['_components'] = null;
        $compRaw = trim((string)($r['components'] ?? ''));
        if (in_array($type, ['assembly', 'group'], true)) {
            if ($compRaw !== '') {
                $r['_components'] = [];
                foreach (import_parse_components($compRaw, $line, $errors) as [$cc, $cq]) {
                    $ck = mb_strtolower($cc);
                    if ($ck === $key) {
                        $errors[] = 'Line ' . $line . ': ' . $code . ' cannot be a component of itself.';
                        continue;
                    }
                    if (isset($inFile[$ck])) {
                        $ctype = $inFile[$ck][1];
                    } else {
                        $found = import_find_item($cc);
                        if (!$found) {
                            $errors[] = 'Line ' . $line . ': the component "' . $cc . '" is not on the Item List. Add it first, or put it earlier in the file.';
                            continue;
                        }
                        $ctype = $found['item_type'];
                        $ck = mb_strtolower($found['code']);
                    }
                    if (!in_array($ctype, $componentTypes, true)) {
                        $errors[] = 'Line ' . $line . ': "' . $cc . '" is a ' . strtolower(Importer::ITEM_TYPES[$ctype] ?? $ctype) . ' item and cannot be a component.';
                        continue;
                    }
                    $r['_components'][$ck] = ($r['_components'][$ck] ?? 0) + $cq;
                }
            } elseif (!$ex) {
                $errors[] = 'Line ' . $line . ': ' . Importer::ITEM_TYPES[$type] . ' ' . $code . ' needs its components ("components" column, e.g. BOLT x 4; NUT x 4).';
            }
        } elseif ($compRaw !== '') {
            $errors[] = 'Line ' . $line . ': only assemblies and groups have components.';
        }

        // opening quantity
        $r['_opening'] = null;
        $oq = (float)($r['opening_qty'] ?? 0);
        if ($oq > 0) {
            if (!$tracked) {
                $errors[] = 'Line ' . $line . ': only inventory parts and assemblies carry a quantity on hand.';
            } elseif ($ex) {
                $errors[] = 'Line ' . $line . ': ' . $code . ' is already on the Item List. Change its stock with Adjust Quantity/Value on Hand, not with an opening quantity.';
            } else {
                $site = $r['opening_site'] ?: (count($sites) === 1 ? (int)array_key_first($sites) : null);
                if (!$site) {
                    $errors[] = 'Line ' . $line . ': name the site the opening quantity is at ("opening_site").';
                } elseif (!isset($sites[$site])) {
                    $errors[] = 'Line ' . $line . ': the site "' . $raw['opening_site'] . '" is not active.';
                }
                $date = $r['opening_date'] ?: today();
                if ($closing && !$closedOk && $date <= $closing) {
                    $errors[] = 'Line ' . $line . ': the opening date ' . fdate($date) . ' is on or before the closing date (' . fdate($closing) . ').';
                }
                $unit = $blank('opening_cost') ? (float)($r['cost'] ?? 0) : (float)$r['opening_cost'];
                $r['_opening'] = ['qty' => $oq, 'unit' => round($unit, 4), 'value' => r2($oq * $unit), 'site' => (int)$site, 'date' => $date,
                    'site_name' => $sites[$site] ?? ''];
            }
        } elseif (!$blank('opening_cost') && (float)$r['opening_cost'] > 0) {
            $errors[] = 'Line ' . $line . ': an opening cost needs an opening quantity.';
        }
    }
    unset($r);
}

/**
 * Writes the checked item lines, then one opening inventory adjustment per site and date.
 * Returns what was created or updated.
 */
function import_write_items(array $records, string $pageKey): array
{
    $created = [];
    $made = [];                    // lower-case code => id, of this file
    $categories = [];
    $openings = [];
    $viewCosts = Access::viewCosts();
    foreach ($records as $r) {
        $type = $r['_type'];
        $tracked = in_array($type, ['inventory', 'assembly'], true);
        $present = $r['_present'];
        $raw = $r['_raw'];
        $ex = $r['_existing'] ? DB::row('SELECT * FROM items WHERE id = :id', ['id' => $r['_existing']]) : null;
        $new = $ex === null;
        $use = fn(string $c): bool => $new || in_array($c, $present, true);
        $text = fn(string $c, int $max) => ($v = trim((string)($r[$c] ?? ''))) === '' ? null : mb_substr($v, 0, $max);

        $d = [];
        if ($new) {
            $d['item_type'] = $type;
            $d['code'] = mb_substr(trim((string)$r['code']), 0, 40);
        }
        if ($use('name')) {
            $d['name'] = mb_substr(trim((string)$r['name']), 0, 120);
        }
        foreach (['barcode' => 60, 'description' => 255, 'purchase_description' => 255] as $c => $max) {
            if ($use($c)) {
                $d[$c] = $text($c, $max);
            }
        }
        if ($use('category')) {
            $cat = trim((string)($r['category'] ?? ''));
            if ($cat === '') {
                $d['category_id'] = null;
            } else {
                $d['category_id'] = $r['_category_id'] ?: ($categories[mb_strtolower($cat)] ??= (int)(DB::value('SELECT id FROM item_categories WHERE name = :n', ['n' => $cat])
                    ?: DB::insert('item_categories', ['name' => mb_substr($cat, 0, 60)])));
            }
        }
        if ($use('uom_set')) {
            // a U/M set fixes the unit stock is kept in, and the first units offered come from that set
            if (!empty($r['uom_set'])) {
                $d['uom_set_id'] = (int)$r['uom_set'];
                $d['unit'] = Uom::set((int)$r['uom_set'])['base_unit'];
                $d['sales_uom_id'] = $r['_uom']['sales'];
                $d['purchase_uom_id'] = $r['_uom']['purchase'];
            } else {
                $d['uom_set_id'] = null;
                $d['sales_uom_id'] = null;
                $d['purchase_uom_id'] = null;
                $d['unit'] = $text('unit', 20) ?? ($ex['unit'] ?? null) ?? 'pc';
            }
        } elseif ($use('unit') && empty($ex['uom_set_id'])) {
            $d['unit'] = $text('unit', 20) ?? ($ex['unit'] ?? null) ?? 'pc';
        }
        if ($use('cost') && $viewCosts) {
            $d['cost'] = round((float)($r['cost'] ?? 0), 4);
        }
        foreach (['sales_price' => 4, 'discount_pct' => 2, 'reorder_point' => 4, 'max_stock' => 4, 'build_point' => 4] as $c => $dec) {
            if ($use($c)) {
                $d[$c] = round((float)($r[$c] ?? 0), $dec);
            }
        }
        if ($use('tax_code')) {
            $d['tax_code_id'] = $r['tax_code'] ?: null;
        }
        if ($use('preferred_vendor')) {
            $d['preferred_vendor_id'] = $r['preferred_vendor'] ?: null;
        }
        if ($use('expense_account')) {
            $d['expense_account_id'] = $r['expense_account'] ?: null;
        }
        // the accounts the modules post to: a blank cell keeps what is there (or the module default for a new item)
        $income = $r['income_account'] ?: ($ex['income_account_id'] ?? null);
        if (!$income && in_array($type, ['service', 'inventory', 'non_inventory', 'assembly', 'other_charge'], true)) {
            $income = Accts::get('sales_income');
        } elseif (!$income && $type === 'discount') {
            $income = Accts::get('sales_discounts');
        }
        $d['income_account_id'] = $income ?: null;
        if ($tracked) {
            $d['asset_account_id'] = $r['asset_account'] ?: ($ex['asset_account_id'] ?? null) ?: Accts::get('inventory_asset');
            $d['cogs_account_id'] = $r['cogs_account'] ?: ($ex['cogs_account_id'] ?? null) ?: Accts::get('cogs');
        }
        if ($use('is_active')) {
            $d['is_active'] = trim((string)($raw['is_active'] ?? '')) === '' ? 1 : (int)$r['is_active'];
        }

        if ($new) {
            $id = DB::insert('items', $d);
            Audit::log('import_create', 'inv', 'item', $id, $d['code'] . ' ' . $d['name'], ['snapshot' => $d, 'page_key' => $pageKey]);
        } else {
            $id = (int)$ex['id'];
            DB::update('items', $d, 'id = :id', ['id' => $id]);
            Audit::log('import_update', 'inv', 'item', $id, $ex['code'] . ' ' . ($d['name'] ?? $ex['name']),
                ['before' => array_intersect_key($ex, $d), 'after' => $d, 'page_key' => $pageKey]);
        }
        $made[mb_strtolower(trim((string)$r['code']))] = $id;

        // prices of the price levels in the file: a blank cell goes back to the level's own percentage
        foreach ($r['_prices'] as $levelId => $price) {
            DB::run('DELETE FROM price_level_items WHERE price_level_id = :p AND item_id = :i', ['p' => $levelId, 'i' => $id]);
            if ($price !== null) {
                DB::insert('price_level_items', ['price_level_id' => $levelId, 'item_id' => $id, 'custom_price' => round($price, 4)]);
            }
        }

        // components of assemblies and groups
        if ($r['_components']) {
            DB::run('DELETE FROM item_components WHERE parent_item_id = :p', ['p' => $id]);
            foreach ($r['_components'] as $ck => $cq) {
                $cid = $made[$ck] ?? (import_find_item($ck)['id'] ?? null);
                if (!$cid) {
                    fail('The component ' . $ck . ' of ' . $r['code'] . ' could not be found (line ' . $r['_line'] . ').');
                }
                DB::insert('item_components', ['parent_item_id' => $id, 'component_item_id' => (int)$cid, 'qty' => $cq]);
            }
        }

        if ($r['_opening']) {
            $o = $r['_opening'];
            $openings[$o['site'] . '|' . $o['date']][] = ['line_kind' => 'item', 'item_id' => $id, 'qty' => $o['qty'], 'rate' => $o['unit'],
                'amount' => $o['value'], 'site_id' => $o['site'], 'description' => 'Opening balance'];
        }
        $created[] = ['type' => 'item', 'id' => $id, 'name' => trim((string)$r['code']) . ' ' . ($d['name'] ?? $ex['name'] ?? ''), 'updated' => !$new];
    }

    // opening quantities: one inventory adjustment per site and date, against Opening Balance Equity
    foreach ($openings as $k => $lines) {
        [$site, $date] = explode('|', $k, 2);
        $txnId = Txn::save('inventory_adjustment', [
            'txn_date' => $date, 'site_id' => (int)$site, 'account_id' => Accts::get('opening_equity'),
            'memo' => 'Opening stock of ' . count($lines) . ' imported item(s)',
        ], $lines, null, ['reason' => 'Opening balance imported from CSV']);
        $created[] = ['type' => 'inventory_adjustment', 'id' => $txnId, 'name' => 'Opening stock, ' . fdate($date)];
    }
    return $created;
}

// ------------------------------------------------------------------ the page
$groups = [];
foreach ($types as $k => $t) {
    $groups[$t['group']][$k] = $t['label'];
}
$recent = DB::all('SELECT b.*, u.username FROM import_batches b LEFT JOIN users u ON u.id = b.created_by ORDER BY b.id DESC LIMIT 15');
layout_start('Import from CSV', ['actions' => page_link('admin.backup', [], 'Backup & Restore', 'btn-outline-secondary', 'database')]);
?>
<p class="text-muted small">Load records from a spreadsheet. Save the sheet as <strong>CSV</strong> in Excel, with the column names on the first line.
  Download the template of the kind you are importing to get the exact columns; a column you do not need can be left out, and the order does not matter.
  <strong>Check the file</strong> first: it reads the whole file and reports every problem without changing anything.</p>

<div class="row g-3">
  <div class="col-lg-5">
    <form method="post" enctype="multipart/form-data" class="card" data-no-class-check><div class="card-header fw-semibold">Import a file</div><div class="card-body">
      <?= csrf_field() ?>
      <label class="form-label">What are you importing?</label>
      <select class="form-select form-select-sm mb-1" name="type" id="impType" required onchange="location = '<?= h(url($pageKey)) ?>&type=' + this.value">
        <option value="">— choose —</option>
        <?php foreach ($groups as $gname => $items): ?>
          <optgroup label="<?= h($gname) ?>"><?php foreach ($items as $k => $label): ?>
            <option value="<?= h($k) ?>" <?= $type === $k ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?></optgroup>
        <?php endforeach; ?>
      </select>
      <?php if ($type): ?>
        <div class="form-text mb-2"><?= h($types[$type]['note']) ?></div>
        <div class="mb-3"><a class="btn btn-sm btn-outline-secondary" href="<?= h(url($pageKey, ['a' => 'template', 'type' => $type])) ?>"><i class="bi bi-download"></i> Download the blank template</a></div>
        <label class="form-label d-block">CSV file</label>
        <input class="form-control form-control-sm" type="file" name="file" accept=".csv,text/csv,text/plain" required>
        <div class="d-flex gap-2 mt-3 flex-wrap">
          <button class="btn btn-primary" name="mode" value="check"><i class="bi bi-search"></i> Check the file</button>
          <button class="btn btn-outline-secondary" name="mode" value="import" data-confirm="Check and import this file in one step? Records are added to the books."><i class="bi bi-upload"></i> Check and import in one step</button>
        </div>
        <div class="form-text">Checking reads every line and reports every problem without writing anything. When the file is in order, it is imported from the result below with one click.</div>
      <?php endif; ?>
    </div></form>
  </div>

  <div class="col-lg-7">
    <?php if ($type): $def = $types[$type]; ?>
    <div class="card"><div class="card-header fw-semibold">The columns of "<?= h($def['label']) ?>"</div>
      <div class="table-responsive" style="max-height:420px"><table class="table table-sm table-tight mb-0">
        <thead><tr><th>Column</th><th>What goes in it</th><th>Needed</th></tr></thead>
        <?php foreach ($def['columns'] as $col => $spec): if (($spec[0] ?? '') === '(not used)') { continue; } ?>
          <tr><td><code><?= h($col) ?></code></td><td class="small"><?= h($spec[0]) ?></td><td class="small"><?= !empty($spec[2]) ? 'yes' : '' ?></td></tr>
        <?php endforeach; ?>
      </table></div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($result): $def = $types[$result['type']]; ?>
<div class="card mt-3 <?= $result['errors'] ? 'border-danger' : ($result['dry'] ? 'border-primary' : 'border-success') ?>">
  <div class="card-header fw-semibold"><?= $result['errors'] ? 'The file was not imported' : ($result['dry'] ? 'The file was checked' : 'Imported') ?>:
    <?= h($result['file']) ?>, <?= (int)$result['count'] ?> <?= $result['type'] === 'items' ? 'item(s)' : (in_array($result['type'], ['accounts', 'customers', 'vendors', 'fixed_assets'], true) ? 'record(s)' : 'document(s)') ?></div>
  <div class="card-body">
    <?php if ($result['errors']): ?>
      <p class="mb-2">Nothing was written. Correct these lines in the spreadsheet and try again:</p>
      <ul class="small mb-0 text-danger" style="max-height:320px;overflow:auto">
        <?php foreach (array_slice($result['errors'], 0, 200) as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
        <?php if (count($result['errors']) > 200): ?><li>… and <?= count($result['errors']) - 200 ?> more.</li><?php endif; ?>
      </ul>
    <?php elseif ($result['dry']): ?>
      <div class="import-ready">
        <p class="text-success mb-0"><i class="bi bi-check2-circle"></i> The file is in order: nothing was written yet. Look the lines over, then import them.</p>
        <?php if (!empty($result['token'])): ?>
        <form method="post" data-no-class-check class="d-flex gap-2 flex-wrap"><?= csrf_field() ?>
          <input type="hidden" name="type" value="<?= h($result['type']) ?>"><input type="hidden" name="token" value="<?= h($result['token']) ?>">
          <button class="btn btn-primary" name="mode" value="import_checked" data-confirm="Import the <?= (int)$result['count'] ?> checked line(s) now?"><i class="bi bi-upload"></i> Import these <?= (int)$result['count'] ?> <?= $result['type'] === 'items' ? 'item(s)' : 'record(s)' ?></button>
        </form>
        <?php endif; ?>
      </div>
      <?php if ($result['type'] === 'items'):
          $viewCostsHere = Access::viewCosts();
          $newItems = count(array_filter($result['preview'], fn($p) => empty($p['_existing'])));
          $opening = array_filter(array_column($result['preview'], '_opening'));
          $newCats = array_unique(array_filter(array_map(fn($p) => !empty($p['_category_new']) ? trim((string)$p['category']) : null, $result['preview'])));
      ?>
        <div class="import-facts">
          <div><span class="n"><?= $newItems ?></span> new item(s)</div>
          <div><span class="n"><?= count($result['preview']) - $newItems ?></span> to update</div>
          <div><span class="n"><?= count($opening) ?></span> with opening stock<?= $viewCostsHere && $opening ? ', ' . money(array_sum(array_column($opening, 'value'))) : '' ?></div>
          <?php if ($newCats): ?><div>New categories: <?= h(implode(', ', $newCats)) ?></div><?php endif; ?>
        </div>
      <?php endif; ?>
      <div class="table-responsive" style="max-height:360px"><table class="table table-sm table-tight mb-0 import-preview">
        <?php if ($result['type'] === 'items'): ?>
          <thead><tr><th>Line</th><th>Item</th><th>Type</th><th>Category</th><th>Unit</th><?= $viewCostsHere ? '<th class="num">Cost</th>' : '' ?><th class="num">Price</th><th>Opening stock</th><th>Will be</th></tr></thead>
          <?php foreach (array_slice($result['preview'], 0, 200) as $p): $o = $p['_opening'] ?? null; ?>
            <tr><td><?= (int)$p['_line'] ?></td>
              <td><span class="fw-semibold"><?= h((string)$p['code']) ?></span><div class="small text-muted"><?= h((string)$p['name']) ?></div></td>
              <td class="small"><?= h(Importer::ITEM_TYPES[$p['_type'] ?? ''] ?? '') ?></td>
              <td class="small"><?= h((string)($p['category'] ?? '')) ?><?= !empty($p['_category_new']) ? ' <span class="text-muted">(new)</span>' : '' ?></td>
              <td class="small"><?= h(!empty($p['uom_set']) ? (string)$p['_raw']['uom_set'] : ((string)($p['unit'] ?? '') ?: 'pc')) ?></td>
              <?= $viewCostsHere ? '<td class="num">' . money((float)($p['cost'] ?? 0)) . '</td>' : '' ?>
              <td class="num"><?= money((float)($p['sales_price'] ?? 0)) ?></td>
              <td class="small"><?= $o ? qty($o['qty']) . ' at ' . h($o['site_name']) . ($viewCostsHere ? ', ' . money($o['value']) : '') . ', ' . h(fdate($o['date'])) : '' ?></td>
              <td class="small"><?= empty($p['_existing']) ? 'Added' : 'Updated (' . count(array_diff($p['_present'], ['code'])) . ' column' . (count(array_diff($p['_present'], ['code'])) === 1 ? '' : 's') . ')' ?></td></tr>
          <?php endforeach; ?>
        <?php elseif (in_array($result['type'], ['accounts', 'customers', 'vendors', 'fixed_assets'], true)): ?>
          <thead><tr><th>Line</th><th>Name</th><th>Details</th></tr></thead>
          <?php foreach (array_slice($result['preview'], 0, 100) as $p): ?>
            <tr><td><?= (int)$p['_line'] ?></td>
              <td><?= h(trim((string)($p['code'] ?? '') . ' ' . (string)($p['name'] ?? $p['description'] ?? ''))) ?></td>
              <td class="small"><?= h(implode(', ', array_filter([
                  $p['account_type'] ?? '', $p['company_name'] ?? '', $p['email'] ?? '',
                  !empty($p['parent_code']) ? 'under ' . $p['parent_code'] : '',
                  !empty($p['with_sl']) ? 'With SL' : '',
                  isset($p['cost']) ? money((float)$p['cost']) : '',
              ]))) ?></td></tr>
          <?php endforeach; ?>
        <?php else: ?>
          <thead><tr><th>Document</th><th>Date</th><th class="num">Lines</th><th class="num">Total</th></tr></thead>
          <?php foreach (array_slice($result['preview'], 0, 100) as $p): ?>
            <tr><td><?= h((string)$p['no']) ?></td><td><?= h(fdate($p['date'])) ?></td><td class="num"><?= (int)$p['lines'] ?></td><td class="num"><?= money($p['total']) ?></td></tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </table></div>
      <?php if (count($result['preview']) > ($result['type'] === 'items' ? 200 : 100)): ?><div class="small text-muted mt-1">… and <?= count($result['preview']) - ($result['type'] === 'items' ? 200 : 100) ?> more.</div><?php endif; ?>
    <?php else: ?>
      <p class="text-success mb-2"><i class="bi bi-check2-circle"></i> <?= (int)$result['ok'] ?> <?= h(strtolower($def['label'])) ?> written.</p>
      <div class="table-responsive" style="max-height:320px"><table class="table table-sm table-tight mb-0">
        <thead><tr><th>What was created</th><th></th></tr></thead>
        <?php foreach (array_slice($result['created'], 0, 100) as $cr): ?>
          <tr><td><?= h((string)($cr['name'] ?? '')) ?><?= !empty($cr['updated']) ? ' <span class="text-muted small">(updated)</span>' : '' ?></td>
            <td class="small"><?= $cr['type'] === 'item' ? safe_link('inv.items', ['a' => 'edit', 'id' => $cr['id']], 'Item') : (in_array($cr['type'], ['customer', 'vendor', 'asset', 'account'], true) ? h(ucfirst($cr['type'])) : Txn::link((int)$cr['id'], Txn::label($cr['type']))) ?></td></tr>
        <?php endforeach; ?>
      </table></div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="card mt-3"><div class="card-header fw-semibold">Recent imports</div>
  <div class="table-responsive"><table class="table table-sm table-tight mb-0">
    <thead><tr><th>When</th><th>What</th><th>File</th><th class="num">Records</th><th class="num">Written</th><th>By</th><th>Notes</th></tr></thead>
    <?php foreach ($recent as $b): ?>
      <tr><td class="text-nowrap"><?= h(fdatetime($b['created_at'])) ?></td><td><?= h($types[$b['import_type']]['label'] ?? $b['import_type']) ?></td>
        <td class="small"><?= h($b['file_name']) ?></td><td class="num"><?= (int)$b['rows_total'] ?></td>
        <td class="num <?= (int)$b['rows_ok'] ? 'text-success' : 'text-muted' ?>"><?= (int)$b['rows_ok'] ?></td><td class="small"><?= h($b['username']) ?></td>
        <td class="small text-muted"><?= h(mb_strimwidth((string)$b['messages'], 0, 80, '…')) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$recent): ?><tr><td colspan="7" class="text-muted py-3">Nothing has been imported yet.</td></tr><?php endif; ?>
  </table></div>
</div>
<?php
layout_end();
