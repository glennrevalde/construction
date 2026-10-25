<?php
declare(strict_types=1);

require_once APP_ROOT . '/modules/txn/_common.php';

$pageKey = 'cust.payments';
if (txn_common_actions($pageKey, ['payment'])) {
    return;
}
$a = g('a', 'new');
if ($a === 'list') {
    render_txn_list($pageKey, ['payment'], 'Customer Payments', [
        'statuses' => ['open' => 'Has unapplied credit', 'closed' => 'Fully applied', 'void' => 'Void'],
        'new_label' => 'Receive payment',
    ]);
    return;
}

$edit = null;
if ($a === 'edit') {
    Access::require($pageKey, 'edit');
    $edit = Txn::load((int)g('id'));
    if (!$edit || $edit['txn_type'] !== 'payment' || (int)$edit['is_voided'] === 1) {
        flash('warning', 'Payment not found or void.');
        redirect(url($pageKey, ['a' => 'list']));
    }
    if ($edit['terminal_id']) {
        flash('info', 'Payments taken at the POS are changed from the POS module.');
        redirect(Txn::url($edit));
    }
} else {
    Access::require($pageKey, 'create');
}

$customerId = $edit ? (int)$edit['customer_id'] : (int)(g('customer_id') ?: p('customer_id') ?: 0);
$existingApps = $edit ? DB::pairs('SELECT target_txn_id, amount + discount + withholding FROM payment_applications WHERE payment_txn_id = :p', ['p' => $edit['id']]) : [];
$existingRows = $edit ? DB::all('SELECT * FROM payment_applications WHERE payment_txn_id = :p', ['p' => $edit['id']]) : [];

/** Open documents for the customer, including those this payment already pays */
$loadOpen = function () use ($customerId, $edit, $existingApps) {
    if (!$customerId) {
        return [];
    }
    $docs = Balances::openReceivables($customerId);
    $have = array_column($docs, 'id');
    foreach (array_keys($existingApps) as $tid) {
        if (!in_array((int)$tid, array_map('intval', $have), true)) {
            $d = DB::row('SELECT t.*, tm.discount_pct, tm.discount_days FROM transactions t LEFT JOIN terms tm ON tm.id = t.terms_id WHERE t.id = :id', ['id' => $tid]);
            if ($d) {
                $d['open_balance'] = Balances::open($d);
                $docs[] = $d;
            }
        }
    }
    foreach ($docs as &$d) {
        $d['available'] = r2($d['open_balance'] + (float)($existingApps[$d['id']] ?? 0));
    }
    return $docs;
};

handle_post(function () use ($edit, $customerId, $loadOpen, $pageKey, $existingApps) {
    if (!$customerId) {
        fail('Choose a customer.');
    }
    $date = pdate('txn_date');
    if (!$date) {
        fail('Enter the payment date.');
    }
    $total = r2(pnum('amount'));
    if ($total < 0) {
        fail('The amount received cannot be negative.');
    }
    $docs = [];
    foreach ($loadOpen() as $d) {
        $docs[(int)$d['id']] = $d;
    }
    $apps = [];
    $whtRows = [];              // invoice id => taxes the customer withheld, by type (BIR Reports)
    $sumAmt = 0.0;
    $sumDisc = 0.0;
    $sumWht = 0.0;
    $creditByDoc = [];          // invoice id => credit amount typed on the invoice line
    foreach ((array)($_POST['apply'] ?? []) as $tid => $r) {
        $tid = (int)$tid;
        $amt = r2(to_num($r['amount'] ?? 0));
        $disc = r2(to_num($r['discount'] ?? 0));
        $wht = r2(to_num($r['withholding'] ?? 0));
        if (isset($docs[$tid]) && !empty($r['wht']) && Withholding::ready()) {
            // the types ticked in the Withholding list: their amounts make the total
            [$wr, $wsum] = Withholding::parse((array)$r, 'sales', Withholding::netRatio($docs[$tid]), (string)$docs[$tid]['txn_no']);
            if ($wr) {
                $whtRows[$tid] = $wr;
                $wht = $wsum;
            }
        }
        if ($wht == 0.0 && !isset($whtRows[$tid]) && isset($r['withholding_old'])) {
            $wht = r2(to_num($r['withholding_old']));     // saved before the types were kept, and not changed
        }
        $cred = r2(to_num($r['credit'] ?? 0));
        if ($amt == 0.0 && $disc == 0.0 && $wht == 0.0 && $cred == 0.0) {
            continue;
        }
        if (!isset($docs[$tid])) {
            fail('One of the invoices is not open for this customer.');
        }
        if ($amt < 0 || $disc < 0 || $wht < 0 || $cred < 0) {
            fail('Payment, discount, withholding and credit amounts cannot be negative.');
        }
        if ($amt + $disc + $wht + $cred > $docs[$tid]['available'] + 0.004) {
            fail('The amount applied to ' . $docs[$tid]['txn_no'] . ' is more than its open balance (' . money($docs[$tid]['available']) . ').');
        }
        if ($amt != 0.0 || $disc != 0.0 || $wht != 0.0) {
            $apps[] = ['target_txn_id' => $tid, 'amount' => $amt, 'discount' => $disc, 'withholding' => $wht];
        }
        $docs[$tid]['after_payment'] = r2($docs[$tid]['available'] - $amt - $disc - $wht);
        if ($cred > 0) {
            $creditByDoc[$tid] = $cred;
        }
        $sumAmt += $amt;
        $sumDisc += $disc;
        $sumWht += $wht;
    }
    // the amount applied may never be more than the amount received
    if (r2($sumAmt) > $total + 0.004) {
        fail('You applied ' . money($sumAmt) . ' but received only ' . money($total) . '. Lower the payments on the invoices, or correct the amount received.');
    }
    $credits = [];
    $creditUse = 0.0;
    foreach ((array)($_POST['credit'] ?? []) as $cid => $amt) {
        $amt = r2(to_num($amt));
        if ($amt <= 0) {
            continue;
        }
        $c = Txn::load((int)$cid);
        if (!$c || !in_array($c['txn_type'], Balances::CUSTOMER_CREDITS, true)
            || ((int)$c['customer_id'] !== $customerId && (int)DB::value('SELECT COALESCE(parent_id, id) FROM customers WHERE id = :id', ['id' => $c['customer_id']]) !== $customerId)) {
            fail('A selected credit does not belong to this customer.');
        }
        if ($edit && (int)$c['id'] === (int)$edit['id']) {
            fail('A payment cannot be applied as a credit to itself.');
        }
        if ($amt > Balances::creditRemaining($c) + 0.004) {
            fail('Credit ' . $c['txn_no'] . ' has only ' . money(Balances::creditRemaining($c)) . ' available.');
        }
        $credits[(int)$cid] = [$c, $amt];
        $creditUse += $amt;
    }
    $creditOnDocs = r2(array_sum($creditByDoc));
    if ($creditOnDocs > 0 && !$credits) {
        // credits typed on the invoices only: use the available credits oldest first
        $need = $creditOnDocs;
        foreach (Balances::customerCredits($customerId) as $c) {
            if ($need <= 0.004) {
                break;
            }
            if ($edit && (int)$c['id'] === (int)$edit['id']) {
                continue;
            }
            $use = min($need, (float)$c['remaining']);
            $credits[(int)$c['id']] = [$c, r2($use)];
            $need = r2($need - $use);
        }
        if ($need > 0.004) {
            fail('You applied ' . money($creditOnDocs) . ' of credits to the invoices, but only ' . money($creditOnDocs - $need) . ' of credit is available.');
        }
        $creditUse = $creditOnDocs;
    } elseif ($creditOnDocs > 0 && abs($creditOnDocs - r2($creditUse)) > 0.01) {
        fail('The credits applied to the invoices (' . money($creditOnDocs) . ') must equal the credits being used (' . money($creditUse) . ').');
    }
    if ($total == 0.0 && !$credits && !$edit) {
        fail('Enter the amount received or choose credits to apply.');
    }
    if ($credits && !$creditByDoc) {
        // only the credits were chosen: they go to the ticked invoices first, then to the oldest open invoices
        $left = r2($creditUse);
        foreach ([true, false] as $tickedPass) {
            foreach ($docs as $tid => $d) {
                if ($left <= 0.004) {
                    break 2;
                }
                $ticked = isset($_POST['apply'][$tid]['selected']) || isset($d['after_payment']);
                if ($ticked !== $tickedPass || isset($creditByDoc[$tid])) {
                    continue;
                }
                $room = $d['after_payment'] ?? $d['available'];
                if ($room <= 0.004) {
                    continue;
                }
                $creditByDoc[$tid] = r2(min($room, $left));
                $left = r2($left - $creditByDoc[$tid]);
            }
        }
        if ($left > 0.004) {
            fail('The credits chosen (' . money($creditUse) . ') are more than the open invoices can take. Lower the credit amounts by ' . money($left) . '.');
        }
    }

    // the payment and the credits are saved together: all of it, or none of it
    DB::begin();
    $paymentId = null;
    if ($total > 0 || $edit) {
        $h = [
            'txn_date'          => $date,
            'customer_id'       => $customerId,
            'ref_no'            => p('ref_no') ?: null,
            'payment_method_id' => pint('payment_method_id'),
            'account_id'        => pint('account_id') ?: Accts::get('undeposited_funds'),
            'account2_id'       => Accts::get('ar'),
            'memo'              => p('memo') ?: null,
            'total'             => $total,
            'discount_amount'   => r2($sumDisc),
            'withholding_amount'=> r2($sumWht),
        ];
        if (Classes::ready()) {
            $h['class_id'] = Classes::posted();
        }
        $paymentId = Txn::save('payment', $h, [], $edit ? (int)$edit['id'] : null, ['keep_totals' => true, 'applications' => $apps, 'withholdings' => $whtRows]);
    }

    // apply the available credits to the invoices, the oldest credit first
    $appliedCredit = 0.0;
    if ($credits) {
        DB::tx(function () use (&$credits, $creditByDoc, $docs, $pageKey, &$appliedCredit) {
            foreach ($creditByDoc as $tid => $want) {
                foreach ($credits as $cid => &$pair) {
                    if ($want <= 0.004) {
                        break;
                    }
                    [$c, $avail] = $pair;
                    if ($avail <= 0.004) {
                        continue;
                    }
                    $use = r2(min($want, $avail));
                    DB::insert('payment_applications', ['payment_txn_id' => $cid, 'target_txn_id' => $tid, 'amount' => $use]);
                    Audit::log('apply_credit', 'cust', 'transaction', $cid, Txn::ref($c), [
                        'page_key' => $pageKey, 'amount' => $use, 'reason' => 'Applied to ' . $docs[$tid]['txn_no'],
                    ]);
                    $pair[1] = r2($avail - $use);
                    $want = r2($want - $use);
                    $appliedCredit += $use;
                }
                unset($pair);
            }
            foreach (array_keys($credits) as $cid) {
                Balances::refreshApplications((int)$cid);
            }
        });
    }
    DB::commit();

    $over = r2($total - $sumAmt);
    if ($paymentId && $over > 0.004) {
        flash('info', 'Overpayment of ' . money($over) . ' is kept as a credit for this customer.' . (p('refund_over') === '1' ? ' Record the refund below.' : ''));
        if (p('refund_over') === '1') {
            redirect(url('cust.credit_memos', ['a' => 'refund', 'id' => $paymentId]));
        }
    }
    flash('success', ($paymentId ? 'Payment saved.' : 'Credits applied.') . ($appliedCredit > 0 ? ' ' . money($appliedCredit) . ' of available credit was applied to the invoices.' : ''));
    redirect_after_save(url($pageKey), $paymentId ? url($pageKey, ['a' => 'view', 'id' => $paymentId]) : url('cust.center', ['id' => $customerId]));
});

$docs = $loadOpen();
$credits = $customerId ? Balances::customerCredits($customerId) : [];
if ($edit) {
    $credits = array_values(array_filter($credits, fn($c) => (int)$c['id'] !== (int)$edit['id']));
}
$appRows = [];
foreach ($existingRows as $er) {
    $appRows[(int)$er['target_txn_id']] = $er;
}
$payDate = fv('txn_date', $edit, today());
$cust = $customerId ? DB::row('SELECT * FROM customers WHERE id = :id', ['id' => $customerId]) : null;
$whtReady = Withholding::ready();
$whtTypes = $whtReady ? Withholding::forSide('sales') : [];
$whtSaved = $edit && $whtReady ? Withholding::byTarget((int)$edit['id']) : [];
$whtDefault = $whtReady ? Withholding::defaults($cust['wht_ids'] ?? '') : [];

layout_start($edit ? 'Edit payment ' . $edit['txn_no'] : 'Receive Payments', [
    'actions' => btn_link(url($pageKey, ['a' => 'list']), 'Payments list', 'btn-outline-secondary', 'list-ul'),
]);
$creditTotal = r2(array_sum(array_column($credits, 'remaining')));
// columns that would be empty for this customer are left out, so the table fits without scrolling
$showJob = (bool)array_filter($docs, fn($d) => (int)$d['customer_id'] !== $customerId);
$showEarly = (bool)array_filter($docs, fn($d) => Balances::earlyDiscount($d, $payDate) > 0);
$lead = 6 + ($showJob ? 1 : 0) + ($showEarly ? 1 : 0) + 2;   // columns before Credits / Payment
?>
<form method="post" id="payForm">
<?= csrf_field() ?>
<div class="card mb-3"><div class="card-body"><div class="row g-2">
  <div class="col-md-4"><label class="form-label">Received from</label>
    <select class="form-select form-select-sm" name="customer_id" <?= $edit ? 'disabled' : '' ?> onchange="location='<?= h(url($pageKey)) ?>&customer_id='+this.value">
      <?= options(Lookup::customers(false), $customerId, '— choose customer —') ?></select>
    <?php if ($edit): ?><input type="hidden" name="customer_id" value="<?= (int)$customerId ?>"><?php endif; ?>
  </div>
  <?= input_field('txn_date', 'Date', $payDate, ['type' => 'date', 'class' => 'col-md-2', 'required' => true]) ?>
  <?= input_field('amount', 'Amount received', fv('amount', $edit ? ['amount' => number_format((float)$edit['total'], 2, '.', '')] : null, ''), ['class' => 'col-md-2', 'extra' => 'id="payAmount" inputmode="decimal" autocomplete="off"']) ?>
  <?= select_field('payment_method_id', 'Payment method', Lookup::paymentMethods(), fv('payment_method_id', $edit, $cust['payment_method_id'] ?? ''), ['blank' => '', 'class' => 'col-md-2']) ?>
  <?= input_field('ref_no', 'Check / reference no.', fv('ref_no', $edit), ['class' => 'col-md-2']) ?>
  <?= select_field('account_id', 'Deposit to', Accts::depositTo(), fv('account_id', $edit, Accts::get('undeposited_funds')), ['class' => 'col-md-4']) ?>
  <?= Classes::field(fv('class_id', $edit), 'col-md-2') ?>
  <?= input_field('memo', 'Memo', fv('memo', $edit), ['class' => Classes::ready() ? 'col-md-6' : 'col-md-8']) ?>
</div>
<?php if ($cust): ?><div class="small text-muted mt-2">Customer balance: <strong><?= money(Balances::customerBalance($customerId)) ?></strong>
  <?php if ($creditTotal > 0): ?><span class="ms-3">Available credits: <strong><?= money($creditTotal) ?></strong></span><?php endif; ?></div><?php endif; ?>
</div></div>

<?php if ($customerId): ?>
<div class="card mb-3">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2"><span class="fw-semibold">Outstanding invoices and charges</span>
    <span class="d-flex gap-2">
      <?php if ($credits): ?><button type="button" class="btn btn-sm btn-outline-success" id="applyCredits"><i class="bi bi-check2-square"></i> Apply available credits</button><?php endif; ?>
      <button type="button" class="btn btn-sm btn-outline-primary" id="autoApply">Auto-apply amount</button></span></div>
  <div class="table-responsive" id="applyTable">
  <table class="table table-sm mb-0">
    <thead><tr><th><input type="checkbox" class="form-check-input" id="payAll" title="Select all"></th><th>Date</th><th>Type / No.</th><?php if ($showJob): ?><th>Job</th><?php endif; ?><th>Due</th><th class="num">Original</th><th class="num">Open</th><?php if ($showEarly): ?><th class="num">Disc. avail.</th><?php endif; ?><th class="num">Discount</th><th class="num">Withholding</th><?php if ($credits): ?><th class="num">Credits</th><?php endif; ?><th class="num">Payment</th></tr></thead>
    <tbody>
    <?php if (!$docs): ?><tr><td colspan="12" class="text-center text-muted">No open invoices.</td></tr><?php endif; ?>
    <?php foreach ($docs as $d):
        $tid = (int)$d['id'];
        $er = $appRows[$tid] ?? null;
        $post = $_POST['apply'][$tid] ?? null;
        $vAmt = $post['amount'] ?? ($er && (float)$er['amount'] ? number_format((float)$er['amount'], 2, '.', '') : '');
        $vDisc = $post['discount'] ?? ($er && (float)$er['discount'] ? number_format((float)$er['discount'], 2, '.', '') : '');
        $vWht = $post['withholding'] ?? ($er && (float)$er['withholding'] ? number_format((float)$er['withholding'], 2, '.', '') : '');
        $vCred = $post['credit'] ?? '';
        $ticked = $post ? isset($post['selected']) : (bool)$er;
        $early = Balances::earlyDiscount($d, $payDate);
        $overdue = $d['due_date'] && $d['due_date'] < today();
    ?>
      <tr data-doc="<?= $tid ?>" data-open="<?= h(number_format((float)$d['available'], 2, '.', '')) ?>">
        <td><input type="checkbox" class="form-check-input pay-cb" name="apply[<?= $tid ?>][selected]" value="1" <?= $ticked ? 'checked' : '' ?>></td>
        <td><?= h(fdate($d['txn_date'])) ?></td>
        <td><?= h(Txn::label($d['txn_type'])) ?> <?= Txn::link($d) ?></td>
        <?php if ($showJob): ?><td class="small"><?= (int)$d['customer_id'] !== $customerId ? h(DB::value('SELECT name FROM customers WHERE id = :id', ['id' => $d['customer_id']])) : '' ?></td><?php endif; ?>
        <td class="<?= $overdue ? 'text-danger' : '' ?>"><?= h(fdate($d['due_date'])) ?></td>
        <td class="num"><?= money($d['total']) ?></td>
        <td class="num fw-semibold"><?= money($d['available']) ?></td>
        <?php if ($showEarly): ?><td class="num small"><?= $early > 0 ? money($early) : '' ?></td><?php endif; ?>
        <td><input class="form-control form-control-sm text-end pay-in" style="width:92px" data-k="discount" name="apply[<?= $tid ?>][discount]" value="<?= h($vDisc) ?>" placeholder="<?= $early > 0 ? h(number_format($early, 2, '.', '')) : '' ?>"></td>
        <?php if ($whtReady && ($whtTypes || isset($whtSaved[$tid]))): ?>
        <td class="pay-wht"><?php
            // a payment saved before the types were kept shows its total as the base type's amount
            $saved = $whtSaved[$tid] ?? [];
            echo Withholding::picker('apply[' . $tid . ']', $whtTypes, Withholding::netRatio($d), $saved, $post ?? [], $whtDefault, (string)$d['txn_no']);
            if (!$saved && !$post && $er && (float)$er['withholding'] != 0.0): ?><div class="small text-muted text-end">Saved: <?= money($er['withholding']) ?></div><input type="hidden" name="apply[<?= $tid ?>][withholding_old]" value="<?= h($vWht) ?>"><?php endif; ?></td>
        <?php else: ?>
        <td><input class="form-control form-control-sm text-end pay-in" style="width:92px" data-k="withholding" name="apply[<?= $tid ?>][withholding]" value="<?= h($vWht) ?>"></td>
        <?php endif; ?>
        <?php if ($credits): ?><td><input class="form-control form-control-sm text-end pay-in" style="width:104px" data-k="credit" name="apply[<?= $tid ?>][credit]" value="<?= h($vCred) ?>"></td><?php endif; ?>
        <td><input class="form-control form-control-sm text-end pay-in" style="width:112px" data-k="amount" name="apply[<?= $tid ?>][amount]" value="<?= h($vAmt) ?>"></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr class="fw-semibold grid-totals"><td colspan="<?= $lead ?>" class="text-end">Totals applied</td><?php if ($credits): ?><td class="num" id="creditSum">0.00</td><?php endif; ?><td class="num" id="appliedSum">0.00</td></tr></tfoot>
  </table>
  </div>
  <div class="card-body small text-muted pt-2">Withholding: taxes withheld by the customer, ticked by type from the Withholding Tax Table (income tax to Creditable Withholding Tax, VAT withheld to Creditable VAT Withheld); ask the customer for BIR Form 2307. Discount: early-payment discount (posted to Sales Discounts).
    The payments applied can never be more than the amount received; tick invoices first and the amount received adds itself up.</div>
</div>

<?php if ($credits): ?>
<div class="card mb-3" id="creditsCard"><div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Available credits</span>
    <span class="small text-muted fw-normal">Unused payments and credit memos of this customer</span></div>
  <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Credit</th><th>Date</th><th>Memo</th><th class="num">Available</th><th class="num">Apply now</th></tr></thead>
  <?php foreach ($credits as $c): ?>
    <tr><td><?= h(Txn::label($c['txn_type'])) ?> <?= Txn::link($c) ?></td><td><?= h(fdate($c['txn_date'])) ?></td><td class="small"><?= h($c['memo']) ?></td><td class="num"><?= money($c['remaining']) ?></td>
      <td><input class="form-control form-control-sm text-end ms-auto credit-in" style="width:120px" data-avail="<?= h(number_format((float)$c['remaining'], 2, '.', '')) ?>" name="credit[<?= (int)$c['id'] ?>]" value="<?= h($_POST['credit'][$c['id']] ?? '') ?>"></td></tr>
  <?php endforeach; ?>
  <tfoot><tr class="fw-semibold grid-totals"><td colspan="3" class="text-end">Total</td><td class="num"><?= money($creditTotal) ?></td><td class="num" id="creditUse">0.00</td></tr></tfoot>
  </table></div>
  <div class="card-body small text-muted pt-2">Click <strong>Apply available credits</strong> to spread the credits over the open invoices, oldest first, or type an amount in the <strong>Credits</strong> column of an invoice.
    The two always agree: what is used here is what is applied above. Credits can be applied with or without receiving a payment.</div>
</div>
<?php endif; ?>

<div class="alert alert-danger py-2 small d-none" id="overMsg"></div>
<div class="d-flex flex-wrap gap-3 align-items-center sticky-actions">
  <?= save_buttons('Save payment', url($pageKey, ['a' => 'list']), Access::can($pageKey, 'create')) ?>
  <div class="form-check"><input class="form-check-input" type="checkbox" name="refund_over" value="1" id="refOver"><label class="form-check-label small" for="refOver">Refund any overpayment</label></div>
  <span class="small">Received <strong id="sumReceived">0.00</strong></span>
  <span class="small">Applied <strong id="sumApplied">0.00</strong></span>
  <span class="small">Unapplied <strong id="unapplied">0.00</strong></span>
  <?php if ($credits): ?><span class="small">Credits applied <strong id="sumCredits">0.00</strong></span><?php endif; ?>
</div>
<?php endif; ?>
</form>
<script>
(function () {
  const { num, r2, fmt } = AppFmt;
  const amt = document.getElementById('payAmount');
  const rows = Array.from(document.querySelectorAll('#applyTable tbody tr[data-doc]'));
  if (!amt || !rows.length) return;
  const creditIns = Array.from(document.querySelectorAll('.credit-in'));
  const msg = document.getElementById('overMsg');
  const saveBtns = Array.from(document.querySelectorAll('#payForm button[name="save"], #payForm button[name="save_new"]'));
  const f = (tr, k) => tr.querySelector('[data-k="' + k + '"]');
  const val = (tr, k) => { const i = f(tr, k); return i ? num(i.value) : 0; };
  const put = (inp, v) => { if (inp) inp.value = v > 0.004 ? r2(v).toFixed(2) : ''; };
  const cb = (tr) => tr.querySelector('.pay-cb');
  const open = (tr) => num(tr.dataset.open);
  // what is left of an invoice for one kind of amount, after everything else on its line
  const roomFor = (tr, k) => Math.max(0, r2(open(tr) - val(tr, 'discount') - val(tr, 'withholding') - (k === 'credit' ? val(tr, 'amount') : val(tr, 'credit'))));
  const applied = (except) => rows.reduce((s, tr) => s + (tr === except ? 0 : val(tr, 'amount')), 0);
  const creditsOnDocs = (except) => rows.reduce((s, tr) => s + (tr === except ? 0 : val(tr, 'credit')), 0);
  const creditAvail = () => creditIns.reduce((s, i) => s + num(i.dataset.avail), 0);

  // The amount received is "typed" once the user enters it (or when editing a saved payment).
  // Until then it simply follows the invoices that are ticked, so applied can never pass received.
  let typed = amt.value.trim() !== '';
  let note = '';

  function syncReceived() { if (!typed) { const s = r2(applied()); amt.value = s > 0 ? s.toFixed(2) : ''; } }

  /** spreads the credits used (bottom table) over the invoices: ticked ones first, then the oldest */
  function spreadCredits() {
    let left = r2(creditIns.reduce((s, i) => s + Math.min(num(i.value), num(i.dataset.avail)), 0));
    rows.forEach((tr) => put(f(tr, 'credit'), 0));
    [true, false].forEach((pass) => rows.forEach((tr) => {
      if (left <= 0.004 || cb(tr).checked !== pass || !f(tr, 'credit')) return;
      const use = Math.min(roomFor(tr, 'credit'), left);
      if (use > 0.004) { put(f(tr, 'credit'), use); cb(tr).checked = true; left = r2(left - use); }
    }));
    if (left > 0.004) {
      // more credit than the invoices can take: give the rest back, newest credit first
      creditIns.slice().reverse().forEach((i) => { const cut = Math.min(left, num(i.value)); put(i, num(i.value) - cut); left = r2(left - cut); });
      note = 'The open invoices cannot take more credit than that, so the rest of the credit stays available.';
    }
  }

  /** sets the credits used (bottom table) to what the invoices ask for, oldest credit first */
  function pullCredits() {
    let need = r2(creditsOnDocs());
    creditIns.forEach((i) => { const use = Math.min(need, num(i.dataset.avail)); put(i, use); need = r2(need - use); });
  }

  function paint() {
    const received = num(amt.value), app = r2(applied()), cr = r2(creditsOnDocs());
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = fmt(v); };
    set('appliedSum', app); set('creditSum', cr); set('sumReceived', received); set('sumApplied', app);
    set('unapplied', r2(received - app)); set('sumCredits', cr);
    set('creditUse', r2(creditIns.reduce((s, i) => s + num(i.value), 0)));
    const over = app > received + 0.004;
    document.getElementById('unapplied').classList.toggle('text-danger', over);
    rows.forEach((tr) => tr.classList.toggle('table-active', cb(tr).checked));
    saveBtns.forEach((b) => { b.disabled = over; });
    const text = over
      ? 'The payments applied (' + fmt(app) + ') are more than the amount received (' + fmt(received) + '). Lower the payments, or click "Auto-apply amount" to spread the amount received over the invoices.'
      : note;
    msg.textContent = text;
    msg.classList.toggle('d-none', text === '');
    msg.classList.toggle('alert-danger', over);
    msg.classList.toggle('alert-warning', !over);
    const all = document.getElementById('payAll');
    if (all) all.checked = rows.every((tr) => cb(tr).checked);
  }

  function tick(tr, on) {
    cb(tr).checked = on;
    if (!on) { put(f(tr, 'amount'), 0); put(f(tr, 'credit'), 0); pullCredits(); syncReceived(); return; }
    const room = roomFor(tr, 'amount');
    if (!typed) { put(f(tr, 'amount'), room); syncReceived(); return; }
    const left = Math.max(0, r2(num(amt.value) - applied(tr)));
    put(f(tr, 'amount'), Math.min(room, left));
    if (left < room - 0.004) {
      note = left > 0.004
        ? 'Only ' + fmt(left) + ' of the amount received was left, so ' + tr.querySelector('a, td:nth-child(3)').textContent.trim() + ' is paid in part.'
        : 'The amount received is already fully applied, so no payment was put on this invoice. It stays ticked for credits; to pay it, raise the amount received.';
    }
  }

  rows.forEach((tr) => {
    const pick = tr.querySelector('[data-wht]');
    // the Withholding list works out its base from the invoice's open balance, ticked or not
    if (pick) AppWht.source(pick, () => Math.max(0, r2(open(tr) - val(tr, 'discount'))));
    cb(tr).addEventListener('change', () => {
      note = '';
      if (pick) { if (cb(tr).checked) { AppWht.setGross(pick, Math.max(0, r2(open(tr) - val(tr, 'discount')))); AppWht.useDefaults(pick); } else { AppWht.clear(pick); } }
      tick(tr, cb(tr).checked); paint();
    });
    // the taxes withheld changed: the payment and credit must still fit in the open balance
    tr.addEventListener('change', (e) => {
      if (!e.target.classList.contains('wht-total')) return;
      note = '';
      if (AppWht.value(pick) > 0.004) cb(tr).checked = true;
      if (val(tr, 'amount') > roomFor(tr, 'amount') || !typed) { put(f(tr, 'amount'), roomFor(tr, 'amount')); syncReceived(); }
      if (val(tr, 'credit') > roomFor(tr, 'credit')) { put(f(tr, 'credit'), roomFor(tr, 'credit')); pullCredits(); }
      paint();
    });
    tr.querySelectorAll('.pay-in').forEach((inp) => inp.addEventListener('change', () => {
      note = '';
      const k = inp.dataset.k;
      if (num(inp.value) < 0) inp.value = '';
      if (k === 'amount') {
        let v = Math.min(num(inp.value), roomFor(tr, 'amount'));
        if (typed) {
          const left = Math.max(0, r2(num(amt.value) - applied(tr)));
          if (v > left + 0.004) { v = left; note = 'The payment was lowered to ' + fmt(left) + ': that is all that is left of the amount received.'; }
        }
        put(inp, v);
        if (v > 0.004) cb(tr).checked = true;
        syncReceived();
      } else if (k === 'credit') {
        const others = creditsOnDocs(tr);
        let v = Math.min(num(inp.value), roomFor(tr, 'credit'), Math.max(0, r2(creditAvail() - others)));
        if (v + 0.004 < num(inp.value)) note = 'The credit was lowered to ' + fmt(v) + ': it cannot be more than the open balance of the invoice or the credits available.';
        put(inp, v);
        if (v > 0.004) cb(tr).checked = true;
        pullCredits();
      } else {
        // discount / withholding changed: the payment and credit must still fit in the open balance
        put(inp, Math.min(num(inp.value), open(tr)));
        if (k === 'discount' && pick && cb(tr).checked) AppWht.setGross(pick, Math.max(0, r2(open(tr) - val(tr, 'discount'))));
        if (val(tr, 'amount') > roomFor(tr, 'amount')) { put(f(tr, 'amount'), roomFor(tr, 'amount')); syncReceived(); }
        if (val(tr, 'credit') > roomFor(tr, 'credit')) { put(f(tr, 'credit'), roomFor(tr, 'credit')); pullCredits(); }
      }
      paint();
    }));
  });

  amt.addEventListener('input', () => { typed = amt.value.trim() !== ''; note = ''; paint(); });
  amt.addEventListener('change', () => { typed = amt.value.trim() !== ''; if (!typed) syncReceived(); paint(); });

  creditIns.forEach((i) => i.addEventListener('change', () => {
    note = '';
    put(i, Math.min(Math.max(0, num(i.value)), num(i.dataset.avail)));
    spreadCredits();
    paint();
  }));

  const all = document.getElementById('payAll');
  if (all) all.addEventListener('change', () => { note = ''; rows.forEach((tr) => { if (cb(tr).checked !== all.checked) tick(tr, all.checked); }); paint(); });

  document.getElementById('autoApply')?.addEventListener('click', () => {
    note = '';
    if (!typed) { note = 'Type the amount received first; it is then spread over the invoices, oldest first.'; amt.focus(); paint(); return; }
    let left = num(amt.value);
    rows.forEach((tr) => {
      const use = Math.min(roomFor(tr, 'amount'), Math.max(left, 0));
      put(f(tr, 'amount'), use);
      cb(tr).checked = use > 0.004 || val(tr, 'credit') > 0.004;
      left = r2(left - use);
    });
    if (left > 0.004) note = fmt(left) + ' is left unapplied and will be kept as a credit for this customer.';
    paint();
  });

  document.getElementById('applyCredits')?.addEventListener('click', () => {
    note = '';
    // no amount typed yet: the ticked invoices were filled in full, so they give way to the credits first
    const refill = !typed ? rows.filter((tr) => val(tr, 'amount') > 0.004) : [];
    refill.forEach((tr) => put(f(tr, 'amount'), 0));
    creditIns.forEach((i) => put(i, num(i.dataset.avail)));
    spreadCredits();
    refill.forEach((tr) => put(f(tr, 'amount'), roomFor(tr, 'amount')));
    syncReceived();
    if (r2(creditsOnDocs()) <= 0.004) note = 'There is no open balance left to apply credits to.';
    paint();
    document.getElementById('creditsCard')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  });

  <?php if (g('credits') === '1' && !is_post()): ?>document.getElementById('applyCredits')?.click();<?php endif; ?>
  paint();
})();
</script>
<?php
layout_end();
