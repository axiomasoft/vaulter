<?php

declare(strict_types=1);

use Vaulter\Collaboration\VaulterCollaborationServiceProvider;
use Vaulter\Contracts\Routing\RouteLabelSource;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Drive\Tenancy\TenancyBoundary;
use Vaulter\Http\Middleware\VaulterHttpOperationMap;
use Vaulter\Http\Middleware\VaulterThrottledRouteMap;
use Vaulter\Kernel;
use Vaulter\Modules\SlotKind;
use Vaulter\Testing\Concerns\FreshApplication;
use Vaulter\Testing\Concerns\WithoutDatabaseSchema;
use Vaulter\Tests\TestCase;

uses(TestCase::class, WithoutDatabaseSchema::class);

// The kernel labels the metrics of every versioned route but names none of them: the active modules contribute their
// routes to the RouteLabelSource slot (P4.16), and a module that is switched off contributes nothing.

it('defines the RouteLabelSource slot in the kernel as a contributor slot', function (): void {
    $definitions = FreshApplication::boot()->make(Kernel::class)->compiled()->slots;

    expect($definitions)->toHaveKey(RouteLabelSource::class)
        ->and($definitions[RouteLabelSource::class]->kind)->toBe(SlotKind::Contributor);
});

it('labels the routes of every active module from its own contribution', function (): void {
    $app = FreshApplication::boot(['vaulter.modules.collaboration' => true], after: [VaulterDocumentsServiceProvider::class, VaulterCollaborationServiceProvider::class]);
    $operations = $app->make(VaulterHttpOperationMap::class);
    $throttled = $app->make(VaulterThrottledRouteMap::class);

    expect($operations->operationFor('vaulter.v1.nodes.show'))->toBe('point_read')
        ->and($operations->operationFor('vaulter.v1.documents.content.update'))->toBe('doc_write')
        ->and($operations->operationFor('vaulter.v1.collaboration.hub.store'))->toBe('collab_persist')
        ->and($operations->operationFor('vaulter.v1.no-such-route'))->toBeNull()
        ->and($throttled->routeFor('vaulter.v1.uploads.store'))->toBe('uploads.tus_create')
        ->and($throttled->routeFor('vaulter.v1.collaboration.hub.authenticate'))->toBe('collab.authenticate')
        ->and($throttled->routeFor('vaulter.v1.nodes.show'))->toBeNull();
});

it('resolves a path for a collector that sees only a URI, the first matching rule winning', function (): void {
    $operations = FreshApplication::boot(after: [VaulterDocumentsServiceProvider::class])->make(VaulterHttpOperationMap::class);

    expect($operations->resolve('GET', 'v1/files/01HX/download'))->toBe('download')
        ->and($operations->resolve('get', '/v1/files/01HX/revisions/9/download/'))->toBe('download')
        ->and($operations->resolve('PATCH', 'v1/uploads/abc'))->toBe('tus_patch')
        ->and($operations->resolve('PUT', 'v1/documents/9/content'))->toBe('doc_write')
        ->and($operations->resolve('DELETE', 'v1/nodes/9/comments/3'))->toBe('comment')
        ->and($operations->resolve('GET', 'v1/files/01HX'))->toBeNull()
        ->and($operations->resolve('PUT', 'v1/files/01HX/download'))->toBeNull();
});

it('labels nothing of a module that is switched off, and binds none of its services', function (): void {
    $app = FreshApplication::boot(['vaulter.modules' => ['drive' => false]], after: [VaulterDocumentsServiceProvider::class]);
    $operations = $app->make(VaulterHttpOperationMap::class);

    expect($operations->operationFor('vaulter.v1.nodes.show'))->toBeNull()
        ->and($operations->resolve('GET', 'v1/files/01HX/download'))->toBeNull()
        ->and($app->make(VaulterThrottledRouteMap::class)->routeFor('vaulter.v1.uploads.store'))->toBeNull()
        ->and($app->bound(TenancyBoundary::class))->toBeFalse();
});
