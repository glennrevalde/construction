<?php
declare(strict_types=1);

/** Leads and tenders: opportunities, bid / no-bid decisions, bid security and the tender checklist; won ones become projects. */
$pageKey = 'plan.opportunities';
$a = (string)g('a', 'list');
$id = gint('id');
$o = $id ? DB::row('SELECT o.*, c.name AS customer_name, u.full_name AS owner_name FROM cs_opportunities o LEFT JOIN customers c ON c.id = o.customer_id LEFT JOIN users u ON u.id = o.owner_user_id WHERE o.id = :id', ['id' => $id]) : null;
$statuses = ['lead' => 'Lead', 'qualifying' => 'Qualifying', 'bidding' => 'Preparing the bid', 'submitted' => 'Bid submitted', 'won' => 'Won', 'lost' => 'Lost', 'no_bid' => 'No bid'];

handle_post(function () use ($pageKey, $id, $o, $statuses) {
    if (p('act') === 'save') {
        Access::require($pageKey, $id ? 'edit' : 'create');
        $checks = [];
        foreach (preg_split('/\R/', (string)p('checklist_items')) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $checks[] = (in_array($line, (array)($_POST['checked'] ?? []), true) ? '[x] ' : '[ ] ') . preg_replace('/^\[[x ]\]\s*/', '', $line);
            }
        }
        $row = ['name' => trim((string)p('name')), 'customer_id' => pint('customer_id'), 'prospect_name' => trim((string)p('prospect_name')) ?: null,
            'source' => trim((string)p('source')) ?: null, 'location' => trim((string)p('location')) ?: null, 'est_value' => r2(pnum('est_value')), 'est_cost' => r2(pnum('est_cost')),
            'probability_pct' => max(0, min(100, pnum('probability_pct'))), 'bid_due_date' => pdate('bid_due_date'),
            'bid_security_amount' => r2(pnum('bid_security_amount')), 'bid_security_ref' => trim((string)p('bid_security_ref')) ?: null, 'bid_security_expiry' => pdate('bid_security_expiry'),
            'owner_user_id' => pint('owner_user_id'), 'notes' => trim((string)p('notes')) ?: null, 'checklist' => $checks ? implode("\n", $checks) : null];
        if ($row['name'] === '') {
            fail('Enter the name of the opportunity.');
        }
        if (!$row['customer_id'] && !$row['prospect_name']) {
            fail('Choose the client, or type the prospect\'s name.');
        }
        if ($id) {
            $row['updated_at'] = date('Y-m-d H:i:s');
            DB::update('cs_opportunities', $row, 'id = :id', ['id' => $id]);
        } else {
            $row += ['opp_no' => Seq::next('cs_opportunity'), 'status' => 'lead', 'created_by' => Auth::id()];
            $id = DB::insert('cs_opportunities', $row);
        }
        Audit::log($o ? 'edit' : 'create', 'plan', 'opportunity', $id, $row['name'], ['page_key' => $pageKey, 'amount' => $row['est_value']]);
        flash('success', 'Saved.');
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if (!$o) {
        return;
    }
    Access::require($pageKey, 'edit');
    if (p('act') === 'decide') {
        $dec = p('bid_decision') === 'bid' ? 'bid' : 'no_bid';
        DB::update('cs_opportunities', ['bid_decision' => $dec, 'decision_notes' => trim((string)p('decision_notes')) ?: null, 'status' => $dec === 'bid' ? 'bidding' : 'no_bid',
            'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
        Audit::log('decide', 'plan', 'opportunity', $id, $o['name'], ['page_key' => $pageKey, 'reason' => ($dec === 'bid' ? 'Bid' : 'No bid') . ': ' . p('decision_notes')]);
        flash('success', $dec === 'bid' ? 'Decision recorded: bid.' : 'Decision recorded: no bid.');
    } elseif (p('act') === 'status') {
        $st = (string)p('status');
        if (!isset($statuses[$st])) {
            fail('Unknown status.');
        }
        DB::update('cs_opportunities', ['status' => $st, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
        Audit::log('status', 'plan', 'opportunity', $id, $o['name'], ['page_key' => $pageKey, 'changes' => [['field' => 'status', 'old' => $o['status'], 'new' => $st]]]);
        flash('success', 'Status changed.');
    } elseif (p('act') === 'convert') {
        Access::require('proj.projects', 'create');
        if ($o['project_id']) {
            fail('This opportunity already has a project.');
        }
        $cust = $o['customer_id'];
        if (!$cust) {
            $cust = DB::value('SELECT id FROM customers WHERE name = :n AND parent_id IS NULL', ['n' => $o['prospect_name']]) ?: DB::insert('customers', ['name' => $o['prospect_name'], 'client_class' => 'private']);
        }
        $pid = CsProject::create(['name' => $o['name'], 'customer_id' => $cust, 'location' => $o['location'], 'contract_value' => $o['est_value'], 'status' => 'planning',
            'pm_user_id' => $o['owner_user_id'], 'notes' => 'Created from ' . $o['opp_no']], true);
        DB::update('cs_opportunities', ['project_id' => $pid, 'customer_id' => $cust, 'status' => 'won'], 'id = :id', ['id' => $id]);
        DB::run('UPDATE cs_estimates SET project_id = :p WHERE opportunity_id = :o AND project_id IS NULL', ['p' => $pid, 'o' => $id]);
        flash('success', 'Project created from the won tender. Record the signed contract next and link it to the project.');
        cs_go('proj.view', ['id' => $pid]);
    }
    cs_go($pageKey, ['a' => 'view', 'id' => $id]);
});

if ($a === 'new' || ($a === 'edit' && $o)) {
    Access::require($pageKey, $o ? 'edit' : 'create');
    $c = $o ?: ['probability_pct' => 50, 'owner_user_id' => Auth::id(),
        'checklist' => (string)DB::value("SELECT items FROM cs_checklist_templates WHERE kind = 'tender' AND is_active = 1 ORDER BY id LIMIT 1")];
    $items = [];
    $checked = [];
    foreach (preg_split('/\R/', (string)($c['checklist'] ?? '')) as $line) {
        if (trim($line) === '') {
            continue;
        }
        $clean = preg_replace('/^\[[x ]\]\s*/', '', trim($line));
        $items[] = $clean;
        if (str_starts_with(trim($line), '[x]')) {
            $checked[] = $clean;
        }
    }
    layout_start($o ? 'Edit ' . $o['opp_no'] : 'New lead or tender', ['module' => 'plan']);
    ?>
    <form method="post" class="cs-split"><?= csrf_field() ?><input type="hidden" name="act" value="save">
    <div class="card"><div class="card-body"><div class="row g-3">
      <?= CsUi::field('Name of the job', CsUi::input('name', fv('name', $c), ['required' => true, 'max' => 200]), 'col-md-8') ?>
      <?= CsUi::field('Source', CsUi::input('source', fv('source', $c), ['max' => 60, 'placeholder' => 'PhilGEPS, invitation, referral']), 'col-md-4') ?>
      <?= CsUi::field('Client', '<select class="form-select form-select-sm" name="customer_id" data-combo>' . options(Lookup::customers(false), fv('customer_id', $c), 'Not a client yet') . '</select>', 'col-md-6') ?>
      <?= CsUi::field('Or prospect\'s name', CsUi::input('prospect_name', fv('prospect_name', $c), ['max' => 150]), 'col-md-6') ?>
      <?= CsUi::field('Location', CsUi::input('location', fv('location', $c), ['max' => 255]), 'col-md-6') ?>
      <?= CsUi::field('Bid due', CsUi::input('bid_due_date', fv('bid_due_date', $c), ['type' => 'date']), 'col-md-3') ?>
      <?= CsUi::field('Owner', CsUi::select('owner_user_id', cs_user_options(), fv('owner_user_id', $c), 'Choose', 'data-combo'), 'col-md-3') ?>
      <?= CsUi::field('Estimated value', CsUi::input('est_value', fv('est_value', $c, '0'), ['num' => true]), 'col-md-3') ?>
      <?= CsUi::field('Estimated cost', CsUi::input('est_cost', fv('est_cost', $c, '0'), ['num' => true]), 'col-md-3') ?>
      <?= CsUi::field('Chance of winning %', CsUi::input('probability_pct', fv('probability_pct', $c), ['num' => true]), 'col-md-2') ?>
      <?= CsUi::field('Bid security', CsUi::input('bid_security_amount', fv('bid_security_amount', $c, '0'), ['num' => true]), 'col-md-2') ?>
      <?= CsUi::field('Bid security ref.', CsUi::input('bid_security_ref', fv('bid_security_ref', $c), ['max' => 60]), 'col-md-2') ?>
      <?= CsUi::field('Bid security expires', CsUi::input('bid_security_expiry', fv('bid_security_expiry', $c), ['type' => 'date']), 'col-md-3') ?>
      <?= CsUi::field('Notes', CsUi::textarea('notes', fv('notes', $c), 3), 'col-12') ?>
    </div></div><div class="card-footer"><?= save_buttons('Save', url($pageKey, $o ? ['a' => 'view', 'id' => $id] : []), false) ?></div></div>
    <div class="card"><div class="card-header">Tender document checklist</div><div class="card-body">
      <?php foreach ($items as $it): ?>
        <label class="form-check d-block mb-1"><input class="form-check-input" type="checkbox" name="checked[]" value="<?= h($it) ?>"<?= checked(in_array($it, $checked, true)) ?>> <span class="form-check-label"><?= h($it) ?></span></label>
      <?php endforeach; ?>
      <label class="form-label mt-2">Items (one per line)</label><textarea class="form-control form-control-sm" name="checklist_items" rows="7"><?= h(implode("\n", $items)) ?></textarea>
    </div></div>
    </form>
    <?php
    layout_end();
    return;
}

if ($a === 'view' && $o) {
    $est = DB::all('SELECT * FROM cs_estimates WHERE opportunity_id = :o ORDER BY version_no DESC', ['o' => $id]);
    $actions = cs_link($pageKey, ['a' => 'edit', 'id' => $id], 'Edit', 'btn-outline-secondary', 'pencil', 'edit') . ' '
        . cs_link('plan.estimates', ['a' => 'new', 'opportunity_id' => $id], 'New estimate', 'btn-outline-primary', 'calculator', 'create');
    layout_start($o['opp_no'] . ' ' . $o['name'], ['module' => 'plan', 'actions' => $actions, 'sub' => h(CsUi::sub([$o['customer_name'] ?: $o['prospect_name'], $o['source'], $o['location']])) . ' ' . CsUi::state($o['status'], $statuses[$o['status']] ?? null)]);
    $margin = (float)$o['est_value'] > 0 ? ((float)$o['est_value'] - (float)$o['est_cost']) / (float)$o['est_value'] * 100 : 0;
    echo '<div class="cs-kpis">' . CsUi::kpi('Estimated value', CsUi::amt($o['est_value']), 'Weighted ' . money((float)$o['est_value'] * (float)$o['probability_pct'] / 100), 'key')
        . CsUi::kpi('Estimated margin', CsUi::pct($margin), money((float)$o['est_value'] - (float)$o['est_cost']), $margin < 5 ? 'warn' : 'ok')
        . CsUi::kpi('Chance of winning', CsUi::pct((float)$o['probability_pct'], 0), '') . CsUi::kpi('Bid due', h(fdate($o['bid_due_date']) ?: 'Not set'), $o['owner_name'] ? 'Owner ' . h($o['owner_name']) : '')
        . CsUi::kpi('Bid security', CsUi::amt($o['bid_security_amount']), $o['bid_security_expiry'] ? 'Expires ' . fdate($o['bid_security_expiry']) : '') . '</div>';
    echo '<div class="cs-split"><div class="cs-stack">';
    $rows = [];
    foreach ($est as $e) {
        $rows[] = ['<a href="' . h(url('plan.estimates', ['a' => 'view', 'id' => $e['id']])) . '">' . h($e['estimate_no']) . ' v' . (int)$e['version_no'] . '</a><span class="cs-sub">' . h($e['title']) . '</span>', money($e['total_cost']), money($e['total_price']), CsUi::state($e['status'])];
    }
    echo cs_table_card('Estimates and pricing', ['Estimate' => '', 'Cost' => 'num', 'Price' => 'num', 'Status' => ''], $rows, 'No estimate yet.');
    echo '<div class="card"><div class="card-header">Tender checklist</div><div class="card-body">';
    foreach (preg_split('/\R/', (string)$o['checklist']) as $line) {
        if (trim($line) !== '') {
            $done = str_starts_with(trim($line), '[x]');
            echo '<div class="mb-1"><i class="bi bi-' . ($done ? 'check-square-fill text-success' : 'square text-muted') . '"></i> ' . h(preg_replace('/^\[[x ]\]\s*/', '', trim($line))) . '</div>';
        }
    }
    echo '</div></div>' . ($o['notes'] ? '<div class="card"><div class="card-body">' . nl2br(h($o['notes'])) . '</div></div>' : '') . '</div><div class="cs-stack">';
    if (Access::can($pageKey, 'edit')) {
        echo '<div class="card"><div class="card-header">Bid or no bid</div><div class="card-body"><form method="post" class="row g-2">' . csrf_field() . '<input type="hidden" name="act" value="decide">'
            . '<div class="col-12">' . CsUi::select('bid_decision', ['bid' => 'Bid', 'no_bid' => 'No bid'], $o['bid_decision'] === 'pending' ? 'bid' : $o['bid_decision'], null, 'data-no-combo') . '</div>'
            . '<div class="col-12"><textarea class="form-control form-control-sm" name="decision_notes" rows="3" placeholder="Capacity, risk, margin, relationship">' . h((string)$o['decision_notes']) . '</textarea></div>'
            . '<div class="col-12"><button class="btn btn-outline-primary btn-sm">Record decision</button></div></form></div></div>';
        echo '<div class="card"><div class="card-header">Status</div><div class="card-body"><form method="post" class="d-flex gap-2">' . csrf_field() . '<input type="hidden" name="act" value="status">'
            . CsUi::select('status', $statuses, $o['status'], null, 'data-no-combo') . '<button class="btn btn-outline-secondary btn-sm">Change</button></form>';
        if ($o['project_id']) {
            echo '<p class="mt-3 mb-0">Project: ' . CsUi::projectLink((int)$o['project_id']) . '</p>';
        } elseif (Access::can('proj.projects', 'create')) {
            echo '<form method="post" class="mt-3">' . csrf_field() . '<input type="hidden" name="act" value="convert"><button class="btn btn-primary btn-sm" data-confirm="Mark it won and create the project?"><i class="bi bi-trophy"></i> Won: create the project</button></form>';
        }
        echo '</div></div>';
    }
    echo '</div></div>';
    layout_end();
    return;
}

$st = (string)g('status');
[$w, $params] = cs_like((string)g('q'), ['o.opp_no', 'o.name', 'o.prospect_name', 'c.name']);
if (isset($statuses[$st])) {
    $w .= ' AND o.status = :st';
    $params['st'] = $st;
}
$rows = DB::all("SELECT o.*, c.name AS customer_name FROM cs_opportunities o LEFT JOIN customers c ON c.id = o.customer_id WHERE 1 = 1 $w ORDER BY FIELD(o.status, 'bidding','submitted','qualifying','lead','won','lost','no_bid'), o.bid_due_date LIMIT 500", $params);
layout_start('Leads & Tenders', ['actions' => cs_link($pageKey, ['a' => 'new'], 'New lead', 'btn-primary', 'plus-lg', 'create')]);
$pipe = 0.0;
$weighted = 0.0;
foreach ($rows as $r) {
    if (in_array($r['status'], ['lead', 'qualifying', 'bidding', 'submitted'], true)) {
        $pipe += (float)$r['est_value'];
        $weighted += (float)$r['est_value'] * (float)$r['probability_pct'] / 100;
    }
}
echo '<div class="cs-kpis">' . CsUi::kpi('Pipeline', CsUi::amt($pipe, true), 'Open leads and bids', 'key') . CsUi::kpi('Weighted pipeline', CsUi::amt($weighted, true), 'By chance of winning')
    . CsUi::kpi('Won', (string)count(array_filter($rows, fn($r) => $r['status'] === 'won')), '') . CsUi::kpi('Lost or no bid', (string)count(array_filter($rows, fn($r) => in_array($r['status'], ['lost', 'no_bid'], true))), '') . '</div>';
echo '<form class="cs-toolbar" method="get"><input type="hidden" name="p" value="' . h($pageKey) . '">' . cs_search_field() . cs_status_field($statuses) . '<div><button class="btn btn-outline-secondary btn-sm">Show</button></div></form>';
$out = [];
foreach ($rows as $r) {
    $out[] = ['<a class="cs-main-link" href="' . h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) . '">' . h($r['opp_no']) . '</a><span class="cs-sub">' . h($r['name']) . '</span>',
        h($r['customer_name'] ?: (string)$r['prospect_name']), h(fdate($r['bid_due_date'])), money($r['est_value']), CsUi::pct((float)$r['probability_pct'], 0), h(CsUi::label($r['bid_decision'])), CsUi::state($r['status'], $statuses[$r['status']] ?? null)];
}
echo cs_table_card(count($rows) . ' opportunities', ['Opportunity' => '', 'Client / prospect' => '', 'Bid due' => '', 'Value' => 'num', 'Chance' => 'num', 'Decision' => '', 'Status' => ''], $out, 'No leads yet.');
layout_end();
