<?php
declare(strict_types=1);

$pageKey = 'admin.audit_trail';
$from = gdate('from', date('Y-m-d', strtotime('-7 days')));
$to = gdate('to', today());
$f = [
    'user_id' => gint('user_id'), 'module' => g('module'), 'page' => g('page_key'), 'action' => g('action'),
    'record_type' => g('record_type'), 'q' => g('q'),
];
$where = ['a.created_at BETWEEN :f AND :t'];
$params = ['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59'];
if ($f['user_id']) {
    $where[] = 'a.user_id = :u';
    $params['u'] = $f['user_id'];
}
foreach (['module' => 'a.module', 'page' => 'a.page_key', 'action' => 'a.action', 'record_type' => 'a.record_type'] as $k => $col) {
    if ($f[$k] !== '') {
        $where[] = "$col = :$k";
        $params[$k] = $f[$k];
    }
}
if ($f['q'] !== '') {
    $where[] = '(a.record_ref LIKE :q OR a.reason LIKE :q OR a.changes LIKE :q OR a.username LIKE :q)';
    $params['q'] = '%' . $f['q'] . '%';
}
$sqlWhere = implode(' AND ', $where);
$total = (int)DB::value("SELECT COUNT(*) FROM audit_trail a WHERE $sqlWhere", $params);
$perPage = 100;
$pageNo = max(1, (int)g('pg', '1'));
$rows = DB::all("SELECT a.*, s.name AS site_name, t.name AS terminal_name FROM audit_trail a
                   LEFT JOIN sites s ON s.id = a.site_id LEFT JOIN pos_terminals t ON t.id = a.terminal_id
                  WHERE $sqlWhere ORDER BY a.id DESC LIMIT " . $perPage . ' OFFSET ' . (($pageNo - 1) * $perPage), $params);

if (g('export') === 'csv') {
    Access::require($pageKey, 'print');
    $all = DB::all("SELECT a.* FROM audit_trail a WHERE $sqlWhere ORDER BY a.id DESC LIMIT 50000", $params);
    Audit::log('export', 'admin', 'audit_trail', null, 'Audit trail ' . $from . ' to ' . $to, ['page_key' => $pageKey, 'reason' => count($all) . ' entries']);
    csv_download('audit_trail_' . $from . '_' . $to . '.csv', ['ID', 'Date/time', 'User', 'Roles', 'IP', 'Workstation', 'Module', 'Page', 'Action', 'Record type', 'Record ID', 'Record', 'Amount', 'Changes', 'Reason', 'Approved by'],
        array_map(fn($r) => [$r['id'], $r['created_at'], $r['username'], $r['user_roles'], $r['ip_address'], $r['workstation'], $r['module'], $r['page_key'], $r['action'],
            $r['record_type'], $r['record_id'], $r['record_ref'], $r['amount'],
            implode(' | ', array_map(fn($c) => $c['field'] . ': ' . json_encode($c['old']) . ' -> ' . json_encode($c['new']), json_decode((string)$r['changes'], true) ?: [])),
            $r['reason'], $r['approved_by']], $all));
}

if (g('snapshot')) {
    $snap = DB::row('SELECT * FROM audit_trail WHERE id = :id', ['id' => (int)g('snapshot')]);
    layout_start('Audit entry #' . (int)g('snapshot'), ['actions' => btn_link(url($pageKey), 'Back')]);
    if ($snap) {
        echo '<div class="card"><div class="card-body"><p><strong>' . h(ucwords(str_replace('_', ' ', $snap['action']))) . '</strong> ' . h($snap['record_ref']) . ' by ' . h($snap['username']) . ' on ' . h(fdatetime($snap['created_at'])) . '</p>';
        echo '<pre class="bg-light p-3 small" style="white-space:pre-wrap">' . h(json_encode(json_decode((string)$snap['snapshot'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre></div></div>';
    }
    layout_end();
    return;
}

$users = DB::pairs('SELECT id, username FROM users ORDER BY username');
$modules = DB::pairs('SELECT code, name FROM modules ORDER BY sort_order') + ['security' => 'Security'];
$actions = DB::column('SELECT DISTINCT action FROM audit_trail ORDER BY action');
$types = DB::column('SELECT DISTINCT record_type FROM audit_trail WHERE record_type IS NOT NULL ORDER BY record_type');
$pages = DB::pairs('SELECT page_key, name FROM pages ORDER BY name');

layout_start('Audit Trail', ['actions' => (Access::can($pageKey, 'print') ? btn_link(current_url(['export' => 'csv']), 'Export', 'btn-outline-secondary', 'download') . ' ' : '')
    . '<button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>']);
?>
<p class="small text-muted">A permanent, read-only log of every action that creates, changes or removes information. Entries cannot be edited or deleted by anyone.</p>
<form class="card card-body mb-3 filters" method="get"><input type="hidden" name="p" value="<?= h($pageKey) ?>">
  <div class="row g-2">
    <div class="col-md-2"><label class="form-label">From</label><input class="form-control form-control-sm" type="date" name="from" value="<?= h($from) ?>"></div>
    <div class="col-md-2"><label class="form-label">To</label><input class="form-control form-control-sm" type="date" name="to" value="<?= h($to) ?>"></div>
    <?= select_field('user_id', 'User', $users, $f['user_id'], ['blank' => 'All', 'class' => 'col-md-2']) ?>
    <?= select_field('module', 'Module', $modules, $f['module'], ['blank' => 'All', 'class' => 'col-md-2']) ?>
    <?= select_field('page_key', 'Page', $pages, $f['page'], ['blank' => 'All', 'class' => 'col-md-2']) ?>
    <?= select_field('action', 'Action', array_combine($actions, array_map(fn($x) => ucwords(str_replace('_', ' ', $x)), $actions)) ?: [], $f['action'], ['blank' => 'All', 'class' => 'col-md-2']) ?>
    <?= select_field('record_type', 'Record type', array_combine($types, $types) ?: [], $f['record_type'], ['blank' => 'All', 'class' => 'col-md-2']) ?>
    <?= input_field('q', 'Record / text', $f['q'], ['class' => 'col-md-4']) ?>
    <div class="col-md-2 d-flex align-items-end"><button class="btn btn-sm btn-primary">Filter</button></div>
  </div>
</form>
<div class="small text-muted mb-1"><?= number_format($total) ?> entries</div>
<div class="card"><div class="table-responsive"><table class="table table-sm table-tight mb-0 small">
  <thead><tr><th>Date / time</th><th>User</th><th>Where</th><th>Module / page</th><th>Action</th><th>Record</th><th class="num">Amount</th><th>Changes (before → after)</th><th>Reason / approval</th></tr></thead>
  <?php foreach ($rows as $r):
      $changes = json_decode((string)$r['changes'], true) ?: [];
      $recLink = h($r['record_ref']);
      if ($r['record_type'] === 'transaction' && $r['record_id']) {
          $t = Txn::load((int)$r['record_id']);
          $recLink = $t ? Txn::link($t, (string)$r['record_ref']) : h($r['record_ref']) . ' <span class="badge text-bg-dark">deleted</span>';
          if (Access::page('admin.txn_history')) {
              $recLink .= ' <a class="text-muted" title="History" href="' . h(url('admin.txn_history', ['id' => $r['record_id']])) . '"><i class="bi bi-clock-history"></i></a>';
          }
      }
      $danger = in_array($r['action'], ['delete', 'void', 'login_failed', 'account_locked', 'access_denied', 'approval_failed', 'sod_override', 'credit_limit_override'], true);
  ?>
    <tr class="<?= $danger ? 'table-warning' : '' ?>">
      <td class="text-nowrap"><?= h(date('m/d/Y H:i:s', strtotime($r['created_at']))) ?></td>
      <td><?= h($r['username']) ?><div class="text-muted"><?= h($r['user_roles']) ?></div></td>
      <td><?= h($r['ip_address']) ?><?= $r['terminal_name'] ? '<div>' . h($r['terminal_name']) . '</div>' : '' ?><?= $r['site_name'] ? '<div class="text-muted">' . h($r['site_name']) . '</div>' : '' ?></td>
      <td><?= h($modules[$r['module']] ?? $r['module']) ?><div class="text-muted"><?= h($pages[$r['page_key']] ?? $r['page_key']) ?></div></td>
      <td><?= h(ucwords(str_replace('_', ' ', $r['action']))) ?></td>
      <td><?= $recLink ?><?= $r['snapshot'] ? ' <a href="' . h(url($pageKey, ['snapshot' => $r['id']])) . '" title="Full copy">[copy]</a>' : '' ?></td>
      <td class="num"><?= $r['amount'] !== null ? money($r['amount']) : '' ?></td>
      <td><?php foreach (array_slice($changes, 0, 8) as $c): ?>
          <div><span class="text-muted"><?= h($c['field']) ?>:</span> <span class="diff-old"><?= h(is_scalar($c['old']) || $c['old'] === null ? $c['old'] : json_encode($c['old'])) ?></span> → <span class="diff-new"><?= h(is_scalar($c['new']) || $c['new'] === null ? $c['new'] : json_encode($c['new'])) ?></span></div>
        <?php endforeach; ?><?= count($changes) > 8 ? '<div class="text-muted">+' . (count($changes) - 8) . ' more</div>' : '' ?></td>
      <td><?= h($r['reason']) ?><?= $r['approved_by'] ? '<div>Approved by <strong>' . h($r['approved_by']) . '</strong></div>' : '' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted">No entries.</td></tr><?php endif; ?>
</table></div></div>
<?php if ($total > $perPage): ?>
<nav class="mt-2"><ul class="pagination pagination-sm">
  <?php for ($i = 1; $i <= min(50, (int)ceil($total / $perPage)); $i++): ?><li class="page-item <?= $i === $pageNo ? 'active' : '' ?>"><a class="page-link" href="<?= h(current_url(['pg' => $i])) ?>"><?= $i ?></a></li><?php endfor; ?>
</ul></nav>
<?php endif; ?>
<?php
layout_end();
