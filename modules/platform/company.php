<?php
declare(strict_types=1);

/**
 * Platform > Company: one company's details, owner, Company Code, database, users and set-up log,
 * and everything the Super Admin can do to it.
 */

$id = gint('id') ?: pint('id');
$c = $id ? Master::company($id, true) : null;
if (!$c) {
    flash('warning', 'That company was not found.');
    redirect(purl('companies'));
}
$ready = in_array($c['status'], ['active', 'inactive'], true) && (string)$c['db_name'] !== '';
$db = null;
$dbError = '';
if ($ready) {
    try {
        $db = Sql::to((string)$c['db_name']);
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}
$saName = $sa['username'];

// ------------------------------------------------------------------ actions
if (is_post()) {
    $a = (string)p('a');
    try {
        $companyDbNeeded = in_array($a, ['info', 'status', 'join_rules', 'owner', 'triggers', 'sync'], true);
        if ($companyDbNeeded && $a !== 'info' && !$db) {
            fail('The company database cannot be reached' . ($dbError ? ': ' . $dbError : '.'));
        }
        if ($a === 'info') {
            $d = [
                'name' => mb_substr(trim((string)p('name')), 0, 150), 'address' => mb_substr(trim((string)p('address')), 0, 255) ?: null,
                'contact_person' => mb_substr(trim((string)p('contact_person')), 0, 120) ?: null, 'phone' => mb_substr(trim((string)p('phone')), 0, 60) ?: null,
                'email' => mb_substr(trim((string)p('email')), 0, 120) ?: null, 'tin' => mb_substr(trim((string)p('tin')), 0, 40) ?: null,
                'notes' => mb_substr(trim((string)p('notes')), 0, 255) ?: null,
            ];
            if ($d['name'] === '') {
                fail('Enter the company name.');
            }
            if ($d['email'] && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
                fail('The e-mail address does not look right.');
            }
            if (Master::sql()->value('SELECT id FROM companies WHERE name = :n AND id <> :id', ['n' => $d['name'], 'id' => $id])) {
                fail('Another company already has that name.');
            }
            $changes = [];
            foreach ($d as $k => $v) {
                if ((string)($c[$k] ?? '') !== (string)$v) {
                    $changes[] = ['field' => $k, 'old' => $c[$k] ?? null, 'new' => $v];
                }
            }
            Master::sql()->update('companies', $d + ['updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            if ($db) {
                // the company's own settings carry the same details (printed on its reports and vouchers)
                foreach (['company_name' => $d['name'], 'company_address' => $d['address'], 'company_phone' => $d['phone'], 'company_email' => $d['email'], 'company_tin' => $d['tin']] as $k => $v) {
                    $db->run('INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', ['k' => $k, 'v' => (string)$v]);
                }
                if ($changes) {
                    Provision::audit($db, 'settings_change', 'company', $id, $d['name'], 'Company details changed by the Super Admin', $changes);
                }
            }
            Master::log('company_edited', $id, $changes ? implode(', ', array_column($changes, 'field')) . ' changed' : 'No change');
            flash('success', 'Company details saved.');
        } elseif ($a === 'status') {
            $to = p('to') === 'active' ? 'active' : 'inactive';
            $reason = mb_substr(trim((string)p('reason')), 0, 200);
            if (!in_array($c['status'], ['active', 'inactive'], true)) {
                fail('Only a company that is set up can be switched on or off.');
            }
            if ($to === 'inactive' && $reason === '') {
                fail('Say why the company is being deactivated.');
            }
            if ($to === 'active' && Schema::pendingOn($db)) {
                fail('Bring the company database up to date first (Database updates).');
            }
            Master::sql()->update('companies', ['status' => $to, $to === 'active' ? 'activated_at' : 'deactivated_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            Provision::audit($db, $to === 'active' ? 'company_activated' : 'company_deactivated', 'company', $id, (string)$c['name'],
                ($to === 'active' ? 'Company switched on by the Super Admin' : 'Company switched off by the Super Admin') . ($reason !== '' ? ': ' . $reason : ''));
            Master::log($to === 'active' ? 'company_activated' : 'company_deactivated', $id, $reason);
            flash('success', $to === 'active' ? $c['name'] . ' is active again. Its users can log in.'
                : $c['name'] . ' is deactivated. Its users cannot log in and nobody can join it; all of its data is kept.');
        } elseif ($a === 'regenerate') {
            $new = CompanyCode::regenerate($id, 'super:' . $saName);
            if ($db) {
                Provision::audit($db, 'company_code_changed', 'company', $id, (string)$c['name'], 'Company Code regenerated by the Super Admin',
                    [['field' => 'company_code', 'old' => CompanyCode::display($c['code']), 'new' => CompanyCode::display($new)]]);
            }
            Master::log('company_code_regenerated', $id, CompanyCode::display($c['code']) . ' retired, ' . CompanyCode::display($new) . ' issued');
            flash('success', 'New Company Code: ' . CompanyCode::display($new) . '. The old one no longer works.');
        } elseif ($a === 'code_state') {
            $exp = trim((string)p('expires_at'));
            if ($exp !== '' && !valid_date($exp)) {
                fail('Enter the expiry as a date.');
            }
            $state = p('joining') === '1' ? 'active' : 'disabled';
            CompanyCode::setState($id, $state, $exp !== '' ? $exp . ' 23:59:59' : null);
            Master::log('company_code_state', $id, 'Joining ' . $state . ($exp !== '' ? ' until ' . $exp : ''));
            flash('success', 'Company Code saved.');
        } elseif ($a === 'join_rules') {
            $approval = p('require_approval') === '1';
            $roleId = pint('join_role_id');
            if ($roleId && !$db->value('SELECT id FROM roles WHERE id = :id AND is_admin = 0', ['id' => $roleId])) {
                fail('Choose one of the company\'s roles (not a full-access role).');
            }
            if (!$approval && !$roleId) {
                fail('Without approval, new employees need a role to start with.');
            }
            Master::sql()->update('companies', ['require_approval' => $approval ? 1 : 0, 'join_role_id' => $roleId ?: null, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            Master::log('company_join_rules', $id, $approval ? 'Approval required' : 'No approval');
            flash('success', 'Joining rules saved.');
        } elseif ($a === 'owner') {
            $new = null;
            if (p('owner_mode') === 'new') {
                $new = ['username' => mb_strtolower(trim((string)p('owner_username'))), 'full_name' => trim((string)p('owner_full_name')),
                    'email' => trim((string)p('owner_email')), 'password' => (string)($_POST['owner_password'] ?? '')];
            }
            Provision::setOwner($id, $new ? null : pint('owner_user_id'), $new);
            flash('success', 'Company Owner saved.' . ($new ? ' They must change the temporary password at the first log-in.' : ''));
        } elseif ($a === 'triggers') {
            $problem = Audit::ensureTriggers($db);
            Master::sql()->update('company_databases', ['triggers_ok' => $problem === '' ? 1 : 0], 'company_id = :id', ['id' => $id]);
            Master::log('company_triggers', $id, $problem === '' ? 'Audit trail protection put in place' : $problem);
            $problem === '' ? flash('success', 'The audit trail protection is in place.') : flash('danger', 'The triggers could not be created: ' . $problem);
        } elseif ($a === 'sync') {
            $r = Membership::sync($id, $db);
            Master::log('company_users_synced', $id, $r['users'] . ' user(s)' . ($r['conflicts'] ? '; username clashes: ' . implode(', ', $r['conflicts']) : ''));
            flash($r['conflicts'] ? 'warning' : 'success', $r['users'] . ' user(s) listed on the platform.'
                . ($r['conflicts'] ? ' These usernames belong to another company and cannot log in until they are renamed: ' . implode(', ', $r['conflicts']) . '.' : ''));
        } elseif ($a === 'retry') {
            $owner = null;
            if (trim((string)p('owner_username')) !== '') {
                $owner = ['username' => mb_strtolower(trim((string)p('owner_username'))), 'full_name' => trim((string)p('owner_full_name')),
                    'email' => trim((string)p('owner_email')), 'password' => (string)($_POST['owner_password'] ?? '')];
            }
            $ok = Provision::retry($id, $owner);
            $ok ? flash('success', 'The company is set up and active.') : flash('danger', 'The set-up failed again. The steps below say where.');
        } elseif ($a === 'delete') {
            if (p('confirm_name') !== $c['name']) {
                fail('Type the company name exactly to delete the failed record.');
            }
            Provision::deleteFailed($id);
            flash('success', 'The failed company record was deleted.');
            redirect(purl('companies'));
        } else {
            fail('Unknown action.');
        }
    } catch (UserError $e) {
        flash('danger', $e->getMessage());
    }
    redirect(purl('company', ['id' => $id]));
}

// ------------------------------------------------------------------ what to show
$users = [];
$roles = [];
$pendingFiles = [];
$triggersNow = null;
if ($db) {
    $users = $db->all("SELECT u.*, GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ', ') AS role_names
                         FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id
                        WHERE u.is_platform = 0 GROUP BY u.id ORDER BY (u.approval_status = 'pending') DESC, u.full_name");
    $roles = $db->pairs('SELECT id, name FROM roles WHERE is_admin = 0 ORDER BY name');
    $pendingFiles = Schema::pendingOn($db);
    $triggersNow = Audit::triggersInPlace($db);
}
// the latest attempt first, each attempt's steps in the order they ran
$log = Master::sql()->all('SELECT * FROM company_provisioning_log WHERE company_id = :c ORDER BY attempt DESC, id ASC LIMIT 60', ['c' => $id]);
$activity = Master::sql()->all('SELECT * FROM platform_audit WHERE company_id = :c ORDER BY id DESC LIMIT 25', ['c' => $id]);
$template = $c['template_company_id'] ? Master::company((int)$c['template_company_id']) : null;
$storageDir = (int)$c['is_primary'] ? 'storage/ (the original folders)' : 'storage/companies/' . Tenant::storageKey($id) . '/';
$ownerRow = null;
foreach ($users as $u) {
    if ((int)$u['id'] === (int)$c['owner_user_id']) {
        $ownerRow = $u;
    }
}
$ownerChoices = [];
foreach ($users as $u) {
    // a user of another company working here (CompanyAccess) cannot be made this company's owner
    if ($u['status'] === 'active' && $u['approval_status'] === 'approved' && $u['created_via'] !== 'link') {
        $ownerChoices[(int)$u['id']] = $u['full_name'] . ' (' . $u['username'] . ')';
    }
}
$viaText = ['registration' => 'Joined with the code', 'provisioning' => 'Created with the company', 'setup' => 'Company set-up', 'platform' => 'Super Admin', 'admin' => 'Administrator', 'link' => 'User of another company'];

$actions = '';
if ($ready) {
    $actions .= '<form method="post" action="' . h(purl('companies', ['a' => 'open'])) . '" class="d-inline">' . csrf_field()
        . '<input type="hidden" name="id" value="' . (int)$id . '"><button class="btn btn-sm btn-primary"><i class="bi bi-box-arrow-in-right"></i> Open company</button></form> ';
}
$actions .= btn_link(purl('companies'), 'All companies');
platform_start($c['name'], ['actions' => $actions]);
?>
<div class="d-flex flex-wrap align-items-center gap-3 mb-3 company-headline">
  <div><?= platform_status((string)$c['status']) ?></div>
  <div class="small text-muted">Company #<?= (int)$id ?>, created <?= h(fdate($c['created_at'])) ?><?= $template && !(int)$c['is_primary'] ? ', set-up copied from ' . h($template['name']) : '' ?></div>
</div>

<?php if (in_array($c['status'], ['failed', 'provisioning'], true)): ?>
<div class="card border-danger-subtle mb-3"><div class="card-body">
  <h2 class="h6 text-danger mb-2"><i class="bi bi-exclamation-octagon"></i> <?= $c['status'] === 'failed' ? 'The set-up did not finish' : 'The set-up has not finished' ?></h2>
  <?php if ($c['last_error']): ?><p class="small mb-2"><?= h($c['last_error']) ?></p><?php endif; ?>
  <p class="small text-muted">The company is not active, nobody can log in to it or join it, and any database this attempt created was removed again. Fix the cause (for example the database rights) and set it up again.</p>
  <?php if (Provision::canRetry($c)): ?>
  <form method="post" class="row g-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="a" value="retry">
    <div class="col-12 small fw-semibold">Company Owner to create (optional; passwords are never kept, so it has to be typed again)</div>
    <?= input_field('owner_username', 'Username', '', ['class' => 'col-md-3', 'extra' => 'autocomplete="off"']) ?>
    <?= input_field('owner_full_name', 'Full name', '', ['class' => 'col-md-3']) ?>
    <?= input_field('owner_email', 'E-mail', '', ['class' => 'col-md-3', 'type' => 'email']) ?>
    <div class="col-md-3"><label class="form-label">Temporary password</label><input class="form-control form-control-sm" type="password" name="owner_password" autocomplete="new-password"></div>
    <div class="col-12 d-flex gap-2 flex-wrap"><button class="btn btn-sm btn-primary"><i class="bi bi-arrow-repeat"></i> Set it up again</button></div>
  </form>
  <?php endif; ?>
  <?php if ($c['status'] === 'failed'): ?>
  <hr><form method="post" class="row g-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="a" value="delete">
    <div class="col-md-6"><label class="form-label">Or delete this failed record: type the company name</label><input class="form-control form-control-sm" name="confirm_name" autocomplete="off"></div>
    <div class="col-md-3"><button class="btn btn-sm btn-outline-danger w-100" data-confirm="Delete the record of this failed company?">Delete record</button></div>
  </form>
  <?php endif; ?>
</div></div>
<?php endif; ?>

<?php if ($dbError): ?><div class="alert alert-danger">The company database cannot be reached: <?= h($dbError) ?></div><?php endif; ?>
<?php if ($pendingFiles): ?><div class="alert alert-warning py-2"><i class="bi bi-database-exclamation"></i> This company's database needs <?= count($pendingFiles) ?> update(s). <a href="<?= h(purl('updates')) ?>">Database updates</a></div><?php endif; ?>

<div class="row g-3">
  <div class="col-xl-7">
    <form method="post" class="card mb-3"><div class="card-header fw-semibold">Company information</div><div class="card-body">
      <?= csrf_field() ?><input type="hidden" name="a" value="info">
      <div class="row g-2">
        <?= input_field('name', 'Company name', fv('name', $c), ['class' => 'col-md-8', 'required' => true]) ?>
        <?= input_field('tin', 'TIN', fv('tin', $c), ['class' => 'col-md-4']) ?>
        <?= input_field('address', 'Address', fv('address', $c), ['class' => 'col-12']) ?>
        <?= input_field('contact_person', 'Contact person', fv('contact_person', $c), ['class' => 'col-md-4']) ?>
        <?= input_field('phone', 'Phone', fv('phone', $c), ['class' => 'col-md-4']) ?>
        <?= input_field('email', 'E-mail', fv('email', $c), ['class' => 'col-md-4', 'type' => 'email']) ?>
        <?= input_field('notes', 'Notes (platform only)', fv('notes', $c), ['class' => 'col-12']) ?>
        <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-check-lg"></i> Save</button>
          <span class="small text-muted ms-2">The name, address, phone, e-mail and TIN are also the ones on the company's own reports.</span></div>
      </div>
    </div></form>

    <div class="card mb-3"><div class="card-header fw-semibold">Company Owner</div><div class="card-body">
      <?php if ($ownerRow): ?>
        <p class="mb-3"><strong><?= h($ownerRow['full_name']) ?></strong> (<?= h($ownerRow['username']) ?>)<?= $ownerRow['email'] ? ', ' . h($ownerRow['email']) : '' ?>.
          <span class="text-muted small">Last log-in <?= h(fdatetime($ownerRow['last_login_at']) ?: 'never') ?>.</span></p>
      <?php else: ?>
        <p class="mb-3 text-warning-emphasis"><i class="bi bi-exclamation-triangle"></i> This company has no Company Owner yet.</p>
      <?php endif; ?>
      <?php if ($db): ?>
      <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="a" value="owner">
        <div class="col-12"><div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="owner_mode" id="omExisting" value="existing" checked><label class="form-check-label" for="omExisting">One of its users</label></div>
          <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="owner_mode" id="omNew" value="new"><label class="form-check-label" for="omNew">A new account</label></div></div>
        <div class="col-md-8" data-owner-mode="existing"><select class="form-select form-select-sm" name="owner_user_id"><?= options($ownerChoices, (int)$c['owner_user_id']) ?></select></div>
        <div class="col-12" data-owner-mode="new" hidden><div class="row g-2">
          <?= input_field('owner_username', 'Username', '', ['class' => 'col-md-3', 'extra' => 'autocomplete="off"']) ?>
          <?= input_field('owner_full_name', 'Full name', '', ['class' => 'col-md-3']) ?>
          <?= input_field('owner_email', 'E-mail', '', ['class' => 'col-md-3', 'type' => 'email']) ?>
          <div class="col-md-3"><label class="form-label">Temporary password</label><input class="form-control form-control-sm" type="password" name="owner_password" autocomplete="new-password"></div>
        </div></div>
        <div class="col-md-4"><button class="btn btn-sm btn-outline-primary w-100" data-confirm="Make this person the Company Owner?">Set owner</button></div>
        <div class="col-12 small text-muted">The Company Owner manages the company's users, join requests and Company Code, and has full access inside the company. A new owner account is given the company's full-access role.</div>
      </form>
      <?php endif; ?>
    </div></div>

    <div class="card mb-3"><div class="card-header d-flex align-items-center justify-content-between"><span class="fw-semibold">Users (<?= count($users) ?>)</span>
      <?php if ($db): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="sync"><button class="btn btn-sm btn-outline-secondary" title="Rebuild the platform's list of this company's usernames from its database"><i class="bi bi-arrow-repeat"></i> Sync user list</button></form><?php endif; ?></div>
      <div class="table-responsive"><table class="table table-sm mb-0">
        <thead><tr><th>Name</th><th>Username</th><th>Roles</th><th>Status</th><th>Account made</th><th>Last log-in</th></tr></thead>
        <?php foreach ($users as $u):
            $st = in_array($u['approval_status'], ['pending', 'rejected'], true) ? $u['approval_status'] : $u['status']; ?>
          <tr><td><?= h($u['full_name']) ?><?= (int)$u['id'] === (int)$c['owner_user_id'] ? '<div class="small text-muted">Company Owner</div>' : '' ?></td>
            <td><?= h($u['username']) ?><div class="small text-muted"><?= h($u['email']) ?></div></td>
            <td class="small"><?= h($u['role_names'] ?: '') ?></td>
            <td><span class="user-state us-<?= h($st) ?>"><?= h(['pending' => 'Waiting for approval', 'rejected' => 'Rejected'][$st] ?? ucfirst($st)) ?></span></td>
            <td class="small"><?= h($viaText[$u['created_via']] ?? 'Administrator') ?>, <?= h(fdate($u['created_at'])) ?></td>
            <td class="small text-nowrap"><?= h(fdatetime($u['last_login_at'])) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$users): ?><tr><td colspan="6" class="text-muted py-3"><?= $db ? 'No users yet.' : 'The users are listed once the company is set up.' ?></td></tr><?php endif; ?>
      </table></div>
    </div>
  </div>

  <div class="col-xl-5">
    <div class="card mb-3"><div class="card-header fw-semibold">Company Code</div><div class="card-body">
      <div class="code-big mb-1"><?= h(CompanyCode::display($c['code'])) ?></div>
      <div class="small text-muted mb-3"><?= ($c['code_status'] ?? '') === 'active' ? 'Joining is on' : 'Joining is off' ?><?= $c['code_expires_at'] ? ', valid until ' . h(fdate($c['code_expires_at'])) : '' ?>. It works only while the company is active.</div>
      <form method="post" class="mb-3"><?= csrf_field() ?><input type="hidden" name="a" value="regenerate">
        <button class="btn btn-sm btn-warning" data-confirm="Make a new code? The current one stops working at once; people who already joined stay."><i class="bi bi-arrow-repeat"></i> Regenerate</button></form>
      <form method="post" class="row g-2 mb-3"><?= csrf_field() ?><input type="hidden" name="a" value="code_state">
        <div class="col-12"><div class="form-check form-switch"><input type="hidden" name="joining" value="0"><input class="form-check-input" type="checkbox" role="switch" id="pcJoin" name="joining" value="1" <?= ($c['code_status'] ?? '') === 'active' ? 'checked' : '' ?>><label class="form-check-label" for="pcJoin">Employees can join with the code</label></div></div>
        <div class="col-7"><input class="form-control form-control-sm" type="date" name="expires_at" value="<?= h($c['code_expires_at'] ? substr((string)$c['code_expires_at'], 0, 10) : '') ?>" aria-label="Valid until"></div>
        <div class="col-5"><button class="btn btn-sm btn-outline-primary w-100">Save</button></div>
      </form>
      <?php if ($db): ?>
      <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="a" value="join_rules">
        <div class="col-12"><div class="form-check"><input type="hidden" name="require_approval" value="0"><input class="form-check-input" type="checkbox" id="pcAppr" name="require_approval" value="1" <?= (int)$c['require_approval'] ? 'checked' : '' ?>><label class="form-check-label" for="pcAppr">New employees wait for the owner's approval</label></div></div>
        <div class="col-7"><select class="form-select form-select-sm" name="join_role_id" aria-label="Role to start with"><?= options($roles, (int)($c['join_role_id'] ?? 0), 'Role chosen when approving') ?></select></div>
        <div class="col-5"><button class="btn btn-sm btn-outline-primary w-100">Save</button></div>
      </form>
      <?php endif; ?>
    </div></div>

    <div class="card mb-3"><div class="card-header fw-semibold">Database</div><div class="card-body small">
      <dl class="row mb-2 company-facts">
        <dt class="col-5">Database</dt><dd class="col-7"><code><?= h($c['db_name'] ?: 'none yet') ?></code></dd>
        <dt class="col-5">Origin</dt><dd class="col-7"><?= (int)$c['is_primary'] ? 'The original database (Company 1)' : ((int)$c['created_by_platform'] ? 'Created by the platform' : '') ?></dd>
        <dt class="col-5">Tables</dt><dd class="col-7"><?= $c['table_count'] !== null ? (int)$c['table_count'] : '' ?></dd>
        <dt class="col-5">Checked</dt><dd class="col-7"><?= h(fdatetime($c['verified_at'])) ?></dd>
        <dt class="col-5">Program updates</dt><dd class="col-7"><?= $db ? ($pendingFiles ? count($pendingFiles) . ' to run' : 'Up to date') : '' ?></dd>
        <dt class="col-5">Audit trail protection</dt><dd class="col-7"><?= $triggersNow === null ? '' : ($triggersNow ? 'In place' : '<span class="text-danger">Missing</span>') ?></dd>
        <dt class="col-5">Files</dt><dd class="col-7"><code><?= h($storageDir) ?></code></dd>
      </dl>
      <?php if ($db && !$triggersNow): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="triggers"><button class="btn btn-sm btn-outline-secondary">Put the protection back</button></form>
      <?php endif; ?>
      <p class="text-muted mb-0 mt-2">Company users never see or choose a database: the platform opens this one for them after they log in.</p>
    </div></div>

    <?php if ($ready): ?>
    <div class="card mb-3"><div class="card-header fw-semibold"><?= $c['status'] === 'active' ? 'Deactivate' : 'Activate' ?></div><div class="card-body">
      <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="a" value="status"><input type="hidden" name="to" value="<?= $c['status'] === 'active' ? 'inactive' : 'active' ?>">
        <?php if ($c['status'] === 'active'): ?>
          <div class="col-12 small text-muted">Its users are logged out and cannot log in, and nobody can join. Its database, users and records are all kept, and the Super Admin can still open it. It can be switched on again at any time.</div>
          <div class="col-8"><input class="form-control form-control-sm" name="reason" placeholder="Reason" required></div>
          <div class="col-4"><button class="btn btn-sm btn-outline-danger w-100" data-confirm="Deactivate <?= h($c['name']) ?>?">Deactivate</button></div>
        <?php else: ?>
          <div class="col-12 small text-muted">Its users can log in again with their accounts as they were.</div>
          <div class="col-8"><input class="form-control form-control-sm" name="reason" placeholder="Reason (optional)"></div>
          <div class="col-4"><button class="btn btn-sm btn-success w-100">Activate</button></div>
        <?php endif; ?>
      </form>
    </div></div>
    <?php endif; ?>

    <div class="card mb-3"><div class="card-header fw-semibold">Set-up steps</div>
      <div class="table-responsive"><table class="table table-sm mb-0 small">
        <?php $lastAttempt = null; foreach ($log as $l): ?>
          <?php if ($l['attempt'] !== $lastAttempt): $lastAttempt = $l['attempt']; ?>
            <tr class="table-light"><td colspan="2" class="fw-semibold">Attempt <?= (int)$l['attempt'] ?>, <?= h(fdatetime($l['created_at'])) ?></td></tr>
          <?php endif; ?>
          <tr><td class="text-nowrap"><i class="bi bi-<?= (int)$l['ok'] ? 'check-circle text-success' : 'x-circle text-danger' ?>"></i> <?= h(ucfirst(str_replace('_', ' ', $l['step']))) ?></td>
            <td class="text-break text-muted"><?= h($l['message']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$log): ?><tr><td class="text-muted py-2"><?= (int)$c['is_primary'] ? 'The original database was registered, not created.' : 'No steps recorded.' ?></td></tr><?php endif; ?>
      </table></div>
    </div>

    <div class="card"><div class="card-header fw-semibold">Recent platform activity</div>
      <div class="table-responsive"><table class="table table-sm mb-0 small">
        <?php foreach ($activity as $l): ?>
          <tr><td class="text-nowrap text-muted"><?= h(fdatetime($l['created_at'])) ?></td><td><?= h(str_replace('_', ' ', $l['action'])) ?><div class="text-muted"><?= h($l['actor_name'] ?: $l['actor_type']) ?></div></td><td class="text-break"><?= h($l['details']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$activity): ?><tr><td class="text-muted py-2">Nothing yet.</td></tr><?php endif; ?>
      </table></div>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('input[name="owner_mode"]').forEach(function (r) {
    r.addEventListener('change', function () {
      document.querySelectorAll('[data-owner-mode]').forEach(function (b) { b.hidden = b.dataset.ownerMode !== r.value || !r.checked; });
    });
  });
});
</script>
<?php
platform_end();
