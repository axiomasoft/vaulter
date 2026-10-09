<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Enums\Events\EventType;
use Vaulter\Events\Access\NodeGrantCreated;
use Vaulter\Events\DomainEvent;
use Vaulter\Events\EventRecorder;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Schema\IdStrategyResolver;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\Webhooks\OutboxRecorder;

/**
 * The transactional outbox stages INSIDE the mutation transaction (§2.11 / C05), not
 * on the after-commit boundary — so there is no crash window between the mutation's
 * commit and the outbox write, a rolled-back mutation drops the staged row too, and a
 * failed outbox write rolls the mutation back instead of committing it without its
 * event.
 */
beforeEach(function (): void {
    $storage = AccessTestHelpers::storage();
    $this->node = AccessTestHelpers::folder($storage, null, 'reports');
    $this->grant = AccessTestHelpers::grant(node: $this->node, role: RoleRegistry::READER);

    // Drop the provisioning outbox rows so we assert only the grant.created row.
    Outbox::query()->delete();
});

function outboxTransactionEvent(object $test): NodeGrantCreated
{
    return new NodeGrantCreated(grant: $test->grant, actor: new ActorRef(type: 'user', id: 7), correlationId: (string) Str::ulid());
}

it('stages the outbox row INSIDE the active transaction, not after commit', function (): void {
    $stagedInside = app(VaulterDatabase::class)->transaction(function (): bool {
        app(EventRecorder::class)->record(outboxTransactionEvent($this));

        // Inside the SAME transaction the staged row must already be visible.
        return Outbox::query()->where('event', EventType::GrantCreated->value)->exists();
    });

    expect($stagedInside)->toBeTrue()
        ->and(Outbox::query()->where('event', EventType::GrantCreated->value)->count())->toBe(1);
});

it('rolls the staged outbox row back when the mutation transaction rolls back', function (): void {
    rescue(function (): void {
        app(VaulterDatabase::class)->transaction(function (): void {
            app(EventRecorder::class)->record(outboxTransactionEvent($this));

            throw new RuntimeException('force rollback');
        });
    }, report: false);

    expect(Outbox::query()->where('event', EventType::GrantCreated->value)->exists())->toBeFalse();
});

it('lets a staging failure leave the transaction so nothing of the occurrence commits', function (): void {
    // Before C05 a savepoint swallowed this failure and the mutation committed without
    // its outbox row. Now the failure propagates and rolls everything back.
    app()->bind(OutboxRecorder::class, fn (): OutboxRecorder => new class(app(VaulterConfig::class), app(MetricsRecorder::class), app(VaulterDatabase::class), app(IdStrategyResolver::class)) extends OutboxRecorder
    {
        #[Override]
        public function stage(DomainEvent $event, array $envelope): bool
        {
            throw new RuntimeException('outbox insert failed');
        }
    });
    $event = outboxTransactionEvent($this);

    expect(fn () => app(VaulterDatabase::class)->transaction(fn () => app(EventRecorder::class)->record($event)))
        ->toThrow(RuntimeException::class, 'outbox insert failed')
        ->and(Outbox::query()->where('event', EventType::GrantCreated->value)->exists())->toBeFalse()
        ->and(Activity::query()->where('event_id', $event->eventId)->exists())->toBeFalse();
});
