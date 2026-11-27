<?php

declare(strict_types=1);

namespace Vaulter\Documents\State;

use InvalidArgumentException;
use Packman\Attributes\Api;

/** The identity used to fence writes to a persisted generation. */
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

    /** A Yjs checkpoint may move from V1 to V2 without changing the generation's journal protocol. */
    public static function acceptsFormat(string $protocol, string $expected, ?string $actual): bool
    {
        return $expected === $actual
            || ($protocol === 'yjs' && $expected === 'yjs-update-v1' && $actual === 'yjs-update-v2');
    }
}
