<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Panels\Panel;
use Vaulter\Schema\PanelTablesBuilder;

/**
 * v_nodes — the unified folder|file|document adjacency tree (master plan §2.2).
 *
 * Thin shell over PanelTablesBuilder; the sibling-unique partial index (which
 * survives soft-delete + soft-trash) is emitted by the builder in a driver-aware
 * way.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new PanelTablesBuilder)->createTable(
            panel: (new Panel)->id('default'),
            entity: 'nodes',
        );
    }

    public function down(): void
    {
        (new PanelTablesBuilder)->dropTable(
            panel: (new Panel)->id('default'),
            entity: 'nodes',
        );
    }
};
