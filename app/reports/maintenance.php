<?php
declare(strict_types=1);

/**
 * Fixed asset maintenance reports:
 *   fa_maint_history  every maintenance record
 *   fa_maint_cost     maintenance cost by asset (parts, labor, number of jobs, downtime, cost against the asset's cost)
 *   fa_maint_parts    spare parts used (part no., serial, model, size, supplier, warranty)
 *   fa_maint_labor    labor (man-hours, bulk charges, by worker)
 *   fa_pm_upcoming    upcoming and overdue preventive maintenance
 *   fa_pm_log         completed preventive maintenance
 */

function maint_rpt_filters(bool $withType = true): array
{
    $f = fa_location_filter();
    if ($withType) {
        $f['mtype'] = ['Type', fn() => AssetMaint::TYPES];
    }
    return $f;
}

/** WHERE parts shared by the record-based reports. $p receives the parameters. */
function maint_rpt_where(array $f, array &$p): string
{
    $sql = " m.status <> 'void' AND m.maint_date BETWEEN :f AND :t";
    $p['f'] = $f['from'];
    $p['t'] = $f['to'];
    if (!empty($f['asset_id'])) {
        $sql .= ' AND m.asset_id = :asset';
        $p['asset'] = $f['asset_id'];
    }
    if (!empty($f['vendor_id'])) {
        $sql .= ' AND m.vendor_id = :vendor';
        $p['vendor'] = $f['vendor_id'];
    }
    if (!empty($f['mtype']) && isset(AssetMaint::TYPES[$f['mtype']])) {
        $sql .= ' AND m.maint_type = :mtype';
        $p['mtype'] = $f['mtype'];
    }
    if (!empty($f['class_id']) && Classes::ready()) {
        if ((int)$f['class_id'] === Classes::UNCLASSIFIED) {
            $sql .= ' AND m.class_id IS NULL';
        } else {
            $sql .= ' AND m.class_id = :mclass';
            $p['mclass'] = (int)$f['class_id'];
        }
    }
    return $sql . fa_location_sql($f, $p);
}

Report::define('fa_maint_history', [
    'filters' => ['range', 'asset', 'vendor', 'class'],
    'extra_filters' => maint_rpt_filters(),
    'default_dates' => 'this_year',
    'run' => function (array $f): array {
        $p = [];
        $where = maint_rpt_where($f, $p);
        $rows = [];
        $tot = ['parts' => 0.0, 'labor' => 0.0, 'tax' => 0.0, 'total' => 0.0];
        foreach (DB::all("SELECT m.*, fa.asset_no, fa.description AS asset_description, fa.location, v.name AS vendor, t.txn_type, t.txn_no
                            FROM asset_maintenance m JOIN fixed_assets fa ON fa.id = m.asset_id
                            LEFT JOIN vendors v ON v.id = m.vendor_id LEFT JOIN transactions t ON t.id = m.txn_id AND t.is_voided = 0
                           WHERE $where ORDER BY m.maint_date, m.id", $p) as $m) {
            $rows[] = ['date' => $m['maint_date'], 'no' => $m['maint_no'], 'asset' => $m['asset_no'] . ' - ' . $m['asset_description'], 'location' => $m['location'],
                'type' => AssetMaint::typeLabel($m['maint_type']), 'work' => mb_strimwidth((string)($m['description'] ?: $m['problem']), 0, 70, '...'), 'vendor' => $m['vendor'], 'ref' => $m['ref_no'],
                'parts' => (float)$m['parts_total'], 'labor' => (float)$m['labor_total'], 'tax' => (float)$m['tax_total'], 'total' => (float)$m['total'],
                'paid' => $m['txn_no'] ? Txn::label($m['txn_type']) . ' ' . $m['txn_no'] : '',
                '_links' => ['no' => Report::link('fa.maintenance', ['a' => 'view', 'id' => $m['id']])], '_ref' => ['asset' => (int)$m['asset_id']]];
            foreach ($tot as $k => $_) {
                $tot[$k] += (float)$m[$k === 'parts' ? 'parts_total' : ($k === 'labor' ? 'labor_total' : ($k === 'tax' ? 'tax_total' : 'total'))];
            }
        }
        return ['columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'no', 'label' => 'Record'], ['key' => 'asset', 'label' => 'Asset'], ['key' => 'location', 'label' => 'Location'],
            ['key' => 'type', 'label' => 'Type'], ['key' => 'work', 'label' => 'Work done'], ['key' => 'vendor', 'label' => 'Supplier'], ['key' => 'ref', 'label' => 'Reference'],
            ['key' => 'parts', 'label' => 'Parts', 'type' => 'money'], ['key' => 'labor', 'label' => 'Labor', 'type' => 'money'], ['key' => 'tax', 'label' => 'VAT', 'type' => 'money'],
            ['key' => 'total', 'label' => 'Total', 'type' => 'money'], ['key' => 'paid', 'label' => 'Paid through']],
            'rows' => $rows, 'totals' => ['date' => 'TOTAL', 'parts' => r2($tot['parts']), 'labor' => r2($tot['labor']), 'tax' => r2($tot['tax']), 'total' => r2($tot['total'])]];
    },
]);

Report::define('fa_maint_cost', [
    'filters' => ['range', 'fa_category', 'class'],
    'extra_filters' => maint_rpt_filters(),
    'default_dates' => 'this_year',
    'run' => function (array $f): array {
        $p = [];
        $where = maint_rpt_where($f, $p);
        if (!empty($f['category_id'])) {
            $where .= ' AND fa.category_id = :cat';
            $p['cat'] = $f['category_id'];
        }
        $rows = [];
        $tot = ['jobs' => 0, 'parts' => 0.0, 'labor' => 0.0, 'total' => 0.0, 'down' => 0.0];
        foreach (DB::all("SELECT fa.id, fa.asset_no, fa.description, fa.location, fa.cost, ac.name AS category, COUNT(*) AS jobs,
                                 SUM(m.maint_type = 'repair') AS repairs, SUM(m.parts_total) AS parts, SUM(m.labor_total) AS labor, SUM(m.parts_total + m.labor_total) AS total,
                                 SUM(COALESCE(m.downtime_days, 0)) AS down, MAX(m.maint_date) AS last_date
                            FROM asset_maintenance m JOIN fixed_assets fa ON fa.id = m.asset_id JOIN asset_categories ac ON ac.id = fa.category_id
                           WHERE $where GROUP BY fa.id, fa.asset_no, fa.description, fa.location, fa.cost, ac.name ORDER BY total DESC, fa.asset_no", $p) as $r) {
            $rows[] = ['asset' => $r['asset_no'] . ' - ' . $r['description'], 'category' => $r['category'], 'location' => $r['location'], 'jobs' => (int)$r['jobs'], 'repairs' => (int)$r['repairs'],
                'parts' => (float)$r['parts'], 'labor' => (float)$r['labor'], 'total' => (float)$r['total'], 'down' => (float)$r['down'], 'last' => $r['last_date'],
                'pct' => (float)$r['cost'] > 0 ? round((float)$r['total'] / (float)$r['cost'] * 100, 1) : null,
                '_links' => ['asset' => Report::link('fa.maintenance', ['asset_id' => $r['id'], 'from' => $f['from'], 'to' => $f['to']])]];
            $tot['jobs'] += (int)$r['jobs'];
            $tot['parts'] += (float)$r['parts'];
            $tot['labor'] += (float)$r['labor'];
            $tot['total'] += (float)$r['total'];
            $tot['down'] += (float)$r['down'];
        }
        return ['notes' => 'Cost before VAT. "% of cost" compares the maintenance spent in the period with what the asset cost: an asset that keeps climbing is a candidate for replacement.',
            'columns' => [['key' => 'asset', 'label' => 'Asset'], ['key' => 'category', 'label' => 'Category'], ['key' => 'location', 'label' => 'Location'], ['key' => 'jobs', 'label' => 'Jobs', 'type' => 'qty'],
            ['key' => 'repairs', 'label' => 'Repairs', 'type' => 'qty'], ['key' => 'parts', 'label' => 'Parts', 'type' => 'money'], ['key' => 'labor', 'label' => 'Labor', 'type' => 'money'],
            ['key' => 'total', 'label' => 'Total cost', 'type' => 'money'], ['key' => 'pct', 'label' => '% of cost', 'type' => 'qty'], ['key' => 'down', 'label' => 'Downtime (days)', 'type' => 'qty'],
            ['key' => 'last', 'label' => 'Last job', 'type' => 'date']],
            'rows' => $rows, 'totals' => ['asset' => 'TOTAL', 'jobs' => $tot['jobs'], 'parts' => r2($tot['parts']), 'labor' => r2($tot['labor']), 'total' => r2($tot['total']), 'down' => $tot['down']]];
    },
]);

Report::define('fa_maint_parts', [
    'filters' => ['range', 'asset', 'vendor'],
    'extra_filters' => maint_rpt_filters(false),
    'default_dates' => 'this_year',
    'run' => function (array $f): array {
        $p = [];
        $where = maint_rpt_where($f, $p);
        if (trim((string)$f['q']) !== '') {
            $where .= ' AND (l.part_no LIKE :q OR l.description LIKE :q OR l.serial_no LIKE :q OR l.model_no LIKE :q OR l.brand LIKE :q)';
            $p['q'] = '%' . trim((string)$f['q']) . '%';
        }
        $rows = [];
        $sum = 0.0;
        foreach (DB::all("SELECT l.*, m.id AS mid, m.asset_id, m.maint_no, m.maint_date, fa.asset_no, fa.description AS asset_description, v.name AS vendor
                            FROM asset_maintenance_parts l JOIN asset_maintenance m ON m.id = l.maintenance_id JOIN fixed_assets fa ON fa.id = m.asset_id
                            LEFT JOIN vendors v ON v.id = m.vendor_id WHERE $where ORDER BY m.maint_date, m.id, l.line_no", $p) as $l) {
            $rows[] = ['date' => $l['maint_date'], 'no' => $l['maint_no'], 'asset' => $l['asset_no'] . ' - ' . $l['asset_description'], 'part_no' => $l['part_no'], 'desc' => $l['description'], 'brand' => $l['brand'],
                'serial' => $l['serial_no'], 'model' => $l['model_no'], 'size' => $l['size_spec'], 'qty' => (float)$l['qty'], 'unit' => $l['unit'], 'cost' => (float)$l['unit_cost'], 'amount' => (float)$l['amount'],
                'vendor' => $l['vendor'], 'warranty' => $l['warranty_until'], '_links' => ['no' => Report::link('fa.maintenance', ['a' => 'view', 'id' => $l['mid']])], '_ref' => ['asset' => (int)$l['asset_id']]];
            $sum += (float)$l['amount'];
        }
        return ['columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'no', 'label' => 'Record'], ['key' => 'asset', 'label' => 'Asset'], ['key' => 'part_no', 'label' => 'Part no.'],
            ['key' => 'desc', 'label' => 'Description'], ['key' => 'brand', 'label' => 'Brand'], ['key' => 'serial', 'label' => 'Serial no.'], ['key' => 'model', 'label' => 'Model no.'], ['key' => 'size', 'label' => 'Size'],
            ['key' => 'qty', 'label' => 'Qty', 'type' => 'qty'], ['key' => 'unit', 'label' => 'Unit'], ['key' => 'cost', 'label' => 'Unit cost', 'type' => 'money'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'],
            ['key' => 'vendor', 'label' => 'Supplier'], ['key' => 'warranty', 'label' => 'Warranty until', 'type' => 'date']],
            'rows' => $rows, 'totals' => ['date' => 'TOTAL', 'amount' => r2($sum)]];
    },
]);

Report::define('fa_maint_labor', [
    'filters' => ['range', 'asset', 'vendor'],
    'extra_filters' => maint_rpt_filters(false),
    'default_dates' => 'this_year',
    'run' => function (array $f): array {
        $p = [];
        $where = maint_rpt_where($f, $p);
        $rows = [];
        $hours = 0.0;
        $sum = 0.0;
        foreach (DB::all("SELECT l.*, m.id AS mid, m.asset_id, m.maint_no, m.maint_date, m.performed_by, fa.asset_no, fa.description AS asset_description, v.name AS vendor
                            FROM asset_maintenance_labor l JOIN asset_maintenance m ON m.id = l.maintenance_id JOIN fixed_assets fa ON fa.id = m.asset_id
                            LEFT JOIN vendors v ON v.id = m.vendor_id WHERE $where ORDER BY m.maint_date, m.id, l.line_no", $p) as $l) {
            $bulk = $l['charge_type'] === 'bulk';
            $rows[] = ['date' => $l['maint_date'], 'no' => $l['maint_no'], 'asset' => $l['asset_no'] . ' - ' . $l['asset_description'], 'work' => $l['description'], 'worker' => $l['worker'] ?: $l['performed_by'],
                'charge' => $bulk ? 'Bulk charge' : 'By the hour', 'hours' => $bulk ? null : (float)$l['hours'], 'rate' => $bulk ? null : (float)$l['rate'], 'amount' => (float)$l['amount'], 'vendor' => $l['vendor'],
                '_links' => ['no' => Report::link('fa.maintenance', ['a' => 'view', 'id' => $l['mid']])], '_ref' => ['asset' => (int)$l['asset_id']]];
            $hours += $bulk ? 0 : (float)$l['hours'];
            $sum += (float)$l['amount'];
        }
        return ['columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'no', 'label' => 'Record'], ['key' => 'asset', 'label' => 'Asset'], ['key' => 'work', 'label' => 'Work done'],
            ['key' => 'worker', 'label' => 'Worker / mechanic'], ['key' => 'charge', 'label' => 'Charge'], ['key' => 'hours', 'label' => 'Man-hours', 'type' => 'qty'], ['key' => 'rate', 'label' => 'Rate', 'type' => 'money'],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'], ['key' => 'vendor', 'label' => 'Supplier']],
            'rows' => $rows, 'totals' => ['date' => 'TOTAL', 'hours' => $hours, 'amount' => r2($sum)]];
    },
]);

Report::define('fa_pm_upcoming', [
    'filters' => ['asset'],
    'extra_filters' => fa_location_filter() + [
        'task_type' => ['Kind of task', fn() => AssetMaint::TASK_TYPES],
        'horizon' => ['Show', fn() => ['due' => 'Overdue and due now', '30' => 'Due within 30 days', '90' => 'Due within 90 days', '365' => 'Due within a year', 'all' => 'Every active task'], null],
    ],
    'sortable' => false,
    'run' => function (array $f): array {
        $loc = (string)($f['fa_location'] ?? '');
        $tasks = AssetMaint::tasks(null, ['asset_id' => $f['asset_id'], 'location_id' => (int)$loc > 0 ? (int)$loc : null, 'task_type' => (string)($f['task_type'] ?? '')]);
        $h = (string)($f['horizon'] ?? '') ?: '90';
        $rows = [];
        $n = ['overdue' => 0, 'due' => 0, 'upcoming' => 0];
        foreach ($tasks as $r) {
            if ($loc === 'none' && $r['location_id']) {
                continue;
            }
            $urgent = in_array($r['state'], ['overdue', 'due'], true);
            if ($h === 'due' && !$urgent) {
                continue;
            }
            if (ctype_digit($h) && !$urgent && ($r['days'] === null || $r['days'] > (int)$h)) {
                continue;
            }
            $rep = [];
            if ($r['interval_value']) {
                $rep[] = 'every ' . (int)$r['interval_value'] . ' ' . $r['interval_unit'];
            }
            if ($r['meter_interval']) {
                $rep[] = 'every ' . qty($r['meter_interval']) . ' ' . ($r['meter_unit'] ?: 'units');
            }
            $n[$r['state']] = ($n[$r['state']] ?? 0) + 1;
            $rows[] = ['asset' => $r['asset_no'] . ' - ' . $r['asset_description'], 'location' => $r['asset_location'], 'task' => $r['task_name'], 'kind' => AssetMaint::TASK_TYPES[$r['task_type']] ?? $r['task_type'],
                'due' => $r['next_due_date'], 'meter' => $r['next_due_meter'] !== null ? qty($r['next_due_meter']) . ' ' . $r['meter_unit'] : '', 'state' => $r['state_text'], 'repeats' => $rep ? implode(' or ', $rep) : 'one time',
                'last' => $r['last_done_date'], 'who' => $r['assigned_to'], 'notes' => $r['notes'],
                '_class' => $r['state'] === 'overdue' ? 'text-danger' : '', '_links' => ['task' => Report::link('fa.pm', ['asset_id' => $r['asset_id'], 'tab' => 'all'])]];
        }
        return ['notes' => $n['overdue'] . ' overdue, ' . $n['due'] . ' due now, ' . $n['upcoming'] . ' upcoming. A task leaves this list only when it is acknowledged as complete in Preventive Maintenance.',
            'columns' => [['key' => 'asset', 'label' => 'Asset'], ['key' => 'location', 'label' => 'Location'], ['key' => 'task', 'label' => 'Task'], ['key' => 'kind', 'label' => 'Kind'],
            ['key' => 'due', 'label' => 'Due date', 'type' => 'date'], ['key' => 'meter', 'label' => 'Due at meter'], ['key' => 'state', 'label' => 'State'], ['key' => 'repeats', 'label' => 'Repeats'],
            ['key' => 'last', 'label' => 'Last done', 'type' => 'date'], ['key' => 'who', 'label' => 'Responsible'], ['key' => 'notes', 'label' => 'Notes']],
            'rows' => $rows];
    },
]);

Report::define('fa_pm_log', [
    'filters' => ['range', 'asset'],
    'extra_filters' => fa_location_filter(),
    'default_dates' => 'this_year',
    'run' => function (array $f): array {
        $p = ['f' => $f['from'], 't' => $f['to']];
        $sql = 'SELECT l.*, fa.asset_no, fa.description AS asset_description, fa.location, u.full_name, m.maint_no, m.total AS cost
                  FROM asset_pm_log l JOIN fixed_assets fa ON fa.id = l.asset_id LEFT JOIN users u ON u.id = l.done_by
                  LEFT JOIN asset_maintenance m ON m.id = l.maintenance_id
                 WHERE l.done_date BETWEEN :f AND :t';
        if (!empty($f['asset_id'])) {
            $sql .= ' AND l.asset_id = :asset';
            $p['asset'] = $f['asset_id'];
        }
        $sql .= fa_location_sql($f, $p);
        $rows = [];
        $late = 0;
        foreach (DB::all($sql . ' ORDER BY l.done_date, l.id', $p) as $l) {
            $days = $l['due_date'] ? (int)round((strtotime($l['done_date']) - strtotime($l['due_date'])) / 86400) : null;
            if ($days !== null && $days > 0) {
                $late++;
            }
            $rows[] = ['done' => $l['done_date'], 'asset' => $l['asset_no'] . ' - ' . $l['asset_description'], 'location' => $l['location'], 'task' => $l['task_name'], 'due' => $l['due_date'],
                'timing' => $days === null ? '' : ($days > 0 ? $days . ' day(s) late' : ($days < 0 ? abs($days) . ' day(s) early' : 'on the due date')),
                'meter' => $l['done_meter'] !== null ? (float)$l['done_meter'] : null, 'remarks' => $l['remarks'], 'by' => $l['full_name'], 'record' => $l['maint_no'], 'cost' => $l['cost'] !== null ? (float)$l['cost'] : null,
                '_links' => $l['maintenance_id'] ? ['record' => Report::link('fa.maintenance', ['a' => 'view', 'id' => $l['maintenance_id']])] : [], '_ref' => ['asset' => (int)$l['asset_id']]];
        }
        return ['notes' => count($rows) . ' task(s) completed, ' . $late . ' of them after the due date.',
            'columns' => [['key' => 'done', 'label' => 'Completed', 'type' => 'date'], ['key' => 'asset', 'label' => 'Asset'], ['key' => 'location', 'label' => 'Location'], ['key' => 'task', 'label' => 'Task'],
            ['key' => 'due', 'label' => 'Was due', 'type' => 'date'], ['key' => 'timing', 'label' => 'Timing'], ['key' => 'meter', 'label' => 'Meter', 'type' => 'qty'], ['key' => 'remarks', 'label' => 'Remarks'],
            ['key' => 'by', 'label' => 'Acknowledged by'], ['key' => 'record', 'label' => 'Maintenance record'], ['key' => 'cost', 'label' => 'Cost', 'type' => 'money']],
            'rows' => $rows];
    },
]);
