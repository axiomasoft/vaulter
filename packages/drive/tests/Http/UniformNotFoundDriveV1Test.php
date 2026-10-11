<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Tests\Http\DriveV1;
use Vaulter\Enums\Drive\DriveStatus;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;
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
    'search' => ['GET', '/search?q=a'],
    'starred' => ['GET', '/starred'],
    'trash.index' => ['GET', '/trash'],
    'trash.empty' => ['DELETE', '/trash'],
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
    $drive = V1::drive(userId: 1);
    $driveId = (string) $drive->model()->getKey();
    $parent = V1::folder($drive, 'hidden');
    $folder = V1::folder($drive, 'visible', $parent->id);
    AccessTestHelpers::grant($folder, RoleRegistry::READER, subjectId: 2);
    $this->actingAs(V1::user(2));

    // A nested grant admits the drive even when this particular list is empty.
    $this->getJson(V1::url('drives/'.$driveId.$suffix))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(V1::url('drives/no-such-drive'.$suffix))->assertNotFound()->assertJsonPath('code', 'drive_not_found');
})->with([
    'nodes.search' => ['/search?q=a'],
    'nodes.starred' => ['/starred'],
    'trash.index' => ['/trash'],
]);

it('admits a drive when the only visible node is a trash root', function (): void {
    $drive = V1::drive(userId: 1);
    $folder = V1::folder($drive, 'shared trash');
    AccessTestHelpers::grant($folder, RoleRegistry::READER, subjectId: 2);
    $drive->nodes()->trash($folder);
    $this->actingAs(V1::user(2));

    $this->getJson(V1::url('drives/'.$drive->model()->getKey().'/trash'))
        ->assertOk()->assertJsonPath('data.0.id', $folder->id);
});

it('keeps foreign trash intact when a stranger tries to empty it', function (): void {
    $drive = V1::drive(userId: 1);
    $folder = V1::folder($drive, 'private trash');
    $drive->nodes()->trash($folder);
    $this->actingAs(V1::user(2));

    $this->deleteJson(V1::url('drives/'.$drive->model()->getKey().'/trash'))
        ->assertNotFound()->assertJsonPath('code', 'drive_not_found');
    expect(DriveTestHelpers::model($folder)->trashed_at)->not->toBeNull();
});

it('hides deleting drives on drive-wide routes', function (string $method, string $suffix): void {
    $drive = V1::drive(userId: 1);
    $model = $drive->model();
    app(VaulterDatabase::class)->write(static fn () => $model->forceFill(['status' => DriveStatus::Deleting])->save());
    $this->actingAs(V1::user(1));

    $this->json($method, V1::url('drives/'.$model->getKey().$suffix))
        ->assertNotFound()->assertJsonPath('code', 'drive_not_found');
})->with([
    ['GET', '/search?q=a'], ['GET', '/starred'], ['GET', '/trash'], ['DELETE', '/trash'],
]);

it('answers the retired unversioned drive paths with route_not_found under the v1 prefix only', function (): void {
    $this->actingAs(V1::user(1));

    $this->getJson(V1::url('storages/1/nodes'))->assertNotFound()->assertJsonPath('code', 'route_not_found');
    $this->getJson('api/vaulter/storages/1/nodes')->assertNotFound()->assertHeaderMissing('X-Vaulter-Api-Version');
});
