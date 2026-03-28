<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Simple Query Builder
 */
class QueryBuilder
{
    private Database $db;
    private string $table;
    private array $select = ['*'];
    private array $where = [];
    private array $params = [];
    private ?string $orderBy = null;
    private ?int $limit = null;
    private ?int $offset = null;

    public function __construct(Database $db, string $table)
    {
        $this->db = $db;
        $this->table = $table;
    }

    public function select(array $columns): self
    {
        $this->select = $columns;
        return $this;
    }

    public function where(string $column, string $operator, mixed $value): self
    {
        $quotedColumn = '`' . str_replace('`', '``', $column) . '`';
        $this->where[] = "{$quotedColumn} {$operator} ?";
        $this->params[] = $value;
        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $quotedColumn = '`' . str_replace('`', '``', $column) . '`';
        $this->where[] = "{$quotedColumn} IS NOT NULL";
        return $this;
    }

    public function whereNull(string $column): self
    {
        $quotedColumn = '`' . str_replace('`', '``', $column) . '`';
        $this->where[] = "{$quotedColumn} IS NULL";
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->orderBy = "{$column} {$direction}";
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    public function get(): array
    {
        $sql = $this->buildSelect();
        return $this->db->fetchAll($sql, $this->params);
    }

    public function first(): ?array
    {
        $this->limit = 1;
        $sql = $this->buildSelect();
        return $this->db->fetch($sql, $this->params);
    }

    public function count(): int
    {
        $where = empty($this->where) ? '1=1' : implode(' AND ', $this->where);
        $sql = "SELECT COUNT(*) FROM {$this->table} WHERE {$where}";
        return (int) $this->db->fetchColumn($sql, $this->params);
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    public function insert(array $data): int
    {
        return $this->db->insert($this->table, $data);
    }

    public function update(array $data): int
    {
        $where = empty($this->where) ? '1=1' : implode(' AND ', $this->where);
        return $this->db->update($this->table, $data, $where, $this->params);
    }

    public function delete(): int
    {
        $where = empty($this->where) ? '1=1' : implode(' AND ', $this->where);
        return $this->db->delete($this->table, $where, $this->params);
    }

    private function buildSelect(): string
    {
        $columns = implode(', ', $this->select);
        $where = empty($this->where) ? '1=1' : implode(' AND ', $this->where);

        $sql = "SELECT {$columns} FROM {$this->table} WHERE {$where}";

        if ($this->orderBy) {
            $sql .= " ORDER BY {$this->orderBy}";
        }
        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
        }
        if ($this->offset !== null) {
            $sql .= " OFFSET {$this->offset}";
        }

        return $sql;
    }
}

/**
 * Database Service - PDO Wrapper with Query Builder
 */
class Database
{
    private PDO $pdo;
    private array $settings;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
        $this->connect();
    }

    private function connect(): void
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->settings['host'],
            $this->settings['port'] ?? 3306,
            $this->settings['database'],
            $this->settings['charset'] ?? 'utf8mb4'
        );

        $offset = (new \DateTime())->format('P');
        $charset = $this->settings['charset'] ?? 'utf8mb4';
        $collation = $this->settings['collation'] ?? 'utf8mb4_unicode_ci';

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE {$collation}, time_zone = '{$offset}'"
        ];

        try {
            $this->pdo = new PDO(
                $dsn,
                $this->settings['username'],
                $this->settings['password'],
                $options
            );
        } catch (PDOException $e) {
            throw new \RuntimeException('Database connection failed: ' . $e->getMessage());
        }
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Start a query builder for a table
     */
    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this, $table);
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        return $this->query($sql, $params)->fetch() ?: null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchColumn(string $sql, array $params = [], int $column = 0): mixed
    {
        return $this->query($sql, $params)->fetchColumn($column);
    }

    public function insert(string $table, array $data): int
    {
        $columns = implode(', ', array_map(fn($col) => "`{$col}`", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $sql = "INSERT INTO `{$table}` ({$columns}) VALUES ({$placeholders})";
        $this->query($sql, array_values($data));

        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $set = implode(', ', array_map(fn($col) => "`{$col}` = ?", array_keys($data)));
        $sql = "UPDATE `{$table}` SET {$set} WHERE {$where}";

        $params = array_merge(array_values($data), $whereParams);
        return $this->query($sql, $params)->rowCount();
    }

    public function delete(string $table, string $where, array $params = []): int
    {
        $sql = "DELETE FROM `{$table}` WHERE {$where}";
        return $this->query($sql, $params)->rowCount();
    }

    public function exists(string $table, string $where, array $params = []): bool
    {
        $sql = "SELECT 1 FROM `{$table}` WHERE {$where} LIMIT 1";
        return (bool) $this->fetchColumn($sql, $params);
    }

    public function count(string $table, string $where = '1=1', array $params = []): int
    {
        $sql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where}";
        return (int) $this->fetchColumn($sql, $params);
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    public function rollback(): bool
    {
        return $this->pdo->rollBack();
    }

    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }
}
