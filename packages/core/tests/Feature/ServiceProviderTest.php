<?php

declare(strict_types=1);

use DocVault\DocVaultManager;
use DocVault\Facades\DocVault;

it('merges the docvault config', function (): void {
    expect(config('docvault'))->toBeArray()
        ->and(config('docvault.route_prefix'))->toBe('api/docvault')
        ->and(config('docvault.document_types'))->toContain('richtext');
});

it('resolves the DocVault manager as a singleton', function (): void {
    expect(app('docvault'))->toBeInstanceOf(DocVaultManager::class)
        ->and(app('docvault'))->toBe(app(DocVaultManager::class));
});

it('registers the document CRUD routes', function (): void {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->uri());

    expect($routes)->toContain('api/docvault/documents');
});

it('exposes the DocVault facade', function (): void {
    DocVault::registerDocumentType('spreadsheet', ['label' => 'Spreadsheet']);

    expect(DocVault::documentTypes())->toHaveKey('spreadsheet')
        ->and(DocVault::permissionResolver())->toBeNull();
});
