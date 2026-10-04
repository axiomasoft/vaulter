<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Schema\SchemaBuilder;

/**
 * v_node_grants — the per-node ACL grant with inheritance (master plan §2.6 / H.2).
 *
 * Thin shell over SchemaBuilder; the live-row grant-unique partial index
 * (one allow + one deny per subject/scope per node) is emitted by the builder in
 * a driver-aware way.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new SchemaBuilder)->createTable(

            entity: 'node_grants',
        );
    }

    public function down(): void
    {
        (new SchemaBuilder)->dropTable(

            entity: 'node_grants',
        );
    }
};
