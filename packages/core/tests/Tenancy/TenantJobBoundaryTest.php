<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Tenancy\TenancyMisconfiguredException;
use Vaulter\Facades\Vaulter;
use Vaulter\Jobs\Middleware\RestoreTenantContext;
use Vaulter\Testing\Tenancy\MutableTenantResolver;
use Vaulter\Tests\TestCase;
use Vaulter\ValueObjects\Access\Actor;
use Vaulter\ValueObjects\Access\ActorRef;

uses(TestCase::class, RefreshDatabase::class);

afterEach(fn () => MutableTenantResolver::clearFixtureStamps());

it('V08 validates host A B A restores on next exception and fails absent or foreign tenant without work', function (): void {
    config()->set('vaulter.tenancy.enabled', true);
    config()->set('vaulter.tenancy.resolver', MutableTenantResolver::class);
    $resolver = new MutableTenantResolver;
    app()->instance(TenantResolver::class, $resolver);
    $a = AccessTestHelpers::storage();
    $resolver->tenant = 'B';
    $b = AccessTestHelpers::storage();
    $job = new class
    {
        public array $failures = [];

        public function fail(Throwable $exception): void
        {
            $this->failures[] = $exception;
        }
    };
    $called = 0;
    $next = function () use (&$called): void {
        $called++;
    };
    $mA = new RestoreTenantContext($a->id, 'A');
    $mB = new RestoreTenantContext($b->id, 'B');
    $resolver->tenant = 'A';
    expect(fn () => $mA->handle($job, fn () => throw new RuntimeException('work failed')))->toThrow(RuntimeException::class);
    expect($resolver->current())->toBe('A')->and($mA->actor())->toBeNull();
    $resolver->tenant = 'B';
    $mB->handle($job, $next);
    Vaulter::withoutTenancy(fn () => $mA->handle($job, $next));
    $resolver->tenant = null;
    $mA->handle($job, $next);
    $resolver->tenant = 'A';
    $mA->handle($job, $next);
    (new RestoreTenantContext($a->id, null))->handle($job, $next);
    expect($called)->toBe(2)->and($job->failures)->toHaveCount(3)
        ->and($job->failures[0])->toBeInstanceOf(NodeNotFoundException::class)
        ->and($job->failures[1])->toBeInstanceOf(TenancyMisconfiguredException::class)
        ->and($resolver->current())->toBe('A');
});

it('V08 a deleted queued actor fails without promotion to system or invoking next', function (): void {
    config()->set('vaulter.tenancy.enabled', true);
    config()->set('vaulter.tenancy.resolver', MutableTenantResolver::class);
    app()->instance(TenantResolver::class, new MutableTenantResolver);
    $drive = AccessTestHelpers::storage();
    $job = new class
    {
        public ?Throwable $failure = null;

        public function fail(Throwable $exception): void
        {
            $this->failure = $exception;
        }
    };
    config()->set('auth.guards.web.provider', null);
    $middleware = new RestoreTenantContext($drive->id, 'A', new ActorRef('deleted-user', 999));
    $called = false;
    $middleware->handle($job, function () use (&$called): void {
        $called = true;
    });
    expect($called)->toBeFalse()->and($job->failure)->toBeInstanceOf(NodeNotFoundException::class)->and($middleware->actor())->toBeNull();
});

it('V08 rehydrates a user through Auth and evaluates changed permissions at execution', function (): void {
    config()->set('vaulter.tenancy.enabled', true);
    config()->set('vaulter.tenancy.resolver', MutableTenantResolver::class);
    config()->set('vaulter.access.permission_driver', 'database');
    app()->instance(TenantResolver::class, new MutableTenantResolver);
    $drive = AccessTestHelpers::storage();
    $actor = AccessTestHelpers::user(31);
    $root = Vaulter::drive($drive)->asSystem('fixture')->root();
    $grant = AccessTestHelpers::grant($root, RoleRegistry::OWNER, subjectId: 31);
    $provider = Mockery::mock(UserProvider::class);
    $provider->shouldReceive('retrieveById')->with(31)->twice()->andReturn($actor);
    Auth::shouldReceive('createUserProvider')->with('users')->twice()->andReturn($provider);
    config()->set('auth.defaults.guard', 'web');
    config()->set('auth.guards.web.provider', 'users');
    $middleware = new RestoreTenantContext($drive->id, 'A', Actor::user($actor)->ref());
    $worked = 0;
    $next = function () use ($middleware, $drive, &$worked): void {
        Vaulter::drive($drive)->actingAs($middleware->actor()->authorizable())->root();
        $worked++;
    };
    $middleware->handle(new stdClass, $next);
    $grant->delete();
    expect(fn () => $middleware->handle(new stdClass, $next))->toThrow(NodeNotFoundException::class);
    expect($worked)->toBe(1)->and($middleware->actor())->toBeNull();
});
