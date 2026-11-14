<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Sharing;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\TenancyConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Configuration\SharingConfig;
use Vaulter\Drive\Data\Sharing\UpdateShareLinkInput;
use Vaulter\Drive\Data\Snapshots\Mapping\AccessSnapshotMapper;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Enums\Sharing\ShareAudience;
use Vaulter\Drive\Events\Access\EffectivePermissionsChanged;
use Vaulter\Drive\Events\Sharing\NodeShareUpdated;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Sharing\ShareRoleNotAllowedException;
use Vaulter\Drive\Models\Sharing\NodeShare;
use Vaulter\Drive\Operations\AggregateVersion;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Operations\NodeLocator;
use Vaulter\Drive\Repositories\Sharing\NodeShareStoreRepository;
use Vaulter\Drive\Sharing\ShareLinkRules;
use Vaulter\Events\EventRecorder;

/**
 * Change a live share link without touching its token: expiry, use
 * budget, password and audience. The link row is locked after the node, so a
 * concurrent revoke or rotate and this update serialize; the expected version is
 * compared under that lock and a change raises it (CC1), so of two writers that
 * read the same version one gets stale_write. The new expiry obeys the same bounds
 * as at creation.
 */
#[Internal]
final readonly class UpdateShareLinkAction
{
    public function __construct(
        private SharingConfig $sharingConfig,
        private NodeShareStoreRepository $shares,
        private VaulterDatabase $database,
        private NodeLocator $locator,
        private ShareLinkRules $rules,
        private TenancyConfig $tenancy,
        private EventRecorder $recorder,
        private AggregateVersion $versions,
        private AccessSnapshotMapper $accessSnapshots,
    ) {}

    public function execute(DriveContext $context, UpdateShareLinkInput $input): NodeShare
    {
        $changes = $input->changes;
        $now = Carbon::now();
        if ($changes->maxUses !== null && $changes->maxUses < 1) {
            throw new InvalidArgumentException('A share use budget must be positive.');
        }
        if ($changes->password !== null && strlen($changes->password) > 255) {
            throw new InvalidArgumentException('Share password is too long.');
        }
        if ($changes->expiresAt !== null) {
            $this->rules->assertExpiry($context->drive, $changes->expiresAt, $now);
            if ($changes->expiresAt->lte($now) || $changes->expiresAt->gt($now->copy()->addHours($this->sharingConfig->maxTtlHours()))) {
                throw new InvalidArgumentException('Share expiry must be within the configured TTL.');
            }
        }
        if ($changes->audience === ShareAudience::Tenant && !$this->tenancy->enabled()) {
            throw new InvalidArgumentException('The tenant audience needs tenancy to be enabled.');
        }

        return $this->database->transaction(function () use ($context, $input, $changes): NodeShare {
            $node = $this->locator->find(context: $context, key: $input->node);
            $share = $input->share->newQuery()->where('node_id', $node->getKey())
                ->whereKey($input->share->getRawOriginal($input->share->getKeyName()))->whereNull('revoked_at')->lockForUpdate()->first();
            if (!$share instanceof NodeShare) {
                throw NodeNotFoundException::forId($node->id);
            }
            $this->versions->assertExpected($share, 'version', $input->expectedVersion, AggregateVersion::SHARE);

            $attributes = [];
            if ($changes->audience !== null && $changes->audience !== $share->audience) {
                if (!$this->rules->allowed($share->role_key, $changes->audience)) {
                    throw ShareRoleNotAllowedException::for($share->role_key, $changes->audience);
                }
                $attributes['audience'] = $changes->audience;
            }
            if ($changes->expiresAt !== null && $share->expires_at?->equalTo($changes->expiresAt) !== true) {
                $attributes['expires_at'] = $changes->expiresAt;
            }
            if ($changes->removeMaxUses) {
                if ($share->max_uses !== null) {
                    $attributes['max_uses'] = null;
                }
            } elseif ($changes->maxUses !== null && $share->max_uses !== $changes->maxUses) {
                $attributes['max_uses'] = $changes->maxUses;
            }
            if ($changes->removePassword) {
                if ($share->password_hash !== null) {
                    $attributes['password_hash'] = null;
                }
            } elseif ($changes->password !== null && $changes->password !== '') {
                $attributes['password_hash'] = Hash::make($changes->password);
            }

            $updated = $this->shares->update($share, $attributes);
            if ($attributes !== []) {
                $this->versions->bump($updated, 'version', AggregateVersion::SHARE);
                // Field names only: the new password must never leave the row.
                $changed = array_map(static fn (string $column): string => $column === 'password_hash' ? 'password' : $column, array_keys($attributes));
                $this->recorder->record(new NodeShareUpdated(accessSnapshots: $this->accessSnapshots, share: $updated, changed: $changed, actor: $context->actor->ref(), correlationId: $context->correlationId));
                $this->recorder->record(EffectivePermissionsChanged::forNode(node: $node, reason: DriveEventType::ShareUpdated->value, actor: $context->actor->ref(), correlationId: $context->correlationId));
            }

            return $updated;
        });
    }
}
