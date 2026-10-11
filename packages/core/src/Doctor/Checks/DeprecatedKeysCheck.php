<?php

declare(strict_types=1);

namespace Vaulter\Doctor\Checks;

use Vaulter\Configuration\ConfigNormalizer;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;

final readonly class DeprecatedKeysCheck implements DoctorCheck
{
    public function __construct(private ConfigNormalizer $normalizer) {}

    public function key(): string
    {
        return 'core.config.deprecated_keys';
    }

    public function title(): string
    {
        return 'Deprecated configuration keys';
    }

    public function run(DoctorContext $context): array
    {
        $keys = $this->normalizer->usedDeprecatedKeys();
        if ($keys === []) {
            return [DoctorFinding::ok(code: 'core.config.no_deprecated_keys', message: 'No deprecated configuration key is set.')];
        }

        return [DoctorFinding::warning(
            code: 'core.config.deprecated_keys',
            message: count($keys).' deprecated configuration key(s) are still set.',
            hint: 'move each value to its replacement; the deprecation notice in the log names it',
            evidence: ['count' => count($keys), 'keys' => implode(', ', array_slice($keys, 0, 10))],
        )];
    }
}
