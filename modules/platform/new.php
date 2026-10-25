<?php
declare(strict_types=1);

/** Platform > New company: the company, its owner, and a database of its own made automatically. */

$templates = [];
foreach (Master::companies() as $c) {
    if ($c['status'] === 'active') {
        $templates[(int)$c['id']] = $c['name'];
    }
}

handle_post(function () use ($sa, $templates) {
    $data = [
        'name' => mb_substr(trim((string)p('name')), 0, 150), 'address' => mb_substr(trim((string)p('address')), 0, 255),
        'contact_person' => mb_substr(trim((string)p('contact_person')), 0, 120), 'phone' => mb_substr(trim((string)p('phone')), 0, 60),
        'email' => mb_substr(trim((string)p('email')), 0, 120), 'tin' => mb_substr(trim((string)p('tin')), 0, 40),
        'notes' => mb_substr(trim((string)p('notes')), 0, 255), 'require_approval' => p('require_approval') === '1',
    ];
    if ($data['name'] === '') {
        fail('Enter the company name.');
    }
    if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        fail('The company e-mail address does not look right.');
    }
    $templateId = (int)p('template_company_id');
    if (!isset($templates[$templateId])) {
        fail('Choose the company whose set-up is copied.');
    }
    $owner = null;
    if (p('owner_now') === '1') {
        $owner = ['username' => mb_strtolower(trim((string)p('owner_username'))), 'full_name' => mb_substr(trim((string)p('owner_full_name')), 0, 120),
            'email' => trim((string)p('owner_email')), 'password' => (string)($_POST['owner_password'] ?? '')];
        if ($owner['password'] !== (string)($_POST['owner_password2'] ?? '')) {
            fail('The two owner passwords do not match.');
        }
    }
    $id = Provision::create($data, $owner, $templateId, (int)$sa['id']);
    $c = Master::company($id, true);
    if ($c && $c['status'] === 'active') {
        flash('success', $data['name'] . ' is set up with a database of its own. Company Code: ' . CompanyCode::display($c['code'])
            . ($owner ? '. The owner logs in as ' . $owner['username'] . ' and must change the temporary password.' : '. Assign a Company Owner next.'));
    } else {
        flash('danger', 'The set-up of ' . $data['name'] . ' did not finish: ' . ($c['last_error'] ?? 'see the steps below') . '. Nothing half-made was left behind.');
    }
    redirect(purl('company', ['id' => $id]));
});

$primary = Master::primaryCompanyId();
platform_start('New company', ['actions' => btn_link(purl('companies'), 'All companies')]);
?>
<form method="post" class="row g-3">
  <?= csrf_field() ?>
  <div class="col-xl-8">
    <div class="card mb-3"><div class="card-header fw-semibold">Company</div><div class="card-body"><div class="row g-2">
      <?= input_field('name', 'Company name', fv('name'), ['class' => 'col-md-8', 'required' => true, 'extra' => 'maxlength="150"']) ?>
      <?= input_field('tin', 'TIN', fv('tin'), ['class' => 'col-md-4']) ?>
      <?= input_field('address', 'Address', fv('address'), ['class' => 'col-12']) ?>
      <?= input_field('contact_person', 'Contact person', fv('contact_person'), ['class' => 'col-md-4']) ?>
      <?= input_field('phone', 'Phone', fv('phone'), ['class' => 'col-md-4']) ?>
      <?= input_field('email', 'E-mail', fv('email'), ['class' => 'col-md-4', 'type' => 'email']) ?>
      <?= input_field('notes', 'Notes (platform only)', fv('notes'), ['class' => 'col-12']) ?>
      <?= select_field('template_company_id', 'Copy the set-up of', $templates, (int)fv('template_company_id', null, (string)$primary), ['class' => 'col-md-6', 'required' => true]) ?>
      <div class="col-md-6 d-flex align-items-end"><div class="form-check"><input type="hidden" name="require_approval" value="0">
        <input class="form-check-input" type="checkbox" id="ncAppr" name="require_approval" value="1" <?= fv('require_approval', null, Master::setting('default_require_approval')) === '1' ? 'checked' : '' ?>>
        <label class="form-check-label" for="ncAppr">Employees who join wait for the owner's approval</label></div></div>
    </div></div></div>

    <div class="card mb-3"><div class="card-header fw-semibold">Company Owner</div><div class="card-body"><div class="row g-2">
      <div class="col-12"><div class="form-check"><input type="hidden" name="owner_now" value="0">
        <input class="form-check-input" type="checkbox" id="ncOwner" name="owner_now" value="1" <?= fv('owner_now', null, '1') === '1' ? 'checked' : '' ?>>
        <label class="form-check-label" for="ncOwner">Create the owner's account now (otherwise assign one later)</label></div></div>
      <div class="col-12" id="ncOwnerFields"><div class="row g-2">
        <?= input_field('owner_full_name', 'Full name', fv('owner_full_name'), ['class' => 'col-md-6']) ?>
        <?= input_field('owner_email', 'E-mail', fv('owner_email'), ['class' => 'col-md-6', 'type' => 'email']) ?>
        <?= input_field('owner_username', 'Username', fv('owner_username'), ['class' => 'col-md-4', 'extra' => 'autocomplete="off" maxlength="50"']) ?>
        <div class="col-md-4"><label class="form-label">Temporary password</label><input class="form-control form-control-sm" type="password" name="owner_password" autocomplete="new-password"></div>
        <div class="col-md-4"><label class="form-label">Password again</label><input class="form-control form-control-sm" type="password" name="owner_password2" autocomplete="new-password"></div>
        <div class="col-12 small text-muted">The username has to be free across every company. The owner gets the company's full-access role and must choose a new password at the first log-in.</div>
      </div></div>
    </div></div></div>
    <button class="btn btn-primary" data-confirm="Create the company and its database now?"><i class="bi bi-buildings"></i> Create company</button>
  </div>
  <div class="col-xl-4">
    <div class="card"><div class="card-header fw-semibold">What is set up</div><div class="card-body small">
      <ol class="ps-3 mb-3 mc-steps">
        <li>A Company Code, unique on the platform.</li>
        <li>A database of its own, named by the platform (<code><?= h(Master::dbPrefix()) ?>NNN</code>).</li>
        <li>Every table, with its columns, keys, indexes and foreign keys, exactly as the copied company has them today.</li>
        <li>The copied company's set-up lists, and one inventory site, Main.</li>
        <li>The audit trail protection.</li>
        <li>The owner's account, and the company's entry on the platform.</li>
        <li>A check of the new database. Only then is the company switched on.</li>
      </ol>
      <div class="fw-semibold mb-1">Copied set-up lists</div>
      <ul class="ps-3 mb-3 text-muted"><?php foreach (Provision::COPY as $t => $what): ?><li><?= h($what) ?></li><?php endforeach; ?></ul>
      <div class="fw-semibold mb-1">Never copied</div>
      <p class="text-muted mb-0">Customers, suppliers, items, employees and other names, users, transactions, sales, payments, bills, journals, balances, budgets, fixed assets, POS records, the audit trail and any other history.</p>
    </div></div>
  </div>
</form>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var box = document.getElementById('ncOwner'), f = document.getElementById('ncOwnerFields');
  var sync = function () { f.hidden = !box.checked; f.querySelectorAll('input').forEach(function (i) { i.required = box.checked && i.name !== 'owner_email'; }); };
  box.addEventListener('change', sync); sync();
});
</script>
<?php
platform_end();
