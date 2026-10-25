<?php
declare(strict_types=1);

$pageKey = 'admin.page_access';
$mode = in_array(g('mode'), ['role', 'user', 'matrix'], true) ? g('mode') : (g('user_id') ? 'user' : 'role');
$pages = DB::all('SELECT p.*, m.code AS module_code, m.name AS module_name FROM pages p JOIN modules m ON m.id = p.module_id ORDER BY m.sort_order, p.sort_order');
$roles = DB::all('SELECT * FROM roles ORDER BY is_admin DESC, name');
$byModule = [];
foreach ($pages as $pg) {
    $byModule[$pg['module_code']]['name'] = $pg['module_name'];
    $byModule[$pg['module_code']]['pages'][] = $pg;
}
$typeLabel = ['center' => 'center', 'transaction' => 'transaction screen', 'list' => 'list', 'report' => 'report', 'setup' => 'setup screen', 'admin' => 'setup screen'];

// ------------------------------------------------------------------ save role page access
if ($mode === 'role' && is_post()) {
    Access::require($pageKey, 'edit');
    $role = DB::row('SELECT * FROM roles WHERE id = :id', ['id' => (int)p('role_id')]);
    if ($role && !(int)$role['is_admin']) {
        $changes = [];
        DB::tx(function () use ($role, $pages, &$changes) {
            $current = DB::pairs('SELECT page_id, allowed FROM role_page_access WHERE role_id = :r', ['r' => $role['id']]);
            foreach ($pages as $pg) {
                $want = (($_POST['access'][$pg['id']] ?? 'deny') === 'allow') ? 1 : 0;
                $had = (int)($current[$pg['id']] ?? 0);
                if ($want === $had) {
                    continue;
                }
                if (isset($current[$pg['id']])) {
                    DB::update('role_page_access', $want ? ['allowed' => 1] : ['allowed' => 0, 'can_create' => 0, 'can_edit' => 0, 'can_delete' => 0, 'can_print' => 0, 'can_approve' => 0],
                        'role_id = :r AND page_id = :p', ['r' => $role['id'], 'p' => $pg['id']]);
                } else {
                    DB::insert('role_page_access', ['role_id' => $role['id'], 'page_id' => $pg['id'], 'allowed' => 1, 'can_print' => 1]);
                }
                $changes[] = ['field' => $pg['name'], 'old' => $had ? 'Allowed' : 'Denied', 'new' => $want ? 'Allowed' : 'Denied'];
            }
            if ($changes) {
                Audit::log('page_access_change', 'admin', 'role', (int)$role['id'], 'Role: ' . $role['name'], ['changes' => $changes, 'reason' => p('reason') ?: null]);
            }
        });
        Access::reset();
        flash('success', count($changes) . ' page setting(s) changed for ' . $role['name'] . '. Changes apply the next time users open a page.');
    }
    redirect(url($pageKey, ['mode' => 'role', 'role_id' => p('role_id')]));
}

// ------------------------------------------------------------------ save user overrides
if ($mode === 'user' && is_post()) {
    Access::require($pageKey, 'edit');
    $user = DB::row('SELECT * FROM users WHERE id = :id', ['id' => (int)p('user_id')]);
    try {
        if (!$user) {
            fail('Choose a user.');
        }
        $reason = trim((string)p('reason'));
        $changes = [];
        $existing = [];
        foreach (DB::all('SELECT * FROM user_page_overrides WHERE user_id = :u', ['u' => $user['id']]) as $o) {
            $existing[(int)$o['page_id']] = $o;
        }
        $isAdminUser = Access::isAdmin((int)$user['id']);
        DB::tx(function () use ($user, $pages, $existing, $reason, $isAdminUser, &$changes) {
            foreach ($pages as $pg) {
                $sel = $_POST['ov'][$pg['id']] ?? 'inherit';
                $old = $existing[(int)$pg['id']] ?? null;
                $oldLabel = $old ? ((int)$old['allowed'] ? 'Override: allowed' : 'Override: denied') : 'Inherit from role';
                if ($sel === 'inherit') {
                    if ($old) {
                        DB::run('DELETE FROM user_page_overrides WHERE user_id = :u AND page_id = :p', ['u' => $user['id'], 'p' => $pg['id']]);
                        $changes[] = ['field' => $pg['name'], 'old' => $oldLabel, 'new' => 'Inherit from role'];
                    }
                    continue;
                }
                if ($sel === 'deny' && $isAdminUser && in_array($pg['page_key'], ['admin.page_access', 'admin.users', 'admin.roles'], true) && (int)$user['id'] === Auth::id()) {
                    fail('You cannot deny yourself access to the administration pages.');
                }
                $acts = (array)($_POST['ovact'][$pg['id']] ?? []);
                $row = [
                    'allowed' => $sel === 'allow' ? 1 : 0,
                    'can_create' => $sel === 'allow' && !empty($acts['create']) ? 1 : 0,
                    'can_edit' => $sel === 'allow' && !empty($acts['edit']) ? 1 : 0,
                    'can_delete' => $sel === 'allow' && !empty($acts['delete']) ? 1 : 0,
                    'can_print' => $sel === 'allow' && !empty($acts['print']) ? 1 : 0,
                    'can_approve' => $sel === 'allow' && !empty($acts['approve']) ? 1 : 0,
                ];
                $newLabel = ($sel === 'allow' ? 'Override: allowed' : 'Override: denied')
                    . ($sel === 'allow' ? ' [' . implode('', array_map(fn($k) => $row['can_' . $k] ? strtoupper($k[0]) : '', ['create', 'edit', 'delete', 'print', 'approve'])) . ']' : '');
                $oldFull = $old ? $oldLabel . ((int)$old['allowed'] ? ' [' . implode('', array_map(fn($k) => (int)$old['can_' . $k] ? strtoupper($k[0]) : '', ['create', 'edit', 'delete', 'print', 'approve'])) . ']' : '') : $oldLabel;
                if ($oldFull === $newLabel) {
                    continue;
                }
                if ($old) {
                    DB::update('user_page_overrides', $row + ['reason' => $reason ?: $old['reason'], 'created_by' => Auth::id()], 'user_id = :u AND page_id = :p', ['u' => $user['id'], 'p' => $pg['id']]);
                } else {
                    DB::insert('user_page_overrides', $row + ['user_id' => $user['id'], 'page_id' => $pg['id'], 'reason' => $reason ?: null, 'created_by' => Auth::id()]);
                }
                $changes[] = ['field' => $pg['name'], 'old' => $oldFull, 'new' => $newLabel];
            }
            if ($changes) {
                Audit::log('page_access_override', 'admin', 'user', (int)$user['id'], 'User: ' . $user['username'], ['changes' => $changes, 'reason' => $reason ?: null]);
            }
        });
        Access::reset();
        flash('success', count($changes) . ' override(s) changed for ' . $user['username'] . '.');
    } catch (UserError $e) {
        flash('danger', $e->getMessage());
    }
    redirect(url($pageKey, ['mode' => 'user', 'user_id' => p('user_id')]));
}

layout_start('Page Access Configuration', ['actions' => page_link('admin.effective_access', [], 'Effective access', 'btn-outline-primary', 'eye') . ' ' . page_link('admin.roles', [], 'Role permissions', 'btn-outline-secondary', 'people')]);
?>
<p class="text-muted">Decide which pages each role or user can open. Denied pages are hidden from the menu, home screen, search and report drill-downs; opening one another way shows "access denied" and is recorded in the audit trail.
A user-level override always takes precedence over the user's roles.</p>
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $mode === 'role' ? 'active' : '' ?>" href="<?= h(url($pageKey, ['mode' => 'role'])) ?>">By role</a></li>
  <li class="nav-item"><a class="nav-link <?= $mode === 'user' ? 'active' : '' ?>" href="<?= h(url($pageKey, ['mode' => 'user'])) ?>">By user (overrides)</a></li>
  <li class="nav-item"><a class="nav-link <?= $mode === 'matrix' ? 'active' : '' ?>" href="<?= h(url($pageKey, ['mode' => 'matrix'])) ?>">Matrix (all roles)</a></li>
</ul>
<?php
if ($mode === 'role'):
    $roleId = (int)(g('role_id') ?: ($roles[1]['id'] ?? $roles[0]['id']));
    $role = DB::row('SELECT * FROM roles WHERE id = :id', ['id' => $roleId]);
    $allowed = DB::pairs('SELECT page_id, allowed FROM role_page_access WHERE role_id = :r', ['r' => $roleId]);
    $canEdit = Access::can($pageKey, 'edit') && !(int)$role['is_admin'];
?>
<form method="get" class="mb-3 filters"><input type="hidden" name="p" value="<?= h($pageKey) ?>"><input type="hidden" name="mode" value="role">
  <select class="form-select form-select-sm w-auto d-inline" name="role_id" data-autosubmit><?= options(array_column($roles, 'name', 'id'), $roleId) ?></select></form>
<?php if ((int)$role['is_admin']): ?><div class="alert alert-info">The <?= h($role['name']) ?> role has full access to every page.</div><?php endif; ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="role_id" value="<?= (int)$roleId ?>">
<fieldset <?= $canEdit ? '' : 'disabled' ?>>
<?php foreach ($byModule as $mcode => $mod):
    $cnt = count(array_filter($mod['pages'], fn($pg) => (int)$role['is_admin'] || !empty($allowed[$pg['id']])));
?>
  <details class="card mb-2 tree-module" <?= $cnt ? 'open' : '' ?>>
    <summary class="card-header d-flex justify-content-between align-items-center"><span><?= h($mod['name']) ?> <span class="badge text-bg-light"><?= $cnt ?> / <?= count($mod['pages']) ?> allowed</span></span>
      <?php if ($canEdit): ?><span class="d-flex gap-1"><button type="button" class="btn btn-sm btn-outline-success" data-module-toggle="<?= h($mcode) ?>" data-value="allow">Allow module</button>
        <button type="button" class="btn btn-sm btn-outline-danger" data-module-toggle="<?= h($mcode) ?>" data-value="deny">Deny module</button></span><?php endif; ?></summary>
    <table class="table table-sm mb-0">
      <?php foreach ($mod['pages'] as $pg): $on = (int)$role['is_admin'] || !empty($allowed[$pg['id']]); ?>
        <tr><td class="ps-4"><?= h($pg['name']) ?> <span class="small text-muted"><?= h($typeLabel[$pg['page_type']] ?? $pg['page_type']) ?><?= $pg['menu_group'] && $pg['page_type'] === 'report' ? ', ' . h($pg['menu_group']) : '' ?></span></td>
          <td style="width:160px"><select class="form-select form-select-sm <?= $on ? 'text-success' : 'text-danger' ?>" name="access[<?= (int)$pg['id'] ?>]" data-module="<?= h($mcode) ?>" onchange="this.className='form-select form-select-sm '+(this.value==='allow'?'text-success':'text-danger')">
            <option value="allow" <?= $on ? 'selected' : '' ?>>Allowed</option><option value="deny" <?= $on ? '' : 'selected' ?>>Denied</option></select></td></tr>
      <?php endforeach; ?>
    </table>
  </details>
<?php endforeach; ?>
<?php if ($canEdit): ?>
  <div class="sticky-actions d-flex gap-2"><input class="form-control form-control-sm" style="max-width:360px" name="reason" placeholder="Reason for the change (recorded in the audit trail)"><button class="btn btn-primary">Save page access</button></div>
  <p class="small text-muted">Newly allowed pages are view-and-print only; set create, edit, delete and approve rights under User Role Configuration.</p>
<?php endif; ?>
</fieldset>
</form>

<?php elseif ($mode === 'user'):
    $userId = (int)g('user_id');
    $users = DB::pairs("SELECT id, CONCAT(full_name, ' (', username, ')') FROM users WHERE is_platform = 0 ORDER BY full_name");
?>
<form method="get" class="mb-3 filters"><input type="hidden" name="p" value="<?= h($pageKey) ?>"><input type="hidden" name="mode" value="user">
  <select class="form-select form-select-sm w-auto d-inline" name="user_id" data-autosubmit><?= options($users, $userId, 'Choose a user…') ?></select></form>
<?php if ($userId):
    $roleNames = implode(', ', Access::roleNames($userId));
    // what the roles alone give (ignoring overrides)
    $roleAllowed = [];
    $isAdminU = Access::isAdmin($userId);
    foreach (DB::all('SELECT rpa.page_id, r.name FROM role_page_access rpa JOIN user_roles ur ON ur.role_id = rpa.role_id AND ur.user_id = :u JOIN roles r ON r.id = rpa.role_id WHERE rpa.allowed = 1', ['u' => $userId]) as $x) {
        $roleAllowed[(int)$x['page_id']][] = $x['name'];
    }
    $ov = [];
    foreach (DB::all('SELECT o.*, u.username AS by_user FROM user_page_overrides o LEFT JOIN users u ON u.id = o.created_by WHERE o.user_id = :u', ['u' => $userId]) as $o) {
        $ov[(int)$o['page_id']] = $o;
    }
    $canEdit = Access::can($pageKey, 'edit');
?>
<div class="alert alert-light border small">Roles: <strong><?= h($roleNames ?: 'none') ?></strong><?= $isAdminU ? ' (full access unless denied below)' : '' ?>. <?= count($ov) ?> override(s). Rows with an override are highlighted.</div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int)$userId ?>">
<fieldset <?= $canEdit ? '' : 'disabled' ?>>
<?php foreach ($byModule as $mcode => $mod): ?>
  <details class="card mb-2 tree-module" <?= array_filter($mod['pages'], fn($pg) => isset($ov[(int)$pg['id']])) ? 'open' : '' ?>>
    <summary class="card-header"><?= h($mod['name']) ?></summary>
    <table class="table table-sm mb-0">
      <thead><tr><th>Page</th><th>From roles</th><th style="width:190px">Setting for this user</th><th>Actions when allowed by override</th><th>Set by</th></tr></thead>
      <?php foreach ($mod['pages'] as $pg): $pid = (int)$pg['id']; $o = $ov[$pid] ?? null; $fromRole = $isAdminU || isset($roleAllowed[$pid]); ?>
        <tr class="<?= $o ? 'override-flag' : '' ?>">
          <td class="ps-4"><?= h($pg['name']) ?></td>
          <td class="small"><?= $fromRole ? '<span class="text-success">Allowed</span> <span class="text-muted">(' . h($isAdminU ? 'Administrator' : implode(', ', $roleAllowed[$pid])) . ')</span>' : '<span class="text-danger">Denied</span>' ?></td>
          <td><select class="form-select form-select-sm" name="ov[<?= $pid ?>]">
            <option value="inherit" <?= !$o ? 'selected' : '' ?>>Inherit from role</option>
            <option value="allow" <?= $o && (int)$o['allowed'] ? 'selected' : '' ?>>Override: allow</option>
            <option value="deny" <?= $o && !(int)$o['allowed'] ? 'selected' : '' ?>>Override: deny</option></select></td>
          <td class="small text-nowrap"><?php foreach (['create' => 'C', 'edit' => 'E', 'delete' => 'D', 'print' => 'P', 'approve' => 'A'] as $k => $l): ?>
            <label class="me-1" title="<?= h(Access::ACTIONS[$k]) ?>"><input type="checkbox" name="ovact[<?= $pid ?>][<?= $k ?>]" value="1" <?= $o && (int)$o['can_' . $k] ? 'checked' : ($o ? '' : ($k === 'print' ? 'checked' : '')) ?>> <?= $l ?></label>
          <?php endforeach; ?></td>
          <td class="small text-muted"><?= $o ? h($o['by_user']) . ', ' . h(fdate($o['created_at'])) . ($o['reason'] ? ', ' . h($o['reason']) : '') : '' ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </details>
<?php endforeach; ?>
<?php if ($canEdit): ?>
  <div class="sticky-actions d-flex gap-2"><input class="form-control form-control-sm" style="max-width:360px" name="reason" placeholder="Reason for the override"><button class="btn btn-primary">Save overrides</button>
    <?= page_link('admin.effective_access', ['user_id' => $userId], 'Check effective access', 'btn-outline-primary', 'eye') ?></div>
<?php endif; ?>
</fieldset>
</form>
<?php endif; ?>

<?php else:
    $matrix = [];
    foreach (DB::all('SELECT role_id, page_id FROM role_page_access WHERE allowed = 1') as $x) {
        $matrix[(int)$x['page_id']][(int)$x['role_id']] = true;
    }
    $only = g('pages') === 'key' ? ['coa.accounts', 'cust.center', 'cust.payments', 'vend.bills', 'vend.pay_bills', 'inv.adjust', 'pos.register', 'bank.reconcile', 'rpt.inv_valuation_summary', 'admin.audit_trail', 'admin.roles'] : null;
?>
<div class="mb-2 small"><a href="<?= h(url($pageKey, ['mode' => 'matrix', 'pages' => 'key'])) ?>">Key pages only</a> | <a href="<?= h(url($pageKey, ['mode' => 'matrix'])) ?>">All pages</a> |
  <a href="#" onclick="window.print();return false">Print</a></div>
<div class="card"><div class="table-responsive"><table class="table table-sm table-bordered mb-0 small">
  <thead class="sticky-top bg-white"><tr><th>Page</th><?php foreach ($roles as $r): ?><th class="text-center"><?= h($r['name']) ?></th><?php endforeach; ?></tr></thead>
  <?php foreach ($byModule as $mod): $rowsShown = array_filter($mod['pages'], fn($pg) => !$only || in_array($pg['page_key'], $only, true)); if (!$rowsShown) { continue; } ?>
    <?php if (!$only): ?><tr class="table-light"><td colspan="<?= count($roles) + 1 ?>" class="fw-semibold"><?= h($mod['name']) ?></td></tr><?php endif; ?>
    <?php foreach ($rowsShown as $pg): ?>
      <tr><td><?= h($pg['name']) ?></td>
        <?php foreach ($roles as $r): $on = (int)$r['is_admin'] || !empty($matrix[(int)$pg['id']][(int)$r['id']]); ?>
          <td class="text-center <?= $on ? 'text-success' : 'text-danger' ?>"><?= $on ? 'Allowed' : 'Denied' ?></td>
        <?php endforeach; ?></tr>
    <?php endforeach; ?>
  <?php endforeach; ?>
</table></div></div>
<?php endif; ?>
<?php
layout_end();
