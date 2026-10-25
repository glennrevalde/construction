<?php
declare(strict_types=1);

/** Platform > Platform settings */

$fields = [
    'db_prefix'                => ['Name of new company databases: prefix', 'text', 'Letters, digits and underscores. New databases are called <prefix>002, <prefix>003 ... On shared hosting use the prefix your control panel requires (for example cpuser_).'],
    'default_require_approval' => ['New companies: employees who join wait for approval', 'bool', 'Each company can change it on its own page.'],
    'session_timeout_minutes'  => ['Super Admin session ends after (minutes without activity)', 'int', ''],
    'max_failed_logins'        => ['Super Admin failed log-ins before the account locks', 'int', ''],
    'join_attempts_per_15min'  => ['Wrong Company Codes allowed from one address in 15 minutes', 'int', 'After that, the join page stops that address for 15 minutes.'],
];

handle_post(function () use ($fields) {
    $changed = [];
    foreach ($fields as $k => [$label, $type]) {
        $v = match ($type) {
            'bool' => p($k) === '1' ? '1' : '0',
            'int' => (string)max(1, (int)p($k)),
            default => trim((string)p($k)),
        };
        if ($k === 'db_prefix' && $v !== '' && !preg_match('/^[A-Za-z0-9_]{1,50}$/', $v)) {
            fail('The database prefix may only have letters, digits and underscores (at most 50).');
        }
        if ($k === 'session_timeout_minutes' && (int)$v < 5) {
            fail('The Super Admin session needs at least 5 minutes.');
        }
        if ($v !== Master::setting($k)) {
            Master::setSetting($k, $v);
            $changed[] = $k . '=' . $v;
        }
    }
    if ($changed) {
        Master::log('platform_settings', null, implode('; ', $changed));
    }
    flash('success', count($changed) . ' setting(s) changed.');
    redirect(purl('settings'));
});

platform_start('Platform settings');
?>
<form method="post" class="card" style="max-width:820px"><div class="card-body"><div class="row g-3">
  <?= csrf_field() ?>
  <?php foreach ($fields as $k => [$label, $type, $help]): $v = Master::setting($k); ?>
    <?php if ($type === 'bool'): ?>
      <div class="col-12"><div class="form-check"><input type="hidden" name="<?= h($k) ?>" value="0"><input class="form-check-input" type="checkbox" id="ps_<?= h($k) ?>" name="<?= h($k) ?>" value="1" <?= $v === '1' ? 'checked' : '' ?>>
        <label class="form-check-label" for="ps_<?= h($k) ?>"><?= h($label) ?></label></div><?php if ($help): ?><div class="form-text"><?= h($help) ?></div><?php endif; ?></div>
    <?php else: ?>
      <div class="col-md-8"><label class="form-label" for="ps_<?= h($k) ?>"><?= h($label) ?></label>
        <input class="form-control form-control-sm" id="ps_<?= h($k) ?>" name="<?= h($k) ?>" type="<?= $type === 'int' ? 'number' : 'text' ?>" value="<?= h($v) ?>" <?= $k === 'db_prefix' ? 'placeholder="' . h(Master::dbPrefix()) . '"' : '' ?>>
        <?php if ($help): ?><div class="form-text"><?= h($help) ?></div><?php endif; ?></div>
    <?php endif; ?>
  <?php endforeach; ?>
  <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-check-lg"></i> Save</button></div>
</div></div></form>
<div class="card mt-3" style="max-width:820px"><div class="card-body small">
  <div class="fw-semibold mb-1">Platform database</div>
  <p class="mb-2"><code><?= h(Master::dbName()) ?></code> on the MySQL server of <code>config/config.php</code>, reached with the same user. Company databases are created with that user too, so it needs the right to create and drop databases, and to create tables, foreign keys and triggers in them.</p>
  <p class="mb-0 text-muted">To switch multi-company off in an emergency, remove <code>storage/platform/multi_company.json</code>: the system then runs as Company 1 alone, with its users only. Put it back (or run Multi-Company Setup again) to return; nothing is lost either way.</p>
</div></div>
<?php
platform_end();
