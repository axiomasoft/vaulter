<?php

declare(strict_types=1);

namespace Vaulter\Drive\Idempotency;

use DateTimeImmutable;
use DateTimeInterface;
use LogicException;
use Packman\Attributes\Internal;

/** Reading and writing the fields of a drive receipt body; a missing or malformed field is a broken receipt, never a guess. */
#[Internal]
final class ReceiptFields
{
    public const string TIME = 'Y-m-d\TH:i:s.uP';

    public static function time(?DateTimeInterface $time): ?string
    {
        return $time?->format(self::TIME);
    }

    /** @param array<array-key, mixed> $fields */
    public static function text(array $fields, string $key, string $receipt): string
    {
        $value = $fields[$key] ?? null;

        return is_scalar($value) ? (string) $value : throw new LogicException(sprintf('[Vaulter] A %s receipt has no "%s".', $receipt, $key));
    }

    /** @param array<array-key, mixed> $fields */
    public static function nullableText(array $fields, string $key): ?string
    {
        $value = $fields[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /** @param array<array-key, mixed> $fields */
    public static function int(array $fields, string $key, string $receipt): int
    {
        $value = $fields[$key] ?? null;

        return is_numeric($value) ? (int) $value : throw new LogicException(sprintf('[Vaulter] A %s receipt has no number "%s".', $receipt, $key));
    }

    /** @param array<array-key, mixed> $fields */
    public static function nullableInt(array $fields, string $key): ?int
    {
        $value = $fields[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /** @param array<array-key, mixed> $fields */
    public static function bool(array $fields, string $key, string $receipt): bool
    {
        $value = $fields[$key] ?? null;

        return is_bool($value) ? $value : throw new LogicException(sprintf('[Vaulter] A %s receipt has no flag "%s".', $receipt, $key));
    }

    /** @param array<array-key, mixed> $fields */
    public static function parse(array $fields, string $key, string $receipt): ?DateTimeImmutable
    {
        $value = $fields[$key] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat(self::TIME, $value);

        return $parsed !== false ? $parsed : throw new LogicException(sprintf('[Vaulter] A %s receipt holds a malformed time "%s".', $receipt, $key));
    }

    /** @param array<array-key, mixed> $fields */
    public static function requiredTime(array $fields, string $key, string $receipt): DateTimeImmutable
    {
        return self::parse($fields, $key, $receipt) ?? throw new LogicException(sprintf('[Vaulter] A %s receipt has no time "%s".', $receipt, $key));
    }

    /**
     * @param  array<array-key, mixed> $fields
     * @return array<array-key, mixed>
     */
    public static function object(array $fields, string $key, string $receipt): array
    {
        $value = $fields[$key] ?? null;

        return is_array($value) ? $value : throw new LogicException(sprintf('[Vaulter] A %s receipt has no object "%s".', $receipt, $key));
    }

    /** @param array<array-key, mixed> $body */
    public static function assertVersion(array $body, int $version, string $kind): void
    {
        if ($version !== 1) {
            throw new LogicException(sprintf('[Vaulter] The receipt codec %s does not know version %d.', $kind, $version));
        }
    }
}
