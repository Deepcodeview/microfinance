<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;
use Throwable;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                Env::get('DB_HOST', '127.0.0.1'),
                Env::int('DB_PORT', 3306),
                Env::get('DB_NAME', 'finserve'),
            );
            self::$pdo = new PDO($dsn, Env::get('DB_USER'), Env::get('DB_PASS'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => true,
            ]);
            self::$pdo->exec("SET time_zone = '+00:00'");
            // Same strictness everywhere (shared hosts differ in their defaults): reject bad data instead of truncating it.
            self::$pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
        }
        return self::$pdo;
    }

    /** @param array<string|int, mixed> $params */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** @param array<string|int, mixed> $params */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string|int, mixed> $params */
    public static function all(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /** @param array<string|int, mixed> $params */
    public static function value(string $sql, array $params = []): mixed
    {
        $value = self::query($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** @param array<string, mixed> $row */
    public static function insert(string $table, array $row): int
    {
        $columns = array_keys($row);
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`, `', $columns),
            implode(', ', array_map(fn ($c) => ':' . $c, $columns)),
        );
        self::query($sql, $row);
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Runs $fn inside a transaction; rolls back on any exception.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Next human-readable number for a sequence, e.g. CUS000001. Must run inside a transaction. */
    public static function nextNumber(string $sequence): string
    {
        $row = self::one('SELECT prefix, pad_length, next_value FROM number_sequences WHERE name = ? FOR UPDATE', [$sequence]);
        if ($row === null) {
            throw new \RuntimeException("Unknown number sequence: {$sequence}");
        }
        self::query('UPDATE number_sequences SET next_value = next_value + 1 WHERE name = ?', [$sequence]);
        return $row['prefix'] . str_pad($row['next_value'], (int) $row['pad_length'], '0', STR_PAD_LEFT);
    }
}
