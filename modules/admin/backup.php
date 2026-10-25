<?php
declare(strict_types=1);

/**
 * System Administration > Backup & Restore.
 *
 * Makes a .sql backup of the whole database, lists the backups kept on the server, downloads one,
 * deletes one, and reads a backup back into the database. A backup uploaded from the user's own
 * computer can be restored as well.
 *
 * The writing and the reading are in app/Backup.php. A restore always makes a fresh backup of what
 * is there now before it changes anything, so it can be undone.
 */

$pageKey = 'admin.backup';
$canMake = Access::can($pageKey, 'create');
$canRestore = Access::can($pageKey, 'edit') && Access::isAdmin();   // restoring replaces the whole database: administrators only
$canDelete = Access::can($pageKey, 'delete');

// ------------------------------------------------------------------ download
if (g('a') === 'download' && g('file')) {
    Access::require($pageKey, 'print');
    try {
        $wanted = Backup::path((string)g('file'));   // refuses anything that is not a backup in that folder
    } catch (UserError $e) {
        flash('danger', $e->getMessage());
        redirect(url($pageKey));
    }
    Audit::log('download', 'admin', 'backup', null, basename($wanted), ['page_key' => $pageKey]);
    Backup::download(basename($wanted));
}

$result = null;
$restored = null;

handle_post(function () use (&$result, &$restored, $pageKey, $canMake, $canRestore, $canDelete) {
    $do = (string)p('do');

    if ($do === 'backup') {
        if (!$canMake) {
            Access::denyPage($pageKey, 'make backups on');
        }
        $result = Backup::create(trim((string)p('note')), p('gzip') === '1', p('what') !== 'structure');
        $keep = (int)p('keep');
        $pruned = $keep > 0 ? Backup::prune($keep) : [];
        Audit::log('backup', 'admin', 'backup', null, $result['file'], ['page_key' => $pageKey,
            'reason' => trim((string)p('note')) ?: null,
            'changes' => [['field' => 'Backup written', 'old' => '', 'new' => $result['file'] . ' (' . backup_size($result['size']) . ', ' . $result['tables'] . ' tables, ' . number_format($result['rows']) . ' rows)']]]);
        flash('success', 'Backup written: ' . $result['file'] . ' (' . backup_size($result['size']) . ').'
            . ($pruned ? ' ' . count($pruned) . ' older backup(s) removed.' : ''));
        return;
    }

    if ($do === 'delete') {
        if (!$canDelete) {
            Access::denyPage($pageKey, 'delete backups on');
        }
        $file = (string)p('file');
        Backup::delete($file);
        Audit::log('delete', 'admin', 'backup', null, $file, ['page_key' => $pageKey, 'reason' => trim((string)p('reason')) ?: null]);
        flash('success', 'Backup ' . $file . ' deleted.');
        return;
    }

    if ($do === 'restore' || $do === 'restore_upload') {
        if (!$canRestore) {
            Access::denyPage($pageKey, 'restore the database from');
        }
        if (strtoupper(trim((string)p('confirm'))) !== 'RESTORE') {
            fail('Type RESTORE in the box to confirm. Nothing has been changed.');
        }
        if ($do === 'restore_upload') {
            $up = $_FILES['file'] ?? null;
            if (!$up || ($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                fail('Choose the backup file to restore.');
            }
            if (!preg_match('/\.sql(\.gz)?$/i', (string)$up['name'])) {
                fail('A backup file ends in .sql or .sql.gz.');
            }
            $path = Backup::dir() . '/uploaded_' . date('Ymd_His') . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$up['name']);
            if (!move_uploaded_file($up['tmp_name'], $path) && !rename($up['tmp_name'], $path)) {
                fail('The uploaded file could not be saved to the backups folder.');
            }
        } else {
            $path = Backup::path((string)p('file'));
        }
        $reason = trim((string)p('reason'));
        $restored = Backup::restore($path, p('safety') !== '0');
        Audit::log('restore', 'admin', 'backup', null, basename($path), ['page_key' => $pageKey, 'reason' => $reason ?: null,
            'changes' => [
                ['field' => 'Restored from', 'old' => '', 'new' => basename($path)],
                ['field' => 'Statements run', 'old' => '', 'new' => (string)$restored['statements']],
                ['field' => 'Backup taken first', 'old' => '', 'new' => $restored['safety']['file'] ?? 'not taken'],
            ]]);
        if ($restored['errors']) {
            flash('warning', 'The restore finished, but ' . count($restored['errors']) . ' statement(s) could not be run. Check the list below.');
        } else {
            flash('success', 'Restored from ' . basename($path) . ': ' . number_format($restored['statements']) . ' statement(s) run.');
        }
    }
});

function backup_size(int $bytes): string
{
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 0) . ' KB';
    }
    return $bytes . ' bytes';
}

$files = Backup::files();
$db = Backup::size();
$history = Backup::history(20);
$writable = is_dir(Backup::dir()) && is_writable(Backup::dir());
$uploadMax = min(
    (int)ini_get('upload_max_filesize') ?: 2,
    (int)ini_get('post_max_size') ?: 8
);

layout_start('Backup & Restore', ['actions' => btn_link(url('admin.utilities'), 'Verify & Rebuild Data', 'btn-outline-secondary', 'clipboard-check')]);
?>
<p class="text-muted">A backup is one <code>.sql</code> file holding every table and every row of
  <strong><?= h(Backup::dbName()) ?></strong>. Keep a copy off the server as well: download it, or copy it from the
  <code><?= h(Backup::dirLabel()) ?></code> folder. Restoring replaces what is in the database now with what is in the file.</p>

<?php if (!$writable): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i>
  The <code><?= h(Backup::dirLabel()) ?></code> folder cannot be written to, so backups cannot be made. Give the web server write
  permission on the <code>storage</code> folder (on most hosts, 0775).</div>
<?php endif; ?>

<div class="row g-3">
  <!-- ------------------------------------------------------------ make a backup -->
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header fw-semibold"><i class="bi bi-download"></i> Make a backup</div>
      <div class="card-body">
        <dl class="row small mb-3">
          <dt class="col-6">Database</dt><dd class="col-6 text-end"><?= h(Backup::dbName()) ?></dd>
          <dt class="col-6">Tables</dt><dd class="col-6 text-end"><?= (int)$db['tables'] ?></dd>
          <dt class="col-6">Size on disk</dt><dd class="col-6 text-end"><?= h(backup_size($db['bytes'])) ?></dd>
          <dt class="col-6">Backups kept here</dt><dd class="col-6 text-end"><?= count($files) ?></dd>
        </dl>
        <?php if ($canMake): ?>
        <form method="post" class="row g-2">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="backup">
          <?= input_field('note', 'What is this backup for? (optional)', '', ['class' => 'col-12', 'extra' => 'maxlength="200" placeholder="Before the month-end close"']) ?>
          <?= select_field('what', 'What to save', ['all' => 'Everything: structure and data', 'structure' => 'Structure only (no rows)'], 'all', ['class' => 'col-12']) ?>
          <?= select_field('keep', 'Backups to keep on the server', [0 => 'Keep them all', 5 => 'The newest 5', 10 => 'The newest 10', 20 => 'The newest 20'], 10, ['class' => 'col-12']) ?>
          <?php if (Backup::canCompress()): ?>
            <?= check_field('gzip', 'Compress the file (.sql.gz, much smaller)', true, 'col-12') ?>
          <?php endif; ?>
          <div class="col-12 mt-2">
            <button class="btn btn-primary w-100" <?= $writable ? '' : 'disabled' ?>><i class="bi bi-download"></i> Make the backup now</button>
          </div>
        </form>
        <p class="small text-muted mt-3 mb-0">A large database takes a while. Leave the page open until it finishes.</p>
        <?php else: ?>
        <p class="text-muted mb-0">Your role cannot make backups.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ------------------------------------------------------------ restore -->
  <div class="col-lg-7">
    <div class="card h-100 border-warning">
      <div class="card-header fw-semibold bg-warning-subtle"><i class="bi bi-arrow-counterclockwise"></i> Restore from a backup</div>
      <div class="card-body">
        <div class="alert alert-warning small">
          <strong>Read this first.</strong> A restore replaces every table in
          <strong><?= h(Backup::dbName()) ?></strong> with what the file holds. Anything entered since that backup was
          made is lost. Everyone else should be logged out first. A fresh backup of what is there now is made
          automatically before anything is changed, so a restore can be undone.
        </div>
        <?php if ($canRestore): ?>
        <ul class="nav nav-tabs" role="tablist">
          <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-here" type="button">From a backup kept here</button></li>
          <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-upload" type="button">From a file on my computer</button></li>
        </ul>
        <div class="tab-content pt-3">
          <div class="tab-pane fade show active" id="tab-here">
            <form method="post" class="row g-2">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="restore">
              <?= select_field('file', 'Backup to restore', array_combine(array_column($files, 'name'),
                    array_map(fn($f) => $f['name'] . '  -  ' . backup_size($f['size']) . ', ' . fdatetime(date('Y-m-d H:i:s', $f['time'])), $files)),
                    $files[0]['name'] ?? '', ['class' => 'col-12', 'required' => true]) ?>
              <?= input_field('reason', 'Reason (recorded in the audit trail)', '', ['class' => 'col-md-7', 'required' => true, 'extra' => 'maxlength="200"']) ?>
              <?= input_field('confirm', 'Type RESTORE to confirm', '', ['class' => 'col-md-5', 'required' => true, 'extra' => 'placeholder="RESTORE" autocomplete="off"']) ?>
              <div class="col-12">
                <button class="btn btn-warning" <?= $files ? '' : 'disabled' ?>
                  data-confirm="Replace the whole database with this backup? A backup of what is there now is made first."><i class="bi bi-arrow-counterclockwise"></i> Restore</button>
                <?php if (!$files): ?><span class="small text-muted ms-2">There are no backups on the server yet.</span><?php endif; ?>
              </div>
            </form>
          </div>
          <div class="tab-pane fade" id="tab-upload">
            <form method="post" enctype="multipart/form-data" class="row g-2">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="restore_upload">
              <div class="col-12">
                <label class="form-label">Backup file (.sql or .sql.gz)</label>
                <input class="form-control" type="file" name="file" accept=".sql,.gz,application/sql,application/gzip" required>
                <div class="form-text">This server accepts uploads up to about <?= (int)$uploadMax ?> MB. A larger file should be copied
                  into the <code><?= h(Backup::dirLabel()) ?></code> folder instead, and restored from the other tab.</div>
              </div>
              <?= input_field('reason', 'Reason (recorded in the audit trail)', '', ['class' => 'col-md-7', 'required' => true, 'extra' => 'maxlength="200"']) ?>
              <?= input_field('confirm', 'Type RESTORE to confirm', '', ['class' => 'col-md-5', 'required' => true, 'extra' => 'placeholder="RESTORE" autocomplete="off"']) ?>
              <div class="col-12">
                <button class="btn btn-warning" data-confirm="Replace the whole database with this file? A backup of what is there now is made first."><i class="bi bi-upload"></i> Upload and restore</button>
              </div>
            </form>
          </div>
        </div>
        <?php else: ?>
        <p class="text-muted mb-0">Only an administrator can restore the database.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($restored && $restored['errors']): ?>
<div class="card mt-3 border-danger">
  <div class="card-header fw-semibold">Statements that could not be run</div>
  <div class="card-body">
    <p class="small mb-2">The rest of the file was read back. Check these before working in the books again.</p>
    <ul class="small text-danger mb-0" style="max-height:260px;overflow:auto">
      <?php foreach ($restored['errors'] as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
</div>
<?php endif; ?>

<!-- ------------------------------------------------------------ the files -->
<div class="card mt-3">
  <div class="card-header fw-semibold"><i class="bi bi-hdd"></i> Backups on the server
    <span class="text-muted fw-normal small ms-2"><?= h(Backup::dirLabel()) ?></span></div>
  <div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
    <thead><tr><th>File</th><th>Made</th><th class="text-end">Size</th><th style="width:1%"></th></tr></thead>
    <tbody>
    <?php if (!$files): ?>
      <tr><td colspan="4" class="text-center text-muted py-4">No backups yet. Make one above.</td></tr>
    <?php endif; ?>
    <?php foreach ($files as $f): ?>
      <tr>
        <td class="text-nowrap"><i class="bi bi-file-earmark-zip<?= str_ends_with($f['name'], '.gz') ? '-fill' : '' ?> text-secondary"></i>
          <?= h($f['name']) ?>
          <?= str_starts_with($f['name'], 'uploaded_') ? badge('uploaded', 'info') : '' ?></td>
        <td class="text-nowrap"><?= h(fdatetime(date('Y-m-d H:i:s', $f['time']))) ?></td>
        <td class="text-end text-nowrap"><?= h(backup_size($f['size'])) ?></td>
        <td class="text-nowrap text-end">
          <?php if (Access::can($pageKey, 'print')): ?>
            <a class="btn btn-sm btn-outline-primary" href="<?= h(url($pageKey, ['a' => 'download', 'file' => $f['name']])) ?>"><i class="bi bi-download"></i> Download</a>
          <?php endif; ?>
          <?php if ($canDelete): ?>
            <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#delBackup"
              data-file="<?= h($f['name']) ?>"><i class="bi bi-trash"></i></button>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<!-- ------------------------------------------------------------ the log -->
<div class="card mt-3">
  <div class="card-header fw-semibold"><i class="bi bi-clock-history"></i> Backup history</div>
  <div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th>When</th><th>What</th><th>File</th><th class="text-end">Tables</th><th class="text-end">Rows</th><th>By</th><th>Note</th></tr></thead>
    <tbody>
    <?php if (!$history): ?><tr><td colspan="7" class="text-center text-muted py-3">Nothing yet.</td></tr><?php endif; ?>
    <?php foreach ($history as $b): ?>
      <tr>
        <td class="text-nowrap"><?= h(fdatetime($b['created_at'])) ?></td>
        <td><?= $b['action'] === 'restore' ? badge('Restore', 'warning') : ($b['action'] === 'delete' ? badge('Deleted', 'secondary') : badge('Backup', 'success')) ?></td>
        <td class="small"><?= h($b['file_name']) ?></td>
        <td class="text-end"><?= (int)$b['tables_count'] ?></td>
        <td class="text-end"><?= number_format((int)$b['rows_count']) ?></td>
        <td><?= h((string)($b['username'] ?? '')) ?></td>
        <td class="small text-muted"><?= h((string)($b['note'] ?? '')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="card mt-3">
  <div class="card-body small text-muted">
    <p class="mb-1"><strong>Keeping the books safe.</strong> Make a backup before the month-end close, before a
      price change run, and before any import. Download a copy every week and keep it somewhere other than this server.</p>
    <p class="mb-0">A backup made here can also be read back with phpMyAdmin (Import) or Navicat (Execute SQL File),
      and a file those programs wrote can be restored here. Compressed files (<code>.sql.gz</code>) are read back here directly.</p>
  </div>
</div>

<?= $canDelete ? reason_modal('delBackup', 'Delete this backup', current_url(),
      'btn-danger', '<input type="hidden" name="do" value="delete"><input type="hidden" name="file" id="delBackupFile">') : '' ?>

<script>
document.addEventListener('show.bs.modal', function (e) {
  if (e.target.id === 'delBackup') {
    var f = e.relatedTarget && e.relatedTarget.getAttribute('data-file');
    var box = document.getElementById('delBackupFile');
    if (box) { box.value = f || ''; }
    var title = e.target.querySelector('.modal-title');
    if (title && f) { title.textContent = 'Delete ' + f; }
  }
});
</script>
<?php
layout_end();
