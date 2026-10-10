<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Enums\Drive\DriveStatus;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;
use Vaulter\Tests\Http\V1;

/**
 * A node or drive the caller may not see answers exactly like one that does not
 * exist: same status, same code, same body except the request id.
 */
uses(RefreshDatabase::class);

function bodyWithoutRequestId(TestResponse $response): array
{
    $body = $response->json();
    unset($body['request_id']);

    return $body;
}

it('answers an unknown node and an invisible node identically on every node-scoped core route', function (string $method, string $suffix, array $body = []): void {
    $drive = V1::drive(userId: 1);
    $hidden = V1::folder($drive, 'hidden');
    $this->actingAs(V1::user(2));

    $unknown = $this->json($method, V1::url('nodes/no-such-node'.$suffix), $body)->assertNotFound();
    $invisible = $this->json($method, V1::url('nodes/'.$hidden->id.$suffix), $body)->assertNotFound();

    expect($invisible->json('code'))->toBe('node_not_found')
        ->and(bodyWithoutRequestId($invisible))->toBe(bodyWithoutRequestId($unknown));
})->with([
    'grants.index' => ['GET', '/grants'],
    'grants.store' => ['POST', '/grants', ['subject' => ['type' => (new TestUser)->getMorphClass(), 'id' => '3'], 'role' => 'reader']],
    'grants.destroy' => ['DELETE', '/grants/g1'],
    'inheritance.restrict' => ['POST', '/inheritance/restrict'],
    'inheritance.restore' => ['POST', '/inheritance/restore'],
    'shares.index' => ['GET', '/shares'],
    'shares.store' => ['POST', '/shares', ['role' => 'reader']],
    'shares.update' => ['PATCH', '/shares/s1'],
    'shares.rotate' => ['POST', '/shares/s1/rotate'],
    'shares.destroy' => ['DELETE', '/shares/s1'],
    'activity.node' => ['GET', '/activity'],
]);

it('answers an unknown drive and a drive whose root is invisible identically', function (string $suffix): void {
    $driveId = V1::drive(userId: 1, role: RoleRegistry::OWNER)->model()->getKey();
    $this->actingAs(V1::user(2));

    $unknown = $this->getJson(V1::url('drives/no-such-drive'.$suffix))->assertNotFound();
    $invisible = $this->getJson(V1::url('drives/'.$driveId.$suffix))->assertNotFound();

    expect($invisible->json('code'))->toBe('drive_not_found')
        ->and(bodyWithoutRequestId($invisible))->toBe(bodyWithoutRequestId($unknown));
})->with([
    'activity.drive' => ['/activity'],
]);

it('hides a node of a drive being deleted', function (): void {
    $drive = V1::drive(userId: 1);
    $node = V1::folder($drive, 'going');
    $model = $drive->model();
    app(VaulterDatabase::class)->write(static fn () => $model->forceFill(['status' => DriveStatus::Deleting])->save());
    $this->actingAs(V1::user(1));

    $this->getJson(V1::url('nodes/'.$node->id.'/grants'))->assertNotFound()->assertJsonPath('code', 'node_not_found');
    $this->getJson(V1::url('drives/'.$model->getKey().'/activity'))->assertNotFound()->assertJsonPath('code', 'drive_not_found');
    expect(DriveTestHelpers::model($node)->exists)->toBeTrue();
});
