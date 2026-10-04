<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Schema\SchemaBuilder;

/**
 * v_files — the mutable 1:1 handle for a kind=file node (master plan §2.4).
 *
 * Gated on the 'versions' feature. Thin shell over SchemaBuilder.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new SchemaBuilder)->createTable(

            entity: 'files',
        );
    }

    public function down(): void
    {
        (new SchemaBuilder)->dropTable(

            entity: 'files',
        );
    }
};
