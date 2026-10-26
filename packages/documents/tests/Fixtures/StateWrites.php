<?php

declare(strict_types=1);

namespace Vaulter\Documents\Tests\Fixtures;

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Content\DocumentStateWriter;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Data\Documents\DocumentStateChange;
use Vaulter\Documents\Data\Documents\DocumentStateResult;
use Vaulter\Documents\Enums\Documents\StateWriteCause;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Drive\Models\Node;

/**
 * Writes the binary (Yjs) working state of a document the way a collaboration store does, but straight through the
 * documents' state writer: the aggregate tests exercise the validators, the checkpoints and the head, not the hub.
 */
final class StateWrites
{
    /** @param array<string, mixed>|null $content the JSON projection that goes with the state; null keeps the current one */
    public static function yjs(Node $node, string $collabState, ?array $content = null, bool $seed = false, int|string|null $actorId = null): DocumentStateResult
    {
        return app(VaulterDatabase::class)->transaction(static function () use ($node, $collabState, $content, $seed, $actorId): DocumentStateResult {
            $document = Document::query()->where('node_id', $node->getKey())->lockForUpdate()->firstOrFail();
            $document->setRelation('node', $node);

            return app(DocumentStateWriter::class)->write($document, new DocumentStateChange(
                cause: $seed ? StateWriteCause::Seed : StateWriteCause::Collaboration,
                content: $content === null ? null : TipTapContent::fromArray($content),
                schemaVersion: $document->schema_version,
                collabState: $collabState,
                actorId: $actorId,
            ));
        });
    }
}
