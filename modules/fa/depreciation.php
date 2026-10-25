<?php
declare(strict_types=1);

require_once APP_ROOT . '/modules/txn/_common.php';

$pageKey = 'fa.depreciation';
if (txn_common_actions($pageKey, ['depreciation'])) {
    return;
}
$period = (string)(g('period') ?: p('period') ?: date('Y-m', strtotime('last day of last month')));
if (!preg_match('/^(\d{4})-(\d{2})$/', $period, $m)) {
    $period = date('Y-m');
    preg_match('/^(\d{4})-(\d{2})$/', $period, $m);
}
$y = (int)$m[1];
$mo = (int)$m[2];
// location filter: depreciation can be run one location at a time (-1 = assets without a location)
$locations = DB::pairs('SELECT id, name FROM asset_locations ORDER BY name');
$locationId = (int)(g('location_id') ?: p('location_id'));
if ($locationId !== -1 && !isset($locations[$locationId])) {
    $locationId = 0;
}

handle_post(function () use ($pageKey, $y, $mo, $period, $locationId) {
    Access::require($pageKey, 'create');
    // save usage for units-of-production assets first
    foreach ((array)($_POST['units'] ?? []) as $aid => $u) {
        if (trim((string)$u) === '') {
            continue;
        }
        DB::run('INSERT INTO asset_meter_readings (asset_id, period_year, period_month, units, entered_by) VALUES (:a, :y, :m, :u, :by)
                 ON DUPLICATE KEY UPDATE units = VALUES(units), entered_by = VALUES(entered_by)',
            ['a' => (int)$aid, 'y' => $y, 'm' => $mo, 'u' => to_num($u), 'by' => Auth::id()]);
    }
    if (p('do') === 'save_units') {
        flash('success', 'Usage saved.');
        redirect(url($pageKey, ['period' => $period] + ($locationId ? ['location_id' => $locationId] : [])));
    }
    $only = array_map('intval', (array)($_POST['assets'] ?? []));
    if (!$only) {
        fail('Select the assets to depreciate.');
    }
    [$txnId, $count, $total, $skipped] = Depreciation::run($y, $mo, $only, (string)p('memo'), $locationId ?: null, Classes::ready() ? Classes::posted() : null);
    foreach ($skipped as $s) {
        flash('warning', 'Skipped ' . $s);
    }
    if (!$txnId) {
        fail('Nothing to post for this period.');
    }
    flash('success', "Depreciation of " . money($total) . " posted for $count asset(s): debit Depreciation Expense, credit Accumulated Depreciation.");
    redirect(url($pageKey, ['period' => $period] + ($locationId ? ['location_id' => $locationId] : [])));
});

$rows = Depreciation::preview($y, $mo, null, $locationId ?: null);
$showClass = Classes::ready() && Classes::inUse();
$runs = DB::all("SELECT t.*, u.username, (SELECT COUNT(*) FROM asset_depreciation ad WHERE ad.txn_id = t.id) AS assets FROM transactions t LEFT JOIN users u ON u.id = t.created_by WHERE t.txn_type = 'depreciation' ORDER BY t.txn_date DESC, t.id DESC LIMIT 24");
layout_start('Run Depreciation', ['actions' => page_link('fa.register', [], 'Asset register', 'btn-outline-secondary', 'building')]);
?>
<form method="get" class="row g-2 mb-3 filters"><input type="hidden" name="p" value="<?= h($pageKey) ?>">
  <div class="col-auto"><label class="form-label">Period</label><input class="form-control form-control-sm" type="month" name="period" value="<?= h($period) ?>" data-autosubmit></div>
  <div class="col-md-3"><label class="form-label">Location</label><select class="form-select form-select-sm" name="location_id" data-autosubmit><?= options($locations + [-1 => '(No location)'], $locationId ?: '', 'All locations') ?></select></div>
  <div class="col-auto small d-flex align-items-end text-muted">Entries are dated <?= h(fdate(Depreciation::monthEnd($y, $mo))) ?>.</div>
</form>
<form method="post" data-no-class-check><?= csrf_field() ?><input type="hidden" name="period" value="<?= h($period) ?>"><input type="hidden" name="location_id" value="<?= h($locationId ?: '') ?>">
<div class="card mb-3"><div class="table-responsive"><table class="table table-sm table-tight mb-0">
  <thead><tr><th><input type="checkbox" class="form-check-input" data-check-all=".dep-cb" checked></th><th>Asset</th><th>Category</th><th>Location</th><?php if ($showClass): ?><th>Default class</th><?php endif; ?><th>Method</th><th class="num">Cost</th><th class="num">Accumulated</th><th class="num">Usage this month</th><th class="num">Depreciation</th><th>Notes</th></tr></thead>
  <?php $total = 0.0; foreach ($rows as $r): $total += $r['amount']; ?>
    <tr class="<?= $r['done'] ? 'text-muted' : '' ?>">
      <td><?php if (!$r['done'] && ($r['amount'] > 0 || $r['method'] === 'units_of_production')): ?><input type="checkbox" class="form-check-input dep-cb" name="assets[]" value="<?= (int)$r['id'] ?>" checked><?php endif; ?></td>
      <td><?= safe_link('fa.register', ['a' => 'view', 'id' => $r['id']], $r['asset_no'] . ' - ' . $r['description']) ?></td><td class="small"><?= h($r['category']) ?></td>
      <td class="small"><?= h($r['location']) ?></td><?php if ($showClass): ?><td class="small"><?= h(Classes::name($r['class_id'] ?? 0)) ?></td><?php endif; ?>
      <td class="small"><?= h(method_label($r['method'])) ?></td><td class="num"><?= money($r['cost']) ?></td><td class="num"><?= money($r['accumulated']) ?></td>
      <td class="num"><?php if ($r['method'] === 'units_of_production' && !$r['done']): ?><input class="form-control form-control-sm text-end ms-auto" style="width:110px" name="units[<?= (int)$r['id'] ?>]" value="<?= h($r['units'] !== null ? qty($r['units']) : '') ?>" placeholder="<?= h($r['unit_label']) ?>"><?php elseif ($r['units'] !== null): ?><?= qty($r['units']) ?><?php endif; ?></td>
      <td class="num"><?= $r['done'] ? 'posted' : money($r['amount']) ?></td>
      <td class="small"><?php
          if ($r['missing'] > 0) { echo '<span class="text-warning">' . (int)$r['missing'] . ' earlier month(s) not yet depreciated</span> '; }
          if ($r['method'] === 'units_of_production' && $r['units'] === null && !$r['done']) { echo '<span class="text-danger">enter usage first</span>'; }
          if ($r['status'] === 'fully_depreciated') { echo 'fully depreciated'; }
      ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="<?= $showClass ? 11 : 10 ?>" class="text-center text-muted">No assets in service for this period<?= $locationId ? ' at this location' : '' ?>.</td></tr><?php endif; ?>
  <tfoot><tr class="fw-bold"><td colspan="<?= $showClass ? 9 : 8 ?>" class="text-end">Total to post</td><td class="num"><?= money($total) ?></td><td></td></tr></tfoot>
</table></div></div>
<?php if (Access::can($pageKey, 'create') && $rows): ?>
<div class="d-flex gap-2 align-items-center">
  <input class="form-control form-control-sm" style="max-width:360px" name="memo" placeholder="Memo (optional)">
  <?php if (Classes::ready()): ?><select class="form-select form-select-sm" style="max-width:300px" name="class_id" title="Choose a class to record the whole entry under it. Leave it as it is to record every asset under its own default class."><?= options(Classes::options(), fv('class_id'), Classes::inUse() ? "Class: each asset's own default class" : 'No classes yet') ?></select><?php endif; ?>
  <button class="btn btn-outline-secondary btn-sm" name="do" value="save_units">Save usage only</button>
  <button class="btn btn-primary" name="do" value="run">Post depreciation</button>
</div>
<p class="small text-muted mt-2">Units-of-production assets use the usage entered for the month. Net book value never goes below salvage value.
  <?php if ($showClass): ?>When no class is chosen, the entry records each asset under the default class set on the asset (Fixed Asset Register).<?php endif; ?>
  <?php if ($locationId): ?>Only the assets of the chosen location are listed and posted; run the other locations separately.<?php endif; ?></p>
<?php endif; ?>
</form>
<div class="card mt-3"><div class="card-header fw-semibold">Posted depreciation</div><table class="table table-sm mb-0">
  <thead><tr><th>Date</th><th>Entry</th><th>Memo</th><th class="num">Assets</th><th class="num">Amount</th><th>By</th></tr></thead>
  <?php foreach ($runs as $r): ?><tr class="<?= (int)$r['is_voided'] ? 'text-muted text-decoration-line-through' : '' ?>"><td><?= h(fdate($r['txn_date'])) ?></td><td><a href="<?= h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) ?>"><?= h($r['txn_no']) ?></a></td><td><?= h($r['memo']) ?></td><td class="num"><?= (int)$r['assets'] ?></td><td class="num"><?= money($r['total']) ?></td><td><?= h($r['username']) ?></td></tr><?php endforeach; ?>
</table><div class="card-body small text-muted py-2">To undo a run, open it and delete or void it; the assets can then be depreciated again for that period.</div></div>
<?php
layout_end();
