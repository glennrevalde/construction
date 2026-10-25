<?php
declare(strict_types=1);

require_once APP_ROOT . '/modules/txn/_common.php';

$pageKey = 'fa.disposal';
if (txn_common_actions($pageKey, ['asset_disposal'])) {
    return;
}
$assetId = (int)(g('asset_id') ?: p('asset_id') ?: 0);
$asset = $assetId ? Depreciation::asset($assetId) : null;
if ($asset && $asset['status'] === 'disposed') {
    $asset = null;
}
$date = (string)(pdate('disposal_date') ?: gdate('date', today()));

/** Months still to depreciate up to and including the disposal month */
function missing_periods(array $asset, string $date): array
{
    $start = Depreciation::idx($asset['acquisition_date']);
    if ($asset['opening_through']) {
        $start = max($start, Depreciation::idx($asset['opening_through']) + 1);
    }
    $end = Depreciation::idx($date);
    $out = [];
    for ($i = $start; $i <= $end; $i++) {
        [$y, $m] = Depreciation::idxToYm($i);
        if (!Depreciation::alreadyRun((int)$asset['id'], $y, $m)) {
            $out[] = [$y, $m];
        }
    }
    return $out;
}

handle_post(function () use ($pageKey, $asset, $date) {
    Access::require($pageKey, 'create');
    if (!$asset) {
        fail('Choose an asset in service.');
    }
    if ($date < $asset['acquisition_date']) {
        fail('The disposal date is before the acquisition date.');
    }
    $reason = trim((string)p('reason'));
    if ($reason === '') {
        fail('Enter how and why the asset was disposed of.');
    }
    $proceeds = r2(pnum('proceeds'));
    $proceedsAcct = pint('proceeds_account_id');
    if ($proceeds > 0 && !$proceedsAcct) {
        fail('Choose the account that received the proceeds.');
    }
    $id = DB::tx(function () use ($asset, $date, $proceeds, $proceedsAcct, $reason, $pageKey) {
        // bring depreciation up to date first (prorated to the disposal month)
        if (p('catch_up') === '1') {
            foreach (missing_periods($asset, $date) as [$y, $m]) {
                if ($asset['method'] === 'units_of_production' && DB::value('SELECT units FROM asset_meter_readings WHERE asset_id = :a AND period_year = :y AND period_month = :m', ['a' => $asset['id'], 'y' => $y, 'm' => $m]) === null) {
                    continue;
                }
                Depreciation::run($y, $m, [(int)$asset['id']], 'Depreciation to disposal date - ' . $asset['asset_no']);
            }
        }
        $fresh = Depreciation::asset((int)$asset['id']);
        $accum = Depreciation::accumulated($fresh);
        $cost = (float)$fresh['cost'];
        $gain = r2($proceeds - ($cost - $accum));
        $lines = [
            ['line_kind' => 'journal', 'account_id' => (int)$fresh['accum_dep_account_id'], 'amount' => r2($accum), 'description' => 'Remove accumulated depreciation'],
            ['line_kind' => 'journal', 'account_id' => (int)$fresh['asset_account_id'], 'amount' => -r2($cost), 'description' => 'Remove asset cost'],
        ];
        if ($proceeds > 0) {
            $lines[] = ['line_kind' => 'journal', 'account_id' => $proceedsAcct, 'amount' => $proceeds, 'description' => 'Proceeds from disposal'];
        }
        if (abs($gain) >= 0.005) {
            $lines[] = ['line_kind' => 'journal', 'account_id' => Accts::get('gain_loss_disposal'), 'amount' => -$gain, 'description' => $gain > 0 ? 'Gain on disposal' : 'Loss on disposal'];
        }
        $txnId = Txn::save('asset_disposal', ['txn_date' => $date, 'memo' => 'Disposal of ' . $fresh['asset_no'] . ' ' . $fresh['description'] . ' - ' . $reason, 'site_id' => $fresh['site_id']], $lines, null, ['reason' => $reason]);
        DB::update('fixed_assets', ['status' => 'disposed', 'disposal_date' => $date, 'disposal_proceeds' => $proceeds, 'disposal_txn_id' => $txnId], 'id = :id', ['id' => $fresh['id']]);
        Audit::log('dispose', 'fa', 'asset', (int)$fresh['id'], $fresh['asset_no'] . ' ' . $fresh['description'], [
            'amount' => $proceeds, 'reason' => $reason . ', ' . ($gain >= 0 ? 'gain ' : 'loss ') . money(abs($gain)), 'page_key' => $pageKey,
        ]);
        return $txnId;
    });
    flash('success', 'Asset disposed of. Its cost and accumulated depreciation were removed and the gain or loss recorded.');
    redirect(url($pageKey, ['a' => 'view', 'id' => $id]));
});

$assets = Lookup::assets(true);
$disposed = DB::all("SELECT fa.*, ac.name AS category FROM fixed_assets fa JOIN asset_categories ac ON ac.id = fa.category_id WHERE fa.status = 'disposed' ORDER BY fa.disposal_date DESC");
layout_start('Dispose of Assets');
?>
<form method="get" class="row g-2 mb-3 filters"><input type="hidden" name="p" value="<?= h($pageKey) ?>">
  <?= select_field('asset_id', 'Asset', $assets, $assetId, ['blank' => '— choose —', 'class' => 'col-md-5', 'extra' => 'data-autosubmit data-combo']) ?>
  <div class="col-md-2"><label class="form-label">Disposal date</label><input class="form-control form-control-sm" type="date" name="date" value="<?= h($date) ?>" data-autosubmit></div>
</form>
<?php if ($asset):
    $accum = Depreciation::accumulated($asset);
    $missing = missing_periods($asset, $date);
    $pending = 0.0;
    foreach ($missing as [$yy, $mm]) {
        $pending += Depreciation::rawMonthly($asset, $yy * 12 + $mm - 1, null);
    }
    $pending = r2(min($pending, max(0, (float)$asset['cost'] - (float)$asset['salvage_value'] - $accum)));
?>
<form method="post" class="card mb-3"><div class="card-body">
  <?= csrf_field() ?><input type="hidden" name="asset_id" value="<?= (int)$asset['id'] ?>"><input type="hidden" name="disposal_date" value="<?= h($date) ?>">
  <div class="row g-2 mb-2 small">
    <div class="col-md-3">Cost: <strong><?= money($asset['cost']) ?></strong></div>
    <div class="col-md-3">Accumulated depreciation: <strong><?= money($accum) ?></strong></div>
    <div class="col-md-3">Net book value: <strong><?= money((float)$asset['cost'] - $accum) ?></strong></div>
    <div class="col-md-3">Method: <?= h(method_label($asset['method'])) ?></div>
  </div>
  <?php if ($missing): ?>
    <div class="alert alert-warning py-2 small"><?= count($missing) ?> month(s) of depreciation up to <?= h(date('F Y', strtotime($date))) ?> are not yet posted (about <?= money($pending) ?>).
      <label class="ms-2"><input type="checkbox" name="catch_up" value="1" checked> Post them first (prorated to the disposal month)</label></div>
  <?php endif; ?>
  <div class="row g-2">
    <?= input_field('reason', 'How / why (sold, scrapped, donated…)', fv('reason'), ['class' => 'col-md-5', 'required' => true]) ?>
    <?= input_field('proceeds', 'Proceeds received', fv('proceeds', null, '0'), ['class' => 'col-md-2']) ?>
    <?= select_field('proceeds_account_id', 'Received into', Accts::depositTo(), fv('proceeds_account_id', null, Accts::get('undeposited_funds')), ['class' => 'col-md-3']) ?>
    <?= Classes::field(fv('class_id', null, $asset['class_id'] ?? ''), 'col-md-3') ?>
    <div class="col-md-2 d-flex align-items-end"><button class="btn btn-danger" data-confirm="Dispose of this asset?">Record disposal</button></div>
  </div>
  <p class="small text-muted mt-2 mb-0">The entry debits Accumulated Depreciation and the proceeds account, credits the asset account, and records the difference as a gain or loss on disposal.</p>
</div></form>
<?php endif; ?>
<div class="card"><div class="card-header fw-semibold">Disposed assets</div><table class="table table-sm mb-0">
  <thead><tr><th>Asset</th><th>Category</th><th>Disposed</th><th class="num">Cost</th><th class="num">Proceeds</th><th>Entry</th></tr></thead>
  <?php foreach ($disposed as $d): ?><tr><td><?= safe_link('fa.register', ['a' => 'view', 'id' => $d['id']], $d['asset_no'] . ' - ' . $d['description']) ?></td><td><?= h($d['category']) ?></td><td><?= h(fdate($d['disposal_date'])) ?></td>
    <td class="num"><?= money($d['cost']) ?></td><td class="num"><?= money($d['disposal_proceeds']) ?></td><td><?= $d['disposal_txn_id'] ? Txn::link((int)$d['disposal_txn_id']) : '' ?></td></tr><?php endforeach; ?>
</table></div>
<?php
layout_end();
