<?php

declare(strict_types=1);

namespace Vaulter\Collaboration\Persistence;

use InvalidArgumentException;
use Vaulter\Attributes\Internal;

/**
 * One Yjs update as protocol 2 carries it: the generation it was made in, the stable id its origin gave it (repeated
 * unchanged on every retry), the decoded binary payload and the sha256 the origin claims for it. The claimed hash is
 * checked against the payload, never trusted.
 */
#[Internal]
final readonly class UpdateEnvelope
{
    public function __construct(
        public int $generation,
        public string $updateId,
        public string $payload,
        public string $claimedSha256,
        public string $origin = 'client',
    ) {
        if ($generation < 0) {
            throw new InvalidArgumentException('[Vaulter] An update names a generation of at least 0.');
        }
        if ($payload === '') {
            throw new InvalidArgumentException('[Vaulter] An update carries a payload.');
        }
    }

    /** The hash of the payload, computed here. */
    public function sha256(): string
    {
        return hash('sha256', $this->payload);
    }
}
