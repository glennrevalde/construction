<?php
declare(strict_types=1);

/**
 * Company > Company Profile & Code: the company's own record on the platform, its Company Code and the
 * rules for employees who join with it.
 *
 * Everyone who can open the page sees the code (so it can be handed out). Only the Company Owner (and the
 * Super Admin) can regenerate it, switch joining off or on, give it an expiry date, or change whether new
 * employees need approval. The company's name, address and TIN are kept in step with
 * System Administration > Company, Security & Closing Date.
 */

$pageKey = 'company.profile';

if (!Tenant::isMulti()) {
    layout_start('Company Profile & Code');
    ?>
    <div class="card" style="max-width:760px"><div class="card-body">
      <p class="mb-2">This installation runs a single company, so there is no Company Code yet.</p>
      <p class="text-muted small mb-3">Setting up several companies makes this company Company 1 (keeping all of its records), gives it a Company Code that new employees join with,
        and creates the Super Admin who adds and manages the other companies.</p>
      <?php if (Access::page('admin.multi_company') && Access::isAdmin()): ?>
        <a class="btn btn-primary btn-sm" href="<?= h(url('admin.multi_company')) ?>"><i class="bi bi-buildings"></i> Multi-Company Setup</a>
      <?php endif; ?>
    </div></div>
    <?php
    layout_end();
    return;
}

$companyId = (int)Tenant::id();
$authority = Tenant::hasAuthority();

// ------------------------------------------------------------------ owner actions
if (is_post()) {
    try {
        if (!$authority) {
            fail('Only the Company Owner can change the Company Code and the joining rules.');
        }
        $a = (string)p('a');
        $by = (Tenant::platformSession() ? 'super:' : 'owner:') . (Auth::user()['username'] ?? '');
        if ($a === 'regenerate') {
            $old = (string)(Master::company($companyId, true)['code'] ?? '');
            $new = CompanyCode::regenerate($companyId, $by);
            Audit::log('company_code_changed', 'admin', 'company', $companyId, Tenant::name(), ['page_key' => $pageKey,
                'reason' => 'Company Code regenerated; the old code no longer works. Employees who already joined are not affected.',
                'changes' => [['field' => 'company_code', 'old' => CompanyCode::display($old), 'new' => CompanyCode::display($new)]]]);
            Master::log('company_code_regenerated', $companyId, CompanyCode::display($old) . ' retired, ' . CompanyCode::display($new) . ' issued');
            flash('success', 'The new Company Code is ' . CompanyCode::display($new) . '. The old one stopped working just now.');
        } elseif ($a === 'code_state') {
            $status = p('joining') === '1' ? 'active' : 'disabled';
            $exp = trim((string)p('expires_at'));
            if ($exp !== '' && !valid_date($exp)) {
                fail('Enter the expiry as a date.');
            }
            if ($exp !== '' && $exp < today()) {
                fail('The expiry date is already past.');
            }
            CompanyCode::setState($companyId, $status, $exp !== '' ? $exp . ' 23:59:59' : null);
            Audit::log('company_code_changed', 'admin', 'company', $companyId, Tenant::name(), ['page_key' => $pageKey,
                'reason' => 'Joining with the Company Code ' . ($status === 'active' ? 'switched on' : 'switched off') . ($exp !== '' ? ', valid until ' . fdate($exp) : '')]);
            Master::log('company_code_state', $companyId, 'Joining ' . $status . ($exp !== '' ? ' until ' . $exp : ''));
            flash('success', $status === 'active' ? 'Employees can join with the Company Code.' : 'Joining with the Company Code is switched off.');
        } elseif ($a === 'join_rules') {
            $approval = p('require_approval') === '1';
            $roleId = pint('join_role_id');
            if ($roleId && !DB::value('SELECT id FROM roles WHERE id = :id', ['id' => $roleId])) {
                fail('Choose one of the company\'s roles.');
            }
            if (!$approval && !$roleId) {
                fail('Without approval, new employees need a role to start with: choose one.');
            }
            if (!$approval && (int)DB::value('SELECT is_admin FROM roles WHERE id = :id', ['id' => $roleId])) {
                fail('A full-access role cannot be given to employees who join without approval.');
            }
            Master::sql()->update('companies', ['require_approval' => $approval ? 1 : 0, 'join_role_id' => $roleId ?: null, 'updated_at' => date('Y-m-d H:i:s')],
                'id = :id', ['id' => $companyId]);
            Master::forget($companyId);
            Audit::log('company_join_rules', 'admin', 'company', $companyId, Tenant::name(), ['page_key' => $pageKey,
                'reason' => ($approval ? 'New employees wait for approval' : 'New employees can log in straight away')
                    . ($roleId ? '; starting role: ' . DB::value('SELECT name FROM roles WHERE id = :id', ['id' => $roleId]) : '')]);
            Master::log('company_join_rules', $companyId, $approval ? 'Approval required' : 'No approval');
            flash('success', 'Joining rules saved.');
        } else {
            fail('Unknown action.');
        }
    } catch (UserError $e) {
        flash('danger', $e->getMessage());
    }
    redirect(url($pageKey));
}

// ------------------------------------------------------------------ the page
$c = Master::company($companyId, true);
$owner = $c['owner_user_id'] ? DB::row('SELECT username, full_name, email, last_login_at FROM users WHERE id = :id', ['id' => $c['owner_user_id']]) : null;
$counts = DB::pairs("SELECT CASE WHEN approval_status IN ('pending','rejected') THEN approval_status ELSE status END AS k, COUNT(*)
                       FROM users WHERE is_platform = 0 GROUP BY k");
$roles = DB::pairs('SELECT id, name FROM roles WHERE is_admin = 0 ORDER BY name');
$codeState = $c['code_status'] ?? '';
$expired = $c['code_expires_at'] && strtotime((string)$c['code_expires_at']) < time();
$usable = $c['code'] && $codeState === 'active' && !$expired && $c['status'] === 'active';
$joinUrl = (isset($_SERVER['HTTP_HOST']) ? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST']) : '')
    . base_url() . '/register.php?code=' . rawurlencode((string)$c['code']);
$settingsLink = Access::page('admin.settings') ? btn_link(url('admin.settings'), 'Edit company details', 'btn-outline-secondary', 'pencil') : '';

layout_start('Company Profile & Code', ['actions' => page_link('company.users', [], 'Employees & Users', 'btn-outline-primary', 'people') . ' ' . $settingsLink]);
?>
<div class="row g-3">
  <div class="col-xl-7">
    <div class="card code-card mb-3"><div class="card-body">
      <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
        <div>
          <div class="mc-fact-label">Company Code</div>
          <div class="code-big" id="companyCode"><?= h(CompanyCode::display($c['code'])) ?></div>
          <div class="small <?= $usable ? 'text-success' : 'text-danger' ?>">
            <?php if ($usable): ?><i class="bi bi-check-circle"></i> Employees can join with this code<?= $c['code_expires_at'] ? ' until ' . h(fdate($c['code_expires_at'])) : '' ?>.
            <?php elseif ($c['status'] !== 'active'): ?><i class="bi bi-x-circle"></i> The company is not active, so nobody can join.
            <?php elseif ($expired): ?><i class="bi bi-x-circle"></i> The code expired on <?= h(fdate($c['code_expires_at'])) ?>.
            <?php else: ?><i class="bi bi-x-circle"></i> Joining with the code is switched off.<?php endif; ?>
          </div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <button type="button" class="btn btn-sm btn-outline-primary" data-copy="<?= h(CompanyCode::display($c['code'])) ?>"><i class="bi bi-copy"></i> Copy code</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" data-copy="<?= h($joinUrl) ?>"><i class="bi bi-link-45deg"></i> Copy join link</button>
        </div>
      </div>
      <p class="small text-muted mt-3 mb-0">Give the code to new employees. On the join page they enter their own name, username and password and this code.
        The code only tells the system which company they are joining; it is not a password and opens nothing by itself.
        <?= (int)$c['require_approval'] ? 'Each registration waits for approval under Join Requests.' : 'They can log in as soon as they have joined.' ?></p>
      <?php if ($authority): ?>
      <hr>
      <div class="row g-3">
        <div class="col-md-6">
          <div class="fw-semibold mb-1">A new code</div>
          <p class="small text-muted mb-2">The current code stops working at once. Employees who already joined keep their accounts; only new employees need the new code.</p>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="regenerate">
            <button class="btn btn-sm btn-warning" data-confirm="Make a new Company Code? The current code stops working immediately."><i class="bi bi-arrow-repeat"></i> Regenerate code</button></form>
        </div>
        <div class="col-md-6">
          <div class="fw-semibold mb-1">Joining</div>
          <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="a" value="code_state">
            <div class="col-12"><div class="form-check form-switch"><input type="hidden" name="joining" value="0">
              <input class="form-check-input" type="checkbox" role="switch" id="cpJoining" name="joining" value="1" <?= $codeState === 'active' ? 'checked' : '' ?>>
              <label class="form-check-label" for="cpJoining">Employees can join with the code</label></div></div>
            <div class="col-7"><label class="form-label">Valid until (optional)</label><input class="form-control form-control-sm" type="date" name="expires_at" value="<?= h($c['code_expires_at'] ? substr((string)$c['code_expires_at'], 0, 10) : '') ?>"></div>
            <div class="col-5 d-flex align-items-end"><button class="btn btn-sm btn-outline-primary w-100">Save</button></div>
          </form>
        </div>
      </div>
      <?php else: ?>
      <p class="small text-muted mt-2 mb-0">Only the Company Owner can make a new code or switch joining off.</p>
      <?php endif; ?>
    </div></div>

    <div class="card mb-3"><div class="card-header fw-semibold">New employees</div><div class="card-body">
      <?php if ($authority): ?>
      <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="a" value="join_rules">
        <div class="col-12"><div class="form-check"><input type="hidden" name="require_approval" value="0">
          <input class="form-check-input" type="checkbox" id="cpApproval" name="require_approval" value="1" <?= (int)$c['require_approval'] ? 'checked' : '' ?>>
          <label class="form-check-label" for="cpApproval">Every employee who joins waits for approval (recommended)</label></div></div>
        <?= select_field('join_role_id', 'Role to start with', $roles, (int)($c['join_role_id'] ?? 0), ['blank' => 'Chosen when approving', 'class' => 'col-md-6']) ?>
        <div class="col-md-6 d-flex align-items-end"><button class="btn btn-sm btn-primary">Save</button></div>
        <div class="col-12 small text-muted">With approval, the role is suggested when you approve. Without approval, every employee who joins gets it straight away (a full-access role is never given this way).</div>
      </form>
      <?php else: ?>
        <p class="mb-0"><?= (int)$c['require_approval'] ? 'Every employee who joins waits for approval.' : 'Employees who join can log in straight away.' ?></p>
      <?php endif; ?>
    </div></div>
  </div>

  <div class="col-xl-5">
    <div class="card mb-3"><div class="card-header fw-semibold">Company</div><div class="card-body">
      <dl class="row mb-0 small company-facts">
        <dt class="col-5">Name</dt><dd class="col-7"><?= h($c['name']) ?></dd>
        <dt class="col-5">Address</dt><dd class="col-7"><?= h($c['address'] ?: '') ?></dd>
        <dt class="col-5">Contact person</dt><dd class="col-7"><?= h($c['contact_person'] ?: '') ?></dd>
        <dt class="col-5">Phone</dt><dd class="col-7"><?= h($c['phone'] ?: '') ?></dd>
        <dt class="col-5">E-mail</dt><dd class="col-7"><?= h($c['email'] ?: '') ?></dd>
        <dt class="col-5">TIN</dt><dd class="col-7"><?= h($c['tin'] ?: '') ?></dd>
        <dt class="col-5">Status</dt><dd class="col-7"><?= h(ucfirst((string)$c['status'])) ?></dd>
        <dt class="col-5">On the platform since</dt><dd class="col-7"><?= h(fdate($c['activated_at'] ?: $c['created_at'])) ?></dd>
        <dt class="col-5">Company Owner</dt><dd class="col-7"><?= $owner ? h($owner['full_name'] . ' (' . $owner['username'] . ')') : 'Not assigned yet' ?></dd>
      </dl>
    </div></div>
    <div class="card"><div class="card-header fw-semibold">Users</div><div class="card-body">
      <div class="row g-2 text-center user-tally">
        <?php foreach (['active' => 'Active', 'pending' => 'Waiting', 'inactive' => 'Inactive', 'locked' => 'Locked', 'rejected' => 'Rejected'] as $k => $label): ?>
          <div class="col"><a class="tally" href="<?= h(url('company.users', ['tab' => $k])) ?>"><span class="tally-n"><?= (int)($counts[$k] ?? 0) ?></span><span class="tally-l"><?= h($label) ?></span></a></div>
        <?php endforeach; ?>
      </div>
    </div></div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var text = b.dataset.copy, done = function () { var o = b.innerHTML; b.innerHTML = '<i class="bi bi-check2"></i> Copied'; setTimeout(function () { b.innerHTML = o; }, 1600); };
      if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done); return; }
      var t = document.createElement('textarea'); t.value = text; document.body.appendChild(t); t.select();
      try { document.execCommand('copy'); done(); } catch (e) {} document.body.removeChild(t);
    });
  });
});
</script>
<?php
layout_end();
