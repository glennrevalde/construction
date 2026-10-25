<?php
declare(strict_types=1);

/** Shared cost allocations: what is waiting to be spread, the allocation itself with its approval, reversal and reallocation. */
$pageKey = 'cost.allocations';
$a = (string)g('a', 'list');
$id = gint('id');
$al = $id ? CsAlloc::get($id) : null;

handle_post(function () use ($pageKey, $id, $al) {
    if ($al && CsApproval::handlePost('allocation', (int)$id, function () use ($al) {
        if (!in_array($al['status'], ['draft', 'rejected'], true)) {
            fail('Only a draft allocation is submitted.');
        }
        CsApproval::submit('allocation', (int)$al['id'], $al['alloc_no'] . ' ' . $al['description'], null, (float)$al['amount']);
    })) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    $act = (string)p('act');
    if ($al && $act === 'reverse') {
        Access::require($pageKey, 'delete');
        CsAlloc::reverse((int)$id, (string)p('reason'));
        flash('success', 'Allocation reversed: its journal was voided with your reason; the cost is back where it was.');
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if ($al && $act === 'reallocate') {
        Access::require($pageKey, 'create');
        if ($al['status'] !== 'posted') {
            fail('Only a posted allocation is reallocated.');
        }
        $lines = DB::column('SELECT project_id FROM cs_allocation_lines WHERE allocation_id = :a', ['a' => $id]);
        $data = $al;
        $data['replaces_id'] = $id;
        $data['method'] = (string)p('method') ?: $al['method'];
        $data['description'] = 'Reallocation of ' . $al['alloc_no'] . ': ' . $al['description'];
        // the cost is allocated again from the same source: the old allocation still holds it until the new one posts
        $data['source_txn_id'] = null;
        $nid = CsAlloc::save($data, array_merge($lines, (array)($_POST['projects'] ?? [])));
        DB::update('cs_allocations', ['source_txn_id' => $al['source_txn_id']], 'id = :id', ['id' => $nid]);
        flash('success', 'Reallocation drafted. When it is approved and posted, ' . $al['alloc_no'] . ' is reversed by itself.');
        cs_go($pageKey, ['a' => 'view', 'id' => $nid]);
    }
    if ($act === 'save') {
        Access::require($pageKey, $id ? 'edit' : 'create');
        $nid = CsAlloc::save($_POST, (array)($_POST['projects'] ?? []), $id);
        flash('success', 'Allocation saved. Check the shares, then submit it for approval.');
        cs_go($pageKey, ['a' => 'view', 'id' => $nid]);
    }
});

if ($a === 'new' || ($a === 'edit' && $al)) {
    Access::require($pageKey, $al ? 'edit' : 'create');
    $src = gint('txn');
    $acct = gint('account');
    $c = $al ?: ['alloc_date' => today(), 'source_txn_id' => $src, 'source_account_id' => $acct, 'method' => 'direct_cost', 'period_from' => date('Y-m-01', strtotime('first day of last month')),
        'period_to' => date('Y-m-t', strtotime('last day of last month')), 'amount' => $src && $acct ? CsAlloc::remaining($src, $acct, null) : ''];
    if ($src && !$al) {
        $t = Txn::load($src);
        $c['description'] = $t ? Txn::label($t['txn_type']) . ' ' . $t['txn_no'] . ($t['memo'] ? ': ' . $t['memo'] : '') : '';
        $c['alloc_date'] = $t['txn_date'] ?? today();
    }
    $sel = $al ? array_map('intval', DB::column('SELECT project_id FROM cs_allocation_lines WHERE allocation_id = :a', ['a' => $id])) : [];
    $accounts = DB::pairs("SELECT id, CONCAT(code, ' ', name) FROM accounts WHERE category = 'Expense' AND is_active = 1 ORDER BY code");
    $rules = DB::pairs("SELECT id, CONCAT(name, ' (', method, ')') FROM cs_alloc_rules WHERE is_active = 1 ORDER BY name");
    $projects = DB::all("SELECT id, code, name, status FROM cs_projects WHERE status NOT IN ('closed') ORDER BY code");
    layout_start($al ? 'Edit ' . $al['alloc_no'] : 'Allocate a shared cost', ['module' => 'cost']);
    echo '<form method="post"><div class="card mb-3"><div class="card-body"><div class="row g-3">' . csrf_field() . '<input type="hidden" name="act" value="save"><input type="hidden" name="source_txn_id" value="' . h((string)($c['source_txn_id'] ?? '')) . '">'
        . CsUi::field('Source', $c['source_txn_id'] ? CsUi::txnLink((int)$c['source_txn_id']) : '<div class="pt-1 text-muted">Not tied to one transaction</div>', 'col-md-3')
        . CsUi::field('Expense account', CsUi::select('source_account_id', $accounts, fv('source_account_id', $c), 'Choose', 'data-combo required'), 'col-md-5')
        . CsUi::field('Amount', CsUi::input('amount', fv('amount', $c), ['num' => true, 'required' => true]), 'col-6 col-md-2') . CsUi::field('Date', CsUi::input('alloc_date', fv('alloc_date', $c), ['type' => 'date']), 'col-6 col-md-2')
        . CsUi::field('Description', CsUi::input('description', fv('description', $c), ['max' => 255]), 'col-md-6') . CsUi::field('Cost code on the projects', CsUi::costCodeSelect('cost_code_id', fv('cost_code_id', $c)), 'col-md-6')
        . CsUi::field('Basis', CsUi::select('method', CsAlloc::METHODS, fv('method', $c), null, 'data-no-combo'), 'col-md-4') . CsUi::field('Rule (fixed or custom shares)', CsUi::select('rule_id', $rules, fv('rule_id', $c), 'None'), 'col-md-4')
        . CsUi::field('Basis measured from', CsUi::input('period_from', fv('period_from', $c), ['type' => 'date']), 'col-6 col-md-2') . CsUi::field('to', CsUi::input('period_to', fv('period_to', $c), ['type' => 'date']), 'col-6 col-md-2')
        . '</div></div></div><div class="card mb-3"><div class="card-header">Projects that share it (not needed with a rule)</div><div class="card-body"><div class="row g-1">';
    foreach ($projects as $p) {
        echo '<div class="col-md-4"><label class="form-check"><input class="form-check-input" type="checkbox" name="projects[]" value="' . (int)$p['id'] . '"' . (in_array((int)$p['id'], $sel, true) || (!$al && in_array($p['status'], ['active', 'mobilization'], true)) ? ' checked' : '') . '> <span class="form-check-label">'
            . h($p['code'] . ' ' . $p['name']) . '</span></label></div>';
    }
    echo '</div></div></div><div class="sticky-actions">' . save_buttons('Work out the shares', url($pageKey, $al ? ['a' => 'view', 'id' => $id] : []), false) . '</div></form>';
    layout_end();
    return;
}

if ($a === 'view' && $al) {
    $lines = DB::all('SELECT l.*, p.code, p.name FROM cs_allocation_lines l JOIN cs_projects p ON p.id = l.project_id WHERE l.allocation_id = :a ORDER BY l.amount DESC', ['a' => $id]);
    layout_start($al['alloc_no'] . ' ' . $al['description'], ['module' => 'cost', 'sub' => h($al['acct_code'] . ' ' . $al['acct_name'] . ', ' . (CsAlloc::METHODS[$al['method']] ?? '') . ', ' . fdate($al['alloc_date'])) . ' ' . CsUi::state($al['status']),
        'actions' => in_array($al['status'], ['draft', 'rejected'], true) ? cs_link($pageKey, ['a' => 'edit', 'id' => $id], 'Edit', 'btn-outline-secondary', 'pencil', 'edit') : '']);
    $rows = [];
    foreach ($lines as $l) {
        $rows[] = [CsUi::projectLink((int)$l['project_id']), number_format((float)$l['basis_value'], 2), CsUi::meter((float)$l['pct'], null, null, number_format((float)$l['pct'], 2) . '%'), money($l['amount'])];
    }
    echo '<div class="cs-split"><div class="cs-stack">' . cs_table_card('Shares', ['Project' => '', 'Basis' => 'num', 'Share' => '', 'Amount' => 'num'], $rows, '', '', '<tfoot><tr><td colspan="3">Total</td><td class="num">' . money($al['amount']) . '</td></tr></tfoot>')
        . '<div class="card"><div class="card-body">' . CsUi::facts(['Source' => $al['source_txn_id'] ? CsUi::txnLink((int)$al['source_txn_id']) : 'Not tied to one transaction',
            'Basis period' => $al['period_from'] ? h(fdate($al['period_from']) . ' to ' . fdate($al['period_to'])) : '', 'Cost code' => h(CsCost::codeLabel($al['cost_code_id'] ? (int)$al['cost_code_id'] : null)),
            'Reclassification journal' => $al['txn_id'] ? CsUi::txnLink((int)$al['txn_id']) : 'Posted when approved',
            'Replaces' => $al['replaces_id'] ? '<a href="' . h(url($pageKey, ['a' => 'view', 'id' => $al['replaces_id']])) . '">' . h((string)DB::value('SELECT alloc_no FROM cs_allocations WHERE id = :a', ['a' => $al['replaces_id']])) . '</a>' : '',
            'Reversed because' => h((string)$al['reversal_reason'])]) . '</div></div></div><div class="cs-stack">';
    echo CsApproval::panel('allocation', $id, Access::can($pageKey, 'edit') && in_array($al['status'], ['draft', 'rejected'], true));
    if ($al['status'] === 'posted') {
        echo '<div class="card"><div class="card-body d-flex flex-wrap gap-1">' . (Access::can($pageKey, 'delete') ? CsUi::confirmButton('alRev', 'Reverse', 'Reverse ' . $al['alloc_no'], 'The reclassification journal is voided (kept with your reason); the cost goes back to where it was recorded.', '<input type="hidden" name="act" value="reverse">', 'btn-outline-danger', 'arrow-counterclockwise', true) : '')
            . (Access::can($pageKey, 'create') ? '<form method="post" class="d-inline">' . csrf_field() . '<input type="hidden" name="act" value="reallocate"><button class="btn btn-outline-secondary btn-sm" data-confirm="Draft a reallocation of the same cost? The old one is reversed when the new one posts."><i class="bi bi-shuffle"></i> Reallocate</button></form>' : '')
            . '</div></div>';
    }
    echo cs_history('allocation', $id) . '</div></div>';
    layout_end();
    return;
}

layout_start('Shared Cost Allocation', ['actions' => cs_link($pageKey, ['a' => 'new'], 'New allocation', 'btn-primary', 'plus-lg', 'create') . ' ' . cs_link('cost.rules', [], 'Rules', 'btn-outline-secondary', 'sliders')]);
$wait = CsAlloc::waiting(null, null, 100);
$rows = [];
foreach ($wait as $w) {
    $rows[] = [Txn::link(['id' => $w['txn_id'], 'txn_type' => $w['txn_type'], 'txn_no' => $w['txn_no']], Txn::label($w['txn_type']) . ' ' . $w['txn_no']) . '<span class="cs-sub">' . h((string)$w['memo']) . '</span>', h(fdate($w['txn_date'])),
        h($w['code'] . ' ' . $w['name']), money($w['amt']), money($w['left']), cs_link($pageKey, ['a' => 'new', 'txn' => $w['txn_id'], 'account' => $w['account_id']], 'Allocate', 'btn-outline-primary', '', 'create')];
}
echo cs_table_card('Costs recorded without a project (last 6 months)', ['Source' => '', 'Date' => '', 'Account' => '', 'Amount' => 'num', 'Not yet allocated' => 'num', '' => ''], $rows, 'Every cost of the last 6 months is on a project or allocated.');
$st = (string)g('status');
$list = DB::all("SELECT a.*, ac.code, ac.name AS acct, (SELECT COUNT(*) FROM cs_allocation_lines l WHERE l.allocation_id = a.id) AS n FROM cs_allocations a JOIN accounts ac ON ac.id = a.source_account_id"
    . ($st !== '' ? ' WHERE a.status = :st' : '') . ' ORDER BY a.id DESC LIMIT 300', $st !== '' ? ['st' => $st] : []);
$rows = [];
foreach ($list as $l) {
    $rows[] = ['<a class="cs-main-link" href="' . h(url($pageKey, ['a' => 'view', 'id' => $l['id']])) . '">' . h($l['alloc_no']) . '</a><span class="cs-sub">' . h($l['description']) . '</span>', h($l['code'] . ' ' . $l['acct']),
        h(CsAlloc::METHODS[$l['method']] ?? ''), (int)$l['n'], h(fdate($l['alloc_date'])), money($l['amount']), CsUi::state($l['status'])];
}
echo '<div class="mb-3"></div><form class="cs-toolbar" method="get"><input type="hidden" name="p" value="' . h($pageKey) . '">' . cs_status_field(['draft' => 'Draft', 'for_approval' => 'For approval', 'posted' => 'Posted', 'reversed' => 'Reversed', 'rejected' => 'Rejected']) . '</form>'
    . cs_table_card('Allocations', ['Allocation' => '', 'Account' => '', 'Basis' => '', 'Projects' => 'num', 'Date' => '', 'Amount' => 'num', 'Status' => ''], $rows, 'No allocations yet.');
layout_end();
