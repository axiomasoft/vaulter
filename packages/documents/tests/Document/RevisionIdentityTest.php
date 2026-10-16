<?php

declare(strict_types=1);

use Vaulter\Data\Drive\UploadSource;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Actions\Revisions\CreateNamedRevisionAction;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Data\Revisions\CreateNamedRevisionInput;
use Vaulter\Documents\Repositories\Revisions\DocumentRevisionReadRepository;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Documents\Tests\Http\DocumentsV1 as Docs;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Testing\Fixtures\TestUser;

/*
 * V32: with ids.strategy = bigint a file revision and a document revision can carry the
 * same id. In 0.2 the node's polymorphic current_version_id resolved a document head
 * id 7 to the FILE revision 7. Heads now live on their own aggregate, so each kind
 * reads its own revision and the node carries no shared pointer.
 */
it('V32 keeps a file revision and a document revision with the same id apart', function (): void {
    expect(config('vaulter.ids.strategy'))->toBe('bigint');
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
    $drive = DocumentTestHelpers::drive();

    $document = app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($drive->model()), new CreateDocumentInput(
        storage: $drive->model(),
        name: 'Spec',
        content: DocumentTestHelpers::content('document body'),
        parent: DriveTestHelpers::model($drive->root()),
    ));
    $file = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('file bytes'), 'report.txt'));

    // Advance whichever head is behind until both kinds have a head revision with the
    // same id — the collision 0.2 could not tell apart.
    $fileHead = fn (): int => (int) DriveTestHelpers::model($file)->file()->firstOrFail()->current_revision_id;
    $documentHead = fn (): int => (int) DocumentTestHelpers::document($document)->current_revision_id;
    $bytes = 'file bytes';
    for ($round = 0; $fileHead() !== $documentHead(); $round++) {
        expect($round)->toBeLessThan(20);
        if ($fileHead() < $documentHead()) {
            $bytes = 'file bytes '.$round;
            $drive->files()->addRevision($file, UploadSource::fromStream(DriveTestHelpers::streamFor($bytes), 'report.txt'));
        } else {
            app(CreateNamedRevisionAction::class)->execute(DocumentTestHelpers::context($document), new CreateNamedRevisionInput(node: $document, label: 'advance'));
        }
    }
    $id = $fileHead();

    $stream = $drive->files()->read($file);
    expect(stream_get_contents($stream->stream))->toBe($bytes)
        ->and($stream->revision->id)->toBe((string) $id)
        ->and(app(DocumentRevisionReadRepository::class)->head($document)?->tipTapContent()->toArray())
        ->toBe(DocumentTestHelpers::content('document body')->toArray())
        ->and(app(DocumentRevisionReadRepository::class)->head($document)?->id)->toBe($id)
        ->and(app(DocumentRevisionReadRepository::class)->forNode(DriveTestHelpers::model($file), $id))->toBeNull();

    $this->getJson(Docs::url('documents/'.$document->id.'/content'))
        ->assertOk()
        ->assertJsonPath('data.revision_id', (string) $id)
        ->assertJsonPath('data.content.content.0.content.0.text', 'document body');

    foreach ([$document->refresh(), DriveTestHelpers::model($file)] as $node) {
        expect($node->getAttributes())->not->toHaveKey('current_version_id')
            ->and($node->getAttributes())->not->toHaveKey('file_id');
    }
});
