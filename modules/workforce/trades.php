<?php
declare(strict_types=1);

/** Trades and their usual daily rates (the rate a new worker of the trade starts with). */
$pageKey = 'labor.trades';
$id = gint('id');
handle_post(function () use ($pageKey, $id) {
    Access::require($pageKey, $id ? 'edit' : 'create');
    $row = ['name' => mb_substr(trim((string)p('name')), 0, 80), 'description' => mb_substr(trim((string)p('description')), 0, 255) ?: null, 'daily_rate' => to_num(p('daily_rate')),
        'is_active' => p('is_active', '1') === '1' ? 1 : 0];
    if ($row['name'] === '') {
        fail('Enter the trade.');
    }
    if (DB::value('SELECT id FROM cs_trades WHERE name = :n' . ($id ? ' AND id <> :id' : ''), ['n' => $row['name']] + ($id ? ['id' => $id] : []))) {
        fail('That trade already exists.');
    }
    if ($id) {
        $old = DB::row('SELECT * FROM cs_trades WHERE id = :id', ['id' => $id]);
        DB::update('cs_trades', $row, 'id = :id', ['id' => $id]);
        Audit::log('edit', 'labor', 'trade', $id, $row['name'], ['page_key' => $pageKey, 'before' => $old, 'after' => $row]);
    } else {
        $id = DB::insert('cs_trades', $row);
        Audit::log('create', 'labor', 'trade', $id, $row['name'], ['page_key' => $pageKey]);
    }
    flash('success', 'Trade saved. Workers already on it keep their own rates.');
    cs_go($pageKey);
});
$edit = $id ? DB::row('SELECT * FROM cs_trades WHERE id = :id', ['id' => $id]) : null;
layout_start('Trades & Rates');
$counts = DB::pairs("SELECT trade_id, COUNT(*) FROM cs_workers WHERE status = 'active' GROUP BY trade_id");
$rows = [];
foreach (DB::all('SELECT * FROM cs_trades ORDER BY name') as $t) {
    $rows[] = [h($t['name']) . '<span class="cs-sub">' . h((string)$t['description']) . '</span>', money($t['daily_rate']), money((float)$t['daily_rate'] / max(1, (float)Settings::get('cs_regular_hours', '8'))), (int)($counts[$t['id']] ?? 0),
        (int)$t['is_active'] ? '' : CsUi::state('inactive'), Access::can($pageKey, 'edit') ? '<a href="' . h(url($pageKey, ['id' => $t['id']])) . '">Edit</a>' : ''];
}
echo '<div class="cs-split"><div>' . cs_table_card('Trades', ['Trade' => '', 'Daily rate' => 'num', 'Hourly' => 'num', 'Active workers' => 'num', '' => '', ' ' => ''], $rows, 'No trades yet.') . '</div><div>';
if (Access::can($pageKey, $edit ? 'edit' : 'create')) {
    $c = $edit ?: ['is_active' => 1];
    echo '<form method="post" class="card"><div class="card-header">' . ($edit ? 'Edit ' . h($edit['name']) : 'New trade') . '</div><div class="card-body"><div class="row g-2">' . csrf_field()
        . CsUi::field('Trade', CsUi::input('name', fv('name', $c), ['required' => true, 'max' => 80]), 'col-12') . CsUi::field('Description', CsUi::input('description', fv('description', $c), ['max' => 255]), 'col-12')
        . CsUi::field('Daily rate', CsUi::input('daily_rate', fv('daily_rate', $c), ['num' => true]), 'col-6') . check_field('is_active', 'Active', (int)fv('is_active', $c, 1) === 1, 'col-6')
        . '</div></div><div class="card-footer">' . save_buttons('Save', $edit ? url($pageKey) : null, false) . '</div></form>';
}
echo '</div></div>';
layout_end();
