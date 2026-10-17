<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Drive\Enums\Sharing\ShareAudience;
use Vaulter\Drive\Models\Sharing\NodeShare;
use Vaulter\Drive\Support\ShareToken;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Tests\Schema\ForceCreates;

/**
 * The v_node_shares schema (§2.7 / M2c): every column exists, the token_hash is
 * UNIQUE, and a row stores the hash — never the raw token.
 */
it('creates the v_node_shares table with every §2.7 column', function (): void {
    $table = app(DatabaseConfig::class)->table('node_shares');

    expect(Schema::hasColumns($table, [
        'id', 'tenant_id', 'node_id', 'token_hash', 'token_prefix', 'role_key', 'role_version',
        'audience', 'token_generation', 'scope', 'password_hash',
        'expires_at', 'max_uses', 'use_count', 'revoked_at', 'granted_by',
        'created_at', 'updated_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns($table, ['permissions', 'permissions_version', 'level', 'require_login', 'allow_discovery']))->toBeFalse();
    foreach (['permissions', 'permissions_version', 'level', 'require_login', 'allow_discovery'] as $legacy) {
        expect(Schema::hasColumn($table, $legacy))->toBeFalse();
    }
});

it('defaults new shares to version 1 of their role, the anyone audience and generation 1', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'reader-default');
    $share = ForceCreates::make(app(VaulterConfig::class)->model('node_share'), [
        'node_id' => $node->id,
        'token_hash' => ShareToken::hash('reader-default'),
        'token_prefix' => 'reader-d',
        'role_key' => 'reader',
    ])->fresh();

    expect($share?->role_version)->toBe(1)
        ->and($share?->audience)->toBe(ShareAudience::Anyone)
        ->and($share?->token_generation)->toBe(1);
});

it('rejects a duplicate token_hash (UNIQUE)', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'doc');

    $model = app(VaulterConfig::class)->model('node_share');
    $hash = ShareToken::hash('the-token');

    $base = [
        'node_id' => $node->getKey(),
        'token_hash' => $hash,
        'token_prefix' => 'the-toke',
        'role_key' => 'reader',
        'scope' => 'item',
    ];

    ForceCreates::make($model, $base);

    // SAVEPOINT the duplicate: on PostgreSQL the UNIQUE violation would otherwise abort the
    // enclosing RefreshDatabase transaction (see BlobDedupScopeTest).
    expect(static fn () => DB::transaction(fn (): Model => ForceCreates::make($model, $base)))
        ->toThrow(QueryException::class);
});

it('stores the token hash, never the raw token', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'doc');

    $share = ShareToken::hash('raw-secret');

    /** @var NodeShare $row */
    $row = ForceCreates::make(app(VaulterConfig::class)->model('node_share'), [
        'node_id' => $node->getKey(),
        'token_hash' => $share,
        'token_prefix' => 'raw-secr',
        'role_key' => 'reader',
        'scope' => 'item',
    ]);

    expect($row->token_hash)->toHaveLength(64)
        ->and($row->token_hash)->not->toBe('raw-secret');
});
