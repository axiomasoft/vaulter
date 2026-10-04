<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Schema\SchemaBuilder;

/**
 * v_drives — the ownable polymorphic disk container (master plan §2.1).
 *
 * Thin shell by design: the column definitions live in SchemaBuilder so the
 * shipped default-panel schema and any generated per-panel schema share ONE
 * source and never drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new SchemaBuilder)->createTable(

            entity: 'drives',
        );
    }

    public function down(): void
    {
        (new SchemaBuilder)->dropTable(

            entity: 'drives',
        );
    }
};
