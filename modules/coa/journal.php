<?php
declare(strict_types=1);

require_once APP_ROOT . '/modules/txn/_common.php';
require_once APP_ROOT . '/modules/txn/_grids.php';
require_once APP_ROOT . '/modules/txn/_memorize.php';

$pageKey = 'coa.journal';
if (txn_common_actions($pageKey, ['journal', 'opening_balance'])) {
    return;
}
$a = g('a', 'list');
if ($a === 'list') {
    render_txn_list($pageKey, ['journal', 'opening_balance'], 'Journal Entries', ['statuses' => ['open' => 'Posted', 'void' => 'Void'], 'new_label' => 'New journal entry']);
    return;
}
$t = null;
$lines = [];
if ($a === 'edit') {
    Access::require($pageKey, 'edit');
    $t = Txn::load((int)g('id'));
    if (!$t || !in_array($t['txn_type'], ['journal', 'opening_balance'], true) || (int)$t['is_voided'] === 1) {
        redirect(url($pageKey));
    }
    $lines = Txn::lines((int)$t['id']);
} else {
    Access::require($pageKey, 'create');
}

handle_post(function () use ($t, $pageKey) {
    $lines = parse_journal_lines(grid_rows('lines'));
    if (count($lines) < 2) {
        fail('A journal entry needs at least two lines.');
    }
    $dr = 0.0;
    $cr = 0.0;
    foreach ($lines as $l) {
        if ($l['amount'] > 0) {
            $dr += $l['amount'];
        } else {
            $cr -= $l['amount'];
        }
    }
    if (abs(r2($dr) - r2($cr)) >= 0.01) {
        fail('Debits (' . money($dr) . ') must equal credits (' . money($cr) . ').');
    }
    // accounts marked "With SL" keep a listing of names, so every line to one must carry a name
    Accts::requireSl($lines);
    foreach ($lines as $l) {
        if (in_array($l['account_id'], Accts::arIds(), true) && !$l['customer_id']) {
            fail('Lines posted to Accounts Receivable need a customer name.');
        }
        if (in_array($l['account_id'], Accts::apIds(), true) && !$l['vendor_id']) {
            fail('Lines posted to Accounts Payable need a vendor name.');
        }
    }
    $type = p('is_opening') === '1' ? 'opening_balance' : 'journal';
    $h = ['txn_date' => pdate('txn_date'), 'memo' => p('memo') ?: null, 'ref_no' => p('ref_no') ?: null, 'status' => 'open'];
    if (Classes::ready()) {
        $h['class_id'] = Classes::posted();
    }
    if (p('txn_no') !== '') {
        $h['txn_no'] = mb_substr((string)p('txn_no'), 0, 30);
    }
    $id = Txn::save($type, $h, $lines, $t ? (int)$t['id'] : null, ['reason' => p('reason') ?: null]);
    flash('success', 'Journal entry saved.');
    redirect_after_save(url($pageKey, ['a' => 'new']), url($pageKey, ['a' => 'view', 'id' => $id]));
});

if (is_post()) {
    $lines = journal_rows_for_redisplay(grid_rows('lines'));
}
// a new entry filled from a memorized journal entry ("Load Memorized Transaction")
$mem = (!$t && !is_post() && gint('mem')) ? Memorized::forForm((int)gint('mem'), ['journal']) : null;
if ($mem) {
    $lines = $mem['lines'];
}
$rec = $t ?: ($mem['header'] ?? null);
[$memButton, $memBox] = memorize_controls('journal', 'Journal entry');
[$loadButton, $loadList] = !$t ? memorized_loader(['journal'], $pageKey) : ['', ''];
layout_start($t ? 'Edit journal entry ' . $t['txn_no'] : 'Make Journal Entries', ['actions' => $loadButton . ' ' . btn_link(url($pageKey), 'Journal list')]);
echo memorized_loaded_note($mem);
?>
<form method="post"><?= csrf_field() ?>
<div class="card mb-3"><div class="card-body row g-2">
  <?= input_field('txn_date', 'Date', fv('txn_date', $rec, today()), ['type' => 'date', 'class' => 'col-md-2']) ?>
  <?= input_field('txn_no', 'Entry no.', fv('txn_no', $t, ''), ['class' => 'col-md-2', 'extra' => 'placeholder="' . h($t ? '' : Seq::peek('journal') . ' (auto)') . '"']) ?>
  <?= input_field('ref_no', 'Reference', fv('ref_no', $rec), ['class' => 'col-md-2']) ?>
  <?= input_field('memo', 'Memo', fv('memo', $rec), ['class' => Classes::ready() ? 'col-md-4' : 'col-md-6']) ?>
  <?= Classes::field(fv('class_id', $rec), 'col-md-2') ?>
  <?= check_field('is_opening', 'Opening balance entry', fv('is_opening', $t ? ['is_opening' => $t['txn_type'] === 'opening_balance' ? 1 : 0] : null, 0), 'col-md-3') ?>
  <?php if ($t): ?><?= input_field('reason', 'Reason for change', fv('reason'), ['class' => 'col-md-9']) ?><?php endif; ?>
</div></div>
<div class="card card-body mb-3"><?= journal_grid($lines) ?>
  <p class="small text-muted mb-0 mt-2">Routine activity posts automatically from invoices, bills, payments and the POS. Use journal entries for opening balances, accruals, corrections and closing entries.
    <?php if (Classes::ready()): ?>The class at the top applies to every line; choose a class on a line only when that line belongs to a different one.<?php endif; ?></p></div>
<div class="d-flex flex-wrap gap-2 align-items-center">
  <?= save_buttons('Save entry', $t ? url($pageKey, ['a' => 'view', 'id' => $t['id']]) : url($pageKey), Access::can($pageKey, 'create')) ?>
  <?php if ($memButton): ?><div class="ms-auto"><?= $memButton ?></div><?php endif; ?>
</div>
<?= $memBox ?>
</form>
<?= $loadList ?>
<?php
layout_end();
