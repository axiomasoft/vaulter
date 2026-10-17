<?php

declare(strict_types=1);

use Vaulter\Data\Drive\ListOptions;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Data\Sharing\ShareLinkInput;
use Vaulter\Drive\Data\Sharing\ShareLinkUpdate;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Operations\ShareOperations;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Tests\Concerns\EnablesWebhooksModule;
use Vaulter\Enums\Events\DriveEventType;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Models\Activity\Activity;
use Vaulter\Operations\ActivityOperations;
use Vaulter\Operations\DriveContext;
use Vaulter\Testing\Support\CanonicalJson;
use Vaulter\ValueObjects\Access\Actor;

uses(EnablesWebhooksModule::class);

/**
 * Updating and rotating a share link are events of their own (PLAN2.D47): both reach
 * the activity feed and the outbox, and neither leaks the token or the new password.
 */
beforeEach(function (): void {
    config()->set('vaulter.features.share_links', true);
    config()->set('vaulter.access.permission_driver', 'database');
    $this->drive = AccessTestHelpers::storage();
    $this->node = AccessTestHelpers::folder($this->drive, null, 'shared');
    $this->shares = app()->makeWith(ShareOperations::class, ['context' => DriveContext::system($this->drive, 'lifecycle events')]);
    $this->minted = $this->shares->create($this->node->id, new ShareLinkInput);
});

it('records share.updated with the names of the changed fields only', function (): void {
    $this->shares->update($this->node->id, $this->minted->link->id, new ShareLinkUpdate(expiresAt: now()->addDay()));

    $outbox = OutboxEvent::query()->where('type', DriveEventType::ShareUpdated->value)->sole();
    $activity = Activity::query()->where('verb', DriveEventType::ShareUpdated->value)->sole();

    expect(CanonicalJson::of($outbox->payload['data']))->toBe(CanonicalJson::of(['node_id' => (string) $this->node->id, 'changed' => ['expires_at']]))
        ->and(CanonicalJson::of($outbox->payload['subject']))->toBe(CanonicalJson::of(['type' => 'share', 'id' => (string) $this->minted->link->id]))
        ->and($activity->event_id)->toBe($outbox->event_id)
        ->and($activity->context['changed'])->toBe(['expires_at']);
});

it('names a password change without carrying the password or its hash', function (): void {
    $this->shares->update($this->node->id, $this->minted->link->id, new ShareLinkUpdate(password: 'open-sesame', maxUses: 3));

    $outbox = OutboxEvent::query()->where('type', DriveEventType::ShareUpdated->value)->sole();
    $activity = Activity::query()->where('verb', DriveEventType::ShareUpdated->value)->sole();

    expect($outbox->payload['data']['changed'])->toBe(['max_uses', 'password'])
        ->and(json_encode([$outbox->payload, $activity->context]))->not->toContain('open-sesame', 'password_hash');
});

it('records no share.updated when nothing changed', function (): void {
    $this->shares->update($this->node->id, $this->minted->link->id, new ShareLinkUpdate);

    expect(OutboxEvent::query()->where('type', DriveEventType::ShareUpdated->value)->exists())->toBeFalse()
        ->and(Activity::query()->where('verb', DriveEventType::ShareUpdated->value)->exists())->toBeFalse();
});

it('records share.rotated and keeps the token out of the envelope', function (): void {
    $rotated = $this->shares->rotate($this->node->id, $this->minted->link->id);

    $outbox = OutboxEvent::query()->where('type', DriveEventType::ShareRotated->value)->sole();
    $activity = Activity::query()->where('verb', DriveEventType::ShareRotated->value)->sole();
    $row = AccessTestHelpers::shareRow($rotated);
    $json = json_encode($outbox->payload);

    expect($outbox->payload['data'])->toBe(['node_id' => (string) $this->node->id])
        ->and($json)->not->toContain($rotated->token, $row->token_hash, $row->token_prefix, 'token_hash', 'token_prefix')
        ->and($activity->event_id)->toBe($outbox->event_id)
        ->and($activity->context['token_prefix'])->toBe($row->token_prefix);
});

it('hides the token prefix of share events from a reader without Share', function (): void {
    $this->shares->update($this->node->id, $this->minted->link->id, new ShareLinkUpdate(expiresAt: now()->addDay()));
    $this->shares->rotate($this->node->id, $this->minted->link->id);
    // Root View opens the drive feed; the node grant makes its rows visible, but without Share.
    AccessTestHelpers::grant($this->drive->rootNode()->firstOrFail(), RoleRegistry::READER, scope: GrantScope::Item);
    AccessTestHelpers::grant($this->node, RoleRegistry::READER, scope: GrantScope::Item);
    $reader = app()->makeWith(ActivityOperations::class, [
        'context' => DriveContext::for($this->drive, Actor::user(AccessTestHelpers::user()), 'reader'),
    ]);
    $system = app()->makeWith(ActivityOperations::class, ['context' => DriveContext::system($this->drive, 'audit')]);

    $seen = array_filter($reader->forDrive(new ListOptions)->items, static fn ($row): bool => in_array($row->verb, ['share.updated', 'share.rotated'], true));
    $audited = array_filter($system->forDrive(new ListOptions)->items, static fn ($row): bool => in_array($row->verb, ['share.updated', 'share.rotated'], true));

    expect($seen)->toHaveCount(2)->and($audited)->toHaveCount(2);
    foreach ($seen as $row) {
        expect($row->context)->not->toHaveKey('token_prefix');
    }
    foreach ($audited as $row) {
        expect($row->context)->toHaveKey('token_prefix');
    }
});

it('records no share.updated when the requested values are the stored ones', function (): void {
    $this->shares->update($this->node->id, $this->minted->link->id, new ShareLinkUpdate(maxUses: 3));
    OutboxEvent::query()->where('type', DriveEventType::ShareUpdated->value)->delete();

    $this->shares->update($this->node->id, $this->minted->link->id, new ShareLinkUpdate(maxUses: 3));
    $this->shares->update($this->node->id, $this->minted->link->id, new ShareLinkUpdate(removePassword: true));

    expect(OutboxEvent::query()->where('type', DriveEventType::ShareUpdated->value)->exists())->toBeFalse();

    $this->shares->update($this->node->id, $this->minted->link->id, new ShareLinkUpdate(removeMaxUses: true));
    expect(OutboxEvent::query()->where('type', DriveEventType::ShareUpdated->value)->sole()->payload['data']['changed'])->toBe(['max_uses']);
});
