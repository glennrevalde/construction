<?php
declare(strict_types=1);

/**
 * Subcontracts and subcontractor billings.
 *
 *  Agreement (work package, items, retention, advance and recoupment) > approval > active.
 *  Billing (progress, final, retention release) > checked against the contract and the verified accomplishment >
 *  approval > a supplier bill in accounting:
 *      Dr Subcontract Cost (project, cost code) gross       Dr Input VAT (on gross less recoupment)
 *      Cr Retention Payable (retention)   Cr Subcontract Cost (back charges, penalties)   Cr Accounts Payable
 *  The advance recoupment is the supplier's advance applied to that bill (as Pay Bills applies advances), so the
 *  bill's open balance is the net of the billing.
 *  Retention release: Dr Retention Payable / Cr Accounts Payable. Payment and the 2% withholding are made in accounting.
 *  The same supplier invoice number is never accepted twice.
 */
final class CsSub
{
    public const STATUSES = ['draft' => 'Draft', 'for_approval' => 'For approval', 'active' => 'Active', 'completed' => 'Completed', 'closed' => 'Closed', 'terminated' => 'Terminated', 'rejected' => 'Rejected'];

    public static function get(int $id): ?array
    {
        return DB::row('SELECT s.*, v.name AS vendor_name, v.accreditation_expiry, v.tax_code_id AS vendor_tax, p.code AS project_code, p.name AS project_name, p.class_id
                          FROM cs_subcontracts s JOIN vendors v ON v.id = s.vendor_id JOIN cs_projects p ON p.id = s.project_id WHERE s.id = :id', ['id' => $id]);
    }

    public static function value(array $s): float
    {
        return r2((float)$s['contract_amount'] + (float)$s['approved_variations']);
    }

    /** Billed gross (posted progress and final billings), retention held and released, advance recouped */
    public static function position(int $id): array
    {
        $r = DB::row("SELECT COALESCE(SUM(CASE WHEN kind <> 'retention_release' THEN gross_amount ELSE 0 END),0) AS billed,
                             COALESCE(SUM(CASE WHEN kind <> 'retention_release' THEN retention_amount ELSE 0 END),0) AS retained,
                             COALESCE(SUM(CASE WHEN kind = 'retention_release' THEN gross_amount ELSE 0 END),0) AS released,
                             COALESCE(SUM(advance_recoup),0) AS recouped, COALESCE(SUM(net_amount),0) AS net
                        FROM cs_sub_billings WHERE subcontract_id = :s AND status = 'posted'", ['s' => $id]);
        return array_map('floatval', $r);
    }

    public static function save(array $d, array $items, ?int $id = null): int
    {
        return DB::tx(function () use ($d, $items, $id) {
            $old = $id ? self::get($id) : null;
            if ($old && !in_array($old['status'], ['draft', 'rejected'], true)) {
                fail('An approved subcontract is not changed. Record a variation (change order) instead.');
            }
            $pid = (int)($old['project_id'] ?? ($d['project_id'] ?? 0));
            if (!CsProject::get($pid)) {
                fail('Choose the project.');
            }
            CsProject::requireAccess($pid);
            $vendor = (int)($d['vendor_id'] ?? 0);
            $v = DB::row('SELECT * FROM vendors WHERE id = :v', ['v' => $vendor]);
            if (!$v) {
                fail('Choose the subcontractor.');
            }
            $clean = [];
            $sum = 0.0;
            foreach ($items as $l) {
                $desc = trim((string)($l['description'] ?? ''));
                if ($desc === '') {
                    continue;
                }
                $qty = to_num($l['qty'] ?? 0);
                $rate = to_num($l['unit_rate'] ?? 0);
                $amt = $qty != 0.0 ? r2($qty * $rate) : r2(to_num($l['amount'] ?? 0));
                if ($qty == 0.0) {
                    $qty = 1;
                    $rate = $amt;
                }
                $clean[] = ['description' => mb_substr($desc, 0, 255), 'unit' => mb_substr(trim((string)($l['unit'] ?? '')), 0, 20) ?: null, 'qty' => $qty, 'unit_rate' => $rate, 'amount' => $amt,
                    'cost_code_id' => (int)($l['cost_code_id'] ?? 0) ?: ((int)($d['cost_code_id'] ?? 0) ?: null), 'activity_id' => (int)($l['activity_id'] ?? 0) ?: null, 'id' => (int)($l['id'] ?? 0)];
                $sum += $amt;
            }
            if (!$clean) {
                fail('Add the items of the work package (at least one).');
            }
            $row = ['vendor_id' => $vendor, 'work_package' => mb_substr(trim((string)($d['work_package'] ?? '')), 0, 200), 'scope' => trim((string)($d['scope'] ?? '')) ?: null,
                'cost_code_id' => (int)($d['cost_code_id'] ?? 0) ?: null, 'contract_amount' => r2($sum), 'retention_pct' => max(0, to_num($d['retention_pct'] ?? 0)),
                'advance_amount' => max(0, to_num($d['advance_amount'] ?? 0)), 'recoupment_pct' => max(0, to_num($d['recoupment_pct'] ?? 0)), 'tax_code_id' => (int)($d['tax_code_id'] ?? 0) ?: ($v['tax_code_id'] ?: null),
                'start_date' => valid_date((string)($d['start_date'] ?? '')) ? $d['start_date'] : null, 'end_date' => valid_date((string)($d['end_date'] ?? '')) ? $d['end_date'] : null,
                'terms' => trim((string)($d['terms'] ?? '')) ?: null];
            if ($row['work_package'] === '') {
                fail('Enter the work package.');
            }
            if ($row['advance_amount'] > $row['contract_amount']) {
                fail('The advance cannot be more than the subcontract amount.');
            }
            if ($old) {
                DB::update('cs_subcontracts', $row, 'id = :id', ['id' => $id]);
            } else {
                $id = DB::insert('cs_subcontracts', $row + ['sc_no' => Seq::next('cs_subcontract'), 'project_id' => $pid, 'status' => 'draft', 'created_by' => Auth::id()]);
            }
            $keep = [];
            foreach ($clean as $i => $l) {
                $lid = $l['id'];
                unset($l['id']);
                if ($lid && DB::value('SELECT id FROM cs_subcontract_items WHERE id = :l AND subcontract_id = :s', ['l' => $lid, 's' => $id])) {
                    DB::update('cs_subcontract_items', $l + ['line_no' => $i + 1], 'id = :id', ['id' => $lid]);
                    $keep[] = $lid;
                } else {
                    $keep[] = DB::insert('cs_subcontract_items', $l + ['subcontract_id' => $id, 'line_no' => $i + 1]);
                }
            }
            foreach (array_diff(array_map('intval', DB::column('SELECT id FROM cs_subcontract_items WHERE subcontract_id = :s', ['s' => $id])), $keep) as $g) {
                DB::run('DELETE FROM cs_subcontract_items WHERE id = :id', ['id' => $g]);
            }
            $s = self::get($id);
            Audit::log($old ? 'edit' : 'create', 'subk', 'subcontract', $id, $s['sc_no'] . ' ' . $s['work_package'], ['page_key' => 'subk.contracts', 'amount' => $s['contract_amount']]);
            return $id;
        });
    }

    public static function submit(int $id): void
    {
        $s = self::get($id);
        if (!$s || !in_array($s['status'], ['draft', 'rejected'], true)) {
            fail('Only a draft subcontract is submitted.');
        }
        if ($s['accreditation_expiry'] && $s['accreditation_expiry'] < today()) {
            fail('The accreditation of ' . $s['vendor_name'] . ' expired on ' . fdate($s['accreditation_expiry']) . '. Update the subcontractor\'s record first.');
        }
        $byCode = [];
        foreach (DB::all('SELECT cost_code_id, SUM(amount) AS amt FROM cs_subcontract_items WHERE subcontract_id = :s GROUP BY cost_code_id', ['s' => $id]) as $r) {
            $byCode[(int)$r['cost_code_id']] = (float)$r['amt'];
        }
        $warn = CsMaterials::budgetWarnings((int)$s['project_id'], $byCode, 'This subcontract');
        CsApproval::submit('subcontract', $id, $s['sc_no'] . ' ' . $s['vendor_name'], (int)$s['project_id'], (float)$s['contract_amount'], $warn ? 'Budget warning: ' . implode(' ', $warn) : '', $warn ? 'high' : null);
        if ($warn) {
            flash('warning', implode(' ', $warn));
        }
    }

    public static function setStatus(int $id, string $status, string $reason): void
    {
        $s = self::get($id);
        $allowed = ['active' => ['completed', 'terminated'], 'completed' => ['closed', 'active']];
        if (!$s || !in_array($status, $allowed[$s['status']] ?? [], true)) {
            fail('That change is not possible from ' . CsUi::label($s['status'] ?? '') . '.');
        }
        if (trim($reason) === '' && $status !== 'completed') {
            fail('Give the reason.');
        }
        if ($status === 'closed') {
            $pos = self::position($id);
            if ($pos['retained'] - $pos['released'] > 0.5) {
                fail('Retention of ' . money($pos['retained'] - $pos['released']) . ' is still held. Release it (retention release billing) before closing.');
            }
            if (DB::value("SELECT id FROM cs_sub_billings WHERE subcontract_id = :s AND status IN ('draft','for_approval','approved') LIMIT 1", ['s' => $id])) {
                fail('A billing of this subcontract is still open.');
            }
        }
        DB::update('cs_subcontracts', ['status' => $status], 'id = :id', ['id' => $id]);
        Audit::log('status', 'subk', 'subcontract', $id, $s['sc_no'], ['page_key' => 'subk.contracts', 'reason' => CsUi::label($status) . ($reason ? ': ' . $reason : '')]);
    }

    // ------------------------------------------------------------------ billings

    public static function getSb(int $id): ?array
    {
        return DB::row('SELECT b.*, s.sc_no, s.work_package, s.retention_pct, s.recoupment_pct, s.advance_amount, s.tax_code_id, s.cost_code_id AS sc_cost_code, v.name AS vendor_name, p.code AS project_code, p.class_id
                          FROM cs_sub_billings b JOIN cs_subcontracts s ON s.id = b.subcontract_id JOIN vendors v ON v.id = b.vendor_id JOIN cs_projects p ON p.id = b.project_id WHERE b.id = :id', ['id' => $id]);
    }

    /** Quantities already billed per subcontract item (posted and in-process billings except one) */
    public static function billedQty(int $scId, ?int $except = null): array
    {
        $out = [];
        foreach (DB::all("SELECT l.sc_item_id, SUM(l.this_qty) AS q, SUM(l.this_amount) AS a FROM cs_sub_billing_lines l JOIN cs_sub_billings b ON b.id = l.sb_id
                           WHERE b.subcontract_id = :s AND b.status IN ('for_approval','approved','posted')" . ($except ? ' AND b.id <> :x' : '') . ' GROUP BY l.sc_item_id', ['s' => $scId] + ($except ? ['x' => $except] : [])) as $r) {
            $out[(int)$r['sc_item_id']] = ['qty' => (float)$r['q'], 'amount' => (float)$r['a']];
        }
        return $out;
    }

    public static function saveSb(array $d, array $lines, ?int $id = null, string $via = 'office'): int
    {
        return DB::tx(function () use ($d, $lines, $id, $via) {
            $old = $id ? self::getSb($id) : null;
            if ($old && !in_array($old['status'], ['draft', 'rejected'], true)) {
                fail('Only a draft billing is changed.');
            }
            $s = self::get((int)($old['subcontract_id'] ?? ($d['subcontract_id'] ?? 0)));
            if (!$s || $s['status'] !== 'active' && !($s['status'] === 'completed' && ($d['kind'] ?? '') === 'retention_release')) {
                fail('Billings are made on an active subcontract.');
            }
            $kind = in_array($d['kind'] ?? ($old['kind'] ?? ''), ['progress', 'final', 'retention_release'], true) ? ($d['kind'] ?? $old['kind']) : 'progress';
            $inv = mb_substr(trim((string)($d['invoice_no'] ?? '')), 0, 60);
            if ($inv === '') {
                fail('Enter the subcontractor\'s invoice / billing number.');
            }
            $dup = DB::value('SELECT sb_no FROM cs_sub_billings WHERE vendor_id = :v AND invoice_no = :i' . ($id ? ' AND id <> :id' : ''), ['v' => $s['vendor_id'], 'i' => $inv] + ($id ? ['id' => $id] : []));
            $dupBill = DB::value("SELECT txn_no FROM transactions WHERE txn_type = 'bill' AND vendor_id = :v AND ref_no = :i AND is_voided = 0", ['v' => $s['vendor_id'], 'i' => $inv]);
            if ($dup || ($dupBill && (!$old || !$old['bill_txn_id']))) {
                fail('Invoice ' . $inv . ' of ' . $s['vendor_name'] . ' was already recorded (' . ($dup ?: 'bill ' . $dupBill) . '). The same invoice is never paid twice.');
            }
            $date = valid_date((string)($d['billing_date'] ?? '')) ? $d['billing_date'] : today();
            $billed = self::billedQty((int)$s['id'], $id);
            $pos = self::position((int)$s['id']);
            $items = [];
            foreach (DB::all('SELECT i.*, a.progress_pct, a.wbs_code FROM cs_subcontract_items i LEFT JOIN cs_activities a ON a.id = i.activity_id WHERE i.subcontract_id = :s ORDER BY i.line_no', ['s' => $s['id']]) as $it) {
                $items[(int)$it['id']] = $it;
            }
            $clean = [];
            $gross = 0.0;
            if ($kind === 'retention_release') {
                $held = $pos['retained'] - $pos['released'];
                $gross = r2(min($held, to_num($d['release_amount'] ?? $held)));
                if ($gross <= 0) {
                    fail('There is no retention held to release.');
                }
            } else {
                foreach ($lines as $l) {
                    $iid = (int)($l['sc_item_id'] ?? 0);
                    $q = to_num($l['this_qty'] ?? 0);
                    if (!isset($items[$iid]) || abs($q) < 0.00001) {
                        continue;
                    }
                    $it = $items[$iid];
                    $prev = $billed[$iid]['qty'] ?? 0.0;
                    if ($prev + $q > (float)$it['qty'] + 0.0001) {
                        fail($it['description'] . ': billing ' . qty($prev + $q) . ' in total is more than the ' . qty($it['qty']) . ' ' . $it['unit'] . ' in the subcontract.');
                    }
                    if ($it['activity_id'] && (float)$it['qty'] > 0) {
                        $pct = ($prev + $q) / (float)$it['qty'] * 100;
                        if ($pct > (float)$it['progress_pct'] + 5) {
                            fail($it['description'] . ' would be billed to ' . number_format($pct, 1) . '% while activity ' . $it['wbs_code'] . ' is verified at ' . number_format((float)$it['progress_pct'], 1) . '% done. Bill only verified accomplishment.');
                        }
                    }
                    $amt = r2($q * (float)$it['unit_rate']);
                    $clean[] = ['sc_item_id' => $iid, 'prev_qty' => $prev, 'this_qty' => $q, 'this_amount' => $amt];
                    $gross += $amt;
                }
                if (!$clean) {
                    fail('Enter the quantities accomplished in this billing.');
                }
            }
            $gross = r2($gross);
            $prevCum = $kind === 'retention_release' ? 0.0 : r2(array_sum(array_map(fn($b) => $b['amount'], $billed)));
            $retention = $kind === 'retention_release' ? 0.0 : r2($gross * (float)$s['retention_pct'] / 100);
            $advLeft = max(0, (float)$s['advance_amount'] - $pos['recouped'] - (float)DB::value("SELECT COALESCE(SUM(advance_recoup),0) FROM cs_sub_billings WHERE subcontract_id = :s AND status IN ('for_approval','approved')" . ($id ? ' AND id <> ' . (int)$id : ''), ['s' => $s['id']]));
            // only an advance actually paid in accounting (Vendors > Advances to Suppliers) is recouped
            $advLeft = min($advLeft, array_sum(array_column(Advances::open((int)$s['vendor_id']), 'remaining')));
            $recoup = $kind === 'retention_release' ? 0.0 : r2(min($advLeft, $kind === 'final' ? $advLeft : $gross * (float)$s['recoupment_pct'] / 100));
            $back = max(0, r2(to_num($d['back_charges'] ?? 0)));
            $pen = max(0, r2(to_num($d['penalties'] ?? 0)));
            $rate = $kind === 'retention_release' ? 0.0 : (float)DB::value('SELECT rate FROM tax_codes WHERE id = :t', ['t' => (int)$s['tax_code_id']]);
            $vat = r2(($gross - $recoup) * $rate / 100);
            $net = r2($gross + $vat - $retention - $recoup - $back - $pen);
            if ($net < 0) {
                fail('The deductions are more than the billing.');
            }
            $row = ['kind' => $kind, 'invoice_no' => $inv, 'billing_date' => $date, 'period_from' => valid_date((string)($d['period_from'] ?? '')) ? $d['period_from'] : null,
                'period_to' => valid_date((string)($d['period_to'] ?? '')) ? $d['period_to'] : null, 'gross_amount' => $gross, 'prev_cumulative' => $prevCum, 'cumulative_amount' => r2($prevCum + ($kind === 'retention_release' ? 0 : $gross)),
                'retention_amount' => $retention, 'advance_recoup' => $recoup, 'back_charges' => $back, 'penalties' => $pen, 'vat_amount' => $vat, 'net_amount' => $net,
                'notes' => mb_substr(trim((string)($d['notes'] ?? '')), 0, 255) ?: null];
            if ($old) {
                DB::update('cs_sub_billings', $row, 'id = :id', ['id' => $id]);
                DB::run('DELETE FROM cs_sub_billing_lines WHERE sb_id = :b', ['b' => $id]);
            } else {
                $id = DB::insert('cs_sub_billings', $row + ['sb_no' => Seq::next('cs_sub_billing'), 'subcontract_id' => $s['id'], 'project_id' => $s['project_id'], 'vendor_id' => $s['vendor_id'],
                    'status' => 'draft', 'submitted_via' => $via, 'created_by' => Auth::id()]);
            }
            foreach ($clean as $l) {
                DB::insert('cs_sub_billing_lines', $l + ['sb_id' => $id]);
            }
            Audit::log($old ? 'edit' : 'create', 'subk', 'sub_billing', $id, $s['sc_no'] . ' invoice ' . $inv, ['page_key' => 'subk.billings', 'amount' => $net, 'reason' => CsUi::label($kind) . ($via === 'portal' ? ' (from the subcontractor portal)' : '')]);
            return $id;
        });
    }

    /** Posts an approved billing as a supplier bill (see the class notes for the entry) */
    public static function postSb(int $id): int
    {
        $b = self::getSb($id);
        if (!$b || $b['bill_txn_id']) {
            return (int)($b['bill_txn_id'] ?? 0);
        }
        $class = (int)$b['class_id'];
        $lines = [];
        $taxId = $b['tax_code_id'] ? (int)$b['tax_code_id'] : null;
        if ($b['kind'] === 'retention_release') {
            $lines[] = ['line_kind' => 'expense', 'account_id' => CsPost::account('cs_retention_payable'), 'description' => 'Release of retention, ' . $b['sc_no'], 'amount' => (float)$b['gross_amount'], 'class_id' => $class];
        } else {
            $vatLeft = (float)$b['vat_amount'];
            $grossLines = DB::all('SELECT l.*, i.description, i.cost_code_id FROM cs_sub_billing_lines l JOIN cs_subcontract_items i ON i.id = l.sc_item_id WHERE l.sb_id = :b', ['b' => $id]);
            foreach ($grossLines as $k => $l) {
                $share = (float)$b['gross_amount'] > 0 ? (float)$l['this_amount'] / (float)$b['gross_amount'] : 0;
                $tax = $k === array_key_last($grossLines) ? r2($vatLeft) : r2((float)$b['vat_amount'] * $share);
                $vatLeft -= $tax;
                $cc = $l['cost_code_id'] ?: $b['sc_cost_code'];
                $lines[] = ['line_kind' => 'expense', 'account_id' => CsPost::costAccount('subcontract', $cc ? (int)$cc : null), 'description' => $l['description'] . ' (' . qty($l['this_qty']) . ')',
                    'amount' => (float)$l['this_amount'], 'tax_code_id' => $taxId, 'tax_amount' => $tax, 'class_id' => $class, 'cost_code_id' => $cc ?: null];
            }
            if ((float)$b['retention_amount'] > 0) {
                $lines[] = ['line_kind' => 'expense', 'account_id' => CsPost::account('cs_retention_payable'), 'description' => 'Retention ' . (float)$b['retention_pct'] . '%', 'amount' => -(float)$b['retention_amount'], 'class_id' => $class];
            }
            foreach (['back_charges' => 'Back charges', 'penalties' => 'Penalties'] as $f => $label) {
                if ((float)$b[$f] > 0) {
                    $lines[] = ['line_kind' => 'expense', 'account_id' => CsPost::costAccount('subcontract', $b['sc_cost_code'] ? (int)$b['sc_cost_code'] : null), 'description' => $label, 'amount' => -(float)$b[$f], 'class_id' => $class,
                        'cost_code_id' => $b['sc_cost_code'] ?: null];
                }
            }
        }
        $terms = DB::value('SELECT terms_id FROM vendors WHERE id = :v', ['v' => $b['vendor_id']]);
        $txn = Txn::save('bill', ['txn_date' => $b['billing_date'], 'vendor_id' => (int)$b['vendor_id'], 'ref_no' => $b['invoice_no'], 'account_id' => Accts::get('ap'), 'class_id' => $class,
            'terms_id' => $terms ?: null, 'due_date' => Txn::dueDate($b['billing_date'], $terms ? (int)$terms : null),
            'memo' => $b['project_code'] . ' ' . $b['sc_no'] . ' ' . CsUi::label($b['kind']) . ' billing ' . $b['sb_no']], $lines, null, ['reason' => 'Subcontractor billing ' . $b['sb_no']]);
        $t = Txn::load($txn);
        if (abs((float)$t['total'] - (float)$b['net_amount'] - (float)$b['advance_recoup']) > 0.02) {
            fail('The bill total (' . money($t['total']) . ') does not agree with the billing (' . money((float)$b['net_amount'] + (float)$b['advance_recoup']) . ').');
        }
        // the recoupment is the advance applied to the bill, the way Pay Bills applies advances
        if ((float)$b['advance_recoup'] > 0) {
            Advances::apply((int)$b['vendor_id'], [$txn => ['amount' => (float)$b['advance_recoup'], 'discount' => 0.0, 'withholding' => 0.0, 'rows' => []]], $b['billing_date'],
                [$txn => Txn::ref($t)], null, 'subk.billings');
        }
        DB::update('cs_sub_billings', ['status' => 'posted', 'bill_txn_id' => $txn], 'id = :id', ['id' => $id]);
        Audit::log('post', 'subk', 'sub_billing', $id, $b['sb_no'], ['page_key' => 'subk.billings', 'amount' => $b['net_amount']]);
        return $txn;
    }

    public static function workflow(string $type, string $event, int $id, array $req): void
    {
        if ($type === 'subcontract') {
            $status = ['submitted' => 'for_approval', 'approved' => 'active', 'rejected' => 'rejected', 'returned' => 'draft', 'cancelled' => 'draft'][$event] ?? null;
            if ($status) {
                DB::update('cs_subcontracts', ['status' => $status] + ($event === 'approved' ? ['approved_by' => Auth::id(), 'approved_at' => date('Y-m-d H:i:s')] : []), 'id = :id', ['id' => $id]);
            }
            return;
        }
        if ($type === 'sub_billing') {
            $status = ['submitted' => 'for_approval', 'approved' => 'approved', 'rejected' => 'rejected', 'returned' => 'draft', 'cancelled' => 'draft'][$event] ?? null;
            if ($status) {
                DB::update('cs_sub_billings', ['status' => $status] + ($event === 'approved' ? ['approved_by' => Auth::id()] : []) + ($event === 'submitted' ? ['certified_by' => Auth::id()] : []), 'id = :id', ['id' => $id]);
            }
            if ($event === 'approved') {
                self::postSb($id);
            }
        }
    }
}
