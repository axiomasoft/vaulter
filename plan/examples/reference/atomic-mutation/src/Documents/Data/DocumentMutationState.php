<?php

declare(strict_types=1);

namespace VaulterRef\Documents\Data;

use VaulterRef\Documents\Exceptions\DocumentMutationException;

/**
 * Target: packages/documents/src/Data/Documents/DocumentMutationState.php.
 * generation = v_documents.collab_epoch, position = v_documents.state_version. documentId is the internal
 * v_documents.id resolved from the authorized node; an adapter needs it for its own FK in the same transaction.
 */
final readonly class DocumentMutationState
{
    public const MAX_WIRE_COUNTER = 9007199254740991;

    /** @param array<string, mixed> $content */
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
        public array $content,
        public string $contentHash,
    ) {
        if ($generation < 0 || $position < 0 || $generation > self::MAX_WIRE_COUNTER || $position > self::MAX_WIRE_COUNTER) {
            throw DocumentMutationException::counterOverflow();
        }
        if (!in_array($writeMode, ['snapshot', 'journal'], true)) {
            throw DocumentMutationException::invalidContent('unknown write_mode');
        }
    }

    /** @param array<string, mixed> $content */
    public function withWrite(int $position, array $content, string $contentHash): self
    {
        return new self($this->nodeId, $this->documentId, $this->type, $this->schemaVersion, $this->generation,
            $position, $this->writeMode, $this->protocol, $this->pending, $content, $contentHash);
    }
}
