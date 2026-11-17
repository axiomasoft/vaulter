<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use Vaulter\Configuration\RoutesOptions;
use Vaulter\Documents\Tests\Document\DocumentTestCase;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Drive\Testing\Sharing\ShareTestHelpers;
use Vaulter\Http\Middleware\AddApiVersionHeader;
use Vaulter\Http\Middleware\ApplyResponseCachePolicy;
use Vaulter\Http\Middleware\AssignCorrelationId;
use Vaulter\Http\Middleware\RecordVaulterRequestMetrics;

class MiddlewareStackUser extends User
{
    use HasApiTokens;
}

class MiddlewareStacksTestCase extends DocumentTestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), SanctumServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('vaulter.routes.middleware.authenticated', null);
        $app['config']->set('vaulter.routes.middleware.authenticated', ['api', 'auth:sanctum']);
    }
}

uses(MiddlewareStacksTestCase::class, RefreshDatabase::class);

it('requires Sanctum on protected routes and accepts an authenticated actor', function (): void {
    $storage = DocumentTestHelpers::drive()->model();
    $url = 'api/vaulter/v1/drives/'.$storage->getKey().'/nodes';
    $this->getJson($url)->assertUnauthorized();
    Sanctum::actingAs((new MiddlewareStackUser)->forceFill(['id' => 17]));
    $this->getJson($url)->assertOk();
});

it('serves a valid public share without a Sanctum token', function (): void {
    config()->set('vaulter-drive.features.share_links', true);
    $node = DocumentTestHelpers::drive()->root();
    ShareTestHelpers::share($node, token: 'middleware-stack-public-token');
    $this->getJson('api/vaulter/v1/shares/middleware-stack-public-token')->assertOk();
});

it('retains legacy authenticated middleware and appends the package middleware once in every stack', function (): void {
    $package = [AssignCorrelationId::class, AddApiVersionHeader::class, ApplyResponseCachePolicy::class, RecordVaulterRequestMetrics::class];
    config()->set('vaulter.routes.middleware.authenticated', ['api']);
    expect(app(RoutesOptions::class)->authenticatedMiddleware())->toBe(['api', ...$package]);
    config()->set('vaulter.routes.middleware.authenticated');
    foreach (['authenticated', 'public', 'hub'] as $stack) {
        config()->set('vaulter.routes.middleware.'.$stack, ['api', ...$package]);
    }
    expect(app(RoutesOptions::class)->authenticatedMiddleware())->toBe(['api', ...$package])
        ->and(app(RoutesOptions::class)->publicMiddleware())->toBe(['api', ...$package])
        ->and(app(RoutesOptions::class)->hubMiddleware())->toBe(['api', ...$package]);
});
