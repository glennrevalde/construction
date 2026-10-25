<?php
declare(strict_types=1);

/**
 * Inventory > Category List
 *
 * The categories items are grouped by (Computers, Accessories, Frozen Products ...). They are chosen on the
 * item form, filter the Item List, Set Item Prices and Levels, the Item Price List and the inventory reports,
 * and group the POS Sales by Category report. Here they are added, renamed and deleted.
 *
 * A category that items still use is deleted only after saying where its items go: to another category (which
 * merges the two) or to no category. Nothing about the items themselves changes, only the category they show.
 */

$pageKey = 'inv.categories';
$canCreate = Access::can($pageKey, 'create');
$canEdit = Access::can($pageKey, 'edit');
$canDelete = Access::can($pageKey, 'delete');
$edit = gint('id') ? DB::row('SELECT * FROM item_categories WHERE id = :id', ['id' => gint('id')]) : null;
$deleting = gint('delete') ? DB::row('SELECT * FROM item_categories WHERE id = :id', ['id' => gint('delete')]) : null;

/** The name as it is saved: trimmed, single spaces, at most 60 characters */
$clean = fn($v): string => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$v) ?? ''), 0, 60);

handle_post(function () use ($pageKey, $edit, $clean) {
    $do = p('do');
    if ($do === 'delete') {
        Access::require($pageKey, 'delete');
        $cat = DB::row('SELECT * FROM item_categories WHERE id = :id', ['id' => pint('id')]);
        if (!$cat) {
            fail('That category no longer exists.');
        }
        $used = (int)DB::value('SELECT COUNT(*) FROM items WHERE category_id = :c', ['c' => $cat['id']]);
        $moveTo = (string)p('move_to');
        $target = null;
        if ($used > 0) {
            if ($moveTo === '') {
                fail(sprintf('%d item(s) are in "%s". Choose where they go before deleting it.', $used, $cat['name']));
            }
            if ($moveTo !== 'none') {
                $target = DB::row('SELECT * FROM item_categories WHERE id = :id', ['id' => (int)$moveTo]);
                if (!$target || (int)$target['id'] === (int)$cat['id']) {
                    fail('Choose another category for the items, or "No category".');
                }
            }
        }
        DB::tx(function () use ($cat, $target, $used) {
            if ($used > 0) {
                DB::run('UPDATE items SET category_id = :t WHERE category_id = :c', ['t' => $target ? (int)$target['id'] : null, 'c' => $cat['id']]);
            }
            DB::run('DELETE FROM item_categories WHERE id = :id', ['id' => $cat['id']]);
        });
        Audit::log('delete', 'inv', 'item_category', (int)$cat['id'], $cat['name'], [
            'page_key' => $pageKey, 'snapshot' => $cat,
            'reason' => $used ? $used . ' item(s) moved to ' . ($target ? '"' . $target['name'] . '"' : 'no category') : null,
        ]);
        flash('success', 'Category "' . $cat['name'] . '" deleted.'
            . ($used ? ' Its ' . $used . ' item(s) are now ' . ($target ? 'in "' . $target['name'] . '".' : 'without a category.') : ''));
        redirect(url($pageKey));
    }

    // add or rename
    Access::require($pageKey, $edit ? 'edit' : 'create');
    $name = $clean(p('name'));
    if ($name === '') {
        fail('Enter the name of the category.');
    }
    $twin = DB::value('SELECT name FROM item_categories WHERE LOWER(name) = LOWER(:n) AND id <> :id', ['n' => $name, 'id' => $edit['id'] ?? 0]);
    if ($twin) {
        fail('There is already a category called "' . $twin . '". To put the two together, delete one and move its items to the other.');
    }
    if ($edit) {
        if ($name === $edit['name']) {
            flash('info', 'Nothing was changed.');
            redirect(url($pageKey));
        }
        DB::update('item_categories', ['name' => $name], 'id = :id', ['id' => $edit['id']]);
        $id = (int)$edit['id'];
        Audit::log('edit', 'inv', 'item_category', $id, $name, ['page_key' => $pageKey,
            'changes' => [['field' => 'Name', 'old' => $edit['name'], 'new' => $name]]]);
        flash('success', 'Category renamed "' . $name . '". Every item in it shows the new name.');
    } else {
        $id = DB::insert('item_categories', ['name' => $name]);
        Audit::log('create', 'inv', 'item_category', $id, $name, ['page_key' => $pageKey, 'snapshot' => ['name' => $name]]);
        flash('success', 'Category "' . $name . '" added.');
    }
    redirect_after_save(url($pageKey), url($pageKey));
});

$viewCosts = Access::viewCosts();
$cats = DB::all(
    "SELECT c.id, c.name, COUNT(i.id) AS items, COALESCE(SUM(i.is_active = 1), 0) AS active,
            COALESCE(SUM(CASE WHEN i.item_type IN ('inventory','assembly') THEN st.q ELSE 0 END), 0) AS on_hand,
            COALESCE(SUM(CASE WHEN i.item_type IN ('inventory','assembly') THEN st.q * i.avg_cost ELSE 0 END), 0) AS value
       FROM item_categories c
       LEFT JOIN items i ON i.category_id = c.id
       LEFT JOIN (SELECT item_id, SUM(qty_on_hand) AS q FROM item_site_stock GROUP BY item_id) st ON st.item_id = i.id
      GROUP BY c.id, c.name ORDER BY c.name"
);
$noCat = DB::row("SELECT COUNT(*) AS items, COALESCE(SUM(is_active = 1), 0) AS active FROM items WHERE category_id IS NULL");
$totalItems = (int)DB::value('SELECT COUNT(*) FROM items');
$deletingUsed = $deleting ? (int)DB::value('SELECT COUNT(*) FROM items WHERE category_id = :c', ['c' => $deleting['id']]) : 0;
$others = $deleting ? array_filter(Lookup::categories(), fn($k) => (int)$k !== (int)$deleting['id'], ARRAY_FILTER_USE_KEY) : [];

layout_start('Category List', ['actions' => page_link('inv.items', [], 'Item List', 'btn-outline-secondary', 'list-ul')
    . ' ' . page_link('rpt.item_price_list', [], 'Item Price List', 'btn-outline-secondary', 'tags')]);
?>
<div class="row g-3">
  <div class="col-lg-7 col-xl-8">
    <div class="card">
      <div class="card-body pb-2">
        <div class="live-search"><i class="bi bi-search"></i>
          <input class="form-control form-control-sm" type="search" placeholder="Type to find a category" autocomplete="off" aria-label="Find a category"
                 data-live-filter="#catList tbody tr" data-live-count="#catCount" data-live-empty="#catNone" data-live-enter-submits></div>
        <div class="live-count mt-1"><span id="catCount"><?= count($cats) ?></span> categor<?= count($cats) === 1 ? 'y' : 'ies' ?>, <?= $totalItems ?> item(s) in all</div>
      </div>
      <div class="table-responsive"><table class="table table-sm table-hover mb-0 cat-list" id="catList">
        <thead><tr><th>Category</th><th class="num">Items</th><th class="num">Active</th><th class="num">On hand</th><?= $viewCosts ? '<th class="num">Value</th>' : '' ?><th class="text-end"></th></tr></thead>
        <tbody>
        <?php foreach ($cats as $c): $isEdit = $edit && (int)$edit['id'] === (int)$c['id']; ?>
          <tr class="<?= $isEdit ? 'table-active' : '' ?>" data-filter-text="<?= h($c['name']) ?>">
            <td class="fw-semibold"><?= h($c['name']) ?></td>
            <td class="num"><?= (int)$c['items'] ? safe_link('inv.items', ['category_id' => $c['id'], 'inactive' => '1'], (string)(int)$c['items']) : '0' ?></td>
            <td class="num"><?= (int)$c['active'] ?></td>
            <td class="num"><?= qty($c['on_hand']) ?></td>
            <?= $viewCosts ? '<td class="num">' . money($c['value']) . '</td>' : '' ?>
            <td class="text-end text-nowrap">
              <?php if ($canEdit): ?><a class="btn btn-sm btn-link p-0 me-2" href="<?= h(url($pageKey, ['id' => $c['id']])) ?>"><i class="bi bi-pencil"></i> Rename</a><?php endif; ?>
              <?php if ($canDelete): ?><a class="btn btn-sm btn-link p-0 text-danger" href="<?= h(url($pageKey, ['delete' => $c['id']])) ?>"><i class="bi bi-trash"></i> Delete</a><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$cats): ?><tr><td colspan="6" class="text-center text-muted py-4">No categories yet. Add the first one on the right.</td></tr><?php endif; ?>
        </tbody>
        <?php if ((int)$noCat['items']): ?>
        <tfoot><tr class="text-muted"><td>(no category)</td><td class="num"><?= safe_link('inv.items', ['category_id' => 'none', 'inactive' => '1'], (string)(int)$noCat['items']) ?></td><td class="num"><?= (int)$noCat['active'] ?></td><td colspan="<?= $viewCosts ? 3 : 2 ?>"></td></tr></tfoot>
        <?php endif; ?>
      </table>
      <div class="p-3 small text-muted" id="catNone">No category matches.</div></div>
    </div>
  </div>

  <div class="col-lg-5 col-xl-4">
    <?php if ($deleting && $canDelete): ?>
      <form method="post" class="card border-danger-subtle mb-3"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$deleting['id'] ?>">
        <div class="card-header fw-semibold text-danger"><i class="bi bi-trash"></i> Delete "<?= h($deleting['name']) ?>"</div>
        <div class="card-body">
          <?php if ($deletingUsed): ?>
            <p class="small mb-2"><?= $deletingUsed ?> item(s) are in this category. Choose where they go: to another category (the two become one) or to no category. The items themselves are not changed.</p>
            <label class="form-label">Move its items to</label>
            <select class="form-select form-select-sm" name="move_to" required data-no-combo>
              <option value="">Choose...</option>
              <?= options($others) ?>
              <option value="none">No category</option>
            </select>
          <?php else: ?>
            <p class="small mb-0">No item is in this category, so nothing else changes.</p>
          <?php endif; ?>
          <div class="d-flex flex-wrap gap-2 mt-3">
            <button class="btn btn-sm btn-danger" data-confirm="Delete the category &quot;<?= h($deleting['name']) ?>&quot;?"><i class="bi bi-trash"></i> Delete the category</button>
            <a class="btn btn-sm btn-outline-secondary" href="<?= h(url($pageKey)) ?>">Keep it</a>
          </div>
        </div>
      </form>
    <?php endif; ?>

    <?php if ($edit ? $canEdit : $canCreate): ?>
      <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="do" value="save">
        <div class="card-header fw-semibold"><i class="bi bi-<?= $edit ? 'pencil' : 'plus-lg' ?>"></i> <?= $edit ? 'Rename "' . h($edit['name']) . '"' : 'New category' ?></div>
        <div class="card-body row g-2">
          <?= input_field('name', 'Category name', fv('name', $edit), ['class' => 'col-12', 'required' => true, 'extra' => 'maxlength="60" autofocus placeholder="e.g. Frozen Products"']) ?>
          <div class="col-12 small text-muted">Renaming changes the name every item in the category shows, on every page and report. Two categories cannot have the same name.</div>
          <div class="col-12"><?= save_buttons($edit ? 'Save the name' : 'Add category', $edit ? url($pageKey) : null, !$edit && $canCreate) ?></div>
        </div>
      </form>
    <?php endif; ?>
    <p class="small text-muted mt-3 mb-0">An item's category is chosen on the item form. Categories filter the Item List, Set Item Prices and Levels, the Item Price List and the inventory reports, and group POS Sales by Category.</p>
  </div>
</div>
<?php
layout_end();
