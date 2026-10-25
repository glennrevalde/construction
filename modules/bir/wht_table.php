<?php
declare(strict_types=1);

/**
 * BIR Reports > Withholding Tax Table: the guide to how much is withheld from suppliers (and by customers).
 *
 * Each line is a withholding tax: a code, the BIR ATC, a name, the kind (income tax withheld, or VAT / business
 * tax withheld), the rate, the base it is worked out on (net of VAT by default), whom it applies to (payments to
 * suppliers, from customers, or both) and the accounts it posts to. The table is flexible: rates and lines can be
 * added, changed or made inactive at any time; a line already used on a payment keeps its history and cannot be
 * deleted, only made inactive. The top card holds whether the company is a withholding agent (Pay Bills shows the
 * Withholding column only then) and what BIR Form 2307 prints for the payor.
 */
$pageKey = 'bir.wht_table';
$a = g('a', 'list');
$canEdit = Access::can($pageKey, 'edit');

// ------------------------------------------------------------------ the company as withholding agent
if ($a === 'agent' && is_post()) {
    Access::require($pageKey, 'edit');
    $before = [];
    $after = [];
    foreach (['withholding_agent', 'bir_zip_code', 'bir_signatory_name', 'bir_signatory_title', 'bir_signatory_tin'] as $k) {
        $v = $k === 'withholding_agent' ? (p($k) === '1' ? '1' : '0') : mb_substr(trim((string)p($k)), 0, 120);
        $before[$k] = Settings::get($k);
        $after[$k] = $v;
        Settings::set($k, $v);
    }
    Audit::log('settings_change', 'bir', 'settings', null, 'Withholding agent details', ['before' => $before, 'after' => $after, 'page_key' => $pageKey]);
    flash('success', 'Withholding agent details saved.');
    redirect(url($pageKey));
}

// ------------------------------------------------------------------ delete an unused line
if ($a === 'delete' && is_post()) {
    Access::require($pageKey, 'delete');
    $w = Withholding::get(gint('id') ?? 0);
    if ($w) {
        $used = (int)DB::value('SELECT COUNT(*) FROM payment_withholdings WHERE wht_id = :id', ['id' => $w['id']]);
        if ($used) {
            flash('warning', $w['name'] . ' has been used on ' . $used . ' payment line(s), so it stays on the table. Make it inactive instead.');
        } else {
            DB::run('UPDATE vendors SET wht_ids = NULL WHERE wht_ids = :i', ['i' => (string)$w['id']]);
            DB::run('UPDATE customers SET wht_ids = NULL WHERE wht_ids = :i', ['i' => (string)$w['id']]);
            DB::run('DELETE FROM withholding_taxes WHERE id = :id', ['id' => $w['id']]);
            Audit::log('delete', 'bir', 'withholding_tax', (int)$w['id'], $w['code'] . ' ' . $w['name'], ['snapshot' => $w, 'reason' => p('reason') ?: null, 'page_key' => $pageKey]);
            flash('success', $w['name'] . ' was taken off the table.');
        }
    }
    redirect(url($pageKey));
}

// ------------------------------------------------------------------ add / change a line
if (in_array($a, ['new', 'edit'], true)) {
    $w = null;
    if ($a === 'edit') {
        Access::require($pageKey, 'edit');
        $w = Withholding::get(gint('id') ?? 0);
        if (!$w) {
            redirect(url($pageKey));
        }
    } else {
        Access::require($pageKey, 'create');
    }
    handle_post(function () use ($w, $pageKey) {
        $data = [
            'code' => strtoupper(mb_substr(trim((string)p('code')), 0, 20)),
            'atc' => strtoupper(mb_substr(trim((string)p('atc')), 0, 10)) ?: null,
            'name' => mb_substr(trim((string)p('name')), 0, 100),
            'tax_kind' => p('tax_kind') === 'vat' ? 'vat' : 'income',
            'rate' => round(pnum('rate'), 4),
            'base_type' => p('base_type') === 'gross' ? 'gross' : 'net_of_vat',
            'applies_to' => in_array(p('applies_to'), ['purchases', 'sales', 'both'], true) ? p('applies_to') : 'both',
            'payable_account_id' => pint('payable_account_id'),
            'receivable_account_id' => pint('receivable_account_id'),
            'description' => mb_substr(trim((string)p('description')), 0, 255) ?: null,
            'sort_order' => (int)pnum('sort_order'),
            'is_active' => p('is_active', '1') === '1' ? 1 : 0,
        ];
        if ($data['code'] === '' || $data['name'] === '') {
            fail('Enter the code and the name of the withholding tax.');
        }
        if ($data['rate'] <= 0 || $data['rate'] > 100) {
            fail('The rate is a percentage above 0 and up to 100 (1 for 1%).');
        }
        if (DB::value('SELECT id FROM withholding_taxes WHERE code = :c AND id <> :id', ['c' => $data['code'], 'id' => $w['id'] ?? 0])) {
            fail('Another withholding tax already has the code ' . $data['code'] . '.');
        }
        if ($w) {
            DB::update('withholding_taxes', $data, 'id = :id', ['id' => $w['id']]);
            $id = (int)$w['id'];
            Audit::log('edit', 'bir', 'withholding_tax', $id, $data['code'] . ' ' . $data['name'], ['before' => $w, 'after' => $data, 'reason' => p('reason') ?: null, 'page_key' => $pageKey]);
        } else {
            $id = DB::insert('withholding_taxes', $data);
            Audit::log('create', 'bir', 'withholding_tax', $id, $data['code'] . ' ' . $data['name'], ['snapshot' => $data, 'page_key' => $pageKey]);
        }
        Withholding::reset();
        flash('success', $data['name'] . ' ' . Withholding::rateText($data['rate']) . ' saved.');
        redirect(wants_new() ? url($pageKey, ['a' => 'new']) : url($pageKey));
    });
    $liab = Accts::ofType(['Other Current Liability', 'Long Term Liability']);
    $asset = Accts::ofType(['Other Current Asset', 'Other Asset']);
    $used = $w ? (int)DB::value('SELECT COUNT(*) FROM payment_withholdings WHERE wht_id = :id', ['id' => $w['id']]) : 0;
    layout_start($w ? 'Edit withholding tax: ' . $w['name'] : 'New withholding tax', ['actions' => btn_link(url($pageKey), 'Cancel', 'btn-outline-secondary')]);
    ?>
    <form method="post" class="card"><div class="card-body"><?= csrf_field() ?>
      <div class="row g-2">
        <?= input_field('name', 'Name', fv('name', $w), ['class' => 'col-md-5', 'required' => true, 'extra' => 'maxlength="100" placeholder="e.g. WT on Goods"']) ?>
        <?= input_field('code', 'Code', fv('code', $w), ['class' => 'col-6 col-md-2', 'required' => true, 'extra' => 'maxlength="20" placeholder="e.g. WT-GOODS"']) ?>
        <?= input_field('atc', 'BIR ATC', fv('atc', $w), ['class' => 'col-6 col-md-2', 'extra' => 'maxlength="10" placeholder="e.g. WC158"']) ?>
        <?= input_field('rate', 'Rate (%)', fv('rate', $w ? ['rate' => rtrim(rtrim(number_format((float)$w['rate'], 4, '.', ''), '0'), '.')] : null), ['class' => 'col-6 col-md-3', 'required' => true, 'extra' => 'inputmode="decimal" placeholder="1 for 1%"']) ?>
        <?= select_field('tax_kind', 'Kind of tax', Withholding::KINDS, fv('tax_kind', $w, 'income'), ['class' => 'col-md-4', 'extra' => 'data-no-combo']) ?>
        <?= select_field('base_type', 'Worked out on', Withholding::BASES, fv('base_type', $w, 'net_of_vat'), ['class' => 'col-md-4', 'extra' => 'data-no-combo']) ?>
        <?= select_field('applies_to', 'Used on', Withholding::SIDES, fv('applies_to', $w, 'both'), ['class' => 'col-md-4', 'extra' => 'data-no-combo']) ?>
        <?= select_field('payable_account_id', 'Account for what is withheld from suppliers', $liab, fv('payable_account_id', $w), ['class' => 'col-md-6', 'blank' => 'Default (' . (fv('tax_kind', $w, 'income') === 'vat' ? 'VAT Withheld Payable' : 'Withholding Tax Payable') . ')']) ?>
        <?= select_field('receivable_account_id', 'Account for what customers withhold', $asset, fv('receivable_account_id', $w), ['class' => 'col-md-6', 'blank' => 'Default (' . (fv('tax_kind', $w, 'income') === 'vat' ? 'Creditable VAT Withheld' : 'Creditable Withholding Tax') . ')']) ?>
        <?= input_field('description', 'When it applies', fv('description', $w), ['class' => 'col-md-9', 'extra' => 'maxlength="255" placeholder="e.g. Goods bought by a top withholding agent from regular suppliers"']) ?>
        <?= input_field('sort_order', 'Order on the list', fv('sort_order', $w, '0'), ['class' => 'col-6 col-md-1', 'type' => 'number']) ?>
        <?= check_field('is_active', 'Active', fv('is_active', $w, 1), 'col-6 col-md-2') ?>
        <?php if ($w): ?><?= input_field('reason', 'Reason for the change', fv('reason'), ['class' => 'col-12', 'extra' => 'maxlength="255" placeholder="Recorded in the audit trail"']) ?><?php endif; ?>
      </div>
      <?php if ($used): ?><p class="small text-muted mt-2 mb-0">Used on <?= $used ?> payment line(s). Those keep the rate and amount they were saved with; a new rate applies to payments from now on.</p><?php endif; ?>
      <p class="small text-muted mt-2 mb-0">Per the company guide, 1% (goods) and 2% (services) are income tax withheld and 5% is VAT withheld, all worked out on the amount <strong>net of VAT</strong>
        (P112,000 with VAT is a base of P100,000: 1% is P1,000 and 5% is P5,000, never 5% of the P12,000 VAT). Special rates (for example 0.5% under RR 24-2025, professional fees) can be added as lines of their own.</p>
      <div class="mt-3"><?= save_buttons('Save', url($pageKey), !$w && Access::can($pageKey, 'create')) ?></div>
    </div></form>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ the table
$rows = Withholding::all();
$used = DB::pairs('SELECT wht_id, COUNT(*) FROM payment_withholdings GROUP BY wht_id');
$vendUse = [];
foreach (DB::column("SELECT wht_ids FROM vendors WHERE wht_ids IS NOT NULL AND wht_ids <> ''") as $ids) {
    foreach (Withholding::defaults($ids) as $i) {
        $vendUse[$i] = ($vendUse[$i] ?? 0) + 1;
    }
}
$s = Settings::all();
$acctName = fn($id) => $id ? (string)DB::value("SELECT CONCAT(code, ' - ', name) FROM accounts WHERE id = :a", ['a' => $id]) : '';
$def = fn(string $key) => $acctName(Accts::get($key));

layout_start('Withholding Tax Table', ['actions' => page_link($pageKey, ['a' => 'new'], 'Add a withholding tax', 'btn-primary', 'plus-lg', 'create')
    . ' ' . page_link('bir.form_2307', [], 'BIR Form 2307', 'btn-outline-secondary', 'file-earmark-text')
    . ' ' . page_link('rpt.bir_wht_summary', [], 'Summary of taxes withheld', 'btn-outline-secondary', 'bar-chart')]);
?>
<form method="post" action="<?= h(url($pageKey, ['a' => 'agent'])) ?>" class="card mb-3 wht-agent"><div class="card-body"><?= csrf_field() ?>
  <div class="d-flex flex-wrap justify-content-between gap-2 align-items-start">
    <div class="form-check form-switch wht-agent-switch">
      <input class="form-check-input" type="checkbox" role="switch" name="withholding_agent" value="1" id="whtAgent" <?= Withholding::isAgent() ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
      <label class="form-check-label" for="whtAgent"><strong>This company is a withholding agent</strong><span class="d-block small text-muted">It deducts withholding taxes when it pays its suppliers: Pay Bills shows the Withholding column, and BIR Form 2307 is printed for the suppliers.</span></label>
    </div>
    <div class="small text-muted text-md-end">Payor on BIR Form 2307: <strong><?= h($s['company_name']) ?></strong>, TIN <?= h(Withholding::tin($s['company_tin']) ?: 'not set') ?>
      <div><?= h($s['company_address']) ?></div><?php if (Access::page('admin.settings')): ?><a href="<?= h(url('admin.settings')) ?>">Change the company name, address and TIN</a><?php endif; ?></div>
  </div>
  <div class="row g-2 mt-1">
    <?= input_field('bir_zip_code', 'Payor zip code', $s['bir_zip_code'] ?? '', ['class' => 'col-6 col-md-2', 'extra' => 'maxlength="10"' . ($canEdit ? '' : ' disabled')]) ?>
    <?= input_field('bir_signatory_name', 'Authorized signatory', $s['bir_signatory_name'] ?? '', ['class' => 'col-6 col-md-3', 'extra' => 'maxlength="120"' . ($canEdit ? '' : ' disabled')]) ?>
    <?= input_field('bir_signatory_title', 'Title / designation', $s['bir_signatory_title'] ?? '', ['class' => 'col-6 col-md-3', 'extra' => 'maxlength="120"' . ($canEdit ? '' : ' disabled')]) ?>
    <?= input_field('bir_signatory_tin', 'Signatory TIN', $s['bir_signatory_tin'] ?? '', ['class' => 'col-6 col-md-2', 'extra' => 'maxlength="30"' . ($canEdit ? '' : ' disabled')]) ?>
    <?php if ($canEdit): ?><div class="col-md-2 d-flex align-items-end"><button class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-check2"></i> Save</button></div><?php endif; ?>
  </div>
</div></form>

<div class="card mb-3">
  <div class="table-responsive"><table class="table table-sm table-hover mb-0 wht-table">
    <thead><tr><th>Type</th><th>ATC</th><th class="num">Rate</th><th>Kind</th><th>Worked out on</th><th>Used on</th><th>Accounts</th><th class="num" title="Payment lines it was withheld on">Used</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="10" class="text-center text-muted py-3">No withholding taxes yet. Add the 1% goods, 2% services and 5% VAT rates.</td></tr><?php endif; ?>
    <?php foreach ($rows as $id => $w): ?>
      <tr class="<?= (int)$w['is_active'] ? '' : 'text-muted' ?>">
        <td data-label="Type"><strong><?= h($w['name']) ?></strong><div class="small text-muted"><?= h($w['code']) ?><?= $w['description'] ? ', ' . h($w['description']) : '' ?></div></td>
        <td data-label="ATC"><?= h($w['atc']) ?></td>
        <td data-label="Rate" class="num fw-semibold"><?= h(Withholding::rateText($w['rate'])) ?></td>
        <td data-label="Kind"><?= $w['tax_kind'] === 'vat' ? 'VAT withheld' : 'Income tax withheld' ?></td>
        <td data-label="Worked out on" class="small"><?= h(Withholding::BASES[$w['base_type']] ?? $w['base_type']) ?></td>
        <td data-label="Used on" class="small"><?= h(Withholding::SIDES[$w['applies_to']] ?? $w['applies_to']) ?></td>
        <td data-label="Accounts" class="small"><?php if ($w['applies_to'] !== 'sales'): ?><div>Suppliers: <?= h($acctName($w['payable_account_id']) ?: $def($w['tax_kind'] === 'vat' ? 'vat_withheld_payable' : 'wht_payable')) ?></div><?php endif; ?>
          <?php if ($w['applies_to'] !== 'purchases'): ?><div>Customers: <?= h($acctName($w['receivable_account_id']) ?: $def($w['tax_kind'] === 'vat' ? 'cvat_withheld' : 'cwt')) ?></div><?php endif; ?></td>
        <td data-label="Used" class="num"><?= (int)($used[$id] ?? 0) ?><?= !empty($vendUse[$id]) ? '<div class="small text-muted">' . (int)$vendUse[$id] . ' supplier(s)</div>' : '' ?></td>
        <td data-label="Status"><?= (int)$w['is_active'] ? 'Active' : 'Inactive' ?></td>
        <td class="text-end text-nowrap">
          <?php if ($canEdit): ?><a class="btn btn-sm btn-outline-primary" href="<?= h(url($pageKey, ['a' => 'edit', 'id' => $id])) ?>"><i class="bi bi-pencil"></i> Edit</a><?php endif; ?>
          <?php if (Access::can($pageKey, 'delete') && empty($used[$id])): ?>
            <form method="post" action="<?= h(url($pageKey, ['a' => 'delete', 'id' => $id])) ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" data-confirm="Take <?= h($w['name']) ?> off the table?" aria-label="Delete <?= h($w['name']) ?>"><i class="bi bi-trash"></i></button></form>
          <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<div class="card"><div class="card-body small wht-guide">
  <div class="fw-semibold mb-1">How the table is used</div>
  <ul class="mb-2">
    <li><strong>Pay Bills</strong>: the Withholding column of each bill opens this table; tick one or more taxes (for example 1% and 5%). The amount is worked out from the open balance <strong>net of VAT</strong> and can be typed over. A supplier's usual taxes (Vendor Center, Withholding taxes) are ticked when its bill is chosen.</li>
    <li><strong>Receive Payments</strong>: the taxes a customer withheld are ticked the same way; 1% / 2% become income-tax credits, 5% a VAT credit (2550Q Item 16).</li>
    <li>What is withheld from suppliers is owed to the BIR: income tax with 0619-E (months 1 and 2) and 1601-EQ (the quarter), VAT withheld with 1600-VT (monthly). <strong>BIR Form 2307</strong> is printed for each supplier and quarter.</li>
  </ul>
  <div class="text-muted">Who must withhold: government payors and BIR-notified top withholding agents withhold 1% on goods and 2% on services (RR 11-2018); government buyers also withhold 5% VAT on qualifying VATable purchases.
    Ordinary private purchasers generally do not. Check thresholds (single purchases of P10,000 or less), supplier status, exemptions and special rates (RR 24-2025, RR 9-2025 for registered business enterprises) before withholding.</div>
</div></div>
<?php
layout_end();
