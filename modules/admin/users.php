<?php
declare(strict_types=1);

$pageKey = 'admin.users';
$a = g('a');
$roles = DB::all('SELECT * FROM roles ORDER BY name');
$sites = DB::pairs('SELECT id, name FROM sites ORDER BY id');
$terminals = DB::pairs("SELECT id, CONCAT(code, ' - ', name) FROM pos_terminals ORDER BY code");

/** Page rights granted by a set of roles (for segregation-of-duties checks) */
function rights_for_roles(array $roleIds): array
{
    if (!$roleIds) {
        return [];
    }
    [$in, $p] = DB::in('r', $roleIds);
    if ((int)DB::value("SELECT COUNT(*) FROM roles WHERE is_admin = 1 AND id IN $in", $p) > 0) {
        return [];
    }
    $out = [];
    foreach (DB::all("SELECT pg.page_key, rpa.* FROM role_page_access rpa JOIN pages pg ON pg.id = rpa.page_id WHERE rpa.allowed = 1 AND rpa.role_id IN $in", $p) as $r) {
        $k = $r['page_key'];
        $out[$k]['allowed'] = true;
        foreach (['create', 'edit', 'delete', 'print', 'approve'] as $act) {
            $out[$k][$act] = !empty($out[$k][$act]) || (int)$r['can_' . $act] === 1;
        }
    }
    return $out;
}

function active_admin_count(?int $excludeUser = null): int
{
    return (int)DB::value(
        "SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
          WHERE r.is_admin = 1 AND u.status = 'active' AND u.id <> :x",
        ['x' => $excludeUser ?? 0]
    );
}

// ------------------------------------------------------------------ quick actions
if (in_array($a, ['unlock', 'deactivate', 'activate', 'reset'], true) && is_post()) {
    Access::require($pageKey, 'edit');
    $u = DB::row('SELECT * FROM users WHERE id = :id AND is_platform = 0', ['id' => (int)g('id')]);
    try {
        if (!$u) {
            fail('User not found.');
        }
        if (Tenant::isOwner((int)$u['id']) && !Tenant::hasAuthority()) {
            fail('Only the Company Owner can change the Company Owner\'s account.');
        }
        if ($a === 'deactivate' && Tenant::isOwner((int)$u['id'])) {
            fail('The Company Owner cannot be deactivated. The Super Admin can make someone else the owner first.');
        }
        if ($a === 'activate' && ($u['approval_status'] ?? 'approved') !== 'approved') {
            fail('This registration has not been approved. Approve it under Company > Join Requests.');
        }
        if (CompanyAccess::isLinked($u)) {
            // a user of another company: switching the account off or on here ends or gives back their access
            $link = CompanyAccess::linkOf((int)$u['id']);
            if (!$link) {
                fail('The access record of this user was not found.');
            }
            if ($a === 'deactivate') {
                if ((int)$u['id'] === Auth::id()) {
                    fail('You cannot remove your own access.');
                }
                if (Access::isAdmin((int)$u['id']) && active_admin_count((int)$u['id']) === 0) {
                    fail('This is the last active administrator.');
                }
                CompanyAccess::remove((int)$link['id'], (string)p('reason'));
                flash('success', $u['username'] . ' can no longer switch to this company. Their name stays on every record they made here.');
            } elseif ($a === 'activate') {
                CompanyAccess::restore((int)$link['id']);
                flash('success', $u['username'] . ' can switch to this company again.');
            } else {
                fail($u['username'] . ' logs in at ' . $link['home_name'] . '; their password is changed there.');
            }
            redirect(url($pageKey, ['a' => 'edit', 'id' => $u['id']]));
        }
        if ($a === 'unlock') {
            DB::update('users', ['status' => 'active', 'failed_attempts' => 0], 'id = :id', ['id' => $u['id']]);
            Audit::log('account_unlocked', 'security', 'user', (int)$u['id'], $u['username'], ['reason' => p('reason') ?: null, 'page_key' => $pageKey]);
            flash('success', $u['username'] . ' unlocked.');
        } elseif ($a === 'deactivate') {
            if ((int)$u['id'] === Auth::id()) {
                fail('You cannot deactivate your own account.');
            }
            if (Access::isAdmin((int)$u['id']) && active_admin_count((int)$u['id']) === 0) {
                fail('This is the last active administrator.');
            }
            DB::update('users', ['status' => 'inactive'], 'id = :id', ['id' => $u['id']]);
            Audit::log('user_deactivated', 'admin', 'user', (int)$u['id'], $u['username'], ['reason' => p('reason') ?: null, 'page_key' => $pageKey,
                'changes' => [['field' => 'status', 'old' => $u['status'], 'new' => 'inactive']]]);
            flash('success', $u['username'] . ' deactivated. Their name stays on every record they created.');
        } elseif ($a === 'activate') {
            DB::update('users', ['status' => 'active', 'failed_attempts' => 0], 'id = :id', ['id' => $u['id']]);
            Audit::log('user_activated', 'admin', 'user', (int)$u['id'], $u['username'], ['page_key' => $pageKey,
                'changes' => [['field' => 'status', 'old' => $u['status'], 'new' => 'active']]]);
            flash('success', $u['username'] . ' activated.');
        } elseif ($a === 'reset') {
            $pw = (string)($_POST['temp_password'] ?? '');
            if ($problems = Auth::passwordProblems($pw, $u['username'])) {
                fail('The temporary password must have ' . implode(', ', $problems) . '.');
            }
            DB::update('users', ['password_hash' => Auth::hash($pw), 'must_change_password' => 1, 'failed_attempts' => 0,
                'status' => $u['status'] === 'locked' ? 'active' : $u['status'], 'password_changed_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $u['id']]);
            Audit::log('password_reset', 'security', 'user', (int)$u['id'], $u['username'], ['reason' => 'Temporary password set by administrator', 'page_key' => $pageKey]);
            flash('success', 'Temporary password set. ' . $u['username'] . ' must change it at the next login.');
        }
        Membership::refresh((int)$u['id']);
    } catch (UserError $e) {
        flash('danger', $e->getMessage());
    }
    redirect(url($pageKey, ['a' => 'edit', 'id' => g('id')]));
}

// ------------------------------------------------------------------ a user of this company: end their access to another company
if ($a === 'unlink' && is_post()) {
    Access::require($pageKey, 'edit');
    try {
        $l = CompanyAccess::remove((int)p('link_id'), (string)p('reason'));
        flash('success', $l['username'] . ' can no longer switch to that company.');
    } catch (UserError $e) {
        flash('danger', $e->getMessage());
    }
    redirect(url($pageKey, ['a' => 'edit', 'id' => g('id')]));
}

// ------------------------------------------------------------------ add a user of another company (same log-in, switches companies)
if ($a === 'link') {
    Access::require($pageKey, 'create');
    if (!Tenant::isMulti()) {
        flash('info', 'Users of another company can be added once there are several companies on the platform.');
        redirect(url($pageKey));
    }
    $sod = [];
    handle_post(function () use ($pageKey, &$sod) {
        $roleIds = array_map('intval', (array)($_POST['roles'] ?? []));
        $sod = Access::sodConflicts(rights_for_roles($roleIds));
        if ($sod && p('sod_ack') !== '1') {
            fail('These roles combine duties that should be kept apart. Review the warning below and tick the box to save anyway.');
        }
        $id = CompanyAccess::grant((string)p('username'), $roleIds, array_map('intval', (array)($_POST['sites'] ?? [])),
            array_map('intval', (array)($_POST['terminals'] ?? [])), trim((string)p('reason')));
        if ($sod) {
            Audit::log('sod_override', 'admin', 'user', $id, (string)p('username'), ['reason' => implode('; ', $sod), 'page_key' => $pageKey]);
        }
        Access::reset();
        flash('success', 'Done. ' . mb_strtolower(trim((string)p('username'))) . ' can now switch to this company from the company name at the top of the screen, with the same log-in.');
        redirect(url($pageKey, ['a' => 'edit', 'id' => $id]));
    });
    $selRoles = array_map('intval', (array)($_POST['roles'] ?? []));
    $selSites = array_map('intval', (array)($_POST['sites'] ?? []));
    $selTerms = array_map('intval', (array)($_POST['terminals'] ?? []));
    layout_start('Add a user from another company', ['actions' => btn_link(url($pageKey), 'Back')]);
    ?>
    <div class="row g-3">
    <div class="col-lg-8">
    <form method="post" class="card"><div class="card-body">
      <?= csrf_field() ?>
      <div class="row g-2">
        <?= input_field('username', 'Their username', fv('username'), ['class' => 'col-md-5', 'required' => true, 'extra' => 'autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="50"']) ?>
        <div class="col-md-7 small text-muted d-flex align-items-end pb-1">The username they log in with at their own company. They keep that one log-in and password.</div>
      </div>
      <hr>
      <div class="row g-3">
        <div class="col-md-5"><div class="fw-semibold mb-1">Roles in this company</div>
          <?php foreach ($roles as $r): ?>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="roles[]" value="<?= (int)$r['id'] ?>" id="r<?= (int)$r['id'] ?>" <?= in_array((int)$r['id'], $selRoles, true) ? 'checked' : '' ?>>
              <label class="form-check-label" for="r<?= (int)$r['id'] ?>"><?= h($r['name']) ?> <span class="small text-muted"><?= $r['is_admin'] ? '(full access)' : '' ?></span></label></div>
          <?php endforeach; ?>
        </div>
        <div class="col-md-4"><div class="fw-semibold mb-1">Inventory sites</div>
          <?php foreach ($sites as $sid => $sn): ?>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="sites[]" value="<?= (int)$sid ?>" id="s<?= (int)$sid ?>" <?= in_array((int)$sid, $selSites, true) ? 'checked' : '' ?>><label class="form-check-label" for="s<?= (int)$sid ?>"><?= h($sn) ?></label></div>
          <?php endforeach; ?>
          <div class="small text-muted mt-1">None ticked = all sites the roles allow.</div>
        </div>
        <div class="col-md-3"><div class="fw-semibold mb-1">POS terminals</div>
          <?php foreach ($terminals as $tid => $tn): ?>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="terminals[]" value="<?= (int)$tid ?>" id="t<?= (int)$tid ?>" <?= in_array((int)$tid, $selTerms, true) ? 'checked' : '' ?>><label class="form-check-label small" for="t<?= (int)$tid ?>"><?= h($tn) ?></label></div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="row g-2 mt-2"><?= input_field('reason', 'Reason (recorded in the audit trail)', fv('reason'), ['class' => 'col-12', 'extra' => 'maxlength="200" placeholder="e.g. Accountant of the group, keeps the books of both companies"']) ?></div>
      <?php if ($sod): ?>
        <div class="alert alert-warning mt-3"><strong>Segregation of duties.</strong> These roles let one person handle: <?= h(implode('; ', $sod)) ?>.
          <div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="sod_ack" value="1" id="sodAck"><label class="form-check-label" for="sodAck">I understand; save anyway (the decision is recorded in the audit trail)</label></div></div>
      <?php endif; ?>
      <div class="mt-3 d-flex flex-wrap gap-2"><button class="btn btn-primary btn-sm"><i class="bi bi-person-plus"></i> Give access to this company</button>
        <a class="btn btn-outline-secondary btn-sm" href="<?= h(url($pageKey)) ?>">Cancel</a></div>
    </div></form>
    </div>
    <div class="col-lg-4">
      <div class="card"><div class="card-body small">
        <div class="fw-semibold mb-2">How it works</div>
        <ul class="ps-3 mb-2">
          <li class="mb-1">The person stays a user of their own company and logs in there as always.</li>
          <li class="mb-1">The company name at the top of their screen becomes a switch: they move between their company and <?= h(Tenant::name()) ?> without logging in again.</li>
          <li class="mb-1">Here they can do only what the roles above allow. They are not a Super Admin and get no rights over the platform or other companies.</li>
          <li class="mb-1">What they do here is recorded in this company's audit trail under their username.</li>
        </ul>
        <div class="text-muted">To end it later, open the user and press <strong>Remove access to this company</strong>. Their own company can also end it, and it ends by itself when their account there is deactivated.</div>
      </div></div>
    </div>
    </div>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ new / edit
if ($a === 'new' || $a === 'edit') {
    $u = null;
    if ($a === 'edit') {
        Access::require($pageKey, 'edit');
        $u = DB::row('SELECT * FROM users WHERE id = :id AND is_platform = 0', ['id' => (int)g('id')]);
        if (!$u) {
            redirect(url($pageKey));
        }
        if (Tenant::isOwner((int)$u['id']) && !Tenant::hasAuthority()) {
            flash('warning', 'Only the Company Owner can change the Company Owner\'s account.');
            redirect(url($pageKey));
        }
    } else {
        Access::require($pageKey, 'create');
    }
    $userRoles = $u ? array_map('intval', DB::column('SELECT role_id FROM user_roles WHERE user_id = :u', ['u' => $u['id']])) : [];
    $userSites = $u ? array_map('intval', DB::column('SELECT site_id FROM user_sites WHERE user_id = :u', ['u' => $u['id']])) : [];
    $userTerms = $u ? array_map('intval', DB::column('SELECT terminal_id FROM user_terminals WHERE user_id = :u', ['u' => $u['id']])) : [];
    $sod = [];

    $link = $u && CompanyAccess::isLinked($u) ? CompanyAccess::linkOf((int)$u['id']) : null;
    handle_post(function () use ($u, $userRoles, $userSites, $userTerms, $pageKey, &$sod, $link) {
        $d = [
            // a user of another company keeps the username of their own company
            'username' => $link ? (string)$u['username'] : mb_strtolower(trim((string)p('username'))),
            'full_name' => mb_substr(trim((string)p('full_name')), 0, 120),
            'email' => p('email') ?: null,
            'employee_no' => p('employee_no') ?: null,
        ];
        if (!preg_match('/^[a-z0-9._-]{3,50}$/', $d['username'])) {
            fail('Usernames are 3–50 characters: letters, numbers, dot, dash or underscore.');
        }
        if ($d['full_name'] === '') {
            fail('Enter the full name.');
        }
        if (DB::value('SELECT id FROM users WHERE username = :u AND id <> :id', ['u' => $d['username'], 'id' => $u['id'] ?? 0])) {
            fail('That username is taken.');
        }
        // with several companies, usernames are unique across all of them (the log-in page finds the company by it)
        if (!$link && !Membership::usernameFree($d['username'], isset($u['id']) ? (int)$u['id'] : null)) {
            fail('That username is already used on this platform. Choose another.');
        }
        $roleIds = array_map('intval', (array)($_POST['roles'] ?? []));
        $siteIds = array_map('intval', (array)($_POST['sites'] ?? []));
        $termIds = array_map('intval', (array)($_POST['terminals'] ?? []));
        if (!$roleIds) {
            fail('Assign at least one role.');
        }
        if ($u && (int)$u['id'] === Auth::id() && Access::isAdmin() && !(int)DB::value('SELECT COUNT(*) FROM roles WHERE is_admin = 1 AND id IN ' . DB::in('rr', $roleIds)[0], DB::in('rr', $roleIds)[1])) {
            fail('You cannot remove the Administrator role from your own account.');
        }
        $sod = Access::sodConflicts(rights_for_roles($roleIds));
        if ($sod && p('sod_ack') !== '1') {
            fail('These roles combine duties that should be kept apart. Review the warning below and tick the box to save anyway.');
        }
        $pw = $link ? '' : (string)($_POST['password'] ?? '');
        if (!$u || $pw !== '') {
            if ($problems = Auth::passwordProblems($pw, $d['username'])) {
                fail('The ' . ($u ? 'new' : 'temporary') . ' password must have ' . implode(', ', $problems) . '.');
            }
            $d['password_hash'] = Auth::hash($pw);
            $d['must_change_password'] = 1;
            $d['password_changed_at'] = date('Y-m-d H:i:s');
        }
        $id = DB::tx(function () use ($u, $d, $roleIds, $siteIds, $termIds, $userRoles, $userSites, $userTerms, $pageKey, $sod) {
            if ($u) {
                DB::update('users', $d, 'id = :id', ['id' => $u['id']]);
                $id = (int)$u['id'];
            } else {
                $d['created_by'] = Auth::id();
                $d['status'] = 'active';
                $id = DB::insert('users', $d);
            }
            DB::run('DELETE FROM user_roles WHERE user_id = :u', ['u' => $id]);
            foreach ($roleIds as $r) {
                DB::insert('user_roles', ['user_id' => $id, 'role_id' => $r]);
            }
            DB::run('DELETE FROM user_sites WHERE user_id = :u', ['u' => $id]);
            foreach ($siteIds as $s) {
                DB::insert('user_sites', ['user_id' => $id, 'site_id' => $s]);
            }
            DB::run('DELETE FROM user_terminals WHERE user_id = :u', ['u' => $id]);
            foreach ($termIds as $t) {
                DB::insert('user_terminals', ['user_id' => $id, 'terminal_id' => $t]);
            }
            $roleName = fn(array $ids) => implode(', ', DB::column('SELECT name FROM roles WHERE id IN ' . DB::in('x', $ids ?: [0])[0] . ' ORDER BY name', DB::in('x', $ids ?: [0])[1]));
            $siteName = fn(array $ids) => implode(', ', DB::column('SELECT code FROM sites WHERE id IN ' . DB::in('x', $ids ?: [0])[0] . ' ORDER BY id', DB::in('x', $ids ?: [0])[1]));
            $before = $u ? array_intersect_key($u, $d) + ['roles' => $roleName($userRoles), 'sites' => $siteName($userSites), 'terminals' => implode(',', $userTerms)] : [];
            $after = array_diff_key($d, ['password_hash' => 1]) + ['roles' => $roleName($roleIds), 'sites' => $siteName($siteIds), 'terminals' => implode(',', $termIds)];
            if (isset($d['password_hash'])) {
                $after['password'] = $u ? 'reset by administrator' : 'temporary password set';
                $before['password'] = '';
            }
            Audit::log($u ? 'user_edit' : 'user_create', 'admin', 'user', $id, $d['username'], [
                'before' => $before, 'after' => $after, 'page_key' => $pageKey,
                'reason' => $sod ? 'Segregation of duties warning accepted: ' . implode('; ', $sod) : null,
            ]);
            if ($sod) {
                Audit::log('sod_override', 'admin', 'user', $id, $d['username'], ['reason' => implode('; ', $sod), 'page_key' => $pageKey]);
            }
            return $id;
        });
        Access::reset();
        Membership::refresh($id);
        flash('success', 'User saved.' . (isset($d['password_hash']) ? ' They must change the temporary password at first login.' : ''));
        redirect_after_save(url($pageKey, ['a' => 'new']), url($pageKey, ['a' => 'edit', 'id' => $id]));
    });

    $selRoles = is_post() ? array_map('intval', (array)($_POST['roles'] ?? [])) : $userRoles;
    $selSites = is_post() ? array_map('intval', (array)($_POST['sites'] ?? [])) : $userSites;
    $selTerms = is_post() ? array_map('intval', (array)($_POST['terminals'] ?? [])) : $userTerms;
    $s = Settings::all();
    layout_start($u ? 'User: ' . $u['username'] : 'New user', ['actions' => ($u && Access::page('admin.effective_access') ? btn_link(url('admin.effective_access', ['user_id' => $u['id']]), 'Effective access', 'btn-outline-primary', 'eye') . ' ' : '')
        . ($u && Access::page('rpt.user_activity') ? btn_link(url('rpt.user_activity', ['user_id' => $u['id']]), 'Activity', 'btn-outline-secondary', 'activity') . ' ' : '')
        . btn_link(url($pageKey), 'Back')]);
    ?>
    <div class="row g-3">
    <div class="col-lg-8">
    <form method="post" class="card"><div class="card-body">
      <?= csrf_field() ?>
      <div class="row g-2">
        <?= input_field('username', 'Username', fv('username', $u), ['class' => 'col-md-4', 'required' => true, 'extra' => 'autocomplete="off"' . ($link ? ' readonly' : '')]) ?>
        <?= input_field('full_name', 'Full name', fv('full_name', $u), ['class' => 'col-md-8', 'required' => true]) ?>
        <?= input_field('email', 'Email', fv('email', $u), ['class' => 'col-md-6', 'type' => 'email']) ?>
        <?= input_field('employee_no', 'Employee no.', fv('employee_no', $u), ['class' => 'col-md-3']) ?>
        <?php if ($link): ?>
        <div class="col-12 small text-muted">A user of <?= h($link['home_name']) ?>: they log in there with this username and their own password, then switch to this company.</div>
        <?php else: ?>
        <div class="col-md-3"><label class="form-label"><?= $u ? 'New password (optional)' : 'Temporary password' ?></label><input class="form-control form-control-sm" type="password" name="password" autocomplete="new-password" <?= $u ? '' : 'required' ?>></div>
        <?php endif; ?>
        <div class="col-12 small text-muted<?= $link ? ' d-none' : '' ?>">Password rules: at least <?= (int)$s['pw_min_length'] ?> characters<?= $s['pw_require_upper'] === '1' ? ', upper- and lower-case' : '' ?><?= $s['pw_require_number'] === '1' ? ', a number' : '' ?><?= $s['pw_require_symbol'] === '1' ? ', a symbol' : '' ?>. The user must replace it at first login.</div>
      </div>
      <hr>
      <div class="row g-3">
        <div class="col-md-5"><div class="fw-semibold mb-1">Roles</div>
          <?php foreach ($roles as $r): ?>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="roles[]" value="<?= (int)$r['id'] ?>" id="r<?= (int)$r['id'] ?>" <?= in_array((int)$r['id'], $selRoles, true) ? 'checked' : '' ?>>
              <label class="form-check-label" for="r<?= (int)$r['id'] ?>"><?= h($r['name']) ?> <span class="small text-muted"><?= $r['is_admin'] ? '(full access)' : '' ?></span></label></div>
          <?php endforeach; ?>
          <div class="small text-muted mt-1">Permissions from several roles add together.</div>
        </div>
        <div class="col-md-4"><div class="fw-semibold mb-1">Inventory sites the user may work at</div>
          <?php foreach ($sites as $sid => $sn): ?>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="sites[]" value="<?= (int)$sid ?>" id="s<?= (int)$sid ?>" <?= in_array((int)$sid, $selSites, true) ? 'checked' : '' ?>><label class="form-check-label" for="s<?= (int)$sid ?>"><?= h($sn) ?></label></div>
          <?php endforeach; ?>
          <div class="small text-muted mt-1">None ticked = all sites the roles allow.</div>
        </div>
        <div class="col-md-3"><div class="fw-semibold mb-1">POS terminals</div>
          <?php foreach ($terminals as $tid => $tn): ?>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="terminals[]" value="<?= (int)$tid ?>" id="t<?= (int)$tid ?>" <?= in_array((int)$tid, $selTerms, true) ? 'checked' : '' ?>><label class="form-check-label small" for="t<?= (int)$tid ?>"><?= h($tn) ?></label></div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php if ($sod): ?>
        <div class="alert alert-warning mt-3"><strong>Segregation of duties.</strong> These roles let one person handle: <?= h(implode('; ', $sod)) ?>.
          <div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="sod_ack" value="1" id="sodAck"><label class="form-check-label" for="sodAck">I understand; save anyway (the decision is recorded in the audit trail)</label></div></div>
      <?php endif; ?>
      <div class="mt-3"><?= save_buttons('Save user', url($pageKey), Access::can($pageKey, 'create')) ?></div>
    </div></form>
    </div>
    <?php if ($u): ?>
    <div class="col-lg-4">
      <?php if ($link): ?>
      <div class="card mb-3 link-card"><div class="card-body small">
        <div class="fw-semibold mb-1"><i class="bi bi-buildings"></i> User of another company</div>
        <div>Own company: <strong><?= h($link['home_name']) ?></strong></div>
        <div>Access here: <?= $link['status'] === 'active' ? 'given' : 'removed' ?><?= $link['status'] === 'active' && $link['granted_at'] ? ' on ' . h(fdate($link['granted_at'])) . ($link['granted_by'] ? ' by ' . h($link['granted_by']) : '') : '' ?>
          <?= $link['status'] !== 'active' && $link['removed_at'] ? ' on ' . h(fdate($link['removed_at'])) . ($link['removed_by'] ? ' by ' . h($link['removed_by']) : '') : '' ?></div>
        <?php if ($link['last_switch_at']): ?><div>Last switched here: <?= h(fdatetime($link['last_switch_at'])) ?></div><?php endif; ?>
        <div class="text-muted mt-1">They switch to this company from the company name at the top of their screen. Their password is changed at their own company.</div>
      </div></div>
      <?php elseif (Tenant::isMulti() && ($links = CompanyAccess::linksFrom((int)$u['id']))): ?>
      <div class="card mb-3 link-card"><div class="card-body small">
        <div class="fw-semibold mb-1"><i class="bi bi-buildings"></i> Also works in</div>
        <?php foreach ($links as $l): ?>
        <div class="link-row"><div><strong><?= h($l['company_name']) ?></strong><div class="text-muted"><?= $l['status'] === 'active' ? 'Since ' . h(fdate($l['granted_at'])) : 'Removed ' . h(fdate($l['removed_at'])) ?></div></div>
          <?php if ($l['status'] === 'active' && Access::can($pageKey, 'edit')): ?>
          <form method="post" action="<?= h(url($pageKey, ['a' => 'unlink', 'id' => $u['id']])) ?>"><?= csrf_field() ?><input type="hidden" name="link_id" value="<?= (int)$l['id'] ?>">
            <button class="btn btn-outline-danger btn-sm" data-confirm="End the access of <?= h($u['username']) ?> to <?= h($l['company_name']) ?>?">Remove</button></form>
          <?php endif; ?></div>
        <?php endforeach; ?>
        <div class="text-muted mt-1">Access to another company is given by that company, in its User Accounts.</div>
      </div></div>
      <?php endif; ?>
      <div class="card mb-3"><div class="card-body small">
        <div>Status: <?= status_badge($u['status']) ?></div>
        <div>Last login: <?= h(fdatetime($u['last_login_at'])) ?> <?= h($u['last_login_ip']) ?></div>
        <?php if (!$link): ?>
        <div>Failed attempts: <?= (int)$u['failed_attempts'] ?></div>
        <div>Password changed: <?= h(fdatetime($u['password_changed_at'])) ?><?= (int)$u['must_change_password'] ? ', must change at next login' : '' ?></div>
        <?php endif; ?>
        <div>Created: <?= h(fdatetime($u['created_at'])) ?></div>
      </div></div>
      <?php if (Access::can($pageKey, 'edit')): ?>
      <div class="card mb-3"><div class="card-body d-grid gap-2">
        <?php if ($u['status'] === 'locked'): ?>
          <form method="post" action="<?= h(url($pageKey, ['a' => 'unlock', 'id' => $u['id']])) ?>"><?= csrf_field() ?><input class="form-control form-control-sm mb-1" name="reason" placeholder="Reason"><button class="btn btn-warning btn-sm w-100">Unlock account</button></form>
        <?php endif; ?>
        <?php if ($link): ?>
          <?php if ($link['status'] === 'active'): ?>
          <form method="post" action="<?= h(url($pageKey, ['a' => 'deactivate', 'id' => $u['id']])) ?>"><?= csrf_field() ?><input class="form-control form-control-sm mb-1" name="reason" placeholder="Reason"><button class="btn btn-outline-danger btn-sm w-100" data-confirm="Remove the access of <?= h($u['username']) ?> to this company? They stay a user of <?= h($link['home_name']) ?>.">Remove access to this company</button></form>
          <?php else: ?>
          <form method="post" action="<?= h(url($pageKey, ['a' => 'activate', 'id' => $u['id']])) ?>"><?= csrf_field() ?><button class="btn btn-outline-success btn-sm w-100">Give access back</button></form>
          <?php endif; ?>
        <?php else: ?>
        <?php if ($u['status'] !== 'inactive'): ?>
          <form method="post" action="<?= h(url($pageKey, ['a' => 'deactivate', 'id' => $u['id']])) ?>"><?= csrf_field() ?><input class="form-control form-control-sm mb-1" name="reason" placeholder="Reason (e.g. resigned)"><button class="btn btn-outline-danger btn-sm w-100" data-confirm="Deactivate this user? They will not be able to log in.">Deactivate (employee left)</button></form>
        <?php else: ?>
          <form method="post" action="<?= h(url($pageKey, ['a' => 'activate', 'id' => $u['id']])) ?>"><?= csrf_field() ?><button class="btn btn-outline-success btn-sm w-100">Reactivate</button></form>
        <?php endif; ?>
        <form method="post" action="<?= h(url($pageKey, ['a' => 'reset', 'id' => $u['id']])) ?>"><?= csrf_field() ?>
          <input class="form-control form-control-sm mb-1" type="password" name="temp_password" placeholder="Temporary password" autocomplete="new-password" required>
          <button class="btn btn-outline-secondary btn-sm w-100">Reset password</button></form>
        <?php endif; ?>
        <p class="small text-muted mb-0">Users are never deleted, so their names stay on the transactions and audit entries they created.</p>
      </div></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    </div>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ list
$status = g('status');
$users = DB::all(
    "SELECT u.*, GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR ', ') AS roles,
            (SELECT GROUP_CONCAT(s.code ORDER BY s.id SEPARATOR ', ') FROM user_sites us JOIN sites s ON s.id = us.site_id WHERE us.user_id = u.id) AS sites,
            (SELECT COUNT(*) FROM user_page_overrides o WHERE o.user_id = u.id) AS overrides
       FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id
      WHERE u.is_platform = 0"
    . ($status ? ' AND u.status = :s' : '') . '
      GROUP BY u.id ORDER BY u.full_name',
    $status ? ['s' => $status] : []
);
$linked = CompanyAccess::linksHere();
$alsoIn = CompanyAccess::linksOut();
layout_start('User Accounts', ['actions' => page_link($pageKey, ['a' => 'new'], 'New user', 'btn-primary', 'person-plus', 'create')
    . (Tenant::isMulti() ? ' ' . page_link($pageKey, ['a' => 'link'], 'Add user from another company', 'btn-outline-primary', 'buildings', 'create') : '')]);
?>
<form method="get" class="mb-2 filters"><input type="hidden" name="p" value="<?= h($pageKey) ?>">
  <select class="form-select form-select-sm w-auto d-inline" name="status" data-autosubmit><?= options(['active' => 'Active', 'inactive' => 'Inactive', 'locked' => 'Locked'], $status, 'All statuses') ?></select></form>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
  <thead><tr><th>Name</th><th>Username</th><th>Employee no.</th><th>Roles</th><th>Sites</th><th>Status</th><th>Last login</th><th class="num">Overrides</th></tr></thead>
  <?php foreach ($users as $r): ?>
    <tr><td><a href="<?= h(url($pageKey, ['a' => 'edit', 'id' => $r['id']])) ?>"><?= h($r['full_name']) ?></a><?php if (isset($linked[(int)$r['id']])): ?><div class="user-from">User of <?= h($linked[(int)$r['id']]['home_name']) ?></div><?php elseif (isset($alsoIn[(int)$r['id']])): ?><div class="user-from">Also works in <?= h(implode(', ', $alsoIn[(int)$r['id']])) ?></div><?php endif; ?></td><td><?= h($r['username']) ?></td><td><?= h($r['employee_no']) ?></td>
      <td class="small"><?= h($r['roles']) ?></td><td class="small"><?= h($r['sites'] ?: 'all') ?></td>
      <td><?= ($r['approval_status'] ?? 'approved') === 'pending' ? badge('Waiting for approval', 'warning') : (($r['approval_status'] ?? 'approved') === 'rejected' ? badge('Rejected', 'secondary') : status_badge($r['status'])) ?><?= Tenant::isOwner((int)$r['id']) ? ' ' . badge('Company Owner', 'light') : '' ?><?= (int)$r['must_change_password'] ? ' ' . badge('temp password', 'light') : '' ?></td>
      <td class="small"><?= h(fdatetime($r['last_login_at'])) ?></td><td class="num"><?= (int)$r['overrides'] ? badge((string)$r['overrides'], 'warning') : '' ?></td></tr>
  <?php endforeach; ?>
</table></div></div>
<?php
layout_end();
