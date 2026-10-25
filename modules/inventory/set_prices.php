<?php
declare(strict_types=1);

/**
 * Inventory > Set Item Prices and Levels.
 *
 * One grid holding the default selling price and every price level, for all items or for one type
 * or category, so a whole price list can be set in one pass and saved once.
 *
 * A price level cell left empty means "follow the level's percentage of the default price"; the
 * figure that would be used is shown in grey behind it. Typing a figure saves it as a fixed price
 * for that item on that level (a row in price_level_items).
 */

$pageKey = 'inv.set_prices';
$canSave = Access::can($pageKey, 'create') || Access::can($pageKey, 'edit');
$viewCosts = Access::viewCosts();

$types = ['inventory' => 'Inventory parts', 'assembly' => 'Assemblies', 'non_inventory' => 'Non-inventory parts',
    'service' => 'Services', 'other_charge' => 'Other charges'];
$type = array_key_exists((string)g('type'), $types) ? (string)g('type') : '';
$catId = gint('category_id');
$find = trim((string)g('find'));
$showInactive = g('inactive') === '1';

$levels = DB::all('SELECT * FROM price_levels WHERE is_active = 1 ORDER BY id');

// ------------------------------------------------------------------ the items on the page
$where = "i.item_type IN ('inventory','assembly','non_inventory','service','other_charge')";
$params = [];
if (!$showInactive) {
    $where .= ' AND i.is_active = 1';
}
if ($type) {
    $where .= ' AND i.item_type = :t';
    $params['t'] = $type;
}
if ($catId) {
    $where .= ' AND i.category_id = :c';
    $params['c'] = $catId;
}
if ($find !== '') {
    $where .= ' AND (i.code LIKE :f OR i.name LIKE :f OR i.barcode LIKE :f)';
    $params['f'] = '%' . $find . '%';
}

$total = (int)DB::value("SELECT COUNT(*) FROM items i WHERE $where", $params);
$perPage = in_array((int)g('per', 100), [50, 100, 250, 500], true) ? (int)g('per', 100) : 100;
$all = g('per') === 'all';
$page = max(1, gint('pg') ?: 1);
$pages = $all ? 1 : max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$limit = $all ? '' : ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage);

$items = DB::all("SELECT i.id, i.code, i.name, i.item_type, i.cost, i.avg_cost, i.sales_price, i.is_active,
                         c.name AS category
                    FROM items i LEFT JOIN item_categories c ON c.id = i.category_id
                   WHERE $where ORDER BY i.code" . $limit, $params);

$ids = array_column($items, 'id');
$custom = [];
if ($ids) {
    [$in, $inParams] = DB::in('i', $ids);
    foreach (DB::all("SELECT price_level_id, item_id, custom_price FROM price_level_items WHERE item_id IN $in", $inParams) as $r) {
        $custom[(int)$r['item_id']][(int)$r['price_level_id']] = (float)$r['custom_price'];
    }
}

function sp_level_price(float $base, array $level): float
{
    return r2($base * (1 + (float)$level['adjust_pct'] / 100));
}

function sp_cost(array $it): float
{
    return in_array($it['item_type'], ['inventory', 'assembly'], true) && (float)$it['avg_cost'] > 0
        ? (float)$it['avg_cost'] : (float)$it['cost'];
}

// ------------------------------------------------------------------ rename the levels, change their percentages
$canLevels = Access::can('coa.lists', 'edit');
handle_post(function () use ($pageKey, $canLevels) {
    if (p('do') !== 'levels') {
        return;
    }
    if (!$canLevels) {
        Access::denyPage($pageKey, 'rename price levels on');
    }
    $all = DB::all('SELECT * FROM price_levels ORDER BY id');
    $names = (array)($_POST['lv_name'] ?? []);
    $pcts = (array)($_POST['lv_pct'] ?? []);
    $active = (array)($_POST['lv_active'] ?? []);
    $seen = [];
    $changes = [];
    foreach ($all as $lv) {
        $id = (int)$lv['id'];
        if (!array_key_exists($id, $names)) {
            continue;
        }
        $name = mb_substr(trim((string)$names[$id]), 0, 40);
        if ($name === '') {
            fail('Every price level needs a name (level ' . $id . ' is blank).');
        }
        if (isset($seen[mb_strtolower($name)])) {
            fail('Two price levels cannot have the same name ("' . $name . '").');
        }
        $seen[mb_strtolower($name)] = true;
        $pct = round(to_num((string)($pcts[$id] ?? $lv['adjust_pct'])), 2);
        if ($pct < -100 || $pct > 1000) {
            fail('The percentage of "' . $name . '" must be between -100 and 1000.');
        }
        $d = ['name' => $name, 'adjust_pct' => $pct, 'is_active' => !empty($active[$id]) ? 1 : 0];
        if ($d['name'] !== $lv['name'] || abs($d['adjust_pct'] - (float)$lv['adjust_pct']) > 0.004 || $d['is_active'] !== (int)$lv['is_active']) {
            DB::update('price_levels', $d, 'id = :id', ['id' => $id]);
            Audit::log('edit', 'coa', 'price_levels', $id, $d['name'], ['before' => $lv, 'after' => $d, 'page_key' => $pageKey]);
            $changes[] = $lv['name'] === $d['name'] ? $d['name'] : $lv['name'] . ' -> ' . $d['name'];
        }
    }
    flash('success', $changes ? 'Price levels saved: ' . implode(', ', $changes) . '.' : 'Nothing was changed: the price levels are already as shown.');
    redirect(current_url());
});

// ------------------------------------------------------------------ save
handle_post(function () use ($pageKey, $items, $levels, $custom, $canSave) {
    if (p('do') !== 'save') {
        return;
    }
    if (!$canSave) {
        Access::denyPage($pageKey, 'set prices on');
    }
    $prices = (array)($_POST['price'] ?? []);
    $levelPrices = (array)($_POST['level'] ?? []);
    $changedItems = 0;
    $changedLevels = 0;

    DB::tx(function () use ($items, $levels, $custom, $prices, $levelPrices, $pageKey, &$changedItems, &$changedLevels) {
        foreach ($items as $it) {
            $id = (int)$it['id'];
            $label = $it['code'] . ' ' . $it['name'];
            $changes = [];

            // an empty default price box keeps the price the item has (only a price level can be left empty)
            if (array_key_exists($id, $prices) && trim((string)$prices[$id]) !== '') {
                $new = r2(to_num((string)$prices[$id]));
                if ($new < 0) {
                    fail('A price cannot be less than nothing (' . $label . ').');
                }
                if (abs($new - (float)$it['sales_price']) > 0.004) {
                    DB::update('items', ['sales_price' => $new], 'id = :id', ['id' => $id]);
                    $changes[] = ['field' => 'Default price', 'old' => money($it['sales_price']), 'new' => money($new)];
                    $changedItems++;
                }
                $base = $new;
            } else {
                $base = (float)$it['sales_price'];
            }

            foreach ($levels as $lv) {
                $lid = (int)$lv['id'];
                if (!isset($levelPrices[$id]) || !array_key_exists($lid, (array)$levelPrices[$id])) {
                    continue;
                }
                $raw = trim((string)$levelPrices[$id][$lid]);
                $was = $custom[$id][$lid] ?? null;
                if ($raw === '') {
                    if ($was !== null) {
                        DB::run('DELETE FROM price_level_items WHERE price_level_id = :p AND item_id = :i', ['p' => $lid, 'i' => $id]);
                        $changes[] = ['field' => $lv['name'], 'old' => money($was), 'new' => 'follows ' . pct($lv['adjust_pct']) . ' of the default price'];
                        $changedLevels++;
                    }
                    continue;
                }
                $new = r2(to_num($raw));
                if ($new < 0) {
                    fail('A price cannot be less than nothing (' . $label . ', ' . $lv['name'] . ').');
                }
                // a figure equal to the percentage price is kept as a fixed price only if it was one already:
                // that way "fill the column" does not litter the table with rows that change nothing
                if ($was === null && abs($new - sp_level_price($base, $lv)) < 0.005) {
                    continue;
                }
                if ($was !== null && abs($new - $was) < 0.005) {
                    continue;
                }
                if ($was === null) {
                    DB::insert('price_level_items', ['price_level_id' => $lid, 'item_id' => $id, 'custom_price' => $new]);
                } else {
                    DB::run('UPDATE price_level_items SET custom_price = :v WHERE price_level_id = :p AND item_id = :i',
                        ['v' => $new, 'p' => $lid, 'i' => $id]);
                }
                $changes[] = ['field' => $lv['name'], 'old' => $was === null ? 'follows the level' : money($was), 'new' => money($new)];
                $changedLevels++;
            }

            if ($changes) {
                Audit::log('price_change', 'inv', 'item', $id, $label,
                    ['changes' => $changes, 'page_key' => $pageKey, 'reason' => trim((string)p('reason')) ?: 'Set in one batch']);
            }
        }
    });

    if (!$changedItems && !$changedLevels) {
        flash('info', 'Nothing was changed: the prices on this page are already what is shown.');
    } else {
        flash('success', $changedItems . ' default price(s) and ' . $changedLevels . ' price level figure(s) saved.'
            . ' New prices apply immediately on invoices, sales receipts, the POS and the Item Price List.');
    }
    redirect(current_url());
});

$filterUrl = fn(array $more = []) => url($pageKey, array_filter([
    'type' => $type, 'category_id' => $catId ?: null, 'find' => $find ?: null,
    'per' => $all ? 'all' : $perPage, 'inactive' => $showInactive ? '1' : null,
] + $more, fn($v) => $v !== null && $v !== ''));

layout_start('Set Item Prices and Levels', ['actions' =>
    ($canLevels ? '<button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#levelsPanel"><i class="bi bi-tag"></i> Rename price levels</button> ' : '')
    . page_link('inv.change_prices', [], 'Change prices by percentage', 'btn-outline-secondary', 'percent')
    . ' ' . page_link('rpt.item_price_list', [], 'Item Price List', 'btn-outline-secondary', 'tags')]);
?>
<p class="text-muted">Set the default selling price and every price level for as many items as you like, then save once.
  Leave a price level empty to let it follow its own percentage of the default price; the figure it would use is shown in grey.
  Type a figure to fix that item's price on that level.</p>

<form method="get" class="row g-2 mb-3 filters"><input type="hidden" name="p" value="<?= h($pageKey) ?>">
  <?= select_field('type', 'Item type', $types, $type, ['blank' => 'All types', 'class' => 'col-md-2', 'extra' => 'data-autosubmit']) ?>
  <?= select_field('category_id', 'Category', Lookup::categories(), $catId, ['blank' => 'All categories', 'class' => 'col-md-2', 'extra' => 'data-autosubmit']) ?>
  <?= input_field('find', 'Find (code, name or barcode)', $find, ['class' => 'col-md-3']) ?>
  <?= select_field('per', 'Items per page', ['50' => '50', '100' => '100', '250' => '250', '500' => '500', 'all' => 'All of them'], $all ? 'all' : (string)$perPage, ['class' => 'col-md-2', 'extra' => 'data-autosubmit']) ?>
  <div class="col-md-3 d-flex align-items-end gap-2">
    <div class="form-check"><input class="form-check-input" type="checkbox" id="showInactive" name="inactive" value="1" <?= $showInactive ? 'checked' : '' ?> data-autosubmit>
      <label class="form-check-label" for="showInactive">Include inactive items</label></div>
    <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i> Show</button>
  </div>
</form>

<?php if (!$levels): ?>
<div class="alert alert-warning">No price levels are set up yet. Add them in <?= page_link('coa.lists', ['tab' => 'price_levels'], 'the Price Level list', 'btn-link p-0 align-baseline') ?> first.</div>
<?php endif; ?>

<?php if ($canLevels): $allLevels = DB::all('SELECT * FROM price_levels ORDER BY id'); ?>
<div class="collapse mb-3" id="levelsPanel">
  <form method="post" class="card level-names"><?= csrf_field() ?><input type="hidden" name="do" value="levels">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span class="fw-semibold"><i class="bi bi-tag"></i> The price levels: names and percentages</span>
      <span class="small text-muted">A level's percentage is applied to the default price wherever no fixed price is typed for an item. An inactive level is hidden everywhere but keeps its prices.</span>
    </div>
    <div class="card-body">
      <div class="row g-2 level-grid">
        <?php foreach ($allLevels as $i => $lv): $lid = (int)$lv['id']; ?>
          <div class="col-6 col-md-4 col-xl-2dot4">
            <div class="level-box <?= (int)$lv['is_active'] ? '' : 'is-off' ?>">
              <div class="level-no">Level <?= $i + 1 ?></div>
              <input class="form-control form-control-sm fw-semibold" name="lv_name[<?= $lid ?>]" value="<?= h($lv['name']) ?>" maxlength="40" required aria-label="Name of level <?= $i + 1 ?>">
              <div class="input-group input-group-sm mt-1">
                <input class="form-control text-end" name="lv_pct[<?= $lid ?>]" value="<?= h((string)(float)$lv['adjust_pct']) ?>" inputmode="decimal" aria-label="Percentage of level <?= $i + 1 ?>">
                <span class="input-group-text">%</span>
              </div>
              <label class="form-check small mt-1 mb-0"><input class="form-check-input" type="checkbox" name="lv_active[<?= $lid ?>]" value="1" <?= (int)$lv['is_active'] ? 'checked' : '' ?>> Active</label>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="d-flex flex-wrap gap-2 align-items-center mt-3">
        <button class="btn btn-sm btn-primary"><i class="bi bi-check-lg"></i> Save the price levels</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#levelsPanel">Close</button>
        <span class="small text-muted">A negative percentage is a discount (-5 = 5% below the default price); a positive one is a mark-up. The customer's level is chosen on the customer's record.</span>
      </div>
    </div>
  </form>
</div>
<?php endif; ?>

<form method="post" id="setPricesForm"><?= csrf_field() ?>
<input type="hidden" name="do" value="save">

<div class="card mb-3"><div class="card-body row g-2 align-items-end">
  <div class="col-md-3"><label class="form-label">Fill a column</label>
    <select class="form-select form-select-sm" id="fillCol">
      <option value="price">Default price</option>
      <?php foreach ($levels as $lv): ?><option value="<?= (int)$lv['id'] ?>"><?= h($lv['name']) ?></option><?php endforeach; ?>
    </select></div>
  <div class="col-md-3"><label class="form-label">With</label>
    <select class="form-select form-select-sm" id="fillHow">
      <option value="level" data-levels-only>The level's own percentage of the default price</option>
      <option value="pct">The default price, changed by a percentage</option>
      <option value="cost">Cost plus a markup percentage</option>
      <option value="fixed">One figure for every row</option>
      <option value="clear" data-levels-only>Nothing (empty the column)</option>
    </select></div>
  <div class="col-md-2"><label class="form-label">Percent / figure</label>
    <input class="form-control form-control-sm" type="text" id="fillValue" value="0" autocomplete="off"></div>
  <div class="col-md-2"><label class="form-label">Round to nearest</label>
    <select class="form-select form-select-sm" id="fillRound">
      <option value="0">No rounding</option><option value="0.05">0.05</option><option value="1">1.00</option>
      <option value="10">10.00</option><option value="100">100.00</option></select></div>
  <div class="col-md-2 d-flex gap-2">
    <button type="button" class="btn btn-sm btn-outline-primary w-100" id="fillGo"><i class="bi bi-magic"></i> Fill the column</button></div>
  <div class="col-12 small text-muted">Filling a column only writes the figures into the boxes below. Nothing is saved until you press Save prices.
    The level's own percentage and Nothing are for the price level columns: an empty level follows its percentage, while every item keeps a default price.</div>
</div></div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span class="fw-semibold"><i class="bi bi-tags"></i> <?= number_format($total) ?> item(s)<?= $all || $pages === 1 ? '' : ', page ' . $page . ' of ' . $pages ?></span>
    <span class="small text-muted">Prices exclude tax unless your items are set up tax-inclusive.</span>
  </div>
  <div class="table-responsive"><table class="table table-sm table-tight mb-0 align-middle price-grid">
    <thead><tr>
      <?php [$h1, $h2] = item_cols(); ?>
      <th><?= h($h1) ?></th><th><?= h($h2) ?></th><th>Category</th>
      <?php if ($viewCosts): ?><th class="num">Cost</th><?php endif; ?>
      <th class="num" style="width:120px">Default price</th>
      <?php foreach ($levels as $lv): ?>
        <th class="num" style="width:120px"><?= h($lv['name']) ?>
          <div class="fw-normal small text-muted"><?= (float)$lv['adjust_pct'] == 0.0 ? 'same as default' : pct($lv['adjust_pct']) ?></div></th>
      <?php endforeach; ?>
    </tr></thead>
    <tbody>
    <?php if (!$items): ?>
      <tr><td colspan="<?= 4 + ($viewCosts ? 1 : 0) + count($levels) ?>" class="text-center text-muted py-4">No items match what you asked for.</td></tr>
    <?php endif; ?>
    <?php foreach ($items as $it): $id = (int)$it['id']; [$c1, $c2] = item_vals($it['code'], $it['name']); ?>
      <tr data-item="<?= $id ?>" data-cost="<?= h((string)sp_cost($it)) ?>">
        <td class="text-nowrap"><?= h($c1) ?><?= $it['is_active'] ? '' : ' ' . badge('inactive', 'secondary') ?></td>
        <td><?= h($c2) ?></td>
        <td class="small text-muted"><?= h((string)$it['category']) ?></td>
        <?php if ($viewCosts): ?><td class="num small"><?= money(sp_cost($it)) ?></td><?php endif; ?>
        <td><input class="form-control form-control-sm num price-default" type="text" inputmode="decimal"
                   name="price[<?= $id ?>]" value="<?= h(number_format((float)$it['sales_price'], 2, '.', '')) ?>"
                   data-item="<?= $id ?>" autocomplete="off"></td>
        <?php foreach ($levels as $lv): $lid = (int)$lv['id']; $c = $custom[$id][$lid] ?? null; ?>
          <td><input class="form-control form-control-sm num price-level" type="text" inputmode="decimal"
                     name="level[<?= $id ?>][<?= $lid ?>]" value="<?= $c === null ? '' : h(number_format($c, 2, '.', '')) ?>"
                     data-item="<?= $id ?>" data-level="<?= $lid ?>" data-adjust="<?= h((string)(float)$lv['adjust_pct']) ?>"
                     placeholder="<?= h(number_format(sp_level_price((float)$it['sales_price'], $lv), 2, '.', '')) ?>"
                     autocomplete="off"></td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($items && $canSave): ?>
  <div class="card-footer d-flex flex-wrap gap-2 align-items-end">
    <div style="min-width:260px"><label class="form-label mb-1 small">Reason (recorded in the audit trail)</label>
      <input class="form-control form-control-sm" type="text" name="reason" maxlength="200" placeholder="New price list, effective this month"></div>
    <button class="btn btn-primary btn-sm" data-confirm="Save the prices on this page?"><i class="bi bi-check-lg"></i> Save prices</button>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(current_url()) ?>">Start again</a>
    <span class="small text-muted ms-auto">Only the items on this page are saved. Move to the next page after saving.</span>
  </div>
  <?php endif; ?>
</div>
</form>

<?php if (!$all && $pages > 1): ?>
<nav class="mt-3"><ul class="pagination pagination-sm">
  <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= h($filterUrl(['pg' => $page - 1])) ?>">Previous</a></li>
  <?php for ($i = max(1, $page - 4); $i <= min($pages, $page + 4); $i++): ?>
    <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="<?= h($filterUrl(['pg' => $i])) ?>"><?= $i ?></a></li>
  <?php endfor; ?>
  <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= h($filterUrl(['pg' => $page + 1])) ?>">Next</a></li>
</ul></nav>
<?php endif; ?>

<script>
(function () {
  var form = document.getElementById('setPricesForm');
  if (!form) { return; }

  function num(v) { var n = parseFloat(String(v).replace(/[^0-9.\-]/g, '')); return isFinite(n) ? n : 0; }
  function roundTo(v, step) { return step > 0 ? Math.round(v / step) * step : v; }
  function show(v) { return (Math.round(v * 100) / 100).toFixed(2); }

  // the grey figure behind an empty price level box follows the default price as it is typed
  function refresh(row) {
    var base = num(row.querySelector('.price-default').value);
    row.querySelectorAll('.price-level').forEach(function (box) {
      box.placeholder = show(base * (1 + num(box.dataset.adjust) / 100));
    });
  }
  form.addEventListener('input', function (e) {
    if (e.target.classList.contains('price-default')) { refresh(e.target.closest('tr')); }
  });

  // the default price cannot follow a level or be emptied: those two choices are for the price level columns
  var fillCol = document.getElementById('fillCol'), fillHow = document.getElementById('fillHow');
  function levelsOnly() {
    var isPrice = fillCol.value === 'price';
    fillHow.querySelectorAll('option[data-levels-only]').forEach(function (o) { o.disabled = isPrice; });
    if (isPrice && fillHow.selectedOptions[0] && fillHow.selectedOptions[0].disabled) { fillHow.value = 'pct'; }
  }
  fillCol.addEventListener('change', levelsOnly);
  levelsOnly();

  document.getElementById('fillGo').addEventListener('click', function () {
    var col = fillCol.value;
    var how = fillHow.value;
    if (col === 'price' && (how === 'clear' || how === 'level')) { return; }
    var value = num(document.getElementById('fillValue').value);
    var step = num(document.getElementById('fillRound').value);
    form.querySelectorAll('tbody tr[data-item]').forEach(function (row) {
      var def = row.querySelector('.price-default');
      var box = col === 'price' ? def : row.querySelector('.price-level[data-level="' + col + '"]');
      if (!box) { return; }
      if (how === 'clear') { box.value = ''; refresh(row); return; }
      var base = num(def.value);
      var out;
      if (how === 'level') {
        if (col === 'price') { return; }
        out = base * (1 + num(box.dataset.adjust) / 100);
      } else if (how === 'pct') {
        out = base * (1 + value / 100);
      } else if (how === 'cost') {
        out = num(row.dataset.cost) * (1 + value / 100);
      } else {
        out = value;
      }
      box.value = show(Math.max(0, roundTo(out, step)));
      refresh(row);
    });
  });
})();
</script>
<?php
layout_end();
