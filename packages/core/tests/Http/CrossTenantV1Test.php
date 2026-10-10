<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Tests\Http\V1;
use Vaulter\Tests\Tenancy\MutableTenantResolver;

/**
 * With strict tenancy, a drive or node of another tenant is unknown on every core v1
 * route: the same 404 body as an id that never existed (D38), whatever grants the
 * caller holds over there.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('vaulter.tenancy.enabled', true);
    config()->set('vaulter.tenancy.resolver', MutableTenantResolver::class);
    app()->instance(TenantResolver::class, new MutableTenantResolver);
    config()->set('vaulter.features.share_links', true);
    config()->set('vaulter.features.changes', true);

    // Tenant A owns the drive; user 1 is its owner there.
    $this->foreign = V1::drive(userId: 1);
    $this->foreignNode = V1::folder($this->foreign, 'tenant a');
    $this->foreignDriveId = $this->foreign->model()->getKey();
    app(TenantResolver::class)->tenant = 'B';
    $this->actingAs(V1::user(1));
});

afterEach(fn () => MutableTenantResolver::clearFixtureStamps());

function foreignBody(TestResponse $response): array
{
    $body = $response->json();
    unset($body['request_id']);

    return $body;
}

it('hides another tenant\'s node on every node-scoped route', function (string $method, string $suffix): void {
    $unknown = $this->json($method, V1::url('nodes/no-such-node'.$suffix))->assertNotFound();
    $foreign = $this->json($method, V1::url('nodes/'.$this->foreignNode->id.$suffix))->assertNotFound();

    expect(foreignBody($foreign))->toBe(foreignBody($unknown))
        ->and($foreign->json('code'))->toBe('node_not_found');
})->with([
    'grants.index' => ['GET', '/grants'],
    'grants.destroy' => ['DELETE', '/grants/g1'],
    'inheritance.restore' => ['POST', '/inheritance/restore'],
    'shares.index' => ['GET', '/shares'],
    'shares.rotate' => ['POST', '/shares/s1/rotate'],
    'shares.destroy' => ['DELETE', '/shares/s1'],
    'activity.node' => ['GET', '/activity'],
]);

it('hides another tenant\'s drive on every drive-scoped route', function (string $suffix): void {
    $unknown = $this->getJson(V1::url('drives/no-such-drive'.$suffix))->assertNotFound();
    $foreign = $this->getJson(V1::url('drives/'.$this->foreignDriveId.$suffix))->assertNotFound();

    expect(foreignBody($foreign))->toBe(foreignBody($unknown))
        ->and($foreign->json('code'))->toBe('drive_not_found');
})->with([
    'activity.drive' => ['/activity'],
    'changes.start' => ['/changes/start'],
    'changes.index' => ['/changes?cursor=abc'],
]);
