<?php

declare(strict_types=1);

namespace Vaulter\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The request-scoped audit context the ActivityRecorder stamps onto an activity row
 * the client ip, the user-agent and a per-request correlation id. It is
 * captured from the CURRENT request when one is bound and degrades to all-null off
 * the request lifecycle (console commands, queue workers) — recording an event from
 * a job must never blow up for lack of a request.
 *
 * The correlation id prefers an inbound X-Request-Id / X-Correlation-Id header (so a
 * gateway-assigned trace id survives) and otherwise mints a uuid, but only when a
 * request actually exists; off-request it stays null.
 */
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
     * Prefer a trace id propagated by the gateway/host (X-Request-Id /
     * X-Correlation-Id); only when none is present do we mint one ONCE and stash it
     * on the request, so every activity row recorded within the same logical request
     * shares the one correlation id.
     */
    private static function requestIdOf(Request $request): string
    {
        $header = $request->header('X-Request-Id') ?? $request->header('X-Correlation-Id');

        if (is_string($header) && $header !== '') {
            return $header;
        }

        $stashed = $request->attributes->get('vaulter_request_id');

        if (is_string($stashed) && $stashed !== '') {
            return $stashed;
        }

        $minted = (string) Str::uuid();
        $request->attributes->set('vaulter_request_id', $minted);

        return $minted;
    }
}
