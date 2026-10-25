<?php
declare(strict_types=1);

require_once APP_ROOT . '/modules/txn/_common.php';

$pageKey = 'bank.transfers';
if (txn_common_actions($pageKey, ['transfer'])) {
    return;
}
$a = g('a', 'new');
if ($a === 'list') {
    render_txn_list($pageKey, ['transfer'], 'Fund Transfers', ['statuses' => ['open' => 'Posted', 'void' => 'Void'], 'new_label' => 'Transfer funds']);
    return;
}
$edit = null;
if ($a === 'edit') {
    Access::require($pageKey, 'edit');
    $edit = Txn::load((int)g('id'));
    if (!$edit || $edit['txn_type'] !== 'transfer' || (int)$edit['is_voided'] === 1) {
        redirect(url($pageKey, ['a' => 'list']));
    }
} else {
    Access::require($pageKey, 'create');
}
$accounts = Accts::ofType(['Bank', 'Credit Card', 'Other Current Asset']);

handle_post(function () use ($pageKey, $edit) {
    $from = pint('account_id');
    $to = pint('account2_id');
    $amt = r2(pnum('total'));
    if (!$from || !$to || $from === $to) {
        fail('Choose two different accounts.');
    }
    if ($amt <= 0) {
        fail('Enter an amount greater than zero.');
    }
    $id = Txn::save('transfer', [
        'txn_date' => pdate('txn_date', today()), 'account_id' => $from, 'account2_id' => $to, 'total' => $amt,
        'memo' => p('memo') ?: null, 'ref_no' => p('ref_no') ?: null, 'status' => 'open',
    ], [], $edit ? (int)$edit['id'] : null, ['keep_totals' => true]);
    flash('success', 'Transfer recorded. It appears in both registers and does not affect income or expenses.');
    redirect_after_save(url($pageKey, ['a' => 'new']), url($pageKey, ['a' => 'view', 'id' => $id]));
});

$balances = [];
foreach (array_keys($accounts) as $aid) {
    $balances[$aid] = Accts::balance((int)$aid);
}
layout_start($edit ? 'Edit transfer ' . $edit['txn_no'] : 'Transfer Funds', ['actions' => btn_link(url($pageKey, ['a' => 'list']), 'Transfers list', 'btn-outline-secondary', 'list-ul')]);
?>
<form method="post" class="card" style="max-width:760px"><div class="card-body row g-2">
  <?= csrf_field() ?>
  <?= input_field('txn_date', 'Date', fv('txn_date', $edit, today()), ['type' => 'date', 'class' => 'col-md-4']) ?>
  <?= input_field('ref_no', 'Reference', fv('ref_no', $edit), ['class' => 'col-md-4']) ?>
  <?= Classes::field(fv('class_id', $edit), 'col-md-4') ?: '<div class="col-md-4"></div>' ?>
  <?= select_field('account_id', 'Transfer funds from', $accounts, fv('account_id', $edit), ['blank' => '', 'class' => 'col-md-6', 'required' => true]) ?>
  <?= select_field('account2_id', 'Transfer funds to', $accounts, fv('account2_id', $edit), ['blank' => '', 'class' => 'col-md-6', 'required' => true]) ?>
  <?= input_field('total', 'Amount', fv('total', $edit), ['class' => 'col-md-4', 'required' => true]) ?>
  <?= input_field('memo', 'Memo', fv('memo', $edit, 'Funds transfer'), ['class' => 'col-md-8']) ?>
  <?php if (Access::special('view_bank_balances') || Access::page('bank.register')): ?>
  <div class="col-12 small text-muted">Current balances: <?php foreach ($balances as $aid => $b): ?><span class="me-3"><?= h(preg_replace('/^\S+ - /', '', $accounts[$aid])) ?>: <?= money($b) ?></span><?php endforeach; ?></div>
  <?php endif; ?>
  <div class="col-12"><?= save_buttons('Save transfer', url($pageKey), Access::can($pageKey, 'create')) ?></div>
</div></form>
<?php
layout_end();
