<?php
declare(strict_types=1);

$user = Auth::user();
if (CompanyAccess::isLinked($user)) {
    // a user of another company working here: the password belongs to their own company
    flash('info', 'You log in at ' . (CompanyAccess::linkOf((int)$user['id'])['home_name'] ?? 'your own company') . '. Switch back to it to change your password.');
    redirect(url('home.dashboard'));
}
handle_post(function () use ($user) {
    $current = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    if (!password_verify($current, $user['password_hash'])) {
        fail('Your current password is incorrect.');
    }
    if ($new !== $confirm) {
        fail('The new passwords do not match.');
    }
    if (password_verify($new, $user['password_hash'])) {
        fail('Choose a password different from your current one.');
    }
    if ($problems = Auth::passwordProblems($new, $user['username'])) {
        fail('The new password must have ' . implode(', ', $problems) . '.');
    }
    DB::update('users', [
        'password_hash' => Auth::hash($new),
        'must_change_password' => 0,
        'password_changed_at' => date('Y-m-d H:i:s'),
    ], 'id = :id', ['id' => $user['id']]);
    Audit::log('password_change', 'security', 'user', (int)$user['id'], $user['username']);
    flash('success', 'Your password has been changed.');
    redirect(url('home.dashboard'));
});

$s = Settings::all();
layout_start('Change password');
?>
<div class="card" style="max-width:520px">
  <div class="card-body">
    <?php if ((int)$user['must_change_password'] === 1): ?>
      <div class="alert alert-warning py-2">Please replace your temporary password before continuing.</div>
    <?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <div class="mb-2"><label class="form-label">Current password</label><input class="form-control" type="password" name="current_password" required></div>
      <div class="mb-2"><label class="form-label">New password</label><input class="form-control" type="password" name="new_password" required></div>
      <div class="mb-3"><label class="form-label">Confirm new password</label><input class="form-control" type="password" name="confirm_password" required></div>
      <p class="small text-muted">Password rules: at least <?= (int)$s['pw_min_length'] ?> characters<?= $s['pw_require_upper'] === '1' ? ', upper- and lower-case letters' : '' ?><?= $s['pw_require_number'] === '1' ? ', a number' : '' ?><?= $s['pw_require_symbol'] === '1' ? ', a symbol' : '' ?>. <?= (int)$s['pw_expiry_days'] > 0 ? 'Passwords expire every ' . (int)$s['pw_expiry_days'] . ' days.' : 'Passwords do not expire.' ?></p>
      <button class="btn btn-primary">Change password</button>
    </form>
  </div>
</div>
<?php
layout_end();
