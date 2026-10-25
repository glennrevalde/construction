<?php
declare(strict_types=1);

/** Executive dashboard: the whole portfolio, money in and out, risk, and the decisions waiting at the top. */
$asOf = today();
[$f, $fp] = CsProject::filter('p.id');
$open = array_map('intval', DB::column("SELECT p.id FROM cs_projects p WHERE p.status IN ('planning','mobilization','active','on_hold','substantially_completed','completed','under_warranty') $f ORDER BY p.code", $fp));
$sum = CsSummary::portfolio($open);
$active = array_filter($sum, fn($s) => $s['project']['status'] === 'active' || $s['project']['status'] === 'mobilization');
$t = array_fill_keys(['orig', 'current', 'eac', 'cost', 'gross', 'oper', 'cert', 'ar', 'overdue', 'collected'], 0.0);
$over = [];
$behind = [];
foreach ($sum as $pid => $s) {
    $t['orig'] += $s['contract_original'];
    $t['current'] += $s['contract_current'];
    $t['eac'] += $s['eac'];
    $t['cost'] += $s['cost_direct'];
    $t['gross'] += $s['contract_current'] - $s['eac'];
    $t['oper'] += $s['projected_profit'];
    $t['cert'] += $s['certified'];
    $t['ar'] += $s['ar_open'];
    $t['overdue'] += $s['ar_overdue'];
    $t['collected'] += $s['collected'];
    if ($s['over_budget'] || $s['projected_profit'] < 0) {
        $over[$pid] = $s;
    }
    if ($s['behind']) {
        $behind[$pid] = $s;
    }
}
// cash needed in the next 30 days: supplier invoices due, payroll accrued and not paid
$apDue = 0.0;
foreach (Balances::openPayables(null, date('Y-m-d', strtotime('+30 days'))) as $b) {
    $apDue += $b['open_balance'];
}
$payroll = -(float)DB::value('SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE account_id IN (:a, :b)', ['a' => Accts::get('cs_accrued_payroll'), 'b' => Accts::get('cs_wages_payable')]);
$collect30 = (float)DB::value("SELECT COALESCE(SUM(t.total),0) FROM transactions t WHERE t.txn_type = 'invoice' AND t.is_voided = 0 AND t.status IN ('open','partial') AND t.due_date <= :d", ['d' => date('Y-m-d', strtotime('+30 days'))]);
$monthCollected = (float)DB::value("SELECT COALESCE(SUM(total),0) FROM transactions WHERE txn_type = 'payment' AND is_voided = 0 AND txn_date >= :d", ['d' => date('Y-m-01')]);
// approvals of high value
$bigAppr = DB::all("SELECT * FROM cs_approval_requests WHERE status = 'pending' AND amount >= 1000000 ORDER BY amount DESC LIMIT 8");
// utilization in the last 30 days
$from30 = date('Y-m-d', strtotime('-30 days'));
$eqHours = (float)DB::value("SELECT COALESCE(SUM(operating_hours),0) FROM cs_equipment_logs WHERE log_date >= :d AND status IN ('approved','posted')", ['d' => $from30]);
$eqIdle = (float)DB::value("SELECT COALESCE(SUM(idle_hours + breakdown_hours),0) FROM cs_equipment_logs WHERE log_date >= :d AND status IN ('approved','posted')", ['d' => $from30]);
$eqUnits = (int)DB::value("SELECT COUNT(*) FROM cs_equipment WHERE status IN ('deployed','available','idle')");
$eqUtil = $eqHours + $eqIdle > 0 ? $eqHours / ($eqHours + $eqIdle) * 100 : 0;
$workers = (int)DB::value("SELECT COUNT(DISTINCT l.worker_id) FROM cs_timesheet_lines l JOIN cs_timesheets t ON t.id = l.timesheet_id WHERE l.work_date >= :d AND t.status <> 'rejected' AND l.is_absent = 0", ['d' => $from30]);
$manHours = (float)DB::value("SELECT COALESCE(SUM(l.regular_hours + l.ot_hours),0) FROM cs_timesheet_lines l JOIN cs_timesheets t ON t.id = l.timesheet_id WHERE l.work_date >= :d AND t.status <> 'rejected'", ['d' => $from30]);
$activeWorkers = (int)DB::value("SELECT COUNT(*) FROM cs_workers WHERE status = 'active'");

$title = !empty($GLOBALS['CS_HOME']) ? 'Home' : 'Executive Dashboard';
layout_start($title, ['module' => 'home', 'sub' => 'Executive view of ' . count($sum) . ' project(s), as of ' . fdate($asOf),
    'actions' => cs_link('home.reports', [], 'Report Center', 'btn-outline-secondary', 'bar-chart') . ' ' . cs_link('rpt.cs_portfolio', [], 'Portfolio report', 'btn-outline-secondary', 'table')]);
echo '<div class="cs-kpis">'
    . CsUi::kpi('Active projects', (string)count($active), count($sum) . ' open in all', 'key', url('proj.center'))
    . CsUi::kpi('Contract portfolio', CsUi::amt($t['current'], true), 'Original ' . CsUi::amt($t['orig'], true) . ', revised with variations', '', Access::page('rpt.cs_contract_summary') ? url('rpt.cs_contract_summary') : null)
    . CsUi::kpi('Estimated cost at completion', CsUi::amt($t['eac'], true), 'Incurred so far ' . CsUi::amt($t['cost'], true), '', Access::page('rpt.cs_eac') ? url('rpt.cs_eac') : null)
    . CsUi::kpi('Projected gross profit', CsUi::amt($t['gross'], true), 'Operating, after overhead: ' . CsUi::amt($t['oper'], true), $t['oper'] < 0 ? 'bad' : 'ok', Access::page('rpt.cs_forecast') ? url('rpt.cs_forecast') : null)
    . CsUi::kpi('Certified billings', CsUi::amt($t['cert'], true), 'Collected ' . CsUi::amt($t['collected'], true), '', Access::page('rpt.cs_billing') ? url('rpt.cs_billing') : null)
    . CsUi::kpi('Outstanding receivables', CsUi::amt($t['ar'], true), '<span class="' . ($t['overdue'] > 0 ? 'cs-bad' : '') . '">' . CsUi::amt($t['overdue'], true) . ' overdue</span>', $t['overdue'] > 0 ? 'warn' : '', Access::page('rpt.cs_ar_aging') ? url('rpt.cs_ar_aging') : null)
    . CsUi::kpi('Collections this month', CsUi::amt($monthCollected, true), CsUi::amt($collect30, true) . ' falls due in 30 days')
    . CsUi::kpi('Cash needed, next 30 days', CsUi::amt($apDue + max(0, $payroll), true), 'Suppliers ' . CsUi::amt($apDue, true) . ', payroll ' . CsUi::amt(max(0, $payroll), true), '', Access::page('vend.pay_bills') ? url('vend.pay_bills') : null)
    . CsUi::kpi('Projects over budget', (string)count($over), 'or heading for a loss', count($over) ? 'bad' : 'ok')
    . CsUi::kpi('Projects behind schedule', (string)count($behind), '', count($behind) ? 'warn' : 'ok')
    . CsUi::kpi('Equipment utilization', CsUi::pct($eqUtil), number_format($eqHours) . ' operating hours, ' . $eqUnits . ' units, last 30 days')
    . CsUi::kpi('Manpower', $workers . ' of ' . $activeWorkers, number_format($manHours) . ' man-hours in the last 30 days')
    . '</div>';

echo '<div class="cs-split is-wide-side"><div class="cs-stack">';
// the portfolio
$rows = [];
foreach ($sum as $pid => $s) {
    $p = $s['project'];
    $rows[] = ['<a class="cs-main-link" href="' . h(url('proj.view', ['id' => $pid])) . '">' . h($p['code'] . ' ' . $p['name']) . '</a><span class="cs-sub">' . h((string)$p['customer_name']) . '</span>',
        CsUi::meter($s['physical_pct'], $s['planned_pct']), CsUi::amt($s['contract_current'], true), '<span class="' . ($s['over_budget'] ? 'cs-bad' : '') . '">' . CsUi::amt($s['eac'], true) . '</span>',
        '<span class="' . ($s['projected_profit'] < 0 ? 'cs-bad' : '') . '">' . CsUi::amt($s['projected_profit'], true) . '</span><span class="cs-sub">' . CsUi::pct($s['projected_margin']) . '</span>',
        CsUi::amt($s['certified_gross'], true), CsUi::amt($s['ar_open'], true)];
}
echo cs_table_card('Portfolio', ['Project' => '', 'Progress' => '', 'Contract value' => 'num', 'Final cost (EAC)' => 'num', 'Projected profit' => 'num', 'Billed' => 'num', 'Receivable' => 'num'], $rows, 'No open project.');
// billed and collected by month
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
    $months[$m] = ['billed' => 0.0, 'collected' => 0.0];
}
$fromM = array_key_first($months) . '-01';
foreach (DB::all("SELECT DATE_FORMAT(billing_date, '%Y-%m') AS m, SUM(this_gross) AS amt FROM cs_billings WHERE status IN ('invoiced','certified') AND billing_date >= :d GROUP BY m", ['d' => $fromM]) as $r) {
    if (isset($months[$r['m']])) {
        $months[$r['m']]['billed'] = (float)$r['amt'];
    }
}
foreach (DB::all("SELECT DATE_FORMAT(txn_date, '%Y-%m') AS m, SUM(total) AS amt FROM transactions WHERE txn_type = 'payment' AND is_voided = 0 AND txn_date >= :d GROUP BY m", ['d' => $fromM]) as $r) {
    if (isset($months[$r['m']])) {
        $months[$r['m']]['collected'] = (float)$r['amt'];
    }
}
$maxV = max(1, max(array_map(fn($m) => max($m['billed'], $m['collected']), $months)));
echo '<div class="card"><div class="card-header">Billed and collected, last six months</div><div class="card-body"><table class="table table-sm mb-0"><thead><tr><th>Month</th><th>Billed (gross)</th><th>Collected</th></tr></thead><tbody>';
foreach ($months as $m => $v) {
    echo '<tr><td class="text-nowrap">' . h(date('M Y', strtotime($m . '-01'))) . '</td>'
        . '<td style="width:42%">' . CsUi::meter($v['billed'] / $maxV * 100, null, '', CsUi::amt($v['billed'], true)) . '</td>'
        . '<td style="width:42%">' . CsUi::meter($v['collected'] / $maxV * 100, null, 'ok', CsUi::amt($v['collected'], true)) . '</td></tr>';
}
echo '</tbody></table></div></div>';
echo '</div><div class="cs-stack">';
$rows = [];
foreach ($bigAppr as $r) {
    $rows[] = ['<a href="' . h(CsDocs::url($r['doc_type'], (int)$r['doc_id'])) . '">' . h(CsDocs::label($r['doc_type']) . ' ' . $r['doc_ref']) . '</a><span class="cs-sub">Step ' . (int)$r['current_step'] . ' of ' . (int)$r['total_steps'] . '</span>', money($r['amount'])];
}
echo cs_table_card('High-value approvals waiting', ['Document' => '', 'Amount' => 'num'], $rows, 'Nothing above P1M is waiting.');
$rows = [];
foreach ($over + $behind as $pid => $s) {
    $why = [];
    if ($s['projected_profit'] < 0) {
        $why[] = '<span class="cs-bad">Loss ' . money($s['projected_profit']) . '</span>';
    } elseif ($s['over_budget']) {
        $why[] = '<span class="cs-bad">Over budget by ' . money($s['eac'] - $s['budget_current']) . '</span>';
    }
    if ($s['behind']) {
        $why[] = '<span class="cs-warn">Behind schedule</span>';
    }
    $rows[] = [CsUi::projectLink($pid), implode('<br>', $why)];
}
echo cs_table_card('Projects that need attention', ['Project' => '', 'Why' => ''], $rows, 'Every project is on budget and on time.');
$al = CsAlerts::all();
echo '<div class="card"><div class="card-header">Alerts <span class="cs-note">' . count($al) . '</span>' . (Access::page('home.alerts') ? '<a class="ms-auto small" href="' . h(url('home.alerts')) . '">All alerts</a>' : '') . '</div>'
    . ($al ? CsAlerts::render(array_slice($al, 0, 8)) : CsUi::empty('No alerts.', '', 'check2-circle')) . '</div>';
echo '</div></div>';
layout_end();
