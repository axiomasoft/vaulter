<?php

declare(strict_types=1);

use Vaulter\Actions\Sharing\RevokeShareLinkAction;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Filament\Resources\NodeShareResource;
use Vaulter\Filament\Tests\Fixtures\DriveBuilder;
use Vaulter\Models\Sharing\NodeShare;
use Vaulter\Repositories\Sharing\NodeShareReadRepository;
use Vaulter\Support\ShareToken;

it('resolves the configured node-share model (swappable)', function (): void {
    expect(NodeShareResource::getModel())->toBe(NodeShare::class);
});

it('is not creatable from a flat form (shares come from the core Action)', function (): void {
    expect(NodeShareResource::canCreate())->toBeFalse();
});

it('creates a share link through the core CreateShareLinkAction', function (): void {
    $owner = DriveBuilder::user();
    $this->actingAs($owner);

    $storage = DriveBuilder::storage();
    $folder = DriveBuilder::folder($storage, 'Public');
    DriveBuilder::grant($folder, $owner); // Owner ⇒ may Share

    NodeShareResource::createFromData([
        'node_id' => $folder->getKey(),
        'level' => GrantLevel::Reader->value,
        'scope' => GrantScope::Item->value,
        'permissions' => 0,
        'password' => '',
        'require_login' => false,
        'allow_discovery' => false,
        'max_uses' => null,
        'expires_at' => '',
    ]);

    $share = app(NodeShareReadRepository::class)->forNode($folder)->first();

    expect($share)->toBeInstanceOf(NodeShare::class)
        ->and($share->isResolvable())->toBeTrue();
});

it('never persists the raw token — only its hash and prefix', function (): void {
    $owner = DriveBuilder::user();
    $this->actingAs($owner);

    $storage = DriveBuilder::storage();
    $folder = DriveBuilder::folder($storage, 'Secret');
    DriveBuilder::grant($folder, $owner);

    NodeShareResource::createFromData([
        'node_id' => $folder->getKey(),
        'level' => GrantLevel::Reader->value,
        'scope' => GrantScope::Item->value,
        'permissions' => 0,
        'password' => '',
        'require_login' => false,
        'allow_discovery' => false,
        'max_uses' => null,
        'expires_at' => '',
    ]);

    $share = app(NodeShareReadRepository::class)->forNode($folder)->first();

    // The stored hash is a sha256 hex digest; the raw token is unrecoverable. The
    // prefix is short and non-secret. There is no raw-token column at all.
    expect($share->token_hash)->toHaveLength(64)
        ->and($share->getAttributes())->not->toHaveKey('token')
        ->and(mb_strlen($share->token_prefix))->toBeLessThanOrEqual(16);
});

it('reveals the token prefix but never the full hash in the prefix column', function (): void {
    $owner = DriveBuilder::user();
    $this->actingAs($owner);

    $storage = DriveBuilder::storage();
    $folder = DriveBuilder::folder($storage, 'Prefixed');
    DriveBuilder::grant($folder, $owner);

    NodeShareResource::createFromData([
        'node_id' => $folder->getKey(),
        'level' => GrantLevel::Reader->value,
        'scope' => GrantScope::Item->value,
        'permissions' => 0,
        'password' => '',
        'require_login' => false,
        'allow_discovery' => false,
        'max_uses' => null,
        'expires_at' => '',
    ]);

    $share = app(NodeShareReadRepository::class)->forNode($folder)->first();

    // The prefix is a leading slice — it is NOT the hash and cannot reconstruct it.
    expect($share->token_hash)->not->toBe($share->token_prefix)
        ->and(str_starts_with($share->token_hash, ShareToken::hash($share->token_prefix)))->toBeFalse();
});

it('revokes a share link through the core RevokeShareLinkAction', function (): void {
    $owner = DriveBuilder::user();
    $this->actingAs($owner);

    $storage = DriveBuilder::storage();
    $folder = DriveBuilder::folder($storage, 'Revocable');
    DriveBuilder::grant($folder, $owner);

    NodeShareResource::createFromData([
        'node_id' => $folder->getKey(),
        'level' => GrantLevel::Reader->value,
        'scope' => GrantScope::Item->value,
        'permissions' => 0,
        'password' => '',
        'require_login' => false,
        'allow_discovery' => false,
        'max_uses' => null,
        'expires_at' => '',
    ]);

    $share = app(NodeShareReadRepository::class)->forNode($folder)->first();
    expect($share->isRevoked())->toBeFalse();

    app(RevokeShareLinkAction::class)->execute(
        share: $share,
        actorId: $owner->getKey(),
    );

    expect($share->fresh()->isRevoked())->toBeTrue();
});

it('does not create a share when the admin may not share the node', function (): void {
    $stranger = DriveBuilder::user(9);
    $this->actingAs($stranger);

    $storage = DriveBuilder::storage();
    $folder = DriveBuilder::folder($storage, 'Locked');

    NodeShareResource::createFromData([
        'node_id' => $folder->getKey(),
        'level' => GrantLevel::Reader->value,
        'scope' => GrantScope::Item->value,
        'permissions' => 0,
        'password' => '',
        'require_login' => false,
        'allow_discovery' => false,
        'max_uses' => null,
        'expires_at' => '',
    ]);

    expect(app(NodeShareReadRepository::class)->forNode($folder)->count())->toBe(0);
});
