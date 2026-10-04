<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Schema\SchemaBuilder;
use Vaulter\Support\Config;

/**
 * v_webhook_deliveries — a single delivery attempt with retry bookkeeping (master
 * plan §2.11 / D14 / M3b), ported from chatom 1:1.
 *
 * Thin shell over SchemaBuilder. endpoint_id is an explicit FK →
 * v_webhook_endpoints (cascade on delete); the (status, next_retry_at) due-retry
 * sweep index is emitted by the builder. Gated on the 'reliability' feature — it
 * must run AFTER the endpoints table so the FK target exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Config::featureEnabled('reliability')) {
            return;
        }

        (new SchemaBuilder)->createTable(

            entity: 'webhook_deliveries',
        );
    }

    public function down(): void
    {
        (new SchemaBuilder)->dropTable(

            entity: 'webhook_deliveries',
        );
    }
};
