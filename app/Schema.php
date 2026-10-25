<?php
declare(strict_types=1);

/**
 * Knows which upgrade scripts (database/migrations) the program files need and which of them
 * have not been run on this database yet. index.php stops with instructions while any is pending,
 * so nobody works on a half-upgraded system.
 */
final class Schema
{
    private const FLAG = 'schema_ok_gsc_1_0';

    /**
     * file => one cheap probe (see below). GSMaRT Construction System 1.0 starts with the complete schema in
     * database/schema.sql, so no upgrade script is needed yet; later versions list theirs here.
     *   A string probe is pending when the query fails: "WHERE 1 = 0" reads no rows, but MySQL still
     *   checks that every table and column it names exists.
     *   An [sql, expected] probe is pending when the query fails OR does not give back what is expected.
     */
    private const MIGRATIONS = [];

    private static ?array $pending = null;

    public static function flush(): void
    {
        self::$pending = null;
    }

    /** Upgrade scripts still to be run on this request's database, oldest first (empty = up to date) */
    public static function pending(): array
    {
        if (self::$pending !== null) {
            return self::$pending;
        }
        // remembered per database: a Super Admin moves between companies in one session
        $flag = self::FLAG . ':' . DB::databaseName();
        $inSession = session_status() === PHP_SESSION_ACTIVE;
        if ($inSession && !empty($_SESSION[$flag])) {
            return self::$pending = [];
        }
        $out = self::pendingWith(fn(string $sql) => DB::value($sql));
        if (!$out && $inSession) {
            $_SESSION[$flag] = 1;
        }
        return self::$pending = $out;
    }

    /** Upgrade scripts still to be run on another company's database (the platform's update page) */
    public static function pendingOn(Sql $db): array
    {
        return self::pendingWith(fn(string $sql) => $db->value($sql));
    }

    /** @return string[] every upgrade script the program knows, oldest first */
    public static function files(): array
    {
        return array_keys(self::MIGRATIONS);
    }

    private static function pendingWith(callable $value): array
    {
        $out = [];
        foreach (self::MIGRATIONS as $file => $probe) {
            [$sql, $want] = is_array($probe) ? $probe : [$probe, null];
            try {
                $got = $value($sql);
                if ($want !== null && (int)$got !== (int)$want) {
                    $out[] = $file;
                }
            } catch (Throwable $e) {
                $out[] = $file;
            }
        }
        return $out;
    }

    public static function ready(): bool
    {
        return !self::pending();
    }
}
