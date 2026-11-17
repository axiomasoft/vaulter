<?php

declare(strict_types=1);

namespace Vaulter\Media\Testing\Contracts;

use DateTimeImmutable;
use Packman\Attributes\Api;
use PHPUnit\Framework\Assert;
use Vaulter\Media\Contracts\Cas\StorageDriver;

/**
 * Local disk contract. Caller configures an isolated default/explicit disk before
 * invoking this kit. This does not qualify S3 atomic conditional writes or signing.
 */
// @phpstan-ignore trait.unused (Public host testing trait; consumed by Pest outside src, like DocumentTypeContractTests.)
#[Api]
trait StorageDriverContractTests
{
    public function assertStorageDriverContract(StorageDriver $driver, ?string $disk = null): void
    {
        $key = 'contract-'.bin2hex(random_bytes(12)).'/object';
        $streams = [];
        try {
            Assert::assertFalse($driver->exists($key, $disk));
            $missing = $driver->get($key, $disk);
            if (is_resource($missing)) {
                $streams[] = $missing;
            }
            Assert::assertNull($missing, 'Missing get must return null.');
            foreach (['abc', 'xyz', '', str_repeat('0123456789abcdef', 65536)] as $bytes) {
                $stream = fopen('php://temp', 'r+b');
                Assert::assertIsResource($stream);
                $streams[] = $stream;
                fwrite($stream, $bytes);
                rewind($stream);
                Assert::assertTrue($driver->put($key, $stream, $disk, ifNoneMatch: $bytes === 'abc'));
                Assert::assertTrue($driver->exists($key, $disk), 'Even zero-byte objects exist.');
                Assert::assertTrue($driver->disk($disk)->exists($key), 'disk() must address the same disk.');
                $read = $driver->get($key, $disk);
                if (is_resource($read)) {
                    $streams[] = $read;
                }
                Assert::assertIsResource($read, 'Stored get must return a stream.');
                $actual = stream_get_contents($read);
                Assert::assertIsString($actual);
                Assert::assertSame(strlen($bytes), strlen($actual), 'Stored stream length differs.');
                Assert::assertSame(hash('sha256', $bytes), hash('sha256', $actual), 'Stored stream hash differs.');
                if ($bytes === 'xyz') {
                    $contender = fopen('php://temp', 'r+b');
                    Assert::assertIsResource($contender);
                    $streams[] = $contender;
                    fwrite($contender, 'replacement');
                    rewind($contender);
                    Assert::assertFalse($driver->put($key, $contender, $disk, ifNoneMatch: true), 'Conditional create must refuse an existing key.');
                    $unchanged = $driver->get($key, $disk);
                    if (is_resource($unchanged)) {
                        $streams[] = $unchanged;
                    }
                    Assert::assertIsResource($unchanged);
                    Assert::assertSame('xyz', stream_get_contents($unchanged), 'Conditional refusal must preserve bytes.');
                }
            }
            Assert::assertNull($driver->temporaryUrl($key, new DateTimeImmutable('+1 minute'), $disk));
            $driver->delete($key, $disk);
            Assert::assertFalse($driver->exists($key, $disk));
            $deleted = $driver->get($key, $disk);
            if (is_resource($deleted)) {
                $streams[] = $deleted;
            }
            Assert::assertNull($deleted);
            $driver->delete($key, $disk);
        } finally {
            foreach ($streams as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $driver->delete($key, $disk);
        }
    }
}
