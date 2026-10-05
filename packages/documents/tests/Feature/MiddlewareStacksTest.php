<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Collaboration\CollaborationCallResolver;
use Vaulter\Documents\Contracts\HostUserResolver;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Tests\Collaboration\CollaborationEnabledTestCase;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Http\Middleware\RecordVaulterRequestMetrics;
use Vaulter\Support\Config;
use Vaulter\Tests\Fixtures\FakeHostUserResolver;
use Vaulter\Tests\Sharing\ShareTestHelpers;
use Vaulter\Webhooks\WebhookSigner;

class MiddlewareStackUser extends User
{
    use HasApiTokens;
}

class MiddlewareStacksTestCase extends CollaborationEnabledTestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), SanctumServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('vaulter.middleware', null);
        $app['config']->set('vaulter.routes.middleware.authenticated', ['api', 'auth:sanctum']);
        $app['config']->set('vaulter.collaboration.driver', 'hocuspocus');
        $app['config']->set('vaulter.collaboration.webhook_secret', 'middleware-stack-webhook-secret');
        $app['config']->set('vaulter.collaboration.drivers.hocuspocus.secret', 'middleware-stack-jwt-secret-at-least-32-bytes');
    }
}

uses(MiddlewareStacksTestCase::class, RefreshDatabase::class);

it('requires Sanctum on protected routes and accepts an authenticated actor', function (): void {
    $storage = DocumentTestHelpers::drive()->model();
    $url = 'api/vaulter/storages/'.$storage->getKey().'/nodes';
    $this->getJson($url)->assertUnauthorized();
    Sanctum::actingAs((new MiddlewareStackUser)->forceFill(['id' => 17]));
    $this->getJson($url)->assertOk();
});

it('serves a valid public share without a Sanctum token', function (): void {
    $node = DocumentTestHelpers::drive()->root();
    ShareTestHelpers::share($node, token: 'middleware-stack-public-token');
    $this->getJson('api/vaulter/share/middleware-stack-public-token')->assertOk();
});

it('accepts a signed hub JWT without a Sanctum token', function (): void {
    $drive = DocumentTestHelpers::drive();
    app()->bind(HostUserResolver::class, fn () => new FakeHostUserResolver);
    $node = app(CreateDocumentAction::class)->execute(new CreateDocumentInput(
        storage: $drive->model(), name: 'Hub', content: DocumentTestHelpers::content(), parent: $drive->root(),
    ));
    $body = ['node_id' => $node->getKey()];
    $timestamp = time();
    $jwt = JWT::encode([
        'room' => Config::collaborationRoom($node->getKey()),
        'sub' => '17', 'readOnly' => false, 'iat' => $timestamp, 'exp' => $timestamp + 3600,
    ], 'middleware-stack-jwt-secret-at-least-32-bytes', 'HS256');
    $signature = (new WebhookSigner)->sign(json_encode($body), 'middleware-stack-webhook-secret', $timestamp);
    $response = $this->postJson('api/vaulter/collab/authenticate', $body, [
        'Authorization' => 'Bearer '.$jwt,
        CollaborationCallResolver::TIMESTAMP_HEADER => (string) $timestamp,
        CollaborationCallResolver::SIGNATURE_HEADER => $signature,
    ]);
    $response->assertOk()->assertJsonPath('allowed', true)->assertJsonPath('user.id', '17');
});

it('retains legacy authenticated middleware and appends metrics once in every stack', function (): void {
    config()->set('vaulter.middleware', ['api']);
    expect(Config::authenticatedMiddleware())->toBe(['api', RecordVaulterRequestMetrics::class]);
    config()->set('vaulter.middleware', null);
    foreach (['authenticated', 'public', 'hub'] as $stack) {
        config()->set('vaulter.routes.middleware.'.$stack, ['api', RecordVaulterRequestMetrics::class]);
    }
    expect(Config::authenticatedMiddleware())->toBe(['api', RecordVaulterRequestMetrics::class])
        ->and(Config::publicMiddleware())->toBe(['api', RecordVaulterRequestMetrics::class])
        ->and(Config::hubMiddleware())->toBe(['api', RecordVaulterRequestMetrics::class]);
});
