<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Models\Drive\Node;

/**
 * F-P1.12-02, on real PostgreSQL only (SQLite does not enforce the FK
 * cascadeOnDelete this fix relies on): vaulter:purge-trash past retention must
 * PHYSICALLY remove the node's inline document-version content
 * (v_node_document_versions.content/yjs_state), not merely soft-delete the node —
 * before this fix, that content lived in the database forever.
 */
beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('FK cascadeOnDelete is not enforced on the default SQLite test connection.');
    }

    config()->set('vaulter.trash.retention_days', 30);
});

it('physically deletes a trashed document node\'s inline version content on purge, F-P1.12-02', function (): void {
    $drive = DocumentTestHelpers::drive();

    $node = app(CreateDocumentAction::class)->execute(new CreateDocumentInput(
        storage: $drive->model(),
        name: 'Sensitive',
        content: DocumentTestHelpers::content('erase me'),
        parent: $drive->root(),
    ));

    expect(DocumentRevision::query()->where('node_id', $node->getKey())->count())->toBe(1);

    $node->refresh()->forceFill(['trashed_at' => Carbon::now()->subDays(40)])->save();

    $this->artisan('vaulter:purge-trash')->assertSuccessful();

    // The node row and every v_node_document_versions row that FK-cascades off it
    // are physically gone — the inline content does not survive purge.
    expect(Node::withTrashed()->whereKey($node->getKey())->exists())->toBeFalse()
        ->and(DocumentRevision::query()->where('node_id', $node->getKey())->count())->toBe(0);
});
