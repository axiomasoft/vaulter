<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\Sharing\ShareLinkInput;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Sharing\NodeShare;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Http\V1;

/**
 * Every route that changes an existing node — or an aggregate with a version of its own: the access of a node, a share
 * link, a drive (D47) — refuses to run blind: without If-Match, or with `*` (which matches anything), it answers 428
 * and changes nothing.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-drive.features.grants', true);
    config()->set('vaulter-drive.features.share_links', true);
});

/**
 * The request of one IM route against fresh fixtures of an owner's drive.
 *
 * @return array{0: string, 1: string, 2: array<string, mixed>, 3: string}
 */
function imRequest(string $route, DriveHandle $drive): array
{
    $file = V1::file($drive, 'target.txt', 'first');
    $folder = V1::folder($drive, 'destination');
    $trashed = V1::folder($drive, 'binned');
    $drive->nodes()->trash($trashed->id);
    $revision = (string) DriveTestHelpers::model($file)->file()->firstOrFail()->currentRevision()->firstOrFail()->getKey();
    $grant = (string) AccessTestHelpers::grant(DriveTestHelpers::model($file), RoleRegistry::READER, subjectId: 9)->getKey();
    $share = $drive->shares()->create($file->id, new ShareLinkInput)->link->id;
    $driveId = (string) $drive->model()->getKey();
    $root = $drive->root()->id;

    return match ($route) {
        'nodes.update' => ['PATCH', 'nodes/'.$file->id, ['name' => 'renamed.txt'], $file->id],
        'nodes.properties.update' => ['PATCH', 'nodes/'.$file->id.'/properties', ['namespace' => 'acme', 'set' => ['k' => 'v']], $file->id],
        'nodes.move' => ['POST', 'nodes/'.$file->id.'/move', ['destination_id' => $folder->id], $file->id],
        'nodes.trash' => ['POST', 'nodes/'.$file->id.'/trash', [], $file->id],
        'nodes.restore' => ['POST', 'nodes/'.$trashed->id.'/restore', [], $trashed->id],
        'nodes.purge' => ['DELETE', 'nodes/'.$trashed->id, [], $trashed->id],
        'files.revisions.store' => ['POST', 'files/'.$file->id.'/revisions', ['file' => UploadedFile::fake()->createWithContent('target.txt', 'second')], $file->id],
        'files.revisions.restore' => ['POST', 'files/'.$file->id.'/revisions/'.$revision.'/restore', [], $file->id],
        'uploads.store' => ['POST', 'drives/'.$drive->model()->getKey().'/uploads', ['protocol' => 'tus', 'node_id' => $file->id, 'total_size_bytes' => 3], $file->id],
        // The aggregates with a version of their own (D47): the node's access, a share link, the drive.
        'grants.destroy' => ['DELETE', 'nodes/'.$file->id.'/grants/'.$grant, [], $file->id],
        'access.inheritance.restrict' => ['POST', 'nodes/'.$folder->id.'/inheritance/restrict', [], $folder->id],
        'access.inheritance.restore' => ['POST', 'nodes/'.$folder->id.'/inheritance/restore', [], $folder->id],
        'shares.update' => ['PATCH', 'nodes/'.$file->id.'/shares/'.$share, ['max_uses' => 4], $file->id],
        'shares.rotate' => ['POST', 'nodes/'.$file->id.'/shares/'.$share.'/rotate', [], $file->id],
        'shares.destroy' => ['DELETE', 'nodes/'.$file->id.'/shares/'.$share, [], $file->id],
        'drives.archive' => ['POST', 'drives/'.$driveId.'/archive', [], $root],
        'drives.unarchive' => ['POST', 'drives/'.$driveId.'/unarchive', [], $root],
        'drives.destroy' => ['DELETE', 'drives/'.$driveId, [], $root],
    };
}

/**
 * The versions a refused write must leave alone: the node's access version, its links' and its drive's.
 *
 * @return array{0: int, 1: list<int>, 2: int}
 */
function imVersions(string $nodeId): array
{
    $node = DriveTestHelpers::model($nodeId);

    return [$node->access_version, NodeShare::query()->where('node_id', $node->getKey())->orderBy('id')->pluck('version')->map(static fn (mixed $version): int => (int) $version)->all(), (int) Drive::query()->whereKey($node->drive_id)->value('version')];
}

dataset('im routes', [
    'nodes.update', 'nodes.properties.update', 'nodes.move', 'nodes.trash', 'nodes.restore', 'nodes.purge', 'files.revisions.store', 'files.revisions.restore', 'uploads.store',
    'grants.destroy', 'access.inheritance.restrict', 'access.inheritance.restore', 'shares.update', 'shares.rotate', 'shares.destroy', 'drives.archive', 'drives.unarchive', 'drives.destroy',
]);

it('answers 428 without If-Match and leaves the node as it was', function (string $route): void {
    $drive = V1::drive(userId: 1);
    [$method, $path, $body, $target] = imRequest($route, $drive);
    $before = DriveTestHelpers::model($target)->sequence;
    $versions = imVersions($target);
    $this->actingAs(V1::user(1));

    $this->json($method, V1::url($path), $body)
        ->assertStatus(428)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'precondition_required')
        ->assertJsonPath('meta.header', 'If-Match');

    expect(DriveTestHelpers::model($target)->sequence)->toBe($before)
        ->and(imVersions($target))->toBe($versions);
})->with('im routes');

it('answers 428 to If-Match: * because it would match any representation', function (string $route): void {
    $drive = V1::drive(userId: 1);
    [$method, $path, $body] = imRequest($route, $drive);
    $this->actingAs(V1::user(1));

    $this->json($method, V1::url($path), $body, ['If-Match' => '*'])
        ->assertStatus(428)
        ->assertJsonPath('code', 'precondition_required')
        ->assertJsonPath('meta.reason', 'wildcard_not_allowed');
})->with('im routes');

it('answers a caller who cannot see the node 404, not 428', function (string $route): void {
    $drive = V1::drive(userId: 1);
    [$method, $path, $body] = imRequest($route, $drive);
    $this->actingAs(V1::user(2));

    $this->json($method, V1::url($path), $body)->assertNotFound();
})->with('im routes');

it('answers a caller who may view but not change the node 403 before any precondition', function (string $route): void {
    $drive = V1::drive(userId: 1);
    [$method, $path, $body, $target] = imRequest($route, $drive);
    AccessTestHelpers::grant(DriveTestHelpers::model($drive->root()), RoleRegistry::READER, subjectId: 2);
    $before = DriveTestHelpers::model($target)->sequence;
    $this->actingAs(V1::user(2));

    $this->json($method, V1::url($path), $body)->assertForbidden()->assertJsonPath('code', 'permission_denied');
    $this->json($method, V1::url($path), $body, ['If-Match' => '"stale"'])->assertForbidden();

    expect(DriveTestHelpers::model($target)->sequence)->toBe($before);
})->with('im routes');
