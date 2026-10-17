<?php

declare(strict_types=1);

use Vaulter\Drive\Content\ContentAvailabilityGuard;
use Vaulter\Drive\Content\DownloadGuards;
use Vaulter\Drive\Contracts\DownloadGuard;
use Vaulter\Drive\Exceptions\Files\ContentUnavailableException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Tests\Content\Fixtures\DownloadGuardProvider;
use Vaulter\Drive\Tests\Content\Fixtures\FirstDownloadGuard;
use Vaulter\Drive\Tests\Content\Fixtures\RecordingDownloadGuard;
use Vaulter\Drive\Tests\Content\Fixtures\SecondDownloadGuard;
use Vaulter\Kernel;
use Vaulter\Testing\Concerns\FreshApplication;

function downloadGuards(): DownloadGuards
{
    RecordingDownloadGuard::$calls = [];
    RecordingDownloadGuard::$failure = null;

    return FreshApplication::boot(after: [DownloadGuardProvider::class])->make(DownloadGuards::class);
}

function checkDownload(DownloadGuards $guards): void
{
    $drive = new Drive;
    $drive->exists = true;
    $drive->setRawAttributes(['id' => 1], true);

    $guards->check(DriveContext::system($drive, 'download guard test'), new Node, new FileRevision);
}

it('contributes the drive content availability check as the built-in download guard', function (): void {
    $contributions = app(Kernel::class)->extensions()->contributions(DownloadGuard::class);

    expect(array_map(static fn ($c): string => $c->id, $contributions))->toBe(['drive.content-availability'])
        ->and($contributions[0]->implementation)->toBe(ContentAvailabilityGuard::class);
});

it('runs every active guard in the order of the slot, the fixtures ahead of the built-in availability guard', function (): void {
    $guards = downloadGuards();

    // The built-in guard (priority 0) runs last and denies the dummy node: no file behind it.
    expect(fn () => checkDownload($guards))->toThrow(ContentUnavailableException::class)
        ->and(RecordingDownloadGuard::$calls)->toBe([FirstDownloadGuard::class, SecondDownloadGuard::class]);
});

it('denies the delivery when a guard throws, and a guard after the denial never runs', function (): void {
    $guards = downloadGuards();
    RecordingDownloadGuard::$failure = new ContentUnavailableException;

    expect(fn () => checkDownload($guards))->toThrow(ContentUnavailableException::class)
        ->and(RecordingDownloadGuard::$calls)->toBe([FirstDownloadGuard::class, SecondDownloadGuard::class]);
});

it('lets a problem a guard throws reach the caller unchanged', function (): void {
    $guards = downloadGuards();
    RecordingDownloadGuard::$failure = NodeNotFoundException::forId('x');

    expect(fn () => checkDownload($guards))->toThrow(NodeNotFoundException::class);
});

it('treats any other exception of a guard as a denial, never a pass (fail-closed)', function (): void {
    $guards = downloadGuards();
    RecordingDownloadGuard::$failure = new RuntimeException('scanner unreachable');

    expect(fn () => checkDownload($guards))->toThrow(ContentUnavailableException::class);
});

it('sends every delivery path of the core through the guards, not through the availability check', function (): void {
    $source = static fn (string $file): string => (string) file_get_contents(dirname(__DIR__, 2).'/src/'.$file);

    // download, share download and direct-signed URL: one guard call each.
    expect(substr_count($source('Content/ContentDelivery.php'), '$this->guards->check('))->toBe(3)
        ->and($source('Content/ContentDelivery.php'))->not->toContain('->assertAvailable');

    foreach (['../../drive/src/Sharing/ShareContentGuard.php', '../../drive/src/Sharing/ShareResolver.php'] as $file) {
        expect($source($file))->toContain('DownloadGuards')->and($source($file))->not->toContain('ContentAvailabilityGuard');
    }
});
