<?php

declare(strict_types=1);

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Exceptions\Database\ConcurrentUpdateException;
use Vaulter\Exceptions\Http\PreconditionRequiredException;
use Vaulter\Exceptions\Idempotency\IdempotencyKeyReusedException;
use Vaulter\Exceptions\Idempotency\OperationInProgressException;
use Vaulter\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Exceptions\Policy\FileSizeLimitExceededException;
use Vaulter\Http\Problems\ProblemCatalog;
use Vaulter\Http\Problems\ProblemRenderer;
use Vaulter\Testing\Support\ProblemProbeRoutes;

const PROBLEM_TYPE_BASE = 'https://github.com/axiomasoft/vaulter/blob/main/docs/reference/errors.md#';

it('renders a domain exception as problem+json with the catalogue title and a request id', function (): void {
    ProblemProbeRoutes::register(['conflict' => NodeNameConflictException::forName('contract.pdf', null)]);

    $response = $this->getJson(ProblemProbeRoutes::path('conflict'))
        ->assertStatus(409)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertHeader('Content-Language', 'en')
        ->assertHeader('X-Vaulter-Api-Version', '1')
        ->assertJsonPath('type', PROBLEM_TYPE_BASE.'node_name_conflict')
        ->assertJsonPath('title', 'Node name conflict')
        ->assertJsonPath('status', 409)
        ->assertJsonPath('code', 'node_name_conflict')
        ->assertJsonPath('detail', 'A node with this name already exists in the folder.')
        ->assertJsonPath('errors.name', ['A node with this name already exists in the folder.']);

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->json('request_id'))->toBe($response->headers->get('X-Request-Id'))
        ->and($response->json('meta'))->toBe([])
        ->and($response->json('retryable'))->toBe('never')
        ->and(array_keys($response->json()))->toBe(['type', 'title', 'status', 'code', 'detail', 'retryable', 'request_id', 'errors', 'meta']);
    // The exception message is written for logs; it must never reach a client.
    expect($response->getContent())->not->toContain('[Vaulter]')->not->toContain('contract.pdf');
});

it('localizes title and detail but never the code', function (): void {
    app()->setLocale('ru');
    ProblemProbeRoutes::register(['conflict' => NodeNameConflictException::forName('a', null)]);

    $this->getJson(ProblemProbeRoutes::path('conflict'))
        ->assertStatus(409)
        ->assertHeader('Content-Language', 'ru')
        ->assertJsonPath('code', 'node_name_conflict')
        ->assertJsonPath('title', 'Конфликт имени узла')
        ->assertJsonPath('detail', 'Узел с таким именем уже есть в папке.');
});

it('carries problemDetails() as meta and fills detail placeholders from it', function (): void {
    ProblemProbeRoutes::register(['size' => FileSizeLimitExceededException::forLimit(5)]);

    $this->getJson(ProblemProbeRoutes::path('size'))
        ->assertStatus(413)
        ->assertJsonPath('code', 'file_size_limit_exceeded')
        ->assertJsonPath('meta.limit', 5)
        ->assertJsonPath('detail', 'The file exceeds the limit of 5 bytes.')
        ->assertJsonPath('errors', null);
});

it('answers a failed precondition with 428 and names the header', function (): void {
    ProblemProbeRoutes::register([
        'missing' => PreconditionRequiredException::missing('If-Match'),
        'wildcard' => PreconditionRequiredException::wildcard(),
    ]);

    $this->getJson(ProblemProbeRoutes::path('missing'))
        ->assertStatus(428)
        ->assertJsonPath('code', 'precondition_required')
        ->assertJsonPath('meta', ['header' => 'If-Match', 'reason' => 'missing'])
        ->assertJsonPath('detail', 'The If-Match header is required for this request.');
    $this->getJson(ProblemProbeRoutes::path('wildcard'))
        ->assertStatus(428)
        ->assertJsonPath('meta.reason', 'wildcard_not_allowed');
});

it('keeps the headers an exception asks for', function (): void {
    ProblemProbeRoutes::register(['concurrent' => new ConcurrentUpdateException]);

    $this->getJson(ProblemProbeRoutes::path('concurrent'))
        ->assertStatus(409)
        ->assertJsonPath('code', 'concurrent_update')
        ->assertHeader('Retry-After', '1');
});

it('renders a validation failure with the Laravel error map', function (): void {
    ProblemProbeRoutes::register(['invalid' => ValidationException::withMessages(['name' => ['The name field is required.']])]);

    $this->getJson(ProblemProbeRoutes::path('invalid'))
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors', ['name' => ['The name field is required.']]);
});

it('forwards Retry-After on a throttled request', function (): void {
    ProblemProbeRoutes::register(['throttle' => new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => '30', 'X-RateLimit-Limit' => '60'])]);

    $response = $this->getJson(ProblemProbeRoutes::path('throttle'))
        ->assertStatus(429)
        ->assertJsonPath('code', 'rate_limited')
        ->assertHeader('Retry-After', '30');

    expect($response->headers->has('X-RateLimit-Limit'))->toBeFalse();
});

it('hides an unexpected failure behind internal_error', function (): void {
    ProblemProbeRoutes::register(['boom' => new RuntimeException('SQLSTATE[HY000]: password=hunter2 at /srv/app/secret.php')]);

    $response = $this->getJson(ProblemProbeRoutes::path('boom'))
        ->assertStatus(500)
        ->assertJsonPath('code', 'internal_error')
        ->assertJsonPath('title', 'Internal server error')
        ->assertJsonPath('detail', null);

    expect($response->getContent())->not->toContain('hunter2')->not->toContain('SQLSTATE')->not->toContain('/srv/app');
});

it('answers a wrong method with 405 and the Allow header', function (): void {
    ProblemProbeRoutes::register(['any' => new RuntimeException]);

    $this->postJson(ProblemProbeRoutes::path('any'))
        ->assertStatus(405)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'method_not_allowed')
        ->assertHeader('Allow');
});

it('answers an unknown path under the versioned prefix with route_not_found', function (): void {
    $prefix = app(RoutesConfig::class)->versionedPrefix();

    $this->getJson($prefix.'/x')
        ->assertStatus(404)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'route_not_found')
        ->assertHeader('X-Vaulter-Api-Version', '1');
});

it('keeps a valid incoming request id and replaces a malformed one', function (): void {
    ProblemProbeRoutes::register(['boom' => new RuntimeException]);

    $this->getJson(ProblemProbeRoutes::path('boom'), ['X-Request-Id' => 'client-req-42'])
        ->assertHeader('X-Request-Id', 'client-req-42')
        ->assertJsonPath('request_id', 'client-req-42');

    $replaced = $this->getJson(ProblemProbeRoutes::path('boom'), ['X-Request-Id' => "bad id\twith spaces"]);
    expect($replaced->json('request_id'))->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($replaced->headers->get('X-Request-Id'))->toBe($replaced->json('request_id'));
});

it('prefers the request id the correlation middleware assigned', function (): void {
    $request = Request::create(ProblemProbeRoutes::path('boom'), 'GET');
    $request->attributes->set('vaulter.request_id', 'assigned-1');
    $renderer = app(ProblemRenderer::class);

    $response = $renderer->render(new RuntimeException, $request);

    expect($response->headers->get('X-Request-Id'))->toBe('assigned-1');
});

it('maps an authentication failure to actor_required', function (): void {
    ProblemProbeRoutes::register(['guest' => new AuthenticationException]);

    $this->getJson(ProblemProbeRoutes::path('guest'))
        ->assertStatus(401)
        ->assertJsonPath('code', 'actor_required');
});

it('says whether the request may be retried and when', function (Throwable $exception, string $retryable, ?string $retryAfter): void {
    ProblemProbeRoutes::register(['case' => $exception]);

    $response = $this->getJson(ProblemProbeRoutes::path('case'))->assertJsonPath('retryable', $retryable);

    expect($response->headers->get('Retry-After'))->toBe($retryAfter);
})->with([
    'in progress' => [new OperationInProgressException(3), 'with_backoff', '3'],
    'concurrent update' => [new ConcurrentUpdateException, 'with_backoff', '1'],
    'throttled' => [new ThrottleRequestsException(headers: ['Retry-After' => '7']), 'with_backoff', '7'],
    'precondition required' => [PreconditionRequiredException::missing('If-Match'), 'after_refetch', null],
    'key reused' => [IdempotencyKeyReusedException::forOperation('drive.folders.create'), 'never', null],
    'unexpected' => [new RuntimeException('boom'), 'never', null],
]);

it('answers an unknown status code as not retryable', function (): void {
    ProblemProbeRoutes::register(['teapot' => new HttpException(418)]);

    $this->getJson(ProblemProbeRoutes::path('teapot'))
        ->assertStatus(418)
        ->assertJsonPath('code', 'http_418')
        ->assertJsonPath('retryable', 'never');
});

it('knows every status it can answer with in the catalogue', function (): void {
    $catalog = app(ProblemCatalog::class);

    foreach (['validation_failed', 'rate_limited', 'internal_error', 'route_not_found', 'method_not_allowed', 'payload_too_large', 'precondition_required'] as $code) {
        expect($catalog->has($code))->toBeTrue($code);
    }
});
