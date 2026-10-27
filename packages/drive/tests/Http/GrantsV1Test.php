<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Http\V1;
use Vaulter\Testing\Fixtures\TestUser;

/**
 * Grants on a node over v1: list, create with {subject, role}, revoke by id (Manage). The list carries the node's access
 * tag (`etag`), which a revoke sends back as If-Match (428 without it, 412 stale_write once the access changed);
 * a grant changes the access tag, never the node's sequence. Create and revoke take an Idempotency-Key.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('vaulter-drive.features.grants', true);
    $this->drive = V1::drive(userId: 1);
    $this->folder = V1::folder($this->drive, 'team');
    $this->actingAs(V1::user(1));
    $this->url = V1::url('nodes/'.$this->folder->id.'/grants');
    $this->subject = static fn (int $id, string $role = RoleRegistry::WRITER): array => ['subject' => ['type' => (new TestUser)->getMorphClass(), 'id' => $id], 'role' => $role];
});

it('creates, lists and revokes a grant with string ids and no permission bits', function (): void {
    $created = $this->postJson($this->url, ($this->subject)(7))
        ->assertCreated()
        ->assertJsonPath('data.node_id', $this->folder->id)
        ->assertJsonPath('data.subject', ['type' => (new TestUser)->getMorphClass(), 'id' => '7'])
        ->assertJsonPath('data.role', RoleRegistry::WRITER)
        ->assertJsonPath('data.role_version', 1)
        ->assertJsonPath('data.created_by', '1');
    expect(array_keys($created->json('data')))->toBe(['id', 'node_id', 'subject', 'role', 'role_version', 'scope', 'expires_at', 'created_by', 'created_at'])
        ->and($created->json('data.id'))->toBeString();

    $list = $this->getJson($this->url)->assertOk();
    expect(array_column($list->json('data'), 'id'))->toContain($created->json('data.id'))
        ->and($list->json('meta'))->toBe(['next_cursor' => null, 'per_page' => 50])
        ->and($list->headers->has('ETag'))->toBeFalse()
        ->and($list->json('etag'))->toBe('"access.'.$this->folder->id.'.1"');

    $this->deleteJson($this->url.'/'.$created->json('data.id'), [], ['If-Match' => $list->json('etag')])->assertNoContent();
    expect(array_column($this->getJson($this->url)->json('data'), 'id'))->not->toContain($created->json('data.id'));
});

it('validates the subject and the role', function (array $body, string $field): void {
    $this->postJson($this->url, $body)->assertUnprocessable()->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors($field);
})->with([
    'no subject' => [['role' => 'reader'], 'subject'],
    'unknown role' => [['subject' => ['type' => (new TestUser)->getMorphClass(), 'id' => 2], 'role' => 'admin'], 'role'],
    'unregistered subject type' => [['subject' => ['type' => 'nope', 'id' => 2], 'role' => 'reader'], 'subject.type'],
]);

it('refuses grant management without Manage (403) and hides the node without View (404)', function (): void {
    AccessTestHelpers::grant(DriveTestHelpers::model($this->folder), RoleRegistry::WRITER, subjectId: 2);

    $this->actingAs(V1::user(2))->getJson($this->url)->assertForbidden()->assertJsonPath('code', 'permission_denied');
    $this->actingAs(V1::user(3))->getJson($this->url)->assertNotFound()->assertJsonPath('code', 'node_not_found');
});

it('does not revoke a grant through another node', function (): void {
    $other = V1::folder($this->drive, 'other');
    $grant = AccessTestHelpers::grant(DriveTestHelpers::model($other), RoleRegistry::READER, subjectId: 4);
    $etag = $this->getJson($this->url)->json('etag');

    $this->deleteJson($this->url.'/'.$grant->getKey(), [], ['If-Match' => $etag])->assertNotFound();
    expect($grant->fresh())->not->toBeNull();
});

it('requires the access tag to revoke: 428 without it or with *, 412 stale_write once the access changed', function (): void {
    $grant = $this->postJson($this->url, ($this->subject)(7))->assertCreated()->json('data.id');
    $stale = $this->getJson($this->url)->json('etag');
    $this->postJson($this->url, ($this->subject)(8))->assertCreated();

    $this->deleteJson($this->url.'/'.$grant)->assertStatus(428)->assertJsonPath('code', 'precondition_required');
    $this->deleteJson($this->url.'/'.$grant, [], ['If-Match' => '*'])->assertStatus(428);
    $this->deleteJson($this->url.'/'.$grant, [], ['If-Match' => $stale])
        ->assertStatus(412)
        ->assertJsonPath('code', 'stale_write')
        ->assertJsonPath('meta.aggregate', 'drive.node_access');

    expect(NodeGrant::query()->whereKey($grant)->exists())->toBeTrue();
});

it('changes the access tag but neither the sequence nor the ETag of the node', function (): void {
    $node = $this->getJson(V1::url('nodes/'.$this->folder->id))->assertOk();
    $access = $this->getJson($this->url)->json('etag');
    $sequence = DriveTestHelpers::model($this->folder)->sequence;

    $grant = $this->postJson($this->url, ($this->subject)(7))->assertCreated()->json('data.id');
    $afterGrant = $this->getJson($this->url)->json('etag');
    $this->deleteJson($this->url.'/'.$grant, [], ['If-Match' => $afterGrant])->assertNoContent();

    expect($afterGrant)->not->toBe($access)
        ->and($this->getJson($this->url)->json('etag'))->not->toBe($afterGrant)
        ->and(DriveTestHelpers::model($this->folder)->sequence)->toBe($sequence)
        ->and($this->getJson(V1::url('nodes/'.$this->folder->id))->headers->get('ETag'))->toBe($node->headers->get('ETag'));
});

it('replays a grant and a revoke under their keys and refuses another body under a used key', function (): void {
    $first = $this->postJson($this->url, ($this->subject)(7), ['Idempotency-Key' => 'grant-7'])->assertCreated();
    $again = $this->postJson($this->url, ($this->subject)(7), ['Idempotency-Key' => 'grant-7'])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
    expect($again->json('data'))->toBe($first->json('data'))
        ->and($this->getJson($this->url)->json('etag'))->toBe('"access.'.$this->folder->id.'.1"');

    $this->postJson($this->url, ($this->subject)(8), ['Idempotency-Key' => 'grant-7'])->assertStatus(422)->assertJsonPath('code', 'idempotency_key_reused');

    $headers = ['If-Match' => $this->getJson($this->url)->json('etag'), 'Idempotency-Key' => 'revoke-7'];
    $this->deleteJson($this->url.'/'.$first->json('data.id'), [], $headers)->assertNoContent();
    // The retry repeats the If-Match that the first revoke made stale: it is answered from the receipt.
    $this->deleteJson($this->url.'/'.$first->json('data.id'), [], $headers)->assertNoContent()->assertHeader('Idempotent-Replayed', 'true');

    expect($this->getJson($this->url)->json('etag'))->toBe('"access.'.$this->folder->id.'.2"');
});

it('refuses the replay of a revoke to an actor who lost Manage', function (): void {
    AccessTestHelpers::grant(DriveTestHelpers::model($this->folder), RoleRegistry::OWNER, subjectId: 2);
    $grant = $this->postJson($this->url, ($this->subject)(7))->assertCreated()->json('data.id');
    $this->actingAs(V1::user(2));
    $headers = ['If-Match' => $this->getJson($this->url)->json('etag'), 'Idempotency-Key' => 'revoke-by-2'];
    $this->deleteJson($this->url.'/'.$grant, [], $headers)->assertNoContent();

    NodeGrant::query()->where('node_id', $this->folder->id)->where('subject_id', '2')->delete();

    $this->deleteJson($this->url.'/'.$grant, [], $headers)->assertNotFound();
});
