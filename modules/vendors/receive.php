<?php
declare(strict_types=1);

/**
 * Receive Items (item receipt, bill to follow) and Receive Items and Enter Bills.
 * An item receipt that has not been billed yet can be edited (a=edit): its date, slip number, site, memo and
 * lines change, the purchase order it was received against is updated, and stock and the journal are posted
 * again (Txn::save keeps the line ids, so the PO lines it received follow the new quantities). The before and
 * after, with the reason given, go to the audit trail.
 */
require_once APP_ROOT . '/modules/txn/_common.php';
require_once APP_ROOT . '/modules/txn/_grids.php';
require_once APP_ROOT . '/modules/txn/_memorize.php';

$mode = $PARAMS['mode'];            // receipt | bill
$pageKey = $PAGE['key'];
$docType = $mode === 'bill' ? 'bill' : 'item_receipt';

if ($mode === 'receipt' && txn_common_actions($pageKey, ['item_receipt'])) {
    return;
}
if (g('a') === 'list') {
    $tracked = ItemGroups::ready();     // the received_via column came with the same database update
    if ($mode === 'bill') {
        // every bill entered with Receive Items and Enter Bills
        if (!$tracked) {
            redirect(url('vend.bills'));
        }
        render_txn_list($pageKey, ['bill'], 'Receive Items and Enter Bills List', ['no_new' => true, 'own_link' => true,
            'where' => "t.received_via = 'bill'", 'hidden' => ['a' => 'list'],
            'statuses' => ['open' => 'Open', 'partial' => 'Partially paid', 'paid' => 'Paid', 'void' => 'Void'],
            'actions' => btn_link(url($pageKey), 'Receive items and enter bill', 'btn-primary', 'box-arrow-in-down')]);
        return;
    }
    // every receipt of items: those waiting for their bill, and those whose bill has been entered
    render_txn_list($pageKey, ['item_receipt', 'bill'], 'Receive Items List', ['no_new' => true, 'own_link' => true,
        'where' => $tracked ? "(t.txn_type = 'item_receipt' OR t.received_via = 'receipt')" : "t.txn_type = 'item_receipt'", 'hidden' => ['a' => 'list'],
        'statuses' => ['open' => 'Open', 'partial' => 'Partially paid', 'paid' => 'Paid', 'void' => 'Void'],
        'actions' => btn_link(url($pageKey), 'Receive items', 'btn-primary', 'box-arrow-in-down')]);
    return;
}
$edit = null;
if ($mode === 'receipt' && g('a') === 'edit') {
    Access::require($pageKey, 'edit');
    $edit = Txn::load(gint('id') ?? 0);
    if (!$edit || $edit['txn_type'] !== 'item_receipt') {
        flash('warning', 'Item receipt not found. A receipt whose bill has been entered is edited as the bill.');
        redirect(url($pageKey, ['a' => 'list']));
    }
    if ((int)$edit['is_voided'] === 1) {
        flash('warning', 'A voided item receipt cannot be edited.');
        redirect(Txn::url($edit));
    }
    if (!Access::siteAllowed($edit['site_id'] ? (int)$edit['site_id'] : null)) {
        Access::denyPage($pageKey, 'edit a receipt of another site on');
    }
} else {
    Access::require($pageKey, 'create');
}

$poId = $edit ? null : gint('po');
$po = $poId ? Txn::load($poId) : null;
if ($po && ($po['txn_type'] !== 'purchase_order' || (int)$po['is_voided'] === 1)) {
    $po = null;
}
$vendorId = $edit ? (int)$edit['vendor_id'] : ($po ? (int)$po['vendor_id'] : gint('vendor_id'));
$openPos = ($vendorId && !$edit) ? DB::all("SELECT * FROM transactions WHERE txn_type = 'purchase_order' AND vendor_id = :v AND status IN ('open','partial') AND is_voided = 0 ORDER BY txn_date", ['v' => $vendorId]) : [];

$lines = [];
if ($edit) {
    $lines = array_values(array_filter(Txn::lines((int)$edit['id']), fn($l) => !empty($l['item_id'])));
    // the PO it was received against, for the note at the top
    $poId = (int)DB::value('SELECT s.txn_id FROM transaction_lines l JOIN transaction_lines s ON s.id = l.source_line_id WHERE l.txn_id = :t LIMIT 1', ['t' => $edit['id']]) ?: null;
    $po = $poId ? Txn::load($poId) : null;
}
if ($po && !$edit) {
    foreach (Txn::lines((int)$po['id']) as $l) {
        $rem = (float)$l['qty'] - (float)$l['qty_fulfilled'];
        if ((int)$l['is_closed'] === 1 || $rem <= 0 || !$l['item_id']) {
            continue;
        }
        $amount = r2($rem * (float)$l['rate']);
        $taxId = $mode === 'bill' && $l['tax_code_id'] ? (int)$l['tax_code_id'] : null;
        $lines[] = [
            'item_id' => $l['item_id'], 'description' => $l['description'], 'qty' => $rem, 'rate' => $l['rate'],
            'amount' => $amount, 'tax_code_id' => $taxId,
            // the tax of the purchase order comes across with the line, so the bill shows the same tax and total
            'tax_amount' => $taxId ? r2($amount * tax_rate($taxId) / 100) : 0.0,
            'site_id' => $l['site_id'] ?: $po['site_id'], 'customer_id' => $l['customer_id'], 'is_billable' => $l['is_billable'],
            'source_line_id' => $l['id'],
        ];
    }
}

handle_post(function () use ($mode, $docType, $vendorId, $po, $pageKey, $edit) {
    if (!$vendorId) {
        fail('Choose a vendor.');
    }
    $lines = parse_item_lines(grid_rows('lines'), 'purchase');
    $lines = array_values(array_filter($lines, fn($l) => abs((float)$l['qty']) > 0 || abs((float)$l['amount']) > 0));
    if (!$lines) {
        fail('Enter the quantities received.');
    }
    if ($mode === 'receipt') {
        foreach ($lines as &$l) {
            $l['tax_code_id'] = null;
            $l['tax_amount'] = 0;
        }
        unset($l);
    }
    // cannot receive more than ordered on a PO line without noticing (on an edit, what this receipt itself
    // received on the line before is still open to it)
    $mine = [];
    if ($edit) {
        foreach (DB::all('SELECT source_line_id, qty FROM transaction_lines WHERE txn_id = :t AND source_line_id IS NOT NULL', ['t' => $edit['id']]) as $r) {
            $mine[(int)$r['source_line_id']] = ($mine[(int)$r['source_line_id']] ?? 0.0) + (float)$r['qty'];
        }
    }
    foreach ($lines as $l) {
        if ($l['source_line_id']) {
            $src = DB::row('SELECT qty, qty_fulfilled FROM transaction_lines WHERE id = :id', ['id' => $l['source_line_id']]);
            $open = $src ? (float)$src['qty'] - (float)$src['qty_fulfilled'] + ($mine[(int)$l['source_line_id']] ?? 0.0) : 0.0;
            if ($src && (float)$l['qty'] > $open + 0.00001 && p('allow_over') !== '1') {
                fail('You are receiving more than was ordered on ' . $l['description'] . '. Tick "Allow over-receiving" to continue.');
            }
        }
    }
    $h = [
        'txn_date' => pdate('txn_date'),
        'vendor_id' => $vendorId,
        'site_id' => pint('site_id'),
        'ref_no' => p('ref_no') ?: null,
        'memo' => p('memo') ?: (($po && !$edit) ? 'Received against ' . Txn::ref($po) : null),
        'account_id' => Accts::get('ap'),
    ];
    if ($mode === 'bill') {
        $h['terms_id'] = pint('terms_id');
        $h['due_date'] = pdate('due_date') ?: Txn::dueDate($h['txn_date'] ?? today(), $h['terms_id']);
    }
    if ($edit) {
        $id = Txn::save('item_receipt', $h, $lines, (int)$edit['id'], ['reason' => trim((string)p('reason')) ?: null]);
        flash('success', Txn::ref(Txn::load($id)) . ' updated. Stock, the journal and the purchase order follow the new figures.');
        redirect(url($pageKey, ['a' => 'view', 'id' => $id]));
    }
    if (ItemGroups::ready()) {
        // the page the items were received on: the Receive Items List / Receive Items and Enter Bills List
        $h['received_via'] = $mode === 'bill' ? 'bill' : 'receipt';
    }
    $id = Txn::save($docType, $h, $lines);
    flash('success', ($mode === 'bill' ? 'Items received and bill entered.' : 'Items received. The bill can be entered when it arrives.'));
    redirect_after_save(url($pageKey), url($mode === 'bill' ? 'vend.bills' : $pageKey, ['a' => 'view', 'id' => $id]));
});

if (is_post()) {
    $lines = array_values(array_filter(grid_rows('lines'), fn($r) => !empty($r['item_id'])));
}
// filled from a memorized receipt or bill ("Load Memorized Transaction"): the item lines and the header
$mem = (!$po && !$edit && !is_post() && gint('mem')) ? Memorized::forForm((int)gint('mem'), [$docType]) : null;
if ($mem) {
    $lines = array_values(array_filter($mem['lines'], fn($l) => !empty($l['item_id'])));
}
$rec = $edit ?: ($mem['header'] ?? null);
$vendor = $vendorId ? DB::row('SELECT * FROM vendors WHERE id = :id', ['id' => $vendorId]) : null;
[$memButton, $memBox] = memorize_controls($docType, trim(($vendor['name'] ?? '') . ' ' . ($mode === 'bill' ? 'bill' : 'item receipt')),
    'Memorize Transaction', $mode === 'bill' ? 'bill' : 'item receipt');
[$loadButton, $loadList] = $edit ? ['', ''] : memorized_loader([$docType], $pageKey);
if ($edit) {
    $memButton = $memBox = '';
}
layout_start($edit ? 'Edit Item Receipt ' . $edit['txn_no'] : ($mode === 'bill' ? 'Receive Items and Enter Bills' : 'Receive Items'), ['actions' => $edit
    ? btn_link(Txn::url($edit), 'Cancel', 'btn-outline-secondary')
    : $loadButton . ' ' . ($mode === 'receipt' ? btn_link(url($pageKey, ['a' => 'list']), 'Receive Items List', 'btn-outline-secondary', 'list-ul')
        : btn_link(url($pageKey, ['a' => 'list']), 'Receive Items and Enter Bills List', 'btn-outline-secondary', 'list-ul'))]);
echo memorized_loaded_note($mem);
?>
<?php if ($edit): ?>
<div class="card mb-3"><div class="card-body">
  <div class="edit-facts">
    <div><span>Vendor</span><strong><?= h($vendor['name'] ?? '') ?></strong></div>
    <div><span>Received against</span><strong><?= $po ? Txn::link($po, Txn::ref($po)) : 'No purchase order' ?></strong></div>
    <div><span>Total now</span><strong><?= money($edit['total']) ?></strong></div>
  </div>
  <p class="small text-muted mb-0 mt-2">Saving posts the receipt again: stock at the site, Inventory and Accounts Payable move to the new quantities and costs, and what the purchase order shows as received is updated. To change the vendor, void this receipt and receive the items again.</p>
</div></div>
<?php else: ?>
<div class="card mb-3"><div class="card-body">
  <form method="get" class="row g-2 filters">
    <input type="hidden" name="p" value="<?= h($pageKey) ?>">
    <?= select_field('vendor_id', 'Vendor', Lookup::vendors(), $vendorId, ['blank' => '— choose —', 'class' => 'col-md-4', 'extra' => 'data-autosubmit']) ?>
    <?php if ($openPos): ?>
      <?= select_field('po', 'Open purchase order', array_combine(array_column($openPos, 'id'), array_map(fn($p) => $p['txn_no'] . ', ' . fdate($p['txn_date']) . ', ' . money($p['total']), $openPos)), $poId, ['blank' => 'Receive without a PO', 'class' => 'col-md-5', 'extra' => 'data-autosubmit']) ?>
    <?php elseif ($vendorId): ?>
      <div class="col-md-5 small text-muted d-flex align-items-end">This vendor has no open purchase orders.</div>
    <?php endif; ?>
  </form>
</div></div>
<?php endif; ?>

<?php if ($vendorId): ?>
<form method="post">
<?= csrf_field() ?>
<div class="card mb-3"><div class="card-body row g-2">
  <?= input_field('txn_date', $mode === 'bill' ? 'Bill date' : 'Date received', fv('txn_date', $edit, today()), ['type' => 'date', 'class' => 'col-md-2']) ?>
  <?= input_field('ref_no', $mode === 'bill' ? "Vendor's invoice no." : 'Delivery / packing slip no.', fv('ref_no', $rec), ['class' => 'col-md-2']) ?>
  <?= select_field('site_id', 'Receive at site', Lookup::sites(), fv('site_id', $rec, $po['site_id'] ?? array_key_first(Lookup::sites())), ['class' => 'col-md-2']) ?>
  <?php if ($mode === 'bill'): ?>
    <?= select_field('terms_id', 'Terms', Lookup::terms(), fv('terms_id', $rec, $vendor['terms_id'] ?? ''), ['blank' => '', 'class' => 'col-md-2']) ?>
    <?= input_field('due_date', 'Due date (blank = from terms)', fv('due_date'), ['type' => 'date', 'class' => 'col-md-2']) ?>
  <?php endif; ?>
  <?= check_field('allow_over', 'Allow over-receiving', fv('allow_over', null, 0), 'col-md-2') ?>
  <?= Classes::field(fv('class_id', $rec, $po['class_id'] ?? ''), 'col-md-2') ?>
  <?= input_field('memo', 'Memo', fv('memo', $rec), ['class' => 'col-md-6']) ?>
  <?php if ($edit): ?><?= input_field('reason', 'Reason for the change', fv('reason'), ['class' => 'col-md-6', 'extra' => 'maxlength="255" placeholder="Recorded in the audit trail"']) ?><?php endif; ?>
</div></div>
<div class="card card-body mb-3">
  <?= item_grid($lines, ['mode' => 'purchase', 'show_site' => true, 'show_job' => true, 'min_rows' => ($po || $edit) ? 0 : 3]) ?>
  <div class="mt-2"><?= totals_box() ?></div>
  <p class="small text-muted mb-0 mt-2"><?= $mode === 'bill'
      ? 'Posting debits Inventory and Input Tax and credits Accounts Payable, and updates the purchase order.'
      : 'Posting increases inventory now (debit Inventory, credit Accounts Payable at the PO cost). The receipt is not a bill, so it does not appear in Pay Bills until you enter the bill.' ?></p>
</div>
<div class="d-flex flex-wrap gap-2 align-items-center">
  <?php if ($edit): ?>
    <button class="btn btn-primary"><i class="bi bi-check2"></i> Save changes</button>
    <?= btn_link(Txn::url($edit), 'Cancel', 'btn-outline-secondary') ?>
  <?php else: ?>
  <?= save_buttons($mode === 'bill' ? 'Receive and enter bill' : 'Record item receipt', url($pageKey), Access::can($pageKey, 'create')) ?>
  <?php endif; ?>
  <?php if ($memButton): ?><div class="ms-auto"><?= $memButton ?></div><?php endif; ?>
</div>
<?= $memBox ?>
</form>
<?php endif; ?>
<?= $loadList ?>
<?php
layout_end();
