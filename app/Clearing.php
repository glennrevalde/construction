<?php
declare(strict_types=1);

/**
 * Card and e-wallet clearing accounts (v28), for the settlements on Make Deposits and their Details.
 *
 * A card or e-wallet sale (POS tender, customer payment, sales receipt) is debited to the clearing account of its
 * payment method (Lists > Payment Methods), or to the module default account Card Clearing / E-wallet Clearing when the
 * method names none. A provider settlement deposited on Make Deposits credits it. The unsettled amount is therefore the
 * balance of the clearing account in the General Ledger: the sum of its debits less its credits up to a date, the
 * figure the Trial Balance shows for that date.
 *
 * Every clearing account is listed once, however many payment methods point to it (GCash and Maya both on E-wallet
 * Clearing, for example). Before v28 the list joined the payment methods to the ledger lines, so an account shared by
 * two methods showed twice its balance.
 */
final class Clearing
{
    /** the money-in documents of a clearing account, for the unsettled receipts */
    private const SOURCES = [
        'pos_sale' => 'POS sales', 'pos_return' => 'POS returns refunded', 'payment' => 'Customer payments',
        'sales_receipt' => 'Sales receipts', 'deposit' => 'Settlements deposited to the bank', 'refund' => 'Refunds to customers',
        'journal' => 'Journal entries', 'transfer' => 'Transfers',
    ];

    /**
     * The clearing accounts: [account id => [id, code, name, kind (card / ewallet), methods (names)]], in code order.
     * The accounts of the card and e-wallet payment methods, and the two module default accounts.
     */
    public static function accounts(): array
    {
        $ids = [];
        $methods = [];
        $kind = [];
        $defaults = DB::pairs("SELECT setting_key, account_id FROM default_accounts WHERE setting_key IN ('card_clearing','ewallet_clearing') AND account_id IS NOT NULL");
        foreach ($defaults as $key => $aid) {
            $ids[(int)$aid] = true;
            $kind[(int)$aid] = $key === 'card_clearing' ? 'card' : 'ewallet';
        }
        foreach (DB::all("SELECT id, name, method_type, clearing_account_id FROM payment_methods WHERE method_type IN ('card','ewallet') ORDER BY name") as $m) {
            $aid = (int)($m['clearing_account_id'] ?: ($defaults[$m['method_type'] === 'card' ? 'card_clearing' : 'ewallet_clearing'] ?? 0));
            if (!$aid) {
                continue;
            }
            $ids[$aid] = true;
            $methods[$aid][] = $m['name'];
            $kind[$aid] = $kind[$aid] ?? $m['method_type'];
        }
        if (!$ids) {
            return [];
        }
        [$in, $p] = DB::in('clr', array_keys($ids));
        $out = [];
        foreach (DB::all("SELECT id, code, name FROM accounts WHERE id IN $in ORDER BY code", $p) as $a) {
            $a['kind'] = $kind[(int)$a['id']] ?? 'card';
            $a['methods'] = $methods[(int)$a['id']] ?? [];
            $out[(int)$a['id']] = $a;
        }
        return $out;
    }

    /** The payment methods whose sales go to a clearing account: [method id => name] */
    public static function methodIds(int $accountId): array
    {
        $defaults = DB::pairs("SELECT setting_key, account_id FROM default_accounts WHERE setting_key IN ('card_clearing','ewallet_clearing')");
        $out = [];
        foreach (DB::all("SELECT id, name, method_type, clearing_account_id FROM payment_methods WHERE method_type IN ('card','ewallet') ORDER BY name") as $m) {
            $aid = (int)($m['clearing_account_id'] ?: ($defaults[$m['method_type'] === 'card' ? 'card_clearing' : 'ewallet_clearing'] ?? 0));
            if ($aid === $accountId) {
                $out[(int)$m['id']] = $m['name'];
            }
        }
        return $out;
    }

    /**
     * The clearing accounts with their General Ledger balance as of a date (debits less credits, as on the Trial
     * Balance): the unsettled amount. Accounts with a zero balance are left out unless $all.
     */
    public static function balances(string $asOf, bool $all = false): array
    {
        $accts = self::accounts();
        if (!$accts) {
            return [];
        }
        [$in, $p] = DB::in('clb', array_keys($accts));
        $bal = DB::pairs("SELECT account_id, SUM(debit - credit) FROM journal_lines WHERE account_id IN $in AND txn_date <= :asof GROUP BY account_id", $p + ['asof' => $asOf]);
        $out = [];
        foreach ($accts as $id => $a) {
            $a['bal'] = r2((float)($bal[$id] ?? 0));
            if ($all || abs($a['bal']) >= 0.005) {
                $out[$id] = $a;
            }
        }
        return $out;
    }

    /** The General Ledger balance of one clearing account as of a date */
    public static function balance(int $accountId, string $asOf): float
    {
        return r2((float)DB::value('SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE account_id = :a AND txn_date <= :d', ['a' => $accountId, 'd' => $asOf]));
    }

    /**
     * What the balance of a clearing account is made of, as of a date:
     *   sources     the ledger by kind of document (POS sales, payments, settlements...): documents, debits, credits
     *   methods     the card / e-wallet sales by payment method (POS tenders, payments and sales receipts)
     *   unsettled   the receipts not yet settled: the latest money-in documents that make up the balance, the
     *               settlements being taken against the oldest receipts first
     *   settlements the latest settlements and other credits
     */
    public static function details(int $accountId, string $asOf): array
    {
        $balance = self::balance($accountId, $asOf);
        $sources = [];
        foreach (DB::all("SELECT t.txn_type, COUNT(DISTINCT j.txn_id) AS docs, SUM(j.debit) AS dr, SUM(j.credit) AS cr
                            FROM journal_lines j JOIN transactions t ON t.id = j.txn_id
                           WHERE j.account_id = :a AND j.txn_date <= :d GROUP BY t.txn_type ORDER BY SUM(j.debit) DESC, SUM(j.credit) DESC",
            ['a' => $accountId, 'd' => $asOf]) as $r) {
            $sources[] = ['type' => $r['txn_type'], 'label' => self::SOURCES[$r['txn_type']] ?? Txn::label($r['txn_type']), 'docs' => (int)$r['docs'],
                'dr' => r2((float)$r['dr']), 'cr' => r2((float)$r['cr']), 'net' => r2((float)$r['dr'] - (float)$r['cr'])];
        }

        // card / e-wallet sales by payment method
        $methods = [];
        $mids = self::methodIds($accountId);
        if ($mids) {
            [$min, $mp] = DB::in('clm', array_keys($mids));
            foreach (DB::all("SELECT pt.payment_method_id AS mid,
                                     SUM(CASE WHEN t.txn_type = 'pos_sale' THEN pt.amount ELSE 0 END) AS sold,
                                     SUM(CASE WHEN t.txn_type = 'pos_return' THEN pt.amount ELSE 0 END) AS refunded,
                                     COUNT(DISTINCT t.id) AS docs
                                FROM pos_tenders pt JOIN transactions t ON t.id = pt.txn_id
                               WHERE pt.payment_method_id IN $min AND t.is_voided = 0 AND t.is_pending = 0 AND t.status <> 'suspended' AND t.txn_date <= :d
                               GROUP BY pt.payment_method_id", $mp + ['d' => $asOf]) as $r) {
                $methods[(int)$r['mid']] = ['name' => $mids[(int)$r['mid']], 'pos' => r2((float)$r['sold']), 'refunded' => r2((float)$r['refunded']), 'other' => 0.0, 'docs' => (int)$r['docs']];
            }
            foreach (DB::all("SELECT t.payment_method_id AS mid, SUM(t.total) AS amt, COUNT(*) AS docs FROM transactions t
                               WHERE t.payment_method_id IN $min AND t.account_id = :a AND t.txn_type IN ('payment','sales_receipt')
                                 AND t.is_voided = 0 AND t.is_pending = 0 AND t.txn_date <= :d GROUP BY t.payment_method_id",
                $mp + ['a' => $accountId, 'd' => $asOf]) as $r) {
                $m = &$methods[(int)$r['mid']];
                $m = ($m ?? ['name' => $mids[(int)$r['mid']], 'pos' => 0.0, 'refunded' => 0.0, 'other' => 0.0, 'docs' => 0]);
                $m['other'] = r2((float)$r['amt']);
                $m['docs'] += (int)$r['docs'];
                unset($m);
            }
            foreach ($methods as &$m) {
                $m['net'] = r2($m['pos'] - $m['refunded'] + $m['other']);
            }
            unset($m);
            uasort($methods, fn($x, $y) => strcmp($x['name'], $y['name']));
        }

        // the receipts not yet settled: the latest money-in documents, newest first, until they make up the balance
        $unsettled = [];
        if ($balance > 0.004) {
            $left = $balance;
            $offset = 0;
            while ($left > 0.004) {
                $batch = DB::all("SELECT t.id, t.txn_type, t.txn_no, t.receipt_no, t.txn_date, t.ref_no, t.created_at, t.payment_method_id,
                                         COALESCE(c.name, t.payee_name) AS party, pt2.name AS terminal, SUM(j.debit - j.credit) AS net
                                    FROM journal_lines j JOIN transactions t ON t.id = j.txn_id
                                    LEFT JOIN customers c ON c.id = t.customer_id LEFT JOIN pos_terminals pt2 ON pt2.id = t.terminal_id
                                   WHERE j.account_id = :a AND j.txn_date <= :d
                                   GROUP BY t.id, t.txn_type, t.txn_no, t.receipt_no, t.txn_date, t.ref_no, t.created_at, t.payment_method_id, c.name, t.payee_name, pt2.name
                                  HAVING SUM(j.debit - j.credit) > 0.004
                                   ORDER BY t.txn_date DESC, t.id DESC LIMIT 200 OFFSET $offset", ['a' => $accountId, 'd' => $asOf]);
                if (!$batch) {
                    break;
                }
                foreach ($batch as $r) {
                    $net = r2((float)$r['net']);
                    $open = r2(min($net, $left));
                    $r['amount'] = $net;
                    $r['open'] = $open;
                    $unsettled[] = $r;
                    $left = r2($left - $open);
                    if ($left <= 0.004) {
                        break;
                    }
                }
                $offset += 200;
                if (count($unsettled) >= 2000) {
                    break;
                }
            }
            // the payment method and reference of each receipt
            $posIds = array_map(fn($r) => (int)$r['id'], array_filter($unsettled, fn($r) => in_array($r['txn_type'], ['pos_sale', 'pos_return'], true)));
            $tend = [];
            if ($posIds && $mids) {
                [$tin, $tp] = DB::in('clt', $posIds);
                [$min, $mp] = DB::in('clu', array_keys($mids));
                foreach (DB::all("SELECT pt.txn_id, pt.payment_method_id, pt.amount, pt.reference FROM pos_tenders pt WHERE pt.txn_id IN $tin AND pt.payment_method_id IN $min ORDER BY pt.id", $tp + $mp) as $tn) {
                    $tend[(int)$tn['txn_id']][] = $tn;
                }
            }
            $allMethods = DB::pairs('SELECT id, name FROM payment_methods');
            foreach ($unsettled as &$r) {
                $ms = [];
                $refs = [];
                foreach ($tend[(int)$r['id']] ?? [] as $tn) {
                    $ms[] = $allMethods[(int)$tn['payment_method_id']] ?? '';
                    if ($tn['reference']) {
                        $refs[] = $tn['reference'];
                    }
                }
                if (!$ms && $r['payment_method_id']) {
                    $ms[] = $allMethods[(int)$r['payment_method_id']] ?? '';
                }
                if (!$refs && $r['ref_no']) {
                    $refs[] = $r['ref_no'];
                }
                $r['method'] = implode(', ', array_unique(array_filter($ms)));
                $r['reference'] = implode(', ', array_unique($refs));
                $r['who'] = $r['party'] ?: ($r['terminal'] ?? '');
            }
            unset($r);
        }

        // the latest settlements and other credits
        $credits = DB::all("SELECT t.id, t.txn_type, t.txn_no, t.receipt_no, t.txn_date, t.ref_no, t.memo, SUM(j.credit - j.debit) AS amount,
                                   (SELECT GROUP_CONCAT(DISTINCT l.description SEPARATOR '; ') FROM transaction_lines l WHERE l.txn_id = t.id AND l.account_id = :a2) AS descr
                              FROM journal_lines j JOIN transactions t ON t.id = j.txn_id
                             WHERE j.account_id = :a AND j.txn_date <= :d
                             GROUP BY t.id, t.txn_type, t.txn_no, t.receipt_no, t.txn_date, t.ref_no, t.memo
                            HAVING SUM(j.credit - j.debit) > 0.004
                             ORDER BY t.txn_date DESC, t.id DESC LIMIT 25", ['a' => $accountId, 'a2' => $accountId, 'd' => $asOf]);

        return ['balance' => $balance, 'sources' => $sources, 'methods' => array_values($methods), 'unsettled' => $unsettled, 'credits' => $credits];
    }
}
