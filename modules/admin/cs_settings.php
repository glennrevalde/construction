<?php
declare(strict_types=1);

/** Construction settings: revenue recognition, materials costing, retention, overbilling and budget control, timekeeping, approvals. */
$pageKey = 'admin.cs_settings';
$fields = [
    'cs_revenue_method' => ['Revenue recognition', ['poc_cost' => 'Percentage of completion, cost-to-cost', 'poc_physical' => 'Percentage of completion, physical progress', 'completed' => 'At completion of the contract', 'billing' => 'As the billings are certified']],
    'cs_material_policy' => ['When materials become project cost', ['on_issue' => 'When issued to the project', 'on_consume' => 'When the site reports them used']],
    'cs_overbilling' => ['Billing past a line of the schedule of values', ['block' => 'Refuse (unless an authorized adjustment is recorded)', 'warn' => 'Warn only']],
    'cs_budget_control' => ['Requests and commitments past the budget', ['block' => 'Refuse until a budget revision is approved', 'warn' => 'Warn and flag the approval as high risk']],
    'cs_billing_vat_base' => ['VAT on progress billings', ['gross_less_recoupment' => 'On the accomplishment less the advance recouped', 'gross' => 'On the gross accomplishment']],
    'cs_project_access' => ['Who sees which project', ['members' => 'Only the project\'s team (and roles marked all projects)', 'all' => 'Every user with access to the page']],
    'cs_self_approval' => ['Maker-checker', ['0' => 'Nobody approves what they submitted', '1' => 'Allow approving one\'s own documents (small companies)']],
];
$numbers = ['cs_retention_default' => ['Usual retention %', '0.01'], 'cs_regular_hours' => ['Regular hours a day', '0.5'], 'cs_max_daily_hours' => ['Most hours a worker can log in a day', '0.5'],
    'cs_approval_overdue_hours' => ['An approval step is overdue after (hours)', '1']];

handle_post(function () use ($pageKey, $fields, $numbers) {
    Access::require($pageKey, 'edit');
    $changes = [];
    foreach ($fields as $k => [$label, $opts]) {
        $v = (string)p($k);
        if (isset($opts[$v]) && $v !== Settings::get($k)) {
            if ($k === 'cs_revenue_method' && DB::value("SELECT id FROM cs_revenue_recognitions WHERE status = 'posted' LIMIT 1") && trim((string)p('reason')) === '') {
                fail('Revenue has already been recognized under the current method. Give the reason for changing it (it is kept in the audit trail).');
            }
            $changes[] = ['field' => $k, 'old' => Settings::get($k), 'new' => $v];
            Settings::set($k, $v);
        }
    }
    foreach ($numbers as $k => $_) {
        $v = trim((string)p($k));
        if ($v !== '' && is_numeric($v) && $v !== Settings::get($k)) {
            $changes[] = ['field' => $k, 'old' => Settings::get($k), 'new' => $v];
            Settings::set($k, $v);
        }
    }
    $pfx = mb_substr(trim((string)p('cs_project_code_prefix')), 0, 10);
    if ($pfx !== '' && $pfx !== Settings::get('cs_project_code_prefix')) {
        $changes[] = ['field' => 'cs_project_code_prefix', 'old' => Settings::get('cs_project_code_prefix'), 'new' => $pfx];
        Settings::set('cs_project_code_prefix', $pfx);
        DB::run("UPDATE sequences SET prefix = :p WHERE seq_key = 'cs_project'", ['p' => $pfx]);
    }
    if ($changes) {
        Audit::log('settings', 'admin', 'settings', null, 'Construction settings', ['page_key' => $pageKey, 'changes' => $changes, 'reason' => (string)p('reason') ?: null]);
    }
    flash('success', $changes ? count($changes) . ' setting(s) changed.' : 'Nothing changed.');
    cs_go($pageKey);
});

layout_start('Construction Settings');
echo '<form method="post">' . csrf_field() . '<div class="card mb-3"><div class="card-header">Policies</div><div class="card-body">';
foreach ($fields as $k => [$label, $opts]) {
    echo '<div class="row g-2 align-items-center py-2 border-bottom"><div class="col-md-5 fw-semibold">' . h($label) . '</div><div class="col-md-7">' . CsUi::select($k, $opts, Settings::get($k), null, 'data-no-combo') . '</div></div>';
}
echo '</div></div><div class="card mb-3"><div class="card-header">Figures</div><div class="card-body"><div class="row g-3">';
foreach ($numbers as $k => [$label, $step]) {
    echo CsUi::field($label, CsUi::input($k, Settings::get($k), ['type' => 'number', 'step' => $step]), 'col-md-3');
}
echo CsUi::field('Project code prefix', CsUi::input('cs_project_code_prefix', Settings::get('cs_project_code_prefix', 'PRJ-'), ['max' => 10]), 'col-md-3') . '</div></div></div>'
    . '<div class="card mb-3"><div class="card-body">' . CsUi::field('Reason for the change (kept in the audit trail)', CsUi::input('reason', '', ['max' => 255]), 'col-12') . '</div></div>'
    . '<div class="sticky-actions">' . save_buttons('Save settings', null, false) . '</div></form>';
echo '<div class="cs-split mt-3"><div class="card"><div class="card-header">Also set up</div><div class="card-body d-flex flex-wrap gap-2">'
    . page_link('coa.default_accounts', [], 'Default accounts (construction)', 'btn-outline-secondary', 'diagram-3') . page_link('appr.routes', [], 'Approval routes', 'btn-outline-secondary', 'signpost-split')
    . page_link('plan.cost_codes', [], 'Cost codes', 'btn-outline-secondary', 'tags') . page_link('labor.trades', [], 'Trades and rates', 'btn-outline-secondary', 'person-gear')
    . page_link('cost.rules', [], 'Allocation rules', 'btn-outline-secondary', 'sliders') . page_link('admin.roles', [], 'Roles', 'btn-outline-secondary', 'people') . '</div></div><div></div></div>';
layout_end();
