<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Http\Problems\ProblemRenderer;
use Vaulter\Tests\TestCase;

uses(TestCase::class);

it('preserves existing JSON rendering and maps actor scope ability and profile errors', function (string $error, int $status, string $code): void {
    $exception = match ($error) {
        'actor' => MissingActorException::required(),
        'scope' => NodeNotFoundException::forId(42),
        'ability' => PermissionDeniedException::forAbility('edit'),
        'profile' => ProfileRestrictionException::forOperation('share'),
    };
    $request = Request::create(uri: app(RoutesConfig::class)->versionedPrefix().'/test', server: ['HTTP_ACCEPT' => 'application/json']);
    $response = app(ProblemRenderer::class)->render($exception, $request);
    expect($response)->not->toBeNull()->and($response->getStatusCode())->toBe($status)
        ->and(json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['code'])->toBe($code);
})->with(['actor' => ['actor', 401, 'actor_required'], 'scope' => ['scope', 404, 'node_not_found'], 'ability' => ['ability', 403, 'permission_denied'], 'profile' => ['profile', 403, 'profile_restriction']]);
