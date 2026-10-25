<?php
declare(strict_types=1);

/**
 * Cash Voucher: other cash transactions such as revolving fund and petty cash fund payments.
 *
 *  Entry  : CAV entry no., paid to (employee, customer, supplier or other name), cash account
 *           (chart of accounts, cash and bank type), transaction date, cash amount, class, and the
 *           account lines (account, debit, credit, memo, SL = subsidiary ledger name) with totals.
 *  Posting: the account lines as entered, and one credit to the cash account for the cash amount.
 *  List   : saved voucher entries for a date range, with Edit, Print, Void and Delete.
 */
require_once APP_ROOT . '/modules/txn/_common.php';
require_once APP_ROOT . '/modules/txn/_grids.php';
require_once APP_ROOT . '/modules/txn/_memorize.php';

$pageKey = 'bank.cash_voucher';
$type = Posting::CASH_VOUCHER;

if (!Classes::ready()) {
    layout_start('Cash Voucher');
    echo '<div class="alert alert-warning">Cash vouchers need a database update. Import <code>database/migrations/2026_09_19_classes_cash_voucher.sql</code> (phpMyAdmin or Navicat), then reload this page.</div>';
    layout_end();
    return;
}
if (txn_common_actions($pageKey, [$type])) {
    return;
}

$a = g('a', 'new');
$t = null;
$lines = [];
if ($a === 'edit') {
    Access::require($pageKey, 'edit');
    $t = Txn::load((int)g('id'));
    if (!$t || $t['txn_type'] !== $type) {
        flash('warning', 'Cash voucher not found.');
        redirect(url($pageKey));
    }
    if ((int)$t['is_voided'] === 1) {
        flash('warning', 'A voided cash voucher cannot be edited.');
        redirect(Txn::url($t));
    }
    $lines = Txn::lines((int)$t['id']);
}
$canSave = $t ? Access::can($pageKey, 'edit') : Access::can($pageKey, 'create');

// ---------------------------------------------------------------------- save

handle_post(function () use ($t, $pageKey, $type) {
    Access::require($pageKey, $t ? 'edit' : 'create');
    $lines = parse_journal_lines(grid_rows('lines'));
    if (!$lines) {
        fail('Enter at least one account line.');
    }
    $cash = pint('account_id');
    if (!$cash || !isset(Accts::ofType(['Bank'])[$cash])) {
        fail('Choose the cash account the voucher is paid from.');
    }
    $dr = 0.0;
    $cr = 0.0;
    foreach ($lines as $l) {
        if ((int)$l['account_id'] === $cash) {
            fail('The cash account is credited automatically for the cash amount. Do not enter it again in the account lines.');
        }
        if ($l['amount'] > 0) {
            $dr += $l['amount'];
        } else {
            $cr -= $l['amount'];
        }
        if (in_array($l['account_id'], Accts::arIds(), true) && !$l['customer_id']) {
            fail('Lines posted to Accounts Receivable need a customer in the SL column.');
        }
        if (in_array($l['account_id'], Accts::apIds(), true) && !$l['vendor_id']) {
            fail('Lines posted to Accounts Payable need a supplier in the SL column.');
        }
    }
    $net = r2($dr - $cr);
    $amount = p('cash_amount') === '' ? $net : r2(pnum('cash_amount'));
    if ($amount <= 0) {
        fail('The cash amount must be more than zero.');
    }
    if (abs($net - $amount) >= 0.01) {
        fail('Debits less credits (' . money($net) . ') must equal the cash amount (' . money($amount) . ').');
    }
    $who = Names::parse((string)p('payee'));
    $payeeName = mb_substr(trim((string)p('payee_name')), 0, 120);
    if (!$who['customer_id'] && !$who['vendor_id'] && !$who['other_name_id'] && $payeeName === '') {
        fail('Choose who the cash is paid to, or type the name.');
    }
    // accounts marked "With SL" keep a listing of names: the line's own name, or the payee of the voucher
    Accts::requireSl($lines, $who);
    $approver = Approval::check($pageKey, Approval::exceeds(Access::limit('max_amount'), $amount), 'cash voucher above your amount limit (' . money($amount) . ')');
    $h = [
        'txn_date'      => pdate('txn_date'),
        'account_id'    => $cash,
        'customer_id'   => $who['customer_id'],
        'vendor_id'     => $who['vendor_id'],
        'other_name_id' => $who['other_name_id'],
        'payee_name'    => ($who['customer_id'] || $who['vendor_id'] || $who['other_name_id']) ? null : $payeeName,
        'ref_no'        => mb_substr(trim((string)p('ref_no')), 0, 60) ?: null,
        'memo'          => mb_substr(trim((string)p('memo')), 0, 255) ?: null,
        'class_id'      => Classes::posted(),
        'subtotal'      => $amount,
        'total'         => $amount,
        'status'        => 'open',
    ];
    if (p('txn_no') !== '') {
        $no = mb_substr((string)p('txn_no'), 0, 30);
        if (DB::value('SELECT id FROM transactions WHERE txn_type = :ty AND txn_no = :n AND id <> :id', ['ty' => $type, 'n' => $no, 'id' => $t['id'] ?? 0])) {
            fail('CAV entry no. ' . $no . ' is already used. Leave the number blank to get the next one.');
        }
        $h['txn_no'] = $no;
    }
    if ($approver) {
        $h['approved_by'] = $approver['id'];
    }
    $id = Txn::save($type, $h, $lines, $t ? (int)$t['id'] : null, [
        'keep_totals' => true, 'reason' => p('reason') ?: null, 'approved_by' => $approver['username'] ?? null,
    ]);
    $saved = Txn::load($id);
    flash('success', Txn::ref($saved) . ' saved: ' . money($amount) . ' paid out of ' . account_label($cash) . '.');
    $_SESSION['cav_preview'] = p('show_preview') === '1' ? 1 : 0;
    if (p('show_preview') === '1' && Access::can($pageKey, 'print') && !wants_new()) {
        redirect(url($pageKey, ['a' => 'print', 'id' => $id]));
    }
    redirect(url($pageKey));
});

if (is_post()) {
    $lines = journal_rows_for_redisplay(grid_rows('lines'));
}

// ---------------------------------------------------------------------- list of saved entries

$presets = ['today' => 'Today', 'this_week' => 'This week', 'this_month' => 'This month', 'last_month' => 'Last month',
    'this_quarter' => 'This quarter', 'this_year' => 'This fiscal year', 'all' => 'All dates', 'custom' => 'Custom'];
$range = array_key_exists(g('range'), $presets) ? g('range') : 'this_month';
[$pf, $pt] = Report::preset($range === 'custom' ? 'this_month' : $range);
$from = $range === 'custom' ? gdate('from', $pf) : $pf;
$to = $range === 'custom' ? gdate('to', $pt) : $pt;
$listClass = gint('list_class');
$where = 't.txn_type = :ty AND t.txn_date BETWEEN :f AND :t';
$params = ['ty' => $type, 'f' => $from, 't' => $to];
if ($listClass) {
    [$csql, $cp] = Classes::sqlFilter($listClass, 't', 'lc');
    $where .= $csql;
    $params += $cp;
}
$vouchers = DB::all(
    "SELECT t.*, a.code AS cash_code, a.name AS cash_name, cl.name AS class_name, u.username
       FROM transactions t
       LEFT JOIN accounts a ON a.id = t.account_id
       LEFT JOIN classes cl ON cl.id = t.class_id
       LEFT JOIN users u ON u.id = t.created_by
      WHERE $where ORDER BY t.txn_date DESC, t.id DESC LIMIT 500",
    $params
);
$listRows = [];
$listTotal = 0.0;
foreach ($vouchers as $v) {
    $payee = entity_name($v);
    foreach (Txn::lines((int)$v['id']) as $l) {
        $listRows[] = ['v' => $v, 'account' => $l['account_code'] . ' - ' . $l['account_name'], 'sl' => line_name($l), 'memo' => (string)$l['description'],
            'class' => (string)($l['class_name'] ?: $v['class_name']), 'amount' => (float)$l['amount']];
    }
    $listRows[] = ['v' => $v, 'account' => $v['cash_code'] . ' - ' . $v['cash_name'], 'sl' => $payee, 'memo' => (string)$v['memo'],
        'class' => (string)$v['class_name'], 'amount' => -(float)$v['total'], 'cash' => true];
    if ((int)$v['is_voided'] === 0) {
        $listTotal += (float)$v['total'];
    }
}
if (g('export') === 'csv' && Access::can($pageKey, 'print')) {
    Audit::log('export', 'bank', 'list', null, 'Cash vouchers', ['page_key' => $pageKey]);
    csv_download('cash_vouchers.csv', ['Date', 'Entry no', 'Paid to', 'Account', 'Subsidiary ledger', 'Memo', 'Class', 'Debit/Credit (+/-)', 'Status'],
        array_map(fn($r) => [$r['v']['txn_date'], $r['v']['txn_no'], entity_name($r['v']), $r['account'], $r['sl'], $r['memo'], $r['class'], $r['amount'],
            (int)$r['v']['is_voided'] ? 'void' : 'posted'], $listRows));
}

// ---------------------------------------------------------------------- screen

$cashAccounts = Accts::ofType(['Bank']);
if ($t && $t['account_id'] && !isset($cashAccounts[(int)$t['account_id']])) {
    $cashAccounts[(int)$t['account_id']] = account_label((int)$t['account_id']);
}
// a new voucher filled from a memorized one ("Load Memorized Transaction")
$mem = (!$t && !is_post() && gint('mem')) ? Memorized::forForm((int)gint('mem'), [$type]) : null;
if ($mem) {
    $lines = $mem['lines'];
}
$rec = $t ?: ($mem ? $mem['header'] + ['cash_amount' => number_format((float)($mem['header']['total'] ?? 0), 2, '.', '')] : null);
$payeeSel = is_post() ? (string)p('payee') : ($rec ? Names::code($rec) : '');
$previewOn = is_post() ? p('show_preview') === '1' : (int)($_SESSION['cav_preview'] ?? 1) === 1;
$actions = ($t ? btn_link(url($pageKey), 'New voucher', 'btn-primary', 'plus-lg') . ' ' : '')
    . page_link('coa.names', [], 'Employees & other names', 'btn-outline-secondary', 'person-lines-fill')
    . (Access::can($pageKey, 'print') ? ' ' . btn_link(current_url(['export' => 'csv']), 'Export list', 'btn-outline-secondary', 'download') : '');

[$memButton, $memBox] = memorize_controls($type, trim(Memorized::entityLabel($rec ?? []) . ' cash voucher'));
[$loadButton, $loadList] = !$t ? memorized_loader([$type], $pageKey) : ['', ''];
layout_start($t ? 'Edit ' . Txn::ref($t) : 'Cash Voucher', ['actions' => $loadButton . ' ' . $actions]);
echo memorized_loaded_note($mem);
?>
<form method="post" id="cavForm" autocomplete="off"><?= csrf_field() ?>
<fieldset <?= $canSave ? '' : 'disabled' ?>>
<div class="card mb-3">
  <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Cash voucher entry</span>
    <span class="small text-muted fw-normal">Revolving fund, petty cash fund and other cash payments</span></div>
  <div class="card-body">
    <div class="row g-2">
      <?= input_field('txn_no', 'CAV entry no.', fv('txn_no', $t, ''), ['class' => 'col-md-2', 'extra' => 'placeholder="' . h($t ? '' : Seq::peek('cash_voucher') . ' (auto)') . '"']) ?>
      <?= select_field('payee', 'Paid to', combo_name_options(Names::options($payeeSel ?: null)), $payeeSel, ['blank' => '', 'class' => 'col-md-4', 'extra' => 'id="cavPayee" data-combo data-combo-placeholder="Type a name: employee, customer, supplier or other"']) ?>
      <?= select_field('account_id', 'Cash account', combo_account_options($cashAccounts), fv('account_id', $rec, ''), ['blank' => '', 'class' => 'col-md-3', 'required' => true, 'extra' => 'data-combo data-combo-placeholder="Type the cash account name"']) ?>
      <?= input_field('txn_date', 'Transaction date', fv('txn_date', $t, today()), ['type' => 'date', 'class' => 'col-md-3', 'required' => true]) ?>

      <?= input_field('payee_name', 'or a name that is not on the lists', fv('payee_name', $rec), ['class' => 'col-md-3', 'extra' => 'maxlength="120" id="cavPayeeName"']) ?>
      <?= input_field('ref_no', 'Reference no.', fv('ref_no', $rec), ['class' => 'col-md-2']) ?>
      <?= input_field('memo', 'Particulars', fv('memo', $rec), ['class' => 'col-md-4', 'extra' => 'maxlength="255"']) ?>
      <?= input_field('cash_amount', 'Cash amount', fv('cash_amount', $t ? ['cash_amount' => number_format((float)$t['total'], 2, '.', '')] : $rec, ''), ['class' => 'col-md-3', 'extra' => 'id="cavAmount" inputmode="decimal" placeholder="adds up from the lines"']) ?>
    </div>
  </div>
  <div class="card-body border-top pt-3">
    <?= journal_grid($lines, ['id' => 'cavGrid', 'name_label' => 'SL', 'total_group' => 'cav', 'min_rows' => 3,
        'foot_extra' => 'Cash account credited: <span id="cavCash">0.00</span><span class="ms-3">Difference: <span id="cavDiff">0.00</span></span>']) ?>
    <p class="small text-muted mb-0 mt-2">Accounts come from the chart of accounts. SL is the subsidiary ledger of the line: an employee, customer, supplier or other name.
      The cash account is credited for the cash amount when the voucher is saved, so debits less credits must equal the cash amount.</p>
  </div>
  <div class="card-body border-top">
    <div class="row g-2 align-items-end">
      <?= Classes::field(fv('class_id', $rec), 'col-md-3') ?>
      <?php if ($t): ?><?= input_field('reason', 'Reason for the change', fv('reason'), ['class' => 'col-md-4']) ?><?php endif; ?>
      <div class="col-md-auto"><div class="form-check mb-1"><input type="hidden" name="show_preview" value="0">
        <input class="form-check-input" type="checkbox" id="cavPreview" name="show_preview" value="1" <?= checked($previewOn) ?>>
        <label class="form-check-label" for="cavPreview">Show print preview after saving</label></div></div>
      <div class="col-md d-flex flex-wrap justify-content-md-end gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= h(url($pageKey)) ?>"><i class="bi bi-eraser"></i> Clear</a>
        <?php if ($canSave): ?>
          <button class="btn btn-primary btn-sm px-4" name="save" value="1"><i class="bi bi-check-lg"></i> Save</button>
          <?php if (!$t && Access::can($pageKey, 'create')): ?><button class="btn btn-outline-primary btn-sm" name="save_new" value="1" title="Save this voucher and start another"><i class="bi bi-plus-lg"></i> Save &amp; New</button><?php endif; ?>
        <?php endif; ?>
        <?= $memButton ?>
      </div>
    </div>
    <?= Approval::fields() ?>
  </div>
</div>
</fieldset>
<?= $memBox ?>
</form>
<?= $loadList ?>

<div class="card mb-3" id="cavList">
  <div class="card-header fw-semibold">Cash voucher list</div>
  <div class="card-body pb-2">
    <form method="get" class="row g-2 align-items-end filters" action="<?= h(base_url() . '/index.php') ?>#cavList">
      <input type="hidden" name="p" value="<?= h($pageKey) ?>">
      <?php if ($t): ?><input type="hidden" name="a" value="edit"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><?php endif; ?>
      <div class="col-md-3"><label class="form-label">Date range</label><select class="form-select form-select-sm" name="range" id="cavRange"><?= options($presets, $range) ?></select></div>
      <div class="col-md-2"><label class="form-label">From</label><input class="form-control form-control-sm" type="date" name="from" id="cavFrom" value="<?= h($from) ?>"></div>
      <div class="col-md-2"><label class="form-label">To</label><input class="form-control form-control-sm" type="date" name="to" id="cavTo" value="<?= h($to) ?>"></div>
      <?php if (Classes::inUse()): ?><div class="col-md-3"><label class="form-label">Class</label><select class="form-select form-select-sm" name="list_class"><?= options(Classes::filterOptions(), $listClass, 'All classes') ?></select></div><?php endif; ?>
      <div class="col-md-2"><button class="btn btn-sm btn-secondary w-100"><i class="bi bi-search"></i> Query</button></div>
    </form>
  </div>
  <div class="table-responsive" style="max-height:420px">
  <table class="table table-sm table-hover mb-0" id="cavTable">
    <thead class="sticky-top"><tr><th>Date</th><th>Entry no</th><th>Paid to</th><th>Account</th><th>Subsidiary ledger</th><th>Memo</th><th>Class</th><th class="num">Debit/Credit (+/-)</th></tr></thead>
    <tbody>
    <?php if (!$listRows): ?><tr><td colspan="8" class="text-center text-muted py-3">No cash vouchers from <?= h(fdate($from)) ?> to <?= h(fdate($to)) ?>.</td></tr><?php endif; ?>
    <?php $prev = null; foreach ($listRows as $r): $v = $r['v']; $first = $prev !== (int)$v['id']; $prev = (int)$v['id']; ?>
      <tr class="cav-row <?= $first ? 'cav-first' : '' ?> <?= (int)$v['is_voided'] ? 'text-muted text-decoration-line-through' : '' ?> <?= $t && (int)$t['id'] === (int)$v['id'] ? 'table-active' : '' ?>"
          data-id="<?= (int)$v['id'] ?>" data-no="<?= h($v['txn_no']) ?>" data-void="<?= (int)$v['is_voided'] ?>">
        <td><?= $first ? h(fdate($v['txn_date'])) : '' ?></td>
        <td><?= $first ? '<a href="' . h(url($pageKey, ['a' => 'view', 'id' => $v['id']])) . '">' . h($v['txn_no']) . '</a>' : '' ?></td>
        <td class="small"><?= $first ? h(entity_name($v)) : '' ?></td>
        <td class="<?= !empty($r['cash']) ? 'text-muted' : '' ?>"><?= h($r['account']) ?></td>
        <td class="small"><?= h($r['sl']) ?></td>
        <td class="small"><?= h(mb_strimwidth($r['memo'], 0, 46, '…')) ?></td>
        <td class="small"><?= h($r['class']) ?></td>
        <td class="num <?= $r['amount'] < 0 ? 'neg' : '' ?>"><?= money($r['amount']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2 border-top">
    <div class="small text-muted"><?= count($vouchers) ?> voucher(s), cash paid out <strong class="text-body"><?= money($listTotal) ?></strong>.
      <span id="cavPicked">Click an entry to select it.</span></div>
    <div class="d-flex gap-2">
      <?php if (Access::can($pageKey, 'edit')): ?><button type="button" class="btn btn-sm btn-outline-primary" data-cav="edit" disabled><i class="bi bi-pencil"></i> Edit</button><?php endif; ?>
      <?php if (Access::can($pageKey, 'delete')): ?>
        <button type="button" class="btn btn-sm btn-outline-danger" data-cav="void" disabled><i class="bi bi-slash-circle"></i> Void</button>
        <button type="button" class="btn btn-sm btn-outline-danger" data-cav="delete" disabled><i class="bi bi-trash"></i> Delete</button>
      <?php endif; ?>
      <?php if (Access::can($pageKey, 'print')): ?><button type="button" class="btn btn-sm btn-outline-secondary" data-cav="print" disabled><i class="bi bi-printer"></i> Print</button><?php endif; ?>
    </div>
  </div>
</div>
<?php
if (Access::can($pageKey, 'delete')) {
    echo reason_modal('cavVoidModal', 'Void cash voucher', url($pageKey, ['a' => 'void', 'id' => 0]), 'btn-warning',
        '<p class="small text-muted mt-2">Voiding keeps the voucher in the list but removes its effect on the ledger.</p>');
    echo reason_modal('cavDeleteModal', 'Delete cash voucher', url($pageKey, ['a' => 'delete', 'id' => 0]), 'btn-danger',
        '<p class="small text-muted mt-2">The voucher is removed; its full content stays in the audit trail.</p>');
}
?>
<script>
(function () {
  const { num, r2, fmt } = AppFmt;

  // ---------------------------------------------------------------- entry: totals follow the lines
  const grid = document.getElementById('cavGrid'), amount = document.getElementById('cavAmount');
  let typed = amount && amount.value.trim() !== '';
  function totals() {
    if (!grid) return;
    let dr = 0, cr = 0;
    grid.querySelectorAll('tbody tr').forEach((tr) => {
      dr += num(tr.querySelector('[data-f="debit"]').value);
      cr += num(tr.querySelector('[data-f="credit"]').value);
    });
    const net = r2(dr - cr);
    if (!typed) amount.value = net > 0 ? net.toFixed(2) : '';
    const cash = num(amount.value), diff = r2(net - cash);
    document.getElementById('cavCash').textContent = fmt(cash);
    const d = document.getElementById('cavDiff');
    d.textContent = fmt(diff);
    d.classList.toggle('text-danger', Math.abs(diff) >= 0.005);
    d.classList.toggle('text-success', Math.abs(diff) < 0.005 && cash > 0);
  }
  if (grid && amount) {
    grid.addEventListener('input', totals);
    grid.addEventListener('change', totals);
    new MutationObserver(totals).observe(grid.querySelector('tbody'), { childList: true });
    amount.addEventListener('input', () => { typed = amount.value.trim() !== ''; totals(); });
    totals();
  }
  // a name from the lists and a typed name exclude each other
  const payee = document.getElementById('cavPayee'), payeeName = document.getElementById('cavPayeeName');
  if (payee && payeeName) {
    const sync = () => { payeeName.disabled = payee.value !== ''; if (payee.value !== '') payeeName.value = ''; };
    payee.addEventListener('change', sync);
    sync();
  }

  // ---------------------------------------------------------------- list: date range and the selected entry
  const range = document.getElementById('cavRange'), from = document.getElementById('cavFrom'), to = document.getElementById('cavTo');
  if (range) {
    const lock = () => { const custom = range.value === 'custom'; from.readOnly = !custom; to.readOnly = !custom; };
    range.addEventListener('change', () => { lock(); if (range.value !== 'custom') range.form.submit(); });
    [from, to].forEach((i) => i.addEventListener('focus', () => { if (range.value !== 'custom') { range.value = 'custom'; lock(); } }));
    lock();
  }
  const base = <?= json_encode(url($pageKey)) ?>;
  const buttons = Array.from(document.querySelectorAll('[data-cav]'));
  let picked = null;
  function pick(id) {
    picked = null;
    document.querySelectorAll('#cavTable tr.cav-row').forEach((tr) => {
      const on = tr.dataset.id === String(id);
      tr.classList.toggle('table-primary', on);
      if (on) picked = tr;
    });
    const isVoid = picked && picked.dataset.void === '1';
    buttons.forEach((b) => { b.disabled = !picked || (isVoid && (b.dataset.cav === 'edit' || b.dataset.cav === 'void')); });
    const label = document.getElementById('cavPicked');
    if (label) label.textContent = picked ? 'Selected: ' + picked.dataset.no + (isVoid ? ' (void)' : '') : 'Click an entry to select it.';
  }
  document.querySelectorAll('#cavTable tr.cav-row').forEach((tr) => {
    tr.addEventListener('click', (e) => { if (!e.target.closest('a')) pick(tr.dataset.id); });
    tr.addEventListener('dblclick', () => { window.location = base + '&a=view&id=' + tr.dataset.id; });
  });
  buttons.forEach((b) => b.addEventListener('click', () => {
    if (!picked) return;
    const id = picked.dataset.id, what = b.dataset.cav;
    if (what === 'edit' || what === 'print') { window.location = base + '&a=' + what + '&id=' + id; return; }
    const modal = document.getElementById(what === 'void' ? 'cavVoidModal' : 'cavDeleteModal');
    if (!modal) return;
    const form = modal.querySelector('form');
    form.action = base + '&a=' + what + '&id=' + id;
    modal.querySelector('.modal-title').textContent = (what === 'void' ? 'Void ' : 'Delete ') + picked.dataset.no;
    bootstrap.Modal.getOrCreateInstance(modal).show();
  }));
})();
</script>
<?php
layout_end();
