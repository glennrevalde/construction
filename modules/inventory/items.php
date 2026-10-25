<?php
declare(strict_types=1);

require_once APP_ROOT . '/modules/txn/_common.php';

$pageKey = 'inv.items';
$a = g('a');
$typeLabels = [
    'service' => 'Service', 'inventory' => 'Inventory part', 'non_inventory' => 'Non-inventory part', 'assembly' => 'Inventory assembly',
    'other_charge' => 'Other charge', 'subtotal' => 'Subtotal', 'group' => 'Group', 'discount' => 'Discount', 'payment' => 'Payment',
];
$viewCosts = Access::viewCosts();

if ($a === 'new' || $a === 'edit') {
    $it = null;
    if ($a === 'edit') {
        $it = DB::row('SELECT * FROM items WHERE id = :id', ['id' => (int)g('id')]);
        if (!$it) {
            redirect(url($pageKey));
        }
    } else {
        Access::require($pageKey, 'create');
    }
    $canEdit = $a === 'new' || Access::can($pageKey, 'edit');
    $type = $it['item_type'] ?? (array_key_exists(g('type'), $typeLabels) ? g('type') : 'inventory');
    $tracked = in_array($type, ['inventory', 'assembly'], true);
    $components = $it ? DB::all('SELECT ic.*, i.code, i.name, i.cost, i.avg_cost, i.item_type FROM item_components ic JOIN items i ON i.id = ic.component_item_id WHERE ic.parent_item_id = :p', ['p' => $it['id']]) : [];

    handle_post(function () use ($it, $type, $tracked, $pageKey, $canEdit) {
        if (!$canEdit) {
            Access::denyPage($pageKey, 'edit');
        }
        $d = [
            'item_type' => $type,
            'code' => mb_substr(trim((string)p('code')), 0, 40),
            'barcode' => p('barcode') ?: null,
            'name' => mb_substr(trim((string)p('name')), 0, 120),
            'description' => p('description') ?: null,
            'purchase_description' => p('purchase_description') ?: null,
            'category_id' => pint('category_id'),
            'uom_set_id' => pint('uom_set_id'),
            'sales_uom_id' => pint('sales_uom_id'),
            'purchase_uom_id' => pint('purchase_uom_id'),
            'unit' => p('unit') ?: null,
            'sales_price' => round(pnum('sales_price'), 4),
            'discount_pct' => round(pnum('discount_pct'), 2),
            'income_account_id' => pint('income_account_id'),
            'cogs_account_id' => pint('cogs_account_id'),
            'asset_account_id' => pint('asset_account_id'),
            'expense_account_id' => pint('expense_account_id'),
            'tax_code_id' => pint('tax_code_id'),
            'preferred_vendor_id' => pint('preferred_vendor_id'),
            'reorder_point' => pnum('reorder_point'),
            'max_stock' => pnum('max_stock'),
            'build_point' => pnum('build_point'),
            'is_active' => p('is_active', '1') === '1' ? 1 : 0,
        ];
        if (Access::viewCosts()) {
            $d['cost'] = round(pnum('cost'), 4);
        }
        // an item group: the account its price variance (group price less consolidated price) goes to
        if ($type === 'group' && ItemGroups::ready()) {
            $d['group_variance_account_id'] = pint('group_variance_account_id');
        }
        if ($d['code'] === '' || $d['name'] === '') {
            fail('Enter the item code and name.');
        }
        // an inventory part or assembly that still has stock (or a value) stays active
        if ($it && (int)$it['is_active'] === 1 && $d['is_active'] === 0 && ($why = Stock::inactiveRefusal($it))) {
            fail($why);
        }
        // a U/M set fixes the unit stock is kept in; the start units must come from that set
        if ($d['uom_set_id']) {
            $set = Uom::set($d['uom_set_id']);
            if (!$set) {
                fail('Choose a U/M set from the list.');
            }
            $d['unit'] = $set['base_unit'];
            $ids = array_map(fn($u) => (int)$u['id'], Uom::units($d['uom_set_id']));
            foreach (['sales_uom_id', 'purchase_uom_id'] as $k) {
                if ($d[$k] && !in_array($d[$k], $ids, true)) {
                    $d[$k] = null;
                }
            }
            if ($it && (int)$it['uom_set_id'] !== $d['uom_set_id']
                && DB::value('SELECT 1 FROM transaction_lines l JOIN uom_units u ON u.id = l.uom_id WHERE l.item_id = :i AND u.set_id <> :s', ['i' => $it['id'], 's' => $d['uom_set_id']])) {
                fail('This item was already entered in units of its present U/M set, so the set cannot be changed. Add the units you need to that set instead.');
            }
        } else {
            $d['sales_uom_id'] = null;
            $d['purchase_uom_id'] = null;
        }
        if (DB::value('SELECT id FROM items WHERE code = :c AND id <> :id', ['c' => $d['code'], 'id' => $it['id'] ?? 0])) {
            fail('Another item already uses this code.');
        }
        if ($d['barcode'] && DB::value('SELECT id FROM items WHERE barcode = :b AND id <> :id', ['b' => $d['barcode'], 'id' => $it['id'] ?? 0])) {
            fail('Another item already uses this barcode.');
        }
        if ($tracked) {
            $d['asset_account_id'] = $d['asset_account_id'] ?: Accts::get('inventory_asset');
            $d['cogs_account_id'] = $d['cogs_account_id'] ?: Accts::get('cogs');
        }
        if (in_array($type, ['service', 'inventory', 'non_inventory', 'assembly', 'other_charge'], true) && !$d['income_account_id']) {
            $d['income_account_id'] = Accts::get('sales_income');
        }
        $comps = [];
        if (in_array($type, ['assembly', 'group'], true)) {
            foreach ((array)($_POST['comp'] ?? []) as $c) {
                $cid = (int)($c['item_id'] ?? 0);
                $q = to_num($c['qty'] ?? 0);
                if ($cid && $q > 0) {
                    if ($it && $cid === (int)$it['id']) {
                        fail('An item cannot be a component of itself.');
                    }
                    $comps[$cid] = ($comps[$cid] ?? 0) + $q;
                }
            }
            if (!$comps) {
                fail('Add at least one component.');
            }
        }
        DB::tx(function () use (&$it, $d, $comps, $tracked, $pageKey) {
            if ($it) {
                DB::update('items', $d, 'id = :id', ['id' => $it['id']]);
                $id = (int)$it['id'];
                $oldComps = DB::pairs('SELECT component_item_id, qty FROM item_components WHERE parent_item_id = :p', ['p' => $id]);
            } else {
                $id = DB::insert('items', $d);
                $oldComps = [];
            }
            if ($comps) {
                DB::run('DELETE FROM item_components WHERE parent_item_id = :p', ['p' => $id]);
                foreach ($comps as $cid => $q) {
                    DB::insert('item_components', ['parent_item_id' => $id, 'component_item_id' => $cid, 'qty' => $q]);
                }
            }
            // price level custom prices
            foreach ((array)($_POST['pl'] ?? []) as $plId => $price) {
                DB::run('DELETE FROM price_level_items WHERE price_level_id = :p AND item_id = :i', ['p' => (int)$plId, 'i' => $id]);
                if (trim((string)$price) !== '') {
                    DB::insert('price_level_items', ['price_level_id' => (int)$plId, 'item_id' => $id, 'custom_price' => to_num($price)]);
                }
            }
            $after = $d + ['components' => array_map(fn($k, $v) => "$k x $v", array_keys($comps), $comps)];
            if ($it) {
                $before = $it + ['components' => array_map(fn($k, $v) => "$k x " . (float)$v, array_keys($oldComps), $oldComps)];
                Audit::log('edit', 'inv', 'item', $id, $d['code'] . ' ' . $d['name'], ['before' => $before, 'after' => $after, 'page_key' => $pageKey]);
            } else {
                Audit::log('create', 'inv', 'item', $id, $d['code'] . ' ' . $d['name'], ['snapshot' => $after, 'page_key' => $pageKey]);
                // opening quantity and value
                $oq = pnum('opening_qty');
                if ($tracked && $oq > 0) {
                    $ov = r2(pnum('opening_value'));
                    $unit = $oq > 0 ? $ov / $oq : 0;
                    Txn::save('inventory_adjustment', [
                        'txn_date' => pdate('opening_date', today()),
                        'site_id' => pint('opening_site_id'),
                        'account_id' => Accts::get('opening_equity'),
                        'memo' => 'Opening quantity for ' . $d['code'],
                    ], [[
                        'line_kind' => 'item', 'item_id' => $id, 'qty' => $oq, 'rate' => round($unit, 4), 'amount' => $ov,
                        'site_id' => pint('opening_site_id'), 'description' => 'Opening balance',
                    ]], null, ['reason' => 'Opening balance']);
                }
            }
            $it = ['id' => $id];
        });
        flash('success', 'Item saved.');
        redirect_after_save(url($pageKey, ['a' => 'new', 'type' => $type]), url($pageKey, ['a' => 'edit', 'id' => $it['id']]));
    });

    $title = ($it ? 'Item: ' . $it['code'] : 'New ' . strtolower($typeLabels[$type]));
    layout_start($title, ['actions' => btn_link(url($pageKey, ['type' => $type]), 'Back to list')
        . ($it && Access::page('inv.center') && $tracked ? ' ' . btn_link(url('inv.center', ['id' => $it['id']]), 'Inventory Center', 'btn-outline-secondary', 'box-seam') : '')]);
    $allAccts = Accts::all();
    $components = is_post() ? array_map(fn($c) => ['component_item_id' => $c['item_id'] ?? '', 'qty' => $c['qty'] ?? ''], (array)($_POST['comp'] ?? [])) : $components;
    $compItems = Lookup::itemPairs("is_active = 1 AND item_type IN " . ($type === 'assembly' ? "('inventory','assembly','service','other_charge','non_inventory')" : "('inventory','assembly','service','non_inventory','other_charge')"));
    ?>
    <form method="post"><?= csrf_field() ?>
    <fieldset <?= $canEdit ? '' : 'disabled' ?>>
    <div class="card mb-3"><div class="card-header"><?= h($typeLabels[$type]) ?></div><div class="card-body row g-2">
      <?= input_field('code', 'Item name / code', fv('code', $it), ['required' => true, 'class' => 'col-md-3']) ?>
      <?= input_field('name', 'Display name', fv('name', $it), ['required' => true, 'class' => 'col-md-5']) ?>
      <?php if (in_array($type, ['inventory', 'assembly', 'non_inventory'], true)): ?><?= input_field('barcode', 'Barcode', fv('barcode', $it), ['class' => 'col-md-2']) ?><?php endif; ?>
      <?php
      $uomSets = Uom::sets(true, $it && $it['uom_set_id'] ? (int)$it['uom_set_id'] : null);
      $curSet = (int)fv('uom_set_id', $it, 0);
      $uomMap = [];
      foreach (array_keys($uomSets) as $sid) {
          $uomMap[$sid] = array_map(fn($u) => ['id' => (int)$u['id'], 'code' => $u['code'], 'name' => $u['name'], 'factor' => (float)$u['factor'], 'base' => (int)$u['is_base']], Uom::units((int)$sid));
      }
      ?>
      <?php if ($uomSets): ?>
        <?= select_field('uom_set_id', 'Unit of measure (U/M set)', $uomSets, $curSet ?: '', ['blank' => 'Single unit', 'class' => 'col-md-3', 'extra' => 'id="uomSet" data-sets="' . h(json_encode($uomMap)) . '"']) ?>
        <?= select_field('sales_uom_id', 'Sales unit', Uom::unitOptions($curSet ?: null), fv('sales_uom_id', $it), ['blank' => 'Base unit', 'class' => 'col-md-2', 'extra' => 'id="salesUom"']) ?>
        <?= select_field('purchase_uom_id', 'Purchase unit', Uom::unitOptions($curSet ?: null), fv('purchase_uom_id', $it), ['blank' => 'Base unit', 'class' => 'col-md-2', 'extra' => 'id="purchUom"']) ?>
        <div class="col-md-2" id="unitPlain" <?= $curSet ? 'hidden' : '' ?>><label class="form-label">Unit</label>
          <input class="form-control form-control-sm" name="unit" value="<?= h(fv('unit', $it, 'pc')) ?>"></div>
        <div class="col-12 small text-muted" id="uomHelp" <?= $curSet ? '' : 'hidden' ?>>Stock, costs and reports stay in the base unit of the set; documents may be entered in any unit of it.
          <?= Access::page('inv.uom') ? '<a href="' . h(url('inv.uom')) . '">Manage U/M sets</a>' : '' ?></div>
      <?php else: ?>
        <?= input_field('unit', 'Unit of measure', fv('unit', $it, 'pc'), ['class' => 'col-md-2']) ?>
        <?php if (Access::page('inv.uom')): ?><div class="col-md-3 d-flex align-items-end"><a class="small" href="<?= h(url('inv.uom')) ?>">Set up U/M sets for multiple units</a></div><?php endif; ?>
      <?php endif; ?>
      <?= select_field('category_id', 'Category', Lookup::categories(), fv('category_id', $it), ['blank' => '', 'class' => 'col-md-3']) ?>
      <?php $keepActive = $it && $tracked && (int)$it['is_active'] === 1 ? Stock::inactiveRefusal($it) : null; ?>
      <?php if ($keepActive): ?>
      <div class="col-md-4 d-flex align-items-end"><div class="form-check item-active-locked" title="<?= h($keepActive) ?>">
        <input type="hidden" name="is_active" value="1">
        <input class="form-check-input" type="checkbox" id="f_is_active" checked disabled aria-describedby="activeWhy">
        <label class="form-check-label" for="f_is_active">Active</label>
        <div class="small text-muted" id="activeWhy">Has a balance: it can be made inactive once it is at zero.</div></div></div>
      <?php else: ?>
      <?= check_field('is_active', 'Active', fv('is_active', $it, 1), 'col-md-2') ?>
      <?php endif; ?>
      <?php if ($type === 'group'):
          // the group price, the consolidated price (the items of the group at their own prices) and the difference
          $consolidated = 0.0;
          foreach ($components as $c) {
              $consolidated += (float)$c['qty'] * (float)(item_cache((int)$c['component_item_id'])['sales_price'] ?? 0);
          }
          $groupPrice = (float)fv('sales_price', $it, '0');
          $incomeAccts = Accts::ofType(['Income', 'Other Income', 'Expense', 'Other Expense', 'Cost of Goods Sold']);
      ?>
      <div class="col-12"><div class="group-pricing" id="groupPricing">
        <div class="group-pricing-row">
          <div class="gp-field"><label class="form-label" for="f_sales_price">Group price</label>
            <input class="form-control form-control-sm text-end" type="number" step="0.0001" min="0" id="f_sales_price" name="sales_price" value="<?= h(rtrim(rtrim(number_format($groupPrice, 4, '.', ''), '0'), '.')) ?>">
            <div class="form-text">The price followed on estimates, sales orders, invoices, sales receipts and POS sales<?= ItemGroups::adopt() ? '' : ' (when "Adopt the Group Price" is switched on)' ?>.</div></div>
          <div class="gp-field"><label class="form-label">Consolidated price</label>
            <div class="form-control form-control-sm text-end gp-readout" id="gpConsolidated"><?= money($consolidated) ?></div>
            <div class="form-text">The sum of the prices of the items in the group.</div></div>
          <div class="gp-field"><label class="form-label">Price variance</label>
            <div class="form-control form-control-sm text-end gp-readout" id="gpVariance"><?= money($groupPrice - $consolidated) ?></div>
            <div class="form-text">Group price less consolidated price.</div></div>
          <?php if (ItemGroups::ready()): ?>
          <div class="gp-field gp-account"><label class="form-label" for="f_gvar">Price variance GL account</label>
            <select class="form-select form-select-sm" id="f_gvar" name="group_variance_account_id"><?= options($incomeAccts, fv('group_variance_account_id', $it, ''), 'Module default (Item Group Price Variance)') ?></select>
            <div class="form-text">Where the price variance of each group sold is recorded.</div></div>
          <?php endif; ?>
        </div>
      </div></div>
      <?php endif; ?>
    </div></div>
    <?php if ($uomSets): ?>
    <script>
    (function () {
      var set = document.getElementById('uomSet');
      if (!set) return;
      var sets = JSON.parse(set.dataset.sets || '{}');
      function fill(sel, units, keep) {
        sel.innerHTML = '<option value="">Base unit</option>';
        (units || []).forEach(function (u) {
          var o = document.createElement('option');
          o.value = u.id;
          o.textContent = u.code + (u.factor !== 1 ? ' = ' + u.factor + ' base' : '');
          if (String(u.id) === String(keep)) o.selected = true;
          sel.appendChild(o);
        });
      }
      set.addEventListener('change', function () {
        var units = sets[set.value] || [];
        fill(document.getElementById('salesUom'), units, '');
        fill(document.getElementById('purchUom'), units, '');
        var plain = document.getElementById('unitPlain'), help = document.getElementById('uomHelp');
        if (plain) plain.hidden = !!set.value;
        if (help) help.hidden = !set.value;
      });
    })();
    </script>
    <?php endif; ?>

    <?php if (!in_array($type, ['subtotal'], true)): ?>
    <div class="row g-3">
      <?php if (in_array($type, ['inventory', 'non_inventory', 'service', 'other_charge', 'assembly'], true)): ?>
      <div class="col-lg-6"><div class="card h-100"><div class="card-header">Purchase information</div><div class="card-body row g-2">
        <?php if ($type !== 'assembly'): ?><div class="col-12"><label class="form-label">Purchase description</label><input class="form-control form-control-sm" name="purchase_description" value="<?= h(fv('purchase_description', $it)) ?>"></div><?php endif; ?>
        <?php if ($viewCosts): ?><?= input_field('cost', $type === 'assembly' ? 'Standard cost (info)' : 'Cost', fv('cost', $it, '0'), ['type' => 'number', 'step' => '0.0001', 'class' => 'col-md-4']) ?><?php endif; ?>
        <?php if ($tracked): ?>
          <?= select_field('cogs_account_id', 'Cost of goods sold account', $allAccts, fv('cogs_account_id', $it, Accts::get('cogs')), ['class' => 'col-md-8']) ?>
        <?php else: ?>
          <?= select_field('expense_account_id', 'Expense / purchase account', $allAccts, fv('expense_account_id', $it), ['blank' => 'Default', 'class' => 'col-md-8']) ?>
        <?php endif; ?>
        <?= select_field('preferred_vendor_id', 'Preferred vendor', Lookup::vendors(), fv('preferred_vendor_id', $it), ['blank' => '', 'class' => 'col-md-6']) ?>
      </div></div></div>
      <?php endif; ?>
      <div class="col-lg-6"><div class="card h-100"><div class="card-header">Sales information</div><div class="card-body row g-2">
        <div class="col-12"><label class="form-label">Sales description</label><input class="form-control form-control-sm" name="description" value="<?= h(fv('description', $it)) ?>"></div>
        <?php if ($type === 'discount'): ?>
          <?= input_field('discount_pct', 'Discount % (0 = fixed amount)', fv('discount_pct', $it, '0'), ['type' => 'number', 'class' => 'col-md-4']) ?>
          <?= input_field('sales_price', 'Fixed discount amount', fv('sales_price', $it, '0'), ['type' => 'number', 'class' => 'col-md-4']) ?>
        <?php elseif (!in_array($type, ['group', 'payment'], true)): ?>
          <?= input_field('sales_price', 'Sales price', fv('sales_price', $it, '0'), ['type' => 'number', 'step' => '0.0001', 'class' => 'col-md-4']) ?>
        <?php endif; ?>
        <?php if (!in_array($type, ['group', 'payment'], true)): ?>
          <?= select_field('income_account_id', $type === 'discount' ? 'Discount account' : 'Income account', $allAccts, fv('income_account_id', $it, Accts::get($type === 'discount' ? 'sales_discounts' : 'sales_income')), ['class' => 'col-md-8']) ?>
          <?= select_field('tax_code_id', 'Tax code', Lookup::taxCodes(), fv('tax_code_id', $it), ['blank' => 'None', 'class' => 'col-md-4']) ?>
        <?php elseif ($type === 'group'): ?>
          <?= select_field('tax_code_id', 'Tax code of the price variance', Lookup::taxCodes(), fv('tax_code_id', $it), ['blank' => 'The one most items of the group have', 'class' => 'col-12 col-md-8']) ?>
        <?php endif; ?>
      </div></div></div>
    </div>
    <?php endif; ?>

    <?php if ($tracked): ?>
    <div class="card my-3"><div class="card-header">Inventory information</div><div class="card-body row g-2">
      <?= select_field('asset_account_id', 'Inventory asset account', $allAccts, fv('asset_account_id', $it, Accts::get('inventory_asset')), ['class' => 'col-md-4']) ?>
      <?= input_field('reorder_point', 'Reorder point (min)', fv('reorder_point', $it, '0'), ['type' => 'number', 'class' => 'col-md-2']) ?>
      <?= input_field('max_stock', 'Maximum stock', fv('max_stock', $it, '0'), ['type' => 'number', 'class' => 'col-md-2']) ?>
      <?php if ($type === 'assembly'): ?><?= input_field('build_point', 'Build point', fv('build_point', $it, '0'), ['type' => 'number', 'class' => 'col-md-2']) ?><?php endif; ?>
      <?php if ($it): ?>
        <div class="col-md-2"><div class="form-label">On hand</div><div class="fw-semibold"><?= qty(Stock::onHand((int)$it['id'])) ?></div></div>
        <?php if ($viewCosts): ?><div class="col-md-2"><div class="form-label">Average cost</div><div class="fw-semibold"><?= money($it['avg_cost']) ?></div></div><?php endif; ?>
      <?php elseif ($type === 'inventory'): ?>
        <div class="col-12 small text-muted mt-2">Opening balance (optional) — posted as an inventory adjustment against Opening Balance Equity.</div>
        <?= input_field('opening_qty', 'Quantity on hand', fv('opening_qty', null, '0'), ['type' => 'number', 'class' => 'col-md-2']) ?>
        <?= input_field('opening_value', 'Total value', fv('opening_value', null, '0'), ['type' => 'number', 'class' => 'col-md-2']) ?>
        <?= input_field('opening_date', 'As of', fv('opening_date', null, today()), ['type' => 'date', 'class' => 'col-md-2']) ?>
        <?= select_field('opening_site_id', 'Site', Lookup::sites(), fv('opening_site_id', null, array_key_first(Lookup::sites())), ['class' => 'col-md-2']) ?>
      <?php endif; ?>
    </div></div>
    <?php endif; ?>

    <?php if (in_array($type, ['assembly', 'group'], true)): ?>
    <div class="card my-3"><div class="card-header"><?= $type === 'assembly' ? 'Bill of materials (components, labor and overhead per unit)' : 'Items in this group' ?></div><div class="card-body">
      <?php $compPrices = $type === 'group' ? array_map('floatval', DB::pairs("SELECT id, sales_price FROM items WHERE is_active = 1 OR id IN (SELECT component_item_id FROM item_components WHERE parent_item_id = :p)", ['p' => $it['id'] ?? 0])) : []; ?>
      <div class="table-responsive"><table class="table table-sm comp-table" id="compTable"<?= $type === 'group' ? ' data-prices="' . h(json_encode($compPrices)) . '"' : '' ?>><thead><tr><th>Item</th><th class="num">Qty</th><?= $viewCosts && $type === 'assembly' ? '<th class="num">Avg / std cost</th><th class="num">Total</th>' : '' ?><?= $type === 'group' ? '<th class="num">Price</th><th class="num">Amount</th>' : '' ?><th></th></tr></thead><tbody>
      <?php $bomTotal = 0.0; $rowsC = $components ?: [['component_item_id' => '', 'qty' => '']]; foreach ($rowsC as $i => $c):
          $ci = !empty($c['component_item_id']) ? item_cache((int)$c['component_item_id']) : null;
          $unitCost = $ci ? (in_array($ci['item_type'], ['inventory', 'assembly'], true) ? (float)$ci['avg_cost'] : (float)$ci['cost']) : 0;
          $bomTotal += $unitCost * to_num($c['qty']);
      ?>
        <tr><td><select class="form-select form-select-sm" name="comp[<?= $i ?>][item_id]"><?= options($compItems, $c['component_item_id'], '') ?></select></td>
          <td><input class="form-control form-control-sm text-end" name="comp[<?= $i ?>][qty]" value="<?= h($c['qty'] !== '' ? qty($c['qty']) : '') ?>"></td>
          <?php if ($viewCosts && $type === 'assembly'): ?><td class="num"><?= money($unitCost) ?></td><td class="num"><?= money($unitCost * to_num($c['qty'])) ?></td><?php endif; ?>
          <?php if ($type === 'group'): $cp = (float)($ci['sales_price'] ?? 0); ?><td class="num" data-comp-price><?= money($cp) ?></td><td class="num" data-comp-amount><?= money($cp * to_num($c['qty'])) ?></td><?php endif; ?>
          <td><button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="this.closest('tr').remove();document.dispatchEvent(new Event('comp:changed'))" aria-label="Remove"><i class="bi bi-x-lg"></i></button></td></tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($viewCosts && $type === 'assembly'): ?><tfoot><tr class="fw-semibold"><td colspan="3" class="text-end">Total component cost</td><td class="num"><?= money($bomTotal) ?></td><td></td></tr></tfoot><?php endif; ?>
      <?php if ($type === 'group'): ?><tfoot><tr class="fw-semibold"><td colspan="3" class="text-end">Sum of the individual prices (consolidated price)</td><td class="num" id="compSum"><?= money($consolidated) ?></td><td></td></tr></tfoot><?php endif; ?>
      </table></div>
      <template id="compTpl"><tr><td><select class="form-select form-select-sm" name="comp[__i__][item_id]"><?= options($compItems, null, '') ?></select></td><td><input class="form-control form-control-sm text-end" name="comp[__i__][qty]" value="1"></td><?= $viewCosts && $type === 'assembly' ? '<td></td><td></td>' : '' ?><?= $type === 'group' ? '<td class="num" data-comp-price></td><td class="num" data-comp-amount></td>' : '' ?><td><button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="this.closest('tr').remove();document.dispatchEvent(new Event('comp:changed'))" aria-label="Remove"><i class="bi bi-x-lg"></i></button></td></tr></template>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="const t=document.getElementById('compTpl').innerHTML.replace(/__i__/g,'n'+Date.now());document.querySelector('#compTable tbody').insertAdjacentHTML('beforeend',t);document.dispatchEvent(new Event('comp:changed'))"><i class="bi bi-plus"></i> <?= $type === 'group' ? 'Add item' : 'Add component' ?></button>
    </div></div>
    <?php if ($type === 'group'): ?>
    <script>
    (function () {
      // the price and amount of each item of the group, their sum (the consolidated price) and the price variance follow what is typed
      const { num, fmt } = AppFmt;
      const table = document.getElementById('compTable');
      const prices = JSON.parse(table.dataset.prices || '{}');
      const priceIn = document.getElementById('f_sales_price');
      function recalc() {
        let sum = 0;
        table.querySelectorAll('tbody tr').forEach((tr) => {
          const sel = tr.querySelector('select'), q = tr.querySelector('input[name$="[qty]"]');
          const p = sel && sel.value ? num(prices[sel.value]) : 0;
          const amt = Math.round(p * num(q ? q.value : 0) * 100) / 100;
          sum += amt;
          const pc = tr.querySelector('[data-comp-price]'), ac = tr.querySelector('[data-comp-amount]');
          if (pc) pc.textContent = sel && sel.value ? fmt(p) : '';
          if (ac) ac.textContent = sel && sel.value ? fmt(amt) : '';
        });
        sum = Math.round(sum * 100) / 100;
        document.getElementById('compSum').textContent = fmt(sum);
        document.getElementById('gpConsolidated').textContent = fmt(sum);
        const v = Math.round((num(priceIn ? priceIn.value : 0) - sum) * 100) / 100;
        const vb = document.getElementById('gpVariance');
        vb.textContent = fmt(v);
        vb.classList.toggle('neg', v < 0);
      }
      table.addEventListener('change', recalc);
      table.addEventListener('input', recalc);
      if (priceIn) priceIn.addEventListener('input', recalc);
      document.addEventListener('comp:changed', recalc);
      recalc();
    })();
    </script>
    <?php if ($it && ItemGroups::ready()):
        // the groups sold, as groups (Sales by Item Group)
        $y0 = Report::fiscalYearStart(today());
        $sold = DB::row("SELECT COALESCE(SUM(CASE WHEN g.txn_type IN ('credit_memo','pos_return') THEN -g.qty ELSE g.qty END),0) AS q,
                                COALESCE(SUM(CASE WHEN g.txn_type IN ('credit_memo','pos_return') THEN -g.amount ELSE g.amount END),0) AS a,
                                COALESCE(SUM(CASE WHEN g.txn_date >= :m THEN CASE WHEN g.txn_type IN ('credit_memo','pos_return') THEN -g.qty ELSE g.qty END ELSE 0 END),0) AS qm
                           FROM item_group_sales g JOIN transactions t ON t.id = g.txn_id
                          WHERE g.group_item_id = :i AND t.is_voided = 0 AND t.is_pending = 0 AND t.status <> 'suspended' AND g.txn_date >= :y
                            AND g.txn_type IN ('invoice','sales_receipt','statement_charge','pos_sale','credit_memo','pos_return')",
            ['i' => $it['id'], 'y' => $y0, 'm' => date('Y-m-01')]);
    ?>
    <div class="card my-3"><div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2"><span>Sold as a group</span>
      <?= Access::page('rpt.sales_item_group') ? btn_link(url('rpt.sales_item_group', ['item_id' => $it['id'], 'dates' => 'this_year']), 'Sales by Item Group', 'btn-outline-secondary', 'bar-chart') : '' ?></div>
      <div class="card-body"><div class="group-sold">
        <div><div class="small text-muted">Groups sold this month</div><div class="fs-5 fw-semibold"><?= qty($sold['qm']) ?></div></div>
        <div><div class="small text-muted">Groups sold this fiscal year</div><div class="fs-5 fw-semibold"><?= qty($sold['q']) ?></div></div>
        <div><div class="small text-muted">Sales of the group this fiscal year</div><div class="fs-5 fw-semibold"><?= money($sold['a']) ?></div></div>
      </div>
      <?php if (!ItemGroups::track()): ?><div class="small text-muted mt-2">"Track Item Group Sales transactions" is switched off (System Administration &gt; Company, Security &amp; Closing Date), so new sales of groups are not counted here.</div><?php endif; ?>
      </div></div>
    <?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (in_array($type, ['inventory', 'assembly', 'non_inventory', 'service', 'other_charge'], true) && Lookup::priceLevels()):
        // every active price level gets a box: blank = the level's percentage, a number = a fixed price for this item
        $plRows = DB::all('SELECT pl.*, pli.custom_price FROM price_levels pl LEFT JOIN price_level_items pli ON pli.price_level_id = pl.id AND pli.item_id = :i WHERE pl.is_active = 1 ORDER BY pl.id', ['i' => $it['id'] ?? 0]);
    ?>
    <div class="card my-3"><div class="card-header d-flex justify-content-between align-items-center"><span>Price levels</span>
      <span class="small text-muted fw-normal"><?= count($plRows) ?> levels. Customers with a price level get these prices on invoices, sales receipts and at the POS.</span></div>
      <div class="card-body"><div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-xl-5 g-2" id="plGrid" data-base="<?= h((string)(float)fv('sales_price', $it, '0')) ?>">
      <?php foreach ($plRows as $pl): $posted = $_POST['pl'][$pl['id']] ?? null; ?>
        <div class="col"><label class="form-label d-flex justify-content-between"><span class="fw-semibold text-body"><?= h($pl['name']) ?></span>
            <span data-pl-pct="<?= h((string)(float)$pl['adjust_pct']) ?>"><?= $pl['adjust_pct'] >= 0 ? '+' : '' ?><?= h((float)$pl['adjust_pct']) ?>% = <?= money((float)fv('sales_price', $it, '0') * (1 + $pl['adjust_pct'] / 100)) ?></span></label>
          <input class="form-control form-control-sm text-end" name="pl[<?= (int)$pl['id'] ?>]" value="<?= h($posted !== null ? $posted : ($pl['custom_price'] !== null ? (float)$pl['custom_price'] : '')) ?>" placeholder="Custom price (optional)"></div>
      <?php endforeach; ?>
      </div>
      <div class="small text-muted mt-2">Leave a box empty to use the level's percentage on the sales price. Rename levels or change their percentages with <?= safe_link('inv.set_prices', [], 'Set Item Prices and Levels') ?> &rsaquo; Rename price levels, or under <?= safe_link('coa.lists', ['tab' => 'price_levels'], 'Terms, Tax Codes & Other Lists') ?>.</div>
      </div></div>
    <script>
    (function () {
      // the "= price" hint of each level follows the sales price as it is typed
      var grid = document.getElementById('plGrid'), price = document.querySelector('[name="sales_price"]');
      if (!grid || !price) return;
      price.addEventListener('input', function () {
        var base = AppFmt.num(price.value);
        grid.querySelectorAll('[data-pl-pct]').forEach(function (el) {
          var pct = AppFmt.num(el.dataset.plPct);
          el.textContent = (pct >= 0 ? '+' : '') + pct + '% = ' + AppFmt.fmt(AppFmt.r2(base * (1 + pct / 100)));
        });
      });
    })();
    </script>
    <?php endif; ?>
    <?php if ($canEdit): ?><div class="mt-2"><?= save_buttons('Save item', url($pageKey, ['type' => $type]), Access::can($pageKey, 'create')) ?></div><?php endif; ?>
    </fieldset>
    </form>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ list
$type = g('type');
$q = g('q');
$showInactive = g('inactive') === '1';
// a category ('none' = items without one), as the Category List links here
$catFilter = g('category_id');
$sql = 'SELECT i.*, c.name AS category, v.name AS vendor, (SELECT COALESCE(SUM(s.qty_on_hand),0) FROM item_site_stock s WHERE s.item_id = i.id) AS on_hand
          FROM items i LEFT JOIN item_categories c ON c.id = i.category_id LEFT JOIN vendors v ON v.id = i.preferred_vendor_id WHERE 1=1';
$params = [];
if ($type === 'tax') {
    $rows = [];
} else {
    if ($type) {
        $sql .= ' AND i.item_type = :t';
        $params['t'] = $type;
    }
    if (!$showInactive) {
        $sql .= ' AND i.is_active = 1';
    }
    if ($catFilter === 'none') {
        $sql .= ' AND i.category_id IS NULL';
    } elseif ((int)$catFilter > 0) {
        $sql .= ' AND i.category_id = :cat';
        $params['cat'] = (int)$catFilter;
    }
    // the search box narrows the list as you type (in the browser), so the page loads every item of the
    // tab; the words typed are only applied here for the export
    if ($q !== '' && g('export') === 'csv') {
        $sql .= ' AND (i.code LIKE :q OR i.name LIKE :q2 OR i.barcode = :b)';
        $params['q'] = "%$q%";
        $params['q2'] = "%$q%";
        $params['b'] = $q;
    }
    $rows = DB::all($sql . ' ORDER BY FIELD(i.item_type,\'service\',\'inventory\',\'non_inventory\',\'assembly\',\'other_charge\',\'subtotal\',\'group\',\'discount\',\'payment\'), i.code', $params);
}
if (g('export') === 'csv') {
    Access::require($pageKey, 'print');
    Audit::log('export', 'inv', 'list', null, 'Item list', ['page_key' => $pageKey]);
    csv_download('items.csv', array_merge(['Type', 'Code', 'Name', 'Description', 'Unit', 'Price', 'On hand'], $viewCosts ? ['Cost', 'Avg cost'] : []),
        array_map(fn($r) => array_merge([$typeLabels[$r['item_type']], $r['code'], $r['name'], $r['description'], $r['unit'], $r['sales_price'], $r['on_hand']], $viewCosts ? [$r['cost'], $r['avg_cost']] : []), $rows));
}

$newMenu = '';
if (Access::can($pageKey, 'create')) {
    $newMenu = '<div class="dropdown d-inline"><button class="btn btn-sm btn-primary dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-plus-lg"></i> New item</button><ul class="dropdown-menu">';
    foreach ($typeLabels as $k => $lbl) {
        $newMenu .= '<li><a class="dropdown-item" href="' . h(url($pageKey, ['a' => 'new', 'type' => $k])) . '">' . h($lbl) . '</a></li>';
    }
    $newMenu .= '</ul></div>';
}
layout_start('Item List', ['actions' => $newMenu . ' '
    . page_link('inv.categories', [], 'Category List', 'btn-outline-secondary', 'tags') . ' '
    . (Access::can('admin.import', 'create') ? btn_link(url('admin.import', ['type' => 'items']), 'Import from CSV', 'btn-outline-secondary', 'upload') . ' ' : '')
    . (Access::can($pageKey, 'print') ? btn_link(current_url(['export' => 'csv']), 'Export', 'btn-outline-secondary', 'download') : '')]);
?>
<ul class="nav nav-tabs mb-2 small">
  <li class="nav-item"><a class="nav-link <?= $type === '' ? 'active' : '' ?>" href="<?= h(url($pageKey)) ?>">All</a></li>
  <?php foreach ($typeLabels as $k => $lbl): ?><li class="nav-item"><a class="nav-link <?= $type === $k ? 'active' : '' ?>" href="<?= h(url($pageKey, ['type' => $k])) ?>"><?= h($lbl) ?></a></li><?php endforeach; ?>
  <li class="nav-item"><a class="nav-link <?= $type === 'tax' ? 'active' : '' ?>" href="<?= h(url($pageKey, ['type' => 'tax'])) ?>">Sales tax items</a></li>
</ul>
<?php if ($type === 'tax'): ?>
  <div class="card"><table class="table table-sm mb-0"><thead><tr><th>Code</th><th>Name</th><th class="num">Rate</th><th>Output tax account</th><th>Input tax account</th><th>Active</th></tr></thead>
  <?php foreach (DB::all('SELECT * FROM tax_codes ORDER BY id') as $tc): ?>
    <tr><td><?= h($tc['code']) ?></td><td><?= h($tc['name']) ?></td><td class="num"><?= h(pct($tc['rate'])) ?></td><td><?= h(account_label($tc['sales_account_id'] ? (int)$tc['sales_account_id'] : null)) ?></td><td><?= h(account_label($tc['purchase_account_id'] ? (int)$tc['purchase_account_id'] : null)) ?></td><td><?= (int)$tc['is_active'] ? 'Yes' : 'No' ?></td></tr>
  <?php endforeach; ?></table>
  <div class="card-body small"><?= safe_link('coa.lists', ['tab' => 'tax'], 'Manage tax codes') ?></div></div>
<?php else: ?>
<form class="item-list-filters filters mb-2" method="get" role="search"><input type="hidden" name="p" value="<?= h($pageKey) ?>"><input type="hidden" name="type" value="<?= h($type) ?>">
  <div class="live-search item-list-search"><i class="bi bi-search"></i>
    <input class="form-control form-control-sm" type="search" name="q" value="<?= h($q) ?>" placeholder="Type part of a code, name, barcode, category or supplier" autocomplete="off"
           aria-label="Search the items" data-live-filter="#itemList tbody tr.item-row" data-live-count="#itemCount" data-live-empty="#itemNone"></div>
  <select class="form-select form-select-sm item-list-cat" name="category_id" aria-label="Category" data-no-combo data-autosubmit><?= options(['' => 'All categories', 'none' => '(no category)'] + Lookup::categories(), $catFilter) ?></select>
  <label class="small d-flex align-items-center gap-1 item-list-inactive"><input class="form-check-input m-0" type="checkbox" name="inactive" value="1" <?= checked($showInactive) ?> data-autosubmit> Include inactive</label>
  <span class="small text-muted item-list-count"><span id="itemCount"><?= count($rows) ?></span> item(s)</span>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0" id="itemList">
  <thead><tr><th>Code</th><th>Name</th><th>Type</th><th>Category</th><th>Unit</th><th class="num">Price</th><?= $viewCosts ? '<th class="num">Cost</th><th class="num">Avg cost</th>' : '' ?><th class="num">On hand</th><th>Tax</th><th>Pref. vendor</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr class="item-row <?= (int)$r['is_active'] ? '' : 'text-muted' ?>" data-filter-text="<?= h($r['code'] . ' ' . $r['name'] . ' ' . $r['barcode'] . ' ' . $r['category'] . ' ' . $r['vendor'] . ' ' . ($typeLabels[$r['item_type']] ?? '') . ' ' . $r['description']) ?>">
      <td><a href="<?= h(url($pageKey, ['a' => 'edit', 'id' => $r['id']])) ?>"><?= h($r['code']) ?></a></td><td><?= h($r['name']) ?></td>
      <td class="small"><?= h($typeLabels[$r['item_type']]) ?></td><td class="small"><?= h($r['category']) ?></td><td><?= h($r['unit']) ?></td>
      <td class="num"><?= $r['item_type'] === 'discount' && (float)$r['discount_pct'] > 0 ? h(pct($r['discount_pct'])) : money($r['sales_price'], true) ?></td>
      <?php if ($viewCosts): ?><td class="num"><?= money($r['cost'], true) ?></td><td class="num"><?= in_array($r['item_type'], ['inventory', 'assembly'], true) ? money($r['avg_cost']) : '' ?></td><?php endif; ?>
      <td class="num <?= (float)$r['on_hand'] < 0 ? 'neg' : '' ?>"><?= in_array($r['item_type'], ['inventory', 'assembly'], true) ? qty($r['on_hand']) : '' ?></td>
      <td class="small"><?= h(Lookup::taxCodes()[$r['tax_code_id']] ?? '') ?></td><td class="small"><?= h($r['vendor']) ?></td></tr>
  <?php endforeach; ?>
  <tr id="itemNone"><td colspan="<?= $viewCosts ? 11 : 9 ?>" class="text-muted text-center py-3"><?= $rows ? 'No item matches what you typed.' : 'No item on this list yet.' ?></td></tr>
  </tbody>
</table></div></div>
<script>
(function () {
  // Export takes the words typed in the search box at the moment it is clicked
  var box = document.querySelector('.item-list-search input');
  document.querySelectorAll('.page-actions a[href*="export=csv"]').forEach(function (a) {
    a.addEventListener('click', function () {
      var u = new URL(a.href, window.location.href);
      if (box && box.value.trim() !== '') { u.searchParams.set('q', box.value.trim()); } else { u.searchParams.delete('q'); }
      a.href = u.toString();
    });
  });
})();
</script>
<?php endif; ?>
<?php
layout_end();
