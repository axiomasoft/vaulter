<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Access\AccessInvalidation;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Data\DriveRef;
use Vaulter\Drive\Data\Sharing\ShareLinkInput;
use Vaulter\Drive\Data\Sharing\ShareLinkUpdate;
use Vaulter\Drive\Data\UploadOptions;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Models\Activity\Activity;
use Vaulter\Drive\Repositories\Changes\ChangeHeadRepository;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Events\Outbox\OutboxEnvelope;
use Vaulter\ValueObjects\Access\ActorRef;

/**
 * drive.access.effective_permissions_changed (D26): every change of who may do what records it in the transaction of
 * the change — grants, inheritance, shares, moves, the trash, the drive lifecycle — and the host records it for changes
 * the drive cannot see through AccessInvalidation. It reaches outbox consumers only.
 */
beforeEach(function (): void {
    config()->set('vaulter-drive.access.permission_driver', 'database');
    config()->set('vaulter-drive.features.share_links', true);
    $this->drive = DriveTestHelpers::drive();
    $this->driveId = $this->drive->snapshot()->id;
});

/** @return list<array{reason: string, scope: string, node_id: string|null, drive_id: string|null}> */
function invalidations(): array
{
    return OutboxEvent::query()->where('type', DriveEventType::EffectivePermissionsChanged->value)->orderBy('outbox_seq')->get()
        ->map(static fn (OutboxEvent $row): array => [
            'reason' => $row->payload['data']['reason'],
            'scope' => $row->payload['data']['scope'],
            'node_id' => $row->payload['data']['node_id'],
            'drive_id' => $row->payload['data']['drive_id'],
        ])->all();
}

function aFile(DriveHandle $drive, string $parent): string
{
    return $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('bytes'), 'doc.txt'), new UploadOptions(parent: $parent))->id;
}

it('records a subtree change for a grant on a folder and a node change for a grant on a file, in the grant\'s transaction', function (): void {
    $folder = $this->drive->folders()->create('Team')->id;
    $file = aFile($this->drive, $folder);

    $this->drive->access()->grant($folder, new GrantInput(subject: new Subject('user', 14), role: RoleRegistry::READER));
    $this->drive->access()->grant($file, new GrantInput(subject: new Subject('user', 15), role: RoleRegistry::WRITER));

    expect(invalidations())->toBe([
        ['reason' => 'grant.created', 'scope' => 'subtree', 'node_id' => $folder, 'drive_id' => $this->driveId],
        ['reason' => 'grant.created', 'scope' => 'node', 'node_id' => $file, 'drive_id' => $this->driveId],
    ]);

    $created = OutboxEvent::query()->where('type', DriveEventType::GrantCreated->value)->orderBy('outbox_seq')->firstOrFail();
    $changed = OutboxEvent::query()->where('type', DriveEventType::EffectivePermissionsChanged->value)->orderBy('outbox_seq')->firstOrFail();
    expect($changed->payload['correlation_id'])->toBe($created->payload['correlation_id'])
        ->and($changed->outbox_seq)->toBeGreaterThan($created->outbox_seq);
});

it('records the revocation of a grant and a change of inheritance', function (): void {
    $folder = $this->drive->folders()->create('Team')->id;
    $grant = $this->drive->access()->grant($folder, new GrantInput(subject: new Subject('user', 14), role: RoleRegistry::READER));
    OutboxEvent::query()->delete();

    $this->drive->access()->revoke($folder, $grant);
    $this->drive->access()->restrictInheritance($folder);
    $this->drive->access()->restoreInheritance($folder);
    // Already inheriting: nothing changed, nothing recorded.
    $this->drive->access()->restoreInheritance($folder);

    expect(array_column(invalidations(), 'reason'))->toBe(['grant.revoked', 'access.inheritance_changed', 'access.inheritance_changed'])
        ->and(array_unique(array_column(invalidations(), 'scope')))->toBe(['subtree']);
});

it('records every change of a share link that moves access, and none for an update that changes nothing', function (): void {
    $folder = $this->drive->folders()->create('Shared')->id;
    $minted = $this->drive->shares()->create($folder, new ShareLinkInput);
    $this->drive->shares()->update($folder, $minted->link->id, new ShareLinkUpdate(expiresAt: now()->addDay()));
    $this->drive->shares()->update($folder, $minted->link->id, new ShareLinkUpdate);
    $rotated = $this->drive->shares()->rotate($folder, $minted->link->id);
    $this->drive->shares()->revoke($folder, $rotated->link->id);

    expect(array_column(invalidations(), 'reason'))->toBe(['share.created', 'share.updated', 'share.rotated', 'share.revoked'])
        ->and(array_unique(array_column(invalidations(), 'node_id')))->toBe([$folder]);
});

it('records a move, a trashing, a restore and a purge for the subtree they reach', function (): void {
    $a = $this->drive->folders()->create('A')->id;
    $b = $this->drive->folders()->create('B')->id;

    $this->drive->nodes()->move($b, $a);
    $this->drive->nodes()->trash($b);
    $this->drive->trash()->restore($b);
    $this->drive->nodes()->trash($b);
    $this->drive->trash()->purge($b);

    expect(invalidations())->toBe(array_map(fn (string $reason): array => ['reason' => $reason, 'scope' => 'subtree', 'node_id' => $b, 'drive_id' => $this->driveId], [
        'node.moved', 'node.trashed', 'node.restored', 'node.trashed', 'node.purged',
    ]));
});

it('records a drive-wide change when the drive is archived, unarchived and deleted', function (): void {
    $this->drive->archive();
    $this->drive->unarchive();
    // The purge job runs on the sync queue here: drive.deleted follows, without a second invalidation.
    $this->drive->delete();

    expect(invalidations())->toBe(array_map(fn (string $reason): array => ['reason' => $reason, 'scope' => 'drive', 'node_id' => null, 'drive_id' => $this->driveId], [
        'drive.archived', 'drive.unarchived', 'drive.deleted',
    ]));
});

it('rolls the event back with the change', function (): void {
    $folder = $this->drive->folders()->create('Team')->id;

    expect(fn () => app(VaulterDatabase::class)->transaction(function () use ($folder): void {
        $this->drive->access()->grant($folder, new GrantInput(subject: new Subject('user', 14), role: RoleRegistry::READER));
        throw new RuntimeException('the caller failed after the grant');
    }))->toThrow(RuntimeException::class);

    expect(invalidations())->toBe([]);
});

it('keeps the event out of the activity feed and away from webhooks, with the access policy as its subject', function (): void {
    $this->drive->archive();

    $row = OutboxEvent::query()->where('type', DriveEventType::EffectivePermissionsChanged->value)->sole();
    expect($row->external)->toBeFalse()
        ->and($row->scope_kind)->toBe(OutboxEnvelope::SCOPE_DRIVE)
        ->and($row->scope_id)->toBe($this->driveId)
        ->and($row->payload['subject'])->toBe(['type' => 'access', 'id' => 'drive:'.$this->driveId])
        ->and(Activity::query()->where('event_id', $row->event_id)->exists())->toBeFalse()
        ->and(DriveEventType::EffectivePermissionsChanged->spec()->activity)->toBeFalse()
        ->and(DriveEventType::EffectivePermissionsChanged->spec()->journal)->toBeNull();
});

it('records a host invalidation of one drive under the host\'s reason', function (): void {
    app(AccessInvalidation::class)->changed(DriveRef::of($this->drive->snapshot()), 'host-policy');
    app(AccessInvalidation::class)->changed(DriveRef::of($this->drive), 'tenant.deactivated');

    $row = OutboxEvent::query()->where('type', DriveEventType::EffectivePermissionsChanged->value)->orderBy('outbox_seq')->firstOrFail();
    expect(invalidations())->toBe([
        ['reason' => 'host-policy', 'scope' => 'drive', 'node_id' => null, 'drive_id' => $this->driveId],
        ['reason' => 'tenant.deactivated', 'scope' => 'drive', 'node_id' => null, 'drive_id' => $this->driveId],
    ])->and($row->payload['actor'])->toBe(['type' => ActorRef::SYSTEM_TYPE, 'id' => null]);
});

it('records a global host invalidation without a drive', function (): void {
    app(AccessInvalidation::class)->changed(null, 'host-role');

    $row = OutboxEvent::query()->where('type', DriveEventType::EffectivePermissionsChanged->value)->sole();
    expect(invalidations())->toBe([['reason' => 'host-role', 'scope' => 'global', 'node_id' => null, 'drive_id' => null]])
        ->and($row->scope_kind)->toBe(OutboxEnvelope::SCOPE_TENANT)
        ->and($row->scope_id)->toBeNull()
        ->and($row->payload['subject'])->toBe(['type' => 'access', 'id' => 'global']);
});

it('refuses a malformed reason or an unknown drive and records nothing', function (Closure $call): void {
    expect($call)->toThrow(InvalidArgumentException::class);
    expect(invalidations())->toBe([]);
})->with([
    'an empty reason' => [fn () => app(AccessInvalidation::class)->changed(null, '')],
    'an upper-case reason' => [fn () => app(AccessInvalidation::class)->changed(null, 'HostRole')],
    'a reason over 64 bytes' => [fn () => app(AccessInvalidation::class)->changed(null, str_repeat('a', 65))],
    'a drive that does not exist' => [fn () => app(AccessInvalidation::class)->changed(DriveRef::of('01JZZZZZZZZZZZZZZZZZZZZZZZ'), 'host-policy')],
]);

it('raises the journal epoch with the host invalidation, in one UPDATE for all drives, and leaves seq alone', function (): void {
    $heads = app(ChangeHeadRepository::class);
    $other = DriveTestHelpers::drive();
    $this->drive->folders()->create('Team');
    $other->folders()->create('Team');
    $before = [$heads->find($this->driveId), $heads->find($other->snapshot()->id)];

    app(AccessInvalidation::class)->changed(DriveRef::of($this->drive), 'host-policy');
    $sql = [];
    DB::listen(static function (QueryExecuted $query) use (&$sql): void {
        if (str_contains($query->sql, 'drive_change_heads')) {
            $sql[] = strtolower($query->sql);
        }
    });
    app(AccessInvalidation::class)->changed(null, 'host-role');

    expect($heads->find($this->driveId))->toBe([...$before[0], 'epoch' => 3])
        ->and($heads->find($other->snapshot()->id))->toBe([...$before[1], 'epoch' => 2])
        ->and(array_values(array_filter($sql, static fn (string $q): bool => str_starts_with($q, 'update'))))->toHaveCount(1)
        ->and(implode(' ', $sql))->not->toContain('order by');
});

it('rolls back the event and the epoch together when the epoch UPDATE fails, and the host can retry', function (): void {
    $heads = app(ChangeHeadRepository::class);
    $this->drive->folders()->create('Team');
    OutboxEvent::query()->delete();
    $fail = true;
    DB::listen(static function (QueryExecuted $query) use (&$fail): void {
        if ($fail && str_contains($query->sql, 'drive_change_heads') && str_starts_with(strtolower($query->sql), 'update')) {
            throw new RuntimeException('epoch update failed');
        }
    });

    expect(fn () => app(AccessInvalidation::class)->changed(null, 'host-role'))->toThrow(RuntimeException::class, 'epoch update failed')
        ->and(invalidations())->toBe([])
        ->and($heads->find($this->driveId)['epoch'])->toBe(1);

    $fail = false;
    app(AccessInvalidation::class)->changed(null, 'host-role');

    expect(array_column(invalidations(), 'reason'))->toBe(['host-role'])
        ->and($heads->find($this->driveId)['epoch'])->toBe(2);
});

it('rolls back the epoch when recording the event fails', function (): void {
    $heads = app(ChangeHeadRepository::class);
    $this->drive->folders()->create('Team');
    DB::listen(static function (QueryExecuted $query): void {
        if (str_contains($query->sql, 'outbox') && str_starts_with(strtolower($query->sql), 'insert')) {
            throw new RuntimeException('event record failed');
        }
    });

    expect(fn () => app(AccessInvalidation::class)->changed(DriveRef::of($this->drive), 'host-policy'))->toThrow(RuntimeException::class, 'event record failed')
        ->and($heads->find($this->driveId)['epoch'])->toBe(1);
});
