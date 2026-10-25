<?php
declare(strict_types=1);

/** Cash advances to workers: request and approval, release in cash (cash voucher), and deduction in the payroll. */
$pageKey = 'labor.advances';
$a = (string)g('a', 'list');
$id = gint('id');
$ca = $id ? DB::row('SELECT a.*, w.full_name, w.worker_no FROM cs_cash_advances a JOIN cs_workers w ON w.id = a.worker_id WHERE a.id = :id', ['id' => $id]) : null;
if ($ca && $ca['project_id']) {
    CsProject::requireAccess((int)$ca['project_id']);
}

handle_post(function () use ($pageKey, $id, $ca) {
    if ($ca && CsApproval::handlePost('cash_advance', (int)$id, function () use ($ca) {
        if ($ca['status'] !== 'for_approval' || CsApproval::pendingFor('cash_advance', (int)$ca['id'])) {
            fail('This advance is not waiting to be submitted.');
        }
        CsApproval::submit('cash_advance', (int)$ca['id'], $ca['ca_no'] . ' ' . $ca['full_name'], $ca['project_id'] ? (int)$ca['project_id'] : null, (float)$ca['amount']);
    })) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    $act = (string)p('act');
    if ($act === 'request') {
        Access::require($pageKey, 'create');
        $nid = CsLabor::requestAdvance($_POST);
        flash('success', 'Advance requested and sent for approval.');
        cs_go($pageKey, ['a' => 'view', 'id' => $nid]);
    }
    if ($ca && $act === 'release') {
        Access::require($pageKey, 'edit');
        CsLabor::releaseAdvance((int)$id, (int)p('cash_account_id'), pdate('release_date'));
        flash('success', 'Released. A cash voucher was recorded; it is deducted in the next payroll run.');
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
});

if ($a === 'new') {
    Access::require($pageKey, 'create');
    $workers = DB::pairs("SELECT id, CONCAT(full_name, ' (', worker_no, ')') FROM cs_workers WHERE status = 'active' AND vendor_id IS NULL ORDER BY full_name");
    $wid = gint('worker');
    $wk = $wid ? DB::row('SELECT * FROM cs_workers WHERE id = :id', ['id' => $wid]) : null;
    layout_start('New cash advance', ['module' => 'labor']);
    echo '<form method="post" class="card"><div class="card-body"><div class="row g-3">' . csrf_field() . '<input type="hidden" name="act" value="request">'
        . CsUi::field('Worker', CsUi::select('worker_id', $workers, $wid ?? '', 'Choose', 'data-combo required'), 'col-md-5')
        . CsUi::field('Project', CsUi::projectSelect('project_id', $wk['assigned_project_id'] ?? CsProject::current() ?? '', ['blank' => 'None']), 'col-md-4')
        . CsUi::field('Date', CsUi::input('advance_date', today(), ['type' => 'date']), 'col-md-3')
        . CsUi::field('Amount', CsUi::input('amount', '', ['num' => true, 'required' => true]), 'col-md-3') . CsUi::field('Purpose', CsUi::input('purpose', '', ['max' => 255]), 'col-md-9')
        . '</div></div><div class="card-footer">' . save_buttons('Request advance', url($pageKey), false) . '</div></form>';
    layout_end();
    return;
}

if ($a === 'view' && $ca) {
    layout_start($ca['ca_no'] . ' Cash advance', ['module' => 'labor', 'sub' => '<a href="' . h(url('labor.workers', ['a' => 'view', 'id' => $ca['worker_id']])) . '">' . h($ca['full_name']) . '</a>' . ($ca['project_id'] ? ', ' . CsUi::projectLink((int)$ca['project_id']) : '') . ' ' . CsUi::state($ca['status'])]);
    echo '<div class="cs-kpis">' . CsUi::kpi('Amount', CsUi::amt($ca['amount']), h(fdate($ca['advance_date'])), 'key') . CsUi::kpi('Deducted so far', CsUi::amt($ca['deducted']), $ca['payroll_run_id'] ? 'Last in ' . h((string)DB::value('SELECT run_no FROM cs_payroll_runs WHERE id = :r', ['r' => $ca['payroll_run_id']])) : '')
        . CsUi::kpi('Still owed', CsUi::amt((float)$ca['amount'] - (float)$ca['deducted']), '') . '</div>';
    echo '<div class="cs-split"><div class="cs-stack"><div class="card"><div class="card-body">' . CsUi::facts(['Purpose' => h((string)$ca['purpose']), 'Released by' => $ca['txn_id'] ? CsUi::txnLink((int)$ca['txn_id']) : 'Not released']) . '</div></div></div><div class="cs-stack">';
    echo CsApproval::panel('cash_advance', $id, false);
    if ($ca['status'] === 'approved' && Access::can($pageKey, 'edit')) {
        $cash = DB::pairs("SELECT id, CONCAT(code, ' ', name) FROM accounts WHERE account_type = 'Bank' AND is_active = 1 ORDER BY code");
        echo '<form method="post" class="card"><div class="card-header">Release</div><div class="card-body"><div class="row g-2">' . csrf_field() . '<input type="hidden" name="act" value="release">'
            . CsUi::field('Paid from', CsUi::select('cash_account_id', $cash, '', 'Choose', 'data-combo required'), 'col-12') . CsUi::field('Date', CsUi::input('release_date', today(), ['type' => 'date']), 'col-12')
            . '</div></div><div class="card-footer"><button class="btn btn-primary btn-sm" data-confirm="Release the advance in cash? A cash voucher is recorded.">Release</button></div></form>';
    }
    echo cs_history('cash_advance', $id) . '</div></div>';
    layout_end();
    return;
}

$st = (string)g('status');
layout_start('Cash Advances', ['actions' => cs_link($pageKey, ['a' => 'new'], 'New advance', 'btn-primary', 'plus-lg', 'create')]);
echo '<form class="cs-toolbar" method="get"><input type="hidden" name="p" value="' . h($pageKey) . '">' . cs_search_field('Worker, CA no.') . cs_status_field(['for_approval' => 'For approval', 'approved' => 'Approved', 'released' => 'Released', 'deducted' => 'Deducted', 'rejected' => 'Rejected'])
    . '<div><button class="btn btn-outline-secondary btn-sm">Show</button></div></form>';
[$w, $params] = cs_like((string)g('q'), ['w.full_name', 'a.ca_no']);
if ($st !== '') {
    $w .= ' AND a.status = :st';
    $params['st'] = $st;
}
$acc = CsProject::accessibleIds();
if ($acc !== null) {
    [$in, $pp] = DB::in('caacc', $acc ?: [0]);
    $w .= " AND (a.project_id IS NULL OR a.project_id IN $in)";
    $params += $pp;
}
$rows = DB::all("SELECT a.*, w.full_name, p.code AS pcode FROM cs_cash_advances a JOIN cs_workers w ON w.id = a.worker_id LEFT JOIN cs_projects p ON p.id = a.project_id WHERE 1 = 1 $w ORDER BY a.id DESC LIMIT 400", $params);
$out = [];
$owed = 0.0;
foreach ($rows as $r) {
    if ($r['status'] === 'released') {
        $owed += (float)$r['amount'] - (float)$r['deducted'];
    }
    $out[] = ['<a class="cs-main-link" href="' . h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) . '">' . h($r['ca_no']) . '</a>', h($r['full_name']), h((string)$r['pcode']), h(fdate($r['advance_date'])),
        money($r['amount']), money($r['deducted']), h((string)$r['purpose']), CsUi::state($r['status'])];
}
echo '<div class="cs-kpis">' . CsUi::kpi('Released, not yet deducted', CsUi::amt($owed), 'Deducted in the next payroll runs', $owed > 0 ? 'warn' : '') . '</div>';
echo cs_table_card(count($rows) . ' advances', ['CA no.' => '', 'Worker' => '', 'Project' => '', 'Date' => '', 'Amount' => 'num', 'Deducted' => 'num', 'Purpose' => '', 'Status' => ''], $out, 'No cash advances.');
layout_end();
