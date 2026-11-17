<?php

declare(strict_types=1);

namespace Vaulter\Drive\Idempotency;

use InvalidArgumentException;
use Packman\Attributes\Internal;
use Vaulter\Drive\Data\Snapshots\DriveSnapshot;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Idempotency\ReceiptCodec;
use Vaulter\Idempotency\ResourceRef;

/**
 * The receipt of a drive lifecycle write (drive.drives.archive|unarchive|delete): the {@see DriveSnapshot} built from
 * the locked row at commit, field by field — its version is historical and never satisfies a later If-Match by itself.
 * Version 1.
 */
#[Internal]
final class DriveSnapshotCodec implements ReceiptCodec
{
    public const string KIND = 'drive.drive-snapshot';

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
        $drive = self::snapshot($result);

        return [
            'id' => $drive->id,
            'owner_type' => $drive->ownerType,
            'owner_id' => $drive->ownerId,
            'profile' => $drive->profile,
            'name' => $drive->name,
            'status' => $drive->status->value,
            'tenant_id' => $drive->tenantId,
            'created_at' => ReceiptFields::time($drive->createdAt),
            'version' => $drive->version,
        ];
    }

    public function decode(array $body, int $version): DriveSnapshot
    {
        ReceiptFields::assertVersion($body, $version, self::KIND);

        return new DriveSnapshot(
            id: ReceiptFields::text($body, 'id', 'drive'),
            ownerType: ReceiptFields::text($body, 'owner_type', 'drive'),
            ownerId: ReceiptFields::text($body, 'owner_id', 'drive'),
            profile: ReceiptFields::text($body, 'profile', 'drive'),
            name: ReceiptFields::text($body, 'name', 'drive'),
            status: DriveStatus::from(ReceiptFields::text($body, 'status', 'drive')),
            tenantId: ReceiptFields::nullableText($body, 'tenant_id'),
            createdAt: ReceiptFields::requiredTime($body, 'created_at', 'drive'),
            version: ReceiptFields::int($body, 'version', 'drive'),
        );
    }

    public function resource(mixed $result): ResourceRef
    {
        return new ResourceRef('drive', self::snapshot($result)->id);
    }

    private static function snapshot(mixed $result): DriveSnapshot
    {
        return $result instanceof DriveSnapshot ? $result : throw new InvalidArgumentException(sprintf('[Vaulter] %s encodes a DriveSnapshot, %s given.', self::KIND, get_debug_type($result)));
    }
}
