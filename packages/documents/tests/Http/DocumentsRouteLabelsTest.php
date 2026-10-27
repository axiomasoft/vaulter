<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vaulter\Documents\Http\Support\DocumentsRouteLabels;
use Vaulter\Http\Middleware\VaulterHttpOperationMap;
use Vaulter\Http\Middleware\VaulterThrottledRouteMap;

it('labels only routes the documents module mounts, so a renamed route cannot leave a stale label', function (): void {
    $labels = new DocumentsRouteLabels;

    foreach ([...array_keys($labels->operations()), ...array_keys($labels->throttled())] as $name) {
        expect(Route::has($name))->toBeTrue($name);
    }
});

it('adds the document labels to the ones the drive contributes', function (): void {
    $operations = app(VaulterHttpOperationMap::class);

    expect((new DocumentsRouteLabels)->operations()['vaulter.v1.documents.collaboration.session'])->toBe('collab_issue')
        ->and($operations->operationFor('vaulter.v1.documents.collaboration.session'))->toBe('collab_issue')
        ->and($operations->resolve('POST', 'v1/documents/9/collaboration/session'))->toBe('collab_issue')
        ->and($operations->operationFor('vaulter.v1.documents.export'))->toBe('doc_export')
        ->and($operations->operationFor('vaulter.v1.nodes.show'))->toBe('point_read')
        ->and($operations->resolve('GET', 'v1/documents/9/export/html'))->toBe('doc_export')
        ->and(app(VaulterThrottledRouteMap::class)->routeFor('vaulter.v1.collaboration.hub.store'))->toBeNull();
});
