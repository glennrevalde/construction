<?php
declare(strict_types=1);

/**
 * Progress billing, revenue recognition and the contract accounts.
 *
 * A billing (application for payment) is worked out on the schedule of values: per line the accomplishment before,
 * this period and to date, never more than the line's value (overbilling is refused unless an authorized contractual
 * adjustment is recorded). From the gross: retention, recoupment of the client's advance, other deductions, VAT and
 * the taxes the client is expected to withhold. Internal approval > sent to the client > certified (possibly for
 * less) > the invoice in accounting:
 *
 *      Dr Accounts Receivable                     amount due
 *      Dr Retention Receivable                    retention
 *      Dr Advances from Clients                   recoupment
 *      Dr Progress Billings                       other deductions (liquidated damages, back charges)
 *          Cr Progress Billings (or Contract Revenue when revenue follows the billings)   gross accomplishment
 *          Cr Output VAT                          on the gross less the recoupment
 *
 * An advance billing credits Advances from Clients; a retention release credits Retention Receivable.
 * Revenue under percentage of completion is recognized by period:  Dr Contract Assets / Cr Contract Revenue, and the
 * full expected loss of a losing contract is provided at once:  Dr Provision for Contract Losses / Cr its liability.
 * The difference between Contract Assets and Progress Billings of a project is its under- or over-billing.
 */
final class CsBilling
{
    public const TYPES = ['progress' => 'Progress billing (schedule of values)', 'milestone' => 'Milestone billing', 'quantity' => 'Quantity-based (unit price)', 'tm' => 'Time and materials',
        'recurring' => 'Recurring billing', 'change_order' => 'Change order billing', 'final' => 'Final billing', 'advance' => 'Advance / mobilization billing', 'retention_release' => 'Retention release'];
    public const STATUSES = ['draft' => 'Draft', 'for_approval' => 'For approval', 'approved' => 'Approved', 'submitted' => 'Submitted to client', 'certified' => 'Certified',
        'invoiced' => 'Invoiced', 'returned' => 'Returned', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];
    /** Billing types worked out on the schedule of values */
    public const SOV_TYPES = ['progress', 'quantity', 'change_order', 'final'];
    /** Billing types that are accomplishment (gross) */
    public const WORK_TYPES = ['progress', 'quantity', 'change_order', 'final', 'milestone', 'tm', 'recurring'];

    /** The kind of billing in a few words (the list and title use it; the form shows the full description) */
    public static function shortType(string $type): string
    {
        return (string)preg_replace('/\s*\(.*\)$/', '', self::TYPES[$type] ?? $type);
    }

    public static function get(int $id): ?array
    {
        return DB::row('SELECT b.*, p.code AS project_code, p.name AS project_name, p.class_id, c.name AS customer_name FROM cs_billings b JOIN cs_projects p ON p.id = b.project_id
                          JOIN customers c ON c.id = b.customer_id WHERE b.id = :id', ['id' => $id]);
    }

    /** The billing terms of a project (from its contract) */
    public static function terms(int $projectId): array
    {
        $p = CsProject::get($projectId);
        $k = $p['contract_id'] ? DB::row('SELECT * FROM cs_contracts WHERE id = :k', ['k' => $p['contract_id']]) : null;
        $cv = CsProject::contractValue($projectId);
        $vatRate = $k && $k['tax_code_id'] ? (float)DB::value('SELECT rate FROM tax_codes WHERE id = :t', ['t' => $k['tax_code_id']]) : 0.0;
        $whtRate = 0.0;
        foreach (array_filter(explode(',', (string)($k['wht_ids'] ?? ''))) as $w) {
            $whtRate += (float)DB::value('SELECT rate FROM withholding_taxes WHERE id = :w', ['w' => (int)$w]);
        }
        return ['project' => $p, 'contract' => $k, 'customer_id' => (int)($k['customer_id'] ?? $p['customer_id']), 'retention_pct' => (float)($k['retention_pct'] ?? 0),
            'retention_cap_pct' => $k && $k['retention_cap_pct'] !== null ? (float)$k['retention_cap_pct'] : null, 'recoupment_pct' => (float)($k['recoupment_pct'] ?? 0),
            'advance' => r2((float)($k['advance_pct'] ?? 0) / 100 * $cv['original']), 'tax_code_id' => $k['tax_code_id'] ?? null, 'vat_rate' => $vatRate, 'wht_rate' => $whtRate,
            'terms_id' => $k['terms_id'] ?? null, 'original' => $cv['original'], 'revised' => $cv['current']];
    }

    /** What earlier billings of the project hold (excluding one): gross accomplishment, retention, recoupment, advance billed */
    public static function previous(int $projectId, ?int $except = null, ?string $statusSet = null): array
    {
        $st = $statusSet ?? "'for_approval','approved','submitted','certified','invoiced'";
        $r = DB::row("SELECT COALESCE(SUM(CASE WHEN billing_type IN ('" . implode("','", self::WORK_TYPES) . "') THEN this_gross + materials_stored ELSE 0 END),0) AS gross,
                             COALESCE(SUM(retention_amount),0) AS retention, COALESCE(SUM(advance_recoupment),0) AS recoup,
                             COALESCE(SUM(CASE WHEN billing_type = 'advance' THEN this_gross ELSE 0 END),0) AS advance,
                             COALESCE(SUM(CASE WHEN billing_type = 'retention_release' THEN this_gross ELSE 0 END),0) AS released
                        FROM cs_billings WHERE project_id = :p AND status IN ($st)" . ($except ? ' AND id <> :x' : ''), ['p' => $projectId] + ($except ? ['x' => $except] : []));
        return array_map('floatval', $r);
    }

    // ------------------------------------------------------------------ build and save

    /**
     * Saves a draft billing. $lines: SOV types: [boq_item_id, this_qty | this_pct]; milestone: [milestone_id]; tm/recurring: [description, unit, this_qty, unit_rate].
     * Returns the id. Overbilling is refused (setting cs_overbilling = block) unless $d['overbill_override'] gives the authorized adjustment.
     */
    public static function save(array $d, array $lines, ?int $id = null): int
    {
        return DB::tx(function () use ($d, $lines, $id) {
            $old = $id ? self::get($id) : null;
            if ($old && !in_array($old['status'], ['draft', 'returned', 'rejected'], true)) {
                fail('Only a draft or returned billing can be changed.');
            }
            $pid = (int)($old['project_id'] ?? ($d['project_id'] ?? 0));
            $p = CsProject::get($pid);
            if (!$p) {
                fail('Choose the project.');
            }
            CsProject::requireAccess($pid);
            $t = self::terms($pid);
            if (!$t['contract'] || !in_array($t['contract']['status'], ['active', 'completed'], true)) {
                fail('The project\'s contract has to be approved (active) before billing.');
            }
            $type = $old['billing_type'] ?? (string)($d['billing_type'] ?? 'progress');
            if (!isset(self::TYPES[$type])) {
                fail('Choose the kind of billing.');
            }
            $date = valid_date((string)($d['billing_date'] ?? '')) ? $d['billing_date'] : today();
            $prev = self::previous($pid, $id);
            $clean = [];
            $gross = 0.0;
            $warnings = [];
            $override = trim((string)($d['overbill_override'] ?? ''));
            if (in_array($type, self::SOV_TYPES, true)) {
                $billed = CsPlanning::billedByBoq($pid, $id);
                $items = [];
                foreach (DB::all('SELECT b.*, a.progress_pct AS act_pct, a.wbs_code FROM cs_boq_items b LEFT JOIN cs_activities a ON a.id = b.activity_id WHERE b.project_id = :p', ['p' => $pid]) as $it) {
                    $items[(int)$it['id']] = $it;
                }
                foreach ($lines as $l) {
                    $iid = (int)($l['boq_item_id'] ?? 0);
                    if (!isset($items[$iid])) {
                        continue;
                    }
                    $it = $items[$iid];
                    if ($type === 'change_order' && !(int)$it['is_variation']) {
                        continue;
                    }
                    $sv = (float)$it['amount'];
                    $q = trim((string)($l['this_qty'] ?? ''));
                    $pc = trim((string)($l['this_pct'] ?? ''));
                    if ($q !== '' && to_num($q) != 0.0 && (float)$it['qty'] > 0) {
                        $thisQty = to_num($q);
                        $amt = r2($thisQty * (float)$it['unit_rate']);
                        $thisPct = $sv != 0.0 ? $amt / $sv * 100 : 0;
                    } elseif ($pc !== '' && to_num($pc) != 0.0) {
                        $thisPct = to_num($pc);
                        $amt = r2($sv * $thisPct / 100);
                        $thisQty = (float)$it['qty'] * $thisPct / 100;
                    } else {
                        continue;
                    }
                    $prevAmt = (float)($billed[$iid]['amount'] ?? 0);
                    $prevQty = (float)($billed[$iid]['qty'] ?? 0);
                    // a centavo over from rounding the percentage or the quantity is the rest of the line, not overbilling
                    if ($sv >= 0 && $prevAmt + $amt > $sv && $prevAmt + $amt - $sv <= 0.05) {
                        $amt = r2($sv - $prevAmt);
                        $thisPct = $sv != 0.0 ? $amt / $sv * 100 : 0;
                    }
                    if ($prevAmt + $amt > $sv + 0.005 && $sv >= 0) {
                        $msg = 'Line ' . $it['item_no'] . ' (' . $it['description'] . ') would be billed ' . money($prevAmt + $amt) . ' in total, more than its value of ' . money($sv) . '.';
                        if (Settings::get('cs_overbilling', 'block') === 'block' && $override === '') {
                            fail($msg . ' Overbilling is not allowed without an authorized contractual adjustment (a change order).');
                        }
                        $warnings[] = $msg;
                    }
                    if ($it['activity_id'] && $sv > 0 && ($prevAmt + $amt) / $sv * 100 > (float)$it['act_pct'] + 5) {
                        $warnings[] = 'Line ' . $it['item_no'] . ' is billed to ' . number_format(($prevAmt + $amt) / $sv * 100, 1) . '% while activity ' . $it['wbs_code'] . ' is verified at ' . number_format((float)$it['act_pct'], 1) . '%.';
                    }
                    $clean[] = ['boq_item_id' => $iid, 'description' => mb_substr($it['item_no'] . ' ' . $it['description'], 0, 255), 'unit' => $it['unit'], 'scheduled_value' => $sv, 'contract_qty' => $it['qty'],
                        'unit_rate' => $it['unit_rate'], 'prev_qty' => $prevQty, 'this_qty' => round($thisQty, 4), 'prev_pct' => $sv != 0.0 ? round($prevAmt / $sv * 100, 4) : 0, 'this_pct' => round($thisPct, 4),
                        'prev_amount' => r2($prevAmt), 'this_amount' => $amt];
                    $gross += $amt;
                }
                if ($type === 'final') {
                    // a final billing takes every line to its full value
                    foreach ($items as $iid => $it) {
                        if (in_array($iid, array_column($clean, 'boq_item_id'), true)) {
                            continue;
                        }
                        $prevAmt = (float)($billed[$iid]['amount'] ?? 0);
                        $rest = r2((float)$it['amount'] - $prevAmt);
                        if (abs($rest) < 0.005) {
                            continue;
                        }
                        $clean[] = ['boq_item_id' => $iid, 'description' => mb_substr($it['item_no'] . ' ' . $it['description'], 0, 255), 'unit' => $it['unit'], 'scheduled_value' => $it['amount'], 'contract_qty' => $it['qty'],
                            'unit_rate' => $it['unit_rate'], 'prev_qty' => (float)($billed[$iid]['qty'] ?? 0), 'this_qty' => max(0, (float)$it['qty'] - (float)($billed[$iid]['qty'] ?? 0)),
                            'prev_pct' => (float)$it['amount'] != 0.0 ? round($prevAmt / (float)$it['amount'] * 100, 4) : 0, 'this_pct' => (float)$it['amount'] != 0.0 ? round($rest / (float)$it['amount'] * 100, 4) : 0,
                            'prev_amount' => r2($prevAmt), 'this_amount' => $rest];
                        $gross += $rest;
                    }
                }
            } elseif ($type === 'milestone') {
                foreach ($lines as $l) {
                    $mid = (int)($l['milestone_id'] ?? 0);
                    $m = $mid ? DB::row('SELECT * FROM cs_milestones WHERE id = :m AND project_id = :p', ['m' => $mid, 'p' => $pid]) : null;
                    if (!$m) {
                        continue;
                    }
                    if (!(int)$m['billing_eligible']) {
                        fail('Milestone ' . $m['code'] . ' is not eligible for billing: ' . implode('; ', CsPlanning::msStatusReasons($m)) . '.');
                    }
                    $done = DB::value("SELECT b.billing_no FROM cs_billing_lines l JOIN cs_billings b ON b.id = l.billing_id WHERE l.milestone_id = :m AND b.status NOT IN ('draft','rejected','cancelled','returned')" . ($id ? ' AND b.id <> ' . (int)$id : ''), ['m' => $mid]);
                    if ($done) {
                        fail('Milestone ' . $m['code'] . ' was already billed on ' . $done . '.');
                    }
                    $amt = r2((float)$m['contract_value'] * ($m['measurement_method'] === 'client_certified' ? min(100, (float)$m['completion_pct']) / 100 : 1));
                    $clean[] = ['milestone_id' => $mid, 'description' => mb_substr($m['code'] . ' ' . $m['name'], 0, 255), 'unit' => 'lot', 'scheduled_value' => $m['contract_value'], 'contract_qty' => 1,
                        'unit_rate' => $m['contract_value'], 'prev_qty' => 0, 'this_qty' => 1, 'prev_pct' => 0, 'this_pct' => (float)$m['contract_value'] ? round($amt / (float)$m['contract_value'] * 100, 4) : 100,
                        'prev_amount' => 0, 'this_amount' => $amt];
                    $gross += $amt;
                }
            } elseif (in_array($type, ['tm', 'recurring'], true)) {
                foreach ($lines as $l) {
                    $desc = trim((string)($l['description'] ?? ''));
                    if ($desc === '') {
                        continue;
                    }
                    $q = to_num($l['this_qty'] ?? 1) ?: 1;
                    $rate = to_num($l['unit_rate'] ?? 0);
                    $amt = r2($q * $rate);
                    $clean[] = ['description' => mb_substr($desc, 0, 255), 'unit' => mb_substr(trim((string)($l['unit'] ?? '')), 0, 20) ?: null, 'scheduled_value' => 0, 'contract_qty' => 0, 'unit_rate' => $rate,
                        'prev_qty' => 0, 'this_qty' => $q, 'prev_pct' => 0, 'this_pct' => 0, 'prev_amount' => 0, 'this_amount' => $amt];
                    $gross += $amt;
                }
                if ($prev['gross'] + $gross > $t['revised'] + 0.005 && $t['revised'] > 0) {
                    $msg = 'Billings would total ' . money($prev['gross'] + $gross) . ', more than the revised contract value of ' . money($t['revised']) . '.';
                    if (Settings::get('cs_overbilling', 'block') === 'block' && $override === '') {
                        fail($msg);
                    }
                    $warnings[] = $msg;
                }
            } elseif ($type === 'advance') {
                $already = $prev['advance'];
                $amt = r2(($d['advance_amount'] ?? '') !== '' ? to_num($d['advance_amount']) : $t['advance'] - $already);
                if ($amt <= 0) {
                    fail('There is no advance left to bill under the contract (' . money($t['advance']) . ', already billed ' . money($already) . ').');
                }
                if ($already + $amt > $t['advance'] + 0.005) {
                    fail('The advance billed would be more than the contract\'s advance of ' . money($t['advance']) . '.');
                }
                $clean[] = ['description' => 'Advance payment (' . (float)($t['contract']['advance_pct'] ?? 0) . '% of the contract)', 'unit' => 'lot', 'scheduled_value' => $t['advance'], 'contract_qty' => 1, 'unit_rate' => $amt,
                    'prev_qty' => 0, 'this_qty' => 1, 'prev_pct' => 0, 'this_pct' => 0, 'prev_amount' => $already, 'this_amount' => $amt];
                $gross = $amt;
            } elseif ($type === 'retention_release') {
                $held = r2($prev['retention'] - $prev['released']);
                $amt = r2(($d['release_amount'] ?? '') !== '' ? to_num($d['release_amount']) : $held);
                if ($amt <= 0 || $amt > $held + 0.005) {
                    fail('The retention held on this project is ' . money($held) . '.');
                }
                $clean[] = ['description' => 'Release of retention', 'unit' => 'lot', 'scheduled_value' => $held, 'contract_qty' => 1, 'unit_rate' => $amt, 'prev_qty' => 0, 'this_qty' => 1,
                    'prev_pct' => 0, 'this_pct' => 0, 'prev_amount' => $prev['released'], 'this_amount' => $amt];
                $gross = $amt;
            }
            if (!$clean) {
                fail('Enter what is billed this period.');
            }
            $gross = r2($gross);
            $mat = in_array($type, self::WORK_TYPES, true) ? max(0, r2(to_num($d['materials_stored'] ?? 0))) : 0.0;
            $fig = self::figures($type, $gross, $mat, $t, $prev, to_num($d['other_deductions'] ?? 0));
            // the project's accomplishment: billings ahead of the verified physical progress are flagged
            if (in_array($type, self::WORK_TYPES, true) && $t['revised'] > 0) {
                $cumPct = ($prev['gross'] + $gross + $mat) / $t['revised'] * 100;
                $phys = CsProject::physicalPct($pid);
                if ($cumPct > $phys + 5) {
                    $warnings[] = 'Billed to date would be ' . number_format($cumPct, 1) . '% of the contract while the verified progress is ' . number_format($phys, 1) . '%.';
                }
            }
            $row = ['billing_date' => $date, 'period_from' => valid_date((string)($d['period_from'] ?? '')) ? $d['period_from'] : null, 'period_to' => valid_date((string)($d['period_to'] ?? '')) ? $d['period_to'] : null,
                'original_contract' => $t['original'], 'revised_contract' => $t['revised'], 'prev_gross' => r2($prev['gross']), 'this_gross' => $gross,
                'cumulative_gross' => in_array($type, self::WORK_TYPES, true) ? r2($prev['gross'] + $gross + $mat) : r2($prev['gross']), 'materials_stored' => $mat,
                'retention_pct' => $fig['retention_pct'], 'retention_amount' => $fig['retention'], 'advance_recoupment' => $fig['recoup'], 'other_deductions' => $fig['other'],
                'deductions_note' => mb_substr(trim((string)($d['deductions_note'] ?? '')), 0, 255) ?: null, 'vat_amount' => $fig['vat'], 'wht_expected' => $fig['wht'], 'amount_applied' => $fig['due'],
                'overbill_override' => $override !== '' ? mb_substr($override, 0, 255) : null, 'notes' => trim((string)($d['notes'] ?? '')) ?: null, 'updated_at' => date('Y-m-d H:i:s')];
            if ($override !== '' && !Access::can('pbill.billings', 'approve') && !Access::isAdmin()) {
                fail('Only someone with Approve on progress billings records an authorized adjustment for overbilling.');
            }
            if ($old) {
                DB::update('cs_billings', $row + ['status' => 'draft'], 'id = :id', ['id' => $id]);
                DB::run('DELETE FROM cs_billing_lines WHERE billing_id = :b', ['b' => $id]);
            } else {
                $id = DB::insert('cs_billings', $row + ['billing_no' => Seq::next('cs_billing'), 'project_id' => $pid, 'contract_id' => $p['contract_id'], 'customer_id' => $t['customer_id'],
                    'billing_type' => $type, 'status' => 'draft', 'created_by' => Auth::id()]);
            }
            foreach ($clean as $l) {
                DB::insert('cs_billing_lines', $l + ['billing_id' => $id]);
            }
            if ($warnings) {
                flash('warning', implode(' ', $warnings));
            }
            Audit::log($old ? 'edit' : 'create', 'pbill', 'billing', $id, $p['code'] . ' ' . self::TYPES[$type], ['page_key' => 'pbill.billings', 'amount' => $fig['due'],
                'reason' => $override !== '' ? 'Overbilling adjustment: ' . $override : ($warnings ? implode(' ', $warnings) : null)]);
            return $id;
        });
    }

    /** Retention, recoupment, VAT, expected withholding and amount due of a billing */
    public static function figures(string $type, float $gross, float $mat, array $t, array $prev, float $other): array
    {
        $base = $gross + $mat;
        $retention = 0.0;
        $recoup = 0.0;
        $retPct = 0.0;
        if (in_array($type, self::WORK_TYPES, true)) {
            $retPct = $t['retention_pct'];
            $retention = r2($base * $retPct / 100);
            if ($t['retention_cap_pct'] !== null && $t['revised'] > 0) {
                $cap = r2($t['revised'] * $t['retention_cap_pct'] / 100);
                $retention = max(0, min($retention, r2($cap - $prev['retention'])));
            }
            $advLeft = max(0, $prev['advance'] - $prev['recoup']);
            $recoup = r2(min($advLeft, $type === 'final' ? $advLeft : $base * $t['recoupment_pct'] / 100));
        }
        $other = in_array($type, self::WORK_TYPES, true) ? max(0, r2($other)) : 0.0;
        $vatBase = match ($type) {
            'retention_release' => 0.0,
            default => Settings::get('cs_billing_vat_base', 'gross_less_recoupment') === 'gross' ? $base : $base - $recoup,
        };
        $vat = r2($vatBase * $t['vat_rate'] / 100);
        $wht = r2(($type === 'retention_release' ? 0 : $vatBase) * $t['wht_rate'] / 100);
        $due = r2($base + $vat - $retention - $recoup - $other);
        return ['retention_pct' => $retPct, 'retention' => $retention, 'recoup' => $recoup, 'other' => $other, 'vat' => $vat, 'wht' => $wht, 'due' => $due];
    }

    // ------------------------------------------------------------------ steps after approval

    public static function workflow(string $type, string $event, int $id, array $req): void
    {
        if ($type === 'billing') {
            $status = ['submitted' => 'for_approval', 'approved' => 'approved', 'rejected' => 'rejected', 'returned' => 'draft', 'cancelled' => 'draft'][$event] ?? null;
            if ($status) {
                DB::update('cs_billings', ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            }
            return;
        }
        if ($type === 'revenue') {
            $status = ['submitted' => 'for_approval', 'rejected' => 'rejected', 'returned' => 'draft', 'cancelled' => 'draft'][$event] ?? null;
            if ($status) {
                DB::update('cs_revenue_recognitions', ['status' => $status], 'id = :id', ['id' => $id]);
            }
            if ($event === 'approved') {
                self::postRecognition($id);
            }
        }
    }

    /** Sent to the client (date of submission) */
    public static function markSubmitted(int $id, string $date): void
    {
        $b = self::get($id);
        if (!$b || $b['status'] !== 'approved') {
            fail('Only an approved billing is sent to the client.');
        }
        DB::update('cs_billings', ['status' => 'submitted', 'updated_at' => date('Y-m-d H:i:s'), 'notes' => trim((string)$b['notes'] . "\nSent to the client " . fdate($date))], 'id = :id', ['id' => $id]);
        Audit::log('submit_client', 'pbill', 'billing', $id, $b['billing_no'], ['page_key' => 'pbill.billings', 'amount' => $b['amount_applied'], 'reason' => 'Sent to the client on ' . fdate($date)]);
    }

    /**
     * The client's certification. When the client certifies less accomplishment than was applied for, the lines are
     * scaled down and the retention, recoupment and VAT worked out again. With $invoice the invoice is made at once.
     */
    public static function certify(int $id, string $date, string $ref, ?float $certifiedGross, bool $invoice = true): ?int
    {
        return DB::tx(function () use ($id, $date, $ref, $certifiedGross, $invoice) {
            $b = self::get($id);
            if (!$b || !in_array($b['status'], ['approved', 'submitted'], true)) {
                fail('Only an approved or submitted billing is certified.');
            }
            if (!valid_date($date)) {
                fail('Enter the date of the client\'s certificate.');
            }
            $gross = (float)$b['this_gross'];
            if ($certifiedGross !== null && abs($certifiedGross - $gross) > 0.005) {
                if ($certifiedGross < 0 || $certifiedGross > $gross + 0.005) {
                    fail('The certified accomplishment can be at most the amount applied for (' . money($gross) . '). Bill the rest next period.');
                }
                $f = $gross != 0.0 ? $certifiedGross / $gross : 0;
                foreach (DB::all('SELECT * FROM cs_billing_lines WHERE billing_id = :b', ['b' => $id]) as $l) {
                    DB::update('cs_billing_lines', ['this_amount' => r2((float)$l['this_amount'] * $f), 'this_qty' => round((float)$l['this_qty'] * $f, 4), 'this_pct' => round((float)$l['this_pct'] * $f, 4)], 'id = :id', ['id' => $l['id']]);
                }
                $sum = (float)DB::value('SELECT SUM(this_amount) FROM cs_billing_lines WHERE billing_id = :b', ['b' => $id]);
                $t = self::terms((int)$b['project_id']);
                $prev = self::previous((int)$b['project_id'], $id);
                $fig = self::figures($b['billing_type'], r2($sum), (float)$b['materials_stored'], $t, $prev, (float)$b['other_deductions']);
                DB::update('cs_billings', ['this_gross' => r2($sum), 'cumulative_gross' => r2($prev['gross'] + $sum + (float)$b['materials_stored']), 'retention_amount' => $fig['retention'],
                    'advance_recoupment' => $fig['recoup'], 'vat_amount' => $fig['vat'], 'wht_expected' => $fig['wht'], 'amount_applied' => $fig['due']], 'id = :id', ['id' => $id]);
                $b = self::get($id);
            }
            DB::update('cs_billings', ['status' => 'certified', 'certified_amount' => $b['amount_applied'], 'certified_date' => $date, 'certificate_ref' => mb_substr($ref, 0, 60) ?: null,
                'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            Audit::log('certify', 'pbill', 'billing', $id, $b['billing_no'], ['page_key' => 'pbill.billings', 'amount' => $b['amount_applied'],
                'reason' => 'Certified by the client' . ($ref ? ' (' . $ref . ')' : '') . ($certifiedGross !== null && abs($certifiedGross - $gross) > 0.005 ? ': accomplishment cut from ' . money($gross) . ' to ' . money($certifiedGross) : '')]);
            return $invoice ? self::invoice($id) : null;
        });
    }

    /** The invoice in accounting for a certified billing (see the class notes for the entry) */
    public static function invoice(int $id): int
    {
        return DB::tx(function () use ($id) {
            $b = self::get($id);
            if (!$b || !in_array($b['status'], ['certified'], true)) {
                fail('A billing is invoiced once the client certified it.');
            }
            if ($b['invoice_txn_id']) {
                return (int)$b['invoice_txn_id'];
            }
            $t = self::terms((int)$b['project_id']);
            $class = (int)$b['class_id'];
            $taxId = $t['tax_code_id'] ? (int)$t['tax_code_id'] : null;
            $revAcct = Settings::get('cs_revenue_method', 'poc_cost') === 'billing' ? CsPost::account('cs_contract_revenue') : CsPost::account('cs_progress_billings');
            $lines = [];
            $line = fn(int $acct, string $desc, float $amt, ?int $tax = null, float $taxAmt = 0.0) => ['line_kind' => 'item', 'item_id' => null, 'account_id' => $acct, 'description' => mb_substr($desc, 0, 255),
                'qty' => 0, 'rate' => 0, 'amount' => r2($amt), 'tax_code_id' => $tax, 'tax_amount' => r2($taxAmt), 'class_id' => $class];
            $label = self::TYPES[$b['billing_type']] . ' ' . $b['billing_no'] . ($b['period_to'] ? ', period to ' . fdate($b['period_to']) : '');
            switch ($b['billing_type']) {
                case 'advance':
                    $lines[] = $line(CsPost::account('cs_client_advances'), $label, (float)$b['this_gross'], $taxId, (float)$b['vat_amount']);
                    break;
                case 'retention_release':
                    $lines[] = $line(CsPost::account('cs_retention_receivable'), $label, (float)$b['this_gross']);
                    break;
                default:
                    $lines[] = $line($revAcct, 'Accomplishment: ' . $label, (float)$b['this_gross'] + (float)$b['materials_stored'], $taxId, (float)$b['vat_amount']);
                    if ((float)$b['retention_amount'] > 0) {
                        $lines[] = $line(CsPost::account('cs_retention_receivable'), 'Less retention ' . (float)$b['retention_pct'] . '%', -(float)$b['retention_amount']);
                    }
                    if ((float)$b['advance_recoupment'] > 0) {
                        $lines[] = $line(CsPost::account('cs_client_advances'), 'Less recoupment of the advance', -(float)$b['advance_recoupment']);
                    }
                    if ((float)$b['other_deductions'] > 0) {
                        $lines[] = $line($revAcct, 'Less ' . ($b['deductions_note'] ?: 'other deductions'), -(float)$b['other_deductions']);
                    }
            }
            $date = $b['certified_date'] ?: $b['billing_date'];
            $txn = Txn::save('invoice', ['txn_date' => $date, 'customer_id' => (int)$b['customer_id'], 'account_id' => Accts::get('ar'), 'class_id' => $class, 'terms_id' => $t['terms_id'],
                'due_date' => Txn::dueDate($date, $t['terms_id'] ? (int)$t['terms_id'] : null), 'ref_no' => $b['certificate_ref'] ?: $b['billing_no'],
                'memo' => $b['project_code'] . ' ' . $label, 'progress_pct' => $b['revised_contract'] > 0 ? min(999, round((float)$b['cumulative_gross'] / (float)$b['revised_contract'] * 100, 2)) : null],
                $lines, null, ['reason' => 'Progress billing ' . $b['billing_no'] . ' certified by the client']);
            $inv = Txn::load($txn);
            if (abs((float)$inv['total'] - (float)$b['amount_applied']) > 0.02) {
                fail('The invoice total (' . money($inv['total']) . ') does not agree with the billing (' . money($b['amount_applied']) . ').');
            }
            DB::update('cs_billings', ['status' => 'invoiced', 'invoice_txn_id' => $txn, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            Audit::log('invoice', 'pbill', 'billing', $id, $b['billing_no'], ['page_key' => 'pbill.billings', 'amount' => $inv['total'], 'reason' => 'Invoice ' . $inv['txn_no']]);
            return $txn;
        });
    }

    /** A status the client gives back: returned for correction (editable again), rejected, or cancelled by us */
    public static function setStatus(int $id, string $status, string $reason): void
    {
        $b = self::get($id);
        $ok = ['returned' => ['approved', 'submitted'], 'rejected' => ['submitted', 'approved'], 'cancelled' => ['draft', 'returned', 'rejected', 'approved']];
        if (!$b || !in_array($b['status'], $ok[$status] ?? [], true)) {
            fail('That change is not possible from ' . CsUi::label($b['status'] ?? '') . '.');
        }
        if (trim($reason) === '') {
            fail('Give the reason.');
        }
        if (CsApproval::pendingFor('billing', $id)) {
            fail('Withdraw it from approval first.');
        }
        DB::update('cs_billings', ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
        Audit::log('status', 'pbill', 'billing', $id, $b['billing_no'], ['page_key' => 'pbill.billings', 'reason' => CsUi::label($status) . ': ' . $reason]);
    }

    // ------------------------------------------------------------------ revenue recognition

    /** Works out the revenue of a project to a period end under the company's method (does not post) */
    public static function computeRevenue(int $projectId, string $periodEnd): array
    {
        $method = Settings::get('cs_revenue_method', 'poc_cost');
        $p = CsProject::get($projectId);
        $cv = CsProject::contractValue($projectId)['current'];
        $cost = (float)(CsCost::costByType([$projectId], $periodEnd)[$projectId]['direct'] ?? 0);
        $fc = CsCost::forecast($projectId, $periodEnd);
        $commit = CsCost::commitments([$projectId])[$projectId]['total'] ?? 0;
        $eac = $fc['rows'] ? max((float)$fc['totals']['eac'], $cost + $commit) : $cost + $commit;
        $done = in_array($p['status'], ['completed', 'under_warranty', 'closed'], true);
        $pct = match ($method) {
            'poc_physical' => CsProject::physicalPct($projectId),
            'completed' => $done ? 100.0 : 0.0,
            default => $eac > 0 ? min(100.0, $cost / $eac * 100) : 0.0,
        };
        if ($done && $method !== 'completed') {
            $pct = 100.0;
        }
        $earned = r2($cv * $pct / 100);
        $prev = (float)(CsCost::contractRevenue([$projectId], $periodEnd)[$projectId] ?? 0);
        // the loss that is expected and not yet in the books: provided in full now
        $loss = max(0, $eac - $cv);
        $needed = r2($loss * (1 - $pct / 100));
        $provided = (float)(CsCost::lossProvided([$projectId], $periodEnd)[$projectId] ?? 0);
        return ['method' => $method, 'contract_value' => $cv, 'est_total_cost' => r2($eac), 'cost_to_date' => r2($cost), 'pct' => round($pct, 4), 'earned' => $earned,
            'prev' => r2($prev), 'this' => r2($earned - $prev), 'loss_needed' => $needed, 'loss_provided' => r2($provided), 'loss_delta' => r2($needed - $provided)];
    }

    public static function prepareRecognition(int $projectId, string $periodEnd): int
    {
        return DB::tx(function () use ($projectId, $periodEnd) {
            if (Settings::get('cs_revenue_method', 'poc_cost') === 'billing') {
                fail('Revenue follows the certified billings (Construction settings), so there is nothing to recognize separately.');
            }
            CsProject::requireAccess($projectId);
            if (!valid_date($periodEnd) || $periodEnd > today()) {
                fail('Choose a period end that is not in the future.');
            }
            if (DB::value("SELECT id FROM cs_revenue_recognitions WHERE project_id = :p AND status IN ('draft','for_approval')", ['p' => $projectId])) {
                fail('This project already has a recognition waiting. Approve or remove it first.');
            }
            $last = DB::value("SELECT MAX(period_end) FROM cs_revenue_recognitions WHERE project_id = :p AND status = 'posted'", ['p' => $projectId]);
            if ($last && $periodEnd <= $last) {
                fail('Revenue is already recognized up to ' . fdate($last) . '.');
            }
            $r = self::computeRevenue($projectId, $periodEnd);
            if (abs($r['this']) < 0.005 && abs($r['loss_delta']) < 0.005) {
                fail('Nothing to recognize: revenue to date already agrees with ' . CsUi::pct($r['pct']) . ' complete.');
            }
            $id = DB::insert('cs_revenue_recognitions', ['rr_no' => Seq::next('cs_revenue'), 'project_id' => $projectId, 'period_end' => $periodEnd, 'method' => $r['method'],
                'contract_value' => $r['contract_value'], 'est_total_cost' => $r['est_total_cost'], 'cost_to_date' => $r['cost_to_date'], 'pct_complete' => $r['pct'],
                'earned_to_date' => $r['earned'], 'prev_recognized' => $r['prev'], 'this_period' => $r['this'], 'loss_provision' => $r['loss_delta'], 'status' => 'draft', 'created_by' => Auth::id()]);
            Audit::log('create', 'pbill', 'revenue', $id, CsProject::get($projectId)['code'] . ' to ' . fdate($periodEnd), ['page_key' => 'pbill.revenue', 'amount' => $r['this']]);
            return $id;
        });
    }

    public static function postRecognition(int $id): int
    {
        $r = DB::row('SELECT r.*, p.class_id, p.code FROM cs_revenue_recognitions r JOIN cs_projects p ON p.id = r.project_id WHERE r.id = :id', ['id' => $id]);
        if (!$r || $r['txn_id']) {
            return (int)($r['txn_id'] ?? 0);
        }
        $jl = [];
        if (abs((float)$r['this_period']) >= 0.005) {
            $jl[] = ['account_id' => CsPost::account('cs_contract_asset'), 'amount' => (float)$r['this_period'], 'description' => 'Revenue earned, ' . number_format((float)$r['pct_complete'], 2) . '% complete'];
            $jl[] = ['account_id' => CsPost::account('cs_contract_revenue'), 'amount' => -(float)$r['this_period'], 'description' => 'Contract revenue recognized to ' . fdate($r['period_end'])];
        }
        if (abs((float)$r['loss_provision']) >= 0.005) {
            $jl[] = ['account_id' => CsPost::account('cs_loss_provision'), 'amount' => (float)$r['loss_provision'], 'description' => 'Expected loss on the contract'];
            $jl[] = ['account_id' => CsPost::account('cs_loss_liability'), 'amount' => -(float)$r['loss_provision'], 'description' => 'Provision for loss on the contract'];
        }
        $txn = CsPost::journal($r['period_end'], 'Revenue recognition ' . $r['rr_no'] . ' ' . $r['code'] . ' (' . CsUi::label($r['method']) . ')', $jl, (int)$r['class_id'], $r['rr_no']);
        DB::update('cs_revenue_recognitions', ['status' => 'posted', 'txn_id' => $txn], 'id = :id', ['id' => $id]);
        Audit::log('post', 'pbill', 'revenue', $id, $r['rr_no'], ['page_key' => 'pbill.revenue', 'amount' => $r['this_period']]);
        return $txn;
    }

    public static function reverseRecognition(int $id, string $reason): void
    {
        DB::tx(function () use ($id, $reason) {
            $r = DB::row('SELECT * FROM cs_revenue_recognitions WHERE id = :id', ['id' => $id]);
            if (!$r || $r['status'] !== 'posted') {
                fail('Only a posted recognition is reversed.');
            }
            if (trim($reason) === '') {
                fail('Give the reason.');
            }
            if (DB::value("SELECT id FROM cs_revenue_recognitions WHERE project_id = :p AND status = 'posted' AND period_end > :d", ['p' => $r['project_id'], 'd' => $r['period_end']])) {
                fail('A later recognition is posted for this project. Reverse the latest one first.');
            }
            CsPost::void((int)$r['txn_id'], 'Revenue recognition ' . $r['rr_no'] . ' reversed: ' . $reason);
            DB::update('cs_revenue_recognitions', ['status' => 'reversed'], 'id = :id', ['id' => $id]);
            Audit::log('reverse', 'pbill', 'revenue', $id, $r['rr_no'], ['page_key' => 'pbill.revenue', 'reason' => $reason, 'amount' => $r['this_period']]);
        });
    }

    /** Over- and under-billing of a project: revenue earned (contract assets) against progress billings */
    public static function wip(int $projectId, ?string $asOf = null): array
    {
        $earned = (float)(CsCost::balance('cs_contract_asset', [$projectId], $asOf)[$projectId] ?? 0);
        $billed = -(float)(CsCost::balance('cs_progress_billings', [$projectId], $asOf)[$projectId] ?? 0);
        return ['earned' => r2($earned), 'billed' => r2($billed), 'under' => r2(max(0, $earned - $billed)), 'over' => r2(max(0, $billed - $earned))];
    }

    /** At completion: progress billings are closed against the contract assets (Dr Progress Billings / Cr Contract Assets) */
    public static function settleContractAccounts(int $projectId, string $date): ?int
    {
        $p = CsProject::get($projectId);
        if (!in_array($p['status'], ['completed', 'under_warranty', 'closed'], true)) {
            fail('The contract accounts are settled when the project is completed.');
        }
        $w = self::wip($projectId, $date);
        $amt = min($w['earned'], $w['billed']);
        if ($amt < 0.005) {
            return null;
        }
        $txn = CsPost::journal($date, 'Contract accounts settled at completion, ' . $p['code'], [
            ['account_id' => CsPost::account('cs_progress_billings'), 'amount' => $amt, 'description' => 'Progress billings closed'],
            ['account_id' => CsPost::account('cs_contract_asset'), 'amount' => -$amt, 'description' => 'Contract assets closed'],
        ], (int)$p['class_id'], $p['code']);
        Audit::log('settle', 'pbill', 'project', $projectId, $p['code'], ['page_key' => 'pbill.revenue', 'amount' => $amt]);
        return $txn;
    }
}
