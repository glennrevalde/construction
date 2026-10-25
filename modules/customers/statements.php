<?php
declare(strict_types=1);

require_once APP_ROOT . '/modules/txn/_common.php';

$pageKey = 'cust.statements';
$from = gdate('from', date('Y-m-01', strtotime('first day of last month')));
$to = gdate('to', date('Y-m-t', strtotime('last day of last month')));
$format = in_array(g('format'), ['balance_forward', 'open_item', 'transaction'], true) ? g('format') : 'balance_forward';
$selected = array_map('intval', (array)($_GET['customers'] ?? (g('customer_id') ? [g('customer_id')] : [])));
$skipZero = g('skip_zero', '1') === '1';
$skipInactive = g('skip_noactivity', '0') === '1';
$withAging = g('aging', '1') === '1';
$generate = g('go') === '1' || g('customer_id') !== '';

/** Builds one statement; returns null when it should be skipped */
function build_statement(int $cid, string $from, string $to, string $format, bool $skipZero, bool $skipInactive): ?array
{
    $c = DB::row('SELECT * FROM customers WHERE id = :id', ['id' => $cid]);
    if (!$c) {
        return null;
    }
    $ids = array_merge([$cid], array_map('intval', DB::column('SELECT id FROM customers WHERE parent_id = :c', ['c' => $cid])));
    [$in, $p] = DB::in('sc', $ids);
    [$ain, $ap] = DB::in('sa', Accts::arIds() ?: [0]);
    $opening = (float)DB::value("SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE customer_id IN $in AND account_id IN $ain AND txn_date < :f", $p + $ap + ['f' => $from]);
    $activity = DB::all(
        "SELECT t.id, t.txn_type, t.txn_no, t.txn_date, t.due_date, t.memo, SUM(j.debit) AS charge, SUM(j.credit) AS credit
           FROM journal_lines j JOIN transactions t ON t.id = j.txn_id
          WHERE j.customer_id IN $in AND j.account_id IN $ain AND j.txn_date BETWEEN :f AND :t
          GROUP BY t.id, t.txn_type, t.txn_no, t.txn_date, t.due_date, t.memo
          ORDER BY t.txn_date, t.id",
        $p + $ap + ['f' => $from, 't' => $to]
    );
    $ending = Balances::customerBalance($cid, true, $to);
    if ($skipZero && abs($ending) < 0.005) {
        return null;
    }
    if ($skipInactive && !$activity) {
        return null;
    }
    $open = Aging::receivables($to, $cid);
    $aging = array_fill_keys(array_keys(Aging::BUCKETS), 0.0);
    foreach ($open as $o) {
        $aging[$o['bucket']] += $o['open'];
    }
    return compact('c', 'opening', 'activity', 'ending', 'open', 'aging');
}

if ($generate && g('print') === '1') {
    Access::require($pageKey, 'print');
}
$statements = [];
if ($generate) {
    if (!$selected) {
        $selected = array_map('intval', DB::column('SELECT id FROM customers WHERE parent_id IS NULL AND is_active = 1 ORDER BY name'));
    }
    foreach ($selected as $cid) {
        $st = build_statement($cid, $from, $to, $format, $skipZero, $skipInactive);
        if ($st) {
            $statements[] = $st;
        }
    }
    Audit::log(g('print') === '1' ? 'print' : 'view', 'cust', 'statement', null, count($statements) . ' statement(s) ' . fdate($from) . '-' . fdate($to), ['page_key' => $pageKey, 'reason' => $format]);
}

$s = Settings::all();
$renderStatement = function (array $st) use ($s, $from, $to, $format, $withAging) {
    $c = $st['c'];
    ?>
    <div class="doc mb-4" style="page-break-after:always">
      <div class="d-flex justify-content-between">
        <div class="doc-company"><?= Logo::img('doc.statement') ?><div><h2 class="mb-0"><?= h($s['company_name']) ?></h2><div class="small"><?= h($s['company_address']) ?><br>TIN <?= h($s['company_tin']) ?></div></div></div>
        <div class="text-end"><h2 class="mb-0">STATEMENT</h2><div>Date: <?= h(fdate($to)) ?></div><div class="small"><?= h(['balance_forward' => 'Balance forward', 'open_item' => 'Open items', 'transaction' => 'Transactions'][$format]) ?></div></div>
      </div>
      <hr>
      <div class="mb-3"><div class="small text-muted">To</div><strong><?= h($c['company_name'] ?: $c['name']) ?></strong><br><?= nl2br(h($c['billing_address'])) ?></div>
      <?php if ($format === 'open_item'): ?>
        <table class="table table-sm"><thead><tr><th>Date</th><th>Transaction</th><th>Due</th><th class="num">Amount</th><th class="num">Open balance</th></tr></thead>
        <?php foreach ($st['open'] as $o): ?>
          <tr><td><?= h(fdate($o['txn_date'])) ?></td><td><?= h(Txn::label($o['txn_type']) . ' ' . $o['txn_no']) ?><?= $o['parent_id'] ? ', ' . h($o['customer']) : '' ?></td><td><?= h(fdate($o['due_date'])) ?></td><td class="num"><?= money($o['total']) ?></td><td class="num"><?= money($o['open']) ?></td></tr>
        <?php endforeach; ?>
        </table>
      <?php else: ?>
        <table class="table table-sm"><thead><tr><th>Date</th><th>Transaction</th><th>Memo</th><th class="num">Charges</th><th class="num">Payments / credits</th><?= $format === 'balance_forward' ? '<th class="num">Balance</th>' : '' ?></tr></thead>
        <?php $run = $st['opening']; if ($format === 'balance_forward'): ?>
          <tr><td><?= h(fdate($from)) ?></td><td colspan="4">Balance forward</td><td class="num"><?= money($run) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($st['activity'] as $a): $run += $a['charge'] - $a['credit']; ?>
          <tr><td><?= h(fdate($a['txn_date'])) ?></td><td><?= h(Txn::label($a['txn_type']) . ' ' . $a['txn_no']) ?></td><td class="small"><?= h($a['memo']) ?></td>
            <td class="num"><?= money($a['charge'], true) ?></td><td class="num"><?= money($a['credit'], true) ?></td><?= $format === 'balance_forward' ? '<td class="num">' . money($run) . '</td>' : '' ?></tr>
        <?php endforeach; ?>
        </table>
      <?php endif; ?>
      <?php if ($withAging): ?>
      <table class="table table-sm table-bordered text-center small"><tr><?php foreach (Aging::BUCKETS as $lbl): ?><th><?= h($lbl) ?></th><?php endforeach; ?><th>Amount due</th></tr>
        <tr><?php foreach (array_keys(Aging::BUCKETS) as $k): ?><td><?= money($st['aging'][$k]) ?></td><?php endforeach; ?><td class="fw-bold"><?= money($st['ending']) ?></td></tr></table>
      <?php else: ?>
        <p class="text-end fw-bold">Amount due: <?= money($st['ending']) ?></p>
      <?php endif; ?>
      <p class="small text-muted">Please disregard this statement if payment has already been made. Thank you.</p>
    </div>
    <?php
};

if ($generate && g('print') === '1') {
    layout_start('Statements', ['bare' => true, 'no_title' => true]);
    echo '<div class="no-print text-center mb-2"><button class="btn btn-primary btn-sm" onclick="window.print()">Print</button> <a class="btn btn-outline-secondary btn-sm" href="' . h(current_url(['print' => null])) . '">Back</a></div>';
    foreach ($statements as $st) {
        $renderStatement($st);
    }
    layout_end();
    return;
}

layout_start('Create Statements');
?>
<form class="card mb-3 filters" method="get"><div class="card-body row g-2">
  <input type="hidden" name="p" value="<?= h($pageKey) ?>"><input type="hidden" name="go" value="1">
  <div class="col-md-2"><label class="form-label">From</label><input class="form-control form-control-sm" type="date" name="from" value="<?= h($from) ?>"></div>
  <div class="col-md-2"><label class="form-label">To (statement date)</label><input class="form-control form-control-sm" type="date" name="to" value="<?= h($to) ?>"></div>
  <?= select_field('format', 'Format', ['balance_forward' => 'Balance forward', 'open_item' => 'Open item', 'transaction' => 'Transaction statement'], $format, ['class' => 'col-md-3']) ?>
  <div class="col-md-5"><label class="form-label">Customers (none selected = all)</label>
    <select class="form-select form-select-sm" name="customers[]" multiple size="4"><?= options(Lookup::customers(false), null) ?></select></div>
  <div class="col-12 d-flex gap-3 small">
    <label><input type="hidden" name="skip_zero" value="0"><input type="checkbox" name="skip_zero" value="1" <?= checked($skipZero) ?>> Skip zero balances</label>
    <label><input type="hidden" name="skip_noactivity" value="0"><input type="checkbox" name="skip_noactivity" value="1" <?= checked($skipInactive) ?>> Skip customers with no activity</label>
    <label><input type="hidden" name="aging" value="0"><input type="checkbox" name="aging" value="1" <?= checked($withAging) ?>> Include aging summary</label>
  </div>
  <div class="col-12"><button class="btn btn-primary btn-sm">Preview statements</button></div>
</div></form>
<?php if ($generate): ?>
  <div class="d-flex gap-2 mb-3 no-print">
    <?php if (Access::can($pageKey, 'print') && $statements): ?>
      <a class="btn btn-success btn-sm" target="_blank" href="<?= h(current_url(['print' => '1'])) ?>"><i class="bi bi-printer"></i> Print all (<?= count($statements) ?>)</a>
      <?php foreach ($statements as $st): if (!$st['c']['email']) { continue; } ?>
        <a class="btn btn-outline-secondary btn-sm" href="mailto:<?= h($st['c']['email']) ?>?subject=<?= rawurlencode('Statement of account as of ' . fdate($to)) ?>&body=<?= rawurlencode('Your balance as of ' . fdate($to) . ' is ' . money($st['ending']) . '.') ?>"><i class="bi bi-envelope"></i> <?= h($st['c']['name']) ?></a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <?php if (!$statements): ?><p class="text-muted">No statements match these settings.</p><?php endif; ?>
  <?php foreach ($statements as $st) { $renderStatement($st); } ?>
<?php endif; ?>
<?php
layout_end();
