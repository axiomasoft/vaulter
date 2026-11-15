<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Command\Command;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Media\Cas\BlobScrubber;
use Vaulter\Media\Configuration\MediaConfig;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Contracts\Cas\StorageDriver;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\Media\ValueObjects\Cas\BlobScope;
use Vaulter\Testing\FakeMetricsRecorder;

/*
 * The integrity scrub of the CAS: the sample is the never-checked blobs first, then the least recently checked;
 * the first used-up budget ends the run; a missing object, a wrong size and a wrong digest mark the blob; nothing is
 * ever deleted; a dry run writes nothing; the exit code and the metrics tell the result.
 */

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
    Carbon::setTestNow('2026-10-18 12:00:00');
    $this->metrics = new FakeMetricsRecorder;
    $this->app->instance(MetricsRecorder::class, $this->metrics);
});

function scrubCommit(string $bytes, string $tenant = '1'): Blob
{
    return CasTestHelpers::row(app(BlobStore::class)->commit(CasTestHelpers::streamFor($bytes), BlobScope::tenant($tenant)));
}

/** Set columns of a blob row as the scrub (or an operator) would have left them. */
function scrubSet(Blob $blob, array $attributes): Blob
{
    CasTestHelpers::write(static fn () => $blob->forceFill($attributes)->save());

    return $blob->fresh();
}

/** @return list<string> */
function scrubVisited(array $result): array
{
    return array_map(static fn (array $failure): string => $failure['id'], $result['failures']);
}

function scrubRows(): array
{
    return DB::table((new Blob)->getTable())->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
}

/** @return array<string, int|float> */
function scrubCounters(FakeMetricsRecorder $metrics, string $name): array
{
    $values = [];
    foreach ($metrics->emissions() as $emission) {
        if ($emission['type'] === 'counter' && $emission['name'] === $name) {
            $values[$emission['labels']['reason'] ?? ''] = $emission['value'];
        }
    }

    return $values;
}

it('marks nothing and moves the check time of an intact blob', function (): void {
    $blob = scrubCommit('intact bytes');

    $result = app(BlobScrubber::class)->scrub();

    $fresh = $blob->fresh();
    expect($result)->toMatchArray(['visited' => 1, 'checked' => 1, 'bytes' => strlen('intact bytes'), 'failures' => [], 'unverified' => [], 'stopped_by' => 'exhausted'])
        ->and($fresh->isIntact())->toBeTrue()
        ->and($fresh->integrity_failed_at)->toBeNull()
        ->and($fresh->integrity_checked_at?->toDateTimeString())->toBe('2026-10-18 12:00:00');
});

it('marks a missing object, a wrong size and a wrong digest, each with its reason, and deletes nothing', function (): void {
    $missing = scrubCommit('object is gone');
    $short = scrubCommit('object is truncated');
    $long = scrubCommit('object has a tail');
    $bent = scrubCommit('object has other bytes');
    Storage::disk('local')->delete($missing->storage_key);
    Storage::disk('local')->put($short->storage_key, 'object is');
    Storage::disk('local')->put($long->storage_key, 'object has a tail and more');
    Storage::disk('local')->put($bent->storage_key, str_repeat('x', strlen('object has other bytes')));
    CasTestHelpers::pin($bent);
    $rowsBefore = Blob::query()->count();

    $result = app(BlobScrubber::class)->scrub();

    $reasons = [];
    foreach ($result['failures'] as $failure) {
        $reasons[$failure['id']] = $failure['reason'];
    }
    expect($reasons)->toBe([
        (string) $missing->getKey() => 'missing',
        (string) $short->getKey() => 'size',
        (string) $long->getKey() => 'size',
        (string) $bent->getKey() => 'digest',
    ])->and(Blob::query()->count())->toBe($rowsBefore)
        ->and(Blob::query()->whereNotNull('integrity_failed_at')->count())->toBe(4)
        ->and(Storage::disk('local')->exists($short->storage_key))->toBeTrue()
        ->and(Storage::disk('local')->exists($bent->storage_key))->toBeTrue()
        ->and(CasTestHelpers::pins($bent))->toBe(1)
        ->and($bent->fresh()->status)->toBe(BlobStatus::Committed)
        ->and($bent->fresh()->isIntact())->toBeFalse()
        ->and(scrubCounters($this->metrics, 'vaulter_blobs_scrub_failed_total'))->toBe(['missing' => 1, 'size' => 2, 'digest' => 1]);
});

it('takes the never checked blobs first, then the least recently checked', function (): void {
    $recent = scrubSet(scrubCommit('checked recently'), ['integrity_checked_at' => '2026-10-17 12:00:00']);
    $old = scrubSet(scrubCommit('checked long ago'), ['integrity_checked_at' => '2026-09-01 12:00:00']);
    $never = scrubCommit('never checked');
    foreach ([$recent, $old, $never] as $blob) {
        Storage::disk('local')->put($blob->storage_key, 'bent');
    }

    $result = app(BlobScrubber::class)->scrub(limit: 2);

    expect(scrubVisited($result))->toBe([(string) $never->getKey(), (string) $old->getKey()])
        ->and($result['stopped_by'])->toBe('limit')
        ->and($recent->fresh()->integrity_failed_at)->toBeNull();
});

it('pages through the queue in the same order', function (): void {
    $blobs = [];
    foreach (range(1, 5) as $i) {
        $blobs[] = scrubSet(scrubCommit('queued '.$i), ['integrity_checked_at' => $i === 3 ? null : sprintf('2026-10-%02d 12:00:00', $i)]);
        Storage::disk('local')->put($blobs[$i - 1]->storage_key, 'bent');
    }

    $result = app(BlobScrubber::class)->scrub(limit: 5, dryRun: true);

    // 3 was never checked; then by the check time (1, 2, 4, 5).
    expect(scrubVisited($result))->toBe(array_map(static fn (int $i): string => (string) $blobs[$i - 1]->getKey(), [3, 1, 2, 4, 5]));
});

it('ends at the first used-up budget: limit, bytes or seconds', function (): void {
    foreach (['aaaaaaaaaa', 'bbbbbbbbbb', 'cccccccccc', 'dddddddddd'] as $bytes) {
        scrubCommit($bytes);
    }

    $limited = app(BlobScrubber::class)->scrub(limit: 2, maxBytes: 1000, dryRun: true);
    $byBytes = app(BlobScrubber::class)->scrub(limit: 4, maxBytes: 15, dryRun: true);
    $unbounded = app(BlobScrubber::class)->scrub(limit: 10, dryRun: true);

    expect($limited)->toMatchArray(['visited' => 2, 'stopped_by' => 'limit'])
        // 10 bytes < 15: the second blob is read, the third is not started.
        ->and($byBytes)->toMatchArray(['visited' => 2, 'bytes' => 20, 'stopped_by' => 'max_bytes'])
        ->and($unbounded)->toMatchArray(['visited' => 4, 'bytes' => 40, 'stopped_by' => 'exhausted']);
});

it('ends when the seconds are used up', function (): void {
    scrubCommit('slow one');
    scrubCommit('slow two');
    $driver = app(StorageDriver::class);
    app()->instance(StorageDriver::class, new class($driver) implements StorageDriver
    {
        public function __construct(private readonly StorageDriver $inner) {}

        public function disk(?string $disk = null): Filesystem
        {
            return $this->inner->disk($disk);
        }

        public function put(string $key, mixed $stream, ?string $disk = null, bool $ifNoneMatch = false, array $options = []): bool
        {
            return $this->inner->put($key, $stream, $disk, $ifNoneMatch, $options);
        }

        public function get(string $key, ?string $disk = null): mixed
        {
            usleep(1_200_000);

            return $this->inner->get($key, $disk);
        }

        public function exists(string $key, ?string $disk = null): bool
        {
            return $this->inner->exists($key, $disk);
        }

        public function delete(string $key, ?string $disk = null): void
        {
            $this->inner->delete($key, $disk);
        }

        public function temporaryUrl(string $key, DateTimeInterface $expiresAt, ?string $disk = null): ?string
        {
            return $this->inner->temporaryUrl($key, $expiresAt, $disk);
        }
    });

    $result = app(BlobScrubber::class)->scrub(maxSeconds: 1, dryRun: true);

    expect($result)->toMatchArray(['visited' => 1, 'stopped_by' => 'max_seconds']);
});

it('takes the sample and the budgets from vaulter-media.scrub when no option is given', function (): void {
    foreach (['one', 'two', 'three'] as $bytes) {
        scrubCommit($bytes);
    }
    config()->set('vaulter-media.scrub.limit', '2');

    expect(app(BlobScrubber::class)->scrub(dryRun: true))->toMatchArray(['visited' => 2, 'stopped_by' => 'limit']);

    config()->set('vaulter-media.scrub.limit', 100);
    config()->set('vaulter-media.scrub.max_bytes', 4);

    expect(app(BlobScrubber::class)->scrub(dryRun: true))->toMatchArray(['visited' => 2, 'bytes' => 6, 'stopped_by' => 'max_bytes']);

    config()->set('vaulter-media.scrub.max_bytes', null);
    config()->set('vaulter-media.scrub.limit', 0);

    expect(fn () => app(MediaConfig::class)->scrubLimit())->toThrow(InvalidConfigurationException::class);
});

it('leaves chunked, pending, tombstoned and deleted blobs alone and honours the scope', function (): void {
    $mine = scrubCommit('in my scope', '1');
    $other = scrubCommit('in another scope', '2');
    $chunked = scrubSet(scrubCommit('chunked'), ['chunked' => true]);
    $pending = scrubSet(scrubCommit('pending'), ['status' => BlobStatus::Pending]);
    $tombstone = scrubSet(scrubCommit('tombstone'), ['status' => BlobStatus::Tombstoned, 'delete_after_at' => now()->addDay()]);
    $deleted = scrubSet(scrubCommit('deleted'), ['status' => BlobStatus::Deleted]);
    foreach ([$mine, $other, $chunked, $pending, $tombstone, $deleted] as $blob) {
        Storage::disk('local')->delete($blob->storage_key);
    }

    $scoped = app(BlobScrubber::class)->scrub(scope: 'tenant:1', dryRun: true);
    $all = app(BlobScrubber::class)->scrub(dryRun: true);

    expect(scrubVisited($scoped))->toContain((string) $mine->getKey())
        ->and($scoped['visited'])->toBe(1)
        ->and(scrubVisited($all))->toContain((string) $mine->getKey(), (string) $other->getKey())
        ->and($all['visited'])->toBe(2);
});

it('writes nothing in a dry run, found failures included', function (): void {
    $bent = scrubCommit('dry run bent');
    scrubCommit('dry run intact');
    Storage::disk('local')->put($bent->storage_key, 'xxxxxxxxxxxx');
    $before = scrubRows();

    $result = app(BlobScrubber::class)->scrub(dryRun: true);

    expect($result['failures'])->toBe([['id' => (string) $bent->getKey(), 'reason' => 'digest']])
        ->and($result['checked'])->toBe(2)
        ->and(scrubRows())->toBe($before)
        ->and($this->metrics->emissions())->toBe([]);
});

it('records no verdict when the row changed while its bytes were read', function (): void {
    $blob = scrubCommit('healed meanwhile');
    Storage::disk('local')->put($blob->storage_key, 'bent bytes here!');
    $driver = app(StorageDriver::class);
    // The ingest heals the blob between the read of the object and the write of the verdict.
    app()->instance(StorageDriver::class, new class($driver, $blob) implements StorageDriver
    {
        public function __construct(private readonly StorageDriver $inner, private readonly Blob $blob) {}

        public function disk(?string $disk = null): Filesystem
        {
            return $this->inner->disk($disk);
        }

        public function put(string $key, mixed $stream, ?string $disk = null, bool $ifNoneMatch = false, array $options = []): bool
        {
            return $this->inner->put($key, $stream, $disk, $ifNoneMatch, $options);
        }

        public function get(string $key, ?string $disk = null): mixed
        {
            $stream = $this->inner->get($key, $disk);
            CasTestHelpers::write(fn () => $this->blob->newQuery()->whereKey($this->blob->getKey())->update(['integrity_checked_at' => '2026-10-01 00:00:00']));

            return $stream;
        }

        public function exists(string $key, ?string $disk = null): bool
        {
            return $this->inner->exists($key, $disk);
        }

        public function delete(string $key, ?string $disk = null): void
        {
            $this->inner->delete($key, $disk);
        }

        public function temporaryUrl(string $key, DateTimeInterface $expiresAt, ?string $disk = null): ?string
        {
            return $this->inner->temporaryUrl($key, $expiresAt, $disk);
        }
    });

    $result = app(BlobScrubber::class)->scrub();

    expect($result['failures'])->toBe([])
        ->and($blob->fresh()->integrity_failed_at)->toBeNull();
});

it('reports an object that cannot be read as unverified and does not mark it', function (): void {
    $blob = scrubCommit('store is failing');
    app()->instance(StorageDriver::class, new class implements StorageDriver
    {
        public function disk(?string $disk = null): Filesystem
        {
            throw new RuntimeException('not used');
        }

        public function put(string $key, mixed $stream, ?string $disk = null, bool $ifNoneMatch = false, array $options = []): bool
        {
            return false;
        }

        public function get(string $key, ?string $disk = null): mixed
        {
            throw new RuntimeException('the store is down');
        }

        public function exists(string $key, ?string $disk = null): bool
        {
            return true;
        }

        public function delete(string $key, ?string $disk = null): void {}

        public function temporaryUrl(string $key, DateTimeInterface $expiresAt, ?string $disk = null): ?string
        {
            return null;
        }
    });
    $before = scrubRows();

    $result = app(BlobScrubber::class)->scrub();
    $exit = Artisan::call('vaulter:blobs:scrub');

    expect($result)->toMatchArray(['visited' => 1, 'checked' => 0, 'failures' => [], 'unverified' => [(string) $blob->getKey()]])
        ->and(scrubRows())->toBe($before)
        ->and($exit)->toBe(Command::FAILURE)
        ->and(Artisan::output())->toContain('Blobs that could not be read, left unmarked: '.$blob->getKey());
});

it('emits the checked, bytes and failed counters of a run', function (): void {
    scrubCommit('twelve bytes');
    $bent = scrubCommit('another one!');
    Storage::disk('local')->put($bent->storage_key, 'x');

    app(BlobScrubber::class)->scrub();

    // 12 bytes, then 1 byte of the bent object read twice: the failure is read again under the lock.
    expect(scrubCounters($this->metrics, 'vaulter_blobs_scrub_checked_total'))->toBe(['' => 2])
        ->and(scrubCounters($this->metrics, 'vaulter_blobs_scrub_bytes_total'))->toBe(['' => 14])
        ->and(scrubCounters($this->metrics, 'vaulter_blobs_scrub_failed_total'))->toBe(['missing' => 0, 'size' => 1, 'digest' => 0]);
});

it('keeps the first failure time of a blob that stays bad and never clears the mark by itself', function (): void {
    $blob = scrubCommit('stays bad');
    Storage::disk('local')->put($blob->storage_key, 'bad');
    app(BlobScrubber::class)->scrub();
    $first = $blob->fresh()->integrity_failed_at?->toDateTimeString();

    Carbon::setTestNow('2026-10-19 12:00:00');
    $again = app(BlobScrubber::class)->scrub();
    $afterSecond = $blob->fresh();

    expect($first)->toBe('2026-10-18 12:00:00')
        ->and($again['failures'])->toHaveCount(1)
        ->and($afterSecond->integrity_failed_at?->toDateTimeString())->toBe('2026-10-18 12:00:00')
        ->and($afterSecond->integrity_checked_at?->toDateTimeString())->toBe('2026-10-19 12:00:00');

    // The bytes are back, but only an ingest lifts the mark.
    Storage::disk('local')->put($blob->storage_key, 'stays bad');
    Carbon::setTestNow('2026-10-20 12:00:00');
    $clean = app(BlobScrubber::class)->scrub();

    expect($clean['failures'])->toBe([])
        ->and($blob->fresh()->isIntact())->toBeFalse();
});

describe('vaulter:blobs:scrub', function (): void {
    it('exits 0 and reports the totals when every blob is intact', function (): void {
        scrubCommit('command intact');

        $exit = Artisan::call('vaulter:blobs:scrub');

        expect($exit)->toBe(Command::SUCCESS)
            ->and(Artisan::output())->toContain('Scrubbed 1 blobs ('.strlen('command intact').' bytes), found 0 damaged; stopped by exhausted.');
    });

    it('exits 1 and names the damaged blobs with their reasons', function (): void {
        $bent = scrubCommit('command bent');
        Storage::disk('local')->delete($bent->storage_key);

        $exit = Artisan::call('vaulter:blobs:scrub');

        expect($exit)->toBe(Command::FAILURE)
            ->and(Artisan::output())->toContain('found 1 damaged')
            ->toContain('Damaged blobs: '.$bent->getKey().' (missing)')
            ->and($bent->fresh()->isIntact())->toBeFalse();
    });

    it('exits 1 for a dry run that finds a failure and writes nothing', function (): void {
        $bent = scrubCommit('command dry run');
        Storage::disk('local')->delete($bent->storage_key);
        $before = scrubRows();

        $exit = Artisan::call('vaulter:blobs:scrub', ['--dry-run' => true]);

        expect($exit)->toBe(Command::FAILURE)
            ->and(Artisan::output())->toContain('(dry run, nothing recorded).')
            ->and(scrubRows())->toBe($before);
    });

    it('passes the options on', function (): void {
        scrubCommit('option one');
        scrubCommit('option two');
        scrubCommit('other scope', '9');

        Artisan::call('vaulter:blobs:scrub', ['--limit' => '1', '--dry-run' => true]);
        $limited = Artisan::output();
        Artisan::call('vaulter:blobs:scrub', ['--scope' => 'tenant:9', '--dry-run' => true]);
        $scoped = Artisan::output();
        Artisan::call('vaulter:blobs:scrub', ['--max-bytes' => '1', '--max-seconds' => '60', '--dry-run' => true]);
        $budget = Artisan::output();

        expect($limited)->toContain('Scrubbed 1 blobs')->toContain('stopped by limit')
            ->and($scoped)->toContain('Scrubbed 1 blobs ('.strlen('other scope').' bytes)')
            ->and($budget)->toContain('Scrubbed 1 blobs')->toContain('stopped by max_bytes');
    });

    it('exits 2 for an invalid option and does nothing', function (array $options): void {
        $bent = scrubCommit('invalid options');
        Storage::disk('local')->delete($bent->storage_key);
        $before = scrubRows();

        $exit = Artisan::call('vaulter:blobs:scrub', $options);

        expect($exit)->toBe(2)
            ->and(scrubRows())->toBe($before);
    })->with([
        'limit zero' => [['--limit' => '0']],
        'limit text' => [['--limit' => 'many']],
        'limit negative' => [['--limit' => '-5']],
        'max bytes decimal' => [['--max-bytes' => '1.5']],
        'max seconds zero' => [['--max-seconds' => '0']],
        'scope without a kind' => [['--scope' => '1']],
        'scope of an unknown kind' => [['--scope' => 'team:1']],
    ]);
});

it('drops a failing verdict when an ingest restored the object after the read', function (): void {
    $blob = scrubCommit('restored meanwhile');
    Storage::disk('local')->delete($blob->storage_key);
    $inner = app(StorageDriver::class);
    // The first read finds the object missing (and the store confirms it is not there); the same bytes are ingested
    // before the verdict is written. Neither integrity column changes (the row is committed, was never marked), so
    // only a second read can tell.
    app()->instance(StorageDriver::class, new class($inner) implements StorageDriver
    {
        private bool $restored = false;

        public function __construct(private readonly StorageDriver $inner) {}

        public function disk(?string $disk = null): Filesystem
        {
            return $this->inner->disk($disk);
        }

        public function put(string $key, mixed $stream, ?string $disk = null, bool $ifNoneMatch = false, array $options = []): bool
        {
            return $this->inner->put($key, $stream, $disk, $ifNoneMatch, $options);
        }

        public function get(string $key, ?string $disk = null): mixed
        {
            return $this->inner->get($key, $disk);
        }

        public function exists(string $key, ?string $disk = null): bool
        {
            $exists = $this->inner->exists($key, $disk);

            if (!$this->restored) {
                $this->restored = true;
                scrubCommit('restored meanwhile');
            }

            return $exists;
        }

        public function delete(string $key, ?string $disk = null): void
        {
            $this->inner->delete($key, $disk);
        }

        public function temporaryUrl(string $key, DateTimeInterface $expiresAt, ?string $disk = null): ?string
        {
            return $this->inner->temporaryUrl($key, $expiresAt, $disk);
        }
    });

    $result = app(BlobScrubber::class)->scrub();

    expect($result['failures'])->toBe([])
        ->and($blob->fresh()->integrity_failed_at)->toBeNull()
        ->and($blob->fresh()->integrity_checked_at)->toBeNull()
        ->and(Storage::disk('local')->get($blob->storage_key))->toBe('restored meanwhile')
        ->and(scrubCounters($this->metrics, 'vaulter_blobs_scrub_failed_total'))->toBe(['missing' => 0, 'size' => 0, 'digest' => 0]);
});

/** A driver over the real one whose get() can be replaced. */
function scrubDriver(Closure $get): StorageDriver
{
    return new class(app(StorageDriver::class), $get) implements StorageDriver
    {
        public function __construct(private readonly StorageDriver $inner, private readonly Closure $get) {}

        public function disk(?string $disk = null): Filesystem
        {
            return $this->inner->disk($disk);
        }

        public function put(string $key, mixed $stream, ?string $disk = null, bool $ifNoneMatch = false, array $options = []): bool
        {
            return $this->inner->put($key, $stream, $disk, $ifNoneMatch, $options);
        }

        public function get(string $key, ?string $disk = null): mixed
        {
            return ($this->get)($key, fn (): mixed => $this->inner->get($key, $disk));
        }

        public function exists(string $key, ?string $disk = null): bool
        {
            return $this->inner->exists($key, $disk);
        }

        public function delete(string $key, ?string $disk = null): void
        {
            $this->inner->delete($key, $disk);
        }

        public function temporaryUrl(string $key, DateTimeInterface $expiresAt, ?string $disk = null): ?string
        {
            return $this->inner->temporaryUrl($key, $expiresAt, $disk);
        }
    };
}

/**
 * A read stream that yields $head, then fails as a store that drops the connection would.
 *
 * @return resource
 */
function scrubFailingStream(string $head): mixed
{
    static $wrapper = null;

    if ($wrapper === null) {
        $wrapper = (new class
        {
            public static string $head = '';

            /** @var resource|null */
            public $context;

            private bool $served = false;

            public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
            {
                return true;
            }

            public function stream_read(int $count): string
            {
                if ($this->served) {
                    throw new RuntimeException('connection reset');
                }

                $this->served = true;

                return self::$head;
            }

            public function stream_eof(): bool
            {
                return false;
            }
        })::class;
        stream_wrapper_register('vaulter-scrub-failing', $wrapper);
    }

    $wrapper::$head = $head;

    return fopen('vaulter-scrub-failing://object', 'rb');
}

it('leaves a blob unmarked when the store answers the read with nothing but the object is there', function (): void {
    $blob = scrubCommit('there but unreadable');
    // A Flysystem disk that does not throw answers a failed readStream() with null, as for an absent object.
    app()->instance(StorageDriver::class, scrubDriver(static fn (): mixed => null));
    $before = scrubRows();

    $result = app(BlobScrubber::class)->scrub();

    expect($result)->toMatchArray(['visited' => 1, 'checked' => 0, 'failures' => [], 'unverified' => [(string) $blob->getKey()]])
        ->and(scrubRows())->toBe($before)
        ->and($blob->fresh()->isIntact())->toBeTrue();
});

it('counts the second read of a failure under the lock against the byte budget', function (): void {
    $bent = scrubCommit('aaaaaaaaaa');
    $next = scrubSet(scrubCommit('bbbbbbbbbb'), ['integrity_checked_at' => '2026-10-17 12:00:00']);
    Storage::disk('local')->put($bent->storage_key, 'xxxxxxxxxx');

    $result = app(BlobScrubber::class)->scrub(maxBytes: 15);

    // 10 bytes read, 10 read again under the lock: the budget of 15 is used up before the next blob.
    expect($result)->toMatchArray(['visited' => 1, 'checked' => 1, 'bytes' => 20, 'stopped_by' => 'max_bytes'])
        ->and($next->fresh()->integrity_checked_at?->toDateTimeString())->toBe('2026-10-17 12:00:00');
});

it('counts the bytes of a read that fails against the byte budget', function (): void {
    $failing = scrubCommit('fails half way');
    $next = scrubSet(scrubCommit('never read'), ['integrity_checked_at' => '2026-10-17 12:00:00']);
    app()->instance(StorageDriver::class, scrubDriver(static fn (string $key, Closure $read): mixed => $key === $failing->storage_key
        ? scrubFailingStream('fails')
        : $read()));

    $result = app(BlobScrubber::class)->scrub(maxBytes: 5);

    expect($result)->toMatchArray(['visited' => 1, 'checked' => 0, 'bytes' => 5, 'unverified' => [(string) $failing->getKey()], 'stopped_by' => 'max_bytes'])
        ->and($failing->fresh()->isIntact())->toBeTrue()
        ->and($next->fresh()->integrity_checked_at?->toDateTimeString())->toBe('2026-10-17 12:00:00');
});
