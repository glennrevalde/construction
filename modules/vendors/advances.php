<?php
declare(strict_types=1);

/**
 * Vendors > Advances to Suppliers: money paid to a supplier before the goods or services it pays for arrive.
 *
 * Recording an advance debits Advances to Suppliers (or the asset account chosen) and credits the bank or card it was
 * paid from. When the supplier's bill comes, the advance is applied to it in Pay Bills (Use advance): the application
 * debits Accounts Payable and credits Advances to Suppliers, and the taxes withheld on the bill go with it.
 * An advance can be edited while nothing, or less than its new amount, has been applied; it is voided only after its
 * applications are voided.
 */
require_once APP_ROOT . '/modules/txn/_common.php';

$pageKey = 'vend.advances';
if (txn_common_actions($pageKey, ['vendor_advance'])) {
    return;
}
$a = g('a', 'new');
if ($a === 'list') {
    render_txn_list($pageKey, ['vendor_advance'], 'Advances to Suppliers', [
        'statuses' => ['open' => 'Not fully applied', 'closed' => 'Fully applied', 'void' => 'Void'],
        'new_label' => 'New advance',
    ]);
    return;
}

$t = null;
if ($a === 'edit') {
    Access::require($pageKey, 'edit');
    $t = Txn::load(gint('id') ?? 0);
    if (!$t || $t['txn_type'] !== 'vendor_advance') {
        flash('warning', 'Advance not found.');
        redirect(url($pageKey, ['a' => 'list']));
    }
    if ((int)$t['is_voided'] === 1) {
        flash('warning', 'A voided advance cannot be edited.');
        redirect(Txn::url($t));
    }
} else {
    Access::require($pageKey, 'create');
}
$applied = $t ? Advances::applied((int)$t['id']) : 0.0;
$vendorId = $t ? (int)$t['vendor_id'] : (int)(p('vendor_id') ?: gint('vendor_id') ?: 0);
$advAccounts = Accts::ofType(['Other Current Asset', 'Other Asset']);
$payAccounts = Accts::ofType(['Bank', 'Credit Card']);

handle_post(function () use ($t, $pageKey, $applied, $advAccounts, $payAccounts) {
    $vendorId = $t ? (int)$t['vendor_id'] : pint('vendor_id');
    if (!$vendorId) {
        fail('Choose the supplier the advance was paid to.');
    }
    $amount = r2(pnum('amount'));
    if ($amount <= 0) {
        fail('Enter the amount paid in advance.');
    }
    if ($t && $amount < $applied - 0.004) {
        fail(money($applied) . ' of this advance has already been applied to bills, so it cannot be lowered below that. Void the applications first.');
    }
    $from = pint('account_id');
    if (!$from || !isset($payAccounts[$from])) {
        fail('Choose the bank or card account the advance was paid from.');
    }
    $to = pint('account2_id') ?: Accts::get('vendor_advances');
    if (!isset($advAccounts[$to])) {
        fail('The advance account must be an asset account (Advances to Suppliers).');
    }
    $approver = Approval::check($pageKey, Approval::exceeds(Access::limit('max_amount'), $amount), 'advance above your amount limit (' . money($amount) . ')');
    $h = [
        'txn_date' => pdate('txn_date'),
        'vendor_id' => $vendorId,
        'account_id' => $from,
        'account2_id' => $to,
        'payment_method_id' => pint('payment_method_id'),
        'ref_no' => p('ref_no') ?: null,
        'due_date' => pdate('due_date'),
        'memo' => p('memo') ?: null,
        'total' => $amount,
        'subtotal' => $amount,
        'status' => 'open',
        'approved_by' => $approver['id'] ?? ($t['approved_by'] ?? null),
    ];
    if (p('txn_no') !== '') {
        $h['txn_no'] = mb_substr((string)p('txn_no'), 0, 30);
    }
    if (Classes::ready()) {
        $h['class_id'] = Classes::posted();
    }
    $id = Txn::save('vendor_advance', $h, [], $t ? (int)$t['id'] : null, ['keep_totals' => true, 'reason' => trim((string)p('reason')) ?: null,
        'approved_by' => $approver['username'] ?? null]);
    Balances::refresh($id);
    flash('success', 'Advance ' . Txn::ref(Txn::load($id)) . ' saved. Apply it to the supplier\'s bills in Pay Bills when they come.');
    redirect_after_save(url($pageKey, ['a' => 'new']), url($pageKey, ['a' => 'view', 'id' => $id]));
});

$vendor = $vendorId ? DB::row('SELECT * FROM vendors WHERE id = :id', ['id' => $vendorId]) : null;
$openPos = $vendorId ? DB::all("SELECT txn_no, txn_date, total FROM transactions WHERE txn_type = 'purchase_order' AND vendor_id = :v AND status IN ('open','partial') AND is_voided = 0 ORDER BY txn_date", ['v' => $vendorId]) : [];
$left = $vendorId ? Advances::open($vendorId) : [];
$bank = (int)DB::value("SELECT id FROM accounts WHERE code = '1010'") ?: array_key_first($payAccounts);

layout_start($t ? 'Edit Advance ' . $t['txn_no'] : 'Advance Payment to Supplier', ['actions' => $t
    ? btn_link(Txn::url($t), 'Cancel', 'btn-outline-secondary')
    : btn_link(url($pageKey, ['a' => 'list']), 'Advances list', 'btn-outline-secondary', 'list-ul')
        . (Access::page('rpt.vendor_advances') ? ' ' . btn_link(url('rpt.vendor_advances'), 'Advances report', 'btn-outline-secondary', 'bar-chart') : '')]);
?>
<form method="post" class="adv-form">
<?= csrf_field() ?>
<div class="card mb-3"><div class="card-body">
  <div class="row g-2">
    <div class="col-md-5"><label class="form-label">Supplier</label>
      <select class="form-select form-select-sm" name="vendor_id" required <?= $t ? 'disabled' : '' ?> data-combo onchange="if(this.value){location='<?= h(url($pageKey, ['a' => 'new'])) ?>&vendor_id='+this.value}">
        <?= options(Lookup::vendors(), $vendorId ?: null, '— choose the supplier —') ?></select>
      <?php if ($t): ?><input type="hidden" name="vendor_id" value="<?= (int)$vendorId ?>"><?php endif; ?></div>
    <?= input_field('txn_date', 'Date paid', fv('txn_date', $t, today()), ['type' => 'date', 'class' => 'col-6 col-md-2', 'required' => true]) ?>
    <?= input_field('txn_no', 'Advance no.', fv('txn_no', $t, ''), ['class' => 'col-6 col-md-2', 'extra' => 'placeholder="' . h($t ? '' : Seq::peek('vendor_advance') . ' (auto)') . '" maxlength="30"']) ?>
    <?= input_field('amount', 'Amount paid in advance', fv('amount', $t ? ['amount' => number_format((float)$t['total'], 2, '.', '')] : null, ''), ['class' => 'col-md-3', 'required' => true, 'extra' => 'inputmode="decimal" autocomplete="off"']) ?>
    <?= select_field('account_id', 'Paid from', $payAccounts, fv('account_id', $t, $bank), ['class' => 'col-md-4', 'required' => true]) ?>
    <?= select_field('payment_method_id', 'Method', Lookup::paymentMethods(), fv('payment_method_id', $t, DB::value("SELECT id FROM payment_methods WHERE method_type = 'check' ORDER BY id LIMIT 1")), ['class' => 'col-6 col-md-2', 'blank' => '']) ?>
    <?= input_field('ref_no', 'Check / reference no.', fv('ref_no', $t), ['class' => 'col-6 col-md-2']) ?>
    <?= select_field('account2_id', 'Advance account', $advAccounts, fv('account2_id', $t, Accts::get('vendor_advances')), ['class' => 'col-md-4']) ?>
    <?= input_field('due_date', 'Goods / services expected', fv('due_date', $t), ['type' => 'date', 'class' => 'col-6 col-md-3']) ?>
    <?= Classes::field(fv('class_id', $t), 'col-6 col-md-3') ?>
    <?= input_field('memo', 'What it pays for', fv('memo', $t), ['class' => 'col-md-6', 'extra' => 'placeholder="e.g. 30% down payment on PO-00012, delivery of 200 units"']) ?>
    <?php if ($t): ?><?= input_field('reason', 'Reason for the change', fv('reason'), ['class' => 'col-md-6', 'extra' => 'maxlength="255" placeholder="Recorded in the audit trail"']) ?><?php endif; ?>
  </div>
  <?php if ($vendor): ?>
  <div class="edit-facts mt-3">
    <div><span>Owed to the supplier on bills</span><strong><?= money(Balances::vendorBalance($vendorId)) ?></strong></div>
    <div><span>Advances not yet applied</span><strong><?= money(array_sum(array_column($left, 'remaining'))) ?></strong></div>
    <?php if ($t): ?><div><span>Applied from this advance</span><strong><?= money($applied) ?></strong></div><?php endif; ?>
    <?php if ($openPos): ?><div><span>Open purchase orders</span><strong><?= h(implode(', ', array_map(fn($p) => $p['txn_no'] . ' (' . money($p['total']) . ')', array_slice($openPos, 0, 4)))) ?></strong></div><?php endif; ?>
  </div>
  <?php endif; ?>
</div></div>
<?= Approval::fields() ?>
<p class="small text-muted mt-2">Posting debits the advance account (an asset: the supplier owes the goods or services) and credits the account it was paid from.
  When the supplier's bill is entered, open <strong>Pay Bills</strong> and type the amount in <strong>Use advance</strong> on the bill: Accounts Payable is debited and the advance account credited.
  The taxes withheld on the bill (1% / 2% / 5%) are deducted when the advance is applied to it.</p>
<?php if ($t): ?>
  <div class="d-flex flex-wrap gap-2"><button class="btn btn-primary btn-sm" name="save" value="1"><i class="bi bi-check2"></i> Save changes</button><?= btn_link(Txn::url($t), 'Cancel', 'btn-outline-secondary') ?></div>
<?php else: ?>
  <?= save_buttons('Record advance', url($pageKey, ['a' => 'list']), Access::can($pageKey, 'create')) ?>
<?php endif; ?>
</form>
<?php
layout_end();
