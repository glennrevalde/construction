<?php
declare(strict_types=1);

/**
 * Fixed asset depreciation: straight-line, declining balance (with switch to
 * straight-line), units of production and sum-of-the-years'-digits.
 * Depreciation is calculated monthly, so partial years are prorated by month.
 */
final class Depreciation
{
    public const METHODS = ['straight_line', 'declining_balance', 'units_of_production', 'syd'];

    public static function idx(string $date): int
    {
        return (int)substr($date, 0, 4) * 12 + (int)substr($date, 5, 2) - 1;
    }

    public static function idxToYm(int $idx): array
    {
        return [intdiv($idx, 12), $idx % 12 + 1];
    }

    public static function monthEnd(int $y, int $m): string
    {
        return date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $y, $m)));
    }

    public static function asset(int $id): ?array
    {
        return DB::row(
            'SELECT fa.*, ac.name AS category, ac.asset_account_id, ac.accum_dep_account_id, ac.dep_expense_account_id
               FROM fixed_assets fa JOIN asset_categories ac ON ac.id = fa.category_id WHERE fa.id = :id',
            ['id' => $id]
        );
    }

    /** Accumulated depreciation (opening + recorded) */
    public static function accumulated(array $a, ?int $throughIdx = null): float
    {
        $sql = 'SELECT COALESCE(SUM(ad.amount),0) FROM asset_depreciation ad
                  JOIN transactions t ON t.id = ad.txn_id AND t.is_voided = 0
                 WHERE ad.asset_id = :a';
        $p = ['a' => $a['id']];
        if ($throughIdx !== null) {
            $sql .= ' AND (ad.period_year * 12 + ad.period_month - 1) <= :i';
            $p['i'] = $throughIdx;
        }
        return r2((float)$a['opening_accum_dep'] + (float)DB::value($sql, $p));
    }

    public static function nbv(array $a): float
    {
        return r2((float)$a['cost'] - self::accumulated($a));
    }

    /** Year-by-year charges for a time-based method. */
    public static function annualSchedule(float $base, float $salvage, int $years, string $method, float $mult = 2.0): array
    {
        $years = max(1, $years);
        $dep = max(0.0, $base - $salvage);
        $out = [];
        if ($method === 'straight_line') {
            for ($y = 0; $y < $years; $y++) {
                $out[$y] = $y === $years - 1 ? r2($dep - array_sum($out)) : r2($dep / $years);
            }
        } elseif ($method === 'syd') {
            $sum = $years * ($years + 1) / 2;
            for ($y = 0; $y < $years; $y++) {
                $out[$y] = $y === $years - 1 ? r2($dep - array_sum($out)) : r2($dep * ($years - $y) / $sum);
            }
        } else { // declining balance, switching to straight-line when that is higher
            $rate = $mult / $years;
            $nbv = $base;
            for ($y = 0; $y < $years; $y++) {
                if ($y === $years - 1) {
                    $charge = $nbv - $salvage;
                } else {
                    $db = $nbv * $rate;
                    $sl = ($nbv - $salvage) / ($years - $y);
                    $charge = min(max($db, $sl), $nbv - $salvage);
                }
                $charge = r2(max(0.0, $charge));
                $out[$y] = $charge;
                $nbv -= $charge;
            }
        }
        return $out;
    }

    /** Uncapped charge for a month. */
    public static function rawMonthly(array $a, int $monthIdx, ?float $units): float
    {
        $acqIdx = self::idx($a['acquisition_date']);
        $salvage = (float)$a['salvage_value'];
        $base = (float)$a['cost'];
        $startIdx = $acqIdx;
        $lifeMonths = (int)round((float)$a['useful_life_years'] * 12);
        if ($a['basis_date'] && $monthIdx >= self::idx($a['basis_date'])) {
            $base = (float)$a['basis_nbv'];
            $startIdx = self::idx($a['basis_date']);
            $lifeMonths = (int)$a['basis_life_months'];
        }
        $k = $monthIdx - $startIdx;
        if ($k < 0) {
            return 0.0;
        }
        if ($a['method'] === 'units_of_production') {
            $total = (float)$a['total_units'];
            if ($total <= 0 || !$units) {
                return 0.0;
            }
            $usedBefore = 0.0;
            if ($a['basis_date'] && $monthIdx >= self::idx($a['basis_date'])) {
                $usedBefore = (float)DB::value(
                    'SELECT COALESCE(SUM(units_used),0) FROM asset_depreciation WHERE asset_id = :a AND (period_year*12+period_month-1) < :i',
                    ['a' => $a['id'], 'i' => self::idx($a['basis_date'])]
                );
            }
            $remainingUnits = max(1.0, $total - $usedBefore);
            return ($base - $salvage) / $remainingUnits * $units;
        }
        if ($lifeMonths <= 0 || $k >= $lifeMonths) {
            return 0.0;
        }
        if ($a['method'] === 'straight_line') {
            return ($base - $salvage) / $lifeMonths;
        }
        $years = max(1, (int)round($lifeMonths / 12));
        $sched = self::annualSchedule($base, $salvage, $years, $a['method'], (float)$a['db_multiplier']);
        $yearNo = intdiv($k, 12);
        return isset($sched[$yearNo]) ? $sched[$yearNo] / 12 : 0.0;
    }

    /** Charge for a month, never taking net book value below salvage value. */
    public static function amountFor(array $a, int $y, int $m, ?float $units = null): float
    {
        $idx = $y * 12 + $m - 1;
        if ($a['status'] === 'disposed' && $a['disposal_date'] && $idx > self::idx($a['disposal_date'])) {
            return 0.0;
        }
        if ($a['opening_through'] && $idx <= self::idx($a['opening_through'])) {
            return 0.0;
        }
        $amount = self::rawMonthly($a, $idx, $units);
        $remaining = (float)$a['cost'] - (float)$a['salvage_value'] - self::accumulated($a);
        // last month of a time-based life: true-up rounding
        if ($a['method'] !== 'units_of_production') {
            $startIdx = self::idx($a['basis_date'] && $idx >= self::idx($a['basis_date']) ? $a['basis_date'] : $a['acquisition_date']);
            $life = ($a['basis_date'] && $idx >= self::idx($a['basis_date'])) ? (int)$a['basis_life_months'] : (int)round((float)$a['useful_life_years'] * 12);
            if ($idx - $startIdx === $life - 1) {
                $amount = $remaining;
            }
        }
        return r2(max(0.0, min($amount, $remaining)));
    }

    /** Months before $idx that were never depreciated (for warnings). */
    public static function missingMonths(array $a, int $idx): int
    {
        $start = self::idx($a['acquisition_date']);
        if ($a['opening_through']) {
            $start = max($start, self::idx($a['opening_through']) + 1);
        }
        if ($idx <= $start) {
            return 0;
        }
        $done = (int)DB::value(
            'SELECT COUNT(*) FROM asset_depreciation ad JOIN transactions t ON t.id = ad.txn_id AND t.is_voided = 0
              WHERE ad.asset_id = :a AND (ad.period_year*12+ad.period_month-1) BETWEEN :s AND :e',
            ['a' => $a['id'], 's' => $start, 'e' => $idx - 1]
        );
        return max(0, ($idx - $start) - $done);
    }

    public static function alreadyRun(int $assetId, int $y, int $m): bool
    {
        return (bool)DB::value(
            'SELECT COUNT(*) FROM asset_depreciation ad JOIN transactions t ON t.id = ad.txn_id AND t.is_voided = 0
              WHERE ad.asset_id = :a AND ad.period_year = :y AND ad.period_month = :m',
            ['a' => $assetId, 'y' => $y, 'm' => $m]
        );
    }

    /** Assets that can be depreciated for a period, with the calculated charge. */
    /** $locationId: only the assets of one location (Fixed Assets > Asset Locations); -1 = assets without a location */
    public static function preview(int $y, int $m, ?array $onlyIds = null, ?int $locationId = null): array
    {
        $end = self::monthEnd($y, $m);
        $where = '';
        $params = ['e' => $end, 's' => sprintf('%04d-%02d-01', $y, $m)];
        if ($locationId === -1) {
            $where = ' AND fa.location_id IS NULL';
        } elseif ($locationId) {
            $where = ' AND fa.location_id = :loc';
            $params['loc'] = $locationId;
        }
        $assets = DB::all(
            "SELECT fa.*, ac.name AS category, ac.asset_account_id, ac.accum_dep_account_id, ac.dep_expense_account_id
               FROM fixed_assets fa JOIN asset_categories ac ON ac.id = fa.category_id
              WHERE fa.acquisition_date <= :e AND (fa.status <> 'disposed' OR fa.disposal_date >= :s)$where
              ORDER BY ac.name, fa.asset_no",
            $params
        );
        $rows = [];
        foreach ($assets as $a) {
            if ($onlyIds !== null && !in_array((int)$a['id'], $onlyIds, true)) {
                continue;
            }
            $done = self::alreadyRun((int)$a['id'], $y, $m);
            $units = null;
            if ($a['method'] === 'units_of_production') {
                $units = DB::value('SELECT units FROM asset_meter_readings WHERE asset_id = :a AND period_year = :y AND period_month = :m', ['a' => $a['id'], 'y' => $y, 'm' => $m]);
                $units = $units === null ? null : (float)$units;
            }
            $a['units'] = $units;
            $a['done'] = $done;
            $a['amount'] = $done ? 0.0 : self::amountFor($a, $y, $m, $units);
            $a['missing'] = self::missingMonths($a, $y * 12 + $m - 1);
            $a['accumulated'] = self::accumulated($a);
            $rows[] = $a;
        }
        return $rows;
    }

    /**
     * Posts one depreciation entry for the period. Returns [txnId|null, assetCount, total, skipped[]].
     * $classId: the class chosen on the Run Depreciation page. When none is chosen, every asset is recorded
     * under its own default class (Fixed Asset Register), so the entry has one pair of lines per category and class.
     */
    public static function run(int $y, int $m, ?array $onlyIds = null, string $memo = '', ?int $locationId = null, ?int $classId = null): array
    {
        return DB::tx(function () use ($y, $m, $onlyIds, $memo, $locationId, $classId) {
            $rows = self::preview($y, $m, $onlyIds, $locationId);
            $useClasses = Classes::ready();
            $byCat = [];
            $posted = [];
            $skipped = [];
            foreach ($rows as $a) {
                if ($a['done']) {
                    continue;
                }
                if ($a['method'] === 'units_of_production' && $a['units'] === null) {
                    $skipped[] = $a['asset_no'] . ' (enter the meter reading / production count first)';
                    continue;
                }
                if ($a['amount'] <= 0) {
                    continue;
                }
                $lineClass = $useClasses ? ($classId ?: (Classes::valid($a['class_id'] ?? null))) : null;
                $key = $a['dep_expense_account_id'] . ':' . $a['accum_dep_account_id'] . ':' . (int)$lineClass;
                $byCat[$key]['class'] = $lineClass;
                $byCat[$key]['exp'] = (int)$a['dep_expense_account_id'];
                $byCat[$key]['acc'] = (int)$a['accum_dep_account_id'];
                $byCat[$key]['name'] = $a['category'];
                $byCat[$key]['amt'] = ($byCat[$key]['amt'] ?? 0) + $a['amount'];
                $posted[] = $a;
            }
            if (!$posted) {
                return [null, 0, 0.0, $skipped];
            }
            $lines = [];
            foreach ($byCat as $c) {
                $cls = $useClasses ? ['class_id' => $c['class']] : [];
                $lines[] = ['line_kind' => 'journal', 'account_id' => $c['exp'], 'amount' => r2($c['amt']), 'description' => 'Depreciation - ' . $c['name']] + $cls;
                $lines[] = ['line_kind' => 'journal', 'account_id' => $c['acc'], 'amount' => -r2($c['amt']), 'description' => 'Accumulated depreciation - ' . $c['name']] + $cls;
            }
            $label = date('F Y', strtotime(sprintf('%04d-%02d-01', $y, $m)));
            $locName = $locationId && $locationId > 0 ? (string)DB::value('SELECT name FROM asset_locations WHERE id = :id', ['id' => $locationId]) : '';
            $header = [
                'txn_date' => self::monthEnd($y, $m),
                'memo'     => $memo ?: "Depreciation for $label" . ($locName !== '' ? " - $locName" : ''),
            ];
            if ($useClasses) {
                // set here so the form value is not picked up a second time: no class on top means "each asset's own class"
                $header['class_id'] = $classId ?: null;
            }
            $txnId = Txn::save('depreciation', $header, $lines);
            $total = 0.0;
            foreach ($posted as $a) {
                DB::insert('asset_depreciation', [
                    'asset_id' => $a['id'], 'period_year' => $y, 'period_month' => $m,
                    'units_used' => $a['units'], 'amount' => $a['amount'], 'txn_id' => $txnId,
                ]);
                $total += $a['amount'];
                $fresh = self::asset((int)$a['id']);
                if ($fresh['status'] === 'active' && self::nbv($fresh) <= (float)$fresh['salvage_value'] + 0.005) {
                    DB::update('fixed_assets', ['status' => 'fully_depreciated'], 'id = :id', ['id' => $a['id']]);
                }
            }
            return [$txnId, count($posted), r2($total), $skipped];
        });
    }
}
