<?php

declare(strict_types=1);

namespace Vaulter\Actions\Sharing;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Vaulter\Configuration\SharingConfig;
use Vaulter\Configuration\TenancyConfig;
use Vaulter\Data\Sharing\UpdateShareLinkInput;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Enums\Sharing\ShareAudience;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Sharing\ShareRoleNotAllowedException;
use Vaulter\Models\Sharing\NodeShare;
use Vaulter\Operations\NodeLocator;
use Vaulter\Operations\OperationContext;
use Vaulter\Repositories\Sharing\NodeShareStoreRepository;
use Vaulter\Sharing\ShareLinkRules;

/**
 * Change a live share link without touching its token (PLAN2.D47): expiry, use
 * budget, password and audience. The link row is locked after the node, so a
 * concurrent revoke or rotate and this update serialize. The new expiry obeys the
 * same bounds as at creation.
 *
 * @internal
 */
final readonly class UpdateShareLinkAction
{
    public function __construct(
        private SharingConfig $sharingConfig,
        private NodeShareStoreRepository $shares,
        private VaulterDatabase $database,
        private NodeLocator $locator,
        private ShareLinkRules $rules,
        private TenancyConfig $tenancy,
    ) {}

    public function execute(OperationContext $context, UpdateShareLinkInput $input): NodeShare
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

            $attributes = [];
            if ($changes->audience !== null && $changes->audience !== $share->audience) {
                if (!$this->rules->allowed($share->role_key, $changes->audience)) {
                    throw ShareRoleNotAllowedException::for($share->role_key, $changes->audience);
                }
                $attributes['audience'] = $changes->audience;
            }
            if ($changes->expiresAt !== null) {
                $attributes['expires_at'] = $changes->expiresAt;
            }
            if ($changes->removeMaxUses) {
                $attributes['max_uses'] = null;
            } elseif ($changes->maxUses !== null) {
                $attributes['max_uses'] = $changes->maxUses;
            }
            if ($changes->removePassword) {
                $attributes['password_hash'] = null;
            } elseif ($changes->password !== null && $changes->password !== '') {
                $attributes['password_hash'] = Hash::make($changes->password);
            }

            return $this->shares->update($share, $attributes);
        });
    }
}
