<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Vaulter\Contracts\Cas\BlobStore;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Load\Console\SeedLoadDatasetCommand;
use Vaulter\Models\Drive\FileVersion;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Drive\Storage as DriveStorage;
use Vaulter\Support\Config;

it('seeds primary blob relations matching GC edges for hot and metadata-only versions', function (): void {
    $connection = DB::connection(Config::connection());
    expect($connection->getDriverName())->toBe('sqlite')
        ->and($connection->getDatabaseName())->toBe(':memory:');
    $this->loadMigrationsFrom(dirname(__DIR__, 3).'/packages/core/database/migrations');
    Storage::fake('s3');

    $storage = new DriveStorage;
    $storage->forceFill(['owner_type' => 'team', 'owner_id' => 1, 'name' => 'R03 test'])->save();
    $nodeIds = [];
    foreach (['hot.bin', 'cold.bin'] as $name) {
        $node = new Node;
        $node->forceFill(['storage_id' => $storage->getKey(), 'kind' => NodeKind::File, 'name' => $name])->save();
        $nodeIds[] = (int) $node->getKey();
    }

    $store = app(BlobStore::class);
    $seed = new ReflectionMethod(SeedLoadDatasetCommand::class, 'seedBlobsAndVersions');
    $seed->invoke(new SeedLoadDatasetCommand, $store, $nodeIds, 'r03-test', [
        'file_versions' => ['extra_version_share' => 0],
        'blobs' => ['hot_set' => 1, 'hot_set_avg_bytes' => 128, 'orphan' => 0],
    ]);

    $versions = FileVersion::query()->orderBy('id')->get();
    expect($versions)->toHaveCount(2);
    foreach ($versions as $version) {
        expect($version->blob)->not->toBeNull()
            ->and($version->blobs()->sole()->getKey())->toBe($version->blob->getKey());
    }
    $stream = $store->read($versions[0]->blob);
    expect(is_resource($stream))->toBeTrue();
    $bytes = stream_get_contents($stream);
    fclose($stream);
    expect(strlen($bytes))->toBe($versions[0]->size_bytes)
        ->and(hash('sha256', $bytes))->toBe($versions[0]->content_sha256);
});
