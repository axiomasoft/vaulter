<?php

declare(strict_types=1);

use Vaulter\Facades\Vaulter;
use Vaulter\Models\Document;
use Vaulter\Models\DocumentShare;
use Vaulter\Tests\Fixtures\AllowResolver;
use Vaulter\Tests\Fixtures\TestUser;
use Vaulter\Tests\Fixtures\ViewOnlyResolver;

beforeEach(function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 11]));
});

it('creates a share link with the manage ability', function (): void {
    Vaulter::resolvePermissionsWith(new AllowResolver);
    $document = Document::factory()->create();

    $response = $this->postJson(sprintf('api/vaulter/documents/%s/shares', $document->id), ['permission' => 'edit'])
        ->assertCreated()
        ->assertJsonPath('data.permission', 'edit');

    $token = $response->json('data.token');
    expect($token)->toBeString()->and(strlen((string) $token))->toBe(48);
    $this->assertDatabaseHas('v_document_shares', ['document_id' => $document->id, 'permission' => 'edit']);
});

it('forbids creating a share without the manage ability', function (): void {
    Vaulter::resolvePermissionsWith(new ViewOnlyResolver);
    $document = Document::factory()->create();

    $this->postJson(sprintf('api/vaulter/documents/%s/shares', $document->id), ['permission' => 'view'])
        ->assertForbidden();
});

it('resolves a shared document publicly with abilities from the level', function (): void {
    $document = Document::factory()->create(['title' => 'Shared spec']);
    $share = DocumentShare::factory()->create(['document_id' => $document->id, 'permission' => 'comment']);

    $this->getJson('api/vaulter/shares/'.$share->token)
        ->assertOk()
        ->assertJsonPath('data.title', 'Shared spec')
        ->assertJsonPath('data.abilities.view', true)
        ->assertJsonPath('data.abilities.comment', true)
        ->assertJsonPath('data.abilities.edit', false)
        ->assertJsonPath('data.share.permission', 'comment');
});

it('returns 404 for an expired share token', function (): void {
    $share = DocumentShare::factory()->expired()->create();

    $this->getJson('api/vaulter/shares/'.$share->token)->assertNotFound();
});

it('revokes a share link', function (): void {
    Vaulter::resolvePermissionsWith(new AllowResolver);
    $document = Document::factory()->create();
    $share = DocumentShare::factory()->create(['document_id' => $document->id]);

    $this->deleteJson(sprintf('api/vaulter/documents/%s/shares/%s', $document->id, $share->id))
        ->assertNoContent();

    $this->assertDatabaseMissing('v_document_shares', ['id' => $share->id]);
});
