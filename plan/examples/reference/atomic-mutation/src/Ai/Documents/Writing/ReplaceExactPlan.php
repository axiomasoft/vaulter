<?php

declare(strict_types=1);

namespace VaulterRef\Ai\Documents\Writing;

use InvalidArgumentException;

/**
 * Target: packages/documents/src/Writing/TextPreconditions.php in vaulter-ai. The private plan of
 * text.replace_exact@1, sealed in v_ai_proposal_payloads at prepare. None of it comes from the commit request.
 */
final readonly class ReplaceExactPlan
{
    /** @param list<array<string, mixed>> $marks marks of the target text node, attributes included */
    public function __construct(
        public string $proposalId,
        public string $writer,
        public string $documentType,
        public int $schemaVersion,
        public string $writeMode,
        public int $generation,
        public string $blockId,
        public string $expected,
        public string $replacement,
        public array $marks,
        public string $sourceBlockHash,
        public string $expiresAt,
    ) {
        if ($expected === '' || $blockId === '' || preg_match('/^[0-9a-f]{64}$/', $sourceBlockHash) !== 1) {
            throw new InvalidArgumentException('A replace_exact plan needs a block, a non-empty fragment and a block hash.');
        }
    }

    /** @param array<string, mixed> $plan */
    public static function fromArray(array $plan): self
    {
        return new self(
            proposalId: (string) $plan['proposal_id'],
            writer: (string) $plan['writer'],
            documentType: (string) $plan['document_type'],
            schemaVersion: (int) $plan['schema_version'],
            writeMode: (string) $plan['write_mode'],
            generation: (int) $plan['generation'],
            blockId: (string) $plan['block_id'],
            expected: (string) $plan['expected'],
            replacement: (string) $plan['replacement'],
            marks: array_values((array) $plan['marks']),
            sourceBlockHash: (string) $plan['source_block_hash'],
            expiresAt: (string) $plan['expires_at'],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['proposal_id' => $this->proposalId, 'writer' => $this->writer, 'document_type' => $this->documentType,
            'schema_version' => $this->schemaVersion, 'write_mode' => $this->writeMode, 'generation' => $this->generation,
            'block_id' => $this->blockId, 'expected' => $this->expected, 'replacement' => $this->replacement,
            'marks' => $this->marks, 'source_block_hash' => $this->sourceBlockHash, 'expires_at' => $this->expiresAt];
    }
}
