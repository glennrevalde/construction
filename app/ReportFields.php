<?php
declare(strict_types=1);

/**
 * The fields a report can add that are not among its own columns ("Modify Report", as in QuickBooks).
 *
 * Every row of a report points at records: the transaction it was taken from, the customer or vendor, the
 * item, the account, the fixed asset. The report says so through the links on its cells (every number that
 * opens a transaction, every name that opens a customer) and, when it wants to, through an explicit '_ref'
 * on the row: ['_ref' => ['txn' => 12, 'customer' => 3]]. From those records, every field below can be added
 * to the report as a column: on the Columns tab of the Modify Report panel they are listed under the report's
 * own columns, and the ones ticked are looked up (in one query per kind) only when they print.
 *
 * A related column is an 'optional' column, so it is never on a report until somebody adds it, and a layout
 * saved before the field existed does not suddenly show it.
 */
final class ReportFields
{
    public const PREFIX = 'rf_';

    /** kind => [heading of the group, fields: key => [label, type, sensitive]] */
    public const KINDS = [
        'txn' => ['Transaction', [
            'type'        => ['Transaction type', 'text'],
            'date'        => ['Transaction date', 'date'],
            'num'         => ['Transaction number', 'text'],
            'name'        => ['Name (customer, supplier or payee)', 'text'],
            'ref'         => ['Reference number', 'text'],
            'memo'        => ['Memo', 'text'],
            'class'       => ['Class', 'text'],
            'site'        => ['Site', 'text'],
            'terms'       => ['Terms', 'text'],
            'due'         => ['Due date', 'date'],
            'ship'        => ['Ship date', 'date'],
            'status'      => ['Status', 'text'],
            'total'       => ['Transaction total', 'money'],
            'created_by'  => ['Entered by', 'text'],
            'created_at'  => ['Entered on', 'datetime'],
            'approved_by' => ['Approved by', 'text'],
        ]],
        'customer' => ['Customer', [
            'name'        => ['Customer name', 'text'],
            'company'     => ['Company name', 'text'],
            'contact'     => ['Contact person', 'text'],
            'phone'       => ['Phone', 'text'],
            'mobile'      => ['Mobile', 'text'],
            'email'       => ['Email', 'text'],
            'address'     => ['Billing address', 'text'],
            'tin'         => ['TIN', 'text'],
            'terms'       => ['Terms', 'text'],
            'type'        => ['Customer type', 'text'],
            'rep'         => ['Sales rep', 'text'],
            'price_level' => ['Price level', 'text'],
            'limit'       => ['Credit limit', 'money'],
            'balance'     => ['Balance owed today', 'money'],
        ]],
        'vendor' => ['Supplier', [
            'name'       => ['Supplier name', 'text'],
            'company'    => ['Company name', 'text'],
            'contact'    => ['Contact person', 'text'],
            'phone'      => ['Phone', 'text'],
            'fax'        => ['Fax', 'text'],
            'email'      => ['Email', 'text'],
            'address'    => ['Address', 'text'],
            'tin'        => ['TIN', 'text'],
            'terms'      => ['Terms', 'text'],
            'account_no' => ['Our account number with them', 'text'],
            'limit'      => ['Credit limit', 'money'],
            'balance'    => ['Balance owed today', 'money'],
        ]],
        'item' => ['Item', [
            'code'      => ['Item code', 'text'],
            'name'      => ['Item name', 'text'],
            'barcode'   => ['Barcode', 'text'],
            'desc'      => ['Sales description', 'text'],
            'category'  => ['Category', 'text'],
            'type'      => ['Item type', 'text'],
            'unit'      => ['Unit', 'text'],
            'cost'      => ['Cost', 'money', 'cost'],
            'avg_cost'  => ['Average cost', 'money', 'cost'],
            'price'     => ['Sales price', 'money'],
            'reorder'   => ['Reorder point', 'qty'],
            'vendor'    => ['Preferred supplier', 'text'],
            'on_hand'   => ['On hand today, all sites', 'qty'],
            'pdesc'     => ['Purchase description', 'text'],
            'tax'       => ['Tax code', 'text'],
            'max'       => ['Maximum stock', 'qty'],
            'status'    => ['Item status', 'text'],
            'value'     => ['Asset value today (on hand x average cost)', 'money', 'cost'],
            'retail'    => ['Retail value today (on hand x sales price)', 'money'],
            'gp_unit'   => ['Gross profit per unit (price less average cost)', 'money', 'cost'],
            'gp_pct'    => ['Gross profit % per unit', 'pct', 'cost'],
            'markup'    => ['Markup % on average cost', 'pct', 'cost'],
        ]],
        'account' => ['Account', [
            'code'     => ['Account number', 'text'],
            'name'     => ['Account name', 'text'],
            'type'     => ['Account type', 'text'],
            'category' => ['Category', 'text'],
            'parent'   => ['Parent account', 'text'],
            'desc'     => ['Description', 'text'],
            'bank_no'  => ['Bank account number', 'text', 'bank'],
        ]],
        'asset' => ['Fixed asset', [
            'no'       => ['Asset number', 'text'],
            'name'     => ['Asset', 'text'],
            'category' => ['Category', 'text'],
            'location' => ['Location', 'text'],
            'serial'   => ['Serial number', 'text'],
            'acquired' => ['Date acquired', 'date'],
            'cost'     => ['Cost', 'money'],
            'status'   => ['Status', 'text'],
        ]],
    ];

    /** The pages whose "id" names a record, and the kind of record */
    private const PAGE_KIND = ['cust.center' => 'customer', 'vend.center' => 'vendor', 'inv.center' => 'item', 'fa.register' => 'asset'];
    /** The address parameters that name a record */
    private const PARAM_KIND = ['customer_id' => 'customer', 'vendor_id' => 'vendor', 'item_id' => 'item', 'account_id' => 'account', 'asset_id' => 'asset'];

    /**
     * Reads which records the rows point at (into $row['_ref'][kind] = id) and returns the optional
     * columns for the kinds found: [rows, columns].
     */
    public static function extend(array $rows): array
    {
        static $txnPages = null;
        if ($txnPages === null) {
            $txnPages = [];
            foreach (Txn::TYPES as [$label, $page]) {
                $txnPages[$page] = true;
            }
        }
        $kinds = [];
        foreach ($rows as &$r) {
            $ref = is_array($r['_ref'] ?? null) ? $r['_ref'] : [];
            foreach ((array)($r['_links'] ?? []) as $link) {
                if (!is_string($link) || $link === '' || !str_contains($link, 'p=')) {
                    continue;
                }
                parse_str((string)(parse_url($link, PHP_URL_QUERY) ?? ''), $q);
                $page = (string)($q['p'] ?? '');
                $id = (int)($q['id'] ?? 0);
                if ($id > 0 && isset($txnPages[$page]) && ($q['a'] ?? '') === 'view') {
                    $ref['txn'] ??= $id;
                } elseif ($id > 0 && isset(self::PAGE_KIND[$page]) && (($q['a'] ?? 'view') === 'view')) {
                    $ref[self::PAGE_KIND[$page]] ??= $id;
                }
                foreach (self::PARAM_KIND as $param => $kind) {
                    if ((int)($q[$param] ?? 0) > 0) {
                        $ref[$kind] ??= (int)$q[$param];
                    }
                }
            }
            if ($ref) {
                $r['_ref'] = $ref;
            }
        }
        unset($r);
        // a row that comes from a transaction also comes from its customer or supplier
        $viaTxn = [];
        foreach ($rows as $i => $r) {
            $tid = (int)($r['_ref']['txn'] ?? 0);
            if ($tid > 0 && empty($r['_ref']['customer']) && empty($r['_ref']['vendor'])) {
                $viaTxn[$tid][] = $i;
            }
        }
        foreach (array_chunk(array_keys($viaTxn), 500) as $chunk) {
            [$in, $p] = DB::in('t', $chunk);
            foreach (DB::all("SELECT id, customer_id, vendor_id FROM transactions WHERE id IN $in AND (customer_id IS NOT NULL OR vendor_id IS NOT NULL)", $p) as $t) {
                foreach ($viaTxn[(int)$t['id']] as $i) {
                    if ((int)$t['customer_id'] > 0) {
                        $rows[$i]['_ref']['customer'] = (int)$t['customer_id'];
                    } elseif ((int)$t['vendor_id'] > 0) {
                        $rows[$i]['_ref']['vendor'] = (int)$t['vendor_id'];
                    }
                }
            }
        }
        foreach ($rows as $r) {
            foreach ((array)($r['_ref'] ?? []) as $k => $id) {
                if ((int)$id > 0 && isset(self::KINDS[$k])) {
                    $kinds[$k] = true;
                }
            }
        }
        $cols = [];
        foreach (self::KINDS as $kind => [$group, $fields]) {
            if (!isset($kinds[$kind])) {
                continue;
            }
            foreach ($fields as $field => $f) {
                $cols[] = ['key' => self::PREFIX . $kind . '_' . $field, 'label' => $f[0], 'type' => $f[1], 'optional' => true,
                    'related' => $group, 'sensitive' => $f[2] ?? null];
            }
        }
        return [$rows, $cols];
    }

    /** Is this a column added from a related record? */
    public static function isRelated(array $c): bool
    {
        return !empty($c['related']) || str_starts_with((string)($c['key'] ?? ''), self::PREFIX);
    }

    /** Fills the related columns among $cols into the rows, one lookup per kind of record */
    public static function fill(array $rows, array $cols): array
    {
        $want = [];      // kind => [field => key]
        foreach ($cols as $c) {
            if (!self::isRelated($c) || !preg_match('/^rf_([a-z]+)_([a-z_]+)$/', (string)$c['key'], $m) || !isset(self::KINDS[$m[1]][1][$m[2]])) {
                continue;
            }
            $want[$m[1]][$m[2]] = $c['key'];
        }
        if (!$want) {
            return $rows;
        }
        foreach ($want as $kind => $fields) {
            $ids = [];
            foreach ($rows as $r) {
                $id = (int)($r['_ref'][$kind] ?? 0);
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
            if (!$ids) {
                continue;
            }
            $records = self::records($kind, array_keys($ids), array_keys($fields));
            foreach ($rows as &$r) {
                $id = (int)($r['_ref'][$kind] ?? 0);
                if ($id <= 0 || !isset($records[$id])) {
                    continue;
                }
                foreach ($fields as $field => $key) {
                    $r[$key] = $records[$id][$field] ?? '';
                }
            }
            unset($r);
        }
        return $rows;
    }

    /** The records of one kind, by id, with the fields asked for */
    private static function records(string $kind, array $ids, array $fields): array
    {
        [$in, $p] = DB::in('r', $ids);
        $out = [];
        switch ($kind) {
            case 'txn':
                $rows = DB::all("SELECT t.id, t.txn_type, t.txn_no, t.txn_date, t.ref_no, t.memo, t.due_date, t.ship_date, t.status, t.is_voided, t.is_pending, t.total,
                                        t.created_at, t.payee_name, c.name AS cust, v.name AS vend, cl.name AS class_name, s.name AS site_name, tm.name AS terms_name,
                                        u.full_name AS creator, a.full_name AS approver
                                   FROM transactions t LEFT JOIN customers c ON c.id = t.customer_id LEFT JOIN vendors v ON v.id = t.vendor_id
                                   LEFT JOIN classes cl ON cl.id = t.class_id LEFT JOIN sites s ON s.id = t.site_id LEFT JOIN terms tm ON tm.id = t.terms_id
                                   LEFT JOIN users u ON u.id = t.created_by LEFT JOIN users a ON a.id = t.approved_by
                                  WHERE t.id IN $in", $p);
                foreach ($rows as $t) {
                    $status = (int)$t['is_voided'] ? 'Voided' : ((int)$t['is_pending'] ? 'Pending' : ucfirst((string)$t['status']));
                    $out[(int)$t['id']] = ['type' => Txn::label((string)$t['txn_type']), 'date' => $t['txn_date'], 'num' => $t['txn_no'],
                        'name' => $t['cust'] ?: ($t['vend'] ?: $t['payee_name']), 'ref' => $t['ref_no'], 'memo' => $t['memo'], 'class' => $t['class_name'],
                        'site' => $t['site_name'], 'terms' => $t['terms_name'], 'due' => $t['due_date'], 'ship' => $t['ship_date'], 'status' => $status,
                        'total' => $t['total'], 'created_by' => $t['creator'], 'created_at' => $t['created_at'], 'approved_by' => $t['approver']];
                }
                break;
            case 'customer':
                $rows = DB::all("SELECT c.*, tm.name AS terms_name, ct.name AS type_name, sr.name AS rep_name, pl.name AS level_name
                                   FROM customers c LEFT JOIN terms tm ON tm.id = c.terms_id LEFT JOIN customer_types ct ON ct.id = c.customer_type_id
                                   LEFT JOIN sales_reps sr ON sr.id = c.sales_rep_id LEFT JOIN price_levels pl ON pl.id = c.price_level_id
                                  WHERE c.id IN $in", $p);
                foreach ($rows as $c) {
                    $out[(int)$c['id']] = ['name' => $c['name'], 'company' => $c['company_name'], 'contact' => $c['contact_person'], 'phone' => $c['phone'],
                        'mobile' => $c['mobile'], 'email' => $c['email'], 'address' => self::oneLine($c['billing_address']), 'tin' => $c['tin'], 'terms' => $c['terms_name'],
                        'type' => $c['type_name'], 'rep' => $c['rep_name'], 'price_level' => $c['level_name'], 'limit' => $c['credit_limit'],
                        'balance' => in_array('balance', $fields, true) ? Balances::customerBalance((int)$c['id']) : ''];
                }
                break;
            case 'vendor':
                $rows = DB::all("SELECT v.*, tm.name AS terms_name FROM vendors v LEFT JOIN terms tm ON tm.id = v.terms_id WHERE v.id IN $in", $p);
                foreach ($rows as $v) {
                    $out[(int)$v['id']] = ['name' => $v['name'], 'company' => $v['company_name'], 'contact' => $v['contact_person'], 'phone' => $v['phone'],
                        'fax' => $v['fax'], 'email' => $v['email'], 'address' => self::oneLine($v['address']), 'tin' => $v['tin'], 'terms' => $v['terms_name'],
                        'account_no' => $v['account_no'], 'limit' => $v['credit_limit'],
                        'balance' => in_array('balance', $fields, true) ? Balances::vendorBalance((int)$v['id']) : ''];
                }
                break;
            case 'item':
                $rows = DB::all("SELECT i.*, ic.name AS category_name, pv.name AS vendor_name, COALESCE(st.q, 0) AS on_hand, tc.code AS tax_code
                                   FROM items i LEFT JOIN item_categories ic ON ic.id = i.category_id LEFT JOIN vendors pv ON pv.id = i.preferred_vendor_id
                                   LEFT JOIN tax_codes tc ON tc.id = i.tax_code_id
                                   LEFT JOIN (SELECT item_id, SUM(qty_on_hand) AS q FROM item_site_stock GROUP BY item_id) st ON st.item_id = i.id
                                  WHERE i.id IN $in", $p);
                foreach ($rows as $i) {
                    $tracked = Stock::isTracked($i['item_type']);
                    $price = (float)$i['sales_price'];
                    $avg = (float)$i['avg_cost'];
                    $gp = round($price - $avg, 4);
                    $profit = $tracked || $avg > 0;
                    $out[(int)$i['id']] = ['pdesc' => self::oneLine($i['purchase_description']), 'tax' => $i['tax_code'], 'max' => $tracked ? $i['max_stock'] : '',
                        'status' => (int)$i['is_active'] ? 'Active' : 'Inactive',
                        'value' => $tracked ? r2((float)$i['on_hand'] * $avg) : '', 'retail' => $tracked ? r2((float)$i['on_hand'] * $price) : '',
                        'gp_unit' => $profit ? $gp : '', 'gp_pct' => $profit && $price > 0 ? round($gp / $price * 100, 1) : '',
                        'markup' => $profit && $avg > 0 ? round($gp / $avg * 100, 1) : '',
                        'code' => $i['code'], 'name' => $i['name'], 'barcode' => $i['barcode'], 'desc' => self::oneLine($i['description']),
                        'category' => $i['category_name'], 'type' => ucwords(str_replace('_', ' ', (string)$i['item_type'])), 'unit' => $i['unit'],
                        'cost' => $i['cost'], 'avg_cost' => $i['avg_cost'], 'price' => $i['sales_price'], 'reorder' => $i['reorder_point'],
                        'vendor' => $i['vendor_name'], 'on_hand' => Stock::isTracked($i['item_type']) ? $i['on_hand'] : ''];
                }
                break;
            case 'account':
                $rows = DB::all("SELECT a.*, pa.name AS parent_name, pa.code AS parent_code FROM accounts a LEFT JOIN accounts pa ON pa.id = a.parent_id WHERE a.id IN $in", $p);
                foreach ($rows as $a) {
                    $out[(int)$a['id']] = ['code' => $a['code'], 'name' => $a['name'], 'type' => ucwords(str_replace('_', ' ', (string)$a['account_type'])),
                        'category' => ucwords(str_replace('_', ' ', (string)$a['category'])), 'parent' => $a['parent_name'] ? trim($a['parent_code'] . ' ' . $a['parent_name']) : '',
                        'desc' => self::oneLine($a['description']), 'bank_no' => $a['bank_account_no']];
                }
                break;
            case 'asset':
                $rows = DB::all("SELECT f.*, ac.name AS category_name, s.name AS site_name FROM fixed_assets f LEFT JOIN asset_categories ac ON ac.id = f.category_id
                                   LEFT JOIN sites s ON s.id = f.site_id WHERE f.id IN $in", $p);
                foreach ($rows as $a) {
                    $out[(int)$a['id']] = ['no' => $a['asset_no'], 'name' => $a['description'], 'category' => $a['category_name'],
                        'location' => $a['location'] ?: $a['site_name'], 'serial' => $a['serial_no'], 'acquired' => $a['acquisition_date'],
                        'cost' => $a['cost'], 'status' => ucfirst(str_replace('_', ' ', (string)$a['status']))];
                }
                break;
        }
        return $out;
    }

    private static function oneLine($v): string
    {
        return trim(preg_replace('/\s*\R\s*/', ', ', (string)$v) ?? '');
    }
}
