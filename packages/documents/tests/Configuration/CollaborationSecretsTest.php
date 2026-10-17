<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;

function collaborationConfig(bool $enabled, ?string $jwt, ?string $webhook): DocumentsConfig
{
    return new DocumentsConfig(new Repository(['vaulter-documents' => ['collaboration' => [
        'enabled' => $enabled,
        'hub' => ['jwt_secret' => $jwt, 'webhook_secret' => $webhook],
    ]]]));
}

it('rejects missing or reused collaboration secrets in production', function (?string $jwt, ?string $webhook): void {
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('environment')->with('production')->andReturn(true);

    collaborationConfig(true, $jwt, $webhook)->assertCollaborationSecrets($application);
})->with([[null, null], ['same', 'same'], ['jwt', null], [null, 'webhook']])->throws(InvalidConfigurationException::class);

it('only warns outside production and stays silent for distinct secrets or a disabled collaboration', function (): void {
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('environment')->with('production')->andReturn(false);
    Log::shouldReceive('warning')->once();

    collaborationConfig(true, 'same', 'same')->assertCollaborationSecrets($application);
    collaborationConfig(true, 'jwt', 'webhook')->assertCollaborationSecrets($application);
    collaborationConfig(false, null, null)->assertCollaborationSecrets($application);

    expect(true)->toBeTrue();
});
