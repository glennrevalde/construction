<?php
declare(strict_types=1);

require_once APP_ROOT . '/modules/txn/_common.php';

/**
 * Build Assemblies, and Edit Build Assembly (a=edit): the assembly, site, quantity, date and memo of a build can be
 * changed. The components follow the quantity (the quantities per unit recorded on the build when the assembly stays
 * the same, the bill of materials when another assembly is chosen). A completed build is posted again (components out,
 * assembly in at their cost); a pending one stays pending unless it is completed on the same save.
 */

$pageKey = 'inv.build';
$a = g('a', 'list');

if ($a === 'finalize' && is_post()) {
    Access::require($pageKey, 'edit');
    $t = Txn::load((int)g('id'));
    if ($t && $t['txn_type'] === 'build_assembly' && (int)$t['is_pending'] === 1 && (int)$t['is_voided'] === 0) {
        try {
            $short = [];
            foreach (Txn::lines((int)$t['id']) as $l) {
                if ($l['line_kind'] === 'component' && Stock::isTracked($l['item_type'])) {
                    $have = Stock::onHand((int)$l['item_id'], (int)$t['site_id']);
                    if ($have + 0.00001 < (float)$l['qty']) {
                        $short[] = $l['item_code'] . ' (need ' . qty($l['qty']) . ', have ' . qty($have) . ')';
                    }
                }
            }
            if ($short) {
                fail('Still short of components: ' . implode(', ', $short) . '.');
            }
            $lines = array_map(fn($l) => array_intersect_key($l, array_flip(['id', 'line_kind', 'item_id', 'description', 'qty', 'site_id'])), Txn::lines((int)$t['id']));
            Txn::save('build_assembly', ['txn_date' => pdate('txn_date', today()), 'is_pending' => 0, 'status' => 'completed'], $lines, (int)$t['id'], ['audit_action' => 'mark_final']);
            flash('success', 'Build completed and posted.');
        } catch (UserError $e) {
            flash('danger', $e->getMessage());
        }
        redirect(url($pageKey, ['a' => 'view', 'id' => $t['id']]));
    }
    redirect(url($pageKey));
}

if ($a === 'view') {
    $t = Txn::load((int)g('id'));
    if ($t && (int)$t['is_pending'] === 1 && (int)$t['is_voided'] === 0 && Access::can($pageKey, 'edit')) {
        $GLOBALS['TXN_VIEW_EXTRA'] = '<form method="post" class="card card-body border-warning mb-4" style="max-width:520px" action="' . h(url($pageKey, ['a' => 'finalize', 'id' => $t['id']])) . '">'
            . csrf_field() . '<p class="small mb-2">This build is pending. When the missing components arrive, complete it here.</p>'
            . '<div class="row g-2 align-items-end"><div class="col"><label class="form-label">Build date</label><input class="form-control form-control-sm" type="date" name="txn_date" value="' . h(today()) . '"></div>'
            . '<div class="col"><button class="btn btn-warning btn-sm">Complete build</button></div></div></form>';
    }
}
if (txn_common_actions($pageKey, ['build_assembly'])) {
    return;
}
if ($a === 'list') {
    render_txn_list($pageKey, ['build_assembly'], 'Assembly Builds', ['statuses' => ['completed' => 'Completed', 'pending' => 'Pending', 'void' => 'Void'], 'new_label' => 'Build assemblies']);
    return;
}

$edit = null;
$editLines = [];
if ($a === 'edit') {
    Access::require($pageKey, 'edit');
    $edit = Txn::load(gint('id') ?? 0);
    if (!$edit || $edit['txn_type'] !== 'build_assembly') {
        flash('warning', 'Build not found.');
        redirect(url($pageKey));
    }
    if ((int)$edit['is_voided'] === 1) {
        flash('warning', 'A voided build cannot be edited.');
        redirect(Txn::url($edit));
    }
    if (!Access::siteAllowed($edit['site_id'] ? (int)$edit['site_id'] : null)) {
        Access::denyPage($pageKey, 'edit a build of another site on');
    }
    $editLines = Txn::lines((int)$edit['id']);
} else {
    Access::require($pageKey, 'create');
}
$editHead = null;
foreach ($editLines as $l) {
    if ($l['line_kind'] === 'item') {
        $editHead = $l;
        break;
    }
}
$assemblies = Lookup::itemPairs("is_active = 1 AND item_type = 'assembly'");
if ($editHead && !isset($assemblies[(int)$editHead['item_id']]) && ($it = item_cache((int)$editHead['item_id']))) {
    $assemblies[(int)$it['id']] = $it['code'] . ' ' . $it['name'];
}
$itemId = (int)(p('item_id') ?: g('item_id') ?: ($editHead['item_id'] ?? array_key_first($assemblies)));
$sites = Lookup::sites();
$siteId = (int)(p('site_id') ?: g('site_id') ?: ($edit['site_id'] ?? array_key_first($sites)));
$qtyBuild = to_num(p('qty') ?: g('qty', $editHead ? (string)(float)$editHead['qty'] : '1'));
$recorded = $editHead && (int)$editHead['item_id'] === $itemId && (float)$editHead['qty'] > 0;
if ($recorded) {
    // the same assembly: its components as they were recorded on this build, per unit built
    $bom = [];
    foreach ($editLines as $l) {
        if ($l['line_kind'] === 'component' && ($it = item_cache((int)$l['item_id']))) {
            $bom[] = ['per_unit' => (float)$l['qty'] / (float)$editHead['qty'], 'line_id' => (int)$l['id']] + $it;
        }
    }
} else {
    $bom = $itemId ? DB::all('SELECT ic.qty AS per_unit, i.* FROM item_components ic JOIN items i ON i.id = ic.component_item_id WHERE ic.parent_item_id = :p', ['p' => $itemId]) : [];
}
// a completed build that is edited gives back what it used at its own site before the new figures are checked
$usedBefore = [];
if ($edit && (int)$edit['is_pending'] === 0 && (int)$edit['site_id'] === $siteId) {
    foreach ($editLines as $l) {
        if ($l['line_kind'] === 'component' && $l['item_id']) {
            $usedBefore[(int)$l['item_id']] = ($usedBefore[(int)$l['item_id']] ?? 0.0) + (float)$l['qty'];
        }
    }
}
$short = false;
foreach ($bom as &$c) {
    $c['need'] = (float)$c['per_unit'] * $qtyBuild;
    $c['have'] = Stock::isTracked($c['item_type']) ? Stock::onHand((int)$c['id'], $siteId) + ($usedBefore[(int)$c['id']] ?? 0.0) : null;
    $c['short'] = $c['have'] !== null && $c['have'] + 0.00001 < $c['need'];
    $short = $short || $c['short'];
}
unset($c);

handle_post(function () use ($pageKey, $itemId, $siteId, $qtyBuild, $bom, $short, $edit, $editLines) {
    if (p('do') === 'refresh') {
        return;
    }
    if ($edit) {
        if (!$itemId || $qtyBuild <= 0) {
            fail('Choose the assembly and a quantity greater than zero.');
        }
        if (!$bom) {
            fail('This assembly has no bill of materials.');
        }
        $pending = (int)$edit['is_pending'] === 1 && p('do') !== 'complete';
        if ($short && !$pending) {
            fail('There are not enough components at this site for the new quantity. Lower the quantity, or transfer / receive the components first.');
        }
        // keep the line ids: the assembly line, and each component line of the same item
        $ids = ['item' => null, 'comp' => []];
        foreach ($editLines as $l) {
            if ($l['line_kind'] === 'item' && !$ids['item']) {
                $ids['item'] = (int)$l['id'];
            } elseif ($l['line_kind'] === 'component') {
                $ids['comp'][(int)$l['item_id']] = (int)$l['id'];
            }
        }
        $it = item_cache($itemId);
        $lines = [['id' => $ids['item'], 'line_kind' => 'item', 'item_id' => $itemId, 'qty' => $qtyBuild, 'description' => $it['name'], 'site_id' => $siteId]];
        foreach ($bom as $c) {
            $lines[] = ['id' => $ids['comp'][(int)$c['id']] ?? null, 'line_kind' => 'component', 'item_id' => (int)$c['id'], 'qty' => $c['need'], 'description' => $c['name'], 'site_id' => $siteId];
        }
        $lines = array_map(function ($l) {
            if (!$l['id']) {
                unset($l['id']);
            }
            return $l;
        }, $lines);
        $completing = (int)$edit['is_pending'] === 1 && !$pending;
        $id = Txn::save('build_assembly', [
            'txn_date' => pdate('txn_date', today()), 'site_id' => $siteId, 'memo' => p('memo') ?: null,
            'is_pending' => $pending ? 1 : 0, 'status' => $pending ? 'pending' : 'completed',
        ], $lines, (int)$edit['id'], ['reason' => trim((string)p('reason')) ?: null] + ($completing ? ['audit_action' => 'mark_final'] : []));
        flash('success', Txn::ref(Txn::load($id)) . ($pending ? ' updated. It is still pending and does not move stock until it is completed.' : ($completing ? ' updated and completed.' : ' updated. Components and the assembly were posted again at the new quantity.')));
        redirect(url($pageKey, ['a' => 'view', 'id' => $id]));
    }
    if (!$itemId || $qtyBuild <= 0) {
        fail('Choose the assembly and a quantity greater than zero.');
    }
    if (!$bom) {
        fail('This assembly has no bill of materials.');
    }
    $pending = p('do') === 'pending';
    if ($short && !$pending) {
        fail('There are not enough components at this site. Save the build as pending, or transfer / receive the components first.');
    }
    $it = item_cache($itemId);
    $lines = [['line_kind' => 'item', 'item_id' => $itemId, 'qty' => $qtyBuild, 'description' => $it['name'], 'site_id' => $siteId]];
    foreach ($bom as $c) {
        $lines[] = ['line_kind' => 'component', 'item_id' => (int)$c['id'], 'qty' => $c['need'], 'description' => $c['name'], 'site_id' => $siteId];
    }
    $id = Txn::save('build_assembly', [
        'txn_date' => pdate('txn_date', today()), 'site_id' => $siteId, 'memo' => p('memo') ?: null,
        'is_pending' => $pending ? 1 : 0, 'status' => $pending ? 'pending' : 'completed',
    ], $lines);
    flash('success', $pending ? 'Build saved as pending. It will not affect inventory until completed.' : 'Assembly built.');
    redirect_after_save(url($pageKey), url($pageKey, ['a' => 'view', 'id' => $id]));
});

$onHandAsm = $itemId ? Stock::onHand($itemId, $siteId) : 0;
$asm = $itemId ? item_cache($itemId) : null;
layout_start($edit ? 'Edit Build Assembly ' . $edit['txn_no'] : 'Build Assemblies', ['actions' => $edit ? btn_link(Txn::url($edit), 'Cancel', 'btn-outline-secondary') : btn_link(url($pageKey), 'Builds list') . ' ' . page_link('rpt.pending_builds', [], 'Pending Builds', 'btn-outline-secondary', 'bar-chart')
    . ' ' . page_link('inv.process', ['a' => 'new', 'item_id' => $itemId], 'Disassemble (unbuild)', 'btn-outline-secondary', 'diagram-3', 'create')]);
if (!$assemblies) {
    echo '<p class="text-muted">No assembly items yet. Create one in the Item List.</p>';
    layout_end();
    return;
}
?>
<?php if ($edit): ?>
<p class="text-muted small"><?= (int)$edit['is_pending'] === 1
    ? 'This build is pending: saving keeps it pending (nothing moves in stock) unless you complete it.'
    : 'Saving posts the build again: the components it used go back to the site, then the new quantities are taken and the assembly is added at their cost. "On hand at site" counts what this build used.' ?>
  <?= $recorded ? 'The components are the ones recorded on this build, per unit built.' : 'The components come from the bill of materials of the assembly chosen.' ?></p>
<?php endif; ?>
<form method="post"><?= csrf_field() ?>
<div class="card mb-3"><div class="card-body row g-2">
  <?= select_field('item_id', 'Assembly item', $assemblies, $itemId, ['class' => 'col-md-4', 'extra' => 'onchange="this.form.querySelector(\'[name=do]\').value=\'refresh\';this.form.submit()"']) ?>
  <?= select_field('site_id', 'Build at site', $sites, $siteId, ['class' => 'col-md-3', 'extra' => 'onchange="this.form.querySelector(\'[name=do]\').value=\'refresh\';this.form.submit()"']) ?>
  <?= input_field('qty', 'Quantity to build', qty($qtyBuild), ['class' => 'col-md-2', 'extra' => 'onchange="this.form.querySelector(\'[name=do]\').value=\'refresh\';this.form.submit()"']) ?>
  <?= input_field('txn_date', 'Date', fv('txn_date', $edit, today()), ['type' => 'date', 'class' => 'col-md-3']) ?>
  <?= input_field('memo', 'Memo', fv('memo', $edit), ['class' => Classes::ready() ? 'col-md-5' : 'col-md-8']) ?>
  <?= Classes::field(fv('class_id', $edit), 'col-md-3') ?>
  <?php if ($edit): ?><?= input_field('reason', 'Reason for the change', fv('reason'), ['class' => 'col-md-8', 'extra' => 'maxlength="255" placeholder="Recorded in the audit trail"']) ?><?php endif; ?>
  <div class="col-md-4 small d-flex align-items-end">On hand at site: <strong class="ms-1"><?= qty($onHandAsm) ?></strong>, build point <?= qty($asm['build_point'] ?? 0) ?></div>
</div></div>
<div class="card mb-3"><table class="table table-sm mb-0">
  <thead><tr><th>Component</th><th>Description</th><th class="num">Per unit</th><th class="num">Needed</th><th class="num">On hand at site</th><th></th></tr></thead>
  <?php foreach ($bom as $c): ?>
    <tr class="<?= $c['short'] ? 'table-danger' : '' ?>"><td><?= h($c['code']) ?></td><td><?= h($c['name']) ?></td><td class="num"><?= qty($c['per_unit']) ?></td><td class="num"><?= qty($c['need']) ?></td>
      <td class="num"><?= $c['have'] === null ? '<span class="text-muted">n/a</span>' : qty($c['have']) ?></td><td><?= $c['short'] ? badge('short', 'danger') : '' ?></td></tr>
  <?php endforeach; ?>
</table></div>
<input type="hidden" name="do" value="build">
<?php if ($edit): ?>
  <div class="d-flex flex-wrap gap-2 align-items-center">
  <?php if ((int)$edit['is_pending'] === 1): ?>
    <button class="btn btn-primary" onclick="this.form.querySelector('[name=do]').value='save'"><i class="bi bi-check2"></i> Save changes (still pending)</button>
    <?php if (!$short): ?><button class="btn btn-warning" onclick="this.form.querySelector('[name=do]').value='complete'"><i class="bi bi-hammer"></i> Save and complete the build</button><?php endif; ?>
  <?php elseif ($short): ?>
    <div class="alert alert-warning py-2 mb-0">Some components are short for this quantity at this site. Lower the quantity or move the components here first.</div>
  <?php else: ?>
    <button class="btn btn-primary" onclick="this.form.querySelector('[name=do]').value='save'"><i class="bi bi-check2"></i> Save changes</button>
  <?php endif; ?>
    <?= btn_link(Txn::url($edit), 'Cancel', 'btn-outline-secondary') ?>
  </div>
<?php elseif ($short): ?>
  <div class="alert alert-warning py-2">Some components are short. You can save the build as <strong>pending</strong>; it is non-posting and can be completed when the parts arrive.</div>
  <button class="btn btn-warning" onclick="this.form.querySelector('[name=do]').value='pending'">Save as pending build</button>
<?php else: ?>
  <button class="btn btn-primary" onclick="this.form.querySelector('[name=do]').value='build'"><i class="bi bi-hammer"></i> Build</button>
  <?php if (Access::can($pageKey, 'create')): ?><input type="hidden" name="save_new" value=""><button class="btn btn-outline-primary" title="Build this one and start another with empty fields"
    onclick="this.form.querySelector('[name=do]').value='build';this.form.save_new.value='1'"><i class="bi bi-plus-lg"></i> Build &amp; New</button><?php endif; ?>
<?php endif; ?>
<p class="small text-muted mt-2">Building reduces component quantities and increases the assembly quantity. The component cost (plus any labor on the bill of materials) becomes the assembly's value, so there is no gain or loss.</p>
</form>
<?php
layout_end();
