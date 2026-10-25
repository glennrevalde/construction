<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/views/layout.php';

// already logged in: into a company, or (several companies) on the platform pages as a Super Admin
if (!DB::isBlocked() && Auth::user()) {
    redirect(url('home.dashboard'));
}
if (Tenant::isMulti() && !Tenant::id() && Platform::admin()) {
    redirect(purl());
}

$error = '';
if (is_post()) {
    verify_csrf();
    // which company (or the platform) the username belongs to is decided on the server: app/Login.php
    [$ok, $error, $to] = Login::attempt((string)p('username'), (string)($_POST['password'] ?? ''));
    if ($ok) {
        redirect($to);
    }
}
$app = app_name();
$multi = Tenant::isMulti();
$coName = $multi ? '' : (string)Settings::get('company_name');
?>
<!doctype html>
<html lang="en">
<head>
<?= gs_head('Log in - ' . $app) ?>
</head>
<body class="gs-entry">
<main class="gs-entry-card">
  <section class="gs-titleblock" aria-label="About this system">
    <div>
      <?= gs_brand_mark('is-large') ?>
      <h1><?= h($app) ?></h1>
      <p>Projects, site work, billing and the books of a construction company, in one place.</p>
    </div>
    <div class="gs-tb-grid" aria-hidden="true">
      <div><span>Sheet</span><b>Log in</b></div>
      <div><span>Scale</span><b>Company-wide</b></div>
      <div><span>Covers</span><b>Projects to ledger</b></div>
      <div><span>Date</span><b><?= h(date('M j, Y')) ?></b></div>
    </div>
  </section>
  <section class="gs-entry-form">
    <h2>Log in</h2>
    <p class="text-muted mb-4"><?= $multi ? 'Use the username your company gave you.' : h($coName) ?></p>
    <?php foreach (take_flash() as [$type, $msg]): ?>
      <div class="alert alert-<?= h($type) ?> py-2"><?= h($msg) ?></div>
    <?php endforeach; ?>
    <?php if ($error): ?><div class="alert alert-danger py-2" role="alert"><?= h($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <div class="mb-3"><label class="form-label" for="lgUser">Username</label>
        <input class="form-control" id="lgUser" name="username" value="<?= h(p('username')) ?>" required autofocus autocapitalize="none" autocomplete="username"></div>
      <div class="mb-4"><label class="form-label" for="lgPw">Password</label>
        <input class="form-control" id="lgPw" type="password" name="password" required autocomplete="current-password"></div>
      <button class="btn btn-primary w-100">Log in</button>
    </form>
    <div class="gs-entry-foot">
      <?php if ($multi): ?><div class="mb-1">New employee? <a href="<?= h(base_url() . '/register.php') ?>">Join your company with its Company Code</a></div><?php endif; ?>
      <div>Every log-in, failed attempt and action is recorded in the audit trail.</div>
      <?php if (Manual::available()): ?><div class="mt-1"><a href="<?= h(Manual::forPage('login')) ?>" target="_blank" rel="noopener"><i class="bi bi-book"></i> User's Manual</a></div><?php endif; ?>
    </div>
  </section>
</main>
</body>
</html>
