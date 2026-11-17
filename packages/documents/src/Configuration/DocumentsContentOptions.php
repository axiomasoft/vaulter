<?php

declare(strict_types=1);

namespace Vaulter\Documents\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Immutable compiled document content options for `vaulter-documents.content.*`.
 */
#[Api(since: '1.0')]
final readonly class DocumentsContentOptions
{
    public function __construct(
        public int $maxSizeKb = 5120,
    ) {}

    public static function fromArray(mixed $raw, string $path, ConfigurationValidator $validator): self
    {
        if (!is_array($raw)) {
            $validator->add($path, 'must be an array.');

            return new self;
        }

        $validator->rejectUnknownKeys($raw, ['max_size_kb'], $path);

        $maxSizeKb = $raw['max_size_kb'] ?? 5120;

        return new self(
            maxSizeKb: is_numeric($maxSizeKb) ? (int) $maxSizeKb : 5120,
        );
    }
}
