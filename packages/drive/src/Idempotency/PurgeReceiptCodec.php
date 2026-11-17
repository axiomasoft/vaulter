<?php

declare(strict_types=1);

namespace Vaulter\Drive\Idempotency;

use InvalidArgumentException;
use LogicException;
use Packman\Attributes\Internal;
use Vaulter\Idempotency\ReceiptCodec;
use Vaulter\Idempotency\ResourceRef;

/**
 * The receipt of a permanent removal (nodes.purge, files.delete_revision, trash.empty): ids and the authorization
 * scope only — a destructive receipt keeps no content. Version 1.
 */
#[Internal]
final class PurgeReceiptCodec implements ReceiptCodec
{
    public const string KIND = 'drive.purge-receipt';

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
        $receipt = self::receipt($result);

        return [
            'kind' => $receipt->kind,
            'id' => $receipt->id,
            'drive_id' => $receipt->driveId,
            'scope_node_id' => $receipt->scopeNodeId,
            'ability' => $receipt->ability,
            'count' => $receipt->count,
        ];
    }

    public function decode(array $body, int $version): PurgeReceipt
    {
        if ($version !== 1) {
            throw new LogicException(sprintf('[Vaulter] The receipt codec %s does not know version %d.', self::KIND, $version));
        }
        $scope = $body['scope_node_id'] ?? null;
        $count = $body['count'] ?? null;

        return new PurgeReceipt(
            kind: self::text($body, 'kind'),
            id: self::text($body, 'id'),
            driveId: self::text($body, 'drive_id'),
            scopeNodeId: is_scalar($scope) ? (string) $scope : null,
            ability: self::text($body, 'ability'),
            count: is_numeric($count) ? (int) $count : 1,
        );
    }

    /** A removed node or trash is not a resource any more; a deleted revision is about the file node that still exists. */
    public function resource(mixed $result): ?ResourceRef
    {
        $receipt = self::receipt($result);

        return $receipt->kind === PurgeReceipt::REVISION && $receipt->scopeNodeId !== null ? new ResourceRef('node', $receipt->scopeNodeId) : null;
    }

    private static function receipt(mixed $result): PurgeReceipt
    {
        return $result instanceof PurgeReceipt ? $result : throw new InvalidArgumentException(sprintf('[Vaulter] %s encodes a PurgeReceipt, %s given.', self::KIND, get_debug_type($result)));
    }

    /** @param array<string, mixed> $body */
    private static function text(array $body, string $key): string
    {
        $value = $body[$key] ?? null;

        return is_scalar($value) ? (string) $value : throw new LogicException(sprintf('[Vaulter] A purge receipt has no "%s".', $key));
    }
}
