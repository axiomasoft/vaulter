<?php

declare(strict_types=1);

namespace Vaulter\Observability;

use Packman\Attributes\Internal;

#[Internal]
final class TracingAttributes
{
    /**
     * @param  array<string, scalar> $extra
     * @return array<string, scalar>
     */
    public static function forTarget(string $targetId, string $layer, array $extra = []): array
    {
        return [
            'vaulter.target' => $targetId,
            'vaulter.layer' => $layer,
            ...$extra,
        ];
    }
}
