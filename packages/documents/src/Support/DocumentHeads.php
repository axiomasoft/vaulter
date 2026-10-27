<?php

declare(strict_types=1);

namespace Vaulter\Documents\Support;

use Vaulter\Attributes\Internal;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Drive\Contracts\NodeSnapshotContributor;
use Vaulter\Drive\Data\Snapshots\DocumentHeadSnapshot;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\NodeKind;

/**
 * The `documents.heads` contribution to the drive's node snapshots: the `document` fragment of
 * document nodes, one query for the whole page.
 */
#[Internal]
final readonly class DocumentHeads implements NodeSnapshotContributor
{
    public const string KEY = 'document';

    public function __construct(private DocumentTypeRegistry $types) {}

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @param  list<NodeSnapshot>                  $nodes
     * @return array<string, DocumentHeadSnapshot>
     */
    public function contribute(array $nodes): array
    {
        $documents = array_values(array_filter($nodes, static fn (NodeSnapshot $node): bool => $node->kind === NodeKind::Document));
        if ($documents === []) {
            return [];
        }

        $heads = [];
        $rows = Document::query()->whereIn('node_id', array_map(static fn (NodeSnapshot $node): string => $node->id, $documents))->get();
        foreach ($rows as $row) {
            $heads[(string) $row->node_id] = new DocumentHeadSnapshot(
                type: $row->type,
                schemaVersion: $row->schema_version,
                typeAvailable: $this->types->has($row->type),
                headRevisionId: $row->current_revision_id === null ? null : (string) $row->current_revision_id,
                epoch: $row->collab_epoch,
                stateVersion: $row->state_version,
            );
        }

        return $heads;
    }
}
