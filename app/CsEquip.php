<?php
declare(strict_types=1);

/**
 * Equipment and vehicles: the register (owned, rented, leased), dispatch to projects without double booking, daily
 * usage logs, and their cost to the project.
 *
 *  Owned equipment (standard rate): approved usage charges the project at the charge-out rate
 *      Dr Equipment Cost (project, cost code)   Cr Equipment Cost Recovery (an expense contra account)
 *  so the project carries the use of the machine while the company's own depreciation, fuel and repairs stay in the
 *  equipment cost pool without being counted twice.
 *  Rented equipment: the rental supplier's bill (charged to the project in accounting) carries the cost; the log only
 *  records the hours.
 */
final class CsEquip
{
    public const TYPES = ['excavator' => 'Excavator', 'backhoe' => 'Backhoe', 'bulldozer' => 'Bulldozer', 'loader' => 'Loader', 'crane' => 'Crane', 'roller' => 'Roller / compactor',
        'mixer' => 'Concrete mixer', 'pump' => 'Concrete pump', 'generator' => 'Generator', 'welder' => 'Welding machine', 'compressor' => 'Air compressor', 'truck' => 'Dump truck',
        'vehicle' => 'Service vehicle', 'scaffold' => 'Scaffolding', 'other' => 'Other'];
    public const STATUSES = ['available' => 'Available', 'deployed' => 'Deployed', 'idle' => 'Idle', 'under_repair' => 'Under repair', 'off_hired' => 'Off-hired', 'disposed' => 'Disposed'];

    public static function get(int $id): ?array
    {
        return DB::row('SELECT e.*, v.name AS vendor_name, w.full_name AS operator_name, p.code AS project_code FROM cs_equipment e LEFT JOIN vendors v ON v.id = e.vendor_id
                          LEFT JOIN cs_workers w ON w.id = e.operator_worker_id LEFT JOIN cs_projects p ON p.id = e.current_project_id WHERE e.id = :id', ['id' => $id]);
    }

    public static function save(array $d, ?int $id = null): int
    {
        return DB::tx(function () use ($d, $id) {
            $old = $id ? self::get($id) : null;
            $date = fn($k) => valid_date((string)($d[$k] ?? '')) ? $d[$k] : null;
            $row = ['name' => mb_substr(trim((string)($d['name'] ?? '')), 0, 150), 'equipment_type' => isset(self::TYPES[$d['equipment_type'] ?? '']) ? $d['equipment_type'] : 'other',
                'make_model' => mb_substr(trim((string)($d['make_model'] ?? '')), 0, 120) ?: null, 'plate_serial' => mb_substr(trim((string)($d['plate_serial'] ?? '')), 0, 60) ?: null,
                'ownership' => in_array($d['ownership'] ?? '', ['owned', 'rented', 'leased'], true) ? $d['ownership'] : 'owned', 'fixed_asset_id' => (int)($d['fixed_asset_id'] ?? 0) ?: null,
                'vendor_id' => (int)($d['vendor_id'] ?? 0) ?: null, 'costing_method' => in_array($d['costing_method'] ?? '', ['standard', 'rental', 'actual'], true) ? $d['costing_method'] : 'standard',
                'hourly_rate' => to_num($d['hourly_rate'] ?? 0), 'idle_rate' => to_num($d['idle_rate'] ?? 0), 'rental_basis' => in_array($d['rental_basis'] ?? '', ['hourly', 'daily', 'monthly'], true) ? $d['rental_basis'] : null,
                'rental_rate' => to_num($d['rental_rate'] ?? 0), 'acquisition_cost' => to_num($d['acquisition_cost'] ?? 0), 'meter_unit' => in_array($d['meter_unit'] ?? '', ['hours', 'km'], true) ? $d['meter_unit'] : 'hours',
                'current_meter' => to_num($d['current_meter'] ?? 0), 'fuel_type' => mb_substr(trim((string)($d['fuel_type'] ?? '')), 0, 20) ?: null,
                'operator_worker_id' => (int)($d['operator_worker_id'] ?? 0) ?: null, 'insurance_expiry' => $date('insurance_expiry'), 'inspection_due' => $date('inspection_due'),
                'registration_expiry' => $date('registration_expiry'), 'next_service_meter' => ($d['next_service_meter'] ?? '') !== '' ? to_num($d['next_service_meter']) : null,
                'notes' => mb_substr(trim((string)($d['notes'] ?? '')), 0, 255) ?: null];
            if ($row['name'] === '') {
                fail('Enter the equipment name.');
            }
            if ($row['ownership'] !== 'owned' && !$row['vendor_id']) {
                fail('Choose the rental or leasing company.');
            }
            if ($row['costing_method'] !== 'rental' && $row['hourly_rate'] <= 0) {
                fail('Enter the charge-out rate per operating hour (or cost it through the rental bills).');
            }
            if ($old) {
                if (isset($d['status']) && isset(self::STATUSES[$d['status']]) && $d['status'] !== $old['status']) {
                    if ($d['status'] === 'disposed' && DB::value("SELECT id FROM cs_equipment_dispatch WHERE equipment_id = :e AND status IN ('scheduled','dispatched') LIMIT 1", ['e' => $id])) {
                        fail('The unit still has dispatches scheduled or out.');
                    }
                    $row['status'] = $d['status'];
                }
                DB::update('cs_equipment', $row, 'id = :id', ['id' => $id]);
                Audit::log('edit', 'equip', 'equipment', $id, $old['code'] . ' ' . $row['name'], ['page_key' => 'equip.register', 'before' => $old, 'after' => $row]);
            } else {
                $row['code'] = trim((string)($d['code'] ?? '')) ?: Seq::next('cs_equipment');
                if (DB::value('SELECT id FROM cs_equipment WHERE code = :c', ['c' => $row['code']])) {
                    fail('Equipment code ' . $row['code'] . ' already exists.');
                }
                $row['status'] = 'available';
                $id = DB::insert('cs_equipment', $row);
                Audit::log('create', 'equip', 'equipment', $id, $row['code'] . ' ' . $row['name'], ['page_key' => 'equip.register']);
            }
            return $id;
        });
    }

    // ------------------------------------------------------------------ dispatch

    /** Dispatches that overlap a date range for one unit (scheduled or out) */
    public static function clashes(int $equipmentId, string $from, string $to, ?int $exceptId = null): array
    {
        return DB::all("SELECT d.*, p.code FROM cs_equipment_dispatch d JOIN cs_projects p ON p.id = d.project_id
                         WHERE d.equipment_id = :e AND d.status IN ('scheduled','dispatched') AND d.date_from <= :t AND d.date_to >= :f" . ($exceptId ? ' AND d.id <> :x' : ''),
            ['e' => $equipmentId, 'f' => $from, 't' => $to] + ($exceptId ? ['x' => $exceptId] : []));
    }

    public static function saveDispatch(array $d, ?int $id = null): int
    {
        return DB::tx(function () use ($d, $id) {
            $old = $id ? DB::row('SELECT * FROM cs_equipment_dispatch WHERE id = :id', ['id' => $id]) : null;
            if ($old && $old['status'] !== 'scheduled') {
                fail('Only a scheduled dispatch is changed.');
            }
            $eq = self::get((int)($d['equipment_id'] ?? ($old['equipment_id'] ?? 0)));
            if (!$eq) {
                fail('Choose the equipment.');
            }
            if (in_array($eq['status'], ['under_repair', 'disposed', 'off_hired'], true)) {
                fail($eq['code'] . ' is ' . strtolower(self::STATUSES[$eq['status']]) . ' and cannot be dispatched.');
            }
            $pid = (int)($d['project_id'] ?? 0);
            $p = CsProject::get($pid);
            if (!$p || !in_array($p['status'], CsProject::OPEN, true)) {
                fail('Choose an open project.');
            }
            $from = valid_date((string)($d['date_from'] ?? '')) ? $d['date_from'] : null;
            $to = valid_date((string)($d['date_to'] ?? '')) ? $d['date_to'] : null;
            if (!$from || !$to || $to < $from) {
                fail('Enter the dates the unit is needed (from and to).');
            }
            if ($c = self::clashes((int)$eq['id'], $from, $to, $id)) {
                fail($eq['code'] . ' is already booked for ' . $c[0]['code'] . ' from ' . fdate($c[0]['date_from']) . ' to ' . fdate($c[0]['date_to']) . ' (' . $c[0]['dispatch_no'] . '). One unit cannot be on two projects at the same time.');
            }
            if ($eq['insurance_expiry'] && $eq['insurance_expiry'] < $from) {
                fail('The insurance of ' . $eq['code'] . ' expires on ' . fdate($eq['insurance_expiry']) . ', before the dispatch starts. Renew it first.');
            }
            $row = ['equipment_id' => (int)$eq['id'], 'project_id' => $pid, 'date_from' => $from, 'date_to' => $to, 'operator_worker_id' => (int)($d['operator_worker_id'] ?? 0) ?: ($eq['operator_worker_id'] ?: null),
                'purpose' => mb_substr(trim((string)($d['purpose'] ?? '')), 0, 255) ?: null];
            if ($old) {
                DB::update('cs_equipment_dispatch', $row, 'id = :id', ['id' => $id]);
            } else {
                $id = DB::insert('cs_equipment_dispatch', $row + ['dispatch_no' => Seq::next('cs_dispatch'), 'status' => 'scheduled', 'created_by' => Auth::id()]);
            }
            Audit::log($old ? 'edit' : 'create', 'equip', 'dispatch', $id, $eq['code'] . ' to ' . $p['code'], ['page_key' => 'equip.dispatch', 'reason' => fdate($from) . ' to ' . fdate($to)]);
            return $id;
        });
    }

    /** Moves a dispatch along: out (dispatched), back (returned) or cancelled */
    public static function dispatchStatus(int $id, string $status, array $d = []): void
    {
        DB::tx(function () use ($id, $status, $d) {
            $x = DB::row('SELECT * FROM cs_equipment_dispatch WHERE id = :id FOR UPDATE', ['id' => $id]);
            if (!$x) {
                fail('Dispatch not found.');
            }
            $eq = self::get((int)$x['equipment_id']);
            if ($status === 'dispatched') {
                if ($x['status'] !== 'scheduled') {
                    fail('Only a scheduled dispatch goes out.');
                }
                if ($eq['status'] === 'deployed' && (int)$eq['current_project_id'] !== (int)$x['project_id']) {
                    fail($eq['code'] . ' is still out at ' . $eq['project_code'] . '. Return it first.');
                }
                $meter = ($d['meter'] ?? '') !== '' ? to_num($d['meter']) : (float)$eq['current_meter'];
                DB::update('cs_equipment_dispatch', ['status' => 'dispatched', 'meter_out' => $meter], 'id = :id', ['id' => $id]);
                DB::update('cs_equipment', ['status' => 'deployed', 'current_project_id' => $x['project_id'], 'current_meter' => max((float)$eq['current_meter'], $meter)], 'id = :id', ['id' => $eq['id']]);
            } elseif ($status === 'returned') {
                if ($x['status'] !== 'dispatched') {
                    fail('Only a unit that is out is returned.');
                }
                $meter = ($d['meter'] ?? '') !== '' ? to_num($d['meter']) : (float)$eq['current_meter'];
                if ($x['meter_out'] !== null && $meter + 0.001 < (float)$x['meter_out']) {
                    fail('The meter reading on return is lower than when it went out.');
                }
                DB::update('cs_equipment_dispatch', ['status' => 'returned', 'meter_in' => $meter, 'returned_date' => valid_date((string)($d['date'] ?? '')) ? $d['date'] : today()], 'id = :id', ['id' => $id]);
                DB::update('cs_equipment', ['status' => 'available', 'current_project_id' => null, 'current_meter' => max((float)$eq['current_meter'], $meter)], 'id = :id', ['id' => $eq['id']]);
            } elseif ($status === 'cancelled') {
                if ($x['status'] !== 'scheduled') {
                    fail('Only a scheduled dispatch is cancelled.');
                }
                DB::update('cs_equipment_dispatch', ['status' => 'cancelled'], 'id = :id', ['id' => $id]);
            } else {
                fail('Unknown status.');
            }
            Audit::log('status', 'equip', 'dispatch', $id, $x['dispatch_no'], ['page_key' => 'equip.dispatch', 'reason' => CsUi::label($status) . (!empty($d['reason']) ? ': ' . $d['reason'] : '')]);
        });
    }

    // ------------------------------------------------------------------ usage logs

    public static function getLog(int $id): ?array
    {
        return DB::row('SELECT l.*, e.code, e.name AS equipment_name, e.costing_method, e.meter_unit, p.code AS project_code, p.class_id FROM cs_equipment_logs l
                          JOIN cs_equipment e ON e.id = l.equipment_id JOIN cs_projects p ON p.id = l.project_id WHERE l.id = :id', ['id' => $id]);
    }

    public static function saveLog(array $d, ?int $id = null): int
    {
        return DB::tx(function () use ($d, $id) {
            $old = $id ? self::getLog($id) : null;
            if ($old && !in_array($old['status'], ['draft', 'rejected'], true)) {
                fail('Only a draft usage log is changed.');
            }
            $eq = self::get((int)($d['equipment_id'] ?? ($old['equipment_id'] ?? 0)));
            if (!$eq) {
                fail('Choose the equipment.');
            }
            $pid = (int)($d['project_id'] ?? ($old['project_id'] ?? 0));
            if (!CsProject::get($pid)) {
                fail('Choose the project.');
            }
            CsProject::requireAccess($pid);
            $date = valid_date((string)($d['log_date'] ?? '')) ? $d['log_date'] : null;
            if (!$date || $date > today()) {
                fail('Enter the date the equipment was used (not a future date).');
            }
            // the unit must be dispatched to this project that day, and only one project a day
            $disp = DB::value("SELECT id FROM cs_equipment_dispatch WHERE equipment_id = :e AND project_id = :p AND status IN ('dispatched','returned') AND date_from <= :d AND COALESCE(returned_date, date_to) >= :d2",
                ['e' => $eq['id'], 'p' => $pid, 'd' => $date, 'd2' => $date]);
            if (!$disp) {
                fail($eq['code'] . ' was not dispatched to this project on ' . fdate($date) . '. Record the dispatch first.');
            }
            $other = DB::row("SELECT l.log_no, p.code FROM cs_equipment_logs l JOIN cs_projects p ON p.id = l.project_id WHERE l.equipment_id = :e AND l.log_date = :d AND l.project_id <> :p AND l.status <> 'rejected'" . ($id ? ' AND l.id <> :id' : ''),
                ['e' => $eq['id'], 'd' => $date, 'p' => $pid] + ($id ? ['id' => $id] : []));
            if ($other) {
                fail($eq['code'] . ' is already logged on ' . $other['code'] . ' that day (' . $other['log_no'] . '). A unit works on one project a day.');
            }
            $op = max(0, to_num($d['operating_hours'] ?? 0));
            $idle = max(0, to_num($d['idle_hours'] ?? 0));
            $bd = max(0, to_num($d['breakdown_hours'] ?? 0));
            $sameDay = (float)DB::value("SELECT COALESCE(SUM(operating_hours + idle_hours + breakdown_hours),0) FROM cs_equipment_logs WHERE equipment_id = :e AND log_date = :d AND status <> 'rejected'" . ($id ? ' AND id <> :id' : ''),
                ['e' => $eq['id'], 'd' => $date] + ($id ? ['id' => $id] : []));
            if ($op + $idle + $bd + $sameDay > 24) {
                fail('More than 24 hours logged for ' . $eq['code'] . ' on ' . fdate($date) . '.');
            }
            $ms = ($d['meter_start'] ?? '') !== '' ? to_num($d['meter_start']) : null;
            $me = ($d['meter_end'] ?? '') !== '' ? to_num($d['meter_end']) : null;
            if ($ms !== null && $me !== null && $me < $ms) {
                fail('The meter at the end of the day is lower than at the start.');
            }
            if ($ms !== null && $me !== null && $eq['meter_unit'] === 'hours' && $op > ($me - $ms) + 0.5) {
                fail('Operating hours (' . $op . ') are more than the hour meter shows (' . ($me - $ms) . ').');
            }
            $fuelCost = max(0, to_num($d['fuel_cost'] ?? 0));
            $rate = (float)$eq['hourly_rate'];
            $charge = $eq['costing_method'] === 'rental' ? 0.0 : r2($op * $rate + $idle * (float)$eq['idle_rate'] + ($eq['costing_method'] === 'actual' ? $fuelCost : 0));
            $row = ['equipment_id' => (int)$eq['id'], 'project_id' => $pid, 'activity_id' => (int)($d['activity_id'] ?? 0) ?: null, 'cost_code_id' => (int)($d['cost_code_id'] ?? 0) ?: null, 'log_date' => $date,
                'operating_hours' => $op, 'idle_hours' => $idle, 'breakdown_hours' => $bd, 'meter_start' => $ms, 'meter_end' => $me, 'fuel_liters' => max(0, to_num($d['fuel_liters'] ?? 0)), 'fuel_cost' => $fuelCost,
                'operator_worker_id' => (int)($d['operator_worker_id'] ?? 0) ?: null, 'hourly_rate' => $rate, 'charge_amount' => $charge, 'remarks' => mb_substr(trim((string)($d['remarks'] ?? '')), 0, 255) ?: null];
            if ($old) {
                DB::update('cs_equipment_logs', $row, 'id = :id', ['id' => $id]);
            } else {
                $id = DB::insert('cs_equipment_logs', $row + ['log_no' => Seq::next('cs_equip_log'), 'status' => 'draft', 'created_by' => Auth::id()]);
            }
            if ($me !== null && $me > (float)$eq['current_meter']) {
                DB::update('cs_equipment', ['current_meter' => $me], 'id = :id', ['id' => $eq['id']]);
            }
            Audit::log($old ? 'edit' : 'create', 'equip', 'equipment_log', $id, $eq['code'] . ' ' . fdate($date), ['page_key' => 'equip.logs', 'amount' => $charge, 'reason' => $op . ' h operating, ' . $idle . ' h idle']);
            return $id;
        });
    }

    public static function postLog(int $id): ?int
    {
        $l = self::getLog($id);
        if (!$l || $l['txn_id']) {
            return $l['txn_id'] ?? null;
        }
        $txn = null;
        if ((float)$l['charge_amount'] > 0) {
            $txn = CsPost::journal($l['log_date'], 'Equipment ' . $l['code'] . ' ' . $l['equipment_name'] . ' on ' . $l['project_code'] . ', ' . (float)$l['operating_hours'] . ' h', [
                ['account_id' => CsPost::costAccount('equipment', $l['cost_code_id'] ? (int)$l['cost_code_id'] : null), 'amount' => (float)$l['charge_amount'], 'cost_code_id' => $l['cost_code_id'],
                    'description' => $l['code'] . ' ' . (float)$l['operating_hours'] . ' h operating, ' . (float)$l['idle_hours'] . ' h idle'],
                // the recovery is the company's (the equipment's own costs sit in its overhead), not the project's
                ['account_id' => CsPost::account('cs_equipment_recovery'), 'amount' => -(float)$l['charge_amount'], 'description' => 'Recovery ' . $l['code'], 'class_id' => null],
            ], (int)$l['class_id'], $l['log_no']);
        }
        DB::update('cs_equipment_logs', ['status' => 'posted', 'txn_id' => $txn], 'id = :id', ['id' => $id]);
        Audit::log('post', 'equip', 'equipment_log', $id, $l['log_no'], ['page_key' => 'equip.logs', 'amount' => $l['charge_amount']]);
        return $txn;
    }

    public static function reopenLog(int $id, string $reason): void
    {
        DB::tx(function () use ($id, $reason) {
            $l = self::getLog($id);
            if (!$l || !in_array($l['status'], ['approved', 'posted'], true)) {
                fail('Only an approved usage log is reopened.');
            }
            if (trim($reason) === '') {
                fail('Give the reason for the correction.');
            }
            CsPost::void($l['txn_id'] ? (int)$l['txn_id'] : null, 'Equipment log ' . $l['log_no'] . ' reopened: ' . $reason);
            DB::update('cs_equipment_logs', ['status' => 'draft', 'txn_id' => null, 'approved_by' => null], 'id = :id', ['id' => $id]);
            Audit::log('reopen', 'equip', 'equipment_log', $id, $l['log_no'], ['page_key' => 'equip.logs', 'reason' => $reason]);
        });
    }

    public static function workflow(string $type, string $event, int $id, array $req): void
    {
        if ($type !== 'equipment_log') {
            return;
        }
        $status = ['submitted' => 'for_approval', 'approved' => 'approved', 'rejected' => 'rejected', 'returned' => 'draft', 'cancelled' => 'draft'][$event] ?? null;
        if ($status) {
            DB::update('cs_equipment_logs', ['status' => $status] + ($event === 'approved' ? ['approved_by' => Auth::id()] : []), 'id = :id', ['id' => $id]);
        }
        if ($event === 'approved') {
            self::postLog($id);
        }
    }
}
