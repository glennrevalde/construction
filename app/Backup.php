<?php
declare(strict_types=1);

/**
 * Database backup and restore.
 *
 * The backup is a plain .sql file, written by PHP itself rather than by mysqldump, because shared
 * hosting usually switches exec() off. It holds the CREATE TABLE of every table and every row, in an
 * order that lets it be read back by phpMyAdmin, Navicat or the restore below.
 *
 * The restore reads such a file back. Before it writes anything it makes a fresh backup of what is
 * there now, so a restore can itself be undone.
 *
 * Files live in storage/backups, which the web server is told not to serve; they are downloaded
 * through the page, which checks the user's access first.
 */
final class Backup
{
    /** Rows written per INSERT. Small enough for the usual max_allowed_packet of 1 MB. */
    private const CHUNK = 200;

    /** The company's own backup folder (storage/backups for the first company) */
    public static function dir(): string
    {
        $dir = Tenant::storage('backups');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!file_exists($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\nOrder allow,deny\nDeny from all\n");
        }
        if (!file_exists($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '');
        }
        return $dir;
    }

    /** The database this company works on, as the page names it (other companies see their company name, not a database name) */
    public static function dbName(): string
    {
        return Tenant::isPrimary() ? DB::databaseName() : Tenant::name();
    }

    /** Where the files are kept, as the page names it */
    public static function dirLabel(): string
    {
        $dir = realpath(self::dir()) ?: self::dir();
        $root = realpath(APP_ROOT) ?: APP_ROOT;
        return ltrim(str_replace('\\', '/', str_starts_with($dir, $root) ? substr($dir, strlen($root)) : 'storage/backups'), '/');
    }

    /** true when the server can gzip the file as it is written */
    public static function canCompress(): bool
    {
        return function_exists('gzopen');
    }

    /** Every table of this database, in name order */
    public static function tables(): array
    {
        return DB::column('SELECT table_name FROM information_schema.tables
                            WHERE table_schema = DATABASE() AND table_type = \'BASE TABLE\' ORDER BY table_name');
    }

    /** Rough size of the database, for the page */
    public static function size(): array
    {
        $r = DB::row('SELECT COUNT(*) AS tables_count, COALESCE(SUM(data_length + index_length), 0) AS bytes, COALESCE(SUM(table_rows), 0) AS rows_count
                        FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = \'BASE TABLE\'');
        return ['tables' => (int)($r['tables_count'] ?? 0), 'bytes' => (int)($r['bytes'] ?? 0), 'rows' => (int)($r['rows_count'] ?? 0)];
    }

    /**
     * Writes a backup and returns what it wrote.
     *
     * @param string $note     what the user typed as the reason
     * @param bool   $gzip     compress the file
     * @param bool   $withData false writes the structure only
     * @param string $prefix   put in front of the name, so a safety backup is easy to tell apart
     */
    public static function create(string $note = '', bool $gzip = true, bool $withData = true, string $prefix = ''): array
    {
        @set_time_limit(0);
        $gzip = $gzip && self::canCompress();
        $stem = $prefix . Tenant::fileStem() . '_' . date('Ymd_His') . ($withData ? '' : '_structure');
        $ext = '.sql' . ($gzip ? '.gz' : '');
        // two backups made in the same second must not land on the same file: the second one would
        // overwrite the first, and a restore would then read back the file it had just replaced
        $name = $stem . $ext;
        for ($i = 2; file_exists(self::dir() . '/' . $name); $i++) {
            $name = $stem . '_' . $i . $ext;
        }
        $path = self::dir() . '/' . $name;
        $fh = $gzip ? gzopen($path, 'wb9') : fopen($path, 'wb');
        if (!$fh) {
            fail('The backup file could not be created. Check that the storage/backups folder can be written to.');
        }
        $write = $gzip
            ? function (string $s) use ($fh) { gzwrite($fh, $s); }
            : function (string $s) use ($fh) { fwrite($fh, $s); };

        $pdo = DB::pdo();
        $tables = self::tables();
        $rowCount = 0;

        $write("-- Backup of " . self::dbName() . " made by " . app_name() . " on " . date('Y-m-d H:i:s') . "\n"
            . "-- " . count($tables) . " table(s)" . ($withData ? '' : ', structure only') . "\n"
            . "-- Read it back with the Backup & Restore page, phpMyAdmin (Import) or Navicat (Execute SQL File).\n\n"
            . "SET NAMES utf8mb4;\n"
            . "SET FOREIGN_KEY_CHECKS = 0;\n"
            . "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

        foreach ($tables as $table) {
            $create = DB::row('SHOW CREATE TABLE `' . str_replace('`', '', $table) . '`');
            $ddl = (string)($create['Create Table'] ?? '');
            $write("-- ---------------------------------------------------------\n-- Table `$table`\n-- ---------------------------------------------------------\n"
                . "DROP TABLE IF EXISTS `$table`;\n" . $ddl . ";\n\n");
            if (!$withData) {
                continue;
            }
            $cols = DB::column('SELECT column_name FROM information_schema.columns
                                 WHERE table_schema = DATABASE() AND table_name = :t ORDER BY ordinal_position', ['t' => $table]);
            if (!$cols) {
                continue;
            }
            $colList = '`' . implode('`, `', $cols) . '`';
            $stmt = $pdo->query('SELECT * FROM `' . str_replace('`', '', $table) . '`');
            $n = 0;
            $buf = [];
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $vals = [];
                foreach ($cols as $c) {
                    $vals[] = self::quote($pdo, $row[$c] ?? null);
                }
                $buf[] = '(' . implode(',', $vals) . ')';
                $n++;
                $rowCount++;
                if (count($buf) >= self::CHUNK) {
                    $write("INSERT INTO `$table` ($colList) VALUES\n" . implode(",\n", $buf) . ";\n");
                    $buf = [];
                }
            }
            $stmt->closeCursor();
            if ($buf) {
                $write("INSERT INTO `$table` ($colList) VALUES\n" . implode(",\n", $buf) . ";\n");
            }
            $write("-- $n row(s) in `$table`\n\n");
        }

        $write("SET FOREIGN_KEY_CHECKS = 1;\n-- End of backup\n");
        $gzip ? gzclose($fh) : fclose($fh);
        @chmod($path, 0640);

        $size = (int)@filesize($path);
        self::log('backup', $name, $size, count($tables), $rowCount, $note ?: null);
        return ['file' => $name, 'path' => $path, 'size' => $size, 'tables' => count($tables), 'rows' => $rowCount, 'gzip' => $gzip];
    }

    /** One value, ready for the file */
    private static function quote(PDO $pdo, $v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_int($v) || is_float($v)) {
            return (string)$v;
        }
        $s = (string)$v;
        if ($s !== '' && preg_match('/[^\x09\x0A\x0D\x20-\x7E\x80-\xFF]/', $s)) {
            // binary content: written as hex so no byte is lost
            return '0x' . bin2hex($s);
        }
        return $pdo->quote($s);
    }

    /**
     * Reads a .sql file back into the database.
     * Makes a safety backup first unless $safety is false, and returns what happened.
     */
    public static function restore(string $path, bool $safety = true): array
    {
        @set_time_limit(0);
        if (!is_file($path)) {
            fail('That backup file is no longer there.');
        }
        $before = $safety ? self::create('Made automatically before a restore', true, true, 'before_restore_') : null;
        if ($before && realpath($before['path']) === realpath($path)) {
            fail('The safety backup landed on the file being restored. Nothing was changed; try again.');
        }

        $pdo = DB::pdo();
        $statements = 0;
        $errors = [];
        // with several companies, a restore may only ever touch this company's own tables
        $guard = Tenant::isMulti();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            self::eachStatement($path, function (string $sql) use ($pdo, $guard, &$statements, &$errors) {
                if ($guard && ($why = self::refused($sql)) !== '') {
                    $errors[] = self::shortSql($sql) . ' - not run: ' . $why;
                    return;
                }
                try {
                    $pdo->exec($sql);
                    $statements++;
                } catch (Throwable $e) {
                    $errors[] = self::shortSql($sql) . ' - ' . $e->getMessage();
                    if (count($errors) > 25) {
                        throw new RuntimeException('The file holds too many statements that could not be run; it was stopped after 25.');
                    }
                }
            });
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        // the restored audit_trail table was created again, and a dropped table loses its protection triggers
        $triggerProblem = Audit::ensureTriggers();
        if ($triggerProblem !== '') {
            $errors[] = 'The audit trail protection triggers could not be put back: ' . $triggerProblem;
        }
        // the users came back from the file too: the master database's list of who belongs to this company follows them
        if (Tenant::isMulti() && Tenant::id()) {
            $sync = Membership::sync((int)Tenant::id(), new Sql($pdo));
            foreach ($sync['conflicts'] as $taken) {
                $errors[] = "The username $taken belongs to another company, so that user cannot log in until the username is changed.";
            }
            Master::log('restore', (int)Tenant::id(), 'Company database restored from ' . basename($path));
        }

        $name = basename($path);
        // the restore replaced backup_log too, so the safety backup's own entry went with it: write it again
        if ($before) {
            self::log('backup', $before['file'], $before['size'], $before['tables'], $before['rows'],
                'Made automatically before a restore');
        }
        self::log('restore', $name, (int)@filesize($path), count(self::tables()), $statements,
            $errors ? count($errors) . ' statement(s) failed' : 'Restored');
        return ['file' => $name, 'statements' => $statements, 'errors' => $errors, 'safety' => $before];
    }

    /**
     * Reads a .sql file and hands each statement to $run, one at a time, so a file of any size fits
     * in memory. Quoted text, backticks, line comments and block comments are all skipped over, so a
     * semicolon inside a memo or an address does not split a statement in two.
     */
    public static function eachStatement(string $path, callable $run): int
    {
        $gz = substr($path, -3) === '.gz';
        $fh = $gz ? gzopen($path, 'rb') : fopen($path, 'rb');
        if (!$fh) {
            fail('The backup file could not be read.');
        }
        $sql = '';
        $mode = '';           // '' | "'" | '"' | '`' | 'line' | 'block'
        $count = 0;
        try {
            while (!($gz ? gzeof($fh) : feof($fh))) {
                $ln = $gz ? gzgets($fh) : fgets($fh);
                if ($ln === false) {
                    break;
                }
                if ($mode === 'line') {
                    $mode = '';                       // a line comment ends with the line it started on
                }
                if ($mode === '' && trim($sql) === '') {
                    $head = ltrim($ln);
                    if ($head === '' || str_starts_with($head, '--') || str_starts_with($head, '#')) {
                        continue;                     // a whole comment line, the common case: skip it fast
                    }
                }
                $len = strlen($ln);
                for ($i = 0; $i < $len; $i++) {
                    $ch = $ln[$i];
                    if ($mode === 'line') {
                        continue;
                    }
                    if ($mode === 'block') {
                        if ($ch === '*' && ($ln[$i + 1] ?? '') === '/') {
                            $mode = '';
                            $i++;
                        }
                        continue;
                    }
                    if ($mode !== '') {                       // inside '...', "..." or `...`
                        $sql .= $ch;
                        if ($ch === '\\' && $mode !== '`') {      // \' and \\ inside a string
                            $sql .= $ln[++$i] ?? '';
                        } elseif ($ch === $mode) {
                            if (($ln[$i + 1] ?? '') === $mode) {   // '' written for one quote
                                $sql .= $ln[++$i];
                            } else {
                                $mode = '';
                            }
                        }
                        continue;
                    }
                    if ($ch === '-' && ($ln[$i + 1] ?? '') === '-' && in_array($ln[$i + 2] ?? ' ', [' ', "\t", "\n", "\r", ''], true)) {
                        $mode = 'line';
                        continue;
                    }
                    if ($ch === '#') {
                        $mode = 'line';
                        continue;
                    }
                    if ($ch === '/' && ($ln[$i + 1] ?? '') === '*') {
                        $mode = 'block';
                        $i++;
                        continue;
                    }
                    if ($ch === "'" || $ch === '"' || $ch === '`') {
                        $mode = $ch;
                        $sql .= $ch;
                        continue;
                    }
                    if ($ch === ';') {
                        $one = trim($sql);
                        $sql = '';
                        if ($one !== '') {
                            $run($one);
                            $count++;
                        }
                        continue;
                    }
                    $sql .= $ch;
                }
                if (strlen($sql) > 33554432) {
                    fail('One statement in the file is larger than 32 MB, so it cannot be read here. Restore that file with phpMyAdmin or Navicat instead.');
                }
            }
            if (trim($sql) !== '') {
                $run(trim($sql));
                $count++;
            }
        } finally {
            $gz ? gzclose($fh) : fclose($fh);
        }
        return $count;
    }

    /**
     * Why a statement of a restore file may not run for a company ('' = it may).
     * A restore may only rebuild this company's own tables: it may not switch to or name another database,
     * create or change users, grants, triggers, routines, views or events, read or write files on the
     * server, or copy data with a SELECT (which could read another company's database).
     */
    public static function refused(string $sql): string
    {
        // look at the statement with quoted text blanked out, so words inside values do not count
        $bare = self::blankStrings($sql);
        $head = strtoupper(ltrim($bare));
        $allowed = ['SET ', 'DROP TABLE ', 'CREATE TABLE ', 'INSERT INTO ', 'REPLACE INTO ', 'ALTER TABLE ',
            'LOCK TABLES', 'UNLOCK TABLES', 'START TRANSACTION', 'BEGIN', 'COMMIT', 'TRUNCATE '];
        $ok = false;
        foreach ($allowed as $a) {
            if (str_starts_with(preg_replace('/\s+/', ' ', $head), $a)) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            return 'only table statements can be restored into a company';
        }
        if (preg_match('/^SET\s+(PASSWORD|GLOBAL|PERSIST|@@GLOBAL)/i', ltrim($bare))) {
            return 'server settings and passwords cannot be changed from a restore';
        }
        $words = preg_replace('/`[^`]*`/', '``', $bare) ?? '';
        if (preg_match('/\b(SELECT|UNION|OUTFILE|DUMPFILE|LOAD_FILE|DATABASE|SCHEMA|GRANT|TRIGGER|PROCEDURE|FUNCTION|EVENT|VIEW|FEDERATED|CONNECTION|DIRECTORY)\b/i', $words)) {
            return 'it reads other data, or creates something other than a table';
        }
        // db.table, `db`.`table`, `db`.table or db.`table`: a name in another database
        if (preg_match('/(`[^`]+`|\b[A-Za-z_][A-Za-z0-9_$]*)\s*\.\s*(`[^`]+`|[A-Za-z_])/', $bare)) {
            return 'it names a table in another database';
        }
        return '';
    }

    /** The statement with the contents of every '...' and "..." removed (a plain scan: any size, no regex limits) */
    private static function blankStrings(string $sql): string
    {
        $out = '';
        $len = strlen($sql);
        $i = 0;
        while ($i < $len) {
            $n = strcspn($sql, "'\"", $i);
            $out .= substr($sql, $i, $n);
            $i += $n;
            if ($i >= $len) {
                break;
            }
            $q = $sql[$i++];
            while ($i < $len) {
                $n = strcspn($sql, $q . '\\', $i);
                $i += $n;
                if ($i >= $len) {
                    break;
                }
                if ($sql[$i] === '\\') {
                    $i += 2;
                    continue;
                }
                if (($sql[$i + 1] ?? '') === $q) {
                    $i += 2;
                    continue;
                }
                $i++;
                break;
            }
            $out .= "''";
        }
        return $out;
    }

    private static function shortSql(string $sql): string
    {
        $s = preg_replace('/\s+/', ' ', trim($sql));
        return mb_substr($s, 0, 90) . (mb_strlen($s) > 90 ? '…' : '');
    }

    /** The backup files that are there now, newest first */
    public static function files(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*.sql*') ?: [] as $f) {
            if (!is_file($f)) {
                continue;
            }
            $out[] = ['name' => basename($f), 'size' => (int)filesize($f), 'time' => (int)filemtime($f)];
        }
        usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
        return $out;
    }

    /** Checks a file name came from this folder, and gives back its full path */
    public static function path(string $name): string
    {
        $name = basename(trim($name));
        if ($name === '' || !preg_match('/\.sql(\.gz)?$/i', $name)) {
            fail('That is not a backup file.');
        }
        $path = self::dir() . '/' . $name;
        if (!is_file($path)) {
            fail('That backup file is no longer there.');
        }
        return $path;
    }

    public static function delete(string $name): void
    {
        $path = self::path($name);
        @unlink($path);
        self::log('delete', basename($path), 0, 0, 0, 'Deleted');
    }

    /** Removes the oldest files so only $keep are left. Returns the names removed. */
    public static function prune(int $keep): array
    {
        $gone = [];
        $files = self::files();
        foreach (array_slice($files, max(0, $keep)) as $f) {
            @unlink(self::dir() . '/' . $f['name']);
            $gone[] = $f['name'];
        }
        return $gone;
    }

    public static function log(string $action, string $file, int $size, int $tables, int $rows, ?string $note): void
    {
        DB::insert('backup_log', ['action' => $action, 'file_name' => $file, 'file_size' => $size,
            'tables_count' => $tables, 'rows_count' => $rows, 'note' => $note, 'created_by' => Auth::id()]);
    }

    public static function history(int $limit = 30): array
    {
        return DB::all('SELECT b.*, u.username FROM backup_log b LEFT JOIN users u ON u.id = b.created_by
                         ORDER BY b.id DESC LIMIT ' . max(1, $limit));
    }

    /** Sends a backup file to the browser */
    public static function download(string $name): void
    {
        $path = self::path($name);
        $gz = substr($path, -3) === '.gz';
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: ' . ($gz ? 'application/gzip' : 'application/sql'));
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . (int)filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }
}
