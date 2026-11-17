<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Http\V1;
use Vaulter\Idempotency\IdempotencyStore;

/**
 * Idempotency-Key over v1 (ADR-0029): folders.store and nodes.update read the header — one Structured Field
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

it('gives a replayed folder creation the same reader key as the first answer and as a plain read', function (): void {
    $first = $this->postJson(V1::url('drives/'.$this->driveId.'/folders'), ['name' => 'Keyed'], ['Idempotency-Key' => 'key-replay-1'])->assertCreated();
    $second = $this->postJson(V1::url('drives/'.$this->driveId.'/folders'), ['name' => 'Keyed'], ['Idempotency-Key' => 'key-replay-1'])->assertCreated();
    $read = $this->getJson(V1::url('nodes/'.$first->json('data.id')))->assertOk();

    expect($first->json('data.sync_key'))->toBeString()->toHaveLength(43)
        ->and($second->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($second->json('data.sync_key'))->toBe($first->json('data.sync_key'))
        ->and($read->json('data.sync_key'))->toBe($first->json('data.sync_key'));
});

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

it('replays a move pinned to its destination after the destination changed, and refuses a new key on the stale tag', function (): void {
    $file = V1::file($this->drive, 'a.txt');
    $folder = V1::folder($this->drive, 'Target');
    $headers = [...V1::ifMatch($file->id), 'X-Vaulter-Destination-If-Match' => V1::ifMatch($folder->id)['If-Match'], 'Idempotency-Key' => 'move-pinned'];

    $first = $this->postJson(V1::url('nodes/'.$file->id.'/move'), ['destination_id' => $folder->id], $headers)->assertOk();
    $this->drive->nodes()->rename($folder->id, 'Target renamed');

    // The same request, headers unchanged: answered from the receipt, not refused by the destination tag.
    $this->postJson(V1::url('nodes/'.$file->id.'/move'), ['destination_id' => $folder->id], $headers)
        ->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.sequence', $first->json('data.sequence'));
    // The destination tag is part of the request: another one under the same key is another request.
    $this->postJson(V1::url('nodes/'.$file->id.'/move'), ['destination_id' => $folder->id], [...$headers, 'X-Vaulter-Destination-If-Match' => V1::ifMatch($folder->id)['If-Match']])
        ->assertStatus(422)->assertJsonPath('code', 'idempotency_key_reused');

    $other = V1::file($this->drive, 'b.txt');
    $this->postJson(V1::url('nodes/'.$other->id.'/move'), ['destination_id' => $folder->id], [...V1::ifMatch($other->id), 'X-Vaulter-Destination-If-Match' => $headers['X-Vaulter-Destination-If-Match'], 'Idempotency-Key' => 'move-pinned-new'])
        ->assertStatus(412)->assertJsonPath('code', 'node_sequence_mismatch');
    expect((string) Node::query()->findOrFail($other->id)->parent_id)->not->toBe($folder->id);
});

it('replays a purge over HTTP although the node is gone, and only for the same caller, key and request', function (): void {
    $folder = V1::folder($this->drive, 'Gone');
    $this->drive->nodes()->trash($folder->id);
    $headers = [...V1::ifMatch($folder->id), 'Idempotency-Key' => 'purge-replay'];

    $this->deleteJson(V1::url('nodes/'.$folder->id), [], $headers)->assertNoContent()->assertHeaderMissing('Idempotent-Replayed');
    expect(Node::query()->whereKey($folder->id)->exists())->toBeFalse();

    $this->deleteJson(V1::url('nodes/'.$folder->id), [], $headers)->assertNoContent()->assertHeader('Idempotent-Replayed', 'true');
    $this->deleteJson(V1::url('nodes/'.$folder->id), [], [...$headers, 'Idempotency-Key' => 'purge-other'])->assertNotFound();
    $this->deleteJson(V1::url('nodes/'.$folder->id), [], ['If-Match' => $headers['If-Match']])->assertNotFound();
    $this->actingAs(V1::user(2));
    $this->deleteJson(V1::url('nodes/'.$folder->id), [], $headers)->assertNotFound();
});

it('refuses a new-revision upload session on a stale If-Match under a new key, and replays the first one', function (): void {
    $file = V1::file($this->drive, 'a.txt', 'one');
    $tag = V1::ifMatch($file->id)['If-Match'];
    $body = ['protocol' => 'tus', 'node_id' => $file->id, 'total_size_bytes' => 3];

    $first = $this->postJson(V1::url('drives/'.$this->driveId.'/uploads'), $body, ['If-Match' => $tag, 'Idempotency-Key' => 'rev-1'])->assertCreated();
    $this->drive->files()->addRevision($file->id, UploadSource::fromStream(DriveTestHelpers::streamFor('two'), 'a.txt'));

    // The retry of the first request is answered from its receipt although its tag is stale now.
    $this->postJson(V1::url('drives/'.$this->driveId.'/uploads'), $body, ['If-Match' => $tag, 'Idempotency-Key' => 'rev-1'])
        ->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.upload_id', $first->json('data.upload_id'));
    // A new key does not turn the stale tag into a new session; the refusal is stored under that key.
    $this->postJson(V1::url('drives/'.$this->driveId.'/uploads'), $body, ['If-Match' => $tag, 'Idempotency-Key' => 'rev-2'])
        ->assertStatus(412)->assertJsonPath('code', 'node_sequence_mismatch');
    $this->postJson(V1::url('drives/'.$this->driveId.'/uploads'), $body, ['If-Match' => $tag, 'Idempotency-Key' => 'rev-2'])
        ->assertStatus(412)->assertHeader('Idempotent-Replayed', 'true');
    expect(UploadSession::query()->count())->toBe(1);

    // A matching tag still opens a session, and the commit does not compare it again (no persisted expectation).
    $fresh = $this->postJson(V1::url('drives/'.$this->driveId.'/uploads'), $body, ['If-Match' => V1::ifMatch($file->id)['If-Match'], 'Idempotency-Key' => 'rev-3'])->assertCreated();
    expect(UploadSession::query()->where('upload_id', $fresh->json('data.upload_id'))->firstOrFail()->metadata['expected_sequence'] ?? null)->toBeNull();
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

it('answers a repeated direct upload by its precondition: one file, 409 node_name_conflict, the key ignored', function (): void {
    $folder = V1::folder($this->drive, 'direct');
    $request = fn (): array => ['parent_id' => $folder->id, 'file' => UploadedFile::fake()->createWithContent('once.txt', 'same bytes')];

    $this->post(V1::url('drives/'.$this->driveId.'/files'), $request(), ['Accept' => 'application/json', 'Idempotency-Key' => 'direct-1'])->assertCreated();
    $this->post(V1::url('drives/'.$this->driveId.'/files'), $request(), ['Accept' => 'application/json', 'Idempotency-Key' => 'direct-1'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'node_name_conflict')
        ->assertHeaderMissing('Idempotent-Replayed');

    expect(Node::query()->where('name', 'once.txt')->count())->toBe(1);
});

it('answers a repeated direct revision by its If-Match: one new revision, then 412', function (): void {
    $file = V1::file($this->drive, 'rev.txt', 'one');
    $headers = ['Accept' => 'application/json', ...V1::ifMatch($file->id)];
    $request = fn (): array => ['file' => UploadedFile::fake()->createWithContent('rev.txt', 'two')];

    $this->post(V1::url('files/'.$file->id.'/revisions'), $request(), $headers)->assertCreated()->assertJsonPath('data.number', 2);
    $this->post(V1::url('files/'.$file->id.'/revisions'), $request(), $headers)->assertStatus(412)->assertJsonPath('code', 'node_sequence_mismatch');

    expect(DriveTestHelpers::model($file)->file()->firstOrFail()->versions()->count())->toBe(2);
});

/** The raw receipt rows of the idempotency table, by key as stored (base64). */
function ikReceipt(string $key): stdClass
{
    /** @var stdClass $row */
    $row = app(VaulterDatabase::class)->connection()->table(app(DatabaseOptions::class)->table(IdempotencyStore::ENTITY))
        ->where('idempotency_key', base64_encode($key))->firstOrFail();

    return $row;
}

it('replays a restored inheritance and an unarchive under their keys although their tags went stale, and answers a retried drive deletion drive_not_found', function (): void {
    config()->set('vaulter-drive.features.grants', true);
    $folder = V1::folder($this->drive, 'Inherits');
    AccessTestHelpers::grant(DriveTestHelpers::model($folder), RoleRegistry::OWNER, subjectId: 1);
    $access = fn (): array => ['If-Match' => (string) $this->getJson(V1::url('nodes/'.$folder->id.'/grants'))->assertOk()->json('etag')];
    $this->postJson(V1::url('nodes/'.$folder->id.'/inheritance/restrict'), [], $access())->assertOk();
    $restore = [...$access(), 'Idempotency-Key' => 'restore-inheritance-1'];
    $first = $this->postJson(V1::url('nodes/'.$folder->id.'/inheritance/restore'), [], $restore)->assertOk()->assertJsonPath('data.inherits_access', true);
    $this->postJson(V1::url('nodes/'.$folder->id.'/inheritance/restore'), [], $restore)
        ->assertOk()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('data.etag', $first->json('data.etag'));
    expect(DriveTestHelpers::model($folder)->access_version)->toBe(2);

    $tag = fn (): array => ['If-Match' => (string) $this->getJson(V1::url('drives/'.$this->driveId))->assertOk()->json('data.etag')];
    $this->postJson(V1::url('drives/'.$this->driveId.'/archive'), [], $tag())->assertOk();
    $unarchive = [...$tag(), 'Idempotency-Key' => 'unarchive-1'];
    $this->postJson(V1::url('drives/'.$this->driveId.'/unarchive'), [], $unarchive)->assertOk()->assertJsonPath('data.version', 2);
    $this->postJson(V1::url('drives/'.$this->driveId.'/unarchive'), [], $unarchive)
        ->assertOk()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('data.version', 2);

    $delete = [...$tag(), 'Idempotency-Key' => 'delete-drive-1'];
    $this->deleteJson(V1::url('drives/'.$this->driveId), [], $delete)->assertOk()->assertJsonPath('data.status', 'deleting');
    // A deleting drive is absent before any operation runs (DriveReplayAuthorizer): the retry of the committed delete
    // answers drive_not_found like any request to it, never a second deletion.
    $this->deleteJson(V1::url('drives/'.$this->driveId), [], $delete)->assertNotFound()->assertJsonPath('code', 'drive_not_found');
    expect(Drive::query()->withoutGlobalScopes()->whereKey($this->driveId)->exists())->toBeFalse();
});

it('stores the semantic snapshot of a keyed rename in its receipt: no representation tag, abilities or sync key', function (): void {
    $folder = V1::folder($this->drive, 'Before');
    $sequence = DriveTestHelpers::model($folder)->sequence;
    $response = $this->patchJson(V1::url('nodes/'.$folder->id), ['name' => 'After'], [...V1::ifMatch($folder->id), 'Idempotency-Key' => 'raw-1'])->assertOk();

    $row = ikReceipt('raw-1');
    $body = json_decode((string) $row->receipt_body, true, flags: JSON_THROW_ON_ERROR);

    expect($row->receipt_kind)->toBe('drive.node-snapshot')
        ->and($row->receipt_headers)->toBeNull()
        ->and($body['id'])->toBe($folder->id)
        ->and($body['name'])->toBe('After')
        ->and($body['sequence'])->toBe($sequence + 1)
        ->and(array_keys($body))->not->toContain('etag', 'abilities', 'sync_key', 'links')
        ->and((string) $row->receipt_body)->not->toContain((string) $response->headers->get('ETag'))
        ->and((string) $row->receipt_body)->not->toContain('sync_key');
});

it('replays the historical snapshot after a later change, refuses a changed request and a revoked actor without the stored name', function (): void {
    $folder = V1::folder($this->drive, 'Shared');
    AccessTestHelpers::grant(DriveTestHelpers::model($folder), RoleRegistry::WRITER, subjectId: 2);
    $this->actingAs(V1::user(2));
    $sequence = DriveTestHelpers::model($folder)->sequence;
    $headers = [...V1::ifMatch($folder->id), 'Idempotency-Key' => 'canary-1'];
    $first = $this->patchJson(V1::url('nodes/'.$folder->id), ['name' => 'canary-name'], $headers)->assertOk();

    // A later change moves the live node on: the replay answers the committed result, not a reread.
    $this->patchJson(V1::url('nodes/'.$folder->id), ['name' => 'Later'], V1::ifMatch($folder->id))->assertOk();
    $replay = $this->patchJson(V1::url('nodes/'.$folder->id), ['name' => 'canary-name'], $headers)
        ->assertOk()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('data.name', 'canary-name')
        ->assertJsonPath('data.etag', $first->json('data.etag'));
    expect((string) $replay->headers->get('ETag'))->toStartWith('"'.$folder->id.'.'.($sequence + 1).'.');

    $changed = $this->patchJson(V1::url('nodes/'.$folder->id), ['name' => 'other-name'], $headers)->assertStatus(422)->assertJsonPath('code', 'idempotency_key_reused');
    expect((string) $changed->getContent())->not->toContain('canary');

    NodeGrant::query()->where('node_id', $folder->id)->where('subject_id', '2')->delete();
    $denied = $this->patchJson(V1::url('nodes/'.$folder->id), ['name' => 'canary-name'], $headers)->assertNotFound();
    expect((string) $denied->getContent())->not->toContain('canary')
        ->and(Node::query()->findOrFail($folder->id)->name)->toBe('Later');
});

it('replays a keyed rename after a key generation bump with the old name and the new key and tag, and refuses a revoked actor before any key', function (): void {
    $folder = V1::folder($this->drive, 'Rotating');
    AccessTestHelpers::grant(DriveTestHelpers::model($folder), RoleRegistry::WRITER, subjectId: 2);
    $this->actingAs(V1::user(2));
    $headers = [...V1::ifMatch($folder->id), 'Idempotency-Key' => 'rotate-1'];
    $first = $this->patchJson(V1::url('nodes/'.$folder->id), ['name' => 'Rotated-name'], $headers)->assertOk();
    $stored = (string) ikReceipt('rotate-1')->receipt_body;

    config()->set('vaulter-drive.changes.key_generation', 2);
    $replay = $this->patchJson(V1::url('nodes/'.$folder->id), ['name' => 'Rotated-name'], $headers)
        ->assertOk()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('data.name', 'Rotated-name');
    $read = $this->getJson(V1::url('nodes/'.$folder->id))->assertOk();

    expect($replay->json('data.sync_key'))->toHaveLength(43)->not->toBe($first->json('data.sync_key'))
        ->and($replay->json('data.etag'))->not->toBe($first->json('data.etag'))
        ->and($replay->json('data.sync_key'))->toBe($read->json('data.sync_key'))
        ->and($replay->headers->get('ETag'))->toBe($replay->json('data.etag'))
        ->and((string) ikReceipt('rotate-1')->receipt_body)->toBe($stored)
        ->and($stored)->not->toContain((string) $replay->json('data.sync_key'))->not->toContain((string) $first->json('data.sync_key'));

    NodeGrant::query()->where('node_id', $folder->id)->where('subject_id', '2')->delete();
    $denied = $this->patchJson(V1::url('nodes/'.$folder->id), ['name' => 'Rotated-name'], $headers)->assertNotFound();
    expect((string) $denied->getContent())->not->toContain('sync_key')->not->toContain((string) $replay->json('data.sync_key'));
});

it('keys the node a direct upload and a direct revision return like a plain read', function (): void {
    $upload = $this->post(V1::url('drives/'.$this->driveId.'/files'), ['file' => UploadedFile::fake()->createWithContent('keyed.txt', 'one')], ['Accept' => 'application/json'])->assertCreated();
    $id = $upload->json('data.id');
    $read = $this->getJson(V1::url('nodes/'.$id))->assertOk();

    expect($upload->json('data.sync_key'))->toHaveLength(43)
        ->and($upload->json('data.sync_key'))->toBe($read->json('data.sync_key'))
        ->and($upload->headers->get('ETag') ?? $read->headers->get('ETag'))->toBe($read->headers->get('ETag'));
});
