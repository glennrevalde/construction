<?php
declare(strict_types=1);

/**
 * Inventory > Adjust Quantity/Value on Hand
 *
 * Two ways to enter an adjustment, chosen at the top of the page (the choice is remembered):
 *   Per Item Adjustment  - the QuickBooks way: pick the items one by one on lines (or several at once with
 *                          Find & Select Items), with the quantity on hand, the new quantity or the difference,
 *                          the U/M and a class on every line, and the total value and the number of items below.
 *   Per Batch Adjustment - every stocked item of the site in one long grid; type the new quantity (or value) of
 *                          the ones that changed. Handy after a full count.
 * Both post the same inventory adjustment: a decrease debits the adjustment account and credits Inventory at
 * average cost, an increase the reverse; a value change moves only the value.
 *
 * Edit Inventory Adjustment (a=edit) opens a posted adjustment in the Per Item form: "Qty on hand" and the current
 * value are what the site had without this adjustment, so the new quantity, the difference and the new value mean
 * what they meant when it was entered. Saving takes the adjustment off the books, works the lines out again and
 * posts it again in the same database transaction; the before and after, with the reason, go to the audit trail.
 */

require_once APP_ROOT . '/modules/txn/_common.php';
require_once APP_ROOT . '/modules/txn/_memorize.php';

$pageKey = 'inv.adjust';

/** The reasons offered for an adjustment (Edit reasons on this page); "Other" (described in words) is always last */
function adjust_reasons(): array
{
    $list = json_decode(Settings::get('inventory_adjust_reasons', ''), true);
    if (!is_array($list) || !$list) {
        $list = ['Count difference', 'Damage', 'Theft / loss', 'Spoilage / expiry', 'Samples / promotional use', 'Found stock',
            'Write-down to net realizable value', 'Cost correction'];
    }
    $out = [];
    foreach ($list as $r) {
        $r = trim((string)$r);
        if ($r !== '' && strcasecmp($r, 'Other') !== 0 && !in_array(mb_strtolower($r), array_map('mb_strtolower', $out), true)) {
            $out[] = $r;
        }
    }
    $out[] = 'Other';
    return $out;
}

/** The Reason box of the form, with "Edit reasons" beside its label for the users who may change the list */
function adjust_reason_field(array $reasons, $value, string $class, bool $canEdit): string
{
    if ($value !== '' && $value !== null && !in_array($value, $reasons, true)) {
        $reasons = array_merge(array_slice($reasons, 0, -1), [(string)$value, 'Other']);   // a reason since taken off the list
    }
    return '<div class="' . h($class) . '"><div class="adj-reason-label"><label class="form-label" for="reasonSel">Reason (required)</label>'
        . ($canEdit ? '<button type="button" class="btn btn-link btn-sm p-0 adj-reason-edit" data-bs-toggle="modal" data-bs-target="#adjReasons" title="Add, rename or take off reasons"><i class="bi bi-pencil-square"></i> Edit reasons</button>' : '')
        . '</div><select class="form-select form-select-sm" name="reason" id="reasonSel" required data-no-combo>'
        . options(array_combine($reasons, $reasons), $value, '— choose —') . '</select></div>';
}

/** The "Edit reasons" box */
function adjust_reasons_modal(array $reasons, string $pageKey): string
{
    $rows = '';
    foreach (array_slice($reasons, 0, -1) as $r) {
        $rows .= adjust_reason_row($r);
    }
    return '<div class="modal fade" id="adjReasons" tabindex="-1" aria-labelledby="adjReasonsTitle"><div class="modal-dialog modal-dialog-scrollable"><form class="modal-content" method="post" id="adjReasonsForm" action="' . h(url($pageKey, ['a' => 'reasons'])) . '">'
        . csrf_field() . '<input type="hidden" name="back" value="' . h(current_url()) . '">'
        . '<div class="modal-header"><h5 class="modal-title" id="adjReasonsTitle">Reasons for adjustments</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
        . '<div class="modal-body"><p class="small text-muted">Add a reason, change its wording, put the list in order with the arrows or take a reason off. '
        . 'Adjustments already saved keep the reason they were saved with. <strong>Other</strong>, described in words, is always offered last.</p>'
        . '<div class="reason-list" id="adjReasonList">' . $rows . '</div>'
        . '<template id="adjReasonTpl">' . adjust_reason_row('') . '</template>'
        . '<button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="adjReasonAdd"><i class="bi bi-plus-lg"></i> Add a reason</button>'
        . '<div class="alert alert-danger py-2 mt-2 mb-0 small" id="adjReasonErr" hidden></div></div>'
        . '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary"><i class="bi bi-check2"></i> Save the reasons</button></div>'
        . '</form></div></div>';
}

function adjust_reason_row(string $r): string
{
    return '<div class="reason-row"><input class="form-control form-control-sm" name="reasons[]" value="' . h($r) . '" maxlength="60" aria-label="Reason">'
        . '<button type="button" class="btn btn-sm btn-link p-0" data-move="-1" title="Move up" aria-label="Move up"><i class="bi bi-arrow-up"></i></button>'
        . '<button type="button" class="btn btn-sm btn-link p-0" data-move="1" title="Move down" aria-label="Move down"><i class="bi bi-arrow-down"></i></button>'
        . '<button type="button" class="btn btn-sm btn-link text-danger p-0" data-remove title="Take this reason off" aria-label="Take this reason off"><i class="bi bi-x-lg"></i></button></div>';
}
if (txn_common_actions($pageKey, ['inventory_adjustment'])) {
    return;
}
$a = g('a', 'new');

// ------------------------------------------------------------------ Edit reasons (add, rename, put in order, take off)
if ($a === 'reasons' && is_post()) {
    Access::require($pageKey, 'edit');
    $before = adjust_reasons();
    $list = [];
    foreach ((array)($_POST['reasons'] ?? []) as $r) {
        $r = trim(preg_replace('/\s+/', ' ', (string)$r) ?? '');
        if ($r === '' || strcasecmp($r, 'Other') === 0) {
            continue;
        }
        if (mb_strlen($r) > 60) {
            $msg = 'A reason can be at most 60 characters: "' . mb_substr($r, 0, 40) . '…".';
        }
        if (in_array(mb_strtolower($r), array_map('mb_strtolower', $list), true)) {
            $msg = '"' . $r . '" is on the list twice.';
        }
        $list[] = $r;
    }
    if (!$list) {
        $msg = 'Keep at least one reason besides "Other".';
    }
    if (count($list) > 40) {
        $msg = 'At most 40 reasons.';
    }
    if (empty($msg)) {
        Settings::set('inventory_adjust_reasons', json_encode(array_values($list), JSON_UNESCAPED_UNICODE));
        Audit::log('edit', 'inv', 'setting', null, 'Inventory adjustment reasons', [
            'page_key' => $pageKey, 'changes' => [['field' => 'reasons', 'old' => implode(', ', $before), 'new' => implode(', ', adjust_reasons())]],
        ]);
    }
    if (p('ajax') === '1') {
        json_out(empty($msg) ? ['ok' => true, 'reasons' => adjust_reasons()] : ['ok' => false, 'error' => $msg], empty($msg) ? 200 : 422);
    }
    flash(empty($msg) ? 'success' : 'danger', empty($msg) ? 'The reasons for adjustments are saved.' : $msg);
    redirect((string)(p('back') ?: url($pageKey)));
}

if ($a === 'list') {
    render_txn_list($pageKey, ['inventory_adjustment'], 'Inventory Adjustments', ['statuses' => ['open' => 'Posted', 'void' => 'Void'], 'new_label' => 'New adjustment']);
    return;
}
$edit = null;
$editLines = [];
if ($a === 'edit') {
    Access::require($pageKey, 'edit');
    $edit = Txn::load(gint('id') ?? 0);
    if (!$edit || $edit['txn_type'] !== 'inventory_adjustment') {
        flash('warning', 'Adjustment not found.');
        redirect(url($pageKey, ['a' => 'list']));
    }
    if ((int)$edit['is_voided'] === 1) {
        flash('warning', 'A voided adjustment cannot be edited.');
        redirect(Txn::url($edit));
    }
    if (!Access::siteAllowed($edit['site_id'] ? (int)$edit['site_id'] : null)) {
        Access::denyPage($pageKey, 'edit an adjustment of another site on');
    }
    $editLines = array_values(array_filter(Txn::lines((int)$edit['id']), fn($l) => !empty($l['item_id'])));
} else {
    Access::require($pageKey, 'create');
}

// a memorized adjustment loaded into the form ("Load Memorized Adjustment"): its option, type, site and lines
$memAdj = (!$edit && !is_post() && gint('mem')) ? Memorized::forForm((int)gint('mem'), ['inventory_adjustment']) : null;
$mh = $memAdj['header'] ?? [];

// Per Item or Per Batch: the address, then the form, then what this user chose last time
// (a form posted without the choice but with the grid of every item is a Per Batch one)
$entry = (string)(g('entry') ?: p('entry') ?: ($mh['entry'] ?? null) ?: (is_post() && isset($_POST['rows']) ? 'batch' : null) ?: ($_SESSION['adj_entry'] ?? 'item'));
$entry = in_array($entry, ['item', 'batch'], true) ? $entry : 'item';
if ($edit) {
    $entry = 'item';       // an adjustment is edited on lines, whichever way it was entered
} else {
    $_SESSION['adj_entry'] = $entry;
}

// the adjustment being edited, as the Per Item form shows it: its header, and one line per item with the
// difference it made (in the unit it was entered in) and, for a value change, the value the item had after it
if ($edit) {
    $hasQty = $hasVal = false;
    foreach ($editLines as $l) {
        $hasQty = $hasQty || abs((float)$l['qty']) > 0.00001;
        $hasVal = $hasVal || abs((float)$l['value_change']) >= 0.005;
    }
    $editReason = (string)($editLines[0]['description'] ?? '');
    $editMemo = (string)$edit['memo'];
    if ($editReason !== '' && str_starts_with($editMemo, $editReason)) {
        $editMemo = ltrim(substr($editMemo, strlen($editReason)), ' -');
    }
    $mh = [
        'mode' => $hasVal ? ($hasQty ? 'both' : 'value') : 'qty', 'site_id' => $edit['site_id'], 'account_id' => $edit['account_id'],
        'customer_id' => $edit['customer_id'], 'class_id' => $edit['class_id'], 'reason' => $editReason, 'memo' => $editMemo,
        'txn_date' => $edit['txn_date'], 'ref_no' => $edit['ref_no'],
    ];
}

$viewCosts = Access::viewCosts();
$modes = $viewCosts ? ['qty' => 'Quantity', 'value' => 'Total value', 'both' => 'Quantity and total value'] : ['qty' => 'Quantity'];
$mode = (string)(p('mode') ?: g('mode') ?: ($mh['mode'] ?? 'qty'));
$mode = isset($modes[$mode]) ? $mode : 'qty';
$sites = Lookup::sites();
$siteId = (int)(p('site_id') ?: g('site_id') ?: ($mh['site_id'] ?? null) ?: array_key_first($sites));
$reasons = adjust_reasons();
$memRec = $mh ?: null;     // the boxes of a loaded memorized adjustment (or of the adjustment being edited)
$categories = Lookup::categories();
$items = DB::all("SELECT i.id, i.code, i.name, i.unit, i.avg_cost, i.uom_set_id, i.category_id, COALESCE(s.qty_on_hand,0) AS site_qty,
                         (SELECT COALESCE(SUM(qty_on_hand),0) FROM item_site_stock x WHERE x.item_id = i.id) AS total_qty
                    FROM items i LEFT JOIN item_site_stock s ON s.item_id = i.id AND s.site_id = :s
                   WHERE (i.is_active = 1" . ($editLines ? ' OR i.id IN (' . implode(',', array_map(fn($l) => (int)$l['item_id'], $editLines)) . ')' : '') . ")
                     AND i.item_type IN ('inventory','assembly') ORDER BY i.code", ['s' => $siteId]);
$byId = [];
foreach ($items as $it) {
    $byId[(int)$it['id']] = $it;
}
// what the adjustment being edited did, by item: its quantity at its own site, and its value
$own = [];
if ($edit) {
    foreach (DB::all('SELECT item_id, site_id, SUM(qty) AS q, SUM(total_cost) AS v FROM inventory_movements WHERE txn_id = :t GROUP BY item_id, site_id', ['t' => $edit['id']]) as $m) {
        $iid = (int)$m['item_id'];
        $own[$iid]['v'] = ($own[$iid]['v'] ?? 0.0) + (float)$m['v'];
        $own[$iid]['q'] = ($own[$iid]['q'] ?? 0.0) + (float)$m['q'];
        $own[$iid]['sites'][(int)$m['site_id']] = (float)$m['q'];
    }
}

handle_post(function () use ($pageKey, $mode, $siteId, $byId, $entry, $sites, $edit) {
    if (p('memorize') === '1') {
        // "Memorize Adjustment": what is typed on the form is kept as a pro-forma, to be loaded and revised next time
        $keep = ['item_id', 'new_qty', 'qty_diff', 'uom_id', 'new_value', 'class_id'];
        $lines = [];
        if ($entry === 'item') {
            foreach ((array)($_POST['lines'] ?? []) as $r) {
                if (!(int)($r['item_id'] ?? 0)) {
                    continue;
                }
                $lines[] = array_filter(array_intersect_key((array)$r, array_flip($keep)), fn($v) => trim((string)$v) !== '');
            }
        } else {
            foreach ((array)($_POST['rows'] ?? []) as $iid => $r) {
                $row = array_filter(array_intersect_key((array)$r, array_flip(['new_qty', 'new_value'])), fn($v) => trim((string)$v) !== '');
                if ($row && isset($byId[(int)$iid])) {
                    $lines[] = ['item_id' => (int)$iid] + $row;
                }
            }
        }
        if (!$lines) {
            fail('Put at least one item on the adjustment before memorizing it.');
        }
        $header = array_filter([
            'entry' => $entry, 'mode' => $mode, 'site_id' => $siteId, 'account_id' => pint('account_id'), 'customer_id' => pint('customer_id'),
            'class_id' => Classes::ready() ? Classes::posted() : null, 'reason' => (string)p('reason'), 'reason_other' => (string)p('reason_other'), 'memo' => (string)p('memo'),
        ], fn($v) => $v !== null && $v !== '' && $v !== 0);
        $name = Memorized::saveFromForm('inventory_adjustment', ['header' => $header, 'lines' => $lines],
            ($sites[$siteId] ?? '') . ', ' . count($lines) . ' item' . (count($lines) === 1 ? '' : 's'), 0.0);
        throw new FormNotice('Memorized as "' . $name . '" (' . count($lines) . ' item' . (count($lines) === 1 ? '' : 's') . '). Load it with "Load Memorized Adjustment" and revise the figures for the next adjustment. Nothing was posted.');
    }
    if ($edit) {
        // take the adjustment off the books first, so the new figures are measured against the stock without it;
        // the lines are then worked out and the adjustment posted again in the same database transaction
        $id = DB::tx(function () use ($edit, $pageKey, $mode, $siteId, $byId, $entry) {
            Stock::reverseTxn((int)$edit['id']);
            return adjust_post_lines($edit, $pageKey, $mode, $siteId, $byId, $entry);
        });
        flash('success', Txn::ref(Txn::load($id)) . ' updated. Stock and the journal follow the new figures.');
        redirect(url($pageKey, ['a' => 'view', 'id' => $id]));
    }
    $id = adjust_post_lines(null, $pageKey, $mode, $siteId, $byId, $entry);
    flash('success', $GLOBALS['ADJ_COUNT'] . ' item adjustment(s) posted and logged with your name and the reason.');
    redirect_after_save(url($pageKey, ['a' => 'new', 'entry' => $entry, 'mode' => $mode, 'site_id' => $siteId, 'category_ids' => gints('category_ids', 'category_id') ?: null]), url($pageKey, ['a' => 'view', 'id' => $id]));
});

/** Works out the lines of the posted form and saves the adjustment (a new one, or the one edited); returns its id */
function adjust_post_lines(?array $edit, string $pageKey, string $mode, int $siteId, array $byId, string $entry): int
{
    $reason = trim((string)p('reason'));
    if ($reason === '' || ($reason === 'Other' && trim((string)p('reason_other')) === '')) {
        fail('Choose or describe the reason for the adjustment.');
    }
    if ($reason === 'Other') {
        $reason = trim((string)p('reason_other'));
    }
    if (!Access::siteAllowed($siteId)) {
        fail('You are not allowed to adjust stock at that site.');
    }
    $headerClass = Classes::ready() ? Classes::posted() : null;
    $customerId = pint('customer_id') ?: null;
    $lines = [];
    $totalValue = 0.0;

    if ($entry === 'item') {
        // ------------------------------------------------------------ one line per item
        $seen = [];
        $units = Uom::unitsByItem(array_keys($byId));
        foreach ((array)($_POST['lines'] ?? []) as $r) {
            $iid = (int)($r['item_id'] ?? 0);
            if (!$iid) {
                continue;
            }
            if (!isset($byId[$iid])) {
                fail('An item on the lines is not an active inventory part or assembly.');
            }
            $it = $byId[$iid];
            if (isset($seen[$iid])) {
                fail($it['code'] . ' is on two lines. Keep it on one line.');
            }
            $seen[$iid] = true;
            // the unit typed in: the item's own unit, or one of its U/M set
            $uomId = (int)($r['uom_id'] ?? 0);
            $factor = 1.0;
            $unitCode = $it['unit'];
            if ($uomId) {
                $u = null;
                foreach ($units[$iid] ?? [] as $x) {
                    if ($x['id'] === $uomId) {
                        $u = $x;
                    }
                }
                if (!$u) {
                    fail('The U/M on the line of ' . $it['code'] . ' is not one of its units.');
                }
                $factor = $u['factor'] > 0 ? $u['factor'] : 1.0;
                $unitCode = $u['code'];
            }
            $qtyDiff = 0.0;
            $newQty = trim((string)($r['new_qty'] ?? ''));
            $diffTyped = trim((string)($r['qty_diff'] ?? ''));
            // on an edit the adjustment is already off the books: what the site has now is the base
            $base = $edit ? Stock::onHand($iid, $siteId) : (float)$it['site_qty'];
            if ($mode !== 'value') {
                if ($newQty !== '') {
                    $qtyDiff = to_num($newQty) * $factor - $base;
                } elseif ($diffTyped !== '') {
                    $qtyDiff = to_num($diffTyped) * $factor;
                }
            }
            $valueDiff = 0.0;
            if ($mode !== 'qty' && trim((string)($r['new_value'] ?? '')) !== '') {
                $valueDiff = r2(to_num($r['new_value']) - Stock::valueAfter($iid, $qtyDiff));
            }
            if (abs($qtyDiff) < 0.00001 && abs($valueDiff) < 0.005) {
                continue;
            }
            $lineClass = Classes::ready() && (int)($r['class_id'] ?? 0) ? (int)$r['class_id'] : $headerClass;
            $lines[] = [
                'line_kind' => 'item', 'item_id' => $iid, 'qty' => round($qtyDiff, 4), 'rate' => 0, 'value_change' => $valueDiff,
                'site_id' => $siteId, 'description' => $reason, 'class_id' => $lineClass, 'customer_id' => $customerId,
                'unit' => $unitCode, 'uom_id' => $uomId ?: null, 'uom_factor' => $factor, 'uom_qty' => $uomId ? round($qtyDiff / $factor, 4) : 0,
            ];
            $totalValue += abs($qtyDiff * (float)$it['avg_cost']) + abs($valueDiff);
        }
        if (!$lines) {
            fail('No changes entered. Choose the items and type their new quantity (or the difference)' . ($mode !== 'qty' ? ' or their new value' : '') . '.');
        }
    } else {
        // ------------------------------------------------------------ every item of the site in one grid
        foreach ((array)($_POST['rows'] ?? []) as $iid => $r) {
            $iid = (int)$iid;
            if (!isset($byId[$iid])) {
                continue;
            }
            $it = $byId[$iid];
            $qtyDiff = 0.0;
            $valueDiff = 0.0;
            if ($mode !== 'value' && isset($r['new_qty']) && trim((string)$r['new_qty']) !== '') {
                $qtyDiff = to_num($r['new_qty']) - (float)$it['site_qty'];
            }
            if ($mode !== 'qty' && isset($r['new_value']) && trim((string)$r['new_value']) !== '') {
                // the new value is what the item is worth after the adjustment: measured against the value once the
                // quantity change (made first, at the average cost) is in, so the item ends at exactly the value typed
                $valueDiff = r2(to_num($r['new_value']) - Stock::valueAfter($iid, $qtyDiff));
            }
            if (abs($qtyDiff) < 0.00001 && abs($valueDiff) < 0.005) {
                continue;
            }
            $lines[] = [
                'line_kind' => 'item', 'item_id' => $iid, 'qty' => $qtyDiff, 'rate' => 0, 'value_change' => $valueDiff,
                'site_id' => $siteId, 'description' => $reason, 'class_id' => $headerClass, 'customer_id' => $customerId,
            ];
            $totalValue += abs($qtyDiff * (float)$it['avg_cost']) + abs($valueDiff);
        }
        if (!$lines) {
            fail('No changes entered. Type the new quantity or value for the items to adjust.');
        }
    }

    $approver = Approval::check($pageKey, Approval::exceeds(Access::limit('max_amount'), $totalValue), 'inventory adjustment of about ' . money($totalValue));
    $GLOBALS['ADJ_COUNT'] = count($lines);
    if ($edit) {
        // the lines are new rows: the old ones go (nothing points at the lines of an adjustment)
        $note = trim((string)p('change_reason'));
        return Txn::save('inventory_adjustment', [
            'txn_date' => pdate('txn_date', today()),
            'site_id' => $siteId,
            'account_id' => pint('account_id') ?: Accts::get('inventory_adjustment'),
            'customer_id' => $customerId,
            'class_id' => $headerClass,
            'ref_no' => p('ref_no') ?: null,
            'memo' => $reason . (p('memo') ? ' - ' . p('memo') : ''),
            'status' => 'open',
            'approved_by' => $approver['id'] ?? ($edit['approved_by'] ?? null),
        ], $lines, (int)$edit['id'], ['reason' => $note !== '' ? $note : 'Adjustment edited (' . $reason . ')', 'approved_by' => $approver['username'] ?? null]);
    }
    return Txn::save('inventory_adjustment', [
        'txn_date' => pdate('txn_date', today()),
        'site_id' => $siteId,
        'account_id' => pint('account_id') ?: Accts::get('inventory_adjustment'),
        'customer_id' => $customerId,
        'class_id' => $headerClass,
        'ref_no' => p('ref_no') ?: null,
        'memo' => $reason . (p('memo') ? ' - ' . p('memo') : ''),
        'status' => 'open',
        'approved_by' => $approver['id'] ?? null,
    ], $lines, null, ['reason' => $reason, 'approved_by' => $approver['username'] ?? null]);
}

$focus = gint('item_id');
$switch = fn(string $to) => url($pageKey, ['entry' => $to, 'mode' => $mode, 'site_id' => $siteId, 'item_id' => $focus ?: null, 'mem' => $memAdj ? (int)$memAdj['m']['id'] : null]);
[$memButton, $memBox] = $edit ? ['', ''] : memorize_controls('inventory_adjustment', ($sites[$siteId] ?? '') . ' adjustment', 'Memorize Adjustment', 'adjustment');
[$loadButton, $loadList] = $edit ? ['', ''] : memorized_loader(['inventory_adjustment'], $pageKey, 'Load Memorized Adjustment');
$canEditReasons = Access::can($pageKey, 'edit');
// the memorized lines, by item, for the Per Batch grid (a Per Item line typed as a difference becomes the new quantity)
$memRows = [];
foreach ($memAdj['lines'] ?? [] as $ml) {
    $iid = (int)($ml['item_id'] ?? 0);
    if (!isset($byId[$iid]) || !empty($ml['uom_id'])) {
        continue;
    }
    $nq = $ml['new_qty'] ?? (isset($ml['qty_diff']) && $ml['qty_diff'] !== '' ? (float)$byId[$iid]['site_qty'] + to_num($ml['qty_diff']) : null);
    $memRows[$iid] = array_filter(['new_qty' => $nq !== null ? (string)$nq : null, 'new_value' => $ml['new_value'] ?? null], fn($v) => $v !== null && $v !== '');
}
layout_start($edit ? 'Edit Inventory Adjustment ' . $edit['txn_no'] : 'Adjust Quantity/Value on Hand', ['actions' => $edit
    ? btn_link(Txn::url($edit), 'Cancel', 'btn-outline-secondary')
    : $loadButton . ' ' . btn_link(url($pageKey, ['a' => 'list']), 'Adjustments list', 'btn-outline-secondary', 'list-ul')]);
echo memorized_loaded_note($memAdj);
?>
<?php if ($edit): ?>
<p class="text-muted small adj-edit-note">"Qty on hand" and "Current value" are what the site had without this adjustment, so the new quantity, the difference and the new value read as they did when it was entered. Saving takes the adjustment off the books and posts it again with the figures below.</p>
<?php else: ?>
<div class="adj-entry mb-3" role="tablist" aria-label="Adjustment option">
  <span class="adj-entry-label">Adjustment option</span>
  <div class="btn-group adj-entry-switch">
    <a class="btn btn-sm <?= $entry === 'item' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= h($switch('item')) ?>" role="tab" aria-selected="<?= $entry === 'item' ? 'true' : 'false' ?>"><i class="bi bi-list-check"></i> Per Item Adjustment</a>
    <a class="btn btn-sm <?= $entry === 'batch' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= h($switch('batch')) ?>" role="tab" aria-selected="<?= $entry === 'batch' ? 'true' : 'false' ?>"><i class="bi bi-grid-3x3"></i> Per Batch Adjustment</a>
  </div>
  <span class="small text-muted adj-entry-hint"><?= $entry === 'item' ? 'Pick the items to adjust on lines, one by one or several at once.' : 'Every item of the site in one grid: type the new figures of the ones that changed.' ?></span>
</div>
<?php endif; ?>
<?php if ($entry === 'item'): ?>
<?php
    // ------------------------------------------------------------------ Per Item Adjustment
    $units = Uom::unitsByItem(array_keys($byId));
    $stock = [];
    foreach (DB::all('SELECT item_id, site_id, qty_on_hand FROM item_site_stock WHERE qty_on_hand <> 0') as $s) {
        $stock[(int)$s['item_id']][(int)$s['site_id']] = (float)$s['qty_on_hand'];
    }
    // editing: the stock without this adjustment
    foreach ($own as $iid => $o) {
        foreach ($o['sites'] as $sid => $q) {
            $stock[$iid][$sid] = round(($stock[$iid][$sid] ?? 0.0) - $q, 4);
        }
    }
    $itemData = [];
    $itemOpts = [];
    foreach ($items as $it) {
        [$c1, $c2] = item_vals($it['code'], $it['name'], $pageKey);
        $itemOpts[(int)$it['id']] = $c1 . ' - ' . $c2;
        $totalQty = (float)$it['total_qty'];
        $avg = (float)$it['avg_cost'];
        if (isset($own[(int)$it['id']])) {
            $o = $own[(int)$it['id']];
            $baseQty = $totalQty - $o['q'];
            $baseVal = Stock::valueOnHand((int)$it['id']) - $o['v'];
            $totalQty = $baseQty;
            $avg = ($baseQty > 0.000001 && $baseVal > 0.004) ? $baseVal / $baseQty : $avg;
        }
        $itemData[(int)$it['id']] = [
            'code' => $it['code'], 'name' => $it['name'], 'unit' => (string)$it['unit'],
            'avg' => $viewCosts ? round($avg, 4) : 0, 'total' => $totalQty, 'cat' => (int)$it['category_id'],
            'stock' => $stock[(int)$it['id']] ?? new stdClass(), 'units' => $units[(int)$it['id']] ?? [],
        ];
    }
    $posted = (array)($_POST['lines'] ?? []);
    if (!$posted && $edit) {
        $fq = fn($v) => rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.');
        foreach ($editLines as $l) {
            $iid = (int)$l['item_id'];
            $row = ['item_id' => $iid];
            if (abs((float)$l['qty']) > 0.00001) {
                $row['qty_diff'] = $fq($l['uom_id'] ? (float)$l['uom_qty'] : (float)$l['qty']);
                // the quantity the site had after it: what it has without the adjustment, plus the difference
                $factor = $l['uom_id'] && (float)$l['uom_factor'] > 0 ? (float)$l['uom_factor'] : 1.0;
                $siteBase = (float)($stock[$iid][(int)$edit['site_id']] ?? 0.0);
                $row['new_qty'] = $fq(($siteBase + (float)$l['qty']) / $factor);
                if ($l['uom_id']) {
                    $row['uom_id'] = (int)$l['uom_id'];
                }
            }
            if (abs((float)$l['value_change']) >= 0.005 && $viewCosts) {
                // the value the item had after this adjustment: its value without it, plus what it changed
                $row['new_value'] = number_format(Stock::valueOnHand($iid), 2, '.', '');
            }
            if ($l['class_id'] && (int)$l['class_id'] !== (int)$edit['class_id']) {
                $row['class_id'] = (int)$l['class_id'];
            }
            $posted[] = $row;
        }
    }
    if (!$posted && $memAdj) {
        $posted = array_values(array_filter($memAdj['lines'], fn($l) => isset($byId[(int)($l['item_id'] ?? 0)])));
    }
    if (!$posted) {
        $posted = $focus && isset($byId[$focus]) ? [['item_id' => $focus]] : [];
        while (count($posted) < 3) {
            $posted[] = [];
        }
    }
    $classOn = Classes::ready();
    $lineCell = function (string $k, array $r) use ($itemOpts, $classOn, $byId, $viewCosts): string {
        $iid = (int)($r['item_id'] ?? 0);
        $h = '<tr class="adj-line">'
            . '<td data-label="Item"><select class="form-select form-select-sm adj-item" name="lines[' . $k . '][item_id]" data-combo aria-label="Item">' . options($itemOpts, $iid ?: null, '') . '</select></td>'
            . '<td data-label="Description" class="small adj-desc">' . h($byId[$iid]['name'] ?? '') . '</td>'
            . '<td data-label="Qty on hand" class="num adj-onhand"></td>'
            . '<td data-label="New quantity" class="adj-q"><input class="form-control form-control-sm text-end adj-newq" name="lines[' . $k . '][new_qty]" inputmode="decimal" value="' . h($r['new_qty'] ?? '') . '" aria-label="New quantity"></td>'
            . '<td data-label="Qty difference" class="adj-q"><input class="form-control form-control-sm text-end adj-diffq" name="lines[' . $k . '][qty_diff]" inputmode="decimal" value="' . h($r['qty_diff'] ?? '') . '" aria-label="Quantity difference"></td>'
            . '<td data-label="U/M"><select class="form-select form-select-sm adj-uom" name="lines[' . $k . '][uom_id]" data-no-combo data-value="' . h($r['uom_id'] ?? '') . '" aria-label="U/M"></select></td>'
            . '<td data-label="Current value" class="num adj-v adj-curval"></td>'
            . '<td data-label="New value" class="adj-v"><input class="form-control form-control-sm text-end adj-newv" name="lines[' . $k . '][new_value]" inputmode="decimal" value="' . h($r['new_value'] ?? '') . '" aria-label="New value"></td>'
            . ($viewCosts ? '<td data-label="Net adjustment" class="num adj-net" title="What the line changes the value of the stock by"></td>' : '')
            . ($classOn ? '<td data-label="Class">' . Classes::lineSelect('lines[' . $k . '][class_id]', $r['class_id'] ?? null) . '</td>' : '')
            . '<td class="text-end adj-x grid-acts"><button type="button" class="btn btn-sm btn-link p-0 grid-ins adj-ins" title="Insert a line above this one" aria-label="Insert a line above this one"><i class="bi bi-plus-square"></i></button>'
            . '<button type="button" class="btn btn-sm btn-link text-danger p-0 adj-remove" title="Remove the line" aria-label="Remove the line"><i class="bi bi-x-lg"></i></button></td>'
            . '</tr>';
        return $h;
    };
?>
<form method="post" id="adjForm" class="adj-item-form" data-mode="<?= h($mode) ?>" data-site="<?= (int)$siteId ?>"><?= csrf_field() ?><?= negative_stock_fields() ?>
<input type="hidden" name="entry" value="item">
<div class="card mb-3"><div class="card-body row g-2">
  <?= select_field('mode', 'Adjustment type', $modes, $mode, ['class' => 'col-md-3 col-lg-2', 'extra' => 'id="adjMode" data-no-combo']) ?>
  <?= input_field('txn_date', 'Adjustment date', fv('txn_date', $edit ? $memRec : null, today()), ['type' => 'date', 'class' => 'col-6 col-md-3 col-lg-2']) ?>
  <?= input_field('ref_no', 'Reference no.', fv('ref_no', $edit ? $memRec : null), ['class' => 'col-6 col-md-3 col-lg-2']) ?>
  <?= select_field('account_id', 'Adjustment account', Accts::all(), fv('account_id', $memRec, Accts::get('inventory_adjustment')), ['class' => 'col-md-6 col-lg-3']) ?>
  <?= select_field('site_id', 'Inventory site', $sites, $siteId, ['class' => 'col-md-6 col-lg-3', 'extra' => 'id="adjSite"']) ?>
  <?= select_field('customer_id', 'Customer:Job', Lookup::customers(), fv('customer_id', $memRec), ['blank' => '', 'class' => 'col-md-6 col-lg-3']) ?>
  <?= Classes::field(fv('class_id', $memRec), 'col-md-6 col-lg-3') ?>
  <?= adjust_reason_field($reasons, fv('reason', $memRec), 'col-md-6 col-lg-3', $canEditReasons) ?>
  <div class="col-md-6 col-lg-3" data-toggle-by="#reasonSel" data-show-when="Other"><label class="form-label">Describe</label><input class="form-control form-control-sm" name="reason_other" value="<?= h(fv('reason_other', $memRec)) ?>"></div>
  <?php if ($edit): ?><?= input_field('change_reason', 'Why it is being changed', fv('change_reason'), ['class' => 'col-md-12 col-lg-6', 'extra' => 'maxlength="255" placeholder="Recorded in the audit trail"']) ?><?php endif; ?>
</div></div>

<div class="card mb-3 adj-lines-card">
  <div class="card-body pb-0 d-flex flex-wrap gap-2 align-items-center">
    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#adjFind"><i class="bi bi-search"></i> Find &amp; Select Items...</button>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="adjZero" title="Make the new quantity of every line 0"><i class="bi bi-0-circle"></i> Set Qty to Zero</button>
    <div class="adj-cat-pick" title="Every item of the ticked categories on the lines at once">
      <span class="adj-cat-label">Categories</span>
      <?= multi_check_field('', 'Categories to add', $categories, [], ['all' => 'Tick one or more', 'noun' => 'categories', 'class' => 'adj-cat-drop', 'id' => 'adjCat', 'nolabel' => true]) ?>
      <button type="button" class="btn btn-sm btn-outline-secondary" id="adjCatAdd"><i class="bi bi-collection"></i> Add their items</button>
    </div>
    <span class="small text-muted ms-xl-auto">Type the <strong>new quantity</strong> or the <strong>difference</strong>; the other follows.</span>
  </div>
  <div class="card-body">
    <div class="table-responsive adj-lines-wrap"><table class="table table-sm align-middle mb-0 adj-lines" id="adjLines" data-items="<?= h(json_encode($itemData, JSON_UNESCAPED_UNICODE)) ?>">
      <thead><tr><th class="adj-col-item">Item</th><th>Description</th><th class="num">Qty on hand</th><th class="num adj-q">New quantity</th><th class="num adj-q">Qty difference</th><th>U/M</th>
        <th class="num adj-v">Current value</th><th class="num adj-v">New value</th><?= $viewCosts ? '<th class="num" title="What the line changes the value of the stock by">Net adjustment</th>' : '' ?><?= $classOn ? '<th>Class</th>' : '' ?><th></th></tr></thead>
      <tbody>
      <?php foreach (array_values($posted) as $i => $r): ?><?= $lineCell((string)$i, (array)$r) ?><?php endforeach; ?>
      </tbody>
    </table></div>
    <template id="adjTpl"><?= $lineCell('__k__', []) ?></template>
    <div class="grid-add mt-2" id="adjAdd"><?php foreach ([1 => 'Add line', 5 => 'Add 5 lines', 10 => 'Add 10 lines'] as $n => $lbl): ?><button type="button" class="btn btn-sm btn-outline-secondary" data-count="<?= $n ?>"><i class="bi bi-plus-lg"></i> <?= h($lbl) ?></button><?php endforeach; ?></div>
  </div>
  <div class="card-footer adj-foot">
    <?= input_field('memo', 'Memo', fv('memo', $memRec), ['class' => 'adj-memo']) ?>
    <div class="adj-totals">
      <?php if ($viewCosts): ?><div><span>Total value of adjustment</span><strong id="adjTotalValue">0.00</strong></div><?php endif; ?>
      <div><span>Number of item adjustments</span><strong id="adjCount">0</strong></div>
    </div>
  </div>
</div>
<?= Approval::fields() ?>
<p class="small text-muted mt-2">A decrease debits the adjustment account and credits Inventory at average cost; an increase does the reverse. Lines without an item, or without a change, are left out.<?php if ($viewCosts): ?>
  <span class="adj-v-note"><strong>New value</strong> is what the item is worth in total (all sites) after the adjustment; with a new quantity typed it is worked out at the average cost until you type a value of your own.</span>
  <strong>Net adjustment</strong> is what each line changes the value of the stock by.<?php endif; ?></p>
<div class="d-flex flex-wrap gap-2 align-items-center">
  <?php if ($edit): ?>
  <button class="btn btn-sm btn-primary" name="save" value="1"><i class="bi bi-check2"></i> Save changes</button>
  <?= btn_link(Txn::url($edit), 'Cancel', 'btn-outline-secondary') ?>
  <?php else: ?>
  <button class="btn btn-sm btn-outline-primary" name="save" value="1"><i class="bi bi-check2"></i> Save &amp; Close</button>
  <button class="btn btn-sm btn-primary" name="save_new" value="1"><i class="bi bi-check2-all"></i> Save &amp; New</button>
  <a class="btn btn-sm btn-outline-secondary" href="<?= h(url($pageKey, ['entry' => 'item', 'mode' => $mode, 'site_id' => $siteId])) ?>" data-confirm="Clear every line and start again?"><i class="bi bi-eraser"></i> Clear</a>
  <?php endif; ?>
  <?php if ($memButton): ?><div class="ms-auto"><?= $memButton ?></div><?php endif; ?>
</div>
<?= $memBox ?>
</form>

<div class="modal fade" id="adjFind" tabindex="-1" aria-labelledby="adjFindTitle"><div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="adjFindTitle">Find &amp; Select Items</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body">
    <div class="live-search mb-2"><i class="bi bi-search"></i><input class="form-control form-control-sm" type="search" id="adjFindQ" placeholder="Type part of a code, name or barcode" autocomplete="off"
         data-live-filter="#adjFindList tbody tr" data-live-count="#adjFindCount" data-live-empty="#adjFindNone" data-live-enter-submits></div>
    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center small mb-1">
      <span class="live-count"><span id="adjFindCount"><?= count($items) ?></span> item(s)</span>
      <span class="d-flex flex-wrap gap-3 align-items-center">
        <span class="d-flex gap-1 align-items-center">Categories <?= multi_check_field('', 'Categories shown', $categories, [], ['all' => 'All categories', 'noun' => 'categories', 'class' => 'adj-find-cat', 'id' => 'adjFindCat', 'nolabel' => true]) ?></span>
        <label class="form-check-label"><input class="form-check-input me-1" type="checkbox" id="adjFindStock"> Only items in stock at the site</label></span></div>
    <div class="table-responsive adj-find-box"><table class="table table-sm table-hover mb-0" id="adjFindList">
      <thead><tr><th style="width:2rem"><input class="form-check-input" type="checkbox" id="adjFindAll" aria-label="Select every item shown"></th><?php [$h1, $h2] = item_cols($pageKey); ?><th><?= h($h1) ?></th><th><?= h($h2) ?></th><th class="num">Qty on hand</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): [$c1, $c2] = item_vals($it['code'], $it['name'], $pageKey); ?>
        <tr data-id="<?= (int)$it['id'] ?>" data-cat="<?= (int)$it['category_id'] ?>" data-filter-text="<?= h($it['code'] . ' ' . $it['name'] . ' ' . ($categories[$it['category_id']] ?? '')) ?>"><td><input class="form-check-input adj-pick" type="checkbox" value="<?= (int)$it['id'] ?>" aria-label="Select <?= h($it['code']) ?>"></td>
          <td><?= h($c1) ?></td><td class="small"><?= h($c2) ?></td><td class="num adj-find-qty"></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table><div class="p-3 small text-muted" id="adjFindNone">No item matches.</div></div>
  </div>
  <div class="modal-footer"><span class="small text-muted me-auto" id="adjFindPicked">None selected</span>
    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="button" class="btn btn-sm btn-primary" id="adjFindAdd"><i class="bi bi-plus-lg"></i> Add the selected items</button></div>
</div></div></div>

<script>
(function () {
  'use strict';
  var form = document.getElementById('adjForm');
  var table = document.getElementById('adjLines');
  var tbody = table.querySelector('tbody');
  var items = JSON.parse(table.dataset.items || '{}');
  var site = function () { return document.getElementById('adjSite').value; };
  var mode = function () { return document.getElementById('adjMode').value; };
  var num = function (v) { v = String(v == null ? '' : v).replace(/,/g, '').trim(); if (v === '') { return null; } var n = parseFloat(v); return isNaN(n) ? null : n; };
  var fmt = function (v, d) { return (v < 0 ? '-' : '') + Math.abs(v).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d }); };
  var qfmt = function (v) { return Math.abs(v - Math.round(v)) < 1e-9 ? fmt(v, 0) : fmt(v, 4).replace(/0+$/, '').replace(/\.$/, ''); };
  var onHand = function (it) { var s = it.stock || {}; return parseFloat(s[site()] || 0); };
  var factorOf = function (tr) { var u = tr.querySelector('.adj-uom'); var o = u.options[u.selectedIndex]; return o ? parseFloat(o.dataset.factor || '1') : 1; };

  var fillUnits = function (tr, keep) {
    var it = items[tr.querySelector('.adj-item').value];
    var sel = tr.querySelector('.adj-uom');
    var want = keep ? (sel.value || sel.dataset.value) : '';
    sel.innerHTML = '';
    if (!it) { sel.disabled = true; return; }
    if (!it.units.length) {
      sel.insertAdjacentHTML('beforeend', '<option value="" data-factor="1"></option>');
      sel.options[0].textContent = it.unit || '';
    } else {
      it.units.forEach(function (u) {
        var o = document.createElement('option');
        o.value = u.base ? '' : u.id; o.dataset.factor = u.factor; o.textContent = u.code + (u.base ? '' : ' (' + qfmt(u.factor) + ')');
        if (String(u.id) === String(want) || (!want && u.base)) { o.selected = true; }
        sel.appendChild(o);
      });
    }
    sel.disabled = sel.options.length < 2;
  };

  var refresh = function (tr, from) {
    var it = items[tr.querySelector('.adj-item').value];
    var newq = tr.querySelector('.adj-newq'), diffq = tr.querySelector('.adj-diffq');
    tr.querySelector('.adj-desc').textContent = it ? it.name : '';
    if (!it) { tr.querySelector('.adj-onhand').textContent = ''; tr.querySelector('.adj-curval').textContent = ''; tr.classList.remove('is-changed'); return; }
    var f = factorOf(tr) || 1;
    var have = onHand(it) / f;
    tr.querySelector('.adj-onhand').textContent = qfmt(have);
    tr.querySelector('.adj-curval').textContent = fmt(it.total * it.avg, 2);
    if (from === 'new') {
      var n = num(newq.value); diffq.value = n === null ? '' : qfmt(n - have).replace(/,/g, '');
    } else if (from === 'diff') {
      var d = num(diffq.value); newq.value = d === null ? '' : qfmt(have + d).replace(/,/g, '');
    } else if (num(newq.value) !== null) {
      diffq.value = qfmt(num(newq.value) - have).replace(/,/g, '');
    }
    // Quantity and total value: the new value that the new quantity gives at the average cost, until a value is typed
    var nvIn = tr.querySelector('.adj-newv');
    if (mode() === 'both' && (nvIn.value === '' || nvIn.dataset.auto === '1')) {
      var dq = num(diffq.value);
      if (dq === null || Math.abs(dq) < 1e-9) {
        if (nvIn.dataset.auto === '1') { nvIn.value = ''; delete nvIn.dataset.auto; }
      } else {
        nvIn.value = ((it.total + dq * f) * it.avg).toFixed(2); nvIn.dataset.auto = '1';
      }
    } else if (mode() !== 'both' && nvIn.dataset.auto === '1') {
      nvIn.value = ''; delete nvIn.dataset.auto;
    }
    nvIn.classList.toggle('is-auto', nvIn.dataset.auto === '1');
    var netCell = tr.querySelector('.adj-net');
    if (netCell) {
      var net = lineNet(tr, it);
      netCell.textContent = net === null ? '' : fmt(net, 2);
      netCell.classList.toggle('neg', net !== null && net < -0.004);
    }
    tr.classList.toggle('is-changed', (num(diffq.value) || 0) !== 0 || num(nvIn.value) !== null);
  };

  /** What a line changes the value of the stock by: the quantity change at the average cost, and any value typed */
  var lineNet = function (tr, it) {
    var d = mode() === 'value' ? 0 : (num(tr.querySelector('.adj-diffq').value) || 0) * (factorOf(tr) || 1);
    var nvIn = tr.querySelector('.adj-newv');
    var nv = mode() === 'qty' || nvIn.dataset.auto === '1' ? null : num(nvIn.value);
    if (Math.abs(d) < 1e-9 && nv === null) { return null; }
    return d * it.avg + (nv === null ? 0 : nv - (it.total + d) * it.avg);
  };

  var totals = function () {
    var count = 0, value = 0;
    tbody.querySelectorAll('tr.adj-line').forEach(function (tr) {
      var it = items[tr.querySelector('.adj-item').value];
      if (!it) { return; }
      var net = lineNet(tr, it);
      if (net === null) { return; }
      count++;
      value += net;
    });
    document.getElementById('adjCount').textContent = count;
    var tv = document.getElementById('adjTotalValue');
    if (tv) { tv.textContent = fmt(value, 2); tv.classList.toggle('neg', value < -0.004); }
  };

  var showMode = function () {
    form.dataset.mode = mode();
    form.classList.toggle('adj-mode-value', mode() === 'value');
    form.classList.toggle('adj-mode-qty', mode() === 'qty');
    tbody.querySelectorAll('tr.adj-line').forEach(function (tr) { refresh(tr); });
    totals();
  };
  // a new value worked out from the new quantity is only shown: the quantity alone is sent, which gives that value
  form.addEventListener('submit', function () {
    var auto = Array.prototype.filter.call(form.querySelectorAll('.adj-newv'), function (i) { return i.dataset.auto === '1'; });
    auto.forEach(function (i) { i.disabled = true; });
    setTimeout(function () { auto.forEach(function (i) { i.disabled = false; }); }, 0);
  });

  var wire = function (tr) {
    if (window.AppCombo) { window.AppCombo.init(tr); }
    fillUnits(tr, true);
    refresh(tr);
  };
  var seq = 0;
  var markNew = function (rows) {
    rows.forEach(function (r) { r.classList.add('row-new'); setTimeout(function () { r.classList.remove('row-new'); }, 1300); });
    if (rows[0]) { rows[0].scrollIntoView({ block: 'nearest' }); }
  };
  var addLine = function (itemId, before) {
    var html = document.getElementById('adjTpl').innerHTML.replace(/__k__/g, 'n' + Date.now() + '_' + (seq++));
    // reuse an empty line before adding one
    var empty = itemId ? Array.prototype.find.call(tbody.querySelectorAll('tr.adj-line'), function (tr) { return !tr.querySelector('.adj-item').value; }) : null;
    var tr = empty;
    if (!tr) {
      var box = document.createElement('tbody'); box.innerHTML = html; tr = box.firstElementChild;
      tbody.insertBefore(tr, before || null); wire(tr);
    }
    if (itemId) {
      var sel = tr.querySelector('.adj-item'); sel.value = itemId;
      sel.dispatchEvent(new Event('change', { bubbles: true }));
      if (sel.comboSync) { sel.comboSync(); }
    }
    return tr;
  };

  tbody.addEventListener('change', function (e) {
    var tr = e.target.closest('tr.adj-line'); if (!tr) { return; }
    if (e.target.classList.contains('adj-item')) {
      var dup = Array.prototype.filter.call(tbody.querySelectorAll('.adj-item'), function (s) { return s.value && s.value === e.target.value; }).length > 1;
      tr.classList.toggle('is-dup', dup);
      fillUnits(tr, false); refresh(tr);
    } else if (e.target.classList.contains('adj-uom')) { refresh(tr, tr.dataset.last || 'new'); }
    totals();
  });
  tbody.addEventListener('input', function (e) {
    var tr = e.target.closest('tr.adj-line'); if (!tr) { return; }
    if (e.target.classList.contains('adj-newv')) { delete e.target.dataset.auto; }
    if (e.target.classList.contains('adj-newq')) { tr.dataset.last = 'new'; refresh(tr, 'new'); }
    else if (e.target.classList.contains('adj-diffq')) { tr.dataset.last = 'diff'; refresh(tr, 'diff'); }
    else { refresh(tr); }
    totals();
  });
  tbody.addEventListener('click', function (e) {
    var ins = e.target.closest('.adj-ins');
    if (ins) { markNew([addLine(null, ins.closest('tr'))]); return; }
    var b = e.target.closest('.adj-remove'); if (!b) { return; }
    b.closest('tr').remove();
    if (!tbody.querySelector('tr.adj-line')) { addLine(); }
    totals();
  });
  document.getElementById('adjAdd').addEventListener('click', function (e) {
    var b = e.target.closest('button[data-count]'); if (!b) { return; }
    var added = [];
    for (var i = 0, n = parseInt(b.dataset.count, 10) || 1; i < n; i++) { added.push(addLine()); }
    markNew(added);
  });
  document.getElementById('adjZero').addEventListener('click', function () {
    tbody.querySelectorAll('tr.adj-line').forEach(function (tr) {
      if (!tr.querySelector('.adj-item').value) { return; }
      tr.querySelector('.adj-newq').value = '0'; tr.dataset.last = 'new'; refresh(tr, 'new');
    });
    totals();
  });
  document.getElementById('adjSite').addEventListener('change', function () { form.dataset.site = site(); tbody.querySelectorAll('tr.adj-line').forEach(function (tr) { refresh(tr, tr.dataset.last || 'new'); }); totals(); findQty(); });
  document.getElementById('adjMode').addEventListener('change', showMode);

  // ---- Find & Select Items
  var list = document.getElementById('adjFindList');
  // the values ticked in one of the category check lists
  var ticked = function (id) {
    return Array.prototype.map.call(document.querySelectorAll('#' + id + ' input[type=checkbox]:checked'), function (cb) { return cb.value; });
  };
  var findQty = function () {
    var onlyStock = document.getElementById('adjFindStock').checked;
    var cats = ticked('adjFindCat');
    list.querySelectorAll('tbody tr').forEach(function (tr) {
      var it = items[tr.dataset.id]; var q = it ? onHand(it) : 0;
      tr.querySelector('.adj-find-qty').textContent = qfmt(q);
      tr.classList.toggle('adj-find-off', (onlyStock && Math.abs(q) < 1e-9) || (cats.length > 0 && cats.indexOf(tr.dataset.cat) < 0));
    });
    document.getElementById('adjFindCount').textContent = list.querySelectorAll('tbody tr:not(.adj-find-off):not(.live-hide)').length;
  };
  var picked = function () {
    var n = list.querySelectorAll('.adj-pick:checked').length;
    document.getElementById('adjFindPicked').textContent = n ? n + ' selected' : 'None selected';
  };
  list.addEventListener('change', picked);
  document.getElementById('adjFindStock').addEventListener('change', findQty);
  document.getElementById('adjFindCat').addEventListener('change', findQty);
  // every item of the ticked categories on the lines at once (the ones already on a line are left as they are)
  document.getElementById('adjCatAdd').addEventListener('click', function () {
    var btn = document.querySelector('#adjCat .multi-check-btn');
    var cats = ticked('adjCat');
    if (!cats.length) { btn.classList.add('is-invalid'); if (window.bootstrap) { setTimeout(function () { bootstrap.Dropdown.getOrCreateInstance(btn).show(); }, 0); } return; }
    var on = {}; tbody.querySelectorAll('.adj-item').forEach(function (s) { if (s.value) { on[s.value] = true; } });
    var added = 0;
    list.querySelectorAll('tbody tr').forEach(function (tr) {
      if (cats.indexOf(tr.dataset.cat) >= 0 && !on[tr.dataset.id]) { addLine(tr.dataset.id); on[tr.dataset.id] = true; added++; }
    });
    totals();
    btn.classList.toggle('is-invalid', added === 0);
    if (added && window.bootstrap) { bootstrap.Dropdown.getOrCreateInstance(btn).hide(); }
    if (added) { var first = tbody.querySelector('tr.adj-line:last-child'); if (first) { first.scrollIntoView({ block: 'nearest' }); } }
  });
  document.getElementById('adjFindAll').addEventListener('change', function (e) {
    list.querySelectorAll('tbody tr').forEach(function (tr) {
      if (!tr.classList.contains('live-hide') && !tr.classList.contains('adj-find-off')) { tr.querySelector('.adj-pick').checked = e.target.checked; }
    });
    picked();
  });
  document.getElementById('adjFind').addEventListener('show.bs.modal', function () {
    var on = {}; tbody.querySelectorAll('.adj-item').forEach(function (s) { if (s.value) { on[s.value] = true; } });
    list.querySelectorAll('.adj-pick').forEach(function (cb) { cb.checked = false; cb.disabled = !!on[cb.value]; cb.closest('tr').classList.toggle('text-muted', !!on[cb.value]); });
    findQty(); picked();
    setTimeout(function () { document.getElementById('adjFindQ').focus(); }, 300);
  });
  document.getElementById('adjFindAdd').addEventListener('click', function () {
    list.querySelectorAll('.adj-pick:checked').forEach(function (cb) { addLine(cb.value); });
    totals();
    if (window.bootstrap) { bootstrap.Modal.getOrCreateInstance(document.getElementById('adjFind')).hide(); }
  });

  tbody.querySelectorAll('tr.adj-line').forEach(wire);
  showMode();
})();
</script>
<?php else: ?>
<?php // ------------------------------------------------------------------ Per Batch Adjustment (every item of the site) ?>
<?php
    // one category, two or more, or all of them (nothing ticked)
    $catIds = array_values(array_filter(gints('category_ids', 'category_id'), fn($c) => isset($categories[$c])));
    $shown = $catIds ? array_values(array_filter($items, fn($it) => in_array((int)$it['category_id'], $catIds, true))) : $items;
    $catNames = array_values(array_intersect_key($categories, array_flip($catIds)));   // in the order of the list
    $showNet = $viewCosts;
?>
<form method="get" class="row g-2 mb-2 filters">
  <input type="hidden" name="p" value="<?= h($pageKey) ?>"><input type="hidden" name="entry" value="batch">
  <?php if ($memAdj): ?><input type="hidden" name="mem" value="<?= (int)$memAdj['m']['id'] ?>"><?php endif; ?>
  <?= select_field('mode', 'Adjustment type', $modes, $mode, ['class' => 'col-md-3', 'extra' => 'data-autosubmit']) ?>
  <?= select_field('site_id', 'Site', $sites, $siteId, ['class' => 'col-md-3', 'extra' => 'data-autosubmit']) ?>
  <?= multi_check_field('category_ids', 'Categories', $categories, $catIds, ['all' => 'All categories', 'noun' => 'categories', 'submit' => true, 'class' => 'col-md-4', 'id' => 'adjBatchCats']) ?>
</form>
<form method="post" id="adjBatchForm" class="adj-batch-form" data-mode="<?= h($mode) ?>" action="<?= h(url($pageKey, ['entry' => 'batch', 'category_ids' => $catIds ?: null])) ?>"><?= csrf_field() ?><?= negative_stock_fields() ?>
<input type="hidden" name="entry" value="batch">
<input type="hidden" name="mode" value="<?= h($mode) ?>"><input type="hidden" name="site_id" value="<?= (int)$siteId ?>">
<div class="card mb-3"><div class="card-body row g-2">
  <?= input_field('txn_date', 'Adjustment date', fv('txn_date', null, today()), ['type' => 'date', 'class' => 'col-md-2']) ?>
  <?= input_field('ref_no', 'Reference no.', fv('ref_no'), ['class' => 'col-md-2']) ?>
  <?= select_field('account_id', 'Adjustment account', Accts::all(), fv('account_id', $memRec, Accts::get('inventory_adjustment')), ['class' => 'col-md-3']) ?>
  <?= adjust_reason_field($reasons, fv('reason', $memRec), 'col-md-2', $canEditReasons) ?>
  <div class="col-md-3" data-toggle-by="#reasonSel" data-show-when="Other"><label class="form-label">Describe</label><input class="form-control form-control-sm" name="reason_other" value="<?= h(fv('reason_other', $memRec)) ?>"></div>
  <?= input_field('memo', 'Memo', fv('memo', $memRec), ['class' => 'col-md-6']) ?>
  <?= Classes::field(fv('class_id', $memRec), 'col-md-3') ?>
</div></div>
<div class="card mb-3 adj-batch-card"><div class="table-responsive" style="max-height:60vh"><table class="table table-sm table-hover mb-0 adj-batch" id="adjBatch">
  <thead class="sticky-top bg-white"><tr><?php [$h1, $h2] = item_cols(); ?><th><?= h($h1) ?></th><th><?= h($h2) ?></th>
    <?php if ($mode !== 'value'): ?><th class="num">Qty at site</th><th class="num">New qty</th><th class="num">Change</th><?php endif; ?>
    <?php if ($mode !== 'qty'): ?><th class="num">Total qty</th><th class="num">Current value</th><th class="num">New value</th><?php endif; ?>
    <?php if ($showNet): ?><th class="num" title="What the row changes the value of the stock by">Net adjustment</th><?php endif; ?>
  </tr></thead>
  <tbody>
  <?php foreach ($shown as $it):
      $post = $_POST['rows'][$it['id']] ?? ($memRows[(int)$it['id']] ?? []);
  ?>
    <tr class="<?= $focus === (int)$it['id'] ? 'table-warning' : '' ?>" data-cur="<?= h($it['site_qty']) ?>" data-total="<?= h($it['total_qty']) ?>" data-avg="<?= h($viewCosts ? $it['avg_cost'] : 0) ?>">
      <?php [$c1, $c2] = item_vals($it['code'], $it['name']); ?><td><?= h($c1) ?></td><td class="small"><?= h($c2) ?></td>
      <?php if ($mode !== 'value'): ?>
        <td class="num"><?= qty($it['site_qty']) ?></td>
        <td><input class="form-control form-control-sm text-end ms-auto adj-new" style="width:100px" name="rows[<?= (int)$it['id'] ?>][new_qty]" value="<?= h($post['new_qty'] ?? '') ?>" inputmode="decimal" aria-label="New quantity of <?= h($it['code']) ?>" <?= $focus === (int)$it['id'] ? 'autofocus' : '' ?>></td>
        <td class="num adj-diff"></td>
      <?php endif; ?>
      <?php if ($mode !== 'qty'): ?>
        <td class="num"><?= qty($it['total_qty']) ?></td><td class="num"><?= money((float)$it['total_qty'] * (float)$it['avg_cost']) ?></td>
        <td><input class="form-control form-control-sm text-end ms-auto adj-val" style="width:120px" name="rows[<?= (int)$it['id'] ?>][new_value]" value="<?= h($post['new_value'] ?? '') ?>" inputmode="decimal" aria-label="New value of <?= h($it['code']) ?>"></td>
      <?php endif; ?>
      <?php if ($showNet): ?><td class="num adj-net"></td><?php endif; ?>
    </tr>
  <?php endforeach; ?>
  <?php if (!$shown): ?><tr><td colspan="9" class="text-muted text-center py-3">No active inventory part or assembly in <?= count($catIds) > 1 ? 'these categories' : 'this category' ?>.</td></tr><?php endif; ?>
  </tbody>
</table></div>
  <div class="card-footer adj-foot">
    <div class="small text-muted"><?= count($shown) ?> item(s)<?= $catIds ? ' in ' . h(count($catNames) > 1 ? implode(', ', array_slice($catNames, 0, -1)) . ' and ' . end($catNames) : $catNames[0]) : '' ?> at <?= h($sites[$siteId] ?? '') ?></div>
    <div class="adj-totals">
      <?php if ($showNet): ?><div><span>Total value of adjustment</span><strong id="adjBatchValue">0.00</strong></div><?php endif; ?>
      <div><span>Number of item adjustments</span><strong id="adjBatchCount">0</strong></div>
    </div>
  </div>
</div>
<?= Approval::fields() ?>
<p class="small text-muted mt-2">A decrease debits the adjustment (shrinkage) account and credits Inventory at average cost; an increase does the reverse. Leave a row blank to keep it unchanged.<?php if ($mode !== 'qty'): ?>
  <strong>New value</strong> is what the item is worth in total (all sites) after the adjustment<?= $mode === 'both' ? '; typing the new quantity works it out at the average cost, until you type a value of your own' : '' ?>.<?php endif; ?>
  <?php if ($showNet): ?><strong>Net adjustment</strong> is what the row changes the value of the stock by.<?php endif; ?></p>
<div class="d-flex flex-wrap gap-2 align-items-center">
  <?= save_buttons('Post adjustment', url($pageKey), Access::can($pageKey, 'create')) ?>
  <?php if ($memButton): ?><div class="ms-auto"><?= $memButton ?></div><?php endif; ?>
</div>
<?= $memBox ?>
</form>
<script>
(function () {
  const form = document.getElementById('adjBatchForm');
  const mode = form.dataset.mode;
  const { num, fmt } = AppFmt;
  const isBlank = (i) => !i || i.value.trim() === '';
  function row(tr) {
    const cur = num(tr.dataset.cur), total = num(tr.dataset.total), avg = num(tr.dataset.avg);
    const q = tr.querySelector('.adj-new'), v = tr.querySelector('.adj-val'), diffCell = tr.querySelector('.adj-diff'), netCell = tr.querySelector('.adj-net');
    const d = q && !isBlank(q) ? num(q.value) - cur : 0;
    if (diffCell) { diffCell.textContent = q && !isBlank(q) ? (Math.round(d * 10000) / 10000).toString() : ''; diffCell.classList.toggle('neg', d < 0); }
    // Quantity and total value: the new quantity gives a new value at the average cost, until a value is typed
    if (v && mode === 'both' && (isBlank(v) || v.dataset.auto === '1')) {
      if (q && !isBlank(q) && Math.abs(d) > 1e-9) { v.value = ((total + d) * avg).toFixed(2); v.dataset.auto = '1'; }
      else if (v.dataset.auto === '1') { v.value = ''; delete v.dataset.auto; }
    }
    if (v) v.classList.toggle('is-auto', v.dataset.auto === '1');
    const typedValue = v && !isBlank(v) && v.dataset.auto !== '1' ? num(v.value) : null;
    const changed = Math.abs(d) > 1e-9 || typedValue !== null;
    const net = changed ? d * avg + (typedValue === null ? 0 : typedValue - (total + d) * avg) : null;
    if (netCell) { netCell.textContent = net === null ? '' : fmt(net); netCell.classList.toggle('neg', net !== null && net < -0.004); }
    tr.classList.toggle('is-changed', changed);
    return net;
  }
  function totals() {
    let n = 0, val = 0;
    form.querySelectorAll('#adjBatch tbody tr[data-cur]').forEach((tr) => { const net = row(tr); if (net !== null) { n++; val += net; } });
    document.getElementById('adjBatchCount').textContent = n;
    const tv = document.getElementById('adjBatchValue');
    if (tv) { tv.textContent = fmt(val); tv.classList.toggle('neg', val < -0.004); }
  }
  form.addEventListener('input', (e) => {
    if (e.target.classList.contains('adj-val')) delete e.target.dataset.auto;
    if (e.target.closest('#adjBatch')) totals();
  });
  // a value worked out from the quantity is only shown: the quantity alone is sent, which gives that value
  form.addEventListener('submit', () => {
    const auto = Array.prototype.filter.call(form.querySelectorAll('.adj-val'), (i) => i.dataset.auto === '1');
    auto.forEach((i) => { i.disabled = true; });
    setTimeout(() => auto.forEach((i) => { i.disabled = false; }), 0);
  });
  totals();
})();
</script>
<?php endif; ?>
<?= $loadList ?>
<?php if ($canEditReasons): ?>
<?= adjust_reasons_modal($reasons, $pageKey) ?>
<script>
(function () {
  // Edit reasons: the list is saved without leaving the page, and the Reason box is given the new list at once
  const form = document.getElementById('adjReasonsForm');
  const list = document.getElementById('adjReasonList');
  const err = document.getElementById('adjReasonErr');
  document.getElementById('adjReasonAdd').addEventListener('click', () => {
    list.insertAdjacentHTML('beforeend', document.getElementById('adjReasonTpl').innerHTML);
    list.lastElementChild.querySelector('input').focus();
  });
  list.addEventListener('click', (e) => {
    const row = e.target.closest('.reason-row'); if (!row) return;
    const mv = e.target.closest('[data-move]');
    if (mv) {
      const sib = mv.dataset.move === '-1' ? row.previousElementSibling : row.nextElementSibling;
      if (sib) { mv.dataset.move === '-1' ? list.insertBefore(row, sib) : list.insertBefore(sib, row); }
      mv.focus();
    } else if (e.target.closest('[data-remove]')) { row.remove(); }
  });
  form.addEventListener('submit', (e) => {
    if (!window.fetch) return;
    e.preventDefault();
    err.hidden = true;
    const fd = new FormData(form); fd.append('ajax', '1');
    fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin' }).then((r) => r.json()).then((res) => {
      if (!res.ok) { err.textContent = res.error || 'The reasons could not be saved.'; err.hidden = false; return; }
      document.querySelectorAll('select[name="reason"]').forEach((sel) => {
        const keep = sel.value;
        sel.innerHTML = '<option value="">— choose —</option>' + res.reasons.map((r) => '<option></option>').join('');
        res.reasons.forEach((r, i) => { sel.options[i + 1].value = r; sel.options[i + 1].textContent = r; });
        sel.value = res.reasons.indexOf(keep) >= 0 ? keep : '';
        sel.dispatchEvent(new Event('change', { bubbles: true }));
        if (sel.comboSync) sel.comboSync();
      });
      // the box keeps the list as saved (spaces tidied, "Other" and blank rows left out)
      const tpl = document.getElementById('adjReasonTpl').innerHTML;
      list.innerHTML = '';
      res.reasons.slice(0, -1).forEach((r) => { list.insertAdjacentHTML('beforeend', tpl); const i = list.lastElementChild.querySelector('input'); i.value = r; i.setAttribute('value', r); });
      bootstrap.Modal.getOrCreateInstance(document.getElementById('adjReasons')).hide();
    }).catch(() => { err.textContent = 'The reasons could not be saved. Check the connection and try again.'; err.hidden = false; });
  });
})();
</script>
<?php endif; ?>
<?php
layout_end();
