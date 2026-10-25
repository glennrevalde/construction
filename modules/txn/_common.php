<?php
declare(strict_types=1);

/**
 * Shared pieces for transaction pages: line parsing, list, view, print,
 * void / delete / email handling.
 */

const SALES_TYPES = ['estimate', 'sales_order', 'invoice', 'sales_receipt', 'statement_charge', 'credit_memo', 'finance_charge', 'pos_sale', 'pos_return'];
const PURCHASE_TYPES = ['purchase_order', 'bill', 'vendor_credit', 'item_receipt', 'check'];

function item_cache(int $id): ?array
{
    static $c = [];
    return $c[$id] ??= DB::row('SELECT * FROM items WHERE id = :id', ['id' => $id]);
}

function tax_rate(?int $taxId): float
{
    if (!$taxId) {
        return 0.0;
    }
    return (float)(Lookup::taxRates()[$taxId] ?? 0);
}

/** Quantity as it was entered, with its unit (and the base quantity when a larger unit was used) */
function line_qty(array $l): string
{
    $entered = (float)($l['uom_qty'] ?? 0);
    $q = abs($entered) > 0.000001 ? $entered : (float)($l['qty'] ?? 0);
    $s = qty($q) . (!empty($l['unit']) ? ' ' . h($l['unit']) : '');
    if (abs((float)($l['uom_factor'] ?? 1) - 1.0) > 0.000001) {
        $s .= ' <span class="text-muted small">= ' . qty($l['qty']) . '</span>';
    }
    return $s;
}

/** Price as it was entered (per the unit on the line) */
function line_rate(array $l, bool $blankZero = true): string
{
    $entered = (float)($l['uom_rate'] ?? 0);
    return money(abs($entered) > 0.000001 ? $entered : (float)($l['rate'] ?? 0), $blankZero);
}

/** Discount shown the way it was entered: a percent or an amount off */
function line_discount(array $l): string
{
    if (($l['discount_kind'] ?? 'pct') === 'amt') {
        return (float)($l['discount_amount'] ?? 0) != 0.0 ? money($l['discount_amount']) : '';
    }
    return (float)($l['discount_pct'] ?? 0) > 0 ? pct($l['discount_pct']) : '';
}

/**
 * Normalises item lines posted from a grid.
 * $mode = sales | purchase ; $ctx = ['customer_id' => ..., 'site_id' => ...]
 */
function parse_item_lines(array $rows, string $mode, array $ctx = []): array
{
    $out = [];
    $prevAmount = 0.0;
    // item groups: a new group on the form takes the next group number of the document
    $groupsOn = $mode === 'sales' && ItemGroups::ready();
    $groupRef = 0;
    foreach ($rows as $r) {
        $groupRef = max($groupRef, (int)($r['group_ref'] ?? 0));
    }
    foreach ($rows as $r) {
        $itemId = !empty($r['item_id']) ? (int)$r['item_id'] : null;
        $accountId = !empty($r['account_id']) ? (int)$r['account_id'] : null;
        $desc = trim((string)($r['description'] ?? ''));
        // quantity and price are entered in the line's own unit of measure
        $qtyEntered = to_num($r['qty'] ?? 0);
        $rateEntered = to_num($r['rate'] ?? 0);
        $qty = $qtyEntered;
        $rate = $rateEntered;
        // discount: a percent of the line, or an absolute amount off it
        $discKind = ($r['discount_kind'] ?? 'pct') === 'amt' ? 'amt' : 'pct';
        $discEntered = to_num($r['discount_val'] ?? ($r['discount_pct'] ?? 0));
        $disc = $discKind === 'pct' ? min(100.0, max(0.0, $discEntered)) : 0.0;
        $given = to_num($r['amount'] ?? 0);
        if (!$itemId && !$accountId) {
            if ($desc === '' && abs($given) < 0.005) {
                continue;
            }
            fail('Choose an item for every line that has a description or amount.');
        }
        $item = $itemId ? item_cache($itemId) : null;
        if ($itemId && !$item) {
            fail('An item on this form no longer exists.');
        }
        $taxId = !empty($r['tax_code_id']) ? (int)$r['tax_code_id'] : null;

        // unit of measure: qty and rate are stored in the item's base unit, what was typed is kept beside them
        $uomId = !empty($r['uom_id']) ? (int)$r['uom_id'] : null;
        if ($uomId && !Uom::unitBelongsTo($uomId, $itemId)) {
            fail('The unit chosen on a line does not belong to that item\'s U/M set.');
        }
        $factor = $uomId ? Uom::factor($uomId) : 1.0;
        if ($factor != 1.0) {
            $qty = round($qtyEntered * $factor, 4);
            $rate = round($rateEntered / $factor, 4);
        }

        $base = [
            'id'             => !empty($r['id']) && ctype_digit((string)$r['id']) ? (int)$r['id'] : null,
            'line_kind'      => 'item',
            'item_id'        => $itemId,
            'account_id'     => $accountId,
            'description'    => $desc !== '' ? mb_substr($desc, 0, 255) : ($item['name'] ?? null),
            'unit'           => $uomId ? Uom::code($uomId) : ($item['unit'] ?? null),
            'uom_id'         => $uomId,
            'uom_factor'     => $factor,
            'uom_qty'        => $qtyEntered,
            'uom_rate'       => $rateEntered,
            'discount_kind'  => $discKind,
            'site_id'        => !empty($r['site_id']) ? (int)$r['site_id'] : null,
            'customer_id'    => !empty($r['customer_id']) ? (int)$r['customer_id'] : null,
            'is_billable'    => !empty($r['is_billable']) ? 1 : 0,
            'source_line_id' => !empty($r['source_line_id']) ? (int)$r['source_line_id'] : null,
            'is_closed'      => !empty($r['is_closed']) ? 1 : 0,
        ];
        if ($base['id'] === null) {
            unset($base['id']);
        }
        // a line of an item group shown as the items in it ("View item details"): it stays part of its group
        if ($groupsOn && !empty($r['group_ref']) && !empty($r['group_item_id']) && in_array($r['group_role'] ?? '', ['member', 'variance'], true)) {
            $base += ['group_item_id' => (int)$r['group_item_id'], 'group_ref' => (int)$r['group_ref'], 'group_qty' => to_num($r['group_qty'] ?? 0),
                'group_rate' => to_num($r['group_rate'] ?? 0), 'group_role' => (string)$r['group_role']];
        }
        $type = $item['item_type'] ?? null;

        if ($type === 'group' && $groupsOn) {
            // one group line: the items of the group at their own prices, and the price variance (ItemGroups::expand)
            $gq = $qtyEntered != 0.0 ? $qtyEntered : 1.0;
            $grate = trim((string)($r['rate'] ?? '')) === '' ? ItemGroups::price($itemId, $ctx['customer_id'] ?? null) : $rateEntered;
            $gross = r2($gq * $grate);
            $discAmt = $discKind === 'amt' ? min(abs($discEntered), abs($gross)) : r2($gross * $disc / 100);
            $ref = (int)($r['group_ref'] ?? 0) ?: ++$groupRef;
            $keep = ItemGroups::keepIds((string)($r['group_line_ids'] ?? ''));
            $src = array_map('intval', array_filter(explode(',', (string)($r['group_src_ids'] ?? '')), 'strlen'));
            unset($base['id']);
            $base['source_line_id'] = null;
            $expanded = ItemGroups::expand($item, $base, $gq, $grate, $discAmt, $taxId, $ctx['customer_id'] ?? null, $ref, $keep, $src);
            foreach ($expanded as $e) {
                $out[] = $e;
            }
            $prevAmount = r2($gross - $discAmt);
            continue;
        }
        if ($type === 'group') {
            $members = DB::all('SELECT ic.qty, i.* FROM item_components ic JOIN items i ON i.id = ic.component_item_id WHERE ic.parent_item_id = :p', ['p' => $itemId]);
            foreach ($members as $m) {
                $q = ($qty ?: 1) * (float)$m['qty'];
                $price = $mode === 'sales' ? Lookup::priceFor((int)$m['id'], $ctx['customer_id'] ?? null) : (float)$m['cost'];
                $amt = r2($q * $price);
                $out[] = array_merge($base, [
                    'item_id' => (int)$m['id'], 'description' => $m['description'] ?: $m['name'], 'unit' => $m['unit'],
                    'qty' => $q, 'rate' => $price, 'discount_kind' => 'pct', 'discount_pct' => 0, 'discount_amount' => 0, 'amount' => $amt,
                    'uom_id' => null, 'uom_factor' => 1, 'uom_qty' => $q, 'uom_rate' => $price,
                    'tax_code_id' => $m['tax_code_id'], 'tax_amount' => r2($amt * tax_rate($m['tax_code_id'] ? (int)$m['tax_code_id'] : null) / 100),
                ]);
                unset($out[array_key_last($out)]['id']);
                $prevAmount = $amt;
            }
            continue;
        }
        if ($type === 'subtotal') {
            $out[] = array_merge($base, ['qty' => 0, 'rate' => 0, 'amount' => 0, 'discount_kind' => 'pct', 'discount_pct' => 0, 'discount_amount' => 0,
                'uom_id' => null, 'uom_factor' => 1, 'uom_qty' => 0, 'uom_rate' => 0, 'tax_code_id' => null, 'tax_amount' => 0]);
            continue;
        }
        // the line is priced in the unit it was entered in, so the amount comes from what was typed
        $gross = r2($qtyEntered * $rateEntered);
        if ($type === 'discount') {
            $pct = (float)$item['discount_pct'];
            $amount = $pct > 0 ? -r2($prevAmount * $pct / 100) : -abs($rate ?: (float)$item['sales_price']);
            $qty = $qtyEntered = 0;
            $rate = $rateEntered = $pct > 0 ? 0 : abs($amount);
            $disc = $pct;
            $gross = 0.0;
        } elseif ($type === 'payment') {
            $amount = -abs($rate ?: $given);
            $qty = $qtyEntered = 0;
            $rate = $rateEntered = abs($amount);
            $taxId = null;
            $gross = 0.0;
        } elseif (abs($qty) < 0.000001 && abs($rate) < 0.000001) {
            $amount = r2($given);
        } elseif ($discKind === 'amt') {
            // an absolute amount off the line, never more than the line itself
            $off = min(abs($discEntered), abs($gross));
            $amount = r2($gross - ($gross < 0 ? -$off : $off));
            $disc = abs($gross) > 0.000001 ? round($off / abs($gross) * 100, 2) : 0.0;
        } else {
            $amount = r2($gross * (1 - $disc / 100));
        }
        $out[] = array_merge($base, [
            'qty'             => $qty,
            'rate'            => $rate,
            'uom_qty'         => $qtyEntered,
            'uom_rate'        => $rateEntered,
            'discount_kind'   => $type === 'discount' || $type === 'payment' ? 'pct' : $discKind,
            'discount_pct'    => $type === 'discount' ? 0 : $disc,
            'discount_amount' => ($type === 'discount' || $type === 'payment' || abs($gross) < 0.000001) ? 0 : r2($gross - $amount),
            'amount'          => $amount,
            'tax_code_id'     => $taxId,
            'tax_amount'      => r2($amount * tax_rate($taxId) / 100),
        ]);
        $prevAmount = $amount;
    }
    return $out;
}

/** Expense-tab lines (bills, checks, vendor credits) */
function parse_expense_lines(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $acct = !empty($r['account_id']) ? (int)$r['account_id'] : null;
        $amount = r2(to_num($r['amount'] ?? 0));
        if (!$acct && abs($amount) < 0.005) {
            continue;
        }
        if (!$acct) {
            fail('Choose an account for every expense line.');
        }
        $taxId = !empty($r['tax_code_id']) ? (int)$r['tax_code_id'] : null;
        $line = [
            'line_kind'   => 'expense',
            'account_id'  => $acct,
            'description' => mb_substr(trim((string)($r['description'] ?? '')), 0, 255) ?: null,
            'amount'      => $amount,
            'tax_code_id' => $taxId,
            'tax_amount'  => r2($amount * tax_rate($taxId) / 100),
            'customer_id' => !empty($r['customer_id']) ? (int)$r['customer_id'] : null,
            'is_billable' => !empty($r['is_billable']) && !empty($r['customer_id']) ? 1 : 0,
        ];
        if (!empty($r['id']) && ctype_digit((string)$r['id'])) {
            $line['id'] = (int)$r['id'];
        }
        $out[] = $line;
    }
    return $out;
}

/** Discount / price override checks. Returns a description of what needs approval, or ''. */
function price_checks(array $lines, ?int $customerId): string
{
    $maxDisc = Access::limit('max_discount_pct');
    $maxOverride = Access::limit('max_price_override_pct');
    // item groups are checked as groups: their discount and their price against the group's own
    $needs = ItemGroups::ready() ? ItemGroups::priceChecks($lines, $customerId, $maxDisc, $maxOverride) : [];
    foreach ($lines as $l) {
        if (!$l['item_id'] || !empty($l['group_ref'])) {
            continue;
        }
        $item = item_cache((int)$l['item_id']);
        if (in_array($item['item_type'], ['subtotal', 'payment', 'group'], true)) {
            continue;
        }
        if ($item['item_type'] === 'discount') {
            if ((float)$item['discount_pct'] > 0 && Approval::exceeds($maxDisc, (float)$item['discount_pct'])) {
                $needs[] = $item['name'] . ' (' . pct($item['discount_pct']) . ' discount)';
            }
            continue;
        }
        if (Approval::exceeds($maxDisc, (float)$l['discount_pct'])) {
            $needs[] = pct($l['discount_pct']) . ' discount on ' . $item['code'];
        }
        $std = Lookup::priceFor((int)$l['item_id'], $customerId);
        if ($std > 0 && abs((float)$l['rate']) > 0) {
            $change = abs((float)$l['rate'] - $std) / $std * 100;
            if ($change > 0.001 && Approval::exceeds($maxOverride, $change)) {
                $needs[] = 'price override on ' . $item['code'] . ' (' . money($l['rate']) . ' vs ' . money($std) . ')';
            }
        }
    }
    return implode('; ', $needs);
}

/** POST array rows as a list (grid rows keyed by index) */
function grid_rows(string $key): array
{
    $rows = $_POST[$key] ?? [];
    return is_array($rows) ? array_values($rows) : [];
}

// ---------------------------------------------------------------------- common actions

/**
 * Handles a=view|print|void|delete|email|close for any transaction owned by $pageKey.
 * Returns true when the request was handled.
 */
function txn_common_actions(string $pageKey, array $types): bool
{
    $a = g('a');
    if (!in_array($a, ['view', 'print', 'void', 'delete', 'email', 'close', 'reopen'], true)) {
        return false;
    }
    $id = (int)g('id');
    $t = Txn::load($id);
    if (!$t || !in_array($t['txn_type'], $types, true)) {
        flash('warning', 'Transaction not found.');
        redirect(url($pageKey));
    }
    if (!Access::siteAllowed($t['site_id'] ? (int)$t['site_id'] : null)) {
        Access::denyPage($pageKey, 'open a transaction of another site on');
    }
    switch ($a) {
        case 'view':
            render_txn_view($t, $pageKey);
            return true;
        case 'print':
            Access::require($pageKey, 'print');
            if (in_array($t['txn_type'], ['check', 'bill_payment', 'refund'], true)) {
                DB::update('transactions', ['is_printed' => 1, 'to_be_printed' => 0], 'id = :id', ['id' => $id]);
            }
            Audit::log('print', Txn::module($t['txn_type']), 'transaction', $id, Txn::ref($t), ['page_key' => $pageKey]);
            render_txn_print($t);
            return true;
        case 'email':
            Access::require($pageKey, 'print');
            $email = '';
            if ($t['customer_id']) {
                $email = (string)DB::value('SELECT email FROM customers WHERE id = :id', ['id' => $t['customer_id']]);
            } elseif ($t['vendor_id']) {
                $email = (string)DB::value('SELECT email FROM vendors WHERE id = :id', ['id' => $t['vendor_id']]);
            }
            Audit::log('email', Txn::module($t['txn_type']), 'transaction', $id, Txn::ref($t), ['page_key' => $pageKey, 'reason' => $email ? "To $email" : null]);
            $company = Settings::get('company_name');
            $subject = Txn::ref($t) . ' from ' . $company;
            $body = "Hello,\n\nPlease find " . Txn::ref($t) . ' dated ' . fdate($t['txn_date']) . ' for ' . money($t['total'])
                . ".\n\nView / print: " . (isset($_SERVER['HTTP_HOST']) ? 'http://' . $_SERVER['HTTP_HOST'] : '') . Txn::url($t, 'print')
                . "\n\nThank you,\n$company";
            redirect('mailto:' . rawurlencode($email) . '?subject=' . rawurlencode($subject) . '&body=' . rawurlencode($body));
        case 'close':
        case 'reopen':
            Access::require($pageKey, 'edit');
            if (!is_post()) {
                redirect(Txn::url($t));
            }
            $closing = $a === 'close';
            DB::run('UPDATE transaction_lines SET is_closed = :c WHERE txn_id = :id', ['c' => $closing ? 1 : 0, 'id' => $id]);
            if ($t['txn_type'] === 'estimate') {
                DB::update('transactions', ['status' => $closing ? 'closed' : 'pending'], 'id = :id', ['id' => $id]);
            } else {
                Txn::refreshOrderStatus($id, $t['txn_type']);
            }
            Audit::log($a, Txn::module($t['txn_type']), 'transaction', $id, Txn::ref($t), [
                'page_key' => $pageKey, 'changes' => [['field' => 'status', 'old' => $t['status'], 'new' => $closing ? 'closed' : 'reopened']],
            ]);
            flash('success', Txn::ref($t) . ($closing ? ' closed.' : ' reopened.'));
            redirect(Txn::url($t));
        case 'void':
        case 'delete':
            Access::require($pageKey, 'delete');
            if (!is_post()) {
                redirect(Txn::url($t));
            }
            try {
                $approver = null;
                $limit = Access::limit('max_amount');
                if (in_array($t['txn_type'], ['check', 'bill_payment', 'refund', 'pos_sale', 'pos_return'], true)) {
                    $approver = Approval::check($pageKey, str_starts_with($t['txn_type'], 'pos_') || Approval::exceeds($limit, (float)$t['total']), $a . ' of ' . Txn::ref($t));
                }
                if ($a === 'void') {
                    Txn::void($id, (string)p('reason'), $approver);
                    flash('success', Txn::ref($t) . ' was voided.');
                    redirect(Txn::url($t));
                }
                Txn::delete($id, (string)p('reason'), $approver);
                flash('success', Txn::ref($t) . ' was deleted. A full copy is kept in the audit trail.');
                redirect(url($pageKey));
            } catch (UserError $e) {
                flash('danger', $e->getMessage());
                redirect(Txn::url($t));
            }
    }
    return false;
}

function entity_name(array $t): string
{
    if ($t['customer_id']) {
        return (string)DB::value(
            "SELECT CASE WHEN p.id IS NULL THEN c.name ELSE CONCAT(p.name, ':', c.name) END FROM customers c LEFT JOIN customers p ON p.id = c.parent_id WHERE c.id = :id",
            ['id' => $t['customer_id']]
        );
    }
    if ($t['vendor_id']) {
        return (string)DB::value('SELECT name FROM vendors WHERE id = :id', ['id' => $t['vendor_id']]);
    }
    if (!empty($t['other_name_id'])) {
        return Names::label($t);
    }
    return (string)($t['payee_name'] ?? '');
}

/** Documents shown and printed as journal entries: account, description, name, debit, credit */
function is_journal_style(string $type): bool
{
    return in_array($type, Posting::JOURNAL_TYPES, true) || $type === Posting::CASH_VOUCHER;
}

/** Name on a journal-style line: customer, supplier, employee or other name */
function line_name(array $l): string
{
    return (string)($l['customer_name'] ?: (($l['vendor_name'] ?? '') ?: ($l['other_name'] ?? '')));
}

function account_label(?int $id): string
{
    if (!$id) {
        return '';
    }
    $a = DB::row('SELECT code, name FROM accounts WHERE id = :id', ['id' => $id]);
    return $a ? $a['code'] . ' - ' . $a['name'] : '';
}

/** Links to documents created from this one / that this one came from */
function related_docs(array $t): array
{
    $out = [];
    $from = DB::all(
        'SELECT DISTINCT s.* FROM transaction_lines l JOIN transaction_lines sl ON sl.id = l.source_line_id
           JOIN transactions s ON s.id = sl.txn_id WHERE l.txn_id = :id',
        ['id' => $t['id']]
    );
    foreach ($from as $s) {
        $out[] = ['Created from', $s];
    }
    $to = DB::all(
        'SELECT DISTINCT d.* FROM transaction_lines sl JOIN transaction_lines l ON l.source_line_id = sl.id
           JOIN transactions d ON d.id = l.txn_id WHERE sl.txn_id = :id',
        ['id' => $t['id']]
    );
    foreach ($to as $d) {
        $out[] = [$d['txn_type'] === 'invoice' && in_array($t['txn_type'], ['bill', 'check'], true) ? 'Billed on' : 'Converted to', $d];
    }
    foreach (DB::all('SELECT t2.*, pa.amount, pa.discount, pa.withholding FROM payment_applications pa JOIN transactions t2 ON t2.id = pa.payment_txn_id WHERE pa.target_txn_id = :id', ['id' => $t['id']]) as $p) {
        $out[] = ['Paid by (' . money($p['amount'] + $p['discount'] + $p['withholding']) . ')', $p];
    }
    foreach (DB::all('SELECT t2.*, pa.amount, pa.discount, pa.withholding FROM payment_applications pa JOIN transactions t2 ON t2.id = pa.target_txn_id WHERE pa.payment_txn_id = :id', ['id' => $t['id']]) as $p) {
        $out[] = ['Applied to (' . money($p['amount'] + $p['discount'] + $p['withholding']) . ')', $p];
    }
    foreach (DB::all("SELECT * FROM transactions WHERE source_txn_id = :id", ['id' => $t['id']]) as $d) {
        $out[] = [$d['txn_type'] === 'refund' ? 'Refunded by' : ($t['txn_type'] === 'vendor_advance' ? 'Applied by (' . money($d['total']) . ')' : 'Related'), $d];
    }
    if ($t['source_txn_id'] && ($s = Txn::load((int)$t['source_txn_id']))) {
        $out[] = [$t['txn_type'] === 'refund' ? 'Refund of' : ($s['txn_type'] === 'vendor_advance' ? 'Advance applied' : 'Source'), $s];
    }
    foreach (DB::all('SELECT d.* FROM transaction_lines l JOIN transactions d ON d.id = l.txn_id WHERE l.ref_txn_id = :id', ['id' => $t['id']]) as $d) {
        $out[] = ['Deposited in', $d];
    }
    return $out;
}

function render_txn_view(array $t, string $pageKey): void
{
    $id = (int)$t['id'];
    $type = $t['txn_type'];
    $lines = Txn::lines($id);
    $isSales = in_array($type, SALES_TYPES, true);
    // item groups: shown as one line per group (the default) or as the items in them
    $groupSwitch = '';
    if ($isSales && ItemGroups::ready() && ItemGroups::has($lines)) {
        $lineMode = ItemGroups::viewMode();
        $groupSwitch = ItemGroups::viewSwitch($lineMode);
        if ($lineMode === 'groups') {
            $lines = ItemGroups::collapse($lines);
        }
    }
    $showCosts = Access::viewCosts();
    $open = in_array($type, array_merge(Balances::RECEIVABLE_DOCS, Balances::PAYABLE_DOCS), true) ? Balances::open($t) : null;
    $credit = in_array($type, array_merge(Balances::CUSTOMER_CREDITS, Balances::VENDOR_CREDITS), true) ? Balances::creditRemaining($t) : null;
    $advLeft = $type === 'vendor_advance' ? Advances::remaining($t) : null;
    $voided = (int)$t['is_voided'] === 1;

    $actions = [];
    // finance charges and the documents the system makes itself have no edit form of their own: they are corrected by
    // voiding and entering them again, so they get no Edit button (item receipts, inventory adjustments, transfers and
    // builds are edited on their own pages)
    $editable = !$voided && Access::can($pageKey, 'edit') && !in_array($type, ['pos_sale', 'pos_return', 'depreciation', 'asset_disposal', 'bank_charge', 'bank_interest', 'pos_over_short', 'refund',
        'finance_charge'], true);
    if ($editable) {
        $actions[] = btn_link(url($pageKey, ['a' => 'edit', 'id' => $id]), 'Edit', 'btn-primary', 'pencil');
        if ((int)$t['is_pending'] === 1 && in_array($type, ['invoice', 'sales_receipt'], true)) {
            $actions[] = btn_link(url($pageKey, ['a' => 'edit', 'id' => $id, 'finalize' => 1]), 'Mark as final', 'btn-warning', 'check2-circle');
        }
    }
    if (Access::can($pageKey, 'print')) {
        $actions[] = btn_link(Txn::url($t, 'print') . (g('lines') === 'details' ? '&lines=details' : ''), 'Print', 'btn-outline-secondary', 'printer');
        if ($t['customer_id'] || $t['vendor_id']) {
            $actions[] = btn_link(Txn::url($t, 'email'), 'Email', 'btn-outline-secondary', 'envelope');
        }
    }
    if (!$voided) {
        if ($type === 'estimate') {
            if (Access::can('cust.sales_orders', 'create')) {
                $actions[] = btn_link(url('cust.sales_orders', ['a' => 'new', 'from' => $id]), 'Create Sales Order', 'btn-outline-success', 'arrow-right');
            }
            if (Access::can('cust.invoices', 'create')) {
                $actions[] = btn_link(url('cust.invoices', ['a' => 'new', 'from' => $id]), 'Create Invoice', 'btn-outline-success', 'receipt');
            }
        }
        if ($type === 'sales_order' && in_array($t['status'], ['open', 'partial'], true) && Access::can('cust.invoices', 'create')) {
            $actions[] = btn_link(url('cust.invoices', ['a' => 'new', 'from' => $id]), 'Create Invoice', 'btn-outline-success', 'receipt');
        }
        if ($type === 'purchase_order' && in_array($t['status'], ['open', 'partial'], true)) {
            if (Access::can('vend.receive_items', 'create')) {
                $actions[] = btn_link(url('vend.receive_items', ['po' => $id]), 'Receive Items', 'btn-outline-success', 'box-arrow-in-down');
            }
            if (Access::can('vend.receive_bill', 'create')) {
                $actions[] = btn_link(url('vend.receive_bill', ['po' => $id]), 'Receive Items and Enter Bill', 'btn-outline-success', 'receipt');
            }
        }
        if ($type === 'item_receipt' && Access::can('vend.bill_received', 'create')) {
            $actions[] = btn_link(url('vend.bill_received', ['id' => $id]), 'Enter Bill for Received Items', 'btn-outline-success', 'receipt');
        }
        if (in_array($type, ['invoice', 'statement_charge', 'finance_charge'], true) && $open > 0 && (int)$t['is_pending'] === 0 && Access::can('cust.payments', 'create')) {
            $actions[] = btn_link(url('cust.payments', ['customer_id' => $t['customer_id']]), 'Receive Payment', 'btn-outline-success', 'cash');
        }
        if ($type === 'bill' && $open > 0 && Access::can('vend.pay_bills', 'create')) {
            $actions[] = btn_link(url('vend.pay_bills', ['vendor_id' => $t['vendor_id']]), 'Pay Bill', 'btn-outline-success', 'cash');
        }
        if ($type === 'vendor_advance' && $advLeft > 0.004 && Access::can('vend.pay_bills', 'create')) {
            $actions[] = btn_link(url('vend.pay_bills', ['vendor_id' => $t['vendor_id']]), 'Apply to bills', 'btn-outline-success', 'check2-square');
        }
        if ($type === 'bill_payment' && (float)$t['withholding_amount'] > 0 && Access::page('bir.form_2307')) {
            [$qy, $qq] = Withholding::quarter((string)$t['txn_date']);
            $actions[] = btn_link(url('bir.form_2307', ['vendor_id' => $t['vendor_id'], 'year' => $qy, 'quarter' => $qq]), 'BIR Form 2307', 'btn-outline-secondary', 'file-earmark-text');
        }
        if (in_array($type, Memorized::TYPES, true) && (int)$t['is_voided'] === 0 && Access::can('coa.memorized_txns', 'create')) {
            $actions[] = '<button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#memorizeModal"><i class="bi bi-bookmark-plus"></i> Memorize</button>';
        }
        if ($type === 'vendor_credit' && (int)$t['is_voided'] === 0 && Access::can('vend.debit_memos', 'edit')) {
            $actions[] = btn_link(url('vend.debit_memos', ['a' => 'apply', 'id' => $id]), $credit > 0 ? 'Apply to outstanding bills' : 'Bills it was applied to', 'btn-outline-success', 'check2-square');
        }
        if (in_array($type, ['credit_memo', 'payment'], true) && $credit > 0 && Access::can('cust.payments', 'create')) {
            $actions[] = btn_link(url('cust.payments', ['customer_id' => $t['customer_id'], 'credits' => 1]), 'Apply credit to invoices', 'btn-outline-success', 'check2-square');
        }
        if (in_array($type, ['credit_memo', 'payment'], true) && $credit > 0 && Access::can('cust.credit_memos', 'create')) {
            $actions[] = btn_link(url('cust.credit_memos', ['a' => 'refund', 'id' => $id]), 'Refund', 'btn-outline-warning', 'arrow-counterclockwise');
        }
        if (in_array($type, ['sales_order', 'purchase_order', 'estimate'], true) && Access::can($pageKey, 'edit')) {
            if ($t['status'] !== 'closed') {
                $actions[] = '<form method="post" action="' . h(Txn::url($t, 'close')) . '" class="d-inline">' . csrf_field() . '<button class="btn btn-sm btn-outline-dark" data-confirm="Close this document? Remaining quantities will no longer be expected."><i class="bi bi-x-circle"></i> Close</button></form>';
            } else {
                $actions[] = '<form method="post" action="' . h(Txn::url($t, 'reopen')) . '" class="d-inline">' . csrf_field() . '<button class="btn btn-sm btn-outline-dark">Reopen</button></form>';
            }
        }
    }
    $posting = !in_array($type, Posting::NON_POSTING, true);
    if (Access::can($pageKey, 'delete')) {
        if (!$voided && $posting) {
            $actions[] = '<button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#voidModal"><i class="bi bi-slash-circle"></i> Void</button>';
        }
        if (!str_starts_with($type, 'pos_')) {
            $actions[] = '<button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal"><i class="bi bi-trash"></i> Delete</button>';
        }
    }
    if (Access::page('admin.txn_history')) {
        $actions[] = btn_link(url('admin.txn_history', ['id' => $id]), 'History', 'btn-outline-info', 'clock-history');
    }
    $actions[] = btn_link(url($pageKey), 'Back to list', 'btn-outline-secondary');

    layout_start(Txn::ref($t), ['actions' => implode(' ', $actions)]);
    if ($voided) {
        echo '<div class="alert alert-dark"><strong>VOID</strong> — ' . h($t['void_reason']) . ' (' . h(fdatetime($t['voided_at'])) . ')</div>';
    }
    if ((int)$t['is_pending'] === 1) {
        echo '<div class="alert alert-warning">This transaction is <strong>pending</strong>. It does not affect the books or inventory until it is marked final.</div>';
    }
    if (in_array($type, Posting::NON_POSTING, true)) {
        echo '<div class="alert alert-info py-2 small">Non-posting document: it records a commitment and does not affect the ledger.</div>';
    }
    $site = $t['site_id'] ? DB::value('SELECT name FROM sites WHERE id = :id', ['id' => $t['site_id']]) : '';
    $info = [
        'Date' => fdate($t['txn_date']),
        'Number' => $t['txn_no'],
        'Status' => status_badge($t['status']),
    ];
    $ent = entity_name($t);
    if ($ent !== '') {
        $info[$type === Posting::CASH_VOUCHER ? 'Paid to' : ($t['vendor_id'] ? 'Vendor' : ($t['customer_id'] ? 'Customer' : 'Payee'))] = h($ent);
    }
    if (!empty($t['class_id'])) {
        $info['Class'] = h(Classes::name($t['class_id']));
    }
    if ($t['ref_no']) {
        $info['Reference'] = h($t['ref_no']);
    }
    if ($t['due_date']) {
        $info[$type === 'estimate' ? 'Valid until' : ($type === 'purchase_order' ? 'Expected' : 'Due date')] = fdate($t['due_date']);
    }
    if ($t['terms_id']) {
        $info['Terms'] = h(DB::value('SELECT name FROM terms WHERE id = :id', ['id' => $t['terms_id']]));
    }
    if ($t['sales_rep_id']) {
        $info['Sales rep'] = h(DB::value('SELECT name FROM sales_reps WHERE id = :id', ['id' => $t['sales_rep_id']]));
    }
    if ($t['account_id']) {
        $info[$type === Posting::CASH_VOUCHER ? 'Cash account' : (in_array($type, ['check', 'bill_payment', 'refund', 'transfer', 'vendor_advance'], true) ? 'From account' : 'Account')] = h(account_label((int)$t['account_id']));
    }
    if ($t['account2_id']) {
        $info[$type === 'transfer' ? 'To account' : ($type === 'vendor_advance' ? 'Advance account' : 'Other account')] = h(account_label((int)$t['account2_id']));
    }
    if ($t['payment_method_id']) {
        $info['Payment method'] = h(DB::value('SELECT name FROM payment_methods WHERE id = :id', ['id' => $t['payment_method_id']]));
    }
    if ($type === 'inventory_transfer') {
        $info['From site'] = h($site);
        $info['To site'] = h($t['site2_id'] ? (string)DB::value('SELECT name FROM sites WHERE id = :id', ['id' => $t['site2_id']]) : '');
    } elseif ($site) {
        $info['Site'] = h($site);
    }
    if ($t['receipt_no']) {
        $info['Receipt no.'] = h($t['receipt_no']);
    }
    if ($t['memo']) {
        $info['Memo'] = h($t['memo']);
    }
    $creator = DB::value('SELECT full_name FROM users WHERE id = :id', ['id' => $t['created_by']]);
    $info['Entered by'] = h($creator) . ', ' . h(fdatetime($t['created_at']));
    if ($t['approved_by']) {
        $info['Approved by'] = h(DB::value('SELECT full_name FROM users WHERE id = :id', ['id' => $t['approved_by']]));
    }
    ?>
    <div class="row g-3">
      <div class="col-lg-8">
        <div class="card mb-3"><div class="card-body">
          <div class="row g-2">
          <?php foreach ($info as $k => $v): ?>
            <div class="col-sm-6 col-md-4"><div class="small text-muted"><?= h($k) ?></div><div><?= $v ?></div></div>
          <?php endforeach; ?>
          </div>
        </div></div>
        <?php if ($lines): ?>
        <?= $groupSwitch !== '' ? '<div class="mb-2">' . $groupSwitch . '</div>' : '' ?>
        <div class="card mb-3"><div class="table-responsive"><table class="table table-sm mb-0">
          <thead><tr>
            <?php $lineClasses = (bool)array_filter($lines, fn($x) => !empty($x['class_id'])); ?>
            <?php if (is_journal_style($type) || $type === 'deposit'): ?>
              <th>Account</th><th>Description</th><th>Name</th><?php if ($lineClasses): ?><th>Class</th><?php endif; ?><th class="num">Debit</th><th class="num">Credit</th>
            <?php else: ?>
              <th>Item / Account</th><th>Description</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Disc</th><th class="num">Amount</th><th>Tax</th>
              <?php if (in_array($type, ['sales_order', 'purchase_order'], true)): ?><th class="num"><?= $type === 'sales_order' ? 'Invoiced' : 'Received' ?></th><?php endif; ?>
              <?php if ($showCosts && $isSales && $posting): ?><th class="num">Cost</th><?php endif; ?>
              <?php if (in_array($type, PURCHASE_TYPES, true)): ?><th>Customer:Job</th><?php endif; ?>
            <?php endif; ?>
          </tr></thead>
          <tbody>
          <?php foreach ($lines as $l): ?>
            <?php if (is_journal_style($type) || $type === 'deposit'):
                $amt = (float)$l['amount'];
                if ($type === 'deposit') { $amt = -$amt; }
                $name = line_name($l);
            ?>
              <tr><td><?= h($l['account_code'] . ' - ' . $l['account_name']) ?></td><td><?= h($l['description']) ?><?= $l['ref_txn_id'] ? ' ' . Txn::link((int)$l['ref_txn_id'], '(source)') : '' ?></td><td><?= h($name) ?></td>
                <?php if ($lineClasses): ?><td class="small"><?= h($l['class_name'] ?? '') ?></td><?php endif; ?>
                <td class="num"><?= $amt > 0 ? money($amt) : '' ?></td><td class="num"><?= $amt < 0 ? money(-$amt) : '' ?></td></tr>
            <?php else: ?>
              <tr class="<?= (int)$l['is_closed'] ? 'text-muted' : '' ?>">
                <td><?= h($l['item_code'] ?: ($l['account_code'] ? $l['account_code'] . ' - ' . $l['account_name'] : '')) ?><?= $l['line_kind'] === 'component' ? ' <span class="badge text-bg-light">component</span>' : '' ?></td>
                <td><?= h($l['description']) ?><?= !empty($l['_group']) ? '<div class="small text-muted group-members">Item group: ' . h(implode(', ', array_map(fn($m) => qty($m['qty']) . ' ' . ($m['item_code'] ?: $m['description']), $l['_members']))) . '</div>'
                    : (!empty($l['group_ref']) && !empty($l['group_item_id']) ? '<div class="small text-muted">In item group ' . h((string)(item_cache((int)$l['group_item_id'])['code'] ?? '')) . '</div>' : '') ?><?= (int)$l['is_billable'] ? ' <span class="badge text-bg-info">billable</span>' : '' ?><?= $l['billed_txn_id'] ? ' <span class="small">billed on ' . Txn::link((int)$l['billed_txn_id']) . '</span>' : '' ?></td>
                <td class="num"><?= $l['item_type'] === 'subtotal' ? '' : line_qty($l) ?></td>
                <td class="num"><?= $l['item_type'] === 'subtotal' ? '' : line_rate($l) ?></td>
                <td class="num"><?= line_discount($l) ?></td>
                <td class="num"><?= money($l['amount']) ?></td>
                <td class="small"><?= $l['tax_code_id'] ? h(Lookup::taxCodes()[$l['tax_code_id']] ?? '') . ' ' . money($l['tax_amount'], true) : '' ?></td>
                <?php if (in_array($type, ['sales_order', 'purchase_order'], true)): ?><td class="num"><?= qty($l['qty_fulfilled']) ?><?= (int)$l['is_closed'] ? ' <span class="badge text-bg-secondary">closed</span>' : '' ?></td><?php endif; ?>
                <?php if ($showCosts && $isSales && $posting): ?><td class="num"><?= money($l['cost_amount'], true) ?></td><?php endif; ?>
                <?php if (in_array($type, PURCHASE_TYPES, true)): ?><td><?= h($l['customer_name']) ?></td><?php endif; ?>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>
          </tbody>
        </table></div></div>
        <?php endif; ?>

        <?php if ($type === 'pos_sale' || $type === 'pos_return'): $tenders = DB::all('SELECT pt.*, pm.name FROM pos_tenders pt JOIN payment_methods pm ON pm.id = pt.payment_method_id WHERE pt.txn_id = :id', ['id' => $id]); ?>
        <div class="card mb-3"><div class="card-header">Tenders</div><table class="table table-sm mb-0">
          <?php foreach ($tenders as $tn): ?><tr><td><?= h($tn['name']) ?> <?= h($tn['reference']) ?></td><td class="num"><?= money($tn['amount']) ?></td></tr><?php endforeach; ?>
          <tr><td>Amount tendered / change</td><td class="num"><?= money($t['amount_tendered']) ?> / <?= money($t['change_given']) ?></td></tr>
        </table></div>
        <?php endif; ?>

        <?php if ($posting && !$voided && ($showCosts || !$isSales)): $gl = DB::all('SELECT j.*, a.code, a.name, cl.name AS class_name FROM journal_lines j JOIN accounts a ON a.id = j.account_id LEFT JOIN classes cl ON cl.id = j.class_id WHERE j.txn_id = :id ORDER BY j.id', ['id' => $id]); ?>
        <?php if ($gl): $glClass = (bool)array_filter($gl, fn($x) => !empty($x['class_id'])); ?>
        <div class="card mb-3"><div class="card-header small fw-semibold">General ledger entries created automatically</div>
          <div class="table-responsive"><table class="table table-sm mb-0 small"><thead><tr><th>Account</th><th>Memo</th><?php if ($glClass): ?><th>Class</th><?php endif; ?><th class="num">Debit</th><th class="num">Credit</th><th></th></tr></thead>
          <?php $sd = 0; $sc = 0; foreach ($gl as $g): $sd += $g['debit']; $sc += $g['credit']; ?>
            <tr><td><?= h($g['code'] . ' - ' . $g['name']) ?></td><td><?= h($g['memo']) ?></td><?php if ($glClass): ?><td><?= h($g['class_name']) ?></td><?php endif; ?><td class="num"><?= money($g['debit'], true) ?></td><td class="num"><?= money($g['credit'], true) ?></td><td><?= (int)$g['cleared'] ? '<span class="badge text-bg-success">cleared</span>' : '' ?></td></tr>
          <?php endforeach; ?>
          <tr class="fw-semibold"><td colspan="<?= $glClass ? 3 : 2 ?>">Total</td><td class="num"><?= money($sd) ?></td><td class="num"><?= money($sc) ?></td><td></td></tr>
          </table></div>
        </div>
        <?php endif; endif; ?>
      </div>
      <div class="col-lg-4">
        <div class="card mb-3"><div class="card-body">
          <table class="table table-sm mb-0 totals-box">
            <?php if ((float)$t['subtotal'] != 0.0 || (float)$t['tax_total'] != 0.0): ?>
            <tr><td>Subtotal</td><td class="num"><?= money($t['subtotal']) ?></td></tr>
            <tr><td>Tax</td><td class="num"><?= money($t['tax_total']) ?></td></tr>
            <?php endif; ?>
            <?php if ((float)$t['discount_amount'] != 0.0): ?><tr><td>Discount</td><td class="num"><?= money($t['discount_amount']) ?></td></tr><?php endif; ?>
            <?php if ((float)$t['withholding_amount'] != 0.0): ?><tr><td>Withholding tax</td><td class="num"><?= money($t['withholding_amount']) ?></td></tr><?php endif; ?>
            <?php if ((float)$t['cash_back_amount'] != 0.0): ?><tr><td>Cash back</td><td class="num"><?= money($t['cash_back_amount']) ?></td></tr><?php endif; ?>
            <?php if (in_array($type, ['inventory_transfer', 'build_assembly'], true)): ?>
            <tr class="fw-bold"><td>Total quantity</td><td class="num"><?= qty(array_sum(array_map(fn($x) => $x['line_kind'] === 'component' ? 0.0 : (float)$x['qty'], $lines))) ?></td></tr>
            <?php else: ?>
            <tr class="fw-bold"><td>Total</td><td class="num"><?= money($t['total']) ?></td></tr>
            <?php endif; ?>
            <?php if ($open !== null): ?><tr class="fw-semibold"><td>Open balance</td><td class="num"><?= money($open) ?></td></tr><?php endif; ?>
            <?php if ($credit !== null): ?><tr class="fw-semibold"><td>Unapplied credit</td><td class="num"><?= money($credit) ?></td></tr><?php endif; ?>
            <?php if ($advLeft !== null): ?><tr><td>Applied to bills</td><td class="num"><?= money(Advances::applied($id)) ?></td></tr><tr class="fw-semibold"><td>Left to apply</td><td class="num"><?= money($advLeft) ?></td></tr><?php endif; ?>
          </table>
        </div></div>
        <?php $whtRows = in_array($type, ['bill_payment', 'payment'], true) ? Withholding::forPayment($id) : []; if ($whtRows): ?>
        <div class="card mb-3"><div class="card-header small fw-semibold"><?= $type === 'payment' ? 'Taxes withheld by the customer' : 'Taxes withheld from the supplier' ?></div>
          <div class="table-responsive"><table class="table table-sm mb-0 small"><thead><tr><th>Document</th><th>Tax</th><th class="num">Base</th><th class="num">Amount</th></tr></thead>
          <?php foreach ($whtRows as $w): ?>
            <tr><td><?= $w['doc_id'] ? Txn::link((int)$w['doc_id'], (string)($w['doc_ref'] ?: $w['doc_no'])) : '' ?></td><td><?= h($w['name']) ?> <?= h(Withholding::rateText($w['rate'])) ?><?= $w['atc'] ? '<div class="text-muted">ATC ' . h($w['atc']) . '</div>' : '' ?></td>
              <td class="num"><?= money($w['base']) ?></td><td class="num"><?= money($w['amount']) ?></td></tr>
          <?php endforeach; ?>
          </table></div>
        </div>
        <?php endif; ?>
        <?php $rel = related_docs($t); if ($rel): ?>
        <div class="card mb-3"><div class="card-header small fw-semibold">Related documents</div>
          <ul class="list-group list-group-flush small">
            <?php foreach ($rel as [$label, $d]): ?>
              <li class="list-group-item"><?= h($label) ?>: <?= Txn::link($d, Txn::ref($d)) ?> <span class="text-muted"><?= h(fdate($d['txn_date'])) ?></span></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>
        <?php $docs = DB::all('SELECT * FROM receipt_documents WHERE linked_txn_id = :id', ['id' => $id]); if ($docs): ?>
        <div class="card mb-3"><div class="card-header small fw-semibold">Attached receipts</div>
          <ul class="list-group list-group-flush small">
            <?php foreach ($docs as $d): ?><li class="list-group-item"><?= safe_link('vend.receipts', ['a' => 'file', 'id' => $d['id']], $d['original_name']) ?></li><?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php
    echo $GLOBALS['TXN_VIEW_EXTRA'] ?? '';
    if (in_array($type, Memorized::TYPES, true) && (int)$t['is_voided'] === 0 && Access::can('coa.memorized_txns', 'create')) {
        echo memorize_modal($t);
    }
    if (Access::can($pageKey, 'delete')) {
        echo reason_modal('voidModal', 'Void transaction', Txn::url($t, 'void'), 'btn-warning',
            '<p class="small text-muted mt-2">Voiding keeps the document but removes its effect on the ledger and inventory.</p>');
        echo reason_modal('deleteModal', 'Delete transaction', Txn::url($t, 'delete'), 'btn-danger',
            '<p class="small text-muted mt-2">The document is removed; its full content stays in the audit trail.</p>');
    }
    layout_end();
}

function render_txn_print(array $t): void
{
    layout_start(Txn::ref($t), ['bare' => true, 'no_title' => true]);
    ?>
    <div class="no-print mb-2 text-center"><button class="btn btn-primary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
      <a class="btn btn-outline-secondary btn-sm" href="<?= h(Txn::url($t)) ?>">Back</a></div>
    <?php
    txn_print_body($t);
    layout_end();
}

/**
 * Journal entries and cash vouchers print in journal format:
 * Account, Description, Name, Debit and Credit, with the totals of both columns underneath.
 */
function journal_print_body(array $t): void
{
    $lines = Txn::lines((int)$t['id']);
    $s = Settings::all();
    $type = $t['txn_type'];
    $isVoucher = $type === Posting::CASH_VOUCHER;
    $payee = entity_name($t);
    $rows = [];
    foreach ($lines as $l) {
        $rows[] = ['account' => trim($l['account_code'] . ' - ' . $l['account_name'], ' -'), 'description' => (string)$l['description'],
            'name' => line_name($l), 'class' => (string)($l['class_name'] ?? ''), 'amount' => (float)$l['amount']];
    }
    if ($isVoucher && $t['account_id']) {
        // the cash side of the voucher, so the entry prints complete and in balance
        $rows[] = ['account' => account_label((int)$t['account_id']), 'description' => (string)($t['memo'] ?: 'Cash paid out'), 'name' => $payee, 'class' => '', 'amount' => -(float)$t['total']];
    }
    // the Class column is always printed; a line without its own class shows the class of the entry
    $showClass = Classes::ready();
    $headClass = $showClass && !empty($t['class_id']) ? Classes::name($t['class_id']) : '';
    foreach ($rows as &$r) {
        if ($r['class'] === '') {
            $r['class'] = $headClass;
        }
    }
    unset($r);
    $site = print_site_name($t);
    $td = 0.0;
    $tc = 0.0;
    ?>
    <div class="doc doc-journal">
      <div class="d-flex justify-content-between">
        <?= print_company_block('doc.' . $type) ?>
        <div class="text-end">
          <h2 class="mb-0"><?= h(strtoupper(Txn::label($type))) ?></h2>
          <div>No. <strong><?= h($t['txn_no']) ?></strong></div>
          <div>Date: <?= h(fdate($t['txn_date'])) ?></div>
          <?php if ((int)$t['is_voided'] === 1): ?><div class="text-danger fw-bold">VOID</div><?php endif; ?>
        </div>
      </div>
      <hr>
      <div class="row mb-3 small">
        <div class="col-7">
          <?php if ($isVoucher): ?>
            <div>Paid to: <strong><?= h($payee) ?></strong></div>
            <div>Cash account: <?= h(account_label((int)$t['account_id'])) ?></div>
            <div>Amount: <strong><?= money($t['total']) ?></strong> (<?= h(amount_in_words((float)$t['total'])) ?>)</div>
          <?php elseif ($payee !== ''): ?>
            <div>Name: <strong><?= h($payee) ?></strong></div>
          <?php endif; ?>
          <?php if ($t['memo']): ?><div><?= $isVoucher ? 'Particulars' : 'Memo' ?>: <?= h($t['memo']) ?></div><?php endif; ?>
        </div>
        <div class="col-5 text-end">
          <?php if ($t['ref_no']): ?><div>Reference: <?= h($t['ref_no']) ?></div><?php endif; ?>
          <?php if ($site !== ''): ?><div>Location / site: <?= h($site) ?></div><?php endif; ?>
          <?php if (!empty($t['class_id'])): ?><div>Class: <?= h(Classes::name($t['class_id'])) ?></div><?php endif; ?>
        </div>
      </div>
      <table class="table table-sm journal-print">
        <thead><tr><th>Account</th><th>Description</th><th>Name</th><?php if ($showClass): ?><th>Class</th><?php endif; ?><th class="num">Debit</th><th class="num">Credit</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $td += max(0, $r['amount']); $tc += max(0, -$r['amount']); ?>
          <tr><td><?= h($r['account']) ?></td><td><?= h($r['description']) ?></td><td><?= h($r['name']) ?></td><?php if ($showClass): ?><td><?= h($r['class']) ?></td><?php endif; ?>
            <td class="num"><?= $r['amount'] > 0 ? money($r['amount']) : '' ?></td><td class="num"><?= $r['amount'] < 0 ? money(-$r['amount']) : '' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr class="journal-totals"><td colspan="<?= $showClass ? 4 : 3 ?>" class="text-end">Total</td><td class="num"><?= money($td) ?></td><td class="num"><?= money($tc) ?></td></tr></tfoot>
      </table>
      <?= print_signatures($t, $isVoucher) ?>
    </div>
    <?php
}

/**
 * "Memorize" dialog of a transaction: saves a copy on the Memorized Transactions list
 * with how and when it should be entered again.
 */
function memorize_modal(array $t): string
{
    $name = trim((string)(Memorized::entityLabel($t) . ' ' . Txn::label($t['txn_type'])));
    ob_start();
    ?>
    <div class="modal fade" id="memorizeModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post" action="<?= h(url('coa.memorized_txns', ['a' => 'add'])) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="txn_id" value="<?= (int)$t['id'] ?>">
      <input type="hidden" name="back" value="<?= h(current_url()) ?>">
      <div class="modal-header"><h5 class="modal-title">Memorize this <?= h(strtolower(Txn::label($t['txn_type']))) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body row g-2">
        <?= input_field('name', 'Name it', $name, ['class' => 'col-md-8', 'required' => true, 'extra' => 'maxlength="120"']) ?>
        <?= input_field('group_name', 'Group (optional)', '', ['class' => 'col-md-4', 'extra' => 'maxlength="60"']) ?>
        <?= select_field('how', 'How it is entered', Memorized::HOW, 'remind', ['class' => 'col-12', 'extra' => 'data-no-combo']) ?>
        <div class="col-md-6"><label class="form-label">Every</label><div class="input-group input-group-sm">
          <input class="form-control text-end" type="number" min="1" step="1" name="every_n" value="1">
          <select class="form-select" name="frequency" data-no-combo><?= options(Memorized::FREQ, 'monthly') ?></select></div></div>
        <?= input_field('next_date', 'Next due', date('Y-m-d', strtotime(($t['txn_date'] ?: today()) . ' +1 month')), ['type' => 'date', 'class' => 'col-md-6']) ?>
        <?= input_field('days_in_advance', 'Remind (days before)', '5', ['type' => 'number', 'step' => '1', 'class' => 'col-md-6', 'extra' => 'min="0" max="90"']) ?>
        <?= input_field('remaining', 'Number remaining (blank = no limit)', '', ['class' => 'col-md-6', 'extra' => 'inputmode="numeric"']) ?>
        <?= input_field('notes', 'Notes', '', ['class' => 'col-12', 'extra' => 'maxlength="255"']) ?>
        <div class="col-12 small text-muted">The names, accounts, items and amounts are kept. The date and the document number are given each time it is entered.</div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary"><i class="bi bi-bookmark-plus"></i> Memorize</button></div>
    </form></div></div>
    <?php
    return (string)ob_get_clean();
}

/** Company name block of every print-out, with the logo when the administrator switched it on for this document */
function print_company_block(string $logoKey): string
{
    $s = Settings::all();
    $logo = Logo::img($logoKey);
    return '<div class="doc-company' . ($logo !== '' ? ' has-logo' : '') . '">' . $logo
        . '<div><h2 class="mb-0">' . h($s['company_name']) . '</h2><div class="small">' . h($s['company_address']) . '<br>TIN ' . h($s['company_tin'])
        . ($s['company_phone'] ? ', ' . h($s['company_phone']) : '') . '</div></div></div>';
}

/** Prepared by / Checked by / Approved by (/ Received by) */
function print_signatures(array $t, bool $received = false): string
{
    $who = fn($id) => $id ? h((string)DB::value('SELECT full_name FROM users WHERE id = :id', ['id' => $id])) : '&nbsp;';
    return '<div class="row mt-5 small signatures">'
        . '<div class="col"><div class="sig-label">Prepared by:</div><div class="sig-line">' . $who($t['created_by']) . '</div></div>'
        . '<div class="col"><div class="sig-label">Checked by:</div><div class="sig-line">&nbsp;</div></div>'
        . '<div class="col"><div class="sig-label">Approved by:</div><div class="sig-line">' . $who($t['approved_by'] ?? null) . '</div></div>'
        . ($received ? '<div class="col"><div class="sig-label">Received by:</div><div class="sig-line">&nbsp;</div></div>' : '')
        . '</div>';
}

function print_site_name(array $t): string
{
    return !empty($t['site_id']) ? (string)DB::value('SELECT name FROM sites WHERE id = :id', ['id' => $t['site_id']]) : '';
}

/**
 * Ledger voucher: the print-out of checks (CHECK VOUCHER), bill payments, refunds, deposits and fund transfers.
 * Same layout as the journal entry print: the ledger entries with Account, Memo, Class, Debit and Credit,
 * the two totals below, then Prepared by / Checked by / Approved by (/ Received by).
 */
function check_voucher_print_body(array $t): void
{
    $type = $t['txn_type'];
    $paysOut = in_array($type, ['check', 'bill_payment', 'refund', 'vendor_advance'], true);
    $payee = entity_name($t);
    $party = null;
    if ($t['vendor_id']) {
        $party = DB::row('SELECT address, tin FROM vendors WHERE id = :id', ['id' => $t['vendor_id']]);
    } elseif ($t['customer_id']) {
        $party = DB::row('SELECT billing_address AS address, tin FROM customers WHERE id = :id', ['id' => $t['customer_id']]);
    }
    $useClass = Classes::ready();
    $gl = DB::all('SELECT j.debit, j.credit, j.memo, a.code, a.name' . ($useClass ? ', c.name AS class_name' : ", '' AS class_name") . '
                     FROM journal_lines j JOIN accounts a ON a.id = j.account_id' . ($useClass ? ' LEFT JOIN classes c ON c.id = j.class_id' : '') . '
                    WHERE j.txn_id = :id ORDER BY j.credit > 0, j.id', ['id' => $t['id']]);
    $bills = $type === 'bill_payment'
        ? DB::all('SELECT t2.txn_no, t2.ref_no, t2.txn_date, pa.amount, pa.discount, pa.withholding FROM payment_applications pa JOIN transactions t2 ON t2.id = pa.target_txn_id WHERE pa.payment_txn_id = :id ORDER BY t2.txn_date, t2.id', ['id' => $t['id']])
        : [];
    $site = print_site_name($t);
    $td = 0.0;
    $tc = 0.0;
    $cols = $useClass ? 5 : 4;
    $withheld = (bool)array_filter($bills, fn($b) => (float)$b['withholding'] != 0.0);
    $whtRows = $withheld ? Withholding::forPayment((int)$t['id']) : [];
    ?>
    <div class="doc doc-journal">
      <div class="d-flex justify-content-between">
        <?= print_company_block('doc.' . $type) ?>
        <div class="text-end">
          <h2 class="mb-0"><?= h(voucher_title($t)) ?></h2>
          <div>No. <strong><?= h($t['txn_no']) ?></strong></div>
          <div>Date: <?= h(fdate($t['txn_date'])) ?></div>
          <?php if ((int)$t['is_voided'] === 1): ?><div class="text-danger fw-bold">VOID</div><?php endif; ?>
        </div>
      </div>
      <hr>
      <div class="row mb-3 small">
        <div class="col-7">
          <?php if ($paysOut): ?>
            <div>Pay to: <strong><?= h($payee) ?></strong></div>
            <?php if ($party && trim((string)$party['address']) !== ''): ?><div><?= nl2br(h($party['address'])) ?></div><?php endif; ?>
            <?php if ($party && $party['tin']): ?><div>TIN <?= h($party['tin']) ?></div><?php endif; ?>
            <div class="mt-1">Amount: <strong><?= money($t['total']) ?></strong> (<?= h(amount_in_words((float)$t['total'])) ?>)</div>
          <?php elseif ($type === 'deposit'): ?>
            <div>Deposited to: <strong><?= h(account_label((int)$t['account_id'])) ?></strong></div>
            <div class="mt-1">Amount: <strong><?= money($t['total']) ?></strong> (<?= h(amount_in_words((float)$t['total'])) ?>)</div>
          <?php elseif ($type === 'transfer'): ?>
            <div>From: <strong><?= h(account_label((int)$t['account_id'])) ?></strong></div>
            <div>To: <strong><?= h(account_label((int)$t['account2_id'])) ?></strong></div>
            <div class="mt-1">Amount: <strong><?= money($t['total']) ?></strong> (<?= h(amount_in_words((float)$t['total'])) ?>)</div>
          <?php endif; ?>
          <?php if ($t['memo']): ?><div>Memo: <?= h($t['memo']) ?></div><?php endif; ?>
        </div>
        <div class="col-5 text-end">
          <?php if ($paysOut && $t['account_id']): ?><div>Paid from: <?= h(account_label((int)$t['account_id'])) ?></div><?php endif; ?>
          <?php if ($t['ref_no']): ?><div>Reference: <?= h($t['ref_no']) ?></div><?php endif; ?>
          <?php if ($site !== ''): ?><div>Location / site: <?= h($site) ?></div><?php endif; ?>
          <?php if (!empty($t['class_id'])): ?><div>Class: <?= h(Classes::name($t['class_id'])) ?></div><?php endif; ?>
        </div>
      </div>
      <?php if ($bills): ?>
      <table class="table table-sm journal-print mb-3">
        <thead><tr><th>Bill paid</th><th>Bill date</th><th class="num">Amount paid</th><th class="num">Discount</th><?php if ($withheld): ?><th class="num">Tax withheld</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($bills as $a): ?>
          <tr><td><?= h($a['ref_no'] ?: $a['txn_no']) ?></td><td><?= h(fdate($a['txn_date'])) ?></td><td class="num"><?= money($a['amount']) ?></td><td class="num"><?= money($a['discount'], true) ?></td>
            <?php if ($withheld): ?><td class="num"><?= money($a['withholding'], true) ?></td><?php endif; ?></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($whtRows): ?>
      <div class="small mb-3">Taxes withheld: <?= h(implode('; ', array_map(fn($w) => $w['name'] . ' ' . Withholding::rateText($w['rate']) . ($w['atc'] ? ' (' . $w['atc'] . ')' : '') . ' on ' . money($w['base']) . ' = ' . money($w['amount']), $whtRows))) ?>. BIR Form 2307 is issued for the quarter.</div>
      <?php endif; ?>
      <?php endif; ?>
      <table class="table table-sm journal-print">
        <thead><tr><th>Account</th><th>Memo</th><?php if ($useClass): ?><th>Class</th><?php endif; ?><th class="num">Debit</th><th class="num">Credit</th></tr></thead>
        <tbody>
        <?php foreach ($gl as $g): $td += (float)$g['debit']; $tc += (float)$g['credit']; ?>
          <tr><td><?= h($g['code'] . ' - ' . $g['name']) ?></td><td><?= h($g['memo']) ?></td><?php if ($useClass): ?><td><?= h($g['class_name']) ?></td><?php endif; ?>
            <td class="num"><?= (float)$g['debit'] != 0.0 ? money($g['debit']) : '' ?></td><td class="num"><?= (float)$g['credit'] != 0.0 ? money($g['credit']) : '' ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$gl): ?><tr><td colspan="<?= $cols ?>" class="text-muted">No ledger entries (<?= (int)$t['is_voided'] === 1 ? 'voided' : 'not posted' ?>).</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr class="journal-totals"><td colspan="<?= $cols - 2 ?>" class="text-end">Total</td><td class="num"><?= money($td) ?></td><td class="num"><?= money($tc) ?></td></tr></tfoot>
      </table>
      <?= print_signatures($t, $paysOut) ?>
    </div>
    <?php
}

/** Title of the voucher print-out: checks print as CHECK VOUCHER */
function voucher_title(array $t): string
{
    $type = $t['txn_type'];
    if ($type === 'deposit') {
        return 'DEPOSIT';
    }
    if ($type === 'transfer') {
        return 'FUND TRANSFER';
    }
    if ($type === 'refund') {
        return 'REFUND VOUCHER';
    }
    if ($type === 'bill_payment' && Advances::sourceOf($t)) {
        return 'ADVANCE APPLIED';
    }
    if (in_array($type, ['bill_payment', 'vendor_advance'], true) && !empty($t['payment_method_id'])) {
        $kind = (string)DB::value('SELECT method_type FROM payment_methods WHERE id = :id', ['id' => $t['payment_method_id']]);
        if ($kind !== '' && $kind !== 'check') {
            return 'PAYMENT VOUCHER';
        }
    }
    return 'CHECK VOUCHER';
}

function prints_as_check_voucher(string $type): bool
{
    return in_array($type, ['check', 'bill_payment', 'refund', 'deposit', 'transfer', 'vendor_advance'], true);
}

/** The printable document itself (used for single and batch printing) */
function txn_print_body(array $t): void
{
    if (is_journal_style($t['txn_type'])) {
        journal_print_body($t);
        return;
    }
    if (prints_as_check_voucher($t['txn_type'])) {
        check_voucher_print_body($t);
        return;
    }
    $lines = Txn::lines((int)$t['id']);
    // item groups print as one line each, or as the items in them (?lines=details)
    if (in_array($t['txn_type'], SALES_TYPES, true) && ItemGroups::ready() && ItemGroups::has($lines) && ItemGroups::viewMode() === 'groups') {
        $lines = ItemGroups::collapse($lines);
    }
    $s = Settings::all();
    $type = $t['txn_type'];
    $title = strtoupper(Txn::label($type));
    if ($type === 'sales_receipt' || $type === 'pos_sale') {
        $title = strtoupper($s['pos_receipt_title'] ?: 'OFFICIAL RECEIPT');
    }
    $party = null;
    if ($t['customer_id']) {
        $party = DB::row('SELECT name, company_name, billing_address AS address, tin FROM customers WHERE id = :id', ['id' => $t['customer_id']]);
    } elseif ($t['vendor_id']) {
        $party = DB::row('SELECT name, company_name, address, tin FROM vendors WHERE id = :id', ['id' => $t['vendor_id']]);
    }
    ?>
    <div class="doc">
      <div class="d-flex justify-content-between">
        <?= print_company_block('doc.' . $type) ?>
        <div class="text-end">
          <h2 class="mb-0"><?= h($title) ?></h2>
          <div>No. <strong><?= h($t['receipt_no'] ?: $t['txn_no']) ?></strong></div>
          <div>Date: <?= h(fdate($t['txn_date'])) ?></div>
          <?php if ($t['due_date']): ?><div><?= $type === 'estimate' ? 'Valid until' : 'Due' ?>: <?= h(fdate($t['due_date'])) ?></div><?php endif; ?>
          <?php if ((int)$t['is_voided'] === 1): ?><div class="text-danger fw-bold">VOID</div><?php endif; ?>
        </div>
      </div>
      <hr>
      <div class="row mb-3">
        <?php if ($type === 'inventory_transfer'): $toSite = $t['site2_id'] ? (string)DB::value('SELECT name FROM sites WHERE id = :id', ['id' => $t['site2_id']]) : ''; ?>
        <div class="col-6">
          <div class="small text-muted">From site</div><strong><?= h(print_site_name($t)) ?></strong>
          <div class="small text-muted mt-2">To site</div><strong><?= h($toSite) ?></strong>
        </div>
        <div class="col-6">
          <?php if ($t['ref_no']): ?><div class="small">Reference: <?= h($t['ref_no']) ?></div><?php endif; ?>
        <?php else: ?>
        <div class="col-6">
          <div class="small text-muted"><?= $t['vendor_id'] ? 'Vendor' : ($t['customer_id'] ? 'Bill to' : 'Pay to') ?></div>
          <?php if ($party): ?>
            <strong><?= h($party['company_name'] ?: $party['name']) ?></strong><br><?= nl2br(h($party['address'])) ?><?= $party['tin'] ? '<br>TIN ' . h($party['tin']) : '' ?>
          <?php else: ?><strong><?= h($t['payee_name']) ?></strong><?php endif; ?>
        </div>
        <div class="col-6">
          <?php if ($t['shipping_address']): ?><div class="small text-muted">Ship to</div><?= nl2br(h($t['shipping_address'])) ?><?php endif; ?>
          <?php if ($t['ref_no']): ?><div class="small">Reference: <?= h($t['ref_no']) ?></div><?php endif; ?>
          <?php if ($t['terms_id']): ?><div class="small">Terms: <?= h(DB::value('SELECT name FROM terms WHERE id = :id', ['id' => $t['terms_id']])) ?></div><?php endif; ?>
          <?php $site = print_site_name($t); if ($site !== ''): ?><div class="small">Location / site: <?= h($site) ?></div><?php endif; ?>
        <?php endif; ?>
          <?php if (Classes::ready() && !empty($t['class_id'])): ?><div class="small">Class: <?= h(Classes::name($t['class_id'])) ?></div><?php endif; ?>
        </div>
      </div>
      <?php if (in_array($type, ['check', 'bill_payment', 'refund'], true)): ?>
        <div class="border p-3 mb-3">
          <div class="d-flex justify-content-between"><span>PAY TO THE ORDER OF <strong><?= h(entity_name($t)) ?></strong></span><span class="fs-5">**<?= money($t['total']) ?>**</span></div>
          <div class="small text-muted mt-2"><?= h(account_label((int)$t['account_id'])) ?></div>
          <div class="small">Memo: <?= h($t['memo']) ?></div>
        </div>
      <?php endif; ?>
      <?php $qtyOnly = in_array($type, ['inventory_transfer', 'build_assembly'], true); ?>
      <?php if ($lines && !in_array($type, ['bill_payment'], true)): ?>
      <table class="table table-sm">
        <thead><tr><th>Item</th><th>Description</th><th class="num">Qty</th><?php if (!$qtyOnly): ?><th class="num">Rate</th><th class="num">Amount</th><?php endif; ?></tr></thead>
        <tbody>
        <?php $qtySum = 0.0; foreach ($lines as $l): if ($l['line_kind'] === 'component') { continue; } $qtySum += (float)$l['qty']; ?>
          <tr><td><?= h($l['item_code'] ?: $l['account_name']) ?></td><td><?= h($l['description']) ?><?= line_discount($l) !== '' && $l['item_type'] !== 'discount' ? ' (less ' . line_discount($l) . ')' : '' ?></td>
            <td class="num"><?= $l['item_type'] === 'subtotal' ? '' : line_qty($l) ?></td><?php if (!$qtyOnly): ?><td class="num"><?= line_rate($l) ?></td><td class="num"><?= money($l['amount']) ?></td><?php endif; ?></tr>
        <?php endforeach; ?>
        <?php if ($qtyOnly): ?><tr class="fw-semibold"><td colspan="2">Total quantity</td><td class="num"><?= qty($qtySum) ?></td></tr><?php endif; ?>
        </tbody>
      </table>
      <?php endif; ?>
      <?php if ($type === 'bill_payment'): ?>
      <table class="table table-sm"><thead><tr><th>Bill</th><th>Date</th><th class="num">Amount paid</th><th class="num">Discount</th></tr></thead>
        <?php foreach (DB::all('SELECT t2.txn_no, t2.ref_no, t2.txn_date, pa.amount, pa.discount FROM payment_applications pa JOIN transactions t2 ON t2.id = pa.target_txn_id WHERE pa.payment_txn_id = :id', ['id' => $t['id']]) as $a): ?>
          <tr><td><?= h($a['ref_no'] ?: $a['txn_no']) ?></td><td><?= h(fdate($a['txn_date'])) ?></td><td class="num"><?= money($a['amount']) ?></td><td class="num"><?= money($a['discount'], true) ?></td></tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
      <div class="d-flex justify-content-between">
        <div class="small" style="max-width:55%"><?= h($t['customer_message']) ?><?= $t['memo'] && !in_array($type, ['check'], true) ? '<br><span class="text-muted">' . h($t['memo']) . '</span>' : '' ?></div>
        <?php if (!$qtyOnly): ?>
        <table class="table table-sm w-auto">
          <?php if ((float)$t['tax_total'] != 0.0): ?>
          <tr><td>Subtotal (net of VAT)</td><td class="num"><?= money($t['subtotal']) ?></td></tr>
          <tr><td>VAT</td><td class="num"><?= money($t['tax_total']) ?></td></tr>
          <?php endif; ?>
          <tr class="fw-bold"><td>Total</td><td class="num"><?= money($t['total']) ?></td></tr>
          <?php if (in_array($type, ['invoice', 'statement_charge', 'finance_charge', 'bill'], true)): ?>
          <tr><td>Balance due</td><td class="num"><?= money(Balances::open($t)) ?></td></tr>
          <?php endif; ?>
        </table>
        <?php endif; ?>
      </div>
      <?= print_signatures($t, true) ?>
    </div>
    <?php
}

/** Generic list of transactions for a page */
function render_txn_list(string $pageKey, array $types, string $title, array $opt = []): void
{
    $from = gdate('from', date('Y-m-d', strtotime('-90 days')));
    $to = gdate('to', date('Y-m-d', strtotime('+30 days')));
    // a ready-made period (This month, Last month ...) fills in From and To
    $preset = (string)g('dates');
    $presets = ['' => 'Custom', 'today' => 'Today', 'this_week' => 'This week', 'this_month' => 'This month', 'last_month' => 'Last month',
        'this_quarter' => 'This quarter', 'last_quarter' => 'Last quarter', 'this_year' => 'This fiscal year', 'last_year' => 'Last fiscal year', 'all' => 'All dates'];
    if ($preset !== '' && isset($presets[$preset])) {
        [$from, $to] = Report::preset($preset);
    }
    $status = g('status');
    $q = g('q');
    $where = ['t.txn_date BETWEEN :f AND :t'];
    [$in, $params] = DB::in('ty', $types);
    $where[] = "t.txn_type IN $in";
    $params += ['f' => $from, 't' => $to];
    // a list of its own documents only (Receive Items List: what was received on that page)
    if (!empty($opt['where'])) {
        $where[] = $opt['where'];
        $params += $opt['where_params'] ?? [];
    }
    if ($status === 'pending') {
        $where[] = 't.is_pending = 1';
    } elseif ($status === 'unpaid') {
        $where[] = "t.status IN ('open','partial') AND t.is_pending = 0";
    } elseif ($status === 'to_print') {
        $where[] = 't.to_be_printed = 1';
    } elseif ($status !== '') {
        $where[] = 't.status = :st';
        $params['st'] = $status;
    }
    if ($q !== '') {
        $where[] = '(t.txn_no LIKE :q OR t.ref_no LIKE :q OR t.memo LIKE :q OR c.name LIKE :q OR v.name LIKE :q OR t.payee_name LIKE :q)';
        $params['q'] = "%$q%";
    }
    if ($cid = gint('customer_id')) {
        $where[] = 't.customer_id = :cid';
        $params['cid'] = $cid;
    }
    if ($vid = gint('vendor_id')) {
        $where[] = 't.vendor_id = :vid';
        $params['vid'] = $vid;
    }
    $useClasses = Classes::ready() && Classes::inUse();
    $classId = $useClasses ? gint('class_id') : null;
    if ($classId) {
        [$csql, $cparams] = Classes::sqlFilter($classId, 't', 'lclass');
        $where[] = ltrim($csql, ' AND');
        $params += $cparams;
    }
    [$sf, $sp] = Access::siteFilter('t.site_id');
    $rows = DB::all(
        'SELECT t.*, COALESCE(c.name, v.name, o.name, t.payee_name) AS party, pc.name AS parent_name, u.username
           FROM transactions t
           LEFT JOIN customers c ON c.id = t.customer_id
           LEFT JOIN customers pc ON pc.id = c.parent_id
           LEFT JOIN vendors v ON v.id = t.vendor_id
           LEFT JOIN other_names o ON o.id = t.other_name_id
           LEFT JOIN users u ON u.id = t.created_by
          WHERE ' . implode(' AND ', $where) . $sf . '
          ORDER BY t.txn_date DESC, t.id DESC LIMIT 500',
        $params + $sp
    );
    $showOpen = (bool)array_intersect($types, ['invoice', 'bill', 'statement_charge', 'finance_charge', 'credit_memo', 'payment', 'vendor_credit']);
    // transfers: where the items came from and went to, instead of a name and a money total
    $siteCols = !empty($opt['site_cols']);
    $siteNames = $siteCols ? DB::pairs('SELECT id, name FROM sites') : [];
    $qtyOf = $siteCols ? DB::pairs('SELECT txn_id, SUM(qty) FROM transaction_lines WHERE txn_id IN (' . (implode(',', array_map(fn($r) => (int)$r['id'], $rows)) ?: '0') . ') GROUP BY txn_id') : [];

    if (g('export') === 'csv' && Access::can($pageKey, 'print')) {
        Audit::log('export', explode('.', $pageKey)[0], 'list', null, $title, ['page_key' => $pageKey]);
        if ($siteCols) {
            csv_download($pageKey . '.csv', ['Date', 'Number', 'From site', 'To site', 'Reference', 'Memo', 'Class', 'Quantity', 'Status'], array_map(fn($r) => [
                $r['txn_date'], $r['txn_no'], $siteNames[$r['site_id']] ?? '', $siteNames[$r['site2_id']] ?? '', $r['ref_no'], $r['memo'], Classes::name($r['class_id'] ?? null), $qtyOf[$r['id']] ?? 0, $r['status'],
            ], $rows));
        }
        csv_download($pageKey . '.csv', ['Date', 'Type', 'Number', 'Name', 'Reference', 'Class', 'Total', 'Status'], array_map(fn($r) => [
            $r['txn_date'], Txn::label($r['txn_type']), $r['txn_no'], $r['party'], $r['ref_no'], Classes::name($r['class_id'] ?? null), $r['total'], $r['status'],
        ], $rows));
    }

    $actions = $opt['actions'] ?? '';
    if (Access::can($pageKey, 'create') && empty($opt['no_new'])) {
        $actions = btn_link(url($pageKey, ['a' => 'new']), $opt['new_label'] ?? 'New', 'btn-primary', 'plus-lg') . ' ' . $actions;
    }
    if (Access::can($pageKey, 'print')) {
        $actions .= ' ' . btn_link(current_url(['export' => 'csv']), 'Export', 'btn-outline-secondary', 'download');
    }
    layout_start($title, ['actions' => $actions]);
    $statuses = $opt['statuses'] ?? ['open' => 'Open', 'partial' => 'Partial', 'paid' => 'Paid', 'closed' => 'Closed', 'pending' => 'Pending', 'void' => 'Void'];
    ?>
    <form class="row g-2 mb-3 filters" method="get">
      <input type="hidden" name="p" value="<?= h($pageKey) ?>">
      <?php foreach ((array)($opt['hidden'] ?? []) as $hk => $hv): ?><input type="hidden" name="<?= h((string)$hk) ?>" value="<?= h((string)$hv) ?>"><?php endforeach; ?>
      <div class="col-auto"><label class="form-label">Dates</label><select class="form-select form-select-sm" name="dates" data-no-combo onchange="if(this.value){this.form.from.value='';this.form.to.value='';}this.form.submit()"><?= options($presets, $preset) ?></select></div>
      <div class="col-auto"><label class="form-label">From</label><input class="form-control form-control-sm" type="date" name="from" value="<?= h($from) ?>" onchange="this.form.dates.value=''"></div>
      <div class="col-auto"><label class="form-label">To</label><input class="form-control form-control-sm" type="date" name="to" value="<?= h($to) ?>" onchange="this.form.dates.value=''"></div>
      <div class="col-auto"><label class="form-label">Status</label><select class="form-select form-select-sm" name="status"><?= options($statuses, $status, 'All') ?></select></div>
      <?php if ($useClasses): ?><div class="col-auto"><label class="form-label">Class</label><select class="form-select form-select-sm" name="class_id"><?= options(Classes::filterOptions(), $classId, 'All') ?></select></div><?php endif; ?>
      <div class="col-auto"><label class="form-label">Search</label><input class="form-control form-control-sm" name="q" value="<?= h($q) ?>" placeholder="No., name, memo"></div>
      <div class="col-auto d-flex align-items-end"><button class="btn btn-sm btn-secondary">Filter</button></div>
    </form>
    <?= $opt['before_table'] ?? '' ?>
    <div class="card"><div class="table-responsive">
    <table class="table table-sm table-hover mb-0">
      <thead><tr><th>Date</th><?php if (count($types) > 1): ?><th>Type</th><?php endif; ?><th>No.</th>
        <?php if ($siteCols): ?><th>From site</th><th>To site</th><?php else: ?><th>Name</th><?php endif; ?>
        <th>Reference</th><th>Memo</th><?php if ($useClasses): ?><th>Class</th><?php endif; ?>
        <?php if ($siteCols): ?><th class="num">Quantity</th><?php else: ?><th class="num">Total</th><?php endif; ?>
        <?php if ($showOpen): ?><th class="num">Open</th><?php endif; ?><th>Status</th><th>By</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="11" class="text-muted text-center py-3">No transactions in this period.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r):
          $openAmt = '';
          if ($showOpen && (int)$r['is_voided'] === 0) {
              if (in_array($r['txn_type'], array_merge(Balances::RECEIVABLE_DOCS, Balances::PAYABLE_DOCS), true)) {
                  $openAmt = money(Balances::open($r));
              } elseif (in_array($r['txn_type'], array_merge(Balances::CUSTOMER_CREDITS, Balances::VENDOR_CREDITS), true)) {
                  $openAmt = money(Balances::creditRemaining($r));
              }
          }
      ?>
        <tr class="<?= (int)$r['is_voided'] ? 'text-muted text-decoration-line-through' : '' ?>">
          <td><?= h(fdate($r['txn_date'])) ?></td>
          <?php if (count($types) > 1): ?><td><?= h(Txn::label($r['txn_type'])) ?></td><?php endif; ?>
          <td><a href="<?= h(!empty($opt['own_link']) ? Txn::url($r) : url($pageKey, ['a' => 'view', 'id' => $r['id']])) ?>"><?= h($r['txn_no'] ?: '#' . $r['id']) ?></a></td>
          <?php if ($siteCols): ?>
            <td><?= h($siteNames[$r['site_id']] ?? '') ?></td><td><i class="bi bi-arrow-right-short text-muted"></i> <?= h($siteNames[$r['site2_id']] ?? '') ?></td>
          <?php else: ?>
            <td><?= h(($r['parent_name'] ? $r['parent_name'] . ':' : '') . $r['party']) ?></td>
          <?php endif; ?>
          <td><?= h($r['ref_no'] ?: $r['receipt_no']) ?></td>
          <td class="small"><?= h(mb_strimwidth((string)$r['memo'], 0, 40, '…')) ?></td>
          <?php if ($useClasses): ?><td class="small"><?= h(Classes::name($r['class_id'] ?? null)) ?></td><?php endif; ?>
          <?php if ($siteCols): ?><td class="num"><?= qty($qtyOf[$r['id']] ?? 0) ?></td><?php else: ?><td class="num"><?= money($r['total']) ?></td><?php endif; ?>
          <?php if ($showOpen): ?><td class="num"><?= $openAmt ?></td><?php endif; ?>
          <td><?= status_badge((int)$r['is_pending'] ? 'pending' : $r['status']) ?><?= (int)$r['to_be_printed'] ? ' ' . badge('to print', 'info') : '' ?></td>
          <td class="small text-muted"><?= h($r['username']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div></div>
    <?php
    layout_end();
}
