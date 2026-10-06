<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\Tests\TestCase;

uses(TestCase::class);

it('preserves existing JSON rendering and maps actor scope ability and profile errors', function (string $error, int $status, string $code): void {
    $exception = match ($error) {
        'actor' => MissingActorException::required(),
        'scope' => NodeNotFoundException::forId(42),
        'ability' => PermissionDeniedException::forAbility('edit'),
        'profile' => ProfileRestrictionException::forOperation('share'),
    };
    $request = Request::create(uri: '/api/vaulter/test', server: ['HTTP_ACCEPT' => 'application/json']);
    $response = $exception->render($request);
    expect($response)->not->toBeFalse()->and($response->getStatusCode())->toBe($status)
        ->and($response->getData(true)['code'])->toBe($code);
})->with(['actor' => ['actor', 401, 'actor_required'], 'scope' => ['scope', 404, 'node_not_found'], 'ability' => ['ability', 403, 'permission_denied'], 'profile' => ['profile', 403, 'profile_restriction']]);
