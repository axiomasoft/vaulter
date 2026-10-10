<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Vaulter\Actions\Webhooks\RegisterWebhookEndpointAction;
use Vaulter\Actions\Webhooks\UpdateWebhookEndpointAction;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Data\Webhooks\RegisterWebhookEndpointInput;
use Vaulter\Data\Webhooks\UpdateWebhookEndpointInput;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;
use Vaulter\Tests\Tenancy\MutableTenantResolver;
use Vaulter\Tests\Webhooks\WebhookTestHelpers;
use Vaulter\Webhooks\OutboxRelay;

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
function scopedRecipients(Outbox $message): array
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
    app(OutboxRelay::class)->process();

    expect($endpointA->tenant_id)->toBe('A')
        ->and($endpointB->tenant_id)->toBe('B')
        ->and(scopedRecipients($eventA))->toBe([(string) $endpointA->getKey()])
        ->and(scopedRecipients($eventB))->toBe([(string) $endpointB->getKey()])
        ->and(WebhookDelivery::query()->where('endpoint_id', $legacy->getKey())->exists())->toBeFalse();
});

it('matches a missing tenant only with a missing tenant', function (): void {
    $untenanted = WebhookTestHelpers::endpoint();
    $tenanted = WebhookTestHelpers::endpoint(['tenant_id' => 'A']);

    $plain = WebhookTestHelpers::outbox();
    $scoped = WebhookTestHelpers::outbox(['tenant_id' => 'A']);
    app(OutboxRelay::class)->process();

    expect($untenanted->tenant_id)->toBeNull()
        ->and(scopedRecipients($plain))->toBe([(string) $untenanted->getKey()])
        ->and(scopedRecipients($scoped))->toBe([(string) $tenanted->getKey()]);
});

it('narrows an endpoint with a drive filter to that drive\'s events', function (): void {
    $driveX = AccessTestHelpers::storage();
    $driveY = AccessTestHelpers::storage();
    Outbox::query()->delete();
    $onlyX = registerEndpoint(driveId: (string) $driveX->getKey(), url: 'https://x.test/hook');
    $everyDrive = registerEndpoint(url: 'https://all.test/hook');

    $inX = WebhookTestHelpers::outbox(['drive_id' => $driveX->getKey()]);
    $inY = WebhookTestHelpers::outbox(['drive_id' => $driveY->getKey()]);
    $noDrive = WebhookTestHelpers::outbox();
    app(OutboxRelay::class)->process();

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
    config()->set('vaulter.webhooks.manage_ability', null);
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
    $this->patchJson('api/vaulter/v1/webhook-endpoints/'.$created->json('data.id'), ['drive_id' => (string) $other->getKey()])
        ->assertOk()
        ->assertJsonPath('data.drive_id', (string) $other->getKey());
});
