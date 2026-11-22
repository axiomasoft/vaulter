<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Vaulter\Load\Console\ConcurrencyFixtureSupport;
use Vaulter\Load\Console\ConcurrencyHashtext;

it('builds an idempotent fixture with a real int4 collision and distinct payload hashes', function (): void {
    $runPrefix = 'p5-'.bin2hex(random_bytes(4));
    $fixtureId = 'collision-pair';

    expect(Artisan::call('load:concurrency-fixtures', [
        'action' => 'prepare',
        '--fixture-id' => $fixtureId,
        '--run-prefix' => $runPrefix,
    ]))->toBe(0);

    $manifestPath = ConcurrencyFixtureSupport::fixtureDirectory($runPrefix, $fixtureId).'/manifest.json';
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    $mtime = filemtime($manifestPath);
    $left = $manifest['payloads']['collision-a'];
    $right = $manifest['payloads']['collision-b'];
    $normalA = $manifest['payloads']['normal-a'];
    $normalB = $manifest['payloads']['normal-b'];

    expect($left['sha256'])->not->toBe($right['sha256'])
        ->and($left['hashtext'])->toBe($right['hashtext'])
        ->and($left['lock_key'])->not->toBe($right['lock_key'])
        ->and($normalA['sha256'])->not->toBe($normalB['sha256'])
        ->and($normalA['lock_key'])->not->toBe($normalB['lock_key'])
        ->and($manifest['dirty']['dirty-20k']['rows'])->toBe(20_000)
        ->and($manifest['dirty']['dirty-1m']['rows'])->toBe(1_000_000)
        ->and($manifest['dirty']['dirty-1m']['seeded'])->toBeFalse()
        ->and($manifest['dirty']['dirty-1m']['tier_l_claim'])->toBeFalse()
        ->and($manifest['trees']['disjoint']['node_count'])->toBe(1000)
        ->and($manifest['trees']['overlapping']['node_count'])->toBe(1000)
        ->and($manifest['trees']['disjoint']['restore_each_repetition'])->toBeTrue();

    expect(Artisan::call('load:concurrency-fixtures', [
        'action' => 'prepare',
        '--fixture-id' => $fixtureId,
        '--run-prefix' => $runPrefix,
    ]))->toBe(0)
        ->and(file_get_contents($manifestPath))->toBe(json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n")
        ->and(filemtime($manifestPath))->toBe($mtime);

    expect(Artisan::call('load:concurrency-fixtures', [
        'action' => 'verify',
        '--fixture-id' => $fixtureId,
        '--run-prefix' => $runPrefix,
    ]))->toBe(0);

    Artisan::call('load:concurrency-fixtures', [
        'action' => 'cleanup',
        '--fixture-id' => $fixtureId,
        '--run-prefix' => $runPrefix,
    ]);
});

it('cleans up one fixture id and leaves the sibling snapshot in place', function (): void {
    $runPrefix = 'p5-clean-'.bin2hex(random_bytes(4));

    expect(Artisan::call('load:concurrency-fixtures', [
        'action' => 'prepare',
        '--fixture-id' => 'keep-me',
        '--run-prefix' => $runPrefix,
    ]))->toBe(0);
    expect(Artisan::call('load:concurrency-fixtures', [
        'action' => 'prepare',
        '--fixture-id' => 'drop-me',
        '--run-prefix' => $runPrefix,
    ]))->toBe(0);

    $keep = ConcurrencyFixtureSupport::fixtureDirectory($runPrefix, 'keep-me');
    $drop = ConcurrencyFixtureSupport::fixtureDirectory($runPrefix, 'drop-me');

    expect(Artisan::call('load:concurrency-fixtures', [
        'action' => 'cleanup',
        '--fixture-id' => 'drop-me',
        '--run-prefix' => $runPrefix,
    ]))->toBe(0)
        ->and(is_dir($drop))->toBeFalse()
        ->and(is_file($keep.'/manifest.json'))->toBeTrue();

    expect(Artisan::call('load:concurrency-fixtures', [
        'action' => 'cleanup',
        '--run-prefix' => $runPrefix,
    ]))->toBe(0)
        ->and(is_dir($keep))->toBeFalse();
});

it('rejects a collision search that exhausts the candidate bound', function (): void {
    expect(fn () => ConcurrencyFixtureSupport::searchCollision('p5', 2))
        ->toThrow(RuntimeException::class, 'refusing to fabricate a pair');
});

it('detects blob aliasing, torn uploads, tree cycles, lost nodes, and a swept live session', function (): void {
    $directory = sys_get_temp_dir().'/p5-integrity-'.bin2hex(random_bytes(4));
    mkdir($directory);

    $write = function (string $name, array $report) use ($directory): string {
        $path = $directory.'/'.$name.'.json';
        file_put_contents($path, json_encode($report));

        return $path;
    };

    $cleanTree = ['1' => null, '2' => '1'];
    $clean = $write('clean', [
        'blobs' => [
            ['sha256' => 'aa', 'bytes_sha256' => 'aa', 'blob_id' => 'b1'],
            ['sha256' => 'bb', 'bytes_sha256' => 'bb', 'blob_id' => 'b2'],
        ],
        'upload' => ['received_bytes' => 32, 'staged_bytes' => 32, 'status' => 'uploading'],
        'tree' => ['parents' => $cleanTree, 'expected_count' => 2],
        'sweep' => [
            'before' => ['live' => true, 'staged_bytes' => 32, 'status' => 'uploading'],
            'after' => ['status' => 'uploading', 'staged_bytes' => 32],
        ],
    ]);

    expect(Artisan::call('load:concurrency-fixtures', [
        'action' => 'check-integrity',
        '--report' => $clean,
    ]))->toBe(0);

    $aliased = $write('alias', [
        'blobs' => [
            ['sha256' => 'aa', 'bytes_sha256' => 'aa', 'blob_id' => 'same'],
            ['sha256' => 'bb', 'bytes_sha256' => 'bb', 'blob_id' => 'same'],
        ],
    ]);
    $torn = $write('torn', [
        'upload' => ['received_bytes' => 32, 'staged_bytes' => 8, 'status' => 'uploading'],
    ]);
    $cycle = $write('cycle', [
        'tree' => ['parents' => ['1' => '2', '2' => '1'], 'expected_count' => 2],
    ]);
    $lost = $write('lost', [
        'tree' => ['parents' => ['1' => null], 'expected_count' => 2],
    ]);
    $swept = $write('swept', [
        'sweep' => [
            'before' => ['live' => true, 'staged_bytes' => 32, 'status' => 'uploading'],
            'after' => ['status' => 'expired', 'staged_bytes' => 0],
        ],
    ]);

    expect(Artisan::call('load:concurrency-fixtures', ['action' => 'check-integrity', '--report' => $aliased]))->toBe(1)
        ->and(Artisan::call('load:concurrency-fixtures', ['action' => 'check-integrity', '--report' => $torn]))->toBe(1)
        ->and(Artisan::call('load:concurrency-fixtures', ['action' => 'check-integrity', '--report' => $cycle]))->toBe(1)
        ->and(Artisan::call('load:concurrency-fixtures', ['action' => 'check-integrity', '--report' => $lost]))->toBe(1)
        ->and(Artisan::call('load:concurrency-fixtures', ['action' => 'check-integrity', '--report' => $swept]))->toBe(1);

    foreach (glob($directory.'/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($directory);
});

it('matches PostgreSQL hashtextextended for the legacy collision pair', function (): void {
    expect(ConcurrencyHashtext::int4('hello'))->toBe(-1870292951);

    $running = trim((string) shell_exec('docker inspect -f {{.State.Running}} vaulter-load-postgres-1 2>/dev/null'));
    if ($running !== 'true') {
        return;
    }

    $pair = ConcurrencyFixtureSupport::searchCollision('p5');
    expect($pair['left']['sha256'])->not->toBe($pair['right']['sha256'])
        ->and($pair['left']['hashtext'])->toBe($pair['right']['hashtext']);

    foreach (['left', 'right'] as $side) {
        $sha = $pair[$side]['sha256'];
        expect(ctype_xdigit($sha))->toBeTrue();
        $key = 'cas:p5:'.$sha;
        $quoted = str_replace("'", "''", $key);
        $remote = trim((string) shell_exec(
            'docker exec vaulter-load-postgres-1 psql -U vaulter -d vaulter_load -At -c '.escapeshellarg("SELECT hashtextextended('{$quoted}', 0)"),
        ));

        expect($remote)->toBe((string) ConcurrencyHashtext::int8($key));
    }
});
