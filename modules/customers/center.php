<?php
declare(strict_types=1);

require_once APP_ROOT . '/modules/txn/_common.php';

$pageKey = 'cust.center';
$a = g('a');

// ------------------------------------------------------------------ notes / to-dos
if ($a === 'note' && is_post()) {
    Access::require($pageKey, 'edit');
    $cid = (int)p('entity_id');
    DB::insert('entity_notes', [
        'entity_type' => 'customer', 'entity_id' => $cid, 'note' => mb_substr((string)p('note'), 0, 2000),
        'is_todo' => p('is_todo') === '1' ? 1 : 0, 'due_date' => pdate('due_date'), 'created_by' => Auth::id(),
    ]);
    Audit::log('note_added', 'cust', 'customer', $cid, (string)DB::value('SELECT name FROM customers WHERE id = :id', ['id' => $cid]), ['reason' => mb_substr((string)p('note'), 0, 200)]);
    redirect(url($pageKey, ['id' => $cid]));
}
if ($a === 'note_done' && is_post()) {
    Access::require($pageKey, 'edit');
    $n = DB::row("SELECT * FROM entity_notes WHERE id = :id AND entity_type = 'customer'", ['id' => (int)g('note')]);
    if ($n) {
        DB::update('entity_notes', ['is_done' => 1], 'id = :id', ['id' => $n['id']]);
        redirect(url($pageKey, ['id' => $n['entity_id']]));
    }
    redirect(url($pageKey));
}

// ------------------------------------------------------------------ new / edit customer
if ($a === 'new' || $a === 'edit') {
    $c = null;
    if ($a === 'edit') {
        Access::require($pageKey, 'edit');
        $c = DB::row('SELECT * FROM customers WHERE id = :id', ['id' => (int)g('id')]);
        if (!$c) {
            redirect(url($pageKey));
        }
    } else {
        Access::require($pageKey, 'create');
    }
    $fields = ['name', 'company_name', 'contact_person', 'phone', 'mobile', 'email', 'billing_address', 'shipping_address', 'tin', 'notes'];
    $ids = ['parent_id', 'terms_id', 'price_level_id', 'tax_code_id', 'payment_method_id', 'customer_type_id', 'sales_rep_id', 'billing_group_id'];
    handle_post(function () use ($c, $fields, $ids, $pageKey) {
        $data = [];
        foreach ($fields as $f) {
            $data[$f] = p($f) !== '' ? mb_substr((string)p($f), 0, $f === 'notes' ? 5000 : 255) : null;
        }
        foreach ($ids as $f) {
            $data[$f] = pint($f);
        }
        if (!$data['name']) {
            fail('Enter the customer name.');
        }
        $data['credit_limit'] = r2(pnum('credit_limit'));
        $data['allow_pos_charge'] = p('allow_pos_charge') === '1' ? 1 : 0;
        if (Withholding::ready()) {
            // the taxes this customer usually withholds (a government office: 5% VAT and 1% or 2% income tax)
            $wids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['wht_ids'] ?? [])), fn($i) => Withholding::get($i) !== null)));
            $data['wht_ids'] = $wids ? implode(',', $wids) : null;
        }
        $data['is_active'] = p('is_active', '1') === '1' ? 1 : 0;
        if ($c && $data['parent_id'] === (int)$c['id']) {
            fail('A customer cannot be its own parent.');
        }
        if ($c && (float)$c['credit_limit'] !== $data['credit_limit'] && !Access::can($pageKey, 'approve') && !Access::isAdmin()) {
            // credit limit changes are sensitive: logged with a reason
            if (trim((string)p('change_reason')) === '') {
                fail('Enter a reason for changing the credit limit.');
            }
        }
        $dup = DB::value('SELECT id FROM customers WHERE name = :n AND COALESCE(parent_id,0) = :p AND id <> :id', ['n' => $data['name'], 'p' => $data['parent_id'] ?? 0, 'id' => $c['id'] ?? 0]);
        if ($dup) {
            fail('Another customer or job already has this name.');
        }
        if ($c) {
            DB::update('customers', $data, 'id = :id', ['id' => $c['id']]);
            $id = (int)$c['id'];
            Audit::log('edit', 'cust', 'customer', $id, $data['name'], ['before' => $c, 'after' => $data, 'reason' => p('change_reason') ?: null, 'page_key' => $pageKey]);
        } else {
            $id = DB::insert('customers', $data);
            Audit::log('create', 'cust', 'customer', $id, $data['name'], ['snapshot' => $data, 'page_key' => $pageKey]);
        }
        flash('success', 'Customer saved.');
        redirect_after_save(url($pageKey, ['a' => 'new']), url($pageKey, ['id' => $id]));
    });
    layout_start($c ? 'Edit customer: ' . $c['name'] : 'New customer or job', ['actions' => btn_link(url($pageKey, $c ? ['id' => $c['id']] : []), 'Cancel')]);
    $parents = DB::pairs('SELECT id, name FROM customers WHERE parent_id IS NULL AND id <> :id ORDER BY name', ['id' => $c['id'] ?? 0]);
    ?>
    <form method="post" class="card"><div class="card-body">
      <?= csrf_field() ?>
      <div class="row g-2">
        <?= input_field('name', 'Customer / job name', fv('name', $c), ['required' => true]) ?>
        <?= select_field('parent_id', 'Job of (leave blank for a customer)', $parents, fv('parent_id', $c, g('parent')), ['blank' => '']) ?>
        <?= input_field('company_name', 'Company name', fv('company_name', $c)) ?>
        <?= input_field('contact_person', 'Contact person', fv('contact_person', $c)) ?>
        <?= input_field('phone', 'Phone', fv('phone', $c)) ?>
        <?= input_field('mobile', 'Mobile', fv('mobile', $c)) ?>
        <?= input_field('email', 'Email', fv('email', $c), ['type' => 'email']) ?>
        <?= input_field('tin', 'TIN', fv('tin', $c)) ?>
        <div class="col-md-4"></div>
        <div class="col-md-6"><label class="form-label">Billing address</label><textarea class="form-control form-control-sm" name="billing_address" rows="2"><?= h(fv('billing_address', $c)) ?></textarea></div>
        <div class="col-md-6"><label class="form-label">Shipping address</label><textarea class="form-control form-control-sm" name="shipping_address" rows="2"><?= h(fv('shipping_address', $c)) ?></textarea></div>
        <?= select_field('terms_id', 'Payment terms', Lookup::terms(), fv('terms_id', $c), ['blank' => '']) ?>
        <?= input_field('credit_limit', 'Credit limit (0 = none)', fv('credit_limit', $c, '0'), ['type' => 'number']) ?>
        <?= select_field('price_level_id', 'Price level', Lookup::priceLevels(), fv('price_level_id', $c), ['blank' => 'Standard']) ?>
        <?= select_field('tax_code_id', 'Tax code', Lookup::taxCodes(), fv('tax_code_id', $c), ['blank' => '']) ?>
        <?= select_field('payment_method_id', 'Preferred payment method', Lookup::paymentMethods(), fv('payment_method_id', $c), ['blank' => '']) ?>
        <?= select_field('customer_type_id', 'Customer type', Lookup::customerTypes(), fv('customer_type_id', $c), ['blank' => '']) ?>
        <?= select_field('sales_rep_id', 'Sales representative', Lookup::reps(), fv('sales_rep_id', $c), ['blank' => '']) ?>
        <?= select_field('billing_group_id', 'Billing group (batch invoicing)', DB::pairs('SELECT id, name FROM billing_groups ORDER BY name'), fv('billing_group_id', $c), ['blank' => '']) ?>
        <?php if (Withholding::ready()):
            $whtOpts = [];
            foreach (Withholding::forSide('sales') + array_intersect_key(Withholding::all(), array_flip(Withholding::defaults($c['wht_ids'] ?? ''))) as $wid => $wt) {
                $whtOpts[$wid] = Withholding::label($wt) . ($wt['atc'] ? ' (' . $wt['atc'] . ')' : '');
            }
            $whtVals = is_post() ? array_map('intval', (array)($_POST['wht_ids'] ?? [])) : Withholding::defaults($c['wht_ids'] ?? '');
        ?>
        <?= multi_check_field('wht_ids', 'Taxes this customer withholds', $whtOpts, $whtVals, ['all' => 'None withheld', 'noun' => 'taxes', 'class' => 'col-md-4', 'id' => 'custWht']) ?>
        <?php endif; ?>
        <?= check_field('allow_pos_charge', 'Approved account customer at the POS', fv('allow_pos_charge', $c, 0)) ?>
        <?= check_field('is_active', 'Active', fv('is_active', $c, 1)) ?>
        <?php if ($c): ?><?= input_field('change_reason', 'Reason for change (required for credit limit changes)', fv('change_reason'), ['class' => 'col-md-8']) ?><?php endif; ?>
        <div class="col-12"><label class="form-label">Notes</label><textarea class="form-control form-control-sm" name="notes" rows="2"><?= h(fv('notes', $c)) ?></textarea></div>
      </div>
      <div class="mt-3"><?= save_buttons('Save customer', url($pageKey), Access::can($pageKey, 'create')) ?></div>
    </div></form>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ list + detail
$q = g('q');
$show = g('show', 'active');
$where = [];
$params = [];
if ($show === 'active') {
    $where[] = 'c.is_active = 1';
} elseif ($show === 'balance') {
    $where[] = 'c.is_active = 1';
}
if ($q !== '') {
    $where[] = '(c.name LIKE :q OR c.company_name LIKE :q OR c.contact_person LIKE :q OR c.phone LIKE :q)';
    $params['q'] = "%$q%";
}
[$ain, $ap] = DB::in('ar', Accts::arIds() ?: [0]);
$list = DB::all(
    "SELECT c.*, p.name AS parent_name, ct.name AS type_name,
            (SELECT COALESCE(SUM(j.debit - j.credit),0) FROM journal_lines j WHERE j.customer_id = c.id AND j.account_id IN $ain) AS balance
       FROM customers c LEFT JOIN customers p ON p.id = c.parent_id LEFT JOIN customer_types ct ON ct.id = c.customer_type_id"
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . '
      ORDER BY COALESCE(p.name, c.name), c.parent_id IS NOT NULL, c.name',
    $params + $ap
);
if ($show === 'balance') {
    $list = array_values(array_filter($list, fn($r) => abs((float)$r['balance']) > 0.004));
}

if (g('export') === 'csv') {
    Access::require($pageKey, 'print');
    Audit::log('export', 'cust', 'list', null, 'Customer list', ['page_key' => $pageKey]);
    csv_download('customers.csv', ['Name', 'Job of', 'Company', 'Contact', 'Phone', 'Email', 'Billing address', 'Terms', 'Credit limit', 'Balance', 'Active'],
        array_map(fn($r) => [$r['name'], $r['parent_name'], $r['company_name'], $r['contact_person'], $r['phone'], $r['email'], $r['billing_address'],
            DB::value('SELECT name FROM terms WHERE id = :id', ['id' => $r['terms_id']]), $r['credit_limit'], $r['balance'], $r['is_active']], $list));
}

$selId = gint('id') ?? ($list[0]['id'] ?? null);
$sel = $selId ? DB::row('SELECT c.*, p.name AS parent_name FROM customers c LEFT JOIN customers p ON p.id = c.parent_id WHERE c.id = :id', ['id' => $selId]) : null;

$actions = page_link($pageKey, ['a' => 'new'], 'New customer / job', 'btn-primary', 'person-plus', 'create')
    . ' ' . (Access::can($pageKey, 'print') ? btn_link(current_url(['export' => 'csv']), 'Export list', 'btn-outline-secondary', 'download') : '');
layout_start('Customer Center', ['actions' => $actions]);
?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card">
      <div class="card-body pb-2">
        <form class="d-flex gap-1 align-items-center" method="get">
          <input type="hidden" name="p" value="<?= h($pageKey) ?>">
          <div class="live-search flex-grow-1"><i class="bi bi-search"></i>
            <input class="form-control form-control-sm" name="q" value="<?= h($q) ?>" placeholder="Type to find a customer or job" autocomplete="off"
                   data-live-filter="#custList .list-group-item" data-live-count="#custCount" data-live-empty="#custNone" aria-label="Find a customer"></div>
          <select class="form-select form-select-sm" name="show" data-autosubmit style="max-width:140px"><?= options(['active' => 'Active', 'all' => 'All', 'balance' => 'With balance'], $show) ?></select>
        </form>
        <div class="d-flex justify-content-between mt-1"><span class="live-count"><span id="custCount"><?= count($list) ?></span> customer(s) and job(s)</span><span class="live-count d-none d-xl-inline">Type any part of the name, company, contact or phone</span></div>
      </div>
      <div class="list-group list-group-flush center-list" id="custList" style="max-height:70vh;overflow:auto">
        <?php foreach ($list as $r): ?>
          <a class="list-group-item list-group-item-action d-flex justify-content-between <?= (int)$r['id'] === (int)$selId ? 'active' : '' ?> <?= $r['parent_id'] ? 'ps-4' : '' ?>" href="<?= h(url($pageKey, ['id' => $r['id'], 'show' => $show])) ?>"
             data-filter-text="<?= h(($r['parent_name'] ? $r['parent_name'] . ' ' : '') . $r['name'] . ' ' . $r['company_name'] . ' ' . $r['contact_person'] . ' ' . $r['phone'] . ' ' . $r['mobile']) ?>">
            <span><?= $r['parent_id'] ? '<i class="bi bi-arrow-return-right"></i> ' : '' ?><?= h($r['name']) ?><?= (int)$r['is_active'] ? '' : ' <span class="badge text-bg-secondary">inactive</span>' ?></span>
            <span class="num"><?= money($r['balance'], true) ?></span>
          </a>
        <?php endforeach; ?>
        <div class="list-group-item text-muted small" id="custNone">No customer matches. Press Enter to search the whole list on the server.</div>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
  <?php if ($sel):
      $bal = Balances::customerBalance((int)$sel['id'], !$sel['parent_id']);
      $txFrom = gdate('from', date('Y-01-01', strtotime('-1 year')));
      $txType = g('ttype');
      $ids = [(int)$sel['id']];
      if (!$sel['parent_id']) {
          $ids = array_merge($ids, array_map('intval', DB::column('SELECT id FROM customers WHERE parent_id = :c', ['c' => $sel['id']])));
      }
      [$cin, $cp] = DB::in('cc', $ids);
      $tsql = "SELECT t.*, c.name AS cname FROM transactions t JOIN customers c ON c.id = t.customer_id WHERE t.customer_id IN $cin AND t.txn_date >= :f";
      $tp = $cp + ['f' => $txFrom];
      if ($txType) {
          $tsql .= ' AND t.txn_type = :tt';
          $tp['tt'] = $txType;
      }
      $txns = DB::all($tsql . ' ORDER BY t.txn_date DESC, t.id DESC LIMIT 300', $tp);
      $notes = DB::all("SELECT n.*, u.username FROM entity_notes n LEFT JOIN users u ON u.id = n.created_by WHERE n.entity_type = 'customer' AND n.entity_id = :id ORDER BY n.is_done, n.id DESC", ['id' => $sel['id']]);
      $credits = Balances::customerCredits((int)$sel['id']);
  ?>
    <div class="card mb-3"><div class="card-body">
      <div class="d-flex justify-content-between flex-wrap gap-2">
        <div>
          <h2 class="h5 mb-0"><?= h(($sel['parent_name'] ? $sel['parent_name'] . ':' : '') . $sel['name']) ?></h2>
          <div class="small text-muted"><?= h($sel['company_name']) ?> <?= $sel['tin'] ? ', TIN ' . h($sel['tin']) : '' ?></div>
          <div class="small"><?= h($sel['contact_person']) ?> <?= h($sel['phone']) ?> <?= h($sel['mobile']) ?> <?= h($sel['email']) ?></div>
          <div class="small text-muted"><?= h($sel['billing_address']) ?></div>
        </div>
        <div class="text-end">
          <div class="small text-muted">Open balance</div>
          <div class="fs-4 fw-bold"><?= money($bal) ?></div>
          <div class="small">Credit limit: <?= (float)$sel['credit_limit'] > 0 ? money($sel['credit_limit']) : 'none' ?></div>
          <?php if ($credits): ?><div class="small text-success">Available credits: <?= money(array_sum(array_column($credits, 'remaining'))) ?></div><?php endif; ?>
        </div>
      </div>
      <div class="row small mt-2 g-1">
        <div class="col-sm-4">Terms: <?= h(DB::value('SELECT name FROM terms WHERE id = :id', ['id' => $sel['terms_id']])) ?></div>
        <div class="col-sm-4">Price level: <?= h(DB::value('SELECT name FROM price_levels WHERE id = :id', ['id' => $sel['price_level_id']]) ?: 'Standard') ?></div>
        <div class="col-sm-4">Tax: <?= h(Lookup::taxCodes()[$sel['tax_code_id']] ?? '') ?></div>
        <div class="col-sm-4">Rep: <?= h(DB::value('SELECT name FROM sales_reps WHERE id = :id', ['id' => $sel['sales_rep_id']])) ?></div>
        <div class="col-sm-4">Type: <?= h(DB::value('SELECT name FROM customer_types WHERE id = :id', ['id' => $sel['customer_type_id']])) ?></div>
        <div class="col-sm-4">Pays by: <?= h(DB::value('SELECT name FROM payment_methods WHERE id = :id', ['id' => $sel['payment_method_id']])) ?></div>
      </div>
      <div class="d-flex flex-wrap gap-1 mt-3">
        <?= page_link($pageKey, ['a' => 'edit', 'id' => $sel['id']], 'Edit', 'btn-outline-primary', 'pencil', 'edit') ?>
        <?= !$sel['parent_id'] ? page_link($pageKey, ['a' => 'new', 'parent' => $sel['id']], 'Add job', 'btn-outline-primary', 'plus', 'create') : '' ?>
        <?php foreach ([
            ['cust.estimates', 'Estimate'], ['cust.sales_orders', 'Sales order'], ['cust.invoices', 'Invoice'], ['cust.sales_receipts', 'Sales receipt'],
            ['cust.statement_charges', 'Statement charge'], ['cust.credit_memos', 'Credit memo'],
        ] as [$pk, $lbl]): ?>
          <?= page_link($pk, ['a' => 'new', 'customer_id' => $sel['id']], $lbl, 'btn-outline-success', 'plus', 'create') ?>
        <?php endforeach; ?>
        <?= page_link('cust.payments', ['customer_id' => $sel['id']], 'Receive payment', 'btn-outline-success', 'cash', 'create') ?>
        <?= $credits ? page_link('cust.payments', ['customer_id' => $sel['id'], 'credits' => 1], 'Apply credits to invoices', 'btn-outline-success', 'check2-square', 'create') : '' ?>
        <?= page_link('rpt.customer_balance_detail', ['customer_id' => $sel['id']], 'Balance detail', 'btn-outline-secondary', 'bar-chart') ?>
        <?= page_link('rpt.txn_list_customer', ['customer_id' => $sel['id']], 'Transaction list', 'btn-outline-secondary', 'list-ul') ?>
        <?= page_link('cust.statements', ['customer_id' => $sel['id']], 'Statement', 'btn-outline-secondary', 'file-text') ?>
      </div>
    </div></div>

    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Transactions</span>
        <form class="d-flex gap-1" method="get">
          <input type="hidden" name="p" value="<?= h($pageKey) ?>"><input type="hidden" name="id" value="<?= (int)$sel['id'] ?>">
          <select class="form-select form-select-sm" name="ttype" data-autosubmit><?= options(['estimate' => 'Estimates', 'sales_order' => 'Sales orders', 'invoice' => 'Invoices', 'sales_receipt' => 'Sales receipts', 'payment' => 'Payments', 'credit_memo' => 'Credit memos', 'statement_charge' => 'Statement charges', 'finance_charge' => 'Finance charges', 'refund' => 'Refunds'], $txType, 'All types') ?></select>
          <input class="form-control form-control-sm" type="date" name="from" value="<?= h($txFrom) ?>" data-autosubmit>
        </form>
      </div>
      <div class="table-responsive" style="max-height:420px">
      <table class="table table-sm table-hover mb-0">
        <thead><tr><th>Type</th><th>No.</th><th>Date</th><th>Job</th><th class="num">Amount</th><th class="num">Open</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($txns as $t):
            $open = '';
            if ((int)$t['is_voided'] === 0 && in_array($t['txn_type'], Balances::RECEIVABLE_DOCS, true)) {
                $open = money(Balances::open($t));
            } elseif ((int)$t['is_voided'] === 0 && in_array($t['txn_type'], Balances::CUSTOMER_CREDITS, true)) {
                $open = money(Balances::creditRemaining($t));
            }
        ?>
          <tr><td><?= h(Txn::label($t['txn_type'])) ?></td><td><?= Txn::link($t) ?></td><td><?= h(fdate($t['txn_date'])) ?></td>
            <td class="small"><?= (int)$t['customer_id'] !== (int)$sel['id'] ? h($t['cname']) : '' ?></td>
            <td class="num"><?= money($t['total']) ?></td><td class="num"><?= $open ?></td>
            <td><?= status_badge((int)$t['is_pending'] ? 'pending' : $t['status']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$txns): ?><tr><td colspan="7" class="text-muted text-center">No transactions.</td></tr><?php endif; ?>
        </tbody>
      </table>
      </div>
    </div>

    <div class="card">
      <div class="card-header fw-semibold">Notes and to-do reminders</div>
      <div class="card-body">
        <?php if ($sel['notes']): ?><p class="small"><?= nl2br(h($sel['notes'])) ?></p><?php endif; ?>
        <?php foreach ($notes as $n): ?>
          <div class="d-flex justify-content-between border-bottom py-1 small <?= (int)$n['is_done'] ? 'text-muted text-decoration-line-through' : '' ?>">
            <span><?= (int)$n['is_todo'] ? '<i class="bi bi-check2-square"></i> ' : '<i class="bi bi-sticky"></i> ' ?><?= h($n['note']) ?> <?= $n['due_date'] ? '(' . h(fdate($n['due_date'])) . ')' : '' ?> <span class="text-muted">— <?= h($n['username']) ?>, <?= h(fdatetime($n['created_at'])) ?></span></span>
            <?php if ((int)$n['is_todo'] && !(int)$n['is_done'] && Access::can($pageKey, 'edit')): ?>
              <form method="post" action="<?= h(url($pageKey, ['a' => 'note_done', 'note' => $n['id']])) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-link p-0">Done</button></form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if (Access::can($pageKey, 'edit')): ?>
        <form method="post" action="<?= h(url($pageKey, ['a' => 'note'])) ?>" class="row g-2 mt-2">
          <?= csrf_field() ?><input type="hidden" name="entity_id" value="<?= (int)$sel['id'] ?>">
          <div class="col-md-6"><input class="form-control form-control-sm" name="note" placeholder="Add a note or reminder" required></div>
          <div class="col-md-2"><input class="form-control form-control-sm" type="date" name="due_date"></div>
          <div class="col-md-2 form-check d-flex align-items-center gap-1"><input class="form-check-input" type="checkbox" name="is_todo" value="1" id="todo"><label for="todo" class="small">To-do</label></div>
          <div class="col-md-2"><button class="btn btn-sm btn-outline-primary w-100">Add</button></div>
        </form>
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>
    <p class="text-muted">No customers yet.</p>
  <?php endif; ?>
  </div>
</div>
<?php
layout_end();
