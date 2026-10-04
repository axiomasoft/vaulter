<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Schema\SchemaBuilder;

/**
 * v_blobs — the content-addressable, immutable payload (master plan §2.3).
 *
 * storage_key is scope-qualified and the dedup-unique index spans the scope
 * columns (H.3). Created independently of runtime feature flags.
 */
return new class extends Migration
{
    public function up(): void
    {
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
