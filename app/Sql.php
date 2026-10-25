<?php
declare(strict_types=1);

/**
 * The same helpers as DB (run, all, row, value, column, insert, update), on a connection of its own.
 * Used for the master database and, by the Super Admin pages and the company provisioning, for a company
 * database other than the one the request works on. Modules keep using DB.
 */
final class Sql
{
    public function __construct(public readonly PDO $pdo)
    {
    }

    /** A connection to one database (null = the server only) */
    public static function to(?string $database): self
    {
        return new self(DB::connect($database));
    }

    public function run(string $sql, array $params = []): PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $key = is_int($k) ? $k + 1 : (str_starts_with((string)$k, ':') ? $k : ':' . $k);
            if (is_bool($v)) {
                $st->bindValue($key, $v ? 1 : 0, PDO::PARAM_INT);
            } elseif (is_int($v)) {
                $st->bindValue($key, $v, PDO::PARAM_INT);
            } elseif ($v === null) {
                $st->bindValue($key, null, PDO::PARAM_NULL);
            } else {
                $st->bindValue($key, (string)$v, PDO::PARAM_STR);
            }
        }
        $st->execute();
        return $st;
    }

    public function exec(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function row(string $sql, array $params = []): ?array
    {
        $r = $this->run($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public function value(string $sql, array $params = [])
    {
        $r = $this->run($sql, $params)->fetchColumn();
        return $r === false ? null : $r;
    }

    public function column(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    public function pairs(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (:' . implode(',:', $cols) . ')';
        $this->run($sql, $data);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        $params = [];
        foreach ($data as $k => $v) {
            $sets[] = "`$k` = :set_$k";
            $params["set_$k"] = $v;
        }
        return $this->run('UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE ' . $where, array_merge($params, $whereParams))->rowCount();
    }

    /** Runs a callback inside one transaction */
    public function tx(callable $fn)
    {
        $this->pdo->beginTransaction();
        try {
            $r = $fn($this);
            $this->pdo->commit();
            return $r;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
