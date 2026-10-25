<?php
declare(strict_types=1);

$pageKey = 'admin.effective_access';
$userId = (int)g('user_id');
$users = DB::pairs("SELECT id, CONCAT(full_name, ' (', username, ')') FROM users WHERE is_platform = 0 ORDER BY full_name");
$showDenied = g('denied') === '1';

layout_start('Effective Access (View as User)');
?>
<form method="get" class="row g-2 mb-3 filters"><input type="hidden" name="p" value="<?= h($pageKey) ?>">
  <div class="col-md-4"><select class="form-select form-select-sm" name="user_id" data-autosubmit><?= options($users, $userId, 'Choose a user…') ?></select></div>
  <div class="col-md-3 d-flex align-items-center"><label class="small"><input type="checkbox" name="denied" value="1" <?= checked($showDenied) ?> data-autosubmit> Show denied pages too</label></div>
  <div class="col-md-5 text-end"><?php if ($userId): ?><button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button><?php endif; ?></div>
</form>
<?php if ($userId):
    $u = DB::row('SELECT * FROM users WHERE id = :id', ['id' => $userId]);
    $eff = Access::effective($userId);
    $defs = Access::allPageDefs();
    $mods = Access::modules();
    Audit::log('view_access', 'admin', 'user', $userId, $u['username'], ['page_key' => $pageKey]);
    $sites = Access::siteIds($userId);
    $terms = DB::column("SELECT CONCAT(t.code, ' ', t.name) FROM user_terminals ut JOIN pos_terminals t ON t.id = ut.terminal_id WHERE ut.user_id = :u", ['u' => $userId]);
?>
<div class="row g-3 mb-3">
  <div class="col-md-6"><div class="card h-100"><div class="card-body small">
    <div class="fs-6 fw-semibold"><?= h($u['full_name']) ?> <span class="text-muted">(<?= h($u['username']) ?>)</span> <?= status_badge($u['status']) ?></div>
    <div>Roles: <strong><?= h(implode(', ', Access::roleNames($userId)) ?: 'none') ?></strong></div>
    <div>Sites: <?= $sites === null ? 'all' : h(implode(', ', DB::column('SELECT name FROM sites WHERE id IN ' . DB::in('s', $sites ?: [0])[0], DB::in('s', $sites ?: [0])[1])) ?: 'none') ?></div>
    <div>POS terminals: <?= h(implode(', ', $terms) ?: 'any at allowed sites') ?></div>
    <div>Pages allowed: <strong><?= count(array_filter($eff, fn($e) => $e['allowed'])) ?></strong> of <?= count($eff) ?></div>
  </div></div></div>
  <div class="col-md-6"><div class="card h-100"><div class="card-header small fw-semibold">Sensitive permissions</div><table class="table table-sm mb-0 small">
    <?php foreach (Access::SPECIAL as $k => [$label, $type]): $v = Access::special($k, $userId); ?>
      <tr><td><?= h($label) ?></td><td class="text-end"><?= $type === 'bool' ? ($v ? '<span class="text-success">Yes</span>' : '<span class="text-danger">No</span>') : ($v === null ? 'No limit' : h(rtrim(rtrim(number_format((float)$v, 2), '0'), '.'))) ?></td></tr>
    <?php endforeach; ?>
  </table></div></div>
</div>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0">
  <thead class="sticky-top bg-white"><tr><th>Module</th><th>Page</th><th>Access</th><?php foreach (Access::ACTIONS as $lbl): ?><th class="text-center"><?= h($lbl) ?></th><?php endforeach; ?><th>Comes from</th></tr></thead>
  <?php foreach ($defs as $k => $d): $e = $eff[$k]; if (!$e['allowed'] && !$showDenied) { continue; } ?>
    <tr class="<?= $e['override'] ? 'override-flag' : '' ?>">
      <td class="small"><?= h($mods[$d['module']][0]) ?></td><td><?= h($d['name']) ?></td>
      <td><?= $e['allowed'] ? '<span class="text-success">Allowed</span>' : '<span class="text-danger">Denied</span>' ?></td>
      <?php foreach (array_keys(Access::ACTIONS) as $act): ?><td class="text-center"><?= !empty($e[$act]) ? '<i class="bi bi-check-lg text-success"></i>' : '' ?></td><?php endforeach; ?>
      <td class="small"><?= h($e['source'] ?: ($e['allowed'] ? '' : 'No role grants it')) ?></td></tr>
  <?php endforeach; ?>
</table></div></div>
<?php endif; ?>
<?php
layout_end();
