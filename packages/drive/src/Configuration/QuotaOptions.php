<?php

declare(strict_types=1);

namespace Vaulter\Drive\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Immutable compiled default drive quota options for `vaulter-drive.quota.*`.
 */
#[Api(since: '1.0')]
final readonly class QuotaOptions
{
    public function __construct(
        public ?int $defaultBytes = null,
    ) {}

    public static function fromArray(mixed $raw, string $path, ConfigurationValidator $validator): self
    {
        if (!is_array($raw)) {
            $validator->add($path, 'must be an array.');

            return new self;
        }

        $validator->rejectUnknownKeys($raw, ['default_bytes'], $path);

        $value = $raw['default_bytes'] ?? null;
        if ($value === null) {
            return new self;
        }

        if (!is_int($value) || $value < 0) {
            $validator->add($path.'.default_bytes', 'Default quota must be nonnegative or null.');

            return new self;
        }

        return new self(defaultBytes: $value);
    }
}
