<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Media\Cas\BlobGarbageCollector;
use Vaulter\Media\Cas\BlobReconciler;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Contracts\Cas\ContentStore;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\Media\Tests\Fixtures\Cas\FailingPromoteContentStore;
use Vaulter\Media\ValueObjects\Cas\BlobScope;
use Vaulter\Testing\Concurrency\ForkRace;

beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this);
    config()->set('vaulter.ids.strategy', 'ulid');
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
