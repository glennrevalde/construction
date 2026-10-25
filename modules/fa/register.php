<?php
declare(strict_types=1);

require_once APP_ROOT . '/modules/txn/_common.php';

$pageKey = 'fa.register';
$a = g('a');
$cats = DB::all('SELECT * FROM asset_categories WHERE is_active = 1 ORDER BY name');
$catPairs = array_column($cats, 'name', 'id');
$locations = DB::pairs('SELECT id, name FROM asset_locations WHERE is_active = 1 ORDER BY name');
$methods = ['straight_line' => 'Straight-line', 'declining_balance' => 'Declining balance', 'units_of_production' => 'Units of production', 'syd' => "Sum-of-the-years'-digits"];

/**
 * Keeps the purchase transaction in step when the acquired date or the cost of an asset is corrected.
 * Only the line(s) that carry the asset (the category's asset account, old cost) are changed; the class,
 * the payee and every other line stay as they are. Closed periods, reconciled checks and paid bills are
 * protected by Txn::save, which stops the correction with its own message.
 */
function fa_sync_purchase(array $asset, string $newDate, float $newCost): void
{
    $txnId = (int)($asset['acquisition_txn_id'] ?? 0);
    $txn = $txnId ? Txn::load($txnId) : null;
    if (!$txn || (int)$txn['is_voided'] === 1 || !in_array($txn['txn_type'], ['check', 'bill', 'opening_balance'], true)) {
        return;
    }
    $oldCost = (float)$asset['cost'];
    $diff = r2($newCost - $oldCost);
    $lines = DB::all('SELECT * FROM transaction_lines WHERE txn_id = :t ORDER BY line_no, id', ['t' => $txnId]);
    $hit = false;
    foreach ($lines as &$l) {
        if (!$hit && (int)$l['account_id'] === (int)$asset['asset_account_id'] && abs((float)$l['amount'] - $oldCost) < 0.005) {
            $l['amount'] = $newCost;
            if ($l['tax_code_id']) {
                $l['tax_amount'] = r2($newCost * tax_rate((int)$l['tax_code_id']) / 100);
            }
            $hit = true;
        }
    }
    unset($l);
    if (!$hit && abs($diff) > 0.004) {
        fail('The purchase was recorded on ' . Txn::ref($txn) . ', and its asset line no longer matches the old cost. Correct that transaction first, then the asset.');
    }
    if ($txn['txn_type'] === 'opening_balance' && abs($diff) > 0.004) {
        foreach ($lines as &$l) {
            if ((int)$l['account_id'] === Accts::get('opening_equity')) {
                $l['amount'] = r2((float)$l['amount'] - $diff);
                break;
            }
        }
        unset($l);
    }
    $keep = ['id', 'line_kind', 'item_id', 'account_id', 'description', 'qty', 'rate', 'amount', 'tax_code_id', 'tax_amount', 'customer_id', 'vendor_id', 'other_name_id', 'class_id', 'site_id', 'is_billable'];
    $clean = array_map(fn($l) => array_intersect_key($l, array_flip($keep)), $lines);
    Txn::save($txn['txn_type'], ['txn_date' => $newDate, 'class_id' => $txn['class_id'] ?? null] + ($txn['txn_type'] === 'bill' && $txn['due_date'] && $newDate !== $txn['txn_date']
        ? ['due_date' => Txn::dueDate($newDate, $txn['terms_id'] ? (int)$txn['terms_id'] : null)] : []), $clean, $txnId, ['reason' => 'Asset ' . $asset['asset_no'] . ' acquisition corrected']);
}

// ------------------------------------------------------------------ meter readings
if ($a === 'meter' && is_post()) {
    Access::require($pageKey, 'edit');
    $asset = Depreciation::asset((int)g('id'));
    $y = (int)p('year');
    $m = (int)p('month');
    if ($asset && $y > 2000 && $m >= 1 && $m <= 12) {
        DB::run('INSERT INTO asset_meter_readings (asset_id, period_year, period_month, units, entered_by) VALUES (:a, :y, :m, :u, :by)
                 ON DUPLICATE KEY UPDATE units = VALUES(units), entered_by = VALUES(entered_by)',
            ['a' => $asset['id'], 'y' => $y, 'm' => $m, 'u' => pnum('units'), 'by' => Auth::id()]);
        Audit::log('meter_reading', 'fa', 'asset', (int)$asset['id'], $asset['asset_no'], ['reason' => sprintf('%04d-%02d: %s %s', $y, $m, qty(pnum('units')), $asset['unit_label']), 'page_key' => $pageKey]);
        flash('success', 'Usage recorded.');
    }
    redirect(url($pageKey, ['a' => 'view', 'id' => g('id')]));
}

// ------------------------------------------------------------------ view
if ($a === 'view') {
    $asset = Depreciation::asset((int)g('id'));
    if (!$asset) {
        redirect(url($pageKey));
    }
    $accum = Depreciation::accumulated($asset);
    $hist = DB::all('SELECT ad.*, t.txn_no, t.txn_type, t.id AS tid FROM asset_depreciation ad JOIN transactions t ON t.id = ad.txn_id WHERE ad.asset_id = :a AND t.is_voided = 0 ORDER BY ad.period_year, ad.period_month', ['a' => $asset['id']]);
    $meters = DB::all('SELECT * FROM asset_meter_readings WHERE asset_id = :a ORDER BY period_year DESC, period_month DESC LIMIT 24', ['a' => $asset['id']]);
    $life = max(1, (int)round((float)$asset['useful_life_years']));
    $sched = $asset['method'] !== 'units_of_production' ? Depreciation::annualSchedule((float)$asset['cost'], (float)$asset['salvage_value'], $life, $asset['method'], (float)$asset['db_multiplier']) : [];
    layout_start($asset['asset_no'] . ' - ' . $asset['description'], ['actions' =>
        page_link($pageKey, ['a' => 'edit', 'id' => $asset['id']], 'Edit', 'btn-primary', 'pencil', 'edit')
        . ' ' . ($asset['status'] !== 'disposed' ? page_link('fa.disposal', ['asset_id' => $asset['id']], 'Dispose', 'btn-outline-danger', 'trash', 'create') : '')
        . ' ' . page_link('rpt.fa_schedule', ['asset_id' => $asset['id']], 'Compare methods', 'btn-outline-secondary', 'bar-chart')
        . ' ' . btn_link(url($pageKey), 'Back')]);
    ?>
    <div class="row g-3">
      <div class="col-lg-5"><div class="card"><div class="card-body">
        <table class="table table-sm mb-0">
          <tr><td>Category</td><td><?= h($asset['category']) ?></td></tr>
          <tr><td>Location</td><td><?= h($asset['location']) ?></td></tr>
          <?php if (Classes::ready() && !empty($asset['class_id'])): ?><tr><td>Default class</td><td><?= h(Classes::name($asset['class_id'])) ?></td></tr><?php endif; ?>
          <tr><td>Serial no.</td><td><?= h($asset['serial_no']) ?></td></tr>
          <tr><td>Acquired</td><td><?= h(fdate($asset['acquisition_date'])) ?> <?= $asset['acquisition_txn_id'] ? Txn::link((int)$asset['acquisition_txn_id'], '(source)') : '' ?></td></tr>
          <tr><td>Cost</td><td class="num"><?= money($asset['cost']) ?></td></tr>
          <tr><td>Salvage value</td><td class="num"><?= money($asset['salvage_value']) ?></td></tr>
          <tr><td>Useful life</td><td><?= h((float)$asset['useful_life_years']) ?> years</td></tr>
          <tr><td>Method</td><td><?= h(method_label($asset['method'])) ?><?= $asset['method'] === 'declining_balance' ? ' (' . h((float)$asset['db_multiplier']) . '× straight-line rate = ' . pct((float)$asset['db_multiplier'] / max(1, $life) * 100) . ')' : '' ?></td></tr>
          <?php if ($asset['method'] === 'units_of_production'): ?><tr><td>Estimated total output</td><td><?= qty($asset['total_units']) ?> <?= h($asset['unit_label']) ?> (rate <?= money(((float)$asset['cost'] - (float)$asset['salvage_value']) / max(1, (float)$asset['total_units'])) ?> per unit)</td></tr><?php endif; ?>
          <?php if ($asset['basis_date']): ?><tr><td>Change in estimate</td><td class="small">From <?= h(fdate($asset['basis_date'])) ?>: NBV <?= money($asset['basis_nbv']) ?> over <?= (int)$asset['basis_life_months'] ?> months</td></tr><?php endif; ?>
          <tr class="fw-semibold"><td>Accumulated depreciation</td><td class="num"><?= money($accum) ?></td></tr>
          <tr class="fw-semibold"><td>Net book value</td><td class="num"><?= money((float)$asset['cost'] - $accum) ?></td></tr>
          <tr><td>Status</td><td><?= status_badge($asset['status']) ?> <?= $asset['disposal_date'] ? h(fdate($asset['disposal_date'])) . ' ' . ($asset['disposal_txn_id'] ? Txn::link((int)$asset['disposal_txn_id'], '(entry)') : '') : '' ?></td></tr>
        </table>
      </div></div>
      <?php if ($asset['method'] === 'units_of_production' && Access::can($pageKey, 'edit')): ?>
      <form method="post" action="<?= h(url($pageKey, ['a' => 'meter', 'id' => $asset['id']])) ?>" class="card mt-3"><div class="card-header">Record usage (meter reading / production count)</div><div class="card-body row g-2">
        <?= csrf_field() ?>
        <?= input_field('year', 'Year', date('Y'), ['class' => 'col-4']) ?><?= input_field('month', 'Month', date('n'), ['class' => 'col-4']) ?>
        <?= input_field('units', 'Units used (' . $asset['unit_label'] . ')', '', ['class' => 'col-4']) ?>
        <div class="col-12"><button class="btn btn-sm btn-primary">Save</button></div>
        <?php foreach ($meters as $mt): ?><div class="col-6 small"><?= sprintf('%04d-%02d', $mt['period_year'], $mt['period_month']) ?>: <?= qty($mt['units']) ?></div><?php endforeach; ?>
      </div></form>
      <?php endif; ?>
      </div>
      <div class="col-lg-7">
        <?php if ($sched): ?>
        <div class="card mb-3"><div class="card-header">Annual schedule (<?= h(method_label($asset['method'])) ?>)</div><table class="table table-sm mb-0">
          <thead><tr><th>Asset year</th><th class="num">Depreciation</th><th class="num">Accumulated</th><th class="num">Net book value</th></tr></thead>
          <?php $acc = 0.0; foreach ($sched as $y => $amt): $acc += $amt; ?><tr><td><?= $y + 1 ?> (from <?= h(date('M Y', strtotime($asset['acquisition_date'] . " +$y year"))) ?>)</td><td class="num"><?= money($amt) ?></td><td class="num"><?= money($acc) ?></td><td class="num"><?= money((float)$asset['cost'] - $acc) ?></td></tr><?php endforeach; ?>
        </table><div class="card-body small text-muted py-2">Depreciation is charged monthly (one-twelfth of the asset-year amount), so partial years are prorated by month.</div></div>
        <?php endif; ?>
        <div class="card"><div class="card-header">Depreciation posted</div><div class="table-responsive" style="max-height:360px"><table class="table table-sm mb-0">
          <thead><tr><th>Period</th><th>Entry</th><th class="num">Units</th><th class="num">Amount</th></tr></thead>
          <?php if ((float)$asset['opening_accum_dep'] > 0): ?><tr><td>Before <?= h(fdate($asset['opening_through'])) ?></td><td>Opening balance</td><td></td><td class="num"><?= money($asset['opening_accum_dep']) ?></td></tr><?php endif; ?>
          <?php foreach ($hist as $hh): ?><tr><td><?= sprintf('%04d-%02d', $hh['period_year'], $hh['period_month']) ?></td><td><?= Txn::link(['id' => $hh['tid'], 'txn_type' => $hh['txn_type'], 'txn_no' => $hh['txn_no']]) ?></td><td class="num"><?= $hh['units_used'] !== null ? qty($hh['units_used']) : '' ?></td><td class="num"><?= money($hh['amount']) ?></td></tr><?php endforeach; ?>
        </table></div></div>
      </div>
    </div>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ new / edit
if ($a === 'new' || $a === 'edit') {
    $asset = null;
    if ($a === 'edit') {
        Access::require($pageKey, 'edit');
        $asset = Depreciation::asset((int)g('id'));
        if (!$asset) {
            redirect(url($pageKey));
        }
    } else {
        Access::require($pageKey, 'create');
    }
    $hasDep = $asset && ((int)DB::value('SELECT COUNT(*) FROM asset_depreciation WHERE asset_id = :a', ['a' => $asset['id']]) > 0 || (float)$asset['opening_accum_dep'] > 0);

    handle_post(function () use ($asset, $hasDep, $pageKey, $cats) {
        $locId = pint('location_id');
        $locName = $locId ? DB::value('SELECT name FROM asset_locations WHERE id = :id', ['id' => $locId]) : null;
        if ($locId && $locName === null) {
            fail('Choose a location from the list (Fixed Assets > Asset Locations).');
        }
        $catId = pint('category_id');
        $cat = null;
        foreach ($cats as $c) {
            if ((int)$c['id'] === $catId) {
                $cat = $c;
            }
        }
        if (!$cat) {
            fail('Choose the asset category.');
        }
        $method = (string)p('method');
        if (!in_array($method, Depreciation::METHODS, true)) {
            fail('Choose a depreciation method.');
        }
        $d = [
            'description' => mb_substr(trim((string)p('description')), 0, 160),
            'category_id' => $catId,
            'location' => $locName ?: null,
            'location_id' => $locId ?: null,
            'site_id' => pint('site_id'),
            'class_id' => Classes::ready() ? Classes::posted('asset_class_id') : null,
            'serial_no' => p('serial_no') ?: null,
            'vendor_id' => pint('vendor_id'),
            'salvage_value' => r2(pnum('salvage_value')),
            'useful_life_years' => round(pnum('useful_life_years'), 2),
            'method' => $method,
            'db_multiplier' => round(pnum('db_multiplier', 2) ?: 2, 2),
            'total_units' => $method === 'units_of_production' ? pnum('total_units') : null,
            'unit_label' => p('unit_label') ?: null,
            'notes' => p('notes') ?: null,
        ];
        if ($d['description'] === '' || $d['useful_life_years'] <= 0) {
            fail('Enter the description and a useful life greater than zero.');
        }
        if ($method === 'units_of_production' && (float)$d['total_units'] <= 0) {
            fail('Enter the estimated total units for units-of-production depreciation.');
        }
        if ($asset) {
            if ((int)$asset['category_id'] !== $catId && $hasDep) {
                fail('The category cannot change after depreciation has been posted.');
            }
            $estimateChanged = $hasDep && (
                $asset['method'] !== $d['method'] || abs((float)$asset['useful_life_years'] - $d['useful_life_years']) > 0.001
                || abs((float)$asset['salvage_value'] - $d['salvage_value']) > 0.004 || (float)$asset['db_multiplier'] != $d['db_multiplier']
                || (float)$asset['total_units'] != (float)$d['total_units']
            );
            if ($estimateChanged) {
                // new basis applied to the remaining net book value from the change date forward
                $lastIdx = (int)DB::value('SELECT MAX(period_year*12+period_month-1) FROM asset_depreciation ad JOIN transactions t ON t.id = ad.txn_id AND t.is_voided = 0 WHERE ad.asset_id = :a', ['a' => $asset['id']]);
                if (!$lastIdx && $asset['opening_through']) {
                    $lastIdx = Depreciation::idx($asset['opening_through']);
                }
                $startIdx = max($lastIdx + 1, Depreciation::idx(pdate('change_date', today())));
                [$yy, $mm] = Depreciation::idxToYm($startIdx);
                $elapsed = $startIdx - Depreciation::idx($asset['acquisition_date']);
                $d['basis_date'] = sprintf('%04d-%02d-01', $yy, $mm);
                $d['basis_nbv'] = r2((float)$asset['cost'] - Depreciation::accumulated($asset));
                $d['basis_life_months'] = max(1, (int)round($d['useful_life_years'] * 12) - $elapsed);
                if ($d['basis_nbv'] < $d['salvage_value']) {
                    fail('The new salvage value is higher than the remaining net book value.');
                }
            }
            // acquisition date and cost: two boxes of their own, open for correction until depreciation is posted
            $newDate = pdate('acquisition_date');
            $newCost = r2(pnum('cost'));
            $acqChanged = !$hasDep && $newDate && $newCost > 0
                && ($newDate !== $asset['acquisition_date'] || abs($newCost - (float)$asset['cost']) > 0.004);
            if (!$hasDep && array_key_exists('cost', $_POST) && (!$newDate || $newCost <= 0)) {
                fail('Enter the acquisition date and a cost greater than zero.');
            }
            if ($acqChanged && $newCost < $d['salvage_value']) {
                fail('The cost cannot be lower than the salvage value.');
            }
            DB::tx(function () use ($asset, &$d, $acqChanged, $newDate, $newCost) {
                if ($acqChanged) {
                    $d['acquisition_date'] = $newDate;
                    $d['cost'] = $newCost;
                    fa_sync_purchase($asset, $newDate, $newCost);
                }
                DB::update('fixed_assets', $d, 'id = :id', ['id' => $asset['id']]);
            });
            Audit::log($estimateChanged ? 'change_in_estimate' : 'edit', 'fa', 'asset', (int)$asset['id'], $asset['asset_no'] . ' ' . $d['description'], [
                'before' => $asset, 'after' => $d, 'page_key' => $pageKey,
                'reason' => $estimateChanged ? 'Applied prospectively from ' . fdate($d['basis_date']) . '; past depreciation unchanged'
                    : ($acqChanged ? 'Acquisition corrected: ' . fdate($asset['acquisition_date']) . ', ' . money($asset['cost']) . ' changed to ' . fdate($newDate) . ', ' . money($newCost) : null),
            ]);
            $id = (int)$asset['id'];
        } else {
            $date = pdate('acquisition_date');
            $cost = r2(pnum('cost'));
            if (!$date || $cost <= 0) {
                fail('Enter the acquisition date and cost.');
            }
            $d['acquisition_date'] = $date;
            $d['cost'] = $cost;
            $d['asset_no'] = Seq::next('asset');
            $funding = p('funding');
            $opening = r2(pnum('opening_accum_dep'));
            $id = DB::tx(function () use ($d, $funding, $cat, $cost, $date, $opening, $pageKey) {
                $txnId = null;
                $taxId = p('with_vat') === '1' ? (int)DB::value("SELECT id FROM tax_codes WHERE code = 'VAT12'") : null;
                $line = ['line_kind' => 'expense', 'account_id' => (int)$cat['asset_account_id'], 'amount' => $cost, 'description' => $d['description'],
                    'tax_code_id' => $taxId, 'tax_amount' => $taxId ? r2($cost * tax_rate($taxId) / 100) : 0];
                if ($funding === 'check') {
                    $bank = pint('bank_account_id');
                    if (!$bank) {
                        fail('Choose the bank account for the check.');
                    }
                    $approver = Approval::check('bank.write_checks', Approval::exceeds(Access::limit('max_amount'), $cost), 'check above your amount limit');
                    $txnId = Txn::save('check', ['txn_date' => $date, 'account_id' => $bank, 'vendor_id' => $d['vendor_id'], 'payee_name' => $d['vendor_id'] ? null : (p('payee') ?: 'Asset purchase'),
                        'memo' => 'Purchase of ' . $d['description'], 'approved_by' => $approver['id'] ?? null], [$line], null, ['approved_by' => $approver['username'] ?? null]);
                } elseif ($funding === 'bill') {
                    if (!$d['vendor_id']) {
                        fail('Choose the vendor for the bill.');
                    }
                    $terms = DB::value('SELECT terms_id FROM vendors WHERE id = :v', ['v' => $d['vendor_id']]);
                    $txnId = Txn::save('bill', ['txn_date' => $date, 'vendor_id' => $d['vendor_id'], 'account_id' => Accts::get('ap'), 'terms_id' => $terms,
                        'due_date' => Txn::dueDate($date, $terms ? (int)$terms : null), 'ref_no' => p('vendor_ref') ?: null, 'memo' => 'Purchase of ' . $d['description']], [$line]);
                } elseif ($funding === 'opening') {
                    $through = pdate('opening_through');
                    if ($opening > 0 && !$through) {
                        fail('Enter the date depreciation was recorded through.');
                    }
                    $d['opening_accum_dep'] = $opening;
                    $d['opening_through'] = $through;
                    $lines = [
                        ['line_kind' => 'journal', 'account_id' => (int)$cat['asset_account_id'], 'amount' => $cost, 'description' => 'Opening balance - ' . $d['description']],
                        ['line_kind' => 'journal', 'account_id' => Accts::get('opening_equity'), 'amount' => -r2($cost - $opening), 'description' => 'Opening balance'],
                    ];
                    if ($opening > 0) {
                        $lines[] = ['line_kind' => 'journal', 'account_id' => (int)$cat['accum_dep_account_id'], 'amount' => -$opening, 'description' => 'Opening accumulated depreciation'];
                    }
                    $txnId = Txn::save('opening_balance', ['txn_date' => $date, 'memo' => 'Opening balance of asset ' . $d['asset_no']], $lines);
                }
                $d['acquisition_txn_id'] = $txnId;
                $newId = DB::insert('fixed_assets', $d);
                Audit::log('create', 'fa', 'asset', $newId, $d['asset_no'] . ' ' . $d['description'], ['snapshot' => $d, 'amount' => $cost, 'page_key' => $pageKey, 'reason' => 'Funding: ' . ($funding ?: 'recorded elsewhere')]);
                return $newId;
            });
        }
        flash('success', 'Asset saved.');
        redirect_after_save(url($pageKey, ['a' => 'new']), url($pageKey, ['a' => 'view', 'id' => $id]));
    });

    layout_start($asset ? 'Edit asset ' . $asset['asset_no'] : 'New fixed asset', ['actions' => btn_link(url($pageKey), 'Cancel')]);
    $catJson = [];
    foreach ($cats as $c) {
        $catJson[$c['id']] = ['m' => $c['default_method'], 'l' => (float)$c['default_life_years']];
    }
    ?>
    <form method="post"><?= csrf_field() ?>
    <div class="card mb-3"><div class="card-body row g-2">
      <?= input_field('description', 'Description', fv('description', $asset), ['class' => 'col-md-6', 'required' => true]) ?>
      <?= select_field('category_id', 'Category', $catPairs, fv('category_id', $asset), ['blank' => '', 'class' => 'col-md-3', 'required' => true, 'extra' => 'id="catSel"']) ?>
      <?= input_field('serial_no', 'Serial / plate no.', fv('serial_no', $asset), ['class' => 'col-md-3']) ?>
      <?php
        $locOptions = $locations;
        if ($asset && $asset['location_id'] && !isset($locOptions[(int)$asset['location_id']])) {
            $locOptions[(int)$asset['location_id']] = (string)$asset['location'];   // an inactive location stays on the assets that have it
        }
      ?>
      <div class="col-md-3"><label class="form-label d-flex justify-content-between"><span>Location</span><?= Access::page('fa.locations') ? '<a class="fw-normal" href="' . h(url('fa.locations')) . '" target="_blank" tabindex="-1">Manage locations</a>' : '' ?></label>
        <select class="form-select form-select-sm" name="location_id"><?= options($locOptions, fv('location_id', $asset), $locOptions ? '' : 'No locations yet (Asset Locations)') ?></select></div>
      <?= select_field('site_id', 'Site', Lookup::sites(false), fv('site_id', $asset), ['blank' => '', 'class' => 'col-md-2']) ?>
      <?= Classes::field(fv('asset_class_id', null, $asset['class_id'] ?? ''), 'col-md-2', 'asset_class_id', 'Default class') ?>
      <?= select_field('vendor_id', 'Vendor', Lookup::vendors(), fv('vendor_id', $asset), ['blank' => '', 'class' => 'col-md-3', 'extra' => 'data-combo']) ?>
      <?php $acqLocked = $asset && $hasDep; ?>
      <?= input_field('acquisition_date', 'Acquired date', fv('acquisition_date', $asset, today()), ['type' => 'date', 'class' => 'col-md-2', 'required' => !$acqLocked, 'extra' => $acqLocked ? 'disabled' : '']) ?>
      <?= input_field('cost', 'Cost', fv('cost', $asset), ['class' => 'col-md-2', 'required' => !$acqLocked, 'extra' => ($acqLocked ? 'disabled ' : '') . 'inputmode="decimal"']) ?>
      <?php if ($acqLocked): ?>
        <div class="col-12 small text-muted">The acquired date and the cost are locked because depreciation has already been posted for this asset. To correct them, void the depreciation entries first (Run Depreciation), then edit the asset.</div>
      <?php elseif ($asset && $asset['acquisition_txn_id']): ?>
        <div class="col-12 small text-muted">Changing the acquired date or the cost also updates <?= Txn::link((int)$asset['acquisition_txn_id'], 'the transaction that recorded the purchase') ?>, so the ledger stays in step with the asset.</div>
      <?php endif; ?>
    </div></div>
    <div class="card mb-3"><div class="card-header">Depreciation</div><div class="card-body row g-2">
      <?= select_field('method', 'Method', $methods, fv('method', $asset, 'straight_line'), ['class' => 'col-md-3', 'extra' => 'id="methodSel"']) ?>
      <?= input_field('useful_life_years', 'Useful life (years)', fv('useful_life_years', $asset, '5'), ['class' => 'col-md-2']) ?>
      <?= input_field('salvage_value', 'Salvage (residual) value', fv('salvage_value', $asset, '0'), ['class' => 'col-md-2']) ?>
      <div class="col-md-2" data-toggle-by="#methodSel" data-show-when="declining_balance"><?= input_field('db_multiplier', 'Rate multiplier (2 = double)', fv('db_multiplier', $asset, '2'), ['class' => '']) ?></div>
      <div class="col-md-3" data-toggle-by="#methodSel" data-show-when="units_of_production"><div class="row g-1"><?= input_field('total_units', 'Estimated total output', fv('total_units', $asset), ['class' => 'col-7']) ?><?= input_field('unit_label', 'Unit', fv('unit_label', $asset, 'units'), ['class' => 'col-5']) ?></div></div>
      <?php if ($hasDep): ?>
        <?= input_field('change_date', 'Changes apply from (change in estimate)', fv('change_date', null, today()), ['type' => 'date', 'class' => 'col-md-3']) ?>
        <div class="col-md-9 small text-muted d-flex align-items-end">Depreciation has been posted. A new method, life or salvage value is applied to the remaining net book value from the change date forward; past depreciation is left unchanged.</div>
      <?php endif; ?>
    </div></div>
    <?php if (!$asset): ?>
    <div class="card mb-3"><div class="card-header">How was the asset acquired?</div><div class="card-body row g-2">
      <?= select_field('funding', 'Record the purchase', ['check' => 'Paid now (Write Checks)', 'bill' => 'On account (Enter Bills)', 'opening' => 'Existing asset (opening balance)', 'none' => 'Already recorded elsewhere'], fv('funding', null, 'check'), ['class' => 'col-md-4', 'extra' => 'id="fundSel"']) ?>
      <div class="col-md-8" data-toggle-by="#fundSel" data-show-when="check"><div class="row g-2"><?= select_field('bank_account_id', 'Bank account', Accts::ofType(['Bank', 'Credit Card']), fv('bank_account_id'), ['class' => 'col-md-6']) ?><?= input_field('payee', 'Payee (if no vendor)', fv('payee'), ['class' => 'col-md-6']) ?></div></div>
      <div class="col-md-8" data-toggle-by="#fundSel" data-show-when="bill"><?= input_field('vendor_ref', "Vendor's invoice no.", fv('vendor_ref'), ['class' => 'col-md-6']) ?></div>
      <div class="col-md-8" data-toggle-by="#fundSel" data-show-when="opening"><div class="row g-2"><?= input_field('opening_accum_dep', 'Accumulated depreciation to date', fv('opening_accum_dep', null, '0'), ['class' => 'col-md-6']) ?><?= input_field('opening_through', 'Depreciation recorded through', fv('opening_through'), ['type' => 'date', 'class' => 'col-md-6']) ?></div></div>
      <div class="col-md-4" data-toggle-by="#fundSel" data-show-when="check,bill"><?= check_field('with_vat', 'Cost is net of 12% input VAT (VAT added)', fv('with_vat', null, 0), '') ?></div>
      <div class="col-md-4" data-toggle-by="#fundSel" data-show-when="check,bill,opening"><div class="row g-2"><?= Classes::field(fv('class_id'), 'col-12') ?></div></div>
      <div class="col-12 small text-muted">Paying by check debits the fixed asset account and credits the bank; entering a bill credits Accounts Payable.</div>
    </div></div>
    <?php endif; ?>
    <div class="card mb-3"><div class="card-body"><label class="form-label">Notes</label><textarea class="form-control form-control-sm" name="notes" rows="2"><?= h(fv('notes', $asset)) ?></textarea></div></div>
    <?= save_buttons('Save asset', url($pageKey), Access::can($pageKey, 'create')) ?>
    </form>
    <script>
    document.getElementById('catSel')?.addEventListener('change', function () {
      const c = <?= json_encode($catJson) ?>[this.value]; if (!c) return;
      <?php if (!$asset): ?>document.getElementById('methodSel').value = c.m; document.querySelector('[name=useful_life_years]').value = c.l;
      document.getElementById('methodSel').dispatchEvent(new Event('change'));<?php endif; ?>
    });
    </script>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ list
$status = g('status', 'active');
$catId = gint('category_id');
$locId = (int)g('location_id');
$allLocations = DB::pairs('SELECT id, name FROM asset_locations ORDER BY name');
$sql = 'SELECT fa.*, ac.name AS category FROM fixed_assets fa JOIN asset_categories ac ON ac.id = fa.category_id WHERE 1=1';
$params = [];
if ($status !== 'all') {
    $sql .= $status === 'active' ? " AND fa.status <> 'disposed'" : ' AND fa.status = :s';
    if ($status !== 'active') {
        $params['s'] = $status;
    }
}
if ($catId) {
    $sql .= ' AND fa.category_id = :c';
    $params['c'] = $catId;
}
if ($locId === -1) {
    $sql .= ' AND fa.location_id IS NULL';
} elseif ($locId && isset($allLocations[$locId])) {
    $sql .= ' AND fa.location_id = :loc';
    $params['loc'] = $locId;
}
$assets = DB::all($sql . ' ORDER BY fa.asset_no', $params);
$showClass = Classes::ready() && Classes::inUse();
$tot = ['cost' => 0.0, 'acc' => 0.0];
layout_start('Fixed Asset Register', ['actions' => page_link($pageKey, ['a' => 'new'], 'New asset', 'btn-primary', 'plus-lg', 'create')
    . ' ' . page_link('fa.depreciation', [], 'Run depreciation', 'btn-outline-primary', 'calculator')]);
?>
<form method="get" class="row g-2 mb-2 filters"><input type="hidden" name="p" value="<?= h($pageKey) ?>">
  <?= select_field('status', 'Status', ['active' => 'In service', 'fully_depreciated' => 'Fully depreciated', 'disposed' => 'Disposed', 'all' => 'All'], $status, ['class' => 'col-md-2', 'extra' => 'data-autosubmit']) ?>
  <?= select_field('category_id', 'Category', $catPairs, $catId, ['blank' => 'All', 'class' => 'col-md-3', 'extra' => 'data-autosubmit']) ?>
  <?= select_field('location_id', 'Location', $allLocations + [-1 => '(No location)'], $locId ?: '', ['blank' => 'All locations', 'class' => 'col-md-3', 'extra' => 'data-autosubmit']) ?>
  <?php if ($locId > 0 && Access::page('fa.depreciation')): ?><div class="col-auto d-flex align-items-end"><?= btn_link(url('fa.depreciation', ['location_id' => $locId]), 'Run depreciation for this location', 'btn-outline-primary', 'calculator') ?></div><?php endif; ?>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
  <thead><tr><th>Asset no.</th><th>Description</th><th>Category</th><th>Location</th><?php if ($showClass): ?><th>Class</th><?php endif; ?><th>Acquired</th><th>Method</th><th class="num">Life</th><th class="num">Cost</th><th class="num">Accum. dep.</th><th class="num">Net book value</th><th>Status</th></tr></thead>
  <?php foreach ($assets as $as): $acc = Depreciation::accumulated($as); $tot['cost'] += (float)$as['cost']; $tot['acc'] += $acc; ?>
    <tr><td><a href="<?= h(url($pageKey, ['a' => 'view', 'id' => $as['id']])) ?>"><?= h($as['asset_no']) ?></a></td><td><?= h($as['description']) ?></td><td class="small"><?= h($as['category']) ?></td><td class="small"><?= h($as['location']) ?></td><?php if ($showClass): ?><td class="small"><?= h(Classes::name($as['class_id'] ?? 0)) ?></td><?php endif; ?>
      <td><?= h(fdate($as['acquisition_date'])) ?></td><td class="small"><?= h(method_label($as['method'])) ?></td><td class="num"><?= h((float)$as['useful_life_years']) ?></td>
      <td class="num"><?= money($as['cost']) ?></td><td class="num"><?= money($acc) ?></td><td class="num"><?= money((float)$as['cost'] - $acc) ?></td><td><?= status_badge($as['status']) ?></td></tr>
  <?php endforeach; ?>
  <tfoot><tr class="fw-bold"><td colspan="<?= $showClass ? 8 : 7 ?>">Total</td><td class="num"><?= money($tot['cost']) ?></td><td class="num"><?= money($tot['acc']) ?></td><td class="num"><?= money($tot['cost'] - $tot['acc']) ?></td><td></td></tr></tfoot>
</table></div></div>
<?php
layout_end();
