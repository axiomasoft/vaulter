<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Schema\SchemaBuilder;

/**
 * v_file_revision_blobs — the version ↔ blob edge table, the GC truth (§2.4).
 *
 * Created independently of runtime feature flags (core owns the whole schema); depends on
 * v_blobs and the file revisions table. Composite primary key, never soft-deleted. Thin shell
 * over SchemaBuilder.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new SchemaBuilder)->createTable(

            entity: 'file_revision_blobs',
        );
    }

    public function down(): void
    {
        (new SchemaBuilder)->dropTable(

            entity: 'file_revision_blobs',
        );
    }
};
