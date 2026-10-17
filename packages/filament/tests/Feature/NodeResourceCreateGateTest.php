<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Facades\Vaulter;
use Vaulter\Filament\Resources\NodeResource;
use Vaulter\Filament\Tests\Fixtures\DriveBuilder;
use Vaulter\Models\Drive\Node;
use Vaulter\Profiles\Profile;
use Vaulter\Profiles\ProfileRegistry;

it('applies the selected profile create gate to folder options and canonical folder creation', function (bool $allow): void {
    $this->actingAs(DriveBuilder::user());
    $drive = DriveBuilder::storage();
    $node = DriveBuilder::folder($drive, 'source');
    config()->set('vaulter.access.permission_driver', 'database');
    $registry = new ProfileRegistry;
    $registry->register((new Profile)->key('default')->permissionDriver('gate'));
    $registry->freeze();
    app()->instance(ProfileRegistry::class, $registry);
    Gate::define('vaulter.manage', static fn (): bool => true);
    Gate::define('vaulter.create', static fn ($actor, array $context): bool => $allow && $context['profile'] === 'default');
    $options = new ReflectionMethod(NodeResource::class, 'folderOptions');
    $create = static fn (): NodeSnapshot => Vaulter::drive($drive)->folders()->create('child');
    $handle = Vaulter::drive($drive)->actingAs(DriveBuilder::user());
    expect($handle->nodes()->abilities($drive->root_node_id)->flags['create'])->toBe($allow)
        ->and($handle->nodes()->abilitiesMany([$drive->root_node_id])[(string) $drive->root_node_id]->flags['create'])->toBe($allow);
    if ($allow) {
        expect($options->invoke(null, DriveBuilder::row($node)))->not->toBe([]);
        expect($create()->parentId)->toEqual((string) $drive->root_node_id);
    } else {
        expect($options->invoke(null, DriveBuilder::row($node)))->toBe([]);
        $exception = rescue($create, static fn (Throwable $exception): Throwable => $exception, report: false);
        expect($exception)->toBeInstanceOf(PermissionDeniedException::class)
            ->and($exception->httpStatus())->toBe(403);
        expect(Node::query()->where('name', 'child')->exists())->toBeFalse();
    }
})->with([true, false]);
