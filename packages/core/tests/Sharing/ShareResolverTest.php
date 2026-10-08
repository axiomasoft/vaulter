<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Enums\Sharing\ShareAudience;
use Vaulter\Models\Access\NodeGrant;
use Vaulter\Sharing\ShareResolution;
use Vaulter\Sharing\ShareResolver;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;
use Vaulter\Tests\Sharing\ShareTestHelpers;

/**
 * The resolver hardening matrix (§2.7 / M2c): a valid token resolves within its
 * bits; a wrong/expired/revoked/over-budget token is denied; password and
 * require-login gates hold; a trashed target fails item-safety opaquely. The share
 * grants EXACTLY its bits — no identity inheritance.
 */
beforeEach(function (): void {
    config()->set('vaulter.features.share_links', true);
});

function shareResolver(): ShareResolver
{
    return app(ShareResolver::class);
}

it('resolves a valid token and grants exactly the abilities of the link role', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'doc');
    ShareTestHelpers::share($node, token: 'good-token');

    $result = shareResolver()->resolve('good-token');

    expect($result->isOk())->toBeTrue();
    $context = $result->context;

    // EXACTLY the role: reader allows View and Download, but not Edit/Share — no
    // identity grant ever widens a share.
    expect($context->allows(Ability::View))->toBeTrue()
        ->and($context->allows(Ability::Download))->toBeTrue()
        ->and($context->allows(Ability::Edit))->toBeFalse()
        ->and($context->allows(Ability::Share))->toBeFalse()
        ->and($context->node->getKey())->toBe($node->getKey());
});

it('honours a wider role exactly (writer) without identity inheritance', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'editable');
    ShareTestHelpers::share($node, token: 'writer-token', role: RoleRegistry::WRITER, audience: ShareAudience::Authenticated);

    $context = shareResolver()->resolve('writer-token', actor: (new TestUser)->forceFill(['id' => 7]))->context;

    expect($context->allows(Ability::Edit))->toBeTrue()
        ->and($context->allows(Ability::View))->toBeTrue()
        // A writer link never carries Share or Manage.
        ->and($context->allows(Ability::Share))->toBeFalse()
        ->and($context->allows(Ability::Manage))->toBeFalse();
});

it('keeps a previewer link from downloading while it can view', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'preview');
    ShareTestHelpers::share($node, token: 'preview-token', role: RoleRegistry::PREVIEWER);

    $context = shareResolver()->resolve('preview-token')->context;

    expect($context->allows(Ability::View))->toBeTrue()
        ->and($context->allows(Ability::Download))->toBeFalse();
});

it('treats a stored role the link rules no longer allow as not found', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'forged');
    // A writer link for anyone cannot be created; if one is stored it never resolves.
    ShareTestHelpers::share($node, token: 'writer-anyone', role: RoleRegistry::WRITER);
    ShareTestHelpers::share($node, token: 'organizer-link', role: RoleRegistry::ORGANIZER, audience: ShareAudience::Authenticated);
    $actor = (new TestUser)->forceFill(['id' => 7]);

    expect(shareResolver()->resolve('writer-anyone', actor: $actor)->resolution)->toBe(ShareResolution::NotFound)
        ->and(shareResolver()->resolve('organizer-link', actor: $actor)->resolution)->toBe(ShareResolution::NotFound);
});

it('denies a wrong token (NotFound)', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'doc');
    ShareTestHelpers::share($node, token: 'right-token');

    $result = shareResolver()->resolve('WRONG-token');

    expect($result->resolution)->toBe(ShareResolution::NotFound)
        ->and($result->context)->toBeNull();
});

it('denies an expired token (NotFound)', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'doc');
    ShareTestHelpers::share($node, token: 'expired-token', expiresAt: Carbon::now()->subMinute());

    expect(shareResolver()->resolve('expired-token')->resolution)->toBe(ShareResolution::NotFound);
});

it('denies a revoked token (NotFound)', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'doc');
    ShareTestHelpers::share($node, token: 'revoked-token', revokedAt: Carbon::now()->subMinute());

    expect(shareResolver()->resolve('revoked-token')->resolution)->toBe(ShareResolution::NotFound);
});

it('denies a token that has exhausted its use budget (NotFound)', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'doc');
    ShareTestHelpers::share($node, token: 'spent-token', maxUses: 3, useCount: 3);

    expect(shareResolver()->resolve('spent-token')->resolution)->toBe(ShareResolution::NotFound);
});

it('resolves while still under the use budget', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'doc');
    ShareTestHelpers::share($node, token: 'budget-token', maxUses: 3, useCount: 2);

    expect(shareResolver()->resolve('budget-token')->isOk())->toBeTrue();
});

it('demands a password when the share is password-gated', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'doc');
    ShareTestHelpers::share($node, token: 'pw-token', passwordHash: Hash::make('letmein'));

    // No password → password required.
    expect(shareResolver()->resolve('pw-token')->resolution)->toBe(ShareResolution::PasswordRequired);

    // Wrong password → still required (never resolves).
    expect(shareResolver()->resolve('pw-token', password: 'nope')->resolution)->toBe(ShareResolution::PasswordRequired);

    // Correct password → resolves.
    expect(shareResolver()->resolve('pw-token', password: 'letmein')->isOk())->toBeTrue();
});

it('demands login when the share audience is authenticated', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'doc');
    ShareTestHelpers::share($node, token: 'login-token', audience: ShareAudience::Authenticated);

    // No actor → login required.
    expect(shareResolver()->resolve('login-token')->resolution)->toBe(ShareResolution::LoginRequired);

    // An authenticated host user satisfies it.
    $actor = (new TestUser)->forceFill(['id' => 7]);
    expect(shareResolver()->resolve('login-token', actor: $actor)->isOk())->toBeTrue();
});

it('fails item-safety for a trashed target (NotFound)', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'doomed');
    ShareTestHelpers::share($node, token: 'trash-token');

    // Send the target to the trash — the share must no longer resolve.
    $node->forceFill(['trashed_at' => Carbon::now()])->save();

    expect(shareResolver()->resolve('trash-token')->resolution)->toBe(ShareResolution::NotFound);
});

it('fails item-safety for a purged target (NotFound)', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'gone');
    ShareTestHelpers::share($node, token: 'deleted-token');

    $node->delete(); // purge is a real DELETE; the share row cascades with it

    expect(shareResolver()->resolve('deleted-token')->resolution)->toBe(ShareResolution::NotFound);
});

// ─────────────────────────── issuer re-check (P1.2 / F-P1.3-02, D14) ───────────

/**
 * A real, persisted TestUser row — issuer re-check inverts granted_by (a bare id)
 * back into an Authorizable via the host's configured auth user provider
 * (Auth::createUserProvider()->retrieveById()), which needs a real backing table;
 * unlike AccessTestHelpers::user() (a fabricated, unpersisted stand-in used
 * elsewhere in the ACL suite), this row actually exists.
 */
function persistedIssuer(): TestUser
{
    $table = (new TestUser)->getTable();

    if (!Schema::hasTable($table)) {
        Schema::create($table, function (Blueprint $table): void {
            $table->id();
        });
    }

    config()->set('auth.providers.users.model', TestUser::class);

    /** @var TestUser $user */
    $user = TestUser::query()->create([]);

    return $user;
}

it('resolves while its issuer still holds Ability::Share on the node (F-P1.3-02)', function (): void {
    config()->set('vaulter.access.permission_driver', 'database');

    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'issuer-ok');
    $issuer = persistedIssuer();
    AccessTestHelpers::grant($node, RoleRegistry::OWNER, scope: GrantScope::Item, subjectId: $issuer->getKey());

    $share = ShareTestHelpers::share($node, token: 'issuer-ok-token');
    $share->forceFill(['granted_by' => $issuer->getKey()])->save();

    expect(shareResolver()->resolve('issuer-ok-token')->isOk())->toBeTrue();
});

it('404s once its issuer no longer holds Ability::Share on the node (confused deputy, F-P1.3-02)', function (): void {
    config()->set('vaulter.access.permission_driver', 'database');

    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'issuer-revoked');
    $issuer = persistedIssuer();
    AccessTestHelpers::grant($node, RoleRegistry::OWNER, scope: GrantScope::Item, subjectId: $issuer->getKey());

    $share = ShareTestHelpers::share($node, token: 'issuer-revoked-token');
    $share->forceFill(['granted_by' => $issuer->getKey()])->save();

    // The issuer's own grant is pulled — the link they minted must die with it.
    NodeGrant::query()->where('subject_id', $issuer->getKey())->delete();

    expect(shareResolver()->resolve('issuer-revoked-token')->resolution)->toBe(ShareResolution::NotFound);
});

it('404s once its issuer is no longer resolvable (deleted account, F-P1.3-02)', function (): void {
    config()->set('vaulter.access.permission_driver', 'database');

    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'issuer-gone');
    $issuer = persistedIssuer();
    AccessTestHelpers::grant($node, RoleRegistry::OWNER, scope: GrantScope::Item, subjectId: $issuer->getKey());

    $share = ShareTestHelpers::share($node, token: 'issuer-gone-token');
    $share->forceFill(['granted_by' => $issuer->getKey()])->save();

    $issuer->delete();

    expect(shareResolver()->resolve('issuer-gone-token')->resolution)->toBe(ShareResolution::NotFound);
});

it('leaves a share with no recorded issuer unaffected (nothing to re-verify, F-P1.3-02)', function (): void {
    config()->set('vaulter.access.permission_driver', 'database');

    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'no-issuer');
    // granted_by is null by default — ShareTestHelpers::share() never sets it.
    ShareTestHelpers::share($node, token: 'no-issuer-token');

    expect(shareResolver()->resolve('no-issuer-token')->isOk())->toBeTrue();
});
