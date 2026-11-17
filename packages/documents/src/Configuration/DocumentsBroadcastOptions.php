<?php

declare(strict_types=1);

namespace Vaulter\Documents\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Immutable compiled broadcast options for `vaulter-documents.broadcast.*`.
 */
#[Api(since: '1.0')]
final readonly class DocumentsBroadcastOptions
{
    public function __construct(
        public string $channelPrefix = 'vaulter.documents',
    ) {}

    public static function fromArray(mixed $raw, string $path, ConfigurationValidator $validator): self
    {
        if (!is_array($raw)) {
            $validator->add($path, 'must be an array.');

            return new self;
        }

        $validator->rejectUnknownKeys($raw, ['channel_prefix'], $path);

        $prefix = $raw['channel_prefix'] ?? 'vaulter.documents';

        return new self(
            channelPrefix: is_string($prefix) ? $prefix : 'vaulter.documents',
        );
    }
}
