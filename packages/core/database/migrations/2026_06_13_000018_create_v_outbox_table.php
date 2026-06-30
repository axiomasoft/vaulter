<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Panels\Panel;
use Vaulter\Schema\PanelTablesBuilder;
use Vaulter\Support\Config;

/**
 * v_outbox — the transactional-outbox staging table (master plan §2.11 / D14 / M3b),
 * ported from chatom 1:1.
 *
 * Thin shell over PanelTablesBuilder; the event_id UNIQUE idempotency key and the
 * (status, available_at) relay-drain index are emitted by the builder. Gated on the
 * 'reliability' feature — when off, the table is not created.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Config::featureEnabled('reliability')) {
            return;
        }

        (new PanelTablesBuilder)->createTable(
            panel: (new Panel)->id('default'),
            entity: 'outbox',
        );
    }

    public function down(): void
    {
        (new PanelTablesBuilder)->dropTable(
            panel: (new Panel)->id('default'),
            entity: 'outbox',
        );
    }
};
