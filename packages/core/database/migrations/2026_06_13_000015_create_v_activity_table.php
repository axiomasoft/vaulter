<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Panels\Panel;
use Vaulter\Schema\PanelTablesBuilder;

/**
 * v_activity — the append-only audit/activity feed (master plan §2.9 /
 * M2c-activity).
 *
 * Thin shell over PanelTablesBuilder; the feed indexes (created_at desc, and the
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
        (new PanelTablesBuilder)->createTable(
            panel: (new Panel)->id('default'),
            entity: 'activity',
        );
    }

    public function down(): void
    {
        (new PanelTablesBuilder)->dropTable(
            panel: (new Panel)->id('default'),
            entity: 'activity',
        );
    }
};
