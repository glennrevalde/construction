<?php
declare(strict_types=1);

/**
 * Approval routes: for each kind of document, by amount (and optionally by project or risk), the steps it goes
 * through and who takes each step: a role, one user, or the project's own manager, engineer or quantity surveyor.
 */
$pageKey = 'appr.routes';
$id = gint('id');
$r = $id ? DB::row('SELECT * FROM cs_approval_routes WHERE id = :id', ['id' => $id]) : null;
$types = [];
foreach (CsDocs::TYPES as $k => $t) {
    $types[$k] = $t[0];
}

handle_post(function () use ($pageKey, $id, $r, $types) {
    Access::require($pageKey, $id ? 'edit' : 'create');
    if (p('act') === 'delete' && $r) {
        Access::require($pageKey, 'delete');
        if (DB::value("SELECT id FROM cs_approval_requests WHERE route_id = :r AND status = 'pending' LIMIT 1", ['r' => $id])) {
            fail('Documents are waiting on this route. Make it inactive instead.');
        }
        DB::run('DELETE FROM cs_approval_routes WHERE id = :id', ['id' => $id]);
        Audit::log('delete', 'appr', 'route', (int)$id, $r['name'], ['page_key' => $pageKey]);
        flash('success', 'Route deleted.');
        cs_go($pageKey);
    }
    $row = ['doc_type' => isset($types[p('doc_type')]) ? p('doc_type') : '', 'name' => mb_substr(trim((string)p('name')), 0, 120), 'min_amount' => to_num(p('min_amount')),
        'max_amount' => trim((string)p('max_amount')) !== '' ? to_num(p('max_amount')) : null, 'project_id' => pint('project_id'), 'risk_level' => in_array(p('risk_level'), ['low', 'medium', 'high'], true) ? p('risk_level') : null,
        'is_active' => p('is_active', '1') === '1' ? 1 : 0, 'sort_order' => (int)p('sort_order')];
    if ($row['doc_type'] === '' || $row['name'] === '') {
        fail('Choose the kind of document and name the route.');
    }
    if ($row['max_amount'] !== null && $row['max_amount'] <= $row['min_amount']) {
        fail('The upper amount must be more than the lower amount.');
    }
    $steps = [];
    foreach (cs_lines('steps') as $s) {
        $label = mb_substr(trim((string)($s['label'] ?? '')), 0, 80);
        $who = (string)($s['who'] ?? '');
        if ($label === '' && $who === '') {
            continue;
        }
        $step = ['label' => $label ?: 'Approval', 'role_id' => null, 'user_id' => null, 'project_role' => null, 'due_hours' => max(1, (int)($s['due_hours'] ?? 48))];
        if (preg_match('/^role:(\d+)$/', $who, $m)) {
            $step['role_id'] = (int)$m[1];
        } elseif (preg_match('/^user:(\d+)$/', $who, $m)) {
            $step['user_id'] = (int)$m[1];
        } elseif (preg_match('/^proj:(pm|engineer|qs)$/', $who, $m)) {
            $step['project_role'] = $m[1];
        } else {
            fail('Choose who takes step "' . $step['label'] . '".');
        }
        $steps[] = $step;
    }
    if (!$steps) {
        fail('A route has at least one step.');
    }
    DB::tx(function () use (&$id, $row, $steps, $r, $pageKey) {
        if ($r) {
            DB::update('cs_approval_routes', $row, 'id = :id', ['id' => $id]);
            DB::run('DELETE FROM cs_approval_steps WHERE route_id = :r', ['r' => $id]);
        } else {
            $id = DB::insert('cs_approval_routes', $row);
        }
        foreach ($steps as $i => $s) {
            DB::insert('cs_approval_steps', $s + ['route_id' => $id, 'step_no' => $i + 1]);
        }
        Audit::log($r ? 'edit' : 'create', 'appr', 'route', $id, $row['name'], ['page_key' => $pageKey, 'reason' => count($steps) . ' step(s)']);
    });
    flash('success', 'Route saved. Documents already waiting keep the route they started on.');
    cs_go($pageKey);
});

layout_start('Approval Routes', ['sub' => 'With no route for a document and amount, one approval by anyone with Approve on its page is needed. Nobody approves what they submitted.']);
$rows = [];
foreach (DB::all('SELECT r.*, p.code FROM cs_approval_routes r LEFT JOIN cs_projects p ON p.id = r.project_id ORDER BY r.doc_type, r.min_amount, r.sort_order') as $x) {
    $steps = CsApproval::steps((int)$x['id']);
    $rows[] = ['_class' => (int)$x['is_active'] ? '' : 'text-muted', h($types[$x['doc_type']] ?? $x['doc_type']), h($x['name']) . ($x['code'] ? '<span class="cs-sub">Only ' . h($x['code']) . '</span>' : '') . ($x['risk_level'] ? '<span class="cs-sub">Risk: ' . h($x['risk_level']) . '</span>' : ''),
        money($x['min_amount']) . ' to ' . ($x['max_amount'] !== null ? money($x['max_amount']) : 'any'), '<div class="cs-route">' . implode('<i class="bi bi-chevron-right"></i>', array_map(fn($s) => '<span title="' . h(CsApproval::stepWho($s)) . '">' . h($s['label']) . '</span>', $steps)) . '</div>',
        Access::can($pageKey, 'edit') ? '<a href="' . h(url($pageKey, ['id' => $x['id']])) . '#routeForm">Edit</a>' : ''];
}
echo cs_table_card('Routes', ['Document' => '', 'Route' => '', 'Amount' => '', 'Steps' => '', '' => ''], $rows, 'No routes yet.');
if (Access::can($pageKey, $r ? 'edit' : 'create')) {
    $c = $r ?: ['is_active' => 1, 'min_amount' => 0, 'doc_type' => (string)g('type', 'billing')];
    $steps = $r ? CsApproval::steps((int)$id) : [];
    $who = ['' => 'Choose'];
    foreach (DB::all('SELECT id, name FROM roles ORDER BY name') as $ro) {
        $who['role:' . $ro['id']] = 'Role: ' . $ro['name'];
    }
    $who += ['proj:pm' => 'The project\'s manager', 'proj:engineer' => 'The project\'s engineer', 'proj:qs' => 'The project\'s quantity surveyor'];
    foreach (DB::all("SELECT id, full_name FROM users WHERE status = 'active' AND is_platform = 0 ORDER BY full_name") as $u) {
        $who['user:' . $u['id']] = 'User: ' . $u['full_name'];
    }
    $stepRow = function (array $s, string $i) use ($who): string {
        $v = $s ? ($s['role_id'] ? 'role:' . $s['role_id'] : ($s['user_id'] ? 'user:' . $s['user_id'] : ($s['project_role'] ? 'proj:' . $s['project_role'] : ''))) : '';
        return '<tr><td class="w-desc"><input class="form-control form-control-sm" name="steps[' . $i . '][label]" value="' . h($s['label'] ?? '') . '" placeholder="Finance Manager approval"></td>'
            . '<td style="min-width:240px">' . CsUi::select('steps[' . $i . '][who]', array_slice($who, 1, null, true), $v, 'Choose', 'data-combo') . '</td>'
            . '<td style="width:110px"><input class="form-control form-control-sm text-end" type="number" min="1" name="steps[' . $i . '][due_hours]" value="' . h((string)($s['due_hours'] ?? 48)) . '"></td>'
            . '<td class="grid-acts"><button type="button" class="btn btn-link btn-sm text-danger p-0" data-cs-del aria-label="Remove"><i class="bi bi-x-lg"></i></button></td></tr>';
    };
    echo '<form method="post" class="card mt-3" id="routeForm"><div class="card-header">' . ($r ? 'Edit ' . h($r['name']) : 'New route') . '</div><div class="card-body"><div class="row g-3">' . csrf_field()
        . CsUi::field('Document', CsUi::select('doc_type', $types, fv('doc_type', $c), null, 'data-no-combo'), 'col-md-3') . CsUi::field('Name', CsUi::input('name', fv('name', $c), ['required' => true, 'max' => 120]), 'col-md-5')
        . CsUi::field('From amount', CsUi::input('min_amount', fv('min_amount', $c), ['num' => true]), 'col-6 col-md-2') . CsUi::field('Below amount', CsUi::input('max_amount', fv('max_amount', $c), ['num' => true, 'placeholder' => 'No limit']), 'col-6 col-md-2')
        . CsUi::field('Only for project', CsUi::projectSelect('project_id', fv('project_id', $c), ['blank' => 'Every project', 'closed' => true]), 'col-md-4')
        . CsUi::field('Only for risk', CsUi::select('risk_level', ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'], fv('risk_level', $c), 'Any', 'data-no-combo'), 'col-md-3', 'Over-budget requests and large change orders are high risk.')
        . CsUi::field('Order', CsUi::input('sort_order', fv('sort_order', $c, '0'), ['type' => 'number', 'step' => '1']), 'col-6 col-md-2') . check_field('is_active', 'Active', (int)fv('is_active', $c, 1) === 1, 'col-6 col-md-3')
        . '</div></div><div class="table-responsive"><table class="table table-sm cs-lines mb-0" id="stepGrid" data-cs-lines data-min-rows="1"><thead><tr><th>Step</th><th>Who approves</th><th class="num">Hours to act</th><th></th></tr></thead><tbody>';
    foreach ($steps as $i => $s) {
        echo $stepRow($s, 'e' . $i);
    }
    echo '</tbody></table></div><template data-cs-row="stepGrid">' . $stepRow([], '__i__') . '</template><div class="card-body pt-2"><button type="button" class="btn btn-outline-secondary btn-sm" data-cs-add="stepGrid"><i class="bi bi-plus"></i> Add a step</button></div>'
        . '<div class="card-footer d-flex gap-2">' . save_buttons('Save route', $r ? url($pageKey) : null, false)
        . ($r && Access::can($pageKey, 'delete') ? '<button class="btn btn-outline-danger ms-auto" name="act" value="delete" data-confirm="Delete this route?">Delete</button>' : '') . '</div></form>';
}
layout_end();
