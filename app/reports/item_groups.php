<?php
declare(strict_types=1);

/**
 * Item group sales (v27): the groups sold as groups, from item_group_sales (ItemGroups::record).
 *   Sales by Item Group      (Sales Reports)  every module: invoices, sales receipts, statement charges, POS sales,
 *                                             less credit memos and POS returns
 *   POS Sales by Item Group  (Point of Sale)  POS sales less POS returns
 * Both show Item Group Name, Qty, Group Price and Total with a Totals line, and can show the individual items of each
 * group (and the transactions) under it.
 */

/** The item groups, for the drop-down */
function igs_groups(): array
{
    return DB::pairs("SELECT id, CONCAT(code, IF(name <> code, CONCAT(' - ', name), '')) FROM items WHERE item_type = 'group' ORDER BY code");
}

/** The WHERE of the group sales the filters let through (alias g = item_group_sales, t = transactions) */
function igs_where(array $f, array $types, array &$p): string
{
    [$in, $tp] = DB::in('igt', $types);
    $p += $tp + ['igf' => $f['from'], 'igto' => $f['to']];
    $w = " WHERE t.is_voided = 0 AND t.is_pending = 0 AND t.status <> 'suspended' AND g.txn_type IN $in AND g.txn_date BETWEEN :igf AND :igto";
    if (!empty($f['group_id'])) {
        $w .= ' AND g.group_item_id = :igg';
        $p['igg'] = (int)$f['group_id'];
    }
    if (!empty($f['customer_id'])) {
        $w .= ' AND (g.customer_id = :igc OR g.customer_id IN (SELECT id FROM customers WHERE parent_id = :igc2))';
        $p['igc'] = (int)$f['customer_id'];
        $p['igc2'] = (int)$f['customer_id'];
    }
    if (!empty($f['site_id'])) {
        $w .= ' AND g.site_id = :igs';
        $p['igs'] = (int)$f['site_id'];
    }
    if (!empty($f['rep_id'])) {
        $w .= ' AND g.sales_rep_id = :igr';
        $p['igr'] = (int)$f['rep_id'];
    }
    if (!empty($f['terminal_id'])) {
        $w .= ' AND g.terminal_id = :igterm';
        $p['igterm'] = (int)$f['terminal_id'];
    }
    if (!empty($f['user_id'])) {
        $w .= ' AND g.created_by = :igu';
        $p['igu'] = (int)$f['user_id'];
    }
    if (!empty($f['class_id']) && Classes::ready()) {
        if ((int)$f['class_id'] === Classes::UNCLASSIFIED) {
            $w .= ' AND g.class_id IS NULL';
        } else {
            $w .= ' AND g.class_id = :igcl';
            $p['igcl'] = (int)$f['class_id'];
        }
    }
    [$sf, $sp] = Access::siteFilter('g.site_id', 'igst');
    $p += $sp;
    return $w . $sf;
}

/** credit memos and POS returns take sales back */
const IGS_SIGN = "CASE WHEN g.txn_type IN ('credit_memo','pos_return') THEN -1 ELSE 1 END";

/** The report: one line per item group, optionally its items and its transactions under it */
function igs_report(array $f, array $types, string $scope): array
{
    if (!ItemGroups::ready()) {
        return ['columns' => [['key' => 'group', 'label' => 'Item group name']], 'rows' => [], 'notes' => 'Run the database update 2026_11_05_item_groups_receiving_lists.sql first.'];
    }
    $sign = IGS_SIGN;
    $p = [];
    $where = igs_where($f, $types, $p);
    $groups = DB::all("SELECT g.group_item_id, i.code, i.name, SUM($sign * g.qty) AS qty, SUM($sign * g.amount) AS amount,
                              SUM($sign * g.consolidated) AS cons, SUM($sign * g.variance) AS variance, SUM($sign * g.tax_amount) AS tax, COUNT(DISTINCT g.txn_id) AS docs
                         FROM item_group_sales g JOIN transactions t ON t.id = g.txn_id JOIN items i ON i.id = g.group_item_id
                        $where GROUP BY g.group_item_id, i.code, i.name ORDER BY i.code", $p);
    $showItems = ($f['show_items'] ?? '') === '1';
    $showTxns = ($f['show_txns'] ?? '') === '1';
    $items = [];
    if ($showItems && $groups) {
        $p2 = [];
        $w2 = igs_where($f, $types, $p2);
        foreach (DB::all("SELECT g.group_item_id, l.item_id, l.group_role, MAX(COALESCE(i.code, '')) AS code, MAX(COALESCE(i.name, l.description)) AS name,
                                 SUM($sign * l.qty) AS qty, SUM($sign * l.amount) AS amount
                            FROM item_group_sales g JOIN transactions t ON t.id = g.txn_id
                            JOIN transaction_lines l ON l.txn_id = g.txn_id AND l.group_ref = g.group_ref
                            LEFT JOIN items i ON i.id = l.item_id
                           $w2 GROUP BY g.group_item_id, l.item_id, l.group_role ORDER BY g.group_item_id, l.group_role, code", $p2) as $r) {
            $items[(int)$r['group_item_id']][] = $r;
        }
    }
    $txns = [];
    if ($showTxns && $groups) {
        $p3 = [];
        $w3 = igs_where($f, $types, $p3);
        foreach (DB::all("SELECT g.*, t.txn_no, t.receipt_no, t.id AS txn_id, COALESCE(c.name, t.payee_name) AS party, $sign AS sgn
                            FROM item_group_sales g JOIN transactions t ON t.id = g.txn_id LEFT JOIN customers c ON c.id = g.customer_id
                           $w3 ORDER BY g.txn_date, g.id", $p3) as $r) {
            $txns[(int)$r['group_item_id']][] = $r;
        }
    }
    $nested = $showItems || $showTxns;
    $rows = [];
    $tot = ['group' => 'Totals', 'qty' => 0.0, 'amount' => 0.0, 'cons' => 0.0, 'variance' => 0.0, 'tax' => 0.0, 'docs' => 0];
    foreach ($groups as $g) {
        $gid = (int)$g['group_item_id'];
        $qty = (float)$g['qty'];
        $amount = r2((float)$g['amount']);
        $rows[] = [
            'group' => $g['code'] . ($g['name'] !== $g['code'] ? ' - ' . $g['name'] : ''), 'qty' => $qty,
            'price' => abs($qty) > 0.00001 ? round($amount / $qty, 4) : '', 'amount' => $amount,
            'cons' => r2((float)$g['cons']), 'variance' => r2((float)$g['variance']), 'tax' => r2((float)$g['tax']), 'docs' => (int)$g['docs'],
            '_links' => ['group' => Report::link('inv.items', ['a' => 'edit', 'id' => $gid]),
                'amount' => Report::link($scope === 'pos' ? 'rpt.pos_sales_item_group' : 'rpt.sales_item_group', array_filter([
                    'dates' => 'custom', 'from' => $f['from'], 'to' => $f['to'], 'group_id' => $gid, 'show_txns' => '1',
                    'site_id' => $f['site_id'] ?? null, 'customer_id' => $f['customer_id'] ?? null, 'terminal_id' => $f['terminal_id'] ?? null, 'user_id' => $f['user_id'] ?? null,
                ]))],
            '_class' => $nested ? 'group-row' : '',
        ];
        foreach (['qty' => $qty, 'amount' => $amount, 'cons' => (float)$g['cons'], 'variance' => (float)$g['variance'], 'tax' => (float)$g['tax'], 'docs' => (int)$g['docs']] as $k => $v) {
            $tot[$k] += $v;
        }
        foreach ($items[$gid] ?? [] as $it) {
            $isVar = $it['group_role'] === 'variance';
            $iq = (float)$it['qty'];
            $rows[] = [
                'group' => $isVar ? 'Price variance (group price less the item prices)' : trim($it['code'] . ' - ' . $it['name'], ' -'),
                'qty' => $isVar ? '' : $iq, 'price' => !$isVar && abs($iq) > 0.00001 ? round((float)$it['amount'] / $iq, 4) : '',
                'amount' => r2((float)$it['amount']), '_class' => 'indent-1 igs-item',
            ];
        }
        foreach ($txns[$gid] ?? [] as $t) {
            $s = (int)$t['sgn'];
            $rows[] = [
                'group' => Txn::label($t['txn_type']) . ' ' . ($t['receipt_no'] ?: $t['txn_no']) . ', ' . fdate($t['txn_date']) . ($t['party'] ? ', ' . $t['party'] : ''),
                'qty' => $s * (float)$t['qty'], 'price' => (float)$t['group_price'], 'amount' => r2($s * (float)$t['amount']),
                'cons' => r2($s * (float)$t['consolidated']), 'variance' => r2($s * (float)$t['variance']),
                '_links' => ['group' => Report::txnLink(['txn_id' => $t['txn_id'], 'txn_type' => $t['txn_type']])],
                '_class' => 'indent-1 igs-txn',
            ];
        }
        if ($nested) {
            $rows[] = ['group' => 'Total ' . $g['code'], 'qty' => $qty, 'amount' => $amount, '_class' => 'subtotal-row'];
        }
    }
    $tot['price'] = abs($tot['qty']) > 0.00001 ? round($tot['amount'] / $tot['qty'], 4) : '';
    $notes = $scope === 'pos'
        ? 'Item groups sold at the POS, as groups: POS sales less POS returns.'
        : 'Item groups sold, as groups, in every module: invoices, sales receipts, statement charges and POS sales, less credit memos and POS returns.';
    $notes .= ' Qty is the number of groups sold; Group price is the total over the quantity; Total is what the groups sold for, before tax. Click a total to see its transactions.';
    if (!ItemGroups::track()) {
        $notes .= ' <strong>"Track Item Group Sales transactions" is switched off</strong> (System Administration &gt; Company, Security &amp; Closing Date), so groups sold since then are not on this report.';
    }
    return [
        'grouped' => $nested,
        'columns' => [['key' => 'group', 'label' => 'Item group name'], ['key' => 'qty', 'label' => 'Qty', 'type' => 'qty'],
            ['key' => 'price', 'label' => 'Group price', 'type' => 'money'], ['key' => 'amount', 'label' => 'Total', 'type' => 'money'],
            ['key' => 'cons', 'label' => 'Consolidated price', 'type' => 'money', 'optional' => true],
            ['key' => 'variance', 'label' => 'Price variance', 'type' => 'money', 'optional' => true],
            ['key' => 'tax', 'label' => 'Tax', 'type' => 'money', 'optional' => true], ['key' => 'docs', 'label' => 'Transactions', 'type' => 'int', 'optional' => true]],
        'rows' => $rows,
        'totals' => $tot,
        'notes' => $notes,
    ];
}

Report::define('sales_item_group', [
    'filters' => ['range', 'customer', 'site', 'class', 'rep'],
    // the item groups sorted on a figure, increasing or decreasing; their items and transactions move with them (v29)
    'sort_by' => ['qty' => 'Qty', 'amount' => 'Total'],
    'default_dates' => 'this_month',
    'extra_filters' => ['group_id' => ['Item group', fn() => igs_groups()]],
    'checks' => ['show_items' => 'Show the individual item details', 'show_txns' => 'Show the transactions'],
    'filter_hook' => function (array $f): array {
        // the Item Group's own page links here with item_id
        if (empty($f['group_id']) && !empty($f['item_id'])) {
            $f['group_id'] = (string)$f['item_id'];
        }
        return $f;
    },
    'run' => fn(array $f) => igs_report($f, ItemGroups::SOLD_TYPES, 'all'),
]);

Report::define('pos_sales_item_group', [
    'filters' => ['range', 'terminal', 'site', 'cashier'],
    'sort_by' => ['qty' => 'Qty', 'amount' => 'Total'],
    'default_dates' => 'today',
    'extra_filters' => ['group_id' => ['Item group', fn() => igs_groups()]],
    'checks' => ['show_items' => 'Show the individual item details', 'show_txns' => 'Show the transactions'],
    'run' => fn(array $f) => igs_report($f, ['pos_sale', 'pos_return'], 'pos'),
]);
