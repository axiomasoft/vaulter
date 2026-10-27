<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Vaulter\Collaboration\Configuration\CollaborationConfig;
use Vaulter\Collaboration\Security\HubUrlInvariant;
use Vaulter\Collaboration\Security\SecretsInvariant;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;

function collaborationConfig(?string $jwt, ?string $webhook): CollaborationConfig
{
    $repository = new Repository(['vaulter-collaboration' => [
        'hub' => ['jwt_secret' => $jwt, 'webhook_secret' => $webhook],
    ]]);

    return new CollaborationConfig($repository, new DocumentsConfig($repository));
}

it('reports missing or reused collaboration secrets as the collaboration.secrets invariant', function (?string $jwt, ?string $webhook): void {
    $invariant = new SecretsInvariant(collaborationConfig($jwt, $webhook));

    expect($invariant->id())->toBe('collaboration.secrets')
        ->and($invariant->violation())->toContain('nonempty, different')
        ->and($invariant->violation())->not->toContain('same');
})->with([[null, null], ['same', 'same'], ['jwt', null], [null, 'webhook']]);

it('is silent for distinct secrets', function (): void {
    expect((new SecretsInvariant(collaborationConfig('jwt', 'webhook')))->violation())->toBeNull();
});

it('requires a configured hub url to be wss and treats an empty url as no violation', function (?string $url, bool $secure): void {
    $repository = new Repository(['vaulter-collaboration' => ['hub' => ['url' => $url]]]);
    $invariant = new HubUrlInvariant(new CollaborationConfig($repository, new DocumentsConfig($repository)));

    expect($invariant->id())->toBe('collaboration.hub-url')
        ->and($invariant->violation() === null)->toBe($secure);
})->with([[null, true], ['', true], ['   ', true], ['ws://hub', false], ['http://hub', false], ['https://hub.example', false], ['wss://hub.example', true]]);

it('refuses one value for the JWT secret and the webhook secret at boot in every environment', function (): void {
    expect(fn () => collaborationConfig('same', 'same')->assertDistinctHubSecrets())->toThrow(InvalidConfigurationException::class, 'must differ');

    collaborationConfig('jwt', 'webhook')->assertDistinctHubSecrets();
    collaborationConfig(null, null)->assertDistinctHubSecrets();

    expect(true)->toBeTrue();
});
