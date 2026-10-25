<?php
declare(strict_types=1);

$q = trim((string)g('q'));
$like = '%' . $q . '%';
$results = [];
if ($q !== '') {
    if (Access::page('cust.center')) {
        foreach (DB::all('SELECT id, name, company_name, phone FROM customers WHERE name LIKE :q OR company_name LIKE :q OR email LIKE :q OR phone LIKE :q LIMIT 20', ['q' => $like]) as $r) {
            $results['Customers'][] = [url('cust.center', ['id' => $r['id']]), $r['name'], trim($r['company_name'] . ' ' . $r['phone'])];
        }
    }
    if (Access::page('vend.center')) {
        foreach (DB::all('SELECT id, name, company_name, phone FROM vendors WHERE name LIKE :q OR company_name LIKE :q OR email LIKE :q LIMIT 20', ['q' => $like]) as $r) {
            $results['Vendors'][] = [url('vend.center', ['id' => $r['id']]), $r['name'], trim($r['company_name'] . ' ' . $r['phone'])];
        }
    }
    if (Access::page('inv.items')) {
        foreach (DB::all('SELECT id, code, name, item_type FROM items WHERE code LIKE :q OR name LIKE :q OR barcode = :b LIMIT 20', ['q' => $like, 'b' => $q]) as $r) {
            $results['Items'][] = [url('inv.items', ['a' => 'edit', 'id' => $r['id']]), $r['code'] . ' - ' . $r['name'], $r['item_type']];
        }
    }
    $txns = DB::all(
        'SELECT t.*, c.name AS cname, v.name AS vname FROM transactions t
           LEFT JOIN customers c ON c.id = t.customer_id LEFT JOIN vendors v ON v.id = t.vendor_id
          WHERE t.txn_no LIKE :q OR t.ref_no LIKE :q OR t.memo LIKE :q OR t.receipt_no LIKE :q OR t.payee_name LIKE :q
          ORDER BY t.txn_date DESC LIMIT 50',
        ['q' => $like]
    );
    foreach ($txns as $t) {
        if (!Access::page(Txn::page($t['txn_type']))) {
            continue;
        }
        $results['Transactions'][] = [Txn::url($t), Txn::ref($t), fdate($t['txn_date']) . ', ' . ($t['cname'] ?? $t['vname'] ?? $t['payee_name']) . ', ' . money($t['total'])];
    }
}

layout_start('Search');
?>
<form class="mb-3 d-flex gap-2" method="get">
  <input type="hidden" name="p" value="home.search">
  <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Name, document number, reference, barcode" autofocus>
  <button class="btn btn-primary">Search</button>
</form>
<?php if ($q !== '' && !$results): ?><p class="text-muted">Nothing found for “<?= h($q) ?>”.</p><?php endif; ?>
<?php foreach ($results as $group => $rows): ?>
  <div class="card mb-3"><div class="card-header fw-semibold"><?= h($group) ?></div>
    <ul class="list-group list-group-flush">
      <?php foreach ($rows as [$link, $title, $sub]): ?>
        <li class="list-group-item"><a href="<?= h($link) ?>"><?= h($title) ?></a> <span class="text-muted small ms-2"><?= h($sub) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endforeach; ?>
<?php
layout_end();
