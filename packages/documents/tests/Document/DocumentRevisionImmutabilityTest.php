<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\MassAssignmentException;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Actions\Revisions\UpdateRevisionRetentionAction;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Data\Revisions\UpdateRevisionRetentionInput;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Repositories\Revisions\DocumentRevisionStoreRepository;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;

/*
 * A checkpoint is immutable — after INSERT only keep_forever changes, so a
 * revision id always names the same bytes. The model refuses any other update, the
 * store repository has no general update, and setRetention is the one mutator.
 */
function immutableCheckpoint(): DocumentRevision
{
    $drive = DocumentTestHelpers::drive();
    $node = app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($drive->model()), new CreateDocumentInput(
        storage: $drive->model(),
        name: 'Immutable '.bin2hex(random_bytes(3)),
        content: DocumentTestHelpers::content('frozen'),
        parent: DriveTestHelpers::model($drive->root()),
    ));

    return DocumentTestHelpers::head($node);
}

it('refuses to change any payload or provenance column of a stored checkpoint', function (string $column, mixed $value): void {
    $revision = immutableCheckpoint();
    $before = $revision->getAttributes();

    expect(fn () => $revision->forceFill([$column => $value])->save())->toThrow(LogicException::class, 'immutable');
    expect(DocumentRevision::query()->whereKey($revision->id)->firstOrFail()->getAttributes())->toEqual($before);
})->with([
    'content' => ['content', ['type' => 'doc', 'content' => []]],
    'content_hash' => ['content_hash', str_repeat('0', 64)],
    'collab_state' => ['collab_state', "rewritten\0state"],
    'number' => ['number', 99],
    'label' => ['label', 'renamed'],
    'is_autosave' => ['is_autosave', true],
    'state_version' => ['state_version', 42],
    'document_id' => ['document_id', 999],
]);

it('changes keep_forever through setRetention, the one allowed mutation', function (): void {
    $revision = immutableCheckpoint();

    app(VaulterDatabase::class)->write(fn () => app(DocumentRevisionStoreRepository::class)->setRetention($revision, keepForever: true));
    expect($revision->refresh()->keep_forever)->toBeTrue();

    app(UpdateRevisionRetentionAction::class)->execute(DocumentTestHelpers::context($revision->document->node), new UpdateRevisionRetentionInput(node: $revision->document->node, revision: $revision->id, keepForever: false));
    expect($revision->refresh()->keep_forever)->toBeFalse();
});

it('accepts no mass assignment at all', function (): void {
    expect(fn () => (new DocumentRevision)->fill(['content' => ['type' => 'doc']]))->toThrow(MassAssignmentException::class);
});

it('exposes no general update on the checkpoint store', function (): void {
    $methods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(DocumentRevisionStoreRepository::class))->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    expect($methods)->toEqualCanonicalizing(['__construct', 'create', 'setRetention', 'prune']);
});
