<?php

declare(strict_types=1);

namespace Vaulter\Support;

use Illuminate\Http\Request;
use Packman\Attributes\Internal;
use Vaulter\Http\Middleware\AssignCorrelationId;

/**
 * The request-scoped audit context the ActivityRecorder stamps onto an activity row
 * the client ip, the user-agent and a per-request correlation id. It is
 * captured from the CURRENT request when one is bound and degrades to all-null off
 * the request lifecycle (console commands, queue workers) — recording an event from
 * a job must never blow up for lack of a request.
 *
 * The correlation id is the token {@see AssignCorrelationId} already assigned (an
 * accepted X-Request-Id / X-Correlation-Id, or one ULID). A raw header is never copied
 * into the audit row. Off-request the id stays null.
 */
#[Internal]
final readonly class RequestContext
{
    public function __construct(
        public ?string $ip = null,
        public ?string $userAgent = null,
        public ?string $requestId = null,
    ) {}

    /**
     * Capture the context from the bound HTTP request, or an all-null context when
     * none is bound (console/queue). Never throws.
     */
    public static function capture(): self
    {
        $request = self::currentRequest();

        if (!$request instanceof Request) {
            return new self;
        }

        return new self(
            ip: $request->ip(),
            userAgent: self::userAgentOf($request),
            requestId: self::requestIdOf($request),
        );
    }

    /**
     * The bound request, or null when there is none (off the HTTP lifecycle). We
     * resolve it through the container WITHOUT forcing a fresh instance, so a
     * console/queue context yields null instead of a synthetic request.
     */
    private static function currentRequest(): ?Request
    {
        if (!app()->bound('request')) {
            return null;
        }

        return app('request');
    }

    private static function userAgentOf(Request $request): ?string
    {
        $agent = $request->userAgent();

        return is_string($agent) && $agent !== '' ? $agent : null;
    }

    /**
     * The same token the response echoes. One assignment per request, shared by every
     * activity row recorded while that request is current.
     */
    private static function requestIdOf(Request $request): string
    {
        return AssignCorrelationId::resolve($request);
    }
}
