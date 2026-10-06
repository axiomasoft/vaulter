<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Drive\DriveHandle;
use Vaulter\Exceptions\Nodes\InvalidDestinationException;
use Vaulter\Exceptions\Nodes\InvalidNodeNameException;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * A document node is placed like any other node: no parent means the drive root
 * folder (never a second parentless node), and an explicit parent must be a live
 * folder of the same drive.
 */
function createDocumentUnder(DriveHandle $drive, string $name, ?Node $parent): Node
{
    return app(CreateDocumentAction::class)->execute(new CreateDocumentInput(
        storage: $drive->model(),
        name: $name,
        content: DocumentTestHelpers::content(),
        parent: $parent,
    ));
}

it('places a document without a parent under the drive root and lists it there', function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
    $drive = DocumentTestHelpers::drive();

    $node = createDocumentUnder($drive, 'Rootless', null);

    expect($node->parent_id)->toEqual($drive->model()->root_node_id)
        ->and(Node::query()->where('drive_id', $drive->model()->getKey())->whereNull('parent_id')->count())->toBe(1);

    $this->getJson('api/vaulter/storages/'.$drive->model()->getKey().'/nodes')
        ->assertOk()
        ->assertJsonPath('data.0.id', $node->getKey());
});

it('rejects a document under a file, a document or a trashed folder', function (string $kind): void {
    $drive = DocumentTestHelpers::drive();

    $parent = match ($kind) {
        'file' => $drive->upload(DriveTestHelpers::streamFor('x'), originalName: 'f.bin'),
        'document' => createDocumentUnder($drive, 'Doc', null),
        'trashed folder' => $drive->trash($drive->createFolder('Gone')),
    };

    expect(fn (): Node => createDocumentUnder($drive, 'Child', $parent))
        ->toThrow(InvalidDestinationException::class);
})->with(['file', 'document', 'trashed folder']);

it('rejects a parent folder of another drive', function (): void {
    $drive = DocumentTestHelpers::drive();
    $foreign = DocumentTestHelpers::drive()->createFolder('Elsewhere');

    expect(fn (): Node => createDocumentUnder($drive, 'Child', $foreign))
        ->toThrow(InvalidDestinationException::class, 'belongs to drive');
});

it('normalizes the document name and rejects an invalid one', function (): void {
    $drive = DocumentTestHelpers::drive();

    expect(createDocumentUnder($drive, "  Caf\u{0065}\u{0301}  ", null)->name)->toBe("Caf\u{00E9}")
        ->and(fn (): Node => createDocumentUnder($drive, 'a/b', null))->toThrow(InvalidNodeNameException::class);
});

it('takes the storage row before the parent row, as every node insert does', function (): void {
    $drive = DocumentTestHelpers::drive();
    $folder = $drive->createFolder('Target');
    $queries = [];

    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    createDocumentUnder($drive, 'Ordered', $folder);

    $driver = $drive->model()->getConnection()->getDriverName();
    $storageLock = array_key_first(array_filter($queries, static function (string $sql) use ($driver): bool {
        if ($driver === 'pgsql') {
            return str_contains(strtolower($sql), 'pg_advisory_xact_lock_shared');
        }

        return str_starts_with($sql, 'select "id" from "v_drives"');
    }));
    $nodeLock = array_key_first(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'from "v_nodes"') && str_contains($sql, 'order by')));

    // PostgreSQL takes a shared advisory tree lock. SQLite and MySQL lock the storage row.
    expect($storageLock)->not->toBeNull()
        ->and($nodeLock)->not->toBeNull()
        ->and($storageLock)->toBeLessThan($nodeLock);
});
