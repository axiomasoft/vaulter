<?php

declare(strict_types=1);

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\PortableVisibilityConverter;
use Psr\Http\Message\RequestInterface;
use Vaulter\Media\Cas\BlobGarbageCollector;
use Vaulter\Media\Cas\BlobReconciler;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Data\Snapshots\BlobSnapshot;
use Vaulter\Media\Encryption\KeyManagementPort;
use Vaulter\Media\Encryption\SegmentedSecretStreamCipher;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\Media\ValueObjects\Cas\BlobScope;
use Vaulter\Support\Identity;

/**
 * The content-addressed commit flow: stage → hash the staged file →
 * find-or-create by the per-scope dedup key → promote bytes only on a newly
 * created row. Server-recompute beats any client claim; dedup yields ONE row and
 * ONE physical object for identical bytes.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

it('commits a blob: one row, an object on disk, status committed', function (): void {
    $store = app(BlobStore::class);

    $blob = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('hello cas'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));

    expect($blob->status)->toBe(BlobStatus::Committed)
        ->and($blob->sha256)->toBe(hash('sha256', 'hello cas'))
        ->and($blob->size_bytes)->toBe(9)
        ->and(Blob::query()->count())->toBe(1);

    Storage::disk('local')->assertExists($blob->storage_key);
});

it('the server recomputes sha256 and ignores a lying client', function (): void {
    $store = app(BlobStore::class);

    // The client could send any sha256 they like; commit() never takes it — the
    // hash is recomputed from the staged temp file. We prove it by checking the
    // stored hash matches the REAL bytes, not a lie.
    $blob = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('real bytes'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));

    expect($blob->sha256)->toBe(hash('sha256', 'real bytes'))
        ->and($blob->sha256)->not->toBe(hash('sha256', 'a lie'));
});

function bindCasTestKeyManagement(): void
{
    app()->instance(KeyManagementPort::class, new class implements KeyManagementPort
    {
        public function wrap(#[SensitiveParameter] string $dek): array
        {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

            return ['key_id' => 1, 'wrapped_dek' => $nonce.sodium_crypto_secretbox($dek, $nonce, str_repeat('k', 32))];
        }

        public function unwrap(int|string $keyId, string $wrappedDek): string
        {
            $dek = sodium_crypto_secretbox_open(substr($wrappedDek, 24), substr($wrappedDek, 0, 24), str_repeat('k', 32));

            return $dek === false ? throw new RuntimeException('Invalid test envelope.') : $dek;
        }
    });
}

it('stores a wrapped key and authenticates independently seekable encrypted segments', function (): void {
    bindCasTestKeyManagement();
    $bytes = str_repeat('a', 4_194_304).str_repeat('b', 4_194_304).str_repeat('c', 3_000_000);
    $store = app(BlobStore::class);
    $row = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor($bytes), BlobScope::tenant('1')));
    $disk = Storage::disk('local');
    expect((string) $row->encryption_key_id)->toBe('1')
        ->and($row->wrapped_dek)->not->toBeNull()
        ->and($disk->get($row->storage_key))->not->toContain(str_repeat('c', 100));

    $stream = $store->read((string) $row->id);
    expect(fseek($stream, 8_388_605))->toBe(0)
        ->and(stream_get_contents($stream, 6))->toBe('bbbccc');
    fclose($stream);

    $object = $disk->get($row->storage_key);
    $object[strlen($object) - 1] = chr(ord($object[strlen($object) - 1]) ^ 1);
    $disk->put($row->storage_key, $object);
    $stream = $store->read((string) $row->id);
    fseek($stream, 8_388_608);
    expect(fn (): string|false => fread($stream, 1))->toThrow(RuntimeException::class, 'authentication');
    fclose($stream);

    // An otherwise well-formed record from another index must also fail.
    $prefix = strlen("VAULTER-SECRETSTREAM-1\0") + 2 + strlen($row->wrapped_dek);
    $stride = 4_194_304 + SegmentedSecretStreamCipher::OVERHEAD;
    $disk->put($row->storage_key, substr_replace($object, substr($object, $prefix, $stride), $prefix + $stride, $stride));
    $stream = $store->read((string) $row->id);
    fseek($stream, 4_194_304);
    expect(fn (): string|false => fread($stream, 1))->toThrow(RuntimeException::class, 'authentication');
    fclose($stream);
});

it('fetches just the requested encrypted segment from the committed S3 version', function (): void {
    bindCasTestKeyManagement();
    $ranges = [];
    $deletions = [];
    $object = '';
    if (!class_exists(MockHandler::class) || !class_exists(PortableVisibilityConverter::class)) {
        test()->markTestSkipped('aws/aws-sdk-php or league/flysystem-aws-s3-v3 is not installed.');
    }
    $mock = new MockHandler;
    $mock->append(
        static function (CommandInterface $command) use (&$object): Result {
            $object = (string) $command['Body'];

            return new Result(['VersionId' => 'stored-version', 'ETag' => '"etag"']);
        },
        new Result(['VersionId' => 'stored-version', 'ETag' => '"etag"']),
        static function (CommandInterface $command) use (&$ranges, &$object): Result {
            $ranges[] = [$command['VersionId'], $command['Range']];
            preg_match('/bytes=(\d+)-(\d+)/', $command['Range'], $matches);
            $start = (int) $matches[1];
            $length = (int) $matches[2] - $start + 1;

            return new Result(['Body' => Utils::streamFor(substr($object, $start, $length))]);
        },
        new Result(['VersionId' => 'stored-version', 'ETag' => '"etag"']),
        static function (CommandInterface $command) use (&$deletions): Result {
            $deletions[] = $command['VersionId'];

            return new Result;
        },
        static function (CommandInterface $command) use (&$deletions): Result {
            $deletions[] = $command['VersionId'];

            return new Result;
        },
    );
    Storage::set('versioned-s3', Storage::build([
        'driver' => 's3', 'region' => 'us-east-1', 'bucket' => 'cas-bucket',
        'credentials' => ['key' => 'test-access-key', 'secret' => 'test-secret'], 'handler' => $mock,
    ]));
    $bytes = str_repeat('a', 4_194_304).str_repeat('b', 4_194_304).str_repeat('c', 3_000_000);
    $store = app(BlobStore::class);
    $row = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor($bytes), BlobScope::tenant('1'), 'versioned-s3'));
    $stream = $store->read((string) $row->id);
    expect(fseek($stream, 8_389_000))->toBe(0)
        ->and(stream_get_contents($stream, 19))->toBe(str_repeat('c', 19));
    fclose($stream);

    $length = 3_000_000 + SegmentedSecretStreamCipher::OVERHEAD;
    $start = strlen($object) - $length;
    expect($ranges)->toBe([['stored-version', 'bytes='.$start.'-'.(strlen($object) - 1)]]);
    $row->forceFill(['status' => BlobStatus::Tombstoned, 'delete_after_at' => now()->subMinute()])->save();
    expect(app(BlobGarbageCollector::class)->sweep()['swept'])->toBe(1)
        ->and($deletions)->toBe([null, 'stored-version'])
        ->and($store->read((string) $row->id))->toBeNull();
});

it('conditionally publishes multipart CAS bytes on R2 without UploadPart checksums and records the object VersionId', function (): void {
    $requests = [];
    if (!class_exists(MockHandler::class) || !class_exists(PortableVisibilityConverter::class)) {
        test()->markTestSkipped('aws/aws-sdk-php or league/flysystem-aws-s3-v3 is not installed.');
    }
    $mock = new MockHandler;
    $capture = static function (CommandInterface $command, RequestInterface $request) use (&$requests): Result {
        $requests[] = [$command->getName(), $request];

        return match ($command->getName()) {
            'CreateMultipartUpload' => new Result(['UploadId' => 'mup-1']),
            'UploadPart' => new Result(['ETag' => '"part-'.$command['PartNumber'].'"']),
            'CompleteMultipartUpload' => new Result(['VersionId' => str_repeat('v', 1_024), 'ETag' => '"etag-fallback"']),
        };
    };
    for ($i = 0; $i < 6; $i++) {
        $mock->append($capture);
    }
    Storage::set('r2', Storage::build([
        'driver' => 's3', 'region' => 'us-east-1', 'bucket' => 'cas-bucket', 'root' => 'vaulter',
        'credentials' => ['key' => 'test-access-key', 'secret' => 'test-secret'], 'handler' => $mock,
        'vaulter_s3_profile' => 'r2',
    ]));
    $bytes = str_repeat('x', 17 * 1_048_576);
    $row = CasTestHelpers::row(app(BlobStore::class)->commit(CasTestHelpers::streamFor($bytes), BlobScope::tenant('1'), 'r2'));
    expect($row->storage_version)->toBe(str_repeat('v', 1_024))
        ->and($row->etag)->toBe('"etag-fallback"')
        ->and(array_column($requests, 0))->toBe(['CreateMultipartUpload', 'UploadPart', 'UploadPart', 'UploadPart', 'UploadPart', 'CompleteMultipartUpload']);
    foreach (array_slice($requests, 1, 4) as [, $request]) {
        expect($request->hasHeader('X-Amz-Checksum-Sha256'))->toBeFalse()
            ->and($request->hasHeader('X-Amz-Sdk-Checksum-Algorithm'))->toBeFalse();
    }
    expect($requests[5][1]->getHeaderLine('If-None-Match'))->toBe('*')
        ->and($requests[5][1]->getUri()->getPath())->toContain('/vaulter/'.$row->storage_key);
});

it('dedup: the same bytes twice give ONE row, ONE object and two edges', function (): void {
    $store = app(BlobStore::class);

    $first = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('same bytes'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));
    $second = CasTestHelpers::row($store->commit(CasTestHelpers::streamFor('same bytes'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));

    expect($second->getKey())->toBe($first->getKey())
        ->and(Blob::query()->count())->toBe(1);

    // Two distinct versions reference the one blob — two edges, one physical object.
    $v1 = CasTestHelpers::reference('a.bin');
    $v2 = CasTestHelpers::reference('b.bin');
    CasTestHelpers::pin($first, $v1);
    CasTestHelpers::pin($second, $v2);

    expect(Storage::disk('local')->allFiles())->toHaveCount(1);

    // ref_count is NOT bumped synchronously — it stays 0 until reconciliation.
    expect($first->fresh()->ref_count)->toBe(0);

    // Reconciliation rebuilds ref_count to the edge total (2).
    app(BlobReconciler::class)->reconcile();
    expect($first->fresh()->ref_count)->toBe(2);
});

it('a repeated commit is idempotent and writes no second object', function (): void {
    $store = app(BlobStore::class);

    $store->commit(CasTestHelpers::streamFor('idem'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused'));
    $store->commit(CasTestHelpers::streamFor('idem'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused'));

    expect(Storage::disk('local')->allFiles())->toHaveCount(1)
        ->and(Blob::query()->count())->toBe(1);
});

it('answers a commit with an immutable snapshot of the committed row: its id, never a model', function (): void {
    $store = app(BlobStore::class);
    $scope = BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused');

    $first = $store->commit(CasTestHelpers::streamFor('snapshot bytes'), scope: $scope);
    $again = $store->commit(CasTestHelpers::streamFor('snapshot bytes'), scope: $scope);
    $row = Blob::query()->sole();

    expect($first)->toBeInstanceOf(BlobSnapshot::class)
        ->and($first->id)->toBe(Identity::stringify($row->getKey()))
        ->and($again->id)->toBe($first->id)
        ->and([$first->sha256, $first->sizeBytes, $first->scopeKey, $first->storageKey, $first->disk])->toBe([hash('sha256', 'snapshot bytes'), 14, $row->scope_key, $row->storage_key, $row->disk])
        ->and([$first->status, $first->intact, $first->chunked])->toBe([BlobStatus::Committed, true, false])
        ->and(fn (): BlobStatus => $first->status = BlobStatus::Deleted)->toThrow(Error::class);

    // The snapshot is a point in time: a later change of the row does not reach it, and read() follows the row by id.
    $row->forceFill(['status' => BlobStatus::Tombstoned])->save();
    expect($first->status)->toBe(BlobStatus::Committed)
        ->and(stream_get_contents($store->read($first)))->toBe('snapshot bytes')
        ->and(stream_get_contents($store->read($first->id)))->toBe('snapshot bytes');

    $row->forceDelete();
    expect($store->read($first))->toBeNull();
});
