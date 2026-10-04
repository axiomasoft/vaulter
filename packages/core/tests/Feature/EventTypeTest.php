<?php

declare(strict_types=1);

use Vaulter\Enums\Events\EventType;

it('keeps the 0.2 stored vocabulary while excluding upload lifecycle events from webhook subscriptions', function (): void {
    expect(array_map(static fn (EventType $type): string => $type->value, EventType::cases()))->toBe([
        'storage.created', 'node.created', 'file.uploaded', 'version.created',
        'upload_session.initiated', 'upload_session.aborted', 'upload_session.expired',
        'node.moved', 'node.renamed', 'node.copied', 'node.trashed', 'node.restored',
        'node.force_deleted', 'grant.created', 'grant.revoked', 'share.created', 'share.revoked',
    ]);
    expect(EventType::webhookTypes())->toHaveCount(14)
        ->not->toContain(EventType::UploadInitiated, EventType::UploadAborted, EventType::UploadExpired)
        ->and(EventType::names())->toContain('storage.created', 'node.created', 'version.created');
});
