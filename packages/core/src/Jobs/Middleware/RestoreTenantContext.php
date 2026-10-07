<?php

declare(strict_types=1);

namespace Vaulter\Jobs\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Auth;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Tenancy\TenancyMisconfiguredException;
use Vaulter\Tenancy\TenancyBoundary;
use Vaulter\Tenancy\TenancyBypass;
use Vaulter\ValueObjects\Access\Actor;
use Vaulter\ValueObjects\Access\ActorRef;

/** Host restoration middleware precedes this validator; TenantResolver is read-only. */
final class RestoreTenantContext
{
    private ?Actor $actor = null;

    public function __construct(private readonly int|string $driveId, private readonly int|string|null $tenantId,
        private readonly ?ActorRef $actorRef = null) {}

    public function actor(): ?Actor
    {
        return $this->actor;
    }

    public function handle(object $job, Closure $next): mixed
    {
        return app(TenancyBypass::class)->suspend(function () use ($job, $next): mixed {
            try {
                app(TenancyBoundary::class)->assertJobDrive($this->driveId, $this->tenantId);
                if ($this->actorRef !== null) {
                    $ref = $this->actorRef;
                    if ($ref->type === ActorRef::SYSTEM_TYPE) {
                        $this->actor = Actor::system($ref->reason ?? '');
                    } else {
                        $configuration = app(Repository::class);
                        $guard = $configuration->get('auth.defaults.guard');
                        $providerName = is_string($guard) ? $configuration->get('auth.guards.'.$guard.'.provider') : null;
                        $user = is_string($providerName) ? Auth::createUserProvider($providerName)?->retrieveById($ref->id) : null;
                        if (!$user instanceof Authorizable) {
                            throw NodeNotFoundException::forId('actor');
                        }
                        $identifier = $user->getAuthIdentifier();
                        if ((!is_int($identifier) && !is_string($identifier)) || Actor::user($user)->ref()->type !== $ref->type
                            || (string) $identifier !== (string) $ref->id) {
                            throw NodeNotFoundException::forId('actor');
                        }
                        $this->actor = Actor::user($user);
                    }
                }
            } catch (NodeNotFoundException|TenancyMisconfiguredException $exception) {
                if (method_exists($job, 'fail')) {
                    $job->fail($exception);

                    return null;
                }
                throw $exception;
            }
            try {
                return $next($job);
            } finally {
                $this->actor = null;
            }
        });
    }
}
