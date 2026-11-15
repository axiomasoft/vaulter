<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Resources;

use Closure;
use Illuminate\Http\Request;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\Snapshots\DriveSnapshot;
use Vaulter\Drive\Data\Snapshots\GrantSnapshot;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Data\Snapshots\ShareLinkSnapshot;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Operations\AggregateVersion;
use Vaulter\Exceptions\Concurrency\StaleWriteException;
use Vaulter\Http\Middleware\ReadIdempotencyKey;
use Vaulter\Http\Support\Preconditions;
use Vaulter\Http\Support\RepresentationTag;

/**
 * The entity tags of the drive aggregates with a version of their own and what a conditional write of one
 * expects: the access of a node (`grants.index`, its grants and inheritance), a share link, a drive.
 *
 * As for nodes ({@see NodeResponses::expectedSequence()}), the If-Match header is compared only after the resource was
 * found as this caller (404) and the caller was seen to hold the ability the write needs (403); then it is required
 * (428 without it or with `*`) and must equal the current tag (412 stale_write). The version it names is what the
 * operation compares again under the aggregate's row lock, so this check only answers earlier.
 *
 * On the route of a receipt operation a retry under its Idempotency-Key repeats the If-Match of the first request,
 * stale once that one committed (and a revoked grant's node may be gone from the caller's view since): with a key the
 * write gets a version no row holds, and the operation answers from the receipt, or refuses — and stores — the stale
 * write. Without a key the refusal stands.
 */
#[Internal]
final readonly class AggregatePreconditions
{
    public static function accessTag(NodeSnapshot $node): string
    {
        return RepresentationTag::versioned('access', $node->id, $node->accessVersion);
    }

    public static function shareTag(ShareLinkSnapshot $share): string
    {
        return RepresentationTag::versioned('share', $share->id, $share->version);
    }

    public static function driveTag(DriveSnapshot $drive): string
    {
        return RepresentationTag::versioned('drive', $drive->id, $drive->version);
    }

    /**
     * The access version a revoke (of `$grantId`) or an inheritance change of `$nodeId` expects: Manage on the node, the
     * grant on that node (a grant of another node is as unknown as a missing one), then If-Match.
     */
    public function expectedAccessVersion(Request $request, DriveHandle $drive, string $nodeId, ?string $grantId = null): int
    {
        return $this->expected($request, static function () use ($drive, $nodeId, $grantId): array {
            $node = $drive->nodes()->find($nodeId);
            self::requireAbility($drive, $node, Ability::Manage);
            if ($grantId !== null && !in_array($grantId, array_map(static fn (GrantSnapshot $grant): string => $grant->id, $drive->access()->grants($node->id)), true)) {
                throw NodeNotFoundException::forId($node->id);
            }

            return [self::accessTag($node), $node->accessVersion, AggregateVersion::NODE_ACCESS, $node->id];
        });
    }

    /** The version an update, rotation or revocation of the link `$shareId` on `$nodeId` expects: Share on the node, then If-Match. */
    public function expectedShareVersion(Request $request, DriveHandle $drive, string $nodeId, string $shareId): int
    {
        return $this->expected($request, static function () use ($drive, $nodeId, $shareId): array {
            // The listing authorizes Share on the node; a link of another node is as unknown as a missing one.
            foreach ($drive->shares()->list($nodeId) as $share) {
                if ($share->id === $shareId) {
                    return [self::shareTag($share), $share->version, AggregateVersion::SHARE, $share->id];
                }
            }

            throw NodeNotFoundException::forId($nodeId);
        });
    }

    /** The version a lifecycle change of the drive expects: Manage on its root, then If-Match. */
    public function expectedDriveVersion(Request $request, DriveHandle $drive): int
    {
        return $this->expected($request, static function () use ($drive): array {
            self::requireAbility($drive, $drive->root(), Ability::Manage);
            $snapshot = $drive->snapshot();

            return [self::driveTag($snapshot), $snapshot->version, AggregateVersion::DRIVE, $snapshot->id];
        });
    }

    /**
     * @param Closure(): array{0: string, 1: int, 2: string, 3: string} $current the caller's current tag, version, aggregate and id
     */
    private function expected(Request $request, Closure $current): int
    {
        $held = null;
        try {
            [$tag, $held, $aggregate, $id] = $current();
            if (!Preconditions::matches($request, $tag)) {
                throw StaleWriteException::of($aggregate, $id);
            }

            return $held;
        } catch (StaleWriteException|NodeNotFoundException $refusal) {
            if (ReadIdempotencyKey::key($request) === null) {
                throw $refusal;
            }

            return $held === PHP_INT_MAX ? PHP_INT_MAX - 1 : PHP_INT_MAX;
        }
    }

    private static function requireAbility(DriveHandle $drive, NodeSnapshot $node, Ability $ability): void
    {
        $abilities = $drive->nodes()->abilitiesMany([$node])[$node->id] ?? throw NodeNotFoundException::forId($node->id);
        if (!($abilities->flags[$ability->value] ?? false)) {
            throw PermissionDeniedException::forAbility($ability->value);
        }
    }
}
