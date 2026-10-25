<?php
declare(strict_types=1);

/**
 * Receivables and collections of the projects: every invoice charged to a project (made from a billing or typed in
 * accounting), what is open, how old, the taxes the client is expected to withhold, and the follow-ups. Payments are
 * received in accounting (Receive Payments), where the withholding and BIR Form 2307 are recorded.
 */
$pageKey = 'pbill.collections';
$pid = CsProject::current();

handle_post(function () use ($pageKey, $pid) {
    Access::require($pageKey, 'edit');
    $cust = (int)p('customer_id');
    $note = trim((string)p('note'));
    if (!$cust || $note === '') {
        fail('Choose the client and write the follow-up.');
    }
    DB::insert('entity_notes', ['entity_type' => 'customer', 'entity_id' => $cust, 'note' => mb_substr('Collection: ' . $note, 0, 2000), 'is_todo' => pdate('due_date') ? 1 : 0, 'due_date' => pdate('due_date'), 'created_by' => Auth::id()]);
    Audit::log('follow_up', 'pbill', 'customer', $cust, (string)DB::value('SELECT name FROM customers WHERE id = :c', ['c' => $cust]), ['page_key' => $pageKey, 'reason' => mb_substr($note, 0, 200)]);
    flash('success', 'Follow-up recorded on the client.');
    cs_go($pageKey, ['project' => $pid]);
});

layout_start('Collections & Receivables', ['actions' => page_link('cust.payments', [], 'Receive payment', 'btn-primary', 'cash-coin', 'create') . ' ' . page_link('cust.statements', [], 'Statements', 'btn-outline-secondary', 'file-text')]);
echo cs_filter_bar($pageKey, $pid, '<div><label class="form-label">Show</label><select class="form-select form-select-sm" name="show" data-no-combo onchange="this.form.submit()">'
    . options(['open' => 'Open invoices', 'all' => 'All invoices of the last 12 months'], (string)g('show', 'open')) . '</select></div>');
$cls = CsCost::classes($pid ? [$pid] : CsProject::accessibleIds());
if (!$cls) {
    echo '<div class="card">' . CsUi::empty('No project to show.') . '</div>';
    layout_end();
    return;
}
[$in, $pp] = DB::in('col', array_keys($cls));
$show = (string)g('show', 'open');
$invs = DB::all("SELECT t.*, c.name AS customer_name, b.billing_no, b.id AS billing_id, b.wht_expected
                   FROM transactions t JOIN customers c ON c.id = t.customer_id LEFT JOIN cs_billings b ON b.invoice_txn_id = t.id
                  WHERE t.txn_type IN ('invoice','statement_charge') AND t.is_voided = 0 AND t.is_pending = 0 AND t.class_id IN $in"
    . ($show === 'open' ? " AND t.status IN ('open','partial')" : ' AND t.txn_date >= :since') . ' ORDER BY t.due_date, t.id', $pp + ($show === 'open' ? [] : ['since' => date('Y-m-d', strtotime('-12 months'))]));
$buckets = ['current' => 0.0, '1-30' => 0.0, '31-60' => 0.0, '61-90' => 0.0, 'over 90' => 0.0];
$rows = [];
$tot = ['total' => 0.0, 'open' => 0.0, 'wht' => 0.0, 'n' => 0];
$byClient = [];
foreach ($invs as $t) {
    $open = Balances::open($t);
    $days = $t['due_date'] ? (int)floor((strtotime(today()) - strtotime($t['due_date'])) / 86400) : 0;
    $bk = $open <= 0.004 ? null : ($days <= 0 ? 'current' : ($days <= 30 ? '1-30' : ($days <= 60 ? '31-60' : ($days <= 90 ? '61-90' : 'over 90'))));
    if ($bk) {
        $buckets[$bk] += $open;
    }
    $tot['total'] += (float)$t['total'];
    $tot['open'] += $open;
    $tot['n'] += $open > 0.004 ? 1 : 0;
    $tot['wht'] += $open > 0.004 ? (float)$t['wht_expected'] : 0;
    $byClient[(int)$t['customer_id']] = ($byClient[(int)$t['customer_id']] ?? 0) + $open;
    $proj = CsProject::byClass((int)$t['class_id']);
    $rows[] = [Txn::link($t) . ($t['billing_no'] ? '<span class="cs-sub"><a href="' . h(url('pbill.billings', ['a' => 'view', 'id' => $t['billing_id']])) . '">' . h($t['billing_no']) . '</a></span>' : ''), h($t['customer_name']), $proj ? h($proj['code']) : '',
        h(fdate($t['txn_date'])), '<span class="' . ($bk && $days > 0 ? 'cs-bad' : '') . '">' . h(fdate($t['due_date'])) . '</span>' . ($bk && $days > 0 ? '<span class="cs-sub cs-bad">' . $days . ' days overdue</span>' : ''),
        money($t['total']), money($open), (float)$t['wht_expected'] && $open > 0.004 ? money($t['wht_expected']) : '',
        $open > 0.004 ? page_link('cust.payments', ['customer_id' => $t['customer_id']], 'Receive', 'btn-outline-primary', '', 'create') : '<span class="cs-good">Paid</span>'];
}
$s = $pid ? CsSummary::project($pid) : null;
echo '<div class="cs-kpis">' . CsUi::kpi('Open receivables', CsUi::amt($tot['open']), $tot['n'] . ' invoice(s)', 'key')
    . CsUi::kpi('Overdue', CsUi::amt($tot['open'] - $buckets['current']), 'Past their due date', $tot['open'] - $buckets['current'] > 0.5 ? 'bad' : 'ok')
    . CsUi::kpi('Expected withholding', CsUi::amt($tot['wht']), 'Ask for BIR Form 2307 with each payment')
    . ($s ? CsUi::kpi('Retention receivable', CsUi::amt($s['retention_receivable']), 'Billed when released') . CsUi::kpi('Collected to date', CsUi::amt($s['collected']), 'Of ' . money($s['invoiced']) . ' invoiced') : '') . '</div>';
echo '<div class="card mb-3"><div class="card-header">Aging of what is open</div><div class="card-body"><div class="cs-cols" style="height:120px">';
$max = max(1, max($buckets));
foreach ($buckets as $k => $v) {
    echo '<div class="col-pair" title="' . h($k . ': ' . money($v)) . '"><div class="c' . ($k === 'current' ? '' : ' b') . '" style="height:' . round($v / $max * 100) . '%;width:60%;max-width:46px"></div></div>';
}
echo '</div><div class="cs-cols-x">';
foreach ($buckets as $k => $v) {
    echo '<span>' . h(ucfirst($k)) . '<br><strong>' . h(CsUi::amt($v, true)) . '</strong></span>';
}
echo '</div></div></div>';
echo '<div class="cs-split is-wide-side"><div>' . cs_table_card(($show === 'open' ? 'Open invoices' : 'Invoices'), ['Invoice' => '', 'Client' => '', 'Project' => '', 'Date' => '', 'Due' => '', 'Total' => 'num', 'Open' => 'num', 'Expected withholding' => 'num', '' => ''], $rows, 'Nothing is open.', '',
    $rows ? '<tfoot><tr><td colspan="5">Total</td><td class="num">' . money($tot['total']) . '</td><td class="num">' . money($tot['open']) . '</td><td class="num">' . money($tot['wht']) . '</td><td></td></tr></tfoot>' : '') . '</div><div class="cs-stack">';
if (Access::can($pageKey, 'edit') && $byClient) {
    $clients = DB::pairs('SELECT id, name FROM customers WHERE id IN (' . implode(',', array_map('intval', array_keys($byClient))) . ') ORDER BY name');
    echo '<form method="post" class="card"><div class="card-header">Record a follow-up</div><div class="card-body"><div class="row g-2">' . csrf_field()
        . CsUi::field('Client', CsUi::select('customer_id', $clients, '', 'Choose', 'data-no-combo required'), 'col-12') . CsUi::field('What was said or agreed', CsUi::textarea('note', '', 3, 'required'), 'col-12')
        . CsUi::field('Follow up on', CsUi::input('due_date', date('Y-m-d', strtotime('+7 days')), ['type' => 'date']), 'col-12') . '</div></div><div class="card-footer"><button class="btn btn-primary btn-sm">Save</button></div></form>';
    $notes = DB::all("SELECT n.*, c.name, u.full_name FROM entity_notes n JOIN customers c ON c.id = n.entity_id LEFT JOIN users u ON u.id = n.created_by
                       WHERE n.entity_type = 'customer' AND n.entity_id IN (" . implode(',', array_map('intval', array_keys($byClient))) . ") AND n.note LIKE 'Collection:%' ORDER BY n.id DESC LIMIT 15");
    if ($notes) {
        echo '<div class="card"><div class="card-header">Recent follow-ups</div><div class="card-body"><ul class="cs-trail">';
        foreach ($notes as $n) {
            echo '<li class="' . ((int)$n['is_todo'] && !(int)$n['is_done'] && $n['due_date'] && $n['due_date'] <= today() ? 'is-now' : '') . '"><div class="who">' . h($n['name']) . '</div><div class="when">' . h(fdatetime($n['created_at'])) . ', ' . h((string)$n['full_name'])
                . ($n['due_date'] ? ', follow up ' . h(fdate($n['due_date'])) : '') . '</div><div class="what">' . h(substr($n['note'], 12)) . '</div></li>';
        }
        echo '</ul></div></div>';
    }
}
echo '</div></div>';
layout_end();
