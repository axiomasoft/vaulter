<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Enums\Events\EventType;
use Vaulter\Events\Access\NodeGrantCreated;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Webhooks\OutboxRecorder;

/**
 * The transactional outbox stages INSIDE the mutation transaction (§2.11 / F-P1.7-03),
 * not on the after-commit boundary — so there is no crash window between the mutation's
 * commit and the outbox write, and a rolled-back mutation drops the staged row too.
 *
 * The kernel domain events used to be ShouldDispatchAfterCommit, which deferred staging
 * past the transaction; the first test is RED on that old code (the row is not visible
 * inside the transaction) and GREEN once the events fire synchronously in-transaction.
 */
beforeEach(function (): void {
    $storage = AccessTestHelpers::storage();
    $this->node = AccessTestHelpers::folder($storage, null, 'reports');
    $this->grant = AccessTestHelpers::grant(node: $this->node, role: RoleRegistry::READER);

    // Drop the provisioning outbox rows so we assert only the grant.created row.
    Outbox::query()->delete();
});

it('stages the outbox row INSIDE the active transaction, not after commit, F-P1.7-03', function (): void {
    $stagedInside = null;

    DB::transaction(function () use (&$stagedInside): void {
        event(new NodeGrantCreated(grant: $this->grant, actorId: 7));

        // Inside the SAME transaction the staged row must already be visible.
        $stagedInside = Outbox::query()
            ->where('event', EventType::GrantCreated->value)
            ->exists();
    });

    expect($stagedInside)->toBeTrue()
        ->and(Outbox::query()->where('event', EventType::GrantCreated->value)->count())->toBe(1);
});

it('rolls the staged outbox row back when the mutation transaction rolls back, F-P1.7-03', function (): void {
    rescue(function (): void {
        DB::transaction(function (): void {
            event(new NodeGrantCreated(grant: $this->grant, actorId: 7));

            throw new RuntimeException('force rollback');
        });
    });

    expect(Outbox::query()->where('event', EventType::GrantCreated->value)->exists())->toBeFalse();
});

it('confines a staging failure to a savepoint so the mutation still commits, F-P1.7-03', function (): void {
    // The recorder throws while the event fires INSIDE the transaction. Without a
    // savepoint around the staging call this poisons the transaction on PostgreSQL and
    // the following COMMIT fails — silently rolling the grant back (review P0.2).
    // RED-BEFORE requires PostgreSQL: SQLite does not poison an aborted statement, so
    // this asserts the invariant that MUST hold on PG (env here lacks pdo_pgsql).
    app()->bind(OutboxRecorder::class, fn (): OutboxRecorder => new class(app(MetricsRecorder::class)) extends OutboxRecorder
    {
        public function __construct(MetricsRecorder $metrics)
        {
            parent::__construct(vaulterConfig: app(VaulterConfig::class), metrics: $metrics);
        }

        #[Override]
        public function recordGrantCreated(NodeGrantCreated $event): ?Outbox
        {
            throw new RuntimeException('outbox insert failed');
        }
    });

    $committed = DB::transaction(function (): bool {
        event(new NodeGrantCreated(grant: $this->grant, actorId: 7));

        // Reached (and the COMMIT succeeds) only when the swallowed staging failure did
        // NOT abort the surrounding transaction.
        return true;
    });

    expect($committed)->toBeTrue()
        ->and(Outbox::query()->where('event', EventType::GrantCreated->value)->exists())->toBeFalse();
});
