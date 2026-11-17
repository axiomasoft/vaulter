<?php

declare(strict_types=1);

namespace Vaulter\Documents\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Immutable compiled document revision options for `vaulter-documents.revisions.*`.
 */
#[Api(since: '1.0')]
final readonly class DocumentsRevisionsOptions
{
    public function __construct(
        public int $limit = 50,
        public int $checkpointIntervalSeconds = 300,
    ) {}

    public static function fromArray(mixed $raw, string $path, ConfigurationValidator $validator): self
    {
        if (!is_array($raw)) {
            $validator->add($path, 'must be an array.');

            return new self;
        }

        $validator->rejectUnknownKeys($raw, ['limit', 'checkpoint_interval_seconds'], $path);

        $limit = $raw['limit'] ?? 50;
        $interval = $raw['checkpoint_interval_seconds'] ?? 300;
        $seconds = is_numeric($interval) ? (int) $interval : 300;

        return new self(
            limit: is_numeric($limit) ? (int) $limit : 50,
            checkpointIntervalSeconds: max($seconds, 0),
        );
    }
}
