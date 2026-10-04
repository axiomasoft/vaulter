<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Schema\SchemaBuilder;

/**
 * v_node_shares — the bearer share-link, the SEPARATE public sharing channel
 * (master plan §2.7 / M2c).
 *
 * Thin shell over SchemaBuilder; the UNIQUE token_hash, the (node_id,
 * revoked_at) listing index and the partial (expires_at) WHERE revoked_at IS NULL
 * live-sweep index are emitted by the builder in a driver-aware way. Only the
 * sha256 hash of the bearer token is ever stored — never the raw token.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new SchemaBuilder)->createTable(

            entity: 'node_shares',
        );
    }

    public function down(): void
    {
        (new SchemaBuilder)->dropTable(

            entity: 'node_shares',
        );
    }
};
