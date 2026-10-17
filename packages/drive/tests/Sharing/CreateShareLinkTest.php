<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Actions\Sharing\CreateShareLinkAction;
use Vaulter\Drive\Actions\Sharing\RevokeShareLinkAction;
use Vaulter\Drive\Data\Sharing\CreateShareLinkInput;
use Vaulter\Drive\Data\Sharing\RevokeShareInput;
use Vaulter\Drive\Data\Sharing\ShareLinkInput;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Enums\Sharing\ShareAudience;
use Vaulter\Drive\Events\Sharing\NodeShareCreated;
use Vaulter\Drive\Events\Sharing\NodeShareRevoked;
use Vaulter\Drive\Models\Sharing\NodeShare;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Support\ShareToken;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;

/**
 * The mint/revoke Actions (§2.7 / M2c): the raw token is returned ONCE and only its
 * hash + prefix are persisted; the password is hashed; the lifecycle events fire.
 */
beforeEach(function (): void {
    config()->set('vaulter-drive.features.share_links', true);
});

it('returns the raw token once and persists ONLY its hash + prefix', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'shared');

    $minted = app(CreateShareLinkAction::class)->execute(context: DriveContext::system($node->storage()->firstOrFail(), 'fixture'), input: new CreateShareLinkInput(node: $node, link: new ShareLinkInput));

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

    app(CreateShareLinkAction::class)->execute(context: DriveContext::system($node->storage()->firstOrFail(), 'fixture'), input: new CreateShareLinkInput(node: $node, link: new ShareLinkInput(password: 's3cret')));

    $row = NodeShare::query()->firstOrFail();

    expect($row->password_hash)->not->toBeNull()
        ->and($row->password_hash)->not->toBe('s3cret')
        ->and(Hash::check('s3cret', $row->password_hash))->toBeTrue();
});

it('defaults to a reader link for anyone on the item, born at generation 1', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'plain');

    $minted = app(CreateShareLinkAction::class)->execute(context: DriveContext::system($node->storage()->firstOrFail(), 'fixture'), input: new CreateShareLinkInput(node: $node, link: new ShareLinkInput));

    expect($minted->share->role_key)->toBe(RoleRegistry::READER)
        ->and($minted->share->role_version)->toBe(1)
        ->and($minted->share->audience)->toBe(ShareAudience::Anyone)
        ->and($minted->share->token_generation)->toBe(1)
        ->and($minted->share->scope)->toBe(GrantScope::Item)
        ->and($minted->share->use_count)->toBe(0)
        ->and(strlen($minted->token))->toBe(43);
});

it('emits NodeShareCreated on mint', function (): void {
    Event::fake([NodeShareCreated::class]);

    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'evented');

    app(CreateShareLinkAction::class)->execute(context: DriveContext::system($node->storage()->firstOrFail(), 'fixture'), input: new CreateShareLinkInput(node: $node, link: new ShareLinkInput));

    Event::assertDispatched(NodeShareCreated::class);
});

it('revokes a share (stamps revoked_at) and emits NodeShareRevoked', function (): void {
    Event::fake([NodeShareRevoked::class]);

    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'revocable');

    $minted = app(CreateShareLinkAction::class)->execute(context: DriveContext::system($node->storage()->firstOrFail(), 'fixture'), input: new CreateShareLinkInput(node: $node, link: new ShareLinkInput));

    $revoked = app(RevokeShareLinkAction::class)->execute(context: DriveContext::system($storage, 'fixture'), input: new RevokeShareInput(node: $node, share: $minted->share));

    expect($revoked)->toBeTrue()
        ->and($minted->share->refresh()->revoked_at)->not->toBeNull();

    Event::assertDispatched(NodeShareRevoked::class);
});

it('is a no-op (and emits nothing) when revoking an already-revoked share', function (): void {
    Event::fake([NodeShareRevoked::class]);

    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'twice');

    $minted = app(CreateShareLinkAction::class)->execute(context: DriveContext::system($node->storage()->firstOrFail(), 'fixture'), input: new CreateShareLinkInput(node: $node, link: new ShareLinkInput(role: RoleRegistry::READER)));

    app(RevokeShareLinkAction::class)->execute(context: DriveContext::system($storage, 'fixture'), input: new RevokeShareInput(node: $node, share: $minted->share));
    Event::assertDispatchedTimes(NodeShareRevoked::class, 1);

    $secondRevoke = app(RevokeShareLinkAction::class)->execute(context: DriveContext::system($storage, 'fixture'), input: new RevokeShareInput(node: $node, share: $minted->share->refresh()));

    expect($secondRevoke)->toBeFalse();
    Event::assertDispatchedTimes(NodeShareRevoked::class, 1);
});
