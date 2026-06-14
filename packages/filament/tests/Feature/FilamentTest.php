<?php

declare(strict_types=1);

use DocVault\Filament\DocVaultPlugin;
use DocVault\Filament\Resources\DocumentResource;
use DocVault\Filament\Resources\FolderResource;
use DocVault\Filament\Resources\ShareResource;
use DocVault\Filament\Tests\Fixtures\CustomDocument;
use DocVault\Models\Document;
use DocVault\Models\DocumentFolder;
use DocVault\Models\DocumentShare;

it('resolves the configured models for every resource', function (): void {
    expect(DocumentResource::getModel())->toBe(Document::class)
        ->and(FolderResource::getModel())->toBe(DocumentFolder::class)
        ->and(ShareResource::getModel())->toBe(DocumentShare::class);
});

it('marks share links as not creatable in the panel', function (): void {
    expect(ShareResource::canCreate())->toBeFalse();
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
