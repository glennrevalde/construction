<?php
declare(strict_types=1);

/**
 * Workforce, attendance and labor cost.
 *
 *  Timesheet (per project and day) > approval > labor cost posted to the project by cost code:
 *      Dr Direct Labor (or Indirect Labor for indirect and staff workers)   Cr Accrued Payroll (per worker)
 *      Dr Labor Burden                                                      Cr SSS, PhilHealth and Pag-IBIG Payable
 *  Payroll run (a pay period, all projects) > approval > clears what was accrued:
 *      Dr Accrued Payroll   Cr Employee Advances (deducted)   Cr Statutory Payable (employee share)   Cr Wages Payable (net)
 *  The wages are then paid from accounting (check or cash voucher on Wages Payable).
 *  Agency and labor-only workers (with a supplier) are timed for productivity, but their cost comes from the agency's bill.
 *
 * Checks: a worker cannot be on two timesheets at overlapping times, nor work more than the company's maximum hours a day.
 */
final class CsLabor
{
    public const CLASSES = ['direct' => 'Direct labor', 'indirect' => 'Indirect labor', 'labor_only' => 'Labor-only subcontractor', 'outsourced' => 'Agency / outsourced', 'staff' => 'Site staff'];

    // ------------------------------------------------------------------ workers

    public static function saveWorker(array $d, ?int $id = null): int
    {
        return DB::tx(function () use ($d, $id) {
            $old = $id ? DB::row('SELECT * FROM cs_workers WHERE id = :id', ['id' => $id]) : null;
            $row = ['full_name' => mb_substr(trim((string)($d['full_name'] ?? '')), 0, 120), 'classification' => isset(self::CLASSES[$d['classification'] ?? '']) ? $d['classification'] : 'direct',
                'trade_id' => (int)($d['trade_id'] ?? 0) ?: null, 'skills' => mb_substr(trim((string)($d['skills'] ?? '')), 0, 255) ?: null,
                'certifications' => mb_substr(trim((string)($d['certifications'] ?? '')), 0, 255) ?: null, 'rate_basis' => ($d['rate_basis'] ?? '') === 'hourly' ? 'hourly' : 'daily',
                'daily_rate' => to_num($d['daily_rate'] ?? 0), 'hourly_rate' => to_num($d['hourly_rate'] ?? 0), 'ot_multiplier' => to_num($d['ot_multiplier'] ?? 1.25) ?: 1.25,
                'holiday_multiplier' => to_num($d['holiday_multiplier'] ?? 2) ?: 2.0, 'burden_pct' => to_num($d['burden_pct'] ?? 0), 'allowance_daily' => to_num($d['allowance_daily'] ?? 0),
                'assigned_project_id' => (int)($d['assigned_project_id'] ?? 0) ?: null, 'supervisor_id' => (int)($d['supervisor_id'] ?? 0) ?: null, 'vendor_id' => (int)($d['vendor_id'] ?? 0) ?: null,
                'shift_start' => preg_match('/^\d{2}:\d{2}/', (string)($d['shift_start'] ?? '')) ? substr($d['shift_start'], 0, 5) : null,
                'shift_end' => preg_match('/^\d{2}:\d{2}/', (string)($d['shift_end'] ?? '')) ? substr($d['shift_end'], 0, 5) : null,
                'break_minutes' => max(0, (int)($d['break_minutes'] ?? 60)), 'phone' => mb_substr(trim((string)($d['phone'] ?? '')), 0, 40) ?: null,
                'address' => mb_substr(trim((string)($d['address'] ?? '')), 0, 255) ?: null, 'date_hired' => valid_date((string)($d['date_hired'] ?? '')) ? $d['date_hired'] : null,
                'safety_docs' => mb_substr(trim((string)($d['safety_docs'] ?? '')), 0, 255) ?: null, 'status' => in_array($d['status'] ?? '', ['active', 'inactive', 'terminated'], true) ? $d['status'] : 'active',
                'notes' => mb_substr(trim((string)($d['notes'] ?? '')), 0, 255) ?: null];
            if ($row['full_name'] === '') {
                fail('Enter the worker\'s name.');
            }
            if ($row['rate_basis'] === 'daily' && $row['daily_rate'] <= 0 && $row['trade_id']) {
                $row['daily_rate'] = (float)DB::value('SELECT daily_rate FROM cs_trades WHERE id = :t', ['t' => $row['trade_id']]);
            }
            if ($row['daily_rate'] <= 0 && $row['hourly_rate'] <= 0) {
                fail('Enter the worker\'s daily or hourly rate.');
            }
            if (in_array($row['classification'], ['labor_only', 'outsourced'], true) && !$row['vendor_id']) {
                fail('Choose the agency or labor-only subcontractor the worker comes from.');
            }
            $trade = $row['trade_id'] ? (string)DB::value('SELECT name FROM cs_trades WHERE id = :t', ['t' => $row['trade_id']]) : null;
            if ($old) {
                DB::update('cs_workers', $row, 'id = :id', ['id' => $id]);
                $on = (int)$old['other_name_id'];
                Audit::log('edit', 'labor', 'worker', $id, $old['worker_no'] . ' ' . $row['full_name'], ['page_key' => 'labor.workers', 'before' => $old, 'after' => $row]);
            } else {
                $row['worker_no'] = trim((string)($d['worker_no'] ?? '')) ?: Seq::next('cs_worker');
                if (DB::value('SELECT id FROM cs_workers WHERE worker_no = :n', ['n' => $row['worker_no']])) {
                    fail('Worker no. ' . $row['worker_no'] . ' already exists.');
                }
                $id = DB::insert('cs_workers', $row);
                $on = 0;
                Audit::log('create', 'labor', 'worker', $id, $row['worker_no'] . ' ' . $row['full_name'], ['page_key' => 'labor.workers']);
            }
            // the worker's name in accounting (Employees & Other Names) for the payroll and advances ledgers
            $w = DB::row('SELECT * FROM cs_workers WHERE id = :id', ['id' => $id]);
            $onRow = ['name_type' => 'employee', 'name' => $w['full_name'], 'employee_no' => $w['worker_no'], 'position' => $trade, 'phone' => $w['phone'], 'address' => $w['address'],
                'is_active' => $w['status'] === 'active' ? 1 : 0];
            if ($on && DB::value('SELECT id FROM other_names WHERE id = :o', ['o' => $on])) {
                DB::update('other_names', $onRow, 'id = :id', ['id' => $on]);
            } else {
                DB::update('cs_workers', ['other_name_id' => DB::insert('other_names', $onRow)], 'id = :id', ['id' => $id]);
            }
            return $id;
        });
    }

    /** Hourly rate of a worker (a daily rate is spread over the company's regular hours) */
    public static function hourly(array $w): float
    {
        $reg = max(1.0, (float)Settings::get('cs_regular_hours', '8'));
        return $w['rate_basis'] === 'hourly' && (float)$w['hourly_rate'] > 0 ? (float)$w['hourly_rate'] : round((float)$w['daily_rate'] / $reg, 4);
    }

    // ------------------------------------------------------------------ timesheets

    /** Minutes since midnight of "HH:MM", or null */
    private static function mins(?string $t): ?int
    {
        if (!$t || !preg_match('/^(\d{1,2}):(\d{2})/', $t, $m)) {
            return null;
        }
        return (int)$m[1] * 60 + (int)$m[2];
    }

    /** Works out one line: hours, lateness, cost. Returns the row for cs_timesheet_lines. */
    public static function computeLine(array $l, array $w, string $date): array
    {
        $reg = (float)Settings::get('cs_regular_hours', '8');
        $in = self::mins($l['time_in'] ?? null);
        $out = self::mins($l['time_out'] ?? null);
        $brk = isset($l['break_minutes']) && $l['break_minutes'] !== '' ? max(0, (int)$l['break_minutes']) : (int)$w['break_minutes'];
        $absent = !empty($l['is_absent']);
        $holiday = !empty($l['is_holiday']);
        $late = 0;
        $under = 0;
        if ($absent) {
            $regular = 0.0;
            $ot = 0.0;
            $in = $out = null;
        } elseif ($in !== null && $out !== null) {
            $span = $out - $in;
            if ($span <= 0) {
                $span += 1440; // past midnight
            }
            $worked = max(0, $span - $brk) / 60;
            $regular = min($reg, $worked);
            $ot = max(0, $worked - $reg);
            $ss = self::mins($w['shift_start']);
            $se = self::mins($w['shift_end']);
            if ($ss !== null && $in > $ss) {
                $late = $in - $ss;
            }
            if ($se !== null && $out < $se && $out > $in) {
                $under = $se - $out;
            }
        } else {
            $regular = max(0, to_num($l['regular_hours'] ?? 0));
            $ot = max(0, to_num($l['ot_hours'] ?? 0));
        }
        $rate = self::hourly($w);
        $mult = $holiday ? (float)$w['holiday_multiplier'] : 1.0;
        $cost = r2($regular * $rate * $mult + $ot * $rate * (float)$w['ot_multiplier'] * $mult);
        return ['worker_id' => (int)$w['id'], 'work_date' => $date, 'activity_id' => (int)($l['activity_id'] ?? 0) ?: null, 'cost_code_id' => (int)($l['cost_code_id'] ?? 0) ?: null,
            'time_in' => $in !== null ? sprintf('%02d:%02d', intdiv($in, 60), $in % 60) : null, 'time_out' => $out !== null ? sprintf('%02d:%02d', intdiv($out, 60) % 24, $out % 60) : null,
            'break_minutes' => $in !== null ? $brk : 0, 'regular_hours' => round($regular, 2), 'ot_hours' => round($ot, 2), 'late_minutes' => $late, 'undertime_minutes' => $under,
            'is_absent' => $absent ? 1 : 0, 'is_holiday' => $holiday ? 1 : 0, 'hourly_rate' => $rate, 'labor_cost' => $cost, 'burden_cost' => r2($cost * (float)$w['burden_pct'] / 100),
            'allowance' => $absent || ($regular + $ot) <= 0 ? 0.0 : (float)$w['allowance_daily'], 'remarks' => mb_substr(trim((string)($l['remarks'] ?? '')), 0, 255) ?: null,
            'correction_note' => mb_substr(trim((string)($l['correction_note'] ?? '')), 0, 255) ?: null];
    }

    public static function getTs(int $id): ?array
    {
        return DB::row('SELECT t.*, p.code AS project_code, p.name AS project_name, p.class_id FROM cs_timesheets t JOIN cs_projects p ON p.id = t.project_id WHERE t.id = :id', ['id' => $id]);
    }

    public static function saveTs(array $d, array $lines, ?int $id = null): int
    {
        return DB::tx(function () use ($d, $lines, $id) {
            $old = $id ? self::getTs($id) : null;
            if ($old && !in_array($old['status'], ['draft', 'rejected'], true)) {
                fail('Only a draft timesheet can be changed. Reopen an approved one with the reason for the correction.');
            }
            $pid = (int)($old['project_id'] ?? ($d['project_id'] ?? 0));
            $p = CsProject::get($pid);
            if (!$p) {
                fail('Choose the project.');
            }
            CsProject::requireAccess($pid);
            $date = valid_date((string)($d['work_date'] ?? '')) ? $d['work_date'] : null;
            if (!$date) {
                fail('Enter the work date.');
            }
            if ($date > today()) {
                fail('Attendance is recorded for days already worked.');
            }
            $shift = ($d['shift'] ?? '') === 'night' ? 'night' : 'day';
            $max = (float)Settings::get('cs_max_daily_hours', '16');
            $clean = [];
            $seen = [];
            foreach ($lines as $l) {
                $wid = (int)($l['worker_id'] ?? 0);
                if (!$wid) {
                    continue;
                }
                $w = DB::row('SELECT * FROM cs_workers WHERE id = :id', ['id' => $wid]);
                if (!$w) {
                    fail('A worker on the timesheet no longer exists.');
                }
                if ($w['status'] !== 'active') {
                    fail($w['full_name'] . ' is not an active worker.');
                }
                $row = self::computeLine($l, $w, $date);
                $hours = $row['regular_hours'] + $row['ot_hours'];
                if ($hours > $max) {
                    fail($w['full_name'] . ': ' . $hours . ' hours in one day is more than the maximum of ' . $max . '.');
                }
                // the same worker twice on this sheet: their times must not overlap
                foreach ($seen[$wid] ?? [] as $s) {
                    if (self::overlaps($s, $row)) {
                        fail($w['full_name'] . ' is entered twice on this timesheet at overlapping times.');
                    }
                }
                $seen[$wid][] = $row;
                $clean[] = $row;
            }
            if (!$clean) {
                fail('Add at least one worker.');
            }
            // other timesheets of the same day (any project): no overlapping times, no more than the maximum hours
            foreach ($seen as $wid => $rows) {
                $others = DB::all("SELECT l.*, t.ts_no, p.code FROM cs_timesheet_lines l JOIN cs_timesheets t ON t.id = l.timesheet_id JOIN cs_projects p ON p.id = t.project_id
                                    WHERE l.worker_id = :w AND l.work_date = :d AND t.status NOT IN ('rejected') " . ($id ? 'AND t.id <> :id' : ''), ['w' => $wid, 'd' => $date] + ($id ? ['id' => $id] : []));
                $total = array_sum(array_map(fn($r) => $r['regular_hours'] + $r['ot_hours'], $rows));
                foreach ($others as $o) {
                    $total += (float)$o['regular_hours'] + (float)$o['ot_hours'];
                    foreach ($rows as $r) {
                        if (self::overlaps($o, $r)) {
                            fail(DB::value('SELECT full_name FROM cs_workers WHERE id = :w', ['w' => $wid]) . ' is already on timesheet ' . $o['ts_no'] . ' (' . $o['code'] . ') at overlapping times that day.');
                        }
                    }
                }
                if ($total > $max) {
                    fail(DB::value('SELECT full_name FROM cs_workers WHERE id = :w', ['w' => $wid]) . ' would have ' . $total . ' hours that day across timesheets, more than the maximum of ' . $max . '.');
                }
            }
            $tot = ['h' => 0.0, 'c' => 0.0];
            foreach ($clean as $r) {
                $tot['h'] += $r['regular_hours'] + $r['ot_hours'];
                $tot['c'] += $r['labor_cost'] + $r['burden_cost'] + $r['allowance'];
            }
            $row = ['work_date' => $date, 'shift' => $shift, 'total_hours' => r2($tot['h']), 'total_cost' => r2($tot['c']), 'notes' => mb_substr(trim((string)($d['notes'] ?? '')), 0, 255) ?: null];
            if ($old) {
                DB::update('cs_timesheets', $row, 'id = :id', ['id' => $id]);
                DB::run('DELETE FROM cs_timesheet_lines WHERE timesheet_id = :t', ['t' => $id]);
            } else {
                if (DB::value("SELECT id FROM cs_timesheets WHERE project_id = :p AND work_date = :d AND shift = :s AND status <> 'rejected'", ['p' => $pid, 'd' => $date, 's' => $shift])) {
                    fail('There is already a ' . $shift . '-shift timesheet for this project on ' . fdate($date) . '. Add the workers to it.');
                }
                $id = DB::insert('cs_timesheets', $row + ['ts_no' => Seq::next('cs_timesheet'), 'project_id' => $pid, 'status' => 'draft', 'prepared_by' => Auth::id()]);
            }
            foreach ($clean as $r) {
                DB::insert('cs_timesheet_lines', $r + ['timesheet_id' => $id]);
            }
            Audit::log($old ? 'edit' : 'create', 'labor', 'timesheet', $id, $p['code'] . ' ' . fdate($date), ['page_key' => 'labor.timesheets', 'amount' => $row['total_cost'], 'reason' => $tot['h'] . ' hours']);
            return $id;
        });
    }

    /** Whether two attendance lines of one worker overlap in time (lines without times never overlap) */
    private static function overlaps(array $a, array $b): bool
    {
        $a1 = self::mins($a['time_in'] ?? null);
        $a2 = self::mins($a['time_out'] ?? null);
        $b1 = self::mins($b['time_in'] ?? null);
        $b2 = self::mins($b['time_out'] ?? null);
        if ($a1 === null || $a2 === null || $b1 === null || $b2 === null) {
            return false;
        }
        if ($a2 <= $a1) {
            $a2 += 1440;
        }
        if ($b2 <= $b1) {
            $b2 += 1440;
        }
        return $a1 < $b2 && $b1 < $a2;
    }

    /** Posts the labor cost of an approved timesheet (journal by cost code and worker) */
    public static function postTs(int $id): ?int
    {
        $t = self::getTs($id);
        if (!$t || $t['txn_id']) {
            return $t['txn_id'] ?? null;
        }
        $lines = DB::all('SELECT l.*, w.full_name, w.classification, w.vendor_id, w.other_name_id FROM cs_timesheet_lines l JOIN cs_workers w ON w.id = l.worker_id WHERE l.timesheet_id = :t', ['t' => $id]);
        $jl = [];
        $classId = (int)$t['class_id'];
        $direct = CsPost::account('cs_labor_cost');
        $indirect = CsPost::account('cs_indirect_labor');
        $burden = CsPost::account('cs_labor_burden');
        $accrued = CsPost::account('cs_accrued_payroll');
        $stat = CsPost::account('cs_statutory_payable');
        foreach ($lines as $l) {
            if ($l['vendor_id']) {
                continue; // agency and labor-only workers: the agency's bill carries the cost
            }
            $pay = r2((float)$l['labor_cost'] + (float)$l['allowance']);
            if ($pay <= 0 && (float)$l['burden_cost'] <= 0) {
                continue;
            }
            $acct = in_array($l['classification'], ['indirect', 'staff'], true) ? $indirect : CsPost::costAccount('labor', $l['cost_code_id'] ? (int)$l['cost_code_id'] : null);
            if ($acct === $indirect && !in_array($l['classification'], ['indirect', 'staff'], true)) {
                $acct = $direct;
            }
            $jl[] = ['account_id' => $acct, 'amount' => $pay, 'cost_code_id' => $l['cost_code_id'], 'description' => $l['full_name'] . ', ' . r2((float)$l['regular_hours'] + (float)$l['ot_hours']) . ' h', 'other_name_id' => $l['other_name_id']];
            $jl[] = ['account_id' => $accrued, 'amount' => -$pay, 'description' => $l['full_name'], 'other_name_id' => $l['other_name_id']];
            if ((float)$l['burden_cost'] > 0) {
                $jl[] = ['account_id' => $burden, 'amount' => (float)$l['burden_cost'], 'cost_code_id' => $l['cost_code_id'], 'description' => 'Burden ' . $l['full_name']];
                $jl[] = ['account_id' => $stat, 'amount' => -(float)$l['burden_cost'], 'description' => 'Employer share ' . $l['full_name']];
            }
        }
        $txn = null;
        if ($jl) {
            $txn = CsPost::journal($t['work_date'], 'Labor ' . $t['ts_no'] . ' ' . $t['project_code'] . ' ' . fdate($t['work_date']), $jl, $classId, $t['ts_no']);
        }
        DB::update('cs_timesheets', ['status' => 'posted', 'txn_id' => $txn], 'id = :id', ['id' => $id]);
        Audit::log('post', 'labor', 'timesheet', $id, $t['ts_no'], ['page_key' => 'labor.timesheets', 'amount' => $t['total_cost']]);
        return $txn;
    }

    /** Takes an approved timesheet back to draft for a correction (its labor posting is voided with the reason) */
    public static function reopenTs(int $id, string $reason): void
    {
        DB::tx(function () use ($id, $reason) {
            $t = self::getTs($id);
            if (!$t || !in_array($t['status'], ['approved', 'posted'], true)) {
                fail('Only an approved timesheet is reopened.');
            }
            if (trim($reason) === '') {
                fail('Give the reason for the correction.');
            }
            if ($t['payroll_run_id'] && DB::value("SELECT status FROM cs_payroll_runs WHERE id = :r", ['r' => $t['payroll_run_id']]) === 'posted') {
                fail('This timesheet was paid in a posted payroll run. Correct it on the next timesheet instead (with a correction note).');
            }
            if ($t['payroll_run_id']) {
                fail('This timesheet is on a draft payroll run. Remove the run first.');
            }
            CsPost::void($t['txn_id'] ? (int)$t['txn_id'] : null, 'Timesheet ' . $t['ts_no'] . ' reopened: ' . $reason);
            DB::update('cs_timesheets', ['status' => 'draft', 'txn_id' => null, 'approved_by' => null, 'approved_at' => null], 'id = :id', ['id' => $id]);
            Audit::log('reopen', 'labor', 'timesheet', $id, $t['ts_no'], ['page_key' => 'labor.timesheets', 'reason' => $reason]);
        });
    }

    // ------------------------------------------------------------------ approval events

    public static function workflow(string $type, string $event, int $id, array $req): void
    {
        if ($type === 'timesheet') {
            $status = ['submitted' => 'for_approval', 'approved' => 'approved', 'rejected' => 'rejected', 'returned' => 'draft', 'cancelled' => 'draft'][$event] ?? null;
            if ($status) {
                DB::update('cs_timesheets', ['status' => $status] + ($event === 'approved' ? ['approved_by' => Auth::id(), 'approved_at' => date('Y-m-d H:i:s')] : []), 'id = :id', ['id' => $id]);
            }
            if ($event === 'approved') {
                self::postTs($id);
            }
            return;
        }
        if ($type === 'payroll') {
            $status = ['submitted' => 'for_approval', 'approved' => 'approved', 'rejected' => 'rejected', 'returned' => 'draft', 'cancelled' => 'draft'][$event] ?? null;
            if ($status) {
                DB::update('cs_payroll_runs', ['status' => $status], 'id = :id', ['id' => $id]);
            }
            if ($event === 'approved') {
                self::postPayroll($id);
            }
            if ($event === 'rejected') {
                DB::run('UPDATE cs_timesheets SET payroll_run_id = NULL WHERE payroll_run_id = :r', ['r' => $id]);
            }
            return;
        }
        if ($type === 'cash_advance') {
            $status = ['submitted' => 'for_approval', 'approved' => 'approved', 'rejected' => 'rejected', 'returned' => 'for_approval', 'cancelled' => 'rejected'][$event] ?? null;
            if ($status) {
                DB::update('cs_cash_advances', ['status' => $status] + ($event === 'approved' ? ['approved_by' => Auth::id()] : []), 'id = :id', ['id' => $id]);
            }
        }
    }

    // ------------------------------------------------------------------ payroll

    /** Builds a draft payroll run from the approved timesheets of the period not paid yet */
    public static function buildPayroll(string $from, string $to, ?string $payDate, string $notes = ''): int
    {
        return DB::tx(function () use ($from, $to, $payDate, $notes) {
            if (!valid_date($from) || !valid_date($to) || $to < $from) {
                fail('Enter the pay period.');
            }
            $sheets = DB::column("SELECT id FROM cs_timesheets WHERE status = 'posted' AND payroll_run_id IS NULL AND work_date BETWEEN :f AND :t", ['f' => $from, 't' => $to]);
            $waiting = (int)DB::value("SELECT COUNT(*) FROM cs_timesheets WHERE status IN ('draft','for_approval','approved') AND work_date BETWEEN :f AND :t", ['f' => $from, 't' => $to]);
            if (!$sheets) {
                fail('No approved timesheet in this period is waiting to be paid.' . ($waiting ? ' ' . $waiting . ' timesheet(s) of the period are not approved yet.' : ''));
            }
            $id = DB::insert('cs_payroll_runs', ['run_no' => Seq::next('cs_payroll'), 'period_from' => $from, 'period_to' => $to, 'pay_date' => valid_date((string)$payDate) ? $payDate : null,
                'status' => 'draft', 'notes' => mb_substr($notes, 0, 255) ?: null, 'created_by' => Auth::id()]);
            [$in, $pp] = DB::in('prs', array_map('intval', $sheets));
            DB::run("UPDATE cs_timesheets SET payroll_run_id = :r WHERE id IN $in", ['r' => $id] + $pp);
            foreach (DB::all("SELECT l.worker_id, COUNT(DISTINCT CASE WHEN l.regular_hours + l.ot_hours > 0 THEN l.work_date END) AS days, SUM(l.regular_hours) AS reg, SUM(l.ot_hours) AS ot,
                                     SUM(l.labor_cost) AS gross, SUM(l.allowance) AS allow, SUM(l.burden_cost) AS burden
                                FROM cs_timesheet_lines l JOIN cs_workers w ON w.id = l.worker_id WHERE l.timesheet_id IN $in AND w.vendor_id IS NULL GROUP BY l.worker_id", $pp) as $r) {
                $gross = r2((float)$r['gross'] + (float)$r['allow']);
                $adv = 0.0;
                $left = $gross;
                foreach (DB::all("SELECT * FROM cs_cash_advances WHERE worker_id = :w AND status = 'released' AND amount > deducted ORDER BY advance_date, id", ['w' => $r['worker_id']]) as $ca) {
                    $take = min($left, (float)$ca['amount'] - (float)$ca['deducted']);
                    if ($take <= 0) {
                        break;
                    }
                    $adv += $take;
                    $left -= $take;
                }
                DB::insert('cs_payroll_lines', ['run_id' => $id, 'worker_id' => $r['worker_id'], 'days_worked' => $r['days'], 'regular_hours' => $r['reg'], 'ot_hours' => $r['ot'],
                    'gross_pay' => r2($r['gross']), 'allowances' => r2($r['allow']), 'burden' => r2($r['burden']), 'advance_deduction' => r2($adv), 'other_deductions' => 0, 'net_pay' => r2($gross - $adv)]);
            }
            self::totals($id);
            Audit::log('create', 'labor', 'payroll', $id, 'Payroll ' . fdate($from) . ' to ' . fdate($to), ['page_key' => 'labor.payroll']);
            return $id;
        });
    }

    public static function totals(int $runId): void
    {
        $t = DB::row('SELECT SUM(gross_pay + allowances) AS g, SUM(burden) AS b, SUM(advance_deduction + other_deductions) AS d, SUM(net_pay) AS n FROM cs_payroll_lines WHERE run_id = :r', ['r' => $runId]);
        DB::update('cs_payroll_runs', ['total_gross' => r2($t['g']), 'total_burden' => r2($t['b']), 'total_deductions' => r2($t['d']), 'total_net' => r2($t['n'])], 'id = :id', ['id' => $runId]);
    }

    /** Edits the other deductions (employee share of SSS, PhilHealth, Pag-IBIG, loans) of a draft run */
    public static function setDeductions(int $runId, array $other): void
    {
        DB::tx(function () use ($runId, $other) {
            $r = DB::row('SELECT * FROM cs_payroll_runs WHERE id = :id', ['id' => $runId]);
            if (!$r || !in_array($r['status'], ['draft', 'rejected'], true)) {
                fail('Only a draft payroll run is changed.');
            }
            foreach ($other as $lineId => $amt) {
                $l = DB::row('SELECT * FROM cs_payroll_lines WHERE id = :l AND run_id = :r', ['l' => (int)$lineId, 'r' => $runId]);
                if (!$l) {
                    continue;
                }
                $od = max(0, to_num($amt));
                $gross = (float)$l['gross_pay'] + (float)$l['allowances'];
                if ($od + (float)$l['advance_deduction'] > $gross + 0.005) {
                    fail('Deductions cannot be more than the gross pay of a worker.');
                }
                DB::update('cs_payroll_lines', ['other_deductions' => r2($od), 'net_pay' => r2($gross - (float)$l['advance_deduction'] - $od)], 'id = :id', ['id' => $l['id']]);
            }
            self::totals($runId);
            Audit::log('edit', 'labor', 'payroll', $runId, $r['run_no'], ['page_key' => 'labor.payroll', 'reason' => 'Deductions changed']);
        });
    }

    /** Posts an approved payroll run: clears the accrued payroll of each worker and project */
    public static function postPayroll(int $runId): int
    {
        $r = DB::row('SELECT * FROM cs_payroll_runs WHERE id = :id', ['id' => $runId]);
        if (!$r || $r['txn_id']) {
            return (int)($r['txn_id'] ?? 0);
        }
        $accrued = CsPost::account('cs_accrued_payroll');
        $wages = CsPost::account('cs_wages_payable');
        $stat = CsPost::account('cs_statutory_payable');
        $adv = CsPost::account('cs_employee_advances');
        $jl = [];
        // what each worker accrued on each project in the run (class kept, so each project's accrual is cleared)
        foreach (DB::all("SELECT l.worker_id, w.other_name_id, w.full_name, p.class_id, SUM(l.labor_cost + l.allowance) AS amt
                            FROM cs_timesheet_lines l JOIN cs_timesheets t ON t.id = l.timesheet_id JOIN cs_projects p ON p.id = t.project_id JOIN cs_workers w ON w.id = l.worker_id
                           WHERE t.payroll_run_id = :r AND w.vendor_id IS NULL GROUP BY l.worker_id, w.other_name_id, w.full_name, p.class_id", ['r' => $runId]) as $a) {
            $jl[] = ['account_id' => $accrued, 'amount' => r2($a['amt']), 'class_id' => (int)$a['class_id'], 'other_name_id' => $a['other_name_id'], 'description' => 'Payroll ' . $a['full_name']];
        }
        foreach (DB::all('SELECT l.*, w.other_name_id, w.full_name FROM cs_payroll_lines l JOIN cs_workers w ON w.id = l.worker_id WHERE l.run_id = :r', ['r' => $runId]) as $l) {
            $jl[] = ['account_id' => $wages, 'amount' => -(float)$l['net_pay'], 'other_name_id' => $l['other_name_id'], 'description' => 'Net pay ' . $l['full_name']];
            if ((float)$l['advance_deduction'] > 0) {
                $jl[] = ['account_id' => $adv, 'amount' => -(float)$l['advance_deduction'], 'other_name_id' => $l['other_name_id'], 'description' => 'Advance deducted ' . $l['full_name']];
                $left = (float)$l['advance_deduction'];
                foreach (DB::all("SELECT * FROM cs_cash_advances WHERE worker_id = :w AND status = 'released' AND amount > deducted ORDER BY advance_date, id", ['w' => $l['worker_id']]) as $ca) {
                    $take = min($left, (float)$ca['amount'] - (float)$ca['deducted']);
                    if ($take <= 0) {
                        break;
                    }
                    $nd = r2((float)$ca['deducted'] + $take);
                    DB::update('cs_cash_advances', ['deducted' => $nd, 'payroll_run_id' => $runId, 'status' => $nd + 0.005 >= (float)$ca['amount'] ? 'deducted' : 'released'], 'id = :id', ['id' => $ca['id']]);
                    $left -= $take;
                }
            }
            if ((float)$l['other_deductions'] > 0) {
                $jl[] = ['account_id' => $stat, 'amount' => -(float)$l['other_deductions'], 'other_name_id' => $l['other_name_id'], 'description' => 'Employee share and deductions ' . $l['full_name']];
            }
        }
        $txn = CsPost::journal($r['pay_date'] ?: $r['period_to'], 'Payroll ' . $r['run_no'] . ' ' . fdate($r['period_from']) . ' to ' . fdate($r['period_to']), $jl, null, $r['run_no']);
        DB::update('cs_payroll_runs', ['status' => 'posted', 'txn_id' => $txn, 'posted_by' => Auth::id(), 'posted_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $runId]);
        Audit::log('post', 'labor', 'payroll', $runId, $r['run_no'], ['page_key' => 'labor.payroll', 'amount' => $r['total_gross']]);
        return $txn;
    }

    public static function deleteDraftRun(int $runId, string $reason): void
    {
        DB::tx(function () use ($runId, $reason) {
            $r = DB::row('SELECT * FROM cs_payroll_runs WHERE id = :id', ['id' => $runId]);
            if (!$r || !in_array($r['status'], ['draft', 'rejected'], true)) {
                fail('Only a draft payroll run is removed.');
            }
            if (CsApproval::pendingFor('payroll', $runId)) {
                fail('Withdraw it from approval first.');
            }
            DB::run('UPDATE cs_timesheets SET payroll_run_id = NULL WHERE payroll_run_id = :r', ['r' => $runId]);
            DB::update('cs_payroll_runs', ['status' => 'void', 'notes' => mb_substr(trim((string)$r['notes'] . ' Removed: ' . $reason), 0, 255)], 'id = :id', ['id' => $runId]);
            DB::run('DELETE FROM cs_payroll_lines WHERE run_id = :r', ['r' => $runId]);
            Audit::log('void', 'labor', 'payroll', $runId, $r['run_no'], ['page_key' => 'labor.payroll', 'reason' => $reason]);
        });
    }

    // ------------------------------------------------------------------ cash advances

    public static function requestAdvance(array $d): int
    {
        return DB::tx(function () use ($d) {
            $w = DB::row('SELECT * FROM cs_workers WHERE id = :id', ['id' => (int)($d['worker_id'] ?? 0)]);
            if (!$w || $w['status'] !== 'active') {
                fail('Choose an active worker.');
            }
            if ($w['vendor_id']) {
                fail('Agency workers get advances from their agency.');
            }
            $amt = r2(to_num($d['amount'] ?? 0));
            if ($amt <= 0) {
                fail('Enter the amount.');
            }
            $open = (float)DB::value("SELECT COALESCE(SUM(amount - deducted),0) FROM cs_cash_advances WHERE worker_id = :w AND status IN ('for_approval','approved','released')", ['w' => $w['id']]);
            $id = DB::insert('cs_cash_advances', ['ca_no' => Seq::next('cs_advance'), 'worker_id' => $w['id'], 'project_id' => (int)($d['project_id'] ?? 0) ?: $w['assigned_project_id'],
                'advance_date' => valid_date((string)($d['advance_date'] ?? '')) ? $d['advance_date'] : today(), 'amount' => $amt,
                'purpose' => mb_substr(trim((string)($d['purpose'] ?? '')), 0, 255) ?: null, 'status' => 'for_approval', 'created_by' => Auth::id()]);
            $ca = DB::row('SELECT * FROM cs_cash_advances WHERE id = :id', ['id' => $id]);
            CsApproval::submit('cash_advance', $id, $ca['ca_no'] . ' ' . $w['full_name'], $ca['project_id'] ? (int)$ca['project_id'] : null, $amt,
                $open > 0 ? 'Worker still owes ' . money($open) . ' on earlier advances.' : '');
            return $id;
        });
    }

    /** Releases an approved advance in cash: a cash voucher Dr Employee Advances (worker) / Cr the cash account */
    public static function releaseAdvance(int $id, int $cashAccount, ?string $date = null): int
    {
        return DB::tx(function () use ($id, $cashAccount, $date) {
            $ca = DB::row('SELECT a.*, w.full_name, w.other_name_id FROM cs_cash_advances a JOIN cs_workers w ON w.id = a.worker_id WHERE a.id = :id', ['id' => $id]);
            if (!$ca || $ca['status'] !== 'approved') {
                fail('Only an approved advance is released.');
            }
            if (!$cashAccount || DB::value('SELECT account_type FROM accounts WHERE id = :a', ['a' => $cashAccount]) !== 'Bank') {
                fail('Choose the cash or bank account the advance is paid from.');
            }
            $classId = $ca['project_id'] ? CsProject::classId((int)$ca['project_id']) : null;
            $txn = Txn::save(Posting::CASH_VOUCHER, ['txn_date' => $date && valid_date($date) ? $date : today(), 'account_id' => $cashAccount, 'other_name_id' => $ca['other_name_id'],
                'payee_name' => $ca['full_name'], 'memo' => 'Cash advance ' . $ca['ca_no'] . ($ca['purpose'] ? ': ' . $ca['purpose'] : ''), 'class_id' => $classId, 'total' => $ca['amount']],
                [['line_kind' => 'journal', 'account_id' => CsPost::account('cs_employee_advances'), 'amount' => (float)$ca['amount'], 'other_name_id' => $ca['other_name_id'],
                    'description' => 'Cash advance ' . $ca['full_name'], 'class_id' => $classId]], null, ['keep_totals' => true, 'reason' => 'Cash advance released']);
            DB::update('cs_cash_advances', ['status' => 'released', 'txn_id' => $txn, 'cash_account_id' => $cashAccount], 'id = :id', ['id' => $id]);
            Audit::log('release', 'labor', 'cash_advance', $id, $ca['ca_no'], ['page_key' => 'labor.advances', 'amount' => $ca['amount']]);
            return $txn;
        });
    }
}
