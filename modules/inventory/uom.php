<?php
declare(strict_types=1);

/**
 * U/M Set List — units of measure.
 *
 * A set has one base unit (the unit stock is counted and costed in) and any number of
 * related units with the number of base units each one contains, so an item can be
 * bought by the box and sold by the piece.
 */

$pageKey = 'inv.uom';
$edit = gint('id') ? Uom::set(gint('id')) : null;
$units = $edit ? Uom::units((int)$edit['id']) : [];

handle_post(function () use (&$edit, $pageKey) {
    Access::require($pageKey, $edit ? 'edit' : 'create');
    $name = mb_substr(trim((string)p('name')), 0, 60);
    $base = mb_substr(trim((string)p('base_unit')), 0, 20);
    $kind = array_key_exists((string)p('uom_kind'), Uom::KINDS) ? (string)p('uom_kind') : 'other';
    if ($name === '' || $base === '') {
        fail('Enter the set name and the base unit.');
    }
    if (DB::value('SELECT id FROM uom_sets WHERE name = :n AND id <> :id', ['n' => $name, 'id' => $edit['id'] ?? 0])) {
        fail('Another U/M set already uses this name.');
    }

    // posted extra units
    $rows = [];
    foreach ((array)($_POST['u'] ?? []) as $r) {
        $code = mb_substr(trim((string)($r['code'] ?? '')), 0, 20);
        if ($code === '') {
            continue;
        }
        $factor = to_num($r['factor'] ?? 0);
        if ($factor <= 0) {
            fail('Every unit needs how many ' . $base . ' it contains (a number greater than zero).');
        }
        if (strcasecmp($code, $base) === 0) {
            fail('The unit "' . $code . '" is the base unit; it is already on the list.');
        }
        if (isset($rows[strtolower($code)])) {
            fail('The unit "' . $code . '" is listed twice.');
        }
        $rows[strtolower($code)] = [
            'id' => !empty($r['id']) ? (int)$r['id'] : null,
            'code' => $code,
            'name' => mb_substr(trim((string)($r['name'] ?? '')) ?: $code, 0, 60),
            'factor' => round($factor, 6),
        ];
    }

    $setId = DB::tx(function () use (&$edit, $name, $base, $kind, $rows, $pageKey) {
        $d = ['name' => $name, 'uom_kind' => $kind, 'base_unit' => $base, 'is_active' => p('is_active', '1') === '1' ? 1 : 0];
        if ($edit) {
            DB::update('uom_sets', $d, 'id = :id', ['id' => $edit['id']]);
            $setId = (int)$edit['id'];
        } else {
            $setId = DB::insert('uom_sets', $d);
        }

        // base unit row: always factor 1
        $baseRow = DB::row('SELECT * FROM uom_units WHERE set_id = :s AND is_base = 1', ['s' => $setId]);
        if ($baseRow) {
            DB::update('uom_units', ['code' => $base, 'name' => $base, 'factor' => 1, 'sort_order' => 0], 'id = :id', ['id' => $baseRow['id']]);
            $baseId = (int)$baseRow['id'];
        } else {
            $baseId = DB::insert('uom_units', ['set_id' => $setId, 'code' => $base, 'name' => $base, 'factor' => 1, 'is_base' => 1, 'sort_order' => 0]);
        }

        $keep = [$baseId];
        $i = 1;
        foreach ($rows as $r) {
            $data = ['set_id' => $setId, 'code' => $r['code'], 'name' => $r['name'], 'factor' => $r['factor'], 'is_base' => 0, 'sort_order' => $i++];
            if ($r['id'] && DB::value('SELECT id FROM uom_units WHERE id = :id AND set_id = :s', ['id' => $r['id'], 's' => $setId])) {
                DB::update('uom_units', $data, 'id = :id', ['id' => $r['id']]);
                $keep[] = (int)$r['id'];
            } else {
                $keep[] = DB::insert('uom_units', $data);
            }
        }

        // remove units that were taken off the list, unless something already uses them
        [$in, $p] = DB::in('k', $keep);
        foreach (DB::all("SELECT * FROM uom_units WHERE set_id = :s AND id NOT IN $in", ['s' => $setId] + $p) as $gone) {
            $used = (int)DB::value('SELECT COUNT(*) FROM transaction_lines WHERE uom_id = :u', ['u' => $gone['id']])
                + (int)DB::value('SELECT COUNT(*) FROM items WHERE sales_uom_id = :u OR purchase_uom_id = :u', ['u' => $gone['id']]);
            if ($used) {
                fail('The unit "' . $gone['code'] . '" is used on ' . $used . ' record(s) and cannot be removed. Keep it on the list.');
            }
            DB::run('DELETE FROM uom_units WHERE id = :id', ['id' => $gone['id']]);
        }

        // defaults (posted as the code of the chosen unit)
        $sd = (string)p('sales_default');
        $pd = (string)p('purchase_default');
        DB::run('UPDATE uom_units SET is_sales_default = 0, is_purchase_default = 0 WHERE set_id = :s', ['s' => $setId]);
        foreach (['is_sales_default' => $sd, 'is_purchase_default' => $pd] as $col => $code) {
            $uid = $code !== '' ? DB::value('SELECT id FROM uom_units WHERE set_id = :s AND code = :c', ['s' => $setId, 'c' => $code]) : null;
            DB::run("UPDATE uom_units SET $col = 1 WHERE id = :id", ['id' => (int)($uid ?: $baseId)]);
        }

        // items stocked in this set follow its base unit
        DB::run('UPDATE items SET unit = :u WHERE uom_set_id = :s', ['u' => $base, 's' => $setId]);

        $after = ['name' => $name, 'base_unit' => $base, 'kind' => $kind,
            'units' => array_map(fn($r) => $r['code'] . ' = ' . $r['factor'] . ' ' . $base, array_values($rows))];
        Audit::log($edit ? 'edit' : 'create', 'inv', 'uom_set', $setId, $name, [$edit ? 'after' : 'snapshot' => $after, 'page_key' => $pageKey]);
        return $setId;
    });

    flash('success', 'U/M set saved.');
    redirect_after_save(url($pageKey), url($pageKey, ['id' => $setId]));
});

$sets = DB::all('SELECT s.*, (SELECT COUNT(*) FROM uom_units u WHERE u.set_id = s.id) AS units,
                        (SELECT COUNT(*) FROM items i WHERE i.uom_set_id = s.id) AS items,
                        (SELECT GROUP_CONCAT(u.code ORDER BY u.is_base DESC, u.factor SEPARATOR ", ") FROM uom_units u WHERE u.set_id = s.id) AS codes
                   FROM uom_sets s ORDER BY s.name');
$canEdit = Access::can($pageKey, $edit ? 'edit' : 'create');
$extra = $edit ? [] : [['code' => '', 'name' => '', 'factor' => '']];
if (is_post()) {
    $extra = array_values((array)($_POST['u'] ?? []));
} elseif ($edit) {
    $extra = array_values(array_filter($units, fn($u) => !(int)$u['is_base']));
}
$salesDefault = '';
$purchaseDefault = '';
foreach ($units as $u) {
    $salesDefault = (int)$u['is_sales_default'] ? $u['code'] : $salesDefault;
    $purchaseDefault = (int)$u['is_purchase_default'] ? $u['code'] : $purchaseDefault;
}
$salesDefault = is_post() ? (string)p('sales_default') : $salesDefault;
$purchaseDefault = is_post() ? (string)p('purchase_default') : $purchaseDefault;
$baseCode = (string)fv('base_unit', $edit, 'pc');

layout_start('U/M Set List', ['actions' => page_link('inv.items', [], 'Item List', 'btn-outline-secondary', 'box-seam')
    . ($edit ? ' ' . btn_link(url($pageKey), 'New U/M set', 'btn-outline-primary', 'plus-lg') : '')]);
?>
<p class="text-muted small">Stock, costs and reports are always kept in the <strong>base unit</strong>. A document line can be entered in any
  unit of the set: the quantity is multiplied by the number of base units it contains and the price is divided by it, so a case of 12 at
  1,200.00 is stored as 12 pieces at 100.00 each.</p>
<div class="row g-3">
  <div class="col-lg-6"><div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
    <thead><tr><th>Set</th><th>Kind</th><th>Base unit</th><th>Units</th><th class="num">Items</th><th></th></tr></thead>
    <?php foreach ($sets as $s): ?>
      <tr class="<?= (int)$s['is_active'] ? '' : 'text-muted' ?>">
        <td><?= h($s['name']) ?><?= (int)$s['is_active'] ? '' : ' <span class="badge text-bg-secondary">inactive</span>' ?></td>
        <td class="small"><?= h(Uom::KINDS[$s['uom_kind']] ?? $s['uom_kind']) ?></td>
        <td><?= h($s['base_unit']) ?></td>
        <td class="small"><?= h((string)$s['codes']) ?></td>
        <td class="num"><?= (int)$s['items'] ?></td>
        <td><?= Access::can($pageKey, 'edit') ? '<a href="' . h(url($pageKey, ['id' => $s['id']])) . '">Edit</a>' : '' ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$sets): ?><tr><td colspan="6" class="text-muted">No U/M sets yet. Create one on the right.</td></tr><?php endif; ?>
  </table></div></div></div>

  <div class="col-lg-6">
    <?php if ($canEdit): ?>
    <form method="post" class="card"><?= csrf_field() ?>
      <div class="card-header"><?= $edit ? 'Edit U/M set: ' . h($edit['name']) : 'New U/M set' ?></div>
      <div class="card-body">
        <div class="row g-2">
          <?= input_field('name', 'Set name', fv('name', $edit), ['class' => 'col-md-5', 'required' => true]) ?>
          <?= select_field('uom_kind', 'Kind', Uom::KINDS, fv('uom_kind', $edit, 'count'), ['class' => 'col-md-3']) ?>
          <?= input_field('base_unit', 'Base unit', $baseCode, ['class' => 'col-md-2', 'required' => true, 'extra' => 'id="baseUnit"']) ?>
          <?= check_field('is_active', 'Active', fv('is_active', $edit, 1), 'col-md-2') ?>
        </div>
        <hr>
        <div class="small text-muted mb-1">Other units in this set — how many <span class="base-unit"><?= h($baseCode) ?></span> each one contains.</div>
        <table class="table table-sm" id="umTable">
          <thead><tr><th>Unit</th><th>Description</th><th class="num">= how many <span class="base-unit"><?= h($baseCode) ?></span></th><th></th></tr></thead>
          <tbody>
          <?php foreach ($extra as $i => $u): ?>
            <tr>
              <td><input type="hidden" name="u[<?= $i ?>][id]" value="<?= h($u['id'] ?? '') ?>">
                <input class="form-control form-control-sm" name="u[<?= $i ?>][code]" value="<?= h($u['code'] ?? '') ?>" placeholder="box"></td>
              <td><input class="form-control form-control-sm" name="u[<?= $i ?>][name]" value="<?= h($u['name'] ?? '') ?>" placeholder="Box of 12"></td>
              <td><input class="form-control form-control-sm text-end" name="u[<?= $i ?>][factor]" value="<?= h(isset($u['factor']) && $u['factor'] !== '' ? rtrim(rtrim(number_format(to_num($u['factor']), 6, '.', ''), '0'), '.') : '') ?>"></td>
              <td><button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="this.closest('tr').remove()"><i class="bi bi-x-lg"></i></button></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <template id="umTpl"><tr>
          <td><input type="hidden" name="u[__i__][id]" value=""><input class="form-control form-control-sm" name="u[__i__][code]" placeholder="box"></td>
          <td><input class="form-control form-control-sm" name="u[__i__][name]" placeholder="Box of 12"></td>
          <td><input class="form-control form-control-sm text-end" name="u[__i__][factor]"></td>
          <td><button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="this.closest('tr').remove()"><i class="bi bi-x-lg"></i></button></td></tr></template>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="umAdd"><i class="bi bi-plus"></i> Add unit</button>
        <div class="row g-2 mt-2">
          <div class="col-md-6"><label class="form-label">Unit sales documents start in</label>
            <input class="form-control form-control-sm" name="sales_default" value="<?= h($salesDefault) ?>" placeholder="<?= h($baseCode) ?>" list="umCodes"></div>
          <div class="col-md-6"><label class="form-label">Unit purchases start in</label>
            <input class="form-control form-control-sm" name="purchase_default" value="<?= h($purchaseDefault) ?>" placeholder="<?= h($baseCode) ?>" list="umCodes"></div>
          <datalist id="umCodes"><?php foreach ($units as $u): ?><option value="<?= h($u['code']) ?>"><?php endforeach; ?></datalist>
        </div>
      </div>
      <div class="card-footer"><?= save_buttons('Save U/M set', $edit ? url($pageKey) : null) ?></div>
    </form>
    <?php else: ?>
      <div class="alert alert-secondary">You can view the U/M sets but not change them.</div>
    <?php endif; ?>
  </div>
</div>
<script>
(function () {
  var t = document.getElementById('umTpl'), tb = document.querySelector('#umTable tbody'), add = document.getElementById('umAdd');
  if (add) add.addEventListener('click', function () {
    tb.insertAdjacentHTML('beforeend', t.innerHTML.replace(/__i__/g, 'n' + Date.now()));
  });
  var base = document.getElementById('baseUnit');
  if (base) base.addEventListener('input', function () {
    document.querySelectorAll('.base-unit').forEach(function (el) { el.textContent = base.value || 'base unit'; });
  });
})();
</script>
<?php
layout_end();
