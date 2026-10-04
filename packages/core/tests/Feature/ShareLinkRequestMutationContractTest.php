<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Hash;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Enums\Access\Permission;
use Vaulter\Models\Sharing\NodeShare;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    config()->set('vaulter.features.share_links', true);
    config()->set('vaulter.drivers.permission', 'database');
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));

    $storage = AccessTestHelpers::storage();
    $this->shareNode = AccessTestHelpers::folder($storage, null, 'mutation-contract');
    AccessTestHelpers::grant($this->shareNode, GrantLevel::Owner, scope: GrantScope::Item);
});

it('rejects every invalid share-link request boundary', function (array $payload, string $field): void {
    $this->postJson('api/vaulter/nodes/'.$this->shareNode->getKey().'/shares', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(NodeShare::query()->count())->toBe(0);
})->with([
    'permissions must be an integer' => [['permissions' => 'read'], 'permissions'],
    'permissions cannot be negative' => [['permissions' => -1], 'permissions'],
    'permissions cannot exceed the CRUDS mask' => [['permissions' => Permission::allBits() + 1], 'permissions'],
    'level must be a GrantLevel' => [['level' => 'super-owner'], 'level'],
    'scope must be a GrantScope' => [['scope' => 'workspace'], 'scope'],
    'password must be a string' => [['password' => ['secret']], 'password'],
    'password is bounded' => [['password' => str_repeat('x', 256)], 'password'],
    'require_login must be boolean' => [['require_login' => 'sometimes'], 'require_login'],
    'allow_discovery must be boolean' => [['allow_discovery' => 'sometimes'], 'allow_discovery'],
    'expiry must be a date' => [['expires_at' => 'tomorrow-ish'], 'expires_at'],
    'expiry must be in the future' => [['expires_at' => now()->subSecond()->toIso8601String()], 'expires_at'],
    'expiry cannot exceed the configured ceiling' => [['expires_at' => now()->addHours(721)->toIso8601String()], 'expires_at'],
    'max_uses must be an integer' => [['max_uses' => 'many'], 'max_uses'],
    'max_uses must be positive' => [['max_uses' => 0], 'max_uses'],
]);

it('maps the complete valid request into the persisted share contract', function (): void {
    $expiresAt = now()->addHour()->startOfSecond();

    $this->postJson('api/vaulter/nodes/'.$this->shareNode->getKey().'/shares', [
        'permissions' => Permission::Read->value | Permission::Update->value,
        'level' => GrantLevel::Writer->value,
        'scope' => GrantScope::Subtree->value,
        'password' => 'secret',
        'require_login' => true,
        'allow_discovery' => true,
        'expires_at' => $expiresAt->toIso8601String(),
        'max_uses' => 7,
    ])->assertCreated();

    $share = NodeShare::query()->sole();

    expect($share->permissions)->toBe(Permission::Read->value | Permission::Update->value)
        ->and($share->level)->toBe(GrantLevel::Writer)
        ->and($share->scope)->toBe(GrantScope::Subtree)
        ->and(Hash::check('secret', $share->password_hash))->toBeTrue()
        ->and($share->require_login)->toBeTrue()
        ->and($share->allow_discovery)->toBeTrue()
        ->and($share->expires_at?->equalTo($expiresAt))->toBeTrue()
        ->and($share->max_uses)->toBe(7)
        ->and($share->granted_by)->toBe('1');
});

it('derives permissions from the selected level when no mask is supplied', function (): void {
    $this->postJson('api/vaulter/nodes/'.$this->shareNode->getKey().'/shares', [
        'level' => GrantLevel::Writer->value,
    ])->assertCreated();

    expect(NodeShare::query()->sole()->permissions)->toBe(GrantLevel::Writer->bits());
});

it('preserves an explicit zero permission mask', function (): void {
    $this->postJson('api/vaulter/nodes/'.$this->shareNode->getKey().'/shares', [
        'permissions' => 0,
        'level' => GrantLevel::Owner->value,
    ])->assertCreated();

    expect(NodeShare::query()->sole()->permissions)->toBe(0);
});
