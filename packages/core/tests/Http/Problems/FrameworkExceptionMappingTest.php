<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Testing\Support\ProblemProbeRoutes;

it('maps a framework exception to its problem status and code', function (Throwable $exception, int $status, string $code): void {
    ProblemProbeRoutes::register(['case' => $exception]);

    $this->getJson(ProblemProbeRoutes::path('case'))
        ->assertStatus($status)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', $code)
        ->assertJsonPath('status', $status);
})->with([
    'authentication' => [new AuthenticationException, 401, 'actor_required'],
    'missing actor' => [MissingActorException::required(), 401, 'actor_required'],
    'gate denial' => [new AuthorizationException('no'), 403, 'permission_denied'],
    'gate denial with status' => [(new AuthorizationException('hidden'))->withStatus(403), 403, 'permission_denied'],
    'gate denial as not found' => [(new AuthorizationException('hidden'))->withStatus(404), 404, 'node_not_found'],
    'missing model' => [new ModelNotFoundException, 404, 'node_not_found'],
    'not found' => [new NotFoundHttpException, 404, 'route_not_found'],
    'post too large' => [new PostTooLargeException, 413, 'payload_too_large'],
    'teapot' => [new HttpException(418, 'teapot'), 418, 'http_418'],
    'unavailable' => [new ServiceUnavailableHttpException(30, 'down'), 503, 'http_503'],
    'unexpected' => [new LogicException('internal'), 500, 'internal_error'],
]);

it('titles an unregistered http status with its standard phrase and no detail', function (): void {
    ProblemProbeRoutes::register(['case' => new HttpException(418, 'teapot message')]);

    $response = $this->getJson(ProblemProbeRoutes::path('case'))
        ->assertJsonPath('title', "I'm a teapot")
        ->assertJsonPath('detail', null);

    expect($response->getContent())->not->toContain('teapot message');
});

it('keeps Retry-After of a service-unavailable answer', function (): void {
    ProblemProbeRoutes::register(['case' => new ServiceUnavailableHttpException(30, 'down')]);

    $this->getJson(ProblemProbeRoutes::path('case'))->assertHeader('Retry-After', '30');
});
