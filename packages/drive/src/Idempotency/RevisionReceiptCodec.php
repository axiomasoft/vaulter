<?php

declare(strict_types=1);

namespace Vaulter\Drive\Idempotency;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Data\Snapshots\FileRevisionSnapshot;
use Vaulter\Drive\Enums\ScanStatus;
use Vaulter\Idempotency\ReceiptCodec;
use Vaulter\Idempotency\ResourceRef;

/**
 * The receipt of a write that answers a {@see FileRevisionSnapshot} (files.restore_revision): the snapshot built at
 * commit, field by field, so a replay returns what the first request returned. Version 1.
 */
#[Internal]
final class RevisionReceiptCodec implements ReceiptCodec
{
    public const string KIND = 'drive.revision-snapshot';

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
        $revision = self::snapshot($result);

        return [
            'id' => $revision->id,
            'file_node_id' => $revision->fileNodeId,
            'number' => $revision->number,
            'size_bytes' => $revision->sizeBytes,
            'sha256' => $revision->sha256,
            'mime_type' => $revision->mimeType,
            'declared_mime_type' => $revision->declaredMimeType,
            'scan_status' => $revision->scanStatus->value,
            'keep_forever' => $revision->keepForever,
            'is_head' => $revision->isHead,
            'restored_from_revision_id' => $revision->restoredFromRevisionId,
            'client_modified_at' => $revision->clientModifiedAt?->format(self::TIME),
            'created_at' => $revision->createdAt->format(self::TIME),
            'created_by' => $revision->createdBy,
        ];
    }

    public function decode(array $body, int $version): FileRevisionSnapshot
    {
        if ($version !== 1) {
            throw new LogicException(sprintf('[Vaulter] The receipt codec %s does not know version %d.', self::KIND, $version));
        }

        return new FileRevisionSnapshot(
            id: self::text($body, 'id'),
            fileNodeId: self::text($body, 'file_node_id'),
            number: self::int($body, 'number'),
            sizeBytes: self::int($body, 'size_bytes'),
            sha256: self::text($body, 'sha256'),
            mimeType: self::nullable($body['mime_type'] ?? null),
            declaredMimeType: self::nullable($body['declared_mime_type'] ?? null),
            scanStatus: ScanStatus::from(self::text($body, 'scan_status')),
            keepForever: ($body['keep_forever'] ?? false) === true,
            isHead: ($body['is_head'] ?? false) === true,
            restoredFromRevisionId: self::nullable($body['restored_from_revision_id'] ?? null),
            clientModifiedAt: self::time($body['client_modified_at'] ?? null),
            createdAt: self::time($body['created_at'] ?? null) ?? throw new LogicException('[Vaulter] A revision receipt has no created_at.'),
            createdBy: self::nullable($body['created_by'] ?? null),
        );
    }

    public function resource(mixed $result): ResourceRef
    {
        return new ResourceRef('node', self::snapshot($result)->fileNodeId);
    }

    private static function snapshot(mixed $result): FileRevisionSnapshot
    {
        return $result instanceof FileRevisionSnapshot ? $result : throw new InvalidArgumentException(sprintf('[Vaulter] %s encodes a FileRevisionSnapshot, %s given.', self::KIND, get_debug_type($result)));
    }

    private static function time(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return DateTimeImmutable::createFromFormat(self::TIME, $value) ?: throw new LogicException('[Vaulter] A revision receipt holds a malformed time.');
    }

    private static function nullable(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /** @param array<string, mixed> $body */
    private static function text(array $body, string $key): string
    {
        $value = $body[$key] ?? null;

        return is_scalar($value) ? (string) $value : throw new LogicException(sprintf('[Vaulter] A revision receipt has no "%s".', $key));
    }

    /** @param array<string, mixed> $body */
    private static function int(array $body, string $key): int
    {
        $value = $body[$key] ?? null;

        return is_numeric($value) ? (int) $value : throw new LogicException(sprintf('[Vaulter] A revision receipt has no number "%s".', $key));
    }
}
