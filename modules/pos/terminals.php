<?php
declare(strict_types=1);

$pageKey = 'pos.terminals';
$edit = gint('id') ? DB::row('SELECT * FROM pos_terminals WHERE id = :id', ['id' => gint('id')]) : null;

handle_post(function () use ($edit, $pageKey) {
    Access::require($pageKey, $edit ? 'edit' : 'create');
    $d = [
        'code' => mb_substr(strtoupper(trim((string)p('code'))), 0, 20),
        'name' => mb_substr(trim((string)p('name')), 0, 80),
        'site_id' => pint('site_id'),
        'cash_account_id' => pint('cash_account_id'),
        'receipt_prefix' => mb_substr(trim((string)p('receipt_prefix')), 0, 10),
        'is_active' => p('is_active', '1') === '1' ? 1 : 0,
    ];
    if (Classes::ready()) {
        $d['class_id'] = Classes::posted();
    }
    if (!$d['code'] || !$d['name'] || !$d['site_id'] || !$d['cash_account_id']) {
        fail('Enter the code, name, site and cash account.');
    }
    if (DB::value('SELECT id FROM pos_terminals WHERE code = :c AND id <> :id', ['c' => $d['code'], 'id' => $edit['id'] ?? 0])) {
        fail('Another terminal uses this code.');
    }
    $users = array_map('intval', (array)($_POST['users'] ?? []));
    DB::tx(function () use ($edit, $d, $users, $pageKey) {
        if ($edit) {
            DB::update('pos_terminals', $d, 'id = :id', ['id' => $edit['id']]);
            $id = (int)$edit['id'];
        } else {
            $id = DB::insert('pos_terminals', $d);
        }
        $old = array_map('intval', DB::column('SELECT user_id FROM user_terminals WHERE terminal_id = :t', ['t' => $id]));
        DB::run('DELETE FROM user_terminals WHERE terminal_id = :t', ['t' => $id]);
        foreach ($users as $u) {
            DB::insert('user_terminals', ['user_id' => $u, 'terminal_id' => $id]);
        }
        Audit::log($edit ? 'edit' : 'create', 'pos', 'terminal', $id, $d['name'], [
            'before' => $edit ? $edit + ['users' => implode(',', $old)] : [], 'after' => $d + ['users' => implode(',', $users)], 'page_key' => $pageKey,
        ]);
    });
    flash('success', 'Terminal saved.');
    redirect_after_save(url($pageKey), url($pageKey, ['id' => $edit['id'] ?? DB::value('SELECT id FROM pos_terminals WHERE code = :c', ['c' => $d['code']])]));
});

$terms = DB::all('SELECT t.*, s.name AS site, a.name AS cash FROM pos_terminals t JOIN sites s ON s.id = t.site_id JOIN accounts a ON a.id = t.cash_account_id ORDER BY t.code');
$showClass = Classes::ready();
$assigned = $edit ? DB::column('SELECT user_id FROM user_terminals WHERE terminal_id = :t', ['t' => $edit['id']]) : [];
layout_start('POS Terminals');
?>
<div class="row g-3">
  <div class="col-lg-7"><div class="card"><table class="table table-sm mb-0">
    <thead><tr><th>Code</th><th>Name</th><th>Site</th><th>Cash account</th><?php if ($showClass): ?><th>Class</th><?php endif; ?><th>Next receipt</th><th>Users</th><th></th></tr></thead>
    <?php foreach ($terms as $t): $users = DB::column('SELECT u.username FROM user_terminals ut JOIN users u ON u.id = ut.user_id WHERE ut.terminal_id = :t', ['t' => $t['id']]); ?>
      <tr class="<?= (int)$t['is_active'] ? '' : 'text-muted' ?>"><td><?= h($t['code']) ?></td><td><?= h($t['name']) ?></td><td><?= h($t['site']) ?></td><td class="small"><?= h($t['cash']) ?></td>
        <?php if ($showClass): ?><td class="small"><?= h(Classes::name($t['class_id'] ?? null)) ?></td><?php endif; ?>
        <td><?= h($t['receipt_prefix'] . str_pad((string)$t['next_receipt_no'], 8, '0', STR_PAD_LEFT)) ?></td><td class="small"><?= h(implode(', ', $users) ?: 'any user with POS access') ?></td>
        <td><?= Access::can($pageKey, 'edit') ? '<a href="' . h(url($pageKey, ['id' => $t['id']])) . '">Edit</a>' : '' ?></td></tr>
    <?php endforeach; ?>
  </table></div></div>
  <div class="col-lg-5">
  <?php if (Access::can($pageKey, $edit ? 'edit' : 'create')): ?>
    <form method="post" class="card" data-no-class-check><div class="card-header"><?= $edit ? 'Edit terminal' : 'New terminal' ?></div><div class="card-body row g-2">
      <?= csrf_field() ?>
      <?= input_field('code', 'Code', fv('code', $edit), ['class' => 'col-md-4', 'required' => true]) ?>
      <?= input_field('name', 'Name', fv('name', $edit), ['class' => 'col-md-8', 'required' => true]) ?>
      <?= select_field('site_id', 'Inventory site (stock is sold from here)', Lookup::sites(false), fv('site_id', $edit), ['class' => 'col-md-6']) ?>
      <?= select_field('cash_account_id', 'Cash on hand account', Accts::banks(), fv('cash_account_id', $edit, Accts::get('cash_on_hand')), ['class' => 'col-md-6']) ?>
      <?= input_field('receipt_prefix', 'Receipt prefix', fv('receipt_prefix', $edit, 'OR-'), ['class' => 'col-md-4']) ?>
      <?= Classes::field(fv('class_id', $edit), 'col-md-4', 'class_id', 'Default class of sales') ?>
      <?= check_field('is_active', 'Active', fv('is_active', $edit, 1), 'col-md-4') ?>
      <div class="col-12"><label class="form-label">Users assigned to this terminal (none = any user with POS access at the site)</label>
        <select class="form-select form-select-sm" name="users[]" multiple size="5"><?php foreach (Lookup::users() as $id => $name): ?><option value="<?= (int)$id ?>" <?= in_array((string)$id, array_map('strval', $assigned), true) ? 'selected' : '' ?>><?= h($name) ?></option><?php endforeach; ?></select></div>
      <div class="col-12"><?= save_buttons('Save terminal', $edit ? url($pageKey) : null, Access::can($pageKey, 'create')) ?></div>
      <p class="small text-muted mb-0">Receipt numbers are sequential per terminal and cannot be reset here.</p>
    </div></form>
  <?php endif; ?>
  </div>
</div>
<?php
layout_end();
