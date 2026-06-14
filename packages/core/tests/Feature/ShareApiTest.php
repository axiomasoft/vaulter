<?php

declare(strict_types=1);

use DocVault\Facades\DocVault;
use DocVault\Models\Document;
use DocVault\Models\DocumentShare;
use DocVault\Tests\Fixtures\AllowResolver;
use DocVault\Tests\Fixtures\TestUser;
use DocVault\Tests\Fixtures\ViewOnlyResolver;

beforeEach(function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 11]));
});

it('creates a share link with the manage ability', function (): void {
    DocVault::resolvePermissionsWith(new AllowResolver);
    $document = Document::factory()->create();

    $response = $this->postJson(sprintf('api/docvault/documents/%s/shares', $document->id), ['permission' => 'edit'])
        ->assertCreated()
        ->assertJsonPath('data.permission', 'edit');

    $token = $response->json('data.token');
    expect($token)->toBeString()->and(strlen((string) $token))->toBe(48);
    $this->assertDatabaseHas('dv_document_shares', ['document_id' => $document->id, 'permission' => 'edit']);
});

it('forbids creating a share without the manage ability', function (): void {
    DocVault::resolvePermissionsWith(new ViewOnlyResolver);
    $document = Document::factory()->create();

    $this->postJson(sprintf('api/docvault/documents/%s/shares', $document->id), ['permission' => 'view'])
        ->assertForbidden();
});

it('resolves a shared document publicly with abilities from the level', function (): void {
    $document = Document::factory()->create(['title' => 'Shared spec']);
    $share = DocumentShare::factory()->create(['document_id' => $document->id, 'permission' => 'comment']);

    $this->getJson('api/docvault/shares/'.$share->token)
        ->assertOk()
        ->assertJsonPath('data.title', 'Shared spec')
        ->assertJsonPath('data.abilities.view', true)
        ->assertJsonPath('data.abilities.comment', true)
        ->assertJsonPath('data.abilities.edit', false)
        ->assertJsonPath('data.share.permission', 'comment');
});

it('returns 404 for an expired share token', function (): void {
    $share = DocumentShare::factory()->expired()->create();

    $this->getJson('api/docvault/shares/'.$share->token)->assertNotFound();
});

it('revokes a share link', function (): void {
    DocVault::resolvePermissionsWith(new AllowResolver);
    $document = Document::factory()->create();
    $share = DocumentShare::factory()->create(['document_id' => $document->id]);

    $this->deleteJson(sprintf('api/docvault/documents/%s/shares/%s', $document->id, $share->id))
        ->assertNoContent();

    $this->assertDatabaseMissing('dv_document_shares', ['id' => $share->id]);
});
