<?php

declare(strict_types=1);

use Vaulter\Drive\Contracts\Access\PermissionDriver;
use Vaulter\Drive\Contracts\DownloadGuard;
use Vaulter\Drive\Contracts\DriveHandleFactory;
use Vaulter\Drive\Contracts\Handlers\FileHandler;
use Vaulter\Drive\Contracts\Handlers\NodePresenter;
use Vaulter\Drive\Contracts\NodeContentLifecycle;
use Vaulter\Drive\Contracts\Uploads\UploadInspector;
use Vaulter\Drive\Contracts\Uploads\UploadProtocolHandler;
use Vaulter\Drive\DriveHandleFactory as DriveFactory;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Handlers\NodePresenter as CoreNodePresenter;
use Vaulter\Drive\Uploads\S3MultipartHandler;
use Vaulter\Drive\Uploads\TusUploadHandler;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Kernel;
use Vaulter\Modules\SlotContribution;

function driveContributions(string $slot): array
{
    return app(Kernel::class)->extensions()->contributions($slot);
}

/** @return list<string> */
function contributionIds(string $slot): array
{
    return array_map(static fn (SlotContribution $c): string => $c->id, driveContributions($slot));
}

it('declares the handle factory and the node presenter as the drive capabilities', function (): void {
    $capabilities = app(Kernel::class)->capabilities();

    expect($capabilities->require(DriveHandleFactory::class))->toBeInstanceOf(DriveFactory::class)
        ->and($capabilities->require(NodePresenter::class))->toBeInstanceOf(CoreNodePresenter::class);
});

it('contributes the three built-in permission drivers by key', function (): void {
    expect(app(Kernel::class)->extensions()->handlerKeys(PermissionDriver::class))->toEqualCanonicalizing(['database', 'gate', 'spatie']);
});

it('keeps the inspectors in the order they ran before the slot existed (priority descending)', function (): void {
    $inspectors = app(Kernel::class)->extensions()->resolve(UploadInspector::class);
    $byPriority = $inspectors;
    usort($byPriority, static fn (UploadInspector $a, UploadInspector $b): int => ($b->priority() <=> $a->priority()) ?: strcmp($a->key(), $b->key()));

    expect(contributionIds(UploadInspector::class))->toBe(['drive.inspector.filename', 'drive.inspector.size', 'drive.inspector.mime-consistency', 'drive.inspector.scan-dispatch'])
        ->and(array_map(static fn (UploadInspector $i): string => $i->key(), $inspectors))
        ->toBe(array_map(static fn (UploadInspector $i): string => $i->key(), $byPriority));
});

it('serves the upload protocols by key, the one-shot direct upload through the tus handler', function (): void {
    $sessions = app(UploadSessionManager::class);

    expect(app(Kernel::class)->extensions()->handlerKeys(UploadProtocolHandler::class))->toEqualCanonicalizing(['tus', 's3-multipart'])
        ->and($sessions->handlerForProtocol(UploadProtocol::Tus))->toBeInstanceOf(TusUploadHandler::class)
        ->and($sessions->handlerForProtocol(UploadProtocol::Direct))->toBeInstanceOf(TusUploadHandler::class)
        ->and($sessions->handlerForProtocol(UploadProtocol::S3Multipart))->toBeInstanceOf(S3MultipartHandler::class);
});

it('contributes the preview and download-only handlers, the file and shortcut lifecycles and the availability guard', function (): void {
    expect(app(Kernel::class)->extensions()->handlerKeys(FileHandler::class))->toEqualCanonicalizing(['vaulter/preview', 'vaulter/download'])
        ->and(app(Kernel::class)->extensions()->handlerKeys(NodeContentLifecycle::class))->toContain('file', 'shortcut')
        ->and(contributionIds(DownloadGuard::class))->toBe(['drive.content-availability']);
});
