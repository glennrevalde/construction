<?php
declare(strict_types=1);

/**
 * Fixed Assets > Preventive Maintenance
 * Tasks that fall due by date and / or by meter: vehicle registration renewal, change oil, cleaning of
 * air-conditioning units, computers and solar systems, and any other maintenance requirement.
 * A task that is due keeps reminding (bell in the top bar, Home page, pop-up after logging in) until a user
 * acknowledges that it is complete. See app/AssetMaint.php.
 */
$pageKey = 'fa.pm';
$a = g('a');

$assets = DB::all("SELECT id, asset_no, description, location, current_meter, meter_unit FROM fixed_assets WHERE status <> 'disposed' ORDER BY asset_no");
$assetPairs = [];
foreach ($assets as $as) {
    $assetPairs[(int)$as['id']] = $as['description'] . ' (' . $as['asset_no'] . ')';
}
$back = fn(array $extra = []) => url($pageKey, array_filter(['tab' => g('tab'), 'asset_id' => gint('asset_id'), 'location_id' => gint('location_id')]) + $extra);

// ------------------------------------------------------------------ acknowledge a task as complete
if ($a === 'complete' && is_post()) {
    Access::require($pageKey, 'edit');
    $s = AssetMaint::schedule((int)g('id'));
    try {
        if (!$s || (int)$s['is_active'] !== 1) {
            fail('That task no longer exists or is already finished.');
        }
        $done = pdate('done_date');
        if (!$done) {
            fail('Enter the date the task was completed.');
        }
        if ($done > today()) {
            fail('The completion date cannot be in the future.');
        }
        $meter = trim((string)p('done_meter')) === '' ? null : to_num(p('done_meter'));
        if ($s['meter_interval'] !== null && (float)$s['meter_interval'] > 0 && $meter === null) {
            fail('Enter the meter reading: this task repeats by ' . ($s['meter_unit'] ?: 'meter') . '.');
        }
        AssetMaint::complete($s, $done, $meter, (string)p('remarks'));
        $fresh = AssetMaint::schedule((int)$s['id']);
        flash('success', $s['task_name'] . ' of ' . $s['asset_no'] . ' acknowledged as complete.'
            . ((int)$fresh['is_active'] === 1 ? ' Next due ' . ($fresh['next_due_date'] ? fdate($fresh['next_due_date']) : '') . ($fresh['next_due_meter'] !== null ? ($fresh['next_due_date'] ? ' or ' : '') . 'at ' . qty($fresh['next_due_meter']) . ' ' . ($s['meter_unit'] ?: '') : '') . '.' : ' The task does not repeat, so it is finished.'));
        if (p('then') === 'record' && Access::can('fa.maintenance', 'create')) {
            redirect(url('fa.maintenance', ['a' => 'new', 'asset_id' => $s['asset_id']]));
        }
    } catch (UserError $e) {
        if (DB::inTransaction()) {
            DB::rollBack();
        }
        flash('danger', $e->getMessage());
    }
    redirect($back());
}

// ------------------------------------------------------------------ odometer / running hours
if ($a === 'meter' && is_post()) {
    Access::require($pageKey, 'edit');
    $asset = DB::row('SELECT * FROM fixed_assets WHERE id = :id', ['id' => pint('asset_id')]);
    if ($asset && trim((string)p('current_meter')) !== '') {
        $unit = mb_substr(trim((string)p('meter_unit')), 0, 20) ?: null;
        DB::update('fixed_assets', ['current_meter' => max(0, to_num(p('current_meter'))), 'meter_unit' => $unit], 'id = :id', ['id' => $asset['id']]);
        Audit::log('meter_reading', 'fa', 'asset', (int)$asset['id'], $asset['asset_no'], ['reason' => 'Meter ' . qty(to_num(p('current_meter'))) . ' ' . $unit, 'page_key' => $pageKey]);
        flash('success', 'Meter reading of ' . $asset['asset_no'] . ' updated.');
    }
    redirect($back());
}

// ------------------------------------------------------------------ delete / stop a task
if ($a === 'delete' && is_post()) {
    Access::require($pageKey, 'delete');
    $s = AssetMaint::schedule((int)g('id'));
    if ($s) {
        $logs = (int)DB::value('SELECT COUNT(*) FROM asset_pm_log WHERE schedule_id = :s', ['s' => $s['id']]);
        if ($logs > 0) {
            DB::update('asset_pm_schedules', ['is_active' => 0], 'id = :id', ['id' => $s['id']]);
            flash('success', 'The task was stopped. Its ' . $logs . ' completed entries stay in the log.');
        } else {
            DB::run('DELETE FROM asset_pm_schedules WHERE id = :id', ['id' => $s['id']]);
            flash('success', 'Task deleted.');
        }
        Audit::log('delete', 'fa', 'pm_schedule', (int)$s['id'], $s['asset_no'] . ' ' . $s['task_name'], ['before' => $s, 'page_key' => $pageKey]);
    }
    redirect($back());
}

// ------------------------------------------------------------------ add / edit a task
$edit = gint('id') && $a === '' ? AssetMaint::schedule((int)gint('id')) : null;
handle_post(function () use ($edit, $pageKey, $back) {
    Access::require($pageKey, $edit ? 'edit' : 'create');
    $assetId = pint('asset_id');
    $asset = $assetId ? DB::row('SELECT * FROM fixed_assets WHERE id = :id', ['id' => $assetId]) : null;
    if (!$asset) {
        fail('Choose the fixed asset.');
    }
    $type = isset(AssetMaint::TASK_TYPES[(string)p('task_type')]) ? (string)p('task_type') : 'other';
    $name = mb_substr(trim((string)p('task_name')), 0, 160);
    if ($name === '') {
        $name = AssetMaint::TASK_TYPES[$type];
    }
    $repeat = p('repeats') === '1';
    $iv = $repeat ? max(0, (int)p('interval_value')) : 0;
    $unit = isset(AssetMaint::INTERVAL_UNITS[(string)p('interval_unit')]) ? (string)p('interval_unit') : 'months';
    $meterIv = trim((string)p('meter_interval')) === '' ? null : max(0, to_num(p('meter_interval')));
    $due = pdate('next_due_date');
    $dueMeter = trim((string)p('next_due_meter')) === '' ? null : to_num(p('next_due_meter'));
    if (!$due && $dueMeter === null) {
        fail('Enter when the task is next due: a date, a meter reading, or both.');
    }
    if ($meterIv && $dueMeter === null) {
        fail('Enter the meter reading at which the task is next due.');
    }
    $d = [
        'asset_id' => (int)$asset['id'], 'task_name' => $name, 'task_type' => $type,
        'interval_value' => $iv ?: null, 'interval_unit' => $iv ? $unit : null, 'meter_interval' => $meterIv ?: null,
        'next_due_date' => $due, 'next_due_meter' => $dueMeter, 'remind_days' => max(0, min(365, (int)p('remind_days', '14'))),
        'assigned_to' => mb_substr(trim((string)p('assigned_to')), 0, 120) ?: null, 'notes' => mb_substr(trim((string)p('notes')), 0, 255) ?: null,
        'is_active' => p('is_active', '1') === '1' ? 1 : 0,
    ];
    if (trim((string)p('current_meter')) !== '') {
        DB::update('fixed_assets', ['current_meter' => max(0, to_num(p('current_meter'))), 'meter_unit' => mb_substr(trim((string)p('meter_unit')), 0, 20) ?: ($asset['meter_unit'] ?: null)], 'id = :id', ['id' => $asset['id']]);
    }
    if ($edit) {
        DB::update('asset_pm_schedules', $d, 'id = :id', ['id' => $edit['id']]);
        Audit::log('edit', 'fa', 'pm_schedule', (int)$edit['id'], $asset['asset_no'] . ' ' . $name, ['before' => $edit, 'after' => $d, 'page_key' => $pageKey]);
    } else {
        $d['created_by'] = Auth::id();
        $id = DB::insert('asset_pm_schedules', $d);
        Audit::log('create', 'fa', 'pm_schedule', $id, $asset['asset_no'] . ' ' . $name, ['snapshot' => $d, 'page_key' => $pageKey]);
    }
    flash('success', 'Task saved. It starts reminding ' . ($due ? (int)$d['remind_days'] . ' day(s) before ' . fdate($due) : 'when the meter gets close to ' . qty($dueMeter)) . '.');
    redirect(wants_new() ? url($pageKey, ['new' => 1]) : $back(['id' => null]));
});

// ------------------------------------------------------------------ board
$tab = in_array(g('tab'), ['due', 'upcoming', 'all', 'log'], true) ? g('tab') : 'due';
$filter = ['asset_id' => gint('asset_id'), 'location_id' => gint('location_id'), 'task_type' => (string)g('task_type')];
$all = AssetMaint::tasks(null, $filter + ['with_inactive' => $tab === 'all']);
$counts = ['overdue' => 0, 'due' => 0, 'upcoming' => 0];
foreach ($all as $r) {
    if (isset($counts[$r['state']])) {
        $counts[$r['state']]++;
    }
}
$show = array_values(array_filter($all, fn($r) => $tab === 'all' || ($tab === 'due' ? in_array($r['state'], ['overdue', 'due'], true) : $r['state'] === 'upcoming')));
$showForm = ($edit || g('new') || is_post()) && Access::can($pageKey, $edit ? 'edit' : 'create');
$meterOf = [];
foreach ($assets as $as) {
    $meterOf[(int)$as['id']] = ['m' => $as['current_meter'], 'u' => (string)$as['meter_unit']];
}

layout_start('Preventive Maintenance', ['actions' => page_link($pageKey, ['new' => 1], 'New task', 'btn-primary', 'plus-lg', 'create')
    . ' ' . page_link('fa.maintenance', [], 'Maintenance records', 'btn-outline-secondary', 'tools')
    . ' ' . page_link('rpt.fa_pm_upcoming', [], 'Upcoming report', 'btn-outline-secondary', 'bar-chart')]);
?>
<div class="row g-3 mb-3">
  <div class="col-md-4"><a class="card kpi h-100 text-decoration-none" href="<?= h(url($pageKey, ['tab' => 'due'])) ?>"><div class="card-body"><div class="label small text-muted">Overdue</div><div class="value <?= $counts['overdue'] ? 'text-danger' : '' ?>"><?= (int)$counts['overdue'] ?></div><div class="small text-muted">past their due date or meter</div></div></a></div>
  <div class="col-md-4"><a class="card kpi h-100 text-decoration-none" href="<?= h(url($pageKey, ['tab' => 'due'])) ?>"><div class="card-body"><div class="label small text-muted">Due soon</div><div class="value" style="<?= $counts['due'] ? 'color:#b25e00' : '' ?>"><?= (int)$counts['due'] ?></div><div class="small text-muted">inside their reminder period</div></div></a></div>
  <div class="col-md-4"><a class="card kpi h-100 text-decoration-none" href="<?= h(url($pageKey, ['tab' => 'upcoming'])) ?>"><div class="card-body"><div class="label small text-muted">Upcoming</div><div class="value"><?= (int)$counts['upcoming'] ?></div><div class="small text-muted">scheduled, not reminding yet</div></div></a></div>
</div>

<?php if ($showForm):
    $selAsset = (int)fv('asset_id', $edit, gint('asset_id') ?: '');
    $repeats = is_post() ? p('repeats') === '1' : (!$edit || $edit['interval_value'] || $edit['meter_interval']);
?>
<form method="post" class="card mb-3" id="pmForm"><div class="card-header"><?= $edit ? 'Edit task' : 'New preventive maintenance task' ?></div><div class="card-body row g-2">
  <?= csrf_field() ?>
  <div class="col-md-4"><label class="form-label">Fixed asset</label>
    <select class="form-select form-select-sm" name="asset_id" id="pmAsset" required data-combo data-combo-placeholder="Type the asset name or number"><?= options($assetPairs, $selAsset ?: '', '') ?></select></div>
  <?= select_field('task_type', 'Kind of task', AssetMaint::TASK_TYPES, fv('task_type', $edit, 'other'), ['class' => 'col-md-4', 'extra' => 'id="pmType"']) ?>
  <?= input_field('task_name', 'Task (what has to be done)', fv('task_name', $edit), ['class' => 'col-md-4', 'extra' => 'maxlength="160" id="pmName" placeholder="e.g. LTO registration renewal, Change oil and filter"']) ?>
  <?= input_field('next_due_date', 'Next due date', fv('next_due_date', $edit), ['type' => 'date', 'class' => 'col-md-2']) ?>
  <?= input_field('remind_days', 'Remind (days before)', fv('remind_days', $edit, '14'), ['type' => 'number', 'step' => '1', 'class' => 'col-md-2', 'extra' => 'min="0" max="365"']) ?>
  <div class="col-md-2 d-flex align-items-end"><div class="form-check mb-1"><input type="hidden" name="repeats" value="0"><input class="form-check-input" type="checkbox" id="pmRepeats" name="repeats" value="1" <?= checked($repeats) ?>><label class="form-check-label" for="pmRepeats">Repeats</label></div></div>
  <div class="col-md-3" data-toggle-by="#pmRepeats" data-show-when="1"><label class="form-label">Every</label><div class="input-group input-group-sm">
    <input class="form-control text-end" type="number" min="0" step="1" name="interval_value" value="<?= h(fv('interval_value', $edit, '')) ?>" placeholder="e.g. 12">
    <select class="form-select" name="interval_unit" style="max-width:120px"><?= options(AssetMaint::INTERVAL_UNITS, fv('interval_unit', $edit, 'months')) ?></select></div></div>
  <div class="col-md-3" data-toggle-by="#pmRepeats" data-show-when="1"><label class="form-label">or every (meter: km, hours)</label>
    <input class="form-control form-control-sm text-end" name="meter_interval" value="<?= h(fv('meter_interval', $edit) !== '' && fv('meter_interval', $edit) !== null ? (string)(float)fv('meter_interval', $edit) : '') ?>" inputmode="decimal" placeholder="e.g. 5000"></div>
  <?= input_field('next_due_meter', 'Next due at meter reading', fv('next_due_meter', $edit) !== '' && fv('next_due_meter', $edit) !== null ? (string)(float)fv('next_due_meter', $edit) : '', ['class' => 'col-md-3', 'extra' => 'inputmode="decimal" placeholder="blank = by date only"']) ?>
  <div class="col-md-3"><label class="form-label">Meter reading of the asset now</label><div class="input-group input-group-sm">
    <input class="form-control text-end" name="current_meter" id="pmMeter" value="<?= h(is_post() ? p('current_meter') : '') ?>" inputmode="decimal">
    <input class="form-control" name="meter_unit" id="pmUnit" value="<?= h(is_post() ? p('meter_unit') : '') ?>" placeholder="km" style="max-width:80px" maxlength="20"></div></div>
  <?= input_field('assigned_to', 'Person responsible', fv('assigned_to', $edit), ['class' => 'col-md-3', 'extra' => 'maxlength="120"']) ?>
  <?= input_field('notes', 'Notes (office to go to, requirements, supplier ...)', fv('notes', $edit), ['class' => 'col-md-6', 'extra' => 'maxlength="255"']) ?>
  <?= $edit ? check_field('is_active', 'Active (keeps reminding)', fv('is_active', $edit, 1), 'col-md-3') : '' ?>
  <div class="col-12 small text-muted">A task starts reminding the chosen number of days before its due date, or when the meter is within 10% of the interval. It keeps reminding until someone acknowledges that it is complete; a repeating task then moves to its next due date.</div>
  <div class="col-12"><?= save_buttons('Save task', url($pageKey, ['tab' => $tab]), !$edit) ?></div>
</div></form>
<script>
(function () {
  const meters = <?= json_encode($meterOf) ?>, names = <?= json_encode(AssetMaint::TASK_TYPES) ?>;
  const asset = document.getElementById('pmAsset'), meter = document.getElementById('pmMeter'), unit = document.getElementById('pmUnit'), type = document.getElementById('pmType'), name = document.getElementById('pmName');
  const fill = () => { const m = meters[asset.value]; if (!m) return; if (meter.value === '' && m.m !== null) meter.placeholder = 'last: ' + parseFloat(m.m); if (unit.value === '') unit.value = m.u || ''; };
  asset.addEventListener('change', fill); fill();
  let auto = name.value === '';
  name.addEventListener('input', () => { auto = name.value === ''; });
  type.addEventListener('change', () => { if (auto && type.value !== 'other') name.value = names[type.value].replace(/ \(.*$/, ''); });
})();
</script>
<?php endif; ?>

<ul class="nav nav-tabs mb-0">
  <?php foreach (['due' => 'Reminding now (' . ($counts['overdue'] + $counts['due']) . ')', 'upcoming' => 'Upcoming (' . $counts['upcoming'] . ')', 'all' => 'All tasks', 'log' => 'Completed'] as $k => $label): ?>
    <li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active' : '' ?>" href="<?= h(url($pageKey, ['tab' => $k] + array_filter($filter))) ?>"><?= h($label) ?></a></li>
  <?php endforeach; ?>
</ul>
<form method="get" class="row g-2 my-2 filters"><input type="hidden" name="p" value="<?= h($pageKey) ?>"><input type="hidden" name="tab" value="<?= h($tab) ?>">
  <?= select_field('asset_id', 'Asset', $assetPairs, $filter['asset_id'], ['blank' => 'All assets', 'class' => 'col-md-4', 'extra' => 'data-autosubmit']) ?>
  <?= select_field('location_id', 'Location', DB::pairs('SELECT id, name FROM asset_locations ORDER BY name'), $filter['location_id'], ['blank' => 'All locations', 'class' => 'col-md-3', 'extra' => 'data-autosubmit']) ?>
  <?= select_field('task_type', 'Kind of task', AssetMaint::TASK_TYPES, $filter['task_type'], ['blank' => 'All kinds', 'class' => 'col-md-3', 'extra' => 'data-autosubmit']) ?>
</form>

<?php if ($tab === 'log'):
    $sql = 'SELECT l.*, fa.asset_no, fa.description AS asset_description, u.full_name, m.maint_no FROM asset_pm_log l JOIN fixed_assets fa ON fa.id = l.asset_id
              LEFT JOIN users u ON u.id = l.done_by LEFT JOIN asset_maintenance m ON m.id = l.maintenance_id WHERE 1 = 1';
    $lp = [];
    if ($filter['asset_id']) { $sql .= ' AND l.asset_id = :a'; $lp['a'] = $filter['asset_id']; }
    if ($filter['location_id']) { $sql .= ' AND fa.location_id = :l'; $lp['l'] = $filter['location_id']; }
    $log = DB::all($sql . ' ORDER BY l.done_date DESC, l.id DESC LIMIT 300', $lp);
?>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
  <thead><tr><th>Completed</th><th>Asset</th><th>Task</th><th>Was due</th><th class="num">Meter</th><th>Remarks</th><th>Acknowledged by</th><th>Record</th></tr></thead>
  <?php foreach ($log as $l): ?><tr><td><?= h(fdate($l['done_date'])) ?></td><td><?= h($l['asset_no']) ?><div class="small text-muted"><?= h($l['asset_description']) ?></div></td><td><?= h($l['task_name']) ?></td>
    <td class="<?= $l['due_date'] && $l['done_date'] > $l['due_date'] ? 'text-danger' : '' ?>"><?= h(fdate($l['due_date'])) ?></td><td class="num"><?= $l['done_meter'] !== null ? qty($l['done_meter']) : '' ?></td>
    <td class="small"><?= h($l['remarks']) ?></td><td class="small"><?= h($l['full_name']) ?></td><td><?= $l['maintenance_id'] ? safe_link('fa.maintenance', ['a' => 'view', 'id' => $l['maintenance_id']], (string)$l['maint_no']) : '' ?></td></tr><?php endforeach; ?>
  <?php if (!$log): ?><tr><td colspan="8" class="text-muted py-3">Nothing has been completed yet.</td></tr><?php endif; ?>
</table></div></div>
<?php else: ?>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
  <thead><tr><th>Asset</th><th>Task</th><th>Due</th><th>State</th><th>Repeats</th><th>Last done</th><th>Responsible</th><th></th></tr></thead>
  <?php foreach ($show as $r):
      $rep = [];
      if ($r['interval_value']) { $rep[] = 'every ' . (int)$r['interval_value'] . ' ' . rtrim(AssetMaint::INTERVAL_UNITS[$r['interval_unit']] ?? '', '(s)') . ((int)$r['interval_value'] > 1 ? 's' : ''); }
      if ($r['meter_interval']) { $rep[] = 'every ' . qty($r['meter_interval']) . ' ' . ($r['meter_unit'] ?: 'units'); }
  ?>
    <tr class="pm-<?= h($r['state']) ?>">
      <td><?= safe_link('fa.register', ['a' => 'view', 'id' => $r['asset_id']], (string)$r['asset_no']) ?><div class="small text-muted"><?= h($r['asset_description']) ?><?= $r['asset_location'] ? ', ' . h($r['asset_location']) : '' ?></div></td>
      <td><?= h($r['task_name']) ?><?php if ($r['notes']): ?><div class="small text-muted"><?= h($r['notes']) ?></div><?php endif; ?></td>
      <td class="text-nowrap"><?= h(fdate($r['next_due_date'])) ?><?php if ($r['next_due_meter'] !== null): ?><div class="small text-muted">at <?= qty($r['next_due_meter']) ?> <?= h($r['meter_unit']) ?><?= $r['current_meter'] !== null ? ' (now ' . qty($r['current_meter']) . ')' : '' ?></div><?php endif; ?></td>
      <td><span class="pm-state <?= h($r['state']) ?>"><?= h($r['state_text']) ?></span></td>
      <td class="small"><?= h($rep ? implode(' or ', $rep) : 'one time') ?></td>
      <td class="small"><?= h(fdate($r['last_done_date'])) ?></td><td class="small"><?= h($r['assigned_to']) ?></td>
      <td class="text-nowrap text-end">
        <?php if ((int)$r['is_active'] === 1 && Access::can($pageKey, 'edit')): ?>
          <button type="button" class="btn btn-sm <?= in_array($r['state'], ['overdue', 'due'], true) ? 'btn-success' : 'btn-outline-success' ?>" data-pm-done="<?= (int)$r['id'] ?>" data-task="<?= h($r['task_name']) ?>" data-asset="<?= h($r['asset_no'] . ' - ' . $r['asset_description']) ?>"
            data-meter="<?= $r['meter_interval'] ? '1' : '0' ?>" data-unit="<?= h($r['meter_unit']) ?>" data-now="<?= h($r['current_meter'] !== null ? (string)(float)$r['current_meter'] : '') ?>"><i class="bi bi-check2-circle"></i> Completed</button>
          <a class="btn btn-sm btn-outline-secondary" href="<?= h(url($pageKey, ['id' => $r['id'], 'tab' => $tab])) ?>" title="Edit"><i class="bi bi-pencil"></i></a>
        <?php endif; ?>
        <?php if (Access::can($pageKey, 'delete')): ?>
          <form method="post" action="<?= h(url($pageKey, ['a' => 'delete', 'id' => $r['id'], 'tab' => $tab])) ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" title="Delete" data-confirm="Delete the task <?= h($r['task_name']) ?> of <?= h($r['asset_no']) ?>? A task with completed entries is stopped instead."><i class="bi bi-trash"></i></button></form>
        <?php endif; ?>
      </td></tr>
  <?php endforeach; ?>
  <?php if (!$show): ?><tr><td colspan="8" class="text-muted py-3"><?= $tab === 'due' ? 'Nothing is due. Tasks appear here when they enter their reminder period, and stay until they are acknowledged as complete.' : 'No tasks here yet. Use New task to add one.' ?></td></tr><?php endif; ?>
</table></div></div>
<?php endif; ?>

<div class="modal fade" id="pmDoneModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post" id="pmDoneForm">
  <?= csrf_field() ?>
  <div class="modal-header"><h5 class="modal-title">Acknowledge the task as complete</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <p class="mb-2"><strong id="pmDoneTask"></strong><br><span class="text-muted small" id="pmDoneAsset"></span></p>
    <div class="row g-2">
      <div class="col-6"><label class="form-label">Date completed</label><input class="form-control form-control-sm" type="date" name="done_date" value="<?= h(today()) ?>" max="<?= h(today()) ?>" required></div>
      <div class="col-6"><label class="form-label">Meter reading <span id="pmDoneUnit" class="text-muted fw-normal"></span></label><input class="form-control form-control-sm text-end" name="done_meter" id="pmDoneMeter" inputmode="decimal"></div>
      <div class="col-12"><label class="form-label">Remarks (official receipt no., who did it, findings)</label><input class="form-control form-control-sm" name="remarks" maxlength="255"></div>
      <?php if (Access::can('fa.maintenance', 'create')): ?>
      <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="then" value="record" id="pmThen"><label class="form-check-label" for="pmThen">Then record the parts, labor and payment in Asset Maintenance</label></div></div>
      <?php endif; ?>
    </div>
    <p class="small text-muted mt-2 mb-0">The reminder stops only now. A repeating task moves to its next due date and will remind again.</p>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-success"><i class="bi bi-check2-circle"></i> Task is complete</button></div>
</form></div></div>
<script>
(function () {
  const base = <?= json_encode(url($pageKey, array_filter(['a' => 'complete', 'tab' => $tab, 'asset_id' => $filter['asset_id'], 'location_id' => $filter['location_id']]))) ?>;
  document.querySelectorAll('[data-pm-done]').forEach((b) => b.addEventListener('click', () => {
    document.getElementById('pmDoneForm').action = base + '&id=' + b.dataset.pmDone;
    document.getElementById('pmDoneTask').textContent = b.dataset.task;
    document.getElementById('pmDoneAsset').textContent = b.dataset.asset;
    document.getElementById('pmDoneUnit').textContent = b.dataset.unit ? '(' + b.dataset.unit + ')' : '';
    const m = document.getElementById('pmDoneMeter'); m.required = b.dataset.meter === '1'; m.value = ''; m.placeholder = b.dataset.now ? 'last: ' + b.dataset.now : '';
    new bootstrap.Modal(document.getElementById('pmDoneModal')).show();
  }));
})();
</script>
<?php
layout_end();
