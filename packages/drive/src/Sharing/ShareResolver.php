<?php

declare(strict_types=1);

namespace Vaulter\Drive\Sharing;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\TenancyConfig;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Drive\Access\AccessResolver;
use Vaulter\Drive\Content\DownloadGuards;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Drive\Enums\Sharing\ShareAudience;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Models\Sharing\NodeShare;
use Vaulter\Drive\Profiles\ProfilePolicy;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Drive\Repositories\Sharing\NodeShareReadRepository;
use Vaulter\Drive\Support\ShareToken;
use Vaulter\Drive\Tenancy\ShareTenantContext;
use Vaulter\Tenancy\TenancyBoundary;

/**
 * Resolves a bearer share-token into a {@see ShareContext} — the
 * SEPARATE sharing pass that runs INSTEAD of the identity ACL. Given a raw token it:
 *
 *   1. hashes the token (sha256) and looks up the LIVE share by token_hash (not
 *      revoked, not expired) — the raw token never touches the DB;
 *   2. re-verifies the match timing-safe (hash_equals) — defence-in-depth so the
 *      compare can never be a probe-able side channel;
 *   3. checks the use budget (use_count < max_uses) unless the request carries a
 *      valid link session, which has already paid for its visit;
 *   4. resolves the target node and INTERSECTS it with item-safety — the node must
 *      not be trashed or soft-deleted and must belong to a real storage (tenant
 *      boundary) — and with the link rules as they are NOW: the role may still be
 *      carried for the audience and the expiry may not pass the drive policy's
 *      maxShareLinkTtlDays. A share on an unsafe node resolves to NotFound,
 *      indistinguishable from a wrong token;
 *   5. enforces the audience (anyone | authenticated | tenant) and the optional
 *      password gate.
 *   6. re-checks that the share's issuer (granted_by)
 *      STILL holds Ability::Share on the node through the identity ACL — a share
 *      does not outlive the access that authorized minting it (confused-deputy
 *      hardening). A share with no recorded issuer has nothing to re-verify.
 *
 * The produced ShareContext grants EXACTLY the share's role on its node-set and
 * NOTHING inherited from any identity. This evaluator is read-only — it persists
 * nothing (the redemption is owned by RedeemShareLinkAction, called by
 * ResolveShareToken) and never accepts a Request.
 */
#[Internal]
final readonly class ShareResolver
{
    public function __construct(
        private Repository $configuration,
        private NodeShareReadRepository $shares,
        private AccessResolver $access,
        private ProfilePolicy $profiles,
        private ProfileRegistry $registry,
        private ShareContentGuard $content,
        private ShareLinkRules $rules,
        private ShareSession $sessions,
        private TenancyConfig $tenancy,
        private Container $container,
    ) {}

    /**
     * Resolve a raw bearer token for the (optional) acting host user, the (optional)
     * supplied password and the (optional) link session of an earlier redemption.
     * Returns a ShareResult whose status the public HTTP layer translates to
     * 404 / 401 / proceed.
     */
    public function resolve(string $token, ?Authenticatable $actor = null, ?string $password = null, ?string $session = null): ShareResult
    {
        $now = Carbon::now();
        $tokenHash = ShareToken::hash($token);

        $share = $this->shares->findLiveByTokenHash($tokenHash, $now);

        if (!$share instanceof NodeShare) {
            return ShareResult::notFound();
        }

        // Defence-in-depth: the lookup already matched by hash, but re-verify with a
        // constant-time compare so the resolve path is never a timing oracle.
        if (!ShareToken::hashesMatch($share->token_hash, $tokenHash)) {
            return ShareResult::notFound();
        }

        // The use budget is part of liveness — an exhausted share is as good as
        // expired (NotFound, not a distinct leak). A visitor whose session already
        // redeemed a use keeps the visit they paid for.
        $inSession = $this->sessions->valid($session, $share, $now);
        if (!$inSession && $share->isExhausted()) {
            return ShareResult::notFound();
        }

        $node = $this->safeTargetNode($share);

        if (!$node instanceof Node) {
            // Item-safety failed (trashed / soft-deleted / no storage / link rules) — opaque 404.
            return ShareResult::notFound();
        }

        if ($share->audience->requiresIdentity() && !$actor instanceof Authenticatable) {
            return ShareResult::loginRequired();
        }

        if (!$this->audienceAllows($share, $node)) {
            // A visitor from another tenant learns nothing about the link.
            return ShareResult::notFound();
        }

        if ($share->hasPassword() && !$this->passwordMatches($share, $password)) {
            return ShareResult::passwordRequired();
        }

        if (!$this->issuerStillAuthorized($share, $node)) {
            // Confused-deputy re-check failed — opaque 404, same as any
            // other resolve failure.
            return ShareResult::notFound();
        }

        return ShareResult::ok(new ShareContext(
            share: $share,
            node: $node,
            role: $share->role(),
        ), $inSession);
    }

    /** Re-check a resolved share at byte open; consumption has already reserved this use. */
    public function refresh(ShareContext $context): ShareContext
    {
        $share = $context->share->newQuery()->whereKey($context->share->getRawOriginal($context->share->getKeyName()))->first();
        $originalHash = $context->share->getRawOriginal('token_hash');
        if (!is_string($originalHash) || !$share instanceof NodeShare || $share->isRevoked() || $share->isExpired()
            || !hash_equals($share->token_hash, $originalHash)
            || $share->token_generation !== $context->share->token_generation
            || $share->password_hash !== $context->share->getRawOriginal('password_hash')
            || $share->audience !== $context->share->audience
            || !$share->role()->is($context->role)) {
            throw new NotFoundHttpException('Share link not found or no longer available.');
        }

        $node = $this->safeTargetNode($share);
        $fresh = $node === null ? null : new ShareContext($share, $node, $share->role());
        if ($node === null || $fresh === null || !$fresh->allows(Ability::View) || !$fresh->allows(Ability::Download) || !$this->issuerStillAuthorized($share, $node)) {
            throw new NotFoundHttpException('Share link not found or no longer available.');
        }

        return $fresh;
    }

    public function assertContentAvailable(Node $node, FileRevision $revision, ?ShareContext $context = null): void
    {
        if ($context !== null) {
            app(DownloadGuards::class)->check($context, $node, $revision);

            return;
        }

        $this->content->assertAvailable(node: $node, revision: $revision);
    }

    /**
     * The share's target node, but ONLY when it passes item-safety: not
     * trashed, not soft-deleted, attached to a real storage (the tenant boundary)
     * and that storage's profile still allows the link as it is stored — its role
     * for its audience, its expiry within maxShareLinkTtlDays. Returns null
     * otherwise so a share on an unsafe node is opaque.
     */
    private function safeTargetNode(NodeShare $share): ?Node
    {
        /** @var Node|null $node */
        $node = $share->node()->first();

        if (!$node instanceof Node) {
            return null;
        }

        // Trashed nodes are never publicly reachable; purged ones no longer exist.
        if ($node->trashed_at !== null) {
            return null;
        }

        // The storage must exist (tenant/storage boundary) — a detached node is a
        // corrupt state, never a public surface.
        $drive = $node->storage()->first();
        if ($drive instanceof Drive && !in_array($drive->status, [DriveStatus::Active, DriveStatus::Archived], true)) {
            return null;
        }

        if (!$drive instanceof Drive || ($this->tenancy->enabled() && ($drive->tenant_id === null || TenancyBoundary::normalize($node->tenant_id) !== TenancyBoundary::normalize($drive->tenant_id))) || !$this->profiles->featureEnabled($this->registry->get($drive->profile), 'share_links') || (string) $share->tenant_id !== (string) $drive->tenant_id
            || !$this->rules->holds($drive, $share, Carbon::now())) {
            return null;
        }

        return $node;
    }

    /**
     * The confused-deputy re-check: a bearer link is
     * only as trustworthy as the identity that minted it, so its issuer must STILL
     * hold Ability::Share on the node — the SAME ability the owner surface
     * ({@see ShareController::guardShare()})
     * requires to mint a link in the first place — through the real identity ACL
     * (host-role driver + grants), never a hand-rolled grant lookup, so this
     * honours exactly the algorithm every other authorization decision does.
     *
     * binds the re-check to Ability::Share (the ability that authorized the
     * mint), NOT to the share's own role: an issuer who has lost write but kept
     * Share still keeps a read-only link alive; only losing Share itself revokes
     * every link they minted.
     *
     * A share that records no issuer (granted_by is null — a legacy row, or one
     * minted by a system/console actor with no identity to re-check) has nothing
     * to re-verify and is left alone. A share WITH a recorded issuer whose account
     * no longer resolves (deleted user, no configured auth user provider) is
     * treated the same as a revoked grant — opaque NotFound, never a 500.
     */
    private function issuerStillAuthorized(NodeShare $share, Node $node): bool
    {
        $grantedBy = $share->granted_by;

        if ($grantedBy === null) {
            return true;
        }

        $issuer = $this->userProvider()?->retrieveById($grantedBy);

        if (!$issuer instanceof Authorizable) {
            return false;
        }

        $drive = $node->storage()->first();
        if (!$drive instanceof Drive || ($this->tenancy->enabled() && $drive->tenant_id === null)) {
            return false;
        }

        return app(ShareTenantContext::class)->evaluate($drive,
            fn (): bool => $this->access->can($issuer, Ability::View, $node) && $this->access->can($issuer, Ability::Share, $node));
    }

    /**
     * The auth user provider for the app's default guard — Laravel's own
     * `Auth::createUserProvider()` only resolves a provider given its NAME (it
     * falls back to `config('auth.defaults.provider')`, a key most Laravel apps
     * never set, NOT `auth.guards.<default guard>.provider`), so this looks the
     * name up through the default guard explicitly, mirroring exactly how
     * `$request->user()` — the source of `granted_by` — resolved the issuer in the
     * first place.
     */
    private function userProvider(): ?UserProvider
    {
        $guard = $this->configuration->get('auth.defaults.guard');

        if (!is_string($guard) || $guard === '') {
            return null;
        }

        $provider = $this->configuration->get('auth.guards.'.$guard.'.provider');

        return is_string($provider) && $provider !== '' ? Auth::createUserProvider($provider) : null;
    }

    /**
     * Whether the visitor belongs to the link's audience beyond being signed in:
     * "tenant" needs the host's current tenant to be the drive's tenant (an exact
     * comparison of tenant keys — no e-mail suffixes).
     */
    private function audienceAllows(NodeShare $share, Node $node): bool
    {
        if ($share->audience !== ShareAudience::Tenant) {
            return true;
        }

        $drive = $node->storage()->first();
        // Without strict tenancy there is no host tenant to compare with.
        if (!$drive instanceof Drive || $drive->tenant_id === null || !$this->tenancy->enabled()) {
            return false;
        }

        $current = TenancyBoundary::normalize($this->container->make(TenantResolver::class)->current());

        return $current !== null
            && $current === TenancyBoundary::normalize($drive->tenant_id);
    }

    private function passwordMatches(NodeShare $share, ?string $password): bool
    {
        if ($password === null || $password === '' || $share->password_hash === null) {
            return false;
        }

        return Hash::check($password, $share->password_hash);
    }
}
