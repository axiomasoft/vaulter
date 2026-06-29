<?php

declare(strict_types=1);

use Vaulter\Filament\Resources\DocumentResource;
use Vaulter\Filament\Resources\FolderResource;
use Vaulter\Filament\Resources\ShareResource;
use Vaulter\Filament\Tests\Fixtures\CustomDocument;
use Vaulter\Filament\VaulterPlugin;
use Vaulter\Models\Document;
use Vaulter\Models\DocumentFolder;
use Vaulter\Models\DocumentShare;

it('resolves the configured models for every resource', function (): void {
    expect(DocumentResource::getModel())->toBe(Document::class)
        ->and(FolderResource::getModel())->toBe(DocumentFolder::class)
        ->and(ShareResource::getModel())->toBe(DocumentShare::class);
});

it('marks share links as not creatable in the panel', function (): void {
    expect(ShareResource::canCreate())->toBeFalse();
});

it('respects a swapped document model', function (): void {
    config()->set('vaulter.models.document', CustomDocument::class);

    expect(DocumentResource::getModel())->toBe(CustomDocument::class);
});

it('identifies the plugin', function (): void {
    expect(VaulterPlugin::make()->getId())->toBe('vaulter');
});

it('loads the package config', function (): void {
    expect(config('vaulter-filament.navigation_group'))->toBe('Vaulter');
});
