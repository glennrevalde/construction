<?php
declare(strict_types=1);

/**
 * Class List.
 * Classes are named by the company itself (branches, departments, projects, product lines ...).
 * They can be renamed at any time; reports always show the current name.
 */

$pageKey = 'coa.classes';

if (!Classes::ready()) {
    layout_start('Class List');
    echo '<div class="alert alert-warning">Class tracking needs a database update. Import <code>database/migrations/2026_09_19_classes_cash_voucher.sql</code> (phpMyAdmin or Navicat), then reload this page.</div>';
    layout_end();
    return;
}

$edit = gint('id') ? DB::row('SELECT * FROM classes WHERE id = :id', ['id' => gint('id')]) : null;

// ---------------------------------------------------------------- delete (only a class that was never used)
if (g('a') === 'delete' && is_post()) {
    Access::require($pageKey, 'delete');
    $row = DB::row('SELECT * FROM classes WHERE id = :id', ['id' => (int)g('id')]);
    if ($row) {
        if (Classes::usage((int)$row['id']) > 0) {
            flash('warning', $row['name'] . ' is already used on transactions, so it cannot be deleted. Make it inactive instead.');
        } else {
            DB::run('DELETE FROM classes WHERE id = :id', ['id' => $row['id']]);
            Audit::log('delete', 'coa', 'class', (int)$row['id'], $row['name'], ['snapshot' => $row, 'page_key' => $pageKey]);
            flash('success', 'Class ' . $row['name'] . ' deleted.');
        }
    }
    redirect(url($pageKey));
}

handle_post(function () use ($edit, $pageKey) {
    Access::require($pageKey, $edit ? 'edit' : 'create');
    $d = [
        'name'        => mb_substr(trim((string)p('name')), 0, 80),
        'description' => mb_substr(trim((string)p('description')), 0, 255) ?: null,
        'sort_order'  => (int)p('sort_order', '0'),
        'is_active'   => p('is_active', '1') === '1' ? 1 : 0,
    ];
    if ($d['name'] === '') {
        fail('Enter the class name.');
    }
    if (DB::value('SELECT id FROM classes WHERE name = :n AND id <> :id', ['n' => $d['name'], 'id' => $edit['id'] ?? 0])) {
        fail('Another class already has this name.');
    }
    if ($edit) {
        DB::update('classes', $d, 'id = :id', ['id' => $edit['id']]);
        $id = (int)$edit['id'];
        Audit::log('edit', 'coa', 'class', $id, $d['name'], ['before' => $edit, 'after' => $d, 'page_key' => $pageKey]);
    } else {
        $d['created_by'] = Auth::id();
        $id = DB::insert('classes', $d);
        Audit::log('create', 'coa', 'class', $id, $d['name'], ['snapshot' => $d, 'page_key' => $pageKey]);
    }
    Classes::reset();
    flash('success', 'Class saved.');
    redirect_after_save(url($pageKey), url($pageKey, ['id' => $id]));
});

$rows = DB::all(
    'SELECT c.*,
            (SELECT COUNT(*) FROM transactions t WHERE t.class_id = c.id) AS txns,
            (SELECT COALESCE(SUM(j.credit - j.debit), 0) FROM journal_lines j JOIN accounts a ON a.id = j.account_id
              WHERE j.class_id = c.id AND a.category IN (\'Income\', \'Expense\') AND j.txn_date >= :fy) AS net_income
       FROM classes c ORDER BY c.sort_order, c.name',
    ['fy' => Report::fiscalYearStart(today())]
);
$canEdit = Access::can($pageKey, 'edit');
$canDelete = Access::can($pageKey, 'delete');

layout_start('Class List', ['actions' => page_link('rpt.income_statement_class', [], 'Income Statement by Class', 'btn-outline-secondary', 'bar-chart')
    . ' ' . page_link('rpt.balance_sheet_class', [], 'Balance Sheet by Class', 'btn-outline-secondary', 'columns-gap')]);
?>
<p class="text-muted small">Classes split the books your own way: by branch, department, project or product line. Name them anything you like and rename them whenever you need to.
  Every transaction form has a Class box, and the financial reports can be filtered by class or shown with one column per class.</p>
<div class="row g-3">
  <div class="col-xl-8"><div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
    <thead><tr><th>Class name</th><th>Description</th><th class="num">Order</th><th class="num">Entries</th><th class="num" title="Net income of this class in the current fiscal year">Net income (FY)</th><th>Active</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-3">No classes yet. Add the first one on the right.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr class="<?= (int)$r['is_active'] ? '' : 'text-muted' ?>">
        <td class="fw-semibold text-nowrap"><?= h($r['name']) ?></td>
        <td class="small"><?= h($r['description']) ?></td>
        <td class="num"><?= (int)$r['sort_order'] ?></td>
        <td class="num"><?= (int)$r['txns'] ?></td>
        <td class="num <?= (float)$r['net_income'] < 0 ? 'neg' : '' ?>"><?= Access::page('rpt.income_statement_class') ? money($r['net_income']) : '' ?></td>
        <td><?= (int)$r['is_active'] ? 'Yes' : 'No' ?></td>
        <td class="text-nowrap text-end">
          <?php if ($canEdit): ?><a href="<?= h(url($pageKey, ['id' => $r['id']])) ?>">Edit</a><?php endif; ?>
          <?php if ($canDelete && (int)$r['txns'] === 0): ?>
            <form method="post" action="<?= h(url($pageKey, ['a' => 'delete', 'id' => $r['id']])) ?>" class="d-inline ms-2"><?= csrf_field() ?>
              <button class="btn btn-link btn-sm text-danger p-0 align-baseline" data-confirm="Delete the class <?= h($r['name']) ?>?">Delete</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div></div></div>
  <div class="col-xl-4">
    <?php if (Access::can($pageKey, $edit ? 'edit' : 'create')): ?>
    <form method="post" class="card"><div class="card-header"><?= $edit ? 'Edit class' : 'New class' ?></div><div class="card-body row g-2">
      <?= csrf_field() ?>
      <?= input_field('name', 'Class name', fv('name', $edit), ['class' => 'col-md-8', 'required' => true, 'extra' => 'maxlength="80" placeholder="e.g. Main Branch, Piggery, Meat Shop"']) ?>
      <?= input_field('sort_order', 'Order in lists', fv('sort_order', $edit, '0'), ['class' => 'col-md-4', 'type' => 'number', 'step' => '1']) ?>
      <div class="col-12"><label class="form-label">Description</label><input class="form-control form-control-sm" name="description" maxlength="255" value="<?= h(fv('description', $edit)) ?>"></div>
      <?= check_field('is_active', 'Active (offered on transaction forms)', fv('is_active', $edit, 1), 'col-12') ?>
      <div class="col-12"><?= save_buttons('Save class', $edit ? url($pageKey) : null, Access::can($pageKey, 'create')) ?></div>
      <?php if ($edit): ?><div class="col-12 small text-muted">Renaming a class changes its name everywhere, including past transactions and reports.</div><?php endif; ?>
    </div></form>
    <?php endif; ?>
  </div>
</div>
<?php
layout_end();
