<?php

declare(strict_types=1);

namespace Luxid\Database;

use Luxid\Foundation\Application;
use Luxid\ORM\Entity;
use PDO;
use PDOStatement;

/**
 * Legacy Active Record base class.
 *
 * Predates Rocket ORM and remains for applications that have not migrated. New
 * entities should extend `Rocket\ORM\Entity`, which is attribute driven and
 * carries relations, migrations and seeding.
 *
 * Column names reaching SQL are validated against {@see DbEntity::attributes()}
 * so a `where` array assembled from request data cannot smuggle in an
 * identifier: values are bound, but identifiers cannot be.
 *
 * @property int $id The primary key
 *
 * @package Luxid\Database
 */
abstract class DbEntity extends Entity
{
    /**
     * Name of the backing table.
     */
    abstract public static function tableName(): string;

    /**
     * Every database column mapped by this entity.
     *
     * @return list<string>
     */
    abstract public function attributes(): array;

    /**
     * Name of the primary key column.
     */
    abstract public static function primaryKey(): string;

    /**
     * Insert the entity as a new row.
     */
    public function save(): bool
    {
        $table = static::tableName();
        $attributes = $this->attributes();
        $placeholders = array_map(static fn (string $attr): string => ':' . $attr, $attributes);

        $statement = static::prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $attributes),
            implode(', ', $placeholders)
        ));

        foreach ($attributes as $attribute) {
            $statement->bindValue(':' . $attribute, $this->normalize($this->{$attribute} ?? null));
        }

        if (!$statement->execute()) {
            return false;
        }

        $primaryKey = static::primaryKey();

        // Only claim the generated id when the entity did not carry one already.
        if (empty($this->{$primaryKey} ?? null)) {
            $this->{$primaryKey} = static::lastInsertId();
        }

        return true;
    }

    /**
     * Persist changes to an existing row.
     */
    public function update(): bool
    {
        $primaryKey = static::primaryKey();
        $columns = array_filter(
            $this->attributes(),
            static fn (string $attr): bool => $attr !== $primaryKey
        );

        if ($columns === []) {
            return false;
        }

        $assignments = implode(', ', array_map(
            static fn (string $attr): string => $attr . ' = :' . $attr,
            $columns
        ));

        $statement = static::prepare(sprintf(
            'UPDATE %s SET %s WHERE %s = :__pk',
            static::tableName(),
            $assignments,
            $primaryKey
        ));

        foreach ($columns as $attribute) {
            $statement->bindValue(':' . $attribute, $this->normalize($this->{$attribute} ?? null));
        }

        $statement->bindValue(':__pk', $this->{$primaryKey});

        return $statement->execute();
    }

    /**
     * Delete the row backing this entity.
     */
    public function delete(): bool
    {
        $primaryKey = static::primaryKey();

        $statement = static::prepare(sprintf(
            'DELETE FROM %s WHERE %s = :__pk',
            static::tableName(),
            $primaryKey
        ));

        $statement->bindValue(':__pk', $this->{$primaryKey});

        return $statement->execute();
    }

    /**
     * Find the first row matching every given column.
     *
     * @param array<string, mixed> $where Column/value pairs combined with AND
     *
     * @throws \InvalidArgumentException When a column is not mapped by this entity
     */
    public static function findOne(array $where): ?static
    {
        if ($where === []) {
            return null;
        }

        $columns = static::assertColumns(array_keys($where));

        $statement = static::prepare(sprintf(
            'SELECT * FROM %s WHERE %s LIMIT 1',
            static::tableName(),
            implode(' AND ', array_map(static fn (string $c): string => $c . ' = :' . $c, $columns))
        ));

        foreach ($where as $column => $value) {
            $statement->bindValue(':' . $column, $value);
        }

        $statement->execute();

        return $statement->fetchObject(static::class) ?: null;
    }

    /**
     * Find every row matching the given columns.
     *
     * @param array<string, mixed> $where   Column/value pairs combined with AND
     * @param string               $orderBy Column to sort by; must be a mapped column
     * @param string               $direction Sort direction, `ASC` or `DESC`
     *
     * @return list<static>
     *
     * @throws \InvalidArgumentException When a column or direction is not valid
     */
    public static function findAll(array $where = [], string $orderBy = '', string $direction = 'ASC'): array
    {
        $sql = 'SELECT * FROM ' . static::tableName();

        if ($where !== []) {
            $columns = static::assertColumns(array_keys($where));
            $sql .= ' WHERE ' . implode(
                ' AND ',
                array_map(static fn (string $c): string => $c . ' = :' . $c, $columns)
            );
        }

        if ($orderBy !== '') {
            // Identifiers cannot be bound, so the column is checked against the
            // entity's own column list and the direction against a fixed set.
            $sql .= ' ORDER BY ' . static::assertColumns([$orderBy])[0] . ' ' . static::assertDirection($direction);
        }

        $statement = static::prepare($sql);

        foreach ($where as $column => $value) {
            $statement->bindValue(':' . $column, $value);
        }

        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_CLASS, static::class);
    }

    /**
     * Find a row by primary key.
     *
     * @param int|string $id Primary key value
     */
    public static function find(int|string $id): ?static
    {
        return static::findOne([static::primaryKey() => $id]);
    }

    /**
     * Reject any column this entity does not map.
     *
     * @param list<string> $columns Candidate column names
     *
     * @return list<string> The validated columns
     *
     * @throws \InvalidArgumentException When a column is not mapped
     */
    protected static function assertColumns(array $columns): array
    {
        $allowed = (new static())->attributes();

        foreach ($columns as $column) {
            if (!in_array($column, $allowed, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Unknown column "%s" for %s',
                    $column,
                    static::class
                ));
            }
        }

        return array_values($columns);
    }

    /**
     * Reject any sort direction other than ASC or DESC.
     *
     * @param string $direction Candidate direction
     *
     * @throws \InvalidArgumentException When the direction is not recognised
     */
    protected static function assertDirection(string $direction): string
    {
        $direction = strtoupper(trim($direction));

        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException(sprintf('Invalid sort direction "%s"', $direction));
        }

        return $direction;
    }

    /**
     * Coerce PHP values into something PDO can bind.
     *
     * @param mixed $value Raw property value
     */
    protected function normalize(mixed $value): mixed
    {
        return is_bool($value) ? (int) $value : $value;
    }

    /**
     * Prepare a statement on the application connection.
     *
     * Goes through `getPdo()` rather than reaching for a `$pdo` property, which
     * is protected on Rocket's connection and previously made every query here
     * fail at runtime.
     *
     * @param string $sql SQL to prepare
     *
     * @throws \RuntimeException When no database is configured
     */
    public static function prepare(string $sql): PDOStatement
    {
        return static::pdo()->prepare($sql);
    }

    /**
     * Get the id generated by the most recent insert.
     */
    public static function lastInsertId(): int
    {
        return (int) static::pdo()->lastInsertId();
    }

    /**
     * Resolve the PDO handle behind the application connection.
     *
     * @throws \RuntimeException When no database is configured
     */
    protected static function pdo(): PDO
    {
        $connection = Application::$app->db ?? null;

        if ($connection === null) {
            throw new \RuntimeException('No database connection configured for this application.');
        }

        return $connection->getPdo();
    }
}
