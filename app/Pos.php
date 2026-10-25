<?php
declare(strict_types=1);

/** Point of Sale helpers: terminals, shifts, readings. */
final class Pos
{
    /** Terminals the current user may use */
    public static function terminals(): array
    {
        $uid = Auth::id();
        $assigned = DB::column('SELECT terminal_id FROM user_terminals WHERE user_id = :u', ['u' => $uid]);
        $rows = DB::all('SELECT t.*, s.name AS site_name FROM pos_terminals t JOIN sites s ON s.id = t.site_id WHERE t.is_active = 1 ORDER BY t.code');
        $out = [];
        foreach ($rows as $r) {
            if ($assigned && !Access::isAdmin() && !in_array((string)$r['id'], array_map('strval', $assigned), true)) {
                continue;
            }
            if (!Access::siteAllowed((int)$r['site_id'])) {
                continue;
            }
            $out[(int)$r['id']] = $r;
        }
        return $out;
    }

    public static function terminal(): ?array
    {
        $list = self::terminals();
        $id = (int)($_SESSION['pos_terminal_id'] ?? 0);
        if (!isset($list[$id])) {
            // nothing chosen yet: the cashier's own default site decides which counter opens
            $id = (int)(self::terminalAtSite(self::defaults()['site_id']) ?: array_key_first($list) ?: 0);
            $_SESSION['pos_terminal_id'] = $id ?: null;
        }
        return $list[$id] ?? null;
    }

    /** The first terminal this user may use at a site, or 0 when there is none */
    public static function terminalAtSite(?int $siteId): int
    {
        if (!$siteId) {
            return 0;
        }
        foreach (self::terminals() as $id => $t) {
            if ((int)$t['site_id'] === $siteId) {
                return (int)$id;
            }
        }
        return 0;
    }

    /** The sites the terminals this user may use are in: id => name */
    public static function sites(): array
    {
        $out = [];
        foreach (self::terminals() as $t) {
            $out[(int)$t['site_id']] = (string)$t['site_name'];
        }
        asort($out);
        return $out;
    }

    // ------------------------------------------------------------------ a cashier's own site and class
    // Set up in System Administration > Company, Security & Closing Date > Default site & class for
    // POS transactions. Both are optional: with neither set the register behaves exactly as it always has.

    /** ['site_id' => ?int, 'class_id' => ?int] for a user */
    public static function defaults(?int $userId = null): array
    {
        static $cache = [];
        $userId = $userId ?: Auth::id();
        if (!$userId) {
            return ['site_id' => null, 'class_id' => null];
        }
        if (!isset($cache[$userId])) {
            $r = null;
            try {
                // a site or class that has since been deleted or switched off counts as no default at all
                $r = DB::row(
                    'SELECT s.id AS site_id, c.id AS class_id
                       FROM users u
                       LEFT JOIN sites s ON s.id = u.pos_default_site_id AND s.is_active = 1
                       LEFT JOIN classes c ON c.id = u.pos_default_class_id AND c.is_active = 1
                      WHERE u.id = :u',
                    ['u' => $userId]
                );
            } catch (Throwable $e) {
                // the columns are only there once the POS defaults migration has been run
            }
            $cache[$userId] = [
                'site_id'  => $r && $r['site_id'] ? (int)$r['site_id'] : null,
                'class_id' => $r && $r['class_id'] ? (int)$r['class_id'] : null,
            ];
        }
        return $cache[$userId];
    }

    /** Are the two columns there yet? (false before the migration is run) */
    public static function defaultsReady(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                DB::value('SELECT COUNT(pos_default_site_id) + COUNT(pos_default_class_id) FROM users WHERE 1 = 0');
                $ok = true;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    /** Every user the defaults can be set for, with what is set now */
    public static function defaultRows(): array
    {
        if (!self::defaultsReady()) {
            return [];
        }
        return DB::all(
            "SELECT u.id, u.username, u.full_name, u.pos_default_site_id, u.pos_default_class_id,
                    (SELECT GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ', ')
                       FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS role_names
               FROM users u
              WHERE u.status <> 'inactive' AND u.is_platform = 0
              ORDER BY u.full_name, u.username"
        );
    }

    /**
     * Saves the defaults posted from the settings page.
     * @param array $sites   user id => site id (blank = none)
     * @param array $classes user id => class id (blank = none)
     * @return array the changes, ready for the audit trail
     */
    public static function saveDefaults(array $sites, array $classes): array
    {
        if (!self::defaultsReady()) {
            return [];
        }
        $validSites = DB::pairs('SELECT id, name FROM sites');
        $validClasses = Classes::ready() ? Classes::options() : [];
        $changes = [];
        foreach (self::defaultRows() as $u) {
            $uid = (int)$u['id'];
            $site = (int)($sites[$uid] ?? 0);
            $class = (int)($classes[$uid] ?? 0);
            $site = $site && isset($validSites[$site]) ? $site : null;
            $class = $class && isset($validClasses[$class]) ? $class : null;
            $wasSite = $u['pos_default_site_id'] ? (int)$u['pos_default_site_id'] : null;
            $wasClass = $u['pos_default_class_id'] ? (int)$u['pos_default_class_id'] : null;
            if ($site === $wasSite && $class === $wasClass) {
                continue;
            }
            DB::run('UPDATE users SET pos_default_site_id = :s, pos_default_class_id = :c WHERE id = :u',
                ['s' => $site, 'c' => $class, 'u' => $uid]);
            if ($site !== $wasSite) {
                $changes[] = ['field' => 'POS default site: ' . $u['full_name'],
                    'old' => $wasSite ? ($validSites[$wasSite] ?? $wasSite) : '(none)',
                    'new' => $site ? ($validSites[$site] ?? $site) : '(none)'];
            }
            if ($class !== $wasClass) {
                $changes[] = ['field' => 'POS default class: ' . $u['full_name'],
                    'old' => $wasClass ? ($validClasses[$wasClass] ?? $wasClass) : '(none)',
                    'new' => $class ? ($validClasses[$class] ?? $class) : '(none)'];
            }
        }
        return $changes;
    }

    public static function openShift(int $terminalId): ?array
    {
        return DB::row("SELECT s.*, u.full_name FROM pos_shifts s JOIN users u ON u.id = s.cashier_id WHERE s.terminal_id = :t AND s.status = 'open' ORDER BY s.id DESC LIMIT 1", ['t' => $terminalId]);
    }

    public static function nextReceipt(int $terminalId): string
    {
        return DB::tx(function () use ($terminalId) {
            $t = DB::row('SELECT receipt_prefix, next_receipt_no FROM pos_terminals WHERE id = :id FOR UPDATE', ['id' => $terminalId]);
            DB::run('UPDATE pos_terminals SET next_receipt_no = next_receipt_no + 1 WHERE id = :id', ['id' => $terminalId]);
            return $t['receipt_prefix'] . str_pad((string)$t['next_receipt_no'], 8, '0', STR_PAD_LEFT);
        });
    }

    /** Figures for X- and Z-readings and the cash count */
    public static function summary(int $shiftId): array
    {
        $shift = DB::row('SELECT * FROM pos_shifts WHERE id = :id', ['id' => $shiftId]);
        $sales = DB::all("SELECT * FROM transactions WHERE pos_shift_id = :s AND txn_type = 'pos_sale' AND status <> 'suspended' ORDER BY id", ['s' => $shiftId]);
        $live = array_values(array_filter($sales, fn($t) => (int)$t['is_voided'] === 0));
        $voided = array_values(array_filter($sales, fn($t) => (int)$t['is_voided'] === 1));
        $returns = DB::all("SELECT * FROM transactions WHERE pos_shift_id = :s AND txn_type = 'pos_return' AND is_voided = 0", ['s' => $shiftId]);
        [$in, $p] = DB::in('sx', array_column($live, 'id') ?: [0]);
        $lineAgg = DB::row(
            "SELECT COALESCE(SUM(CASE WHEN i.item_type NOT IN ('discount','payment','subtotal') THEN l.amount + l.discount_amount ELSE 0 END),0) AS gross,
                    COALESCE(SUM(CASE WHEN i.item_type = 'discount' THEN -l.amount ELSE l.discount_amount END),0) AS disc
               FROM transaction_lines l LEFT JOIN items i ON i.id = l.item_id WHERE l.txn_id IN $in",
            $p
        );
        $tenders = [];
        foreach (DB::all(
            "SELECT pm.name, pm.method_type, t.txn_type, SUM(pt.amount) AS amt FROM pos_tenders pt
               JOIN transactions t ON t.id = pt.txn_id JOIN payment_methods pm ON pm.id = pt.payment_method_id
              WHERE t.pos_shift_id = :s AND t.is_voided = 0 AND t.status <> 'suspended' GROUP BY pm.name, pm.method_type, t.txn_type",
            ['s' => $shiftId]
        ) as $r) {
            $sign = $r['txn_type'] === 'pos_return' ? -1 : 1;
            $tenders[$r['name']] = ($tenders[$r['name']] ?? 0) + $sign * (float)$r['amt'];
        }
        $cashSales = (float)DB::value(
            "SELECT COALESCE(SUM(CASE WHEN t.txn_type = 'pos_return' THEN -pt.amount ELSE pt.amount END),0)
               FROM pos_tenders pt JOIN transactions t ON t.id = pt.txn_id JOIN payment_methods pm ON pm.id = pt.payment_method_id
              WHERE t.pos_shift_id = :s AND t.is_voided = 0 AND t.status <> 'suspended' AND pm.method_type = 'cash'",
            ['s' => $shiftId]
        );
        $cashPayments = (float)DB::value(
            "SELECT COALESCE(SUM(t.total),0) FROM transactions t JOIN payment_methods pm ON pm.id = t.payment_method_id
              WHERE t.pos_shift_id = :s AND t.txn_type = 'payment' AND t.is_voided = 0 AND pm.method_type = 'cash'",
            ['s' => $shiftId]
        );
        if ($cashPayments) {
            $tenders['Cash (account payments)'] = $cashPayments;
        }
        $charges = (float)DB::value("SELECT COALESCE(SUM(total),0) FROM transactions WHERE pos_shift_id = :s AND txn_type = 'invoice' AND is_voided = 0", ['s' => $shiftId]);
        if ($charges) {
            $tenders['Charged to accounts'] = $charges;
        }
        $drops = (float)DB::value('SELECT COALESCE(SUM(amount),0) FROM pos_cash_drops WHERE shift_id = :s', ['s' => $shiftId]);
        $receipts = array_filter(array_column($live, 'receipt_no'));
        return [
            'shift'         => $shift,
            'sales_count'   => count($live),
            'first_receipt' => $receipts ? reset($receipts) : null,
            'last_receipt'  => $receipts ? end($receipts) : null,
            'gross_sales'   => r2($lineAgg['gross']),
            'discounts'     => r2($lineAgg['disc']),
            'returns'       => r2(array_sum(array_column($returns, 'total'))),
            'voids'         => r2(array_sum(array_column($voided, 'total'))),
            'void_count'    => count($voided),
            'net_sales'     => r2(array_sum(array_column($live, 'subtotal')) - array_sum(array_column($returns, 'subtotal'))),
            'tax'           => r2(array_sum(array_column($live, 'tax_total')) - array_sum(array_column($returns, 'tax_total'))),
            'total'         => r2(array_sum(array_column($live, 'total')) - array_sum(array_column($returns, 'total'))),
            'tenders'       => $tenders,
            'cash_drops'    => r2($drops),
            'expected_cash' => r2((float)$shift['opening_float'] + $cashSales + $cashPayments - $drops),
        ];
    }

    public static function saveReading(string $type, int $shiftId, ?float $counted = null): int
    {
        $s = self::summary($shiftId);
        $shift = $s['shift'];
        $zNo = null;
        if ($type === 'Z') {
            $zNo = (int)DB::value('SELECT next_z_no FROM pos_terminals WHERE id = :id', ['id' => $shift['terminal_id']]);
            DB::run('UPDATE pos_terminals SET next_z_no = next_z_no + 1 WHERE id = :id', ['id' => $shift['terminal_id']]);
        }
        return DB::insert('pos_readings', [
            'reading_type' => $type, 'terminal_id' => $shift['terminal_id'], 'shift_id' => $shiftId, 'z_no' => $zNo,
            'business_date' => $shift['business_date'], 'first_receipt' => $s['first_receipt'], 'last_receipt' => $s['last_receipt'],
            'sales_count' => $s['sales_count'], 'gross_sales' => $s['gross_sales'], 'discounts' => $s['discounts'],
            'returns' => $s['returns'], 'voids' => $s['voids'], 'net_sales' => $s['net_sales'], 'tax' => $s['tax'],
            'tenders_json' => json_encode($s['tenders']), 'cash_drops' => $s['cash_drops'], 'expected_cash' => $s['expected_cash'],
            'counted_cash' => $counted, 'over_short' => $counted === null ? null : r2($counted - $s['expected_cash']),
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * Corrects what was entered when a register was closed: the opening float typed when the shift was opened,
     * the cash drops, and the cash counted at the Z-reading. The expected cash and the over/short are worked out
     * again, and the Cash Over/Short journal entry follows: it is changed, made (there was none and now there is a
     * difference) or voided (the difference is gone). The Z-reading keeps its number and prints the new figures.
     *
     * $c: opening_float, counted_cash, drops [drop id => amount, 0 removes it], new_drop [amount, reason].
     * Returns [before, after] as label => value, for the message and the audit trail.
     */
    /**
     * The cash of a closed shift that goes to the bank: what was counted, less the opening float (it stays in the
     * drawer for the next shift), plus the cash drops (taken out of the drawer during the shift).
     * $s: a pos_shifts row; its 'drops' total is read when it is not there.
     */
    public static function toDeposit(array $s): float
    {
        $drops = array_key_exists('drops', $s) ? (float)$s['drops']
            : (float)DB::value('SELECT COALESCE(SUM(amount),0) FROM pos_cash_drops WHERE shift_id = :s', ['s' => $s['id']]);
        return r2((float)$s['counted_cash'] - (float)$s['opening_float'] + $drops);
    }

    /**
     * After a correction: the deposit that took the shift's cash to the bank is changed to the shift's new figures
     * (its line for the shift, and so its total and its ledger entries). Returns what changed, or null when the
     * shift's cash is not on a deposit.
     */
    public static function followDeposit(int $shiftId, string $reason, ?array $approver = null): ?array
    {
        $shift = DB::row('SELECT * FROM pos_shifts WHERE id = :id', ['id' => $shiftId]);
        $dep = $shift && $shift['deposit_txn_id'] ? Txn::load((int)$shift['deposit_txn_id']) : null;
        if (!$dep || (int)$dep['is_voided'] === 1) {
            return null;
        }
        $lines = DB::all('SELECT * FROM transaction_lines WHERE txn_id = :t ORDER BY line_no, id', ['t' => $dep['id']]);
        $at = null;
        foreach ($lines as $i => $l) {
            if ((int)($l['pos_shift_id'] ?? 0) === $shiftId
                || (empty($l['pos_shift_id']) && empty($l['ref_txn_id']) && str_ends_with((string)$l['description'], '(shift #' . $shiftId . ')'))) {
                $at = $i;
                break;
            }
        }
        if ($at === null) {
            fail('Deposit ' . $dep['txn_no'] . ' holds the cash of this shift but has no line for it. Edit the deposit first (take the shift off and tick it again).');
        }
        $new = self::toDeposit($shift);
        $old = r2((float)$lines[$at]['amount']);
        if (abs($new - $old) < 0.005 && (int)($lines[$at]['pos_shift_id'] ?? 0) === $shiftId) {
            return ['id' => (int)$dep['id'], 'txn_no' => $dep['txn_no'], 'old' => $old, 'new' => $new, 'changed' => false];
        }
        if ($new <= 0.004) {
            fail('After this correction nothing is left to deposit from the shift (counted cash less the float plus the drops), but deposit '
                . $dep['txn_no'] . ' holds ' . money($old) . ' for it. Take the shift off the deposit first (Edit on the deposit), then correct the shift.');
        }
        $lines[$at]['amount'] = $new;
        $lines[$at]['pos_shift_id'] = $shiftId;
        foreach ($lines as &$l) {
            unset($l['txn_id'], $l['line_no']);
        }
        unset($l);
        $h = ['txn_date' => $dep['txn_date'], 'account_id' => $dep['account_id'], 'account2_id' => $dep['account2_id'], 'memo' => $dep['memo'],
            'ref_no' => $dep['ref_no'], 'cash_back_amount' => $dep['cash_back_amount'], 'status' => $dep['status']];
        if (array_key_exists('class_id', $dep)) {
            $h['class_id'] = $dep['class_id'];
        }
        Txn::save('deposit', $h, $lines, (int)$dep['id'], [
            'reason' => mb_substr('POS shift #' . $shiftId . ' corrected: its cash to deposit is now ' . money($new) . ' (was ' . money($old) . '). ' . $reason, 0, 255),
            'approved_by' => $approver['username'] ?? null,
        ]);
        return ['id' => (int)$dep['id'], 'txn_no' => $dep['txn_no'], 'old' => $old, 'new' => $new, 'changed' => true];
    }

    public static function correctShift(int $shiftId, array $c, string $reason, ?array $approver = null): array
    {
        return DB::tx(function () use ($shiftId, $c, $reason, $approver) {
            $shift = DB::row('SELECT * FROM pos_shifts WHERE id = :id FOR UPDATE', ['id' => $shiftId]);
            if (!$shift || $shift['status'] !== 'closed') {
                fail('Only a closed shift can be corrected.');
            }
            if (trim($reason) === '') {
                fail('Say why the closing entries are corrected (it is kept in the audit trail).');
            }
            $term = DB::row('SELECT * FROM pos_terminals WHERE id = :id', ['id' => $shift['terminal_id']]);
            $oldDrops = DB::all('SELECT * FROM pos_cash_drops WHERE shift_id = :s ORDER BY id', ['s' => $shiftId]);
            $before = self::summary($shiftId);
            $changes = [];

            // the opening float
            $float = array_key_exists('opening_float', $c) && $c['opening_float'] !== null ? r2((float)$c['opening_float']) : (float)$shift['opening_float'];
            if ($float < 0) {
                fail('The opening float cannot be less than zero.');
            }
            if (abs($float - (float)$shift['opening_float']) >= 0.005) {
                DB::update('pos_shifts', ['opening_float' => $float], 'id = :id', ['id' => $shiftId]);
                $changes[] = ['field' => 'Opening cash float', 'old' => money($shift['opening_float']), 'new' => money($float)];
            }
            // the cash drops: changed, taken out, one added
            foreach ($oldDrops as $d) {
                if (!array_key_exists((int)$d['id'], (array)($c['drops'] ?? []))) {
                    continue;
                }
                $amt = r2((float)$c['drops'][(int)$d['id']]);
                if ($amt < 0) {
                    fail('A cash drop cannot be less than zero.');
                }
                if (abs($amt - (float)$d['amount']) < 0.005) {
                    continue;
                }
                if ($amt < 0.005) {
                    DB::run('DELETE FROM pos_cash_drops WHERE id = :id', ['id' => $d['id']]);
                    $changes[] = ['field' => 'Cash drop #' . $d['id'] . ' (' . $d['reason'] . ')', 'old' => money($d['amount']), 'new' => 'removed'];
                } else {
                    DB::update('pos_cash_drops', ['amount' => $amt], 'id = :id', ['id' => $d['id']]);
                    $changes[] = ['field' => 'Cash drop #' . $d['id'] . ' (' . $d['reason'] . ')', 'old' => money($d['amount']), 'new' => money($amt)];
                }
            }
            $newDrop = r2((float)($c['new_drop'][0] ?? 0));
            if ($newDrop < 0) {
                fail('A cash drop cannot be less than zero.');
            }
            if ($newDrop >= 0.005) {
                $why = trim((string)($c['new_drop'][1] ?? '')) ?: 'Cash drop (entered on correction)';
                $nid = DB::insert('pos_cash_drops', ['shift_id' => $shiftId, 'amount' => $newDrop, 'reason' => mb_substr($why, 0, 255),
                    'created_by' => Auth::id(), 'approved_by' => $approver['id'] ?? Auth::id()]);
                $changes[] = ['field' => 'Cash drop #' . $nid . ' (' . $why . ')', 'old' => '', 'new' => money($newDrop)];
            }
            // the cash counted
            $counted = array_key_exists('counted_cash', $c) && $c['counted_cash'] !== null ? r2((float)$c['counted_cash']) : (float)$shift['counted_cash'];
            if ($counted < 0) {
                fail('The cash counted cannot be less than zero.');
            }
            if (abs($counted - (float)$shift['counted_cash']) >= 0.005) {
                $changes[] = ['field' => 'Cash counted', 'old' => money($shift['counted_cash']), 'new' => money($counted)];
            }
            if (!$changes) {
                fail('Nothing was changed: the figures are already the ones entered at closing.');
            }

            // expected cash and over/short again, and the journal entry that follows them
            $after = self::summary($shiftId);
            $expected = $after['expected_cash'];
            $diff = r2($counted - $expected);
            $osId = $shift['over_short_txn_id'] ? (int)$shift['over_short_txn_id'] : null;
            if ($osId && (int)DB::value('SELECT is_voided FROM transactions WHERE id = :id', ['id' => $osId]) === 1) {
                $osId = null;
            }
            $memo = ($diff > 0 ? 'Cash over' : 'Cash short') . ' - ' . $term['name'] . ' (corrected)';
            if (abs($diff) >= 0.01) {
                $cashAcct = (int)$term['cash_account_id'];
                $osTxn = Txn::save('pos_over_short', [
                    'txn_date' => $shift['business_date'], 'terminal_id' => $shift['terminal_id'], 'pos_shift_id' => $shiftId,
                    'site_id' => $term['site_id'], 'memo' => $memo, 'class_id' => $term['class_id'] ?? null,
                    'approved_by' => $approver['id'] ?? null,
                ], [
                    ['line_kind' => 'journal', 'account_id' => $cashAcct, 'amount' => $diff, 'description' => 'Drawer count difference'],
                    ['line_kind' => 'journal', 'account_id' => Accts::get('cash_over_short'), 'amount' => -$diff, 'description' => 'Cash over and short'],
                ], $osId);
            } else {
                if ($osId) {
                    Txn::void($osId, 'Closing entries corrected: the drawer now agrees with the expected cash. ' . $reason, $approver);
                }
                $osTxn = null;
            }
            DB::update('pos_shifts', [
                'expected_cash' => $expected, 'counted_cash' => $counted, 'over_short' => $diff, 'over_short_txn_id' => $osTxn,
                'corrected_by' => Auth::id(), 'corrected_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $shiftId]);
            // the Z-reading prints the corrected figures (its number does not change)
            DB::run("UPDATE pos_readings SET cash_drops = :d, expected_cash = :e, counted_cash = :c, over_short = :o WHERE shift_id = :s AND reading_type = 'Z'",
                ['d' => $after['cash_drops'], 'e' => $expected, 'c' => $counted, 'o' => $diff, 's' => $shiftId]);
            $changes[] = ['field' => 'Expected cash', 'old' => money($before['expected_cash']), 'new' => money($expected)];
            $changes[] = ['field' => 'Over / (short)', 'old' => money($shift['over_short']), 'new' => money($diff)];
            // the deposit that already took the shift's cash to the bank follows the correction
            $deposit = self::followDeposit($shiftId, $reason, $approver);
            if ($deposit && $deposit['changed']) {
                $changes[] = ['field' => 'Cash deposited (' . $deposit['txn_no'] . ')', 'old' => money($deposit['old']), 'new' => money($deposit['new'])];
            }
            Audit::log('shift_correct', 'pos', 'shift', $shiftId, 'Z-reading of ' . $term['name'] . ', ' . fdate($shift['business_date']), [
                'page_key' => 'pos.shifts', 'reason' => $reason, 'amount' => $diff, 'terminal_id' => $shift['terminal_id'], 'site_id' => $term['site_id'],
                'approved_by' => $approver['username'] ?? null, 'changes' => $changes,
            ]);
            return ['changes' => $changes, 'over_short' => $diff, 'expected' => $expected, 'txn_id' => $osTxn, 'old_txn_id' => $shift['over_short_txn_id'],
                'deposit' => $deposit, 'to_deposit' => self::toDeposit(DB::row('SELECT * FROM pos_shifts WHERE id = :id', ['id' => $shiftId]))];
        });
    }
}
