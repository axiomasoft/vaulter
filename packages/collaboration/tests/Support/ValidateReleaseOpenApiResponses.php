<?php

declare(strict_types=1);

namespace Vaulter\Collaboration\Tests\Support;

use Vaulter\Repo\Tests\Support\ReleaseOpenApi;
use Vaulter\Testing\Support\OpenApi\OpenApiContract;
use Vaulter\Testing\Support\OpenApi\OpenApiResponseValidator;

require_once dirname(__DIR__, 4).'/tests/Repo/Arch/Support/ReleaseOpenApi.php';

use Closure;
use Illuminate\Http\Request;
use LogicException;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Vaulter\Attributes\Internal;

/**
 * Test-only global middleware: every response of a `vaulter.v1.*` route is checked against
 * the controller owner’s public or private spec after the response is built, and the test fails with the list of
 * violations. Routes a test registers itself as `vaulter.v1.__probe` or `vaulter.v1.__probe.*` are fixtures of the
 * HTTP kernel, not API surface, and stay outside the contract.
 */
#[Internal]
final class ValidateReleaseOpenApiResponses
{
    public const string PREFIX = 'vaulter.v1.';

    private const string PROBE = 'vaulter.v1.__probe';

    public static function isProbe(string $name): bool
    {
        return $name === self::PROBE || str_starts_with($name, self::PROBE.'.');
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        return $response instanceof Response ? $response : throw new LogicException('The response contract check wraps a middleware stack that returns a Symfony response.');
    }

    public function terminate(Request $request, Response $response): void
    {
        $name = (string) $request->route()?->getName();
        if (!str_starts_with($name, self::PREFIX) || self::isProbe($name)) {
            return;
        }

        $headers = [];
        foreach ($response->headers->allPreserveCase() as $header => $values) {
            $headers[strtolower((string) $header)] = implode(', ', array_filter((array) $values, is_string(...)));
        }

        $streamed = $response instanceof StreamedResponse || $response instanceof BinaryFileResponse;
        $violations = (new OpenApiResponseValidator(ReleaseOpenApi::routeChannel($request->route()) === 'private' ? ReleaseOpenApi::privateContract() : OpenApiContract::shared()))->validate(
            substr($name, strlen(self::PREFIX)),
            $response->getStatusCode(),
            $headers,
            $streamed || $request->isMethod('HEAD') ? '' : (string) $response->getContent(),
            inspectBody: !$streamed && !$request->isMethod('HEAD'),
        );

        if ($streamed && str_contains($headers['content-type'] ?? '', 'json')) {
            $violations[] = "{$name}: a JSON response must not be streamed: its body cannot be validated";
        }

        if ($violations !== []) {
            throw new AssertionFailedError(
                "{$request->method()} {$request->getPathInfo()} breaks the OpenAPI contract:\n - ".implode("\n - ", $violations),
            );
        }
    }
}
