<?php
declare(strict_types=1);

/**
 * Edit Bill Payment (vend.pay_bills&a=edit&id=N), opened from the Edit button on a bill payment.
 *
 * What can be changed: the payment date, the check / payment number, the method, the account paid from, the reference,
 * the print queue mark, the memo and class, and which of the vendor's bills it pays and by how much (with the
 * early-payment discount and the taxes withheld on each). The vendor stays the same: a payment made to the wrong vendor
 * is voided and entered again. Vendor credits used on the bills are not part of the payment; they are applied from the
 * debit memo itself. An advance applied to bills is not edited here: its application is voided and applied again.
 *
 * The change is saved through Txn::save, so the closed-period and bank-reconciliation protections, the reposting of
 * the journal, the refresh of every bill it paid before and after, and the audit trail (before / after) all apply.
 * A new total above the user's amount limit needs a supervisor's approval, as when paying bills.
 *
 * Included by modules/vendors/pay_bills.php; $pageKey is set there.
 */

Access::require($pageKey, 'edit');
$pay = Txn::load((int)g('id'));
if (!$pay || $pay['txn_type'] !== 'bill_payment') {
    flash('warning', 'Bill payment not found.');
    redirect(url($pageKey, ['a' => 'list']));
}
if ((int)$pay['is_voided'] === 1) {
    flash('warning', 'A voided bill payment cannot be edited.');
    redirect(Txn::url($pay));
}
if (Advances::sourceOf($pay)) {
    flash('info', 'This payment applies an advance to bills. To change it, void it and apply the advance again in Pay Bills.');
    redirect(Txn::url($pay));
}
$payId = (int)$pay['id'];
$withAgent = Withholding::ready() && (Withholding::isAgent() || (float)$pay['withholding_amount'] != 0.0);
$whtTypes = $withAgent ? Withholding::forSide('purchases') : [];
$whtSaved = Withholding::byTarget($payId);
$vendorId = (int)$pay['vendor_id'];
$vendor = DB::row('SELECT id, name FROM vendors WHERE id = :id', ['id' => $vendorId]);

/** What this payment applies now: bill id => application row */
$current = [];
foreach (DB::all('SELECT * FROM payment_applications WHERE payment_txn_id = :p', ['p' => $payId]) as $r) {
    $tid = (int)$r['target_txn_id'];
    if (isset($current[$tid])) {
        $current[$tid]['amount'] += (float)$r['amount'];
        $current[$tid]['discount'] += (float)$r['discount'];
        $current[$tid]['withholding'] += (float)$r['withholding'];
    } else {
        $current[$tid] = ['amount' => (float)$r['amount'], 'discount' => (float)$r['discount'], 'withholding' => (float)$r['withholding']];
    }
}

/**
 * The vendor's bills this payment can pay: every open bill, and the bills it pays now (even when it pays them in full).
 * 'available' is what is left on the bill without this payment: the most the payment can put on it.
 */
$editBills = function () use ($vendorId, $current): array {
    $rows = [];
    foreach (Balances::openPayables($vendorId) as $b) {
        $rows[(int)$b['id']] = $b;
    }
    foreach (array_keys($current) as $tid) {
        if (!isset($rows[$tid])) {
            $b = DB::row("SELECT t.*, v.name AS vendor_name, tm.discount_pct, tm.discount_days FROM transactions t JOIN vendors v ON v.id = t.vendor_id
                           LEFT JOIN terms tm ON tm.id = t.terms_id WHERE t.id = :id AND t.txn_type = 'bill' AND t.is_voided = 0", ['id' => $tid]);
            if ($b) {
                $b['open_balance'] = Balances::open($b);
                $rows[$tid] = $b;
            }
        }
    }
    foreach ($rows as $tid => &$b) {
        $b['available'] = r2((float)$b['open_balance'] + ($current[$tid]['amount'] ?? 0) + ($current[$tid]['discount'] ?? 0) + ($current[$tid]['withholding'] ?? 0));
    }
    unset($b);
    uasort($rows, fn($a, $b) => [isset($current[(int)$b['id']]) ? 1 : 0, (string)($a['due_date'] ?: $a['txn_date']), (int)$a['id']]
        <=> [isset($current[(int)$a['id']]) ? 1 : 0, (string)($b['due_date'] ?: $b['txn_date']), (int)$b['id']]);
    return $rows;
};

handle_post(function () use ($pageKey, $pay, $payId, $vendorId, $editBills, $withAgent) {
    $date = pdate('txn_date');
    if (!$date) {
        fail('Enter the payment date.');
    }
    $bank = pint('account_id');
    if (!$bank || !DB::value("SELECT id FROM accounts WHERE id = :id AND account_type IN ('Bank','Credit Card')", ['id' => $bank])) {
        fail('Choose the bank or credit card account the bills were paid from.');
    }
    $methodId = pint('payment_method_id');
    $method = $methodId ? DB::value('SELECT method_type FROM payment_methods WHERE id = :id', ['id' => $methodId]) : 'check';
    $no = trim((string)p('txn_no'));
    if ($no === '') {
        fail('Enter the check or payment number.');
    }
    if ($no !== (string)$pay['txn_no'] && DB::value("SELECT id FROM transactions WHERE txn_type IN ('check','bill_payment') AND txn_no = :n AND id <> :id AND is_voided = 0 LIMIT 1",
            ['n' => $no, 'id' => $payId])) {
        fail('Check / payment number ' . $no . ' is already used by another check or bill payment.');
    }
    $bills = $editBills();
    $apps = [];
    $total = 0.0;
    $disc = 0.0;
    $whtSum = 0.0;
    $wrows = [];
    $paid = [];
    foreach ((array)($_POST['pay'] ?? []) as $bid => $r) {
        $bid = (int)$bid;
        if (empty($r['selected'])) {
            continue;
        }
        if (!isset($bills[$bid])) {
            fail('One of the ticked bills is not an open bill of this vendor.');
        }
        $b = $bills[$bid];
        $amt = r2(to_num($r['amount'] ?? 0));
        $d = r2(to_num($r['discount'] ?? 0));
        [$wr, $w] = $withAgent ? Withholding::parse((array)$r, 'purchases', Withholding::netRatio($b), $b['ref_no'] ?: $b['txn_no']) : [[], 0.0];
        if ($amt < 0 || $d < 0) {
            fail('Amounts cannot be negative.');
        }
        if ($amt + $d + $w > $b['available'] + 0.004) {
            fail('Payment for ' . ($b['ref_no'] ?: $b['txn_no']) . ' is more than what is left on it (' . money($b['available']) . ').');
        }
        if ($amt + $d + $w <= 0) {
            continue;
        }
        $apps[] = ['target_txn_id' => $bid, 'amount' => $amt, 'discount' => $d, 'withholding' => $w];
        if ($wr) {
            $wrows[$bid] = $wr;
        }
        $total += $amt;
        $disc += $d;
        $whtSum += $w;
        $paid[] = $b['ref_no'] ?: $b['txn_no'];
    }
    $total = r2($total);
    $disc = r2($disc);
    if (!$apps || $total <= 0) {
        fail('Tick at least one bill and enter the amount paid on it. To cancel the whole payment, void it instead.');
    }
    $approver = Approval::check($pageKey, Approval::exceeds(Access::limit('max_amount'), $total), 'bill payment above your amount limit (' . money($total) . ')');
    $h = [
        'txn_date'          => $date,
        'txn_no'            => $no,
        'vendor_id'         => $vendorId,
        'account_id'        => $bank,
        'account2_id'       => $pay['account2_id'] ?: Accts::get('ap'),
        'payment_method_id' => $methodId,
        'ref_no'            => p('ref_no') ?: null,
        'total'             => $total,
        'discount_amount'   => $disc,
        'withholding_amount'=> r2($whtSum),
        'to_be_printed'     => $method === 'check' && p('to_be_printed') === '1' ? 1 : 0,
        'memo'              => p('memo') ?: ('Payment of ' . implode(', ', $paid)),
        'approved_by'       => $approver['id'] ?? $pay['approved_by'],
    ];
    Txn::save('bill_payment', $h, [], $payId, ['keep_totals' => true, 'applications' => $apps, 'withholdings' => $wrows, 'approved_by' => $approver['username'] ?? null,
        'reason' => trim((string)p('reason')) ?: null]);
    flash('success', 'Bill payment ' . $no . ' saved.');
    if ((int)$pay['is_printed'] === 1 && (abs($total - (float)$pay['total']) > 0.004 || $no !== (string)$pay['txn_no'] || $date !== $pay['txn_date'])) {
        flash('info', 'This check was printed before the change. Print it again so the printed check matches.');
    }
    redirect(url($pageKey, ['a' => 'view', 'id' => $payId]));
});

$bills = $editBills();
$payDate = (string)fv('txn_date', $pay);
$accounts = Accts::ofType(['Bank', 'Credit Card']);
if (!isset($accounts[(int)$pay['account_id']])) {
    $accounts[(int)$pay['account_id']] = (string)DB::value("SELECT CONCAT(code, ' - ', name) FROM accounts WHERE id = :id", ['id' => $pay['account_id']]);
}
$posted = is_post() ? (array)($_POST['pay'] ?? []) : null;
$credits = Balances::vendorCredits($vendorId);
$creditLeft = array_sum(array_column($credits, 'remaining'));

layout_start('Edit Bill Payment ' . ($pay['txn_no'] ?: ''), ['actions' => btn_link(Txn::url($pay), 'Back to the payment', 'btn-outline-secondary', 'arrow-left')]);
?>
<form method="post" class="bp-edit">
<?= csrf_field() ?>
<div class="card mb-3"><div class="card-body">
  <div class="bp-edit-head">
    <div><span class="bp-edit-k">Vendor</span><strong><?= h($vendor['name'] ?? '') ?></strong></div>
    <div><span class="bp-edit-k">Paid now</span><strong><?= money($pay['total']) ?></strong></div>
    <?php if ((float)$pay['discount_amount'] != 0.0): ?><div><span class="bp-edit-k">Discounts taken</span><strong><?= money($pay['discount_amount']) ?></strong></div><?php endif; ?>
    <?php if ((float)$pay['withholding_amount'] != 0.0): ?><div><span class="bp-edit-k">Taxes withheld</span><strong><?= money($pay['withholding_amount']) ?></strong></div><?php endif; ?>
    <div><span class="bp-edit-k">Printed</span><strong><?= (int)$pay['is_printed'] === 1 ? 'Yes' : 'No' ?></strong></div>
  </div>
  <div class="row g-2">
    <?= input_field('txn_date', 'Payment date', $payDate, ['type' => 'date', 'class' => 'col-6 col-md-3 col-xl-2', 'required' => true]) ?>
    <?= input_field('txn_no', 'Check / payment no.', fv('txn_no', $pay), ['class' => 'col-6 col-md-3 col-xl-2', 'required' => true, 'extra' => 'maxlength="30"']) ?>
    <?= select_field('payment_method_id', 'Method', Lookup::paymentMethods(), fv('payment_method_id', $pay), ['class' => 'col-6 col-md-3 col-xl-2']) ?>
    <?= select_field('account_id', 'Payment account', $accounts, fv('account_id', $pay), ['class' => 'col-6 col-md-3 col-xl-3']) ?>
    <?= input_field('ref_no', 'Reference (transfer / card)', fv('ref_no', $pay), ['class' => 'col-md-4 col-xl-3']) ?>
    <?= input_field('memo', 'Memo', fv('memo', $pay), ['class' => 'col-md-8 col-xl-6']) ?>
    <?= Classes::field(fv('class_id', $pay), 'col-md-4 col-xl-3') ?>
    <?= check_field('to_be_printed', 'Queue the check for printing', fv('to_be_printed', $pay), 'col-md-4 col-xl-3') ?>
  </div>
</div></div>

<div class="card mb-3"><div class="card-header bp-edit-cardhead"><strong>Bills paid</strong>
  <span class="small text-muted">Tick a bill to pay it, untick it to take the payment off it, or change the amount.</span></div>
<div class="table-responsive" data-sum-into="#bpSum">
<table class="table table-sm table-tight mb-0 bp-edit-table">
  <thead><tr><th class="bp-c-tick"></th><th>Due</th><th>Bill / ref.</th><th class="num">Bill amount</th><th class="num" title="What is left on the bill without this payment">Left to pay</th>
    <th class="num">Disc. avail.</th><th class="num">Discount</th><?php if ($withAgent): ?><th class="num">Withholding</th><?php endif; ?><th class="num">Amount paid</th></tr></thead>
  <tbody>
  <?php if (!$bills): ?><tr><td colspan="<?= $withAgent ? 9 : 8 ?>" class="text-muted text-center py-3">This vendor has no open bills.</td></tr><?php endif; ?>
  <?php foreach ($bills as $bid => $b):
      $cur = $current[$bid] ?? null;
      $early = Balances::earlyDiscount($b, $payDate);
      $row = $posted !== null ? ($posted[$bid] ?? []) : null;
      $sel = $row !== null ? !empty($row['selected']) : $cur !== null;
      $amt = $row !== null ? ($row['amount'] ?? '') : ($cur ? number_format($cur['amount'], 2, '.', '') : '');
      $d = $row !== null ? ($row['discount'] ?? '') : ($cur && $cur['discount'] != 0.0 ? number_format($cur['discount'], 2, '.', '') : '');
  ?>
    <tr class="bp-row <?= $cur ? 'bp-on' : '' ?>" data-avail="<?= h(number_format((float)$b['available'], 2, '.', '')) ?>">
      <td class="bp-c-tick"><input type="checkbox" class="form-check-input bp-cb" name="pay[<?= $bid ?>][selected]" value="1"
          aria-label="Pay <?= h($b['ref_no'] ?: $b['txn_no']) ?>" <?= $sel ? 'checked' : '' ?>></td>
      <td class="text-nowrap <?= $b['due_date'] && $b['due_date'] < today() && (float)$b['open_balance'] > 0.004 ? 'text-danger fw-semibold' : '' ?>"><?= h(fdate($b['due_date'] ?: $b['txn_date'])) ?></td>
      <td><?= Txn::link($b, $b['ref_no'] ?: $b['txn_no']) ?><?php if ($cur): ?><div class="small text-muted">Paid by this payment</div><?php endif; ?></td>
      <td class="num"><?= money($b['total']) ?></td>
      <td class="num"><?= money($b['available']) ?></td>
      <td class="num small"><?= $early > 0 ? money($early) : '' ?></td>
      <td><input class="form-control form-control-sm text-end ms-auto bp-disc" style="width:100px" name="pay[<?= $bid ?>][discount]" value="<?= h($d) ?>" inputmode="decimal" aria-label="Discount on <?= h($b['ref_no'] ?: $b['txn_no']) ?>"></td>
      <?php if ($withAgent): ?><td class="pb-wht"><?= Withholding::picker('pay[' . $bid . ']', $whtTypes, Withholding::netRatio($b), $whtSaved[$bid] ?? [], $row ?? [], Withholding::defaults((string)DB::value('SELECT wht_ids FROM vendors WHERE id = :v', ['v' => $vendorId])), $b['ref_no'] ?: $b['txn_no']) ?></td><?php endif; ?>
      <td><input class="form-control form-control-sm text-end ms-auto bp-amt" style="width:120px" id="bpa<?= $bid ?>" data-sum name="pay[<?= $bid ?>][amount]" value="<?= h($amt) ?>" inputmode="decimal" aria-label="Amount paid on <?= h($b['ref_no'] ?: $b['txn_no']) ?>"></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot><tr class="fw-semibold"><td colspan="<?= $withAgent ? 8 : 7 ?>" class="text-end">Total paid</td><td class="num" id="bpSum">0.00</td></tr></tfoot>
</table>
</div></div>

<div class="card mb-3"><div class="card-body row g-2 align-items-end">
  <?= input_field('reason', 'Reason for the change', fv('reason'), ['class' => 'col-md-6', 'extra' => 'maxlength="255" placeholder="Recorded in the audit trail"']) ?>
  <div class="col-md-6 small text-muted">The payment is posted again with the new amounts: Accounts Payable is debited and the payment account credited for the new total,
    and every bill it paid before or pays now gets its balance worked out again.<?php if ($creditLeft > 0.004): ?>
    The vendor has <?= money($creditLeft) ?> of unused credit; credits are applied to bills from the debit memo itself.<?php endif; ?></div>
</div></div>
<?= Approval::fields() ?>
<div class="d-flex flex-wrap gap-2 mt-2">
  <button class="btn btn-primary" name="save" value="1"><i class="bi bi-check-lg"></i> Save changes</button>
  <a class="btn btn-outline-secondary" href="<?= h(Txn::url($pay)) ?>">Cancel</a>
</div>
</form>
<script>
(function () {
  const { num, r2 } = AppFmt;
  document.querySelectorAll('.bp-edit-table tr.bp-row').forEach((tr) => {
    const cb = tr.querySelector('.bp-cb'), disc = tr.querySelector('.bp-disc'), amt = tr.querySelector('.bp-amt'), pick = tr.querySelector('[data-wht]');
    const gross = () => Math.max(0, r2(num(tr.dataset.avail) - num(disc.value)));
    const fill = () => { const v = Math.max(0, r2(gross() - (pick ? AppWht.value(pick) : 0))); amt.value = v > 0.004 ? v.toFixed(2) : ''; amt.dispatchEvent(new Event('input', { bubbles: true })); };
    if (pick) AppWht.source(pick, gross);
    cb.addEventListener('change', () => {
      if (cb.checked) { if (pick) { AppWht.setGross(pick, gross()); AppWht.useDefaults(pick); } fill(); }
      else { amt.value = ''; if (pick) AppWht.clear(pick); amt.dispatchEvent(new Event('input', { bubbles: true })); }
    });
    disc.addEventListener('change', () => { if (cb.checked) { if (pick) AppWht.setGross(pick, gross()); fill(); } });
    tr.addEventListener('change', (e) => {
      if (!e.target.classList.contains('wht-total')) return;
      if (pick && AppWht.value(pick) > 0.004) cb.checked = true;
      if (cb.checked) fill();
    });
  });
})();
</script>
<?php
layout_end();
