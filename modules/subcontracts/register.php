<?php
declare(strict_types=1);

/**
 * Subcontractor register: the subcontractors (suppliers in accounting, marked as subcontractors) with their trades,
 * accreditation, qualifications, rating, subcontracts, billings and retention.
 */
$pageKey = 'subk.register';
$a = (string)g('a', 'list');
$id = gint('id');
$v = $id ? DB::row('SELECT * FROM vendors WHERE id = :id', ['id' => $id]) : null;

handle_post(function () use ($pageKey, $id, $v) {
    if ($v && cs_handle_attach('subcontractor', (int)$id, null)) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    Access::require($pageKey, $id ? 'edit' : 'create');
    $row = ['vendor_kind' => in_array(p('vendor_kind'), ['subcontractor', 'both', 'service'], true) ? p('vendor_kind') : 'subcontractor', 'service_categories' => mb_substr(trim((string)p('service_categories')), 0, 255) ?: null,
        'accreditation_no' => mb_substr(trim((string)p('accreditation_no')), 0, 60) ?: null, 'accreditation_expiry' => pdate('accreditation_expiry'), 'qualifications' => trim((string)p('qualifications')) ?: null,
        'rating' => (int)p('rating') >= 1 && (int)p('rating') <= 5 ? (int)p('rating') : null];
    if (!$v) {
        $row += ['name' => mb_substr(trim((string)p('name')), 0, 120), 'contact_person' => mb_substr(trim((string)p('contact_person')), 0, 120) ?: null, 'phone' => mb_substr(trim((string)p('phone')), 0, 40) ?: null,
            'email' => mb_substr(trim((string)p('email')), 0, 120) ?: null, 'address' => mb_substr(trim((string)p('address')), 0, 255) ?: null, 'tin' => mb_substr(trim((string)p('tin')), 0, 30) ?: null,
            'terms_id' => pint('terms_id'), 'tax_code_id' => pint('tax_code_id'), 'wht_ids' => implode(',', array_map('intval', (array)($_POST['wht_ids'] ?? []))) ?: null, 'is_active' => 1];
        if ($row['name'] === '') {
            fail('Enter the subcontractor\'s name.');
        }
        if (DB::value('SELECT id FROM vendors WHERE name = :n', ['n' => $row['name']])) {
            fail('A supplier named ' . $row['name'] . ' already exists. Open it from the list and mark it as a subcontractor.');
        }
        $id = DB::insert('vendors', $row);
        Audit::log('create', 'subk', 'vendor', $id, $row['name'], ['page_key' => $pageKey, 'reason' => 'Subcontractor added']);
    } else {
        DB::update('vendors', $row + ['updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
        Audit::log('edit', 'subk', 'vendor', (int)$id, $v['name'], ['page_key' => $pageKey, 'before' => $v, 'after' => $row]);
    }
    flash('success', 'Subcontractor saved.');
    cs_go($pageKey, ['a' => 'view', 'id' => $id]);
});

if ($a === 'new' || ($a === 'edit' && $v)) {
    Access::require($pageKey, $v ? 'edit' : 'create');
    $c = $v ?: ['vendor_kind' => 'subcontractor', 'tax_code_id' => DB::value("SELECT id FROM tax_codes WHERE code = 'VAT12'"), 'wht_ids' => (string)DB::value("SELECT id FROM withholding_taxes WHERE code = 'WT-CONTRACTOR'")];
    $wSel = array_map('intval', explode(',', (string)($c['wht_ids'] ?? '')));
    layout_start($v ? 'Edit ' . $v['name'] : 'New subcontractor', ['module' => 'subk', 'sub' => 'Subcontractors are suppliers in accounting: their bills, payments and withholding taxes are kept there.']);
    echo '<form method="post"><div class="card mb-3"><div class="card-body"><div class="row g-3">' . csrf_field();
    if (!$v) {
        echo CsUi::field('Name', CsUi::input('name', fv('name', $c), ['required' => true, 'max' => 120]), 'col-md-6') . CsUi::field('TIN', CsUi::input('tin', fv('tin', $c), ['max' => 30]), 'col-md-3')
            . CsUi::field('Contact person', CsUi::input('contact_person', fv('contact_person', $c), ['max' => 120]), 'col-md-3') . CsUi::field('Phone', CsUi::input('phone', fv('phone', $c), ['max' => 40]), 'col-md-3')
            . CsUi::field('Email', CsUi::input('email', fv('email', $c), ['type' => 'email', 'max' => 120]), 'col-md-3') . CsUi::field('Address', CsUi::input('address', fv('address', $c), ['max' => 255]), 'col-md-6')
            . CsUi::field('Terms', CsUi::select('terms_id', Lookup::terms(), fv('terms_id', $c), 'None'), 'col-md-3') . CsUi::field('VAT on their bills', CsUi::select('tax_code_id', Lookup::taxCodes(), fv('tax_code_id', $c), 'None'), 'col-md-3')
            . '<div class="col-md-6"><label class="form-label">Taxes we withhold</label><div class="d-flex flex-wrap gap-3 pt-1">';
        foreach (Withholding::forSide('purchases') as $w) {
            echo '<label class="form-check"><input class="form-check-input" type="checkbox" name="wht_ids[]" value="' . (int)$w['id'] . '"' . checked(in_array((int)$w['id'], $wSel, true)) . '> <span class="form-check-label">' . h(Withholding::label($w)) . '</span></label>';
        }
        echo '</div></div>';
    } else {
        echo '<div class="col-12">' . h($v['name']) . ' ' . page_link('vend.center', ['a' => 'edit', 'id' => $id], 'Edit contact, tax and terms in Suppliers', 'btn-outline-secondary btn-sm', 'pencil', 'edit') . '</div>';
    }
    echo CsUi::field('Kind', CsUi::select('vendor_kind', ['subcontractor' => 'Subcontractor', 'both' => 'Supplier and subcontractor', 'service' => 'Service provider / labor agency'], fv('vendor_kind', $c), null, 'data-no-combo'), 'col-md-3')
        . CsUi::field('Trades and services', CsUi::input('service_categories', fv('service_categories', $c), ['max' => 255, 'placeholder' => 'Electrical, plumbing, waterproofing']), 'col-md-5')
        . CsUi::field('Rating (1 to 5)', CsUi::select('rating', [1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5'], fv('rating', $c), 'Not rated', 'data-no-combo'), 'col-md-2')
        . CsUi::field('PCAB licence / accreditation no.', CsUi::input('accreditation_no', fv('accreditation_no', $c), ['max' => 60]), 'col-md-3') . CsUi::field('Expires', CsUi::input('accreditation_expiry', fv('accreditation_expiry', $c), ['type' => 'date']), 'col-md-3')
        . CsUi::field('Qualifications, experience, equipment', CsUi::textarea('qualifications', fv('qualifications', $c), 3), 'col-12')
        . '</div></div></div><div class="sticky-actions">' . save_buttons('Save', url($pageKey, $v ? ['a' => 'view', 'id' => $id] : []), false) . '</div></form>';
    layout_end();
    return;
}

if ($a === 'view' && $v) {
    $scs = DB::all('SELECT s.*, p.code FROM cs_subcontracts s JOIN cs_projects p ON p.id = s.project_id WHERE s.vendor_id = :v ORDER BY s.id DESC', ['v' => $id]);
    $tot = ['value' => 0.0, 'billed' => 0.0, 'held' => 0.0];
    $rows = [];
    foreach ($scs as $s) {
        $pos = CsSub::position((int)$s['id']);
        $tot['value'] += CsSub::value($s);
        $tot['billed'] += $pos['billed'];
        $tot['held'] += $pos['retained'] - $pos['released'];
        $rows[] = ['<a href="' . h(url('subk.contracts', ['a' => 'view', 'id' => $s['id']])) . '">' . h($s['sc_no']) . '</a><span class="cs-sub">' . h($s['work_package']) . '</span>', h($s['code']), money(CsSub::value($s)),
            CsSub::value($s) > 0 ? CsUi::meter($pos['billed'] / CsSub::value($s) * 100) : '', money($pos['retained'] - $pos['released']), CsUi::state($s['status'])];
    }
    $ap = array_sum(array_map(fn($b) => Balances::open($b), DB::all("SELECT * FROM transactions WHERE txn_type = 'bill' AND vendor_id = :v AND is_voided = 0 AND status IN ('open','partial')", ['v' => $id])));
    $ncr = (int)DB::value("SELECT COUNT(*) FROM cs_ncrs WHERE responsible_party = :n", ['n' => $v['name']]);
    $expired = $v['accreditation_expiry'] && $v['accreditation_expiry'] < today();
    layout_start($v['name'], ['module' => 'subk', 'sub' => h(CsUi::sub([$v['service_categories'], $v['accreditation_no'] ? 'Licence ' . $v['accreditation_no'] : ''])) . ($expired ? ' <span class="cs-state is-bad">Accreditation expired</span>' : ''),
        'actions' => cs_link($pageKey, ['a' => 'edit', 'id' => $id], 'Edit', 'btn-outline-secondary', 'pencil', 'edit') . ' ' . cs_link('subk.contracts', ['a' => 'new', 'vendor' => $id], 'New subcontract', 'btn-primary', 'plus-lg', 'create')]);
    echo '<div class="cs-kpis">' . CsUi::kpi('Subcontract value', CsUi::amt($tot['value']), count($scs) . ' subcontract(s)', 'key') . CsUi::kpi('Billed', CsUi::amt($tot['billed']), '')
        . CsUi::kpi('Retention held', CsUi::amt($tot['held']), 'Released after acceptance') . CsUi::kpi('Unpaid bills', CsUi::amt($ap), '') . CsUi::kpi('Rating', $v['rating'] ? (int)$v['rating'] . ' of 5' : 'Not rated', $ncr ? $ncr . ' non-conformance(s)' : '', $ncr ? 'warn' : '') . '</div>';
    echo '<div class="cs-split"><div class="cs-stack">' . cs_table_card('Subcontracts', ['Subcontract' => '', 'Project' => '', 'Value' => 'num', 'Billed' => '', 'Retention held' => 'num', 'Status' => ''], $rows, 'No subcontracts yet.');
    echo '</div><div class="cs-stack"><div class="card"><div class="card-body">' . CsUi::facts(['Contact' => h(CsUi::sub([$v['contact_person'], $v['phone'], $v['email']])), 'TIN' => h((string)$v['tin']),
        'Accreditation' => h((string)$v['accreditation_no']) . ($v['accreditation_expiry'] ? ', expires <span class="' . ($expired ? 'cs-bad' : '') . '">' . h(fdate($v['accreditation_expiry'])) . '</span>' : ''),
        'Qualifications' => nl2br(h((string)$v['qualifications']))]) . '<div class="mt-2">' . page_link('vend.center', ['id' => $id], 'Supplier ledger in accounting', 'btn-outline-secondary', 'journal-text') . '</div></div></div>'
        . CsFiles::panel('subcontractor', $id, Access::can($pageKey, 'edit'), 'Licences, permits, insurance') . '</div></div>';
    layout_end();
    return;
}

layout_start('Subcontractor Register', ['actions' => cs_link($pageKey, ['a' => 'new'], 'New subcontractor', 'btn-primary', 'plus-lg', 'create')]);
echo '<form class="cs-toolbar" method="get"><input type="hidden" name="p" value="' . h($pageKey) . '">' . cs_search_field('Name, trade') . '<div><button class="btn btn-outline-secondary btn-sm">Show</button></div></form>';
[$w, $params] = cs_like((string)g('q'), ['v.name', 'v.service_categories']);
$rows = DB::all("SELECT v.*, (SELECT COUNT(*) FROM cs_subcontracts s WHERE s.vendor_id = v.id AND s.status = 'active') AS active_sc,
                        (SELECT COALESCE(SUM(s.contract_amount + s.approved_variations),0) FROM cs_subcontracts s WHERE s.vendor_id = v.id AND s.status IN ('active','completed','closed')) AS value
                   FROM vendors v WHERE v.vendor_kind IN ('subcontractor','both','service') AND v.is_active = 1 $w ORDER BY v.name LIMIT 500", $params);
$out = [];
foreach ($rows as $r) {
    $exp = $r['accreditation_expiry'] && $r['accreditation_expiry'] < today();
    $soon = !$exp && $r['accreditation_expiry'] && $r['accreditation_expiry'] <= date('Y-m-d', strtotime('+60 days'));
    $out[] = ['<a class="cs-main-link" href="' . h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) . '">' . h($r['name']) . '</a>', h((string)$r['service_categories']), h((string)$r['accreditation_no']),
        '<span class="' . ($exp ? 'cs-bad' : ($soon ? 'cs-warn' : '')) . '">' . h(fdate($r['accreditation_expiry'])) . '</span>', $r['rating'] ? (int)$r['rating'] . ' / 5' : '', (int)$r['active_sc'], money($r['value'])];
}
echo cs_table_card(count($rows) . ' subcontractors', ['Subcontractor' => '', 'Trades' => '', 'Licence' => '', 'Expires' => '', 'Rating' => '', 'Active' => 'num', 'Contracted' => 'num'], $out, 'No subcontractors yet.');
layout_end();
