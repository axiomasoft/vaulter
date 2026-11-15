<?php

declare(strict_types=1);

namespace Vaulter\Drive\Idempotency;

use Vaulter\Attributes\Internal;

/**
 * What a revocation keeps in its receipt (a delete receipt carries the provable original authorization
 * scope): the id of the revoked grant or share link and the node the right to revoke it was proven on, never
 * its content. A replay checks the recorded ability on that node, because the grant itself is gone.
 *
 * `$kind` is `grant` (drive.access.revoke) or `share` (drive.shares.revoke).
 */
#[Internal]
final readonly class RevokeReceipt
{
    public const string GRANT = 'grant';

    public const string SHARE = 'share';

    public function __construct(
        public string $kind,
        public string $id,
        public string $driveId,
        public string $nodeId,
        public string $ability,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'id' => $this->id, 'drive_id' => $this->driveId, 'node_id' => $this->nodeId, 'ability' => $this->ability];
    }

    /** @param array<array-key, mixed> $body */
    public static function fromArray(array $body, string $receipt): self
    {
        return new self(
            kind: ReceiptFields::text($body, 'kind', $receipt),
            id: ReceiptFields::text($body, 'id', $receipt),
            driveId: ReceiptFields::text($body, 'drive_id', $receipt),
            nodeId: ReceiptFields::text($body, 'node_id', $receipt),
            ability: ReceiptFields::text($body, 'ability', $receipt),
        );
    }
}
