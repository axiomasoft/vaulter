<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Vaulter\Collaboration\Configuration\CollaborationConfig;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;

function collaborationConfig(?string $jwt, ?string $webhook): CollaborationConfig
{
    $repository = new Repository(['vaulter-collaboration' => [
        'hub' => ['jwt_secret' => $jwt, 'webhook_secret' => $webhook],
    ]]);

    return new CollaborationConfig($repository, new DocumentsConfig($repository));
}

it('rejects missing or reused collaboration secrets in production', function (?string $jwt, ?string $webhook): void {
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('environment')->with('production')->andReturn(true);

    collaborationConfig($jwt, $webhook)->assertSecrets($application);
})->with([[null, null], ['same', 'same'], ['jwt', null], [null, 'webhook']])->throws(InvalidConfigurationException::class);

it('only warns outside production and stays silent for distinct secrets', function (): void {
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('environment')->with('production')->andReturn(false);
    Log::shouldReceive('warning')->once();

    collaborationConfig('same', 'same')->assertSecrets($application);
    collaborationConfig('jwt', 'webhook')->assertSecrets($application);

    expect(true)->toBeTrue();
});

it('refuses one value for the JWT secret and the webhook secret at boot in every environment', function (): void {
    expect(fn () => collaborationConfig('same', 'same')->assertDistinctHubSecrets())->toThrow(InvalidConfigurationException::class, 'must differ');

    collaborationConfig('jwt', 'webhook')->assertDistinctHubSecrets();
    collaborationConfig(null, null)->assertDistinctHubSecrets();

    expect(true)->toBeTrue();
});
