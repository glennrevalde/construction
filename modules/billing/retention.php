<?php
declare(strict_types=1);

/** Retention both ways: what clients hold back from our billings, and what we hold back from subcontractors. */
$pageKey = 'pbill.retention';
$pid = CsProject::current();
layout_start('Retention Management', ['sub' => 'Retention receivable is released by a retention release billing; retention payable by a subcontractor\'s retention release billing.']);
echo cs_filter_bar($pageKey, $pid);
[$f, $params] = CsProject::filter('p.id');
$projects = DB::all("SELECT p.* FROM cs_projects p WHERE p.status <> 'planning' $f" . ($pid ? ' AND p.id = ' . (int)$pid : '') . ' ORDER BY p.code', $params);
$rows = [];
$tot = ['ret' => 0.0, 'rel' => 0.0, 'bal' => 0.0, 'pay' => 0.0];
foreach ($projects as $p) {
    $prev = CsBilling::previous((int)$p['id'], null, "'invoiced'");
    $bal = (float)(CsCost::balance('cs_retention_receivable', [(int)$p['id']])[(int)$p['id']] ?? 0);
    $pay = -(float)(CsCost::balance('cs_retention_payable', [(int)$p['id']])[(int)$p['id']] ?? 0);
    $k = $p['contract_id'] ? DB::row('SELECT retention_pct, retention_release_terms, dlp_months FROM cs_contracts WHERE id = :k', ['k' => $p['contract_id']]) : null;
    $due = $p['warranty_end_date'] ?: ($p['actual_end_date'] && $k ? date('Y-m-d', strtotime($p['actual_end_date'] . ' +' . (int)$k['dlp_months'] . ' months')) : null);
    $tot['ret'] += $prev['retention'];
    $tot['rel'] += $prev['released'];
    $tot['bal'] += $bal;
    $tot['pay'] += $pay;
    $canRelease = $bal > 0.5 && in_array($p['status'], ['substantially_completed', 'completed', 'under_warranty', 'closed'], true);
    $rows[] = [CsUi::projectLink((int)$p['id']) . '<span class="cs-sub">' . h(CsUi::label($p['status'])) . '</span>', $k ? CsUi::pct((float)$k['retention_pct'], 2) : '', money($prev['retention']), money($prev['released']), money($bal),
        $due ? '<span class="' . ($due <= today() && $bal > 0.5 ? 'cs-warn' : '') . '">' . h(fdate($due)) . '</span>' : '', money($pay),
        $canRelease ? cs_link('pbill.billings', ['a' => 'new', 'project' => $p['id'], 'type' => 'retention_release'], 'Bill release', 'btn-outline-primary', '', 'create') : ''];
}
echo '<div class="cs-kpis">' . CsUi::kpi('Retention receivable', CsUi::amt($tot['bal']), 'Held by clients', 'key') . CsUi::kpi('Retained to date', CsUi::amt($tot['ret']), 'Released ' . money($tot['rel']))
    . CsUi::kpi('Retention payable', CsUi::amt($tot['pay']), 'Held from subcontractors') . '</div>';
echo cs_table_card('By project', ['Project' => '', 'Rate' => 'num', 'Retained' => 'num', 'Released' => 'num', 'Receivable now' => 'num', 'Release due' => '', 'Payable to subcontractors' => 'num', '' => ''], $rows, 'No projects.', '',
    $rows ? '<tfoot><tr><td colspan="2">Total</td><td class="num">' . money($tot['ret']) . '</td><td class="num">' . money($tot['rel']) . '</td><td class="num">' . money($tot['bal']) . '</td><td></td><td class="num">' . money($tot['pay']) . '</td><td></td></tr></tfoot>' : '');
// subcontractor retention
[$f2, $p2] = CsProject::filter('s.project_id', 'rs');
$subs = DB::all("SELECT s.*, v.name AS vendor_name, p.code FROM cs_subcontracts s JOIN vendors v ON v.id = s.vendor_id JOIN cs_projects p ON p.id = s.project_id WHERE s.status IN ('active','completed','closed') $f2" . ($pid ? ' AND s.project_id = ' . (int)$pid : '') . ' ORDER BY p.code, s.sc_no', $p2);
$rows = [];
foreach ($subs as $s) {
    $pos = CsSub::position((int)$s['id']);
    $held = $pos['retained'] - $pos['released'];
    if ($pos['retained'] < 0.5) {
        continue;
    }
    $rows[] = ['<a href="' . h(url('subk.contracts', ['a' => 'view', 'id' => $s['id']])) . '">' . h($s['sc_no']) . '</a><span class="cs-sub">' . h($s['work_package']) . '</span>', h($s['vendor_name']), h($s['code']),
        money($pos['retained']), money($pos['released']), money($held), CsUi::state($s['status']),
        $held > 0.5 && $s['status'] === 'completed' ? cs_link('subk.billings', ['a' => 'new', 'sc' => $s['id'], 'kind' => 'retention_release'], 'Release', 'btn-outline-primary', '', 'create') : ''];
}
echo '<div class="mb-3"></div>' . cs_table_card('Held from subcontractors', ['Subcontract' => '', 'Subcontractor' => '', 'Project' => '', 'Retained' => 'num', 'Released' => 'num', 'Held' => 'num', 'Status' => '', '' => ''], $rows, 'No retention held from subcontractors.');
layout_end();
