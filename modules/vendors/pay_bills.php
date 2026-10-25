<?php
declare(strict_types=1);

require_once APP_ROOT . '/modules/txn/_common.php';

$pageKey = 'vend.pay_bills';
if (txn_common_actions($pageKey, ['bill_payment'])) {
    return;
}
if (g('a') === 'list') {
    render_txn_list($pageKey, ['bill_payment'], 'Bill Payments', ['no_new' => true, 'statuses' => ['to_print' => 'To be printed', 'void' => 'Void'],
        'actions' => btn_link(url($pageKey), 'Pay bills', 'btn-primary', 'cash')]);
    return;
}
if (g('a') === 'edit') {
    require __DIR__ . '/_bill_payment_edit.php';
    return;
}
Access::require($pageKey, 'create');

/*
 * Pay Bills: one payment per vendor for the bills ticked. Each bill line can take
 *   - an early-payment discount (credited to Purchase Discounts),
 *   - a supplier debit memo (Use credit), applied from the debit memo,
 *   - an advance paid to the supplier before (Use advance): applied out of Advances to Suppliers,
 *   - the taxes withheld (Withholding): one or more types of the Withholding Tax Table, each worked out from the
 *     bill's open amount net of VAT and owed to the BIR until remitted; BIR Form 2307 is printed from them,
 * and the amount paid is what is left: open balance - discount - credit - advance - withholding.
 */
$vendorId = gint('vendor_id');
$dueBy = g('show', 'all') === 'due' ? gdate('due_by', date('Y-m-d', strtotime('+7 days'))) : null;
$preselect = array_map('intval', (array)($_GET['bills'] ?? []));
$bills = Balances::openPayables($vendorId, $dueBy);
if ($preselect) {
    $bills = array_values(array_filter($bills, fn($b) => in_array((int)$b['id'], $preselect, true))) ?: $bills;
}
$payDate = (string)(p('txn_date') ?: today());
$withAgent = Withholding::ready() && Withholding::isAgent();
$whtTypes = $withAgent ? Withholding::forSide('purchases') : [];

handle_post(function () use ($pageKey, $withAgent) {
    $date = pdate('txn_date');
    if (!$date) {
        fail('Enter the payment date.');
    }
    $bank = pint('account_id');
    if (!$bank) {
        fail('Choose the account to pay from.');
    }
    $methodId = pint('payment_method_id');
    $method = $methodId ? DB::value('SELECT method_type FROM payment_methods WHERE id = :id', ['id' => $methodId]) : 'check';
    $byVendor = [];
    foreach ((array)($_POST['pay'] ?? []) as $bid => $r) {
        if (empty($r['selected'])) {
            continue;
        }
        $bill = DB::row("SELECT t.*, tm.discount_pct, tm.discount_days FROM transactions t LEFT JOIN terms tm ON tm.id = t.terms_id WHERE t.id = :id AND t.txn_type = 'bill' AND t.is_voided = 0", ['id' => (int)$bid]);
        if (!$bill) {
            fail('A selected bill no longer exists.');
        }
        $ref = $bill['ref_no'] ?: $bill['txn_no'];
        $open = Balances::open($bill);
        $amt = r2(to_num($r['amount'] ?? 0));
        $disc = r2(to_num($r['discount'] ?? 0));
        $credit = r2(to_num($r['credit'] ?? 0));
        $adv = r2(to_num($r['advance'] ?? 0));
        [$wrows, $wht] = $withAgent ? Withholding::parse((array)$r, 'purchases', Withholding::netRatio($bill), $ref) : [[], 0.0];
        if ($amt < 0 || $disc < 0 || $credit < 0 || $adv < 0) {
            fail('Amounts cannot be negative.');
        }
        if ($amt + $disc + $credit + $adv + $wht > $open + 0.004) {
            fail('Payment for ' . $ref . ' (paid ' . money($amt) . ($disc ? ', discount ' . money($disc) : '') . ($credit ? ', credit ' . money($credit) : '')
                . ($adv ? ', advance ' . money($adv) : '') . ($wht ? ', withheld ' . money($wht) : '') . ') is more than its open balance of ' . money($open) . '.');
        }
        if ($amt + $disc + $credit + $adv + $wht <= 0) {
            continue;
        }
        $byVendor[(int)$bill['vendor_id']][] = ['bill' => $bill, 'ref' => $ref, 'amount' => $amt, 'discount' => $disc, 'credit' => $credit, 'advance' => $adv,
            'withholding' => $wht, 'wrows' => $wrows];
    }
    if (!$byVendor) {
        fail('Select at least one bill and enter the amount to pay.');
    }
    $limit = Access::limit('max_amount');
    $maxPayment = 0.0;
    foreach ($byVendor as $rows) {
        $maxPayment = max($maxPayment, array_sum(array_column($rows, 'amount')));
    }
    $approver = Approval::check($pageKey, Approval::exceeds($limit, $maxPayment), 'bill payment above your amount limit (' . money($maxPayment) . ')');

    $made = [];
    DB::tx(function () use ($byVendor, $date, $bank, $methodId, $method, $approver, $pageKey, &$made) {
        foreach ($byVendor as $vid => $rows) {
            // vendor credits first
            $credits = Balances::vendorCredits($vid);
            $needCredit = array_sum(array_column($rows, 'credit'));
            if ($needCredit > array_sum(array_column($credits, 'remaining')) + 0.004) {
                fail('Not enough vendor credit available for ' . DB::value('SELECT name FROM vendors WHERE id = :id', ['id' => $vid]) . '.');
            }
            foreach ($rows as $r) {
                $left = $r['credit'];
                foreach ($credits as &$c) {
                    if ($left <= 0.004) {
                        break;
                    }
                    if ($c['remaining'] <= 0.004) {
                        continue;
                    }
                    $use = min($left, $c['remaining']);
                    DB::insert('payment_applications', ['payment_txn_id' => $c['id'], 'target_txn_id' => $r['bill']['id'], 'amount' => r2($use)]);
                    $c['remaining'] -= $use;
                    $left -= $use;
                    Audit::log('apply_credit', 'vend', 'transaction', (int)$c['id'], Txn::ref($c), ['amount' => $use, 'reason' => 'Applied to bill ' . $r['ref'], 'page_key' => $pageKey]);
                }
                unset($c);
            }
            foreach ($credits as $c) {
                Balances::refreshApplications((int)$c['id']);
            }

            // the discount and the taxes withheld of a bill go with its payment; on a bill settled out of an advance
            // alone they go with the advance applied to it
            $useAdv = [];
            $refs = [];
            foreach ($rows as $i => $r) {
                $refs[(int)$r['bill']['id']] = $r['ref'];
                if ($r['advance'] > 0.004) {
                    $carry = $r['amount'] <= 0.004;
                    $useAdv[(int)$r['bill']['id']] = ['amount' => $r['advance'], 'discount' => $carry ? $r['discount'] : 0.0,
                        'withholding' => $carry ? $r['withholding'] : 0.0, 'rows' => $carry ? $r['wrows'] : []];
                    if ($carry) {
                        $rows[$i]['discount'] = 0.0;
                        $rows[$i]['withholding'] = 0.0;
                        $rows[$i]['wrows'] = [];
                    }
                }
            }
            if ($useAdv) {
                $made = array_merge($made, Advances::apply($vid, $useAdv, $date, $refs, $approver, $pageKey));
            }

            $total = r2(array_sum(array_column($rows, 'amount')));
            $disc = r2(array_sum(array_column($rows, 'discount')));
            $wht = r2(array_sum(array_column($rows, 'withholding')));
            if ($total <= 0 && $disc <= 0 && $wht <= 0) {
                foreach ($rows as $r) {
                    Balances::refresh((int)$r['bill']['id']);
                }
                continue;
            }
            $paid = array_values(array_filter($rows, fn($r) => $r['amount'] + $r['discount'] + $r['withholding'] > 0.004));
            $apps = array_map(fn($r) => ['target_txn_id' => $r['bill']['id'], 'amount' => $r['amount'], 'discount' => $r['discount'], 'withholding' => $r['withholding']], $paid);
            $wrows = [];
            foreach ($paid as $r) {
                if ($r['wrows']) {
                    $wrows[(int)$r['bill']['id']] = $r['wrows'];
                }
            }
            $toPrint = $method === 'check' && p('to_be_printed') === '1';
            $id = Txn::save('bill_payment', [
                'txn_date' => $date,
                'vendor_id' => $vid,
                'account_id' => $bank,
                'account2_id' => Accts::get('ap'),
                'payment_method_id' => $methodId,
                'ref_no' => p('ref_no') ?: null,
                'total' => $total,
                'discount_amount' => $disc,
                'withholding_amount' => $wht,
                'to_be_printed' => $toPrint ? 1 : 0,
                'memo' => p('memo') ?: ('Payment of ' . implode(', ', array_map(fn($r) => $r['ref'], $paid))),
                'approved_by' => $approver['id'] ?? null,
            ], [], null, ['keep_totals' => true, 'applications' => $apps, 'withholdings' => $wrows, 'approved_by' => $approver['username'] ?? null]);
            $made[] = $id;
        }
    });
    flash('success', count($made) . ' bill payment(s) recorded.');
    if (count($made) === 1) {
        redirect(url($pageKey, ['a' => 'view', 'id' => $made[0]]));
    }
    redirect(url($pageKey, ['a' => 'list']));
});

$vendorCredits = [];
$vendorAdvances = Advances::byVendor();
foreach (array_unique(array_column($bills, 'vendor_id')) as $vid) {
    $vendorCredits[(int)$vid] = array_sum(array_column(Balances::vendorCredits((int)$vid), 'remaining'));
}
// columns that would be empty for the bills shown are left out, so the table fits the page
$showCredit = (bool)array_filter($vendorCredits, fn($v) => $v > 0.004);
$showAdv = (bool)array_filter(array_unique(array_column($bills, 'vendor_id')), fn($v) => ($vendorAdvances[(int)$v] ?? 0) > 0.004);
$vendorWht = $withAgent ? DB::pairs('SELECT id, wht_ids FROM vendors WHERE wht_ids IS NOT NULL AND wht_ids <> \'\'') : [];
$checkMethod = DB::value("SELECT id FROM payment_methods WHERE method_type = 'check' ORDER BY id LIMIT 1");
$cols = 7 + ($showCredit ? 1 : 0) + ($showAdv ? 1 : 0) + ($withAgent ? 1 : 0);

layout_start('Pay Bills', ['actions' => btn_link(url($pageKey, ['a' => 'list']), 'Bill payments', 'btn-outline-secondary', 'list-ul')
    . (Access::page('vend.advances') ? ' ' . btn_link(url('vend.advances', ['a' => 'list']), 'Advances to suppliers', 'btn-outline-secondary', 'wallet2') : '')
    . (Access::page('rpt.unpaid_bills') ? ' ' . btn_link(url('rpt.unpaid_bills'), 'Unpaid Bills Detail', 'btn-outline-secondary', 'bar-chart') : '')]);
?>
<form class="row g-2 mb-3 filters" method="get">
  <input type="hidden" name="p" value="<?= h($pageKey) ?>">
  <?= select_field('vendor_id', 'Vendor', Lookup::vendors(), $vendorId, ['blank' => 'All vendors', 'class' => 'col-md-3']) ?>
  <?= select_field('show', 'Show bills', ['all' => 'All open bills', 'due' => 'Due on or before'], g('show', 'all'), ['class' => 'col-md-2']) ?>
  <div class="col-md-2"><label class="form-label">Due by</label><input class="form-control form-control-sm" type="date" name="due_by" value="<?= h(g('due_by', date('Y-m-d', strtotime('+7 days')))) ?>"></div>
  <div class="col-md-1 d-flex align-items-end"><button class="btn btn-sm btn-secondary">Filter</button></div>
</form>
<form method="post" id="pbForm">
<?= csrf_field() ?>
<div class="card mb-3"><div class="table-responsive pb-wrap">
<table class="table table-sm table-tight mb-0 pb-table" id="pbTable">
  <thead><tr><th><input type="checkbox" class="form-check-input" id="pbAll" aria-label="Select every bill"></th><th>Due</th><th>Vendor</th><th>Bill / ref.</th><th class="num">Open</th>
    <th class="num" title="Early-payment discount taken (what is available is shown under the box)">Discount</th>
    <?php if ($showCredit): ?><th class="num" title="Supplier debit memos applied (what the vendor has is shown under the box)">Use credit</th><?php endif; ?>
    <?php if ($showAdv): ?><th class="num" title="Advances to the supplier applied (what is left is shown under the box)">Use advance</th><?php endif; ?>
    <?php if ($withAgent): ?><th class="num" title="Taxes withheld from the supplier: tick one or more types of the Withholding Tax Table">Withholding</th><?php endif; ?>
    <th class="num">Amount to pay</th></tr></thead>
  <tbody>
  <?php if (!$bills): ?><tr><td colspan="<?= $cols ?>" class="text-muted text-center">No open bills.</td></tr><?php endif; ?>
  <?php foreach ($bills as $b):
      $bid = (int)$b['id'];
      $vid = (int)$b['vendor_id'];
      $ref = $b['ref_no'] ?: $b['txn_no'];
      $early = Balances::earlyDiscount($b, $payDate);
      $post = $_POST['pay'][$bid] ?? [];
      $sel = !empty($post['selected']) || in_array($bid, $preselect, true);
      $cred = $vendorCredits[$vid] ?? 0;
      $adv = $vendorAdvances[$vid] ?? 0;
  ?>
    <tr class="pb-row" data-bill="<?= $bid ?>" data-vendor="<?= $vid ?>" data-open="<?= h(number_format((float)$b['open_balance'], 2, '.', '')) ?>" data-early="<?= h(number_format($early, 2, '.', '')) ?>">
      <td><input type="checkbox" class="form-check-input pb-cb" name="pay[<?= $bid ?>][selected]" value="1" aria-label="Pay <?= h($ref) ?>" <?= $sel ? 'checked' : '' ?>></td>
      <td class="text-nowrap <?= $b['due_date'] && $b['due_date'] < today() ? 'text-danger fw-semibold' : '' ?>"><?= h(fdate($b['due_date'])) ?></td>
      <td class="pb-vendor"><?= h($b['vendor_name']) ?></td>
      <td><?= Txn::link($b, $ref) ?></td>
      <td class="num"><?= money($b['open_balance']) ?></td>
      <td><input class="form-control form-control-sm text-end pb-in" data-k="discount" name="pay[<?= $bid ?>][discount]" value="<?= h($post['discount'] ?? ($sel && $early > 0 ? number_format($early, 2, '.', '') : '')) ?>" inputmode="decimal" aria-label="Discount on <?= h($ref) ?>">
        <?php if ($early > 0): ?><div class="pb-hint">avail. <?= money($early) ?></div><?php endif; ?></td>
      <?php if ($showCredit): ?>
      <td><input class="form-control form-control-sm text-end pb-in" data-k="credit" name="pay[<?= $bid ?>][credit]" value="<?= h($post['credit'] ?? '') ?>" inputmode="decimal" aria-label="Credit used on <?= h($ref) ?>" <?= $cred > 0 ? '' : 'disabled' ?>>
        <?php if ($cred > 0): ?><div class="pb-hint">of <?= money($cred) ?></div><?php endif; ?></td>
      <?php endif; ?>
      <?php if ($showAdv): ?>
      <td><input class="form-control form-control-sm text-end pb-in" data-k="advance" name="pay[<?= $bid ?>][advance]" value="<?= h($post['advance'] ?? '') ?>" inputmode="decimal" aria-label="Advance applied to <?= h($ref) ?>" data-avail="<?= h(number_format($adv, 2, '.', '')) ?>" <?= $adv > 0 ? '' : 'disabled' ?>>
        <?php if ($adv > 0): ?><div class="pb-hint">of <?= money($adv) ?></div><?php endif; ?></td>
      <?php endif; ?>
      <?php if ($withAgent): ?>
      <td class="pb-wht"><?= Withholding::picker('pay[' . $bid . ']', $whtTypes, Withholding::netRatio($b), [], $post, Withholding::defaults($vendorWht[$vid] ?? ''), $ref) ?></td>
      <?php endif; ?>
      <td><input class="form-control form-control-sm text-end pb-in pb-amt" data-k="amount" data-sum id="pa<?= $bid ?>" name="pay[<?= $bid ?>][amount]" value="<?= h($post['amount'] ?? ($sel ? number_format($b['open_balance'] - $early, 2, '.', '') : '')) ?>" inputmode="decimal" aria-label="Amount to pay on <?= h($ref) ?>"></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot><tr class="fw-semibold"><td colspan="<?= $cols - 1 - ($withAgent ? 1 : 0) ?>" class="text-end">Total to pay</td><?php if ($withAgent): ?><td class="num" id="pbWhtSum" title="Total withheld">0.00</td><?php endif; ?><td class="num" id="paySum">0.00</td></tr></tfoot>
</table>
</div></div>
<?php if ($bills): ?>
<div class="card mb-3"><div class="card-body row g-2">
  <?= input_field('txn_date', 'Payment date', fv('txn_date', null, today()), ['type' => 'date', 'class' => 'col-md-2']) ?>
  <?= select_field('payment_method_id', 'Method', Lookup::paymentMethods(), fv('payment_method_id', null, $checkMethod), ['class' => 'col-md-2']) ?>
  <?= select_field('account_id', 'Payment account', Accts::ofType(['Bank', 'Credit Card']), fv('account_id', null, DB::value("SELECT id FROM accounts WHERE code = '1010'")), ['class' => 'col-md-3']) ?>
  <?= input_field('ref_no', 'Reference (transfer / card)', fv('ref_no'), ['class' => 'col-md-2']) ?>
  <?= check_field('to_be_printed', 'Queue checks for printing', fv('to_be_printed', null, 0), 'col-md-3') ?>
  <?= input_field('memo', 'Memo', fv('memo'), ['class' => 'col-md-6']) ?>
  <?= Classes::field(fv('class_id'), 'col-md-3') ?>
</div></div>
<?= Approval::fields() ?>
<p class="small text-muted mt-2">One payment is created per vendor. Each payment debits Accounts Payable and credits the payment account; early-payment discounts are credited to Purchase Discounts.
  <?php if ($withAgent): ?>Taxes withheld are credited to the payable account of each tax (owed to the BIR until remitted) and printed on BIR Form 2307 for the supplier; the base is the open amount net of VAT.<?php endif; ?>
  <?php if ($showAdv): ?>An advance used is applied out of Advances to Suppliers.<?php endif; ?></p>
<button class="btn btn-primary"><i class="bi bi-cash"></i> Pay selected bills</button>
<?php endif; ?>
</form>
<script>
(function () {
  const { num, r2, fmt } = AppFmt;
  const rows = Array.from(document.querySelectorAll('#pbTable tr.pb-row'));
  if (!rows.length) return;
  const f = (tr, k) => tr.querySelector('[data-k="' + k + '"]');
  const val = (tr, k) => { const i = f(tr, k); return i && !i.disabled ? num(i.value) : 0; };
  const put = (i, v) => { if (i) i.value = v > 0.004 ? r2(v).toFixed(2) : ''; };
  const cb = (tr) => tr.querySelector('.pb-cb');
  const pick = (tr) => tr.querySelector('[data-wht]');
  const open = (tr) => num(tr.dataset.open);
  // what the line settles before the taxes: the open balance less the discount and the supplier's debit memo
  const gross = (tr) => Math.max(0, r2(open(tr) - val(tr, 'discount') - val(tr, 'credit')));
  const toPay = (tr) => Math.max(0, r2(gross(tr) - val(tr, 'advance') - (pick(tr) ? AppWht.value(pick(tr)) : 0)));

  function totals() {
    let s = 0, w = 0;
    rows.forEach((tr) => { s += val(tr, 'amount'); if (pick(tr)) w += AppWht.value(pick(tr)); tr.classList.toggle('table-active', cb(tr).checked); });
    document.getElementById('paySum').textContent = fmt(s);
    const ws = document.getElementById('pbWhtSum'); if (ws) ws.textContent = fmt(w);
    document.getElementById('pbAll').checked = rows.every((tr) => cb(tr).checked);
  }
  function refill(tr) {
    if (!cb(tr).checked) return;
    if (pick(tr)) AppWht.setGross(pick(tr), gross(tr));
    if (f(tr, 'amount').dataset.typed !== '1') put(f(tr, 'amount'), toPay(tr));
  }
  function tick(tr, on) {
    cb(tr).checked = on;
    if (on) {
      if (val(tr, 'discount') === 0 && num(tr.dataset.early) > 0) put(f(tr, 'discount'), num(tr.dataset.early));
      if (pick(tr)) { AppWht.setGross(pick(tr), gross(tr)); AppWht.useDefaults(pick(tr)); }
      delete f(tr, 'amount').dataset.typed;
      put(f(tr, 'amount'), toPay(tr));
    } else {
      ['amount', 'discount', 'credit', 'advance'].forEach((k) => put(f(tr, k), 0));
      delete f(tr, 'amount').dataset.typed;
      if (pick(tr)) AppWht.clear(pick(tr));
    }
  }
  rows.forEach((tr) => {
    cb(tr).addEventListener('change', () => { tick(tr, cb(tr).checked); totals(); });
    tr.querySelectorAll('.pb-in').forEach((i) => i.addEventListener('change', () => {
      if (num(i.value) < 0) i.value = '';
      const k = i.dataset.k;
      if (k === 'advance' && num(i.value) > num(i.dataset.avail)) put(i, num(i.dataset.avail));
      if (k === 'amount') { i.dataset.typed = '1'; put(i, num(i.value)); }
      if (num(i.value) > 0.004 && !cb(tr).checked) { cb(tr).checked = true; if (pick(tr)) AppWht.useDefaults(pick(tr)); }
      if (k !== 'amount') { delete f(tr, 'amount').dataset.typed; refill(tr); }
      totals();
    }));
    // the Withholding list works out its base from what this bill would settle, ticked or not
    if (pick(tr)) AppWht.source(pick(tr), () => cb(tr).checked ? gross(tr)
      : Math.max(0, r2(open(tr) - (val(tr, 'discount') || num(tr.dataset.early)) - val(tr, 'credit'))));
    // the taxes withheld changed: the amount to pay follows (a tax ticked on a bill not yet chosen chooses it)
    tr.addEventListener('change', (e) => {
      if (!e.target.classList.contains('wht-total')) return;
      if (AppWht.value(pick(tr)) > 0.004 && !cb(tr).checked) {
        cb(tr).checked = true;
        if (val(tr, 'discount') === 0 && num(tr.dataset.early) > 0) put(f(tr, 'discount'), num(tr.dataset.early));
      }
      if (!cb(tr).checked) { totals(); return; }
      delete f(tr, 'amount').dataset.typed;
      put(f(tr, 'amount'), toPay(tr));
      totals();
    });
  });
  document.getElementById('pbAll').addEventListener('change', (e) => { rows.forEach((tr) => { if (cb(tr).checked !== e.target.checked) tick(tr, e.target.checked); }); totals(); });
  // bills chosen before the page opened (from a bill's Pay Bill button) get their usual taxes and amounts
  rows.forEach((tr) => { if (cb(tr).checked && !<?= is_post() ? 'true' : 'false' ?>) tick(tr, true); });
  totals();
})();
</script>
<?php
layout_end();
