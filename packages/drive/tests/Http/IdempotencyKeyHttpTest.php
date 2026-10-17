<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Vaulter\Drive\Models\Node;
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

    $this->postJson(V1::url('nodes/'.$folder->id.'/trash'), [], [...V1::ifMatch($folder->id), 'Idempotency-Key' => 'ignored'])
        ->assertOk()
        ->assertHeaderMissing('Idempotent-Replayed');
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
