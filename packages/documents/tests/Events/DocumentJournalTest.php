<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Enums\DocumentEventType;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Documents\Tests\Operations\OperationsTestCase;
use Vaulter\Drive\Enums\Changes\ChangeOperation;
use Vaulter\Events\EventTypeContract;
use Vaulter\Events\Outbox\Models\OutboxEvent;

/**
 * Document events in the drive change journal: every content save is
 * an upsert, compacted to one entry per document, whether or not it took a checkpoint;
 * content events carry no node sequence, a restore is the newest state.
 */
uses(OperationsTestCase::class, DatabaseMigrations::class);

/** @return list<array{seq: int, event_type: string, operation: string, node_sequence: ?int}> */
function documentJournal(int|string $nodeId): array
{
    return app(VaulterDatabase::class)->connection()->table('v_drive_changes')->where('node_id', $nodeId)->orderBy('seq')->get()
        ->map(static fn (object $row): array => [
            'seq' => (int) $row->seq,
            'event_type' => $row->event_type,
            'operation' => $row->operation,
            'node_sequence' => $row->node_sequence === null ? null : (int) $row->node_sequence,
        ])->all();
}

it('compacts every content save of a document into its latest upsert', function (): void {
    config()->set('vaulter-documents.revisions.checkpoint_interval_seconds', 3600);
    $drive = DocumentTestHelpers::drive();
    $documents = Documents::in($drive);
    $node = $documents->create('Spec', content: DocumentTestHelpers::content('one')->toArray());
    $created = documentJournal($node->id);

    // Inside the checkpoint interval: saves without a checkpoint are journalled all the same.
    $documents->update($node, DocumentTestHelpers::content('two')->toArray());
    $documents->update($node, DocumentTestHelpers::content('three')->toArray());

    expect($created)->toHaveCount(1)
        ->and($created[0]['event_type'])->toBe(DocumentEventType::DocumentCreated->value)
        ->and($created[0]['node_sequence'])->not->toBeNull()
        ->and(documentJournal($node->id))->toBe([
            ['seq' => $created[0]['seq'] + 2, 'event_type' => DocumentEventType::DocumentUpdated->value, 'operation' => 'upsert', 'node_sequence' => null],
        ]);
});

it('stamps the checkpoint webhook with the seq of its journal entry', function (): void {
    config()->set('vaulter-documents.revisions.checkpoint_interval_seconds', 0);
    $documents = Documents::in(DocumentTestHelpers::drive());
    $node = $documents->create('Spec', content: DocumentTestHelpers::content('one')->toArray());
    $documents->update($node, DocumentTestHelpers::content('two')->toArray());

    $updated = OutboxEvent::query()->where('type', DocumentEventType::DocumentUpdated->value)->sole();

    expect($updated->payload['changes'])->toBe(['seq' => documentJournal($node->id)[0]['seq']]);
});

it('journals a restore as the newest upsert of the document', function (): void {
    config()->set('vaulter-documents.revisions.checkpoint_interval_seconds', 3600);
    $drive = DocumentTestHelpers::drive();
    $documents = Documents::in($drive);
    $node = $documents->create('Spec', content: DocumentTestHelpers::content('one')->toArray());
    $named = $documents->snapshot($node, 'Named');
    $documents->update($node, DocumentTestHelpers::content('two')->toArray());
    $cursor = $drive->changes()->startCursor();
    $documents->restore($node, $named);

    $entries = documentJournal($node->id);
    $page = $drive->changes()->list($cursor);

    expect(array_column($entries, 'event_type'))->toBe([DocumentEventType::DocumentRestored->value])
        ->and($page->changes)->toHaveCount(1)
        ->and($page->changes[0]->operation)->toBe(ChangeOperation::Upsert)
        ->and($page->changes[0]->node?->id)->toBe((string) $node->id);
});

it('journals exactly the created, updated and restored document types of the catalogue', function (): void {
    $journalled = array_values(array_filter(DocumentEventType::cases(), static fn (EventTypeContract $type): bool => $type->spec()->journal !== null));

    expect(array_map(static fn (EventTypeContract $type): string => (string) $type->value, $journalled))
        ->toEqualCanonicalizing(['document.created', 'document.updated', 'document.restored']);
});
