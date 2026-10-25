<?php
declare(strict_types=1);

/**
 * Runs the upgrade scripts (database/migrations/*.sql) on a company database.
 *
 * With one company they are run by hand in phpMyAdmin or Navicat, as always. With several, the Super Admin
 * can bring every company database up to date from the platform, because each company has a database of
 * its own. The scripts are the same files, written for MySQL 5.5 and safe to run twice.
 */
final class Migrator
{
    /**
     * Splits a .sql file into statements: honours DELIMITER lines, quoted text and comments,
     * so a semicolon inside a string or a trigger body does not split a statement.
     */
    public static function split(string $sql): array
    {
        $out = [];
        $delim = ';';
        $buf = '';
        $mode = '';
        $lines = preg_split('/\R/', $sql) ?: [];
        foreach ($lines as $line) {
            if ($mode === '' && trim($buf) === '' && preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $m)) {
                $delim = $m[1];
                continue;
            }
            $len = strlen($line);
            for ($i = 0; $i < $len; $i++) {
                $ch = $line[$i];
                if ($mode === 'block') {
                    if ($ch === '*' && ($line[$i + 1] ?? '') === '/') {
                        $mode = '';
                        $i++;
                    }
                    continue;
                }
                if ($mode !== '') {
                    $buf .= $ch;
                    if ($ch === '\\' && $mode !== '`') {
                        $buf .= $line[++$i] ?? '';
                    } elseif ($ch === $mode) {
                        $mode = '';
                    }
                    continue;
                }
                if ($ch === '-' && ($line[$i + 1] ?? '') === '-' && in_array($line[$i + 2] ?? ' ', [' ', "\t", ''], true)) {
                    break;                                    // comment to the end of the line
                }
                if ($ch === '#') {
                    break;
                }
                if ($ch === '/' && ($line[$i + 1] ?? '') === '*') {
                    $mode = 'block';
                    $i++;
                    continue;
                }
                if ($ch === "'" || $ch === '"' || $ch === '`') {
                    $mode = $ch;
                    $buf .= $ch;
                    continue;
                }
                if (substr($line, $i, strlen($delim)) === $delim) {
                    $one = trim($buf);
                    if ($one !== '') {
                        $out[] = $one;
                    }
                    $buf = '';
                    $i += strlen($delim) - 1;
                    continue;
                }
                $buf .= $ch;
            }
            $buf .= "\n";
        }
        if (trim($buf) !== '') {
            $out[] = trim($buf);
        }
        return $out;
    }

    /**
     * Runs every upgrade script still pending on one company database, oldest first.
     * @return array{ran: string[], errors: string[]}
     */
    public static function runPending(Sql $db): array
    {
        $ran = [];
        $errors = [];
        foreach (Schema::pendingOn($db) as $file) {
            $path = APP_ROOT . '/' . $file;
            if (!is_file($path)) {
                $errors[] = "$file is missing from the program folder.";
                break;
            }
            try {
                foreach (self::split((string)file_get_contents($path)) as $stmt) {
                    $db->exec($stmt);
                }
                $ran[] = $file;
            } catch (Throwable $e) {
                $errors[] = basename($file) . ': ' . $e->getMessage();
                break;
            }
        }
        return ['ran' => $ran, 'errors' => $errors];
    }
}
