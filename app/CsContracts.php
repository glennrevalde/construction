<?php
declare(strict_types=1);

/** Contracts with clients, their bonds and permits, and change orders (variations and claims). */
final class CsContracts
{
    public const TYPES = ['lump_sum' => 'Lump sum', 'unit_price' => 'Unit price', 'cost_plus' => 'Cost-plus', 'tm' => 'Time and materials', 'hybrid' => 'Hybrid'];
    public const STATUSES = ['draft' => 'Draft', 'for_approval' => 'For approval', 'active' => 'Active', 'suspended' => 'Suspended', 'completed' => 'Completed',
        'closed' => 'Closed', 'terminated' => 'Terminated', 'rejected' => 'Rejected'];
    public const BOND_KINDS = ['performance_bond' => 'Performance bond', 'surety_bond' => 'Surety bond', 'warranty_bond' => 'Warranty bond', 'advance_bond' => 'Advance payment bond',
        'insurance' => 'Contractor\'s all-risk insurance', 'permit' => 'Permit or licence', 'bid_security' => 'Bid security', 'other' => 'Other requirement'];
    public const CO_TYPES = ['client_request' => 'Client-requested change', 'additional_work' => 'Additional work', 'scope_reduction' => 'Scope reduction',
        'design_change' => 'Design modification', 'material_change' => 'Change in materials', 'quantity_adjustment' => 'Quantity adjustment',
        'price_revision' => 'Contract price revision', 'time_extension' => 'Additional time request', 'contractor_claim' => 'Contractor claim',
        'delay_cost' => 'Delay-related expenses', 'dispute' => 'Dispute or settlement'];
    public const CLIENT_CLASSES = ['government' => 'Government', 'private' => 'Private', 'corporate' => 'Corporate', 'institutional' => 'Institutional', 'other' => 'Other'];

    public static function get(int $id): ?array
    {
        return DB::row('SELECT k.*, c.name AS customer_name FROM cs_contracts k JOIN customers c ON c.id = k.customer_id WHERE k.id = :id', ['id' => $id]);
    }

    /** Original, approved variations and revised value of a contract */
    public static function value(array $k): array
    {
        return ['original' => r2($k['original_amount']), 'variations' => r2($k['approved_variations']), 'revised' => r2((float)$k['original_amount'] + (float)$k['approved_variations'])];
    }

    public static function options(?int $customerId = null): array
    {
        $p = [];
        $w = '';
        if ($customerId) {
            $w = ' AND customer_id = :c';
            $p['c'] = $customerId;
        }
        return DB::pairs("SELECT id, CONCAT(contract_no, ' ', title) FROM cs_contracts WHERE status NOT IN ('rejected','terminated') $w ORDER BY contract_no", $p);
    }

    // ------------------------------------------------------------------ contracts

    public static function save(array $d, array $bonds, ?int $id = null): int
    {
        return DB::tx(function () use ($d, $bonds, $id) {
            $old = $id ? self::get($id) : null;
            if ($id && !$old) {
                fail('Contract not found.');
            }
            $row = [
                'customer_id' => (int)($d['customer_id'] ?? 0) ?: null, 'title' => trim((string)($d['title'] ?? '')),
                'client_ref_no' => trim((string)($d['client_ref_no'] ?? '')) ?: null, 'scope' => trim((string)($d['scope'] ?? '')) ?: null,
                'description' => trim((string)($d['description'] ?? '')) ?: null, 'participating_orgs' => trim((string)($d['participating_orgs'] ?? '')) ?: null,
                'contract_type' => isset(self::TYPES[$d['contract_type'] ?? '']) ? $d['contract_type'] : 'lump_sum',
                'original_amount' => r2(to_num($d['original_amount'] ?? 0)), 'tax_code_id' => (int)($d['tax_code_id'] ?? 0) ?: null,
                'wht_ids' => implode(',', array_map('intval', (array)($d['wht_ids'] ?? []))) ?: null,
                'terms_id' => (int)($d['terms_id'] ?? 0) ?: null, 'payment_terms' => trim((string)($d['payment_terms'] ?? '')) ?: null,
                'advance_pct' => to_num($d['advance_pct'] ?? 0), 'recoupment_pct' => to_num($d['recoupment_pct'] ?? 0),
                'retention_pct' => to_num($d['retention_pct'] ?? 0), 'retention_cap_pct' => ($d['retention_cap_pct'] ?? '') === '' ? null : to_num($d['retention_cap_pct']),
                'retention_release_terms' => trim((string)($d['retention_release_terms'] ?? '')) ?: null,
                'ld_per_day' => r2(to_num($d['ld_per_day'] ?? 0)), 'ld_cap_pct' => ($d['ld_cap_pct'] ?? '') === '' ? null : to_num($d['ld_cap_pct']),
                'penalty_terms' => trim((string)($d['penalty_terms'] ?? '')) ?: null,
                'warranty_months' => (int)($d['warranty_months'] ?? 12), 'dlp_months' => (int)($d['dlp_months'] ?? 12), 'notes' => trim((string)($d['notes'] ?? '')) ?: null,
            ];
            foreach (['signing_date', 'effective_date', 'ntp_date', 'commencement_date', 'original_completion_date', 'revised_completion_date'] as $dc) {
                $row[$dc] = valid_date((string)($d[$dc] ?? '')) ? $d[$dc] : null;
            }
            $row['advance_amount'] = r2($row['original_amount'] * $row['advance_pct'] / 100);
            if (!$row['customer_id']) {
                fail('Choose the client.');
            }
            if ($row['title'] === '') {
                fail('Enter the contract title.');
            }
            if ($row['original_amount'] <= 0) {
                fail('Enter the original contract amount (net of VAT).');
            }
            foreach (['advance_pct', 'recoupment_pct', 'retention_pct'] as $pc) {
                if ($row[$pc] < 0 || $row[$pc] > 100) {
                    fail('Percentages must be between 0 and 100.');
                }
            }
            if ($old && in_array($old['status'], ['active', 'completed', 'closed', 'suspended'], true)) {
                // an approved contract changes value only through change orders
                foreach (['customer_id', 'original_amount', 'contract_type', 'advance_pct', 'recoupment_pct', 'retention_pct', 'advance_amount'] as $locked) {
                    if ((string)$row[$locked] !== (string)$old[$locked] && abs((float)$row[$locked] - (float)$old[$locked]) > 0.00001) {
                        fail('This contract is approved: its client, amount, type, advance and retention are locked. Record a change order for any change in value.');
                    }
                }
            }
            if ($old) {
                $row['updated_at'] = date('Y-m-d H:i:s');
                DB::update('cs_contracts', $row, 'id = :id', ['id' => $id]);
            } else {
                $row['contract_no'] = trim((string)($d['contract_no'] ?? '')) ?: Seq::next('cs_contract');
                if (DB::value('SELECT id FROM cs_contracts WHERE contract_no = :n', ['n' => $row['contract_no']])) {
                    fail('Contract number ' . $row['contract_no'] . ' is already used.');
                }
                $row['status'] = 'draft';
                $row['created_by'] = Auth::id();
                $id = DB::insert('cs_contracts', $row);
            }
            DB::run('DELETE FROM cs_contract_bonds WHERE contract_id = :c', ['c' => $id]);
            foreach ($bonds as $b) {
                if (trim((string)($b['kind'] ?? '')) === '' || (trim((string)($b['reference_no'] ?? '')) === '' && to_num($b['amount'] ?? 0) == 0 && trim((string)($b['issuer'] ?? '')) === '')) {
                    continue;
                }
                DB::insert('cs_contract_bonds', ['contract_id' => $id, 'kind' => isset(self::BOND_KINDS[$b['kind']]) ? $b['kind'] : 'other',
                    'reference_no' => mb_substr(trim((string)($b['reference_no'] ?? '')), 0, 60) ?: null, 'issuer' => mb_substr(trim((string)($b['issuer'] ?? '')), 0, 150) ?: null,
                    'amount' => r2(to_num($b['amount'] ?? 0)), 'issue_date' => valid_date((string)($b['issue_date'] ?? '')) ? $b['issue_date'] : null,
                    'expiry_date' => valid_date((string)($b['expiry_date'] ?? '')) ? $b['expiry_date'] : null,
                    'status' => in_array($b['status'] ?? '', ['active', 'released', 'expired', 'claimed'], true) ? $b['status'] : 'active',
                    'notes' => mb_substr(trim((string)($b['notes'] ?? '')), 0, 255) ?: null]);
            }
            $new = self::get($id);
            Audit::log($old ? 'edit' : 'create', 'proj', 'contract', $id, $new['contract_no'] . ' ' . $new['title'], ['page_key' => 'proj.contracts',
                'amount' => $new['original_amount'], 'before' => $old ?: [], 'after' => $new]);
            return $id;
        });
    }

    /** Approval events of contracts and change orders */
    public static function workflow(string $type, string $event, int $id, array $req): void
    {
        if ($type === 'contract') {
            $status = ['submitted' => 'for_approval', 'approved' => 'active', 'rejected' => 'rejected', 'returned' => 'draft', 'cancelled' => 'draft'][$event] ?? null;
            if ($status) {
                $row = ['status' => $status];
                if ($event === 'approved') {
                    $row += ['approved_by' => Auth::id(), 'approved_at' => date('Y-m-d H:i:s')];
                }
                DB::update('cs_contracts', $row, 'id = :id', ['id' => $id]);
            }
            return;
        }
        if ($type === 'change_order') {
            $status = ['submitted' => 'for_approval', 'approved' => 'internally_approved', 'rejected' => 'rejected', 'returned' => 'proposed', 'cancelled' => 'proposed'][$event] ?? null;
            if ($status) {
                $row = ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')];
                if ($event === 'approved') {
                    $row += ['internal_approved_by' => Auth::id(), 'internal_approved_at' => date('Y-m-d H:i:s')];
                }
                DB::update('cs_change_orders', $row, 'id = :id', ['id' => $id]);
            }
        }
    }

    public static function contractStatus(int $id, string $status, string $reason): void
    {
        $k = self::get($id);
        if (!$k || !in_array($status, ['active', 'suspended', 'completed', 'closed', 'terminated'], true)) {
            fail('Unknown contract or status.');
        }
        if (in_array($k['status'], ['draft', 'for_approval', 'rejected'], true)) {
            fail('The contract has to be approved first.');
        }
        DB::update('cs_contracts', ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
        Audit::log('status', 'proj', 'contract', $id, $k['contract_no'], ['page_key' => 'proj.contracts', 'reason' => $reason,
            'changes' => [['field' => 'status', 'old' => $k['status'], 'new' => $status]]]);
    }

    // ------------------------------------------------------------------ change orders

    public static function getCo(int $id): ?array
    {
        return DB::row('SELECT o.*, p.code AS project_code, p.name AS project_name FROM cs_change_orders o JOIN cs_projects p ON p.id = o.project_id WHERE o.id = :id', ['id' => $id]);
    }

    public static function saveCo(array $d, array $lines, ?int $id = null): int
    {
        return DB::tx(function () use ($d, $lines, $id) {
            $old = $id ? self::getCo($id) : null;
            if ($old && !in_array($old['status'], ['proposed', 'disputed'], true)) {
                fail('Only a proposed or disputed change order can be edited. Its approvals are kept with it.');
            }
            $projectId = (int)($d['project_id'] ?? ($old['project_id'] ?? 0));
            $p = CsProject::get($projectId);
            if (!$p) {
                fail('Choose the project.');
            }
            $clean = [];
            $price = 0.0;
            $cost = 0.0;
            foreach ($lines as $l) {
                if (trim((string)($l['description'] ?? '')) === '') {
                    continue;
                }
                $qty = to_num($l['qty'] ?? 0);
                $rate = to_num($l['unit_rate'] ?? 0);
                $amt = isset($l['amount']) && $l['amount'] !== '' ? r2(to_num($l['amount'])) : r2($qty * $rate);
                $c = r2(to_num($l['cost_amount'] ?? 0));
                $clean[] = ['description' => mb_substr(trim((string)$l['description']), 0, 255), 'unit' => mb_substr(trim((string)($l['unit'] ?? '')), 0, 20) ?: null,
                    'qty' => $qty, 'unit_rate' => $rate, 'amount' => $amt, 'cost_amount' => $c, 'cost_code_id' => (int)($l['cost_code_id'] ?? 0) ?: null];
                $price += $amt;
                $cost += $c;
            }
            $row = ['project_id' => $projectId, 'contract_id' => $p['contract_id'], 'co_type' => isset(self::CO_TYPES[$d['co_type'] ?? '']) ? $d['co_type'] : 'client_request',
                'title' => trim((string)($d['title'] ?? '')), 'reason' => trim((string)($d['reason'] ?? '')) ?: null, 'description' => trim((string)($d['description'] ?? '')) ?: null,
                'requested_by_party' => in_array($d['requested_by_party'] ?? '', ['client', 'contractor', 'consultant'], true) ? $d['requested_by_party'] : 'client',
                'price_impact' => $clean ? r2($price) : r2(to_num($d['price_impact'] ?? 0)), 'cost_impact' => $clean ? r2($cost) : r2(to_num($d['cost_impact'] ?? 0)),
                'labor_impact' => mb_substr(trim((string)($d['labor_impact'] ?? '')), 0, 255) ?: null, 'equipment_impact' => mb_substr(trim((string)($d['equipment_impact'] ?? '')), 0, 255) ?: null,
                'schedule_impact_days' => (int)($d['schedule_impact_days'] ?? 0), 'billing_implications' => trim((string)($d['billing_implications'] ?? '')) ?: null];
            if ($row['title'] === '') {
                fail('Enter the title of the change order.');
            }
            if ($old) {
                $row['updated_at'] = date('Y-m-d H:i:s');
                DB::update('cs_change_orders', $row, 'id = :id', ['id' => $id]);
            } else {
                $row['co_no'] = Seq::next('cs_change_order');
                $row['status'] = 'proposed';
                $row['created_by'] = Auth::id();
                $id = DB::insert('cs_change_orders', $row);
            }
            DB::run('DELETE FROM cs_co_lines WHERE co_id = :c', ['c' => $id]);
            foreach ($clean as $i => $l) {
                DB::insert('cs_co_lines', $l + ['co_id' => $id, 'line_no' => $i + 1]);
            }
            $new = self::getCo($id);
            Audit::log($old ? 'edit' : 'create', 'proj', 'change_order', $id, $new['co_no'] . ' ' . $new['title'], ['page_key' => 'proj.change_orders',
                'amount' => $new['price_impact'], 'before' => $old ?: [], 'after' => $new]);
            return $id;
        });
    }

    /**
     * The client approved the change order: from now on it is part of the revised contract value, its lines join the
     * schedule of values (so they can be billed), the revised completion date moves by its time impact, and a budget
     * revision is prepared for the cost impact (it still needs its own approval).
     */
    public static function clientApprove(int $id, string $date, string $ref, ?float $settlement = null): void
    {
        DB::tx(function () use ($id, $date, $ref, $settlement) {
            $co = self::getCo($id);
            if (!$co) {
                fail('Change order not found.');
            }
            if (!in_array($co['status'], ['internally_approved', 'disputed'], true)) {
                fail('The change order has to be approved internally before the client\'s approval is recorded.');
            }
            if (!valid_date($date)) {
                fail('Enter the date the client approved it.');
            }
            $upd = ['status' => 'client_approved', 'client_approved_date' => $date, 'client_ref' => mb_substr($ref, 0, 60) ?: null, 'updated_at' => date('Y-m-d H:i:s')];
            if ($settlement !== null) {
                $upd['settlement_amount'] = r2($settlement);
                $upd['price_impact'] = r2($settlement);
            }
            DB::update('cs_change_orders', $upd, 'id = :id', ['id' => $id]);
            $co = self::getCo($id);
            // the schedule of values
            $lines = DB::all('SELECT * FROM cs_co_lines WHERE co_id = :c ORDER BY line_no', ['c' => $id]);
            $sort = (int)DB::value('SELECT COALESCE(MAX(sort_order),0) FROM cs_boq_items WHERE project_id = :p', ['p' => $co['project_id']]);
            if ($lines && $settlement === null) {
                foreach ($lines as $i => $l) {
                    DB::insert('cs_boq_items', ['project_id' => $co['project_id'], 'contract_id' => $co['contract_id'], 'item_no' => $co['co_no'] . '-' . ($i + 1),
                        'description' => $l['description'], 'unit' => $l['unit'], 'qty' => $l['qty'], 'unit_rate' => $l['unit_rate'], 'amount' => $l['amount'],
                        'cost_code_id' => $l['cost_code_id'], 'is_variation' => 1, 'change_order_id' => $id, 'sort_order' => $sort += 10]);
                }
            } elseif (abs((float)$co['price_impact']) >= 0.005) {
                DB::insert('cs_boq_items', ['project_id' => $co['project_id'], 'contract_id' => $co['contract_id'], 'item_no' => $co['co_no'],
                    'description' => mb_substr('Variation: ' . $co['title'], 0, 255), 'unit' => 'lot', 'qty' => 1, 'unit_rate' => $co['price_impact'], 'amount' => $co['price_impact'],
                    'is_variation' => 1, 'change_order_id' => $id, 'sort_order' => $sort + 10]);
            }
            // the contract's revised value
            if ($co['contract_id']) {
                $sum = (float)DB::value("SELECT COALESCE(SUM(price_impact),0) FROM cs_change_orders WHERE contract_id = :k AND status = 'client_approved'", ['k' => $co['contract_id']]);
                DB::update('cs_contracts', ['approved_variations' => r2($sum), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $co['contract_id']]);
            }
            // the completion date
            if ((int)$co['schedule_impact_days'] !== 0) {
                $p = CsProject::get((int)$co['project_id']);
                $base = $p['revised_end_date'] ?: $p['target_end_date'];
                if ($base) {
                    DB::update('cs_projects', ['revised_end_date' => date('Y-m-d', strtotime($base . ' ' . ((int)$co['schedule_impact_days'] >= 0 ? '+' : '') . (int)$co['schedule_impact_days'] . ' days'))],
                        'id = :id', ['id' => $co['project_id']]);
                }
                if ($co['contract_id']) {
                    $k = self::get((int)$co['contract_id']);
                    $kb = $k['revised_completion_date'] ?: $k['original_completion_date'];
                    if ($kb) {
                        DB::update('cs_contracts', ['revised_completion_date' => date('Y-m-d', strtotime($kb . ' +' . (int)$co['schedule_impact_days'] . ' days'))], 'id = :id', ['id' => $co['contract_id']]);
                    }
                }
            }
            // a budget revision for the cost impact (draft: it goes through its own approval)
            if (abs((float)$co['cost_impact']) >= 0.005) {
                $bid = CsPlanning::reviseFromChangeOrder($co);
                if ($bid) {
                    DB::update('cs_change_orders', ['budget_id' => $bid], 'id = :id', ['id' => $id]);
                }
            }
            CsProject::flush();
            Audit::log('client_approve', 'proj', 'change_order', $id, $co['co_no'] . ' ' . $co['title'], ['page_key' => 'proj.change_orders',
                'amount' => $co['price_impact'], 'reason' => 'Client approval ' . $ref . ' dated ' . fdate($date) . '; added to the revised contract value and the schedule of values']);
        });
    }

    public static function coStatus(int $id, string $status, string $reason): void
    {
        $co = self::getCo($id);
        if (!$co) {
            fail('Change order not found.');
        }
        $allowed = ['disputed' => ['internally_approved', 'proposed'], 'withdrawn' => ['proposed', 'internally_approved', 'disputed'], 'rejected' => ['internally_approved', 'disputed']];
        if (!isset($allowed[$status]) || !in_array($co['status'], $allowed[$status], true)) {
            fail('That change is not possible from ' . CsUi::label($co['status']) . '.');
        }
        if (trim($reason) === '') {
            fail('Enter the reason.');
        }
        DB::update('cs_change_orders', ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
        Audit::log('status', 'proj', 'change_order', $id, $co['co_no'], ['page_key' => 'proj.change_orders', 'reason' => $reason,
            'changes' => [['field' => 'status', 'old' => $co['status'], 'new' => $status]]]);
    }
}
