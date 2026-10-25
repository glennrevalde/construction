<?php
declare(strict_types=1);

/** Project Center: every project the user may see, with its progress, cost, billing and profit at a glance. */
$pageKey = 'proj.center';
$st = (string)g('status', 'open');
$q = (string)g('q');
$pm = gint('pm');
$cust = gint('customer_id');

[$f, $params] = CsProject::filter('p.id');
$w = $f;
if ($st === 'open') {
    $w .= " AND p.status IN ('planning','mobilization','active','on_hold','substantially_completed')";
} elseif ($st !== '' && $st !== 'all' && isset(CsProject::STATUSES[$st])) {
    $w .= ' AND p.status = :st';
    $params['st'] = $st;
}
if ($pm) {
    $w .= ' AND p.pm_user_id = :pm';
    $params['pm'] = $pm;
}
if ($cust) {
    $w .= ' AND p.customer_id = :cu';
    $params['cu'] = $cust;
}
[$lw, $lp] = cs_like($q, ['p.code', 'p.name', 'p.location', 'c.name']);
$ids = array_map('intval', DB::column("SELECT p.id FROM cs_projects p LEFT JOIN customers c ON c.id = p.customer_id WHERE 1 = 1 $w $lw ORDER BY p.code", $params + $lp));
$sum = CsSummary::portfolio($ids);

$t = ['contract' => 0.0, 'eac' => 0.0, 'cost' => 0.0, 'billed' => 0.0, 'collected' => 0.0, 'ar' => 0.0, 'profit' => 0.0];
$behind = 0;
$over = 0;
foreach ($sum as $s) {
    $t['contract'] += $s['contract_current'];
    $t['eac'] += $s['eac'];
    $t['cost'] += $s['cost_direct'];
    $t['billed'] += $s['billed_gross'];
    $t['collected'] += $s['collected'];
    $t['ar'] += $s['ar_open'];
    $t['profit'] += $s['projected_profit'];
    $behind += $s['behind'] ? 1 : 0;
    $over += $s['over_budget'] ? 1 : 0;
}

$actions = cs_link('proj.projects', [], 'New project', 'btn-primary', 'plus-lg', 'create');
layout_start('Project Center', ['actions' => $actions, 'sub' => count($ids) . ' project' . (count($ids) === 1 ? '' : 's') . ' shown']);
echo '<div class="cs-kpis">'
    . CsUi::kpi('Contract portfolio', CsUi::amt($t['contract'], true), 'Current approved values', 'key')
    . CsUi::kpi('Cost to date', CsUi::amt($t['cost'], true), 'Estimated final cost ' . CsUi::amt($t['eac'], true))
    . CsUi::kpi('Billed', CsUi::amt($t['billed'], true), $t['contract'] > 0 ? CsUi::pct($t['billed'] / $t['contract'] * 100) . ' of the portfolio' : '')
    . CsUi::kpi('Collected', CsUi::amt($t['collected'], true), 'Receivable ' . CsUi::amt($t['ar'], true))
    . CsUi::kpi('Projected profit', CsUi::amt($t['profit'], true), $t['contract'] > 0 ? CsUi::pct($t['profit'] / $t['contract'] * 100) . ' margin' : '', $t['profit'] < 0 ? 'bad' : 'ok')
    . CsUi::kpi('Need attention', (string)($behind + $over), $behind . ' behind schedule, ' . $over . ' over budget', $behind + $over ? 'warn' : 'ok')
    . '</div>';
?>
<form class="cs-toolbar" method="get"><input type="hidden" name="p" value="<?= h($pageKey) ?>">
  <?= cs_search_field('Code, name, location, client') ?>
  <div><label class="form-label">Status</label><select class="form-select form-select-sm" name="status" data-no-combo onchange="this.form.submit()"><?= options(['open' => 'Open projects', 'all' => 'Every project'] + CsProject::STATUSES, $st) ?></select></div>
  <div><label class="form-label">Project manager</label><select class="form-select form-select-sm" name="pm" onchange="this.form.submit()"><?= options(DB::pairs('SELECT DISTINCT u.id, u.full_name FROM users u JOIN cs_projects p ON p.pm_user_id = u.id ORDER BY u.full_name'), $pm, 'Any') ?></select></div>
  <div><label class="form-label">Client</label><select class="form-select form-select-sm" name="customer_id" onchange="this.form.submit()"><?= options(DB::pairs('SELECT DISTINCT c.id, c.name FROM customers c JOIN cs_projects p ON p.customer_id = c.id ORDER BY c.name'), $cust, 'Any') ?></select></div>
  <div><button class="btn btn-outline-secondary btn-sm">Show</button></div>
</form>
<?php
if (!$sum) {
    echo '<div class="card">' . CsUi::empty('No project matches. A project is created from an approved contract, or with New project.', cs_link('proj.projects', [], 'New project', 'btn-primary', 'plus-lg', 'create'), 'buildings') . '</div>';
    layout_end();
    return;
}
?>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover cs-table">
<thead><tr><th>Project</th><th>Status</th><th style="min-width:170px">Progress (tick = planned)</th><th class="num">Contract value</th><th class="num">Cost to date</th><th class="num">Est. final cost</th>
<th class="num">Projected profit</th><th class="num">Billed</th><th class="num">Receivable</th><th>Completion</th></tr></thead><tbody>
<?php foreach ($sum as $pid => $s): $p = $s['project']; ?>
<tr>
  <td class="cs-wrap"><a class="cs-main-link" href="<?= h(url('proj.view', ['id' => $pid])) ?>"><?= h($p['code']) ?> <?= h($p['name']) ?></a>
    <span class="cs-sub"><?= h(CsUi::sub([$p['customer_name'], $p['pm_name'] ? 'PM ' . $p['pm_name'] : ''])) ?></span></td>
  <td><?= CsUi::state($p['status']) ?><?= $s['behind'] ? '<span class="cs-sub cs-bad">Behind schedule</span>' : '' ?><?= $s['over_budget'] ? '<span class="cs-sub cs-bad">Over budget</span>' : '' ?></td>
  <td><?= CsUi::meter($s['physical_pct'], $s['planned_pct']) ?></td>
  <td class="num"><?= money($s['contract_current']) ?><?= $s['variations'] ? '<span class="cs-sub">incl. ' . money($s['variations']) . ' VO</span>' : '' ?></td>
  <td class="num"><?= money($s['cost_direct']) ?></td>
  <td class="num<?= $s['over_budget'] ? ' cs-bad' : '' ?>"><?= money($s['eac']) ?><?= $s['budget_current'] ? '<span class="cs-sub">Budget ' . money($s['budget_current']) . '</span>' : '<span class="cs-sub">No budget yet</span>' ?></td>
  <td class="num<?= $s['projected_profit'] < 0 ? ' cs-bad' : '' ?>"><?= money($s['projected_profit']) ?><span class="cs-sub"><?= CsUi::pct($s['projected_margin']) ?></span></td>
  <td class="num"><?= money($s['billed_gross']) ?><span class="cs-sub"><?= $s['contract_current'] > 0 ? CsUi::pct($s['billed_gross'] / $s['contract_current'] * 100) : '' ?></span></td>
  <td class="num"><?= money($s['ar_open']) ?><?= $s['ar_overdue'] > 0 ? '<span class="cs-sub cs-bad">' . money($s['ar_overdue']) . ' overdue</span>' : '' ?></td>
  <td><?= h(fdate($s['end_date'])) ?><?= $s['forecast_end'] && $s['end_date'] && $s['forecast_end'] > $s['end_date'] ? '<span class="cs-sub cs-bad">Forecast ' . h(fdate($s['forecast_end'])) . '</span>' : '' ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div></div>
<?php
layout_end();
