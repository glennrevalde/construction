<?php
declare(strict_types=1);

$pageKey = 'admin.roles';
$a = g('a');
$pages = DB::all('SELECT p.*, m.code AS module_code, m.name AS module_name FROM pages p JOIN modules m ON m.id = p.module_id ORDER BY m.sort_order, p.sort_order');
$acts = ['allowed' => 'View', 'can_create' => 'Create', 'can_edit' => 'Edit', 'can_delete' => 'Delete/Void', 'can_print' => 'Print/Export', 'can_approve' => 'Approve'];

/** "page: VCEDPA" strings for audit comparisons */
function role_matrix_text(int $roleId): array
{
    $out = [];
    foreach (DB::all('SELECT p.page_key, r.* FROM role_page_access r JOIN pages p ON p.id = r.page_id WHERE r.role_id = :r AND r.allowed = 1 ORDER BY p.page_key', ['r' => $roleId]) as $x) {
        $out[$x['page_key']] = 'V' . ($x['can_create'] ? 'C' : '') . ($x['can_edit'] ? 'E' : '') . ($x['can_delete'] ? 'D' : '') . ($x['can_print'] ? 'P' : '') . ($x['can_approve'] ? 'A' : '');
    }
    return $out;
}

if ($a === 'delete' && is_post()) {
    Access::require($pageKey, 'delete');
    $role = DB::row('SELECT * FROM roles WHERE id = :id', ['id' => (int)g('id')]);
    $users = $role ? (int)DB::value('SELECT COUNT(*) FROM user_roles WHERE role_id = :r', ['r' => $role['id']]) : 0;
    if (!$role || (int)$role['is_system'] === 1 || $users > 0) {
        flash('danger', 'Only custom roles with no users can be deleted.');
    } else {
        $snap = $role + ['pages' => role_matrix_text((int)$role['id'])];
        DB::run('DELETE FROM roles WHERE id = :id', ['id' => $role['id']]);
        Audit::log('role_delete', 'admin', 'role', (int)$role['id'], $role['name'], ['snapshot' => $snap, 'reason' => p('reason') ?: null, 'page_key' => $pageKey]);
        flash('success', 'Role deleted.');
    }
    redirect(url($pageKey));
}

if (in_array($a, ['new', 'edit', 'copy'], true)) {
    $role = null;
    $source = null;
    if ($a === 'edit') {
        Access::require($pageKey, 'edit');
        $role = DB::row('SELECT * FROM roles WHERE id = :id', ['id' => (int)g('id')]);
        if (!$role) {
            redirect(url($pageKey));
        }
        $source = $role;
    } else {
        Access::require($pageKey, 'create');
        if ($a === 'copy') {
            $source = DB::row('SELECT * FROM roles WHERE id = :id', ['id' => (int)g('id')]);
        }
    }
    $matrix = [];
    $special = [];
    $roleSites = [];
    if ($source) {
        foreach (DB::all('SELECT * FROM role_page_access WHERE role_id = :r', ['r' => $source['id']]) as $x) {
            $matrix[(int)$x['page_id']] = $x;
        }
        $special = DB::pairs('SELECT perm_key, perm_value FROM role_special_permissions WHERE role_id = :r', ['r' => $source['id']]);
        $roleSites = array_map('intval', DB::column('SELECT site_id FROM role_sites WHERE role_id = :r', ['r' => $source['id']]));
    }
    $sod = [];

    handle_post(function () use ($role, $pages, $acts, $pageKey, &$sod) {
        $name = mb_substr(trim((string)p('name')), 0, 60);
        if ($name === '') {
            fail('Enter the role name.');
        }
        if (DB::value('SELECT id FROM roles WHERE name = :n AND id <> :id', ['n' => $name, 'id' => $role['id'] ?? 0])) {
            fail('Another role has this name.');
        }
        $isAdmin = p('is_admin') === '1' ? 1 : 0;
        if ($role && (int)$role['is_admin'] === 1 && !$isAdmin) {
            $others = (int)DB::value("SELECT COUNT(*) FROM roles WHERE is_admin = 1 AND id <> :id", ['id' => $role['id']]);
            if (!$others) {
                fail('At least one role must keep full administrator access.');
            }
        }
        $grid = (array)($_POST['perm'] ?? []);
        $rights = [];
        foreach ($pages as $pg) {
            $row = $grid[$pg['id']] ?? [];
            if (!empty($row['allowed'])) {
                $rights[$pg['page_key']] = ['allowed' => true, 'create' => !empty($row['can_create']), 'edit' => !empty($row['can_edit']),
                    'delete' => !empty($row['can_delete']), 'print' => !empty($row['can_print']), 'approve' => !empty($row['can_approve'])];
            }
        }
        $sod = $isAdmin ? [] : Access::sodConflicts($rights);
        if ($sod && p('sod_ack') !== '1') {
            fail('This role combines duties that should be kept apart. Review the warning and tick the box to save anyway.');
        }
        $roleId = DB::tx(function () use ($role, $name, $isAdmin, $pages, $grid, $pageKey, $sod) {
            $data = ['name' => $name, 'description' => p('description') ?: null, 'is_admin' => $isAdmin, 'all_sites' => p('all_sites', '1') === '1' ? 1 : 0];
            $before = [];
            if ($role) {
                $before = $role + ['pages' => role_matrix_text((int)$role['id']),
                    'special' => DB::pairs('SELECT perm_key, perm_value FROM role_special_permissions WHERE role_id = :r', ['r' => $role['id']]),
                    'sites' => implode(',', DB::column('SELECT site_id FROM role_sites WHERE role_id = :r ORDER BY site_id', ['r' => $role['id']]))];
                DB::update('roles', $data, 'id = :id', ['id' => $role['id']]);
                $id = (int)$role['id'];
            } else {
                $id = DB::insert('roles', $data);
            }
            DB::run('DELETE FROM role_page_access WHERE role_id = :r', ['r' => $id]);
            foreach ($pages as $pg) {
                $row = $grid[$pg['id']] ?? [];
                if (empty($row['allowed'])) {
                    continue;
                }
                DB::insert('role_page_access', [
                    'role_id' => $id, 'page_id' => $pg['id'], 'allowed' => 1,
                    'can_create' => empty($row['can_create']) ? 0 : 1, 'can_edit' => empty($row['can_edit']) ? 0 : 1,
                    'can_delete' => empty($row['can_delete']) ? 0 : 1, 'can_print' => empty($row['can_print']) ? 0 : 1,
                    'can_approve' => empty($row['can_approve']) ? 0 : 1,
                ]);
            }
            DB::run('DELETE FROM role_special_permissions WHERE role_id = :r', ['r' => $id]);
            foreach (Access::SPECIAL as $k => [$label, $type]) {
                $v = $type === 'bool' ? (p('sp_' . $k) === '1' ? '1' : '0') : trim((string)p('sp_' . $k));
                if ($type === 'number' && $v !== '' && !is_numeric($v)) {
                    fail("\"$label\" must be a number or blank.");
                }
                DB::insert('role_special_permissions', ['role_id' => $id, 'perm_key' => $k, 'perm_value' => $v]);
            }
            DB::run('DELETE FROM role_sites WHERE role_id = :r', ['r' => $id]);
            if ($data['all_sites'] === 0) {
                foreach ((array)($_POST['sites'] ?? []) as $s) {
                    DB::insert('role_sites', ['role_id' => $id, 'site_id' => (int)$s]);
                }
            }
            $after = $data + ['pages' => role_matrix_text($id),
                'special' => DB::pairs('SELECT perm_key, perm_value FROM role_special_permissions WHERE role_id = :r', ['r' => $id]),
                'sites' => implode(',', DB::column('SELECT site_id FROM role_sites WHERE role_id = :r ORDER BY site_id', ['r' => $id]))];
            Audit::log($role ? 'role_edit' : 'role_create', 'admin', 'role', $id, $name, [
                'before' => $before, 'after' => $after, 'snapshot' => $role ? null : $after, 'page_key' => $pageKey,
                'reason' => $sod ? 'Segregation of duties warning accepted: ' . implode('; ', $sod) : null,
            ]);
            if ($sod) {
                Audit::log('sod_override', 'admin', 'role', $id, $name, ['reason' => implode('; ', $sod), 'page_key' => $pageKey]);
            }
            return $id;
        });
        Access::reset();
        flash('success', 'Role saved. Every user with this role is updated at once.');
        redirect_after_save(url($pageKey, ['a' => 'new']), url($pageKey, ['a' => 'edit', 'id' => $roleId]));
    });

    if (is_post()) {
        $matrix = [];
        foreach ((array)($_POST['perm'] ?? []) as $pid => $row) {
            $matrix[(int)$pid] = $row;
        }
        foreach (Access::SPECIAL as $k => $_) {
            $special[$k] = (string)p('sp_' . $k);
        }
        $roleSites = array_map('intval', (array)($_POST['sites'] ?? []));
    }
    $title = $role ? 'Role: ' . $role['name'] : ($source ? 'Copy of ' . $source['name'] : 'New role');
    $users = $role ? DB::all('SELECT u.id, u.full_name, u.username, u.status FROM users u JOIN user_roles ur ON ur.user_id = u.id WHERE ur.role_id = :r ORDER BY u.full_name', ['r' => $role['id']]) : [];
    layout_start($title, ['actions' => ($role ? page_link('admin.page_access', ['role_id' => $role['id']], 'Page access view', 'btn-outline-primary', 'diagram-3') . ' ' : '') . btn_link(url($pageKey), 'Back')]);
    $byModule = [];
    foreach ($pages as $pg) {
        $byModule[$pg['module_code']]['name'] = $pg['module_name'];
        $byModule[$pg['module_code']]['pages'][] = $pg;
    }
    ?>
    <form method="post"><?= csrf_field() ?>
    <div class="card mb-3"><div class="card-body row g-2">
      <?= input_field('name', 'Role name', fv('name', $role, $source ? $source['name'] . ' (copy)' : ''), ['class' => 'col-md-4', 'required' => true]) ?>
      <?= input_field('description', 'What the role can do', fv('description', $role, $source['description'] ?? ''), ['class' => 'col-md-8']) ?>
      <?= check_field('is_admin', 'Administrator (full access to everything)', fv('is_admin', $role, $source['is_admin'] ?? 0), 'col-md-4') ?>
      <div class="col-md-8"><label class="form-label">Sites</label>
        <div class="d-flex flex-wrap gap-3 small">
          <label><input type="radio" name="all_sites" value="1" <?= (int)fv('all_sites', $role, $source['all_sites'] ?? 1) === 1 ? 'checked' : '' ?>> All sites</label>
          <label><input type="radio" name="all_sites" value="0" <?= (int)fv('all_sites', $role, $source['all_sites'] ?? 1) === 0 ? 'checked' : '' ?>> Only:</label>
          <?php foreach (DB::pairs('SELECT id, name FROM sites ORDER BY id') as $sid => $sn): ?>
            <label><input type="checkbox" name="sites[]" value="<?= (int)$sid ?>" <?= in_array((int)$sid, $roleSites, true) ? 'checked' : '' ?>> <?= h($sn) ?></label>
          <?php endforeach; ?>
        </div></div>
    </div></div>

    <div class="card mb-3"><div class="card-header fw-semibold">Sensitive permissions (set separately from the grid)</div><div class="card-body row g-2">
      <?php foreach (Access::SPECIAL as $k => [$label, $type]): ?>
        <?php if ($type === 'bool'): ?>
          <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="sp_<?= h($k) ?>" value="1" id="sp_<?= h($k) ?>" <?= ($special[$k] ?? '0') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="sp_<?= h($k) ?>"><?= h($label) ?></label></div></div>
        <?php else: ?>
          <div class="col-md-4"><label class="form-label"><?= h($label) ?></label><input class="form-control form-control-sm" name="sp_<?= h($k) ?>" value="<?= h($special[$k] ?? '') ?>" placeholder="blank = no limit"></div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div></div>

    <div class="card mb-3"><div class="card-header d-flex justify-content-between"><span class="fw-semibold">Permissions by page</span><span class="small text-muted">View = page access (the page appears in the menu and can be opened)</span></div>
      <div class="table-responsive"><table class="table table-sm mb-0">
        <thead class="sticky-top bg-white"><tr><th>Page</th><?php foreach ($acts as $lbl): ?><th class="text-center"><?= h($lbl) ?></th><?php endforeach; ?></tr></thead>
        <?php foreach ($byModule as $mcode => $mod): ?>
          <tr class="table-light"><td class="fw-semibold"><?= h($mod['name']) ?></td>
            <?php foreach ($acts as $col => $lbl): ?><td class="text-center"><input type="checkbox" class="form-check-input" title="All <?= h($lbl) ?> in <?= h($mod['name']) ?>" data-check-all='input[data-mod="<?= h($mcode) ?>"][data-col="<?= h($col) ?>"]'></td><?php endforeach; ?></tr>
          <?php foreach ($mod['pages'] as $pg): $row = $matrix[(int)$pg['id']] ?? []; ?>
            <tr><td class="ps-4 small"><?= h($pg['name']) ?> <span class="text-muted">(<?= h($pg['page_type']) ?>)</span></td>
              <?php foreach ($acts as $col => $lbl): ?>
                <td class="text-center"><input type="checkbox" class="form-check-input" data-mod="<?= h($mcode) ?>" data-col="<?= h($col) ?>" name="perm[<?= (int)$pg['id'] ?>][<?= h($col) ?>]" value="1" <?= !empty($row[$col]) ? 'checked' : '' ?>></td>
              <?php endforeach; ?></tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </table></div>
    </div>
    <?php if ($sod): ?>
      <div class="alert alert-warning"><strong>Segregation of duties.</strong> This role combines: <?= h(implode('; ', $sod)) ?>.
        <div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="sod_ack" value="1" id="sodAck"><label class="form-check-label" for="sodAck">Save anyway (the warning and the decision are recorded in the audit trail)</label></div></div>
    <?php endif; ?>
    <div class="sticky-actions"><?= save_buttons('Save role', url($pageKey), Access::can($pageKey, 'create')) ?></div>
    </form>
    <?php if ($users): ?>
    <div class="card mt-3"><div class="card-header">Users with this role (<?= count($users) ?>)</div><ul class="list-group list-group-flush">
      <?php foreach ($users as $u): ?><li class="list-group-item small"><?= safe_link('admin.users', ['a' => 'edit', 'id' => $u['id']], $u['full_name'] . ' (' . $u['username'] . ')') ?> <?= status_badge($u['status']) ?></li><?php endforeach; ?>
    </ul></div>
    <?php endif; ?>
    <?php
    layout_end();
    return;
}

$roles = DB::all('SELECT r.*, (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) AS users,
                         (SELECT COUNT(*) FROM role_page_access x WHERE x.role_id = r.id AND x.allowed = 1) AS pages
                    FROM roles r ORDER BY r.is_admin DESC, r.name');
$totalPages = count($pages);
layout_start('User Role Configuration', ['actions' => page_link($pageKey, ['a' => 'new'], 'New role', 'btn-primary', 'plus-lg', 'create')]);
?>
<p class="text-muted">A role is a named set of permissions that matches a job. Set permissions once on the role, then assign the role to users; changing a role updates every user who has it.</p>
<div class="card"><table class="table table-sm table-hover mb-0">
  <thead><tr><th>Role</th><th>What the role can do</th><th class="num">Pages</th><th class="num">Users</th><th>Sites</th><th></th></tr></thead>
  <?php foreach ($roles as $r): ?>
    <tr><td><a href="<?= h(url($pageKey, ['a' => 'edit', 'id' => $r['id']])) ?>"><?= h($r['name']) ?></a> <?= $r['is_admin'] ? badge('full access', 'danger') : '' ?> <?= $r['is_system'] ? badge('standard', 'light') : '' ?></td>
      <td class="small"><?= h($r['description']) ?></td><td class="num"><?= $r['is_admin'] ? $totalPages : (int)$r['pages'] ?> / <?= $totalPages ?></td><td class="num"><?= (int)$r['users'] ?></td>
      <td class="small"><?= (int)$r['all_sites'] ? 'All' : h(implode(', ', DB::column('SELECT s.code FROM role_sites rs JOIN sites s ON s.id = rs.site_id WHERE rs.role_id = :r', ['r' => $r['id']]))) ?></td>
      <td class="text-nowrap small">
        <?= page_link($pageKey, ['a' => 'copy', 'id' => $r['id']], 'Copy', 'btn-link btn-sm p-0', '', 'create') ?>
        <?php if (!$r['is_system'] && !(int)$r['users'] && Access::can($pageKey, 'delete')): ?>
          <form method="post" action="<?= h(url($pageKey, ['a' => 'delete', 'id' => $r['id']])) ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-link btn-sm p-0 text-danger" data-confirm="Delete this role?">Delete</button></form>
        <?php endif; ?>
      </td></tr>
  <?php endforeach; ?>
</table></div>
<?php
layout_end();
