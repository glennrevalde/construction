<?php
declare(strict_types=1);

/** Project manager dashboard: the user's own projects, what is happening on site, and what needs them today. */
$me = (int)Auth::id();
$mine = array_map('intval', DB::column("SELECT DISTINCT p.id FROM cs_projects p LEFT JOIN cs_project_members m ON m.project_id = p.id
                                         WHERE p.status <> 'closed' AND (p.pm_user_id = :u OR p.engineer_user_id = :u2 OR p.qs_user_id = :u3 OR m.user_id = :u4) ORDER BY p.id",
    ['u' => $me, 'u2' => $me, 'u3' => $me, 'u4' => $me]));
$scope = 'Your projects';
if (!$mine) {
    [$f, $fp] = CsProject::filter('p.id');
    $mine = array_map('intval', DB::column("SELECT p.id FROM cs_projects p WHERE p.status IN ('mobilization','active','on_hold','substantially_completed') $f ORDER BY p.code", $fp));
    $scope = 'Open projects';
}
$sum = CsSummary::portfolio($mine);
$today = today();
$week = date('Y-m-d', strtotime('monday this week'));
[$in, $inP] = DB::in('pm', $mine ?: [0]);
$att = DB::row("SELECT COUNT(DISTINCT CASE WHEN l.work_date = :t AND l.is_absent = 0 THEN l.worker_id END) AS today_n,
                       COALESCE(SUM(CASE WHEN l.work_date >= :w THEN l.regular_hours + l.ot_hours END),0) AS week_h,
                       COALESCE(SUM(CASE WHEN l.work_date >= :w2 AND l.is_absent = 1 THEN 1 END),0) AS absent
                  FROM cs_timesheet_lines l JOIN cs_timesheets t ON t.id = l.timesheet_id WHERE t.project_id IN $in AND t.status <> 'rejected'", $inP + ['t' => $today, 'w' => $week, 'w2' => $week]) ?: [];
$eq = DB::row("SELECT COALESCE(SUM(operating_hours),0) AS op, COALESCE(SUM(idle_hours + breakdown_hours),0) AS idle FROM cs_equipment_logs WHERE project_id IN $in AND log_date >= :w", $inP + ['w' => date('Y-m-d', strtotime('-30 days'))]) ?: [];
$eqUtil = ((float)$eq['op'] + (float)$eq['idle']) > 0 ? (float)$eq['op'] / ((float)$eq['op'] + (float)$eq['idle']) * 100 : 0;
$t = ['pending' => 0, 'cost' => 0.0, 'remaining' => 0.0, 'ready' => 0.0, 'mat' => 0.0];
foreach ($sum as $s) {
    $t['pending'] += $s['deliverables_pending'];
    $t['cost'] += $s['cost_direct'];
    $t['remaining'] += max(0, $s['budget_current'] - $s['cost_direct'] - $s['committed']['total']);
    $t['ready'] += max(0, $s['work_done_value'] - $s['billed_gross']);
    $t['mat'] += $s['mat_consumed'] + $s['mat_issued'] - $s['mat_returned'];
}
$title = !empty($GLOBALS['CS_HOME']) ? 'Home' : 'Project Manager Dashboard';
layout_start($title, ['module' => 'home', 'sub' => $scope . ': ' . count($sum) . ', as of ' . fdate($today),
    'actions' => cs_link('site.daily', ['a' => 'new'], 'Daily site report', 'btn-primary', 'clipboard2-check', 'create') . ' ' . cs_link('labor.timesheets', ['a' => 'new'], 'Timesheet', 'btn-outline-secondary', 'clock', 'create')]);
echo '<div class="cs-kpis">'
    . CsUi::kpi('My approvals', (string)CsApproval::countFor($me), 'Waiting for you', CsApproval::countFor($me) ? 'warn' : '', Access::page('appr.inbox') ? url('appr.inbox') : null)
    . CsUi::kpi('Pending deliverables', (string)$t['pending'], 'Required items not yet accepted', $t['pending'] ? 'warn' : '', Access::page('plan.milestones') ? url('plan.milestones') : null)
    . CsUi::kpi('Actual cost', CsUi::amt($t['cost'], true), 'Remaining budget ' . CsUi::amt($t['remaining'], true))
    . CsUi::kpi('Ready to bill', CsUi::amt($t['ready'], true), 'Completed work not billed yet', $t['ready'] > 0 ? 'key' : '', Access::page('pbill.billings') ? url('pbill.billings', ['a' => 'new']) : null)
    . CsUi::kpi('On site today', (string)(int)($att['today_n'] ?? 0), number_format((float)($att['week_h'] ?? 0), 1) . ' man-hours this week, ' . (int)($att['absent'] ?? 0) . ' absences')
    . CsUi::kpi('Equipment utilization', CsUi::pct($eqUtil), number_format((float)$eq['op'], 1) . ' operating hours, last 30 days')
    . CsUi::kpi('Materials used', CsUi::amt($t['mat'], true), 'Issued and consumed, to date')
    . '</div>';
echo '<div class="cs-split is-wide-side"><div class="cs-stack">';
$rows = [];
foreach ($sum as $pid => $s) {
    $p = $s['project'];
    $rows[] = ['<a class="cs-main-link" href="' . h(url('proj.view', ['id' => $pid])) . '">' . h($p['code'] . ' ' . $p['name']) . '</a><span class="cs-sub">' . CsUi::label($p['status']) . '</span>',
        CsUi::meter($s['physical_pct'], $s['planned_pct']), money($s['cost_direct']),
        '<span class="' . ($s['budget_current'] - $s['cost_direct'] - $s['committed']['total'] < 0 ? 'cs-bad' : '') . '">' . money($s['budget_current'] - $s['cost_direct'] - $s['committed']['total']) . '</span>',
        money(max(0, $s['work_done_value'] - $s['billed_gross'])), (string)$s['deliverables_pending']];
}
echo cs_table_card('Projects', ['Project' => '', 'Progress (tick = planned)' => '', 'Actual cost' => 'num', 'Budget left' => 'num', 'Ready to bill' => 'num', 'Open deliverables' => 'num'], $rows, 'You are not on any project yet.');
// site issues: DSR issues of the last week, open NCRs and incidents
$issues = DB::all("SELECT d.id, d.report_no, d.report_date, d.issues, d.delays, p.code FROM cs_daily_reports d JOIN cs_projects p ON p.id = d.project_id
                    WHERE d.project_id IN $in AND d.report_date >= :d AND (COALESCE(d.issues,'') <> '' OR COALESCE(d.delays,'') <> '') ORDER BY d.report_date DESC LIMIT 8", $inP + ['d' => date('Y-m-d', strtotime('-7 days'))]);
$rows = [];
foreach ($issues as $i) {
    $rows[] = ['<a href="' . h(url('site.daily', ['a' => 'view', 'id' => $i['id']])) . '">' . h($i['report_no']) . '</a><span class="cs-sub">' . h($i['code'] . ', ' . fdate($i['report_date'])) . '</span>',
        h(mb_strimwidth(trim($i['issues'] . ' ' . $i['delays']), 0, 140, '...'))];
}
echo cs_table_card('Site issues reported this week', ['Report' => '', 'Issue or delay' => ''], $rows, 'No issue reported in the last seven days.');
// materials requested and not yet delivered
$mr = DB::all("SELECT r.*, p.code FROM cs_material_requests r JOIN cs_projects p ON p.id = r.project_id WHERE r.project_id IN $in AND (r.status IN ('for_approval','approved','partially_ordered')
                        OR (r.status = 'ordered' AND EXISTS (SELECT 1 FROM cs_canvass c JOIN transactions t ON t.id = c.po_txn_id WHERE c.mr_id = r.id AND t.is_voided = 0 AND t.status NOT IN ('closed','void'))))
                  ORDER BY r.needed_date LIMIT 10", $inP);
$rows = [];
foreach ($mr as $r) {
    $rows[] = ['<a href="' . h(url('proc.requests', ['a' => 'view', 'id' => $r['id']])) . '">' . h($r['mr_no']) . '</a><span class="cs-sub">' . h($r['code']) . '</span>', h(fdate($r['needed_date'])), CsUi::state($r['status'])];
}
echo cs_table_card('Material requests in progress', ['Request' => '', 'Needed' => '', 'Status' => ''], $rows, 'No open material request.');
echo '</div><div class="cs-stack">';
$inbox = array_slice(CsApproval::inbox($me), 0, 8);
$rows = [];
foreach ($inbox as $r) {
    $rows[] = ['<a href="' . h(CsDocs::url($r['doc_type'], (int)$r['doc_id'])) . '">' . h(CsDocs::label($r['doc_type']) . ' ' . $r['doc_ref']) . '</a>', money($r['amount'])];
}
echo cs_table_card('Waiting for your approval', ['Document' => '', 'Amount' => 'num'], $rows, 'Nothing waits for you.');
$al = array_values(array_filter(CsAlerts::all(), fn($a) => $a['project_id'] === null || in_array((int)$a['project_id'], $mine, true)));
echo '<div class="card"><div class="card-header">Alerts <span class="cs-note">' . count($al) . '</span></div>' . ($al ? CsAlerts::render(array_slice($al, 0, 10)) : CsUi::empty('No alerts.', '', 'check2-circle')) . '</div>';
echo '</div></div>';
layout_end();
