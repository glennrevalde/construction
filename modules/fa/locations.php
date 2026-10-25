<?php
declare(strict_types=1);

/** Fixed Assets > Asset Locations: the list behind the Location box of every asset */
$pageKey = 'fa.locations';
$edit = gint('id') ? DB::row('SELECT * FROM asset_locations WHERE id = :id', ['id' => gint('id')]) : null;

if (g('a') === 'delete' && is_post()) {
    Access::require($pageKey, 'delete');
    $row = DB::row('SELECT * FROM asset_locations WHERE id = :id', ['id' => gint('id')]);
    if ($row) {
        if ((int)DB::value('SELECT COUNT(*) FROM fixed_assets WHERE location_id = :id', ['id' => $row['id']]) > 0) {
            flash('warning', $row['name'] . ' still has assets. Move them to another location first, or make the location inactive.');
        } else {
            DB::run('DELETE FROM asset_locations WHERE id = :id', ['id' => $row['id']]);
            Audit::log('delete', 'fa', 'asset_location', (int)$row['id'], $row['name'], ['before' => $row, 'page_key' => $pageKey]);
            flash('success', 'Location deleted.');
        }
    }
    redirect(url($pageKey));
}

handle_post(function () use ($edit, $pageKey) {
    Access::require($pageKey, $edit ? 'edit' : 'create');
    $d = ['name' => mb_substr(trim((string)p('name')), 0, 120), 'is_active' => p('is_active', '1') === '1' ? 1 : 0];
    if ($d['name'] === '') {
        fail('Enter the name of the location.');
    }
    $dup = DB::value('SELECT id FROM asset_locations WHERE name = :n AND id <> :id', ['n' => $d['name'], 'id' => $edit['id'] ?? 0]);
    if ($dup) {
        fail('There is already a location named ' . $d['name'] . '.');
    }
    if ($edit) {
        DB::tx(function () use ($d, $edit, $pageKey) {
            DB::update('asset_locations', $d, 'id = :id', ['id' => $edit['id']]);
            // the register and the reports print the name kept on the asset
            DB::run('UPDATE fixed_assets SET location = :n WHERE location_id = :id', ['n' => $d['name'], 'id' => $edit['id']]);
            Audit::log('edit', 'fa', 'asset_location', (int)$edit['id'], $d['name'], ['before' => $edit, 'after' => $d, 'page_key' => $pageKey]);
        });
        $id = (int)$edit['id'];
    } else {
        $id = DB::insert('asset_locations', $d);
        Audit::log('create', 'fa', 'asset_location', $id, $d['name'], ['snapshot' => $d, 'page_key' => $pageKey]);
    }
    flash('success', 'Location saved.');
    redirect_after_save(url($pageKey), url($pageKey));
});

$rows = DB::all("SELECT l.*, (SELECT COUNT(*) FROM fixed_assets f WHERE f.location_id = l.id AND f.status <> 'disposed') AS assets,
                        (SELECT COALESCE(SUM(f.cost),0) FROM fixed_assets f WHERE f.location_id = l.id AND f.status <> 'disposed') AS cost
                   FROM asset_locations l ORDER BY l.name");
layout_start('Asset Locations', ['actions' => page_link('fa.register', [], 'Asset register', 'btn-outline-secondary', 'building')]);
?>
<p class="text-muted small">Where the fixed assets are kept: a branch, a building, a room, a vehicle yard. The Fixed Asset Register, the asset reports and Run Depreciation can all be filtered by location.</p>
<div class="row g-3">
  <div class="col-lg-7"><div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
    <thead><tr><th>Location</th><th class="num">Assets in service</th><th class="num">Cost</th><th>Active</th><th></th></tr></thead>
    <?php foreach ($rows as $r): ?>
      <tr class="<?= (int)$r['is_active'] ? '' : 'text-muted' ?>"><td class="fw-semibold"><?= h($r['name']) ?></td>
        <td class="num"><?= (int)$r['assets'] ? '<a href="' . h(url('fa.register', ['location_id' => $r['id']])) . '">' . (int)$r['assets'] . '</a>' : '0' ?></td>
        <td class="num"><?= money($r['cost'], true) ?></td><td><?= (int)$r['is_active'] ? 'Yes' : 'No' ?></td>
        <td class="text-nowrap text-end">
          <?php if (Access::can($pageKey, 'edit')): ?><a href="<?= h(url($pageKey, ['id' => $r['id']])) ?>">Edit</a><?php endif; ?>
          <?php if (Access::can($pageKey, 'delete') && (int)$r['assets'] === 0): ?>
            <form method="post" action="<?= h(url($pageKey, ['a' => 'delete', 'id' => $r['id']])) ?>" class="d-inline ms-2"><?= csrf_field() ?>
              <button class="btn btn-link btn-sm text-danger p-0 align-baseline" data-confirm="Delete the location <?= h($r['name']) ?>?">Delete</button></form>
          <?php endif; ?>
        </td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="text-muted py-3">No locations yet. Add the first one on the right.</td></tr><?php endif; ?>
  </table></div></div></div>
  <div class="col-lg-5"><?php if (Access::can($pageKey, $edit ? 'edit' : 'create')): ?>
    <form method="post" class="card"><div class="card-header"><?= $edit ? 'Edit location' : 'New location' ?></div><div class="card-body row g-2">
      <?= csrf_field() ?>
      <?= input_field('name', 'Location name', fv('name', $edit), ['class' => 'col-12', 'required' => true, 'extra' => 'maxlength="120" placeholder="e.g. Head Office, Cordova Plant, Motor Pool"']) ?>
      <?= check_field('is_active', 'Active (offered on the asset form)', fv('is_active', $edit, 1), 'col-12') ?>
      <div class="col-12"><?= save_buttons('Save location', $edit ? url($pageKey) : null, Access::can($pageKey, 'create')) ?></div>
    </div></form>
  <?php endif; ?></div>
</div>
<?php
layout_end();
