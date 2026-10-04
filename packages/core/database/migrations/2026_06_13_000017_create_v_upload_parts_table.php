<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Schema\SchemaBuilder;
use Vaulter\Support\Config;

/**
 * v_upload_parts — the normalised per-part record (master plan §2.8 / D16 / M3a).
 *
 * Gated on the 'uploads' feature. The composite primary key
 * (upload_session_id, part_no) IS the S3 re-upload-replaces semantics. Thin shell
 * over SchemaBuilder.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Config::featureEnabled('uploads')) {
            return;
        }

        (new SchemaBuilder)->createTable(

            entity: 'upload_parts',
        );
    }

    public function down(): void
    {
        (new SchemaBuilder)->dropTable(

            entity: 'upload_parts',
        );
    }
};
