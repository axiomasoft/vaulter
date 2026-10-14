<?php

declare(strict_types=1);

namespace Vaulter\Drive\Idempotency;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use LogicException;
use Vaulter\Data\Snapshots\DocumentHeadSnapshot;
use Vaulter\Data\Snapshots\FileHeadSnapshot;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Data\Snapshots\ViewerStateSnapshot;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Enums\Drive\ScanStatus;
use Vaulter\Idempotency\ReceiptCodec;
use Vaulter\Idempotency\ResourceRef;

/**
 * The receipt of a write that answers a {@see NodeSnapshot} (folders.create, nodes.rename): the snapshot built from
 * the locked state at commit, field by field, so a replay returns exactly what the first request returned — its
 * ETag is historical and never satisfies a later If-Match by itself. Version 1.
 *
 * @internal
 */
final class NodeSnapshotCodec implements ReceiptCodec
{
    public const string KIND = 'drive.node-snapshot';

    private const string TIME = 'Y-m-d\TH:i:s.uP';

    public function kind(): string
    {
        return self::KIND;
    }

    public function version(): int
    {
        return 1;
    }

    public function encode(mixed $result): array
    {
        $node = self::snapshot($result);

        return [
            'id' => $node->id,
            'drive_id' => $node->driveId,
            'parent_id' => $node->parentId,
            'kind' => $node->kind->value,
            'name' => $node->name,
            'mime_type' => $node->mimeType,
            'extension' => $node->extension,
            'size_bytes' => $node->sizeBytes,
            'sequence' => $node->sequence,
            'inherits_access' => $node->inheritsAccess,
            'properties' => $node->properties,
            'created_at' => self::time($node->createdAt),
            'created_by' => $node->createdBy,
            'updated_at' => self::time($node->updatedAt),
            'updated_by' => $node->updatedBy,
            'content_modified_at' => self::time($node->contentModifiedAt),
            'content_modified_by' => $node->contentModifiedBy,
            'trashed_at' => self::time($node->trashedAt),
            'trashed_by' => $node->trashedBy,
            'trash_root' => $node->trashRoot,
            'file' => $node->file === null ? null : [
                'file_id' => $node->file->fileId,
                'revision_id' => $node->file->revisionId,
                'revision_number' => $node->file->revisionNumber,
                'sha256' => $node->file->sha256,
                'size_bytes' => $node->file->sizeBytes,
                'mime_type' => $node->file->mimeType,
                'scan_status' => $node->file->scanStatus->value,
            ],
            'document' => $node->document === null ? null : [
                'type' => $node->document->type,
                'schema_version' => $node->document->schemaVersion,
                'type_available' => $node->document->typeAvailable,
                'head_revision_id' => $node->document->headRevisionId,
                'epoch' => $node->document->epoch,
                'state_version' => $node->document->stateVersion,
            ],
            'viewer' => $node->viewer === null ? null : ['starred' => $node->viewer->starred],
        ];
    }

    public function decode(array $body, int $version): NodeSnapshot
    {
        if ($version !== 1) {
            throw new LogicException(sprintf('[Vaulter] The receipt codec %s does not know version %d.', self::KIND, $version));
        }
        $file = $body['file'] ?? null;
        $document = $body['document'] ?? null;
        $viewer = $body['viewer'] ?? null;

        return new NodeSnapshot(
            id: (string) $body['id'],
            driveId: (string) $body['drive_id'],
            parentId: self::nullableString($body['parent_id'] ?? null),
            kind: NodeKind::from((string) $body['kind']),
            name: (string) $body['name'],
            mimeType: self::nullableString($body['mime_type'] ?? null),
            extension: self::nullableString($body['extension'] ?? null),
            sizeBytes: (int) $body['size_bytes'],
            sequence: (int) $body['sequence'],
            inheritsAccess: (bool) $body['inherits_access'],
            properties: is_array($body['properties'] ?? null) ? $body['properties'] : [],
            createdAt: self::parse($body['created_at']) ?? throw new LogicException('[Vaulter] A node receipt has no created_at.'),
            createdBy: self::nullableString($body['created_by'] ?? null),
            updatedAt: self::parse($body['updated_at']) ?? throw new LogicException('[Vaulter] A node receipt has no updated_at.'),
            updatedBy: self::nullableString($body['updated_by'] ?? null),
            contentModifiedAt: self::parse($body['content_modified_at'] ?? null),
            contentModifiedBy: self::nullableString($body['content_modified_by'] ?? null),
            trashedAt: self::parse($body['trashed_at'] ?? null),
            trashedBy: self::nullableString($body['trashed_by'] ?? null),
            trashRoot: (bool) $body['trash_root'],
            file: is_array($file) ? new FileHeadSnapshot(
                fileId: (string) $file['file_id'],
                revisionId: (string) $file['revision_id'],
                revisionNumber: (int) $file['revision_number'],
                sha256: (string) $file['sha256'],
                sizeBytes: (int) $file['size_bytes'],
                mimeType: self::nullableString($file['mime_type'] ?? null),
                scanStatus: ScanStatus::from((string) $file['scan_status']),
            ) : null,
            document: is_array($document) ? new DocumentHeadSnapshot(
                type: (string) $document['type'],
                schemaVersion: (int) $document['schema_version'],
                typeAvailable: (bool) $document['type_available'],
                headRevisionId: self::nullableString($document['head_revision_id'] ?? null),
                epoch: (int) $document['epoch'],
                stateVersion: (int) $document['state_version'],
            ) : null,
            viewer: is_array($viewer) ? new ViewerStateSnapshot(starred: (bool) $viewer['starred']) : null,
        );
    }

    public function resource(mixed $result): ResourceRef
    {
        return new ResourceRef('node', self::snapshot($result)->id);
    }

    private static function snapshot(mixed $result): NodeSnapshot
    {
        return $result instanceof NodeSnapshot ? $result : throw new InvalidArgumentException(sprintf('[Vaulter] %s encodes a NodeSnapshot, %s given.', self::KIND, get_debug_type($result)));
    }

    private static function time(?DateTimeInterface $time): ?string
    {
        return $time?->format(self::TIME);
    }

    private static function parse(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return DateTimeImmutable::createFromFormat(self::TIME, $value) ?: throw new LogicException('[Vaulter] A node receipt holds a malformed time.');
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
