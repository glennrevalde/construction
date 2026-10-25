<?php
declare(strict_types=1);

/** Helpers for the command-line tools. */

function split_sql(string $sql): array
{
    $stmts = [];
    $delim = ';';
    $buf = '';
    foreach (preg_split('/\R/', $sql) as $line) {
        $trim = trim($line);
        if (preg_match('/^DELIMITER\s+(\S+)/i', $trim, $m)) {
            $delim = $m[1];
            continue;
        }
        if ($buf === '' && ($trim === '' || str_starts_with($trim, '--'))) {
            continue;
        }
        $buf .= $line . "\n";
        if (str_ends_with(rtrim($line), $delim)) {
            $stmt = trim(substr(rtrim($buf), 0, -strlen($delim)));
            if ($stmt !== '') {
                $stmts[] = $stmt;
            }
            $buf = '';
        }
    }
    if (trim($buf) !== '') {
        $stmts[] = trim($buf);
    }
    return $stmts;
}

function run_sql_file(PDO $pdo, string $file): void
{
    foreach (split_sql(file_get_contents($file)) as $stmt) {
        $pdo->exec($stmt);
    }
}

function server_pdo(): PDO
{
    $c = $GLOBALS['CONFIG']['db'];
    return new PDO("mysql:host={$c['host']};port={$c['port']};charset=utf8mb4", $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}

function sql_value($v, PDO $pdo): string
{
    if ($v === null) {
        return 'NULL';
    }
    if (is_int($v) || is_float($v)) {
        return (string)$v;
    }
    return $pdo->quote((string)$v);
}

/**
 * INSERT statements for every row of every table (load with FK checks off).
 * Each statement holds at most 100 rows and about 256 KB, well under MySQL 5.5's default 1 MB max_allowed_packet.
 */
function export_data(PDO $pdo, array $tables): string
{
    $out = '';
    foreach ($tables as $t) {
        $rows = $pdo->query("SELECT * FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            continue;
        }
        $head = "INSERT INTO `$t` (`" . implode('`,`', array_keys($rows[0])) . "`) VALUES\n";
        $out .= "\n-- $t (" . count($rows) . " rows)\n";
        $vals = [];
        $bytes = 0;
        foreach ($rows as $r) {
            $v = '(' . implode(',', array_map(fn($x) => sql_value($x, $pdo), array_values($r))) . ')';
            if ($vals && (count($vals) >= 100 || $bytes + strlen($v) > 256000)) {
                $out .= $head . implode(",\n", $vals) . ";\n";
                $vals = [];
                $bytes = 0;
            }
            $vals[] = $v;
            $bytes += strlen($v) + 2;
        }
        $out .= $head . implode(",\n", $vals) . ";\n";
    }
    return $out;
}

function schema_tables(string $schemaFile): array
{
    preg_match_all('/CREATE TABLE\s+(?:IF NOT EXISTS\s+)?`?(\w+)`?/i', file_get_contents($schemaFile), $m);
    return $m[1];
}

function out(string $msg): void
{
    fwrite(STDOUT, $msg . PHP_EOL);
}
