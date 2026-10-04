<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Schema\SchemaBuilder;

/**
 * v_file_revisions — the immutable file ↔ blob version (master plan §2.4).
 *
 * Gated on the 'versions' feature. Thin shell over SchemaBuilder.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new SchemaBuilder)->createTable(

            entity: 'file_revisions',
        );
    }

    public function down(): void
    {
        (new SchemaBuilder)->dropTable(

            entity: 'file_revisions',
        );
    }
};
