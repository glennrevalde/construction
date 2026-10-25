<?php
declare(strict_types=1);

/**
 * Fixed asset maintenance.
 *
 *  - Maintenance records (Fixed Assets > Asset Maintenance): what was done to an asset, the spare parts used
 *    (part no., serial, model, size ...), the labor (man-hours or a bulk charge), pictures, and the supplier.
 *    The cost is disbursed through accounting: saving the record creates (and later keeps in step) a Bill,
 *    a Check Voucher or a Cash Voucher, so the expense, the payable / bank / cash and the class are posted
 *    by the same engine as every other transaction.
 *  - Preventive maintenance (Fixed Assets > Preventive Maintenance): tasks that fall due by date and / or by
 *    meter (vehicle registration, change oil, cleaning of air-conditioning units, computers, solar panels ...).
 *    A task keeps reminding until a user acknowledges that it is complete; a repeating task then moves to its next due date.
 */
final class AssetMaint
{
    public const TYPES = [
        'repair'     => 'Repair',
        'preventive' => 'Preventive maintenance',
        'inspection' => 'Inspection / testing',
        'upgrade'    => 'Upgrade / overhaul',
    ];

    public const STATUSES = ['completed' => 'Completed', 'in_progress' => 'In progress', 'void' => 'Void'];

    public const PAYMENT_MODES = [
        'bill'         => 'On account: enter a Bill (pay later in Pay Bills)',
        'check'        => 'Paid by check: write a Check Voucher',
        'cash_voucher' => 'Paid in cash: petty cash / revolving fund Cash Voucher',
        'none'         => 'No entry: already recorded elsewhere, or no cost',
    ];

    public const TASK_TYPES = [
        'registration' => 'Vehicle registration renewal',
        'insurance'    => 'Insurance renewal',
        'change_oil'   => 'Change oil',
        'tune_up'      => 'Tune-up / servicing',
        'cleaning'     => 'Cleaning (air-con, computers, solar panels ...)',
        'inspection'   => 'Inspection / calibration / testing',
        'permit'       => 'Permit / license renewal',
        'other'        => 'Other maintenance',
    ];

    public const INTERVAL_UNITS = ['days' => 'day(s)', 'weeks' => 'week(s)', 'months' => 'month(s)', 'years' => 'year(s)'];

    public const PHOTO_DIR = '/storage/maintenance';
    public const PHOTO_TYPES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
    public const PHOTO_MAX_BYTES = 8388608;   // 8 MB each

    // ------------------------------------------------------------------ records

    public static function load(int $id): ?array
    {
        return DB::row(
            'SELECT m.*, fa.asset_no, fa.description AS asset_description, fa.location AS asset_location, fa.serial_no AS asset_serial, fa.site_id AS asset_site_id,
                    v.name AS vendor_name, u.full_name AS created_by_name
               FROM asset_maintenance m
               JOIN fixed_assets fa ON fa.id = m.asset_id
               LEFT JOIN vendors v ON v.id = m.vendor_id
               LEFT JOIN users u ON u.id = m.created_by
              WHERE m.id = :id',
            ['id' => $id]
        );
    }

    public static function parts(int $id): array
    {
        return DB::all('SELECT * FROM asset_maintenance_parts WHERE maintenance_id = :m ORDER BY line_no, id', ['m' => $id]);
    }

    public static function labor(int $id): array
    {
        return DB::all('SELECT * FROM asset_maintenance_labor WHERE maintenance_id = :m ORDER BY line_no, id', ['m' => $id]);
    }

    public static function photos(int $id): array
    {
        return DB::all('SELECT * FROM asset_maintenance_photos WHERE maintenance_id = :m ORDER BY id', ['m' => $id]);
    }

    public static function typeLabel(?string $t): string
    {
        return self::TYPES[$t] ?? (string)$t;
    }

    /** Default expense account of repairs: the account named "Repairs and Maintenance", else the default purchases / expense account */
    public static function defaultExpenseAccount(): ?int
    {
        $id = DB::value("SELECT id FROM accounts WHERE is_active = 1 AND account_type IN ('Expense','Other Expense','Cost of Goods Sold') AND name LIKE '%Repair%' ORDER BY code LIMIT 1");
        if ($id) {
            return (int)$id;
        }
        try {
            return Accts::get('default_expense');
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Creates, updates or voids the accounting transaction behind a maintenance record.
     * $m = the record as saved (with parts_total, labor_total, with_vat, payment_mode ...). Returns the transaction id or null.
     */
    public static function syncTransaction(array $m, ?array $old): ?int
    {
        $mode = (string)$m['payment_mode'];
        $oldTxn = $old && $old['txn_id'] ? Txn::load((int)$old['txn_id']) : null;
        if ($oldTxn && (int)$oldTxn['is_voided'] === 1) {
            $oldTxn = null;
        }
        $typeOf = ['bill' => 'bill', 'check' => 'check', 'cash_voucher' => 'cash_voucher'];
        $wantType = ($m['status'] === 'void') ? null : ($typeOf[$mode] ?? null);
        $parts = r2((float)$m['parts_total']);
        $labor = r2((float)$m['labor_total']);
        if ($wantType && $parts + $labor <= 0) {
            fail('Enter the parts or the labor cost, or choose "No entry" when there is nothing to pay.');
        }

        // the kind of disbursement changed, or the record no longer has one: take the old transaction out
        if ($oldTxn && $oldTxn['txn_type'] !== $wantType) {
            Txn::void((int)$oldTxn['id'], 'Maintenance record ' . $m['maint_no'] . ($wantType ? ' now paid another way' : ' no longer has a disbursement'));
            $oldTxn = null;
        }
        if (!$wantType) {
            return null;
        }

        $pageOf = ['bill' => 'vend.bills', 'check' => 'bank.write_checks', 'cash_voucher' => 'bank.cash_voucher'];
        if (!Access::can($pageOf[$wantType], $oldTxn ? 'edit' : 'create')) {
            fail('Your role cannot ' . ($oldTxn ? 'change' : 'create') . ' a ' . Txn::label($wantType) . '. Ask someone who can, or save the record with "No entry" and have accounting record the payment.');
        }
        $partsAcct = (int)($m['parts_account_id'] ?: 0);
        $laborAcct = (int)($m['labor_account_id'] ?: $partsAcct);
        if (($parts > 0 && !$partsAcct) || ($labor > 0 && !$laborAcct)) {
            fail('Choose the expense account for the parts and the labor.');
        }
        $vendorId = $m['vendor_id'] ? (int)$m['vendor_id'] : null;
        $asset = DB::row('SELECT asset_no, description, site_id FROM fixed_assets WHERE id = :id', ['id' => $m['asset_id']]);
        $about = $m['maint_no'] . ' ' . $asset['asset_no'] . ' ' . $asset['description'];
        $memo = mb_substr(self::typeLabel($m['maint_type']) . ' of ' . $asset['asset_no'] . ' ' . $asset['description'] . ' (' . $m['maint_no'] . ')', 0, 255);
        $withVat = (int)$m['with_vat'] === 1;
        $taxId = $withVat ? (int)DB::value("SELECT id FROM tax_codes WHERE code = 'VAT12'") : 0;
        $rate = $taxId ? tax_rate($taxId) : 0.0;
        $classId = Classes::ready() ? Classes::valid($m['class_id'] ?? null) : null;
        $total = r2($parts + $labor + ($withVat ? r2($parts * $rate / 100) + r2($labor * $rate / 100) : 0));

        $h = ['txn_date' => $m['maint_date'], 'vendor_id' => $vendorId, 'ref_no' => $m['ref_no'] ?: null, 'memo' => $memo];
        if (Classes::ready()) {
            $h['class_id'] = $classId;
        }
        $opt = ['reason' => 'Maintenance record ' . $m['maint_no']];
        $lines = [];

        if ($wantType === 'cash_voucher') {
            if (!$m['pay_account_id']) {
                fail('Choose the cash account the voucher is paid from.');
            }
            if ($parts > 0) {
                $lines[] = ['line_kind' => 'journal', 'account_id' => $partsAcct, 'amount' => $parts, 'description' => mb_substr('Parts - ' . $about, 0, 255), 'vendor_id' => $vendorId];
            }
            if ($labor > 0) {
                $lines[] = ['line_kind' => 'journal', 'account_id' => $laborAcct, 'amount' => $labor, 'description' => mb_substr('Labor - ' . $about, 0, 255), 'vendor_id' => $vendorId];
            }
            if ($withVat && $total - $parts - $labor > 0) {
                $lines[] = ['line_kind' => 'journal', 'account_id' => Accts::get('input_tax'), 'amount' => r2($total - $parts - $labor), 'description' => 'Input VAT - ' . $m['maint_no']];
            }
            $approver = Approval::check('bank.cash_voucher', Approval::exceeds(Access::limit('max_amount'), $total), 'cash voucher above your amount limit (' . money($total) . ')');
            $h += ['account_id' => (int)$m['pay_account_id'], 'payee_name' => $vendorId ? null : (mb_substr((string)$m['performed_by'], 0, 120) ?: 'Maintenance ' . $m['maint_no']),
                'subtotal' => $total, 'total' => $total, 'status' => 'open'];
            $opt['keep_totals'] = true;
        } else {
            $mk = function (int $acct, float $amt, string $label) use ($taxId, $rate, $about) {
                return ['line_kind' => 'expense', 'account_id' => $acct, 'amount' => $amt, 'description' => mb_substr($label . ' - ' . $about, 0, 255),
                    'tax_code_id' => $taxId ?: null, 'tax_amount' => $taxId ? r2($amt * $rate / 100) : 0];
            };
            if ($parts > 0) {
                $lines[] = $mk($partsAcct, $parts, 'Parts');
            }
            if ($labor > 0) {
                $lines[] = $mk($laborAcct, $labor, 'Labor');
            }
            $approver = null;
            if ($wantType === 'bill') {
                if (!$vendorId) {
                    fail('Choose the supplier: a bill is owed to a supplier.');
                }
                $terms = DB::value('SELECT terms_id FROM vendors WHERE id = :v', ['v' => $vendorId]);
                $h += ['account_id' => Accts::get('ap'), 'terms_id' => $terms ?: null, 'due_date' => Txn::dueDate($m['maint_date'], $terms ? (int)$terms : null)];
            } else {
                if (!$m['pay_account_id']) {
                    fail('Choose the bank account the check is drawn on.');
                }
                $approver = Approval::check('bank.write_checks', Approval::exceeds(Access::limit('max_amount'), $total), 'check above your amount limit (' . money($total) . ')');
                $h += ['account_id' => (int)$m['pay_account_id'], 'payee_name' => $vendorId ? null : (mb_substr((string)$m['performed_by'], 0, 120) ?: 'Maintenance ' . $m['maint_no'])];
            }
            if ($asset['site_id']) {
                $h['site_id'] = (int)$asset['site_id'];
            }
        }
        if (!empty($approver)) {
            $h['approved_by'] = $approver['id'];
            $opt['approved_by'] = $approver['username'];
        }
        if ($oldTxn) {
            // keep the line ids so the ledger lines are updated, not duplicated
            $ids = DB::column('SELECT id FROM transaction_lines WHERE txn_id = :t ORDER BY line_no, id', ['t' => $oldTxn['id']]);
            foreach ($lines as $i => &$l) {
                if (isset($ids[$i])) {
                    $l['id'] = (int)$ids[$i];
                }
            }
            unset($l);
        }
        return Txn::save($wantType, $h, $lines, $oldTxn ? (int)$oldTxn['id'] : null, $opt);
    }

    // ------------------------------------------------------------------ pictures

    /** The company's own folder for maintenance pictures (storage/maintenance for the first company) */
    public static function photoDir(): string
    {
        return Tenant::storage('maintenance');
    }

    public static function photoPath(array $photo): string
    {
        return self::photoDir() . '/' . basename((string)$photo['file_name']);
    }

    /** Stores the pictures uploaded on the form ($_FILES['photos'], multiple). Returns how many were saved. */
    public static function storePhotos(int $maintenanceId, ?array $files, array $captions = []): int
    {
        if (!$files || !isset($files['name']) || !is_array($files['name'])) {
            return 0;
        }
        $dir = self::photoDir();
        $saved = 0;
        foreach ($files['name'] as $i => $name) {
            $err = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($err !== UPLOAD_ERR_OK) {
                fail('The picture ' . $name . ' could not be uploaded' . (in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? ': the file is too large.' : '.'));
            }
            if ((int)$files['size'][$i] > self::PHOTO_MAX_BYTES) {
                fail('The picture ' . $name . ' is larger than 8 MB.');
            }
            $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
            $info = @getimagesize($files['tmp_name'][$i]);
            if (!isset(self::PHOTO_TYPES[$ext]) || !$info || !in_array($info['mime'], self::PHOTO_TYPES, true)) {
                fail($name . ' is not a picture. Upload JPG, PNG, GIF or WEBP files.');
            }
            if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
                fail('The folder storage/maintenance could not be created. Make the storage folder writable by the web server.');
            }
            $stored = 'm' . $maintenanceId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!move_uploaded_file($files['tmp_name'][$i], $dir . '/' . $stored)) {
                fail('The picture could not be saved. Make the storage folder writable by the web server.');
            }
            DB::insert('asset_maintenance_photos', [
                'maintenance_id' => $maintenanceId, 'file_name' => $stored, 'original_name' => mb_substr((string)$name, 0, 190), 'mime_type' => $info['mime'],
                'file_size' => (int)$files['size'][$i], 'caption' => mb_substr(trim((string)($captions[$i] ?? '')), 0, 190) ?: null, 'uploaded_by' => Auth::id(),
            ]);
            $saved++;
        }
        return $saved;
    }

    public static function deletePhoto(array $photo): void
    {
        @unlink(self::photoPath($photo));
        DB::run('DELETE FROM asset_maintenance_photos WHERE id = :id', ['id' => $photo['id']]);
    }

    public static function sendPhoto(array $photo): never
    {
        $file = self::photoPath($photo);
        if (!is_file($file)) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: ' . ($photo['mime_type'] ?: 'application/octet-stream'));
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: private, max-age=86400');
        header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)($photo['original_name'] ?: $photo['file_name'])) . '"');
        readfile($file);
        exit;
    }

    // ------------------------------------------------------------------ preventive maintenance

    public static function schedule(int $id): ?array
    {
        return DB::row('SELECT s.*, fa.asset_no, fa.description AS asset_description, fa.location AS asset_location, fa.current_meter, fa.meter_unit, fa.status AS asset_status
                          FROM asset_pm_schedules s JOIN fixed_assets fa ON fa.id = s.asset_id WHERE s.id = :id', ['id' => $id]);
    }

    /** Next due date after $from for a repeating task (null = one time only) */
    public static function nextDate(string $from, ?int $value, ?string $unit): ?string
    {
        if (!$value || $value < 1 || !isset(self::INTERVAL_UNITS[(string)$unit])) {
            return null;
        }
        if ($unit === 'months' || $unit === 'years') {
            // keep month ends sensible: 31 January + 1 month = 28/29 February
            $months = $unit === 'years' ? $value * 12 : $value;
            $d = new DateTime($from);
            $day = (int)$d->format('j');
            $d->modify('first day of this month')->modify("+$months months");
            $d->setDate((int)$d->format('Y'), (int)$d->format('n'), min($day, (int)$d->format('t')));
            return $d->format('Y-m-d');
        }
        return date('Y-m-d', strtotime($from . ' +' . $value . ' ' . $unit));
    }

    /**
     * State of a task today: overdue, due (inside the reminder window, or the meter has reached it), upcoming, or done (inactive).
     * Returns [state, days until due (negative = overdue) or null, short text]
     */
    public static function state(array $s, ?string $today = null): array
    {
        $today ??= today();
        if ((int)$s['is_active'] !== 1) {
            return ['done', null, 'Finished'];
        }
        $days = null;
        $byDate = 'upcoming';
        if ($s['next_due_date']) {
            $days = (int)round((strtotime($s['next_due_date']) - strtotime($today)) / 86400);
            $byDate = $days < 0 ? 'overdue' : ($days <= (int)$s['remind_days'] ? 'due' : 'upcoming');
        }
        $byMeter = 'upcoming';
        $meterText = '';
        if ($s['next_due_meter'] !== null && $s['current_meter'] !== null) {
            $left = (float)$s['next_due_meter'] - (float)$s['current_meter'];
            $warn = max(0.0, (float)($s['meter_interval'] ?? 0) * 0.1);
            $byMeter = $left <= 0 ? 'overdue' : ($left <= $warn ? 'due' : 'upcoming');
            $unit = $s['meter_unit'] ?: 'units';
            $meterText = $left <= 0 ? qty(-$left) . ' ' . $unit . ' past ' . qty($s['next_due_meter']) : qty($left) . ' ' . $unit . ' to go';
        }
        $rank = ['upcoming' => 0, 'due' => 1, 'overdue' => 2];
        $state = $rank[$byMeter] > $rank[$byDate] ? $byMeter : $byDate;
        if ($days === null) {
            $text = $meterText !== '' ? $meterText : 'No due date';
        } elseif ($days < 0) {
            $text = abs($days) . ' day' . (abs($days) === 1 ? '' : 's') . ' overdue';
        } elseif ($days === 0) {
            $text = 'Due today';
        } else {
            $text = 'Due in ' . $days . ' day' . ($days === 1 ? '' : 's');
        }
        if ($meterText !== '' && $days !== null && $rank[$byMeter] >= $rank[$byDate] && $byMeter !== 'upcoming') {
            $text .= ', ' . $meterText;
        }
        return [$state, $days, $text];
    }

    /** Active tasks with their state, most urgent first. $only: null = all, or a list of states */
    public static function tasks(?array $only = null, array $filter = []): array
    {
        $sql = "SELECT s.*, fa.asset_no, fa.description AS asset_description, fa.location AS asset_location, fa.location_id, fa.current_meter, fa.meter_unit
                  FROM asset_pm_schedules s JOIN fixed_assets fa ON fa.id = s.asset_id
                 WHERE fa.status <> 'disposed'";
        $p = [];
        if (empty($filter['with_inactive'])) {
            $sql .= ' AND s.is_active = 1';
        }
        if (!empty($filter['asset_id'])) {
            $sql .= ' AND s.asset_id = :a';
            $p['a'] = (int)$filter['asset_id'];
        }
        if (!empty($filter['location_id'])) {
            $sql .= ' AND fa.location_id = :l';
            $p['l'] = (int)$filter['location_id'];
        }
        if (!empty($filter['task_type'])) {
            $sql .= ' AND s.task_type = :tt';
            $p['tt'] = (string)$filter['task_type'];
        }
        $rows = DB::all($sql . ' ORDER BY s.next_due_date IS NULL, s.next_due_date, fa.asset_no, s.id', $p);
        $out = [];
        foreach ($rows as $r) {
            [$state, $days, $text] = self::state($r);
            if ($only !== null && !in_array($state, $only, true)) {
                continue;
            }
            $r['state'] = $state;
            $r['days'] = $days;
            $r['state_text'] = $text;
            $out[] = $r;
        }
        $rank = ['overdue' => 0, 'due' => 1, 'upcoming' => 2, 'done' => 3];
        usort($out, fn($a, $b) => [$rank[$a['state']], $a['days'] ?? 99999] <=> [$rank[$b['state']], $b['days'] ?? 99999]);
        return $out;
    }

    /** Tasks that are reminding right now (due or overdue). The reminder stays until the task is acknowledged as complete. */
    public static function reminders(): array
    {
        static $cache = null;
        if ($cache === null) {
            try {
                $cache = self::tasks(['overdue', 'due']);
            } catch (Throwable $e) {
                $cache = [];   // database not upgraded yet
            }
        }
        return $cache;
    }

    /** Who sees the reminders: users who can open the Preventive Maintenance page */
    public static function remindsUser(): bool
    {
        return Auth::id() !== null && Access::page('fa.pm');
    }

    /**
     * A user acknowledges that a task is complete: it is written to the log and the task moves to its next due
     * date / meter (or is finished when it does not repeat). Returns the log id.
     */
    public static function complete(array $s, string $doneDate, ?float $doneMeter, string $remarks, ?int $maintenanceId = null): int
    {
        return DB::tx(function () use ($s, $doneDate, $doneMeter, $remarks, $maintenanceId) {
            $logId = DB::insert('asset_pm_log', [
                'schedule_id' => $s['id'], 'asset_id' => $s['asset_id'], 'task_name' => $s['task_name'], 'due_date' => $s['next_due_date'],
                'done_date' => $doneDate, 'done_meter' => $doneMeter, 'maintenance_id' => $maintenanceId,
                'remarks' => mb_substr(trim($remarks), 0, 255) ?: null, 'done_by' => Auth::id(),
            ]);
            $upd = ['last_done_date' => $doneDate, 'last_done_meter' => $doneMeter];
            // the next date runs from the due date when the work was done on time or early, from the done date when it was late
            $base = $s['next_due_date'] && $s['next_due_date'] > $doneDate ? $s['next_due_date'] : $doneDate;
            $next = self::nextDate($base, $s['interval_value'] !== null ? (int)$s['interval_value'] : null, $s['interval_unit']);
            $nextMeter = $s['meter_interval'] !== null && (float)$s['meter_interval'] > 0
                ? r2(($doneMeter ?? (float)($s['next_due_meter'] ?? 0)) + (float)$s['meter_interval']) : null;
            if ($next === null && $nextMeter === null) {
                $upd['is_active'] = 0;          // a one-time task: finished
            } else {
                $upd['next_due_date'] = $next;
                $upd['next_due_meter'] = $nextMeter;
            }
            DB::update('asset_pm_schedules', $upd, 'id = :id', ['id' => $s['id']]);
            if ($doneMeter !== null) {
                self::updateMeter((int)$s['asset_id'], $doneMeter);
            }
            Audit::log('pm_complete', 'fa', 'pm_schedule', (int)$s['id'], $s['asset_no'] . ' ' . $s['task_name'], [
                'reason' => 'Acknowledged complete on ' . fdate($doneDate) . ($next ? '; next due ' . fdate($next) : ($nextMeter ? '' : '; task finished')),
                'page_key' => 'fa.pm',
            ]);
            return $logId;
        });
    }

    /** Odometer / running hours only move forward */
    public static function updateMeter(int $assetId, float $reading): void
    {
        DB::run('UPDATE fixed_assets SET current_meter = :m WHERE id = :id AND (current_meter IS NULL OR current_meter < :m2)', ['m' => $reading, 'm2' => $reading, 'id' => $assetId]);
    }
}
