<?php
declare(strict_types=1);

/**
 * Perpetual inventory with weighted-average costing.
 * Quantities are tracked per site; the average cost is per item (all sites) and always equals
 * the value on hand divided by the quantity on hand.
 *
 * v31: the movements are the books. The average is the remaining value of the item's movements divided by the
 * remaining quantity of the SAME movements (books()), never by the quick-lookup table item_site_stock, and that table is
 * always written with an insert-or-update. (Before v31 a site row that Rebuild Data had removed at zero was not taken
 * down when a later edit reversed a movement there, so the table could hold less than the movements, and as the average
 * was divided by the table's quantity, every receipt after that overstated it.)
 * A receipt into stock that was sold below zero settles the shortfall at the cost received (see move()).
 */
final class Stock
{
    public const TRACKED = ['inventory', 'assembly'];

    /** v31: receipts settle a shortfall (stock sold below zero) at their own cost; off while a transfer moves stock between sites */
    public static bool $settleShortfall = true;

    /**
     * v31: the shortfalls settled by the receipts of the transaction being posted, for the posting engine to charge to
     * cost of goods sold: [item_id, site_id, line_id, amount]; amount > 0 = the stock sold below zero cost more than it
     * was costed at (more cost of goods sold), < 0 = less.
     */
    public static array $settlements = [];

    public static function isTracked(?string $itemType): bool
    {
        return in_array($itemType, self::TRACKED, true);
    }

    /**
     * What an item still holds: the quantity on hand (all sites), the sites where it is not zero, and its book
     * value. An inventory part or assembly with any of them can not be made inactive.
     * @return array{qty: float, value: float, sites: string[], any: bool}
     */
    public static function balance(int $itemId): array
    {
        $sites = DB::column('SELECT s.name FROM item_site_stock x JOIN sites s ON s.id = x.site_id
                              WHERE x.item_id = :i AND ABS(x.qty_on_hand) >= 0.00005 ORDER BY s.id', ['i' => $itemId]);
        $qty = self::onHand($itemId);
        $value = r2(self::valueOnHand($itemId));
        return ['qty' => $qty, 'value' => $value, 'sites' => $sites, 'any' => $sites !== [] || abs($qty) >= 0.00005 || abs($value) >= 0.005];
    }

    /** The reason an item can not be made inactive, or null when it can */
    public static function inactiveRefusal(array $item): ?string
    {
        if (!self::isTracked((string)$item['item_type'])) {
            return null;
        }
        $b = self::balance((int)$item['id']);
        if (!$b['any']) {
            return null;
        }
        $parts = [];
        if (abs($b['qty']) >= 0.00005 || $b['sites']) {
            $parts[] = qty($b['qty']) . ' on hand' . ($b['sites'] ? ' (' . implode(', ', $b['sites']) . ')' : '');
        }
        if (abs($b['value']) >= 0.005 && Access::viewCosts()) {
            $parts[] = 'a value of ' . money($b['value']);
        }
        return $item['code'] . ' still has ' . (implode(' and ', $parts) ?: 'a balance')
            . '. Only an inventory item with no balance can be made inactive: sell, transfer or adjust it to zero first.';
    }

    public static function onHand(int $itemId, ?int $siteId = null): float
    {
        if ($siteId) {
            return (float)DB::value('SELECT qty_on_hand FROM item_site_stock WHERE item_id = :i AND site_id = :s', ['i' => $itemId, 's' => $siteId]);
        }
        return (float)DB::value('SELECT COALESCE(SUM(qty_on_hand),0) FROM item_site_stock WHERE item_id = :i', ['i' => $itemId]);
    }

    /**
     * v31: the quantity on the books: the total of the item's movements (at one site, or at all sites). The average cost
     * is always worked out on this quantity, so it is the remaining value divided by the remaining quantity of the same
     * movements.
     */
    public static function bookQty(int $itemId, ?int $siteId = null): float
    {
        if ($siteId) {
            return round((float)DB::value('SELECT COALESCE(SUM(qty),0) FROM inventory_movements WHERE item_id = :i AND site_id = :s', ['i' => $itemId, 's' => $siteId]), 4);
        }
        return round((float)DB::value('SELECT COALESCE(SUM(qty),0) FROM inventory_movements WHERE item_id = :i', ['i' => $itemId]), 4);
    }

    /** v31: the quantity and the value of an item on the books (all sites): the totals of its movements, in one read */
    private static function books(int $itemId): array
    {
        $b = DB::row('SELECT COALESCE(SUM(qty),0) AS q, COALESCE(SUM(total_cost),0) AS v FROM inventory_movements WHERE item_id = :i', ['i' => $itemId]);
        return [round((float)$b['q'], 4), r2((float)$b['v'])];
    }

    /** v31: adds a quantity to the quick-lookup row of an item at a site, adding the row when it is not there */
    private static function addToSite(int $itemId, int $siteId, float $qty): void
    {
        DB::run(
            'INSERT INTO item_site_stock (item_id, site_id, qty_on_hand) VALUES (:i, :s, :q)
             ON DUPLICATE KEY UPDATE qty_on_hand = qty_on_hand + VALUES(qty_on_hand)',
            ['i' => $itemId, 's' => $siteId, 'q' => $qty]
        );
    }

    /** v31: sets the quick-lookup quantity of an item at a site to the total of its movements there (adds the row when missing) */
    public static function syncSite(int $itemId, int $siteId): void
    {
        DB::run(
            'INSERT INTO item_site_stock (item_id, site_id, qty_on_hand) VALUES (:i, :s, :q)
             ON DUPLICATE KEY UPDATE qty_on_hand = VALUES(qty_on_hand)',
            ['i' => $itemId, 's' => $siteId, 'q' => self::bookQty($itemId, $siteId)]
        );
    }

    /**
     * v31: puts the quick-lookup quantities of items (or of every item) back to the totals of their movements and
     * their average cost back to the remaining value / remaining quantity. Returns the number of site rows corrected.
     */
    public static function resync(?array $itemIds = null): int
    {
        $p = [];
        $c1 = $c2 = '';
        if ($itemIds !== null) {
            if (!$itemIds) {
                return 0;
            }
            $ids = array_map('intval', $itemIds);
            [$in1, $p1] = DB::in('rsa', $ids);
            [$in2, $p2] = DB::in('rsb', $ids);
            $c1 = " WHERE item_id IN $in1";
            $c2 = " WHERE item_id IN $in2";
            $p = $p1 + $p2;
        }
        $wrong = DB::all(
            "SELECT x.item_id, x.site_id FROM (
                SELECT item_id, site_id, qty_on_hand AS q, 0 AS m FROM item_site_stock$c1
                UNION ALL SELECT item_id, site_id, 0, SUM(qty) FROM inventory_movements$c2 GROUP BY item_id, site_id) x
              GROUP BY x.item_id, x.site_id HAVING ABS(SUM(x.q) - SUM(x.m)) >= 0.00005",
            $p
        );
        $items = [];
        foreach ($wrong as $w) {
            self::syncSite((int)$w['item_id'], (int)$w['site_id']);
            $items[(int)$w['item_id']] = true;
        }
        foreach (array_keys($items) as $itemId) {
            $q = self::bookQty($itemId);
            $v = self::valueOnHand($itemId);
            if ($q > 0.000001 && $v > 0.004) {
                DB::run('UPDATE items SET avg_cost = :a WHERE id = :i', ['a' => round($v / $q, 4), 'i' => $itemId]);
            }
        }
        return count($wrong);
    }

    /** Book value of an item: what its movements have put into and taken out of inventory (all sites) */
    public static function valueOnHand(int $itemId): float
    {
        return (float)DB::value('SELECT COALESCE(SUM(total_cost),0) FROM inventory_movements WHERE item_id = :i', ['i' => $itemId]);
    }

    /**
     * Records a movement and returns its signed value (positive = inventory value increased).
     *  - qty > 0 : receipt at $unitCost (defaults to current average)
     *  - qty < 0 : issue at $unitCost (defaults to current average)
     *  - qty = 0 : value-only change of $valueChange
     *
     * Moving weighted average, kept on the book value of the item:
     *  - the average is always value on hand / quantity on hand, so rounding the average to
     *    four decimals never builds up a difference between the two;
     *  - an issue that brings the quantity to zero takes out ALL the value that is left, so the
     *    cost of goods sold of that last sale equals the remaining cost and nothing stays behind
     *    on an item with no stock;
     *  - an issue that goes below zero takes out what is left plus the shortfall at the last average.
     */
    public static function move(int $txnId, ?int $lineId, string $date, int $itemId, int $siteId, float $qty, ?float $unitCost = null, float $valueChange = 0.0, ?float $exactTotal = null): float
    {
        if ($exactTotal !== null && abs($qty) > 0.000001) {
            $unitCost = abs($exactTotal / $qty);
        }
        $item = DB::row('SELECT id, avg_cost, cost, item_type FROM items WHERE id = :i FOR UPDATE', ['i' => $itemId]);
        if (!$item || !self::isTracked($item['item_type'])) {
            return 0.0;
        }
        // v31: the quantity of the same movements the value comes from (one read for both)
        [$totalQty, $value] = self::books($itemId);
        $avg = (float)$item['avg_cost'];
        // the average the books really carry; the stored one is only used when there is nothing to divide
        $bookAvg = ($totalQty > 0.000001 && $value > 0.004) ? $value / $totalQty : $avg;
        // v29: an item with no average cost yet (nothing received at a cost) goes out at its unit cost from the Item List,
        // as the estimates of processing orders already price it, instead of at no cost at all
        if ($qty < 0 && $unitCost === null && $exactTotal === null && $bookAvg <= 0.0 && (float)$item['cost'] > 0) {
            $bookAvg = (float)$item['cost'];
        }
        $newQty = $totalQty + $qty;
        $settle = 0.0;

        if (abs($qty) < 0.000001) {
            $total = r2($valueChange);
            $cost = 0.0;
            $newAvg = $totalQty > 0.000001 ? max(0.0, ($value + $total) / $totalQty) : $avg;
        } elseif ($qty > 0) {
            [$total, $cost] = self::movementValue($qty, $totalQty, $value, $bookAvg, $unitCost, $exactTotal);
            // v31: stock sold or used below zero went out at the average of that time; the receipt that brings it back
            // settles that shortfall at its own cost and the difference goes to cost of goods sold. Before v31 the
            // difference stayed on the few units left, so a processing order or bill posted after the day's sales could
            // multiply the average (for example 9.9 short at 225, then 10 received at 300: 7,725 on the 0.1 left).
            if (self::$settleShortfall && $totalQty < -0.000001 && $cost > 0.000001) {
                if ($newQty > -0.000001) {
                    $settle = r2($value + $total - r2(max(0.0, $newQty) * $cost));   // what is left carries this cost
                } elseif ($value < -0.004) {
                    $settle = r2($qty * ($cost - $value / $totalQty));             // still short: the units received settle part of it
                }
            }
            $left = $value + $total - $settle;
            if ($newQty > 0.000001) {
                // value on hand (negative when stock was oversold) plus the value received, over the new quantity
                $newAvg = $left / $newQty;
                if ($newAvg <= 0) {
                    $newAvg = $cost;
                }
            } else {
                $newAvg = $cost;                                   // still short after the receipt
            }
        } else {
            [$total, $cost] = self::movementValue($qty, $totalQty, $value, $bookAvg, $unitCost, $exactTotal);
            if ($newQty > 0.000001) {
                $left = $value + $total;
                $newAvg = $left > 0.004 ? $left / $newQty : $bookAvg;
            } else {
                $newAvg = $bookAvg;                                // nothing left: keep the last average for reference
            }
        }

        DB::run('UPDATE items SET avg_cost = :a WHERE id = :i', ['a' => round($newAvg, 4), 'i' => $itemId]);
        DB::insert('inventory_movements', [
            'txn_id'      => $txnId,
            'txn_line_id' => $lineId,
            'txn_date'    => $date,
            'item_id'     => $itemId,
            'site_id'     => $siteId,
            'qty'         => $qty,
            'unit_cost'   => round($cost, 4),
            'total_cost'  => $total,
            'qty_after'   => $newQty,
            'avg_after'   => round($newAvg, 4),
        ]);
        if (abs($settle) >= 0.005) {
            // the shortfall settled: a value-only movement of the same line, charged to cost of goods sold by the posting
            DB::insert('inventory_movements', [
                'txn_id' => $txnId, 'txn_line_id' => $lineId, 'txn_date' => $date, 'item_id' => $itemId, 'site_id' => $siteId,
                'qty' => 0, 'unit_cost' => 0, 'total_cost' => -$settle, 'qty_after' => $newQty, 'avg_after' => round($newAvg, 4),
            ]);
            self::$settlements[] = ['item_id' => $itemId, 'site_id' => $siteId, 'line_id' => $lineId, 'amount' => $settle];
        }
        // the site's quick-lookup quantity (v31: the row is added again when Rebuild Data removed it at zero)
        self::addToSite($itemId, $siteId, $qty);
        // v31: a table that drifted before this version is put right the first time the item moves again
        if (abs(self::onHand($itemId) - $newQty) >= 0.00005) {
            self::resync([$itemId]);
        }
        return $total;
    }

    /**
     * Where a transaction comes in the day, for an item's history and for replaying the costs (v30):
     * 0 = it only brings stock in (bills, item receipts, returns, adjustments up), 1 = it takes some out and puts some
     * in (processing orders, builds, transfers), 2 = it only takes stock out (sales, adjustments down).
     * On one date, stock received is counted before the stock that is used or sold, whatever order they were typed in:
     * a meat shop that enters the processing order before the day's bill still processes what that bill brought in.
     * @param int[] $txnIds
     * @return array<int,int> txn id => rank
     */
    public static function dayRanks(array $txnIds): array
    {
        $txnIds = array_values(array_unique(array_map('intval', $txnIds)));
        $out = [];
        foreach (array_chunk($txnIds, 500) as $chunk) {
            [$in, $p] = DB::in('dr', $chunk);
            foreach (DB::all("SELECT txn_id, MIN(qty) AS lo, MAX(qty) AS hi FROM inventory_movements WHERE txn_id IN $in GROUP BY txn_id", $p) as $r) {
                $out[(int)$r['txn_id']] = (float)$r['lo'] >= 0 ? 0 : ((float)$r['hi'] <= 0 ? 2 : 1);
            }
        }
        return $out;
    }

    /**
     * An item's movements in the order they happened: by date, then stock in before stock out (dayRanks), then the
     * order they were entered in. Each one carries 'balance': the quantity on hand after it, counted from the item's
     * very first movement (at the site, or at all sites), and 'rank'.
     */
    public static function history(int $itemId, ?int $siteId = null): array
    {
        $sql = 'SELECT m.*, t.txn_type, t.txn_no, t.id AS tid, s.name AS site FROM inventory_movements m JOIN transactions t ON t.id = m.txn_id JOIN sites s ON s.id = m.site_id
                 WHERE m.item_id = :i';
        $p = ['i' => $itemId];
        if ($siteId) {
            $sql .= ' AND m.site_id = :sid';
            $p['sid'] = $siteId;
        }
        $rows = DB::all($sql, $p);
        $ranks = self::dayRanks(array_column($rows, 'txn_id'));
        foreach ($rows as &$r) {
            $r['rank'] = $ranks[(int)$r['txn_id']] ?? 1;
        }
        unset($r);
        usort($rows, fn($a, $b) => [$a['txn_date'], $a['rank'], (int)$a['txn_id'], (int)$a['id']] <=> [$b['txn_date'], $b['rank'], (int)$b['txn_id'], (int)$b['id']]);
        $bal = 0.0;
        foreach ($rows as &$r) {
            $bal += (float)$r['qty'];
            $r['balance'] = round($bal, 4);
        }
        unset($r);
        return $rows;
    }

    /** Undo every movement of a transaction (used before re-posting, voiding or deleting). */
    public static function reverseTxn(int $txnId): void
    {
        $moves = DB::all('SELECT * FROM inventory_movements WHERE txn_id = :t ORDER BY id DESC', ['t' => $txnId]);
        foreach ($moves as $m) {
            $item = DB::row('SELECT avg_cost FROM items WHERE id = :i FOR UPDATE', ['i' => $m['item_id']]);
            DB::run('DELETE FROM inventory_movements WHERE id = :id', ['id' => $m['id']]);
            // v31: the site's quantity is taken down with an insert-or-update. Before v31 it was an UPDATE, which did
            // nothing when the site row had been removed at zero by Rebuild Data, so the table kept less than the
            // movements and, with the average worked out on the table, the next receipts overstated the average.
            self::addToSite((int)$m['item_id'], (int)$m['site_id'], -(float)$m['qty']);
            // the average follows what is left on the books; with nothing left the last average stays
            [$qtyLeft, $valueLeft] = self::books((int)$m['item_id']);
            $newAvg = ($qtyLeft > 0.000001 && $valueLeft > 0.004) ? $valueLeft / $qtyLeft : (float)$item['avg_cost'];
            DB::run('UPDATE items SET avg_cost = :a WHERE id = :i', ['a' => round($newAvg, 4), 'i' => $m['item_id']]);
        }
    }

    /**
     * The value a receipt (qty > 0) or an issue (qty < 0) puts into or takes out of inventory, and its unit cost:
     * [signed total, unit cost]. The one rule used by move() and by valueAfter().
     */
    private static function movementValue(float $qty, float $totalQty, float $value, float $bookAvg, ?float $unitCost, ?float $exactTotal): array
    {
        $cost = $unitCost ?? $bookAvg;
        if ($qty > 0) {
            return [$exactTotal !== null ? r2(abs($exactTotal)) : r2($qty * $cost), $cost];
        }
        $newQty = $totalQty + $qty;
        if ($exactTotal !== null) {
            $total = -r2(abs($exactTotal));
        } elseif ($unitCost !== null) {
            $total = r2($qty * $cost);
        } elseif ($totalQty > 0.000001 && $value > 0.004 && $newQty < 0.00005) {
            // the last of the stock (and perhaps more): all the remaining value goes out with it
            $short = abs($newQty) < 0.00005 ? 0.0 : -$newQty;
            $total = -r2($value + $short * $bookAvg);
            $cost = abs($total / $qty);
        } else {
            $total = r2($qty * $cost);
            if ($totalQty > 0.000001 && $value > 0.004 && -$total > $value) {
                $total = -$value;                              // never take out more than the books hold
            }
        }
        return [$total, $cost];
    }

    /**
     * The book value of an item (all sites) after a quantity change at its current average, without recording
     * anything: what "Quantity and total value" adjustments measure the new value against.
     */
    public static function valueAfter(int $itemId, float $qty): float
    {
        [$totalQty, $value] = self::books($itemId);
        if (abs($qty) < 0.000001) {
            return $value;
        }
        $item = DB::row('SELECT avg_cost, cost FROM items WHERE id = :i', ['i' => $itemId]) ?: ['avg_cost' => 0, 'cost' => 0];
        $avg = (float)$item['avg_cost'];
        $bookAvg = ($totalQty > 0.000001 && $value > 0.004) ? $value / $totalQty : $avg;
        // v29: an item with no average cost yet (nothing received at a cost) goes out at its unit cost from the Item List,
        // as the estimates of processing orders already price it, instead of at no cost at all
        if ($qty < 0 && $bookAvg <= 0.0 && (float)$item['cost'] > 0) {
            $bookAvg = (float)$item['cost'];
        }
        return r2($value + self::movementValue($qty, $totalQty, $value, $bookAvg, null, null)[0]);
    }

    /** Quantity committed on open sales orders */
    public static function onSalesOrder(int $itemId, ?int $siteId = null): float
    {
        $sql = "SELECT COALESCE(SUM(GREATEST(l.qty - l.qty_fulfilled, 0)),0)
                  FROM transaction_lines l JOIN transactions t ON t.id = l.txn_id
                 WHERE t.txn_type = 'sales_order' AND t.status IN ('open','partial') AND t.is_voided = 0
                   AND l.is_closed = 0 AND l.item_id = :i";
        $p = ['i' => $itemId];
        if ($siteId) {
            $sql .= ' AND COALESCE(l.site_id, t.site_id) = :s';
            $p['s'] = $siteId;
        }
        return (float)DB::value($sql, $p);
    }

    public static function onPurchaseOrder(int $itemId, ?int $siteId = null): float
    {
        $sql = "SELECT COALESCE(SUM(GREATEST(l.qty - l.qty_fulfilled, 0)),0)
                  FROM transaction_lines l JOIN transactions t ON t.id = l.txn_id
                 WHERE t.txn_type = 'purchase_order' AND t.status IN ('open','partial') AND t.is_voided = 0
                   AND l.is_closed = 0 AND l.item_id = :i";
        $p = ['i' => $itemId];
        if ($siteId) {
            $sql .= ' AND COALESCE(l.site_id, t.site_id) = :s';
            $p['s'] = $siteId;
        }
        return (float)DB::value($sql, $p);
    }
}
