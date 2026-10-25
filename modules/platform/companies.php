<?php
declare(strict_types=1);

/** Platform > Companies: every company, its code, owner, database and status; open one to manage it. */

if (is_post() && g('a') === 'open') {
    try {
        Platform::enterCompany((int)p('id'));
        redirect(url('home.dashboard'));
    } catch (UserError $e) {
        flash('danger', $e->getMessage());
        redirect(purl('companies'));
    }
}

$companies = Master::companies();
$tally = ['active' => 0, 'inactive' => 0, 'provisioning' => 0, 'failed' => 0];
$users = 0;
$pending = 0;
foreach ($companies as $c) {
    $tally[$c['status']] = ($tally[$c['status']] ?? 0) + 1;
    $users += (int)$c['users_count'];
    $pending += (int)$c['pending_count'];
}

platform_start('Companies', ['actions' => btn_link(purl('new'), 'New company', 'btn-primary', 'plus-lg')
    . ' ' . btn_link(purl('updates'), 'Database updates', 'btn-outline-secondary', 'database-check')]);
?>
<div class="row g-3 mb-3">
  <div class="col-6 col-xl-3"><div class="card kpi h-100"><div class="card-body"><div class="small text-muted">Active companies</div><div class="value"><?= (int)$tally['active'] ?></div><div class="small text-muted"><?= count($companies) ?> in all</div></div></div></div>
  <div class="col-6 col-xl-3"><div class="card kpi h-100" style="border-left-color: var(--bs-secondary)"><div class="card-body"><div class="small text-muted">Inactive</div><div class="value"><?= (int)$tally['inactive'] ?></div><div class="small text-muted">Users cannot log in</div></div></div></div>
  <div class="col-6 col-xl-3"><div class="card kpi h-100" style="border-left-color: var(--bs-success)"><div class="card-body"><div class="small text-muted">Company users</div><div class="value"><?= $users ?></div><div class="small text-muted">Across every company</div></div></div></div>
  <div class="col-6 col-xl-3"><div class="card kpi h-100" style="border-left-color: var(--bs-warning)"><div class="card-body"><div class="small text-muted">Waiting for approval</div><div class="value"><?= $pending ?></div><div class="small text-muted">Approved by each Company Owner</div></div></div></div>
</div>
<?php if ($tally['failed'] || $tally['provisioning']): ?>
  <div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle"></i>
    <?= $tally['failed'] ? (int)$tally['failed'] . ' company set-up failed.' : '' ?> <?= $tally['provisioning'] ? (int)$tally['provisioning'] . ' company is still being set up.' : '' ?>
    Open the company to see the steps and set it up again.</div>
<?php endif; ?>

<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0 platform-companies">
  <thead><tr><th>#</th><th>Company</th><th>Company Code</th><th>Company Owner</th><th>Status</th><th class="num">Users</th><th>Database</th><th class="text-end"></th></tr></thead>
  <tbody>
  <?php foreach ($companies as $c): ?>
    <tr>
      <td class="text-muted"><?= (int)$c['id'] ?></td>
      <td><a class="fw-semibold" href="<?= h(purl('company', ['id' => $c['id']])) ?>"><?= h($c['name']) ?></a>
        <div class="small text-muted"><?= h(trim(($c['contact_person'] ?: '') . ($c['contact_person'] && $c['email'] ? ', ' : '') . ($c['email'] ?: ''))) ?></div></td>
      <td class="text-nowrap"><span class="code-inline"><?= h(CompanyCode::display($c['code'])) ?></span><?= ($c['code_status'] ?? '') === 'disabled' ? '<div class="small text-muted">joining off</div>' : '' ?></td>
      <td><?= $c['owner_username'] ? h($c['owner_name']) . '<div class="small text-muted">' . h($c['owner_username']) . '</div>' : '<span class="text-muted small">Not assigned</span>' ?></td>
      <td><?= platform_status((string)$c['status']) ?></td>
      <td class="num"><?= (int)$c['users_count'] ?><?= (int)$c['pending_count'] ? '<div class="small text-warning-emphasis">' . (int)$c['pending_count'] . ' waiting</div>' : '' ?></td>
      <td class="small"><code><?= h($c['db_name'] ?: '') ?></code><div class="text-muted"><?= (int)$c['is_primary'] ? 'original database' : 'since ' . h(fdate($c['created_at'])) ?></div></td>
      <td class="text-end text-nowrap">
        <?php if (in_array($c['status'], ['active', 'inactive'], true)): ?>
        <form method="post" action="<?= h(purl('companies', ['a' => 'open'])) ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
          <button class="btn btn-sm btn-outline-primary" title="Work inside this company as Super Admin"><i class="bi bi-box-arrow-in-right"></i> Open</button></form>
        <?php endif; ?>
        <?php if (!in_array($c['status'], ['active', 'inactive'], true)): ?><a class="btn btn-sm btn-outline-secondary" href="<?= h(purl('company', ['id' => $c['id']])) ?>">Details</a><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$companies): ?><tr><td colspan="8" class="text-muted py-3">No company yet.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
<p class="small text-muted mt-2">Each company has a database of its own. Its users only ever see that database; the Super Admin opens a company to work inside it, and every change made there is recorded in that company's audit trail.</p>
<?php
platform_end();
