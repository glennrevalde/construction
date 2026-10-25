<?php
declare(strict_types=1);

/**
 * Point of Sale > Approval Codes
 *
 * A supervisor who is not at the counter generates a one-time code here and gives it to the cashier or clerk,
 * who types it in the Supervisor approval box of the transaction. The list shows every code with its state:
 * Open (usable), Used (by whom, when and on what), Expired or Cancelled.
 *
 * Who may generate: whoever may open this page with the create right (the roles that may approve at the POS
 * Register, and administrators). A code is only accepted on a page where its issuer may approve.
 */

$pageKey = 'pos.approval_codes';
$canIssue = Access::can($pageKey, 'create');
$canCancel = Access::can($pageKey, 'edit') || Access::can($pageKey, 'delete');
ApprovalCode::sweep();

$issued = null;
handle_post(function () use ($pageKey, $canIssue, $canCancel, &$issued) {
    $do = p('do');
    if ($do === 'issue') {
        if (!$canIssue) {
            Access::denyPage($pageKey, 'generate approval codes on');
        }
        $forUser = pint('for_user_id');
        $page = (string)p('page_key');
        if ($page !== '' && !isset(ApprovalCode::pages()[$page])) {
            fail('Choose a page from the list.');
        }
        $hours = (int)p('hours', '24');
        if (!isset(ApprovalCode::VALIDITY[(string)$hours])) {
            fail('Choose how long the code stays valid.');
        }
        $issued = ApprovalCode::issue(trim((string)p('purpose')), $forUser, $page ?: null, $hours);
        flash('success', 'Approval code ' . ApprovalCode::display($issued['code']) . ' generated. It can be used once, until ' . fdatetime($issued['expires_at']) . '.');
        redirect(url($pageKey, ['new' => $issued['id']]));
    }
    if ($do === 'cancel') {
        if (!$canCancel) {
            Access::denyPage($pageKey, 'cancel approval codes on');
        }
        ApprovalCode::cancel((int)p('id'), trim((string)p('reason')) ?: 'Cancelled by ' . Auth::user()['username']);
        flash('warning', 'The code was cancelled. It can no longer be used.');
        redirect(url($pageKey));
    }
});

$status = in_array(g('status'), ['open', 'used', 'expired', 'cancelled'], true) ? g('status') : '';
$from = gdate('from', date('Y-m-d', strtotime('-30 days')));
$to = gdate('to', today());
$mine = g('mine') === '1';
$where = ['c.issued_at BETWEEN :f AND :t'];
$params = ['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59'];
if ($status !== '') {
    $where[] = 'c.status = :st';
    $params['st'] = $status;
}
if ($mine) {
    $where[] = 'c.issued_by = :me';
    $params['me'] = Auth::id();
}
$codes = DB::all('SELECT c.*, i.full_name AS issuer, i.username AS issuer_user, f.username AS for_user, u.username AS used_user, t.txn_type, t.txn_no
                    FROM approval_codes c JOIN users i ON i.id = c.issued_by
                    LEFT JOIN users f ON f.id = c.for_user_id LEFT JOIN users u ON u.id = c.used_by LEFT JOIN transactions t ON t.id = c.used_txn_id
                   WHERE ' . implode(' AND ', $where) . ' ORDER BY c.id DESC LIMIT 300', $params);
$counts = DB::pairs('SELECT status, COUNT(*) FROM approval_codes GROUP BY status');
$fresh = gint('new') ? ApprovalCode::load((int)gint('new')) : null;
if ($fresh && (int)$fresh['issued_by'] !== Auth::id()) {
    $fresh = null;
}
$users = DB::pairs("SELECT id, CONCAT(full_name, ' (', username, ')') FROM users WHERE status = 'active' AND is_platform = 0 AND id <> " . (int)Auth::id() . ' ORDER BY full_name');

$stateLabel = ['open' => 'Open', 'used' => 'Used', 'expired' => 'Expired', 'cancelled' => 'Cancelled'];
layout_start('Approval Codes', ['actions' => page_link('pos.register', [], 'POS Register', 'btn-outline-secondary', 'cart')]);
?>
<div class="row g-3">
  <div class="col-xl-4">
    <?php if ($fresh): ?>
    <div class="card mb-3 code-card"><div class="card-body text-center">
      <div class="small text-muted">Give this code to the cashier. It works once.</div>
      <div class="code-big" id="freshCode"><?= h(ApprovalCode::display($fresh['code'])) ?></div>
      <div class="small text-muted">Valid until <?= h(fdatetime($fresh['expires_at'])) ?><?= $fresh['for_user_id'] ? ', for ' . h((string)DB::value('SELECT username FROM users WHERE id = :id', ['id' => $fresh['for_user_id']])) : ', for any user' ?><?= $fresh['page_key'] ? ', on ' . h(Access::pageDef($fresh['page_key'])['name'] ?? $fresh['page_key']) : '' ?></div>
      <?php if ($fresh['purpose']): ?><div class="small mt-1"><?= h($fresh['purpose']) ?></div><?php endif; ?>
      <button type="button" class="btn btn-sm btn-outline-primary mt-2" data-copy="#freshCode"><i class="bi bi-clipboard"></i> Copy</button>
    </div></div>
    <?php endif; ?>

    <?php if ($canIssue): ?>
    <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="do" value="issue">
      <div class="card-header fw-semibold"><i class="bi bi-key"></i> Generate an approval code</div>
      <div class="card-body">
        <p class="small text-muted">For a cashier or clerk who needs your approval while you are away. The code stands for your username and password: the approval is recorded under your name, and it is accepted only where you may approve.</p>
        <div class="row g-2">
          <?= input_field('purpose', 'What it is for (optional)', fv('purpose'), ['class' => 'col-12', 'extra' => 'placeholder="e.g. price override for a bulk buyer" maxlength="160"']) ?>
          <?= select_field('for_user_id', 'For which user', $users, fv('for_user_id'), ['blank' => 'Any user', 'class' => 'col-12']) ?>
          <?= select_field('page_key', 'On which page', ApprovalCode::pages(), fv('page_key', null, 'pos.register'), ['blank' => 'Any page where I may approve', 'class' => 'col-12', 'extra' => 'data-no-combo']) ?>
          <?= select_field('hours', 'Valid for', ApprovalCode::VALIDITY, fv('hours', null, '24'), ['class' => 'col-12', 'extra' => 'data-no-combo']) ?>
        </div>
        <button class="btn btn-primary mt-3 w-100"><i class="bi bi-shield-check"></i> Generate code</button>
      </div>
    </form>
    <?php else: ?>
    <div class="card"><div class="card-body small text-muted">You can see the codes but not generate them: generating needs the create right on this page, which the roles that may approve at the register have.</div></div>
    <?php endif; ?>
  </div>

  <div class="col-xl-8">
    <div class="code-stats mb-3">
      <?php foreach ($stateLabel as $k => $lbl): ?>
        <a class="code-stat state-<?= $k ?> <?= $status === $k ? 'is-on' : '' ?>" href="<?= h(url($pageKey, ['status' => $status === $k ? '' : $k, 'from' => $from, 'to' => $to, 'mine' => $mine ? '1' : null])) ?>">
          <span class="code-stat-n"><?= (int)($counts[$k] ?? 0) ?></span><span><?= h($lbl) ?></span></a>
      <?php endforeach; ?>
    </div>
    <form method="get" class="row g-2 mb-3 filters"><input type="hidden" name="p" value="<?= h($pageKey) ?>"><input type="hidden" name="status" value="<?= h($status) ?>">
      <div class="col-auto"><label class="form-label">Generated from</label><input class="form-control form-control-sm" type="date" name="from" value="<?= h($from) ?>"></div>
      <div class="col-auto"><label class="form-label">To</label><input class="form-control form-control-sm" type="date" name="to" value="<?= h($to) ?>"></div>
      <div class="col-auto d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="mine" value="1" id="mineCb" <?= $mine ? 'checked' : '' ?>><label class="form-check-label" for="mineCb">My codes only</label></div></div>
      <div class="col-auto d-flex align-items-end"><button class="btn btn-sm btn-secondary">Show</button></div>
    </form>
    <div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0 code-list">
      <thead><tr><th>Code</th><th>State</th><th>Generated</th><th>For</th><th>Purpose</th><th>Used</th><th></th></tr></thead>
      <tbody>
      <?php if (!$codes): ?><tr><td colspan="7" class="text-center text-muted py-4">No approval codes<?= $status ? ' that are ' . strtolower($stateLabel[$status]) : '' ?> in this period.</td></tr><?php endif; ?>
      <?php foreach ($codes as $c): $open = $c['status'] === 'open'; ?>
        <tr class="<?= $open ? '' : 'text-muted' ?>">
          <td><span class="code-mono <?= $open ? '' : 'is-done' ?>"><?= h($open || (int)$c['issued_by'] === Auth::id() || Access::isAdmin() ? ApprovalCode::display($c['code']) : substr(ApprovalCode::display($c['code']), 0, 4) . '-····') ?></span></td>
          <td><span class="code-state state-<?= h($c['status']) ?>"><?= h($stateLabel[$c['status']] ?? $c['status']) ?></span>
            <?php if ($open): ?><div class="small text-muted">until <?= h(fdatetime($c['expires_at'])) ?></div><?php endif; ?></td>
          <td class="small"><?= h(fdatetime($c['issued_at'])) ?><div class="text-muted">by <?= h($c['issuer_user']) ?></div></td>
          <td class="small"><?= h($c['for_user'] ?: 'any user') ?><div class="text-muted"><?= h($c['page_key'] ? (Access::pageDef($c['page_key'])['name'] ?? $c['page_key']) : 'any page') ?></div></td>
          <td class="small"><?= h($c['purpose']) ?></td>
          <td class="small">
            <?php if ($c['status'] === 'used'): ?><?= h(fdatetime($c['used_at'])) ?><div class="text-muted">by <?= h($c['used_user']) ?><?= $c['used_on'] ? ': ' . h($c['used_on']) : '' ?></div>
              <?php if ($c['used_txn_id']): ?><div><?= Txn::link(['id' => $c['used_txn_id'], 'txn_type' => $c['txn_type'], 'txn_no' => $c['txn_no']], Txn::label((string)$c['txn_type']) . ' ' . $c['txn_no']) ?></div><?php endif; ?>
            <?php elseif ($c['status'] === 'cancelled'): ?><?= h(fdatetime($c['cancelled_at'])) ?><div class="text-muted">cancelled</div>
            <?php elseif ($c['status'] === 'expired'): ?><span class="text-muted">never used</span><?php endif; ?></td>
          <td class="text-end">
            <?php if ($open && ($canCancel || (int)$c['issued_by'] === Auth::id())): ?>
            <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="do" value="cancel"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" data-confirm="Cancel this code? It can no longer be used."><i class="bi bi-x-circle"></i> Cancel</button></form>
            <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div></div>
    <p class="small text-muted mt-2">Every code generated, used, refused or cancelled is in the audit trail. A code is accepted only on pages where the supervisor who generated it may approve, never by the supervisor for their own transaction, and never twice.</p>
  </div>
</div>
<script>
document.querySelectorAll('[data-copy]').forEach(function (b) {
  b.addEventListener('click', function () {
    var t = document.querySelector(b.dataset.copy).textContent.trim();
    (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function () { b.innerHTML = '<i class="bi bi-check2"></i> Copied'; }, function () { window.prompt('Copy the code', t); });
  });
});
</script>
<?php
layout_end();
