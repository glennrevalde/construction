<?php
declare(strict_types=1);

/**
 * Fixed Assets > Asset Maintenance
 * Repairs and maintenance of fixed assets: supplier, spare parts (serial, model no., size, part no. ...),
 * labor (man-hours or bulk charge), pictures, and the disbursement linked to accounting
 * (Bill, Check Voucher or Cash Voucher). See app/AssetMaint.php.
 */
require_once APP_ROOT . '/modules/txn/_common.php';

$pageKey = 'fa.maintenance';
$a = g('a', 'list');

// ------------------------------------------------------------------ pictures
if ($a === 'photo') {
    $ph = DB::row('SELECT * FROM asset_maintenance_photos WHERE id = :id', ['id' => gint('id')]);
    if (!$ph) {
        http_response_code(404);
        exit;
    }
    AssetMaint::sendPhoto($ph);
}

$assetsAll = DB::all("SELECT fa.id, fa.asset_no, fa.description, fa.status, fa.class_id, fa.current_meter, fa.meter_unit, fa.location FROM fixed_assets fa ORDER BY fa.asset_no");
$assetPairs = [];
foreach ($assetsAll as $as) {
    if ($as['status'] !== 'disposed') {
        $assetPairs[(int)$as['id']] = $as['description'] . ' (' . $as['asset_no'] . ')';
    }
}

// ------------------------------------------------------------------ void / delete
if (in_array($a, ['void', 'delete'], true) && is_post()) {
    Access::require($pageKey, 'delete');
    $m = AssetMaint::load((int)g('id'));
    if (!$m) {
        redirect(url($pageKey));
    }
    try {
        $reason = trim((string)p('reason'));
        if ($reason === '') {
            fail('Enter the reason.');
        }
        DB::tx(function () use ($m, $a, $reason, $pageKey) {
            $txn = $m['txn_id'] ? Txn::load((int)$m['txn_id']) : null;
            if ($a === 'void') {
                if ($m['status'] === 'void') {
                    fail('This record is already void.');
                }
                if ($txn && (int)$txn['is_voided'] === 0) {
                    Txn::void((int)$txn['id'], 'Maintenance record ' . $m['maint_no'] . ' voided: ' . $reason);
                }
                DB::update('asset_maintenance', ['status' => 'void', 'void_reason' => mb_substr($reason, 0, 255)], 'id = :id', ['id' => $m['id']]);
                Audit::log('void', 'fa', 'maintenance', (int)$m['id'], $m['maint_no'], ['reason' => $reason, 'amount' => (float)$m['total'], 'page_key' => $pageKey]);
            } else {
                if ($txn && (int)$txn['is_voided'] === 0) {
                    Txn::delete((int)$txn['id'], 'Maintenance record ' . $m['maint_no'] . ' deleted: ' . $reason);
                }
                foreach (AssetMaint::photos((int)$m['id']) as $ph) {
                    AssetMaint::deletePhoto($ph);
                }
                $snapshot = $m + ['parts' => AssetMaint::parts((int)$m['id']), 'labor' => AssetMaint::labor((int)$m['id'])];
                DB::run('DELETE FROM asset_maintenance WHERE id = :id', ['id' => $m['id']]);
                Audit::log('delete', 'fa', 'maintenance', (int)$m['id'], $m['maint_no'], ['reason' => $reason, 'before' => $snapshot, 'amount' => (float)$m['total'], 'page_key' => $pageKey]);
            }
        });
        flash('success', 'Maintenance record ' . $m['maint_no'] . ($a === 'void' ? ' voided' : ' deleted') . ($m['txn_id'] ? ', together with its accounting entry.' : '.'));
        redirect($a === 'void' ? url($pageKey, ['a' => 'view', 'id' => $m['id']]) : url($pageKey));
    } catch (UserError $e) {
        if (DB::inTransaction()) {
            DB::rollBack();
        }
        flash('danger', $e->getMessage());
        redirect(url($pageKey, ['a' => 'view', 'id' => $m['id']]));
    }
}

// ------------------------------------------------------------------ view / print
if ($a === 'view' || $a === 'print') {
    $m = AssetMaint::load((int)g('id'));
    if (!$m) {
        flash('warning', 'Maintenance record not found.');
        redirect(url($pageKey));
    }
    $parts = AssetMaint::parts((int)$m['id']);
    $labor = AssetMaint::labor((int)$m['id']);
    $photos = AssetMaint::photos((int)$m['id']);
    $txn = $m['txn_id'] ? Txn::load((int)$m['txn_id']) : null;
    $showCosts = true;
    $task = $m['schedule_id'] ? DB::value('SELECT task_name FROM asset_pm_schedules WHERE id = :id', ['id' => $m['schedule_id']]) : null;

    if ($a === 'print') {
        Access::require($pageKey, 'print');
        Audit::log('print', 'fa', 'maintenance', (int)$m['id'], $m['maint_no'], ['page_key' => $pageKey]);
        layout_start($m['maint_no'], ['bare' => true, 'no_title' => true]);
        ?>
        <div class="no-print mb-2 text-center"><button class="btn btn-primary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
          <a class="btn btn-outline-secondary btn-sm" href="<?= h(url($pageKey, ['a' => 'view', 'id' => $m['id']])) ?>">Back</a></div>
        <div class="doc doc-journal">
          <div class="d-flex justify-content-between">
            <?= print_company_block('doc.maintenance') ?>
            <div class="text-end"><h2 class="mb-0">ASSET MAINTENANCE RECORD</h2><div>No. <strong><?= h($m['maint_no']) ?></strong></div><div>Date: <?= h(fdate($m['maint_date'])) ?></div>
              <?php if ($m['status'] === 'void'): ?><div class="text-danger fw-bold">VOID</div><?php endif; ?></div>
          </div>
          <hr>
          <div class="row mb-3 small">
            <div class="col-7">
              <div>Asset: <strong><?= h($m['asset_no'] . ' - ' . $m['asset_description']) ?></strong></div>
              <?php if ($m['asset_serial']): ?><div>Serial / plate no.: <?= h($m['asset_serial']) ?></div><?php endif; ?>
              <?php if ($m['asset_location']): ?><div>Location: <?= h($m['asset_location']) ?></div><?php endif; ?>
              <div>Type: <?= h(AssetMaint::typeLabel($m['maint_type'])) ?><?= $task ? ' (' . h($task) . ')' : '' ?></div>
              <?php if ($m['meter_reading'] !== null): ?><div>Meter reading: <?= qty($m['meter_reading']) ?></div><?php endif; ?>
            </div>
            <div class="col-5 text-end">
              <?php if ($m['vendor_name']): ?><div>Supplier: <?= h($m['vendor_name']) ?></div><?php endif; ?>
              <?php if ($m['performed_by']): ?><div>Performed by: <?= h($m['performed_by']) ?></div><?php endif; ?>
              <?php if ($m['ref_no']): ?><div>Reference: <?= h($m['ref_no']) ?></div><?php endif; ?>
              <?php if ($txn): ?><div>Paid through: <?= h(Txn::ref($txn)) ?></div><?php endif; ?>
              <?php if (Classes::ready() && $m['class_id']): ?><div>Class: <?= h(Classes::name($m['class_id'])) ?></div><?php endif; ?>
            </div>
          </div>
          <?php if ($m['problem']): ?><p class="small mb-1"><strong>Reported:</strong> <?= h($m['problem']) ?></p><?php endif; ?>
          <?php if ($m['description']): ?><p class="small"><strong>Work done:</strong> <?= nl2br(h($m['description'])) ?></p><?php endif; ?>
          <?php if ($parts): ?>
          <table class="table table-sm journal-print mb-3"><thead><tr><th>Part no.</th><th>Description</th><th>Serial no.</th><th>Model no.</th><th>Size</th><th class="num">Qty</th><th class="num">Unit cost</th><th class="num">Amount</th></tr></thead>
            <tbody><?php foreach ($parts as $l): ?><tr><td><?= h($l['part_no']) ?></td><td><?= h($l['description']) ?><?= $l['brand'] ? ' <span class="text-muted">(' . h($l['brand']) . ')</span>' : '' ?></td><td><?= h($l['serial_no']) ?></td><td><?= h($l['model_no']) ?></td><td><?= h($l['size_spec']) ?></td>
              <td class="num"><?= qty($l['qty']) ?> <?= h($l['unit']) ?></td><td class="num"><?= money($l['unit_cost']) ?></td><td class="num"><?= money($l['amount']) ?></td></tr><?php endforeach; ?></tbody>
            <tfoot><tr><td colspan="7" class="text-end">Parts total</td><td class="num"><?= money($m['parts_total']) ?></td></tr></tfoot></table>
          <?php endif; ?>
          <?php if ($labor): ?>
          <table class="table table-sm journal-print mb-3"><thead><tr><th>Labor</th><th>Worker</th><th class="num">Man-hours</th><th class="num">Rate</th><th class="num">Amount</th></tr></thead>
            <tbody><?php foreach ($labor as $l): ?><tr><td><?= h($l['description']) ?></td><td><?= h($l['worker']) ?></td><td class="num"><?= $l['charge_type'] === 'bulk' ? 'bulk' : qty($l['hours']) ?></td><td class="num"><?= $l['charge_type'] === 'bulk' ? '' : money($l['rate']) ?></td><td class="num"><?= money($l['amount']) ?></td></tr><?php endforeach; ?></tbody>
            <tfoot><tr><td colspan="4" class="text-end">Labor total</td><td class="num"><?= money($m['labor_total']) ?></td></tr></tfoot></table>
          <?php endif; ?>
          <div class="d-flex justify-content-end"><table class="table table-sm w-auto mb-0">
            <?php if ((float)$m['tax_total'] != 0.0): ?><tr><td>Input VAT</td><td class="num"><?= money($m['tax_total']) ?></td></tr><?php endif; ?>
            <tr class="fw-bold"><td>Total cost</td><td class="num"><?= money($m['total']) ?></td></tr></table></div>
          <?= print_signatures(['created_by' => $m['created_by'], 'approved_by' => $txn['approved_by'] ?? null], false) ?>
        </div>
        <?php
        layout_end();
        return;
    }

    $actions = '';
    if ($m['status'] !== 'void') {
        $actions .= page_link($pageKey, ['a' => 'edit', 'id' => $m['id']], 'Edit', 'btn-primary', 'pencil', 'edit') . ' ';
    }
    $actions .= page_link($pageKey, ['a' => 'print', 'id' => $m['id']], 'Print', 'btn-outline-secondary', 'printer', 'print') . ' ';
    if (Access::can($pageKey, 'delete')) {
        if ($m['status'] !== 'void') {
            $actions .= '<button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#voidModal"><i class="bi bi-slash-circle"></i> Void</button> ';
        }
        $actions .= '<button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal"><i class="bi bi-trash"></i> Delete</button> ';
    }
    $actions .= btn_link(url($pageKey), 'Back to list');
    layout_start('Maintenance ' . $m['maint_no'], ['actions' => $actions]);
    $info = [
        'Asset' => safe_link('fa.register', ['a' => 'view', 'id' => $m['asset_id']], $m['asset_no'] . ' - ' . $m['asset_description']),
        'Date' => h(fdate($m['maint_date'])),
        'Type' => h(AssetMaint::typeLabel($m['maint_type'])),
        'Status' => status_badge($m['status']),
        'Supplier' => $m['vendor_id'] ? safe_link('vend.center', ['id' => $m['vendor_id']], (string)$m['vendor_name']) : '<span class="text-muted">none</span>',
        'Performed by' => h($m['performed_by'] ?: ''),
        'Reference' => h($m['ref_no'] ?: ''),
        'Location' => h($m['asset_location'] ?: ''),
    ];
    if ($m['meter_reading'] !== null) {
        $info['Meter reading'] = qty($m['meter_reading']);
    }
    if ($m['downtime_days'] !== null && (float)$m['downtime_days'] > 0) {
        $info['Downtime'] = qty($m['downtime_days']) . ' day(s)';
    }
    if ($m['next_service_date']) {
        $info['Next service'] = h(fdate($m['next_service_date']));
    }
    if ($task) {
        $info['Preventive task done'] = safe_link('fa.pm', ['asset_id' => $m['asset_id']], (string)$task);
    }
    if (Classes::ready() && $m['class_id']) {
        $info['Class'] = h(Classes::name($m['class_id']));
    }
    $info['Entered by'] = h((string)$m['created_by_name']) . ', ' . h(fdatetime($m['created_at']));
    ?>
    <?php if ($m['status'] === 'void'): ?><div class="alert alert-secondary">This record is void<?= $m['void_reason'] ? ': ' . h($m['void_reason']) : '' ?>.</div><?php endif; ?>
    <div class="card mb-3"><div class="card-body"><div class="row g-2">
      <?php foreach ($info as $k => $v): if ($v === '') { continue; } ?><div class="col-sm-6 col-md-3"><div class="small text-muted"><?= h($k) ?></div><div><?= $v ?></div></div><?php endforeach; ?>
      <?php if ($m['problem']): ?><div class="col-12"><div class="small text-muted">Problem reported</div><div><?= h($m['problem']) ?></div></div><?php endif; ?>
      <?php if ($m['description']): ?><div class="col-12"><div class="small text-muted">Maintenance description (work done)</div><div><?= nl2br(h($m['description'])) ?></div></div><?php endif; ?>
      <?php if ($m['notes']): ?><div class="col-12"><div class="small text-muted">Notes</div><div><?= nl2br(h($m['notes'])) ?></div></div><?php endif; ?>
    </div></div></div>

    <div class="row g-3">
      <div class="col-xl-9">
        <div class="card mb-3"><div class="card-header"><i class="bi bi-gear"></i> Spare parts and materials</div>
          <div class="table-responsive"><table class="table table-sm table-tight mb-0">
            <thead><tr><th>Part no.</th><th>Description</th><th>Brand</th><th>Serial no.</th><th>Model no.</th><th>Size</th><th class="num">Qty</th><th class="num">Unit cost</th><th class="num">Amount</th><th>Warranty until</th></tr></thead>
            <?php foreach ($parts as $l): ?><tr><td><?= h($l['part_no']) ?></td><td><?= h($l['description']) ?></td><td><?= h($l['brand']) ?></td><td><?= h($l['serial_no']) ?></td><td><?= h($l['model_no']) ?></td><td><?= h($l['size_spec']) ?></td>
              <td class="num"><?= qty($l['qty']) ?> <span class="small text-muted"><?= h($l['unit']) ?></span></td><td class="num"><?= money($l['unit_cost']) ?></td><td class="num"><?= money($l['amount']) ?></td>
              <td class="<?= $l['warranty_until'] && $l['warranty_until'] >= today() ? 'text-success' : 'text-muted' ?>"><?= h(fdate($l['warranty_until'])) ?></td></tr><?php endforeach; ?>
            <?php if (!$parts): ?><tr><td colspan="10" class="text-muted">No parts recorded.</td></tr><?php endif; ?>
          </table></div></div>
        <div class="card mb-3"><div class="card-header"><i class="bi bi-person-gear"></i> Labor</div>
          <div class="table-responsive"><table class="table table-sm mb-0">
            <thead><tr><th>Work done</th><th>Worker / mechanic</th><th>Charge</th><th class="num">Man-hours</th><th class="num">Rate per hour</th><th class="num">Amount</th></tr></thead>
            <?php foreach ($labor as $l): ?><tr><td><?= h($l['description']) ?></td><td><?= h($l['worker']) ?></td><td><?= $l['charge_type'] === 'bulk' ? 'Bulk charge' : 'By the hour' ?></td>
              <td class="num"><?= $l['charge_type'] === 'bulk' ? '' : qty($l['hours']) ?></td><td class="num"><?= $l['charge_type'] === 'bulk' ? '' : money($l['rate']) ?></td><td class="num"><?= money($l['amount']) ?></td></tr><?php endforeach; ?>
            <?php if (!$labor): ?><tr><td colspan="6" class="text-muted">No labor recorded.</td></tr><?php endif; ?>
          </table></div></div>
        <div class="card mb-3"><div class="card-header"><i class="bi bi-images"></i> Pictures of the materials and parts</div><div class="card-body">
          <?php if ($photos): ?><div class="photo-grid">
            <?php foreach ($photos as $ph): ?><a class="photo-tile" href="<?= h(url($pageKey, ['a' => 'photo', 'id' => $ph['id']])) ?>" target="_blank" title="<?= h($ph['original_name']) ?>">
              <img src="<?= h(url($pageKey, ['a' => 'photo', 'id' => $ph['id']])) ?>" alt="" loading="lazy"><span><?= h($ph['caption'] ?: $ph['original_name']) ?></span></a><?php endforeach; ?>
          </div><?php else: ?><span class="text-muted small">No pictures. Add them from Edit.</span><?php endif; ?>
        </div></div>
      </div>
      <div class="col-xl-3">
        <div class="card mb-3"><div class="card-header">Cost</div><div class="card-body">
          <table class="table table-sm mb-0 totals-box">
            <tr><td>Parts</td><td class="num"><?= money($m['parts_total']) ?></td></tr>
            <tr><td>Labor</td><td class="num"><?= money($m['labor_total']) ?></td></tr>
            <?php if ((float)$m['tax_total'] != 0.0): ?><tr><td>Input VAT</td><td class="num"><?= money($m['tax_total']) ?></td></tr><?php endif; ?>
            <tr class="fw-bold"><td>Total</td><td class="num"><?= money($m['total']) ?></td></tr>
          </table>
        </div></div>
        <div class="card mb-3"><div class="card-header">Accounting</div><div class="card-body small">
          <?php if ($txn): ?>
            <div class="mb-1">Disbursed through <?= Txn::link($txn, Txn::label($txn['txn_type']) . ' ' . $txn['txn_no']) ?> <?= (int)$txn['is_voided'] ? status_badge('void') : status_badge($txn['status']) ?></div>
            <?php foreach (DB::all('SELECT j.debit, j.credit, a.code, a.name FROM journal_lines j JOIN accounts a ON a.id = j.account_id WHERE j.txn_id = :t ORDER BY j.credit > 0, j.id', ['t' => $txn['id']]) as $g): ?>
              <div class="d-flex justify-content-between"><span><?= (float)$g['credit'] > 0 ? '&nbsp;&nbsp;&nbsp;' : '' ?><?= h($g['code'] . ' - ' . $g['name']) ?></span><span class="num"><?= money((float)$g['debit'] ?: (float)$g['credit']) ?></span></div>
            <?php endforeach; ?>
            <?php if ($txn['txn_type'] === 'bill' && (int)$txn['is_voided'] === 0 && Balances::open($txn) > 0.004 && Access::can('vend.pay_bills', 'create')): ?>
              <div class="mt-2"><?= btn_link(url('vend.pay_bills', ['vendor_id' => $txn['vendor_id']]), 'Pay this bill', 'btn-outline-success', 'cash') ?></div>
            <?php endif; ?>
          <?php else: ?>
            <span class="text-muted">No accounting entry was made from this record<?= $m['payment_mode'] !== 'none' && $m['status'] !== 'void' ? ' (its transaction was removed; edit and save the record to create it again)' : '' ?>.</span>
          <?php endif; ?>
        </div></div>
      </div>
    </div>
    <?php
    if (Access::can($pageKey, 'delete')) {
        echo reason_modal('voidModal', 'Void maintenance record', url($pageKey, ['a' => 'void', 'id' => $m['id']]), 'btn-warning',
            '<p class="small text-muted mt-2">Voiding keeps the record and its details, and voids the bill, check or cash voucher that was created for it.</p>');
        echo reason_modal('deleteModal', 'Delete maintenance record', url($pageKey, ['a' => 'delete', 'id' => $m['id']]), 'btn-danger',
            '<p class="small text-muted mt-2">Deleting removes the record, its pictures and its accounting entry. A paid bill or a reconciled check must be dealt with first.</p>');
    }
    layout_end();
    return;
}

// ------------------------------------------------------------------ new / edit
if (in_array($a, ['new', 'edit'], true)) {
    $m = null;
    if ($a === 'edit') {
        Access::require($pageKey, 'edit');
        $m = AssetMaint::load((int)g('id'));
        if (!$m) {
            flash('warning', 'Maintenance record not found.');
            redirect(url($pageKey));
        }
        if ($m['status'] === 'void') {
            flash('warning', 'A void record cannot be edited.');
            redirect(url($pageKey, ['a' => 'view', 'id' => $m['id']]));
        }
    } else {
        Access::require($pageKey, 'create');
    }
    $fromTask = !$m && gint('schedule_id') ? AssetMaint::schedule((int)gint('schedule_id')) : null;

    handle_post(function () use ($m, $pageKey) {
        // remove a picture (button on the edit form)
        if ($m && ($del = pint('delete_photo'))) {
            $ph = DB::row('SELECT * FROM asset_maintenance_photos WHERE id = :id AND maintenance_id = :m', ['id' => $del, 'm' => $m['id']]);
            if ($ph) {
                AssetMaint::deletePhoto($ph);
                Audit::log('photo_delete', 'fa', 'maintenance', (int)$m['id'], $m['maint_no'], ['reason' => (string)$ph['original_name'], 'page_key' => $pageKey]);
                flash('success', 'Picture removed.');
            }
            redirect(url($pageKey, ['a' => 'edit', 'id' => $m['id']]));
        }

        $assetId = pint('asset_id');
        $asset = $assetId ? DB::row('SELECT * FROM fixed_assets WHERE id = :id', ['id' => $assetId]) : null;
        if (!$asset) {
            fail('Choose the fixed asset that was repaired or maintained.');
        }
        $date = pdate('maint_date');
        if (!$date) {
            fail('Enter the date of the maintenance.');
        }
        $type = (string)p('maint_type');
        if (!isset(AssetMaint::TYPES[$type])) {
            fail('Choose the type of maintenance.');
        }
        $status = p('status') === 'in_progress' ? 'in_progress' : 'completed';
        $mode = (string)p('payment_mode', 'none');
        if (!isset(AssetMaint::PAYMENT_MODES[$mode])) {
            $mode = 'none';
        }

        // spare parts
        $parts = [];
        $partsTotal = 0.0;
        foreach ((array)($_POST['parts'] ?? []) as $r) {
            if (!is_array($r)) {
                continue;
            }
            $desc = mb_substr(trim((string)($r['description'] ?? '')), 0, 200);
            $partNo = mb_substr(trim((string)($r['part_no'] ?? '')), 0, 60);
            $qtyv = to_num($r['qty'] ?? 0);
            $cost = to_num($r['unit_cost'] ?? 0);
            if ($desc === '' && $partNo === '' && $cost == 0.0) {
                continue;
            }
            if ($desc === '') {
                fail('Enter the description of every spare part (part no. ' . ($partNo ?: '?') . ').');
            }
            if ($qtyv <= 0) {
                $qtyv = 1.0;
            }
            if ($cost < 0) {
                fail('A unit cost cannot be negative.');
            }
            $amount = r2($qtyv * $cost);
            $partsTotal += $amount;
            $parts[] = [
                'part_no' => $partNo ?: null, 'description' => $desc,
                'serial_no' => mb_substr(trim((string)($r['serial_no'] ?? '')), 0, 60) ?: null,
                'model_no' => mb_substr(trim((string)($r['model_no'] ?? '')), 0, 60) ?: null,
                'size_spec' => mb_substr(trim((string)($r['size_spec'] ?? '')), 0, 60) ?: null,
                'brand' => mb_substr(trim((string)($r['brand'] ?? '')), 0, 60) ?: null,
                'qty' => round($qtyv, 4), 'unit' => mb_substr(trim((string)($r['unit'] ?? '')), 0, 20) ?: null,
                'unit_cost' => round($cost, 4), 'amount' => $amount,
                'warranty_until' => valid_date($r['warranty_until'] ?? '') ? $r['warranty_until'] : null,
            ];
        }
        // labor: man-hours x rate, or one bulk charge
        $labor = [];
        $laborTotal = 0.0;
        foreach ((array)($_POST['labor'] ?? []) as $r) {
            if (!is_array($r)) {
                continue;
            }
            $desc = mb_substr(trim((string)($r['description'] ?? '')), 0, 200);
            $bulk = ($r['charge_type'] ?? 'hourly') === 'bulk';
            $hours = to_num($r['hours'] ?? 0);
            $rate = to_num($r['rate'] ?? 0);
            $amount = $bulk ? r2(to_num($r['amount'] ?? 0)) : r2($hours * $rate);
            if ($desc === '' && $amount == 0.0 && $hours == 0.0) {
                continue;
            }
            if ($desc === '') {
                fail('Describe the work on every labor line.');
            }
            if ($amount < 0 || $hours < 0) {
                fail('Labor hours and amounts cannot be negative.');
            }
            $laborTotal += $amount;
            $labor[] = ['description' => $desc, 'worker' => mb_substr(trim((string)($r['worker'] ?? '')), 0, 120) ?: null, 'charge_type' => $bulk ? 'bulk' : 'hourly',
                'hours' => $bulk ? 0 : round($hours, 2), 'rate' => $bulk ? 0 : round($rate, 4), 'amount' => $amount];
        }
        $description = trim((string)p('description'));
        if ($description === '' && !$parts && !$labor) {
            fail('Describe the maintenance that was done, or enter the parts and labor.');
        }
        if ($labor && $description === '') {
            fail('Enter the maintenance description: what the labor was for.');
        }
        $partsTotal = r2($partsTotal);
        $laborTotal = r2($laborTotal);
        $withVat = p('with_vat') === '1' && $mode !== 'none';
        $rate = $withVat ? tax_rate((int)DB::value("SELECT id FROM tax_codes WHERE code = 'VAT12'")) : 0.0;
        $tax = $withVat ? r2(r2($partsTotal * $rate / 100) + r2($laborTotal * $rate / 100)) : 0.0;
        $meter = trim((string)p('meter_reading')) === '' ? null : to_num(p('meter_reading'));
        $scheduleId = pint('schedule_id');
        $schedule = $scheduleId ? AssetMaint::schedule($scheduleId) : null;
        if ($schedule && (int)$schedule['asset_id'] !== (int)$asset['id']) {
            fail('The preventive maintenance task belongs to another asset.');
        }

        $d = [
            'asset_id' => (int)$asset['id'], 'maint_date' => $date, 'maint_type' => $type, 'status' => $status,
            'problem' => mb_substr(trim((string)p('problem')), 0, 255) ?: null, 'description' => $description ?: null,
            'vendor_id' => pint('vendor_id'), 'performed_by' => mb_substr(trim((string)p('performed_by')), 0, 120) ?: null,
            'ref_no' => mb_substr(trim((string)p('ref_no')), 0, 60) ?: null, 'meter_reading' => $meter,
            'downtime_days' => trim((string)p('downtime_days')) === '' ? null : max(0, to_num(p('downtime_days'))),
            'parts_total' => $partsTotal, 'labor_total' => $laborTotal, 'tax_total' => $tax, 'total' => r2($partsTotal + $laborTotal + $tax),
            'with_vat' => $withVat ? 1 : 0, 'payment_mode' => $mode,
            'pay_account_id' => in_array($mode, ['check', 'cash_voucher'], true) ? pint($mode === 'check' ? 'bank_account_id' : 'cash_account_id') : null,
            'parts_account_id' => pint('parts_account_id'), 'labor_account_id' => pint('labor_account_id'),
            'class_id' => Classes::ready() ? Classes::posted() : null,
            'schedule_id' => $schedule ? (int)$schedule['id'] : null,
            'next_service_date' => pdate('next_service_date'), 'notes' => trim((string)p('notes')) ?: null,
        ];

        $id = DB::tx(function () use ($d, $m, $parts, $labor, $schedule, $pageKey, $asset) {
            if ($m) {
                $id = (int)$m['id'];
                $d['maint_no'] = $m['maint_no'];
                DB::update('asset_maintenance', array_diff_key($d, ['maint_no' => 1]), 'id = :id', ['id' => $id]);
            } else {
                $d['maint_no'] = Seq::next('maintenance');
                $d['created_by'] = Auth::id();
                $id = DB::insert('asset_maintenance', $d);
            }
            DB::run('DELETE FROM asset_maintenance_parts WHERE maintenance_id = :m', ['m' => $id]);
            DB::run('DELETE FROM asset_maintenance_labor WHERE maintenance_id = :m', ['m' => $id]);
            foreach ($parts as $i => $l) {
                DB::insert('asset_maintenance_parts', $l + ['maintenance_id' => $id, 'line_no' => $i + 1]);
            }
            foreach ($labor as $i => $l) {
                DB::insert('asset_maintenance_labor', $l + ['maintenance_id' => $id, 'line_no' => $i + 1]);
            }
            // the disbursement: bill, check voucher or cash voucher (an "in progress" record posts when it is completed)
            $forTxn = $d + ['id' => $id];
            if ($d['status'] === 'in_progress') {
                $forTxn['payment_mode'] = $m && $m['txn_id'] ? $d['payment_mode'] : 'none';
            }
            $txnId = AssetMaint::syncTransaction($forTxn, $m);
            DB::update('asset_maintenance', ['txn_id' => $txnId], 'id = :id', ['id' => $id]);
            if ($d['meter_reading'] !== null) {
                AssetMaint::updateMeter((int)$asset['id'], (float)$d['meter_reading']);
            }
            // a preventive task done through this record is acknowledged as complete, once
            if ($schedule && $d['status'] === 'completed' && (!$m || (int)$m['schedule_id'] !== (int)$schedule['id'])
                && !(int)DB::value('SELECT COUNT(*) FROM asset_pm_log WHERE maintenance_id = :m AND schedule_id = :s', ['m' => $id, 's' => $schedule['id']])) {
                AssetMaint::complete($schedule, $d['maint_date'], $d['meter_reading'], 'Done through maintenance record ' . $d['maint_no'], $id);
            }
            Audit::log($m ? 'edit' : 'create', 'fa', 'maintenance', $id, $d['maint_no'] . ' ' . $asset['asset_no'], [
                'before' => $m ?: null, 'after' => $d, 'amount' => $d['total'], 'page_key' => $pageKey,
            ]);
            return $id;
        });
        $n = AssetMaint::storePhotos($id, $_FILES['photos'] ?? null, (array)($_POST['photo_caption'] ?? []));
        $saved = AssetMaint::load($id);
        $txn = $saved['txn_id'] ? Txn::load((int)$saved['txn_id']) : null;
        flash('success', 'Maintenance record ' . $saved['maint_no'] . ' saved' . ($txn ? ': ' . Txn::label($txn['txn_type']) . ' ' . $txn['txn_no'] . ' for ' . money($txn['total']) . ' was ' . ($m && $m['txn_id'] ? 'updated' : 'created') : '')
            . ($n ? ', ' . $n . ' picture(s) added' : '') . '.');
        redirect_after_save(url($pageKey, ['a' => 'new']), url($pageKey, ['a' => 'view', 'id' => $id]));
    });

    $parts = $m ? AssetMaint::parts((int)$m['id']) : [];
    $labor = $m ? AssetMaint::labor((int)$m['id']) : [];
    if (is_post()) {
        $parts = array_values(array_filter((array)($_POST['parts'] ?? []), 'is_array'));
        $labor = array_values(array_filter((array)($_POST['labor'] ?? []), 'is_array'));
    }
    $photos = $m ? AssetMaint::photos((int)$m['id']) : [];
    $expAccounts = Accts::ofType(['Expense', 'Other Expense', 'Cost of Goods Sold', 'Fixed Asset']);
    $defExp = AssetMaint::defaultExpenseAccount();
    $schedules = DB::all("SELECT s.id, s.asset_id, s.task_name, s.next_due_date FROM asset_pm_schedules s WHERE s.is_active = 1 OR s.id = :keep ORDER BY s.task_name", ['keep' => (int)($m['schedule_id'] ?? 0)]);
    $assetOpts = $assetPairs;
    if ($m && !isset($assetOpts[(int)$m['asset_id']])) {
        $assetOpts[(int)$m['asset_id']] = $m['asset_description'] . ' (' . $m['asset_no'] . ')';
    }
    $selAsset = (int)fv('asset_id', $m, $fromTask['asset_id'] ?? (gint('asset_id') ?: ''));
    $assetMeta = [];
    foreach ($assetsAll as $as) {
        $assetMeta[(int)$as['id']] = ['class' => $as['class_id'] ? (int)$as['class_id'] : '', 'meter' => $as['current_meter'], 'unit' => $as['meter_unit'] ?: '', 'loc' => (string)$as['location']];
    }
    $existingTxn = $m && $m['txn_id'] ? Txn::load((int)$m['txn_id']) : null;

    layout_start($m ? 'Edit maintenance ' . $m['maint_no'] : 'New maintenance record', ['actions' => btn_link($m ? url($pageKey, ['a' => 'view', 'id' => $m['id']]) : url($pageKey), 'Cancel')]);
    ?>
    <form method="post" enctype="multipart/form-data" id="maintForm"><?= csrf_field() ?>
    <div class="card mb-3"><div class="card-body row g-2">
      <div class="col-md-5"><label class="form-label">Fixed asset</label>
        <select class="form-select form-select-sm" name="asset_id" id="mAsset" required data-combo data-combo-placeholder="Type the asset name or number"><?= options($assetOpts, $selAsset ?: '', '') ?></select>
        <div class="form-text" id="mAssetInfo"></div></div>
      <?= input_field('maint_date', 'Date', fv('maint_date', $m, today()), ['type' => 'date', 'class' => 'col-md-2', 'required' => true]) ?>
      <?= select_field('maint_type', 'Type', AssetMaint::TYPES, fv('maint_type', $m, $fromTask ? 'preventive' : 'repair'), ['class' => 'col-md-3']) ?>
      <?= select_field('status', 'Status', ['completed' => 'Completed', 'in_progress' => 'In progress (post the cost later)'], fv('status', $m, 'completed'), ['class' => 'col-md-2']) ?>
      <?= select_field('vendor_id', 'Supplier of the parts / service', Lookup::vendors(), fv('vendor_id', $m), ['blank' => '', 'class' => 'col-md-4', 'extra' => 'data-combo data-combo-placeholder="Type the supplier name"']) ?>
      <?= input_field('performed_by', 'Performed by (mechanic, technician, shop)', fv('performed_by', $m), ['class' => 'col-md-4', 'extra' => 'maxlength="120"']) ?>
      <?= input_field('ref_no', "Supplier's invoice / job order no.", fv('ref_no', $m), ['class' => 'col-md-2', 'extra' => 'maxlength="60"']) ?>
      <div class="col-md-2"><label class="form-label">Meter reading <span id="mMeterUnit" class="text-muted fw-normal"></span></label>
        <input class="form-control form-control-sm text-end" name="meter_reading" value="<?= h(fv('meter_reading', $m)) ?>" inputmode="decimal" placeholder="odometer / hours"></div>
      <?= input_field('problem', 'Problem reported / reason', fv('problem', $m), ['class' => 'col-md-8', 'extra' => 'maxlength="255"']) ?>
      <?= input_field('downtime_days', 'Downtime (days)', fv('downtime_days', $m), ['class' => 'col-md-2', 'extra' => 'inputmode="decimal"']) ?>
      <?= input_field('next_service_date', 'Next service date', fv('next_service_date', $m), ['type' => 'date', 'class' => 'col-md-2']) ?>
      <div class="col-md-8"><label class="form-label">Maintenance description (work done, including what the labor was for)</label>
        <textarea class="form-control form-control-sm" name="description" rows="3"><?= h(fv('description', $m)) ?></textarea></div>
      <div class="col-md-4"><label class="form-label">Preventive maintenance task this completes</label>
        <select class="form-select form-select-sm" name="schedule_id" id="mSchedule">
          <option value="">None</option>
          <?php $selTask = (int)fv('schedule_id', $m, $fromTask['id'] ?? ''); foreach ($schedules as $s): ?>
            <option value="<?= (int)$s['id'] ?>" data-asset="<?= (int)$s['asset_id'] ?>" <?= $selTask === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['task_name'] . ($s['next_due_date'] ? ', due ' . fdate($s['next_due_date']) : '')) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Saving the record as Completed acknowledges the task and moves it to its next due date.</div></div>
    </div></div>

    <div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center"><span><i class="bi bi-gear"></i> Spare parts and materials</span><span class="small text-muted fw-normal">Part no., serial, model no. and size identify the part for warranty claims and re-ordering.</span></div>
      <div class="card-body pb-2"><div class="table-responsive"><table class="table table-sm line-free mb-1" id="partsGrid">
        <thead><tr><th style="min-width:96px">Part no.</th><th style="min-width:150px">Description</th><th style="min-width:80px">Brand</th><th style="min-width:92px">Serial no.</th><th style="min-width:88px">Model no.</th><th style="min-width:72px">Size</th>
          <th class="num" style="width:58px">Qty</th><th style="width:54px">Unit</th><th class="num" style="width:96px">Unit cost</th><th class="num" style="width:96px">Amount</th><th style="width:132px">Warranty until</th><th style="width:44px"></th></tr></thead>
        <tbody></tbody>
        <tfoot><tr class="fw-semibold grid-totals"><td colspan="9" class="text-end">Parts total</td><td class="num" id="partsTotal">0.00</td><td colspan="2"></td></tr></tfoot>
      </table></div>
      <div class="grid-add" data-add-to="parts"><?php foreach ([1 => 'Add part', 5 => 'Add 5 parts', 10 => 'Add 10 parts'] as $n => $lbl): ?><button type="button" class="btn btn-sm btn-outline-secondary" data-count="<?= $n ?>"><i class="bi bi-plus"></i> <?= h($lbl) ?></button><?php endforeach; ?></div></div></div>

    <div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center"><span><i class="bi bi-person-gear"></i> Labor</span><span class="small text-muted fw-normal">By the hour: man-hours × rate. Bulk: one agreed charge for the job.</span></div>
      <div class="card-body pb-2"><div class="table-responsive"><table class="table table-sm line-free mb-1" id="laborGrid">
        <thead><tr><th style="min-width:240px">Work done</th><th style="min-width:160px">Worker / mechanic</th><th style="width:150px">Charge</th><th class="num" style="width:110px">Man-hours</th><th class="num" style="width:120px">Rate per hour</th><th class="num" style="width:130px">Amount</th><th></th></tr></thead>
        <tbody></tbody>
        <tfoot><tr class="fw-semibold grid-totals"><td colspan="3" class="text-end">Labor total</td><td class="num" id="laborHours">0</td><td></td><td class="num" id="laborTotal">0.00</td><td></td></tr></tfoot>
      </table></div>
      <div class="grid-add" data-add-to="labor"><?php foreach ([1 => 'Add labor line', 5 => 'Add 5 lines', 10 => 'Add 10 lines'] as $n => $lbl): ?><button type="button" class="btn btn-sm btn-outline-secondary" data-count="<?= $n ?>"><i class="bi bi-plus"></i> <?= h($lbl) ?></button><?php endforeach; ?></div></div></div>

    <div class="row g-3">
      <div class="col-lg-7">
        <div class="card mb-3"><div class="card-header"><i class="bi bi-bank"></i> Disbursement (linked to accounting)</div><div class="card-body row g-2">
          <?= select_field('payment_mode', 'How is it paid?', AssetMaint::PAYMENT_MODES, fv('payment_mode', $m, 'bill'), ['class' => 'col-12', 'extra' => 'id="mMode"']) ?>
          <div class="col-md-6" data-toggle-by="#mMode" data-show-when="check"><label class="form-label">Bank account</label>
            <select class="form-select form-select-sm" name="bank_account_id"><?= options(Accts::ofType(['Bank', 'Credit Card']), fv('bank_account_id', $m && $m['payment_mode'] === 'check' ? ['bank_account_id' => $m['pay_account_id']] : null), '') ?></select></div>
          <div class="col-md-6" data-toggle-by="#mMode" data-show-when="cash_voucher"><label class="form-label">Cash account (petty cash, revolving fund)</label>
            <select class="form-select form-select-sm" name="cash_account_id"><?= options(Accts::ofType(['Bank']), fv('cash_account_id', $m && $m['payment_mode'] === 'cash_voucher' ? ['cash_account_id' => $m['pay_account_id']] : null), '') ?></select></div>
          <div class="col-12" data-toggle-by="#mMode" data-show-when="bill,check,cash_voucher"><div class="row g-2">
            <?= select_field('parts_account_id', 'Parts charged to', $expAccounts, fv('parts_account_id', $m, $defExp), ['blank' => '', 'class' => 'col-md-6']) ?>
            <?= select_field('labor_account_id', 'Labor charged to', $expAccounts, fv('labor_account_id', $m, $defExp), ['blank' => '', 'class' => 'col-md-6']) ?>
            <?= Classes::field(fv('class_id', $m), 'col-md-6') ?>
            <?= check_field('with_vat', 'Costs are net of 12% VAT (add input VAT)', fv('with_vat', $m, 0), 'col-md-6') ?>
          </div></div>
          <div class="col-12 small text-muted" id="mModeHelp"></div>
          <?php if ($existingTxn && (int)$existingTxn['is_voided'] === 0): ?>
            <div class="col-12 small">Saving updates <?= Txn::link($existingTxn, Txn::label($existingTxn['txn_type']) . ' ' . $existingTxn['txn_no']) ?>. Choosing another way of paying voids it and creates the new document.</div>
          <?php endif; ?>
          <div class="col-12"><?= Approval::fields() ?></div>
        </div></div>
        <div class="card mb-3"><div class="card-body"><label class="form-label">Notes</label><textarea class="form-control form-control-sm" name="notes" rows="2"><?= h(fv('notes', $m)) ?></textarea></div></div>
      </div>
      <div class="col-lg-5">
        <div class="card mb-3"><div class="card-header"><i class="bi bi-images"></i> Pictures of the materials and parts</div><div class="card-body">
          <input class="form-control form-control-sm" type="file" name="photos[]" id="mPhotos" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
          <div class="form-text">JPG, PNG, GIF or WEBP, up to 8 MB each. Several pictures can be chosen at once.</div>
          <div class="photo-grid mt-2" id="mPreview"></div>
          <?php if ($photos): ?><div class="photo-grid mt-2">
            <?php foreach ($photos as $ph): ?><div class="photo-tile"><a href="<?= h(url($pageKey, ['a' => 'photo', 'id' => $ph['id']])) ?>" target="_blank"><img src="<?= h(url($pageKey, ['a' => 'photo', 'id' => $ph['id']])) ?>" alt="" loading="lazy"></a>
              <span><?= h($ph['caption'] ?: $ph['original_name']) ?></span>
              <button class="btn btn-link btn-sm text-danger p-0" name="delete_photo" value="<?= (int)$ph['id'] ?>" formnovalidate data-no-class-check data-confirm="Remove this picture?">Remove</button></div><?php endforeach; ?>
          </div><?php endif; ?>
        </div></div>
        <div class="card mb-3"><div class="card-body">
          <table class="table table-sm mb-0 totals-box">
            <tr><td>Parts</td><td class="num" id="sumParts">0.00</td></tr>
            <tr><td>Labor</td><td class="num" id="sumLabor">0.00</td></tr>
            <tr id="sumVatRow"><td>Input VAT 12%</td><td class="num" id="sumVat">0.00</td></tr>
            <tr class="fw-bold fs-6"><td>Total cost</td><td class="num" id="sumTotal">0.00</td></tr>
          </table>
        </div></div>
      </div>
    </div>
    <div class="sticky-actions"><?= save_buttons('Save record', $m ? url($pageKey, ['a' => 'view', 'id' => $m['id']]) : url($pageKey), !$m && Access::can($pageKey, 'create')) ?></div>
    </form>

    <template id="tplPart"><tr>
      <td><input class="form-control form-control-sm" data-k="part_no" maxlength="60"></td>
      <td><input class="form-control form-control-sm" data-k="description" maxlength="200"></td>
      <td><input class="form-control form-control-sm" data-k="brand" maxlength="60"></td>
      <td><input class="form-control form-control-sm" data-k="serial_no" maxlength="60"></td>
      <td><input class="form-control form-control-sm" data-k="model_no" maxlength="60"></td>
      <td><input class="form-control form-control-sm" data-k="size_spec" maxlength="60"></td>
      <td><input class="form-control form-control-sm text-end" data-k="qty" inputmode="decimal"></td>
      <td><input class="form-control form-control-sm" data-k="unit" maxlength="20" placeholder="pc"></td>
      <td><input class="form-control form-control-sm text-end" data-k="unit_cost" inputmode="decimal"></td>
      <td class="num align-middle" data-k="amount">0.00</td>
      <td><input class="form-control form-control-sm" type="date" data-k="warranty_until"></td>
      <td class="grid-acts"><button type="button" class="btn btn-sm btn-link p-0 grid-ins" data-ins title="Insert a line above this one" aria-label="Insert a line above this one"><i class="bi bi-plus-square"></i></button><button type="button" class="btn btn-sm btn-link text-danger p-0" data-del title="Remove" aria-label="Remove the line"><i class="bi bi-x-lg"></i></button></td>
    </tr></template>
    <template id="tplLabor"><tr>
      <td><input class="form-control form-control-sm" data-k="description" maxlength="200"></td>
      <td><input class="form-control form-control-sm" data-k="worker" maxlength="120"></td>
      <td><select class="form-select form-select-sm" data-k="charge_type"><option value="hourly">By the hour</option><option value="bulk">Bulk charge</option></select></td>
      <td><input class="form-control form-control-sm text-end" data-k="hours" inputmode="decimal"></td>
      <td><input class="form-control form-control-sm text-end" data-k="rate" inputmode="decimal"></td>
      <td><input class="form-control form-control-sm text-end" data-k="amount" inputmode="decimal"></td>
      <td class="grid-acts"><button type="button" class="btn btn-sm btn-link p-0 grid-ins" data-ins title="Insert a line above this one" aria-label="Insert a line above this one"><i class="bi bi-plus-square"></i></button><button type="button" class="btn btn-sm btn-link text-danger p-0" data-del title="Remove" aria-label="Remove the line"><i class="bi bi-x-lg"></i></button></td>
    </tr></template>
    <script>
    (function () {
      const { num, fmt, r2 } = window.AppFmt;
      const parts = <?= json_encode(array_values($parts)) ?>, labor = <?= json_encode(array_values($labor)) ?>;
      const assets = <?= json_encode($assetMeta) ?>;
      const vatRate = <?= json_encode(tax_rate((int)DB::value("SELECT id FROM tax_codes WHERE code = 'VAT12'"))) ?>;
      const isNew = <?= $m ? 'false' : 'true' ?>;
      let n = 0;
      function addRow(gridId, tplId, group, data, before) {
        const tr = document.getElementById(tplId).content.firstElementChild.cloneNode(true);
        const i = n++;
        tr.querySelectorAll('[data-k]').forEach((el) => {
          if (el.tagName === 'TD') return;
          el.name = group + '[' + i + '][' + el.dataset.k + ']';
          let v = data ? data[el.dataset.k] : '';
          if (v === null || v === undefined) v = '';
          if (['qty', 'unit_cost', 'hours', 'rate', 'amount'].includes(el.dataset.k) && v !== '' && num(v) === 0) v = '';
          if (v !== '' && ['qty', 'unit_cost', 'hours', 'rate'].includes(el.dataset.k)) v = String(parseFloat(v));
          if (v !== '' && el.dataset.k === 'amount') v = num(v).toFixed(2);
          el.value = v;
        });
        document.querySelector('#' + gridId + ' tbody').insertBefore(tr, before || null);
        return tr;
      }
      function paint() {
        let p = 0, l = 0, hrs = 0;
        document.querySelectorAll('#partsGrid tbody tr').forEach((tr) => {
          const q = tr.querySelector('[data-k=qty]').value.trim() === '' ? 1 : num(tr.querySelector('[data-k=qty]').value);
          const amt = r2(q * num(tr.querySelector('[data-k=unit_cost]').value));
          tr.querySelector('td[data-k=amount]').textContent = fmt(amt);
          p += amt;
        });
        document.querySelectorAll('#laborGrid tbody tr').forEach((tr) => {
          const bulk = tr.querySelector('[data-k=charge_type]').value === 'bulk';
          const h = tr.querySelector('[data-k=hours]'), r = tr.querySelector('[data-k=rate]'), a = tr.querySelector('[data-k=amount]');
          h.disabled = bulk; r.disabled = bulk; a.readOnly = !bulk;
          if (bulk) { h.value = ''; r.value = ''; } else { const v = r2(num(h.value) * num(r.value)); a.value = v ? v.toFixed(2) : ''; hrs += num(h.value); }
          l += num(a.value);
        });
        p = r2(p); l = r2(l);
        const mode = document.getElementById('mMode').value;
        const vatOn = mode !== 'none' && document.getElementById('f_with_vat').checked;
        const vat = vatOn ? r2(r2(p * vatRate / 100) + r2(l * vatRate / 100)) : 0;
        document.getElementById('partsTotal').textContent = fmt(p);
        document.getElementById('laborTotal').textContent = fmt(l);
        document.getElementById('laborHours').textContent = hrs ? String(r2(hrs)) : '0';
        document.getElementById('sumParts').textContent = fmt(p);
        document.getElementById('sumLabor').textContent = fmt(l);
        document.getElementById('sumVat').textContent = fmt(vat);
        document.getElementById('sumVatRow').style.display = vatOn ? '' : 'none';
        document.getElementById('sumTotal').textContent = fmt(r2(p + l + vat));
        const help = {
          bill: 'Saving enters a Bill for the supplier: debit the expense accounts, credit Accounts Payable. Pay it later in Pay Bills.',
          check: 'Saving writes a Check Voucher: debit the expense accounts, credit the bank account.',
          cash_voucher: 'Saving makes a Cash Voucher: debit the expense accounts, credit the petty cash or revolving fund.',
          none: 'Only the maintenance details are kept; nothing is posted to the ledger.'
        };
        document.getElementById('mModeHelp').textContent = help[mode] || '';
      }
      (parts.length ? parts : [null, null]).forEach((d) => addRow('partsGrid', 'tplPart', 'parts', d));
      (labor.length ? labor : [null]).forEach((d) => addRow('laborGrid', 'tplLabor', 'labor', d));
      const grids = { parts: ['partsGrid', 'tplPart'], labor: ['laborGrid', 'tplLabor'] };
      document.querySelectorAll('.grid-add[data-add-to]').forEach((box) => box.addEventListener('click', (e) => {
        const b = e.target.closest('button[data-count]'); if (!b) return;
        const g = grids[box.dataset.addTo]; let first = null;
        for (let k = 0; k < (parseInt(b.dataset.count, 10) || 1); k++) { const tr = addRow(g[0], g[1], box.dataset.addTo, null); first = first || tr; }
        first.scrollIntoView({ block: 'nearest' }); first.querySelector('input').focus(); paint();
      }));
      const form = document.getElementById('maintForm');
      form.addEventListener('input', paint);
      form.addEventListener('change', paint);
      form.addEventListener('click', (e) => {
        const ins = e.target.closest('[data-ins]');
        if (ins) {
          const tr = ins.closest('tr'), grid = tr.closest('table').id === 'partsGrid' ? 'parts' : 'labor';
          addRow(grids[grid][0], grids[grid][1], grid, null, tr).querySelector('input').focus(); paint(); return;
        }
        const b = e.target.closest('[data-del]'); if (!b) return; b.closest('tr').remove(); paint();
      });

      // the asset decides the tasks offered, the meter unit and (on a new record) the class
      const assetSel = document.getElementById('mAsset'), taskSel = document.getElementById('mSchedule');
      function assetChanged(first) {
        const a = assets[assetSel.value] || null;
        Array.prototype.forEach.call(taskSel.options, (o) => { if (!o.value) return; const okay = o.dataset.asset === assetSel.value; o.hidden = !okay; o.disabled = !okay; if (!okay && o.selected) taskSel.value = ''; });
        document.getElementById('mMeterUnit').textContent = a && a.unit ? '(' + a.unit + ')' : '';
        document.getElementById('mAssetInfo').textContent = a ? [a.loc ? 'Location: ' + a.loc : '', a.meter !== null ? 'last meter reading ' + parseFloat(a.meter) + (a.unit ? ' ' + a.unit : '') : ''].filter(Boolean).join(', ') : '';
        const cls = form.querySelector('select[name=class_id]');
        if (cls && a && a.class !== '' && (isNew && (!first || cls.value === '')) && (cls.value === '' || !first)) cls.value = String(a.class);
      }
      assetSel.addEventListener('change', () => assetChanged(false));
      assetChanged(true);

      // preview of the pictures about to be uploaded
      document.getElementById('mPhotos').addEventListener('change', function () {
        const box = document.getElementById('mPreview'); box.innerHTML = '';
        Array.prototype.forEach.call(this.files, (f, i) => {
          const tile = document.createElement('div'); tile.className = 'photo-tile';
          const img = document.createElement('img'); img.src = URL.createObjectURL(f); img.onload = () => URL.revokeObjectURL(img.src);
          const cap = document.createElement('input'); cap.className = 'form-control form-control-sm'; cap.name = 'photo_caption[' + i + ']'; cap.placeholder = 'Caption (optional)'; cap.maxLength = 190;
          tile.appendChild(img); tile.appendChild(cap); box.appendChild(tile);
        });
      });
      paint();
    })();
    </script>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ list
$from = gdate('from', date('Y-m-d', strtotime('-12 months')));
$to = gdate('to', date('Y-m-d', strtotime('+30 days')));
$fAsset = gint('asset_id');
$fLoc = gint('location_id');
$fType = (string)g('maint_type');
$fVendor = gint('vendor_id');
$fStatus = (string)g('status');
$q = trim((string)g('q'));
$sql = "SELECT m.*, fa.asset_no, fa.description AS asset_description, fa.location AS asset_location, v.name AS vendor_name, t.txn_type, t.txn_no, t.is_voided AS txn_voided,
               (SELECT COUNT(*) FROM asset_maintenance_photos ph WHERE ph.maintenance_id = m.id) AS photos
          FROM asset_maintenance m JOIN fixed_assets fa ON fa.id = m.asset_id
          LEFT JOIN vendors v ON v.id = m.vendor_id LEFT JOIN transactions t ON t.id = m.txn_id
         WHERE m.maint_date BETWEEN :f AND :t";
$params = ['f' => $from, 't' => $to];
if ($fAsset) { $sql .= ' AND m.asset_id = :a'; $params['a'] = $fAsset; }
if ($fLoc) { $sql .= ' AND fa.location_id = :l'; $params['l'] = $fLoc; }
if (isset(AssetMaint::TYPES[$fType])) { $sql .= ' AND m.maint_type = :ty'; $params['ty'] = $fType; }
if ($fVendor) { $sql .= ' AND m.vendor_id = :v'; $params['v'] = $fVendor; }
if (isset(AssetMaint::STATUSES[$fStatus])) { $sql .= ' AND m.status = :s'; $params['s'] = $fStatus; } else { $sql .= " AND m.status <> 'void'"; }
if ($q !== '') {
    $sql .= ' AND (m.maint_no LIKE :q OR m.description LIKE :q OR m.problem LIKE :q OR m.ref_no LIKE :q OR fa.description LIKE :q OR fa.asset_no LIKE :q
               OR EXISTS (SELECT 1 FROM asset_maintenance_parts p WHERE p.maintenance_id = m.id AND (p.part_no LIKE :q OR p.description LIKE :q OR p.serial_no LIKE :q OR p.model_no LIKE :q)))';
    $params['q'] = "%$q%";
}
$rows = DB::all($sql . ' ORDER BY m.maint_date DESC, m.id DESC', $params);

if (g('export') === 'csv') {
    Access::require($pageKey, 'print');
    Audit::log('export', 'fa', 'maintenance', null, 'Asset maintenance list', ['page_key' => $pageKey]);
    csv_download('asset_maintenance.csv', ['Date', 'Record no.', 'Asset no.', 'Asset', 'Location', 'Type', 'Status', 'Supplier', 'Reference', 'Work done', 'Parts', 'Labor', 'VAT', 'Total', 'Paid through'],
        array_map(fn($r) => [$r['maint_date'], $r['maint_no'], $r['asset_no'], $r['asset_description'], $r['asset_location'], AssetMaint::typeLabel($r['maint_type']), $r['status'], $r['vendor_name'], $r['ref_no'],
            $r['description'], $r['parts_total'], $r['labor_total'], $r['tax_total'], $r['total'], $r['txn_no'] ? Txn::label($r['txn_type']) . ' ' . $r['txn_no'] : ''], $rows));
}

$due = AssetMaint::remindsUser() ? AssetMaint::reminders() : [];
layout_start('Asset Maintenance', ['actions' => page_link($pageKey, ['a' => 'new'], 'New maintenance record', 'btn-primary', 'plus-lg', 'create')
    . ' ' . page_link('fa.pm', [], 'Preventive maintenance' . ($due ? ' (' . count($due) . ' due)' : ''), $due ? 'btn-outline-danger' : 'btn-outline-primary', 'calendar-check')
    . (Access::can($pageKey, 'print') ? ' ' . btn_link(current_url(['export' => 'csv']), 'Export', 'btn-outline-secondary', 'download') : '')]);
$tp = 0.0; $tl = 0.0; $tt = 0.0;
?>
<form method="get" class="card card-body mb-3 filters"><input type="hidden" name="p" value="<?= h($pageKey) ?>"><div class="row g-2 align-items-end">
  <?= input_field('from', 'From', $from, ['type' => 'date', 'class' => 'col-md-2']) ?>
  <?= input_field('to', 'To', $to, ['type' => 'date', 'class' => 'col-md-2']) ?>
  <?= select_field('asset_id', 'Asset', $assetPairs, $fAsset, ['blank' => 'All assets', 'class' => 'col-md-3']) ?>
  <?= select_field('location_id', 'Location', DB::pairs('SELECT id, name FROM asset_locations ORDER BY name'), $fLoc, ['blank' => 'All locations', 'class' => 'col-md-2']) ?>
  <?= select_field('maint_type', 'Type', AssetMaint::TYPES, $fType, ['blank' => 'All types', 'class' => 'col-md-3']) ?>
  <?= select_field('vendor_id', 'Supplier', Lookup::vendors(), $fVendor, ['blank' => 'All suppliers', 'class' => 'col-md-3']) ?>
  <?= select_field('status', 'Status', AssetMaint::STATUSES, $fStatus, ['blank' => 'Completed and in progress', 'class' => 'col-md-3']) ?>
  <?= input_field('q', 'Search (record, asset, part no., serial, model)', $q, ['class' => 'col-md-4']) ?>
  <div class="col-md-2"><button class="btn btn-secondary btn-sm w-100"><i class="bi bi-search"></i> Show</button></div>
</div></form>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
  <thead><tr><th>Date</th><th>Record</th><th>Asset</th><th>Type</th><th>Work done</th><th>Supplier</th><th class="num">Parts</th><th class="num">Labor</th><th class="num">Total</th><th>Paid through</th><th>Status</th></tr></thead>
  <?php foreach ($rows as $r): if ($r['status'] !== 'void') { $tp += (float)$r['parts_total']; $tl += (float)$r['labor_total']; $tt += (float)$r['total']; } ?>
    <tr class="<?= $r['status'] === 'void' ? 'text-muted' : '' ?>"><td class="text-nowrap"><?= h(fdate($r['maint_date'])) ?></td>
      <td><a href="<?= h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) ?>"><?= h($r['maint_no']) ?></a><?= (int)$r['photos'] ? ' <i class="bi bi-image text-muted" title="' . (int)$r['photos'] . ' picture(s)"></i>' : '' ?></td>
      <td class="wrap-link"><?= h($r['asset_no']) ?><div class="small text-muted"><?= h($r['asset_description']) ?></div></td>
      <td class="small"><?= h(AssetMaint::typeLabel($r['maint_type'])) ?></td><td class="small"><?= h(mb_strimwidth((string)($r['description'] ?: $r['problem']), 0, 90, '…')) ?></td>
      <td class="small"><?= h($r['vendor_name']) ?></td><td class="num"><?= money($r['parts_total'], true) ?></td><td class="num"><?= money($r['labor_total'], true) ?></td><td class="num"><?= money($r['total'], true) ?></td>
      <td class="small"><?= $r['txn_no'] ? Txn::link(['id' => $r['txn_id'], 'txn_type' => $r['txn_type'], 'txn_no' => $r['txn_no']], Txn::label($r['txn_type']) . ' ' . $r['txn_no']) . ((int)$r['txn_voided'] ? ' (void)' : '') : '' ?></td>
      <td><?= status_badge($r['status']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="11" class="text-muted py-3">No maintenance records for these filters.</td></tr><?php endif; ?>
  <?php if ($rows): ?><tfoot><tr class="fw-bold"><td colspan="6"><?= count($rows) ?> record(s)</td><td class="num"><?= money($tp) ?></td><td class="num"><?= money($tl) ?></td><td class="num"><?= money($tt) ?></td><td colspan="2"></td></tr></tfoot><?php endif; ?>
</table></div></div>
<?php
layout_end();
