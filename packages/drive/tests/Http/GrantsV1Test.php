<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Http\V1;
use Vaulter\Testing\Fixtures\TestUser;

/** Grants on a node over v1: list, create with {subject, role}, revoke by id (Manage). */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('vaulter.features.grants', true);
    $this->drive = V1::drive(userId: 1);
    $this->folder = V1::folder($this->drive, 'team');
    $this->actingAs(V1::user(1));
    $this->url = V1::url('nodes/'.$this->folder->id.'/grants');
});

it('creates, lists and revokes a grant with string ids and no permission bits', function (): void {
    $created = $this->postJson($this->url, ['subject' => ['type' => (new TestUser)->getMorphClass(), 'id' => 7], 'role' => RoleRegistry::WRITER])
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
        ->and($list->json())->not->toHaveKey('meta');

    $this->deleteJson($this->url.'/'.$created->json('data.id'))->assertNoContent();
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

    $this->deleteJson($this->url.'/'.$grant->getKey())->assertNotFound();
    expect($grant->fresh())->not->toBeNull();
});
