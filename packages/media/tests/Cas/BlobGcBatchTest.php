<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Vaulter\Contracts\Cas\BlobStore;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Enums\Cas\BlobStatus;
use Vaulter\Media\Cas\BlobGarbageCollector;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\FileRevisionBlob;
use Vaulter\Support\Config;
use Vaulter\Testing\FakeMetricsRecorder;

beforeEach(function (): void {
    Storage::fake('local');
    $this->freezeSecond();
    $this->metrics = new FakeMetricsRecorder;
    $this->app->instance(MetricsRecorder::class, $this->metrics);
});

/** @return list<Blob> */
function r21SeedCandidates(int $count, BlobStatus $status = BlobStatus::Committed): array
{
    $blobs = [];

    for ($i = 0; $i < $count; $i++) {
        $blob = CasTestHelpers::seedBlobRow('r21 candidate '.$i, $status);

        if ($status !== BlobStatus::Committed) {
            $blob->forceFill([
                'delete_after_at' => now()->subHour(),
                'first_seen_at' => now()->subDays(2),
            ])->save();
        }

        $blobs[] = $blob;
    }

    return $blobs;
}

/** @return list<int|float> */
function r21MetricValues(FakeMetricsRecorder $metrics, string $name): array
{
    return array_values(array_map(
        static fn (array $emission): int|float => $emission['value'],
        array_filter($metrics->emissions(), static fn (array $emission): bool => $emission['name'] === $name),
    ));
}

it('caps mark batches and advances past live candidates without per-row liveness reads', function (): void {
    $blobs = r21SeedCandidates(2105);
    $version = CasTestHelpers::makeVersion();
    $store = app(BlobStore::class);

    // Stale ref_count pins candidates in the scan; both full batches contain
    // skipped rows, including the checkpoint boundary.
    foreach ([0, 999, 1000, 1999, 2104] as $index) {
        $store->attach($version, $blobs[$index]);
    }

    $reads = [];
    DB::listen(function (QueryExecuted $query) use (&$reads): void {
        if (str_starts_with(strtolower($query->sql), 'select')) {
            $reads[] = $query->sql;
        }
    });

    expect(app(BlobGarbageCollector::class)->mark(graceHours: 24))->toBe([
        'tombstoned' => 2100,
        'skipped' => 5,
    ])->and(count($reads))->toBeLessThan(20)
        ->and(r21MetricValues($this->metrics, 'vaulter_gc_batch_rows'))->toBe([1000, 1000, 105])
        ->and(r21MetricValues($this->metrics, 'vaulter_gc_rows_scanned_total'))->toBe([1000, 1000, 105]);

    expect(Blob::query()->where('status', BlobStatus::Tombstoned)->count())->toBe(2100)
        ->and($blobs[999]->fresh()->status)->toBe(BlobStatus::Committed)
        ->and($blobs[2000]->fresh()->delete_after_at->equalTo(now()->addHours(24)))->toBeTrue();
});

it('caps sweep and pending batches while skipped rows stay eligible', function (BlobStatus $status): void {
    $blobs = r21SeedCandidates(1005, $status);
    $version = CasTestHelpers::makeVersion();
    app(BlobStore::class)->attach($version, $blobs[999]);

    $collector = app(BlobGarbageCollector::class);
    $result = $status === BlobStatus::Pending ? $collector->sweepPending(graceHours: 24) : $collector->sweep();

    expect($result)->toBe(['swept' => 1004, 'skipped' => 1])
        ->and(r21MetricValues($this->metrics, 'vaulter_gc_batch_rows'))->toBe([1000, 5])
        ->and($blobs[999]->fresh()->status)->toBe($status)
        ->and($blobs[1004]->fresh()->status)->toBe(BlobStatus::Deleted);

    Storage::disk('local')->assertExists($blobs[999]->storage_key);
    Storage::disk('local')->assertMissing($blobs[1004]->storage_key);

    if ($status === BlobStatus::Tombstoned) {
        expect(r21MetricValues($this->metrics, 'vaulter_gc_objects_deleted_total'))->toBe([999, 5]);
    }
})->with([BlobStatus::Tombstoned, BlobStatus::Pending]);

it('does not extend the current pass for candidates inserted after its high-water key', function (): void {
    r21SeedCandidates(1001);
    $inserted = null;
    Blob::updated(function (Blob $blob) use (&$inserted): void {
        if ($inserted === null && $blob->status === BlobStatus::Tombstoned) {
            $inserted = CasTestHelpers::seedBlobRow('inserted during mark', BlobStatus::Committed);
        }
    });

    $collector = app(BlobGarbageCollector::class);
    expect($collector->mark(graceHours: 24))->toBe(['tombstoned' => 1001, 'skipped' => 0])
        ->and($inserted->fresh()->status)->toBe(BlobStatus::Committed)
        ->and(r21MetricValues($this->metrics, 'vaulter_gc_batch_rows'))->toBe([1000, 1])
        ->and($collector->mark(graceHours: 24))->toBe(['tombstoned' => 1, 'skipped' => 0]);
});

it('rechecks a later candidate whose live edge is added by an earlier model observer', function (): void {
    [$first, $later] = r21SeedCandidates(2);
    $version = CasTestHelpers::makeVersion();
    $store = app(BlobStore::class);

    Blob::updated(function (Blob $blob) use ($first, $later, $version, $store): void {
        if ($blob->is($first)) {
            $store->attach($version, $later);
        }
    });

    expect(app(BlobGarbageCollector::class)->mark(graceHours: 24))->toBe(['tombstoned' => 1, 'skipped' => 1])
        ->and($later->fresh()->status)->toBe(BlobStatus::Committed);
    Storage::disk('local')->assertExists($later->storage_key);
});

it('rechecks live edges and status after the cheap scan in the optimized mark path', function (): void {
    [$live, $changed, $orphan] = r21SeedCandidates(3);
    $version = CasTestHelpers::makeVersion();
    $injected = false;
    DB::listen(function (QueryExecuted $query) use (&$injected, $live, $changed, $version): void {
        if (!$injected && str_contains($query->sql, 'limit 1000')) {
            $injected = true;
            app(BlobStore::class)->attach($version, $live);
            $changed->forceFill(['status' => BlobStatus::Pending])->save();
        }
    });

    expect(app(BlobGarbageCollector::class)->mark(graceHours: 24))->toBe(['tombstoned' => 1, 'skipped' => 2])
        ->and($live->fresh()->status)->toBe(BlobStatus::Committed)
        ->and($changed->fresh()->status)->toBe(BlobStatus::Pending)
        ->and($orphan->fresh()->status)->toBe(BlobStatus::Tombstoned);
});

it('rechecks status liveness and grace for a materialized deletion batch', function (BlobStatus $status): void {
    [$live, $changed, $recent, $orphan] = r21SeedCandidates(4, $status);
    $version = CasTestHelpers::makeVersion();
    $injected = false;
    DB::listen(function (QueryExecuted $query) use (&$injected, $live, $changed, $recent, $version, $status): void {
        if (!$injected && str_contains($query->sql, 'limit 1000')) {
            $injected = true;
            app(BlobStore::class)->attach($version, $live);
            $changed->forceFill(['status' => BlobStatus::Committed])->save();
            $recent->forceFill($status === BlobStatus::Pending
                ? ['first_seen_at' => now()]
                : ['delete_after_at' => now()->addHour()])->save();
        }
    });

    $collector = app(BlobGarbageCollector::class);
    $result = $status === BlobStatus::Pending ? $collector->sweepPending(graceHours: 24) : $collector->sweep();
    expect($result)->toBe(['swept' => 1, 'skipped' => 3]);

    foreach ([$live, $changed, $recent] as $blob) {
        Storage::disk('local')->assertExists($blob->storage_key);
    }
    Storage::disk('local')->assertMissing($orphan->storage_key);
})->with([BlobStatus::Tombstoned, BlobStatus::Pending]);

it('retains completed checkpoints when a later optimized mark batch rolls back', function (): void {
    $blobs = r21SeedCandidates(1005);
    $updates = 0;
    DB::listen(function (QueryExecuted $query) use (&$updates): void {
        if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, Config::table('blobs'))) {
            if (++$updates === 1002) {
                throw new RuntimeException('Later batch failed');
            }
        }
    });

    expect(fn () => app(BlobGarbageCollector::class)->mark(graceHours: 24))->toThrow(RuntimeException::class, 'Later batch failed');
    expect(Blob::query()->where('status', BlobStatus::Tombstoned)->count())->toBe(1000)
        ->and($blobs[1000]->fresh()->status)->toBe(BlobStatus::Committed)
        ->and(r21MetricValues($this->metrics, 'vaulter_gc_batch_rows'))->toBe([1000])
        ->and(r21MetricValues($this->metrics, 'vaulter_gc_rows_scanned_total'))->toBe([1000]);
});

it('advances sparse string and native UUID keys with configured models', function (string $keyType): void {
    Schema::create('r21_string_blobs', function (Blueprint $table) use ($keyType): void {
        $table->{$keyType}('blob_key')->primary();
        $table->string('sha256');
        $table->string('dedup_scope');
        $table->string('scope_key');
        $table->string('status');
        $table->unsignedInteger('ref_count');
        $table->timestamp('delete_after_at')->nullable();
    });
    Schema::create('r21_string_edges', function (Blueprint $table) use ($keyType): void {
        $table->{$keyType}('blob_id');
    });
    $model = new class extends Blob
    {
        protected $primaryKey = 'blob_key';

        protected $keyType = 'string';

        public $incrementing = false;

        public function getTable(): string
        {
            return 'r21_string_blobs';
        }
    };
    $edgeModel = new class extends FileRevisionBlob
    {
        public function getTable(): string
        {
            return 'r21_string_edges';
        }
    };
    config()->set('vaulter.models.blob', $model::class);
    config()->set('vaulter.models.file_version_blob', $edgeModel::class);
    $keyFor = static fn (int $index): string => $keyType === 'uuid'
        ? sprintf('00000000-0000-7000-8000-%012d', $index * 3)
        : sprintf('blob-%08d', $index * 3);

    for ($i = 1004; $i >= 0; $i--) {
        (new ($model::class))->forceFill([
            'blob_key' => $keyFor($i),
            'sha256' => hash('sha256', 'string blob '.$i),
            'dedup_scope' => 'global',
            'scope_key' => 'global',
            'status' => BlobStatus::Committed,
            'ref_count' => 0,
        ])->save();
    }

    (new ($edgeModel::class))->forceFill(['blob_id' => $keyFor(999)])->save();

    expect(app(BlobGarbageCollector::class)->mark(graceHours: 24))->toBe(['tombstoned' => 1004, 'skipped' => 1])
        ->and(r21MetricValues($this->metrics, 'vaulter_gc_batch_rows'))->toBe([1000, 5])
        ->and($model::query()->where('status', BlobStatus::Tombstoned)->count())->toBe(1004)
        ->and($model::query()->find($keyFor(999))->status)->toBe(BlobStatus::Committed);
})->with(['string', 'uuid']);

it('uses the same PostgreSQL lock namespace for every batched mark candidate before liveness reads', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL advisory transaction locks require the package PG lane.');
    }

    $blobs = r21SeedCandidates(3);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    expect(app(BlobGarbageCollector::class)->mark(graceHours: 24)['tombstoned'])->toBe(3);
    $lockIndex = array_find_key($queries, static fn (string $sql): bool => str_contains($sql, 'pg_advisory_xact_lock'));
    $edgeIndex = array_find_key($queries, static fn (string $sql): bool => str_contains($sql, Config::table('file_revision_blobs')));
    expect($lockIndex)->not->toBeNull()->and($lockIndex)->toBeLessThan($edgeIndex);

    config()->set('database.connections.r21_probe', config('database.connections.testing'));
    $probe = DB::connection('r21_probe');

    try {
        foreach ($blobs as $blob) {
            $key = 'cas:'.$blob->scope_key.':'.$blob->sha256;
            $acquired = $probe->selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS acquired', [$key])->acquired;

            if ($acquired) {
                $probe->selectOne('SELECT pg_advisory_unlock(hashtext(?))', [$key]);
            }

            expect($acquired)->toBeFalse();
        }
    } finally {
        DB::purge('r21_probe');
    }
});
