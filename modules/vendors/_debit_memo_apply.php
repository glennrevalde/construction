<?php
declare(strict_types=1);

/**
 * Supplier Debit Memo > apply to outstanding bills (included by modules/txn/page.php, a=apply).
 * A debit memo is a credit with the supplier. Applying it to a bill lowers the open balance of the bill
 * without any new ledger entry: the memo itself already debited Accounts Payable when it was saved.
 */
Access::require($pageKey, 'edit');
$memo = Txn::load((int)g('id'));
if (!$memo || $memo['txn_type'] !== 'vendor_credit') {
    flash('warning', 'Supplier debit memo not found.');
    redirect(url($pageKey));
}
if ((int)$memo['is_voided'] === 1) {
    flash('warning', 'A voided debit memo cannot be applied.');
    redirect(Txn::url($memo));
}
$memoId = (int)$memo['id'];
$vendorId = (int)$memo['vendor_id'];
$vendorName = (string)DB::value('SELECT name FROM vendors WHERE id = :id', ['id' => $vendorId]);

handle_post(function () use ($memo, $memoId, $vendorId, $pageKey) {
    // take an application back
    if ($un = pint('unapply_id')) {
        $row = DB::row('SELECT pa.*, t.txn_no, t.ref_no FROM payment_applications pa JOIN transactions t ON t.id = pa.target_txn_id WHERE pa.id = :id AND pa.payment_txn_id = :m', ['id' => $un, 'm' => $memoId]);
        if (!$row) {
            fail('That application no longer exists.');
        }
        DB::tx(function () use ($row, $memo, $memoId, $pageKey) {
            DB::run('DELETE FROM payment_applications WHERE id = :id', ['id' => $row['id']]);
            Balances::refreshApplications($memoId, [(int)$row['target_txn_id']]);
            Audit::log('unapply_credit', 'vend', 'transaction', $memoId, Txn::ref($memo), ['amount' => (float)$row['amount'],
                'reason' => 'Removed from bill ' . ($row['ref_no'] ?: $row['txn_no']), 'page_key' => $pageKey]);
        });
        flash('success', 'The amount was taken back from bill ' . ($row['ref_no'] ?: $row['txn_no']) . ' and is available again.');
        redirect(url($pageKey, ['a' => 'apply', 'id' => $memoId]));
    }

    $remaining = Balances::creditRemaining($memo);
    $rows = [];
    $sum = 0.0;
    foreach ((array)($_POST['apply'] ?? []) as $bid => $amt) {
        $amt = r2(to_num($amt));
        if ($amt == 0.0) {
            continue;
        }
        if ($amt < 0) {
            fail('Amounts cannot be negative.');
        }
        $bill = DB::row("SELECT * FROM transactions WHERE id = :id AND txn_type = 'bill' AND is_voided = 0 AND vendor_id = :v", ['id' => (int)$bid, 'v' => $vendorId]);
        if (!$bill) {
            fail('A selected bill no longer exists or belongs to another supplier.');
        }
        $open = Balances::open($bill);
        if ($amt > $open + 0.004) {
            fail('The amount for bill ' . ($bill['ref_no'] ?: $bill['txn_no']) . ' is more than its open balance of ' . money($open) . '.');
        }
        $rows[] = [$bill, $amt];
        $sum += $amt;
    }
    if (!$rows) {
        fail('Enter the amount to apply on at least one bill.');
    }
    if ($sum > $remaining + 0.004) {
        fail('The total applied (' . money($sum) . ') is more than the available amount of this debit memo (' . money($remaining) . ').');
    }
    DB::tx(function () use ($rows, $memo, $memoId, $pageKey) {
        $targets = [];
        foreach ($rows as [$bill, $amt]) {
            DB::insert('payment_applications', ['payment_txn_id' => $memoId, 'target_txn_id' => $bill['id'], 'amount' => $amt]);
            $targets[] = (int)$bill['id'];
            Audit::log('apply_credit', 'vend', 'transaction', $memoId, Txn::ref($memo), ['amount' => $amt,
                'reason' => 'Applied to bill ' . ($bill['ref_no'] ?: $bill['txn_no']), 'page_key' => $pageKey]);
        }
        Balances::refreshApplications($memoId, $targets);
    });
    flash('success', money($sum) . ' applied to ' . count($rows) . ' bill(s).');
    redirect(url($pageKey, ['a' => 'apply', 'id' => $memoId]));
});

$remaining = Balances::creditRemaining($memo);
$bills = Balances::openPayables($vendorId);
$applied = DB::all('SELECT pa.id, pa.amount, t.id AS tid, t.txn_type, t.txn_no, t.ref_no, t.txn_date, t.total
                      FROM payment_applications pa JOIN transactions t ON t.id = pa.target_txn_id
                     WHERE pa.payment_txn_id = :m ORDER BY t.txn_date, t.id', ['m' => $memoId]);
$appliedTotal = array_sum(array_map(fn($r) => (float)$r['amount'], $applied));

layout_start('Apply ' . Txn::ref($memo), ['actions' => btn_link(Txn::url($memo), 'Back to the debit memo', 'btn-outline-secondary', 'arrow-left')
    . ' ' . page_link('vend.pay_bills', ['vendor_id' => $vendorId], 'Pay bills', 'btn-outline-secondary', 'cash-stack')]);
?>
<div class="row g-3 mb-3">
  <div class="col-md-3"><div class="card kpi h-100"><div class="card-body"><div class="label small text-muted">Supplier</div><div class="fw-semibold"><?= h($vendorName) ?></div><div class="small text-muted"><?= h(fdate($memo['txn_date'])) ?><?= $memo['ref_no'] ? ', ref. ' . h($memo['ref_no']) : '' ?></div></div></div></div>
  <div class="col-md-3"><div class="card kpi h-100"><div class="card-body"><div class="label small text-muted">Debit memo amount</div><div class="value"><?= money($memo['total']) ?></div></div></div></div>
  <div class="col-md-3"><div class="card kpi h-100"><div class="card-body"><div class="label small text-muted">Already applied</div><div class="value"><?= money($appliedTotal) ?></div></div></div></div>
  <div class="col-md-3"><div class="card kpi h-100"><div class="card-body"><div class="label small text-muted">Available to apply</div><div class="value text-success" id="dmAvail" data-v="<?= h(number_format($remaining, 2, '.', '')) ?>"><?= money($remaining) ?></div></div></div></div>
</div>

<form method="post" class="card mb-3" id="dmForm"><?= csrf_field() ?>
  <div class="card-header d-flex justify-content-between align-items-center"><span>Outstanding bills of <?= h($vendorName) ?></span>
    <?php if ($bills && $remaining > 0.004): ?><button type="button" class="btn btn-sm btn-outline-primary" id="dmAuto"><i class="bi bi-magic"></i> Apply to the oldest bills first</button><?php endif; ?></div>
  <div class="table-responsive"><table class="table table-sm table-hover mb-0" id="dmTable">
    <thead><tr><th>Bill</th><th>Supplier's invoice no.</th><th>Date</th><th>Due</th><th class="num">Bill amount</th><th class="num">Open balance</th><th class="num" style="width:170px">Amount to apply</th></tr></thead>
    <tbody>
    <?php foreach ($bills as $b): ?>
      <tr data-open="<?= h(number_format((float)$b['open_balance'], 2, '.', '')) ?>">
        <td><?= Txn::link($b) ?></td><td><?= h($b['ref_no']) ?></td><td><?= h(fdate($b['txn_date'])) ?></td>
        <td class="<?= $b['due_date'] && $b['due_date'] < today() ? 'text-danger' : '' ?>"><?= h(fdate($b['due_date'])) ?></td>
        <td class="num"><?= money($b['total']) ?></td><td class="num"><?= money($b['open_balance']) ?></td>
        <td class="num"><input class="form-control form-control-sm text-end dm-amt" name="apply[<?= (int)$b['id'] ?>]" value="<?= h($_POST['apply'][$b['id']] ?? '') ?>" placeholder="0.00" inputmode="decimal" <?= $remaining > 0.004 ? '' : 'disabled' ?>></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$bills): ?><tr><td colspan="7" class="text-muted py-3">This supplier has no outstanding bills. The available amount stays on the debit memo and can be applied later, here or in Pay Bills.</td></tr><?php endif; ?>
    </tbody>
    <?php if ($bills): ?>
    <tfoot><tr class="fw-semibold"><td colspan="5" class="text-end">Total to apply</td><td class="num text-muted small" id="dmLeft"></td><td class="num" id="dmSum">0.00</td></tr></tfoot>
    <?php endif; ?>
  </table></div>
  <?php if ($bills && $remaining > 0.004): ?>
  <div class="card-body border-top d-flex justify-content-between align-items-center">
    <div class="small text-danger" id="dmMsg"></div>
    <button class="btn btn-primary" id="dmSave"><i class="bi bi-check2"></i> Apply to the bills</button>
  </div>
  <?php endif; ?>
</form>

<div class="card"><div class="card-header">Bills this debit memo was applied to</div>
  <div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th>Bill</th><th>Supplier's invoice no.</th><th>Date</th><th class="num">Bill amount</th><th class="num">Applied</th><th></th></tr></thead>
    <?php foreach ($applied as $r): ?>
      <tr><td><?= Txn::link(['id' => $r['tid'], 'txn_type' => $r['txn_type'], 'txn_no' => $r['txn_no']]) ?></td><td><?= h($r['ref_no']) ?></td><td><?= h(fdate($r['txn_date'])) ?></td>
        <td class="num"><?= money($r['total']) ?></td><td class="num"><?= money($r['amount']) ?></td>
        <td class="text-end"><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="unapply_id" value="<?= (int)$r['id'] ?>">
          <button class="btn btn-link btn-sm text-danger p-0" data-confirm="Take this amount back from the bill? It becomes available on the debit memo again.">Take back</button></form></td></tr>
    <?php endforeach; ?>
    <?php if (!$applied): ?><tr><td colspan="6" class="text-muted py-3">Not applied to any bill yet.</td></tr><?php endif; ?>
  </table></div>
</div>
<script>
(function () {
  const { num, fmt, r2 } = window.AppFmt;
  const avail = num(document.getElementById('dmAvail').dataset.v);
  const inputs = Array.from(document.querySelectorAll('.dm-amt'));
  const sumEl = document.getElementById('dmSum'), leftEl = document.getElementById('dmLeft'), msg = document.getElementById('dmMsg'), save = document.getElementById('dmSave');
  function paint() {
    let s = 0, bad = '';
    inputs.forEach((i) => {
      const v = num(i.value), open = num(i.closest('tr').dataset.open);
      i.classList.toggle('is-invalid', v > open + 0.004 || v < 0);
      if (v > open + 0.004) bad = 'An amount is more than the open balance of its bill.';
      s += v;
    });
    s = r2(s);
    if (sumEl) sumEl.textContent = fmt(s);
    if (leftEl) leftEl.textContent = 'left on the memo ' + fmt(r2(avail - s));
    if (s > avail + 0.004) bad = 'The total is more than the available amount of the debit memo (' + fmt(avail) + ').';
    if (msg) msg.textContent = bad;
    if (save) save.disabled = bad !== '' || s <= 0;
  }
  inputs.forEach((i) => i.addEventListener('input', paint));
  const auto = document.getElementById('dmAuto');
  if (auto) auto.addEventListener('click', () => {
    let left = avail;
    inputs.forEach((i) => { const open = num(i.closest('tr').dataset.open); const use = Math.min(open, left); i.value = use > 0.004 ? use.toFixed(2) : ''; left = r2(left - use); });
    paint();
  });
  paint();
})();
</script>
<?php
layout_end();
