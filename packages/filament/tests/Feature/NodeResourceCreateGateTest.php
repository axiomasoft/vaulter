<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Filament\Resources\NodeResource;
use Vaulter\Filament\Tests\Fixtures\DriveBuilder;
use Vaulter\Profiles\Profile;
use Vaulter\Profiles\ProfileRegistry;

it('applies the selected create gate to folder options and destination assertion', function (bool $allow): void {
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
    $assert = new ReflectionMethod(NodeResource::class, 'assertCanCreateIn');
    if ($allow) {
        expect($options->invoke(null, $node))->not->toBe([]);
        $assert->invoke(null, $node, null);
    } else {
        expect($options->invoke(null, $node))->toBe([])
            ->and(fn () => $assert->invoke(null, $node, null))->toThrow(PermissionDeniedException::class);
    }
})->with([true, false]);
