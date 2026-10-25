<?php
declare(strict_types=1);

require_once APP_ROOT . '/modules/txn/_common.php';

$pageKey = 'vend.receipts';
$a = g('a');
$dir = Tenant::storage('receipts');   // the company's own folder (config upload_dir for the first company)
if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
}
$allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
    'pdf' => 'application/pdf', 'txt' => 'text/plain', 'eml' => 'message/rfc822', 'heic' => 'image/heic'];

/** Stores one file and creates the review-queue record */
function store_receipt(string $tmp, string $name, string $source, string $dir, array $allowed, bool $moveUploaded): int
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) {
        fail("File type .$ext is not accepted. Use JPG, PNG, PDF, TXT or EML.");
    }
    if (filesize($tmp) > 10 * 1024 * 1024) {
        fail("$name is larger than 10 MB.");
    }
    $stored = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = rtrim($dir, '/') . '/' . $stored;
    $ok = $moveUploaded ? move_uploaded_file($tmp, $dest) : rename($tmp, $dest);
    if (!$ok) {
        fail("Could not save $name. Check that the storage folder is writable.");
    }
    $details = ReceiptReader::read($dest, $name, $allowed[$ext]);
    $vendorExp = $details['vendor_id'] ? DB::value('SELECT default_expense_account_id FROM vendors WHERE id = :id', ['id' => $details['vendor_id']]) : null;
    $id = DB::insert('receipt_documents', [
        'file_path' => $stored, 'original_name' => mb_substr($name, 0, 255), 'mime_type' => $allowed[$ext], 'source' => $source,
        'vendor_id' => $details['vendor_id'], 'receipt_date' => $details['receipt_date'], 'amount' => $details['amount'],
        'account_id' => $vendorExp, 'uploaded_by' => Auth::id(),
    ]);
    Audit::log('receipt_uploaded', 'vend', 'receipt', $id, $name, ['reason' => "Source: $source", 'page_key' => 'vend.receipts']);
    return $id;
}

if ($a === 'file') {
    $doc = DB::row('SELECT * FROM receipt_documents WHERE id = :id', ['id' => (int)g('id')]);
    $path = $doc ? rtrim($dir, '/') . '/' . basename($doc['file_path']) : '';
    if (!$doc || !is_file($path)) {
        http_response_code(404);
        exit('File not found.');
    }
    header('Content-Type: ' . $doc['mime_type']);
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', (string)$doc['original_name']) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

if ($a === 'upload' && is_post()) {
    Access::require($pageKey, 'create');
    try {
        $n = 0;
        $files = $_FILES['files'] ?? null;
        if ($files && is_array($files['name'])) {
            foreach ($files['name'] as $i => $name) {
                if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                    fail("Upload of $name failed (error {$files['error'][$i]}).");
                }
                store_receipt($files['tmp_name'][$i], $name, p('source', 'upload') === 'mobile' ? 'mobile' : 'upload', $dir, $allowed, true);
                $n++;
            }
        }
        flash($n ? 'success' : 'warning', $n ? "$n receipt(s) added to the review queue." : 'Choose at least one file.');
    } catch (UserError $e) {
        flash('danger', $e->getMessage());
    }
    redirect(url($pageKey));
}

if ($a === 'import_inbox' && is_post()) {
    // e-mailed receipts: a mail rule saves attachments into storage/receipts/inbox
    Access::require($pageKey, 'create');
    $inbox = rtrim($dir, '/') . '/inbox';
    @mkdir($inbox, 0775, true);
    $n = 0;
    foreach (glob($inbox . '/*') ?: [] as $f) {
        if (!is_file($f)) {
            continue;
        }
        try {
            store_receipt($f, basename($f), 'email', $dir, $allowed, false);
            $n++;
        } catch (UserError $e) {
            flash('warning', $e->getMessage());
        }
    }
    flash('info', "$n e-mailed receipt(s) imported from the inbox folder.");
    redirect(url($pageKey));
}

if ($a === 'update' && is_post()) {
    Access::require($pageKey, 'edit');
    $doc = DB::row('SELECT * FROM receipt_documents WHERE id = :id', ['id' => (int)g('id')]);
    if ($doc) {
        $new = [
            'vendor_id' => pint('vendor_id'), 'receipt_date' => pdate('receipt_date'), 'amount' => p('amount') !== '' ? r2(pnum('amount')) : null,
            'account_id' => pint('account_id'), 'memo' => p('memo') ?: null,
        ];
        $action = p('do');
        if ($action === 'reject') {
            $new['status'] = 'rejected';
        }
        if ($action === 'match') {
            $tid = pint('match_txn_id');
            if ($tid && Txn::load($tid)) {
                $new['status'] = 'matched';
                $new['linked_txn_id'] = $tid;
            } else {
                flash('warning', 'Choose the transaction to match.');
            }
        }
        $new['reviewed_by'] = Auth::id();
        $new['reviewed_at'] = date('Y-m-d H:i:s');
        DB::update('receipt_documents', $new, 'id = :id', ['id' => $doc['id']]);
        Audit::log('receipt_' . ($action ?: 'reviewed'), 'vend', 'receipt', (int)$doc['id'], $doc['original_name'], ['before' => $doc, 'after' => $new, 'page_key' => $pageKey]);
        if ($action === 'bill') {
            redirect(url('vend.bills', ['a' => 'new', 'receipt' => $doc['id'], 'vendor_id' => $new['vendor_id']]));
        }
        if ($action === 'check') {
            redirect(url('bank.write_checks', ['a' => 'new', 'receipt' => $doc['id'], 'vendor_id' => $new['vendor_id']]));
        }
        if ($action === 'card') {
            redirect(url('bank.write_checks', ['a' => 'new', 'receipt' => $doc['id'], 'vendor_id' => $new['vendor_id'], 'cc' => 1]));
        }
        flash('success', 'Receipt updated.');
    }
    redirect(url($pageKey, ['status' => g('status')]));
}

// ------------------------------------------------------------------ Receipt List: every saved receipt, by date
if ($a === 'list') {
    $presets = ['' => 'Custom', 'today' => 'Today', 'this_week' => 'This week', 'this_month' => 'This month', 'last_month' => 'Last month',
        'this_quarter' => 'This quarter', 'last_quarter' => 'Last quarter', 'this_year' => 'This fiscal year', 'last_year' => 'Last fiscal year', 'all' => 'All dates'];
    $preset = isset($presets[(string)g('dates')]) ? (string)g('dates') : (g('from') || g('to') ? '' : 'this_month');
    [$from, $to] = $preset !== '' ? Report::preset($preset) : [gdate('from', date('Y-m-01')), gdate('to', date('Y-m-t'))];
    $basis = g('basis') === 'uploaded' ? 'uploaded' : 'receipt';
    $st = g('status');
    $vid = gint('vendor_id');
    $q = trim((string)g('q'));
    $dateCol = $basis === 'uploaded' ? 'DATE(r.uploaded_at)' : 'COALESCE(r.receipt_date, DATE(r.uploaded_at))';
    $sql = "SELECT r.*, v.name AS vendor, u.username, CONCAT(a.code, ' - ', a.name) AS account
              FROM receipt_documents r LEFT JOIN vendors v ON v.id = r.vendor_id LEFT JOIN users u ON u.id = r.uploaded_by LEFT JOIN accounts a ON a.id = r.account_id
             WHERE $dateCol BETWEEN :f AND :t";
    $params = ['f' => $from, 't' => $to];
    if (in_array($st, ['for_review', 'matched', 'created', 'rejected'], true)) {
        $sql .= ' AND r.status = :s';
        $params['s'] = $st;
    }
    if ($vid) {
        $sql .= ' AND r.vendor_id = :v';
        $params['v'] = $vid;
    }
    if ($q !== '') {
        $sql .= ' AND (r.original_name LIKE :q OR r.memo LIKE :q2 OR v.name LIKE :q3)';
        $params += ['q' => "%$q%", 'q2' => "%$q%", 'q3' => "%$q%"];
    }
    $list = DB::all($sql . ' ORDER BY ' . $dateCol . ' DESC, r.id DESC LIMIT 1000', $params);
    $statusNames = ['for_review' => 'For review', 'matched' => 'Matched', 'created' => 'Created', 'rejected' => 'Rejected'];
    if (g('export') === 'csv' && Access::can($pageKey, 'print')) {
        Audit::log('export', 'vend', 'list', null, 'Receipt List', ['page_key' => $pageKey]);
        csv_download('receipt_list.csv', ['Receipt date', 'Uploaded', 'File', 'Source', 'Vendor', 'Amount', 'Account', 'Memo', 'Status', 'Transaction', 'Uploaded by'], array_map(fn($r) => [
            $r['receipt_date'], $r['uploaded_at'], $r['original_name'], $r['source'], $r['vendor'], $r['amount'], $r['account'], $r['memo'],
            $statusNames[$r['status']] ?? $r['status'], ($lt = $r['linked_txn_id'] ? Txn::load((int)$r['linked_txn_id']) : null) ? Txn::ref($lt) : '', $r['username'],
        ], $list));
    }
    $total = array_sum(array_map(fn($r) => (float)$r['amount'], $list));
    layout_start('Receipt List', ['actions' => btn_link(url($pageKey), 'Receipt Management', 'btn-outline-secondary', 'arrow-left')
        . (Access::can($pageKey, 'print') ? ' ' . btn_link(current_url(['export' => 'csv']), 'Export', 'btn-outline-secondary', 'download') : '')]);
    ?>
    <form class="card card-body mb-3 filters" method="get">
      <input type="hidden" name="p" value="<?= h($pageKey) ?>"><input type="hidden" name="a" value="list">
      <div class="row g-2 align-items-end">
        <div class="col-6 col-md-auto"><label class="form-label">Dates</label><select class="form-select form-select-sm" name="dates" data-no-combo onchange="if(this.value){this.form.from.value='';this.form.to.value='';}this.form.submit()"><?= options($presets, $preset) ?></select></div>
        <div class="col-6 col-md-auto"><label class="form-label">Date of</label><select class="form-select form-select-sm" name="basis" data-no-combo><?= options(['receipt' => 'The receipt', 'uploaded' => 'The upload'], $basis) ?></select></div>
        <div class="col-6 col-md-auto"><label class="form-label">From</label><input class="form-control form-control-sm" type="date" name="from" value="<?= h($from) ?>" onchange="this.form.dates.value=''"></div>
        <div class="col-6 col-md-auto"><label class="form-label">To</label><input class="form-control form-control-sm" type="date" name="to" value="<?= h($to) ?>" onchange="this.form.dates.value=''"></div>
        <div class="col-6 col-md-auto"><label class="form-label">Status</label><select class="form-select form-select-sm" name="status" data-no-combo><?= options($statusNames, $st, 'All') ?></select></div>
        <div class="col-6 col-md-auto"><label class="form-label">Vendor</label><select class="form-select form-select-sm" name="vendor_id" style="max-width:240px"><?= options(Lookup::vendors(), $vid, 'All') ?></select></div>
        <div class="col-12 col-md-auto"><label class="form-label">Search</label><input class="form-control form-control-sm" name="q" value="<?= h($q) ?>" placeholder="File, vendor, memo"></div>
        <div class="col-12 col-md-auto"><button class="btn btn-sm btn-primary">Refresh</button></div>
      </div>
    </form>
    <div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0 receipt-list">
      <thead><tr><th>Date</th><th>Receipt</th><th>Vendor</th><th class="num">Amount</th><th>Account</th><th>Status</th><th>Transaction</th><th>Uploaded</th></tr></thead>
      <tbody>
      <?php if (!$list): ?><tr><td colspan="8" class="text-center text-muted py-3">No receipts in this period.</td></tr><?php endif; ?>
      <?php foreach ($list as $r): ?>
        <tr>
          <td class="text-nowrap"><?= h(fdate($r['receipt_date'] ?: substr((string)$r['uploaded_at'], 0, 10))) ?></td>
          <td><a href="<?= h(url($pageKey, ['a' => 'file', 'id' => $r['id']])) ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-text"></i> <?= h(mb_strimwidth((string)$r['original_name'], 0, 48, '…')) ?></a>
            <?php if ($r['memo']): ?><div class="small text-muted"><?= h(mb_strimwidth((string)$r['memo'], 0, 60, '…')) ?></div><?php endif; ?></td>
          <td><?= h($r['vendor']) ?></td>
          <td class="num"><?= $r['amount'] !== null ? money($r['amount']) : '' ?></td>
          <td class="small"><?= h($r['account']) ?></td>
          <td><?= h($statusNames[$r['status']] ?? $r['status']) ?><?= $r['status'] === 'for_review' ? ' <a class="small" href="' . h(url($pageKey, ['status' => 'for_review'])) . '">review</a>' : '' ?></td>
          <td><?= $r['linked_txn_id'] ? Txn::link((int)$r['linked_txn_id']) : '' ?></td>
          <td class="small text-muted text-nowrap"><?= h(fdatetime($r['uploaded_at'])) ?><br><?= h($r['source']) ?>, <?= h($r['username']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($list): ?><tfoot><tr class="fw-semibold"><td colspan="3"><?= count($list) ?> receipt(s)</td><td class="num"><?= money($total) ?></td><td colspan="4"></td></tr></tfoot><?php endif; ?>
    </table></div></div>
    <?php
    layout_end();
    return;
}

$status = g('status', 'for_review');
$docs = DB::all(
    'SELECT r.*, v.name AS vendor, u.username FROM receipt_documents r LEFT JOIN vendors v ON v.id = r.vendor_id LEFT JOIN users u ON u.id = r.uploaded_by'
    . ($status !== 'all' ? ' WHERE r.status = :s' : '') . ' ORDER BY r.uploaded_at DESC LIMIT 200',
    $status !== 'all' ? ['s' => $status] : []
);
$counts = DB::pairs('SELECT status, COUNT(*) FROM receipt_documents GROUP BY status');

layout_start('Receipt Management', ['actions' => btn_link(url($pageKey, ['a' => 'list']), 'Receipt List', 'btn-outline-secondary', 'list-ul')]);
?>
<?php if (Access::can($pageKey, 'create')): ?>
<div class="card mb-3"><div class="card-body">
  <form method="post" enctype="multipart/form-data" action="<?= h(url($pageKey, ['a' => 'upload'])) ?>" class="row g-2 align-items-end">
    <?= csrf_field() ?>
    <div class="col-md-5"><label class="form-label">Upload receipts (JPG, PNG, PDF, TXT, EML, up to 10 MB each)</label>
      <input class="form-control form-control-sm" type="file" name="files[]" multiple accept="image/*,.pdf,.txt,.eml" capture="environment"></div>
    <div class="col-md-2"><label class="form-label">Source</label><select class="form-select form-select-sm" name="source"><option value="upload">Computer</option><option value="mobile">Mobile photo</option></select></div>
    <div class="col-md-2"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-upload"></i> Upload</button></div>
  </form>
  <form method="post" action="<?= h(url($pageKey, ['a' => 'import_inbox'])) ?>" class="mt-2 small">
    <?= csrf_field() ?>
    E-mailed receipts: set a mail rule to save attachments to <code><?= h(ltrim(str_replace('\\', '/', substr(realpath($dir) ?: $dir, strlen(realpath(APP_ROOT) ?: APP_ROOT))), '/')) ?>/inbox</code>, then
    <button class="btn btn-link btn-sm p-0 align-baseline">import them</button>.
    On a phone, the upload button opens the camera.
  </form>
</div></div>
<?php endif; ?>

<ul class="nav nav-pills mb-2 small">
  <?php foreach (['for_review' => 'For review', 'matched' => 'Matched', 'created' => 'Created', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $lbl): ?>
    <li class="nav-item"><a class="nav-link py-1 <?= $status === $k ? 'active' : '' ?>" href="<?= h(url($pageKey, ['status' => $k])) ?>"><?= h($lbl) ?> <?= $k !== 'all' ? '(' . (int)($counts[$k] ?? 0) . ')' : '' ?></a></li>
  <?php endforeach; ?>
</ul>

<?php if (!$docs): ?><p class="text-muted">No receipts here.</p><?php endif; ?>
<?php foreach ($docs as $d):
    $candidates = $d['vendor_id'] ? DB::all(
        "SELECT id, txn_type, txn_no, ref_no, txn_date, total FROM transactions WHERE vendor_id = :v AND txn_type IN ('bill','check','item_receipt') AND is_voided = 0
          ORDER BY ABS(total - :a), ABS(DATEDIFF(txn_date, :d)) LIMIT 10",
        ['v' => $d['vendor_id'], 'a' => (float)$d['amount'], 'd' => $d['receipt_date'] ?: today()]
    ) : [];
    $isImage = str_starts_with((string)$d['mime_type'], 'image/');
?>
<div class="card mb-2"><div class="card-body">
  <div class="row g-3">
    <div class="col-md-2 text-center">
      <a href="<?= h(url($pageKey, ['a' => 'file', 'id' => $d['id']])) ?>" target="_blank">
        <?php if ($isImage): ?><img src="<?= h(url($pageKey, ['a' => 'file', 'id' => $d['id']])) ?>" class="img-fluid border rounded" style="max-height:120px" alt="receipt">
        <?php else: ?><i class="bi bi-file-earmark-text display-5"></i><?php endif; ?>
      </a>
      <div class="small text-truncate"><?= h($d['original_name']) ?></div>
      <div class="small text-muted"><?= h($d['source']) ?>, <?= h($d['username']) ?></div>
    </div>
    <div class="col-md-10">
      <form method="post" action="<?= h(url($pageKey, ['a' => 'update', 'id' => $d['id'], 'status' => $status])) ?>" class="row g-2">
        <?= csrf_field() ?>
        <?= select_field('vendor_id', 'Vendor', Lookup::vendors(), $d['vendor_id'], ['blank' => '', 'class' => 'col-md-3']) ?>
        <?= input_field('receipt_date', 'Date', $d['receipt_date'], ['type' => 'date', 'class' => 'col-md-2']) ?>
        <?= input_field('amount', 'Amount', $d['amount'], ['class' => 'col-md-2']) ?>
        <?= select_field('account_id', 'Account', Accts::all(), $d['account_id'], ['blank' => '', 'class' => 'col-md-3']) ?>
        <div class="col-md-2"><label class="form-label">Status</label><div><?= status_badge($d['status']) ?> <?= $d['linked_txn_id'] ? Txn::link((int)$d['linked_txn_id']) : '' ?></div></div>
        <?= input_field('memo', 'Memo', $d['memo'], ['class' => 'col-md-5']) ?>
        <?php if ($d['status'] === 'for_review' && Access::can($pageKey, 'edit')): ?>
        <div class="col-md-4"><label class="form-label">Match to existing transaction</label>
          <select class="form-select form-select-sm" name="match_txn_id"><option value=""></option>
            <?php foreach ($candidates as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h(Txn::label($c['txn_type']) . ' ' . ($c['ref_no'] ?: $c['txn_no']) . ', ' . fdate($c['txn_date']) . ', ' . money($c['total'])) ?></option><?php endforeach; ?>
          </select></div>
        <div class="col-12 d-flex flex-wrap gap-1">
          <button class="btn btn-sm btn-outline-primary" name="do" value="">Save details</button>
          <button class="btn btn-sm btn-outline-success" name="do" value="match">Match</button>
          <?php if (Access::can('vend.bills', 'create')): ?><button class="btn btn-sm btn-success" name="do" value="bill">Create bill</button><?php endif; ?>
          <?php if (Access::can('bank.write_checks', 'create')): ?><button class="btn btn-sm btn-success" name="do" value="check">Create check</button>
            <button class="btn btn-sm btn-success" name="do" value="card">Create credit card charge</button><?php endif; ?>
          <button class="btn btn-sm btn-outline-danger" name="do" value="reject" data-confirm="Reject this receipt?">Reject</button>
        </div>
        <?php endif; ?>
      </form>
    </div>
  </div>
</div></div>
<?php endforeach; ?>
<?php
layout_end();
