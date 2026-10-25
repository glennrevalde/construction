<?php
declare(strict_types=1);

require_once APP_ROOT . '/modules/txn/_common.php';

$pageKey = 'pos.shifts';
$a = g('a');
$terminal = Pos::terminal();
$shift = $terminal ? Pos::openShift((int)$terminal['id']) : null;

if (txn_common_actions($pageKey, ['pos_over_short'])) {
    return;
}

// ------------------------------------------------------------------ print a reading
if ($a === 'reading') {
    $r = DB::row('SELECT r.*, t.name AS terminal, u.full_name, s.opening_float, s.corrected_at, cu.full_name AS corrected_name
                    FROM pos_readings r JOIN pos_terminals t ON t.id = r.terminal_id JOIN users u ON u.id = r.created_by
                    JOIN pos_shifts s ON s.id = r.shift_id LEFT JOIN users cu ON cu.id = s.corrected_by
                   WHERE r.id = :id', ['id' => (int)g('id')]);
    if (!$r) {
        redirect(url($pageKey));
    }
    $s = Settings::all();
    layout_start($r['reading_type'] . '-reading', ['bare' => true, 'no_title' => true]);
    ?>
    <div class="no-print text-center mb-2"><button class="btn btn-primary btn-sm" onclick="window.print()">Print</button> <a class="btn btn-outline-secondary btn-sm" href="<?= h(url($pageKey)) ?>">Back</a></div>
    <div class="receipt">
      <div class="text-center"><strong><?= h($s['company_name']) ?></strong><br>TIN <?= h($s['company_tin']) ?><br><?= h($r['terminal']) ?></div>
      <hr class="my-1"><div class="text-center fw-bold"><?= h($r['reading_type']) ?>-READING <?= $r['z_no'] ? '#' . (int)$r['z_no'] : '' ?></div>
      <div>Business date: <?= h(fdate($r['business_date'])) ?><br>Printed: <?= h(fdatetime($r['created_at'])) ?><br>By: <?= h($r['full_name']) ?></div>
      <hr class="my-1">
      <table class="w-100">
        <tr><td>First receipt</td><td class="text-end"><?= h($r['first_receipt']) ?></td></tr>
        <tr><td>Last receipt</td><td class="text-end"><?= h($r['last_receipt']) ?></td></tr>
        <tr><td>Sales count</td><td class="text-end"><?= (int)$r['sales_count'] ?></td></tr>
        <tr><td>Gross sales</td><td class="text-end"><?= money($r['gross_sales']) ?></td></tr>
        <tr><td>Discounts</td><td class="text-end"><?= money($r['discounts']) ?></td></tr>
        <tr><td>Returns</td><td class="text-end"><?= money($r['returns']) ?></td></tr>
        <tr><td>Voids</td><td class="text-end"><?= money($r['voids']) ?></td></tr>
        <tr><td>Net sales (net of VAT)</td><td class="text-end"><?= money($r['net_sales']) ?></td></tr>
        <tr><td>VAT</td><td class="text-end"><?= money($r['tax']) ?></td></tr>
      </table><hr class="my-1">
      <table class="w-100">
        <tr><td>Opening cash float</td><td class="text-end"><?= money($r['opening_float']) ?></td></tr>
        <?php foreach (json_decode((string)$r['tenders_json'], true) ?: [] as $k => $v): ?><tr><td><?= h($k) ?></td><td class="text-end"><?= money($v) ?></td></tr><?php endforeach; ?>
        <tr><td>Cash drops</td><td class="text-end"><?= money($r['cash_drops']) ?></td></tr>
        <tr><td>Expected cash in drawer</td><td class="text-end"><?= money($r['expected_cash']) ?></td></tr>
        <?php if ($r['counted_cash'] !== null): ?>
        <tr><td>Counted cash</td><td class="text-end"><?= money($r['counted_cash']) ?></td></tr>
        <tr class="fw-bold"><td>Over / (short)</td><td class="text-end"><?= money($r['over_short']) ?></td></tr>
        <?php endif; ?>
      </table>
      <?php if ($r['reading_type'] === 'Z' && $r['corrected_at']): ?><hr class="my-1"><div class="text-center small">Closing entries corrected <?= h(fdatetime($r['corrected_at'])) ?> by <?= h($r['corrected_name']) ?></div><?php endif; ?>
      <hr class="my-1"><div class="text-center"><?= $r['reading_type'] === 'Z' ? 'End of day - counters reset' : 'Mid-shift reading - register not closed' ?></div>
    </div>
    <?php
    layout_end();
    return;
}

if (is_post()) {
    try {
        $do = p('do');
        if ($do === 'open') {
            Access::require($pageKey, 'create');
            $tid = pint('terminal_id');
            $terms = Pos::terminals();
            if (!$tid || !isset($terms[$tid])) {
                fail('Choose a terminal assigned to you.');
            }
            if (Pos::openShift($tid)) {
                fail('A shift is already open on this terminal.');
            }
            $float = r2(pnum('opening_float'));
            $sid = DB::insert('pos_shifts', ['terminal_id' => $tid, 'cashier_id' => Auth::id(), 'business_date' => pdate('business_date', today()), 'opening_float' => $float]);
            $_SESSION['pos_terminal_id'] = $tid;
            Audit::log('shift_open', 'pos', 'shift', $sid, $terms[$tid]['name'], ['amount' => $float, 'terminal_id' => $tid, 'site_id' => $terms[$tid]['site_id'], 'reason' => 'Opening float ' . money($float)]);
            flash('success', 'Shift opened. You can start selling.');
            redirect(url('pos.register'));
        }
        if ($do === 'correct') {
            // correcting what the cashier entered when the register was closed (any terminal's closed shift)
            Access::require($pageKey, 'edit');
            $sid = pint('shift_id');
            $raw = fn(string $k) => trim((string)p($k)) === '' ? null : to_num((string)p($k));
            $drops = [];
            foreach ((array)($_POST['drop'] ?? []) as $did => $v) {
                $drops[(int)$did] = trim((string)$v) === '' ? 0.0 : to_num((string)$v);
            }
            $approver = Approval::check($pageKey, true, 'correcting the closing entries of a shift');
            $res = Pos::correctShift($sid, [
                'opening_float' => $raw('opening_float'), 'counted_cash' => $raw('counted_cash'), 'drops' => $drops,
                'new_drop' => [$raw('new_drop_amount') ?? 0, (string)p('new_drop_reason')],
            ], trim((string)p('reason')), $approver);
            $txnNote = $res['txn_id'] ? ' The Cash Over/Short entry ' . ($res['old_txn_id'] && (int)$res['old_txn_id'] === (int)$res['txn_id'] ? 'was changed' : 'was made') . ' to follow it.'
                : ($res['old_txn_id'] ? ' The Cash Over/Short entry was voided: the drawer now agrees.' : '');
            $depNote = !empty($res['deposit']['changed'])
                ? ' Deposit ' . $res['deposit']['txn_no'] . ' was changed to follow it: ' . money($res['deposit']['old']) . ' to ' . money($res['deposit']['new']) . '.'
                : (!empty($res['deposit']) ? '' : ' Make Deposits now takes ' . money($res['to_deposit']) . ' from this shift (cash counted less the float plus the drops).');
            flash('success', 'Closing entries corrected. Expected cash ' . money($res['expected']) . ', over/(short) ' . money($res['over_short']) . '.' . $txnNote . $depNote);
            redirect(url($pageKey, ['a' => 'shift', 'id' => $sid]));
        }
        if (!$shift) {
            fail('There is no open shift on this terminal.');
        }
        if ($do === 'drop') {
            Access::require($pageKey, 'create');
            $amt = r2(pnum('drop_amount'));
            if ($amt <= 0) {
                fail('Enter the amount removed from the drawer.');
            }
            $id = DB::insert('pos_cash_drops', ['shift_id' => $shift['id'], 'amount' => $amt, 'reason' => p('drop_reason') ?: 'Cash drop', 'created_by' => Auth::id()]);
            Audit::log('cash_drop', 'pos', 'shift', (int)$shift['id'], 'Cash drop #' . $id, ['amount' => $amt, 'terminal_id' => $shift['terminal_id'], 'reason' => p('drop_reason') ?: null]);
            flash('success', 'Cash drop of ' . money($amt) . ' recorded.');
            redirect(url($pageKey));
        }
        if ($do === 'x') {
            $approver = Approval::check($pageKey, true, 'X-reading');
            $rid = Pos::saveReading('X', (int)$shift['id']);
            Audit::log('x_reading', 'pos', 'shift', (int)$shift['id'], 'X-reading', ['approved_by' => $approver['username'] ?? null, 'terminal_id' => $shift['terminal_id']]);
            redirect(url($pageKey, ['a' => 'reading', 'id' => $rid]));
        }
        if ($do === 'close') {
            Access::require($pageKey, 'create');
            $counted = r2(pnum('counted_cash'));
            if (p('counted_cash') === '') {
                fail('Count the drawer and enter the cash counted.');
            }
            $sum = Pos::summary((int)$shift['id']);
            $diff = r2($counted - $sum['expected_cash']);
            $approver = Approval::check($pageKey, true, 'closing the register (Z-reading)');
            $zid = DB::tx(function () use ($shift, $counted, $sum, $diff, $approver, $terminal) {
                $osTxn = null;
                if (abs($diff) >= 0.01) {
                    $cashAcct = (int)DB::value('SELECT cash_account_id FROM pos_terminals WHERE id = :id', ['id' => $shift['terminal_id']]);
                    $osTxn = Txn::save('pos_over_short', [
                        'txn_date' => $shift['business_date'], 'terminal_id' => $shift['terminal_id'], 'pos_shift_id' => $shift['id'],
                        'site_id' => $terminal['site_id'], 'memo' => ($diff > 0 ? 'Cash over' : 'Cash short') . ' - ' . $terminal['name'],
                        'class_id' => $terminal['class_id'] ?? null,
                    ], [
                        ['line_kind' => 'journal', 'account_id' => $cashAcct, 'amount' => $diff, 'description' => 'Drawer count difference'],
                        ['line_kind' => 'journal', 'account_id' => Accts::get('cash_over_short'), 'amount' => -$diff, 'description' => 'Cash over and short'],
                    ]);
                }
                $zid = Pos::saveReading('Z', (int)$shift['id'], $counted);
                DB::update('pos_shifts', [
                    'status' => 'closed', 'closed_at' => date('Y-m-d H:i:s'), 'closed_by' => Auth::id(),
                    'expected_cash' => $sum['expected_cash'], 'counted_cash' => $counted, 'over_short' => $diff, 'over_short_txn_id' => $osTxn,
                ], 'id = :id', ['id' => $shift['id']]);
                Audit::log('shift_close', 'pos', 'shift', (int)$shift['id'], 'Z-reading', [
                    'amount' => $counted, 'terminal_id' => $shift['terminal_id'], 'approved_by' => $approver['username'] ?? null,
                    'reason' => 'Expected ' . money($sum['expected_cash']) . ', counted ' . money($counted) . ', over/short ' . money($diff),
                ]);
                return $zid;
            });
            flash($diff == 0.0 ? 'success' : 'warning', 'Register closed. Over/(short): ' . money($diff));
            redirect(url($pageKey, ['a' => 'reading', 'id' => $zid]));
        }
    } catch (UserError $e) {
        if (DB::inTransaction()) {
            DB::rollBack();
        }
        Approval::release();
        $GLOBALS['FORM_ERROR'] = $e->getMessage();
    }
}

/** The figures of a shift: sales, how the money came in, the float, the drops and the cash expected (and counted) */
function shift_summary_table(array $summary, ?array $closed = null): string
{
    $h = '<table class="table table-sm shift-sum mb-0">'
        . '<tr><td>Sales</td><td class="num">' . (int)$summary['sales_count'] . ($summary['first_receipt'] ? ' (' . h($summary['first_receipt']) . ' – ' . h($summary['last_receipt']) . ')' : '') . '</td></tr>'
        . '<tr><td>Gross sales</td><td class="num">' . money($summary['gross_sales']) . '</td></tr>'
        . '<tr><td>Discounts</td><td class="num">' . money($summary['discounts']) . '</td></tr>'
        . '<tr><td>Returns</td><td class="num">' . money($summary['returns']) . '</td></tr>'
        . '<tr><td>Voids (' . (int)$summary['void_count'] . ')</td><td class="num">' . money($summary['voids']) . '</td></tr>'
        . '<tr><td>Net sales + VAT</td><td class="num">' . money($summary['total']) . '</td></tr>'
        . '<tr class="shift-float"><td>Opening cash float</td><td class="num">' . money($summary['shift']['opening_float']) . '</td></tr>';
    foreach ($summary['tenders'] as $k => $v) {
        $h .= '<tr><td class="ps-3 small">' . h($k) . '</td><td class="num small">' . money($v) . '</td></tr>';
    }
    $h .= '<tr><td>Cash drops</td><td class="num">' . money($summary['cash_drops']) . '</td></tr>'
        . '<tr class="fw-bold"><td>Expected cash in drawer</td><td class="num">' . money($summary['expected_cash']) . '</td></tr>';
    if ($closed) {
        $h .= '<tr><td>Cash counted</td><td class="num">' . money($closed['counted_cash']) . '</td></tr>'
            . '<tr class="fw-bold"><td>Over / (short)</td><td class="num ' . ((float)$closed['over_short'] < 0 ? 'neg' : '') . '">' . money($closed['over_short']) . '</td></tr>';
    }
    return $h . '</table>';
}

// ------------------------------------------------------------------ one shift: its figures, and correcting its closing entries
if ($a === 'shift') {
    $sh = DB::row('SELECT s.*, t.name AS terminal, t.code AS terminal_code, u.full_name, cb.full_name AS closed_name, cr.full_name AS corrected_name
                     FROM pos_shifts s JOIN pos_terminals t ON t.id = s.terminal_id JOIN users u ON u.id = s.cashier_id
                     LEFT JOIN users cb ON cb.id = s.closed_by LEFT JOIN users cr ON cr.id = s.corrected_by WHERE s.id = :id', ['id' => gint('id')]);
    if (!$sh) {
        redirect(url($pageKey));
    }
    $sum = Pos::summary((int)$sh['id']);
    $zId = DB::value("SELECT id FROM pos_readings WHERE shift_id = :s AND reading_type = 'Z' ORDER BY id DESC LIMIT 1", ['s' => $sh['id']]);
    $shDrops = DB::all('SELECT d.*, u.username FROM pos_cash_drops d JOIN users u ON u.id = d.created_by WHERE d.shift_id = :s ORDER BY d.id', ['s' => $sh['id']]);
    $os = $sh['over_short_txn_id'] ? DB::row('SELECT id, txn_type, txn_no, is_voided FROM transactions WHERE id = :id', ['id' => $sh['over_short_txn_id']]) : null;
    $dep = $sh['deposit_txn_id'] ? DB::row('SELECT id, txn_type, txn_no, is_voided FROM transactions WHERE id = :id', ['id' => $sh['deposit_txn_id']]) : null;
    $deposited = $dep && (int)$dep['is_voided'] === 0;
    $canCorrect = $sh['status'] === 'closed' && Access::can($pageKey, 'edit');
    $history = DB::all("SELECT created_at, username, reason, changes, approved_by FROM audit_trail WHERE record_type = 'shift' AND record_id = :id AND action = 'shift_correct' ORDER BY id DESC", ['id' => $sh['id']]);
    // what came into the drawer apart from the float and the drops (cash sales, cash account payments)
    $cashIn = r2($sum['expected_cash'] - (float)$sh['opening_float'] + $sum['cash_drops']);
    layout_start('Shift: ' . $sh['terminal'] . ', ' . fdate($sh['business_date']), ['actions' => btn_link(url($pageKey), 'All shifts', 'btn-outline-secondary', 'arrow-left')
        . ($zId ? ' ' . btn_link(url($pageKey, ['a' => 'reading', 'id' => $zId]), 'Z-reading', 'btn-outline-secondary', 'printer') : '')
        . (Access::page('rpt.pos_ending_inventory') ? ' ' . btn_link(url('rpt.pos_ending_inventory', ['shift_id' => $sh['id']]), 'Ending inventory', 'btn-outline-secondary', 'box-seam') : '')]);
    ?>
    <div class="row g-3">
      <div class="col-lg-7">
        <div class="card mb-3"><div class="card-body">
          <div class="shift-facts">
            <div><span>Terminal</span><strong><?= h($sh['terminal_code'] . ' - ' . $sh['terminal']) ?></strong></div>
            <div><span>Cashier</span><strong><?= h($sh['full_name']) ?></strong></div>
            <div><span>Business date</span><strong><?= h(fdate($sh['business_date'])) ?></strong></div>
            <div><span>Opening cash float</span><strong><?= money($sh['opening_float']) ?></strong></div>
            <div><span>Opened</span><strong><?= h(fdatetime($sh['opened_at'])) ?></strong></div>
            <div><span><?= $sh['status'] === 'closed' ? 'Closed' : 'Status' ?></span><strong><?= $sh['status'] === 'closed' ? h(fdatetime($sh['closed_at'])) . ($sh['closed_name'] ? ', ' . h($sh['closed_name']) : '') : 'Open' ?></strong></div>
            <?php if ($sh['corrected_at']): ?><div><span>Last corrected</span><strong><?= h(fdatetime($sh['corrected_at'])) ?>, <?= h($sh['corrected_name']) ?></strong></div><?php endif; ?>
            <?php if ($os): ?><div><span>Cash over/short entry</span><strong><?= Txn::link(['id' => $os['id'], 'txn_type' => $os['txn_type'], 'txn_no' => $os['txn_no']], $os['txn_no'] . ((int)$os['is_voided'] ? ' (void)' : '')) ?></strong></div><?php endif; ?>
            <?php if ($sh['status'] === 'closed'): ?><div><span>Cash to deposit</span><strong><?= money(Pos::toDeposit($sh)) ?></strong></div><?php endif; ?>
            <?php if ($dep): ?><div><span>Deposited</span><strong><?= Txn::link(['id' => $dep['id'], 'txn_type' => $dep['txn_type'], 'txn_no' => $dep['txn_no']], $dep['txn_no'] . ((int)$dep['is_voided'] ? ' (void)' : '')) ?></strong></div><?php endif; ?>
          </div>
        </div></div>
        <div class="card"><div class="card-header fw-semibold">The figures of the shift</div><div class="card-body">
          <?= shift_summary_table($sum, $sh['status'] === 'closed' ? $sh : null) ?>
          <?php if ($shDrops): ?>
            <div class="rd-sub mt-3">Cash drops</div>
            <?php foreach ($shDrops as $d): ?><div class="small"><?= h(fdatetime($d['created_at'])) ?>, <?= money($d['amount']) ?>, <?= h($d['reason']) ?> (<?= h($d['username']) ?>)</div><?php endforeach; ?>
          <?php endif; ?>
        </div></div>
      </div>
      <div class="col-lg-5">
        <?php if ($canCorrect): ?>
        <form method="post" class="card shift-correct" data-cash-in="<?= h((string)$cashIn) ?>"><?= csrf_field() ?>
          <input type="hidden" name="do" value="correct"><input type="hidden" name="shift_id" value="<?= (int)$sh['id'] ?>">
          <div class="card-header fw-semibold"><i class="bi bi-pencil-square"></i> Correct the closing entries</div>
          <div class="card-body">
            <p class="small text-muted">For a wrong figure entered by the cashier: the float typed when the shift was opened, a cash drop, or the cash counted at closing. The expected cash and the over/short are worked out again, and the Cash Over/Short journal entry is changed to match (made, changed or voided).</p>
            <?php if ($deposited): ?><div class="alert alert-info py-2 small">The cash of this shift is on deposit <?= Txn::link(['id' => $dep['id'], 'txn_type' => $dep['txn_type'], 'txn_no' => $dep['txn_no']], $dep['txn_no']) ?>. The deposit is changed to follow the correction: its line for this shift becomes the new cash to deposit.</div><?php endif; ?>
            <div class="row g-2">
              <div class="col-6"><label class="form-label">Opening cash float</label><input class="form-control form-control-sm text-end" name="opening_float" inputmode="decimal" value="<?= h(fv('opening_float', null, number_format((float)$sh['opening_float'], 2, '.', ''))) ?>" data-sc="float"></div>
              <div class="col-6"><label class="form-label">Cash counted</label><input class="form-control form-control-sm text-end" name="counted_cash" inputmode="decimal" value="<?= h(fv('counted_cash', null, number_format((float)$sh['counted_cash'], 2, '.', ''))) ?>" data-sc="counted"></div>
              <?php foreach ($shDrops as $d): ?>
                <div class="col-6"><label class="form-label">Cash drop #<?= (int)$d['id'] ?> <span class="text-muted fw-normal">(<?= h(mb_strimwidth((string)$d['reason'], 0, 24, '...')) ?>)</span></label>
                  <input class="form-control form-control-sm text-end" name="drop[<?= (int)$d['id'] ?>]" inputmode="decimal" value="<?= h($_POST['drop'][$d['id']] ?? number_format((float)$d['amount'], 2, '.', '')) ?>" data-sc="drop"></div>
              <?php endforeach; ?>
              <div class="col-6"><label class="form-label">A cash drop not recorded</label><input class="form-control form-control-sm text-end" name="new_drop_amount" inputmode="decimal" placeholder="0.00" value="<?= h(fv('new_drop_amount')) ?>" data-sc="drop"></div>
              <div class="col-6"><label class="form-label">Its reason</label><input class="form-control form-control-sm" name="new_drop_reason" maxlength="120" placeholder="e.g. cash to safe at 3 PM" value="<?= h(fv('new_drop_reason')) ?>"></div>
              <div class="col-12"><label class="form-label">Why the entries are corrected (required)</label><input class="form-control form-control-sm" name="reason" required maxlength="200" placeholder="e.g. cashier typed 5,717 instead of 5,771" value="<?= h(fv('reason')) ?>"></div>
            </div>
            <div class="shift-preview mt-3">
              <div><span>Expected cash</span><strong data-sc-out="expected"><?= money($sum['expected_cash']) ?></strong></div>
              <div><span>Over / (short)</span><strong data-sc-out="diff"><?= money($sh['over_short']) ?></strong></div>
              <div><span>Cash to deposit</span><strong data-sc-out="deposit"><?= money(Pos::toDeposit($sh)) ?></strong></div>
            </div>
            <?= Access::can($pageKey, 'approve') ? '' : Approval::fields(true) ?>
            <button class="btn btn-primary btn-sm mt-3" data-confirm="Save the corrected closing entries? The Cash Over/Short entry will follow them."><i class="bi bi-check-lg"></i> Save the correction</button>
          </div>
        </form>
        <?php elseif ($sh['status'] === 'closed'): ?>
          <div class="card"><div class="card-body small text-muted">Correcting the closing entries of a shift needs the Edit right on this page (administrators and POS supervisors have it).</div></div>
        <?php endif; ?>
        <?php if ($history): ?>
        <div class="card mt-3"><div class="card-header fw-semibold">Corrections</div><div class="list-group list-group-flush">
          <?php foreach ($history as $hx): ?>
            <div class="list-group-item small">
              <div class="d-flex justify-content-between gap-2"><strong><?= h(fdatetime($hx['created_at'])) ?></strong><span class="text-muted"><?= h($hx['username']) ?><?= $hx['approved_by'] && $hx['approved_by'] !== $hx['username'] ? ', approved by ' . h($hx['approved_by']) : '' ?></span></div>
              <div class="text-muted"><?= h($hx['reason']) ?></div>
              <?php foreach (json_decode((string)$hx['changes'], true) ?: [] as $ch): ?><div><?= h($ch['field']) ?>: <?= h((string)$ch['old']) ?> &rarr; <?= h((string)$ch['new']) ?></div><?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div></div>
        <?php endif; ?>
      </div>
    </div>
    <script>
    (function () {
      var f = document.querySelector('.shift-correct');
      if (!f) { return; }
      var cashIn = parseFloat(f.dataset.cashIn) || 0;
      var num = function (el) { var v = parseFloat(String(el.value).replace(/,/g, '')); return isNaN(v) ? 0 : v; };
      var fmt = function (v) { var s = Math.abs(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); return v < 0 ? '(' + s + ')' : s; };
      var run = function () {
        var drops = 0;
        f.querySelectorAll('[data-sc="drop"]').forEach(function (el) { drops += num(el); });
        var flt = num(f.querySelector('[data-sc="float"]'));
        var counted = num(f.querySelector('[data-sc="counted"]'));
        var expected = flt + cashIn - drops;
        var diff = counted - expected;
        var dep = f.querySelector('[data-sc-out="deposit"]');
        if (dep) { dep.textContent = fmt(counted - flt + drops); }
        f.querySelector('[data-sc-out="expected"]').textContent = fmt(expected);
        var d = f.querySelector('[data-sc-out="diff"]');
        d.textContent = fmt(diff);
        d.classList.toggle('neg', diff < -0.004);
      };
      f.addEventListener('input', run);
      run();
    })();
    </script>
    <?php
    layout_end();
    return;
}

$terminals = Pos::terminals();
$summary = $shift ? Pos::summary((int)$shift['id']) : null;
// ---- the list of shifts: the latest ones, or every shift of the business dates chosen
$hf = [
    'from' => gdate('from', ''), 'to' => gdate('to', ''), 'terminal' => gint('terminal_id'), 'cashier' => gint('cashier_id'),
    'status' => in_array(g('status'), ['open', 'closed', 'to_deposit', 'deposited', 'corrected'], true) ? g('status') : '',
];
if ($hf['from'] !== '' && $hf['to'] !== '' && $hf['from'] > $hf['to']) {
    [$hf['from'], $hf['to']] = [$hf['to'], $hf['from']];
}
$hFiltered = $hf['from'] !== '' || $hf['to'] !== '' || $hf['terminal'] || $hf['cashier'] || $hf['status'] !== '';
$hWhere = ['1 = 1'];
$hp = [];
if ($hf['from'] !== '') {
    $hWhere[] = 's.business_date >= :hfrom';
    $hp['hfrom'] = $hf['from'];
}
if ($hf['to'] !== '') {
    $hWhere[] = 's.business_date <= :hto';
    $hp['hto'] = $hf['to'];
}
if ($hf['terminal']) {
    $hWhere[] = 's.terminal_id = :hterm';
    $hp['hterm'] = $hf['terminal'];
}
if ($hf['cashier']) {
    $hWhere[] = 's.cashier_id = :hcash';
    $hp['hcash'] = $hf['cashier'];
}
$hWhere[] = ['open' => "s.status = 'open'", 'closed' => "s.status = 'closed'", 'corrected' => 's.corrected_at IS NOT NULL',
    'to_deposit' => "s.status = 'closed' AND (s.deposit_txn_id IS NULL OR EXISTS (SELECT 1 FROM transactions dv WHERE dv.id = s.deposit_txn_id AND dv.is_voided = 1))",
    'deposited' => 'EXISTS (SELECT 1 FROM transactions dv WHERE dv.id = s.deposit_txn_id AND dv.is_voided = 0)'][$hf['status']] ?? '1 = 1';
$hSql = ' FROM pos_shifts s JOIN pos_terminals t ON t.id = s.terminal_id JOIN users u ON u.id = s.cashier_id WHERE ' . implode(' AND ', $hWhere);
$hCount = (int)DB::value('SELECT COUNT(*)' . $hSql, $hp);
$hPer = $hFiltered ? 100 : 30;
$hPage = max(1, (int)(gint('pg') ?? 1));
$hPages = max(1, (int)ceil($hCount / $hPer));
$hPage = min($hPage, $hPages);
$history = DB::all(
    'SELECT s.*, t.name AS terminal, u.full_name, (SELECT id FROM pos_readings r WHERE r.shift_id = s.id AND r.reading_type = \'Z\' LIMIT 1) AS z_id,
            (SELECT is_voided FROM transactions dv WHERE dv.id = s.deposit_txn_id) AS dep_void' . $hSql
    . ' ORDER BY s.business_date DESC, s.id DESC LIMIT ' . (int)$hPer . ' OFFSET ' . (int)(($hPage - 1) * $hPer),
    $hp
);
$hTot = $hFiltered ? DB::row('SELECT COUNT(*) AS n, SUM(s.opening_float) AS flt, SUM(s.expected_cash) AS exp, SUM(s.counted_cash) AS cnt, SUM(s.over_short) AS os' . $hSql, $hp) : null;
$hTerminals = DB::pairs("SELECT id, CONCAT(code, ' - ', name) FROM pos_terminals ORDER BY code");
$hCashiers = DB::pairs('SELECT DISTINCT u.id, COALESCE(NULLIF(u.full_name, \'\'), u.username) FROM pos_shifts s JOIN users u ON u.id = s.cashier_id ORDER BY 2');
$hDates = ['' => 'Latest shifts', 'today' => 'Today', 'yesterday' => 'Yesterday', 'this_week' => 'This week', 'last_week' => 'Last week',
    'this_month' => 'This month', 'last_month' => 'Last month', 'custom' => 'Custom dates'];
$hPreset = array_key_exists(g('dates'), $hDates) ? g('dates') : ($hf['from'] !== '' || $hf['to'] !== '' ? 'custom' : '');
$drops = $shift ? DB::all('SELECT d.*, u.username FROM pos_cash_drops d JOIN users u ON u.id = d.created_by WHERE d.shift_id = :s ORDER BY d.id', ['s' => $shift['id']]) : [];
$xReadings = $shift ? DB::all("SELECT * FROM pos_readings WHERE shift_id = :s AND reading_type = 'X' ORDER BY id", ['s' => $shift['id']]) : [];

layout_start('Shifts, Cash Drops & Readings', ['actions' => page_link('pos.register', [], 'Go to register', 'btn-success', 'cart')]);
?>
<div class="row g-3">
  <div class="col-xl-5">
  <?php if (!$shift): ?>
    <form method="post" class="card"><div class="card-header fw-semibold">Open a shift</div><div class="card-body row g-2">
      <?= csrf_field() ?><input type="hidden" name="do" value="open">
      <?= select_field('terminal_id', 'Terminal', array_map(fn($t) => $t['code'] . ' - ' . $t['name'], $terminals), $terminal['id'] ?? '', ['class' => 'col-md-6']) ?>
      <?= input_field('business_date', 'Business date', today(), ['type' => 'date', 'class' => 'col-md-3']) ?>
      <?= input_field('opening_float', 'Opening cash float', '2000.00', ['class' => 'col-md-3']) ?>
      <div class="col-12"><button class="btn btn-primary" <?= $terminals ? '' : 'disabled' ?>>Open shift</button></div>
      <?php if (!$terminals): ?><div class="col-12 small text-danger">No terminal is assigned to you.</div><?php endif; ?>
    </div></form>
  <?php else: ?>
    <div class="card mb-3"><div class="card-header fw-semibold">Current shift: <?= h($terminal['name']) ?></div>
      <div class="card-body">
        <div class="small text-muted mb-2">Opened <?= h(fdatetime($shift['opened_at'])) ?> by <?= h($shift['full_name']) ?>, opening cash float <strong><?= money($shift['opening_float']) ?></strong>
          <?php if (Access::page('rpt.pos_ending_inventory')): ?> <a class="ms-1" href="<?= h(url('rpt.pos_ending_inventory', ['shift_id' => $shift['id']])) ?>"><i class="bi bi-box-seam"></i> Ending inventory so far</a><?php endif; ?></div>
        <?= shift_summary_table($summary) ?>
      </div>
    </div>
    <div class="row g-3">
      <div class="col-md-6 col-xl-12"><form method="post" class="card h-100"><div class="card-header">Cash drop</div><div class="card-body">
        <?= csrf_field() ?><input type="hidden" name="do" value="drop">
        <input class="form-control form-control-sm mb-2 text-end" name="drop_amount" placeholder="Amount removed">
        <input class="form-control form-control-sm mb-2" name="drop_reason" placeholder="Reason (e.g. excess cash to safe)">
        <button class="btn btn-sm btn-outline-primary">Record drop</button>
        <?php foreach ($drops as $d): ?><div class="small mt-1"><?= h(fdatetime($d['created_at'])) ?>, <?= money($d['amount']) ?>, <?= h($d['username']) ?></div><?php endforeach; ?>
      </div></form></div>
      <div class="col-md-6 col-xl-12"><form method="post" class="card h-100"><div class="card-header">X-reading (mid-shift)</div><div class="card-body">
        <?= csrf_field() ?><input type="hidden" name="do" value="x">
        <p class="small text-muted">Prints a sales summary without closing the register. Requires a supervisor.</p>
        <?= Access::can($pageKey, 'approve') ? '' : Approval::fields(true) ?>
        <button class="btn btn-sm btn-outline-primary mt-2">Run X-reading</button>
        <?php foreach ($xReadings as $x): ?><div class="small mt-1"><a href="<?= h(url($pageKey, ['a' => 'reading', 'id' => $x['id']])) ?>">X <?= h(fdatetime($x['created_at'])) ?></a></div><?php endforeach; ?>
      </div></form></div>
    </div>
    <form method="post" class="card mt-3 border-danger"><div class="card-header">Close register (count drawer + Z-reading)</div><div class="card-body row g-2">
      <?= csrf_field() ?><input type="hidden" name="do" value="close">
      <div class="col-md-5"><label class="form-label">Cash counted in drawer</label><input class="form-control text-end" name="counted_cash" required></div>
      <div class="col-md-7 small text-muted d-flex align-items-end">Any difference from the expected cash is posted to Cash Over and Short. The Z-reading closes the day's totals.</div>
      <div class="col-12"><?= Access::can($pageKey, 'approve') ? '' : Approval::fields(true) ?></div>
      <div class="col-12"><button class="btn btn-danger" data-confirm="Close the register now?">Close register and print Z-reading</button></div>
    </div></form>
  <?php endif; ?>
  </div>
  <div class="col-xl-7">
    <div class="card shift-list-card"><div class="card-header shift-list-head">
      <div class="fw-semibold"><?= $hFiltered ? 'Shifts' : 'Recent shifts' ?></div>
      <div class="small text-muted"><?php if ($hFiltered): ?><?= $hCount ?> shift(s)<?= $hf['from'] !== '' || $hf['to'] !== '' ? ', business dates ' . h($hf['from'] !== '' ? fdate($hf['from']) : 'the start') . ' to ' . h($hf['to'] !== '' ? fdate($hf['to']) : 'today') : '' ?><?php else: ?>The latest <?= min($hPer, $hCount) ?> of <?= $hCount ?>. Choose dates to see earlier shifts.<?php endif; ?></div>
    </div>
    <form method="get" class="card-body shift-filter filters" id="shiftFilter"><input type="hidden" name="p" value="<?= h($pageKey) ?>">
      <div class="row g-2 align-items-end">
        <div class="col-6 col-md-4 col-xxl-3"><label class="form-label" for="sfDates">Business dates</label>
          <select class="form-select form-select-sm" name="dates" id="sfDates" data-no-combo><?= options($hDates, $hPreset) ?></select></div>
        <div class="col-6 col-md-4 col-xxl-2"><label class="form-label" for="sfFrom">From</label><input class="form-control form-control-sm" type="date" name="from" id="sfFrom" value="<?= h($hf['from']) ?>"></div>
        <div class="col-6 col-md-4 col-xxl-2"><label class="form-label" for="sfTo">To</label><input class="form-control form-control-sm" type="date" name="to" id="sfTo" value="<?= h($hf['to']) ?>"></div>
        <div class="col-6 col-md-4 col-xxl-3"><label class="form-label" for="sfStatus">Status</label>
          <select class="form-select form-select-sm" name="status" id="sfStatus" data-no-combo><?= options(['' => 'Any status', 'open' => 'Open', 'closed' => 'Closed', 'to_deposit' => 'Closed, not deposited', 'deposited' => 'Deposited', 'corrected' => 'Corrected'], $hf['status']) ?></select></div>
        <div class="col-6 col-md-4 col-xxl-3"><label class="form-label" for="sfTerm">Terminal</label>
          <select class="form-select form-select-sm" name="terminal_id" id="sfTerm"><?= options($hTerminals, $hf['terminal'], 'All terminals') ?></select></div>
        <div class="col-6 col-md-4 col-xxl-3"><label class="form-label" for="sfCash">Cashier</label>
          <select class="form-select form-select-sm" name="cashier_id" id="sfCash"><?= options($hCashiers, $hf['cashier'], 'All cashiers') ?></select></div>
        <div class="col-12 col-md-4 col-xxl-3 d-flex gap-2"><button class="btn btn-sm btn-primary flex-fill"><i class="bi bi-funnel"></i> Show</button>
          <?php if ($hFiltered): ?><a class="btn btn-sm btn-outline-secondary" href="<?= h(url($pageKey)) ?>">Clear</a><?php endif; ?></div>
      </div>
    </form>
    <div class="table-responsive"><table class="table table-sm table-tight mb-0 shift-list">
      <thead><tr><th>Date / terminal</th><th>Cashier</th><th>Status</th><th class="num">Float</th><th class="num">Expected</th><th class="num">Counted</th><th class="num">Over/short</th><th></th></tr></thead>
      <tbody>
      <?php if (!$history): ?><tr><td colspan="8" class="text-muted text-center py-3"><?= $hFiltered ? 'No shift for these choices.' : 'No shift yet.' ?></td></tr><?php endif; ?>
      <?php $canEndInv = Access::page('rpt.pos_ending_inventory'); foreach ($history as $hsh): $hDeposited = $hsh['deposit_txn_id'] && (int)$hsh['dep_void'] === 0; ?>
        <tr><td class="text-nowrap"><?= h(fdate($hsh['business_date'])) ?><div class="small text-muted"><?= h($hsh['terminal']) ?></div></td><td><?= h($hsh['full_name']) ?></td><td><?= status_badge($hsh['status'] === 'open' ? 'open' : 'closed') ?><?= $hsh['corrected_at'] ? '<div class="small text-muted">corrected</div>' : '' ?></td>
          <td class="num"><?= money($hsh['opening_float']) ?></td>
          <td class="num"><?= $hsh['expected_cash'] !== null ? money($hsh['expected_cash']) : '' ?></td><td class="num"><?= $hsh['counted_cash'] !== null ? money($hsh['counted_cash']) : '' ?></td>
          <td class="num <?= (float)$hsh['over_short'] < 0 ? 'neg' : '' ?>"><?= $hsh['over_short'] !== null ? money($hsh['over_short']) : '' ?></td>
          <?php $canFix = $hsh['status'] === 'closed' && Access::can($pageKey, 'edit'); ?>
          <td class="text-nowrap text-end shift-acts"><a href="<?= h(url($pageKey, ['a' => 'shift', 'id' => $hsh['id']])) ?>" title="<?= $canFix ? 'Correct the closing entries' : 'The figures of the shift' ?>" aria-label="<?= $canFix ? 'Correct' : 'Details' ?>"><i class="bi bi-<?= $canFix ? 'pencil-square' : 'eye' ?>"></i></a>
            <?= $hsh['z_id'] ? '<a class="ms-2" href="' . h(url($pageKey, ['a' => 'reading', 'id' => $hsh['z_id']])) . '" title="Print the Z-reading">Z</a>' : '' ?>
            <?= $canEndInv ? '<a class="ms-2" href="' . h(url('rpt.pos_ending_inventory', ['shift_id' => $hsh['id']])) . '" title="Ending inventory of this shift" aria-label="Ending inventory"><i class="bi bi-box-seam"></i></a>' : '' ?> <?= $hDeposited ? '<div class="small">' . Txn::link((int)$hsh['deposit_txn_id'], 'deposited') . '</div>' : '' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($hTot && $hTot['n'] > 0): ?>
      <tfoot><tr class="fw-semibold"><td colspan="3"><?= (int)$hTot['n'] ?> shift(s)</td><td class="num"><?= money($hTot['flt']) ?></td><td class="num"><?= money($hTot['exp']) ?></td>
        <td class="num"><?= money($hTot['cnt']) ?></td><td class="num <?= (float)$hTot['os'] < 0 ? 'neg' : '' ?>"><?= money($hTot['os']) ?></td><td></td></tr></tfoot>
      <?php endif; ?>
    </table></div>
    <?php if ($hPages > 1): $hq = array_filter(['from' => $hf['from'], 'to' => $hf['to'], 'dates' => $hPreset, 'terminal_id' => $hf['terminal'], 'cashier_id' => $hf['cashier'], 'status' => $hf['status']]); ?>
    <div class="card-footer d-flex flex-wrap gap-2 align-items-center justify-content-between shift-pager">
      <span class="small text-muted">Page <?= $hPage ?> of <?= $hPages ?></span>
      <div class="d-flex gap-2">
        <?php if ($hPage > 1): ?><a class="btn btn-sm btn-outline-secondary" href="<?= h(url($pageKey, $hq + ['pg' => $hPage - 1])) ?>"><i class="bi bi-chevron-left"></i> Newer</a><?php endif; ?>
        <?php if ($hPage < $hPages): ?><a class="btn btn-sm btn-outline-secondary" href="<?= h(url($pageKey, $hq + ['pg' => $hPage + 1])) ?>">Earlier <i class="bi bi-chevron-right"></i></a><?php endif; ?>
      </div></div>
    <?php endif; ?>
    </div>
    <script>
    (function () {
      // a choice of dates fills From and To; typing a date makes it "Custom dates"
      var f = document.getElementById('shiftFilter');
      if (!f) { return; }
      var sel = f.querySelector('#sfDates'), from = f.querySelector('#sfFrom'), to = f.querySelector('#sfTo');
      var iso = function (d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
      sel.addEventListener('change', function () {
        var t = new Date(); t.setHours(12); var a = new Date(t), b = new Date(t);
        var dow = (t.getDay() + 6) % 7;
        switch (sel.value) {
          case 'today': break;
          case 'yesterday': a.setDate(t.getDate() - 1); b = new Date(a); break;
          case 'this_week': a.setDate(t.getDate() - dow); break;
          case 'last_week': a.setDate(t.getDate() - dow - 7); b = new Date(a); b.setDate(a.getDate() + 6); break;
          case 'this_month': a = new Date(t.getFullYear(), t.getMonth(), 1, 12); break;
          case 'last_month': a = new Date(t.getFullYear(), t.getMonth() - 1, 1, 12); b = new Date(t.getFullYear(), t.getMonth(), 0, 12); break;
          case '': from.value = ''; to.value = ''; return;
          default: return;
        }
        from.value = iso(a); to.value = iso(b);
      });
      [from, to].forEach(function (i) { i.addEventListener('change', function () { sel.value = 'custom'; }); });
    })();
    </script>
  </div>
</div>
<?php
layout_end();
