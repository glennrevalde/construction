<?php
declare(strict_types=1);

require_once APP_ROOT . '/modules/txn/_common.php';
require_once APP_ROOT . '/modules/txn/_grids.php';
require_once APP_ROOT . '/modules/txn/_memorize.php';

$pageKey = 'cust.batch_invoices';
$a = g('a');

if ($a === 'print') {
    Access::require($pageKey, 'print');
    $batch = (string)g('batch');
    $ids = array_map('intval', DB::column("SELECT id FROM transactions WHERE batch_ref = :b AND txn_type = 'invoice' ORDER BY id", ['b' => $batch]));
    Audit::log('print', 'cust', 'batch', null, "Batch $batch (" . count($ids) . ' invoices)', ['page_key' => $pageKey]);
    layout_start('Batch ' . $batch, ['bare' => true, 'no_title' => true]);
    echo '<div class="no-print text-center mb-2"><button class="btn btn-primary btn-sm" onclick="window.print()">Print all</button> <a class="btn btn-outline-secondary btn-sm" href="' . h(url($pageKey)) . '">Back</a></div>';
    foreach ($ids as $id) {
        echo '<div style="page-break-after:always">';
        txn_print_body(Txn::load($id));
        echo '</div>';
    }
    layout_end();
    return;
}

Access::require($pageKey, 'create');
$groups = DB::pairs('SELECT id, name FROM billing_groups ORDER BY name');
$groupId = gint('group');
$customers = DB::all('SELECT c.*, bg.name AS group_name FROM customers c LEFT JOIN billing_groups bg ON bg.id = c.billing_group_id WHERE c.is_active = 1 ORDER BY c.name');

handle_post(function () use ($pageKey) {
    $date = pdate('txn_date');
    if (!$date) {
        fail('Enter the invoice date.');
    }
    $selected = array_map('intval', (array)($_POST['customers'] ?? []));
    if (!$selected) {
        fail('Select at least one customer.');
    }
    $rows = grid_rows('lines');
    $template = parse_item_lines($rows, 'sales');
    if (!$template) {
        fail('Enter the items to bill.');
    }
    if (p('memorize') === '1') {
        // Memorize: the customers, the lines and the settings are kept to be loaded into this page again
        $payload = Memorized::clean(['site_id' => pint('site_id'), 'memo' => p('memo'), 'customer_message' => p('customer_message'),
            'class_id' => Classes::ready() ? Classes::posted() : null], $template);
        $payload['customers'] = $selected;
        $payload['use_price_levels'] = p('use_price_levels') === '1' ? 1 : 0;
        $amount = array_sum(array_map(fn($l) => (float)$l['amount'] + (float)($l['tax_amount'] ?? 0), $template));
        $name = Memorized::saveFromForm('batch_invoice', $payload, count($selected) . ' customer' . (count($selected) === 1 ? '' : 's'), $amount);
        throw new FormNotice('Memorized as "' . $name . '": the ' . count($selected) . ' customer(s) and the lines. Load it into this page with "Load Memorized Transaction" for the next billing. No invoices were created.');
    }
    $need = price_checks($template, null);
    $approver = Approval::check($pageKey, $need !== '', $need);
    $batch = 'B' . date('YmdHis');
    $made = 0;
    DB::tx(function () use ($selected, $rows, $date, $batch, &$made, $approver) {
        foreach ($selected as $cid) {
            $c = DB::row('SELECT * FROM customers WHERE id = :id', ['id' => $cid]);
            if (!$c) {
                continue;
            }
            $custRows = $rows;
            foreach ($custRows as &$r) {
                if (!empty($r['item_id']) && p('use_price_levels') === '1') {
                    $it = item_cache((int)$r['item_id']);
                    if (!in_array($it['item_type'], ['discount', 'subtotal', 'payment', 'group'], true)) {
                        $r['rate'] = Lookup::priceFor((int)$r['item_id'], $cid);
                    }
                }
                if ($c['tax_code_id'] && !empty($r['tax_code_id'])) {
                    $r['tax_code_id'] = $c['tax_code_id'];
                }
            }
            unset($r);
            $lines = parse_item_lines($custRows, 'sales', ['customer_id' => $cid]);
            Txn::save('invoice', [
                'txn_date' => $date,
                'customer_id' => $cid,
                'account_id' => Accts::get('ar'),
                'terms_id' => $c['terms_id'],
                'due_date' => Txn::dueDate($date, $c['terms_id'] ? (int)$c['terms_id'] : null),
                'sales_rep_id' => $c['sales_rep_id'],
                'site_id' => pint('site_id'),
                'billing_address' => $c['billing_address'],
                'shipping_address' => $c['shipping_address'],
                'memo' => p('memo') ?: null,
                'customer_message' => p('customer_message') ?: null,
                'batch_ref' => $batch,
                'approved_by' => $approver['id'] ?? null,
            ], $lines, null, ['reason' => 'Batch ' . $batch, 'approved_by' => $approver['username'] ?? null]);
            $made++;
        }
    });
    Audit::log('batch_invoice', 'cust', 'batch', null, "Batch $batch", ['reason' => "$made invoices created", 'page_key' => $pageKey]);
    flash('success', "$made invoices created (batch $batch).");
    redirect(url($pageKey, ['done' => $batch]));
});

$done = g('done');
// filled from a memorized batch ("Load Memorized Transaction"): the customers ticked, the lines and the settings
$mem = !is_post() && gint('mem') ? Memorized::forForm((int)gint('mem'), ['batch_invoice']) : null;
$memCustomers = $mem ? array_map('strval', (array)($mem['customers'] ?? [])) : [];
$rec = $mem ? $mem['header'] + ['use_price_levels' => $mem['use_price_levels'] ?? 1] : null;
[$memButton, $memBox] = memorize_controls('batch_invoice', 'Monthly billing', 'Memorize Transaction', 'batch of invoices');
[$loadButton, $loadList] = memorized_loader(['batch_invoice'], $pageKey);
layout_start('Create Batch Invoices', ['actions' => $loadButton]);
echo memorized_loaded_note($mem);
if ($done):
    $made = DB::all("SELECT t.*, c.name FROM transactions t JOIN customers c ON c.id = t.customer_id WHERE t.batch_ref = :b ORDER BY c.name", ['b' => $done]);
?>
<div class="card mb-3 border-success"><div class="card-header">Batch <?= h($done) ?> — <?= count($made) ?> invoices
  <?php if (Access::can($pageKey, 'print')): ?><a class="btn btn-sm btn-success ms-2" href="<?= h(url($pageKey, ['a' => 'print', 'batch' => $done])) ?>" target="_blank"><i class="bi bi-printer"></i> Print all</a><?php endif; ?></div>
  <table class="table table-sm mb-0"><?php foreach ($made as $m): ?><tr><td><?= Txn::link($m) ?></td><td><?= h($m['name']) ?></td><td class="num"><?= money($m['total']) ?></td><td><?= $m['customer_id'] && Access::can('cust.invoices', 'print') ? '<a href="' . h(url('cust.invoices', ['a' => 'email', 'id' => $m['id']])) . '">Email</a>' : '' ?></td></tr><?php endforeach; ?></table>
</div>
<?php endif; ?>
<form method="post">
<?= csrf_field() ?><?= negative_stock_fields() ?>
<div class="row g-3">
  <div class="col-lg-4 col-xl-3">
    <div class="card"><div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
      <span class="fw-semibold">Customers</span>
      <select class="form-select form-select-sm" style="flex:1 1 150px;min-width:0" onchange="location='<?= h(url($pageKey)) ?>&group='+this.value"><?= options($groups, $groupId, 'Select a billing group…') ?></select></div>
      <div class="list-group list-group-flush" style="max-height:60vh;overflow:auto">
        <label class="list-group-item small"><input type="checkbox" class="form-check-input me-2" data-check-all=".cust-cb"> Select all</label>
        <?php foreach ($customers as $c):
            $isChecked = in_array((string)$c['id'], (array)($_POST['customers'] ?? []), true) || ($groupId && (int)$c['billing_group_id'] === $groupId)
                || in_array((string)$c['id'], $memCustomers, true);
        ?>
          <label class="list-group-item small"><input type="checkbox" class="form-check-input me-2 cust-cb" name="customers[]" value="<?= (int)$c['id'] ?>" <?= $isChecked ? 'checked' : '' ?>>
            <?= h($c['name']) ?> <span class="text-muted"><?= h($c['group_name']) ?></span></label>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-8 col-xl-9">
    <div class="card mb-3"><div class="card-body row g-2">
      <?= input_field('txn_date', 'Invoice date', fv('txn_date', null, today()), ['type' => 'date', 'class' => 'col-md-3']) ?>
      <?= select_field('site_id', 'Inventory site', Lookup::sites(), fv('site_id', $rec, array_key_first(Lookup::sites())), ['class' => 'col-md-3']) ?>
      <?= input_field('memo', 'Memo', fv('memo', $rec, 'Monthly billing ' . date('F Y')), ['class' => 'col-md-6']) ?>
      <?= input_field('customer_message', 'Customer message', fv('customer_message', $rec, 'Thank you for your business.'), ['class' => Classes::ready() ? 'col-md-5' : 'col-md-8']) ?>
      <?= Classes::field(fv('class_id', $rec), 'col-md-3') ?>
      <?= check_field('use_price_levels', "Use each customer's price level", fv('use_price_levels', $rec, 1)) ?>
      <p class="small text-muted mb-0">Each invoice uses the customer's own terms, due date, sales rep and tax code.</p>
    </div></div>
    <div class="card card-body mb-3"><?= item_grid($mem ? $mem['lines'] : array_values(array_filter(grid_rows('lines'), fn($r) => !empty($r['item_id']))), ['mode' => 'sales', 'show_disc' => true, 'show_site' => false, 'min_rows' => 2]) ?>
      <div class="mt-2"><?= totals_box() ?></div><p class="small text-muted mb-0">Totals shown per invoice before customer-specific prices.</p></div>
    <?= Approval::fields() ?>
    <div class="d-flex flex-wrap gap-2 align-items-center mt-3">
      <button class="btn btn-primary"><i class="bi bi-files"></i> Create invoices</button>
      <?php if ($memButton): ?><div class="ms-auto"><?= $memButton ?></div><?php endif; ?>
    </div>
  </div>
</div>
<?= $memBox ?>
</form>
<?= $loadList ?>
<?php
layout_end();
