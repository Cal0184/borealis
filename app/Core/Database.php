<?php
declare(strict_types=1);

namespace Borealis\Core;

use PDO;
use PDOStatement;

final class Database
{
    private static ?Database $instance = null;
    private int $txDepth = 0;

    public function __construct(public readonly PDO $pdo)
    {
    }

    public static function connect(array $cfg): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'] ?? '127.0.0.1', (int)($cfg['port'] ?? 3306), $cfg['database'] ?? '', $cfg['charset'] ?? 'utf8mb4');
        $pdo = new PDO($dsn, (string)($cfg['username'] ?? ''), (string)($cfg['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00', sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        return $pdo;
    }

    public static function get(): Database
    {
        if (!self::$instance) {
            self::$instance = new Database(self::connect((array)Config::get('db', [])));
        }
        return self::$instance;
    }

    public static function setInstance(?Database $db): void
    {
        self::$instance = $db;
    }

    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        foreach (array_values(array_is_list($params) ? $params : []) as $i => $v) {
            $stmt->bindValue($i + 1, $v, $this->type($v));
        }
        if (!array_is_list($params)) {
            foreach ($params as $k => $v) {
                $stmt->bindValue(':' . ltrim((string)$k, ':'), $v, $this->type($v));
            }
        }
        $stmt->execute();
        return $stmt;
    }

    private function type(mixed $v): int
    {
        return match (true) {
            is_int($v) => PDO::PARAM_INT,
            is_bool($v) => PDO::PARAM_INT,
            $v === null => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
    }

    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function value(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public function column(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $this->run($sql, array_map(fn($v) => is_bool($v) ? (int)$v : $v, array_values($data)));
        return (int)$this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        if (!$data) return 0;
        $set = implode(',', array_map(fn($c) => "`$c` = ?", array_keys($data)));
        $params = array_merge(array_map(fn($v) => is_bool($v) ? (int)$v : $v, array_values($data)), $whereParams);
        return $this->run("UPDATE `$table` SET $set WHERE $where", $params)->rowCount();
    }

    /** Nested-safe transaction helper. */
    public function transaction(callable $fn): mixed
    {
        if ($this->txDepth === 0) {
            $this->pdo->beginTransaction();
        }
        $this->txDepth++;
        try {
            $result = $fn($this);
            $this->txDepth--;
            if ($this->txDepth === 0) $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->txDepth--;
            if ($this->txDepth === 0 && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Build "IN (?,?,?)" placeholders. */
    public static function in(array $values): string
    {
        return $values ? implode(',', array_fill(0, count($values), '?')) : 'NULL';
    }
}
