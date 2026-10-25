<?php
declare(strict_types=1);

/**
 * One name picker for payees and subsidiary ledgers:
 * customers, suppliers (vendors), employees and other names.
 *
 * A choice travels as one short code: c12 = customer 12, v5 = vendor 5, o7 = employee / other name 7.
 */
final class Names
{
    public const TYPES = ['employee' => 'Employee', 'other' => 'Other name'];

    private static ?array $others = null;

    /** Employees and other names: id => row */
    public static function others(): array
    {
        if (self::$others === null) {
            self::$others = [];
            try {
                foreach (DB::all('SELECT * FROM other_names ORDER BY name') as $r) {
                    self::$others[(int)$r['id']] = $r;
                }
            } catch (Throwable $e) {
                // table not installed yet
            }
        }
        return self::$others;
    }

    public static function reset(): void
    {
        self::$others = null;
    }

    /**
     * code => label, grouped by kind.
     * $keep is a code that stays in the list even when the record is inactive.
     */
    public static function options(?string $keep = null): array
    {
        $out = [];
        foreach (self::others() as $id => $r) {
            if ($r['name_type'] === 'employee' && ((int)$r['is_active'] === 1 || $keep === 'o' . $id)) {
                $out['o' . $id] = 'Employee: ' . $r['name'];
            }
        }
        foreach (Lookup::customers() as $id => $name) {
            $out['c' . $id] = 'Customer: ' . $name;
        }
        foreach (Lookup::vendors() as $id => $name) {
            $out['v' . $id] = 'Supplier: ' . $name;
        }
        foreach (self::others() as $id => $r) {
            if ($r['name_type'] !== 'employee' && ((int)$r['is_active'] === 1 || $keep === 'o' . $id)) {
                $out['o' . $id] = 'Other: ' . $r['name'];
            }
        }
        if ($keep && !isset($out[$keep])) {
            $label = self::label(self::parse($keep));
            if ($label !== '') {
                $out[$keep] = $label . ' (inactive)';
            }
        }
        return $out;
    }

    /** Code -> the three id columns */
    public static function parse(?string $code): array
    {
        $code = (string)$code;
        $id = (int)substr($code, 1);
        $out = ['customer_id' => null, 'vendor_id' => null, 'other_name_id' => null];
        if ($id <= 0) {
            return $out;
        }
        switch ($code[0] ?? '') {
            case 'c':
                $out['customer_id'] = $id;
                break;
            case 'v':
                $out['vendor_id'] = $id;
                break;
            case 'o':
                if (!isset(self::others()[$id])) {
                    fail('A name chosen on this form no longer exists.');
                }
                $out['other_name_id'] = $id;
                break;
        }
        return $out;
    }

    /** Row (transaction, line or ledger entry) -> code */
    public static function code(array $row): string
    {
        if (!empty($row['customer_id'])) {
            return 'c' . (int)$row['customer_id'];
        }
        if (!empty($row['vendor_id'])) {
            return 'v' . (int)$row['vendor_id'];
        }
        if (!empty($row['other_name_id'])) {
            return 'o' . (int)$row['other_name_id'];
        }
        return '';
    }

    /** Display name for a row that has customer_id / vendor_id / other_name_id */
    public static function label(array $row): string
    {
        if (!empty($row['customer_id'])) {
            return (string)DB::value(
                "SELECT CASE WHEN p.id IS NULL THEN c.name ELSE CONCAT(p.name, ':', c.name) END
                   FROM customers c LEFT JOIN customers p ON p.id = c.parent_id WHERE c.id = :id",
                ['id' => $row['customer_id']]
            );
        }
        if (!empty($row['vendor_id'])) {
            return (string)DB::value('SELECT name FROM vendors WHERE id = :id', ['id' => $row['vendor_id']]);
        }
        if (!empty($row['other_name_id'])) {
            return (string)(self::others()[(int)$row['other_name_id']]['name'] ?? '');
        }
        return '';
    }
}
