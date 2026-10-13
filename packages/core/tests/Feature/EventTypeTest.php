<?php

declare(strict_types=1);

use Vaulter\Enums\Events\EventType;

it('keeps the 0.2 stored vocabulary and appends the 1.0 lifecycle, document and comment types', function (): void {
    expect(array_map(static fn (EventType $type): string => $type->value, EventType::cases()))->toBe([
        'drive.created', 'folder.created', 'file.uploaded', 'file.revision_added',
        'upload.initiated', 'upload.aborted', 'upload.expired',
        'node.moved', 'node.renamed', 'node.copied', 'node.updated', 'node.trashed', 'node.restored',
        'node.purged', 'grant.created', 'grant.revoked', 'access.inheritance_changed', 'share.created', 'share.updated',
        'share.rotated', 'share.revoked',
        'drive.archived', 'drive.unarchived', 'drive.deleted', 'file.revision_deleted',
        'document.created', 'document.updated', 'document.revision_created', 'document.restored',
        'comment.added', 'comment.resolved', 'comment.deleted',
    ]);
});

it('keeps upload sessions out of webhooks and document saves out of the activity feed', function (): void {
    expect(EventType::webhookTypes())->toHaveCount(29)
        ->not->toContain(EventType::UploadInitiated, EventType::UploadAborted, EventType::UploadExpired)
        ->and(EventType::names())->toContain('drive.created', 'folder.created', 'file.revision_added', 'document.updated')
        ->and(EventType::activityTypes())->toHaveCount(31)
        ->not->toContain(EventType::DocumentUpdated)
        ->and(EventType::UploadInitiated->isActivity())->toBeTrue()
        ->and(EventType::UploadInitiated->isWebhook())->toBeFalse()
        ->and(EventType::DocumentUpdated->isActivity())->toBeFalse();
});
