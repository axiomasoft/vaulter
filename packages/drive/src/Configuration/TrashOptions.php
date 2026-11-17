<?php

declare(strict_types=1);

namespace Vaulter\Drive\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Immutable compiled trash retention options for `vaulter-drive.trash.*`.
 */
#[Api(since: '1.0')]
final readonly class TrashOptions
{
    public function __construct(
        public int $retentionDays = 30,
    ) {}

    public static function fromArray(mixed $raw, string $path, ConfigurationValidator $validator): self
    {
        if (!is_array($raw)) {
            $validator->add($path, 'must be an array.');

            return new self;
        }

        $validator->rejectUnknownKeys($raw, ['retention_days'], $path);

        $days = $raw['retention_days'] ?? 30;

        return new self(
            retentionDays: is_numeric($days) ? max((int) $days, 0) : 30,
        );
    }
}
