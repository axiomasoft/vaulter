<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Vaulter\Cas\BlobLock;
use Vaulter\Contracts\Cas\ContentStore;
use Vaulter\Contracts\Cas\StagedObject;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Enums\Cas\BlobStatus;
use Vaulter\Media\Cas\BlobGarbageCollector;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\Repositories\Cas\BlobStoreRepository;
use Vaulter\Repositories\Drive\FileRevisionBlobStoreRepository;
use Vaulter\ValueObjects\Cas\BlobScope;

/**
 * sweepPending must run its critical section through the SERIALIZABLE + retry-on-40001
 * wrapper on PostgreSQL, symmetric with sweep() (F-P1.5-02). Without the wrapper a
 * transient serialization failure (SQLSTATE 40001) propagates as a QueryException and
 * aborts the whole GC pass instead of being retried. Real PG only — the retry path is
 * PG-specific (the FOR-UPDATE/single-writer path serialises elsewhere).
 */
beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('The 40001 serialization-retry path is PostgreSQL-specific.');
    }

    Storage::fake('local');
    config()->set('vaulter.dedup.scope', 'tenant');
});

it('sweepPending ретраит транзиентный serialization-failure 40001 вместо проброса, F-P1.5-02', function (): void {
    // A pending blob past the grace window with no live edge — a sweepPending target.
    $blob = CasTestHelpers::seedBlobRow(bytes: 'crashed staging bytes', status: BlobStatus::Pending);
    $blob->forceFill(['first_seen_at' => now()->subDay()])->save();
    $key = $blob->storage_key;

    Storage::disk('local')->assertExists($key);

    // A ContentStore that throws a 40001 serialization failure the FIRST time it is
    // asked to delete the object, then delegates — modelling a transient PG conflict
    // inside the sweepPending critical section.
    $real = app(ContentStore::class);
    $flaky = new class($real) implements ContentStore
    {
        private int $deletes = 0;

        public function __construct(private ContentStore $inner) {}

        public function stage(mixed $stream): StagedObject
        {
            return $this->inner->stage($stream);
        }

        public function promote(StagedObject $staged, string $key, ?string $disk = null): void
        {
            $this->inner->promote($staged, $key, $disk);
        }

        public function get(string $key, ?string $disk = null): mixed
        {
            return $this->inner->get($key, $disk);
        }

        public function exists(string $key, ?string $disk = null): bool
        {
            return $this->inner->exists($key, $disk);
        }

        public function delete(string $key, ?string $disk = null): void
        {
            if ($this->deletes++ === 0) {
                throw new QueryException(
                    'testing',
                    'delete blob object',
                    [],
                    new RuntimeException('SQLSTATE[40001]: serialization_failure: could not serialize access'),
                );
            }

            $this->inner->delete($key, $disk);
        }

        public function keyFor(BlobScope $scope, string $sha256): string
        {
            return $this->inner->keyFor($scope, $sha256);
        }
    };

    $gc = new BlobGarbageCollector(
        $flaky,
        app(BlobLock::class),
        app(BlobStoreRepository::class),
        app(FileRevisionBlobStoreRepository::class),
        app(MetricsRecorder::class),
        app(VaulterDatabase::class),
    );

    // Pre-fix: the first 40001 propagates and aborts the pass. Post-fix: the unit is
    // retried under serializable() and the blob is swept on the second attempt.
    $result = $gc->sweepPending(graceHours: 1);

    expect($result['swept'])->toBe(1)
        ->and($blob->fresh()->status)->toBe(BlobStatus::Deleted);

    Storage::disk('local')->assertMissing($key);
});
