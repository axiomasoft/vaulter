<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Panels\Panel;
use Vaulter\Schema\PanelTablesBuilder;

/**
 * v_storages — the ownable polymorphic disk container (master plan §2.1).
 *
 * Thin shell by design: the column definitions live in PanelTablesBuilder so the
 * shipped default-panel schema and any generated per-panel schema share ONE
 * source and never drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new PanelTablesBuilder)->createTable(
            panel: (new Panel)->id('default'),
            entity: 'storages',
        );
    }

    public function down(): void
    {
        (new PanelTablesBuilder)->dropTable(
            panel: (new Panel)->id('default'),
            entity: 'storages',
        );
    }
};
