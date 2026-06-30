<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Panels\Panel;
use Vaulter\Schema\PanelTablesBuilder;
use Vaulter\Support\Config;

/**
 * v_file_versions — the immutable file ↔ blob version (master plan §2.4).
 *
 * Gated on the 'versions' feature. Thin shell over PanelTablesBuilder.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Config::featureEnabled('versions')) {
            return;
        }

        (new PanelTablesBuilder)->createTable(
            panel: (new Panel)->id('default'),
            entity: 'file_versions',
        );
    }

    public function down(): void
    {
        (new PanelTablesBuilder)->dropTable(
            panel: (new Panel)->id('default'),
            entity: 'file_versions',
        );
    }
};
