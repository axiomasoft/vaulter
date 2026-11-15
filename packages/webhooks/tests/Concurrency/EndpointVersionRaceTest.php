<?php

declare(strict_types=1);

use Vaulter\Exceptions\Concurrency\StaleWriteException;
use Vaulter\Testing\Concurrency\ForkRace;
use Vaulter\Testing\Concurrency\RaceOutcome;
use Vaulter\Webhooks\Actions\DeleteWebhookEndpointAction;
use Vaulter\Webhooks\Actions\RotateWebhookSecretAction;
use Vaulter\Webhooks\Actions\UpdateWebhookEndpointAction;
use Vaulter\Webhooks\Data\UpdateWebhookEndpointInput;
use Vaulter\Webhooks\Exceptions\WebhookEndpointNotFoundException;
use Vaulter\Webhooks\Models\WebhookEndpoint;
use Vaulter\Webhooks\Testing\WebhookTestHelpers;

/**
 * Conflicting endpoint writes on real backends: a secret rotation and an update — or a deletion
 * — of one endpoint that both read the same version race; the endpoint row lock serialises them and the second one is
 * refused: stale_write when the row is still there with a newer version, webhook_endpoint_not_found when the deletion
 * landed first. Exactly one lands. PostgreSQL/MySQL only (ForkRace); classified serial + database.
 */
beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this);
});

/** @param list<RaceOutcome> $outcomes */
function endpointVersionOneLanded(array $outcomes): void
{
    $landed = array_values(array_filter($outcomes, static fn (RaceOutcome $outcome): bool => $outcome->ok));
    $refused = array_values(array_filter($outcomes, static fn (RaceOutcome $outcome): bool => !$outcome->ok));

    expect($landed)->toHaveCount(1)
        ->and($refused)->toHaveCount(1)
        ->and($refused[0]->failedWith(StaleWriteException::class) || $refused[0]->failedWith(WebhookEndpointNotFoundException::class))->toBeTrue($refused[0]->exception.': '.$refused[0]->message);
}

it('lets one of a rotation and an update under the same version land and refuses the other with stale_write', function (): void {
    $id = WebhookTestHelpers::endpoint()->getKey();

    $outcomes = ForkRace::run([
        static fn (): string => app(RotateWebhookSecretAction::class)->execute(WebhookEndpoint::query()->findOrFail($id), 0, expectedVersion: 0)->secret,
        static fn (): string => (string) app(UpdateWebhookEndpointAction::class)->execute(WebhookEndpoint::query()->findOrFail($id), new UpdateWebhookEndpointInput(url: 'https://raced.test/hook'), expectedVersion: 0)->version,
    ], timeoutMs: 30_000);

    endpointVersionOneLanded($outcomes);
    expect(WebhookEndpoint::query()->findOrFail($id)->version)->toBe(1);
});

it('lets one of a rotation and a deletion under the same version land and refuses the other', function (): void {
    // Both read the endpoint before the race, as a request does before it writes: read inside the race, the loser's own
    // lookup would fail before its action could refuse it.
    $endpoint = WebhookTestHelpers::endpoint();

    $outcomes = ForkRace::run([
        static fn (): string => app(RotateWebhookSecretAction::class)->execute($endpoint, 0, expectedVersion: 0)->secret,
        static function () use ($endpoint): string {
            app(DeleteWebhookEndpointAction::class)->execute($endpoint, expectedVersion: 0);

            return 'deleted';
        },
    ], timeoutMs: 30_000);

    endpointVersionOneLanded($outcomes);
    $row = WebhookEndpoint::query()->find($endpoint->getKey());
    expect($row === null || $row->version === 1)->toBeTrue();
});
