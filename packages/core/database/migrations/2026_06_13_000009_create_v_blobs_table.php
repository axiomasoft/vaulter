<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Schema\SchemaBuilder;
use Vaulter\Support\Config;

/**
 * v_blobs — the content-addressable, immutable payload (master plan §2.3).
 *
 * Gated on the 'blobs' feature. storage_key is scope-qualified and the
 * dedup-unique index spans the scope columns (H.3). Thin shell over
 * SchemaBuilder.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Config::featureEnabled('blobs')) {
            return;
        }

        (new SchemaBuilder)->createTable(

            entity: 'blobs',
        );
    }

    public function down(): void
    {
        (new SchemaBuilder)->dropTable(

            entity: 'blobs',
        );
    }
};
