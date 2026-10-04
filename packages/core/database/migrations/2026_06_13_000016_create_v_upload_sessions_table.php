<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Schema\SchemaBuilder;

/**
 * v_upload_sessions — the resumable-ingest session (master plan §2.8 / D16 / M3a).
 *
 * Gated on the 'uploads' feature. upload_id is the client UNIQUE handle and
 * received_bytes is the authoritative tus offset; the (status, expires_at) sweeper
 * index is added by the builder. Thin shell over SchemaBuilder.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new SchemaBuilder)->createTable(

            entity: 'upload_sessions',
        );
    }

    public function down(): void
    {
        (new SchemaBuilder)->dropTable(

            entity: 'upload_sessions',
        );
    }
};
