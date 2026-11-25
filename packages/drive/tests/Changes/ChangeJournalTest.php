<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Changes\ChangeJournal;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Enums\Changes\ChangeReason;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Events\Nodes\NodeRenamed;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Tests\TestCase;
use Vaulter\Drive\ValueObjects\Events\JournalEffect;
use Vaulter\Events\EventTypeContract;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\ValueObjects\Access\Actor;

/**
 * Writing the drive change journal: one entry per journalled
 * event, numbered 1..n per drive under the head row, compacted to the latest state of a
 * node without ever dropping a remove or an entry a weaker one would hide.
 */
uses(TestCase::class, DatabaseMigrations::class);

beforeEach(function (): void {
    config()->set('vaulter-drive.access.permission_driver', 'database');
    $this->drive = DriveTestHelpers::drive();
});

/** @return list<array{seq: int, event_type: string, node_id: string, operation: string, reason: ?string, subtree: bool}> */
function journalEntries(DriveHandle $drive): array
{
    return app(VaulterDatabase::class)->connection()->table(app(DatabaseOptions::class)->table('drive_changes'))
        ->where('drive_id', $drive->model()->getKey())->orderBy('seq')->get()
        ->map(static fn (object $row): array => [
            'seq' => (int) $row->seq,
            'event_type' => $row->event_type,
            'node_id' => (string) $row->node_id,
            'operation' => $row->operation,
            'reason' => $row->reason,
            'subtree' => (bool) $row->subtree,
        ])->all();
}

/** @return list<array{string, string}> event type and operation of each entry of $nodeId, in seq order */
function journalOf(DriveHandle $drive, string $nodeId): array
{
    return array_values(array_map(
        static fn (array $entry): array => [$entry['event_type'], $entry['operation']],
        array_filter(journalEntries($drive), static fn (array $entry): bool => $entry['node_id'] === $nodeId),
    ));
}

function journalHead(DriveHandle $drive): object
{
    return app(VaulterDatabase::class)->connection()->table(app(DatabaseOptions::class)->table('drive_change_heads'))
        ->where('drive_id', $drive->model()->getKey())->sole();
}

it('provisions a head at zero and numbers entries 1..n per drive with the event id', function (): void {
    expect((int) journalHead($this->drive)->last_seq)->toBe(0);

    $alpha = $this->drive->folders()->create('alpha');
    $this->drive->folders()->create('beta');
    $other = DriveTestHelpers::drive();
    $other->folders()->create('gamma');

    $entries = journalEntries($this->drive);
    $outbox = OutboxEvent::query()->where('type', DriveEventType::FolderCreated->value)->get()
        ->sole(static fn (OutboxEvent $row): bool => $row->payload['subject']['id'] === (string) $alpha->id);

    expect(array_column($entries, 'seq'))->toBe([1, 2])
        ->and((int) journalHead($this->drive)->last_seq)->toBe(2)
        ->and(array_column(journalEntries($other), 'seq'))->toBe([1])
        ->and($outbox->payload['changes'])->toBe(['seq' => 1])
        ->and(app(VaulterDatabase::class)->connection()->table('v_drive_changes')->where('seq', 1)->where('drive_id', $this->drive->model()->getKey())->value('change_id'))->toBe($outbox->event_id);
});

it('replaces earlier plain upserts and keeps the latest state of a node', function (): void {
    $folder = $this->drive->folders()->create('alpha');
    $this->drive->nodes()->rename($folder, 'beta');
    $this->drive->nodes()->rename($folder, 'gamma');

    expect(journalOf($this->drive, $folder->id))->toBe([['node.renamed', 'upsert']])
        // Numbering continues: compaction removes entries, never seqs.
        ->and(array_column(journalEntries($this->drive), 'seq'))->toBe([3]);
});

it('keeps a subtree upsert under a later plain upsert and replaces it with a later subtree one', function (): void {
    $folder = $this->drive->folders()->create('alpha');
    $target = $this->drive->folders()->create('target');
    $this->drive->nodes()->move($folder, $target);
    $this->drive->nodes()->rename($folder, 'beta');

    expect(journalOf($this->drive, $folder->id))->toBe([['node.moved', 'upsert'], ['node.renamed', 'upsert']]);

    $this->drive->nodes()->move($folder, null);

    expect(journalOf($this->drive, $folder->id))->toBe([['node.moved', 'upsert']]);
});

it('keeps a file move or access change under a later plain upsert', function (): void {
    $file = $this->drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('a'), 'a.txt'));
    $target = $this->drive->folders()->create('target');
    $this->drive->nodes()->move($file, $target);
    $this->drive->nodes()->rename($file, 'b.txt');

    expect(journalOf($this->drive, $file->id))->toBe([['node.moved', 'upsert'], ['node.renamed', 'upsert']]);

    $this->drive->access()->grant($file, new GrantInput(subject: new Subject('user', 14), role: RoleRegistry::READER));

    // An access change replaces both: it explains a disappearance as well as the move did.
    expect(journalOf($this->drive, $file->id))->toBe([['grant.created', 'upsert']]);

    $this->drive->nodes()->rename($file, 'c.txt');

    expect(journalOf($this->drive, $file->id))->toBe([['grant.created', 'upsert'], ['node.renamed', 'upsert']]);
});

it('never compacts a remove away', function (): void {
    $folder = $this->drive->folders()->create('alpha');
    $this->drive->nodes()->rename($folder, 'beta');
    $this->drive->nodes()->trash($folder);
    $this->drive->trash()->restore($folder);
    $this->drive->nodes()->trash($folder);

    expect(journalOf($this->drive, $folder->id))->toBe([['node.trashed', 'remove'], ['node.trashed', 'remove']])
        ->and(array_column(array_filter(journalEntries($this->drive), static fn (array $entry): bool => $entry['operation'] === 'remove'), 'reason'))->toBe(['deleted', 'deleted']);
});

it('flags subtree only for folders that move, copy, restore or change access', function (): void {
    $folder = $this->drive->folders()->create('alpha');
    $file = $this->drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('a'), 'a.txt'));
    $target = $this->drive->folders()->create('target');
    $this->drive->nodes()->move($folder, $target);
    $this->drive->nodes()->move($file, $target);
    $copy = $this->drive->nodes()->copy($folder, null);
    $this->drive->access()->grant($target, new GrantInput(subject: new Subject('user', 14), role: RoleRegistry::READER));
    $this->drive->nodes()->rename($target, 'renamed');
    $this->drive->nodes()->trash($copy);

    $flags = [];
    foreach (journalEntries($this->drive) as $entry) {
        $flags[] = [$entry['event_type'], $entry['node_id'] === (string) $file->id ? 'file' : 'folder', $entry['subtree']];
    }

    expect($flags)->toContain(['node.moved', 'folder', true])
        ->toContain(['node.moved', 'file', false])
        ->toContain(['grant.created', 'folder', true])
        ->toContain(['node.renamed', 'folder', false])
        ->toContain(['node.trashed', 'folder', false]);
});

it('journals an occurrence once and creates a missing head on first use', function (): void {
    $folder = $this->drive->folders()->create('alpha');
    app(VaulterDatabase::class)->connection()->table('v_drive_change_heads')->where('drive_id', $this->drive->model()->getKey())->delete();
    app(VaulterDatabase::class)->connection()->table('v_drive_changes')->where('drive_id', $this->drive->model()->getKey())->delete();

    $event = new NodeRenamed(nodeSnapshots: app(NodeSnapshotMapper::class), node: DriveTestHelpers::model($folder->id), fromName: 'alpha', toName: 'alpha', actor: Actor::system('replay')->ref(), correlationId: (string) Str::ulid());
    $journal = app(ChangeJournal::class);
    $seqs = app(VaulterDatabase::class)->transaction(static fn (): array => [
        $journal->append($event, $event->journal()),
        $journal->append($event, $event->journal()),
    ]);

    expect($seqs)->toBe([1, null])
        ->and(journalEntries($this->drive))->toHaveCount(1)
        ->and((int) journalHead($this->drive)->last_seq)->toBe(1);
});

it('refuses an entry whose operation contradicts the catalogue', function (): void {
    $folder = $this->drive->folders()->create('alpha');
    $event = new NodeRenamed(nodeSnapshots: app(NodeSnapshotMapper::class), node: DriveTestHelpers::model($folder->id), fromName: 'alpha', toName: 'alpha', actor: Actor::system('replay')->ref(), correlationId: (string) Str::ulid());

    app(VaulterDatabase::class)->transaction(static fn () => app(ChangeJournal::class)->append($event, JournalEffect::remove($folder->id, ChangeReason::Deleted)));
})->throws(LogicException::class, 'does not match its catalogue operation');

it('writes nothing and leaves changes.seq empty when the journal is switched off', function (): void {
    config()->set('vaulter-drive.features.changes', false);
    $this->drive->folders()->create('alpha');

    expect(journalEntries($this->drive))->toBe([])
        ->and(OutboxEvent::query()->where('type', DriveEventType::FolderCreated->value)->sole()->payload['changes'])->toBeNull();
});

it('matches every journalled catalogue type with the operation of its entry', function (): void {
    $journalled = array_values(array_filter(DriveEventType::cases(), static fn (EventTypeContract $type): bool => $type->spec()->journal !== null));

    // The document types of the catalogue are asserted by vaulter-documents (tests/Events/DocumentJournalCatalogueTest).
    expect(array_map(static fn (EventTypeContract $type): string => (string) $type->value, $journalled))->toEqualCanonicalizing([
        'folder.created', 'file.uploaded', 'file.revision_added', 'node.renamed', 'node.moved', 'node.copied', 'node.updated',
        'node.trashed', 'node.restored',
        'grant.created', 'grant.revoked', 'access.inheritance_changed',
    ])->and(DriveEventType::NodeTrashed->spec()->journal)->toBe('remove');
});
