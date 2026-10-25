<?php
declare(strict_types=1);

require_once APP_ROOT . '/modules/txn/_common.php';

$pageKey = 'bank.reconcile';
if (txn_common_actions($pageKey, ['bank_charge', 'bank_interest'])) {
    return;
}
$a = g('a');
$banks = Accts::banks();
$accountId = (int)(g('account_id') ?: p('account_id') ?: DB::value("SELECT id FROM accounts WHERE code = '1010'") ?: array_key_first($banks));
$last = DB::row("SELECT * FROM bank_reconciliations WHERE account_id = :a AND status = 'completed' ORDER BY statement_date DESC, id DESC LIMIT 1", ['a' => $accountId]);
$draft = DB::row("SELECT * FROM bank_reconciliations WHERE account_id = :a AND status = 'in_progress' ORDER BY id DESC LIMIT 1", ['a' => $accountId]);
$beginning = $last ? (float)$last['ending_balance'] : 0.0;

// undo the last reconciliation
if ($a === 'undo' && is_post()) {
    verify_csrf();
    Access::require($pageKey, 'delete');
    if ($last) {
        try {
            DB::tx(function () use ($last, $pageKey) {
                $reason = trim((string)p('reason')) ?: 'Undo last reconciliation';
                DB::run('UPDATE journal_lines SET cleared = 0, reconciliation_id = NULL WHERE reconciliation_id = :r', ['r' => $last['id']]);
                // the service charge and interest it posted are voided; their figures stay on the reconciliation,
                // so they are posted again when it is finished
                $keep = ['status' => 'in_progress', 'service_charge_txn_id' => null, 'interest_txn_id' => null];
                foreach (['service' => 'service_charge_txn_id', 'interest' => 'interest_txn_id'] as $kind => $col) {
                    $tid = (int)($last[$col] ?? 0);
                    $t = $tid ? Txn::load($tid) : null;
                    if (!$t || (int)$t['is_voided'] === 1) {
                        continue;
                    }
                    $amountCol = $kind === 'service' ? 'service_charge' : 'interest_earned';
                    if (abs((float)($last[$amountCol] ?? 0)) < 0.005) {
                        // finished before these figures were kept: read them back from the entry
                        $other = DB::row('SELECT account_id, ABS(debit - credit) AS amt FROM transaction_lines WHERE txn_id = :t AND account_id <> :a ORDER BY id LIMIT 1',
                            ['t' => $tid, 'a' => (int)$last['account_id']]);
                        $keep[$amountCol] = r2((float)($other['amt'] ?? $t['total']));
                        $keep[$kind . '_date'] = $t['txn_date'];
                        $keep[$kind . '_account_id'] = $other['account_id'] ?? null;
                        $keep['class_id'] = $keep['class_id'] ?? ($t['class_id'] ?? null);
                    }
                    Txn::void($tid, 'Reconciliation of ' . fdate($last['statement_date']) . ' undone: ' . $reason);
                }
                DB::update('bank_reconciliations', $keep, 'id = :id', ['id' => $last['id']]);
                Audit::log('reconcile_undo', 'bank', 'reconciliation', (int)$last['id'], 'Reconciliation ' . fdate($last['statement_date']), [
                    'reason' => $reason, 'page_key' => $pageKey,
                ]);
            });
            flash('warning', 'The last reconciliation was undone. Its items are uncleared, its service charge and interest entries are voided, and it is open for editing.');
        } catch (UserError $e) {
            flash('danger', 'The reconciliation was not undone: ' . $e->getMessage());
        }
    }
    redirect(url($pageKey, ['account_id' => $accountId]));
}

$stmtDate = (string)(p('statement_date') ?: g('statement_date') ?: ($draft['statement_date'] ?? date('Y-m-t', strtotime('last day of last month'))));
$ending = p('ending_balance') !== '' ? pnum('ending_balance') : (float)($draft['ending_balance'] ?? 0);

$items = DB::all(
    "SELECT j.txn_id, t.txn_type, t.txn_no, t.ref_no, t.txn_date, COALESCE(c.name, v.name, o.name, t.payee_name) AS party, t.memo,
            SUM(j.debit - j.credit) AS amount
       FROM journal_lines j JOIN transactions t ON t.id = j.txn_id
       LEFT JOIN customers c ON c.id = t.customer_id LEFT JOIN vendors v ON v.id = t.vendor_id
       LEFT JOIN other_names o ON o.id = t.other_name_id
      WHERE j.account_id = :a AND j.cleared = 0 AND j.txn_date <= :d
      GROUP BY j.txn_id, t.txn_type, t.txn_no, t.ref_no, t.txn_date, c.name, v.name, o.name, t.payee_name, t.memo
     HAVING ABS(SUM(j.debit - j.credit)) >= 0.005
      ORDER BY t.txn_date, j.txn_id",
    ['a' => $accountId, 'd' => $stmtDate]
);
$draftMarked = $draft ? array_map('intval', DB::column('SELECT txn_id FROM reconciliation_items WHERE reconciliation_id = :r AND cleared = 1', ['r' => $draft['id']])) : [];

handle_post(function () use ($pageKey, $accountId, $items, $beginning, $draft, $stmtDate) {
    $do = p('do');
    Access::require($pageKey, 'create');
    if (!valid_date($stmtDate)) {
        fail('Enter the statement date.');
    }
    $ending = r2(pnum('ending_balance'));
    $marked = array_map('intval', (array)($_POST['clear'] ?? []));
    $byId = array_column($items, null, 'txn_id');
    $clearedSum = 0.0;
    foreach ($marked as $tid) {
        if (!isset($byId[$tid])) {
            fail('An item changed while you were reconciling. Refresh and try again.');
        }
        $clearedSum += (float)$byId[$tid]['amount'];
    }
    $svc = r2(pnum('service_charge'));
    $int = r2(pnum('interest_earned'));
    $clearedBalance = r2($beginning + $clearedSum - $svc + $int);
    $difference = r2($ending - $clearedBalance);

    $result = DB::tx(function () use ($do, $draft, $accountId, $stmtDate, $beginning, $ending, $clearedBalance, $difference, $marked, $items, $svc, $int, $pageKey) {
        $recData = [
            'account_id' => $accountId, 'statement_date' => $stmtDate, 'beginning_balance' => $beginning,
            'ending_balance' => $ending, 'cleared_balance' => $clearedBalance, 'difference' => $difference,
            // kept with the reconciliation, so "Save for later" brings them back and "Undo last" can post them again
            'service_charge' => $svc, 'service_date' => pdate('service_date', $stmtDate), 'service_account_id' => pint('service_account_id'),
            'interest_earned' => $int, 'interest_date' => pdate('interest_date', $stmtDate), 'interest_account_id' => pint('interest_account_id'),
            'class_id' => Classes::ready() ? Classes::posted() : null,
        ];
        if ($draft) {
            DB::update('bank_reconciliations', $recData, 'id = :id', ['id' => $draft['id']]);
            $rid = (int)$draft['id'];
        } else {
            $rid = DB::insert('bank_reconciliations', $recData + ['created_by' => Auth::id(), 'status' => 'in_progress']);
        }
        DB::run('DELETE FROM reconciliation_items WHERE reconciliation_id = :r', ['r' => $rid]);
        foreach ($items as $it) {
            DB::insert('reconciliation_items', ['reconciliation_id' => $rid, 'txn_id' => $it['txn_id'], 'amount' => r2($it['amount']), 'cleared' => in_array((int)$it['txn_id'], $marked, true) ? 1 : 0]);
        }
        if ($do === 'save') {
            Audit::log('reconcile_saved', 'bank', 'reconciliation', $rid, 'Reconciliation ' . fdate($stmtDate), ['page_key' => $pageKey, 'reason' => 'Difference ' . money($difference)]);
            return ['saved', $rid];
        }
        if (abs($difference) >= 0.005) {
            fail('The difference must be zero to finish. Current difference: ' . money($difference) . '.');
        }
        $bank = $accountId;
        $extra = [];
        if ($svc > 0) {
            $sid = Txn::save('bank_charge', ['txn_date' => pdate('service_date', $stmtDate), 'account_id' => $bank, 'memo' => 'Bank service charge'], [
                ['line_kind' => 'journal', 'account_id' => pint('service_account_id') ?: Accts::get('merchant_fees'), 'amount' => $svc, 'description' => 'Service charge'],
                ['line_kind' => 'journal', 'account_id' => $bank, 'amount' => -$svc, 'description' => 'Service charge'],
            ]);
            $extra[] = $sid;
            DB::update('bank_reconciliations', ['service_charge_txn_id' => $sid], 'id = :id', ['id' => $rid]);
        }
        if ($int > 0) {
            $iid = Txn::save('bank_interest', ['txn_date' => pdate('interest_date', $stmtDate), 'account_id' => $bank, 'memo' => 'Interest earned'], [
                ['line_kind' => 'journal', 'account_id' => $bank, 'amount' => $int, 'description' => 'Interest earned'],
                ['line_kind' => 'journal', 'account_id' => pint('interest_account_id') ?: Accts::get('interest_income'), 'amount' => -$int, 'description' => 'Interest earned'],
            ]);
            $extra[] = $iid;
            DB::update('bank_reconciliations', ['interest_txn_id' => $iid], 'id = :id', ['id' => $rid]);
        }
        foreach (array_merge($marked, $extra) as $tid) {
            DB::run('UPDATE journal_lines SET cleared = 1, reconciliation_id = :r WHERE txn_id = :t AND account_id = :a', ['r' => $rid, 't' => $tid, 'a' => $bank]);
            if (in_array($tid, $extra, true)) {
                $amt = (float)DB::value('SELECT SUM(debit - credit) FROM journal_lines WHERE txn_id = :t AND account_id = :a', ['t' => $tid, 'a' => $bank]);
                DB::insert('reconciliation_items', ['reconciliation_id' => $rid, 'txn_id' => $tid, 'amount' => r2($amt), 'cleared' => 1]);
            }
        }
        DB::update('bank_reconciliations', ['status' => 'completed', 'completed_by' => Auth::id(), 'completed_at' => date('Y-m-d H:i:s'), 'difference' => 0], 'id = :id', ['id' => $rid]);
        Audit::log('reconcile', 'bank', 'reconciliation', $rid, account_label($bank) . ', ' . fdate($stmtDate), [
            'page_key' => $pageKey, 'amount' => $ending, 'reason' => count($marked) . ' items cleared',
        ]);
        return ['done', $rid];
    });
    if ($result[0] === 'saved') {
        flash('info', 'Progress saved. Difference: ' . money($difference));
        redirect(url($pageKey, ['account_id' => $accountId]));
    }
    flash('success', 'Reconciliation complete.');
    redirect(url($pageKey, ['account_id' => $accountId, 'done' => $result[1]]));
});

$history = DB::all('SELECT r.*, u.username FROM bank_reconciliations r LEFT JOIN users u ON u.id = r.completed_by WHERE r.account_id = :a ORDER BY r.statement_date DESC, r.id DESC LIMIT 12', ['a' => $accountId]);
$markedNow = is_post() ? array_map('intval', (array)($_POST['clear'] ?? [])) : $draftMarked;

layout_start('Reconcile', ['actions' => page_link('rpt.recon_discrepancy', ['account_id' => $accountId], 'Discrepancy report', 'btn-outline-secondary', 'exclamation-triangle')]);
?>
<form method="get" class="row g-2 mb-3 filters"><input type="hidden" name="p" value="<?= h($pageKey) ?>">
  <?= select_field('account_id', 'Account', $banks, $accountId, ['class' => 'col-md-4', 'extra' => 'data-autosubmit']) ?>
  <div class="col-md-2"><label class="form-label">Statement date</label><input class="form-control form-control-sm" type="date" name="statement_date" value="<?= h($stmtDate) ?>" data-autosubmit></div>
  <div class="col-md-6 small d-flex align-items-end">Last reconciled: <?= $last ? h(fdate($last['statement_date'])) . ', ending balance ' . money($last['ending_balance']) : 'never' ?></div>
</form>
<form method="post" id="recForm" data-class-check-when="#svc,#int"><?= csrf_field() ?>
<input type="hidden" name="account_id" value="<?= (int)$accountId ?>"><input type="hidden" name="statement_date" value="<?= h($stmtDate) ?>">
<?php
// a reconciliation saved for later (or undone) opens with the service charge and interest it had
$dv = $draft ? [
    'service_charge' => (float)$draft['service_charge'] ? number_format((float)$draft['service_charge'], 2, '.', '') : '',
    'service_date' => $draft['service_date'] ?: $stmtDate, 'service_account_id' => $draft['service_account_id'] ?: Accts::get('merchant_fees'),
    'interest_earned' => (float)$draft['interest_earned'] ? number_format((float)$draft['interest_earned'], 2, '.', '') : '',
    'interest_date' => $draft['interest_date'] ?: $stmtDate, 'interest_account_id' => $draft['interest_account_id'] ?: Accts::get('interest_income'),
    'class_id' => $draft['class_id'],
] : null;
?>
<div class="card mb-3"><div class="card-body row g-2">
  <div class="col-md-2"><label class="form-label">Beginning balance</label><div class="form-control form-control-sm bg-light text-end" id="begBal" data-v="<?= h($beginning) ?>"><?= money($beginning) ?></div></div>
  <?= input_field('ending_balance', 'Ending balance (from statement)', fv('ending_balance', null, $draft ? number_format((float)$draft['ending_balance'], 2, '.', '') : ''), ['class' => 'col-md-2', 'required' => true, 'extra' => 'id="endBal"']) ?>
  <?= input_field('service_charge', 'Service charge', fv('service_charge', $dv), ['class' => 'col-md-2', 'extra' => 'id="svc"']) ?>
  <?= input_field('service_date', 'Date', fv('service_date', $dv, $stmtDate), ['type' => 'date', 'class' => 'col-md-2']) ?>
  <?= select_field('service_account_id', 'Account', Accts::ofType(['Expense', 'Other Expense']), fv('service_account_id', $dv, Accts::get('merchant_fees')), ['class' => 'col-md-4']) ?>
  <?= Classes::field(fv('class_id', $dv), 'col-md-4', 'class_id', 'Class (service charge and interest)') ?: '<div class="col-md-4"></div>' ?>
  <?= input_field('interest_earned', 'Interest earned', fv('interest_earned', $dv), ['class' => 'col-md-2', 'extra' => 'id="int"']) ?>
  <?= input_field('interest_date', 'Date', fv('interest_date', $dv, $stmtDate), ['type' => 'date', 'class' => 'col-md-2']) ?>
  <?= select_field('interest_account_id', 'Account', Accts::ofType(['Income', 'Other Income']), fv('interest_account_id', $dv, Accts::get('interest_income')), ['class' => 'col-md-4']) ?>
</div></div>
<div class="row g-3">
  <?php foreach (['payments' => 'Checks and payments', 'deposits' => 'Deposits and other credits'] as $side => $title): ?>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header d-flex justify-content-between"><span class="fw-semibold"><?= h($title) ?></span>
    <label class="small"><input type="checkbox" class="form-check-input" data-check-all=".rc-<?= $side ?>"> Mark all</label></div>
    <div class="table-responsive" style="max-height:50vh"><table class="table table-sm mb-0">
      <thead><tr><th>✓</th><th>Date</th><th>No.</th><th>Payee</th><th class="num">Amount</th></tr></thead>
      <?php foreach ($items as $it): if (($side === 'payments') !== ((float)$it['amount'] < 0)) { continue; } ?>
        <tr><td><input type="checkbox" class="form-check-input rc-cb rc-<?= $side ?>" name="clear[]" value="<?= (int)$it['txn_id'] ?>" data-amt="<?= h($it['amount']) ?>" <?= in_array((int)$it['txn_id'], $markedNow, true) ? 'checked' : '' ?>></td>
          <td><?= h(fdate($it['txn_date'])) ?></td><td><?= Txn::link(['id' => $it['txn_id'], 'txn_type' => $it['txn_type'], 'txn_no' => $it['txn_no']], ($it['txn_no'] ?: '#' . $it['txn_id'])) ?> <span class="small text-muted"><?= h(Txn::label($it['txn_type'])) ?></span></td>
          <td class="small"><?= h($it['party']) ?></td><td class="num"><?= money(abs((float)$it['amount'])) ?></td></tr>
      <?php endforeach; ?>
    </table></div></div></div>
  <?php endforeach; ?>
</div>
<div class="card mt-3"><div class="card-body d-flex flex-wrap gap-4 align-items-center">
  <div>Cleared balance: <strong id="clrBal">0.00</strong></div>
  <div>Ending balance: <strong id="endShow">0.00</strong></div>
  <div class="fs-5">Difference: <strong id="diff">0.00</strong></div>
  <div class="ms-auto d-flex gap-2">
    <button class="btn btn-outline-secondary" name="do" value="save">Save for later</button>
    <button class="btn btn-success" name="do" value="finish" id="finishBtn">Reconcile now</button>
  </div>
</div></div>
</form>

<div class="card mt-3"><div class="card-header d-flex justify-content-between"><span class="fw-semibold">Reconciliation history</span>
  <?php if ($last && Access::can($pageKey, 'delete')): ?>
    <form method="post" action="<?= h(url($pageKey, ['a' => 'undo', 'account_id' => $accountId])) ?>" class="d-flex gap-1"><?= csrf_field() ?>
      <input class="form-control form-control-sm" name="reason" placeholder="Reason" required><button class="btn btn-sm btn-outline-danger" data-confirm="Undo the last reconciliation?">Undo last</button></form>
  <?php endif; ?></div>
  <table class="table table-sm mb-0"><thead><tr><th>Statement date</th><th class="num">Beginning</th><th class="num">Ending</th><th>Status</th><th>By</th><th>Reports</th></tr></thead>
  <?php foreach ($history as $r): ?>
    <tr><td><?= h(fdate($r['statement_date'])) ?></td><td class="num"><?= money($r['beginning_balance']) ?></td><td class="num"><?= money($r['ending_balance']) ?></td><td><?= status_badge($r['status']) ?></td><td><?= h($r['username']) ?></td>
      <td><?= safe_link('rpt.recon_summary', ['rec_id' => $r['id']], 'Summary') ?> | <?= safe_link('rpt.recon_detail', ['rec_id' => $r['id']], 'Detail') ?></td></tr>
  <?php endforeach; ?>
  </table>
</div>
<script>
(function () {
  const {num, fmt, r2} = AppFmt;
  const upd = () => {
    let s = num(document.getElementById('begBal').dataset.v);
    document.querySelectorAll('.rc-cb:checked').forEach(c => s += num(c.dataset.amt));
    s = r2(s - num(document.getElementById('svc').value) + num(document.getElementById('int').value));
    const end = num(document.getElementById('endBal').value);
    document.getElementById('clrBal').textContent = fmt(s);
    document.getElementById('endShow').textContent = fmt(end);
    const d = r2(end - s);
    const el = document.getElementById('diff'); el.textContent = fmt(d); el.className = Math.abs(d) < 0.005 ? 'text-success' : 'text-danger';
    document.getElementById('finishBtn').disabled = Math.abs(d) >= 0.005;
  };
  document.getElementById('recForm').addEventListener('input', upd);
  document.getElementById('recForm').addEventListener('change', upd);
  upd();
})();
</script>
<?php
layout_end();
