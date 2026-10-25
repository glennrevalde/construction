<?php
declare(strict_types=1);

/**
 * Make Deposits, and Edit Deposit (bank.deposits&a=edit&id=N, from the Edit button of a deposit).
 *
 * A deposit takes to the bank: customer payments and sales receipts waiting in Undeposited Funds, the cash of
 * closed POS shifts (counted cash less the opening float plus the cash drops), card and e-wallet settlements
 * (less the provider's fee), and other receipts. Editing a deposit shows the same page with what the deposit
 * holds already ticked: a payment or a shift can be taken off it (it waits to be deposited again) or added,
 * and the date, account, slip number, memo, class, other receipts and cash back can be changed. Saving posts the
 * deposit again through Txn::save, so the closed-period and bank-reconciliation protections and the audit trail
 * (before / after, with the reason) apply. A shift's line always takes the shift's figures as they are now, so a
 * deposit follows a correction of the shift's closing entries (see Pos::correctShift).
 */

require_once APP_ROOT . '/modules/txn/_common.php';

$pageKey = 'bank.deposits';
if (txn_common_actions($pageKey, ['deposit'])) {
    return;
}
$a = g('a', 'new');
if ($a === 'list') {
    render_txn_list($pageKey, ['deposit'], 'Deposits', ['statuses' => ['open' => 'Posted', 'void' => 'Void'], 'new_label' => 'Make deposit']);
    return;
}
if ($a === 'clearing') {
    dep_clearing_details($pageKey);
    return;
}

$dep = null;
if ($a === 'edit') {
    Access::require($pageKey, 'edit');
    $dep = Txn::load((int)g('id'));
    if (!$dep || $dep['txn_type'] !== 'deposit') {
        flash('warning', 'Deposit not found.');
        redirect(url($pageKey, ['a' => 'list']));
    }
    if ((int)$dep['is_voided'] === 1) {
        flash('warning', 'A voided deposit cannot be edited.');
        redirect(Txn::url($dep));
    }
} else {
    Access::require($pageKey, 'create');
}
$depId = $dep ? (int)$dep['id'] : 0;

// what the deposit holds now: payments (by the payment), POS shifts (by the shift), and every other line
$inPay = [];
$inShift = [];
$otherLines = [];
if ($dep) {
    $shiftIds = array_map('intval', DB::column('SELECT id FROM pos_shifts WHERE deposit_txn_id = :d', ['d' => $depId]));
    foreach (DB::all('SELECT * FROM transaction_lines WHERE txn_id = :t ORDER BY line_no, id', ['t' => $depId]) as $l) {
        $sid = (int)($l['pos_shift_id'] ?? 0);
        if (!$sid) {
            // a line written before deposits knew their shifts: "POS cash <terminal> <date> (shift #n)"
            foreach ($shiftIds as $cand) {
                if (str_ends_with((string)$l['description'], '(shift #' . $cand . ')')) {
                    $sid = $cand;
                    break;
                }
            }
        }
        if ($l['ref_txn_id']) {
            $inPay[(int)$l['ref_txn_id']] = $l;
        } elseif ($sid) {
            $inShift[$sid] = $l;
        } else {
            $otherLines[] = $l;
        }
    }
}

$uf = Accts::get('undeposited_funds');
$pending = DB::all(
    "SELECT t.*, COALESCE(c.name, t.payee_name) AS party, pm.name AS method
       FROM transactions t
       LEFT JOIN customers c ON c.id = t.customer_id
       LEFT JOIN payment_methods pm ON pm.id = t.payment_method_id
      WHERE t.txn_type IN ('payment','sales_receipt') AND t.is_voided = 0 AND t.is_pending = 0 AND t.account_id = :uf
        AND NOT EXISTS (SELECT 1 FROM transaction_lines l JOIN transactions d ON d.id = l.txn_id AND d.is_voided = 0 AND d.id <> :dep WHERE l.ref_txn_id = t.id)
      ORDER BY t.txn_date, t.id",
    ['uf' => $uf, 'dep' => $depId]
);
$shifts = DB::all(
    "SELECT s.*, t.name AS terminal, t.cash_account_id, cu.full_name AS corrected_name,
            (SELECT COALESCE(SUM(amount),0) FROM pos_cash_drops d WHERE d.shift_id = s.id) AS drops
       FROM pos_shifts s JOIN pos_terminals t ON t.id = s.terminal_id LEFT JOIN users cu ON cu.id = s.corrected_by
      WHERE s.status = 'closed' AND (s.deposit_txn_id IS NULL OR s.deposit_txn_id = :dep) ORDER BY s.business_date, s.id",
    ['dep' => $depId ?: -1]
);
foreach ($shifts as &$s) {
    $s['to_deposit'] = Pos::toDeposit($s);
}
unset($s);
$shifts = array_values(array_filter($shifts, fn($s) => $s['to_deposit'] > 0.004 || isset($inShift[(int)$s['id']])));
// card and e-wallet clearing accounts, each once, with what is unsettled: the account's General Ledger balance as of
// the deposit date, the figure the Trial Balance shows for that date (app/Clearing.php)
$clearDate = (is_post() ? pdate('txn_date') : null) ?? ($dep['txn_date'] ?? null) ?? gdate('txn_date', today());
$clearing = array_values(Clearing::balances($clearDate, true));

handle_post(function () use ($pageKey, $pending, $shifts, $uf, $dep, $depId, $inShift) {
    $bank = pint('account_id');
    $date = pdate('txn_date');
    if (!$bank || !$date) {
        fail('Choose the bank account and deposit date.');
    }
    // a settlement is checked against the balance of its clearing account on the deposit date
    $clearing = Clearing::balances($date, true);
    $lines = [];
    $shiftIds = [];
    $pendingById = array_column($pending, null, 'id');
    foreach ((array)($_POST['pay'] ?? []) as $pid) {
        $t = $pendingById[(int)$pid] ?? null;
        if (!$t) {
            fail('A selected payment was already deposited or changed. Refresh and try again.');
        }
        $lines[] = ['line_kind' => 'deposit', 'account_id' => $uf, 'amount' => (float)$t['total'], 'ref_txn_id' => $t['id'],
            'customer_id' => $t['customer_id'], 'description' => Txn::ref($t) . ($t['ref_no'] ? ' #' . $t['ref_no'] : '') . ' ' . ($t['method'] ?? '')];
    }
    $shiftById = array_column($shifts, null, 'id');
    foreach ((array)($_POST['shift'] ?? []) as $sid) {
        $s = $shiftById[(int)$sid] ?? null;
        if (!$s) {
            fail('A selected POS shift was already deposited.');
        }
        if ($s['to_deposit'] <= 0.004) {
            fail('The shift of ' . $s['terminal'] . ' on ' . fdate($s['business_date']) . ' has nothing left to deposit (counted cash less the float plus the drops). Take it off the deposit.');
        }
        $lines[] = ['line_kind' => 'deposit', 'account_id' => (int)$s['cash_account_id'], 'amount' => $s['to_deposit'], 'pos_shift_id' => (int)$s['id'],
            'description' => 'POS cash ' . $s['terminal'] . ' ' . fdate($s['business_date']) . ' (shift #' . $s['id'] . ')'];
        $shiftIds[] = (int)$s['id'];
    }
    $clearById = $clearing;
    foreach ((array)($_POST['settle'] ?? []) as $aid => $r) {
        $gross = r2(to_num($r['gross'] ?? 0));
        $fee = r2(to_num($r['fee'] ?? 0));
        if ($gross <= 0) {
            if ($fee > 0) {
                fail('Enter the gross amount settled for ' . ($clearById[(int)$aid]['name'] ?? 'the clearing account') . ' with its fee.');
            }
            continue;
        }
        if (!isset($clearById[(int)$aid])) {
            fail('That clearing account is no longer used by a card or e-wallet payment method. Refresh and try again.');
        }
        $c = $clearById[(int)$aid];
        if ($gross > (float)$c['bal'] + 0.004) {
            fail('Settlement for ' . $c['code'] . ' - ' . $c['name'] . ' (' . money($gross) . ') is more than its unsettled balance on ' . fdate($date) . ' (' . money($c['bal']) . ').');
        }
        if ($fee > $gross + 0.004) {
            fail('The fee deducted for ' . $c['name'] . ' is more than the gross settled.');
        }
        $lines[] = ['line_kind' => 'deposit', 'account_id' => (int)$aid, 'amount' => $gross, 'description' => 'Provider settlement ' . ($r['ref'] ?? '')];
        if ($fee > 0) {
            $lines[] = ['line_kind' => 'deposit', 'account_id' => Accts::get('merchant_fees'), 'amount' => -$fee, 'description' => 'Merchant fee deducted'];
        }
    }
    foreach ((array)($_POST['other'] ?? []) as $r) {
        $amt = r2(to_num($r['amount'] ?? 0));
        $acc = (int)($r['account_id'] ?? 0);
        if (abs($amt) < 0.005 && !$acc) {
            continue;
        }
        if (!$acc || abs($amt) < 0.005) {
            fail('Other receipts need both an account and an amount.');
        }
        $lines[] = ['line_kind' => 'deposit', 'account_id' => $acc, 'amount' => $amt, 'customer_id' => ((int)($r['customer_id'] ?? 0)) ?: null,
            'description' => trim(($r['from'] ?? '') . ' ' . ($r['memo'] ?? '')) ?: null];
    }
    if (!$lines) {
        fail($dep ? 'A deposit needs at least one line. To cancel the whole deposit, void it instead.' : 'Select the payments to deposit or enter other receipts.');
    }
    $cashBack = r2(pnum('cash_back_amount'));
    if ($cashBack < 0) {
        fail('The cash back cannot be less than zero.');
    }
    $sum = r2(array_sum(array_column($lines, 'amount')));
    if ($cashBack > 0 && $cashBack > $sum + 0.004) {
        fail('The cash back (' . money($cashBack) . ') is more than the items deposited (' . money($sum) . ').');
    }
    $h = [
        'txn_date' => $date, 'account_id' => $bank, 'memo' => p('memo') ?: null, 'ref_no' => p('ref_no') ?: null,
        'cash_back_amount' => $cashBack, 'account2_id' => $cashBack > 0 ? pint('cash_back_account_id') : null, 'status' => 'open',
    ];
    $id = DB::tx(function () use ($h, $lines, $depId, $shiftIds) {
        $id = Txn::save('deposit', $h, $lines, $depId ?: null, ['reason' => trim((string)p('reason')) ?: null]);
        // the shifts taken off the deposit wait to be deposited again; the ones on it point at it
        if ($depId) {
            [$in, $ip] = DB::in('ks', $shiftIds ?: [0]);
            DB::run("UPDATE pos_shifts SET deposit_txn_id = NULL WHERE deposit_txn_id = :d AND id NOT IN $in", ['d' => $depId] + $ip);
        }
        foreach ($shiftIds as $sid) {
            DB::update('pos_shifts', ['deposit_txn_id' => $id], 'id = :id', ['id' => $sid]);
        }
        return $id;
    });
    flash('success', $dep ? 'Deposit ' . $dep['txn_no'] . ' saved.' : 'Deposit recorded.');
    if ($dep) {
        redirect(url($pageKey, ['a' => 'view', 'id' => $id]));
    }
    redirect_after_save(url($pageKey, ['a' => 'new']), url($pageKey, ['a' => 'view', 'id' => $id]));
});

// what is ticked: what was posted, else what the deposit holds
$posted = is_post();
$payOn = $posted ? array_map('strval', (array)($_POST['pay'] ?? [])) : array_map('strval', array_keys($inPay));
$shiftOn = $posted ? array_map('strval', (array)($_POST['shift'] ?? [])) : array_map('strval', array_keys($inShift));
$others = $posted ? array_values((array)($_POST['other'] ?? []))
    : array_map(fn($l) => ['from' => '', 'memo' => (string)$l['description'], 'account_id' => $l['account_id'], 'amount' => number_format((float)$l['amount'], 2, '.', ''),
        'customer_id' => $l['customer_id']], $otherLines);
$otherRows = max(3, count($others) + 1);
$rec = $dep ?? [];

$title = $dep ? 'Edit Deposit ' . $dep['txn_no'] : 'Make Deposits';
layout_start($title, ['actions' => $dep ? btn_link(Txn::url($dep), 'Back to the deposit', 'btn-outline-secondary', 'arrow-left')
    : btn_link(url($pageKey, ['a' => 'list']), 'Deposits list', 'btn-outline-secondary', 'list-ul')]);
?>
<form method="post" class="dep-form"><?= csrf_field() ?>
<?php if ($dep): ?>
<div class="alert alert-info py-2 small d-flex gap-2"><i class="bi bi-info-circle"></i><div>What this deposit holds is ticked. Untick a payment or a shift to take it off the deposit (it waits to be deposited again), tick another to add it, or change the other receipts. A POS shift is always taken at its figures as they are now.</div></div>
<?php endif; ?>
<div class="card mb-3"><div class="card-body row g-2">
  <?= select_field('account_id', 'Deposit to', Accts::banks(), fv('account_id', $dep, DB::value("SELECT id FROM accounts WHERE code = '1010'")), ['class' => 'col-md-4']) ?>
  <?= input_field('txn_date', 'Date', fv('txn_date', $dep, today()), ['type' => 'date', 'class' => 'col-6 col-md-2']) ?>
  <?= input_field('ref_no', 'Deposit slip no.', fv('ref_no', $dep), ['class' => 'col-6 col-md-2']) ?>
  <?= input_field('memo', 'Memo', fv('memo', $dep, 'Deposit'), ['class' => Classes::ready() ? 'col-md-2' : 'col-md-4']) ?>
  <?= Classes::field(fv('class_id', $dep), 'col-md-2') ?>
</div></div>
<div id="depSum" data-sum-into="#depTotal" data-sum-selector="input[data-sum]:not([disabled])">
<div class="card mb-3"><div class="card-header fw-semibold">Payments waiting to be deposited (Undeposited Funds)</div>
  <div class="table-responsive"><table class="table table-sm mb-0 dep-table"><thead><tr><th><input type="checkbox" class="form-check-input" data-check-all=".dep-cb-pay" aria-label="Tick every payment"></th><th>Date</th><th>Type</th><th>No.</th><th>Received from</th><th>Method</th><th>Ref.</th><th class="num">Amount</th></tr></thead>
  <tbody>
  <?php foreach ($pending as $t): $chk = in_array((string)$t['id'], $payOn, true); ?>
    <tr class="<?= isset($inPay[(int)$t['id']]) ? 'dep-in' : '' ?>"><td><input type="checkbox" class="form-check-input dep-cb dep-cb-pay" name="pay[]" value="<?= (int)$t['id'] ?>" <?= $chk ? 'checked' : '' ?> aria-label="Deposit <?= h(Txn::ref($t)) ?>"></td>
      <td><?= h(fdate($t['txn_date'])) ?></td><td><?= h(Txn::label($t['txn_type'])) ?></td><td><?= Txn::link($t) ?><?= isset($inPay[(int)$t['id']]) ? '<div class="small text-muted">On this deposit</div>' : '' ?></td><td><?= h($t['party']) ?></td><td><?= h($t['method']) ?></td><td><?= h($t['ref_no']) ?></td>
      <td class="num"><?= money($t['total']) ?><input type="hidden" data-sum value="<?= h($t['total']) ?>" <?= $chk ? '' : 'disabled' ?>></td></tr>
  <?php endforeach; ?>
  <?php if (!$pending): ?><tr><td colspan="8" class="text-muted text-center">No payments waiting.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<?php if ($shifts): ?>
<div class="card mb-3"><div class="card-header dep-head"><span class="fw-semibold">POS cash from closed shifts</span>
  <span class="small text-muted">To deposit = cash counted less the opening float (it stays in the drawer) plus the cash drops.</span></div>
  <div class="table-responsive"><table class="table table-sm mb-0 dep-table"><thead><tr><th></th><th>Business date</th><th>Terminal</th><th class="num">Counted</th><th class="num">Less float</th><th class="num">Plus drops</th><th class="num">To deposit</th></tr></thead>
  <tbody>
  <?php foreach ($shifts as $s): $chk = in_array((string)$s['id'], $shiftOn, true); $was = $inShift[(int)$s['id']] ?? null; ?>
    <tr class="<?= $was ? 'dep-in' : '' ?>"><td><input type="checkbox" class="form-check-input dep-cb" name="shift[]" value="<?= (int)$s['id'] ?>" <?= $chk ? 'checked' : '' ?> aria-label="Deposit the cash of shift <?= (int)$s['id'] ?>"></td>
      <td><a href="<?= h(url('pos.shifts', ['a' => 'shift', 'id' => $s['id']])) ?>"><?= h(fdate($s['business_date'])) ?></a></td><td><?= h($s['terminal']) ?>
        <?php if ($s['corrected_at']): ?><div class="small text-muted">Closing entries corrected <?= h(fdate($s['corrected_at'])) ?><?= $s['corrected_name'] ? ' by ' . h($s['corrected_name']) : '' ?></div><?php endif; ?></td>
      <td class="num"><?= money($s['counted_cash']) ?></td><td class="num"><?= money($s['opening_float']) ?></td><td class="num"><?= money($s['drops']) ?></td>
      <td class="num"><?= money($s['to_deposit']) ?>
        <?php if ($was && abs((float)$was['amount'] - $s['to_deposit']) > 0.004): ?><div class="small text-muted">was <?= money($was['amount']) ?></div><?php endif; ?>
        <input type="hidden" data-sum value="<?= h($s['to_deposit']) ?>" <?= $chk ? '' : 'disabled' ?>></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>
<?php if ($clearing): $clrUrl = url($pageKey, ['a' => 'clearing']); ?>
<div class="card mb-3" id="clrCard" data-clearing-url="<?= h($clrUrl) ?>"><div class="card-header dep-head"><span class="fw-semibold">Card and e-wallet settlements</span>
  <span class="small text-muted">Unsettled = the balance of the clearing account in the General Ledger on <span data-clr-date><?= h(fdate($clearDate)) ?></span>, as on the Trial Balance.</span></div>
  <div class="table-responsive"><table class="table table-sm mb-0 dep-table clr-table"><thead><tr><th>Clearing account</th><th class="num">Unsettled</th><th class="num">Gross settled</th><th class="num">Fee deducted</th><th>Reference</th></tr></thead>
  <?php foreach ($clearing as $c): $sp = $_POST['settle'][$c['id']] ?? []; ?>
    <tr data-clr-row="<?= (int)$c['id'] ?>"><td><?= h($c['code'] . ' - ' . $c['name']) ?><?php if ($c['methods']): ?><div class="small text-muted"><?= h(implode(', ', $c['methods'])) ?></div><?php endif; ?></td>
      <td class="num"><div class="clr-unsettled"><button type="button" class="btn btn-outline-primary btn-sm clr-details" data-clr-details="<?= (int)$c['id'] ?>" title="What the unsettled balance is made of"><i class="bi bi-list-ul"></i> Details</button>
        <span data-clr-bal class="<?= $c['bal'] < 0 ? 'text-danger' : '' ?>"><?= money($c['bal']) ?></span></div></td>
      <td><input class="form-control form-control-sm text-end ms-auto" style="width:120px" name="settle[<?= (int)$c['id'] ?>][gross]" value="<?= h($sp['gross'] ?? '') ?>" inputmode="decimal" data-sum aria-label="Gross settled"></td>
      <td><input class="form-control form-control-sm text-end ms-auto sub-fee" style="width:100px" name="settle[<?= (int)$c['id'] ?>][fee]" value="<?= h($sp['fee'] ?? '') ?>" inputmode="decimal" aria-label="Fee deducted"></td>
      <td><input class="form-control form-control-sm" name="settle[<?= (int)$c['id'] ?>][ref]" value="<?= h($sp['ref'] ?? '') ?>" aria-label="Reference"></td></tr>
  <?php endforeach; ?>
  </table></div>
  <div class="card-body small text-muted py-2">Merchant fees are recorded as Bank and Merchant Charges; the bank receives the gross less the fee. Details lists the sales, refunds and settlements behind each balance.<?= $dep ? ' Settlements already on this deposit are listed under Other receipts.' : '' ?></div>
</div>
<div class="modal fade" id="clrModal" tabindex="-1" aria-labelledby="clrModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-md-down"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title" id="clrModalTitle">Card and e-wallet balance details</h5>
      <a class="btn btn-outline-secondary btn-sm ms-auto me-2" id="clrModalOpen" href="#" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i><span class="d-none d-sm-inline"> Open as a page</span></a>
      <button type="button" class="btn-close ms-0" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body" id="clrModalBody"></div>
  </div></div>
</div>
<?php endif; ?>
<div class="card mb-3"><div class="card-header fw-semibold">Other receipts (loan proceeds, owner contributions, interest…)</div>
  <div class="table-responsive"><table class="table table-sm mb-0 dep-table dep-other"><thead><tr><th>Received from</th><th>From account</th><th>Memo</th><th class="num">Amount</th></tr></thead>
  <?php for ($i = 0; $i < $otherRows; $i++): $o = $others[$i] ?? []; ?>
    <tr><td><input class="form-control form-control-sm" name="other[<?= $i ?>][from]" value="<?= h($o['from'] ?? '') ?>" aria-label="Received from">
        <input type="hidden" name="other[<?= $i ?>][customer_id]" value="<?= h((string)($o['customer_id'] ?? '')) ?>"></td>
      <td><select class="form-select form-select-sm" name="other[<?= $i ?>][account_id]" aria-label="From account"><?= options(Accts::all(), $o['account_id'] ?? '', '') ?></select></td>
      <td><input class="form-control form-control-sm" name="other[<?= $i ?>][memo]" value="<?= h($o['memo'] ?? '') ?>" aria-label="Memo"></td>
      <td><input class="form-control form-control-sm text-end ms-auto" style="width:120px" name="other[<?= $i ?>][amount]" data-sum inputmode="decimal" value="<?= h($o['amount'] ?? '') ?>" aria-label="Amount"></td></tr>
  <?php endfor; ?>
  </table></div>
</div>
</div>
<div class="card mb-3"><div class="card-body row g-2 align-items-end">
  <?= select_field('cash_back_account_id', 'Cash back goes to', Accts::banks(), fv('cash_back_account_id', null, ($dep['account2_id'] ?? null) ?: DB::value("SELECT id FROM accounts WHERE code = '1005'")), ['class' => 'col-md-4']) ?>
  <?= input_field('cash_back_amount', 'Cash back amount', fv('cash_back_amount', null, $dep && (float)$dep['cash_back_amount'] != 0.0 ? number_format((float)$dep['cash_back_amount'], 2, '.', '') : ''), ['class' => 'col-md-2', 'extra' => 'id="cashBack" inputmode="decimal"']) ?>
  <div class="col-md-6 text-md-end dep-totals">Items selected: <strong id="depTotal">0.00</strong><span class="dep-totals-sep"></span>Deposit total: <strong class="fs-5" id="depNet">0.00</strong></div>
  <?php if ($dep): ?><?= input_field('reason', 'Reason for the change', fv('reason'), ['class' => 'col-12', 'extra' => 'maxlength="255" placeholder="Recorded in the audit trail"']) ?><?php endif; ?>
</div></div>
<?php if ($dep): ?>
<div class="d-flex flex-wrap gap-2"><button class="btn btn-primary btn-sm" name="save" value="1"><i class="bi bi-check-lg"></i> Save changes</button>
  <a class="btn btn-outline-secondary btn-sm" href="<?= h(Txn::url($dep)) ?>">Cancel</a></div>
<?php else: ?>
<?= save_buttons('Save deposit', url($pageKey), Access::can($pageKey, 'create')) ?>
<?php endif; ?>
</form>
<script>
(function () {
  // a ticked payment or shift counts in the total; an unticked one does not
  document.querySelectorAll('.dep-form .dep-cb').forEach(function (cb) {
    cb.addEventListener('change', function () {
      var h = cb.closest('tr').querySelector('input[data-sum]');
      if (h) { h.disabled = !cb.checked; }
      cb.closest('[data-sum-into]').dispatchEvent(new Event('change'));
    });
  });
  const upd = () => {
    let s = 0; document.querySelectorAll('#depSum input[data-sum]:not([disabled])').forEach(i => s += AppFmt.num(i.value));
    document.querySelectorAll('.sub-fee').forEach(i => s -= AppFmt.num(i.value));
    document.getElementById('depNet').textContent = AppFmt.fmt(s - AppFmt.num(document.getElementById('cashBack').value));
  };
  document.addEventListener('input', upd); document.addEventListener('change', upd); upd();

  // card and e-wallet settlements: the unsettled balances follow the deposit date; Details shows what they are made of
  const card = document.getElementById('clrCard');
  if (!card) { return; }
  const base = card.dataset.clearingUrl;
  const dateF = document.querySelector('.dep-form [name="txn_date"]');
  const asOf = () => (dateF && dateF.value) ? dateF.value : '';
  if (dateF) {
    dateF.addEventListener('change', function () {
      if (!dateF.value) { return; }
      fetch(base + '&format=json&as_of=' + encodeURIComponent(dateF.value), { headers: { 'Accept': 'application/json' } })
        .then((r) => r.json()).then((d) => {
          card.querySelectorAll('[data-clr-date]').forEach((el) => { el.textContent = d.date_label; });
          card.querySelectorAll('[data-clr-row]').forEach((tr) => {
            const a = d.accounts[tr.dataset.clrRow];
            const el = tr.querySelector('[data-clr-bal]');
            if (el) { el.textContent = a ? a.bal_label : AppFmt.fmt(0); el.classList.toggle('text-danger', !!a && a.bal < 0); }
          });
        }).catch(() => {});
    });
  }
  const modalEl = document.getElementById('clrModal');
  const body = document.getElementById('clrModalBody');
  const openLink = document.getElementById('clrModalOpen');
  card.addEventListener('click', function (e) {
    const b = e.target.closest('[data-clr-details]');
    if (!b) { return; }
    e.preventDefault();
    const u = base + '&account_id=' + encodeURIComponent(b.dataset.clrDetails) + '&as_of=' + encodeURIComponent(asOf());
    openLink.href = u;
    body.innerHTML = '<div class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm"></span> Loading...</div>';
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
    fetch(u + '&partial=1').then((r) => r.text()).then((html) => { body.innerHTML = html; })
      .catch(() => { body.innerHTML = '<div class="alert alert-danger">The details could not be loaded. Use Open as a page.</div>'; });
  });
})();
</script>
<?php
layout_end();

// ---------------------------------------------------------------------- card and e-wallet balance details

/**
 * bank.deposits&a=clearing: what the unsettled balance of a card / e-wallet clearing account is made of, as of a date.
 * &partial=1 gives the body only (the Details window of Make Deposits); &format=json the balances (the deposit date).
 */
function dep_clearing_details(string $pageKey): void
{
    $asOf = gdate('as_of', today());
    if (g('format') === 'json') {
        $out = [];
        foreach (Clearing::balances($asOf, true) as $id => $c) {
            $out[$id] = ['bal' => $c['bal'], 'bal_label' => money($c['bal'])];
        }
        header('Content-Type: application/json');
        echo json_encode(['date' => $asOf, 'date_label' => fdate($asOf), 'accounts' => $out]);
        return;
    }
    $accts = Clearing::balances($asOf, true);
    $aid = (int)g('account_id');
    if (!isset($accts[$aid])) {
        $aid = (int)array_key_first($accts);
    }
    $partial = g('partial') === '1';
    if (!$partial) {
        layout_start('Card and E-wallet Balance Details', ['actions' => btn_link(url($pageKey), 'Make Deposits', 'btn-outline-secondary', 'arrow-left')
            . ' <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>']);
        ?>
        <form class="row g-2 mb-3 filters no-print" method="get">
          <input type="hidden" name="p" value="<?= h($pageKey) ?>"><input type="hidden" name="a" value="clearing">
          <div class="col-12 col-sm-6 col-md-4"><label class="form-label">Clearing account</label>
            <select class="form-select form-select-sm" name="account_id" onchange="this.form.submit()"><?= options(array_map(fn($c) => $c['code'] . ' - ' . $c['name'], $accts), $aid ?: null) ?></select></div>
          <div class="col-6 col-sm-3 col-md-2"><label class="form-label">As of</label><input class="form-control form-control-sm" type="date" name="as_of" value="<?= h($asOf) ?>"></div>
          <div class="col-6 col-sm-3 col-md-2 d-flex align-items-end"><button class="btn btn-sm btn-secondary w-100">Show</button></div>
        </form>
        <?php
    }
    if (!$aid) {
        echo '<div class="alert alert-info">No card or e-wallet payment method has a clearing account.</div>';
        if (!$partial) {
            layout_end();
        }
        return;
    }
    $c = $accts[$aid];
    $d = Clearing::details($aid, $asOf);
    $gl = Report::link('rpt.general_ledger', ['account_id' => $aid, 'dates' => 'custom', 'from' => date('Y-m-01', strtotime($asOf)), 'to' => $asOf]);
    $tb = Report::link('rpt.trial_balance', ['asof' => $asOf]);
    $srcTot = ['docs' => 0, 'dr' => 0.0, 'cr' => 0.0];
    foreach ($d['sources'] as $s) {
        $srcTot['docs'] += $s['docs'];
        $srcTot['dr'] += $s['dr'];
        $srcTot['cr'] += $s['cr'];
    }
    $unsTot = array_sum(array_map(fn($r) => (float)$r['open'], $d['unsettled']));
    $docLabel = fn(array $r): string => Txn::label($r['txn_type']) . ' ' . ($r['receipt_no'] ?: ($r['txn_no'] ?: '#' . $r['id']));
    $docLink = function (array $r) use ($docLabel): string {
        $u = Report::txnLink(['txn_type' => $r['txn_type'], 'txn_id' => $r['id']]);
        return $u ? '<a href="' . h($u) . '" target="_blank" rel="noopener">' . h($docLabel($r)) . '</a>' : h($docLabel($r));
    };
    ?>
    <div class="clr-details">
      <div class="clr-summary">
        <div class="clr-summary-main"><div class="small text-muted"><?= h($c['code'] . ' - ' . $c['name']) ?></div>
          <div class="clr-figure <?= $d['balance'] < 0 ? 'text-danger' : '' ?>"><?= money($d['balance']) ?></div>
          <div class="small text-muted">Unsettled on <?= h(fdate($asOf)) ?>: the balance of the account in the General Ledger, the same as the Trial Balance for that date.</div></div>
        <div class="clr-summary-side small">
          <?php if ($c['methods']): ?><div><span class="text-muted">Payment methods:</span> <?= h(implode(', ', $c['methods'])) ?></div><?php endif; ?>
          <div><span class="text-muted">Received (debits):</span> <?= money($srcTot['dr']) ?></div>
          <div><span class="text-muted">Settled and refunded (credits):</span> <?= money($srcTot['cr']) ?></div>
          <div class="clr-links no-print"><?php if ($gl): ?><a href="<?= h($gl) ?>" target="_blank" rel="noopener">General Ledger</a><?php endif; ?><?php if ($tb): ?><a href="<?= h($tb) ?>" target="_blank" rel="noopener">Trial Balance</a><?php endif; ?></div>
        </div>
      </div>
      <?php if ($d['balance'] < -0.004): ?>
      <div class="alert alert-warning small py-2">More was settled than was received: the account has a credit balance. Check the latest settlements below for one entered twice or for the wrong account.</div>
      <?php endif; ?>

      <h6 class="clr-h">What the balance is made of</h6>
      <div class="table-responsive"><table class="table table-sm clr-tbl">
        <thead><tr><th>Source</th><th class="num">Documents</th><th class="num">Received (debit)</th><th class="num">Settled / refunded (credit)</th><th class="num">Net</th></tr></thead>
        <tbody>
        <?php foreach ($d['sources'] as $s): ?>
          <tr><td><?= h($s['label']) ?></td><td class="num"><?= (int)$s['docs'] ?></td><td class="num"><?= money($s['dr'], true) ?></td><td class="num"><?= money($s['cr'], true) ?></td><td class="num"><?= money($s['net']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$d['sources']): ?><tr><td colspan="5" class="text-muted text-center">Nothing posted to this account yet.</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr class="total-row"><td>Balance on <?= h(fdate($asOf)) ?></td><td class="num"><?= (int)$srcTot['docs'] ?></td><td class="num"><?= money($srcTot['dr']) ?></td><td class="num"><?= money($srcTot['cr']) ?></td><td class="num"><?= money($d['balance']) ?></td></tr></tfoot>
      </table></div>

      <?php if ($d['methods']): ?>
      <h6 class="clr-h">Card and e-wallet sales by payment method</h6>
      <div class="table-responsive"><table class="table table-sm clr-tbl">
        <thead><tr><th>Payment method</th><th class="num">Documents</th><th class="num">POS sales</th><th class="num">POS refunds</th><th class="num">Payments and sales receipts</th><th class="num">Net received</th></tr></thead>
        <tbody>
        <?php $mt = ['docs' => 0, 'pos' => 0.0, 'refunded' => 0.0, 'other' => 0.0, 'net' => 0.0]; foreach ($d['methods'] as $m): foreach ($mt as $k => $v) { $mt[$k] += $m[$k]; } ?>
          <tr><td><?= h($m['name']) ?></td><td class="num"><?= (int)$m['docs'] ?></td><td class="num"><?= money($m['pos'], true) ?></td><td class="num"><?= money($m['refunded'], true) ?></td><td class="num"><?= money($m['other'], true) ?></td><td class="num"><?= money($m['net']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <?php if (count($d['methods']) > 1): ?><tfoot><tr class="total-row"><td>Total</td><td class="num"><?= (int)$mt['docs'] ?></td><td class="num"><?= money($mt['pos']) ?></td><td class="num"><?= money($mt['refunded']) ?></td><td class="num"><?= money($mt['other']) ?></td><td class="num"><?= money($mt['net']) ?></td></tr></tfoot><?php endif; ?>
      </table></div>
      <?php endif; ?>

      <h6 class="clr-h">Receipts not yet settled</h6>
      <p class="small text-muted mb-1">Settlements are taken against the oldest receipts first, so these are the latest receipts that make up the unsettled balance. The oldest one may be partly settled.</p>
      <div class="table-responsive"><table class="table table-sm clr-tbl">
        <thead><tr><th>Date</th><th>Document</th><th>Customer / terminal</th><th>Method</th><th>Reference</th><th class="num">Amount</th><th class="num">Unsettled</th></tr></thead>
        <tbody>
        <?php foreach ($d['unsettled'] as $r): ?>
          <tr><td class="text-nowrap"><?= h(fdate($r['txn_date'])) ?></td><td class="text-nowrap"><?= $docLink($r) ?></td><td><?= h($r['who']) ?></td><td><?= h($r['method']) ?></td><td class="small"><?= h($r['reference']) ?></td>
            <td class="num"><?= money($r['amount']) ?></td><td class="num <?= abs((float)$r['open'] - (float)$r['amount']) >= 0.005 ? 'text-muted' : '' ?>"><?= money($r['open']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$d['unsettled']): ?><tr><td colspan="7" class="text-muted text-center">Nothing is waiting to be settled.</td></tr><?php endif; ?>
        </tbody>
        <?php if ($d['unsettled']): ?><tfoot><tr class="total-row"><td colspan="5"><?= count($d['unsettled']) ?> receipt(s)</td><td></td><td class="num"><?= money($unsTot) ?></td></tr></tfoot><?php endif; ?>
      </table></div>

      <h6 class="clr-h">Latest settlements and other credits</h6>
      <div class="table-responsive"><table class="table table-sm clr-tbl mb-0">
        <thead><tr><th>Date</th><th>Document</th><th>Description</th><th class="num">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($d['credits'] as $r): ?>
          <tr><td class="text-nowrap"><?= h(fdate($r['txn_date'])) ?></td><td class="text-nowrap"><?= $docLink($r) ?></td><td class="small"><?= h(trim((string)($r['descr'] ?: $r['memo']) . ($r['ref_no'] ? ' #' . $r['ref_no'] : ''))) ?></td><td class="num"><?= money($r['amount']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$d['credits']): ?><tr><td colspan="4" class="text-muted text-center">Nothing settled yet.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
      <?php if (count($d['credits']) >= 25): ?><p class="small text-muted mt-1 mb-0">The latest 25 are listed; the General Ledger has all of them.</p><?php endif; ?>
    </div>
    <?php
    if (!$partial) {
        layout_end();
    }
}
