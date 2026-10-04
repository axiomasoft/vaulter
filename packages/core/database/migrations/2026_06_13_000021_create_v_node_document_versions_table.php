<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Schema\SchemaBuilder;

/**
 * v_node_document_versions — the NODE-attached, append-only document version
 * (master plan §2.5 / M4a). DISTINCT from the legacy document_id-attached
 * v_document_versions (that table is untouched). Gated on the 'document_versions'
 * feature (default off → opt-in). Thin shell over SchemaBuilder.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new SchemaBuilder)->createTable(

            entity: 'node_document_versions',
        );
    }

    public function down(): void
    {
        (new SchemaBuilder)->dropTable(

            entity: 'node_document_versions',
        );
    }
};
