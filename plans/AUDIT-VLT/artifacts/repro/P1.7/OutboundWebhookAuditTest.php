<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Vaulter\Http\Requests\Webhooks\RegisterWebhookEndpointRequest;
use Vaulter\Jobs\Webhooks\DeliverWebhook;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Policies\WebhookEndpointPolicy;
use Vaulter\Tests\Fixtures\TestUser;
use Vaulter\Tests\TestCase;

/**
 * READ-ONLY audit repro for P1.7 (outbound webhooks). Proves the SSRF, retry-owner,
 * authorization and mass-assignment claims of the checklist. Lives outside packages/*
 * per D6 — never shipped.
 */
uses(TestCase::class, RefreshDatabase::class);

// ─────────────── C1 — SSRF: register accepts private/link-local URLs ───────────────

it('C1: registration validation accepts SSRF target URLs (no private-range block)', function (): void {
    $rules = (new RegisterWebhookEndpointRequest)->rules();

    foreach ([
        'http://169.254.169.254/latest/meta-data/',
        'http://127.0.0.1/admin',
        'http://localhost:6379/',
        'http://[::1]/',
        'http://10.0.0.5/internal',
    ] as $ssrf) {
        $validator = Validator::make(
            ['url' => $ssrf, 'events' => ['node.created']],
            $rules,
        );

        expect($validator->passes())->toBeTrue("SSRF URL {$ssrf} should have been rejected but passed validation");
    }
});

it('C1: DeliverWebhook POSTs to a private/link-local endpoint with no egress guard', function (): void {
    Http::fake([
        '*' => Http::response('', 200),
    ]);

    $endpoint = WebhookEndpoint::query()->create([
        'url' => 'http://169.254.169.254/latest/meta-data/iam/security-credentials/',
        'events' => ['node.created'],
        'signing_secret' => 'topsecret',
        'is_active' => true,
        'failure_count' => 0,
    ]);

    $delivery = WebhookDelivery::query()->create([
        'endpoint_id' => $endpoint->getKey(),
        'event' => 'node.created',
        'payload' => ['node_id' => 'n1', 'event_id' => 'evt-1'],
        'status' => 'pending',
        'attempts' => 0,
        'next_retry_at' => now(),
    ]);

    (new DeliverWebhook(deliveryId: (string) $delivery->getKey()))->handle(app(\Vaulter\Webhooks\WebhookSigner::class));

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '169.254.169.254'));
});

// ─────────────── C7 — single retry owner: job never self-releases ───────────────

it('C7: DeliverWebhook declares no queue retry knobs and never self-releases', function (): void {
    $ref = new ReflectionClass(DeliverWebhook::class);

    $hasTries = $ref->hasProperty('tries');
    $hasBackoff = $ref->hasProperty('backoff');
    $hasRetryUntil = $ref->hasMethod('retryUntil');

    $source = (string) file_get_contents((string) $ref->getFileName());
    $selfReleases = str_contains($source, '->release(') || str_contains($source, 'throw ');

    expect($hasTries)->toBeFalse('DeliverWebhook must not set $tries (queue would become a 2nd retry owner)')
        ->and($hasBackoff)->toBeFalse()
        ->and($hasRetryUntil)->toBeFalse()
        ->and($selfReleases)->toBeFalse('DeliverWebhook must not self-release or rethrow — relay sweep is sole retry owner');
});

// ─────────────── C12 — authorization default-open when no ability configured ─────────

it('C12: WebhookEndpointPolicy allows ANY authenticated user when manage_ability is null', function (): void {
    config()->set('vaulter.webhooks.manage_ability', null);

    $policy = new WebhookEndpointPolicy;
    $anyUser = (new TestUser)->forceFill(['id' => 12345]);

    expect($policy->manage($anyUser))->toBeTrue()
        ->and($policy->viewAny($anyUser))->toBeTrue();
});

// ─────────────── C13 — mass assignment: model is fully unguarded ───────────────

it('C13: WebhookEndpoint mass-assigns signing_secret (guarded is empty)', function (): void {
    $endpoint = WebhookEndpoint::query()->create([
        'url' => 'https://hooks.test/receive',
        'events' => ['node.created'],
        'signing_secret' => 'ATTACKER-CHOSEN-SECRET',
        'is_active' => true,
        'failure_count' => 999,
    ]);

    expect($endpoint->signing_secret)->toBe('ATTACKER-CHOSEN-SECRET')
        ->and($endpoint->failure_count)->toBe(999)
        ->and($endpoint->getGuarded())->toBe([]);
});
