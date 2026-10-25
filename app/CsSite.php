<?php
declare(strict_types=1);

/**
 * Daily site reports, inspections, non-conformances and safety records.
 *
 * A daily report is one per project and day. Its accomplishment lines say what was done on which activity; when the
 * report is approved the quantities are added to the activities, which moves the schedule's physical progress (and so
 * earned value and percentage-of-completion revenue). Photos are attached to the report.
 */
final class CsSite
{
    public const WEATHER = ['sunny' => 'Sunny', 'cloudy' => 'Cloudy', 'light_rain' => 'Light rain', 'heavy_rain' => 'Heavy rain', 'storm' => 'Storm / typhoon', 'hot' => 'Very hot'];

    public static function get(int $id): ?array
    {
        return DB::row('SELECT r.*, p.code AS project_code, p.name AS project_name, u.full_name AS by_name FROM cs_daily_reports r JOIN cs_projects p ON p.id = r.project_id
                         LEFT JOIN users u ON u.id = r.created_by WHERE r.id = :id', ['id' => $id]);
    }

    /** What the other records say about a day on a project (to prefill and to cross-check the report) */
    public static function dayFacts(int $projectId, string $date): array
    {
        $ts = DB::row("SELECT COUNT(DISTINCT CASE WHEN l.is_absent = 0 THEN l.worker_id END) AS workers, COALESCE(SUM(l.regular_hours + l.ot_hours),0) AS hours
                         FROM cs_timesheet_lines l JOIN cs_timesheets t ON t.id = l.timesheet_id WHERE t.project_id = :p AND t.work_date = :d AND t.status <> 'rejected'", ['p' => $projectId, 'd' => $date]);
        $eq = DB::all("SELECT e.code, e.name, l.operating_hours, l.idle_hours FROM cs_equipment_logs l JOIN cs_equipment e ON e.id = l.equipment_id WHERE l.project_id = :p AND l.log_date = :d AND l.status <> 'rejected'", ['p' => $projectId, 'd' => $date]);
        $mat = DB::all("SELECT l.description, SUM(l.qty) AS q, l.unit FROM cs_material_doc_lines l JOIN cs_material_docs d ON d.id = l.doc_id
                         WHERE d.project_id = :p AND d.doc_date = :d AND d.doc_type IN ('issue','consume') AND d.status = 'posted' GROUP BY l.description, l.unit", ['p' => $projectId, 'd' => $date]);
        return ['workers' => (int)$ts['workers'], 'hours' => (float)$ts['hours'],
            'equipment' => implode(', ', array_map(fn($e) => $e['code'] . ' ' . $e['name'] . ' (' . (float)$e['operating_hours'] . ' h)', $eq)),
            'materials' => implode("\n", array_map(fn($m) => $m['description'] . ': ' . qty($m['q']) . ' ' . $m['unit'], $mat))];
    }

    public static function save(array $d, array $lines, ?int $id = null): int
    {
        return DB::tx(function () use ($d, $lines, $id) {
            $old = $id ? self::get($id) : null;
            if ($old && !in_array($old['status'], ['draft', 'returned'], true)) {
                fail('Only a draft or returned report is changed.');
            }
            $pid = (int)($old['project_id'] ?? ($d['project_id'] ?? 0));
            if (!CsProject::get($pid)) {
                fail('Choose the project.');
            }
            CsProject::requireAccess($pid);
            $date = valid_date((string)($d['report_date'] ?? '')) ? $d['report_date'] : null;
            if (!$date || $date > today()) {
                fail('Enter the date of the report (today or earlier).');
            }
            if (DB::value('SELECT id FROM cs_daily_reports WHERE project_id = :p AND report_date = :d' . ($id ? ' AND id <> :id' : ''), ['p' => $pid, 'd' => $date] + ($id ? ['id' => $id] : []))) {
                fail('There is already a report for this project on ' . fdate($date) . '. Open it and add to it.');
            }
            $clean = [];
            $mh = 0.0;
            foreach ($lines as $l) {
                $desc = trim((string)($l['description'] ?? ''));
                $act = (int)($l['activity_id'] ?? 0) ?: null;
                if ($desc === '' && !$act) {
                    continue;
                }
                if ($desc === '' && $act) {
                    $desc = (string)DB::value('SELECT name FROM cs_activities WHERE id = :a', ['a' => $act]);
                }
                if ($act && !DB::value('SELECT id FROM cs_activities WHERE id = :a AND project_id = :p', ['a' => $act, 'p' => $pid])) {
                    fail('An activity on the report is not part of this project.');
                }
                $q = max(0, to_num($l['qty_done'] ?? 0));
                $clean[] = ['activity_id' => $act, 'cost_code_id' => (int)($l['cost_code_id'] ?? 0) ?: ($act ? DB::value('SELECT cost_code_id FROM cs_activities WHERE id = :a', ['a' => $act]) : null),
                    'description' => mb_substr($desc, 0, 255), 'qty_done' => $q, 'unit' => mb_substr(trim((string)($l['unit'] ?? '')), 0, 20) ?: ($act ? DB::value('SELECT unit FROM cs_activities WHERE id = :a', ['a' => $act]) : null),
                    'workers' => max(0, (int)($l['workers'] ?? 0)), 'man_hours' => max(0, to_num($l['man_hours'] ?? 0)), 'remarks' => mb_substr(trim((string)($l['remarks'] ?? '')), 0, 255) ?: null];
                $mh += max(0, to_num($l['man_hours'] ?? 0));
            }
            $txt = fn($k) => trim((string)($d[$k] ?? '')) ?: null;
            $row = ['report_date' => $date, 'weather_am' => isset(self::WEATHER[$d['weather_am'] ?? '']) ? $d['weather_am'] : null, 'weather_pm' => isset(self::WEATHER[$d['weather_pm'] ?? '']) ? $d['weather_pm'] : null,
                'site_conditions' => mb_substr((string)$txt('site_conditions'), 0, 255) ?: null, 'workers_count' => max(0, (int)($d['workers_count'] ?? 0)),
                'man_hours' => ($d['man_hours'] ?? '') !== '' ? max(0, to_num($d['man_hours'])) : $mh, 'equipment_used' => mb_substr((string)$txt('equipment_used'), 0, 255) ?: null,
                'materials_used' => $txt('materials_used'), 'delays' => $txt('delays'), 'delay_hours' => max(0, to_num($d['delay_hours'] ?? 0)), 'safety_observations' => $txt('safety_observations'),
                'inspections' => $txt('inspections'), 'instructions' => $txt('instructions'), 'issues' => $txt('issues'), 'remarks' => $txt('remarks')];
            if ($old) {
                DB::update('cs_daily_reports', $row, 'id = :id', ['id' => $id]);
                DB::run('DELETE FROM cs_daily_report_lines WHERE report_id = :r', ['r' => $id]);
            } else {
                $id = DB::insert('cs_daily_reports', $row + ['report_no' => Seq::next('cs_daily'), 'project_id' => $pid, 'status' => 'draft', 'created_by' => Auth::id()]);
            }
            foreach ($clean as $l) {
                DB::insert('cs_daily_report_lines', $l + ['report_id' => $id]);
            }
            $p = CsProject::get($pid);
            Audit::log($old ? 'edit' : 'create', 'site', 'daily_report', $id, $p['code'] . ' ' . fdate($date), ['page_key' => 'site.daily', 'reason' => count($clean) . ' accomplishment line(s)']);
            return $id;
        });
    }

    /** Adds the accomplishments of an approved report to the schedule */
    public static function applyProgress(int $id): void
    {
        $r = self::get($id);
        foreach (DB::all('SELECT l.*, a.planned_qty, a.progress_pct FROM cs_daily_report_lines l JOIN cs_activities a ON a.id = l.activity_id WHERE l.report_id = :r AND l.qty_done > 0', ['r' => $id]) as $l) {
            if ((float)$l['planned_qty'] > 0) {
                CsPlanning::addProgress((int)$l['activity_id'], (float)$l['qty_done'], $r['report_date']);
            } else {
                // activities measured in percent: the quantity is the percent done that day
                $pct = min(100, (float)$l['progress_pct'] + (float)$l['qty_done']);
                $a = DB::row('SELECT * FROM cs_activities WHERE id = :a', ['a' => $l['activity_id']]);
                DB::update('cs_activities', ['progress_pct' => $pct, 'status' => $pct >= 100 ? 'completed' : 'in_progress', 'actual_start' => $a['actual_start'] ?: $r['report_date'],
                    'actual_finish' => $pct >= 100 ? ($a['actual_finish'] ?: $r['report_date']) : null], 'id = :id', ['id' => $l['activity_id']]);
            }
        }
        // summary rows follow their children
        foreach (DB::all('SELECT DISTINCT parent_id FROM cs_activities WHERE project_id = :p AND parent_id IS NOT NULL', ['p' => $r['project_id']]) as $x) {
            $agg = DB::row('SELECT SUM(weight_pct * progress_pct) / NULLIF(SUM(weight_pct),0) AS pr, AVG(progress_pct) AS av FROM cs_activities WHERE parent_id = :p', ['p' => $x['parent_id']]);
            $pr = round((float)($agg['pr'] ?? $agg['av']), 2);
            DB::update('cs_activities', ['progress_pct' => $pr, 'status' => $pr >= 100 ? 'completed' : ($pr > 0 ? 'in_progress' : 'not_started')], 'id = :id', ['id' => $x['parent_id']]);
        }
        $p = CsProject::get((int)$r['project_id']);
        if ($p['status'] === 'mobilization' || $p['status'] === 'planning') {
            CsProject::setStatus((int)$r['project_id'], 'active', 'Work started (daily report ' . $r['report_no'] . ')');
        }
        CsProject::flush();
    }

    public static function workflow(string $type, string $event, int $id, array $req): void
    {
        if ($type !== 'daily_report') {
            return;
        }
        $row = match ($event) {
            'submitted' => ['status' => 'for_approval', 'submitted_by' => Auth::id(), 'submitted_at' => date('Y-m-d H:i:s')],
            'approved' => ['status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => date('Y-m-d H:i:s')],
            'returned', 'rejected' => ['status' => 'returned'],
            'cancelled' => ['status' => 'draft'],
            default => null,
        };
        if ($row) {
            DB::update('cs_daily_reports', $row, 'id = :id', ['id' => $id]);
        }
        if ($event === 'approved') {
            self::applyProgress($id);
        }
    }
}
