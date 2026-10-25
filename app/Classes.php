<?php
declare(strict_types=1);

/**
 * Class tracking (Chart of Accounts > Class List).
 *
 * A class is your own way of splitting the books: branches, departments, projects, product lines.
 * Every transaction can carry one class; journal entry and cash voucher lines may carry their own.
 * The posting engine copies the class to the general ledger, so every financial report can
 * filter by class and the "by Class" statements can show one column per class.
 */
final class Classes
{
    /** Filter value for "transactions without a class" */
    public const UNCLASSIFIED = -1;

    /** The session key that remembers the classes tables were found */
    private const SCHEMA_FLAG = 'schema_ok_2026_09_19';

    private static ?array $all = null;
    private static ?bool $ready = null;

    /** False until the migration that adds class tracking has been run */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            $inSession = session_status() === PHP_SESSION_ACTIVE;
            if ($inSession && !empty($_SESSION[self::SCHEMA_FLAG])) {
                return self::$ready = true;
            }
            try {
                // one cheap statement: "WHERE 1 = 0" reads no rows, but MySQL still checks that every table and column exists
                DB::value('SELECT (SELECT COUNT(*) FROM classes WHERE 1 = 0)
                                + (SELECT COUNT(*) FROM other_names WHERE 1 = 0)
                                + (SELECT COUNT(class_id) + COUNT(other_name_id) FROM transactions WHERE 1 = 0)
                                + (SELECT COUNT(class_id) + COUNT(other_name_id) FROM transaction_lines WHERE 1 = 0)
                                + (SELECT COUNT(class_id) + COUNT(other_name_id) FROM journal_lines WHERE 1 = 0)
                                + (SELECT COUNT(show_in_pos) FROM payment_methods WHERE 1 = 0)
                                + (SELECT COUNT(class_id) FROM pos_terminals WHERE 1 = 0)');
                self::$ready = true;
                if ($inSession) {
                    $_SESSION[self::SCHEMA_FLAG] = 1;
                }
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    public static function reset(): void
    {
        self::$all = null;
    }

    /** Every class, in list order: id => row */
    public static function all(): array
    {
        if (self::$all === null) {
            self::$all = [];
            if (self::ready()) {
                foreach (DB::all('SELECT * FROM classes ORDER BY sort_order, name') as $r) {
                    self::$all[(int)$r['id']] = $r;
                }
            }
        }
        return self::$all;
    }

    /** id => name for drop-downs. Inactive classes are left out unless they are the current value. */
    public static function options(?int $keepId = null): array
    {
        $out = [];
        foreach (self::all() as $id => $r) {
            if ((int)$r['is_active'] === 1 || $id === $keepId) {
                $out[$id] = $r['name'] . ((int)$r['is_active'] === 1 ? '' : ' (inactive)');
            }
        }
        return $out;
    }

    /** Options for report filters: every class that exists, plus "no class" */
    public static function filterOptions(): array
    {
        $out = [];
        foreach (self::all() as $id => $r) {
            $out[$id] = $r['name'];
        }
        $out[self::UNCLASSIFIED] = '(Unclassified)';
        return $out;
    }

    public static function name($id): string
    {
        $id = (int)$id;
        if ($id === self::UNCLASSIFIED) {
            return '(Unclassified)';
        }
        return $id ? (string)(self::all()[$id]['name'] ?? '') : '';
    }

    /** True when at least one class has been set up */
    public static function inUse(): bool
    {
        return (bool)self::all();
    }

    /**
     * The class drop-down for a transaction form.
     * $value is the current class (posted value first).
     */
    public static function field($value, string $cls = 'col-md-2', string $name = 'class_id', string $label = 'Class'): string
    {
        if (!self::ready()) {
            return '';
        }
        $value = $value === '' || $value === null ? null : (int)$value;
        $blank = self::inUse() ? '' : 'No classes yet (Class List)';
        return select_field($name, $label, self::options($value), $value, ['blank' => $blank, 'class' => $cls]);
    }

    /** A class <select> for line grids */
    public static function lineSelect(string $name, $value): string
    {
        $value = $value === '' || $value === null ? null : (int)$value;
        return '<select class="form-select form-select-sm" name="' . h($name) . '">' . options(self::options($value), $value, '') . '</select>';
    }

    /** The class chosen on the submitted form (null = none), checked against the list */
    public static function posted(string $key = 'class_id'): ?int
    {
        return self::valid($_POST[$key] ?? null);
    }

    public static function valid($id): ?int
    {
        $id = is_scalar($id) ? (int)$id : 0;
        if ($id <= 0) {
            return null;
        }
        if (!isset(self::all()[$id])) {
            fail('The class chosen on this form no longer exists. Choose another class.');
        }
        return $id;
    }

    /** SQL condition and parameters for a class filter on a ledger alias */
    public static function sqlFilter(?int $classId, string $alias = 'j', string $param = 'fclass'): array
    {
        $col = ($alias !== '' ? $alias . '.' : '') . 'class_id';
        if (!$classId) {
            return ['', []];
        }
        if ($classId === self::UNCLASSIFIED) {
            return [" AND $col IS NULL", []];
        }
        return [" AND $col = :$param", [$param => $classId]];
    }

    /** How many transactions, lines and ledger entries use a class */
    public static function usage(int $id): int
    {
        return (int)DB::value('SELECT COUNT(*) FROM transactions WHERE class_id = :id', ['id' => $id])
            + (int)DB::value('SELECT COUNT(*) FROM transaction_lines WHERE class_id = :id', ['id' => $id])
            + (int)DB::value('SELECT COUNT(*) FROM journal_lines WHERE class_id = :id', ['id' => $id])
            + (int)DB::value('SELECT COUNT(*) FROM pos_terminals WHERE class_id = :id', ['id' => $id]);
    }
}
