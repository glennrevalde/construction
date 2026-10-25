<?php
declare(strict_types=1);

/**
 * BIR Reports > BIR Form 2307: the Certificate of Creditable Tax Withheld at Source the company gives each supplier
 * for a quarter, from every tax withheld on the bills paid to it in the quarter (Pay Bills, Withholding).
 *
 * Part III lists the income payments subject to expanded withholding tax by ATC, with the income payments of the
 * 1st, 2nd and 3rd month of the quarter, their total and the tax withheld; the money payments subject to withholding
 * of business tax (VAT withheld) are listed below them. The amounts are the tax bases kept on the payments (net of
 * VAT) and the taxes withheld. Printing a certificate is recorded in the audit trail, so the registers can say when
 * the 2307 of a supplier and quarter was issued.
 */
$pageKey = 'bir.form_2307';
$today = today();
[$cy, $cq] = Withholding::quarter($today);
$year = (int)(g('year') ?: $cy);
$quarter = max(1, min(4, (int)(g('quarter') ?: $cq)));
$from = sprintf('%04d-%02d-01', $year, $quarter * 3 - 2);
$to = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $quarter * 3)));
$qkey = $year . '-Q' . $quarter;
$vendorId = gint('vendor_id');
$all = g('all') === '1';
$print = g('print') === '1';

/** The certificate of one supplier: its taxes withheld in the quarter by type and month */
$certificate = function (int $vid) use ($from, $to): ?array {
    $rows = Withholding::rows('purchases', $from, $to, ['party_id' => $vid, 'order' => 'x.tax_kind, x.sort_order, w.atc, p.txn_date']);
    if (!$rows) {
        return null;
    }
    $m0 = (int)substr($from, 5, 2);
    $lines = ['income' => [], 'vat' => []];
    foreach ($rows as $r) {
        $k = $r['tax_kind'] === 'vat' ? 'vat' : 'income';
        $key = $r['wht_id'] . '|' . $r['atc'] . '|' . $r['rate'];
        if (!isset($lines[$k][$key])) {
            $lines[$k][$key] = ['name' => $r['wht_name'] . ' ' . Withholding::rateText($r['rate']), 'atc' => $r['atc'], 'm' => [0.0, 0.0, 0.0], 'tax' => 0.0];
        }
        $mi = max(0, min(2, (int)substr((string)$r['txn_date'], 5, 2) - $m0));
        $lines[$k][$key]['m'][$mi] += (float)$r['base'];
        $lines[$k][$key]['tax'] += (float)$r['amount'];
    }
    $v = DB::row('SELECT * FROM vendors WHERE id = :id', ['id' => $vid]);
    return ['vendor' => $v, 'lines' => $lines];
};

$money = fn($v) => abs((float)$v) >= 0.005 ? number_format((float)$v, 2) : '';
$boxes = function (string $text, int $n, string $cls = '') {
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $out = '<span class="f2307-boxes ' . h($cls) . '">';
    for ($i = 0; $i < $n; $i++) {
        $out .= '<span>' . h($chars[$i] ?? '') . '</span>';
    }
    return $out . '</span>';
};
$tinBoxes = function (?string $tin) use ($boxes) {
    $d = str_pad(substr(preg_replace('/\D/', '', (string)$tin), 0, 14), 14, ' ');
    return $boxes(substr($d, 0, 3), 3) . '<i>-</i>' . $boxes(substr($d, 3, 3), 3) . '<i>-</i>' . $boxes(substr($d, 6, 3), 3) . '<i>-</i>' . $boxes(trim(substr($d, 9, 5)), 5);
};
$dateBoxes = fn(string $d) => $boxes(date('mdY', strtotime($d)), 8, 'f2307-date');

/** One certificate, as the form lays it out */
$render = function (array $c) use ($from, $to, $year, $quarter, $money, $boxes, $tinBoxes, $dateBoxes): string {
    $s = Settings::all();
    $v = $c['vendor'];
    $mNames = [date('M', strtotime($from)), date('M', strtotime($from . ' +1 month')), date('M', strtotime($from . ' +2 months'))];
    $table = function (array $lines, string $title) use ($money): string {
        $html = $title !== '' ? '<tr class="f2307-sec"><td colspan="7">' . h($title) . '</td></tr>' : '';
        $tot = [0.0, 0.0, 0.0, 0.0, 0.0];
        $n = 0;
        foreach ($lines as $l) {
            $sum = array_sum($l['m']);
            $html .= '<tr><td>' . h($l['name']) . '</td><td class="c">' . h($l['atc']) . '</td><td class="n">' . $money($l['m'][0]) . '</td><td class="n">' . $money($l['m'][1]) . '</td><td class="n">' . $money($l['m'][2]) . '</td>'
                . '<td class="n">' . $money($sum) . '</td><td class="n">' . $money($l['tax']) . '</td></tr>';
            $tot[0] += $l['m'][0];
            $tot[1] += $l['m'][1];
            $tot[2] += $l['m'][2];
            $tot[3] += $sum;
            $tot[4] += $l['tax'];
            $n++;
        }
        for (; $n < 4; $n++) {
            $html .= '<tr class="f2307-blank"><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>';
        }
        return $html . '<tr class="f2307-total"><td>Total</td><td></td><td class="n">' . $money($tot[0]) . '</td><td class="n">' . $money($tot[1]) . '</td><td class="n">' . $money($tot[2]) . '</td><td class="n">' . $money($tot[3]) . '</td><td class="n">' . $money($tot[4]) . '</td></tr>';
    };
    $sign = trim((string)($s['bir_signatory_name'] ?? ''));
    $signLine = $sign !== '' ? h($sign) . ((string)($s['bir_signatory_title'] ?? '') !== '' ? ', ' . h($s['bir_signatory_title']) : '') . ((string)($s['bir_signatory_tin'] ?? '') !== '' ? ', TIN ' . h(Withholding::tin($s['bir_signatory_tin'])) : '') : '&nbsp;';
    return '<div class="f2307">'
        . '<div class="f2307-top"><div class="f2307-bir">For BIR Use Only<br>BCS/ Item:</div>'
        . '<div class="f2307-gov">Republic of the Philippines<br>Department of Finance<br><strong>Bureau of Internal Revenue</strong></div><div class="f2307-bir"></div></div>'
        . '<div class="f2307-titlebar"><div class="f2307-formno">BIR Form No.<strong>2307</strong><span>January 2018 (ENCS)</span></div>'
        . '<div class="f2307-formname">Certificate of Creditable Tax<br>Withheld At Source</div><div class="f2307-code">2307 01/18ENCS</div></div>'
        . '<div class="f2307-hint">Fill in all applicable spaces. Mark all appropriate boxes with an "X".</div>'
        . '<div class="f2307-line"><b>1</b> For the Period &nbsp;From ' . $dateBoxes($from) . ' <small>(MM/DD/YYYY)</small> &nbsp;To ' . $dateBoxes($to) . ' <small>(MM/DD/YYYY)</small></div>'
        . '<div class="f2307-part">Part I - Payee Information</div>'
        . '<div class="f2307-line"><b>2</b> Taxpayer Identification Number (TIN) ' . $tinBoxes($v['tin']) . '</div>'
        . '<div class="f2307-line f2307-field"><b>3</b> Payee\'s Name <small>(Last Name, First Name, Middle Name for Individual OR Registered Name for Non-Individual)</small><div class="f2307-val">' . h($v['company_name'] ?: $v['name']) . '</div></div>'
        . '<div class="f2307-line f2307-split"><div class="f2307-field"><b>4</b> Registered Address<div class="f2307-val">' . h((string)$v['address']) . '</div></div><div class="f2307-field f2307-zip"><b>4A</b> ZIP Code<div class="f2307-val">&nbsp;</div></div></div>'
        . '<div class="f2307-line f2307-field"><b>5</b> Foreign Address, if applicable<div class="f2307-val">&nbsp;</div></div>'
        . '<div class="f2307-part">Part II - Payor Information</div>'
        . '<div class="f2307-line"><b>6</b> Taxpayer Identification Number (TIN) ' . $tinBoxes($s['company_tin']) . '</div>'
        . '<div class="f2307-line f2307-field"><b>7</b> Payor\'s Name <small>(Last Name, First Name, Middle Name for Individual OR Registered Name for Non-Individual)</small><div class="f2307-val">' . h($s['company_name']) . '</div></div>'
        . '<div class="f2307-line f2307-split"><div class="f2307-field"><b>8</b> Registered Address<div class="f2307-val">' . h((string)$s['company_address']) . '</div></div><div class="f2307-field f2307-zip"><b>8A</b> ZIP Code<div class="f2307-val">' . h((string)($s['bir_zip_code'] ?? '')) . '&nbsp;</div></div></div>'
        . '<div class="f2307-part">Part III - Details of Monthly Income Payments and Taxes Withheld</div>'
        . '<div class="f2307-tablewrap"><table class="f2307-table"><thead><tr><th rowspan="2" class="w-desc">Income Payments Subject to Expanded Withholding Tax</th><th rowspan="2">ATC</th>'
        . '<th colspan="4">AMOUNT OF INCOME PAYMENTS</th><th rowspan="2">Tax Withheld for the Quarter</th></tr>'
        . '<tr><th>1st Month of the Quarter<small>' . h($mNames[0]) . '</small></th><th>2nd Month of the Quarter<small>' . h($mNames[1]) . '</small></th><th>3rd Month of the Quarter<small>' . h($mNames[2]) . '</small></th><th>Total</th></tr></thead><tbody>'
        . $table($c['lines']['income'], '')
        . $table($c['lines']['vat'], 'Money Payments Subject to Withholding of Business Tax (Government & Private)')
        . '</tbody></table></div>'
        . '<div class="f2307-decl">We declare under the penalties of perjury that this certificate has been made in good faith, verified by us, and to the best of our knowledge and belief, is true and correct, pursuant to the provisions of the National Internal Revenue Code, as amended, and the regulations issued under authority thereof. Further, we give our consent to the processing of our information as contemplated under the *Data Privacy Act of 2012 (R.A. No. 10173) for legitimate and lawful purposes.</div>'
        . '<div class="f2307-sign"><div class="f2307-signname">' . $signLine . '</div><div class="f2307-signcap">Signature over Printed Name of Payor/Payor\'s Authorized Representative/Tax Agent<br>(Indicate Title/Designation and TIN)</div></div>'
        . '<div class="f2307-agent"><div>Tax Agent Accreditation No./<br>Attorney\'s Roll No. (if applicable)</div><div>Date of Issue<br>(MM/DD/YYYY)</div><div>Date of Expiry<br>(MM/DD/YYYY)</div></div>'
        . '<div class="f2307-conf">CONFORME:</div>'
        . '<div class="f2307-sign"><div class="f2307-signname">&nbsp;</div><div class="f2307-signcap">Signature over Printed Name of Payee/Payee\'s Authorized Representative/Tax Agent<br>(Indicate Title/Designation and TIN)</div></div>'
        . '<div class="f2307-agent"><div>Tax Agent Accreditation No./<br>Attorney\'s Roll No. (if applicable)</div><div>Date of Issue<br>(MM/DD/YYYY)</div><div>Date of Expiry<br>(MM/DD/YYYY)</div></div>'
        . '<div class="f2307-foot">*NOTE: The BIR Data Privacy is in the BIR website (www.bir.gov.ph). Generated by GSMaRT Construction System for ' . h('Q' . $quarter . ' ' . $year) . '.</div>'
        . '</div>';
};

// the suppliers with taxes withheld in the quarter
$list = DB::all("SELECT v.id, v.name, v.tin, SUM(CASE WHEN w.tax_kind = 'income' THEN w.base ELSE 0 END) AS base_income, SUM(CASE WHEN w.tax_kind = 'income' THEN w.amount ELSE 0 END) AS income,
                        SUM(CASE WHEN w.tax_kind = 'vat' THEN w.amount ELSE 0 END) AS vat
                   FROM payment_withholdings w JOIN transactions p ON p.id = w.payment_txn_id AND p.is_voided = 0 AND p.txn_type = 'bill_payment'
                   JOIN vendors v ON v.id = p.vendor_id
                  WHERE p.txn_date BETWEEN :f AND :t GROUP BY v.id, v.name, v.tin ORDER BY v.name", ['f' => $from, 't' => $to]);

$certs = [];
if ($all) {
    foreach ($list as $r) {
        if ($c = $certificate((int)$r['id'])) {
            $certs[] = $c;
        }
    }
} elseif ($vendorId) {
    if ($c = $certificate($vendorId)) {
        $certs[] = $c;
    }
}
if ($print && $certs) {
    Access::require($pageKey, 'print');
    foreach ($certs as $c) {
        $tax = 0.0;
        foreach ($c['lines'] as $ls) {
            foreach ($ls as $l) {
                $tax += $l['tax'];
            }
        }
        Audit::log('print_2307', 'bir', 'vendor', (int)$c['vendor']['id'], 'BIR Form 2307 ' . $c['vendor']['name'] . ' Q' . $quarter . ' ' . $year, ['reason' => $qkey, 'amount' => $tax, 'page_key' => $pageKey]);
    }
}

$years = [];
$firstYear = (int)substr((string)(DB::value("SELECT MIN(txn_date) FROM transactions WHERE txn_type = 'bill_payment'") ?: $today), 0, 4);
for ($y = (int)date('Y') + 1; $y >= min($firstYear, (int)date('Y') - 1); $y--) {
    $years[$y] = (string)$y;
}
layout_start('BIR Form 2307', ['bare' => $print, 'no_title' => $print, 'body_class' => 'f2307-body',
    'actions' => page_link('bir.wht_table', [], 'Withholding Tax Table', 'btn-outline-secondary', 'table') . ' ' . page_link('rpt.bir_wht_summary', ['dates' => 'custom', 'from' => $from, 'to' => $to], 'Summary of taxes withheld', 'btn-outline-secondary', 'bar-chart')]);
if ($print) {
    echo '<div class="no-print text-center my-2"><button class="btn btn-primary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print / Save as PDF</button> <a class="btn btn-outline-secondary btn-sm" href="' . h(current_url(['print' => null])) . '">Back</a></div>';
    foreach ($certs as $c) {
        echo $render($c);
    }
    layout_end();
    return;
}
?>
<form class="card card-body mb-3 filters" method="get">
  <input type="hidden" name="p" value="<?= h($pageKey) ?>">
  <div class="row g-2 align-items-end">
    <?= select_field('vendor_id', 'Supplier', Lookup::vendors(), $vendorId, ['blank' => '— choose a supplier —', 'class' => 'col-md-4']) ?>
    <?= select_field('year', 'Year', $years, $year, ['class' => 'col-6 col-md-2', 'extra' => 'data-no-combo']) ?>
    <?= select_field('quarter', 'Quarter', [1 => '1st quarter (Jan to Mar)', 2 => '2nd quarter (Apr to Jun)', 3 => '3rd quarter (Jul to Sep)', 4 => '4th quarter (Oct to Dec)'], $quarter, ['class' => 'col-6 col-md-3', 'extra' => 'data-no-combo']) ?>
    <div class="col-md-3 d-flex gap-2"><button class="btn btn-sm btn-primary"><i class="bi bi-eye"></i> Show</button>
      <?php if ($list && Access::can($pageKey, 'print')): ?><a class="btn btn-sm btn-outline-secondary" href="<?= h(url($pageKey, ['year' => $year, 'quarter' => $quarter, 'all' => 1, 'print' => 1])) ?>"><i class="bi bi-printer"></i> Print all (<?= count($list) ?>)</a><?php endif; ?></div>
  </div>
</form>

<div class="row g-3">
  <div class="col-xl-4">
    <div class="card"><div class="card-header fw-semibold">Suppliers with taxes withheld, Q<?= $quarter ?> <?= $year ?></div>
      <div class="list-group list-group-flush f2307-list">
      <?php foreach ($list as $r): ?>
        <a class="list-group-item list-group-item-action <?= (int)$r['id'] === (int)$vendorId ? 'active' : '' ?>" href="<?= h(url($pageKey, ['vendor_id' => $r['id'], 'year' => $year, 'quarter' => $quarter])) ?>">
          <div class="d-flex justify-content-between gap-2"><span class="fw-semibold"><?= h($r['name']) ?></span><span class="num"><?= money((float)$r['income'] + (float)$r['vat']) ?></span></div>
          <div class="small f2307-list-sub">TIN <?= h(Withholding::tin($r['tin']) ?: 'missing') ?>, income tax <?= money($r['income']) ?><?= (float)$r['vat'] ? ', VAT ' . money($r['vat']) : '' ?></div>
          <div class="small f2307-list-sub"><?= h(bir_2307_status((int)$r['id'], $qkey)) ?></div>
        </a>
      <?php endforeach; ?>
      <?php if (!$list): ?><div class="list-group-item text-muted small">No taxes were withheld from suppliers in this quarter. Taxes are withheld in Pay Bills (Withholding column).</div><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-xl-8">
  <?php if ($certs): $c = $certs[0]; ?>
    <div class="d-flex flex-wrap gap-2 mb-2 align-items-center">
      <?php if (Access::can($pageKey, 'print')): ?><a class="btn btn-sm btn-primary" href="<?= h(current_url(['print' => 1])) ?>"><i class="bi bi-printer"></i> Print / PDF</a><?php endif; ?>
      <?php if (!$c['vendor']['tin']): ?><span class="small text-danger">The supplier has no TIN: add it in the Vendor Center before issuing the certificate.</span><?php endif; ?>
      <?php if (!trim((string)Settings::get('company_tin'))): ?><span class="small text-danger">The company TIN is not set (System Administration, Company).</span><?php endif; ?>
    </div>
    <div class="f2307-preview"><?= $render($c) ?></div>
  <?php elseif ($vendorId): ?>
    <div class="card card-body text-muted">No taxes were withheld from this supplier in Q<?= $quarter ?> <?= $year ?>.</div>
  <?php else: ?>
    <div class="card card-body text-muted">Choose a supplier on the left (or above) to see its certificate for the quarter. The amounts are the income payments (net of VAT) and the taxes withheld in Pay Bills.</div>
  <?php endif; ?>
  </div>
</div>
<?php
layout_end();

function bir_2307_status(int $vendorId, string $qkey): string
{
    $d = DB::value("SELECT MAX(created_at) FROM audit_trail WHERE action = 'print_2307' AND record_type = 'vendor' AND record_id = :v AND reason = :q", ['v' => $vendorId, 'q' => $qkey]);
    return $d ? 'Printed ' . fdate($d) : 'Not yet printed';
}
