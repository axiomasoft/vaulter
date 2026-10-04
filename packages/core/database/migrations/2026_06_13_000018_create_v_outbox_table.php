<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Schema\SchemaBuilder;

/**
 * v_outbox — the transactional-outbox staging table (master plan §2.11 / D14 / M3b),
 * ported from chatom 1:1.
 *
 * Thin shell over SchemaBuilder; the event_id UNIQUE idempotency key and the
 * (status, available_at) relay-drain index are emitted by the builder. Gated on the
 * 'reliability' feature — when off, the table is not created.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new SchemaBuilder)->createTable(

            entity: 'outbox',
        );
    }

    public function down(): void
    {
        (new SchemaBuilder)->dropTable(

            entity: 'outbox',
        );
    }
};
