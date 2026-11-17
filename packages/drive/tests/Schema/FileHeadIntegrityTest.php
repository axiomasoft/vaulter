<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\File;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Repositories\FileStoreRepository;
use Vaulter\Drive\Tests\Schema\ForceCreates;

/*
 * For files: v_files.current_revision_id names a revision of that very file. On
 * PostgreSQL and MySQL the composite head FK (current_revision_id, id) →
 * v_file_revisions(id, file_id) ON DELETE RESTRICT refuses a foreign head and the
 * deletion of a head, while purging the node still cascades through both tables.
 * SQLite cannot add the constraint to an existing table; there the file Actions
 * hold the invariant alone.
 */

/** @return array{node: Node, file: File, head: FileRevision} */
function headFixture(string $name): array
{
    $drive = ForceCreates::make(Drive::class, ['owner_type' => 'team', 'owner_id' => 1, 'name' => 'Disk '.$name, 'profile' => 'p-'.$name]);
    $node = ForceCreates::make(Node::class, ['drive_id' => $drive->getKey(), 'kind' => NodeKind::File, 'name' => $name.'.txt']);
    $file = ForceCreates::make(File::class, ['node_id' => $node->getKey(), 'mime_type' => 'text/plain', 'size_bytes' => 3]);
    $head = ForceCreates::make(FileRevision::class, [
        'file_id' => $file->getKey(), 'number' => 1, 'sha256' => str_repeat('a', 64),
        'size_bytes' => 3, 'original_name' => $name.'.txt', 'status' => 'committed',
    ]);
    $file->forceFill(['current_revision_id' => $head->getKey(), 'revision_count' => 1])->save();

    return ['node' => $node, 'file' => $file, 'head' => $head];
}

it('gives file revisions their provenance column and the head FK target', function (): void {
    $schema = app(VaulterDatabase::class)->connection()->getSchemaBuilder();
    $revisions = app(DatabaseOptions::class)->table('file_revisions');
    $indexes = array_column($schema->getIndexes($revisions), null, 'name');
    // SQLite reports foreign keys without names, so match the provenance FK by column.
    $provenance = array_values(array_filter($schema->getForeignKeys($revisions), static fn (array $key): bool => $key['columns'] === ['restored_from_revision_id']));

    expect($schema->hasColumn($revisions, 'restored_from_revision_id'))->toBeTrue()
        ->and($indexes[$revisions.'_id_file_uniq']['columns'])->toBe(['id', 'file_id'])
        ->and($indexes[$revisions.'_id_file_uniq']['unique'])->toBeTrue()
        ->and($provenance)->toHaveCount(1)
        ->and($provenance[0]['foreign_table'])->toEndWith($revisions)
        ->and(strtolower((string) $provenance[0]['on_delete']))->toBe('set null');
});

it('enforces the file head FK where the driver supports it', function (): void {
    $db = app(VaulterDatabase::class)->connection();
    $files = app(DatabaseOptions::class)->table('files');
    $keys = array_column($db->getSchemaBuilder()->getForeignKeys($files), null, 'name');

    if ($db->getDriverName() === 'sqlite') {
        expect($keys)->not->toHaveKey($files.'_head_fk');

        return;
    }

    expect($keys[$files.'_head_fk']['columns'])->toBe(['current_revision_id', 'id'])
        ->and($keys[$files.'_head_fk']['foreign_columns'])->toBe(['id', 'file_id'])
        ->and(strtolower((string) $keys[$files.'_head_fk']['on_delete']))->toBe('restrict');

    ['file' => $file, 'head' => $head] = headFixture('guarded');
    ['head' => $foreign] = headFixture('foreign');

    // Each violation runs in its own savepoint so the surrounding test transaction survives.
    expect(fn () => $db->transaction(fn () => FileRevision::query()->whereKey($head->getKey())->delete()))->toThrow(QueryException::class)
        ->and(fn () => $db->transaction(fn () => File::query()->whereKey($file->getKey())->update(['current_revision_id' => $foreign->getKey()])))->toThrow(QueryException::class);

    expect(FileRevision::query()->whereKey($head->getKey())->exists())->toBeTrue()
        ->and($file->refresh()->current_revision_id)->toEqual($head->getKey());
});

it('purges a file node through the head FK by cascade', function (): void {
    if (app(VaulterDatabase::class)->driver() === 'sqlite') {
        $this->markTestSkipped('The SQLite test connection does not enforce FK cascades.');
    }

    ['node' => $node, 'file' => $file, 'head' => $head] = headFixture('purged');
    $restored = ForceCreates::make(FileRevision::class, [
        'file_id' => $file->getKey(), 'number' => 2, 'sha256' => str_repeat('b', 64), 'size_bytes' => 3,
        'original_name' => 'purged.txt', 'status' => 'committed', 'restored_from_revision_id' => $head->getKey(),
    ]);
    $file->forceFill(['current_revision_id' => $restored->getKey(), 'revision_count' => 2])->save();

    // The purge path releases the head first (MySQL checks RESTRICT mid-cascade).
    app(FileStoreRepository::class)->releaseHead($file);
    Node::query()->whereKey($node->getKey())->delete();

    expect(File::query()->whereKey($file->getKey())->exists())->toBeFalse()
        ->and(FileRevision::query()->whereIn('id', [$head->getKey(), $restored->getKey()])->exists())->toBeFalse();
});
