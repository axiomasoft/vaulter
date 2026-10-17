<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;

/**
 * F-P1.12-02, on real PostgreSQL only (SQLite does not enforce the FK
 * cascadeOnDelete this fix relies on): vaulter:trash:purge past retention must
 * PHYSICALLY remove the document's working state and checkpoints (v_documents and
 * v_document_revisions content/yjs_state) through the node → document → revision
 * cascades, even though the head FK (RESTRICT) points back from the document into
 * its revisions — before this fix, that content lived in the database forever.
 */
beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('FK cascadeOnDelete is not enforced on the default SQLite test connection.');
    }

    config()->set('vaulter-drive.trash.retention_days', 30);
});

it('physically deletes a trashed document node\'s inline version content on purge, F-P1.12-02', function (): void {
    $drive = DocumentTestHelpers::drive();

    $node = app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($drive->model()), new CreateDocumentInput(
        storage: $drive->model(),
        name: 'Sensitive',
        content: DocumentTestHelpers::content('erase me'),
        parent: DriveTestHelpers::model($drive->root()),
    ));

    $document = DocumentTestHelpers::document($node);
    expect(DocumentRevision::query()->where('document_id', $document->id)->count())->toBe(1);

    app(VaulterDatabase::class)->write(fn () => $node->refresh()->forceFill(['trashed_at' => Carbon::now()->subDays(40), 'trash_root_id' => $node->getKey()])->save());

    $this->artisan('vaulter:trash:purge')->assertSuccessful();

    // The node row, its aggregate and every checkpoint that FK-cascades off it are
    // physically gone — the content does not survive purge.
    expect(Node::query()->whereKey($node->getKey())->exists())->toBeFalse()
        ->and(Document::query()->whereKey($document->id)->exists())->toBeFalse()
        ->and(DocumentRevision::query()->where('document_id', $document->id)->count())->toBe(0);
});
