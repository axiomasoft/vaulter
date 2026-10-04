<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Vaulter\Contracts\Cas\BlobStore;
use Vaulter\Contracts\Cas\ContentStore;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Enums\Cas\BlobStatus;
use Vaulter\Media\Cas\BlobGarbageCollector;
use Vaulter\Media\Cas\BlobReconciler;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\Media\Tests\Fixtures\Cas\FailingPromoteContentStore;
use Vaulter\Models\Cas\Blob;
use Vaulter\Tests\Concurrency\ForkRace;
use Vaulter\ValueObjects\Cas\BlobScope;

beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this);
    config()->set('vaulter.ids.default', 'ulid');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    Storage::fake('local');
});

it('collects a post-promote SQL rollback only after grace under the lossless lock', function (): void {
    $content = app(ContentStore::class);
    app()->instance(ContentStore::class, new FailingPromoteContentStore($content, after: true));
    $scope = BlobScope::tenant('019a0000-1234-7000-8000-123456789abc');
    $bytes = 'promoted then rollback';
    expect(fn () => app(BlobStore::class)->commit(CasTestHelpers::streamFor($bytes), $scope))->toThrow(RuntimeException::class);
    expect(Blob::query()->count())->toBe(0);
    app()->instance(ContentStore::class, $content);
    $key = $content->keyFor($scope, hash('sha256', $bytes));
    expect(app(BlobReconciler::class)->sweepOrphans('local', 24, true)['pruned'])->toBe(0);
    CasTestHelpers::ageObject($key, 48);
    $queries = [];
    app(VaulterDatabase::class)->connection()->listen(function ($query) use (&$queries): void {
        $queries[] = $query->bindings;
    });
    expect(app(BlobReconciler::class)->sweepOrphans('local', 24, true)['pruned'])->toBe(1);
    expect($queries)->toContain(['cas:'.$scope->key().':'.hash('sha256', $bytes)]);
    Storage::disk('local')->assertMissing($key);
});

it('serializes GC against a writer of the same scope and keeps reachable bytes', function (): void {
    $scope = BlobScope::drive('01ARZ3NDEKTSV4RRFFQ69G5FAV');
    $bytes = 'race bytes';
    $blob = app(BlobStore::class)->commit(CasTestHelpers::streamFor($bytes), $scope);
    $blob->forceFill(['status' => BlobStatus::Tombstoned, 'delete_after_at' => now()->subDay()])->save();
    $version = CasTestHelpers::makeVersion();
    $outcomes = ForkRace::run([
        fn (): array => app(BlobGarbageCollector::class)->sweep(),
        function () use ($scope, $bytes, $version): string {
            return app(VaulterDatabase::class)->transaction(function () use ($scope, $bytes, $version): string {
                $store = app(BlobStore::class);
                $fresh = $store->commit(CasTestHelpers::streamFor($bytes), $scope);
                $store->attach($version, $fresh);

                return (string) $fresh->getKey();
            });
        },
    ]);
    foreach ($outcomes as $outcome) {
        expect($outcome->ok)->toBeTrue($outcome->message ?? '');
    }
    expect($blob->fresh()->status)->toBe(BlobStatus::Committed);
    Storage::disk('local')->assertExists($blob->storage_key);
    expect(stream_get_contents(app(BlobStore::class)->read($blob)))->toBe($bytes);
});

it('repairs while batch GC holds the first advisory lock without a lock-order cycle', function (): void {
    $version = CasTestHelpers::makeVersion();
    $scope = BlobScope::drive((string) $version->file->node->storage->getKey());
    $database = app(VaulterDatabase::class);
    // Select a pair where lexical scope order and PostgreSQL lock order disagree.
    for ($i = 0; $i < 100; $i++) {
        $bytes = 'repair GC lock order '.$i;
        $sha = hash('sha256', $bytes);
        $sourceKey = 'cas:legacy:snull:'.$sha;
        $targetKey = 'cas:'.$scope->key().':'.$sha;
        $hashes = $database->connection()->selectOne('SELECT hashtext(?) AS source, hashtext(?) AS target', [$sourceKey, $targetKey]);
        if ($hashes->source < $hashes->target) {
            break;
        }
    }
    expect($hashes->source)->toBeLessThan($hashes->target);
    $store = app(BlobStore::class);
    $source = $store->commit(CasTestHelpers::streamFor($bytes), BlobScope::legacy('snull'));
    $target = $store->commit(CasTestHelpers::streamFor($bytes), $scope);
    $version->forceFill(['blob_id' => $source->getKey()])->save();
    $store->attach($version, $source);
    $barrier = sys_get_temp_dir().'/vlt_repair_gc_'.bin2hex(random_bytes(8));
    mkdir($barrier);
    $wait = static function (Closure $condition): void {
        $deadline = microtime(true) + 5;
        while (!$condition()) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Repair/GC barrier timed out.');
            }
            usleep(1_000);
        }
    };
    try {
        $outcomes = ForkRace::run([
            function () use ($sourceKey, $barrier, $wait): array {
                $db = app(VaulterDatabase::class)->connection();

                // No automatic retry: a deadlock must fail this regression.
                return $db->transaction(function () use ($db, $sourceKey, $barrier, $wait): array {
                    $db->select('SELECT pg_advisory_xact_lock(hashtext(?))', [$sourceKey]);
                    file_put_contents($barrier.'/held', '1');
                    $wait(static fn (): bool => is_file($barrier.'/repair-pid'));
                    $pid = (int) file_get_contents($barrier.'/repair-pid');
                    $wait(static function () use ($db, $pid): bool {
                        $db->select('SELECT pg_stat_clear_snapshot()');

                        return $db->table('pg_stat_activity')->where('pid', $pid)->where('wait_event', 'advisory')->exists();
                    });

                    return app(BlobGarbageCollector::class)->mark(24);
                }, 1);
            },
            function () use ($barrier, $wait, $targetKey): array {
                $wait(static fn (): bool => is_file($barrier.'/held'));
                $db = app(VaulterDatabase::class)->connection();
                $pid = (int) $db->selectOne('SELECT pg_backend_pid() AS pid')->pid;
                $attempts = 0;
                $db->beforeExecuting(static function (string $query, array $bindings) use ($barrier, $pid, $targetKey, &$attempts): void {
                    if (str_contains($query, 'pg_advisory_xact_lock') && str_contains(json_encode($bindings, JSON_THROW_ON_ERROR), 'cas:')) {
                        file_put_contents($barrier.'/repair-pid', (string) $pid);
                        if (str_contains(json_encode($bindings, JSON_THROW_ON_ERROR), $targetKey)) {
                            $attempts++;
                        }
                    }
                });

                $exit = Artisan::call('vaulter:blobs:repair-scopes', ['--execute' => true]);

                return ['exit' => $exit, 'attempts' => $attempts];
            },
        ]);
        foreach ($outcomes as $outcome) {
            expect($outcome->ok)->toBeTrue($outcome->message ?? '');
        }
        expect($outcomes[1]->value)->toBe(['exit' => 0, 'attempts' => 1])
            ->and($version->fresh()->blob_id)->toBe($target->getKey())
            ->and($target->fresh()->ref_count)->toBe(1)
            ->and(stream_get_contents($store->read($target)))->toBe($bytes);
        Storage::disk('local')->assertExists($source->storage_key);
    } finally {
        foreach (glob($barrier.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($barrier);
    }
});
