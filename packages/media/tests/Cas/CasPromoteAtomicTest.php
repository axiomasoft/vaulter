<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Vaulter\Media\Cas\FlysystemStorageDriver;

/**
 * Atomic CAS promotion (H.3 / F-P1.5-01): the driver must stage bytes under a
 * TEMPORARY key and only atomically move them onto the canonical key, so a crash
 * mid-write can never leave a truncated object under the canonical key (which would
 * be trusted as a committed blob — silent CAS corruption). The pre-fix driver wrote
 * the incoming stream straight to the canonical key.
 */
it('промоушен пишет во временный ключ и атомарно move в финальный, не в финальный напрямую, F-P1.5-01', function (): void {
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

    $driver = new FlysystemStorageDriver;
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
