<?php

declare(strict_types=1);

namespace Vaulter\Drive\Contracts\Sharing;

use Vaulter\Attributes\Api;
use Vaulter\Drive\Data\Sharing\ShareLinkInput;
use Vaulter\Drive\Data\Sharing\ShareLinkUpdate;
use Vaulter\Drive\Data\Snapshots\MintedShareLink;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Data\Snapshots\ShareLinkSnapshot;
use Vaulter\Exceptions\Concurrency\StaleWriteException;

#[Api]
interface ShareOperations
{
    /** @return list<ShareLinkSnapshot> */
    public function list(NodeSnapshot|string|int $node): array;

    public function create(NodeSnapshot|string|int $node, ShareLinkInput $input): MintedShareLink;

    /**
     * Change expiry, use budget, password or audience of a live link. The token and
     * the role stay; use rotate() for a new token. `$expectedVersion` — the link's
     * version the caller read ({@see ShareLinkSnapshot::$version}) — is compared
     * atomically with the write; a mismatch throws {@see StaleWriteException}. Null
     * skips the check.
     */
    public function update(NodeSnapshot|string|int $node, ShareLinkSnapshot|string|int $share, ShareLinkUpdate $changes, ?int $expectedVersion = null): ShareLinkSnapshot;

    /**
     * Replace the link's token. The old token stops working immediately; the new one
     * is in the result and nowhere else. `$expectedVersion` as for {@see update()}.
     */
    public function rotate(NodeSnapshot|string|int $node, ShareLinkSnapshot|string|int $share, ?int $expectedVersion = null): MintedShareLink;

    /** Revoke the link. `$expectedVersion` as for {@see update()}. */
    public function revoke(NodeSnapshot|string|int $node, ShareLinkSnapshot|string|int $share, ?int $expectedVersion = null): void;
}
