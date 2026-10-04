<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Schema\SchemaBuilder;
use Vaulter\Support\Config;

/**
 * v_file_versions — the immutable file ↔ blob version (master plan §2.4).
 *
 * Gated on the 'versions' feature. Thin shell over SchemaBuilder.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Config::featureEnabled('versions')) {
            return;
        }

        (new SchemaBuilder)->createTable(

            entity: 'file_versions',
        );
    }

    public function down(): void
    {
        (new SchemaBuilder)->dropTable(

            entity: 'file_versions',
        );
    }
};
