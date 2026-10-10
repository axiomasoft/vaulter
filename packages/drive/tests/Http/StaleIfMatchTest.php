<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Drive\Tests\Http\DriveV1;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Http\V1;

/**
 * Lost updates (V36), sequentially on SQLite: two clients that read the same tag cannot
 * both write; the second gets 412 node_sequence_mismatch and its change is not applied.
 * The parallel race on a real server database is a phase-run check.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

it('lets the first of two renames with the same If-Match win and refuses the second with 412', function (): void {
    $drive = V1::drive(userId: 1);
    $node = V1::folder($drive, 'original');
    AccessTestHelpers::grant(DriveTestHelpers::model($drive->root()), RoleRegistry::WRITER, subjectId: 2);

    $this->actingAs(V1::user(1));
    $etagA = DriveV1::etag($this, $node->id);
    $this->actingAs(V1::user(2));
    $etagB = DriveV1::etag($this, $node->id);

    $this->actingAs(V1::user(1));
    $first = $this->patchJson(V1::url('nodes/'.$node->id), ['name' => 'x'], ['If-Match' => $etagA])->assertOk()->assertJsonPath('data.name', 'x');
    expect($first->headers->get('ETag'))->not->toBe($etagA)->toBe($first->json('data.etag'));

    $this->actingAs(V1::user(2));
    $this->patchJson(V1::url('nodes/'.$node->id), ['name' => 'y'], ['If-Match' => $etagB])
        ->assertStatus(412)
        ->assertJsonPath('code', 'node_sequence_mismatch')
        ->assertJsonPath('meta.node_id', $node->id)
        ->assertHeaderMissing('ETag');

    expect(DriveTestHelpers::model($node)->name)->toBe('x');
});

it('refuses a weak tag, since If-Match compares strongly', function (): void {
    $drive = V1::drive(userId: 1);
    $node = V1::folder($drive, 'weak');
    $this->actingAs(V1::user(1));
    $etag = DriveV1::etag($this, $node->id);

    $this->patchJson(V1::url('nodes/'.$node->id), ['name' => 'w'], ['If-Match' => 'W/'.$etag])->assertStatus(412);
    $this->patchJson(V1::url('nodes/'.$node->id), ['name' => 'w'], ['If-Match' => '"other", '.$etag])->assertOk();
});

it('refuses a stale tag on every conditional mutation without changing anything', function (string $route): void {
    $drive = V1::drive(userId: 1);
    $file = V1::file($drive, 'stale.txt', 'one');
    $folder = V1::folder($drive, 'dest');
    $this->actingAs(V1::user(1));
    $stale = DriveV1::etag($this, $file->id);
    $drive->nodes()->rename($file->id, 'moved-on.txt');
    $revision = (string) DriveTestHelpers::model($file)->file()->firstOrFail()->currentRevision()->firstOrFail()->getKey();

    [$method, $path, $body] = match ($route) {
        'nodes.update' => ['PATCH', 'nodes/'.$file->id, ['name' => 'late.txt']],
        'nodes.properties.update' => ['PATCH', 'nodes/'.$file->id.'/properties', ['namespace' => 'acme', 'set' => ['k' => 'v']]],
        'nodes.move' => ['POST', 'nodes/'.$file->id.'/move', ['destination_id' => $folder->id]],
        'nodes.trash' => ['POST', 'nodes/'.$file->id.'/trash', []],
        'files.revisions.store' => ['POST', 'files/'.$file->id.'/revisions', ['file' => UploadedFile::fake()->createWithContent('stale.txt', 'two')]],
        'files.revisions.restore' => ['POST', 'files/'.$file->id.'/revisions/'.$revision.'/restore', []],
        'uploads.store' => ['POST', 'drives/'.$drive->model()->getKey().'/uploads', ['protocol' => 'tus', 'node_id' => $file->id, 'total_size_bytes' => 3]],
    };
    $sequence = DriveTestHelpers::model($file)->sequence;

    $this->json($method, V1::url($path), $body, ['If-Match' => $stale])
        ->assertStatus(412)
        ->assertJsonPath('code', 'node_sequence_mismatch');

    $after = DriveTestHelpers::model($file);
    expect($after->sequence)->toBe($sequence)
        ->and($after->name)->toBe('moved-on.txt')
        ->and($after->trashed_at)->toBeNull()
        ->and($after->file()->firstOrFail()->versions()->count())->toBe(1);
})->with(['nodes.update', 'nodes.properties.update', 'nodes.move', 'nodes.trash', 'files.revisions.store', 'files.revisions.restore', 'uploads.store']);
