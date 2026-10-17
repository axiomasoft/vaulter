<?php

declare(strict_types=1);

namespace Vaulter\Collaboration\Http\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vaulter\Attributes\Internal;
use Vaulter\Collaboration\Exceptions\CollaborationProtocolMismatchException;

/**
 * The version of the hub ↔ backend protocol a hub call speaks (header `X-Vaulter-Collab-Protocol`). Protocol 2 routes
 * require it; `load` answers protocol 1 to a call without the header until the v1 hub is gone (P5.7). Every protocol 2
 * answer repeats the header.
 */
#[Internal]
final class HubProtocol
{
    public const string HEADER = 'X-Vaulter-Collab-Protocol';

    public const int VERSION = 2;

    /** @throws CollaborationProtocolMismatchException when the call does not speak protocol 2 */
    public static function require(Request $request): void
    {
        if (!self::speaksV2($request)) {
            throw new CollaborationProtocolMismatchException(self::presented($request), self::VERSION);
        }
    }

    /**
     * Whether the call asks for protocol 2; a call without the header is protocol 1.
     *
     * @throws CollaborationProtocolMismatchException for a header naming any other version
     */
    public static function negotiate(Request $request): bool
    {
        $presented = self::presented($request);
        if ($presented === null) {
            return false;
        }
        if (!self::speaksV2($request)) {
            throw new CollaborationProtocolMismatchException($presented, self::VERSION);
        }

        return true;
    }

    /**
     * @template T of Response
     *
     * @param  T $response
     * @return T
     */
    public static function mark(Response $response): Response
    {
        $response->headers->set(self::HEADER, (string) self::VERSION);

        return $response;
    }

    private static function speaksV2(Request $request): bool
    {
        return self::presented($request) === (string) self::VERSION;
    }

    private static function presented(Request $request): ?string
    {
        $value = $request->headers->get(self::HEADER);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
