<?php
declare(strict_types=1);

/** Platform > My account: the Super Admin's own password */

$rules = ['pw_min_length' => '10', 'pw_require_upper' => '1', 'pw_require_number' => '1', 'pw_require_symbol' => '1'];
handle_post(function () use ($sa, $rules) {
    $current = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    if (!password_verify($current, $sa['password_hash'])) {
        fail('Your current password is incorrect.');
    }
    if ($new !== (string)($_POST['confirm_password'] ?? '')) {
        fail('The new passwords do not match.');
    }
    if (password_verify($new, $sa['password_hash'])) {
        fail('Choose a password different from your current one.');
    }
    if ($problems = Auth::passwordProblems($new, $sa['username'], $rules)) {
        fail('The new password must have ' . implode(', ', $problems) . '.');
    }
    Master::sql()->update('super_admins', ['password_hash' => Auth::hash($new), 'must_change_password' => 0, 'password_changed_at' => date('Y-m-d H:i:s')],
        'id = :id', ['id' => $sa['id']]);
    Master::log('password_change', null, 'Super Admin changed their password');
    flash('success', 'Your password has been changed.');
    redirect(purl('companies'));
});

platform_start('My account');
?>
<div class="card" style="max-width:560px"><div class="card-body">
  <?php if ((int)$sa['must_change_password'] === 1): ?><div class="alert alert-warning py-2">Please replace your temporary password before continuing.</div><?php endif; ?>
  <p class="small text-muted"><?= h($sa['full_name']) ?> (<?= h($sa['username']) ?>), Super Admin. Last log-in <?= h(fdatetime($sa['last_login_at'])) ?>.</p>
  <form method="post">
    <?= csrf_field() ?>
    <div class="mb-2"><label class="form-label">Current password</label><input class="form-control" type="password" name="current_password" required autocomplete="current-password"></div>
    <div class="mb-2"><label class="form-label">New password</label><input class="form-control" type="password" name="new_password" required autocomplete="new-password"></div>
    <div class="mb-3"><label class="form-label">Confirm new password</label><input class="form-control" type="password" name="confirm_password" required autocomplete="new-password"></div>
    <p class="small text-muted">At least 10 characters with upper- and lower-case letters, a number and a symbol.</p>
    <button class="btn btn-primary">Change password</button>
  </form>
</div></div>
<?php
platform_end();
