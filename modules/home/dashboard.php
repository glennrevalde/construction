<?php
declare(strict_types=1);

// Home opens the dashboard that fits the user's work: the executive, project manager or finance dashboard, the
// client or subcontractor portal, or (for accounting-only users) the accounting overview below. ?view=accounting
// always shows the accounting overview.
if (g('view') !== 'accounting') {
    $u = DB::row('SELECT portal_customer_id, portal_vendor_id FROM users WHERE id = :id', ['id' => Auth::id()]) ?: [];
    $roles = Access::roleNames();
    $route = null;
    if (Access::page('portal.client') && !Access::page('proj.center')) {
        $route = ['portal.client', 'portal/client.php'];
    } elseif (Access::page('portal.sub') && !Access::page('proj.center')) {
        $route = ['portal.sub', 'portal/sub.php'];
    } elseif (array_intersect($roles, ['Finance Manager', 'Chief Accountant / Controller', 'Accounting Staff', 'Billing Officer', 'Collection Officer / Cashier']) && Access::page('home.fin_dashboard')) {
        $route = ['home.fin_dashboard', 'cs/dash_fin.php'];
    } elseif (array_intersect($roles, ['Project Manager', 'Site Engineer', 'Project Director', 'Quantity Surveyor', 'Site Supervisor / Foreman']) && Access::page('home.pm_dashboard')) {
        $route = ['home.pm_dashboard', 'cs/dash_pm.php'];
    } elseif (Access::page('home.exec_dashboard')) {
        $route = ['home.exec_dashboard', 'cs/dash_exec.php'];
    } elseif (Access::page('home.pm_dashboard')) {
        $route = ['home.pm_dashboard', 'cs/dash_pm.php'];
    } elseif (Access::page('home.fin_dashboard')) {
        $route = ['home.fin_dashboard', 'cs/dash_fin.php'];
    }
    if ($route) {
        $GLOBALS['CS_HOME'] = true;
        require APP_ROOT . '/modules/' . $route[1];
        return;
    }
}

$today = today();
$monthStart = date('Y-m-01');
$kpis = [];

if (Access::page('rpt.ar_aging_summary') || Access::page('cust.center')) {
    [$in, $p] = DB::in('a', Accts::arIds() ?: [0]);
    $ar = (float)DB::value("SELECT COALESCE(SUM(debit-credit),0) FROM journal_lines WHERE account_id IN $in", $p);
    $overdue = (int)DB::value(
        "SELECT COUNT(*) FROM transactions WHERE txn_type IN ('invoice','statement_charge','finance_charge')
          AND status IN ('open','partial') AND is_voided = 0 AND due_date < :d",
        ['d' => $today]
    );
    $kpis[] = ['Accounts Receivable', money($ar), "$overdue overdue document(s)", Access::page('rpt.ar_aging_summary') ? url('rpt.ar_aging_summary') : null, 'primary'];
}
if (Access::page('rpt.ap_aging_summary') || Access::page('vend.center')) {
    [$in, $p] = DB::in('a', Accts::apIds() ?: [0]);
    $ap = (float)DB::value("SELECT COALESCE(SUM(credit-debit),0) FROM journal_lines WHERE account_id IN $in", $p);
    $dueSoon = (int)DB::value(
        "SELECT COUNT(*) FROM transactions WHERE txn_type = 'bill' AND status IN ('open','partial') AND is_voided = 0 AND due_date <= :d",
        ['d' => date('Y-m-d', strtotime('+7 days'))]
    );
    $kpis[] = ['Accounts Payable', money($ap), "$dueSoon bill(s) due within 7 days", Access::page('vend.pay_bills') ? url('vend.pay_bills') : null, 'danger'];
}
if (Access::page('rpt.sales_item_summary') || Access::page('rpt.pos_daily_summary') || Access::page('cust.invoices')) {
    $sales = (float)DB::value(
        "SELECT COALESCE(SUM(l.amount),0) FROM transaction_lines l JOIN transactions t ON t.id = l.txn_id
          LEFT JOIN items i ON i.id = l.item_id
          WHERE t.txn_type IN ('invoice','sales_receipt','pos_sale') AND t.is_voided = 0 AND t.is_pending = 0
            AND t.status <> 'suspended' AND t.txn_date BETWEEN :a AND :b AND COALESCE(i.item_type,'') NOT IN ('payment','subtotal')",
        ['a' => $monthStart, 'b' => $today]
    );
    $pending = (int)DB::value("SELECT COUNT(*) FROM transactions WHERE is_pending = 1 AND is_voided = 0 AND txn_type IN ('invoice','sales_receipt')");
    $kpis[] = ['Sales this month (net of VAT)', money($sales), "$pending pending sale(s)", Access::page('rpt.sales_item_summary') ? url('rpt.sales_item_summary', ['from' => $monthStart, 'to' => $today]) : null, 'success'];
}
if (Access::page('inv.center') || Access::page('rpt.stock_status_item')) {
    $low = (int)DB::value(
        "SELECT COUNT(*) FROM items i WHERE i.is_active = 1 AND i.item_type IN ('inventory','assembly') AND i.reorder_point > 0
           AND (SELECT COALESCE(SUM(s.qty_on_hand),0) FROM item_site_stock s WHERE s.item_id = i.id) <= i.reorder_point"
    );
    $neg = (int)DB::value('SELECT COUNT(*) FROM item_site_stock WHERE qty_on_hand < 0');
    $kpis[] = ['Items at or below reorder point', (string)$low, "$neg negative stock record(s)", Access::page('rpt.stock_status_item') ? url('rpt.stock_status_item') : null, 'warning'];
}
if (Access::special('view_bank_balances')) {
    $banks = DB::all("SELECT a.id, a.code, a.name, COALESCE(SUM(j.debit - j.credit),0) AS bal FROM accounts a
                       LEFT JOIN journal_lines j ON j.account_id = a.id WHERE a.account_type = 'Bank' AND a.is_active = 1
                       GROUP BY a.id, a.code, a.name ORDER BY a.code");
}
$openShifts = Access::page('pos.shifts') ? DB::all(
    "SELECT s.*, t.name AS terminal, u.full_name FROM pos_shifts s JOIN pos_terminals t ON t.id = s.terminal_id JOIN users u ON u.id = s.cashier_id WHERE s.status = 'open' ORDER BY s.opened_at"
) : [];

$todos = DB::all(
    "SELECT n.*, CASE n.entity_type WHEN 'customer' THEN c.name WHEN 'vendor' THEN v.name ELSE i.name END AS entity_name
       FROM entity_notes n
       LEFT JOIN customers c ON n.entity_type = 'customer' AND c.id = n.entity_id
       LEFT JOIN vendors v ON n.entity_type = 'vendor' AND v.id = n.entity_id
       LEFT JOIN items i ON n.entity_type = 'item' AND i.id = n.entity_id
      WHERE n.is_todo = 1 AND n.is_done = 0 ORDER BY n.due_date IS NULL, n.due_date LIMIT 10"
);
$recent = DB::all('SELECT * FROM audit_trail WHERE user_id = :u ORDER BY id DESC LIMIT 10', ['u' => Auth::id()]);

// quick actions: allowed transaction pages with create permission
$quick = [];
foreach (Access::allPageDefs() as $k => $d) {
    if ($d['type'] === 'transaction' && Access::can($k, 'create')) {
        $quick[] = [$k, $d['name'], $d['module']];
    }
}

layout_start(g('view') === 'accounting' ? 'Accounting overview' : 'Home', ['actions' => Access::page('home.reports') ? btn_link(url('home.reports'), 'Report Center', 'btn-outline-primary', 'bar-chart') : '']);
?>
<p class="text-muted">Welcome, <?= h(Auth::user()['full_name']) ?>. Today is <?= h(date('l, F j, Y')) ?>.
<?php if ($cd = Settings::closingDate()): ?><span class="ms-2">Books closed through <strong><?= h(fdate($cd)) ?></strong>.</span><?php endif; ?></p>

<?php if ($kpis): ?>
<div class="row g-3 mb-3">
  <?php foreach ($kpis as [$label, $value, $sub, $link, $color]): ?>
  <div class="col-sm-6 col-xl-3">
    <div class="card kpi h-100" style="border-left-color: var(--bs-<?= h($color) ?>)">
      <div class="card-body">
        <div class="small text-muted"><?= h($label) ?></div>
        <div class="value"><?= h($value) ?></div>
        <div class="small"><?= $link ? '<a href="' . h($link) . '">' . h($sub) . '</a>' : h($sub) ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <?php if ($quick):
        // same links as before, set out by module so they are quick to scan
        $quickByModule = [];
        foreach ($quick as [$k, $name, $module]) {
            $quickByModule[$module][] = [$k, $name];
        }
        $newForm = ['cust.estimates', 'cust.sales_orders', 'cust.invoices', 'cust.sales_receipts', 'cust.statement_charges', 'cust.credit_memos', 'vend.bills', 'vend.purchase_orders', 'bank.write_checks', 'coa.journal'];
    ?>
    <div class="card mb-3"><div class="card-header fw-semibold">Quick actions</div>
      <div class="card-body">
        <?php foreach ($quickByModule as $module => $links): ?>
          <div class="quick-group">
            <div class="quick-name"><?= h(Access::modules()[$module][0] ?? $module) ?></div>
            <div class="quick-links">
              <?php foreach ($links as [$k, $name]): ?>
                <a class="btn btn-sm btn-outline-primary" href="<?= h(url($k, in_array($k, $newForm, true) ? ['a' => 'new'] : [])) ?>"><?= h($name) ?></a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($banks)): ?>
    <div class="card mb-3"><div class="card-header fw-semibold">Cash and bank accounts</div>
      <table class="table table-sm mb-0">
        <?php foreach ($banks as $b): ?>
          <tr><td><?= safe_link('bank.register', ['account_id' => $b['id']], $b['code'] . ' - ' . $b['name']) ?></td><td class="num"><?= money($b['bal']) ?></td></tr>
        <?php endforeach; ?>
      </table>
    </div>
    <?php endif; ?>

    <?php if ($openShifts): ?>
    <div class="card mb-3"><div class="card-header fw-semibold">Open POS shifts</div>
      <table class="table table-sm mb-0"><thead><tr><th>Terminal</th><th>Cashier</th><th>Opened</th><th class="num">Float</th></tr></thead>
        <?php foreach ($openShifts as $s): ?>
          <tr><td><?= h($s['terminal']) ?></td><td><?= h($s['full_name']) ?></td><td><?= h(fdatetime($s['opened_at'])) ?></td><td class="num"><?= money($s['opening_float']) ?></td></tr>
        <?php endforeach; ?>
      </table>
    </div>
    <?php endif; ?>
  </div>
  <div class="col-lg-5">
    <?php $memDue = Memorized::remindsUser() ? Memorized::due() : []; if ($memDue): ?>
    <div class="card mb-3"><div class="card-header fw-semibold d-flex justify-content-between align-items-center"><span><i class="bi bi-bookmark-check"></i> Memorized transactions due (<?= count($memDue) ?>)</span>
        <a class="btn btn-sm btn-outline-primary" href="<?= h(url('coa.memorized_txns')) ?>">Open</a></div>
      <div class="card-body py-2"><div class="reminder-list" style="max-height:230px">
        <?php foreach (array_slice($memDue, 0, 10) as $r): ?>
          <div class="reminder-item small"><div><strong><?= h($r['name']) ?></strong><div class="text-muted"><?= h(Txn::label($r['txn_type'])) ?><?= $r['entity_label'] ? ', ' . h($r['entity_label']) : '' ?></div></div>
            <div class="text-end text-nowrap"><span class="pm-state <?= $r['next_date'] < $today ? 'overdue' : 'due' ?>"><?= $r['next_date'] < $today ? 'Overdue' : 'Due' ?></span><div class="text-muted"><?= h(fdate($r['next_date'])) ?></div></div></div>
        <?php endforeach; ?>
      </div></div>
    </div>
    <?php endif; ?>
    <?php $pmDue = AssetMaint::remindsUser() ? AssetMaint::reminders() : []; if ($pmDue): ?>
    <div class="card mb-3"><div class="card-header fw-semibold d-flex justify-content-between align-items-center"><span><i class="bi bi-bell text-danger"></i> Asset maintenance due (<?= count($pmDue) ?>)</span>
        <a class="btn btn-sm btn-outline-primary" href="<?= h(url('fa.pm', ['tab' => 'due'])) ?>">Open</a></div>
      <div class="card-body py-2"><div class="reminder-list" style="max-height:260px">
        <?php foreach (array_slice($pmDue, 0, 12) as $r): ?>
          <div class="reminder-item small"><div><strong><?= h($r['task_name']) ?></strong><div class="text-muted"><?= h($r['asset_no'] . ' - ' . $r['asset_description']) ?></div></div>
            <div class="text-end text-nowrap"><span class="pm-state <?= h($r['state']) ?>"><?= h($r['state_text']) ?></span><div class="text-muted"><?= h(fdate($r['next_due_date'])) ?></div></div></div>
        <?php endforeach; ?>
      </div><div class="small text-muted mt-1">A task stays here until it is acknowledged as complete.</div></div>
    </div>
    <?php endif; ?>
    <div class="card mb-3"><div class="card-header fw-semibold">To-do reminders</div>
      <?php if (!$todos): ?><div class="card-body text-muted small">No open reminders.</div><?php else: ?>
      <ul class="list-group list-group-flush">
        <?php foreach ($todos as $t): ?>
          <li class="list-group-item small"><strong><?= h($t['entity_name']) ?>:</strong> <?= h($t['note']) ?>
            <?php if ($t['due_date']): ?><span class="badge text-bg-<?= $t['due_date'] < $today ? 'danger' : 'light' ?>"><?= h(fdate($t['due_date'])) ?></span><?php endif; ?></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
    <div class="card"><div class="card-header fw-semibold">My recent activity</div>
      <ul class="list-group list-group-flush">
        <?php foreach ($recent as $r): ?>
          <li class="list-group-item small d-flex justify-content-between"><span><?= h(ucwords(str_replace('_', ' ', $r['action']))) ?> <?= h($r['record_ref']) ?></span><span class="text-muted"><?= h(fdatetime($r['created_at'])) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</div>
<?php
layout_end();
