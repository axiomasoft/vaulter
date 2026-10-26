<?php

declare(strict_types=1);

namespace Vaulter\Documents\State;

use InvalidArgumentException;
use Vaulter\Attributes\Api;

/** Immutable identity of one persisted generation. */
#[Api]
final readonly class CollaborationStateBinding
{
    public function __construct(
        public string $protocol,
        public string $stateFormat,
        public int $schemaVersion,
        public int $generation,
    ) {
        if (trim($protocol) === '' || trim($stateFormat) === '' || $schemaVersion < 1 || $generation < 0) {
            throw new InvalidArgumentException('[Vaulter] Invalid collaboration state binding.');
        }
    }

    public function sameAs(self $other): bool
    {
        return $this->protocol === $other->protocol && $this->stateFormat === $other->stateFormat
            && $this->schemaVersion === $other->schemaVersion && $this->generation === $other->generation;
    }
}
