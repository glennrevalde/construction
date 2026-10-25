<?php
declare(strict_types=1);

$pageName = $GLOBALS['DENIED_PAGE'] ?? 'this page';
$action = $GLOBALS['DENIED_ACTION'] ?? 'open';
layout_start('Access denied', ['no_title' => true]);
?>
<div class="card border-danger mt-4" style="max-width:640px">
  <div class="card-body">
    <h1 class="h4 text-danger"><i class="bi bi-shield-lock"></i> Access denied</h1>
    <p class="mb-2">You do not have permission to <?= $action === 'open' ? 'open' : h($action) . ' on' ?> <strong><?= h($pageName) ?></strong>.</p>
    <p class="text-muted small mb-3">This attempt has been recorded in the audit trail. If you need access, ask your system administrator to update your role or page access.</p>
    <a class="btn btn-primary btn-sm" href="<?= h(url('home.dashboard')) ?>">Go to Home</a>
    <a class="btn btn-outline-secondary btn-sm" href="javascript:history.back()">Go back</a>
  </div>
</div>
<?php
layout_end();
