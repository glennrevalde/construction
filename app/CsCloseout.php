<?php
declare(strict_types=1);

/**
 * Close-out, financial closure and warranty. A project is closed only when nothing is left open: purchase orders,
 * receivables, payables, retention both ways, documents waiting for approval or posting, punch-list items and the
 * close-out checklist. Closing with something still open needs a documented exception by an authorized user, and
 * financial closure itself goes through approval.
 */
final class CsCloseout
{
    public const PUNCH_STATUSES = ['open' => 'Open', 'in_progress' => 'In progress', 'rectified' => 'Rectified', 'verified' => 'Verified', 'closed' => 'Closed'];

    /** What still keeps the project from being closed: list of [what, figure, page key, params] */
    public static function issues(int $projectId): array
    {
        $p = CsProject::get($projectId);
        if (!$p) {
            return [];
        }
        CsSummary::flush();
        $s = CsSummary::project($projectId);
        $cid = (int)$p['class_id'];
        $out = [];
        $openPo = (int)DB::value("SELECT COUNT(DISTINCT t.id) FROM transactions t LEFT JOIN transaction_lines l ON l.txn_id = t.id
                                    WHERE t.txn_type = 'purchase_order' AND t.is_voided = 0 AND t.status NOT IN ('closed','void')
                                      AND (t.class_id = :c OR l.class_id = :c2)", ['c' => $cid, 'c2' => $cid]);
        if ($openPo) {
            $out[] = ['Open purchase orders', $openPo . ' (' . money($s['committed']['po']) . ' not received)', 'vend.purchase_orders', []];
        }
        if ($s['ar_open'] > 0.5) {
            $out[] = ['Receivables not collected', money($s['ar_open']), 'pbill.collections', ['project' => $projectId]];
        }
        if ($s['ap_open'] > 0.5) {
            $out[] = ['Supplier and subcontractor bills not paid', money($s['ap_open']), 'vend.pay_bills', []];
        }
        if (abs($s['retention_receivable']) > 0.5) {
            $out[] = ['Retention receivable not yet billed or collected', money($s['retention_receivable']), 'pbill.retention', ['project' => $projectId]];
        }
        if (abs($s['retention_payable']) > 0.5) {
            $out[] = ['Retention held from subcontractors not yet released', money($s['retention_payable']), 'subk.billings', ['project' => $projectId]];
        }
        if (abs($s['advances_unrecouped']) > 0.5) {
            $out[] = ['Client advance not fully recouped', money($s['advances_unrecouped']), 'pbill.billings', ['project' => $projectId]];
        }
        if ($s['approvals_pending']) {
            $out[] = ['Documents waiting for approval', (string)$s['approvals_pending'], 'appr.inbox', []];
        }
        $unposted = [
            'Timesheets not posted' => ["SELECT COUNT(*) FROM cs_timesheets WHERE project_id = :p AND status IN ('draft','for_approval','approved')", 'labor.timesheets'],
            'Equipment logs not posted' => ["SELECT COUNT(*) FROM cs_equipment_logs WHERE project_id = :p AND status IN ('draft','for_approval','approved')", 'equip.logs'],
            'Material issues and returns not posted' => ["SELECT COUNT(*) FROM cs_material_docs WHERE (project_id = :p) AND status IN ('draft','for_approval','approved')", 'proc.materials'],
            'Subcontractor billings not posted' => ["SELECT COUNT(*) FROM cs_sub_billings WHERE project_id = :p AND status IN ('draft','for_approval','approved')", 'subk.billings'],
            'Progress billings not invoiced' => ["SELECT COUNT(*) FROM cs_billings WHERE project_id = :p AND status IN ('draft','for_approval','approved','submitted','certified','returned')", 'pbill.billings'],
            'Material requests still open' => ["SELECT COUNT(*) FROM cs_material_requests WHERE project_id = :p AND status IN ('draft','for_approval','approved','partially_ordered')", 'proc.requests'],
            'Change orders not settled' => ["SELECT COUNT(*) FROM cs_change_orders WHERE project_id = :p AND status IN ('proposed','for_approval','internally_approved','disputed')", 'proj.change_orders'],
            'Allocations waiting' => ["SELECT COUNT(*) FROM cs_allocations a JOIN cs_allocation_lines l ON l.allocation_id = a.id WHERE l.project_id = :p AND a.status IN ('draft','for_approval')", 'cost.allocations'],
            'Punch-list items and defects open' => ["SELECT COUNT(*) FROM cs_punch_items WHERE project_id = :p AND status NOT IN ('verified','closed')", 'proj.closeout'],
            'Close-out checklist items not done' => ["SELECT COUNT(*) FROM cs_closeout_items WHERE project_id = :p AND status NOT IN ('completed','waived')", 'proj.closeout'],
            'Non-conformances open' => ["SELECT COUNT(*) FROM cs_ncrs WHERE project_id = :p AND status <> 'closed'", 'site.ncr'],
            'Subcontracts not closed' => ["SELECT COUNT(*) FROM cs_subcontracts WHERE project_id = :p AND status IN ('draft','for_approval','active')", 'subk.contracts'],
        ];
        foreach ($unposted as $label => [$sql, $page]) {
            $n = (int)DB::value($sql, ['p' => $projectId]);
            if ($n) {
                $out[] = [$label, (string)$n, $page, ['project' => $projectId]];
            }
        }
        $last = DB::value("SELECT MAX(period_end) FROM cs_revenue_recognitions WHERE project_id = :p AND status = 'posted'", ['p' => $projectId]);
        if (Settings::get('cs_revenue_method', 'poc_cost') !== 'billing' && abs($s['contract_revenue'] - $s['certified_gross']) > 0.5 && in_array($p['status'], ['completed', 'under_warranty', 'substantially_completed'], true)) {
            $out[] = ['Revenue recognized differs from the final billed value (run the final revenue recognition)', money($s['contract_revenue']) . ' vs ' . money($s['certified_gross']), 'pbill.revenue', ['project' => $projectId]];
        } elseif (!$last && Settings::get('cs_revenue_method', 'poc_cost') !== 'billing' && $s['cost_direct'] > 0) {
            $out[] = ['No revenue recognition posted yet', '', 'pbill.revenue', ['project' => $projectId]];
        }
        return $out;
    }

    /**
     * Refuses to close while something is open, unless an authorized user gives the reason for the exception
     * (the reason is kept on the project and in the audit trail).
     */
    public static function assertClosable(int $projectId, string $reason = ''): void
    {
        $p = CsProject::get($projectId);
        if (!in_array($p['status'], ['completed', 'under_warranty', 'substantially_completed', 'closed'], true)) {
            fail('Only a completed project is closed. Mark it completed first.');
        }
        $issues = self::issues($projectId);
        if (!$issues) {
            return;
        }
        $reason = trim($reason) ?: trim((string)$p['closure_override']);
        if ($reason === '') {
            fail('The project cannot be closed yet: ' . implode('; ', array_map(fn($i) => $i[0] . ($i[1] !== '' ? ' (' . $i[1] . ')' : ''), $issues))
                . '. Clear them, or record a documented exception.');
        }
        if (!Access::isAdmin() && !Access::can('proj.closeout', 'approve')) {
            fail('Closing with open items needs an authorized user (Approve on Close-out).');
        }
    }

    /** Approval events of the financial closure (doc id = project id) */
    public static function workflow(string $type, string $event, int $id, array $req): void
    {
        if ($type !== 'closeout') {
            return;
        }
        if ($event === 'approved') {
            $p = CsProject::get($id);
            CsProject::setStatus($id, 'closed', (string)($p['closure_override'] ?? ''));
            CsNotify::send(array_filter([(int)$p['pm_user_id']]), 'closeout', 'Project ' . $p['code'] . ' is financially closed', '', url('proj.closeout', ['project' => $id]), $id);
        }
    }

    /** Starts the warranty / defect-liability period from the completion date and the contract's warranty months */
    public static function startWarranty(int $projectId, ?string $from = null): string
    {
        $p = CsProject::get($projectId);
        $from = $from ?: ($p['actual_end_date'] ?: today());
        $months = (int)DB::value('SELECT GREATEST(warranty_months, dlp_months) FROM cs_contracts WHERE id = :k', ['k' => (int)$p['contract_id']]) ?: 12;
        $end = date('Y-m-d', strtotime($from . ' +' . $months . ' months'));
        DB::update('cs_projects', ['warranty_end_date' => $end, 'actual_end_date' => $p['actual_end_date'] ?: $from, 'status' => 'under_warranty', 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $projectId]);
        CsProject::flush();
        Audit::log('warranty', 'proj', 'project', $projectId, $p['code'], ['page_key' => 'proj.closeout', 'reason' => 'Warranty / defect liability from ' . fdate($from) . ' to ' . fdate($end)]);
        return $end;
    }

    public static function savePunch(int $projectId, array $d, ?int $id = null): int
    {
        $row = ['kind' => ($d['kind'] ?? '') === 'defect' ? 'defect' : 'punch', 'location' => mb_substr(trim((string)($d['location'] ?? '')), 0, 150) ?: null,
            'description' => mb_substr(trim((string)($d['description'] ?? '')), 0, 255), 'responsible_party' => mb_substr(trim((string)($d['responsible_party'] ?? '')), 0, 150) ?: null,
            'reported_date' => valid_date((string)($d['reported_date'] ?? '')) ? $d['reported_date'] : today(), 'due_date' => valid_date((string)($d['due_date'] ?? '')) ? $d['due_date'] : null,
            'severity' => in_array($d['severity'] ?? '', ['low', 'medium', 'high', 'critical'], true) ? $d['severity'] : 'medium', 'warranty_covered' => !empty($d['warranty_covered']) ? 1 : 0,
            'cost' => to_num($d['cost'] ?? 0), 'remarks' => mb_substr(trim((string)($d['remarks'] ?? '')), 0, 255) ?: null];
        if ($row['description'] === '') {
            fail('Describe the item.');
        }
        $isNew = !$id;
        if ($id) {
            DB::update('cs_punch_items', $row, 'id = :id AND project_id = :p', ['id' => $id, 'p' => $projectId]);
        } else {
            $id = DB::insert('cs_punch_items', $row + ['project_id' => $projectId, 'ref_no' => Seq::next('cs_punch'), 'status' => 'open', 'created_by' => Auth::id()]);
        }
        Audit::log($isNew ? 'create' : 'edit', 'proj', 'punch', $id, $row['description'], ['page_key' => 'proj.closeout']);
        return $id;
    }

    public static function punchStatus(int $id, string $status, string $remarks = ''): void
    {
        $it = DB::row('SELECT * FROM cs_punch_items WHERE id = :id', ['id' => $id]);
        if (!$it || !isset(self::PUNCH_STATUSES[$status])) {
            fail('Unknown item or status.');
        }
        if ($status === 'closed' && !in_array($it['status'], ['verified', 'rectified'], true)) {
            fail('An item is closed once its rectification is done and verified.');
        }
        $row = ['status' => $status, 'remarks' => trim($remarks) !== '' ? mb_substr($remarks, 0, 255) : $it['remarks']];
        if (in_array($status, ['closed', 'verified'], true) && !$it['closed_date']) {
            $row['closed_date'] = today();
        }
        DB::update('cs_punch_items', $row, 'id = :id', ['id' => $id]);
        Audit::log('status', 'proj', 'punch', $id, $it['ref_no'], ['page_key' => 'proj.closeout', 'reason' => CsUi::label($status) . ($remarks !== '' ? ': ' . $remarks : ''),
            'changes' => [['field' => 'status', 'old' => $it['status'], 'new' => $status]]]);
    }
}
