<?php

declare(strict_types=1);

use Vaulter\Access\Abilities\DocumentAbilities;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Documents\Document;
use Vaulter\Tests\Fixtures\ViewOnlyResolver;
use Vaulter\VaulterManager;

it('computes per-document abilities from the resolver', function (): void {
    Vaulter::resolvePermissionsWith(new ViewOnlyResolver);
    $document = Document::factory()->create();

    $abilities = DocumentAbilities::for(null, $document, app(VaulterManager::class));

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

    $abilities = DocumentAbilities::for(null, $document, app(VaulterManager::class));

    expect($abilities->view)->toBeTrue()
        ->and($abilities->manage)->toBeTrue();
});
