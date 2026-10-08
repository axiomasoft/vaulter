<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Hash;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Enums\Sharing\ShareAudience;
use Vaulter\Models\Sharing\NodeShare;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    config()->set('vaulter.features.share_links', true);
    config()->set('vaulter.access.permission_driver', 'database');
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));

    $storage = AccessTestHelpers::storage();
    $this->shareNode = AccessTestHelpers::folder($storage, null, 'mutation-contract');
    AccessTestHelpers::grant($this->shareNode, RoleRegistry::OWNER, scope: GrantScope::Item);
});

it('rejects every invalid share-link request boundary', function (array $payload, string $field): void {
    $this->postJson('api/vaulter/nodes/'.$this->shareNode->getKey().'/shares', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(NodeShare::query()->count())->toBe(0);
})->with([
    'role must be a role key' => [['role' => 'super-owner'], 'role'],
    'role must be a string' => [['role' => 3], 'role'],
    'audience must be an audience' => [['audience' => 'everyone'], 'audience'],
    'scope must be a GrantScope' => [['scope' => 'workspace'], 'scope'],
    'password must be a string' => [['password' => ['secret']], 'password'],
    'password is bounded' => [['password' => str_repeat('x', 256)], 'password'],
    'expiry must be a date' => [['expires_at' => 'tomorrow-ish'], 'expires_at'],
    'expiry must be in the future' => [['expires_at' => now()->subSecond()->toIso8601String()], 'expires_at'],
    'expiry cannot exceed the configured ceiling' => [['expires_at' => now()->addHours(721)->toIso8601String()], 'expires_at'],
    'max_uses must be an integer' => [['max_uses' => 'many'], 'max_uses'],
    'max_uses must be positive' => [['max_uses' => 0], 'max_uses'],
]);

it('maps the complete valid request into the persisted share contract', function (): void {
    $expiresAt = now()->addHour()->startOfSecond();

    $this->postJson('api/vaulter/nodes/'.$this->shareNode->getKey().'/shares', [
        'role' => RoleRegistry::WRITER,
        'audience' => ShareAudience::Authenticated->value,
        'scope' => GrantScope::Subtree->value,
        'password' => 'secret',
        'expires_at' => $expiresAt->toIso8601String(),
        'max_uses' => 7,
    ])->assertCreated()
        ->assertJsonPath('data.role', RoleRegistry::WRITER)
        ->assertJsonPath('data.audience', 'authenticated')
        ->assertJsonPath('data.token_generation', 1)
        ->assertJsonMissingPath('data.permissions')
        ->assertJsonMissingPath('data.require_login');

    $share = NodeShare::query()->sole();

    expect($share->role_key)->toBe(RoleRegistry::WRITER)
        ->and($share->role_version)->toBe(1)
        ->and($share->audience)->toBe(ShareAudience::Authenticated)
        ->and($share->scope)->toBe(GrantScope::Subtree)
        ->and(Hash::check('secret', $share->password_hash))->toBeTrue()
        ->and($share->expires_at?->equalTo($expiresAt))->toBeTrue()
        ->and($share->max_uses)->toBe(7)
        ->and($share->granted_by)->toBe('1');
});

it('defaults to a reader link for anyone', function (): void {
    $this->postJson('api/vaulter/nodes/'.$this->shareNode->getKey().'/shares', [])
        ->assertCreated()->assertJsonPath('data.role', 'reader')->assertJsonPath('data.audience', 'anyone');
});

it('accepts the roles a link may carry for anyone', function (string $role): void {
    $this->postJson('api/vaulter/nodes/'.$this->shareNode->id.'/shares', ['role' => $role, 'scope' => GrantScope::Item->value])
        ->assertCreated()->assertJsonPath('data.role', $role);

    expect(NodeShare::query()->sole()->role_key)->toBe($role);
})->with(['previewer', 'reader', 'commenter']);

it('refuses the roles a link cannot carry with share_role_not_allowed', function (string $role, string $audience): void {
    $this->postJson('api/vaulter/nodes/'.$this->shareNode->id.'/shares', ['role' => $role, 'audience' => $audience])
        ->assertUnprocessable()->assertJsonPath('code', 'share_role_not_allowed');

    expect(NodeShare::query()->count())->toBe(0);
})->with([
    'writer for anyone' => ['writer', 'anyone'],
    'organizer for authenticated' => ['organizer', 'authenticated'],
    'owner for authenticated' => ['owner', 'authenticated'],
]);

it('answers unknown_role for a stored-role lookup that does not exist', function (): void {
    $this->postJson('api/vaulter/nodes/'.$this->shareNode->id.'/shares', ['role' => 'admin'])->assertUnprocessable()->assertJsonValidationErrors('role');
});
