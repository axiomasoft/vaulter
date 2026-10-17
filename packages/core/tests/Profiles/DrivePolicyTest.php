<?php

declare(strict_types=1);

use Vaulter\Drive\Exceptions\Policy\FileSizeLimitExceededException;
use Vaulter\Drive\Exceptions\Policy\FolderChildrenLimitExceededException;
use Vaulter\Drive\Exceptions\Policy\TreeDepthLimitExceededException;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Nodes\InvalidPropertiesException;
use Vaulter\Exceptions\Profiles\InvalidProfileException;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Naming\PermissiveNamePolicy;
use Vaulter\Naming\PortableNamePolicy;
use Vaulter\Profiles\DrivePolicyBuilder;
use Vaulter\Profiles\DrivePolicyEnforcer;
use Vaulter\Profiles\Profile;
use Vaulter\Profiles\ProfileRegistry;
use Vaulter\Profiles\UploadPolicy;

function drivePolicyRegistry(Profile ...$profiles): ProfileRegistry
{
    $registry = new ProfileRegistry;
    foreach ($profiles as $profile) {
        $registry->register($profile);
    }
    app()->instance(ProfileRegistry::class, $registry);

    return $registry;
}

function drivePolicyDrive(string $profile): Drive
{
    $drive = new Drive;
    $drive->forceFill(['owner_type' => 'team', 'owner_id' => 1, 'name' => 'Disk', 'profile' => $profile])->save();

    return $drive;
}

function drivePolicyChain(Drive $drive, int $levels): Node
{
    $parent = null;
    for ($level = 0; $level <= $levels; $level++) {
        $node = new Node;
        $node->forceFill(['drive_id' => $drive->getKey(), 'parent_id' => $parent?->getKey(), 'kind' => NodeKind::Folder, 'name' => 'level '.$level])->save();
        $parent = $node;
    }

    return $parent ?? throw new LogicException('No chain.');
}

it('ships the D43 defaults', function (): void {
    $policy = (new Profile)->key('p')->getDrivePolicy();

    expect($policy->namePolicy)->toBe(PortableNamePolicy::KEY)
        ->and($policy->names())->toBeInstanceOf(PortableNamePolicy::class)
        ->and($policy->maxFolderDepth)->toBe(100)
        ->and($policy->maxFolderChildren)->toBe(10_000)
        ->and($policy->maxFileBytes)->toBeNull()
        ->and($policy->maxPropertiesBytes)->toBe(16_384)
        ->and($policy->maxPropertyCount)->toBe(100)
        ->and($policy->maxShareLinkTtlDays)->toBeNull();
});

it('builds a profile drive policy fluently and freezes it with the profile', function (): void {
    $profile = (new Profile)->key('p')->drivePolicy(fn (DrivePolicyBuilder $p) => $p
        ->namePolicy(PermissiveNamePolicy::KEY)->maxFolderDepth(5)->maxFolderChildren(3)
        ->maxFileBytes(1024)->maxPropertiesBytes(200)->maxPropertyCount(2)->maxShareLinkTtlDays(7));
    $registry = drivePolicyRegistry($profile);
    $policy = $registry->get('p')->getDrivePolicy();

    expect($policy->names())->toBeInstanceOf(PermissiveNamePolicy::class)
        ->and([$policy->maxFolderDepth, $policy->maxFolderChildren, $policy->maxFileBytes, $policy->maxPropertiesBytes, $policy->maxPropertyCount, $policy->maxShareLinkTtlDays])
        ->toBe([5, 3, 1024, 200, 2, 7])
        ->and(fn () => $registry->get('p')->drivePolicy(fn (DrivePolicyBuilder $p) => $p->maxFolderDepth(1)))->toThrow(LogicException::class);
});

it('carries a legacy profile upload size into maxFileBytes unless the drive policy sets one', function (): void {
    $legacy = (new Profile)->key('a')->uploads(new UploadPolicy(maxFileSize: 500));
    $explicit = (new Profile)->key('b')->uploads(new UploadPolicy(maxFileSize: 500))->drivePolicy(fn (DrivePolicyBuilder $p) => $p->maxFileBytes(100));

    expect($legacy->getDrivePolicy()->maxFileBytes)->toBe(500)
        ->and($explicit->getDrivePolicy()->maxFileBytes)->toBe(100);
});

it('rejects an invalid drive policy', function (callable $build): void {
    expect(fn () => (new Profile)->key('p')->drivePolicy($build))->toThrow(InvalidProfileException::class);
})->with([
    'unknown name policy' => [fn (DrivePolicyBuilder $p) => $p->namePolicy('windows-v9')],
    'zero depth' => [fn (DrivePolicyBuilder $p) => $p->maxFolderDepth(0)],
    'zero children' => [fn (DrivePolicyBuilder $p) => $p->maxFolderChildren(0)],
    'negative file size' => [fn (DrivePolicyBuilder $p) => $p->maxFileBytes(-1)],
    'zero property count' => [fn (DrivePolicyBuilder $p) => $p->maxPropertyCount(0)],
    'zero ttl' => [fn (DrivePolicyBuilder $p) => $p->maxShareLinkTtlDays(0)],
]);

it('counts trashed children against maxFolderChildren', function (): void {
    drivePolicyRegistry((new Profile)->key('default')->drivePolicy(fn (DrivePolicyBuilder $p) => $p->maxFolderChildren(2)));
    $drive = drivePolicyDrive('default');
    $root = drivePolicyChain($drive, 0);
    $enforcer = app(DrivePolicyEnforcer::class);
    foreach (['a', 'b'] as $name) {
        $child = new Node;
        $child->forceFill(['drive_id' => $drive->getKey(), 'parent_id' => $root->getKey(), 'kind' => NodeKind::Folder, 'name' => $name, 'trashed_at' => $name === 'b' ? now() : null])->save();
    }

    $enforcer->assertChildren($drive, $root->getKey(), adding: 0);
    expect(fn () => $enforcer->assertChildren($drive, $root->getKey()))->toThrow(FolderChildrenLimitExceededException::class);
});

it('refuses only a deeper placement once the depth limit is reached', function (): void {
    drivePolicyRegistry((new Profile)->key('default')->drivePolicy(fn (DrivePolicyBuilder $p) => $p->maxFolderDepth(3)));
    $drive = drivePolicyDrive('default');
    $enforcer = app(DrivePolicyEnforcer::class);
    $atTwo = drivePolicyChain($drive, 2);

    $enforcer->assertDepth($drive, $atTwo);
    expect(fn () => $enforcer->assertDepth($drive, $atTwo, height: 1))->toThrow(TreeDepthLimitExceededException::class)
        // A move that ends no deeper than the subtree already reaches stays allowed.
        ->and(fn () => $enforcer->assertDepth($drive, $atTwo, height: 2, currentDeepest: 5))->not->toThrow(TreeDepthLimitExceededException::class);
});

it('checks a file size against maxFileBytes', function (): void {
    drivePolicyRegistry((new Profile)->key('default')->drivePolicy(fn (DrivePolicyBuilder $p) => $p->maxFileBytes(10)));
    $drive = drivePolicyDrive('default');
    $enforcer = app(DrivePolicyEnforcer::class);

    $enforcer->assertFileSize($drive, 10);
    expect(fn () => $enforcer->assertFileSize($drive, 11))->toThrow(FileSizeLimitExceededException::class);
});

it('patches one namespace and enforces the D41 property contract', function (): void {
    drivePolicyRegistry((new Profile)->key('default')->drivePolicy(fn (DrivePolicyBuilder $p) => $p->maxPropertyCount(3)->maxPropertiesBytes(120)));
    $drive = drivePolicyDrive('default');
    $enforcer = app(DrivePolicyEnforcer::class);
    $reason = static function (callable $patch): ?string {
        try {
            $patch();

            return null;
        } catch (InvalidPropertiesException $exception) {
            return $exception->reason;
        }
    };

    $next = $enforcer->patchProperties($drive, null, 'acme.crm', ['b' => '2', 'a' => '1'], [], system: false);
    expect($next)->toBe(['acme.crm' => ['a' => '1', 'b' => '2']])
        ->and($enforcer->patchProperties($drive, $next, 'acme.crm', [], ['a', 'b'], system: false))->toBeNull()
        ->and($reason(fn () => $enforcer->patchProperties($drive, null, 'Acme', ['a' => '1'], [], false)))->toBe('namespace')
        ->and($reason(fn () => $enforcer->patchProperties($drive, null, 'vaulter.x', ['a' => '1'], [], false)))->toBe('reserved_namespace')
        ->and($enforcer->patchProperties($drive, null, 'vaulter.x', ['a' => '1'], [], true))->toBe(['vaulter.x' => ['a' => '1']])
        ->and($reason(fn () => $enforcer->patchProperties($drive, null, 'acme', ['bad key' => '1'], [], false)))->toBe('key')
        ->and($reason(fn () => $enforcer->patchProperties($drive, null, 'acme', ['k' => 1], [], false)))->toBe('value_too_long')
        ->and($reason(fn () => $enforcer->patchProperties($drive, null, 'acme', ['k' => str_repeat('x', 1025)], [], false)))->toBe('value_too_long')
        ->and($reason(fn () => $enforcer->patchProperties($drive, $next, 'acme.crm', ['c' => '3', 'd' => '4'], [], false)))->toBe('too_many')
        ->and($reason(fn () => $enforcer->patchProperties($drive, null, 'acme', ['k' => str_repeat('x', 200)], [], false)))->toBe('too_large');
});

it('lets a node already over a lowered properties limit shrink', function (): void {
    drivePolicyRegistry((new Profile)->key('default')->drivePolicy(fn (DrivePolicyBuilder $p) => $p->maxPropertyCount(1)));
    $drive = drivePolicyDrive('default');
    $over = ['acme' => ['a' => '1', 'b' => '2', 'c' => '3']];

    expect(app(DrivePolicyEnforcer::class)->patchProperties($drive, $over, 'acme', [], ['c'], system: false))
        ->toBe(['acme' => ['a' => '1', 'b' => '2']]);
});
