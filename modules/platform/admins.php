<?php
declare(strict_types=1);

/** Platform > Super Admins: the accounts that manage every company. They belong to no company. */

$m = Master::sql();
$rules = ['pw_min_length' => '10', 'pw_require_upper' => '1', 'pw_require_number' => '1', 'pw_require_symbol' => '1'];

if (is_post()) {
    $a = (string)p('a');
    try {
        if ($a === 'new') {
            $d = ['username' => mb_strtolower(trim((string)p('username'))), 'full_name' => mb_substr(trim((string)p('full_name')), 0, 120),
                'email' => trim((string)p('email'))];
            $pw = (string)($_POST['password'] ?? '');
            if (!preg_match('/^[a-z0-9._-]{3,50}$/', $d['username'])) {
                fail('Username: 3 to 50 characters, letters, numbers, dot, dash or underscore.');
            }
            if ($d['full_name'] === '') {
                fail('Enter the full name.');
            }
            if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
                fail('The e-mail address does not look right.');
            }
            if (!Master::usernameFree($d['username'])) {
                fail('That username is already used on the platform (by a Super Admin or a company user).');
            }
            if ($problems = Auth::passwordProblems($pw, $d['username'], $rules)) {
                fail('The temporary password must have ' . implode(', ', $problems) . '.');
            }
            $m->insert('super_admins', $d + ['email' => $d['email'] ?: null, 'password_hash' => Auth::hash($pw), 'status' => 'active',
                'must_change_password' => 1, 'password_changed_at' => date('Y-m-d H:i:s'), 'created_by' => (int)$sa['id']]);
            Master::log('super_admin_created', null, $d['username']);
            flash('success', 'Super Admin ' . $d['username'] . ' created. They must change the temporary password at the first log-in.');
        } else {
            $t = Master::superAdmin((int)p('id'));
            if (!$t) {
                fail('Account not found.');
            }
            if ($a === 'deactivate') {
                if ((int)$t['id'] === (int)$sa['id']) {
                    fail('You cannot deactivate your own account.');
                }
                if ((int)$m->value("SELECT COUNT(*) FROM super_admins WHERE status = 'active' AND id <> :id", ['id' => $t['id']]) === 0) {
                    fail('This is the last active Super Admin.');
                }
                $m->update('super_admins', ['status' => 'inactive'], 'id = :id', ['id' => $t['id']]);
                Master::log('super_admin_deactivated', null, $t['username']);
                flash('success', $t['username'] . ' was deactivated.');
            } elseif ($a === 'activate' || $a === 'unlock') {
                $m->update('super_admins', ['status' => 'active', 'failed_attempts' => 0], 'id = :id', ['id' => $t['id']]);
                Master::log('super_admin_' . ($a === 'unlock' ? 'unlocked' : 'activated'), null, $t['username']);
                flash('success', $t['username'] . ($a === 'unlock' ? ' was unlocked.' : ' was activated.'));
            } elseif ($a === 'reset') {
                $pw = (string)($_POST['password'] ?? '');
                if ($problems = Auth::passwordProblems($pw, $t['username'], $rules)) {
                    fail('The temporary password must have ' . implode(', ', $problems) . '.');
                }
                $m->update('super_admins', ['password_hash' => Auth::hash($pw), 'must_change_password' => 1, 'failed_attempts' => 0,
                    'status' => $t['status'] === 'locked' ? 'active' : $t['status'], 'password_changed_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $t['id']]);
                Master::log('super_admin_password_reset', null, $t['username']);
                flash('success', 'Temporary password set for ' . $t['username'] . '.');
            } else {
                fail('Unknown action.');
            }
        }
    } catch (UserError $e) {
        flash('danger', $e->getMessage());
    }
    redirect(purl('admins'));
}

$admins = $m->all('SELECT * FROM super_admins ORDER BY full_name');
platform_start('Super Admins');
?>
<div class="row g-3">
  <div class="col-xl-8">
    <div class="card"><div class="table-responsive"><table class="table table-sm mb-0">
      <thead><tr><th>Name</th><th>Username</th><th>Status</th><th>Last log-in</th><th class="text-end"></th></tr></thead>
      <?php foreach ($admins as $a): ?>
        <tr><td><?= h($a['full_name']) ?><?= (int)$a['id'] === (int)$sa['id'] ? '<div class="small text-muted">You</div>' : '' ?><div class="small text-muted"><?= h($a['email']) ?></div></td>
          <td><?= h($a['username']) ?></td>
          <td><span class="user-state us-<?= h($a['status']) ?>"><?= h(ucfirst($a['status'])) ?></span><?= (int)$a['must_change_password'] ? '<div class="small text-muted">temporary password</div>' : '' ?></td>
          <td class="small text-nowrap"><?= h(fdatetime($a['last_login_at'])) ?></td>
          <td class="text-end text-nowrap">
            <?php if ((int)$a['id'] !== (int)$sa['id']): ?>
              <?php if ($a['status'] === 'locked'): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="a" value="unlock"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn btn-sm btn-outline-warning">Unlock</button></form><?php endif; ?>
              <?php if ($a['status'] === 'inactive'): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="a" value="activate"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn btn-sm btn-outline-success">Activate</button></form>
              <?php else: ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="a" value="deactivate"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn btn-sm btn-outline-danger" data-confirm="Deactivate <?= h($a['username']) ?>?">Deactivate</button></form><?php endif; ?>
              <form method="post" class="d-inline-flex gap-1 mt-1"><?= csrf_field() ?><input type="hidden" name="a" value="reset"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <input class="form-control form-control-sm" style="width:10rem" type="password" name="password" placeholder="Temporary password" autocomplete="new-password" required><button class="btn btn-sm btn-outline-secondary">Reset</button></form>
            <?php else: ?>
              <a class="btn btn-sm btn-outline-secondary" href="<?= h(purl('account')) ?>">Change my password</a>
            <?php endif; ?>
          </td></tr>
      <?php endforeach; ?>
    </table></div></div>
  </div>
  <div class="col-xl-4">
    <form method="post" class="card"><div class="card-header fw-semibold">New Super Admin</div><div class="card-body"><div class="row g-2">
      <?= csrf_field() ?><input type="hidden" name="a" value="new">
      <?= input_field('full_name', 'Full name', '', ['class' => 'col-12', 'required' => true]) ?>
      <?= input_field('username', 'Username', '', ['class' => 'col-md-6', 'required' => true, 'extra' => 'autocomplete="off"']) ?>
      <?= input_field('email', 'E-mail', '', ['class' => 'col-md-6', 'type' => 'email']) ?>
      <div class="col-12"><label class="form-label">Temporary password</label><input class="form-control form-control-sm" type="password" name="password" required autocomplete="new-password"></div>
      <div class="col-12 small text-muted">At least 10 characters with upper- and lower-case letters, a number and a symbol. A Super Admin can open and change every company.</div>
      <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-person-plus"></i> Create</button></div>
    </div></div></form>
  </div>
</div>
<?php
platform_end();
