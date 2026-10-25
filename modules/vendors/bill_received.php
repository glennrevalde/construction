<?php
declare(strict_types=1);

/** Enter Bill for Received Items: converts an item receipt into a bill. */
require_once APP_ROOT . '/modules/txn/_common.php';
require_once APP_ROOT . '/modules/txn/_grids.php';

$pageKey = 'vend.bill_received';
Access::require($pageKey, 'create');

$receipt = gint('id') ? Txn::load((int)g('id')) : null;
if ($receipt && ($receipt['txn_type'] !== 'item_receipt' || (int)$receipt['is_voided'] === 1)) {
    $receipt = null;
}
$vendorId = $receipt ? (int)$receipt['vendor_id'] : gint('vendor_id');
$receipts = $vendorId ? DB::all("SELECT * FROM transactions WHERE txn_type = 'item_receipt' AND is_voided = 0 AND vendor_id = :v ORDER BY txn_date", ['v' => $vendorId]) : [];

handle_post(function () use ($receipt, $pageKey) {
    if (!$receipt) {
        fail('Choose the item receipt.');
    }
    $existing = [];
    foreach (Txn::lines((int)$receipt['id']) as $l) {
        $existing[(int)$l['id']] = $l;
    }
    $rows = grid_rows('lines');
    foreach ($rows as &$r) {
        // quantities stay as received; only cost and tax may change
        $lid = (int)($r['id'] ?? 0);
        if (isset($existing[$lid])) {
            $r['qty'] = $existing[$lid]['qty'];
            $r['item_id'] = $existing[$lid]['item_id'];
            $r['source_line_id'] = $existing[$lid]['source_line_id'];
            $r['site_id'] = $existing[$lid]['site_id'];
        }
    }
    unset($r);
    $lines = parse_item_lines($rows, 'purchase');
    $expenses = parse_expense_lines(grid_rows('expenses'));
    $date = pdate('txn_date', today());
    $termsId = pint('terms_id');
    $h = [
        'txn_date' => $date,
        'txn_no' => Seq::next('bill'),
        'vendor_id' => $receipt['vendor_id'],
        'ref_no' => p('ref_no') ?: null,
        'terms_id' => $termsId,
        'due_date' => pdate('due_date') ?: Txn::dueDate($date, $termsId),
        'memo' => p('memo') ?: ('Bill for ' . Txn::ref($receipt)),
        'account_id' => Accts::get('ap'),
    ];
    $id = Txn::save('bill', $h, array_merge($expenses, $lines), (int)$receipt['id'], [
        'audit_action' => 'convert',
        'reason' => 'Item receipt ' . $receipt['txn_no'] . ' converted to a bill',
    ]);
    flash('success', 'Bill entered. Inventory value and the payable were adjusted to the billed cost.');
    redirect_after_save(url($pageKey), url('vend.bills', ['a' => 'view', 'id' => $id]));
});

$lines = $receipt ? Txn::lines((int)$receipt['id']) : [];
if ($receipt && !is_post()) {
    $tax = DB::value("SELECT id FROM tax_codes WHERE code = 'VAT12'");
    foreach ($lines as &$l) {
        $l['tax_code_id'] = $l['tax_code_id'] ?: $tax;
        $l['tax_amount'] = r2((float)$l['amount'] * tax_rate($l['tax_code_id'] ? (int)$l['tax_code_id'] : null) / 100);
    }
    unset($l);
}
$vendor = $vendorId ? DB::row('SELECT * FROM vendors WHERE id = :id', ['id' => $vendorId]) : null;

layout_start('Enter Bill for Received Items');
?>
<div class="card mb-3"><div class="card-body">
  <form method="get" class="row g-2 filters"><input type="hidden" name="p" value="<?= h($pageKey) ?>">
    <?= select_field('vendor_id', 'Vendor', Lookup::vendors(), $vendorId, ['blank' => '— choose —', 'class' => 'col-md-4', 'extra' => 'data-autosubmit']) ?>
    <?php if ($receipts): ?>
      <?= select_field('id', 'Item receipt', array_combine(array_column($receipts, 'id'), array_map(fn($r) => $r['txn_no'] . ', ' . fdate($r['txn_date']) . ', ' . money($r['total']) . ($r['ref_no'] ? ', ' . $r['ref_no'] : ''), $receipts)), $receipt['id'] ?? '', ['blank' => '— choose —', 'class' => 'col-md-5', 'extra' => 'data-autosubmit']) ?>
    <?php elseif ($vendorId): ?><div class="col-md-5 d-flex align-items-end small text-muted">No open item receipts for this vendor.</div><?php endif; ?>
  </form>
</div></div>
<?php if ($receipt): ?>
<form method="post">
<?= csrf_field() ?>
<div class="card mb-3"><div class="card-body row g-2">
  <div class="col-md-3 small">Received <?= h(fdate($receipt['txn_date'])) ?> at cost <?= money($receipt['total']) ?></div>
  <?= input_field('txn_date', 'Bill date', fv('txn_date', null, today()), ['type' => 'date', 'class' => 'col-md-2']) ?>
  <?= input_field('ref_no', "Vendor's invoice no.", fv('ref_no', null, ''), ['class' => 'col-md-2', 'required' => true]) ?>
  <?= select_field('terms_id', 'Terms', Lookup::terms(), fv('terms_id', null, $vendor['terms_id'] ?? ''), ['blank' => '', 'class' => 'col-md-2']) ?>
  <?= input_field('due_date', 'Due date (blank = from terms)', fv('due_date'), ['type' => 'date', 'class' => 'col-md-2']) ?>
  <?= Classes::field(fv('class_id', null, $receipt['class_id'] ?? ''), 'col-md-2') ?>
  <?= input_field('memo', 'Memo', fv('memo'), ['class' => 'col-md-6']) ?>
</div></div>
<ul class="nav nav-tabs"><li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#bi">Items (update billed costs)</button></li>
  <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#be">Other charges (freight, etc.)</button></li></ul>
<div class="tab-content card card-body mb-3 border-top-0">
  <div class="tab-pane fade show active" id="bi"><?= item_grid(is_post() ? grid_rows('lines') : $lines, ['mode' => 'purchase', 'show_site' => false, 'show_job' => true, 'min_rows' => 0]) ?>
    <p class="small text-muted mb-0">Quantities are fixed to what was received. Change the cost if the vendor billed a different price; the inventory value is adjusted.</p></div>
  <div class="tab-pane fade" id="be"><?= expense_grid(is_post() ? grid_rows('expenses') : [], ['min_rows' => 1]) ?></div>
</div>
<div class="row"><div class="col-md-6"></div><div class="col-md-6"><?= totals_box() ?></div></div>
<div class="mt-2"><?= save_buttons('Enter bill', url($pageKey), Access::can($pageKey, 'create')) ?></div>
</form>
<?php endif; ?>
<?php
layout_end();
