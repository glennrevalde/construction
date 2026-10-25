<?php
declare(strict_types=1);

/**
 * The construction documents that go through approval: what each is called, where it is opened, which table holds
 * it, and which class reacts when it is approved, rejected or returned (CsXxx::workflow($event, $id)).
 */
final class CsDocs
{
    /** type => [label, page key, table, number column, handler class] */
    public const TYPES = [
        'contract'          => ['Contract', 'proj.contracts', 'cs_contracts', 'contract_no', 'CsContracts'],
        'estimate'          => ['Estimate', 'plan.estimates', 'cs_estimates', 'estimate_no', 'CsPlanning'],
        'budget'            => ['Project budget', 'plan.budgets', 'cs_budgets', 'name', 'CsPlanning'],
        'change_order'      => ['Change order', 'proj.change_orders', 'cs_change_orders', 'co_no', 'CsContracts'],
        'material_request'  => ['Material request', 'proc.requests', 'cs_material_requests', 'mr_no', 'CsMaterials'],
        'purchase'          => ['Canvass award and purchase order', 'proc.canvass', 'cs_canvass', 'canvass_no', 'CsMaterials'],
        'material_transfer' => ['Material transfer', 'proc.materials', 'cs_material_docs', 'doc_no', 'CsMaterials'],
        'timesheet'         => ['Timesheet', 'labor.timesheets', 'cs_timesheets', 'ts_no', 'CsLabor'],
        'payroll'           => ['Payroll run', 'labor.payroll', 'cs_payroll_runs', 'run_no', 'CsLabor'],
        'cash_advance'      => ['Cash advance', 'labor.advances', 'cs_cash_advances', 'ca_no', 'CsLabor'],
        'equipment_log'     => ['Equipment usage', 'equip.logs', 'cs_equipment_logs', 'log_no', 'CsEquip'],
        'daily_report'      => ['Daily site report', 'site.daily', 'cs_daily_reports', 'report_no', 'CsSite'],
        'subcontract'       => ['Subcontract agreement', 'subk.contracts', 'cs_subcontracts', 'sc_no', 'CsSub'],
        'sub_billing'       => ['Subcontractor billing', 'subk.billings', 'cs_sub_billings', 'sb_no', 'CsSub'],
        'billing'           => ['Progress billing', 'pbill.billings', 'cs_billings', 'billing_no', 'CsBilling'],
        'revenue'           => ['Revenue recognition', 'pbill.revenue', 'cs_revenue_recognitions', 'rr_no', 'CsBilling'],
        'allocation'        => ['Cost allocation', 'cost.allocations', 'cs_allocations', 'alloc_no', 'CsAlloc'],
        'closeout'          => ['Financial closure', 'proj.closeout', 'cs_projects', 'code', 'CsCloseout'],
    ];

    public static function label(string $type): string
    {
        return self::TYPES[$type][0] ?? ucfirst(str_replace('_', ' ', $type));
    }

    public static function page(string $type): string
    {
        return self::TYPES[$type][1] ?? 'appr.inbox';
    }

    public static function url(string $type, int $id): string
    {
        if ($type === 'closeout') {
            return url('proj.closeout', ['project' => $id]);
        }
        return url(self::page($type), ['a' => 'view', 'id' => $id]);
    }

    /** Tells the document's own class what happened ('submitted', 'approved', 'rejected', 'returned', 'cancelled') */
    public static function notify(string $type, int $id, string $event, array $request = []): void
    {
        $cls = self::TYPES[$type][4] ?? null;
        if ($cls && method_exists($cls, 'workflow')) {
            $cls::workflow($type, $event, $id, $request);
        }
    }
}
