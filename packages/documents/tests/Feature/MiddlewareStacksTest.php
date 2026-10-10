<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Collaboration\CollaborationCallResolver;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Contracts\HostUserResolver;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Tests\Collaboration\CollaborationEnabledTestCase;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Http\Middleware\RecordVaulterRequestMetrics;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\FakeHostUserResolver;
use Vaulter\Tests\Sharing\ShareTestHelpers;

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
        $app['config']->set('vaulter.routes.middleware.authenticated', null);
        $app['config']->set('vaulter.routes.middleware.authenticated', ['api', 'auth:sanctum']);
        $app['config']->set('vaulter-documents.collaboration.driver', 'hocuspocus');
        $app['config']->set('vaulter-documents.collaboration.hub.webhook_secret', 'middleware-stack-webhook-secret');
        $app['config']->set('vaulter-documents.collaboration.hub.jwt_secret', 'middleware-stack-jwt-secret-at-least-32-bytes');
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
    config()->set('vaulter.features.share_links', true);
    $node = DocumentTestHelpers::drive()->root();
    ShareTestHelpers::share($node, token: 'middleware-stack-public-token');
    $this->getJson('api/vaulter/share/middleware-stack-public-token')->assertOk();
});

it('accepts a signed hub JWT without a Sanctum token', function (): void {
    $drive = DocumentTestHelpers::drive();
    app()->bind(HostUserResolver::class, fn () => new FakeHostUserResolver);
    $node = app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($drive->model()), new CreateDocumentInput(
        storage: $drive->model(), name: 'Hub', content: DocumentTestHelpers::content(), parent: DriveTestHelpers::model($drive->root()),
    ));
    $room = app(DocumentsConfig::class)->collaborationRoom($node->drive_id, $node->getKey(), 0);
    $body = ['room' => $room];
    $timestamp = time();
    $jwt = JWT::encode([
        'room' => $room, 'node' => (string) $node->getKey(), 'drive' => (string) $node->drive_id, 'tenant' => null,
        'epoch' => 0, 'schema_version' => 1, 'mode' => 'rw',
        'sub' => '17', 'iat' => $timestamp, 'exp' => $timestamp + 3600,
    ], 'middleware-stack-jwt-secret-at-least-32-bytes', 'HS256');
    $signature = CollaborationCallResolver::signature((string) json_encode($body), 'middleware-stack-webhook-secret', $timestamp);
    $response = $this->postJson('api/vaulter/collab/authenticate', $body, [
        'Authorization' => 'Bearer '.$jwt,
        CollaborationCallResolver::TIMESTAMP_HEADER => (string) $timestamp,
        CollaborationCallResolver::SIGNATURE_HEADER => $signature,
    ]);
    $response->assertOk()->assertJsonPath('allowed', true)->assertJsonPath('userId', '17');
});

it('retains legacy authenticated middleware and appends metrics once in every stack', function (): void {
    config()->set('vaulter.routes.middleware.authenticated', ['api']);
    expect(app(RoutesConfig::class)->authenticatedMiddleware())->toBe(['api', RecordVaulterRequestMetrics::class]);
    config()->set('vaulter.routes.middleware.authenticated', null);
    foreach (['authenticated', 'public', 'hub'] as $stack) {
        config()->set('vaulter.routes.middleware.'.$stack, ['api', RecordVaulterRequestMetrics::class]);
    }
    expect(app(RoutesConfig::class)->authenticatedMiddleware())->toBe(['api', RecordVaulterRequestMetrics::class])
        ->and(app(RoutesConfig::class)->publicMiddleware())->toBe(['api', RecordVaulterRequestMetrics::class])
        ->and(app(RoutesConfig::class)->hubMiddleware())->toBe(['api', RecordVaulterRequestMetrics::class]);
});
