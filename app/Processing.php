<?php
declare(strict_types=1);

/**
 * Item processing: one or more inputs converted into several outputs, with the input cost
 * allocated among them. Covers
 *   - Disassembly / unbuild orders (reverse of an assembly build),
 *   - De-kitting / break-bulk (a kit, box or bulk pack split into single units),
 *   - Co-product / by-product processing (one or many raw materials yielding several products).
 *
 * Cost flow (posted by Posting::processing):
 *   total cost = input materials (issued at average cost) + additional processing costs (labor, overhead)
 *   by-products and scrap are valued first (qty x value per unit)
 *   the rest is allocated to the co-products by the chosen method
 *   valuation methods (average cost, manual) post any difference to the processing variance account.
 */
final class Processing
{
    public const TXN_TYPE = 'item_processing';

    /** process type => [label, explanation] */
    public const TYPES = [
        'disassembly' => ['Disassembly / Unbuild', 'Break a finished or assembled item (one input) into its component parts.'],
        'dekit'       => ['De-kitting / Break-bulk', 'Split a kit, bundle, box or bulk pack back into individual units for separate sale.'],
        'co_product'  => ['Co-product / By-product', 'Process one or more raw materials that yield several products at once (for example cuts, blends or refurbished units and recovered parts).'],
    ];

    /** allocation method => [label, explanation, kind (alloc = share the cost, value = value outputs and post the difference)] */
    public const METHODS = [
        'sales_value'   => ['Relative sales value', 'Co-products share the cost in proportion to qty x selling price. A basis entered on the line overrides the item price.', 'alloc'],
        'standard_cost' => ['Relative standard cost', 'Co-products share the cost in proportion to qty x standard (purchase) cost. A basis overrides the item cost.', 'alloc'],
        'quantity'      => ['Physical units', 'Every unit produced carries the same cost.', 'alloc'],
        'weight'        => ['Weight / measure', 'Co-products share the cost in proportion to qty x the weight or volume per unit entered as the basis.', 'alloc'],
        'percent'       => ['Fixed percentages', 'The basis is the percentage of the cost each co-product receives. The percentages must add up to 100.', 'alloc'],
        'average_cost'  => ['Current average cost (difference to variance)', 'Each output is valued at its current average cost (or the basis per unit). The difference from the total cost is posted to the processing variance account.', 'value'],
        'manual'        => ['Manual amounts (difference to variance)', 'The basis is the total cost you assign to each output line. The difference from the total cost is posted to the processing variance account.', 'value'],
    ];

    public const ROLES = [
        'co_product' => 'Co-product (main)',
        'by_product' => 'By-product',
        'scrap'      => 'Scrap / waste',
    ];

    /** Item types allowed as inputs / outputs */
    public const INPUT_TYPES = ['inventory', 'assembly', 'non_inventory', 'service', 'other_charge'];
    public const OUTPUT_TYPES = ['inventory', 'assembly'];

    public static function typeLabel(?string $t): string
    {
        return self::TYPES[(string)$t][0] ?? ucwords(str_replace('_', ' ', (string)$t));
    }

    public static function methodLabel(?string $m): string
    {
        return self::METHODS[(string)$m][0] ?? ucwords(str_replace('_', ' ', (string)$m));
    }

    public static function roleLabel(?string $r): string
    {
        return self::ROLES[(string)$r] ?? self::ROLES['co_product'];
    }

    public static function isValueMethod(?string $m): bool
    {
        return (self::METHODS[(string)$m][2] ?? '') === 'value';
    }

    /** Default method for a process type */
    public static function defaultMethod(string $type): string
    {
        return match ($type) {
            'disassembly' => 'standard_cost',
            'dekit' => 'standard_cost',
            default => 'sales_value',
        };
    }

    /** Item row (read fresh each time: average costs change as transactions post) */
    private static function item(int $id): array
    {
        $row = DB::row('SELECT id, code, name, item_type, cost, avg_cost, sales_price, unit, is_active FROM items WHERE id = :id', ['id' => $id]);
        if (!$row) {
            fail('An item on this form no longer exists.');
        }
        return $row;
    }

    // ------------------------------------------------------------------ allocation

    /**
     * Allocates $pool among output lines.
     *
     * @param array  $outputs key => [item_id, qty, role, basis]
     * @return array ['rows' => key => [cost, unit, pct, share], 'pool', 'fixed', 'allocated', 'variance']
     */
    public static function allocate(array $outputs, float $pool, string $method): array
    {
        if (!isset(self::METHODS[$method])) {
            fail('Choose a cost allocation method.');
        }
        $valueMode = self::isValueMethod($method);
        $pool = r2($pool);
        $res = [];
        $fixed = 0.0;
        $co = [];
        foreach ($outputs as $k => $o) {
            $item = self::item((int)$o['item_id']);
            $qty = (float)$o['qty'];
            $basis = (float)($o['basis'] ?? 0);
            if ($basis < 0) {
                fail('The allocation basis cannot be negative (' . $item['code'] . ').');
            }
            $role = isset(self::ROLES[$o['role'] ?? '']) ? $o['role'] : 'co_product';
            if ($role !== 'co_product') {
                // by-products and scrap: fixed value per unit (net realizable value), taken out first
                $cost = r2($qty * $basis);
                $res[$k] = ['cost' => $cost, 'share' => null];
                $fixed += $cost;
                continue;
            }
            $co[$k] = [$qty, $basis, $item];
        }
        $fixed = r2($fixed);

        if ($valueMode) {
            foreach ($co as $k => [$qty, $basis, $item]) {
                if ($method === 'manual') {
                    $cost = r2($basis);
                } else {
                    $unit = $basis > 0 ? $basis : ((float)$item['avg_cost'] > 0 ? (float)$item['avg_cost'] : (float)$item['cost']);
                    $cost = r2($qty * $unit);
                }
                $res[$k] = ['cost' => $cost, 'share' => null];
            }
            $allocated = r2(array_sum(array_column($res, 'cost')));
            $variance = r2($pool - $allocated);
        } else {
            if (!$co) {
                fail('Mark at least one output as a co-product (main product). Co-products absorb the cost that is left after by-products and scrap.');
            }
            $allocable = r2($pool - $fixed);
            if ($allocable < 0) {
                fail(sprintf('By-product and scrap values (%s) are more than the total cost to allocate (%s). Lower their values per unit.', money($fixed), money($pool)));
            }
            $weights = [];
            foreach ($co as $k => [$qty, $basis, $item]) {
                if ($method === 'weight' && $basis <= 0) {
                    fail('Enter the weight or measure per unit (basis) for ' . $item['code'] . '.');
                }
                $weights[$k] = match ($method) {
                    'sales_value' => $qty * ($basis > 0 ? $basis : (float)$item['sales_price']),
                    'standard_cost' => $qty * ($basis > 0 ? $basis : ((float)$item['cost'] > 0 ? (float)$item['cost'] : (float)$item['avg_cost'])),
                    'quantity' => $qty,
                    'weight' => $qty * $basis,
                    default => $basis, // percent
                };
            }
            $sumW = array_sum($weights);
            if ($method === 'percent' && abs($sumW - 100) > 0.01) {
                fail('The co-product percentages must add up to 100% (they add up to ' . pct($sumW) . ').');
            }
            if ($sumW <= 0.0000001) {
                fail(match ($method) {
                    'sales_value' => 'The co-products have no selling price. Enter a basis (price per unit) on each co-product, or choose another method.',
                    'standard_cost' => 'The co-products have no standard cost. Enter a basis (cost per unit) on each co-product, or choose another method.',
                    default => 'The allocation basis of the co-products adds up to zero.',
                });
            }
            $sum = 0.0;
            $bigKey = null;
            $big = -1.0;
            foreach ($weights as $k => $w) {
                $cost = r2($allocable * $w / $sumW);
                $res[$k] = ['cost' => $cost, 'share' => $w / $sumW * 100];
                $sum += $cost;
                if ($w > $big) {
                    $big = $w;
                    $bigKey = $k;
                }
            }
            // rounding difference goes to the largest co-product so the outputs equal the cost exactly
            $diff = r2($allocable - $sum);
            if (abs($diff) >= 0.005) {
                $res[$bigKey]['cost'] = r2($res[$bigKey]['cost'] + $diff);
            }
            $allocated = $pool;
            $variance = 0.0;
        }

        foreach ($res as $k => &$r) {
            $q = (float)$outputs[$k]['qty'];
            $r['unit'] = $q > 0 ? round($r['cost'] / $q, 4) : 0.0;
            $r['pct'] = $pool > 0 ? max(-99999.0, min(99999.0, round($r['cost'] / $pool * 100, 4))) : 0.0;
        }
        unset($r);
        // keep the caller's order
        $ordered = [];
        foreach ($outputs as $k => $_) {
            $ordered[$k] = $res[$k];
        }
        return ['rows' => $ordered, 'pool' => $pool, 'fixed' => $fixed, 'allocated' => $allocated, 'variance' => $variance];
    }

    /** Estimated cost of inputs (current average cost) and additional costs, for previews and approvals. */
    public static function estimate(array $inputs, array $costs): array
    {
        $rows = [];
        $materials = 0.0;
        foreach ($inputs as $k => $in) {
            $item = self::item((int)$in['item_id']);
            $unit = Stock::isTracked($item['item_type'])
                ? ((float)$item['avg_cost'] > 0 ? (float)$item['avg_cost'] : (float)$item['cost'])
                : (float)$item['cost'];
            $cost = r2((float)$in['qty'] * $unit);
            $rows[$k] = ['unit' => $unit, 'cost' => $cost];
            $materials += $cost;
        }
        $extra = 0.0;
        foreach ($costs as $c) {
            $extra += (float)$c['amount'];
        }
        return ['rows' => $rows, 'materials' => r2($materials), 'extra' => r2($extra), 'pool' => r2($materials + $extra)];
    }

    /**
     * Transaction lines for a processing order (inputs, costs, outputs) carrying estimated costs,
     * which posting replaces with the actual costs. Returns [lines, estimate, allocation].
     */
    public static function orderLines(array $inputs, array $outputs, array $costs, string $method): array
    {
        $inputs = array_values($inputs);
        $outputs = array_values($outputs);
        $est = self::estimate($inputs, $costs);
        $keyed = [];
        foreach ($outputs as $i => $o) {
            $keyed[$i] = ['item_id' => $o['item_id'], 'qty' => $o['qty'], 'role' => $o['output_role'] ?? 'co_product', 'basis' => $o['alloc_basis'] ?? 0];
        }
        $alloc = self::allocate($keyed, $est['pool'], $method);
        $lines = [];
        foreach ($inputs as $i => $l) {
            $lines[] = $l + ['line_kind' => 'input', 'unit' => self::item((int)$l['item_id'])['unit'], 'site_id' => null,
                'rate' => $est['rows'][$i]['unit'], 'amount' => $est['rows'][$i]['cost'], 'output_role' => null, 'alloc_basis' => 0, 'alloc_pct' => 0];
        }
        foreach ($costs as $l) {
            $lines[] = $l + ['line_kind' => 'cost', 'item_id' => null, 'qty' => 0, 'rate' => 0, 'site_id' => null,
                'output_role' => null, 'alloc_basis' => 0, 'alloc_pct' => 0, 'planned_qty' => null];
        }
        foreach ($outputs as $i => $l) {
            $r = $alloc['rows'][$i];
            $lines[] = $l + ['line_kind' => 'output', 'unit' => self::item((int)$l['item_id'])['unit'], 'site_id' => null,
                'rate' => $r['unit'], 'amount' => $r['cost'], 'alloc_pct' => $r['pct']];
        }
        return [$lines, $est, $alloc];
    }

    // ------------------------------------------------------------------ validation

    /**
     * Cleans and checks posted line rows. Returns [inputs, outputs, costs].
     * Each row keeps an optional 'id' (existing transaction / formula line).
     */
    /**
     * Normalises the three grids of the processing form.
     *
     * $method is the cost allocation method in force. The outputs grid lets the user type
     * either the allocated cost or the unit cost of a line; under "Manual amounts" that
     * typed figure becomes the co-product's basis (its total cost), and for by-products
     * and scrap — whose basis is always a value per unit — it becomes the unit value.
     */
    public static function cleanLines(array $inputs, array $outputs, array $costs, string $method = ''): array
    {
        $in = [];
        foreach ($inputs as $r) {
            $itemId = (int)($r['item_id'] ?? 0);
            $q = to_num($r['qty'] ?? 0);
            if (!$itemId && abs($q) < 0.000001) {
                continue;
            }
            if (!$itemId) {
                fail('Choose an item for every input line that has a quantity.');
            }
            $item = self::item($itemId);
            if (!in_array($item['item_type'], self::INPUT_TYPES, true)) {
                fail($item['code'] . ' cannot be used as an input (group, discount, subtotal and payment items are not allowed).');
            }
            if ($q <= 0) {
                fail('Enter a quantity greater than zero for input ' . $item['code'] . '.');
            }
            $row = [
                'item_id' => $itemId, 'qty' => $q,
                'description' => mb_substr(trim((string)($r['description'] ?? '')), 0, 255) ?: $item['name'],
                'planned_qty' => ($r['planned_qty'] ?? '') === '' ? null : to_num($r['planned_qty']),
            ];
            if (!empty($r['id']) && ctype_digit((string)$r['id'])) {
                $row['id'] = (int)$r['id'];
            }
            $in[] = $row;
        }
        $out = [];
        foreach ($outputs as $r) {
            $itemId = (int)($r['item_id'] ?? 0);
            $q = to_num($r['qty'] ?? 0);
            if (!$itemId && abs($q) < 0.000001) {
                continue;
            }
            if (!$itemId) {
                fail('Choose an item for every output line that has a quantity.');
            }
            $item = self::item($itemId);
            if (!in_array($item['item_type'], self::OUTPUT_TYPES, true)) {
                fail($item['code'] . ' cannot be an output. Outputs must be inventory or assembly items so their quantity and value can be tracked.');
            }
            if ($q <= 0) {
                fail('Enter a quantity greater than zero for output ' . $item['code'] . '.');
            }
            $role = (string)($r['output_role'] ?? 'co_product');
            if (!isset(self::ROLES[$role])) {
                $role = 'co_product';
            }
            $basis = to_num($r['alloc_basis'] ?? 0);
            if ($basis < 0) {
                fail('The allocation basis cannot be negative (' . $item['code'] . ').');
            }
            // an allocated cost or a unit cost typed on the line
            $typedCost = ($r['alloc_cost'] ?? '') !== '' ? to_num($r['alloc_cost']) : null;
            $typedUnit = ($r['unit_cost'] ?? '') !== '' ? to_num($r['unit_cost']) : null;
            if (($typedCost !== null && $typedCost < 0) || ($typedUnit !== null && $typedUnit < 0)) {
                fail('A cost cannot be negative (' . $item['code'] . ').');
            }
            if ($role === 'co_product') {
                if ($method === 'manual') {
                    if ($typedCost !== null) {
                        $basis = r2($typedCost);
                    } elseif ($typedUnit !== null) {
                        $basis = r2($typedUnit * $q);
                    }
                }
            } elseif ($typedUnit !== null) {
                $basis = round($typedUnit, 4);
            } elseif ($typedCost !== null) {
                $basis = $q > 0 ? round($typedCost / $q, 4) : 0.0;
            }
            $row = [
                'item_id' => $itemId, 'qty' => $q, 'output_role' => $role, 'alloc_basis' => $basis,
                'description' => mb_substr(trim((string)($r['description'] ?? '')), 0, 255) ?: $item['name'],
                'planned_qty' => ($r['planned_qty'] ?? '') === '' ? null : to_num($r['planned_qty']),
            ];
            if (!empty($r['id']) && ctype_digit((string)$r['id'])) {
                $row['id'] = (int)$r['id'];
            }
            $out[] = $row;
        }
        $cs = [];
        foreach ($costs as $r) {
            $acct = (int)($r['account_id'] ?? 0);
            $amt = r2(to_num($r['amount'] ?? 0));
            if (abs($amt) < 0.005) {
                continue;   // blank rows (the account may be preselected)
            }
            if ($amt < 0) {
                fail('Additional costs must be greater than zero.');
            }
            if (!$acct) {
                fail('Choose the account to credit for every additional cost line.');
            }
            $row = ['account_id' => $acct, 'amount' => $amt, 'description' => mb_substr(trim((string)($r['description'] ?? '')), 0, 255) ?: 'Processing cost'];
            if (!empty($r['id']) && ctype_digit((string)$r['id'])) {
                $row['id'] = (int)$r['id'];
            }
            $cs[] = $row;
        }
        if (!$in) {
            fail('Add at least one input item (what is being processed or taken apart).');
        }
        if (!$out) {
            fail('Add at least one output item (what the process produces).');
        }
        $inIds = array_column($in, 'item_id');
        foreach ($out as $o) {
            if (in_array($o['item_id'], $inIds, true)) {
                fail(self::item($o['item_id'])['code'] . ' is both an input and an output. Use an inventory adjustment for rework of the same item.');
            }
        }
        if (count(array_unique(array_column($out, 'item_id'))) !== count($out)) {
            fail('The same output item is listed twice. Combine the lines.');
        }
        return [$in, $out, $cs];
    }

    // ------------------------------------------------------------------ formulas

    /** id => "code - name" of formulas (active only by default) */
    public static function formulas(bool $activeOnly = true): array
    {
        return DB::pairs("SELECT id, CONCAT(code, ' - ', name) FROM process_formulas" . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY code');
    }

    public static function formula(int $id): ?array
    {
        return DB::row('SELECT * FROM process_formulas WHERE id = :id', ['id' => $id]);
    }

    public static function formulaLines(int $id): array
    {
        return DB::all(
            'SELECT fl.*, i.code AS item_code, i.name AS item_name, i.item_type, a.code AS account_code, a.name AS account_name
               FROM process_formula_lines fl
               LEFT JOIN items i ON i.id = fl.item_id
               LEFT JOIN accounts a ON a.id = fl.account_id
              WHERE fl.formula_id = :id ORDER BY fl.line_no, fl.id',
            ['id' => $id]
        );
    }

    /** Formula lines split into [inputs, outputs, costs] with quantities / amounts multiplied by $batches. */
    public static function expandFormula(int $formulaId, float $batches): array
    {
        $in = $out = $cs = [];
        foreach (self::formulaLines($formulaId) as $l) {
            $q = round((float)$l['qty'] * $batches, 4);
            if ($l['line_type'] === 'input') {
                $in[] = ['item_id' => (int)$l['item_id'], 'qty' => $q, 'planned_qty' => $q, 'description' => $l['description'] ?: $l['item_name']];
            } elseif ($l['line_type'] === 'output') {
                $basis = (float)$l['alloc_basis'];
                $f = self::formula($formulaId);
                // manual amounts are per batch; everything else is per unit / percent
                if ($f && $f['alloc_method'] === 'manual') {
                    $basis = r2($basis * $batches);
                }
                $out[] = ['item_id' => (int)$l['item_id'], 'qty' => $q, 'planned_qty' => $q, 'output_role' => $l['output_role'] ?: 'co_product',
                    'alloc_basis' => $basis, 'description' => $l['description'] ?: $l['item_name']];
            } else {
                $cs[] = ['account_id' => (int)$l['account_id'], 'amount' => r2((float)$l['amount'] * $batches), 'description' => $l['description']];
            }
        }
        return [$in, $out, $cs];
    }

    /** Assemblies with a bill of materials, labelled the way the item search setting asks for */
    public static function assemblies(): array
    {
        return Lookup::itemPairs("item_type = 'assembly' AND is_active = 1
                AND EXISTS (SELECT 1 FROM item_components ic WHERE ic.parent_item_id = items.id)");
    }

    /**
     * Unbuild lines from an assembly's bill of materials: the assembly is the input,
     * its stocked components are the outputs. Labor / service components are not recovered.
     */
    public static function expandBom(int $assemblyId, float $qty): array
    {
        $asm = self::item($assemblyId);
        $in = [['item_id' => $assemblyId, 'qty' => $qty, 'planned_qty' => $qty, 'description' => $asm['name']]];
        $out = [];
        foreach (DB::all('SELECT ic.qty, i.id, i.name, i.item_type FROM item_components ic JOIN items i ON i.id = ic.component_item_id WHERE ic.parent_item_id = :p ORDER BY i.code', ['p' => $assemblyId]) as $c) {
            if (!in_array($c['item_type'], self::OUTPUT_TYPES, true)) {
                continue;
            }
            $q = round((float)$c['qty'] * $qty, 4);
            $out[] = ['item_id' => (int)$c['id'], 'qty' => $q, 'planned_qty' => $q, 'output_role' => 'co_product', 'alloc_basis' => 0, 'description' => $c['name']];
        }
        if (!$out) {
            fail('The bill of materials of ' . $asm['code'] . ' has no stocked components to recover.');
        }
        return [$in, $out, []];
    }

    // ------------------------------------------------------------------ stock checks

    /** Inputs that are short at the site: list of "CODE (need x, have y)" */
    /**
     * What a processing order dated $date can take of an item at a site without the stock going below zero on that
     * date or on any later one (v30): the lowest balance from that date on, the day's receipts counted first
     * (Stock::history). A back-dated order can not use stock that only arrived later. $excludeTxnId: an order being
     * edited, whose own movements are left out.
     */
    public static function availableFrom(int $itemId, int $siteId, string $date, ?int $excludeTxnId = null): float
    {
        $bal = 0.0;
        $min = null;
        $cut = null;
        foreach (Stock::history($itemId, $siteId) as $m) {
            if ($excludeTxnId && (int)$m['txn_id'] === $excludeTxnId) {
                continue;
            }
            $after = $m['txn_date'] > $date || ($m['txn_date'] === $date && (int)$m['rank'] >= 1);
            if ($after && $cut === null) {
                $cut = $bal;                      // the balance the order would take from
                $min = $bal;
            }
            $bal += (float)$m['qty'];
            if ($after) {
                $min = min($min, $bal);
            }
        }
        return round($cut === null ? $bal : (float)$min, 4);
    }

    public static function shortInputs(array $inputs, int $siteId, ?int $excludeTxnId = null, ?string $date = null): array
    {
        $need = [];
        foreach ($inputs as $in) {
            $item = self::item((int)$in['item_id']);
            if (Stock::isTracked($item['item_type'])) {
                $need[(int)$in['item_id']] = ($need[(int)$in['item_id']] ?? 0) + (float)$in['qty'];
            }
        }
        $short = [];
        foreach ($need as $itemId => $q) {
            $have = Stock::onHand($itemId, $siteId);
            if ($excludeTxnId) {
                // an edited order already took its inputs out; count them as available again
                $have -= (float)DB::value('SELECT COALESCE(SUM(qty),0) FROM inventory_movements WHERE txn_id = :t AND item_id = :i AND site_id = :s', ['t' => $excludeTxnId, 'i' => $itemId, 's' => $siteId]);
            }
            if ($have + 0.00001 < $q) {
                $short[] = self::item($itemId)['code'] . ' (need ' . qty($q) . ', have ' . qty($have) . ')';
                continue;
            }
            // v30: a back-dated order may not take stock that only came in after its date
            if ($date !== null && $date < today()) {
                $from = self::availableFrom($itemId, $siteId, $date, $excludeTxnId);
                if ($from + 0.00001 < $q) {
                    $short[] = self::item($itemId)['code'] . ' (need ' . qty($q) . ', have ' . qty(max(0.0, $from)) . ' on ' . fdate($date)
                        . ' without going below zero on a later date; the rest came in after it)';
                }
            }
        }
        return $short;
    }

    /** Outputs that would go below zero if the order were voided or deleted. */
    public static function reversalShortfalls(int $txnId): array
    {
        $out = [];
        foreach (DB::all('SELECT m.item_id, m.site_id, SUM(m.qty) AS q, i.code, s.name AS site FROM inventory_movements m JOIN items i ON i.id = m.item_id JOIN sites s ON s.id = m.site_id
                           WHERE m.txn_id = :t GROUP BY m.item_id, m.site_id, i.code, s.name HAVING SUM(m.qty) > 0', ['t' => $txnId]) as $m) {
            $have = Stock::onHand((int)$m['item_id'], (int)$m['site_id']);
            if ($have + 0.00001 < (float)$m['q']) {
                $out[] = $m['code'] . ' at ' . $m['site'] . ' (on hand ' . qty($have) . ', produced ' . qty($m['q']) . ')';
            }
        }
        return $out;
    }
}
