<?php

declare(strict_types=1);

use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Database\WriteScope;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Exceptions\Database\WriteOutsideOperationException;

it('opens the write scope only for document operations and bounded fixtures', function (): void {
    config()->set('vaulter.database.write_guard', true);
    $scope = app(WriteScope::class);
    expect($scope->isOpen())->toBeFalse();
    $drive = DocumentTestHelpers::drive();
    $documents = Documents::in($drive);
    $node = $documents->create('Guarded', content: DocumentTestHelpers::content('First')->toArray());
    $model = DriveTestHelpers::model($node);
    $directWrite = fn () => Document::query()->where('node_id', $node->id)->update(['state_version' => 100]);

    expect($scope->isOpen())->toBeFalse()
        ->and($directWrite)->toThrow(WriteOutsideOperationException::class);
    app(VaulterDatabase::class)->write(fn () => Document::query()->where('node_id', $node->id)->update(['state_version' => 2]));
    expect($scope->isOpen())->toBeFalse()
        ->and($directWrite)->toThrow(WriteOutsideOperationException::class);

    $documents->update($node->id, DocumentTestHelpers::content('Second')->toArray());
    expect($scope->isOpen())->toBeFalse()
        ->and(DocumentTestHelpers::document($model)->state_version)->toBe(3)
        ->and($directWrite)->toThrow(WriteOutsideOperationException::class);

    $reader = Documents::in($drive->actingAs(AccessTestHelpers::user(99)));
    AccessTestHelpers::grant($model, RoleRegistry::READER, subjectId: 99);
    expect(fn () => $reader->update($node->id, DocumentTestHelpers::content('Denied')->toArray()))->toThrow(PermissionDeniedException::class)
        ->and($scope->isOpen())->toBeFalse()
        ->and($directWrite)->toThrow(WriteOutsideOperationException::class);
});
