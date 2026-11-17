<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Vaulter\Drive\Testing\Http\V1;
use Vaulter\Drive\Tests\Http\DriveV1;

/**
 * The cache policy of the drive routes: a single node with its validator is personal and
 * revalidated; lists, mutations, bytes and errors are never stored.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    $this->drive = V1::drive(userId: 1);
    $this->driveId = (string) $this->drive->model()->getKey();
    $this->actingAs(V1::user(1));
});

it('marks a single node private, no-cache and varying on credentials', function (): void {
    $node = V1::folder($this->drive, 'one');

    $response = $this->getJson(V1::url('nodes/'.$node->id))->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-cache')->not->toContain('public')
        ->and($response->getVary())->toContain('Authorization', 'Cookie');
});

it('never stores lists, drives, mutations, downloads or errors', function (): void {
    $file = V1::file($this->drive, 'c.txt');
    $responses = [
        'nodes.index' => $this->getJson(V1::url('drives/'.$this->driveId.'/nodes')),
        'trash.index' => $this->getJson(V1::url('drives/'.$this->driveId.'/trash')),
        'drives.show' => $this->getJson(V1::url('drives/'.$this->driveId)),
        'nodes.update' => $this->patchJson(V1::url('nodes/'.$file->id), ['name' => 'd.txt'], ['If-Match' => DriveV1::etag($this, $file->id)]),
        'files.download' => $this->get(V1::url('files/'.$file->id.'/download')),
        'nodes.update 428' => $this->patchJson(V1::url('nodes/'.$file->id), ['name' => 'e.txt']),
        'nodes.show 404' => $this->getJson(V1::url('nodes/no-such-node')),
    ];

    foreach ($responses as $response) {
        expect($response->headers->get('Cache-Control'))->toContain('no-store')->not->toContain('public');
    }
    expect($responses['nodes.update']->headers->get('Cache-Control'))->not->toContain('no-cache');
});
