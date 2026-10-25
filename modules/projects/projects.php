<?php
declare(strict_types=1);

/** Project set-up: create a project (with its accounting class and stockyard) or change its details and team. */
$pageKey = 'proj.projects';
$id = gint('id');
$p = $id ? CsProject::requireAccess((int)$id) : null;

handle_post(function () use ($pageKey, $id, $p) {
    if (p('act') === 'team' && $p) {
        Access::require($pageKey, 'edit');
        $uid = pint('user_id');
        if (p('remove')) {
            DB::run('DELETE FROM cs_project_members WHERE project_id = :p AND user_id = :u', ['p' => $id, 'u' => (int)p('remove')]);
            Audit::log('team_remove', 'proj', 'project', $id, $p['code'], ['reason' => 'User #' . (int)p('remove') . ' removed from the team']);
        } elseif ($uid) {
            DB::run('INSERT INTO cs_project_members (project_id, user_id, role_label) VALUES (:p, :u, :r) ON DUPLICATE KEY UPDATE role_label = VALUES(role_label)',
                ['p' => $id, 'u' => $uid, 'r' => mb_substr((string)p('role_label'), 0, 60) ?: null]);
            Audit::log('team_add', 'proj', 'project', $id, $p['code'], ['reason' => 'User #' . $uid . ' added to the team as ' . p('role_label')]);
        }
        CsProject::flush();
        flash('success', 'Project team updated.');
        cs_go($pageKey, ['id' => $id, 'tab' => 'team']);
    }
    if (p('act') === 'save') {
        Access::require($pageKey, $id ? 'edit' : 'create');
        $d = $_POST;
        if (!empty($d['contract_id']) && empty($d['customer_id'])) {
            $d['customer_id'] = DB::value('SELECT customer_id FROM cs_contracts WHERE id = :k', ['k' => (int)$d['contract_id']]);
        }
        if (!empty($d['contract_id'])) {
            $k = CsContracts::get((int)$d['contract_id']);
            if ($k && !in_array($k['status'], ['active', 'completed', 'suspended'], true)) {
                fail('Contract ' . $k['contract_no'] . ' is not approved yet. Approve the contract first, or leave the project without a contract for now.');
            }
            $other = (float)DB::value('SELECT COALESCE(SUM(contract_value),0) FROM cs_projects WHERE contract_id = :k' . ($id ? ' AND id <> :id' : ''), ['k' => (int)$d['contract_id']] + ($id ? ['id' => $id] : []));
            if ($k && $other + to_num($d['contract_value'] ?? 0) > (float)$k['original_amount'] + 0.005) {
                fail('The projects of contract ' . $k['contract_no'] . ' would share more than its original amount (' . money($k['original_amount']) . '; '
                    . money($other) . ' is already allocated to its other projects). Variations are added through change orders.');
            }
        }
        if ($id) {
            CsProject::update((int)$id, $d);
            flash('success', 'Project saved.');
        } else {
            $id = CsProject::create($d, p('stockyard', '1') === '1');
            flash('success', 'Project created with its own accounting class' . (p('stockyard', '1') === '1' ? ' and stockyard' : '') . '. Next: its BOQ, budget and schedule.');
        }
        cs_go('proj.view', ['id' => $id]);
    }
});

Access::require($pageKey, $p ? 'edit' : 'create');
$c = $p ?: ['status' => 'planning', 'progress_source' => 'schedule', 'pm_user_id' => null];
if (!$p && ($kid = gint('contract_id'))) {
    $k = CsContracts::get($kid);
    if ($k) {
        $alloc = (float)DB::value('SELECT COALESCE(SUM(contract_value),0) FROM cs_projects WHERE contract_id = :k', ['k' => $kid]);
        $c += ['contract_id' => $kid, 'customer_id' => $k['customer_id'], 'contract_value' => max(0, (float)$k['original_amount'] - $alloc),
            'name' => $alloc > 0 ? '' : $k['title'], 'start_date' => $k['commencement_date'], 'target_end_date' => $k['original_completion_date']];
    }
}
$users = cs_user_options();
$tab = (string)g('tab', 'details');
layout_start($p ? 'Project set-up: ' . $p['code'] : 'New project', ['module' => 'proj',
    'sub' => $p ? h($p['name']) : 'The project gets an accounting class of its own, so every entry with that class lands in its books.',
    'actions' => $p ? cs_link('proj.view', ['id' => $id], 'Back to the project', 'btn-outline-secondary', 'arrow-left') : '']);
if ($p) {
    echo CsUi::tabs(['details' => 'Details', 'team' => 'Team and access'], $tab, fn($t) => url($pageKey, ['id' => $id, 'tab' => $t]));
}
if ($p && $tab === 'team') {
    $members = DB::all('SELECT m.*, u.full_name, u.username FROM cs_project_members m JOIN users u ON u.id = m.user_id WHERE m.project_id = :p ORDER BY u.full_name', ['p' => $id]);
    $rows = [];
    foreach ($members as $m) {
        $rows[] = [h($m['full_name']) . '<span class="cs-sub">' . h($m['username']) . '</span>', h((string)$m['role_label']), h(implode(', ', Access::roleNames((int)$m['user_id']))),
            '<form method="post" class="d-inline">' . csrf_field() . '<input type="hidden" name="act" value="team"><button class="btn btn-link btn-sm text-danger p-0" name="remove" value="' . (int)$m['user_id'] . '" data-confirm="Remove from the team?">Remove</button></form>'];
    }
    echo '<div class="cs-split"><div>' . cs_table_card('Team', ['Member' => '', 'On this project' => '', 'Roles' => '', '' => ''], $rows, 'No members yet.') . '</div>';
    echo '<div class="card"><div class="card-header">Add a member</div><div class="card-body"><form method="post" class="row g-2">' . csrf_field() . '<input type="hidden" name="act" value="team">'
        . '<div class="col-12">' . CsUi::select('user_id', $users, '', 'Choose a user', 'data-combo') . '</div>'
        . '<div class="col-12"><input class="form-control form-control-sm" name="role_label" placeholder="Role on the project (Site engineer, Foreman ...)"></div>'
        . '<div class="col-12"><button class="btn btn-primary btn-sm">Add to the team</button></div></form>'
        . '<p class="cs-note mt-3 mb-0">Users see the projects they are a member of. Users marked "All projects" on their account, and administrators, see every project.</p></div></div></div>';
    layout_end();
    return;
}
?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="save">
<div class="card mb-3"><div class="card-header">The project</div><div class="card-body"><div class="row g-3">
  <?= CsUi::field('Project name', CsUi::input('name', fv('name', $c), ['required' => true, 'max' => 200]), 'col-md-6') ?>
  <?= CsUi::field('Code', $p ? '<input class="form-control form-control-sm" value="' . h($p['code']) . '" readonly>' : CsUi::input('code', fv('code', $c), ['placeholder' => 'Next: ' . Seq::peek('cs_project'), 'max' => 30]), 'col-md-2') ?>
  <?= CsUi::field('Status', CsUi::select('status', array_diff_key(CsProject::STATUSES, ['closed' => 1]), fv('status', $c), null, 'data-no-combo'), 'col-md-2', $p && $p['status'] === 'closed' ? 'Closed' : '') ?>
  <?= CsUi::field('Division', CsUi::input('division', fv('division', $c), ['max' => 60, 'placeholder' => 'Buildings, Civil works ...']), 'col-md-2') ?>
  <?= CsUi::field('Client', '<select class="form-select form-select-sm" name="customer_id" data-combo>' . options(Lookup::customers(false), fv('customer_id', $c), 'Choose the client') . '</select>', 'col-md-4') ?>
  <?= CsUi::field('Contract', '<select class="form-select form-select-sm" name="contract_id" data-combo>' . options(CsContracts::options(), fv('contract_id', $c), 'No contract yet') . '</select>', 'col-md-5') ?>
  <?= CsUi::field('This project\'s contract value', CsUi::input('contract_value', fv('contract_value', $c, '0'), ['num' => true]), 'col-md-3', 'Net of VAT. A contract may cover several projects.') ?>
  <?= CsUi::field('Location', CsUi::input('location', fv('location', $c), ['max' => 255]), 'col-md-8') ?>
  <?= CsUi::field('Floor area / measurable quantity', CsUi::input('floor_area', fv('floor_area', $c, '0'), ['num' => true]), 'col-md-4', 'Used when shared costs are allocated by floor area.') ?>
  <?= CsUi::field('Description', CsUi::textarea('description', fv('description', $c), 2), 'col-12') ?>
</div></div></div>
<div class="card mb-3"><div class="card-header">People</div><div class="card-body"><div class="row g-3">
  <?= CsUi::field('Project manager', CsUi::select('pm_user_id', $users, fv('pm_user_id', $c), 'Choose', 'data-combo'), 'col-md-4') ?>
  <?= CsUi::field('Site engineer', CsUi::select('engineer_user_id', $users, fv('engineer_user_id', $c), 'Choose', 'data-combo'), 'col-md-4') ?>
  <?= CsUi::field('Quantity surveyor', CsUi::select('qs_user_id', $users, fv('qs_user_id', $c), 'Choose', 'data-combo'), 'col-md-4') ?>
</div></div></div>
<div class="card mb-3"><div class="card-header">Schedule and progress</div><div class="card-body"><div class="row g-3">
  <?= CsUi::field('Start', CsUi::input('start_date', fv('start_date', $c), ['type' => 'date']), 'col-6 col-md-2') ?>
  <?= CsUi::field('Target completion', CsUi::input('target_end_date', fv('target_end_date', $c), ['type' => 'date']), 'col-6 col-md-2') ?>
  <?= CsUi::field('Revised completion', CsUi::input('revised_end_date', fv('revised_end_date', $c), ['type' => 'date']), 'col-6 col-md-2', 'Moves with approved time extensions') ?>
  <?= CsUi::field('Actual start', CsUi::input('actual_start_date', fv('actual_start_date', $c), ['type' => 'date']), 'col-6 col-md-2') ?>
  <?= CsUi::field('Physical progress from', CsUi::select('progress_source', ['schedule' => 'The schedule (weighted activities)', 'manual' => 'The figure typed here'], fv('progress_source', $c), null, 'data-no-combo'), 'col-md-2') ?>
  <?= CsUi::field('Progress % (manual)', CsUi::input('completion_pct', fv('completion_pct', $c, '0'), ['num' => true]), 'col-md-2') ?>
  <?php if (!$p): ?><?= check_field('stockyard', 'Make a stockyard (inventory site) for this project', true, 'col-md-6') ?><?php endif; ?>
  <?= CsUi::field('Notes', CsUi::textarea('notes', fv('notes', $c), 2), 'col-12') ?>
</div></div></div>
<div class="sticky-actions"><?= save_buttons($p ? 'Save project' : 'Create project', url($p ? 'proj.view' : 'proj.center', $p ? ['id' => $id] : []), false) ?></div>
</form>
<?php
layout_end();
