<?php

declare(strict_types=1);

namespace Vaulter\Data\Snapshots\Mapping;

use DateTimeImmutable;
use DateTimeInterface;
use Vaulter\Attributes\Internal;
use Vaulter\Support\Identity;

/** @internal Scalar conversions shared by the snapshot mappers. */
#[Internal]
final readonly class SnapshotValue
{
    private function __construct() {}

    public static function time(?DateTimeInterface $time): ?DateTimeImmutable
    {
        return $time === null ? null : DateTimeImmutable::createFromInterface($time)->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function requiredTime(?DateTimeInterface $time): DateTimeImmutable
    {
        return self::time($time) ?? new DateTimeImmutable('@0');
    }

    /** The REST shape of a time: what Carbon serialised before (UTC, microseconds, trailing Z). */
    public static function iso(?DateTimeInterface $time): ?string
    {
        return $time === null ? null : DateTimeImmutable::createFromInterface($time)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    public static function id(mixed $id): string
    {
        return Identity::stringify(is_int($id) || is_string($id) ? $id : '');
    }

    public static function nullableId(mixed $id): ?string
    {
        return is_int($id) || is_string($id) ? Identity::stringify($id) : null;
    }
}
