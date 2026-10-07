<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Configuration\IdsConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Schema\UpgradePreflightException;
use Vaulter\Schema\KeyColumnInspector;
use Vaulter\Tests\Upgrade\LoadsV02Fixture;

uses(LoadsV02Fixture::class);

/*
 * V15/V32 on the 0.2 fixture: migration №5 turns every legacy document version into
 * a checkpoint of the new aggregate without changing its id, the head's payload
 * becomes the working state, the node loses its polymorphic pointers, both
 * aggregates get their composite head FK, and the whole chain rolls back and
 * re-applies with the same ids.
 */
beforeEach(function (): void {
    if (app(VaulterDatabase::class)->driver() !== 'pgsql' || (new ExecutableFinder)->find('psql') === null) {
        $this->markTestSkipped('Upgrade fixtures require PostgreSQL and psql.');
    }
});

afterEach(function (): void {
    $db = app(VaulterDatabase::class)->connection();
    if ($db->getDriverName() === 'pgsql' && str_ends_with($db->getDatabaseName(), '_test') && $db->transactionLevel() === 0) {
        $db->getSchemaBuilder()->dropAllTables();
    }

    RefreshDatabaseState::$migrated = false;
});

it('upgrades 0.2 document versions into the document aggregate with ids preserved', function (string $strategy): void {
    $this->loadV02Fixture($strategy);
    $db = app(VaulterDatabase::class)->connection();

    // A markdown document with a third version that is its head.
    $readme = $db->table('v_node_document_versions')->where('format', 'markdown')->value('node_id');
    $v3 = ['node_id' => $readme, 'version_no' => 3, 'format' => 'markdown', 'is_autosave' => true, 'status' => 'committed', 'keep_forever' => false,
        'content' => json_encode(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'markdown v3']]]]]),
        'content_hash' => str_repeat('c', 64), 'created_at' => '2026-01-01 02:00:00'];
    $headId = $strategy === 'bigint' ? $db->table('v_node_document_versions')->insertGetId($v3) : (string) Str::ulid();
    if ($strategy !== 'bigint') {
        $db->table('v_node_document_versions')->insert(['id' => $headId, ...$v3]);
    }
    $db->table('v_nodes')->where('id', $readme)->update(['current_version_id' => $headId]);
    $legacy = $db->table('v_node_document_versions')->orderBy('id')->get()->keyBy(fn ($row): string => (string) $row->id);
    $legacyIds = $legacy->keys()->map(fn ($id): string => (string) $id)->all();
    $documentNodes = $db->table('v_nodes')->where('kind', 'document')->count();
    $fileRevisions = $db->table('v_file_versions')->count();

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    $schema = $db->getSchemaBuilder();
    expect($schema->hasTable('v_node_document_versions'))->toBeFalse()
        ->and($schema->hasColumns('v_nodes', ['current_version_id']))->toBeFalse()
        ->and($schema->hasColumn('v_nodes', 'file_id'))->toBeFalse()
        ->and($schema->hasColumn('v_nodes', 'deleted_at'))->toBeFalse()
        ->and($db->table('v_documents')->count())->toBe($documentNodes)
        ->and($db->table('v_file_revisions')->count())->toBe($fileRevisions)
        ->and($db->table('v_file_revisions')->whereNotNull('restored_from_revision_id')->count())->toBe(0)
        ->and((new KeyColumnInspector(app(VaulterDatabase::class), app(DatabaseConfig::class), app(IdsConfig::class)))->mismatches())->toBe([]);

    // Every legacy version is a checkpoint under its own id.
    $revisions = $db->table('v_document_revisions as r')->join('v_documents as d', 'd.id', '=', 'r.document_id')
        ->orderBy('r.id')->get(['r.*', 'd.node_id'])->keyBy(fn ($row): string => (string) $row->id);
    expect($revisions->keys()->map(fn ($id): string => (string) $id)->all())->toBe($legacyIds);
    foreach ($legacy as $id => $row) {
        $revision = $revisions[$id];
        expect((string) $revision->node_id)->toBe((string) $row->node_id)
            ->and($revision->number)->toBe($row->version_no)
            ->and($revision->label)->toBe($row->snapshot_label)
            ->and($revision->content_hash)->toBe($row->content_hash)
            ->and($revision->is_autosave)->toBe($row->is_autosave)
            ->and($revision->schema_version)->toBe(1);
    }

    // The head's payload is the working state.
    $document = $db->table('v_documents')->where('node_id', $readme)->first();
    expect($document->type)->toBe('vaulter/markdown')
        ->and((string) $document->current_revision_id)->toBe((string) $headId)
        ->and($document->revision_count)->toBe(3)
        ->and($document->state_version)->toBe(1)
        ->and($document->checkpoint_state_version)->toBe(1)
        ->and($document->collab_epoch)->toBe(0)
        ->and(json_decode((string) $document->content, true))->toEqual(json_decode($v3['content'], true))
        ->and($document->content_hash)->toBe($v3['content_hash'])
        ->and($revisions[(string) $headId]->state_version)->toBe(1)
        ->and($revisions->where('state_version', 1)->count())->toBe($documentNodes);
    expect($db->table('v_documents')->orderBy('type')->distinct()->pluck('type')->all())->toBe(['vaulter/markdown', 'vaulter/rich-text']);

    // Both aggregates now refuse to lose their head; the revision serial moved past the copied ids.
    $keys = fn (string $table): array => array_column($schema->getForeignKeys($table), 'name');
    expect($keys('v_documents'))->toContain('v_documents_head_fk')
        ->and($keys('v_files'))->toContain('v_files_head_fk');
    expect(fn () => $db->transaction(fn () => $db->table('v_document_revisions')->where('id', $headId)->delete()))->toThrow(QueryException::class);
    if ($strategy === 'bigint') {
        $next = $db->table('v_document_revisions')->insertGetId([
            'document_id' => $document->id, 'number' => 4, 'schema_version' => 1, 'state_version' => 0, 'status' => 'committed',
        ]);
        expect($next)->toBeGreaterThan((int) $legacy->keys()->max());
        $db->table('v_document_revisions')->where('id', $next)->delete();
    }

    // Rolling back restores the 0.2 shape with the same ids; re-applying converges again.
    $this->artisan('migrate:rollback', ['--force' => true])->assertSuccessful();
    expect($schema->hasTable('v_node_document_versions'))->toBeTrue()
        ->and($schema->hasTable('v_documents'))->toBeFalse()
        ->and($db->table('v_node_document_versions')->orderBy('id')->pluck('id')->map(fn ($id): string => (string) $id)->all())->toBe($legacyIds)
        ->and($db->table('v_node_document_versions')->where('id', $headId)->value('format'))->toBe('markdown')
        ->and((string) $db->table('v_nodes')->where('id', $readme)->value('current_version_id'))->toBe((string) $headId);

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    expect($db->table('v_document_revisions')->orderBy('id')->pluck('id')->map(fn ($id): string => (string) $id)->all())->toBe($legacyIds)
        ->and((string) $db->table('v_documents')->where('node_id', $readme)->value('current_revision_id'))->toBe((string) $headId);
})->with(['bigint', 'ulid']);

it('refuses a document node without versions instead of inventing content', function (): void {
    $this->loadV02Fixture('bigint');
    $db = app(VaulterDatabase::class)->connection();
    $orphan = $db->table('v_node_document_versions')->where('format', 'table')->value('node_id');
    $db->table('v_node_document_versions')->where('node_id', $orphan)->delete();
    // #1 refuses the same state; №5 keeps its own guard for databases that ran #1 earlier.
    foreach (['000002_rename_storages_to_drives', '000003_host_key_columns', '000004_file_revisions'] as $name) {
        (require __DIR__.'/../../database/migrations/2026_10_01_'.$name.'.php')->up();
    }

    $migration = require __DIR__.'/../../database/migrations/2026_10_01_000005_documents_aggregate.php';
    expect(fn () => $migration->up())->toThrow(UpgradePreflightException::class, 'document nodes without any version ['.$orphan.']')
        ->and($db->getSchemaBuilder()->hasTable('v_documents'))->toBeFalse()
        ->and($db->getSchemaBuilder()->hasTable('v_node_document_versions'))->toBeTrue();
});
