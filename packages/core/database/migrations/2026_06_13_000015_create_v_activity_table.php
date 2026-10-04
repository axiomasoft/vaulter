<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Schema\SchemaBuilder;

/**
 * v_activity — the append-only audit/activity feed (master plan §2.9 /
 * M2c-activity).
 *
 * Thin shell over SchemaBuilder; the feed indexes (created_at desc, and the
 * actor/target/verb/storage scope indexes), the driver-aware ip column (inet on
 * Postgres, varchar(45) elsewhere) and the optional gin(context) index on Postgres
 * are all emitted by the builder. Append-only: no updated_at, no soft-delete — ONE
 * row per domain event, recipients resolved at READ by scoping the feed (anti-NC
 * fan-out, §1.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        (new SchemaBuilder)->createTable(

            entity: 'activity',
        );
    }

    public function down(): void
    {
        (new SchemaBuilder)->dropTable(

            entity: 'activity',
        );
    }
};
