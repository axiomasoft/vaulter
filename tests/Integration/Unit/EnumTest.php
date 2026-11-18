<?php

declare(strict_types=1);

use Vaulter\Collaboration\CollaborationModule;
use Vaulter\Collaboration\HocuspocusCollaborationDriver;
use Vaulter\Documents\Contracts\CollaborationProtocolDriver;
use Vaulter\Modules\ModuleDeclarations;
use Vaulter\Modules\SlotContribution;

it('labels collaboration drivers', function (): void {
    // The adopted protocol keys replace the retired transport enum.
    $module = new CollaborationModule;
    $builder = ModuleDeclarations::buildOffered($module::class);
    $protocols = array_values(array_filter($builder->contributions(), static fn (SlotContribution $contribution): bool => $contribution->slot === CollaborationProtocolDriver::class));

    expect($protocols)->toHaveCount(1)
        ->and($protocols[0]->key)->toBe('yjs')
        ->and($protocols[0]->implementation)->toBe(HocuspocusCollaborationDriver::class);
});
