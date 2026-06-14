<?php

declare(strict_types=1);

use DocVault\Filament\DocVaultPlugin;
use DocVault\Filament\Resources\DocumentResource;
use DocVault\Filament\Tests\Fixtures\CustomDocument;
use DocVault\Models\Document;

it('resolves the configured document model', function (): void {
    expect(DocumentResource::getModel())->toBe(Document::class);
});

it('respects a swapped document model', function (): void {
    config()->set('docvault.models.document', CustomDocument::class);

    expect(DocumentResource::getModel())->toBe(CustomDocument::class);
});

it('identifies the plugin', function (): void {
    expect(DocVaultPlugin::make()->getId())->toBe('docvault');
});

it('loads the package config', function (): void {
    expect(config('docvault-filament.navigation_group'))->toBe('DocVault');
});
