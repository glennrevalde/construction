<?php
declare(strict_types=1);

/**
 * The join page: an employee creates their own account in their company with the Company Code.
 *
 *   own details -> Company Code -> the code is checked on the server (current code of an active company,
 *   switched on, not expired) -> the company is found -> the account is created in that company's own
 *   database -> it waits for the Company Owner's approval (unless the company switched approval off).
 *
 * The code only says which company; it is never a password. A wrong, old, switched-off or expired code,
 * or the code of an inactive company, all get the same message, and repeated wrong codes from one
 * address are stopped for a while, so codes cannot be found by trying.
 */

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/views/layout.php';

$app = app_name();
$multi = Tenant::isMulti();
// someone logged in already has an account: joining is for new employees (and the audit trail must not
// credit their session with the new account)
$loggedIn = !empty($_SESSION['user_id']) || !empty($_SESSION['platform_sa_id']);
$done = $_SESSION['join_done'] ?? null;
unset($_SESSION['join_done']);
$error = '';

if ($multi && !$loggedIn && is_post()) {
    verify_csrf();
    try {
        $m = Master::sql();
        $ip = client_ip();
        $limit = max(3, (int)Master::setting('join_attempts_per_15min'));
        $recent = (int)$m->value('SELECT COUNT(*) FROM registration_attempts WHERE ip_address = :ip AND ok = 0 AND created_at > NOW() - INTERVAL 15 MINUTE', ['ip' => $ip]);
        if ($recent >= $limit) {
            fail('Too many attempts from this computer. Please wait 15 minutes and try again.');
        }
        $d = [
            'full_name'   => mb_substr(trim((string)p('full_name')), 0, 120),
            'email'       => mb_substr(trim((string)p('email')), 0, 120),
            'username'    => mb_strtolower(trim((string)p('username'))),
            'employee_no' => mb_substr(trim((string)p('employee_no')), 0, 30),
        ];
        $pw = (string)($_POST['password'] ?? '');
        if ($d['full_name'] === '') {
            fail('Enter your full name.');
        }
        if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            fail('Enter a valid e-mail address.');
        }
        if (!preg_match('/^[a-z0-9._-]{3,50}$/', $d['username'])) {
            fail('Choose a username of 3 to 50 characters: letters, numbers, dot, dash or underscore.');
        }
        if ($pw !== (string)($_POST['password2'] ?? '')) {
            fail('The two passwords do not match.');
        }

        // which company: decided on the server from the code, and nothing else
        $company = CompanyCode::resolve((string)p('company_code'));
        if (!$company) {
            $m->insert('registration_attempts', ['ip_address' => $ip, 'ok' => 0]);
            Master::log('join_refused', null, 'Invalid or inactive Company Code entered', ['guest', null, mb_substr($d['username'], 0, 50)]);
            fail(CompanyCode::INVALID);
        }
        if (!Master::usernameFree($d['username'])) {
            fail('That username is already taken. Please choose another.');
        }
        Tenant::use($company);
        if (DB::value('SELECT id FROM users WHERE username = :u', ['u' => $d['username']])) {
            fail('That username is already taken. Please choose another.');
        }
        if ($problems = Auth::passwordProblems($pw, $d['username'])) {
            fail('Your password must have ' . implode(', ', $problems) . '.');
        }

        $needsApproval = (int)$company['require_approval'] === 1 || !(int)$company['join_role_id'];
        $userId = DB::tx(function () use ($d, $pw, $needsApproval, $company) {
            $id = DB::insert('users', [
                'username' => $d['username'], 'password_hash' => Auth::hash($pw), 'full_name' => $d['full_name'],
                'email' => $d['email'], 'employee_no' => $d['employee_no'] !== '' ? $d['employee_no'] : null,
                'status' => $needsApproval ? 'inactive' : 'active', 'approval_status' => $needsApproval ? 'pending' : 'approved',
                'created_via' => 'registration', 'approved_at' => $needsApproval ? null : date('Y-m-d H:i:s'),
                'must_change_password' => 0, 'password_changed_at' => date('Y-m-d H:i:s'),
            ]);
            if (!$needsApproval) {
                $role = DB::value('SELECT id FROM roles WHERE id = :r AND is_admin = 0', ['r' => (int)$company['join_role_id']]);
                if ($role) {
                    DB::insert('user_roles', ['user_id' => $id, 'role_id' => (int)$role]);
                }
            }
            Audit::log('user_registered', 'admin', 'user', $id, $d['username'], [
                'user_id' => null, 'username' => $d['username'], 'roles' => '', 'page_key' => 'company.join_requests',
                'reason' => 'Joined with the Company Code' . ($needsApproval ? '; waiting for approval' : '; approved automatically'),
                'after' => ['full_name' => $d['full_name'], 'email' => $d['email'], 'employee_no' => $d['employee_no']],
            ]);
            return $id;
        });
        if (!Membership::write((int)$company['id'], DB::row('SELECT * FROM users WHERE id = :id', ['id' => $userId]))) {
            // the username was taken by someone else a moment ago: undo
            DB::update('users', ['status' => 'inactive', 'approval_status' => 'rejected', 'rejected_reason' => 'Username taken on the platform'], 'id = :id', ['id' => $userId]);
            fail('That username is already taken. Please choose another.');
        }
        $m->insert('registration_attempts', ['ip_address' => $ip, 'ok' => 1]);
        $m->run('DELETE FROM registration_attempts WHERE created_at < NOW() - INTERVAL 2 DAY');
        Master::log('employee_joined', (int)$company['id'], $d['username'] . ($needsApproval ? ' is waiting for approval' : ' joined'), ['guest', null, $d['username']]);
        $_SESSION['join_done'] = ['company' => $company['name'], 'pending' => $needsApproval, 'username' => $d['username']];
        redirect(base_url() . '/register.php');
    } catch (UserError $e) {
        $error = $e->getMessage();
    }
}
$code = (string)(p('company_code') ?: g('code'));
?>
<!doctype html>
<html lang="en">
<head>
<?= gs_head('Join your company - ' . $app) ?>
</head>
<body class="gs-entry">
<main class="gs-entry-card is-narrow">
    <section class="gs-entry-form">
      <div class="d-flex align-items-center gap-3 mb-3">
        <?= gs_brand_mark() ?>
        <div><h2 class="mb-0">Join your company</h2><div class="text-muted small"><?= h($app) ?></div></div>
      </div>
      <?php if (!$multi): ?>
        <p>Joining with a Company Code is not available on this system. Ask your administrator to create your account.</p>
        <a class="btn btn-outline-secondary w-100" href="<?= h(base_url() . '/login.php') ?>">Back to log in</a>
      <?php elseif ($loggedIn): ?>
        <p>You are logged in already. Joining is for new employees who do not have an account yet.</p>
        <a class="btn btn-outline-secondary w-100" href="<?= h(base_url() . '/index.php') ?>">Back</a>
      <?php elseif ($done): ?>
        <div class="join-done">
          <i class="bi bi-<?= $done['pending'] ? 'hourglass-split' : 'check2-circle' ?>"></i>
          <h2 class="h5">Your account for <?= h($done['company']) ?> was created</h2>
          <?php if ($done['pending']): ?>
            <p>Your Company Owner has to approve it before you can log in. Once it is approved, log in with the username <strong><?= h($done['username']) ?></strong> and the password you chose.</p>
          <?php else: ?>
            <p>You can log in now with the username <strong><?= h($done['username']) ?></strong> and the password you chose.</p>
          <?php endif; ?>
        </div>
        <a class="btn btn-primary w-100" href="<?= h(base_url() . '/login.php') ?>">Go to log in</a>
      <?php else: ?>
        <?php if ($error): ?><div class="alert alert-danger py-2"><?= h($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="off" novalidate>
          <?= csrf_field() ?>
          <div class="row g-3">
            <div class="col-12"><label class="form-label" for="jName">Full name</label>
              <input class="form-control" id="jName" name="full_name" value="<?= h(p('full_name')) ?>" required maxlength="120" autocomplete="name"></div>
            <div class="col-sm-7"><label class="form-label" for="jEmail">E-mail</label>
              <input class="form-control" id="jEmail" type="email" name="email" value="<?= h(p('email')) ?>" required maxlength="120" autocomplete="email"></div>
            <div class="col-sm-5"><label class="form-label" for="jEmp">Employee no. <span class="text-muted small">(if any)</span></label>
              <input class="form-control" id="jEmp" name="employee_no" value="<?= h(p('employee_no')) ?>" maxlength="30"></div>
            <div class="col-12"><label class="form-label" for="jUser">Username</label>
              <input class="form-control" id="jUser" name="username" value="<?= h(p('username')) ?>" required maxlength="50" autocomplete="username" autocapitalize="none">
              <div class="form-text">3 to 50 characters: letters, numbers, dot, dash or underscore. You log in with it.</div></div>
            <div class="col-sm-6"><label class="form-label" for="jPw">Password</label>
              <input class="form-control" id="jPw" type="password" name="password" required autocomplete="new-password"></div>
            <div class="col-sm-6"><label class="form-label" for="jPw2">Confirm password</label>
              <input class="form-control" id="jPw2" type="password" name="password2" required autocomplete="new-password"></div>
            <div class="col-12"><label class="form-label" for="jCode">Company Code</label>
              <input class="form-control join-code" id="jCode" name="company_code" value="<?= h($code) ?>" required maxlength="24" autocapitalize="characters" spellcheck="false" placeholder="e.g. ABC-7K2QX">
              <div class="form-text">Your Company Owner gives you this code. It is not your password.</div></div>
          </div>
          <button class="btn btn-primary w-100 mt-4">Create my account</button>
        </form>
        <div class="login-join mt-3">Already have an account? <a href="<?= h(base_url() . '/login.php') ?>">Log in</a></div>
      <?php endif; ?>
      <?php if (Manual::available()): ?>
      <div class="gs-entry-foot"><a href="<?= h(Manual::forPage('register')) ?>" target="_blank" rel="noopener"><i class="bi bi-book"></i> How joining works (User's Manual)</a></div>
      <?php endif; ?>
    </section>
</main>
</body>
</html>
