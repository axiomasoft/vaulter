<?php

declare(strict_types=1);

use Vaulter\Facades\Vaulter;
use Vaulter\VaulterManager;

it('merges the vaulter config', function (): void {
    expect(config('vaulter.route_prefix'))->toBe('api/vaulter')
        ->and(config('vaulter.enabled_document_types'))->toContain('richtext');
});

it('binds the manager as a singleton', function (): void {
    expect(app('vaulter'))->toBeInstanceOf(VaulterManager::class)
        ->and(app('vaulter'))->toBe(app(VaulterManager::class));
});

it('registers the API routes', function (): void {
    $uris = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->uri());

    expect($uris)
        ->toContain('api/vaulter/documents')
        ->toContain('api/vaulter/documents/{documentId}/versions')
        ->toContain('api/vaulter/folders');
});

it('exposes the Vaulter facade', function (): void {
    Vaulter::registerDocumentType('spreadsheet', ['label' => 'Spreadsheet']);

    expect(Vaulter::documentTypes())->toHaveKey('spreadsheet')
        ->and(Vaulter::permissionResolver())->toBeNull();
});
