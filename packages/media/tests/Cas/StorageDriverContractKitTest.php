<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\AssertionFailedError;
use Vaulter\Media\Cas\FlysystemStorageDriver;
use Vaulter\Media\Contracts\Cas\StorageDriver;
use Vaulter\Media\Testing\Contracts\StorageDriverContractTests;

uses(StorageDriverContractTests::class);

it('detects broken storage semantics and cleans every temporary object', function (string $fault, string $message): void {
    Storage::fake('kit');
    config()->set('vaulter-media.disk', 'kit');
    $driver = new class(app(FlysystemStorageDriver::class), $fault) implements StorageDriver
    {
        public function __construct(private StorageDriver $inner, private string $fault) {}

        public function disk(?string $disk = null): Filesystem
        {
            return $this->inner->disk($disk);
        }

        public function put(string $key, mixed $stream, ?string $disk = null, bool $ifNoneMatch = false, array $options = []): bool
        {
            if ($this->fault === 'conditional' && $ifNoneMatch && $this->inner->exists($key, $disk)) {
                return true;
            }

            return $this->inner->put($key, $stream, $disk, $ifNoneMatch, $options);
        }

        public function get(string $key, ?string $disk = null): mixed
        {
            $stream = $this->inner->get($key, $disk);
            if ($this->fault === 'missing' && $stream === null) {
                return '';
            }
            if ($this->fault === 'zero' && is_resource($stream) && fstat($stream)['size'] === 0) {
                fclose($stream);

                return null;
            }
            if ($this->fault === 'bytes' && is_resource($stream)) {
                fclose($stream);
                $stream = fopen('php://temp', 'r+b');
                fwrite($stream, 'corrupt');
                rewind($stream);
            }

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
    };
    expect(fn () => $this->assertStorageDriverContract($driver))->toThrow(AssertionFailedError::class, $message);
    expect(Storage::disk('kit')->allFiles())->toBe([]);
})->with([
    'missing is not empty bytes' => ['missing', 'Missing get must return null'],
    'conditional refusal' => ['conditional', 'Conditional create must refuse'],
    'zero bytes is not missing' => ['zero', 'Stored get must return a stream'],
    'corrupt stream' => ['bytes', 'Stored stream length'],
]);
