<?php
declare(strict_types=1);

/** Allocation rules: a named basis, and for fixed percentages or custom units the share of each project. */
$pageKey = 'cost.rules';
$id = gint('id');
$r = $id ? DB::row('SELECT * FROM cs_alloc_rules WHERE id = :id', ['id' => $id]) : null;

handle_post(function () use ($pageKey, $id, $r) {
    Access::require($pageKey, $id ? 'edit' : 'create');
    $row = ['name' => mb_substr(trim((string)p('name')), 0, 120), 'method' => isset(CsAlloc::METHODS[p('method')]) ? p('method') : 'equal', 'description' => mb_substr(trim((string)p('description')), 0, 255) ?: null,
        'is_active' => p('is_active', '1') === '1' ? 1 : 0];
    if ($row['name'] === '') {
        fail('Enter the rule\'s name.');
    }
    $shares = [];
    foreach ((array)($_POST['share'] ?? []) as $pid => $v) {
        if (trim((string)$v) !== '' && to_num($v) > 0) {
            $shares[(int)$pid] = to_num($v);
        }
    }
    if ($row['method'] === 'fixed_pct' && abs(array_sum($shares) - 100) > 0.01) {
        fail('The percentages add up to ' . number_format(array_sum($shares), 2) . '%; they must make 100%.');
    }
    if (in_array($row['method'], ['fixed_pct', 'custom'], true) && !$shares) {
        fail('Give each project its share.');
    }
    DB::tx(function () use (&$id, $row, $shares, $r, $pageKey) {
        if ($r) {
            DB::update('cs_alloc_rules', $row, 'id = :id', ['id' => $id]);
        } else {
            $id = DB::insert('cs_alloc_rules', $row);
        }
        DB::run('DELETE FROM cs_alloc_rule_shares WHERE rule_id = :r', ['r' => $id]);
        foreach ($shares as $pid => $v) {
            DB::insert('cs_alloc_rule_shares', ['rule_id' => $id, 'project_id' => $pid, 'share_value' => $v]);
        }
        Audit::log($r ? 'edit' : 'create', 'cost', 'alloc_rule', $id, $row['name'], ['page_key' => $pageKey, 'reason' => CsAlloc::METHODS[$row['method']]]);
    });
    flash('success', 'Rule saved.');
    cs_go($pageKey);
});

layout_start('Allocation Rules');
$rules = DB::all('SELECT r.*, (SELECT COUNT(*) FROM cs_alloc_rule_shares s WHERE s.rule_id = r.id) AS n FROM cs_alloc_rules r ORDER BY r.name');
$rows = [];
foreach ($rules as $x) {
    $rows[] = [h($x['name']) . '<span class="cs-sub">' . h((string)$x['description']) . '</span>', h(CsAlloc::METHODS[$x['method']] ?? $x['method']), (int)$x['n'] ?: '', (int)$x['is_active'] ? '' : CsUi::state('inactive'),
        Access::can($pageKey, 'edit') ? '<a href="' . h(url($pageKey, ['id' => $x['id']])) . '">Edit</a>' : ''];
}
echo '<div class="cs-split"><div>' . cs_table_card('Rules', ['Rule' => '', 'Basis' => '', 'Projects with a share' => 'num', '' => '', ' ' => ''], $rows, 'No rules yet.') . '</div><div>';
if (Access::can($pageKey, $r ? 'edit' : 'create')) {
    $c = $r ?: ['method' => 'fixed_pct', 'is_active' => 1];
    $shares = $r ? DB::pairs('SELECT project_id, share_value FROM cs_alloc_rule_shares WHERE rule_id = :r', ['r' => $id]) : [];
    echo '<form method="post" class="card"><div class="card-header">' . ($r ? 'Edit ' . h($r['name']) : 'New rule') . '</div><div class="card-body"><div class="row g-2">' . csrf_field()
        . CsUi::field('Name', CsUi::input('name', fv('name', $c), ['required' => true, 'max' => 120]), 'col-12') . CsUi::field('Basis', CsUi::select('method', CsAlloc::METHODS, fv('method', $c), null, 'data-no-combo'), 'col-7')
        . check_field('is_active', 'Active', (int)fv('is_active', $c, 1) === 1, 'col-5') . CsUi::field('Description', CsUi::input('description', fv('description', $c), ['max' => 255]), 'col-12')
        . '</div><div class="cs-note mt-3 mb-1">Shares (percent for fixed percentages, any units for custom)</div>';
    foreach (DB::all("SELECT id, code, name FROM cs_projects WHERE status <> 'closed' ORDER BY code") as $p) {
        echo '<div class="cs-check"><div>' . h($p['code'] . ' ' . $p['name']) . '</div><div><input class="form-control form-control-sm text-end" name="share[' . (int)$p['id'] . ']" value="' . h(isset($shares[$p['id']]) ? (string)(float)$shares[$p['id']] : '') . '"></div></div>';
    }
    echo '</div><div class="card-footer">' . save_buttons('Save rule', $r ? url($pageKey) : null, false) . '</div></form>';
}
echo '</div></div>';
layout_end();
