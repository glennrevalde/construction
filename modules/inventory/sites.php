<?php
declare(strict_types=1);

$pageKey = 'inv.sites';
$edit = gint('id') ? DB::row('SELECT * FROM sites WHERE id = :id', ['id' => gint('id')]) : null;

handle_post(function () use ($edit, $pageKey) {
    Access::require($pageKey, $edit ? 'edit' : 'create');
    $d = [
        'code' => mb_substr(strtoupper(trim((string)p('code'))), 0, 20),
        'name' => mb_substr(trim((string)p('name')), 0, 80),
        'site_type' => in_array(p('site_type'), ['warehouse', 'store', 'stockroom', 'van', 'damaged', 'other'], true) ? p('site_type') : 'warehouse',
        'address' => p('address') ?: null,
        'is_active' => p('is_active', '1') === '1' ? 1 : 0,
    ];
    if ($d['code'] === '' || $d['name'] === '') {
        fail('Enter the site code and name.');
    }
    if (DB::value('SELECT id FROM sites WHERE code = :c AND id <> :id', ['c' => $d['code'], 'id' => $edit['id'] ?? 0])) {
        fail('Another site uses this code.');
    }
    if ($edit && !$d['is_active'] && abs((float)DB::value('SELECT COALESCE(SUM(ABS(qty_on_hand)),0) FROM item_site_stock WHERE site_id = :s', ['s' => $edit['id']])) > 0) {
        fail('This site still holds stock. Transfer it out before making the site inactive.');
    }
    if ($edit) {
        DB::update('sites', $d, 'id = :id', ['id' => $edit['id']]);
        $id = (int)$edit['id'];
        Audit::log('edit', 'inv', 'site', $id, $d['name'], ['before' => $edit, 'after' => $d, 'page_key' => $pageKey]);
    } else {
        $id = DB::insert('sites', $d);
        Audit::log('create', 'inv', 'site', $id, $d['name'], ['snapshot' => $d, 'page_key' => $pageKey]);
    }
    flash('success', 'Site saved.');
    redirect_after_save(url($pageKey), url($pageKey, ['id' => $id]));
});

$sites = DB::all(
    'SELECT s.*, COUNT(DISTINCT x.item_id) AS items, COALESCE(SUM(x.qty_on_hand),0) AS qty,
            (SELECT COUNT(*) FROM pos_terminals t WHERE t.site_id = s.id) AS terminals
       FROM sites s LEFT JOIN item_site_stock x ON x.site_id = s.id AND x.qty_on_hand <> 0 GROUP BY s.id ORDER BY s.id'
);
layout_start('Inventory Site List', ['actions' => page_link('inv.transfers', ['a' => 'new'], 'Transfer inventory', 'btn-outline-primary', 'arrow-left-right', 'create')
    . ' ' . page_link('rpt.qoh_by_site', [], 'Quantity on Hand by Site', 'btn-outline-secondary', 'bar-chart')]);
?>
<div class="row g-3">
  <div class="col-lg-7"><div class="card"><table class="table table-sm table-hover mb-0">
    <thead><tr><th>Code</th><th>Name</th><th>Type</th><th>Address</th><th class="num">Items</th><th class="num">Units</th><th class="num">POS</th><th></th></tr></thead>
    <?php foreach ($sites as $s): ?>
      <tr class="<?= (int)$s['is_active'] ? '' : 'text-muted' ?>"><td><?= h($s['code']) ?></td><td><?= h($s['name']) ?></td><td><?= h(ucfirst($s['site_type'])) ?></td><td class="small"><?= h($s['address']) ?></td>
        <td class="num"><?= (int)$s['items'] ?></td><td class="num"><?= qty($s['qty']) ?></td><td class="num"><?= (int)$s['terminals'] ?></td>
        <td><?= Access::can($pageKey, 'edit') ? '<a href="' . h(url($pageKey, ['id' => $s['id']])) . '">Edit</a>' : '' ?></td></tr>
    <?php endforeach; ?>
  </table></div></div>
  <div class="col-lg-5">
    <?php if (Access::can($pageKey, $edit ? 'edit' : 'create')): ?>
    <form method="post" class="card"><div class="card-header"><?= $edit ? 'Edit site' : 'New site' ?></div><div class="card-body row g-2">
      <?= csrf_field() ?>
      <?= input_field('code', 'Code', fv('code', $edit), ['class' => 'col-md-4', 'required' => true]) ?>
      <?= input_field('name', 'Name', fv('name', $edit), ['class' => 'col-md-8', 'required' => true]) ?>
      <?= select_field('site_type', 'Type', ['warehouse' => 'Warehouse', 'store' => 'Retail store', 'stockroom' => 'Stockroom', 'van' => 'Delivery van', 'damaged' => 'Damaged goods', 'other' => 'Other'], fv('site_type', $edit, 'warehouse'), ['class' => 'col-md-6']) ?>
      <?= check_field('is_active', 'Active', fv('is_active', $edit, 1), 'col-md-6') ?>
      <div class="col-12"><label class="form-label">Address</label><input class="form-control form-control-sm" name="address" value="<?= h(fv('address', $edit)) ?>"></div>
      <div class="col-12"><?= save_buttons('Save site', $edit ? url($pageKey) : null, Access::can($pageKey, 'create')) ?></div>
    </div></form>
    <?php endif; ?>
  </div>
</div>
<?php
layout_end();
