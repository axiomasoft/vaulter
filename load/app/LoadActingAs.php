<?php

declare(strict_types=1);

namespace Vaulter\Load;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Vaulter\Testing\Fixtures\TestUser;

/**
 * Test authentication for the VLT-LOAD stand (NOT for production).
 *
 * Mirrors tests/Integration/E2E/E2eActingAs.php (P2.1 Required Reads #4): a real host
 * plugs its own auth layer, this stand takes the actor id from `X-Load-Actor` — k6
 * (harness.md §4 executor split, operations-catalog.md many-grant actor) walks in as N
 * distinct users by varying this header per VU/session, no session/CSRF/login needed.
 */
final class LoadActingAs
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->header('X-Load-Actor');

        if ($id !== null && $id !== '') {
            $user = (new TestUser)->forceFill(['id' => (int) $id]);
            $user->exists = true;

            Auth::setUser($user);
            $request->setUserResolver(static fn (): TestUser => $user);
        }

        return $next($request);
    }
}
