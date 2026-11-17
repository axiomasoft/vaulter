<?php

declare(strict_types=1);

namespace Vaulter\Documents\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Immutable compiled collaboration session options for `vaulter-documents.collaboration.*`.
 */
#[Api(since: '1.0')]
final readonly class DocumentsCollaborationOptions
{
    public function __construct(
        public int $sessionTtl = 300,
    ) {}

    public static function fromArray(mixed $raw, string $path, ConfigurationValidator $validator): self
    {
        if (!is_array($raw)) {
            $validator->add($path, 'must be an array.');

            return new self;
        }

        $validator->rejectUnknownKeys($raw, ['session_ttl'], $path);

        $ttl = array_key_exists('session_ttl', $raw) ? $raw['session_ttl'] : 300;
        if (!is_int($ttl) || $ttl < 1 || $ttl > 300) {
            $validator->add($path.'.session_ttl', '[Vaulter] vaulter-documents.collaboration.session_ttl must be an integer between 1 and 300.');
            $ttl = 300;
        }

        return new self(sessionTtl: $ttl);
    }
}
