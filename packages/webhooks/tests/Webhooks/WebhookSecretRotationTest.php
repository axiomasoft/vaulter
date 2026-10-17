<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Vaulter\Webhooks\Actions\RegisterWebhookEndpointAction;
use Vaulter\Webhooks\Actions\RotateWebhookSecretAction;
use Vaulter\Webhooks\Data\RegisterWebhookEndpointInput;
use Vaulter\Webhooks\Data\RotatedWebhookSecret;
use Vaulter\Webhooks\Exceptions\InvalidWebhookSignatureException;
use Vaulter\Webhooks\StandardWebhookVerifier;
use Vaulter\Webhooks\Testing\WebhookTestHelpers;
use Vaulter\Webhooks\WebhookSecret;

/**
 * Rotation hands a receiver a window in which both the old and the new secret sign, so
 * it can switch without dropping a delivery. After the window only the new one signs.
 */
function sentHeaders(): array
{
    $captured = [];
    Http::assertSent(function (Request $request) use (&$captured): bool {
        $captured = [
            'headers' => ['webhook-id' => $request->header('webhook-id')[0], 'webhook-timestamp' => $request->header('webhook-timestamp')[0], 'webhook-signature' => $request->header('webhook-signature')[0]],
            'body' => $request->body(),
        ];

        return true;
    });

    return $captured;
}

it('registers an endpoint with a whsec_ secret returned once', function (): void {
    $registered = app(RegisterWebhookEndpointAction::class)->execute(new RegisterWebhookEndpointInput(url: 'https://hooks.test/receive', events: ['folder.created'], isActive: true));

    expect($registered->signingSecret)->toStartWith('whsec_')
        ->and($registered->endpoint->fresh()->signing_secret)->toBe($registered->signingSecret)
        ->and($registered->endpoint->fresh()->previous_signing_secret)->toBeNull();
});

it('rotates: the new secret is returned once, the old one is kept for the overlap', function (): void {
    $this->freezeTime(function (): void {
        $endpoint = WebhookTestHelpers::endpoint();

        $rotated = app(RotateWebhookSecretAction::class)->execute($endpoint, overlapSeconds: 3600);

        $fresh = $endpoint->fresh();
        expect($rotated)->toBeInstanceOf(RotatedWebhookSecret::class)
            ->and($rotated->endpointId)->toBe($endpoint->id)
            ->and($rotated->secret)->toStartWith('whsec_')->not->toBe(WebhookTestHelpers::SECRET)
            ->and($fresh->signing_secret)->toBe($rotated->secret)
            ->and($fresh->previous_signing_secret)->toBe(WebhookTestHelpers::SECRET)
            ->and($fresh->previous_secret_expires_at->getTimestamp())->toBe(now()->addHour()->getTimestamp())
            ->and($fresh->activeSigningSecrets())->toBe([$rotated->secret, WebhookTestHelpers::SECRET]);
    });
});

it('stores both secrets encrypted at rest', function (): void {
    $endpoint = WebhookTestHelpers::endpoint();
    app(RotateWebhookSecretAction::class)->execute($endpoint);

    $raw = DB::table($endpoint->getTable())->where('id', $endpoint->id)->first();

    expect($raw->signing_secret)->not->toContain('whsec_')
        ->and($raw->previous_signing_secret)->not->toContain(WebhookTestHelpers::SECRET)
        ->and($raw->previous_signing_secret)->not->toBeNull();
});

it('signs with both secrets during the overlap so either receiver verifies the delivery', function (): void {
    Http::fake(['https://hooks.test/*' => Http::response('', 200)]);
    $endpoint = WebhookTestHelpers::endpoint();
    $rotated = app(RotateWebhookSecretAction::class)->execute($endpoint, overlapSeconds: 3600);
    $delivery = WebhookTestHelpers::delivery($endpoint, ['payload' => ['id' => 'evt-9', 'type' => 'folder.created']]);

    WebhookTestHelpers::deliver($delivery);

    $sent = sentHeaders();
    expect(explode(' ', $sent['headers']['webhook-signature']))->toHaveCount(2);

    $verifier = new StandardWebhookVerifier;
    $verifier->verify($sent['headers'], $sent['body'], [WebhookTestHelpers::SECRET]);
    $verifier->verify($sent['headers'], $sent['body'], [$rotated->secret]);
    expect(true)->toBeTrue();
});

it('signs with the new secret only once the overlap has passed', function (): void {
    Http::fake(['https://hooks.test/*' => Http::response('', 200)]);
    $endpoint = WebhookTestHelpers::endpoint();
    $rotated = app(RotateWebhookSecretAction::class)->execute($endpoint, overlapSeconds: 60);
    $this->travel(61)->seconds();
    $delivery = WebhookTestHelpers::delivery($endpoint, ['payload' => ['id' => 'evt-10', 'type' => 'folder.created']]);

    WebhookTestHelpers::deliver($delivery);

    $sent = sentHeaders();
    expect(explode(' ', $sent['headers']['webhook-signature']))->toHaveCount(1);
    (new StandardWebhookVerifier)->verify($sent['headers'], $sent['body'], [$rotated->secret]);
    expect(fn () => (new StandardWebhookVerifier)->verify($sent['headers'], $sent['body'], [WebhookTestHelpers::SECRET]))
        ->toThrow(InvalidWebhookSignatureException::class);
});

it('drops the old secret immediately with an overlap of 0', function (): void {
    $endpoint = WebhookTestHelpers::endpoint();

    $rotated = app(RotateWebhookSecretAction::class)->execute($endpoint, overlapSeconds: 0);

    $fresh = $endpoint->fresh();
    expect($fresh->previous_signing_secret)->toBeNull()
        ->and($fresh->previous_secret_expires_at)->toBeNull()
        ->and($fresh->activeSigningSecrets())->toBe([$rotated->secret]);
});

it('uses the configured overlap by default and caps an explicit one at seven days', function (): void {
    config()->set('vaulter-webhooks.secret_rotation_overlap', 7200);
    $this->freezeTime(function (): void {
        $endpoint = WebhookTestHelpers::endpoint();

        app(RotateWebhookSecretAction::class)->execute($endpoint);
        expect($endpoint->fresh()->previous_secret_expires_at->getTimestamp())->toBe(now()->addSeconds(7200)->getTimestamp());

        app(RotateWebhookSecretAction::class)->execute($endpoint, overlapSeconds: 99_999_999);
        expect($endpoint->fresh()->previous_secret_expires_at->getTimestamp())->toBe(now()->addDays(7)->getTimestamp());
    });
});

it('keeps only one previous secret across two rotations', function (): void {
    $endpoint = WebhookTestHelpers::endpoint();

    $first = app(RotateWebhookSecretAction::class)->execute($endpoint, overlapSeconds: 3600);
    $second = app(RotateWebhookSecretAction::class)->execute($endpoint, overlapSeconds: 3600);

    $fresh = $endpoint->fresh();
    expect($fresh->signing_secret)->toBe($second->secret)
        ->and($fresh->previous_signing_secret)->toBe($first->secret)
        ->and(WebhookSecret::isWebhookSecret($fresh->previous_signing_secret))->toBeTrue();
});

it('never serializes a secret from the model', function (): void {
    $endpoint = WebhookTestHelpers::endpoint();
    app(RotateWebhookSecretAction::class)->execute($endpoint);

    expect(array_keys($endpoint->fresh()->toArray()))->not->toContain('signing_secret')->not->toContain('previous_signing_secret')
        ->and($endpoint->fresh()->toJson())->not->toContain('whsec_');
});
