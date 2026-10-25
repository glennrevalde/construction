<?php
declare(strict_types=1);

/**
 * Employees & Other Names.
 * Names that are neither customers nor suppliers but are paid or tracked on cash vouchers
 * and journal entries: employees (cash advances, reimbursements), owners, agencies and so on.
 */

$pageKey = 'coa.names';
$types = Names::TYPES;
$tab = array_key_exists(g('type'), $types) ? g('type') : '';
$edit = gint('id') ? DB::row('SELECT * FROM other_names WHERE id = :id', ['id' => gint('id')]) : null;

if (g('a') === 'delete' && is_post()) {
    Access::require($pageKey, 'delete');
    $row = DB::row('SELECT * FROM other_names WHERE id = :id', ['id' => (int)g('id')]);
    if ($row) {
        $used = (int)DB::value('SELECT COUNT(*) FROM transactions WHERE other_name_id = :id', ['id' => $row['id']])
            + (int)DB::value('SELECT COUNT(*) FROM transaction_lines WHERE other_name_id = :id', ['id' => $row['id']]);
        if ($used > 0) {
            flash('warning', $row['name'] . ' is already used on transactions, so it cannot be deleted. Make it inactive instead.');
        } else {
            DB::run('DELETE FROM other_names WHERE id = :id', ['id' => $row['id']]);
            Audit::log('delete', 'coa', 'other_name', (int)$row['id'], $row['name'], ['snapshot' => $row, 'page_key' => $pageKey]);
            flash('success', $row['name'] . ' deleted.');
        }
    }
    redirect(url($pageKey, ['type' => $tab]));
}

handle_post(function () use ($edit, $pageKey, $types) {
    Access::require($pageKey, $edit ? 'edit' : 'create');
    $d = [
        'name_type'   => array_key_exists(p('name_type'), $types) ? p('name_type') : 'other',
        'name'        => mb_substr(trim((string)p('name')), 0, 120),
        'employee_no' => mb_substr(trim((string)p('employee_no')), 0, 30) ?: null,
        'position'    => mb_substr(trim((string)p('position')), 0, 80) ?: null,
        'phone'       => mb_substr(trim((string)p('phone')), 0, 40) ?: null,
        'address'     => mb_substr(trim((string)p('address')), 0, 255) ?: null,
        'tin'         => mb_substr(trim((string)p('tin')), 0, 30) ?: null,
        'notes'       => mb_substr(trim((string)p('notes')), 0, 255) ?: null,
        'is_active'   => p('is_active', '1') === '1' ? 1 : 0,
    ];
    if ($d['name'] === '') {
        fail('Enter the name.');
    }
    if (DB::value('SELECT id FROM other_names WHERE name = :n AND name_type = :t AND id <> :id', ['n' => $d['name'], 't' => $d['name_type'], 'id' => $edit['id'] ?? 0])) {
        fail('This name is already on the list.');
    }
    if ($edit) {
        DB::update('other_names', $d, 'id = :id', ['id' => $edit['id']]);
        $id = (int)$edit['id'];
        Audit::log('edit', 'coa', 'other_name', $id, $d['name'], ['before' => $edit, 'after' => $d, 'page_key' => $pageKey]);
    } else {
        $id = DB::insert('other_names', $d);
        Audit::log('create', 'coa', 'other_name', $id, $d['name'], ['snapshot' => $d, 'page_key' => $pageKey]);
    }
    flash('success', 'Name saved.');
    redirect_after_save(url($pageKey, ['type' => $d['name_type']]), url($pageKey, ['type' => $d['name_type'], 'id' => $id]));
});

$sql = 'SELECT n.*, (SELECT COUNT(*) FROM transactions t WHERE t.other_name_id = n.id) AS txns FROM other_names n';
$params = [];
if ($tab !== '') {
    $sql .= ' WHERE n.name_type = :t';
    $params['t'] = $tab;
}
$rows = DB::all($sql . ' ORDER BY n.name', $params);
$canEdit = Access::can($pageKey, 'edit');
$canDelete = Access::can($pageKey, 'delete');

layout_start('Employees & Other Names', ['actions' => page_link('bank.cash_voucher', [], 'Cash Voucher', 'btn-outline-primary', 'cash-stack')]);
?>
<p class="text-muted small">Names that can be paid or tracked without being a customer or a supplier: employees, owners, agencies and the like.
  They appear in the Paid to and SL (subsidiary ledger) lists of cash vouchers and in the Name list of journal entries, next to customers and suppliers.</p>
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab === '' ? 'active' : '' ?>" href="<?= h(url($pageKey)) ?>">All</a></li>
  <?php foreach ($types as $k => $label): ?>
    <li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active' : '' ?>" href="<?= h(url($pageKey, ['type' => $k])) ?>"><?= h($label) ?>s</a></li>
  <?php endforeach; ?>
</ul>
<div class="row g-3">
  <div class="col-lg-7"><div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
    <thead><tr><th>Name</th><th>Type</th><th>Employee no.</th><th>Position</th><th>Phone</th><th>Active</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-3">No names yet. Add the first one on the right.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr class="<?= (int)$r['is_active'] ? '' : 'text-muted' ?>">
        <td class="fw-semibold"><?= h($r['name']) ?></td><td class="small"><?= h($types[$r['name_type']] ?? $r['name_type']) ?></td>
        <td class="small"><?= h($r['employee_no']) ?></td><td class="small"><?= h($r['position']) ?></td><td class="small"><?= h($r['phone']) ?></td>
        <td><?= (int)$r['is_active'] ? 'Yes' : 'No' ?></td>
        <td class="text-nowrap text-end">
          <?php if ($canEdit): ?><a href="<?= h(url($pageKey, ['type' => $tab, 'id' => $r['id']])) ?>">Edit</a><?php endif; ?>
          <?php if ($canDelete && (int)$r['txns'] === 0): ?>
            <form method="post" action="<?= h(url($pageKey, ['a' => 'delete', 'id' => $r['id'], 'type' => $tab])) ?>" class="d-inline ms-2"><?= csrf_field() ?>
              <button class="btn btn-link btn-sm text-danger p-0 align-baseline" data-confirm="Delete <?= h($r['name']) ?>?">Delete</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div></div></div>
  <div class="col-lg-5">
    <?php if (Access::can($pageKey, $edit ? 'edit' : 'create')): ?>
    <form method="post" class="card"><div class="card-header"><?= $edit ? 'Edit name' : 'New name' ?></div><div class="card-body row g-2">
      <?= csrf_field() ?>
      <?= select_field('name_type', 'Type', $types, fv('name_type', $edit, $tab ?: 'employee'), ['class' => 'col-md-4']) ?>
      <?= input_field('name', 'Name', fv('name', $edit), ['class' => 'col-md-8', 'required' => true, 'extra' => 'maxlength="120"']) ?>
      <?= input_field('employee_no', 'Employee no.', fv('employee_no', $edit), ['class' => 'col-md-4']) ?>
      <?= input_field('position', 'Position / relationship', fv('position', $edit), ['class' => 'col-md-8']) ?>
      <?= input_field('phone', 'Phone', fv('phone', $edit), ['class' => 'col-md-6']) ?>
      <?= input_field('tin', 'TIN', fv('tin', $edit), ['class' => 'col-md-6']) ?>
      <div class="col-12"><label class="form-label">Address</label><input class="form-control form-control-sm" name="address" maxlength="255" value="<?= h(fv('address', $edit)) ?>"></div>
      <div class="col-12"><label class="form-label">Notes</label><input class="form-control form-control-sm" name="notes" maxlength="255" value="<?= h(fv('notes', $edit)) ?>"></div>
      <?= check_field('is_active', 'Active', fv('is_active', $edit, 1), 'col-12') ?>
      <div class="col-12"><?= save_buttons('Save name', $edit ? url($pageKey, ['type' => $tab]) : null, Access::can($pageKey, 'create')) ?></div>
    </div></form>
    <?php endif; ?>
  </div>
</div>
<?php
layout_end();
