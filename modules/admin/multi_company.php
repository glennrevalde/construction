<?php
declare(strict_types=1);

/**
 * Multi-Company Setup (System Administration): the one-time step that turns this installation into a
 * platform of several companies. This database becomes Company 1, keeping everything in it; the master
 * database and the first Super Admin are created, and Company 1 gets its Company Code and Company Owner.
 */

$pageKey = 'admin.multi_company';
$result = $_SESSION['mc_setup_result'] ?? null;
unset($_SESSION['mc_setup_result']);

// ------------------------------------------------------------------ already running
if (Tenant::isMulti()) {
    $c = Tenant::company();
    layout_start('Multi-Company Setup');
    ?>
    <?php if ($result): ?>
    <div class="card mc-done mb-3"><div class="card-body">
      <h2 class="h5 mb-2"><i class="bi bi-check2-circle text-success"></i> This company is now Company 1 of the platform</h2>
      <p class="mb-3">Nothing in this database was changed apart from the new records of the setup. Everyone keeps logging in with the same username and password.</p>
      <div class="row g-3">
        <div class="col-md-4"><div class="mc-fact"><div class="mc-fact-label">Company Code</div><div class="mc-code"><?= h(CompanyCode::display($result['code'])) ?></div><div class="small text-muted">New employees join with it on the join page.</div></div></div>
        <div class="col-md-4"><div class="mc-fact"><div class="mc-fact-label">Super Admin</div><div class="mc-fact-value"><?= h($result['sa']) ?></div><div class="small text-muted">Logs in on the same log-in page and manages every company.</div></div></div>
        <div class="col-md-4"><div class="mc-fact"><div class="mc-fact-label">Company users registered</div><div class="mc-fact-value"><?= (int)$result['users'] ?></div><div class="small text-muted">All of them keep their roles and access.</div></div></div>
      </div>
      <?php if (!empty($result['conflicts'])): ?>
        <div class="alert alert-warning mt-3 mb-0">These usernames could not be registered because the Super Admin uses the same name: <?= h(implode(', ', $result['conflicts'])) ?>.</div>
      <?php endif; ?>
      <?php if (!empty($result['trigger_problem'])): ?>
        <div class="alert alert-warning mt-3 mb-0">The audit trail protection triggers could not be put in place: <?= h($result['trigger_problem']) ?></div>
      <?php endif; ?>
    </div></div>
    <?php endif; ?>
    <div class="card"><div class="card-body">
      <p class="mb-2">Multi-company is running. This company is <strong><?= h($c['name'] ?? '') ?></strong> (company #<?= (int)($c['id'] ?? 0) ?>) and keeps its own database, its users and all of its records.</p>
      <p class="mb-0 text-muted small">Companies are created, switched on and off and given owners by the Super Admin on the platform pages.
        The Company Code and the employees of this company are managed under <a href="<?= h(url('company.profile')) ?>">Company &gt; Company Profile &amp; Code</a>.</p>
    </div></div>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ the setup
$isAdmin = Access::isAdmin();
$owners = DB::all(
    "SELECT u.id, u.username, u.full_name,
            MAX(r.is_admin) AS is_admin
       FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id
      WHERE u.status = 'active' AND u.approval_status = 'approved' AND u.is_platform = 0
      GROUP BY u.id, u.username, u.full_name ORDER BY MAX(r.is_admin) DESC, u.full_name"
);
$s = Settings::all();
$masterDb = Master::dbName();
$storageOk = is_writable(APP_ROOT . '/storage');

handle_post(function () use ($pageKey, $isAdmin, $s) {
    if (!$isAdmin) {
        Access::denyPage($pageKey, 'set up');
    }
    $sa = [
        'username'  => mb_strtolower(trim((string)p('sa_username'))),
        'full_name' => mb_substr(trim((string)p('sa_full_name')), 0, 120),
        'email'     => trim((string)p('sa_email')),
        'password'  => (string)($_POST['sa_password'] ?? ''),
    ];
    $company = [
        'name' => mb_substr(trim((string)p('name')), 0, 150), 'address' => mb_substr(trim((string)p('address')), 0, 255),
        'contact_person' => mb_substr(trim((string)p('contact_person')), 0, 120), 'phone' => mb_substr(trim((string)p('phone')), 0, 60),
        'email' => mb_substr(trim((string)p('email')), 0, 120), 'tin' => mb_substr(trim((string)p('tin')), 0, 40),
    ];
    if ($company['name'] === '') {
        fail('Enter the company name.');
    }
    if ($company['email'] !== '' && !filter_var($company['email'], FILTER_VALIDATE_EMAIL)) {
        fail('The company e-mail address does not look right.');
    }
    if (!preg_match('/^[a-z0-9._-]{3,50}$/', $sa['username'])) {
        fail('The Super Admin username: 3 to 50 characters, letters, numbers, dot, dash or underscore.');
    }
    if ($sa['full_name'] === '') {
        fail('Enter the Super Admin\'s full name.');
    }
    if ($sa['email'] !== '' && !filter_var($sa['email'], FILTER_VALIDATE_EMAIL)) {
        fail('The Super Admin\'s e-mail address does not look right.');
    }
    if ($sa['password'] !== (string)($_POST['sa_password2'] ?? '')) {
        fail('The two Super Admin passwords do not match.');
    }
    // the platform account opens every company: a stronger rule than a company's own
    $rules = ['pw_min_length' => (string)max(10, (int)$s['pw_min_length']), 'pw_require_upper' => '1', 'pw_require_number' => '1', 'pw_require_symbol' => '1'];
    if ($problems = Auth::passwordProblems($sa['password'], $sa['username'], $rules)) {
        fail('The Super Admin password must have ' . implode(', ', $problems) . '.');
    }
    if (p('confirm') !== '1') {
        fail('Tick the box to confirm that a backup of the database was made.');
    }
    $r = Provision::setupPrimary($sa, $company, (int)p('owner_id'), p('require_approval') === '1');
    $_SESSION['mc_setup_result'] = ['code' => $r['code'], 'sa' => $sa['username'], 'users' => $r['users'] ?? 0,
        'conflicts' => $r['conflicts'] ?? [], 'trigger_problem' => $r['trigger_problem'] ?? ''];
    flash('success', 'Multi-company is set up. This company is Company 1.');
    redirect(url($pageKey));
});

layout_start('Multi-Company Setup');
?>
<div class="row g-3">
  <div class="col-xl-8">
    <form method="post" class="card"><div class="card-body">
      <?= csrf_field() ?>
      <p class="mb-3">Setting up several companies keeps this company exactly as it is and makes it <strong>Company 1</strong>.
        Every company added later gets a database of its own, so no company can ever see another's records.
        This step creates the platform (master) database, the first <strong>Super Admin</strong> account that manages all companies,
        and the <strong>Company Code</strong> that new employees of this company use to join.</p>

      <div class="rd-sub">Company 1</div>
      <div class="row g-2 mb-3">
        <?= input_field('name', 'Company name', fv('name', null, $s['company_name']), ['class' => 'col-md-6', 'required' => true]) ?>
        <?= input_field('tin', 'TIN', fv('tin', null, $s['company_tin']), ['class' => 'col-md-3']) ?>
        <?= input_field('phone', 'Phone', fv('phone', null, $s['company_phone']), ['class' => 'col-md-3']) ?>
        <?= input_field('address', 'Address', fv('address', null, $s['company_address']), ['class' => 'col-md-8']) ?>
        <?= input_field('email', 'E-mail', fv('email', null, $s['company_email']), ['class' => 'col-md-4', 'type' => 'email']) ?>
        <?= input_field('contact_person', 'Contact person', fv('contact_person'), ['class' => 'col-md-6']) ?>
        <div class="col-md-6"><label class="form-label">Company Owner</label>
          <select class="form-select form-select-sm" name="owner_id" required>
            <?php foreach ($owners as $o): ?>
              <option value="<?= (int)$o['id'] ?>" <?= (int)fv('owner_id', null, (string)Auth::id()) === (int)$o['id'] ? 'selected' : '' ?>><?= h($o['full_name'] . ' (' . $o['username'] . ')' . ((int)$o['is_admin'] ? ', administrator' : '')) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Manages this company's employees, join requests and Company Code.</div></div>
        <div class="col-12"><div class="form-check"><input type="hidden" name="require_approval" value="0">
          <input class="form-check-input" type="checkbox" id="mcApproval" name="require_approval" value="1" <?= fv('require_approval', null, '1') === '1' ? 'checked' : '' ?>>
          <label class="form-check-label" for="mcApproval">Employees who join with the Company Code wait for the Company Owner's approval</label></div></div>
      </div>

      <div class="rd-sub">Super Admin (manages every company)</div>
      <div class="row g-2 mb-3">
        <?= input_field('sa_username', 'Username', fv('sa_username', null, 'superadmin'), ['class' => 'col-md-4', 'required' => true, 'extra' => 'autocomplete="off"']) ?>
        <?= input_field('sa_full_name', 'Full name', fv('sa_full_name', null, 'Platform Administrator'), ['class' => 'col-md-4', 'required' => true]) ?>
        <?= input_field('sa_email', 'E-mail', fv('sa_email'), ['class' => 'col-md-4', 'type' => 'email']) ?>
        <div class="col-md-4"><label class="form-label">Password</label><input class="form-control form-control-sm" type="password" name="sa_password" autocomplete="new-password" required></div>
        <div class="col-md-4"><label class="form-label">Password again</label><input class="form-control form-control-sm" type="password" name="sa_password2" autocomplete="new-password" required></div>
        <div class="col-12 small text-muted">At least <?= max(10, (int)$s['pw_min_length']) ?> characters with upper- and lower-case letters, a number and a symbol. The Super Admin's username has to differ from every username of this company.</div>
      </div>

      <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="mcConfirm" name="confirm" value="1">
        <label class="form-check-label" for="mcConfirm">A backup of this database was made (System Administration &gt; Backup &amp; Restore)</label></div>
      <?php if ($isAdmin): ?>
        <button class="btn btn-primary" <?= $storageOk ? '' : 'disabled' ?> data-confirm="Set up multi-company now? This company becomes Company 1 and keeps all of its records."><i class="bi bi-buildings"></i> Set up</button>
      <?php else: ?>
        <p class="text-muted mb-0">Only an administrator can set up multi-company.</p>
      <?php endif; ?>
    </div></form>
  </div>
  <div class="col-xl-4">
    <div class="card mb-3"><div class="card-header fw-semibold">What happens</div><div class="card-body small">
      <ol class="ps-3 mb-0 mc-steps">
        <li>The platform database <code><?= h($masterDb) ?></code> is created on the same MySQL server, with the same user as <code>config/config.php</code>.</li>
        <li>This database is registered as Company 1. No record in it is moved, changed or deleted, and nobody's password changes.</li>
        <li>Every user of this company is listed in the platform database, so the log-in page knows where they belong.</li>
        <li>Company 1 gets its Company Code and the owner you chose.</li>
        <li>The Super Admin account is created. It logs in on the usual log-in page.</li>
      </ol>
    </div></div>
    <div class="card"><div class="card-header fw-semibold">Before you start</div><div class="card-body small">
      <p class="mb-2"><i class="bi bi-<?= $storageOk ? 'check-circle text-success' : 'x-circle text-danger' ?>"></i> The <code>storage</code> folder <?= $storageOk ? 'can be written to' : 'cannot be written to: give the web server write permission on it first' ?>.</p>
      <p class="mb-2">The MySQL user in <code>config/config.php</code> needs the right to create databases (CREATE and DROP), tables, triggers and foreign keys. XAMPP's root user has them. On shared hosting, create the database <code><?= h($masterDb) ?></code> in the control panel first; new companies may then have to be given a database the same way.</p>
      <p class="mb-0">A new company gets its own copy of this company's set-up (chart of accounts, tax codes, terms, roles and the other lists), never its customers, suppliers, items, users or transactions.</p>
    </div></div>
  </div>
</div>
<?php
layout_end();
