<?php
declare(strict_types=1);

/**
 * Materials issued to projects, returned, used on site (with waste) and transferred between projects. Each posted
 * document moves the stock and, depending on the company's cost policy, the project's materials cost.
 */
$pageKey = 'proc.materials';
$a = (string)g('a', 'list');
$id = gint('id');
$d = $id ? CsMaterials::getDoc($id) : null;
if ($d) {
    CsProject::requireAccess((int)$d['project_id']);
}
$policy = CsMaterials::policy();
$types = CsMaterials::DOC_TYPES;
if ($policy !== 'on_consume') {
    unset($types['consume']);
}

handle_post(function () use ($pageKey, $id, $d) {
    if ($d && cs_handle_attach('material_doc', (int)$id, (int)$d['project_id'])) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if ($d && CsApproval::handlePost('material_transfer', (int)$id, function () use ($d) {
        if ($d['doc_type'] !== 'transfer' || $d['status'] !== 'draft') {
            fail('Only a draft transfer is submitted for approval.');
        }
        CsApproval::submit('material_transfer', (int)$d['id'], $d['doc_no'], (int)$d['project_id'], (float)$d['total_cost']);
    })) {
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    $act = (string)p('act');
    if ($d && $act === 'post') {
        Access::require($pageKey, 'edit');
        CsMaterials::postDoc((int)$id);
        flash('success', 'Posted. The stock and the project\'s cost are updated.');
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if ($d && $act === 'cancel') {
        Access::require($pageKey, $d['status'] === 'posted' ? 'delete' : 'edit');
        CsMaterials::cancelDoc((int)$id, (string)p('reason'));
        flash('success', $d['status'] === 'posted' ? 'Reversed: the accounting entries were voided with your reason.' : 'Cancelled.');
        cs_go($pageKey, ['a' => 'view', 'id' => $id]);
    }
    if ($act === 'save') {
        Access::require($pageKey, $id ? 'edit' : 'create');
        $nid = CsMaterials::saveDoc($_POST, cs_lines(), $id);
        if (p('then') === 'post' && ($_POST['doc_type'] ?? $d['doc_type'] ?? '') !== 'transfer') {
            CsMaterials::postDoc($nid);
            flash('success', 'Saved and posted.');
        } else {
            flash('success', 'Saved as a draft.');
        }
        cs_go($pageKey, ['a' => 'view', 'id' => $nid]);
    }
});

if ($a === 'new' || ($a === 'edit' && $d)) {
    Access::require($pageKey, $d ? 'edit' : 'create');
    $type = $d['doc_type'] ?? (string)g('type', 'issue');
    if (!isset($types[$type])) {
        $type = 'issue';
    }
    $mr = !$d && gint('mr') ? CsMaterials::getMr((int)gint('mr')) : null;
    $pid = $d ? (int)$d['project_id'] : (int)($mr['project_id'] ?? gint('project') ?? CsProject::current() ?? 0);
    $p = $pid ? CsProject::get($pid) : null;
    $c = $d ?: ['project_id' => $pid, 'doc_date' => today(), 'mr_id' => $mr['id'] ?? null,
        'site_id' => $type === 'issue' ? CsMaterials::warehouse() : ($type === 'return' ? CsMaterials::warehouse() : null)];
    if (is_post()) {
        $lines = cs_lines();
    } elseif ($d) {
        $lines = DB::all('SELECT * FROM cs_material_doc_lines WHERE doc_id = :d ORDER BY line_no', ['d' => $id]);
    } elseif ($mr) {
        $lines = [];
        foreach (DB::all('SELECT * FROM cs_mr_lines WHERE mr_id = :m AND item_id IS NOT NULL AND qty_approved - qty_issued - qty_ordered > 0.0001 ORDER BY line_no', ['m' => $mr['id']]) as $l) {
            $lines[] = ['item_id' => $l['item_id'], 'description' => $l['description'], 'qty' => (float)$l['qty_approved'] - (float)$l['qty_issued'] - (float)$l['qty_ordered'], 'cost_code_id' => $l['cost_code_id'],
                'activity_id' => $mr['activity_id'], 'mr_line_id' => $l['id']];
        }
    } else {
        $lines = [];
    }
    $acts = $pid ? DB::pairs("SELECT id, CONCAT(wbs_code, ' ', name) FROM cs_activities WHERE project_id = :p ORDER BY sort_order", ['p' => $pid]) : [];
    $ccOpts = CsCost::codeOptions();
    $sites = DB::pairs("SELECT id, CONCAT(name, IF(site_type = 'project', ' (project stockyard)', '')) FROM sites WHERE is_active = 1 ORDER BY site_type <> 'warehouse', name");
    $row = function (array $l, string $i) use ($ccOpts, $acts, $type): string {
        $n = fn($f) => 'lines[' . $i . '][' . $f . ']';
        return '<tr><td style="min-width:220px"><input type="hidden" name="' . $n('mr_line_id') . '" value="' . h($l['mr_line_id'] ?? '') . '">' . CsMaterials::itemSelect($n('item_id'), $l['item_id'] ?? '', 'data-fill="cost:unit_cost,desc:description"') . '</td>'
            . '<td class="w-desc"><input class="form-control form-control-sm" name="' . $n('description') . '" value="' . h($l['description'] ?? '') . '"></td>'
            . '<td class="w-sm"><input class="form-control form-control-sm text-end" name="' . $n('qty') . '" value="' . h(isset($l['qty']) && $l['qty'] !== '' ? qty($l['qty']) : '') . '" data-q></td>'
            . ($type === 'consume' ? '<td class="w-sm"><input class="form-control form-control-sm text-end" name="' . $n('qty_wasted') . '" value="' . h(isset($l['qty_wasted']) && (float)$l['qty_wasted'] ? qty($l['qty_wasted']) : '') . '" placeholder="0"></td>' : '')
            . '<td class="w-num"><input class="form-control form-control-sm text-end" name="' . $n('unit_cost') . '" value="' . h(isset($l['unit_cost']) && $l['unit_cost'] !== '' ? number_format((float)$l['unit_cost'], 2, '.', '') : '') . '" data-r title="Average cost now; the posting uses the actual average cost"></td>'
            . '<td class="w-num num" data-a>0.00</td>'
            . '<td style="min-width:160px">' . CsUi::select($n('cost_code_id'), $ccOpts, $l['cost_code_id'] ?? '', '', 'data-combo') . '</td>'
            . '<td style="min-width:160px">' . CsUi::select($n('activity_id'), $acts, $l['activity_id'] ?? '', '', 'data-combo') . '</td>'
            . '<td class="grid-acts"><button type="button" class="btn btn-link btn-sm text-danger p-0" data-cs-del aria-label="Remove"><i class="bi bi-x-lg"></i></button></td></tr>';
    };
    $help = [
        'issue' => $policy === 'on_issue' ? 'Issued materials become the project\'s cost now, at their average cost.' : 'Issued materials move to the project\'s stockyard; they become cost when the site reports them used.',
        'return' => $policy === 'on_issue' ? 'Returned materials go back into stock and are taken off the project\'s cost.' : 'Returned materials move from the project\'s stockyard back to the warehouse.',
        'consume' => 'What the site used from its stockyard, with the part wasted. This is when the cost is taken.',
        'transfer' => 'Transfers between projects need approval. ' . ($policy === 'on_issue' ? 'The cost moves to the receiving project.' : 'The stock moves between the stockyards.'),
    ][$type];
    layout_start(($d ? 'Edit ' . $d['doc_no'] : 'New: ' . strtolower($types[$type])), ['module' => 'proc', 'sub' => h($help)]);
    ?>
    <form method="post" data-cs-offline="mat-<?= h($type) ?>-<?= (int)($id ?? 0) ?>"<?= $d ? '' : ' data-cs-new' ?>><?= csrf_field() ?><input type="hidden" name="act" value="save"><input type="hidden" name="doc_type" value="<?= h($type) ?>">
    <input type="hidden" name="mr_id" value="<?= h((string)($c['mr_id'] ?? '')) ?>">
    <div class="card mb-3"><div class="card-body"><div class="row g-3">
      <?= CsUi::field($type === 'transfer' ? 'From project' : 'Project', $d || $mr ? '<input type="hidden" name="project_id" value="' . $pid . '"><div class="pt-1">' . CsUi::projectLink($pid) . '</div>' : CsUi::projectSelect('project_id', $pid ?: '', ['required' => true]), 'col-md-5') ?>
      <?= CsUi::field('Date', CsUi::input('doc_date', fv('doc_date', $c), ['type' => 'date']), 'col-6 col-md-2') ?>
      <?php if ($type === 'issue' || $type === 'return'): ?>
        <?= CsUi::field($type === 'issue' ? 'Taken from' : 'Returned to', CsUi::select('site_id', $sites, fv('site_id', $c), null, 'data-combo'), 'col-6 col-md-5') ?>
      <?php elseif ($type === 'transfer'): ?>
        <?= CsUi::field('To project', CsUi::projectSelect('to_project_id', fv('to_project_id', $c), ['required' => true]), 'col-md-5') ?>
      <?php else: ?>
        <div class="col-md-5"><label class="form-label">From</label><div class="pt-1"><?= h($p ? (string)DB::value('SELECT name FROM sites WHERE id = :s', ['s' => (int)$p['site_id']]) : 'The project\'s stockyard') ?></div></div>
      <?php endif; ?>
      <?php if ($mr): ?><div class="col-12 cs-note">Filled from <?= h($mr['mr_no']) ?>: the approved quantities not yet ordered or issued.</div><?php endif; ?>
      <?= CsUi::field('Notes', CsUi::input('notes', fv('notes', $c), ['max' => 255]), 'col-12') ?>
    </div></div></div>
    <div class="card mb-3"><div class="table-responsive"><table class="table table-sm cs-lines mb-0" id="matGrid" data-cs-lines data-min-rows="3"><thead><tr><th>Material</th><th>Description</th><th class="num">Qty</th><?= $type === 'consume' ? '<th class="num">Of which wasted</th>' : '' ?><th class="num">Unit cost</th><th class="num">Amount</th><th>Cost code</th><th>Activity</th><th></th></tr></thead>
      <tbody><?php foreach ($lines as $i => $l) { echo $row($l, 'e' . $i); } ?></tbody>
      <tfoot><tr><td colspan="<?= $type === 'consume' ? 5 : 4 ?>" class="text-end">Estimated cost</td><td class="num" data-cs-sum="amount">0.00</td><td colspan="3"></td></tr></tfoot></table></div>
      <template data-cs-row="matGrid"><?= $row([], '__i__') ?></template>
      <div class="card-body pt-2"><button type="button" class="btn btn-outline-secondary btn-sm" data-cs-add="matGrid"><i class="bi bi-plus"></i> Add a material</button></div></div>
    <div class="sticky-actions d-flex flex-wrap gap-2"><button class="btn btn-outline-primary">Save draft</button>
      <?php if ($type !== 'transfer'): ?><button class="btn btn-primary" name="then" value="post">Save and post</button><?php endif; ?>
      <a class="btn btn-outline-secondary" href="<?= h(url($pageKey, $d ? ['a' => 'view', 'id' => $id] : [])) ?>">Cancel</a></div>
    </form>
    <?php
    layout_end();
    return;
}

if ($a === 'view' && $d) {
    $lines = DB::all('SELECT l.*, a.wbs_code FROM cs_material_doc_lines l LEFT JOIN cs_activities a ON a.id = l.activity_id WHERE l.doc_id = :d ORDER BY l.line_no', ['d' => $id]);
    $site = $d['site_id'] ? (string)DB::value('SELECT name FROM sites WHERE id = :s', ['s' => $d['site_id']]) : '';
    $actions = $d['status'] === 'draft' ? cs_link($pageKey, ['a' => 'edit', 'id' => $id], 'Edit', 'btn-outline-secondary', 'pencil', 'edit') : '';
    if ($d['status'] === 'draft' && $d['doc_type'] !== 'transfer' && Access::can($pageKey, 'edit')) {
        $actions .= ' ' . CsUi::confirmButton('mdPost', 'Post', 'Post ' . $d['doc_no'], 'The stock moves now' . (($d['doc_type'] === 'issue' && $policy === 'on_issue') || $d['doc_type'] === 'consume' ? ' and the project is charged the materials\' average cost.' : '.'), '<input type="hidden" name="act" value="post">', 'btn-primary', 'check2');
    }
    layout_start($d['doc_no'] . ' ' . CsMaterials::DOC_TYPES[$d['doc_type']], ['module' => 'proc', 'actions' => $actions,
        'sub' => CsUi::projectLink((int)$d['project_id']) . ($d['to_project_id'] ? ' to ' . CsUi::projectLink((int)$d['to_project_id']) : '') . ', ' . h(fdate($d['doc_date'])) . ($site ? ', ' . h($site) : '') . ' ' . CsUi::state($d['status'])]);
    $rows = [];
    foreach ($lines as $l) {
        $rows[] = [h($l['description']), qty($l['qty']) . ' ' . h((string)$l['unit']), $d['doc_type'] === 'consume' ? qty($l['qty_wasted']) : '', money($l['unit_cost']), money($l['amount']),
            h(CsCost::codeLabel($l['cost_code_id'] ? (int)$l['cost_code_id'] : null)), h((string)$l['wbs_code'])];
    }
    echo '<div class="cs-split"><div class="cs-stack">' . cs_table_card('Materials', ['Material' => '', 'Qty' => 'num', 'Wasted' => 'num', 'Unit cost' => 'num', 'Amount' => 'num', 'Cost code' => '', 'Activity' => ''], $rows, '', '',
        '<tfoot><tr><td colspan="4">Total</td><td class="num">' . money($d['total_cost']) . '</td><td colspan="2"></td></tr></tfoot>');
    echo '<div class="card"><div class="card-body">' . CsUi::facts(['Cost policy' => h($d['cost_policy'] ? ($d['cost_policy'] === 'on_issue' ? 'Costed when issued' : 'Costed when used') : ($policy === 'on_issue' ? 'Costed when issued' : 'Costed when used')),
        'Accounting entry' => $d['txn_id'] ? CsUi::txnLink((int)$d['txn_id']) : ($d['status'] === 'posted' ? 'None needed (stock stayed in the project\'s stockyard)' : ''),
        'Material request' => $d['mr_id'] ? '<a href="' . h(url('proc.requests', ['a' => 'view', 'id' => $d['mr_id']])) . '">' . h((string)DB::value('SELECT mr_no FROM cs_material_requests WHERE id = :m', ['m' => $d['mr_id']])) . '</a>' : '',
        'Posted' => $d['posted_at'] ? h(fdatetime($d['posted_at'])) : '', 'Notes' => h((string)$d['notes'])]) . '</div></div></div><div class="cs-stack">';
    if ($d['doc_type'] === 'transfer') {
        echo CsApproval::panel('material_transfer', $id, Access::can($pageKey, 'edit') && $d['status'] === 'draft', 'Submit transfer for approval');
    }
    if (in_array($d['status'], ['draft', 'posted'], true) && Access::can($pageKey, $d['status'] === 'posted' ? 'delete' : 'edit')) {
        echo '<div class="card"><div class="card-body">' . CsUi::confirmButton('mdCancel', $d['status'] === 'posted' ? 'Reverse' : 'Cancel draft', ($d['status'] === 'posted' ? 'Reverse ' : 'Cancel ') . $d['doc_no'],
            $d['status'] === 'posted' ? 'The stock movement and the cost are reversed by voiding the accounting entry. The document stays on file as cancelled.' : 'The draft is kept as cancelled.',
            '<input type="hidden" name="act" value="cancel">', 'btn-outline-danger', 'arrow-counterclockwise', true) . '</div></div>';
    }
    echo CsFiles::panel('material_doc', $id, Access::can($pageKey, 'edit'), 'Signed slips and photos') . cs_history('material_doc', $id) . '</div></div>';
    layout_end();
    return;
}

$pid = CsProject::current();
$type = (string)g('type');
$st = (string)g('status');
$new = '';
foreach ($types as $k => $label) {
    $new .= '<li><a class="dropdown-item" href="' . h(url($pageKey, ['a' => 'new', 'type' => $k, 'project' => $pid])) . '">' . h($label) . '</a></li>';
}
layout_start('Material Issues & Transfers', ['sub' => $policy === 'on_issue' ? 'Materials are costed to projects when issued.' : 'Materials are costed to projects when the site reports them used.',
    'actions' => Access::can($pageKey, 'create') ? '<div class="dropdown d-inline-block"><button class="btn btn-primary btn-sm dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-plus-lg"></i> New</button><ul class="dropdown-menu dropdown-menu-end">' . $new . '</ul></div>' : '']);
echo cs_filter_bar($pageKey, $pid, cs_status_field($types, 'type', 'Kind') . cs_status_field(['draft' => 'Draft', 'for_approval' => 'For approval', 'posted' => 'Posted', 'cancelled' => 'Cancelled']));
[$f, $params] = CsProject::filter('d.project_id');
$w = '';
if ($pid) {
    $w .= ' AND (d.project_id = :pid OR d.to_project_id = :pid2)';
    $params += ['pid' => $pid, 'pid2' => $pid];
}
if (isset(CsMaterials::DOC_TYPES[$type])) {
    $w .= ' AND d.doc_type = :t';
    $params['t'] = $type;
}
if ($st !== '') {
    $w .= ' AND d.status = :st';
    $params['st'] = $st;
}
$rows = DB::all("SELECT d.*, p.code AS pcode, tp.code AS to_code, s.name AS site_name FROM cs_material_docs d JOIN cs_projects p ON p.id = d.project_id
                   LEFT JOIN cs_projects tp ON tp.id = d.to_project_id LEFT JOIN sites s ON s.id = d.site_id WHERE 1 = 1 $f $w ORDER BY d.id DESC LIMIT 400", $params);
$out = [];
foreach ($rows as $r) {
    $out[] = ['<a class="cs-main-link" href="' . h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) . '">' . h($r['doc_no']) . '</a>', h(CsMaterials::DOC_TYPES[$r['doc_type']]), h($r['pcode'] . ($r['to_code'] ? ' to ' . $r['to_code'] : '')),
        h((string)$r['site_name']), h(fdate($r['doc_date'])), money($r['total_cost']), CsUi::state($r['status'])];
}
echo cs_table_card(count($rows) . ' documents', ['No.' => '', 'Kind' => '', 'Project' => '', 'Site' => '', 'Date' => '', 'Cost' => 'num', 'Status' => ''], $out, 'Nothing issued yet.');
layout_end();
