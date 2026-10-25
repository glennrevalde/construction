<?php
declare(strict_types=1);

/**
 * Units of measure.
 *
 * A U/M set holds one base unit and any number of related units, each with the number of
 * base units it contains (1 box = 50 pc, 1 sack = 25 kg). Items are stocked and costed in
 * the base unit; documents can be entered in any unit of the item's set and are converted:
 *
 *   quantity in base units = quantity entered x factor
 *   price per base unit    = price entered / factor
 */
final class Uom
{
    /** Kinds of measure, used only to group the list */
    public const KINDS = [
        'count'  => 'Count',
        'weight' => 'Weight',
        'volume' => 'Volume',
        'length' => 'Length',
        'area'   => 'Area',
        'time'   => 'Time',
        'other'  => 'Other',
    ];

    /** id => "name (base unit)" for drop-downs */
    public static function sets(bool $activeOnly = true, ?int $keepId = null): array
    {
        $rows = DB::all('SELECT id, name, base_unit, is_active FROM uom_sets ORDER BY name');
        $out = [];
        foreach ($rows as $r) {
            if ($activeOnly && !(int)$r['is_active'] && (int)$r['id'] !== (int)$keepId) {
                continue;
            }
            $out[(int)$r['id']] = $r['name'] . ' (' . $r['base_unit'] . ')' . ((int)$r['is_active'] ? '' : ' — inactive');
        }
        return $out;
    }

    public static function set(int $id): ?array
    {
        return DB::row('SELECT * FROM uom_sets WHERE id = :id', ['id' => $id]);
    }

    /** Units of a set, base unit first */
    public static function units(int $setId): array
    {
        return DB::all('SELECT * FROM uom_units WHERE set_id = :s ORDER BY is_base DESC, factor, code', ['s' => $setId]);
    }

    /** @var array<int,array|null> */
    private static array $unitCache = [];

    public static function unit(?int $unitId): ?array
    {
        if (!$unitId) {
            return null;
        }
        if (!array_key_exists($unitId, self::$unitCache)) {
            self::$unitCache[$unitId] = DB::row('SELECT u.*, s.name AS set_name, s.base_unit FROM uom_units u JOIN uom_sets s ON s.id = u.set_id WHERE u.id = :id', ['id' => $unitId]);
        }
        return self::$unitCache[$unitId];
    }

    /** Unit code, e.g. "box" */
    public static function code(?int $unitId): ?string
    {
        $u = self::unit($unitId);
        return $u['code'] ?? null;
    }

    /** Units an item can be entered in (empty when the item has no set) */
    public static function unitsForItem(?int $itemId): array
    {
        if (!$itemId) {
            return [];
        }
        $setId = DB::value('SELECT uom_set_id FROM items WHERE id = :i', ['i' => $itemId]);
        return $setId ? self::units((int)$setId) : [];
    }

    /** True when the unit belongs to the item's set (documents may only use those) */
    public static function unitBelongsTo(?int $unitId, ?int $itemId): bool
    {
        if (!$unitId || !$itemId) {
            return false;
        }
        return (bool)DB::value('SELECT 1 FROM uom_units u JOIN items i ON i.uom_set_id = u.set_id WHERE u.id = :u AND i.id = :i', ['u' => $unitId, 'i' => $itemId]);
    }

    /** id => code for a set, for drop-downs */
    public static function unitOptions(?int $setId, bool $withFactor = true): array
    {
        if (!$setId) {
            return [];
        }
        $base = (string)DB::value('SELECT base_unit FROM uom_sets WHERE id = :s', ['s' => $setId]);
        $out = [];
        foreach (self::units((int)$setId) as $u) {
            $out[(int)$u['id']] = $u['code'] . ($withFactor && (float)$u['factor'] != 1.0 ? ' = ' . qty($u['factor']) . ' ' . $base : '');
        }
        return $out;
    }

    /** Base units contained in one of $unitId (1 when there is no unit) */
    public static function factor(?int $unitId): float
    {
        $u = self::unit($unitId);
        return $u === null ? 1.0 : max(0.000001, (float)$u['factor']);
    }

    /** The unit a line should default to: the item's sales / purchase default, else the base unit */
    public static function defaultUnit(?int $itemId, string $mode = 'sales'): ?array
    {
        $units = self::unitsForItem($itemId);
        if (!$units) {
            return null;
        }
        $itemDefault = (int)DB::value('SELECT ' . ($mode === 'purchase' ? 'purchase_uom_id' : 'sales_uom_id') . ' FROM items WHERE id = :i', ['i' => $itemId]);
        foreach ($units as $u) {
            if ($itemDefault && (int)$u['id'] === $itemDefault) {
                return $u;
            }
        }
        $col = $mode === 'purchase' ? 'is_purchase_default' : 'is_sales_default';
        foreach ($units as $u) {
            if ((int)$u[$col] === 1) {
                return $u;
            }
        }
        foreach ($units as $u) {
            if ((int)$u['is_base'] === 1) {
                return $u;
            }
        }
        return $units[0] ?? null;
    }

    /** Short label for a quantity entered in a unit, e.g. "2 box (100 pc)" */
    public static function label(float $qtyEntered, ?int $unitId, float $baseQty, ?string $baseUnit): string
    {
        $u = self::unit($unitId);
        if (!$u) {
            return qty($baseQty) . ($baseUnit ? ' ' . $baseUnit : '');
        }
        $s = qty($qtyEntered) . ' ' . $u['code'];
        if ((float)$u['factor'] != 1.0) {
            $s .= ' (' . qty($baseQty) . ' ' . ($baseUnit ?: $u['base_unit']) . ')';
        }
        return $s;
    }

    /** Units of every item, for the line-grid JavaScript: item id => [[id, code, factor, base]] */
    public static function unitsByItem(array $itemIds = []): array
    {
        $sql = 'SELECT i.id AS item_id, u.id, u.code, u.factor, u.is_base,
                       CASE WHEN i.sales_uom_id IS NULL THEN u.is_sales_default ELSE (u.id = i.sales_uom_id) END AS is_sales_default,
                       CASE WHEN i.purchase_uom_id IS NULL THEN u.is_purchase_default ELSE (u.id = i.purchase_uom_id) END AS is_purchase_default
                  FROM items i JOIN uom_units u ON u.set_id = i.uom_set_id';
        $p = [];
        if ($itemIds) {
            [$in, $p] = DB::in('i', array_map('intval', $itemIds));
            $sql .= " WHERE i.id IN $in";
        }
        $out = [];
        foreach (DB::all($sql . ' ORDER BY u.is_base DESC, u.factor, u.code', $p) as $r) {
            $out[(int)$r['item_id']][] = [
                'id' => (int)$r['id'], 'code' => $r['code'], 'factor' => (float)$r['factor'],
                'base' => (int)$r['is_base'], 'sd' => (int)$r['is_sales_default'], 'pd' => (int)$r['is_purchase_default'],
            ];
        }
        return $out;
    }

    /**
     * Makes sure a set exists for a plain unit name (used when importing or when an item
     * still has free text). Returns the set id.
     */
    public static function setForUnitText(string $unit): int
    {
        $unit = trim($unit) ?: 'pc';
        $id = DB::value('SELECT id FROM uom_sets WHERE base_unit = :u ORDER BY (SELECT COUNT(*) FROM uom_units x WHERE x.set_id = uom_sets.id), id LIMIT 1', ['u' => $unit]);
        if ($id) {
            return (int)$id;
        }
        return DB::tx(function () use ($unit) {
            $setId = DB::insert('uom_sets', ['name' => ucfirst($unit), 'uom_kind' => 'other', 'base_unit' => mb_substr($unit, 0, 20), 'is_active' => 1]);
            DB::insert('uom_units', ['set_id' => $setId, 'code' => mb_substr($unit, 0, 20), 'name' => ucfirst($unit), 'factor' => 1, 'is_base' => 1, 'sort_order' => 1]);
            return $setId;
        });
    }
}
