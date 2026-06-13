<?php

declare(strict_types=1);

use DocVault\DocVaultManager;

it('registers and returns custom document types', function (): void {
    $manager = new DocVaultManager;

    expect($manager->documentTypes())->toBe([]);

    $manager->registerDocumentType('spreadsheet', ['label' => 'Spreadsheet']);

    expect($manager->documentTypes())->toHaveKey('spreadsheet')
        ->and($manager->documentTypes()['spreadsheet'])->toBe(['label' => 'Spreadsheet']);
});

it('starts with no permission resolver', function (): void {
    expect((new DocVaultManager)->permissionResolver())->toBeNull();
});
