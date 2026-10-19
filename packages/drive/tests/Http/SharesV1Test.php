<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Models\Sharing\NodeShare;
use Vaulter\Drive\Testing\Http\V1;

/**
 * Share links over v1: the token and URL appear only in the create and rotate
 * answers; update changes the policy, rotate replaces the token, revoke ends the link.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('vaulter-drive.features.share_links', true);
    $this->drive = V1::drive(userId: 1);
    $this->node = V1::folder($this->drive, 'shared');
    $this->actingAs(V1::user(1));
    $this->url = V1::url('nodes/'.$this->node->id.'/shares');
});

it('creates a link and shows its token exactly once', function (): void {
    $created = $this->postJson($this->url, ['role' => RoleRegistry::READER, 'audience' => 'authenticated', 'max_uses' => 3])
        ->assertCreated()
        ->assertJsonPath('data.role', RoleRegistry::READER)
        ->assertJsonPath('data.audience', 'authenticated')
        ->assertJsonPath('data.scope', 'item')
        ->assertJsonPath('data.max_uses', 3)
        ->assertJsonPath('data.use_count', 0)
        ->assertJsonPath('data.has_password', false)
        ->assertJsonPath('data.token_generation', 1)
        ->assertJsonPath('data.created_by', '1');
    $token = $created->json('data.token');
    expect($created->json('data.url'))->toEndWith('/'.$token)
        ->and(NodeShare::query()->firstOrFail()->token_hash)->toBe(hash('sha256', $token));

    $listed = $this->getJson($this->url)->assertOk()->json('data.0');
    expect($listed)->not->toHaveKey('token')->not->toHaveKey('url')->not->toHaveKey('token_hash');
});

it('updates policy, rotates the token and revokes', function (): void {
    $created = $this->postJson($this->url, ['role' => RoleRegistry::READER])->assertCreated();
    $id = $created->json('data.id');
    expect($created->headers->has('ETag'))->toBeFalse()
        ->and($created->json('data.etag'))->toBe('"share.'.$id.'.0"')
        ->and($created->json('data.version'))->toBe(0);

    $updated = $this->patchJson($this->url.'/'.$id, ['password' => 'secret', 'max_uses' => 5], ['If-Match' => $created->json('data.etag')])
        ->assertOk()
        ->assertJsonPath('data.has_password', true)
        ->assertJsonPath('data.max_uses', 5)
        ->assertJsonPath('data.version', 1)
        ->assertJsonMissingPath('data.token');
    expect($updated->headers->get('ETag'))->toBe($updated->json('data.etag'));

    $rotated = $this->postJson($this->url.'/'.$id.'/rotate', [], ['If-Match' => $updated->json('data.etag')])->assertOk()->assertJsonPath('data.token_generation', 2)->assertJsonPath('data.version', 2);
    expect($rotated->json('data.token'))->not->toBe($created->json('data.token'))
        ->and($rotated->headers->has('ETag'))->toBeFalse();
    $this->getJson(V1::url('shares/'.$created->json('data.token')))->assertNotFound();

    $this->deleteJson($this->url.'/'.$id, [], ['If-Match' => $rotated->json('data.etag')])->assertNoContent();
    $this->getJson(V1::url('shares/'.$rotated->json('data.token')))->assertNotFound();
    expect(NodeShare::query()->whereKey($id)->value('version'))->toBe(3);
});

it('refuses a share id of another node and a caller without Share', function (): void {
    $other = V1::folder($this->drive, 'other');
    $foreign = $this->postJson(V1::url('nodes/'.$other->id.'/shares'), ['role' => RoleRegistry::READER])->assertCreated();

    $this->postJson($this->url.'/'.$foreign->json('data.id').'/rotate', [], ['If-Match' => $foreign->json('data.etag')])->assertNotFound();
    $this->actingAs(V1::user(2))->postJson($this->url, ['role' => RoleRegistry::READER])->assertNotFound();
});

it('lists every link with its tag', function (): void {
    $created = $this->postJson($this->url, ['role' => RoleRegistry::READER])->assertCreated();

    $this->getJson($this->url)->assertOk()->assertJsonPath('data.0.etag', $created->json('data.etag'))->assertJsonPath('data.0.version', 0);
});

it('requires the link tag on update, rotate and revoke: 428 without it or with *, 412 stale_write once it changed', function (string $method, string $suffix, array $body): void {
    $created = $this->postJson($this->url, ['role' => RoleRegistry::READER])->assertCreated();
    $id = $created->json('data.id');
    $this->patchJson($this->url.'/'.$id, ['max_uses' => 9], ['If-Match' => $created->json('data.etag')])->assertOk();

    $this->json($method, $this->url.'/'.$id.$suffix, $body)->assertStatus(428)->assertJsonPath('code', 'precondition_required');
    $this->json($method, $this->url.'/'.$id.$suffix, $body, ['If-Match' => '*'])->assertStatus(428);
    $this->json($method, $this->url.'/'.$id.$suffix, $body, ['If-Match' => $created->json('data.etag')])
        ->assertStatus(412)
        ->assertJsonPath('code', 'stale_write')
        ->assertJsonPath('meta.aggregate', 'drive.share');

    $share = NodeShare::query()->whereKey($id)->firstOrFail();
    expect($share->version)->toBe(1)->and($share->revoked_at)->toBeNull()->and($share->token_generation)->toBe(1);
})->with([
    'update' => ['PATCH', '', ['max_uses' => 2]],
    'rotate' => ['POST', '/rotate', []],
    'revoke' => ['DELETE', '', []],
]);

it('lets one of two clients with the same tag rotate and refuses the other (rotate ∥ update)', function (): void {
    $created = $this->postJson($this->url, ['role' => RoleRegistry::READER])->assertCreated();
    $id = $created->json('data.id');
    $tag = ['If-Match' => $created->json('data.etag')];

    $this->postJson($this->url.'/'.$id.'/rotate', [], $tag)->assertOk();
    $this->patchJson($this->url.'/'.$id, ['max_uses' => 2], $tag)->assertStatus(412)->assertJsonPath('code', 'stale_write');

    expect(NodeShare::query()->whereKey($id)->value('max_uses'))->toBeNull();
});
