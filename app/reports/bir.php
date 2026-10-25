<?php
declare(strict_types=1);

/**
 * BIR Reports: withholding taxes and VAT, worked out the way the company's "VAT, Withholding Tax and Company Tax
 * Returns" guide sets them out.
 *
 *  - 12% output VAT is on the VAT-exclusive taxable sale; input VAT on the VAT-exclusive eligible purchase.
 *  - 1% (goods) and 2% (services) are expanded withholding of INCOME tax on the VAT-exclusive income payment:
 *    remitted with 0619-E (months 1 and 2 of the quarter) and 1601-EQ (the quarter), certified on BIR Form 2307.
 *  - 5% is creditable VAT withheld (business tax) on the VAT-exclusive payment: the withholding agent remits it with
 *    1600-VT; for the seller it is a VAT credit (2550Q Item 16, Schedule 3), never input VAT.
 *  - Net cash = invoice total less every tax withheld.
 *
 * The registers follow the worksheets of the guide: A quarterly VAT reconciliation (2550Q), B output VAT / sales,
 * C input VAT / purchases, D creditable VAT withheld, E 1% / 2% income withholding, F VAT withholding (1600-VT).
 * They are internal worksheets: the official BIR forms are filed from them.
 */

/** The note every BIR report carries, and the lines to sign under it */
function bir_foot(string $extra = ''): string
{
    return ($extra !== '' ? '<div class="small text-muted mb-2">' . $extra . '</div>' : '')
        . '<div class="bir-sign"><div><span></span>Prepared by</div><div><span></span>Reviewed by</div><div><span></span>Date</div></div>'
        . '<div class="bir-foot-note">Internal worksheet only, not a substitute for BIR filing forms. Verify the current BIR issuances, the tax profile and the form version before filing.</div>';
}

/** VAT status of a line from its tax code: vat (12%), zero (zero-rated), exempt (exempt, non-taxable or none) */
function bir_vat_case_sql(string $tc = 'tc'): array
{
    $vat = "($tc.rate > 0)";
    $zero = "($tc.id IS NOT NULL AND $tc.rate = 0 AND ($tc.code = 'ZR' OR $tc.name LIKE '%zero%'))";
    return [$vat, $zero, "NOT ($vat OR $zero) OR $tc.id IS NULL"];
}

/**
 * Sales documents of a period with their VATable, zero-rated and exempt sales and output VAT (credit memos and
 * POS returns as negatives), whether they were goods or services, and the taxes customers withheld on them.
 */
function bir_sales_docs(string $from, string $to, ?int $customerId = null): array
{
    [$vat, $zero, $ex] = bir_vat_case_sql();
    $sql = "SELECT t.id, t.txn_type, t.txn_no, t.ref_no, t.txn_date, t.total, t.customer_id, c.name AS party, c.tin,
                   SUM(CASE WHEN $vat THEN l.amount ELSE 0 END) AS vatable,
                   SUM(CASE WHEN $vat THEN l.tax_amount ELSE 0 END) AS vat,
                   SUM(CASE WHEN $zero THEN l.amount ELSE 0 END) AS zero_rated,
                   SUM(CASE WHEN ($ex) THEN l.amount ELSE 0 END) AS exempt,
                   SUM(CASE WHEN i.item_type IN ('inventory','assembly','non_inventory','group') THEN ABS(l.amount) ELSE 0 END) AS goods,
                   SUM(CASE WHEN i.item_type IN ('inventory','assembly','non_inventory','group') THEN 0 ELSE ABS(l.amount) END) AS services
              FROM transactions t
              JOIN transaction_lines l ON l.txn_id = t.id
              LEFT JOIN items i ON i.id = l.item_id
              LEFT JOIN tax_codes tc ON tc.id = l.tax_code_id
              LEFT JOIN customers c ON c.id = t.customer_id
             WHERE t.txn_type IN ('invoice','sales_receipt','statement_charge','credit_memo','pos_sale','pos_return')
               AND t.is_voided = 0 AND t.is_pending = 0 AND t.txn_date BETWEEN :f AND :t
               AND (i.item_type IS NULL OR i.item_type NOT IN ('subtotal','payment'))";
    $p = ['f' => $from, 't' => $to];
    if ($customerId) {
        $sql .= ' AND (t.customer_id = :c OR c.parent_id = :c2)';
        $p['c'] = $customerId;
        $p['c2'] = $customerId;
    }
    $rows = DB::all($sql . ' GROUP BY t.id ORDER BY t.txn_date, t.id', $p);
    $wht = bir_wht_by_doc(array_column($rows, 'id'), 'sales');
    foreach ($rows as &$r) {
        $sign = in_array($r['txn_type'], ['credit_memo', 'pos_return'], true) ? -1 : 1;
        foreach (['vatable', 'vat', 'zero_rated', 'exempt'] as $k) {
            $r[$k] = r2($sign * (float)$r[$k]);
        }
        $r['total'] = r2($sign * (float)$r['total']);
        $r['kind'] = bir_goods_services((float)$r['goods'], (float)$r['services']);
        $r['wht_income'] = $wht[(int)$r['id']]['income'] ?? 0.0;
        $r['wht_vat'] = $wht[(int)$r['id']]['vat'] ?? 0.0;
        if (in_array($r['txn_type'], ['sales_receipt', 'pos_sale', 'pos_return', 'credit_memo'], true)) {
            $r['cash'] = $r['txn_type'] === 'credit_memo' ? 0.0 : $r['total'];
        } else {
            $r['cash'] = r2((float)DB::value("SELECT COALESCE(SUM(pa.amount),0) FROM payment_applications pa JOIN transactions p ON p.id = pa.payment_txn_id
                                               WHERE pa.target_txn_id = :d AND p.is_voided = 0 AND p.txn_type = 'payment'", ['d' => $r['id']]));
        }
    }
    return $rows;
}

/** Purchase documents of a period (bills, checks, debit memos as negatives) with their VAT, category and taxes withheld */
function bir_purchase_docs(string $from, string $to, ?int $vendorId = null): array
{
    [$vat, $zero, $ex] = bir_vat_case_sql();
    $sql = "SELECT t.id, t.txn_type, t.txn_no, t.ref_no, t.txn_date, t.total, t.vendor_id, v.name AS party, v.tin,
                   SUM(CASE WHEN $vat THEN l.amount ELSE 0 END) AS vatable,
                   SUM(CASE WHEN $vat THEN l.tax_amount ELSE 0 END) AS vat,
                   SUM(CASE WHEN $zero THEN l.amount ELSE 0 END) AS zero_rated,
                   SUM(CASE WHEN ($ex) THEN l.amount ELSE 0 END) AS exempt,
                   SUM(CASE WHEN a.account_type = 'Fixed Asset' THEN ABS(l.amount) ELSE 0 END) AS capital,
                   SUM(CASE WHEN i.item_type IN ('inventory','assembly','non_inventory','group') THEN ABS(l.amount) ELSE 0 END) AS goods,
                   SUM(CASE WHEN a.account_type = 'Fixed Asset' OR i.item_type IN ('inventory','assembly','non_inventory','group') THEN 0 ELSE ABS(l.amount) END) AS services
              FROM transactions t
              JOIN transaction_lines l ON l.txn_id = t.id
              LEFT JOIN items i ON i.id = l.item_id
              LEFT JOIN accounts a ON a.id = l.account_id
              LEFT JOIN tax_codes tc ON tc.id = l.tax_code_id
              LEFT JOIN vendors v ON v.id = t.vendor_id
             WHERE t.txn_type IN ('bill','check','vendor_credit')
               AND t.is_voided = 0 AND t.is_pending = 0 AND t.txn_date BETWEEN :f AND :t
               AND (i.item_type IS NULL OR i.item_type NOT IN ('subtotal','payment'))";
    $p = ['f' => $from, 't' => $to];
    if ($vendorId) {
        $sql .= ' AND t.vendor_id = :v';
        $p['v'] = $vendorId;
    }
    $rows = DB::all($sql . ' GROUP BY t.id ORDER BY t.txn_date, t.id', $p);
    $wht = bir_wht_by_doc(array_column($rows, 'id'), 'purchases');
    foreach ($rows as &$r) {
        $sign = $r['txn_type'] === 'vendor_credit' ? -1 : 1;
        foreach (['vatable', 'vat', 'zero_rated', 'exempt'] as $k) {
            $r[$k] = r2($sign * (float)$r[$k]);
        }
        $r['total'] = r2($sign * (float)$r['total']);
        $r['category'] = (float)$r['capital'] > max((float)$r['goods'], (float)$r['services']) ? 'Capital goods' : bir_goods_services((float)$r['goods'], (float)$r['services']);
        $r['wht_income'] = $wht[(int)$r['id']]['income'] ?? 0.0;
        $r['wht_vat'] = $wht[(int)$r['id']]['vat'] ?? 0.0;
        $r['status'] = abs((float)$r['vat']) >= 0.005 ? 'VATable' : (abs((float)$r['zero_rated']) >= 0.005 ? 'Zero-rated' : (abs((float)$r['exempt']) >= 0.005 ? 'VAT-exempt / non-VAT' : ''));
        if ($r['txn_type'] === 'check') {
            $r['paid'] = $r['total'];
        } elseif ($r['txn_type'] === 'vendor_credit') {
            $r['paid'] = 0.0;
        } else {
            $r['paid'] = r2((float)DB::value("SELECT COALESCE(SUM(pa.amount),0) FROM payment_applications pa JOIN transactions p ON p.id = pa.payment_txn_id
                                               WHERE pa.target_txn_id = :d AND p.is_voided = 0 AND p.txn_type = 'bill_payment'", ['d' => $r['id']]));
        }
    }
    return $rows;
}

function bir_goods_services(float $goods, float $services): string
{
    if ($goods < 0.005 && $services < 0.005) {
        return '';
    }
    if ($goods >= 0.005 && $services >= 0.005) {
        return $goods >= $services ? 'Goods (and services)' : 'Services (and goods)';
    }
    return $goods >= 0.005 ? 'Goods' : 'Services';
}

/** Taxes withheld on documents by kind: [doc id => ['income' => x, 'vat' => y]] */
function bir_wht_by_doc(array $ids, string $side): array
{
    if (!$ids || !Withholding::ready()) {
        return [];
    }
    [$in, $p] = DB::in('d', array_map('intval', $ids));
    $out = [];
    foreach (DB::all("SELECT w.target_txn_id, w.tax_kind, SUM(w.amount) AS amt FROM payment_withholdings w
                        JOIN transactions p ON p.id = w.payment_txn_id AND p.is_voided = 0 AND p.txn_type = :pt
                       WHERE w.target_txn_id IN $in GROUP BY w.target_txn_id, w.tax_kind", $p + ['pt' => $side === 'sales' ? 'payment' : 'bill_payment']) as $r) {
        $out[(int)$r['target_txn_id']][$r['tax_kind']] = r2($r['amt']);
    }
    return $out;
}

/** When BIR Form 2307 was printed for a supplier and quarter ("2026-Q3"), from the audit trail */
function bir_2307_printed(int $vendorId, string $quarterKey): string
{
    $d = DB::value("SELECT MAX(created_at) FROM audit_trail WHERE action = 'print_2307' AND record_type = 'vendor' AND record_id = :v AND reason = :q",
        ['v' => $vendorId, 'q' => $quarterKey]);
    return $d ? 'Printed ' . fdate($d) : 'Not yet printed';
}

function bir_qkey(string $date): string
{
    [$y, $q] = Withholding::quarter($date);
    return $y . '-Q' . $q;
}

/** The return a tax withheld in a month goes on, and when it is due (filing table of the guide) */
function bir_return_for(string $date, string $kind): array
{
    $m = (int)substr($date, 5, 2);
    $nextMonth = date('Y-m', strtotime(substr($date, 0, 7) . '-01 +1 month'));
    if ($kind === 'vat') {
        return ['1600-VT', $nextMonth . '-10'];
    }
    if ($m % 3 !== 0) {
        return ['0619-E', $nextMonth . '-10'];
    }
    return ['1601-EQ', date('Y-m-t', strtotime($nextMonth . '-01'))];
}

$birKinds = fn() => ['income' => 'Income tax withheld (1% / 2%)', 'vat' => 'VAT withheld (5%)'];
$birTypes = fn() => array_map(fn($t) => Withholding::label($t) . ($t['atc'] ? ' (' . $t['atc'] . ')' : ''), Withholding::all());

// ---------------------------------------------------------------------------------------------------------------
Report::define('bir_wht_summary', [
    'filters' => ['range', 'vendor'],
    'default_dates' => 'this_quarter',
    'extra_filters' => ['kind' => ['Tax', $birKinds], 'wht' => ['Withholding tax', $birTypes]],
    'sortable' => false,
    'run' => function (array $f): array {
        $rows = Withholding::rows('purchases', $f['from'], $f['to'], ['party_id' => $f['vendor_id'], 'kind' => $f['kind'], 'wht_id' => (int)$f['wht'],
            'order' => 'party, w.atc, x.code']);
        // one line per supplier and tax: the income payments (the base) and the tax withheld
        $by = [];
        foreach ($rows as $r) {
            $k = $r['party_id'] . ':' . $r['wht_id'];
            if (!isset($by[$k])) {
                $by[$k] = ['party_id' => (int)$r['party_id'], 'party' => $r['party'], 'tin' => $r['tin'], 'atc' => $r['atc'], 'tax' => $r['wht_name'],
                    'kind' => $r['tax_kind'], 'rate' => (float)$r['rate'], 'base' => 0.0, 'amount' => 0.0, 'n' => 0];
            }
            $by[$k]['base'] += (float)$r['base'];
            $by[$k]['amount'] += (float)$r['amount'];
            $by[$k]['n']++;
        }
        $out = [];
        $cur = null;
        $sb = $sa = $tb = $ta = 0.0;
        $ti = $tv = 0.0;
        $q = Withholding::quarter($f['from']);
        foreach ($by as $x) {
            if ($cur !== $x['party_id']) {
                if ($cur !== null) {
                    $out[] = ['party' => 'Total ' . $prev, 'base' => r2($sb), 'amount' => r2($sa), '_class' => 'subtotal-row'];
                }
                $cur = $x['party_id'];
                $prev = $x['party'];
                $sb = $sa = 0.0;
            }
            $out[] = [
                'party' => $x['party'], 'tin' => Withholding::tin($x['tin']), 'atc' => $x['atc'], 'tax' => $x['tax'], 'kind' => $x['kind'] === 'vat' ? 'VAT (business tax)' : 'Income tax',
                'rate' => $x['rate'], 'n' => $x['n'], 'base' => r2($x['base']), 'amount' => r2($x['amount']),
                '_links' => ['party' => Access::page('bir.form_2307') ? url('bir.form_2307', ['vendor_id' => $x['party_id'], 'year' => $q[0], 'quarter' => $q[1]]) : ''],
            ];
            $sb += $x['base'];
            $sa += $x['amount'];
            $tb += $x['base'];
            $ta += $x['amount'];
            if ($x['kind'] === 'vat') {
                $tv += $x['amount'];
            } else {
                $ti += $x['amount'];
            }
        }
        if ($cur !== null) {
            $out[] = ['party' => 'Total ' . $prev, 'base' => r2($sb), 'amount' => r2($sa), '_class' => 'subtotal-row'];
        }
        return [
            'grouped' => true,
            'columns' => [['key' => 'party', 'label' => 'Supplier'], ['key' => 'tin', 'label' => 'TIN'], ['key' => 'atc', 'label' => 'ATC'], ['key' => 'tax', 'label' => 'Withholding tax'],
                ['key' => 'kind', 'label' => 'Kind', 'optional' => true], ['key' => 'rate', 'label' => 'Rate', 'type' => 'pct'], ['key' => 'n', 'label' => 'Payments', 'type' => 'int', 'optional' => true],
                ['key' => 'base', 'label' => 'Income payment (tax base)', 'type' => 'money'], ['key' => 'amount', 'label' => 'Tax withheld', 'type' => 'money']],
            'rows' => $out,
            'totals' => ['party' => 'TOTAL', 'base' => r2($tb), 'amount' => r2($ta)],
            'notes' => 'Taxes withheld from suppliers on the bills paid in the period, by supplier and tax, worked out on the payment net of VAT. '
                . 'Income tax withheld (1% goods, 2% services) goes on 0619-E / 1601-EQ and the quarterly alphalist of payees; VAT withheld (5%) on 1600-VT. '
                . 'Click a supplier to print its BIR Form 2307.',
            'footer' => bir_foot('Income tax withheld: <strong>' . money($ti) . '</strong>. VAT withheld: <strong>' . money($tv) . '</strong>.'),
        ];
    },
]);

// ---------------------------------------------------------------------------------------------------------------
Report::define('bir_ewt_register', [
    'filters' => ['range', 'vendor', 'customer'],
    'default_dates' => 'this_quarter',
    'extra_filters' => ['side' => ['Register of', fn() => ['purchases' => 'Taxes withheld from suppliers', 'sales' => 'Taxes withheld by customers'], null]],
    'run' => function (array $f): array {
        $side = $f['side'] === 'sales' ? 'sales' : 'purchases';
        $rows = Withholding::rows($side, $f['from'], $f['to'], ['party_id' => $side === 'sales' ? $f['customer_id'] : $f['vendor_id'], 'kind' => 'income', 'order' => 'p.txn_date, p.id, w.id']);
        $out = [];
        $tb = $ta = 0.0;
        foreach ($rows as $r) {
            [$form, $due] = bir_return_for((string)$r['txn_date'], 'income');
            $out[] = [
                'date' => $r['txn_date'], 'party' => $r['party'], 'tin' => Withholding::tin($r['tin']),
                'doc' => $r['doc_ref'] ?: $r['doc_no'], 'kind' => $r['wht_name'], 'atc' => $r['atc'], 'base' => r2($r['base']), 'rate' => (float)$r['rate'], 'amount' => r2($r['amount']),
                'status' => $side === 'purchases' ? bir_2307_printed((int)$r['party_id'], bir_qkey((string)$r['txn_date'])) : 'Ask the customer for it',
                'return' => $side === 'purchases' ? $form . ', due ' . fdate($due) : 'Income-tax credit',
                'pay' => $r['pay_no'],
                '_links' => ['doc' => $r['doc_id'] ? Report::txnLink(['txn_id' => $r['doc_id'], 'txn_type' => $r['doc_type']]) : '', 'pay' => Report::txnLink(['txn_id' => $r['pay_id'], 'txn_type' => $r['pay_type']])],
            ];
            $tb += (float)$r['base'];
            $ta += (float)$r['amount'];
        }
        return [
            'columns' => [['key' => 'date', 'label' => 'Date paid', 'type' => 'date'], ['key' => 'party', 'label' => $side === 'sales' ? 'Payor (customer)' : 'Payee (supplier)'], ['key' => 'tin', 'label' => 'TIN'],
                ['key' => 'doc', 'label' => 'Invoice / reference'], ['key' => 'kind', 'label' => 'Goods / services'], ['key' => 'atc', 'label' => 'ATC', 'optional' => true],
                ['key' => 'base', 'label' => 'VAT-exclusive income base', 'type' => 'money'], ['key' => 'rate', 'label' => 'EWT rate', 'type' => 'pct'], ['key' => 'amount', 'label' => 'Income tax withheld', 'type' => 'money'],
                ['key' => 'status', 'label' => '2307 status'], ['key' => 'return', 'label' => 'Remittance / return'], ['key' => 'pay', 'label' => 'Payment no.', 'optional' => true]],
            'rows' => $out,
            'totals' => ['date' => 'TOTAL', 'base' => r2($tb), 'amount' => r2($ta)],
            'notes' => $side === 'purchases'
                ? 'Form E of the guide, for payments the company withholds from: reconcile with 0619-E (months 1 and 2 of the quarter, due the 10th of the next month) and 1601-EQ (the quarter, due the last day of the month after it). Income tax withheld is not a credit against the 2550Q VAT return.'
                : 'Form E of the guide, for sales: the 1% / 2% customers withheld are income-tax credits, supported by the BIR Form 2307 each customer gives. They do not reduce the VAT due.',
            'footer' => bir_foot(),
        ];
    },
]);

// ---------------------------------------------------------------------------------------------------------------
Report::define('bir_remittance', [
    'filters' => ['range'],
    'default_dates' => 'this_year',
    'sortable' => false,
    'run' => function (array $f): array {
        $rows = Withholding::rows('purchases', $f['from'], $f['to'], ['order' => 'p.txn_date, w.atc']);
        $by = [];
        foreach ($rows as $r) {
            [$form, $due] = bir_return_for((string)$r['txn_date'], $r['tax_kind']);
            $period = $form === '1601-EQ' ? bir_qkey((string)$r['txn_date']) : substr((string)$r['txn_date'], 0, 7);
            $k = $form . '|' . $period . '|' . $r['atc'] . '|' . $r['wht_id'];
            if (!isset($by[$k])) {
                $by[$k] = ['form' => $form, 'period' => $period, 'due' => $due, 'atc' => $r['atc'], 'tax' => $r['wht_name'], 'rate' => (float)$r['rate'], 'base' => 0.0, 'amount' => 0.0];
            }
            $by[$k]['base'] += (float)$r['base'];
            $by[$k]['amount'] += (float)$r['amount'];
        }
        // 1601-EQ reports the whole quarter: the first two months were remitted with 0619-E and are credited on it
        $paidBefore = [];
        foreach ($by as $x) {
            if ($x['form'] === '0619-E') {
                $q = bir_qkey($x['period'] . '-01');
                $paidBefore[$q] = ($paidBefore[$q] ?? 0) + $x['amount'];
            }
        }
        uasort($by, fn($a, $b) => [$a['due'], $a['form'], $a['atc']] <=> [$b['due'], $b['form'], $b['atc']]);
        $out = [];
        $curKey = null;
        $sub = 0.0;
        $total = 0.0;
        $flush = function () use (&$out, &$curKey, &$sub, &$paidBefore) {
            if ($curKey === null) {
                return;
            }
            [$form, $period] = explode('|', $curKey);
            $out[] = ['form' => 'Total ' . $form . ' ' . $period, 'amount' => r2($sub), '_class' => 'subtotal-row'];
            if ($form === '1601-EQ' && !empty($paidBefore[$period])) {
                $out[] = ['form' => 'Less: remitted with 0619-E for months 1 and 2', 'amount' => -r2($paidBefore[$period]), '_class' => 'subtotal-row'];
            }
        };
        foreach ($by as $x) {
            $key = $x['form'] . '|' . $x['period'];
            if ($key !== $curKey) {
                $flush();
                $curKey = $key;
                $sub = 0.0;
            }
            $label = $x['form'] === '1601-EQ' ? 'Quarter ' . str_replace('-', ' ', $x['period']) : date('F Y', strtotime($x['period'] . '-01'));
            $out[] = ['form' => $x['form'], 'period' => $label, 'due' => $x['due'], 'atc' => $x['atc'], 'tax' => $x['tax'], 'rate' => $x['rate'], 'base' => r2($x['base']), 'amount' => r2($x['amount'])];
            $sub += $x['amount'];
            $total += $x['form'] === '1601-EQ' ? 0 : $x['amount'];
        }
        $flush();
        // the quarter-end months make the rest of 1601-EQ
        foreach ($by as $x) {
            if ($x['form'] === '1601-EQ') {
                $total += $x['amount'];
            }
        }
        return [
            'grouped' => true,
            'columns' => [['key' => 'form', 'label' => 'Return'], ['key' => 'period', 'label' => 'Period'], ['key' => 'due', 'label' => 'Due date', 'type' => 'date'], ['key' => 'atc', 'label' => 'ATC'],
                ['key' => 'tax', 'label' => 'Withholding tax'], ['key' => 'rate', 'label' => 'Rate', 'type' => 'pct'], ['key' => 'base', 'label' => 'Tax base', 'type' => 'money'], ['key' => 'amount', 'label' => 'Tax withheld', 'type' => 'money']],
            'rows' => $out,
            'totals' => ['form' => 'TOTAL WITHHELD IN THE PERIOD', 'amount' => r2($total)],
            'notes' => 'What the company withheld from its suppliers and must remit, by return: <strong>0619-E</strong> for income tax withheld in months 1 and 2 of a quarter (by the 10th of the next month), '
                . '<strong>1601-EQ</strong> for the quarter (by the last day of the month after it; the 0619-E remittances are credited on it), <strong>1600-VT</strong> for VAT withheld (monthly, by the 10th of the next month). '
                . 'eFPS filers and special issuances can have other dates: check the current BIR tax calendar.',
            'footer' => bir_foot(),
        ];
    },
]);

// ---------------------------------------------------------------------------------------------------------------
Report::define('bir_vat_withholding', [
    'filters' => ['range', 'vendor'],
    'default_dates' => 'this_month',
    'run' => function (array $f): array {
        $rows = Withholding::rows('purchases', $f['from'], $f['to'], ['party_id' => $f['vendor_id'], 'kind' => 'vat', 'order' => 'p.txn_date, p.id, w.id']);
        // the income tax withheld on the same bill and payment, for the column beside it
        $inc = [];
        foreach (Withholding::rows('purchases', $f['from'], $f['to'], ['party_id' => $f['vendor_id'], 'kind' => 'income']) as $r) {
            $k = $r['pay_id'] . ':' . $r['doc_id'];
            $inc[$k] = ($inc[$k] ?? 0) + (float)$r['amount'];
        }
        $out = [];
        $tb = $ta = $ti = 0.0;
        foreach ($rows as $r) {
            $e = $inc[$r['pay_id'] . ':' . $r['doc_id']] ?? 0.0;
            unset($inc[$r['pay_id'] . ':' . $r['doc_id']]);
            [, $due] = bir_return_for((string)$r['txn_date'], 'vat');
            $out[] = [
                'date' => $r['txn_date'], 'party' => $r['party'], 'tin' => Withholding::tin($r['tin']), 'doc' => $r['doc_ref'] ?: $r['doc_no'],
                'kind' => $r['wht_name'], 'base' => r2($r['base']), 'rate' => (float)$r['rate'], 'amount' => r2($r['amount']), 'due' => $due, 'ewt' => r2($e),
                'status' => bir_2307_printed((int)$r['party_id'], bir_qkey((string)$r['txn_date'])),
                '_links' => ['doc' => $r['doc_id'] ? Report::txnLink(['txn_id' => $r['doc_id'], 'txn_type' => $r['doc_type']]) : ''],
            ];
            $tb += (float)$r['base'];
            $ta += (float)$r['amount'];
            $ti += $e;
        }
        return [
            'columns' => [['key' => 'date', 'label' => 'Date paid', 'type' => 'date'], ['key' => 'party', 'label' => 'VAT-registered seller'], ['key' => 'tin', 'label' => 'TIN'],
                ['key' => 'doc', 'label' => 'Invoice reference'], ['key' => 'kind', 'label' => 'Withholding tax', 'optional' => true], ['key' => 'base', 'label' => 'VAT-exclusive payment', 'type' => 'money'],
                ['key' => 'rate', 'label' => 'VAT WHT rate', 'type' => 'pct'], ['key' => 'amount', 'label' => 'VAT withheld (1600-VT)', 'type' => 'money'], ['key' => 'due', 'label' => '1600-VT due', 'type' => 'date'],
                ['key' => 'ewt', 'label' => 'Income EWT 1% / 2%', 'type' => 'money'], ['key' => 'status', 'label' => 'BIR 2307 issued']],
            'rows' => $out,
            'totals' => ['date' => 'TOTAL', 'base' => r2($tb), 'amount' => r2($ta), 'ewt' => r2($ti)],
            'notes' => 'Form F of the guide: the VAT withheld from suppliers (5% of the VAT-exclusive payment), remitted monthly with 1600-VT by the 10th of the following month. '
                . 'The income EWT on the same payments is shown beside it but goes on 0619-E / 1601-EQ. Do not use this register for an ordinary private buyer without an applicable special rule; for a qualifying RBE review the special 12% rules (RR 9-2025).',
            'footer' => bir_foot(),
        ];
    },
]);

// ---------------------------------------------------------------------------------------------------------------
Report::define('bir_sales_register', [
    'filters' => ['range', 'customer'],
    'default_dates' => 'this_quarter',
    'column_picker' => true,
    'run' => function (array $f): array {
        $docs = bir_sales_docs($f['from'], $f['to'], $f['customer_id']);
        $out = [];
        $tot = array_fill_keys(['vatable', 'vat', 'zero_rated', 'exempt', 'total', 'wht_income', 'wht_vat', 'cash'], 0.0);
        foreach ($docs as $d) {
            $row = [
                'date' => $d['txn_date'], 'no' => $d['txn_no'], 'type' => Txn::label($d['txn_type']), 'party' => $d['party'] ?: 'Walk-in / cash sales', 'tin' => Withholding::tin($d['tin']), 'kind' => $d['kind'],
                '_links' => ['no' => Report::txnLink(['txn_id' => $d['id'], 'txn_type' => $d['txn_type']])],
            ];
            foreach ($tot as $k => $_) {
                $row[$k] = r2($d[$k]);
                $tot[$k] += (float)$d[$k];
            }
            $out[] = $row;
        }
        return [
            'columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'no', 'label' => 'VAT invoice no.'], ['key' => 'type', 'label' => 'Type', 'optional' => true],
                ['key' => 'party', 'label' => 'Customer'], ['key' => 'tin', 'label' => 'TIN'], ['key' => 'kind', 'label' => 'Goods / services'],
                ['key' => 'vatable', 'label' => 'Net taxable sales', 'type' => 'money'], ['key' => 'vat', 'label' => 'Output VAT 12%', 'type' => 'money'],
                ['key' => 'zero_rated', 'label' => 'Zero-rated sales', 'type' => 'money', 'optional' => true], ['key' => 'exempt', 'label' => 'Exempt sales', 'type' => 'money', 'optional' => true],
                ['key' => 'total', 'label' => 'Invoice total (inc. VAT)', 'type' => 'money'], ['key' => 'wht_income', 'label' => 'Income WHT 1% / 2%', 'type' => 'money'],
                ['key' => 'wht_vat', 'label' => 'VAT WHT 5%', 'type' => 'money'], ['key' => 'cash', 'label' => 'Actual cash received', 'type' => 'money']],
            'rows' => $out,
            'totals' => ['date' => 'TOTAL'] + array_map('r2', $tot),
            'notes' => 'Form B of the guide. Taxable sales are reported in full even when customers withhold taxes from the payment. '
                . 'Reconcile the net sales and output VAT to 2550Q (Items 31A and 31B). Income WHT (1% / 2%) belongs to the income-tax records; VAT WHT (5%) to the VAT-credit records (2550Q Schedule 3). '
                . 'Credit memos and POS returns are negatives.',
            'footer' => bir_foot(),
        ];
    },
]);

// ---------------------------------------------------------------------------------------------------------------
Report::define('bir_purchase_register', [
    'filters' => ['range', 'vendor'],
    'default_dates' => 'this_quarter',
    'column_picker' => true,
    'run' => function (array $f): array {
        $docs = bir_purchase_docs($f['from'], $f['to'], $f['vendor_id']);
        $out = [];
        $tot = array_fill_keys(['vatable', 'vat', 'zero_rated', 'exempt', 'total', 'wht_income', 'wht_vat', 'paid'], 0.0);
        foreach ($docs as $d) {
            $row = [
                'date' => $d['txn_date'], 'no' => $d['ref_no'] ?: $d['txn_no'], 'type' => Txn::label($d['txn_type']), 'party' => $d['party'], 'tin' => Withholding::tin($d['tin']),
                'category' => $d['category'], 'status' => $d['status'],
                '_links' => ['no' => Report::txnLink(['txn_id' => $d['id'], 'txn_type' => $d['txn_type']])],
            ];
            foreach ($tot as $k => $_) {
                $row[$k] = r2($d[$k]);
                $tot[$k] += (float)$d[$k];
            }
            $out[] = $row;
        }
        return [
            'columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'no', 'label' => 'VAT invoice no.'], ['key' => 'type', 'label' => 'Type', 'optional' => true],
                ['key' => 'party', 'label' => 'Supplier'], ['key' => 'tin', 'label' => 'TIN'], ['key' => 'category', 'label' => 'Category'],
                ['key' => 'vatable', 'label' => 'VAT-exclusive purchase', 'type' => 'money'], ['key' => 'vat', 'label' => 'Input VAT', 'type' => 'money'],
                ['key' => 'zero_rated', 'label' => 'Zero-rated purchases', 'type' => 'money', 'optional' => true], ['key' => 'exempt', 'label' => 'Exempt / non-VAT purchases', 'type' => 'money'],
                ['key' => 'total', 'label' => 'Total bill (inc. VAT)', 'type' => 'money'], ['key' => 'wht_income', 'label' => 'Income WHT', 'type' => 'money'],
                ['key' => 'wht_vat', 'label' => 'VAT WHT', 'type' => 'money', 'optional' => true], ['key' => 'paid', 'label' => 'Amount paid to supplier', 'type' => 'money'],
                ['key' => 'status', 'label' => 'VAT status']],
            'rows' => $out,
            'totals' => ['date' => 'TOTAL'] + array_map('r2', $tot),
            'notes' => 'Form C of the guide. Input VAT is claimable only where the company meets the invoicing and creditability requirements: '
                . 'a non-VAT supplier invoice has no claimable input VAT. Identify noncreditable, exempt, import, capital-goods, unpaid-payable and adjustment amounts separately. '
                . 'Supplier debit memos are negatives.',
            'footer' => bir_foot(),
        ];
    },
]);

// ---------------------------------------------------------------------------------------------------------------
Report::define('bir_cvat_withheld', [
    'filters' => ['range', 'customer'],
    'default_dates' => 'this_quarter',
    'run' => function (array $f): array {
        $rows = Withholding::rows('sales', $f['from'], $f['to'], ['party_id' => $f['customer_id'], 'kind' => 'vat', 'order' => 'p.txn_date, p.id, w.id']);
        $out = [];
        $tb = $ta = 0.0;
        foreach ($rows as $r) {
            $out[] = [
                'date' => $r['txn_date'], 'quarter' => str_replace('-', ' ', bir_qkey((string)$r['txn_date'])), 'party' => $r['party'], 'tin' => Withholding::tin($r['tin']),
                'doc' => $r['doc_no'], 'kind' => $r['wht_name'], 'base' => r2($r['base']), 'rate' => (float)$r['rate'], 'amount' => r2($r['amount']), 'pay' => $r['pay_no'],
                '_links' => ['doc' => $r['doc_id'] ? Report::txnLink(['txn_id' => $r['doc_id'], 'txn_type' => $r['doc_type']]) : '', 'pay' => Report::txnLink(['txn_id' => $r['pay_id'], 'txn_type' => 'payment'])],
            ];
            $tb += (float)$r['base'];
            $ta += (float)$r['amount'];
        }
        return [
            'columns' => [['key' => 'date', 'label' => 'Payment date', 'type' => 'date'], ['key' => 'quarter', 'label' => 'Quarter', 'optional' => true], ['key' => 'party', 'label' => 'Government withholding agent'],
                ['key' => 'tin', 'label' => 'TIN'], ['key' => 'doc', 'label' => 'VAT invoice reference'], ['key' => 'kind', 'label' => 'Withholding tax'],
                ['key' => 'base', 'label' => 'VAT-exclusive tax base', 'type' => 'money'], ['key' => 'rate', 'label' => 'VAT WHT rate', 'type' => 'pct'], ['key' => 'amount', 'label' => 'VAT amount withheld', 'type' => 'money'],
                ['key' => 'pay', 'label' => 'Payment no.', 'optional' => true]],
            'rows' => $out,
            'totals' => ['date' => 'TOTAL', 'base' => r2($tb), 'amount' => r2($ta)],
            'notes' => 'Form D of the guide: match each credit to the government purchaser, the invoice, the proof of payment and the BIR Form 2307 received. '
                . 'Government VAT withheld is not input VAT: the certified total goes to 2550Q Part V Schedule 3 and Item 16. '
                . 'Do not apply the 5% standard illustration to RBE and other special-regime payments without validation.',
            'footer' => bir_foot('Tick each line once its BIR Form 2307 has been received.'),
        ];
    },
]);

// ---------------------------------------------------------------------------------------------------------------
/** The 2550Q figures of a quarter (with the excess input VAT carried from the quarters before) */
function bir_vat_quarter(string $from, string $to, int $depth = 8): array
{
    $sales = bir_sales_docs($from, $to);
    $buys = bir_purchase_docs($from, $to);
    $s = ['vatable' => 0.0, 'vat' => 0.0, 'zero' => 0.0, 'exempt' => 0.0, 'buy_net' => 0.0, 'buy_vat' => 0.0, 'buy_novat' => 0.0];
    foreach ($sales as $d) {
        $s['vatable'] += $d['vatable'];
        $s['vat'] += $d['vat'];
        $s['zero'] += $d['zero_rated'];
        $s['exempt'] += $d['exempt'];
    }
    foreach ($buys as $d) {
        $s['buy_net'] += $d['vatable'];
        $s['buy_vat'] += $d['vat'];
        $s['buy_novat'] += $d['zero_rated'] + $d['exempt'];
    }
    $s['cvat'] = 0.0;
    $s['cwt'] = 0.0;
    foreach (Withholding::rows('sales', $from, $to) as $r) {
        $s[$r['tax_kind'] === 'vat' ? 'cvat' : 'cwt'] += (float)$r['amount'];
    }
    // input VAT left over from the quarter before (its allowable input VAT was more than its output VAT)
    $s['carry'] = 0.0;
    if ($depth > 0) {
        $pf = date('Y-m-d', strtotime($from . ' -3 months'));
        $pt = date('Y-m-d', strtotime($from . ' -1 day'));
        if (DB::value("SELECT 1 FROM transactions WHERE txn_date BETWEEN :f AND :t AND is_voided = 0 LIMIT 1", ['f' => $pf, 't' => $pt])) {
            $prev = bir_vat_quarter($pf, $pt, $depth - 1);
            $s['carry'] = max(0.0, -$prev['net']);
        }
    }
    $s['allowable'] = $s['buy_vat'] + $s['carry'];
    $s['net'] = r2($s['vat'] - $s['allowable']);
    return array_map(fn($v) => r2($v), $s);
}

Report::define('bir_2550q', [
    'filters' => ['range'],
    'default_dates' => 'this_quarter',
    'sortable' => false,
    'run' => function (array $f): array {
        $s = bir_vat_quarter($f['from'], $f['to']);
        $payable = max(0.0, $s['net']);
        $credits = $s['cvat'];
        $row = fn($item, $desc, $amt, $cls = '') => ['item' => $item, 'desc' => $desc, 'amount' => $amt] + ($cls ? ['_class' => $cls] : []);
        $rows = [
            ['item' => 'OUTPUT VAT', '_class' => 'group-row'],
            $row('31A', 'VATable sales, VAT-exclusive', $s['vatable']),
            $row('31B', 'Output VAT on VATable sales (12%)', $s['vat']),
            $row('32', 'Zero-rated sales', $s['zero']),
            $row('33', 'Exempt sales', $s['exempt']),
            $row('34A', 'Total sales', r2($s['vatable'] + $s['zero'] + $s['exempt'])),
            $row('', 'Output VAT adjustments (+/-), uncollected / recovered receivables', 0.0),
            $row('37B', 'Total adjusted output VAT', $s['vat'], 'subtotal-row'),
            ['item' => 'INPUT VAT', '_class' => 'group-row'],
            $row('38', 'Input VAT carried over from the previous quarter', $s['carry']),
            $row('44A', 'Domestic purchases (eligible), VAT-exclusive', $s['buy_net']),
            $row('44B', 'Input tax on domestic purchases', $s['buy_vat']),
            $row('48A', 'Domestic purchases with no input tax (zero-rated, exempt, non-VAT)', $s['buy_novat']),
            $row('50B', 'Current period input tax', $s['buy_vat']),
            $row('51B', 'Total available input tax', $s['allowable']),
            $row('', 'Less input VAT adjustments (noncreditable, capital goods deferral, ...)', 0.0),
            $row('60B', 'Total allowable input tax', $s['allowable'], 'subtotal-row'),
            ['item' => 'NET VAT AND CREDITS', '_class' => 'group-row'],
            $row('61', 'Net VAT payable before payment credits (37B less 60B)', $s['net'], 'subtotal-row'),
            $row('15', 'Net VAT payable carried to the tax payable section', $payable),
            $row('16', 'Creditable VAT withheld (Schedule 3)', $credits),
            $row('17', 'Advance VAT payments / other credits', 0.0),
            $row('20', 'Total tax credits / payments', $credits),
            $row('21', $s['net'] < 0 ? 'Excess input VAT carried to the next quarter' : 'Tax still payable before penalties', $s['net'] < 0 ? -$s['net'] : r2($payable - $credits), 'total-row'),
            ['item' => 'NOT VAT', '_class' => 'group-row'],
            $row('', 'Income tax withheld by customers (1% / 2%): an income-tax credit, not a VAT credit', $s['cwt']),
        ];
        return [
            'grouped' => true,
            'columns' => [['key' => 'item', 'label' => '2550Q item'], ['key' => 'desc', 'label' => 'Description'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money']],
            'rows' => $rows,
            'notes' => 'Form A of the guide, the quarterly VAT reconciliation, mapped to BIR Form 2550Q (April 2024 edition): Item 31 output VAT, Item 44 input VAT, Item 61 the VAT before credits, '
                . 'Item 16 / Schedule 3 the creditable VAT withheld by government customers. Output VAT is on the VAT-exclusive sale; input VAT only on supported, creditable purchases. '
                . 'The 1% / 2% income tax withheld by customers never reduces the VAT due. 2550Q is filed within 25 days after the quarter closes. '
                . 'Use the actual amounts, special adjustments and the latest BIR instructions: the official form has more items than this worksheet.',
            'footer' => bir_foot('Sources: ' . (Access::page('rpt.bir_sales_register') ? '<a href="' . h(url('rpt.bir_sales_register', ['dates' => 'custom', 'from' => $f['from'], 'to' => $f['to']])) . '">Output VAT / Sales Register</a>' : 'Output VAT / Sales Register')
                . ', ' . (Access::page('rpt.bir_purchase_register') ? '<a href="' . h(url('rpt.bir_purchase_register', ['dates' => 'custom', 'from' => $f['from'], 'to' => $f['to']])) . '">Input VAT / Purchases Register</a>' : 'Input VAT / Purchases Register')
                . ', ' . (Access::page('rpt.bir_cvat_withheld') ? '<a href="' . h(url('rpt.bir_cvat_withheld', ['dates' => 'custom', 'from' => $f['from'], 'to' => $f['to']])) . '">Creditable VAT Withheld Schedule</a>' : 'Creditable VAT Withheld Schedule') . '.'),
        ];
    },
]);

// ---------------------------------------------------------------------------------------------------------------
Report::define('vendor_advances', [
    'filters' => ['asof', 'vendor'],
    'extra_filters' => ['show' => ['Show', fn() => ['open' => 'With something left to apply', 'all' => 'Every advance'], null]],
    'sortable' => false,
    'run' => function (array $f): array {
        $sql = "SELECT t.*, v.name AS vendor FROM transactions t JOIN vendors v ON v.id = t.vendor_id
                 WHERE t.txn_type = 'vendor_advance' AND t.is_voided = 0 AND t.txn_date <= :d";
        $p = ['d' => $f['asof']];
        if ($f['vendor_id']) {
            $sql .= ' AND t.vendor_id = :v';
            $p['v'] = $f['vendor_id'];
        }
        $rows = [];
        $cur = null;
        $sub = $grand = $gAmt = $gApp = 0.0;
        $prevName = '';
        foreach (DB::all($sql . ' ORDER BY v.name, t.txn_date, t.id', $p) as $a) {
            $left = Advances::remaining($a, $f['asof']);
            if ($f['show'] !== 'all' && $left <= 0.004) {
                continue;
            }
            if ($cur !== (int)$a['vendor_id']) {
                if ($cur !== null) {
                    $rows[] = ['date' => 'Total ' . $prevName, 'left' => r2($sub), '_class' => 'subtotal-row'];
                }
                $rows[] = ['date' => $a['vendor'], '_class' => 'group-row'];
                $cur = (int)$a['vendor_id'];
                $prevName = $a['vendor'];
                $sub = 0.0;
            }
            $applied = Advances::applied((int)$a['id'], $f['asof']);
            $rows[] = ['date' => $a['txn_date'], 'no' => $a['txn_no'], 'ref' => $a['ref_no'], 'memo' => $a['memo'], 'expected' => $a['due_date'],
                'amount' => r2($a['total']), 'applied' => $applied, 'left' => $left, 'days' => max(0, (int)floor((strtotime($f['asof']) - strtotime($a['txn_date'])) / 86400)),
                '_links' => ['no' => Report::txnLink(['txn_id' => $a['id'], 'txn_type' => 'vendor_advance'])]];
            $sub += $left;
            $grand += $left;
            $gAmt += (float)$a['total'];
            $gApp += $applied;
        }
        if ($cur !== null) {
            $rows[] = ['date' => 'Total ' . $prevName, 'left' => r2($sub), '_class' => 'subtotal-row'];
        }
        $acct = Accts::get('vendor_advances');
        $gl = (float)DB::value('SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE account_id = :a AND txn_date <= :d', ['a' => $acct, 'd' => $f['asof']]);
        return [
            'grouped' => true,
            'columns' => [['key' => 'date', 'label' => 'Date paid', 'type' => 'date'], ['key' => 'no', 'label' => 'Advance no.'], ['key' => 'ref', 'label' => 'Check / ref.', 'optional' => true],
                ['key' => 'memo', 'label' => 'What it pays for'], ['key' => 'expected', 'label' => 'Expected', 'type' => 'date', 'optional' => true], ['key' => 'days', 'label' => 'Days outstanding', 'type' => 'int'],
                ['key' => 'amount', 'label' => 'Advance', 'type' => 'money'], ['key' => 'applied', 'label' => 'Applied to bills', 'type' => 'money'], ['key' => 'left', 'label' => 'Left to apply', 'type' => 'money']],
            'rows' => $rows,
            'totals' => ['date' => 'TOTAL', 'amount' => r2($gAmt), 'applied' => r2($gApp), 'left' => r2($grand)],
            'notes' => 'Money paid to suppliers before their goods or services came, and what is left to apply to their bills (Pay Bills, Use advance).',
            'footer' => '<div class="small text-muted">Balance of ' . h((string)DB::value("SELECT CONCAT(code, ' - ', name) FROM accounts WHERE id = :a", ['a' => $acct])) . ' in the general ledger as of ' . h(fdate($f['asof'])) . ': <strong>' . money($gl) . '</strong>'
                . (abs($gl - $grand) > 0.009 && !$f['vendor_id'] ? ' (it differs from the advances left by ' . money($gl - $grand) . ': look for entries made to the account directly)' : '') . '.</div>',
        ];
    },
]);
