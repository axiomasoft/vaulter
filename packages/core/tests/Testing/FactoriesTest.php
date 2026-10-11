<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Vaulter\Contracts\Tenancy\ResolvesOwnerTenant;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Tenancy\MutableTenantResolver;

/**
 * The factories create through the same Operations a host calls, so the invariants a
 * request relies on (root, quota, sequence, blobs) hold for every fixture.
 */
it('provisions a drive with its root through the drive owner', function (): void {
    $owner = DriveTestHelpers::host();

    $drive = Drive::factory()->for($owner)->create();

    expect($drive)->toBeInstanceOf(Drive::class)
        ->and($drive->exists)->toBeTrue()
        ->and((string) $drive->owner_id)->toBe((string) $owner->getKey())
        ->and($drive->root_node_id)->not->toBeNull()
        ->and(Node::query()->whereKey($drive->root_node_id)->exists())->toBeTrue();
});

it('refuses a drive without an owner', function (): void {
    Drive::factory()->create();
})->throws(LogicException::class, 'owner');

it('creates folders and files under a drive root or a folder', function (): void {
    $drive = Drive::factory()->for(DriveTestHelpers::host())->create();

    $folder = Node::factory()->folder()->in($drive)->create(['name' => 'Reports']);
    $file = Node::factory()->file('hello')->in($folder)->create(['name' => 'a.txt']);

    expect($folder->kind)->toBe(NodeKind::Folder)
        ->and($folder->name)->toBe('Reports')
        ->and($folder->parent_id)->toBe($drive->root_node_id)
        ->and($file->kind)->toBe(NodeKind::File)
        ->and($file->parent_id)->toBe($folder->getKey())
        ->and($file->size_bytes)->toBe(5)
        ->and($drive->refresh()->used_bytes)->toBe(5)
        ->and(Blob::query()->count())->toBe(1);
});

it('adds a head revision to a file', function (): void {
    $drive = Drive::factory()->for(DriveTestHelpers::host())->create();
    $file = Node::factory()->file('v1')->in($drive)->create();

    $revision = FileRevision::factory()->for($file)->bytes('version two')->create();

    expect($revision)->toBeInstanceOf(FileRevision::class)
        ->and($revision->number)->toBe(2)
        ->and($revision->size_bytes)->toBe(11)
        ->and($drive->refresh()->used_bytes)->toBeGreaterThanOrEqual(11);
});

it('creates distinct names for repeated factory calls', function (): void {
    $drive = Drive::factory()->for(DriveTestHelpers::host())->create();

    $nodes = Node::factory()->count(3)->folder()->in($drive)->create();

    expect($nodes->pluck('name')->unique())->toHaveCount(3);
});

it('refuses to bulk insert, which would skip every Operations invariant', function (): void {
    Drive::factory()->for(DriveTestHelpers::host())->insert([['name' => 'x']]);
})->throws(LogicException::class, 'create()');

it('keeps the owner tenant check when it creates for another tenant', function (): void {
    // A persisted row stands in for the host owner: the check reads it back.
    $owner = Node::factory()->folder()->in(Drive::factory()->for(DriveTestHelpers::host())->create())->create();
    config()->set('vaulter.tenancy.enabled', true);
    $resolver = new class implements ResolvesOwnerTenant, TenantResolver
    {
        public function current(): string
        {
            return 'A';
        }

        public function tenantOf(Model $owner): string
        {
            return 'A';
        }
    };
    app()->instance(TenantResolver::class, $resolver);

    try {
        expect(fn () => Drive::factory()->for($owner)->forTenant('B')->create())->toThrow(NodeNotFoundException::class)
            ->and(app(TenantResolver::class))->toBe($resolver);
        $drive = Drive::factory()->for($owner)->forTenant('A')->create();
        expect($drive->tenant_id)->toBe('A');
    } finally {
        MutableTenantResolver::clearFixtureStamps();
    }
});
