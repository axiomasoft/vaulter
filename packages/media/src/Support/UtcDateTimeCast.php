<?php

declare(strict_types=1);

namespace Vaulter\Media\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Vaulter\Attributes\Internal;

/** @implements CastsAttributes<CarbonImmutable,DateTimeInterface|string> */
#[Internal]
final class UtcDateTimeCast implements CastsAttributes
{
    /** @param array<string,mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) && !$value instanceof DateTimeInterface) {
            throw new InvalidArgumentException('Timestamp must be a UTC date.');
        }

        return CarbonImmutable::parse($value, 'UTC')->utc();
    }

    /** @param array<string,mixed> $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC')->utc()->format($model->getDateFormat());
    }
}
