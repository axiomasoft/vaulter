<?php

declare(strict_types=1);

use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Data\Access\GrantInput;
use Vaulter\Enums\Access\InheritanceRestriction;
use Vaulter\Enums\Events\DriveEventType;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Models\Activity\Activity;
use Vaulter\Operations\AccessOperations;
use Vaulter\Operations\DriveContext;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Concerns\EnablesWebhooksModule;
use Vaulter\Tests\Support\CanonicalJson;

uses(EnablesWebhooksModule::class);

/**
 * Opening or closing a node to grants from above is an access.inheritance_changed
 * event; the grants copied by copy_current keep producing grant.created.
 */
beforeEach(function (): void {
    config()->set('vaulter.access.permission_driver', 'database');
    $this->drive = AccessTestHelpers::storage();
    $this->access = app()->makeWith(AccessOperations::class, ['context' => DriveContext::system($this->drive, 'inheritance events')]);
    $this->parent = AccessTestHelpers::folder($this->drive, null, 'parent');
    $this->child = AccessTestHelpers::folder($this->drive, $this->parent, 'child');
});

it('records access.inheritance_changed for a restriction and its restoration', function (): void {
    $this->access->restrictInheritance($this->child->id);
    $this->access->restoreInheritance($this->child->id);

    $events = OutboxEvent::query()->where('type', DriveEventType::InheritanceChanged->value)->orderBy('outbox_seq')->get();

    expect($events->map(fn (OutboxEvent $row): array => $row->payload['data'])->all())->toBe([['inherits' => false], ['inherits' => true]])
        ->and(CanonicalJson::of($events[0]->payload['subject']))->toBe(CanonicalJson::of(['type' => 'node', 'id' => (string) $this->child->id]))
        // Access changes are not node versions: a sequence here would look stale next to the node's last mutation.
        ->and($events[0]->payload['sequence'])->toBeNull()
        ->and(Activity::query()->where('verb', DriveEventType::InheritanceChanged->value)->count())->toBe(2)
        ->and(Activity::query()->where('verb', DriveEventType::InheritanceChanged->value)->orderBy('id')->first()->event_id)->toBe($events[0]->event_id);
});

it('records nothing when the node already has the requested inheritance', function (): void {
    $this->access->restoreInheritance($this->child->id);

    expect(OutboxEvent::query()->where('type', DriveEventType::InheritanceChanged->value)->exists())->toBeFalse();
});

it('keeps copy_current producing grant.created next to the inheritance event', function (): void {
    $this->access->grant($this->parent->id, new GrantInput(subject: new Subject('user', 14), role: RoleRegistry::READER));
    OutboxEvent::query()->delete();
    Activity::query()->delete();

    $this->access->restrictInheritance($this->child->id, InheritanceRestriction::CopyCurrent);

    expect(OutboxEvent::query()->orderBy('outbox_seq')->pluck('type')->all())->toBe(['access.inheritance_changed', 'grant.created']);
});
