<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Contracts\Tenancy\ResolvesOwnerTenant;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Access\AccessResolver;
use Vaulter\Drive\Data\Sharing\ShareLinkInput;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Repositories\DriveReadRepository;
use Vaulter\Drive\Repositories\NodeReadRepository;
use Vaulter\Drive\Sharing\ShareResolver;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Exceptions\Tenancy\TenancyMisconfiguredException;
use Vaulter\Facades\Vaulter;
use Vaulter\Http\Support\HttpResourceResolver;
use Vaulter\Tenancy\TenancyBoundary;
use Vaulter\Testing\Tenancy\MutableTenantResolver;
use Vaulter\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('vaulter.tenancy.enabled', true);
    config()->set('vaulter.tenancy.resolver', MutableTenantResolver::class);
    app()->instance(TenantResolver::class, new MutableTenantResolver);
});

afterEach(fn () => MutableTenantResolver::clearFixtureStamps());

it('V31 stamps authoritative drive and children and retained system handles recheck A B A', function (): void {
    $handle = Vaulter::drive(DriveTestHelpers::host())->asSystem('fixture');
    $drive = $handle->model();
    $root = $handle->root();
    expect($drive->tenant_id)->toBe('A')->and(Node::query()->findOrFail($root->id)->tenant_id)->toBe('A');
    app(TenantResolver::class)->tenant = 'B';
    expect(fn () => $handle->root())->toThrow(NodeNotFoundException::class)
        ->and(fn () => Vaulter::drive($drive))->toThrow(NodeNotFoundException::class);
    app(TenantResolver::class)->tenant = 'A';
    expect($handle->root()->id)->toBe($root->id);
});

it('V31 denies mutated supplied drive and node models and cached repository authority', function (): void {
    $a = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($a, null, 'in-a');
    app(DriveReadRepository::class)->find($a->id);
    app(TenantResolver::class)->tenant = 'B';
    $b = AccessTestHelpers::storage();
    $a->tenant_id = 'B';
    $node->drive_id = $b->id;
    $node->tenant_id = 'B';
    $node->setRelation('storage', $b);
    expect(app(DriveReadRepository::class)->find($a->id))->toBeNull()
        ->and(fn () => Vaulter::drive($a))->toThrow(NodeNotFoundException::class)
        ->and(fn () => app(AccessResolver::class)->can(AccessTestHelpers::user(), Ability::View, $node))->toThrow(NodeNotFoundException::class);
});

it('V31 constrains HTTP lookup search shared and trash reads to the owning drive tenant', function (): void {
    $a = AccessTestHelpers::storage();
    $nodeA = AccessTestHelpers::folder($a, null, 'secret');
    app(TenantResolver::class)->tenant = 'B';
    $b = AccessTestHelpers::storage();
    $nodeB = AccessTestHelpers::folder($b, null, 'visible');
    $read = app(NodeReadRepository::class);
    expect($read->find($nodeA->id))->toBeNull()->and($read->find($nodeB->id)?->id)->toBe($nodeB->id)
        ->and($read->queryForUser(AccessTestHelpers::user())->pluck('id')->all())->not->toContain($nodeA->id)
        ->and($read->sharedWith(AccessTestHelpers::user())->pluck('id')->all())->not->toContain($nodeA->id)
        ->and(fn () => app(HttpResourceResolver::class)->driveOfNode((string) $nodeA->id))->toThrow(NodeNotFoundException::class);
    $this->actingAs(AccessTestHelpers::user());
    $this->getJson('api/vaulter/v1/nodes/'.$nodeA->id)->assertNotFound();
    $this->getJson('api/vaulter/v1/nodes/'.$nodeB->id)->assertOk();
});

it('V31 refuses a foreign existing owner profile winner without creating another drive', function (): void {
    $owner = DriveTestHelpers::host();
    $a = Vaulter::drive($owner)->model();
    app(TenantResolver::class)->tenant = 'B';
    expect(fn () => Vaulter::findDrive($owner))->toThrow(NodeNotFoundException::class)
        ->and(fn () => Vaulter::drive($owner))->toThrow(NodeNotFoundException::class);
    expect($a->newQuery()->count())->toBe(1);
});

it('V31 null refuses current authority while normalized tenant ids retain leading zeroes', function (): void {
    $resolver = app(TenantResolver::class);
    $resolver->tenant = '001';
    $drive = AccessTestHelpers::storage();
    $resolver->tenant = 1;
    expect(fn () => Vaulter::drive($drive))->toThrow(NodeNotFoundException::class);
    $resolver->tenant = null;
    expect(fn () => Vaulter::drive($drive))->toThrow(TenancyMisconfiguredException::class);
    expect(TenancyBoundary::normalize(1))->toBe('1')->and(TenancyBoundary::normalize('001'))->toBe('001')->and(TenancyBoundary::normalize(null))->toBeNull();
});

it('I15 restores nested maintenance exceptions without carrying bypass into a Fiber', function (): void {
    $a = AccessTestHelpers::storage();
    app(TenantResolver::class)->tenant = 'B';
    expect(fn () => Vaulter::withoutTenancy(function () use ($a): void {
        expect(Node::query()->findOrFail(Vaulter::drive($a)->asSystem('maintenance')->root()->id)->tenant_id)->toBe('A');
        $fiber = new Fiber(function () use ($a): void {
            expect(fn () => Vaulter::drive($a))->toThrow(NodeNotFoundException::class);
        });
        $fiber->start();
        Vaulter::withoutTenancy(fn () => throw new RuntimeException('nested'));
    }))->toThrow(RuntimeException::class, 'nested');
    expect(fn () => Vaulter::drive($a))->toThrow(NodeNotFoundException::class);
});

it('I15 maintenance keeps ACL checks and authoritative child consistency', function (): void {
    config()->set('vaulter.access.permission_driver', 'database');
    $a = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($a, null, 'secret');
    app(TenantResolver::class)->tenant = 'B';
    Vaulter::withoutTenancy(function () use ($a, $node): void {
        expect(fn () => Vaulter::drive($a)->actingAs(AccessTestHelpers::user(99))->root())->toThrow(NodeNotFoundException::class);
        $node->forceFill(['tenant_id' => 'wrong'])->save();
        expect(fn () => Vaulter::drive($a)->asSystem('maintenance')->nodes()->find($node->id))->toThrow(NodeNotFoundException::class);
    });
});

it('V31 optional owner tenant resolver rereads owner on both provision and existing lookup', function (): void {
    $connection = app(VaulterDatabase::class)->connection();
    $connection->getSchemaBuilder()->create('p38_owners', function (Blueprint $table): void {
        $table->id();
        $table->string('tenant_id');
    });
    $owner = new class extends Model
    {
        public $timestamps = false;

        protected $table = 'p38_owners';

        protected $guarded = [];
    };
    $owner->forceFill(['tenant_id' => 'A'])->save();
    $resolver = new class implements ResolvesOwnerTenant, TenantResolver
    {
        public function current(): int|string|null
        {
            return 'A';
        }

        public function tenantOf(Model $owner): int|string|null
        {
            return $owner->getAttribute('tenant_id');
        }
    };
    app()->instance(TenantResolver::class, $resolver);
    $drive = Vaulter::drive($owner)->model();
    $connection->table('p38_owners')->where('id', $owner->id)->update(['tenant_id' => 'B']);
    $owner->tenant_id = 'A';
    expect(fn () => Vaulter::findDrive($owner))->toThrow(NodeNotFoundException::class)
        ->and(fn () => Vaulter::drive($owner))->toThrow(NodeNotFoundException::class);
    expect($drive->newQuery()->count())->toBe(1);
});

it('V31 anonymous bearer shares derive only their target tenant and do not open a foreign system handle', function (): void {
    config()->set('vaulter.features.share_links', true);
    $a = Vaulter::drive(DriveTestHelpers::host())->asSystem('fixture');
    $rootA = $a->root();
    $childA = AccessTestHelpers::folder($a->model(), $rootA, 'shared child');
    $link = $a->shares()->create($rootA, new ShareLinkInput(scope: GrantScope::Subtree));
    $driveA = $a->model();
    app(TenantResolver::class)->tenant = 'B';
    $b = AccessTestHelpers::storage();
    $nodeB = AccessTestHelpers::folder($b, null, 'foreign child');
    app(TenantResolver::class)->tenant = null;
    $result = app(ShareResolver::class)->resolve($link->token);
    expect($result->context?->node->id)->toEqual($rootA->id);
    $this->getJson('api/vaulter/v1/shares/'.$link->token)->assertOk();
    $this->getJson('api/vaulter/v1/shares/'.$link->token.'/nodes')->assertOk()->assertJsonFragment(['name' => 'shared child'])->assertJsonMissing(['name' => 'foreign child']);
    app(TenantResolver::class)->tenant = 'B';
    expect(fn () => Vaulter::drive($driveA))->toThrow(NodeNotFoundException::class);
    AccessTestHelpers::shareRow($link)->forceFill(['tenant_id' => 'B'])->save();
    $this->getJson('api/vaulter/v1/shares/'.$link->token)->assertNotFound();
});

it('V31 F1 binds optional owner validation lookup and insert to one persisted identity', function (): void {
    $db = app(VaulterDatabase::class)->connection();
    $db->getSchemaBuilder()->create('p38_binding_owners', function (Blueprint $table): void {
        $table->id();
        $table->string('tenant_id');
        $table->string('morph')->nullable();
    });
    $owner = new class extends Model
    {
        public $timestamps = false;

        protected $table = 'p38_binding_owners';

        protected $guarded = [];

        public function getMorphClass(): string
        {
            return $this->getAttribute('morph') ?? parent::getMorphClass();
        }
    };
    $owner->forceFill(['tenant_id' => 'A'])->save();
    $foreign = $owner->newInstance(['tenant_id' => 'B']);
    $foreign->save();
    $original = $owner->getKey();
    app()->instance(TenantResolver::class, new class implements ResolvesOwnerTenant, TenantResolver
    {
        public function current(): int|string|null
        {
            return 'A';
        }

        public function tenantOf(Model $model): int|string|null
        {
            return $model->getAttribute('tenant_id');
        }
    });
    $owner->setAttribute($owner->getKeyName(), $foreign->getKey());
    expect(fn () => Vaulter::drive($owner))->toThrow(NodeNotFoundException::class)
        ->and(fn () => Vaulter::findDrive($owner))->toThrow(NodeNotFoundException::class)
        ->and(fn () => app(DriveReadRepository::class)->forOwner($owner, 'default'))->toThrow(NodeNotFoundException::class);
    $driveClass = config('vaulter.models.drive');
    expect($driveClass::query()->count())->toBe(0);
    $owner->setAttribute($owner->getKeyName(), $original);
    $drive = Vaulter::drive($owner)->model();
    expect((string) $drive->owner_id)->toBe((string) $original);
    $owner->setAttribute('morph', 'foreign-owner');
    expect(fn () => Vaulter::drive($owner))->toThrow(NodeNotFoundException::class)
        ->and(fn () => Vaulter::findDrive($owner))->toThrow(NodeNotFoundException::class);
    Vaulter::withoutTenancy(function () use ($owner): void {
        expect(fn () => Vaulter::drive($owner))->toThrow(NodeNotFoundException::class);
    });
    expect($driveClass::query()->count())->toBe(1);
});

it('V31 F2 rejects mismatched descendant ancestor breadcrumb and ACL tree stamps', function (): void {
    $drive = AccessTestHelpers::storage();
    $root = AccessTestHelpers::folder($drive, null, 'root');
    $parent = AccessTestHelpers::folder($drive, $root, 'parent');
    $leaf = AccessTestHelpers::folder($drive, $parent, 'leaf');
    $parent->newQuery()->whereKey($parent->id)->update(['tenant_id' => 'B']);
    $read = app(NodeReadRepository::class);
    foreach ([fn () => $read->descendants($root), fn () => $read->subtree($root),
        fn () => $read->ancestors($leaf), fn () => $read->breadcrumb($leaf),
        fn () => $read->ancestorChains([$leaf->id])] as $walk) {
        expect($walk)->toThrow(NodeNotFoundException::class);
    }
    config()->set('vaulter.access.permission_driver', 'database');
    expect(fn () => app(AccessResolver::class)->can(AccessTestHelpers::user(), Ability::View, $leaf))->toThrow(NodeNotFoundException::class);
    Vaulter::withoutTenancy(function () use ($read, $root): void {
        expect(fn () => $read->subtree($root))->toThrow(NodeNotFoundException::class);
    });
    $parent->newQuery()->whereKey($parent->id)->update(['tenant_id' => 'A']);
    expect($read->descendants($root))->toHaveCount(2)
        ->and($read->subtree($root))->toHaveCount(3)
        ->and($read->ancestors($leaf))->toHaveCount(3)
        ->and($read->breadcrumb($leaf))->toHaveCount(4)
        ->and($read->ancestorChains([$leaf->id]))->toHaveCount(1);
});
