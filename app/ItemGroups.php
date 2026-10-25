<?php
declare(strict_types=1);

/**
 * Item groups on sales documents (estimates, sales orders, invoices, sales receipts, statement charges, credit memos,
 * POS sales and returns).
 *
 * A group is entered as one line: the group item, how many, and its price. When the document is saved the line becomes
 * the items of the group, each at its own price (so stock, cost of sales and the sales of each item are recorded as
 * always), plus one "price variance" line: the group price less the prices of its items, posted to the group's Price
 * variance GL account (or the module default Item Group Price Variance). Every one of those lines keeps the group it
 * came from (group_item_id, group_ref, group_qty, group_rate, group_role), so the document can be shown again as its
 * groups or as the items in them, and the groups sold are kept in item_group_sales for the two reports.
 *
 * Settings (System Administration > Company, Security & Closing Date > Inventory and POS):
 *   adopt_group_price  the group price of the item group is the price on sales; off = the consolidated price
 *   track_group_sales  the groups sold are recorded in item_group_sales (Sales by Item Group, POS Sales by Item Group)
 */
final class ItemGroups
{
    /** The documents that carry item groups */
    public const SALES_TYPES = ['estimate', 'sales_order', 'invoice', 'sales_receipt', 'statement_charge', 'credit_memo', 'pos_sale', 'pos_return'];

    /** The documents the group sales reports count (credit memos and POS returns take sales back) */
    public const SOLD_TYPES = ['invoice', 'sales_receipt', 'statement_charge', 'pos_sale', 'credit_memo', 'pos_return'];

    private static ?bool $ready = null;
    private static array $members = [];

    /** Are the columns and the table there (the migration has been run)? */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                DB::value('SELECT COUNT(group_ref) FROM transaction_lines WHERE 1 = 0');
                DB::value('SELECT COUNT(*) FROM item_group_sales WHERE 1 = 0');
                self::$ready = true;
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    public static function adopt(): bool
    {
        return Settings::get('adopt_group_price', '1') === '1';
    }

    public static function track(): bool
    {
        return Settings::get('track_group_sales', '1') === '1';
    }

    /** The items of a group: [item row + 'qty' per group] */
    public static function members(int $groupId): array
    {
        return self::$members[$groupId] ??= DB::all(
            'SELECT ic.qty AS member_qty, i.* FROM item_components ic JOIN items i ON i.id = ic.component_item_id WHERE ic.parent_item_id = :p ORDER BY i.code',
            ['p' => $groupId]
        );
    }

    /** The items of the group at their own prices (the customer's price level included): the consolidated price */
    public static function consolidated(int $groupId, ?int $customerId = null): float
    {
        $sum = 0.0;
        foreach (self::members($groupId) as $m) {
            $sum += r2((float)$m['member_qty'] * Lookup::priceFor((int)$m['id'], $customerId));
        }
        return r2($sum);
    }

    /**
     * The price of one group on a sale: its group price (with the customer's price level) when "Adopt the Group Price"
     * is on and the group has one, otherwise the consolidated price.
     */
    public static function price(int $groupId, ?int $customerId = null): float
    {
        $g = DB::row('SELECT sales_price FROM items WHERE id = :i', ['i' => $groupId]);
        $own = (float)($g['sales_price'] ?? 0);
        if (self::adopt() && $own > 0) {
            return Lookup::levelPrice($groupId, $own, $customerId);
        }
        return self::consolidated($groupId, $customerId);
    }

    /** The account the price variance of a group goes to */
    public static function varianceAccount(array $group): int
    {
        if (!empty($group['group_variance_account_id'])) {
            return (int)$group['group_variance_account_id'];
        }
        $map = DB::pairs("SELECT setting_key, account_id FROM default_accounts WHERE setting_key IN ('group_price_variance','sales_income')");
        return (int)($map['group_price_variance'] ?? 0) ?: (int)($map['sales_income'] ?? 0) ?: Accts::get('sales_income');
    }

    /** The tax code of a group's price variance: the group's own, else the one most of its items have */
    public static function taxCode(array $group): ?int
    {
        if (!empty($group['tax_code_id'])) {
            return (int)$group['tax_code_id'];
        }
        $n = [];
        foreach (self::members((int)$group['id']) as $m) {
            if ($m['tax_code_id']) {
                $n[(int)$m['tax_code_id']] = ($n[(int)$m['tax_code_id']] ?? 0) + 1;
            }
        }
        arsort($n);
        return $n ? (int)array_key_first($n) : null;
    }

    /**
     * One group line of a form, as the lines that are saved.
     * $g: the group item; $base: the line fields every line shares (site, class ...); $qty / $rate: groups and the price
     * of one; $disc: the discount taken off the group line; $taxId: the tax code chosen on the group line;
     * $ref: which group of the document; $keep: [member ids..., 'v' => variance line id] of a saved group (its lines
     * keep their ids, so what was invoiced from them stays linked); $src: the source lines of the members (a sales order
     * or estimate the group came from), in the same order.
     */
    public static function expand(array $g, array $base, float $qty, float $rate, float $disc, ?int $taxId, ?int $customerId, int $ref, array $keep = [], array $src = [], array $srcVar = []): array
    {
        $out = [];
        $tags = ['group_item_id' => (int)$g['id'], 'group_ref' => $ref, 'group_qty' => $qty, 'group_rate' => round($rate, 4)];
        $cons = 0.0;
        $tax = 0.0;
        foreach (array_values(self::members((int)$g['id'])) as $k => $m) {
            $q = round($qty * (float)$m['member_qty'], 4);
            $price = Lookup::priceFor((int)$m['id'], $customerId);
            $amt = r2($q * $price);
            $cons += $amt;
            $line = array_merge($base, $tags, [
                'group_role' => 'member',
                'item_id' => (int)$m['id'], 'account_id' => null, 'description' => $m['description'] ?: $m['name'], 'unit' => $m['unit'],
                'qty' => $q, 'rate' => $price, 'discount_kind' => 'pct', 'discount_pct' => 0, 'discount_amount' => 0, 'amount' => $amt,
                'uom_id' => null, 'uom_factor' => 1, 'uom_qty' => $q, 'uom_rate' => $price,
                'tax_code_id' => $m['tax_code_id'], 'tax_amount' => r2($amt * tax_rate($m['tax_code_id'] ? (int)$m['tax_code_id'] : null) / 100),
                'source_line_id' => !empty($src[$k]) ? (int)$src[$k] : null,
            ]);
            unset($line['id']);
            if (!empty($keep[$k])) {
                $line['id'] = (int)$keep[$k];
            }
            $out[] = $line;
        }
        // the group price less the prices of its items, and the discount on the group line
        $gross = r2($qty * $rate);
        $amount = r2($gross - $disc);
        $variance = r2($amount - $cons);
        if (abs($variance) >= 0.005 || abs($disc) >= 0.005) {
            $vt = $taxId ?: self::taxCode($g);
            $line = array_merge($base, $tags, [
                'group_role' => 'variance',
                'item_id' => null, 'account_id' => self::varianceAccount($g), 'description' => 'Price variance: ' . $g['name'], 'unit' => null,
                'qty' => 0, 'rate' => 0, 'discount_kind' => 'amt', 'discount_pct' => 0, 'discount_amount' => r2($disc), 'amount' => $variance,
                'uom_id' => null, 'uom_factor' => 1, 'uom_qty' => 0, 'uom_rate' => 0,
                'tax_code_id' => $vt, 'tax_amount' => r2($variance * tax_rate($vt) / 100),
                'source_line_id' => null,
            ]);
            unset($line['id']);
            if (!empty($keep['v'])) {
                $line['id'] = (int)$keep['v'];
            }
            $out[] = $line;
        }
        return $out;
    }

    /** Does a document's line list hold item groups? */
    public static function has(array $lines): bool
    {
        foreach ($lines as $l) {
            if (!empty($l['group_ref']) && !empty($l['group_item_id'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * The lines of a saved document with each group shown as one line (the group, how many, its price and amount),
     * the items of the group in '_members'. Lines outside a group are left as they are. The group line carries the
     * ids of its lines (group_line_ids) and their source lines (group_src_ids), so saving it again keeps them.
     */
    public static function collapse(array $lines): array
    {
        $out = [];
        $at = [];
        foreach ($lines as $l) {
            $ref = (int)($l['group_ref'] ?? 0);
            $gid = (int)($l['group_item_id'] ?? 0);
            if (!$ref || !$gid) {
                $out[] = $l;
                continue;
            }
            if (!isset($at[$ref])) {
                $g = DB::row('SELECT * FROM items WHERE id = :i', ['i' => $gid]) ?: ['name' => 'Item group', 'code' => '', 'description' => '', 'unit' => null, 'tax_code_id' => null];
                $gq = (float)($l['group_qty'] ?? 0);
                $out[] = [
                    'line_kind' => 'item', 'item_id' => $gid, 'item_code' => $g['code'], 'item_name' => $g['name'], 'item_type' => 'group',
                    'description' => $g['description'] ?: $g['name'], 'unit' => $g['unit'],
                    'qty' => $gq, 'uom_qty' => $gq, 'rate' => (float)($l['group_rate'] ?? 0), 'uom_rate' => (float)($l['group_rate'] ?? 0), 'uom_factor' => 1, 'uom_id' => null,
                    'discount_kind' => 'amt', 'discount_pct' => 0, 'discount_amount' => 0.0, 'amount' => 0.0, 'tax_amount' => 0.0,
                    'tax_code_id' => null, 'site_id' => $l['site_id'] ?? null, 'class_id' => $l['class_id'] ?? null, 'class_name' => $l['class_name'] ?? null,
                    'qty_fulfilled' => null, 'is_closed' => 1, 'cost_amount' => 0.0, 'amount_fulfilled' => 0.0,
                    'is_billable' => 0, 'billed_txn_id' => null, 'ref_txn_id' => null, 'account_id' => null, 'account_code' => null, 'account_name' => null,
                    'customer_id' => null, 'customer_name' => null, 'vendor_name' => null, 'other_name' => null, 'source_line_id' => null, 'id' => null,
                    'group_ref' => $ref, 'group_item_id' => $gid, 'group_qty' => $gq, 'group_rate' => (float)($l['group_rate'] ?? 0),
                    '_group' => true, '_members' => [], '_variance' => null, 'group_line_ids' => '', 'group_src_ids' => '',
                ];
                $at[$ref] = array_key_last($out);
            }
            $o = &$out[$at[$ref]];
            $o['amount'] = r2($o['amount'] + (float)$l['amount']);
            $o['tax_amount'] = r2($o['tax_amount'] + (float)($l['tax_amount'] ?? 0));
            $o['cost_amount'] = r2($o['cost_amount'] + (float)($l['cost_amount'] ?? 0));
            if (($l['group_role'] ?? '') === 'variance') {
                $o['_variance'] = $l;
                $o['tax_code_id'] = $l['tax_code_id'] ?? $o['tax_code_id'];
                $o['discount_amount'] = (float)($l['discount_amount'] ?? 0);
            } else {
                $o['_members'][] = $l;
                $o['tax_code_id'] ??= $l['tax_code_id'] ?? null;
                if ((int)($l['is_closed'] ?? 0) === 0) {
                    $o['is_closed'] = 0;
                }
                // groups invoiced (received) so far: the least of what each item of the group was invoiced
                $per = $o['group_qty'] > 0 ? (float)$l['qty'] / $o['group_qty'] : 0.0;
                if ($per > 0 && isset($l['qty_fulfilled'])) {
                    $done = (float)$l['qty_fulfilled'] / $per;
                    $o['qty_fulfilled'] = $o['qty_fulfilled'] === null ? $done : min($o['qty_fulfilled'], $done);
                }
            }
            unset($o);
        }
        foreach ($out as &$o) {
            if (empty($o['_group'])) {
                continue;
            }
            $ids = array_map(fn($m) => (int)($m['id'] ?? 0), $o['_members']);
            $o['group_line_ids'] = implode(',', $ids) . ($o['_variance'] && !empty($o['_variance']['id']) ? ';v' . (int)$o['_variance']['id'] : '');
            $o['group_src_ids'] = implode(',', array_map(fn($m) => (int)($m['source_line_id'] ?? 0), $o['_members']));
            $o['qty_fulfilled'] = $o['qty_fulfilled'] === null ? 0.0 : round($o['qty_fulfilled'], 4);
            // a discount shows only when the group line was given one
            if ((float)$o['discount_amount'] < 0.005) {
                $o['discount_amount'] = 0.0;
            }
        }
        unset($o);
        return $out;
    }

    /** "12,34;v56" back to [12, 34, 'v' => 56] */
    public static function keepIds(string $s): array
    {
        $out = [];
        [$m, $v] = array_pad(explode(';', $s, 2), 2, '');
        foreach (array_filter(explode(',', $m), 'strlen') as $id) {
            $out[] = (int)$id;
        }
        if (preg_match('/^v(\d+)$/', trim($v), $mm)) {
            $out['v'] = (int)$mm[1];
        }
        return $out;
    }

    /** Which way a document's lines are shown: 'groups' (one line per item group) or 'details' (the items in them) */
    public static function viewMode(): string
    {
        return g('lines') === 'details' ? 'details' : 'groups';
    }

    /** The two buttons that switch between the item groups and the items in them */
    public static function viewSwitch(string $mode): string
    {
        $b = fn(string $m, string $label, string $icon) => '<a class="btn btn-sm ' . ($mode === $m ? 'btn-primary' : 'btn-outline-primary') . '" href="'
            . h(current_url(['lines' => $m === 'groups' ? null : $m])) . '"' . ($mode === $m ? ' aria-current="true"' : '') . '><i class="bi bi-' . $icon . '"></i> ' . $label . '</a>';
        return '<div class="group-view-switch"><span class="small text-muted">Item groups on this document:</span> <div class="btn-group" role="group" aria-label="Show the lines">'
            . $b('groups', 'View by item group', 'collection') . $b('details', 'View item details', 'list-ul') . '</div></div>';
    }

    /** Rebuilds the item group sales of one document (when tracking is on), from its lines */
    public static function record(int $txnId): void
    {
        if (!self::ready()) {
            return;
        }
        DB::run('DELETE FROM item_group_sales WHERE txn_id = :t', ['t' => $txnId]);
        if (!self::track()) {
            return;
        }
        $t = DB::row('SELECT * FROM transactions WHERE id = :id', ['id' => $txnId]);
        if (!$t || !in_array($t['txn_type'], self::SALES_TYPES, true)) {
            return;
        }
        $rows = DB::all("SELECT group_ref, group_item_id, MAX(group_qty) AS qty, MAX(group_rate) AS rate, SUM(amount) AS amount,
                                SUM(CASE WHEN group_role = 'variance' THEN 0 ELSE amount END) AS cons,
                                SUM(CASE WHEN group_role = 'variance' THEN amount ELSE 0 END) AS variance, SUM(tax_amount) AS tax
                           FROM transaction_lines WHERE txn_id = :t AND group_ref IS NOT NULL AND group_item_id IS NOT NULL
                          GROUP BY group_ref, group_item_id", ['t' => $txnId]);
        foreach ($rows as $r) {
            DB::insert('item_group_sales', [
                'txn_id' => $txnId, 'group_ref' => (int)$r['group_ref'], 'group_item_id' => (int)$r['group_item_id'],
                'txn_type' => $t['txn_type'], 'txn_date' => $t['txn_date'], 'customer_id' => $t['customer_id'], 'site_id' => $t['site_id'],
                'terminal_id' => $t['terminal_id'], 'class_id' => $t['class_id'], 'sales_rep_id' => $t['sales_rep_id'], 'created_by' => $t['created_by'],
                'qty' => (float)$r['qty'], 'group_price' => (float)$r['rate'], 'amount' => r2((float)$r['amount']),
                'consolidated' => r2((float)$r['cons']), 'variance' => r2((float)$r['variance']), 'tax_amount' => r2((float)$r['tax']),
            ]);
        }
    }

    /**
     * Discount and price checks of the groups on a document (price_checks does the other lines): a group sold for less
     * than its price, or at a price other than its own, needs the same approval a single item would.
     */
    public static function priceChecks(array $lines, ?int $customerId, ?float $maxDisc, ?float $maxOverride): array
    {
        $groups = [];
        foreach ($lines as $l) {
            if (empty($l['group_ref']) || empty($l['group_item_id'])) {
                continue;
            }
            $ref = (int)$l['group_ref'];
            $groups[$ref] ??= ['id' => (int)$l['group_item_id'], 'qty' => (float)$l['group_qty'], 'rate' => (float)$l['group_rate'], 'amount' => 0.0];
            $groups[$ref]['amount'] += (float)$l['amount'];
        }
        $needs = [];
        foreach ($groups as $g) {
            $item = item_cache($g['id']);
            $gross = r2($g['qty'] * $g['rate']);
            if ($gross > 0) {
                $pct = ($gross - r2($g['amount'])) / $gross * 100;
                if ($pct > 0.001 && Approval::exceeds($maxDisc, $pct)) {
                    $needs[] = pct($pct) . ' discount on ' . ($item['code'] ?? 'an item group');
                }
            }
            $std = self::price($g['id'], $customerId);
            if ($std > 0 && abs($g['rate']) > 0) {
                $change = abs($g['rate'] - $std) / $std * 100;
                if ($change > 0.001 && Approval::exceeds($maxOverride, $change)) {
                    $needs[] = 'price override on ' . ($item['code'] ?? 'an item group') . ' (' . money($g['rate']) . ' vs ' . money($std) . ')';
                }
            }
        }
        return $needs;
    }
}
