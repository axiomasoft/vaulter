<?php

declare(strict_types=1);

namespace Vaulter\Documents\Support;

use Vaulter\Contracts\Drive\NodeDocumentHeads;
use Vaulter\Data\Snapshots\DocumentHeadSnapshot;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Models\Drive\Node;

/**
 * Supplies NodeSnapshot::$document for document nodes: one query for the whole page.
 *
 * @internal
 */
final readonly class DocumentHeads implements NodeDocumentHeads
{
    public function __construct(private DocumentsConfig $config) {}

    /**
     * @param  list<Node>                          $documents
     * @return array<string, DocumentHeadSnapshot>
     */
    public function heads(array $documents): array
    {
        $enabled = $this->config->enabledDocumentTypes();
        $heads = [];
        $rows = Document::query()->whereIn('node_id', array_map(static fn (Node $node): int|string => $node->id, $documents))->get();
        foreach ($rows as $row) {
            $heads[(string) $row->node_id] = new DocumentHeadSnapshot(
                type: $row->type,
                schemaVersion: $row->schema_version,
                typeAvailable: $enabled === [] || in_array($row->type, $enabled, true),
                headRevisionId: $row->current_revision_id === null ? null : (string) $row->current_revision_id,
                epoch: $row->collab_epoch,
                stateVersion: $row->state_version,
            );
        }

        return $heads;
    }
}
