<?php
declare(strict_types=1);

/**
 * Generic document page: estimates, sales orders, invoices, sales receipts,
 * statement charges, credit memos (+ refunds), purchase orders, bills (+ vendor credits)
 * and checks.
 */
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/_grids.php';
require_once __DIR__ . '/_memorize.php';

$type = $PARAMS['type'];
$pageKey = $PAGE['key'];
$ownedTypes = [$type];
if ($type === 'bill' && g('credit')) {
    // vendor credits moved to their own page: Vendors > Supplier Debit Memos
    redirect(url('vend.debit_memos', ['a' => 'new'] + (gint('vendor_id') ? ['vendor_id' => gint('vendor_id')] : [])));
}
if ($type === 'credit_memo') {
    $ownedTypes = ['credit_memo', 'refund'];
} elseif ($type === 'check') {
    $ownedTypes = ['check'];
}

if (txn_common_actions($pageKey, $ownedTypes)) {
    return;
}

$a = g('a', 'list');
$isSalesDoc = in_array($type, ['estimate', 'sales_order', 'invoice', 'sales_receipt', 'statement_charge', 'credit_memo'], true);

// ---------------------------------------------------------------- refund of a credit memo / overpayment
if ($a === 'refund') {
    Access::require($pageKey, 'create');
    $src = Txn::load((int)g('id'));
    if (!$src || !in_array($src['txn_type'], ['credit_memo', 'payment'], true) || (int)$src['is_voided'] === 1) {
        flash('warning', 'Choose a credit memo or payment with an unapplied credit.');
        redirect(url($pageKey));
    }
    $remaining = Balances::creditRemaining($src);
    handle_post(function () use ($src, $remaining, $pageKey) {
        $amount = r2(pnum('amount'));
        if ($amount <= 0 || $amount > $remaining + 0.004) {
            fail('The refund must be more than zero and no more than the available credit (' . money($remaining) . ').');
        }
        $acct = pint('account_id');
        if (!$acct) {
            fail('Choose the account the refund is paid from.');
        }
        $approver = Approval::check($pageKey, Approval::exceeds(Access::limit('max_amount'), $amount), 'refund above your amount limit');
        $id = Txn::save('refund', [
            'txn_date' => pdate('txn_date', today()),
            'customer_id' => $src['customer_id'],
            'payee_name' => entity_name($src),
            'account_id' => $acct,
            'payment_method_id' => pint('payment_method_id'),
            'total' => $amount,
            'source_txn_id' => $src['id'],
            'memo' => p('memo') ?: 'Refund of ' . Txn::ref($src),
            'to_be_printed' => p('to_be_printed') === '1' ? 1 : 0,
            'approved_by' => $approver['id'] ?? null,
        ], [], null, ['keep_totals' => true, 'approved_by' => $approver['username'] ?? null]);
        flash('success', 'Refund recorded.');
        redirect(url($pageKey, ['a' => 'view', 'id' => $id]));
    });
    layout_start('Refund ' . Txn::ref($src));
    ?>
    <form method="post" class="card" style="max-width:720px"><div class="card-body">
      <?= csrf_field() ?>
      <p>Customer: <strong><?= h(entity_name($src)) ?></strong>, Available credit: <strong><?= money($remaining) ?></strong></p>
      <div class="row g-2">
        <?= input_field('txn_date', 'Date', fv('txn_date', null, today()), ['type' => 'date', 'class' => 'col-md-4']) ?>
        <?= select_field('account_id', 'Pay from account', Accts::ofType(['Bank']), fv('account_id', null, ''), ['blank' => '', 'required' => true]) ?>
        <?= select_field('payment_method_id', 'Refund method', Lookup::paymentMethods(), fv('payment_method_id', null, ''), ['blank' => '']) ?>
        <?= input_field('amount', 'Amount', fv('amount', null, number_format($remaining, 2, '.', '')), ['class' => 'col-md-4']) ?>
        <?= input_field('memo', 'Memo', fv('memo'), ['class' => 'col-md-8']) ?>
        <?= Classes::field(fv('class_id', null, $src['class_id'] ?? ''), 'col-md-4') ?>
        <?= check_field('to_be_printed', 'Queue a refund check for printing', fv('to_be_printed', null, 0)) ?>
      </div>
      <?= Approval::fields() ?>
      <div class="mt-3"><button class="btn btn-primary">Record refund</button> <a class="btn btn-outline-secondary" href="<?= h(Txn::url($src)) ?>">Cancel</a></div>
    </div></form>
    <?php
    layout_end();
    return;
}

// ---------------------------------------------------------------- supplier debit memo: apply to outstanding bills
if ($a === 'apply' && $type === 'vendor_credit') {
    require __DIR__ . '/../vendors/_debit_memo_apply.php';
    return;
}

// ---------------------------------------------------------------- list
if ($a === 'list') {
    $titles = [
        'estimate' => 'Estimates', 'sales_order' => 'Sales Orders', 'invoice' => 'Invoices', 'sales_receipt' => 'Sales Receipts',
        'statement_charge' => 'Statement Charges', 'credit_memo' => 'Credit Memos and Refunds', 'purchase_order' => 'Purchase Orders',
        'bill' => 'Bills', 'check' => 'Checks', 'vendor_credit' => 'Supplier Debit Memos',
    ];
    $statuses = null;
    if ($type === 'estimate') {
        $statuses = ['pending' => 'Pending', 'accepted' => 'Accepted', 'closed' => 'Closed'];
    } elseif (in_array($type, ['sales_order', 'purchase_order'], true)) {
        $statuses = ['open' => 'Open', 'partial' => 'Partially filled', 'closed' => 'Closed', 'void' => 'Void'];
    } elseif ($type === 'check') {
        $statuses = ['to_print' => 'To be printed', 'void' => 'Void'];
    } elseif ($type === 'invoice' || $type === 'bill') {
        $statuses = ['unpaid' => 'Unpaid', 'open' => 'Open', 'partial' => 'Partially paid', 'paid' => 'Paid', 'pending' => 'Pending', 'void' => 'Void'];
    }
    $opt = ['statuses' => $statuses ?? ['open' => 'Open', 'closed' => 'Closed', 'paid' => 'Paid', 'pending' => 'Pending', 'void' => 'Void']];
    if ($type === 'bill') {
        $opt['new_label'] = 'Enter bill';
        $opt['actions'] = page_link('vend.debit_memos', [], 'Supplier debit memos', 'btn-outline-primary', 'arrow-return-left');
    }
    if ($type === 'vendor_credit') {
        $opt['new_label'] = 'New debit memo';
        $opt['statuses'] = ['open' => 'With available amount', 'closed' => 'Fully applied', 'void' => 'Void'];
        $opt['actions'] = page_link('vend.pay_bills', [], 'Pay bills', 'btn-outline-secondary', 'cash-stack');
    }
    if ($type === 'check' && Access::can($pageKey, 'print')) {
        $opt['actions'] = btn_link(url($pageKey, ['status' => 'to_print']), 'Checks to print', 'btn-outline-primary', 'printer');
    }
    render_txn_list($pageKey, $ownedTypes, $titles[$type] ?? Txn::label($type), $opt);
    return;
}

// ---------------------------------------------------------------- new / edit
if (!in_array($a, ['new', 'edit'], true)) {
    redirect(url($pageKey));
}
$t = null;
$lines = [];
if ($a === 'edit') {
    Access::require($pageKey, 'edit');
    $t = Txn::load((int)g('id'));
    if (!$t || !in_array($t['txn_type'], $ownedTypes, true)) {
        flash('warning', 'Transaction not found.');
        redirect(url($pageKey));
    }
    if ((int)$t['is_voided'] === 1) {
        flash('warning', 'A voided transaction cannot be edited.');
        redirect(Txn::url($t));
    }
    if ($t['txn_type'] === 'refund') {
        flash('info', 'Refunds cannot be edited. Void it and record a new one.');
        redirect(Txn::url($t));
    }
    $lines = Txn::lines((int)$t['id']);
} else {
    Access::require($pageKey, 'create');
}
$docType = $t['txn_type'] ?? $type;

// prefill from a source document (estimate / sales order)
$source = null;
if (!$t && ($fromId = gint('from'))) {
    $source = Txn::load($fromId);
    if ($source && in_array($source['txn_type'], ['estimate', 'sales_order'], true) && (int)$source['is_voided'] === 0) {
        $pctProgress = min(100.0, max(0.0, to_num(g('pct', '100'))));
        $srcLines = Txn::lines($fromId);
        // what is left of each item group of the source: the share of its first item still to invoice
        $groupLeft = [];
        foreach ($srcLines as $sl) {
            $ref = (int)($sl['group_ref'] ?? 0);
            if ($ref && ($sl['group_role'] ?? '') === 'member' && !isset($groupLeft[$ref]) && (float)$sl['qty'] != 0.0) {
                $groupLeft[$ref] = $source['txn_type'] === 'estimate' ? $pctProgress / 100
                    : max(0.0, ((float)$sl['qty'] - (float)$sl['qty_fulfilled']) / (float)$sl['qty']);
            }
        }
        foreach ($srcLines as $sl) {
            $gref = (int)($sl['group_ref'] ?? 0);
            if ($gref && ($sl['group_role'] ?? '') === 'variance') {
                // the price variance of a group comes across in the same share as its items
                $share = $groupLeft[$gref] ?? 0.0;
                if ($share <= 0) {
                    continue;
                }
                $vAmount = r2((float)$sl['amount'] * $share);
                $lines[] = [
                    'line_kind' => 'item', 'item_id' => null, 'account_id' => $sl['account_id'], 'description' => $sl['description'],
                    'qty' => 0, 'rate' => 0, 'discount_pct' => 0, 'discount_kind' => 'amt', 'discount_amount' => r2((float)$sl['discount_amount'] * $share), 'amount' => $vAmount,
                    'tax_code_id' => $sl['tax_code_id'], 'tax_amount' => $sl['tax_code_id'] ? r2($vAmount * tax_rate((int)$sl['tax_code_id']) / 100) : 0.0,
                    'site_id' => $sl['site_id'], 'source_line_id' => null,
                    'group_item_id' => $sl['group_item_id'], 'group_ref' => $gref, 'group_qty' => round((float)$sl['group_qty'] * $share, 4), 'group_rate' => $sl['group_rate'], 'group_role' => 'variance',
                ];
                continue;
            }
            if ((int)$sl['is_closed'] === 1) {
                continue;
            }
            $remaining = (float)$sl['qty'] - (float)$sl['qty_fulfilled'];
            if ($source['txn_type'] === 'estimate') {
                $remaining = (float)$sl['qty'] * $pctProgress / 100;
                if (abs((float)$sl['amount']) > 0 && abs((float)$sl['amount_fulfilled']) >= abs((float)$sl['amount']) - 0.005) {
                    continue;
                }
            }
            if ($sl['item_id'] && in_array($sl['item_type'], ['inventory', 'assembly', 'non_inventory', 'service', 'other_charge'], true) && $remaining <= 0) {
                continue;
            }
            $amount = in_array($sl['item_type'], ['discount', 'subtotal', 'payment'], true)
                ? (float)$sl['amount']
                : r2($remaining * (float)$sl['rate'] * (1 - (float)$sl['discount_pct'] / 100));
            $lines[] = [
                'line_kind' => 'item',
                'item_id' => $sl['item_id'], 'account_id' => $sl['account_id'], 'description' => $sl['description'],
                'qty' => in_array($sl['item_type'], ['discount', 'subtotal', 'payment'], true) ? 0 : $remaining,
                'rate' => $sl['rate'], 'discount_pct' => $sl['discount_pct'],
                'amount' => $amount,
                'tax_code_id' => $sl['tax_code_id'],
                // the tax of the source document comes across with the line, so the new form shows the
                // same tax and total the estimate or the sales order showed
                'tax_amount' => $sl['tax_code_id'] ? r2($amount * tax_rate((int)$sl['tax_code_id']) / 100) : 0.0,
                'site_id' => $sl['site_id'], 'source_line_id' => $sl['id'],
            ];
            if ($gref && ($sl['group_role'] ?? '') === 'member') {
                $lines[array_key_last($lines)] += ['group_item_id' => $sl['group_item_id'], 'group_ref' => $gref,
                    'group_qty' => round((float)$sl['group_qty'] * ($groupLeft[$gref] ?? 1.0), 4), 'group_rate' => $sl['group_rate'], 'group_role' => 'member'];
            }
        }
    } else {
        $source = null;
    }
}

// a new form filled from a memorized transaction ("Load Memorized Transaction")
$mem = (!$t && !$source && !is_post() && gint('mem')) ? Memorized::forForm((int)gint('mem'), [$docType]) : null;
if ($mem) {
    $lines = $mem['lines'];
}
$rec = $t ?: ($mem['header'] ?? null);      // what the boxes of the form show when nothing was posted

$customerId = $t['customer_id'] ?? ($source['customer_id'] ?? ($mem['header']['customer_id'] ?? gint('customer_id')));
$vendorId = $t['vendor_id'] ?? ($mem['header']['vendor_id'] ?? gint('vendor_id'));
$defaultSite = array_key_first(Lookup::sites());

handle_post(function () use ($t, $docType, $pageKey, $isSalesDoc, $type) {
    $id = $t ? (int)$t['id'] : null;
    $h = [
        'txn_date' => pdate('txn_date'),
        'ref_no'   => p('ref_no') ?: null,
        'memo'     => p('memo') ?: null,
    ];
    if (Classes::ready()) {
        $h['class_id'] = Classes::posted();
    }
    if (!$t && p('txn_no') !== '') {
        $h['txn_no'] = mb_substr((string)p('txn_no'), 0, 30);
    } elseif ($t && p('txn_no') !== '') {
        $h['txn_no'] = mb_substr((string)p('txn_no'), 0, 30);
    }
    $opt = [];
    $approver = null;

    if ($isSalesDoc) {
        $cust = pint('customer_id');
        if (!$cust) {
            fail('Choose a customer.');
        }
        $h['customer_id'] = $cust;
        $h['customer_message'] = p('customer_message') ?: null;
        $h['billing_address'] = p('billing_address') ?: null;
        $h['shipping_address'] = p('shipping_address') ?: null;
        $h['sales_rep_id'] = pint('sales_rep_id');
        $h['site_id'] = pint('site_id');
        $h['terms_id'] = pint('terms_id');
        $h['due_date'] = pdate('due_date');
        if (in_array($docType, ['invoice', 'statement_charge'], true) && !$h['due_date']) {
            $h['due_date'] = Txn::dueDate($h['txn_date'] ?? today(), $h['terms_id']);
        }
        if ($docType === 'sales_order') {
            $h['ship_date'] = pdate('ship_date');
        }
        if (in_array($docType, ['invoice', 'sales_receipt'], true)) {
            $h['is_pending'] = p('is_pending') === '1' ? 1 : 0;
        }
        if ($docType === 'invoice') {
            $h['account_id'] = Accts::get('ar');
        }
        if ($docType === 'sales_receipt') {
            $h['payment_method_id'] = pint('payment_method_id');
            $h['account_id'] = pint('account_id') ?: Accts::get('undeposited_funds');
        }
        if ($docType === 'estimate') {
            $st = p('status');
            $h['status'] = in_array($st, ['pending', 'accepted', 'closed'], true) ? $st : 'pending';
        }
        if ($docType === 'credit_memo') {
            $h['account_id'] = Accts::get('ar');
            $h['restock'] = p('restock') === '1' ? 1 : 0;
            $h['site2_id'] = pint('site2_id');
        }
        $lines = parse_item_lines(grid_rows('lines'), 'sales', ['customer_id' => $cust]);
        // billable costs added to the invoice
        foreach ((array)($_POST['billable_add'] ?? []) as $srcLine) {
            $bl = DB::row('SELECT l.*, t.txn_no, v.name AS vendor FROM transaction_lines l JOIN transactions t ON t.id = l.txn_id LEFT JOIN vendors v ON v.id = t.vendor_id
                            WHERE l.id = :id AND l.is_billable = 1 AND l.billed_txn_id IS NULL AND t.is_voided = 0', ['id' => (int)$srcLine]);
            if ($bl) {
                $markup = to_num(p('billable_markup', '0'));
                $amt = r2((float)$bl['amount'] * (1 + $markup / 100));
                $lines[] = [
                    'line_kind' => 'item', 'item_id' => null, 'account_id' => Accts::get('reimbursable_income'),
                    'description' => 'Reimbursable: ' . ($bl['description'] ?: 'cost') . ' (' . ($bl['vendor'] ?: '') . ' ' . $bl['txn_no'] . ')',
                    'qty' => 0, 'rate' => 0, 'discount_pct' => 0, 'discount_amount' => 0, 'amount' => $amt,
                    'tax_code_id' => null, 'tax_amount' => 0, 'source_line_id' => (int)$bl['id'],
                ];
            }
        }
        if (!$lines) {
            fail('Add at least one line.');
        }
        if (in_array($docType, ['estimate', 'sales_order', 'invoice', 'sales_receipt'], true)) {
            $need = price_checks($lines, $cust);
            $approver = Approval::check($pageKey, $need !== '', $need);
        }
        if ($docType === 'invoice' && empty($h['is_pending'])) {
            $top = (int)(DB::value('SELECT COALESCE(parent_id, id) FROM customers WHERE id = :id', ['id' => $cust]));
            $limit = (float)DB::value('SELECT credit_limit FROM customers WHERE id = :id', ['id' => $top]);
            if ($limit > 0) {
                $total = 0.0;
                foreach ($lines as $l) {
                    $total += $l['amount'] + $l['tax_amount'];
                }
                $current = Balances::customerBalance($top) - ($t && (int)$t['is_pending'] === 0 ? (float)$t['total'] : 0);
                if ($current + $total > $limit + 0.004) {
                    $msg = 'Credit limit exceeded: balance ' . money($current) . ' + this invoice ' . money($total) . ' > limit ' . money($limit) . '.';
                    if (!Access::special('credit_limit_override')) {
                        fail($msg . ' You are not allowed to exceed customer credit limits.');
                    }
                    if (trim((string)p('override_reason')) === '') {
                        $GLOBALS['NEEDS_OVERRIDE'] = true;
                        fail($msg . ' Enter a reason to override the limit.');
                    }
                    $opt['reason'] = 'Credit limit override: ' . p('override_reason');
                    $opt['credit_override'] = $msg;
                }
            }
        }
    } else {
        // purchase documents and checks
        if ($docType === 'check') {
            $who = Names::parse((string)p('payee'));
            $h['vendor_id'] = $who['vendor_id'];
            $h['customer_id'] = $who['customer_id'];
            $h['other_name_id'] = $who['other_name_id'];
            $h['payee_name'] = p('payee_name') ?: null;
            if (!$h['vendor_id'] && !$h['customer_id'] && !$h['other_name_id'] && !$h['payee_name']) {
                fail('Choose a payee or type a payee name.');
            }
            $h['account_id'] = pint('account_id');
            if (!$h['account_id']) {
                fail('Choose the bank account.');
            }
            $h['to_be_printed'] = p('to_be_printed') === '1' ? 1 : 0;
            if (p('to_be_printed') === '1' && !$t) {
                $h['txn_no'] = $h['txn_no'] ?? null;
            }
        } else {
            $h['vendor_id'] = pint('vendor_id');
            if (!$h['vendor_id']) {
                fail('Choose a vendor.');
            }
        }
        $h['site_id'] = pint('site_id');
        if (in_array($docType, ['bill', 'vendor_credit'], true)) {
            $h['terms_id'] = pint('terms_id');
            $h['due_date'] = pdate('due_date') ?: Txn::dueDate($h['txn_date'] ?? today(), $h['terms_id']);
            $h['account_id'] = Accts::get('ap');
        }
        if ($docType === 'purchase_order') {
            $h['due_date'] = pdate('due_date');
            $h['shipping_address'] = p('shipping_address') ?: null;
        }
        $lines = [];
        if ($docType !== 'purchase_order') {
            $lines = parse_expense_lines(grid_rows('expenses'));
        }
        $lines = array_merge($lines, parse_item_lines(grid_rows('lines'), 'purchase'));
        if (!$lines) {
            fail('Add at least one expense or item line.');
        }
        if ($docType === 'check') {
            $total = 0.0;
            foreach ($lines as $l) {
                $total += $l['amount'] + $l['tax_amount'];
            }
            // accounts marked "With SL" keep a listing of names: the line's own name, or the payee of the check
            Accts::requireSl($lines, $h);
            $approver = Approval::check($pageKey, Approval::exceeds(Access::limit('max_amount'), $total), 'check above your amount limit (' . money($total) . ')');
        }
    }
    if ($approver) {
        $h['approved_by'] = $approver['id'];
        $opt['approved_by'] = $approver['username'];
    }
    if ($t && (int)$t['is_pending'] === 1 && isset($h['is_pending']) && (int)$h['is_pending'] === 0) {
        $opt['audit_action'] = 'mark_final';
    }

    $newId = Txn::save($docType, $h, $lines, $id, $opt);

    if (!empty($opt['credit_override'])) {
        Audit::log('credit_limit_override', 'cust', 'transaction', $newId, Txn::ref(Txn::load($newId)), ['reason' => $opt['reason'], 'page_key' => $pageKey]);
    }

    // attach a receipt from Receipt Management
    if ($rid = pint('receipt_document_id')) {
        DB::update('receipt_documents', ['linked_txn_id' => $newId, 'status' => 'created', 'reviewed_by' => Auth::id(), 'reviewed_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $rid]);
        Audit::log('receipt_linked', 'vend', 'receipt', $rid, 'Receipt #' . $rid, ['page_key' => 'vend.receipts']);
    }

    // credit memo: what to do with the credit
    if ($docType === 'credit_memo' && !$id) {
        $cm = Txn::load($newId);
        $disp = p('disposition', 'keep');
        DB::update('transactions', ['credit_disposition' => $disp], 'id = :id', ['id' => $newId]);
        if ($disp === 'apply' && ($inv = pint('apply_invoice_id'))) {
            $invoice = Txn::load($inv);
            if ($invoice && (int)$invoice['customer_id'] > 0) {
                $amt = min(Balances::open($invoice), Balances::creditRemaining($cm));
                if ($amt > 0) {
                    DB::insert('payment_applications', ['payment_txn_id' => $newId, 'target_txn_id' => $inv, 'amount' => r2($amt)]);
                    Balances::refreshApplications($newId, [$inv]);
                    Audit::log('apply_credit', 'cust', 'transaction', $newId, Txn::ref($cm), ['amount' => $amt, 'reason' => 'Applied to ' . Txn::ref($invoice), 'page_key' => $pageKey]);
                }
            }
        } elseif ($disp === 'refund') {
            flash('info', 'Now record how the refund was paid.');
            redirect(url($pageKey, ['a' => 'refund', 'id' => $newId]));
        }
    }

    // supplier debit memo: go straight to applying it to the supplier's outstanding bills when asked to
    if ($docType === 'vendor_credit' && p('dm_disposition') === 'apply' && !wants_new()) {
        flash('success', 'Supplier Debit Memo saved. Now choose the bills it is applied to.');
        redirect(url($pageKey, ['a' => 'apply', 'id' => $newId]));
    }

    flash('success', Txn::label($docType) . ' saved.');
    redirect_after_save(
        url($pageKey, ['a' => 'new']),
        url($pageKey, ['a' => 'view', 'id' => $newId])
    );
});

// posted lines take precedence when the form is re-shown after an error
$groupSwitch = '';
if (is_post()) {
    $lines = array_values(array_filter(grid_rows('lines'), fn($r) => !empty($r['item_id']) || !empty($r['account_id'])));
    $expenseLines = grid_rows('expenses');
} else {
    $expenseLines = array_values(array_filter($lines, fn($l) => $l['line_kind'] === 'expense'));
    $lines = array_values(array_filter($lines, fn($l) => $l['line_kind'] !== 'expense'));
    // item groups: one line per group (edited as the group), or the items in them (each edited on its own)
    if ($isSalesDoc && ItemGroups::ready() && ItemGroups::has($lines)) {
        $lineMode = ItemGroups::viewMode();
        $groupSwitch = ItemGroups::viewSwitch($lineMode);
        if ($lineMode === 'groups') {
            $lines = ItemGroups::collapse($lines);
        }
    }
}

$title = ($t ? 'Edit ' : 'New ') . Txn::label($docType) . ($t ? ' ' . $t['txn_no'] : '');
// Memorize (any form) and Load memorized (a new form)
[$memButton, $memBox] = in_array($docType, Memorized::TYPES, true)
    ? memorize_controls($docType, trim((string)Memorized::entityLabel($rec ?? []) . ' ' . Txn::label($docType)))
    : ['', ''];
[$loadButton, $loadList] = !$t && in_array($docType, Memorized::TYPES, true) ? memorized_loader([$docType], $pageKey) : ['', ''];
layout_start($title, ['actions' => $loadButton . ' ' . btn_link($t ? Txn::url($t) : url($pageKey), 'Cancel', 'btn-outline-secondary')]);
echo memorized_loaded_note($mem);

$custInfo = $customerId ? DB::row('SELECT * FROM customers WHERE id = :id', ['id' => $customerId]) : null;
$vendInfo = $vendorId ? DB::row('SELECT * FROM vendors WHERE id = :id', ['id' => $vendorId]) : null;
$receiptDoc = gint('receipt') ? DB::row('SELECT * FROM receipt_documents WHERE id = :id', ['id' => gint('receipt')]) : null;
if ($receiptDoc && !$t && !is_post()) {
    $vendorId = $receiptDoc['vendor_id'] ?: $vendorId;
    $vendInfo = $vendorId ? DB::row('SELECT * FROM vendors WHERE id = :id', ['id' => $vendorId]) : null;
    if ($receiptDoc['account_id'] || $receiptDoc['amount']) {
        $expenseLines = [['account_id' => $receiptDoc['account_id'], 'amount' => $receiptDoc['amount'], 'description' => $receiptDoc['memo'], 'tax_code_id' => null]];
    }
}
if ($source && !is_post()) {
    echo '<div class="alert alert-info py-2">Creating from ' . Txn::link($source, Txn::ref($source)) . (g('pct') ? ' — progress invoice for ' . h(g('pct')) . '%' : '') . '.</div>';
}
?>
<form method="post" id="txnForm">
<?= csrf_field() ?>
<?= in_array($docType, ['invoice', 'sales_receipt'], true) ? negative_stock_fields() : '' ?>
<?php if ($receiptDoc): ?><input type="hidden" name="receipt_document_id" value="<?= (int)$receiptDoc['id'] ?>"><?php endif; ?>
<div class="card mb-3"><div class="card-body">
<div class="row g-2">
<?php if ($isSalesDoc): ?>
  <?= select_field('customer_id', 'Customer:Job', Lookup::customers(), fv('customer_id', $rec, $customerId), ['blank' => '— choose —', 'required' => true, 'class' => 'col-md-4', 'extra' => 'id="customerSel" data-combo']) ?>
  <?= input_field('txn_date', 'Date', fv('txn_date', $rec, today()), ['type' => 'date', 'class' => 'col-md-2', 'required' => true]) ?>
  <?= input_field('txn_no', 'Number', fv('txn_no', $rec, ''), ['class' => 'col-md-2', 'extra' => 'placeholder="' . h($t ? '' : Seq::peek(Txn::TYPES[$docType][2]) . ' (auto)') . '"']) ?>
  <?php if (in_array($docType, ['invoice', 'sales_order'], true)): ?>
    <?= input_field('ref_no', 'Customer P.O. No.', fv('ref_no', $rec, $source['ref_no'] ?? ''), ['class' => 'col-md-2']) ?>
  <?php elseif ($docType === 'sales_receipt'): ?>
    <?= input_field('ref_no', 'Check / reference no.', fv('ref_no', $rec), ['class' => 'col-md-2']) ?>
  <?php else: ?>
    <?= input_field('ref_no', 'Reference', fv('ref_no', $rec), ['class' => 'col-md-2']) ?>
  <?php endif; ?>
  <?php if (in_array($docType, ['invoice', 'statement_charge'], true)): ?>
    <?php if ($docType === 'invoice'): ?><?= select_field('terms_id', 'Terms', Lookup::terms(), fv('terms_id', $rec, $custInfo['terms_id'] ?? ''), ['blank' => '', 'class' => 'col-md-2', 'extra' => 'id="termsSel"']) ?><?php endif; ?>
    <?= input_field('due_date', 'Due date (blank = from terms)', fv('due_date', $rec, ''), ['type' => 'date', 'class' => 'col-md-2']) ?>
  <?php endif; ?>
  <?php if ($docType === 'estimate'): ?>
    <?= input_field('due_date', 'Valid until', fv('due_date', $rec, date('Y-m-d', strtotime('+30 days'))), ['type' => 'date', 'class' => 'col-md-2']) ?>
    <?= select_field('status', 'Status', ['pending' => 'Pending', 'accepted' => 'Accepted', 'closed' => 'Closed'], fv('status', $rec, 'pending'), ['class' => 'col-md-2']) ?>
  <?php endif; ?>
  <?php if ($docType === 'sales_order'): ?>
    <?= input_field('ship_date', 'Expected ship date', fv('ship_date', $rec, ''), ['type' => 'date', 'class' => 'col-md-2']) ?>
  <?php endif; ?>
  <?php if ($docType !== 'statement_charge'): ?>
    <?= select_field('sales_rep_id', 'Sales rep', Lookup::reps(), fv('sales_rep_id', $rec, $source['sales_rep_id'] ?? ($custInfo['sales_rep_id'] ?? '')), ['blank' => '', 'class' => 'col-md-2', 'extra' => 'id="repSel"']) ?>
  <?php endif; ?>
  <?php if (in_array($docType, ['sales_order', 'invoice', 'sales_receipt', 'credit_memo'], true)): ?>
    <?= select_field('site_id', 'Inventory site', Lookup::sites(), fv('site_id', $rec, $source['site_id'] ?? $defaultSite), ['class' => 'col-md-2']) ?>
  <?php endif; ?>
  <?php if ($docType === 'sales_receipt'): ?>
    <?= select_field('payment_method_id', 'Payment method', Lookup::paymentMethods(), fv('payment_method_id', $rec, $custInfo['payment_method_id'] ?? ''), ['blank' => '', 'class' => 'col-md-2']) ?>
    <?= select_field('account_id', 'Deposit to', Accts::depositTo(), fv('account_id', $rec, Accts::get('undeposited_funds')), ['class' => 'col-md-3']) ?>
  <?php endif; ?>
  <?php if (in_array($docType, ['invoice', 'sales_receipt'], true)): ?>
    <?= check_field('is_pending', 'Pending (non-posting until marked final)', fv('is_pending', g('finalize') ? ['is_pending' => 0] : $t, 0), 'col-md-3') ?>
    <?php if (g('finalize')): ?><div class="col-md-3 small text-warning d-flex align-items-end">Pending is unticked — saving posts this sale.</div><?php endif; ?>
  <?php endif; ?>
  <?php if ($docType === 'credit_memo'): ?>
    <?= check_field('restock', 'Return goods to stock', fv('restock', $rec, 1), 'col-md-2') ?>
    <?= select_field('site2_id', 'Return to site', Lookup::sites(false), fv('site2_id', $rec, ''), ['blank' => 'Same as inventory site', 'class' => 'col-md-2']) ?>
  <?php endif; ?>
  <?php if ($docType !== 'statement_charge'): ?>
    <div class="col-md-6"><label class="form-label">Bill to</label><textarea class="form-control form-control-sm" rows="2" name="billing_address" id="billAddr"><?= h(fv('billing_address', $rec, $source['billing_address'] ?? ($custInfo['billing_address'] ?? ''))) ?></textarea></div>
    <div class="col-md-6"><label class="form-label">Ship to</label><textarea class="form-control form-control-sm" rows="2" name="shipping_address" id="shipAddr"><?= h(fv('shipping_address', $rec, $source['shipping_address'] ?? ($custInfo['shipping_address'] ?? ''))) ?></textarea></div>
  <?php endif; ?>
<?php else: ?>
  <?php if ($docType === 'check'):
      $payeeSel = $rec ? Names::code($rec) : ($vendorId ? 'v' . $vendorId : '');
      $payees = Names::options($payeeSel ?: null);
  ?>
    <?= select_field('account_id', 'Bank / credit card account', Accts::ofType(['Bank', 'Credit Card']), fv('account_id', $rec, g('cc') ? DB::value("SELECT id FROM accounts WHERE account_type = 'Credit Card' ORDER BY code LIMIT 1") : DB::value("SELECT id FROM accounts WHERE code = '1010'")), ['class' => 'col-md-3', 'required' => true]) ?>
    <?= select_field('payee', 'Pay to the order of', $payees, fv('payee', null, $payeeSel), ['blank' => '— other payee —', 'class' => 'col-md-3', 'extra' => 'data-combo']) ?>
    <?= input_field('payee_name', 'Other payee name', fv('payee_name', $rec), ['class' => 'col-md-2']) ?>
    <?= input_field('txn_date', 'Date', fv('txn_date', $rec, today()), ['type' => 'date', 'class' => 'col-md-2', 'required' => true]) ?>
    <?= input_field('txn_no', 'Check no.', fv('txn_no', $rec, ''), ['class' => 'col-md-2', 'extra' => 'placeholder="' . h($t ? '' : Seq::peek('check') . ' (auto)') . '"']) ?>
    <?= check_field('to_be_printed', 'Print later', fv('to_be_printed', $rec, 0), 'col-md-2') ?>
  <?php else: ?>
    <?= select_field('vendor_id', $docType === 'vendor_credit' ? 'Supplier' : 'Vendor', Lookup::vendors(), fv('vendor_id', $rec, $vendorId), ['blank' => '— choose —', 'required' => true, 'class' => 'col-md-4', 'extra' => 'data-combo']) ?>
    <?= input_field('txn_date', 'Date', fv('txn_date', $rec, $receiptDoc['receipt_date'] ?? today()), ['type' => 'date', 'class' => 'col-md-2', 'required' => true]) ?>
    <?= input_field('txn_no', 'Number', fv('txn_no', $rec, ''), ['class' => 'col-md-2', 'extra' => 'placeholder="' . h($t ? '' : Seq::peek(Txn::TYPES[$docType][2]) . ' (auto)') . '"']) ?>
    <?php if ($docType !== 'purchase_order'): ?>
      <?= input_field('ref_no', "Vendor's invoice / reference no.", fv('ref_no', $rec), ['class' => 'col-md-2']) ?>
    <?php endif; ?>
    <?php if (in_array($docType, ['bill', 'vendor_credit'], true)): ?>
      <?= select_field('terms_id', 'Terms', Lookup::terms(), fv('terms_id', $rec, $vendInfo['terms_id'] ?? ''), ['blank' => '', 'class' => 'col-md-2']) ?>
      <?= input_field('due_date', 'Due date (blank = from terms)', fv('due_date', $rec, ''), ['type' => 'date', 'class' => 'col-md-2']) ?>
    <?php endif; ?>
    <?php if ($docType === 'purchase_order'): ?>
      <?= input_field('due_date', 'Expected delivery', fv('due_date', $rec, date('Y-m-d', strtotime('+7 days'))), ['type' => 'date', 'class' => 'col-md-2']) ?>
      <div class="col-md-4"><label class="form-label">Ship to</label><input class="form-control form-control-sm" name="shipping_address" value="<?= h(fv('shipping_address', $rec, Settings::get('company_address'))) ?>"></div>
    <?php endif; ?>
  <?php endif; ?>
  <?= select_field('site_id', $docType === 'purchase_order' ? 'Receive at site' : 'Default site for items', Lookup::sites(), fv('site_id', $rec, $defaultSite), ['class' => 'col-md-2']) ?>
<?php endif; ?>
  <?= Classes::field(fv('class_id', $rec, $source['class_id'] ?? ''), 'col-md-2') ?>
  <div class="<?= Classes::ready() ? 'col-md-4' : 'col-md-6' ?>"><label class="form-label">Memo</label><input class="form-control form-control-sm" name="memo" value="<?= h(fv('memo', $rec, $source ? 'From ' . Txn::ref($source) : '')) ?>"></div>
  <?php if ($isSalesDoc): ?>
  <div class="col-md-6"><label class="form-label">Customer message</label><input class="form-control form-control-sm" name="customer_message" value="<?= h(fv('customer_message', $rec, 'Thank you for your business.')) ?>"></div>
  <?php endif; ?>
</div>
<?php if ($docType === 'vendor_credit'): ?><div class="small text-muted mt-2">A supplier debit memo reduces what you owe the supplier (purchase returns, price or billing corrections, allowances). It debits Accounts Payable and credits the accounts or items below, then it can be applied to the supplier's outstanding bills.</div><?php endif; ?>
</div></div>

<?php if (in_array($docType, ['bill', 'vendor_credit', 'check'], true)): ?>
<ul class="nav nav-tabs" role="tablist">
  <li class="nav-item"><button class="nav-link active" type="button" data-bs-toggle="tab" data-bs-target="#tabExp">Expenses</button></li>
  <li class="nav-item"><button class="nav-link" type="button" data-bs-toggle="tab" data-bs-target="#tabItems">Items</button></li>
</ul>
<div class="tab-content card card-body mb-3 border-top-0 rounded-0 rounded-bottom">
  <div class="tab-pane fade show active" id="tabExp"><?= expense_grid($expenseLines) ?></div>
  <div class="tab-pane fade" id="tabItems"><?= item_grid($lines, ['mode' => 'purchase', 'show_site' => true, 'show_job' => true, 'min_rows' => 2]) ?></div>
</div>
<?php elseif ($docType === 'purchase_order'): ?>
<div class="card card-body mb-3"><?= item_grid($lines, ['mode' => 'purchase', 'show_site' => false, 'show_job' => true, 'show_fulfilled' => $t ? 'Received' : '', 'show_closed' => (bool)$t]) ?></div>
<?php else: ?>
<?= $groupSwitch !== '' ? '<div class="mb-2">' . $groupSwitch . '</div>' : '' ?>
<div class="card card-body mb-3"><?= item_grid($lines, ['mode' => 'sales', 'show_disc' => $docType !== 'statement_charge', 'show_site' => false, 'show_fulfilled' => ($docType === 'sales_order' && $t) ? 'Invoiced' : '', 'show_closed' => $docType === 'sales_order' && $t, 'min_rows' => $docType === 'statement_charge' ? 1 : 3]) ?></div>
<?php endif; ?>

<?php
// billable costs for invoices
if ($docType === 'invoice' && $customerId):
    $ids = array_merge([(int)$customerId], array_map('intval', DB::column('SELECT id FROM customers WHERE parent_id = :c', ['c' => $customerId])));
    [$cin, $cp] = DB::in('bc', $ids);
    $billables = DB::all(
        "SELECT l.*, t.txn_no, t.txn_date, t.txn_type, v.name AS vendor FROM transaction_lines l JOIN transactions t ON t.id = l.txn_id
           LEFT JOIN vendors v ON v.id = t.vendor_id
          WHERE l.is_billable = 1 AND l.billed_txn_id IS NULL AND t.is_voided = 0 AND t.txn_type IN ('bill','check') AND l.customer_id IN $cin
          ORDER BY t.txn_date",
        $cp
    );
    if ($billables): ?>
<div class="card mb-3 border-info"><div class="card-header">Billable time and costs for this customer</div>
  <div class="card-body">
  <table class="table table-sm mb-2"><thead><tr><th>Add</th><th>Date</th><th>Source</th><th>Vendor</th><th>Description</th><th class="num">Amount</th></tr></thead>
  <?php foreach ($billables as $b): ?>
    <tr><td><input type="checkbox" class="form-check-input" name="billable_add[]" value="<?= (int)$b['id'] ?>"></td><td><?= h(fdate($b['txn_date'])) ?></td>
      <td><?= Txn::link((int)$b['txn_id'], Txn::label($b['txn_type']) . ' ' . $b['txn_no']) ?></td><td><?= h($b['vendor']) ?></td><td><?= h($b['description']) ?></td><td class="num"><?= money($b['amount']) ?></td></tr>
  <?php endforeach; ?>
  </table>
  <div class="row g-2"><div class="col-auto"><label class="form-label">Markup %</label><input class="form-control form-control-sm" name="billable_markup" value="0"></div></div>
  </div>
</div>
<?php endif; endif; ?>

<?php if ($docType === 'vendor_credit'):
    $openBills = $vendorId ? Balances::openPayables((int)$vendorId) : [];
    $openBillsTotal = array_sum(array_column($openBills, 'open_balance'));
?>
<div class="card mb-3"><div class="card-header">What to do with this debit memo</div><div class="card-body row g-2 align-items-end">
  <?= select_field('dm_disposition', 'After saving', ['apply' => 'Apply it to outstanding bills now', 'keep' => 'Keep it as an available credit'], fv('dm_disposition', null, $t ? 'keep' : 'apply'), ['class' => 'col-md-4']) ?>
  <div class="col-md-8 small text-muted"><?= $vendorId
      ? ($openBills ? 'This supplier has ' . count($openBills) . ' outstanding bill(s) totalling ' . money($openBillsTotal) . '.' : 'This supplier has no outstanding bills right now; the debit memo stays available and can be applied later, here or in Pay Bills.')
      : 'An available debit memo can be applied at any time from its own page, or in Pay Bills under Credits.' ?></div>
</div></div>
<?php endif; ?>

<?php if ($docType === 'credit_memo' && !$t):
    $openInv = $customerId ? Balances::openReceivables((int)$customerId) : [];
?>
<div class="card mb-3"><div class="card-header">What to do with this credit</div><div class="card-body row g-2">
  <?= select_field('disposition', 'Credit', ['keep' => 'Keep as available credit', 'apply' => 'Apply to an invoice now', 'refund' => 'Give a refund'], fv('disposition', null, 'keep'), ['class' => 'col-md-4', 'extra' => 'id="dispSel"']) ?>
  <div class="col-md-6" data-toggle-by="#dispSel" data-show-when="apply"><label class="form-label">Invoice</label>
    <select class="form-select form-select-sm" name="apply_invoice_id"><?= options(array_combine(array_column($openInv, 'id'), array_map(fn($i) => $i['txn_no'] . ', ' . fdate($i['txn_date']) . ', open ' . money($i['open_balance']), $openInv)) ?: [], fv('apply_invoice_id'), $customerId ? '' : 'Choose the customer first, then reload') ?></select></div>
</div></div>
<?php endif; ?>

<div class="row">
  <div class="col-md-6">
    <?php if (!empty($GLOBALS['NEEDS_OVERRIDE'])): ?>
      <div class="card border-warning mb-2"><div class="card-body"><label class="form-label">Credit limit override reason</label><input class="form-control form-control-sm" name="override_reason" required></div></div>
    <?php endif; ?>
    <?= in_array($docType, ['estimate', 'sales_order', 'invoice', 'sales_receipt', 'check'], true) ? Approval::fields() : '' ?>
  </div>
  <div class="col-md-6"><?= totals_box() ?></div>
</div>

<div class="sticky-actions d-flex flex-wrap gap-2">
  <?= save_buttons('Save', $t ? Txn::url($t) : url($pageKey), Access::can($pageKey, 'create')) ?>
  <?php if ($memButton): ?><div class="ms-auto"><?= $memButton ?></div><?php endif; ?>
</div>
<?= $memBox ?>
</form>
<?= $loadList ?>

<?php if ($isSalesDoc): ?>
<script>
document.getElementById('customerSel')?.addEventListener('change', function () {
  if (!this.value) return;
  fetch(<?= json_encode(url('api.lookup', ['what' => 'customer'])) ?> + '&id=' + this.value).then(r => r.json()).then(c => {
    const b = document.getElementById('billAddr'), s = document.getElementById('shipAddr');
    if (b && c.billing_address !== undefined) b.value = c.billing_address || '';
    if (s && c.shipping_address !== undefined) s.value = c.shipping_address || '';
    const t = document.getElementById('termsSel'); if (t && c.terms_id) t.value = c.terms_id;
    const r = document.getElementById('repSel'); if (r && c.sales_rep_id) r.value = c.sales_rep_id;
  });
  <?php if (in_array($docType, ['invoice', 'credit_memo'], true) && !$t): ?>
  const hasLines = Array.from(document.querySelectorAll('[data-f="item_id"]')).some(s => s.value);
  if (!hasLines) { window.location = <?= json_encode(url($pageKey, ['a' => 'new'])) ?> + '&customer_id=' + this.value; }
  <?php endif; ?>
});
</script>
<?php endif; ?>
<?php
layout_end();
