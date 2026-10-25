<?php
declare(strict_types=1);

require_once APP_ROOT . '/modules/txn/_common.php';

$pageKey = 'bank.register';
$accounts = Accts::ofType(['Bank', 'Accounts Receivable', 'Accounts Payable', 'Credit Card', 'Other Current Asset', 'Other Current Liability', 'Fixed Asset', 'Long Term Liability', 'Equity', 'Other Asset']);
$accountId = (int)(g('account_id') ?: DB::value("SELECT id FROM accounts WHERE code = '1010'") ?: array_key_first($accounts));
$acct = DB::row('SELECT * FROM accounts WHERE id = :id', ['id' => $accountId]);
$from = gdate('from', date('Y-m-01', strtotime('-2 months')));
$to = gdate('to', date('Y-m-d', strtotime('+30 days')));
$q = g('q');

// quick entry directly in the register
handle_post(function () use ($acct, $pageKey) {
    $kind = p('kind');
    $date = pdate('txn_date');
    $amount = r2(pnum('amount'));
    $other = pint('other_account_id');
    if (!$date || $amount <= 0 || !$other) {
        fail('Enter the date, a positive amount and the other account.');
    }
    if ($acct['account_type'] !== 'Bank') {
        fail('Quick entry is available for bank and cash accounts only.');
    }
    $memo = p('memo') ?: null;
    $payee = p('payee') ?: null;
    if ($kind === 'check') {
        Access::require('bank.write_checks', 'create');
        $approver = Approval::check('bank.write_checks', Approval::exceeds(Access::limit('max_amount'), $amount), 'check above your amount limit');
        $id = Txn::save('check', ['txn_date' => $date, 'account_id' => $acct['id'], 'payee_name' => $payee, 'txn_no' => p('number') ?: null, 'memo' => $memo, 'approved_by' => $approver['id'] ?? null],
            [['line_kind' => 'expense', 'account_id' => $other, 'amount' => $amount, 'description' => $memo]], null, ['approved_by' => $approver['username'] ?? null]);
    } elseif ($kind === 'deposit') {
        Access::require('bank.deposits', 'create');
        $id = Txn::save('deposit', ['txn_date' => $date, 'account_id' => $acct['id'], 'memo' => $memo, 'payee_name' => $payee, 'ref_no' => p('number') ?: null],
            [['line_kind' => 'deposit', 'account_id' => $other, 'amount' => $amount, 'description' => $memo ?: $payee]]);
    } elseif ($kind === 'transfer') {
        Access::require('bank.transfers', 'create');
        $id = Txn::save('transfer', ['txn_date' => $date, 'account_id' => $acct['id'], 'account2_id' => $other, 'total' => $amount, 'memo' => $memo], [], null, ['keep_totals' => true]);
    } else {
        fail('Choose what to enter.');
    }
    flash('success', 'Entry recorded.');
    redirect(url($pageKey, ['account_id' => $acct['id']]));
});

$opening = (float)DB::value('SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE account_id = :a AND txn_date < :f', ['a' => $accountId, 'f' => $from]);
$sql = "SELECT j.txn_id, j.txn_date, SUM(j.debit) AS debit, SUM(j.credit) AS credit, MAX(j.cleared) AS cleared,
               t.txn_type, t.txn_no, t.ref_no, t.memo, COALESCE(c.name, v.name, o.name, t.payee_name) AS party
          FROM journal_lines j JOIN transactions t ON t.id = j.txn_id
          LEFT JOIN customers c ON c.id = t.customer_id LEFT JOIN vendors v ON v.id = t.vendor_id
          LEFT JOIN other_names o ON o.id = t.other_name_id
         WHERE j.account_id = :a AND j.txn_date BETWEEN :f AND :t";
$params = ['a' => $accountId, 'f' => $from, 't' => $to];
if ($q !== '') {
    $sql .= ' AND (t.txn_no LIKE :q OR t.memo LIKE :q OR c.name LIKE :q OR v.name LIKE :q OR o.name LIKE :q OR t.payee_name LIKE :q)';
    $params['q'] = "%$q%";
}
$rows = DB::all($sql . ' GROUP BY j.txn_id, j.txn_date, t.txn_type, t.txn_no, t.ref_no, t.memo, c.name, v.name, o.name, t.payee_name ORDER BY j.txn_date, j.txn_id', $params);
$creditNormal = $acct['normal_balance'] === 'Credit';
$sign = $creditNormal ? -1 : 1;

if (g('export') === 'csv') {
    Access::require($pageKey, 'print');
    $run = $opening;
    $out = [];
    foreach ($rows as $r) {
        $run += $r['debit'] - $r['credit'];
        $out[] = [$r['txn_date'], Txn::label($r['txn_type']), $r['txn_no'], $r['party'], $r['memo'], $r['credit'], $r['debit'], $r['cleared'] ? 'X' : '', $sign * $run];
    }
    Audit::log('export', 'bank', 'register', $accountId, $acct['name'], ['page_key' => $pageKey]);
    csv_download('register_' . $acct['code'] . '.csv', ['Date', 'Type', 'Number', 'Payee', 'Memo', 'Payment/Credit', 'Deposit/Debit', 'Cleared', 'Balance'], $out);
}

layout_start('Register: ' . $acct['code'] . ' - ' . $acct['name'], ['actions' => (Access::can($pageKey, 'print') ? btn_link(current_url(['export' => 'csv']), 'Export', 'btn-outline-secondary', 'download') : '')
    . ' ' . page_link('bank.reconcile', ['account_id' => $accountId], 'Reconcile', 'btn-outline-primary', 'check2-square')]);
?>
<form class="row g-2 mb-3 filters" method="get"><input type="hidden" name="p" value="<?= h($pageKey) ?>">
  <?= select_field('account_id', 'Account', $accounts, $accountId, ['class' => 'col-md-4', 'extra' => 'data-autosubmit']) ?>
  <div class="col-auto"><label class="form-label">From</label><input class="form-control form-control-sm" type="date" name="from" value="<?= h($from) ?>"></div>
  <div class="col-auto"><label class="form-label">To</label><input class="form-control form-control-sm" type="date" name="to" value="<?= h($to) ?>"></div>
  <div class="col-auto"><label class="form-label">Search</label><input class="form-control form-control-sm" name="q" value="<?= h($q) ?>"></div>
  <div class="col-auto d-flex align-items-end"><button class="btn btn-sm btn-secondary">Show</button></div>
</form>
<div class="card mb-3"><div class="table-responsive" style="max-height:62vh"><table class="table table-sm table-hover mb-0">
  <thead class="sticky-top bg-white"><tr><th>Date</th><th>Number</th><th>Type</th><th>Payee / name</th><th>Memo</th>
    <th class="num"><?= $creditNormal ? 'Decrease' : 'Payment' ?></th><th class="text-center">✓</th><th class="num"><?= $creditNormal ? 'Increase' : 'Deposit' ?></th><th class="num">Balance</th></tr></thead>
  <tr class="table-light"><td><?= h(fdate($from)) ?></td><td colspan="7">Opening balance</td><td class="num"><?= money($sign * $opening) ?></td></tr>
  <?php $run = $opening; foreach ($rows as $r): $run += $r['debit'] - $r['credit']; $net = $r['debit'] - $r['credit']; ?>
    <tr><td><?= h(fdate($r['txn_date'])) ?></td><td><?= Txn::link(['id' => $r['txn_id'], 'txn_type' => $r['txn_type'], 'txn_no' => $r['txn_no']]) ?></td>
      <td class="small"><?= h(Txn::label($r['txn_type'])) ?></td><td><?= h($r['party']) ?></td><td class="small"><?= h(mb_strimwidth((string)$r['memo'], 0, 40, '…')) ?></td>
      <td class="num"><?= $sign * $net < 0 ? money(abs($net)) : '' ?></td><td class="text-center"><?= (int)$r['cleared'] ? '<i class="bi bi-check-lg text-success"></i>' : '' ?></td>
      <td class="num"><?= $sign * $net > 0 ? money(abs($net)) : '' ?></td><td class="num"><?= money($sign * $run) ?></td></tr>
  <?php endforeach; ?>
  <tr class="fw-bold table-light"><td colspan="8">Ending balance</td><td class="num"><?= money($sign * $run) ?></td></tr>
</table></div></div>

<?php if ($acct['account_type'] === 'Bank' && (Access::can('bank.write_checks', 'create') || Access::can('bank.deposits', 'create') || Access::can('bank.transfers', 'create'))): ?>
<form method="post" class="card"><div class="card-header">Enter a simple transaction in this register</div><div class="card-body row g-2">
  <?= csrf_field() ?>
  <?= select_field('kind', 'Type', array_filter(['check' => Access::can('bank.write_checks', 'create') ? 'Check / payment' : null, 'deposit' => Access::can('bank.deposits', 'create') ? 'Deposit' : null, 'transfer' => Access::can('bank.transfers', 'create') ? 'Transfer to another account' : null]), fv('kind'), ['class' => 'col-md-2']) ?>
  <?= input_field('txn_date', 'Date', fv('txn_date', null, today()), ['type' => 'date', 'class' => 'col-md-2']) ?>
  <?= input_field('number', 'Number', fv('number'), ['class' => 'col-md-1']) ?>
  <?= input_field('payee', 'Payee / received from', fv('payee'), ['class' => 'col-md-2']) ?>
  <?= select_field('other_account_id', 'Account (expense / income / transfer to)', Accts::all(), fv('other_account_id'), ['blank' => '', 'class' => 'col-md-3']) ?>
  <?= input_field('amount', 'Amount', fv('amount'), ['class' => 'col-md-2']) ?>
  <?= Classes::field(fv('class_id'), 'col-md-2') ?>
  <?= input_field('memo', 'Memo', fv('memo'), ['class' => 'col-md-6']) ?>
  <div class="col-md-6"><?= Approval::fields() ?></div>
  <div class="col-12"><button class="btn btn-primary btn-sm">Record</button></div>
</div></form>
<?php endif; ?>
<?php
layout_end();
