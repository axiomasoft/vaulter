<?php

declare(strict_types=1);

namespace Vaulter\Database;

use Packman\Attributes\Internal;
use PDOException;
use Throwable;

#[Internal]
final class DatabaseErrorMapper
{
    public static function isForeignKeyViolation(Throwable $exception): bool
    {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof PDOException
                && in_array($cause->errorInfo[0] ?? (string) $cause->getCode(), ['23503', '23001'], true)) {
                return true;
            }
        }

        return false;
    }
}
