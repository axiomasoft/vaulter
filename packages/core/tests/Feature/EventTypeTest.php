<?php

declare(strict_types=1);

use Vaulter\Documents\Enums\DocumentEventType;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Events\EventTypeContract;

it('keeps the 0.2 stored vocabulary and appends the 1.0 lifecycle, document and comment types', function (): void {
    expect(array_map(static fn (EventTypeContract $type): string => (string) $type->value, [...DriveEventType::cases(), ...DocumentEventType::cases()]))->toBe([
        'drive.created', 'folder.created', 'file.uploaded', 'file.revision_added',
        'upload.initiated', 'upload.aborted', 'upload.expired',
        'node.moved', 'node.renamed', 'node.copied', 'node.updated', 'node.trashed', 'node.restored',
        'node.purged', 'grant.created', 'grant.revoked', 'access.inheritance_changed', 'share.created', 'share.updated',
        'share.rotated', 'share.revoked',
        'drive.archived', 'drive.unarchived', 'drive.deleted', 'file.revision_deleted', 'drive.access.effective_permissions_changed',
        'document.created', 'document.updated', 'document.revision_created', 'document.restored',
        'comment.added', 'comment.resolved', 'comment.deleted',
    ]);
});

it('keeps upload sessions external-free and document saves out of the activity feed', function (): void {
    $all = [...DriveEventType::cases(), ...DocumentEventType::cases()];
    $external = array_values(array_filter($all, static fn (EventTypeContract $type): bool => $type->spec()->external));
    $activity = array_values(array_filter($all, static fn (EventTypeContract $type): bool => $type->spec()->activity));

    expect($external)->toHaveCount(29)
        ->not->toContain(DriveEventType::UploadInitiated, DriveEventType::UploadAborted, DriveEventType::UploadExpired)
        ->toContain(DriveEventType::DriveCreated, DriveEventType::FolderCreated, DriveEventType::FileRevisionAdded, DocumentEventType::DocumentUpdated)
        ->and($activity)->toHaveCount(31)
        ->not->toContain(DocumentEventType::DocumentUpdated)
        ->and(DriveEventType::UploadInitiated->spec()->activity)->toBeTrue()
        ->and(DriveEventType::UploadInitiated->spec()->external)->toBeFalse()
        ->and(DocumentEventType::DocumentUpdated->spec()->activity)->toBeFalse();
});
