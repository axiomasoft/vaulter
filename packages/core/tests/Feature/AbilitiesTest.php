<?php

declare(strict_types=1);

use DocVault\Abilities\DocumentAbilities;
use DocVault\DocVaultManager;
use DocVault\Facades\DocVault;
use DocVault\Models\Document;
use DocVault\Tests\Fixtures\ViewOnlyResolver;

it('computes per-document abilities from the resolver', function (): void {
    DocVault::resolvePermissionsWith(new ViewOnlyResolver);
    $document = Document::factory()->create();

    $abilities = DocumentAbilities::for(null, $document, app(DocVaultManager::class));

    expect($abilities->toArray())->toBe([
        'view' => false,
        'comment' => false,
        'edit' => false,
        'manage' => false,
        'delete' => false,
    ]);
});

it('grants all abilities when no resolver is configured', function (): void {
    $document = Document::factory()->create();

    $abilities = DocumentAbilities::for(null, $document, app(DocVaultManager::class));

    expect($abilities->view)->toBeTrue()
        ->and($abilities->manage)->toBeTrue();
});
