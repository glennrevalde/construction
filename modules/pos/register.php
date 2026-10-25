<?php
declare(strict_types=1);

require_once APP_ROOT . '/modules/txn/_common.php';

$pageKey = 'pos.register';
$a = g('a');

if ($tid = gint('terminal')) {
    if (isset(Pos::terminals()[$tid])) {
        $_SESSION['pos_terminal_id'] = $tid;
    }
    redirect(url($pageKey));
}
// changing the site moves to the first counter this user may use at that site
if ($sid = gint('site')) {
    if ($atSite = Pos::terminalAtSite($sid)) {
        $_SESSION['pos_terminal_id'] = $atSite;
    }
    redirect(url($pageKey));
}
$terminal = Pos::terminal();
$shift = $terminal ? Pos::openShift((int)$terminal['id']) : null;

// ------------------------------------------------------------------ receipt
if ($a === 'receipt') {
    $t = Txn::load((int)g('id'));
    if (!$t || !in_array($t['txn_type'], ['pos_sale', 'pos_return', 'payment', 'invoice'], true)) {
        redirect(url($pageKey));
    }
    if (g('reprint')) {
        Access::require('pos.receipts', 'print');
        Audit::log('reprint', 'pos', 'transaction', (int)$t['id'], Txn::ref($t), ['page_key' => 'pos.receipts', 'terminal_id' => $t['terminal_id']]);
    }
    $lines = Txn::lines((int)$t['id']);
    // item groups print as one line each (the group, how many, its price), or as the items in them
    $hasGroups = ItemGroups::ready() && ItemGroups::has($lines);
    $lineMode = ItemGroups::viewMode();
    if ($hasGroups && $lineMode === 'groups') {
        $lines = ItemGroups::collapse($lines);
    }
    $tenders = DB::all('SELECT pt.*, pm.name FROM pos_tenders pt JOIN payment_methods pm ON pm.id = pt.payment_method_id WHERE pt.txn_id = :id', ['id' => $t['id']]);
    $s = Settings::all();
    $term = DB::row('SELECT * FROM pos_terminals WHERE id = :id', ['id' => $t['terminal_id']]);
    $cashier = DB::value('SELECT full_name FROM users WHERE id = :id', ['id' => $t['created_by']]);
    // wording comes from System Administration > Company, Security & Closing Date > POS receipt
    $titleKey = ['pos_return' => 'pos_receipt_title_return', 'invoice' => 'pos_receipt_title_charge', 'payment' => 'pos_receipt_title_payment'][$t['txn_type']] ?? 'pos_receipt_title';
    $receiptTitle = trim((string)$s[$titleKey]) !== '' ? $s[$titleKey] : Settings::DEFAULTS[$titleKey];
    $footerLines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$s['pos_receipt_footer'])), fn($x) => $x !== ''));
    $customerName = entity_name($t);
    if ($customerName === '' && $s['pos_receipt_show_customer'] === '1') {
        $customerName = 'Walk-in customer';
    }
    // the same receipt again, written out for a Bluetooth roll printer (System Administration > Company ... > Bluetooth receipt printer)
    $btOn = Receipt::btEnabled();
    $btOps = $btOn ? Receipt::ops($t, $lines, $tenders, [
        'title' => $receiptTitle, 'customer' => $customerName, 'cashier' => (string)$cashier,
        'terminal' => (string)($term['name'] ?? ''), 'footer' => $footerLines,
    ]) : [];
    layout_start('Receipt', ['bare' => true, 'no_title' => true, 'js' => $btOn ? ['js/btprint.js'] : []]);
    ?>
    <div class="no-print text-center mb-2"><button class="btn btn-primary btn-sm" onclick="window.print()" autofocus><i class="bi bi-printer"></i> Print</button>
      <?php if ($btOn): ?><button class="btn btn-outline-primary btn-sm" type="button" data-bt-print data-bt-auto data-bt-ops="#btReceipt"><i class="bi bi-bluetooth"></i> Bluetooth printer</button><?php endif; ?>
      <a class="btn btn-success btn-sm" href="<?= h(url($pageKey)) ?>">New sale</a>
      <?php if ($hasGroups): ?><div class="mt-2"><?= ItemGroups::viewSwitch($lineMode) ?></div><?php endif; ?></div>
    <?php if ($btOn): ?>
    <div class="bt-note mx-auto" data-bt-status hidden></div>
    <script type="application/json" id="btPrinterOptions"><?= json_encode(Receipt::btOptions(), JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <script type="application/json" id="btReceipt"><?= json_encode($btOps, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <?php endif; ?>
    <div class="receipt">
      <div class="text-center"><?= Logo::img('doc.pos_receipt', 'receipt-logo') ?><strong><?= h($s['company_name']) ?></strong><br><?= h($s['company_address']) ?><br>VAT REG TIN <?= h($s['company_tin']) ?><br>
        <?= h($term['name'] ?? '') ?></div>
      <hr class="my-1">
      <div class="text-center fw-bold"><?= h($receiptTitle) ?></div>
      <div>No: <?= h($t['receipt_no'] ?: $t['txn_no']) ?><br>Date: <?= h(fdatetime($t['created_at'])) ?>
        <?php if ($customerName !== ''): ?><br>Customer: <?= h($customerName) ?><?php endif; ?>
        <br>Cashier: <?= h($cashier) ?></div>
      <?php if ((int)$t['is_voided'] === 1): ?><div class="text-center fw-bold">*** VOID ***</div><?php endif; ?>
      <hr class="my-1">
      <table class="w-100">
      <?php foreach ($lines as $l): ?>
        <tr><td colspan="2"><?= h($l['description']) ?></td></tr>
        <tr><td>&nbsp; <?= line_qty($l) ?> x <?= line_rate($l, false) ?><?= line_discount($l) !== '' ? ' less ' . line_discount($l) : '' ?></td><td class="text-end"><?= money($l['amount']) ?></td></tr>
      <?php endforeach; ?>
      </table>
      <hr class="my-1">
      <table class="w-100">
        <?php if ($t['txn_type'] !== 'payment'): ?>
        <tr><td>VATable sales</td><td class="text-end"><?= money($t['subtotal']) ?></td></tr>
        <tr><td>VAT</td><td class="text-end"><?= money($t['tax_total']) ?></td></tr>
        <?php endif; ?>
        <tr class="fw-bold"><td>TOTAL</td><td class="text-end"><?= money($t['total']) ?></td></tr>
        <?php foreach ($tenders as $tn): ?><tr><td><?= h($tn['name']) ?> <?= h($tn['reference']) ?></td><td class="text-end"><?= money($tn['amount']) ?></td></tr><?php endforeach; ?>
        <?php if ((float)$t['amount_tendered'] > 0): ?><tr><td>Amount tendered</td><td class="text-end"><?= money($t['amount_tendered']) ?></td></tr><tr><td>Change</td><td class="text-end"><?= money($t['change_given']) ?></td></tr><?php endif; ?>
      </table>
      <hr class="my-1">
      <div class="text-center"><?= implode('<br>', array_map('h', $footerLines)) ?></div>
    </div>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ helpers
$cartRows = function (array $cart): array {
    $rows = [];
    foreach ($cart as $c) {
        $iid = (int)($c['item_id'] ?? 0);
        $it = $iid ? item_cache($iid) : null;
        if (!$it || !in_array($it['item_type'], ['inventory', 'assembly', 'non_inventory', 'service', 'other_charge', 'group'], true)) {
            continue;
        }
        if ($it['item_type'] === 'group' && !ItemGroups::ready()) {
            continue;
        }
        $qtyv = to_num($c['qty'] ?? 0);
        if ($qtyv <= 0) {
            continue;
        }
        $rows[] = ['item_id' => $iid, 'qty' => $qtyv, 'rate' => to_num($c['price'] ?? $it['sales_price']),
            'uom_id' => !empty($c['uom']) ? (int)$c['uom'] : null,
            'discount_kind' => ($c['dk'] ?? 'pct') === 'amt' ? 'amt' : 'pct',
            'discount_val' => to_num($c['disc'] ?? 0),
            'tax_code_id' => $it['item_type'] === 'group' ? ItemGroups::taxCode($it) : $it['tax_code_id'], 'description' => $it['name']];
    }
    return $rows;
};

// ------------------------------------------------------------------ actions
if (is_post()) {
    try {
        if (!$terminal || !$shift) {
            fail('Open a shift for this terminal first.');
        }
        Access::require($pageKey, 'create');
        $do = p('do');
        $cart = json_decode((string)p('cart', '[]'), true) ?: [];
        $customerId = pint('customer_id');
        $recallId = pint('recall_id');
        $recall = $recallId ? Txn::load($recallId) : null;
        if ($recall && ($recall['status'] !== 'suspended' || (int)$recall['terminal_id'] !== (int)$terminal['id'])) {
            $recall = null;
        }
        $siteId = (int)$terminal['site_id'];
        $base = [
            'txn_date' => today(), 'site_id' => $siteId, 'terminal_id' => (int)$terminal['id'], 'pos_shift_id' => (int)$shift['id'],
            'customer_id' => $customerId,
            // a walk-in customer can still be named on the receipt
            'payee_name' => $customerId ? null : (mb_substr(trim((string)p('walkin_name')), 0, 120) ?: null),
        ];
        if (Classes::ready()) {
            $base['class_id'] = array_key_exists('class_id', $_POST)
                ? Classes::posted()
                : (Pos::defaults()['class_id'] ?: ($terminal['class_id'] ?? null));
        }

        if ($do === 'payment') {
            // customer paying an existing balance at the counter
            $cid = pint('pay_customer_id');
            $amt = r2(pnum('pay_amount'));
            $pm = pint('pay_method_id');
            if (!$cid || $amt <= 0 || !$pm) {
                fail('Choose the customer, amount and payment method.');
            }
            $method = DB::row('SELECT * FROM payment_methods WHERE id = :id', ['id' => $pm]);
            // cash goes to the drawer; a card or e-wallet payment to the clearing account of its method, or to the module default
            // Card Clearing / E-wallet Clearing like a POS sale paid that way (v28: it went to Undeposited Funds); others to Undeposited Funds
            $acct = $method['method_type'] === 'cash' ? (int)$terminal['cash_account_id'] : (int)($method['clearing_account_id']
                ?: (in_array($method['method_type'], ['card', 'ewallet'], true) ? Accts::get($method['method_type'] === 'ewallet' ? 'ewallet_clearing' : 'card_clearing') : Accts::get('undeposited_funds')));
            $apps = [];
            $left = $amt;
            foreach (Balances::openReceivables($cid) as $d) {
                if ($left <= 0.004) {
                    break;
                }
                $use = min($left, $d['open_balance']);
                $apps[] = ['target_txn_id' => $d['id'], 'amount' => $use];
                $left -= $use;
            }
            // array_merge, not "+": the customer paying is the one chosen on this small form, not the sale's customer
            $id = Txn::save('payment', array_merge($base, [
                'customer_id' => $cid, 'account_id' => $acct, 'account2_id' => Accts::get('ar'), 'payment_method_id' => $pm,
                'total' => $amt, 'ref_no' => p('pay_ref') ?: null, 'memo' => 'Payment at ' . $terminal['name'], 'receipt_no' => Pos::nextReceipt((int)$terminal['id']),
                'payee_name' => null,
            ]), [], null, ['keep_totals' => true, 'applications' => $apps]);
            flash('success', 'Payment of ' . money($amt) . ' recorded.');
            redirect(url($pageKey, ['a' => 'receipt', 'id' => $id]));
        }

        if ($do === 'cancel_suspended') {
            if ($recall) {
                Txn::delete((int)$recall['id'], 'Suspended sale cancelled at the counter');
                flash('info', 'Suspended sale cancelled.');
            }
            redirect(url($pageKey));
        }

        $rows = $cartRows($cart);
        if (!$rows) {
            fail('The cart is empty.');
        }
        $lines = parse_item_lines($rows, 'sales', ['customer_id' => $customerId]);
        foreach ($lines as &$l) {
            $l['site_id'] = $siteId;
        }
        unset($l);
        $need = price_checks($lines, $customerId);
        $approver = Approval::check($pageKey, $need !== '', $need);
        if ($need !== '' && $approver) {
            foreach ($lines as $l) {
                if ((float)$l['discount_pct'] > 0) {
                    Audit::log('pos_discount', 'pos', 'item', $l['item_id'], $l['description'], [
                        'reason' => ($l['discount_kind'] === 'amt' ? money($l['discount_amount']) . ' off' : pct($l['discount_pct']) . ' discount') . ' / ' . $need,
                        'approved_by' => $approver['username'], 'amount' => $l['discount_amount'],
                        'terminal_id' => $terminal['id'], 'site_id' => $siteId,
                    ]);
                }
            }
        }
        $total = 0.0;
        foreach ($lines as $l) {
            $total += $l['amount'] + $l['tax_amount'];
        }
        $total = r2($total);
        $extra = ['approved_by' => $approver['username'] ?? null];
        $hApprover = $approver ? ['approved_by' => $approver['id']] : [];

        if ($do === 'suspend') {
            $id = Txn::save('pos_sale', $base + $hApprover + ['status' => 'suspended', 'memo' => p('memo') ?: 'Suspended sale'], $lines, $recall ? (int)$recall['id'] : null, $extra + ['audit_action' => 'suspend']);
            flash('info', 'Sale suspended. Recall it from the list when the customer is ready.');
            redirect(url($pageKey));
        }

        if ($do === 'charge') {
            if (!$customerId) {
                fail('Choose the account customer to charge.');
            }
            $cust = DB::row('SELECT * FROM customers WHERE id = :id', ['id' => $customerId]);
            if (!(int)$cust['allow_pos_charge']) {
                fail($cust['name'] . ' is not an approved account customer for charges at the counter.');
            }
            $limit = (float)$cust['credit_limit'];
            if ($limit > 0 && Balances::customerBalance($customerId) + $total > $limit + 0.004 && !Access::special('credit_limit_override')) {
                fail('This charge would exceed the customer\'s credit limit of ' . money($limit) . '.');
            }
            $id = Txn::save('invoice', $base + $hApprover + [
                'account_id' => Accts::get('ar'), 'terms_id' => $cust['terms_id'],
                'due_date' => Txn::dueDate(today(), $cust['terms_id'] ? (int)$cust['terms_id'] : null),
                'billing_address' => $cust['billing_address'], 'sales_rep_id' => $cust['sales_rep_id'],
                'memo' => 'Charge sale at ' . $terminal['name'], 'receipt_no' => Pos::nextReceipt((int)$terminal['id']),
            ], $lines, null, $extra);
            if ($recall) {
                Txn::delete((int)$recall['id'], 'Suspended sale completed as a charge invoice');
            }
            $_SESSION['pos_keep'] = ['terminal_id' => (int)$terminal['id'], 'customer_id' => $customerId ?: null, 'class_id' => $base['class_id'] ?? null];
            flash('success', 'Charged to ' . $cust['name'] . '.');
            redirect(url($pageKey, ['a' => 'receipt', 'id' => $id]));
        }

        if ($do === 'complete') {
            $tenders = json_decode((string)p('tenders', '[]'), true) ?: [];
            $methods = DB::all('SELECT * FROM payment_methods WHERE is_active = 1');
            $mById = [];
            foreach ($methods as $m) {
                $mById[(int)$m['id']] = $m;
            }
            $cashTendered = 0.0;
            $nonCash = 0.0;
            $rowsT = [];
            $cashMethod = null;
            foreach ($tenders as $tn) {
                $mid = (int)($tn['method_id'] ?? 0);
                $amt = r2(to_num($tn['amount'] ?? 0));
                if (!isset($mById[$mid]) || $amt <= 0) {
                    continue;
                }
                if ($mById[$mid]['method_type'] === 'cash') {
                    $cashTendered += $amt;
                    $cashMethod = $mid;
                } else {
                    $nonCash += $amt;
                    $rowsT[] = ['payment_method_id' => $mid, 'amount' => $amt, 'reference' => mb_substr((string)($tn['ref'] ?? ''), 0, 60) ?: null];
                }
            }
            if ($nonCash > $total + 0.004) {
                fail('Card, bank, e-wallet and voucher amounts are more than the total.');
            }
            $cashNeeded = r2($total - $nonCash);
            if ($cashTendered + 0.004 < $cashNeeded) {
                fail('Payment is short by ' . money($cashNeeded - $cashTendered) . '.');
            }
            if ($cashNeeded > 0) {
                $rowsT[] = ['payment_method_id' => $cashMethod, 'amount' => $cashNeeded, 'reference' => null];
            }
            $change = r2($cashTendered - $cashNeeded);
            $receiptNo = $recall['receipt_no'] ?? null ?: Pos::nextReceipt((int)$terminal['id']);
            $id = DB::tx(function () use ($base, $hApprover, $lines, $recall, $extra, $rowsT, $cashTendered, $nonCash, $change, $receiptNo) {
                // tenders must exist before posting, so create the sale as suspended first, then complete it
                $sid = Txn::save('pos_sale', $base + $hApprover + ['status' => 'suspended', 'receipt_no' => $receiptNo], $lines, $recall ? (int)$recall['id'] : null, $extra + ['audit_action' => $recall ? 'recall' : 'start']);
                DB::run('DELETE FROM pos_tenders WHERE txn_id = :t', ['t' => $sid]);
                foreach ($rowsT as $r) {
                    DB::insert('pos_tenders', $r + ['txn_id' => $sid]);
                }
                return Txn::save('pos_sale', $base + $hApprover + [
                    'status' => 'completed', 'receipt_no' => $receiptNo, 'amount_tendered' => r2($cashTendered + $nonCash), 'change_given' => $change,
                    'payment_method_id' => $rowsT[0]['payment_method_id'] ?? null, 'memo' => p('memo') ?: null,
                ], $lines, $sid, $extra + ['audit_action' => 'sale']);
            });
            // the next sale starts blank, except for the customer and the class, which stay as they were
            $_SESSION['pos_keep'] = ['terminal_id' => (int)$terminal['id'], 'customer_id' => $customerId ?: null, 'class_id' => $base['class_id'] ?? null];
            flash('success', 'Sale completed. Change: ' . money($change));
            redirect(url($pageKey, ['a' => 'receipt', 'id' => $id]));
        }
        fail('Unknown action.');
    } catch (UserError $e) {
        if (DB::inTransaction()) {
            DB::rollBack();
        }
        Approval::release();
        $GLOBALS['FORM_ERROR'] = $e->getMessage();
        $GLOBALS['FORM_STOCK_QUESTION'] = $e instanceof StockQuestion ? $e->short : null;
        if ($e instanceof StockQuestion) {
            Approval::keepForAnswer();
        }
    }
}

// ------------------------------------------------------------------ screen
$terminals = Pos::terminals();
$posSites = Pos::sites();
// The site and the counter are both shown, and both start on what this cashier was given in
// System Administration > Company, Security & Closing Date > Default site & class for POS transactions.
$siteBox = count($posSites) > 1
    ? '<form method="get" class="d-flex gap-1"><input type="hidden" name="p" value="' . h($pageKey) . '">'
        . '<label class="col-form-label col-form-label-sm text-muted pe-1" for="posSite">Site</label>'
        . '<select class="form-select form-select-sm" id="posSite" name="site" data-autosubmit title="The site this counter sells from">'
        . options($posSites, $terminal['site_id'] ?? null) . '</select></form>'
    : ($terminal ? '<span class="pos-site-name" title="The site this counter sells from">Site: <strong>' . h($terminal['site_name']) . '</strong></span>' : '');
layout_start('POS Register', ['actions' => $siteBox
    . ' ' . ($terminals ? '<form method="get" class="d-flex gap-1"><input type="hidden" name="p" value="' . h($pageKey) . '"><select class="form-select form-select-sm" name="terminal" data-autosubmit>'
    . options(array_map(fn($t) => $t['code'] . ' - ' . $t['name'], $terminals), $terminal['id'] ?? null) . '</select></form>' : '')
    . ' ' . page_link('pos.shifts', [], 'Shift & readings', 'btn-outline-secondary', 'clock')
    . ' ' . page_link('pos.receipts', [], 'Receipts / returns', 'btn-outline-secondary', 'receipt')]);

if (!$terminal) {
    echo '<div class="alert alert-warning">No POS terminal is assigned to you. Ask an administrator to set up a terminal and assign it to your account.</div>';
    layout_end();
    return;
}
if (!$shift) {
    echo '<div class="alert alert-info">No shift is open on ' . h($terminal['name']) . '. ' . (Access::can('pos.shifts', 'create') ? '<a class="btn btn-sm btn-primary ms-2" href="' . h(url('pos.shifts')) . '">Open a shift</a>' : '') . '</div>';
    layout_end();
    return;
}

$siteId = (int)$terminal['site_id'];
$items = DB::all("SELECT i.id, i.code, i.barcode, i.name, i.sales_price, i.item_type, i.tax_code_id, COALESCE(t.rate,0) AS tax_rate,
                         COALESCE(s.qty_on_hand,0) AS on_hand
                    FROM items i LEFT JOIN tax_codes t ON t.id = i.tax_code_id
                    LEFT JOIN item_site_stock s ON s.item_id = i.id AND s.site_id = :s
                   WHERE i.is_active = 1 AND i.item_type IN ('inventory','assembly','non_inventory','service','other_charge'" . (ItemGroups::ready() ? ",'group'" : '') . ") ORDER BY i.code", ['s' => $siteId]);
$itemUnits = Uom::unitsByItem(array_map(fn($r) => (int)$r['id'], $items));
$taxRates = Lookup::taxRates();
$adoptGroup = ItemGroups::adopt();
$itemsJs = array_map(function ($r) use ($itemUnits, $taxRates, $adoptGroup) {
    $row = ['id' => (int)$r['id'], 'code' => $r['code'], 'barcode' => $r['barcode'], 'name' => $r['name'], 'label' => item_label($r['code'], $r['name']), 'price' => (float)$r['sales_price'],
        'tax' => (float)$r['tax_rate'], 'stock' => in_array($r['item_type'], ['inventory', 'assembly'], true) ? (float)$r['on_hand'] : null,
        'units' => count($itemUnits[(int)$r['id']] ?? []) > 1 ? $itemUnits[(int)$r['id']] : []];
    if ($r['item_type'] === 'group') {
        // an item group: sold at its group price (or, with "Adopt the Group Price" off or no group price, at the prices of its items)
        $row['group'] = true;
        $row['members'] = array_map(fn($m) => [(int)$m['id'], (float)$m['member_qty']], ItemGroups::members((int)$r['id']));
        $row['own'] = $adoptGroup && (float)$r['sales_price'] > 0;
        $row['price'] = ItemGroups::price((int)$r['id'], null);
        $tc = ItemGroups::taxCode($r);
        $row['tax'] = (float)($taxRates[$tc] ?? 0);
        $row['units'] = [];
    }
    return $row;
}, $items);
$methods = DB::all('SELECT * FROM payment_methods WHERE is_active = 1 ORDER BY id');
// payment options offered on the register: Chart of Accounts > Terms, Tax Codes & Other Lists > Payment methods > "POS payment option"
$posMethods = array_values(array_filter($methods, fn($m) => array_key_exists('show_in_pos', $m)
    ? (int)$m['show_in_pos'] === 1
    : !in_array($m['method_type'], ['check', 'bank_transfer'], true)));

// price levels: the customer chosen on the sale decides the prices, exactly as on invoices (Lookup::priceFor)
$levels = [];
foreach (DB::all('SELECT id, name, adjust_pct FROM price_levels') as $pl) {
    $levels[(int)$pl['id']] = ['name' => $pl['name'], 'pct' => (float)$pl['adjust_pct'], 'custom' => new stdClass()];
}
foreach (DB::all('SELECT price_level_id, item_id, custom_price FROM price_level_items') as $pli) {
    if (isset($levels[(int)$pli['price_level_id']])) {
        $levels[(int)$pli['price_level_id']]['custom']->{(int)$pli['item_id']} = (float)$pli['custom_price'];
    }
}
$customerLevels = array_map('intval', DB::pairs('SELECT id, price_level_id FROM customers WHERE price_level_id IS NOT NULL'));
$suspended = DB::all("SELECT t.*, u.username FROM transactions t JOIN users u ON u.id = t.created_by WHERE t.txn_type = 'pos_sale' AND t.status = 'suspended' AND t.terminal_id = :t ORDER BY t.id DESC", ['t' => $terminal['id']]);
$recallId = gint('recall') ?: pint('recall_id');
$initialCart = [];
if ($recallId && ($r = Txn::load($recallId)) && $r['status'] === 'suspended') {
    foreach ((ItemGroups::ready() ? ItemGroups::collapse(Txn::lines($recallId)) : Txn::lines($recallId)) as $l) {
        if (!empty($l['_group'])) {
            $initialCart[] = ['item_id' => (int)$l['item_id'], 'qty' => (float)$l['group_qty'], 'price' => (float)$l['group_rate'], 'uom' => null,
                'dk' => 'amt', 'disc' => (float)$l['discount_amount']];
            continue;
        }
        if (empty($l['item_id'])) {
            continue;
        }
        $initialCart[] = ['item_id' => (int)$l['item_id'],
            'qty' => (float)$l['uom_qty'] != 0.0 ? (float)$l['uom_qty'] : (float)$l['qty'],
            'price' => (float)$l['uom_rate'] != 0.0 ? (float)$l['uom_rate'] : (float)$l['rate'],
            'uom' => $l['uom_id'] ? (int)$l['uom_id'] : null, 'dk' => $l['discount_kind'] ?: 'pct',
            'disc' => $l['discount_kind'] === 'amt' ? (float)$l['discount_amount'] : (float)$l['discount_pct']];
    }
    $initialCustomer = $r['customer_id'];
    $initialWalkin = (string)($r['payee_name'] ?? '');
    $initialClass = $r['class_id'] ?? null;
} else {
    $recallId = null;
    // customer and class of the last completed sale on this terminal stay selected for the next one
    $keep = $_SESSION['pos_keep'] ?? null;
    if (is_array($keep) && (int)($keep['terminal_id'] ?? 0) === (int)$terminal['id']) {
        $initialCustomer = $keep['customer_id'] ?? null;
        $keptClass = true;
        $initialClass = $keep['class_id'] ?? null;
    }
}
// The cashier's own default class starts every new sale, ahead of the class of the last sale and of the
// counter's own. A recalled suspended sale keeps the class it was suspended with.
$posDefaults = Pos::defaults();
if (!$recallId && $posDefaults['class_id']) {
    $initialClass = $posDefaults['class_id'];
    $keptClass = true;
}
$initialTenders = [];
if (is_post()) {
    $initialCart = json_decode((string)p('cart', '[]'), true) ?: $initialCart;
    // a sale that came back (a question, or a payment that was short) keeps the amounts already keyed in
    $initialTenders = array_values(array_filter((array)(json_decode((string)p('tenders', '[]'), true) ?: []), 'is_array'));
}
$negativeAllowed = Settings::get('allow_negative_stock') === '1';
$accountCustomers = DB::pairs('SELECT id, name FROM customers WHERE is_active = 1 AND allow_pos_charge = 1 ORDER BY name');
$maxDisc = Access::limit('max_discount_pct');
?>
<div class="row g-3">
  <div class="col-xl-8">
    <div class="card mb-3"><div class="card-body">
      <div class="pos-entry mb-2">
        <div class="pos-entry-qty"><label class="form-label" for="scanQty">Quantity</label>
          <input class="form-control form-control-lg text-end" id="scanQty" value="1" inputmode="decimal" autocomplete="off" autofocus title="Quantity, then Enter"></div>
        <div class="pos-entry-item"><label class="form-label" for="scan">Item code / name</label>
          <input class="form-control form-control-lg" id="scan" placeholder="Scan the barcode, or type any part of the item name or code"
                 autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false"
                 role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="scanList"></div>
        <div class="scan-list" id="scanList" role="listbox" hidden></div>
      </div>
      <div class="small text-muted"><?= h($terminal['name']) ?>, <?= h($terminal['site_name']) ?>, shift opened <?= h(fdatetime($shift['opened_at'])) ?> by <?= h($shift['full_name']) ?>
        <?= $maxDisc !== null ? ', your discount limit ' . h(pct($maxDisc)) : '' ?></div>
    </div></div>
    <div class="card"><div class="pos-cart"><table class="table table-sm mb-0" id="cartTable">
      <thead><tr><th class="c-item">Item</th><th class="num c-qty">Qty</th><th class="c-uom">U/M</th><th class="num c-price">Price</th><th class="num c-disc">Discount</th><th class="num c-amt">Amount</th><th class="c-rm"></th></tr></thead>
      <tbody></tbody>
    </table></div>
    <div class="card-body py-2 small text-muted" id="cartEmpty">Cart is empty.</div></div>
    <?php if ($suspended): ?>
    <div class="card mt-3"><div class="card-header">Suspended sales</div><table class="table table-sm mb-0">
      <?php foreach ($suspended as $sp): ?><tr><td><?= h(fdatetime($sp['created_at'])) ?></td><td><?= h($sp['username']) ?></td><td><?= h(entity_name($sp)) ?></td><td class="num"><?= money($sp['total']) ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= h(url($pageKey, ['recall' => $sp['id']])) ?>">Recall</a></td></tr><?php endforeach; ?>
    </table></div>
    <?php endif; ?>
  </div>
  <div class="col-xl-4">
    <form method="post" id="posForm" class="card">
      <?= csrf_field() ?><?= negative_stock_fields() ?>
      <input type="hidden" name="cart" id="cartField">
      <input type="hidden" name="tenders" id="tenderField">
      <input type="hidden" name="do" id="doField" value="<?= h(is_post() && p('do') === 'charge' ? 'charge' : 'complete') ?>">
      <input type="hidden" name="recall_id" value="<?= h($recallId) ?>">
      <div class="card-body">
        <?php if ($recallId): ?><div class="alert alert-info py-1 small">Recalled suspended sale #<?= (int)$recallId ?></div><?php endif; ?>
        <label class="form-label">Customer</label>
        <select class="form-select form-select-sm" name="customer_id" id="posCustomer"><?= options(Lookup::customers(false), $initialCustomer ?? p('customer_id'), 'Walk-in customer') ?></select>
        <div class="small text-muted mt-1 mb-2" id="posLevel"></div>
        <div id="walkinBox" class="mb-2"><input class="form-control form-control-sm" name="walkin_name" maxlength="120" value="<?= h(is_post() ? p('walkin_name') : ($initialWalkin ?? '')) ?>" placeholder="Customer name on the receipt (optional)"></div>
        <?php if (Classes::ready()): ?>
        <div class="row g-1 align-items-center mb-2"><label class="col-3 col-form-label col-form-label-sm text-muted" for="posClass">Class</label>
          <div class="col-9"><select class="form-select form-select-sm" name="class_id" id="posClass"><?= options(Classes::options(), is_post() ? p('class_id') : (!empty($keptClass) ? $initialClass : ($initialClass ?? ($terminal['class_id'] ?? null))), Classes::inUse() ? '' : 'No classes yet') ?></select></div></div>
        <?php endif; ?>
        <table class="table table-sm mb-2">
          <tr><td>Subtotal (net of VAT)</td><td class="num" id="tSub">0.00</td></tr>
          <tr><td>Discounts</td><td class="num" id="tDisc">0.00</td></tr>
          <tr><td>VAT</td><td class="num" id="tTax">0.00</td></tr>
        </table>
        <div class="d-flex justify-content-between align-items-center mb-2"><span class="fs-5">TOTAL</span><span class="pos-total" id="tTotal">0.00</span></div>
        <div id="tenderRows">
          <?php foreach ($posMethods as $m): ?>
            <div class="input-group input-group-sm mb-1">
              <span class="input-group-text tender-name" title="<?= h($m['name']) ?>"><?= h($m['name']) ?></span>
              <input class="form-control text-end tender-amt" data-method="<?= (int)$m['id'] ?>" data-type="<?= h($m['method_type']) ?>" placeholder="0.00">
              <?php if ($m['method_type'] !== 'cash'): ?><input class="form-control tender-ref" data-method="<?= (int)$m['id'] ?>" placeholder="Ref / approval no."><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="d-flex justify-content-between mt-2"><span>Change</span><strong id="tChange">0.00</strong></div>
        <div class="d-flex justify-content-between"><span>Balance due</span><strong id="tDue" class="text-danger">0.00</strong></div>
        <input class="form-control form-control-sm mt-2" name="memo" placeholder="Note (optional)">
        <?= Approval::fields() ?>
        <div class="d-grid gap-2 mt-3">
          <button class="btn btn-success btn-lg" type="submit" data-do="complete"><i class="bi bi-check2-circle"></i> Complete sale</button>
          <div class="btn-group">
            <button class="btn btn-outline-secondary" type="submit" data-do="suspend"><i class="bi bi-pause-circle"></i> Suspend</button>
            <?php if ($accountCustomers): ?><button class="btn btn-outline-primary" type="submit" data-do="charge" title="Approved account customers only"><i class="bi bi-person-badge"></i> Charge to account</button><?php endif; ?>
            <button class="btn btn-outline-danger" type="button" id="clearCart"><i class="bi bi-x-circle"></i> Clear</button>
          </div>
          <?php if ($recallId): ?><button class="btn btn-sm btn-outline-danger" type="submit" data-do="cancel_suspended">Cancel suspended sale</button><?php endif; ?>
        </div>
      </div>
    </form>
    <form method="post" class="card mt-3">
      <?= csrf_field() ?><input type="hidden" name="do" value="payment">
      <div class="card-header small fw-semibold">Customer paying an account balance</div>
      <div class="card-body row g-2">
        <div class="col-12"><select class="form-select form-select-sm" name="pay_customer_id" required><?= options(Lookup::customers(false), null, 'Customer…') ?></select></div>
        <div class="col-6"><input class="form-control form-control-sm text-end" name="pay_amount" placeholder="Amount" required></div>
        <div class="col-6"><select class="form-select form-select-sm" name="pay_method_id"><?= options(array_column($methods, 'name', 'id'), null) ?></select></div>
        <div class="col-8"><input class="form-control form-control-sm" name="pay_ref" placeholder="Reference"></div>
        <div class="col-4"><button class="btn btn-sm btn-outline-success w-100">Receive</button></div>
      </div>
    </form>
  </div>
</div>
<script>
(function () {
  const items = <?= json_encode($itemsJs) ?>;
  const levels = <?= json_encode((object)$levels) ?>;                 // price level id -> {name, pct, custom: {item id: price}}
  const customerLevels = <?= json_encode((object)$customerLevels) ?>; // customer id -> price level id
  const custSel = document.getElementById('posCustomer');
  const r4 = (v) => Math.round((v + Number.EPSILON) * 10000) / 10000;
  const levelOf = () => levels[customerLevels[custSel ? custSel.value : ''] || ''] || null;
  // price of one base unit for the customer on the sale: a custom price of the level, else the level's percentage
  const priceOf = (it) => {
    const lv = levelOf();
    // an item group without its own price: the items in it at the customer's prices
    if (it.group && !it.own) {
      let sum = 0;
      for (const [mid, q] of (it.members || [])) { const m = itemOf(mid); if (!m) return it.price; sum += r2(q * priceOf(m)); }
      return r2(sum);
    }
    if (!lv) return it.price;
    const custom = lv.custom ? lv.custom[it.id] : undefined;
    return custom !== undefined ? Number(custom) : r2(it.price * (1 + lv.pct / 100));
  };
  const byCode = {}; items.forEach(i => { byCode[i.code.toLowerCase()] = i; if (i.barcode) byCode[i.barcode] = i; if (i.label) byCode[i.label.toLowerCase()] = i; });
  let cart = <?= json_encode(array_values($initialCart)) ?>;
  const initialTenders = <?= json_encode($initialTenders) ?>;
  const negativeAllowed = <?= $negativeAllowed ? 'true' : 'false' ?>;
  const tbody = document.querySelector('#cartTable tbody');
  const {num, r2, fmt} = AppFmt;
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (ch) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[ch]));
  const find = (q) => {
    q = q.trim(); if (!q) return null;
    if (byCode[q] || byCode[q.toLowerCase()]) return byCode[q] || byCode[q.toLowerCase()];
    const low = q.toLowerCase();
    // any part of the name or the code, in any order: "pork ch" finds PORK CHOP
    const words = low.split(/\s+/).filter(Boolean);
    const hay = (i) => (i.label || (i.code + ' ' + i.name)).toLowerCase();
    let hits = items.filter(i => words.every(w => hay(i).indexOf(w) >= 0));
    if (hits.length > 1) {
      const starts = hits.filter(i => hay(i).indexOf(low) === 0);
      if (starts.length === 1) return starts[0];
    }
    return hits.length === 1 ? hits[0] : null;
  };
  const itemOf = (id) => items.find(i => i.id === id);

  // ---------------------------------------------------------------- the list that drops down under the box
  // A phone browser shows nothing at all for the list of choices a plain <input list="..."> carries, so the
  // matches are drawn here instead. They appear as soon as the box is tapped, narrow with every letter,
  // and one tap on a line puts that item in the cart with the quantity already keyed in.
  // entry order: quantity first, then the item code / name
  const scan = document.getElementById('scan');
  const scanQty = document.getElementById('scanQty');
  const backToQty = () => { scan.value = ''; hideSug(); scanQty.value = '1'; scanQty.focus(); scanQty.select(); };
  const sugBox = document.getElementById('scanList');
  let sug = [], sugHi = -1, lastPick = 0;
  const hay = (i) => (i.label || (i.code + ' ' + i.name)).toLowerCase();

  function matches(q) {
    const low = q.trim().toLowerCase();
    if (!low) return items.slice(0, 60);
    const words = low.split(/\s+/).filter(Boolean);
    const hit = items.filter(i => words.every(w => hay(i).indexOf(w) >= 0)
      || (i.barcode && String(i.barcode).toLowerCase().indexOf(low) >= 0));
    // whatever begins with what was typed is offered first
    return hit.filter(i => hay(i).indexOf(low) === 0).concat(hit.filter(i => hay(i).indexOf(low) !== 0)).slice(0, 60);
  }

  // the part of the text that was typed is picked out, so it is clear why a line is on the list
  function markUp(text, words) {
    if (!words.length) return esc(text);
    const low = String(text).toLowerCase();
    const hits = [];
    words.forEach(w => { const at = low.indexOf(w); if (at >= 0) hits.push([at, at + w.length]); });
    hits.sort((a, b) => a[0] - b[0]);
    let out = '', i = 0;
    hits.forEach(([a, b]) => { if (a < i) return; out += esc(text.slice(i, a)) + '<mark>' + esc(text.slice(a, b)) + '</mark>'; i = b; });
    return out + esc(text.slice(i));
  }

  function renderSug() {
    const q = scan.value.trim();
    const words = q ? q.toLowerCase().split(/\s+/).filter(Boolean) : [];
    sug = matches(q);
    sugHi = q && sug.length ? 0 : -1;
    if (!sug.length) {
      sugBox.innerHTML = '<div class="scan-none">Nothing matches "' + esc(q) + '"</div>';
    } else {
      sugBox.innerHTML = sug.map((it, i) =>
        '<div class="scan-opt" role="option" data-i="' + i + '">'
        + '<span class="scan-text"><span class="scan-name">' + markUp(it.name, words) + '</span>'
        + '<span class="scan-sub">' + markUp(it.code, words) + (it.stock !== null ? ', stock ' + esc(it.stock) : '') + '</span></span>'
        + '<span class="scan-price">' + fmt(priceOf(it)) + '</span></div>').join('');
    }
    paintSug();
    if (!sugBox.hidden) { placeSug(); }
  }

  function paintSug() {
    sugBox.querySelectorAll('.scan-opt').forEach(el => el.classList.toggle('is-hi', +el.dataset.i === sugHi));
    const el = sugBox.querySelector('.scan-opt.is-hi');
    if (el) {
      const top = el.offsetTop, bot = top + el.offsetHeight;
      if (top < sugBox.scrollTop) sugBox.scrollTop = top;
      else if (bot > sugBox.scrollTop + sugBox.clientHeight) sugBox.scrollTop = bot - sugBox.clientHeight;
    }
  }

  // The list is put against the part of the window the on-screen keyboard is NOT covering. With the phone
  // on its side the keyboard takes most of the screen, and a list drawn in the ordinary way ends up behind
  // it; visualViewport says where the visible band is, so the list is placed and sized to fit inside it.
  function placeSug() {
    const vv = window.visualViewport;
    const seenTop = vv ? vv.offsetTop : 0;
    const seenBottom = seenTop + (vv ? vv.height : window.innerHeight);
    const r = scan.getBoundingClientRect();
    const row = scan.closest('.pos-entry').getBoundingClientRect();
    const gap = 4, edge = 6;
    // the box itself can be behind the keyboard; the list is hung off the edge of the visible band then
    const from = Math.min(Math.max(r.top, seenTop), seenBottom);
    const to = Math.min(Math.max(r.bottom, seenTop), seenBottom);
    const below = seenBottom - to - gap - edge;
    const above = from - seenTop - gap - edge;
    const under = below >= 90 || below >= above;
    sugBox.style.left = Math.round(row.left) + 'px';
    sugBox.style.width = Math.round(row.width) + 'px';
    sugBox.style.maxHeight = Math.round(Math.max(72, Math.min(340, under ? below : above))) + 'px';
    if (under) {
      sugBox.style.top = Math.round(to + gap) + 'px';
      sugBox.style.bottom = 'auto';
    } else {
      sugBox.style.bottom = Math.round(window.innerHeight - from + gap) + 'px';
      sugBox.style.top = 'auto';
    }
  }

  function showSug() {
    renderSug();
    sugBox.hidden = false;
    scan.setAttribute('aria-expanded', 'true');
    placeSug();
  }
  function hideSug() { sugBox.hidden = true; sugHi = -1; scan.setAttribute('aria-expanded', 'false'); }

  // The keyboard opening or closing, the page scrolling, the phone being turned: put the list back where
  // it belongs. When the keyboard has just taken half the screen, the entry row is pulled to the top of
  // what is left first, so the whole of the remaining space goes to the products instead of a thin strip.
  const replace = (makeRoom) => {
    if (sugBox.hidden) { return; }
    const vv = window.visualViewport;
    if (makeRoom && vv && vv.height < window.innerHeight - 80) {
      document.body.classList.add('entry-focus');
      try { scan.closest('.pos-entry').scrollIntoView({ block: 'start' }); } catch (e) { /* older browser */ }
    }
    placeSug();
  };
  window.addEventListener('resize', () => replace(true));
  window.addEventListener('scroll', () => replace(false), true);
  window.addEventListener('orientationchange', () => setTimeout(() => replace(true), 250));
  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', () => replace(true));
    window.visualViewport.addEventListener('scroll', () => replace(false));
  }

  // one tap sells the item; a stray second event from the same tap is ignored
  function pickSug(i) {
    if (Date.now() - lastPick < 400) return;
    lastPick = Date.now();
    const it = sug[i];
    if (!it) return;
    hideSug();
    scan.classList.remove('is-invalid');
    add(it, num(scanQty.value) || 1);
    backToQty();
  }

  sugBox.addEventListener('mousedown', (e) => {
    const el = e.target.closest('.scan-opt');
    if (!el) return;
    e.preventDefault();          // the box keeps the focus, so the keyboard stays where it is
    pickSug(+el.dataset.i);
  });
  sugBox.addEventListener('click', (e) => {
    const el = e.target.closest('.scan-opt');
    if (el) pickSug(+el.dataset.i);
  });
  function render() {
    tbody.innerHTML = '';
    let sub = 0, tax = 0, disc = 0;
    cart.forEach((c, idx) => {
      const it = itemOf(c.item_id); if (!it) return;
      const gross = r2(c.qty * c.price);
      const amt = c.dk === 'amt' ? r2(gross - Math.min(Math.abs(c.disc || 0), gross)) : r2(gross * (1 - (c.disc || 0) / 100));
      sub += amt; disc += gross - amt; tax += r2(amt * it.tax / 100);
      const units = it.units || [];
      const uomCell = units.length
        ? `<select class="form-select form-select-sm" data-k="uom">${units.map(u => `<option value="${u.id}" data-factor="${u.factor}"${String(u.id) === String(c.uom) ? ' selected' : ''}>${esc(u.code)}</option>`).join('')}</select>`
        : '';
      const tr = document.createElement('tr');
      tr.innerHTML = `<td><div class="fw-semibold">${esc(it.name)}</div><div class="small text-muted">${esc(it.code)}${it.stock !== null ? ', stock ' + it.stock : ''}</div></td>
        <td><input class="form-control form-control-sm text-end" data-k="qty" value="${c.qty}"></td>
        <td>${uomCell}</td>
        <td><input class="form-control form-control-sm text-end" data-k="price" value="${c.price}"></td>
        <td><div class="input-group input-group-sm flex-nowrap">
          <input class="form-control form-control-sm text-end" data-k="disc" value="${c.disc || ''}">
          <select class="form-select form-select-sm flex-grow-0 w-auto" data-k="dk" title="Percent of the line, or an amount off">
            <option value="pct"${c.dk !== 'amt' ? ' selected' : ''}>%</option>
            <option value="amt"${c.dk === 'amt' ? ' selected' : ''}>Amt</option></select></div></td>
        <td class="num">${fmt(amt)}</td>
        <td><button type="button" class="btn btn-sm btn-link text-danger p-0" title="Remove"><i class="bi bi-x-lg"></i></button></td>`;
      tr.querySelectorAll('input').forEach(inp => inp.addEventListener('change', () => {
        c[inp.dataset.k] = num(inp.value);
        if (inp.dataset.k === 'price') c.auto = false;   // a typed price stays as typed when the customer changes
        if (c.qty <= 0) cart.splice(idx, 1);
        render();
      }));
      const dkSel = tr.querySelector('[data-k="dk"]');
      if (dkSel) dkSel.addEventListener('change', () => { c.dk = dkSel.value; render(); });
      const uomSel = tr.querySelector('[data-k="uom"]');
      if (uomSel) uomSel.addEventListener('change', () => {
        // the price follows the unit: it is always the base price times the units it contains
        const f = num(uomSel.options[uomSel.selectedIndex].dataset.factor) || 1;
        c.uom = +uomSel.value;
        c.price = r4(priceOf(it) * f);
        c.auto = true;
        render();
      });
      tr.querySelector('button').addEventListener('click', () => { cart.splice(idx, 1); render(); });
      tbody.appendChild(tr);
    });
    document.getElementById('cartEmpty').style.display = cart.length ? 'none' : '';
    // the unit column earns its place only when a line in the cart is sold by the unit; on a phone held
    // upright the space it would take goes to the quantity and the price instead
    document.getElementById('cartTable').classList.toggle('has-uom', cart.some((c) => {
      const it = itemOf(c.item_id);
      return !!(it && (it.units || []).length);
    }));
    const total = r2(sub + tax);
    document.getElementById('tSub').textContent = fmt(sub);
    document.getElementById('tDisc').textContent = fmt(disc);
    document.getElementById('tTax').textContent = fmt(tax);
    document.getElementById('tTotal').textContent = fmt(total);
    let paid = 0, cash = 0, nonCash = 0;
    document.querySelectorAll('.tender-amt').forEach(i => { const v = num(i.value); paid += v; if (i.dataset.type === 'cash') cash += v; else nonCash += v; });
    const cashNeeded = Math.max(0, total - nonCash);
    document.getElementById('tChange').textContent = fmt(Math.max(0, cash - cashNeeded));
    document.getElementById('tDue').textContent = fmt(Math.max(0, total - paid));
    window.posTotal = total;
  }
  function add(it, q) {
    const units = it.units || [];
    const def = units.filter(u => u.sd)[0] || units.filter(u => u.base)[0] || null;
    const factor = def ? num(def.factor) || 1 : 1;
    const price = r4(priceOf(it) * factor);
    const ex = cart.find(c => c.item_id === it.id && !c.disc && c.price === price && String(c.uom || '') === String(def ? def.id : ''));
    if (ex) ex.qty = r2(ex.qty + q);
    else cart.push({item_id: it.id, qty: q, price: price, disc: 0, dk: 'pct', uom: def ? def.id : null, auto: true});
    render();
  }
  // units in the line's unit of measure
  const factorOf = (it, c) => { const u = (it.units || []).find(x => String(x.id) === String(c.uom)); return u ? (num(u.factor) || 1) : 1; };
  // customer on the sale: shows the price level and re-prices the lines that still carry a list price
  function applyCustomer(reprice) {
    const lv = levelOf();
    const box = document.getElementById('posLevel');
    if (box) box.textContent = lv ? 'Price level: ' + lv.name + (lv.pct ? ' (' + (lv.pct > 0 ? '+' : '') + lv.pct + '% on items without a custom price)' : '') : '';
    const walk = document.getElementById('walkinBox');
    if (walk) walk.style.display = custSel && custSel.value ? 'none' : '';
    if (reprice) {
      cart.forEach(c => { const it = itemOf(c.item_id); if (it && c.auto !== false) { c.price = r4(priceOf(it) * factorOf(it, c)); c.auto = true; } });
      render();
    }
  }
  if (custSel) custSel.addEventListener('change', () => applyCustomer(true));
  scanQty.addEventListener('focus', () => scanQty.select());
  scanQty.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const v = scanQty.value.trim();
    // a barcode scanned while the cursor is still on the quantity: sell one of it
    const scanned = v !== '' && (!/^\d*\.?\d+$/.test(v.replace(/,/g, '')) || v.length >= 8) ? (byCode[v] || byCode[v.toLowerCase()] || null) : null;
    if (scanned) { add(scanned, 1); backToQty(); return; }
    if (!(num(v) > 0)) { scanQty.classList.add('is-invalid'); scanQty.select(); return; }
    scanQty.classList.remove('is-invalid');
    scan.focus();
  });
  scan.addEventListener('focus', () => {
    // On a short screen (the phone on its side) the entry row is brought to the top of the page first and
    // the top bar is let go, so the whole of the space the keyboard leaves is there for the list of products.
    if (window.innerHeight < 560) {
      document.body.classList.add('entry-focus');
      try { scan.closest('.pos-entry').scrollIntoView({ block: 'start' }); } catch (e) { /* older browser */ }
    }
    showSug();
    setTimeout(() => replace(true), 300);   // again once the keyboard has finished coming up
  });
  scan.addEventListener('click', () => { if (sugBox.hidden) showSug(); });
  scan.addEventListener('input', () => { scan.classList.remove('is-invalid'); showSug(); });
  scan.addEventListener('blur', () => setTimeout(() => { hideSug(); document.body.classList.remove('entry-focus'); }, 150));
  scan.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (sugBox.hidden) { showSug(); return; }
      if (!sug.length) return;
      sugHi = e.key === 'ArrowDown' ? Math.min(sug.length - 1, sugHi + 1) : Math.max(0, sugHi - 1);
      paintSug();
      return;
    }
    if (e.key === 'Escape') { if (!sugBox.hidden) { e.preventDefault(); hideSug(); } return; }
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const raw = scan.value.trim();
    // a barcode or a code typed in full wins; otherwise the line highlighted on the list, then a single match
    const it = byCode[raw] || byCode[raw.toLowerCase()]
      || (!sugBox.hidden && sugHi >= 0 ? sug[sugHi] : null)
      || find(scan.value);
    if (!it) { scan.classList.add('is-invalid'); return; }
    hideSug();
    scan.classList.remove('is-invalid');
    add(it, num(scanQty.value) || 1);
    backToQty();
  });
  if (document.activeElement === scanQty) scanQty.select();
  document.querySelectorAll('.tender-amt').forEach(i => i.addEventListener('input', render));
  // clicking into an empty cash box fills in the total, selected, so typing the amount handed over replaces it
  document.querySelectorAll('.tender-amt[data-type=cash]').forEach(i => {
    i.addEventListener('focus', () => {
      if (i.value) return;
      i.value = (window.posTotal || 0).toFixed(2);
      render();
      i.select();
      i.dataset.keepSelection = '1';
    });
    i.addEventListener('mouseup', (e) => { if (i.dataset.keepSelection) { e.preventDefault(); delete i.dataset.keepSelection; } });
    i.addEventListener('keydown', () => { delete i.dataset.keepSelection; });
  });
  document.getElementById('clearCart').addEventListener('click', () => { if (confirm('Clear the cart?')) { cart = []; render(); } });
  const form = document.getElementById('posForm');
  form.querySelectorAll('button[data-do]').forEach(b => b.addEventListener('click', () => { document.getElementById('doField').value = b.dataset.do; }));
  // Complete sale / Charge to account: selling more than the site has asks first (Yes saves, No goes back to the sale).
  // When selling below zero is switched off the server refuses the sale instead.
  const shortItems = () => {
    const want = {};
    cart.forEach((c) => { const it = itemOf(c.item_id); if (it && it.stock !== null) want[it.id] = (want[it.id] || 0) + num(c.qty) * factorOf(it, c); });
    return Object.keys(want).map((id) => itemOf(+id)).filter((it) => want[it.id] > num(it.stock) + 0.00001)
      .map((it) => ({ code: it.code, name: it.name, before: fmtQty(num(it.stock)), after: fmtQty(r4(num(it.stock) - want[it.id])) }));
  };
  const fmtQty = (v) => (Math.abs(v - Math.round(v)) < 1e-9 ? String(Math.round(v)) : String(r4(v))).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  form.addEventListener('submit', (e) => {
    const doing = document.getElementById('doField').value;
    const answered = form.elements.confirm_negative && form.elements.confirm_negative.value === '1';
    if (negativeAllowed && !answered && (doing === 'complete' || doing === 'charge') && window.AppNegStock) {
      const short = shortItems();
      if (short.length) {
        e.preventDefault();
        e.stopImmediatePropagation();
        window.AppNegStock.ask(short, () => window.AppNegStock.resend(form, null));
        return;
      }
    }
    document.getElementById('cartField').value = JSON.stringify(cart);
    const t = [];
    document.querySelectorAll('.tender-amt').forEach(i => {
      const ref = document.querySelector('.tender-ref[data-method="' + i.dataset.method + '"]');
      if (num(i.value) > 0) t.push({method_id: +i.dataset.method, amount: num(i.value), ref: ref ? ref.value : ''});
    });
    document.getElementById('tenderField').value = JSON.stringify(t);
  });
  // a recalled or re-shown cart: a line is "list priced" when its price is the one the customer's level gives
  cart.forEach(c => { const it = itemOf(c.item_id); if (it && c.auto === undefined) c.auto = Math.abs(num(c.price) - r4(priceOf(it) * factorOf(it, c))) < 0.00005; });
  // the payment keyed in before the sale came back
  initialTenders.forEach((t) => {
    const a = document.querySelector('.tender-amt[data-method="' + (+t.method_id) + '"]');
    if (a && num(t.amount) > 0) a.value = num(t.amount).toFixed(2);
    const ref = document.querySelector('.tender-ref[data-method="' + (+t.method_id) + '"]');
    if (ref && t.ref) ref.value = t.ref;
  });
  applyCustomer(false);
  render();
})();
</script>
<?php
layout_end();
