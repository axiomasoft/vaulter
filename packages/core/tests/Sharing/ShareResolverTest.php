<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Vaulter\Enums\Ability;
use Vaulter\Enums\GrantLevel;
use Vaulter\Enums\Permission;
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

it('resolves a valid token and grants exactly the share bits', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'doc');
    ShareTestHelpers::share($node, token: 'good-token', permissions: Permission::Read->value);

    $result = shareResolver()->resolve('good-token');

    expect($result->isOk())->toBeTrue();
    $context = $result->context;

    // EXACTLY the bits: READ allows View, but not Edit/Share — no identity grant
    // ever widens a share.
    expect($context->allows(Ability::View))->toBeTrue()
        ->and($context->allows(Ability::Download))->toBeTrue()
        ->and($context->allows(Ability::Edit))->toBeFalse()
        ->and($context->allows(Ability::Share))->toBeFalse()
        ->and($context->node->getKey())->toBe($node->getKey());
});

it('honours a wider share bitmask exactly (DOWNLOAD/EDIT) without identity inheritance', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'editable');
    ShareTestHelpers::share(
        $node,
        token: 'writer-token',
        permissions: Permission::Read->value | Permission::Update->value,
        level: GrantLevel::Writer,
    );

    $context = shareResolver()->resolve('writer-token')->context;

    expect($context->allows(Ability::Edit))->toBeTrue()
        ->and($context->allows(Ability::View))->toBeTrue()
        // Update|Read does NOT include CREATE or SHARE — the share is exactly its bits.
        ->and($context->allows(Ability::Create))->toBeFalse()
        ->and($context->allows(Ability::Share))->toBeFalse();
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

it('demands login when the share requires it', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'doc');
    ShareTestHelpers::share($node, token: 'login-token', requireLogin: true);

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

it('fails item-safety for a soft-deleted target (NotFound)', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'gone');
    ShareTestHelpers::share($node, token: 'deleted-token');

    $node->delete(); // soft-delete (deleted_at)

    expect(shareResolver()->resolve('deleted-token')->resolution)->toBe(ShareResolution::NotFound);
});
