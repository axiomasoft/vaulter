<?php

declare(strict_types=1);

namespace Vaulter\Drive\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Immutable compiled drive lifecycle options for `vaulter-drive.drives.*`.
 */
#[Api(since: '1.0')]
final readonly class DrivesOptions
{
    public function __construct(
        public bool $deleteWithOwner = false,
    ) {}

    public static function fromArray(mixed $raw, string $path, ConfigurationValidator $validator): self
    {
        if (!is_array($raw)) {
            $validator->add($path, 'must be an array.');

            return new self;
        }

        $validator->rejectUnknownKeys($raw, ['delete_with_owner'], $path);

        return new self(
            deleteWithOwner: ($raw['delete_with_owner'] ?? false) === true,
        );
    }
}
