<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Drive\Data\UploadOptions;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Sharing\ShareTestHelpers;
use Vaulter\Media\Contracts\Cas\StorageDriver;
use Vaulter\Testing\Fixtures\TestUser;

/**
 * The public share-download response headers (P1.1 / F-P1.3-04, F-P1.11-03):
 * the SAME shared helper the identity DownloadController uses is also wired into
 * PublicShareController::stream(), so the declared mime_type never dictates the
 * response's Content-Type without going through the whitelist, and
 * X-Content-Type-Options: nosniff is set unconditionally.
 */
beforeEach(function (): void {
    StorageFacade::fake('local');
    config()->set('vaulter-drive.features.share_links', true);
    // Force the streaming branch on the PUBLIC path too — a faked local disk
    // otherwise mints a signed temporaryUrl and 302-redirects, which would make
    // this test non-deterministic (that redirect branch is P1.2's scope).
    app()->instance(StorageDriver::class, new NullTemporaryUrlStorageDriver(app(StorageDriver::class)));
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
});

it('serves a public download of a text/html declared mime as octet-stream with nosniff (F-P1.11-03)', function (): void {
    $drive = Vaulter::drive(DriveTestHelpers::host())->asSystem('test fixture');

    $fileNode = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('<script>alert(1)</script>'), 'evil.txt'), new UploadOptions(declaredMimeType: 'text/html'));

    ShareTestHelpers::share($fileNode, token: 'html-dl-token');

    $response = $this->get('api/vaulter/v1/shares/html-dl-token/download');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toBe('application/octet-stream')
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff');
});

it('serves a public download of a whitelisted image/png declared mime as-is, nosniff still set unconditionally (F-P1.11-03)', function (): void {
    $drive = Vaulter::drive(DriveTestHelpers::host())->asSystem('test fixture');

    $fileNode = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jE1cAAAAASUVORK5CYII=')), 'photo.png'), new UploadOptions(declaredMimeType: 'image/png'));

    ShareTestHelpers::share($fileNode, token: 'png-dl-token');

    $response = $this->get('api/vaulter/v1/shares/png-dl-token/download');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toBe('image/png')
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff');
});

/**
 * A StorageDriver decorator that mints no signed URL — forces both PHP-stream
 * download surfaces down their streaming branch so the response headers can be
 * asserted directly, independent of whatever temporaryUrl support the fake local
 * disk happens to provide. Every other call delegates to the real driver.
 */
final class NullTemporaryUrlStorageDriver implements StorageDriver
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
        return null;
    }
}
