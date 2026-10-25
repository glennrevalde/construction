<?php
declare(strict_types=1);

/**
 * Change orders, variations and claims: price, cost, labor, equipment and time impacts; internal approval; the client's
 * approval, which moves the contract value, the schedule of values, the completion date and prepares a budget revision.
 */
$pageKey = 'proj.change_orders';
$a = (string)g('a', 'list');
$id = gint('id');
$co = $id ? CsContracts::getCo($id) : null;
if ($co) {
    CsProject::requireAccess((int)$co['project_id']);
}

handle_post(function () use ($pageKey, $id, $co) {
    if ($co && cs_handle_attach('change_order', (int)$id, (int)$co['project_id'])) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if ($co && CsApproval::handlePost('change_order', (int)$id, function () use ($co) {
        if (!in_array($co['status'], ['proposed', 'disputed'], true)) {
            fail('Only a proposed change order is submitted for approval.');
        }
        $risk = abs((float)$co['price_impact']) >= 1000000 || (int)$co['schedule_impact_days'] >= 30 ? 'high' : null;
        CsApproval::submit('change_order', (int)$co['id'], $co['co_no'], (int)$co['project_id'], abs((float)$co['price_impact']), '', $risk);
    })) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    $act = (string)p('act');
    if ($co && $act === 'client') {
        Access::require($pageKey, 'approve');
        $settle = trim((string)p('settlement')) !== '' ? to_num(p('settlement')) : null;
        CsContracts::clientApprove((int)$id, (string)p('client_date'), trim((string)p('client_ref')), $settle);
        flash('success', 'Client approval recorded. The revised contract value and the schedule of values now include it; a draft budget revision was prepared for its cost impact.');
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if ($co && $act === 'status') {
        Access::require($pageKey, 'edit');
        CsContracts::coStatus((int)$id, (string)p('status'), (string)p('reason'));
        flash('success', 'Status changed.');
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if ($act === 'save') {
        Access::require($pageKey, $id ? 'edit' : 'create');
        if (!$co) {
            CsProject::requireAccess((int)p('project_id'));
        }
        $nid = CsContracts::saveCo($_POST, cs_lines(), $id);
        flash('success', 'Change order saved.');
        cs_go($pageKey, ['a' => 'view', 'id' => $nid]);
    }
});

if ($a === 'new' || ($a === 'edit' && $co)) {
    Access::require($pageKey, $co ? 'edit' : 'create');
    $pid = $co ? (int)$co['project_id'] : (int)(gint('project') ?? CsProject::current() ?? 0);
    $c = $co ?: ['project_id' => $pid, 'co_type' => 'client_request', 'requested_by_party' => 'client'];
    $lines = is_post() ? cs_lines() : ($co ? DB::all('SELECT * FROM cs_co_lines WHERE co_id = :c ORDER BY line_no', ['c' => $id]) : []);
    $ccOpts = CsCost::codeOptions();
    $row = function (array $l, string $i) use ($ccOpts): string {
        $n = fn($f) => 'lines[' . $i . '][' . $f . ']';
        return '<tr><td class="w-desc"><input class="form-control form-control-sm" name="' . $n('description') . '" value="' . h($l['description'] ?? '') . '"></td>'
            . '<td class="w-sm"><input class="form-control form-control-sm" name="' . $n('unit') . '" value="' . h($l['unit'] ?? '') . '"></td>'
            . '<td class="w-sm"><input class="form-control form-control-sm text-end" name="' . $n('qty') . '" value="' . h(isset($l['qty']) && $l['qty'] !== '' ? qty($l['qty']) : '') . '" data-q></td>'
            . '<td class="w-num"><input class="form-control form-control-sm text-end" name="' . $n('unit_rate') . '" value="' . h(isset($l['unit_rate']) && $l['unit_rate'] !== '' ? (float)$l['unit_rate'] : '') . '" data-r></td>'
            . '<td class="w-num num" data-a>' . money($l['amount'] ?? 0) . '</td>'
            . '<td class="w-num"><input class="form-control form-control-sm text-end" name="' . $n('cost_amount') . '" value="' . h(isset($l['cost_amount']) && $l['cost_amount'] !== '' ? number_format((float)$l['cost_amount'], 2, '.', '') : '') . '" data-sum="cost"></td>'
            . '<td style="min-width:170px">' . CsUi::select($n('cost_code_id'), $ccOpts, $l['cost_code_id'] ?? '', '', 'data-combo') . '</td>'
            . '<td class="grid-acts"><button type="button" class="btn btn-link btn-sm text-danger p-0" data-cs-del aria-label="Remove"><i class="bi bi-x-lg"></i></button></td></tr>';
    };
    layout_start($co ? 'Edit ' . $co['co_no'] : 'New change order', ['module' => 'proj', 'sub' => 'Price is the change to the contract value (net of VAT); cost is what it will cost us. Negative figures reduce the scope.']);
    ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="save">
    <div class="card mb-3"><div class="card-body"><div class="row g-3">
      <?= CsUi::field('Project', $co ? '<input type="hidden" name="project_id" value="' . $pid . '"><div class="pt-1">' . CsUi::projectLink($pid) . '</div>' : CsUi::projectSelect('project_id', $pid ?: '', ['required' => true]), 'col-md-5') ?>
      <?= CsUi::field('Type', CsUi::select('co_type', CsContracts::CO_TYPES, fv('co_type', $c), null, 'data-no-combo'), 'col-md-4') ?>
      <?= CsUi::field('Requested by', CsUi::select('requested_by_party', ['client' => 'Client', 'contractor' => 'Contractor (us)', 'consultant' => 'Consultant'], fv('requested_by_party', $c), null, 'data-no-combo'), 'col-md-3') ?>
      <?= CsUi::field('Title', CsUi::input('title', fv('title', $c), ['required' => true, 'max' => 200]), 'col-md-8') ?>
      <?= CsUi::field('Time impact (days)', CsUi::input('schedule_impact_days', fv('schedule_impact_days', $c, '0'), ['type' => 'number', 'step' => '1']), 'col-md-4') ?>
      <?= CsUi::field('Reason', CsUi::textarea('reason', fv('reason', $c), 2), 'col-md-6') ?>
      <?= CsUi::field('Description of the change', CsUi::textarea('description', fv('description', $c), 2), 'col-md-6') ?>
      <?= CsUi::field('Labor impact', CsUi::input('labor_impact', fv('labor_impact', $c), ['max' => 255]), 'col-md-6') ?>
      <?= CsUi::field('Equipment impact', CsUi::input('equipment_impact', fv('equipment_impact', $c), ['max' => 255]), 'col-md-6') ?>
      <?= CsUi::field('Billing implications', CsUi::textarea('billing_implications', fv('billing_implications', $c), 2), 'col-12') ?>
    </div></div></div>
    <div class="card mb-3"><div class="card-header">Priced items</div>
      <div class="table-responsive"><table class="table table-sm cs-lines mb-0" id="coGrid" data-cs-lines data-min-rows="3"><thead><tr><th>Description</th><th>Unit</th><th class="num">Qty</th><th class="num">Unit rate</th><th class="num">Price</th><th class="num">Our cost</th><th>Cost code</th><th></th></tr></thead>
      <tbody><?php foreach ($lines as $i => $l) { echo $row($l, 'e' . $i); } ?></tbody>
      <tfoot><tr><td colspan="4" class="text-end">Totals</td><td class="num" data-cs-sum="amount">0.00</td><td class="num" data-cs-sum="cost">0.00</td><td colspan="2"></td></tr></tfoot></table></div>
      <template data-cs-row="coGrid"><?= $row([], '__i__') ?></template>
      <div class="card-body pt-2"><button type="button" class="btn btn-outline-secondary btn-sm" data-cs-add="coGrid"><i class="bi bi-plus"></i> Add an item</button>
        <span class="cs-note ms-2">Without items, the price and cost below are used.</span></div>
      <div class="card-body border-top"><div class="row g-3">
        <?= CsUi::field('Price impact (when no items)', CsUi::input('price_impact', fv('price_impact', $c), ['num' => true]), 'col-md-3') ?>
        <?= CsUi::field('Cost impact (when no items)', CsUi::input('cost_impact', fv('cost_impact', $c), ['num' => true]), 'col-md-3') ?>
      </div></div></div>
    <div class="sticky-actions"><?= save_buttons('Save change order', url($pageKey, $co ? ['a' => 'view', 'id' => $id] : ['project' => $pid]), false) ?></div>
    </form>
    <?php
    layout_end();
    return;
}

if ($a === 'view' && $co) {
    $lines = DB::all('SELECT * FROM cs_co_lines WHERE co_id = :c ORDER BY line_no', ['c' => $id]);
    $cv = CsProject::contractValue((int)$co['project_id']);
    $editable = in_array($co['status'], ['proposed', 'disputed'], true) && Access::can($pageKey, 'edit');
    layout_start($co['co_no'] . ' ' . $co['title'], ['module' => 'proj', 'sub' => CsUi::projectLink((int)$co['project_id']) . ', ' . h(CsContracts::CO_TYPES[$co['co_type']] ?? '') . ' ' . CsUi::state($co['status']),
        'actions' => $editable ? cs_link($pageKey, ['a' => 'edit', 'id' => $id], 'Edit', 'btn-outline-secondary', 'pencil', 'edit') : '']);
    $margin = (float)$co['price_impact'] - (float)$co['cost_impact'];
    echo '<div class="cs-kpis">' . CsUi::kpi('Price impact', CsUi::amt($co['price_impact']), $co['settlement_amount'] !== null ? 'Settled at ' . money($co['settlement_amount']) : 'Change to the contract value', 'key')
        . CsUi::kpi('Cost impact', CsUi::amt($co['cost_impact']), 'Margin ' . money($margin), $margin < 0 ? 'bad' : '')
        . CsUi::kpi('Time impact', (int)$co['schedule_impact_days'] . ' days', '')
        . CsUi::kpi('Contract value now', CsUi::amt($cv['current']), 'Pending changes ' . money($cv['pending'])) . '</div>';
    echo '<div class="cs-split"><div class="cs-stack">';
    $rows = [];
    foreach ($lines as $l) {
        $rows[] = [h($l['description']), h((string)$l['unit']), qty($l['qty']), money($l['unit_rate']), money($l['amount']), money($l['cost_amount']), h(CsCost::codeLabel($l['cost_code_id'] ? (int)$l['cost_code_id'] : null))];
    }
    echo cs_table_card('Priced items', ['Description' => '', 'Unit' => '', 'Qty' => 'num', 'Rate' => 'num', 'Price' => 'num', 'Cost' => 'num', 'Cost code' => ''], $rows, 'Priced as a lump sum.');
    echo '<div class="card"><div class="card-header">Details</div><div class="card-body">' . CsUi::facts([
        'Requested by' => h(CsUi::label($co['requested_by_party'])), 'Labor impact' => h((string)$co['labor_impact']), 'Equipment impact' => h((string)$co['equipment_impact']),
        'Internally approved' => $co['internal_approved_at'] ? h(fdatetime($co['internal_approved_at'])) : '', 'Client approval' => $co['client_approved_date'] ? h(fdate($co['client_approved_date'])) . ($co['client_ref'] ? ', ref. ' . h($co['client_ref']) : '') : '',
        'Budget revision' => $co['budget_id'] ? '<a href="' . h(url('plan.budgets', ['a' => 'view', 'id' => $co['budget_id']])) . '">' . h((string)DB::value("SELECT CONCAT('v', version_no, ' ', name, ' (', status, ')') FROM cs_budgets WHERE id = :b", ['b' => $co['budget_id']])) . '</a>' : '',
    ]) . '</div>';
    foreach (['reason' => 'Reason', 'description' => 'Description', 'billing_implications' => 'Billing implications'] as $f => $l) {
        if (trim((string)$co[$f]) !== '') {
            echo '<div class="card-body border-top"><div class="cs-note mb-1">' . h($l) . '</div>' . nl2br(h($co[$f])) . '</div>';
        }
    }
    echo '</div>' . CsFiles::panel('change_order', $id, Access::can($pageKey, 'edit'), 'Client instruction and supporting documents') . '</div><div class="cs-stack">';
    echo CsApproval::panel('change_order', $id, Access::can($pageKey, 'edit') && in_array($co['status'], ['proposed', 'disputed'], true), 'Submit for internal approval');
    if (in_array($co['status'], ['internally_approved', 'disputed'], true) && Access::can($pageKey, 'approve')) {
        echo '<form method="post" class="card"><div class="card-header">Client approval</div><div class="card-body"><div class="row g-2">' . csrf_field() . '<input type="hidden" name="act" value="client">'
            . CsUi::field('Date approved by the client', CsUi::input('client_date', today(), ['type' => 'date', 'required' => true]), 'col-6')
            . CsUi::field('Client\'s reference', CsUi::input('client_ref', '', ['max' => 60]), 'col-6')
            . CsUi::field('Settled amount (claims and disputes)', CsUi::input('settlement', '', ['num' => true, 'placeholder' => 'As priced']), 'col-12')
            . '</div></div><div class="card-footer"><button class="btn btn-primary btn-sm" data-confirm="Record the client\'s approval? The contract value, the schedule of values and the completion date change.">Record client approval</button></div></form>';
    }
    $moves = ['proposed' => ['withdrawn' => 'Withdrawn', 'disputed' => 'Disputed'], 'internally_approved' => ['disputed' => 'Disputed', 'rejected' => 'Rejected by the client', 'withdrawn' => 'Withdrawn'],
        'disputed' => ['withdrawn' => 'Withdrawn', 'rejected' => 'Rejected by the client']][$co['status']] ?? [];
    if ($moves && Access::can($pageKey, 'edit')) {
        echo '<form method="post" class="card"><div class="card-header">Other outcome</div><div class="card-body"><div class="row g-2">' . csrf_field() . '<input type="hidden" name="act" value="status">'
            . '<div class="col-12">' . CsUi::select('status', $moves, '', null, 'data-no-combo') . '</div><div class="col-12"><input class="form-control form-control-sm" name="reason" placeholder="Reason" required></div>'
            . '<div class="col-12"><button class="btn btn-outline-secondary btn-sm">Change status</button></div></div></div></form>';
    }
    echo cs_history('change_order', $id) . '</div></div>';
    layout_end();
    return;
}

$pid = CsProject::current();
$st = (string)g('status');
$statuses = ['proposed' => 'Proposed', 'for_approval' => 'For approval', 'internally_approved' => 'Internally approved', 'client_approved' => 'Client-approved', 'disputed' => 'Disputed', 'rejected' => 'Rejected', 'withdrawn' => 'Withdrawn'];
layout_start('Change Orders & Claims', ['actions' => cs_link($pageKey, ['a' => 'new', 'project' => $pid], 'New change order', 'btn-primary', 'plus-lg', 'create')]);
echo cs_filter_bar($pageKey, $pid, cs_search_field('No., title') . cs_status_field($statuses));
[$f, $params] = CsProject::filter('o.project_id');
[$w, $wp] = cs_like((string)g('q'), ['o.co_no', 'o.title']);
$params += $wp;
if ($pid) {
    $w .= ' AND o.project_id = :pid';
    $params['pid'] = $pid;
}
if (isset($statuses[$st])) {
    $w .= ' AND o.status = :st';
    $params['st'] = $st;
}
$rows = DB::all("SELECT o.*, p.code, p.name AS pname FROM cs_change_orders o JOIN cs_projects p ON p.id = o.project_id WHERE 1 = 1 $f $w ORDER BY o.id DESC LIMIT 400", $params);
$out = [];
$t = [0.0, 0.0];
foreach ($rows as $r) {
    if ($r['status'] === 'client_approved') {
        $t[0] += (float)$r['price_impact'];
    } elseif (!in_array($r['status'], ['rejected', 'withdrawn'], true)) {
        $t[1] += (float)$r['price_impact'];
    }
    $age = (int)floor((time() - strtotime((string)$r['created_at'])) / 86400);
    $out[] = ['<a class="cs-main-link" href="' . h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) . '">' . h($r['co_no']) . '</a><span class="cs-sub">' . h($r['title']) . '</span>', h($r['code'] . ' ' . $r['pname']),
        h(CsContracts::CO_TYPES[$r['co_type']] ?? ''), money($r['price_impact']), money($r['cost_impact']), (int)$r['schedule_impact_days'] . ' d',
        CsUi::state($r['status']) . (in_array($r['status'], ['proposed', 'for_approval', 'internally_approved'], true) && $age > 14 ? '<span class="cs-sub cs-warn">' . $age . ' days old</span>' : '')];
}
echo '<div class="cs-kpis">' . CsUi::kpi('Client-approved variations', CsUi::amt($t[0]), 'In the revised contract values', 'ok') . CsUi::kpi('Pending variations and claims', CsUi::amt($t[1]), 'Not yet approved by the client', $t[1] ? 'warn' : '') . '</div>';
echo cs_table_card(count($rows) . ' change orders', ['Change order' => '', 'Project' => '', 'Type' => '', 'Price' => 'num', 'Cost' => 'num', 'Time' => 'num', 'Status' => ''], $out, 'No change orders.');
layout_end();
