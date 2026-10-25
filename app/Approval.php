<?php
declare(strict_types=1);

/**
 * Second-person approval for sensitive actions (voids, refunds, price overrides,
 * large discounts, amounts above a user's limit). The approver types their own
 * username and password on the same form.
 */
final class Approval
{
    /** Returns the approver's user row, or null when no approval is needed. */
    public static function check(string $pageKey, bool $required, string $what): ?array
    {
        // memorizing a form records nothing, so there is nothing to approve yet
        if (!$required || Memorized::$capture) {
            return null;
        }
        // a user who can approve on this page approves their own action
        if (Access::can($pageKey, 'approve')) {
            return Auth::user();
        }
        // a one-time approval code from a supervisor who is not at the counter
        $code = trim((string)($_POST['approver_code'] ?? ''));
        if ($code !== '') {
            $GLOBALS['NEEDS_APPROVAL'] = $what;
            $u = ApprovalCode::redeem($code, $pageKey, $what);
            unset($GLOBALS['NEEDS_APPROVAL']);
            return $u;
        }
        // the supervisor already approved this same action a moment ago, and the save only stopped to ask
        // about stock going below zero: the approval is kept for the answer (once, for ten minutes)
        if ($u = self::carried($pageKey, $what)) {
            return $u;
        }
        $username = trim((string)($_POST['approver_username'] ?? ''));
        $password = (string)($_POST['approver_password'] ?? '');
        if ($username === '') {
            $GLOBALS['NEEDS_APPROVAL'] = $what;
            fail("Supervisor approval is required: $what. Ask a supervisor to enter their username and password below, or type a one-time approval code.");
        }
        $u = Auth::verifyOther($username, $password);
        if (!$u) {
            Audit::log('approval_failed', 'security', 'user', null, $username, ['reason' => $what]);
            $GLOBALS['NEEDS_APPROVAL'] = $what;
            fail('Approval failed: the supervisor username or password is wrong.');
        }
        if ((int)$u['id'] === Auth::id()) {
            fail('You cannot approve your own transaction.');
        }
        if (!Access::can($pageKey, 'approve', (int)$u['id'])) {
            $GLOBALS['NEEDS_APPROVAL'] = $what;
            fail($u['username'] . ' is not allowed to approve on this page.');
        }
        $GLOBALS['APPROVAL_GIVEN'] = ['user_id' => (int)$u['id'], 'page' => $pageKey, 'what' => $what];
        return $u;
    }

    /**
     * The save stopped to ask a question (stock below zero) after a supervisor had typed their password: the approval
     * is kept in the session under a token the form carries, so answering Yes does not need the password again.
     */
    public static function keepForAnswer(): void
    {
        $g = $GLOBALS['APPROVAL_GIVEN'] ?? null;
        if (!$g) {
            return;
        }
        $token = bin2hex(random_bytes(12));
        $keep = array_filter((array)($_SESSION['approval_carry'] ?? []), fn($c) => ($c['until'] ?? 0) > time());
        $keep[$token] = $g + ['until' => time() + 600];
        $_SESSION['approval_carry'] = array_slice($keep, -5, null, true);
        $GLOBALS['APPROVAL_CARRY'] = $token;
    }

    private static function carried(string $pageKey, string $what): ?array
    {
        $token = (string)($_POST['approver_carry'] ?? '');
        $c = $token !== '' ? ($_SESSION['approval_carry'][$token] ?? null) : null;
        if (!$c) {
            return null;
        }
        unset($_SESSION['approval_carry'][$token]);
        if ($c['until'] < time() || $c['page'] !== $pageKey || $c['what'] !== $what) {
            return null;
        }
        $u = DB::row("SELECT * FROM users WHERE id = :id AND status = 'active' AND approval_status = 'approved'", ['id' => $c['user_id']]);
        if (!$u || (int)$u['id'] === Auth::id() || !Access::can($pageKey, 'approve', (int)$u['id'])) {
            return null;
        }
        $GLOBALS['APPROVAL_GIVEN'] = $c;
        return $u;
    }

    public static function exceeds(?float $limit, float $value): bool
    {
        return $limit !== null && $value > $limit + 0.00001;
    }

    /** A save failed after an approval code was accepted: the code is open again */
    public static function release(): void
    {
        ApprovalCode::release();
    }

    /**
     * Form fields shown on forms that may need approval: the supervisor's username and password, or a one-time
     * approval code the supervisor generated on Point of Sale > Approval Codes.
     */
    public static function fields(bool $open = false): string
    {
        $need = $GLOBALS['NEEDS_APPROVAL'] ?? null;
        $open = $open || $need;
        $codeTab = isset($_POST['approver_code']) && trim((string)$_POST['approver_code']) !== '';
        $carry = $GLOBALS['APPROVAL_CARRY'] ?? null;
        if ($carry) {
            $by = (string)DB::value('SELECT full_name FROM users WHERE id = :id', ['id' => $GLOBALS['APPROVAL_GIVEN']['user_id'] ?? 0]);
            return '<input type="hidden" name="approver_carry" value="' . h($carry) . '">'
                . '<div class="small text-success mt-3"><i class="bi bi-person-check"></i> Approved by ' . h($by) . '. The approval is kept for this save.</div>';
        }
        return '<details class="approval-box mt-3"' . ($open ? ' open' : '') . '><summary class="small text-muted">Supervisor approval'
            . ($need ? ' <span class="approval-required">required</span>' : '') . '</summary>'
            . '<div class="approval-ways mt-2" data-approval>'
            . '<div class="approval-tabs" role="tablist">'
            . '<button type="button" class="approval-tab' . ($codeTab ? '' : ' is-on') . '" data-way="login"><i class="bi bi-person-badge"></i> Supervisor is here</button>'
            . '<button type="button" class="approval-tab' . ($codeTab ? ' is-on' : '') . '" data-way="code"><i class="bi bi-key"></i> Approval code</button>'
            . '</div>'
            . '<div class="approval-way" data-way-panel="login"' . ($codeTab ? ' hidden' : '') . '><div class="row g-2">'
            . '<div class="col-sm-5"><input class="form-control form-control-sm" name="approver_username" placeholder="Supervisor username" autocomplete="off" aria-label="Supervisor username"></div>'
            . '<div class="col-sm-5"><input class="form-control form-control-sm" type="password" name="approver_password" placeholder="Password" autocomplete="new-password" aria-label="Supervisor password"></div>'
            . '</div><div class="small text-muted mt-1">The supervisor types their own username and password; the approval is recorded under their name.</div></div>'
            . '<div class="approval-way" data-way-panel="code"' . ($codeTab ? '' : ' hidden') . '><div class="row g-2 align-items-center">'
            . '<div class="col-auto"><input class="form-control form-control-sm approval-code" name="approver_code" placeholder="XXXX-XXXX" autocomplete="off" maxlength="9" autocapitalize="characters" spellcheck="false" aria-label="Approval code" value="' . h($codeTab ? (string)$_POST['approver_code'] : '') . '"></div>'
            . '<div class="col-12 small text-muted">A one-time code the supervisor generated on <strong>Point of Sale &gt; Approval Codes</strong>. It works once and then expires.</div>'
            . '</div></div></div></details>';
    }
}
