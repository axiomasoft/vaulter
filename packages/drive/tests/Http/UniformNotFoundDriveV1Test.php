<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Vaulter\Drive\Tests\Http\DriveV1;
use Vaulter\Tests\Http\V1;

/**
 * A node or drive the caller may not see answers exactly like one that does not exist,
 * on every drive route: same status, same code, same body except the request id.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

it('answers an unknown node and an invisible node identically', function (string $method, string $prefix, string $suffix, array $body = []): void {
    $drive = V1::drive(userId: 1);
    $hidden = V1::file($drive, 'hidden.txt');
    $this->actingAs(V1::user(2));

    $unknown = $this->json($method, V1::url($prefix.'no-such-node'.$suffix), $body)->assertNotFound();
    $invisible = $this->json($method, V1::url($prefix.$hidden->id.$suffix), $body)->assertNotFound();

    expect($invisible->json('code'))->toBe('node_not_found')
        ->and(DriveV1::problem($invisible))->toBe(DriveV1::problem($unknown));
})->with([
    'nodes.show' => ['GET', 'nodes/', ''],
    'nodes.update' => ['PATCH', 'nodes/', '', ['name' => 'x']],
    'nodes.properties.update' => ['PATCH', 'nodes/', '/properties', ['namespace' => 'acme']],
    'nodes.star' => ['PUT', 'nodes/', '/star'],
    'nodes.unstar' => ['DELETE', 'nodes/', '/star'],
    'nodes.move' => ['POST', 'nodes/', '/move', ['destination_id' => null]],
    'nodes.copy' => ['POST', 'nodes/', '/copy'],
    'nodes.trash' => ['POST', 'nodes/', '/trash'],
    'nodes.restore' => ['POST', 'nodes/', '/restore'],
    'nodes.purge' => ['DELETE', 'nodes/', ''],
    'nodes.abilities' => ['GET', 'nodes/', '/abilities'],
    'files.download' => ['GET', 'files/', '/download'],
    'files.revisions.index' => ['GET', 'files/', '/revisions'],
    'files.revisions.restore' => ['POST', 'files/', '/revisions/r1/restore'],
    'files.revisions.update' => ['PATCH', 'files/', '/revisions/r1', ['keep_forever' => true]],
    'files.revisions.destroy' => ['DELETE', 'files/', '/revisions/r1'],
    'files.revisions.download' => ['GET', 'files/', '/revisions/r1/download'],
]);

it('answers an unknown drive and a drive whose root is invisible identically', function (string $method, string $suffix): void {
    $driveId = (string) V1::drive(userId: 1)->model()->getKey();
    $this->actingAs(V1::user(2));

    $unknown = $this->json($method, V1::url('drives/no-such-drive'.$suffix))->assertNotFound();
    $invisible = $this->json($method, V1::url('drives/'.$driveId.$suffix))->assertNotFound();

    expect($invisible->json('code'))->toBe('drive_not_found')
        ->and(DriveV1::problem($invisible))->toBe(DriveV1::problem($unknown));
})->with([
    'drives.show' => ['GET', ''],
    'drives.archive' => ['POST', '/archive'],
    'drives.unarchive' => ['POST', '/unarchive'],
    'drives.destroy' => ['DELETE', ''],
    'nodes.index' => ['GET', '/nodes'],
]);

it('answers a child id under an invisible drive like an unknown drive', function (string $method, string $suffix, array $body): void {
    $driveId = (string) V1::drive(userId: 1)->model()->getKey();
    $this->actingAs(V1::user(2));

    $unknown = $this->json($method, V1::url('drives/no-such-drive'.$suffix), $body)->assertNotFound();
    $invisible = $this->json($method, V1::url('drives/'.$driveId.$suffix), $body)->assertNotFound();

    expect($invisible->json('code'))->toBe('drive_not_found')
        ->and(DriveV1::problem($invisible))->toBe(DriveV1::problem($unknown));
})->with([
    'nodes.index?parent' => ['GET', '/nodes?parent=no-such', []],
    'folders.store parent_id' => ['POST', '/folders', ['name' => 'x', 'parent_id' => 'no-such']],
    'uploads.store parent_id' => ['POST', '/uploads', ['name' => 'x.bin', 'total_size_bytes' => 1, 'parent_id' => 'no-such']],
    'uploads.store node_id' => ['POST', '/uploads', ['total_size_bytes' => 1, 'node_id' => 'no-such']],
]);

it('keeps node_not_found for a missing child of a drive the caller can see', function (): void {
    $driveId = (string) V1::drive(userId: 1)->model()->getKey();
    $this->actingAs(V1::user(1));

    $this->getJson(V1::url('drives/'.$driveId.'/nodes?parent=no-such'))->assertNotFound()->assertJsonPath('code', 'node_not_found');
});

it('filters the drive-wide lists down to what the caller can see', function (string $suffix): void {
    $driveId = (string) V1::drive(userId: 1)->model()->getKey();
    $this->actingAs(V1::user(2));

    // A caller granted only a folder deep inside may search or list the trash of the
    // drive; a stranger gets an empty page, an unknown drive id 404.
    $this->getJson(V1::url('drives/'.$driveId.$suffix))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(V1::url('drives/no-such-drive'.$suffix))->assertNotFound()->assertJsonPath('code', 'drive_not_found');
})->with([
    'nodes.search' => ['/search?q=a'],
    'nodes.starred' => ['/starred'],
    'trash.index' => ['/trash'],
]);

it('answers the retired unversioned drive paths with route_not_found under the v1 prefix only', function (): void {
    $this->actingAs(V1::user(1));

    $this->getJson(V1::url('storages/1/nodes'))->assertNotFound()->assertJsonPath('code', 'route_not_found');
    $this->getJson('api/vaulter/storages/1/nodes')->assertNotFound()->assertHeaderMissing('X-Vaulter-Api-Version');
});
