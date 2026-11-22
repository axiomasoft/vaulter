<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Documents;

use InvalidArgumentException;
use OverflowException;
use Packman\Attributes\Api;
use Vaulter\Documents\Contracts\DocumentContent;

#[Api]
final readonly class DocumentMutationState
{
    public const int MAX_WIRE_COUNTER = 9_007_199_254_740_991;

    public function __construct(
        public string $nodeId,
        public string $documentId,
        public string $type,
        public int $schemaVersion,
        public int $generation,
        public int $position,
        public string $writeMode,
        public ?string $protocol,
        public bool $pending,
        public DocumentContent $content,
    ) {
        if ($generation < 0 || $position < 0 || $generation > self::MAX_WIRE_COUNTER || $position > self::MAX_WIRE_COUNTER) {
            throw new OverflowException('[Vaulter] The document generation or position is outside the safe wire range.');
        }
        if ($writeMode !== 'snapshot' && $writeMode !== 'journal') {
            throw new InvalidArgumentException('[Vaulter] Unknown document write mode.');
        }
    }
}
