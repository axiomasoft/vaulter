<?php

declare(strict_types=1);

namespace Vaulter\Collaboration\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vaulter\Attributes\Internal;
use Vaulter\Collaboration\Configuration\CollaborationConfig;
use Vaulter\Collaboration\Exceptions\CollaborationUpdateTooLargeException;

/**
 * The size gate of the hub's protocol 2 write routes, ahead of the signature check: a body above
 * `vaulter-collaboration.limits.request_bytes` (the append and the compaction lease) or above the checkpoint limit
 * (the route parameter `checkpoint`: a base64 state of `max_state_bytes` plus `request_bytes` for the rest) is refused
 * by its Content-Length (413 collaboration_update_too_large) before anything reads it. The gate never reads the body to
 * measure it: a request without a Content-Length, or with one that is not a plain decimal, is refused the same way (the
 * hub always sends one).
 */
#[Internal]
final readonly class LimitHubAppend
{
    /** The route parameter that sizes the gate for a checkpoint publication. */
    public const string CHECKPOINT = 'checkpoint';

    public function __construct(private CollaborationConfig $config) {}

    public function handle(Request $request, Closure $next, ?string $body = null): Response
    {
        $max = $body === self::CHECKPOINT ? $this->config->checkpointRequestBytes() : $this->config->requestBytes();
        $declared = $request->headers->get('Content-Length');
        if (!is_string($declared) || !ctype_digit($declared)) {
            throw CollaborationUpdateTooLargeException::undeclared($max);
        }
        $size = (int) $declared;
        if ($size > $max) {
            throw new CollaborationUpdateTooLargeException($size, $max);
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
