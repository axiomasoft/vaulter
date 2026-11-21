<?php

declare(strict_types=1);

namespace VaulterRef\Database;

use Closure;
use LogicException;
use PDO;
use PDOException;
use Throwable;

/**
 * BOUNDARY: Vaulter\Database\VaulterDatabase. It keeps the property the port depends on:
 * transaction() is the OUTERMOST unit of work at READ COMMITTED and repeats the WHOLE callback
 * after 40001/40P01 (VaulterDatabase::transaction, packages/core/src/Database/VaulterDatabase.php:51,
 * default 3 attempts). A callback therefore runs one or more times and may not keep effects
 * outside this connection.
 */
final class Pg
{
    private function __construct(public readonly PDO $pdo, public readonly string $label) {}

    public static function connect(string $dsn, string $label): self
    {
        $pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET statement_timeout = '20s'");
        $pdo->exec('SET application_name = '.$pdo->quote($label));

        return new self($pdo, $label);
    }

    /**
     * @template T
     *
     * @param  Closure(self): T $callback
     * @return T
     */
    public function transaction(Closure $callback, int $attempts = 3): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new LogicException('A retried unit of work must be the outermost transaction.');
        }
        for ($attempt = 1; ; $attempt++) {
            $this->pdo->beginTransaction();
            try {
                $result = $callback($this);
                $this->pdo->commit();

                return $result;
            } catch (Throwable $error) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                if ($attempt < $attempts && self::isConcurrencyFailure($error)) {
                    continue;
                }
                throw $error;
            }
        }
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public static function isConcurrencyFailure(Throwable $error): bool
    {
        for ($cursor = $error; $cursor !== null; $cursor = $cursor->getPrevious()) {
            if ($cursor instanceof PDOException && in_array(self::sqlState($cursor), ['40001', '40P01'], true)) {
                return true;
            }
        }

        return false;
    }

    public static function sqlState(PDOException $error): string
    {
        return (string) ($error->errorInfo[0] ?? $error->getCode());
    }

    /** @param array<string, scalar|null> $params */
    public function row(string $sql, array $params = []): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param  array<string, scalar|null> $params
     * @return list<array<string, mixed>>
     */
    public function rows(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /** @param array<string, scalar|null> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();

        return $value === false ? null : $value;
    }

    /** @param array<string, scalar|null> $params */
    public function execute(string $sql, array $params = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }

    public function backendPid(): int
    {
        return (int) $this->value('SELECT pg_backend_pid()');
    }
}
