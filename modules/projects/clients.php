<?php
declare(strict_types=1);

/**
 * Clients: the construction view of the customer list. A client is an accounting customer, so its invoices,
 * payments and statements are the ones in Customers; here it also carries its classification, contracts and projects.
 */
$pageKey = 'proj.clients';
$a = (string)g('a', 'list');
$id = gint('id');
$whtAll = Withholding::ready() ? Withholding::forSide('sales') : [];

handle_post(function () use ($pageKey, $a, $id) {
    if (cs_handle_attach('client', (int)$id, null)) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if (p('act') === 'note' && $id) {
        Access::require($pageKey, 'edit');
        $note = trim((string)p('note'));
        if ($note === '') {
            fail('Write the note first.');
        }
        DB::insert('entity_notes', ['entity_type' => 'customer', 'entity_id' => $id, 'note' => $note, 'is_todo' => p('is_todo') === '1' ? 1 : 0,
            'due_date' => pdate('due_date'), 'created_by' => Auth::id()]);
        Audit::log('note', 'proj', 'customer', $id, 'Communication note', ['reason' => mb_substr($note, 0, 200)]);
        flash('success', 'Note added to the client\'s history.');
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if (p('act') === 'save') {
        Access::require($pageKey, $id ? 'edit' : 'create');
        $row = [
            'name' => trim((string)p('name')), 'company_name' => trim((string)p('company_name')) ?: null, 'contact_person' => trim((string)p('contact_person')) ?: null,
            'phone' => trim((string)p('phone')) ?: null, 'mobile' => trim((string)p('mobile')) ?: null, 'email' => trim((string)p('email')) ?: null,
            'billing_address' => trim((string)p('billing_address')) ?: null, 'shipping_address' => trim((string)p('shipping_address')) ?: null,
            'tin' => trim((string)p('tin')) ?: null, 'terms_id' => pint('terms_id'), 'credit_limit' => r2(pnum('credit_limit')),
            'customer_type_id' => pint('customer_type_id'), 'client_class' => array_key_exists((string)p('client_class'), CsContracts::CLIENT_CLASSES) ? p('client_class') : null,
            'tax_code_id' => pint('tax_code_id'), 'wht_ids' => implode(',', array_map('intval', (array)($_POST['wht_ids'] ?? []))) ?: null,
            'notes' => trim((string)p('notes')) ?: null, 'is_active' => p('is_active', '1') === '1' ? 1 : 0,
        ];
        if ($row['name'] === '') {
            fail('Enter the client name.');
        }
        $dupe = DB::value('SELECT id FROM customers WHERE name = :n AND parent_id IS NULL' . ($id ? ' AND id <> :id' : ''), ['n' => $row['name']] + ($id ? ['id' => $id] : []));
        if ($dupe) {
            fail('There is already a client or customer named ' . $row['name'] . '.');
        }
        if ($id) {
            $old = DB::row('SELECT * FROM customers WHERE id = :id', ['id' => $id]);
            DB::update('customers', $row, 'id = :id', ['id' => $id]);
            Audit::log('edit', 'proj', 'customer', $id, $row['name'], ['page_key' => $pageKey, 'before' => $old, 'after' => DB::row('SELECT * FROM customers WHERE id = :id', ['id' => $id])]);
        } else {
            $id = DB::insert('customers', $row);
            Audit::log('create', 'proj', 'customer', $id, $row['name'], ['page_key' => $pageKey]);
        }
        flash('success', 'Client saved.');
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
});

// ---------------------------------------------------------------- form
if ($a === 'new' || $a === 'edit') {
    Access::require($pageKey, $a === 'new' ? 'create' : 'edit');
    $c = $id ? DB::row('SELECT * FROM customers WHERE id = :id', ['id' => $id]) : ['is_active' => 1, 'client_class' => 'private', 'tax_code_id' => DB::value("SELECT id FROM tax_codes WHERE code = 'VAT12'")];
    if ($id && !$c) {
        cs_go($pageKey);
    }
    $wSel = array_map('intval', explode(',', (string)fv('wht_ids', $c, '')));
    layout_start($id ? 'Edit client' : 'New client', ['module' => 'proj', 'sub' => $id ? h($c['name']) : 'A client is also a customer in accounting: its invoices, payments and statements live there.']);
    ?>
    <form method="post" class="card"><div class="card-body"><?= csrf_field() ?><input type="hidden" name="act" value="save">
      <div class="row g-3">
        <?= CsUi::field('Client name', CsUi::input('name', fv('name', $c), ['required' => true, 'max' => 120]), 'col-md-6') ?>
        <?= CsUi::field('Registered company name', CsUi::input('company_name', fv('company_name', $c), ['max' => 120]), 'col-md-6') ?>
        <?= CsUi::field('Classification', CsUi::select('client_class', CsContracts::CLIENT_CLASSES, fv('client_class', $c), null), 'col-md-3') ?>
        <?= CsUi::field('Customer type', CsUi::select('customer_type_id', Lookup::customerTypes(), fv('customer_type_id', $c), 'None'), 'col-md-3') ?>
        <?= CsUi::field('TIN', CsUi::input('tin', fv('tin', $c), ['max' => 30]), 'col-md-3') ?>
        <?= CsUi::field('Credit terms', CsUi::select('terms_id', Lookup::terms(), fv('terms_id', $c), 'None'), 'col-md-3') ?>
        <?= CsUi::field('Contact person', CsUi::input('contact_person', fv('contact_person', $c), ['max' => 120]), 'col-md-4') ?>
        <?= CsUi::field('Phone', CsUi::input('phone', fv('phone', $c), ['max' => 40]), 'col-md-2') ?>
        <?= CsUi::field('Mobile', CsUi::input('mobile', fv('mobile', $c), ['max' => 40]), 'col-md-2') ?>
        <?= CsUi::field('E-mail', CsUi::input('email', fv('email', $c), ['type' => 'email', 'max' => 120]), 'col-md-4') ?>
        <?= CsUi::field('Billing address', CsUi::input('billing_address', fv('billing_address', $c), ['max' => 255]), 'col-md-6') ?>
        <?= CsUi::field('Site / delivery address', CsUi::input('shipping_address', fv('shipping_address', $c), ['max' => 255]), 'col-md-6') ?>
        <?= CsUi::field('Credit limit', CsUi::input('credit_limit', fv('credit_limit', $c, '0'), ['num' => true]), 'col-md-3', '0 means no limit.') ?>
        <?= CsUi::field('VAT on billings', CsUi::select('tax_code_id', Lookup::taxCodes(), fv('tax_code_id', $c), 'None'), 'col-md-3') ?>
        <div class="col-md-6"><label class="form-label">Taxes this client withholds from its payments</label>
          <div class="d-flex flex-wrap gap-3 pt-1">
          <?php foreach ($whtAll as $w): ?>
            <label class="form-check"><input class="form-check-input" type="checkbox" name="wht_ids[]" value="<?= (int)$w['id'] ?>"<?= checked(in_array((int)$w['id'], $wSel, true)) ?>> <span class="form-check-label"><?= h(Withholding::label($w)) ?></span></label>
          <?php endforeach; ?>
          </div>
          <div class="form-text">Government agencies usually withhold 5% VAT and 2% income tax. Received payments apply them automatically.</div></div>
        <?= CsUi::field('Notes', CsUi::textarea('notes', fv('notes', $c), 2), 'col-12') ?>
        <?= check_field('is_active', 'Active', (int)fv('is_active', $c, 1) === 1, 'col-md-3') ?>
      </div>
    </div><div class="card-footer"><?= save_buttons('Save client', url($pageKey, $id ? ['a' => 'view', 'id' => $id] : []), false) ?></div></form>
    <?php
    layout_end();
    return;
}

// ---------------------------------------------------------------- one client
if ($a === 'view' && $id) {
    $c = DB::row('SELECT c.*, t.name AS terms_name, ct.name AS type_name FROM customers c LEFT JOIN terms t ON t.id = c.terms_id LEFT JOIN customer_types ct ON ct.id = c.customer_type_id WHERE c.id = :id', ['id' => $id]);
    if (!$c) {
        cs_go($pageKey);
    }
    $contracts = DB::all('SELECT * FROM cs_contracts WHERE customer_id = :c ORDER BY contract_no', ['c' => $id]);
    [$pf, $pp] = CsProject::filter('p.id');
    $projects = DB::all("SELECT p.* FROM cs_projects p WHERE p.customer_id = :c $pf ORDER BY p.code", ['c' => $id] + $pp);
    $bal = Balances::customerBalance($id);
    $ret = (float)DB::value('SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE account_id = :a AND customer_id = :c', ['a' => Accts::get('cs_retention_receivable'), 'c' => $id]);
    $adv = -(float)DB::value('SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE account_id = :a AND customer_id = :c', ['a' => Accts::get('cs_client_advances'), 'c' => $id]);
    $paid12 = (float)DB::value("SELECT COALESCE(SUM(total),0) FROM transactions WHERE txn_type = 'payment' AND customer_id = :c AND is_voided = 0 AND txn_date >= :d", ['c' => $id, 'd' => date('Y-m-d', strtotime('-12 months'))]);
    $open = Balances::openReceivables($id);
    $notes = DB::all("SELECT n.*, u.full_name FROM entity_notes n LEFT JOIN users u ON u.id = n.created_by WHERE n.entity_type = 'customer' AND n.entity_id = :c ORDER BY n.id DESC LIMIT 30", ['c' => $id]);
    $actions = cs_link($pageKey, ['a' => 'edit', 'id' => $id], 'Edit', 'btn-outline-secondary', 'pencil', 'edit')
        . ' ' . cs_link('proj.contracts', ['a' => 'new', 'customer_id' => $id], 'New contract', 'btn-primary', 'plus-lg', 'create')
        . ' ' . cs_link('cust.statements', ['customer_id' => $id], 'Statement of account', 'btn-outline-secondary', 'file-text')
        . ' ' . cs_link('cust.payments', ['customer_id' => $id], 'Receive payment', 'btn-outline-secondary', 'cash-coin', 'create');
    layout_start($c['name'], ['module' => 'proj', 'actions' => $actions,
        'sub' => h(CsUi::sub([CsContracts::CLIENT_CLASSES[$c['client_class'] ?? ''] ?? 'Unclassified', $c['type_name'], $c['tin'] ? 'TIN ' . $c['tin'] : '', (int)$c['is_active'] ? '' : 'Inactive']))]);
    echo '<div class="cs-kpis">'
        . CsUi::kpi('Outstanding balance', CsUi::amt($bal), count($open) . ' open document(s)', $bal > 0 ? '' : 'ok', Access::page('rpt.customer_balance_detail') ? url('rpt.customer_balance_detail', ['customer_id' => $id]) : null)
        . CsUi::kpi('Retention receivable', CsUi::amt($ret), 'Held by the client until release')
        . CsUi::kpi('Unrecouped advances', CsUi::amt($adv), 'Mobilization still to be recouped')
        . CsUi::kpi('Collected, last 12 months', CsUi::amt($paid12), '')
        . CsUi::kpi('Credit terms', h($c['terms_name'] ?: 'None'), (float)$c['credit_limit'] > 0 ? 'Limit ' . money($c['credit_limit']) : 'No credit limit')
        . '</div>';
    echo '<div class="cs-split"><div class="cs-stack">';
    $rows = [];
    foreach ($contracts as $k) {
        $v = CsContracts::value($k);
        $rows[] = ['<a class="cs-main-link" href="' . h(url('proj.contracts', ['a' => 'view', 'id' => $k['id']])) . '">' . h($k['contract_no']) . '</a><span class="cs-sub">' . h($k['title']) . '</span>',
            h(CsContracts::TYPES[$k['contract_type']] ?? $k['contract_type']), money($v['original']), money($v['variations']), money($v['revised']), CsUi::state($k['status'])];
    }
    echo cs_table_card('Contracts', ['Contract' => '', 'Type' => '', 'Original' => 'num', 'Variations' => 'num', 'Revised' => 'num', 'Status' => ''], $rows, 'No contract with this client yet.');
    $rows = [];
    foreach ($projects as $p) {
        $rows[] = [CsUi::projectLink((int)$p['id']), CsUi::state($p['status']), CsUi::meter(CsProject::physicalPct((int)$p['id'])), money(CsProject::contractValue((int)$p['id'])['current'])];
    }
    echo cs_table_card('Projects', ['Project' => '', 'Status' => '', 'Progress' => '', 'Contract value' => 'num'], $rows, 'No project for this client yet.');
    $rows = [];
    foreach (array_slice($open, 0, 15) as $d) {
        $rows[] = [Txn::link($d), fdate($d['txn_date']), fdate($d['due_date']), money($d['total']), money($d['open_balance'])];
    }
    echo cs_table_card('Open invoices', ['Document' => '', 'Date' => '', 'Due' => '', 'Amount' => 'num', 'Open' => 'num'], $rows, 'Nothing is owed.');
    echo '</div><div class="cs-stack">';
    echo '<div class="card"><div class="card-header">Contact</div><div class="card-body">' . CsUi::facts([
        'Contact person' => h((string)$c['contact_person']), 'Phone' => h(trim($c['phone'] . ' ' . $c['mobile'])), 'E-mail' => $c['email'] ? '<a href="mailto:' . h($c['email']) . '">' . h($c['email']) . '</a>' : '',
        'Billing address' => h((string)$c['billing_address']), 'Site address' => h((string)$c['shipping_address']),
    ]) . '</div></div>';
    // communication history
    echo '<div class="card"><div class="card-header">Communication history</div><div class="card-body">';
    if (Access::can($pageKey, 'edit')) {
        echo '<form method="post" class="mb-3">' . csrf_field() . '<input type="hidden" name="act" value="note"><textarea class="form-control form-control-sm mb-2" name="note" rows="2" placeholder="Call, meeting, letter or e-mail with the client"></textarea>'
            . '<div class="d-flex gap-2 align-items-center"><label class="form-check small"><input class="form-check-input" type="checkbox" name="is_todo" value="1"> <span class="form-check-label">Follow up by</span></label>'
            . '<input class="form-control form-control-sm" style="max-width:150px" type="date" name="due_date"><button class="btn btn-outline-primary btn-sm ms-auto">Add</button></div></form>';
    }
    if (!$notes) {
        echo '<p class="cs-note mb-0">No notes yet.</p>';
    }
    echo '<ul class="cs-trail">';
    foreach ($notes as $n) {
        echo '<li class="' . ((int)$n['is_todo'] && !(int)$n['is_done'] ? 'is-now' : '') . '"><div class="what">' . nl2br(h($n['note'])) . '</div><div class="when">' . h(fdatetime($n['created_at'])) . ', ' . h((string)$n['full_name'])
            . ($n['due_date'] ? ', follow up by ' . h(fdate($n['due_date'])) : '') . '</div></li>';
    }
    echo '</ul></div></div>';
    echo CsFiles::panel('client', $id, Access::can($pageKey, 'edit'), 'Client documents');
    echo '</div></div>';
    layout_end();
    return;
}

// ---------------------------------------------------------------- the list
$q = (string)g('q');
$cls = (string)g('class');
[$lw, $lp] = cs_like($q, ['c.name', 'c.company_name', 'c.contact_person', 'c.tin']);
$w = $lw;
$params = $lp;
if ($cls !== '' && isset(CsContracts::CLIENT_CLASSES[$cls])) {
    $w .= ' AND c.client_class = :cls';
    $params['cls'] = $cls;
}
$rows = DB::all("SELECT c.*, (SELECT COUNT(*) FROM cs_contracts k WHERE k.customer_id = c.id AND k.status NOT IN ('rejected')) AS contracts,
                        (SELECT COALESCE(SUM(k.original_amount + k.approved_variations),0) FROM cs_contracts k WHERE k.customer_id = c.id AND k.status IN ('active','completed','suspended')) AS contract_value,
                        (SELECT COUNT(*) FROM cs_projects p WHERE p.customer_id = c.id AND p.status <> 'closed') AS projects
                   FROM customers c WHERE c.parent_id IS NULL AND (c.client_class IS NOT NULL OR EXISTS (SELECT 1 FROM cs_contracts k WHERE k.customer_id = c.id) OR :all = 1) $w
                  ORDER BY c.is_active DESC, c.name LIMIT 500", ['all' => g('all') === '1' ? 1 : 0] + $params);
$actions = cs_link($pageKey, ['a' => 'new'], 'New client', 'btn-primary', 'plus-lg', 'create');
layout_start('Clients', ['actions' => $actions]);
?>
<form class="cs-toolbar" method="get"><input type="hidden" name="p" value="<?= h($pageKey) ?>">
  <?= cs_search_field('Name, contact, TIN') ?>
  <?= cs_status_field(CsContracts::CLIENT_CLASSES, 'class', 'Classification') ?>
  <div class="d-flex align-items-end pb-1"><label class="form-check small"><input class="form-check-input" type="checkbox" name="all" value="1"<?= checked(g('all') === '1') ?> onchange="this.form.submit()"> <span class="form-check-label">Include every customer</span></label></div>
  <div><button class="btn btn-outline-secondary btn-sm">Show</button></div>
</form>
<?php
$out = [];
$totBal = 0.0;
foreach ($rows as $c) {
    $bal = Balances::customerBalance((int)$c['id']);
    $totBal += $bal;
    $out[] = ['<a class="cs-main-link" href="' . h(url($pageKey, ['a' => 'view', 'id' => $c['id']])) . '">' . h($c['name']) . '</a>' . ((int)$c['is_active'] ? '' : ' <span class="cs-state is-muted">Inactive</span>')
        . '<span class="cs-sub">' . h((string)$c['contact_person']) . '</span>',
        h(CsContracts::CLIENT_CLASSES[$c['client_class'] ?? ''] ?? 'Unclassified'), (int)$c['contracts'], money($c['contract_value']), (int)$c['projects'], money($bal)];
}
echo cs_table_card(count($rows) . ' client' . (count($rows) === 1 ? '' : 's'), ['Client' => '', 'Classification' => '', 'Contracts' => 'num', 'Contract value' => 'num', 'Open projects' => 'num', 'Balance' => 'num'],
    $out, 'No client matches. A new client is added with New client.', '', $out ? '<tfoot><tr><td colspan="5">Total balance</td><td class="num">' . money($totBal) . '</td></tr></tfoot>' : '');
layout_end();
