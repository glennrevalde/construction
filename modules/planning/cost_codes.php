<?php
declare(strict_types=1);

/** Cost codes: the work items costs, budgets and progress are tracked by (a two-level list: groups and items). */
$pageKey = 'plan.cost_codes';
$id = gint('id');

handle_post(function () use ($pageKey, $id) {
    Access::require($pageKey, $id ? 'edit' : 'create');
    $row = ['code' => trim((string)p('code')), 'name' => trim((string)p('name')), 'category' => isset(CsCost::TYPES[p('category')]) ? p('category') : 'other',
        'parent_id' => pint('parent_id'), 'account_id' => pint('account_id'), 'unit' => trim((string)p('unit')) ?: null, 'description' => trim((string)p('description')) ?: null,
        'is_active' => p('is_active', '1') === '1' ? 1 : 0, 'sort_order' => (int)p('sort_order')];
    if ($row['code'] === '' || $row['name'] === '') {
        fail('Enter the code and the name.');
    }
    if (DB::value('SELECT id FROM cs_cost_codes WHERE code = :c' . ($id ? ' AND id <> :id' : ''), ['c' => $row['code']] + ($id ? ['id' => $id] : []))) {
        fail('Cost code ' . $row['code'] . ' already exists.');
    }
    if ($id && $row['parent_id'] === $id) {
        fail('A cost code cannot be its own group.');
    }
    if ($id) {
        $old = DB::row('SELECT * FROM cs_cost_codes WHERE id = :id', ['id' => $id]);
        DB::update('cs_cost_codes', $row, 'id = :id', ['id' => $id]);
        Audit::log('edit', 'plan', 'cost_code', $id, $row['code'], ['page_key' => $pageKey, 'before' => $old, 'after' => $row]);
    } else {
        if (!$row['sort_order']) {
            $row['sort_order'] = 10 + (int)DB::value('SELECT COALESCE(MAX(sort_order),0) FROM cs_cost_codes');
        }
        $id = DB::insert('cs_cost_codes', $row);
        Audit::log('create', 'plan', 'cost_code', $id, $row['code'], ['page_key' => $pageKey]);
    }
    CsCost::flush();
    flash('success', 'Cost code saved.');
    cs_go($pageKey);
});

$edit = $id ? DB::row('SELECT * FROM cs_cost_codes WHERE id = :id', ['id' => $id]) : null;
$codes = CsCost::codes();
$heads = DB::pairs("SELECT id, CONCAT(code, ' ', name) FROM cs_cost_codes WHERE parent_id IS NULL ORDER BY sort_order, code");
$costAccounts = DB::pairs("SELECT id, CONCAT(code, ' ', name) FROM accounts WHERE category = 'Expense' AND is_active = 1 ORDER BY code");
$used = DB::pairs('SELECT cost_code_id, COUNT(*) FROM journal_lines WHERE cost_code_id IS NOT NULL GROUP BY cost_code_id');
layout_start('Cost Codes', ['sub' => 'Work items of a job. Costs are recorded by cost code (the work) and by account (the kind of cost), so budgets compare both ways.']);
echo '<div class="cs-split"><div>';
$rows = [];
foreach ($codes as $c) {
    $isHead = !$c['parent_id'];
    $rows[] = ['_class' => $isHead ? 'is-group' : '', ($isHead ? '' : '&nbsp;&nbsp;&nbsp;') . h($c['code']), h($c['name']), h(CsCost::TYPES[$c['category']] ?? $c['category']),
        h((string)($costAccounts[$c['account_id']] ?? '')), (int)($used[$c['id']] ?? 0), (int)$c['is_active'] ? '' : '<span class="cs-state is-muted">Inactive</span>',
        Access::can($pageKey, 'edit') ? '<a href="' . h(url($pageKey, ['id' => $c['id']])) . '">Edit</a>' : ''];
}
echo cs_table_card(count($codes) . ' cost codes', ['Code' => '', 'Name' => '', 'Usual cost type' => '', 'Suggested account' => '', 'Ledger lines' => 'num', '' => '', ' ' => ''], $rows, 'No cost codes yet.');
echo '</div><div>';
if (Access::can($pageKey, $edit ? 'edit' : 'create')) {
    $c = $edit ?: ['is_active' => 1, 'category' => 'material'];
    echo '<form method="post" class="card"><div class="card-header">' . ($edit ? 'Edit ' . h($edit['code']) : 'New cost code') . '</div><div class="card-body"><div class="row g-2">' . csrf_field()
        . CsUi::field('Code', CsUi::input('code', fv('code', $c), ['required' => true, 'max' => 30, 'placeholder' => '03-400']), 'col-5')
        . CsUi::field('Name', CsUi::input('name', fv('name', $c), ['required' => true, 'max' => 150]), 'col-7')
        . CsUi::field('Group', CsUi::select('parent_id', $heads, fv('parent_id', $c), 'None: this is a group'), 'col-12')
        . CsUi::field('Usual cost type', CsUi::select('category', CsCost::TYPES, fv('category', $c), null, 'data-no-combo'), 'col-6')
        . CsUi::field('Unit', CsUi::input('unit', fv('unit', $c), ['max' => 20, 'placeholder' => 'cu.m, sq.m, lot']), 'col-6')
        . CsUi::field('Suggested cost account', CsUi::select('account_id', $costAccounts, fv('account_id', $c), 'From the cost type'), 'col-12')
        . CsUi::field('Description', CsUi::input('description', fv('description', $c), ['max' => 255]), 'col-12')
        . CsUi::field('Order', CsUi::input('sort_order', fv('sort_order', $c, ''), ['type' => 'number', 'step' => '1']), 'col-6')
        . check_field('is_active', 'Active', (int)fv('is_active', $c, 1) === 1, 'col-6')
        . '</div></div><div class="card-footer">' . save_buttons('Save', $edit ? url($pageKey) : null, false) . '</div></form>';
}
echo '</div></div>';
layout_end();
