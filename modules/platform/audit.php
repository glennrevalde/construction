<?php
declare(strict_types=1);

/** Platform > Platform audit log: what happened on the platform, and to which company. Entries cannot be changed. */

$m = Master::sql();
$companyNames = $m->pairs('SELECT id, name FROM companies ORDER BY name');
$f = ['company' => gint('company'), 'action' => (string)g('action'), 'from' => gdate('from', date('Y-m-d', strtotime('-30 days'))), 'to' => gdate('to', today())];
$where = ['a.created_at >= :from', 'a.created_at < :to + INTERVAL 1 DAY'];
$params = ['from' => $f['from'], 'to' => $f['to']];
if ($f['company']) {
    $where[] = 'a.company_id = :c';
    $params['c'] = $f['company'];
}
if ($f['action'] !== '') {
    $where[] = 'a.action = :act';
    $params['act'] = $f['action'];
}
$rows = $m->all('SELECT a.*, c.name AS company FROM platform_audit a LEFT JOIN companies c ON c.id = a.company_id WHERE ' . implode(' AND ', $where)
    . ' ORDER BY a.id DESC LIMIT 500', $params);
$actions = $m->column('SELECT DISTINCT action FROM platform_audit ORDER BY action');

platform_start('Platform audit log');
?>
<form method="get" class="row g-2 mb-2 filters"><input type="hidden" name="p" value="audit">
  <?= select_field('company', 'Company', $companyNames, $f['company'], ['blank' => 'All companies', 'class' => 'col-md-3', 'extra' => 'data-autosubmit']) ?>
  <?= select_field('action', 'What happened', array_combine($actions, array_map(fn($x) => str_replace('_', ' ', $x), $actions)) ?: [], $f['action'], ['blank' => 'Everything', 'class' => 'col-md-3', 'extra' => 'data-autosubmit']) ?>
  <?= input_field('from', 'From', $f['from'], ['class' => 'col-md-2', 'type' => 'date']) ?>
  <?= input_field('to', 'To', $f['to'], ['class' => 'col-md-2', 'type' => 'date']) ?>
  <div class="col-md-2 d-flex align-items-end"><button class="btn btn-sm btn-outline-primary w-100">Show</button></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0 small">
  <thead><tr><th>When</th><th>Who</th><th>Company</th><th>What happened</th><th>Details</th><th>From</th></tr></thead>
  <?php foreach ($rows as $r): ?>
    <tr><td class="text-nowrap"><?= h(fdatetime($r['created_at'])) ?></td>
      <td><?= h($r['actor_name'] ?: '') ?><div class="text-muted"><?= h(str_replace('_', ' ', $r['actor_type'])) ?></div></td>
      <td><?= h($r['company'] ?: ($r['company_id'] ? '#' . $r['company_id'] : '')) ?></td>
      <td><?= h(str_replace('_', ' ', $r['action'])) ?></td>
      <td class="text-break"><?= h($r['details']) ?></td>
      <td class="text-nowrap text-muted"><?= h($r['ip_address']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="text-muted py-3">Nothing in this period.</td></tr><?php endif; ?>
</table></div></div>
<p class="small text-muted mt-2">Showing the latest 500 entries of the period. What happens inside a company is also in that company's own audit trail.</p>
<?php
platform_end();
