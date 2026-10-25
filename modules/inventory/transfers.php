<?php
declare(strict_types=1);

require_once APP_ROOT . '/modules/txn/_common.php';

/**
 * Transfer Inventory between sites, and Edit Inventory Transfer (a=edit): the date, the two sites, the memo and the
 * lines of a posted transfer can be changed. Saving posts it again (stock leaves and arrives at the new sites and
 * quantities, the value moves at average cost) and the before and after, with the reason, go to the audit trail.
 */

$pageKey = 'inv.transfers';
if (txn_common_actions($pageKey, ['inventory_transfer'])) {
    return;
}
$a = g('a', 'list');
if ($a === 'list') {
    render_txn_list($pageKey, ['inventory_transfer'], 'Inventory Transfers', ['statuses' => ['open' => 'Posted', 'void' => 'Void'], 'new_label' => 'New transfer', 'site_cols' => true]);
    return;
}
$edit = null;
if ($a === 'edit') {
    Access::require($pageKey, 'edit');
    $edit = Txn::load(gint('id') ?? 0);
    if (!$edit || $edit['txn_type'] !== 'inventory_transfer') {
        flash('warning', 'Transfer not found.');
        redirect(url($pageKey));
    }
    if ((int)$edit['is_voided'] === 1) {
        flash('warning', 'A voided transfer cannot be edited.');
        redirect(Txn::url($edit));
    }
    if (!Access::siteAllowed($edit['site_id'] ? (int)$edit['site_id'] : null)) {
        Access::denyPage($pageKey, 'edit a transfer of another site on');
    }
} else {
    Access::require($pageKey, 'create');
}
$items = Lookup::itemPairs("is_active = 1 AND item_type IN ('inventory','assembly')");
// an item already on the transfer stays on the list even if it was made inactive since
$editLines = $edit ? array_values(array_filter(Txn::lines((int)$edit['id']), fn($l) => !empty($l['item_id']))) : [];
foreach ($editLines as $l) {
    if (!isset($items[(int)$l['item_id']]) && ($it = item_cache((int)$l['item_id']))) {
        $items[(int)$it['id']] = $it['code'] . ' ' . $it['name'];
    }
}
$sites = Lookup::sites();

/** What this transfer itself took out of a site, by item (added back to what is available when it is edited) */
$ownOut = function (?array $edit, int $siteId): array {
    $out = [];
    if ($edit && (int)$edit['site_id'] === $siteId) {
        foreach (Txn::lines((int)$edit['id']) as $l) {
            if ($l['item_id']) {
                $out[(int)$l['item_id']] = ($out[(int)$l['item_id']] ?? 0.0) + (float)$l['qty'];
            }
        }
    }
    return $out;
};

handle_post(function () use ($pageKey, $edit, $ownOut) {
    $from = pint('site_id');
    $to = pint('site2_id');
    if (!$from || !$to || $from === $to) {
        fail('Choose two different sites.');
    }
    if (!Access::siteAllowed($to)) {
        fail('You are not allowed to move stock to that site.');
    }
    $lines = [];
    $own = $ownOut($edit, $from);
    $want = [];
    foreach ((array)($_POST['rows'] ?? []) as $r) {
        $iid = (int)($r['item_id'] ?? 0);
        $q = to_num($r['qty'] ?? 0);
        if (!$iid || $q <= 0) {
            continue;
        }
        $want[$iid] = ($want[$iid] ?? 0.0) + $q;
        $q = $want[$iid];
        $avail = Stock::onHand($iid, $from) + ($own[$iid] ?? 0.0);
        if ($q > $avail + 0.00001 && Settings::get('allow_negative_stock') !== '1') {
            fail('Not enough stock of ' . item_cache($iid)['code'] . ' at the source site (' . qty($avail) . ').');
        }
        $lines[] = ['id' => (int)($r['id'] ?? 0) ?: null, 'line_kind' => 'item', 'item_id' => $iid, 'qty' => to_num($r['qty'] ?? 0), 'description' => item_cache($iid)['name'], 'site_id' => $from];
    }
    $lines = array_map(function ($l) {
        if (!$l['id']) {
            unset($l['id']);
        }
        return $l;
    }, $lines);
    if (!$lines) {
        fail('Enter at least one item and quantity.');
    }
    $h = ['txn_date' => pdate('txn_date', today()), 'site_id' => $from, 'site2_id' => $to, 'memo' => p('memo') ?: null, 'status' => 'open'];
    if ($edit) {
        $id = Txn::save('inventory_transfer', $h, $lines, (int)$edit['id'], ['reason' => trim((string)p('reason')) ?: null]);
        flash('success', Txn::ref(Txn::load($id)) . ' updated. Stock at both sites follows the new quantities.');
        redirect(url($pageKey, ['a' => 'view', 'id' => $id]));
    }
    $id = Txn::save('inventory_transfer', $h, $lines);
    flash('success', 'Transfer recorded. Quantities moved without changing total inventory value.');
    redirect_after_save(url($pageKey, ['a' => 'new']), url($pageKey, ['a' => 'view', 'id' => $id]));
});

if (is_post()) {
    $rows = array_values((array)($_POST['rows'] ?? []));
} elseif ($edit) {
    $rows = array_map(fn($l) => ['id' => $l['id'], 'item_id' => $l['item_id'], 'qty' => rtrim(rtrim(number_format((float)$l['qty'], 4, '.', ''), '0'), '.')], $editLines);
} else {
    $rows = gint('item_id') ? [['item_id' => gint('item_id'), 'qty' => '']] : [];
}
$rows = $rows ?: [['item_id' => '', 'qty' => ''], ['item_id' => '', 'qty' => ''], ['item_id' => '', 'qty' => '']];
$ownNow = $ownOut($edit, (int)($edit['site_id'] ?? 0));
layout_start($edit ? 'Edit Inventory Transfer ' . $edit['txn_no'] : 'Transfer Inventory', ['actions' => $edit
    ? btn_link(Txn::url($edit), 'Cancel', 'btn-outline-secondary')
    : btn_link(url($pageKey), 'Transfers list')]);
?>
<?php if ($edit): ?>
<p class="text-muted small">Saving moves the stock again: the quantities this transfer moved go back to <?= h($sites[(int)$edit['site_id']] ?? 'the source site') ?>, then the new lines leave the source site and arrive at the destination. "On hand at source" counts what this transfer took from the site it moved from.</p>
<?php endif; ?>
<form method="post"><?= csrf_field() ?><?= negative_stock_fields() ?>
<div class="card mb-3"><div class="card-body row g-2">
  <?= input_field('txn_date', 'Date', fv('txn_date', $edit, today()), ['type' => 'date', 'class' => 'col-md-2']) ?>
  <?= select_field('site_id', 'From site', $sites, fv('site_id', $edit, array_key_first($sites)), ['class' => 'col-md-3', 'extra' => 'id="fromSite"']) ?>
  <?= select_field('site2_id', 'To site', Lookup::sites(false), fv('site2_id', $edit), ['class' => 'col-md-3', 'blank' => '— choose —']) ?>
  <?= input_field('memo', 'Memo', fv('memo', $edit), ['class' => Classes::ready() ? 'col-md-2' : 'col-md-4']) ?>
  <?= Classes::field(fv('class_id', $edit), 'col-md-2') ?>
  <?php if ($edit): ?><?= input_field('reason', 'Reason for the change', fv('reason'), ['class' => 'col-12', 'extra' => 'maxlength="255" placeholder="Recorded in the audit trail"']) ?><?php endif; ?>
</div></div>
<div class="card card-body mb-3">
  <table class="table table-sm" id="trTable"><thead><tr><th>Item</th><th class="num">On hand at source</th><th class="num">Qty to move</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $i => $r): ?>
    <tr><td><?php if (!empty($r['id'])): ?><input type="hidden" name="rows[<?= $i ?>][id]" value="<?= (int)$r['id'] ?>"><?php endif; ?><select class="form-select form-select-sm tr-item" name="rows[<?= $i ?>][item_id]" aria-label="Item"><?= options($items, $r['item_id'], '') ?></select></td>
      <td class="num tr-avail"></td>
      <td><input class="form-control form-control-sm text-end" name="rows[<?= $i ?>][qty]" value="<?= h($r['qty']) ?>" inputmode="decimal" placeholder="Qty to move" aria-label="Qty to move"></td>
      <td class="grid-acts"><button type="button" class="btn btn-sm btn-link p-0 grid-ins tr-ins" title="Insert a line above this one" aria-label="Insert a line above"><i class="bi bi-plus-square"></i></button><button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="this.closest('tr').remove()" aria-label="Remove the line"><i class="bi bi-x-lg"></i></button></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <template id="trTpl"><tr><td><select class="form-select form-select-sm tr-item" name="rows[__i__][item_id]" aria-label="Item"><?= options($items, null, '') ?></select></td><td class="num tr-avail"></td><td><input class="form-control form-control-sm text-end" name="rows[__i__][qty]" inputmode="decimal" placeholder="Qty to move" aria-label="Qty to move"></td><td class="grid-acts"><button type="button" class="btn btn-sm btn-link p-0 grid-ins tr-ins" title="Insert a line above this one" aria-label="Insert a line above"><i class="bi bi-plus-square"></i></button><button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="this.closest('tr').remove()" aria-label="Remove the line"><i class="bi bi-x-lg"></i></button></td></tr></template>
  <div class="grid-add"><?php foreach ([1 => 'Add line', 5 => 'Add 5 lines', 10 => 'Add 10 lines'] as $n => $lbl): ?><button type="button" class="btn btn-sm btn-outline-secondary tr-add" data-count="<?= $n ?>"><i class="bi bi-plus"></i> <?= h($lbl) ?></button><?php endforeach; ?></div>
</div>
<?php if ($edit): ?>
  <div class="d-flex flex-wrap gap-2"><button class="btn btn-primary"><i class="bi bi-check2"></i> Save changes</button><?= btn_link(Txn::url($edit), 'Cancel', 'btn-outline-secondary') ?></div>
<?php else: ?>
<?= save_buttons('Record transfer', url($pageKey), Access::can($pageKey, 'create')) ?>
<?php endif; ?>
</form>
<script>
(function () {
  const lookup = <?= json_encode(url('api.lookup', ['what' => 'stock'])) ?>;
  // on an edit, what the transfer itself took from its source site is counted as available there
  const own = <?= json_encode((object)$ownNow) ?>, ownSite = <?= json_encode((string)($edit['site_id'] ?? '')) ?>;
  const refresh = (tr) => {
    const sel = tr.querySelector('.tr-item'); const out = tr.querySelector('.tr-avail');
    if (!sel.value) { out.textContent = ''; return; }
    const site = document.getElementById('fromSite').value;
    fetch(lookup + '&item=' + sel.value + '&site=' + site).then(r => r.json()).then(d => {
      const back = site === ownSite ? (+own[sel.value] || 0) : 0;
      out.textContent = back ? String(Math.round((parseFloat(String(d.on_hand).replace(/,/g, '')) + back) * 10000) / 10000) : d.on_hand;
    });
  };
  const tbody = document.querySelector('#trTable tbody');
  tbody.addEventListener('change', e => { if (e.target.classList.contains('tr-item')) refresh(e.target.closest('tr')); });
  document.getElementById('fromSite').addEventListener('change', () => tbody.querySelectorAll('tr').forEach(refresh));
  let seq = 0;
  const newRow = (before) => {
    const tmp = document.createElement('tbody');
    tmp.innerHTML = document.getElementById('trTpl').innerHTML.replace(/__i__/g, 'n' + Date.now() + '_' + (seq++)).trim();
    const tr = tmp.firstElementChild;
    if (before) tbody.insertBefore(tr, before); else tbody.appendChild(tr);
    if (window.AppCombo) window.AppCombo.init(tr);
    return tr;
  };
  document.querySelectorAll('.tr-add').forEach((b) => b.addEventListener('click', () => { for (let i = 0; i < (+b.dataset.count || 1); i++) newRow(); }));
  tbody.addEventListener('click', (e) => { const b = e.target.closest('.tr-ins'); if (b) newRow(b.closest('tr')); });
  tbody.querySelectorAll('tr').forEach(refresh);
})();
</script>
<?php
layout_end();
