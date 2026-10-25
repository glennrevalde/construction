<?php
declare(strict_types=1);

require_once APP_ROOT . '/modules/txn/_common.php';

$pageKey = 'cust.finance_charges';
if (txn_common_actions($pageKey, ['finance_charge'])) {
    return;
}
if (g('a') === 'list') {
    render_txn_list($pageKey, ['finance_charge'], 'Finance Charges', ['no_new' => true]);
    return;
}

$s = Settings::all();
$rate = (float)$s['finance_charge_rate'];
$min = (float)$s['finance_charge_min'];
$grace = (int)$s['finance_charge_grace_days'];
$onCharges = $s['finance_charge_on_charges'] === '1';
$asOf = gdate('asof', today());

/** Date of the customer's latest finance charge that is not voided, or null */
function fc_last_charged(int $customerId): ?string
{
    $d = DB::value("SELECT MAX(txn_date) FROM transactions WHERE txn_type = 'finance_charge' AND is_voided = 0 AND customer_id = :c", ['c' => $customerId]);
    return $d ? (string)$d : null;
}

/** Calculates the finance charge for every customer with overdue balances */
function assess(string $asOf, float $rate, float $min, int $grace, bool $onCharges): array
{
    $rows = [];
    foreach (Aging::receivables($asOf) as $d) {
        if ($d['open'] <= 0 || !$d['due_date']) {
            continue;
        }
        if (!$onCharges && $d['txn_type'] === 'finance_charge') {
            continue;
        }
        $overdueFrom = date('Y-m-d', strtotime($d['due_date'] . " +$grace days"));
        if ($overdueFrom >= $asOf) {
            continue;
        }
        $top = (int)$d['top_id'];
        // interest runs from the last assessment: a customer already charged on or after this date is not charged again
        $last = fc_last_charged($top);
        if ($last !== null && $last >= $asOf) {
            continue;
        }
        $start = max($d['due_date'], (string)$last);
        $days = max(0, (int)floor((strtotime($asOf) - strtotime($start)) / 86400));
        if ($days <= 0) {
            continue;
        }
        $charge = $d['open'] * $rate / 100 * $days / 365;
        if (!isset($rows[$top])) {
            $rows[$top] = ['customer_id' => $top, 'name' => $d['parent'] ?: $d['customer'], 'overdue' => 0.0, 'charge' => 0.0, 'docs' => [], 'last' => $last];
        }
        $rows[$top]['overdue'] += $d['open'];
        $rows[$top]['charge'] += $charge;
        $rows[$top]['docs'][] = $d['txn_no'] . " ($days days)";
    }
    foreach ($rows as &$r) {
        $r['charge'] = r2(max($r['charge'], $min));
    }
    return array_values($rows);
}

handle_post(function () use ($asOf, $pageKey) {
    Access::require($pageKey, 'create');
    $date = pdate('asof');
    if (!$date) {
        fail('Enter the assessment date.');
    }
    $made = 0;
    $skipped = [];
    foreach ((array)($_POST['charge'] ?? []) as $cid => $r) {
        if (empty($r['selected'])) {
            continue;
        }
        $amt = r2(to_num($r['amount'] ?? 0));
        if ($amt <= 0) {
            continue;
        }
        // checked again when saving: the page may have been sent twice, or someone else assessed in the meantime
        $last = fc_last_charged((int)$cid);
        if ($last !== null && $last >= $date) {
            $skipped[] = (string)DB::value('SELECT name FROM customers WHERE id = :id', ['id' => (int)$cid]) . ' (charged ' . fdate($last) . ')';
            continue;
        }
        Txn::save('finance_charge', [
            'txn_date' => $date,
            'due_date' => $date,
            'customer_id' => (int)$cid,
            'account_id' => Accts::get('ar'),
            'memo' => 'Finance charge on overdue balance as of ' . fdate($date),
        ], [[
            'line_kind' => 'item', 'account_id' => Accts::get('finance_charge_income'),
            'description' => 'Finance charge (' . ($r['detail'] ?? '') . ')', 'qty' => 0, 'rate' => 0, 'amount' => $amt, 'tax_amount' => 0,
        ]]);
        $made++;
    }
    if (!$made) {
        fail($skipped ? 'Nothing was posted: already charged on or after ' . fdate($date) . ': ' . implode(', ', $skipped) . '.' : 'Select at least one customer to charge.');
    }
    flash('success', "$made finance charge(s) posted.");
    if ($skipped) {
        flash('warning', 'Not charged again, already charged on or after ' . fdate($date) . ': ' . implode(', ', $skipped) . '.');
    }
    redirect(url($pageKey, ['a' => 'list']));
});

$rows = assess($asOf, $rate, $min, $grace, $onCharges);
layout_start('Assess Finance Charges', ['actions' => btn_link(url($pageKey, ['a' => 'list']), 'Posted finance charges', 'btn-outline-secondary', 'list-ul')
    . (Access::page('admin.settings') ? ' ' . btn_link(url('admin.settings'), 'Finance charge settings', 'btn-outline-secondary', 'gear') : '')]);
?>
<div class="alert alert-light border small">
  Annual interest rate <strong><?= h(pct($rate)) ?></strong>, minimum charge <strong><?= money($min) ?></strong>, grace period <strong><?= $grace ?> days</strong> ,
  <?= $onCharges ? 'interest is charged on earlier finance charges' : 'earlier finance charges are not charged interest' ?>.
  Interest runs from the due date (or the last assessment) to the assessment date. A customer already charged on or after the assessment date is not charged again.
</div>
<form method="get" class="row g-2 mb-3 filters">
  <input type="hidden" name="p" value="<?= h($pageKey) ?>">
  <div class="col-auto"><label class="form-label">Assessment date</label><input class="form-control form-control-sm" type="date" name="asof" value="<?= h($asOf) ?>"></div>
  <div class="col-auto d-flex align-items-end"><button class="btn btn-sm btn-secondary">Recalculate</button></div>
</form>
<form method="post">
  <?= csrf_field() ?><input type="hidden" name="asof" value="<?= h($asOf) ?>">
  <div class="card"><table class="table table-sm mb-0">
    <thead><tr><th><input type="checkbox" class="form-check-input" data-check-all=".fc-cb" checked></th><th>Customer</th><th>Overdue documents</th><th>Last charged</th><th class="num">Overdue balance</th><th class="num">Finance charge</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="6" class="text-muted text-center">No customers have overdue balances as of <?= h(fdate($asOf)) ?>.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><input type="checkbox" class="form-check-input fc-cb" name="charge[<?= (int)$r['customer_id'] ?>][selected]" value="1" checked></td>
        <td><?= h($r['name']) ?></td>
        <td class="small"><?= h(implode(', ', $r['docs'])) ?><input type="hidden" name="charge[<?= (int)$r['customer_id'] ?>][detail]" value="<?= h(implode(', ', $r['docs'])) ?>"></td>
        <td><?= h(fdate($r['last'])) ?></td>
        <td class="num"><?= money($r['overdue']) ?></td>
        <td><input class="form-control form-control-sm text-end ms-auto" style="width:120px" name="charge[<?= (int)$r['customer_id'] ?>][amount]" value="<?= h(number_format($r['charge'], 2, '.', '')) ?>"></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($rows && Access::can($pageKey, 'create')): ?>
    <p class="small text-muted mt-2">Untick customers to exclude them. Each charge debits Accounts Receivable, credits Finance Charge Income, and appears on the next statement.</p>
    <div class="row g-2 align-items-end"><?= Classes::field(fv('class_id'), 'col-md-3') ?>
      <div class="col-auto"><button class="btn btn-primary">Assess charges</button></div></div>
  <?php endif; ?>
</form>
<?php
layout_end();
