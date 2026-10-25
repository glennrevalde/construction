<?php
declare(strict_types=1);

/** Finance dashboard: receivables, payables, collections ahead, bank balances, retention, margins and cash flow. */
$today = today();
$buckets = ['current' => 'Not yet due', '1_30' => '1 to 30 days', '31_60' => '31 to 60', '61_90' => '61 to 90', 'over_90' => 'Over 90'];
$bucket = function (?string $due) use ($today): string {
    if (!$due || $due >= $today) {
        return 'current';
    }
    $d = (int)((strtotime($today) - strtotime($due)) / 86400);
    return $d <= 30 ? '1_30' : ($d <= 60 ? '31_60' : ($d <= 90 ? '61_90' : 'over_90'));
};
$ar = array_fill_keys(array_keys($buckets), 0.0);
$arDocs = DB::all("SELECT t.* FROM transactions t WHERE t.txn_type IN ('invoice','statement_charge','finance_charge') AND t.is_voided = 0 AND t.is_pending = 0 AND t.status IN ('open','partial')");
$unpaidInv = 0;
foreach ($arDocs as $t) {
    $o = Balances::open($t);
    if ($o > 0.004) {
        $ar[$bucket($t['due_date'])] += $o;
        $unpaidInv++;
    }
}
$ap = array_fill_keys(array_keys($buckets), 0.0);
foreach (Balances::openPayables() as $b) {
    $ap[$bucket($b['due_date'])] += $b['open_balance'];
}
$forecast = ['30' => 0.0, '60' => 0.0, '90' => 0.0];
foreach ($arDocs as $t) {
    $o = Balances::open($t);
    if ($o <= 0.004) {
        continue;
    }
    $due = $t['due_date'] ?: $t['txn_date'];
    $days = (strtotime($due) - strtotime($today)) / 86400;
    // overdue invoices are expected within the month; the rest on their due dates
    $forecast[$days <= 30 ? '30' : ($days <= 60 ? '60' : '90')] += $o;
}
$banks = Access::viewCosts() || Access::special('view_bank_balances') ? DB::all("SELECT a.id, a.code, a.name, COALESCE(SUM(j.debit - j.credit),0) AS bal FROM accounts a LEFT JOIN journal_lines j ON j.account_id = a.id
                                                                                  WHERE a.account_type = 'Bank' AND a.is_active = 1 AND a.parent_id IS NOT NULL GROUP BY a.id, a.code, a.name ORDER BY a.code") : [];
$retRec = (float)DB::value('SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE account_id = :a', ['a' => Accts::get('cs_retention_receivable')]);
$retPay = -(float)DB::value('SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE account_id = :a', ['a' => Accts::get('cs_retention_payable')]);
$finAppr = (int)DB::value("SELECT COUNT(*) FROM cs_approval_requests WHERE status = 'pending' AND doc_type IN ('billing','sub_billing','allocation','payroll','cash_advance','revenue','purchase','budget')");
// cash flow by month: money in and out of the bank and cash accounts
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $months[date('Y-m', strtotime(date('Y-m-01') . " -$i months"))] = ['in' => 0.0, 'out' => 0.0];
}
foreach (DB::all("SELECT DATE_FORMAT(j.txn_date, '%Y-%m') AS m, SUM(j.debit) AS dr, SUM(j.credit) AS cr FROM journal_lines j JOIN accounts a ON a.id = j.account_id JOIN transactions t ON t.id = j.txn_id
                   WHERE a.account_type = 'Bank' AND j.txn_date >= :d AND t.txn_type NOT IN ('transfer','opening_balance') GROUP BY m", ['d' => array_key_first($months) . '-01']) as $r) {
    if (isset($months[$r['m']])) {
        $months[$r['m']] = ['in' => (float)$r['dr'], 'out' => (float)$r['cr']];
    }
}
[$f, $fp] = CsProject::filter('p.id');
$pids = array_map('intval', DB::column("SELECT p.id FROM cs_projects p WHERE p.status <> 'closed' $f ORDER BY p.code", $fp));
$sum = CsSummary::portfolio($pids);

$title = !empty($GLOBALS['CS_HOME']) ? 'Home' : 'Finance Dashboard';
layout_start($title, ['module' => 'home', 'sub' => 'Finance view as of ' . fdate($today),
    'actions' => cs_link('cust.payments', [], 'Receive payment', 'btn-primary', 'cash-coin', 'create') . ' ' . cs_link('vend.pay_bills', [], 'Pay bills', 'btn-outline-secondary', 'wallet2') . ' '
        . cs_link('home.dashboard', ['view' => 'accounting'], 'Accounting overview', 'btn-outline-secondary', 'journal-bookmark')]);
$arT = array_sum($ar);
$apT = array_sum($ap);
echo '<div class="cs-kpis">'
    . CsUi::kpi('Receivables', CsUi::amt($arT, true), $unpaidInv . ' unpaid invoice(s), ' . CsUi::amt($arT - $ar['current'], true) . ' overdue', $arT - $ar['current'] > 0 ? 'warn' : '', Access::page('rpt.ar_aging_summary') ? url('rpt.ar_aging_summary') : null)
    . CsUi::kpi('Payables', CsUi::amt($apT, true), CsUi::amt($apT - $ap['current'], true) . ' overdue', '', Access::page('rpt.ap_aging_summary') ? url('rpt.ap_aging_summary') : null)
    . CsUi::kpi('Expected in 30 days', CsUi::amt($forecast['30'], true), 'Then ' . CsUi::amt($forecast['60'], true) . ' in 31 to 60 days', 'key')
    . CsUi::kpi('Retention receivable', CsUi::amt($retRec, true), 'Held by clients', '', Access::page('rpt.cs_retention') ? url('rpt.cs_retention') : null)
    . CsUi::kpi('Retention payable', CsUi::amt($retPay, true), 'Held from subcontractors')
    . CsUi::kpi('Finance approvals waiting', (string)$finAppr, 'Billings, payments, allocations, payroll', $finAppr ? 'warn' : '', Access::page('appr.inbox') ? url('appr.inbox') : null)
    . '</div>';
echo '<div class="cs-split is-wide-side"><div class="cs-stack">';
$rows = [];
foreach ($buckets as $k => $l) {
    $rows[] = [h($l), money($ar[$k]), money($ap[$k])];
}
echo cs_table_card('Aging of receivables and payables', ['' => '', 'Receivable' => 'num', 'Payable' => 'num'], $rows, '', '', '<tfoot><tr><td>Total</td><td class="num">' . money($arT) . '</td><td class="num">' . money($apT) . '</td></tr></tfoot>');
$rows = [];
foreach ($sum as $pid => $s) {
    $rows[] = ['<a class="cs-main-link" href="' . h(url('proj.view', ['id' => $pid])) . '">' . h($s['project']['code']) . '</a><span class="cs-sub">' . h($s['project']['name']) . '</span>',
        CsUi::amt($s['revenue'], true), CsUi::amt($s['cost_direct'], true), '<span class="' . ($s['gross_profit_to_date'] < 0 ? 'cs-bad' : '') . '">' . CsUi::amt($s['gross_profit_to_date'], true) . '</span>',
        '<span class="' . ($s['projected_margin'] < 0 ? 'cs-bad' : '') . '">' . CsUi::pct($s['projected_margin']) . '</span>', CsUi::amt($s['ar_open'], true), CsUi::amt($s['retention_receivable'], true)];
}
echo cs_table_card('Project margins and receivables', ['Project' => '', 'Revenue' => 'num', 'Direct cost' => 'num', 'Gross profit' => 'num', 'Projected margin' => 'num', 'Receivable' => 'num', 'Retention' => 'num'], $rows, 'No open project.');
$maxV = max(1, max(array_map(fn($m) => max($m['in'], $m['out']), $months)));
echo '<div class="card"><div class="card-header">Cash flow through the bank and cash accounts</div><div class="card-body"><table class="table table-sm mb-0"><thead><tr><th>Month</th><th>Money in</th><th>Money out</th><th class="num">Net</th></tr></thead><tbody>';
foreach ($months as $m => $v) {
    echo '<tr><td class="text-nowrap">' . h(date('M Y', strtotime($m . '-01'))) . '</td><td style="width:36%">' . CsUi::meter($v['in'] / $maxV * 100, null, 'ok', CsUi::amt($v['in'], true)) . '</td>'
        . '<td style="width:36%">' . CsUi::meter($v['out'] / $maxV * 100, null, 'warn', CsUi::amt($v['out'], true)) . '</td><td class="num ' . ($v['in'] - $v['out'] < 0 ? 'cs-bad' : '') . '">' . money($v['in'] - $v['out']) . '</td></tr>';
}
echo '</tbody></table></div></div>';
echo '</div><div class="cs-stack">';
if ($banks) {
    $rows = [];
    $tb = 0.0;
    foreach ($banks as $b) {
        $rows[] = [h($b['code'] . ' ' . $b['name']), money($b['bal'])];
        $tb += (float)$b['bal'];
    }
    echo cs_table_card('Bank and cash balances', ['Account' => '', 'Balance' => 'num'], $rows, '', '', '<tfoot><tr><td>Total</td><td class="num">' . money($tb) . '</td></tr></tfoot>');
}
$rows = [];
foreach (array_slice(CsApproval::inbox((int)Auth::id()), 0, 8) as $r) {
    $rows[] = ['<a href="' . h(CsDocs::url($r['doc_type'], (int)$r['doc_id'])) . '">' . h(CsDocs::label($r['doc_type']) . ' ' . $r['doc_ref']) . '</a>', money($r['amount'])];
}
echo cs_table_card('Waiting for your approval', ['Document' => '', 'Amount' => 'num'], $rows, 'Nothing waits for you.');
$al = array_values(array_filter(CsAlerts::all(), fn($a) => in_array($a['kind'], ['collection', 'payable', 'billing', 'loss', 'budget', 'spending', 'approval'], true)));
echo '<div class="card"><div class="card-header">Money alerts <span class="cs-note">' . count($al) . '</span></div>' . ($al ? CsAlerts::render(array_slice($al, 0, 10)) : CsUi::empty('No alerts.', '', 'check2-circle')) . '</div>';
echo '</div></div>';
layout_end();
