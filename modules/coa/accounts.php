<?php
declare(strict_types=1);

$pageKey = 'coa.accounts';
$a = g('a');
$types = ['Bank', 'Accounts Receivable', 'Other Current Asset', 'Fixed Asset', 'Other Asset', 'Accounts Payable', 'Credit Card',
    'Other Current Liability', 'Long Term Liability', 'Equity', 'Income', 'Other Income', 'Cost of Goods Sold', 'Expense', 'Other Expense'];
$catOf = [
    'Bank' => 'Asset', 'Accounts Receivable' => 'Asset', 'Other Current Asset' => 'Asset', 'Fixed Asset' => 'Asset', 'Other Asset' => 'Asset',
    'Accounts Payable' => 'Liability', 'Credit Card' => 'Liability', 'Other Current Liability' => 'Liability', 'Long Term Liability' => 'Liability',
    'Equity' => 'Equity', 'Income' => 'Income', 'Other Income' => 'Income', 'Cost of Goods Sold' => 'Expense', 'Expense' => 'Expense', 'Other Expense' => 'Expense',
];
$canChange = (bool)Access::special('coa_changes');

if ($a === 'delete' && is_post()) {
    Access::require($pageKey, 'delete');
    $acc = DB::row('SELECT * FROM accounts WHERE id = :id', ['id' => (int)g('id')]);
    try {
        if (!$acc || !$canChange) {
            fail('You are not allowed to change the Chart of Accounts.');
        }
        if ((int)$acc['is_system'] === 1) {
            fail('System accounts cannot be deleted. Make them inactive instead.');
        }
        $used = (int)DB::value('SELECT COUNT(*) FROM journal_lines WHERE account_id = :a', ['a' => $acc['id']])
            + (int)DB::value('SELECT COUNT(*) FROM transaction_lines WHERE account_id = :a', ['a' => $acc['id']])
            + (int)DB::value('SELECT COUNT(*) FROM default_accounts WHERE account_id = :a', ['a' => $acc['id']])
            + (int)DB::value('SELECT COUNT(*) FROM accounts WHERE parent_id = :a', ['a' => $acc['id']])
            + (int)DB::value('SELECT COUNT(*) FROM items WHERE :a IN (income_account_id, cogs_account_id, asset_account_id, expense_account_id)', ['a' => $acc['id']]);
        if ($used) {
            fail('This account is in use. Make it inactive instead of deleting it.');
        }
        DB::run('DELETE FROM accounts WHERE id = :id', ['id' => $acc['id']]);
        Audit::log('delete', 'coa', 'account', (int)$acc['id'], $acc['code'] . ' ' . $acc['name'], ['snapshot' => $acc, 'reason' => p('reason') ?: null, 'page_key' => $pageKey]);
        flash('success', 'Account deleted.');
    } catch (UserError $e) {
        flash('danger', $e->getMessage());
    }
    redirect(url($pageKey));
}

if ($a === 'new' || $a === 'edit') {
    $acc = null;
    if ($a === 'edit') {
        Access::require($pageKey, 'edit');
        $acc = DB::row('SELECT * FROM accounts WHERE id = :id', ['id' => (int)g('id')]);
        if (!$acc) {
            redirect(url($pageKey));
        }
    } else {
        Access::require($pageKey, 'create');
    }
    if (!$canChange) {
        Access::denyPage($pageKey, 'change the Chart of Accounts');
    }
    handle_post(function () use ($acc, $catOf, $pageKey) {
        $type = (string)p('account_type');
        if (!isset($catOf[$type])) {
            fail('Choose the account type.');
        }
        $d = [
            'code' => mb_substr(trim((string)p('code')), 0, 20),
            'name' => mb_substr(trim((string)p('name')), 0, 120),
            'account_type' => $type,
            'category' => $catOf[$type],
            'normal_balance' => in_array(p('normal_balance'), ['Debit', 'Credit'], true) ? p('normal_balance') : (in_array($catOf[$type], ['Asset', 'Expense'], true) ? 'Debit' : 'Credit'),
            'parent_id' => pint('parent_id'),
            'description' => p('description') ?: null,
            'bank_account_no' => p('bank_account_no') ?: null,
            'with_sl' => p('with_sl', '0') === '1' ? 1 : 0,
            'is_active' => p('is_active', '1') === '1' ? 1 : 0,
        ];
        if ($d['code'] === '' || $d['name'] === '') {
            fail('Enter the account code and name.');
        }
        if (DB::value('SELECT id FROM accounts WHERE code = :c AND id <> :id', ['c' => $d['code'], 'id' => $acc['id'] ?? 0])) {
            fail('Another account uses this code.');
        }
        if ($d['parent_id']) {
            $parent = DB::row('SELECT * FROM accounts WHERE id = :id', ['id' => $d['parent_id']]);
            if (!$parent || $parent['category'] !== $d['category'] || ($acc && (int)$parent['id'] === (int)$acc['id'])) {
                fail('A sub-account must be under a parent account of the same category.');
            }
        }
        if ($acc && (int)$acc['is_system'] === 1 && !$d['is_active']) {
            fail('System accounts used by the modules cannot be made inactive.');
        }
        if ($acc && $acc['account_type'] !== $type && (int)DB::value('SELECT COUNT(*) FROM journal_lines WHERE account_id = :a', ['a' => $acc['id']]) > 0
            && $acc['category'] !== $d['category']) {
            fail('This account has transactions; it cannot be moved to a different category.');
        }
        if ($acc) {
            DB::update('accounts', $d, 'id = :id', ['id' => $acc['id']]);
            $id = (int)$acc['id'];
            Audit::log('edit', 'coa', 'account', $id, $d['code'] . ' ' . $d['name'], ['before' => $acc, 'after' => $d, 'page_key' => $pageKey]);
        } else {
            $id = DB::insert('accounts', $d);
            Audit::log('create', 'coa', 'account', $id, $d['code'] . ' ' . $d['name'], ['snapshot' => $d, 'page_key' => $pageKey]);
        }
        Accts::reset();
        flash('success', 'Account saved.');
        redirect_after_save(url($pageKey, ['a' => 'new']), url($pageKey));
    });
    layout_start($acc ? 'Edit account ' . $acc['code'] : 'New account', ['actions' => btn_link(url($pageKey), 'Cancel')]);
    ?>
    <form method="post" class="card" style="max-width:860px"><div class="card-body row g-2">
      <?= csrf_field() ?>
      <?= select_field('account_type', 'Account type', array_combine($types, $types), fv('account_type', $acc, 'Expense'), ['class' => 'col-md-4']) ?>
      <?= input_field('code', 'Code', fv('code', $acc), ['class' => 'col-md-2', 'required' => true]) ?>
      <?= input_field('name', 'Name', fv('name', $acc), ['class' => 'col-md-6', 'required' => true]) ?>
      <?= select_field('parent_id', 'Sub-account of', DB::pairs("SELECT id, CONCAT(code, ' - ', name) FROM accounts WHERE parent_id IS NULL AND id <> :id ORDER BY code", ['id' => $acc['id'] ?? 0]), fv('parent_id', $acc), ['blank' => '', 'class' => 'col-md-6']) ?>
      <?= select_field('normal_balance', 'Normal balance', ['Debit' => 'Debit', 'Credit' => 'Credit'], fv('normal_balance', $acc, ''), ['blank' => 'From type', 'class' => 'col-md-3']) ?>
      <?= check_field('is_active', 'Active', fv('is_active', $acc, 1), 'col-md-3') ?>
      <?= check_field('with_sl', 'With SL (keeps a subsidiary ledger)', fv('with_sl', $acc, 0), 'col-md-6') ?>
      <?= input_field('bank_account_no', 'Bank account no. (bank accounts)', fv('bank_account_no', $acc), ['class' => 'col-md-4']) ?>
      <?= input_field('description', 'Description', fv('description', $acc), ['class' => 'col-md-8']) ?>
      <div class="col-12 small text-muted">Contra accounts (accumulated depreciation, sales returns, drawings) use the opposite normal balance of their type.</div>
      <div class="col-12 small text-muted"><strong>With SL</strong> keeps a listing of the names that transacted this account. While it is ticked, a journal entry,
        cash voucher or check that posts to this account must name a customer, supplier, employee or other name, and the
        <?= page_link('rpt.subsidiary_ledger', [], 'Subsidiary Ledger', 'btn-link p-0 align-baseline') ?> and
        <?= page_link('rpt.schedule_of_accounts', [], 'Schedule of Accounts', 'btn-link p-0 align-baseline') ?> reports show those names.</div>
      <div class="col-12"><?= save_buttons('Save account', url($pageKey), Access::can($pageKey, 'create')) ?></div>
    </div></form>
    <?php
    layout_end();
    return;
}

$showInactive = g('inactive') === '1';
$accounts = DB::all(
    'SELECT a.*, p.code AS parent_code, COALESCE(SUM(j.debit - j.credit),0) AS net
       FROM accounts a LEFT JOIN accounts p ON p.id = a.parent_id LEFT JOIN journal_lines j ON j.account_id = a.id'
    . ($showInactive ? '' : ' WHERE a.is_active = 1') . '
      GROUP BY a.id, p.code ORDER BY a.code'
);
if (g('export') === 'csv') {
    Access::require($pageKey, 'print');
    Audit::log('export', 'coa', 'list', null, 'Chart of Accounts', ['page_key' => $pageKey]);
    csv_download('chart_of_accounts.csv', ['Code', 'Name', 'Type', 'Category', 'Normal balance', 'Parent', 'With SL', 'Balance', 'Active'],
        array_map(fn($r) => [$r['code'], $r['name'], $r['account_type'], $r['category'], $r['normal_balance'], $r['parent_code'],
            (int)$r['with_sl'] ? 'Yes' : '', ($r['normal_balance'] === 'Credit' ? -1 : 1) * $r['net'], $r['is_active']], $accounts));
}
$byCat = [];
foreach ($accounts as $r) {
    $byCat[$r['category']][] = $r;
}
$showBank = Access::special('view_bank_balances') || Access::page('bank.register');
layout_start('Chart of Accounts', ['actions' => ($canChange ? page_link($pageKey, ['a' => 'new'], 'New account', 'btn-primary', 'plus-lg', 'create') : '')
    . ' ' . (Access::can($pageKey, 'print') ? btn_link(current_url(['export' => 'csv']), 'Export', 'btn-outline-secondary', 'download') : '')
    . ' ' . page_link('coa.default_accounts', [], 'Module default accounts', 'btn-outline-secondary', 'diagram-3')]);
?>
<form method="get" class="mb-2 filters"><input type="hidden" name="p" value="<?= h($pageKey) ?>"><label class="small"><input type="checkbox" name="inactive" value="1" <?= checked($showInactive) ?> data-autosubmit> Include inactive accounts</label></form>
<?php foreach (['Asset', 'Liability', 'Equity', 'Income', 'Expense'] as $cat): if (empty($byCat[$cat])) { continue; } ?>
<div class="card mb-3"><div class="card-header fw-semibold"><?= h($cat === 'Liability' ? 'Liabilities' : $cat . ($cat === 'Equity' ? '' : 's')) ?></div>
  <table class="table table-sm table-hover mb-0">
    <thead><tr><th style="width:90px">Code</th><th>Name</th><th>Type</th><th>Normal</th><th style="width:70px">With SL</th><th class="num">Balance</th><th></th></tr></thead>
    <?php foreach ($byCat[$cat] as $r): $bal = ($r['normal_balance'] === 'Credit' ? -1 : 1) * (float)$r['net']; ?>
      <tr class="<?= (int)$r['is_active'] ? '' : 'text-muted' ?>">
        <td><?= h($r['code']) ?></td>
        <td class="<?= $r['parent_id'] ? 'ps-4' : '' ?>"><?= $r['parent_id'] ? '<i class="bi bi-arrow-return-right"></i> ' : '' ?><?= safe_link('rpt.general_ledger', ['account_id' => $r['id']], $r['name']) ?>
          <?= (int)$r['is_system'] ? '<span class="badge text-bg-light">system</span>' : '' ?></td>
        <td class="small"><?= h($r['account_type']) ?></td><td class="small"><?= h($r['normal_balance']) ?></td>
        <td class="small"><?php if ((int)$r['with_sl']): ?><i class="bi bi-check-lg text-success"></i> Yes<?php endif; ?></td>
        <td class="num"><?= ($r['account_type'] === 'Bank' && !$showBank) ? '•••' : money($bal) ?></td>
        <td class="text-nowrap small">
          <?= $canChange && Access::can($pageKey, 'edit') ? '<a href="' . h(url($pageKey, ['a' => 'edit', 'id' => $r['id']])) . '">Edit</a>' : '' ?>
          <?= Access::page('bank.register') && in_array($r['account_type'], ['Bank', 'Credit Card', 'Accounts Receivable', 'Accounts Payable', 'Other Current Asset', 'Other Current Liability'], true) ? ' | <a href="' . h(url('bank.register', ['account_id' => $r['id']])) . '">Register</a>' : '' ?>
          <?php if ($canChange && Access::can($pageKey, 'delete') && !(int)$r['is_system'] && abs((float)$r['net']) < 0.005): ?>
            <form method="post" action="<?= h(url($pageKey, ['a' => 'delete', 'id' => $r['id']])) ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-link btn-sm p-0 text-danger" data-confirm="Delete this account?">Delete</button></form>
          <?php endif; ?>
        </td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endforeach; ?>
<?php
layout_end();
