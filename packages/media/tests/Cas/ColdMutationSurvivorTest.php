<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Vaulter\Cas\BlobLock;
use Vaulter\Contracts\Cas\BlobStore as BlobStoreContract;
use Vaulter\Contracts\Cas\ContentStore;
use Vaulter\Contracts\Cas\StagedObject;
use Vaulter\Contracts\Cas\StorageDriver;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Enums\Cas\BlobStatus;
use Vaulter\Media\Cas\BlobGarbageCollector;
use Vaulter\Media\Cas\BlobReconciler;
use Vaulter\Media\Cas\FlysystemStorageDriver;
use Vaulter\Media\Cas\Sha256ContentStore;
use Vaulter\Media\Cas\StagedTempObject;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\Media\Tests\Fixtures\Cas\NonListableContentStore;
use Vaulter\Models\Cas\Blob;
use Vaulter\Repositories\Cas\BlobStoreRepository;
use Vaulter\Repositories\Drive\FileRevisionBlobStoreRepository;
use Vaulter\Testing\FakeMetricsRecorder;
use Vaulter\ValueObjects\Cas\BlobScope;

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter.storage.default_disk', 'local');
    config()->set('vaulter.dedup.scope', 'tenant');
    Carbon::setTestNow('2026-09-25 12:00:00');
});

function r18Collector(FakeMetricsRecorder $metrics): BlobGarbageCollector
{
    return new BlobGarbageCollector(
        app(ContentStore::class),
        app(BlobLock::class),
        app(BlobStoreRepository::class),
        app(FileRevisionBlobStoreRepository::class),
        $metrics,
        app(VaulterDatabase::class),
    );
}

function r18Reconciler(FakeMetricsRecorder $metrics): BlobReconciler
{
    return new BlobReconciler(
        app(ContentStore::class),
        app(BlobStoreRepository::class),
        app(FileRevisionBlobStoreRepository::class),
        app(BlobLock::class),
        $metrics,
        app(VaulterDatabase::class),
    );
}

function r18PgAdvisoryLockIsHeld(string $scope, string $sha256): bool
{
    config()->set('database.connections.r18_lock_probe', config('database.connections.pgsql'));
    DB::purge('r18_lock_probe');
    $connection = DB::connection('r18_lock_probe');
    $lockKey = 'cas:'.$scope.':'.$sha256;
    $acquired = (bool) $connection->selectOne(
        'SELECT pg_try_advisory_lock(hashtext(?)) AS acquired',
        [$lockKey],
    )->acquired;

    if ($acquired) {
        $connection->selectOne('SELECT pg_advisory_unlock(hashtext(?))', [$lockKey]);
    }

    DB::disconnect('r18_lock_probe');

    return !$acquired;
}

it('records exact mark metrics and suppresses zero-row batch metrics', function (): void {
    $emptyMetrics = new FakeMetricsRecorder;

    expect(r18Collector($emptyMetrics)->mark(graceHours: 24))->toBe([
        'tombstoned' => 0,
        'skipped' => 0,
    ])->and($emptyMetrics->emissions())->toHaveCount(1)
        ->and($emptyMetrics->emissions()[0]['name'])->toBe('vaulter_gc_duration_seconds')
        ->and($emptyMetrics->emissions()[0]['labels'])->toBe(['phase' => 'mark']);

    $blob = app(BlobStoreContract::class)->commit(CasTestHelpers::streamFor('mark metric one'), scope: BlobScope::forUpload((string) config('vaulter.dedup.scope'), (string) 1, 'unused'));
    $metrics = new FakeMetricsRecorder;

    expect(r18Collector($metrics)->mark(graceHours: 24))->toBe([
        'tombstoned' => 1,
        'skipped' => 0,
    ])->and($metrics->emissions())->toHaveCount(3)
        ->and($metrics->emissions()[2]['name'])->toBe('vaulter_gc_duration_seconds')
        ->and($metrics->emissions()[2]['labels'])->toBe(['phase' => 'mark'])
        ->and($metrics->emissions()[0])->toBe([
            'type' => 'histogram',
            'name' => 'vaulter_gc_batch_rows',
            'value' => 1,
            'labels' => [],
        ])->and($metrics->emissions()[1])->toBe([
            'type' => 'counter',
            'name' => 'vaulter_gc_rows_scanned_total',
            'value' => 1,
            'labels' => [],
        ])->and($blob->fresh()->status)->toBe(BlobStatus::Tombstoned)
        ->and($blob->fresh()->delete_after_at?->equalTo(now()->addHours(24)))->toBeTrue();
});

it('records exact sweep metrics including the zero deletion counter', function (): void {
    $emptyMetrics = new FakeMetricsRecorder;

    expect(r18Collector($emptyMetrics)->sweep())->toBe([
        'swept' => 0,
        'skipped' => 0,
    ])->and($emptyMetrics->emissions())->toHaveCount(2)
        ->and($emptyMetrics->emissions()[0]['name'])->toBe('vaulter_gc_duration_seconds')
        ->and($emptyMetrics->emissions()[0]['labels'])->toBe(['phase' => 'sweep'])
        ->and($emptyMetrics->emissions()[1])->toBe([
            'type' => 'counter',
            'name' => 'vaulter_gc_objects_deleted_total',
            'value' => 0,
            'labels' => [],
        ]);

    $blob = CasTestHelpers::seedBlobRow('sweep metric one', BlobStatus::Tombstoned);
    $blob->forceFill(['delete_after_at' => now()->subMinute()])->save();
    $statuses = [];
    Blob::updated(function (Blob $updated) use (&$statuses, $blob): void {
        if ($updated->is($blob)) {
            $statuses[] = $updated->status;
        }
    });
    $metrics = new FakeMetricsRecorder;

    expect(r18Collector($metrics)->sweep())->toBe([
        'swept' => 1,
        'skipped' => 0,
    ])->and($metrics->emissions())->toHaveCount(3)
        ->and($metrics->emissions()[2]['labels'])->toBe(['phase' => 'sweep'])
        ->and($metrics->emissions()[0])->toBe([
            'type' => 'histogram',
            'name' => 'vaulter_gc_batch_rows',
            'value' => 1,
            'labels' => [],
        ])->and($metrics->emissions()[1])->toBe([
            'type' => 'counter',
            'name' => 'vaulter_gc_objects_deleted_total',
            'value' => 1,
            'labels' => [],
        ])->and($blob->fresh()->status)->toBe(BlobStatus::Deleted)
        ->and($statuses)->toBe([BlobStatus::Deleting, BlobStatus::Deleted]);
    Storage::disk('local')->assertMissing($blob->storage_key);
});

it('adds skipped candidates from both collect phases', function (): void {
    $store = app(BlobStoreContract::class);
    $markSkipped = $store->commit(CasTestHelpers::streamFor('mark skipped'), scope: BlobScope::forUpload((string) config('vaulter.dedup.scope'), (string) 1, 'unused'));
    $store->attach(CasTestHelpers::makeVersion(name: 'mark skipped edge'), $markSkipped);

    $sweepSkipped = $store->commit(CasTestHelpers::streamFor('sweep skipped'), scope: BlobScope::forUpload((string) config('vaulter.dedup.scope'), (string) 1, 'unused'));
    $store->attach(CasTestHelpers::makeVersion(name: 'sweep skipped edge'), $sweepSkipped);
    $sweepSkipped->forceFill([
        'status' => BlobStatus::Tombstoned,
        'delete_after_at' => now()->subMinute(),
    ])->save();

    expect(r18Collector(new FakeMetricsRecorder)->collect(graceHours: 24))->toBe([
        'tombstoned' => 0,
        'swept' => 0,
        'skipped' => 2,
    ]);
});

it('keeps a pending blob seen exactly at the grace cutoff', function (): void {
    $atCutoff = CasTestHelpers::seedBlobRow('pending cutoff', BlobStatus::Pending);
    $older = CasTestHelpers::seedBlobRow('pending older', BlobStatus::Pending);
    $referenced = CasTestHelpers::seedBlobRow('pending referenced', BlobStatus::Pending);
    $atCutoff->forceFill(['first_seen_at' => now()->subHours(2)])->save();
    $older->forceFill(['first_seen_at' => now()->subHours(2)->subSecond()])->save();
    $referenced->forceFill(['first_seen_at' => now()->subHours(3)])->save();
    app(BlobStoreContract::class)->attach(
        CasTestHelpers::makeVersion(name: 'pending referenced edge'),
        $referenced,
    );
    $olderStatuses = [];
    Blob::updated(function (Blob $updated) use (&$olderStatuses, $older): void {
        if ($updated->is($older)) {
            $olderStatuses[] = $updated->status;
        }
    });

    expect(r18Collector(new FakeMetricsRecorder)->sweepPending(graceHours: 2))->toBe([
        'swept' => 1,
        'skipped' => 1,
    ])->and($atCutoff->fresh()->status)->toBe(BlobStatus::Pending)
        ->and($older->fresh()->status)->toBe(BlobStatus::Deleted)
        ->and($referenced->fresh()->status)->toBe(BlobStatus::Pending)
        ->and($olderStatuses)->toBe([BlobStatus::Deleting, BlobStatus::Deleted]);
    Storage::disk('local')->assertMissing($older->storage_key);
    Storage::disk('local')->assertExists($atCutoff->storage_key);
    Storage::disk('local')->assertExists($referenced->storage_key);
});

it('records the exact reconcile count and suppresses its zero-row counter', function (): void {
    $emptyMetrics = new FakeMetricsRecorder;
    expect(r18Reconciler($emptyMetrics)->reconcile())->toBe([
        'reconciled' => 0,
        'changed' => 0,
        'dangling' => [],
    ])->and($emptyMetrics->emissions())->toHaveCount(1)
        ->and($emptyMetrics->emissions()[0]['name'])->toBe('vaulter_reconcile_duration_seconds');

    app(BlobStoreContract::class)->commit(CasTestHelpers::streamFor('reconcile metric one'), scope: BlobScope::forUpload((string) config('vaulter.dedup.scope'), (string) 1, 'unused'));
    $metrics = new FakeMetricsRecorder;

    expect(r18Reconciler($metrics)->reconcile()['reconciled'])->toBe(1)
        ->and($metrics->emissions())->toHaveCount(2)
        ->and($metrics->emissions()[1])->toBe([
            'type' => 'counter',
            'name' => 'vaulter_reconcile_objects_total',
            'value' => 1,
            'labels' => [],
        ]);
});

it('uses the configured disk for an orphan walk when no disk is supplied', function (): void {
    Storage::fake('archive');
    config()->set('vaulter.storage.default_disk', 'archive');
    $orphan = CasTestHelpers::seedOrphan(
        bytes: 'configured archive orphan',
        scope: 't1',
        ageHours: 48,
        disk: 'archive',
    );

    $report = r18Reconciler(new FakeMetricsRecorder)->sweepOrphans(
        graceHours: 24,
        prune: false,
    );

    expect($report['scanned'])->toBe(1)
        ->and($report['orphans'])->toBe([$orphan]);
    Storage::disk('local')->assertMissing($orphan);
});

it('preserves an explicitly supplied orphan disk', function (): void {
    Storage::fake('archive');
    config()->set('vaulter.storage.default_disk', 'local');
    $orphan = CasTestHelpers::seedOrphan(
        bytes: 'explicit archive orphan',
        scope: 't1',
        ageHours: 48,
        disk: 'archive',
    );

    $report = r18Reconciler(new FakeMetricsRecorder)->sweepOrphans(
        disk: 'archive',
        graceHours: 24,
        prune: false,
    );

    expect($report['scanned'])->toBe(1)
        ->and($report['orphans'])->toBe([$orphan]);
});

it('keeps an orphan whose timestamp is exactly at the grace cutoff', function (): void {
    $orphan = CasTestHelpers::seedOrphan(
        bytes: 'orphan at exact cutoff',
        scope: 't1',
        ageHours: 0,
    );
    touch(Storage::disk('local')->path($orphan), now()->subHours(24)->getTimestamp());

    $report = r18Reconciler(new FakeMetricsRecorder)->sweepOrphans(
        disk: 'local',
        graceHours: 24,
        prune: false,
    );

    expect($report['scanned'])->toBe(1)
        ->and($report['orphans'])->toBe([])
        ->and($report['skipped'])->toBe(1);
    Storage::disk('local')->assertExists($orphan);
});

it('returns the complete refusal report when orphan listing is unsupported', function (): void {
    app()->instance(ContentStore::class, new NonListableContentStore);

    expect(r18Reconciler(new FakeMetricsRecorder)->sweepOrphans(
        disk: 'local',
        graceHours: 24,
        prune: true,
    ))->toBe([
        'scanned' => 0,
        'orphans' => [],
        'pruned' => 0,
        'skipped' => 0,
        'listingSupported' => false,
        'pruneSupported' => false,
    ]);
});

it('locks the parsed orphan scope and digest while pruning on PostgreSQL', function (): void {
    $orphan = CasTestHelpers::seedOrphan(
        bytes: 'orphan prune advisory lock',
        scope: 't161803',
        ageHours: 48,
    );
    $sha256 = basename($orphan);

    $report = r18Reconciler(new FakeMetricsRecorder)->sweepOrphans(
        disk: 'local',
        graceHours: 24,
        prune: true,
    );

    expect($report['pruned'])->toBe(1)
        ->and(r18PgAdvisoryLockIsHeld('tenant:161803', $sha256))->toBeTrue();
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'pgsql',
    'PostgreSQL exposes the orphan-prune advisory lock to a second connection.',
);

it('uses the explicit blob disk instead of the configured default', function (): void {
    Storage::fake('archive');

    $blob = app(BlobStoreContract::class)->commit(
        CasTestHelpers::streamFor('explicit archive disk'),
        disk: 'archive', scope: BlobScope::forUpload((string) config('vaulter.dedup.scope'), (string) 1, 'unused'));

    expect($blob->disk)->toBe('archive');
    Storage::disk('archive')->assertExists($blob->storage_key);
    Storage::disk('local')->assertMissing($blob->storage_key);
});

it('takes the tenant advisory lock when committing on PostgreSQL', function (): void {
    $blob = app(BlobStoreContract::class)->commit(
        CasTestHelpers::streamFor('commit advisory lock'), scope: BlobScope::forUpload((string) config('vaulter.dedup.scope'), (string) 314159, 'unused'));

    expect(r18PgAdvisoryLockIsHeld('tenant:314159', $blob->sha256))->toBeTrue();
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'pgsql',
    'PostgreSQL exposes the transaction advisory lock to a second connection.',
);

it('takes the row scope advisory lock while collecting on PostgreSQL', function (): void {
    $blob = CasTestHelpers::seedBlobRow('collector advisory lock', BlobStatus::Committed);
    $blob->forceFill(['tenant_id' => 271828])->save();

    expect(r18Collector(new FakeMetricsRecorder)->mark(graceHours: 24))->toBe([
        'tombstoned' => 1,
        'skipped' => 0,
    ])->and(r18PgAdvisoryLockIsHeld($blob->scope_key, $blob->sha256))->toBeTrue();
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'pgsql',
    'PostgreSQL exposes the transaction advisory lock to a second connection.',
);

it('observes a successful local promotion result and an exact random temp suffix', function (): void {
    $tempKey = null;
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('writeStream')->once()->andReturnUsing(function (string $key) use (&$tempKey): bool {
        $tempKey = $key;

        return true;
    });
    $filesystem->shouldReceive('move')->once()->withArgs(function (string $from, string $to) use (&$tempKey): bool {
        return $from === $tempKey && $to === 'scope/final';
    })->andReturn(true);
    Storage::set('local', $filesystem);

    $result = (new FlysystemStorageDriver)->put(
        'scope/final',
        CasTestHelpers::streamFor('promote success'),
        'local',
    );

    expect($result)->toBeTrue()
        ->and($tempKey)->toMatch('/^scope\/final\.tmp-[0-9a-f]{16}$/');
});

it('returns false and reclaims the temp when the staged write fails', function (): void {
    $tempKey = null;
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('writeStream')->once()->andReturnUsing(function (string $key) use (&$tempKey): bool {
        $tempKey = $key;

        return false;
    });
    $filesystem->shouldReceive('exists')->once()->andReturn(true);
    $filesystem->shouldReceive('delete')->once()->andReturn(true);
    $filesystem->shouldNotReceive('move');
    Storage::set('local', $filesystem);

    expect((new FlysystemStorageDriver)->put(
        'scope/final',
        CasTestHelpers::streamFor('failed stage'),
        'local',
    ))->toBeFalse();
});

it('moves a completed temp for both conditional fallthrough cases', function (bool $ifNoneMatch, bool $canonicalExists): void {
    $tempKey = null;
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('exists')->andReturnUsing(function (string $key) use (&$tempKey, $canonicalExists): bool {
        if ($key === 'scope/final') {
            return $canonicalExists;
        }

        return $key === $tempKey;
    });
    $filesystem->shouldReceive('writeStream')->once()->andReturnUsing(function (string $key) use (&$tempKey): bool {
        $tempKey = $key;

        return true;
    });
    $filesystem->shouldReceive('move')->once()->withArgs(function (string $from, string $to) use (&$tempKey): bool {
        return $from === $tempKey && $to === 'scope/final';
    })->andReturn(true);
    Storage::set('local', $filesystem);

    expect((new FlysystemStorageDriver)->put(
        'scope/final',
        CasTestHelpers::streamFor('conditional fallthrough'),
        'local',
        $ifNoneMatch,
    ))->toBeTrue();
})->with([
    'overwrite may replace an existing key' => [false, true],
    'conditional create continues when the key is absent' => [true, false],
]);

it('does not read or inspect metadata after an object is reported missing', function (): void {
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('exists')->twice()->andReturn(false);
    $filesystem->shouldNotReceive('readStream', 'lastModified');
    Storage::set('plain', $filesystem);
    $driver = new FlysystemStorageDriver;

    expect($driver->get('missing', 'plain'))->toBeNull()
        ->and($driver->lastModified('missing', 'plain'))->toBeNull();
});

it('returns no listing or temporary URL for a non adapter filesystem', function (): void {
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldNotReceive('getDriver', 'providesTemporaryUrls', 'temporaryUrl');
    Storage::set('plain', $filesystem);
    $driver = new FlysystemStorageDriver;

    expect(iterator_to_array($driver->listContents('plain')))->toBe([])
        ->and($driver->temporaryUrl('key', now()->addMinute(), 'plain'))->toBeNull();
});

it('does not ask an adapter for a URL when temporary URLs are unsupported', function (): void {
    $filesystem = Mockery::mock(FilesystemAdapter::class);
    $filesystem->shouldReceive('providesTemporaryUrls')->once()->andReturn(false);
    $filesystem->shouldNotReceive('temporaryUrl');
    Storage::set('plain', $filesystem);

    expect((new FlysystemStorageDriver)->temporaryUrl('key', now()->addMinute(), 'plain'))->toBeNull();
});

it('returns the exact result of an atomic object-store write', function (bool $written): void {
    $filesystem = Mockery::mock(FilesystemAdapter::class);
    $filesystem->shouldReceive('providesTemporaryUrls')->once()->andReturn(true);
    $filesystem->shouldReceive('writeStream')->once()->andReturn($written);
    $filesystem->shouldNotReceive('move', 'delete');
    Storage::set('object-store', $filesystem);

    expect((new FlysystemStorageDriver)->put(
        'scope/final',
        CasTestHelpers::streamFor('atomic object store'),
        'object-store',
    ))->toBe($written);
})->with([
    'successful atomic write' => true,
    'failed atomic write' => false,
]);

it('closes the promoted staged stream even when the driver owns no reference to it', function (): void {
    $handle = CasTestHelpers::streamFor('close me');
    $staged = new class($handle) implements StagedObject
    {
        public function __construct(public mixed $handle) {}

        public function path(): string
        {
            return 'memory';
        }

        public function sha256(): string
        {
            return hash('sha256', 'close me');
        }

        public function sizeBytes(): int
        {
            return strlen('close me');
        }

        public function readStream(): mixed
        {
            return $this->handle;
        }

        public function discard(): void {}
    };
    $driver = Mockery::mock(StorageDriver::class);
    $driver->shouldReceive('exists')->once()->andReturn(false);
    $driver->shouldReceive('put')->once()->andReturn(true);

    (new Sha256ContentStore($driver))->promote($staged, 'key');

    expect(is_resource($staged->handle))->toBeFalse();
});

it('passes an empty checksum for an invalid staged digest', function (): void {
    $handle = CasTestHelpers::streamFor('invalid staged digest');
    $staged = new class($handle) implements StagedObject
    {
        public function __construct(public mixed $handle) {}

        public function path(): string
        {
            return 'memory';
        }

        public function sha256(): string
        {
            return 'invalid';
        }

        public function sizeBytes(): int
        {
            return 21;
        }

        public function readStream(): mixed
        {
            return $this->handle;
        }

        public function discard(): void {}
    };
    $checksum = null;
    $driver = Mockery::mock(StorageDriver::class);
    $driver->shouldReceive('exists')->once()->andReturn(false);
    $driver->shouldReceive('put')->once()->withArgs(function (
        string $key,
        mixed $stream,
        ?string $disk,
        bool $ifNoneMatch,
        array $options,
    ) use (&$checksum): bool {
        $checksum = $options['ChecksumSHA256'] ?? null;

        return $key === 'key' && is_resource($stream) && $disk === null && $ifNoneMatch;
    })->andReturn(true);

    set_error_handler(static fn (): bool => true);

    try {
        (new Sha256ContentStore($driver))->promote($staged, 'key');
    } finally {
        restore_error_handler();
    }

    expect($checksum)->toBe('')
        ->and(is_resource($staged->handle))->toBeFalse();
});

it('throws when a staged temp path disappears before it is reopened', function (): void {
    $staged = new StagedTempObject(
        path: sys_get_temp_dir().'/missing-r18-staged-object',
        sha256: str_repeat('0', 64),
        sizeBytes: 0,
    );

    expect(fn () => $staged->readStream())
        ->toThrow(RuntimeException::class, 'Unable to reopen staged temp file');
});
