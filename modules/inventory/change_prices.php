<?php
declare(strict_types=1);

$pageKey = 'inv.change_prices';
$types = ['inventory' => 'Inventory parts', 'assembly' => 'Assemblies', 'non_inventory' => 'Non-inventory parts', 'service' => 'Services', 'other_charge' => 'Other charges'];
$type = array_key_exists(g('type'), $types) ? g('type') : '';
$catId = gint('category_id');
$viewCosts = Access::viewCosts();

$sql = "SELECT i.*, c.name AS category FROM items i LEFT JOIN item_categories c ON c.id = i.category_id
         WHERE i.is_active = 1 AND i.item_type IN ('inventory','assembly','non_inventory','service','other_charge')";
$params = [];
if ($type) {
    $sql .= ' AND i.item_type = :t';
    $params['t'] = $type;
}
if ($catId) {
    $sql .= ' AND i.category_id = :c';
    $params['c'] = $catId;
}
$items = DB::all($sql . ' ORDER BY i.code', $params);

function new_price(array $it, string $basis, string $how, float $value, float $round): float
{
    if ($basis === 'cost') {
        $base = in_array($it['item_type'], ['inventory', 'assembly'], true) && (float)$it['avg_cost'] > 0 ? (float)$it['avg_cost'] : (float)$it['cost'];
        $price = $base * (1 + $value / 100);        // markup on cost
    } else {
        $base = (float)$it['sales_price'];
        $price = $how === 'percent' ? $base * (1 + $value / 100) : $base + $value;
    }
    if ($round > 0) {
        $price = round($price / $round) * $round;
    }
    return max(0.0, round($price, 2));
}

$basis = p('basis', g('basis', 'current')) === 'cost' ? 'cost' : 'current';
$how = p('how', 'percent') === 'amount' ? 'amount' : 'percent';
$value = to_num(p('value', '0'));
$round = to_num(p('round', '0'));

handle_post(function () use ($pageKey, $items, $basis, $how, $value, $round) {
    if (p('do') !== 'apply') {
        return;
    }
    Access::require($pageKey, 'create');
    $selected = array_map('intval', (array)($_POST['items'] ?? []));
    if (!$selected) {
        fail('Select at least one item.');
    }
    $changes = [];
    foreach ($items as $it) {
        if (!in_array((int)$it['id'], $selected, true)) {
            continue;
        }
        $np = new_price($it, $basis, $how, $value, $round);
        if (abs($np - (float)$it['sales_price']) < 0.005) {
            continue;
        }
        DB::update('items', ['sales_price' => $np], 'id = :id', ['id' => $it['id']]);
        Audit::log('price_change', 'inv', 'item', (int)$it['id'], $it['code'] . ' ' . $it['name'], [
            'changes' => [['field' => 'sales_price', 'old' => $it['sales_price'], 'new' => $np]], 'page_key' => $pageKey,
            'reason' => $basis === 'cost' ? "Markup $value% on cost" : ($how === 'percent' ? "Adjust $value%" : "Adjust by $value"),
        ]);
        $changes[] = $it['code'];
    }
    flash('success', count($changes) . ' price(s) updated. New prices apply immediately on invoices, sales receipts, the POS and the Item Price List.');
    redirect(current_url());
});

layout_start('Change Item Prices', ['actions' => page_link('rpt.item_price_list', [], 'Item Price List', 'btn-outline-secondary', 'tags')]);
?>
<form method="get" class="row g-2 mb-2 filters"><input type="hidden" name="p" value="<?= h($pageKey) ?>">
  <?= select_field('type', 'Item type', $types, $type, ['blank' => 'All types', 'class' => 'col-md-3', 'extra' => 'data-autosubmit']) ?>
  <?= select_field('category_id', 'Category', Lookup::categories(), $catId, ['blank' => 'All categories', 'class' => 'col-md-3', 'extra' => 'data-autosubmit']) ?>
</form>
<form method="post"><?= csrf_field() ?>
<div class="card mb-3"><div class="card-body row g-2 align-items-end">
  <?= select_field('basis', 'Change', array_merge(['current' => 'Adjust the current price'], $viewCosts ? ['cost' => 'Set new price from cost (markup)'] : []), $basis, ['class' => 'col-md-3', 'extra' => 'id="basisSel"']) ?>
  <div class="col-md-2" data-toggle-by="#basisSel" data-show-when="current"><label class="form-label">By</label><select class="form-select form-select-sm" name="how"><?= options(['percent' => 'Percent', 'amount' => 'Fixed amount'], $how) ?></select></div>
  <?= input_field('value', 'Percent / amount (negative to lower)', (string)$value, ['class' => 'col-md-2']) ?>
  <?= select_field('round', 'Round to nearest', ['0' => 'No rounding', '0.05' => '0.05', '1' => '1.00', '10' => '10.00', '100' => '100.00'], (string)$round, ['class' => 'col-md-2']) ?>
  <div class="col-md-3 d-flex gap-2"><button class="btn btn-sm btn-outline-secondary" name="do" value="preview">Preview</button>
    <?php if (Access::can($pageKey, 'create')): ?><button class="btn btn-sm btn-primary" name="do" value="apply" data-confirm="Update the selected prices?">Apply to selected</button><?php endif; ?></div>
</div></div>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0">
  <thead><tr><th><input type="checkbox" class="form-check-input" data-check-all=".cp-cb" checked></th><?php [$h1, $h2] = item_cols(); ?><th><?= h($h1) ?></th><th><?= h($h2) ?></th><th>Category</th><?= $viewCosts ? '<th class="num">Cost</th>' : '' ?><th class="num">Current price</th><th class="num">New price</th></tr></thead>
  <?php foreach ($items as $it): $np = new_price($it, $basis, $how, $value, $round); $sel = !is_post() || in_array((string)$it['id'], (array)($_POST['items'] ?? []), true); ?>
    <tr><td><input type="checkbox" class="form-check-input cp-cb" name="items[]" value="<?= (int)$it['id'] ?>" <?= $sel ? 'checked' : '' ?>></td><?php [$c1, $c2] = item_vals($it['code'], $it['name']); ?><td><?= h($c1) ?></td><td><?= h($c2) ?></td><td class="small"><?= h($it['category']) ?></td>
      <?php if ($viewCosts): ?><td class="num"><?= money(in_array($it['item_type'], ['inventory', 'assembly'], true) && (float)$it['avg_cost'] > 0 ? $it['avg_cost'] : $it['cost']) ?></td><?php endif; ?>
      <td class="num"><?= money($it['sales_price']) ?></td><td class="num <?= abs($np - (float)$it['sales_price']) > 0.004 ? 'fw-semibold text-primary' : '' ?>"><?= money($np) ?></td></tr>
  <?php endforeach; ?>
</table></div></div>
<p class="small text-muted mt-2">Changing a price does not change the value of stock already on hand.</p>
</form>
<?php
layout_end();
