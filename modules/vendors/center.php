<?php
declare(strict_types=1);

require_once APP_ROOT . '/modules/txn/_common.php';

$pageKey = 'vend.center';
$a = g('a');

if ($a === 'note' && is_post()) {
    Access::require($pageKey, 'edit');
    $vid = (int)p('entity_id');
    DB::insert('entity_notes', [
        'entity_type' => 'vendor', 'entity_id' => $vid, 'note' => mb_substr((string)p('note'), 0, 2000),
        'is_todo' => p('is_todo') === '1' ? 1 : 0, 'due_date' => pdate('due_date'), 'created_by' => Auth::id(),
    ]);
    redirect(url($pageKey, ['id' => $vid]));
}

if ($a === 'new' || $a === 'edit') {
    $v = null;
    if ($a === 'edit') {
        Access::require($pageKey, 'edit');
        $v = DB::row('SELECT * FROM vendors WHERE id = :id', ['id' => (int)g('id')]);
        if (!$v) {
            redirect(url($pageKey));
        }
    } else {
        Access::require($pageKey, 'create');
    }
    handle_post(function () use ($v, $pageKey) {
        $data = [];
        foreach (['name', 'company_name', 'contact_person', 'phone', 'fax', 'email', 'address', 'tin', 'account_no', 'notes'] as $f) {
            $data[$f] = p($f) !== '' ? mb_substr((string)p($f), 0, $f === 'notes' ? 5000 : 255) : null;
        }
        if (!$data['name']) {
            fail('Enter the vendor name.');
        }
        foreach (['terms_id', 'default_expense_account_id', 'tax_code_id'] as $f) {
            $data[$f] = pint($f);
        }
        $data['credit_limit'] = r2(pnum('credit_limit'));
        if (Withholding::ready()) {
            // the taxes usually withheld from this supplier: ticked for its bills in Pay Bills (one or two types, or more)
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['wht_ids'] ?? [])), fn($i) => Withholding::get($i) !== null)));
            $data['wht_ids'] = $ids ? implode(',', $ids) : null;
        }
        $data['is_active'] = p('is_active', '1') === '1' ? 1 : 0;
        if (DB::value('SELECT id FROM vendors WHERE name = :n AND id <> :id', ['n' => $data['name'], 'id' => $v['id'] ?? 0])) {
            fail('Another vendor already has this name.');
        }
        if ($v) {
            DB::update('vendors', $data, 'id = :id', ['id' => $v['id']]);
            $id = (int)$v['id'];
            Audit::log('edit', 'vend', 'vendor', $id, $data['name'], ['before' => $v, 'after' => $data, 'page_key' => $pageKey]);
        } else {
            $id = DB::insert('vendors', $data);
            Audit::log('create', 'vend', 'vendor', $id, $data['name'], ['snapshot' => $data, 'page_key' => $pageKey]);
        }
        flash('success', 'Vendor saved.');
        redirect_after_save(url($pageKey, ['a' => 'new']), url($pageKey, ['id' => $id]));
    });
    layout_start($v ? 'Edit vendor: ' . $v['name'] : 'New vendor', ['actions' => btn_link(url($pageKey, $v ? ['id' => $v['id']] : []), 'Cancel')]);
    ?>
    <form method="post" class="card"><div class="card-body"><?= csrf_field() ?>
      <div class="row g-2">
        <?= input_field('name', 'Vendor name', fv('name', $v), ['required' => true]) ?>
        <?= input_field('company_name', 'Company name', fv('company_name', $v)) ?>
        <?= input_field('contact_person', 'Contact person', fv('contact_person', $v)) ?>
        <?= input_field('phone', 'Phone', fv('phone', $v)) ?>
        <?= input_field('fax', 'Fax', fv('fax', $v)) ?>
        <?= input_field('email', 'Email', fv('email', $v), ['type' => 'email']) ?>
        <div class="col-md-8"><label class="form-label">Address</label><input class="form-control form-control-sm" name="address" value="<?= h(fv('address', $v)) ?>"></div>
        <?= input_field('tin', 'Tax identification no. (TIN)', fv('tin', $v)) ?>
        <?= select_field('terms_id', 'Payment terms', Lookup::terms(), fv('terms_id', $v), ['blank' => '']) ?>
        <?= input_field('credit_limit', 'Credit limit with vendor', fv('credit_limit', $v, '0'), ['type' => 'number']) ?>
        <?= input_field('account_no', 'Our account no. with this vendor', fv('account_no', $v)) ?>
        <?= select_field('default_expense_account_id', 'Default expense account', Accts::all(), fv('default_expense_account_id', $v), ['blank' => '']) ?>
        <?= select_field('tax_code_id', 'Tax code', Lookup::taxCodes(), fv('tax_code_id', $v), ['blank' => '']) ?>
        <?php if (Withholding::ready()):
            $whtOpts = [];
            foreach (Withholding::forSide('purchases') + array_intersect_key(Withholding::all(), array_flip(Withholding::defaults($v['wht_ids'] ?? ''))) as $wid => $wt) {
                $whtOpts[$wid] = Withholding::label($wt) . ($wt['atc'] ? ' (' . $wt['atc'] . ')' : '');
            }
            $whtVals = is_post() ? array_map('intval', (array)($_POST['wht_ids'] ?? [])) : Withholding::defaults($v['wht_ids'] ?? '');
        ?>
        <?= multi_check_field('wht_ids', 'Withholding taxes', $whtOpts, $whtVals, ['all' => 'None withheld', 'noun' => 'taxes', 'class' => 'col-md-4', 'id' => 'vendWht']) ?>
        <?php endif; ?>
        <?= check_field('is_active', 'Active', fv('is_active', $v, 1)) ?>
        <div class="col-12"><label class="form-label">Notes</label><textarea class="form-control form-control-sm" name="notes" rows="2"><?= h(fv('notes', $v)) ?></textarea></div>
      </div>
      <?php if (Withholding::ready()): ?><p class="small text-muted mt-2 mb-0">Withholding taxes: the taxes of the Withholding Tax Table usually withheld when this supplier is paid (for example 1% on goods, and 5% VAT withheld). Pay Bills ticks them for the supplier's bills; they can still be changed on each bill.</p><?php endif; ?>
      <div class="mt-3"><?= save_buttons('Save vendor', url($pageKey), Access::can($pageKey, 'create')) ?></div>
    </div></form>
    <?php
    layout_end();
    return;
}

$q = g('q');
$show = g('show', 'active');
[$ain, $ap] = DB::in('ap', Accts::apIds() ?: [0]);
$sql = "SELECT v.*, (SELECT COALESCE(SUM(j.credit - j.debit),0) FROM journal_lines j WHERE j.vendor_id = v.id AND j.account_id IN $ain) AS balance FROM vendors v WHERE 1=1";
$params = $ap;
if ($show !== 'all') {
    $sql .= ' AND v.is_active = 1';
}
if ($q !== '') {
    $sql .= ' AND (v.name LIKE :q OR v.company_name LIKE :q OR v.contact_person LIKE :q)';
    $params['q'] = "%$q%";
}
$list = DB::all($sql . ' ORDER BY v.name', $params);
if ($show === 'balance') {
    $list = array_values(array_filter($list, fn($r) => abs((float)$r['balance']) > 0.004));
}
if (g('export') === 'csv') {
    Access::require($pageKey, 'print');
    Audit::log('export', 'vend', 'list', null, 'Vendor list', ['page_key' => $pageKey]);
    $whtName = fn($ids) => implode(', ', array_map(fn($i) => Withholding::get($i) ? Withholding::label(Withholding::get($i)) : '', Withholding::defaults($ids)));
    csv_download('vendors.csv', ['Name', 'Company', 'Contact', 'Phone', 'Email', 'Address', 'TIN', 'Account no.', 'Withholding taxes', 'Balance'],
        array_map(fn($r) => [$r['name'], $r['company_name'], $r['contact_person'], $r['phone'], $r['email'], $r['address'], $r['tin'], $r['account_no'], $whtName($r['wht_ids'] ?? ''), $r['balance']], $list));
}
$selId = gint('id') ?? ($list[0]['id'] ?? null);
$sel = $selId ? DB::row('SELECT * FROM vendors WHERE id = :id', ['id' => $selId]) : null;

layout_start('Vendor Center', ['actions' => page_link($pageKey, ['a' => 'new'], 'New vendor', 'btn-primary', 'plus-lg', 'create')
    . ' ' . (Access::can($pageKey, 'print') ? btn_link(current_url(['export' => 'csv']), 'Export list', 'btn-outline-secondary', 'download') : '')]);
?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card">
      <div class="card-body pb-2">
        <form class="d-flex gap-1 align-items-center" method="get"><input type="hidden" name="p" value="<?= h($pageKey) ?>">
          <div class="live-search flex-grow-1"><i class="bi bi-search"></i>
            <input class="form-control form-control-sm" name="q" value="<?= h($q) ?>" placeholder="Type to find a vendor" autocomplete="off"
                   data-live-filter="#vendList .list-group-item" data-live-count="#vendCount" data-live-empty="#vendNone" aria-label="Find a vendor"></div>
          <select class="form-select form-select-sm" name="show" data-autosubmit style="max-width:140px"><?= options(['active' => 'Active', 'all' => 'All', 'balance' => 'With balance'], $show) ?></select></form>
        <div class="d-flex justify-content-between mt-1"><span class="live-count"><span id="vendCount"><?= count($list) ?></span> vendor(s)</span><span class="live-count d-none d-xl-inline">Type any part of the name, company or contact</span></div>
      </div>
      <div class="list-group list-group-flush center-list" id="vendList" style="max-height:70vh;overflow:auto">
        <?php foreach ($list as $r): ?>
          <a class="list-group-item list-group-item-action d-flex justify-content-between <?= (int)$r['id'] === (int)$selId ? 'active' : '' ?>" href="<?= h(url($pageKey, ['id' => $r['id'], 'show' => $show])) ?>"
             data-filter-text="<?= h($r['name'] . ' ' . $r['company_name'] . ' ' . $r['contact_person'] . ' ' . $r['phone']) ?>">
            <span><?= h($r['name']) ?></span><span class="num"><?= money($r['balance'], true) ?></span></a>
        <?php endforeach; ?>
        <div class="list-group-item text-muted small" id="vendNone">No vendor matches. Press Enter to search the whole list on the server.</div>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
  <?php if ($sel):
      $bal = Balances::vendorBalance((int)$sel['id']);
      $txns = DB::all("SELECT * FROM transactions WHERE vendor_id = :v ORDER BY txn_date DESC, id DESC LIMIT 300", ['v' => $sel['id']]);
      $notes = DB::all("SELECT n.*, u.username FROM entity_notes n LEFT JOIN users u ON u.id = n.created_by WHERE n.entity_type = 'vendor' AND n.entity_id = :id ORDER BY n.id DESC", ['id' => $sel['id']]);
  ?>
    <div class="card mb-3"><div class="card-body">
      <div class="d-flex justify-content-between flex-wrap">
        <div>
          <h2 class="h5 mb-0"><?= h($sel['name']) ?></h2>
          <div class="small text-muted"><?= h($sel['company_name']) ?>, TIN <?= h($sel['tin']) ?>, Account no. <?= h($sel['account_no']) ?></div>
          <div class="small"><?= h($sel['contact_person']) ?> <?= h($sel['phone']) ?> <?= h($sel['email']) ?></div>
          <div class="small text-muted"><?= h($sel['address']) ?></div>
          <div class="small">Terms: <?= h(DB::value('SELECT name FROM terms WHERE id = :id', ['id' => $sel['terms_id']])) ?>, Default expense: <?= h(account_label($sel['default_expense_account_id'] ? (int)$sel['default_expense_account_id'] : null)) ?></div>
          <?php if (Withholding::ready()): $wl = array_filter(array_map(fn($i) => Withholding::get($i) ? Withholding::label(Withholding::get($i)) : null, Withholding::defaults($sel['wht_ids'] ?? ''))); ?>
          <div class="small">Withholding taxes: <?= $wl ? h(implode(', ', $wl)) : '<span class="text-muted">none</span>' ?></div><?php endif; ?>
        </div>
        <div class="text-end"><div class="small text-muted">We owe</div><div class="fs-4 fw-bold"><?= money($bal) ?></div>
          <?php $vc = Balances::vendorCredits((int)$sel['id']); if ($vc): ?><div class="small text-success">Credits available: <?= money(array_sum(array_column($vc, 'remaining'))) ?></div><?php endif; ?>
          <?php $va = Advances::open((int)$sel['id']); if ($va): ?><div class="small text-success">Advances to apply: <?= money(array_sum(array_column($va, 'remaining'))) ?></div><?php endif; ?></div>
      </div>
      <div class="d-flex flex-wrap gap-1 mt-3">
        <?= page_link($pageKey, ['a' => 'edit', 'id' => $sel['id']], 'Edit', 'btn-outline-primary', 'pencil', 'edit') ?>
        <?= page_link('vend.purchase_orders', ['a' => 'new', 'vendor_id' => $sel['id']], 'Purchase order', 'btn-outline-success', 'plus', 'create') ?>
        <?= page_link('vend.receive_items', ['vendor_id' => $sel['id']], 'Receive items', 'btn-outline-success', 'box-arrow-in-down', 'create') ?>
        <?= page_link('vend.bills', ['a' => 'new', 'vendor_id' => $sel['id']], 'Enter bill', 'btn-outline-success', 'plus', 'create') ?>
        <?= page_link('vend.pay_bills', ['vendor_id' => $sel['id']], 'Pay bills', 'btn-outline-success', 'cash', 'create') ?>
        <?= page_link('vend.advances', ['a' => 'new', 'vendor_id' => $sel['id']], 'Advance payment', 'btn-outline-success', 'wallet2', 'create') ?>
        <?= page_link('bank.write_checks', ['a' => 'new', 'vendor_id' => $sel['id']], 'Write check', 'btn-outline-success', 'pencil-square', 'create') ?>
        <?= page_link('rpt.vendor_balance_detail', ['vendor_id' => $sel['id']], 'Balance detail', 'btn-outline-secondary', 'bar-chart') ?>
        <?= page_link('rpt.txn_list_vendor', ['vendor_id' => $sel['id']], 'Transaction list', 'btn-outline-secondary', 'list-ul') ?>
      </div>
    </div></div>
    <div class="card mb-3"><div class="card-header fw-semibold">Transactions</div>
      <div class="table-responsive" style="max-height:420px"><table class="table table-sm table-hover mb-0">
        <thead><tr><th>Type</th><th>No.</th><th>Vendor ref.</th><th>Date</th><th>Due</th><th class="num">Amount</th><th class="num">Open</th><th>Status</th></tr></thead>
        <?php foreach ($txns as $t):
            $open = '';
            if ((int)$t['is_voided'] === 0 && $t['txn_type'] === 'bill') { $open = money(Balances::open($t)); }
            if ((int)$t['is_voided'] === 0 && $t['txn_type'] === 'vendor_credit') { $open = money(Balances::creditRemaining($t)); }
            if ((int)$t['is_voided'] === 0 && $t['txn_type'] === 'vendor_advance') { $open = money(Advances::remaining($t)); }
        ?>
          <tr><td><?= h(Txn::label($t['txn_type'])) ?></td><td><?= Txn::link($t) ?></td><td><?= h($t['ref_no']) ?></td><td><?= h(fdate($t['txn_date'])) ?></td><td><?= h(fdate($t['due_date'])) ?></td>
            <td class="num"><?= money($t['total']) ?></td><td class="num"><?= $open ?></td><td><?= status_badge($t['status']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$txns): ?><tr><td colspan="8" class="text-muted text-center">No transactions.</td></tr><?php endif; ?>
      </table></div>
    </div>
    <div class="card"><div class="card-header fw-semibold">Notes and reminders</div><div class="card-body">
      <?php if ($sel['notes']): ?><p class="small"><?= nl2br(h($sel['notes'])) ?></p><?php endif; ?>
      <?php foreach ($notes as $n): ?><div class="small border-bottom py-1"><?= (int)$n['is_todo'] ? '<i class="bi bi-check2-square"></i>' : '<i class="bi bi-sticky"></i>' ?> <?= h($n['note']) ?> <?= $n['due_date'] ? '(' . h(fdate($n['due_date'])) . ')' : '' ?> <span class="text-muted">— <?= h($n['username']) ?></span></div><?php endforeach; ?>
      <?php if (Access::can($pageKey, 'edit')): ?>
      <form method="post" action="<?= h(url($pageKey, ['a' => 'note'])) ?>" class="row g-2 mt-2"><?= csrf_field() ?><input type="hidden" name="entity_id" value="<?= (int)$sel['id'] ?>">
        <div class="col-md-6"><input class="form-control form-control-sm" name="note" required placeholder="Add a note or reminder"></div>
        <div class="col-md-2"><input class="form-control form-control-sm" type="date" name="due_date"></div>
        <div class="col-md-2 small d-flex align-items-center gap-1"><input type="checkbox" class="form-check-input" name="is_todo" value="1"> To-do</div>
        <div class="col-md-2"><button class="btn btn-sm btn-outline-primary w-100">Add</button></div></form>
      <?php endif; ?>
    </div></div>
  <?php else: ?><p class="text-muted">No vendors yet.</p><?php endif; ?>
  </div>
</div>
<?php
layout_end();
