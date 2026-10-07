<?php

declare(strict_types=1);

namespace Vaulter\Sharing;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Vaulter\Access\AccessResolver;
use Vaulter\Configuration\TenancyConfig;
use Vaulter\Content\ContentAvailabilityGuard;
use Vaulter\Enums\Access\Ability;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Sharing\NodeShare;
use Vaulter\Profiles\ProfilePolicy;
use Vaulter\Profiles\ProfileRegistry;
use Vaulter\Repositories\Sharing\NodeShareReadRepository;
use Vaulter\Support\ShareToken;
use Vaulter\Tenancy\ShareTenantContext;
use Vaulter\Tenancy\TenancyBoundary;

/**
 * Resolves a bearer share-token into a {@see ShareContext} (§2.7 / M2c) — the
 * SEPARATE sharing pass that runs INSTEAD of the identity ACL. Given a raw token it:
 *
 *   1. hashes the token (sha256) and looks up the LIVE share by token_hash (not
 *      revoked, not expired) — the raw token never touches the DB;
 *   2. re-verifies the match timing-safe (hash_equals) — defence-in-depth so the
 *      compare can never be a probe-able side channel;
 *   3. checks the use budget (use_count < max_uses);
 *   4. enforces the optional password gate and the optional require-login gate;
 *   5. resolves the target node and INTERSECTS it with item-safety — the node must
 *      not be trashed or soft-deleted and must belong to a real storage (tenant
 *      boundary). A share on an unsafe node resolves to NotFound, indistinguishable
 *      from a wrong token.
 *   6. (P1.2 / F-P1.3-02, D14, S2.2) re-checks that the share's issuer (granted_by)
 *      STILL holds Ability::Share on the node through the identity ACL — a share
 *      does not outlive the access that authorized minting it (confused-deputy
 *      hardening). A share with no recorded issuer has nothing to re-verify.
 *
 * The produced ShareContext grants EXACTLY the share's bitmask on its node-set and
 * NOTHING inherited from any identity. This evaluator is read-only — it persists
 * nothing (the atomic use_count increment is owned by the Action/store-repo on the
 * consuming endpoints) and never accepts a Request.
 */
final class ShareResolver
{
    public function __construct(
        private readonly Repository $configuration,
        private readonly NodeShareReadRepository $shares,
        private readonly AccessResolver $access,
        private readonly ProfilePolicy $profiles,
        private readonly ProfileRegistry $registry,
        private readonly ShareContentGuard $content,
    ) {}

    /**
     * Resolve a raw bearer token for the (optional) acting host user and the
     * (optional) supplied password. Returns a ShareResult whose status the public
     * HTTP layer translates to 404 / 401 / proceed.
     */
    public function resolve(string $token, ?Authenticatable $actor = null, ?string $password = null): ShareResult
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
        // expired (NotFound, not a distinct leak).
        if ($share->isExhausted()) {
            return ShareResult::notFound();
        }

        if ($share->require_login && !$actor instanceof Authenticatable) {
            return ShareResult::loginRequired();
        }

        if ($share->hasPassword() && !$this->passwordMatches($share, $password)) {
            return ShareResult::passwordRequired();
        }

        $node = $this->safeTargetNode($share);

        if (!$node instanceof Node) {
            // Item-safety failed (trashed / soft-deleted / no storage) — opaque 404.
            return ShareResult::notFound();
        }

        if (!$this->issuerStillAuthorized($share, $node)) {
            // Confused-deputy re-check failed (F-P1.3-02) — opaque 404, same as any
            // other resolve failure.
            return ShareResult::notFound();
        }

        return ShareResult::ok(new ShareContext(
            share: $share,
            node: $node,
            permissions: $share->permissions,
        ));
    }

    /**
     * The share's target node, but ONLY when it passes item-safety (§2.7): not
     * trashed, not soft-deleted, and attached to a real storage (the tenant
     * boundary). Returns null otherwise so a share on an unsafe node is opaque.
     */
    /** Re-check a resolved share at byte open; consumption has already reserved this use. */
    public function refresh(ShareContext $context): ShareContext
    {
        $share = $context->share->newQuery()->whereKey($context->share->getRawOriginal($context->share->getKeyName()))->first();
        $originalHash = $context->share->getRawOriginal('token_hash');
        if (!is_string($originalHash) || !$share instanceof NodeShare || $share->isRevoked() || $share->isExpired()
            || !hash_equals($share->token_hash, $originalHash)
            || $share->password_hash !== $context->share->getRawOriginal('password_hash')
            || $share->require_login !== $context->share->require_login) {
            throw new NotFoundHttpException('Share link not found or no longer available.');
        }
        $node = $this->safeTargetNode($share);
        $fresh = $node === null ? null : new ShareContext($share, $node, $share->permissions);
        if ($node === null || $fresh === null || !$fresh->allows(Ability::View) || !$fresh->allows(Ability::Download) || !$this->issuerStillAuthorized($share, $node)) {
            throw new NotFoundHttpException('Share link not found or no longer available.');
        }

        return $fresh;
    }

    public function assertContentAvailable(Node $node, FileRevision $revision, ?ShareContext $context = null): void
    {
        if ($context !== null) {
            app(ContentAvailabilityGuard::class)->assertAvailableForShare($context, $node, $revision);

            return;
        }
        $this->content->assertAvailable(node: $node, revision: $revision);
    }

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
        if (!$drive instanceof Drive || (app(TenancyConfig::class)->enabled() && ($drive->tenant_id === null || TenancyBoundary::normalize($node->tenant_id) !== TenancyBoundary::normalize($drive->tenant_id))) || !$this->profiles->featureEnabled($this->registry->get($drive->profile), 'share_links') || (string) $share->tenant_id !== (string) $drive->tenant_id) {
            return null;
        }

        return $node;
    }

    /**
     * The confused-deputy re-check (P1.2 / F-P1.3-02, D14, S2.2): a bearer link is
     * only as trustworthy as the identity that minted it, so its issuer must STILL
     * hold Ability::Share on the node — the SAME ability the owner surface
     * ({@see ShareController::guardShare()})
     * requires to mint a link in the first place — through the real identity ACL
     * (host-role driver + deny-veto), never a hand-rolled grant lookup, so this
     * honours exactly the algorithm every other authorization decision does.
     *
     * D14 binds the re-check to Ability::Share (the ability that authorized the
     * mint), NOT to the share's own bitmask: an issuer who has lost write but kept
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
        if (!$drive instanceof Drive || (app(TenancyConfig::class)->enabled() && $drive->tenant_id === null)) {
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

    private function passwordMatches(NodeShare $share, ?string $password): bool
    {
        if ($password === null || $password === '' || $share->password_hash === null) {
            return false;
        }

        return Hash::check($password, $share->password_hash);
    }
}
