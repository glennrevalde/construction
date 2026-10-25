<?php
declare(strict_types=1);

/**
 * Utilities: Verify Data and Rebuild Data.
 * Verify runs read-only checks; Rebuild replays inventory costs in date order and
 * refreshes document balances. Both are recorded in the audit trail.
 */

$pageKey = 'admin.utilities';
$a = g('a', 'verify');
$canRebuild = Access::can($pageKey, 'edit');

$result = null;
handle_post(function () use (&$result, $pageKey, $canRebuild) {
    if (p('do') !== 'rebuild') {
        return;
    }
    if (!$canRebuild) {
        Access::denyPage($pageKey, 'rebuild data on');
    }
    $costs = p('costs') === '1';
    $balances = p('balances') === '1';
    if (!$costs && !$balances) {
        fail('Choose at least one thing to rebuild.');
    }
    $t0 = microtime(true);
    $result = Maintenance::rebuild($costs, $balances);
    $result['seconds'] = round(microtime(true) - $t0, 1);
    Audit::log('rebuild_data', 'admin', 'system', null, 'Rebuild data', [
        'page_key' => $pageKey,
        'reason' => trim((string)p('reason')) ?: null,
        'changes' => [
            ['field' => 'Inventory costs replayed in date order', 'old' => '', 'new' => $costs ? $result['transactions'] . ' transaction(s), ' . $result['items'] . ' item(s)' : 'not run'],
            ['field' => 'Cost of sales total', 'old' => money($result['cogs_before']), 'new' => money($result['cogs_after'])],
            ['field' => 'Document balances refreshed', 'old' => '', 'new' => $balances ? (string)$result['balances'] : 'not run'],
        ],
    ]);
    flash('success', 'Rebuild finished in ' . $result['seconds'] . ' second(s).');
});

$checks = Maintenance::verify();
$errors = count(array_filter($checks, fn($c) => $c['level'] === 'error'));
$warnings = count(array_filter($checks, fn($c) => $c['level'] === 'warning'));

layout_start('Utilities: Verify & Rebuild Data', ['actions' => btn_link(current_url(), 'Run the checks again', 'btn-outline-secondary', 'arrow-clockwise')]);
?>
<p class="text-muted">Verify Data checks that the books, the subledgers and inventory agree. Rebuild Data repairs what it can:
  it replays inventory transactions in date order so costs and cost of sales match the dates, and refreshes document balances.</p>

<div class="card mb-3">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span class="fw-semibold"><i class="bi bi-clipboard-check"></i> Verify data</span>
    <span><?= $errors ? badge($errors . ' problem(s)', 'danger') : badge('No problems', 'success') ?> <?= $warnings ? badge($warnings . ' warning(s)', 'warning') : '' ?></span>
  </div>
  <div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th style="width:1%"></th><th>Check</th><th>Result</th><th>What to do</th></tr></thead>
    <tbody>
    <?php foreach ($checks as $c): ?>
      <tr class="<?= $c['level'] === 'error' ? 'table-danger' : ($c['level'] === 'warning' ? 'table-warning' : '') ?>">
        <td><?= $c['level'] === 'ok' ? '<i class="bi bi-check-circle-fill text-success"></i>' : ($c['level'] === 'warning' ? '<i class="bi bi-exclamation-triangle-fill text-warning"></i>' : '<i class="bi bi-x-circle-fill text-danger"></i>') ?></td>
        <td><?= h($c['check']) ?></td>
        <td class="small"><?= h($c['detail']) ?></td>
        <td class="small text-muted"><?= h($c['fix']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php if ($result): ?>
<div class="card mb-3 border-primary">
  <div class="card-header fw-semibold">Rebuild result</div>
  <div class="card-body">
    <p class="mb-2"><?= (int)$result['transactions'] ?> inventory transaction(s) replayed in date order over <?= (int)$result['items'] ?> item(s);
      <?= (int)$result['balances'] ?> document balance(s) refreshed. Took <?= h((string)$result['seconds']) ?> second(s).</p>
    <?php if (Access::viewCosts()): ?>
      <p class="mb-2">Total cost of sales before <strong><?= money($result['cogs_before']) ?></strong>, after <strong><?= money($result['cogs_after']) ?></strong>
        (difference <?= money($result['cogs_after'] - $result['cogs_before']) ?>).</p>
      <?php if ($result['changed']): ?>
        <div class="table-responsive"><table class="table table-sm">
          <thead><tr><th>Date</th><th>Type</th><th>Number</th><th class="num">Cost before</th><th class="num">Cost after</th><th class="num">Difference</th></tr></thead>
          <?php foreach (array_slice($result['changed'], 0, 100) as $c): ?>
            <tr><td><?= h(fdate($c['date'])) ?></td><td><?= h($c['type']) ?></td>
              <td><?= Txn::link((int)$c['id'], $c['txn_no']) ?></td>
              <td class="num"><?= money($c['before']) ?></td><td class="num"><?= money($c['after']) ?></td>
              <td class="num"><?= money($c['after'] - $c['before']) ?></td></tr>
          <?php endforeach; ?>
        </table></div>
        <?php if (count($result['changed']) > 100): ?><p class="small text-muted">Showing the first 100 of <?= count($result['changed']) ?> changed transactions.</p><?php endif; ?>
      <?php else: ?>
        <p class="small text-muted mb-0">No cost of sales amount changed: every transaction was already costed in date order.</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header fw-semibold"><i class="bi bi-wrench-adjustable"></i> Rebuild data</div>
  <div class="card-body">
    <?php if (!$canRebuild): ?>
      <p class="text-muted mb-0">You can run the checks above. Rebuilding data needs the edit permission on this page.</p>
    <?php else: ?>
    <form method="post"><?= csrf_field() ?>
      <input type="hidden" name="do" value="rebuild">
      <div class="form-check"><input class="form-check-input" type="checkbox" name="costs" value="1" id="cbCosts" checked>
        <label class="form-check-label" for="cbCosts"><strong>Recalculate inventory costs</strong> — replays every inventory transaction in date order.
          Average costs, item values and the cost of sales on past sales are restated, so a purchase entered late but dated early is costed correctly.
          The sales amounts, quantities and the rest of the ledger are untouched.</label></div>
      <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="balances" value="1" id="cbBal" checked>
        <label class="form-check-label" for="cbBal"><strong>Refresh document balances and statuses</strong> — recalculates open balances on invoices and bills and the status of orders.</label></div>
      <div class="row g-2 align-items-end">
        <div class="col-md-6"><label class="form-label">Reason (kept in the audit trail)</label><input class="form-control form-control-sm" name="reason" placeholder="e.g. month-end check"></div>
        <div class="col-auto"><button class="btn btn-warning" data-confirm="Rebuild now? Cost of sales on past sales may change. Make a backup first."><i class="bi bi-wrench-adjustable"></i> Rebuild data</button></div>
      </div>
      <p class="small text-muted mt-3 mb-0">Back up the database before rebuilding (Utilities work on every transaction). Nobody else should be entering transactions while it runs.
        Transactions dated on or before the closing date are re-costed as well; their totals do not change.</p>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php
layout_end();
