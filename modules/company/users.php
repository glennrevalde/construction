<?php
declare(strict_types=1);

/**
 * Company > Employees & Users (and Join Requests): the Company Owner's user management.
 *
 * Everything here reads and writes this company's own database only (DB is switched to it by Tenant),
 * so a Company Owner can never see or change another company's users. The owner's own account and the
 * Super Admin's platform account are protected: company administrators cannot change them here.
 */

$pageKey = $PAGE['key'] ?? 'company.users';
$listKey = 'company.users';
$tab = (string)(g('tab') ?: ($PARAMS['tab'] ?? 'all'));
$tabs = ['all' => 'All users', 'pending' => 'Join requests', 'active' => 'Active', 'inactive' => 'Inactive', 'locked' => 'Locked', 'rejected' => 'Rejected'];
if (!isset($tabs[$tab])) {
    $tab = 'all';
}
$roles = DB::pairs('SELECT id, name FROM roles ORDER BY name');
$adminRoles = array_map('intval', DB::column('SELECT id FROM roles WHERE is_admin = 1'));
$company = Tenant::company();
$authority = Tenant::hasAuthority();

/** May the current user change this user here? */
function company_user_editable(array $u): bool
{
    if ((int)$u['is_platform'] === 1) {
        return false;
    }
    if (Tenant::isOwner((int)$u['id']) && !Tenant::hasAuthority()) {
        return false;                        // only the owner (or the Super Admin) touches the owner's account
    }
    return true;
}

/** How the user came to be, in words */
function company_user_origin(string $via): string
{
    return ['registration' => 'Joined with the Company Code', 'provisioning' => 'Created with the company', 'setup' => 'Company set-up',
        'platform' => 'Created by the Super Admin', 'admin' => 'Created by an administrator'][$via] ?? 'Created by an administrator';
}

// ------------------------------------------------------------------ actions
if (is_post()) {
    $a = (string)p('a');
    $u = DB::row('SELECT * FROM users WHERE id = :id', ['id' => (int)p('id')]);
    try {
        if (!$u || !company_user_editable($u)) {
            fail('That user cannot be changed here.');
        }
        $isSelf = (int)$u['id'] === Auth::id();
        // a user of another company working here: their password is kept there, and switching them off ends the access
        if (CompanyAccess::isLinked($u) && in_array($a, ['reset', 'unlock', 'deactivate', 'activate', 'approve', 'reject'], true)) {
            $link = CompanyAccess::linkOf((int)$u['id']);
            Access::require($pageKey, 'edit');
            if (!$link) {
                fail('The access record of this user was not found.');
            }
            if ($a === 'deactivate' && !$isSelf) {
                CompanyAccess::remove((int)$link['id'], (string)p('reason'));
                flash('success', $u['full_name'] . ' can no longer switch to this company.');
            } elseif ($a === 'activate') {
                CompanyAccess::restore((int)$link['id']);
                flash('success', $u['full_name'] . ' can switch to this company again.');
            } else {
                fail($u['full_name'] . ' is a user of ' . $link['home_name'] . ' and logs in there.');
            }
            redirect(url($pageKey, array_filter(['tab' => $pageKey === 'company.join_requests' ? null : $tab, 'q' => g('q'), 'role' => g('role')])));
        }
        $roleIds = array_values(array_intersect(array_map('intval', (array)($_POST['roles'] ?? [])), array_map('intval', array_keys($roles))));
        $roleNames = fn(array $ids) => implode(', ', array_map(fn($i) => $roles[$i] ?? '', $ids));
        $oldRoles = array_map('intval', DB::column('SELECT role_id FROM user_roles WHERE user_id = :u', ['u' => $u['id']]));
        $setRoles = function (array $ids) use ($u) {
            DB::run('DELETE FROM user_roles WHERE user_id = :u', ['u' => $u['id']]);
            foreach ($ids as $r) {
                DB::insert('user_roles', ['user_id' => $u['id'], 'role_id' => $r]);
            }
        };

        if ($a === 'approve') {
            Access::require($pageKey, 'approve');
            if ($u['approval_status'] === 'approved') {
                fail($u['username'] . ' is already approved.');
            }
            if (!$roleIds) {
                fail('Give ' . $u['full_name'] . ' at least one role before approving.');
            }
            DB::tx(function () use ($u, $roleIds, $setRoles, $roleNames, $listKey) {
                DB::update('users', ['approval_status' => 'approved', 'status' => 'active', 'approved_by' => Auth::id(),
                    'approved_at' => date('Y-m-d H:i:s'), 'rejected_reason' => null, 'failed_attempts' => 0], 'id = :id', ['id' => $u['id']]);
                $setRoles($roleIds);
                Audit::log('user_approved', 'admin', 'user', (int)$u['id'], $u['username'], ['page_key' => $listKey,
                    'reason' => 'Registration approved; roles: ' . $roleNames($roleIds),
                    'changes' => [['field' => 'approval_status', 'old' => $u['approval_status'], 'new' => 'approved'],
                        ['field' => 'roles', 'old' => '', 'new' => $roleNames($roleIds)]]]);
            });
            Membership::refresh((int)$u['id']);
            Master::log('employee_approved', Tenant::id(), $u['username'] . ' approved');
            flash('success', $u['full_name'] . ' is approved and can log in now.');
        } elseif ($a === 'reject') {
            Access::require($pageKey, 'approve');
            if ($u['approval_status'] !== 'pending') {
                fail('Only a registration waiting for approval can be rejected.');
            }
            $reason = mb_substr(trim((string)p('reason')), 0, 255);
            if ($reason === '') {
                fail('Say why the registration is rejected.');
            }
            DB::update('users', ['approval_status' => 'rejected', 'status' => 'inactive', 'approved_by' => Auth::id(),
                'approved_at' => date('Y-m-d H:i:s'), 'rejected_reason' => $reason], 'id = :id', ['id' => $u['id']]);
            Audit::log('user_rejected', 'admin', 'user', (int)$u['id'], $u['username'], ['page_key' => $listKey, 'reason' => $reason,
                'changes' => [['field' => 'approval_status', 'old' => $u['approval_status'], 'new' => 'rejected']]]);
            Membership::refresh((int)$u['id']);
            Master::log('employee_rejected', Tenant::id(), $u['username'] . ' rejected: ' . $reason);
            flash('success', 'The registration of ' . $u['full_name'] . ' was rejected. They cannot log in.');
        } elseif ($a === 'roles') {
            Access::require($pageKey, 'edit');
            if (!$roleIds) {
                fail('Keep at least one role.');
            }
            if ($isSelf && array_intersect($oldRoles, $adminRoles) && !array_intersect($roleIds, $adminRoles) && !Tenant::hasAuthority()) {
                fail('You cannot remove the Administrator role from your own account.');
            }
            DB::tx(function () use ($u, $roleIds, $oldRoles, $setRoles, $roleNames, $listKey) {
                $setRoles($roleIds);
                Audit::log('user_edit', 'admin', 'user', (int)$u['id'], $u['username'], ['page_key' => $listKey,
                    'changes' => [['field' => 'roles', 'old' => $roleNames($oldRoles), 'new' => $roleNames($roleIds)]]]);
            });
            flash('success', 'Roles of ' . $u['full_name'] . ' saved: ' . $roleNames($roleIds) . '.');
        } elseif ($a === 'deactivate') {
            Access::require($pageKey, 'edit');
            if ($isSelf) {
                fail('You cannot deactivate your own account.');
            }
            if (Tenant::isOwner((int)$u['id'])) {
                fail('The Company Owner cannot be deactivated. The Super Admin can make someone else the owner first.');
            }
            DB::update('users', ['status' => 'inactive'], 'id = :id', ['id' => $u['id']]);
            Audit::log('user_deactivated', 'admin', 'user', (int)$u['id'], $u['username'], ['page_key' => $listKey, 'reason' => p('reason') ?: null,
                'changes' => [['field' => 'status', 'old' => $u['status'], 'new' => 'inactive']]]);
            Membership::refresh((int)$u['id']);
            flash('success', $u['full_name'] . ' was deactivated and can no longer log in. Their name stays on everything they entered.');
        } elseif ($a === 'activate' || $a === 'unlock') {
            Access::require($pageKey, 'edit');
            if ($u['approval_status'] !== 'approved') {
                fail('Approve the registration first.');
            }
            DB::update('users', ['status' => 'active', 'failed_attempts' => 0], 'id = :id', ['id' => $u['id']]);
            Audit::log($a === 'unlock' ? 'account_unlocked' : 'user_activated', $a === 'unlock' ? 'security' : 'admin', 'user', (int)$u['id'], $u['username'],
                ['page_key' => $listKey, 'changes' => [['field' => 'status', 'old' => $u['status'], 'new' => 'active']]]);
            Membership::refresh((int)$u['id']);
            flash('success', $u['full_name'] . ($a === 'unlock' ? ' was unlocked.' : ' was activated and can log in again.'));
        } elseif ($a === 'reset') {
            Access::require($pageKey, 'edit');
            $pw = (string)($_POST['temp_password'] ?? '');
            if ($problems = Auth::passwordProblems($pw, $u['username'])) {
                fail('The temporary password must have ' . implode(', ', $problems) . '.');
            }
            DB::update('users', ['password_hash' => Auth::hash($pw), 'must_change_password' => 1, 'failed_attempts' => 0,
                'status' => $u['status'] === 'locked' ? 'active' : $u['status'], 'password_changed_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $u['id']]);
            Audit::log('password_reset', 'security', 'user', (int)$u['id'], $u['username'], ['page_key' => $listKey, 'reason' => 'Temporary password set by ' . (Tenant::isOwner() ? 'the Company Owner' : 'an administrator')]);
            Membership::refresh((int)$u['id']);
            flash('success', 'Temporary password set. ' . $u['full_name'] . ' must change it at the next log-in.');
        } else {
            fail('Unknown action.');
        }
        Access::reset();
    } catch (UserError $e) {
        flash('danger', $e->getMessage());
    }
    redirect(url($pageKey, array_filter(['tab' => $pageKey === 'company.join_requests' ? null : $tab, 'q' => g('q'), 'role' => g('role')])));
}

// ------------------------------------------------------------------ the list
$q = trim((string)g('q'));
$roleFilter = gint('role');
$counts = ['all' => 0, 'pending' => 0, 'active' => 0, 'inactive' => 0, 'locked' => 0, 'rejected' => 0];
foreach (DB::all("SELECT CASE WHEN approval_status IN ('pending','rejected') THEN approval_status ELSE status END AS k, COUNT(*) AS n
                    FROM users WHERE is_platform = 0 GROUP BY k") as $r) {
    $counts[$r['k']] = (int)$r['n'];
    $counts['all'] += (int)$r['n'];
}
$where = ['u.is_platform = 0'];
$params = [];
if ($tab === 'pending' || $tab === 'rejected') {
    $where[] = 'u.approval_status = :tab';
    $params['tab'] = $tab;
} elseif ($tab !== 'all') {
    $where[] = "u.approval_status = 'approved' AND u.status = :tab";
    $params['tab'] = $tab;
}
if ($q !== '') {
    $where[] = '(u.full_name LIKE :q OR u.username LIKE :q2 OR u.email LIKE :q3 OR u.employee_no LIKE :q4)';
    $params += ['q' => "%$q%", 'q2' => "%$q%", 'q3' => "%$q%", 'q4' => "%$q%"];
}
if ($roleFilter) {
    $where[] = 'EXISTS (SELECT 1 FROM user_roles x WHERE x.user_id = u.id AND x.role_id = :role)';
    $params['role'] = $roleFilter;
}
$users = DB::all(
    "SELECT u.*, GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR ', ') AS role_names, GROUP_CONCAT(DISTINCT r.id) AS role_ids,
            ab.full_name AS approver
       FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id
       LEFT JOIN users ab ON ab.id = u.approved_by
      WHERE " . implode(' AND ', $where) . "
      GROUP BY u.id ORDER BY (u.approval_status = 'pending') DESC, u.full_name",
    $params
);
$canApprove = Access::can($pageKey, 'approve');
$canEdit = Access::can($pageKey, 'edit');
$joinRole = (int)($company['join_role_id'] ?? 0);

$linked = CompanyAccess::linksHere();
$alsoIn = CompanyAccess::linksOut();
$actions = page_link('admin.users', ['a' => 'new'], 'New user', 'btn-primary', 'person-plus', 'create')
    . (Tenant::isMulti() ? ' ' . page_link('admin.users', ['a' => 'link'], 'Add user from another company', 'btn-outline-primary', 'buildings', 'create') : '')
    . ' ' . page_link('admin.roles', [], 'Roles', 'btn-outline-secondary', 'diagram-3')
    . (Tenant::isMulti() ? ' ' . page_link('company.profile', [], 'Company Code', 'btn-outline-secondary', 'ticket-perforated') : '');
layout_start($pageKey === 'company.join_requests' ? 'Join Requests' : 'Employees & Users', ['actions' => $actions]);
?>
<?php if (Tenant::isMulti() && $counts['pending'] && $tab !== 'pending'): ?>
  <div class="alert alert-info d-flex align-items-center gap-2 py-2"><i class="bi bi-hourglass-split"></i>
    <div><?= $counts['pending'] ?> employee<?= $counts['pending'] === 1 ? ' is' : 's are' ?> waiting for approval.
    <a href="<?= h(url($listKey, ['tab' => 'pending'])) ?>">Review the join requests</a></div></div>
<?php endif; ?>

<ul class="nav nav-tabs mb-0">
  <?php foreach ($tabs as $k => $label): ?>
    <li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active' : '' ?>" href="<?= h(url($listKey, ['tab' => $k, 'q' => $q ?: null, 'role' => $roleFilter])) ?>"><?= h($label) ?> <span class="tab-count"><?= (int)$counts[$k] ?></span></a></li>
  <?php endforeach; ?>
</ul>
<form method="get" class="row g-2 my-2 filters"><input type="hidden" name="p" value="<?= h($listKey) ?>"><input type="hidden" name="tab" value="<?= h($tab) ?>">
  <div class="col-md-5"><label class="form-label">Search</label><input class="form-control form-control-sm" type="search" name="q" value="<?= h($q) ?>" placeholder="Name, username, e-mail or employee no."></div>
  <?= select_field('role', 'Role', $roles, $roleFilter, ['blank' => 'All roles', 'class' => 'col-md-3', 'extra' => 'data-autosubmit']) ?>
  <div class="col-md-2 d-flex align-items-end"><button class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-search"></i> Search</button></div>
</form>

<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0 company-users">
  <thead><tr><th>Employee</th><th>Username</th><th>Roles</th><th>Status</th><th>Account made</th><th>Last log-in</th><th class="text-end">Actions</th></tr></thead>
  <tbody>
  <?php foreach ($users as $u):
      $isOwner = Tenant::isOwner((int)$u['id']);
      $state = in_array($u['approval_status'], ['pending', 'rejected'], true) ? $u['approval_status'] : $u['status'];
      $stateText = ['pending' => 'Waiting for approval', 'rejected' => 'Rejected', 'active' => 'Active', 'inactive' => 'Inactive', 'locked' => 'Locked'][$state] ?? $state;
      $editable = company_user_editable($u);
      $rolesOf = $u['role_ids'] ? array_map('intval', explode(',', (string)$u['role_ids'])) : [];
  ?>
    <tr>
      <td><div class="fw-semibold"><?= h($u['full_name']) ?></div>
        <div class="small text-muted"><?= $isOwner ? 'Company Owner' : '' ?><?= $isOwner && $u['employee_no'] ? ', ' : '' ?><?= $u['employee_no'] ? 'No. ' . h($u['employee_no']) : '' ?></div>
        <?php if (isset($alsoIn[(int)$u['id']])): ?><div class="user-from">Also works in <?= h(implode(', ', $alsoIn[(int)$u['id']])) ?></div><?php endif; ?></td>
      <td><?= h($u['username']) ?><div class="small text-muted text-break"><?= h($u['email']) ?></div></td>
      <td class="small"><?= h($u['role_names'] ?: ($state === 'pending' ? 'None yet' : 'None')) ?></td>
      <td><span class="user-state us-<?= h($state) ?>"><?= h($stateText) ?></span>
        <?php if ($state === 'rejected' && $u['rejected_reason']): ?><div class="small text-muted"><?= h($u['rejected_reason']) ?></div><?php endif; ?>
        <?php if ($u['approved_at'] && $u['approver'] && $u['created_via'] === 'registration'): ?><div class="small text-muted"><?= $state === 'rejected' ? 'By' : 'Approved by' ?> <?= h($u['approver']) ?></div><?php endif; ?></td>
      <td class="small"><?= isset($linked[(int)$u['id']]) ? 'User of ' . h($linked[(int)$u['id']]['home_name']) : h(company_user_origin((string)$u['created_via'])) ?><div class="text-muted"><?= h(fdate($u['created_at'])) ?></div></td>
      <td class="small"><?= $u['last_login_at'] ? h(fdate($u['last_login_at'])) . '<div class="text-muted">' . h(date('h:i A', strtotime((string)$u['last_login_at']))) . '</div>' : '<span class="text-muted">Never</span>' ?></td>
      <td class="text-end text-nowrap">
        <?php if ($editable): ?>
          <?php if ($state === 'pending' || $state === 'rejected'): ?>
            <?php if ($canApprove): ?>
              <button type="button" class="btn btn-sm btn-success" data-user-action="approve" data-id="<?= (int)$u['id'] ?>" data-name="<?= h($u['full_name']) ?>" data-roles="<?= h(implode(',', $rolesOf ?: ($joinRole ? [$joinRole] : []))) ?>"><i class="bi bi-person-check"></i> Approve</button>
              <?php if ($state === 'pending'): ?>
              <button type="button" class="btn btn-sm btn-outline-danger" data-user-action="reject" data-id="<?= (int)$u['id'] ?>" data-name="<?= h($u['full_name']) ?>"><i class="bi bi-person-x"></i> Reject</button>
              <?php endif; ?>
            <?php endif; ?>
          <?php elseif ($canEdit): ?>
            <div class="dropdown d-inline-block">
              <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">Manage</button>
              <ul class="dropdown-menu dropdown-menu-end">
                <li><button type="button" class="dropdown-item" data-user-action="roles" data-id="<?= (int)$u['id'] ?>" data-name="<?= h($u['full_name']) ?>" data-roles="<?= h(implode(',', $rolesOf)) ?>"><i class="bi bi-diagram-3"></i> Change roles</button></li>
                <?php if (!isset($linked[(int)$u['id']])): ?>
                <li><button type="button" class="dropdown-item" data-user-action="reset" data-id="<?= (int)$u['id'] ?>" data-name="<?= h($u['full_name']) ?>"><i class="bi bi-key"></i> Reset password</button></li>
                <?php endif; ?>
                <?php if ($state === 'locked'): ?>
                  <li><form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="unlock"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="dropdown-item"><i class="bi bi-unlock"></i> Unlock</button></form></li>
                <?php endif; ?>
                <?php if ($state === 'inactive'): ?>
                  <li><form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="activate"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="dropdown-item"><i class="bi bi-person-check"></i> <?= isset($linked[(int)$u['id']]) ? 'Give access back' : 'Activate' ?></button></form></li>
                <?php elseif (!$isOwner && (int)$u['id'] !== Auth::id()): ?>
                  <li><button type="button" class="dropdown-item text-danger" data-user-action="deactivate" data-id="<?= (int)$u['id'] ?>" data-name="<?= h($u['full_name']) ?>"><i class="bi bi-person-dash"></i> <?= isset($linked[(int)$u['id']]) ? 'Remove access to this company' : 'Deactivate' ?></button></li>
                <?php endif; ?>
                <?php if (Access::can('admin.users', 'edit')): ?>
                  <li><hr class="dropdown-divider"></li>
                  <li><a class="dropdown-item" href="<?= h(url('admin.users', ['a' => 'edit', 'id' => $u['id']])) ?>"><i class="bi bi-pencil"></i> All details, sites and POS terminals</a></li>
                <?php endif; ?>
              </ul>
            </div>
          <?php endif; ?>
        <?php elseif ($isOwner): ?>
          <span class="small text-muted">Company Owner</span>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$users): ?>
    <tr><td colspan="7" class="text-muted py-3"><?= $tab === 'pending' ? 'No employee is waiting for approval.' : 'No user matches.' ?></td></tr>
  <?php endif; ?>
  </tbody>
</table></div></div>
<?php if (Tenant::isMulti()): ?>
<p class="small text-muted mt-2">Employees join with the Company Code on the join page<?= (int)($company['require_approval'] ?? 1) ? ' and wait here until they are approved' : ' and can log in straight away' ?>.
  Users are never deleted, so their names stay on every record and audit entry they made.</p>
<?php endif; ?>

<!-- one form for every action that needs more than a click -->
<div class="modal fade" id="userActionModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="a" id="uaAction"><input type="hidden" name="id" id="uaId">
  <div class="modal-header"><h5 class="modal-title" id="uaTitle"></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div data-ua-part="roles approve">
      <div class="fw-semibold mb-1">Roles</div>
      <div class="ua-roles">
        <?php foreach ($roles as $rid => $rn): ?>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="roles[]" value="<?= (int)$rid ?>" id="uar<?= (int)$rid ?>">
            <label class="form-check-label" for="uar<?= (int)$rid ?>"><?= h($rn) ?><?= in_array((int)$rid, $adminRoles, true) ? ' <span class="small text-muted">(full access)</span>' : '' ?></label></div>
        <?php endforeach; ?>
      </div>
      <div class="small text-muted mt-1" data-ua-part="approve">Approving lets them log in with the password they chose. Sites and POS terminals can be set afterwards in User Accounts.</div>
    </div>
    <div data-ua-part="reject deactivate"><label class="form-label">Reason (kept in the audit trail)</label><textarea class="form-control" name="reason" rows="2"></textarea></div>
    <div data-ua-part="reset"><label class="form-label">Temporary password</label><input class="form-control" type="password" name="temp_password" autocomplete="new-password">
      <div class="small text-muted mt-1">They must replace it at the next log-in.</div></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" id="uaSubmit">Save</button></div>
</form></div></div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var modalEl = document.getElementById('userActionModal');
  if (!modalEl || !window.bootstrap) { return; }
  var modal = new bootstrap.Modal(modalEl);
  var titles = { approve: 'Approve ', reject: 'Reject the registration of ', roles: 'Roles of ', reset: 'Reset the password of ', deactivate: 'Deactivate ' };
  var buttons = { approve: ['Approve', 'btn-success'], reject: ['Reject', 'btn-danger'], roles: ['Save roles', 'btn-primary'], reset: ['Set temporary password', 'btn-primary'], deactivate: ['Deactivate', 'btn-danger'] };
  document.querySelectorAll('[data-user-action]').forEach(function (b) {
    b.addEventListener('click', function () {
      var act = b.dataset.userAction;
      document.getElementById('uaAction').value = act;
      document.getElementById('uaId').value = b.dataset.id;
      document.getElementById('uaTitle').textContent = titles[act] + b.dataset.name;
      var sub = document.getElementById('uaSubmit');
      sub.textContent = buttons[act][0];
      sub.className = 'btn ' + buttons[act][1];
      modalEl.querySelectorAll('[data-ua-part]').forEach(function (p) {
        var on = p.dataset.uaPart.split(' ').indexOf(act) !== -1;
        p.hidden = !on;
        p.querySelectorAll('input, textarea').forEach(function (i) { i.disabled = !on; if (i.type !== 'checkbox') { i.value = ''; } });
      });
      var pick = (b.dataset.roles || '').split(',').filter(Boolean);
      modalEl.querySelectorAll('input[name="roles[]"]').forEach(function (c) { c.checked = pick.indexOf(c.value) !== -1; });
      modalEl.querySelector('textarea[name="reason"]').required = (act === 'reject');
      modalEl.querySelector('input[name="temp_password"]').required = (act === 'reset');
      modal.show();
    });
  });
});
</script>
<?php
layout_end();
