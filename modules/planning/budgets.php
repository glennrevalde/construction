<?php
declare(strict_types=1);

/**
 * Project budgets: the first approved budget is the baseline and is never changed; later budgets are revisions,
 * each with its reason and its own approval. Comparisons always show the original beside the revised figures.
 */
$pageKey = 'plan.budgets';
$a = (string)g('a', 'list');
$id = gint('id');
$b = $id ? DB::row('SELECT * FROM cs_budgets WHERE id = :id', ['id' => $id]) : null;
if ($b) {
    CsProject::requireAccess((int)$b['project_id']);
}

handle_post(function () use ($pageKey, $id, $b) {
    if ($b && CsApproval::handlePost('budget', (int)$id, function () use ($b) {
        if (!in_array($b['status'], ['draft', 'rejected'], true)) {
            fail('Only a draft budget is submitted.');
        }
        $p = CsProject::get((int)$b['project_id']);
        CsApproval::submit('budget', (int)$b['id'], $p['code'] . ' v' . $b['version_no'], (int)$b['project_id'], (float)$b['total_amount']);
    })) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if (p('act') === 'revise') {
        Access::require($pageKey, 'create');
        $pid = (int)p('project_id');
        CsProject::requireAccess($pid);
        $cur = CsCost::budgets($pid)['current'];
        if (!$cur) {
            fail('There is no approved budget to revise.');
        }
        $lines = DB::all('SELECT cost_code_id, category, description, qty, unit, unit_cost, amount FROM cs_budget_lines WHERE budget_id = :b', ['b' => $cur['id']]);
        $nid = CsPlanning::saveBudget(['project_id' => $pid, 'name' => 'Revision ' . (1 + (int)$cur['version_no']), 'reason' => trim((string)p('reason')) ?: 'Budget revision'], $lines);
        flash('success', 'A draft revision was made from the budget in force. Change the lines, then submit it.');
        cs_go($pageKey, ['a' => 'edit', 'id' => $nid]);
    }
    if (p('act') === 'save') {
        Access::require($pageKey, $id ? 'edit' : 'create');
        $nid = CsPlanning::saveBudget($_POST, cs_lines(), $id);
        flash('success', 'Budget saved.');
        cs_go($pageKey, ['a' => 'view', 'id' => $nid]);
    }
});

if ($a === 'new' || ($a === 'edit' && $b)) {
    Access::require($pageKey, $b ? 'edit' : 'create');
    $pid = $b ? (int)$b['project_id'] : (int)(gint('project') ?? CsProject::current() ?? 0);
    $c = $b ?: ['project_id' => $pid, 'name' => 'Original budget', 'effective_date' => today()];
    $lines = is_post() ? cs_lines() : ($b ? DB::all('SELECT * FROM cs_budget_lines WHERE budget_id = :b ORDER BY id', ['b' => $id]) : []);
    $ccOpts = CsCost::codeOptions();
    $row = function (array $l, string $i) use ($ccOpts): string {
        $n = fn($f) => 'lines[' . $i . '][' . $f . ']';
        return '<tr><td>' . CsUi::select($n('cost_code_id'), $ccOpts, $l['cost_code_id'] ?? '', '', 'data-combo') . '</td>'
            . '<td>' . CsUi::select($n('category'), array_diff_key(CsCost::TYPES, ['overhead' => 1]) + ['overhead' => 'Overhead'], $l['category'] ?? 'material', null, 'data-no-combo') . '</td>'
            . '<td class="w-desc"><input class="form-control form-control-sm" name="' . $n('description') . '" value="' . h($l['description'] ?? '') . '"></td>'
            . '<td class="w-sm"><input class="form-control form-control-sm text-end" name="' . $n('qty') . '" value="' . h(isset($l['qty']) && $l['qty'] !== '' ? qty($l['qty']) : '') . '" data-q></td>'
            . '<td class="w-sm"><input class="form-control form-control-sm" name="' . $n('unit') . '" value="' . h($l['unit'] ?? '') . '"></td>'
            . '<td class="w-num"><input class="form-control form-control-sm text-end" name="' . $n('unit_cost') . '" value="' . h(isset($l['unit_cost']) && $l['unit_cost'] !== '' ? (float)$l['unit_cost'] : '') . '" data-r></td>'
            . '<td class="w-num num" data-a>' . money($l['amount'] ?? 0) . '</td>'
            . '<td class="grid-acts"><button type="button" class="btn btn-link btn-sm text-danger p-0" data-cs-del aria-label="Remove"><i class="bi bi-x-lg"></i></button></td></tr>';
    };
    $hasBase = $pid && (int)DB::value("SELECT COUNT(*) FROM cs_budgets WHERE project_id = :p AND is_baseline = 1 AND status IN ('approved','superseded')", ['p' => $pid]) > 0;
    layout_start($b ? 'Edit budget v' . (int)$b['version_no'] : 'New budget', ['module' => 'plan', 'sub' => $hasBase ? 'This is a revision: give the reason. The original baseline stays as it is.' : 'The first approved budget becomes the baseline.']);
    ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="save">
    <div class="card mb-3"><div class="card-body"><div class="row g-3">
      <?= CsUi::field('Project', $b ? '<input type="hidden" name="project_id" value="' . (int)$pid . '"><div class="pt-1">' . CsUi::projectLink($pid) . '</div>' : CsUi::projectSelect('project_id', $pid ?: '', ['required' => true]), 'col-md-5') ?>
      <?= CsUi::field('Name', CsUi::input('name', fv('name', $c), ['max' => 150]), 'col-md-4') ?>
      <?= CsUi::field('Effective date', CsUi::input('effective_date', fv('effective_date', $c), ['type' => 'date']), 'col-md-3') ?>
      <?= CsUi::field($hasBase ? 'Reason for the revision' : 'Notes', CsUi::textarea('reason', fv('reason', $c), 2), 'col-12') ?>
    </div></div></div>
    <div class="card mb-3"><div class="table-responsive"><table class="table table-sm cs-lines mb-0" id="budGrid" data-cs-lines data-min-rows="5">
      <thead><tr><th>Cost code</th><th>Cost type</th><th>Description</th><th class="num">Qty</th><th>Unit</th><th class="num">Unit cost</th><th class="num">Amount</th><th></th></tr></thead>
      <tbody><?php foreach ($lines as $i => $l) { echo $row($l, 'e' . $i); } ?></tbody>
      <tfoot><tr><td colspan="6" class="text-end fw-bold">Budget total</td><td class="num fw-bold" data-cs-sum="amount">0.00</td><td></td></tr></tfoot></table></div>
      <template data-cs-row="budGrid"><?= $row([], '__i__') ?></template>
      <div class="card-body pt-2"><button type="button" class="btn btn-outline-secondary btn-sm" data-cs-add="budGrid"><i class="bi bi-plus"></i> Add a line</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-cs-add="budGrid" data-count="5">Add 5 lines</button></div></div>
    <div class="sticky-actions"><?= save_buttons('Save budget', url($pageKey, $b ? ['a' => 'view', 'id' => $id] : ['project' => $pid]), false) ?></div>
    </form>
    <?php
    layout_end();
    return;
}

if ($a === 'view' && $b) {
    $pid = (int)$b['project_id'];
    $bs = CsCost::budgets($pid);
    $mine = CsCost::budgetByCode($id);
    $base = CsCost::budgetByCode($bs['baseline']['id'] ?? null);
    $act = CsCost::costByCode($pid);
    $ids = array_unique(array_merge(array_keys($mine), array_keys($base)));
    usort($ids, fn($x, $y) => strcmp(CsCost::codeLabel($x), CsCost::codeLabel($y)));
    $rows = [];
    $tot = [0.0, 0.0, 0.0];
    foreach ($ids as $cc) {
        $o = array_sum($base[$cc] ?? []);
        $m = array_sum($mine[$cc] ?? []);
        $x = array_sum(array_intersect_key($act[$cc] ?? [], array_flip(CsCost::DIRECT))) + (float)($act[$cc]['overhead'] ?? 0);
        $tot[0] += $o;
        $tot[1] += $m;
        $tot[2] += $x;
        $rows[] = [h(CsCost::codeLabel($cc)), h(implode(', ', array_map(fn($t) => CsCost::TYPES[$t] ?? $t, array_keys($mine[$cc] ?? [])))), money($o), money($m),
            '<span class="' . (abs($m - $o) > 0.5 ? ($m > $o ? 'cs-warn' : 'cs-good') : '') . '">' . money($m - $o) . '</span>', money($x), $m > 0 ? CsUi::meter($x / $m * 100) : ''];
    }
    $actions = (in_array($b['status'], ['draft', 'rejected'], true) ? cs_link($pageKey, ['a' => 'edit', 'id' => $id], 'Edit', 'btn-outline-secondary', 'pencil', 'edit') : '')
        . ($b['status'] === 'approved' && Access::can($pageKey, 'create') ? ' <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#revModal"><i class="bi bi-arrow-repeat"></i> Revise</button>' : '');
    layout_start('Budget v' . (int)$b['version_no'] . ': ' . $b['name'], ['module' => 'plan', 'actions' => $actions,
        'sub' => CsUi::projectLink($pid) . ', ' . h(CsUi::label($b['kind'])) . ((int)$b['is_baseline'] ? ', baseline' : '') . ' ' . CsUi::state($b['status'])]);
    echo '<div class="cs-kpis">' . CsUi::kpi('This budget', CsUi::amt($b['total_amount']), 'Effective ' . fdate($b['effective_date']), 'key')
        . CsUi::kpi('Original baseline', CsUi::amt($bs['baseline']['total_amount'] ?? 0), $bs['baseline'] ? 'v' . (int)$bs['baseline']['version_no'] : 'None approved yet')
        . CsUi::kpi('Change from the baseline', CsUi::amt((float)$b['total_amount'] - (float)($bs['baseline']['total_amount'] ?? 0)), '')
        . CsUi::kpi('Actual cost to date', CsUi::amt($tot[2]), '') . '</div>';
    if ($b['reason']) {
        echo '<div class="alert alert-info">' . nl2br(h($b['reason'])) . '</div>';
    }
    echo '<div class="cs-split"><div>' . cs_table_card('Lines by cost code', ['Cost code' => '', 'Types' => '', 'Original' => 'num', 'This budget' => 'num', 'Change' => 'num', 'Actual' => 'num', 'Spent' => ''], $rows, 'No lines.', '',
        '<tfoot><tr><td colspan="2">Total</td><td class="num">' . money($tot[0]) . '</td><td class="num">' . money($tot[1]) . '</td><td class="num">' . money($tot[1] - $tot[0]) . '</td><td class="num">' . money($tot[2]) . '</td><td></td></tr></tfoot>') . '</div>';
    echo '<div>' . CsApproval::panel('budget', $id, Access::can($pageKey, 'edit') && in_array($b['status'], ['draft', 'rejected'], true)) . '</div></div>';
    echo '<div class="modal fade" id="revModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post">' . csrf_field() . '<input type="hidden" name="act" value="revise"><input type="hidden" name="project_id" value="' . $pid . '">'
        . '<div class="modal-header"><h5 class="modal-title">Revise the budget</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>'
        . '<div class="modal-body"><p>A draft copy of the budget in force is made for you to change. It takes effect only once approved; the baseline is kept.</p><label class="form-label">Reason</label><textarea class="form-control" name="reason" rows="2" required></textarea></div>'
        . '<div class="modal-footer"><button class="btn btn-primary">Make the revision</button></div></form></div></div>';
    layout_end();
    return;
}

$pid = CsProject::current();
layout_start('Project Budgets', ['actions' => cs_link($pageKey, ['a' => 'new', 'project' => $pid], 'New budget', 'btn-primary', 'plus-lg', 'create')]);
echo cs_filter_bar($pageKey, $pid);
[$f, $params] = CsProject::filter('b.project_id');
$w = '';
if ($pid) {
    $w = ' AND b.project_id = :pid';
    $params['pid'] = $pid;
}
$rows = DB::all("SELECT b.*, p.code, p.name AS pname FROM cs_budgets b JOIN cs_projects p ON p.id = b.project_id WHERE 1 = 1 $f $w ORDER BY p.code, b.version_no DESC LIMIT 400", $params);
$out = [];
foreach ($rows as $r) {
    $out[] = ['<a class="cs-main-link" href="' . h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) . '">v' . (int)$r['version_no'] . ' ' . h($r['name']) . '</a>' . ((int)$r['is_baseline'] ? '<span class="cs-sub">Baseline</span>' : ''),
        h($r['code'] . ' ' . $r['pname']), h(CsUi::label($r['kind'])), h(fdate($r['effective_date'])), money($r['total_amount']), CsUi::state($r['status'])];
}
echo cs_table_card(count($rows) . ' budget versions', ['Budget' => '', 'Project' => '', 'Kind' => '', 'Effective' => '', 'Total' => 'num', 'Status' => ''], $out, 'No budgets yet.');
layout_end();
