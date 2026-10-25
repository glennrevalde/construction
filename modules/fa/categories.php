<?php
declare(strict_types=1);

$pageKey = 'fa.categories';
$edit = gint('id') ? DB::row('SELECT * FROM asset_categories WHERE id = :id', ['id' => gint('id')]) : null;
$methods = ['straight_line' => 'Straight-line', 'declining_balance' => 'Declining balance', 'units_of_production' => 'Units of production', 'syd' => "Sum-of-the-years'-digits"];

handle_post(function () use ($edit, $pageKey, $methods) {
    Access::require($pageKey, $edit ? 'edit' : 'create');
    $d = [
        'name' => mb_substr(trim((string)p('name')), 0, 80),
        'asset_account_id' => pint('asset_account_id'),
        'accum_dep_account_id' => pint('accum_dep_account_id'),
        'dep_expense_account_id' => pint('dep_expense_account_id'),
        'default_method' => array_key_exists(p('default_method'), $methods) ? p('default_method') : 'straight_line',
        'default_life_years' => max(0.5, pnum('default_life_years')),
        'is_active' => p('is_active', '1') === '1' ? 1 : 0,
    ];
    if (!$d['name'] || !$d['asset_account_id'] || !$d['accum_dep_account_id'] || !$d['dep_expense_account_id']) {
        fail('Enter the name and all three accounts.');
    }
    if ($edit) {
        DB::update('asset_categories', $d, 'id = :id', ['id' => $edit['id']]);
        $id = (int)$edit['id'];
        Audit::log('edit', 'fa', 'asset_category', $id, $d['name'], ['before' => $edit, 'after' => $d, 'page_key' => $pageKey]);
    } else {
        $id = DB::insert('asset_categories', $d);
        Audit::log('create', 'fa', 'asset_category', $id, $d['name'], ['snapshot' => $d, 'page_key' => $pageKey]);
    }
    flash('success', 'Category saved.');
    redirect_after_save(url($pageKey), url($pageKey, ['id' => $id]));
});

$rows = DB::all('SELECT c.*, a1.code AS a1, a2.code AS a2, a3.code AS a3, (SELECT COUNT(*) FROM fixed_assets f WHERE f.category_id = c.id) AS assets
                   FROM asset_categories c JOIN accounts a1 ON a1.id = c.asset_account_id JOIN accounts a2 ON a2.id = c.accum_dep_account_id JOIN accounts a3 ON a3.id = c.dep_expense_account_id ORDER BY c.name');
layout_start('Asset Categories');
?>
<div class="row g-3">
  <div class="col-lg-7"><div class="card"><table class="table table-sm mb-0">
    <thead><tr><th>Category</th><th>Asset / Accum. / Expense</th><th>Default method</th><th class="num">Life</th><th class="num">Assets</th><th></th></tr></thead>
    <?php foreach ($rows as $r): ?><tr class="<?= (int)$r['is_active'] ? '' : 'text-muted' ?>"><td><?= h($r['name']) ?></td><td class="small"><?= h($r['a1'] . ' / ' . $r['a2'] . ' / ' . $r['a3']) ?></td>
      <td class="small"><?= h($methods[$r['default_method']]) ?></td><td class="num"><?= h((float)$r['default_life_years']) ?></td><td class="num"><?= (int)$r['assets'] ?></td>
      <td><?= Access::can($pageKey, 'edit') ? '<a href="' . h(url($pageKey, ['id' => $r['id']])) . '">Edit</a>' : '' ?></td></tr><?php endforeach; ?>
  </table></div></div>
  <div class="col-lg-5"><?php if (Access::can($pageKey, $edit ? 'edit' : 'create')): $fa = Accts::ofType(['Fixed Asset']); $exp = Accts::ofType(['Expense', 'Other Expense', 'Cost of Goods Sold']); ?>
    <form method="post" class="card"><div class="card-header"><?= $edit ? 'Edit category' : 'New category' ?></div><div class="card-body row g-2">
      <?= csrf_field() ?>
      <?= input_field('name', 'Name', fv('name', $edit), ['class' => 'col-12', 'required' => true]) ?>
      <?= select_field('asset_account_id', 'Asset account', $fa, fv('asset_account_id', $edit), ['blank' => '', 'class' => 'col-12']) ?>
      <?= select_field('accum_dep_account_id', 'Accumulated depreciation account', $fa, fv('accum_dep_account_id', $edit), ['blank' => '', 'class' => 'col-12']) ?>
      <?= select_field('dep_expense_account_id', 'Depreciation expense account', $exp, fv('dep_expense_account_id', $edit), ['blank' => '', 'class' => 'col-12']) ?>
      <?= select_field('default_method', 'Default method', $methods, fv('default_method', $edit, 'straight_line'), ['class' => 'col-md-7']) ?>
      <?= input_field('default_life_years', 'Default life (years)', fv('default_life_years', $edit, '5'), ['class' => 'col-md-5']) ?>
      <?= check_field('is_active', 'Active', fv('is_active', $edit, 1), 'col-12') ?>
      <div class="col-12"><?= save_buttons('Save category', $edit ? url($pageKey) : null, Access::can($pageKey, 'create')) ?></div>
    </div></form>
  <?php endif; ?></div>
</div>
<?php
layout_end();
