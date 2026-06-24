<?php

require_once __DIR__ . '/../db/drivers/DbDriver.php';
require_once __DIR__ . '/Entity.php';

/**
 * Generic CRUD repository for any table that follows the {@see Entity}
 * column convention. Phase 1+ entities reuse this class as-is by pointing it
 * at their table; no per-entity boilerplate is required.
 *
 * Talks only to {@see DbDriver}, so it works on every supported backend.
 */
class EntityRepository
{
    private DbDriver $driver;
    private string $table;
    /** @var class-string<Entity> */
    private string $entityClass;
    /** @var list<string>|null Cached column names of the table. */
    private ?array $columns = null;

    /**
     * @param class-string<Entity> $entityClass Concrete entity to hydrate.
     */
    public function __construct(DbDriver $driver, string $table, string $entityClass = Entity::class)
    {
        if (!self::isValidIdentifier($table)) {
            throw new InvalidArgumentException("Invalid table name: $table");
        }
        if (!is_a($entityClass, Entity::class, true)) {
            throw new InvalidArgumentException("$entityClass must extend Entity");
        }
        $this->driver = $driver;
        $this->table = $table;
        $this->entityClass = $entityClass;
    }

    public function find(int $id): ?Entity
    {
        $row = $this->driver->selectOne(
            "SELECT * FROM {$this->table} WHERE id = ?",
            [[$id, DbDriver::INT]]
        );
        return $row === [] ? null : $this->make($row);
    }

    public function findByName(string $name): ?Entity
    {
        $row = $this->driver->selectOne(
            "SELECT * FROM {$this->table} WHERE name = ? " . $this->driver->caseInsensitiveCollation() . " LIMIT 1",
            [[$name, DbDriver::TEXT]]
        );
        return $row === [] ? null : $this->make($row);
    }

    /**
     * @param array<string,mixed> $where Equality conditions ANDed together.
     * @return list<Entity>
     */
    public function findAll(
        array $where = [],
        string $orderBy = 'id',
        string $dir = 'ASC',
        ?int $limit = null,
        ?int $offset = null
    ): array {
        [$clause, $params] = $this->buildWhere($where);
        $orderBy = $this->assertColumn($orderBy);
        $dir = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';

        $sql = "SELECT * FROM {$this->table}$clause ORDER BY $orderBy $dir";
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int)$limit;
            if ($offset !== null) {
                $sql .= ' OFFSET ' . (int)$offset;
            }
        }

        return array_map(fn(array $row) => $this->make($row), $this->driver->select($sql, $params));
    }

    /** @param array<string,mixed> $where */
    public function count(array $where = []): int
    {
        [$clause, $params] = $this->buildWhere($where);
        $row = $this->driver->selectOne("SELECT COUNT(*) AS c FROM {$this->table}$clause", $params);
        return (int)($row['c'] ?? 0);
    }

    /**
     * Insert (when id is null) or update the entity. Manages created_at /
     * updated_at timestamps and returns the same instance with id populated.
     */
    public function save(Entity $entity): Entity
    {
        $now = time();
        $cols = $this->writableColumns();
        $row = $entity->toRow();

        if ($entity->id === null) {
            $entity->created_at = $now;
            $entity->updated_at = $now;
            $row = $entity->toRow();

            $insertCols = array_values(array_intersect($cols, array_keys($row)));
            $placeholders = implode(',', array_fill(0, count($insertCols), '?'));
            $params = array_map(fn(string $c) => $this->bind($row[$c]), $insertCols);

            $id = $this->driver->insert(
                "INSERT INTO {$this->table} (" . implode(',', $insertCols) . ") VALUES ($placeholders)",
                $params
            );
            $entity->id = $id;
            return $entity;
        }

        $entity->updated_at = $now;
        $row = $entity->toRow();
        unset($row['created_at']); // never overwrite creation time on update

        $updateCols = array_values(array_intersect($cols, array_keys($row)));
        $assignments = implode(',', array_map(static fn(string $c) => "$c = ?", $updateCols));
        $params = array_map(fn(string $c) => $this->bind($row[$c]), $updateCols);
        $params[] = [$entity->id, DbDriver::INT];

        $this->driver->execute("UPDATE {$this->table} SET $assignments WHERE id = ?", $params);
        return $entity;
    }

    public function delete(int $id): bool
    {
        $this->driver->execute("DELETE FROM {$this->table} WHERE id = ?", [[$id, DbDriver::INT]]);
        return $this->driver->affectedRows() > 0;
    }

    /**
     * @param array<string,mixed> $where
     * @return array{0:string,1:list<array{0:mixed,1:int}>}
     */
    private function buildWhere(array $where): array
    {
        if ($where === []) {
            return ['', []];
        }
        $parts = [];
        $params = [];
        foreach ($where as $column => $value) {
            $column = $this->assertColumn($column);
            if ($value === null) {
                $parts[] = "$column IS NULL";
                continue;
            }
            $parts[] = "$column = ?";
            $params[] = $this->bind($value);
        }
        return [' WHERE ' . implode(' AND ', $parts), $params];
    }

    /** Infer a driver bind pair [value, type] from a PHP value. */
    private function bind(mixed $value): array
    {
        if (is_int($value)) {
            return [$value, DbDriver::INT];
        }
        if (is_float($value)) {
            return [$value, DbDriver::FLOAT];
        }
        if ($value === null) {
            return [null, DbDriver::NULL];
        }
        if (is_bool($value)) {
            return [$value ? 1 : 0, DbDriver::INT];
        }
        return [(string)$value, DbDriver::TEXT];
    }

    private function make(array $row): Entity
    {
        /** @var Entity $entity */
        $entity = new $this->entityClass($row);
        return $entity;
    }

    /** @return list<string> */
    private function tableColumns(): array
    {
        if ($this->columns === null) {
            $this->columns = $this->driver->tableColumns($this->table);
        }
        return $this->columns;
    }

    /** @return list<string> Persistable columns excluding the id primary key. */
    private function writableColumns(): array
    {
        return array_values(array_filter($this->tableColumns(), static fn(string $c) => $c !== 'id'));
    }

    private function assertColumn(string $column): string
    {
        if (!self::isValidIdentifier($column) || !in_array($column, $this->tableColumns(), true)) {
            throw new InvalidArgumentException("Unknown column '$column' on table {$this->table}");
        }
        return $column;
    }

    private static function isValidIdentifier(string $identifier): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) === 1;
    }
}
