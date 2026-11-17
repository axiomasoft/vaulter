<?php

declare(strict_types=1);

namespace Vaulter\Documents\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Immutable compiled comments feature options for `vaulter-documents.comments.*`.
 */
#[Api(since: '1.0')]
final readonly class DocumentsCommentsOptions
{
    public function __construct(
        public bool $enabled = true,
    ) {}

    public static function fromArray(mixed $raw, string $path, ConfigurationValidator $validator): self
    {
        if (!is_array($raw)) {
            $validator->add($path, 'must be an array.');

            return new self;
        }

        $validator->rejectUnknownKeys($raw, ['enabled'], $path);

        $enabled = $raw['enabled'] ?? true;

        return new self(
            enabled: is_bool($enabled) ? $enabled : true,
        );
    }
}
