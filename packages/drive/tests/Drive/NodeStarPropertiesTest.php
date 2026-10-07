<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Exceptions\Nodes\InvalidPropertiesException;
use Vaulter\Exceptions\Nodes\StaleNodeException;
use Vaulter\Models\Drive\NodeUserState;
use Vaulter\Tests\Drive\DriveTestHelpers;

beforeEach(fn () => Storage::fake('local'));

it('keeps stars per user, without touching the node sequence or timestamps', function (): void {
    $drive = DriveTestHelpers::drive();
    $alice = $drive->actingAs(DriveTestHelpers::actor($drive, 7));
    $bob = $drive->actingAs(DriveTestHelpers::actor($drive, 8));
    $folder = $drive->folders()->create('Projects');
    $state = static fn (): array => [$folder->refresh()->sequence, $folder->getRawOriginal('updated_at')];
    $before = $state();

    $alice->nodes()->star($folder);
    $alice->nodes()->star($folder);

    expect($state())->toBe($before)
        ->and(NodeUserState::query()->count())->toBe(1)
        ->and(array_map(static fn ($node) => $node->id, $alice->nodes()->starred()->items))->toBe([$folder->id])
        ->and($bob->nodes()->starred()->items)->toBe([]);

    $alice->nodes()->unstar($folder);
    expect($alice->nodes()->starred()->items)->toBe([]);
});

it('lists the newest star first, pages by cursor and hides trashed nodes', function (): void {
    $drive = DriveTestHelpers::drive();
    $alice = $drive->actingAs(DriveTestHelpers::actor($drive, 7));
    $first = $drive->folders()->create('first');
    $second = $drive->folders()->create('second');
    $third = $drive->folders()->create('third');
    foreach ([$first, $second, $third] as $offset => $node) {
        $this->travelTo(now()->addMinutes($offset + 1));
        $alice->nodes()->star($node);
    }
    $drive->nodes()->trash($second);

    $page = $alice->nodes()->starred(new ListOptions(perPage: 1));
    $next = $alice->nodes()->starred((new ListOptions(perPage: 1))->cursor($page->nextCursor));

    expect($page->items[0]->id)->toBe($third->id)
        ->and($next->items[0]->id)->toBe($first->id)
        ->and(fn () => $alice->nodes()->starred(ListOptions::make()->descending(true)))->toThrow(InvalidArgumentException::class);
});

it('refuses a star from the system actor, which has no person behind it', function (): void {
    $drive = DriveTestHelpers::drive();

    expect(fn () => $drive->nodes()->star($drive->folders()->create('x')))->toThrow(InvalidArgumentException::class)
        ->and($drive->nodes()->starred()->items)->toBe([]);
});

it('patches one properties namespace atomically and bumps the sequence only on a change', function (): void {
    $drive = DriveTestHelpers::drive();
    $user = $drive->actingAs(DriveTestHelpers::actor($drive, 7));
    $file = $drive->files()->upload(UploadSource::fromString('x', 'a.txt'));

    $patched = $user->nodes()->setProperties($file, 'acme.crm', ['deal' => '42', 'stage' => 'won']);
    expect($patched->sequence)->toBe(1)
        ->and($user->nodes()->properties($file))->toBe(['acme.crm' => ['deal' => '42', 'stage' => 'won']]);

    $same = $user->nodes()->setProperties($file, 'acme.crm', ['deal' => '42'], [], expectedSequence: 1);
    expect($same->sequence)->toBe(1);

    $user->nodes()->setProperties($file, 'acme.crm', [], ['deal', 'stage'], expectedSequence: 1);
    expect($user->nodes()->properties($file))->toBe([])
        ->and(fn () => $user->nodes()->setProperties($file, 'acme', ['k' => 'v'], [], expectedSequence: 1))->toThrow(StaleNodeException::class);
});

it('reserves the vaulter namespaces for the system', function (): void {
    $drive = DriveTestHelpers::drive();
    $user = $drive->actingAs(DriveTestHelpers::actor($drive, 7));
    $folder = $drive->folders()->create('x');

    expect(fn () => $user->nodes()->setProperties($folder, 'vaulter.sync', ['k' => 'v']))->toThrow(InvalidPropertiesException::class)
        ->and($drive->nodes()->setProperties($folder, 'vaulter.sync', ['k' => 'v'])->properties)->toBe(['vaulter.sync' => ['k' => 'v']]);
});

it('stamps content times on upload and keeps the client time informational', function (): void {
    $this->travelTo(now()->startOfSecond());
    $drive = DriveTestHelpers::drive();
    $user = $drive->actingAs(DriveTestHelpers::actor($drive, 7));
    $client = new DateTimeImmutable('2020-05-06 07:08:09');

    $file = $user->files()->upload(UploadSource::fromString('x', 'a.txt'), UploadOptions::make()->clientModifiedAt($client));
    $revision = $file->file()->firstOrFail()->currentRevision()->firstOrFail();

    expect($file->refresh()->content_modified_at?->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and((string) $file->content_modified_by)->toBe('7')
        ->and((string) $revision->getRawOriginal('client_modified_at'))->toBe('2020-05-06 07:08:09');

    $this->travel(5)->minutes();
    $drive->nodes()->rename($file, 'b.txt');
    expect($file->refresh()->content_modified_at?->toDateTimeString())->not->toBe(now()->toDateTimeString());
});

it('answers viewer.starred for the request user on a node and its listed children', function (): void {
    $drive = DriveTestHelpers::drive();
    $user = DriveTestHelpers::actor($drive, 7);
    $folder = $drive->folders()->create('Docs');
    $starred = $drive->folders()->create('starred', $folder);
    $drive->folders()->create('plain', $folder);
    $drive->actingAs($user)->nodes()->star($starred);
    $drive->actingAs($user)->nodes()->star($folder);
    $this->actingAs($user);

    $response = $this->getJson('api/vaulter/nodes/'.$folder->getKey())->assertOk()
        ->assertJsonPath('data.viewer.starred', true)
        ->assertJsonMissingPath('data.is_pinned')
        ->assertJsonMissingPath('data.position');
    $children = collect($response->json('data.children'))->mapWithKeys(fn (array $child): array => [$child['name'] => $child['viewer']['starred']]);
    expect($children->all())->toBe(['plain' => false, 'starred' => true]);

    $this->getJson('api/vaulter/storages/'.$drive->model()->getKey().'/nodes?parent_id='.$folder->getKey())->assertOk()
        ->assertJsonPath('data.0.name', 'plain')->assertJsonPath('data.0.viewer.starred', false)
        ->assertJsonPath('data.1.name', 'starred')->assertJsonPath('data.1.viewer.starred', true);
});
