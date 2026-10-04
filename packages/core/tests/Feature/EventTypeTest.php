<?php

declare(strict_types=1);

use Vaulter\Enums\Events\EventType;

it('keeps the 0.2 stored vocabulary while excluding upload lifecycle events from webhook subscriptions', function (): void {
    expect(array_map(static fn (EventType $type): string => $type->value, EventType::cases()))->toBe([
        'drive.created', 'folder.created', 'file.uploaded', 'file.revision_added',
        'upload.initiated', 'upload.aborted', 'upload.expired',
        'node.moved', 'node.renamed', 'node.copied', 'node.trashed', 'node.restored',
        'node.purged', 'grant.created', 'grant.revoked', 'share.created', 'share.revoked',
    ]);
    expect(EventType::webhookTypes())->toHaveCount(14)
        ->not->toContain(EventType::UploadInitiated, EventType::UploadAborted, EventType::UploadExpired)
        ->and(EventType::names())->toContain('drive.created', 'folder.created', 'file.revision_added');
});
