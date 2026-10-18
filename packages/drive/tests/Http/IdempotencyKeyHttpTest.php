<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Http\V1;

/**
 * Idempotency-Key over v1 (D25, ADR-0029): folders.store and nodes.update read the header — one Structured Field
 * String or one plain token — and a retry with the same key and request returns the stored response with
 * Idempotent-Replayed: true, even when its If-Match went stale because the first request committed. Routes of
 * operations without idempotency ignore the header.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    $this->drive = V1::drive(userId: 1);
    $this->driveId = (string) $this->drive->model()->getKey();
    $this->actingAs(V1::user(1));
});

it('replays a folder creation for the same key and body', function (string $header): void {
    $first = $this->postJson(V1::url('drives/'.$this->driveId.'/folders'), ['name' => 'Docs'], ['Idempotency-Key' => $header])->assertCreated();
    $second = $this->postJson(V1::url('drives/'.$this->driveId.'/folders'), ['name' => 'Docs'], ['Idempotency-Key' => $header])->assertCreated();

    expect($first->headers->has('Idempotent-Replayed'))->toBeFalse()
        ->and($second->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(Node::query()->where('name', 'Docs')->count())->toBe(1);
})->with([
    'structured field string' => ['"8e03978e-40d5-43e8-bc93-6894a57f9324"'],
    'plain token' => ['8e03978e-40d5-43e8-bc93-6894a57f9324'],
]);

it('treats the quoted and the plain spelling of one key as the same key', function (): void {
    $first = $this->postJson(V1::url('drives/'.$this->driveId.'/folders'), ['name' => 'Same'], ['Idempotency-Key' => '"abc"'])->assertCreated();

    $this->postJson(V1::url('drives/'.$this->driveId.'/folders'), ['name' => 'Same'], ['Idempotency-Key' => 'abc'])
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('data.id', $first->json('data.id'));
});

it('refuses a malformed key before anything runs', function (string $header): void {
    $this->postJson(V1::url('drives/'.$this->driveId.'/folders'), ['name' => 'Never'], ['Idempotency-Key' => $header])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['errors' => ['header:Idempotency-Key']]);

    expect(Node::query()->where('name', 'Never')->exists())->toBeFalse();
})->with([
    'list' => ['"a", "b"'],
    'token list' => ['a,b'],
    'space' => ['a b'],
    'unterminated' => ['"abc'],
    'too long' => [str_repeat('k', 256)],
]);

it('answers another body under a used key with idempotency_key_reused', function (): void {
    $this->postJson(V1::url('drives/'.$this->driveId.'/folders'), ['name' => 'One'], ['Idempotency-Key' => 'reuse'])->assertCreated();

    $this->postJson(V1::url('drives/'.$this->driveId.'/folders'), ['name' => 'Two'], ['Idempotency-Key' => 'reuse'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'idempotency_key_reused')
        ->assertJsonPath('retryable', 'never');

    expect(Node::query()->where('name', 'Two')->exists())->toBeFalse();
});

it('replays a rename whose If-Match went stale because the first request committed', function (): void {
    $folder = V1::folder($this->drive, 'Before');
    $ifMatch = V1::ifMatch($folder->id);
    $headers = [...$ifMatch, 'Idempotency-Key' => 'rename-1'];

    $first = $this->patchJson(V1::url('nodes/'.$folder->id), ['name' => 'After'], $headers)->assertOk();
    $second = $this->patchJson(V1::url('nodes/'.$folder->id), ['name' => 'After'], $headers)->assertOk();

    expect($second->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($second->json('data.name'))->toBe('After')
        ->and($second->json('data.id'))->toBe($first->json('data.id'));

    // Without the key the same stale precondition is refused, as before.
    $this->patchJson(V1::url('nodes/'.$folder->id), ['name' => 'After'], $ifMatch)->assertStatus(412);
});

it('stores a stale rename under its key and replays the refusal', function (): void {
    $folder = V1::folder($this->drive, 'Stale');
    $ifMatch = V1::ifMatch($folder->id);
    V1::folder($this->drive, 'Other');
    $this->drive->nodes()->rename($folder->id, 'Moved on');
    $headers = [...$ifMatch, 'Idempotency-Key' => 'stale-1'];

    $this->patchJson(V1::url('nodes/'.$folder->id), ['name' => 'Mine'], $headers)->assertStatus(412)->assertJsonPath('retryable', 'after_refetch');
    $this->patchJson(V1::url('nodes/'.$folder->id), ['name' => 'Mine'], $headers)
        ->assertStatus(412)
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('code', 'node_sequence_mismatch');

    expect(Node::query()->findOrFail($folder->id)->name)->toBe('Moved on');
});

it('replays a cached conflict with the same problem', function (): void {
    V1::folder($this->drive, 'Taken');

    $this->postJson(V1::url('drives/'.$this->driveId.'/folders'), ['name' => 'Taken'], ['Idempotency-Key' => 'conflict'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'node_name_conflict');
    $this->postJson(V1::url('drives/'.$this->driveId.'/folders'), ['name' => 'Taken'], ['Idempotency-Key' => 'conflict'])
        ->assertStatus(409)
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('code', 'node_name_conflict');
});

it('ignores the header on the route of an operation without idempotency', function (): void {
    $folder = V1::folder($this->drive, 'Plain');

    $this->putJson(V1::url('nodes/'.$folder->id.'/star'), [], ['Idempotency-Key' => 'ignored'])->assertOk()->assertHeaderMissing('Idempotent-Replayed');
    $this->putJson(V1::url('nodes/'.$folder->id.'/star'), [], ['Idempotency-Key' => 'ignored'])->assertOk()->assertHeaderMissing('Idempotent-Replayed');
});

it('replays a move whose If-Match went stale and refuses another destination under the key', function (): void {
    $file = V1::file($this->drive, 'a.txt');
    $one = V1::folder($this->drive, 'One');
    $two = V1::folder($this->drive, 'Two');
    $headers = [...V1::ifMatch($file->id), 'Idempotency-Key' => 'move-http'];

    $first = $this->postJson(V1::url('nodes/'.$file->id.'/move'), ['destination_id' => $one->id], $headers)->assertOk();
    $second = $this->postJson(V1::url('nodes/'.$file->id.'/move'), ['destination_id' => $one->id], $headers)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect($second->json('data.parent_id'))->toBe($one->id)->and($second->json('data.sequence'))->toBe($first->json('data.sequence'));
    $this->postJson(V1::url('nodes/'.$file->id.'/move'), ['destination_id' => $two->id], $headers)->assertStatus(422)->assertJsonPath('code', 'idempotency_key_reused');
    expect((string) Node::query()->findOrFail($file->id)->parent_id)->toBe($one->id);
});

it('pins the destination of a move with X-Vaulter-Destination-If-Match', function (): void {
    $file = V1::file($this->drive, 'a.txt');
    $folder = V1::folder($this->drive, 'Target');
    $seen = V1::ifMatch($folder->id)['If-Match'];
    $this->drive->nodes()->rename($folder->id, 'Target renamed');

    $this->postJson(V1::url('nodes/'.$file->id.'/move'), ['destination_id' => $folder->id], [...V1::ifMatch($file->id), 'X-Vaulter-Destination-If-Match' => $seen])
        ->assertStatus(412)
        ->assertJsonPath('code', 'node_sequence_mismatch');
    expect((string) Node::query()->findOrFail($file->id)->parent_id)->not->toBe($folder->id);

    $this->postJson(V1::url('nodes/'.$file->id.'/move'), ['destination_id' => $folder->id], [...V1::ifMatch($file->id), 'X-Vaulter-Destination-If-Match' => V1::ifMatch($folder->id)['If-Match']])->assertOk();
    $root = V1::ifMatch($this->drive->root()->id)['If-Match'];
    $this->postJson(V1::url('nodes/'.$file->id.'/move'), ['destination_id' => null], [...V1::ifMatch($file->id), 'X-Vaulter-Destination-If-Match' => $root])->assertOk();
});

it('replays a copy, a property patch, a trash and a restore', function (): void {
    $file = V1::file($this->drive, 'a.txt');
    $folder = V1::folder($this->drive, 'Into');

    $copy = $this->postJson(V1::url('nodes/'.$file->id.'/copy'), ['destination_id' => $folder->id, 'name' => 'copy.txt'], ['Idempotency-Key' => 'copy-http'])->assertCreated();
    $this->postJson(V1::url('nodes/'.$file->id.'/copy'), ['destination_id' => $folder->id, 'name' => 'copy.txt'], ['Idempotency-Key' => 'copy-http'])
        ->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $copy->json('data.id'));
    expect(Node::query()->where('name', 'copy.txt')->count())->toBe(1);

    $props = [...V1::ifMatch($file->id), 'Idempotency-Key' => 'props-http'];
    $this->patchJson(V1::url('nodes/'.$file->id.'/properties'), ['namespace' => 'acme', 'set' => ['k' => 'v']], $props)->assertOk();
    $this->patchJson(V1::url('nodes/'.$file->id.'/properties'), ['namespace' => 'acme', 'set' => ['k' => 'v']], $props)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    $trash = [...V1::ifMatch($folder->id), 'Idempotency-Key' => 'trash-http'];
    $trashed = $this->postJson(V1::url('nodes/'.$folder->id.'/trash'), [], $trash)->assertOk();
    $this->postJson(V1::url('nodes/'.$folder->id.'/trash'), [], $trash)->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.sequence', $trashed->json('data.sequence'));

    $restore = [...V1::ifMatch($folder->id), 'Idempotency-Key' => 'restore-http'];
    $this->postJson(V1::url('nodes/'.$folder->id.'/restore'), [], $restore)->assertOk();
    $this->postJson(V1::url('nodes/'.$folder->id.'/restore'), [], $restore)->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.trashed_at', null);
});

it('purges once under a key and answers an empty trash with the same count', function (): void {
    $folder = V1::folder($this->drive, 'Doomed');
    $this->drive->nodes()->trash($folder->id);
    $other = V1::folder($this->drive, 'Other');
    $this->drive->nodes()->trash($other->id);

    $this->deleteJson(V1::url('nodes/'.$folder->id), [], [...V1::ifMatch($folder->id), 'Idempotency-Key' => 'purge-http'])->assertNoContent();
    expect(Node::query()->whereKey($folder->id)->exists())->toBeFalse();

    $first = $this->deleteJson(V1::url('drives/'.$this->driveId.'/trash'), [], ['Idempotency-Key' => 'empty-http'])->assertOk();
    $later = V1::folder($this->drive, 'Later');
    $this->drive->nodes()->trash($later->id);
    $second = $this->deleteJson(V1::url('drives/'.$this->driveId.'/trash'), [], ['Idempotency-Key' => 'empty-http'])->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect($first->json('data.purged_count'))->toBe(1)->and($second->json('data.purged_count'))->toBe(1)->and(Node::query()->whereKey($later->id)->exists())->toBeTrue();
});

it('replays a restored and a deleted revision and an upload session', function (): void {
    $file = V1::file($this->drive, 'a.txt', 'one');
    $this->drive->files()->addRevision($file->id, UploadSource::fromStream(DriveTestHelpers::streamFor('two'), 'a.txt'));
    $revisions = DriveTestHelpers::model($file)->file()->firstOrFail()->versions()->orderBy('number')->get();

    $headers = [...V1::ifMatch($file->id), 'Idempotency-Key' => 'rr-http'];
    $first = $this->postJson(V1::url('files/'.$file->id.'/revisions/'.$revisions[0]->getKey().'/restore'), [], $headers)->assertOk();
    $this->postJson(V1::url('files/'.$file->id.'/revisions/'.$revisions[0]->getKey().'/restore'), [], $headers)
        ->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $first->json('data.id'));

    $this->deleteJson(V1::url('files/'.$file->id.'/revisions/'.$revisions[1]->getKey()), [], ['Idempotency-Key' => 'dr-http'])->assertNoContent();
    $this->deleteJson(V1::url('files/'.$file->id.'/revisions/'.$revisions[1]->getKey()), [], ['Idempotency-Key' => 'dr-http'])->assertNoContent()->assertHeader('Idempotent-Replayed', 'true');

    $folder = V1::folder($this->drive, 'Sessions');
    $body = ['protocol' => 'tus', 'parent_id' => $folder->id, 'name' => 'x.bin', 'total_size_bytes' => 3];
    $session = $this->postJson(V1::url('drives/'.$this->driveId.'/uploads'), $body, ['Idempotency-Key' => 'up-http'])->assertCreated();
    $this->postJson(V1::url('drives/'.$this->driveId.'/uploads'), $body, ['Idempotency-Key' => 'up-http'])
        ->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.upload_id', $session->json('data.upload_id'));
    expect(UploadSession::query()->count())->toBe(1);
});

it('replays a rename of a node that is in the trash by now instead of answering 404', function (): void {
    $folder = V1::folder($this->drive, 'Before trash');
    $headers = [...V1::ifMatch($folder->id), 'Idempotency-Key' => 'rename-trashed'];

    $first = $this->patchJson(V1::url('nodes/'.$folder->id), ['name' => 'After trash'], $headers)->assertOk();
    $this->drive->nodes()->trash($folder->id);

    $this->patchJson(V1::url('nodes/'.$folder->id), ['name' => 'After trash'], $headers)
        ->assertOk()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('data.id', $first->json('data.id'));
});
