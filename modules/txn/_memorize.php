<?php
declare(strict_types=1);

/**
 * "Memorize" and "Load Memorized Transaction" on the transaction forms (see app/Memorized.php).
 *
 *   memorize_controls($type, $name) - the Memorize button and its box; both go inside the form. Pressing Memorize in
 *                                     the box sends the form with memorize=1: the form is checked as for saving, then
 *                                     kept on the Memorized Transactions list instead of being recorded.
 *   memorized_loader($types, $page) - the "Load Memorized Transaction" button for the title bar, and the list it opens: each
 *                                     memorized transaction of these types, loaded into a new form with one click.
 */

/** [button, box] of the Memorize control; the box carries the hidden memorize field and must be inside the form */
function memorize_controls(string $type, string $defaultName = '', string $label = 'Memorize Transaction', string $what = ''): array
{
    if (!Access::can('coa.memorized_txns', 'create')) {
        return ['', ''];
    }
    $formOnly = Memorized::formOnly($type);
    $what = $what !== '' ? $what : strtolower(Memorized::typeLabel($type));
    $how = $formOnly ? ['none' => "Don't remind me (I load it when needed)", 'remind' => 'Remind me when it is due'] : Memorized::HOW;
    $button = '<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#memFormModal" data-no-class-check>'
        . '<i class="bi bi-bookmark-plus"></i> ' . h($label) . '</button>';
    ob_start();
    ?>
    <input type="hidden" name="memorize" value="0">
    <div class="modal fade" id="memFormModal" tabindex="-1" aria-labelledby="memFormTitle"><div class="modal-dialog"><div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="memFormTitle"><?= h($label) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body row g-2">
        <p class="small text-muted col-12 mb-1">What is on this <?= h($what) ?> now is kept as a pro-forma on the Memorized Transactions list.
          Load it into a new form whenever it is needed and change what differs. Nothing is saved to the books by memorizing.</p>
        <?= input_field('mem_name', 'Name it', fv('mem_name', null, $defaultName), ['class' => 'col-md-8', 'extra' => 'maxlength="120" data-mem-name']) ?>
        <?= input_field('mem_group', 'Group (optional)', fv('mem_group'), ['class' => 'col-md-4', 'extra' => 'maxlength="60"']) ?>
        <?= select_field('mem_how', 'Reminder', $how, fv('mem_how', null, 'none'), ['class' => 'col-12', 'extra' => 'id="memFormHow" data-no-combo']) ?>
        <div class="col-md-6" data-toggle-by="#memFormHow" data-show-when="remind,auto"><label class="form-label">Every</label><div class="input-group input-group-sm">
          <input class="form-control text-end" type="number" min="1" step="1" name="mem_every_n" value="<?= h(fv('mem_every_n', null, '1')) ?>" aria-label="Every">
          <select class="form-select" name="mem_frequency" data-no-combo aria-label="Frequency"><?= options(Memorized::FREQ, fv('mem_frequency', null, 'monthly')) ?></select></div></div>
        <div class="col-md-6" data-toggle-by="#memFormHow" data-show-when="remind,auto"><label class="form-label">Next due</label>
          <input class="form-control form-control-sm" type="date" name="mem_next_date" value="<?= h(fv('mem_next_date', null, date('Y-m-d', strtotime('+1 month')))) ?>"></div>
        <div class="col-md-6" data-toggle-by="#memFormHow" data-show-when="remind,auto"><label class="form-label">Remind (days before)</label>
          <input class="form-control form-control-sm" type="number" step="1" min="0" max="90" name="mem_days" value="<?= h(fv('mem_days', null, '5')) ?>"></div>
        <?= input_field('mem_notes', 'Notes', fv('mem_notes'), ['class' => 'col-12', 'extra' => 'maxlength="255"']) ?>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" data-memorize-go><i class="bi bi-bookmark-plus"></i> <?= h($label) ?></button></div>
    </div></div></div>
    <?php
    return [$button, (string)ob_get_clean()];
}

/** [button, list] of "Load Memorized Transaction" for a new form; the list goes outside the form */
function memorized_loader(array $types, string $pageKey, string $label = 'Load Memorized Transaction'): array
{
    if (!Access::can($pageKey, 'create')) {
        return ['', ''];
    }
    $rows = Memorized::ofTypes($types);
    $button = '<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#memLoadModal">'
        . '<i class="bi bi-bookmark-check"></i> ' . h($label) . ($rows ? ' (' . count($rows) . ')' : '') . '</button>';
    ob_start();
    ?>
    <div class="modal fade" id="memLoadModal" tabindex="-1" aria-labelledby="memLoadTitle"><div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="memLoadTitle"><?= h($label) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <?php if ($rows): ?>
        <div class="live-search mb-2"><i class="bi bi-search"></i><input class="form-control form-control-sm" type="search" placeholder="Type part of the name" autocomplete="off"
             data-live-filter="#memLoadList tbody tr" data-live-empty="#memLoadNone"></div>
        <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 mem-load-list" id="memLoadList">
          <thead><tr><th>Name</th><th>Name on the transaction</th><th class="num">Amount</th><th>Last entered</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): $u = Memorized::loadUrl($r, $pageKey); ?>
            <tr data-filter-text="<?= h($r['name'] . ' ' . $r['group_name'] . ' ' . $r['entity_label']) ?>">
              <td><strong><?= h($r['name']) ?></strong><?php if ($r['group_name'] || $r['notes']): ?><div class="small text-muted"><?= h(trim(($r['group_name'] ?: '') . ($r['group_name'] && $r['notes'] ? ', ' : '') . ($r['notes'] ?: ''))) ?></div><?php endif; ?></td>
              <td class="small"><?= h($r['entity_label']) ?></td>
              <td class="num"><?= (float)$r['amount'] != 0.0 ? money($r['amount']) : '' ?></td>
              <td class="small text-nowrap"><?= h(fdate($r['last_entered'])) ?></td>
              <td class="text-end"><a class="btn btn-sm btn-primary" href="<?= h($u) ?>" data-mem-load><i class="bi bi-box-arrow-in-down"></i> Load</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table><div class="p-3 small text-muted" id="memLoadNone">Nothing matches.</div></div>
        <p class="small text-muted mt-2 mb-0">Loading fills a new form with the names, items, accounts and amounts that were memorized. Change what differs, then save.</p>
        <?php else: ?>
        <p class="mb-0 text-muted">Nothing is memorized for this form yet. Fill in the form and press <strong>Memorize Transaction</strong> to keep it as a pro-forma.</p>
        <?php endif; ?>
      </div>
    </div></div></div>
    <?php
    return [$button, (string)ob_get_clean()];
}

/** The note above a form filled from a memorized transaction */
function memorized_loaded_note(?array $mem): string
{
    if (!$mem) {
        return '';
    }
    return '<div class="alert alert-info py-2 d-flex gap-2 align-items-center"><i class="bi bi-bookmark-check"></i><div>Loaded from the memorized transaction <strong>'
        . h($mem['m']['name']) . '</strong>. Change what differs, then save.</div></div>';
}
