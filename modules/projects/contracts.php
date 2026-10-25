<?php
declare(strict_types=1);

/** Contracts with clients: terms, bonds and permits, approval, projects and change orders. */
$pageKey = 'proj.contracts';
$a = (string)g('a', 'list');
$id = gint('id');
$k = $id ? CsContracts::get($id) : null;

handle_post(function () use ($pageKey, $id, $k) {
    if ($k && cs_handle_attach('contract', (int)$id, null)) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if ($k && CsApproval::handlePost('contract', (int)$id, function () use ($k) {
        if (!in_array($k['status'], ['draft', 'rejected'], true)) {
            fail('Only a draft contract is submitted for approval.');
        }
        CsApproval::submit('contract', (int)$k['id'], $k['contract_no'], null, (float)$k['original_amount']);
    })) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if (p('act') === 'status' && $k) {
        Access::require($pageKey, 'edit');
        CsContracts::contractStatus((int)$id, (string)p('status'), (string)p('reason'));
        flash('success', 'Contract status changed.');
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if (p('act') === 'save') {
        Access::require($pageKey, $id ? 'edit' : 'create');
        $nid = CsContracts::save($_POST, cs_lines('bonds'), $id);
        flash('success', 'Contract saved.' . ($id ? '' : ' Submit it for approval when it is complete.'));
        cs_go($pageKey, ['a' => 'view', 'id' => $nid]);
    }
});

// ---------------------------------------------------------------- form
if ($a === 'new' || ($a === 'edit' && $k)) {
    Access::require($pageKey, $k ? 'edit' : 'create');
    $c = $k ?: ['customer_id' => gint('customer_id'), 'contract_type' => 'lump_sum', 'retention_pct' => Settings::get('cs_retention_default', '10'), 'advance_pct' => 15,
        'recoupment_pct' => 15, 'warranty_months' => 12, 'dlp_months' => 12, 'tax_code_id' => DB::value("SELECT id FROM tax_codes WHERE code = 'VAT12'"),
        'terms_id' => DB::value("SELECT id FROM terms WHERE name = 'Net 30'")];
    if (!$k && $c['customer_id']) {
        $cust = DB::row('SELECT tax_code_id, wht_ids, terms_id FROM customers WHERE id = :id', ['id' => $c['customer_id']]);
        if ($cust) {
            $c['wht_ids'] = $cust['wht_ids'];
            $c['tax_code_id'] = $cust['tax_code_id'] ?: $c['tax_code_id'];
            $c['terms_id'] = $cust['terms_id'] ?: $c['terms_id'];
        }
    }
    $locked = $k && in_array($k['status'], ['active', 'completed', 'closed', 'suspended'], true);
    $bonds = is_post() ? cs_lines('bonds') : ($k ? DB::all('SELECT * FROM cs_contract_bonds WHERE contract_id = :c ORDER BY id', ['c' => $k['id']]) : []);
    $wSel = array_map('intval', explode(',', (string)(is_post() ? implode(',', (array)($_POST['wht_ids'] ?? [])) : ($c['wht_ids'] ?? ''))));
    $ro = $locked ? ' readonly' : '';
    $roSel = $locked ? ' disabled' : '';
    layout_start($k ? 'Edit contract ' . $k['contract_no'] : 'New contract', ['module' => 'proj',
        'sub' => $locked ? 'Approved: the amount, client, type, advance and retention are locked. Change the value with a change order.' : 'Amounts are net of VAT.']);
    $bondRow = function (array $b, string $i): string {
        $n = fn($f) => 'bonds[' . $i . '][' . $f . ']';
        return '<tr><td>' . CsUi::select($n('kind'), CsContracts::BOND_KINDS, $b['kind'] ?? 'performance_bond', null, 'data-no-combo') . '</td>'
            . '<td>' . CsUi::input($n('reference_no'), $b['reference_no'] ?? '') . '</td><td>' . CsUi::input($n('issuer'), $b['issuer'] ?? '') . '</td>'
            . '<td class="w-num">' . CsUi::input($n('amount'), isset($b['amount']) && $b['amount'] !== '' ? number_format((float)$b['amount'], 2, '.', '') : '', ['num' => true]) . '</td>'
            . '<td>' . CsUi::input($n('issue_date'), $b['issue_date'] ?? '', ['type' => 'date']) . '</td><td>' . CsUi::input($n('expiry_date'), $b['expiry_date'] ?? '', ['type' => 'date']) . '</td>'
            . '<td>' . CsUi::select($n('status'), ['active' => 'Active', 'released' => 'Released', 'expired' => 'Expired', 'claimed' => 'Claimed'], $b['status'] ?? 'active', null, 'data-no-combo') . '</td>'
            . '<td class="grid-acts"><button type="button" class="btn btn-link btn-sm text-danger p-0" data-cs-del aria-label="Remove"><i class="bi bi-x-lg"></i></button></td></tr>';
    };
    ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="save">
    <div class="card mb-3"><div class="card-header">The contract</div><div class="card-body"><div class="row g-3">
      <?php if ($locked): ?><input type="hidden" name="customer_id" value="<?= (int)$c['customer_id'] ?>"><input type="hidden" name="contract_type" value="<?= h($c['contract_type']) ?>"><?php endif; ?>
      <?= CsUi::field('Client', '<select class="form-select form-select-sm" name="customer_id" data-combo required' . $roSel . '>' . options(Lookup::customers(false), fv('customer_id', $c), 'Choose the client') . '</select>', 'col-md-5') ?>
      <?= CsUi::field('Contract no.', $k ? '<input class="form-control form-control-sm" value="' . h($k['contract_no']) . '" readonly>' : CsUi::input('contract_no', fv('contract_no', $c), ['placeholder' => 'Next number: ' . Seq::peek('cs_contract'), 'max' => 30]), 'col-md-2') ?>
      <?= CsUi::field('Client\'s reference no.', CsUi::input('client_ref_no', fv('client_ref_no', $c), ['max' => 60]), 'col-md-2') ?>
      <?= CsUi::field('Contract type', CsUi::select('contract_type', CsContracts::TYPES, fv('contract_type', $c), null, $roSel), 'col-md-3') ?>
      <?= CsUi::field('Title', CsUi::input('title', fv('title', $c), ['required' => true, 'max' => 200]), 'col-md-8') ?>
      <?= CsUi::field('Original contract amount (net of VAT)', CsUi::input('original_amount', fv('original_amount', $c), ['num' => true, 'required' => true, 'extra' => $ro . ' id="kAmt"']), 'col-md-4') ?>
      <?= CsUi::field('Scope of work', CsUi::textarea('scope', fv('scope', $c), 3), 'col-md-6') ?>
      <?= CsUi::field('Description', CsUi::textarea('description', fv('description', $c), 3), 'col-md-6') ?>
      <?= CsUi::field('Participating organizations', CsUi::input('participating_orgs', fv('participating_orgs', $c), ['max' => 255, 'placeholder' => 'Consultant, project management firm, joint-venture partner']), 'col-md-12') ?>
    </div></div></div>

    <div class="card mb-3"><div class="card-header">Dates</div><div class="card-body"><div class="row g-3">
      <?php foreach (['signing_date' => 'Signing date', 'effective_date' => 'Effective date', 'ntp_date' => 'Notice to Proceed', 'commencement_date' => 'Commencement', 'original_completion_date' => 'Original completion', 'revised_completion_date' => 'Revised completion'] as $f => $l): ?>
        <?= CsUi::field($l, CsUi::input($f, fv($f, $c), ['type' => 'date']), 'col-6 col-md-2') ?>
      <?php endforeach; ?>
    </div></div></div>

    <div class="card mb-3"><div class="card-header">Payment, retention and taxes</div><div class="card-body"><div class="row g-3">
      <?= CsUi::field('Advance payment %', CsUi::input('advance_pct', fv('advance_pct', $c), ['num' => true, 'extra' => $ro . ' data-pct-of="#kAmt" data-pct-out="#kAdv"']), 'col-6 col-md-2') ?>
      <?= CsUi::field('Advance amount', '<input class="form-control form-control-sm text-end" id="kAdv" value="' . h(number_format((float)($c['advance_amount'] ?? 0), 2, '.', '')) . '" readonly tabindex="-1">', 'col-6 col-md-2') ?>
      <?= CsUi::field('Recoupment % per billing', CsUi::input('recoupment_pct', fv('recoupment_pct', $c), ['num' => true, 'extra' => $ro]), 'col-6 col-md-2') ?>
      <?= CsUi::field('Retention %', CsUi::input('retention_pct', fv('retention_pct', $c), ['num' => true, 'extra' => $ro]), 'col-6 col-md-2') ?>
      <?= CsUi::field('Retention stops at % of contract', CsUi::input('retention_cap_pct', fv('retention_cap_pct', $c), ['num' => true, 'placeholder' => 'No cap']), 'col-6 col-md-2') ?>
      <?= CsUi::field('Credit terms', CsUi::select('terms_id', Lookup::terms(), fv('terms_id', $c), 'None'), 'col-6 col-md-2') ?>
      <?= CsUi::field('VAT on billings', CsUi::select('tax_code_id', Lookup::taxCodes(), fv('tax_code_id', $c), 'None'), 'col-md-3') ?>
      <div class="col-md-9"><label class="form-label">Taxes the client withholds</label><div class="d-flex flex-wrap gap-3 pt-1">
        <?php foreach (Withholding::forSide('sales') as $w): ?>
          <label class="form-check"><input class="form-check-input" type="checkbox" name="wht_ids[]" value="<?= (int)$w['id'] ?>"<?= checked(in_array((int)$w['id'], $wSel, true)) ?>> <span class="form-check-label"><?= h(Withholding::label($w)) ?></span></label>
        <?php endforeach; ?></div><div class="form-text">Shown on each billing as the expected withholding; nothing is assumed for clients who withhold nothing.</div></div>
      <?= CsUi::field('Payment conditions and milestones', CsUi::textarea('payment_terms', fv('payment_terms', $c), 2), 'col-md-6') ?>
      <?= CsUi::field('Retention release conditions', CsUi::textarea('retention_release_terms', fv('retention_release_terms', $c), 2), 'col-md-6') ?>
    </div></div></div>

    <div class="card mb-3"><div class="card-header">Penalties and warranty</div><div class="card-body"><div class="row g-3">
      <?= CsUi::field('Liquidated damages per day', CsUi::input('ld_per_day', fv('ld_per_day', $c, '0'), ['num' => true]), 'col-6 col-md-2') ?>
      <?= CsUi::field('LD cap % of contract', CsUi::input('ld_cap_pct', fv('ld_cap_pct', $c), ['num' => true, 'placeholder' => 'No cap']), 'col-6 col-md-2') ?>
      <?= CsUi::field('Warranty (months)', CsUi::input('warranty_months', fv('warranty_months', $c), ['type' => 'number', 'step' => '1']), 'col-6 col-md-2') ?>
      <?= CsUi::field('Defect liability (months)', CsUi::input('dlp_months', fv('dlp_months', $c), ['type' => 'number', 'step' => '1']), 'col-6 col-md-2') ?>
      <?= CsUi::field('Other deductions and penalties', CsUi::textarea('penalty_terms', fv('penalty_terms', $c), 2), 'col-md-4') ?>
    </div></div></div>

    <div class="card mb-3"><div class="card-header">Bonds, insurance and permits</div>
      <div class="table-responsive"><table class="table table-sm cs-lines mb-0" id="bondGrid" data-cs-lines data-min-rows="1"><thead><tr><th>Requirement</th><th>Reference no.</th><th>Issuer</th><th class="num">Amount</th><th>Issued</th><th>Expires</th><th>Status</th><th></th></tr></thead>
      <tbody><?php foreach ($bonds as $i => $b) { echo $bondRow($b, 'e' . $i); } ?></tbody></table></div>
      <template data-cs-row="bondGrid"><?= $bondRow([], '__i__') ?></template>
      <div class="card-body pt-2"><button type="button" class="btn btn-outline-secondary btn-sm" data-cs-add="bondGrid"><i class="bi bi-plus"></i> Add a requirement</button></div>
    </div>
    <div class="card"><div class="card-body"><?= CsUi::field('Notes', CsUi::textarea('notes', fv('notes', $c), 2), 'col-12') ?></div></div>
    <div class="sticky-actions mt-3"><?= save_buttons('Save contract', url($pageKey, $k ? ['a' => 'view', 'id' => $k['id']] : []), false) ?></div>
    </form>
    <?php
    layout_end();
    return;
}

// ---------------------------------------------------------------- one contract
if ($a === 'view' && $k) {
    $v = CsContracts::value($k);
    $bonds = DB::all('SELECT * FROM cs_contract_bonds WHERE contract_id = :c ORDER BY expiry_date', ['c' => $id]);
    [$pf, $pp] = CsProject::filter('p.id');
    $projects = DB::all("SELECT p.* FROM cs_projects p WHERE p.contract_id = :c $pf ORDER BY p.code", ['c' => $id] + $pp);
    $allocated = (float)DB::value('SELECT COALESCE(SUM(contract_value),0) FROM cs_projects WHERE contract_id = :c', ['c' => $id]);
    $cos = DB::all('SELECT * FROM cs_change_orders WHERE contract_id = :c ORDER BY id DESC', ['c' => $id]);
    $billed = (float)DB::value("SELECT COALESCE(SUM(this_gross),0) FROM cs_billings WHERE contract_id = :c AND status IN ('invoiced','certified','submitted','approved')", ['c' => $id]);
    $editable = Access::can($pageKey, 'edit') && !in_array($k['status'], ['closed', 'terminated'], true);
    $actions = ($editable ? cs_link($pageKey, ['a' => 'edit', 'id' => $id], 'Edit', 'btn-outline-secondary', 'pencil', 'edit') . ' ' : '')
        . (in_array($k['status'], ['active'], true) ? cs_link('proj.projects', ['a' => 'new', 'contract_id' => $id], 'New project under this contract', 'btn-primary', 'plus-lg', 'create') : '')
        . ' ' . cs_link('proj.clients', ['a' => 'view', 'id' => $k['customer_id']], 'Client', 'btn-outline-secondary', 'person-vcard');
    layout_start($k['contract_no'] . ' ' . $k['title'], ['module' => 'proj', 'actions' => $actions,
        'sub' => h(CsUi::sub([$k['customer_name'], CsContracts::TYPES[$k['contract_type']] ?? '', $k['client_ref_no'] ? 'Client ref. ' . $k['client_ref_no'] : ''])) . ' ' . CsUi::state($k['status'])]);
    echo '<div class="cs-kpis">'
        . CsUi::kpi('Original contract', CsUi::amt($v['original']), 'Net of VAT', 'key')
        . CsUi::kpi('Approved variations', CsUi::amt($v['variations']), count(array_filter($cos, fn($o) => $o['status'] === 'client_approved')) . ' client-approved change order(s)')
        . CsUi::kpi('Revised contract value', CsUi::amt($v['revised']), '')
        . CsUi::kpi('Billed to date', CsUi::amt($billed), $v['revised'] > 0 ? CsUi::pct($billed / $v['revised'] * 100) . ' of the revised value' : '')
        . CsUi::kpi('Allocated to projects', CsUi::amt($allocated), abs($allocated - $v['original']) > 0.5 ? '<span class="cs-warn">' . money($v['original'] - $allocated) . ' not allocated</span>' : 'Fully allocated', abs($allocated - $v['original']) > 0.5 ? 'warn' : '')
        . '</div>';
    echo '<div class="cs-split"><div class="cs-stack">';
    echo '<div class="card"><div class="card-header">Terms</div><div class="card-body">' . CsUi::facts([
        'Signed' => fdate($k['signing_date']), 'Effective' => fdate($k['effective_date']), 'Notice to Proceed' => fdate($k['ntp_date']), 'Commencement' => fdate($k['commencement_date']),
        'Original completion' => fdate($k['original_completion_date']), 'Revised completion' => fdate($k['revised_completion_date']),
        'Advance payment' => CsUi::pct((float)$k['advance_pct'], 2) . ' (' . money($k['advance_amount']) . ')', 'Recoupment per billing' => CsUi::pct((float)$k['recoupment_pct'], 2),
        'Retention' => CsUi::pct((float)$k['retention_pct'], 2) . ($k['retention_cap_pct'] !== null ? ', up to ' . CsUi::pct((float)$k['retention_cap_pct'], 2) . ' of the contract' : ''),
        'Liquidated damages' => (float)$k['ld_per_day'] > 0 ? money($k['ld_per_day']) . ' a day' . ($k['ld_cap_pct'] !== null ? ', cap ' . CsUi::pct((float)$k['ld_cap_pct'], 2) : '') : 'None',
        'Warranty' => (int)$k['warranty_months'] . ' months', 'Defect liability' => (int)$k['dlp_months'] . ' months',
        'VAT' => h((string)DB::value('SELECT name FROM tax_codes WHERE id = :id', ['id' => (int)$k['tax_code_id']])),
        'Client withholds' => h(implode(', ', array_map(fn($w) => Withholding::label($w), array_filter(array_map(fn($x) => Withholding::get((int)$x), array_filter(explode(',', (string)$k['wht_ids']))))))) ?: 'Nothing',
    ]) . '</div>';
    foreach (['scope' => 'Scope of work', 'payment_terms' => 'Payment conditions', 'retention_release_terms' => 'Retention release', 'penalty_terms' => 'Penalties and deductions', 'participating_orgs' => 'Participating organizations', 'notes' => 'Notes'] as $f => $l) {
        if (trim((string)$k[$f]) !== '') {
            echo '<div class="card-body border-top"><div class="cs-note mb-1">' . h($l) . '</div>' . nl2br(h($k[$f])) . '</div>';
        }
    }
    echo '</div>';
    $rows = [];
    foreach ($projects as $p) {
        $cv = CsProject::contractValue((int)$p['id']);
        $rows[] = [CsUi::projectLink((int)$p['id']), CsUi::state($p['status']), CsUi::meter(CsProject::physicalPct((int)$p['id'])), money($cv['original']), money($cv['current'])];
    }
    echo cs_table_card('Projects under this contract', ['Project' => '', 'Status' => '', 'Progress' => '', 'Allocated value' => 'num', 'Current value' => 'num'], $rows,
        $k['status'] === 'active' ? 'No project yet. Create one with "New project under this contract".' : 'Projects are created once the contract is approved.');
    $rows = [];
    foreach ($cos as $o) {
        $rows[] = ['<a href="' . h(url('proj.change_orders', ['a' => 'view', 'id' => $o['id']])) . '">' . h($o['co_no']) . '</a><span class="cs-sub">' . h($o['title']) . '</span>',
            h(CsContracts::CO_TYPES[$o['co_type']] ?? $o['co_type']), money($o['price_impact']), (int)$o['schedule_impact_days'] . ' d', CsUi::state($o['status'])];
    }
    echo cs_table_card('Change orders and claims', ['Change order' => '', 'Type' => '', 'Price impact' => 'num', 'Time' => 'num', 'Status' => ''], $rows, 'No change orders.');
    $rows = [];
    foreach ($bonds as $b) {
        $soon = $b['expiry_date'] && $b['status'] === 'active' && $b['expiry_date'] <= date('Y-m-d', strtotime('+30 days'));
        $rows[] = [h(CsContracts::BOND_KINDS[$b['kind']] ?? $b['kind']), h((string)$b['reference_no']), h((string)$b['issuer']), money($b['amount']),
            '<span class="' . ($soon ? 'cs-bad' : '') . '">' . h(fdate($b['expiry_date'])) . '</span>', CsUi::state($soon && $b['expiry_date'] < today() ? 'expired' : $b['status'])];
    }
    echo cs_table_card('Bonds, insurance and permits', ['Requirement' => '', 'Reference' => '', 'Issuer' => '', 'Amount' => 'num', 'Expires' => '', 'Status' => ''], $rows, 'None recorded.');
    echo '</div><div class="cs-stack">';
    echo CsApproval::panel('contract', $id, Access::can($pageKey, 'edit') && in_array($k['status'], ['draft', 'rejected'], true), 'Submit contract for approval');
    if (Access::can($pageKey, 'edit') && in_array($k['status'], ['active', 'suspended', 'completed'], true)) {
        echo '<div class="card"><div class="card-header">Contract status</div><div class="card-body"><form method="post" class="row g-2">' . csrf_field() . '<input type="hidden" name="act" value="status">'
            . '<div class="col-12">' . CsUi::select('status', ['active' => 'Active', 'suspended' => 'Suspended', 'completed' => 'Completed', 'closed' => 'Closed', 'terminated' => 'Terminated'], $k['status'], null, 'data-no-combo') . '</div>'
            . '<div class="col-12"><input class="form-control form-control-sm" name="reason" placeholder="Reason" required></div><div class="col-12"><button class="btn btn-outline-primary btn-sm">Change status</button></div></form></div></div>';
    }
    echo CsFiles::panel('contract', $id, Access::can($pageKey, 'edit'), 'Signed contract and attachments');
    echo '</div></div>';
    layout_end();
    return;
}

// ---------------------------------------------------------------- the list
$q = (string)g('q');
$st = (string)g('status');
[$w, $params] = cs_like($q, ['k.contract_no', 'k.title', 'c.name', 'k.client_ref_no']);
if ($st !== '' && isset(CsContracts::STATUSES[$st])) {
    $w .= ' AND k.status = :st';
    $params['st'] = $st;
}
if (gint('customer_id')) {
    $w .= ' AND k.customer_id = :cu';
    $params['cu'] = gint('customer_id');
}
$rows = DB::all("SELECT k.*, c.name AS customer_name, (SELECT COUNT(*) FROM cs_projects p WHERE p.contract_id = k.id) AS projects
                   FROM cs_contracts k JOIN customers c ON c.id = k.customer_id WHERE 1 = 1 $w ORDER BY k.id DESC LIMIT 500", $params);
layout_start('Contracts', ['actions' => cs_link($pageKey, ['a' => 'new'], 'New contract', 'btn-primary', 'plus-lg', 'create')]);
echo '<form class="cs-toolbar" method="get"><input type="hidden" name="p" value="' . h($pageKey) . '">' . cs_search_field('Contract no., title, client') . cs_status_field(CsContracts::STATUSES)
    . '<div><button class="btn btn-outline-secondary btn-sm">Show</button></div></form>';
$out = [];
$tot = [0.0, 0.0, 0.0];
foreach ($rows as $r) {
    $v = CsContracts::value($r);
    $tot[0] += $v['original'];
    $tot[1] += $v['variations'];
    $tot[2] += $v['revised'];
    $out[] = ['<a class="cs-main-link" href="' . h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) . '">' . h($r['contract_no']) . '</a><span class="cs-sub">' . h($r['title']) . '</span>',
        h($r['customer_name']), h(CsContracts::TYPES[$r['contract_type']] ?? ''), money($v['original']), money($v['variations']), money($v['revised']), (int)$r['projects'],
        h(fdate($r['revised_completion_date'] ?: $r['original_completion_date'])), CsUi::state($r['status'])];
}
echo cs_table_card(count($rows) . ' contract' . (count($rows) === 1 ? '' : 's'), ['Contract' => '', 'Client' => '', 'Type' => '', 'Original' => 'num', 'Variations' => 'num', 'Revised' => 'num', 'Projects' => 'num', 'Completion' => '', 'Status' => ''],
    $out, 'No contracts yet.', '', $out ? '<tfoot><tr><td colspan="3">Total</td><td class="num">' . money($tot[0]) . '</td><td class="num">' . money($tot[1]) . '</td><td class="num">' . money($tot[2]) . '</td><td colspan="3"></td></tr></tfoot>' : '');
layout_end();
