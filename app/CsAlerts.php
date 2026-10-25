<?php
declare(strict_types=1);

/**
 * Alerts worked out from the records each time they are looked at (so they disappear by themselves once the
 * cause is dealt with): approvals that are late, billings and collections that are due, unpaid supplier invoices,
 * low stock, budget overruns and spending past budget, labor-hour anomalies, equipment maintenance and booking
 * clashes, late milestones and missing deliverables, expiring contracts and bonds, unapproved change orders, open
 * quality and safety incidents, warranties about to end and projects heading for a loss.
 * Only the projects the user may see are looked at.
 */
final class CsAlerts
{
    private static ?array $all = null;

    public static function count(): int
    {
        return count(self::all()) + CsNotify::unread((int)Auth::id());
    }

    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }
        $out = [];
        $add = function (string $kind, string $tone, string $icon, string $title, string $detail, ?string $link, ?int $projectId = null) use (&$out) {
            $out[] = compact('kind', 'tone', 'icon', 'title', 'detail', 'link') + ['project_id' => $projectId];
        };
        $ids = CsProject::accessibleIds();
        [$pf, $pp] = CsProject::filter('p.id', 'alp');
        $today = today();
        $soon = date('Y-m-d', strtotime('+7 days'));
        $month = date('Y-m-d', strtotime('+30 days'));
        $projects = DB::all("SELECT p.* FROM cs_projects p WHERE p.status <> 'closed' $pf", $pp);
        $byId = [];
        foreach ($projects as $p) {
            $byId[(int)$p['id']] = $p;
        }
        $pname = fn($pid) => isset($byId[$pid]) ? $byId[$pid]['code'] . ' ' . $byId[$pid]['name'] : '';

        // approvals waiting past their due time
        if (Access::page('appr.inbox')) {
            foreach (DB::all("SELECT * FROM cs_approval_requests WHERE status = 'pending' AND due_at < NOW() ORDER BY due_at LIMIT 30") as $r) {
                if ($r['project_id'] && $ids !== null && !in_array((int)$r['project_id'], $ids, true)) {
                    continue;
                }
                $s = CsApproval::currentStep($r);
                $add('approval', 'warn', 'hourglass-split', 'Approval overdue: ' . CsDocs::label($r['doc_type']) . ' ' . $r['doc_ref'],
                    'Waiting for ' . CsApproval::stepWho($s, $r) . ' since ' . fdatetime($r['step_since']), CsDocs::url($r['doc_type'], (int)$r['doc_id']), $r['project_id'] ? (int)$r['project_id'] : null);
            }
        }
        foreach ($projects as $p) {
            $pid = (int)$p['id'];
            if (!in_array($p['status'], CsProject::OPEN, true) && $p['status'] !== 'under_warranty') {
                continue;
            }
            $s = CsSummary::project($pid);
            if ($s['projected_profit'] < 0) {
                $add('loss', 'bad', 'graph-down-arrow', 'Projected loss on ' . $pname($pid), 'Estimated final cost ' . money($s['eac']) . ' against a contract value of ' . money($s['contract_current']) . ' (' . money($s['projected_profit']) . ').', url('proj.view', ['id' => $pid, 'tab' => 'cost']), $pid);
            } elseif ($s['over_budget']) {
                $add('budget', 'bad', 'exclamation-octagon', 'Over budget: ' . $pname($pid), 'Estimated final cost ' . money($s['eac']) . ' against an approved budget of ' . money($s['budget_current']) . '.', url('proj.view', ['id' => $pid, 'tab' => 'cost']), $pid);
            }
            if ($s['behind'] && in_array($p['status'], ['active', 'mobilization', 'on_hold'], true)) {
                $add('schedule', 'warn', 'calendar-x', 'Behind schedule: ' . $pname($pid), CsUi::pct($s['physical_pct']) . ' done' . ($s['planned_pct'] !== null ? ' against ' . CsUi::pct($s['planned_pct']) . ' planned' : '') . ($s['forecast_end'] ? ', forecast to finish ' . fdate($s['forecast_end']) : '') . '.', url('plan.schedule', ['project' => $pid]), $pid);
            }
            // spending past the budget of a cost code
            if ($s['budget_current'] > 0) {
                foreach (CsCost::forecast($pid)['rows'] as $cc => $r) {
                    if ($r['actual'] > $r['budget'] + 0.5 && $cc) {
                        $add('spending', 'bad', 'cash-stack', 'Spent past budget: ' . CsCost::codeLabel((int)$cc), $pname($pid) . ': ' . money($r['actual']) . ' spent against ' . money($r['budget']) . ' budgeted.', url('proj.view', ['id' => $pid, 'tab' => 'cost']), $pid);
                    } elseif ($r['budget'] == 0.0 && $r['actual'] > 0.5 && $cc) {
                        $add('spending', 'warn', 'cash-stack', 'Spending with no budget: ' . CsCost::codeLabel((int)$cc), $pname($pid) . ': ' . money($r['actual']) . ' charged to a cost code that has no approved budget.', url('proj.view', ['id' => $pid, 'tab' => 'cost']), $pid);
                    }
                }
            }
            // billing that is due: active work and no billing for 35 days
            if ($p['status'] === 'active' && $s['physical_pct'] > 0 && $s['contract_current'] > 0) {
                $last = DB::value("SELECT MAX(billing_date) FROM cs_billings WHERE project_id = :p AND status NOT IN ('rejected','cancelled')", ['p' => $pid]);
                if ((!$last || $last < date('Y-m-d', strtotime('-35 days'))) && $s['work_done_value'] - $s['billed_gross'] > 0.02 * $s['contract_current']) {
                    $add('billing', 'warn', 'receipt', 'Progress billing due: ' . $pname($pid), money($s['work_done_value'] - $s['billed_gross']) . ' of completed work is not billed yet' . ($last ? '; last billing ' . fdate($last) : '') . '.', url('pbill.billings', ['a' => 'new', 'project' => $pid]), $pid);
                }
            }
            if ($p['warranty_end_date'] && $p['warranty_end_date'] >= $today && $p['warranty_end_date'] <= date('Y-m-d', strtotime('+60 days'))) {
                $add('warranty', 'warn', 'shield-exclamation', 'Warranty ends ' . fdate($p['warranty_end_date']) . ': ' . $pname($pid), 'Release of retention and warranty bonds falls due; close the open defects first.', url('proj.closeout', ['project' => $pid]), $pid);
            }
        }
        // billings submitted and not certified for 30 days
        foreach (DB::all("SELECT b.* FROM cs_billings b JOIN cs_projects p ON p.id = b.project_id WHERE b.status = 'submitted' AND b.billing_date < :d $pf", ['d' => date('Y-m-d', strtotime('-30 days'))] + $pp) as $b) {
            $add('billing', 'warn', 'receipt', 'Billing ' . $b['billing_no'] . ' not certified yet', 'Submitted to the client on ' . fdate($b['billing_date']) . ' for ' . money($b['amount_applied']) . '.', url('pbill.billings', ['a' => 'view', 'id' => $b['id']]), (int)$b['project_id']);
        }
        // collections due and overdue (project invoices)
        $classes = array_map(fn($p) => (int)$p['class_id'], $projects);
        if ($classes && Access::page('pbill.collections')) {
            [$cin, $cp] = DB::in('alc', $classes);
            foreach (DB::all("SELECT t.*, c.name AS customer_name FROM transactions t JOIN customers c ON c.id = t.customer_id
                               WHERE t.txn_type = 'invoice' AND t.is_voided = 0 AND t.status IN ('open','partial') AND t.class_id IN $cin AND t.due_date <= :soon ORDER BY t.due_date LIMIT 40", $cp + ['soon' => $soon]) as $t) {
                $open = Balances::open($t);
                if ($open < 0.01) {
                    continue;
                }
                $late = $t['due_date'] < $today;
                $prj = CsProject::byClass((int)$t['class_id']);
                $add('collection', $late ? 'bad' : 'warn', 'cash-coin', ($late ? 'Overdue: ' : 'Due ' . fdate($t['due_date']) . ': ') . $t['txn_no'] . ' ' . $t['customer_name'],
                    money($open) . ' open' . ($late ? ', due ' . fdate($t['due_date']) . ' (' . (int)((strtotime($today) - strtotime($t['due_date'])) / 86400) . ' days)' : '') . '.', Txn::url($t), $prj ? (int)$prj['id'] : null);
            }
        }
        // supplier invoices due within a week or overdue
        if (Access::page('vend.pay_bills')) {
            $n = 0;
            $amt = 0.0;
            $late = 0;
            foreach (Balances::openPayables(null, $soon) as $b) {
                $n++;
                $amt += $b['open_balance'];
                $late += $b['due_date'] && $b['due_date'] < $today ? 1 : 0;
            }
            if ($n) {
                $add('payable', $late ? 'bad' : 'warn', 'wallet2', $n . ' supplier invoice(s) due within a week', money($amt) . ' to pay' . ($late ? ', ' . $late . ' already overdue' : '') . '.', url('vend.pay_bills'));
            }
        }
        // low stock
        if (Access::page('inv.center') || Access::page('proc.stock')) {
            $low = DB::all("SELECT i.code, i.name, i.reorder_point, COALESCE((SELECT SUM(qty_on_hand) FROM item_site_stock s WHERE s.item_id = i.id),0) AS qoh
                              FROM items i WHERE i.item_type = 'inventory' AND i.is_active = 1 AND i.reorder_point > 0
                            HAVING qoh <= i.reorder_point ORDER BY i.code LIMIT 10");
            if ($low) {
                $add('stock', 'warn', 'box-seam', count($low) . ' material(s) at or below reorder level', implode(', ', array_map(fn($r) => $r['code'] . ' (' . qty($r['qoh']) . ')', $low)) . '.', url('rpt.stock_status_item'));
            }
        }
        // labor-hour anomalies of the last 14 days
        $max = (float)Settings::get('cs_max_daily_hours', '16');
        foreach (DB::all("SELECT l.worker_id, l.work_date, w.full_name, SUM(l.regular_hours + l.ot_hours) AS hrs, MIN(t.project_id) AS project_id
                            FROM cs_timesheet_lines l JOIN cs_timesheets t ON t.id = l.timesheet_id JOIN cs_workers w ON w.id = l.worker_id
                           WHERE l.work_date >= :d AND t.status <> 'rejected' GROUP BY l.worker_id, l.work_date, w.full_name HAVING hrs > :m OR hrs > 12
                           ORDER BY l.work_date DESC LIMIT 10", ['d' => date('Y-m-d', strtotime('-14 days')), 'm' => min($max, 12)]) as $r) {
            if ($ids !== null && !in_array((int)$r['project_id'], $ids, true)) {
                continue;
            }
            $add('labor', 'warn', 'clock-history', 'Unusual hours: ' . $r['full_name'], number_format((float)$r['hrs'], 1) . ' hours on ' . fdate($r['work_date']) . '.', url('labor.timesheets', ['worker' => $r['worker_id']]), (int)$r['project_id']);
        }
        // equipment: service by meter, inspections, insurance, booking clashes
        if (Access::page('equip.register')) {
            foreach (DB::all("SELECT * FROM cs_equipment WHERE status NOT IN ('disposed','off_hired') AND ((next_service_meter IS NOT NULL AND current_meter >= next_service_meter)
                               OR (inspection_due IS NOT NULL AND inspection_due <= :m) OR (insurance_expiry IS NOT NULL AND insurance_expiry <= :m2) OR (registration_expiry IS NOT NULL AND registration_expiry <= :m3))",
                ['m' => $month, 'm2' => $month, 'm3' => $month]) as $e) {
                $why = [];
                if ($e['next_service_meter'] !== null && (float)$e['current_meter'] >= (float)$e['next_service_meter']) {
                    $why[] = 'service due at ' . number_format((float)$e['next_service_meter']) . ' ' . $e['meter_unit'];
                }
                foreach (['inspection_due' => 'inspection', 'insurance_expiry' => 'insurance', 'registration_expiry' => 'registration'] as $col => $l) {
                    if ($e[$col] && $e[$col] <= $month) {
                        $why[] = $l . ' ' . ($e[$col] < $today ? 'expired ' : 'due ') . fdate($e[$col]);
                    }
                }
                $add('equipment', 'warn', 'tools', 'Equipment ' . $e['code'] . ' ' . $e['name'], ucfirst(implode(', ', $why)) . '.', url('equip.register', ['a' => 'view', 'id' => $e['id']]), $e['current_project_id'] ? (int)$e['current_project_id'] : null);
            }
            foreach (DB::all("SELECT a.id, e.code, e.name, a.project_id FROM cs_equipment_dispatch a JOIN cs_equipment_dispatch b ON b.equipment_id = a.equipment_id AND b.id > a.id
                               AND b.status IN ('scheduled','dispatched') AND a.status IN ('scheduled','dispatched') AND a.date_from <= b.date_to AND b.date_from <= a.date_to
                              JOIN cs_equipment e ON e.id = a.equipment_id LIMIT 10") as $r) {
                $add('equipment', 'bad', 'calendar2-x', 'Booking clash: ' . $r['code'] . ' ' . $r['name'], 'Two bookings of the same unit overlap.', url('equip.dispatch', ['equipment' => $r['code']]), (int)$r['project_id']);
            }
        }
        // late milestones and missing deliverables
        foreach (DB::all("SELECT m.*, (SELECT COUNT(*) FROM cs_milestone_items i WHERE i.milestone_id = m.id AND i.is_required = 1 AND i.status NOT IN ('accepted','completed')) AS open_items
                            FROM cs_milestones m JOIN cs_projects p ON p.id = m.project_id
                           WHERE m.status NOT IN ('accepted','completed') AND m.scheduled_date IS NOT NULL AND m.scheduled_date <= :d $pf ORDER BY m.scheduled_date LIMIT 30",
            ['d' => date('Y-m-d', strtotime('+14 days'))] + $pp) as $m) {
            $late = $m['scheduled_date'] < $today;
            if ($late || (int)$m['open_items'] > 0) {
                $add($late ? 'milestone' : 'deliverable', $late ? 'bad' : 'warn', $late ? 'flag' : 'list-check',
                    ($late ? 'Late milestone: ' : 'Deliverables missing: ') . $m['code'] . ' ' . $m['name'],
                    $pname((int)$m['project_id']) . ', due ' . fdate($m['scheduled_date']) . ((int)$m['open_items'] ? ', ' . (int)$m['open_items'] . ' required deliverable(s) still open' : '') . '.',
                    url('plan.milestones', ['a' => 'view', 'id' => $m['id']]), (int)$m['project_id']);
            }
        }
        // contracts nearing completion with work left, and bonds expiring
        foreach (DB::all("SELECT b.*, k.contract_no FROM cs_contract_bonds b JOIN cs_contracts k ON k.id = b.contract_id WHERE b.status = 'active' AND b.expiry_date IS NOT NULL AND b.expiry_date <= :m ORDER BY b.expiry_date LIMIT 15", ['m' => $month]) as $b) {
            $add('bond', $b['expiry_date'] < $today ? 'bad' : 'warn', 'shield-check', (CsContracts::BOND_KINDS[$b['kind']] ?? 'Bond') . ' ' . ($b['expiry_date'] < $today ? 'expired' : 'expiring') . ': ' . $b['contract_no'],
                ($b['reference_no'] ? $b['reference_no'] . ', ' : '') . 'expires ' . fdate($b['expiry_date']) . '.', url('proj.contracts', ['a' => 'view', 'id' => $b['contract_id']]));
        }
        foreach (DB::all("SELECT * FROM cs_contracts WHERE status = 'active' AND COALESCE(revised_completion_date, original_completion_date) <= :m", ['m' => $month]) as $k) {
            $add('contract', 'warn', 'file-earmark-text', 'Contract ' . $k['contract_no'] . ' reaches its completion date', 'Completion ' . fdate($k['revised_completion_date'] ?: $k['original_completion_date']) . '. Request an extension of time if the work will run over.', url('proj.contracts', ['a' => 'view', 'id' => $k['id']]));
        }
        // change orders not approved after two weeks
        foreach (DB::all("SELECT o.* FROM cs_change_orders o JOIN cs_projects p ON p.id = o.project_id WHERE o.status IN ('proposed','for_approval','internally_approved') AND o.created_at < :d $pf LIMIT 15",
            ['d' => date('Y-m-d', strtotime('-14 days'))] + $pp) as $o) {
            $add('change_order', 'warn', 'arrow-left-right', 'Unapproved change order ' . $o['co_no'], $o['title'] . ', ' . CsUi::label($o['status']) . ' since ' . fdate($o['created_at']) . ' (' . money($o['price_impact']) . ').', url('proj.change_orders', ['a' => 'view', 'id' => $o['id']]), (int)$o['project_id']);
        }
        // quality and safety
        foreach (DB::all("SELECT n.* FROM cs_ncrs n JOIN cs_projects p ON p.id = n.project_id WHERE n.status IN ('open','in_progress') $pf ORDER BY n.raised_date LIMIT 15", $pp) as $n) {
            $add('quality', $n['due_date'] && $n['due_date'] < $today ? 'bad' : 'warn', 'exclamation-diamond', 'Open NCR ' . $n['ncr_no'] . ': ' . $n['title'], $pname((int)$n['project_id']) . ($n['due_date'] ? ', corrective action due ' . fdate($n['due_date']) : '') . '.', url('site.ncr', ['a' => 'view', 'id' => $n['id']]), (int)$n['project_id']);
        }
        foreach (DB::all("SELECT r.* FROM cs_safety_records r JOIN cs_projects p ON p.id = r.project_id WHERE r.kind IN ('incident','near_miss') AND r.status = 'open' $pf ORDER BY r.record_date DESC LIMIT 15", $pp) as $r) {
            $add('safety', in_array($r['severity'], ['high', 'critical'], true) ? 'bad' : 'warn', 'cone-striped', CsUi::label($r['kind']) . ' ' . $r['ref_no'] . ': ' . $r['title'], $pname((int)$r['project_id']) . ', ' . fdate($r['record_date']) . ', severity ' . ($r['severity'] ?: 'not set') . '.', url('site.safety', ['a' => 'view', 'id' => $r['id']]), (int)$r['project_id']);
        }
        $order = ['bad' => 0, 'warn' => 1, 'info' => 2];
        usort($out, fn($a, $b) => ($order[$a['tone']] ?? 3) <=> ($order[$b['tone']] ?? 3));
        return self::$all = $out;
    }

    public static function render(array $alerts): string
    {
        $out = '<ul class="cs-alerts">';
        foreach ($alerts as $a) {
            $out .= '<li class="is-' . h($a['tone']) . '"><i class="bi bi-' . h($a['icon']) . '"></i><div><div class="t">' . ($a['link'] ? '<a href="' . h($a['link']) . '">' . h($a['title']) . '</a>' : h($a['title'])) . '</div>'
                . '<div class="d">' . h($a['detail']) . '</div></div><div></div></li>';
        }
        return $out . '</ul>';
    }
}
