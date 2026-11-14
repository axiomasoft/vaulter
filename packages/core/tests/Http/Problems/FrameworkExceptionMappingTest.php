<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Testing\Concerns\FreshApplication;
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

it('answers the invalid-graph catch-all outside v1 with 503 module_graph_invalid, not 500', function (string $uri): void {
    $app = FreshApplication::boot(['vaulter.modules' => ['nope' => true]]);
    $response = $app->make(HttpKernel::class)->handle(Request::create($uri, 'GET', server: ['HTTP_ACCEPT' => 'application/json']));
    $body = json_decode((string) $response->getContent(), true);

    expect($response->getStatusCode())->toBe(503)
        ->and($response->headers->get('Content-Type'))->toContain('application/problem+json')
        ->and($body)->toMatchArray(['code' => 'module_graph_invalid', 'status' => 503])
        ->and($response->getContent())->not->toContain('vaulter.modules.nope')->not->toContain('[Vaulter]');
})->with([
    'the package prefix' => ['/api/vaulter'],
    'a path under the prefix that is not v1' => ['/api/vaulter/not-v1'],
]);
