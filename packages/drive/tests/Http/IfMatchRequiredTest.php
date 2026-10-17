<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Http\V1;

/**
 * Every route that changes an existing node refuses to run blind: without If-Match, or
 * with `*` (which matches anything), it answers 428 and changes nothing.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
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
    };
}

dataset('im routes', ['nodes.update', 'nodes.properties.update', 'nodes.move', 'nodes.trash', 'nodes.restore', 'nodes.purge', 'files.revisions.store', 'files.revisions.restore', 'uploads.store']);

it('answers 428 without If-Match and leaves the node as it was', function (string $route): void {
    $drive = V1::drive(userId: 1);
    [$method, $path, $body, $target] = imRequest($route, $drive);
    $before = DriveTestHelpers::model($target)->sequence;
    $this->actingAs(V1::user(1));

    $this->json($method, V1::url($path), $body)
        ->assertStatus(428)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'precondition_required')
        ->assertJsonPath('meta.header', 'If-Match');

    expect(DriveTestHelpers::model($target)->sequence)->toBe($before);
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
