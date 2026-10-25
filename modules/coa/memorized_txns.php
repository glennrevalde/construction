<?php
declare(strict_types=1);

/**
 * Chart of Accounts > Memorized Transactions
 * The list of saved transactions: enter one now, change its schedule, or take it off the list.
 * Transactions are added to it from their own page with the "Memorize" button. See app/Memorized.php.
 */
require_once APP_ROOT . '/modules/txn/_common.php';

$pageKey = 'coa.memorized_txns';
$a = g('a', 'list');

// ------------------------------------------------------------------ memorize a transaction (from its own page)
if ($a === 'add' && is_post()) {
    Access::require($pageKey, 'create');
    $t = Txn::load((int)p('txn_id'));
    if (!$t || !in_array($t['txn_type'], Memorized::TYPES, true)) {
        flash('warning', 'That transaction cannot be memorized.');
        redirect(url($pageKey));
    }
    try {
        $name = mb_substr(trim((string)p('name')), 0, 120);
        if ($name === '') {
            fail('Enter a name for the memorized transaction.');
        }
        $how = isset(Memorized::HOW[p('how')]) ? (string)p('how') : 'remind';
        $freq = isset(Memorized::FREQ[p('frequency')]) ? (string)p('frequency') : 'monthly';
        $next = pdate('next_date');
        if ($how !== 'none' && !$next) {
            fail('Enter the date it is next due.');
        }
        $id = DB::insert('memorized_txns', [
            'name' => $name, 'group_name' => mb_substr(trim((string)p('group_name')), 0, 60) ?: null,
            'txn_type' => $t['txn_type'], 'entity_label' => mb_substr(Memorized::entityLabel($t), 0, 160) ?: null,
            'amount' => (float)$t['total'], 'payload' => json_encode(Memorized::fromTxn($t)),
            'how' => $how, 'frequency' => $freq, 'every_n' => max(1, (int)p('every_n', '1')), 'next_date' => $next,
            'days_in_advance' => max(0, min(90, (int)p('days_in_advance', '5'))),
            'remaining' => trim((string)p('remaining')) === '' ? null : max(0, (int)p('remaining')),
            'end_date' => pdate('end_date'), 'notes' => mb_substr(trim((string)p('notes')), 0, 255) ?: null,
            'created_by' => Auth::id(),
        ]);
        Audit::log('memorize', 'coa', 'memorized_txn', $id, $name, ['reason' => 'From ' . Txn::ref($t), 'amount' => (float)$t['total'], 'page_key' => $pageKey]);
        flash('success', 'Memorized as "' . $name . '". It is on the Memorized Transactions list.');
    } catch (UserError $e) {
        flash('danger', $e->getMessage());
    }
    redirect(p('back') ?: Txn::url($t));
}

// ------------------------------------------------------------------ enter one now
if ($a === 'enter' && is_post()) {
    Access::require($pageKey, 'create');
    $m = Memorized::load((int)g('id'));
    if (!$m) {
        redirect(url($pageKey));
    }
    try {
        $date = pdate('txn_date', today());
        $id = Memorized::enter($m, $date, p('advance') !== '0');
        $t = Txn::load($id);
        flash('success', Txn::label($m['txn_type']) . ' ' . ($t['txn_no'] ?: '') . ' entered from "' . $m['name'] . '".');
        redirect(Txn::url($t));
    } catch (UserError $e) {
        flash('danger', $e->getMessage());
    } catch (Throwable $e) {
        flash('danger', 'The entry could not be recorded: ' . $e->getMessage());
    }
    redirect(url($pageKey));
}

// ------------------------------------------------------------------ delete
if ($a === 'delete' && is_post()) {
    Access::require($pageKey, 'delete');
    $m = Memorized::load((int)g('id'));
    if ($m) {
        DB::run('DELETE FROM memorized_txns WHERE id = :id', ['id' => $m['id']]);
        Audit::log('delete', 'coa', 'memorized_txn', (int)$m['id'], (string)$m['name'], ['before' => $m, 'page_key' => $pageKey]);
        flash('success', '"' . $m['name'] . '" was taken off the list.');
    }
    redirect(url($pageKey));
}

// ------------------------------------------------------------------ edit the schedule
$edit = gint('id') && $a === 'edit' ? Memorized::load((int)gint('id')) : null;
if ($a === 'edit' && !$edit) {
    redirect(url($pageKey));
}
handle_post(function () use ($edit, $pageKey) {
    if (!$edit) {
        return;
    }
    Access::require($pageKey, 'edit');
    $name = mb_substr(trim((string)p('name')), 0, 120);
    if ($name === '') {
        fail('Enter a name for the memorized transaction.');
    }
    $how = isset(Memorized::HOW[p('how')]) ? (string)p('how') : 'remind';
    $next = pdate('next_date');
    if ($how !== 'none' && !$next) {
        fail('Enter the date it is next due.');
    }
    $d = [
        'name' => $name, 'group_name' => mb_substr(trim((string)p('group_name')), 0, 60) ?: null,
        'how' => $how, 'frequency' => isset(Memorized::FREQ[p('frequency')]) ? (string)p('frequency') : 'monthly',
        'every_n' => max(1, (int)p('every_n', '1')), 'next_date' => $next,
        'days_in_advance' => max(0, min(90, (int)p('days_in_advance', '5'))),
        'remaining' => trim((string)p('remaining')) === '' ? null : max(0, (int)p('remaining')),
        'end_date' => pdate('end_date'), 'notes' => mb_substr(trim((string)p('notes')), 0, 255) ?: null,
        'is_active' => p('is_active', '1') === '1' ? 1 : 0,
    ];
    DB::update('memorized_txns', $d, 'id = :id', ['id' => $edit['id']]);
    Audit::log('edit', 'coa', 'memorized_txn', (int)$edit['id'], $name, ['before' => $edit, 'after' => $d, 'page_key' => $pageKey]);
    flash('success', 'Memorized transaction saved.');
    redirect(url($pageKey));
});

$rows = DB::all('SELECT m.*, u.full_name FROM memorized_txns m LEFT JOIN users u ON u.id = m.created_by ORDER BY m.group_name IS NULL, m.group_name, m.name');
$due = Memorized::due();
$dueIds = array_flip(array_map(fn($r) => (int)$r['id'], $due));
$canCreate = Access::can($pageKey, 'create');

layout_start('Memorized Transactions', ['actions' => page_link('coa.journal', ['a' => 'new'], 'New journal entry', 'btn-outline-secondary', 'journal-text')]);
?>
<p class="text-muted small">Transactions you enter again and again: monthly rent, a standing invoice, the payroll journal, a recurring stock adjustment.
  Press <strong>Memorize</strong> on a form (or on a saved transaction) to add it here, and <strong>Load Memorized Transaction</strong> on a new form to fill the form from it.
  A memorized transaction keeps the names, the accounts, the items and the amounts; the date and the document number are given when it is entered.</p>

<?php if ($edit): ?>
<form method="post" class="card mb-3"><div class="card-header">Edit "<?= h($edit['name']) ?>"</div><div class="card-body row g-2">
  <?= csrf_field() ?>
  <?= input_field('name', 'Name', fv('name', $edit), ['class' => 'col-md-4', 'required' => true, 'extra' => 'maxlength="120"']) ?>
  <?= input_field('group_name', 'Group (optional)', fv('group_name', $edit), ['class' => 'col-md-2', 'extra' => 'maxlength="60"']) ?>
  <?= select_field('how', 'How it is entered', Memorized::HOW, fv('how', $edit), ['class' => 'col-md-3', 'extra' => 'id="mHow" data-no-combo']) ?>
  <div class="col-md-3"><label class="form-label">Every</label><div class="input-group input-group-sm">
    <input class="form-control text-end" type="number" min="1" step="1" name="every_n" value="<?= h(fv('every_n', $edit, '1')) ?>">
    <select class="form-select" name="frequency" data-no-combo><?= options(Memorized::FREQ, fv('frequency', $edit, 'monthly')) ?></select></div></div>
  <?= input_field('next_date', 'Next due', fv('next_date', $edit), ['type' => 'date', 'class' => 'col-md-2']) ?>
  <?= input_field('days_in_advance', 'Remind (days before)', fv('days_in_advance', $edit, '5'), ['type' => 'number', 'step' => '1', 'class' => 'col-md-2', 'extra' => 'min="0" max="90"']) ?>
  <?= input_field('remaining', 'Number remaining (blank = no limit)', fv('remaining', $edit), ['class' => 'col-md-3', 'extra' => 'inputmode="numeric"']) ?>
  <?= input_field('end_date', 'Stop after', fv('end_date', $edit), ['type' => 'date', 'class' => 'col-md-2']) ?>
  <?= check_field('is_active', 'Active', fv('is_active', $edit, 1), 'col-md-3') ?>
  <?= input_field('notes', 'Notes', fv('notes', $edit), ['class' => 'col-12', 'extra' => 'maxlength="255"']) ?>
  <div class="col-12"><?= save_buttons('Save', url($pageKey), false) ?></div>
</div></form>
<?php endif; ?>

<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0 mem-list">
  <thead><tr><th class="mem-col-name">Name and type</th><th>Name on the transaction</th><th class="num">Amount</th><th>Schedule</th><th>Next due</th><th>Entered</th><th></th></tr></thead>
  <?php $group = null; foreach ($rows as $r):
      if (($r['group_name'] ?? '') !== $group): $group = $r['group_name'] ?? ''; ?>
        <?php if ($group !== ''): ?><tr class="table-light"><td colspan="7" class="fw-semibold"><?= h($group) ?></td></tr><?php endif; ?>
      <?php endif; ?>
    <tr class="<?= (int)$r['is_active'] ? (isset($dueIds[(int)$r['id']]) ? 'pm-due' : '') : 'text-muted' ?>">
      <td><span class="fw-semibold"><?= h($r['name']) ?></span><div class="small text-muted"><?= h(Memorized::typeLabel($r['txn_type'])) ?><?= $r['notes'] ? ', ' . h($r['notes']) : '' ?></div></td>
      <td class="small"><?= h($r['entity_label']) ?></td>
      <td class="num"><?= money($r['amount'], true) ?></td>
      <td class="small text-nowrap" title="<?= h(Memorized::HOW[$r['how']] ?? '') ?>"><?= h(Memorized::howShort($r)) ?>
        <?php if ($r['how'] !== 'none'): ?><div class="text-muted"><?= h(Memorized::frequencyLabel($r)) ?></div><?php endif; ?></td>
      <td class="text-nowrap"><?= h(fdate($r['next_date'])) ?>
        <?php if (isset($dueIds[(int)$r['id']])): ?><div class="pm-state <?= $r['next_date'] < today() ? 'overdue' : 'due' ?>"><?= $r['next_date'] < today() ? 'Overdue' : 'Due' ?></div><?php endif; ?></td>
      <td class="small text-nowrap"><?= (int)$r['entered_count'] ?><?= $r['remaining'] !== null ? ' of ' . ((int)$r['entered_count'] + (int)$r['remaining']) : '' ?> time<?= (int)$r['entered_count'] === 1 && $r['remaining'] === null ? '' : 's' ?>
        <?php if ($r['last_entered']): ?><div class="text-muted">last <?= h(fdate($r['last_entered'])) ?></div><?php endif; ?></td>
      <td class="text-nowrap text-end">
        <?php if ($canCreate && (int)$r['is_active'] === 1 && !Memorized::formOnly($r['txn_type'])): ?>
          <button type="button" class="btn btn-sm <?= isset($dueIds[(int)$r['id']]) ? 'btn-success' : 'btn-outline-success' ?>" data-mem-enter="<?= (int)$r['id'] ?>"
            data-name="<?= h($r['name']) ?>" data-type="<?= h(Txn::label($r['txn_type'])) ?>" data-date="<?= h($r['next_date'] ?: today()) ?>"><i class="bi bi-play-fill"></i> Enter now</button>
        <?php endif; ?>
        <?php $formPage = Memorized::formPage($r['txn_type']); if ((int)$r['is_active'] === 1 && $formPage && Access::can($formPage, 'create')): ?>
          <a class="btn btn-sm <?= Memorized::formOnly($r['txn_type']) && isset($dueIds[(int)$r['id']]) ? 'btn-success' : 'btn-outline-primary' ?>" href="<?= h(Memorized::loadUrl($r)) ?>" title="Open on the form: a new form filled from it, to change and save" aria-label="Open on the form"><i class="bi bi-box-arrow-in-down"></i> Open</a>
        <?php endif; ?>
        <?php if (Access::can($pageKey, 'edit')): ?><a class="btn btn-sm btn-outline-secondary" href="<?= h(url($pageKey, ['a' => 'edit', 'id' => $r['id']])) ?>" title="Edit"><i class="bi bi-pencil"></i></a><?php endif; ?>
        <?php if (Access::can($pageKey, 'delete')): ?>
          <form method="post" action="<?= h(url($pageKey, ['a' => 'delete', 'id' => $r['id']])) ?>" class="d-inline"><?= csrf_field() ?>
            <button class="btn btn-sm btn-outline-danger" title="Delete" data-confirm="Take &quot;<?= h($r['name']) ?>&quot; off the memorized list? The transactions already entered from it are not touched."><i class="bi bi-trash"></i></button></form>
        <?php endif; ?>
      </td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="text-muted py-3">Nothing is memorized yet. Press <strong>Memorize Transaction</strong> on a form you fill in often, or <strong>Memorize</strong> on a saved transaction.</td></tr><?php endif; ?>
</table></div></div>

<div class="modal fade" id="memEnterModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post" id="memEnterForm">
  <?= csrf_field() ?>
  <div class="modal-header"><h5 class="modal-title">Enter a memorized transaction</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <p class="mb-2"><strong id="memName"></strong><br><span class="small text-muted" id="memType"></span></p>
    <label class="form-label">Date of the entry</label>
    <input class="form-control form-control-sm" type="date" name="txn_date" id="memDate" required>
    <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="advance" value="1" id="memAdvance" checked>
      <label class="form-check-label" for="memAdvance">Move the schedule on to the next due date</label></div>
    <p class="small text-muted mt-2 mb-0">The document is recorded straight away and opens so you can check it.</p>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-success"><i class="bi bi-play-fill"></i> Enter it</button></div>
</form></div></div>
<script>
(function () {
  const base = <?= json_encode(url($pageKey, ['a' => 'enter'])) ?>;
  document.querySelectorAll('[data-mem-enter]').forEach((b) => b.addEventListener('click', () => {
    document.getElementById('memEnterForm').action = base + '&id=' + b.dataset.memEnter;
    document.getElementById('memName').textContent = b.dataset.name;
    document.getElementById('memType').textContent = b.dataset.type;
    document.getElementById('memDate').value = b.dataset.date;
    new bootstrap.Modal(document.getElementById('memEnterModal')).show();
  }));
  document.getElementById('memEnterForm').addEventListener('submit', function () {
    if (!document.getElementById('memAdvance').checked) { const i = document.createElement('input'); i.type = 'hidden'; i.name = 'advance'; i.value = '0'; this.appendChild(i); }
  });
})();
</script>
<?php
layout_end();
