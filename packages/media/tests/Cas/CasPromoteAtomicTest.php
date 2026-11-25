<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Vaulter\Media\Cas\FlysystemStorageDriver;

it('never exposes partial canonical bytes on a signed local disk', function (): void {
    $fake = Storage::fake('signed-local');
    $filesystem = Storage::build(['driver' => 'local', 'root' => $fake->path(''), 'serve' => true, 'throw' => true]);
    Storage::set('signed-local', $filesystem);
    expect($filesystem->providesTemporaryUrls())->toBeTrue();
    $key = 'tenant/ab/cd/canonical';
    $driver = app(FlysystemStorageDriver::class);
    stream_wrapper_register('interrupted-cas-promotion', InterruptedCasPromotionStream::class);
    $stream = fopen('interrupted-cas-promotion://input', 'rb');

    try {
        expect(fn () => $driver->put($key, $stream, 'signed-local', true))
            ->toThrow(RuntimeException::class, 'interrupted CAS input');
    } finally {
        fclose($stream);
        stream_wrapper_unregister('interrupted-cas-promotion');
    }

    expect($filesystem->exists($key))->toBeFalse()->and($filesystem->allFiles())->toBe([]);

    $complete = fopen('php://temp', 'w+b');
    fwrite($complete, 'complete bytes');
    rewind($complete);
    try {
        expect($driver->put($key, $complete, 'signed-local', true))->toBeTrue();
    } finally {
        fclose($complete);
    }
    expect($filesystem->get($key))->toBe('complete bytes')->and($filesystem->allFiles())->toBe([$key]);
});

final class InterruptedCasPromotionStream
{
    public mixed $context;

    private bool $read = false;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        if ($this->read) {
            throw new RuntimeException('interrupted CAS input');
        }
        $this->read = true;

        return 'partial bytes';
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_stat(): array
    {
        return [];
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        if ($offset !== 0 || $whence !== SEEK_SET) {
            return false;
        }
        $this->read = false;

        return true;
    }

    public function stream_tell(): int
    {
        return $this->read ? strlen('partial bytes') : 0;
    }
}

/**
 * Atomic CAS promotion: the driver must stage bytes under a
 * TEMPORARY key and only atomically move them onto the canonical key, so a crash
 * mid-write can never leave a truncated object under the canonical key (which would
 * be trusted as a committed blob — silent CAS corruption). The pre-fix driver wrote
 * the incoming stream straight to the canonical key.
 */
it('promotion writes to a temporary key and moves it atomically to the final key, never writing the final key directly', function (): void {
    $writes = [];
    $moves = [];
    $present = [];

    $spy = Mockery::mock(Filesystem::class);

    $spy->shouldReceive('exists')->andReturnUsing(
        fn (string $key): bool => in_array($key, $present, strict: true),
    );

    $spy->shouldReceive('writeStream')->andReturnUsing(function (string $key, $stream, array $options = []) use (&$writes, &$present): bool {
        $writes[] = $key;
        $present[] = $key;

        return true;
    });

    $spy->shouldReceive('move')->andReturnUsing(function (string $from, string $to) use (&$moves, &$present): bool {
        $moves[] = [$from, $to];
        $present = array_values(array_filter($present, static fn (string $k): bool => $k !== $from));
        $present[] = $to;

        return true;
    });

    $spy->shouldReceive('delete')->andReturnUsing(function (string $key) use (&$present): bool {
        $present = array_values(array_filter($present, static fn (string $k): bool => $k !== $key));

        return true;
    });

    Storage::set('local', $spy);

    $driver = app(FlysystemStorageDriver::class);
    $finalKey = 'tenant/de/ad/deadbeefcafe';

    $stream = fopen('php://temp', 'r+b');
    fwrite($stream, 'the canonical bytes');
    rewind($stream);

    $driver->put($finalKey, $stream, 'local');
    fclose($stream);

    // The bytes were staged under a temp sibling of the final key, NOT written to the
    // canonical key directly (the pre-fix code wrote straight to $finalKey → RED).
    expect($writes)->toHaveCount(1)
        ->and($writes[0])->not->toBe($finalKey)
        ->and(str_starts_with($writes[0], $finalKey.'.tmp-'))->toBeTrue()
        // …then atomically moved onto the canonical key.
        ->and($moves)->toBe([[$writes[0], $finalKey]]);
});

/**
 * A failed move must NOT be reported as a committed blob (review def.1): with the
 * default disk config ('throw' => false) FilesystemAdapter::move returns FALSE on
 * failure (disk full / permissions / S3 copy error) rather than throwing. The pre-fix
 * put() ignored the return value and unconditionally returned true — reporting SUCCESS
 * while the canonical CAS key was never written and the temp leaked (silent CAS
 * corruption / phantom success). put() must return false AND reclaim the temp.
 */
it('promote: when move returns false, put returns false and the temp is removed (no phantom success)', function (): void {
    $present = [];
    $deleted = [];

    $spy = Mockery::mock(Filesystem::class);

    // NB: capture $present BY REFERENCE (a regular closure, not an arrow fn — the
    // latter would snapshot the empty array at definition and never see the staged
    // temp, so deleteQuietly's exists() guard would silently no-op).
    $spy->shouldReceive('exists')->andReturnUsing(
        function (string $key) use (&$present): bool {
            return in_array($key, $present, strict: true);
        },
    );

    $spy->shouldReceive('writeStream')->andReturnUsing(function (string $key) use (&$present): bool {
        $present[] = $key;

        return true;
    });

    // move FAILS the non-throwing way: returns false, leaving the temp in place.
    $spy->shouldReceive('move')->andReturn(false);

    $spy->shouldReceive('delete')->andReturnUsing(function (string $key) use (&$deleted, &$present): bool {
        $deleted[] = $key;
        $present = array_values(array_filter($present, static fn (string $k): bool => $k !== $key));

        return true;
    });

    Storage::set('local', $spy);

    $driver = app(FlysystemStorageDriver::class);
    $finalKey = 'tenant/de/ad/deadbeefcafe';

    $stream = fopen('php://temp', 'r+b');
    fwrite($stream, 'the canonical bytes');
    rewind($stream);

    $ok = $driver->put($finalKey, $stream, 'local');
    fclose($stream);

    // Pre-fix returned true unconditionally → RED. A failed promotion is a failure.
    expect($ok)->toBeFalse()
        // …and the staged temp was reclaimed, never left leaking under a sibling key.
        ->and($deleted)->toHaveCount(1)
        ->and(str_starts_with($deleted[0], $finalKey.'.tmp-'))->toBeTrue();
});

/**
 * Object stores land a write ATOMICALLY, so the temp-then-move staging is skipped
 * there (review def.2): a canonical S3 PUT was already atomic, and staging would only
 * double the transfer and — past the S3 5 GiB single-request CopyObject limit — make
 * the move FAIL (outside the try/catch, leaking the temp) where a direct PUT succeeds.
 * The adapter type identifies S3; signed-URL support also exists on local disks.
 */
it('an object store (atomic writes) makes put write straight to the final key, without a temp or a move', function (): void {
    $writes = [];
    $moved = false;

    $spy = Mockery::mock(AwsS3V3Adapter::class);

    $spy->shouldReceive('exists')->andReturn(false);

    $spy->shouldReceive('writeStream')->andReturnUsing(function (string $key) use (&$writes): bool {
        $writes[] = $key;

        return true;
    });

    $spy->shouldReceive('move')->andReturnUsing(function () use (&$moved): bool {
        $moved = true;

        return true;
    });

    Storage::set('s3', $spy);

    $driver = app(FlysystemStorageDriver::class);
    $finalKey = 'tenant/de/ad/deadbeefcafe';

    $stream = fopen('php://temp', 'r+b');
    fwrite($stream, 'the canonical bytes');
    rewind($stream);

    $ok = $driver->put($finalKey, $stream, 's3');
    fclose($stream);

    // Written STRAIGHT to the canonical key — no temp sibling, no CopyObject move. The
    // pre-fix code staged to a temp + moved on the object store too → RED.
    expect($ok)->toBeTrue()
        ->and($writes)->toBe([$finalKey])
        ->and($moved)->toBeFalse();
});
