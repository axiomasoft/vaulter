<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Resources\Drive;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;
use Packman\Attributes\Internal;
use stdClass;
use Vaulter\Data\Snapshots\Mapping\SnapshotValue;
use Vaulter\Drive\Access\ValueObjects\NodeAbilities;
use Vaulter\Drive\Contracts\Handlers\NodePresenter;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\ValueObjects\Handlers\NodePresentation;
use Vaulter\Http\Support\RepresentationTag;

/**
 * The versioned node representation. It is built only from what the operations
 * returned — the snapshot and the caller's abilities, computed by the controller in
 * one batch for a whole page — and never asks the access layer itself.
 *
 * Internal pointers (file id, blob, storage key, tenant) are not part of it. The etag
 * is the caller's representation tag: it changes whenever anything below changes for
 * this caller, its abilities, starred flag and reader-scoped sync key included.
 */
#[Internal]
final class NodeResource extends JsonResource
{
    /** @var array<string, mixed> */
    private readonly array $representation;

    private readonly string $etag;

    public function __construct(NodeSnapshot $node, private readonly NodeAbilities $abilities, ?NodePresentation $presentation = null)
    {
        parent::__construct($node);
        $base = $this->base($node, $presentation);
        $this->etag = RepresentationTag::node($base, $node->id, $node->sequence);
        // The tag goes right after `sequence`; it hashes everything else, the reader-scoped sync key included.
        $this->representation = array_slice($base, 0, 9) + ['etag' => $this->etag] + $base;
    }

    /**
     * @param  list<NodeSnapshot>           $nodes
     * @param  array<string, NodeAbilities> $abilities node id => abilities, from NodeOperations::abilitiesMany()
     * @return list<self>                   the nodes the abilities cover, in order
     */
    public static function many(array $nodes, array $abilities): array
    {
        $resources = [];
        foreach ($nodes as $node) {
            if (isset($abilities[$node->id])) {
                $resources[] = new self($node, $abilities[$node->id]);
            }
        }

        return $resources;
    }

    /** The ETag header value: the same tag the body carries. */
    public function etag(): string
    {
        return $this->etag;
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        return $this->representation;
    }

    /** @return array<string, mixed> */
    private function base(NodeSnapshot $node, ?NodePresentation $presentation): array
    {
        return [
            'id' => $node->id,
            'drive_id' => $node->driveId,
            'parent_id' => $node->parentId,
            'kind' => $node->kind->value,
            'name' => $node->name,
            'mime' => $node->mimeType,
            'extension' => $node->extension,
            'size_bytes' => $node->sizeBytes,
            'sequence' => $node->sequence,
            'inherits_access' => $node->inheritsAccess,
            'properties' => self::properties($node->properties),
            'created_at' => SnapshotValue::iso($node->createdAt),
            'created_by' => $node->createdBy,
            'updated_at' => SnapshotValue::iso($node->updatedAt),
            'updated_by' => $node->updatedBy,
            'content_modified_at' => SnapshotValue::iso($node->contentModifiedAt),
            'content_modified_by' => $node->contentModifiedBy,
            'trashed_at' => SnapshotValue::iso($node->trashedAt),
            'trashed_by' => $node->trashedBy,
            'trash_root' => $node->trashRoot,
            'presentation' => self::presentation($node, $presentation),
            'abilities' => $this->abilities->toArray(),
            'viewer' => ['starred' => $node->viewer->starred ?? false],
            'scan_status' => $node->file?->scanStatus->value ?? 'not_required',
            'file' => $node->file === null ? null : [
                'revision_id' => $node->file->revisionId,
                'revision_number' => $node->file->revisionNumber,
                'size_bytes' => $node->file->sizeBytes,
                'sha256' => $node->file->sha256,
                'mime' => $node->file->mimeType,
                'scan_status' => $node->file->scanStatus->value,
            ],
            'document' => $node->document === null ? null : [
                'type' => $node->document->type,
                'schema_version' => $node->document->schemaVersion,
                'state_version' => $node->document->stateVersion,
                'collab_epoch' => $node->document->epoch,
                'revision_id' => $node->document->headRevisionId,
            ],
            // Reader-scoped: another reader, or the same reader after a key generation bump, gets another tag.
            'sync_key' => $node->syncKey,
        ];
    }

    /** @return array<string, mixed>|null */
    private static function presentation(NodeSnapshot $node, ?NodePresentation $presentation): ?array
    {
        if ($node->kind === NodeKind::Folder) {
            return null;
        }
        $presentation ??= app(NodePresenter::class)->present($node);

        return [
            // Documents are routed by document_type, never by a file handler.
            'handler' => $node->kind === NodeKind::Document ? null : $presentation->id,
            'document_type' => $presentation->documentType,
            'type_available' => $presentation->typeAvailable,
            'viewer' => ['key' => $presentation->ui->key, 'props' => self::object($presentation->ui->props)],
            'capabilities' => $presentation->capabilities->toArray(),
        ];
    }

    /**
     * Always a JSON object, even when empty or keyed by digits.
     *
     * @param array<string, array<string, string>> $properties
     */
    private static function properties(array $properties): stdClass
    {
        $object = new stdClass;
        foreach ($properties as $namespace => $values) {
            $object->{$namespace} = self::object($values);
        }

        return $object;
    }

    /** @param array<array-key, mixed> $values */
    private static function object(array $values): stdClass
    {
        $object = new stdClass;
        foreach ($values as $key => $value) {
            $object->{$key} = $value;
        }

        return $object;
    }
}
