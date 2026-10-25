<?php
declare(strict_types=1);

/**
 * Data utilities: Verify Data (read-only checks) and Rebuild Data (repairs).
 *
 * The main repair is the inventory cost rebuild. Weighted-average costing is sequential:
 * each issue takes the average at the moment it is posted. A purchase entered after a sale
 * but dated before it therefore does not restate the COGS already posted. Rebuilding replays
 * every inventory transaction in date order, so costs, averages and COGS match the dates.
 */
final class Maintenance
{
    // ------------------------------------------------------------------ verify

    /**
     * Read-only consistency checks.
     * @return array list of ['level' => ok|warning|error, 'check' => ..., 'detail' => ..., 'fix' => ...]
     */
    public static function verify(): array
    {
        $out = [];
        $add = function (string $check, bool $ok, string $detail, string $fix = '', string $badLevel = 'error') use (&$out) {
            $out[] = ['level' => $ok ? 'ok' : $badLevel, 'check' => $check, 'detail' => $detail, 'fix' => $ok ? '' : $fix];
        };

        // 1. the ledger balances
        $diff = (float)DB::value('SELECT COALESCE(ROUND(SUM(debit - credit), 2), 0) FROM journal_lines');
        $add('General ledger balances', abs($diff) < 0.005, 'Debits less credits: ' . money($diff), 'Contact support: a posting did not balance.');

        $unbal = (int)DB::value('SELECT COUNT(*) FROM (SELECT txn_id FROM journal_lines GROUP BY txn_id HAVING ABS(SUM(debit - credit)) > 0.005) x');
        $add('Every transaction balances', $unbal === 0, $unbal . ' transaction(s) out of balance', 'Rebuild data re-posts every transaction.');

        // 2. subledgers agree with the ledger
        [$arIn, $arP] = DB::in('a', Accts::arIds() ?: [0]);
        $arGl = (float)DB::value("SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE account_id IN $arIn", $arP);
        $arSub = 0.0;
        foreach (DB::column('SELECT id FROM customers WHERE parent_id IS NULL') as $c) {
            $arSub += Balances::customerBalance((int)$c);
        }
        $add('Accounts receivable matches the customer balances', abs($arGl - $arSub) < 0.05,
            'Ledger ' . money($arGl) . ', customers ' . money($arSub), 'Rebuild data refreshes document balances.');

        [$apIn, $apP] = DB::in('a', Accts::apIds() ?: [0]);
        $apGl = (float)DB::value("SELECT COALESCE(SUM(credit - debit),0) FROM journal_lines WHERE account_id IN $apIn", $apP);
        $apSub = 0.0;
        foreach (DB::column('SELECT id FROM vendors') as $v) {
            $apSub += Balances::vendorBalance((int)$v);
        }
        $add('Accounts payable matches the vendor balances', abs($apGl - $apSub) < 0.05,
            'Ledger ' . money($apGl) . ', vendors ' . money($apSub), 'Rebuild data refreshes document balances.');

        // 3. inventory
        $mv = (float)DB::value('SELECT COALESCE(SUM(total_cost),0) FROM inventory_movements');
        $assetIds = array_map('intval', DB::column("SELECT DISTINCT COALESCE(asset_account_id, :d) FROM items WHERE item_type IN ('inventory','assembly')", ['d' => Accts::get('inventory_asset')]));
        [$iIn, $iP] = DB::in('a', $assetIds ?: [0]);
        $invGl = (float)DB::value("SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE account_id IN $iIn", $iP);
        $add('Inventory ledger matches the inventory movements', abs($invGl - $mv) < 0.05,
            'Ledger ' . money($invGl) . ', movements ' . money($mv), 'Rebuild data recalculates inventory costs.');

        // v31: a site with movements but no quantity row is counted too (that is how the quantities used to drift)
        $qtyBad = DB::all(
            'SELECT i.code, x.site_id, SUM(x.q) AS qty_on_hand, SUM(x.m) AS moved
               FROM (SELECT item_id, site_id, qty_on_hand AS q, 0 AS m FROM item_site_stock
                     UNION ALL SELECT item_id, site_id, 0, SUM(qty) FROM inventory_movements GROUP BY item_id, site_id) x
               JOIN items i ON i.id = x.item_id
              GROUP BY i.code, x.item_id, x.site_id
             HAVING ABS(SUM(x.q) - SUM(x.m)) > 0.0001 LIMIT 20'
        );
        $add('Quantities on hand match the movements', !$qtyBad,
            $qtyBad ? count($qtyBad) . ' item/site row(s) differ: ' . implode(', ', array_map(fn($r) => $r['code'], $qtyBad)) : 'All quantities agree',
            'Rebuild data recalculates quantities from the movements (and the average costs on them).');

        $drift = self::costDrift();
        $add('Average cost matches the value on hand', !$drift,
            $drift ? count($drift) . ' item(s): ' . implode(', ', array_slice(array_map(fn($r) => $r['code'] . ' (' . money($r['diff']) . ')', $drift), 0, 8)) : 'All items agree',
            'Rebuild data recalculates inventory costs.', 'warning');

        $out_of_order = self::outOfDateOrder();
        $add('Inventory entered in date order', !$out_of_order,
            $out_of_order
                ? count($out_of_order) . ' item(s) have movements recorded out of date order (an earlier date entered later, or stock taken out before the same day\'s receipt was entered): ' . implode(', ', array_slice(array_column($out_of_order, 'code'), 0, 8))
                : 'Every item was costed in date order',
            'Rebuild data replays them in date order, the stock received on a date before the stock used or sold that day, so the cost of sales matches.', 'warning');

        $neg = DB::all('SELECT i.code, st.name AS site, s.qty_on_hand FROM item_site_stock s JOIN items i ON i.id = s.item_id JOIN sites st ON st.id = s.site_id WHERE s.qty_on_hand < -0.00001 LIMIT 20');
        $add('No negative quantities on hand', !$neg,
            $neg ? count($neg) . ' item/site row(s): ' . implode(', ', array_map(fn($r) => $r['code'] . ' @ ' . $r['site'] . ' ' . qty($r['qty_on_hand']), $neg)) : 'No negative stock',
            'Record the missing purchase, transfer or adjustment. See the Negative Inventory Listing report.', 'warning');

        // 4. links between documents
        $orphan = (int)DB::value('SELECT COUNT(*) FROM transaction_lines l LEFT JOIN transactions t ON t.id = l.txn_id WHERE t.id IS NULL');
        $add('No orphan transaction lines', $orphan === 0, $orphan . ' line(s) without a transaction', 'Contact support.');

        $overApplied = DB::all(
            "SELECT t.txn_no, t.total, SUM(pa.amount + pa.discount + pa.withholding) AS applied
               FROM payment_applications pa JOIN transactions t ON t.id = pa.target_txn_id
              WHERE t.is_voided = 0 GROUP BY t.id, t.txn_no, t.total
             HAVING SUM(pa.amount + pa.discount + pa.withholding) > t.total + 0.005 LIMIT 10"
        );
        $add('No document is over-applied', !$overApplied,
            $overApplied ? count($overApplied) . ' document(s): ' . implode(', ', array_column($overApplied, 'txn_no')) : 'Payments and credits are within the document totals',
            'Open the payment or credit memo and correct the applied amounts.');

        $stale = [];
        foreach (DB::all("SELECT * FROM transactions
                           WHERE is_voided = 0 AND is_pending = 0
                             AND txn_type IN ('invoice','bill','statement_charge','finance_charge')") as $doc) {
            $open = Balances::open($doc);
            $shouldBePaid = $open <= 0.005;
            if (($shouldBePaid && in_array($doc['status'], ['open', 'partial'], true)) || (!$shouldBePaid && $doc['status'] === 'paid')) {
                $stale[] = $doc['txn_no'];
            }
        }
        $add('Document statuses match their balances', !$stale,
            $stale ? count($stale) . ' document(s): ' . implode(', ', array_slice($stale, 0, 8)) : 'Invoices and bills show the right status',
            'Rebuild data refreshes document balances.');

        // 5. setup
        $missing = [];
        foreach (Accts::KEYS as $key => [$label, $module]) {
            if (!DB::value('SELECT account_id FROM default_accounts WHERE setting_key = :k', ['k' => $key])) {
                $missing[] = $label;
            }
        }
        $add('Module default accounts are set', !$missing, $missing ? implode(', ', $missing) : 'All set',
            'Set them under Financial Set-up & Reports > Module Default Accounts.');

        return $out;
    }

    /** Items whose average cost no longer matches value on hand / quantity on hand */
    public static function costDrift(): array
    {
        $rows = DB::all(
            "SELECT i.id, i.code, i.avg_cost,
                    COALESCE(SUM(m.qty), 0) AS qty, COALESCE(SUM(m.total_cost), 0) AS value
               FROM items i LEFT JOIN inventory_movements m ON m.item_id = i.id
              WHERE i.item_type IN ('inventory','assembly')
              GROUP BY i.id, i.code, i.avg_cost
             HAVING COALESCE(SUM(m.qty), 0) > 0.00001"
        );
        $out = [];
        foreach ($rows as $r) {
            $expected = (float)$r['value'] / (float)$r['qty'];
            $diff = $expected - (float)$r['avg_cost'];
            if (abs($diff) > 0.005) {
                $out[] = ['id' => (int)$r['id'], 'code' => $r['code'], 'avg' => (float)$r['avg_cost'], 'expected' => $expected, 'diff' => $diff];
            }
        }
        return $out;
    }

    /**
     * Items whose movements were costed in a different order than they happened: an earlier date entered after a later
     * one, or (v30) on one date, stock taken out before the stock that came in that day was entered (Stock::dayRanks).
     */
    public static function outOfDateOrder(): array
    {
        $out = [];
        $prev = [];
        $moves = DB::all('SELECT item_id, txn_id, txn_date, id FROM inventory_movements ORDER BY item_id, id');
        $ranks = Stock::dayRanks(array_column($moves, 'txn_id'));
        foreach ($moves as $m) {
            $i = (int)$m['item_id'];
            $key = [$m['txn_date'], $ranks[(int)$m['txn_id']] ?? 1];
            if (isset($prev[$i]) && $key < $prev[$i]) {
                $out[$i] = true;
            }
            $prev[$i] = $key;
        }
        if (!$out) {
            return [];
        }
        [$in, $p] = DB::in('id', array_keys($out));
        return DB::all("SELECT id, code FROM items WHERE id IN $in ORDER BY code", $p);
    }

    // ------------------------------------------------------------------ rebuild

    /**
     * Replays every inventory transaction in date order, then refreshes document balances.
     * Returns a summary of what changed.
     */
    public static function rebuild(bool $costs = true, bool $balances = true): array
    {
        $summary = ['transactions' => 0, 'items' => 0, 'cogs_before' => 0.0, 'cogs_after' => 0.0, 'changed' => [], 'balances' => 0];
        DB::tx(function () use (&$summary, $costs, $balances) {
            if ($costs) {
                $cogsAccounts = array_map('intval', DB::column("SELECT DISTINCT COALESCE(cogs_account_id, :d) FROM items WHERE item_type IN ('inventory','assembly')", ['d' => Accts::get('cogs')]));
                [$cIn, $cP] = DB::in('a', $cogsAccounts ?: [0]);

                // transactions that moved inventory, oldest first: by date, then (v30) the ones bringing stock in before
                // the ones taking it out on the same date (Stock::dayRanks), then by entry order
                $txns = DB::all(
                    'SELECT DISTINCT m.txn_id AS id, t.txn_date, t.txn_no, t.txn_type
                       FROM inventory_movements m JOIN transactions t ON t.id = m.txn_id
                      ORDER BY t.txn_date, m.txn_id'
                );
                $ranks = Stock::dayRanks(array_column($txns, 'id'));
                usort($txns, fn($a, $b) => [$a['txn_date'], $ranks[(int)$a['id']] ?? 1, (int)$a['id']] <=> [$b['txn_date'], $ranks[(int)$b['id']] ?? 1, (int)$b['id']]);
                $before = [];
                foreach ($txns as $t) {
                    $before[(int)$t['id']] = (float)DB::value(
                        "SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines WHERE txn_id = :t AND account_id IN $cIn",
                        ['t' => $t['id']] + $cP
                    );
                }
                $itemIds = array_map('intval', DB::column('SELECT DISTINCT item_id FROM inventory_movements'));

                // clear the perpetual records, then post again in date order
                DB::run('DELETE FROM inventory_movements');
                DB::run('UPDATE item_site_stock SET qty_on_hand = 0');
                DB::run("UPDATE items SET avg_cost = 0 WHERE item_type IN ('inventory','assembly')");
                // re-posting history is not a new sale: the below-zero rule is for documents being saved now
                Posting::$stockCheck = false;
                try {
                    foreach ($txns as $t) {
                        Posting::post((int)$t['id']);
                    }
                } finally {
                    Posting::$stockCheck = true;
                }
                // v31: and every quick-lookup quantity agrees with the movements it came from
                Stock::resync();
                DB::run('DELETE FROM item_site_stock WHERE qty_on_hand = 0');

                foreach ($txns as $t) {
                    $id = (int)$t['id'];
                    $after = (float)DB::value(
                        "SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines WHERE txn_id = :t AND account_id IN $cIn",
                        ['t' => $id] + $cP
                    );
                    $summary['cogs_before'] += $before[$id] ?? 0.0;
                    $summary['cogs_after'] += $after;
                    if (abs($after - ($before[$id] ?? 0.0)) > 0.005) {
                        $summary['changed'][] = [
                            'txn_no' => $t['txn_no'], 'type' => Txn::label($t['txn_type']), 'date' => $t['txn_date'],
                            'before' => $before[$id] ?? 0.0, 'after' => $after, 'id' => $id,
                        ];
                    }
                }
                $summary['transactions'] = count($txns);
                $summary['items'] = count($itemIds);
            }

            if ($balances) {
                $docs = DB::column(
                    "SELECT id FROM transactions
                      WHERE txn_type IN ('invoice','bill','statement_charge','finance_charge','credit_memo','payment','vendor_credit','sales_receipt')
                        AND is_voided = 0"
                );
                foreach ($docs as $id) {
                    Balances::refresh((int)$id);
                }
                foreach (DB::all("SELECT id, txn_type FROM transactions WHERE txn_type IN ('estimate','sales_order','purchase_order') AND is_voided = 0") as $o) {
                    Txn::refreshOrderStatus((int)$o['id'], $o['txn_type']);
                }
                $summary['balances'] = count($docs);
            }
        });
        return $summary;
    }
}
