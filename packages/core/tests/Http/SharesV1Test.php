<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Drive\Testing\Http\V1;
use Vaulter\Models\Sharing\NodeShare;

/**
 * Share links over v1: the token and URL appear only in the create and rotate
 * answers; update changes the policy, rotate replaces the token, revoke ends the link.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('vaulter.features.share_links', true);
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

    $this->patchJson($this->url.'/'.$id, ['password' => 'secret', 'max_uses' => 5])
        ->assertOk()
        ->assertJsonPath('data.has_password', true)
        ->assertJsonPath('data.max_uses', 5)
        ->assertJsonMissingPath('data.token');

    $rotated = $this->postJson($this->url.'/'.$id.'/rotate')->assertOk()->assertJsonPath('data.token_generation', 2);
    expect($rotated->json('data.token'))->not->toBe($created->json('data.token'));
    $this->getJson(V1::url('shares/'.$created->json('data.token')))->assertNotFound();

    $this->deleteJson($this->url.'/'.$id)->assertNoContent();
    $this->getJson(V1::url('shares/'.$rotated->json('data.token')))->assertNotFound();
});

it('refuses a share id of another node and a caller without Share', function (): void {
    $other = V1::folder($this->drive, 'other');
    $foreign = $this->postJson(V1::url('nodes/'.$other->id.'/shares'), ['role' => RoleRegistry::READER])->assertCreated();

    $this->postJson($this->url.'/'.$foreign->json('data.id').'/rotate')->assertNotFound();
    $this->actingAs(V1::user(2))->postJson($this->url, ['role' => RoleRegistry::READER])->assertNotFound();
});
