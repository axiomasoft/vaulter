<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Vaulter\Actions\Sharing\CreateShareLinkAction;
use Vaulter\Actions\Sharing\RevokeShareLinkAction;
use Vaulter\Data\Sharing\ShareLinkInput;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Enums\Access\Permission;
use Vaulter\Events\Sharing\NodeShareCreated;
use Vaulter\Events\Sharing\NodeShareRevoked;
use Vaulter\Models\Sharing\NodeShare;
use Vaulter\Support\ShareToken;
use Vaulter\Tests\Access\AccessTestHelpers;

/**
 * The mint/revoke Actions (§2.7 / M2c): the raw token is returned ONCE and only its
 * hash + prefix are persisted; the password is hashed; the lifecycle events fire.
 */
beforeEach(function (): void {
    config()->set('vaulter.features.share_links', true);
});

it('returns the raw token once and persists ONLY its hash + prefix', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'shared');

    $minted = app(CreateShareLinkAction::class)->execute(
        node: $node,
        data: new ShareLinkInput(permissions: Permission::Read->value),
    );

    // The raw token is surfaced exactly once, on the result.
    expect($minted->token)->toBeString()->not->toBe('');

    $row = NodeShare::query()->firstOrFail();

    // The DB stores ONLY the sha256 hash + a short prefix — never the raw token.
    expect($row->token_hash)->toBe(ShareToken::hash($minted->token))
        ->and($row->token_prefix)->toBe(ShareToken::prefix($minted->token, 8))
        ->and($row->getAttributes())->not->toHaveKey('token')
        ->and($row->token_hash)->not->toBe($minted->token);
});

it('hashes a share password — the plaintext never lands in the row', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'protected');

    app(CreateShareLinkAction::class)->execute(
        node: $node,
        data: new ShareLinkInput(permissions: Permission::Read->value, password: 's3cret'),
    );

    $row = NodeShare::query()->firstOrFail();

    expect($row->password_hash)->not->toBeNull()
        ->and($row->password_hash)->not->toBe('s3cret')
        ->and(Hash::check('s3cret', $row->password_hash))->toBeTrue();
});

it('defaults the bitmask to READ and the scope to item', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'plain');

    $minted = app(CreateShareLinkAction::class)->execute(
        node: $node,
        data: new ShareLinkInput(permissions: Permission::Read->value),
    );

    expect($minted->share->permissions)->toBe(Permission::Read->value)
        ->and($minted->share->scope)->toBe(GrantScope::Item)
        ->and($minted->share->use_count)->toBe(0);
});

it('emits NodeShareCreated on mint', function (): void {
    Event::fake([NodeShareCreated::class]);

    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'evented');

    app(CreateShareLinkAction::class)->execute(
        node: $node,
        data: new ShareLinkInput(permissions: Permission::Read->value),
    );

    Event::assertDispatched(NodeShareCreated::class);
});

it('revokes a share (stamps revoked_at) and emits NodeShareRevoked', function (): void {
    Event::fake([NodeShareRevoked::class]);

    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'revocable');

    $minted = app(CreateShareLinkAction::class)->execute(
        node: $node,
        data: new ShareLinkInput(permissions: Permission::Read->value),
    );

    $revoked = app(RevokeShareLinkAction::class)->execute(share: $minted->share);

    expect($revoked)->toBeTrue()
        ->and($minted->share->refresh()->revoked_at)->not->toBeNull();

    Event::assertDispatched(NodeShareRevoked::class);
});

it('is a no-op (and emits nothing) when revoking an already-revoked share', function (): void {
    Event::fake([NodeShareRevoked::class]);

    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'twice');

    $minted = app(CreateShareLinkAction::class)->execute(
        node: $node,
        data: new ShareLinkInput(permissions: Permission::Read->value, level: GrantLevel::Reader),
    );

    app(RevokeShareLinkAction::class)->execute(share: $minted->share);
    Event::assertDispatchedTimes(NodeShareRevoked::class, 1);

    $secondRevoke = app(RevokeShareLinkAction::class)->execute(share: $minted->share->refresh());

    expect($secondRevoke)->toBeFalse();
    Event::assertDispatchedTimes(NodeShareRevoked::class, 1);
});
