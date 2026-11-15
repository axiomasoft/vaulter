<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Access\AccessInvalidation;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Repositories\Changes\ChangeHeadRepository;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Events\Outbox\ConsumeResult;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Events\Outbox\OutboxConsumer;
use Vaulter\Events\Outbox\OutboxRelay;
use Vaulter\Integration\Tests\Concerns\EnablesWebhooksModule;
use Vaulter\Integration\Tests\Outbox\Fixtures\ScriptedConsumer;
use Vaulter\Integration\Tests\TestCase;
use Vaulter\Kernel;
use Vaulter\Modules\CompiledModules;
use Vaulter\Modules\SlotContribution;
use Vaulter\Webhooks\Configuration\WebhooksConfig;
use Vaulter\Webhooks\Contracts\OutboundUrlGuard;
use Vaulter\Webhooks\Jobs\DeliverWebhook;
use Vaulter\Webhooks\Testing\WebhookTestHelpers;
use Vaulter\Webhooks\WebhookSigner;

/**
 * Single-process partial-failure fixtures: the event is committed, the delivery stage fails
 * once, and the next relay pass really runs and converges. Process-kill, PostgreSQL and Redis races are not proven here;
 * docs/reference/concurrency.md classifies them as serial+database/stand.
 */
uses(TestCase::class, RefreshDatabase::class, EnablesWebhooksModule::class);

beforeEach(function (): void {
    ScriptedConsumer::reset();
    config()->set('vaulter.outbox.settle_window_seconds', 300);
    config()->set('vaulter.outbox.max_attempts', 5);
    config()->set('vaulter.outbox.backoff_base_seconds', 10);

    $arguments = get_object_vars(app(Kernel::class)->compiled());
    $arguments['contributions'][OutboxConsumer::class] = [
        new SlotContribution(OutboxConsumer::class, ScriptedConsumer::ID, 'core', ScriptedConsumer::class, 0, null),
    ];
    $kernel = new Kernel(app());
    $kernel->seal(new CompiledModules(...$arguments));
    app()->instance(Kernel::class, $kernel);
});

afterEach(function (): void {
    ScriptedConsumer::reset();
});

it('keeps a committed event durable when its delivery stage throws once, and delivers it on the next pass', function (): void {
    $event = WebhookTestHelpers::outbox(['created_at' => now()->subSeconds(900)], consumer: ScriptedConsumer::ID);
    ScriptedConsumer::$answers = ['throw'];

    app(OutboxRelay::class)->pass();

    $failed = WebhookTestHelpers::outboxDelivery($event->event_id, ScriptedConsumer::ID);
    // positive control: the fault really fired inside the consumer and the row is durable, not lost or done
    expect(ScriptedConsumer::$consumed)->toBe([ScriptedConsumer::ID.':'.$event->event_id])
        ->and($failed['state'])->toBe('pending')
        ->and((int) $failed['attempts'])->toBe(1)
        ->and($failed['last_error'])->toContain('consumer failed');

    $this->travel(30)->seconds();
    app(OutboxRelay::class)->pass();

    $done = WebhookTestHelpers::outboxDelivery($event->event_id, ScriptedConsumer::ID);
    expect(ScriptedConsumer::$consumed)->toHaveCount(2)
        ->and($done['state'])->toBe('done');
});

it('consumes an event once when the relay pass is repeated after the delivery completed', function (): void {
    $event = WebhookTestHelpers::outbox(['created_at' => now()->subSeconds(900)], consumer: ScriptedConsumer::ID);

    app(OutboxRelay::class)->pass();
    app(OutboxRelay::class)->pass();
    $this->travel(60)->seconds();
    app(OutboxRelay::class)->pass();

    expect(ScriptedConsumer::$consumed)->toBe([ScriptedConsumer::ID.':'.$event->event_id])
        ->and(WebhookTestHelpers::outboxDelivery($event->event_id, ScriptedConsumer::ID)['state'])->toBe('done');
});

it('never resurrects a dead delivery after the clock moves forward', function (): void {
    config()->set('vaulter.outbox.max_attempts', 1);
    $event = WebhookTestHelpers::outbox(['created_at' => now()->subSeconds(900)], consumer: ScriptedConsumer::ID);
    ScriptedConsumer::$answers = [ConsumeResult::dead('poison')];

    app(OutboxRelay::class)->pass();
    $this->travel(7200)->seconds();
    app(OutboxRelay::class)->pass();

    expect(ScriptedConsumer::$consumed)->toHaveCount(1)
        ->and(WebhookTestHelpers::outboxDelivery($event->event_id, ScriptedConsumer::ID)['state'])->toBe('dead');
});

it('retries an accepted-then-timed-out webhook delivery with the same webhook-id', function (): void {
    config()->set('vaulter-webhooks.retry_jitter', 0);
    $endpoint = WebhookTestHelpers::endpoint();
    $delivery = WebhookTestHelpers::delivery($endpoint, ['payload' => ['id' => 'evt-timeout-1', 'type' => 'folder.created']]);
    /** @var list<string> $accepted */
    $accepted = [];
    $calls = 0;
    // The receiver records the request (acceptance) and only then the local transport times out; the retry succeeds.
    Http::fake(function (Request $request) use (&$accepted, &$calls) {
        $calls++;
        $accepted[] = $request->header('webhook-id')[0];
        if ($calls === 1) {
            throw new ConnectionException('cURL error 28: Operation timed out after the receiver accepted the request');
        }

        return Http::response('', 200);
    });
    $deliver = static fn () => (new DeliverWebhook(deliveryId: (string) $delivery->getKey()))
        ->handle(signer: app(WebhookSigner::class), urlGuard: app(OutboundUrlGuard::class), webhooksConfig: app(WebhooksConfig::class), database: app(VaulterDatabase::class));

    $deliver();

    // positive control: the first call reached the fake receiver, then failed locally and stayed retryable
    expect($calls)->toBe(1)
        ->and($accepted)->toBe(['evt-timeout-1'])
        ->and($delivery->fresh()->status)->toBe('failed')
        ->and($delivery->fresh()->attempts)->toBe(1)
        ->and($delivery->fresh()->next_retry_at)->not->toBeNull();

    $this->travel(3600)->seconds();
    $deliver();

    expect($calls)->toBe(2)
        ->and($accepted)->toBe(['evt-timeout-1', 'evt-timeout-1'])
        ->and($delivery->fresh()->status)->toBe('delivered')
        ->and($delivery->fresh()->attempts)->toBe(2);
});

it('wakes consumers only for a committed host invalidation, and a dropped delivery does not keep old cursors alive', function (): void {
    $drive = DriveTestHelpers::drive();
    $drive->folders()->create('Team');
    $driveId = $drive->model()->getKey();
    $heads = app(ChangeHeadRepository::class);
    $invalidations = static fn () => OutboxEvent::query()->where('type', DriveEventType::EffectivePermissionsChanged->value)->get();
    $fail = true;
    DB::listen(static function (QueryExecuted $query) use (&$fail): void {
        if ($fail && str_contains($query->sql, 'drive_change_heads') && str_starts_with(strtolower($query->sql), 'update')) {
            throw new RuntimeException('epoch update failed');
        }
    });

    // positive control: the fault fired on the epoch UPDATE; journal head and outbox roll back together
    expect(fn () => app(AccessInvalidation::class)->changed(null, 'host-role'))->toThrow(RuntimeException::class, 'epoch update failed')
        ->and($invalidations())->toHaveCount(0)
        ->and($heads->find($driveId)['epoch'])->toBe(1);

    $fail = false;
    $seq = $heads->find($driveId)['last_seq'];
    app(AccessInvalidation::class)->changed(null, 'host-role');
    $event = $invalidations()->sole();

    // The webhook is a wake-up only: its delivery failing leaves the epoch raised, so polling still resets.
    $earlier = OutboxEvent::query()->where('outbox_seq', '<', $event->outbox_seq)->count();
    ScriptedConsumer::$answers = [...array_fill(0, $earlier, ConsumeResult::done()), 'throw'];
    $this->travel(900)->seconds();
    app(OutboxRelay::class)->pass();

    expect(ScriptedConsumer::$consumed)->toContain(ScriptedConsumer::ID.':'.$event->event_id)
        ->and(WebhookTestHelpers::outboxDelivery($event->event_id, ScriptedConsumer::ID)['state'])->toBe('pending')
        ->and($heads->find($driveId))->toMatchArray(['epoch' => 2, 'last_seq' => $seq]);
});
