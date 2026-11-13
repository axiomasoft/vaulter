<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Testing\Fixtures\TestUser;
use Vaulter\Testing\Tenancy\MutableTenantResolver;
use Vaulter\Webhooks\Actions\RegisterWebhookEndpointAction;
use Vaulter\Webhooks\Repositories\WebhookEndpointReadRepository;
use Vaulter\Webhooks\Actions\UpdateWebhookEndpointAction;
use Vaulter\Webhooks\Data\RegisterWebhookEndpointInput;
use Vaulter\Webhooks\Data\UpdateWebhookEndpointInput;
use Vaulter\Webhooks\Models\WebhookDelivery;
use Vaulter\Webhooks\Models\WebhookEndpoint;
use Vaulter\Webhooks\Testing\WebhookTestHelpers;

/**
 * Endpoints are scoped: one belongs to the tenant it was registered in and receives
 * only that tenant's events (an endpoint without a tenant receives only events
 * without one), and an optional drive filter narrows it to one drive's events. A drive
 * filter must name a drive visible in the registering tenant.
 */
beforeEach(function (): void {
    Queue::fake();
});

afterEach(fn () => MutableTenantResolver::clearFixtureStamps());

function strictTenancy(string $tenant = 'A'): MutableTenantResolver
{
    config()->set('vaulter.tenancy.enabled', true);
    config()->set('vaulter.tenancy.resolver', MutableTenantResolver::class);
    $resolver = new MutableTenantResolver;
    $resolver->tenant = $tenant;
    app()->instance(TenantResolver::class, $resolver);

    return $resolver;
}

function registerEndpoint(?string $driveId = null, string $url = 'https://hooks.test/receive'): WebhookEndpoint
{
    return app(RegisterWebhookEndpointAction::class)->execute(new RegisterWebhookEndpointInput(
        url: $url,
        events: ['folder.created'],
        driveId: $driveId,
    ))->endpoint;
}

/** @return list<string> */
function scopedRecipients(OutboxEvent $message): array
{
    return WebhookDelivery::query()->where('event_id', $message->event_id)->pluck('endpoint_id')
        ->map(static fn (mixed $id): string => (string) $id)->sort()->values()->all();
}

it('delivers a tenant event only to that tenant\'s endpoints under strict tenancy', function (): void {
    $resolver = strictTenancy('A');
    $endpointA = registerEndpoint(url: 'https://a.test/hook');
    $resolver->tenant = 'B';
    $endpointB = registerEndpoint(url: 'https://b.test/hook');
    $legacy = WebhookTestHelpers::endpoint(['url' => 'https://legacy.test/hook']);

    $eventA = WebhookTestHelpers::outbox(['tenant_id' => 'A']);
    $eventB = WebhookTestHelpers::outbox(['tenant_id' => 'B']);
    WebhookTestHelpers::relay();

    expect($endpointA->tenant_id)->toBe('A')
        ->and($endpointB->tenant_id)->toBe('B')
        ->and(scopedRecipients($eventA))->toBe([(string) $endpointA->getKey()])
        ->and(scopedRecipients($eventB))->toBe([(string) $endpointB->getKey()])
        ->and(WebhookDelivery::query()->where('endpoint_id', $legacy->getKey())->exists())->toBeFalse();
});

it('does not list another tenant whose id differs only by case', function (): void {
    strictTenancy('A');
    $mine = registerEndpoint();
    $other = WebhookTestHelpers::endpoint(['tenant_id' => 'a', 'url' => 'https://other-case.test/hook']);

    $ids = app(WebhookEndpointReadRepository::class)->listing()->pluck('id')
        ->map(static fn (mixed $id): string => (string) $id)->all();

    expect($ids)->toContain((string) $mine->getKey())
        ->and($ids)->not->toContain((string) $other->getKey());
});

it('does not deliver a tenant event to an endpoint whose tenant id differs only by case', function (): void {
    strictTenancy('A');
    $lower = registerEndpoint(url: 'https://lower.test/hook');
    $upper = WebhookTestHelpers::endpoint(['tenant_id' => 'a', 'url' => 'https://upper.test/hook']);

    $event = WebhookTestHelpers::outbox(['tenant_id' => 'A']);
    WebhookTestHelpers::relay();

    expect($lower->tenant_id)->toBe('A')
        ->and(scopedRecipients($event))->toBe([(string) $lower->getKey()])
        ->and(WebhookDelivery::query()->where('endpoint_id', $upper->getKey())->exists())->toBeFalse();
});

it('matches a missing tenant only with a missing tenant', function (): void {
    $untenanted = WebhookTestHelpers::endpoint();
    $tenanted = WebhookTestHelpers::endpoint(['tenant_id' => 'A']);

    $plain = WebhookTestHelpers::outbox();
    $scoped = WebhookTestHelpers::outbox(['tenant_id' => 'A']);
    WebhookTestHelpers::relay();

    expect($untenanted->tenant_id)->toBeNull()
        ->and(scopedRecipients($plain))->toBe([(string) $untenanted->getKey()])
        ->and(scopedRecipients($scoped))->toBe([(string) $tenanted->getKey()]);
});

it('narrows an endpoint with a drive filter to that drive\'s events', function (): void {
    $driveX = AccessTestHelpers::storage();
    $driveY = AccessTestHelpers::storage();
    OutboxEvent::query()->delete();
    $onlyX = registerEndpoint(driveId: (string) $driveX->getKey(), url: 'https://x.test/hook');
    $everyDrive = registerEndpoint(url: 'https://all.test/hook');

    $inX = WebhookTestHelpers::outbox(['scope_kind' => 'drive', 'scope_id' => (string) $driveX->getKey()]);
    $inY = WebhookTestHelpers::outbox(['scope_kind' => 'drive', 'scope_id' => (string) $driveY->getKey()]);
    $noDrive = WebhookTestHelpers::outbox();
    WebhookTestHelpers::relay();

    expect((string) $onlyX->drive_id)->toBe((string) $driveX->getKey())
        ->and(scopedRecipients($inX))->toBe(collect([$onlyX, $everyDrive])->map(fn ($e): string => (string) $e->getKey())->sort()->values()->all())
        ->and(scopedRecipients($inY))->toBe([(string) $everyDrive->getKey()])
        ->and(scopedRecipients($noDrive))->toBe([(string) $everyDrive->getKey()]);
});

it('refuses a drive filter naming another tenant\'s drive as not found', function (): void {
    $resolver = strictTenancy('A');
    $driveA = AccessTestHelpers::storage();
    $resolver->tenant = 'B';

    expect(fn () => registerEndpoint(driveId: (string) $driveA->getKey()))->toThrow(NodeNotFoundException::class)
        ->and(fn () => registerEndpoint(driveId: 'no-such-drive'))->toThrow(NodeNotFoundException::class)
        ->and(WebhookEndpoint::query()->count())->toBe(0);

    $resolver->tenant = 'A';
    $endpoint = registerEndpoint(driveId: (string) $driveA->getKey());
    expect((string) $endpoint->drive_id)->toBe((string) $driveA->getKey())
        ->and($endpoint->tenant_id)->toBe('A');
});

it('moves an endpoint into the updating tenant and checks a new drive filter there', function (): void {
    $resolver = strictTenancy('A');
    $driveA = AccessTestHelpers::storage();
    $legacy = WebhookTestHelpers::endpoint();
    $update = app(UpdateWebhookEndpointAction::class);

    $update->execute($legacy, new UpdateWebhookEndpointInput(driveId: (string) $driveA->getKey()));
    expect($legacy->refresh()->tenant_id)->toBe('A')
        ->and((string) $legacy->drive_id)->toBe((string) $driveA->getKey());

    $resolver->tenant = 'B';
    expect(fn () => $update->execute($legacy, new UpdateWebhookEndpointInput(driveId: (string) $driveA->getKey())))
        ->toThrow(NodeNotFoundException::class)
        ->and($legacy->refresh()->tenant_id)->toBe('A');
});

it('accepts and returns drive_id over HTTP', function (): void {
    config()->set('vaulter-webhooks.manage_ability', null);
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
    $drive = AccessTestHelpers::storage();

    $created = $this->postJson('api/vaulter/v1/webhook-endpoints', [
        'url' => 'https://hooks.test/receive',
        'events' => ['folder.created'],
        'drive_id' => (string) $drive->getKey(),
    ])->assertCreated();

    expect((string) $created->json('data.drive_id'))->toBe((string) $drive->getKey());

    $this->postJson('api/vaulter/v1/webhook-endpoints', [
        'url' => 'https://hooks.test/receive',
        'events' => ['folder.created'],
        'drive_id' => '999999',
    ])->assertNotFound();

    $this->postJson('api/vaulter/v1/webhook-endpoints', [
        'url' => 'https://hooks.test/receive',
        'events' => ['folder.created'],
        'drive_id' => 'not a key',
    ])->assertStatus(422);

    $other = AccessTestHelpers::storage();
    $this->patchJson('api/vaulter/v1/webhook-endpoints/'.$created->json('data.id'), ['drive_id' => (string) $other->getKey()], ['If-Match' => $created->headers->get('ETag')])
        ->assertOk()
        ->assertJsonPath('data.drive_id', (string) $other->getKey());
});
