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
use Vaulter\Drive\Data\Sharing\CreateShareLinkInput;
use Vaulter\Drive\Data\Sharing\MintedShareLink;
use Vaulter\Drive\Enums\Sharing\ShareAudience;
use Vaulter\Drive\Events\Sharing\NodeShareCreated;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Operations\NodeLocator;
use Vaulter\Drive\Repositories\Sharing\NodeShareStoreRepository;
use Vaulter\Drive\Sharing\ShareLinkRules;
use Vaulter\Drive\Sharing\ShareTokenMinter;
use Vaulter\Events\EventRecorder;

/**
 * Mint a bearer share-link on a node — the SEPARATE public sharing
 * channel. The raw token is generated here, returned ONCE via the result and NEVER
 * persisted: only its sha256 hash (token_hash, UNIQUE) and a short token_prefix are
 * stored. Any password is hashed before it touches the DB. The write runs in a
 * transaction and emits NodeShareCreated.
 *
 * The role and audience obey {@see ShareLinkRules}, and the expiry may not pass the
 * drive profile's maxShareLinkTtlDays. A link is never minted immortal by omission
 * without an `expiresAt` the Action stamps
 * `now() + sharingConfig->defaultTtlHours()` (cut back to the profile limit) at mint
 * time, NOT a DB column default, so it stays host-config-overridable. An explicit
 * `expiresAt` above `sharingConfig->maxTtlHours()` is rejected before minting.
 *
 * Authorization (the actor may Share the node) is enforced by the caller before
 * reaching the Action — the Action mints + persists, it does not re-authorize.
 */
#[Internal]
final readonly class CreateShareLinkAction
{
    public function __construct(
        private SharingConfig $sharingConfig,
        private ShareTokenMinter $minter,
        private NodeShareStoreRepository $shares,
        private VaulterDatabase $database,
        private NodeLocator $locator,
        private ShareLinkRules $rules,
        private TenancyConfig $tenancy,
        private EventRecorder $recorder,
    ) {}

    public function execute(DriveContext $context, CreateShareLinkInput $input): MintedShareLink
    {
        $node = $this->locator->find(context: $context, key: $input->node);
        $data = $input->link;
        $now = Carbon::now();

        $role = $this->rules->roleFor($data->role, $data->audience);
        $this->assertTenantAudience($data->audience);
        if ($data->maxUses !== null && $data->maxUses < 1) {
            throw new InvalidArgumentException('A share use budget must be positive.');
        }
        if ($data->password !== null && strlen($data->password) > 255) {
            throw new InvalidArgumentException('Share password is too long.');
        }
        if ($data->expiresAt !== null) {
            $this->rules->assertExpiry($context->drive, $data->expiresAt, $now);
            $this->assertWithinConfiguredTtl($data->expiresAt, $now);
        }
        $passwordHash = $data->password === null || $data->password === '' ? null : Hash::make($data->password);

        // an omitted expiry defaults to the configured TTL, stamped at
        // mint time (not a DB default) so it stays host-overridable.
        $expiresAt = $data->expiresAt ?? $this->defaultExpiry($context->drive, $now);

        return $this->database->transaction(function () use ($context, $node, $data, $role, $passwordHash, $expiresAt): MintedShareLink {
            [$share, $token] = $this->minter->mint(fn (string $tokenHash, string $tokenPrefix) => $this->shares->create(
                node: $node,
                tokenHash: $tokenHash,
                tokenPrefix: $tokenPrefix,
                role: $role,
                audience: $data->audience,
                scope: $data->scope->value,
                passwordHash: $passwordHash,
                expiresAt: $expiresAt,
                maxUses: $data->maxUses,
                grantedBy: $context->actor->ref()->id,
            ));
            $minted = new MintedShareLink(share: $share, token: $token);

            $this->recorder->record(new NodeShareCreated(share: $minted->share, actor: $context->actor->ref(), correlationId: $context->correlationId));

            return $minted;
        });
    }

    private function assertTenantAudience(ShareAudience $audience): void
    {
        if ($audience === ShareAudience::Tenant && !$this->tenancy->enabled()) {
            throw new InvalidArgumentException('The tenant audience needs tenancy to be enabled.');
        }
    }

    private function assertWithinConfiguredTtl(Carbon $expiresAt, Carbon $now): void
    {
        if ($expiresAt->lte($now) || $expiresAt->gt($now->copy()->addHours($this->sharingConfig->maxTtlHours()))) {
            throw new InvalidArgumentException('Share expiry must be within the configured TTL.');
        }
    }

    private function defaultExpiry(Drive $drive, Carbon $now): Carbon
    {
        $expiresAt = $now->copy()->addHours($this->sharingConfig->defaultTtlHours());
        $limit = $this->rules->latestExpiry($drive, $now);

        return $limit !== null && $expiresAt->gt($limit) ? $limit : $expiresAt;
    }
}
