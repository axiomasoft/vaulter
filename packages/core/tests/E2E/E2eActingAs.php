<?php

declare(strict_types=1);

namespace Vaulter\Tests\E2E;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * Тестовая аутентификация для e2e-стенда (НЕ для прода).
 *
 * Боевой хост подключает свой auth-слой; здесь, чтобы не тащить сессии/CSRF/логин в
 * стенд, актора берём из заголовка `X-E2E-User` (или ?e2e_user= как запасной вариант).
 * Браузер A открывает редактор как user 1 (writer), браузер B — как user 2 (viewer);
 * vaulter-маршруты остаются на группе `api`, а серверные collab-вебхуки не несут этот
 * заголовок и проходят мимо — их аутентифицирует ResolveCollaborationCall.
 */
final class E2eActingAs
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->header('X-E2E-User') ?? $request->query('e2e_user');

        if ($id !== null && $id !== '') {
            $user = (new TestUser)->forceFill(['id' => (int) $id]);
            $user->exists = true;

            Auth::setUser($user);
            $request->setUserResolver(static fn (): TestUser => $user);
        }

        return $next($request);
    }
}
