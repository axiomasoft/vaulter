<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Tests\TestCase;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Schema\ModuleSchemas;

uses(TestCase::class);

it('creates the scan evidence and work schema with enforced checks and foreign keys under every package key strategy', function (string $strategy): void {
    $oldPrefix = config('vaulter.database.table_prefix');
    $oldStrategy = config('vaulter.ids.strategy');
    config()->set('vaulter.database.table_prefix', 'scan_probe_');
    config()->set('vaulter.ids.strategy', $strategy);
    $builder = app(ModuleSchemas::class);
    $db = app(VaulterDatabase::class)->connection();
    $schema = $db->getSchemaBuilder();
    if ($db->getDriverName() === 'sqlite') {
        $db->statement('PRAGMA foreign_keys = ON');
        expect((int) $db->selectOne('PRAGMA foreign_keys')->foreign_keys)->toBe(1);
    }
    $key = static fn (int $id): int|string => match ($strategy) {
        'bigint' => $id, 'ulid' => (string) Str::ulid(), 'uuid7' => (string) Str::uuid7(),
    };
    [$drive, $node, $file, $revision, $blob, $missing] = array_map($key, [1, 2, 3, 4, 5, 6]);
    try {
        $builder->createAll();
        $db->table('scan_probe_drives')->insert(['id' => $drive, 'owner_type' => 'team', 'owner_id' => '1', 'name' => 'drive']);
        $db->table('scan_probe_nodes')->insert(['id' => $node, 'drive_id' => $drive, 'kind' => 'file', 'name' => 'prior', 'name_key' => 'prior']);
        $db->table('scan_probe_files')->insert(['id' => $file, 'node_id' => $node]);
        $db->table('scan_probe_blobs')->insert(['id' => $blob, 'sha256' => str_repeat('a', 64), 'size_bytes' => 3, 'scope_key' => 'drive:'.$drive, 'storage_key' => 'prior', 'status' => 'committed']);
        $db->table('scan_probe_file_revisions')->insert(['id' => $revision, 'file_id' => $file, 'blob_id' => $blob,
            'number' => 1, 'sha256' => str_repeat('a', 64), 'size_bytes' => 3, 'original_name' => 'prior', 'scan_status' => 'clean', 'status' => 'committed', 'committed_at' => '2026-09-01 00:00:00']);
        $stored = (array) $db->table('scan_probe_file_revisions')->first();
        expect((int) $stored['scan_admission_sequence'])->toBe(0)->and($stored['quarantined_at'])->toBeNull();
        foreach (['scan_policy_states', 'scan_obligations', 'scan_requests', 'scan_evidence', 'scan_backfill_runs'] as $entity) {
            expect($schema->hasTable('scan_probe_'.$entity))->toBeTrue();
        }
        expect($schema->hasColumns('scan_probe_upload_sessions', ['result_blob_id', 'input_fingerprint', 'cleanup_pending', 'cleanup_attempts', 'cleanup_available_at', 'cleanup_last_reason']))->toBeTrue();
        $base = ['revision_id' => $revision, 'drive_id' => $drive, 'blob_id' => $blob, 'spec_key' => str_repeat('b', 64),
            'scanner_key' => 'fixture', 'scanner_version' => '1', 'policy_version' => '1', 'scope_key' => 'drive:'.$drive,
            'content_identity' => str_repeat('c', 64), 'sha256' => str_repeat('a', 64), 'size_bytes' => 3,
            'generation' => 1, 'attempt' => 0, 'request_key' => (string) Str::uuid(), 'state' => 'pending',
            'available_at' => '2026-10-07 00:00:00', 'created_at' => '2026-10-07 00:00:00', 'updated_at' => '2026-10-07 00:00:00'];
        $obligation = $key(7);
        $db->table('scan_probe_scan_obligations')->insert(['id' => $obligation] + $base);
        foreach ([['generation' => 0], ['generation' => '9223372036854775808'], ['attempt' => -1], ['size_bytes' => -1],
            ['state' => 'invented'], ['state' => 'leased'], ['state' => 'settled'], ['outcome' => 'clean', 'reason' => 'unknown'],
            ['blob_id' => $missing], ['revision_id' => $missing]] as $invalid) {
            expect(fn () => $db->transaction(fn () => $db->table('scan_probe_scan_obligations')->where('id', $obligation)->update($invalid)))
                ->toThrow(QueryException::class);
        }
        foreach (['scan_probe_drives' => $drive, 'scan_probe_file_revisions' => $revision] as $table => $id) {
            $db->table($table)->where('id', $id)->update(['scan_admission_sequence' => PHP_INT_MAX]);
            foreach ([-1, '9223372036854775808'] as $invalid) {
                expect(fn () => $db->transaction(fn () => $db->table($table)->where('id', $id)->update(['scan_admission_sequence' => $invalid])))
                    ->toThrow(QueryException::class);
            }
        }
        // Work protects physical CAS content until the revision-owned intent is purged.
        expect(fn () => $db->transaction(fn () => $db->table('scan_probe_blobs')->where('id', $blob)->delete()))->toThrow(QueryException::class);
        $db->table('scan_probe_file_revisions')->where('id', $revision)->delete();
        expect($db->table('scan_probe_scan_obligations')->count())->toBe(0);
        $db->table('scan_probe_blobs')->where('id', $blob)->delete();
    } finally {
        $builder->dropAll();
        config()->set('vaulter.database.table_prefix', $oldPrefix);
        config()->set('vaulter.ids.strategy', $oldStrategy);
    }
})->with(['bigint', 'ulid', 'uuid7']);

it('guards scan authority against mass assignment and validates aliased model subclasses', function (): void {
    $config = app(VaulterConfig::class);
    foreach (['scan_obligation', 'scan_request', 'scan_evidence', 'scan_policy_state', 'scan_backfill_run'] as $alias) {
        $class = $config->model($alias);
        $model = new $class;
        expect($model->isFillable('tenant_id'))->toBeFalse()->and($model->isFillable('outcome'))->toBeFalse()
            ->and($model->isFillable('lease_token'))->toBeFalse();
        config()->set('vaulter-drive.models.'.$alias, Drive::class);
        expect(fn () => $config->model($alias))->toThrow(InvalidConfigurationException::class);
        config()->set('vaulter-drive.models.'.$alias, $class);
    }
});
