<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Vaulter\Configuration\SharingConfig;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Enums\Access\Permission;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Filament\Resources\NodeShareResource;
use Vaulter\Filament\Tests\Fixtures\DriveBuilder;
use Vaulter\Models\Sharing\NodeShare;
use Vaulter\Repositories\Sharing\NodeShareReadRepository;
use Vaulter\Support\ShareToken;

beforeEach(fn () => config()->set('vaulter.features.share_links', true));

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

it('preserves the validated share form contract and one-time token notification', function (): void {
    $owner = DriveBuilder::user(51);
    $this->actingAs($owner);

    $storage = DriveBuilder::storage();
    $folder = DriveBuilder::folder($storage, 'Exact share');
    DriveBuilder::grant($folder, $owner);

    session()->forget('filament.notifications');
    $expires = Carbon::now()->addDay()->startOfSecond();

    NodeShareResource::createFromData([
        'node_id' => $folder->getKey(),
        'permissions' => '31',
        'level' => GrantLevel::Writer->value,
        'scope' => GrantScope::Subtree->value,
        'password' => 'top-secret',
        'require_login' => true,
        'allow_discovery' => true,
        'expires_at' => $expires->toDateTimeString(),
        'max_uses' => '7',
    ]);

    $share = app(NodeShareReadRepository::class)->forNode($folder)->first();

    expect($share)->toBeInstanceOf(NodeShare::class)
        ->and($share->permissions)->toBe(31)
        ->and($share->level)->toBe(GrantLevel::Writer)
        ->and($share->scope)->toBe(GrantScope::Subtree)
        ->and(Hash::check('top-secret', $share->password_hash))->toBeTrue()
        ->and($share->require_login)->toBeTrue()
        ->and($share->allow_discovery)->toBeTrue()
        ->and($share->expires_at?->format('Y-m-d H:i:s'))->toBe($expires->toDateTimeString())
        ->and($share->max_uses)->toBe(7)
        ->and((string) $share->granted_by)->toBe('51');

    $notification = session('filament.notifications.0');
    $prefix = 'Copy it now — it is shown only once: '.app(SharingConfig::class)->urlBase().'/';

    expect($notification)->toBeArray()
        ->and($notification['title'])->toBe('Share link created')
        ->and($notification['status'])->toBe('success')
        ->and($notification['duration'])->toBe('persistent')
        ->and($notification['body'])->toStartWith($prefix);

    $token = mb_substr($notification['body'], mb_strlen($prefix));

    expect($token)->not->toBe('')
        ->and(ShareToken::hash($token))->toBe($share->token_hash);
});

it('applies safe share defaults to omitted and blank optional values', function (): void {
    Carbon::setTestNow('2030-03-04 05:06:07');

    try {
        $owner = DriveBuilder::user(52);
        $this->actingAs($owner);

        $storage = DriveBuilder::storage();
        $folder = DriveBuilder::folder($storage, 'Default share');
        DriveBuilder::grant($folder, $owner);

        NodeShareResource::createFromData([
            'node_id' => $folder->getKey(),
            'password' => '',
            'expires_at' => '',
            'max_uses' => '',
        ]);

        $share = app(NodeShareReadRepository::class)->forNode($folder)->first();

        expect($share)->toBeInstanceOf(NodeShare::class)
            ->and($share->permissions)->toBe(Permission::Read->value)
            ->and($share->level)->toBe(GrantLevel::Reader)
            ->and($share->scope)->toBe(GrantScope::Item)
            ->and($share->password_hash)->toBeNull()
            ->and($share->require_login)->toBeFalse()
            ->and($share->allow_discovery)->toBeFalse()
            ->and($share->expires_at?->equalTo(now()->addHours(app(SharingConfig::class)->defaultTtlHours())))->toBeTrue()
            ->and($share->max_uses)->toBeNull()
            ->and((string) $share->granted_by)->toBe('52');
    } finally {
        Carbon::setTestNow();
    }
});

it('rejects a missing share node with the declared warning', function (): void {
    session()->forget('filament.notifications');

    NodeShareResource::createFromData([]);

    $notification = session('filament.notifications.0');

    expect(NodeShare::query()->count())->toBe(0)
        ->and($notification)->toBeArray()
        ->and($notification['title'])->toBe('Share not created')
        ->and($notification['body'])->toBe('The selected node no longer exists.')
        ->and($notification['status'])->toBe('warning');
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

    NodeShareResource::revokeRecord($share);

    expect($share->fresh()->isRevoked())->toBeTrue();
});

it('does not create a share when the admin may not share the node', function (): void {
    $stranger = DriveBuilder::user(9);
    $this->actingAs($stranger);

    $storage = DriveBuilder::storage();
    $folder = DriveBuilder::folder($storage, 'Locked');

    session()->forget('filament.notifications');

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

    $notification = session('filament.notifications.0');

    expect(app(NodeShareReadRepository::class)->forNode($folder)->count())->toBe(0)
        ->and($notification)->toBeArray()
        ->and($notification['title'])->toBe('Share not created')
        ->and($notification['body'])->toBe('You may not share this node.')
        ->and($notification['status'])->toBe('warning');
});

it('does not mint or list shares when the feature is disabled', function (): void {
    $owner = DriveBuilder::user();
    $this->actingAs($owner);
    $drive = DriveBuilder::storage();
    $node = DriveBuilder::folder($drive);
    DriveBuilder::grant($node, $owner);
    config()->set('vaulter.features.share_links', false);
    NodeShareResource::createFromData(['node_id' => $node->id]);
    expect(NodeShare::query()->count())->toBe(0)
        ->and(NodeShareResource::getEloquentQuery()->count())->toBe(0);
});

it('rechecks the reader ability inside the actual revoke mutation', function (): void {
    $owner = DriveBuilder::user();
    $this->actingAs($owner);
    $drive = DriveBuilder::storage();
    $node = DriveBuilder::folder($drive);
    $grant = DriveBuilder::grant($node, $owner);
    NodeShareResource::createFromData(['node_id' => $node->id]);
    $share = NodeShare::query()->sole();
    $grant->forceFill(['level' => GrantLevel::Reader])->save();
    expect(fn () => NodeShareResource::revokeRecord($share))->toThrow(PermissionDeniedException::class)
        ->and($share->fresh()->revoked_at)->toBeNull()
        ->and(NodeShareResource::getEloquentQuery()->count())->toBe(0);
});
