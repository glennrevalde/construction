<?php
declare(strict_types=1);

$pageKey = 'admin.txn_history';
$id = gint('id');
$q = g('q');
if (!$id && $q !== '') {
    $id = (int)DB::value('SELECT record_id FROM audit_trail WHERE record_type = \'transaction\' AND record_ref LIKE :q ORDER BY id DESC LIMIT 1', ['q' => '%' . $q . '%']);
}
$entries = $id ? DB::all("SELECT * FROM audit_trail WHERE record_type = 'transaction' AND record_id = :id ORDER BY id", ['id' => $id]) : [];
$t = $id ? Txn::load($id) : null;
if ($id && $entries) {
    $txPage = Txn::page(json_decode((string)($entries[0]['snapshot'] ?? '{}'), true)['txn_type'] ?? ($t['txn_type'] ?? ''));
    // someone who cannot open the transaction page should not read its history either
    if ($txPage && !Access::page($txPage) && !Access::page('admin.audit_trail')) {
        Access::denyPage($pageKey, 'view the history of this transaction on');
    }
}

layout_start('Transaction History', ['actions' => $t ? Txn::link($t, 'Open current version') : '']);
?>
<form method="get" class="d-flex gap-2 mb-3 filters"><input type="hidden" name="p" value="<?= h($pageKey) ?>">
  <input class="form-control form-control-sm" style="max-width:320px" name="q" value="<?= h($q) ?>" placeholder="Document number, e.g. INV-00012">
  <button class="btn btn-sm btn-primary">Find</button></form>
<?php if ($id && !$entries): ?><p class="text-muted">No history found.</p><?php endif; ?>
<?php if ($entries): $first = $entries[0]; ?>
  <h2 class="h5"><?= h($first['record_ref']) ?> <?= $t ? status_badge((int)$t['is_voided'] ? 'void' : $t['status']) : '<span class="badge text-bg-dark">deleted</span>' ?></h2>
  <p class="small text-muted"><?= count($entries) ?> recorded version(s) and action(s), oldest first.</p>
  <?php $v = 0; foreach ($entries as $e):
      $changes = json_decode((string)$e['changes'], true) ?: [];
      $snap = json_decode((string)$e['snapshot'], true);
      if (in_array($e['action'], ['create', 'edit', 'convert', 'mark_final', 'sale', 'refund', 'recall', 'start', 'suspend'], true)) {
          $v++;
      }
  ?>
  <div class="card mb-2 <?= in_array($e['action'], ['void', 'delete'], true) ? 'border-danger' : '' ?>">
    <div class="card-header d-flex justify-content-between small">
      <span><strong><?= h(ucwords(str_replace('_', ' ', $e['action']))) ?></strong><?= in_array($e['action'], ['create', 'edit', 'convert', 'mark_final', 'sale', 'refund'], true) ? ', version ' . $v : '' ?>
        by <strong><?= h($e['username']) ?></strong> (<?= h($e['user_roles']) ?>)</span>
      <span><?= h(fdatetime($e['created_at'])) ?>, <?= h($e['ip_address']) ?></span>
    </div>
    <div class="card-body small">
      <?php if ($e['reason']): ?><div>Reason: <strong><?= h($e['reason']) ?></strong></div><?php endif; ?>
      <?php if ($e['approved_by']): ?><div>Approved by: <strong><?= h($e['approved_by']) ?></strong></div><?php endif; ?>
      <?php if ($changes): ?>
        <table class="table table-sm mb-2"><thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead>
        <?php foreach ($changes as $c): ?><tr><td><?= h($c['field']) ?></td><td class="diff-old"><?= h(is_scalar($c['old']) || $c['old'] === null ? $c['old'] : json_encode($c['old'])) ?></td><td class="diff-new"><?= h(is_scalar($c['new']) || $c['new'] === null ? $c['new'] : json_encode($c['new'])) ?></td></tr><?php endforeach; ?>
        </table>
      <?php endif; ?>
      <?php if (is_array($snap) && $snap): ?>
        <details><summary><?= in_array($e['action'], ['void', 'delete'], true) ? 'Content before this action' : 'Content after this action' ?></summary>
          <div class="row mt-2">
            <?php foreach ($snap as $k => $val): if (is_array($val)) { continue; } ?><div class="col-md-3"><span class="text-muted"><?= h($k) ?>:</span> <?= h($val) ?></div><?php endforeach; ?>
          </div>
          <?php if (!empty($snap['lines'])): ?>
          <table class="table table-sm mt-2"><thead><tr><?php foreach (array_keys($snap['lines'][0]) as $col): ?><th><?= h($col) ?></th><?php endforeach; ?></tr></thead>
            <?php foreach ($snap['lines'] as $ln): ?><tr><?php foreach ($ln as $val): ?><td><?= h($val) ?></td><?php endforeach; ?></tr><?php endforeach; ?></table>
          <?php endif; ?>
          <?php if (!empty($snap['applied_to'])): ?><div>Applied to: <?= h(implode(', ', array_map(fn($x) => $x['txn_no'] . ' ' . money($x['amount']), $snap['applied_to']))) ?></div><?php endif; ?>
        </details>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>
<?php
layout_end();
