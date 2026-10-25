<?php
declare(strict_types=1);

$pageKey = 'coa.default_accounts';
$rows = DB::all('SELECT d.*, a.code, a.name FROM default_accounts d LEFT JOIN accounts a ON a.id = d.account_id ORDER BY FIELD(d.module, \'proj\',\'cust\',\'vend\',\'inv\',\'pos\',\'bank\',\'fa\',\'coa\'), d.label');
$canChange = Access::can($pageKey, 'edit') && Access::special('coa_changes');

handle_post(function () use ($rows, $pageKey, $canChange) {
    if (!$canChange) {
        Access::denyPage($pageKey, 'change default accounts on');
    }
    $changes = [];
    foreach ($rows as $r) {
        $new = pint('acct_' . $r['setting_key']);
        if ($new && (int)$new !== (int)$r['account_id']) {
            DB::update('default_accounts', ['account_id' => $new], 'setting_key = :k', ['k' => $r['setting_key']]);
            $changes[] = ['field' => $r['label'], 'old' => $r['code'] . ' ' . $r['name'], 'new' => account_label_plain($new)];
        }
    }
    if ($changes) {
        Audit::log('edit', 'coa', 'default_accounts', null, 'Module default accounts', ['changes' => $changes, 'page_key' => $pageKey]);
    }
    Accts::reset();
    flash('success', count($changes) . ' default account(s) changed.');
    redirect(url($pageKey));
});

function account_label_plain(int $id): string
{
    $a = DB::row('SELECT code, name FROM accounts WHERE id = :id', ['id' => $id]);
    return $a ? $a['code'] . ' ' . $a['name'] : '';
}

$modules = ['proj' => 'Construction: billing, revenue, retention and project costs', 'cust' => 'Customers', 'vend' => 'Vendors', 'inv' => 'Inventory', 'pos' => 'Point of Sale', 'bank' => 'Banking', 'fa' => 'Fixed Assets', 'coa' => 'General'];
$all = Accts::all();
layout_start('Module Default Accounts');
?>
<p class="text-muted">Before any transaction is recorded, each module is set up with the accounts it uses by default. Items and fixed asset categories also carry their own accounts.</p>
<form method="post"><?= csrf_field() ?>
<fieldset <?= $canChange ? '' : 'disabled' ?>>
<div class="row g-3">
<?php foreach ($modules as $code => $label): $list = array_filter($rows, fn($r) => $r['module'] === $code); if (!$list) { continue; } ?>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header fw-semibold"><?= h($label) ?></div><div class="card-body">
    <?php foreach ($list as $r): ?>
      <div class="row mb-1 align-items-center"><div class="col-5 small"><?= h($r['label']) ?></div>
        <div class="col-7"><select class="form-select form-select-sm" name="acct_<?= h($r['setting_key']) ?>"><?= options($all, $r['account_id'], '— not set —') ?></select></div></div>
    <?php endforeach; ?>
  </div></div></div>
<?php endforeach; ?>
</div>
<?php if ($canChange): ?><button class="btn btn-primary mt-3">Save default accounts</button><?php else: ?><p class="small text-muted mt-3">You need the "Chart of Accounts changes" permission to edit these.</p><?php endif; ?>
</fieldset>
</form>
<?php
layout_end();
