<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Events\Nodes\FolderCreated;
use Vaulter\Drive\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Drive\Repositories\NodeReadRepository;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;

it('creates a folder at the storage root (under the root node)', function (): void {
    $drive = DriveTestHelpers::drive();

    $folder = $drive->folders()->create('Docs');

    // "At root" means a child of the storage's root folder node, not a parentless
    // sibling of it — the root node is the top of the tree.
    expect($folder->kind)->toBe(NodeKind::Folder)
        ->and($folder->name)->toBe('Docs')
        ->and($folder->parentId)->toEqual($drive->root()->id)
        ->and($folder->driveId)->toEqual($drive->model()->getKey());
});

it('creates a nested folder under its parent', function (): void {
    $drive = DriveTestHelpers::drive();

    $parent = $drive->folders()->create('Docs');
    $child = $drive->folders()->create('2026', $parent);

    expect($child->parentId)->toEqual($parent->id);
});

it('rejects a sibling name conflict deterministically', function (): void {
    $drive = DriveTestHelpers::drive();

    $drive->folders()->create('Docs');

    expect(fn (): NodeSnapshot => $drive->folders()->create('Docs'))
        ->toThrow(NodeNameConflictException::class);
});

it('ignores case when checking siblings', function (): void {
    $drive = DriveTestHelpers::drive();

    $drive->folders()->create('Docs');

    expect(fn (): NodeSnapshot => $drive->folders()->create('DOCS'))
        ->toThrow(NodeNameConflictException::class);
});

it('allows the same name under different parents', function (): void {
    $drive = DriveTestHelpers::drive();

    $a = $drive->folders()->create('A');
    $b = $drive->folders()->create('B');

    $drive->folders()->create('Shared', $a);
    $shared = $drive->folders()->create('Shared', $b);

    expect($shared->parentId)->toEqual($b->id);
});

it('dispatches NodeCreated when a folder is created', function (): void {
    Event::fake([FolderCreated::class]);

    $folder = DriveTestHelpers::drive()->folders()->create('Docs');

    Event::assertDispatched(
        FolderCreated::class,
        fn (FolderCreated $event): bool => $event->node->id === $folder->id,
    );
});

it('list returns the direct children of a folder', function (): void {
    $drive = DriveTestHelpers::drive();

    $drive->folders()->create('A');
    $drive->folders()->create('B');

    expect(collect($drive->nodes()->children()->items)->pluck('name')->all())->toBe(['A', 'B']);
});

// MariaDB defaults to REPEATABLE READ as well, so the sibling check must take the same locking
// read there; it used to compare the driver name with 'mysql' only. Without a server lane for
// either engine, this checks the SQL the repository emits with each grammar: pretend() logs the
// query without executing it.
it('reads the sibling name with a locking read on MySQL and MariaDB', function (string $driver): void {
    $storage = DriveTestHelpers::drive()->model();
    $default = DB::getDefaultConnection();
    $pdo = DB::connection($default)->getPdo();
    config()->set("database.connections.{$driver}", ['driver' => $driver, 'database' => 'vaulter_sibling_lock_grammar_test', 'prefix' => '']);
    // pretend() still quotes string bindings for its log; the isolated test PDO only does that escaping.
    $connection = DB::connection($driver)->setPdo($pdo)->setReadPdo($pdo);
    config()->set('database.default', $driver);

    try {
        $queries = $connection->pretend(static function () use ($storage): void {
            app(NodeReadRepository::class)->siblingNameExists($storage, null, 'report.pdf');
        });
    } finally {
        // Before teardown: RefreshDatabase rolls back the default connection by name.
        config()->set('database.default', $default);
    }

    $sql = strtolower(implode("\n", array_map(static fn (array $query): string => (string) $query['query'], $queries)));
    expect($sql)->toContain('name_key')->toContain('lock in share mode');
})->with(['mysql', 'mariadb']);
