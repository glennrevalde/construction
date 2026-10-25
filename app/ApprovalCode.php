<?php
declare(strict_types=1);

/**
 * One-time approval codes.
 *
 * When a cashier or clerk needs a supervisor's approval (a price override, a discount above the limit, a
 * refund, a Z-reading ...) and the supervisor is not at the counter, the supervisor logs in anywhere, generates
 * a code on Point of Sale > Approval Codes and reads it out. The cashier types the code in the Supervisor
 * approval box instead of the supervisor's username and password. A code works once: the moment a transaction
 * is saved with it, it is "used" and cannot be used again. A code that is not used expires on its own.
 *
 * The supervisor who generated the code is the approver on record (approved_by, the audit trail), exactly as
 * if they had typed their password, and the same rule applies: they must be allowed to approve on that page.
 */
final class ApprovalCode
{
    /** Letters and digits that cannot be misread for one another: no 0/O, 1/I/L, 5/S, 8/B, 2/Z */
    private const ALPHABET = 'ACDEFGHJKMNPQRTUVWXY346799';
    public const LENGTH = 8;

    public const VALIDITY = ['1' => '1 hour', '4' => '4 hours', '12' => '12 hours', '24' => '1 day', '72' => '3 days', '168' => '7 days'];

    /** The code as it is shown and read out: XXXX-XXXX */
    public static function display(string $code): string
    {
        $code = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code));
        return strlen($code) === self::LENGTH ? substr($code, 0, 4) . '-' . substr($code, 4) : $code;
    }

    /** What the user typed, made comparable: upper-case, without the dash or spaces */
    public static function normalize(string $typed): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $typed));
    }

    /** A fresh, unused code with at least one letter and one digit */
    public static function generate(): string
    {
        for ($try = 0; $try < 50; $try++) {
            $code = '';
            for ($i = 0; $i < self::LENGTH; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            if (!preg_match('/[A-Z]/', $code) || !preg_match('/[0-9]/', $code)) {
                continue;
            }
            if (!DB::value('SELECT id FROM approval_codes WHERE code = :c', ['c' => $code])) {
                return $code;
            }
        }
        throw new RuntimeException('Could not make an approval code.');
    }

    /**
     * Generates a code for the logged-in supervisor.
     * $forUserId: the cashier it is meant for (optional); $pageKey: the page it may approve on (optional);
     * $hours: how long it stays usable.
     */
    public static function issue(?string $purpose, ?int $forUserId, ?string $pageKey, int $hours, ?float $maxAmount = null): array
    {
        $hours = max(1, min(168, $hours));
        $code = self::generate();
        $id = DB::insert('approval_codes', [
            'code' => $code, 'purpose' => $purpose !== null && $purpose !== '' ? mb_substr($purpose, 0, 160) : null,
            'for_user_id' => $forUserId ?: null, 'page_key' => $pageKey ?: null, 'max_amount' => $maxAmount,
            'status' => 'open', 'issued_by' => Auth::id(), 'issued_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', time() + $hours * 3600),
        ]);
        Audit::log('approval_code_issued', 'pos', 'approval_code', $id, self::display($code), [
            'page_key' => 'pos.approval_codes', 'reason' => $purpose ?: null, 'amount' => $maxAmount,
            'changes' => [
                ['field' => 'For', 'old' => '', 'new' => $forUserId ? (string)DB::value('SELECT username FROM users WHERE id = :id', ['id' => $forUserId]) : 'any user'],
                ['field' => 'On', 'old' => '', 'new' => $pageKey ? (Access::pageDef($pageKey)['name'] ?? $pageKey) : 'any page'],
                ['field' => 'Valid for', 'old' => '', 'new' => self::VALIDITY[(string)$hours] ?? ($hours . ' hour(s)')],
            ],
        ]);
        return self::load($id);
    }

    public static function load(int $id): ?array
    {
        return DB::row('SELECT * FROM approval_codes WHERE id = :id', ['id' => $id]);
    }

    /** Marks open codes whose time has run out as expired (cheap: only the open ones are looked at) */
    public static function sweep(): void
    {
        DB::run("UPDATE approval_codes SET status = 'expired' WHERE status = 'open' AND expires_at < NOW()");
    }

    /**
     * Checks a typed code for the current user on this page and, when it is good, marks it used and returns
     * the supervisor who issued it. Every refusal says why, without revealing which codes exist.
     */
    public static function redeem(string $typed, string $pageKey, string $what): array
    {
        $code = self::normalize($typed);
        $generic = 'The approval code is not valid. Ask the supervisor for a new code.';
        if (strlen($code) !== self::LENGTH) {
            fail('An approval code has ' . self::LENGTH . ' letters and numbers, for example ' . self::display(str_repeat('A', 4) . str_repeat('7', 4)) . '.');
        }
        self::sweep();
        $row = DB::row('SELECT * FROM approval_codes WHERE code = :c FOR UPDATE', ['c' => $code]);
        $me = (int)Auth::id();
        if (!$row) {
            Audit::log('approval_failed', 'security', 'approval_code', null, self::display($code), ['reason' => 'Unknown approval code: ' . $what, 'page_key' => $pageKey]);
            fail($generic);
        }
        if ($row['status'] !== 'open') {
            Audit::log('approval_failed', 'security', 'approval_code', (int)$row['id'], self::display($code), ['reason' => 'Code already ' . $row['status'] . ': ' . $what, 'page_key' => $pageKey]);
            fail($row['status'] === 'used' ? 'That approval code was already used. Each code works once: ask the supervisor for a new one.'
                : ($row['status'] === 'expired' ? 'That approval code has expired. Ask the supervisor for a new one.' : 'That approval code was cancelled. Ask the supervisor for a new one.'));
        }
        if ($row['for_user_id'] && (int)$row['for_user_id'] !== $me) {
            Audit::log('approval_failed', 'security', 'approval_code', (int)$row['id'], self::display($code), ['reason' => 'Code is for another user: ' . $what, 'page_key' => $pageKey]);
            fail('That approval code was issued for another user.');
        }
        if ($row['page_key'] && $row['page_key'] !== $pageKey) {
            fail('That approval code is for ' . (Access::pageDef($row['page_key'])['name'] ?? $row['page_key']) . ', not for this page.');
        }
        if ((int)$row['issued_by'] === $me) {
            fail('You cannot approve your own transaction with a code you generated.');
        }
        $sup = DB::row('SELECT * FROM users WHERE id = :id', ['id' => $row['issued_by']]);
        if (!$sup || $sup['status'] !== 'active') {
            fail('The supervisor who issued that code is no longer active.');
        }
        if (!Access::can($pageKey, 'approve', (int)$sup['id'])) {
            fail($sup['username'] . ' is not allowed to approve on this page, so their code cannot be used here.');
        }
        DB::update('approval_codes', [
            'status' => 'used', 'used_by' => $me, 'used_at' => date('Y-m-d H:i:s'), 'used_on' => mb_substr($what, 0, 160),
        ], 'id = :id', ['id' => $row['id']]);
        // if the save then fails, the code is given back (Approval::release)
        $GLOBALS['APPROVAL_CODE_USED'] = (int)$row['id'];
        Audit::log('approval_code_used', 'pos', 'approval_code', (int)$row['id'], self::display($code), [
            'page_key' => $pageKey, 'reason' => $what, 'changes' => [['field' => 'Approved by', 'old' => '', 'new' => $sup['username']]],
        ]);
        return $sup;
    }

    /** The transaction a used code approved (recorded once the transaction has its id) */
    public static function attach(int $txnId): void
    {
        $id = (int)($GLOBALS['APPROVAL_CODE_USED'] ?? 0);
        if ($id) {
            DB::update('approval_codes', ['used_txn_id' => $txnId], 'id = :id AND used_txn_id IS NULL', ['id' => $id]);
        }
    }

    /** The save failed after the code was accepted: the code is open again, so the cashier can try again with it */
    public static function release(): void
    {
        $id = (int)($GLOBALS['APPROVAL_CODE_USED'] ?? 0);
        if ($id) {
            unset($GLOBALS['APPROVAL_CODE_USED']);
            DB::update('approval_codes', ['status' => 'open', 'used_by' => null, 'used_at' => null, 'used_on' => null], "id = :id AND status = 'used' AND used_txn_id IS NULL", ['id' => $id]);
        }
    }

    public static function cancel(int $id, string $reason): void
    {
        $row = self::load($id);
        if (!$row || $row['status'] !== 'open') {
            fail('Only an open code can be cancelled.');
        }
        DB::update('approval_codes', ['status' => 'cancelled', 'cancelled_by' => Auth::id(), 'cancelled_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
        Audit::log('approval_code_cancelled', 'pos', 'approval_code', $id, self::display($row['code']), ['page_key' => 'pos.approval_codes', 'reason' => $reason]);
    }

    /** Pages on which an approval can be asked for (the ones that show the Supervisor approval box) */
    public static function pages(): array
    {
        $keys = ['pos.register', 'pos.receipts', 'pos.shifts', 'cust.invoices', 'cust.sales_receipts', 'cust.estimates', 'cust.sales_orders', 'cust.credit_memos',
            'cust.batch_invoices', 'bank.write_checks', 'bank.cash_voucher', 'vend.pay_bills', 'inv.adjust', 'inv.physical_count', 'inv.process', 'fa.maintenance'];
        $out = [];
        foreach ($keys as $k) {
            if ($d = Access::pageDef($k)) {
                $out[$k] = $d['name'];
            }
        }
        return $out;
    }
}
