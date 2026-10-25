<?php
declare(strict_types=1);

/**
 * The client portal: the client's projects with their progress, milestones, billings to certify, change orders to
 * approve, invoices and payments, approved drawings and reports, and site photos. Nothing internal (costs, margins,
 * budgets) is shown.
 */
$pageKey = 'portal.client';
$u = DB::row('SELECT * FROM users WHERE id = :id', ['id' => Auth::id()]);
$custId = (int)($u['portal_customer_id'] ?? 0);
if (!$custId && (Access::isAdmin() || Access::can($pageKey, 'view'))) {
    // staff previewing the portal choose a client
    $custId = (int)(gint('customer') ?? 0);
}
$cust = $custId ? DB::row('SELECT * FROM customers WHERE id = :id', ['id' => $custId]) : null;
$projects = $custId ? DB::all("SELECT * FROM cs_projects WHERE customer_id = :c AND status <> 'planning' ORDER BY status = 'closed', code", ['c' => $custId]) : [];
$pids = array_map('intval', array_column($projects, 'id'));
$pid = gint('project') && in_array((int)gint('project'), $pids, true) ? (int)gint('project') : ($pids[0] ?? null);

handle_post(function () use ($pageKey, $custId, $pids, $u) {
    if (!$custId) {
        fail('This account is not linked to a client.');
    }
    $act = (string)p('act');
    $who = 'Client portal, ' . ($u['full_name'] ?? '');
    if ($act === 'co_approve' || $act === 'co_reject') {
        $co = CsContracts::getCo((int)p('co_id'));
        if (!$co || !in_array((int)$co['project_id'], $pids, true) || $co['status'] !== 'internally_approved') {
            fail('This change order is not waiting for your approval.');
        }
        if ($act === 'co_approve') {
            CsContracts::clientApprove((int)$co['id'], today(), mb_substr($who, 0, 60));
            flash('success', 'Change order ' . $co['co_no'] . ' approved. Thank you.');
        } else {
            CsContracts::coStatus((int)$co['id'], 'rejected', 'Rejected through the client portal: ' . p('reason'));
            flash('success', 'Change order ' . $co['co_no'] . ' declined.');
        }
        $p = CsProject::get((int)$co['project_id']);
        CsNotify::send(array_filter([(int)$p['pm_user_id']]), 'change_order', 'Client ' . ($act === 'co_approve' ? 'approved' : 'declined') . ' ' . $co['co_no'], (string)p('reason'), url('proj.change_orders', ['a' => 'view', 'id' => $co['id']]), (int)$p['id']);
    } elseif ($act === 'certify') {
        $b = CsBilling::get((int)p('billing_id'));
        if (!$b || !in_array((int)$b['project_id'], $pids, true) || $b['status'] !== 'submitted') {
            fail('This billing is not waiting for your certification.');
        }
        CsBilling::certify((int)$b['id'], today(), mb_substr('Portal ' . trim((string)p('cert_ref')), 0, 60), null, false);
        $p = CsProject::get((int)$b['project_id']);
        $billers = array_map('intval', DB::column("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.name IN ('Billing Officer','Chief Accountant / Controller') AND u.status = 'active'"));
        CsNotify::send(array_merge($billers, array_filter([(int)$p['pm_user_id']])), 'billing', 'Client certified ' . $b['billing_no'], 'Make the invoice.', url('pbill.billings', ['a' => 'view', 'id' => $b['id']]), (int)$p['id']);
        flash('success', 'Billing ' . $b['billing_no'] . ' certified. Our billing team will send the invoice.');
    } elseif ($act === 'note') {
        $msg = trim((string)p('message'));
        $proj = (int)p('project_id');
        if ($msg === '' || !in_array($proj, $pids, true)) {
            fail('Write your message.');
        }
        DB::insert('cs_project_logs', ['project_id' => $proj, 'kind' => 'correspondence', 'ref_no' => Seq::next('cs_log'), 'log_date' => today(), 'title' => mb_substr('From the client: ' . $msg, 0, 200),
            'details' => $msg, 'party' => $who, 'status' => 'open', 'created_by' => Auth::id()]);
        $p = CsProject::get($proj);
        CsNotify::send(array_filter([(int)$p['pm_user_id']]), 'message', 'Message from the client on ' . $p['code'], mb_substr($msg, 0, 300), url('proj.logs', ['project' => $proj, 'kind' => 'correspondence']), $proj);
        flash('success', 'Message sent to the project manager.');
    }
    cs_go($pageKey, ['project' => p('project_id') ?: null]);
});

layout_start('Client Portal', ['sub' => $cust ? h($cust['name']) : 'Choose a client to preview the portal']);
if (!$custId) {
    if (Access::isAdmin() || Access::can($pageKey, 'view')) {
        echo '<form class="cs-toolbar" method="get"><input type="hidden" name="p" value="' . h($pageKey) . '"><div class="cs-grow" style="max-width:360px"><label class="form-label">Client</label>'
            . CsUi::select('customer', DB::pairs('SELECT DISTINCT c.id, c.name FROM customers c JOIN cs_projects p ON p.customer_id = c.id ORDER BY c.name'), '', 'Choose', 'data-combo onchange="this.form.submit()"') . '</div></form>';
    }
    echo '<div class="card">' . CsUi::empty('This account is not linked to a client. Ask the company to link it (Users: portal client).', '', 'person-badge') . '</div>';
    layout_end();
    return;
}
if (!$projects) {
    echo '<div class="card">' . CsUi::empty('You have no project with us yet.', '', 'building') . '</div>';
    layout_end();
    return;
}
if (count($projects) > 1) {
    echo '<div class="d-flex flex-wrap gap-1 mb-3">';
    foreach ($projects as $p) {
        echo '<a class="btn btn-sm ' . ((int)$p['id'] === $pid ? 'btn-primary' : 'btn-outline-secondary') . '" href="' . h(url($pageKey, ['project' => $p['id']] + ($u['portal_customer_id'] ? [] : ['customer' => $custId]))) . '">' . h($p['code'] . ' ' . $p['name']) . '</a>';
    }
    echo '</div>';
}
$p = CsProject::get($pid);
$s = CsSummary::project($pid);
echo '<div class="cs-proj-head"><div><h2 class="h4 mb-1">' . h($p['name']) . '</h2><div class="cs-note">' . h(CsUi::sub([$p['code'], $p['location'], 'target completion ' . fdate($p['revised_end_date'] ?: $p['target_end_date'])])) . ' ' . CsUi::state($p['status']) . '</div></div></div>';
echo '<div class="cs-kpis">' . CsUi::kpi('Work done', CsUi::pct($s['physical_pct']), $s['planned_pct'] !== null ? 'Planned by now ' . CsUi::pct($s['planned_pct']) : '', 'key')
    . CsUi::kpi('Contract value', CsUi::amt($s['contract_current']), (float)$s['variations'] ? 'Including ' . money($s['variations']) . ' approved changes' : '')
    . CsUi::kpi('Billed', CsUi::amt($s['certified_gross']), $s['contract_current'] > 0 ? CsUi::pct($s['certified_gross'] / $s['contract_current'] * 100) . ' of the contract' : '')
    . CsUi::kpi('Paid', CsUi::amt($s['collected']), 'Open ' . money($s['ar_open']), $s['ar_overdue'] > 0.5 ? 'warn' : '') . CsUi::kpi('Retention held', CsUi::amt($s['retention_receivable']), 'Released after acceptance') . '</div>';
echo CsUi::meter($s['physical_pct'], $s['planned_pct'], null, 'Physical progress ' . CsUi::pct($s['physical_pct'])) . '<div class="mb-3"></div>';
echo '<div class="cs-split"><div class="cs-stack">';
// billings to certify and invoices
$rows = [];
foreach (DB::all("SELECT * FROM cs_billings WHERE project_id = :p AND status IN ('submitted','certified','invoiced') ORDER BY billing_date DESC", ['p' => $pid]) as $b) {
    $inv = $b['invoice_txn_id'] ? Txn::load((int)$b['invoice_txn_id']) : null;
    $open = $inv ? Balances::open($inv) : null;
    $form = $b['status'] === 'submitted' ? '<form method="post" class="d-flex gap-1">' . csrf_field() . '<input type="hidden" name="act" value="certify"><input type="hidden" name="billing_id" value="' . (int)$b['id'] . '"><input type="hidden" name="project_id" value="' . $pid . '">'
        . '<input class="form-control form-control-sm" name="cert_ref" placeholder="Your reference" style="max-width:130px"><button class="btn btn-primary btn-sm" data-confirm="Certify this billing as correct?">Certify</button></form>' : '';
    $rows[] = [h($b['billing_no']) . '<span class="cs-sub">' . h(CsBilling::shortType($b['billing_type'])) . '</span>', h(fdate($b['billing_date'])), money((float)$b['this_gross'] + (float)$b['materials_stored']), money($b['certified_amount'] ?? $b['amount_applied']),
        $inv ? h($inv['txn_no']) . '<span class="cs-sub">Due ' . h(fdate($inv['due_date'])) . '</span>' : '', $open === null ? '' : ($open > 0.004 ? '<span class="' . ($inv['due_date'] < today() ? 'cs-bad' : 'cs-warn') . '">' . money($open) . ' open</span>' : '<span class="cs-good">Paid</span>'),
        $form ?: CsUi::state($b['status'], CsBilling::STATUSES[$b['status']] ?? null)];
}
echo cs_table_card('Billings and invoices', ['Billing' => '', 'Date' => '', 'Accomplishment' => 'num', 'Amount due' => 'num', 'Invoice' => '', 'Payment' => '', '' => ''], $rows, 'No billing sent yet.');
// change orders
$rows = [];
foreach (DB::all("SELECT * FROM cs_change_orders WHERE project_id = :p AND status IN ('internally_approved','client_approved','disputed') ORDER BY id DESC", ['p' => $pid]) as $co) {
    $form = $co['status'] === 'internally_approved' ? '<form method="post" class="d-flex flex-wrap gap-1">' . csrf_field() . '<input type="hidden" name="co_id" value="' . (int)$co['id'] . '"><input type="hidden" name="project_id" value="' . $pid . '">'
        . '<input class="form-control form-control-sm" name="reason" placeholder="Comment" style="max-width:140px"><button class="btn btn-success btn-sm" name="act" value="co_approve" data-confirm="Approve this change to the contract?">Approve</button>'
        . '<button class="btn btn-outline-danger btn-sm" name="act" value="co_reject" data-confirm="Decline this change order?">Decline</button></form>' : CsUi::state($co['status']);
    $rows[] = [h($co['co_no']) . '<span class="cs-sub">' . h($co['title']) . '</span>', h(CsContracts::CO_TYPES[$co['co_type']] ?? ''), money($co['price_impact']), (int)$co['schedule_impact_days'] . ' days', $form];
}
echo cs_table_card('Change orders', ['Change order' => '', 'Type' => '', 'Price' => 'num', 'Time' => 'num', '' => ''], $rows, 'No change order.');
// milestones
$rows = [];
foreach (DB::all('SELECT * FROM cs_milestones WHERE project_id = :p ORDER BY sort_order', ['p' => $pid]) as $m) {
    $rows[] = [h($m['code'] . ' ' . $m['name']), h(fdate($m['scheduled_date'])), CsUi::meter((float)$m['completion_pct']), CsUi::state($m['status']) . ($m['client_certified_date'] ? '<span class="cs-sub">Certified ' . h(fdate($m['client_certified_date'])) . '</span>' : '')];
}
echo cs_table_card('Milestones', ['Milestone' => '', 'Scheduled' => '', 'Completion' => '', 'Status' => ''], $rows, 'No milestones.');
echo '</div><div class="cs-stack">';
// recent site photos and reports
$photos = DB::all("SELECT a.* FROM cs_attachments a JOIN cs_daily_reports r ON r.id = a.entity_id AND a.entity_type = 'daily_report' WHERE r.project_id = :p AND r.status = 'approved' AND a.mime LIKE 'image/%' ORDER BY a.id DESC LIMIT 12", ['p' => $pid]);
echo '<div class="card"><div class="card-header">Site photos</div><div class="card-body">';
if ($photos) {
    echo '<div class="d-flex flex-wrap gap-2">';
    foreach ($photos as $f) {
        echo '<a href="' . h(CsFiles::url((int)$f['id'])) . '" target="_blank" rel="noopener"><img src="' . h(CsFiles::url((int)$f['id'])) . '" alt="' . h($f['caption'] ?: 'Site photo') . '" style="width:96px;height:72px;object-fit:cover;border-radius:6px"></a>';
    }
    echo '</div>';
} else {
    echo '<p class="cs-note mb-0">No photos yet.</p>';
}
echo '</div></div>';
$rows = [];
foreach (DB::all("SELECT * FROM cs_daily_reports WHERE project_id = :p AND status = 'approved' ORDER BY report_date DESC LIMIT 7", ['p' => $pid]) as $r) {
    $rows[] = [h(date('D, M j', strtotime($r['report_date']))), (int)$r['workers_count'], h(CsSite::WEATHER[$r['weather_am']] ?? ''), h(mb_strimwidth((string)($r['remarks'] ?: $r['issues']), 0, 80, '...'))];
}
echo cs_table_card('Recent site days', ['Date' => '', 'Workers' => 'num', 'Weather' => '', 'Notes' => ''], $rows, 'No approved report yet.');
$rows = [];
foreach (DB::all("SELECT d.*, r.id AS rid, r.rev_no FROM cs_documents d JOIN cs_document_revisions r ON r.document_id = d.id AND r.status = 'approved' WHERE d.project_id = :p AND d.status = 'active' AND d.category IN ('drawing','as_built','report','permit','manual','specification') ORDER BY d.category, d.doc_no LIMIT 30", ['p' => $pid]) as $d) {
    $rows[] = ['<a href="' . h(url('file.cs', ['rev' => $d['rid'], 'inline' => 1])) . '" target="_blank" rel="noopener">' . h($d['doc_no']) . '</a><span class="cs-sub">' . h($d['title']) . '</span>', 'Rev. ' . h($d['rev_no'])];
}
echo cs_table_card('Approved documents', ['Document' => '', 'Revision' => ''], $rows, 'No documents shared.');
echo '<form method="post" class="card"><div class="card-header">Message the project manager</div><div class="card-body">' . csrf_field() . '<input type="hidden" name="act" value="note"><input type="hidden" name="project_id" value="' . $pid . '">'
    . '<textarea class="form-control form-control-sm" name="message" rows="3" required></textarea></div><div class="card-footer"><button class="btn btn-primary btn-sm">Send</button></div></form>';
echo '</div></div>';
layout_end();
