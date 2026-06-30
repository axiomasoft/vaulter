<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Panels\Panel;
use Vaulter\Schema\PanelTablesBuilder;

/**
 * v_node_grants — the per-node ACL grant with inheritance (master plan §2.6 / H.2).
 *
 * Thin shell over PanelTablesBuilder; the live-row grant-unique partial index
 * (one allow + one deny per subject/scope per node) is emitted by the builder in
 * a driver-aware way.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new PanelTablesBuilder)->createTable(
            panel: (new Panel)->id('default'),
            entity: 'node_grants',
        );
    }

    public function down(): void
    {
        (new PanelTablesBuilder)->dropTable(
            panel: (new Panel)->id('default'),
            entity: 'node_grants',
        );
    }
};
