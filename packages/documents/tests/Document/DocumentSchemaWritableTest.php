<?php

declare(strict_types=1);

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Actions\Documents\UpdateDocumentContentAction;
use Vaulter\Documents\Actions\Revisions\RestoreDocumentRevisionAction;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentInput;
use Vaulter\Documents\Data\Revisions\RestoreDocumentRevisionInput;
use Vaulter\Documents\Exceptions\DocumentContentDegradedException;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;

/*
 * The sequential half of the writability race: once a revision of a newer schema has been
 * restored, a write that carries no expectation — the case a stale precheck would let
 * through — is refused against the locked document and changes nothing.
 */
beforeEach(function (): void {
    config()->set('vaulter-documents.revisions.checkpoint_interval_seconds', 0);
});

/** A document whose working state was restored from a revision written by a newer schema. */
function restoredNewerSchema(): Node
{
    $drive = DocumentTestHelpers::drive();
    $node = app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($drive->model()), new CreateDocumentInput(
        storage: $drive->model(),
        name: 'Newer schema',
        content: DocumentTestHelpers::content('initial'),
        parent: DriveTestHelpers::model($drive->root()),
    ));
    app(UpdateDocumentContentAction::class)->execute(DocumentTestHelpers::context($node), new UpdateDocumentContentInput(node: $node, content: DocumentTestHelpers::content('newer')));
    $newer = DocumentTestHelpers::head($node);
    app(VaulterDatabase::class)->write(fn () => DocumentRevision::query()->whereKey($newer->getKey())->update(['schema_version' => 99]));
    app(UpdateDocumentContentAction::class)->execute(DocumentTestHelpers::context($node), new UpdateDocumentContentInput(node: $node, content: DocumentTestHelpers::content('current')));
    app(RestoreDocumentRevisionAction::class)->execute(DocumentTestHelpers::context($node), new RestoreDocumentRevisionInput(node: $node, revision: $newer));

    return $node;
}

it('refuses a JSON update without an expectation once a newer schema is the working state', function (): void {
    $node = restoredNewerSchema();
    $before = DocumentTestHelpers::document($node);

    expect(fn () => app(UpdateDocumentContentAction::class)->execute(
        DocumentTestHelpers::context($node), new UpdateDocumentContentInput(node: $node, content: DocumentTestHelpers::content('late edit')),
    ))->toThrow(DocumentContentDegradedException::class);

    $after = DocumentTestHelpers::document($node);
    expect($after->state_version)->toBe($before->state_version)
        ->and($after->schema_version)->toBe(99)
        ->and($after->content_hash)->toBe($before->content_hash)
        ->and($after->revision_count)->toBe($before->revision_count)
        ->and($after->tipTapContent()->toArray())->toBe(DocumentTestHelpers::content('newer')->toArray());
});
