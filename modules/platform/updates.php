<?php
declare(strict_types=1);

/**
 * Platform > Database updates: every company database has to match the program files. When a new version
 * brings upgrade scripts (database/migrations), they are run here on each company database, oldest first.
 * The scripts are written for MySQL 5.5, keep every record and are safe to run twice.
 */

$companies = array_values(array_filter(Master::companies(), fn($c) => in_array($c['status'], ['active', 'inactive'], true) && $c['db_name']));

if (is_post()) {
    $only = pint('id');
    $ran = 0;
    $problems = [];
    foreach ($companies as $c) {
        if ($only && (int)$c['id'] !== $only) {
            continue;
        }
        try {
            $r = Migrator::runPending(Sql::to((string)$c['db_name']));
            $ran += count($r['ran']);
            if ($r['ran']) {
                Master::log('database_updated', (int)$c['id'], 'Ran ' . implode(', ', array_map('basename', $r['ran'])));
            }
            foreach ($r['errors'] as $e) {
                $problems[] = $c['name'] . ': ' . $e;
            }
        } catch (Throwable $e) {
            $problems[] = $c['name'] . ': ' . $e->getMessage();
        }
    }
    if ($problems) {
        flash('danger', 'Some updates could not be run. ' . implode(' | ', $problems));
    } else {
        flash('success', $ran ? $ran . ' update(s) run. Every company database is up to date.' : 'Every company database was already up to date.');
    }
    redirect(purl('updates'));
}

$rows = [];
$waiting = 0;
foreach ($companies as $c) {
    try {
        $pending = Schema::pendingOn(Sql::to((string)$c['db_name']));
        $err = '';
    } catch (Throwable $e) {
        $pending = [];
        $err = $e->getMessage();
    }
    $waiting += count($pending);
    $rows[] = [$c, $pending, $err];
}

platform_start('Database updates', ['actions' => $waiting ? '<form method="post" class="d-inline">' . csrf_field()
    . '<button class="btn btn-sm btn-primary" data-confirm="Run every pending update on every company database? Back the databases up first."><i class="bi bi-database-check"></i> Update all companies</button></form>' : '']);
?>
<p class="text-muted">The program knows <?= count(Schema::files()) ?> upgrade scripts. A company database is up to date when every one of them has been run on it.
  New company databases are made up to date when they are created. Back the databases up before running updates.</p>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0">
  <thead><tr><th>Company</th><th>Database</th><th>Updates to run</th><th class="text-end"></th></tr></thead>
  <?php foreach ($rows as [$c, $pending, $err]): ?>
    <tr><td><a href="<?= h(purl('company', ['id' => $c['id']])) ?>"><?= h($c['name']) ?></a><div class="small text-muted"><?= platform_status((string)$c['status']) ?></div></td>
      <td class="small"><code><?= h($c['db_name']) ?></code></td>
      <td class="small"><?php if ($err): ?><span class="text-danger"><?= h($err) ?></span>
        <?php elseif ($pending): ?><?php foreach ($pending as $f): ?><div><code><?= h(basename($f)) ?></code></div><?php endforeach; ?>
        <?php else: ?><span class="text-success"><i class="bi bi-check-circle"></i> Up to date</span><?php endif; ?></td>
      <td class="text-end"><?php if ($pending): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-sm btn-outline-primary">Run</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="4" class="text-muted py-3">No company database yet.</td></tr><?php endif; ?>
</table></div></div>
<?php
platform_end();
