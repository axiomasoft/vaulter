<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Vaulter\Models\NodeShare;
use Vaulter\Support\Config;
use Vaulter\Support\ShareToken;
use Vaulter\Tests\Access\AccessTestHelpers;

/**
 * The v_node_shares schema (§2.7 / M2c): every column exists, the token_hash is
 * UNIQUE, and a row stores the hash — never the raw token.
 */
it('creates the v_node_shares table with every §2.7 column', function (): void {
    $table = Config::table('node_shares');

    expect(Schema::hasColumns($table, [
        'id', 'tenant_id', 'node_id', 'token_hash', 'token_prefix', 'permissions',
        'level', 'scope', 'password_hash', 'require_login', 'allow_discovery',
        'expires_at', 'max_uses', 'use_count', 'revoked_at', 'granted_by',
        'created_at', 'updated_at', 'deleted_at',
    ]))->toBeTrue();
});

it('rejects a duplicate token_hash (UNIQUE)', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'doc');

    $model = Config::nodeShareModel();
    $hash = ShareToken::hash('the-token');

    $base = [
        'node_id' => $node->getKey(),
        'token_hash' => $hash,
        'token_prefix' => 'the-toke',
        'permissions' => 1,
        'level' => 'reader',
        'scope' => 'item',
    ];

    $model::query()->create($base);

    expect(static fn () => $model::query()->create($base))->toThrow(QueryException::class);
});

it('stores the token hash, never the raw token', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'doc');

    $share = ShareToken::hash('raw-secret');

    /** @var NodeShare $row */
    $row = Config::nodeShareModel()::query()->create([
        'node_id' => $node->getKey(),
        'token_hash' => $share,
        'token_prefix' => 'raw-secr',
        'permissions' => 1,
        'level' => 'reader',
        'scope' => 'item',
    ]);

    expect($row->token_hash)->toHaveLength(64)
        ->and($row->token_hash)->not->toBe('raw-secret');
});
