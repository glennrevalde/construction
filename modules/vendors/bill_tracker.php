<?php
declare(strict_types=1);

require_once APP_ROOT . '/modules/txn/_common.php';

$pageKey = 'vend.bill_tracker';
$vendorId = gint('vendor_id');
$from = gdate('from', date('Y-m-d', strtotime('-30 days')));
$to = gdate('to', today());
$stage = g('stage');

$vf = $vendorId ? ' AND t.vendor_id = :v' : '';
$vp = $vendorId ? ['v' => $vendorId] : [];

$pos = DB::all("SELECT t.*, v.name AS vendor FROM transactions t JOIN vendors v ON v.id = t.vendor_id
                 WHERE t.txn_type = 'purchase_order' AND t.is_voided = 0 AND t.status IN ('open','partial') $vf ORDER BY t.due_date", $vp);
foreach ($pos as &$po) {
    $po['open_amount'] = (float)DB::value('SELECT COALESCE(SUM(GREATEST(qty - qty_fulfilled,0) * rate),0) FROM transaction_lines WHERE txn_id = :t AND is_closed = 0', ['t' => $po['id']]);
}
unset($po);
$receipts = DB::all("SELECT t.*, v.name AS vendor FROM transactions t JOIN vendors v ON v.id = t.vendor_id
                      WHERE t.txn_type = 'item_receipt' AND t.is_voided = 0 $vf ORDER BY t.txn_date", $vp);
$bills = Balances::openPayables($vendorId);
$paid = DB::all("SELECT * FROM (SELECT t.*, v.name AS vendor,
                        (SELECT MAX(p.txn_date) FROM payment_applications pa JOIN transactions p ON p.id = pa.payment_txn_id WHERE pa.target_txn_id = t.id AND p.is_voided = 0) AS paid_on
                   FROM transactions t JOIN vendors v ON v.id = t.vendor_id
                  WHERE t.txn_type = 'bill' AND t.status = 'paid' AND t.is_voided = 0 $vf) x
                 WHERE x.paid_on BETWEEN :f AND :to ORDER BY x.paid_on DESC", $vp + ['f' => $from, 'to' => $to]);

$overdue = array_filter($bills, fn($b) => $b['due_date'] && $b['due_date'] < today());
$sum = fn(array $rows, string $k) => array_sum(array_map(fn($r) => (float)$r[$k], $rows));

layout_start('Bill Tracker');
?>
<form class="row g-2 mb-3 filters" method="get">
  <input type="hidden" name="p" value="<?= h($pageKey) ?>">
  <?= select_field('vendor_id', 'Vendor', Lookup::vendors(), $vendorId, ['blank' => 'All vendors', 'class' => 'col-md-3']) ?>
  <?= select_field('stage', 'Show', ['unbilled' => 'Unbilled', 'unpaid' => 'Unpaid', 'overdue' => 'Overdue only', 'paid' => 'Paid'], $stage, ['blank' => 'All stages', 'class' => 'col-md-2']) ?>
  <div class="col-md-2"><label class="form-label">Paid from</label><input class="form-control form-control-sm" type="date" name="from" value="<?= h($from) ?>"></div>
  <div class="col-md-2"><label class="form-label">Paid to</label><input class="form-control form-control-sm" type="date" name="to" value="<?= h($to) ?>"></div>
  <div class="col-md-1 d-flex align-items-end"><button class="btn btn-sm btn-secondary">Filter</button></div>
</form>

<div class="row g-3 mb-3">
  <div class="col-md-3"><div class="card kpi" style="border-left-color:var(--bs-secondary)"><div class="card-body"><div class="small text-muted">Unbilled (open POs + receipts)</div><div class="value"><?= money($sum($pos, 'open_amount') + $sum($receipts, 'total')) ?></div><div class="small"><?= count($pos) ?> PO(s), <?= count($receipts) ?> receipt(s)</div></div></div></div>
  <div class="col-md-3"><div class="card kpi" style="border-left-color:var(--bs-primary)"><div class="card-body"><div class="small text-muted">Unpaid bills</div><div class="value"><?= money($sum($bills, 'open_balance')) ?></div><div class="small"><?= count($bills) ?> bill(s)</div></div></div></div>
  <div class="col-md-3"><div class="card kpi" style="border-left-color:var(--bs-danger)"><div class="card-body"><div class="small text-muted">Overdue</div><div class="value"><?= money($sum($overdue, 'open_balance')) ?></div><div class="small"><?= count($overdue) ?> bill(s)</div></div></div></div>
  <div class="col-md-3"><div class="card kpi" style="border-left-color:var(--bs-success)"><div class="card-body"><div class="small text-muted">Paid <?= h(fdate($from)) ?> – <?= h(fdate($to)) ?></div><div class="value"><?= money($sum($paid, 'total')) ?></div><div class="small"><?= count($paid) ?> bill(s)</div></div></div></div>
</div>

<?php if (!$stage || $stage === 'unbilled'): ?>
<div class="card mb-3"><div class="card-header fw-semibold">Unbilled</div>
  <table class="table table-sm mb-0"><thead><tr><th>Type</th><th>No.</th><th>Vendor</th><th>Date</th><th>Expected</th><th class="num">Amount</th><th>Actions</th></tr></thead>
  <?php foreach ($pos as $po): ?>
    <tr><td>Purchase order</td><td><?= Txn::link($po) ?></td><td><?= h($po['vendor']) ?></td><td><?= h(fdate($po['txn_date'])) ?></td><td><?= h(fdate($po['due_date'])) ?></td><td class="num"><?= money($po['open_amount']) ?></td>
      <td class="d-flex gap-1">
        <?= page_link('vend.receive_bill', ['po' => $po['id']], 'Convert to bill', 'btn-outline-success', '', 'create') ?>
        <?php if (Access::can('vend.purchase_orders', 'edit')): ?>
          <form method="post" action="<?= h(url('vend.purchase_orders', ['a' => 'close', 'id' => $po['id']])) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-dark" data-confirm="Close this purchase order?">Close PO</button></form>
        <?php endif; ?>
      </td></tr>
  <?php endforeach; ?>
  <?php foreach ($receipts as $r): ?>
    <tr><td>Item receipt</td><td><?= Txn::link($r) ?></td><td><?= h($r['vendor']) ?></td><td><?= h(fdate($r['txn_date'])) ?></td><td></td><td class="num"><?= money($r['total']) ?></td>
      <td><?= page_link('vend.bill_received', ['id' => $r['id']], 'Enter bill', 'btn-outline-success', '', 'create') ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$pos && !$receipts): ?><tr><td colspan="7" class="text-muted text-center">Nothing unbilled.</td></tr><?php endif; ?>
  </table>
</div>
<?php endif; ?>

<?php if (!$stage || $stage === 'unpaid' || $stage === 'overdue'): $list = $stage === 'overdue' ? $overdue : $bills; ?>
<form method="get" action="<?= h(base_url() . '/index.php') ?>">
<input type="hidden" name="p" value="vend.pay_bills">
<div class="card mb-3"><div class="card-header d-flex justify-content-between"><span class="fw-semibold">Unpaid bills</span>
  <?php if (Access::can('vend.pay_bills', 'create') && $list): ?><button class="btn btn-sm btn-success">Pay selected</button><?php endif; ?></div>
  <table class="table table-sm mb-0"><thead><tr><th><input type="checkbox" class="form-check-input" data-check-all=".bt-cb"></th><th>Bill</th><th>Vendor ref.</th><th>Vendor</th><th>Date</th><th>Due</th><th class="num">Amount</th><th class="num">Open</th><th>Status</th></tr></thead>
  <?php foreach ($list as $b): $late = $b['due_date'] && $b['due_date'] < today(); ?>
    <tr><td><input type="checkbox" class="form-check-input bt-cb" name="bills[]" value="<?= (int)$b['id'] ?>"></td><td><?= Txn::link($b) ?></td><td><?= h($b['ref_no']) ?></td><td><?= h($b['vendor_name']) ?></td>
      <td><?= h(fdate($b['txn_date'])) ?></td><td class="<?= $late ? 'text-danger fw-semibold' : '' ?>"><?= h(fdate($b['due_date'])) ?></td>
      <td class="num"><?= money($b['total']) ?></td><td class="num"><?= money($b['open_balance']) ?></td><td><?= $late ? badge('overdue', 'danger') : status_badge($b['status']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$list): ?><tr><td colspan="9" class="text-muted text-center">No unpaid bills.</td></tr><?php endif; ?>
  </table>
</div>
</form>
<?php endif; ?>

<?php if (!$stage || $stage === 'paid'): ?>
<div class="card"><div class="card-header fw-semibold">Paid</div>
  <table class="table table-sm mb-0"><thead><tr><th>Bill</th><th>Vendor ref.</th><th>Vendor</th><th>Bill date</th><th>Paid on</th><th class="num">Amount</th></tr></thead>
  <?php foreach ($paid as $b): ?>
    <tr><td><?= Txn::link($b) ?></td><td><?= h($b['ref_no']) ?></td><td><?= h($b['vendor']) ?></td><td><?= h(fdate($b['txn_date'])) ?></td><td><?= h(fdate($b['paid_on'])) ?></td><td class="num"><?= money($b['total']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$paid): ?><tr><td colspan="6" class="text-muted text-center">No bills paid in this period.</td></tr><?php endif; ?>
  </table>
</div>
<?php endif; ?>
<?php
layout_end();
