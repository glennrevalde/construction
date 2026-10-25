<?php
declare(strict_types=1);

$pageKey = 'home.memorized';
$a = g('a');
if ($a === 'save' && is_post()) {
    $key = (string)p('report_key');
    if (!Access::page($key) || !str_starts_with($key, 'rpt.') && $key !== 'admin.audit_trail') {
        flash('danger', 'You cannot memorize that report.');
        redirect(url($pageKey));
    }
    $schedule = in_array(p('schedule'), ['daily', 'weekly', 'monthly'], true) ? p('schedule') : null;
    $id = DB::insert('memorized_reports', [
        'name' => mb_substr((string)p('name'), 0, 120) ?: 'Report', 'group_name' => p('group_name') ?: null, 'report_key' => $key,
        'query_string' => mb_substr((string)p('query_string'), 0, 4000), 'is_shared' => p('is_shared') === '1' ? 1 : 0,
        'schedule' => $schedule, 'email_to' => $schedule ? (p('email_to') ?: null) : null, 'created_by' => Auth::id(),
    ]);
    Audit::log('memorize_report', 'home', 'memorized_report', $id, (string)p('name'), ['page_key' => $key]);
    flash('success', 'Report memorized.');
    redirect(base_url() . '/index.php?p=' . urlencode($key) . '&' . p('query_string'));
}
if ($a === 'delete' && is_post()) {
    $m = DB::row('SELECT * FROM memorized_reports WHERE id = :id', ['id' => (int)g('id')]);
    if ($m && ((int)$m['created_by'] === Auth::id() || Access::isAdmin())) {
        DB::run('DELETE FROM memorized_reports WHERE id = :id', ['id' => $m['id']]);
        Audit::log('delete', 'home', 'memorized_report', (int)$m['id'], $m['name'], ['snapshot' => $m]);
        flash('success', 'Memorized report removed.');
    }
    redirect(url($pageKey));
}
$rows = DB::all('SELECT m.*, u.username FROM memorized_reports m JOIN users u ON u.id = m.created_by WHERE m.created_by = :u OR m.is_shared = 1 ORDER BY m.group_name, m.name', ['u' => Auth::id()]);
$defs = Access::allPageDefs();
layout_start('Memorized Reports', ['actions' => page_link('home.reports', [], 'Report Center', 'btn-outline-primary', 'bar-chart')]);
?>
<p class="text-muted">Save a customized report from its "Memorize" button. Scheduled reports are e-mailed by <code>tools/send_scheduled_reports.php</code> (run it daily from cron or Task Scheduler).</p>
<div class="card"><table class="table table-sm mb-0">
  <thead><tr><th>Group</th><th>Name</th><th>Report</th><th>Shared</th><th>Schedule</th><th>Owner</th><th></th></tr></thead>
  <?php foreach ($rows as $m): $allowed = Access::page($m['report_key']); ?>
    <tr><td><?= h($m['group_name']) ?></td>
      <td><?= $allowed ? '<a href="' . h(base_url() . '/index.php?p=' . urlencode($m['report_key']) . '&' . $m['query_string']) . '">' . h($m['name']) . '</a>' : h($m['name']) . ' <span class="badge text-bg-secondary">no access</span>' ?></td>
      <td class="small"><?= h($defs[$m['report_key']]['name'] ?? $m['report_key']) ?></td><td><?= (int)$m['is_shared'] ? 'Yes' : '' ?></td>
      <td class="small"><?= h($m['schedule'] ? ucfirst($m['schedule']) . ' → ' . $m['email_to'] . ($m['last_sent_at'] ? ' (last ' . fdatetime($m['last_sent_at']) . ')' : '') : '') ?></td>
      <td><?= h($m['username']) ?></td>
      <td><?php if ((int)$m['created_by'] === Auth::id() || Access::isAdmin()): ?><form method="post" action="<?= h(url($pageKey, ['a' => 'delete', 'id' => $m['id']])) ?>"><?= csrf_field() ?><button class="btn btn-link btn-sm p-0 text-danger" data-confirm="Remove this memorized report?">Remove</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="text-muted text-center">No memorized reports yet.</td></tr><?php endif; ?>
</table></div>
<?php
layout_end();
