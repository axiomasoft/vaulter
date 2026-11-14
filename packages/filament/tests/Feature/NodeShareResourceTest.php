<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Livewire\Mechanisms\DataStore;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Configuration\SharingConfig;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Enums\Sharing\ShareAudience;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Models\Sharing\NodeShare;
use Vaulter\Drive\Repositories\Sharing\NodeShareReadRepository;
use Vaulter\Drive\Support\ShareToken;
use Vaulter\Filament\Resources\NodeShareResource;
use Vaulter\Filament\Resources\NodeShareResource\Pages\ListNodeShares;
use Vaulter\Filament\Support\PanelRecord;
use Vaulter\Filament\Support\PanelShares;
use Vaulter\Filament\Tests\Fixtures\DriveBuilder;

beforeEach(fn () => config()->set('vaulter-drive.features.share_links', true));

it('declares the panel record class, not a drive share model', function (): void {
    expect(NodeShareResource::getModel())->toBe(PanelRecord::class)
        ->and(NodeShareResource::getModelLabel())->toBe('node share');
});

it('is not creatable from a flat form (shares come from the core Action)', function (): void {
    expect(NodeShareResource::canCreate())->toBeFalse();
});

it('creates a share link through the core CreateShareLinkAction', function (): void {
    $owner = DriveBuilder::user();
    $this->actingAs($owner);

    $storage = DriveBuilder::storage();
    DriveBuilder::openDrive($storage, $owner);
    $folder = DriveBuilder::folder($storage, 'Public');
    DriveBuilder::grant($folder, $owner); // Owner ⇒ may Share

    NodeShareResource::createFromData([
        'node_id' => $folder->getKey(),
        'role' => RoleRegistry::READER,
        'audience' => ShareAudience::Anyone->value,
        'scope' => GrantScope::Item->value,
        'password' => '',
        'max_uses' => null,
        'expires_at' => '',
    ], app(SharingConfig::class));

    $share = app(NodeShareReadRepository::class)->forNode($folder)->first();

    expect($share)->toBeInstanceOf(NodeShare::class)
        ->and($share->isResolvable())->toBeTrue();
});

it('preserves the validated share form contract and one-time token notification', function (): void {
    $owner = DriveBuilder::user(51);
    $this->actingAs($owner);

    $storage = DriveBuilder::storage();
    DriveBuilder::openDrive($storage, $owner);
    $folder = DriveBuilder::folder($storage, 'Exact share');
    DriveBuilder::grant($folder, $owner);

    pullSentNotifications();
    $expires = Carbon::now()->addDay()->startOfSecond();

    NodeShareResource::createFromData([
        'node_id' => $folder->getKey(),
        'role' => RoleRegistry::WRITER,
        'audience' => ShareAudience::Authenticated->value,
        'scope' => GrantScope::Subtree->value,
        'password' => 'top-secret',
        'expires_at' => $expires->toDateTimeString(),
        'max_uses' => '7',
    ], app(SharingConfig::class));

    $share = app(NodeShareReadRepository::class)->forNode($folder)->first();

    expect($share)->toBeInstanceOf(NodeShare::class)
        ->and($share->role_key)->toBe(RoleRegistry::WRITER)
        ->and($share->audience)->toBe(ShareAudience::Authenticated)
        ->and($share->scope)->toBe(GrantScope::Subtree)
        ->and(Hash::check('top-secret', $share->password_hash))->toBeTrue()
        ->and($share->expires_at?->format('Y-m-d H:i:s'))->toBe($expires->toDateTimeString())
        ->and($share->max_uses)->toBe(7)
        ->and((string) $share->granted_by)->toBe('51');

    $notification = pullSentNotifications()[0] ?? null;
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
        DriveBuilder::openDrive($storage, $owner);
        $folder = DriveBuilder::folder($storage, 'Default share');
        DriveBuilder::grant($folder, $owner);

        NodeShareResource::createFromData([
            'node_id' => $folder->getKey(),
            'password' => '',
            'expires_at' => '',
            'max_uses' => '',
        ], app(SharingConfig::class));

        $share = app(NodeShareReadRepository::class)->forNode($folder)->first();

        expect($share)->toBeInstanceOf(NodeShare::class)
            ->and($share->role_key)->toBe(RoleRegistry::READER)
            ->and($share->audience)->toBe(ShareAudience::Anyone)
            ->and($share->scope)->toBe(GrantScope::Item)
            ->and($share->password_hash)->toBeNull()
            ->and($share->expires_at?->equalTo(now()->addHours(app(SharingConfig::class)->defaultTtlHours())))->toBeTrue()
            ->and($share->max_uses)->toBeNull()
            ->and((string) $share->granted_by)->toBe('52');
    } finally {
        Carbon::setTestNow();
    }
});

it('rejects a missing share node with the declared warning', function (): void {
    pullSentNotifications();

    NodeShareResource::createFromData([], app(SharingConfig::class));

    $notification = pullSentNotifications()[0] ?? null;

    expect(NodeShare::query()->count())->toBe(0)
        ->and($notification)->toBeArray()
        ->and($notification['title'])->toBe('Share not created')
        ->and($notification['body'])->toBe('The selected node no longer exists.')
        ->and($notification['status'])->toBe('warning');
});

it('injects live sharing configuration into the create form and action and enforces its expiry ceiling', function (): void {
    app(DataStore::class)->register();
    $owner = DriveBuilder::user();
    $this->actingAs($owner);
    $storage = DriveBuilder::storage();
    DriveBuilder::openDrive($storage, $owner);
    $folder = DriveBuilder::folder($storage, 'Live expiry');
    DriveBuilder::grant($folder, $owner);
    config()->set('vaulter-drive.sharing.max_ttl_hours', 1);
    $panel = Livewire::test(ListNodeShares::class);

    config()->set('vaulter-drive.sharing.max_ttl_hours', 2);
    $expiresAt = now()->addMinutes(90)->startOfSecond()->toDateTimeString();
    $data = [
        'node_id' => $folder->getKey(),
        'role' => RoleRegistry::READER,
        'audience' => ShareAudience::Anyone->value,
        'scope' => GrantScope::Item->value,
        'expires_at' => $expiresAt,
    ];
    $panel->callAction('create', data: $data)
        ->assertHasNoActionErrors()
        ->assertNotified('Share link created');

    expect(NodeShare::query()->sole()->expires_at?->toDateTimeString())->toBe($expiresAt);

    $tooLate = [...$data, 'expires_at' => now()->addHours(3)->toDateTimeString()];
    $panel->callAction('create', data: $tooLate)->assertHasActionErrors(['expires_at' => 'before_or_equal']);
    expect(fn () => NodeShareResource::createFromData($tooLate, app(SharingConfig::class)))
        ->toThrow(ValidationException::class);
    expect(NodeShare::query()->count())->toBe(1);
});

it('never persists the raw token — only its hash and prefix', function (): void {
    $owner = DriveBuilder::user();
    $this->actingAs($owner);

    $storage = DriveBuilder::storage();
    DriveBuilder::openDrive($storage, $owner);
    $folder = DriveBuilder::folder($storage, 'Secret');
    DriveBuilder::grant($folder, $owner);

    NodeShareResource::createFromData([
        'node_id' => $folder->getKey(),
        'role' => RoleRegistry::READER,
        'audience' => ShareAudience::Anyone->value,
        'scope' => GrantScope::Item->value,
        'password' => '',
        'max_uses' => null,
        'expires_at' => '',
    ], app(SharingConfig::class));

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
    DriveBuilder::openDrive($storage, $owner);
    $folder = DriveBuilder::folder($storage, 'Prefixed');
    DriveBuilder::grant($folder, $owner);

    NodeShareResource::createFromData([
        'node_id' => $folder->getKey(),
        'role' => RoleRegistry::READER,
        'audience' => ShareAudience::Anyone->value,
        'scope' => GrantScope::Item->value,
        'password' => '',
        'max_uses' => null,
        'expires_at' => '',
    ], app(SharingConfig::class));

    $share = app(NodeShareReadRepository::class)->forNode($folder)->first();

    // The prefix is a leading slice — it is NOT the hash and cannot reconstruct it.
    expect($share->token_hash)->not->toBe($share->token_prefix)
        ->and(str_starts_with($share->token_hash, ShareToken::hash($share->token_prefix)))->toBeFalse();
});

it('revokes a share link through the core RevokeShareLinkAction', function (): void {
    $owner = DriveBuilder::user();
    $this->actingAs($owner);

    $storage = DriveBuilder::storage();
    DriveBuilder::openDrive($storage, $owner);
    $folder = DriveBuilder::folder($storage, 'Revocable');
    DriveBuilder::grant($folder, $owner);

    NodeShareResource::createFromData([
        'node_id' => $folder->getKey(),
        'role' => RoleRegistry::READER,
        'audience' => ShareAudience::Anyone->value,
        'scope' => GrantScope::Item->value,
        'password' => '',
        'max_uses' => null,
        'expires_at' => '',
    ], app(SharingConfig::class));

    $share = app(NodeShareReadRepository::class)->forNode($folder)->first();
    expect($share->isRevoked())->toBeFalse();

    NodeShareResource::revokeRecord(DriveBuilder::shareRow($share));

    expect($share->fresh()->isRevoked())->toBeTrue();
});

it('does not create a share when the admin may not share the node', function (): void {
    $viewer = DriveBuilder::user(9);
    $this->actingAs($viewer);

    $storage = DriveBuilder::storage();
    $folder = DriveBuilder::folder($storage, 'Locked');
    // The viewer can open the drive and see the node (read through the root's subtree), but holds no Share on it.
    DriveBuilder::grant($storage->rootNode()->firstOrFail(), $viewer, RoleRegistry::READER);

    pullSentNotifications();

    NodeShareResource::createFromData([
        'node_id' => $folder->getKey(),
        'role' => RoleRegistry::READER,
        'audience' => ShareAudience::Anyone->value,
        'scope' => GrantScope::Item->value,
        'password' => '',
        'max_uses' => null,
        'expires_at' => '',
    ], app(SharingConfig::class));

    $notification = pullSentNotifications()[0] ?? null;

    expect(app(NodeShareReadRepository::class)->forNode($folder)->count())->toBe(0)
        ->and($notification)->toBeArray()
        ->and($notification['title'])->toBe('Share not created')
        ->and($notification['body'])->toBe('You may not share this node.')
        ->and($notification['status'])->toBe('warning');
});

it('answers a node the admin cannot see exactly like a missing node', function (): void {
    $this->actingAs(DriveBuilder::user(9));

    $folder = DriveBuilder::folder(DriveBuilder::storage(), 'Hidden');
    // No grant at all: the drive is not listed, so the node is not found.

    pullSentNotifications();

    NodeShareResource::createFromData(['node_id' => $folder->getKey()], app(SharingConfig::class));
    $hidden = pullSentNotifications()[0] ?? null;
    NodeShareResource::createFromData(['node_id' => 987654], app(SharingConfig::class));
    $missing = pullSentNotifications()[0] ?? null;

    expect(app(NodeShareReadRepository::class)->forNode($folder)->count())->toBe(0)
        ->and($hidden['body'])->toBe('The selected node no longer exists.')
        ->and($hidden['body'])->toBe($missing['body']);
});

it('does not mint or list shares when the feature is disabled', function (): void {
    $owner = DriveBuilder::user();
    $this->actingAs($owner);
    $drive = DriveBuilder::storage();
    DriveBuilder::openDrive($drive, $owner);
    $node = DriveBuilder::folder($drive);
    DriveBuilder::grant($node, $owner);
    config()->set('vaulter-drive.features.share_links', false);
    NodeShareResource::createFromData(['node_id' => $node->id], app(SharingConfig::class));
    expect(NodeShare::query()->count())->toBe(0)
        ->and(PanelShares::rows())->toBe([]);
});

it('rechecks the reader ability inside the actual revoke mutation', function (): void {
    $owner = DriveBuilder::user();
    $this->actingAs($owner);
    $drive = DriveBuilder::storage();
    DriveBuilder::openDrive($drive, $owner);
    $node = DriveBuilder::folder($drive);
    $grant = DriveBuilder::grant($node, $owner);
    NodeShareResource::createFromData(['node_id' => $node->id], app(SharingConfig::class));
    $share = NodeShare::query()->sole();
    $grant->forceFill(['role_key' => RoleRegistry::READER])->save();
    expect(fn () => NodeShareResource::revokeRecord(DriveBuilder::shareRow($share)))->toThrow(PermissionDeniedException::class)
        ->and($share->fresh()->revoked_at)->toBeNull()
        ->and(PanelShares::rows())->toBe([]);
});

it('rotates a link through the core Action and rechecks Share inside the mutation', function (): void {
    $owner = DriveBuilder::user();
    $this->actingAs($owner);
    $drive = DriveBuilder::storage();
    DriveBuilder::openDrive($drive, $owner);
    $node = DriveBuilder::folder($drive);
    $grant = DriveBuilder::grant($node, $owner);
    NodeShareResource::createFromData(['node_id' => $node->id], app(SharingConfig::class));
    $share = NodeShare::query()->sole();
    $before = $share->token_hash;

    $token = NodeShareResource::rotateRecord(DriveBuilder::shareRow($share));

    expect(ShareToken::hash($token))->toBe($share->fresh()->token_hash)
        ->and($share->fresh()->token_hash)->not->toBe($before)
        ->and($share->fresh()->token_generation)->toBe(2);

    $grant->forceFill(['role_key' => RoleRegistry::READER])->save();
    expect(fn () => NodeShareResource::rotateRecord(DriveBuilder::shareRow($share)))->toThrow(PermissionDeniedException::class)
        ->and($share->fresh()->token_generation)->toBe(2);
});

it('offers organizer and owner roles to nobody', function (): void {
    $options = (new ReflectionClass(NodeShareResource::class))->getMethod('roleOptions')->invoke(null);

    expect(array_keys($options))->toBe([RoleRegistry::PREVIEWER, RoleRegistry::READER, RoleRegistry::COMMENTER, RoleRegistry::WRITER]);
});

it('shows the refusal instead of failing when the role does not fit the audience', function (): void {
    $owner = DriveBuilder::user();
    $this->actingAs($owner);
    $drive = DriveBuilder::storage();
    DriveBuilder::openDrive($drive, $owner);
    $node = DriveBuilder::folder($drive);
    DriveBuilder::grant($node, $owner);
    pullSentNotifications();

    NodeShareResource::createFromData(['node_id' => $node->id, 'role' => RoleRegistry::WRITER, 'audience' => ShareAudience::Anyone->value], app(SharingConfig::class));
    $writer = pullSentNotifications()[0] ?? null;
    pullSentNotifications();
    NodeShareResource::createFromData(['node_id' => $node->id, 'audience' => ShareAudience::Tenant->value], app(SharingConfig::class));
    $tenant = pullSentNotifications()[0] ?? null;

    expect(NodeShare::query()->count())->toBe(0)
        ->and($writer['status'])->toBe('warning')
        ->and($writer['body'])->toContain('writer')
        ->and($tenant['status'])->toBe('warning')
        ->and($tenant['body'])->toContain('tenant');
});

it('renders the share table from the share listings, filters revoked links and rotates through the table action', function (): void {
    app(DataStore::class)->register();
    $owner = DriveBuilder::user();
    $this->actingAs($owner);
    $storage = DriveBuilder::storage();
    DriveBuilder::openDrive($storage, $owner);
    $node = DriveBuilder::folder($storage, 'Shared');
    DriveBuilder::grant($node, $owner);
    NodeShareResource::createFromData(['node_id' => $node->id], app(SharingConfig::class));
    NodeShareResource::createFromData(['node_id' => $node->id], app(SharingConfig::class));
    [$live, $revoked] = NodeShare::query()->orderBy('id')->get()->all();
    NodeShareResource::revokeRecord(DriveBuilder::shareRow($revoked));

    Livewire::test(ListNodeShares::class)
        ->assertCanSeeTableRecords([(string) $live->getKey(), (string) $revoked->getKey()])
        ->filterTable('revoked', true)
        ->assertCanSeeTableRecords([(string) $revoked->getKey()])
        ->assertCanNotSeeTableRecords([(string) $live->getKey()])
        ->filterTable('revoked', false)
        ->assertCanSeeTableRecords([(string) $live->getKey()])
        ->assertCanNotSeeTableRecords([(string) $revoked->getKey()])
        ->resetTableFilters()
        ->assertTableActionHidden('revoke', (string) $revoked->getKey())
        ->assertTableActionVisible('rotate', (string) $live->getKey());

    $before = $live->token_hash;
    Livewire::test(ListNodeShares::class)->callTableAction('rotate', (string) $live->getKey());

    expect($live->fresh()->token_hash)->not->toBe($before);
});

it('shows the status of a share row without ever carrying its token', function (): void {
    $owner = DriveBuilder::user();
    $this->actingAs($owner);
    $storage = DriveBuilder::storage();
    DriveBuilder::openDrive($storage, $owner);
    $node = DriveBuilder::folder($storage, 'Statuses');
    DriveBuilder::grant($node, $owner);
    NodeShareResource::createFromData(['node_id' => $node->id], app(SharingConfig::class));
    $share = NodeShare::query()->sole();

    $row = PanelShares::rows()[0];

    expect($row['status'])->toBe(PanelShares::LIVE)
        ->and($row['token_prefix'])->toBe($share->token_prefix)
        ->and(array_keys($row))->not->toContain('token', 'token_hash');

    $share->forceFill(['max_uses' => 1, 'use_count' => 1])->save();
    expect(PanelShares::rows()[0]['status'])->toBe(PanelShares::EXHAUSTED);

    $share->forceFill(['max_uses' => null, 'expires_at' => now()->subMinute()])->save();
    expect(PanelShares::rows()[0]['status'])->toBe(PanelShares::EXPIRED);

    NodeShareResource::revokeRecord(DriveBuilder::shareRow($share));
    expect(PanelShares::rows()[0]['status'])->toBe(PanelShares::REVOKED);
});
